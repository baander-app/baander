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

static void aac_vector_fmul_reverse_avx2(float* dst, const float* a, const float* b, int len) {
  int i = 0, n8 = len & ~7;
  for (; i < n8; i += 8) {
    __m256 va = _mm256_loadu_ps(a + i);
    __m256 vb = _mm256_set_ps(b[len - 8 - i], b[len - 7 - i], b[len - 6 - i], b[len - 5 - i],
                              b[len - 4 - i], b[len - 3 - i], b[len - 2 - i], b[len - 1 - i]);
    _mm256_storeu_ps(dst + i, _mm256_mul_ps(va, vb));
  }
  for (; i < len; i++) {
    dst[i] = a[i] * b[len - 1 - i];
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
}

#endif /* BAAC_AAC_AVX2 || __AVX2__ */
