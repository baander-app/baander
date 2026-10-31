#ifndef BAANDER_AAC_LOUDNESS_H
#define BAANDER_AAC_LOUDNESS_H

#include "aac.h"
#include "bitstream.h"

#ifdef __cplusplus
extern "C" {
#endif

/* The loudnessInfoSet() writer of ISO/IEC 23003-4 for the figures of
 * the loudness meter, and the meter's K-weighting filter for the other
 * users of BS.1770-4 levels; the meter itself (aac_loudness_create, _feed,
 * _result and AacLoudnessInfo) is public API in aac.h.  The meter runs
 * in one pass: every channel goes through the K-weighting (the high
 * shelf and the high-pass of BS.1770-4 clause 2, as two biquads whose
 * coefficients are derived for the sample rate), the mean square of
 * each channel is taken over 400 ms blocks with 75 % overlap (a block
 * every 100 ms), and the blocks are gated at the end: the absolute gate
 * at -70 LKFS and the relative gate 10 LU below the mean of the blocks
 * above it.  The channel weights follow the canonical order L, R, C,
 * LFE, Ls, Rs: the front channels count once, the LFE not at all and
 * the surrounds at +1.5 dB; a channel count of 1 to 6 takes that
 * order's prefix.  The loudness range follows EBU Tech 3342 (3 s windows
 * every 100 ms, the absolute gate at -70 LUFS, the relative gate 20 LU
 * below the mean, the spread between the 10th and the 95th percentile),
 * the maximum momentary and short-term loudness EBU Tech 3341
 * (ungated), and the true peak BS.1770-4 Annex 2 (4 times oversampled
 * below 96 kHz, twice from 96 kHz, as is from 192 kHz). */

/* The K-weighting of BS.1770-4 clause 2, which the meter and the MPEG-D
 * DRC gain generator share: a high shelf and a high-pass as two biquads
 * whose coefficients are derived for the sample rate.  Each biquad's
 * coefficients are b0, b1, b2, a1, a2 (a0 normalised to 1). */
using AacKWeighting = struct AacKWeighting_ {
  double shelf[5];
  double highpass[5];
};

/* One channel's filter state: the two delays of each biquad; all zero
 * is the filter at rest. */
using AacKWeightingState = struct AacKWeightingState_ {
  double shelf[2];
  double highpass[2];
};

void aac_k_weighting_init(AacKWeighting* k, int sample_rate);

/* One biquad in direct form II transposed. */
static inline double aac_k_weighting_biquad_(const double* b, double* z, double x) {
  const double y = b[0] * x + z[0];
  z[0] = b[1] * x - b[3] * y + z[1];
  z[1] = b[2] * x - b[4] * y;
  return y;
}

/* Filters one sample: the shelf, then the high-pass. */
static inline double aac_k_weighting_run(const AacKWeighting* k, AacKWeightingState* s, double x) {
  return aac_k_weighting_biquad_(k->highpass, s->highpass,
                                 aac_k_weighting_biquad_(k->shelf, s->shelf, x));
}

/* loudnessInfoSet() of ISO/IEC 23003-4 clause 5.2 (Tables 57 and 58)
 * with loudnessInfoAlbumCount 0 and `count` loudnessInfo() entries (0 to
 * 63): entry i describes the output of the DRC set drc_set_ids[i] (0 to
 * 63; drcSetId 0 is the signal as coded, without DRC) on the base layout
 * (downmixId 0) with the figures of info[i].  Each entry carries
 * samplePeakLevel and, with has_true_peak, truePeakLevel (12-bit codes in
 * 0.03125 dB steps down from +20 dBFS), then the measurements in this
 * order: program loudness (methodDefinition 1), anchor loudness (2) with
 * has_anchor, loudness range (6) with has_range, maximum momentary
 * loudness (4) with has_momentary and maximum short-term loudness (5)
 * with has_short_term.  Every level is coded in 0.25 dB steps from
 * -57.75 dB, the range in the three-segment scale the standard gives
 * bsMethodValue of methodDefinition 6 (0.25 LU steps to 32 LU, 0.5 LU
 * steps to 70 LU, 1 LU steps to 121 LU); the measurement system is
 * BS.1770-4 (2), the reliability "accurate" (3).  Values outside the
 * coded ranges clamp.  No extension follows (loudnessInfoSetExtPresent
 * 0), and the writer stops at the last bit: the configuration extension
 * pads the payload to whole bytes.  Returns the bits written, or
 * AAC_ERR_INVALID_ARG / AAC_ERR_OVERFLOW. */
int aac_loudness_info_set_write(AacBitWriter* w, const AacLoudnessInfo* info,
                                const int* drc_set_ids, int count);
/* The bits aac_loudness_info_set_write writes for the same arguments, or
 * AAC_ERR_INVALID_ARG. */
int aac_loudness_info_set_bits(const AacLoudnessInfo* info, const int* drc_set_ids, int count);

/* The set of a single program: one entry with drcSetId 0. */
int aac_loudness_info_write(AacBitWriter* w, const AacLoudnessInfo* info);
/* The bits aac_loudness_info_write writes for `info`. */
int aac_loudness_info_bits(const AacLoudnessInfo* info);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_LOUDNESS_H */
