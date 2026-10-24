#ifndef BAANDER_AAC_LOWDELAY_H
#define BAANDER_AAC_LOWDELAY_H

#include "mdct.h"

#ifdef __cplusplus
extern "C" {
#endif

/* The AAC-ELD low-delay filterbank (ISO/IEC 14496-3 4.6.20).

   A frame of M spectral lines (512 or 480) is transformed to 4 M time
   samples
     s[i] = -2/N sum_{k<M} X[k] cos(2 pi / N (i + n0) (k + 1/2)),  N = 2 M,
     n0 = -M/4 + 1/2,
   windowed by the synthesis window and overlap-added with the three frames
   before it.  The last M/4 window coefficients are zero, so only 15 M / 4
   are kept.

   Downscaled playout decodes the lowest M / F lines of each frame with the
   same transform at M / F lines and a window of M / F * 15 / 4
   coefficients, each the mean of F neighbouring full-rate coefficients
   divided by F; output sample n of a downscaled frame then stands for the
   full-rate position F n + (F - 1) / 2. */

#define AAC_ELD_MAX_FRAME 512
/* Synthesis window coefficients kept per frame length M. */
#define AAC_ELD_WINDOW_LENGTH(m) ((m) * 15 / 4)
/* Overlap history of one channel: the windowed output of the last three
 * frames still to be added, for any frame length up to 512. */
#define AAC_ELD_OVERLAP (3 * AAC_ELD_MAX_FRAME)

extern const float aac_eld_synthesis_window_512[AAC_ELD_WINDOW_LENGTH(512)];
extern const float aac_eld_synthesis_window_480[AAC_ELD_WINDOW_LENGTH(480)];
extern const float aac_eld_analysis_window_512[3 * 512];
extern const float aac_eld_analysis_window_480[3 * 480];

/* The synthesis window for a coded frame length (512 or 480) and a
 * downscale factor (1, 2 or 4), or NULL for other values. */
const float* aac_eld_synthesis_window(int frame_length, int factor);

/* One frame of synthesis: `spec` holds m lines (m = 512, 480 or a
 * downscaled length), `mdct` is an MDCT of window length 2 m, `overlap`
 * the channel's 3 m sample history.  Writes m output samples, scaled by
 * `scale`. */
void aac_eld_synthesis(const AacMdct* mdct, const float* window, int m, const float* spec,
                       float* overlap, float* out, float scale);

/* One frame of analysis: `in` holds the newest 4 m input samples (m = 512
 * or 480), `mdct` is an MDCT of window length 2 m; writes m lines.
 * Synthesis of these lines reproduces the input 1.5 frames later. */
void aac_eld_analysis(const AacMdct* mdct, int m, const float* in, float* spec);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_LOWDELAY_H */
