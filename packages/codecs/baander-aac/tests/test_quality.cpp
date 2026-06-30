/*
 * Quality benchmark harness.
 * Measures encode/decode quality metrics (spectral comparison, SNR).
 * Uses 3 frames to account for the 1024-sample MDCT delay:
 *   Frame 1 primes overlap, Frame 2 reconstructs pcm[0..1023],
 *   Frame 3 reconstructs pcm[1024..2047] (measured).
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
  if (noise_power < 1e-20f) { return 999.0f;
}
  return 10.0f * log10f(signal_power / noise_power);
}

int main(int argc, char** argv) {
  if (argc > 1 && strcmp(argv[1], "--help") == 0) {
    printf("Usage: test_quality [--help]\n");
    printf("Runs quality benchmark for AAC encode/decode.\n");
    return 0;
  }

  aac_tables_init();
  printf("=== Quality Benchmark ===\n\n");

  /* Test at various bitrates */
  int bitrates[] = {64000, 96000, 128000, 192000, 256000};
  const char* labels[] = {"64k", "96k", "128k", "192k", "256k"};

  for (int b = 0; b < 5; b++) {
    AacEncoderHandle enc = aac_encoder_create(44100, 1, bitrates[b], AAC_AOT_LC, AAC_RC_CBR);
    AacDecoderHandle dec = aac_decoder_create(44100, 1);
    if (!enc || !dec) {
      printf("%s CBR: SKIP (create failed)\n", labels[b]);
      if (enc) { aac_encoder_destroy(enc);
}
      if (dec) { aac_decoder_destroy(dec);
}
      continue;
    }

    /* Generate multi-tone test signal: 3 frames = 3072 samples.
     * The MDCT has N=1024 sample delay, so we need 3 encode/decode
     * cycles to get a full reconstruction of the second frame. */
    float pcm_in[3072];
    for (int i = 0; i < 3072; i++) {
      pcm_in[i] = 0.3f * sinf(2.0f * (float)M_PI * 440.0f * i / 44100.0f) +
                  0.2f * sinf(2.0f * (float)M_PI * 1000.0f * i / 44100.0f) +
                  0.1f * sinf(2.0f * (float)M_PI * 4000.0f * i / 44100.0f);
    }

    uint8_t bs[8192];
    float pcm_out[2048];

    /* Frame 1: primes encoder/decoder overlap */
    int len1 = aac_encoder_encode(enc, pcm_in, 1024, bs, sizeof(bs));
    aac_decoder_decode(dec, bs, len1, pcm_out, 2048);

    /* Frame 2: reconstructs pcm_in[0..1023] */
    int len2 = aac_encoder_encode(enc, pcm_in + 1024, 1024, bs, sizeof(bs));
    aac_decoder_decode(dec, bs, len2, pcm_out, 2048);

    /* Frame 3: reconstructs pcm_in[1024..2047] — this is the measured frame */
    int len3 = aac_encoder_encode(enc, pcm_in + 2048, 1024, bs, sizeof(bs));
    int n3 = aac_decoder_decode(dec, bs, len3, pcm_out, 2048);

    float snr = 0.0f;
    if (n3 > 0 && len3 > 0) {
      snr = compute_snr(pcm_in + 1024, pcm_out, n3 < 1024 ? n3 : 1024);
      printf("%s CBR: frame=%d bytes, decoded=%d samples, SNR=%.1f dB\n", labels[b], len3, n3, snr);
    } else {
      printf("%s CBR: encode/decode failed\n", labels[b]);
    }

    aac_encoder_destroy(enc);
    aac_decoder_destroy(dec);
  }

  printf("\n=== Benchmark Complete ===\n");
  return 0;
}
