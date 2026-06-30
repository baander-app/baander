#ifndef BAANDER_AAC_MDCT_H
#define BAANDER_AAC_MDCT_H

#include <cstdint>

#include "aac_dsp.h"
#include "aac_tables.h"

#ifdef __cplusplus
extern "C" {
#endif

/* AAC_FRAME_SIZE_LONG/SHORT defined in aac_tables.h — do not re-define */

using AacWindowSequence = enum AacWindowSequence_ {
  AAC_WIN_ONLY_LONG = 0,
  AAC_WIN_LONG_START = 1,
  AAC_WIN_EIGHT_SHORT = 2,
  AAC_WIN_LONG_STOP = 3,
};

using AacWindowShape = enum AacWindowShape_ {
  AAC_WIN_SINE = 0,
  AAC_WIN_KBD = 1,
};

using AacMdctContext = struct AacMdctContext_ {
  int frame_size_long;
  int frame_size_short;
  float* overlap_long;      /* Forward MDCT overlap (N samples) */
  float* overlap_save_long; /* Inverse MDCT overlap (N/2 samples) */
  float* overlap_short[8];
  float* window_sine_long;
  float* window_kbd_long;
  float* window_sine_short;
  float* window_kbd_short;
  /* Pre-allocated scratch buffers (avoid per-frame heap alloc) */
  float* scratch_re;   /* max(frame_size_long/4) */
  float* scratch_im;   /* max(frame_size_long/4) */
  float* scratch_tmp;  /* max(frame_size_long) */
  float* scratch_tmp2; /* max(2 * frame_size_short) for EIGHT_SHORT */
  AacWindowSequence prev_win_seq;
  AacWindowShape prev_win_shape;
  const AacDSP* dsp;

  /* Precomputed MDCT twiddles for the two supported block sizes.
   * These are filled once in aac_mdct_init to avoid repeated trig per frame.
   * long:  size = frame_size_long / 4
   * short: size = frame_size_short / 4
   */
  float* mdct_tw_re_long;
  float* mdct_tw_im_long;
  float* mdct_tw_re_short;
  float* mdct_tw_im_short;
};

void aac_mdct_init(AacMdctContext* ctx, int frame_size_long, const AacDSP* dsp);
void aac_mdct_free(AacMdctContext* ctx);
void aac_mdct_forward_c(float* out, const float* in, int n, const float* win);
/**
 * Output contract for aac_mdct_forward_* and aac_mdct_forward_with_twiddles:
 * Only the first n/2 floats of `out` are written (interleaved lower bins after
 * post-rotation + scaling). The upper half is left untouched.
 * Use aac_mdct_forward_with_twiddles when you have precomputed twiddles from
 * an AacMdctContext.
 */
void aac_mdct_forward_aac(AacMdctContext* ctx, float* out, const float* in, int n,
                          AacWindowSequence win_seq, AacWindowShape win_shape, int channel);
void aac_imdct_half_c(float* out, const float* in, int n, const float* win);

/* Internal versions that can use caller-provided precomputed twiddles.
 * If tw_re/tw_im are NULL, they fall back to computing locally (same behavior as _c versions).
 * These enable hoisting the trig cost out of the per-frame hot path.
 */
void aac_mdct_forward_with_twiddles(float* out, const float* in, int n, const float* win,
                                    const float* tw_re, const float* tw_im);
void aac_imdct_half_with_twiddles(float* out, const float* in, int n, const float* win,
                                  const float* tw_re, const float* tw_im);
void aac_imdct(AacMdctContext* ctx, float* out, const float* spectral, int n,
               AacWindowSequence win_seq, AacWindowShape win_shape, int channel);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_MDCT_H */
