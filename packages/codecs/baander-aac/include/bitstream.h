#ifndef BAANDER_AAC_BITSTREAM_H
#define BAANDER_AAC_BITSTREAM_H

#include <cstddef>
#include <cstdint>

#include "aac.h"

#ifdef __cplusplus
extern "C" {
#endif

using AacBitReader = struct AacBitReader_ {
  const uint8_t* data;
  int size;
  int byte_pos;
  int bit_pos;
};

void aac_bitreader_init(AacBitReader* r, const uint8_t* data, int size);
int aac_bitreader_bits_left(const AacBitReader* r);
uint32_t aac_bitreader_read(AacBitReader* r, int nbits);
int32_t aac_bitreader_read_signed(AacBitReader* r, int nbits);
uint32_t aac_bitreader_peek(AacBitReader* r, int nbits);
void aac_bitreader_skip(AacBitReader* r, int nbits);
void aac_bitreader_byte_align(AacBitReader* r);
int aac_bitreader_read_huffman(AacBitReader* r, int codebook, int* out);
int aac_bitreader_read_scalefactor(AacBitReader* r);
int aac_bitreader_read_sections(AacBitReader* r, int is_short, int max_sfb,
                                int* out_codebooks, int max_bands);

using AacBitWriter = struct AacBitWriter_ {
  uint8_t* data;
  int capacity;
  int byte_pos;
  int bit_pos;
  int overflow;
};

void aac_bitwriter_init(AacBitWriter* w, uint8_t* data, int capacity);
void aac_bitwriter_write(AacBitWriter* w, uint32_t value, int nbits);
int aac_bitwriter_overflow(const AacBitWriter* w);
void aac_bitwriter_write_signed(AacBitWriter* w, int32_t value, int nbits);
int aac_bitwriter_write_huffman(AacBitWriter* w, int codebook, const int* v);
void aac_bitwriter_write_scalefactor(AacBitWriter* w, int diff);
void aac_bitwriter_write_sections(AacBitWriter* w, int is_short, const int* codebooks, int nb);
void aac_bitwriter_byte_align(AacBitWriter* w);
int aac_bitwriter_bytes_written(const AacBitWriter* w);

/* Codebook with escape-sequence support (ISO 14496-3 §4.6.3.3): unsigned
 * book 11 codes magnitudes 0..16 plus per-coefficient sign bits; magnitude 16
 * is ESC and carries an escape extension, so quantized values are not limited
 * to the table range. */
#define AAC_CB_ESCAPE 11
/* Largest magnitude the escape book accepts (largest escape word used). */
#define AAC_ESC_MAX_MAG 8191
/* Unsigned magnitude books: the VLC table codes |values| and every nonzero
 * coefficient carries a separate sign bit after the codeword (ISO 14496-3
 * §4.6.3.3).  Signed-in-table books (1, 2, 5, 6) encode the sign in the
 * codeword itself and have no trailing sign bits. */
#define AAC_CB_IS_SIGN_CODED(cb) \
  ((cb) == 3 || (cb) == 4 || (cb) == 7 || (cb) == 8 || (cb) == 9 || (cb) == 10 || (cb) == AAC_CB_ESCAPE)

#define AAC_ADTS_HEADER_SIZE 7

using AacAdtsHeader = struct AacAdtsHeader_ {
  int id, layer, protection_absent, sample_rate_index, private_bit;
  int channel_config, original_copy, home, copyright_id_bit, copyright_id_start;
  AacObjectType profile;
  int frame_length, buffer_fullness, num_aac_frames;
};

int aac_adts_parse(AacAdtsHeader* hdr, const uint8_t* data, int size);
int aac_adts_write(const AacAdtsHeader* hdr, uint8_t* out);

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
