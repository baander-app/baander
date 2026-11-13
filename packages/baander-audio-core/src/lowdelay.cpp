#include "lowdelay.h"

#include <cstring>

namespace {

/* Downscaled synthesis windows: each coefficient is the mean of `factor`
 * neighbouring full-rate coefficients, divided by `factor` so that the
 * shorter transform's larger 2/N scale keeps the output level. */
struct DownscaledWindows {
  float w512_2[AAC_ELD_WINDOW_LENGTH(256)];
  float w512_4[AAC_ELD_WINDOW_LENGTH(128)];
  float w480_2[AAC_ELD_WINDOW_LENGTH(240)];
  float w480_4[AAC_ELD_WINDOW_LENGTH(120)];

  static void build(const float* full, int full_len, int factor, float* out) {
    for (int i = 0; i < full_len / factor; i++) {
      float sum = 0.0f;
      for (int j = 0; j < factor; j++) {
        sum += full[i * factor + j];
      }
      out[i] = sum / (float)(factor * factor);
    }
  }

  DownscaledWindows() {
    build(aac_eld_synthesis_window_512, AAC_ELD_WINDOW_LENGTH(512), 2, w512_2);
    build(aac_eld_synthesis_window_512, AAC_ELD_WINDOW_LENGTH(512), 4, w512_4);
    build(aac_eld_synthesis_window_480, AAC_ELD_WINDOW_LENGTH(480), 2, w480_2);
    build(aac_eld_synthesis_window_480, AAC_ELD_WINDOW_LENGTH(480), 4, w480_4);
  }
};

const DownscaledWindows& downscaled() {
  static const DownscaledWindows windows;
  return windows;
}

}  // namespace

const float* aac_eld_synthesis_window(int frame_length, int factor) {
  if (frame_length != 512 && frame_length != 480) {
    return nullptr;
  }
  const bool is512 = frame_length == 512;
  switch (factor) {
    case 1:
      return is512 ? aac_eld_synthesis_window_512 : aac_eld_synthesis_window_480;
    case 2:
      return is512 ? downscaled().w512_2 : downscaled().w480_2;
    case 4:
      return is512 ? downscaled().w512_4 : downscaled().w480_4;
    default:
      return nullptr;
  }
}

/* The modulation of the synthesis is the inverse MDCT's,
 *   x[j] = 2/N sum X[k] cos(2 pi / N (j + N/4 + 1/2)(k + 1/2)),  j < N,
 * shifted by 3M/4 samples: s[i] = -x[i - 3M/4], with x continued outside
 * 0..N-1 by x[j + N] = -x[j]. */
void aac_eld_synthesis(const AacMdct* mdct, const float* window, int m, const float* spec,
                       float* overlap, float* out, float scale) {
  const int n = 2 * m;
  const int shift = 3 * m / 4;
  const int win_len = AAC_ELD_WINDOW_LENGTH(m);
  float x[2 * AAC_ELD_MAX_FRAME];
  float y[4 * AAC_ELD_MAX_FRAME];
  aac_imdct(mdct, spec, x);
  for (int i = 0; i < win_len; i++) {
    int j = i - shift;
    float sign = -1.0f;
    if (j < 0) {
      j += n;
      sign = 1.0f;
    } else if (j >= 2 * n) {
      j -= 2 * n;
    } else if (j >= n) {
      j -= n;
      sign = 1.0f;
    }
    y[i] = sign * window[i] * x[j];
  }
  memset(y + win_len, 0, (size_t)(4 * m - win_len) * sizeof(float));
  for (int i = 0; i < m; i++) {
    out[i] = (overlap[i] + y[i]) * scale;
  }
  for (int i = 0; i < 2 * m; i++) {
    overlap[i] = overlap[m + i] + y[m + i];
  }
  memcpy(overlap + 2 * m, y + 3 * m, (size_t)m * sizeof(float));
}

/* The analysis modulation is the synthesis one reversed in time,
 *   X[k] = -sum_{j<4M} a[j] u[j] cos(2 pi / N (j - 7M/4 + 1/2)(k + 1/2)),
 * folded onto the forward MDCT X[k] = 2 sum_{r<N} z[r] cos(2 pi / N (r +
 * N/4 + 1/2)(k + 1/2)): sample j lands on r = j - 9M/4 + q N for the q
 * that puts r in 0..N-1, with the sign (-1)^q. */
void aac_eld_analysis(const AacMdct* mdct, int m, const float* in, float* spec) {
  const int n = 2 * m;
  const float* a = m == 512 ? aac_eld_analysis_window_512 : aac_eld_analysis_window_480;
  float z[2 * AAC_ELD_MAX_FRAME];
  memset(z, 0, (size_t)n * sizeof(float));
  /* The last M window coefficients are zero: the newest frame of input
   * does not enter. */
  for (int j = 0; j < 3 * m; j++) {
    const float v = a[j] * in[j];
    int r = j - 9 * m / 4;
    if (r < -n) {
      z[r + 2 * n] += v;
    } else if (r < 0) {
      z[r + n] -= v;
    } else {
      z[r] += v;
    }
  }
  aac_mdct_forward(mdct, z, spec);
  for (int k = 0; k < m; k++) {
    spec[k] *= -0.5f;
  }
}
