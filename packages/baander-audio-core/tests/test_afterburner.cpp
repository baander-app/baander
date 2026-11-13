/* Afterburner: it measures a band's noise as the rate loop does, from the
 * values the frame codes; switched off it leaves the stream untouched,
 * switched on it keeps every frame within the rate control's limits,
 * raises the segmental SNR at low bitrates and never lowers it, and its
 * streams decode in our decoder and in FFmpeg. */
#include "bitstream.h"
#include "encoder.h"
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

/* AAC-LC runs the pass by default: an encoder switched off and on again
 * before its first frame produces the bytes of one that never heard of
 * the switch, and the switch is rejected without a handle. */
static void test_default_on() {
  const int sr = 44100;
  const std::vector<float> pcm = gen_music(sr * 3, sr, 2);
  CHECK(aac_encoder_set_afterburner(nullptr, 1) == AAC_ERR_INVALID_ARG, "null handle");
  for (AacRateControl rc : {AAC_RC_CBR, AAC_RC_TVBR}) {
    const std::vector<uint8_t> untouched = encode_stream(pcm, sr, 2, 64000, rc);
    AacEncoderHandle enc = aac_encoder_create(sr, 2, 64000, AAC_AOT_LC, rc);
    CHECK(aac_encoder_set_afterburner(enc, 0) == AAC_OK && aac_encoder_set_afterburner(enc, 1) == AAC_OK,
          "switch off and on");
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
    CHECK(toggled == untouched, "rc%d: the switched-on stream equals the untouched one", rc);
    /* AAC-LC runs the pass by default, and twice on gives the same
     * stream: the pass is deterministic. */
    const std::vector<uint8_t> on1 = encode_with(pcm, sr, 2, 64000, rc, 1);
    const std::vector<uint8_t> on2 = encode_with(pcm, sr, 2, 64000, rc, 1);
    const std::vector<uint8_t> off = encode_with(pcm, sr, 2, 64000, rc, 0);
    CHECK(on1 == on2, "rc%d: the afterburner is deterministic", rc);
    CHECK(on1 == untouched, "rc%d: the afterburner is on by default", rc);
    CHECK(off != untouched, "rc%d: the afterburner changes the stream", rc);
  }
}

/* Switching mid-stream takes effect at the next frame and leaves the
 * frames before it untouched. */
static void test_switch_midstream() {
  const int sr = 44100;
  const std::vector<float> pcm = gen_music(sr * 2, sr, 2);
  const std::vector<uint8_t> off = encode_with(pcm, sr, 2, 48000, AAC_RC_CBR, 0);
  AacEncoderHandle enc = aac_encoder_create(sr, 2, 48000, AAC_AOT_LC, AAC_RC_CBR);
  aac_encoder_set_afterburner(enc, 0);
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

/* After every frame, each coded band holds quantize_band's values at its
 * scalefactor: the kernel's own index, min((int)(x34 scale + 0.4054),
 * 8191), for every line.  So aac_enc_band_noise, through which the rate
 * loop and the afterburner both measure a band, is the noise of the
 * values the frame codes, summed in the kernel's order: line i of every
 * window into accumulator i % 8, the accumulators combined as ((a0 + a4)
 * + (a2 + a6)) + ((a1 + a5) + (a3 + a7)). */
static void test_band_noise_of_coded_values() {
  const int sr = 48000;
  const std::vector<float> pcm = gen_music(sr * 2, sr, 2);
  int bands = 0, wrong_index = 0, wrong_noise = 0;
  for (int bitrate : {64000, 128000}) {
    AacEncoderHandle enc = aac_encoder_create(sr, 2, bitrate, AAC_AOT_LC, AAC_RC_CBR);
    CHECK(enc != nullptr, "create at %d bit/s", bitrate);
    if (!enc) {
      continue;
    }
    const AacEncoderState* s = static_cast<const AacEncoderState*>(enc);
    std::vector<uint8_t> buf(8192);
    for (size_t pos = 0; pos + 2 * 1024 <= pcm.size(); pos += 2 * 1024) {
      CHECK(aac_encoder_encode(enc, pcm.data() + pos, 1024, buf.data(), (int)buf.size()) > 0,
            "frame at %zu", pos);
      for (int c = 0; c < 2; c++) {
        const AacEncChannel* ch = &s->ch[c];
        const AacIcsInfo* ics = &ch->ics;
        const int* off = ics->swb_offset;
        for (int g = 0, w0 = 0; g < ics->num_window_groups; w0 += ics->group_len[g++]) {
          for (int b = 0; b < ics->num_swb; b++) {
            const int idx = g * ics->num_swb + b;
            const int sf = ch->sf[idx];
            if (ch->cb[idx] < 1 || ch->cb[idx] > AAC_NUM_CODEBOOKS) {
              continue;
            }
            const float scale = exp2f(-0.1875f * (float)(sf - 100));
            const float gain = exp2f(0.25f * (float)(sf - 100));
            float a[8] = {0.0f, 0.0f, 0.0f, 0.0f, 0.0f, 0.0f, 0.0f, 0.0f};
            int max_mag = 0, off_index = 0;
            for (int w = w0; w < w0 + ics->group_len[g]; w++) {
              for (int i = 0; i < off[b + 1] - off[b]; i++) {
                const int line = w * 128 + off[b] + i;
                const int q = abs(ch->quant[line]);
                const float v = ch->x34[line] * scale + 0.4054f;
                off_index += q != (int)std::min(v, 8191.0f);
                const float d = fabsf(ch->coef[line]) - aac_pow43[q] * gain;
                a[i % 8] += d * d;
                max_mag = std::max(max_mag, q);
              }
            }
            if (max_mag == 0) {
              continue; /* a zero band inside a section carries its neighbour's scalefactor */
            }
            const float want = ((a[0] + a[4]) + (a[2] + a[6])) + ((a[1] + a[5]) + (a[3] + a[7]));
            const float got = aac_enc_band_noise(s->dsp, ch, g, w0, b, sf);
            wrong_index += off_index;
            wrong_noise += memcmp(&want, &got, sizeof want) != 0;
            bands++;
          }
        }
      }
    }
    aac_encoder_destroy(enc);
  }
  printf("band noise: %d coded bands, %d lines off the kernel's index, %d noises differ\n", bands,
         wrong_index, wrong_noise);
  CHECK(bands > 1000, "%d coded bands checked", bands);
  CHECK(wrong_index == 0, "%d coded lines differ from the kernel's index", wrong_index);
  CHECK(wrong_noise == 0, "%d bands: the measured noise is not that of the coded values",
        wrong_noise);
}

int main() {
  const std::string tmp = make_temp_dir();
  test_band_noise_of_coded_values();
  test_default_on();
  test_switch_midstream();
  test_quality_and_budget(tmp);
  test_layouts_and_sbr();
  remove_temp_dir(tmp);
  return test_summary("test_afterburner");
}
