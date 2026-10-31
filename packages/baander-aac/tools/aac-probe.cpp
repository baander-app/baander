/* aac-probe: per-frame side information of an AAC-LC ADTS stream, and,
 * given the source PCM, the quantisation the encoder chose against the
 * source spectrum.  An analysis tool for comparing encoders from their
 * output alone; it is not part of the library.
 *
 *   aac-probe <in.aac> [<source.f32le> --offset D | --find-offset LO HI]
 *             [--frames N] [--no-frames]
 *
 * One JSON object per frame on stdout, then one summary object.  With a
 * source, frame n is compared with the MDCT of the source window that
 * starts D samples before n * 1024 (the encoder's look-ahead); the
 * window sequence and shapes are those of the stream.  The summary holds
 * the rounding statistics: for every coefficient of a coded band in a
 * frame without TNS, v = |x|^(3/4) / step and whether the encoder chose
 * floor(v) or floor(v) + 1, by the integer part (0, 1, 2, 3+) and the
 * fractional part in steps of 0.02. */
#include <algorithm>
#include <cmath>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>
#include <vector>

#include "aac_dsp.h"
#include "aac_tables.h"
#include "bitstream.h"
#include "decoder.h"
#include "ics.h"
#include "mdct.h"

namespace {

constexpr float kInputScale = 32768.0f;

struct Adts {
  int header_bytes, frame_bytes, rate_index, channel_config, fullness, profile;
};

bool parse_adts(const uint8_t* p, size_t left, Adts* h) {
  if (left < 7 || p[0] != 0xFF || (p[1] & 0xF0) != 0xF0) {
    return false;
  }
  const int protection_absent = p[1] & 1;
  h->profile = (p[2] >> 6) & 3;
  h->rate_index = (p[2] >> 2) & 15;
  h->channel_config = ((p[2] & 1) << 2) | (p[3] >> 6);
  h->frame_bytes = ((p[3] & 3) << 11) | (p[4] << 3) | (p[5] >> 5);
  h->fullness = ((p[5] & 31) << 6) | (p[6] >> 2);
  h->header_bytes = protection_absent ? 7 : 9;
  return h->frame_bytes >= h->header_bytes && (size_t)h->frame_bytes <= left;
}

struct Pre {
  int elem, common_window, ms_present;
  int ms[AAC_MAX_BANDS];
};

/* Reads the element head the decoder does not keep: the M/S mask. */
void pre_parse(const uint8_t* data, int size, Pre* pre) {
  AacBitReader r;
  aac_bitreader_init(&r, data, size);
  memset(pre, 0, sizeof(*pre));
  pre->elem = (int)aac_bitreader_read(&r, 3);
  aac_bitreader_read(&r, 4);
  if (pre->elem != 1) {
    return;
  }
  pre->common_window = (int)aac_bitreader_read(&r, 1);
  if (!pre->common_window) {
    return;
  }
  aac_bitreader_read(&r, 1);
  const int seq = (int)aac_bitreader_read(&r, 2);
  aac_bitreader_read(&r, 1);
  int max_sfb, groups = 1;
  if (seq == AAC_WIN_EIGHT_SHORT) {
    max_sfb = (int)aac_bitreader_read(&r, 4);
    const int grouping = (int)aac_bitreader_read(&r, 7);
    for (int i = 0; i < 7; i++) {
      if (!((grouping >> (6 - i)) & 1)) {
        groups++;
      }
    }
  } else {
    max_sfb = (int)aac_bitreader_read(&r, 6);
    aac_bitreader_read(&r, 1);
  }
  pre->ms_present = (int)aac_bitreader_read(&r, 2);
  for (int i = 0; i < groups * max_sfb; i++) {
    pre->ms[i] = pre->ms_present == 1 ? (int)aac_bitreader_read(&r, 1) : pre->ms_present == 2;
  }
}

/* Source MDCT of one channel for a frame of the stream, as the encoder's
 * window_and_mdct computes it from pcm[start .. start + 2048). */
void source_mdct(const AacMdct* ml, const AacMdct* ms, const float* pcm, int seq, int shape,
                 int prev_shape, float* coef) {
  const int fl = 1024, sl = 128, start = (fl - sl) / 2;
  const float* lw_prev = aac_window_long_for(fl, prev_shape);
  const float* sw_prev = aac_window_short_for(fl, prev_shape);
  const float* lw = aac_window_long_for(fl, shape);
  const float* sw = aac_window_short_for(fl, shape);
  float buf[2048];
  if (seq != AAC_WIN_EIGHT_SHORT) {
    if (seq == AAC_WIN_LONG_STOP) {
      memset(buf, 0, start * sizeof(float));
      for (int i = 0; i < sl; i++) buf[start + i] = pcm[start + i] * sw_prev[i];
      memcpy(buf + start + sl, pcm + start + sl, start * sizeof(float));
    } else {
      for (int i = 0; i < fl; i++) buf[i] = pcm[i] * lw_prev[i];
    }
    if (seq == AAC_WIN_LONG_START) {
      memcpy(buf + fl, pcm + fl, start * sizeof(float));
      for (int i = 0; i < sl; i++) buf[fl + start + i] = pcm[fl + start + i] * sw[sl - 1 - i];
      memset(buf + fl + start + sl, 0, start * sizeof(float));
    } else {
      for (int i = 0; i < fl; i++) buf[fl + i] = pcm[fl + i] * lw[fl - 1 - i];
    }
    aac_mdct_forward(ml, buf, coef);
  } else {
    for (int w = 0; w < 8; w++) {
      const float* src = pcm + start + w * sl;
      const float* left = w == 0 ? sw_prev : sw;
      for (int i = 0; i < sl; i++) {
        buf[i] = src[i] * left[i];
        buf[sl + i] = src[sl + i] * sw[sl - 1 - i];
      }
      aac_mdct_forward(ms, buf, coef + w * 128);
    }
  }
}

struct Frame {
  Adts h;
  std::vector<uint8_t> payload;
};

struct Source {
  std::vector<float> pcm; /* interleaved, scaled to 16-bit range */
  int channels = 0;
  long frames() const { return channels ? (long)pcm.size() / channels : 0; }
  /* Channel c of samples [start, start + n), zero outside the file. */
  void window(int c, long start, int n, float* out) const {
    for (int i = 0; i < n; i++) {
      const long t = start + i;
      out[i] = t >= 0 && t < frames() ? pcm[(size_t)t * channels + c] : 0.0f;
    }
  }
};

struct Rounding {
  /* [integer part 0..3][fraction bin 0..49][0 down, 1 up, 2 other] */
  long n[4][50][3] = {};
};

/* The source spectrum of channel k in the coded domain (M/S per band). */
void coded_source(const float* l, const float* r, const Pre& pre, const AacIcsInfo* ics, int k,
                  float* out) {
  if (!r) {
    memcpy(out, l, 1024 * sizeof(float));
    return;
  }
  int w0 = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int w = w0; w < w0 + ics->group_len[g]; w++) {
      for (int b = 0; b < ics->num_swb; b++) {
        const bool ms = b < ics->max_sfb && pre.common_window && pre.ms[g * ics->max_sfb + b];
        for (int i = w * 128 + ics->swb_offset[b]; i < w * 128 + ics->swb_offset[b + 1]; i++) {
          out[i] = ms ? (k == 0 ? 0.5f * (l[i] + r[i]) : 0.5f * (l[i] - r[i])) : (k == 0 ? l[i] : r[i]);
        }
      }
    }
    w0 += ics->group_len[g];
  }
}

}  // namespace

int main(int argc, char** argv) {
  if (argc < 2) {
    fprintf(stderr,
            "usage: aac-probe <in.aac> [<source.f32le> --offset D | --find-offset LO HI]\n"
            "                 [--frames N] [--no-frames]\n");
    return 2;
  }
  const char* src_path = nullptr;
  long offset = 0, find_lo = -1, find_hi = -1, max_frames = -1;
  bool print_frames = true;
  for (int i = 2; i < argc; i++) {
    if (!strcmp(argv[i], "--offset") && i + 1 < argc) {
      offset = atol(argv[++i]);
    } else if (!strcmp(argv[i], "--find-offset") && i + 2 < argc) {
      find_lo = atol(argv[++i]);
      find_hi = atol(argv[++i]);
    } else if (!strcmp(argv[i], "--frames") && i + 1 < argc) {
      max_frames = atol(argv[++i]);
    } else if (!strcmp(argv[i], "--no-frames")) {
      print_frames = false;
    } else {
      src_path = argv[i];
    }
  }

  FILE* f = fopen(argv[1], "rb");
  if (!f) {
    perror(argv[1]);
    return 1;
  }
  std::vector<uint8_t> data;
  {
    uint8_t buf[65536];
    size_t n;
    while ((n = fread(buf, 1, sizeof buf, f)) > 0) data.insert(data.end(), buf, buf + n);
    fclose(f);
  }
  std::vector<Frame> frames;
  for (size_t pos = 0; pos < data.size();) {
    Frame fr;
    if (!parse_adts(data.data() + pos, data.size() - pos, &fr.h)) {
      pos++;
      continue;
    }
    fr.payload.assign(data.begin() + pos + fr.h.header_bytes, data.begin() + pos + fr.h.frame_bytes);
    pos += fr.h.frame_bytes;
    frames.push_back(std::move(fr));
    if (max_frames > 0 && (long)frames.size() >= max_frames) break;
  }
  if (frames.empty()) {
    fprintf(stderr, "no ADTS frames\n");
    return 1;
  }
  const int nch = frames[0].h.channel_config;
  if (nch < 1 || nch > 2) {
    fprintf(stderr, "mono or stereo streams only\n");
    return 1;
  }

  Source src;
  if (src_path) {
    FILE* sf = fopen(src_path, "rb");
    if (!sf) {
      perror(src_path);
      return 1;
    }
    float buf[8192];
    size_t n;
    while ((n = fread(buf, sizeof(float), 8192, sf)) > 0)
      for (size_t i = 0; i < n; i++) src.pcm.push_back(buf[i] * kInputScale);
    fclose(sf);
    src.channels = nch;
  }

  aac_tables_init();
  AacMdct mdct_long, mdct_short;
  aac_mdct_init(&mdct_long, 2048);
  aac_mdct_init(&mdct_short, 256);

  /* Pass 1 (or the only pass): decode every frame once and keep what
   * the analysis needs. */
  struct ChInfo {
    AacIcsInfo ics;
    int cb[AAC_MAX_BANDS], sf[AAC_MAX_BANDS];
    int quant[1024];
    int tns_present, tns_filters, tns_order_max;
    AacTnsData tns;
  };
  struct FrameInfo {
    Pre pre;
    ChInfo ch[2];
    int ok;
  };
  std::vector<FrameInfo> info(frames.size());
  AacDecoderState* dec = aac_decoder_state_create(48000, 0, aac_dsp_global());
  aac_decoder_set_object_type(dec, AAC_AOT_LC);
  aac_decoder_set_rate_index(dec, frames[0].h.rate_index);
  aac_decoder_set_channel_config(dec, nch);
  for (size_t n = 0; n < frames.size(); n++) {
    const Frame& fr = frames[n];
    FrameInfo& fi = info[n];
    pre_parse(fr.payload.data(), (int)fr.payload.size(), &fi.pre);
    AacBitReader r;
    aac_bitreader_init(&r, fr.payload.data(), (int)fr.payload.size());
    fi.ok = aac_decode_raw_data_block(dec, &r) >= 0;
    for (int c = 0; c < nch && fi.ok; c++) {
      const AacChannelStream* cs = &dec->ch[c].cs;
      ChInfo& ci = fi.ch[c];
      ci.ics = cs->ics;
      memcpy(ci.cb, cs->band_type, sizeof ci.cb);
      memcpy(ci.sf, cs->sf, sizeof ci.sf);
      memcpy(ci.quant, cs->quant, sizeof ci.quant);
      ci.tns_present = cs->tns_present;
      ci.tns = cs->tns;
      ci.tns_filters = 0;
      ci.tns_order_max = 0;
      if (cs->tns_present) {
        for (int w = 0; w < cs->ics.num_windows; w++) {
          for (int k = 0; k < cs->tns.n_filt[w]; k++) {
            ci.tns_filters++;
            if (cs->tns.order[w][k] > ci.tns_order_max) ci.tns_order_max = cs->tns.order[w][k];
          }
        }
      }
    }
  }
  aac_decoder_state_destroy(dec);

  auto step_gain = [](int sf) { return exp2f(0.25f * (float)(sf - 100)); };

  /* Noise-to-energy of the coded bands of the first frames for one
   * offset, over long frames without TNS. */
  auto offset_score = [&](long d) {
    double e_sum = 0, n_sum = 0;
    int used = 0;
    int prev_shape[2] = {0, 0};
    float pcm[2048], coef[2][1024], x[1024];
    for (size_t n = 0; n < frames.size() && used < 40; n++) {
      const FrameInfo& fi = info[n];
      if (!fi.ok) continue;
      bool skip = false;
      for (int c = 0; c < nch; c++) {
        const ChInfo& ci = fi.ch[c];
        src.window(c, (long)n * 1024 - d, 2048, pcm);
        source_mdct(&mdct_long, &mdct_short, pcm, ci.ics.window_sequence, ci.ics.window_shape,
                    prev_shape[c], coef[c]);
        prev_shape[c] = ci.ics.window_shape;
        if (ci.tns_present || ci.ics.num_windows == 8) skip = true;
      }
      if (skip || n < 2) continue;
      used++;
      for (int c = 0; c < nch; c++) {
        const ChInfo& ci = fi.ch[c];
        coded_source(coef[0], nch == 2 ? coef[1] : nullptr, fi.pre, &ci.ics, c, x);
        for (int b = 0; b < ci.ics.max_sfb; b++) {
          const int cb = ci.cb[b];
          if (cb < 1 || cb > 11) continue;
          const float gain = step_gain(ci.sf[b]);
          for (int i = ci.ics.swb_offset[b]; i < ci.ics.swb_offset[b + 1]; i++) {
            const int q = ci.quant[i];
            const float rec = (q < 0 ? -1.0f : 1.0f) * aac_pow43[q < 0 ? -q : q] * gain;
            e_sum += (double)x[i] * x[i];
            n_sum += (double)(x[i] - rec) * (x[i] - rec);
          }
        }
      }
    }
    return e_sum > 0 ? n_sum / e_sum : 1e9;
  };

  if (src_path && find_lo >= 0) {
    long best = find_lo;
    double best_score = 1e30;
    for (long d = find_lo; d <= find_hi; d++) {
      const double s = offset_score(d);
      if (s < best_score) {
        best_score = s;
        best = d;
      }
    }
    fprintf(stderr, "offset %ld: noise/energy %.3g dB\n", best, 10 * log10(best_score));
    offset = best;
  }

  Rounding rounding;
  int prev_shape[2] = {0, 0};
  float pcm[2048], coef[2][1024], x[1024];
  long total_bytes = 0;
  for (size_t n = 0; n < frames.size(); n++) {
    const Frame& fr = frames[n];
    const FrameInfo& fi = info[n];
    total_bytes += fr.h.frame_bytes;
    if (!fi.ok) {
      if (print_frames) printf("{\"f\":%zu,\"error\":1}\n", n);
      continue;
    }
    if (src_path) {
      for (int c = 0; c < nch; c++) {
        const ChInfo& ci = fi.ch[c];
        src.window(c, (long)n * 1024 - offset, 2048, pcm);
        source_mdct(&mdct_long, &mdct_short, pcm, ci.ics.window_sequence, ci.ics.window_shape,
                    prev_shape[c], coef[c]);
        prev_shape[c] = ci.ics.window_shape;
      }
    }
    /* The decoded spectrum of both channels in the L/R domain (before
     * TNS synthesis is irrelevant here: frames with TNS are compared in
     * the filtered domain and flagged). */
    float rec[2][1024] = {};
    if (src_path) {
      for (int c = 0; c < nch; c++) {
        const ChInfo& ci = fi.ch[c];
        const AacIcsInfo* ics = &ci.ics;
        int w0 = 0;
        for (int g = 0; g < ics->num_window_groups; g++) {
          for (int b = 0; b < ics->max_sfb; b++) {
            const int idx = g * ics->max_sfb + b;
            const int cb = ci.cb[idx];
            if (cb < 1 || cb > 11) continue;
            const float gain = step_gain(ci.sf[idx]);
            for (int w = w0; w < w0 + ics->group_len[g]; w++)
              for (int i = w * 128 + ics->swb_offset[b]; i < w * 128 + ics->swb_offset[b + 1]; i++) {
                const int q = ci.quant[i];
                rec[c][i] = (q < 0 ? -1.0f : 1.0f) * aac_pow43[q < 0 ? -q : q] * gain;
              }
          }
          w0 += ics->group_len[g];
        }
      }
      if (nch == 2 && fi.pre.common_window) {
        const AacIcsInfo* ics = &fi.ch[0].ics;
        int w0 = 0;
        for (int g = 0; g < ics->num_window_groups; g++) {
          for (int b = 0; b < ics->max_sfb; b++) {
            const int idx = g * ics->max_sfb + b;
            if (!fi.pre.ms[idx] || fi.ch[1].cb[idx] >= 13) continue;
            for (int w = w0; w < w0 + ics->group_len[g]; w++)
              for (int i = w * 128 + ics->swb_offset[b]; i < w * 128 + ics->swb_offset[b + 1]; i++) {
                const float m = rec[0][i], sd = rec[1][i];
                rec[0][i] = m + sd;
                rec[1][i] = m - sd;
              }
          }
          w0 += ics->group_len[g];
        }
      }
    }
    std::string out;
    char tmp[256];
    snprintf(tmp, sizeof tmp, "{\"f\":%zu,\"bytes\":%d,\"fullness\":%d,\"ms_present\":%d,\"ch\":[", n,
             fr.h.frame_bytes, fr.h.fullness, fi.pre.ms_present);
    out += tmp;
    for (int c = 0; c < nch; c++) {
      const ChInfo& ci = fi.ch[c];
      const AacIcsInfo* ics = &ci.ics;
      if (src_path) coded_source(coef[0], nch == 2 ? coef[1] : nullptr, fi.pre, ics, c, x);
      snprintf(tmp, sizeof tmp, "%s{\"ws\":%d,\"shape\":%d,\"max_sfb\":%d,\"groups\":[", c ? "," : "",
               ics->window_sequence, ics->window_shape, ics->max_sfb);
      out += tmp;
      for (int g = 0; g < ics->num_window_groups; g++) {
        snprintf(tmp, sizeof tmp, "%s%d", g ? "," : "", ics->group_len[g]);
        out += tmp;
      }
      snprintf(tmp, sizeof tmp, "],\"tns\":%d,\"tns_order\":%d,\"bands\":[", ci.tns_filters,
               ci.tns_order_max);
      out += tmp;
      int w0 = 0;
      int spec_bits = 0;
      bool first = true;
      for (int g = 0; g < ics->num_window_groups; g++) {
        for (int b = 0; b < ics->max_sfb; b++) {
          const int idx = g * ics->max_sfb + b;
          const int cb = ci.cb[idx];
          int maxq = 0, nz = 0, ones = 0, bits = 0;
          double e = 0, noise = 0, e_lr = 0, n_lr = 0;
          const float gain = cb >= 1 && cb <= 11 ? step_gain(ci.sf[idx]) : 0.0f;
          for (int w = w0; w < w0 + ics->group_len[g]; w++) {
            const int lo = w * 128 + ics->swb_offset[b], hi = w * 128 + ics->swb_offset[b + 1];
            for (int i = lo; i < hi; i++) {
              const int q = ci.quant[i], aq = q < 0 ? -q : q;
              if (aq > maxq) maxq = aq;
              if (aq) nz++;
              if (aq == 1) ones++;
              if (src_path) {
                const float orig = coef[c][i];
                e_lr += (double)orig * orig;
                n_lr += (double)(orig - rec[c][i]) * (orig - rec[c][i]);
              }
              if (src_path) {
                const float rec = cb >= 1 && cb <= 11 ? (q < 0 ? -1.0f : 1.0f) * aac_pow43[aq] * gain : 0.0f;
                e += (double)x[i] * x[i];
                noise += (double)(x[i] - rec) * (x[i] - rec);
                if (cb >= 1 && cb <= 11 && !ci.tns_present) {
                  const float v = powf(fabsf(x[i]), 0.75f) * exp2f(-0.1875f * (float)(ci.sf[idx] - 100));
                  const int fl = (int)floorf(v);
                  const int bin = std::min(49, (int)((v - fl) * 50.0f));
                  const int kind = aq == fl ? 0 : aq == fl + 1 ? 1 : 2;
                  rounding.n[std::min(fl, 3)][bin][kind]++;
                }
              }
            }
            if (cb >= 1 && cb <= 11) {
              const int dim = aac_codebook_info[cb].dim;
              for (int i = lo; i < hi; i += dim) bits += aac_huffman_bits(cb, ci.quant + i);
            }
          }
          spec_bits += bits;
          snprintf(tmp, sizeof tmp, "%s[%d,%d,%d,%d,%d,%d", first ? "" : ",", cb, ci.sf[idx], maxq, nz,
                   ones, bits);
          out += tmp;
          if (src_path) {
            snprintf(tmp, sizeof tmp, ",%.4g,%.4g,%.4g,%.4g", e, noise, e_lr, n_lr);
            out += tmp;
          }
          if (nch == 2) {
            snprintf(tmp, sizeof tmp, ",%d", fi.pre.common_window ? fi.pre.ms[idx] : 0);
            out += tmp;
          }
          out += "]";
          first = false;
        }
        w0 += ics->group_len[g];
      }
      snprintf(tmp, sizeof tmp, "],\"spec_bits\":%d}", spec_bits);
      out += tmp;
    }
    out += "]}\n";
    if (print_frames) fputs(out.c_str(), stdout);
  }
  printf("{\"summary\":1,\"frames\":%zu,\"bytes\":%ld,\"offset\":%ld,\"rounding\":[", frames.size(),
         total_bytes, offset);
  for (int k = 0; k < 4; k++) {
    printf("%s[", k ? "," : "");
    for (int b = 0; b < 50; b++)
      printf("%s[%ld,%ld,%ld]", b ? "," : "", rounding.n[k][b][0], rounding.n[k][b][1], rounding.n[k][b][2]);
    printf("]");
  }
  printf("]}\n");
  return 0;
}
