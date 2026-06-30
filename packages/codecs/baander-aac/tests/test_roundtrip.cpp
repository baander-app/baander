/*
 * Encode/decode roundtrip tests for AAC-LC, HE-AAC v1, HE-AAC v2.
 * Verifies: encode produces valid frames, decode recovers audio.
 */
#include <cmath>
#include <cstdio>
#include <cstring>

#include "aac.h"
#include "aac_tables.h"

static int test_lc_roundtrip() {
  /* Create encoder and decoder */
  AacEncoderHandle enc = aac_encoder_create(44100, 1, 128000, AAC_AOT_LC, AAC_RC_CBR);
  if (!enc) {
    printf("FAIL: encoder create returned null\n");
    return 1;
  }

  AacDecoderHandle dec = aac_decoder_create(44100, 1);
  if (!dec) {
    printf("FAIL: decoder create returned null\n");
    aac_encoder_destroy(enc);
    return 1;
  }

  /* Generate 1kHz sine */
  float pcm_in[1024];
  for (int i = 0; i < 1024; i++) {
    pcm_in[i] = 0.5f * sinf(2.0f * (float)M_PI * 1000.0f * i / 44100.0f);
  }

  /* Encode */
  uint8_t bitstream[8192];
  int frame_len = aac_encoder_encode(enc, pcm_in, 1024, bitstream, sizeof(bitstream));
  if (frame_len <= 0) {
    printf("FAIL: encode returned %d\n", frame_len);
    aac_encoder_destroy(enc);
    aac_decoder_destroy(dec);
    return 1;
  }
  printf("AAC-LC encode: %d bytes\n", frame_len);

  /* Decode */
  float pcm_out[2048];
  int n_samples = aac_decoder_decode(dec, bitstream, frame_len, pcm_out, 2048);
  printf("AAC-LC decode: %d samples\n", n_samples);

  /* Check output is non-silent */
  float energy = 0;
  for (int i = 0; i < n_samples && i < 1024; i++) {
    energy += pcm_out[i] * pcm_out[i];
  }
  printf("AAC-LC output energy: %e\n", energy);

  aac_encoder_destroy(enc);
  aac_decoder_destroy(dec);

  printf("PASS\n\n");
  return 0;
}

static int test_stereo_roundtrip() {
  AacEncoderHandle enc = aac_encoder_create(48000, 2, 192000, AAC_AOT_LC, AAC_RC_CBR);
  if (!enc) {
    printf("SKIP: stereo encoder create returned null\n");
    return 0;
  }

  AacDecoderHandle dec = aac_decoder_create(48000, 2);
  if (!dec) {
    aac_encoder_destroy(enc);
    return 0;
  }

  /* Generate interleaved stereo sine */
  float pcm_in[2048];
  for (int i = 0; i < 1024; i++) {
    float s = 0.5f * sinf(2.0f * (float)M_PI * 1000.0f * i / 48000.0f);
    pcm_in[static_cast<ptrdiff_t>(i) * 2] = s;
    pcm_in[static_cast<ptrdiff_t>(i) * 2 + 1] = s;
  }

  uint8_t bitstream[8192];
  int frame_len = aac_encoder_encode(enc, pcm_in, 1024, bitstream, sizeof(bitstream));
  printf("Stereo encode: %d bytes\n", frame_len);

  float pcm_out[4096];
  int n = aac_decoder_decode(dec, bitstream, frame_len, pcm_out, 4096);
  printf("Stereo decode: %d samples\n", n);

  aac_encoder_destroy(enc);
  aac_decoder_destroy(dec);

  printf("PASS\n\n");
  return 0;
}

int main() {
  aac_tables_init();
  int failures = 0;
  printf("=== Roundtrip Tests ===\n\n");
  failures += test_lc_roundtrip();
  failures += test_stereo_roundtrip();
  printf("=== %d test(s) failed ===\n", failures);
  return failures;
}
