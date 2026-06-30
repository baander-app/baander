/*
 * ARM NEON SIMD implementations for AAC codec hot-path DSP operations.
 *
 * Overrides DSP function pointers when NEON is detected at runtime.
 * Processes 4 floats per cycle (128-bit Q registers).
 * NEON is mandatory on AArch64, optional on ARMv7.
 *
 * FFT: precomputed twiddles + 4-wide butterfly for stages s >= 4.
 * Vector ops: 4-wide load/multiply/store with scalar tail.
 */
#include "aac_dsp.h"
#include "fft.h"
#include "mdct.h"

#if defined(BAAC_AAC_NEON) || defined(__ARM_NEON) || defined(__aarch64__)

#include <arm_neon.h>

#include <cmath>
#include <cstring>

/* ── Vector Operations ───────────────────────────────────────── */

static void aac_vector_fmul_neon(float* dst, const float* a, const float* b, int len) {
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    float32x4_t va = vld1q_f32(a + i);
    float32x4_t vb = vld1q_f32(b + i);
    vst1q_f32(dst + i, vmulq_f32(va, vb));
  }
  for (; i < len; i++) dst[i] = a[i] * b[i];
}

static void aac_vector_fmul_scalar_neon(float* dst, const float* a, float scale, int len) {
  float32x4_t vs = vdupq_n_f32(scale);
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) vst1q_f32(dst + i, vmulq_f32(vld1q_f32(a + i), vs));
  for (; i < len; i++) dst[i] = a[i] * scale;
}

static void aac_vector_fmul_add_neon(float* dst, const float* a, const float* b, const float* c,
                                     int len) {
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    float32x4_t va = vld1q_f32(a + i);
    float32x4_t vb = vld1q_f32(b + i);
    float32x4_t vc = vld1q_f32(c + i);
    /* vmlaq_f32(a, b, c) = a + b*c */
    vst1q_f32(dst + i, vmlaq_f32(vc, va, vb));
  }
  for (; i < len; i++) dst[i] = a[i] * b[i] + c[i];
}

static void aac_vector_fmul_window_neon(float* dst, const float* a, const float* b,
                                        const float* win, int n) {
  int i = 0, n4 = n & ~3;
  for (; i < n4; i += 4) {
    float32x4_t va = vld1q_f32(a + i);
    float32x4_t vw = vld1q_f32(win + i);
    /* Build reversed window: win[n-1-i], win[n-2-i], win[n-3-i], win[n-4-i] */
    float wr[4] = {win[n - 1 - i], win[n - 2 - i], win[n - 3 - i], win[n - 4 - i]};
    float32x4_t vwr = vld1q_f32(wr);
    float32x4_t vb = vld1q_f32(b + i);
    float32x4_t prod_a = vmulq_f32(va, vw);
    /* NEON vmlaq: prod_a + vb * vwr */
    vst1q_f32(dst + i, vmlaq_f32(prod_a, vb, vwr));
  }
  for (; i < n; i++) dst[i] = a[i] * win[i] + b[i] * win[n - 1 - i];
}

static void aac_vector_fmul_reverse_neon(float* dst, const float* a, const float* b, int len) {
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    float32x4_t va = vld1q_f32(a + i);
    float rb[4] = {b[len - 1 - i], b[len - 2 - i], b[len - 3 - i], b[len - 4 - i]};
    float32x4_t vb = vld1q_f32(rb);
    vst1q_f32(dst + i, vmulq_f32(va, vb));
  }
  for (; i < len; i++) dst[i] = a[i] * b[len - 1 - i];
}

static void aac_vector_fmul_accumulate_neon(float* dst, const float* a, const float* b, int len) {
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    float32x4_t vd = vld1q_f32(dst + i);
    float32x4_t va = vld1q_f32(a + i);
    float32x4_t vb = vld1q_f32(b + i);
    vst1q_f32(dst + i, vmlaq_f32(vd, va, vb));
  }
  for (; i < len; i++) dst[i] += a[i] * b[i];
}

/* ── FFT ─────────────────────────────────────────────────────── */

static void neon_bit_reverse(float* re, float* im, int n) {
  int j = 0;
  for (int i = 0; i < n - 1; i++) {
    if (i < j) {
      float tr = re[i];
      re[i] = re[j];
      re[j] = tr;
      float ti = im[i];
      im[i] = im[j];
      im[j] = ti;
    }
    int k = n >> 1;
    while (k <= j) {
      j -= k;
      k >>= 1;
    }
    j += k;
  }
}

static void aac_fft_forward_neon(float* re, float* im, int n) {
  neon_bit_reverse(re, im, n);

  static thread_local float tw_re_buf[2048];
  static thread_local float tw_im_buf[2048];

  for (int s = 1; s < n; s <<= 1) {
    int m = s << 1;
    float wn_re = cosf((float)M_PI / (float)s);
    float wn_im = -sinf((float)M_PI / (float)s);

    /* Precompute twiddles iteratively */
    {
      float w_re = 1.0f, w_im = 0.0f;
      for (int j = 0; j < s; j++) {
        tw_re_buf[j] = w_re;
        tw_im_buf[j] = w_im;
        float nw_re = w_re * wn_re - w_im * wn_im;
        float nw_im = w_re * wn_im + w_im * wn_re;
        w_re = nw_re;
        w_im = nw_im;
      }
    }

    for (int k = 0; k < n; k += m) {
      int j = 0;
      /* 4-wide butterfly with NEON */
      for (; j <= s - 4; j += 4) {
        float32x4_t r_up = vld1q_f32(&re[k + j]);
        float32x4_t i_up = vld1q_f32(&im[k + j]);
        float32x4_t r_lo = vld1q_f32(&re[k + j + s]);
        float32x4_t i_lo = vld1q_f32(&im[k + j + s]);
        float32x4_t tw_r = vld1q_f32(&tw_re_buf[j]);
        float32x4_t tw_i = vld1q_f32(&tw_im_buf[j]);

        /* t_re = tw_r * r_lo - tw_i * i_lo */
        float32x4_t t_re = vmlsq_f32(vmulq_f32(tw_r, r_lo), tw_i, i_lo);
        /* t_im = tw_r * i_lo + tw_i * r_lo */
        float32x4_t t_im = vmlaq_f32(vmulq_f32(tw_r, i_lo), tw_i, r_lo);

        vst1q_f32(&re[k + j + s], vsubq_f32(r_up, t_re));
        vst1q_f32(&im[k + j + s], vsubq_f32(i_up, t_im));
        vst1q_f32(&re[k + j], vaddq_f32(r_up, t_re));
        vst1q_f32(&im[k + j], vaddq_f32(i_up, t_im));
      }
      /* Scalar tail */
      for (; j < s; j++) {
        float wr = tw_re_buf[j], wi = tw_im_buf[j];
        float t_re = wr * re[k + j + s] - wi * im[k + j + s];
        float t_im = wr * im[k + j + s] + wi * re[k + j + s];
        re[k + j + s] = re[k + j] - t_re;
        im[k + j + s] = im[k + j] - t_im;
        re[k + j] += t_re;
        im[k + j] += t_im;
      }
    }
  }
}

static void aac_fft_inverse_neon(float* re, float* im, int n) {
  /* Negate imaginary part */
  float32x4_t sign_mask = vdupq_n_f32(-0.0f);
  int i = 0, n4 = n & ~3;
  for (; i < n4; i += 4) {
    float32x4_t vi = vld1q_f32(&im[i]);
    /* XOR with sign bit to negate */
    vst1q_f32(&im[i], vreinterpretq_f32_u32(
                          veorq_u32(vreinterpretq_u32_f32(vi), vreinterpretq_u32_f32(sign_mask))));
  }
  for (; i < n; i++) im[i] = -im[i];

  aac_fft_forward_neon(re, im, n);

  /* Scale and negate imaginary */
  float32x4_t vinv = vdupq_n_f32(1.0f / (float)n);
  i = 0;
  for (; i < n4; i += 4) {
    vst1q_f32(&re[i], vmulq_f32(vld1q_f32(&re[i]), vinv));
    float32x4_t mi = vmulq_f32(vld1q_f32(&im[i]), vinv);
    vst1q_f32(&im[i], vreinterpretq_f32_u32(
                          veorq_u32(vreinterpretq_u32_f32(mi), vreinterpretq_u32_f32(sign_mask))));
  }
  for (; i < n; i++) {
    re[i] /= (float)n;
    im[i] = -im[i] / (float)n;
  }
}

/* ── MDCT Forward (calls NEON FFT) ─────────────────────────────
 * Rotation strategy (4-wide NEON):
 *   - Twiddle precompute + vectorized complex multiply using vmlaq_f32 / vmlsq_f32.
 *   - Post-rotation uses vzipq_f32 for interleaving.
 *   - Folding kept scalar.
 * Numerical expectations: see AVX2 comment (identical tolerance model).
 */

static void aac_mdct_forward_neon(float* out, const float* in, int n, const float* win) {
  int n2 = n >> 1, n4 = n >> 2;
  static thread_local float s_re[512], s_im[512];
  static thread_local float mdct_tw_re[256], mdct_tw_im[256];
  float* re = s_re;
  float* im = s_im;
  memset(re, 0, n4 * sizeof(float));
  memset(im, 0, n4 * sizeof(float));

  /* Precompute MDCT twiddles once */
  for (int k = 0; k < n4; k++) {
    float ang = (float)M_PI * (k + 0.125f) / (float)n4;
    mdct_tw_re[k] = cosf(ang);
    mdct_tw_im[k] = sinf(ang);
  }

  /* Pre-rotation: folding scalar; complex mul vectorized with vmlaq */
  for (int k = 0; k < n4; k++) {
    float a = win[n2 - n4 + k] * in[n2 - n4 + k] + win[n4 - 1 - k] * in[n4 - 1 - k];
    float b = win[n2 + k] * in[n2 + k] - win[n - 1 - k] * in[n - 1 - k];
    re[k] = a;
    im[k] = b;
  }

  int k = 0;
  for (; k <= n4 - 4; k += 4) {
    float32x4_t va = vld1q_f32(&re[k]);
    float32x4_t vb = vld1q_f32(&im[k]);
    float32x4_t tw_r = vld1q_f32(&mdct_tw_re[k]);
    float32x4_t tw_i = vld1q_f32(&mdct_tw_im[k]);

    float32x4_t r_out = vmlaq_f32(vmulq_f32(vb, tw_i), va, tw_r);
    float32x4_t i_out = vmlsq_f32(vmulq_f32(vb, tw_r), va, tw_i);
    vst1q_f32(&re[k], r_out);
    vst1q_f32(&im[k], i_out);
  }
  for (; k < n4; k++) {
    float tw_r = mdct_tw_re[k], tw_i = mdct_tw_im[k];
    float a = re[k], b = im[k];
    re[k] = a * tw_r + b * tw_i;
    im[k] = b * tw_r - a * tw_i;
  }

  aac_fft_forward_neon(re, im, n4);

  /* Post-rotation + interleave (4-wide with vzip) */
  k = 0;
  for (; k <= n4 - 4; k += 4) {
    float32x4_t vr = vld1q_f32(&re[k]);
    float32x4_t vi = vld1q_f32(&im[k]);
    float32x4_t tw_r = vld1q_f32(&mdct_tw_re[k]);
    float32x4_t tw_i = vld1q_f32(&mdct_tw_im[k]);

    float32x4_t o0 = vmlaq_f32(vmulq_f32(vi, tw_i), vr, tw_r);
    float32x4_t o1 = vmlsq_f32(vmulq_f32(vr, tw_i), vi, tw_r);

    float32x4x2_t zipped = vzipq_f32(o0, o1);
    vst1q_f32(&out[2 * k], zipped.val[0]);
    vst1q_f32(&out[2 * (k + 2)], zipped.val[1]);
  }
  for (; k < n4; k++) {
    float tw_r = mdct_tw_re[k], tw_i = mdct_tw_im[k];
    out[2 * k] = re[k] * tw_r + im[k] * tw_i;
    out[2 * k + 1] = im[k] * tw_r - re[k] * tw_i;
  }

  float32x4_t vscale = vdupq_n_f32(2.0f / (float)n);
  k = 0;
  int nn4 = n2 & ~3;
  for (; k < nn4; k += 4) vst1q_f32(&out[k], vmulq_f32(vld1q_f32(&out[k]), vscale));
  for (; k < n2; k++) out[k] *= 2.0f / (float)n;
}

/* ── IMDCT Half (calls NEON FFT) ───────────────────────────────
 * Same twiddle precompute strategy. Pre-rotation arithmetic is vector-ready
 * (currently scalar loads for robustness). Post-rotation uses the precomputed
 * twiddles. See numerical note in the forward function.
 */

static void aac_imdct_half_neon(float* out, const float* in, int n, const float* win) {
  int n2 = n >> 1, n4 = n >> 2;
  static thread_local float s_re[512], s_im[512];
  static thread_local float mdct_tw_re[256], mdct_tw_im[256];
  float* re = s_re;
  float* im = s_im;
  memset(re, 0, n4 * sizeof(float));
  memset(im, 0, n4 * sizeof(float));

  /* Precompute MDCT twiddles once */
  for (int k = 0; k < n4; k++) {
    float ang = (float)M_PI * (k + 0.125f) / (float)n4;
    mdct_tw_re[k] = cosf(ang);
    mdct_tw_im[k] = sinf(ang);
  }

  /* Pre-rotation (twiddle precompute eliminates repeated trig; arithmetic ready for vmlaq) */
  for (int k = 0; k < n4; k++) {
    float tw_r = mdct_tw_re[k], tw_i = mdct_tw_im[k];
    float rin = in[2 * k], iin = in[2 * k + 1];
    re[k] = rin * tw_r + iin * tw_i;
    im[k] = iin * tw_r - rin * tw_i;
  }

  aac_fft_inverse_neon(re, im, n4);

  /* Post-rotation (twiddles + window) */
  for (k = 0; k < n4; k++) {
    float tw_r = mdct_tw_re[k], tw_i = mdct_tw_im[k];
    float a = re[k] * tw_r + im[k] * tw_i;
    float b = re[k] * tw_i - im[k] * tw_r;
    out[k] = a * win[k];
    out[n4 + k] = b * win[n4 + k];
    out[n2 + k] = -b * win[n2 + k];
    out[n2 + n4 + k] = -a * win[n2 + n4 + k];
  }
}

/* ── Registration ────────────────────────────────────────────── */

void aac_dsp_init_neon(AacDSP* dsp) {
  dsp->fft_forward = aac_fft_forward_neon;
  dsp->fft_inverse = aac_fft_inverse_neon;
  dsp->mdct_forward = aac_mdct_forward_neon;
  dsp->imdct_half = aac_imdct_half_neon;
  dsp->vector_fmul = aac_vector_fmul_neon;
  dsp->vector_fmul_scalar = aac_vector_fmul_scalar_neon;
  dsp->vector_fmul_add = aac_vector_fmul_add_neon;
  dsp->vector_fmul_window = aac_vector_fmul_window_neon;
  dsp->vector_fmul_reverse = aac_vector_fmul_reverse_neon;
  dsp->vector_fmul_accumulate = aac_vector_fmul_accumulate_neon;
}

#endif /* BAAC_AAC_NEON || __ARM_NEON || __aarch64__ */
