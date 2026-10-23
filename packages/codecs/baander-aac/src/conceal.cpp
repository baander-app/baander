#include "conceal.h"

#include <cmath>
#include <cstring>

#include "mdct.h"

namespace {

constexpr float kStepGain = 0.70710678118654752f; /* -3 dB per concealed frame */
constexpr float kSilence = 1e-4f;                 /* -80 dB: the fade is complete */

unsigned next_random(unsigned* state) {
  *state = *state * 1664525u + 1013904223u;
  return *state;
}

/* The window sequence that keeps the overlap-add consistent after the
 * given one: a start window ends in a short slope that only a stop window
 * or short windows continue, and the spectrum stays long. */
int next_sequence(int seq) {
  switch (seq) {
    case AAC_WIN_LONG_START:
      return AAC_WIN_LONG_STOP;
    case AAC_WIN_EIGHT_SHORT:
      return AAC_WIN_EIGHT_SHORT;
    default:
      return AAC_WIN_ONLY_LONG;
  }
}

/* Replaces the stored spectrum's bands by noise of the same energy, band
 * by band and window by window over the stored scalefactor band layout. */
void substitute_noise(const AacConcealChannel* c, unsigned* random_state, float gain,
                      float* coef) {
  const AacIcsInfo* ics = &c->ics;
  const int bands = ics->max_sfb < ics->num_swb ? ics->max_sfb : ics->num_swb;
  memset(coef, 0, 1024 * sizeof(float));
  for (int w = 0; w < ics->num_windows; w++) {
    const float* src = c->coef + w * 128;
    float* dst = coef + w * 128;
    for (int b = 0; b < bands; b++) {
      const int start = ics->swb_offset[b];
      const int len = ics->swb_offset[b + 1] - start;
      float energy = 0.0f;
      for (int k = 0; k < len; k++) {
        energy += src[start + k] * src[start + k];
      }
      if (energy <= 0.0f) {
        continue;
      }
      float noise_energy = 0.0f;
      for (int k = 0; k < len; k++) {
        dst[start + k] = (float)(int)next_random(random_state);
        noise_energy += dst[start + k] * dst[start + k];
      }
      const float scale = gain * sqrtf(energy / noise_energy);
      for (int k = 0; k < len; k++) {
        dst[start + k] *= scale;
      }
    }
  }
}

}  // namespace

void aac_conceal_reset(AacConcealChannel* c) {
  c->valid = 0;
  c->count = 0;
  c->gain = kStepGain;
}

void aac_conceal_store(AacConcealChannel* c, const AacIcsInfo* ics, const float* coef) {
  c->ics = *ics;
  memcpy(c->coef, coef, sizeof(c->coef));
  c->valid = 1;
}

void aac_conceal_recovered(AacConcealChannel* c) {
  c->count = 0;
  c->gain = kStepGain;
}

void aac_conceal_spectrum(AacConcealChannel* c, int mode, unsigned* random_state,
                          AacIcsInfo* ics, float* coef) {
  if (!c->valid) {
    /* Nothing to continue: silence through the filterbank, so that the
     * overlap stays in step. */
    aac_ics_layout(ics, 0, AAC_WIN_ONLY_LONG, 0);
    ics->window_shape = AAC_WIN_SINE;
    ics->max_sfb = 0;
    memset(coef, 0, 1024 * sizeof(float));
    c->count++;
    return;
  }
  c->ics.window_sequence = next_sequence(c->ics.window_sequence);
  *ics = c->ics;
  const float gain = c->gain;
  if (gain < kSilence) {
    memset(coef, 0, 1024 * sizeof(float));
  } else if (mode == AAC_CONCEAL_NOISE || c->count >= AAC_CONCEAL_FADE_FRAMES) {
    substitute_noise(c, random_state, gain, coef);
  } else {
    /* The previous spectrum at a random sign per coefficient: the same
     * spectral envelope, decorrelated in time. */
    for (int k = 0; k < 1024; k++) {
      const float sign = (next_random(random_state) & 0x80000000u) ? -gain : gain;
      coef[k] = c->coef[k] * sign;
    }
  }
  c->gain = gain * kStepGain;
  c->count++;
}
