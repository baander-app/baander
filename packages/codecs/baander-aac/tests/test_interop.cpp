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
  snprintf(cmd, sizeof(cmd), "-i %s -f f32le -ac %d -ar %d %s", path("ours.aac").c_str(), channels,
           sr, path("ours.ff.f32").c_str());
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

  our_encoded("transients 44.1k 96k cbr", gen_transients(n, 44100, 2), 44100, 2, 96000, AAC_RC_CBR);
  our_encoded("harmonics 48k mono 64k", gen_harmonics(n, 48000, 1), 48000, 1, 64000, AAC_RC_CBR);
  our_encoded("noise 44.1k 256k abr", gen_noise(n, 2), 44100, 2, 256000, AAC_RC_ABR);
  our_encoded("tones 16k mono tvbr", gen_harmonics(16000 * 3, 16000, 1), 16000, 1, 48000,
              AAC_RC_TVBR);
  our_encoded("harmonics 44.1k 48k intensity", gen_harmonics(n, 44100, 2), 44100, 2, 48000,
              AAC_RC_CBR);
  our_encoded("noise 44.1k 64k pns", gen_noise(n, 2), 44100, 2, 64000, AAC_RC_CBR);
  remove_temp_dir(g_dir);
  return test_summary("test_interop");
}
