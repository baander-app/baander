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
  he_stream("he_mono_24k.aac", 1, 70.0);
  he_stream("he_stereo_40k.aac", 2, 70.0);
  he_stream("he_stereo_96k.aac", 2, 70.0);
  he_stream("hev2_stereo_32k.aac", 2, 70.0);
  remove_temp_dir(g_dir);
  return test_summary("test_interop");
}
