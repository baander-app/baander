#include "mps212_enc.h"

#include <algorithm>
#include <cstdlib>
#include <cmath>
#include <cstring>
#include <mutex>

#include "mps212.h"
#include "mps_internal.h"

/* MPS 212 encoder (ISO/IEC 23003-3 7.11, ISO/IEC 23003-1 OTT box).  The
 * parameters of a frame describe the slots the decoder applies them to:
 * one set at the frame's last slot, from which the decoder interpolates
 * back to the previous frame's.  The parameter bands below the third
 * QMF band border are narrower than a QMF band, so the three lowest
 * bands are analysed by a windowed transform of their slots across the
 * frame, which is why the analysis reads slots on either side of it. */

namespace {

constexpr int kMaxPb = AAC_MPS212_MAX_PARAM_BANDS;
constexpr int kHyb = AAC_MPS212_HYBRID_BANDS;
constexpr int kLfBands = 3; /* QMF bands the hybrid filter bank splits */
constexpr int kLookaround = AAC_MPS212_ENC_LOOKAROUND;
constexpr int kMaxWindow = AAC_USAC_MAX_QMF_SLOTS + 2 * kLookaround;
constexpr int kMaxSlots = AAC_USAC_MAX_QMF_SLOTS;
constexpr double kEps = 1e-9;
/* The largest energy-preserving gain: an anti-phase pair has almost no
 * sum, and amplifying what is left would only raise its noise. */
constexpr float kMaxDmxGain = 4.0f;
/* Parameter bands and the downmix gain of the 2-1-2 element at every
 * bitrate: 20 bands, and 4.5 dB of headroom on the downmix. */
constexpr int kFreqRes = 2;
constexpr int kFixedGainDmx = 3;

enum { kCld = 0, kIcc = 1 };

/* ── Huffman books from the decoder's node tables ──────────────── */

struct Book {
  uint32_t code[257]; /* symbols 0..255, 256 the escape */
  uint8_t len[257];
};

/* Walks a node table (an entry above zero is the next node, zero or
 * below a leaf holding -(symbol + 1), the escape leaf -1 symbol) into
 * codes. */
void build_book(const int16_t (*nodes)[2], int count, Book* b) {
  memset(b, 0, sizeof(*b));
  struct Item {
    int node;
    uint32_t code;
    int len;
  };
  Item stack[64];
  int depth = 0;
  stack[depth++] = {0, 0, 0};
  while (depth > 0) {
    const Item it = stack[--depth];
    for (int bit = 0; bit < 2; bit++) {
      const int e = nodes[it.node][bit];
      const uint32_t code = (it.code << 1) | (uint32_t)bit;
      if (e > 0) {
        if (e < count && depth < 64) {
          stack[depth++] = {e, code, it.len + 1};
        }
      } else {
        const int sym = -e - 1;
        const int idx = sym < 0 ? 256 : sym;
        b->code[idx] = code;
        b->len[idx] = (uint8_t)(it.len + 1);
      }
    }
  }
}

struct Books {
  Book first[2];     /* the first value of a frequency-coded set */
  Book one[2][2];    /* one-dimensional, per direction (0 frequency, 1 time) */
  Book two[2][4][2]; /* two-dimensional, per LAV class and direction */
  Book lav;
};

Books g_books;

void build_books(void) {
  build_book(aac_mps212_huff_cld_first, 30, &g_books.first[kCld]);
  build_book(aac_mps212_huff_icc_first, 7, &g_books.first[kIcc]);
  build_book(aac_mps212_huff_lav, 3, &g_books.lav);
  for (int d = 0; d < 2; d++) {
    build_book(aac_mps212_huff_cld_1d[d], 30, &g_books.one[kCld][d]);
    build_book(aac_mps212_huff_icc_1d[d], 7, &g_books.one[kIcc][d]);
    build_book(aac_mps212_huff_cld_2d_lav3[d][0], 15, &g_books.two[kCld][0][d]);
    build_book(aac_mps212_huff_cld_2d_lav5[d][0], 35, &g_books.two[kCld][1][d]);
    build_book(aac_mps212_huff_cld_2d_lav7[d][0], 63, &g_books.two[kCld][2][d]);
    build_book(aac_mps212_huff_cld_2d_lav9[d][0], 99, &g_books.two[kCld][3][d]);
    build_book(aac_mps212_huff_icc_2d_lav1[d][0], 3, &g_books.two[kIcc][0][d]);
    build_book(aac_mps212_huff_icc_2d_lav3[d][0], 15, &g_books.two[kIcc][1][d]);
    build_book(aac_mps212_huff_icc_2d_lav5[d][0], 35, &g_books.two[kIcc][2][d]);
    build_book(aac_mps212_huff_icc_2d_lav7[d][0], 63, &g_books.two[kIcc][3][d]);
  }
}

void books_init(void) {
  static std::once_flag once;
  std::call_once(once, build_books);
}

int lav_of(int type, int cls) { return type == kCld ? 2 * cls + 3 : 2 * cls + 1; }

/* Writes the code of `sym` (-1 the escape), or returns AAC_ERR_ENCODE
 * for a symbol the book has no code for, so that the frame fails
 * instead of carrying a payload the decoder cannot parse. */
int put_code(AacBitWriter* w, const Book* b, int sym) {
  if (sym > 255) {
    return AAC_ERR_ENCODE;
  }
  const int idx = sym < 0 ? 256 : sym;
  if (b->len[idx] == 0) {
    return AAC_ERR_ENCODE;
  }
  aac_bitwriter_write(w, b->code[idx], b->len[idx]);
  return AAC_OK;
}

/* ── EcDataPair() (ISO/IEC 23003-1 Table 7.x), one set, fine scale ── */

/* PCM words of `levels` levels, grouped as the decoder reads them. */
void write_pcm(AacBitWriter* w, const int* vals, int num, int levels) {
  const int group = aac_mps_pcm_group(levels);
  for (int i = 0; i < num; i += group) {
    const int n = std::min(group, num - i);
    uint64_t word = 0;
    for (int k = 0; k < n; k++) {
      word = word * (uint64_t)levels + (uint64_t)vals[i + k];
    }
    aac_bitwriter_write(w, (uint32_t)word, aac_mps_pcm_bits(levels, n));
  }
}

/* One-dimensional codes of `num` differences in direction `d`, with the
 * first value of a frequency-coded set in front. */
int write_1d(AacBitWriter* w, int type, int d, const int* first, const int* diff, int num) {
  int ret = AAC_OK;
  if (first) {
    ret = put_code(w, &g_books.first[type], *first);
  }
  for (int i = 0; i < num && !ret; i++) {
    const int v = diff[i];
    ret = put_code(w, &g_books.one[type][d], std::abs(v));
    if (v != 0) {
      aac_bitwriter_write(w, v < 0 ? 1u : 0u, 1);
    }
  }
  return ret;
}

/* Two-dimensional codes of an even number of differences as frequency
 * pairs: the LAV class, the first value, each pair's code with its sign
 * and swap bits, and the pairs without a code escaped at the end. */
int write_2d(AacBitWriter* w, int type, int d, const int* first, const int* diff, int num) {
  int max_abs = 0;
  for (int i = 0; i < num; i++) {
    max_abs = std::max(max_abs, std::abs(diff[i]));
  }
  int cls = 0;
  while (cls < 3 && lav_of(type, cls) < max_abs) {
    cls++;
  }
  const int lav = lav_of(type, cls);
  int ret = put_code(w, &g_books.lav, cls);
  if (first && !ret) {
    ret = put_code(w, &g_books.first[type], *first);
  }
  const Book* book = &g_books.two[type][cls][d];
  int esc[2 * kMaxPb];
  int num_esc = 0;
  for (int i = 0; i < num && !ret; i += 2) {
    const int d0 = diff[i];
    const int d1 = diff[i + 1];
    bool done = false;
    for (int sign = 0; sign < 2 && !done; sign++) {
      for (int swap = 0; swap < 2 && !done; swap++) {
        int e0 = d0;
        int e1 = d1;
        if (swap) {
          std::swap(e0, e1);
        }
        if (sign) {
          e0 = -e0;
          e1 = -e1;
        }
        if ((sign && e0 + e1 == 0) || (swap && e0 == e1)) {
          continue;
        }
        /* the decoder forms (sum, difference) from the leaf's (a, b) */
        for (int branch = 0; branch < 2 && !done; branch++) {
          int sum;
          int dif;
          if (branch == 0) {
            sum = e0;
            dif = e1;
            if (sum > lav || sum < 0) {
              continue;
            }
          } else {
            sum = 2 * lav + 1 - e0;
            dif = -e1;
            if (sum <= lav) {
              continue;
            }
          }
          if ((sum + dif) % 2 != 0) {
            continue;
          }
          const int a = (sum + dif) / 2;
          const int bb = (sum - dif) / 2;
          if (a < 0 || bb < 0 || a > lav || bb > lav || a > 15 || bb > 15) {
            continue;
          }
          const int sym = (a << 4) | bb;
          if (book->len[sym] == 0) {
            continue;
          }
          put_code(w, book, sym);
          if (e0 + e1 != 0) {
            aac_bitwriter_write(w, sign ? 1u : 0u, 1);
          }
          if (e0 - e1 != 0) {
            aac_bitwriter_write(w, swap ? 1u : 0u, 1);
          }
          done = true;
        }
      }
    }
    if (!done) {
      ret = put_code(w, book, -1);
      esc[num_esc++] = d0 + lav;
      esc[num_esc++] = d1 + lav;
    }
  }
  if (num_esc > 0 && !ret) {
    write_pcm(w, esc, num_esc, 2 * lav + 1);
  }
  return ret;
}

/* EcDataPair() of one set of `bands` quantiser indices against the
 * decoder's history, Huffman coded along frequency or, when allowed and
 * cheaper, against the history. */
int write_ec_pair(AacBitWriter* w, int type, const int* v, int bands, int time_ref,
                  const int* history) {
  const int offset = type == kCld ? 15 : 0;
  aac_bitwriter_write(w, 0, 1); /* bsPcmCoding */
  int s1[kMaxPb];
  int ref[kMaxPb];
  for (int i = 0; i < bands; i++) {
    s1[i] = v[i] + offset;
    ref[i] = history[i] + offset;
  }
  int diff1 = 0;
  if (time_ref) {
    int cost_f = 0;
    int cost_t = 0;
    for (int i = 0; i < bands; i++) {
      cost_f += std::abs(s1[i] - (i ? s1[i - 1] : 0));
      cost_t += std::abs(s1[i] - ref[i]);
    }
    diff1 = cost_t < cost_f ? 1 : 0;
    aac_bitwriter_write(w, (uint32_t)diff1, 1); /* bsDiffType */
  }
  int d1[kMaxPb];
  int max_abs = 0;
  for (int i = 0; i < bands; i++) {
    d1[i] = diff1 ? s1[i] - ref[i] : (i ? s1[i] - s1[i - 1] : s1[0]);
    if (diff1 || i > 0) {
      max_abs = std::max(max_abs, std::abs(d1[i]));
    }
  }
  /* the two-dimensional books reach the largest LAV only; beyond it the
   * one-dimensional books code the whole range */
  const int two_d = max_abs <= lav_of(type, 3);
  aac_bitwriter_write(w, two_d ? 1u : 0u, 1); /* bsCodingScheme */
  const int* first = diff1 == 0 ? &d1[0] : nullptr;
  const int* rest = diff1 == 0 ? d1 + 1 : d1;
  const int n = diff1 == 0 ? bands - 1 : bands;
  if (!two_d) {
    return write_1d(w, type, diff1, first, rest, n);
  }
  const int even = n & ~1;
  const int ret = write_2d(w, type, diff1, first, rest, even);
  if (ret || !(n & 1)) {
    return ret;
  }
  return write_1d(w, type, diff1, nullptr, rest + even, 1);
}

/* EcData() of one coded set (bsDataMode 3) on the fine quantiser with
 * stride 1; the history follows the decoder's. */
int write_ecdata(AacBitWriter* w, int type, const int* v, int bands, int indep, int* history) {
  aac_bitwriter_write(w, 3, 2); /* bsDataMode */
  aac_bitwriter_write(w, 0, 1); /* bsDataPair */
  aac_bitwriter_write(w, 0, 1); /* bsQuantCoarse */
  aac_bitwriter_write(w, 0, 2); /* bsFreqResStride */
  const int ret = write_ec_pair(w, type, v, bands, !indep, history);
  memcpy(history, v, (size_t)bands * sizeof(int));
  return ret;
}

/* ── the analysis of the split QMF bands ───────────────────────── */

/* The decoder's hybrid filter bank (ISO/IEC 23003-1 6.6.3) splits QMF
 * band 0 into eight sub-bands folded to six and bands 1 and 2 into two
 * each with a 13-tap prototype: neighbouring sub-bands overlap by
 * several dB at their border, and the folding puts the aliased skirt of
 * band 1 into the hybrid band at the top of band 0.  Level differences
 * measured through that filter understate the separation of sounds near
 * a border by 10 dB and more, and the upmix then spreads each into both
 * channels.  So the parameters of these bands come from a windowed
 * transform of each band's slots across the frame, whose bins are
 * assigned to hybrid bands by their frequency in the input, each alias
 * resolved to the band's own range. */

/* The hybrid band of a frequency in units of the QMF band width. */
int hybrid_band_of(double f) {
  if (f < 0.0) {
    f = -f;
  }
  if (f < 1.0) {
    static const int sub[4] = {2, 3, 4, 5};
    return sub[std::min(3, (int)(4.0 * f))];
  }
  if (f < 3.0) {
    const int lower = (int)f;
    return 6 + 2 * (lower - 1) + (f - lower >= 0.5 ? 1 : 0);
  }
  return std::min(kHyb - 1, 7 + (int)f);
}

/* Adds the energies of the two channels and their cross power per
 * parameter band from the transform of QMF band `b` over the `n` ring
 * slots from `start` of `l` and `r`. */
void accumulate_split_band(const AacMps212Encoder* m, const float (*l)[64][2],
                           const float (*r)[64][2], int start, int mask, int n, int b,
                           double* ea, double* eb, double* pab) {
  float w[kMaxWindow];
  float tw[kMaxWindow][2];
  for (int t = 0; t < n; t++) {
    w[t] = 0.5f - 0.5f * (float)cos(2.0 * M_PI * (t + 0.5) / n);
    tw[t][0] = (float)cos(2.0 * M_PI * t / n);
    tw[t][1] = (float)-sin(2.0 * M_PI * t / n);
  }
  for (int bin = -n / 2; bin < n / 2; bin++) {
    float lr = 0.0f, li = 0.0f, rr = 0.0f, ri = 0.0f;
    for (int t = 0; t < n; t++) {
      const int k = (int)(((long)bin * t) % n + n) % n;
      const int idx = (start + t) & mask;
      const float cr = w[t] * tw[k][0];
      const float ci = w[t] * tw[k][1];
      lr += cr * l[idx][b][0] - ci * l[idx][b][1];
      li += cr * l[idx][b][1] + ci * l[idx][b][0];
      rr += cr * r[idx][b][0] - ci * r[idx][b][1];
      ri += cr * r[idx][b][1] + ci * r[idx][b][0];
    }
    /* the bin's frequency in band widths: the slot rate spans two
     * bands, and the band's content lies in the alias nearest its
     * centre */
    const double fs = 2.0 * bin / n;
    const double f = fs + 2.0 * std::round((b + 0.5 - fs) / 2.0);
    const int pb = m->kernel[hybrid_band_of(f)];
    ea[pb] += (double)lr * lr + (double)li * li;
    eb[pb] += (double)rr * rr + (double)ri * ri;
    pab[pb] += (double)lr * rr + (double)li * ri;
  }
}

/* ── quantisers ────────────────────────────────────────────────── */

int quantise_cld(double db) {
  int best = 0;
  for (int i = 1; i < 31; i++) {
    if (fabs(aac_mps212_cld_db[i] - db) < fabs(aac_mps212_cld_db[best] - db)) {
      best = i;
    }
  }
  return best - 15;
}

int quantise_icc(double rho) {
  int best = 0;
  for (int i = 1; i < 8; i++) {
    if (fabs(aac_mps212_icc_value[i] - rho) < fabs(aac_mps212_icc_value[best] - rho)) {
      best = i;
    }
  }
  return best;
}

}  // namespace

/* ── public ────────────────────────────────────────────────────── */

void aac_mps212_enc_init(AacMps212Encoder* m) {
  books_init();
  memset(m, 0, sizeof(*m));
  m->freq_res = kFreqRes;
  m->num_bands = aac_mps212_freq_res_bands[kFreqRes];
  m->fixed_gain_dmx = kFixedGainDmx;
  m->inv_gain_dmx = 1.0f / aac_mps212_fixed_gain[kFixedGainDmx];
  m->kernel = aac_mps212_kernel(m->num_bands);
  /* the downmix gain groups: the three split QMF bands on their own,
   * the others by their parameter band */
  for (int k = 0; k < 64; k++) {
    m->dmx_group[k] = k < kLfBands ? k : kLfBands + m->kernel[k + 7];
  }
  m->num_dmx_groups = kLfBands + m->num_bands;
  aac_mps212_enc_reset(m);
}

void aac_mps212_enc_reset(AacMps212Encoder* m) {
  for (int g = 0; g < 3 + kMaxPb; g++) {
    m->gain_prev[g] = 1.0f;
  }
  memset(m->cld, 0, sizeof(m->cld));
  memset(m->icc, 0, sizeof(m->icc));
  memset(m->hist_cld, 0, sizeof(m->hist_cld));
  memset(m->hist_icc, 0, sizeof(m->hist_icc));
  m->have_hist = 0;
  m->payload_bits = 0;
  m->error = 0;
}

void aac_mps212_enc_config(const AacMps212Encoder* m, AacUsacMps212Config* c) {
  memset(c, 0, sizeof(*c));
  c->freq_res = m->freq_res;
  c->fixed_gain_dmx = m->fixed_gain_dmx;
  c->temp_shape_config = 0;
  c->decorr_config = 0;
  c->high_rate_mode = 0;
  c->phase_coding = 0;
  c->ott_bands_phase_present = 0;
}

void aac_mps212_enc_downmix(AacMps212Encoder* m, const float (*l)[64][2], const float (*r)[64][2],
                            float (*out)[64][2], int slots) {
  double ea[3 + kMaxPb] = {};
  double eb[3 + kMaxPb] = {};
  double esum[3 + kMaxPb] = {};
  for (int t = 0; t < slots; t++) {
    for (int k = 0; k < 64; k++) {
      const int g = m->dmx_group[k];
      const double ar = l[t][k][0], ai = l[t][k][1], br = r[t][k][0], bi = r[t][k][1];
      ea[g] += ar * ar + ai * ai;
      eb[g] += br * br + bi * bi;
      esum[g] += (ar + br) * (ar + br) + (ai + bi) * (ai + bi);
    }
  }
  float gain[3 + kMaxPb];
  for (int g = 0; g < m->num_dmx_groups; g++) {
    const double v = sqrt((ea[g] + eb[g] + kEps) / (esum[g] + kEps));
    gain[g] = (float)std::min(v, (double)kMaxDmxGain) * m->inv_gain_dmx;
  }
  /* the gain interpolated from the previous block's to this one's, as
   * the decoder interpolates its parameters towards the frame's end */
  for (int t = 0; t < slots; t++) {
    const float wgt = (float)(t + 1) / (float)slots;
    for (int k = 0; k < 64; k++) {
      const int g = m->dmx_group[k];
      const float gk = m->gain_prev[g] + wgt * (gain[g] - m->gain_prev[g]);
      const float re = l[t][k][0] + r[t][k][0];
      const float im = l[t][k][1] + r[t][k][1];
      out[t][k][0] = gk * re;
      out[t][k][1] = gk * im;
    }
  }
  memcpy(m->gain_prev, gain, sizeof(gain));
}

int aac_mps212_enc_analyze(AacMps212Encoder* m, const float (*l)[64][2], const float (*r)[64][2],
                           int base, int mask, int slots, int indep) {
  double ea[kMaxPb] = {};
  double eb[kMaxPb] = {};
  double pab[kMaxPb] = {};
  const int n = std::min(kMaxWindow, slots + 2 * kLookaround);
  for (int b = 0; b < kLfBands; b++) {
    accumulate_split_band(m, l, r, base - kLookaround, mask, n, b, ea, eb, pab);
  }
  for (int i = 0; i < slots; i++) {
    const int t = (base + i) & mask;
    for (int b = kLfBands; b < 64; b++) {
      const int pb = m->kernel[7 + b];
      ea[pb] += (double)l[t][b][0] * l[t][b][0] + (double)l[t][b][1] * l[t][b][1];
      eb[pb] += (double)r[t][b][0] * r[t][b][0] + (double)r[t][b][1] * r[t][b][1];
      pab[pb] += (double)l[t][b][0] * r[t][b][0] + (double)l[t][b][1] * r[t][b][1];
    }
  }
  for (int pb = 0; pb < m->num_bands; pb++) {
    m->cld[pb] = quantise_cld(10.0 * log10((ea[pb] + kEps) / (eb[pb] + kEps)));
    m->icc[pb] = quantise_icc(pab[pb] / sqrt((ea[pb] + kEps) * (eb[pb] + kEps)));
  }
  /* Mps212Data(): one parameter set at the frame's end, the level
   * differences, the coherences, nothing else with the configuration */
  AacBitWriter w;
  aac_bitwriter_init(&w, m->payload, sizeof(m->payload));
  const int time_ok = !indep && m->have_hist;
  if (!indep) {
    aac_bitwriter_write(&w, time_ok ? 0u : 1u, 1); /* bsIndependencyFlag */
  }
  int ret = write_ecdata(&w, kCld, m->cld, m->num_bands, !time_ok, m->hist_cld);
  if (!ret) {
    ret = write_ecdata(&w, kIcc, m->icc, m->num_bands, !time_ok, m->hist_icc);
  }
  if (!ret && aac_bitwriter_overflow(&w)) {
    ret = AAC_ERR_OVERFLOW;
  }
  m->have_hist = 1;
  m->error = ret;
  m->payload_bits = ret ? 0 : aac_bitwriter_bits_written(&w);
  return ret;
}

int aac_mps212_enc_bits(const AacMps212Encoder* m) { return m->payload_bits; }

int aac_mps212_enc_error(const AacMps212Encoder* m) { return m->error; }

void aac_mps212_enc_write(const AacMps212Encoder* m, AacBitWriter* w) {
  aac_bitwriter_write_bits(w, m->payload, m->payload_bits);
}

void aac_mps212_enc_write_silent(AacBitWriter* w) {
  aac_bitwriter_write(w, 0, 2); /* bsDataMode of the level differences: default */
  aac_bitwriter_write(w, 0, 2); /* bsDataMode of the coherences: default */
}
