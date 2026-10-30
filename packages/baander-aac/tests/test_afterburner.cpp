/* Afterburner: switched off it leaves the stream untouched, switched on
 * it keeps every frame within the rate control's limits, raises the
 * segmental SNR at low bitrates and never lowers it, and its streams
 * decode in our decoder and in FFmpeg. */
#include "bitstream.h"
#include "test_util.h"

/* Several tones with vibrato over a quiet noise floor, with a decaying
 * noise burst every 400 ms; the second channel carries the tones at other
 * levels and phases so that the pair is neither mono nor uncorrelated. */
static std::vector<float> gen_music(int n, int sr, int channels) {
  std::vector<float> pcm((size_t)n * channels);
  const float freqs[6] = {220.0f, 330.0f, 660.0f, 1320.0f, 2640.0f, 5280.0f};
  const float amps[6] = {0.25f, 0.18f, 0.12f, 0.08f, 0.05f, 0.03f};
  const float vib_rate[6] = {5.0f, 5.5f, 6.0f, 6.5f, 7.0f, 4.5f};
  unsigned seed = 2024u;
  float phase[2][6] = {};
  const int burst_period = sr * 2 / 5;
  const int burst_len = sr * 3 / 100;
  for (int i = 0; i < n; i++) {
    const float t = (float)i / (float)sr;
    const int in_burst = i % burst_period;
    for (int c = 0; c < channels; c++) {
      float v = 0.0f;
      for (int k = 0; k < 6; k++) {
        const float vib = 1.0f + 0.01f * sinf(2.0f * (float)M_PI * vib_rate[k] * t + (float)k);
        phase[c & 1][k] += 2.0f * (float)M_PI * freqs[k] * vib / (float)sr;
        const float amp = c & 1 ? amps[k] * (k & 1 ? 1.2f : 0.6f) : amps[k];
        v += amp * sinf(phase[c & 1][k] + 0.4f * (float)c);
      }
      seed = seed * 1664525u + 1013904223u;
      const float noise = (float)(seed >> 8) / 8388608.0f - 1.0f;
      v += 0.004f * noise;
      if (in_burst < burst_len) {
        v += 0.4f * noise * expf(-(float)in_burst / ((float)sr * 0.008f));
      }
      pcm[(size_t)i * channels + c] = v;
    }
  }
  return pcm;
}

/* Mean of the per-segment SNR over 512-sample segments, each clamped to
 * -10..50 dB, after removing the encoder delay. */
static double segmental_snr_db(const std::vector<float>& ref, const std::vector<float>& out,
                               int channels, int delay) {
  const size_t skip = (size_t)delay * channels;
  if (out.size() <= skip) {
    return -100.0;
  }
  const size_t n = std::min(ref.size(), out.size() - skip);
  const size_t seg = 512 * (size_t)channels;
  double sum = 0.0;
  int count = 0;
  for (size_t pos = 0; pos + seg <= n; pos += seg) {
    double sig = 0.0;
    double err = 0.0;
    for (size_t i = pos; i < pos + seg; i++) {
      sig += (double)ref[i] * ref[i];
      const double d = (double)ref[i] - (double)out[i + skip];
      err += d * d;
    }
    if (sig < 1e-9) {
      continue;
    }
    const double snr = 10.0 * log10(sig / std::max(err, 1e-20));
    sum += std::max(-10.0, std::min(50.0, snr));
    count++;
  }
  return count ? sum / count : -100.0;
}

/* Encodes a whole signal with the afterburner in the given state. */
static std::vector<uint8_t> encode_with(const std::vector<float>& pcm, int sr, int channels,
                                        int bitrate, AacRateControl rc, int afterburner,
                                        AacObjectType aot = AAC_AOT_LC, int quality = 0) {
  std::vector<uint8_t> out;
  AacEncoderHandle enc = aac_encoder_create(sr, channels, bitrate, aot, rc);
  if (!enc) {
    return out;
  }
  if (quality) {
    aac_encoder_set_quality(enc, quality);
  }
  CHECK(aac_encoder_set_afterburner(enc, afterburner) == AAC_OK, "set_afterburner(%d)",
        afterburner);
  const int frame = aac_encoder_frame_size(enc);
  std::vector<float> block((size_t)frame * channels);
  std::vector<uint8_t> buf(16384);
  for (size_t pos = 0; pos < pcm.size(); pos += block.size()) {
    std::fill(block.begin(), block.end(), 0.0f);
    const size_t n = std::min(block.size(), pcm.size() - pos);
    memcpy(block.data(), pcm.data() + pos, n * sizeof(float));
    const int len = aac_encoder_encode(enc, block.data(), frame, buf.data(), (int)buf.size());
    CHECK(len > 0, "encode returned %d", len);
    if (len < 0) {
      break;
    }
    out.insert(out.end(), buf.begin(), buf.begin() + len);
  }
  const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
  if (len > 0) {
    out.insert(out.end(), buf.begin(), buf.begin() + len);
  }
  aac_encoder_destroy(enc);
  return out;
}

static double kbps_of(const std::vector<uint8_t>& stream, int sr, int frame) {
  const size_t frames = split_adts(stream).size();
  return frames ? (double)stream.size() * 8.0 * sr / frame / (double)frames / 1000.0 : 0.0;
}

/* Off, the stream is the stream of an encoder that never heard of the
 * switch: an encoder switched on and off again before its first frame
 * produces the same bytes, and the switch is rejected without a handle. */
static void test_off_identity() {
  const int sr = 44100;
  const std::vector<float> pcm = gen_music(sr * 3, sr, 2);
  CHECK(aac_encoder_set_afterburner(nullptr, 1) == AAC_ERR_INVALID_ARG, "null handle");
  for (AacRateControl rc : {AAC_RC_CBR, AAC_RC_TVBR}) {
    const std::vector<uint8_t> untouched = encode_stream(pcm, sr, 2, 64000, rc);
    AacEncoderHandle enc = aac_encoder_create(sr, 2, 64000, AAC_AOT_LC, rc);
    CHECK(aac_encoder_set_afterburner(enc, 1) == AAC_OK && aac_encoder_set_afterburner(enc, 0) == AAC_OK,
          "switch on and off");
    std::vector<uint8_t> toggled;
    std::vector<float> block(2048);
    std::vector<uint8_t> buf(16384);
    for (size_t pos = 0; pos < pcm.size(); pos += block.size()) {
      std::fill(block.begin(), block.end(), 0.0f);
      const size_t n = std::min(block.size(), pcm.size() - pos);
      memcpy(block.data(), pcm.data() + pos, n * sizeof(float));
      const int len = aac_encoder_encode(enc, block.data(), 1024, buf.data(), (int)buf.size());
      CHECK(len > 0, "encode");
      toggled.insert(toggled.end(), buf.begin(), buf.begin() + std::max(len, 0));
    }
    const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
    toggled.insert(toggled.end(), buf.begin(), buf.begin() + std::max(len, 0));
    aac_encoder_destroy(enc);
    CHECK(toggled == untouched, "rc%d: the switched-off stream equals the untouched one", rc);
    /* Twice on gives the same stream too: the pass is deterministic. */
    const std::vector<uint8_t> on1 = encode_with(pcm, sr, 2, 64000, rc, 1);
    const std::vector<uint8_t> on2 = encode_with(pcm, sr, 2, 64000, rc, 1);
    CHECK(on1 == on2, "rc%d: the afterburner is deterministic", rc);
    CHECK(on1 != untouched, "rc%d: the afterburner changes the stream", rc);
  }
}

/* Switching mid-stream takes effect at the next frame and leaves the
 * frames before it untouched. */
static void test_switch_midstream() {
  const int sr = 44100;
  const std::vector<float> pcm = gen_music(sr * 2, sr, 2);
  const std::vector<uint8_t> off = encode_with(pcm, sr, 2, 48000, AAC_RC_CBR, 0);
  AacEncoderHandle enc = aac_encoder_create(sr, 2, 48000, AAC_AOT_LC, AAC_RC_CBR);
  std::vector<uint8_t> mixed;
  std::vector<float> block(2048);
  std::vector<uint8_t> buf(16384);
  int frames = 0;
  size_t prefix = 0;
  for (size_t pos = 0; pos < pcm.size(); pos += block.size(), frames++) {
    if (frames == 20) {
      prefix = mixed.size();
      aac_encoder_set_afterburner(enc, 1);
    }
    std::fill(block.begin(), block.end(), 0.0f);
    const size_t n = std::min(block.size(), pcm.size() - pos);
    memcpy(block.data(), pcm.data() + pos, n * sizeof(float));
    const int len = aac_encoder_encode(enc, block.data(), 1024, buf.data(), (int)buf.size());
    CHECK(len > 0, "encode");
    mixed.insert(mixed.end(), buf.begin(), buf.begin() + std::max(len, 0));
  }
  const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
  mixed.insert(mixed.end(), buf.begin(), buf.begin() + std::max(len, 0));
  aac_encoder_destroy(enc);
  CHECK(prefix > 0 && off.size() > prefix && memcmp(mixed.data(), off.data(), prefix) == 0,
        "frames before the switch are untouched");
  int errors = 0;
  decode_stream(mixed, sr, 2, &errors);
  CHECK(errors == 0, "the mixed stream decodes");
}

/* At 32 and 48 kbit/s the pass raises the segmental SNR measurably; at
 * no bitrate does it lower it.  Frames stay within the rate control's
 * limits and the bitrate is held. */
static void test_quality_and_budget(const std::string& tmp) {
  const int sr = 44100;
  const std::vector<float> pcm = gen_music(sr * 6, sr, 2);
  const bool ffmpeg = have_ffmpeg();
  for (int bitrate : {32000, 48000, 64000, 96000}) {
    const std::vector<uint8_t> off = encode_with(pcm, sr, 2, bitrate, AAC_RC_CBR, 0);
    const std::vector<uint8_t> on = encode_with(pcm, sr, 2, bitrate, AAC_RC_CBR, 1);
    int errors_off = 0;
    int errors_on = 0;
    const std::vector<float> out_off = decode_stream(off, sr, 2, &errors_off);
    const std::vector<float> out_on = decode_stream(on, sr, 2, &errors_on);
    CHECK(errors_off == 0 && errors_on == 0, "%d: streams decode", bitrate);
    const double snr_off = segmental_snr_db(pcm, out_off, 2, 2048);
    const double snr_on = segmental_snr_db(pcm, out_on, 2, 2048);
    const double kbps_off = kbps_of(off, sr, 1024);
    const double kbps_on = kbps_of(on, sr, 1024);
    printf("%3d kbps: segmental SNR off %.2f dB, on %.2f dB (gain %+.2f dB); %.1f / %.1f kbps\n",
           bitrate / 1000, snr_off, snr_on, snr_on - snr_off, kbps_off, kbps_on);
    CHECK(snr_on >= snr_off, "%d: the afterburner does not lower the segmental SNR", bitrate);
    if (bitrate <= 48000) {
      CHECK(snr_on >= snr_off + 0.5, "%d: the afterburner gains at least 0.5 dB", bitrate);
    }
    CHECK(fabs(kbps_on - bitrate / 1000.0) < 0.06 * bitrate / 1000.0, "%d: achieved %.1f kbps",
          bitrate, kbps_on);
    for (const AdtsFrame& f : split_adts(on)) {
      CHECK(f.size <= 2 * 6144 / 8 + AAC_ADTS_HEADER_SIZE, "frame of %zu bytes", f.size);
      AacAdtsHeader h;
      aac_adts_parse(&h, on.data() + f.offset, (int)f.size);
      CHECK(h.buffer_fullness != 0x7FF, "CBR frames carry the fullness");
    }
    /* The reservoir never goes negative: the stream is at most one
     * reservoir longer than without the pass. */
    CHECK(on.size() <= off.size() + 2 * 6144 / 8, "%d: stream grows by %zd bytes", bitrate,
          (ssize_t)on.size() - (ssize_t)off.size());
    if (ffmpeg) {
      const std::string aac = tmp + "/ab.aac";
      const std::string raw = tmp + "/ab.f32";
      CHECK(write_file(aac, on.data(), on.size()), "write stream");
      const bool ok = run_command("ffmpeg -y -loglevel error -i '" + aac + "' -f f32le -acodec pcm_f32le '" + raw + "'");
      CHECK(ok, "%d: ffmpeg decodes the afterburner stream", bitrate);
      const std::vector<float> theirs = read_f32(raw);
      const size_t n = std::min(theirs.size(), out_on.size());
      CHECK(n > pcm.size() / 2, "%d: ffmpeg output length %zu", bitrate, theirs.size());
      const double agree = snr_db(out_on.data(), theirs.data(), n);
      CHECK(agree > 90.0, "%d: ffmpeg agrees with our decoder (%.1f dB)", bitrate, agree);
    }
  }
  /* Constrained VBR keeps its frame limit with the pass on. */
  const std::vector<uint8_t> cvbr = encode_with(gen_noise(sr * 3, 2), sr, 2, 96000, AAC_RC_CVBR, 1);
  const int avg_bytes = 96000 * 1024 / sr / 8;
  for (const AdtsFrame& f : split_adts(cvbr)) {
    CHECK((int)f.size <= avg_bytes * 3 / 2 + AAC_ADTS_HEADER_SIZE + 1,
          "CVBR frame of %zu bytes exceeds 1.5x the average", f.size);
  }
  /* The quality modes only redistribute the bits a frame already has.
   * The rate loop's count is an upper bound on the written frame (byte
   * alignment, an M/S mask counted whether or not it is written), so a
   * frame may still end one byte longer. */
  const std::vector<uint8_t> tvbr_off = encode_with(pcm, sr, 2, 64000, AAC_RC_TVBR, 0, AAC_AOT_LC, 60);
  const std::vector<uint8_t> tvbr_on = encode_with(pcm, sr, 2, 64000, AAC_RC_TVBR, 1, AAC_AOT_LC, 60);
  const std::vector<AdtsFrame> frames_off = split_adts(tvbr_off);
  const std::vector<AdtsFrame> frames_on = split_adts(tvbr_on);
  printf("tvbr q60: off %zu bytes, on %zu bytes\n", tvbr_off.size(), tvbr_on.size());
  CHECK(frames_on.size() == frames_off.size(), "TVBR frame count");
  for (size_t i = 0; i < frames_on.size() && i < frames_off.size(); i++) {
    CHECK(frames_on[i].size <= frames_off[i].size + 1, "TVBR frame %zu grows from %zu to %zu bytes",
          i, frames_off[i].size, frames_on[i].size);
  }
}

/* Mono, 5.1 and HE-AAC v1 and v2 streams go through the pass and decode
 * at their bitrate. */
static void test_layouts_and_sbr() {
  struct Case {
    int sr, channels, bitrate;
    AacObjectType aot;
  };
  const Case cases[] = {{48000, 1, 48000, AAC_AOT_LC},
                        {48000, 6, 192000, AAC_AOT_LC},
                        {44100, 2, 48000, AAC_AOT_SBR},
                        {44100, 2, 32000, AAC_AOT_PS},
                        {48000, 1, 32000, AAC_AOT_SBR}};
  for (const Case& k : cases) {
    const std::vector<float> pcm = k.channels == 6 ? gen_multichannel(k.sr * 2, k.sr, 6)
                                                   : gen_music(k.sr * 3, k.sr, k.channels);
    const std::vector<uint8_t> on = encode_with(pcm, k.sr, k.channels, k.bitrate, AAC_RC_CBR, 1, k.aot);
    const std::vector<uint8_t> off = encode_with(pcm, k.sr, k.channels, k.bitrate, AAC_RC_CBR, 0, k.aot);
    const int frame = k.aot == AAC_AOT_LC ? 1024 : 2048;
    int errors = 0;
    const std::vector<float> out = decode_stream(on, k.sr, k.channels == 6 ? 6 : 0, &errors);
    const double kbps = kbps_of(on, k.sr, frame);
    printf("aot %d, %d ch, %d kbps: %.1f kbps on, %.1f kbps off\n", k.aot, k.channels,
           k.bitrate / 1000, kbps, kbps_of(off, k.sr, frame));
    CHECK(errors == 0, "aot %d %d ch: decodes", k.aot, k.channels);
    CHECK(fabs(kbps - k.bitrate / 1000.0) < 0.06 * k.bitrate / 1000.0, "aot %d %d ch: %.1f kbps",
          k.aot, k.channels, kbps);
    CHECK(on != off, "aot %d %d ch: the pass changes the stream", k.aot, k.channels);
    const int per_channel = 6144 / 8;
    for (const AdtsFrame& f : split_adts(on)) {
      CHECK(f.size <= (size_t)(per_channel * k.channels + AAC_ADTS_HEADER_SIZE + 272 * 2),
            "frame of %zu bytes", f.size);
    }
  }
}

int main() {
  const std::string tmp = make_temp_dir();
  test_off_identity();
  test_switch_midstream();
  test_quality_and_budget(tmp);
  test_layouts_and_sbr();
  remove_temp_dir(tmp);
  return test_summary("test_afterburner");
}
