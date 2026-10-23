/* Shared helpers for the baander-aac tests: signal generators, SNR, ADTS
 * stream handling and encode/decode drivers over the public C API. */
#ifndef BAANDER_AAC_TEST_UTIL_H
#define BAANDER_AAC_TEST_UTIL_H

#include <algorithm>
#include <cmath>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>
#include <vector>

#include "aac.h"

static int g_failures = 0;

#define CHECK(cond, ...)                          \
  do {                                            \
    if (!(cond)) {                                \
      printf("FAIL %s:%d: ", __FILE__, __LINE__); \
      printf(__VA_ARGS__);                        \
      printf("\n");                               \
      g_failures++;                               \
    }                                             \
  } while (0)

static int test_summary(const char* name) {
  printf("%s: %d failure(s)\n", name, g_failures);
  return g_failures ? 1 : 0;
}

/* ── signals (interleaved float, full scale 1.0) ────────────────── */

static std::vector<float> gen_tones(int n, int sr, int channels) {
  std::vector<float> pcm((size_t)n * channels);
  for (int i = 0; i < n; i++) {
    const float t = (float)i / (float)sr;
    for (int c = 0; c < channels; c++) {
      const float f0 = c ? 660.0f : 440.0f;
      pcm[(size_t)i * channels + c] = 0.3f * sinf(2.0f * (float)M_PI * f0 * t) +
                                      0.15f * sinf(2.0f * (float)M_PI * 3000.0f * t) +
                                      0.05f * sinf(2.0f * (float)M_PI * 9000.0f * t);
    }
  }
  return pcm;
}

static std::vector<float> gen_harmonics(int n, int sr, int channels) {
  std::vector<float> pcm((size_t)n * channels);
  for (int i = 0; i < n; i++) {
    const float t = (float)i / (float)sr;
    float v = 0.0f;
    for (int h = 1; h <= 12; h++) {
      v += (0.25f / (float)h) * sinf(2.0f * (float)M_PI * 220.0f * (float)h * t);
    }
    for (int c = 0; c < channels; c++) {
      pcm[(size_t)i * channels + c] = c ? 0.7f * v : v;
    }
  }
  return pcm;
}

static std::vector<float> gen_noise(int n, int channels, unsigned seed = 12345u) {
  std::vector<float> pcm((size_t)n * channels);
  for (size_t i = 0; i < pcm.size(); i++) {
    seed = seed * 1664525u + 1013904223u;
    pcm[i] = 0.3f * ((float)(seed >> 8) / 8388608.0f - 1.0f);
  }
  return pcm;
}

/* Decaying noise bursts every 180 ms: exercises block switching and TNS. */
static std::vector<float> gen_transients(int n, int sr, int channels) {
  std::vector<float> pcm((size_t)n * channels, 0.0f);
  unsigned seed = 777u;
  const int period = sr * 18 / 100;
  for (int start = sr / 50; start < n; start += period) {
    for (int i = 0; i < sr / 25 && start + i < n; i++) {
      seed = seed * 1664525u + 1013904223u;
      const float v =
          0.6f * ((float)(seed >> 8) / 8388608.0f - 1.0f) * expf(-(float)i / ((float)sr * 0.006f));
      for (int c = 0; c < channels; c++) {
        pcm[(size_t)(start + i) * channels + c] = c ? 0.8f * v : v;
      }
    }
  }
  return pcm;
}

/* A distinct tone and noise bed per channel, so that a channel landing
 * in the wrong output slot is heard as a large error. */
static std::vector<float> gen_multichannel(int n, int sr, int channels) {
  std::vector<float> pcm((size_t)n * channels);
  unsigned seed = 4242u;
  for (int i = 0; i < n; i++) {
    const float t = (float)i / (float)sr;
    for (int c = 0; c < channels; c++) {
      seed = seed * 1664525u + 1013904223u;
      const float noise = 0.02f * ((float)(seed >> 8) / 8388608.0f - 1.0f);
      /* Channel 3 is the LFE of the 5.1 and 7.1 layouts: a low tone. */
      const float f0 = c == 3 ? 60.0f : 220.0f * (float)(c + 1);
      pcm[(size_t)i * channels + c] = 0.25f * sinf(2.0f * (float)M_PI * f0 * t) +
                                      0.1f * sinf(2.0f * (float)M_PI * (f0 * 2.5f + 50.0f) * t) +
                                      noise;
    }
  }
  return pcm;
}


/* The signal behind the committed HE-AAC streams in tests/data: one second
 * at 48 kHz of tones, a slowly swelling noise bed with energy up to the
 * top of the band, and a few clicks that make the SBR encoder switch
 * between its frame classes.  Keep it stable: the streams were encoded
 * from exactly this signal. */
static std::vector<float> gen_he_signal(int channels) {
  const int sr = 48000;
  const int n = sr;
  std::vector<float> pcm((size_t)n * channels);
  unsigned seed = 99u;
  for (int i = 0; i < n; i++) {
    const float t = (float)i / (float)sr;
    const float swell = sinf(2.0f * (float)M_PI * 0.7f * t);
    for (int c = 0; c < channels; c++) {
      seed = seed * 1664525u + 1013904223u;
      const float noise = 0.05f * ((float)(seed >> 8) / 8388608.0f - 1.0f) * swell * swell;
      const float f0 = c == 0 ? 220.0f : 330.0f;
      float v = 0.3f * sinf(2.0f * (float)M_PI * f0 * t) +
                0.15f * sinf(2.0f * (float)M_PI * 3.0f * f0 * t + (float)c) +
                0.08f * sinf(2.0f * (float)M_PI * (2500.0f + 800.0f * (float)c) * t) + noise;
      pcm[(size_t)i * channels + c] = v;
    }
  }
  const float clicks[3] = {0.21f, 0.52f, 0.83f};
  for (float at : clicks) {
    const int start = (int)(at * (float)sr);
    for (int i = 0; i < 40; i++) {
      const float w = 0.5f * (1.0f - cosf(2.0f * (float)M_PI * (float)i / 39.0f));
      const float v = 0.6f * w * (sinf((float)i) >= 0.0f ? 1.0f : -1.0f);
      for (int c = 0; c < channels; c++) {
        pcm[(size_t)(start + i) * channels + c] += c ? -v : v;
      }
    }
  }
  for (float& v : pcm) {
    v = std::max(-0.95f, std::min(0.95f, v));
  }
  return pcm;
}

/* ── metrics ───────────────────────────────────────────────────── */

static double snr_db(const float* ref, const float* test, size_t n) {
  double sig = 0.0;
  double err = 0.0;
  for (size_t i = 0; i < n; i++) {
    sig += (double)ref[i] * ref[i];
    const double d = (double)ref[i] - (double)test[i];
    err += d * d;
  }
  if (err <= 0.0) {
    return 200.0;
  }
  return 10.0 * log10(sig / err);
}

/* SNR of a decoded stream against its source after removing `delay`
 * samples of lead-in. */
static double aligned_snr_db(const std::vector<float>& ref, const std::vector<float>& out,
                             int channels, int delay) {
  const size_t skip = (size_t)delay * channels;
  if (out.size() <= skip) {
    return -100.0;
  }
  const size_t n = std::min(ref.size(), out.size() - skip);
  return snr_db(ref.data(), out.data() + skip, n);
}

/* ── filters ────────────────────────────────────────────────────── */

/* Windowed-sinc lowpass of one channel of an interleaved signal. */
static std::vector<float> lowpass_channel(const std::vector<float>& pcm, int channels, int channel,
                                          float cutoff, int sr) {
  const int taps = 255;
  std::vector<float> h(taps);
  double sum = 0.0;
  for (int i = 0; i < taps; i++) {
    const double k = i - taps / 2;
    const double x = 2.0 * cutoff / sr * k;
    const double sinc = k == 0 ? 1.0 : sin(M_PI * x) / (M_PI * x);
    h[i] = (float)(sinc * (0.5 - 0.5 * cos(2.0 * M_PI * i / (taps - 1))));
    sum += h[i];
  }
  for (float& v : h) {
    v = (float)(v / sum);
  }
  const size_t n = pcm.size() / channels;
  std::vector<float> out(n, 0.0f);
  for (size_t i = 0; i < n; i++) {
    float acc = 0.0f;
    for (int j = 0; j < taps; j++) {
      const long k = (long)i + j - taps / 2;
      if (k >= 0 && (size_t)k < n) {
        acc += h[j] * pcm[(size_t)k * channels + channel];
      }
    }
    out[i] = acc;
  }
  return out;
}

/* Best SNR of `out` against `ref` over a range of lead-in delays. */
static double best_aligned_snr_db(const std::vector<float>& ref, const std::vector<float>& out,
                                  int min_delay, int max_delay, int* delay) {
  double best = -100.0;
  for (int d = min_delay; d <= max_delay; d++) {
    if (out.size() <= (size_t)d + 1024) {
      break;
    }
    const size_t n = std::min(ref.size(), out.size() - d) - 512;
    const double v = snr_db(ref.data(), out.data() + d, n);
    if (v > best) {
      best = v;
      if (delay) {
        *delay = d;
      }
    }
  }
  return best;
}

static double energy_db(const float* x, size_t n) {
  double e = 0.0;
  for (size_t i = 0; i < n; i++) {
    e += (double)x[i] * x[i];
  }
  return 10.0 * log10(e + 1e-20);
}

/* Committed reference streams. */
static std::string data_path(const char* name) {
  return std::string(BAAC_TEST_DATA_DIR) + "/" + name;
}

/* ── ADTS streams ──────────────────────────────────────────────── */

struct AdtsFrame {
  size_t offset, size;
};

static std::vector<AdtsFrame> split_adts(const std::vector<uint8_t>& s) {
  std::vector<AdtsFrame> frames;
  size_t pos = 0;
  while (pos + 7 <= s.size()) {
    if (s[pos] != 0xFF || (s[pos + 1] & 0xF6) != 0xF0) {
      break;
    }
    const size_t len = ((s[pos + 3] & 3) << 11) | (s[pos + 4] << 3) | (s[pos + 5] >> 5);
    if (len < 7 || pos + len > s.size()) {
      break;
    }
    frames.push_back({pos, len});
    pos += len;
  }
  return frames;
}

/* Encodes a whole signal (including the flush) into one ADTS stream. */
static std::vector<uint8_t> encode_stream(const std::vector<float>& pcm, int sr, int channels,
                                          int bitrate, AacRateControl rc, int quality = 0,
                                          int* error = nullptr) {
  std::vector<uint8_t> out;
  AacEncoderHandle enc = aac_encoder_create(sr, channels, bitrate, AAC_AOT_LC, rc);
  if (!enc) {
    if (error) {
      *error = AAC_ERR_INIT;
    }
    return out;
  }
  if (quality) {
    aac_encoder_set_quality(enc, quality);
  }
  const int frame = aac_encoder_frame_size(enc);
  std::vector<float> block((size_t)frame * channels);
  std::vector<uint8_t> buf(8192);
  for (size_t pos = 0; pos < pcm.size(); pos += block.size()) {
    std::fill(block.begin(), block.end(), 0.0f);
    const size_t n = std::min(block.size(), pcm.size() - pos);
    memcpy(block.data(), pcm.data() + pos, n * sizeof(float));
    const int len = aac_encoder_encode(enc, block.data(), frame, buf.data(), (int)buf.size());
    if (len < 0) {
      if (error) {
        *error = len;
      }
      break;
    }
    out.insert(out.end(), buf.begin(), buf.begin() + len);
  }
  const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
  if (len > 0) {
    out.insert(out.end(), buf.begin(), buf.begin() + len);
  } else if (len < 0 && error) {
    *error = len;
  }
  aac_encoder_destroy(enc);
  return out;
}

/* Decodes an ADTS stream; counts frames that failed in *errors. */
static std::vector<float> decode_stream(const std::vector<uint8_t>& stream, int sr, int channels,
                                        int* errors = nullptr,
                                        AacDecoderHandle existing = nullptr) {
  std::vector<float> out;
  AacDecoderHandle dec = existing ? existing : aac_decoder_create(sr, channels);
  if (!dec) {
    if (errors) {
      *errors = -1;
    }
    return out;
  }
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  int bad = 0;
  for (const AdtsFrame& f : split_adts(stream)) {
    const int n =
        aac_decoder_decode(dec, stream.data() + f.offset, (int)f.size, pcm.data(), (int)pcm.size());
    if (n < 0) {
      bad++;
      continue;
    }
    out.insert(out.end(), pcm.begin(), pcm.begin() + (size_t)n * aac_decoder_channels(dec));
  }
  if (errors) {
    *errors = bad;
  }
  if (!existing) {
    aac_decoder_destroy(dec);
  }
  return out;
}

/* ── files and ffmpeg ──────────────────────────────────────────── */

static bool write_file(const std::string& path, const void* data, size_t size) {
  FILE* f = fopen(path.c_str(), "wb");
  if (!f) {
    return false;
  }
  const bool ok = fwrite(data, 1, size, f) == size;
  fclose(f);
  return ok;
}

static std::vector<uint8_t> read_file(const std::string& path) {
  std::vector<uint8_t> data;
  FILE* f = fopen(path.c_str(), "rb");
  if (!f) {
    return data;
  }
  uint8_t buf[65536];
  size_t n;
  while ((n = fread(buf, 1, sizeof(buf), f)) > 0) {
    data.insert(data.end(), buf, buf + n);
  }
  fclose(f);
  return data;
}

static std::vector<float> read_f32(const std::string& path) {
  const std::vector<uint8_t> raw = read_file(path);
  std::vector<float> out(raw.size() / sizeof(float));
  memcpy(out.data(), raw.data(), out.size() * sizeof(float));
  return out;
}

static bool have_ffmpeg() { return system("ffmpeg -version > /dev/null 2>&1") == 0; }

/* Temporary directory for one test run, removed by the caller. */
static std::string make_temp_dir() {
  char tmpl[] = "/tmp/baander-aac-test-XXXXXX";
  const char* dir = mkdtemp(tmpl);
  return dir ? std::string(dir) : std::string();
}

static void remove_temp_dir(const std::string& dir) {
  if (!dir.empty()) {
    const std::string cmd = "rm -rf '" + dir + "'";
    (void)system(cmd.c_str());
  }
}

static bool run_command(const std::string& cmd) {
  return system((cmd + " > /dev/null 2>&1").c_str()) == 0;
}

#endif /* BAANDER_AAC_TEST_UTIL_H */
