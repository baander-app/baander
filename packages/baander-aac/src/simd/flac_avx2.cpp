/* AVX2 kernels of the FLAC codec.  Each computes exactly what its scalar
 * counterpart in flac_dsp.cpp does: the integer ones by arithmetic that
 * wraps the same way, the floating-point ones by the same products and
 * the same sums in the same order, without a fused multiply-add. */
#include "../flac_internal.h"
#include "aac_cpu.h"

#if defined(BAAC_AAC_AVX2) || defined(__AVX2__)

#include <immintrin.h>

#include "flac_x86.h"

namespace {

/* ── analysis ───────────────────────────────────────────────────── */

void window_avx2(const double* x, const double* w, int n, double* y) {
  int i = 0;
  for (; i + 4 <= n; i += 4) {
    _mm256_storeu_pd(y + i, _mm256_mul_pd(_mm256_loadu_pd(x + i), _mm256_loadu_pd(w + i)));
  }
  for (; i < n; i++) {
    y[i] = x[i] * w[i];
  }
}

/* Four lags to a vector: lane j of group g is lag 4g + 3 - j, because a
 * load at y + i - 4g - 3 has the sample 4g + 3 back in its first lane.
 * Per lag one sum over the even i and one over the odd i, as the kernel
 * is defined; the first samples, which do not have every lag behind
 * them, go into the same sums one by one. */
template <int GROUPS>
void autocorrelation_groups(const double* y, int n, int max_lag, double* r) {
  const int lags = 4 * GROUPS;
  double sums[2][4 * GROUPS];
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
  __m256d even[GROUPS];
  __m256d odd[GROUPS];
  for (int g = 0; g < GROUPS; g++) {
    even[g] =
        _mm256_setr_pd(sums[0][4 * g + 3], sums[0][4 * g + 2], sums[0][4 * g + 1], sums[0][4 * g]);
    odd[g] =
        _mm256_setr_pd(sums[1][4 * g + 3], sums[1][4 * g + 2], sums[1][4 * g + 1], sums[1][4 * g]);
  }
  int i = start;
  for (; i + 2 <= n; i += 2) {
    const __m256d a = _mm256_set1_pd(y[i]);
    const __m256d b = _mm256_set1_pd(y[i + 1]);
    for (int g = 0; g < GROUPS; g++) {
      even[g] = _mm256_add_pd(even[g], _mm256_mul_pd(a, _mm256_loadu_pd(y + i - 4 * g - 3)));
      odd[g] = _mm256_add_pd(odd[g], _mm256_mul_pd(b, _mm256_loadu_pd(y + i - 4 * g - 2)));
    }
  }
  if (i < n) {
    const __m256d a = _mm256_set1_pd(y[i]);
    for (int g = 0; g < GROUPS; g++) {
      even[g] = _mm256_add_pd(even[g], _mm256_mul_pd(a, _mm256_loadu_pd(y + i - 4 * g - 3)));
    }
  }
  for (int g = 0; g < GROUPS; g++) {
    double lanes[4];
    _mm256_storeu_pd(lanes, _mm256_add_pd(even[g], odd[g]));
    for (int j = 0; j < 4; j++) {
      const int lag = 4 * g + 3 - j;
      if (lag <= max_lag) {
        r[lag] = lanes[j];
      }
    }
  }
}

void autocorrelation_avx2(const double* y, int n, int max_lag, double* r) {
  switch (max_lag / 4 + 1) {
    case 1:
      autocorrelation_groups<1>(y, n, max_lag, r);
      break;
    case 2:
      autocorrelation_groups<2>(y, n, max_lag, r);
      break;
    case 3:
      autocorrelation_groups<3>(y, n, max_lag, r);
      break;
    case 4:
      autocorrelation_groups<4>(y, n, max_lag, r);
      break;
    case 5:
      autocorrelation_groups<5>(y, n, max_lag, r);
      break;
    case 6:
      autocorrelation_groups<6>(y, n, max_lag, r);
      break;
    case 7:
      autocorrelation_groups<7>(y, n, max_lag, r);
      break;
    case 8:
      autocorrelation_groups<8>(y, n, max_lag, r);
      break;
    default:
      autocorrelation_groups<9>(y, n, max_lag, r);
      break;
  }
}

/* ── encoder ────────────────────────────────────────────────────── */

/* Adds the eight unsigned 32-bit lanes of v to the four 64-bit lanes of
 * a sum. */
inline __m256i add_widened(__m256i sum, __m256i v) {
  const __m256i zero = _mm256_setzero_si256();
  sum = _mm256_add_epi64(sum, _mm256_unpacklo_epi32(v, zero));
  return _mm256_add_epi64(sum, _mm256_unpackhi_epi32(v, zero));
}

inline uint64_t sum_lanes(__m256i sum) {
  uint64_t lanes[4];
  _mm256_storeu_si256((__m256i*)lanes, sum);
  return lanes[0] + lanes[1] + lanes[2] + lanes[3];
}

void fixed_sums_avx2(const int32_t* x, int n, int max_order, uint64_t* sums) {
  for (int k = 0; k <= FLAC_MAX_FIXED_ORDER; k++) {
    sums[k] = 0;
  }
  __m256i total[FLAC_MAX_FIXED_ORDER + 1];
  for (int k = 0; k <= FLAC_MAX_FIXED_ORDER; k++) {
    total[k] = _mm256_setzero_si256();
  }
  int i = max_order;
  if (max_order == FLAC_MAX_FIXED_ORDER) {
    for (; i + 8 <= n; i += 8) {
      /* The differences of each order from the samples and the four
       * before them: each order is the difference of the one below. */
      const __m256i x0 = _mm256_loadu_si256((const __m256i*)(x + i));
      const __m256i x1 = _mm256_loadu_si256((const __m256i*)(x + i - 1));
      const __m256i x2 = _mm256_loadu_si256((const __m256i*)(x + i - 2));
      const __m256i x3 = _mm256_loadu_si256((const __m256i*)(x + i - 3));
      const __m256i x4 = _mm256_loadu_si256((const __m256i*)(x + i - 4));
      const __m256i a0 = _mm256_sub_epi32(x0, x1);
      const __m256i a1 = _mm256_sub_epi32(x1, x2);
      const __m256i a2 = _mm256_sub_epi32(x2, x3);
      const __m256i a3 = _mm256_sub_epi32(x3, x4);
      const __m256i b0 = _mm256_sub_epi32(a0, a1);
      const __m256i b1 = _mm256_sub_epi32(a1, a2);
      const __m256i b2 = _mm256_sub_epi32(a2, a3);
      const __m256i c0 = _mm256_sub_epi32(b0, b1);
      const __m256i c1 = _mm256_sub_epi32(b1, b2);
      const __m256i d0 = _mm256_sub_epi32(c0, c1);
      total[0] = add_widened(total[0], _mm256_abs_epi32(x0));
      total[1] = add_widened(total[1], _mm256_abs_epi32(a0));
      total[2] = add_widened(total[2], _mm256_abs_epi32(b0));
      total[3] = add_widened(total[3], _mm256_abs_epi32(c0));
      total[4] = add_widened(total[4], _mm256_abs_epi32(d0));
    }
    for (int k = 0; k <= FLAC_MAX_FIXED_ORDER; k++) {
      sums[k] = sum_lanes(total[k]);
    }
  }
  /* The rest, and blocks too short for order 4, sample by sample. */
  flac_x86_fixed_sums_tail(x, i, n, max_order, sums);
}

void lpc_residual_16_avx2(const int32_t* x, int n, int order, const int32_t* coefs, int shift,
                          int32_t* residual) {
  /* One multiply-add takes two taps: a pair of neighbouring 16-bit
   * samples times a pair of coefficients, summed in 32 bits.  A vector
   * of such pairs serves every second sample, so the even and the odd
   * samples are predicted apart and woven together.  An odd order gets a
   * zero coefficient for the tap it lacks.  The samples are narrowed a
   * stretch at a time into a row on the stack. */
  enum { kStretch = 1024 };
  const int pairs = (order + 1) / 2;
  __m256i c[FLAC_MAX_LPC_ORDER / 2];
  for (int p = 0; p < pairs; p++) {
    const uint32_t near = (uint32_t)coefs[2 * p];
    const uint32_t far = 2 * p + 1 < order ? (uint32_t)coefs[2 * p + 1] : 0;
    c[p] = _mm256_set1_epi32((int32_t)(near << 16 | (far & 0xFFFF)));
  }
  const __m128i count = _mm_cvtsi32_si128(shift);
  int16_t packed[kStretch + FLAC_MAX_LPC_ORDER];
  int i = order;
  while (i + 16 <= n) {
    const int outputs = (n - i) < kStretch ? ((n - i) & ~15) : kStretch;
    flac_x86_narrow_samples(x, i - 2 * pairs, outputs + 2 * pairs, packed);
    for (int o = 0; o < outputs; o += 16) {
      __m256i even = _mm256_setzero_si256();
      __m256i odd = _mm256_setzero_si256();
      for (int p = 0; p < pairs; p++) {
        const int16_t* s = packed + o + 2 * (pairs - 1 - p);
        even =
            _mm256_add_epi32(even, _mm256_madd_epi16(c[p], _mm256_loadu_si256((const __m256i*)s)));
        odd = _mm256_add_epi32(
            odd, _mm256_madd_epi16(c[p], _mm256_loadu_si256((const __m256i*)(s + 1))));
      }
      /* Weaving works within each half of the vectors; the halves are
       * put in order afterwards. */
      const __m256i low = _mm256_unpacklo_epi32(even, odd);
      const __m256i high = _mm256_unpackhi_epi32(even, odd);
      const __m256i first = _mm256_sra_epi32(_mm256_permute2x128_si256(low, high, 0x20), count);
      const __m256i second = _mm256_sra_epi32(_mm256_permute2x128_si256(low, high, 0x31), count);
      const int32_t* in = x + i + o;
      _mm256_storeu_si256((__m256i*)(residual + i + o),
                          _mm256_sub_epi32(_mm256_loadu_si256((const __m256i*)in), first));
      _mm256_storeu_si256((__m256i*)(residual + i + o + 8),
                          _mm256_sub_epi32(_mm256_loadu_si256((const __m256i*)(in + 8)), second));
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

void lpc_residual_32_avx2(const int32_t* x, int n, int order, const int32_t* coefs, int shift,
                          int32_t* residual) {
  __m256i c[FLAC_MAX_LPC_ORDER];
  for (int j = 0; j < order; j++) {
    c[j] = _mm256_set1_epi32(coefs[j]);
  }
  const __m128i count = _mm_cvtsi32_si128(shift);
  int i = order;
  for (; i + 8 <= n; i += 8) {
    __m256i sum = _mm256_setzero_si256();
    for (int j = 0; j < order; j++) {
      sum = _mm256_add_epi32(
          sum, _mm256_mullo_epi32(c[j], _mm256_loadu_si256((const __m256i*)(x + i - 1 - j))));
    }
    _mm256_storeu_si256((__m256i*)(residual + i),
                        _mm256_sub_epi32(_mm256_loadu_si256((const __m256i*)(x + i)),
                                         _mm256_sra_epi32(sum, count)));
  }
  for (; i < n; i++) {
    uint32_t sum = 0;
    for (int j = 0; j < order; j++) {
      sum += (uint32_t)coefs[j] * (uint32_t)x[i - 1 - j];
    }
    residual[i] = (int32_t)((uint32_t)x[i] - (uint32_t)((int32_t)sum >> shift));
  }
}

bool lpc_residual_64_avx2(const int32_t* x, int n, int order, const int32_t* coefs, int shift,
                          int32_t* residual) {
  /* Four samples at a time in 64-bit lanes: a signed 32 by 32 bit
   * product per lane, an arithmetic shift put together from a logical
   * one and the sign, and a residual that is in range when it equals
   * its own low 32 bits sign-extended and is not the most negative
   * value. */
  __m256i c[FLAC_MAX_LPC_ORDER];
  for (int j = 0; j < order; j++) {
    c[j] = _mm256_set1_epi64x(coefs[j]);
  }
  const __m128i count = _mm_cvtsi32_si128(shift);
  const __m128i fill = _mm_cvtsi32_si128(64 - shift);
  const __m256i low = _mm256_setr_epi32(0, 2, 4, 6, 0, 2, 4, 6);
  __m256i bad = _mm256_setzero_si256();
  __m128i lowest = _mm_setzero_si128();
  int i = order;
  for (; i + 4 <= n; i += 4) {
    __m256i sum = _mm256_setzero_si256();
    for (int j = 0; j < order; j++) {
      const __m256i s = _mm256_cvtepi32_epi64(_mm_loadu_si128((const __m128i*)(x + i - 1 - j)));
      sum = _mm256_add_epi64(sum, _mm256_mul_epi32(c[j], s));
    }
    const __m256i sign = _mm256_cmpgt_epi64(_mm256_setzero_si256(), sum);
    const __m256i prediction =
        _mm256_or_si256(_mm256_srl_epi64(sum, count), _mm256_sll_epi64(sign, fill));
    const __m256i r = _mm256_sub_epi64(
        _mm256_cvtepi32_epi64(_mm_loadu_si128((const __m128i*)(x + i))), prediction);
    const __m128i r32 = _mm256_castsi256_si128(_mm256_permutevar8x32_epi32(r, low));
    _mm_storeu_si128((__m128i*)(residual + i), r32);
    bad = _mm256_or_si256(bad, _mm256_xor_si256(r, _mm256_cvtepi32_epi64(r32)));
    lowest = _mm_or_si128(lowest, _mm_cmpeq_epi32(r32, _mm_set1_epi32(INT32_MIN)));
  }
  bool outside = !_mm256_testz_si256(bad, bad) || !_mm_testz_si128(lowest, lowest);
  for (; i < n; i++) {
    int64_t sum = 0;
    for (int j = 0; j < order; j++) {
      sum += (int64_t)coefs[j] * (int64_t)x[i - 1 - j];
    }
    const int64_t r = (int64_t)x[i] - (sum >> shift);
    outside |= (uint64_t)(r + INT32_MAX) > 0xFFFFFFFEull;
    residual[i] = (int32_t)r;
  }
  return !outside;
}

uint64_t rice_fold_avx2(const int32_t* residual, int n, uint32_t* folded, uint32_t* bits) {
  __m256i sum = _mm256_setzero_si256();
  __m256i all = _mm256_setzero_si256();
  int i = 0;
  for (; i + 8 <= n; i += 8) {
    const __m256i r = _mm256_loadu_si256((const __m256i*)(residual + i));
    const __m256i u = _mm256_xor_si256(_mm256_slli_epi32(r, 1), _mm256_srai_epi32(r, 31));
    _mm256_storeu_si256((__m256i*)(folded + i), u);
    all = _mm256_or_si256(all, u);
    sum = add_widened(sum, u);
  }
  uint64_t total = sum_lanes(sum);
  uint32_t lanes[8];
  _mm256_storeu_si256((__m256i*)lanes, all);
  uint32_t any =
      lanes[0] | lanes[1] | lanes[2] | lanes[3] | lanes[4] | lanes[5] | lanes[6] | lanes[7];
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
  __m256i acc[K];
  for (int j = 0; j < K; j++) {
    count[j] = _mm_cvtsi32_si128(k_low + j);
    acc[j] = _mm256_setzero_si256();
  }
  int i = 0;
  if (narrow) {
    for (; i + 8 <= n; i += 8) {
      const __m256i v = _mm256_loadu_si256((const __m256i*)(folded + i));
      for (int j = 0; j < K; j++) {
        acc[j] = _mm256_add_epi32(acc[j], _mm256_srl_epi32(v, count[j]));
      }
    }
    for (int j = 0; j < K; j++) {
      acc[j] = add_widened(_mm256_setzero_si256(), acc[j]);
    }
  } else {
    for (; i + 8 <= n; i += 8) {
      const __m256i v = _mm256_loadu_si256((const __m256i*)(folded + i));
      for (int j = 0; j < K; j++) {
        acc[j] = add_widened(acc[j], _mm256_srl_epi32(v, count[j]));
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

void rice_sums_avx2(const uint32_t* folded, int n, uint32_t bits, int k_low, int k_count,
                    uint64_t* sums, size_t stride) {
  /* Each lane takes n / 8 values, none above bits >> k_low. */
  const bool narrow = (uint64_t)(bits >> k_low) * (uint64_t)(n / 8) <= 0xFFFFFFFFull;
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

/* ── decoder ────────────────────────────────────────────────────── */

/* Processors with AVX2 count leading zeros in one instruction and shift
 * by a variable count without touching the flags (LZCNT and BMI2), which
 * is what the Rice loop waits on.  The function is compiled for those
 * two on top of the file's instruction set, and installed only when the
 * processor reports both. */
#if defined(__GNUC__) || defined(__clang__)
#define FLAC_HAVE_RICE_DECODE_LZCNT 1

__attribute__((target("lzcnt,bmi2"))) int rice_decode_lzcnt(FlacBitReader* r, int32_t* out,
                                                            int count, int k) {
  return flac_rice_decode_words(r, out, count, k);
}
#endif

}  // namespace

void flac_dsp_init_avx2(FlacDSP* dsp) {
  dsp->window = window_avx2;
  dsp->autocorrelation = autocorrelation_avx2;
  dsp->fixed_sums = fixed_sums_avx2;
  dsp->lpc_residual_16 = lpc_residual_16_avx2;
  dsp->lpc_residual_32 = lpc_residual_32_avx2;
  dsp->lpc_residual_64 = lpc_residual_64_avx2;
  dsp->rice_fold = rice_fold_avx2;
  dsp->rice_sums = rice_sums_avx2;
#if defined(FLAC_HAVE_RICE_DECODE_LZCNT)
  const int wanted = AAC_CPU_FLAG_BMI2 | AAC_CPU_FLAG_LZCNT;
  if ((aac_get_cpu_flags() & wanted) == wanted) {
    dsp->rice_decode = rice_decode_lzcnt;
  }
#endif
}

#endif /* BAAC_AAC_AVX2 || __AVX2__ */
