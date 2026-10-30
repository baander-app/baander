#include "qmf.h"

#include <cmath>
#include <cstring>
#include <mutex>

#include "fft.h"

/* Complex QMF banks (ISO/IEC 14496-3 4.6.18.4 and 4.6.18.8, 4.B.18.2).
 * The decoder's banks (32-band analysis, 64-band synthesis) run their
 * modulation through a complex FFT with a twiddle before and after; the
 * encoder's banks (64-band analysis, 32-band synthesis) keep the direct
 * sums over the modulation tables, so that the encoder's output does not
 * depend on the transform's rounding.
 *
 * Analysis with B bands: the window of 10 B taps (the prototype, or every
 * second tap for 32 bands) weights the newest 10 B input samples, the
 * products are folded into 2 B values u(n), and
 *   W(k) = g sum_n u(n) exp(i pi / (2 B) (k + 1/2) (2 n - d)),
 * with g = 2 and d = 1/2 for the 32-band bank on the half-rate core signal
 * (4.6.18.4.1) and g = 1 and d = 1 for the 64-band bank on the full-rate
 * signal (4.B.18.2), so that both banks give the low band the same
 * magnitudes.
 *
 * Synthesis with B bands: v(n) = 1/64 Re{sum_k X(k) exp(i pi / (2 B)
 * (k + 1/2) (2 n - c))} for n < 2 B, with c = 255 for 64 bands and
 * c = 127.5 for the downsampled 32-band bank; the newest block of v is
 * windowed together with alternating halves of the nine older ones, and
 * the ten windowed blocks are summed to B output samples.  Both
 * conventions were pinned against FFmpeg's and FAAD2's filterbanks.
 *
 * FFT form of the decoder's banks.  Analysis: with f = pi / 64,
 *   (k + 1/2)(2 n - 1/2) = 2 n k + (n - 1/4) - k / 2,
 * so W(k) = 2 exp(-i f k / 2) sum_n [u(n) exp(i f (n - 1/4))] exp(2 pi i
 * n k / 64): a real-to-complex twiddle, a 64-point transform and a
 * twiddle of the 32 wanted outputs.  Synthesis: with t = pi / 128,
 *   (k + 1/2)(2 n - 255) = 2 n k + (n - 127.5) - 255 k,
 * so v(n) = Re{exp(i t (n - 127.5)) sum_k [X(k) exp(-i t 255 k)] exp(2 pi
 * i n k / 128)} / 64 over the 64 bands zero-padded to 128 points.  Both
 * sums carry the positive exponent, which the forward transform gives as
 * the conjugate of the transform of the conjugated input. */

namespace {

float g_ana_cos64[64][128], g_ana_sin64[64][128];
float g_syn_cos32[64][32], g_syn_sin32[64][32];
/* The 32-band banks read every second prototype tap. */
float g_window32[320];
/* Decoder banks: transforms and twiddles. */
AacFft g_fft64, g_fft128;
float g_ana32_pre_re[64], g_ana32_pre_im[64];
float g_ana32_post_re[32], g_ana32_post_im[32];
float g_syn64_pre_re[64], g_syn64_pre_im[64];
float g_syn64_post_re[128], g_syn64_post_im[128];
/* The 16- and 24-band banks of the enhanced SBR: the prototype read every
 * fourth tap, or interpolated at every 8/3rd tap, and the direct
 * modulation tables (64 / B) cos and sin of pi / (2 B) (k + 1/2)
 * (2 n - B / 64). */
float g_window16[160], g_window24[240];
float g_ana_cos16[16][32], g_ana_sin16[16][32];
float g_ana_cos24[24][48], g_ana_sin24[24][48];

/* The prototype at a fractional tap position by four-point Lagrange
 * interpolation; the window is zero beyond its ends. */
double prototype_at(double pos) {
  const int i = (int)floor(pos);
  const double t = pos - i;
  if (t < 1e-9) {
    return i >= 0 && i < 640 ? aac_sbr_qmf_window[i] : 0.0;
  }
  double c[4];
  for (int j = 0; j < 4; j++) {
    const int idx = i - 1 + j;
    c[j] = idx >= 0 && idx < 640 ? aac_sbr_qmf_window[idx] : 0.0;
  }
  return c[0] * (-t * (t - 1.0) * (t - 2.0) / 6.0) + c[1] * ((t + 1.0) * (t - 1.0) * (t - 2.0) / 2.0) +
         c[2] * (-(t + 1.0) * t * (t - 2.0) / 2.0) + c[3] * ((t + 1.0) * t * (t - 1.0) / 6.0);
}

void build_small_bank(int B, float* window, float* cos_tab, float* sin_tab) {
  for (int i = 0; i < 10 * B; i++) {
    window[i] = (float)prototype_at(64.0 * i / B);
  }
  for (int k = 0; k < B; k++) {
    for (int n = 0; n < 2 * B; n++) {
      const double a = M_PI / (2.0 * B) * (k + 0.5) * (2.0 * n - B / 64.0);
      cos_tab[k * 2 * B + n] = (float)(64.0 / B * cos(a));
      sin_tab[k * 2 * B + n] = (float)(64.0 / B * sin(a));
    }
  }
}

void build_tables(void) {
  build_small_bank(16, g_window16, &g_ana_cos16[0][0], &g_ana_sin16[0][0]);
  build_small_bank(24, g_window24, &g_ana_cos24[0][0], &g_ana_sin24[0][0]);
  for (int k = 0; k < 64; k++) {
    for (int n = 0; n < 128; n++) {
      const double a = M_PI / 128.0 * (k + 0.5) * (2.0 * n - 1.0);
      g_ana_cos64[k][n] = (float)cos(a);
      g_ana_sin64[k][n] = (float)sin(a);
    }
  }
  for (int n = 0; n < 64; n++) {
    for (int k = 0; k < 32; k++) {
      const double a = M_PI / 64.0 * (k + 0.5) * (2.0 * n - 127.5);
      g_syn_cos32[n][k] = (float)(cos(a) / 64.0);
      g_syn_sin32[n][k] = (float)(sin(a) / 64.0);
    }
  }
  for (int i = 0; i < 320; i++) {
    g_window32[i] = aac_sbr_qmf_window[2 * i];
  }
  aac_fft_init(&g_fft64, 64);
  aac_fft_init(&g_fft128, 128);
  for (int n = 0; n < 64; n++) {
    const double a = M_PI / 64.0 * (n - 0.25);
    g_ana32_pre_re[n] = (float)cos(a);
    g_ana32_pre_im[n] = (float)sin(a);
  }
  for (int k = 0; k < 32; k++) {
    const double a = -M_PI / 64.0 * k / 2.0;
    g_ana32_post_re[k] = (float)(2.0 * cos(a));
    g_ana32_post_im[k] = (float)(2.0 * sin(a));
  }
  for (int k = 0; k < 64; k++) {
    const double a = -M_PI / 128.0 * 255.0 * k;
    g_syn64_pre_re[k] = (float)cos(a);
    g_syn64_pre_im[k] = (float)sin(a);
  }
  for (int n = 0; n < 128; n++) {
    const double a = M_PI / 128.0 * (n - 127.5);
    g_syn64_post_re[n] = (float)(cos(a) / 64.0);
    g_syn64_post_im[n] = (float)(sin(a) / 64.0);
  }
}

}  // namespace

void aac_qmf_tables_init(void) {
  static std::once_flag once;
  std::call_once(once, build_tables);
}

void aac_qmf_analysis_init(AacQmfAnalysis* a, int bands) {
  aac_qmf_tables_init();
  a->bands = bands;
  memset(a->x, 0, sizeof(a->x));
}

void aac_qmf_analyze(AacQmfAnalysis* a, const float* in, float (*W)[2]) {
  const int B = a->bands;
  const int len = 10 * B;
  const int step = 640 / len; /* prototype taps per window tap */
  float* x = a->x;
  /* Newest sample first: shift the history up and insert the block
   * reversed. */
  memmove(x + B, x, (size_t)(len - B) * sizeof(float));
  for (int n = 0; n < B; n++) {
    x[n] = in[B - 1 - n];
  }
  if (B == 16 || B == 24) {
    const float* window = B == 16 ? g_window16 : g_window24;
    const float* cos_tab = B == 16 ? &g_ana_cos16[0][0] : &g_ana_cos24[0][0];
    const float* sin_tab = B == 16 ? &g_ana_sin16[0][0] : &g_ana_sin24[0][0];
    float u[48];
    for (int n = 0; n < 2 * B; n++) {
      float sum = 0.0f;
      for (int j = 0; j < 5; j++) {
        const int idx = n + 2 * B * j;
        sum += x[idx] * window[idx];
      }
      u[n] = sum;
    }
    for (int k = 0; k < B; k++) {
      const float* c = cos_tab + k * 2 * B;
      const float* sn = sin_tab + k * 2 * B;
      float re = 0.0f;
      float im = 0.0f;
      for (int n = 0; n < 2 * B; n++) {
        re += u[n] * c[n];
        im += u[n] * sn[n];
      }
      W[k][0] = re;
      W[k][1] = im;
    }
    return;
  }
  const float* window = step == 2 ? g_window32 : aac_sbr_qmf_window;
  float u[128];
  for (int n = 0; n < 2 * B; n++) {
    float sum = 0.0f;
    for (int j = 0; j < 5; j++) {
      const int idx = n + 2 * B * j;
      sum += x[idx] * window[idx];
    }
    u[n] = sum;
  }
  if (B == 32) {
    /* The conjugate of the twiddled input through the forward transform
     * gives the conjugate of the positive-exponent sum. */
    float re[64], im[64];
    for (int n = 0; n < 64; n++) {
      re[n] = u[n] * g_ana32_pre_re[n];
      im[n] = -(u[n] * g_ana32_pre_im[n]);
    }
    aac_fft_forward(&g_fft64, re, im);
    for (int k = 0; k < 32; k++) {
      const float sr = re[k];
      const float si = -im[k];
      W[k][0] = g_ana32_post_re[k] * sr - g_ana32_post_im[k] * si;
      W[k][1] = g_ana32_post_re[k] * si + g_ana32_post_im[k] * sr;
    }
  } else {
    for (int k = 0; k < 64; k++) {
      float re = 0.0f;
      float im = 0.0f;
      const float* c = g_ana_cos64[k];
      const float* s = g_ana_sin64[k];
      for (int n = 0; n < 128; n++) {
        re += u[n] * c[n];
        im += u[n] * s[n];
      }
      W[k][0] = re;
      W[k][1] = im;
    }
  }
}

void aac_qmf_synthesis_init(AacQmfSynthesis* s, int bands) {
  aac_qmf_tables_init();
  s->bands = bands;
  memset(s->v, 0, sizeof(s->v));
  s->off = 18 * bands;
}

void aac_qmf_synthesize(AacQmfSynthesis* s, const float (*X)[2], float* out) {
  const int B = s->bands;
  const int block = 2 * B;
  const int history = 18 * B; /* nine older blocks */
  const int size = 2 * history;
  const int step = 640 / (10 * B);
  /* The newest block goes below the older ones; when the space in front
   * runs out, the nine blocks at the start move to the end. */
  if (s->off < block) {
    memcpy(s->v + size - history, s->v, (size_t)history * sizeof(float));
    s->off = size - history - block;
  } else {
    s->off -= block;
  }
  float* v = s->v + s->off;
  if (B == 64) {
    /* Twiddle the 64 bands, zero-pad to 128 points, transform the
     * conjugate and take the real part of the twiddled conjugate. */
    float re[128], im[128];
    for (int k = 0; k < 64; k++) {
      const float yr = X[k][0] * g_syn64_pre_re[k] - X[k][1] * g_syn64_pre_im[k];
      const float yi = X[k][0] * g_syn64_pre_im[k] + X[k][1] * g_syn64_pre_re[k];
      re[k] = yr;
      im[k] = -yi;
    }
    memset(re + 64, 0, 64 * sizeof(float));
    memset(im + 64, 0, 64 * sizeof(float));
    aac_fft_forward(&g_fft128, re, im);
    for (int n = 0; n < 128; n++) {
      v[n] = g_syn64_post_re[n] * re[n] - g_syn64_post_im[n] * (-im[n]);
    }
  } else {
    for (int n = 0; n < 64; n++) {
      const float* c = g_syn_cos32[n];
      const float* sn = g_syn_sin32[n];
      float acc = 0.0f;
      for (int k = 0; k < 32; k++) {
        acc += X[k][0] * c[k] - X[k][1] * sn[k];
      }
      v[n] = acc;
    }
  }
  /* g takes the first half of even blocks and the second half of odd
   * ones; w = g * c; the output sums the ten windowed segments. */
  const float* window = step == 2 ? g_window32 : aac_sbr_qmf_window;
  for (int k = 0; k < B; k++) {
    float acc = 0.0f;
    for (int j = 0; j < 5; j++) {
      const int g0 = 2 * block * j + k;         /* g(2 B (2 j) + k) */
      const int g1 = 2 * block * j + 3 * B + k; /* g(2 B (2 j + 1) + k) */
      acc += v[g0] * window[2 * j * B + k] + v[g1] * window[(2 * j + 1) * B + k];
    }
    out[k] = acc;
  }
}
