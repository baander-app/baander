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
#include <cstddef>
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

/* The four floats of v in reverse order. */
static inline __m128 reverse4(__m128 v) { return _mm_shuffle_ps(v, v, _MM_SHUFFLE(0, 1, 2, 3)); }

static void aac_vector_fmul_reverse_sse2(float* dst, const float* a, const float* b, int len) {
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    __m128 va = _mm_loadu_ps(a + i);
    /* b[len-1-i], b[len-2-i], b[len-3-i], b[len-4-i] */
    __m128 vb = reverse4(_mm_loadu_ps(b + len - 4 - i));
    _mm_storeu_ps(dst + i, _mm_mul_ps(va, vb));
  }
  for (; i < len; i++) {
    dst[i] = a[i] * b[len - 1 - i];
  }
}

/* ── Transform kernels ───────────────────────────────────────── */

/* The products and sums stay separate (no fused multiply-add), matching
 * the scalar kernel bit for bit. */
static void aac_fft_radix2_stage_sse2(float* re, float* im, int n, int half, const float* wr,
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
      const __m128 vwr = _mm_loadu_ps(wr + j);
      const __m128 vwi = _mm_loadu_ps(wi + j);
      const __m128 xr = _mm_loadu_ps(rb + j);
      const __m128 xi = _mm_loadu_ps(ib + j);
      const __m128 tr = _mm_sub_ps(_mm_mul_ps(xr, vwr), _mm_mul_ps(xi, vwi));
      const __m128 ti = _mm_add_ps(_mm_mul_ps(xr, vwi), _mm_mul_ps(xi, vwr));
      const __m128 ar = _mm_loadu_ps(ra + j);
      const __m128 ai = _mm_loadu_ps(ia + j);
      _mm_storeu_ps(rb + j, _mm_sub_ps(ar, tr));
      _mm_storeu_ps(ib + j, _mm_sub_ps(ai, ti));
      _mm_storeu_ps(ra + j, _mm_add_ps(ar, tr));
      _mm_storeu_ps(ia + j, _mm_add_ps(ai, ti));
    }
  }
}

static void aac_mdct_pre_twiddle_sse2(float* re, float* im, const float* in, const float* c,
                                      const float* s, int half) {
  const int quarter = half / 2;
  int j = 0;
  for (; j + 4 <= quarter; j += 4) {
    /* a = in[2j], in[2j+2], in[2j+4], in[2j+6]: the even entries of eight
     * consecutive inputs; b = in[half-1-2j] .. in[half-7-2j]: the odd
     * entries of the eight inputs ending at half-1-2j, reversed. */
    const __m128 v0 = _mm_loadu_ps(in + 2 * j);
    const __m128 v1 = _mm_loadu_ps(in + 2 * j + 4);
    const __m128 a = _mm_shuffle_ps(v0, v1, _MM_SHUFFLE(2, 0, 2, 0));
    const __m128 u0 = _mm_loadu_ps(in + half - 8 - 2 * j);
    const __m128 u1 = _mm_loadu_ps(in + half - 4 - 2 * j);
    const __m128 b = _mm_shuffle_ps(u1, u0, _MM_SHUFFLE(1, 3, 1, 3));
    const __m128 vc = _mm_loadu_ps(c + j);
    const __m128 vs = _mm_loadu_ps(s + j);
    _mm_storeu_ps(re + j, _mm_sub_ps(_mm_mul_ps(a, vc), _mm_mul_ps(b, vs)));
    _mm_storeu_ps(im + j, _mm_add_ps(_mm_mul_ps(a, vs), _mm_mul_ps(b, vc)));
  }
  for (; j < quarter; j++) {
    const float a = in[2 * j];
    const float b = in[half - 1 - 2 * j];
    re[j] = a * c[j] - b * s[j];
    im[j] = a * s[j] + b * c[j];
  }
}

/* The even outputs out[2j..2j+6] of a block of four twiddles interleave
 * with the odd outputs of the block mirrored about the middle, so the
 * two blocks j and quarter-4-j are computed together and written as full
 * vectors. */
static void aac_mdct_post_twiddle_sse2(float* out, const float* re, const float* im,
                                       const float* c, const float* s, int half) {
  const int quarter = half / 2;
  const __m128 sign = _mm_set1_ps(-0.0f);
  int j = 0;
  if (quarter % 4 == 0) {
    for (; j <= quarter - 4 - j; j += 4) {
      const int k = quarter - 4 - j;
      const __m128 r0 = _mm_loadu_ps(re + j), i0 = _mm_loadu_ps(im + j);
      const __m128 c0 = _mm_loadu_ps(c + j), s0 = _mm_loadu_ps(s + j);
      const __m128 yr0 = _mm_sub_ps(_mm_mul_ps(r0, c0), _mm_mul_ps(i0, s0));
      const __m128 yi0 = _mm_xor_ps(_mm_add_ps(_mm_mul_ps(r0, s0), _mm_mul_ps(i0, c0)), sign);
      const __m128 r1 = _mm_loadu_ps(re + k), i1 = _mm_loadu_ps(im + k);
      const __m128 c1 = _mm_loadu_ps(c + k), s1 = _mm_loadu_ps(s + k);
      const __m128 yr1 = _mm_sub_ps(_mm_mul_ps(r1, c1), _mm_mul_ps(i1, s1));
      const __m128 yi1 = _mm_xor_ps(_mm_add_ps(_mm_mul_ps(r1, s1), _mm_mul_ps(i1, c1)), sign);
      const __m128 odd0 = reverse4(yi1); /* out[2j+1] = -yi[quarter-1-j] ... */
      _mm_storeu_ps(out + 2 * j, _mm_unpacklo_ps(yr0, odd0));
      _mm_storeu_ps(out + 2 * j + 4, _mm_unpackhi_ps(yr0, odd0));
      if (k != j) {
        const __m128 odd1 = reverse4(yi0);
        _mm_storeu_ps(out + 2 * k, _mm_unpacklo_ps(yr1, odd1));
        _mm_storeu_ps(out + 2 * k + 4, _mm_unpackhi_ps(yr1, odd1));
      }
    }
    return;
  }
  for (; j < quarter; j++) {
    const float yr = re[j] * c[j] - im[j] * s[j];
    const float yi = re[j] * s[j] + im[j] * c[j];
    out[2 * j] = yr;
    out[half - 1 - 2 * j] = -yi;
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

/* ── Encoder kernels ─────────────────────────────────────────── */

namespace {

struct QuantNoiseArgs {
  __m128 scale, round, gain;
  const float* pow43;
};

/* d^2 of four lines (see the scalar kernel).  MINPS gives its second
 * operand for a NaN, so a NaN quantizes to 8191 as in the scalar
 * kernel's clamp. */
inline __m128 quant_noise4(__m128 coef, __m128 x34, const QuantNoiseArgs& a) {
  const __m128 v = _mm_add_ps(_mm_mul_ps(x34, a.scale), a.round);
  const __m128 clamped = _mm_max_ps(_mm_min_ps(v, _mm_set1_ps(8191.0f)), _mm_setzero_ps());
  const __m128i q = _mm_cvttps_epi32(clamped);
  const int q0 = _mm_cvtsi128_si32(q);
  const int q1 = _mm_cvtsi128_si32(_mm_shuffle_epi32(q, _MM_SHUFFLE(1, 1, 1, 1)));
  const int q2 = _mm_cvtsi128_si32(_mm_shuffle_epi32(q, _MM_SHUFFLE(2, 2, 2, 2)));
  const int q3 = _mm_cvtsi128_si32(_mm_shuffle_epi32(q, _MM_SHUFFLE(3, 3, 3, 3)));
  const __m128 p = _mm_setr_ps(a.pow43[q0], a.pow43[q1], a.pow43[q2], a.pow43[q3]);
  const __m128 d = _mm_sub_ps(_mm_andnot_ps(_mm_set1_ps(-0.0f), coef), _mm_mul_ps(p, a.gain));
  return _mm_mul_ps(d, d);
}

/* d^2 of the last n < 4 lines of a span in lanes 0..n-1, +0 in the
 * others, which leaves an accumulator unchanged (its lanes are never
 * -0). */
inline __m128 quant_noise_part(const float* coef, const float* x34, int n,
                               const QuantNoiseArgs& a) {
  alignas(16) float c[4] = {0.0f, 0.0f, 0.0f, 0.0f};
  alignas(16) float x[4] = {0.0f, 0.0f, 0.0f, 0.0f};
  for (int k = 0; k < n; k++) {
    c[k] = coef[k];
    x[k] = x34[k];
  }
  const __m128i lanes = _mm_setr_epi32(0, 1, 2, 3);
  const __m128 live = _mm_castsi128_ps(_mm_cmplt_epi32(lanes, _mm_set1_epi32(n)));
  return _mm_and_ps(quant_noise4(_mm_load_ps(c), _mm_load_ps(x), a), live);
}

/* ((a0 + a4) + (a2 + a6)) + ((a1 + a5) + (a3 + a7)) for lo = a0..a3 and
 * hi = a4..a7. */
inline float sum_lanes8(__m128 lo, __m128 hi) {
  const __m128 s = _mm_add_ps(lo, hi);
  const __m128 t = _mm_add_ps(s, _mm_movehl_ps(s, s));
  return _mm_cvtss_f32(_mm_add_ss(t, _mm_shuffle_ps(t, t, _MM_SHUFFLE(1, 1, 1, 1))));
}

}  // namespace

/* Lanes 0..3 of the scalar kernel's accumulators in lo, 4..7 in hi. */
static float aac_quant_noise_sse2(const float* coef, const float* x34, int len, int windows,
                                  int stride, float scale, float round, float gain,
                                  const float* pow43) {
  const QuantNoiseArgs a{_mm_set1_ps(scale), _mm_set1_ps(round), _mm_set1_ps(gain), pow43};
  __m128 lo = _mm_setzero_ps();
  __m128 hi = _mm_setzero_ps();
  for (int w = 0; w < windows; w++) {
    const float* cw = coef + (ptrdiff_t)w * stride;
    const float* xw = x34 + (ptrdiff_t)w * stride;
    int i = 0;
    for (; i + 8 <= len; i += 8) {
      lo = _mm_add_ps(lo, quant_noise4(_mm_loadu_ps(cw + i), _mm_loadu_ps(xw + i), a));
      hi = _mm_add_ps(hi, quant_noise4(_mm_loadu_ps(cw + i + 4), _mm_loadu_ps(xw + i + 4), a));
    }
    if (i + 4 <= len) {
      lo = _mm_add_ps(lo, quant_noise4(_mm_loadu_ps(cw + i), _mm_loadu_ps(xw + i), a));
      i += 4;
    }
    if (i < len) {
      const __m128 part = quant_noise_part(cw + i, xw + i, len - i, a);
      if (i & 4) {
        hi = _mm_add_ps(hi, part);
      } else {
        lo = _mm_add_ps(lo, part);
      }
    }
  }
  return sum_lanes8(lo, hi);
}

void aac_dsp_init_sse2(AacDSP* dsp) {
  dsp->vector_fmul = aac_vector_fmul_sse2;
  dsp->vector_fmul_scalar = aac_vector_fmul_scalar_sse2;
  dsp->vector_fmul_add = aac_vector_fmul_add_sse2;
  dsp->vector_fmul_window = aac_vector_fmul_window_sse2;
  dsp->vector_fmul_reverse = aac_vector_fmul_reverse_sse2;
  dsp->vector_fmul_accumulate = aac_vector_fmul_accumulate_sse2;
  dsp->fft_radix2_stage = aac_fft_radix2_stage_sse2;
  dsp->mdct_pre_twiddle = aac_mdct_pre_twiddle_sse2;
  dsp->mdct_post_twiddle = aac_mdct_post_twiddle_sse2;
  dsp->quant_noise = aac_quant_noise_sse2;
}

#endif /* BAAC_AAC_SSE2 || __SSE2__ */
