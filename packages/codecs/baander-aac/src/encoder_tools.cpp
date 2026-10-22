#include "encoder_tools.h"

#include <algorithm>
#include <cmath>
#include <cstring>

namespace {

constexpr int kFrame = AAC_FRAME_SIZE_LONG;
constexpr int kShort = AAC_FRAME_SIZE_SHORT;

/* ── TNS (libfdk-aac aacenc_tns.cpp) ────────────────────────────── */

constexpr float kTnsGainOnHigh = 1.437f; /* prediction gain that switches TNS on */
constexpr float kTnsGainOnLow = 1.5f;
constexpr int kTnsLowOrder = 5;
/* Lag windows of the autocorrelation: 1 - (slope * lag)^2. */
constexpr float kTnsAcfSlopeLong = 0.0469f;
constexpr float kTnsAcfSlopeShort = 0.125f;

/* Levinson-Durbin on r[0 .. order]: reflection coefficients in the sign
 * convention of the decoder's step-up recursion, and the prediction gain. */
float levinson(const double* r, int order, float* parcor) {
  double a[AAC_ENC_TNS_MAX_ORDER + 1] = {0.0};
  double tmp[AAC_ENC_TNS_MAX_ORDER + 1];
  double err = r[0];
  if (err <= 0.0) {
    for (int i = 0; i < order; i++) {
      parcor[i] = 0.0f;
    }
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

/* Quantizes a reflection coefficient the way the decoder inverts it
 * (ISO/IEC 14496-3 4.6.9.3). */
int tns_quantize(float parcor, int bits) {
  const double iqfac = ((1 << (bits - 1)) - 0.5) / (M_PI / 2.0);
  const double iqfac_m = ((1 << (bits - 1)) + 0.5) / (M_PI / 2.0);
  const double v = asin(std::clamp(parcor, -1.0f, 1.0f));
  const int idx = (int)lrint(v * (v >= 0.0 ? iqfac : iqfac_m));
  return std::clamp(idx, -(1 << (bits - 1)), (1 << (bits - 1)) - 1);
}

float tns_dequantize(int idx, int bits) {
  const double iqfac = ((1 << (bits - 1)) - 0.5) / (M_PI / 2.0);
  const double iqfac_m = ((1 << (bits - 1)) + 0.5) / (M_PI / 2.0);
  return (float)sin(idx / (idx >= 0 ? iqfac : iqfac_m));
}

/* Energy-normalized autocorrelation of x[start .. stop) up to max_lag,
 * accumulated into r. */
void accumulate_acf(const float* x, int start, int stop, int max_lag, double* r) {
  double e = 0.0;
  for (int i = start; i < stop; i++) {
    e += (double)x[i] * x[i];
  }
  if (e <= 0.0) {
    return;
  }
  for (int lag = 0; lag <= max_lag; lag++) {
    double acc = 0.0;
    for (int i = start; i < stop - lag; i++) {
      acc += (double)x[i] * x[i + lag];
    }
    r[lag] += acc / e;
  }
}

/* Quantizes parcor[0 .. order) into coef and returns the order after
 * dropping trailing zeros; sum_sq receives the sum of squared indices. */
int quantize_filter(const float* parcor, int order, int bits, int* coef, int* sum_sq) {
  int used = 0;
  *sum_sq = 0;
  for (int i = 0; i < order; i++) {
    coef[i] = tns_quantize(parcor[i], bits);
    if (coef[i] != 0) {
      used = i + 1;
    }
  }
  for (int i = 0; i < used; i++) {
    *sum_sq += coef[i] * coef[i];
  }
  return used;
}

/* Decides the filters of one window: the upper filter over the second to
 * fourth quarters of the analysed range (their autocorrelations
 * normalized and summed), the lower one over the first quarter of a long
 * window.  The two are merged when their coefficients nearly agree. */
void decide_window(const AacEncoderState* s, AacEncChannel* c, int w, const float* x, int lo_line,
                   int hi_line, int stop_line, int max_order, int bits, int lo_band, int hi_band,
                   int top_band, bool allow_low) {
  const float slope = bits == 4 ? kTnsAcfSlopeLong : kTnsAcfSlopeShort;
  c->tns_n_filt[w] = 0;
  const int span = stop_line - lo_line;
  if (span < 4 * (max_order + 1)) {
    return;
  }
  const int q1 = lo_line + span / 4;
  const int q2 = lo_line + span / 2;
  const int q3 = lo_line + span * 3 / 4;

  double r_hi[AAC_ENC_TNS_MAX_ORDER + 1] = {0.0};
  accumulate_acf(x, q1, q2, max_order, r_hi);
  accumulate_acf(x, q2, q3, max_order, r_hi);
  accumulate_acf(x, q3, stop_line, max_order, r_hi);
  for (int lag = 0; lag <= max_order; lag++) {
    const float win = 1.0f - (slope * (float)lag) * (slope * (float)lag);
    r_hi[lag] *= win;
  }
  float parcor[AAC_ENC_TNS_MAX_ORDER];
  const float gain_hi = levinson(r_hi, max_order, parcor);
  int sum_sq = 0;
  int* coef_hi = c->tns_coef[w][0];
  const int order_hi = quantize_filter(parcor, max_order, bits, coef_hi, &sum_sq);
  if (w == 0) {
    c->tns_gain = gain_hi;
  }
  if (!(gain_hi > kTnsGainOnHigh || sum_sq > max_order / 2 + 2) || order_hi == 0) {
    return;
  }
  /* Filter lengths count down from num_swb (ISO/IEC 14496-3 4.6.9.3); the
   * decoder clips the range to tns_max_bands and max_sfb itself. */
  const int nswb = c->ics.num_swb;
  c->tns_n_filt[w] = 1;
  c->tns_order[w][0] = order_hi;
  c->tns_length[w][0] = nswb - hi_band;

  if (!allow_low || hi_band <= lo_band) {
    return;
  }
  /* Lower filter: first quarter, lag window stretched three times. */
  const int order_lo = std::min(kTnsLowOrder, max_order);
  double r_lo[AAC_ENC_TNS_MAX_ORDER + 1] = {0.0};
  accumulate_acf(x, lo_line, q1, order_lo, r_lo);
  for (int lag = 0; lag <= order_lo; lag++) {
    const float win = 1.0f - (slope * 3.0f * (float)lag) * (slope * 3.0f * (float)lag);
    r_lo[lag] *= std::max(win, 0.0f);
  }
  const float gain_lo = levinson(r_lo, order_lo, parcor);
  int* coef_lo = c->tns_coef[w][1];
  int sum_sq_lo = 0;
  const int used_lo = quantize_filter(parcor, order_lo, bits, coef_lo, &sum_sq_lo);
  const bool lo_on = (gain_lo > kTnsGainOnLow && gain_lo < 16.0f * (float)order_lo) ||
                     (sum_sq_lo > 9 && sum_sq_lo < 22 * order_lo);
  if (!lo_on || used_lo == 0) {
    return;
  }
  int diff = 0;
  for (int i = 0; i < order_lo; i++) {
    diff += std::abs(coef_hi[i] - coef_lo[i]);
  }
  if (diff < 2) {
    /* One filter over the whole range with the upper coefficients,
     * trimmed below the first large coefficient beyond the lower order. */
    c->tns_merged = 1;
    c->tns_length[w][0] = nswb - lo_band;
    int i = order_lo;
    while (i < order_hi && std::abs(coef_hi[i]) <= 1) {
      i++;
    }
    for (i--; i >= 0; i--) {
      if (coef_hi[i] != 0) {
        break;
      }
    }
    if (i + 1 < order_hi && i >= 0) {
      c->tns_order[w][0] = i + 1;
    }
    return;
  }
  c->tns_n_filt[w] = 2;
  c->tns_order[w][1] = used_lo;
  c->tns_length[w][1] = hi_band - lo_band;
  (void)s;
  (void)top_band;
}

/* Runs the all-zero filter with the quantized coefficients over x[start ..
 * stop), upward, from a zero state. */
void filter_range(float* x, int start, int stop, const int* coef, int order, int bits) {
  float lpc[AAC_ENC_TNS_MAX_ORDER + 1] = {1.0f};
  for (int m = 1; m <= order; m++) {
    const float k = tns_dequantize(coef[m - 1], bits);
    float tmp[AAC_ENC_TNS_MAX_ORDER + 1];
    for (int i = 1; i < m; i++) {
      tmp[i] = lpc[i] + k * lpc[m - i];
    }
    for (int i = 1; i < m; i++) {
      lpc[i] = tmp[i];
    }
    lpc[m] = k;
  }
  float orig[kFrame];
  memcpy(orig + start, x + start, (size_t)(stop - start) * sizeof(float));
  for (int n = start; n < stop; n++) {
    const int lim = std::min(n - start, order);
    float acc = orig[n];
    for (int i = 1; i <= lim; i++) {
      acc += lpc[i] * orig[n - i];
    }
    x[n] = acc;
  }
}

/* ── PNS (libfdk-aac noisedet.cpp, tonality.cpp, aacenc_pns.cpp) ──── */

constexpr int kPnsLav = 60;            /* largest DPCM step of noise energies */
constexpr float kPnsMaskMargin = 1.5f; /* bands this close to masking stay off */
constexpr int kPnsNrgMin = -100;
constexpr int kPnsNrgMax = 155;

/* Unpredictability per line of a long spectrum (Eberlein's peak measure):
 * a line that stands above the mean of its second neighbours is tonal. */
void chaos_measure(const float* x, int n, float* chaos) {
  for (int j = 2; j < n - 2; j++) {
    const float center = fabsf(x[j]);
    const float sides = 0.5f * (fabsf(x[j - 2]) + fabsf(x[j + 2]));
    chaos[j] = sides < center ? (sides / center) * (sides / center) : 1.0f;
  }
  chaos[0] = chaos[1] = chaos[2];
  for (int j = std::max(n - 3, 0); j < n; j++) {
    chaos[j] = 0.5f;
  }
  float left = chaos[0];
  for (int j = 1; j < n; j++) {
    left = 0.75f * chaos[j] + 0.25f * left;
    chaos[j] = left;
  }
}

/* Nearest band border to a frequency (libfdk-aac FreqToBandWidthRounding). */
int band_at_frequency(int freq, int sample_rate, int num_bands, const int* off) {
  const int line = (freq * off[num_bands] * 4 / sample_rate + 1) / 2;
  if (line >= off[num_bands]) {
    return num_bands;
  }
  int band = 0;
  while (band < num_bands && off[band + 1] <= line) {
    band++;
  }
  if (line - off[band] > off[band + 1] - line) {
    band++;
  }
  return band;
}

/* ── intensity stereo (libfdk-aac intensity.cpp) ─────────────────── */

constexpr float kIsCorrThresh = 0.95f;
constexpr float kIsTotalErrorThresh = 0.04f;
constexpr float kIsLocalErrorThresh = 0.01f;
constexpr float kIsDirectionDeviation = 2.0f; /* in log2 of the level ratio */
constexpr float kIsRegionMinLoudness = 0.1f;
constexpr int kIsMinBands = 6;
constexpr float kIsLeftRightRatio = 0.7f;
constexpr float kIsMaxScale = 30.0f; /* log2 of the level ratio */
constexpr float kIsBlocked = 1.0f;   /* error that keeps a band out of a region */

}  // namespace

/* ── configuration ─────────────────────────────────────────────── */

void aac_enc_tools_init(AacEncoderState* s) {
  const int sr = s->sample_rate;
  const int channel_bitrate = s->bitrate / s->channels;
  const int* lo = aac_sfb_offset_long[s->rate_index];
  const int* so = aac_sfb_offset_short[s->rate_index];

  s->tns_order_long = channel_bitrate < 16000 ? 10 : 12;
  s->tns_order_short = channel_bitrate < 16000 ? 3 : 5;
  s->tns_lo_band_long = sr < 9391 ? 2 : (sr < 18783 ? 4 : 8);
  s->tns_top_long = std::min(aac_tns_max_bands_long[s->rate_index], s->active_bands_long);
  s->tns_top_short = std::min(aac_tns_max_bands_short[s->rate_index], s->active_bands_short);
  s->tns_lo_band_long = std::min(s->tns_lo_band_long, s->tns_top_long);
  {
    const int lo_line = lo[s->tns_lo_band_long];
    const int stop = lo[s->tns_top_long];
    int b = s->tns_top_long;
    while (b > s->tns_lo_band_long && lo[b] > lo_line + (stop - lo_line) / 4) {
      b--;
    }
    s->tns_hi_band_long = b;
  }
  {
    const int stop = so[s->tns_top_short];
    int b = s->tns_top_short;
    while (b > 0 && so[b] > stop / 4) {
      b--;
    }
    s->tns_hi_band_short = b;
  }

  /* PNS: the AAC-LC tuning rows of libfdk-aac, by bitrate per channel,
   * for the constant-rate modes only. */
  s->use_pns = 0;
  if (s->rc_mode == AAC_RC_CBR || s->rc_mode == AAC_RC_ABR) {
    float ref_power = 0.0f;
    if (channel_bitrate >= 28000 && channel_bitrate < 32000) {
      ref_power = 0.05f;
      s->pns_tns_gain_thr = 1.41f;
    } else if (channel_bitrate >= 32000 && channel_bitrate < 48000) {
      ref_power = 0.05f;
      s->pns_tns_gain_thr = 1.10f;
    } else if (channel_bitrate == 48000) {
      ref_power = 0.20f;
      s->pns_tns_gain_thr = 1.41f;
    }
    if (ref_power > 0.0f) {
      s->use_pns = 1;
      s->pns_ref_tonality = 0.10f;
      s->pns_min_width = 16;
      const int nswb = aac_num_sfb_long[s->rate_index];
      s->pns_start_band = band_at_frequency(4100, sr, nswb, lo);
      /* A constant dB/Hz slope within a band widens the allowed ratio
       * between its quarters with the band width. */
      for (int b = 0; b < nswb; b++) {
        s->pns_pow_dist[b] = powf(ref_power, (float)(lo[b + 1] - lo[b]) / 32.0f);
      }
      s->pns_pow_dist[nswb] = s->pns_pow_dist[nswb - 1];
    }
  }

  s->allow_is = s->channels == 2 && s->bandwidth > 0 && s->bitrate / s->bandwidth < 5;
}

/* ── TNS ────────────────────────────────────────────────────────── */

int aac_enc_tns_top(const AacEncoderState* s, const AacEncChannel* c) {
  return c->ics.num_windows == 8 ? s->tns_top_short : s->tns_top_long;
}

void aac_enc_tns_decide(const AacEncoderState* s, AacEncChannel* c) {
  const AacIcsInfo* ics = &c->ics;
  c->tns_present = 0;
  c->tns_merged = 0;
  c->tns_gain = 1.0f;
  memset(c->tns_n_filt, 0, sizeof(c->tns_n_filt));
  if (ics->num_windows == 8) {
    const int* so = ics->swb_offset;
    c->tns_coef_res = 0;
    for (int w = 0; w < 8; w++) {
      decide_window(s, c, w, c->coef + w * kShort, 0, so[s->tns_hi_band_short],
                    so[s->tns_top_short], s->tns_order_short, 3, 0, s->tns_hi_band_short,
                    s->tns_top_short, false);
      c->tns_present |= c->tns_n_filt[w] != 0;
    }
    return;
  }
  const int* lo = ics->swb_offset;
  c->tns_coef_res = 1;
  decide_window(s, c, 0, c->coef, lo[s->tns_lo_band_long], lo[s->tns_hi_band_long],
                lo[s->tns_top_long], s->tns_order_long, 4, s->tns_lo_band_long, s->tns_hi_band_long,
                s->tns_top_long, true);
  c->tns_present = c->tns_n_filt[0] != 0;
}

void aac_enc_tns_sync(const AacEncoderState* s, AacEncChannel* dst, const AacEncChannel* src) {
  if (dst->ics.num_windows != src->ics.num_windows) {
    return;
  }
  const int windows = dst->ics.num_windows == 8 ? 8 : 1;
  const int max_order = windows == 8 ? s->tns_order_short : s->tns_order_long;
  for (int w = 0; w < windows; w++) {
    if (dst->tns_n_filt[w] == 0 && src->tns_n_filt[w] == 0) {
      continue;
    }
    int sum = 0;
    bool similar = true;
    for (int i = 0; i < max_order && similar; i++) {
      const int a = i < dst->tns_order[w][0] && dst->tns_n_filt[w] ? dst->tns_coef[w][0][i] : 0;
      const int b = i < src->tns_order[w][0] && src->tns_n_filt[w] ? src->tns_coef[w][0][i] : 0;
      const int diff = std::abs(a - b);
      sum += diff;
      similar = diff <= 1 && sum <= 2;
    }
    if (!similar) {
      continue;
    }
    dst->tns_n_filt[w] = src->tns_n_filt[w];
    for (int f = 0; f < src->tns_n_filt[w]; f++) {
      dst->tns_order[w][f] = src->tns_order[w][f];
      dst->tns_length[w][f] = src->tns_length[w][f];
      memcpy(dst->tns_coef[w][f], src->tns_coef[w][f], sizeof(dst->tns_coef[w][f]));
    }
    dst->tns_merged = src->tns_merged;
  }
  dst->tns_present = 0;
  for (int w = 0; w < windows; w++) {
    dst->tns_present |= dst->tns_n_filt[w] != 0;
  }
}

void aac_enc_tns_apply(const AacEncoderState* s, AacEncChannel* c) {
  if (!c->tns_present) {
    return;
  }
  const AacIcsInfo* ics = &c->ics;
  const int* off = ics->swb_offset;
  const int bits = c->tns_coef_res + 3;
  const int windows = ics->num_windows == 8 ? 8 : 1;
  const int top = aac_enc_tns_top(s, c);
  for (int w = 0; w < windows; w++) {
    float* x = c->coef + w * (windows == 8 ? kShort : 0);
    int band_top = ics->num_swb;
    for (int f = 0; f < c->tns_n_filt[w]; f++) {
      const int band_bottom = std::max(band_top - c->tns_length[w][f], 0);
      const int start = off[std::min(band_bottom, top)];
      const int end = off[std::min(band_top, top)];
      if (end > start) {
        filter_range(x, start, end, c->tns_coef[w][f], c->tns_order[w][f], bits);
      }
      band_top = band_bottom;
    }
  }
}

int aac_enc_tns_bits(const AacEncChannel* c) {
  if (!c->tns_present) {
    return 0;
  }
  const int is8 = c->ics.num_windows == 8;
  const int windows = is8 ? 8 : 1;
  const int coef_bits = c->tns_coef_res + 3;
  int bits = 0;
  for (int w = 0; w < windows; w++) {
    bits += is8 ? 1 : 2;
    if (!c->tns_n_filt[w]) {
      continue;
    }
    bits += 1;
    for (int f = 0; f < c->tns_n_filt[w]; f++) {
      bits += (is8 ? 4 : 6) + (is8 ? 3 : 5);
      if (c->tns_order[w][f]) {
        bits += 2 + c->tns_order[w][f] * coef_bits;
      }
    }
  }
  return bits;
}

void aac_enc_tns_write(AacBitWriter* w, const AacEncChannel* c) {
  const int is8 = c->ics.num_windows == 8;
  const int windows = is8 ? 8 : 1;
  const int coef_bits = c->tns_coef_res + 3;
  for (int win = 0; win < windows; win++) {
    aac_bitwriter_write(w, (uint32_t)c->tns_n_filt[win], is8 ? 1 : 2);
    if (!c->tns_n_filt[win]) {
      continue;
    }
    aac_bitwriter_write(w, (uint32_t)c->tns_coef_res, 1);
    for (int f = 0; f < c->tns_n_filt[win]; f++) {
      aac_bitwriter_write(w, (uint32_t)c->tns_length[win][f], is8 ? 4 : 6);
      aac_bitwriter_write(w, (uint32_t)c->tns_order[win][f], is8 ? 3 : 5);
      if (!c->tns_order[win][f]) {
        continue;
      }
      aac_bitwriter_write(w, 0, 1); /* direction: upward */
      aac_bitwriter_write(w, 0, 1); /* coef_compress */
      for (int i = 0; i < c->tns_order[win][f]; i++) {
        aac_bitwriter_write(w, (uint32_t)(c->tns_coef[win][f][i] & ((1 << coef_bits) - 1)),
                            coef_bits);
      }
    }
  }
}

/* ── PNS ────────────────────────────────────────────────────────── */

void aac_enc_pns_detect(const AacEncoderState* s, AacEncChannel* c) {
  const AacIcsInfo* ics = &c->ics;
  const int nswb = ics->num_swb;
  memset(c->pns_flag, 0, sizeof(c->pns_flag));
  for (int i = 0; i < ics->num_window_groups * nswb; i++) {
    c->pns_energy[i] = c->energy[i];
  }
  if (!s->use_pns || ics->num_windows == 8) {
    return;
  }
  /* Heavy temporal structure that TNS did not take over is not noise. */
  const bool tns_on = c->tns_present && c->tns_n_filt[0] > 0;
  if (c->tns_gain >= s->pns_tns_gain_thr && !(c->tns_gain >= 1.4f && tns_on)) {
    return;
  }
  const int* off = ics->swb_offset;
  const int active = s->active_bands_long;
  float chaos[kFrame];
  chaos_measure(c->coef, off[nswb], chaos);
  const float noise_floor = 0.5f * powf(10.0f, -s->pns_ref_tonality);

  for (int b = s->pns_start_band; b < active; b++) {
    const int width = off[b + 1] - off[b];
    if (width < s->pns_min_width) {
      continue;
    }
    /* Power distribution: the quarters of the band must not differ by
     * more than the slope allowance. */
    const int k = width / 4;
    float q[4] = {0.0f, 0.0f, 0.0f, 0.0f};
    for (int i = 0; i < k; i++) {
      for (int j = 0; j < 4; j++) {
        const float v = c->coef[off[b] + j * k + i];
        q[j] += v * v;
      }
    }
    const float q_max = std::max(std::max(q[0], q[1]), std::max(q[2], q[3]));
    const float q_min = std::min(std::min(q[0], q[1]), std::min(q[2], q[3]));
    if (!(q_max * s->pns_pow_dist[b] < q_min)) {
      continue;
    }
    /* Tonality: energy-weighted unpredictability of the lines. */
    float weighted = 0.0f;
    for (int i = off[b]; i < off[b + 1]; i++) {
      weighted += c->coef[i] * c->coef[i] * chaos[i];
    }
    const float e = c->energy[b];
    if (e <= 0.0f || weighted <= noise_floor * e) {
      continue;
    }
    if (c->thr[b] * kPnsMaskMargin < e) {
      c->pns_flag[b] = 1;
    }
  }
  /* No single substituted band between coded ones, and none right at
   * the top without its neighbour. */
  if (active >= 2 && !c->pns_flag[active - 2]) {
    c->pns_flag[active - 1] = 0;
  }
  for (int b = 1; b < active - 1; b++) {
    if (!c->pns_flag[b - 1] && !c->pns_flag[b + 1]) {
      c->pns_flag[b] = 0;
    }
  }
  if (active >= 1 && (active < 2 || !c->pns_flag[1])) {
    c->pns_flag[0] = 0;
  }
}

void aac_enc_pns_stereo_finish(AacEncoderState* s) {
  AacEncChannel* l = &s->ch[0];
  AacEncChannel* r = &s->ch[1];
  const AacIcsInfo* ics = &l->ics;
  if (!s->use_pns || ics->num_windows == 8) {
    return;
  }
  for (int b = 0; b < ics->num_swb; b++) {
    const bool both = l->pns_flag[b] && r->pns_flag[b];
    if (s->ms_used[b] && !both) {
      l->pns_flag[b] = r->pns_flag[b] = 0;
    }
    /* A set M/S flag on a noise band asks for correlated noise
     * (4.6.13.3).  FFmpeg's decoder draws independent noise regardless
     * and its random sequence then runs ahead of a conforming decoder's,
     * so correlation is never signalled: the two channels get
     * independent noise everywhere. */
    if (both) {
      s->ms_used[b] = 0;
    }
  }
}

void aac_enc_pns_code(const AacEncoderState* s, AacEncChannel* c) {
  const AacIcsInfo* ics = &c->ics;
  const int nbands = ics->num_window_groups * ics->num_swb;
  for (int i = 0; i < nbands; i++) {
    if (c->tool[i] == AAC_CB_NOISE) {
      c->tool[i] = 0;
    }
  }
  if (!s->use_pns || ics->num_windows == 8) {
    return;
  }
  int prev = 0;
  bool first = true;
  for (int b = 0; b < ics->num_swb; b++) {
    if (!c->pns_flag[b] || c->tool[b] != 0) {
      continue;
    }
    /* The decoder fills the band with noise of energy 2^(nrg / 2). */
    const float e = std::max(c->pns_energy[b], 1e-20f);
    int nrg = std::clamp((int)lrintf(2.0f * log2f(e)), kPnsNrgMin, kPnsNrgMax);
    if (!first) {
      nrg = std::clamp(nrg, prev - kPnsLav, prev + kPnsLav);
    }
    first = false;
    prev = nrg;
    c->tool[b] = AAC_CB_NOISE;
    c->tool_sf[b] = nrg;
  }
}

/* ── intensity stereo ───────────────────────────────────────────── */

void aac_enc_intensity(AacEncoderState* s) {
  AacEncChannel* l = &s->ch[0];
  AacEncChannel* r = &s->ch[1];
  const AacIcsInfo* ics = &l->ics;
  const int nswb = ics->num_swb;
  const int* off = ics->swb_offset;
  const int active = ics->num_windows == 8 ? s->active_bands_short : s->active_bands_long;
  const int nbands = ics->num_window_groups * nswb;
  for (int i = 0; i < nbands; i++) {
    if (r->tool[i] == AAC_CB_INTENSITY || r->tool[i] == AAC_CB_INTENSITY2) {
      r->tool[i] = 0;
    }
  }
  if (!s->allow_is) {
    return;
  }

  float err[AAC_MAX_BANDS];
  float loud[AAC_MAX_BANDS];
  float scale[AAC_MAX_BANDS];
  int mask[AAC_MAX_BANDS];
  memset(mask, 0, sizeof(mask));
  memset(err, 0, sizeof(err));

  int w0 = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    float total = 0.0f;
    for (int b = 0; b < active; b++) {
      const int idx = g * nswb + b;
      const float el = l->energy[idx];
      const float er = r->energy[idx];
      scale[idx] =
          std::clamp(log2f(std::max(el, 1e-20f) / std::max(er, 1e-20f)), -kIsMaxScale, kIsMaxScale);
      loud[idx] = sqrtf(sqrtf(0.5f * (el + er)));
      total += loud[idx];
      /* Panned near the middle: M/S does better. */
      if (el >= kIsLeftRightRatio * er && kIsLeftRightRatio * el <= er) {
        err[idx] = kIsBlocked;
      }
    }
    for (int b = 0; b < active; b++) {
      const int idx = g * nswb + b;
      loud[idx] = total > 0.0f ? loud[idx] / total : 0.0f;
      if (err[idx] == kIsBlocked) {
        continue;
      }
      /* Correlation of the two channels' lines, means removed. */
      const int n = ics->group_len[g] * (off[b + 1] - off[b]);
      double ml = 0.0;
      double mr = 0.0;
      for (int w = w0; w < w0 + ics->group_len[g]; w++) {
        for (int i = w * 128 + off[b]; i < w * 128 + off[b + 1]; i++) {
          ml += l->coef[i];
          mr += r->coef[i];
        }
      }
      ml /= n;
      mr /= n;
      double prod = 0.0;
      double sq_l = 0.0;
      double sq_r = 0.0;
      for (int w = w0; w < w0 + ics->group_len[g]; w++) {
        for (int i = w * 128 + off[b]; i < w * 128 + off[b + 1]; i++) {
          const double a = l->coef[i] - ml;
          const double c = r->coef[i] - mr;
          prod += a * c;
          sq_l += a * a;
          sq_r += c * c;
        }
      }
      float corr = 0.0f;
      if (sq_l > 0.0 && sq_r > 0.0) {
        corr = (float)(prod / sqrt(sq_l * sq_r));
      }
      err[idx] = (1.0f - corr) * loud[idx];
      if (fabsf(corr) >= kIsCorrThresh) {
        mask[idx] = 1;
      }
    }
    w0 += ics->group_len[g];
  }

  /* Grow regions of consecutive bands: a band that is not correlated
   * enough on its own still joins when the error stays small; the
   * direction must not jump; small or quiet regions are dropped. */
  float scale_last = 0.0f;
  bool have_last = false;
  for (int g = 0; g < ics->num_window_groups; g++) {
    const int base = g * nswb;
    int start = 0;
    int count = 0;
    bool in_block = false;
    float total_err = 0.0f;
    float region_loud = 0.0f;
    for (int b = 0; b < active; b++) {
      const int idx = base + b;
      if (mask[idx]) {
        if (count == 0) {
          start = idx;
        }
        if (!have_last) {
          scale_last = scale[idx];
          have_last = true;
        }
        in_block = true;
        count++;
        total_err += err[idx];
        region_loud += loud[idx];
      } else if (in_block) {
        total_err += err[idx];
        region_loud += loud[idx];
        if (err[idx] < kIsLocalErrorThresh && total_err < kIsTotalErrorThresh) {
          count++;
          mask[idx] = 1;
        } else {
          in_block = false;
        }
      }
      if (in_block) {
        if (fabsf(scale_last - scale[idx]) < kIsDirectionDeviation) {
          scale_last = scale[idx];
        } else {
          mask[idx] = 0;
          in_block = false;
          count--;
        }
      }
      if (count > 0 && (!in_block || b == active - 1)) {
        if (count < kIsMinBands || region_loud < kIsRegionMinLoudness) {
          for (int j = start; j <= idx; j++) {
            mask[j] = 0;
          }
          have_last = false;
          for (int j = 0; j < start; j++) {
            if (mask[j]) {
              scale_last = scale[j];
              have_last = true;
            }
          }
        }
        count = 0;
        total_err = 0.0f;
        region_loud = 0.0f;
      }
    }
  }

  w0 = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < active; b++) {
      const int idx = g * nswb + b;
      if (!mask[idx]) {
        continue;
      }
      if (l->energy[idx] < l->thr[idx] && r->energy[idx] / 1.5f > r->thr[idx]) {
        continue;
      }
      l->pns_flag[idx] = r->pns_flag[idx] = 0;
      double lr = 0.0;
      for (int w = w0; w < w0 + ics->group_len[g]; w++) {
        for (int i = w * 128 + off[b]; i < w * 128 + off[b + 1]; i++) {
          lr += (double)l->coef[i] * r->coef[i];
        }
      }
      /* The left channel carries the sum (or difference, out of phase)
       * at the left channel's level. */
      const float sign = lr < 0.0 ? -1.0f : 1.0f;
      double e_mix = 0.0;
      for (int w = w0; w < w0 + ics->group_len[g]; w++) {
        for (int i = w * 128 + off[b]; i < w * 128 + off[b + 1]; i++) {
          const double m = 0.5 * (l->coef[i] + sign * r->coef[i]);
          e_mix += m * m;
        }
      }
      const float gain = e_mix > 0.0 ? (float)sqrt(l->energy[idx] / e_mix) : 0.0f;
      for (int w = w0; w < w0 + ics->group_len[g]; w++) {
        for (int i = w * 128 + off[b]; i < w * 128 + off[b + 1]; i++) {
          l->coef[i] = gain * 0.5f * (l->coef[i] + sign * r->coef[i]);
          r->coef[i] = 0.0f;
        }
      }
      r->tool[idx] = sign < 0.0f ? AAC_CB_INTENSITY2 : AAC_CB_INTENSITY;
      r->tool_sf[idx] = (int)lrintf(2.0f * scale[idx]);
      r->energy[idx] = 0.0f;
      r->spread[idx] = 0.0f;
      s->ms_used[idx] = 0;
    }
    w0 += ics->group_len[g];
  }
}
