/* The shared parts of the lossless mode against known answers: MD5
 * against the test suite of RFC 1321, the two frame checksums against
 * values worked out from their polynomials, the coded numbers,
 * STREAMINFO and frame headers against the example streams of RFC 9639
 * Appendix D, and the bit reader and writer. */
#include <cfloat>
#include <cmath>

#include "flac_internal.h"
#include "test_flac_util.h"

/* ── MD5 ───────────────────────────────────────────────────────── */

static std::string md5_hex(const uint8_t* data, size_t size, size_t chunk) {
  FlacMd5 m;
  flac_md5_init(&m);
  for (size_t pos = 0; pos < size; pos += chunk) {
    flac_md5_update(&m, data + pos, std::min(chunk, size - pos));
  }
  uint8_t digest[16];
  flac_md5_final(&m, digest);
  return hex_string(digest, 16);
}

static void test_md5() {
  /* The test suite of RFC 1321 appendix A.5. */
  const struct {
    const char* text;
    const char* digest;
  } suite[] = {
      {"", "d41d8cd98f00b204e9800998ecf8427e"},
      {"a", "0cc175b9c0f1b6a831c399e269772661"},
      {"abc", "900150983cd24fb0d6963f7d28e17f72"},
      {"message digest", "f96b697d7cb7938d525a2f31aaf161d0"},
      {"abcdefghijklmnopqrstuvwxyz", "c3fcd3d76192e4007dfb496cca67e13b"},
      {"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789",
       "d174ab98d277d9f5a5611c2c9f419d9f"},
      {"1234567890123456789012345678901234567890123456789012345678901234567890"
       "1234567890",
       "57edf4a22be3c955ac49da2e2107b67a"},
  };
  for (const auto& s : suite) {
    const size_t n = strlen(s.text);
    const std::string got = md5_hex((const uint8_t*)s.text, n, n ? n : 1);
    CHECK(got == s.digest, "MD5(\"%s\") = %s, expected %s", s.text, got.c_str(), s.digest);
  }

  /* The digest does not depend on how the message is cut up, across the
   * 64-byte block size and the 56-byte padding border. */
  std::vector<uint8_t> msg(1000);
  for (size_t i = 0; i < msg.size(); i++) {
    msg[i] = (uint8_t)(i * 31 + 7);
  }
  for (size_t len : {55u, 56u, 57u, 63u, 64u, 65u, 119u, 120u, 128u, 1000u}) {
    const std::string whole = md5_hex(msg.data(), len, len);
    for (size_t chunk : {1u, 3u, 63u, 64u, 65u}) {
      CHECK(md5_hex(msg.data(), len, chunk) == whole, "MD5 of %zu bytes in chunks of %zu", len,
            chunk);
    }
  }

  /* Samples enter the digest sign-extended to whole bytes, least
   * significant byte first: the two 16-bit samples of RFC 9639 D.1 give
   * the digest of that example's STREAMINFO. */
  {
    const int32_t samples[2] = {25588, 10416};
    FlacMd5 m;
    flac_md5_init(&m);
    flac_md5_update_pcm(&m, samples, 2, 16);
    uint8_t digest[16];
    flac_md5_final(&m, digest);
    CHECK(hex_string(digest, 16) == "3e84b41807dc690307586a3dad1a2e0f",
          "MD5 of the D.1 samples: %s", hex_string(digest, 16).c_str());
  }
  /* Each byte width against the bytes written out by hand; 12 bits take
   * two bytes, 20 bits three. */
  const struct {
    int bits;
    int32_t samples[3];
    const char* bytes;
  } widths[] = {
      {8, {-1, 127, -128}, "ff7f80"},
      {12, {-2048, 2047, -1}, "00f8 ff07 ffff"},
      {16, {-32768, 32767, 258}, "0080 ff7f 0201"},
      {20, {-524288, 524287, -2}, "0000f8 ffff07 feffff"},
      {24, {-8388608, 8388607, 66051}, "000080 ffff7f 030201"},
      {32, {INT32_MIN, INT32_MAX, 16909060}, "00000080 ffffff7f 04030201"},
  };
  for (const auto& w : widths) {
    FlacMd5 m;
    flac_md5_init(&m);
    flac_md5_update_pcm(&m, w.samples, 3, w.bits);
    uint8_t digest[16];
    flac_md5_final(&m, digest);
    const std::vector<uint8_t> bytes = hex_bytes(w.bytes);
    CHECK(hex_string(digest, 16) == md5_hex(bytes.data(), bytes.size(), bytes.size()),
          "MD5 packing at %d bits", w.bits);
  }
  /* More samples than the packing buffer holds at once. */
  {
    std::vector<int32_t> samples(5000);
    std::vector<uint8_t> bytes;
    for (size_t i = 0; i < samples.size(); i++) {
      samples[i] = (int32_t)(i * 2654435761u) >> 8;
      for (int b = 0; b < 3; b++) {
        bytes.push_back((uint8_t)((uint32_t)samples[i] >> (8 * b)));
      }
    }
    FlacMd5 m;
    flac_md5_init(&m);
    flac_md5_update_pcm(&m, samples.data(), samples.size(), 24);
    uint8_t digest[16];
    flac_md5_final(&m, digest);
    CHECK(hex_string(digest, 16) == md5_hex(bytes.data(), bytes.size(), bytes.size()),
          "MD5 packing across the buffer size");
  }
}

/* ── checksums ─────────────────────────────────────────────────── */

/* The checksums as their definition reads: the message as a polynomial,
 * shifted through a register one bit at a time, most significant bit
 * first, starting from zero. */
static unsigned crc_bitwise(const uint8_t* data, size_t size, int width, unsigned poly) {
  const unsigned top = 1u << (width - 1);
  const unsigned mask = (top << 1) - 1;
  unsigned crc = 0;
  for (size_t i = 0; i < size; i++) {
    crc ^= (unsigned)data[i] << (width - 8);
    for (int bit = 0; bit < 8; bit++) {
      crc = (crc & top) ? ((crc << 1) ^ poly) & mask : (crc << 1) & mask;
    }
  }
  return crc;
}

static void test_crc() {
  /* A single one bit shifted out of the register leaves the polynomial's
   * low terms: x^2 + x + 1 and x^15 + x^2 + 1. */
  const uint8_t one = 0x01;
  CHECK(flac_crc8(&one, 1) == 0x07, "CRC-8 of 0x01: %02x", flac_crc8(&one, 1));
  CHECK(flac_crc16(&one, 1) == 0x8005, "CRC-16 of 0x01: %04x", flac_crc16(&one, 1));
  /* 0x80 puts the bit at the top of the CRC-8 register, so each of the
   * eight shifts can overflow.  By hand: 07, 0e, 1c, 38, 70, e0, then
   * e0 << 1 overflows to c0 ^ 07 = c7, and c7 << 1 to 8e ^ 07 = 89. */
  const uint8_t high = 0x80;
  CHECK(flac_crc8(&high, 1) == 0x89, "CRC-8 of 0x80: %02x", flac_crc8(&high, 1));
  /* In the CRC-16 register it is 0x8000, and every shift overflows:
   * 8005, 800f, 801b, 8033, 8063, 80c3, 8183, 8303. */
  CHECK(flac_crc16(&high, 1) == 0x8303, "CRC-16 of 0x80: %04x", flac_crc16(&high, 1));

  /* The digits "123456789": f4 and fee8 are the published check values of
   * these two polynomials with a zero start and no reflection. */
  const uint8_t* digits = (const uint8_t*)"123456789";
  CHECK(flac_crc8(digits, 9) == 0xF4, "CRC-8 of the digits: %02x", flac_crc8(digits, 9));
  CHECK(flac_crc16(digits, 9) == 0xFEE8, "CRC-16 of the digits: %04x", flac_crc16(digits, 9));
  CHECK(flac_crc8(digits, 0) == 0 && flac_crc16(digits, 0) == 0,
        "CRC of nothing is the start value");

  /* The table-driven routines against the bit-serial definition, and
   * the update form against the one-shot. */
  std::vector<uint8_t> msg(777);
  unsigned seed = 4711u;
  for (uint8_t& b : msg) {
    seed = seed * 1664525u + 1013904223u;
    b = (uint8_t)(seed >> 24);
  }
  CHECK(flac_crc8(msg.data(), msg.size()) == crc_bitwise(msg.data(), msg.size(), 8, 0x07),
        "CRC-8 against the bit-serial form");
  CHECK(flac_crc16(msg.data(), msg.size()) == crc_bitwise(msg.data(), msg.size(), 16, 0x8005),
        "CRC-16 against the bit-serial form");
  /* Every length around the eight-byte step of the CRC-16, from every
   * start within a step, and continued from a checksum that is not
   * zero. */
  for (size_t start = 0; start < 9; start++) {
    for (size_t len = 0; len <= 41; len++) {
      CHECK(flac_crc16(msg.data() + start, len) == crc_bitwise(msg.data() + start, len, 16, 0x8005),
            "CRC-16 of %zu bytes from offset %zu", len, start);
      CHECK(flac_crc16_update(flac_crc16(msg.data(), start), msg.data() + start, len) ==
                flac_crc16(msg.data(), start + len),
            "CRC-16 of %zu bytes continued after %zu", len, start);
    }
  }
  uint16_t crc = 0;
  for (size_t pos = 0; pos < msg.size(); pos += 100) {
    crc = flac_crc16_update(crc, msg.data() + pos, std::min<size_t>(100, msg.size() - pos));
  }
  CHECK(crc == flac_crc16(msg.data(), msg.size()), "CRC-16 in pieces");
}

/* ── coded numbers ─────────────────────────────────────────────── */

static void test_coded_numbers() {
  const struct {
    uint64_t value;
    int bytes;
  } cases[] = {
      {0, 1},
      {127, 1},
      {128, 2},
      {0x7FF, 2},
      {0x800, 3},
      {0xFFFF, 3},
      {0x10000, 4},
      {0x1FFFFF, 4},
      {0x200000, 5},
      {0x3FFFFFF, 5},
      {0x4000000, 6},
      {0x7FFFFFFF, 6}, /* the largest frame number: 31 bits */
      {0x80000000ull, 7},
      {0xFFFFFFFFFull, 7}, /* the largest sample number: 36 bits */
  };
  for (const auto& c : cases) {
    uint8_t buf[7] = {0};
    const int n = flac_coded_number_write(c.value, buf);
    CHECK(n == c.bytes, "%llu is written in %d bytes, expected %d", (unsigned long long)c.value, n,
          c.bytes);
    uint64_t back = ~0ull;
    CHECK(flac_coded_number_read(buf, 7, &back) == n && back == c.value, "%llu reads back as %llu",
          (unsigned long long)c.value, (unsigned long long)back);
    /* Every shorter prefix asks for more bytes. */
    for (int have = 0; have < n; have++) {
      CHECK(flac_coded_number_read(buf, (size_t)have, &back) == 0,
            "%d of %d bytes of a number are not enough", have, n);
    }
  }
  uint8_t buf[7];
  CHECK(flac_coded_number_write(0x1000000000ull, buf) == 0, "37 bits do not fit a coded number");

  /* The worked example of RFC 9639 section 9.1.5: 51 billion samples. */
  CHECK(flac_coded_number_write(51000000000ull, buf) == 7 && hex_string(buf, 7) == "feaf9fb5a3b880",
        "51 billion is coded as %s", hex_string(buf, 7).c_str());

  /* Invalid sequences: a continuation byte or 0xFF in the lead, and a
   * following byte that is no continuation byte. */
  uint64_t v;
  const uint8_t continuation[2] = {0x80, 0x80};
  const uint8_t all_ones[7] = {0xFF, 0x80, 0x80, 0x80, 0x80, 0x80, 0x80};
  const uint8_t broken[2] = {0xC2, 0x41};
  const uint8_t broken_late[4] = {0xF0, 0x90, 0x80, 0xC0};
  CHECK(flac_coded_number_read(continuation, 2, &v) == -1, "a continuation byte cannot lead");
  CHECK(flac_coded_number_read(all_ones, 7, &v) == -1, "0xFF cannot lead");
  CHECK(flac_coded_number_read(broken, 2, &v) == -1, "the second byte must continue");
  CHECK(flac_coded_number_read(broken_late, 4, &v) == -1, "the last byte must continue");
}

/* ── STREAMINFO and frame headers of the RFC's examples ────────── */

struct BlockHeader {
  bool last;
  int type;
  size_t size;
};

static BlockHeader block_header(const std::vector<uint8_t>& s, size_t pos) {
  return {(s[pos] & 0x80) != 0, s[pos] & 0x7F,
          ((size_t)s[pos + 1] << 16) | ((size_t)s[pos + 2] << 8) | s[pos + 3]};
}

static void check_streaminfo(const char* name, const std::vector<uint8_t>& s, int block_size,
                             int min_frame, int max_frame, int rate, int channels, int bits,
                             uint64_t total, const char* md5) {
  CHECK(memcmp(s.data(), "fLaC", 4) == 0, "%s: marker", name);
  const BlockHeader bh = block_header(s, 4);
  CHECK(bh.type == FLAC_BLOCK_STREAMINFO && bh.size == BAC_FLAC_STREAMINFO_BYTES,
        "%s: the first block is a STREAMINFO of 34 bytes", name);
  BacFlacStreamInfo info;
  CHECK(flac_streaminfo_parse(s.data() + 8, &info), "%s: STREAMINFO parses", name);
  CHECK(info.min_block_size == block_size && info.max_block_size == block_size,
        "%s: block sizes %d..%d", name, info.min_block_size, info.max_block_size);
  CHECK(info.min_frame_bytes == min_frame && info.max_frame_bytes == max_frame,
        "%s: frame sizes %d..%d", name, info.min_frame_bytes, info.max_frame_bytes);
  CHECK(info.sample_rate == rate, "%s: sample rate %d", name, info.sample_rate);
  CHECK(info.channels == channels, "%s: channels %d", name, info.channels);
  CHECK(info.bits_per_sample == bits, "%s: bit depth %d", name, info.bits_per_sample);
  CHECK(info.total_samples == total, "%s: total %llu", name,
        (unsigned long long)info.total_samples);
  CHECK(hex_string(info.md5, 16) == md5, "%s: MD5 %s", name, hex_string(info.md5, 16).c_str());
  /* Writing the parsed block gives the bytes back. */
  uint8_t out[BAC_FLAC_STREAMINFO_BYTES];
  CHECK(flac_streaminfo_write(&info, out) && memcmp(out, s.data() + 8, sizeof(out)) == 0,
        "%s: STREAMINFO writes back to the same bytes", name);
}

/* A frame at `pos` of `size` bytes: its header fields, the header as
 * rewritten from them, and the frame CRC-16 in its last two bytes. */
static void check_frame(const char* name, const std::vector<uint8_t>& s, size_t pos, size_t size,
                        int header_bytes, int block_size, int rate, int channels, int assignment,
                        int bits, uint64_t number) {
  FlacFrameHeader h;
  const int n = flac_frame_header_parse(s.data() + pos, s.size() - pos, &h);
  CHECK(n == header_bytes, "%s: frame at %zu has a header of %d bytes", name, pos, n);
  if (n <= 0) {
    return;
  }
  CHECK(!h.variable_block_size, "%s: fixed block size stream", name);
  CHECK(h.block_size == block_size, "%s: block size %d", name, h.block_size);
  CHECK(h.sample_rate == rate, "%s: sample rate %d", name, h.sample_rate);
  CHECK(h.channels == channels && h.assignment == assignment, "%s: %d channels, assignment %d",
        name, h.channels, h.assignment);
  CHECK(h.bits_per_sample == bits, "%s: bit depth %d", name, h.bits_per_sample);
  CHECK(h.number == number, "%s: frame number %llu", name, (unsigned long long)h.number);
  uint8_t out[FLAC_MAX_FRAME_HEADER_BYTES];
  CHECK(flac_frame_header_write(&h, out) == n && memcmp(out, s.data() + pos, (size_t)n) == 0,
        "%s: the header writes back to the same bytes", name);
  const unsigned stored = ((unsigned)s[pos + size - 2] << 8) | s[pos + size - 1];
  CHECK(flac_crc16(s.data() + pos, size - 2) == stored, "%s: frame CRC-16 %04x", name, stored);
  /* Fewer bytes than the header ask for more instead of failing. */
  for (int have = 0; have < n; have++) {
    CHECK(flac_frame_header_parse(s.data() + pos, (size_t)have, &h) == 0,
          "%s: %d bytes of the header are not enough", name, have);
  }
}

static void test_rfc_examples() {
  /* D.1: Tables 27 to 29. */
  const std::vector<uint8_t> e1 = rfc9639_example_1();
  CHECK(e1.size() == 0x39, "example 1 has %zu bytes", e1.size());
  check_streaminfo("example 1", e1, 4096, 15, 15, 44100, 2, 16, 1,
                   "3e84b41807dc690307586a3dad1a2e0f");
  CHECK(block_header(e1, 4).last, "example 1: STREAMINFO is the last block");
  check_frame("example 1", e1, 0x2a, 15, 7, 1, 44100, 2, FLAC_ASSIGN_INDEPENDENT, 16, 0);
  CHECK(e1[0x30] == 0xbf, "example 1: header CRC-8");

  /* D.2: Tables 32 to 36 and D.2.8. */
  const std::vector<uint8_t> e2 = rfc9639_example_2();
  CHECK(e2.size() == 0xe3, "example 2 has %zu bytes", e2.size());
  check_streaminfo("example 2", e2, 16, 23, 68, 44100, 2, 16, 19,
                   "d5b0564975e98b8d8b930422757b8103");
  BlockHeader bh = block_header(e2, 4);
  CHECK(!bh.last, "example 2: more blocks follow STREAMINFO");
  bh = block_header(e2, 0x2a);
  CHECK(!bh.last && bh.type == FLAC_BLOCK_SEEKTABLE && bh.size == 18, "example 2: seek table");
  bh = block_header(e2, 0x40);
  CHECK(!bh.last && bh.type == FLAC_BLOCK_VORBIS_COMMENT && bh.size == 58,
        "example 2: Vorbis comment");
  bh = block_header(e2, 0x7e);
  CHECK(bh.last && bh.type == FLAC_BLOCK_PADDING && bh.size == 6, "example 2: padding");
  check_frame("example 2 frame 1", e2, 0x88, 68, 7, 16, 44100, 2, FLAC_ASSIGN_SIDE_RIGHT, 16, 0);
  check_frame("example 2 frame 2", e2, 0xcc, 23, 7, 3, 44100, 2, FLAC_ASSIGN_INDEPENDENT, 16, 1);

  /* D.3: Tables 45 and 46. */
  const std::vector<uint8_t> e3 = rfc9639_example_3();
  CHECK(e3.size() == 0x49, "example 3 has %zu bytes", e3.size());
  check_streaminfo("example 3", e3, 4096, 31, 31, 32000, 1, 8, 24,
                   "f8f9e396f5cbcfc6dc807f9977906b32");
  check_frame("example 3", e3, 0x2a, 31, 7, 24, 32000, 1, FLAC_ASSIGN_INDEPENDENT, 8, 0);
}

static void test_streaminfo_limits() {
  BacFlacStreamInfo info = {};
  info.min_block_size = 16;
  info.max_block_size = 65535;
  info.min_frame_bytes = 0xFFFFFF;
  info.max_frame_bytes = 0xFFFFFF;
  info.sample_rate = BAC_FLAC_MAX_SAMPLE_RATE;
  info.channels = 8;
  info.bits_per_sample = 32;
  info.total_samples = 0xFFFFFFFFFull;
  for (int i = 0; i < 16; i++) {
    info.md5[i] = (uint8_t)(0xF0 + i);
  }
  uint8_t out[BAC_FLAC_STREAMINFO_BYTES];
  BacFlacStreamInfo back;
  CHECK(flac_streaminfo_write(&info, out) && flac_streaminfo_parse(out, &back),
        "the largest STREAMINFO values round-trip");
  CHECK(back.min_block_size == 16 && back.max_block_size == 65535 &&
            back.min_frame_bytes == 0xFFFFFF && back.max_frame_bytes == 0xFFFFFF &&
            back.sample_rate == BAC_FLAC_MAX_SAMPLE_RATE && back.channels == 8 &&
            back.bits_per_sample == 32 && back.total_samples == 0xFFFFFFFFFull &&
            memcmp(back.md5, info.md5, 16) == 0,
        "every field survives at its maximum");

  /* What the writer refuses. */
  const auto refused = [&](const char* what, void (*change)(BacFlacStreamInfo*)) {
    BacFlacStreamInfo bad = info;
    change(&bad);
    CHECK(!flac_streaminfo_write(&bad, out), "the writer refuses %s", what);
  };
  refused("a block size below 16", [](BacFlacStreamInfo* i) { i->min_block_size = 15; });
  refused("a block size of 65536", [](BacFlacStreamInfo* i) { i->max_block_size = 65536; });
  refused("a minimum above the maximum", [](BacFlacStreamInfo* i) {
    i->min_block_size = 4097;
    i->max_block_size = 4096;
  });
  refused("nine channels", [](BacFlacStreamInfo* i) { i->channels = 9; });
  refused("no channel", [](BacFlacStreamInfo* i) { i->channels = 0; });
  refused("3 bits per sample", [](BacFlacStreamInfo* i) { i->bits_per_sample = 3; });
  refused("33 bits per sample", [](BacFlacStreamInfo* i) { i->bits_per_sample = 33; });
  refused("a rate above 20 bits", [](BacFlacStreamInfo* i) { i->sample_rate = 1048576; });
  refused("a total above 36 bits", [](BacFlacStreamInfo* i) { i->total_samples = 1ull << 36; });
  refused("a frame size above 24 bits",
          [](BacFlacStreamInfo* i) { i->max_frame_bytes = 1 << 24; });

  /* What the parser refuses: values the format forbids. */
  flac_streaminfo_write(&info, out);
  uint8_t bad[BAC_FLAC_STREAMINFO_BYTES];
  memcpy(bad, out, sizeof(bad));
  bad[0] = 0;
  bad[1] = 15;
  CHECK(!flac_streaminfo_parse(bad, &back), "a minimum block size of 15 is forbidden");
  memcpy(bad, out, sizeof(bad));
  bad[0] = 0x20;
  bad[2] = 0x10;
  bad[3] = 0;
  CHECK(!flac_streaminfo_parse(bad, &back), "a maximum block size below the minimum is forbidden");
  memcpy(bad, out, sizeof(bad));
  bad[12] &= 0xFE; /* bits per sample minus one: 00010 */
  bad[13] = (uint8_t)((bad[13] & 0x0F) | 0x20);
  CHECK(!flac_streaminfo_parse(bad, &back) && back.bits_per_sample == 3,
        "3 bits per sample are forbidden");
}

/* ── frame headers ─────────────────────────────────────────────── */

static void test_frame_header_roundtrip() {
  const int block_sizes[] = {1,    15,   16,   192,  255,  256,   257,   576,
                             1152, 2304, 4096, 4608, 8192, 16384, 32768, 65535};
  const int rates[] = {0,      8000,   16000, 22050,  24000, 32000, 44100, 48000,  88200, 96000,
                       176400, 192000, 1000,  255000, 22254, 65535, 65540, 655350, 1};
  const int depths[] = {0, 8, 12, 16, 20, 24, 32};
  const uint64_t numbers[] = {0, 1, 127, 128, 70000, 0x7FFFFFFF};
  int checked = 0;
  for (int block_size : block_sizes) {
    for (int rate : rates) {
      for (int depth : depths) {
        for (int layout = 1; layout <= 11; layout++) {
          FlacFrameHeader h = {};
          h.block_size = block_size;
          h.sample_rate = rate;
          h.bits_per_sample = depth;
          h.channels = layout <= 8 ? layout : 2;
          h.assignment = layout <= 8 ? FLAC_ASSIGN_INDEPENDENT : layout - 8;
          h.number = numbers[(size_t)checked % (sizeof(numbers) / sizeof(numbers[0]))];
          h.variable_block_size = (checked & 1) != 0;
          uint8_t buf[FLAC_MAX_FRAME_HEADER_BYTES + 1];
          const int n = flac_frame_header_write(&h, buf);
          FlacFrameHeader back = {};
          const int m = n > 0 ? flac_frame_header_parse(buf, (size_t)n, &back) : -1;
          const bool same = m == n && back.variable_block_size == h.variable_block_size &&
                            back.block_size == h.block_size && back.sample_rate == h.sample_rate &&
                            back.channels == h.channels && back.assignment == h.assignment &&
                            back.bits_per_sample == h.bits_per_sample && back.number == h.number &&
                            back.header_bytes == n;
          CHECK(same, "header round trip: block %d, rate %d, depth %d, layout %d (%d, %d bytes)",
                block_size, rate, depth, layout, n, m);
          checked++;
        }
      }
    }
  }
  CHECK(checked == 16 * 19 * 7 * 11, "%d headers checked", checked);

  /* The longest header: a 36-bit sample number, a 16-bit block size and
   * a 16-bit sample rate. */
  FlacFrameHeader h = {};
  h.variable_block_size = true;
  h.block_size = 65535;
  h.sample_rate = 655350;
  h.channels = 2;
  h.assignment = FLAC_ASSIGN_MID_SIDE;
  h.bits_per_sample = 24;
  h.number = 0xFFFFFFFFFull;
  uint8_t buf[FLAC_MAX_FRAME_HEADER_BYTES];
  FlacFrameHeader back = {};
  CHECK(flac_frame_header_write(&h, buf) == FLAC_MAX_FRAME_HEADER_BYTES &&
            flac_frame_header_parse(buf, sizeof(buf), &back) == FLAC_MAX_FRAME_HEADER_BYTES &&
            back.number == h.number && back.block_size == 65535 && back.sample_rate == 655350,
        "the longest header is %d bytes", FLAC_MAX_FRAME_HEADER_BYTES);

  /* What a header cannot say. */
  const auto unwritable = [&](const char* what, void (*change)(FlacFrameHeader*)) {
    FlacFrameHeader bad = h;
    change(&bad);
    CHECK(flac_frame_header_write(&bad, buf) == -1, "no header for %s", what);
  };
  unwritable("a block of 65536 samples", [](FlacFrameHeader* x) { x->block_size = 65536; });
  unwritable("an empty block", [](FlacFrameHeader* x) { x->block_size = 0; });
  unwritable("700001 Hz", [](FlacFrameHeader* x) { x->sample_rate = 700001; });
  unwritable("65537 Hz", [](FlacFrameHeader* x) { x->sample_rate = 65537; });
  unwritable("17 bits", [](FlacFrameHeader* x) { x->bits_per_sample = 17; });
  unwritable("nine channels", [](FlacFrameHeader* x) {
    x->channels = 9;
    x->assignment = FLAC_ASSIGN_INDEPENDENT;
  });
  unwritable("mid-side on three channels", [](FlacFrameHeader* x) { x->channels = 3; });
  unwritable("a 32-bit frame number", [](FlacFrameHeader* x) {
    x->variable_block_size = false;
    x->number = 0x80000000ull;
  });
  unwritable("a 37-bit sample number", [](FlacFrameHeader* x) { x->number = 1ull << 36; });

  CHECK(flac_sample_rate_is_codable(44100) && flac_sample_rate_is_codable(22254) &&
            flac_sample_rate_is_codable(655350) && flac_sample_rate_is_codable(255000) &&
            !flac_sample_rate_is_codable(700001) && !flac_sample_rate_is_codable(655351) &&
            !flac_sample_rate_is_codable(768000) && !flac_sample_rate_is_codable(0),
        "which sample rates a header can name");
}

/* Every reserved or forbidden field value makes the header invalid.
 * Each case changes one field of a valid header and recomputes the
 * CRC-8, so that the field is what gets it refused. */
static void test_frame_header_rejects() {
  FlacFrameHeader h = {};
  h.block_size = 4096;
  h.sample_rate = 44100;
  h.channels = 2;
  h.assignment = FLAC_ASSIGN_INDEPENDENT;
  h.bits_per_sample = 16;
  h.number = 5;
  uint8_t good[FLAC_MAX_FRAME_HEADER_BYTES];
  const int n = flac_frame_header_write(&h, good);
  FlacFrameHeader back;
  CHECK(n == 6 && flac_frame_header_parse(good, (size_t)n, &back) == n, "the base header is valid");

  const auto parse_changed = [&](int index, uint8_t value, bool fix_crc) {
    uint8_t buf[FLAC_MAX_FRAME_HEADER_BYTES];
    memcpy(buf, good, sizeof(buf));
    buf[index] = value;
    if (fix_crc) {
      buf[n - 1] = flac_crc8(buf, (size_t)n - 1);
    }
    return flac_frame_header_parse(buf, (size_t)n, &back);
  };
  CHECK(parse_changed(0, 0xFE, true) == -1, "a wrong first sync byte");
  CHECK(parse_changed(1, 0xFA, true) == -1, "a wrong second sync byte");
  CHECK(parse_changed(1, 0xF0, true) == -1, "a short sync code");
  CHECK(parse_changed(1, 0xF9, true) == n && back.variable_block_size,
        "the blocking strategy bit alone keeps the header valid");
  CHECK(parse_changed(2, (uint8_t)(good[2] & 0x0F), true) == -1, "block size bits 0000");
  CHECK(parse_changed(2, (uint8_t)(good[2] | 0x0F), true) == -1, "sample rate bits 1111");
  for (int code = 11; code <= 15; code++) {
    CHECK(parse_changed(3, (uint8_t)((code << 4) | (good[3] & 0x0F)), true) == -1,
          "channel bits %d", code);
  }
  CHECK(parse_changed(3, (uint8_t)((good[3] & 0xF1) | (3 << 1)), true) == -1, "bit depth bits 011");
  CHECK(parse_changed(3, (uint8_t)(good[3] | 1), true) == -1, "the reserved bit set");
  CHECK(parse_changed(4, 0x80, true) == -1, "a coded number led by a continuation byte");
  CHECK(parse_changed(4, 0xFF, true) == -1, "a coded number led by 0xFF");
  CHECK(parse_changed(n - 1, (uint8_t)(good[n - 1] ^ 1), false) == -1, "a wrong CRC-8");
  CHECK(parse_changed(4, 6, false) == -1, "a changed frame number under the old CRC-8");

  /* A block size of 65536 stored as 65535. */
  {
    uint8_t buf[FLAC_MAX_FRAME_HEADER_BYTES] = {0xFF, 0xF8, 0x79, 0x18, 0x00, 0xFF, 0xFF};
    buf[7] = flac_crc8(buf, 7);
    CHECK(flac_frame_header_parse(buf, 8, &back) == -1, "a block size of 65536");
    buf[6] = 0xFE;
    buf[7] = flac_crc8(buf, 7);
    CHECK(flac_frame_header_parse(buf, 8, &back) == 8 && back.block_size == 65535,
          "a block size of 65535 is the largest");
  }
  /* A seven-byte number is a sample number; a frame number stops at six
   * bytes. */
  {
    uint8_t buf[FLAC_MAX_FRAME_HEADER_BYTES] = {0xFF, 0xF8, 0xC9, 0x18, 0xFE, 0x80,
                                                0x80, 0x80, 0x80, 0x80, 0x81};
    buf[11] = flac_crc8(buf, 11);
    CHECK(flac_frame_header_parse(buf, 12, &back) == -1,
          "a seven-byte number in a fixed block size stream");
    buf[1] = 0xF9;
    buf[11] = flac_crc8(buf, 11);
    CHECK(flac_frame_header_parse(buf, 12, &back) == 12 && back.number == 1,
          "the same number in a variable block size stream");
  }
}

/* ── bit reader and writer ─────────────────────────────────────── */

static void test_bits() {
  /* Mixed widths, signed values up to 33 bits and unary runs written
   * and read back, with the writer's bit count and byte count. */
  std::vector<uint8_t> buf(4096);
  FlacBitWriter w;
  flac_bitwriter_init(&w, buf.data(), buf.size());
  struct Item {
    int kind; /* 0 unsigned, 1 signed, 2 unary */
    int bits;
    int64_t value;
  };
  std::vector<Item> items = {
      {0, 1, 1},
      {0, 1, 0},
      {0, 3, 5},
      {0, 16, 0xABCD},
      {0, 7, 0},
      {0, 21, 0x1FFFFF},
      {0, 32, 0xFFFFFFFFll},
      {0, 2, 3},
      {1, 33, -4294967296ll},
      {1, 33, 4294967295ll},
      {1, 32, INT32_MIN},
      {1, 1, -1},
      {1, 17, -65536},
      {1, 5, -10},
      {2, 0, 0},
      {2, 0, 1},
      {2, 0, 31},
      {2, 0, 32},
      {2, 0, 63},
      {2, 0, 64},
      {2, 0, 65},
      {2, 0, 200},
      {0, 5, 21},
      {2, 0, 1000},
      {1, 24, -8388608},
      {0, 0, 0},
      {0, 32, 0x12345678},
  };
  unsigned seed = 99u;
  for (int i = 0; i < 400; i++) {
    seed = seed * 1664525u + 1013904223u;
    const int bits = 1 + (int)((seed >> 8) % 32);
    seed = seed * 1664525u + 1013904223u;
    const uint32_t value = bits == 32 ? seed : seed & ((1u << bits) - 1);
    items.push_back({0, bits, (int64_t)value});
    if (i % 7 == 0) {
      items.push_back({2, 0, (int64_t)((seed >> 20) % 90)});
    }
  }
  size_t total_bits = 0;
  for (const Item& it : items) {
    if (it.kind == 0) {
      flac_bitwriter_write(&w, (uint32_t)it.value, it.bits);
      total_bits += (size_t)it.bits;
    } else if (it.kind == 1) {
      flac_bitwriter_write_signed(&w, it.value, it.bits);
      total_bits += (size_t)it.bits;
    } else {
      flac_bitwriter_write_unary(&w, (uint32_t)it.value);
      total_bits += (size_t)it.value + 1;
    }
    CHECK(flac_bitwriter_bits(&w) == total_bits, "the writer counts %zu bits, expected %zu",
          flac_bitwriter_bits(&w), total_bits);
  }
  const size_t bytes = flac_bitwriter_flush(&w);
  CHECK(!w.overflow && bytes == (total_bits + 7) / 8, "%zu bytes hold %zu bits", bytes, total_bits);

  /* The reader is given exactly the written bytes, so that reading
   * beyond them would leave the buffer. */
  const std::vector<uint8_t> stream(buf.begin(), buf.begin() + (long)bytes);
  FlacBitReader r;
  flac_bitreader_init(&r, stream.data(), stream.size());
  size_t read_bits = 0;
  for (size_t i = 0; i < items.size(); i++) {
    const Item& it = items[i];
    int64_t got;
    if (it.kind == 0) {
      got = (int64_t)flac_bitreader_read(&r, it.bits);
      read_bits += (size_t)it.bits;
    } else if (it.kind == 1) {
      got = flac_bitreader_read_signed(&r, it.bits);
      read_bits += (size_t)it.bits;
    } else {
      got = (int64_t)flac_bitreader_read_unary(&r);
      read_bits += (size_t)it.value + 1;
    }
    CHECK(got == it.value, "item %zu (kind %d, %d bits): read %lld, wrote %lld", i, it.kind,
          it.bits, (long long)got, (long long)it.value);
    CHECK(flac_bitreader_position(&r) == read_bits, "the reader is at bit %zu, expected %zu",
          flac_bitreader_position(&r), read_bits);
  }
  CHECK(!r.overread, "reading what was written is no overread");

  /* Alignment skips to the byte boundary and stays there. */
  const uint8_t pattern[4] = {0xA5, 0xFF, 0x3C, 0x81};
  flac_bitreader_init(&r, pattern, sizeof(pattern));
  CHECK(flac_bitreader_read(&r, 3) == 5, "three bits");
  flac_bitreader_align(&r);
  CHECK(flac_bitreader_position(&r) == 8, "aligned to bit %zu", flac_bitreader_position(&r));
  flac_bitreader_align(&r);
  CHECK(flac_bitreader_position(&r) == 8 && flac_bitreader_read(&r, 8) == 0xFF,
        "aligning an aligned reader moves nothing");

  /* An overread: the bits that exist, then zeros, and the flag.  The
   * buffers are heap blocks of the exact size, so that a read outside
   * them is seen by the address sanitizer. */
  {
    const std::vector<uint8_t> two = {0xAB, 0xCD};
    flac_bitreader_init(&r, two.data(), two.size());
    CHECK(flac_bitreader_read(&r, 12) == 0xABC && !r.overread, "twelve bits of sixteen");
    CHECK(flac_bitreader_read(&r, 8) == 0xD0, "the last four bits, then zeros");
    CHECK(r.overread, "the overread is flagged");
    CHECK(flac_bitreader_read(&r, 32) == 0 && flac_bitreader_read_unary(&r) == 0 &&
              flac_bitreader_read_signed(&r, 33) == 0 && r.overread,
          "everything after an overread is zero");
  }
  {
    /* Exactly to the end is no overread, one bit more is. */
    const std::vector<uint8_t> nine = {1, 2, 3, 4, 5, 6, 7, 8, 9};
    flac_bitreader_init(&r, nine.data(), nine.size());
    for (int i = 0; i < 9; i++) {
      CHECK(flac_bitreader_read(&r, 8) == (uint32_t)(i + 1), "byte %d", i);
    }
    CHECK(!r.overread && flac_bitreader_position(&r) == 72, "the end is reached without overread");
    CHECK(flac_bitreader_read(&r, 1) == 0 && r.overread, "one bit past the end");
  }
  {
    /* A unary run that never ends: the zeros that exist are counted and
     * the overread flagged, for every buffer length around the cache
     * size. */
    for (size_t len = 0; len <= 20; len++) {
      const std::vector<uint8_t> zeros(len, 0);
      flac_bitreader_init(&r, zeros.data(), zeros.size());
      CHECK(flac_bitreader_read_unary(&r) == len * 8 && r.overread,
            "an endless unary run over %zu bytes", len);
    }
    /* The terminating bit in the very last position. */
    for (size_t len = 1; len <= 20; len++) {
      std::vector<uint8_t> run(len, 0);
      run[len - 1] = 1;
      flac_bitreader_init(&r, run.data(), run.size());
      CHECK(flac_bitreader_read_unary(&r) == len * 8 - 1 && !r.overread,
            "a unary run ending with the last bit of %zu bytes", len);
    }
  }
  {
    /* An empty input. */
    flac_bitreader_init(&r, nullptr, 0);
    CHECK(flac_bitreader_read(&r, 0) == 0 && !r.overread, "zero bits of nothing");
    CHECK(flac_bitreader_read(&r, 1) == 0 && r.overread, "one bit of nothing");
  }

  /* The writer: exactly full is no overflow, one more bit is, and
   * nothing is stored beyond the capacity. */
  {
    std::vector<uint8_t> small(5, 0xEE);
    flac_bitwriter_init(&w, small.data(), 4);
    flac_bitwriter_write(&w, 0xDEADBEEF, 32);
    CHECK(!w.overflow && flac_bitwriter_flush(&w) == 4, "exactly full");
    flac_bitwriter_write(&w, 1, 1);
    flac_bitwriter_flush(&w);
    CHECK(w.overflow, "one more bit overflows");
    CHECK(small[4] == 0xEE && small[0] == 0xDE && small[3] == 0xEF,
          "nothing is written past the capacity");
    flac_bitwriter_init(&w, small.data(), 3);
    flac_bitwriter_write(&w, 0x01020304, 32);
    CHECK(w.overflow, "a word that does not fit overflows");
  }
  {
    /* Padding to the byte boundary is zeros. */
    uint8_t out[2] = {0xFF, 0xFF};
    flac_bitwriter_init(&w, out, sizeof(out));
    flac_bitwriter_write(&w, 0x7, 3);
    CHECK(flac_bitwriter_flush(&w) == 1 && out[0] == 0xE0 && out[1] == 0xFF,
          "three bits flush as one zero-padded byte");
  }
}

/* ── the analysis's own cosine and logarithm ───────────────────── */

/* The encoder's analysis computes its cosine and its base 2 logarithm
 * itself, so that every build writes the same streams.  Two things are
 * held here: the error of each against a reference of higher precision,
 * over the whole range of its arguments, and the results themselves,
 * bit for bit, on a fixed set of arguments, which every compiler and
 * processor has to reproduce. */
static void test_analysis_functions(bool print) {
  /* The reference is the long double function where that type has more
   * bits than a double (64 on x86, 113 on most others); where it does
   * not, the C library's own double function, with room for its error. */
  const bool wide = LDBL_MANT_DIG >= 64;
  const long double cos_bound = wide ? 0x1p-52L : 0x1p-51L;
  const long double log_bound = wide ? 0x1p-51L : 0x1.8p-51L;
  const double pi = 3.14159265358979323846;
  uint32_t seed = 20261111u;
  const auto next = [&seed]() {
    seed = seed * 1664525u + 1013904223u;
    return seed;
  };
  uint64_t hash = 1469598103934665603ull;
  const auto mix = [&hash](double v) {
    uint64_t bits;
    memcpy(&bits, &v, sizeof(bits));
    hash = (hash ^ bits) * 1099511628211ull;
  };

  /* The cosine: every argument a window of up to 4096 samples can ask
   * for (a subset of the longer tapers), then arguments all over
   * [0, pi], and the ends. */
  long double worst = 0.0L;
  double worst_at = 0.0;
  long count = 0;
  const auto check_cos = [&](double x, bool pinned) {
    const double c = flac_cos(x);
    const long double error = fabsl((long double)c - cosl((long double)x));
    if (error > worst) {
      worst = error;
      worst_at = x;
    }
    if (pinned) {
      mix(c);
    }
    count++;
  };
  /* These arguments are computed here, in a file that does not get the
   * analysis's own flags: another build may round them another way, so
   * they serve the bound and not the pin. */
  for (int taper = 1; taper <= 4096; taper++) {
    for (int edge = 0; edge < taper; edge += taper > 256 ? 13 : 1) {
      check_cos(pi * ((double)edge + 0.5) / (double)taper, false);
    }
  }
  for (double x : {0.0, pi / 4.0, pi / 2.0, 3.0 * pi / 4.0, pi, 1e-300, 1e-9}) {
    check_cos(x, false);
  }
  /* These are exact whatever the options: a whole number below
   * pi * 2^50, scaled by a power of two. */
  for (int i = 0; i < 2000000; i++) {
    const uint64_t whole = (((uint64_t)next() << 32) | next()) % 3537118876014000ull;
    check_cos(ldexp((double)whole, -50), true);
  }
  CHECK(worst <= cos_bound,
        "flac_cos is within %.3Le of the cosine on [0, pi] (%ld arguments; worst %.3Le at %a)",
        cos_bound, count, worst, worst_at);
  CHECK(flac_cos(0.0) == 1.0 && flac_cos(pi) == -1.0, "flac_cos is 1 at 0 and -1 at pi");
  /* And the windows themselves, which the library makes from its own
   * arguments: the three sets at the two block sizes of the levels and
   * at two lengths a last block may have. */
  {
    std::vector<double> window(4096);
    for (int n : {4096, 1152, 1000, 16}) {
      for (int windows : {1, 3, 9}) {
        for (int index = 0; index < windows; index++) {
          flac_lpc_window(window.data(), n, windows, index, 0.5);
          for (int i = 0; i < n; i++) {
            mix(window[(size_t)i]);
          }
        }
      }
      flac_lpc_hann(window.data(), n);
      for (int i = 0; i < n; i++) {
        mix(window[(size_t)i]);
      }
    }
  }
  const uint64_t cos_hash = hash;

  /* The logarithm: mantissas all over [1, 2) at exponents over the
   * whole range of a double, subnormal numbers among them, and at the
   * exponents the estimates meet most; the powers of two with their
   * neighbours; and the ends of the table's intervals. */
  hash = 1469598103934665603ull;
  worst = 0.0L;
  worst_at = 0.0;
  count = 0;
  const auto check_log = [&](double x) {
    if (!(x > 0.0) || std::isinf(x)) {
      return;
    }
    const double l = flac_log2(x);
    const long double truth = log2l((long double)x);
    const long double scale = fabsl(truth) > 1.0L ? fabsl(truth) : 1.0L;
    const long double error = fabsl((long double)l - truth) / scale;
    if (error > worst) {
      worst = error;
      worst_at = x;
    }
    mix(l);
    count++;
  };
  for (int i = 0; i < 3000000; i++) {
    uint64_t bits = ((uint64_t)next() << 20) | (next() >> 12);
    bits = (bits & 0x000FFFFFFFFFFFFFull) | 0x3FF0000000000000ull;
    double mantissa;
    memcpy(&mantissa, &bits, sizeof(mantissa));
    const uint32_t r = next();
    check_log(ldexp(mantissa, i % 3 == 0 ? (int)(r % 2098) - 1075 : (int)(r % 80) - 70));
  }
  for (int e = -1074; e <= 1023; e++) {
    const double power = ldexp(1.0, e);
    check_log(power);
    check_log(nextafter(power, 0.0));
    check_log(nextafter(power, HUGE_VAL));
  }
  for (int i = 0; i <= 128; i++) {
    const double edge = 1.0 + (double)i / 128.0;
    for (double x : {nextafter(edge, 0.0), edge, nextafter(edge, 4.0)}) {
      check_log(x);
      check_log(x * 0.5);
      check_log(x * 0x1p-20);
    }
  }
  CHECK(worst <= log_bound,
        "flac_log2 is within %.3Le times max(1, |log2 x|) of the logarithm (%ld arguments; worst "
        "%.3Le at %a)",
        log_bound, count, worst, worst_at);
  for (int e : {-1074, -1022, -52, -1, 0, 1, 10, 1023}) {
    CHECK(flac_log2(ldexp(1.0, e)) == (double)e, "flac_log2(2^%d) is %d", e, e);
  }
  const uint64_t log_hash = hash;

  if (print) {
    printf("cos %016llx log2 %016llx\n", (unsigned long long)cos_hash,
           (unsigned long long)log_hash);
    return;
  }
  CHECK(cos_hash == 0x29b359da0f88160full,
        "flac_cos and the windows made with it are the pinned values, bit for bit (hash %016llx)",
        (unsigned long long)cos_hash);
  CHECK(log_hash == 0xf40d72d11db6a4bbull,
        "flac_log2 returns the pinned values, bit for bit (hash %016llx)",
        (unsigned long long)log_hash);
}

int main(int argc, char** argv) {
  if (argc > 1 && strcmp(argv[1], "--print-analysis-hashes") == 0) {
    test_analysis_functions(true);
    return 0;
  }
  test_analysis_functions(false);
  test_md5();
  test_crc();
  test_coded_numbers();
  test_rfc_examples();
  test_streaminfo_limits();
  test_frame_header_roundtrip();
  test_frame_header_rejects();
  test_bits();
  return test_summary("test_flac_format");
}
