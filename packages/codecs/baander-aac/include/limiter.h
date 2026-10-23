#ifndef BAANDER_AAC_LIMITER_H
#define BAANDER_AAC_LIMITER_H

#include <cstdint>

#ifdef __cplusplus
extern "C" {
#endif

/* Look-ahead peak limiter on interleaved PCM at full scale 1.0, in the
 * manner of the MPEG-D DRC peak limiter (ISO/IEC 23003-4): the output is
 * delayed by the attack window, the largest magnitude over that window
 * sets the gain that keeps every channel within full scale, the gain
 * ramps down linearly over the attack window so that it has reached the
 * needed value when the peak comes out, and recovers exponentially (a
 * fixed number of decibels per sample) afterwards.  The same gain is
 * applied to all channels.  Everything lives in the struct: no memory is
 * allocated after initialisation. */

#define AAC_LIMITER_MAX_CHANNELS 8
/* Longest attack window in frames: 85 ms at 96 kHz. */
#define AAC_LIMITER_MAX_LOOKAHEAD 8192

using AacLimiter = struct AacLimiter_ {
  int channels;
  int sample_rate;
  int attack_ms, release_ms;
  int lookahead;    /* frames of delay: the attack window */
  float release_up; /* factor the gain grows by per frame while recovering */
  /* Delay line of the input, interleaved, and the gain each delayed frame
   * needs; both are rings of `lookahead` + 1 frames indexed by `pos`. */
  float delay[(AAC_LIMITER_MAX_LOOKAHEAD + 1) * AAC_LIMITER_MAX_CHANNELS];
  float need[AAC_LIMITER_MAX_LOOKAHEAD + 1];
  /* Sliding minimum of `need` over the window: a monotone queue of ring
   * positions with increasing gains, the front being the minimum. */
  int queue[AAC_LIMITER_MAX_LOOKAHEAD + 1];
  int queue_head, queue_len;
  /* The window minimum of each of the last `lookahead` + 1 frames and
   * their running sum, for the linear ramp (a moving average). */
  float window_min[AAC_LIMITER_MAX_LOOKAHEAD + 1];
  double window_sum;
  int pos;
  float gain; /* gain applied to the last output frame */
};

/* Configures the limiter for `channels` (1..AAC_LIMITER_MAX_CHANNELS)
 * at `sample_rate`: attack_ms of look-ahead (at least one frame, at most
 * AAC_LIMITER_MAX_LOOKAHEAD frames) and release_ms, the time the gain
 * takes to recover from a 20 dB reduction.  Returns AAC_OK or
 * AAC_ERR_INVALID_ARG. */
int aac_limiter_init(AacLimiter* l, int channels, int sample_rate, int attack_ms, int release_ms);

/* Limits `frames` interleaved frames in place; the output is the input
 * delayed by aac_limiter_delay frames, scaled so that no sample exceeds
 * full scale, and samples that never needed a reduction pass unchanged. */
void aac_limiter_process(AacLimiter* l, float* interleaved, int frames);

/* Clears the delay line and gain history. */
void aac_limiter_reset(AacLimiter* l);

/* Delay of the output in frames: the attack window. */
int aac_limiter_delay(const AacLimiter* l);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_LIMITER_H */
