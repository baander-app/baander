/*
 * Decoder compliance tests for baander-aac.
 * Focus: CPE common_window parsing, M/S stereo decoding, and malformed-stream
 * robustness.
 */
#include <cmath>
#include <cstdio>
#include <cstring>

#include "aac.h"
#include "aac_tables.h"
#include "bitstream.h"
#include "mdct.h"

/* Build a minimal ADTS + CPE frame with common_window=1 and the specified
 * ms_mask_present.  Both channels carry the same window parameters; spectral
 * data is synthetic and confined to scalefactor band 0 (bins 0..3 @ 48kHz).
 *
 * The spectral payload is chosen so that M/S decoding has an observable effect:
 * both channels carry identical non-zero spectral data in bin 0 only.
 * With M/S applied, L = (M+S)/sqrt(2) has energy and R = (M-S)/sqrt(2) is silent.
 * Without M/S, L and R are identical. */
static int build_ms_cpe_frame(uint8_t* out, int out_size, int ms_present) {
  if (out_size < 64) {
    return -1;
  }

  AacBitWriter w;
  aac_bitwriter_init(&w, out, out_size);

  /* ADTS placeholder (7 bytes) */
  aac_bitwriter_write(&w, 0, 56);

  /* CPE element */
  aac_bitwriter_write(&w, AAC_ELEM_CPE, 3);
  aac_bitwriter_write(&w, 0, 4);            /* element_instance_tag */
  aac_bitwriter_write(&w, 1, 1);            /* common_window */
  aac_bitwriter_write(&w, 0, 1);            /* ics_reserved_bit */
  aac_bitwriter_write(&w, AAC_WIN_ONLY_LONG, 2); /* window_sequence */
  aac_bitwriter_write(&w, AAC_WIN_SINE, 1); /* window_shape */
  aac_bitwriter_write(&w, 49, 6);           /* max_sfb */
  aac_bitwriter_write(&w, 0, 1);            /* predictor_data_present */
  aac_bitwriter_write(&w, ms_present, 2);   /* ms_mask_present */
  if (ms_present == 1) {
    for (int b = 0; b < 49; b++) {
      aac_bitwriter_write(&w, 1, 1);        /* ms_used[b] = 1 */
    }
  }

  const int cb = 4; /* unsigned, dim=4, max_val=2 */
  for (int ch = 0; ch < 2; ch++) {
    aac_bitwriter_write(&w, 100, 8);        /* global_gain -> offset 0 */

    /* section_data: band 0 uses cb=4, bands 1..48 are ZERO_BT. */
    aac_bitwriter_write(&w, cb, 4);         /* sect_cb = 4 */
    aac_bitwriter_write(&w, 1, 5);          /* sect_len = 1 */
    aac_bitwriter_write(&w, 0, 4);          /* sect_cb = 0 */
    aac_bitwriter_write(&w, 31, 5);         /* sect_len escape */
    aac_bitwriter_write(&w, 17, 5);         /* remaining length: 48 - 31 = 17 */

    /* scale_factor_data: only band 0 has a scalefactor, dpcm = 0. */
    aac_bitwriter_write(&w, 0, 1);          /* index 60 -> diff 0 */

    /* pulse_data_present, tns_data_present, gain_control_data_present */
    aac_bitwriter_write(&w, 0, 1);
    aac_bitwriter_write(&w, 0, 1);
    aac_bitwriter_write(&w, 0, 1);

    /* spectral_data: band 0 has 4 bins -> one CB4 quad (1,0,0,0).  Both
     * channels carry identical spectral data so that, with M/S applied, the
     * S channel (ch1) cancels to zero and the R output is silent. */
    int quad[4] = {1, 0, 0, 0};
    aac_bitwriter_write_huffman(&w, cb, quad);
  }

  aac_bitwriter_write(&w, AAC_ELEM_END, 3);
  aac_bitwriter_byte_align(&w);

  int frame_len = aac_bitwriter_bytes_written(&w);

  /* Fill in ADTS header. */
  AacAdtsHeader hdr = {};
  hdr.id = 0;
  hdr.layer = 0;
  hdr.protection_absent = 1;
  hdr.profile = AAC_AOT_LC;
  hdr.sample_rate_index = 3; /* 48000 Hz */
  hdr.channel_config = 2;
  hdr.frame_length = frame_len;
  hdr.buffer_fullness = 0x7FF;
  hdr.num_aac_frames = 0; /* one raw_data_block -> (count - 1) per ISO 13818-7 */
  aac_adts_write(&hdr, out);

  return frame_len;
}

static void channel_energies(const float* pcm, double* eL, double* eR) {
  *eL = 0.0;
  *eR = 0.0;
  for (int i = 0; i < 1024; i++) {
    double L = pcm[static_cast<ptrdiff_t>(i) * 2];
    double R = pcm[static_cast<ptrdiff_t>(i) * 2 + 1];
    *eL += L * L;
    *eR += R * R;
  }
}

static int test_ms_stereo_decode() {
  printf("=== M/S stereo CPE decode ===\n");

  uint8_t frame[256];
  float pcm[4096];
  double eL = 0.0, eR = 0.0;

  /* With ms_mask_present == 2 (all M/S) and identical M/S spectra, the right
   * channel should be silent after decoding. */
  AacDecoderHandle dec_ms = aac_decoder_create(48000, 2);
  if (!dec_ms) {
    printf("FAIL: decoder create returned null\n");
    return 1;
  }
  int frame_len = build_ms_cpe_frame(frame, sizeof(frame), 2); /* all M/S */
  if (frame_len <= 0) {
    printf("FAIL: frame build failed\n");
    aac_decoder_destroy(dec_ms);
    return 1;
  }
  printf("Synthetic CPE frame: %d bytes\n", frame_len);
  int n = aac_decoder_decode(dec_ms, frame, frame_len, pcm, 4096);
  if (n != 1024) {
    printf("FAIL: expected 1024 samples per channel, got %d\n", n);
    aac_decoder_destroy(dec_ms);
    return 1;
  }
  channel_energies(pcm, &eL, &eR);
  printf("M/S on:  eL=%.6f eR=%.6f ratio=%.6f\n", eL, eR, eR / (eL + 1e-12));
  aac_decoder_destroy(dec_ms);

  if (eL < 1e-9) {
    printf("FAIL: left channel is silent\n");
    return 1;
  }
  if (eR > 0.01 * eL) {
    printf("FAIL: right channel not suppressed; M/S not applied?\n");
    return 1;
  }

  /* With ms_mask_present == 0 and identical input spectra, both channels should
   * decode to the same time-domain signal. */
  AacDecoderHandle dec_plain = aac_decoder_create(48000, 2);
  if (!dec_plain) {
    printf("FAIL: decoder create returned null\n");
    return 1;
  }
  frame_len = build_ms_cpe_frame(frame, sizeof(frame), 0); /* no M/S */
  n = aac_decoder_decode(dec_plain, frame, frame_len, pcm, 4096);
  if (n != 1024) {
    printf("FAIL: expected 1024 samples per channel, got %d\n", n);
    aac_decoder_destroy(dec_plain);
    return 1;
  }
  channel_energies(pcm, &eL, &eR);
  printf("M/S off: eL=%.6f eR=%.6f ratio=%.6f\n", eL, eR, std::fabs(eR - eL) / (eL + eR + 1e-12));
  aac_decoder_destroy(dec_plain);

  if (eL < 1e-9 || eR < 1e-9) {
    printf("FAIL: plain decode produced a silent channel\n");
    return 1;
  }
  if (std::fabs(eR - eL) > 0.05 * (eL + eR)) {
    printf("FAIL: plain decode channels differ unexpectedly\n");
    return 1;
  }

  /* ms_mask_present == 1 with all ms_used bits set should behave like
   * ms_mask_present == 2. */
  AacDecoderHandle dec_mask = aac_decoder_create(48000, 2);
  if (!dec_mask) {
    printf("FAIL: decoder create returned null\n");
    return 1;
  }
  frame_len = build_ms_cpe_frame(frame, sizeof(frame), 1); /* mask read from bits */
  n = aac_decoder_decode(dec_mask, frame, frame_len, pcm, 4096);
  if (n != 1024) {
    printf("FAIL: expected 1024 samples per channel, got %d\n", n);
    aac_decoder_destroy(dec_mask);
    return 1;
  }
  channel_energies(pcm, &eL, &eR);
  printf("M/S mask=1: eL=%.6f eR=%.6f ratio=%.6f\n", eL, eR, eR / (eL + 1e-12));
  aac_decoder_destroy(dec_mask);

  if (eL < 1e-9 || eR > 0.01 * eL) {
    printf("FAIL: explicit ms_used mask not applied correctly\n");
    return 1;
  }

  printf("PASS\n\n");
  return 0;
}

static int test_ms_mask_reserved() {
  printf("=== Reserved ms_mask_present == 3 rejection ===\n");

  AacDecoderHandle dec = aac_decoder_create(48000, 2);
  if (!dec) {
    printf("FAIL: decoder create returned null\n");
    return 1;
  }

  uint8_t frame[256];
  int frame_len = build_ms_cpe_frame(frame, sizeof(frame), 3); /* reserved */
  if (frame_len <= 0) {
    printf("FAIL: frame build failed\n");
    aac_decoder_destroy(dec);
    return 1;
  }

  float pcm[4096];
  int n = aac_decoder_decode(dec, frame, frame_len, pcm, 4096);
  if (n >= 0) {
    printf("FAIL: reserved ms_mask_present=3 should fail, got %d\n", n);
    aac_decoder_destroy(dec);
    return 1;
  }

  aac_decoder_destroy(dec);
  printf("PASS (decode returned %d)\n\n", n);
  return 0;
}

static int test_truncated_frame_robust() {
  printf("=== Truncated frame robustness ===\n");

  AacDecoderHandle dec = aac_decoder_create(48000, 2);
  if (!dec) {
    printf("FAIL: decoder create returned null\n");
    return 1;
  }

  uint8_t frame[256];
  int frame_len = build_ms_cpe_frame(frame, sizeof(frame), 2);
  if (frame_len <= 7) {
    printf("FAIL: frame build failed\n");
    aac_decoder_destroy(dec);
    return 1;
  }

  float pcm[4096];
  /* Truncate to just the ADTS header; decoder should not crash. */
  int n = aac_decoder_decode(dec, frame, 7, pcm, 4096);
  if (n > 0) {
    printf("WARN: truncated header produced %d samples (expected <=0)\n", n);
  }

  /* Truncate mid-stream (after header, mid-CPE). */
  n = aac_decoder_decode(dec, frame, frame_len / 2, pcm, 4096);
  if (n > 0) {
    printf("WARN: truncated stream produced %d samples (expected <=0)\n", n);
  }

  aac_decoder_destroy(dec);
  printf("PASS (no crash on truncated input)\n\n");
  return 0;
}

int main() {
  aac_tables_init();
  int failures = 0;
  failures += test_ms_stereo_decode();
  failures += test_ms_mask_reserved();
  failures += test_truncated_frame_robust();
  printf("=== %d test(s) failed ===\n", failures);
  return failures;
}
