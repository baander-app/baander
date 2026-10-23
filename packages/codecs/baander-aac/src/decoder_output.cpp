#include "decoder.h"

/* The output limiter runs over the mixed output; its state follows the
 * output format, which changes when a stream switches layout or starts
 * carrying SBR. */

static int output_rate(const AacDecoderState* s) {
  return s->sbr_mode ? 2 * s->sample_rate : s->sample_rate;
}

void aac_decoder_apply_limiter(AacDecoderState* s, float* pcm, int frames, int channels) {
  if (!s->limiter_enabled || frames <= 0 || channels <= 0 ||
      channels > AAC_LIMITER_MAX_CHANNELS) {
    return;
  }
  const int rate = output_rate(s);
  if (!s->limiter_ready || s->limiter_channels != channels || s->limiter_rate != rate) {
    if (aac_limiter_init(&s->limiter, channels, rate, s->limiter_attack_ms,
                         s->limiter_release_ms) != AAC_OK) {
      s->limiter_ready = 0;
      return;
    }
    s->limiter_ready = 1;
    s->limiter_channels = channels;
    s->limiter_rate = rate;
  }
  aac_limiter_process(&s->limiter, pcm, frames);
}

int aac_decoder_limiter_delay(const AacDecoderState* s) {
  if (!s->limiter_enabled) {
    return 0;
  }
  if (s->limiter_ready) {
    return aac_limiter_delay(&s->limiter);
  }
  const long long frames = aac_limiter_lookahead(output_rate(s), s->limiter_attack_ms);
  return frames < 1 ? 1 : (int)frames;
}
