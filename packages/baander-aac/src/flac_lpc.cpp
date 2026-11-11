/* The floating-point analysis of the FLAC encoder: analysis windows,
 * autocorrelation, the Levinson-Durbin recursion and the quantisation
 * of predictor coefficients.
 *
 * Nothing here touches a sample that reaches the stream: the analysis
 * proposes integer coefficients, and the encoder computes every residual
 * from them in integers.  But the proposal decides which bytes are
 * written, so this file is compiled without the package's Release math
 * options and without contraction: every sum is taken in the order it is
 * written here, and a kernel that replaces a loop of this file has to
 * keep that order to give the same stream. */
#include <cmath>

#include "flac_internal.h"

namespace {

const double kPi = 3.14159265358979323846;

/* Adds a raised-cosine window over [begin, end) to `w`: a rise over the
 * first `taper` samples, a fall over the last, one in between.  The
 * cosine is sampled at the centres of the samples, so that no sample of
 * the range is weighted zero. */
void add_tapered_range(double* w, int begin, int end, int taper) {
  const int length = end - begin;
  if (taper * 2 > length) {
    taper = length / 2;
  }
  for (int i = 0; i < length; i++) {
    double v = 1.0;
    const int edge = i < length - 1 - i ? i : length - 1 - i;
    if (edge < taper) {
      v = 0.5 - 0.5 * cos(kPi * ((double)edge + 0.5) / (double)taper);
    }
    w[begin + i] += v;
  }
}

/* Parts the windows of a set cut the block into: 1, 2 or 3 for sets of
 * 1, 3 and 9 windows. */
int window_parts(int windows) { return windows >= 9 ? 3 : windows >= 3 ? 2 : 1; }

}  // namespace

int flac_lpc_window_ranges(int n, int index, int* ranges) {
  if (index == 0) {
    ranges[0] = 0; /* the whole block */
    ranges[1] = n;
    return 1;
  }
  if (index <= 2) {
    const int half = index - 1; /* one half */
    ranges[0] = n * half / 2;
    ranges[1] = n * (half + 1) / 2;
    return 1;
  }
  if (index <= 5) {
    const int third = index - 3; /* one third */
    ranges[0] = n * third / 3;
    ranges[1] = n * (third + 1) / 3;
    return 1;
  }
  /* The block without one third: what lies before it and what lies
   * behind it, each a range with ends of its own. */
  const int third = index - 6;
  int count = 0;
  if (third > 0) {
    ranges[0] = 0;
    ranges[1] = n * third / 3;
    count++;
  }
  if (third < 2) {
    ranges[2 * count] = n * (third + 1) / 3;
    ranges[2 * count + 1] = n;
    count++;
  }
  return count;
}

void flac_lpc_window(double* w, int n, int windows, int index, double taper_fraction) {
  /* The tapers of every window of a set have one length: the fraction of
   * the smallest part the set cuts the block into, half of it per side. */
  const int parts = window_parts(windows);
  int taper = (int)((double)n * taper_fraction * 0.5 / (double)parts);
  if (taper < 1) {
    taper = 1;
  }
  for (int i = 0; i < n; i++) {
    w[i] = 0.0;
  }
  int ranges[4];
  const int count = flac_lpc_window_ranges(n, index, ranges);
  for (int i = 0; i < count; i++) {
    add_tapered_range(w, ranges[2 * i], ranges[2 * i + 1], taper);
  }
}

void flac_lpc_hann(double* w, int n) {
  for (int i = 0; i < n; i++) {
    w[i] = 0.0;
  }
  add_tapered_range(w, 0, n, n / 2);
}

int flac_lpc_levinson(const double* r, int max_order, double* coefs, double* errors) {
  FLAC_PROFILE(FLAC_PROF_LEVINSON);
  double a[FLAC_MAX_LPC_ORDER];
  double error = r[0];
  int order = 0;
  while (order < max_order) {
    if (!(error > 0.0) || !std::isfinite(error)) {
      break;
    }
    double acc = r[order + 1];
    for (int j = 0; j < order; j++) {
      acc -= a[j] * r[order - j];
    }
    const double k = acc / error;
    if (!std::isfinite(k)) {
      break;
    }
    /* The new coefficients from the old, both ends at a time. */
    for (int j = 0; j < order / 2; j++) {
      const double head = a[j];
      const double tail = a[order - 1 - j];
      a[j] = head - k * tail;
      a[order - 1 - j] = tail - k * head;
    }
    if (order & 1) {
      a[order / 2] -= k * a[order / 2];
    }
    a[order] = k;
    error *= 1.0 - k * k;
    order++;
    for (int j = 0; j < order; j++) {
      coefs[(order - 1) * FLAC_MAX_LPC_ORDER + j] = a[j];
    }
    errors[order - 1] = error;
  }
  return order;
}

bool flac_lpc_quantize(const double* a, int order, int precision, int32_t* q, int* shift) {
  FLAC_PROFILE(FLAC_PROF_QUANTISE);
  double largest = 0.0;
  for (int j = 0; j < order; j++) {
    const double magnitude = fabs(a[j]);
    if (!std::isfinite(magnitude)) {
      return false;
    }
    if (magnitude > largest) {
      largest = magnitude;
    }
  }
  if (largest <= 0.0) {
    return false;
  }
  /* The largest coefficient is below 2^exponent; the shift that brings
   * it just under the top of `precision` bits.  The format has no
   * negative shift, so coefficients too large for the precision cannot
   * be written, and it holds 15 at most, which leaves very small
   * coefficients with fewer bits than asked for. */
  int exponent = 0;
  frexp(largest, &exponent);
  int s = precision - 1 - exponent;
  if (s < 0) {
    return false;
  }
  if (s > 15) {
    s = 15;
  }
  const double scale = (double)(1 << s);
  const double top = (double)((1 << (precision - 1)) - 1);
  const double bottom = -(double)(1 << (precision - 1));
  /* Each coefficient is rounded with the rounding error of the ones
   * before it carried along, so that the errors do not add up. */
  double carried = 0.0;
  bool any = false;
  for (int j = 0; j < order; j++) {
    const double exact = a[j] * scale + carried;
    double rounded = floor(exact + 0.5);
    if (rounded > top) {
      rounded = top;
    }
    if (rounded < bottom) {
      rounded = bottom;
    }
    carried = exact - rounded;
    q[j] = (int32_t)rounded;
    any = any || q[j] != 0;
  }
  *shift = s;
  return any;
}

double flac_lpc_energy(const double* w, int n) {
  double sum = 0.0;
  for (int i = 0; i < n; i++) {
    sum += w[i] * w[i];
  }
  return sum;
}

double flac_lpc_estimate_bits(double error, double window_energy, int samples, double offset) {
  double per_sample = 1.0;
  if (error > 0.0 && window_energy > 0.0) {
    const double bits = 0.5 * log2(error / window_energy) + offset;
    if (bits > per_sample) {
      per_sample = bits;
    }
  }
  return (double)samples * per_sample;
}

double flac_lpc_quantized_error(const double* r, const int32_t* q, int shift, int order) {
  FLAC_PROFILE(FLAC_PROF_QUANTISE);
  /* The error filter: one for the sample itself, minus each quantised
   * coefficient for the sample it weights.  Its output energy on the
   * windowed signal is the filter's quadratic form in the
   * autocorrelation, which is symmetric: every pair of taps counts
   * twice. */
  double b[FLAC_MAX_LPC_ORDER + 1];
  const double scale = 1.0 / (double)(1 << shift);
  b[0] = 1.0;
  for (int j = 0; j < order; j++) {
    b[j + 1] = -(double)q[j] * scale;
  }
  double error = 0.0;
  for (int i = 0; i <= order; i++) {
    double below = 0.0;
    for (int j = 0; j < i; j++) {
      below += b[j] * r[i - j];
    }
    error += b[i] * (b[i] * r[0] + 2.0 * below);
  }
  return error;
}
