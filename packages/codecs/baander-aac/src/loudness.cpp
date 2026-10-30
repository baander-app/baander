/* Loudness measurement (ITU-R BS.1770-4, EBU Tech 3341 and 3342) and the
 * loudnessInfoSet() writer of ISO/IEC 23003-4.  See loudness.h for the
 * overview; the clauses are cited at the code they govern. */
#include "loudness.h"

#include <algorithm>
#include <cmath>
#include <cstdlib>
#include <cstring>
#include <new>
#include <vector>

#include "unidrc.h"

namespace {

constexpr int kMaxChannels = 6;
/* 400 ms blocks every 100 ms (BS.1770-4 clause 3, 75 % overlap): a block
 * is four 100 ms sub-blocks, the 3 s short-term window of EBU Tech 3341
 * thirty. */
constexpr int kSubsPerBlock = 4;
constexpr int kSubsPerShortTerm = 30;
/* The absolute gate, as the power a block's weighted mean square must
 * exceed: -70 LKFS with the -0.691 dB offset of the loudness formula. */
constexpr double kLoudnessOffsetDb = -0.691;
constexpr double kAbsoluteGateLkfs = -70.0;
constexpr double kRelativeGateIntegratedLu = -10.0;
constexpr double kRelativeGateRangeLu = -20.0;
/* The true-peak interpolation filter: taps per polyphase branch, Kaiser
 * window shape and the cut-off relative to the input Nyquist frequency. */
constexpr int kTruePeakTaps = 25;
constexpr double kTruePeakKaiserBeta = 7.0;
constexpr double kTruePeakCutoff = 0.96;

using Biquad = struct Biquad_ {
  double b0, b1, b2, a1, a2;
};

/* The channel weights G of BS.1770-4 Table 3 for the canonical order
 * L, R, C, LFE, Ls, Rs; a shorter layout is that order's prefix. */
const double kChannelWeight[kMaxChannels] = {1.0, 1.0, 1.0, 0.0, 1.41, 1.41};

double db_to_power(double db) { return pow(10.0, db / 10.0); }
/* The level of a power or of a linear peak, floored at the silence value
 * (the library builds with finite-math optimisations, so no infinity is
 * ever formed). */
float power_to_lkfs(double p) {
  const double floor = (double)AAC_LOUDNESS_SILENCE;
  return (float)(p > 0.0 ? std::max(floor, kLoudnessOffsetDb + 10.0 * log10(p)) : floor);
}
float linear_to_db(double v) {
  const double floor = (double)AAC_LOUDNESS_SILENCE;
  return (float)(v > 0.0 ? std::max(floor, 20.0 * log10(v)) : floor);
}

/* The K-weighting of BS.1770-4 clause 2 as two biquads.  The standard
 * tabulates the coefficients at 48 kHz; here they are derived from the
 * analogue prototypes behind that table, so that every sample rate gets
 * the same response: a second-order high shelf of +4 dB above
 * 1681.97 Hz (Q 0.7072) and a second-order high-pass at 38.135 Hz
 * (Q 0.5003), both through the bilinear transform with frequency
 * pre-warping.  At 48 kHz the derived values match the table. */
Biquad k_shelf(double rate) {
  const double f0 = 1681.974450955533;
  const double gain_db = 3.999843853973347;
  const double q = 0.7071752369554196;
  const double k = tan(M_PI * f0 / rate);
  const double vh = pow(10.0, gain_db / 20.0);
  const double vb = pow(vh, 0.4996667741545416);
  const double a0 = 1.0 + k / q + k * k;
  Biquad b;
  b.b0 = (vh + vb * k / q + k * k) / a0;
  b.b1 = 2.0 * (k * k - vh) / a0;
  b.b2 = (vh - vb * k / q + k * k) / a0;
  b.a1 = 2.0 * (k * k - 1.0) / a0;
  b.a2 = (1.0 - k / q + k * k) / a0;
  return b;
}

Biquad k_highpass(double rate) {
  const double f0 = 38.13547087602444;
  const double q = 0.5003270373238773;
  const double k = tan(M_PI * f0 / rate);
  const double a0 = 1.0 + k / q + k * k;
  Biquad b;
  b.b0 = 1.0;
  b.b1 = -2.0;
  b.b2 = 1.0;
  b.a1 = 2.0 * (k * k - 1.0) / a0;
  b.a2 = (1.0 - k / q + k * k) / a0;
  return b;
}

/* Direct form II transposed; s[0] and s[1] are the two delays. */
inline double biquad_run(const Biquad& b, double* s, double x) {
  const double y = b.b0 * x + s[0];
  s[0] = b.b1 * x - b.a1 * y + s[1];
  s[1] = b.b2 * x - b.a2 * y;
  return y;
}

double bessel_i0(double x) {
  double sum = 1.0, term = 1.0;
  const double q = x * x / 4.0;
  for (int k = 1; k < 60; k++) {
    term *= q / ((double)k * (double)k);
    sum += term;
    if (term < sum * 1e-17) {
      break;
    }
  }
  return sum;
}

/* The polyphase interpolation filter of the true-peak meter (BS.1770-4
 * Annex 2: the signal oversampled so that the inter-sample peaks show):
 * a Kaiser-windowed sinc low-pass at the input's Nyquist frequency with
 * gain `factor`, split into `factor` branches of kTruePeakTaps so that
 * branch p gives the point p/factor of a sample interval.  The sinc is
 * centred on a tap (the first tap, at the window's edge, is zero), so
 * the branches fall exactly on those points.  Layout:
 * coef[p * kTruePeakTaps + k] multiplies the input k samples back. */
void design_true_peak_filter(int factor, std::vector<double>* coef) {
  const int n = factor * kTruePeakTaps;
  const int centre = n / 2;
  const double cutoff = kTruePeakCutoff * 0.5 / (double)factor; /* cycles per output sample */
  const double i0_beta = bessel_i0(kTruePeakKaiserBeta);
  std::vector<double> h((size_t)n);
  for (int i = 0; i < n; i++) {
    const double t = (double)(i - centre);
    const double sinc = t == 0.0 ? 2.0 * cutoff : sin(2.0 * M_PI * cutoff * t) / (M_PI * t);
    const double r = t / (double)centre;
    const double window = bessel_i0(kTruePeakKaiserBeta * sqrt(std::max(0.0, 1.0 - r * r))) / i0_beta;
    h[(size_t)i] = sinc * window * (double)factor;
  }
  coef->assign((size_t)n, 0.0);
  for (int p = 0; p < factor; p++) {
    for (int k = 0; k < kTruePeakTaps; k++) {
      /* output phase p at time n + p/factor takes h[k * factor + p] on x[n - k] */
      (*coef)[(size_t)(p * kTruePeakTaps + k)] = h[(size_t)(k * factor + p)];
    }
  }
}

int true_peak_factor(int rate) {
  if (rate >= 192000) {
    return 1;
  }
  return rate >= 96000 ? 2 : 4;
}

using ChannelState = struct ChannelState_ {
  double shelf[2], highpass[2];
  double history[2 * kTruePeakTaps]; /* the last taps input samples, doubled for a wrap-free read */
};

} // namespace

struct AacLoudnessMeter_ {
  int rate, channels;
  Biquad shelf, highpass;
  int tp_factor;
  std::vector<double> tp_coef;
  int history_pos;
  ChannelState ch[kMaxChannels];
  /* the current 100 ms sub-block */
  int sub_len, sub_pos;
  double sub_acc[kMaxChannels];
  /* the last thirty sub-block powers (weighted mean squares) */
  double sub_ring[kSubsPerShortTerm];
  long sub_count;
  std::vector<double> blocks; /* 400 ms block powers, for the gating */
  std::vector<double> shorts; /* 3 s window powers, for the range */
  double max_block, max_short;
  double sample_peak, true_peak; /* linear */
};

AacLoudnessMeter* aac_loudness_create(int sample_rate, int channels) {
  if (sample_rate < 8000 || sample_rate > 384000 || channels < 1 || channels > kMaxChannels) {
    return nullptr;
  }
  AacLoudnessMeter* m = new (std::nothrow) AacLoudnessMeter;
  if (!m) {
    return nullptr;
  }
  m->rate = sample_rate;
  m->channels = channels;
  m->shelf = k_shelf((double)sample_rate);
  m->highpass = k_highpass((double)sample_rate);
  m->tp_factor = true_peak_factor(sample_rate);
  if (m->tp_factor > 1) {
    design_true_peak_filter(m->tp_factor, &m->tp_coef);
  }
  m->sub_len = (sample_rate + 5) / 10;
  aac_loudness_reset(m);
  return m;
}

void aac_loudness_destroy(AacLoudnessMeter* m) { delete m; }

void aac_loudness_reset(AacLoudnessMeter* m) {
  if (!m) {
    return;
  }
  memset(m->ch, 0, sizeof(m->ch));
  m->history_pos = 0;
  m->sub_pos = 0;
  memset(m->sub_acc, 0, sizeof(m->sub_acc));
  memset(m->sub_ring, 0, sizeof(m->sub_ring));
  m->sub_count = 0;
  m->blocks.clear();
  m->shorts.clear();
  m->max_block = 0.0;
  m->max_short = 0.0;
  m->sample_peak = 0.0;
  m->true_peak = 0.0;
}

namespace {

/* A 100 ms sub-block is complete: its weighted power joins the ring, and
 * the block and short-term windows that end here are formed as the mean
 * of the last four and thirty sub-blocks. */
void finish_sub_block(AacLoudnessMeter* m) {
  double power = 0.0;
  for (int c = 0; c < m->channels; c++) {
    power += kChannelWeight[c] * m->sub_acc[c] / (double)m->sub_len;
    m->sub_acc[c] = 0.0;
  }
  m->sub_ring[m->sub_count % kSubsPerShortTerm] = power;
  m->sub_count++;
  m->sub_pos = 0;
  if (m->sub_count >= kSubsPerBlock) {
    double sum = 0.0;
    for (int i = 1; i <= kSubsPerBlock; i++) {
      sum += m->sub_ring[(m->sub_count - i) % kSubsPerShortTerm];
    }
    const double block = sum / kSubsPerBlock;
    m->blocks.push_back(block);
    m->max_block = std::max(m->max_block, block);
  }
  if (m->sub_count >= kSubsPerShortTerm) {
    double sum = 0.0;
    for (int i = 0; i < kSubsPerShortTerm; i++) {
      sum += m->sub_ring[i];
    }
    const double window = sum / kSubsPerShortTerm;
    m->shorts.push_back(window);
    m->max_short = std::max(m->max_short, window);
  }
}

} // namespace

void aac_loudness_feed(AacLoudnessMeter* m, const float* interleaved, int frames) {
  if (!m || !interleaved || frames <= 0) {
    return;
  }
  const int channels = m->channels;
  const int factor = m->tp_factor;
  const double* coef = m->tp_coef.empty() ? nullptr : m->tp_coef.data();
  double sample_peak = m->sample_peak, true_peak = m->true_peak;
  for (int n = 0; n < frames; n++) {
    const int pos = m->history_pos;
    for (int c = 0; c < channels; c++) {
      ChannelState* s = &m->ch[c];
      const double x = (double)interleaved[(size_t)n * (size_t)channels + (size_t)c];
      const double magnitude = fabs(x);
      if (magnitude > sample_peak) {
        sample_peak = magnitude;
      }
      if (coef) {
        /* the newest sample sits at pos + taps, the k-th older at pos + taps - k */
        s->history[pos] = x;
        s->history[pos + kTruePeakTaps] = x;
        const double* recent = &s->history[pos + kTruePeakTaps];
        for (int p = 0; p < factor; p++) {
          const double* h = &coef[p * kTruePeakTaps];
          double y = 0.0;
          for (int k = 0; k < kTruePeakTaps; k++) {
            y += h[k] * recent[-k];
          }
          const double peak = fabs(y);
          if (peak > true_peak) {
            true_peak = peak;
          }
        }
      }
      /* BS.1770-4 clause 2: the shelf then the high-pass; the mean square
       * of the weighted signal accumulates over the sub-block. */
      const double w = biquad_run(m->highpass, s->highpass, biquad_run(m->shelf, s->shelf, x));
      m->sub_acc[c] += w * w;
    }
    m->history_pos = pos + 1 == kTruePeakTaps ? 0 : pos + 1;
    if (++m->sub_pos == m->sub_len) {
      finish_sub_block(m);
    }
  }
  m->sample_peak = sample_peak;
  m->true_peak = true_peak;
}

namespace {

/* The two-stage gate of BS.1770-4 clause 4 over a set of window powers:
 * the windows above the absolute gate set the relative gate, which lies
 * `relative_lu` below their mean loudness; the windows above both are
 * copied to `kept` (when given) and their mean power returned, 0 when
 * none passes. */
double gated_mean(const std::vector<double>& windows, double relative_lu, std::vector<double>* kept) {
  const double absolute = db_to_power(kAbsoluteGateLkfs - kLoudnessOffsetDb);
  double sum = 0.0;
  long count = 0;
  for (double p : windows) {
    if (p > absolute) {
      sum += p;
      count++;
    }
  }
  if (count == 0) {
    return 0.0;
  }
  const double relative = (sum / (double)count) * db_to_power(relative_lu);
  sum = 0.0;
  count = 0;
  for (double p : windows) {
    if (p > absolute && p > relative) {
      sum += p;
      count++;
      if (kept) {
        kept->push_back(p);
      }
    }
  }
  return count ? sum / (double)count : 0.0;
}

/* EBU Tech 3342: the spread between the 10th and the 95th percentile of
 * the gated short-term loudness distribution. */
double loudness_range(const std::vector<double>& shorts) {
  std::vector<double> kept;
  gated_mean(shorts, kRelativeGateRangeLu, &kept);
  if (kept.size() < 2) {
    return 0.0;
  }
  std::sort(kept.begin(), kept.end());
  const size_t last = kept.size() - 1;
  const size_t low = (size_t)((double)last * 0.10 + 0.5);
  const size_t high = (size_t)((double)last * 0.95 + 0.5);
  return power_to_lkfs(kept[high]) - power_to_lkfs(kept[low]);
}

} // namespace

int aac_loudness_result(const AacLoudnessMeter* m, AacLoudnessInfo* out) {
  if (!m || !out) {
    return AAC_ERR_INVALID_ARG;
  }
  if (m->blocks.empty()) {
    return AAC_ERR_STATE;
  }
  memset(out, 0, sizeof(*out));
  out->sample_peak_dbfs = linear_to_db(m->sample_peak);
  out->true_peak_dbtp = linear_to_db(std::max(m->true_peak, m->sample_peak));
  out->has_true_peak = 1;
  out->integrated_lkfs = power_to_lkfs(gated_mean(m->blocks, kRelativeGateIntegratedLu, nullptr));
  out->max_momentary_lkfs = power_to_lkfs(m->max_block);
  out->has_momentary = 1;
  out->max_short_term_lkfs = AAC_LOUDNESS_SILENCE;
  if (!m->shorts.empty()) {
    out->max_short_term_lkfs = power_to_lkfs(m->max_short);
    out->has_short_term = 1;
    out->range_lu = (float)loudness_range(m->shorts);
    out->has_range = 1;
  }
  return AAC_OK;
}

/* ── loudnessInfoSet() ─────────────────────────────────────────── */

namespace {

/* ISO/IEC 23003-4: a peak level in 0.03125 dB steps down from +20 dBFS
 * (12 bits), a loudness level in 0.25 dB steps up from -57.75 (8 bits)
 * and the loudness range in three segments of 0.25, 0.5 and 1 LU. */
int clamp_code(double code, int max) {
  return code <= 0.0 ? 0 : code >= (double)max ? max : (int)lround(code);
}

int peak_code(float db) { return clamp_code((20.0 - (double)db) / 0.03125, 4095); }

int level_code(float db) { return clamp_code(((double)db + 57.75) / 0.25, 255); }

int range_code(float lu) {
  const double v = (double)lu;
  if (v < 32.0) {
    return clamp_code(v * 4.0, 255);
  }
  if (v < 70.0) {
    return clamp_code(128.0 + (v - 32.0) * 2.0, 255);
  }
  return clamp_code(204.0 + (v - 70.0), 255);
}

constexpr int kSystemBs1770_4 = 2;
constexpr int kReliabilityAccurate = 3;

using Measurement = struct Measurement_ {
  int method;
  int code;
};

/* The measurements of the single loudnessInfo(), in the order written. */
int collect_measurements(const AacLoudnessInfo* info, Measurement* out) {
  int n = 0;
  out[n++] = {AAC_UNIDRC_METHOD_PROGRAM_LOUDNESS, level_code(info->integrated_lkfs)};
  if (info->has_anchor) {
    out[n++] = {AAC_UNIDRC_METHOD_ANCHOR_LOUDNESS, level_code(info->anchor_lkfs)};
  }
  if (info->has_range) {
    out[n++] = {AAC_UNIDRC_METHOD_LOUDNESS_RANGE, range_code(info->range_lu)};
  }
  if (info->has_momentary) {
    out[n++] = {AAC_UNIDRC_METHOD_MOMENTARY_MAX, level_code(info->max_momentary_lkfs)};
  }
  if (info->has_short_term) {
    out[n++] = {AAC_UNIDRC_METHOD_SHORT_TERM_MAX, level_code(info->max_short_term_lkfs)};
  }
  return n;
}

} // namespace

int aac_loudness_info_bits(const AacLoudnessInfo* info) {
  if (!info) {
    return AAC_ERR_INVALID_ARG;
  }
  Measurement m[5];
  const int n = collect_measurements(info, m);
  /* the counts, drcSetId, downmixId, the sample peak with its flag, the
   * true peak flag, measurementCount and the terminating extension flag */
  int bits = 6 + 6 + 6 + 7 + 1 + 12 + 1 + 4 + 1;
  if (info->has_true_peak) {
    bits += 12 + 4 + 2;
  }
  return bits + n * (4 + 8 + 4 + 2);
}

int aac_loudness_info_write(AacBitWriter* w, const AacLoudnessInfo* info) {
  if (!w || !info) {
    return AAC_ERR_INVALID_ARG;
  }
  const int start = aac_bitwriter_bits_written(w);
  aac_bitwriter_write(w, 0, 6); /* loudnessInfoAlbumCount */
  aac_bitwriter_write(w, 1, 6); /* loudnessInfoCount */
  /* loudnessInfo() */
  aac_bitwriter_write(w, 0, 6); /* drcSetId */
  aac_bitwriter_write(w, 0, 7); /* downmixId */
  aac_bitwriter_write(w, 1, 1); /* samplePeakLevelPresent */
  aac_bitwriter_write(w, (uint32_t)peak_code(info->sample_peak_dbfs), 12);
  aac_bitwriter_write(w, info->has_true_peak ? 1u : 0u, 1); /* truePeakLevelPresent */
  if (info->has_true_peak) {
    aac_bitwriter_write(w, (uint32_t)peak_code(info->true_peak_dbtp), 12);
    aac_bitwriter_write(w, kSystemBs1770_4, 4);
    aac_bitwriter_write(w, kReliabilityAccurate, 2);
  }
  Measurement m[5];
  const int n = collect_measurements(info, m);
  aac_bitwriter_write(w, (uint32_t)n, 4); /* measurementCount */
  for (int i = 0; i < n; i++) {
    aac_bitwriter_write(w, (uint32_t)m[i].method, 4); /* methodDefinition */
    aac_bitwriter_write(w, (uint32_t)m[i].code, 8);   /* methodValue */
    aac_bitwriter_write(w, kSystemBs1770_4, 4);        /* measurementSystem */
    aac_bitwriter_write(w, kReliabilityAccurate, 2);   /* reliability */
  }
  aac_bitwriter_write(w, 0, 1); /* loudnessInfoSetExtPresent */
  if (aac_bitwriter_overflow(w)) {
    return AAC_ERR_OVERFLOW;
  }
  return aac_bitwriter_bits_written(w) - start;
}
