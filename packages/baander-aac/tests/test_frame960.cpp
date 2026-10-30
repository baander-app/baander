/* 960-sample frames: the transforms and windows of the 960/120 line
 * filterbank, the frame-length settings of the public API, and
 * encode/decode roundtrips of AAC-LC and HE-AAC v1/v2 with 960 frames at
 * every sample rate, with the bounds of the 1024-frame roundtrips. */
#include "aac_tables.h"
#include "mdct.h"
#include "test_util.h"

static float rnd(unsigned* seed) {
  *seed = *seed * 1664525u + 1013904223u;
  return (float)(*seed >> 8) / 8388608.0f - 1.0f;
}

/* ── transforms ────────────────────────────────────────────────── */

static void test_mdct_definition(int n) {
  AacMdct m;
  aac_mdct_init(&m, n);
  std::vector<float> x(n), X(n / 2), y(n);
  unsigned seed = 3;
  for (int i = 0; i < n; i++) {
    x[i] = rnd(&seed);
  }
  const double n0 = n / 4.0 + 0.5;
  aac_mdct_forward(&m, x.data(), X.data());
  double err = 0.0;
  double peak = 0.0;
  for (int k = 0; k < n / 2; k++) {
    double s = 0.0;
    for (int j = 0; j < n; j++) {
      s += x[j] * cos(2.0 * M_PI / n * (j + n0) * (k + 0.5));
    }
    s *= 2.0;
    err = std::max(err, fabs(s - X[k]));
    peak = std::max(peak, fabs(s));
  }
  CHECK(err / peak < 1e-5, "n=%d forward MDCT relative error %.3g", n, err / peak);
  aac_imdct(&m, X.data(), y.data());
  err = 0.0;
  peak = 0.0;
  for (int j = 0; j < n; j++) {
    double s = 0.0;
    for (int k = 0; k < n / 2; k++) {
      s += X[k] * cos(2.0 * M_PI / n * (j + n0) * (k + 0.5));
    }
    s *= 2.0 / n;
    err = std::max(err, fabs(s - y[j]));
    peak = std::max(peak, fabs(s));
  }
  CHECK(err / peak < 1e-5, "n=%d inverse MDCT relative error %.3g", n, err / peak);
}

/* Windowed MDCT of overlapping frames, IMDCT, windowing and overlap-add
 * reproduce the signal through the 960 and 120 windows. */
static void test_tdac(int shape, int half) {
  const int n = 2 * half;
  AacMdct m;
  aac_mdct_init(&m, n);
  const float* w = aac_window_long_for(960, shape);
  if (half == 120) {
    w = aac_window_short_for(960, shape);
  }
  for (int i = 0; i < half; i++) {
    const double a = w[i];
    const double b = w[half - 1 - i];
    CHECK(fabs(a * a + b * b - 1.0) < 1e-5, "window %d/%d sample %d", shape, half, i);
  }
  const int frames = 6;
  std::vector<float> x((size_t)(frames + 1) * half);
  unsigned seed = 11;
  for (float& v : x) {
    v = rnd(&seed);
  }
  std::vector<float> out((size_t)(frames + 1) * half, 0.0f);
  std::vector<float> in(n), X(half), y(n);
  for (int f = 0; f < frames; f++) {
    for (int i = 0; i < half; i++) {
      in[i] = x[(size_t)f * half + i] * w[i];
      in[half + i] = x[(size_t)f * half + half + i] * w[half - 1 - i];
    }
    aac_mdct_forward(&m, in.data(), X.data());
    aac_imdct(&m, X.data(), y.data());
    for (int i = 0; i < half; i++) {
      out[(size_t)f * half + i] += y[i] * w[i];
      out[(size_t)f * half + half + i] += y[half + i] * w[half - 1 - i];
    }
  }
  double err = 0.0;
  for (size_t i = half; i < (size_t)frames * half; i++) {
    err = std::max(err, fabs((double)out[i] - x[i]));
  }
  CHECK(err < 1e-4, "TDAC shape %d half %d: error %.3g", shape, half, err);
}

/* ── API ───────────────────────────────────────────────────────── */

static void test_settings() {
  AacEncoderHandle enc = aac_encoder_create(44100, 2, 128000, AAC_AOT_LC, AAC_RC_CBR);
  CHECK(enc != nullptr, "create");
  CHECK(aac_encoder_set_frame_length(enc, 512) == AAC_ERR_INVALID_ARG, "512 refused");
  CHECK(aac_encoder_frame_size(enc) == 1024, "frame size stays 1024 after a refused length");
  CHECK(aac_encoder_set_frame_length(enc, 960) == AAC_OK, "960 accepted");
  CHECK(aac_encoder_frame_size(enc) == 960, "frame size 960");
  CHECK(aac_encoder_delay(enc) == 1920, "delay 1920, got %d", aac_encoder_delay(enc));
  CHECK(aac_encoder_max_frame_bytes(enc) == (5760 * 2 + 56 + 7) / 8, "max frame bytes %d",
        aac_encoder_max_frame_bytes(enc));
  std::vector<float> block(960 * 2, 0.0f);
  std::vector<uint8_t> buf(8192);
  CHECK(aac_encoder_encode(enc, block.data(), 1024, buf.data(), (int)buf.size()) ==
            AAC_ERR_INVALID_ARG,
        "a 1024-sample block is refused");
  CHECK(aac_encoder_encode(enc, block.data(), 960, buf.data(), (int)buf.size()) > 0, "encode");
  CHECK(aac_encoder_set_frame_length(enc, 1024) == AAC_ERR_STATE,
        "the frame length is fixed once a frame is encoded");
  CHECK(aac_encoder_flush(enc, buf.data(), (int)buf.size()) > 0, "flush");
  CHECK(aac_encoder_set_frame_length(enc, 1024) == AAC_OK, "a flushed encoder takes a new length");
  CHECK(aac_encoder_frame_size(enc) == 1024, "frame size back to 1024");
  CHECK(aac_encoder_delay(enc) == 2048, "delay back to 2048");
  aac_encoder_destroy(enc);

  enc = aac_encoder_create(44100, 2, 48000, AAC_AOT_PS, AAC_RC_CBR);
  CHECK(enc != nullptr, "create HE-AAC v2");
  CHECK(aac_encoder_set_frame_length(enc, 960) == AAC_OK, "960 with SBR");
  CHECK(aac_encoder_frame_size(enc) == 1920, "HE-AAC frame size 1920");
  CHECK(aac_encoder_delay(enc) == 5379, "HE-AAC delay 5379, got %d", aac_encoder_delay(enc));
  aac_encoder_destroy(enc);

  AacDecoderHandle dec = aac_decoder_create(44100, 2);
  CHECK(dec != nullptr, "decoder");
  CHECK(aac_decoder_set_frame_length(dec, 1000) == AAC_ERR_INVALID_ARG, "1000 refused");
  CHECK(aac_decoder_set_frame_length(dec, 960) == AAC_OK, "decoder 960");
  CHECK(aac_decoder_frame_size(dec) == 960, "decoder frame size 960");
  CHECK(aac_decoder_set_frame_length(dec, 1024) == AAC_OK, "decoder 1024");
  CHECK(aac_decoder_frame_size(dec) == 1024, "decoder frame size 1024");
  aac_decoder_destroy(dec);
}

/* ── roundtrips ────────────────────────────────────────────────── */

static std::vector<uint8_t> encode_960(const std::vector<float>& pcm, int sr, int channels,
                                       int bitrate, AacRateControl rc, AacObjectType aot,
                                       int* error) {
  std::vector<uint8_t> out;
  AacEncoderHandle enc = aac_encoder_create(sr, channels, bitrate, aot, rc);
  if (!enc) {
    *error = AAC_ERR_INIT;
    return out;
  }
  *error = aac_encoder_set_frame_length(enc, 960);
  const int frame = aac_encoder_frame_size(enc);
  std::vector<float> block((size_t)frame * channels);
  std::vector<uint8_t> buf(8192);
  for (size_t pos = 0; pos < pcm.size() && *error == 0; pos += block.size()) {
    std::fill(block.begin(), block.end(), 0.0f);
    const size_t n = std::min(block.size(), pcm.size() - pos);
    memcpy(block.data(), pcm.data() + pos, n * sizeof(float));
    const int len = aac_encoder_encode(enc, block.data(), frame, buf.data(), (int)buf.size());
    if (len < 0) {
      *error = len;
      break;
    }
    out.insert(out.end(), buf.begin(), buf.begin() + len);
  }
  const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
  if (len > 0) {
    out.insert(out.end(), buf.begin(), buf.begin() + len);
  } else if (len < 0) {
    *error = len;
  }
  aac_encoder_destroy(enc);
  return out;
}

static std::vector<float> decode_960(const std::vector<uint8_t>& stream, int sr, int channels,
                                     int* errors, AacDecoderHandle dec) {
  *errors = aac_decoder_set_frame_length(dec, 960) == AAC_OK ? 0 : 1;
  return decode_stream(stream, sr, channels, errors, dec);
}

struct Case {
  const char* name;
  std::vector<float> (*gen)(int, int, int);
  int channels;
  int bitrate;
  double min_snr_db;
};

static std::vector<float> noise(int n, int, int channels) { return gen_noise(n, channels); }

static void roundtrip(const Case& c, int sr, AacRateControl rc) {
  const int n = sr * 3;
  const std::vector<float> pcm = c.gen(n, sr, c.channels);
  int err = 0;
  const std::vector<uint8_t> stream =
      encode_960(pcm, sr, c.channels, c.bitrate, rc, AAC_AOT_LC, &err);
  CHECK(err == 0, "%s @%d rc%d: encode error %d", c.name, sr, rc, err);
  const std::vector<AdtsFrame> frames = split_adts(stream);
  const size_t expect_frames = (size_t)(n + 959) / 960 + 2;
  CHECK(frames.size() == expect_frames, "%s @%d: %zu frames, expected %zu", c.name, sr,
        frames.size(), expect_frames);
  int errors = 0;
  AacDecoderHandle dec = aac_decoder_create(sr, c.channels);
  const std::vector<float> out = decode_960(stream, sr, c.channels, &errors, dec);
  CHECK(aac_decoder_frame_size(dec) == 960, "%s @%d: decoder frame size", c.name, sr);
  aac_decoder_destroy(dec);
  CHECK(errors == 0, "%s @%d: %d frames failed to decode", c.name, sr, errors);
  CHECK(out.size() == frames.size() * 960 * c.channels, "%s @%d: output length", c.name, sr);
  const double snr = aligned_snr_db(pcm, out, c.channels, 1920);
  const double kbps = (double)stream.size() * 8.0 * sr / 960.0 / (double)frames.size() / 1000.0;
  printf("%-11s %6d Hz %dch %4d kbps rc%d: %5.1f dB at %.1f kbps\n", c.name, sr, c.channels,
         c.bitrate / 1000, rc, snr, kbps);
  CHECK(snr >= c.min_snr_db, "%s @%d rc%d: SNR %.1f below %.1f dB", c.name, sr, rc, snr,
        c.min_snr_db);
  if (c.gen == gen_transients) {
    const double early = aligned_snr_db(pcm, out, c.channels, 1920 - 64);
    const double late = aligned_snr_db(pcm, out, c.channels, 1920 + 64);
    CHECK(snr > early && snr > late, "%s: delay is not 1920 samples", c.name);
  }
  /* Every frame of a constant-rate stream stays within the buffer limit
   * of 5760 bits per channel. */
  for (const AdtsFrame& f : frames) {
    CHECK(f.size <= (size_t)(5760 * c.channels / 8 + 7), "%s @%d: frame of %zu bytes", c.name, sr,
          f.size);
  }
}

/* HE-AAC with 960 core frames: 1920 samples per frame at the input rate,
 * the declared delay, the low band through the core, the high band at
 * the source's level and the bitrate held. */
static void he_roundtrip(const char* name, AacObjectType aot, int sr, int channels, int bitrate,
                         double min_low_snr_db) {
  const int n = sr * 3;
  const std::vector<float> pcm = gen_wideband(n, sr, channels);
  int err = 0;
  const std::vector<uint8_t> stream =
      encode_960(pcm, sr, channels, bitrate, AAC_RC_CBR, aot, &err);
  CHECK(err == 0, "%s: encode error %d", name, err);
  const std::vector<AdtsFrame> frames = split_adts(stream);
  const int delay = 5379;
  const size_t expect_frames = (size_t)(n + 1919) / 1920 + (delay + 1919) / 1920;
  CHECK(frames.size() == expect_frames, "%s: %zu frames, expected %zu", name, frames.size(),
        expect_frames);
  int errors = 0;
  AacDecoderHandle dec = aac_decoder_create(sr, 0);
  const std::vector<float> out = decode_960(stream, sr, 0, &errors, dec);
  int has_sbr = 0, has_ps = 0;
  aac_decoder_get_sbr_ps(dec, &has_sbr, &has_ps);
  CHECK(has_sbr, "%s: SBR not decoded", name);
  CHECK(has_ps == (aot == AAC_AOT_PS), "%s: PS %d", name, has_ps);
  CHECK(aac_decoder_frame_size(dec) == 1920, "%s: decoder frame size %d", name,
        aac_decoder_frame_size(dec));
  CHECK(aac_decoder_output_sample_rate(dec) == sr, "%s: output rate %d", name,
        aac_decoder_output_sample_rate(dec));
  CHECK(aac_decoder_channels(dec) == channels, "%s: %d output channels", name,
        aac_decoder_channels(dec));
  aac_decoder_destroy(dec);
  CHECK(errors == 0, "%s: %d frames failed to decode", name, errors);
  CHECK(out.size() == frames.size() * 1920 * channels, "%s: output length", name);
  const double kbps = (double)stream.size() * 8.0 * sr / 1920.0 / (double)frames.size() / 1000.0;
  const float cutoff = 3500.0f;
  double low_snr = 100.0;
  double high_diff = 0.0;
  for (int c = 0; c < channels; c++) {
    const std::vector<float> ref = lowpass_channel(pcm, channels, c, cutoff, sr);
    const std::vector<float> got = lowpass_channel(out, channels, c, cutoff, sr);
    const size_t m = std::min(ref.size(), got.size() - delay);
    low_snr = std::min(low_snr, snr_db(ref.data(), got.data() + delay, m));
    const double src = band_db(pcm, channels, c, 9000.0f, 12000.0f, sr, 4096, (size_t)n - 4096);
    const double dst = band_db(out, channels, c, 9000.0f, 12000.0f, sr, 4096 + delay,
                               (size_t)n - 4096 + delay);
    high_diff = std::max(high_diff, fabs(dst - src));
  }
  /* Where the low band lines up best, for the delay check. */
  const std::vector<float> ref = lowpass_channel(pcm, channels, 0, cutoff, sr);
  const std::vector<float> got = lowpass_channel(out, channels, 0, cutoff, sr);
  int best_delay = 0;
  const double best = best_aligned_snr_db(ref, got, delay - 200, delay + 200, &best_delay);
  printf("%-22s %6d Hz %dch %3d kbps: low band %5.1f dB, high band within %.1f dB at %.1f kbps, "
         "best alignment %d (%.1f dB)\n",
         name, sr, channels, bitrate / 1000, low_snr, high_diff, kbps, best_delay, best);
  CHECK(low_snr >= min_low_snr_db, "%s: low band SNR %.1f below %.1f dB", name, low_snr,
        min_low_snr_db);
  CHECK(high_diff < 3.0, "%s: high band level off by %.1f dB", name, high_diff);
  CHECK(fabs(kbps - bitrate / 1000.0) < 0.06 * bitrate / 1000.0, "%s: %.1f kbps", name, kbps);
  const size_t m = std::min(ref.size(), got.size() - delay - 64);
  const double at = snr_db(ref.data(), got.data() + delay, m);
  const double early = snr_db(ref.data(), got.data() + delay - 64, m);
  const double late = snr_db(ref.data(), got.data() + delay + 64, m);
  CHECK(at > early && at > late, "%s: delay is not %d samples", name, delay);
}

/* A 960 stream decoded as 1024 (and the other way round) fails or
 * produces nonsense rather than crashing; the decoder told the right
 * length afterwards recovers. */
static void test_wrong_length() {
  const int sr = 48000;
  const std::vector<float> pcm = gen_harmonics(sr, sr, 1);
  int err = 0;
  const std::vector<uint8_t> stream = encode_960(pcm, sr, 1, 64000, AAC_RC_CBR, AAC_AOT_LC, &err);
  CHECK(err == 0, "encode");
  AacDecoderHandle dec = aac_decoder_create(sr, 1);
  int errors = 0;
  const std::vector<float> wrong = decode_stream(stream, sr, 1, &errors, dec);
  const double wrong_snr = aligned_snr_db(pcm, wrong, 1, 1920);
  CHECK(errors > 0 || wrong_snr < 5.0, "a 960 stream decoded as 1024 cannot sound right (%.1f dB)",
        wrong_snr);
  const std::vector<float> right = decode_960(stream, sr, 1, &errors, dec);
  CHECK(errors == 0, "%d frames failed after the length was set", errors);
  const double snr = aligned_snr_db(pcm, right, 1, 1920);
  CHECK(snr > 15.0, "decoder recovers with the right length: %.1f dB", snr);
  aac_decoder_destroy(dec);
}

int main() {
  test_mdct_definition(1920);
  test_mdct_definition(240);
  for (int shape = 0; shape < 2; shape++) {
    test_tdac(shape, 960);
    test_tdac(shape, 120);
  }
  test_settings();
  test_wrong_length();

  const Case cases[] = {
      {"tones", gen_tones, 2, 128000, 30.0},         {"harmonics", gen_harmonics, 2, 128000, 20.0},
      {"harmonics", gen_harmonics, 1, 64000, 18.0},  {"noise", noise, 1, 128000, 8.0},
      {"transients", gen_transients, 2, 96000, 4.0},
  };
  for (const Case& c : cases) {
    roundtrip(c, 44100, AAC_RC_CBR);
  }
  roundtrip({"tones", gen_tones, 2, 128000, 30.0}, 48000, AAC_RC_CBR);
  roundtrip({"layout 5.1", gen_multichannel, 6, 384000, 20.0}, 48000, AAC_RC_CBR);
  roundtrip({"layout 5.1", gen_multichannel, 6, 256000, 18.0}, 44100, AAC_RC_TVBR);
  for (AacRateControl rc : {AAC_RC_ABR, AAC_RC_CVBR, AAC_RC_TVBR}) {
    roundtrip({"harmonics", gen_harmonics, 2, 128000, 18.0}, 44100, rc);
  }
  const int rates[] = {8000,  11025, 12000, 16000, 22050, 24000,
                       32000, 44100, 48000, 64000, 88200, 96000};
  for (int sr : rates) {
    const int bitrate = sr < 16000 ? 32000 : sr < 32000 ? 64000 : 128000;
    roundtrip({"harmonics", gen_harmonics, 1, bitrate, 15.0}, sr, AAC_RC_CBR);
  }
  he_roundtrip("he mono", AAC_AOT_SBR, 44100, 1, 32000, 18.0);
  he_roundtrip("he stereo", AAC_AOT_SBR, 48000, 2, 64000, 14.0);
  he_roundtrip("he stereo 32k", AAC_AOT_SBR, 32000, 2, 48000, 14.0);
  he_roundtrip("he 5.0", AAC_AOT_SBR, 48000, 5, 160000, 14.0);
  he_roundtrip("hev2 stereo", AAC_AOT_PS, 44100, 2, 32000, 10.0);
  he_roundtrip("hev2 stereo 48k", AAC_AOT_PS, 48000, 2, 24000, 7.0);
  return test_summary("test_frame960");
}
