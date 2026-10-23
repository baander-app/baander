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

int main() {
  AacEncoderHandle enc = aac_encoder_create(44100, 2, 128000, AAC_AOT_LC, AAC_RC_CBR);
  CHECK(enc != nullptr, "create");
  CHECK(aac_encoder_frame_size(enc) == 1024, "frame size");
  CHECK(aac_encoder_delay(enc) == 2048, "delay");
  aac_encoder_destroy(enc);

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
  return test_summary("test_roundtrip");
}
