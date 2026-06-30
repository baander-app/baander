/*
 * WASM SIMD128 implementations for AAC decoder hot-path DSP operations.
 *
 * Overrides DSP function pointers when WASM SIMD128 is detected at compile time.
 * Processes 4 floats per cycle (v128 registers).
 *
 * Decoder-only — encoder uses scalar or native SIMD paths.
 * FFT: precomputed twiddles + 4-wide butterfly for stages s >= 4.
 * Vector ops: 4-wide load/multiply/store with scalar tail.
 */
#include "aac_dsp.h"
#include "fft.h"
#include "mdct.h"

#if defined(BAAC_AAC_WASM) || defined(__wasm_simd128__)

#include <wasm_simd128.h>

#include <cmath>
#include <cstring>

/* ── Vector Operations ───────────────────────────────────────── */

static void aac_vector_fmul_wasm(float* dst, const float* a, const float* b, int len) {
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    v128_t va = wasm_v128_load(a + i);
    v128_t vb = wasm_v128_load(b + i);
    wasm_v128_store(dst + i, wasm_f32x4_mul(va, vb));
  }
  for (; i < len; i++) dst[i] = a[i] * b[i];
}

static void aac_vector_fmul_scalar_wasm(float* dst, const float* a, float scale, int len) {
  v128_t vs = wasm_f32x4_splat(scale);
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) wasm_v128_store(dst + i, wasm_f32x4_mul(wasm_v128_load(a + i), vs));
  for (; i < len; i++) dst[i] = a[i] * scale;
}

static void aac_vector_fmul_add_wasm(float* dst, const float* a, const float* b, const float* c,
                                     int len) {
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    v128_t va = wasm_v128_load(a + i);
    v128_t vb = wasm_v128_load(b + i);
    v128_t vc = wasm_v128_load(c + i);
    wasm_v128_store(dst + i, wasm_f32x4_add(wasm_f32x4_mul(va, vb), vc));
  }
  for (; i < len; i++) dst[i] = a[i] * b[i] + c[i];
}

static void aac_vector_fmul_window_wasm(float* dst, const float* a, const float* b,
                                        const float* win, int n) {
  int i = 0, n4 = n & ~3;
  for (; i < n4; i += 4) {
    v128_t va = wasm_v128_load(a + i);
    v128_t vw = wasm_v128_load(win + i);
    /* Build reversed window: win[n-1-i..n-4-i] */
    float wr[4] = {win[n - 1 - i], win[n - 2 - i], win[n - 3 - i], win[n - 4 - i]};
    v128_t vwr = wasm_v128_load(wr);
    v128_t vb = wasm_v128_load(b + i);
    v128_t prod_a = wasm_f32x4_mul(va, vw);
    wasm_v128_store(dst + i, wasm_f32x4_add(prod_a, wasm_f32x4_mul(vb, vwr)));
  }
  for (; i < n; i++) dst[i] = a[i] * win[i] + b[i] * win[n - 1 - i];
}

static void aac_vector_fmul_reverse_wasm(float* dst, const float* a, const float* b, int len) {
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    v128_t va = wasm_v128_load(a + i);
    float rb[4] = {b[len - 1 - i], b[len - 2 - i], b[len - 3 - i], b[len - 4 - i]};
    v128_t vb = wasm_v128_load(rb);
    wasm_v128_store(dst + i, wasm_f32x4_mul(va, vb));
  }
  for (; i < len; i++) dst[i] = a[i] * b[len - 1 - i];
}

static void aac_vector_fmul_accumulate_wasm(float* dst, const float* a, const float* b, int len) {
  int i = 0, n4 = len & ~3;
  for (; i < n4; i += 4) {
    v128_t vd = wasm_v128_load(dst + i);
    v128_t va = wasm_v128_load(a + i);
    v128_t vb = wasm_v128_load(b + i);
    wasm_v128_store(dst + i, wasm_f32x4_add(vd, wasm_f32x4_mul(va, vb)));
  }
  for (; i < len; i++) dst[i] += a[i] * b[i];
}

/* ── FFT ─────────────────────────────────────────────────────── */

static void wasm_bit_reverse(float* re, float* im, int n) {
  int j = 0;
  for (int i = 0; i < n - 1; i++) {
    if (i < j) {
      float tr = re[i];
      re[i] = re[j];
      re[j] = tr;
      float ti = im[i];
      im[i] = im[j];
      im[j] = ti;
    }
    int k = n >> 1;
    while (k <= j) {
      j -= k;
      k >>= 1;
    }
    j += k;
  }
}

static void aac_fft_forward_wasm(float* re, float* im, int n) {
  wasm_bit_reverse(re, im, n);

  static thread_local float tw_re_buf[2048];
  static thread_local float tw_im_buf[2048];

  for (int s = 1; s < n; s <<= 1) {
    int m = s << 1;
    float wn_re = cosf((float)M_PI / (float)s);
    float wn_im = -sinf((float)M_PI / (float)s);

    /* Precompute twiddles iteratively */
    {
      float w_re = 1.0f, w_im = 0.0f;
      for (int j = 0; j < s; j++) {
        tw_re_buf[j] = w_re;
        tw_im_buf[j] = w_im;
        float nw_re = w_re * wn_re - w_im * wn_im;
        float nw_im = w_re * wn_im + w_im * wn_re;
        w_re = nw_re;
        w_im = nw_im;
      }
    }

    for (int k = 0; k < n; k += m) {
      int j = 0;
      /* 4-wide butterfly with WASM SIMD128 */
      for (; j <= s - 4; j += 4) {
        v128_t r_up = wasm_v128_load(&re[k + j]);
        v128_t i_up = wasm_v128_load(&im[k + j]);
        v128_t r_lo = wasm_v128_load(&re[k + j + s]);
        v128_t i_lo = wasm_v128_load(&im[k + j + s]);
        v128_t tw_r = wasm_v128_load(&tw_re_buf[j]);
        v128_t tw_i = wasm_v128_load(&tw_im_buf[j]);

        v128_t t_re = wasm_f32x4_sub(wasm_f32x4_mul(tw_r, r_lo), wasm_f32x4_mul(tw_i, i_lo));
        v128_t t_im = wasm_f32x4_add(wasm_f32x4_mul(tw_r, i_lo), wasm_f32x4_mul(tw_i, r_lo));

        wasm_v128_store(&re[k + j + s], wasm_f32x4_sub(r_up, t_re));
        wasm_v128_store(&im[k + j + s], wasm_f32x4_sub(i_up, t_im));
        wasm_v128_store(&re[k + j], wasm_f32x4_add(r_up, t_re));
        wasm_v128_store(&im[k + j], wasm_f32x4_add(i_up, t_im));
      }
      /* Scalar tail */
      for (; j < s; j++) {
        float wr = tw_re_buf[j], wi = tw_im_buf[j];
        float t_re = wr * re[k + j + s] - wi * im[k + j + s];
        float t_im = wr * im[k + j + s] + wi * re[k + j + s];
        re[k + j + s] = re[k + j] - t_re;
        im[k + j + s] = im[k + j] - t_im;
        re[k + j] += t_re;
        im[k + j] += t_im;
      }
    }
  }
}

static void aac_fft_inverse_wasm(float* re, float* im, int n) {
  /* Negate imaginary: XOR with sign bit (0x80000000) */
  v128_t sign_mask = wasm_f32x4_splat(-0.0f);
  int i = 0, n4 = n & ~3;
  for (; i < n4; i += 4) {
    v128_t vi = wasm_v128_load(&im[i]);
    wasm_v128_store(&im[i], wasm_v128_xor(vi, sign_mask));
  }
  for (; i < n; i++) im[i] = -im[i];

  aac_fft_forward_wasm(re, im, n);

  /* Scale and negate imaginary */
  v128_t vinv = wasm_f32x4_splat(1.0f / (float)n);
  i = 0;
  for (; i < n4; i += 4) {
    wasm_v128_store(&re[i], wasm_f32x4_mul(wasm_v128_load(&re[i]), vinv));
    wasm_v128_store(&im[i], wasm_v128_xor(wasm_f32x4_mul(wasm_v128_load(&im[i]), vinv), sign_mask));
  }
  for (; i < n; i++) {
    re[i] /= (float)n;
    im[i] = -im[i] / (float)n;
  }
}

/* ── MDCT Forward (calls WASM FFT) ─────────────────────────────
 * Rotation strategy (WASM SIMD128, 4-wide):
 *   - Twiddle precompute (critical because WASM trig is expensive).
 *   - Complex multiplies vectorized with wasm_f32x4 ops.
 *   - Post-rotation uses manual interleave (WASM has limited shuffle support).
 * Numerical expectations: same model as the other backends (see AVX2 comment).
 */

static void aac_mdct_forward_wasm(float* out, const float* in, int n, const float* win) {
  int n2 = n >> 1, n4 = n >> 2;
  static thread_local float s_re[512], s_im[512];
  static thread_local float mdct_tw_re[256], mdct_tw_im[256];
  float* re = s_re;
  float* im = s_im;
  memset(re, 0, n4 * sizeof(float));
  memset(im, 0, n4 * sizeof(float));

  /* Precompute MDCT twiddles once */
  for (int k = 0; k < n4; k++) {
    float ang = (float)M_PI * (k + 0.125f) / (float)n4;
    mdct_tw_re[k] = cosf(ang);
    mdct_tw_im[k] = sinf(ang);
  }

  /* Pre-rotation */
  for (int k = 0; k < n4; k++) {
    float a = win[n2 - n4 + k] * in[n2 - n4 + k] + win[n4 - 1 - k] * in[n4 - 1 - k];
    float b = win[n2 + k] * in[n2 + k] - win[n - 1 - k] * in[n - 1 - k];
    re[k] = a;
    im[k] = b;
  }

  int k = 0;
  for (; k <= n4 - 4; k += 4) {
    v128_t va = wasm_v128_load(&re[k]);
    v128_t vb = wasm_v128_load(&im[k]);
    v128_t tw_r = wasm_v128_load(&mdct_tw_re[k]);
    v128_t tw_i = wasm_v128_load(&mdct_tw_im[k]);

    v128_t r_out = wasm_f32x4_add(wasm_f32x4_mul(va, tw_r), wasm_f32x4_mul(vb, tw_i));
    v128_t i_out = wasm_f32x4_sub(wasm_f32x4_mul(vb, tw_r), wasm_f32x4_mul(va, tw_i));
    wasm_v128_store(&re[k], r_out);
    wasm_v128_store(&im[k], i_out);
  }
  for (; k < n4; k++) {
    float tw_r = mdct_tw_re[k], tw_i = mdct_tw_im[k];
    float a = re[k], b = im[k];
    re[k] = a * tw_r + b * tw_i;
    im[k] = b * tw_r - a * tw_i;
  }

  aac_fft_forward_wasm(re, im, n4);

  /* Post-rotation + interleave */
  k = 0;
  for (; k <= n4 - 4; k += 4) {
    v128_t vr = wasm_v128_load(&re[k]);
    v128_t vi = wasm_v128_load(&im[k]);
    v128_t tw_r = wasm_v128_load(&mdct_tw_re[k]);
    v128_t tw_i = wasm_v128_load(&mdct_tw_im[k]);

    v128_t o0 = wasm_f32x4_add(wasm_f32x4_mul(vr, tw_r), wasm_f32x4_mul(vi, tw_i));
    v128_t o1 = wasm_f32x4_sub(wasm_f32x4_mul(vi, tw_r), wasm_f32x4_mul(vr, tw_i));

    /* Manual interleave for WASM (load/store pairs) */
    float tmp0[4], tmp1[4];
    wasm_v128_store(tmp0, o0);
    wasm_v128_store(tmp1, o1);
    for (int j = 0; j < 4; j++) {
      out[2 * (k + j)] = tmp0[j];
      out[2 * (k + j) + 1] = tmp1[j];
    }
  }
  for (; k < n4; k++) {
    float tw_r = mdct_tw_re[k], tw_i = mdct_tw_im[k];
    out[2 * k] = re[k] * tw_r + im[k] * tw_i;
    out[2 * k + 1] = im[k] * tw_r - re[k] * tw_i;
  }

  v128_t vscale = wasm_f32x4_splat(2.0f / (float)n);
  k = 0;
  int nn4 = n2 & ~3;
  for (; k < nn4; k += 4) wasm_v128_store(&out[k], wasm_f32x4_mul(wasm_v128_load(&out[k]), vscale));
  for (; k < n2; k++) out[k] *= 2.0f / (float)n;
}

/* ── IMDCT Half (calls WASM FFT) ───────────────────────────────
 * Same twiddle precompute as forward. Pre- and post-rotation follow the
 * established pattern. WASM has no FMA yet, so we use mul+add.
 */

static void aac_imdct_half_wasm(float* out, const float* in, int n, const float* win) {
  int n2 = n >> 1, n4 = n >> 2;
  static thread_local float s_re[512], s_im[512];
  static thread_local float mdct_tw_re[256], mdct_tw_im[256];
  float* re = s_re;
  float* im = s_im;
  memset(re, 0, n4 * sizeof(float));
  memset(im, 0, n4 * sizeof(float));

  /* Precompute MDCT twiddles once */
  for (int k = 0; k < n4; k++) {
    float ang = (float)M_PI * (k + 0.125f) / (float)n4;
    mdct_tw_re[k] = cosf(ang);
    mdct_tw_im[k] = sinf(ang);
  }

  /* Pre-rotation */
  for (int k = 0; k < n4; k++) {
    float tw_r = mdct_tw_re[k], tw_i = mdct_tw_im[k];
    float rin = in[2 * k], iin = in[2 * k + 1];
    re[k] = rin * tw_r + iin * tw_i;
    im[k] = iin * tw_r - rin * tw_i;
  }

  aac_fft_inverse_wasm(re, im, n4);

  /* Post-rotation + window */
  for (int k = 0; k < n4; k++) {
    float tw_r = mdct_tw_re[k], tw_i = mdct_tw_im[k];
    float a = re[k] * tw_r + im[k] * tw_i;
    float b = re[k] * tw_i - im[k] * tw_r;
    out[k] = a * win[k];
    out[n4 + k] = b * win[n4 + k];
    out[n2 + k] = -b * win[n2 + k];
    out[n2 + n4 + k] = -a * win[n2 + n4 + k];
  }
}

/* ── Registration ────────────────────────────────────────────── */

void aac_dsp_init_wasm(AacDSP* dsp) {
  dsp->fft_forward = aac_fft_forward_wasm;
  dsp->fft_inverse = aac_fft_inverse_wasm;
  dsp->mdct_forward = aac_mdct_forward_wasm;
  dsp->imdct_half = aac_imdct_half_wasm;
  dsp->vector_fmul = aac_vector_fmul_wasm;
  dsp->vector_fmul_scalar = aac_vector_fmul_scalar_wasm;
  dsp->vector_fmul_add = aac_vector_fmul_add_wasm;
  dsp->vector_fmul_window = aac_vector_fmul_window_wasm;
  dsp->vector_fmul_reverse = aac_vector_fmul_reverse_wasm;
  dsp->vector_fmul_accumulate = aac_vector_fmul_accumulate_wasm;
}

#endif /* BAAC_AAC_WASM || __wasm_simd128__ */
