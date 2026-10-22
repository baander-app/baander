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

void aac_dsp_init_neon(AacDSP* dsp) {
  dsp->vector_fmul = aac_vector_fmul_neon;
  dsp->vector_fmul_scalar = aac_vector_fmul_scalar_neon;
  dsp->vector_fmul_add = aac_vector_fmul_add_neon;
  dsp->vector_fmul_window = aac_vector_fmul_window_neon;
  dsp->vector_fmul_reverse = aac_vector_fmul_reverse_neon;
  dsp->vector_fmul_accumulate = aac_vector_fmul_accumulate_neon;
}

#endif /* BAAC_AAC_NEON || __ARM_NEON || __aarch64__ */
