#ifndef BAANDER_AAC_DSP_H
#define BAANDER_AAC_DSP_H

#include <cstdint>

#ifdef __cplusplus
extern "C" {
#endif

/*
 * AacDSP — Function pointer struct for all hot-path DSP operations.
 *
 * Follows FFmpeg's AVFloatDSPContext pattern:
 *   1. aac_dsp_init() fills with scalar C defaults
 *   2. Platform-specific init functions override pointers per CPU flags
 *   3. Hot-path code calls through function pointers — zero branching at call site
 */
using AacDSP = struct AacDSP_ {
  /* ── Vector Operations ───────────────────────────────────────── */
  void (*vector_fmul)(float* dst, const float* a, const float* b, int len);
  void (*vector_fmul_scalar)(float* dst, const float* a, float scale, int len);
  void (*vector_fmul_add)(float* dst, const float* a, const float* b, const float* c, int len);
  void (*vector_fmul_window)(float* dst, const float* a, const float* b, const float* win, int n);
  void (*vector_fmul_reverse)(float* dst, const float* a, const float* b, int len);
  void (*vector_fmul_accumulate)(float* dst, const float* a, const float* b, int len);
};

/* Initialize DSP struct with scalar C defaults, then override per CPU flags */
void aac_dsp_init(AacDSP* dsp);

/* Platform-specific init functions (called internally by aac_dsp_init) */
void aac_dsp_init_sse2(AacDSP* dsp);
void aac_dsp_init_avx2(AacDSP* dsp);
void aac_dsp_init_neon(AacDSP* dsp);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_DSP_H */
