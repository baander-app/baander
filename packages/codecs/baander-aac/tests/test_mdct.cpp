/* The fast MDCT/IMDCT against the direct definition, and time-domain
 * aliasing cancellation through the windows the codec uses. */
#include "aac_tables.h"
#include "mdct.h"
#include "test_util.h"

static float rnd(unsigned* seed) {
  *seed = *seed * 1664525u + 1013904223u;
  return (float)(*seed >> 8) / 8388608.0f - 1.0f;
}

static void test_against_definition(int n) {
  AacMdct m;
  aac_mdct_init(&m, n);
  std::vector<float> x(n), X(n / 2), y(n);
  unsigned seed = 3;
  for (int i = 0; i < n; i++) {
    x[i] = rnd(&seed);
  }
  const double n0 = n / 4.0 + 0.5;
  aac_mdct_forward(&m, x.data(), X.data());
  double err = 0.0;
  double peak = 0.0;
  for (int k = 0; k < n / 2; k++) {
    double s = 0.0;
    for (int j = 0; j < n; j++) {
      s += x[j] * cos(2.0 * M_PI / n * (j + n0) * (k + 0.5));
    }
    s *= 2.0;
    err = std::max(err, fabs(s - X[k]));
    peak = std::max(peak, fabs(s));
  }
  CHECK(err / peak < 1e-5, "n=%d forward MDCT relative error %.3g", n, err / peak);

  aac_imdct(&m, X.data(), y.data());
  err = 0.0;
  peak = 0.0;
  for (int j = 0; j < n; j++) {
    double s = 0.0;
    for (int k = 0; k < n / 2; k++) {
      s += X[k] * cos(2.0 * M_PI / n * (j + n0) * (k + 0.5));
    }
    s *= 2.0 / n;
    err = std::max(err, fabs(s - y[j]));
    peak = std::max(peak, fabs(s));
  }
  CHECK(err / peak < 1e-5, "n=%d inverse MDCT relative error %.3g", n, err / peak);
}

/* Windowed MDCT of overlapping frames, IMDCT, windowing and overlap-add
 * must reproduce the signal. */
static void test_tdac(int shape, int half) {
  const int n = 2 * half;
  AacMdct m;
  aac_mdct_init(&m, n);
  const float* w = half == AAC_FRAME_SIZE_LONG ? aac_window_long[shape] : aac_window_short[shape];
  const int frames = 6;
  std::vector<float> x((size_t)(frames + 1) * half);
  unsigned seed = 11;
  for (float& v : x) {
    v = rnd(&seed);
  }
  std::vector<float> out(x.size(), 0.0f);
  std::vector<float> buf(n), X(half), y(n);
  for (int f = 0; f < frames; f++) {
    for (int i = 0; i < half; i++) {
      buf[i] = x[(size_t)f * half + i] * w[i];
      buf[half + i] = x[(size_t)f * half + half + i] * w[half - 1 - i];
    }
    aac_mdct_forward(&m, buf.data(), X.data());
    aac_imdct(&m, X.data(), y.data());
    for (int i = 0; i < half; i++) {
      out[(size_t)f * half + i] += y[i] * w[i];
      out[(size_t)f * half + half + i] += y[half + i] * w[half - 1 - i];
    }
  }
  double err = 0.0;
  for (size_t i = half; i < (size_t)frames * half; i++) {
    err = std::max(err, (double)fabs(out[i] - x[i]));
  }
  CHECK(err < 1e-4, "TDAC shape %d half %d: max error %.3g", shape, half, err);
}

int main() {
  aac_tables_init();
  test_against_definition(256);
  test_against_definition(2048);
  for (int shape = 0; shape < 2; shape++) {
    test_tdac(shape, AAC_FRAME_SIZE_LONG);
    test_tdac(shape, AAC_FRAME_SIZE_SHORT);
  }
  return test_summary("test_mdct");
}
