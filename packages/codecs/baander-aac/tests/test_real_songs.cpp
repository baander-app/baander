/*
 * Real-song quality benchmark for baander-aac.
 *
 * Reads FLAC/WAV/MP3 files via FFmpeg (piped to raw PCM),
 * encodes with baander-aac at multiple bitrates/modes,
 * decodes, measures SNR, spectral distortion, and throughput.
 *
 * Only decodes 65 seconds from each file (60s measurement + MDCT delay margin)
 * and processes one song at a time to keep RAM usage ~22 MB.
 *
 * Usage: ./test_real_songs <file1.flac> [file2.flac] ...
 */
#include <algorithm>
#include <chrono>
#include <cmath>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>
#include <vector>

#include "aac.h"
#include "aac_cpu.h"
#include "aac_tables.h"

/* ── Audio buffer ──────────────────────────────────────────────── */

struct AudioBuffer {
  std::vector<float> pcm; /* interleaved float */
  int sample_rate = 0;
  int channels = 0;
  int frames = 0; /* samples per channel */
  std::string name;
};

/* ── FFmpeg decode to float PCM (first 65 seconds only) ────────── */

static AudioBuffer load_audio(const char* path, int target_sr = 44100, int target_ch = 2) {
  AudioBuffer buf;

  /* Extract filename from path */
  const char* slash = strrchr(path, '/');
  buf.name = slash ? slash + 1 : path;

  char cmd[1024];
  snprintf(cmd, sizeof(cmd),
           "ffmpeg -v error -t 65 -i '%s' -ar %d -ac %d -f f32le -acodec pcm_f32le -", path,
           target_sr, target_ch);

  FILE* pipe = popen(cmd, "r");
  if (!pipe) {
    fprintf(stderr, "  ERROR: cannot run ffmpeg for %s\n", path);
    return buf;
  }

  buf.sample_rate = target_sr;
  buf.channels = target_ch;

  float tmp[4096];
  while (true) {
    size_t n = fread(tmp, sizeof(float), 4096, pipe);
    if (n == 0) break;
    buf.pcm.insert(buf.pcm.end(), tmp, tmp + n);
  }
  pclose(pipe);

  buf.frames = (int)(buf.pcm.size() / buf.channels);
  return buf;
}

/* ── Metrics ───────────────────────────────────────────────────── */

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

/* Per-segment SNR to measure consistency */
static float compute_segment_snr_min(const float* ref, const float* test, int n, int seg_samples) {
  float min_snr = 999.0f;
  int n_segs = n / seg_samples;
  for (int s = 0; s < n_segs; s++) {
    int off = s * seg_samples;
    float snr = compute_snr(ref + off, test + off, seg_samples);
    if (snr < min_snr) min_snr = snr;
  }
  return min_snr;
}

/* Spectral distortion (average per-frame log-energy difference) */
static float compute_spectral_distortion(const float* ref, const float* test, int n,
                                         int fft_size = 1024) {
  float total_sd = 0;
  int n_frames = n / fft_size;
  if (n_frames < 1) return 0;

  for (int f = 0; f < n_frames; f++) {
    int off = f * fft_size;
    double ref_energy = 0, test_energy = 0;
    for (int i = 0; i < fft_size; i++) {
      ref_energy += (double)ref[off + i] * ref[off + i];
      test_energy += (double)test[off + i] * test[off + i];
    }
    if (ref_energy > 1e-20 && test_energy > 1e-20) {
      double ratio = test_energy / ref_energy;
      if (ratio > 0) total_sd += (float)fabs(10.0 * log10(ratio));
    }
  }
  return total_sd / n_frames;
}

/* ── Encode/decode a full song ─────────────────────────────────── */

struct BenchmarkResult {
  int bitrate;
  const char* rc_label;
  AacObjectType aot;
  float snr;
  float snr_min_segment;
  float spectral_distortion;
  double encode_ms;
  double decode_ms;
  int n_frames;
  int total_bytes;
  float actual_bitrate;
};

static BenchmarkResult benchmark_song(const AudioBuffer& buf, int bitrate, AacObjectType aot,
                                      AacRateControl rc) {
  BenchmarkResult r = {};
  r.bitrate = bitrate;
  r.aot = aot;

  switch (rc) {
    case AAC_RC_TVBR:
      r.rc_label = "TVBR";
      break;
    case AAC_RC_CVBR:
      r.rc_label = "CVBR";
      break;
    case AAC_RC_ABR:
      r.rc_label = "ABR";
      break;
    case AAC_RC_CBR:
      r.rc_label = "CBR";
      break;
  }

  if (buf.frames < 4096) {
    r.snr = -1;
    return r;
  }

  AacEncoderHandle enc = aac_encoder_create(buf.sample_rate, buf.channels, bitrate, aot, rc);
  AacDecoderHandle dec = aac_decoder_create(buf.sample_rate, buf.channels);

  if (!enc || !dec) {
    r.snr = -1;
    if (enc) aac_encoder_destroy(enc);
    if (dec) aac_decoder_destroy(dec);
    return r;
  }

  int frame_size = aac_encoder_frame_size(enc);

  int measure_samples = std::min(buf.frames, buf.sample_rate * 60);
  int measure_floats = measure_samples * buf.channels;

  std::vector<float> pcm_out(measure_floats + 8192, 0.0f);
  std::vector<uint8_t> bitstream(65536);

  int out_write = 0;
  int delay_frames = 2;

  /* ── Encode ── */
  auto t_enc_start = std::chrono::high_resolution_clock::now();

  struct EncodedFrame {
    std::vector<uint8_t> data;
  };
  std::vector<EncodedFrame> frames;

  int pos = 0;
  while (pos + frame_size <= buf.frames) {
    int len = aac_encoder_encode(enc, buf.pcm.data() + pos * buf.channels, frame_size,
                                 bitstream.data(), (int)bitstream.size());
    if (len > 0) {
      frames.push_back({std::vector<uint8_t>(bitstream.data(), bitstream.data() + len)});
      r.total_bytes += len;
    }
    pos += frame_size;
    if (pos >= measure_samples + delay_frames * frame_size) break;
  }

  int flush_len = aac_encoder_flush(enc, bitstream.data(), (int)bitstream.size());
  if (flush_len > 0) {
    frames.push_back({std::vector<uint8_t>(bitstream.data(), bitstream.data() + flush_len)});
    r.total_bytes += flush_len;
  }

  auto t_enc_end = std::chrono::high_resolution_clock::now();
  r.encode_ms = std::chrono::duration<double, std::milli>(t_enc_end - t_enc_start).count();
  r.n_frames = (int)frames.size();

  /* ── Decode ── */
  auto t_dec_start = std::chrono::high_resolution_clock::now();

  std::vector<float> dec_pcm(frame_size * buf.channels * 2);

  for (size_t f = 0; f < frames.size(); f++) {
    int n = aac_decoder_decode(dec, frames[f].data.data(), (int)frames[f].data.size(),
                               dec_pcm.data(), (int)dec_pcm.size());

    if ((int)f < delay_frames) continue;

    int copy_n = n * buf.channels;
    if (copy_n <= 0) continue;

    if (out_write + copy_n > measure_floats) {
      copy_n = measure_floats - out_write;
    }
    if (copy_n <= 0) break;

    memcpy(pcm_out.data() + out_write, dec_pcm.data(), copy_n * sizeof(float));
    out_write += copy_n;
  }

  auto t_dec_end = std::chrono::high_resolution_clock::now();
  r.decode_ms = std::chrono::duration<double, std::milli>(t_dec_end - t_dec_start).count();

  /* ── Compute metrics ── */
  int compare_n = std::min(out_write, measure_floats);
  if (compare_n > frame_size) {
    r.snr = compute_snr(buf.pcm.data(), pcm_out.data(), compare_n);
    r.snr_min_segment =
        compute_segment_snr_min(buf.pcm.data(), pcm_out.data(), compare_n, frame_size);
    r.spectral_distortion = compute_spectral_distortion(buf.pcm.data(), pcm_out.data(), compare_n);
  }

  float duration_s = (float)pos / buf.sample_rate;
  if (duration_s > 0) {
    r.actual_bitrate = (float)r.total_bytes * 8.0f / duration_s;
  }

  aac_encoder_destroy(enc);
  aac_decoder_destroy(dec);
  return r;
}

/* ── Main ──────────────────────────────────────────────────────── */

int main(int argc, char** argv) {
  if (argc < 2) {
    fprintf(stderr, "Usage: %s <file1.flac> [file2.flac] ...\n", argv[0]);
    return 1;
  }

  aac_tables_init();

  printf("╔══════════════════════════════════════════════════════════════════╗\n");
  printf("║          baander-aac Real-Song Quality Benchmark               ║\n");
  printf("╚══════════════════════════════════════════════════════════════════╝\n\n");

  int flags = aac_get_cpu_flags();
  printf("CPU flags: %s\n", aac_cpu_flags_string(flags));
  printf("Measuring first 60s of each track.\n\n");

  /* Benchmark configs */
  struct Config {
    int bitrate;
    AacObjectType aot;
    AacRateControl rc;
  };

  std::vector<Config> configs = {
      /* AAC-LC CBR sweep */
      {64000, AAC_AOT_LC, AAC_RC_CBR},
      {96000, AAC_AOT_LC, AAC_RC_CBR},
      {128000, AAC_AOT_LC, AAC_RC_CBR},
      {160000, AAC_AOT_LC, AAC_RC_CBR},
      {192000, AAC_AOT_LC, AAC_RC_CBR},
      {256000, AAC_AOT_LC, AAC_RC_CBR},
      /* TVBR */
      {128000, AAC_AOT_LC, AAC_RC_TVBR},
      /* HE-AAC v1 */
      {48000, AAC_AOT_SBR, AAC_RC_CBR},
      {64000, AAC_AOT_SBR, AAC_RC_CBR},
  };

  /* Print header */
  printf(
      "┌─────────────────────────────────────────────────────────────────────────"
      "──────────────────────────────────────────┐\n");
  printf("│ %-28s │ %5s │ %4s │ %7s │ %7s │ %7s │ %5s │ %7s │ %6s │ %6s │\n", "Song", "BR", "Mode",
         "SNR", "MinSeg", "SD", "Frms", "ActBR", "Enc/s", "Dec/s");
  printf(
      "├─────────────────────────────────────────────────────────────────────────"
      "──────────────────────────────────────────┤\n");

  /* Process one song at a time to keep RAM bounded (~22 MB per song) */
  for (int fi = 1; fi < argc; fi++) {
    AudioBuffer song = load_audio(argv[fi]);

    if (song.frames < 4096) {
      fprintf(stderr, "  SKIP: %s (too short or load failed)\n", song.name.c_str());
      continue;
    }

    printf("│ %-28s │ %5s │ %4s │ %7s │ %7s │ %7s │ %5s │ %7s │ %6s │ %6s │\n", song.name.c_str(),
           "", "", "", "", "", "", "", "", "");

    for (const auto& cfg : configs) {
      auto r = benchmark_song(song, cfg.bitrate, cfg.aot, cfg.rc);
      if (r.snr < 0) {
        printf("│ %-28s │ %3dk │ %-4s │ %7s │ %7s │ %7s │ %5s │ %7s │ %6s │ %6s │\n", "",
               cfg.bitrate / 1000, r.rc_label, "SKIP", "-", "-", "-", "-", "-", "-");
        continue;
      }

      float audio_dur = (float)song.frames / song.sample_rate;
      float enc_speed = audio_dur / (r.encode_ms / 1000.0);
      float dec_speed = audio_dur / (r.decode_ms / 1000.0);

      const char* aot_label = (cfg.aot == AAC_AOT_SBR) ? "SBR" : "LC";

      printf(
          "│ %-28s │ %3dk %-2s│ %-4s │ %5.1fdB │ %5.1fdB │ %5.2fdB │ %5d │ %5.0fk │ "
          "%5.1fx │ %5.1fx │\n",
          "", cfg.bitrate / 1000, aot_label, r.rc_label, r.snr, r.snr_min_segment,
          r.spectral_distortion, r.n_frames, r.actual_bitrate / 1000.0f, enc_speed, dec_speed);
    }
    printf(
        "├─────────────────────────────────────────────────────────────────────────"
        "──────────────────────────────────────────┤\n");

    /* song goes out of scope here — frees ~22 MB */
  }

  printf(
      "└─────────────────────────────────────────────────────────────────────────"
      "──────────────────────────────────────────┘\n");

  printf("\nLegend:\n");
  printf("  BR    = target bitrate + profile (LC or SBR)\n");
  printf("  SNR   = signal-to-noise ratio over 60s segment (higher = better)\n");
  printf("  MinSeg= worst per-frame SNR (robustness indicator)\n");
  printf("  SD    = spectral distortion in dB (lower = better)\n");
  printf("  ActBR = actual bitrate achieved (may differ from target)\n");
  printf("  Enc/s = encoding speed vs realtime (e.g. 10x = 10s audio encoded per second)\n");
  printf("  Dec/s = decoding speed vs realtime\n");

  return 0;
}
