/* Encode/decode through the public API: every frame decodes, the delay is
 * as declared, and the reconstruction quality clears signal-dependent
 * floors at common settings and all sample rates. */
#include "test_util.h"

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
  const std::vector<uint8_t> stream = encode_stream(pcm, sr, c.channels, c.bitrate, rc, 0, &err);
  CHECK(err == 0, "%s @%d rc%d: encode error %d", c.name, sr, rc, err);
  const std::vector<AdtsFrame> frames = split_adts(stream);
  const size_t expect_frames = (size_t)(n + 1023) / 1024 + 2;
  CHECK(frames.size() == expect_frames, "%s @%d: %zu frames, expected %zu", c.name, sr,
        frames.size(), expect_frames);
  int errors = 0;
  const std::vector<float> out = decode_stream(stream, sr, c.channels, &errors);
  CHECK(errors == 0, "%s @%d: %d frames failed to decode", c.name, sr, errors);
  CHECK(out.size() == frames.size() * 1024 * c.channels, "%s @%d: output length", c.name, sr);
  const double snr = aligned_snr_db(pcm, out, c.channels, 2048);
  const double kbps = (double)stream.size() * 8.0 * sr / 1024.0 / (double)frames.size() / 1000.0;
  printf("%-11s %6d Hz %dch %4d kbps rc%d: %5.1f dB at %.1f kbps\n", c.name, sr, c.channels,
         c.bitrate / 1000, rc, snr, kbps);
  CHECK(snr >= c.min_snr_db, "%s @%d rc%d: SNR %.1f below %.1f dB", c.name, sr, rc, snr,
        c.min_snr_db);
  /* The declared delay is where the signal lines up best. */
  if (c.gen == gen_transients) {
    const double early = aligned_snr_db(pcm, out, c.channels, 2048 - 64);
    const double late = aligned_snr_db(pcm, out, c.channels, 2048 + 64);
    CHECK(snr > early && snr > late, "%s: delay is not 2048 samples", c.name);
  }
}

/* HE-AAC: the stream decodes at the input rate with SBR (and PS), the
 * delay is as declared, the lowpassed signal survives the core coder and
 * the band above the crossover comes back at the source's level. */
static void he_roundtrip(const char* name, AacObjectType aot, int sr, int channels, int bitrate,
                         double min_low_snr_db) {
  const int n = sr * 3;
  const std::vector<float> pcm = gen_wideband(n, sr, channels);
  int err = 0;
  const std::vector<uint8_t> stream =
      encode_stream(pcm, sr, channels, bitrate, AAC_RC_CBR, 0, &err, aot);
  CHECK(err == 0, "%s: encode error %d", name, err);
  const std::vector<AdtsFrame> frames = split_adts(stream);
  const int delay = 5635;
  const size_t expect_frames = (size_t)(n + 2047) / 2048 + (delay + 2047) / 2048;
  CHECK(frames.size() == expect_frames, "%s: %zu frames, expected %zu", name, frames.size(),
        expect_frames);
  int errors = 0;
  AacDecoderHandle dec = aac_decoder_create(sr, 0);
  const std::vector<float> out = decode_stream(stream, sr, 0, &errors, dec);
  int has_sbr = 0, has_ps = 0;
  aac_decoder_get_sbr_ps(dec, &has_sbr, &has_ps);
  CHECK(has_sbr, "%s: SBR not decoded", name);
  CHECK(has_ps == (aot == AAC_AOT_PS), "%s: PS %d", name, has_ps);
  CHECK(aac_decoder_output_sample_rate(dec) == sr, "%s: output rate %d", name,
        aac_decoder_output_sample_rate(dec));
  CHECK(aac_decoder_channels(dec) == channels, "%s: %d output channels", name,
        aac_decoder_channels(dec));
  aac_decoder_destroy(dec);
  CHECK(errors == 0, "%s: %d frames failed to decode", name, errors);
  CHECK(out.size() == frames.size() * 2048 * channels, "%s: output length", name);
  const double kbps = (double)stream.size() * 8.0 * sr / 2048.0 / (double)frames.size() / 1000.0;
  /* the core's range: SBR starts above 4 kHz at every tuning used here;
   * the band 9 to 12 kHz lies inside the SBR range of every tuning */
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
  printf("%-22s %6d Hz %dch %3d kbps: low band %5.1f dB, high band within %.1f dB at %.1f kbps\n",
         name, sr, channels, bitrate / 1000, low_snr, high_diff, kbps);
  CHECK(low_snr >= min_low_snr_db, "%s: low band SNR %.1f below %.1f dB", name, low_snr,
        min_low_snr_db);
  CHECK(high_diff < 3.0, "%s: high band level off by %.1f dB", name, high_diff);
  CHECK(fabs(kbps - bitrate / 1000.0) < 0.06 * bitrate / 1000.0, "%s: %.1f kbps", name, kbps);
  /* The declared delay is where the low band lines up best. */
  const std::vector<float> ref = lowpass_channel(pcm, channels, 0, cutoff, sr);
  const std::vector<float> got = lowpass_channel(out, channels, 0, cutoff, sr);
  const size_t m = std::min(ref.size(), got.size() - delay - 64);
  const double at = snr_db(ref.data(), got.data() + delay, m);
  const double early = snr_db(ref.data(), got.data() + delay - 64, m);
  const double late = snr_db(ref.data(), got.data() + delay + 64, m);
  CHECK(at > early && at > late, "%s: delay is not %d samples", name, delay);
}

int main() {
  AacEncoderHandle enc = aac_encoder_create(44100, 2, 128000, AAC_AOT_LC, AAC_RC_CBR);
  CHECK(enc != nullptr, "create");
  CHECK(aac_encoder_frame_size(enc) == 1024, "frame size");
  CHECK(aac_encoder_delay(enc) == 2048, "delay");
  aac_encoder_destroy(enc);
  enc = aac_encoder_create(44100, 2, 48000, AAC_AOT_PS, AAC_RC_CBR);
  CHECK(enc != nullptr, "create HE-AAC v2");
  CHECK(aac_encoder_frame_size(enc) == 2048, "HE-AAC frame size");
  CHECK(aac_encoder_delay(enc) == 5635, "HE-AAC delay");
  aac_encoder_destroy(enc);
  CHECK(aac_encoder_create(44100, 1, 32000, AAC_AOT_PS, AAC_RC_CBR) == nullptr,
        "HE-AAC v2 takes a stereo input");
  CHECK(aac_encoder_create(22050, 1, 32000, AAC_AOT_SBR, AAC_RC_CBR) == nullptr,
        "no SBR tuning below a 16 kHz core");

  const Case cases[] = {
      {"tones", gen_tones, 2, 128000, 30.0},         {"harmonics", gen_harmonics, 2, 128000, 20.0},
      {"harmonics", gen_harmonics, 1, 64000, 18.0},  {"noise", noise, 1, 128000, 8.0},
      {"transients", gen_transients, 2, 96000, 4.0},
  };
  for (const Case& c : cases) {
    roundtrip(c, 44100, AAC_RC_CBR);
  }
  roundtrip({"tones", gen_tones, 2, 128000, 30.0}, 48000, AAC_RC_CBR);
  /* Every layout the encoder offers, at 64 kbit/s per channel. */
  for (int channels = 3; channels <= 8; channels++) {
    roundtrip({"layout", gen_multichannel, channels, 64000 * channels, 20.0}, 48000, AAC_RC_CBR);
  }
  roundtrip({"layout 5.1", gen_multichannel, 6, 256000, 18.0}, 44100, AAC_RC_TVBR);
  for (AacRateControl rc : {AAC_RC_ABR, AAC_RC_CVBR, AAC_RC_TVBR}) {
    roundtrip({"harmonics", gen_harmonics, 2, 128000, 18.0}, 44100, rc);
  }
  /* Every supported sample rate. */
  const int rates[] = {8000,  11025, 12000, 16000, 22050, 24000,
                       32000, 44100, 48000, 64000, 88200, 96000};
  for (int sr : rates) {
    const int bitrate = sr < 16000 ? 32000 : sr < 32000 ? 64000 : 128000;
    roundtrip({"harmonics", gen_harmonics, 1, bitrate, 15.0}, sr, AAC_RC_CBR);
  }
  /* Floors a few dB under what the FDK encoder reaches on this signal. */
  he_roundtrip("he mono", AAC_AOT_SBR, 44100, 1, 32000, 18.0);
  he_roundtrip("he stereo", AAC_AOT_SBR, 48000, 2, 64000, 14.0);
  he_roundtrip("he stereo 32k", AAC_AOT_SBR, 32000, 2, 48000, 14.0);
  he_roundtrip("he 5.0", AAC_AOT_SBR, 48000, 5, 160000, 14.0);
  he_roundtrip("hev2 stereo", AAC_AOT_PS, 44100, 2, 32000, 10.0);
  he_roundtrip("hev2 stereo 48k", AAC_AOT_PS, 48000, 2, 24000, 7.0);
  return test_summary("test_roundtrip");
}
