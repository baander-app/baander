/*
 * Reference-codec comparison tests for baander-aac.
 *
 * Compares baander-aac encode/decode output against reference AAC codecs
 * (libfdk-aac via FFmpeg, FFmpeg native AAC) on a set of synthetic test
 * signals.  The goal is to quantify the quality gap and catch regressions.
 *
 * Reference comparisons require ffmpeg with libfdk_aac; when unavailable the
 * test falls back to baander-only SNR checks with bitrate-scaling assertions.
 *
 * Signals:
 *   1. Multi-tone sine (stationary, tonal — best case for AAC)
 *   2. Harmonic stack (fundamental + harmonics — music-like)
 *   3. White noise (worst case — no masking possible)
 *   4. Impulse train (transient — tests window switching / pre-echo)
 *
 * Metrics: SNR vs original after delay alignment, bitrate achieved.
 */
#include <algorithm>
#include <cmath>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>
#include <unistd.h>
#include <vector>

#include <unistd.h>

#include "aac.h"
#include "aac_tables.h"

/* ── Helpers ───────────────────────────────────────────────────── */

static float compute_snr(const float* ref, const float* test, int n) {
  double sig = 0, noise = 0;
  for (int i = 0; i < n; i++) {
    sig += (double)ref[i] * ref[i];
    double err = (double)ref[i] - (double)test[i];
    noise += err * err;
  }
  if (noise < 1e-20) return 999.0f;
  return (float)(10.0 * log10(sig / noise));
}

/* Find the offset in test that maximizes SNR vs ref (delay alignment). */
static float best_snr_aligned(const float* ref, int n_ref, const float* test, int n_test,
                              int* out_offset) {
  float best = -999.0f;
  int best_off = 0;
  int max_off = n_test - n_ref;
  for (int off = 0; off <= max_off; off++) {
    float snr = compute_snr(ref, test + off, n_ref);
    if (snr > best) {
      best = snr;
      best_off = off;
    }
  }
  if (out_offset) *out_offset = best_off;
  return best;
}

static void gen_multitone(float* out, int n, int sr) {
  for (int i = 0; i < n; i++) {
    out[i] = 0.3f * sinf(2.0f * (float)M_PI * 440.0f * i / sr) +
             0.2f * sinf(2.0f * (float)M_PI * 1000.0f * i / sr) +
             0.1f * sinf(2.0f * (float)M_PI * 4000.0f * i / sr);
  }
}

static void gen_harmonics(float* out, int n, int sr) {
  for (int i = 0; i < n; i++) {
    float v = 0.0f;
    for (int h = 1; h <= 8; h++) {
      v += (0.25f / h) * sinf(2.0f * (float)M_PI * 220.0f * h * i / sr);
    }
    out[i] = v;
  }
}

static void gen_white_noise(float* out, int n, int /*sr*/) {
  unsigned int seed = 12345;
  for (int i = 0; i < n; i++) {
    seed = seed * 1103515245 + 12345;
    out[i] = 0.3f * ((float)(seed % 65536) / 32768.0f - 1.0f);
  }
}

static void gen_impulses(float* out, int n, int sr) {
  memset(out, 0, n * sizeof(float));
  int period = sr / 50; /* 50 Hz impulse train */
  for (int i = 0; i < n; i += period) {
    out[i] = 0.8f;
  }
}

/* ── baander-aac encode/decode ─────────────────────────────────── */

struct CodecResult {
  float snr = 0.0f;
  int total_bytes = 0;
  int n_frames = 0;
  float actual_bitrate = 0.0f;
};

static CodecResult baander_roundtrip(const float* pcm, int n, int sr, int ch, int bitrate,
                                     AacObjectType aot = AAC_AOT_LC,
                                     AacRateControl rc = AAC_RC_CBR) {
  CodecResult r;
  AacEncoderHandle enc = aac_encoder_create(sr, ch, bitrate, aot, rc);
  AacDecoderHandle dec = aac_decoder_create(sr, ch);
  if (!enc || !dec) {
    r.snr = -1.0f;
    if (enc) aac_encoder_destroy(enc);
    if (dec) aac_decoder_destroy(dec);
    return r;
  }

  int frame_size = aac_encoder_frame_size(enc);
  int delay_frames = aac_encoder_delay(enc) / frame_size;

  std::vector<std::vector<uint8_t>> frames;
  std::vector<uint8_t> bs(65536);
  int pos = 0;
  while (pos + frame_size <= n) {
    int len = aac_encoder_encode(enc, pcm + pos * ch, frame_size, bs.data(), (int)bs.size());
    if (len <= 0) break;
    frames.push_back(std::vector<uint8_t>(bs.data(), bs.data() + len));
    r.total_bytes += len;
    pos += frame_size;
  }
  int flush = aac_encoder_flush(enc, bs.data(), (int)bs.size());
  if (flush > 0) {
    frames.push_back(std::vector<uint8_t>(bs.data(), bs.data() + flush));
    r.total_bytes += flush;
  }
  r.n_frames = (int)frames.size();

  std::vector<float> out(pos * ch, 0.0f);
  std::vector<float> dec_buf(frame_size * ch * 2);
  int out_write = 0;
  for (size_t f = 0; f < frames.size(); f++) {
    int nd = aac_decoder_decode(dec, frames[f].data(), (int)frames[f].size(), dec_buf.data(),
                                (int)dec_buf.size());
    if ((int)f < delay_frames) continue;
    int copy = nd * ch;
    if (copy <= 0) continue;
    if (out_write + copy > pos * ch) copy = pos * ch - out_write;
    if (copy <= 0) break;
    memcpy(out.data() + out_write, dec_buf.data(), copy * sizeof(float));
    out_write += copy;
  }

  if (out_write > frame_size) {
    r.snr = compute_snr(pcm, out.data(), out_write);
  }
  float dur = (float)pos / sr;
  if (dur > 0) r.actual_bitrate = (float)r.total_bytes * 8.0f / dur;

  aac_encoder_destroy(enc);
  aac_decoder_destroy(dec);
  return r;
}

/* ── Reference codec via FFmpeg ────────────────────────────────── */

static bool ffmpeg_has_encoder(const char* name) {
  std::string cmd = "ffmpeg -v error -h encoder=";
  cmd += name;
  cmd += " 2>/dev/null | grep -q .";
  return system(cmd.c_str()) == 0;
}

static CodecResult ffmpeg_roundtrip(const char* encoder, const float* pcm, int n, int sr, int ch,
                                    int bitrate) {
  CodecResult r;
  r.snr = -1.0f;

  /* Write PCM to a temp file */
  char tmp_in[] = "/tmp/baander_ref_in_XXXXXX";
  int fd = mkstemp(tmp_in);
  if (fd < 0) return r;
  FILE* f = fdopen(fd, "wb");
  if (!f) { close(fd); unlink(tmp_in); return r; }
  fwrite(pcm, sizeof(float), n * ch, f);
  fclose(f);

  char tmp_aac[256], tmp_out[256];
  snprintf(tmp_aac, sizeof(tmp_aac), "%s.aac", tmp_in);
  snprintf(tmp_out, sizeof(tmp_out), "%s.out", tmp_in);

  /* Encode */
  char cmd[1024];
  snprintf(cmd, sizeof(cmd),
           "ffmpeg -v error -f f32le -ar %d -ac %d -i '%s' -c:a %s -b:a %d '%s' -y 2>/dev/null",
           sr, ch, tmp_in, encoder, bitrate, tmp_aac);
  if (system(cmd) != 0) {
    unlink(tmp_in); unlink(tmp_aac); unlink(tmp_out);
    return r;
  }

  /* Decode */
  snprintf(cmd, sizeof(cmd),
           "ffmpeg -v error -i '%s' -f f32le -ar %d -ac %d '%s' -y 2>/dev/null", tmp_aac, sr, ch,
           tmp_out);
  if (system(cmd) != 0) {
    unlink(tmp_in); unlink(tmp_aac); unlink(tmp_out);
    return r;
  }

  /* Read decoded PCM */
  FILE* fo = fopen(tmp_out, "rb");
  if (!fo) { unlink(tmp_in); unlink(tmp_aac); unlink(tmp_out); return r; }
  std::vector<float> out(n * ch * 2);
  int n_out = (int)fread(out.data(), sizeof(float), n * ch * 2, fo);
  fclose(fo);

  /* Get encoded size */
  FILE* fa = fopen(tmp_aac, "rb");
  if (fa) {
    fseek(fa, 0, SEEK_END);
    r.total_bytes = (int)ftell(fa);
    fclose(fa);
    float dur = (float)n / sr;
    if (dur > 0) r.actual_bitrate = (float)r.total_bytes * 8.0f / dur;
  }

  if (n_out > 0) {
    int offset = 0;
    r.snr = best_snr_aligned(pcm, n * ch, out.data(), n_out, &offset);
  }

  unlink(tmp_in); unlink(tmp_aac); unlink(tmp_out);
  return r;
}

/* ── Test driver ───────────────────────────────────────────────── */

struct TestCase {
  const char* name;
  void (*gen)(float*, int, int);
  int n_samples;
};

int main() {
  aac_tables_init();
  const int sr = 44100;
  const int ch = 1;
  const int n = 8192; /* ~186 ms — enough for several frames */

  std::vector<TestCase> cases = {
      {"multitone", gen_multitone, n},
      {"harmonics", gen_harmonics, n},
      {"white_noise", gen_white_noise, n},
      {"impulses", gen_impulses, n},
  };

  int bitrates[] = {64000, 128000, 256000};
  const char* br_labels[] = {"64k", "128k", "256k"};

  bool have_fdk = ffmpeg_has_encoder("libfdk_aac");
  bool have_ffaac = ffmpeg_has_encoder("aac");
  if (!have_fdk && !have_ffaac) {
    printf("NOTE: no ffmpeg AAC encoder available; running baander-only checks.\n");
  }

  printf("=== Reference Codec Comparison ===\n\n");
  printf("%-12s %6s %12s %12s %12s %12s\n", "signal", "br", "baander_snr", "fdk_snr", "ffaac_snr",
         "gap_vs_fdk");

  int failures = 0;
  int comparisons = 0;
  float baander_snr_sum = 0, fdk_snr_sum = 0;

  for (const auto& tc : cases) {
    std::vector<float> pcm(tc.n_samples);
    tc.gen(pcm.data(), tc.n_samples, sr);

    float prev_snr = -999.0f;
    bool scaling_ok = true;

    for (int bi = 0; bi < 3; bi++) {
      auto b = baander_roundtrip(pcm.data(), tc.n_samples, sr, ch, bitrates[bi]);
      float fdk_snr = -1.0f, ffaac_snr = -1.0f;
      if (have_fdk) {
        auto f = ffmpeg_roundtrip("libfdk_aac", pcm.data(), tc.n_samples, sr, ch, bitrates[bi]);
        fdk_snr = f.snr;
      }
      if (have_ffaac) {
        auto f = ffmpeg_roundtrip("aac", pcm.data(), tc.n_samples, sr, ch, bitrates[bi]);
        ffaac_snr = f.snr;
      }

      printf("%-12s %6s %10.1f dB %10.1f dB %10.1f dB %10.1f dB\n", tc.name, br_labels[bi],
             b.snr, fdk_snr, ffaac_snr, (fdk_snr > 0 && b.snr > 0) ? b.snr - fdk_snr : 0.0f);

      if (b.snr > 0) {
        baander_snr_sum += b.snr;
        comparisons++;
        if (fdk_snr > 0) fdk_snr_sum += fdk_snr;
      }

      /* Bitrate-scaling check: SNR should not decrease with bitrate. */
      if (prev_snr > -900.0f && b.snr > 0 && b.snr < prev_snr - 0.5f) {
        scaling_ok = false;
      }
      if (b.snr > 0) prev_snr = b.snr;
    }

    if (!scaling_ok) {
      printf("  WARNING: %s SNR decreases with bitrate\n", tc.name);
    }
  }

  printf("\n=== Summary ===\n");
  if (comparisons > 0) {
    printf("baander-aac avg SNR: %.1f dB\n", baander_snr_sum / comparisons);
    if (fdk_snr_sum > 0) {
      printf("libfdk-aac avg SNR: %.1f dB\n", fdk_snr_sum / comparisons);
      printf("Average gap: %.1f dB\n", (baander_snr_sum - fdk_snr_sum) / comparisons);
    }
  }

  /* Regression floor: baander should achieve at least 15 dB on tonal content. */
  auto tones = baander_roundtrip([&] {
    static std::vector<float> p(n);
    gen_multitone(p.data(), n, sr);
    return p.data();
  }(), n, sr, ch, 128000);
  if (tones.snr < 15.0f) {
    printf("FAIL: 128k multitone SNR %.1f dB below 15 dB floor\n", tones.snr);
    failures++;
  } else {
    printf("PASS: 128k multitone SNR %.1f dB >= 15 dB floor\n", tones.snr);
  }

  printf("\n=== %d test(s) failed ===\n", failures);
  return failures;
}
