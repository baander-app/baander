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

  /* A bit string of whole bytes and a tail, placed at an odd bit
   * position, reads back bit for bit. */
  const uint8_t bits[3] = {0xA5, 0x3C, 0xE0}; /* 19 bits: the last byte's top three */
  aac_bitwriter_init(&w, buf, sizeof(buf));
  aac_bitwriter_write(&w, 5, 3);
  aac_bitwriter_write_bits(&w, bits, 19);
  aac_bitwriter_write(&w, 1, 1);
  CHECK(aac_bitwriter_bits_written(&w) == 23, "write_bits advances by the bit count");
  aac_bitreader_init(&r, buf, sizeof(buf));
  CHECK(aac_bitreader_read(&r, 3) == 5 && aac_bitreader_read(&r, 8) == 0xA5 &&
            aac_bitreader_read(&r, 8) == 0x3C && aac_bitreader_read(&r, 3) == 7 &&
            aac_bitreader_read(&r, 1) == 1,
        "write_bits keeps the bit order across byte borders");
  aac_bitwriter_init(&w, buf, sizeof(buf));
  aac_bitwriter_write_bits(&w, bits, 0);
  CHECK(aac_bitwriter_bits_written(&w) == 0, "write_bits of nothing writes nothing");

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

/* The inline bit counter and the table-driven reader against the writer:
 * for every codeword of every spectral book, with every sign pattern and
 * a range of escape magnitudes for book 11, the counted bits equal the
 * written bits and the reader returns the values; values outside a book
 * do not fit.  The scalefactor codewords go through the same reader. */
static void test_huffman_counts_and_lookup() {
  uint8_t buf[64];
  for (int cb = 1; cb <= AAC_NUM_CODEBOOKS; cb++) {
    const AacCodebookInfo* info = &aac_codebook_info[cb];
    const int dim = info->dim;
    const int mod = info->is_unsigned ? info->max_val + 1 : 2 * info->max_val + 1;
    int checked = 0;
    for (int idx = 0; idx < aac_huff_count[cb]; idx++) {
      int mags[4] = {0, 0, 0, 0};
      int t = idx;
      for (int k = dim - 1; k >= 0; k--) {
        mags[k] = t % mod - (info->is_unsigned ? 0 : info->max_val);
        t /= mod;
      }
      const int patterns = info->is_unsigned ? 1 << dim : 1;
      /* escape magnitudes to put in place of a 16 of book 11 */
      static const int kEscapes[] = {16, 17, 31, 32, 100, 1023, 1024, 8191};
      const int escapes = cb == AAC_CB_ESCAPE ? (int)(sizeof(kEscapes) / sizeof(kEscapes[0])) : 1;
      for (int pattern = 0; pattern < patterns; pattern++) {
        for (int e = 0; e < escapes; e++) {
          int v[4];
          for (int k = 0; k < dim; k++) {
            int mag = mags[k];
            if (cb == AAC_CB_ESCAPE && mag == 16) {
              mag = kEscapes[e];
            }
            v[k] = info->is_unsigned && ((pattern >> k) & 1) ? -mag : mag;
          }
          AacBitWriter w;
          aac_bitwriter_init(&w, buf, sizeof(buf));
          CHECK(aac_bitwriter_write_huffman(&w, cb, v) == 0, "book %d index %d writes", cb, idx);
          const int written = aac_bitwriter_bits_written(&w);
          CHECK(aac_huffman_bits(cb, v) == written, "book %d index %d: counted %d, wrote %d", cb,
                idx, aac_huffman_bits(cb, v), written);
          AacBitReader r;
          aac_bitreader_init(&r, buf, sizeof(buf));
          int out[4] = {0, 0, 0, 0};
          CHECK(aac_bitreader_read_huffman(&r, cb, out) == 0, "book %d index %d reads", cb, idx);
          CHECK(r.pos == written, "book %d index %d: read %d bits of %d", cb, idx, r.pos, written);
          bool same = true;
          for (int k = 0; k < dim; k++) {
            same = same && out[k] == v[k];
          }
          CHECK(same, "book %d index %d: values differ after the roundtrip", cb, idx);
          checked++;
        }
      }
    }
    printf("book %d: %d codewords checked\n", cb, checked);
    /* One step outside the range in each position does not fit. */
    for (int k = 0; k < dim; k++) {
      int v[4] = {0, 0, 0, 0};
      v[k] = cb == AAC_CB_ESCAPE ? AAC_ESC_MAX_MAG + 1 : info->max_val + 1;
      CHECK(aac_huffman_bits(cb, v) == AAC_HUFF_NO_FIT, "book %d: %d does not fit", cb, v[k]);
      v[k] = -v[k];
      CHECK(aac_huffman_bits(cb, v) == AAC_HUFF_NO_FIT, "book %d: %d does not fit", cb, v[k]);
    }
  }
  for (int diff = -60; diff <= 60; diff++) {
    AacBitWriter w;
    aac_bitwriter_init(&w, buf, sizeof(buf));
    aac_bitwriter_write_scalefactor(&w, diff);
    AacBitReader r;
    aac_bitreader_init(&r, buf, sizeof(buf));
    CHECK(aac_bitreader_read_scalefactor(&r) == diff, "scalefactor difference %d", diff);
    CHECK(r.pos == aac_bitwriter_bits_written(&w), "scalefactor difference %d: length", diff);
  }
  /* Multi-bit writes land where bit-by-bit writes would, across byte
   * boundaries and for a field wider than 32 bits. */
  {
    AacBitWriter w;
    aac_bitwriter_init(&w, buf, sizeof(buf));
    aac_bitwriter_write(&w, 0x5, 3);
    aac_bitwriter_write(&w, 0xABCDE, 20);
    aac_bitwriter_write(&w, 0xFFFFFFFFu, 36);
    aac_bitwriter_write(&w, 0x1, 1);
    AacBitReader r;
    aac_bitreader_init(&r, buf, sizeof(buf));
    CHECK(aac_bitreader_read(&r, 3) == 0x5, "3-bit field");
    CHECK(aac_bitreader_read(&r, 20) == 0xABCDE, "20-bit field");
    CHECK(aac_bitreader_read(&r, 4) == 0, "leading zero bits of a 36-bit field");
    CHECK(aac_bitreader_read(&r, 32) == 0xFFFFFFFFu, "36-bit field");
    CHECK(aac_bitreader_read(&r, 1) == 1, "1-bit field");
    CHECK(aac_bitwriter_bits_written(&w) == 60, "60 bits written");
  }
}

int main() {
  test_bits();
  test_adts();
  test_huffman();
  test_huffman_counts_and_lookup();
  return test_summary("test_bitstream");
}
