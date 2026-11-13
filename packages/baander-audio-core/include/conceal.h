#ifndef BAANDER_AAC_CONCEAL_H
#define BAANDER_AAC_CONCEAL_H
#include "aac.h"
#include "ics.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Error concealment of one channel's spectrum.  ISO/IEC 14496-3 leaves
 * the method to the decoder; this one keeps the spectrum of the last good
 * frame and, for a damaged or lost frame, plays it on with a random sign
 * per coefficient and 3 dB less level per frame, then (after five frames,
 * or at once in noise mode) replaces it by noise with the same band
 * energies, still fading, until it is silent.  The window sequence follows
 * the last good frame so that the overlap-add of the filterbank stays
 * consistent: a start window is followed by a stop window, short windows
 * stay short. */

#define AAC_CONCEAL_FADE_FRAMES 5

using AacConcealChannel = struct AacConcealChannel_ {
  int valid;        /* a good frame's spectrum is stored */
  int count;        /* concealed frames since the last good one */
  float gain;       /* level of the next concealed frame relative to the stored one */
  AacIcsInfo ics;   /* window sequence, shape and band layout of the stored spectrum */
  float coef[1024]; /* the spectrum after the stereo tools and before TNS */
};

void aac_conceal_reset(AacConcealChannel* c);

/* Remembers the spectrum and ics_info of a good frame. */
void aac_conceal_store(AacConcealChannel* c, const AacIcsInfo* ics, const float* coef);

/* Marks the channel as recovered after a good frame was output. */
void aac_conceal_recovered(AacConcealChannel* c);

/* Builds the spectrum of the next concealed frame in `coef` with the
 * ics_info it must be windowed with (TNS off) and advances the fade;
 * `mode` is AAC_CONCEAL_NOISE or AAC_CONCEAL_INTERPOLATE.  Without a
 * stored frame the spectrum is silent. */
void aac_conceal_spectrum(AacConcealChannel* c, int mode, int frame_length,
                          unsigned* random_state, AacIcsInfo* ics, float* coef);

#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_CONCEAL_H */
