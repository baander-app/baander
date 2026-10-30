#ifndef BAANDER_AAC_BITSTREAM_H
#define BAANDER_AAC_BITSTREAM_H

#include <cstddef>
#include <cstdint>

#include "aac.h"
#include "aac_tables.h"

#ifdef __cplusplus
extern "C" {
#endif

/* MSB-first bit reader.  Reading past the end yields zero bits and sets
 * `overread`; callers check it once per syntax element instead of on every
 * read. */
using AacBitReader = struct AacBitReader_ {
  const uint8_t* data;
  int size; /* bytes */
  int pos;  /* bit position */
  int overread;
};

void aac_bitreader_init(AacBitReader* r, const uint8_t* data, int size);
int aac_bitreader_bits_left(const AacBitReader* r);
/* nbits in [0, 32]. */
uint32_t aac_bitreader_read(AacBitReader* r, int nbits);
uint32_t aac_bitreader_peek(const AacBitReader* r, int nbits);
void aac_bitreader_skip(AacBitReader* r, int nbits);
void aac_bitreader_byte_align(AacBitReader* r);
/* Decodes one spectral codeword of book 1..11 into out[0..dim-1], including
 * the sign bits of unsigned books and the escape sequences of book 11.
 * Returns 0 or AAC_ERR_DECODE. */
int aac_bitreader_read_huffman(AacBitReader* r, int codebook, int* out);
/* Bit-serial walk through the decoding tree of a spectral codebook 1..11,
 * for readers that take the bits from elsewhere (the reordered spectral
 * data): `node` starts at 0 and each step with the next bit returns the
 * next node (> 0), a leaf as -(index + 1), or 0 for a codeword that is
 * not in the table.  aac_huffman_leaf gives the dim values of a leaf: the
 * magnitudes of an unsigned book, the signed values of a signed one. */
int aac_huffman_step(int codebook, int node, int bit);
const int8_t* aac_huffman_leaf(int codebook, int index);
/* Returns the scalefactor DPCM difference (-60..60), or AAC_SF_INVALID on an
 * invalid codeword. */
int aac_bitreader_read_scalefactor(AacBitReader* r);
#define AAC_SF_INVALID (-1000)

using AacBitWriter = struct AacBitWriter_ {
  uint8_t* data;
  int capacity;
  int byte_pos;
  int bit_pos;
  int overflow;
};

void aac_bitwriter_init(AacBitWriter* w, uint8_t* data, int capacity);
void aac_bitwriter_write(AacBitWriter* w, uint32_t value, int nbits);
/* Writes `len` whole bytes at the current bit position. */
void aac_bitwriter_write_bytes(AacBitWriter* w, const uint8_t* data, int len);
/* Writes the first `nbits` bits of `data`, most significant bit first. */
void aac_bitwriter_write_bits(AacBitWriter* w, const uint8_t* data, int nbits);
int aac_bitwriter_overflow(const AacBitWriter* w);
/* Writes one spectral codeword for the dim values in v, with sign bits and
 * escape sequences.  Returns 0, or AAC_ERR_INVALID_ARG when a value does not
 * fit the codebook (nothing is written then). */
int aac_bitwriter_write_huffman(AacBitWriter* w, int codebook, const int* v);
/* Bits aac_bitwriter_write_huffman would write, or AAC_HUFF_NO_FIT when
 * the values do not fit the codebook.  The encoder's rate loop counts the
 * bits of every band for every applicable book many times per frame, so
 * the function is inline and specialised per book: the codebook index is
 * formed directly from the values, the sign bits come from the cost table
 * and only the escape words of book 11 need a computation. */
#define AAC_HUFF_NO_FIT (1 << 20)

/* Codebook with escape-sequence support (ISO/IEC 14496-3 4.6.3.3): unsigned
 * book 11 codes magnitudes 0..16 plus per-coefficient sign bits; magnitude 16
 * is ESC and carries an escape extension, so quantized values up to 8191 are
 * representable. */
#define AAC_CB_ESCAPE 11
#define AAC_ESC_MAX_MAG 8191
#define AAC_CB_NOISE 13
#define AAC_CB_INTENSITY2 14
#define AAC_CB_INTENSITY 15

static inline int aac_huffman_abs_(int v) { return v < 0 ? -v : v; }

/* Bits of the escape word of magnitude mag > 15: (n - 4) ones, a zero and
 * n bits, with 2^n <= mag < 2^(n+1). */
static inline int aac_huffman_escape_bits_(int mag) {
  int n = 4;
  while (mag >= (1 << (n + 1))) {
    n++;
  }
  return 2 * n - 3;
}

static inline int aac_huffman_bits(int codebook, const int* v) {
  switch (codebook) {
    case 1:
    case 2: {
      const unsigned a = (unsigned)(v[0] + 1), b = (unsigned)(v[1] + 1);
      const unsigned c = (unsigned)(v[2] + 1), d = (unsigned)(v[3] + 1);
      if (a > 2u || b > 2u || c > 2u || d > 2u) {
        return AAC_HUFF_NO_FIT;
      }
      return aac_huff_cost[codebook][27u * a + 9u * b + 3u * c + d];
    }
    case 3:
    case 4: {
      const unsigned a = (unsigned)aac_huffman_abs_(v[0]), b = (unsigned)aac_huffman_abs_(v[1]);
      const unsigned c = (unsigned)aac_huffman_abs_(v[2]), d = (unsigned)aac_huffman_abs_(v[3]);
      if (a > 2u || b > 2u || c > 2u || d > 2u) {
        return AAC_HUFF_NO_FIT;
      }
      return aac_huff_cost[codebook][27u * a + 9u * b + 3u * c + d];
    }
    case 5:
    case 6: {
      const unsigned a = (unsigned)(v[0] + 4), b = (unsigned)(v[1] + 4);
      if (a > 8u || b > 8u) {
        return AAC_HUFF_NO_FIT;
      }
      return aac_huff_cost[codebook][9u * a + b];
    }
    case 7:
    case 8: {
      const unsigned a = (unsigned)aac_huffman_abs_(v[0]), b = (unsigned)aac_huffman_abs_(v[1]);
      if (a > 7u || b > 7u) {
        return AAC_HUFF_NO_FIT;
      }
      return aac_huff_cost[codebook][8u * a + b];
    }
    case 9:
    case 10: {
      const unsigned a = (unsigned)aac_huffman_abs_(v[0]), b = (unsigned)aac_huffman_abs_(v[1]);
      if (a > 12u || b > 12u) {
        return AAC_HUFF_NO_FIT;
      }
      return aac_huff_cost[codebook][13u * a + b];
    }
    case 11: {
      const int a = aac_huffman_abs_(v[0]), b = aac_huffman_abs_(v[1]);
      if (a > AAC_ESC_MAX_MAG || b > AAC_ESC_MAX_MAG) {
        return AAC_HUFF_NO_FIT;
      }
      int bits = aac_huff_cost[11][17 * (a > 16 ? 16 : a) + (b > 16 ? 16 : b)];
      if (a > 15) {
        bits += aac_huffman_escape_bits_(a);
      }
      if (b > 15) {
        bits += aac_huffman_escape_bits_(b);
      }
      return bits;
    }
    default:
      return AAC_HUFF_NO_FIT;
  }
}
void aac_bitwriter_write_scalefactor(AacBitWriter* w, int diff);
void aac_bitwriter_byte_align(AacBitWriter* w);
int aac_bitwriter_bits_written(const AacBitWriter* w);
int aac_bitwriter_bytes_written(const AacBitWriter* w);

#define AAC_ADTS_HEADER_SIZE 7

using AacAdtsHeader = struct AacAdtsHeader_ {
  int id, layer, protection_absent, sample_rate_index, private_bit;
  int channel_config, original_copy, home, copyright_id_bit, copyright_id_start;
  int object_type; /* ADTS profile + 1 */
  int frame_length, buffer_fullness, num_raw_blocks;
};

int aac_adts_parse(AacAdtsHeader* hdr, const uint8_t* data, int size);
int aac_adts_write(const AacAdtsHeader* hdr, uint8_t* out);

/* ── ADTS CRC (ISO/IEC 13818-7 6.2, ISO/IEC 14496-3 1.A.3) ───────── */

/* CRC-16 with the generator polynomial x^16 + x^15 + x^2 + 1 (0x8005),
 * initial value 0xFFFF, bits fed most significant first, no final
 * inversion.  Continues `crc` over `nbits` bits of `data` starting at bit
 * `bit_offset`, or over `nbits` zero bits. */
#define AAC_CRC16_INIT 0xFFFFu
uint16_t aac_crc16_bits(uint16_t crc, const uint8_t* data, int bit_offset, int nbits);
uint16_t aac_crc16_zero_bits(uint16_t crc, int nbits);

/* One syntactic element of a raw data block as the ADTS CRC sees it: bit
 * offsets, from the start of the buffer the block lives in, of the
 * element_instance_tag (just after the id_syn_ele), of the second
 * channel's global_gain in a channel pair (-1 otherwise) and of the
 * element's end. */
using AacCrcRegion = struct AacCrcRegion_ {
  int type; /* AacElementType */
  int start;
  int second;
  int end;
};
#define AAC_CRC_MAX_REGIONS 32

/* The crc_check of an ADTS frame: the CRC runs over `header_bits` bits of
 * the header (the fixed and variable headers, 56 bits, plus the
 * raw_data_block_position words of a frame with several blocks), then over
 * the covered part of each element of the raw data block: the first 192
 * bits of a single channel, LFE or coupling element from its
 * element_instance_tag and, for a channel pair, 192 bits from its tag and
 * 128 bits from the second channel's global_gain, each padded with zero
 * bits when the element is shorter; every bit of a program config or data
 * stream element; nothing of a fill element.  A frame with several raw
 * data blocks checks the header alone and then each block alone (with
 * header_bits 0). */
uint16_t aac_adts_crc(const uint8_t* header, int header_bits, const uint8_t* block,
                      const AacCrcRegion* regions, int num_regions);

using AacElementType = enum AacElementType_ {
  AAC_ELEM_SCE = 0,
  AAC_ELEM_CPE = 1,
  AAC_ELEM_CCE = 2,
  AAC_ELEM_LFE = 3,
  AAC_ELEM_DSE = 4,
  AAC_ELEM_PCE = 5,
  AAC_ELEM_FIL = 6,
  AAC_ELEM_END = 7,
};

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_BITSTREAM_H */
