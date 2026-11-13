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

#if defined(BAC_AAC_NEON) || defined(__ARM_NEON) || defined(__aarch64__)

#include <arm_neon.h>

#include <cmath>
#include <cstddef>
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
    aac_fft_radix2_stage_c(re, im, n, half, wr, wi);
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

/* ── Encoder kernels ─────────────────────────────────────────── */

namespace {

struct QuantNoiseArgs {
  float32x4_t scale, round, gain;
  const float* pow43;
};

/* d^2 of four lines (see the scalar kernel).  The clamp is written as
 * compare and select, which is what the scalar kernel's v < top ? v : top
 * means for a NaN (top); vminq_f32 would return the NaN.  Nothing here
 * may become a fused multiply-add: the file is compiled without
 * contraction and uses no vmla/vfma. */
inline float32x4_t quant_noise4(float32x4_t coef, float32x4_t x34, const QuantNoiseArgs& a) {
  const float32x4_t top = vdupq_n_f32(8191.0f);
  const float32x4_t zero = vdupq_n_f32(0.0f);
  float32x4_t v = vaddq_f32(vmulq_f32(x34, a.scale), a.round);
  v = vbslq_f32(vcltq_f32(v, top), v, top);
  v = vbslq_f32(vcgtq_f32(v, zero), v, zero);
  const int32x4_t q = vcvtq_s32_f32(v); /* truncates, as (int) does */
  float32x4_t p = zero;
  p = vld1q_lane_f32(a.pow43 + vgetq_lane_s32(q, 0), p, 0);
  p = vld1q_lane_f32(a.pow43 + vgetq_lane_s32(q, 1), p, 1);
  p = vld1q_lane_f32(a.pow43 + vgetq_lane_s32(q, 2), p, 2);
  p = vld1q_lane_f32(a.pow43 + vgetq_lane_s32(q, 3), p, 3);
  const float32x4_t d = vsubq_f32(vabsq_f32(coef), vmulq_f32(p, a.gain));
  return vmulq_f32(d, d);
}

/* d^2 of the last n < 4 lines of a span in lanes 0..n-1, +0 in the
 * others, which leaves an accumulator unchanged (its lanes are never
 * -0). */
inline float32x4_t quant_noise_part(const float* coef, const float* x34, int n,
                                    const QuantNoiseArgs& a) {
  float c[4] = {0.0f, 0.0f, 0.0f, 0.0f};
  float x[4] = {0.0f, 0.0f, 0.0f, 0.0f};
  for (int k = 0; k < n; k++) {
    c[k] = coef[k];
    x[k] = x34[k];
  }
  const uint32_t lane_index[4] = {0, 1, 2, 3};
  const uint32x4_t live = vcltq_u32(vld1q_u32(lane_index), vdupq_n_u32((uint32_t)n));
  const float32x4_t sq = quant_noise4(vld1q_f32(c), vld1q_f32(x), a);
  return vreinterpretq_f32_u32(vandq_u32(vreinterpretq_u32_f32(sq), live));
}

}  // namespace

/* Lanes 0..3 of the scalar kernel's accumulators in lo, 4..7 in hi; the
 * same steps as the SSE2 kernel. */
static float aac_quant_noise_neon(const float* coef, const float* x34, int len, int windows,
                                  int stride, float scale, float round, float gain,
                                  const float* pow43) {
  const QuantNoiseArgs a{vdupq_n_f32(scale), vdupq_n_f32(round), vdupq_n_f32(gain), pow43};
  float32x4_t lo = vdupq_n_f32(0.0f);
  float32x4_t hi = vdupq_n_f32(0.0f);
  for (int w = 0; w < windows; w++) {
    const float* cw = coef + (ptrdiff_t)w * stride;
    const float* xw = x34 + (ptrdiff_t)w * stride;
    int i = 0;
    for (; i + 8 <= len; i += 8) {
      lo = vaddq_f32(lo, quant_noise4(vld1q_f32(cw + i), vld1q_f32(xw + i), a));
      hi = vaddq_f32(hi, quant_noise4(vld1q_f32(cw + i + 4), vld1q_f32(xw + i + 4), a));
    }
    if (i + 4 <= len) {
      lo = vaddq_f32(lo, quant_noise4(vld1q_f32(cw + i), vld1q_f32(xw + i), a));
      i += 4;
    }
    if (i < len) {
      const float32x4_t part = quant_noise_part(cw + i, xw + i, len - i, a);
      if (i & 4) {
        hi = vaddq_f32(hi, part);
      } else {
        lo = vaddq_f32(lo, part);
      }
    }
  }
  /* ((a0 + a4) + (a2 + a6)) + ((a1 + a5) + (a3 + a7)) */
  const float32x4_t s = vaddq_f32(lo, hi);
  const float32x2_t t = vadd_f32(vget_low_f32(s), vget_high_f32(s));
  return vget_lane_f32(t, 0) + vget_lane_f32(t, 1);
}

void aac_dsp_init_neon(AacDSP* dsp) {
  dsp->vector_fmul = aac_vector_fmul_neon;
  dsp->vector_fmul_scalar = aac_vector_fmul_scalar_neon;
  dsp->vector_fmul_add = aac_vector_fmul_add_neon;
  dsp->vector_fmul_window = aac_vector_fmul_window_neon;
  dsp->vector_fmul_reverse = aac_vector_fmul_reverse_neon;
  dsp->vector_fmul_accumulate = aac_vector_fmul_accumulate_neon;
  dsp->fft_radix2_stage = aac_fft_radix2_stage_neon;
  dsp->quant_noise = aac_quant_noise_neon;
}

#endif /* BAC_AAC_NEON || __ARM_NEON || __aarch64__ */
