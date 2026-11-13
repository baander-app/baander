/* NEON kernels of the FLAC codec (AArch64).  Each computes exactly what
 * its scalar counterpart in flac_dsp.cpp does: the integer ones by
 * arithmetic that wraps the same way, the floating-point ones by the
 * same products and the same sums in the same order, without a fused
 * multiply-add. */
#include "../flac_internal.h"

#if defined(BAC_AAC_NEON) && defined(__aarch64__)

#include <arm_neon.h>

namespace {

/* ── analysis ───────────────────────────────────────────────────── */

void window_neon(const double* x, const double* w, int n, double* y) {
  int i = 0;
  for (; i + 2 <= n; i += 2) {
    vst1q_f64(y + i, vmulq_f64(vld1q_f64(x + i), vld1q_f64(w + i)));
  }
  for (; i < n; i++) {
    y[i] = x[i] * w[i];
  }
}

/* Two lags to a vector: lane j of group g is lag 2g + 1 - j.  Per lag
 * one sum over the even i and one over the odd i, as the kernel is
 * defined; the first samples, which do not have every lag behind them,
 * go into the same sums one by one.  The product and the sum are two
 * operations: a fused multiply-add would round once where the scalar
 * kernel rounds twice. */
template <int GROUPS>
void autocorrelation_groups(const double* y, int n, int max_lag, double* r) {
  const int lags = 2 * GROUPS;
  double sums[2][2 * GROUPS];
  for (int lag = 0; lag < lags; lag++) {
    sums[0][lag] = 0.0;
    sums[1][lag] = 0.0;
  }
  int start = lags - 1 + ((lags - 1) & 1);
  if (start > n) {
    start = n;
  }
  for (int i = 0; i < start; i++) {
    double* sum = sums[i & 1];
    const double v = y[i];
    const int top = i < lags - 1 ? i : lags - 1;
    for (int lag = 0; lag <= top; lag++) {
      sum[lag] += v * y[i - lag];
    }
  }
  float64x2_t even[GROUPS];
  float64x2_t odd[GROUPS];
  for (int g = 0; g < GROUPS; g++) {
    const double e[2] = {sums[0][2 * g + 1], sums[0][2 * g]};
    const double o[2] = {sums[1][2 * g + 1], sums[1][2 * g]};
    even[g] = vld1q_f64(e);
    odd[g] = vld1q_f64(o);
  }
  int i = start;
  for (; i + 2 <= n; i += 2) {
    const float64x2_t a = vdupq_n_f64(y[i]);
    const float64x2_t b = vdupq_n_f64(y[i + 1]);
    for (int g = 0; g < GROUPS; g++) {
      even[g] = vaddq_f64(even[g], vmulq_f64(a, vld1q_f64(y + i - 2 * g - 1)));
      odd[g] = vaddq_f64(odd[g], vmulq_f64(b, vld1q_f64(y + i - 2 * g)));
    }
  }
  if (i < n) {
    const float64x2_t a = vdupq_n_f64(y[i]);
    for (int g = 0; g < GROUPS; g++) {
      even[g] = vaddq_f64(even[g], vmulq_f64(a, vld1q_f64(y + i - 2 * g - 1)));
    }
  }
  for (int g = 0; g < GROUPS; g++) {
    double lanes[2];
    vst1q_f64(lanes, vaddq_f64(even[g], odd[g]));
    for (int j = 0; j < 2; j++) {
      const int lag = 2 * g + 1 - j;
      if (lag <= max_lag) {
        r[lag] = lanes[j];
      }
    }
  }
}

void autocorrelation_neon(const double* y, int n, int max_lag, double* r) {
  /* The orders the encoder's levels use get loops of their own length;
   * every other one takes the longest. */
  const int groups = max_lag / 2 + 1;
  if (groups <= 4) {
    autocorrelation_groups<4>(y, n, max_lag, r);
  } else if (groups <= 5) {
    autocorrelation_groups<5>(y, n, max_lag, r);
  } else if (groups <= 7) {
    autocorrelation_groups<7>(y, n, max_lag, r);
  } else {
    autocorrelation_groups<17>(y, n, max_lag, r);
  }
}

/* ── encoder ────────────────────────────────────────────────────── */

void lpc_residual_32_neon(const int32_t* x, int n, int order, const int32_t* coefs, int shift,
                          int32_t* residual) {
  const int32x4_t count = vdupq_n_s32(-shift);
  int i = order;
  for (; i + 4 <= n; i += 4) {
    int32x4_t sum = vdupq_n_s32(0);
    for (int j = 0; j < order; j++) {
      sum = vmlaq_n_s32(sum, vld1q_s32(x + i - 1 - j), coefs[j]);
    }
    vst1q_s32(residual + i, vsubq_s32(vld1q_s32(x + i), vshlq_s32(sum, count)));
  }
  for (; i < n; i++) {
    uint32_t sum = 0;
    for (int j = 0; j < order; j++) {
      sum += (uint32_t)coefs[j] * (uint32_t)x[i - 1 - j];
    }
    residual[i] = (int32_t)((uint32_t)x[i] - (uint32_t)((int32_t)sum >> shift));
  }
}

uint64_t rice_fold_neon(const int32_t* residual, int n, uint32_t* folded, uint32_t* bits) {
  uint64x2_t sum = vdupq_n_u64(0);
  uint32x4_t all = vdupq_n_u32(0);
  int i = 0;
  for (; i + 4 <= n; i += 4) {
    const int32x4_t r = vld1q_s32(residual + i);
    const uint32x4_t u = vreinterpretq_u32_s32(veorq_s32(vshlq_n_s32(r, 1), vshrq_n_s32(r, 31)));
    vst1q_u32(folded + i, u);
    all = vorrq_u32(all, u);
    sum = vpadalq_u32(sum, u);
  }
  uint64_t total = vgetq_lane_u64(sum, 0) + vgetq_lane_u64(sum, 1);
  uint32_t any = vgetq_lane_u32(all, 0) | vgetq_lane_u32(all, 1) | vgetq_lane_u32(all, 2) |
                 vgetq_lane_u32(all, 3);
  for (; i < n; i++) {
    const uint32_t r = (uint32_t)residual[i];
    const uint32_t u = (r << 1) ^ (0u - (r >> 31));
    folded[i] = u;
    total += u;
    any |= u;
  }
  *bits = any;
  return total;
}

void rice_sums_neon(const uint32_t* folded, int n, uint32_t, int k_low, int k_count, uint64_t* sums,
                    size_t stride) {
  for (int j = 0; j < k_count; j++) {
    const int k = k_low + j;
    /* A shift to the left by a negative count is one to the right. */
    const int32x4_t count = vdupq_n_s32(-k);
    uint64x2_t sum = vdupq_n_u64(0);
    int i = 0;
    for (; i + 4 <= n; i += 4) {
      sum = vpadalq_u32(sum, vshlq_u32(vld1q_u32(folded + i), count));
    }
    uint64_t total = vgetq_lane_u64(sum, 0) + vgetq_lane_u64(sum, 1);
    for (; i < n; i++) {
      total += folded[i] >> k;
    }
    sums[(size_t)j * stride] = total;
  }
}

}  // namespace

void flac_dsp_init_neon(FlacDSP* dsp, int) {
  dsp->window = window_neon;
  dsp->autocorrelation = autocorrelation_neon;
  dsp->lpc_residual_16 = lpc_residual_32_neon;
  dsp->lpc_residual_32 = lpc_residual_32_neon;
  dsp->rice_fold = rice_fold_neon;
  dsp->rice_sums = rice_sums_neon;
}

#else

/* On 32-bit ARM, which has no vectors of doubles, the scalar kernels
 * stay. */
void flac_dsp_init_neon(FlacDSP*, int) {}

#endif
