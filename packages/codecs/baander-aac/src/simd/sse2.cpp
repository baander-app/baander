/*
 * SSE2 SIMD implementations for AAC codec hot-path DSP operations.
 *
 * Overrides DSP function pointers when SSE2 is detected at runtime.
 * Processes 4 floats per cycle (128-bit XMM registers).
 *
 * Vector ops: 4-wide load/multiply/store with scalar tail.
 */
#include "aac_dsp.h"

#if defined(BAAC_AAC_SSE2) || defined(__SSE2__)

#include <emmintrin.h>

#include <cmath>
#include <cstring>

/* ── Vector Operations ───────────────────────────────────────── */

static void aac_vector_fmul_sse2(float* dst, const float* a, const float* b, int len) {
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    __m128 va = _mm_loadu_ps(a + i);
    __m128 vb = _mm_loadu_ps(b + i);
    _mm_storeu_ps(dst + i, _mm_mul_ps(va, vb));
  }
  for (; i < len; i++) {
    dst[i] = a[i] * b[i];
  }
}

static void aac_vector_fmul_scalar_sse2(float* dst, const float* a, float scale, int len) {
  __m128 vs = _mm_set1_ps(scale);
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    _mm_storeu_ps(dst + i, _mm_mul_ps(_mm_loadu_ps(a + i), vs));
  }
  for (; i < len; i++) {
    dst[i] = a[i] * scale;
  }
}

static void aac_vector_fmul_add_sse2(float* dst, const float* a, const float* b, const float* c,
                                     int len) {
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    __m128 va = _mm_loadu_ps(a + i);
    __m128 vb = _mm_loadu_ps(b + i);
    __m128 vc = _mm_loadu_ps(c + i);
    _mm_storeu_ps(dst + i, _mm_add_ps(_mm_mul_ps(va, vb), vc));
  }
  for (; i < len; i++) {
    dst[i] = a[i] * b[i] + c[i];
  }
}

static void aac_vector_fmul_window_sse2(float* dst, const float* a, const float* b,
                                        const float* win, int n) {
  int i = 0, n4 = n & ~3;
  for (; i < n4; i += 4) {
    __m128 va = _mm_loadu_ps(a + i);
    __m128 vb = _mm_loadu_ps(b + i);
    __m128 vw = _mm_loadu_ps(win + i);
    /* Reverse window for b: win[n-1-i], win[n-2-i], win[n-3-i], win[n-4-i] */
    __m128 vwr = _mm_setr_ps(win[n - 1 - i], win[n - 2 - i], win[n - 3 - i], win[n - 4 - i]);
    __m128 prod_a = _mm_mul_ps(va, vw);
    __m128 prod_b = _mm_mul_ps(vb, vwr);
    _mm_storeu_ps(dst + i, _mm_add_ps(prod_a, prod_b));
  }
  for (; i < n; i++) {
    dst[i] = a[i] * win[i] + b[i] * win[n - 1 - i];
  }
}

static void aac_vector_fmul_reverse_sse2(float* dst, const float* a, const float* b, int len) {
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    __m128 va = _mm_loadu_ps(a + i);
    /* b[len-1-i], b[len-2-i], b[len-3-i], b[len-4-i] */
    __m128 vb = _mm_setr_ps(b[len - 1 - i], b[len - 2 - i], b[len - 3 - i], b[len - 4 - i]);
    _mm_storeu_ps(dst + i, _mm_mul_ps(va, vb));
  }
  for (; i < len; i++) {
    dst[i] = a[i] * b[len - 1 - i];
  }
}

static void aac_vector_fmul_accumulate_sse2(float* dst, const float* a, const float* b, int len) {
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    __m128 vd = _mm_loadu_ps(dst + i);
    __m128 va = _mm_loadu_ps(a + i);
    __m128 vb = _mm_loadu_ps(b + i);
    _mm_storeu_ps(dst + i, _mm_add_ps(vd, _mm_mul_ps(va, vb)));
  }
  for (; i < len; i++) {
    dst[i] += a[i] * b[i];
  }
}

void aac_dsp_init_sse2(AacDSP* dsp) {
  dsp->vector_fmul = aac_vector_fmul_sse2;
  dsp->vector_fmul_scalar = aac_vector_fmul_scalar_sse2;
  dsp->vector_fmul_add = aac_vector_fmul_add_sse2;
  dsp->vector_fmul_window = aac_vector_fmul_window_sse2;
  dsp->vector_fmul_reverse = aac_vector_fmul_reverse_sse2;
  dsp->vector_fmul_accumulate = aac_vector_fmul_accumulate_sse2;
}

#endif /* BAAC_AAC_SSE2 || __SSE2__ */
