#include "encoder.h"

#include <algorithm>
#include <cmath>
#include <cstring>

/* AAC-LC encoder.  The structure follows the Fraunhofer FDK encoder and
 * 3GPP TS 26.403: block switching with one block of lookahead, the masking
 * model of psycho.cpp, per-band M/S stereo, a bitrate-dependent lowpass and
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

/* Codebook with the smallest alphabet per maximum magnitude, by index. */
const int kCodebookMaxVal[12] = {0, 1, 1, 2, 2, 4, 4, 7, 7, 12, 12, AAC_ESC_MAX_MAG};

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
 * zero band may also use ZERO_HCB. */
int section_group(AacEncChannel* c, int g, int w0, int max_sfb) {
  const AacIcsInfo* ics = &c->ics;
  const int run_bits = ics->num_windows == 8 ? 3 : 5;
  const int header = 4 + run_bits;
  int cost[AAC_MAX_SFB_LONG][12];
  int from[AAC_MAX_SFB_LONG][12];
  int* cb_out = c->cb + g * ics->num_swb;

  for (int b = 0; b < max_sfb; b++) {
    const int max_mag = band_max_mag(c, g, w0, b);
    for (int cb = 0; cb <= AAC_NUM_CODEBOOKS; cb++) {
      cost[b][cb] = kInfBits;
      from[b][cb] = -1;
      if (cb == 0 ? max_mag != 0 : kCodebookMaxVal[cb] < max_mag) {
        continue;
      }
      /* A zero band coded with a real book also carries a scalefactor
       * (a zero difference, one bit). */
      const int band = cb == 0 ? 0 : spectral_bits(c, g, w0, b, cb) + (max_mag == 0 ? 1 : 0);
      if (b == 0) {
        cost[b][cb] = header + band;
        continue;
      }
      for (int prev = 0; prev <= AAC_NUM_CODEBOOKS; prev++) {
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
  for (int cb = 1; cb <= AAC_NUM_CODEBOOKS; cb++) {
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
 * that saves bits, and quantizes. */
void quantize_channel(AacEncChannel* c) {
  const AacIcsInfo* ics = &c->ics;
  const int nswb = ics->num_swb;
  int w0 = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < nswb; b++) {
      const int idx = g * nswb + b;
      c->cb[idx] = 0;
      c->sf[idx] = -1;
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
      if (c->cb[idx] == 0) {
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
   * bits, the coarser one adds noise but keeps the stream valid. */
  int prev = -1;
  for (int idx = 0; idx < ics->num_window_groups * nswb; idx++) {
    if (c->cb[idx] == 0) {
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
  int first_coded = -1;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < nswb; b++) {
      const int idx = g * nswb + b;
      if (c->cb[idx] == 0) {
        continue;
      }
      if (quantize_band(c, g, w0, b, c->sf[idx]) == 0) {
        c->cb[idx] = 0;
        c->sf[idx] = -1;
        continue;
      }
      if (first_coded < 0) {
        first_coded = idx;
      }
    }
    w0 += ics->group_len[g];
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

/* Sections every group and assigns the scalefactors of zero bands that
 * ended up with a real book; returns the section, scalefactor and spectral
 * bits of the channel. */
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
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < max_sfb; b++) {
      const int idx = g * nswb + b;
      if (c->cb[idx] == 0) {
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

/* ── TNS (ISO/IEC 14496-3 4.6.9, libfdk-aac aacenc_tns.cpp) ──────── */

constexpr int kTnsMaxOrder = 12;
constexpr float kTnsGainOn = 1.437f; /* prediction gain that switches TNS on */
constexpr int kTnsStartHz = 1400;
constexpr int kTnsCoefBits = 4;

/* Levinson-Durbin on r[0 .. order]: reflection coefficients in the sign
 * convention of the decoder's step-up recursion, and the prediction gain. */
float levinson(const double* r, int order, float* parcor) {
  double a[kTnsMaxOrder + 1] = {0.0};
  double tmp[kTnsMaxOrder + 1];
  double err = r[0];
  if (err <= 0.0) {
    return 1.0f;
  }
  for (int m = 1; m <= order; m++) {
    double acc = r[m];
    for (int i = 1; i < m; i++) {
      acc -= a[i] * r[m - i];
    }
    const double k = acc / err;
    parcor[m - 1] = (float)-k;
    for (int i = 1; i < m; i++) {
      tmp[i] = a[i] - k * a[m - i];
    }
    for (int i = 1; i < m; i++) {
      a[i] = tmp[i];
    }
    a[m] = k;
    err *= 1.0 - k * k;
    if (err <= 1e-9 * r[0]) {
      err = 1e-9 * r[0];
    }
  }
  return (float)(r[0] / err);
}

/* Quantizes a reflection coefficient the way the decoder inverts it. */
int tns_quantize(float parcor) {
  const double iqfac = ((1 << (kTnsCoefBits - 1)) - 0.5) / (M_PI / 2.0);
  const double iqfac_m = ((1 << (kTnsCoefBits - 1)) + 0.5) / (M_PI / 2.0);
  const double v = asin(std::clamp(parcor, -1.0f, 1.0f));
  const int idx = (int)lrint(v * (v >= 0.0 ? iqfac : iqfac_m));
  return std::clamp(idx, -(1 << (kTnsCoefBits - 1)), (1 << (kTnsCoefBits - 1)) - 1);
}

float tns_dequantize(int idx) {
  const double iqfac = ((1 << (kTnsCoefBits - 1)) - 0.5) / (M_PI / 2.0);
  const double iqfac_m = ((1 << (kTnsCoefBits - 1)) + 0.5) / (M_PI / 2.0);
  return (float)sin(idx / (idx >= 0 ? iqfac : iqfac_m));
}

/* Decides on and applies a TNS filter to a long-window spectrum: when the
 * spectrum above kTnsStartHz is predictable (a transient-like temporal
 * envelope) the prediction residual is coded instead, and the decoder's
 * all-pole filter shapes the quantization noise under the envelope. */
void apply_tns(const AacEncoderState* s, AacEncChannel* c) {
  const AacIcsInfo* ics = &c->ics;
  c->tns_present = 0;
  c->tns_order = 0;
  if (ics->num_windows == 8) {
    return;
  }
  const int start = ics->swb_offset[s->tns_start_band];
  const int end = ics->swb_offset[std::min(ics->tns_max_bands, ics->num_swb)];
  if (end - start <= 2 * kTnsMaxOrder) {
    return;
  }
  double r[kTnsMaxOrder + 1];
  for (int lag = 0; lag <= kTnsMaxOrder; lag++) {
    double acc = 0.0;
    for (int i = start + lag; i < end; i++) {
      acc += (double)c->coef[i] * (double)c->coef[i - lag];
    }
    r[lag] = acc;
  }
  if (r[0] <= 0.0) {
    return;
  }
  float parcor[kTnsMaxOrder];
  const float gain = levinson(r, kTnsMaxOrder, parcor);
  int order = 0;
  int sum_sq = 0;
  for (int i = 0; i < kTnsMaxOrder; i++) {
    c->tns_coef[i] = tns_quantize(parcor[i]);
    if (c->tns_coef[i] != 0) {
      order = i + 1;
    }
  }
  for (int i = 0; i < order; i++) {
    sum_sq += c->tns_coef[i] * c->tns_coef[i];
  }
  if (order == 0 || (gain <= kTnsGainOn && sum_sq <= kTnsMaxOrder / 2 + 2)) {
    return;
  }
  /* Direct-form coefficients from the quantized reflection coefficients,
   * exactly as the decoder derives them. */
  float lpc[kTnsMaxOrder + 1] = {1.0f};
  for (int m = 1; m <= order; m++) {
    const float k = tns_dequantize(c->tns_coef[m - 1]);
    float tmp[kTnsMaxOrder + 1];
    for (int i = 1; i < m; i++) {
      tmp[i] = lpc[i] + k * lpc[m - i];
    }
    for (int i = 1; i < m; i++) {
      lpc[i] = tmp[i];
    }
    lpc[m] = k;
  }
  /* All-zero filter over the original spectrum, upward. */
  float orig[AAC_FRAME_SIZE_LONG];
  memcpy(orig + start, c->coef + start, (size_t)(end - start) * sizeof(float));
  for (int n = start; n < end; n++) {
    const int lim = std::min(n - start, order);
    float acc = orig[n];
    for (int i = 1; i <= lim; i++) {
      acc += lpc[i] * orig[n - i];
    }
    c->coef[n] = acc;
  }
  c->tns_present = 1;
  c->tns_order = order;
}

int tns_bits(const AacEncChannel* c) {
  /* n_filt, coef_res, length, order, direction, coef_compress, coefficients */
  return c->tns_present ? 2 + 1 + 6 + 5 + 1 + 1 + c->tns_order * kTnsCoefBits : 0;
}

/* ── stereo ────────────────────────────────────────────────────── */

/* Per-band M/S decision (libfdk-aac ms_stereo.cpp): M/S wins when the
 * noise-to-energy product of mid and side at the smaller threshold beats
 * that of left and right.  M/S bands share the smaller threshold. */
void decide_ms(AacEncoderState* s) {
  AacEncChannel* l = &s->ch[0];
  AacEncChannel* r = &s->ch[1];
  const AacIcsInfo* ics = &l->ics;
  const int nswb = ics->num_swb;
  int lr_bands = 0;
  int w0 = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < nswb; b++) {
      const int idx = g * nswb + b;
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
      s->ms_used[idx] = pn_ms > pn_lr;
      if (!s->ms_used[idx]) {
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

void choose_window_sequence(AacEncoderState* s) {
  int attack_now = 0;
  int attack_next = 0;
  for (int c = 0; c < s->channels; c++) {
    attack_now =
        std::max(attack_now, aac_psy_attack(s->ch[c].pcm, kShortRegionStart, kShortRegionLen));
    attack_next = std::max(
        attack_next, aac_psy_attack(s->ch[c].pcm, kFrame + kShortRegionStart, kShortRegionLen));
  }
  const int prev = s->ch[0].ics.window_sequence;
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
  for (int c = 0; c < s->channels; c++) {
    aac_ics_layout(&s->ch[c].ics, s->rate_index, seq, grouping);
    s->ch[c].ics.window_shape = shape;
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

void write_channel_stream(AacBitWriter* w, const AacEncChannel* c, int max_sfb, int common_window,
                          int tns_start_band) {
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

  /* scale_factor_data */
  int prev = c->global_gain;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < max_sfb; b++) {
      const int idx = g * nswb + b;
      if (c->cb[idx] == 0) {
        continue;
      }
      aac_bitwriter_write_scalefactor(w, c->sf[idx] - prev);
      prev = c->sf[idx];
    }
  }

  aac_bitwriter_write(w, 0, 1); /* pulse_data_present */
  aac_bitwriter_write(w, (uint32_t)c->tns_present, 1);
  if (c->tns_present) {
    aac_bitwriter_write(w, 1, 2);                                 /* n_filt */
    aac_bitwriter_write(w, 1, 1);                                 /* coef_res: 4-bit coefficients */
    aac_bitwriter_write(w, (uint32_t)(nswb - tns_start_band), 6); /* length */
    aac_bitwriter_write(w, (uint32_t)c->tns_order, 5);
    aac_bitwriter_write(w, 0, 1); /* direction: upward */
    aac_bitwriter_write(w, 0, 1); /* coef_compress */
    for (int i = 0; i < c->tns_order; i++) {
      aac_bitwriter_write(w, (uint32_t)(c->tns_coef[i] & ((1 << kTnsCoefBits) - 1)), kTnsCoefBits);
    }
  }
  aac_bitwriter_write(w, 0, 1); /* gain_control_data_present */

  /* spectral_data */
  int w0 = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < max_sfb; b++) {
      const int cb = c->cb[g * nswb + b];
      if (cb == 0) {
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
int ms_present_mode(const AacEncoderState* s) {
  const AacIcsInfo* ics = &s->ch[0].ics;
  int used = 0;
  const int nbands = ics->num_window_groups * s->max_sfb;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < s->max_sfb; b++) {
      used += s->ms_used[g * ics->num_swb + b];
    }
  }
  return used == 0 ? 0 : (used == nbands ? 2 : 1);
}

/* Writes the whole ADTS frame for the current quantization; returns its
 * length in bytes or AAC_ERR_OVERFLOW. */
int write_frame(AacEncoderState* s, int buffer_fullness) {
  AacBitWriter w;
  aac_bitwriter_init(&w, s->output_buf, sizeof(s->output_buf));
  aac_bitwriter_write(&w, 0, kAdtsBits); /* header, filled in below */

  const int max_sfb = s->max_sfb;
  if (s->channels == 1) {
    aac_bitwriter_write(&w, AAC_ELEM_SCE, 3);
    aac_bitwriter_write(&w, 0, 4); /* element_instance_tag */
    write_channel_stream(&w, &s->ch[0], max_sfb, 0, s->tns_start_band);
  } else {
    const AacIcsInfo* ics = &s->ch[0].ics;
    aac_bitwriter_write(&w, AAC_ELEM_CPE, 3);
    aac_bitwriter_write(&w, 0, 4);
    aac_bitwriter_write(&w, 1, 1); /* common_window */
    write_ics_info(&w, ics, max_sfb);
    const int ms_present = ms_present_mode(s);
    aac_bitwriter_write(&w, (uint32_t)ms_present, 2);
    if (ms_present == 1) {
      for (int g = 0; g < ics->num_window_groups; g++) {
        for (int b = 0; b < max_sfb; b++) {
          aac_bitwriter_write(&w, (uint32_t)s->ms_used[g * ics->num_swb + b], 1);
        }
      }
    }
    write_channel_stream(&w, &s->ch[0], max_sfb, 1, s->tns_start_band);
    write_channel_stream(&w, &s->ch[1], max_sfb, 1, s->tns_start_band);
  }
  aac_bitwriter_write(&w, AAC_ELEM_END, 3);
  aac_bitwriter_byte_align(&w);
  if (aac_bitwriter_overflow(&w)) {
    return AAC_ERR_OVERFLOW;
  }

  AacAdtsHeader hdr = {};
  hdr.protection_absent = 1;
  hdr.object_type = AAC_AOT_LC;
  hdr.sample_rate_index = s->rate_index;
  hdr.channel_config = s->channels;
  hdr.frame_length = aac_bitwriter_bytes_written(&w);
  hdr.buffer_fullness = buffer_fullness;
  hdr.num_raw_blocks = 1;
  aac_adts_write(&hdr, s->output_buf);
  return hdr.frame_length;
}

/* ── rate control ──────────────────────────────────────────────── */

/* Side-information bits of the frame apart from sections and scalefactors. */
int frame_overhead_bits(const AacEncoderState* s) {
  const AacIcsInfo* ics = &s->ch[0].ics;
  const int ics_info = ics->num_windows == 8 ? 15 : 11;
  int bits = kAdtsBits + 3 + 7; /* header, END, worst-case byte alignment */
  if (s->channels == 1) {
    bits += 7 + 8 + ics_info + 3 + tns_bits(&s->ch[0]);
  } else {
    bits += 7 + 1 + ics_info + 2 + ics->num_window_groups * s->max_sfb + 2 * (8 + 3) +
            tns_bits(&s->ch[0]) + tns_bits(&s->ch[1]);
  }
  return bits;
}

/* Perceptual entropy of the unreduced thresholds (3GPP TS 26.403
 * 5.6.1.1.2), in bits. */
float frame_pe(const AacEncoderState* s) {
  float pe = 0.0f;
  for (int c = 0; c < s->channels; c++) {
    const AacEncChannel* ch = &s->ch[c];
    const AacIcsInfo* ics = &ch->ics;
    const int active = ics->num_windows == 8 ? s->active_bands_short : s->active_bands_long;
    for (int g = 0; g < ics->num_window_groups; g++) {
      for (int b = 0; b < active; b++) {
        const int idx = g * ics->num_swb + b;
        if (ch->energy[idx] <= ch->thr[idx]) {
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
  const int is_short = s->ch[0].ics.num_windows == 8;
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
    const int active = is_short ? s->active_bands_short : s->active_bands_long;
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
        if (b < active && e > ch->thr[idx] && ch->avoid_hole[idx]) {
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
    const int active = ics->num_windows == 8 ? s->active_bands_short : s->active_bands_long;
    for (int g = 0; g < ics->num_window_groups; g++) {
      for (int b = 0; b < nswb; b++) {
        const int idx = g * nswb + b;
        const float e = ch->energy[idx];
        const float thr = ch->thr[idx];
        float allowed = -1.0f;
        if (b < active && e > thr) {
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
  int max_sfb = 0;
  for (int c = 0; c < s->channels; c++) {
    quantize_channel(&s->ch[c]);
    max_sfb = std::max(max_sfb, channel_max_sfb(&s->ch[c]));
  }
  s->max_sfb = max_sfb;
  int bits = 0;
  for (int c = 0; c < s->channels; c++) {
    bits += finish_channel(&s->ch[c], max_sfb);
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

}  // namespace

AacEncoderState* aac_encoder_state_create(int sr, int ch, int br, AacObjectType aot,
                                          AacRateControl rc, const AacDSP* dsp) {
  aac_tables_init();
  init_sf_tables();
  auto* s = new AacEncoderState();
  s->sample_rate = sr;
  s->channels = ch;
  s->bitrate = br;
  s->aot = aot;
  s->rc_mode = rc;
  s->quality = 70;
  s->frame_size = kFrame;
  s->dsp = dsp;
  s->rate_index = -1;
  for (int i = 0; i < AAC_NUM_SAMPLE_RATES; i++) {
    if (aac_sample_rates[i] == sr) {
      s->rate_index = i;
      break;
    }
  }
  if (s->rate_index < 0) {
    delete s;
    return nullptr;
  }
  aac_mdct_init(&s->mdct_long, 2 * kFrame);
  aac_mdct_init(&s->mdct_short, 2 * kShort);
  for (int c = 0; c < ch; c++) {
    aac_ics_layout(&s->ch[c].ics, s->rate_index, AAC_WIN_ONLY_LONG, 0);
    s->ch[c].ics.window_shape = AAC_WIN_KBD;
    s->ch[c].prev_window_shape = AAC_WIN_KBD;
    aac_psy_init(&s->ch[c].psy);
  }

  s->avg_frame_bits = (int)((long long)br * kFrame / sr);
  s->reservoir_max = std::max(0, kMaxBitsPerChannel * ch - s->avg_frame_bits);
  s->reservoir = s->reservoir_max / 2;
  s->pending_flush = AAC_ENCODER_DELAY / kFrame;
  const float mean_pe = (float)s->avg_frame_bits;
  s->pe_min = 0.4f * mean_pe;
  s->pe_max = 0.6f * mean_pe;

  const int channel_bitrate = br / ch;
  s->bandwidth = bandwidth_for(channel_bitrate, ch, sr, rc, s->quality);
  const int* lo = aac_sfb_offset_long[s->rate_index];
  const int* so = aac_sfb_offset_short[s->rate_index];
  const int lowpass_line_long = 2 * s->bandwidth * kFrame / sr;
  const int lowpass_line_short = 2 * s->bandwidth * kShort / sr;
  s->active_bands_long = aac_num_sfb_long[s->rate_index];
  s->active_bands_short = aac_num_sfb_short[s->rate_index];
  for (int b = 0; b < aac_num_sfb_long[s->rate_index]; b++) {
    if (lo[b] >= lowpass_line_long) {
      s->active_bands_long = std::max(b, 1);
      break;
    }
  }
  for (int b = 0; b < aac_num_sfb_short[s->rate_index]; b++) {
    if (so[b] >= lowpass_line_short) {
      s->active_bands_short = std::max(b, 1);
      break;
    }
  }
  s->tns_start_band = aac_tns_max_bands_long[s->rate_index];
  for (int b = 0; b < aac_num_sfb_long[s->rate_index]; b++) {
    if ((float)lo[b] * (float)sr / (2.0f * kFrame) >= (float)kTnsStartHz) {
      s->tns_start_band = b;
      break;
    }
  }
  init_min_snr(s->min_snr_long, channel_bitrate, sr, 2 * kFrame, lo, s->active_bands_long);
  init_min_snr(s->min_snr_short, channel_bitrate, sr, 2 * kShort, so, s->active_bands_short);
  return s;
}

void aac_encoder_state_destroy(AacEncoderState* s) { delete s; }

int aac_encode_frame_internal(AacEncoderState* s, const float* pcm) {
  /* Shift in the new block; the frame to code spans pcm[0 .. 2048). */
  for (int c = 0; c < s->channels; c++) {
    float* buf = s->ch[c].pcm;
    memmove(buf, buf + kFrame, 2 * kFrame * sizeof(float));
    for (int i = 0; i < kFrame; i++) {
      buf[2 * kFrame + i] = pcm ? pcm[i * s->channels + c] * kInputScale : 0.0f;
    }
  }

  choose_window_sequence(s);
  const int channel_bitrate = s->bitrate / s->channels;
  for (int c = 0; c < s->channels; c++) {
    AacEncChannel* ch = &s->ch[c];
    window_and_mdct(s, ch);
    apply_tns(s, ch);
    aac_psy_thresholds(&ch->psy, s->sample_rate, channel_bitrate, &ch->ics, ch->coef, ch->energy,
                       ch->thr, ch->spread);
  }
  if (s->channels == 2) {
    decide_ms(s);
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
