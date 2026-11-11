/* Internal declarations of the lossless mode (RFC 9639), shared by
 * flac_format.cpp, flac_dec.cpp and flac_api.cpp: the checksums, the MD5
 * of RFC 1321, the coded numbers, STREAMINFO and frame headers, the
 * decoder behind the public API, and a bit reader and writer with a
 * 64-bit cache.  Not part of the public API.
 *
 * The bit reader and writer are separate from the ones in bitstream.h,
 * which were built for Huffman codes: Rice codes are a unary run
 * followed by a few plain bits, and the unary run is read with one
 * count-leading-zeros over the cache instead of bit by bit. */
#ifndef BAANDER_AAC_FLAC_INTERNAL_H
#define BAANDER_AAC_FLAC_INTERNAL_H

#include <cstddef>
#include <cstdint>
#include <cstring>

#if defined(_MSC_VER)
#include <intrin.h>
#endif

#include "flac.h"

/* ── format constants ───────────────────────────────────────────── */

#define FLAC_MARKER_BYTES 4       /* "fLaC" */
#define FLAC_BLOCK_HEADER_BYTES 4 /* last flag and type, 24-bit length */
#define FLAC_SEEK_POINT_BYTES 18
#define FLAC_MAX_FRAME_HEADER_BYTES 16 /* sync to CRC-8 with every optional field */
#define FLAC_MAX_FIXED_ORDER 4
#define FLAC_MAX_LPC_ORDER 32
#define FLAC_MAX_PARTITION_ORDER 15

enum FlacBlockType {
  FLAC_BLOCK_STREAMINFO = 0,
  FLAC_BLOCK_PADDING = 1,
  FLAC_BLOCK_APPLICATION = 2,
  FLAC_BLOCK_SEEKTABLE = 3,
  FLAC_BLOCK_VORBIS_COMMENT = 4,
  FLAC_BLOCK_CUESHEET = 5,
  FLAC_BLOCK_PICTURE = 6,
  FLAC_BLOCK_FORBIDDEN = 127, /* would read as a frame sync code */
};

/* How the channels of a frame are stored (RFC 9639 section 4.2). */
enum FlacChannelAssignment {
  FLAC_ASSIGN_INDEPENDENT = 0,
  FLAC_ASSIGN_LEFT_SIDE = 1,
  FLAC_ASSIGN_SIDE_RIGHT = 2,
  FLAC_ASSIGN_MID_SIDE = 3,
};

/* ── checksums ──────────────────────────────────────────────────── */

/* CRC-8 of a frame header: polynomial x^8 + x^2 + x + 1, initial value
 * 0, most significant bit first. */
uint8_t flac_crc8(const uint8_t* data, size_t size);
/* CRC-16 of a frame: polynomial x^16 + x^15 + x^2 + 1, initial value 0,
 * most significant bit first.  The update form continues a checksum. */
uint16_t flac_crc16_update(uint16_t crc, const uint8_t* data, size_t size);
static inline uint16_t flac_crc16(const uint8_t* data, size_t size) {
  return flac_crc16_update(0, data, size);
}

/* MD5 (RFC 1321). */
struct FlacMd5 {
  uint32_t state[4];
  uint64_t bytes;     /* message length so far */
  uint8_t buffer[64]; /* the block being filled */
};
void flac_md5_init(FlacMd5* m);
void flac_md5_update(FlacMd5* m, const uint8_t* data, size_t size);
void flac_md5_final(FlacMd5* m, uint8_t digest[16]);
/* Feeds interleaved samples the way STREAMINFO's MD5 is defined: each
 * sign-extended to a whole number of bytes, least significant byte
 * first (RFC 9639 section 8.2). */
void flac_md5_update_pcm(FlacMd5* m, const int32_t* samples, size_t count, int bits_per_sample);

/* ── coded numbers ──────────────────────────────────────────────── */

/* The frame or sample number of a frame header: the UTF-8 scheme
 * extended to 36 bits in 7 bytes (RFC 9639 section 9.1.5).  The writer
 * returns the bytes written (1 to 7; `out` holds 7), or 0 for a value
 * above 36 bits.  The reader returns the bytes consumed, 0 when `size`
 * bytes do not hold the whole number yet, or -1 for an invalid
 * sequence. */
int flac_coded_number_write(uint64_t value, uint8_t* out);
int flac_coded_number_read(const uint8_t* data, size_t size, uint64_t* value);

/* ── STREAMINFO ─────────────────────────────────────────────────── */

/* Reads and writes the 34 bytes of a STREAMINFO block.  The parser
 * returns false for values the format forbids: a block size below 16, a
 * minimum above the maximum, a bit depth below 4.  The writer returns
 * false for a field outside its range. */
bool flac_streaminfo_parse(const uint8_t* data, BaacFlacStreamInfo* info);
bool flac_streaminfo_write(const BaacFlacStreamInfo* info, uint8_t* out);

/* ── frame header ───────────────────────────────────────────────── */

struct FlacFrameHeader {
  bool variable_block_size; /* the blocking strategy: `number` counts samples, else frames */
  int block_size;           /* samples per channel, 1..65535 */
  int sample_rate;          /* Hz; 0: the header leaves it to STREAMINFO */
  int channels;             /* 1..8 */
  int assignment;           /* FlacChannelAssignment */
  int bits_per_sample;      /* 0: the header leaves it to STREAMINFO */
  uint64_t number;          /* frame number (31 bits) or sample number (36 bits) */
  int header_bytes;         /* sync code to CRC-8 */
};

/* Parses a frame header at `data`.  Returns its length, 0 when `size`
 * bytes do not hold the whole header yet, or -1 when the bytes are not
 * a frame header: no sync code, a reserved or forbidden field value, an
 * invalid coded number, or a CRC-8 mismatch. */
int flac_frame_header_parse(const uint8_t* data, size_t size, FlacFrameHeader* h);

/* Writes a frame header with its CRC-8 into `out`
 * (FLAC_MAX_FRAME_HEADER_BYTES) and returns its length, or -1 when a
 * field cannot be expressed: a block size outside 1..65535, a sample
 * rate or bit depth no code exists for (0 for either writes "see
 * STREAMINFO"), a channel count or assignment out of range, a number
 * too large for the blocking strategy. */
int flac_frame_header_write(const FlacFrameHeader* h, uint8_t* out);

/* Whether a frame header can name the sample rate itself, as the
 * streamable subset demands. */
bool flac_sample_rate_is_codable(int sample_rate);

/* ── decoder (flac_dec.cpp) ─────────────────────────────────────── */

/* The decoder behind the public functions of flac.h, which check their
 * arguments and turn an allocation failure into AAC_ERR_MEMORY before
 * and after these.  Functions that size buffers may throw
 * std::bad_alloc. */
BaacFlacDecoder* flac_decoder_create();
void flac_decoder_destroy(BaacFlacDecoder* d);
void flac_decoder_reset(BaacFlacDecoder* d);
int flac_decoder_set_streaminfo(BaacFlacDecoder* d, const uint8_t* data);
int flac_decoder_set_md5_check(BaacFlacDecoder* d, bool enabled);
int flac_decoder_feed(BaacFlacDecoder* d, const uint8_t* data, int size);
int flac_decoder_decode_next(BaacFlacDecoder* d, int32_t* pcm, int pcm_size);
int flac_decoder_pending_bytes(const BaacFlacDecoder* d);
int flac_decoder_stream_info(const BaacFlacDecoder* d, BaacFlacStreamInfo* info);
int flac_decoder_last_frame_info(const BaacFlacDecoder* d, BaacFlacFrameInfo* info);
int flac_decoder_lost_frames(const BaacFlacDecoder* d);
int flac_decoder_seek_points(const BaacFlacDecoder* d, const BaacFlacSeekPoint** points);
int64_t flac_decoder_first_frame_offset(const BaacFlacDecoder* d);
int flac_decoder_seek_reset(BaacFlacDecoder* d);
int flac_decoder_end_input(BaacFlacDecoder* d);
int flac_decoder_finish(BaacFlacDecoder* d, BaacFlacMd5Verdict* verdict);

/* ── bit reader ─────────────────────────────────────────────────── */

/* Number of leading zero bits of a nonzero value. */
static inline int flac_clz64(uint64_t x) {
#if defined(_MSC_VER) && !defined(__clang__)
  unsigned long index;
#if defined(_M_X64) || defined(_M_ARM64)
  _BitScanReverse64(&index, x);
  return 63 - (int)index;
#else
  if (_BitScanReverse(&index, (unsigned long)(x >> 32))) {
    return 31 - (int)index;
  }
  _BitScanReverse(&index, (unsigned long)x);
  return 63 - (int)index;
#endif
#else
  return __builtin_clzll(x);
#endif
}

static inline uint64_t flac_load_be64(const uint8_t* p) {
  return ((uint64_t)p[0] << 56) | ((uint64_t)p[1] << 48) | ((uint64_t)p[2] << 40) |
         ((uint64_t)p[3] << 32) | ((uint64_t)p[4] << 24) | ((uint64_t)p[5] << 16) |
         ((uint64_t)p[6] << 8) | (uint64_t)p[7];
}

/* Reads bits most significant first.  `cache` holds the unread bits with
 * the next one in bit 63; `bits` of them are counted as available, and
 * whatever lies below them is either zero or a preview of the bytes at
 * `next`, which a later refill supplies again.  Reading past the end
 * yields zeros and sets `overread`; nothing is ever read outside
 * [data, end). */
struct FlacBitReader {
  const uint8_t* data;
  const uint8_t* next; /* first byte not counted in `bits` yet */
  const uint8_t* end;
  uint64_t cache;
  int bits; /* 0..63 */
  bool overread;
};

static inline void flac_bitreader_init(FlacBitReader* r, const uint8_t* data, size_t size) {
  r->data = data;
  r->next = data;
  r->end = data + size;
  r->cache = 0;
  r->bits = 0;
  r->overread = false;
}

/* Tops the cache up to at least 56 bits while input remains. */
static inline void flac_bitreader_refill(FlacBitReader* r) {
  if (r->end - r->next >= 8) {
    r->cache |= flac_load_be64(r->next) >> r->bits;
    r->next += (63 - r->bits) >> 3;
    r->bits |= 56;
    return;
  }
  while (r->bits <= 55 && r->next < r->end) {
    r->cache |= (uint64_t)*r->next++ << (56 - r->bits);
    r->bits += 8;
  }
}

/* Moves to `bit` bits behind the start of the input, a position inside
 * it. */
static inline void flac_bitreader_seek(FlacBitReader* r, size_t bit) {
  r->next = r->data + bit / 8;
  r->cache = 0;
  r->bits = 0;
  r->overread = false;
  const int odd = (int)(bit & 7);
  if (odd) {
    r->cache = (uint64_t)*r->next++ << (56 + odd);
    r->bits = 8 - odd;
  }
}

/* Marks the reader as exhausted: every further read yields zeros. */
static inline void flac_bitreader_fail(FlacBitReader* r) {
  r->overread = true;
  r->cache = 0;
  r->bits = 0;
}

/* An unsigned value of 0 to 32 bits. */
static inline uint32_t flac_bitreader_read(FlacBitReader* r, int n) {
  if (n == 0) {
    return 0;
  }
  if (r->bits < n) {
    flac_bitreader_refill(r);
    if (r->bits < n) {
      /* The tail of the input: its bits, then zeros. */
      const uint64_t have = r->bits ? r->cache >> (64 - r->bits) << (64 - r->bits) : 0;
      flac_bitreader_fail(r);
      return (uint32_t)(have >> (64 - n));
    }
  }
  const uint32_t v = (uint32_t)(r->cache >> (64 - n));
  r->cache <<= n;
  r->bits -= n;
  return v;
}

/* A two's complement value of 1 to 33 bits (33 for the side channel of
 * 32-bit audio). */
static inline int64_t flac_bitreader_read_signed(FlacBitReader* r, int n) {
  uint64_t v;
  if (n > 32) {
    v = (uint64_t)flac_bitreader_read(r, n - 32) << 32;
    v |= flac_bitreader_read(r, 32);
  } else {
    v = flac_bitreader_read(r, n);
  }
  const uint64_t sign = (uint64_t)1 << (n - 1);
  return (int64_t)((v ^ sign) - sign);
}

/* A unary number: the count of zero bits before the next one bit, which
 * is consumed as well. */
static inline uint32_t flac_bitreader_read_unary(FlacBitReader* r) {
  uint32_t count = 0;
  for (;;) {
    if (r->cache) {
      const int zeros = flac_clz64(r->cache);
      if (zeros < r->bits) {
        r->cache <<= zeros + 1;
        r->bits -= zeros + 1;
        return count + (uint32_t)zeros;
      }
    }
    /* Every available bit is zero: take them all and look further. */
    count += (uint32_t)r->bits;
    r->cache <<= r->bits;
    r->bits = 0;
    flac_bitreader_refill(r);
    if (r->bits == 0) {
      flac_bitreader_fail(r);
      return count;
    }
  }
}

/* Bits consumed since the reader was initialised. */
static inline size_t flac_bitreader_position(const FlacBitReader* r) {
  return (size_t)(r->next - r->data) * 8 - (size_t)r->bits;
}

/* Skips the padding up to the next byte boundary. */
static inline void flac_bitreader_align(FlacBitReader* r) {
  const int pad = r->bits & 7;
  r->cache <<= pad;
  r->bits -= pad;
}

/* ── bit writer ─────────────────────────────────────────────────── */

/* Writes bits most significant first.  Up to 63 bits wait in the low
 * end of `cache`; whole 32-bit words are stored as soon as they are
 * complete.  Writing past the capacity sets `overflow` and stores
 * nothing further. */
struct FlacBitWriter {
  uint8_t* data;
  size_t capacity;
  size_t pos; /* bytes stored */
  uint64_t cache;
  int bits; /* pending in `cache`, 0..31 between calls */
  bool overflow;
};

static inline void flac_bitwriter_init(FlacBitWriter* w, uint8_t* data, size_t capacity) {
  w->data = data;
  w->capacity = capacity;
  w->pos = 0;
  w->cache = 0;
  w->bits = 0;
  w->overflow = false;
}

/* The low `n` bits of `value`, 0 to 32 of them. */
static inline void flac_bitwriter_write(FlacBitWriter* w, uint32_t value, int n) {
  if (n == 0) {
    return;
  }
  const uint64_t mask = n == 32 ? 0xFFFFFFFFu : (((uint64_t)1 << n) - 1);
  w->cache = (w->cache << n) | (value & mask);
  w->bits += n;
  if (w->bits >= 32) {
    const uint32_t word = (uint32_t)(w->cache >> (w->bits - 32));
    w->bits -= 32;
    if (w->capacity - w->pos < 4) {
      w->overflow = true;
      return;
    }
    w->data[w->pos] = (uint8_t)(word >> 24);
    w->data[w->pos + 1] = (uint8_t)(word >> 16);
    w->data[w->pos + 2] = (uint8_t)(word >> 8);
    w->data[w->pos + 3] = (uint8_t)word;
    w->pos += 4;
  }
}

/* A two's complement value in 1 to 33 bits. */
static inline void flac_bitwriter_write_signed(FlacBitWriter* w, int64_t value, int n) {
  if (n > 32) {
    flac_bitwriter_write(w, (uint32_t)((uint64_t)value >> 32), n - 32);
    n = 32;
  }
  flac_bitwriter_write(w, (uint32_t)(uint64_t)value, n);
}

/* `count` zero bits and a one bit. */
static inline void flac_bitwriter_write_unary(FlacBitWriter* w, uint32_t count) {
  while (count >= 32) {
    flac_bitwriter_write(w, 0, 32);
    count -= 32;
  }
  flac_bitwriter_write(w, 1, (int)count + 1);
}

/* Bits written so far. */
static inline size_t flac_bitwriter_bits(const FlacBitWriter* w) {
  return w->pos * 8 + (size_t)w->bits;
}

/* Pads with zero bits to a byte boundary and stores the pending bytes;
 * returns the bytes written in total. */
static inline size_t flac_bitwriter_flush(FlacBitWriter* w) {
  const int pad = (8 - (w->bits & 7)) & 7;
  w->cache <<= pad;
  w->bits += pad;
  while (w->bits > 0) {
    if (w->pos >= w->capacity) {
      w->overflow = true;
      w->bits = 0;
      break;
    }
    w->bits -= 8;
    w->data[w->pos++] = (uint8_t)(w->cache >> w->bits);
  }
  return w->pos;
}

#endif /* BAANDER_AAC_FLAC_INTERNAL_H */
