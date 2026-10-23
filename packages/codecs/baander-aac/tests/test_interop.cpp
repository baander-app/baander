/* Interoperability with FFmpeg's AAC encoder and decoder, when ffmpeg is
 * on PATH: our decoder must reproduce FFmpeg's decode of FFmpeg-encoded
 * streams to floating-point precision, and FFmpeg must decode our streams
 * exactly as our decoder does.  Without ffmpeg the test is skipped. */
#include "test_util.h"

static std::string g_dir;

static std::string path(const char* name) { return g_dir + "/" + name; }

static std::string ffmpeg_cmd(const std::string& args) {
  return "ffmpeg -nostdin -v error -y " + args;
}

/* FFmpeg encodes the signal with `options`; both decoders decode it. */
static void ffmpeg_encoded(const char* name, const std::vector<float>& pcm, int sr, int channels,
                           const char* options) {
  CHECK(write_file(path("in.f32"), pcm.data(), pcm.size() * sizeof(float)), "write input");
  char cmd[512];
  snprintf(cmd, sizeof(cmd), "-f f32le -ar %d -ac %d -i %s -c:a aac %s -f adts %s", sr, channels,
           path("in.f32").c_str(), options, path("ff.aac").c_str());
  CHECK(run_command(ffmpeg_cmd(cmd)), "%s: ffmpeg encode", name);
  snprintf(cmd, sizeof(cmd), "-i %s -f f32le -ac %d -ar %d %s", path("ff.aac").c_str(), channels,
           sr, path("ff.f32").c_str());
  CHECK(run_command(ffmpeg_cmd(cmd)), "%s: ffmpeg decode", name);
  const std::vector<uint8_t> stream = read_file(path("ff.aac"));
  int errors = 0;
  const std::vector<float> ours = decode_stream(stream, sr, channels, &errors);
  const std::vector<float> theirs = read_f32(path("ff.f32"));
  CHECK(errors == 0, "%s: %d frames failed in our decoder", name, errors);
  const size_t n = std::min(ours.size(), theirs.size());
  CHECK(n > 0 && ours.size() == theirs.size(), "%s: %zu vs %zu samples", name, ours.size(),
        theirs.size());
  const double d = snr_db(theirs.data(), ours.data(), n);
  printf("%-28s ffmpeg->ours vs ffmpeg->ffmpeg: %.1f dB\n", name, d);
  CHECK(d > 90.0, "%s: decoders disagree (%.1f dB)", name, d);
}

/* We encode; FFmpeg's decode must match ours. */
static void our_encoded(const char* name, const std::vector<float>& pcm, int sr, int channels,
                        int bitrate, AacRateControl rc) {
  int err = 0;
  const std::vector<uint8_t> stream = encode_stream(pcm, sr, channels, bitrate, rc, 0, &err);
  CHECK(err == 0 && !stream.empty(), "%s: encode", name);
  CHECK(write_file(path("ours.aac"), stream.data(), stream.size()), "write stream");
  char cmd[512];
  snprintf(cmd, sizeof(cmd), "-i %s -f f32le -ar %d %s", path("ours.aac").c_str(), sr,
           path("ours.ff.f32").c_str());
  CHECK(run_command(ffmpeg_cmd(cmd)), "%s: ffmpeg refused our stream", name);
  int errors = 0;
  const std::vector<float> ours = decode_stream(stream, sr, channels, &errors);
  const std::vector<float> theirs = read_f32(path("ours.ff.f32"));
  CHECK(errors == 0, "%s: %d frames failed in our decoder", name, errors);
  const size_t n = std::min(ours.size(), theirs.size());
  CHECK(n > 0 && ours.size() == theirs.size(), "%s: %zu vs %zu samples", name, ours.size(),
        theirs.size());
  const double d = snr_db(theirs.data(), ours.data(), n);
  const double q = aligned_snr_db(pcm, theirs, channels, 2048);
  printf("%-28s ours->ffmpeg vs ours->ours: %.1f dB (quality %.1f dB)\n", name, d, q);
  CHECK(d > 90.0, "%s: decoders disagree on our stream (%.1f dB)", name, d);
}

/* We encode HE-AAC; FFmpeg's decode of the stream must match ours.
 * FFmpeg widens a mono SBR stream to stereo, so only the channels our
 * decoder produces are compared (two of them with parametric stereo). */
static void our_he_encoded(const char* name, AacObjectType aot, int sr, int channels,
                           int bitrate) {
  const std::vector<float> pcm = gen_wideband(sr * 3, sr, channels);
  int err = 0;
  const std::vector<uint8_t> stream =
      encode_stream(pcm, sr, channels, bitrate, AAC_RC_CBR, 0, &err, aot);
  CHECK(err == 0 && !stream.empty(), "%s: encode", name);
  CHECK(write_file(path("ours.aac"), stream.data(), stream.size()), "write stream");
  char cmd[512];
  snprintf(cmd, sizeof(cmd), "-i %s -f f32le %s", path("ours.aac").c_str(),
           path("ours.ff.f32").c_str());
  CHECK(run_command(ffmpeg_cmd(cmd)), "%s: ffmpeg refused our stream", name);
  int errors = 0;
  const std::vector<float> ours = decode_stream(stream, sr, 0, &errors);
  const std::vector<float> theirs = read_f32(path("ours.ff.f32"));
  CHECK(errors == 0, "%s: %d frames failed in our decoder", name, errors);
  const int their_channels = channels == 1 ? 2 : channels;
  const size_t frames = std::min(theirs.size() / their_channels, ours.size() / channels);
  CHECK(frames > 0 && theirs.size() / their_channels == ours.size() / channels,
        "%s: %zu vs %zu frames", name, theirs.size() / their_channels, ours.size() / channels);
  std::vector<float> a(frames * channels), b(frames * channels);
  for (size_t i = 0; i < frames; i++) {
    for (int c = 0; c < channels; c++) {
      a[i * channels + c] = theirs[i * their_channels + c];
      b[i * channels + c] = ours[i * channels + c];
    }
  }
  const double d = snr_db(a.data(), b.data(), a.size());
  const std::vector<float> ref = lowpass_channel(pcm, channels, 0, 3500.0f, sr);
  const std::vector<float> got = lowpass_channel(a, channels, 0, 3500.0f, sr);
  const size_t m = std::min(ref.size(), got.size() - 5635);
  const double q = snr_db(ref.data(), got.data() + 5635, m);
  printf("%-28s ours->ffmpeg vs ours->ours: %.1f dB (low band %.1f dB)\n", name, d, q);
  CHECK(d > 90.0, "%s: decoders disagree on our stream (%.1f dB)", name, d);
  CHECK(q > 12.0, "%s: low band %.1f dB", name, q);
}

/* ── 960-sample frames over LATM/LOAS ──────────────────────────── */

/* FFmpeg decodes 960-sample frames only when an AudioSpecificConfig says
 * so (ADTS cannot), so our ADTS stream is rewrapped into a LOAS
 * AudioSyncStream of AudioMuxElements (ISO/IEC 14496-3 1.7.3): one
 * program, one layer, audioMuxVersion 0, frameLengthType 0, the
 * StreamMuxConfig in band with every frame. */
struct LatmWriter {
  std::vector<uint8_t> bytes;
  uint32_t acc = 0;
  int nbits = 0;
  void put(uint32_t v, int n) {
    for (int i = n - 1; i >= 0; i--) {
      acc = (acc << 1) | ((v >> i) & 1);
      if (++nbits == 8) {
        bytes.push_back((uint8_t)acc);
        acc = 0;
        nbits = 0;
      }
    }
  }
  void align() {
    while (nbits) {
      put(0, 1);
    }
  }
};

static std::vector<uint8_t> adts_to_loas_960(const std::vector<uint8_t>& adts) {
  std::vector<uint8_t> out;
  for (const AdtsFrame& f : split_adts(adts)) {
    const uint8_t* h = adts.data() + f.offset;
    const int rate_index = (h[2] >> 2) & 0xF;
    const int channel_config = ((h[2] & 1) << 2) | (h[3] >> 6);
    const int header = (h[1] & 1) ? 7 : 9;
    LatmWriter w;
    w.put(0, 1); /* useSameStreamMux: the StreamMuxConfig follows */
    w.put(0, 1); /* audioMuxVersion */
    w.put(1, 1); /* allStreamsSameTimeFraming */
    w.put(0, 6); /* numSubFrames */
    w.put(0, 4); /* numProgram */
    w.put(0, 3); /* numLayer */
    /* AudioSpecificConfig: AAC-LC, GASpecificConfig with frameLengthFlag */
    w.put(AAC_AOT_LC, 5);
    w.put((uint32_t)rate_index, 4);
    w.put((uint32_t)channel_config, 4);
    w.put(1, 1); /* frameLengthFlag: 960 */
    w.put(0, 1); /* dependsOnCoreCoder */
    w.put(0, 1); /* extensionFlag */
    w.put(0, 3);    /* frameLengthType */
    w.put(0xFF, 8); /* latmBufferFullness */
    w.put(0, 1);    /* otherDataPresent */
    w.put(0, 1);    /* crcCheckPresent */
    /* PayloadLengthInfo and PayloadMux */
    size_t n = f.size - header;
    while (n >= 255) {
      w.put(255, 8);
      n -= 255;
    }
    w.put((uint32_t)n, 8);
    for (size_t i = header; i < f.size; i++) {
      w.put(h[i], 8);
    }
    w.align();
    /* AudioSyncStream: sync word and the element's length */
    const uint32_t len = (uint32_t)w.bytes.size();
    out.push_back(0x56);
    out.push_back((uint8_t)(0xE0 | (len >> 8)));
    out.push_back((uint8_t)len);
    out.insert(out.end(), w.bytes.begin(), w.bytes.end());
  }
  return out;
}

/* We encode with 960-sample frames; FFmpeg's decode of the LOAS-wrapped
 * stream must match our decode of the ADTS stream. */
static void our_encoded_960(const char* name, const std::vector<float>& pcm, int sr, int channels,
                            int bitrate, AacRateControl rc) {
  std::vector<uint8_t> stream;
  AacEncoderHandle enc = aac_encoder_create(sr, channels, bitrate, AAC_AOT_LC, rc);
  CHECK(enc != nullptr, "%s: create", name);
  CHECK(aac_encoder_set_frame_length(enc, 960) == AAC_OK, "%s: frame length", name);
  std::vector<float> block((size_t)960 * channels);
  std::vector<uint8_t> buf(8192);
  for (size_t pos = 0; pos < pcm.size(); pos += block.size()) {
    std::fill(block.begin(), block.end(), 0.0f);
    const size_t n = std::min(block.size(), pcm.size() - pos);
    memcpy(block.data(), pcm.data() + pos, n * sizeof(float));
    const int len = aac_encoder_encode(enc, block.data(), 960, buf.data(), (int)buf.size());
    CHECK(len > 0, "%s: encode %d", name, len);
    if (len > 0) {
      stream.insert(stream.end(), buf.begin(), buf.begin() + len);
    }
  }
  const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
  CHECK(len > 0, "%s: flush %d", name, len);
  if (len > 0) {
    stream.insert(stream.end(), buf.begin(), buf.begin() + len);
  }
  aac_encoder_destroy(enc);
  const std::vector<uint8_t> loas = adts_to_loas_960(stream);
  CHECK(write_file(path("ours.loas"), loas.data(), loas.size()), "write stream");
  char cmd[512];
  snprintf(cmd, sizeof(cmd), "-f loas -i %s -f f32le -ar %d %s", path("ours.loas").c_str(), sr,
           path("ours.ff.f32").c_str());
  CHECK(run_command(ffmpeg_cmd(cmd)), "%s: ffmpeg refused our 960 stream", name);
  AacDecoderHandle dec = aac_decoder_create(sr, channels);
  CHECK(aac_decoder_set_frame_length(dec, 960) == AAC_OK, "%s: decoder frame length", name);
  int errors = 0;
  const std::vector<float> ours = decode_stream(stream, sr, channels, &errors, dec);
  aac_decoder_destroy(dec);
  const std::vector<float> theirs = read_f32(path("ours.ff.f32"));
  CHECK(errors == 0, "%s: %d frames failed in our decoder", name, errors);
  const size_t n = std::min(ours.size(), theirs.size());
  CHECK(n > 0 && ours.size() == theirs.size(), "%s: %zu vs %zu samples", name, ours.size(),
        theirs.size());
  const double d = snr_db(theirs.data(), ours.data(), n);
  const double q = aligned_snr_db(pcm, theirs, channels, 1920);
  printf("%-28s ours->ffmpeg vs ours->ours (960): %.1f dB (quality %.1f dB)\n", name, d, q);
  CHECK(d > 90.0, "%s: decoders disagree on our 960 stream (%.1f dB)", name, d);
}

/* FFmpeg encodes `channels` channels with a named layout; both decoders
 * then produce `out_channels` channels (the stream's own channels, or a
 * stereo or mono downmix) from the same stream. */
static void ffmpeg_layout(const char* name, const char* layout, int channels, int out_channels,
                          double min_db) {
  const int sr = 48000;
  const std::vector<float> pcm = gen_multichannel(sr * 3, sr, channels);
  CHECK(write_file(path("in.f32"), pcm.data(), pcm.size() * sizeof(float)), "write input");
  char cmd[512];
  snprintf(cmd, sizeof(cmd),
           "-f f32le -ar %d -ac %d -channel_layout %s -i %s -c:a aac -b:a %dk -f adts %s", sr,
           channels, layout, path("in.f32").c_str(), 64 * channels, path("ff.aac").c_str());
  CHECK(run_command(ffmpeg_cmd(cmd)), "%s: ffmpeg encode", name);
  /* Without -ac FFmpeg keeps the stream's own layout; with it, FFmpeg
   * picks a default layout for the count (2.1 for three channels). */
  char ac[32] = "";
  if (out_channels != channels) {
    snprintf(ac, sizeof(ac), "-ac %d ", out_channels);
  }
  snprintf(cmd, sizeof(cmd), "-i %s -f f32le %s-ar %d %s", path("ff.aac").c_str(), ac, sr,
           path("ff.f32").c_str());
  CHECK(run_command(ffmpeg_cmd(cmd)), "%s: ffmpeg decode", name);
  const std::vector<uint8_t> stream = read_file(path("ff.aac"));
  int errors = 0;
  const std::vector<float> ours =
      decode_stream(stream, sr, out_channels == channels ? 0 : out_channels, &errors);
  const std::vector<float> theirs = read_f32(path("ff.f32"));
  CHECK(errors == 0, "%s: %d frames failed in our decoder", name, errors);
  const size_t n = std::min(ours.size(), theirs.size());
  CHECK(n > 0 && ours.size() == theirs.size(), "%s: %zu vs %zu samples", name, ours.size(),
        theirs.size());
  const double d = snr_db(theirs.data(), ours.data(), n);
  printf("%-28s ffmpeg->ours vs ffmpeg->ffmpeg (%d ch): %.1f dB\n", name, out_channels, d);
  CHECK(d > min_db, "%s: decoders disagree (%.1f dB)", name, d);
}

/* One of the committed HE-AAC streams through ffmpeg and through our
 * decoder.  ffmpeg widens a mono HE-AAC stream to stereo (it assumes
 * parametric stereo may follow), so only its first channel is compared;
 * an HE-AAC v2 stream is stereo in both decoders. */
static void he_stream(const char* name, int channels, double min_db) {
  const std::vector<uint8_t> stream = read_file(data_path(name));
  CHECK(!stream.empty(), "%s: stream missing", name);
  const std::string cmd = "-i '" + data_path(name) + "' -f f32le '" + path("ff.f32") + "'";
  CHECK(run_command(ffmpeg_cmd(cmd)), "%s: ffmpeg decode", name);
  const std::vector<float> theirs = read_f32(path("ff.f32"));
  const int their_channels = channels == 1 ? 2 : channels;
  int errors = 0;
  const std::vector<float> ours = decode_stream(stream, 48000, 0, &errors);
  CHECK(errors == 0, "%s: %d frames failed", name, errors);
  const size_t frames = std::min(theirs.size() / their_channels, ours.size() / channels);
  std::vector<float> a(frames * channels), b(frames * channels);
  for (size_t i = 0; i < frames; i++) {
    for (int c = 0; c < channels; c++) {
      a[i * channels + c] = theirs[i * their_channels + c];
      b[i * channels + c] = ours[i * channels + c];
    }
  }
  const double d = snr_db(a.data(), b.data(), a.size());
  printf("%-28s ffmpeg vs ours: %.1f dB\n", name, d);
  CHECK(d > min_db, "%s: %.1f dB", name, d);
}

int main() {
  if (!have_ffmpeg()) {
    printf("SKIP: ffmpeg not found\n");
    return 0;
  }
  g_dir = make_temp_dir();
  if (g_dir.empty()) {
    printf("SKIP: no temporary directory\n");
    return 0;
  }
  const int n = 44100 * 4;
  ffmpeg_encoded("transients 44.1k stereo", gen_transients(n, 44100, 2), 44100, 2, "-b:a 96k");
  ffmpeg_encoded("harmonics 48k mono", gen_harmonics(n, 48000, 1), 48000, 1, "-b:a 64k");
  ffmpeg_encoded("noise 44.1k stereo 32k", gen_noise(n, 2), 44100, 2, "-b:a 32k");
  ffmpeg_encoded("tones 22.05k stereo", gen_tones(n / 2, 22050, 2), 22050, 2, "-b:a 64k");
  ffmpeg_encoded("transients fast coder", gen_transients(n, 44100, 2), 44100, 2,
                 "-b:a 128k -aac_coder fast");
  ffmpeg_encoded("noise 96k stereo", gen_noise(96000 * 2, 2), 96000, 2, "-b:a 192k");


  ffmpeg_layout("3.0 native", "3.0", 3, 3, 90.0);
  ffmpeg_layout("4.0 native", "4.0", 4, 4, 90.0);
  ffmpeg_layout("5.0 native", "5.0", 5, 5, 90.0);
  ffmpeg_layout("5.1 native", "5.1", 6, 6, 90.0);
  ffmpeg_layout("6.1 pce native", "6.1", 7, 7, 90.0);
  ffmpeg_layout("7.1 native", "7.1", 8, 8, 90.0);
  ffmpeg_layout("5.1 stereo downmix", "5.1", 6, 2, 60.0);
  ffmpeg_layout("5.1 mono downmix", "5.1", 6, 1, 60.0);
  ffmpeg_layout("mono to stereo", "mono", 1, 2, 60.0);
  ffmpeg_layout("stereo to mono", "stereo", 2, 1, 60.0);

  our_encoded("transients 44.1k 96k cbr", gen_transients(n, 44100, 2), 44100, 2, 96000, AAC_RC_CBR);
  our_encoded("harmonics 48k mono 64k", gen_harmonics(n, 48000, 1), 48000, 1, 64000, AAC_RC_CBR);
  our_encoded("noise 44.1k 256k abr", gen_noise(n, 2), 44100, 2, 256000, AAC_RC_ABR);
  our_encoded("tones 16k mono tvbr", gen_harmonics(16000 * 3, 16000, 1), 16000, 1, 48000,
              AAC_RC_TVBR);
  our_encoded("harmonics 44.1k 48k intensity", gen_harmonics(n, 44100, 2), 44100, 2, 48000,
              AAC_RC_CBR);
  our_encoded("noise 44.1k 64k pns", gen_noise(n, 2), 44100, 2, 64000, AAC_RC_CBR);
  our_encoded("3.0 48k 192k", gen_multichannel(48000 * 3, 48000, 3), 48000, 3, 192000, AAC_RC_CBR);
  our_encoded("5.1 48k 384k", gen_multichannel(48000 * 3, 48000, 6), 48000, 6, 384000, AAC_RC_CBR);
  our_encoded("6.1 pce 48k 448k", gen_multichannel(48000 * 3, 48000, 7), 48000, 7, 448000,
              AAC_RC_CBR);
  our_encoded("7.1 48k 512k", gen_multichannel(48000 * 3, 48000, 8), 48000, 8, 512000,
              AAC_RC_CBR);
  our_encoded_960("960 transients 44.1k 96k", gen_transients(n, 44100, 2), 44100, 2, 96000,
                  AAC_RC_CBR);
  our_encoded_960("960 harmonics 48k mono 64k", gen_harmonics(n, 48000, 1), 48000, 1, 64000,
                  AAC_RC_CBR);
  our_encoded_960("960 noise 44.1k 64k pns", gen_noise(n, 2), 44100, 2, 64000, AAC_RC_CBR);
  our_encoded_960("960 harmonics 44.1k 48k is", gen_harmonics(n, 44100, 2), 44100, 2, 48000,
                  AAC_RC_CBR);
  our_encoded_960("960 tones 16k mono tvbr", gen_harmonics(16000 * 3, 16000, 1), 16000, 1, 48000,
                  AAC_RC_TVBR);
  our_encoded_960("960 5.1 48k 384k", gen_multichannel(48000 * 3, 48000, 6), 48000, 6, 384000,
                  AAC_RC_CBR);
  our_he_encoded("he 44.1k mono 32k", AAC_AOT_SBR, 44100, 1, 32000);
  our_he_encoded("he 48k stereo 64k", AAC_AOT_SBR, 48000, 2, 64000);
  our_he_encoded("he 48k 5.1 192k", AAC_AOT_SBR, 48000, 6, 192000);
  our_he_encoded("hev2 44.1k 32k", AAC_AOT_PS, 44100, 2, 32000);
  he_stream("he_mono_24k.aac", 1, 70.0);
  he_stream("he_stereo_40k.aac", 2, 70.0);
  he_stream("he_stereo_96k.aac", 2, 70.0);
  he_stream("hev2_stereo_32k.aac", 2, 70.0);
  remove_temp_dir(g_dir);
  return test_summary("test_interop");
}
