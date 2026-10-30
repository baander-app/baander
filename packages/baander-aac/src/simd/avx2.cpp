/*
 * AVX2 + FMA3 + BMI2 SIMD implementations for AAC codec hot-path DSP operations.
 *
 * Extends SSE2 with 8-wide processing (256-bit YMM registers) and fused
 * multiply-add (FMA3). Overrides SSE2 pointers when AVX2 is detected.
 *
 * FMA3: single-instruction multiply-add for the vector ops.
 */
#include "aac_dsp.h"

#if defined(BAAC_AAC_AVX2) || defined(__AVX2__)

#include <immintrin.h>

#include <cmath>
#include <cstring>

/* ── Vector Operations ───────────────────────────────────────── */

static void aac_vector_fmul_avx2(float* dst, const float* a, const float* b, int len) {
  int i = 0, n8 = len & ~7;
  for (; i < n8; i += 8) {
    __m256 va = _mm256_loadu_ps(a + i);
    __m256 vb = _mm256_loadu_ps(b + i);
    _mm256_storeu_ps(dst + i, _mm256_mul_ps(va, vb));
  }
  /* SSE2 tail */
  for (; i < len; i++) {
    dst[i] = a[i] * b[i];
  }
}

static void aac_vector_fmul_scalar_avx2(float* dst, const float* a, float scale, int len) {
  __m256 vs = _mm256_set1_ps(scale);
  int i = 0, n8 = len & ~7;
  for (; i < n8; i += 8) {
    _mm256_storeu_ps(dst + i, _mm256_mul_ps(_mm256_loadu_ps(a + i), vs));
  }
  for (; i < len; i++) {
    dst[i] = a[i] * scale;
  }
}

static void aac_vector_fmul_add_avx2(float* dst, const float* a, const float* b, const float* c,
                                     int len) {
  int i = 0, n8 = len & ~7;
#if defined(__FMA__)
  for (; i < n8; i += 8) {
    __m256 va = _mm256_loadu_ps(a + i);
    __m256 vb = _mm256_loadu_ps(b + i);
    __m256 vc = _mm256_loadu_ps(c + i);
    _mm256_storeu_ps(dst + i, _mm256_fmadd_ps(va, vb, vc)); /* a*b + c */
  }
#else
  for (; i < n8; i += 8) {
    __m256 va = _mm256_loadu_ps(a + i);
    __m256 vb = _mm256_loadu_ps(b + i);
    __m256 vc = _mm256_loadu_ps(c + i);
    _mm256_storeu_ps(dst + i, _mm256_add_ps(_mm256_mul_ps(va, vb), vc));
  }
#endif
  for (; i < len; i++) {
    dst[i] = a[i] * b[i] + c[i];
  }
}

static void aac_vector_fmul_window_avx2(float* dst, const float* a, const float* b,
                                        const float* win, int n) {
  int i = 0, n8 = n & ~7;
  for (; i < n8; i += 8) {
    __m256 va = _mm256_loadu_ps(a + i);
    __m256 vw = _mm256_loadu_ps(win + i);
    /* Build reversed window slice for b */
    __m256 vwr = _mm256_set_ps(win[n - 8 - i], win[n - 7 - i], win[n - 6 - i], win[n - 5 - i],
                               win[n - 4 - i], win[n - 3 - i], win[n - 2 - i], win[n - 1 - i]);
    __m256 vb = _mm256_loadu_ps(b + i);
#if defined(__FMA__)
    __m256 pa = _mm256_mul_ps(va, vw);
    _mm256_storeu_ps(dst + i, _mm256_fmadd_ps(vb, vwr, pa));
#else
    __m256 pa = _mm256_mul_ps(va, vw);
    _mm256_storeu_ps(dst + i, _mm256_add_ps(pa, _mm256_mul_ps(vb, vwr)));
#endif
  }
  for (; i < n; i++) {
    dst[i] = a[i] * win[i] + b[i] * win[n - 1 - i];
  }
}

/* The eight floats of v in reverse order. */
static inline __m256 reverse8(__m256 v) {
  return _mm256_permutevar8x32_ps(v, _mm256_setr_epi32(7, 6, 5, 4, 3, 2, 1, 0));
}

static void aac_vector_fmul_reverse_avx2(float* dst, const float* a, const float* b, int len) {
  int i = 0, n8 = len & ~7;
  for (; i < n8; i += 8) {
    __m256 va = _mm256_loadu_ps(a + i);
    __m256 vb = reverse8(_mm256_loadu_ps(b + len - 8 - i));
    _mm256_storeu_ps(dst + i, _mm256_mul_ps(va, vb));
  }
  for (; i < len; i++) {
    dst[i] = a[i] * b[len - 1 - i];
  }
}

/* ── Transform kernels ───────────────────────────────────────── */

/* This file is compiled without floating-point contraction, so the
 * products and sums below stay separate and match the scalar kernels bit
 * for bit; the vector kernels above ask for fused multiply-adds
 * explicitly where they want them. */
static void aac_fft_radix2_stage_avx2(float* re, float* im, int n, int half, const float* wr,
                                      const float* wi) {
  if (half < 4) {
    aac_fft_radix2_stage_c(re, im, n, half, wr, wi);
    return;
  }
  if (half == 4) {
    for (int start = 0; start < n; start += 8) {
      float* ra = re + start;
      float* ia = im + start;
      const __m128 vwr = _mm_loadu_ps(wr);
      const __m128 vwi = _mm_loadu_ps(wi);
      const __m128 xr = _mm_loadu_ps(ra + 4);
      const __m128 xi = _mm_loadu_ps(ia + 4);
      const __m128 tr = _mm_sub_ps(_mm_mul_ps(xr, vwr), _mm_mul_ps(xi, vwi));
      const __m128 ti = _mm_add_ps(_mm_mul_ps(xr, vwi), _mm_mul_ps(xi, vwr));
      const __m128 ar = _mm_loadu_ps(ra);
      const __m128 ai = _mm_loadu_ps(ia);
      _mm_storeu_ps(ra + 4, _mm_sub_ps(ar, tr));
      _mm_storeu_ps(ia + 4, _mm_sub_ps(ai, ti));
      _mm_storeu_ps(ra, _mm_add_ps(ar, tr));
      _mm_storeu_ps(ia, _mm_add_ps(ai, ti));
    }
    return;
  }
  for (int start = 0; start < n; start += 2 * half) {
    float* ra = re + start;
    float* ia = im + start;
    float* rb = ra + half;
    float* ib = ia + half;
    for (int j = 0; j < half; j += 8) {
      const __m256 vwr = _mm256_loadu_ps(wr + j);
      const __m256 vwi = _mm256_loadu_ps(wi + j);
      const __m256 xr = _mm256_loadu_ps(rb + j);
      const __m256 xi = _mm256_loadu_ps(ib + j);
      const __m256 tr = _mm256_sub_ps(_mm256_mul_ps(xr, vwr), _mm256_mul_ps(xi, vwi));
      const __m256 ti = _mm256_add_ps(_mm256_mul_ps(xr, vwi), _mm256_mul_ps(xi, vwr));
      const __m256 ar = _mm256_loadu_ps(ra + j);
      const __m256 ai = _mm256_loadu_ps(ia + j);
      _mm256_storeu_ps(rb + j, _mm256_sub_ps(ar, tr));
      _mm256_storeu_ps(ib + j, _mm256_sub_ps(ai, ti));
      _mm256_storeu_ps(ra + j, _mm256_add_ps(ar, tr));
      _mm256_storeu_ps(ia + j, _mm256_add_ps(ai, ti));
    }
  }
}

static void aac_mdct_pre_twiddle_avx2(float* re, float* im, const float* in, const float* c,
                                      const float* s, int half) {
  const int quarter = half / 2;
  int j = 0;
  for (; j + 8 <= quarter; j += 8) {
    /* a = the even entries of in[2j .. 2j+15], b = the odd entries of
     * in[half-16-2j .. half-1-2j] in reverse.  The lane-wise shuffle
     * leaves the 64-bit pairs out of order, which the permute fixes. */
    const __m256 v0 = _mm256_loadu_ps(in + 2 * j);
    const __m256 v1 = _mm256_loadu_ps(in + 2 * j + 8);
    const __m256 a = _mm256_castpd_ps(_mm256_permute4x64_pd(
        _mm256_castps_pd(_mm256_shuffle_ps(v0, v1, _MM_SHUFFLE(2, 0, 2, 0))),
        _MM_SHUFFLE(3, 1, 2, 0)));
    const __m256 u0 = _mm256_loadu_ps(in + half - 16 - 2 * j);
    const __m256 u1 = _mm256_loadu_ps(in + half - 8 - 2 * j);
    const __m256 b = _mm256_castpd_ps(_mm256_permute4x64_pd(
        _mm256_castps_pd(_mm256_shuffle_ps(u1, u0, _MM_SHUFFLE(1, 3, 1, 3))),
        _MM_SHUFFLE(1, 3, 0, 2)));
    const __m256 vc = _mm256_loadu_ps(c + j);
    const __m256 vs = _mm256_loadu_ps(s + j);
    _mm256_storeu_ps(re + j, _mm256_sub_ps(_mm256_mul_ps(a, vc), _mm256_mul_ps(b, vs)));
    _mm256_storeu_ps(im + j, _mm256_add_ps(_mm256_mul_ps(a, vs), _mm256_mul_ps(b, vc)));
  }
  for (; j < quarter; j++) {
    const float a = in[2 * j];
    const float b = in[half - 1 - 2 * j];
    re[j] = a * c[j] - b * s[j];
    im[j] = a * s[j] + b * c[j];
  }
}

/* Interleaves a and b into out[0..15]: a0 b0 a1 b1 ... a7 b7. */
static inline void store_interleaved8(float* out, __m256 a, __m256 b) {
  const __m256 lo = _mm256_unpacklo_ps(a, b); /* a0 b0 a1 b1 | a4 b4 a5 b5 */
  const __m256 hi = _mm256_unpackhi_ps(a, b); /* a2 b2 a3 b3 | a6 b6 a7 b7 */
  _mm256_storeu_ps(out, _mm256_permute2f128_ps(lo, hi, 0x20));
  _mm256_storeu_ps(out + 8, _mm256_permute2f128_ps(lo, hi, 0x31));
}

/* Blocks j and quarter-8-j are computed together, as in the SSE2 kernel;
 * what is left in the middle when quarter is not a multiple of 16 goes
 * through the scalar loop. */
static void aac_mdct_post_twiddle_avx2(float* out, const float* re, const float* im,
                                       const float* c, const float* s, int half) {
  const int quarter = half / 2;
  const __m256 sign = _mm256_set1_ps(-0.0f);
  int j = 0;
  int end = quarter;
  for (; quarter - 2 * j >= 16; j += 8) {
    const int k = quarter - 8 - j;
    const __m256 r0 = _mm256_loadu_ps(re + j), i0 = _mm256_loadu_ps(im + j);
    const __m256 c0 = _mm256_loadu_ps(c + j), s0 = _mm256_loadu_ps(s + j);
    const __m256 yr0 = _mm256_sub_ps(_mm256_mul_ps(r0, c0), _mm256_mul_ps(i0, s0));
    const __m256 yi0 =
        _mm256_xor_ps(_mm256_add_ps(_mm256_mul_ps(r0, s0), _mm256_mul_ps(i0, c0)), sign);
    const __m256 r1 = _mm256_loadu_ps(re + k), i1 = _mm256_loadu_ps(im + k);
    const __m256 c1 = _mm256_loadu_ps(c + k), s1 = _mm256_loadu_ps(s + k);
    const __m256 yr1 = _mm256_sub_ps(_mm256_mul_ps(r1, c1), _mm256_mul_ps(i1, s1));
    const __m256 yi1 =
        _mm256_xor_ps(_mm256_add_ps(_mm256_mul_ps(r1, s1), _mm256_mul_ps(i1, c1)), sign);
    store_interleaved8(out + 2 * j, yr0, reverse8(yi1));
    store_interleaved8(out + 2 * k, yr1, reverse8(yi0));
    end = k;
  }
  for (; j < end; j++) {
    const float yr = re[j] * c[j] - im[j] * s[j];
    const float yi = re[j] * s[j] + im[j] * c[j];
    out[2 * j] = yr;
    out[half - 1 - 2 * j] = -yi;
  }
}

static void aac_vector_fmul_accumulate_avx2(float* dst, const float* a, const float* b, int len) {
  int i = 0, n8 = len & ~7;
#if defined(__FMA__)
  for (; i < n8; i += 8) {
    __m256 vd = _mm256_loadu_ps(dst + i);
    __m256 va = _mm256_loadu_ps(a + i);
    __m256 vb = _mm256_loadu_ps(b + i);
    _mm256_storeu_ps(dst + i, _mm256_fmadd_ps(va, vb, vd)); /* dst += a*b */
  }
#else
  for (; i < n8; i += 8) {
    __m256 vd = _mm256_loadu_ps(dst + i);
    __m256 va = _mm256_loadu_ps(a + i);
    __m256 vb = _mm256_loadu_ps(b + i);
    _mm256_storeu_ps(dst + i, _mm256_add_ps(vd, _mm256_mul_ps(va, vb)));
  }
#endif
  for (; i < len; i++) {
    dst[i] += a[i] * b[i];
  }
}

void aac_dsp_init_avx2(AacDSP* dsp) {
  /* AVX2 supersedes SSE2 — override all function pointers */
  dsp->vector_fmul = aac_vector_fmul_avx2;
  dsp->vector_fmul_scalar = aac_vector_fmul_scalar_avx2;
  dsp->vector_fmul_add = aac_vector_fmul_add_avx2;
  dsp->vector_fmul_window = aac_vector_fmul_window_avx2;
  dsp->vector_fmul_reverse = aac_vector_fmul_reverse_avx2;
  dsp->vector_fmul_accumulate = aac_vector_fmul_accumulate_avx2;
  dsp->fft_radix2_stage = aac_fft_radix2_stage_avx2;
  dsp->mdct_pre_twiddle = aac_mdct_pre_twiddle_avx2;
  dsp->mdct_post_twiddle = aac_mdct_post_twiddle_avx2;
}

#endif /* BAAC_AAC_AVX2 || __AVX2__ */
