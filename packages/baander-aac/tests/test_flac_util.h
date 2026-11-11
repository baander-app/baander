/* Shared helpers for the lossless-mode tests: the example streams of
 * RFC 9639 Appendix D with the samples the appendix derives from them,
 * integer test signals, a writer that builds FLAC streams by hand from
 * a description of each subframe, and a driver over the public decoder
 * API.  The writer uses the library's internal bit writer and header
 * routines, so the tests that include this header add src/ to their
 * include path. */
#ifndef BAANDER_AAC_TEST_FLAC_UTIL_H
#define BAANDER_AAC_TEST_FLAC_UTIL_H

#include "flac.h"
#include "flac_internal.h"
#include "test_util.h"

/* Bytes from a string of hexadecimal digits; blanks are skipped. */
static std::vector<uint8_t> hex_bytes(const char* hex) {
  std::vector<uint8_t> out;
  int have = 0;
  unsigned value = 0;
  for (const char* p = hex; *p; p++) {
    int digit;
    if (*p >= '0' && *p <= '9') {
      digit = *p - '0';
    } else if (*p >= 'a' && *p <= 'f') {
      digit = *p - 'a' + 10;
    } else if (*p >= 'A' && *p <= 'F') {
      digit = *p - 'A' + 10;
    } else {
      continue;
    }
    value = (value << 4) | (unsigned)digit;
    if (++have == 2) {
      out.push_back((uint8_t)value);
      have = 0;
      value = 0;
    }
  }
  return out;
}

static std::string hex_string(const uint8_t* data, size_t size) {
  static const char* digits = "0123456789abcdef";
  std::string s;
  for (size_t i = 0; i < size; i++) {
    s += digits[data[i] >> 4];
    s += digits[data[i] & 15];
  }
  return s;
}

/* ── RFC 9639 Appendix D ───────────────────────────────────────── */

/* Example 1 (D.1): two channels of one sample each in verbatim
 * subframes with wasted bits. */
static std::vector<uint8_t> rfc9639_example_1() {
  return hex_bytes(
      "664c 6143 8000 0022 1000 1000"
      "0000 0f00 000f 0ac4 42f0 0000"
      "0001 3e84 b418 07dc 6903 0758"
      "6a3d ad1a 2e0f fff8 6918 0000"
      "bf03 58fd 0312 8baa 9a");
}

/* Example 2 (D.2): a seek table, a Vorbis comment and padding; a
 * side-right frame of 16 samples with fixed predictors and a second
 * frame of 3 samples. */
static std::vector<uint8_t> rfc9639_example_2() {
  return hex_bytes(
      "664c 6143 0000 0022 0010 0010"
      "0000 1700 0044 0ac4 42f0 0000"
      "0013 d5b0 5649 75e9 8b8d 8b93"
      "0422 757b 8103 0300 0012 0000"
      "0000 0000 0000 0000 0000 0000"
      "0000 0010 0400 003a 2000 0000"
      "7265 6665 7265 6e63 6520 6c69"
      "6246 4c41 4320 312e 332e 3320"
      "3230 3139 3038 3034 0100 0000"
      "0e00 0000 5449 544c 453d d7a9"
      "d79c d795 d79d 8100 0006 0000"
      "0000 0000 fff8 6998 000f 9912"
      "0867 0162 3d14 4299 8f5d f70d"
      "6fe0 0c17 caeb 2100 0ee7 a77a"
      "24a1 590c 1217 b603 097b 784f"
      "aa9a 33d2 85e0 70ad 5b1b 4851"
      "b401 0d99 d2cd 1a68 f1e6 b810"
      "fff8 6918 0102 a402 c382 c40b"
      "c14a 03ee 48dd 03b6 7c13 30");
}

/* The samples of example 2 as D.2.9 lists them for the MD5: interleaved
 * 16-bit values, least significant byte first. */
static std::vector<int32_t> rfc9639_example_2_samples() {
  const std::vector<uint8_t> raw = hex_bytes(
      "8428 B617 7946 3129 5E3A 2722 D445 D128 0B3D B723 EB45 DF28"
      "723f 1E25 9D46 4929 B841 7026 5747 B829 8F43 8127 AEC7 14DF"
      "9FC4 41DD 54C7 E4DE A5C4 40DD 1EC6 33DE 82C3 90DC 0BC4 02DD"
      "4AC1 3EDB");
  std::vector<int32_t> out;
  for (size_t i = 0; i + 1 < raw.size(); i += 2) {
    out.push_back((int16_t)(raw[i] | (raw[i + 1] << 8)));
  }
  return out;
}

/* Example 3 (D.3): one mono frame of 24 samples at 8 bits with a
 * third-order linear predictor and four residual partitions, one of
 * them escaped. */
static std::vector<uint8_t> rfc9639_example_3() {
  return hex_bytes(
      "664c 6143 8000 0022 1000 1000"
      "0000 1f00 001f 07d0 0070 0000"
      "0018 f8f9 e396 f5cb cfc6 dc80"
      "7f99 7790 6b32 fff8 6802 0017"
      "e944 004f 6f31 3d10 47d2 27cb"
      "6d09 0831 452b dc28 2222 8057"
      "a3");
}

/* ── integer test signals ──────────────────────────────────────── */

/* The value range of a bit depth. */
static int64_t flac_sample_max(int bits) { return ((int64_t)1 << (bits - 1)) - 1; }
static int64_t flac_sample_min(int bits) { return -((int64_t)1 << (bits - 1)); }

/* Tones and a little noise at `bits` per sample, a different pitch per
 * channel, interleaved.  `level` is the peak as a fraction of full
 * scale, `noise_level` the noise relative to that peak. */
static std::vector<int32_t> flac_gen_tones(int n, int channels, int bits, double level = 0.5,
                                           unsigned seed = 2024u, double noise_level = 0.02) {
  std::vector<int32_t> pcm((size_t)n * (size_t)channels);
  const double scale = level * (double)flac_sample_max(bits);
  for (int i = 0; i < n; i++) {
    for (int c = 0; c < channels; c++) {
      seed = seed * 1664525u + 1013904223u;
      const double noise = ((double)(seed >> 8) / 8388608.0 - 1.0) * noise_level;
      const double v = 0.7 * sin(2.0 * M_PI * (double)i * (0.011 + 0.007 * c)) +
                       0.25 * sin(2.0 * M_PI * (double)i * (0.093 + 0.011 * c)) + noise;
      const double x = std::max(-1.0, std::min(1.0, v)) * scale;
      pcm[(size_t)i * (size_t)channels + (size_t)c] = (int32_t)llround(x);
    }
  }
  return pcm;
}

/* White noise over the whole range of `bits` per sample. */
static std::vector<int32_t> flac_gen_noise(int n, int channels, int bits, unsigned seed = 77u) {
  std::vector<int32_t> pcm((size_t)n * (size_t)channels);
  for (int32_t& v : pcm) {
    seed = seed * 1664525u + 1013904223u;
    uint32_t x = seed;
    seed = seed * 1664525u + 1013904223u;
    x ^= seed >> 16;
    v = (int32_t)x >> (32 - bits);
  }
  return pcm;
}

/* One channel of an interleaved signal. */
static std::vector<int64_t> flac_channel(const std::vector<int32_t>& pcm, int channels, int channel,
                                         size_t from = 0, size_t count = SIZE_MAX) {
  std::vector<int64_t> out;
  const size_t total = pcm.size() / (size_t)channels;
  for (size_t i = from; i < total && i - from < count; i++) {
    out.push_back(pcm[i * (size_t)channels + (size_t)channel]);
  }
  return out;
}

/* ── a FLAC stream written by hand ─────────────────────────────── */

/* How one subframe is to be coded.  The writer does what it is told
 * and searches nothing but the Rice parameters it was not given. */
struct TestSubframe {
  enum Type { kConstant, kVerbatim, kFixed, kLpc };
  Type type = kVerbatim;
  int order = 0;
  int precision = 0;      /* LPC: bits per coefficient */
  int shift = 0;          /* LPC: right shift of the prediction */
  std::vector<int> coefs; /* LPC: `order` coefficients, the nearest sample first */
  int wasted = 0;         /* low zero bits of every sample that are not coded */
  int parameter_bits = 4; /* width of a Rice parameter: 4 or 5 */
  int partition_order = 0;
  int rice = -1;        /* Rice parameter of every partition; -1 takes the cheapest */
  int escape = -1;      /* the partition written unencoded; -1 none, -2 all */
  int escape_bits = -1; /* bits per value of an escaped partition; -1 takes the fewest */

  static TestSubframe constant() {
    TestSubframe s;
    s.type = kConstant;
    return s;
  }
  static TestSubframe verbatim() { return TestSubframe(); }
  static TestSubframe fixed(int order, int partition_order = 0) {
    TestSubframe s;
    s.type = kFixed;
    s.order = order;
    s.partition_order = partition_order;
    return s;
  }
  static TestSubframe lpc(std::vector<int> coefs, int precision, int shift,
                          int partition_order = 0) {
    TestSubframe s;
    s.type = kLpc;
    s.order = (int)coefs.size();
    s.coefs = std::move(coefs);
    s.precision = precision;
    s.shift = shift;
    s.partition_order = partition_order;
    return s;
  }
};

struct TestFrame {
  int block_size = 0;
  int bits = 16; /* the depth of the samples */
  int sample_rate = 44100;
  bool defer_rate = false; /* the header leaves the sample rate to STREAMINFO */
  bool defer_bits = false; /* the header leaves the bit depth to STREAMINFO */
  int assignment = FLAC_ASSIGN_INDEPENDENT;
  bool variable_block_size = false;
  uint64_t number = 0; /* frame number, or sample number with a variable block size */
  std::vector<TestSubframe> subframes; /* one per channel, in the order they are stored */
  size_t capacity = 0;                 /* bytes the frame may take; 0 allows 9 bytes per sample */
};

static void flac_test_fail(const char* what) {
  printf("FAIL test stream writer: %s\n", what);
  g_failures++;
}

/* Bits a two's complement value needs. */
static int flac_signed_bits(int64_t v) {
  int bits = 1;
  while (v < -((int64_t)1 << (bits - 1)) || v > ((int64_t)1 << (bits - 1)) - 1) {
    bits++;
  }
  return bits;
}

static uint64_t flac_fold(int64_t residual) {
  return residual >= 0 ? (uint64_t)residual << 1 : (((uint64_t)(-(residual + 1))) << 1) | 1;
}

static void flac_write_residual(FlacBitWriter* w, const TestSubframe& sf,
                                const std::vector<int64_t>& residual, int block_size) {
  const int max_rice = sf.parameter_bits == 5 ? 30 : 14;
  const int partitions = 1 << sf.partition_order;
  const int partition_size = block_size >> sf.partition_order;
  if (partition_size * partitions != block_size || partition_size < sf.order) {
    flac_test_fail("the partition order does not divide the block");
  }
  flac_bitwriter_write(w, sf.parameter_bits == 5 ? 1 : 0, 2);
  flac_bitwriter_write(w, (uint32_t)sf.partition_order, 4);
  size_t pos = 0;
  for (int p = 0; p < partitions; p++) {
    const int count = p ? partition_size : partition_size - sf.order;
    if (sf.escape == -2 || sf.escape == p) {
      int width = 0;
      for (int i = 0; i < count; i++) {
        if (residual[pos + (size_t)i] != 0) {
          width = std::max(width, flac_signed_bits(residual[pos + (size_t)i]));
        }
      }
      if (sf.escape_bits >= 0) {
        width = sf.escape_bits;
      }
      flac_bitwriter_write(w, (1u << sf.parameter_bits) - 1, sf.parameter_bits);
      flac_bitwriter_write(w, (uint32_t)width, 5);
      for (int i = 0; i < count && width; i++) {
        flac_bitwriter_write_signed(w, residual[pos + (size_t)i], width);
      }
    } else {
      int k = sf.rice;
      if (k < 0) {
        uint64_t best = UINT64_MAX;
        for (int trial = 0; trial <= max_rice; trial++) {
          uint64_t bits = 0;
          for (int i = 0; i < count; i++) {
            bits += (flac_fold(residual[pos + (size_t)i]) >> trial) + 1 + (uint64_t)trial;
          }
          if (bits < best) {
            best = bits;
            k = trial;
          }
        }
      }
      flac_bitwriter_write(w, (uint32_t)k, sf.parameter_bits);
      for (int i = 0; i < count; i++) {
        const uint64_t folded = flac_fold(residual[pos + (size_t)i]);
        flac_bitwriter_write_unary(w, (uint32_t)(folded >> k));
        flac_bitwriter_write(w, (uint32_t)(folded & (((uint64_t)1 << k) - 1)), k);
      }
    }
    pos += (size_t)count;
  }
}

/* Writes one subframe holding `samples` at `bps` bits. */
static void flac_write_subframe(FlacBitWriter* w, const TestSubframe& sf,
                                std::vector<int64_t> samples, int bps) {
  const int n = (int)samples.size();
  int type_code = 1;
  if (sf.type == TestSubframe::kConstant) {
    type_code = 0;
  } else if (sf.type == TestSubframe::kFixed) {
    type_code = 8 + sf.order;
  } else if (sf.type == TestSubframe::kLpc) {
    type_code = 31 + sf.order;
  }
  flac_bitwriter_write(w, 0, 1);
  flac_bitwriter_write(w, (uint32_t)type_code, 6);
  flac_bitwriter_write(w, sf.wasted ? 1 : 0, 1);
  if (sf.wasted) {
    flac_bitwriter_write_unary(w, (uint32_t)sf.wasted - 1);
    for (int64_t& v : samples) {
      if (v & (((int64_t)1 << sf.wasted) - 1)) {
        flac_test_fail("a sample has set bits among its wasted bits");
      }
      v >>= sf.wasted;
    }
    bps -= sf.wasted;
  }
  for (int64_t v : samples) {
    if (v < flac_sample_min(bps) || v > flac_sample_max(bps)) {
      flac_test_fail("a sample does not fit the subframe's depth");
      break;
    }
  }
  if (sf.type == TestSubframe::kConstant) {
    for (int64_t v : samples) {
      if (v != samples[0]) {
        flac_test_fail("a constant subframe of samples that differ");
        break;
      }
    }
    flac_bitwriter_write_signed(w, samples[0], bps);
    return;
  }
  if (sf.type == TestSubframe::kVerbatim) {
    for (int64_t v : samples) {
      flac_bitwriter_write_signed(w, v, bps);
    }
    return;
  }
  for (int i = 0; i < sf.order; i++) {
    flac_bitwriter_write_signed(w, samples[(size_t)i], bps);
  }
  static const int kFixedWeights[5][4] = {
      {0, 0, 0, 0}, {1, 0, 0, 0}, {2, -1, 0, 0}, {3, -3, 1, 0}, {4, -6, 4, -1}};
  std::vector<int64_t> residual;
  for (int i = sf.order; i < n; i++) {
    int64_t prediction = 0;
    for (int j = 0; j < sf.order; j++) {
      const int64_t weight =
          sf.type == TestSubframe::kLpc ? sf.coefs[(size_t)j] : kFixedWeights[sf.order][j];
      prediction += weight * samples[(size_t)(i - 1 - j)];
    }
    if (sf.type == TestSubframe::kLpc) {
      prediction >>= sf.shift;
    }
    const int64_t r = samples[(size_t)i] - prediction;
    if (r <= -((int64_t)1 << 31) || r >= ((int64_t)1 << 31)) {
      flac_test_fail("a residual outside 32 bits");
    }
    residual.push_back(r);
  }
  if (sf.type == TestSubframe::kLpc) {
    flac_bitwriter_write(w, (uint32_t)sf.precision - 1, 4);
    flac_bitwriter_write(w, (uint32_t)sf.shift & 31, 5);
    for (int c : sf.coefs) {
      if (flac_signed_bits(c) > sf.precision) {
        flac_test_fail("a coefficient wider than the precision");
      }
      flac_bitwriter_write_signed(w, c, sf.precision);
    }
  }
  flac_write_residual(w, sf, residual, n);
}

/* One frame from the samples of its channels (channels[c][i], left and
 * right for a stereo assignment), with its header and both checksums. */
static std::vector<uint8_t> flac_build_frame(const TestFrame& f,
                                             const std::vector<std::vector<int64_t>>& channels) {
  FlacFrameHeader h = {};
  h.variable_block_size = f.variable_block_size;
  h.block_size = f.block_size;
  h.sample_rate = f.defer_rate ? 0 : f.sample_rate;
  h.channels = (int)channels.size();
  h.assignment = f.assignment;
  h.bits_per_sample = f.defer_bits ? 0 : f.bits;
  h.number = f.number;
  std::vector<uint8_t> out(f.capacity ? f.capacity
                                      : FLAC_MAX_FRAME_HEADER_BYTES +
                                            channels.size() * ((size_t)f.block_size * 9 + 1024));
  const int header_bytes = flac_frame_header_write(&h, out.data());
  if (header_bytes < 0 || f.subframes.size() != channels.size()) {
    flac_test_fail("a frame header that cannot be written");
    return {};
  }
  FlacBitWriter w;
  flac_bitwriter_init(&w, out.data() + header_bytes, out.size() - (size_t)header_bytes - 2);
  for (size_t c = 0; c < channels.size(); c++) {
    if (channels[c].size() != (size_t)f.block_size) {
      flac_test_fail("a channel of the wrong length");
      return {};
    }
    std::vector<int64_t> coded = channels[c];
    int bps = f.bits;
    const bool side =
        (f.assignment == FLAC_ASSIGN_SIDE_RIGHT && c == 0) ||
        ((f.assignment == FLAC_ASSIGN_LEFT_SIDE || f.assignment == FLAC_ASSIGN_MID_SIDE) && c == 1);
    if (side) {
      for (size_t i = 0; i < coded.size(); i++) {
        coded[i] = channels[0][i] - channels[1][i];
      }
      bps++;
    } else if (f.assignment == FLAC_ASSIGN_MID_SIDE) {
      for (size_t i = 0; i < coded.size(); i++) {
        coded[i] = (channels[0][i] + channels[1][i]) >> 1;
      }
    }
    flac_write_subframe(&w, f.subframes[c], coded, bps);
  }
  const size_t body = flac_bitwriter_flush(&w);
  if (w.overflow) {
    flac_test_fail("a frame larger than the writer's buffer");
    return {};
  }
  out.resize((size_t)header_bytes + body);
  const uint16_t crc = flac_crc16(out.data(), out.size());
  out.push_back((uint8_t)(crc >> 8));
  out.push_back((uint8_t)crc);
  return out;
}

/* A metadata block: its header and `body`. */
static std::vector<uint8_t> flac_metadata_block(int type, const std::vector<uint8_t>& body,
                                                bool last) {
  std::vector<uint8_t> out = {(uint8_t)((last ? 0x80 : 0) | type), (uint8_t)(body.size() >> 16),
                              (uint8_t)(body.size() >> 8), (uint8_t)body.size()};
  out.insert(out.end(), body.begin(), body.end());
  return out;
}

static BaacFlacStreamInfo flac_test_info(int sample_rate, int channels, int bits, int block_size,
                                         uint64_t total_samples = 0) {
  BaacFlacStreamInfo info = {};
  info.min_block_size = block_size;
  info.max_block_size = block_size;
  info.sample_rate = sample_rate;
  info.channels = channels;
  info.bits_per_sample = bits;
  info.total_samples = total_samples;
  return info;
}

static std::vector<uint8_t> flac_streaminfo_bytes(const BaacFlacStreamInfo& info) {
  std::vector<uint8_t> body(BAAC_FLAC_STREAMINFO_BYTES);
  if (!flac_streaminfo_write(&info, body.data())) {
    flac_test_fail("a STREAMINFO that cannot be written");
  }
  return body;
}

/* The marker and a STREAMINFO block, which is the last block unless
 * `more_blocks` follow. */
static std::vector<uint8_t> flac_stream_start(const BaacFlacStreamInfo& info,
                                              bool more_blocks = false) {
  std::vector<uint8_t> out = {'f', 'L', 'a', 'C'};
  const std::vector<uint8_t> block =
      flac_metadata_block(FLAC_BLOCK_STREAMINFO, flac_streaminfo_bytes(info), !more_blocks);
  out.insert(out.end(), block.begin(), block.end());
  return out;
}

static void flac_append(std::vector<uint8_t>& stream, const std::vector<uint8_t>& part) {
  stream.insert(stream.end(), part.begin(), part.end());
}

/* The MD5 a STREAMINFO states for interleaved samples. */
static void flac_pcm_md5(const std::vector<int32_t>& pcm, int bits, uint8_t digest[16]) {
  FlacMd5 m;
  flac_md5_init(&m);
  flac_md5_update_pcm(&m, pcm.data(), pcm.size(), bits);
  flac_md5_final(&m, digest);
}

/* A whole stream of independent channels in frames of `block_size`,
 * every subframe coded as `sf`, with the total and the MD5 in
 * STREAMINFO.  `frame_offsets`, when given, receives the byte offset of
 * each frame and, last, the length of the stream. */
static std::vector<uint8_t> flac_build_stream(const std::vector<int32_t>& pcm, int sample_rate,
                                              int channels, int bits, int block_size,
                                              const TestSubframe& sf,
                                              std::vector<size_t>* frame_offsets = nullptr) {
  const size_t total = pcm.size() / (size_t)channels;
  BaacFlacStreamInfo info = flac_test_info(sample_rate, channels, bits, block_size, total);
  flac_pcm_md5(pcm, bits, info.md5);
  std::vector<uint8_t> stream = flac_stream_start(info);
  uint64_t number = 0;
  for (size_t pos = 0; pos < total; pos += (size_t)block_size) {
    TestFrame f;
    f.block_size = (int)std::min<size_t>((size_t)block_size, total - pos);
    f.bits = bits;
    f.sample_rate = sample_rate;
    f.number = number++;
    f.subframes.assign((size_t)channels, sf);
    std::vector<std::vector<int64_t>> rows;
    for (int c = 0; c < channels; c++) {
      rows.push_back(flac_channel(pcm, channels, c, pos, (size_t)f.block_size));
    }
    if (frame_offsets) {
      frame_offsets->push_back(stream.size());
    }
    flac_append(stream, flac_build_frame(f, rows));
  }
  if (frame_offsets) {
    frame_offsets->push_back(stream.size());
  }
  return stream;
}

/* ── decoding through the public API ───────────────────────────── */

struct FlacDecodeEvent {
  int code;             /* the negative return of a decode call */
  size_t frames_before; /* frames decoded before it */
};

struct FlacDecoded {
  std::vector<int32_t> pcm; /* every decoded frame, interleaved */
  std::vector<BaacFlacFrameInfo> frames;
  std::vector<FlacDecodeEvent> errors;
  bool have_info = false;
  BaacFlacStreamInfo info = {};
  int finish = AAC_OK;
  BaacFlacMd5Verdict verdict = BAAC_FLAC_MD5_UNKNOWN;
  int lost = 0;
  int max_pending = 0; /* the most bytes the FIFO held */
};

/* Drains every frame that is ready into `out`.  Returns false when the
 * decoder stopped for good. */
static bool flac_drain(BaacFlacDecoder* dec, std::vector<int32_t>& buf, FlacDecoded* out) {
  for (;;) {
    const int n = baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size());
    if (n == 0) {
      return true;
    }
    if (n < 0) {
      out->errors.push_back({n, out->frames.size()});
      if (n != AAC_ERR_DECODE) {
        return false;
      }
      continue;
    }
    BaacFlacStreamInfo info;
    BaacFlacFrameInfo frame = {};
    if (baac_flac_decoder_stream_info(dec, &info) != AAC_OK ||
        baac_flac_decoder_last_frame_info(dec, &frame) != AAC_OK || frame.block_size != n) {
      flac_test_fail("no frame or stream information after a decoded frame");
      return false;
    }
    out->frames.push_back(frame);
    out->pcm.insert(out->pcm.end(), buf.begin(), buf.begin() + (long)n * info.channels);
  }
}

/* Decodes a whole stream fed in pieces of `chunk` bytes, with MD5
 * verification on, and ends it. */
static FlacDecoded flac_decode(const std::vector<uint8_t>& stream, size_t chunk = SIZE_MAX,
                               BaacFlacDecoder* existing = nullptr) {
  FlacDecoded out;
  BaacFlacDecoder* dec = existing ? existing : baac_flac_decoder_create();
  if (!dec) {
    flac_test_fail("no decoder");
    return out;
  }
  if (!existing) {
    baac_flac_decoder_set_md5_check(dec, 1);
  }
  std::vector<int32_t> buf((size_t)BAAC_FLAC_MAX_BLOCK_SIZE * BAAC_FLAC_MAX_CHANNELS);
  bool alive = true;
  size_t pos = 0;
  while (alive && pos < stream.size()) {
    const int want = (int)std::min(chunk, stream.size() - pos);
    const int took = baac_flac_decoder_feed(dec, stream.data() + pos, want);
    if (took < 0) {
      flac_test_fail("the decoder refused input");
      break;
    }
    pos += (size_t)took;
    out.max_pending = std::max(out.max_pending, baac_flac_decoder_pending_bytes(dec));
    const size_t frames = out.frames.size();
    const size_t errors = out.errors.size();
    alive = flac_drain(dec, buf, &out);
    if (alive && took == 0 && frames == out.frames.size() && errors == out.errors.size()) {
      flac_test_fail("the decoder neither takes input nor produces output");
      break;
    }
  }
  if (alive) {
    baac_flac_decoder_end_input(dec);
    flac_drain(dec, buf, &out);
  }
  out.have_info = baac_flac_decoder_stream_info(dec, &out.info) == AAC_OK;
  out.finish = baac_flac_decoder_finish(dec, &out.verdict);
  out.lost = baac_flac_decoder_lost_frames(dec);
  if (!existing) {
    baac_flac_decoder_destroy(dec);
  }
  return out;
}

#endif /* BAANDER_AAC_TEST_FLAC_UTIL_H */
