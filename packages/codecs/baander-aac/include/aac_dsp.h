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
  /* ── FFT ─────────────────────────────────────────────────────── */
  void (*fft_forward)(float* re, float* im, int n);
  void (*fft_inverse)(float* re, float* im, int n);

  /* ── MDCT ────────────────────────────────────────────────────── */
  void (*mdct_forward)(float* out, const float* in, int n, const float* win);
  void (*imdct_half)(float* out, const float* in, int n, const float* win);

  /* ── Vector Operations ───────────────────────────────────────── */
  void (*vector_fmul)(float* dst, const float* a, const float* b, int len);
  void (*vector_fmul_scalar)(float* dst, const float* a, float scale, int len);
  void (*vector_fmul_add)(float* dst, const float* a, const float* b, const float* c, int len);
  void (*vector_fmul_window)(float* dst, const float* a, const float* b, const float* win, int n);
  void (*vector_fmul_reverse)(float* dst, const float* a, const float* b, int len);
  void (*vector_fmul_accumulate)(float* dst, const float* a, const float* b, int len);

  /* ── Huffman Decode ──────────────────────────────────────────── */
  int (*huffman_decode)(const uint8_t* data, int bit_pos, int codebook, int* x, int* y,
                        int* bits_used);

  /* ── SBR QMF ─────────────────────────────────────────────────── */
  void (*sbr_qmf_analysis)(float* out, const float* in, int bands);
  void (*sbr_qmf_synthesis)(float* out, const float* in, int bands);

  /* ── Psycho Spreading ────────────────────────────────────────── */
  void (*psycho_spreading)(float* spread, const float* energy, const float* threshold, int n_sfb);
};

/* Initialize DSP struct with scalar C defaults, then override per CPU flags */
void aac_dsp_init(AacDSP* dsp);

/* Platform-specific init functions (called internally by aac_dsp_init) */
void aac_dsp_init_sse2(AacDSP* dsp);
void aac_dsp_init_avx2(AacDSP* dsp);
void aac_dsp_init_neon(AacDSP* dsp);
void aac_dsp_init_wasm(AacDSP* dsp);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_DSP_H */
