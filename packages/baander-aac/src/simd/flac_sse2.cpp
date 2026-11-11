/* SSE2 kernels of the FLAC codec.  Each computes exactly what its scalar
 * counterpart in flac_dsp.cpp does: the integer ones by arithmetic that
 * wraps the same way, the floating-point ones by the same products and
 * the same sums in the same order. */
#include "../flac_internal.h"

#if defined(BAAC_AAC_SSE2) || defined(__SSE2__)

#include <emmintrin.h>

namespace {

/* ── analysis ───────────────────────────────────────────────────── */

void window_sse2(const double* x, const double* w, int n, double* y) {
  int i = 0;
  for (; i + 2 <= n; i += 2) {
    _mm_storeu_pd(y + i, _mm_mul_pd(_mm_loadu_pd(x + i), _mm_loadu_pd(w + i)));
  }
  for (; i < n; i++) {
    y[i] = x[i] * w[i];
  }
}

/* Two lags to a vector: lane j of group g is lag 2g + 1 - j.  Per lag
 * one sum over the even i and one over the odd i, as the kernel is
 * defined; the first samples, which do not have every lag behind them,
 * go into the same sums one by one. */
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
  __m128d even[GROUPS];
  __m128d odd[GROUPS];
  for (int g = 0; g < GROUPS; g++) {
    even[g] = _mm_setr_pd(sums[0][2 * g + 1], sums[0][2 * g]);
    odd[g] = _mm_setr_pd(sums[1][2 * g + 1], sums[1][2 * g]);
  }
  int i = start;
  for (; i + 2 <= n; i += 2) {
    const __m128d a = _mm_set1_pd(y[i]);
    const __m128d b = _mm_set1_pd(y[i + 1]);
    for (int g = 0; g < GROUPS; g++) {
      even[g] = _mm_add_pd(even[g], _mm_mul_pd(a, _mm_loadu_pd(y + i - 2 * g - 1)));
      odd[g] = _mm_add_pd(odd[g], _mm_mul_pd(b, _mm_loadu_pd(y + i - 2 * g)));
    }
  }
  if (i < n) {
    const __m128d a = _mm_set1_pd(y[i]);
    for (int g = 0; g < GROUPS; g++) {
      even[g] = _mm_add_pd(even[g], _mm_mul_pd(a, _mm_loadu_pd(y + i - 2 * g - 1)));
    }
  }
  for (int g = 0; g < GROUPS; g++) {
    double lanes[2];
    _mm_storeu_pd(lanes, _mm_add_pd(even[g], odd[g]));
    for (int j = 0; j < 2; j++) {
      const int lag = 2 * g + 1 - j;
      if (lag <= max_lag) {
        r[lag] = lanes[j];
      }
    }
  }
}

void autocorrelation_sse2(const double* y, int n, int max_lag, double* r) {
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

/* Adds the four unsigned 32-bit lanes of v to the two 64-bit lanes of a
 * sum. */
inline __m128i add_widened(__m128i sum, __m128i v) {
  const __m128i zero = _mm_setzero_si128();
  sum = _mm_add_epi64(sum, _mm_unpacklo_epi32(v, zero));
  return _mm_add_epi64(sum, _mm_unpackhi_epi32(v, zero));
}

inline uint64_t sum_lanes(__m128i sum) {
  uint64_t lanes[2];
  _mm_storeu_si128((__m128i*)lanes, sum);
  return lanes[0] + lanes[1];
}

inline __m128i magnitude(__m128i v) {
  const __m128i sign = _mm_srai_epi32(v, 31);
  return _mm_sub_epi32(_mm_xor_si128(v, sign), sign);
}

void fixed_sums_sse2(const int32_t* x, int n, int max_order, uint64_t* sums) {
  for (int k = 0; k <= FLAC_MAX_FIXED_ORDER; k++) {
    sums[k] = 0;
  }
  __m128i total[FLAC_MAX_FIXED_ORDER + 1];
  for (int k = 0; k <= FLAC_MAX_FIXED_ORDER; k++) {
    total[k] = _mm_setzero_si128();
  }
  int i = max_order;
  if (max_order == FLAC_MAX_FIXED_ORDER) {
    for (; i + 4 <= n; i += 4) {
      /* The differences of each order from the samples and the four
       * before them: each order is the difference of the one below. */
      const __m128i x0 = _mm_loadu_si128((const __m128i*)(x + i));
      const __m128i x1 = _mm_loadu_si128((const __m128i*)(x + i - 1));
      const __m128i x2 = _mm_loadu_si128((const __m128i*)(x + i - 2));
      const __m128i x3 = _mm_loadu_si128((const __m128i*)(x + i - 3));
      const __m128i x4 = _mm_loadu_si128((const __m128i*)(x + i - 4));
      const __m128i a0 = _mm_sub_epi32(x0, x1);
      const __m128i a1 = _mm_sub_epi32(x1, x2);
      const __m128i a2 = _mm_sub_epi32(x2, x3);
      const __m128i a3 = _mm_sub_epi32(x3, x4);
      const __m128i b0 = _mm_sub_epi32(a0, a1);
      const __m128i b1 = _mm_sub_epi32(a1, a2);
      const __m128i b2 = _mm_sub_epi32(a2, a3);
      const __m128i c0 = _mm_sub_epi32(b0, b1);
      const __m128i c1 = _mm_sub_epi32(b1, b2);
      const __m128i d0 = _mm_sub_epi32(c0, c1);
      total[0] = add_widened(total[0], magnitude(x0));
      total[1] = add_widened(total[1], magnitude(a0));
      total[2] = add_widened(total[2], magnitude(b0));
      total[3] = add_widened(total[3], magnitude(c0));
      total[4] = add_widened(total[4], magnitude(d0));
    }
    for (int k = 0; k <= FLAC_MAX_FIXED_ORDER; k++) {
      sums[k] = sum_lanes(total[k]);
    }
  }
  /* The rest, and blocks too short for order 4, sample by sample. */
  for (; i < n; i++) {
    int32_t e = x[i];
    for (int k = 0; k <= max_order; k++) {
      sums[k] += (uint64_t)(e < 0 ? -(int64_t)e : (int64_t)e);
      if (k < max_order) {
        /* The difference of order k at the sample before, from the
         * samples themselves. */
        static const int kBinomial[4][5] = {
            {1, 0, 0, 0, 0}, {1, -1, 0, 0, 0}, {1, -2, 1, 0, 0}, {1, -3, 3, -1, 0}};
        uint32_t before = 0;
        for (int j = 0; j <= k; j++) {
          before += (uint32_t)kBinomial[k][j] * (uint32_t)x[i - 1 - j];
        }
        e = (int32_t)((uint32_t)e - before);
      }
    }
  }
}

/* The samples x[base .. base + count) narrowed to 16 bits each, a zero
 * standing in for an index below the row. */
inline void narrow_samples(const int32_t* x, int base, int count, int16_t* packed) {
  int m = 0;
  if (base < 0) {
    packed[m++] = 0;
  }
  for (; m + 8 <= count; m += 8) {
    _mm_storeu_si128((__m128i*)(packed + m),
                     _mm_packs_epi32(_mm_loadu_si128((const __m128i*)(x + base + m)),
                                     _mm_loadu_si128((const __m128i*)(x + base + m + 4))));
  }
  for (; m < count; m++) {
    packed[m] = (int16_t)x[base + m];
  }
}

void lpc_residual_16_sse2(const int32_t* x, int n, int order, const int32_t* coefs, int shift,
                          int32_t* residual) {
  /* One multiply-add takes two taps: a pair of neighbouring 16-bit
   * samples times a pair of coefficients, summed in 32 bits.  A vector
   * of such pairs serves every second sample, so the even and the odd
   * samples are predicted apart and woven together.  An odd order gets a
   * zero coefficient for the tap it lacks.  The samples are narrowed a
   * stretch at a time into a row on the stack. */
  enum { kStretch = 1024 };
  const int pairs = (order + 1) / 2;
  __m128i c[FLAC_MAX_LPC_ORDER / 2];
  for (int p = 0; p < pairs; p++) {
    const uint32_t near = (uint32_t)coefs[2 * p];
    const uint32_t far = 2 * p + 1 < order ? (uint32_t)coefs[2 * p + 1] : 0;
    c[p] = _mm_set1_epi32((int32_t)(near << 16 | (far & 0xFFFF)));
  }
  const __m128i count = _mm_cvtsi32_si128(shift);
  int16_t packed[kStretch + FLAC_MAX_LPC_ORDER];
  int i = order;
  while (i + 8 <= n) {
    const int outputs = (n - i) < kStretch ? ((n - i) & ~7) : kStretch;
    narrow_samples(x, i - 2 * pairs, outputs + 2 * pairs, packed);
    for (int o = 0; o < outputs; o += 8) {
      __m128i even = _mm_setzero_si128();
      __m128i odd = _mm_setzero_si128();
      for (int p = 0; p < pairs; p++) {
        const int16_t* s = packed + o + 2 * (pairs - 1 - p);
        even = _mm_add_epi32(even, _mm_madd_epi16(c[p], _mm_loadu_si128((const __m128i*)s)));
        odd = _mm_add_epi32(odd, _mm_madd_epi16(c[p], _mm_loadu_si128((const __m128i*)(s + 1))));
      }
      const __m128i first = _mm_sra_epi32(_mm_unpacklo_epi32(even, odd), count);
      const __m128i second = _mm_sra_epi32(_mm_unpackhi_epi32(even, odd), count);
      const int32_t* in = x + i + o;
      _mm_storeu_si128((__m128i*)(residual + i + o),
                       _mm_sub_epi32(_mm_loadu_si128((const __m128i*)in), first));
      _mm_storeu_si128((__m128i*)(residual + i + o + 4),
                       _mm_sub_epi32(_mm_loadu_si128((const __m128i*)(in + 4)), second));
    }
    i += outputs;
  }
  for (; i < n; i++) {
    uint32_t sum = 0;
    for (int j = 0; j < order; j++) {
      sum += (uint32_t)coefs[j] * (uint32_t)x[i - 1 - j];
    }
    residual[i] = (int32_t)((uint32_t)x[i] - (uint32_t)((int32_t)sum >> shift));
  }
}

void lpc_residual_32_sse2(const int32_t* x, int n, int order, const int32_t* coefs, int shift,
                          int32_t* residual) {
  /* SSE2 multiplies 32 by 32 bits only into 64, two lanes at a time:
   * the even and the odd samples are multiplied apart, and the low
   * halves of the two sums, which are the 32-bit sums, put back side by
   * side. */
  __m128i c[FLAC_MAX_LPC_ORDER];
  for (int j = 0; j < order; j++) {
    c[j] = _mm_set1_epi32(coefs[j]);
  }
  const __m128i count = _mm_cvtsi32_si128(shift);
  int i = order;
  for (; i + 4 <= n; i += 4) {
    __m128i even = _mm_setzero_si128();
    __m128i odd = _mm_setzero_si128();
    for (int j = 0; j < order; j++) {
      const __m128i s = _mm_loadu_si128((const __m128i*)(x + i - 1 - j));
      even = _mm_add_epi64(even, _mm_mul_epu32(c[j], s));
      odd = _mm_add_epi64(odd, _mm_mul_epu32(c[j], _mm_srli_si128(s, 4)));
    }
    const __m128i sum = _mm_unpacklo_epi32(_mm_shuffle_epi32(even, _MM_SHUFFLE(0, 0, 2, 0)),
                                           _mm_shuffle_epi32(odd, _MM_SHUFFLE(0, 0, 2, 0)));
    _mm_storeu_si128(
        (__m128i*)(residual + i),
        _mm_sub_epi32(_mm_loadu_si128((const __m128i*)(x + i)), _mm_sra_epi32(sum, count)));
  }
  for (; i < n; i++) {
    uint32_t sum = 0;
    for (int j = 0; j < order; j++) {
      sum += (uint32_t)coefs[j] * (uint32_t)x[i - 1 - j];
    }
    residual[i] = (int32_t)((uint32_t)x[i] - (uint32_t)((int32_t)sum >> shift));
  }
}

uint64_t rice_fold_sse2(const int32_t* residual, int n, uint32_t* folded, uint32_t* bits) {
  __m128i sum = _mm_setzero_si128();
  __m128i all = _mm_setzero_si128();
  int i = 0;
  for (; i + 4 <= n; i += 4) {
    const __m128i r = _mm_loadu_si128((const __m128i*)(residual + i));
    const __m128i u = _mm_xor_si128(_mm_slli_epi32(r, 1), _mm_srai_epi32(r, 31));
    _mm_storeu_si128((__m128i*)(folded + i), u);
    all = _mm_or_si128(all, u);
    sum = add_widened(sum, u);
  }
  uint64_t total = sum_lanes(sum);
  uint32_t lanes[4];
  _mm_storeu_si128((__m128i*)lanes, all);
  uint32_t any = lanes[0] | lanes[1] | lanes[2] | lanes[3];
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

/* The sums of K parameters in one pass over the values.  Where the
 * values are small enough that a 32-bit lane cannot overflow over the
 * whole run, the lanes are added as they are and widened once at the
 * end; the sum is the same either way. */
template <int K>
inline void rice_sums_group(const uint32_t* folded, int n, bool narrow, int k_low, uint64_t* sums,
                            size_t stride) {
  __m128i count[K];
  __m128i acc[K];
  for (int j = 0; j < K; j++) {
    count[j] = _mm_cvtsi32_si128(k_low + j);
    acc[j] = _mm_setzero_si128();
  }
  int i = 0;
  if (narrow) {
    for (; i + 4 <= n; i += 4) {
      const __m128i v = _mm_loadu_si128((const __m128i*)(folded + i));
      for (int j = 0; j < K; j++) {
        acc[j] = _mm_add_epi32(acc[j], _mm_srl_epi32(v, count[j]));
      }
    }
    for (int j = 0; j < K; j++) {
      acc[j] = add_widened(_mm_setzero_si128(), acc[j]);
    }
  } else {
    for (; i + 4 <= n; i += 4) {
      const __m128i v = _mm_loadu_si128((const __m128i*)(folded + i));
      for (int j = 0; j < K; j++) {
        acc[j] = add_widened(acc[j], _mm_srl_epi32(v, count[j]));
      }
    }
  }
  for (int j = 0; j < K; j++) {
    uint64_t total = sum_lanes(acc[j]);
    for (int t = i; t < n; t++) {
      total += folded[t] >> (k_low + j);
    }
    sums[(size_t)j * stride] = total;
  }
}

void rice_sums_sse2(const uint32_t* folded, int n, uint32_t bits, int k_low, int k_count,
                    uint64_t* sums, size_t stride) {
  /* Each lane takes n / 4 values, none above bits >> k_low. */
  const bool narrow = (uint64_t)(bits >> k_low) * (uint64_t)(n / 4) <= 0xFFFFFFFFull;
  while (k_count >= 4) {
    rice_sums_group<4>(folded, n, narrow, k_low, sums, stride);
    k_low += 4;
    k_count -= 4;
    sums += 4 * stride;
  }
  switch (k_count) {
    case 3:
      rice_sums_group<3>(folded, n, narrow, k_low, sums, stride);
      break;
    case 2:
      rice_sums_group<2>(folded, n, narrow, k_low, sums, stride);
      break;
    case 1:
      rice_sums_group<1>(folded, n, narrow, k_low, sums, stride);
      break;
    default:
      break;
  }
}

}  // namespace

void flac_dsp_init_sse2(FlacDSP* dsp) {
  dsp->window = window_sse2;
  dsp->autocorrelation = autocorrelation_sse2;
  dsp->fixed_sums = fixed_sums_sse2;
  dsp->lpc_residual_16 = lpc_residual_16_sse2;
  dsp->lpc_residual_32 = lpc_residual_32_sse2;
  dsp->rice_fold = rice_fold_sse2;
  dsp->rice_sums = rice_sums_sse2;
}

#endif /* BAAC_AAC_SSE2 || __SSE2__ */
