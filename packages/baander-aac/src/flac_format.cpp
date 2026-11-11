/* The parts of the FLAC format (RFC 9639) the encoder and the decoder
 * share: the frame checksums, the MD5 of the unencoded samples (RFC
 * 1321), the coded frame and sample numbers, STREAMINFO and the frame
 * header. */
#include "flac_internal.h"

/* ── checksums ──────────────────────────────────────────────────── */

namespace {

struct Crc8Table {
  uint8_t v[256];
  constexpr Crc8Table() : v() {
    for (int i = 0; i < 256; i++) {
      uint8_t crc = (uint8_t)i;
      for (int bit = 0; bit < 8; bit++) {
        crc = (uint8_t)((crc & 0x80) ? (crc << 1) ^ 0x07 : crc << 1);
      }
      v[i] = crc;
    }
  }
};

/* Eight tables for the CRC-16, so that a frame is checked eight bytes
 * at a step: v[0][i] is the checksum of the byte i, and v[k][i] that of
 * the byte i followed by k zero bytes, which is v[k - 1][i] taken
 * through one more byte. */
struct Crc16Table {
  uint16_t v[8][256];
  constexpr Crc16Table() : v() {
    for (int i = 0; i < 256; i++) {
      uint16_t crc = (uint16_t)(i << 8);
      for (int bit = 0; bit < 8; bit++) {
        crc = (uint16_t)((crc & 0x8000) ? (crc << 1) ^ 0x8005 : crc << 1);
      }
      v[0][i] = crc;
    }
    for (int k = 1; k < 8; k++) {
      for (int i = 0; i < 256; i++) {
        v[k][i] = (uint16_t)((v[k - 1][i] << 8) ^ v[0][v[k - 1][i] >> 8]);
      }
    }
  }
};

constexpr Crc8Table kCrc8;
constexpr Crc16Table kCrc16;

}  // namespace

uint8_t flac_crc8(const uint8_t* data, size_t size) {
  uint8_t crc = 0;
  for (size_t i = 0; i < size; i++) {
    crc = kCrc8.v[crc ^ data[i]];
  }
  return crc;
}

uint16_t flac_crc16_update(uint16_t crc, const uint8_t* data, size_t size) {
  /* Eight bytes at a step: the checksum so far goes into the first two,
   * and each byte contributes through the table of its distance from
   * the end of the group. */
  while (size >= 8) {
    crc = (uint16_t)(kCrc16.v[7][data[0] ^ (crc >> 8)] ^ kCrc16.v[6][data[1] ^ (crc & 0xFF)] ^
                     kCrc16.v[5][data[2]] ^ kCrc16.v[4][data[3]] ^ kCrc16.v[3][data[4]] ^
                     kCrc16.v[2][data[5]] ^ kCrc16.v[1][data[6]] ^ kCrc16.v[0][data[7]]);
    data += 8;
    size -= 8;
  }
  for (size_t i = 0; i < size; i++) {
    crc = (uint16_t)((crc << 8) ^ kCrc16.v[0][(crc >> 8) ^ data[i]]);
  }
  return crc;
}

/* ── MD5 (RFC 1321) ─────────────────────────────────────────────── */

namespace {

inline uint32_t rotl32(uint32_t x, int n) { return (x << n) | (x >> (32 - n)); }

inline uint32_t load_le32(const uint8_t* p) {
  return (uint32_t)p[0] | ((uint32_t)p[1] << 8) | ((uint32_t)p[2] << 16) | ((uint32_t)p[3] << 24);
}

inline void store_le32(uint8_t* p, uint32_t v) {
  p[0] = (uint8_t)v;
  p[1] = (uint8_t)(v >> 8);
  p[2] = (uint8_t)(v >> 16);
  p[3] = (uint8_t)(v >> 24);
}

/* The auxiliary functions of the four rounds (RFC 1321 section 3.4). */
inline uint32_t md5_f(uint32_t x, uint32_t y, uint32_t z) { return (x & y) | (~x & z); }
inline uint32_t md5_g(uint32_t x, uint32_t y, uint32_t z) { return (x & z) | (y & ~z); }
inline uint32_t md5_h(uint32_t x, uint32_t y, uint32_t z) { return x ^ y ^ z; }
inline uint32_t md5_i(uint32_t x, uint32_t y, uint32_t z) { return y ^ (x | ~z); }

/* One step: w = x + ((w + f(x, y, z) + X[k] + T) <<< s), where T is
 * floor(2^32 * abs(sin(step))) for the steps 1 to 64. */
#define MD5_STEP(f, w, x, y, z, k, s, t) w = x + rotl32(w + f(x, y, z) + m[k] + t, s)

/* One 64-byte block through the four rounds of RFC 1321 section 3.4,
 * sixteen steps each, with the round's function, the word order, the
 * rotations and the constants written out. */
void md5_block(uint32_t state[4], const uint8_t* block) {
  uint32_t m[16];
  for (int i = 0; i < 16; i++) {
    m[i] = load_le32(block + 4 * i);
  }
  uint32_t a = state[0];
  uint32_t b = state[1];
  uint32_t c = state[2];
  uint32_t d = state[3];
  /* Round 1: F, the message words in order. */
  MD5_STEP(md5_f, a, b, c, d, 0, 7, 0xd76aa478);
  MD5_STEP(md5_f, d, a, b, c, 1, 12, 0xe8c7b756);
  MD5_STEP(md5_f, c, d, a, b, 2, 17, 0x242070db);
  MD5_STEP(md5_f, b, c, d, a, 3, 22, 0xc1bdceee);
  MD5_STEP(md5_f, a, b, c, d, 4, 7, 0xf57c0faf);
  MD5_STEP(md5_f, d, a, b, c, 5, 12, 0x4787c62a);
  MD5_STEP(md5_f, c, d, a, b, 6, 17, 0xa8304613);
  MD5_STEP(md5_f, b, c, d, a, 7, 22, 0xfd469501);
  MD5_STEP(md5_f, a, b, c, d, 8, 7, 0x698098d8);
  MD5_STEP(md5_f, d, a, b, c, 9, 12, 0x8b44f7af);
  MD5_STEP(md5_f, c, d, a, b, 10, 17, 0xffff5bb1);
  MD5_STEP(md5_f, b, c, d, a, 11, 22, 0x895cd7be);
  MD5_STEP(md5_f, a, b, c, d, 12, 7, 0x6b901122);
  MD5_STEP(md5_f, d, a, b, c, 13, 12, 0xfd987193);
  MD5_STEP(md5_f, c, d, a, b, 14, 17, 0xa679438e);
  MD5_STEP(md5_f, b, c, d, a, 15, 22, 0x49b40821);
  /* Round 2: G, every fifth word from the second. */
  MD5_STEP(md5_g, a, b, c, d, 1, 5, 0xf61e2562);
  MD5_STEP(md5_g, d, a, b, c, 6, 9, 0xc040b340);
  MD5_STEP(md5_g, c, d, a, b, 11, 14, 0x265e5a51);
  MD5_STEP(md5_g, b, c, d, a, 0, 20, 0xe9b6c7aa);
  MD5_STEP(md5_g, a, b, c, d, 5, 5, 0xd62f105d);
  MD5_STEP(md5_g, d, a, b, c, 10, 9, 0x02441453);
  MD5_STEP(md5_g, c, d, a, b, 15, 14, 0xd8a1e681);
  MD5_STEP(md5_g, b, c, d, a, 4, 20, 0xe7d3fbc8);
  MD5_STEP(md5_g, a, b, c, d, 9, 5, 0x21e1cde6);
  MD5_STEP(md5_g, d, a, b, c, 14, 9, 0xc33707d6);
  MD5_STEP(md5_g, c, d, a, b, 3, 14, 0xf4d50d87);
  MD5_STEP(md5_g, b, c, d, a, 8, 20, 0x455a14ed);
  MD5_STEP(md5_g, a, b, c, d, 13, 5, 0xa9e3e905);
  MD5_STEP(md5_g, d, a, b, c, 2, 9, 0xfcefa3f8);
  MD5_STEP(md5_g, c, d, a, b, 7, 14, 0x676f02d9);
  MD5_STEP(md5_g, b, c, d, a, 12, 20, 0x8d2a4c8a);
  /* Round 3: H, every third word from the sixth. */
  MD5_STEP(md5_h, a, b, c, d, 5, 4, 0xfffa3942);
  MD5_STEP(md5_h, d, a, b, c, 8, 11, 0x8771f681);
  MD5_STEP(md5_h, c, d, a, b, 11, 16, 0x6d9d6122);
  MD5_STEP(md5_h, b, c, d, a, 14, 23, 0xfde5380c);
  MD5_STEP(md5_h, a, b, c, d, 1, 4, 0xa4beea44);
  MD5_STEP(md5_h, d, a, b, c, 4, 11, 0x4bdecfa9);
  MD5_STEP(md5_h, c, d, a, b, 7, 16, 0xf6bb4b60);
  MD5_STEP(md5_h, b, c, d, a, 10, 23, 0xbebfbc70);
  MD5_STEP(md5_h, a, b, c, d, 13, 4, 0x289b7ec6);
  MD5_STEP(md5_h, d, a, b, c, 0, 11, 0xeaa127fa);
  MD5_STEP(md5_h, c, d, a, b, 3, 16, 0xd4ef3085);
  MD5_STEP(md5_h, b, c, d, a, 6, 23, 0x04881d05);
  MD5_STEP(md5_h, a, b, c, d, 9, 4, 0xd9d4d039);
  MD5_STEP(md5_h, d, a, b, c, 12, 11, 0xe6db99e5);
  MD5_STEP(md5_h, c, d, a, b, 15, 16, 0x1fa27cf8);
  MD5_STEP(md5_h, b, c, d, a, 2, 23, 0xc4ac5665);
  /* Round 4: I, every seventh word from the first. */
  MD5_STEP(md5_i, a, b, c, d, 0, 6, 0xf4292244);
  MD5_STEP(md5_i, d, a, b, c, 7, 10, 0x432aff97);
  MD5_STEP(md5_i, c, d, a, b, 14, 15, 0xab9423a7);
  MD5_STEP(md5_i, b, c, d, a, 5, 21, 0xfc93a039);
  MD5_STEP(md5_i, a, b, c, d, 12, 6, 0x655b59c3);
  MD5_STEP(md5_i, d, a, b, c, 3, 10, 0x8f0ccc92);
  MD5_STEP(md5_i, c, d, a, b, 10, 15, 0xffeff47d);
  MD5_STEP(md5_i, b, c, d, a, 1, 21, 0x85845dd1);
  MD5_STEP(md5_i, a, b, c, d, 8, 6, 0x6fa87e4f);
  MD5_STEP(md5_i, d, a, b, c, 15, 10, 0xfe2ce6e0);
  MD5_STEP(md5_i, c, d, a, b, 6, 15, 0xa3014314);
  MD5_STEP(md5_i, b, c, d, a, 13, 21, 0x4e0811a1);
  MD5_STEP(md5_i, a, b, c, d, 4, 6, 0xf7537e82);
  MD5_STEP(md5_i, d, a, b, c, 11, 10, 0xbd3af235);
  MD5_STEP(md5_i, c, d, a, b, 2, 15, 0x2ad7d2bb);
  MD5_STEP(md5_i, b, c, d, a, 9, 21, 0xeb86d391);
  state[0] += a;
  state[1] += b;
  state[2] += c;
  state[3] += d;
}

#undef MD5_STEP

}  // namespace

void flac_md5_init(FlacMd5* m) {
  m->state[0] = 0x67452301;
  m->state[1] = 0xefcdab89;
  m->state[2] = 0x98badcfe;
  m->state[3] = 0x10325476;
  m->bytes = 0;
}

void flac_md5_update(FlacMd5* m, const uint8_t* data, size_t size) {
  size_t fill = (size_t)(m->bytes & 63);
  m->bytes += size;
  if (fill) {
    const size_t take = size < 64 - fill ? size : 64 - fill;
    memcpy(m->buffer + fill, data, take);
    data += take;
    size -= take;
    fill += take;
    if (fill < 64) {
      return;
    }
    md5_block(m->state, m->buffer);
  }
  while (size >= 64) {
    md5_block(m->state, data);
    data += 64;
    size -= 64;
  }
  if (size) {
    memcpy(m->buffer, data, size);
  }
}

void flac_md5_final(FlacMd5* m, uint8_t digest[16]) {
  /* Padding: a one bit, zeros up to 56 bytes modulo 64, then the message
   * length in bits, least significant byte first. */
  const uint64_t bit_length = m->bytes * 8;
  uint8_t pad[72] = {0x80};
  const size_t fill = (size_t)(m->bytes & 63);
  const size_t pad_bytes = fill < 56 ? 56 - fill : 120 - fill;
  store_le32(pad + pad_bytes, (uint32_t)bit_length);
  store_le32(pad + pad_bytes + 4, (uint32_t)(bit_length >> 32));
  flac_md5_update(m, pad, pad_bytes + 8);
  for (int i = 0; i < 4; i++) {
    store_le32(digest + 4 * i, m->state[i]);
  }
}

void flac_md5_update_pcm(FlacMd5* m, const int32_t* samples, size_t count, int bits_per_sample) {
  const int width = (bits_per_sample + 7) / 8;
  uint8_t buf[4096];
  const size_t per_chunk = sizeof(buf) / (size_t)width;
  while (count) {
    const size_t n = count < per_chunk ? count : per_chunk;
    uint8_t* p = buf;
    switch (width) {
      case 1:
        for (size_t i = 0; i < n; i++) {
          *p++ = (uint8_t)samples[i];
        }
        break;
      case 2:
        for (size_t i = 0; i < n; i++) {
          const uint32_t v = (uint32_t)samples[i];
          *p++ = (uint8_t)v;
          *p++ = (uint8_t)(v >> 8);
        }
        break;
      case 3:
        for (size_t i = 0; i < n; i++) {
          const uint32_t v = (uint32_t)samples[i];
          *p++ = (uint8_t)v;
          *p++ = (uint8_t)(v >> 8);
          *p++ = (uint8_t)(v >> 16);
        }
        break;
      default:
        for (size_t i = 0; i < n; i++) {
          store_le32(p, (uint32_t)samples[i]);
          p += 4;
        }
        break;
    }
    flac_md5_update(m, buf, (size_t)(p - buf));
    samples += n;
    count -= n;
  }
}

/* ── coded numbers ──────────────────────────────────────────────── */

int flac_coded_number_write(uint64_t value, uint8_t* out) {
  if (value < 0x80) {
    out[0] = (uint8_t)value;
    return 1;
  }
  if (value >> 36) {
    return 0;
  }
  /* n bytes carry 5 * n + 1 bits: 11, 16, 21, 26, 31 and, with a lead
   * byte of 0xFE and no payload bits in it, 36. */
  int n = 2;
  while (n < 7 && (value >> (5 * n + 1))) {
    n++;
  }
  out[0] = (uint8_t)((0xFF00 >> n) | (n == 7 ? 0 : value >> (6 * (n - 1))));
  for (int i = 1; i < n; i++) {
    out[i] = (uint8_t)(0x80 | ((value >> (6 * (n - 1 - i))) & 0x3F));
  }
  return n;
}

int flac_coded_number_read(const uint8_t* data, size_t size, uint64_t* value) {
  if (size == 0) {
    return 0;
  }
  const uint8_t lead = data[0];
  if (lead < 0x80) {
    *value = lead;
    return 1;
  }
  /* The count of leading one bits is the length; 10xxxxxx is a
   * continuation byte and 11111111 is no lead byte at all. */
  int n = 0;
  while (n < 8 && (lead & (0x80 >> n))) {
    n++;
  }
  if (n < 2 || n > 7) {
    return -1;
  }
  uint64_t v = n == 7 ? 0 : (uint64_t)(lead & (0x7F >> n));
  for (int i = 1; i < n; i++) {
    if ((size_t)i >= size) {
      return 0;
    }
    if ((data[i] & 0xC0) != 0x80) {
      return -1;
    }
    v = (v << 6) | (data[i] & 0x3F);
  }
  *value = v;
  return n;
}

/* ── STREAMINFO ─────────────────────────────────────────────────── */

bool flac_streaminfo_parse(const uint8_t* d, BaacFlacStreamInfo* info) {
  info->min_block_size = (d[0] << 8) | d[1];
  info->max_block_size = (d[2] << 8) | d[3];
  info->min_frame_bytes = (d[4] << 16) | (d[5] << 8) | d[6];
  info->max_frame_bytes = (d[7] << 16) | (d[8] << 8) | d[9];
  info->sample_rate = (d[10] << 12) | (d[11] << 4) | (d[12] >> 4);
  info->channels = ((d[12] >> 1) & 7) + 1;
  info->bits_per_sample = (((d[12] & 1) << 4) | (d[13] >> 4)) + 1;
  info->total_samples = ((uint64_t)(d[13] & 0x0F) << 32) | ((uint64_t)d[14] << 24) |
                        ((uint64_t)d[15] << 16) | ((uint64_t)d[16] << 8) | (uint64_t)d[17];
  memcpy(info->md5, d + 18, 16);
  return info->min_block_size >= BAAC_FLAC_MIN_BLOCK_SIZE &&
         info->max_block_size >= info->min_block_size &&
         info->bits_per_sample >= BAAC_FLAC_MIN_BITS_PER_SAMPLE;
}

bool flac_streaminfo_write(const BaacFlacStreamInfo* info, uint8_t* out) {
  if (info->min_block_size < BAAC_FLAC_MIN_BLOCK_SIZE ||
      info->max_block_size > BAAC_FLAC_MAX_BLOCK_SIZE ||
      info->min_block_size > info->max_block_size || info->min_frame_bytes < 0 ||
      info->min_frame_bytes > 0xFFFFFF || info->max_frame_bytes < 0 ||
      info->max_frame_bytes > 0xFFFFFF || info->sample_rate < 0 ||
      info->sample_rate > BAAC_FLAC_MAX_SAMPLE_RATE || info->channels < 1 ||
      info->channels > BAAC_FLAC_MAX_CHANNELS ||
      info->bits_per_sample < BAAC_FLAC_MIN_BITS_PER_SAMPLE ||
      info->bits_per_sample > BAAC_FLAC_MAX_BITS_PER_SAMPLE || (info->total_samples >> 36)) {
    return false;
  }
  const uint32_t rate = (uint32_t)info->sample_rate;
  const uint32_t ch = (uint32_t)(info->channels - 1);
  const uint32_t bps = (uint32_t)(info->bits_per_sample - 1);
  out[0] = (uint8_t)(info->min_block_size >> 8);
  out[1] = (uint8_t)info->min_block_size;
  out[2] = (uint8_t)(info->max_block_size >> 8);
  out[3] = (uint8_t)info->max_block_size;
  out[4] = (uint8_t)(info->min_frame_bytes >> 16);
  out[5] = (uint8_t)(info->min_frame_bytes >> 8);
  out[6] = (uint8_t)info->min_frame_bytes;
  out[7] = (uint8_t)(info->max_frame_bytes >> 16);
  out[8] = (uint8_t)(info->max_frame_bytes >> 8);
  out[9] = (uint8_t)info->max_frame_bytes;
  out[10] = (uint8_t)(rate >> 12);
  out[11] = (uint8_t)(rate >> 4);
  out[12] = (uint8_t)((rate << 4) | (ch << 1) | (bps >> 4));
  out[13] = (uint8_t)((bps << 4) | (uint32_t)(info->total_samples >> 32));
  out[14] = (uint8_t)(info->total_samples >> 24);
  out[15] = (uint8_t)(info->total_samples >> 16);
  out[16] = (uint8_t)(info->total_samples >> 8);
  out[17] = (uint8_t)info->total_samples;
  memcpy(out + 18, info->md5, 16);
  return true;
}

/* ── frame header ───────────────────────────────────────────────── */

namespace {

/* Sample rates of the codes 1 to 11 (RFC 9639 Table 15). */
const int kSampleRates[12] = {0,     88200, 176400, 192000, 8000,  16000,
                              22050, 24000, 32000,  44100,  48000, 96000};

/* Bit depths of the codes 0 to 7 (Table 17); -1 is the reserved code. */
const int kBitDepths[8] = {0, 8, 12, -1, 16, 20, 24, 32};

}  // namespace

namespace {

/* How a frame header states a sample rate: the code of the four sample
 * rate bits, and for the codes 12 to 14 the number stored behind the
 * header's fixed part.  A table code when one fits (0 for a rate of 0,
 * which reads "see STREAMINFO"), else kilohertz in 8 bits, hertz in 16
 * bits or tens of hertz in 16 bits, whichever expresses the rate; -1
 * when none does. */
int sample_rate_code(int rate, uint32_t* value) {
  *value = 0;
  for (int code = 0; code < 12; code++) {
    if (kSampleRates[code] == rate) {
      return code;
    }
  }
  if (rate <= 0) {
    return -1;
  }
  if (rate % 1000 == 0 && rate / 1000 <= 255) {
    *value = (uint32_t)rate / 1000;
    return 12;
  }
  if (rate <= 65535) {
    *value = (uint32_t)rate;
    return 13;
  }
  if (rate % 10 == 0 && rate / 10 <= 65535) {
    *value = (uint32_t)rate / 10;
    return 14;
  }
  return -1;
}

}  // namespace

bool flac_sample_rate_is_codable(int rate) {
  uint32_t value;
  return rate > 0 && sample_rate_code(rate, &value) > 0;
}

int flac_frame_header_parse(const uint8_t* d, size_t size, FlacFrameHeader* h) {
  /* The first four bytes hold every fixed field. */
  if (size >= 1 && d[0] != 0xFF) {
    return -1;
  }
  if (size >= 2 && (d[1] & 0xFE) != 0xF8) {
    return -1;
  }
  if (size < 4) {
    return 0;
  }
  const int block_code = d[2] >> 4;
  const int rate_code = d[2] & 0x0F;
  const int channel_code = d[3] >> 4;
  const int depth_code = (d[3] >> 1) & 7;
  if (block_code == 0 || rate_code == 15 || channel_code > 10 || kBitDepths[depth_code] < 0 ||
      (d[3] & 1)) {
    return -1;
  }
  h->variable_block_size = (d[1] & 1) != 0;
  if (channel_code < 8) {
    h->channels = channel_code + 1;
    h->assignment = FLAC_ASSIGN_INDEPENDENT;
  } else {
    h->channels = 2;
    h->assignment = channel_code - 7;
  }
  h->bits_per_sample = kBitDepths[depth_code];

  size_t pos = 4;
  const int number_bytes = flac_coded_number_read(d + pos, size - pos, &h->number);
  if (number_bytes <= 0) {
    return number_bytes;
  }
  /* A frame number has 31 bits at most, so six bytes. */
  if (!h->variable_block_size && number_bytes == 7) {
    return -1;
  }
  pos += (size_t)number_bytes;

  const size_t block_bytes = block_code == 6 ? 1 : block_code == 7 ? 2 : 0;
  const size_t rate_bytes = rate_code == 12 ? 1 : rate_code >= 13 ? 2 : 0;
  if (size < pos + block_bytes + rate_bytes + 1) {
    return 0;
  }
  if (block_code == 1) {
    h->block_size = 192;
  } else if (block_code <= 5) {
    h->block_size = 144 << block_code;
  } else if (block_code == 6) {
    h->block_size = d[pos] + 1;
  } else if (block_code == 7) {
    h->block_size = ((d[pos] << 8) | d[pos + 1]) + 1;
    /* 65536 samples cannot be stated in STREAMINFO. */
    if (h->block_size > BAAC_FLAC_MAX_BLOCK_SIZE) {
      return -1;
    }
  } else {
    h->block_size = 1 << block_code;
  }
  pos += block_bytes;
  if (rate_code < 12) {
    h->sample_rate = kSampleRates[rate_code];
  } else if (rate_code == 12) {
    h->sample_rate = d[pos] * 1000;
  } else {
    h->sample_rate = ((d[pos] << 8) | d[pos + 1]) * (rate_code == 14 ? 10 : 1);
  }
  pos += rate_bytes;
  if (flac_crc8(d, pos) != d[pos]) {
    return -1;
  }
  h->header_bytes = (int)pos + 1;
  return h->header_bytes;
}

int flac_frame_header_write(const FlacFrameHeader* h, uint8_t* out) {
  if (h->block_size < 1 || h->block_size > BAAC_FLAC_MAX_BLOCK_SIZE || h->channels < 1 ||
      h->channels > BAAC_FLAC_MAX_CHANNELS || h->assignment < FLAC_ASSIGN_INDEPENDENT ||
      h->assignment > FLAC_ASSIGN_MID_SIDE ||
      (h->assignment != FLAC_ASSIGN_INDEPENDENT && h->channels != 2) ||
      (h->number >> (h->variable_block_size ? 36 : 31))) {
    return -1;
  }

  /* Block size: one of the fixed codes when one fits, else the size
   * minus one in 8 or 16 bits after the coded number. */
  int block_code = 0;
  if (h->block_size == 192) {
    block_code = 1;
  } else {
    for (int code = 2; code <= 5 && !block_code; code++) {
      if (h->block_size == 144 << code) {
        block_code = code;
      }
    }
    for (int code = 8; code <= 15 && !block_code; code++) {
      if (h->block_size == 1 << code) {
        block_code = code;
      }
    }
  }
  if (!block_code) {
    block_code = h->block_size <= 256 ? 6 : 7;
  }

  uint32_t rate_value = 0;
  const int rate_code = sample_rate_code(h->sample_rate, &rate_value);
  int depth_code = -1;
  for (int code = 0; code < 8 && depth_code < 0; code++) {
    if (kBitDepths[code] == h->bits_per_sample) {
      depth_code = code;
    }
  }
  if (rate_code < 0 || depth_code < 0) {
    return -1;
  }

  const int channel_code =
      h->assignment == FLAC_ASSIGN_INDEPENDENT ? h->channels - 1 : 7 + h->assignment;
  int pos = 0;
  out[pos++] = 0xFF;
  out[pos++] = (uint8_t)(0xF8 | (h->variable_block_size ? 1 : 0));
  out[pos++] = (uint8_t)((block_code << 4) | rate_code);
  out[pos++] = (uint8_t)((channel_code << 4) | (depth_code << 1));
  pos += flac_coded_number_write(h->number, out + pos);
  if (block_code == 6) {
    out[pos++] = (uint8_t)(h->block_size - 1);
  } else if (block_code == 7) {
    out[pos++] = (uint8_t)((h->block_size - 1) >> 8);
    out[pos++] = (uint8_t)(h->block_size - 1);
  }
  if (rate_code == 12) {
    out[pos++] = (uint8_t)rate_value;
  } else if (rate_code >= 13) {
    out[pos++] = (uint8_t)(rate_value >> 8);
    out[pos++] = (uint8_t)rate_value;
  }
  out[pos] = flac_crc8(out, (size_t)pos);
  return pos + 1;
}
