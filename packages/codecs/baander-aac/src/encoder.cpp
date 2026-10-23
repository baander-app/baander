#include "encoder.h"

#include <algorithm>
#include <cmath>
#include <cstring>

#include "encoder_tools.h"

/* AAC-LC encoder.  The structure follows the Fraunhofer FDK encoder and
 * 3GPP TS 26.403: block switching with one block of lookahead, the masking
 * model of psycho.cpp, TNS, perceptual noise substitution, intensity and
 * per-band M/S stereo (encoder_tools.cpp), a bitrate-dependent lowpass and
 * minimum SNR per band, thresholds raised towards the bit budget in the
 * fourth-root domain so that quiet bands become holes before loud ones lose
 * precision, a bit reservoir driven by perceptual entropy, scalefactor
 * search against the resulting noise targets, scalefactor smoothing, and
 * codebook sectioning by dynamic programming. */

namespace {

constexpr float kInputScale = 32768.0f;
constexpr int kFrame = AAC_FRAME_SIZE_LONG;
constexpr int kShort = AAC_FRAME_SIZE_SHORT;
constexpr int kAdtsBits = 8 * AAC_ADTS_HEADER_SIZE;
constexpr int kMaxBitsPerChannel = 6144; /* ISO/IEC 14496-3 4.5.3.1 */
constexpr float kQuantRound = 0.4054f;
constexpr int kMaxSf = 255;
constexpr int kMaxSfDelta = 60;
constexpr int kInfBits = 1 << 28;
constexpr float kBitsToPe = 1.18f;
constexpr float kMinSnrFloor = 0.003f; /* -25 dB */
constexpr float kMinSnrCeil = 0.8f;    /* -1 dB */
constexpr int kSearchIterations = 12;
/* Noise-to-mask ratio the encoder may go down to when bits are plentiful. */
constexpr float kImproveLimitLog2 = -8.0f;

constexpr int kNumBooks = 16; /* section codebooks 0..15 */
constexpr int kPnsFirstBits = 9;

/* Codebook with the smallest alphabet per maximum magnitude, by index. */
const int kCodebookMaxVal[12] = {0, 1, 1, 2, 2, 4, 4, 7, 7, 12, 12, AAC_ESC_MAX_MAG};

bool is_tool_book(int cb) { return cb >= AAC_CB_NOISE; }

float g_sf_gain[kMaxSf + 1];    /* 2^((sf - 100) / 4) */
float g_sf_scale34[kMaxSf + 1]; /* 2^(-(sf - 100) * 3 / 16) */

void init_sf_tables() {
  static bool done = false;
  if (done) {
    return;
  }
  for (int sf = 0; sf <= kMaxSf; sf++) {
    g_sf_gain[sf] = exp2f(0.25f * (float)(sf - 100));
    g_sf_scale34[sf] = exp2f(-0.1875f * (float)(sf - 100));
  }
  done = true;
}

float bark(float f) {
  return 13.3f * atanf(0.00076f * f) + 3.5f * atanf((f / 7500.0f) * (f / 7500.0f));
}

/* ── configuration ─────────────────────────────────────────────── */

/* Lowpass per channel bitrate for 1024-sample frames (libfdk-aac
 * bandwidth.cpp): {bitrate, mono, stereo}. */
struct BandwidthEntry {
  int bitrate, mono, stereo;
};
const BandwidthEntry kBandwidth[] = {
    {0, 3700, 5000},       {12000, 5000, 6400},   {20000, 6900, 9640},   {28000, 9600, 13050},
    {40000, 12060, 14260}, {56000, 13950, 15500}, {72000, 14200, 16120}, {96000, 17000, 17000}};

int bandwidth_for(int channel_bitrate, int channels, int sample_rate, AacRateControl rc,
                  int quality) {
  int bw = 17000;
  if (rc == AAC_RC_TVBR || rc == AAC_RC_CVBR) {
    /* libfdk-aac's VBR modes span 13 kHz to about 19.3 kHz. */
    bw = 13000 + (int)((float)(quality - 1) / 99.0f * 6300.0f);
  } else if (channel_bitrate >= 128000) {
    bw = 24000; /* enough bits for the full band */
  } else {
    for (const BandwidthEntry& e : kBandwidth) {
      if (channel_bitrate >= e.bitrate) {
        bw = channels == 1 ? e.mono : e.stereo;
      }
    }
  }
  return std::min(bw, sample_rate / 2);
}

/* Minimum SNR per band from the bits available per Bark (3GPP TS 26.403
 * 5.6.1.1, libfdk-aac psy_configuration.cpp): a band that is coded at all
 * is coded no worse than this. */
void init_min_snr(float* min_snr, int channel_bitrate, int sample_rate, int window_len,
                  const int* off, int active_bands) {
  const int lines = window_len / 2;
  const float line_hz = (float)sample_rate / (float)window_len;
  float pe_per_window =
      (float)channel_bitrate / (float)sample_rate * kBitsToPe * 0.024f * (float)lines;
  if (lines == kShort) {
    pe_per_window *= 1.5f; /* short blocks are assumed to get more bits */
  }
  const float bark_factor = std::min(bark((float)off[active_bands] * line_hz), 24.0f) / 25.0f;
  const float pe_const = pe_per_window / std::max(bark_factor, 0.01f);
  for (int b = 0; b < active_bands; b++) {
    const float bark_width = bark((float)off[b + 1] * line_hz) - bark((float)off[b] * line_hz);
    const float pe_part = pe_const * bark_width / (float)(off[b + 1] - off[b]);
    const float snr = std::max(1.0f, exp2f(pe_part) - 1.5f);
    min_snr[b] = std::clamp(1.0f / snr, kMinSnrFloor, kMinSnrCeil);
  }
}

/* ── band helpers ──────────────────────────────────────────────── */

/* Quantizes band b of group g (first window w0) with scalefactor sf;
 * returns the largest magnitude. */
int quantize_band(AacEncChannel* c, int g, int w0, int b, int sf) {
  const AacIcsInfo* ics = &c->ics;
  const float scale = g_sf_scale34[sf];
  int max_mag = 0;
  for (int w = w0; w < w0 + ics->group_len[g]; w++) {
    for (int i = w * 128 + ics->swb_offset[b]; i < w * 128 + ics->swb_offset[b + 1]; i++) {
      int q = (int)(c->x34[i] * scale + kQuantRound);
      if (q > AAC_ESC_MAX_MAG) {
        q = AAC_ESC_MAX_MAG;
      }
      c->quant[i] = c->coef[i] < 0.0f ? -q : q;
      if (q > max_mag) {
        max_mag = q;
      }
    }
  }
  return max_mag;
}

float band_noise(const AacEncChannel* c, int g, int w0, int b, int sf) {
  const AacIcsInfo* ics = &c->ics;
  const float gain = g_sf_gain[sf];
  float noise = 0.0f;
  for (int w = w0; w < w0 + ics->group_len[g]; w++) {
    for (int i = w * 128 + ics->swb_offset[b]; i < w * 128 + ics->swb_offset[b + 1]; i++) {
      const int q = c->quant[i] < 0 ? -c->quant[i] : c->quant[i];
      const float d = fabsf(c->coef[i]) - aac_pow43[q] * gain;
      noise += d * d;
    }
  }
  return noise;
}

/* Smallest scalefactor whose quantized magnitudes stay within the escape
 * range. */
int min_scalefactor(const AacEncChannel* c, int g, int w0, int b) {
  const AacIcsInfo* ics = &c->ics;
  float max34 = 0.0f;
  for (int w = w0; w < w0 + ics->group_len[g]; w++) {
    for (int i = w * 128 + ics->swb_offset[b]; i < w * 128 + ics->swb_offset[b + 1]; i++) {
      max34 = fmaxf(max34, c->x34[i]);
    }
  }
  if (max34 <= 0.0f) {
    return 0;
  }
  const int sf =
      (int)ceilf(100.0f - (16.0f / 3.0f) * log2f(((float)AAC_ESC_MAX_MAG - 0.5f) / max34));
  return std::clamp(sf, 0, kMaxSf);
}

/* Largest (coarsest) scalefactor whose quantization noise stays within
 * allowed, searched by bisection from sf_min. */
int find_scalefactor(AacEncChannel* c, int g, int w0, int b, int sf_min, float allowed) {
  int lo = sf_min;
  int hi = kMaxSf;
  while (lo < hi) {
    const int mid = (lo + hi + 1) / 2;
    quantize_band(c, g, w0, b, mid);
    if (band_noise(c, g, w0, b, mid) <= allowed) {
      lo = mid;
    } else {
      hi = mid - 1;
    }
  }
  return lo;
}

int spectral_bits(const AacEncChannel* c, int g, int w0, int b, int cb) {
  const AacIcsInfo* ics = &c->ics;
  const int dim = aac_codebook_info[cb].dim;
  int bits = 0;
  for (int w = w0; w < w0 + ics->group_len[g]; w++) {
    for (int i = w * 128 + ics->swb_offset[b]; i < w * 128 + ics->swb_offset[b + 1]; i += dim) {
      bits += aac_huffman_bits(cb, c->quant + i);
    }
  }
  return bits;
}

int band_max_mag(const AacEncChannel* c, int g, int w0, int b) {
  const AacIcsInfo* ics = &c->ics;
  int max_mag = 0;
  for (int w = w0; w < w0 + ics->group_len[g]; w++) {
    for (int i = w * 128 + ics->swb_offset[b]; i < w * 128 + ics->swb_offset[b + 1]; i++) {
      const int q = c->quant[i] < 0 ? -c->quant[i] : c->quant[i];
      if (q > max_mag) {
        max_mag = q;
      }
    }
  }
  return max_mag;
}

/* Spectral bits of the band with its cheapest applicable codebook. */
int best_spectral_bits(const AacEncChannel* c, int g, int w0, int b) {
  const int max_mag = band_max_mag(c, g, w0, b);
  int best = kInfBits;
  for (int cb = 1; cb <= AAC_NUM_CODEBOOKS; cb++) {
    if (kCodebookMaxVal[cb] >= max_mag) {
      best = std::min(best, spectral_bits(c, g, w0, b, cb));
    }
  }
  return best;
}

/* ── sectioning (ISO/IEC 14496-3 4.6.3, Table 4.46) ─────────────── */

/* Chooses the codebook of every band of group g below max_sfb so that the
 * section and spectral bits are minimal, and returns those bits.  A band
 * with nonzero values may use any book whose range covers its maximum; a
 * zero band may also use ZERO_HCB; a band of a coding tool keeps its
 * book (noise or intensity) and carries no spectral data. */
int section_group(AacEncChannel* c, int g, int w0, int max_sfb) {
  const AacIcsInfo* ics = &c->ics;
  const int run_bits = ics->num_windows == 8 ? 3 : 5;
  const int header = 4 + run_bits;
  int cost[AAC_MAX_SFB_LONG][kNumBooks];
  int from[AAC_MAX_SFB_LONG][kNumBooks];
  int* cb_out = c->cb + g * ics->num_swb;

  for (int b = 0; b < max_sfb; b++) {
    const int tool = c->tool[g * ics->num_swb + b];
    const int max_mag = tool ? 0 : band_max_mag(c, g, w0, b);
    for (int cb = 0; cb < kNumBooks; cb++) {
      cost[b][cb] = kInfBits;
      from[b][cb] = -1;
      if (tool ? cb != tool
               : (cb > AAC_NUM_CODEBOOKS ||
                  (cb == 0 ? max_mag != 0 : kCodebookMaxVal[cb] < max_mag))) {
        continue;
      }
      /* A zero band coded with a real book also carries a scalefactor
       * (a zero difference, one bit). */
      const int band =
          cb == 0 || tool ? 0 : spectral_bits(c, g, w0, b, cb) + (max_mag == 0 ? 1 : 0);
      if (b == 0) {
        cost[b][cb] = header + band;
        continue;
      }
      for (int prev = 0; prev < kNumBooks; prev++) {
        if (cost[b - 1][prev] >= kInfBits) {
          continue;
        }
        const int total = cost[b - 1][prev] + (prev == cb ? 0 : header) + band;
        if (total < cost[b][cb]) {
          cost[b][cb] = total;
          from[b][cb] = prev;
        }
      }
    }
  }
  if (max_sfb == 0) {
    return 0;
  }
  int best = 0;
  for (int cb = 1; cb < kNumBooks; cb++) {
    if (cost[max_sfb - 1][cb] < cost[max_sfb - 1][best]) {
      best = cb;
    }
  }
  int bits = cost[max_sfb - 1][best];
  for (int b = max_sfb - 1; b >= 0; b--) {
    cb_out[b] = best;
    best = from[b][best];
  }
  /* Runs at or beyond the escape length need extra run words. */
  const int esc = (1 << run_bits) - 1;
  for (int b = 0; b < max_sfb;) {
    int run = 1;
    while (b + run < max_sfb && cb_out[b + run] == cb_out[b]) {
      run++;
    }
    bits += (run / esc) * run_bits;
    b += run;
  }
  return bits;
}

/* ── per-channel quantization against the allowed noise ─────────── */

/* Chooses a scalefactor per coded band (allowed[idx] >= 0) so that the
 * band's noise stays within its allowance, smooths the scalefactors where
 * that saves bits, and quantizes.  Bands of a coding tool keep their book
 * and value. */
void quantize_channel(AacEncChannel* c) {
  const AacIcsInfo* ics = &c->ics;
  const int nswb = ics->num_swb;
  int w0 = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < nswb; b++) {
      const int idx = g * nswb + b;
      c->cb[idx] = 0;
      c->sf[idx] = -1;
      if (c->tool[idx]) {
        c->cb[idx] = c->tool[idx];
        c->sf[idx] = c->tool_sf[idx];
        continue;
      }
      if (c->allowed[idx] < 0.0f) {
        continue;
      }
      const int sf_min = min_scalefactor(c, g, w0, b);
      c->sf[idx] = find_scalefactor(c, g, w0, b, sf_min, c->allowed[idx]);
      c->cb[idx] = 1; /* coded; the book is chosen by sectioning */
    }
    w0 += ics->group_len[g];
  }

  /* Scalefactor smoothing (libfdk-aac sf_estim.cpp): a band that can take
   * its predecessor's scalefactor without exceeding its noise allowance
   * does so when the spectral bits grow less than the scalefactor code
   * shrinks. */
  w0 = 0;
  int prev_sf = -1;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < nswb; b++) {
      const int idx = g * nswb + b;
      if (c->cb[idx] == 0 || is_tool_book(c->cb[idx])) {
        continue;
      }
      const int sf = c->sf[idx];
      if (prev_sf >= 0 && prev_sf != sf && std::abs(prev_sf - sf) <= kMaxSfDelta) {
        quantize_band(c, g, w0, b, prev_sf);
        if (band_noise(c, g, w0, b, prev_sf) <= c->allowed[idx]) {
          const int bits_new = best_spectral_bits(c, g, w0, b) + 1;
          quantize_band(c, g, w0, b, sf);
          const int bits_old = best_spectral_bits(c, g, w0, b) +
                               aac_scalefactor_bits[sf - prev_sf + AAC_SCALE_DIFF_ZERO];
          if (bits_new < bits_old) {
            c->sf[idx] = prev_sf;
          }
        }
      }
      prev_sf = c->sf[idx];
    }
    w0 += ics->group_len[g];
  }

  /* Scalefactor differences are limited to +-60 (Table 4.47).  Pull every
   * coded band towards its predecessor; the finer direction only costs
   * bits, the coarser one adds noise but keeps the stream valid.  A band
   * that quantizes to nothing leaves the chain, which can open a new gap
   * between its neighbours, so the clamp runs again until no band drops. */
  int first_coded = -1;
  for (;;) {
    int prev = -1;
    for (int idx = 0; idx < ics->num_window_groups * nswb; idx++) {
      if (c->cb[idx] == 0 || is_tool_book(c->cb[idx])) {
        continue;
      }
      if (prev >= 0) {
        c->sf[idx] = std::clamp(c->sf[idx], prev - kMaxSfDelta, prev + kMaxSfDelta);
      }
      prev = c->sf[idx];
    }

    /* Final quantization with the chosen scalefactors. */
    memset(c->quant, 0, sizeof(c->quant));
    w0 = 0;
    first_coded = -1;
    int dropped = 0;
    for (int g = 0; g < ics->num_window_groups; g++) {
      for (int b = 0; b < nswb; b++) {
        const int idx = g * nswb + b;
        if (c->cb[idx] == 0 || is_tool_book(c->cb[idx])) {
          continue;
        }
        if (quantize_band(c, g, w0, b, c->sf[idx]) == 0) {
          c->cb[idx] = 0;
          c->sf[idx] = -1;
          dropped++;
          continue;
        }
        if (first_coded < 0) {
          first_coded = idx;
        }
      }
      w0 += ics->group_len[g];
    }
    if (!dropped) {
      break;
    }
  }
  c->global_gain = first_coded >= 0 ? c->sf[first_coded] : 100;
}

/* Highest coded band + 1 over all groups. */
int channel_max_sfb(const AacEncChannel* c) {
  const AacIcsInfo* ics = &c->ics;
  int max_sfb = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < ics->num_swb; b++) {
      if (c->cb[g * ics->num_swb + b] != 0 && b + 1 > max_sfb) {
        max_sfb = b + 1;
      }
    }
  }
  return max_sfb;
}

/* First noise band of a channel: 9-bit PCM difference to the global gain
 * (ISO/IEC 14496-3 4.6.2.3), kept within the field. */
int pns_first_value(const AacEncChannel* c, int nrg) {
  return std::clamp(nrg - (c->global_gain - 90) + 256, 0, 511);
}

/* Sections every group and assigns the scalefactors of zero bands that
 * ended up with a real book; returns the section, scalefactor and spectral
 * bits of the channel.  Scalefactors, noise energies and intensity
 * positions form three separate DPCM chains. */
int finish_channel(AacEncChannel* c, int max_sfb) {
  const AacIcsInfo* ics = &c->ics;
  const int nswb = ics->num_swb;
  int bits = 0;
  int w0 = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    bits += section_group(c, g, w0, max_sfb);
    for (int b = max_sfb; b < nswb; b++) {
      c->cb[g * nswb + b] = 0;
    }
    w0 += ics->group_len[g];
  }
  int prev = c->global_gain;
  int prev_noise = 0;
  int prev_is = 0;
  bool first_noise = true;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < max_sfb; b++) {
      const int idx = g * nswb + b;
      const int cb = c->cb[idx];
      if (cb == 0) {
        continue;
      }
      if (cb == AAC_CB_NOISE) {
        if (first_noise) {
          bits += kPnsFirstBits;
          first_noise = false;
        } else {
          bits += aac_scalefactor_bits[c->sf[idx] - prev_noise + AAC_SCALE_DIFF_ZERO];
        }
        prev_noise = c->sf[idx];
        continue;
      }
      if (cb == AAC_CB_INTENSITY || cb == AAC_CB_INTENSITY2) {
        bits += aac_scalefactor_bits[c->sf[idx] - prev_is + AAC_SCALE_DIFF_ZERO];
        prev_is = c->sf[idx];
        continue;
      }
      if (c->sf[idx] < 0) {
        c->sf[idx] = prev; /* zero band inside a section: zero difference */
      }
      bits += aac_scalefactor_bits[c->sf[idx] - prev + AAC_SCALE_DIFF_ZERO];
      prev = c->sf[idx];
    }
  }
  return bits;
}

/* ── stereo ────────────────────────────────────────────────────── */

/* Per-band M/S decision (libfdk-aac ms_stereo.cpp): M/S wins when the
 * noise-to-energy product of mid and side at the smaller threshold beats
 * that of left and right.  M/S bands share the smaller threshold.
 * Intensity bands stay as they are. */
void decide_ms(AacEncoderState* s, AacEncElement* el) {
  AacEncChannel* l = &s->ch[el->ch[0]];
  AacEncChannel* r = &s->ch[el->ch[1]];
  int* ms_used = el->ms_used;
  const AacIcsInfo* ics = &l->ics;
  const int nswb = ics->num_swb;
  int lr_bands = 0;
  int w0 = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < nswb; b++) {
      const int idx = g * nswb + b;
      if (r->tool[idx] == AAC_CB_INTENSITY || r->tool[idx] == AAC_CB_INTENSITY2) {
        ms_used[idx] = 0;
        continue;
      }
      float em = 0.0f;
      float es = 0.0f;
      for (int w = w0; w < w0 + ics->group_len[g]; w++) {
        for (int i = w * 128 + ics->swb_offset[b]; i < w * 128 + ics->swb_offset[b + 1]; i++) {
          const float m = 0.5f * (l->coef[i] + r->coef[i]);
          const float d = 0.5f * (l->coef[i] - r->coef[i]);
          em += m * m;
          es += d * d;
        }
      }
      const float thr_min = fminf(l->thr[idx], r->thr[idx]);
      const float pn_lr = (l->thr[idx] / fmaxf(l->energy[idx], l->thr[idx])) *
                          (r->thr[idx] / fmaxf(r->energy[idx], r->thr[idx]));
      const float pn_ms = (thr_min / fmaxf(em, thr_min)) * (thr_min / fmaxf(es, thr_min));
      ms_used[idx] = pn_ms > pn_lr;
      if (!ms_used[idx]) {
        lr_bands++;
        continue;
      }
      for (int w = w0; w < w0 + ics->group_len[g]; w++) {
        for (int i = w * 128 + ics->swb_offset[b]; i < w * 128 + ics->swb_offset[b + 1]; i++) {
          const float m = 0.5f * (l->coef[i] + r->coef[i]);
          const float d = 0.5f * (l->coef[i] - r->coef[i]);
          l->coef[i] = m;
          r->coef[i] = d;
        }
      }
      l->energy[idx] = em;
      r->energy[idx] = es;
      l->thr[idx] = r->thr[idx] = thr_min;
      l->spread[idx] = r->spread[idx] = 0.5f * fminf(l->spread[idx], r->spread[idx]);
    }
    w0 += ics->group_len[g];
  }
  (void)lr_bands;
}

/* ── block switching and filterbank ────────────────────────────── */

/* Short windows of a frame cover samples 448 .. 1600 of its 2048-sample
 * window; the frame being coded starts at pcm[0], the next at pcm[1024]. */
constexpr int kShortRegionStart = 448;
constexpr int kShortRegionLen = 9 * kShort;

/* Window groups per attack window (libfdk-aac block_switch.cpp). */
const int kGrouping[8][4] = {{1, 3, 3, 1}, {1, 1, 3, 3}, {2, 1, 3, 2}, {3, 1, 3, 1},
                             {3, 1, 1, 3}, {3, 2, 1, 2}, {3, 3, 1, 1}, {3, 3, 1, 1}};

void choose_window_sequence(AacEncoderState* s, const AacEncElement* el) {
  int attack_now = 0;
  int attack_next = 0;
  /* An LFE element codes long windows only. */
  if (el->type != AAC_ELEM_LFE) {
    for (int k = 0; k < el->num_ch; k++) {
      const float* pcm = s->ch[el->ch[k]].pcm;
      attack_now = std::max(attack_now, aac_psy_attack(pcm, kShortRegionStart, kShortRegionLen));
      attack_next =
          std::max(attack_next, aac_psy_attack(pcm, kFrame + kShortRegionStart, kShortRegionLen));
    }
  }
  const int prev = s->ch[el->ch[0]].ics.window_sequence;
  int seq = AAC_WIN_ONLY_LONG;
  if (attack_now || (prev == AAC_WIN_EIGHT_SHORT && attack_next)) {
    /* No window both stops and starts short blocks in AAC-LC, so a short
     * frame between two short frames stays short. */
    seq = AAC_WIN_EIGHT_SHORT;
  } else if (attack_next) {
    seq = AAC_WIN_LONG_START;
  } else if (prev == AAC_WIN_EIGHT_SHORT) {
    seq = AAC_WIN_LONG_STOP;
  }
  int grouping = 0;
  if (seq == AAC_WIN_EIGHT_SHORT) {
    const int* lens = kGrouping[std::clamp(attack_now - 1, 0, 7)];
    int w = 0;
    for (int g = 0; g < 4; g++) {
      for (int i = 1; i < lens[g]; i++) {
        grouping |= 1 << (6 - (w + i - 1));
      }
      w += lens[g];
    }
  }
  const int shape =
      (seq == AAC_WIN_ONLY_LONG || seq == AAC_WIN_LONG_STOP) ? AAC_WIN_KBD : AAC_WIN_SINE;
  for (int k = 0; k < el->num_ch; k++) {
    AacEncChannel* c = &s->ch[el->ch[k]];
    aac_ics_layout(&c->ics, s->rate_index, seq, grouping);
    c->ics.window_shape = shape;
  }
}

/* Windows pcm[0 .. 2048) as ISO/IEC 14496-3 4.6.11.3.2 and transforms it. */
void window_and_mdct(AacEncoderState* s, AacEncChannel* c) {
  const AacIcsInfo* ics = &c->ics;
  const float* lw_prev = aac_window_long[c->prev_window_shape];
  const float* sw_prev = aac_window_short[c->prev_window_shape];
  const float* lw = aac_window_long[ics->window_shape];
  const float* sw = aac_window_short[ics->window_shape];
  float buf[2 * kFrame];
  const int seq = ics->window_sequence;

  if (seq != AAC_WIN_EIGHT_SHORT) {
    if (seq == AAC_WIN_LONG_STOP) {
      memset(buf, 0, 448 * sizeof(float));
      for (int i = 0; i < kShort; i++) {
        buf[448 + i] = c->pcm[448 + i] * sw_prev[i];
      }
      memcpy(buf + 576, c->pcm + 576, 448 * sizeof(float));
    } else {
      s->dsp->vector_fmul(buf, c->pcm, lw_prev, kFrame);
    }
    if (seq == AAC_WIN_LONG_START) {
      memcpy(buf + kFrame, c->pcm + kFrame, 448 * sizeof(float));
      for (int i = 0; i < kShort; i++) {
        buf[1472 + i] = c->pcm[1472 + i] * sw[kShort - 1 - i];
      }
      memset(buf + 1600, 0, 448 * sizeof(float));
    } else {
      s->dsp->vector_fmul_reverse(buf + kFrame, c->pcm + kFrame, lw, kFrame);
    }
    aac_mdct_forward(&s->mdct_long, buf, c->coef);
  } else {
    for (int w = 0; w < 8; w++) {
      const float* src = c->pcm + kShortRegionStart + w * kShort;
      const float* left = w == 0 ? sw_prev : sw;
      for (int i = 0; i < kShort; i++) {
        buf[i] = src[i] * left[i];
        buf[kShort + i] = src[kShort + i] * sw[kShort - 1 - i];
      }
      aac_mdct_forward(&s->mdct_short, buf, c->coef + w * kShort);
    }
  }
  c->prev_window_shape = ics->window_shape;
}

/* ── bitstream writing ─────────────────────────────────────────── */

void write_ics_info(AacBitWriter* w, const AacIcsInfo* ics, int max_sfb) {
  aac_bitwriter_write(w, 0, 1); /* ics_reserved_bit */
  aac_bitwriter_write(w, (uint32_t)ics->window_sequence, 2);
  aac_bitwriter_write(w, (uint32_t)ics->window_shape, 1);
  if (ics->num_windows == 8) {
    aac_bitwriter_write(w, (uint32_t)max_sfb, 4);
    int grouping = 0;
    int win = 0;
    for (int g = 0; g < ics->num_window_groups; g++) {
      for (int i = 1; i < ics->group_len[g]; i++) {
        grouping |= 1 << (6 - (win + i - 1));
      }
      win += ics->group_len[g];
    }
    aac_bitwriter_write(w, (uint32_t)grouping, 7);
  } else {
    aac_bitwriter_write(w, (uint32_t)max_sfb, 6);
    aac_bitwriter_write(w, 0, 1); /* predictor_data_present */
  }
}

void write_channel_stream(AacBitWriter* w, const AacEncChannel* c, int max_sfb, int common_window) {
  const AacIcsInfo* ics = &c->ics;
  const int nswb = ics->num_swb;
  aac_bitwriter_write(w, (uint32_t)c->global_gain, 8);
  if (!common_window) {
    write_ics_info(w, ics, max_sfb);
  }

  /* section_data */
  const int run_bits = ics->num_windows == 8 ? 3 : 5;
  const int esc = (1 << run_bits) - 1;
  for (int g = 0; g < ics->num_window_groups; g++) {
    const int* cb = c->cb + g * nswb;
    for (int b = 0; b < max_sfb;) {
      int run = 1;
      while (b + run < max_sfb && cb[b + run] == cb[b]) {
        run++;
      }
      aac_bitwriter_write(w, (uint32_t)cb[b], 4);
      int count = run;
      while (count >= esc) {
        aac_bitwriter_write(w, (uint32_t)esc, run_bits);
        count -= esc;
      }
      aac_bitwriter_write(w, (uint32_t)count, run_bits);
      b += run;
    }
  }

  /* scale_factor_data: scalefactors, noise energies and intensity
   * positions each continue their own chain. */
  int prev = c->global_gain;
  int prev_noise = 0;
  int prev_is = 0;
  bool first_noise = true;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < max_sfb; b++) {
      const int idx = g * nswb + b;
      const int cb = c->cb[idx];
      if (cb == 0) {
        continue;
      }
      if (cb == AAC_CB_NOISE) {
        if (first_noise) {
          aac_bitwriter_write(w, (uint32_t)pns_first_value(c, c->sf[idx]), kPnsFirstBits);
          first_noise = false;
        } else {
          aac_bitwriter_write_scalefactor(w, c->sf[idx] - prev_noise);
        }
        prev_noise = c->sf[idx];
      } else if (cb == AAC_CB_INTENSITY || cb == AAC_CB_INTENSITY2) {
        aac_bitwriter_write_scalefactor(w, c->sf[idx] - prev_is);
        prev_is = c->sf[idx];
      } else {
        aac_bitwriter_write_scalefactor(w, c->sf[idx] - prev);
        prev = c->sf[idx];
      }
    }
  }

  aac_bitwriter_write(w, 0, 1); /* pulse_data_present */
  aac_bitwriter_write(w, (uint32_t)c->tns_present, 1);
  if (c->tns_present) {
    aac_enc_tns_write(w, c);
  }
  aac_bitwriter_write(w, 0, 1); /* gain_control_data_present */

  /* spectral_data */
  int w0 = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < max_sfb; b++) {
      const int cb = c->cb[g * nswb + b];
      if (cb == 0 || is_tool_book(cb)) {
        continue;
      }
      const int dim = aac_codebook_info[cb].dim;
      for (int win = w0; win < w0 + ics->group_len[g]; win++) {
        for (int i = win * 128 + ics->swb_offset[b]; i < win * 128 + ics->swb_offset[b + 1];
             i += dim) {
          aac_bitwriter_write_huffman(w, cb, c->quant + i);
        }
      }
    }
    w0 += ics->group_len[g];
  }
}

/* Bands coded as M/S below max_sfb; the mask is sent as "all" when every
 * band uses it. */
int ms_present_mode(const AacEncoderState* s, const AacEncElement* el) {
  const AacIcsInfo* ics = &s->ch[el->ch[0]].ics;
  int used = 0;
  const int nbands = ics->num_window_groups * el->max_sfb;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < el->max_sfb; b++) {
      used += el->ms_used[g * ics->num_swb + b];
    }
  }
  return used == 0 ? 0 : (used == nbands ? 2 : 1);
}

/* program_config_element (ISO/IEC 14496-3 4.4.1.1) for the layout: the
 * elements by position, no mixdowns, no associated data, no comment.  The
 * ADTS raw data block starts byte aligned, so the element's alignment is
 * the writer's. */
void write_pce(AacBitWriter* w, const AacLayout* l, int rate_index) {
  aac_bitwriter_write(w, 0, 4); /* element_instance_tag */
  aac_bitwriter_write(w, AAC_AOT_LC - 1, 2);
  aac_bitwriter_write(w, (uint32_t)rate_index, 4);
  static const int kPositions[4] = {AAC_POS_FRONT, AAC_POS_SIDE, AAC_POS_BACK, AAC_POS_LFE};
  for (int p = 0; p < 4; p++) {
    int n = 0;
    for (int e = 0; e < l->num_elements; e++) {
      n += l->elems[e].position == kPositions[p];
    }
    aac_bitwriter_write(w, (uint32_t)n, p == 3 ? 2 : 4);
  }
  aac_bitwriter_write(w, 0, 3); /* num_assoc_data_elements */
  aac_bitwriter_write(w, 0, 4); /* num_valid_cc_elements */
  aac_bitwriter_write(w, 0, 3); /* mono, stereo and matrix mixdown absent */
  for (int p = 0; p < 4; p++) {
    for (int e = 0; e < l->num_elements; e++) {
      const AacLayoutElement* el = &l->elems[e];
      if (el->position != kPositions[p]) {
        continue;
      }
      if (p < 3) {
        aac_bitwriter_write(w, el->type == AAC_ELEM_CPE ? 1 : 0, 1);
      }
      aac_bitwriter_write(w, (uint32_t)el->tag, 4);
    }
  }
  aac_bitwriter_byte_align(w);
  /* A layout with an element outside the normal layer carries the height
   * extension in the comment field: the sync byte, two bits per front,
   * side and back element in that order, alignment and the CRC. */
  bool height = false;
  for (int e = 0; e < l->num_elements; e++) {
    height = height || l->elems[e].height != AAC_HEIGHT_NORMAL;
  }
  if (!height) {
    aac_bitwriter_write(w, 0, 8); /* comment_field_bytes */
    return;
  }
  uint8_t bytes[1 + (2 * AAC_MAX_ELEMENTS + 7) / 8] = {AAC_PCE_HEIGHT_EXTENSION_SYNC};
  int n = 0;
  for (int p = 0; p < 3; p++) {
    for (int e = 0; e < l->num_elements; e++) {
      const AacLayoutElement* el = &l->elems[e];
      if (el->position == kPositions[p]) {
        bytes[1 + n / 4] |= (uint8_t)(el->height << (6 - 2 * (n % 4)));
        n++;
      }
    }
  }
  const int len = 1 + (2 * n + 7) / 8;
  aac_bitwriter_write(w, (uint32_t)(len + 1), 8); /* comment_field_bytes */
  for (int i = 0; i < len; i++) {
    aac_bitwriter_write(w, bytes[i], 8);
  }
  aac_bitwriter_write(w, aac_pce_height_crc(bytes, len), 8);
}

/* Bits of the program config element for the layout. */
int pce_bits(const AacLayout* l) {
  const int bits = 3 + 34 + 5 * l->num_elements + 7 + 8;
  for (int e = 0; e < l->num_elements; e++) {
    if (l->elems[e].height != AAC_HEIGHT_NORMAL) {
      return bits + 8 * (2 + (2 * l->num_elements + 7) / 8);
    }
  }
  return bits;
}

/* Writes the whole ADTS frame for the current quantization; returns its
 * length in bytes or AAC_ERR_OVERFLOW. */
/* The fill element with the SBR data of element e, when it has any. */
void write_sbr(const AacEncoderState* s, int e, AacBitWriter* w) {
  if (s->sbr[e]) {
    aac_sbr_enc_write(s->sbr[e], w);
  }
}

int write_frame(AacEncoderState* s, int buffer_fullness) {
  AacBitWriter w;
  aac_bitwriter_init(&w, s->output_buf, sizeof(s->output_buf));
  /* The header is filled in below; it is longer than one write takes. */
  aac_bitwriter_write(&w, 0, 32);
  aac_bitwriter_write(&w, 0, kAdtsBits - 32);

  if (s->channel_config == 0) {
    aac_bitwriter_write(&w, AAC_ELEM_PCE, 3);
    write_pce(&w, &s->layout, s->rate_index);
  }
  for (int e = 0; e < s->num_elements; e++) {
    const AacEncElement* el = &s->elem[e];
    const int max_sfb = el->max_sfb;
    aac_bitwriter_write(&w, (uint32_t)el->type, 3);
    aac_bitwriter_write(&w, (uint32_t)el->tag, 4);
    if (el->type != AAC_ELEM_CPE) {
      write_channel_stream(&w, &s->ch[el->ch[0]], max_sfb, 0);
      write_sbr(s, e, &w);
      continue;
    }
    const AacIcsInfo* ics = &s->ch[el->ch[0]].ics;
    aac_bitwriter_write(&w, 1, 1); /* common_window */
    write_ics_info(&w, ics, max_sfb);
    const int ms_present = ms_present_mode(s, el);
    aac_bitwriter_write(&w, (uint32_t)ms_present, 2);
    if (ms_present == 1) {
      for (int g = 0; g < ics->num_window_groups; g++) {
        for (int b = 0; b < max_sfb; b++) {
          aac_bitwriter_write(&w, (uint32_t)el->ms_used[g * ics->num_swb + b], 1);
        }
      }
    }
    write_channel_stream(&w, &s->ch[el->ch[0]], max_sfb, 1);
    write_channel_stream(&w, &s->ch[el->ch[1]], max_sfb, 1);
    write_sbr(s, e, &w);
  }
  aac_bitwriter_write(&w, AAC_ELEM_END, 3);
  aac_bitwriter_byte_align(&w);
  if (aac_bitwriter_overflow(&w)) {
    return AAC_ERR_OVERFLOW;
  }

  AacAdtsHeader hdr = {};
  hdr.id = s->mpeg2;
  hdr.protection_absent = 1;
  hdr.object_type = AAC_AOT_LC;
  hdr.sample_rate_index = s->rate_index;
  hdr.channel_config = s->channel_config;
  hdr.frame_length = aac_bitwriter_bytes_written(&w);
  hdr.buffer_fullness = buffer_fullness;
  hdr.num_raw_blocks = 1;
  aac_adts_write(&hdr, s->output_buf);
  return hdr.frame_length;
}

/* ── rate control ──────────────────────────────────────────────── */

/* Side-information bits of the frame apart from sections and scalefactors,
 * with each element's max_sfb of the previous quantization. */
int frame_overhead_bits(const AacEncoderState* s) {
  int bits = kAdtsBits + 3 + 7; /* header, END, worst-case byte alignment */
  if (s->channel_config == 0) {
    bits += pce_bits(&s->layout);
  }
  for (int e = 0; e < s->num_elements; e++) {
    const AacEncElement* el = &s->elem[e];
    const AacIcsInfo* ics = &s->ch[el->ch[0]].ics;
    const int ics_info = ics->num_windows == 8 ? 15 : 11;
    bits += 7 + ics_info;
    if (el->type != AAC_ELEM_CPE) {
      bits += 8 + 3 + aac_enc_tns_bits(&s->ch[el->ch[0]]);
    } else {
      bits += 1 + 2 + ics->num_window_groups * el->max_sfb + 2 * (8 + 3) +
              aac_enc_tns_bits(&s->ch[el->ch[0]]) + aac_enc_tns_bits(&s->ch[el->ch[1]]);
    }
    if (s->sbr[e]) {
      bits += aac_sbr_enc_bits(s->sbr[e]);
    }
  }
  return bits;
}

/* Whether any channel of the frame uses short windows. */
bool frame_is_short(const AacEncoderState* s) {
  for (int c = 0; c < s->channels; c++) {
    if (s->ch[c].ics.num_windows == 8) {
      return true;
    }
  }
  return false;
}

/* Perceptual entropy of the unreduced thresholds (3GPP TS 26.403
 * 5.6.1.1.2), in bits. */
float frame_pe(const AacEncoderState* s) {
  float pe = 0.0f;
  for (int c = 0; c < s->channels; c++) {
    const AacEncChannel* ch = &s->ch[c];
    const AacIcsInfo* ics = &ch->ics;
    const int active = ics->num_windows == 8 ? ch->active_short : ch->active_long;
    for (int g = 0; g < ics->num_window_groups; g++) {
      for (int b = 0; b < active; b++) {
        const int idx = g * ics->num_swb + b;
        if (ch->tool[idx] || ch->energy[idx] <= ch->thr[idx]) {
          continue;
        }
        float band_pe = log2f(ch->energy[idx] / ch->thr[idx]);
        if (band_pe < 3.0f) {
          band_pe = band_pe * 0.5593573f + 1.3219281f;
        }
        pe += band_pe * (float)(ics->group_len[g] * (ics->swb_offset[b + 1] - ics->swb_offset[b]));
      }
    }
  }
  return pe / kBitsToPe;
}

/* Bits granted to this frame from the reservoir fill and the frame's
 * perceptual entropy (libfdk-aac adj_thr.cpp bitresCalcBitFac): frames
 * harder than usual may borrow, easier ones save. */
int bit_demand(AacEncoderState* s, float pe, int avg_dyn_bits) {
  const bool is_short = frame_is_short(s);
  const float fill = s->reservoir_max > 0 ? (float)s->reservoir / (float)s->reservoir_max : 1.0f;
  float bit_save, bit_spend;
  if (!is_short) {
    const float f = std::clamp(fill, 0.2f, 0.95f) - 0.2f;
    bit_save = 0.3f - f * 0.4666667f;
    bit_spend = -0.1f + f * 0.6666667f;
  } else {
    const float f = std::clamp(fill, 0.2f, 0.75f) - 0.2f;
    bit_save = 0.2f - f * 0.3636364f;
    bit_spend = -0.05f + f * 1.0f;
  }
  const float pex = std::clamp(pe, s->pe_min, s->pe_max);
  const float slope = s->pe_max > s->pe_min ? (pex - s->pe_min) / (s->pe_max - s->pe_min) : 0.5f;
  float factor = 1.0f - bit_save + slope * (bit_spend + bit_save);
  /* Never more than the reservoir can cover. */
  factor = std::min(factor, 1.0f + (float)s->reservoir / (float)std::max(avg_dyn_bits, 1));

  /* Track the range of recent entropies so that "hard" and "easy" stay
   * relative to the material. */
  if (pe > s->pe_max) {
    const float diff = pe - s->pe_max;
    s->pe_min += 0.3f * diff;
    s->pe_max += diff;
  } else if (pe < s->pe_min) {
    const float diff = s->pe_min - pe;
    s->pe_min -= 0.14f * diff;
    s->pe_max -= 0.07f * diff;
  } else {
    s->pe_min += 0.3f * (pe - s->pe_min);
    s->pe_max -= 0.07f * (s->pe_max - pe);
  }
  return (int)((float)avg_dyn_bits * factor);
}

struct ReleaseEntry {
  int ch, idx, sfb;
  float energy;
};

/* Prepares the per-band minimum SNR and hole flags of the frame
 * (libfdk-aac adj_thr.cpp adaptMinSnr / initAvoidHoleFlag) and lists the
 * bands that may be released, highest first, when the budget still fails. */
int prepare_bands(AacEncoderState* s, ReleaseEntry* release) {
  int n_release = 0;
  for (int c = 0; c < s->channels; c++) {
    AacEncChannel* ch = &s->ch[c];
    const AacIcsInfo* ics = &ch->ics;
    const int is_short = ics->num_windows == 8;
    const int nswb = ics->num_swb;
    const int active = is_short ? ch->active_short : ch->active_long;
    const float* table = is_short ? s->min_snr_short : s->min_snr_long;

    float avg = 0.0f;
    int n = 0;
    for (int g = 0; g < ics->num_window_groups; g++) {
      for (int b = 0; b < active; b++) {
        avg += ch->energy[g * nswb + b];
        n++;
      }
    }
    avg = n ? avg / (float)n : 0.0f;

    for (int g = 0; g < ics->num_window_groups; g++) {
      for (int b = 0; b < nswb; b++) {
        const int idx = g * nswb + b;
        const float e = ch->energy[idx];
        float min_snr = b < active ? table[b] : kMinSnrCeil;
        /* Bands far below the frame's average energy need less SNR. */
        if (e > 0.0f && avg > 10.0f * e) {
          const float db = 10.0f * log10f(avg / e);
          const float red = std::max(1.375f - 0.0375f * db, 0.25f);
          min_snr = std::min(kMinSnrCeil, powf(min_snr, red));
        }
        /* Local peaks need more SNR than their neighbours, valleys less. */
        if (s->bitrate / s->channels >= 20000 && b < active) {
          const float e_lo = b > 0 ? ch->energy[idx - 1] : e;
          const float e_hi = b + 1 < active ? ch->energy[idx + 1] : e;
          const float neighbours = 0.5f * (e_lo + e_hi);
          if (e > neighbours) {
            min_snr = std::min(min_snr,
                               kMinSnrCeil * std::max(neighbours / e, is_short ? 0.625f : 0.395f));
          } else if (e > 0.0f && 2.0f * e < neighbours) {
            const float relaxed = std::min(kMinSnrCeil, 0.5f * neighbours / e * min_snr);
            min_snr = std::min(relaxed, min_snr * 3.16f);
          }
        }
        ch->min_snr[idx] = min_snr;
        const float spread = ch->spread[idx] * (is_short ? 0.63f : 0.5f);
        ch->avoid_hole[idx] = !(spread > e);
        if (b < active && e > ch->thr[idx] && ch->avoid_hole[idx] && !ch->tool[idx]) {
          release[n_release++] = {c, idx, b, e};
        }
      }
    }
  }
  std::sort(release, release + n_release, [](const ReleaseEntry& a, const ReleaseEntry& b) {
    return a.sfb != b.sfb ? a.sfb > b.sfb : a.energy < b.energy;
  });
  return n_release;
}

/* Sets each band's noise allowance for one working point: thresholds are
 * raised by `reduction` in the fourth-root domain, bands that would end
 * up below their minimum SNR hold there unless released, and bands whose
 * allowance reaches their energy are left out.  With improve < 1 and no
 * reduction, every threshold is scaled down instead. */
void set_allowances(AacEncoderState* s, float reduction, float improve, const ReleaseEntry* release,
                    int released) {
  for (int c = 0; c < s->channels; c++) {
    AacEncChannel* ch = &s->ch[c];
    const AacIcsInfo* ics = &ch->ics;
    const int nswb = ics->num_swb;
    const int active = ics->num_windows == 8 ? ch->active_short : ch->active_long;
    for (int g = 0; g < ics->num_window_groups; g++) {
      for (int b = 0; b < nswb; b++) {
        const int idx = g * nswb + b;
        const float e = ch->energy[idx];
        const float thr = ch->thr[idx];
        float allowed = -1.0f;
        if (b < active && e > thr && !ch->tool[idx]) {
          if (improve != 1.0f) {
            allowed = thr * improve;
          } else {
            const float root = sqrtf(sqrtf(thr)) + reduction;
            allowed = root * root * root * root;
            const float floor = e * ch->min_snr[idx];
            if (allowed > floor && ch->avoid_hole[idx]) {
              allowed = fmaxf(thr, floor);
            }
          }
          if (allowed >= e) {
            allowed = -1.0f;
          }
        }
        ch->allowed[idx] = allowed;
      }
    }
  }
  for (int i = 0; i < released; i++) {
    s->ch[release[i].ch].allowed[release[i].idx] = -1.0f;
  }
}

/* Quantizes every channel for the current allowances and returns the
 * resulting frame size in bits. */
int quantize_frame(AacEncoderState* s) {
  int bits = 0;
  for (int e = 0; e < s->num_elements; e++) {
    AacEncElement* el = &s->elem[e];
    int max_sfb = 0;
    for (int k = 0; k < el->num_ch; k++) {
      quantize_channel(&s->ch[el->ch[k]]);
      max_sfb = std::max(max_sfb, channel_max_sfb(&s->ch[el->ch[k]]));
    }
    el->max_sfb = max_sfb;
    for (int k = 0; k < el->num_ch; k++) {
      bits += finish_channel(&s->ch[el->ch[k]], max_sfb);
    }
  }
  return bits + frame_overhead_bits(s);
}

/* Quality 1..100 of the VBR modes as a noise-to-mask ratio: 70 sits at the
 * masking threshold, 100 nine dB below it. */
float quality_factor(int quality) { return exp2f((70.0f - (float)quality) / 10.0f); }

/* Finds the working point whose frame fits in target_bits: first the
 * smallest threshold reduction, then, if the minimum-SNR floors still
 * overshoot, the fewest released bands.  With room to spare and a full
 * reservoir the thresholds are lowered instead. */
void rate_control(AacEncoderState* s, int target_bits, int max_bits, float base_improve) {
  ReleaseEntry release[2 * AAC_MAX_BANDS];
  const int n_release = prepare_bands(s, release);

  /* Largest reduction that is ever needed: it lifts every threshold to at
   * least the band energy. */
  float max_root = 0.0f;
  for (int c = 0; c < s->channels; c++) {
    const AacEncChannel* ch = &s->ch[c];
    for (int i = 0; i < ch->ics.num_window_groups * ch->ics.num_swb; i++) {
      max_root = fmaxf(max_root, sqrtf(sqrtf(ch->energy[i])));
    }
  }

  set_allowances(s, 0.0f, base_improve, release, 0);
  int bits = quantize_frame(s);
  if (bits <= target_bits) {
    const bool reservoir_full = s->reservoir >= s->reservoir_max - s->reservoir_max / 10;
    if (base_improve < 1.0f || !reservoir_full || bits > target_bits - target_bits / 8) {
      return;
    }
    /* Spend the surplus on lower noise. */
    float lo = kImproveLimitLog2;
    float hi = 0.0f;
    for (int it = 0; it < kSearchIterations; it++) {
      const float mid = 0.5f * (lo + hi);
      set_allowances(s, 0.0f, exp2f(mid), release, 0);
      if (quantize_frame(s) <= target_bits) {
        hi = mid;
      } else {
        lo = mid;
      }
    }
    set_allowances(s, 0.0f, exp2f(hi), release, 0);
    quantize_frame(s);
    return;
  }

  float lo = 0.0f;
  float hi = max_root;
  set_allowances(s, hi, 1.0f, release, 0);
  bits = quantize_frame(s);
  if (bits <= target_bits) {
    for (int it = 0; it < kSearchIterations; it++) {
      const float mid = 0.5f * (lo + hi);
      set_allowances(s, mid, 1.0f, release, 0);
      if (quantize_frame(s) <= target_bits) {
        hi = mid;
      } else {
        lo = mid;
      }
    }
    set_allowances(s, hi, 1.0f, release, 0);
    quantize_frame(s);
    return;
  }

  /* Even with every hole allowed the minimum-SNR bands do not fit: give
   * them up from the top of the spectrum down. */
  int k_lo = 0;
  int k_hi = n_release;
  while (k_lo < k_hi) {
    const int mid = (k_lo + k_hi) / 2;
    set_allowances(s, max_root, 1.0f, release, mid);
    if (quantize_frame(s) <= target_bits) {
      k_hi = mid;
    } else {
      k_lo = mid + 1;
    }
  }
  set_allowances(s, max_root, 1.0f, release, k_hi);
  bits = quantize_frame(s);
  (void)max_bits;
}



/* Channel configuration of a channel count: the standard layouts 1 to 6,
 * with 6.1 (configuration 11) for seven channels and 7.1 with a back pair
 * (configuration 12) for eight; the ADTS header cannot name either, so a
 * PCE carries them. */
int config_for_channels(int channels) {
  static const int kConfig[AAC_MAX_CHANNELS + 1] = {0, 1, 2, 3, 4, 5, 6, 11, 12};
  return channels >= 1 && channels <= AAC_MAX_CHANNELS ? kConfig[channels] : 0;
}

/* Builds the layout of the channel mode (the count's own configuration
 * for AAC_MODE_DEFAULT) and the elements that code it; returns
 * AAC_ERR_INVALID_ARG when the mode does not fit the channel count. */
int setup_layout(AacEncoderState* s, AacChannelMode mode) {
  AacLayout layout = {};
  if (mode == AAC_MODE_DEFAULT) {
    const int config = config_for_channels(s->channels);
    if (config == 0 || aac_layout_from_config(&layout, config) != AAC_OK) {
      return AAC_ERR_INVALID_ARG;
    }
  } else if (aac_layout_from_mode(&layout, mode) != AAC_OK ||
             layout.num_channels != s->channels) {
    return AAC_ERR_INVALID_ARG;
  }
  s->layout = layout;
  s->channel_mode = mode;
  s->channel_config = layout.channel_config <= 7 ? layout.channel_config : 0;
  s->num_elements = layout.num_elements;
  for (int c = 0; c < AAC_MAX_CHANNELS; c++) {
    s->ch[c].is_lfe = 0;
  }
  for (int e = 0; e < s->num_elements; e++) {
    const AacLayoutElement* le = &layout.elems[e];
    AacEncElement* el = &s->elem[e];
    el->type = le->type;
    el->tag = le->tag;
    el->num_ch = le->type == AAC_ELEM_CPE ? 2 : 1;
    for (int k = 0; k < el->num_ch; k++) {
      el->ch[k] = le->slot[k];
      s->ch[le->slot[k]].is_lfe = le->type == AAC_ELEM_LFE;
    }
  }
  return AAC_OK;
}

void destroy_sbr_encoders(AacEncoderState* s) {
  for (int e = 0; e < AAC_MAX_ELEMENTS; e++) {
    delete s->sbr[e];
    delete s->lfe_down[e];
    s->sbr[e] = nullptr;
    s->lfe_down[e] = nullptr;
  }
}

/* HE-AAC: one SBR encoder per SCE or CPE, with the element's share of
 * the bitrate; with parametric stereo the single SCE takes both inputs.
 * An LFE gets the half-rate downsampler instead. */
int create_sbr_encoders(AacEncoderState* s) {
  const bool ps = s->aot == AAC_AOT_PS;
  for (int e = 0; e < s->num_elements; e++) {
    const AacEncElement* el = &s->elem[e];
    if (el->type == AAC_ELEM_LFE) {
      s->lfe_down[e] = new AacSbrDownsampler();
      continue;
    }
    const int in_ch = ps ? 2 : el->num_ch;
    const int el_bitrate =
        ps ? s->bitrate : (int)((long long)s->bitrate * el->num_ch / s->channels);
    s->sbr[e] = new AacSbrEncoder();
    if (aac_sbr_enc_init(s->sbr[e], s->input_rate, in_ch, el_bitrate, ps)) {
      return AAC_ERR_INIT;
    }
  }
  return AAC_OK;
}

/* Lines of an LFE channel that are coded: the lowest twelve, as FFmpeg and
 * FDK limit it. */
constexpr int kLfeLines = 12;

/* Bits per second one SBR channel is expected to take from the bitrate
 * when the core's bandwidth and noise targets are chosen. */
constexpr int kSbrBitrateEstimate = 1500;

}  // namespace

AacEncoderState* aac_encoder_state_create(int sr, int ch, int br, AacObjectType aot,
                                          AacRateControl rc, const AacDSP* dsp) {
  aac_tables_init();
  init_sf_tables();
  const bool sbr = aot != AAC_AOT_LC;
  const bool ps = aot == AAC_AOT_PS;
  if ((sbr && sr % 2) || (ps && ch != 2)) {
    return nullptr;
  }
  const int coded_channels = ps ? 1 : ch;
  if (config_for_channels(coded_channels) == 0) {
    return nullptr;
  }
  auto* s = new AacEncoderState();
  s->input_rate = sr;
  s->input_channels = ch;
  s->sample_rate = sbr ? sr / 2 : sr;
  s->channels = coded_channels;
  s->bitrate = br;
  s->aot = aot;
  s->rc_mode = rc;
  s->quality = 70;
  s->frame_size = sbr ? 2 * kFrame : kFrame;
  s->delay = sbr ? AAC_ENCODER_SBR_DELAY : AAC_ENCODER_DELAY;
  s->dsp = dsp;
  sr = s->sample_rate;
  s->rate_index = -1;
  for (int i = 0; i < AAC_NUM_SAMPLE_RATES; i++) {
    if (aac_sample_rates[i] == sr) {
      s->rate_index = i;
      break;
    }
  }
  if (s->rate_index < 0 || setup_layout(s, AAC_MODE_DEFAULT) != AAC_OK) {
    delete s;
    return nullptr;
  }
  if (sbr && create_sbr_encoders(s) != AAC_OK) {
    aac_encoder_state_destroy(s);
    return nullptr;
  }
  aac_mdct_init(&s->mdct_long, 2 * kFrame);
  aac_mdct_init(&s->mdct_short, 2 * kShort);

  s->avg_frame_bits = (int)((long long)br * kFrame / sr);
  s->reservoir_max = std::max(0, kMaxBitsPerChannel * ch - s->avg_frame_bits);
  aac_encoder_state_configure(s);
  aac_encoder_state_reset(s);
  return s;
}

namespace {

/* Bands below a lowpass cutoff in Hz, for long and short windows. */
void active_bands(int rate_index, int sample_rate, int cutoff, int* num_long, int* num_short) {
  const int* lo = aac_sfb_offset_long[rate_index];
  const int* so = aac_sfb_offset_short[rate_index];
  const int lowpass_line_long = 2 * cutoff * kFrame / sample_rate;
  const int lowpass_line_short = 2 * cutoff * kShort / sample_rate;
  *num_long = aac_num_sfb_long[rate_index];
  *num_short = aac_num_sfb_short[rate_index];
  for (int b = 0; b < aac_num_sfb_long[rate_index]; b++) {
    if (lo[b] >= lowpass_line_long) {
      *num_long = std::max(b, 1);
      break;
    }
  }
  for (int b = 0; b < aac_num_sfb_short[rate_index]; b++) {
    if (so[b] >= lowpass_line_short) {
      *num_short = std::max(b, 1);
      break;
    }
  }
}

}  // namespace

void aac_encoder_state_configure(AacEncoderState* s) {
  int core_bitrate = s->bitrate;
  for (int e = 0; e < s->num_elements; e++) {
    if (s->sbr[e]) {
      core_bitrate -= kSbrBitrateEstimate * (s->sbr[e]->ps ? 1 : s->sbr[e]->channels);
    }
  }
  const int channel_bitrate = std::max(core_bitrate, 8000) / s->channels;
  s->bandwidth = bandwidth_for(channel_bitrate, s->channels, s->sample_rate, s->rc_mode, s->quality);
  const int* lo = aac_sfb_offset_long[s->rate_index];
  const int* so = aac_sfb_offset_short[s->rate_index];
  active_bands(s->rate_index, s->sample_rate, s->bandwidth, &s->active_bands_long,
               &s->active_bands_short);
  int lfe_bands = 1;
  for (int b = 1; b < aac_num_sfb_long[s->rate_index] && lo[b] < kLfeLines; b++) {
    lfe_bands = b + 1;
  }
  for (int c = 0; c < s->channels; c++) {
    s->ch[c].active_long = s->ch[c].is_lfe ? lfe_bands : s->active_bands_long;
    s->ch[c].active_short = s->ch[c].is_lfe ? 0 : s->active_bands_short;
  }
  /* The channels of an SBR element are coded up to the element's
   * crossover, whatever the bitrate suggests: the generator copies the
   * bands just below it upwards, so they must carry signal. */
  for (int e = 0; e < s->num_elements; e++) {
    if (!s->sbr[e]) {
      continue;
    }
    const int cutoff = s->sbr[e]->freq.kx * s->input_rate / 128;
    const AacEncElement* el = &s->elem[e];
    for (int k = 0; k < el->num_ch; k++) {
      AacEncChannel* ch = &s->ch[el->ch[k]];
      active_bands(s->rate_index, s->sample_rate, cutoff, &ch->active_long, &ch->active_short);
      s->active_bands_long = std::max(s->active_bands_long, ch->active_long);
      s->active_bands_short = std::max(s->active_bands_short, ch->active_short);
      s->bandwidth = std::max(s->bandwidth, cutoff);
    }
  }
  init_min_snr(s->min_snr_long, channel_bitrate, s->sample_rate, 2 * kFrame, lo,
               s->active_bands_long);
  init_min_snr(s->min_snr_short, channel_bitrate, s->sample_rate, 2 * kShort, so,
               s->active_bands_short);
  aac_enc_tools_init(s);
  if (s->mpeg2) {
    /* MPEG-2 AAC (ISO/IEC 13818-7) has no perceptual noise substitution. */
    s->use_pns = 0;
  }
}

void aac_encoder_state_reset(AacEncoderState* s) {
  for (int c = 0; c < s->channels; c++) {
    AacEncChannel* ch = &s->ch[c];
    memset(ch->pcm, 0, sizeof(ch->pcm));
    aac_ics_layout(&ch->ics, s->rate_index, AAC_WIN_ONLY_LONG, 0);
    ch->ics.window_shape = AAC_WIN_KBD;
    ch->prev_window_shape = AAC_WIN_KBD;
    ch->tns_present = 0;
    memset(ch->tns_n_filt, 0, sizeof(ch->tns_n_filt));
    aac_psy_init(&ch->psy);
  }
  for (int e = 0; e < s->num_elements; e++) {
    s->elem[e].max_sfb = 0;
    memset(s->elem[e].ms_used, 0, sizeof(s->elem[e].ms_used));
  }
  for (int e = 0; e < s->num_elements; e++) {
    if (s->sbr[e]) {
      aac_sbr_enc_reset(s->sbr[e]);
    }
    if (s->lfe_down[e]) {
      aac_sbr_downsampler_init(s->lfe_down[e]);
    }
  }
  memset(s->sbr_in, 0, sizeof(s->sbr_in));
  memset(s->core_in, 0, sizeof(s->core_in));
  s->reservoir = s->reservoir_max / 2;
  s->pending_flush = (s->delay + s->frame_size - 1) / s->frame_size;
  s->started = 0;
  const float mean_pe = (float)s->avg_frame_bits;
  s->pe_min = 0.4f * mean_pe;
  s->pe_max = 0.6f * mean_pe;
}

int aac_encoder_state_max_frame_bytes(const AacEncoderState* s) {
  int bytes = (kMaxBitsPerChannel * s->channels + kAdtsBits + 7) / 8;
  for (int e = 0; e < s->num_elements; e++) {
    if (s->sbr[e]) {
      bytes += AAC_SBR_ENC_MAX_BYTES + 2;
    }
  }
  return bytes;
}

void aac_encoder_state_destroy(AacEncoderState* s) {
  if (!s) {
    return;
  }
  destroy_sbr_encoders(s);
  delete s;
}

int aac_encoder_state_set_channel_mode(AacEncoderState* s, AacChannelMode mode) {
  if (mode == s->channel_mode) {
    return AAC_OK;
  }
  const AacChannelMode previous = s->channel_mode;
  if (setup_layout(s, mode) != AAC_OK) {
    setup_layout(s, previous);
    return AAC_ERR_INVALID_ARG;
  }
  if (s->aot != AAC_AOT_LC) {
    /* The elements changed, and the SBR encoders belong to elements. */
    destroy_sbr_encoders(s);
    if (create_sbr_encoders(s) != AAC_OK) {
      return AAC_ERR_INIT;
    }
  }
  aac_encoder_state_configure(s);
  aac_encoder_state_reset(s);
  return AAC_OK;
}

namespace {

/* HE-AAC: runs the SBR encoders over the full-rate input block and leaves
 * the half-rate core signal of each coded channel in core_in. */
void sbr_process(AacEncoderState* s, const float* pcm) {
  const int in_ch = s->input_channels;
  if (pcm) {
    for (int c = 0; c < in_ch; c++) {
      for (int i = 0; i < s->frame_size; i++) {
        s->sbr_in[c][i] = pcm[i * in_ch + c] * kInputScale;
      }
    }
  }
  for (int e = 0; e < s->num_elements; e++) {
    const AacEncElement* el = &s->elem[e];
    AacSbrEncoder* sbr = s->sbr[e];
    if (!sbr) {
      /* an LFE channel: the lower half of its spectrum, at half rate */
      aac_sbr_downsample(s->lfe_down[e], pcm ? s->sbr_in[el->ch[0]] : nullptr,
                         s->core_in[el->ch[0]]);
      continue;
    }
    const float* in[2];
    float* core[2];
    for (int k = 0; k < sbr->channels; k++) {
      in[k] = s->sbr_in[sbr->ps ? k : el->ch[k]];
    }
    for (int k = 0; k < el->num_ch; k++) {
      core[k] = s->core_in[el->ch[k]];
    }
    aac_sbr_enc_process(sbr, pcm ? in : nullptr, core);
  }
}

}  // namespace

int aac_encode_frame_internal(AacEncoderState* s, const float* pcm) {
  s->started = 1;
  const bool sbr = s->frame_size != kFrame;
  if (sbr) {
    sbr_process(s, pcm);
  }
  /* Shift in the new block; the frame to code spans pcm[0 .. 2048). */
  for (int c = 0; c < s->channels; c++) {
    float* buf = s->ch[c].pcm;
    memmove(buf, buf + kFrame, 2 * kFrame * sizeof(float));
    for (int i = 0; i < kFrame; i++) {
      buf[2 * kFrame + i] = sbr   ? s->core_in[c][i]
                            : pcm ? pcm[i * s->channels + c] * kInputScale
                                  : 0.0f;
    }
  }

  const int channel_bitrate = s->bitrate / s->channels;
  for (int e = 0; e < s->num_elements; e++) {
    AacEncElement* el = &s->elem[e];
    choose_window_sequence(s, el);
    for (int k = 0; k < el->num_ch; k++) {
      AacEncChannel* ch = &s->ch[el->ch[k]];
      window_and_mdct(s, ch);
      aac_enc_tns_decide(s, ch);
    }
    if (el->num_ch == 2) {
      aac_enc_tns_sync(s, &s->ch[el->ch[1]], &s->ch[el->ch[0]]);
    }
    for (int k = 0; k < el->num_ch; k++) {
      AacEncChannel* ch = &s->ch[el->ch[k]];
      aac_enc_tns_apply(s, ch);
      aac_psy_thresholds(&ch->psy, s->sample_rate, channel_bitrate, &ch->ics, ch->coef,
                         ch->energy, ch->thr, ch->spread);
      memset(ch->tool, 0, sizeof(ch->tool));
      aac_enc_pns_detect(s, ch);
    }
    if (el->num_ch == 2) {
      aac_enc_intensity(s, el);
      decide_ms(s, el);
      aac_enc_pns_stereo_finish(s, el);
    }
    for (int k = 0; k < el->num_ch; k++) {
      aac_enc_pns_code(s, &s->ch[el->ch[k]]);
    }
  }
  for (int c = 0; c < s->channels; c++) {
    for (int i = 0; i < kFrame; i++) {
      s->ch[c].x34[i] = powf(fabsf(s->ch[c].coef[i]), 0.75f);
    }
  }

  /* Bit budget.  raw_data_block() may not exceed 6144 bits per channel;
   * the budget counts the ADTS header as well. */
  const int avg = s->avg_frame_bits;
  const int hard_max = kMaxBitsPerChannel * s->channels + kAdtsBits;
  int target = hard_max;
  int max_bits = hard_max;
  float improve = 1.0f;
  switch (s->rc_mode) {
    case AAC_RC_CBR:
    case AAC_RC_ABR: {
      const int overhead = frame_overhead_bits(s);
      const int dyn = bit_demand(s, frame_pe(s), std::max(avg - overhead, 1));
      max_bits = std::min(hard_max, avg + s->reservoir);
      target = std::clamp(overhead + dyn, avg / 4, max_bits);
      break;
    }
    case AAC_RC_CVBR:
      improve = quality_factor(s->quality);
      max_bits = std::min(hard_max, avg + avg / 2);
      target = max_bits;
      break;
    case AAC_RC_TVBR:
    default:
      improve = quality_factor(s->quality);
      break;
  }
  rate_control(s, target, max_bits, improve);

  int fullness = 0x7FF; /* variable bitrate marker */
  if (s->rc_mode == AAC_RC_CBR || s->rc_mode == AAC_RC_ABR) {
    fullness = std::min(0x7FE, s->reservoir / (32 * s->channels));
  }
  const int len = write_frame(s, fullness);
  if (len < 0) {
    return len;
  }
  s->reservoir = std::clamp(s->reservoir + avg - len * 8, 0, s->reservoir_max);
  return len;
}
