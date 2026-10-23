#include "limiter.h"

#include <cmath>
#include <cstring>

#include "aac.h"

namespace {

constexpr float kThreshold = 1.0f;

/* The gain that keeps a frame with peak magnitude `peak` within full
 * scale, chosen so that the float product never rounds above it. */
float needed_gain(float peak) {
  if (!(peak > kThreshold)) {
    return 1.0f;
  }
  float g = kThreshold / peak;
  while (peak * g > kThreshold) {
    g = std::nextafter(g, 0.0f);
  }
  return g;
}

}  // namespace

int aac_limiter_init(AacLimiter* l, int channels, int sample_rate, int attack_ms, int release_ms) {
  if (!l || channels < 1 || channels > AAC_LIMITER_MAX_CHANNELS || sample_rate <= 0 ||
      attack_ms < 0 || release_ms < 0) {
    return AAC_ERR_INVALID_ARG;
  }
  const long long lookahead = aac_limiter_lookahead(sample_rate, attack_ms);
  if (lookahead > AAC_LIMITER_MAX_LOOKAHEAD) {
    return AAC_ERR_INVALID_ARG;
  }
  l->channels = channels;
  l->sample_rate = sample_rate;
  l->attack_ms = attack_ms;
  l->release_ms = release_ms;
  l->lookahead = lookahead < 1 ? 1 : (int)lookahead;
  /* 20 dB of recovery over the release time, as a factor per frame; a
   * release of zero recovers at once. */
  const double release_frames = (double)release_ms * sample_rate / 1000.0;
  l->release_up = release_frames >= 1.0 ? (float)std::pow(10.0, 1.0 / release_frames) : 10.0f;
  aac_limiter_reset(l);
  return AAC_OK;
}

void aac_limiter_reset(AacLimiter* l) {
  const int window = l->lookahead + 1;
  memset(l->delay, 0, sizeof(float) * (size_t)l->lookahead * (size_t)l->channels);
  for (int i = 0; i < window; i++) {
    l->need[i] = 1.0f;
    l->window_min[i] = 1.0f;
  }
  l->window_sum = (double)window;
  l->queue_head = 0;
  l->queue_len = 0;
  l->pos = 0;
  l->gain = 1.0f;
}

int aac_limiter_delay(const AacLimiter* l) { return l ? l->lookahead : 0; }

long long aac_limiter_lookahead(int sample_rate, int attack_ms) {
  return (long long)attack_ms * sample_rate / 1000;
}

void aac_limiter_process(AacLimiter* l, float* pcm, int frames) {
  const int nc = l->channels;
  const int n = l->lookahead;
  const int window = n + 1;
  for (int f = 0; f < frames; f++) {
    float* frame = pcm + (size_t)f * nc;
    /* The frame enters the delay line; the frame of `n` steps ago leaves it. */
    float peak = 0.0f;
    float* slot = l->delay + (size_t)(l->pos % n) * nc;
    float out[AAC_LIMITER_MAX_CHANNELS];
    for (int c = 0; c < nc; c++) {
      out[c] = slot[c];
      slot[c] = frame[c];
      const float a = std::fabs(frame[c]);
      peak = a > peak ? a : peak;
    }
    /* Its gain joins the window [pos - n, pos] whose minimum the monotone
     * queue keeps: the entry of the frame that left the window expires,
     * and entries with a gain at or above the new one can never be the
     * minimum again. */
    const int ring = l->pos % window;
    const float need = needed_gain(peak);
    if (l->queue_len > 0 && l->queue[l->queue_head] == ring) {
      l->queue_head = (l->queue_head + 1) % window;
      l->queue_len--;
    }
    while (l->queue_len > 0 &&
           l->need[l->queue[(l->queue_head + l->queue_len - 1) % window]] >= need) {
      l->queue_len--;
    }
    l->need[ring] = need;
    l->queue[(l->queue_head + l->queue_len) % window] = ring;
    l->queue_len++;
    const float window_min = l->need[l->queue[l->queue_head]];
    /* The gain is the mean of the last `window` window minima: a linear
     * ramp down that reaches the needed gain exactly when the peak leaves
     * the delay line, and a ramp up afterwards that the release slows to
     * its exponential recovery. */
    l->window_sum += (double)window_min - (double)l->window_min[ring];
    l->window_min[ring] = window_min;
    if (ring == 0) {
      /* Re-sum the ring once per window so rounding cannot accumulate. */
      double sum = 0.0;
      for (int i = 0; i < window; i++) {
        sum += l->window_min[i];
      }
      l->window_sum = sum;
    }
    float gain = (float)(l->window_sum / window);
    const float released = l->gain * l->release_up;
    if (released < gain) {
      gain = released;
    }
    /* The frame leaving the delay line entered `n` steps ago; its own
     * gain bounds the result whatever the averaging rounded to. */
    const float own = l->need[(ring + 1) % window];
    if (own < gain) {
      gain = own;
    }
    if (gain > 1.0f) {
      gain = 1.0f;
    }
    l->gain = gain;
    for (int c = 0; c < nc; c++) {
      frame[c] = out[c] * gain;
    }
    l->pos = (l->pos + 1) % (n * window);
  }
}
