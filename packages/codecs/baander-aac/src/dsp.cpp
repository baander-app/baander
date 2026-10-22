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

/* ── DSP Init: wire all scalar defaults + platform overrides ────── */

void aac_dsp_init(AacDSP* dsp) {
  dsp->vector_fmul = aac_vector_fmul_c;
  dsp->vector_fmul_scalar = aac_vector_fmul_scalar_c;
  dsp->vector_fmul_add = aac_vector_fmul_add_c;
  dsp->vector_fmul_window = aac_vector_fmul_window_c;
  dsp->vector_fmul_reverse = aac_vector_fmul_reverse_c;
  dsp->vector_fmul_accumulate = aac_vector_fmul_accumulate_c;

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
