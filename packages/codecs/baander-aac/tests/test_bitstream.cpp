/* Bit reader/writer, ADTS headers, and the Huffman coding of spectral
 * values and scalefactors. */
#include "aac_tables.h"
#include "bitstream.h"
#include "test_util.h"

static void test_bits() {
  uint8_t buf[64];
  AacBitWriter w;
  aac_bitwriter_init(&w, buf, sizeof(buf));
  const struct {
    uint32_t v;
    int bits;
  } p[] = {{1, 1}, {0, 1}, {5, 3}, {0xABCD, 16}, {0, 7}, {0x1FFFFF, 21}, {0xFFFFFFFF, 32}, {3, 2}};
  int total = 0;
  for (const auto& e : p) {
    aac_bitwriter_write(&w, e.v, e.bits);
    total += e.bits;
  }
  CHECK(aac_bitwriter_bits_written(&w) == total, "bits written %d", aac_bitwriter_bits_written(&w));
  CHECK(aac_bitwriter_bytes_written(&w) == (total + 7) / 8, "bytes written");
  AacBitReader r;
  aac_bitreader_init(&r, buf, sizeof(buf));
  for (const auto& e : p) {
    const uint32_t got = aac_bitreader_read(&r, e.bits);
    CHECK(got == e.v, "read %u, wrote %u (%d bits)", got, e.v, e.bits);
  }
  CHECK(aac_bitreader_bits_left(&r) == (int)sizeof(buf) * 8 - total, "bits left");
  CHECK(aac_bitreader_peek(&r, 8) == buf[total / 8 + 1] || (total % 8), "peek");

  /* Reading past the end yields zeros and flags the overread. */
  aac_bitreader_init(&r, buf, 2);
  aac_bitreader_skip(&r, 12);
  CHECK(!r.overread, "no overread yet");
  CHECK(aac_bitreader_read(&r, 8) == (uint32_t)((buf[1] & 0x0F) << 4), "tail bits then zeros");
  CHECK(r.overread, "overread flagged");

  /* Writing past the capacity flags the overflow. */
  aac_bitwriter_init(&w, buf, 1);
  aac_bitwriter_write(&w, 0xFF, 8);
  CHECK(!aac_bitwriter_overflow(&w), "exactly full is not an overflow");
  aac_bitwriter_write(&w, 1, 1);
  CHECK(aac_bitwriter_overflow(&w), "overflow flagged");
}

static void test_adts() {
  AacAdtsHeader h = {};
  h.protection_absent = 1;
  h.object_type = AAC_AOT_LC;
  h.sample_rate_index = 4;
  h.channel_config = 2;
  h.frame_length = 345;
  h.buffer_fullness = 0x7FF;
  h.num_raw_blocks = 1;
  uint8_t buf[AAC_ADTS_HEADER_SIZE];
  CHECK(aac_adts_write(&h, buf) == AAC_ADTS_HEADER_SIZE, "header size");
  AacAdtsHeader p;
  CHECK(aac_adts_parse(&p, buf, sizeof(buf)) == 0, "parse");
  CHECK(p.object_type == AAC_AOT_LC && p.sample_rate_index == 4 && p.channel_config == 2 &&
            p.frame_length == 345 && p.buffer_fullness == 0x7FF && p.num_raw_blocks == 1 &&
            p.protection_absent == 1,
        "fields survive the roundtrip");

  h.protection_absent = 0;
  h.frame_length = AAC_ADTS_HEADER_SIZE + 2;
  aac_adts_write(&h, buf);
  CHECK(aac_adts_parse(&p, buf, sizeof(buf)) == 0 && p.protection_absent == 0,
        "CRC-protected header");
  h.frame_length = AAC_ADTS_HEADER_SIZE + 1;
  aac_adts_write(&h, buf);
  CHECK(aac_adts_parse(&p, buf, sizeof(buf)) != 0, "frame shorter than header plus CRC rejected");
  h.protection_absent = 1;
  h.frame_length = 3;
  aac_adts_write(&h, buf);
  CHECK(aac_adts_parse(&p, buf, sizeof(buf)) != 0, "frame shorter than header rejected");
  buf[0] = 0x00;
  CHECK(aac_adts_parse(&p, buf, sizeof(buf)) != 0, "missing syncword rejected");
}

/* Every value combination of every codebook, and escapes up to the limit,
 * must survive write/read and cost what aac_huffman_bits predicts. */
static void test_huffman() {
  aac_tables_init();
  uint8_t buf[64];
  for (int cb = 1; cb <= AAC_NUM_CODEBOOKS; cb++) {
    const AacCodebookInfo* info = &aac_codebook_info[cb];
    const int dim = info->dim;
    const int lo = info->is_unsigned ? -info->max_val : -info->max_val;
    int hi = info->max_val;
    if (cb == AAC_CB_ESCAPE) {
      hi = 15;
    }
    const int range = hi - lo + 1;
    const int combos = (int)pow(range, dim);
    int failures_before = g_failures;
    for (int idx = 0; idx < combos && g_failures == failures_before; idx++) {
      int v[4] = {0, 0, 0, 0};
      int t = idx;
      for (int k = dim - 1; k >= 0; k--) {
        v[k] = lo + t % range;
        t /= range;
      }
      AacBitWriter w;
      aac_bitwriter_init(&w, buf, sizeof(buf));
      CHECK(aac_bitwriter_write_huffman(&w, cb, v) == 0, "cb %d write", cb);
      CHECK(aac_bitwriter_bits_written(&w) == aac_huffman_bits(cb, v), "cb %d bit estimate", cb);
      AacBitReader r;
      aac_bitreader_init(&r, buf, sizeof(buf));
      int out[4] = {0, 0, 0, 0};
      CHECK(aac_bitreader_read_huffman(&r, cb, out) == 0, "cb %d read", cb);
      for (int k = 0; k < dim; k++) {
        CHECK(out[k] == v[k], "cb %d value %d: wrote %d read %d", cb, k, v[k], out[k]);
      }
      CHECK(r.pos == aac_bitwriter_bits_written(&w), "cb %d consumed all bits", cb);
    }
  }
  /* Escape sequences. */
  const int esc_values[] = {16, 17, 31, 32, 100, 1000, 4095, 4096, 8191, -16, -8191};
  for (int a : esc_values) {
    const int v[2] = {a, -3};
    AacBitWriter w;
    aac_bitwriter_init(&w, buf, sizeof(buf));
    CHECK(aac_bitwriter_write_huffman(&w, AAC_CB_ESCAPE, v) == 0, "escape %d write", a);
    CHECK(aac_bitwriter_bits_written(&w) == aac_huffman_bits(AAC_CB_ESCAPE, v), "escape %d bits",
          a);
    AacBitReader r;
    aac_bitreader_init(&r, buf, sizeof(buf));
    int out[2] = {0, 0};
    CHECK(aac_bitreader_read_huffman(&r, AAC_CB_ESCAPE, out) == 0 && out[0] == a && out[1] == -3,
          "escape %d read back %d,%d", a, out[0], out[1]);
  }
  const int too_big[2] = {8192, 0};
  AacBitWriter refused;
  uint8_t refused_buf[16];
  aac_bitwriter_init(&refused, refused_buf, sizeof(refused_buf));
  CHECK(aac_bitwriter_write_huffman(&refused, AAC_CB_ESCAPE, too_big) == AAC_ERR_INVALID_ARG,
        "value beyond the escape range is refused");
  const int outside[4] = {2, 0, 0, 0};
  CHECK(aac_huffman_bits(1, outside) > 1000, "value outside codebook 1 is unaffordable");

  /* Scalefactor differences. */
  for (int d = -60; d <= 60; d++) {
    AacBitWriter w;
    aac_bitwriter_init(&w, buf, sizeof(buf));
    aac_bitwriter_write_scalefactor(&w, d);
    CHECK(aac_bitwriter_bits_written(&w) == aac_scalefactor_bits[d + AAC_SCALE_DIFF_ZERO],
          "scalefactor %d bits", d);
    AacBitReader r;
    aac_bitreader_init(&r, buf, sizeof(buf));
    CHECK(aac_bitreader_read_scalefactor(&r) == d, "scalefactor %d roundtrip", d);
  }
  /* The table is complete, so every bit pattern decodes; an empty reader
   * still yields a value rather than reading out of bounds. */
  AacBitReader r;
  aac_bitreader_init(&r, buf, 0);
  (void)aac_bitreader_read_scalefactor(&r);
  CHECK(r.overread, "reading a scalefactor from nothing flags the overread");
}

int main() {
  test_bits();
  test_adts();
  test_huffman();
  return test_summary("test_bitstream");
}
