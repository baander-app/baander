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

struct Crc16Table {
  uint16_t v[256];
  constexpr Crc16Table() : v() {
    for (int i = 0; i < 256; i++) {
      uint16_t crc = (uint16_t)(i << 8);
      for (int bit = 0; bit < 8; bit++) {
        crc = (uint16_t)((crc & 0x8000) ? (crc << 1) ^ 0x8005 : crc << 1);
      }
      v[i] = crc;
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
  for (size_t i = 0; i < size; i++) {
    crc = (uint16_t)((crc << 8) ^ kCrc16.v[(crc >> 8) ^ data[i]]);
  }
  return crc;
}

/* ── MD5 (RFC 1321) ─────────────────────────────────────────────── */

namespace {

/* T[i] = floor(2^32 * abs(sin(i + 1))), RFC 1321 section 3.4. */
const uint32_t kMd5T[64] = {
    0xd76aa478, 0xe8c7b756, 0x242070db, 0xc1bdceee, 0xf57c0faf, 0x4787c62a, 0xa8304613, 0xfd469501,
    0x698098d8, 0x8b44f7af, 0xffff5bb1, 0x895cd7be, 0x6b901122, 0xfd987193, 0xa679438e, 0x49b40821,
    0xf61e2562, 0xc040b340, 0x265e5a51, 0xe9b6c7aa, 0xd62f105d, 0x02441453, 0xd8a1e681, 0xe7d3fbc8,
    0x21e1cde6, 0xc33707d6, 0xf4d50d87, 0x455a14ed, 0xa9e3e905, 0xfcefa3f8, 0x676f02d9, 0x8d2a4c8a,
    0xfffa3942, 0x8771f681, 0x6d9d6122, 0xfde5380c, 0xa4beea44, 0x4bdecfa9, 0xf6bb4b60, 0xbebfbc70,
    0x289b7ec6, 0xeaa127fa, 0xd4ef3085, 0x04881d05, 0xd9d4d039, 0xe6db99e5, 0x1fa27cf8, 0xc4ac5665,
    0xf4292244, 0x432aff97, 0xab9423a7, 0xfc93a039, 0x655b59c3, 0x8f0ccc92, 0xffeff47d, 0x85845dd1,
    0x6fa87e4f, 0xfe2ce6e0, 0xa3014314, 0x4e0811a1, 0xf7537e82, 0xbd3af235, 0x2ad7d2bb, 0xeb86d391,
};

/* Left rotations per round, four values repeated four times each. */
const int kMd5Shift[4][4] = {{7, 12, 17, 22}, {5, 9, 14, 20}, {4, 11, 16, 23}, {6, 10, 15, 21}};

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

/* One 64-byte block through the four rounds of RFC 1321 section 3.4. */
void md5_block(uint32_t state[4], const uint8_t* block) {
  uint32_t x[16];
  for (int i = 0; i < 16; i++) {
    x[i] = load_le32(block + 4 * i);
  }
  uint32_t a = state[0];
  uint32_t b = state[1];
  uint32_t c = state[2];
  uint32_t d = state[3];
  for (int i = 0; i < 64; i++) {
    uint32_t f;
    int k;
    switch (i >> 4) {
      case 0:
        f = (b & c) | (~b & d);
        k = i;
        break;
      case 1:
        f = (b & d) | (c & ~d);
        k = (5 * i + 1) & 15;
        break;
      case 2:
        f = b ^ c ^ d;
        k = (3 * i + 5) & 15;
        break;
      default:
        f = c ^ (b | ~d);
        k = (7 * i) & 15;
        break;
    }
    const uint32_t t = a + f + x[k] + kMd5T[i];
    a = d;
    d = c;
    c = b;
    b += rotl32(t, kMd5Shift[i >> 4][i & 3]);
  }
  state[0] += a;
  state[1] += b;
  state[2] += c;
  state[3] += d;
}

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

bool flac_sample_rate_is_codable(int rate) {
  if (rate <= 0) {
    return false;
  }
  for (int code = 1; code < 12; code++) {
    if (kSampleRates[code] == rate) {
      return true;
    }
  }
  return (rate % 1000 == 0 && rate / 1000 <= 255) || rate <= 65535 ||
         (rate % 10 == 0 && rate / 10 <= 65535);
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

  /* Sample rate: a table code, else kilohertz in 8 bits, hertz in 16
   * bits or tens of hertz in 16 bits, whichever expresses it. */
  int rate_code = -1;
  uint32_t rate_value = 0;
  for (int code = 0; code < 12 && rate_code < 0; code++) {
    if (kSampleRates[code] == h->sample_rate) {
      rate_code = code;
    }
  }
  if (rate_code < 0 && h->sample_rate > 0) {
    if (h->sample_rate % 1000 == 0 && h->sample_rate / 1000 <= 255) {
      rate_code = 12;
      rate_value = (uint32_t)h->sample_rate / 1000;
    } else if (h->sample_rate <= 65535) {
      rate_code = 13;
      rate_value = (uint32_t)h->sample_rate;
    } else if (h->sample_rate % 10 == 0 && h->sample_rate / 10 <= 65535) {
      rate_code = 14;
      rate_value = (uint32_t)h->sample_rate / 10;
    }
  }
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
