/* baac: command-line encoder/decoder for baander-aac.
 *
 *   baac enc <in.f32le> <out.aac> <rate> <channels> [bitrate] [mode] [quality]
 *   baac dec <in.aac> <out.f32le> [rate] [channels]
 *   baac bench [--seconds N] [--dump DIR] [--only NAME]
 *
 * Raw PCM is interleaved 32-bit float; the AAC side is an ADTS stream.
 * mode: cbr (default), abr, cvbr, tvbr.  The decoder follows the ADTS
 * headers, so rate and channels only set the output layout (0 keeps the
 * stream's channels).  bench encodes and decodes a synthetic stereo
 * signal at 48 kHz through every profile and prints the times; --dump
 * writes each stream and its decode to DIR for comparisons between
 * builds. */
#include <algorithm>
#include <chrono>
#include <cmath>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>
#include <vector>

#include "aac.h"

static std::vector<uint8_t> read_all(const char* path) {
  std::vector<uint8_t> data;
  FILE* f = strcmp(path, "-") == 0 ? stdin : fopen(path, "rb");
  if (!f) {
    fprintf(stderr, "baac: cannot open %s\n", path);
    exit(1);
  }
  uint8_t buf[65536];
  size_t n;
  while ((n = fread(buf, 1, sizeof(buf), f)) > 0) {
    data.insert(data.end(), buf, buf + n);
  }
  if (f != stdin) {
    fclose(f);
  }
  return data;
}

static FILE* open_out(const char* path) {
  FILE* f = strcmp(path, "-") == 0 ? stdout : fopen(path, "wb");
  if (!f) {
    fprintf(stderr, "baac: cannot create %s\n", path);
    exit(1);
  }
  return f;
}

static int usage() {
  fprintf(stderr,
          "usage: baac enc <in.f32le> <out.aac> <rate> <channels> [bitrate] [cbr|abr|cvbr|tvbr] "
          "[quality] [lc|he|hev2]\n"
          "       baac dec <in.aac> <out.f32le> [rate] [channels: 0 = as in the stream]\n"
          "       baac bench [--seconds N] [--dump DIR] [--only NAME]\n");
  return 2;
}

static int encode(int argc, char** argv) {
  if (argc < 6) {
    return usage();
  }
  const int sr = atoi(argv[4]);
  const int channels = atoi(argv[5]);
  const int bitrate = argc > 6 ? atoi(argv[6]) : 128000;
  AacRateControl rc = AAC_RC_CBR;
  if (argc > 7) {
    const std::string mode = argv[7];
    rc = mode == "abr"    ? AAC_RC_ABR
         : mode == "cvbr" ? AAC_RC_CVBR
         : mode == "tvbr" ? AAC_RC_TVBR
                          : AAC_RC_CBR;
  }
  AacObjectType aot = AAC_AOT_LC;
  if (argc > 9) {
    const std::string profile = argv[9];
    aot = profile == "he" ? AAC_AOT_SBR : profile == "hev2" ? AAC_AOT_PS : AAC_AOT_LC;
  }
  AacEncoderHandle enc = aac_encoder_create(sr, channels, bitrate, aot, rc);
  if (!enc) {
    fprintf(stderr, "baac: unsupported encoder configuration\n");
    return 1;
  }
  if (argc > 8 && atoi(argv[8]) > 0) {
    aac_encoder_set_quality(enc, atoi(argv[8]));
  }
  const std::vector<uint8_t> in = read_all(argv[2]);
  FILE* out = open_out(argv[3]);
  const int frame = aac_encoder_frame_size(enc);
  const size_t block = (size_t)frame * channels;
  const size_t total = in.size() / sizeof(float);
  std::vector<float> pcm(block);
  std::vector<uint8_t> buf(16384);
  long bytes = 0;
  int frames = 0;
  for (size_t pos = 0; pos < total; pos += block) {
    std::fill(pcm.begin(), pcm.end(), 0.0f);
    const size_t n = std::min(block, total - pos);
    memcpy(pcm.data(), in.data() + pos * sizeof(float), n * sizeof(float));
    const int len = aac_encoder_encode(enc, pcm.data(), frame, buf.data(), (int)buf.size());
    if (len < 0) {
      fprintf(stderr, "baac: encode error %d at frame %d\n", len, frames);
      return 1;
    }
    fwrite(buf.data(), 1, (size_t)len, out);
    bytes += len;
    frames++;
  }
  const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
  if (len > 0) {
    fwrite(buf.data(), 1, (size_t)len, out);
    bytes += len;
  }
  if (out != stdout) {
    fclose(out);
  }
  aac_encoder_destroy(enc);
  const double seconds = (double)total / channels / sr;
  fprintf(stderr, "baac: %d frames, %ld bytes, %.1f kbit/s\n", frames, bytes,
          seconds > 0 ? bytes * 8.0 / seconds / 1000.0 : 0.0);
  return 0;
}

static int decode(int argc, char** argv) {
  if (argc < 4) {
    return usage();
  }
  const int sr = argc > 4 ? atoi(argv[4]) : 44100;
  const int channels = argc > 5 ? atoi(argv[5]) : 2;
  AacDecoderHandle dec = aac_decoder_create(sr, channels);
  if (!dec) {
    fprintf(stderr, "baac: unsupported decoder configuration\n");
    return 1;
  }
  const std::vector<uint8_t> in = read_all(argv[2]);
  FILE* out = open_out(argv[3]);
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  /* The streaming path takes ADTS, LOAS/LATM and raw access units
   * alike, resynchronising across damaged bytes. */
  size_t pos = 0;
  int frames = 0;
  int errors = 0;
  for (;;) {
    if (pos < in.size()) {
      const int taken = aac_decoder_feed(dec, in.data() + pos, (int)std::min<size_t>(in.size() - pos, 65536));
      if (taken < 0) {
        errors++;
        break;
      }
      pos += (size_t)taken;
    }
    const int n = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
    if (n == 0) {
      if (pos >= in.size()) {
        break;
      }
      continue;
    }
    if (n < 0) {
      errors++;
      if (n == AAC_ERR_UNSUPPORTED) {
        break;
      }
      continue;
    }
    fwrite(pcm.data(), sizeof(float), (size_t)n * aac_decoder_channels(dec), out);
    frames++;
  }
  if (out != stdout) {
    fclose(out);
  }
  fprintf(stderr, "baac: %d frames, %d errors, %d Hz, %d channels (stream %d)\n", frames, errors,
          aac_decoder_output_sample_rate(dec), aac_decoder_channels(dec),
          aac_decoder_stream_channels(dec));
  aac_decoder_destroy(dec);
  return errors ? 1 : 0;
}

/* ── bench ─────────────────────────────────────────────────────── */

/* One benchmark configuration: an encoder setup and its decode. */
struct BenchConfig {
  const char* name;
  AacObjectType aot;
  int bitrate;
  int frame_length; /* 0 keeps the object type's default */
  int afterburner;
};

static const BenchConfig kBenchConfigs[] = {
    {"LC 128k", AAC_AOT_LC, 128000, 0, 0},
    {"HE-AAC v1 64k", AAC_AOT_SBR, 64000, 0, 0},
    {"HE-AAC v2 32k", AAC_AOT_PS, 32000, 0, 0},
    {"LC 960 128k", AAC_AOT_LC, 128000, 960, 0},
    {"LD 512 64k", AAC_AOT_ER_LD, 64000, 512, 0},
    {"ELD 512 64k", AAC_AOT_ER_ELD, 64000, 512, 0},
    {"LC 48k afterburner", AAC_AOT_LC, 48000, 0, 1},
};

/* A deterministic stereo test signal: harmonics of a slowly sweeping
 * fundamental over a noise bed, with a click every 300 ms so that the
 * block switching and the transient tools run, and the right channel
 * quieter and offset in phase. */
static std::vector<float> bench_signal(int n, int sr) {
  std::vector<float> pcm((size_t)n * 2);
  const int harmonics = 24;
  float phase[harmonics] = {0};
  unsigned seed = 12345u;
  const int click_period = sr * 3 / 10;
  for (int i = 0; i < n; i++) {
    const float f0 = 110.0f + 30.0f * sinf(2.0f * (float)M_PI * (float)i / ((float)sr * 7.0f));
    float left = 0.0f;
    float right = 0.0f;
    for (int h = 0; h < harmonics; h++) {
      phase[h] += 2.0f * (float)M_PI * f0 * (float)(h + 1) / (float)sr;
      if (phase[h] > 2.0f * (float)M_PI) {
        phase[h] -= 2.0f * (float)M_PI;
      }
      const float amp = 0.18f / (float)(h + 1);
      left += amp * sinf(phase[h]);
      right += amp * sinf(phase[h] + 0.4f * (float)(h + 1));
    }
    seed = seed * 1664525u + 1013904223u;
    const float noise = 0.02f * ((float)(seed >> 8) / 8388608.0f - 1.0f);
    const int in_period = i % click_period;
    const float click = in_period < 48 ? 0.5f * (1.0f - (float)in_period / 48.0f) : 0.0f;
    pcm[(size_t)i * 2] = left + noise + click;
    pcm[(size_t)i * 2 + 1] = 0.7f * right + noise - click;
  }
  return pcm;
}

static double now_seconds() {
  using clock = std::chrono::steady_clock;
  return std::chrono::duration<double>(clock::now().time_since_epoch()).count();
}

static void write_file(const std::string& path, const void* data, size_t bytes) {
  FILE* f = fopen(path.c_str(), "wb");
  if (!f) {
    fprintf(stderr, "baac: cannot create %s\n", path.c_str());
    exit(1);
  }
  fwrite(data, 1, bytes, f);
  fclose(f);
}

/* Encodes the signal with one configuration into `stream` and reports
 * the wall time; returns false when the configuration is refused. */
static bool bench_encode(const BenchConfig& cfg, const std::vector<float>& pcm, int sr,
                         std::vector<uint8_t>& stream, double* seconds) {
  AacEncoderHandle enc = aac_encoder_create(sr, 2, cfg.bitrate, cfg.aot, AAC_RC_CBR);
  if (!enc) {
    return false;
  }
  if (cfg.frame_length && aac_encoder_set_frame_length(enc, cfg.frame_length) != AAC_OK) {
    aac_encoder_destroy(enc);
    return false;
  }
  if (cfg.afterburner) {
    aac_encoder_set_afterburner(enc, 1);
  }
  const int frame = aac_encoder_frame_size(enc);
  const size_t block = (size_t)frame * 2;
  const size_t total = pcm.size();
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) + 64);
  std::vector<float> tail(block, 0.0f);
  stream.clear();
  stream.reserve(total / 8);
  const double t0 = now_seconds();
  for (size_t pos = 0; pos < total; pos += block) {
    const float* in = pcm.data() + pos;
    if (total - pos < block) {
      memcpy(tail.data(), in, (total - pos) * sizeof(float));
      in = tail.data();
    }
    const int len = aac_encoder_encode(enc, in, frame, buf.data(), (int)buf.size());
    if (len < 0) {
      fprintf(stderr, "baac: %s: encode error %d\n", cfg.name, len);
      aac_encoder_destroy(enc);
      return false;
    }
    stream.insert(stream.end(), buf.begin(), buf.begin() + len);
  }
  for (;;) {
    const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
    if (len <= 0) {
      break;
    }
    stream.insert(stream.end(), buf.begin(), buf.begin() + len);
  }
  *seconds = now_seconds() - t0;
  aac_encoder_destroy(enc);
  return true;
}

/* Decodes a stream through the FIFO interface into `out` and reports
 * the wall time and the output rate. */
static bool bench_decode(const BenchConfig& cfg, const std::vector<uint8_t>& stream,
                         std::vector<float>& out, double* seconds, int* out_rate) {
  AacDecoderHandle dec = aac_decoder_create(48000, 2);
  if (!dec) {
    return false;
  }
  if (cfg.frame_length == 960) {
    aac_decoder_set_frame_length(dec, 960);
  }
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 2);
  out.clear();
  out.reserve(stream.size() * 64);
  int errors = 0;
  const double t0 = now_seconds();
  for (size_t pos = 0; pos < stream.size();) {
    const int taken = aac_decoder_feed(dec, stream.data() + pos,
                                       (int)std::min((size_t)4096, stream.size() - pos));
    if (taken <= 0) {
      errors++;
      break;
    }
    pos += (size_t)taken;
    for (;;) {
      const int got = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
      if (got == 0) {
        break;
      }
      if (got < 0) {
        errors++;
        continue;
      }
      out.insert(out.end(), pcm.begin(), pcm.begin() + (size_t)got * 2);
    }
  }
  *seconds = now_seconds() - t0;
  *out_rate = aac_decoder_output_sample_rate(dec);
  aac_decoder_destroy(dec);
  if (errors) {
    fprintf(stderr, "baac: %s: %d decode errors\n", cfg.name, errors);
  }
  return errors == 0;
}

static int bench(int argc, char** argv) {
  int seconds = 60;
  std::string dump;
  std::string only;
  for (int i = 2; i < argc; i++) {
    if (strcmp(argv[i], "--seconds") == 0 && i + 1 < argc) {
      seconds = atoi(argv[++i]);
    } else if (strcmp(argv[i], "--dump") == 0 && i + 1 < argc) {
      dump = argv[++i];
    } else if (strcmp(argv[i], "--only") == 0 && i + 1 < argc) {
      only = argv[++i];
    } else {
      return usage();
    }
  }
  if (seconds <= 0) {
    return usage();
  }
  const int sr = 48000;
  const std::vector<float> pcm = bench_signal(sr * seconds, sr);
  printf("baac bench: %d s stereo at %d Hz\n\n", seconds, sr);
  printf("%-22s %8s %9s %9s %9s %9s\n", "configuration", "kbit/s", "enc s", "enc x", "dec s",
         "dec x");
  int failures = 0;
  for (const BenchConfig& cfg : kBenchConfigs) {
    if (!only.empty() && only != cfg.name) {
      continue;
    }
    std::vector<uint8_t> stream;
    double enc_s = 0.0;
    if (!bench_encode(cfg, pcm, sr, stream, &enc_s)) {
      printf("%-22s unsupported\n", cfg.name);
      failures++;
      continue;
    }
    std::vector<float> out;
    double dec_s = 0.0;
    int out_rate = sr;
    const bool ok = bench_decode(cfg, stream, out, &dec_s, &out_rate);
    const double decoded = (double)out.size() / 2.0 / (double)out_rate;
    printf("%-22s %8.1f %9.3f %8.1fx %9.3f %8.1fx\n", cfg.name,
           (double)stream.size() * 8.0 / (double)seconds / 1000.0, enc_s, (double)seconds / enc_s,
           dec_s, decoded / dec_s);
    if (!ok) {
      failures++;
    }
    if (!dump.empty()) {
      std::string base = dump + "/" + cfg.name;
      std::replace(base.begin(), base.end(), ' ', '_');
      write_file(base + ".aac", stream.data(), stream.size());
      write_file(base + ".f32", out.data(), out.size() * sizeof(float));
    }
  }
  return failures ? 1 : 0;
}

int main(int argc, char** argv) {
  if (argc < 2) {
    return usage();
  }
  if (strcmp(argv[1], "enc") == 0) {
    return encode(argc, argv);
  }
  if (strcmp(argv[1], "dec") == 0) {
    return decode(argc, argv);
  }
  if (strcmp(argv[1], "bench") == 0) {
    return bench(argc, argv);
  }
  return usage();
}
