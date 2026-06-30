/*
 * Bitstream reader/writer roundtrip tests.
 * Verifies: bit read/write, ADTS parse/write, Huffman encode/decode.
 */
#include <cmath>
#include <cstdio>
#include <cstring>

#include "aac_tables.h"
#include "bitstream.h"

static int test_bit_rw_roundtrip() {
  uint8_t buf[256];
  AacBitWriter w;
  aac_bitwriter_init(&w, buf, sizeof(buf));

  /* Write 1000 random-ish bit patterns */
  struct Pattern {
    uint32_t val;
    int bits;
  };
  Pattern patterns[20];
  uint32_t rng = 12345;
  for (int i = 0; i < 20; i++) {
    rng = rng * 1103515245 + 12345;
    patterns[i].bits = (rng % 30) + 1;
    patterns[i].val = (rng >> 8) & ((1u << patterns[i].bits) - 1);
    aac_bitwriter_write(&w, patterns[i].val, patterns[i].bits);
  }

  /* Read back */
  AacBitReader r;
  aac_bitreader_init(&r, buf, sizeof(buf));
  int failures = 0;
  for (int i = 0; i < 20; i++) {
    uint32_t got = aac_bitreader_read(&r, patterns[i].bits);
    if (got != patterns[i].val) {
      printf("FAIL: bit pattern %d: wrote %u, read %u (%d bits)\n", i, patterns[i].val, got,
             patterns[i].bits);
      failures++;
    }
  }
  printf("Bit read/write roundtrip: %d failures\n", failures);
  if (failures) {
    return 1;
  }
  printf("PASS\n\n");
  return 0;
}

static int test_adts_roundtrip() {
  AacAdtsHeader hdr = {};  // NOLINT(bugprone-invalid-enum-default-initialization)
  hdr.id = 0;
  hdr.layer = 0;
  hdr.protection_absent = 1;
  hdr.profile = AAC_AOT_LC;
  hdr.sample_rate_index = 3; /* 48000 */
  hdr.channel_config = 2;
  hdr.frame_length = 256;
  hdr.buffer_fullness = 0x7FF;
  hdr.num_aac_frames = 1;

  uint8_t buf[7];
  aac_adts_write(&hdr, buf);

  AacAdtsHeader parsed;
  int ret = aac_adts_parse(&parsed, buf, 7);
  if (ret != 0) {
    printf("FAIL: ADTS parse returned %d\n", ret);
    return 1;
  }

  int failures = 0;
  if (parsed.profile != hdr.profile) {
    printf("FAIL: profile mismatch\n");
    failures++;
  }
  if (parsed.sample_rate_index != hdr.sample_rate_index) {
    printf("FAIL: sample_rate_index mismatch\n");
    failures++;
  }
  if (parsed.channel_config != hdr.channel_config) {
    printf("FAIL: channel_config mismatch\n");
    failures++;
  }
  if (parsed.frame_length != hdr.frame_length) {
    printf("FAIL: frame_length mismatch\n");
    failures++;
  }

  printf("ADTS roundtrip: %d failures\n", failures);
  if (failures) {
    return 1;
  }
  printf("PASS\n\n");
  return 0;
}

static int test_huffman_roundtrip() {
  int failures = 0;
  /* Test codebooks 1-5 (populated) */
  for (int cb = 1; cb <= 5; cb++) {
    if (!aac_huff_code[cb] || !aac_huff_len[cb]) {
      continue;
    }
    const AacCodebookInfo* info = &aac_codebook_info[cb];
    int mv = info->max_val;

    /* Test a few symbol pairs */
    for (int x = 0; x <= mv && x < 3; x++) {
      for (int y = 0; y <= mv && y < 3; y++) {
        uint8_t buf[16];
        AacBitWriter w;
        aac_bitwriter_init(&w, buf, sizeof(buf));
        aac_bitwriter_write_huffman(&w, cb, x, y);

        AacBitReader r;
        aac_bitreader_init(&r, buf, sizeof(buf));
        int rx = 0, ry = 0;
        int ret = aac_bitreader_read_huffman(&r, cb, &rx, &ry);
        if (ret != 0) {
          printf("FAIL: Huffman cb=%d (%d,%d): decode returned %d\n", cb, x, y, ret);
          failures++;
        } else if (rx != x || ry != y) {
          printf("FAIL: Huffman cb=%d: wrote (%d,%d), read (%d,%d)\n", cb, x, y, rx, ry);
          failures++;
        }
      }
    }
  }
  printf("Huffman roundtrip (codebooks 1-5): %d failures\n", failures);
  if (failures) {
    return 1;
  }
  printf("PASS\n\n");
  return 0;
}

int main() {
  aac_tables_init();
  int failures = 0;
  printf("=== Bitstream Tests ===\n\n");
  failures += test_bit_rw_roundtrip();
  failures += test_adts_roundtrip();
  failures += test_huffman_roundtrip();
  printf("=== %d test(s) failed ===\n", failures);
  return failures;
}
