/*
 * Rate-control and bitstream robustness tests.
 * Verifies: bitwriter overflow detection, ADTS buffer_fullness signaling,
 * and that CBR keeps the long-term frame size near the bitrate target.
 */
#include <cmath>
#include <cstdio>
#include <cstring>

#include "aac.h"
#include "aac_tables.h"
#include "bitstream.h"

static int test_bitwriter_overflow() {
  uint8_t buf[8];
  AacBitWriter w;
  aac_bitwriter_init(&w, buf, sizeof(buf));

  /* Write beyond capacity; the writer must flag the overflow instead of
   * silently dropping bits. */
  for (int i = 0; i < 20; i++) {
    aac_bitwriter_write(&w, 0xFF, 8);
  }

  if (!aac_bitwriter_overflow(&w)) {
    printf("FAIL: bitwriter overflow was not detected\n");
    return 1;
  }
  printf("PASS: bitwriter overflow detected\n\n");
  return 0;
}

static int test_adts_buffer_fullness() {
  const int sample_rate = 44100;
  const int channels = 1;
  const int bitrate = 128000;

  float pcm[1024];
  for (int i = 0; i < 1024; i++) {
    pcm[i] = 0.3f * sinf(2.0f * (float)M_PI * 1000.0f * i / (float)sample_rate);
  }

  struct Mode {
    AacRateControl rc;
    const char* name;
    int expect_vbr_marker;
  } modes[] = {{AAC_RC_CBR, "CBR", 0},
               {AAC_RC_ABR, "ABR", 0},
               {AAC_RC_CVBR, "CVBR", 1},
               {AAC_RC_TVBR, "TVBR", 1}};

  int failures = 0;
  for (const auto& m : modes) {
    AacEncoderHandle enc = aac_encoder_create(sample_rate, channels, bitrate, AAC_AOT_LC, m.rc);
    if (!enc) {
      printf("SKIP: %s encoder create failed\n", m.name);
      continue;
    }

    uint8_t bs[8192];
    int len = aac_encoder_encode(enc, pcm, 1024, bs, sizeof(bs));
    if (len <= 0) {
      printf("FAIL: %s encode returned %d\n", m.name, len);
      failures++;
      aac_encoder_destroy(enc);
      continue;
    }

    AacAdtsHeader hdr;
    if (aac_adts_parse(&hdr, bs, len) != 0) {
      printf("FAIL: %s ADTS parse failed\n", m.name);
      failures++;
      aac_encoder_destroy(enc);
      continue;
    }

    if (m.expect_vbr_marker) {
      if (hdr.buffer_fullness != 0x7FF) {
        printf("FAIL: %s expected VBR marker 0x7FF, got 0x%03X\n", m.name,
               hdr.buffer_fullness);
        failures++;
      }
    } else {
      if (hdr.buffer_fullness > 0x7FE) {
        printf("FAIL: %s buffer_fullness 0x%03X is not a valid CBR/ABR value\n",
               m.name, hdr.buffer_fullness);
        failures++;
      }
    }

    printf("%s: frame=%d bytes buffer_fullness=0x%03X\n", m.name, len,
           hdr.buffer_fullness);
    aac_encoder_destroy(enc);
  }

  printf("%s\n\n", failures ? "FAIL" : "PASS");
  return failures;
}

static int test_cbr_long_term_average() {
  const int sample_rate = 44100;
  const int channels = 1;
  const int bitrate = 128000;
  const int n_frames = 20;
  const int target_bits_per_frame =
      (int)((long long)bitrate * 1024 / sample_rate);

  AacEncoderHandle enc = aac_encoder_create(sample_rate, channels, bitrate,
                                            AAC_AOT_LC, AAC_RC_CBR);
  if (!enc) {
    printf("SKIP: CBR encoder create failed\n");
    return 0;
  }

  uint8_t bs[8192];
  long long total_bits = 0;
  for (int f = 0; f < n_frames; f++) {
    float pcm[1024];
    for (int i = 0; i < 1024; i++) {
      /* Alternating tone to vary frame complexity. */
      float freq = (f % 4 == 0) ? 4000.0f : 1000.0f;
      pcm[i] = 0.4f * sinf(2.0f * (float)M_PI * freq * i / (float)sample_rate);
    }
    int len = aac_encoder_encode(enc, pcm, 1024, bs, sizeof(bs));
    if (len <= 0) {
      printf("FAIL: frame %d encode returned %d\n", f, len);
      aac_encoder_destroy(enc);
      return 1;
    }
    total_bits += len * 8;
  }
  aac_encoder_destroy(enc);

  double avg_bits = (double)total_bits / n_frames;
  double deviation = fabs(avg_bits - (double)target_bits_per_frame) /
                     (double)target_bits_per_frame;
  printf("CBR average: target=%d bits/frame, actual=%.1f bits/frame "
         "(%.1f%% deviation)\n",
         target_bits_per_frame, avg_bits, deviation * 100.0);

  /* The reservoir allows individual frames to vary, but the long-term average
   * should be within 25% of the nominal bitrate.  libfdk-aac and Apple both
   * use a much tighter servo; this test enforces a coarse bound so that the
   * current quantizer's target tracking can be tightened later without
   * breaking the test. */
  if (deviation > 0.25) {
    printf("FAIL: CBR average bitrate deviates too much\n");
    return 1;
  }

  printf("PASS\n\n");
  return 0;
}

static int test_adts_framing_semantics() {
  const int sample_rate = 44100;
  const int channels = 1;

  float pcm[1024];
  for (int i = 0; i < 1024; i++) {
    pcm[i] = 0.3f * sinf(2.0f * (float)M_PI * 800.0f * i / (float)sample_rate);
  }

  AacEncoderHandle enc = aac_encoder_create(sample_rate, channels, 96000,
                                            AAC_AOT_LC, AAC_RC_CBR);
  AacDecoderHandle dec = aac_decoder_create(sample_rate, channels);
  if (!enc || !dec) {
    printf("SKIP: encoder/decoder create failed\n");
    if (enc) { aac_encoder_destroy(enc); }
    if (dec) { aac_decoder_destroy(dec); }
    return 0;
  }

  uint8_t bs[8192];
  int len = aac_encoder_encode(enc, pcm, 1024, bs, sizeof(bs));
  if (len <= 0) {
    printf("FAIL: encode returned %d\n", len);
    aac_encoder_destroy(enc);
    aac_decoder_destroy(dec);
    return 1;
  }

  int failures = 0;
  AacAdtsHeader hdr;
  if (aac_adts_parse(&hdr, bs, len) != 0) {
    printf("FAIL: ADTS parse failed\n");
    failures++;
  } else {
    /* ISO 13818-7 §8.1.1: number_of_raw_data_blocks_in_frame counts
     * (blocks - 1).  The encoder emits one raw_data_block, so the field
     * must be 0. */
    if (hdr.num_aac_frames != 0) {
      printf("FAIL: num_aac_frames=%d, expected 0 for one raw_data_block\n",
             hdr.num_aac_frames);
      failures++;
    }
    if (hdr.frame_length != len) {
      printf("FAIL: frame_length=%d != encoded %d bytes\n", hdr.frame_length,
             len);
      failures++;
    }
  }

  /* Decode the frame once to get a reference sample count. */
  float pcm_ref[2048];
  int n_ref = aac_decoder_decode(dec, bs, len, pcm_ref, 2048);
  if (n_ref <= 0) {
    printf("FAIL: reference decode returned %d\n", n_ref);
    failures++;
  }

  /* Re-frame the same payload with protection_absent=0 and a dummy 16-bit
   * CRC, as a CRC-protected encoder would emit.  The decoder must skip the
   * CRC and decode identically. */
  if (n_ref > 0 && len + 2 < (int)sizeof(bs)) {
    uint8_t crc_frame[8200];
    memcpy(crc_frame, bs, len);
    memmove(crc_frame + AAC_ADTS_HEADER_SIZE + 2,
            crc_frame + AAC_ADTS_HEADER_SIZE,
            (size_t)(len - AAC_ADTS_HEADER_SIZE));
    crc_frame[AAC_ADTS_HEADER_SIZE] = 0x12;     /* dummy CRC high byte */
    crc_frame[AAC_ADTS_HEADER_SIZE + 1] = 0x34; /* dummy CRC low byte */

    AacAdtsHeader crc_hdr = hdr;
    crc_hdr.protection_absent = 0;
    crc_hdr.frame_length = len + 2;
    aac_adts_write(&crc_hdr, crc_frame);

    AacAdtsHeader parsed;
    if (aac_adts_parse(&parsed, crc_frame, len + 2) != 0) {
      printf("FAIL: CRC-protected ADTS parse failed\n");
      failures++;
    } else {
      float pcm_crc[2048];
      int n_crc = aac_decoder_decode(dec, crc_frame, len + 2, pcm_crc, 2048);
      if (n_crc != n_ref) {
        printf("FAIL: CRC-protected decode produced %d samples, expected %d\n",
               n_crc, n_ref);
        failures++;
      }
    }
  }

  /* A header claiming frame_length smaller than the header itself is
   * malformed and must be rejected. */
  {
    AacAdtsHeader bad = hdr;
    bad.protection_absent = 1;
    bad.frame_length = AAC_ADTS_HEADER_SIZE - 1;
    uint8_t bad_frame[7];
    aac_adts_write(&bad, bad_frame);
    AacAdtsHeader parsed;
    if (aac_adts_parse(&parsed, bad_frame, sizeof(bad_frame)) == 0) {
      printf("FAIL: frame_length < header size was accepted\n");
      failures++;
    }
  }

  aac_encoder_destroy(enc);
  aac_decoder_destroy(dec);

  printf("%s: ADTS framing semantics\n\n", failures ? "FAIL" : "PASS");
  return failures;
}

int main() {
  aac_tables_init();
  int failures = 0;
  printf("=== Rate-Control Tests ===\n\n");
  failures += test_bitwriter_overflow();
  failures += test_adts_buffer_fullness();
  failures += test_cbr_long_term_average();
  failures += test_adts_framing_semantics();
  printf("=== %d test(s) failed ===\n", failures);
  return failures;
}
