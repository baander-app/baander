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
int aac_bitreader_read_huffman(AacBitReader* r, int codebook, int* x, int* y);

using AacBitWriter = struct AacBitWriter_ {
  uint8_t* data;
  int capacity;
  int byte_pos;
  int bit_pos;
};

void aac_bitwriter_init(AacBitWriter* w, uint8_t* data, int capacity);
void aac_bitwriter_write(AacBitWriter* w, uint32_t value, int nbits);
void aac_bitwriter_write_signed(AacBitWriter* w, int32_t value, int nbits);
int aac_bitwriter_write_huffman(AacBitWriter* w, int codebook, int x, int y);
void aac_bitwriter_byte_align(AacBitWriter* w);
int aac_bitwriter_bytes_written(const AacBitWriter* w);

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
