/*
 * ARM NEON SIMD implementations for AAC codec hot-path DSP operations.
 *
 * Overrides DSP function pointers when NEON is detected at runtime.
 * Processes 4 floats per cycle (128-bit Q registers).
 * NEON is mandatory on AArch64, optional on ARMv7.
 *
 * Vector ops: 4-wide load/multiply/store with scalar tail.
 */
#include "aac_dsp.h"

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

/* The four floats of v in reverse order. */
static inline float32x4_t reverse4(float32x4_t v) {
  const float32x4_t swapped = vcombine_f32(vget_high_f32(v), vget_low_f32(v));
  return vrev64q_f32(swapped);
}

static void aac_vector_fmul_reverse_neon(float* dst, const float* a, const float* b, int len) {
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    float32x4_t va = vld1q_f32(a + i);
    float32x4_t vb = reverse4(vld1q_f32(b + len - 4 - i));
    vst1q_f32(dst + i, vmulq_f32(va, vb));
  }
  for (; i < len; i++) dst[i] = a[i] * b[len - 1 - i];
}

/* ── Transform kernels ───────────────────────────────────────── */

/* Separate products and sums, as the scalar kernel; this file is compiled
 * without floating-point contraction so that the compiler does not fuse
 * them.  The MDCT twiddles keep the scalar kernels on NEON. */
static void aac_fft_radix2_stage_neon(float* re, float* im, int n, int half, const float* wr,
                                      const float* wi) {
  if (half < 4) {
    for (int start = 0; start < n; start += 2 * half) {
      for (int j = 0; j < half; j++) {
        const int a = start + j;
        const int b = a + half;
        const float tr = re[b] * wr[j] - im[b] * wi[j];
        const float ti = re[b] * wi[j] + im[b] * wr[j];
        re[b] = re[a] - tr;
        im[b] = im[a] - ti;
        re[a] += tr;
        im[a] += ti;
      }
    }
    return;
  }
  for (int start = 0; start < n; start += 2 * half) {
    float* ra = re + start;
    float* ia = im + start;
    float* rb = ra + half;
    float* ib = ia + half;
    for (int j = 0; j < half; j += 4) {
      const float32x4_t vwr = vld1q_f32(wr + j);
      const float32x4_t vwi = vld1q_f32(wi + j);
      const float32x4_t xr = vld1q_f32(rb + j);
      const float32x4_t xi = vld1q_f32(ib + j);
      const float32x4_t tr = vsubq_f32(vmulq_f32(xr, vwr), vmulq_f32(xi, vwi));
      const float32x4_t ti = vaddq_f32(vmulq_f32(xr, vwi), vmulq_f32(xi, vwr));
      const float32x4_t ar = vld1q_f32(ra + j);
      const float32x4_t ai = vld1q_f32(ia + j);
      vst1q_f32(rb + j, vsubq_f32(ar, tr));
      vst1q_f32(ib + j, vsubq_f32(ai, ti));
      vst1q_f32(ra + j, vaddq_f32(ar, tr));
      vst1q_f32(ia + j, vaddq_f32(ai, ti));
    }
  }
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

void aac_dsp_init_neon(AacDSP* dsp) {
  dsp->vector_fmul = aac_vector_fmul_neon;
  dsp->vector_fmul_scalar = aac_vector_fmul_scalar_neon;
  dsp->vector_fmul_add = aac_vector_fmul_add_neon;
  dsp->vector_fmul_window = aac_vector_fmul_window_neon;
  dsp->vector_fmul_reverse = aac_vector_fmul_reverse_neon;
  dsp->vector_fmul_accumulate = aac_vector_fmul_accumulate_neon;
  dsp->fft_radix2_stage = aac_fft_radix2_stage_neon;
}

#endif /* BAAC_AAC_NEON || __ARM_NEON || __aarch64__ */
