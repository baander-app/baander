/*
 * Encoder regression tests for the psychoacoustic/quantization fixes:
 *
 * 1. TLS clamped-quantizer overshoot: the two-loop search used to walk every
 *    band's scalefactor into the q=codebook-max clamp region, quantizing all
 *    coefficients to zero — decoded output was pure silence (SNR 0.0 dB).
 * 2. Bitrate scaling: before codebook-11 escape sequences, frame sizes and
 *    SNR were nearly flat from 64k to 256k (65-72 bytes, ~23.6 dB) because
 *    the ±12 signed-book clamp capped precision.
 * 3. M/S in-place aliasing: aac_ms_encode(mid=L, side=R) overwrote L[i]
 *    before computing side[i], corrupting identical-channel stereo to a
 *    ~13.7 dB SNR floor regardless of bitrate.
 *
 * Floors are regression guards set well below current measured output.
 */
#include <cmath>
#include <cstdio>
#include <cstring>

#include "aac.h"
#include "aac_tables.h"

static float compute_snr(const float* ref, const float* test, int n) {
  float signal_power = 0, noise_power = 0;
  for (int i = 0; i < n; i++) {
    signal_power += ref[i] * ref[i];
    float err = ref[i] - test[i];
    noise_power += err * err;
  }
  if (noise_power < 1e-20f) {
    return 999.0f;
  }
  return 10.0f * log10f(signal_power / noise_power);
}

/* Encode 3 frames of pcm (3072 mono samples) and return the SNR of the third
 * decoded frame against pcm[1024..2047] (MDCT delay compensated), with the
 * last frame length in *frame_len. */
static float mono_snr_at(int bitrate, const float* pcm, int* frame_len) {
  AacEncoderHandle enc = aac_encoder_create(44100, 1, bitrate, AAC_AOT_LC, AAC_RC_CBR);
  AacDecoderHandle dec = aac_decoder_create(44100, 1);
  if (!enc || !dec) {
    return -1.0f;
  }
  uint8_t bs[8192];
  float out[2048];
  int len = 0, n = 0;
  for (int f = 0; f < 3; f++) {
    len = aac_encoder_encode(enc, pcm + f * 1024, 1024, bs, sizeof(bs));
    n = aac_decoder_decode(dec, bs, len, out, 2048);
  }
  aac_encoder_destroy(enc);
  aac_decoder_destroy(dec);
  if (frame_len) {
    *frame_len = len;
  }
  if (n < 1024 || len <= 0) {
    return -1.0f;
  }
  return compute_snr(pcm + 1024, out, 1024);
}

int main() {
  aac_tables_init();
  int failures = 0;
  printf("=== Encoder Regression Tests ===\n\n");

  /* Multi-tone signal (same as test_quality). */
  float pcm[3072];
  for (int i = 0; i < 3072; i++) {
    pcm[i] = 0.3f * sinf(2.0f * (float)M_PI * 440.0f * i / 44100.0f) +
             0.2f * sinf(2.0f * (float)M_PI * 1000.0f * i / 44100.0f) +
             0.1f * sinf(2.0f * (float)M_PI * 4000.0f * i / 44100.0f);
  }

  /* 1. Silence regression + low-bitrate floor.  Pre-fix this was 0.0 dB;
   * current output is ~38 dB at 64k. */
  int len64 = 0;
  float snr64 = mono_snr_at(64000, pcm, &len64);
  printf("64k mono multitone: frame=%d bytes, SNR=%.1f dB (floor 25)\n", len64, snr64);
  if (snr64 < 25.0f) {
    printf("  FAIL: SNR %.1f dB below 25 dB floor (TLS clamp overshoot?)\n", snr64);
    failures++;
  }

  /* 2. Bitrate scaling: 256k must clearly beat 64k and use a larger frame.
   * Pre-escape-book this gap was ~0.8 dB / 7 bytes; current ~10 dB. */
  int len256 = 0;
  float snr256 = mono_snr_at(256000, pcm, &len256);
  printf("256k mono multitone: frame=%d bytes, SNR=%.1f dB\n", len256, snr256);
  if (snr256 < 40.0f) {
    printf("  FAIL: SNR %.1f dB below 40 dB floor\n", snr256);
    failures++;
  }
  if (snr256 < snr64 + 5.0f || len256 <= len64) {
    printf("  FAIL: quality does not scale with bitrate (%.1f dB / %d B at 64k vs %.1f dB / %d B "
           "at 256k)\n",
           snr64, len64, snr256, len256);
    failures++;
  }

  /* 3. Identical-channel stereo (M/S aliasing regression).  Pre-fix both
   * channels sat at ~13.7 dB regardless of bitrate; current ~38 dB at 128k. */
  {
    AacEncoderHandle enc = aac_encoder_create(44100, 2, 128000, AAC_AOT_LC, AAC_RC_CBR);
    AacDecoderHandle dec = aac_decoder_create(44100, 2);
    float spcm[3072 * 2], out[2048 * 2];
    for (int i = 0; i < 3072; i++) {
      float v = 0.3f * sinf(2.0f * (float)M_PI * 440.0f * i / 44100.0f) +
                0.2f * sinf(2.0f * (float)M_PI * 1000.0f * i / 44100.0f);
      spcm[i * 2] = v;
      spcm[i * 2 + 1] = v;
    }
    uint8_t bs[16384];
    int len = 0, n = 0;
    for (int f = 0; f < 3; f++) {
      len = aac_encoder_encode(enc, spcm + f * 1024 * 2, 1024, bs, sizeof(bs));
      n = aac_decoder_decode(dec, bs, len, out, 2048 * 2);
    }
    float snr_l = -1.0f, snr_r = -1.0f;
    if (n >= 1024 && len > 0) {
      /* Deinterleaved SNR of the measured frame. */
      float ref[1024], tst[1024];
      for (int i = 0; i < 1024; i++) {
        ref[i] = spcm[(1024 + i) * 2];
        tst[i] = out[i * 2];
      }
      snr_l = compute_snr(ref, tst, 1024);
      for (int i = 0; i < 1024; i++) {
        ref[i] = spcm[(1024 + i) * 2 + 1];
        tst[i] = out[i * 2 + 1];
      }
      snr_r = compute_snr(ref, tst, 1024);
    }
    printf("128k stereo identical: frame=%d bytes, SNR L=%.1f R=%.1f dB (floor 25)\n", len, snr_l,
           snr_r);
    if (snr_l < 25.0f || snr_r < 25.0f) {
      printf("  FAIL: identical-channel stereo SNR below 25 dB (M/S aliasing?)\n");
      failures++;
    }
    aac_encoder_destroy(enc);
    aac_decoder_destroy(dec);
  }

  /* 4. Full-scale sine: forces escape-sequence magnitudes (|q| > 16) in
   * codebook 11 on both writer and reader. */
  {
    float loud[3072];
    for (int i = 0; i < 3072; i++) {
      loud[i] = 0.9f * sinf(2.0f * (float)M_PI * 1000.0f * i / 44100.0f);
    }
    int len = 0;
    float snr = mono_snr_at(128000, loud, &len);
    printf("128k mono full-scale sine: frame=%d bytes, SNR=%.1f dB (floor 30)\n", len, snr);
    if (snr < 30.0f) {
      printf("  FAIL: SNR %.1f dB below 30 dB floor (escape path?)\n", snr);
      failures++;
    }
  }

  printf("\n=== %d test(s) failed ===\n", failures);
  return failures;
}
