/* The floating-point analysis of the FLAC encoder: analysis windows,
 * autocorrelation, the Levinson-Durbin recursion and the quantisation
 * of predictor coefficients.
 *
 * Nothing here touches a sample that reaches the stream: the analysis
 * proposes integer coefficients, and the encoder computes every residual
 * from them in integers.  But the proposal decides which bytes are
 * written, so this file is compiled in exact arithmetic and without
 * contraction: every sum is taken in the order it is written here, and
 * a kernel that replaces a loop of this file has to keep that order to
 * give the same stream.
 *
 * For the same reason the two transcendental functions the analysis
 * needs, a cosine for the windows and a base 2 logarithm for the size
 * estimates, are computed here and not taken from the C library: a
 * library is free to return either neighbour of the true value, and
 * libraries, and one library on different processors, do differ in the
 * last bit.  The functions below use additions, multiplications and
 * divisions of doubles in a fixed order, which every IEEE 754 machine
 * performs alike.  What is left of <cmath> is exact: fabs, floor and
 * frexp return the same value everywhere. */
#include <cmath>
#include <cstring>

#include "flac_internal.h"

namespace {

const double kPi = 3.14159265358979323846;

/* Pi and its half, each as the nearest double and what that leaves
 * out, for differences that keep the missing bits. */
const double kPiHigh = 0x1.921fb54442d18p+1;
const double kPiLow = 0x1.1a62633145c07p-53;
const double kHalfPiHigh = 0x1.921fb54442d18p+0;
const double kHalfPiLow = 0x1.1a62633145c07p-54;
const double kQuarterPi = 0x1.921fb54442d18p-1;

/* The cosine of y for y in [0, pi/4]: the Taylor series to y^16, whose
 * next term is below 2e-18 there.  The first two terms are taken apart
 * so that the rounding of 1 - y^2/2 is put back. */
double cos_kernel(double y) {
  const double z = y * y;
  const double tail =
      z * z *
      (1.0 / 24.0 + z * (-1.0 / 720.0 +
                         z * (1.0 / 40320.0 +
                              z * (-1.0 / 3628800.0 +
                                   z * (1.0 / 479001600.0 + z * (-1.0 / 87178291200.0 +
                                                                 z * (1.0 / 20922789888000.0)))))));
  const double half = 0.5 * z;
  const double w = 1.0 - half;
  return w + (((1.0 - w) - half) + tail);
}

/* The sine of y for y in [0, pi/4]: the Taylor series to y^17, whose
 * next term is below 1e-19 there. */
double sin_kernel(double y) {
  const double z = y * y;
  const double tail =
      -1.0 / 6.0 +
      z * (1.0 / 120.0 +
           z * (-1.0 / 5040.0 +
                z * (1.0 / 362880.0 +
                     z * (-1.0 / 39916800.0 +
                          z * (1.0 / 6227020800.0 +
                               z * (-1.0 / 1307674368000.0 + z * (1.0 / 355687428096000.0)))))));
  return y + y * (z * tail);
}

/* 1 / ln 2, and the square root of 2. */
constexpr double kInverseLn2 = 0x1.71547652b82fep+0;
constexpr double kSqrt2 = 0x1.6a09e667f3bcdp+0;

/* The base 2 logarithm of m in [1, 2) by a series: m is halved into
 * (sqrt 1/2, sqrt 2] where it has to be, and ln m = 2 atanh(s) with
 * s = (m - 1) / (m + 1), at most 0.1716 in magnitude, summed as
 * 2 (s + s^3/3 + s^5/5 + ...) to s^23, whose next term is below 1e-20.
 * It divides and has a long chain of products, which is too slow for
 * the estimates; it fills the table below, when the library is
 * compiled. */
constexpr double log2_series(double m) {
  double exponent = 0.0;
  if (m > kSqrt2) {
    m *= 0.5;
    exponent = 1.0;
  }
  const double s = (m - 1.0) / (m + 1.0);
  const double z = s * s;
  double tail = 1.0 / 23.0;
  for (int k = 21; k >= 3; k -= 2) {
    tail = 1.0 / (double)k + z * tail;
  }
  const double twice = 2.0 * s;
  return exponent + (twice * kInverseLn2 + (twice * (z * tail)) * kInverseLn2);
}

/* The mantissas [1, 2) in 128 intervals: for each its centre, which is
 * exact in a double, the reciprocal of the centre and its logarithm. */
constexpr int kLogIntervals = 128;
struct LogTable {
  double centre[kLogIntervals];
  double reciprocal[kLogIntervals];
  double log2_centre[kLogIntervals];
  constexpr LogTable() : centre(), reciprocal(), log2_centre() {
    for (int i = 0; i < kLogIntervals; i++) {
      centre[i] = 1.0 + ((double)i + 0.5) / (double)kLogIntervals;
      reciprocal[i] = 1.0 / centre[i];
      log2_centre[i] = log2_series(centre[i]);
    }
  }
};
constexpr LogTable kLog;

/* The coefficients of log2(1 + u) = (u - u^2/2 + u^3/3 - ...) / ln 2. */
constexpr double kLog1 = kInverseLn2;
constexpr double kLog2 = -kInverseLn2 / 2.0;
constexpr double kLog3 = kInverseLn2 / 3.0;
constexpr double kLog4 = -kInverseLn2 / 4.0;
constexpr double kLog5 = kInverseLn2 / 5.0;
constexpr double kLog6 = -kInverseLn2 / 6.0;
constexpr double kLog7 = kInverseLn2 / 7.0;

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
      v = 0.5 - 0.5 * flac_cos(kPi * ((double)edge + 0.5) / (double)taper);
    }
    w[begin + i] += v;
  }
}

/* Parts the windows of a set cut the block into: 1, 2 or 3 for sets of
 * 1, 3 and 9 windows. */
int window_parts(int windows) { return windows >= 9 ? 3 : windows >= 3 ? 2 : 1; }

}  // namespace

double flac_cos(double x) {
  /* Onto [0, pi/2] by cos(x) = -cos(pi - x), then onto [0, pi/4] by
   * cos(y) = sin(pi/2 - y).  Each difference is exact in its first
   * step, the two doubles being within a factor of two of each other,
   * and takes the bits the constant leaves out in the second. */
  const bool negate = x > kHalfPiHigh;
  const double y = negate ? (kPiHigh - x) + kPiLow : x;
  const double c = y > kQuarterPi ? sin_kernel((kHalfPiHigh - y) + kHalfPiLow) : cos_kernel(y);
  return negate ? -c : c;
}

double flac_log2(double x) {
  /* x = m * 2^e with m in [1, 2), taken from the bits of the double; a
   * subnormal number is scaled into the normal range first, which is
   * exact. */
  uint64_t bits;
  memcpy(&bits, &x, sizeof(bits));
  int exponent = (int)(bits >> 52) & 0x7FF;
  if (exponent == 0) {
    x *= 0x1p54;
    memcpy(&bits, &x, sizeof(bits));
    exponent = ((int)(bits >> 52) & 0x7FF) - 54;
  }
  exponent -= 1023;
  const int interval = (int)(bits >> 45) & (kLogIntervals - 1);
  bits = (bits & 0x000FFFFFFFFFFFFFull) | 0x3FF0000000000000ull;
  double m;
  memcpy(&m, &bits, sizeof(m));
  if (m == 1.0) {
    return (double)exponent; /* a power of two, exactly */
  }
  /* m = c (1 + u) for the centre c of its interval: m - c is exact, and
   * u is below 1/256 in magnitude, so that the series of log2(1 + u) is
   * done at u^7, whose successor is below 1e-20.  The products are
   * paired, not chained, so that the sum does not wait on one long row
   * of them; the order is fixed all the same. */
  const double u = (m - kLog.centre[interval]) * kLog.reciprocal[interval];
  const double u2 = u * u;
  const double low = kLog1 + u * kLog2;
  const double middle = kLog3 + u * kLog4;
  const double high = (kLog5 + u * kLog6) + u2 * kLog7;
  const double series = u * (low + u2 * (middle + u2 * high));
  return (double)exponent + (kLog.log2_centre[interval] + series);
}

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
    const double bits = 0.5 * flac_log2(error / window_energy) + offset;
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
