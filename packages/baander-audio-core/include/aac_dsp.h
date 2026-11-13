#ifndef BAANDER_AUDIO_CORE_DSP_H
#define BAANDER_AUDIO_CORE_DSP_H

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

  /* ── Transform kernels ──────────────────────────────────────── */
  /* One radix-2 stage of an n-point FFT over blocks of 2 * half points:
   * for every block and j < half, with a the j-th point of the block,
   * b = a + half and w = (wr[j], wi[j]),
   *   t = w x[b];  x[b] = x[a] - t;  x[a] = x[a] + t.
   * Every level computes tr = re[b] wr - im[b] wi and ti = re[b] wi +
   * im[b] wr as separate products and sums (no fused multiply-add), so
   * that the scalar and the SIMD kernels agree bit for bit and the
   * encoder's output does not depend on the processor. */
  void (*fft_radix2_stage)(float* re, float* im, int n, int half, const float* wr,
                           const float* wi);
  /* Twiddles that fold a DCT-IV of `half` points into an FFT of half / 2
   * points.  Pre: re[j] = in[2j] c[j] - in[half-1-2j] s[j], im[j] = in[2j]
   * s[j] + in[half-1-2j] c[j].  Post: out[2j] = re[j] c[j] - im[j] s[j],
   * out[half-1-2j] = -(re[j] s[j] + im[j] c[j]).  Same bit-exactness rule
   * as the FFT stage; half is even (the 240-point transform of a 960
   * frame's short window gives 60 pairs, so the kernels keep a tail). */
  void (*mdct_pre_twiddle)(float* re, float* im, const float* in, const float* c, const float* s,
                           int half);
  void (*mdct_post_twiddle)(float* out, const float* re, const float* im, const float* c,
                            const float* s, int half);

  /* ── Encoder kernels ────────────────────────────────────────── */
  /* The noise of a scalefactor band quantized with step `scale`, over
   * `windows` spans of `len` lines that lie `stride` floats apart in coef
   * and in x34 (|coef|^(3/4)).  Each line takes
   *   v = x34[i] * scale + round, clamped to [0, 8191] (8191 for a NaN),
   *   q = (int) v,  d = |coef[i]| - pow43[q] * gain,
   * and the result is the sum of d^2; pow43 has 8192 entries.  The sum
   * has one order on every level, so that the encoder's choices do not
   * depend on the processor: line i of a span adds into accumulator
   * i % 8, span after span, and the accumulators a0..a7 combine as
   * ((a0 + a4) + (a2 + a6)) + ((a1 + a5) + (a3 + a7)).  Every product and
   * sum is rounded on its own (no fused multiply-add). */
  float (*quant_noise)(const float* coef, const float* x34, int len, int windows, int stride,
                       float scale, float round, float gain, const float* pow43);
};

/* Initialize DSP struct with scalar C defaults, then override per CPU flags */
void aac_dsp_init(AacDSP* dsp);

/* The process-wide table for code without a codec state at hand (the
 * transforms and filterbanks), filled once from the detected CPU flags. */
const AacDSP* aac_dsp_global(void);

/* The scalar radix-2 stage, which the SIMD kernels fall back to for the
 * stages too short for a vector. */
void aac_fft_radix2_stage_c(float* re, float* im, int n, int half, const float* wr,
                            const float* wi);

/* Platform-specific init functions (called internally by aac_dsp_init) */
void aac_dsp_init_sse2(AacDSP* dsp);
void aac_dsp_init_avx2(AacDSP* dsp);
void aac_dsp_init_neon(AacDSP* dsp);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AUDIO_CORE_DSP_H */
