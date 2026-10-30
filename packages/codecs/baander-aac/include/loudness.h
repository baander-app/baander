#ifndef BAANDER_AAC_LOUDNESS_H
#define BAANDER_AAC_LOUDNESS_H

#include "aac.h"
#include "bitstream.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Loudness measurement per ITU-R BS.1770-4 and EBU Tech 3341/3342, and
 * the loudnessInfoSet() of ISO/IEC 23003-4 that carries the result in a
 * USAC configuration extension (ID_CONFIG_EXT_LOUDNESS_INFO).
 *
 * The meter runs in one pass over interleaved float input at full scale
 * 1.0: every channel goes through the K-weighting (the high shelf and
 * the high-pass of BS.1770-4 clause 2, as two biquads whose coefficients
 * are derived for the sample rate), the mean square of each channel is
 * taken over 400 ms blocks with 75 % overlap (a block every 100 ms),
 * and the blocks are gated at the end: the absolute gate at -70 LKFS and
 * the relative gate 10 LU below the mean of the blocks above it.  The
 * channel weights follow the canonical order L, R, C, LFE, Ls, Rs: the
 * front channels count once, the LFE not at all and the surrounds at
 * +1.5 dB; a channel count of 1 to 6 takes that order's prefix.  The
 * loudness range follows EBU Tech 3342 (3 s windows every 100 ms, the
 * absolute gate at -70 LUFS, the relative gate 20 LU below the mean, the
 * spread between the 10th and the 95th percentile), the maximum
 * momentary and short-term loudness EBU Tech 3341 (ungated), and the
 * true peak BS.1770-4 Annex 2 (4 times oversampled below 96 kHz, twice
 * from 96 kHz, as is from 192 kHz). */

typedef struct AacLoudnessMeter_ AacLoudnessMeter;

/* channels 1..6; NULL for other arguments or out of memory. */
AacLoudnessMeter* aac_loudness_create(int sample_rate, int channels);
void aac_loudness_destroy(AacLoudnessMeter* m);
void aac_loudness_reset(AacLoudnessMeter* m);
/* Interleaved frames at full scale 1.0, in any chunking. */
void aac_loudness_feed(AacLoudnessMeter* m, const float* interleaved, int frames);

/* No level or peak is reported below this: digital silence, and a
 * loudness with no block above the absolute gate, read exactly it. */
#define AAC_LOUDNESS_SILENCE (-150.0f)

using AacLoudnessInfo = struct AacLoudnessInfo_ {
  float sample_peak_dbfs;
  float true_peak_dbtp;  /* never below the sample peak */
  float integrated_lkfs; /* AAC_LOUDNESS_SILENCE when no block passes the absolute gate */
  float range_lu;        /* 0 when fewer than two windows pass the gates */
  float anchor_lkfs;     /* caller-supplied; the meter leaves it 0 with has_anchor 0 */
  int has_anchor, has_range, has_true_peak;
  float max_momentary_lkfs, max_short_term_lkfs; /* EBU Tech 3341, ungated */
  int has_momentary, has_short_term;             /* a window of 400 ms / 3 s exists */
};

/* AAC_OK, or AAC_ERR_STATE when fewer than 400 ms were fed (the result
 * needs one block).  The range and the short-term maximum need 3 s;
 * before that has_range and has_short_term are 0. */
int aac_loudness_result(const AacLoudnessMeter* m, AacLoudnessInfo* out);

/* loudnessInfoSet() of ISO/IEC 23003-4 clause 5.2 (Tables 57 and 58)
 * for a single program: loudnessInfoAlbumCount 0, loudnessInfoCount 1
 * and one loudnessInfo() with drcSetId 0 and downmixId 0 (the signal as
 * coded, without DRC or downmix).  The entry carries samplePeakLevel and,
 * with has_true_peak, truePeakLevel (12-bit codes in 0.03125 dB steps
 * down from +20 dBFS), then the measurements in this order: program
 * loudness (methodDefinition 1), anchor loudness (2) with has_anchor,
 * loudness range (6) with has_range, maximum momentary loudness (4) with
 * has_momentary and maximum short-term loudness (5) with has_short_term.
 * Every level is coded in
 * 0.25 dB steps from -57.75 dB, the range in the three-segment scale the
 * standard gives bsMethodValue of methodDefinition 6 (0.25 LU steps to
 * 32 LU, 0.5 LU steps to 70 LU, 1 LU steps to 121 LU); the measurement
 * system is BS.1770-4 (2), the reliability "accurate" (3).  Values
 * outside the coded ranges clamp.  No extension follows
 * (loudnessInfoSetExtPresent 0), and the writer stops at the last bit:
 * the configuration extension pads the payload to whole bytes.
 * Returns the bits written, or AAC_ERR_INVALID_ARG / AAC_ERR_OVERFLOW. */
int aac_loudness_info_write(AacBitWriter* w, const AacLoudnessInfo* info);
/* The bits aac_loudness_info_write writes for `info`. */
int aac_loudness_info_bits(const AacLoudnessInfo* info);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_LOUDNESS_H */
