/* The output peak limiter: a signal far above full scale comes out within
 * it, the output is the input delayed by the attack window, a signal
 * within full scale passes bit-exactly, the gain recovers within the
 * release time, and the same gain is applied to every one of eight
 * channels.  The decoder's limiter setting is checked through the public
 * API. */
#include "limiter.h"
#include "test_util.h"

static const int kRate = 48000;

/* Runs the limiter over the whole signal in blocks of varying length. */
static std::vector<float> limit(AacLimiter* l, const std::vector<float>& in, int channels) {
  std::vector<float> out = in;
  size_t pos = 0;
  int block = 1;
  while (pos < out.size() / channels) {
    const int n = (int)std::min((size_t)block, out.size() / channels - pos);
    aac_limiter_process(l, out.data() + pos * channels, n);
    pos += n;
    block = block % 1024 + 97;
  }
  return out;
}

static float peak_abs(const std::vector<float>& x, size_t from, size_t to) {
  float p = 0.0f;
  for (size_t i = from; i < to && i < x.size(); i++) {
    p = std::max(p, fabsf(x[i]));
  }
  return p;
}

static void test_init() {
  AacLimiter l;
  CHECK(aac_limiter_init(&l, 2, kRate, 5, 50) == AAC_OK, "init");
  CHECK(aac_limiter_delay(&l) == 240, "5 ms at 48 kHz is 240 frames (%d)", aac_limiter_delay(&l));
  CHECK(aac_limiter_init(&l, 9, kRate, 5, 50) == AAC_ERR_INVALID_ARG, "nine channels refused");
  CHECK(aac_limiter_init(&l, 1, kRate, 1000, 50) == AAC_ERR_INVALID_ARG, "attack too long");
  CHECK(aac_limiter_init(&l, 1, kRate, 0, 0) == AAC_OK && aac_limiter_delay(&l) == 1,
        "zero attack keeps one frame of look-ahead");
}

static void test_overload() {
  /* A 1 kHz tone at +6 dB over full scale, stereo with the right channel
   * quieter: nothing exceeds 1.0, the loud channel reaches it, the quiet
   * channel is scaled by the same gain. */
  const int n = kRate;
  std::vector<float> in((size_t)n * 2);
  for (int i = 0; i < n; i++) {
    const float v = sinf(2.0f * (float)M_PI * 1000.0f * (float)i / (float)kRate);
    in[2 * (size_t)i] = 2.0f * v;
    in[2 * (size_t)i + 1] = 0.5f * v;
  }
  AacLimiter l;
  aac_limiter_init(&l, 2, kRate, 5, 50);
  const std::vector<float> out = limit(&l, in, 2);
  CHECK(peak_abs(out, 0, out.size()) <= 1.0f, "no sample exceeds full scale (%g)",
        peak_abs(out, 0, out.size()));
  CHECK(peak_abs(out, 0, out.size()) > 0.99f, "the loud channel reaches full scale (%g)",
        peak_abs(out, 0, out.size()));
  /* Same gain on both channels: the ratio is preserved at every sample. */
  double worst = 0.0;
  const int delay = aac_limiter_delay(&l);
  for (int i = delay + 1000; i < n; i++) {
    const float left = out[2 * (size_t)i];
    const float right = out[2 * (size_t)i + 1];
    worst = std::max(worst, (double)fabsf(left - 4.0f * right));
  }
  CHECK(worst < 1e-6, "channels share the gain (%g)", worst);
  /* The output is the input delayed by the attack window: once the gain
   * has settled the ratio of output to input is constant. */
  double ratio_min = 10.0;
  double ratio_max = 0.0;
  for (int i = delay + 2000; i < n; i++) {
    const float x = in[2 * (size_t)(i - delay)];
    if (fabsf(x) > 0.5f) {
      const double r = out[2 * (size_t)i] / x;
      ratio_min = std::min(ratio_min, r);
      ratio_max = std::max(ratio_max, r);
    }
  }
  /* The tone's sampled peak may fall an ulp short of 2, which puts the
   * gain an ulp above one half; full scale itself is checked above. */
  CHECK(ratio_max - ratio_min < 1e-5 && ratio_max > 0.49 && ratio_max <= 0.5 + 1e-6,
        "settled gain of one half (%g..%g)", ratio_min, ratio_max);
}

static void test_delay_and_passthrough() {
  /* A single impulse of 3.0 lands exactly `delay` frames later at 1.0;
   * tones at -1 dB are untouched bit-exactly. */
  const int n = 4800;
  const int channels = 3;
  std::vector<float> in((size_t)n * channels, 0.0f);
  in[100 * channels + 1] = 3.0f;
  AacLimiter l;
  aac_limiter_init(&l, channels, kRate, 10, 20);
  const int delay = aac_limiter_delay(&l);
  CHECK(delay == 480, "10 ms delay (%d)", delay);
  std::vector<float> out = limit(&l, in, channels);
  CHECK(fabsf(out[(size_t)(100 + delay) * channels + 1] - 1.0f) < 1e-6f,
        "impulse limited to full scale after the delay (%g)",
        out[(size_t)(100 + delay) * channels + 1]);
  CHECK(peak_abs(out, 0, (size_t)(100 + delay) * channels) == 0.0f, "nothing before the impulse");
  CHECK(peak_abs(out, (size_t)(101 + delay) * channels, out.size()) == 0.0f,
        "nothing after the impulse");

  const std::vector<float> tones = gen_tones(n, kRate, 2);
  std::vector<float> quiet(tones.size());
  const float scale = 0.891f / peak_abs(tones, 0, tones.size()); /* -1 dB */
  for (size_t i = 0; i < tones.size(); i++) {
    quiet[i] = tones[i] * scale;
  }
  aac_limiter_init(&l, 2, kRate, 10, 20);
  out = limit(&l, quiet, 2);
  bool exact = true;
  for (int i = delay; i < n && exact; i++) {
    for (int c = 0; c < 2; c++) {
      exact = exact && out[(size_t)i * 2 + c] == quiet[(size_t)(i - delay) * 2 + c];
    }
  }
  CHECK(exact, "a signal within full scale passes bit-exactly");
}

static void test_release() {
  /* 100 ms at +6 dB, then a tone at -3 dB: the gain reaches 0.5 while
   * the loud part plays and is back to unity, bit-exactly, within the
   * release time (defined for 20 dB of recovery, so 6 dB takes less),
   * and the gain is monotone during the recovery. */
  const int loud = kRate / 10;
  const int n = kRate / 2;
  const int release_ms = 50;
  std::vector<float> in((size_t)n);
  for (int i = 0; i < n; i++) {
    const float v = sinf(2.0f * (float)M_PI * 997.0f * (float)i / (float)kRate);
    in[(size_t)i] = i < loud ? 2.0f * v : 0.7f * v;
  }
  AacLimiter l;
  aac_limiter_init(&l, 1, kRate, 5, release_ms);
  const int delay = aac_limiter_delay(&l);
  const std::vector<float> out = limit(&l, in, 1);
  CHECK(peak_abs(out, 0, out.size()) <= 1.0f, "release test stays within full scale");
  /* The loud section comes out at half gain. */
  CHECK(fabsf(peak_abs(out, (size_t)(delay + 1000), (size_t)(loud + delay - 1000)) - 1.0f) < 1e-5f,
        "loud section at full scale");
  /* Gain over the quiet section, per sample where the input is large. */
  const int release_frames = release_ms * kRate / 1000;
  const int recovered = loud + delay + release_frames;
  float before = 0.0f;
  bool monotone = true;
  bool exact_after = true;
  for (int i = loud + delay + 1; i < n; i++) {
    const float x = in[(size_t)(i - delay)];
    if (fabsf(x) < 0.3f) {
      continue;
    }
    const float g = out[(size_t)i] / x;
    monotone = monotone && g >= before - 1e-6f;
    before = g;
    if (i >= recovered) {
      exact_after = exact_after && out[(size_t)i] == x;
    }
  }
  CHECK(monotone, "gain rises monotonically during the release");
  CHECK(exact_after, "gain back at unity within the release time");
  /* Gain at the start of the quiet part is still near one half, i.e. the
   * release is not instant. */
  const int probe = loud + delay + 12; /* 997 Hz peaks every 48 samples */
  float g0 = 0.0f;
  for (int i = probe; i < probe + 48; i++) {
    const float x = in[(size_t)(i - delay)];
    if (fabsf(x) > 0.69f) {
      g0 = out[(size_t)i] / x;
    }
  }
  CHECK(g0 > 0.45f && g0 < 0.6f, "gain just after the loud part is near one half (%g)", g0);
}

static void test_eight_channels() {
  /* Eight channels with the peak wandering between them: every channel
   * ends up within full scale, and the gain follows the loudest one. */
  const int n = kRate;
  const int channels = 8;
  std::vector<float> in((size_t)n * channels);
  for (int i = 0; i < n; i++) {
    for (int c = 0; c < channels; c++) {
      const float v = sinf(2.0f * (float)M_PI * (300.0f + 100.0f * (float)c) * (float)i / (float)kRate);
      const int loud = (i / 4800) % channels; /* the loud channel changes every 100 ms */
      in[(size_t)i * channels + c] = c == loud ? 1.8f * v : 0.4f * v;
    }
  }
  AacLimiter l;
  CHECK(aac_limiter_init(&l, channels, kRate, 4, 30) == AAC_OK, "eight-channel init");
  const std::vector<float> out = limit(&l, in, channels);
  CHECK(peak_abs(out, 0, out.size()) <= 1.0f, "eight channels within full scale (%g)",
        peak_abs(out, 0, out.size()));
  CHECK(peak_abs(out, 0, out.size()) > 0.99f, "eight channels reach full scale");
  /* Within each block the quiet channels keep their ratio to the loud
   * one, so the gain is shared. */
  const int delay = aac_limiter_delay(&l);
  double worst = 0.0;
  for (int i = delay + 1000; i < n; i++) {
    const int loud = ((i - delay) / 4800) % channels;
    if ((i - delay) % 4800 < 600 || (i - delay) % 4800 > 4200) {
      continue; /* skip the gain transitions */
    }
    const float x_loud = in[(size_t)(i - delay) * channels + loud];
    if (fabsf(x_loud) < 1.0f) {
      continue;
    }
    const float g = out[(size_t)i * channels + loud] / x_loud;
    for (int c = 0; c < channels; c++) {
      const float x = in[(size_t)(i - delay) * channels + c];
      worst = std::max(worst, (double)fabsf(out[(size_t)i * channels + c] - g * x));
    }
  }
  CHECK(worst < 1e-5, "eight channels share one gain (%g)", worst);
  aac_limiter_reset(&l);
  std::vector<float> zeros((size_t)delay * channels, 0.0f);
  aac_limiter_process(&l, zeros.data(), delay);
  CHECK(peak_abs(zeros, 0, zeros.size()) == 0.0f, "reset clears the delay line");
}

static void test_decoder_setting() {
  /* The decoder refuses bad settings and reports the delay; the limiter
   * itself is wired into the output path elsewhere, so only the setting
   * is checked here. */
  AacDecoderHandle dec = aac_decoder_create(48000, 2);
  CHECK(aac_decoder_delay(dec) == 0, "no delay without the limiter");
  CHECK(aac_decoder_set_limiter(dec, 1, 5, 50) == AAC_OK, "limiter enabled");
  CHECK(aac_decoder_delay(dec) == 240, "5 ms of delay (%d)", aac_decoder_delay(dec));
  CHECK(aac_decoder_set_limiter(dec, 1, 500, 50) == AAC_ERR_INVALID_ARG, "attack too long");
  CHECK(aac_decoder_set_limiter(dec, 1, 5, -1) == AAC_ERR_INVALID_ARG, "negative release");
  CHECK(aac_decoder_set_limiter(dec, 0, 5, 50) == AAC_OK && aac_decoder_delay(dec) == 0,
        "limiter disabled again");
  aac_decoder_destroy(dec);
}

int main() {
  test_init();
  test_overload();
  test_delay_and_passthrough();
  test_release();
  test_eight_channels();
  test_decoder_setting();
  return test_summary("test_limiter");
}
