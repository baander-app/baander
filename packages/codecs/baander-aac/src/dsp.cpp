#include <cstring>

#include "aac_cpu.h"
#include "aac_dsp.h"

/* ── Scalar vector operation defaults ─────────────────────────────── */

static void aac_vector_fmul_c(float* dst, const float* a, const float* b, int len) {
  for (int i = 0; i < len; i++) {
    dst[i] = a[i] * b[i];
  }
}
static void aac_vector_fmul_scalar_c(float* dst, const float* a, float scale, int len) {
  for (int i = 0; i < len; i++) {
    dst[i] = a[i] * scale;
  }
}
static void aac_vector_fmul_add_c(float* dst, const float* a, const float* b, const float* c,
                                  int len) {
  for (int i = 0; i < len; i++) {
    dst[i] = a[i] * b[i] + c[i];
  }
}
static void aac_vector_fmul_window_c(float* dst, const float* a, const float* b, const float* win,
                                     int n) {
  for (int i = 0; i < n; i++) {
    dst[i] = a[i] * win[i] + b[i] * win[n - 1 - i];
  }
}
static void aac_vector_fmul_reverse_c(float* dst, const float* a, const float* b, int len) {
  for (int i = 0; i < len; i++) {
    dst[i] = a[i] * b[len - 1 - i];
  }
}
static void aac_vector_fmul_accumulate_c(float* dst, const float* a, const float* b, int len) {
  for (int i = 0; i < len; i++) {
    dst[i] += a[i] * b[i];
  }
}

/* ── Scalar transform kernels ─────────────────────────────────────── */

static void aac_fft_radix2_stage_c(float* re, float* im, int n, int half, const float* wr,
                                   const float* wi) {
  for (int start = 0; start < n; start += 2 * half) {
    for (int j = 0; j < half; j++) {
      const int a = start + j;
      const int b = a + half;
      const float tr = re[b] * wr[j] - im[b] * wi[j];
      const float ti = re[b] * wi[j] + im[b] * wr[j];
      re[b] = re[a] - tr;
      im[b] = im[a] - ti;
      re[a] += tr;
      im[a] += ti;
    }
  }
}

static void aac_mdct_pre_twiddle_c(float* re, float* im, const float* in, const float* c,
                                   const float* s, int half) {
  const int quarter = half / 2;
  for (int j = 0; j < quarter; j++) {
    const float a = in[2 * j];
    const float b = in[half - 1 - 2 * j];
    re[j] = a * c[j] - b * s[j];
    im[j] = a * s[j] + b * c[j];
  }
}

static void aac_mdct_post_twiddle_c(float* out, const float* re, const float* im, const float* c,
                                    const float* s, int half) {
  const int quarter = half / 2;
  for (int j = 0; j < quarter; j++) {
    const float yr = re[j] * c[j] - im[j] * s[j];
    const float yi = re[j] * s[j] + im[j] * c[j];
    out[2 * j] = yr;
    out[half - 1 - 2 * j] = -yi;
  }
}

/* ── DSP Init: wire all scalar defaults + platform overrides ────── */

void aac_dsp_init(AacDSP* dsp) {
  dsp->vector_fmul = aac_vector_fmul_c;
  dsp->vector_fmul_scalar = aac_vector_fmul_scalar_c;
  dsp->vector_fmul_add = aac_vector_fmul_add_c;
  dsp->vector_fmul_window = aac_vector_fmul_window_c;
  dsp->vector_fmul_reverse = aac_vector_fmul_reverse_c;
  dsp->vector_fmul_accumulate = aac_vector_fmul_accumulate_c;
  dsp->fft_radix2_stage = aac_fft_radix2_stage_c;
  dsp->mdct_pre_twiddle = aac_mdct_pre_twiddle_c;
  dsp->mdct_post_twiddle = aac_mdct_post_twiddle_c;

  int flags = aac_get_cpu_flags();
#if defined(BAAC_AAC_SSE2)
  if (flags & AAC_CPU_FLAG_SSE2) {
    aac_dsp_init_sse2(dsp);
  }
#endif
#if defined(BAAC_AAC_AVX2)
  if (flags & AAC_CPU_FLAG_AVX2) {
    aac_dsp_init_avx2(dsp);
  }
#endif
#if defined(BAAC_AAC_NEON)
  if (flags & AAC_CPU_FLAG_NEON) aac_dsp_init_neon(dsp);
#endif
  (void)flags;
}

const AacDSP* aac_dsp_global(void) {
  static const AacDSP table = [] {
    AacDSP d;
    aac_dsp_init(&d);
    return d;
  }();
  return &table;
}
