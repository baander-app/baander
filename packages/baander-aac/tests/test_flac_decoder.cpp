/* The FLAC decoder on streams written by hand, so that every subframe
 * type, residual form, stereo assignment, bit depth and block size is
 * decoded from a known description to known samples, and on damaged,
 * truncated and unusual streams: the worked examples of RFC 9639
 * Appendix D, feeding in any chunking, resynchronisation after damage,
 * streams that begin at a frame, property changes, metadata that is
 * skipped without being buffered, the MD5 verdict, seeking, and the
 * bounds the decoder keeps on its memory. */
#include <cstdlib>
#include <new>

#include "test_flac_util.h"

/* ── allocation counting ───────────────────────────────────────── */

static long g_allocations = 0;
static size_t g_allocated_bytes = 0;

void* operator new(size_t size) {
  g_allocations++;
  g_allocated_bytes += size;
  void* p = malloc(size ? size : 1);
  if (!p) {
    throw std::bad_alloc();
  }
  return p;
}
void* operator new[](size_t size) { return operator new(size); }
void* operator new(size_t size, const std::nothrow_t&) noexcept {
  g_allocations++;
  g_allocated_bytes += size;
  return malloc(size ? size : 1);
}
void* operator new[](size_t size, const std::nothrow_t& tag) noexcept {
  return operator new(size, tag);
}
void operator delete(void* p) noexcept { free(p); }
void operator delete[](void* p) noexcept { free(p); }
void operator delete(void* p, size_t) noexcept { free(p); }
void operator delete[](void* p, size_t) noexcept { free(p); }

/* ── helpers ───────────────────────────────────────────────────── */

/* Collects frames into a stream and keeps the samples they hold. */
struct StreamBuilder {
  int sample_rate;
  int channels;
  int bits;
  bool variable_block_size = false;
  std::vector<uint8_t> frames;
  std::vector<size_t> offsets; /* of each frame within `frames` */
  std::vector<int> blocks;
  std::vector<int32_t> pcm;
  uint64_t frame_number = 0;
  uint64_t samples = 0;

  StreamBuilder(int rate, int ch, int depth) : sample_rate(rate), channels(ch), bits(depth) {}

  /* Appends a frame of `block` (interleaved); `f` says how it is coded.
   * Above 16 bits the residuals outgrow a 4-bit Rice parameter, so a
   * subframe that leaves the parameter to the writer gets the 5-bit
   * form. */
  void add(TestFrame f, const std::vector<int32_t>& block) {
    for (TestSubframe& sf : f.subframes) {
      if (bits > 16 && sf.rice < 0) {
        sf.parameter_bits = 5;
      }
    }
    f.block_size = (int)(block.size() / (size_t)channels);
    f.bits = bits;
    f.sample_rate = sample_rate;
    f.variable_block_size = variable_block_size;
    f.number = variable_block_size ? samples : frame_number;
    std::vector<std::vector<int64_t>> rows;
    for (int c = 0; c < channels; c++) {
      rows.push_back(flac_channel(block, channels, c));
    }
    offsets.push_back(frames.size());
    blocks.push_back(f.block_size);
    flac_append(frames, flac_build_frame(f, rows));
    pcm.insert(pcm.end(), block.begin(), block.end());
    frame_number++;
    samples += (uint64_t)f.block_size;
  }

  /* Every channel coded the same way. */
  void add(const TestSubframe& sf, const std::vector<int32_t>& block,
           int assignment = FLAC_ASSIGN_INDEPENDENT) {
    TestFrame f;
    f.assignment = assignment;
    f.subframes.assign((size_t)channels, sf);
    add(f, block);
  }

  BaacFlacStreamInfo info() const {
    int max_block = 16;
    int min_block = 65535;
    for (size_t i = 0; i < blocks.size(); i++) {
      max_block = std::max(max_block, blocks[i]);
      if (i + 1 < blocks.size()) {
        min_block = std::min(min_block, blocks[i]);
      }
    }
    BaacFlacStreamInfo info = flac_test_info(sample_rate, channels, bits, max_block, samples);
    info.min_block_size = std::max(16, std::min(min_block, max_block));
    flac_pcm_md5(pcm, bits, info.md5);
    return info;
  }

  std::vector<uint8_t> stream() const {
    std::vector<uint8_t> s = flac_stream_start(info());
    flac_append(s, frames);
    return s;
  }
};

static std::vector<int32_t> slice(const std::vector<int32_t>& pcm, int channels, size_t from,
                                  size_t count) {
  const size_t total = pcm.size() / (size_t)channels;
  const size_t n = from < total ? std::min(count, total - from) : 0;
  return std::vector<int32_t>(pcm.begin() + (long)(from * (size_t)channels),
                              pcm.begin() + (long)((from + n) * (size_t)channels));
}

/* A stream decoded without a complaint to exactly `pcm`. */
static void expect_clean(const char* name, const FlacDecoded& d, const std::vector<int32_t>& pcm,
                         BaacFlacMd5Verdict verdict = BAAC_FLAC_MD5_MATCH) {
  CHECK(d.errors.empty(), "%s: %zu decode errors, the first %d after %zu frames", name,
        d.errors.size(), d.errors.empty() ? 0 : d.errors[0].code,
        d.errors.empty() ? (size_t)0 : d.errors[0].frames_before);
  CHECK(d.finish == AAC_OK && d.lost == 0, "%s: finish %d, %d lost", name, d.finish, d.lost);
  CHECK(d.pcm.size() == pcm.size(), "%s: %zu values decoded of %zu", name, d.pcm.size(),
        pcm.size());
  if (d.pcm.size() == pcm.size()) {
    size_t bad = 0;
    while (bad < pcm.size() && d.pcm[bad] == pcm[bad]) {
      bad++;
    }
    CHECK(bad == pcm.size(), "%s: value %zu is %d, expected %d", name, bad,
          bad < pcm.size() ? d.pcm[bad] : 0, bad < pcm.size() ? pcm[bad] : 0);
  }
  CHECK(d.verdict == verdict, "%s: MD5 verdict %d, expected %d", name, (int)d.verdict,
        (int)verdict);
}

/* Linear prediction coefficients that are arbitrary but keep the
 * prediction within a few times the signal: full use of `precision`
 * bits, alternating in sign, shifted back down by `precision` - 1. */
static std::vector<int> test_coefs(int order, int precision, unsigned seed) {
  std::vector<int> coefs;
  const int limit = 1 << (precision - 1);
  for (int j = 0; j < order; j++) {
    seed = seed * 1664525u + 1013904223u;
    const int magnitude = (int)((seed >> 12) % (unsigned)limit);
    coefs.push_back(j % 2 ? -magnitude - 1 : magnitude);
  }
  return coefs;
}

static TestSubframe test_lpc(int order, int precision, unsigned seed, int partition_order = 0) {
  return TestSubframe::lpc(test_coefs(order, precision, seed), precision,
                           std::min(15, precision - 1), partition_order);
}

/* ── the examples of RFC 9639 Appendix D ───────────────────────── */

static void test_rfc_examples() {
  /* D.1: one sample per channel; the appendix derives 25588 and 10416. */
  FlacDecoded d = flac_decode(rfc9639_example_1());
  expect_clean("example 1", d, {25588, 10416});
  CHECK(d.frames.size() == 1 && d.frames[0].block_size == 1 && d.frames[0].bytes == 15 &&
            d.frames[0].sample_position == 0,
        "example 1: one frame of one sample in 15 bytes");

  /* D.2: a side-right frame with fixed predictors and a short second
   * frame; D.2.9 lists every sample. */
  d = flac_decode(rfc9639_example_2());
  expect_clean("example 2", d, rfc9639_example_2_samples());
  CHECK(d.frames.size() == 2 && d.frames[0].block_size == 16 && d.frames[0].bytes == 68 &&
            d.frames[1].block_size == 3 && d.frames[1].bytes == 23 &&
            d.frames[1].sample_position == 16,
        "example 2: frames of 16 and 3 samples in 68 and 23 bytes");
  CHECK(d.have_info && d.info.total_samples == 19 && d.info.min_frame_bytes == 23 &&
            d.info.max_frame_bytes == 68 && d.info.sample_rate == 44100 && d.info.channels == 2 &&
            d.info.bits_per_sample == 16,
        "example 2: stream properties");
  /* The head of the stream, left before right. */
  CHECK(d.pcm.size() >= 8 && d.pcm[0] == 10372 && d.pcm[1] == 6070 && d.pcm[2] == 18041 &&
            d.pcm[3] == 10545,
        "example 2: the first samples are %d %d %d %d", d.pcm.size() >= 4 ? d.pcm[0] : 0,
        d.pcm.size() >= 4 ? d.pcm[1] : 0, d.pcm.size() >= 4 ? d.pcm[2] : 0,
        d.pcm.size() >= 4 ? d.pcm[3] : 0);

  /* D.3: a linear predictor of order 3 and four residual partitions,
   * the second escaped.  The appendix lists the first twelve samples and
   * leaves the rest to the MD5. */
  d = flac_decode(rfc9639_example_3());
  const int32_t head[12] = {0, 79, 111, 78, 8, -61, -90, -68, -13, 42, 67, 53};
  CHECK(d.pcm.size() == 24 && memcmp(d.pcm.data(), head, sizeof(head)) == 0,
        "example 3: the twelve samples of the appendix");
  CHECK(d.errors.empty() && d.finish == AAC_OK && d.verdict == BAAC_FLAC_MD5_MATCH,
        "example 3: the MD5 of all 24 samples matches");

  /* The seek table and the start of the audio of example 2, read
   * straight after the header was fed. */
  const std::vector<uint8_t> e2 = rfc9639_example_2();
  BaacFlacDecoder* dec = baac_flac_decoder_create();
  CHECK(baac_flac_decoder_first_frame_offset(dec) == AAC_ERR_STATE,
        "no audio offset before the header is read");
  CHECK(baac_flac_decoder_feed(dec, e2.data(), 0x88) == 0x88, "the header is taken");
  const BaacFlacSeekPoint* points = nullptr;
  CHECK(baac_flac_decoder_seek_points(dec, &points) == 1 && points &&
            points[0].sample_position == 0 && points[0].byte_offset == 0 &&
            points[0].block_size == 16,
        "example 2: one seek point to the first frame");
  CHECK(baac_flac_decoder_first_frame_offset(dec) == 0x88, "example 2: the audio starts at 0x88");
  BaacFlacStreamInfo info;
  CHECK(baac_flac_decoder_stream_info(dec, &info) == AAC_OK && info.total_samples == 19,
        "the stream properties are known once the header is fed");
  CHECK(baac_flac_decoder_pending_bytes(dec) == 0, "nothing of the metadata stays in the FIFO");
  baac_flac_decoder_destroy(dec);
}

/* ── every subframe type, residual form and assignment ─────────── */

static void test_subframe_types() {
  /* Four channels, one subframe type each: constant, verbatim, fixed
   * and linear prediction; the frames walk through the fixed orders and
   * a range of prediction orders and precisions.  At 16 bits a
   * precision of 12 with order 8 sums in 32 bits; order 32 with
   * precision 15 needs the 64-bit sum, as everything at 24 bits does. */
  for (int bits : {16, 24}) {
    StreamBuilder b(48000, 4, bits);
    const int block = 192;
    const struct {
      int fixed_order, lpc_order, precision;
    } frames[] = {{0, 1, 2}, {1, 2, 5}, {2, 8, 12}, {3, 12, 14}, {4, 32, 15}, {2, 31, 9}};
    int index = 0;
    for (const auto& fr : frames) {
      std::vector<int32_t> pcm = flac_gen_tones(block, 4, bits, 0.9, 100u + (unsigned)index);
      const std::vector<int32_t> noise = flac_gen_noise(block, 4, bits, 7u + (unsigned)index);
      for (int i = 0; i < block; i++) {
        pcm[(size_t)i * 4] = -1234 - index;                /* constant */
        pcm[(size_t)i * 4 + 1] = noise[(size_t)i * 4 + 1]; /* verbatim: full-scale noise */
      }
      TestFrame f;
      f.subframes = {TestSubframe::constant(), TestSubframe::verbatim(),
                     TestSubframe::fixed(fr.fixed_order),
                     test_lpc(fr.lpc_order, fr.precision, 5u + (unsigned)index)};
      b.add(f, pcm);
      index++;
    }
    char name[64];
    snprintf(name, sizeof(name), "one subframe of each type at %d bits", bits);
    expect_clean(name, flac_decode(b.stream()), b.pcm);
  }

  /* The 32-bit sum at its limit: 16 bits of sample, 12 of coefficient
   * and 4 of summation, with full-scale samples and coefficients whose
   * signs line up so that every product has the same sign. */
  {
    StreamBuilder b(44100, 1, 16);
    const int block = 256;
    for (int sign : {1, -1}) {
      std::vector<int32_t> pcm((size_t)block);
      std::vector<int> coefs;
      for (int j = 0; j < 16; j++) {
        coefs.push_back((j % 2 ? 1 : -1) * sign > 0 ? 2047 : -2048);
      }
      for (int i = 0; i < block; i++) {
        pcm[(size_t)i] = i % 2 ? 32767 : -32768;
      }
      /* The residual is as large as the prediction: stored unencoded. */
      TestSubframe sf = TestSubframe::lpc(coefs, 12, 0);
      sf.escape = -2;
      b.add(sf, pcm);
      sf.shift = 15;
      b.add(sf, pcm);
    }
    expect_clean("the 32-bit prediction sum at its limit", flac_decode(b.stream()), b.pcm);
  }

  /* The same limit reached with coefficients narrower than the precision
   * the subframe declares: 24 bits of sample, 4 bits of coefficient in a
   * declared 15, and 4 of summation.  The sum's width follows from the
   * coefficients that are there, and full-scale samples whose products
   * all have one sign must still come out right. */
  {
    StreamBuilder b(48000, 1, 24);
    const int block = 256;
    for (int sign : {1, -1}) {
      std::vector<int32_t> pcm((size_t)block);
      std::vector<int> coefs;
      for (int j = 0; j < 16; j++) {
        coefs.push_back((j % 2 ? 1 : -1) * sign > 0 ? 7 : -8);
      }
      for (int i = 0; i < block; i++) {
        pcm[(size_t)i] = i % 2 ? 8388607 : -8388608;
      }
      TestSubframe sf = TestSubframe::lpc(coefs, 15, 0);
      sf.escape = -2;
      b.add(sf, pcm);
      sf.shift = 3;
      b.add(sf, pcm);
      /* One coefficient more, or one bit more, and the sum needs 64
       * bits. */
      coefs.push_back(sign > 0 ? 7 : -8);
      b.add(TestSubframe::lpc(coefs, 15, 4), flac_gen_tones(block, 1, 24, 0.9, 6u));
      coefs.pop_back();
      coefs[0] = sign > 0 ? 15 : -16;
      b.add(TestSubframe::lpc(coefs, 15, 4), flac_gen_tones(block, 1, 24, 0.9, 7u));
    }
    expect_clean("narrow coefficients under a wide precision", flac_decode(b.stream()), b.pcm);
  }

  /* Rice parameters of 4 and 5 bits, every partition order a block of
   * 4096 allows, escaped partitions with and without bits, and a fixed
   * Rice parameter that does not fit the residual well. */
  {
    StreamBuilder b(44100, 2, 16);
    const int block = 4096;
    for (int partition_order = 0; partition_order <= 8; partition_order++) {
      for (int parameter_bits : {4, 5}) {
        TestSubframe sf = TestSubframe::fixed(2, partition_order);
        sf.parameter_bits = parameter_bits;
        if (partition_order % 3 == 1) {
          sf.escape = (1 << partition_order) - 1; /* the last partition unencoded */
        }
        if (partition_order == 5) {
          sf.rice = parameter_bits == 5 ? 22 : 2; /* far too wide, and too narrow */
        }
        b.add(sf, flac_gen_tones(block, 2, 16, 0.02, 31u + (unsigned)partition_order));
      }
    }
    /* Every partition escaped. */
    TestSubframe all = TestSubframe::fixed(1, 4);
    all.escape = -2;
    b.add(all, flac_gen_tones(block, 2, 16, 0.5, 9u));
    /* Digital silence through a predictor: escaped partitions of zero
     * bits per value, and Rice parameter zero. */
    all.escape_bits = 0;
    b.add(all, std::vector<int32_t>((size_t)block * 2, 0));
    b.add(TestSubframe::fixed(4, 8), std::vector<int32_t>((size_t)block * 2, 0));
    expect_clean("Rice partitions and escapes", flac_decode(b.stream()), b.pcm);
  }

  /* The largest partition order: 15, one value per partition, on a block
   * of 32768 (and one of 65535, which only order 0 divides). */
  {
    StreamBuilder b(96000, 1, 16);
    TestSubframe sf = TestSubframe::fixed(0, 15);
    b.add(sf, flac_gen_tones(32768, 1, 16, 0.3, 3u));
    sf.partition_order = 14;
    sf.order = 1; /* two values per partition, the first of them a warm-up sample */
    b.add(sf, flac_gen_tones(32768, 1, 16, 0.3, 4u));
    b.add(TestSubframe::fixed(3, 0), flac_gen_tones(65535, 1, 16, 0.3, 5u));
    expect_clean("partition order 15 and a block of 65535", flac_decode(b.stream()), b.pcm);
  }

  /* 5-bit Rice parameters up to 30: residuals of nearly 32 bits. */
  {
    StreamBuilder b(44100, 1, 32);
    std::vector<int32_t> pcm = flac_gen_noise(1024, 1, 32, 55u);
    for (int32_t& v : pcm) {
      v = std::max(v, INT32_MIN + 1); /* a residual may not be -2^31 */
    }
    TestSubframe sf = TestSubframe::fixed(0, 3);
    sf.parameter_bits = 5;
    b.add(sf, pcm);
    sf.rice = 30;
    b.add(sf, pcm);
    sf.rice = -1;
    sf.escape = -2; /* 32-bit values cannot be escaped in 31 bits, but 31-bit ones can */
    for (int32_t& v : pcm) {
      v >>= 1;
    }
    b.add(sf, pcm);
    expect_clean("Rice parameters up to 30", flac_decode(b.stream()), b.pcm);
  }

  /* Wasted bits: the low bits of every sample are zero and not coded,
   * per subframe, also under a stereo assignment. */
  {
    StreamBuilder b(44100, 2, 16);
    const int block = 576;
    for (int wasted : {1, 2, 8, 15}) {
      std::vector<int32_t> pcm = flac_gen_tones(block, 2, 16 - wasted, 0.9, 77u);
      for (int32_t& v : pcm) {
        v = (int32_t)((uint32_t)v << wasted);
      }
      TestFrame f;
      f.subframes = {TestSubframe::fixed(1), TestSubframe::verbatim()};
      f.subframes[0].wasted = wasted;
      f.subframes[1].wasted = wasted;
      b.add(f, pcm);
      /* Only one channel declares them. */
      f.subframes[1].wasted = 0;
      b.add(f, pcm);
      /* Left-side: the side channel has 17 bits, of which `wasted` go. */
      f.assignment = FLAC_ASSIGN_LEFT_SIDE;
      f.subframes[1].wasted = wasted;
      b.add(f, pcm);
    }
    /* A constant subframe with wasted bits. */
    TestFrame f;
    f.subframes = {TestSubframe::constant(), TestSubframe::constant()};
    f.subframes[0].wasted = 4;
    f.subframes[1].wasted = 9;
    std::vector<int32_t> pcm((size_t)block * 2);
    for (int i = 0; i < block; i++) {
      pcm[(size_t)i * 2] = -4096 + 16;
      pcm[(size_t)i * 2 + 1] = 512 * 37;
    }
    b.add(f, pcm);
    expect_clean("wasted bits", flac_decode(b.stream()), b.pcm);
  }
}

static void test_stereo() {
  /* Each assignment at each depth a frame header names, on full-scale
   * noise, so that the side channel uses its extra bit: at 32 bits it
   * has 33.  Verbatim subframes, then predicted ones on a quieter
   * signal. */
  for (int bits : {8, 12, 16, 20, 24, 32}) {
    StreamBuilder b(48000, 2, bits);
    const int block = 512;
    for (int assignment = FLAC_ASSIGN_INDEPENDENT; assignment <= FLAC_ASSIGN_MID_SIDE;
         assignment++) {
      b.add(TestSubframe::verbatim(), flac_gen_noise(block, 2, bits, 300u + (unsigned)assignment),
            assignment);
      const double level = bits == 32 ? 0.01 : 0.4;
      b.add(TestSubframe::fixed(2, 3), flac_gen_tones(block, 2, bits, level, 400u), assignment);
      b.add(test_lpc(8, 12, 12u, 2), flac_gen_tones(block, 2, bits, level, 500u), assignment);
    }
    char name[64];
    snprintf(name, sizeof(name), "stereo assignments at %d bits", bits);
    expect_clean(name, flac_decode(b.stream()), b.pcm);
  }

  /* 32 bits at the extremes: the most negative value and full-scale
   * alternation, where left minus right is ±(2^32 - 1). */
  {
    StreamBuilder b(44100, 2, 32);
    const int block = 64;
    std::vector<int32_t> pcm((size_t)block * 2);
    for (int i = 0; i < block; i++) {
      pcm[(size_t)i * 2] = i % 2 ? INT32_MAX : INT32_MIN;
      pcm[(size_t)i * 2 + 1] = i % 2 ? INT32_MIN : INT32_MAX;
    }
    for (int assignment = FLAC_ASSIGN_INDEPENDENT; assignment <= FLAC_ASSIGN_MID_SIDE;
         assignment++) {
      b.add(TestSubframe::verbatim(), pcm, assignment);
    }
    expect_clean("32-bit extremes", flac_decode(b.stream()), b.pcm);
  }

  /* A 33-bit side channel through the predictors: left near the top of
   * the range and right near the bottom, both moving slowly, so that
   * the side channel is large and its residual small. */
  {
    StreamBuilder b(44100, 2, 32);
    const int block = 1024;
    std::vector<int32_t> pcm((size_t)block * 2);
    unsigned seed = 5u;
    for (int i = 0; i < block; i++) {
      seed = seed * 1664525u + 1013904223u;
      pcm[(size_t)i * 2] = INT32_MAX - i * 3 - (int)(seed >> 29);
      pcm[(size_t)i * 2 + 1] = INT32_MIN + i * 5 + (int)((seed >> 26) & 7);
    }
    for (int assignment = FLAC_ASSIGN_LEFT_SIDE; assignment <= FLAC_ASSIGN_MID_SIDE; assignment++) {
      /* Order 0 is left out: the residual of a full-scale channel would
       * be the channel itself, which does not fit 32 bits here. */
      for (int order = 1; order <= 4; order++) {
        TestFrame f;
        f.assignment = assignment;
        f.subframes.assign(2, TestSubframe::fixed(order, 2));
        b.add(f, pcm);
      }
      TestFrame f;
      f.assignment = assignment;
      f.subframes.assign(2, TestSubframe::lpc({2, -1}, 3, 0, 1));
      b.add(f, pcm);
      f.subframes.assign(2, TestSubframe::lpc({16000, -8000, 0, 0, 1, -1}, 15, 13, 0));
      b.add(f, pcm);
      /* The side channel with wasted bits: both channels even. */
      std::vector<int32_t> even = pcm;
      for (int32_t& v : even) {
        v &= ~3;
      }
      const int side = assignment == FLAC_ASSIGN_SIDE_RIGHT ? 0 : 1;
      f.subframes.assign(2, TestSubframe::fixed(1));
      f.subframes[(size_t)side].wasted = 2;
      b.add(f, even);
      /* A constant side channel of 33 bits. */
      for (int i = 0; i < block; i++) {
        even[(size_t)i * 2 + 1] = (int32_t)((int64_t)even[(size_t)i * 2] - 4294960000ll);
      }
      f.subframes[(size_t)side] = TestSubframe::constant();
      b.add(f, even);
    }
    expect_clean("33-bit side channel through the predictors", flac_decode(b.stream()), b.pcm);
  }
}

static void test_layouts_and_depths() {
  /* One to eight channels, each coded differently, in the order of the
   * interleaved input. */
  for (int channels = 1; channels <= 8; channels++) {
    StreamBuilder b(48000, channels, 24);
    const int block = 1152;
    for (int frame = 0; frame < 3; frame++) {
      TestFrame f;
      for (int c = 0; c < channels; c++) {
        switch ((c + frame) % 4) {
          case 0:
            f.subframes.push_back(TestSubframe::fixed(1 + c % 4, c % 5));
            break;
          case 1:
            f.subframes.push_back(test_lpc(4 + c, 13, (unsigned)c, 3));
            break;
          case 2:
            f.subframes.push_back(TestSubframe::verbatim());
            break;
          default:
            f.subframes.push_back(TestSubframe::fixed(0, 7));
            break;
        }
      }
      b.add(f, flac_gen_tones(block, channels, 24, 0.6, 900u + (unsigned)frame));
    }
    char name[64];
    snprintf(name, sizeof(name), "%d channels", channels);
    const FlacDecoded d = flac_decode(b.stream());
    expect_clean(name, d, b.pcm);
    CHECK(d.have_info && d.info.channels == channels, "%s: channel count reported", name);
  }

  /* Every bit depth from 4 to 32.  A frame header names only six of
   * them; the others leave the depth to STREAMINFO. */
  for (int bits = 4; bits <= 32; bits++) {
    const bool named =
        bits == 8 || bits == 12 || bits == 16 || bits == 20 || bits == 24 || bits == 32;
    StreamBuilder b(32000, 2, bits);
    const int block = 256;
    for (int assignment = FLAC_ASSIGN_INDEPENDENT; assignment <= FLAC_ASSIGN_MID_SIDE;
         assignment++) {
      TestFrame f;
      f.defer_bits = !named;
      f.assignment = assignment;
      f.subframes.assign(2, TestSubframe::verbatim());
      b.add(f, flac_gen_noise(block, 2, bits, 40u + (unsigned)bits));
      f.subframes.assign(2, TestSubframe::fixed(1, 2));
      b.add(f, flac_gen_tones(block, 2, bits, 0.45, 50u + (unsigned)bits));
    }
    char name[64];
    snprintf(name, sizeof(name), "%d bits per sample", bits);
    const FlacDecoded d = flac_decode(b.stream());
    expect_clean(name, d, b.pcm);
    CHECK(d.have_info && d.info.bits_per_sample == bits, "%s: depth reported", name);
  }

  /* Sample rates: named by a header code, by the kilohertz, hertz and
   * ten-hertz forms, and left to STREAMINFO (768 kHz and the largest
   * rate STREAMINFO holds fit no header). */
  for (int rate : {8000, 22050, 44100, 96000, 192000, 22254, 255000, 655350, 1, 768000, 1048575}) {
    StreamBuilder b(rate, 1, 16);
    TestFrame f;
    f.defer_rate = !flac_sample_rate_is_codable(rate);
    f.subframes = {TestSubframe::fixed(2)};
    b.add(f, flac_gen_tones(300, 1, 16, 0.5, 1u));
    b.add(f, flac_gen_tones(300, 1, 16, 0.5, 2u));
    char name[64];
    snprintf(name, sizeof(name), "%d Hz", rate);
    const FlacDecoded d = flac_decode(b.stream());
    expect_clean(name, d, b.pcm);
    CHECK(d.have_info && d.info.sample_rate == rate, "%s: rate reported", name);
  }
}

static void test_block_sizes() {
  /* Fixed block size streams at every size a header code names, the
   * sizes around the 8-bit and 16-bit forms, and a short last frame
   * down to one sample. */
  for (int block :
       {16, 17, 192, 255, 256, 257, 576, 1024, 1152, 2304, 4096, 4608, 8192, 16384, 32768, 65535}) {
    for (int last : {0, 1, 15}) {
      StreamBuilder b(44100, 2, 16);
      for (int frame = 0; frame < 3; frame++) {
        b.add(TestSubframe::fixed(2), flac_gen_tones(block, 2, 16, 0.5, (unsigned)frame));
      }
      if (last) {
        b.add(TestSubframe::fixed(last > 4 ? 4 : 0), flac_gen_tones(last, 2, 16, 0.5, 9u));
      }
      char name[64];
      snprintf(name, sizeof(name), "blocks of %d, last of %d", block, last);
      const FlacDecoded d = flac_decode(b.stream());
      expect_clean(name, d, b.pcm);
      bool positions = d.frames.size() == b.blocks.size();
      for (size_t i = 0; positions && i < d.frames.size(); i++) {
        positions = d.frames[i].block_size == b.blocks[i] &&
                    d.frames[i].sample_position == (uint64_t)i * (uint64_t)block &&
                    d.frames[i].bytes ==
                        (int)((i + 1 < b.offsets.size() ? b.offsets[i + 1] : b.frames.size()) -
                              b.offsets[i]);
      }
      CHECK(positions, "%s: every frame reports its size, position and bytes", name);
    }
  }

  /* A variable block size stream: every frame has its own size and
   * carries its sample position. */
  {
    StreamBuilder b(48000, 2, 24);
    b.variable_block_size = true;
    const int sizes[] = {4096, 16, 1000, 255, 256, 4608, 17, 65535, 2048, 31, 9};
    for (int size : sizes) {
      b.add(test_lpc(std::min(size, 6), 14, (unsigned)size), flac_gen_tones(size, 2, 24, 0.5, 3u));
    }
    const FlacDecoded d = flac_decode(b.stream());
    expect_clean("variable block size", d, b.pcm);
    uint64_t position = 0;
    bool ok = d.frames.size() == sizeof(sizes) / sizeof(sizes[0]);
    for (size_t i = 0; ok && i < d.frames.size(); i++) {
      ok = d.frames[i].block_size == sizes[i] && d.frames[i].sample_position == position;
      position += (uint64_t)sizes[i];
    }
    CHECK(ok, "variable block size: each frame reports its own size and position");
    CHECK(d.have_info && d.info.min_block_size == 16 && d.info.max_block_size == 65535,
          "variable block size: STREAMINFO states the range");
  }

  /* Sample positions beyond 32 bits, as a long stream has them. */
  {
    StreamBuilder b(192000, 1, 16);
    b.variable_block_size = true;
    b.samples = 0xFFFFFF000ull;
    b.add(TestSubframe::fixed(1), flac_gen_tones(4000, 1, 16, 0.5, 1u));
    b.add(TestSubframe::fixed(1), flac_gen_tones(95, 1, 16, 0.5, 2u));
    std::vector<uint8_t> frames_only = b.frames;
    const FlacDecoded d = flac_decode(frames_only);
    CHECK(d.frames.size() == 2 && d.frames[0].sample_position == 0xFFFFFF000ull &&
              d.frames[1].sample_position == 0xFFFFFF000ull + 4000 && d.pcm == b.pcm,
          "36-bit sample positions");
  }
}

/* ── chunking ──────────────────────────────────────────────────── */

static std::vector<uint8_t> chunk_test_stream(std::vector<int32_t>* pcm,
                                              std::vector<size_t>* offsets = nullptr,
                                              int frames = 12, int block = 1152) {
  StreamBuilder b(44100, 2, 16);
  for (int frame = 0; frame < frames; frame++) {
    TestFrame f;
    f.assignment = frame % 4;
    f.subframes = {test_lpc(8, 12, (unsigned)frame, 4), TestSubframe::fixed(frame % 5, 3)};
    b.add(f, flac_gen_tones(block, 2, 16, 0.3, 60u + (unsigned)frame));
  }
  *pcm = b.pcm;
  const std::vector<uint8_t> s = b.stream();
  if (offsets) {
    const size_t header = s.size() - b.frames.size();
    for (size_t o : b.offsets) {
      offsets->push_back(header + o);
    }
    offsets->push_back(s.size());
  }
  return s;
}

static void test_chunking() {
  std::vector<int32_t> pcm;
  const std::vector<uint8_t> stream = chunk_test_stream(&pcm);
  const FlacDecoded whole = flac_decode(stream);
  expect_clean("fed whole", whole, pcm);
  for (size_t chunk : {(size_t)1, (size_t)2, (size_t)7, (size_t)100, (size_t)4096}) {
    const FlacDecoded d = flac_decode(stream, chunk);
    char name[64];
    snprintf(name, sizeof(name), "fed %zu bytes at a time", chunk);
    expect_clean(name, d, pcm);
    bool same = d.frames.size() == whole.frames.size();
    for (size_t i = 0; same && i < d.frames.size(); i++) {
      same = d.frames[i].block_size == whole.frames[i].block_size &&
             d.frames[i].sample_position == whole.frames[i].sample_position &&
             d.frames[i].bytes == whole.frames[i].bytes;
    }
    CHECK(same, "%s: the same frames as fed whole", name);
  }
  /* The largest frame the format has, eight channels of 65535 samples,
   * a byte at a time: the decoder goes on where the input ended instead
   * of reading the frame again from its start with every byte, which
   * would take the square of the frame's two megabytes.  The channels
   * alternate between unencoded samples and a predictor whose residual
   * is one partition, so that reading resumes in the middle of both. */
  {
    StreamBuilder b(96000, 8, 24);
    TestFrame f;
    for (int c = 0; c < 8; c++) {
      f.subframes.push_back(c % 2 ? TestSubframe::fixed(2, 0) : TestSubframe::verbatim());
    }
    f.subframes[3].escape = -2;
    b.add(f, flac_gen_tones(65535, 8, 24, 0.5, 8u));
    b.add(TestSubframe::fixed(1), flac_gen_tones(100, 8, 24, 0.5, 9u));
    const std::vector<uint8_t> s = b.stream();
    CHECK(b.offsets[1] > 1000000, "the large frame takes %zu bytes", b.offsets[1]);
    expect_clean("the largest frame bytewise", flac_decode(s, 1), b.pcm);
    expect_clean("the largest frame in pieces of 4099 bytes", flac_decode(s, 4099), b.pcm);
  }

  /* A stream with every metadata block form fed bytewise: the header
   * parser has no minimum chunk either. */
  const std::vector<uint8_t> e2 = rfc9639_example_2();
  expect_clean("example 2 bytewise", flac_decode(e2, 1), rfc9639_example_2_samples());
}

/* ── damage ────────────────────────────────────────────────────── */

/* A valid stream with one bit flipped inside its third frame: the two
 * frames before it decode, the third is reported once as a decode error
 * and not concealed, and every frame after it decodes at its own
 * position. */
static void test_damaged_frame() {
  std::vector<int32_t> pcm;
  std::vector<size_t> offsets;
  const int block = 1152;
  const std::vector<uint8_t> stream = chunk_test_stream(&pcm, &offsets);
  const size_t frame_count = offsets.size() - 1;
  const size_t start = offsets[2];
  const size_t length = offsets[3] - offsets[2];
  /* Without the third frame's samples. */
  std::vector<int32_t> expected = slice(pcm, 2, 0, 2 * (size_t)block);
  const std::vector<int32_t> rest = slice(pcm, 2, 3 * (size_t)block, SIZE_MAX);
  expected.insert(expected.end(), rest.begin(), rest.end());

  int trials = 0;
  /* Every bit of the header and the checksum, and bits spread over the
   * subframes. */
  std::vector<size_t> bit_positions;
  for (size_t bit = 0; bit < 6 * 8; bit++) {
    bit_positions.push_back(bit);
  }
  for (size_t bit = 6 * 8; bit < (length - 2) * 8; bit += 101) {
    bit_positions.push_back(bit);
  }
  for (size_t bit = (length - 2) * 8; bit < length * 8; bit++) {
    bit_positions.push_back(bit);
  }
  for (size_t bit : bit_positions) {
    std::vector<uint8_t> damaged = stream;
    damaged[start + bit / 8] ^= (uint8_t)(0x80 >> (bit % 8));
    for (size_t chunk : {SIZE_MAX, (size_t)333}) {
      const FlacDecoded d = flac_decode(damaged, chunk);
      trials++;
      const bool one_error = d.errors.size() == 1 && d.errors[0].code == AAC_ERR_DECODE &&
                             d.errors[0].frames_before == 2 && d.lost == 1;
      CHECK(one_error,
            "bit %zu of frame 3 flipped: %zu errors (first %d after %zu frames), %d lost", bit,
            d.errors.size(), d.errors.empty() ? 0 : d.errors[0].code,
            d.errors.empty() ? (size_t)0 : d.errors[0].frames_before, d.lost);
      CHECK(d.frames.size() == frame_count - 1 && d.pcm == expected,
            "bit %zu of frame 3 flipped: %zu frames decoded, the samples of the others %s", bit,
            d.frames.size(), d.pcm == expected ? "exact" : "differ");
      if (d.frames.size() == frame_count - 1) {
        CHECK(d.frames[1].sample_position == (uint64_t)block &&
                  d.frames[2].sample_position == 3 * (uint64_t)block &&
                  d.frames.back().sample_position == (frame_count - 1) * (uint64_t)block,
              "bit %zu of frame 3 flipped: the fourth frame reports position %llu", bit,
              (unsigned long long)d.frames[2].sample_position);
      }
      CHECK(d.finish == AAC_OK && d.verdict == BAAC_FLAC_MD5_MISMATCH,
            "bit %zu of frame 3 flipped: the stream ends cleanly with an MD5 mismatch", bit);
    }
  }
  printf("damaged frame: %d decodes with one flipped bit\n", trials);

  /* Damage in the last frames, where the garbled frame's length can
   * reach past the end of the stream: with the end of the input
   * announced, the frames behind it still decode. */
  for (size_t frame : {frame_count - 2, frame_count - 1}) {
    for (size_t bit = 8 * 8; bit < (offsets[frame + 1] - offsets[frame]) * 8; bit += 1009) {
      std::vector<uint8_t> damaged = stream;
      damaged[offsets[frame] + bit / 8] ^= (uint8_t)(0x80 >> (bit % 8));
      const FlacDecoded d = flac_decode(damaged);
      CHECK(d.frames.size() == frame_count - 1 && d.errors.size() == 1 && d.lost == 1,
            "frame %zu damaged at bit %zu: %zu frames, %zu errors", frame + 1, bit, d.frames.size(),
            d.errors.size());
    }
  }

  /* Frames removed, doubled and separated by garbage. */
  {
    std::vector<uint8_t> s(stream.begin(), stream.begin() + (long)offsets[3]);
    /* Garbage with sync codes in it, among them a header that passes its
     * CRC-8 but is followed by no frame. */
    std::vector<uint8_t> junk = {0xFF, 0xF8, 0x00, 0xFF, 0xF9, 0xC9, 0x18, 0x12, 0xFF, 0xFF};
    FlacFrameHeader fake = {};
    fake.block_size = 1152;
    fake.sample_rate = 44100;
    fake.channels = 2;
    fake.bits_per_sample = 16;
    fake.number = 3;
    uint8_t header[FLAC_MAX_FRAME_HEADER_BYTES];
    const int n = flac_frame_header_write(&fake, header);
    junk.insert(junk.end(), header, header + n);
    for (int i = 0; i < 300; i++) {
      junk.push_back((uint8_t)(i * 89 + 3));
    }
    flac_append(s, junk);
    s.insert(s.end(), stream.begin() + (long)offsets[5], stream.end());
    const FlacDecoded d = flac_decode(s);
    CHECK(d.errors.size() == 1 && d.errors[0].frames_before == 3 && d.lost == 1,
          "garbage between frames is one error (%zu)", d.errors.size());
    CHECK(d.frames.size() == frame_count - 2 && d.frames[3].sample_position == 5 * (uint64_t)block,
          "decoding resumes at the sixth frame's position");
  }
  {
    /* A frame cut out cleanly: nothing is damaged, the positions tell. */
    std::vector<uint8_t> s(stream.begin(), stream.begin() + (long)offsets[4]);
    s.insert(s.end(), stream.begin() + (long)offsets[6], stream.end());
    const FlacDecoded d = flac_decode(s);
    CHECK(d.errors.empty() && d.frames.size() == frame_count - 2 &&
              d.frames[4].sample_position == 6 * (uint64_t)block,
          "a missing frame shows in the positions");
    CHECK(d.verdict == BAAC_FLAC_MD5_MISMATCH, "and in the MD5");
  }
}

/* Random damage: whatever happens, every frame the decoder hands out is
 * exactly the source at the position it reports, because a frame only
 * leaves the decoder with both checksums intact. */
static void test_random_damage() {
  std::vector<int32_t> pcm;
  std::vector<size_t> offsets;
  const int block = 576;
  const std::vector<uint8_t> stream = chunk_test_stream(&pcm, &offsets, 16, block);
  unsigned seed = 31337u;
  int errors = 0;
  int frames = 0;
  for (int trial = 0; trial < 400; trial++) {
    std::vector<uint8_t> damaged = stream;
    seed = seed * 1664525u + 1013904223u;
    const int kind = (int)((seed >> 28) % 4);
    seed = seed * 1664525u + 1013904223u;
    const size_t at = offsets[0] + (seed >> 4) % (damaged.size() - offsets[0]);
    seed = seed * 1664525u + 1013904223u;
    const size_t span = 1 + (seed >> 8) % 40;
    if (kind == 0) { /* overwritten bytes */
      for (size_t i = at; i < std::min(at + span, damaged.size()); i++) {
        seed = seed * 1664525u + 1013904223u;
        damaged[i] = (uint8_t)(seed >> 24);
      }
    } else if (kind == 1) { /* bytes removed */
      damaged.erase(damaged.begin() + (long)at,
                    damaged.begin() + (long)std::min(at + span, damaged.size()));
    } else if (kind == 2) { /* bytes inserted */
      damaged.insert(damaged.begin() + (long)at, span, (uint8_t)0xFF);
    } else { /* cut off */
      damaged.resize(at);
    }
    seed = seed * 1664525u + 1013904223u;
    const FlacDecoded d = flac_decode(damaged, 1 + (seed >> 8) % 3000);
    size_t value = 0;
    bool exact = true;
    for (const BaacFlacFrameInfo& f : d.frames) {
      const std::vector<int32_t> source =
          slice(pcm, 2, (size_t)f.sample_position, (size_t)f.block_size);
      exact = exact && source.size() == (size_t)f.block_size * 2 &&
              value + source.size() <= d.pcm.size() &&
              memcmp(source.data(), d.pcm.data() + value, source.size() * sizeof(int32_t)) == 0;
      value += source.size();
    }
    CHECK(exact, "trial %d (kind %d at %zu, %zu bytes): a decoded frame differs from the source",
          trial, kind, at, span);
    CHECK((d.lost > 0) == (!d.errors.empty() || d.finish != AAC_OK),
          "trial %d: %d lost, %zu errors, finish %d", trial, d.lost, d.errors.size(), d.finish);
    errors += d.lost;
    frames += (int)d.frames.size();
  }
  printf("random damage: 400 streams, %d frames decoded exactly, %d damaged stretches reported\n",
         frames, errors);
}

static void test_truncation() {
  std::vector<int32_t> pcm;
  std::vector<size_t> offsets;
  const std::vector<uint8_t> stream = chunk_test_stream(&pcm, &offsets);
  /* Cut inside the fifth frame: four frames, then the cut-off frame is
   * damage. */
  {
    const std::vector<uint8_t> cut(stream.begin(), stream.begin() + (long)offsets[4] + 100);
    const FlacDecoded d = flac_decode(cut);
    CHECK(d.frames.size() == 4 && d.lost == 1 && d.verdict == BAAC_FLAC_MD5_MISMATCH,
          "a stream cut inside a frame: %zu frames, %d lost", d.frames.size(), d.lost);
  }
  /* Without the end of the input announced, the cut-off frame waits for
   * more and the finish reports it. */
  {
    BaacFlacDecoder* dec = baac_flac_decoder_create();
    std::vector<int32_t> buf(4096);
    baac_flac_decoder_feed(dec, stream.data(), (int)offsets[4] + 100);
    int frames = 0;
    while (baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size()) > 0) {
      frames++;
    }
    CHECK(frames == 4 && baac_flac_decoder_lost_frames(dec) == 0,
          "an unfinished frame is no error while more input may come");
    CHECK(baac_flac_decoder_finish(dec, nullptr) == AAC_ERR_DECODE &&
              baac_flac_decoder_lost_frames(dec) == 1,
          "the finish reports the frame that was cut short");
    CHECK(baac_flac_decoder_feed(dec, stream.data(), 1) == AAC_ERR_STATE &&
              baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size()) == AAC_ERR_STATE &&
              baac_flac_decoder_finish(dec, nullptr) == AAC_ERR_STATE,
          "a finished decoder takes nothing until a reset");
    baac_flac_decoder_destroy(dec);
  }
  /* Cut inside the metadata. */
  for (size_t cut_at : {(size_t)2, (size_t)4, (size_t)6, (size_t)20, (size_t)41}) {
    const std::vector<uint8_t> cut(stream.begin(), stream.begin() + (long)cut_at);
    const FlacDecoded d = flac_decode(cut);
    CHECK(d.frames.empty() && d.finish == AAC_ERR_DECODE && d.lost == 1,
          "a stream cut after %zu bytes of its header: finish %d", cut_at, d.finish);
  }
  /* An empty stream, and a header without frames, end cleanly. */
  {
    const FlacDecoded d = flac_decode({});
    CHECK(d.frames.empty() && d.finish == AAC_OK && d.lost == 0 && !d.have_info, "an empty stream");
    const std::vector<uint8_t> header(stream.begin(), stream.begin() + (long)offsets[0]);
    const FlacDecoded h = flac_decode(header);
    CHECK(h.frames.empty() && h.finish == AAC_OK && h.have_info &&
              h.verdict == BAAC_FLAC_MD5_MISMATCH,
          "a header without its frames: nothing decoded, the MD5 does not match");
  }
}

/* ── streams that begin at a frame ─────────────────────────────── */

static void test_frame_start() {
  /* Frames alone, with frame numbers as a long-running broadcast has
   * them, behind bytes that are no frame. */
  StreamBuilder b(48000, 2, 16);
  b.frame_number = 1234567;
  const int block = 1024;
  for (int frame = 0; frame < 6; frame++) {
    b.add(TestSubframe::fixed(2, 2), flac_gen_tones(block, 2, 16, 0.4, (unsigned)frame));
  }
  {
    const FlacDecoded d = flac_decode(b.frames);
    CHECK(d.errors.empty() && d.lost == 0 && d.finish == AAC_OK && d.pcm == b.pcm,
          "frames without a header decode");
    CHECK(d.frames.size() == 6 && d.frames[0].sample_position == 1234567ull * 1024 &&
              d.frames[5].sample_position == 1234572ull * 1024,
          "their positions come from the frame numbers");
    CHECK(d.have_info && d.info.sample_rate == 48000 && d.info.channels == 2 &&
              d.info.bits_per_sample == 16 && d.info.max_block_size == 0 &&
              d.info.total_samples == 0,
          "the stream properties come from the first frame");
    CHECK(d.verdict == BAAC_FLAC_MD5_UNKNOWN, "there is no MD5 to compare");
  }
  {
    /* The tail of another frame, sync codes included, in front. */
    std::vector<uint8_t> s = {0x12, 0xFF, 0xF8, 0xC9, 0x18, 0x00, 0xFF, 0xFF, 0xF8, 0x34, 0x56};
    s.insert(s.end(), b.frames.begin() + 700, b.frames.begin() + (long)b.offsets[1]);
    flac_append(s, b.frames);
    for (size_t chunk : {SIZE_MAX, (size_t)1}) {
      const FlacDecoded d = flac_decode(s, chunk);
      CHECK(d.errors.empty() && d.lost == 0 && d.pcm == b.pcm,
            "unparsable bytes before the first frame are passed over silently (%zu errors)",
            d.errors.size());
    }
    BaacFlacDecoder* dec = baac_flac_decoder_create();
    baac_flac_decoder_feed(dec, s.data(), (int)s.size());
    CHECK(baac_flac_decoder_first_frame_offset(dec) == AAC_ERR_STATE,
          "a stream without a header has no audio offset");
    baac_flac_decoder_destroy(dec);
  }

  /* Frames that leave the sample rate or the bit depth to a STREAMINFO
   * they do not come with: unsupported until one is supplied. */
  for (int what = 0; what < 2; what++) {
    const int bits = what ? 20 : 16;
    const int rate = what ? 44100 : 768000;
    StreamBuilder c(rate, 2, bits);
    for (int frame = 0; frame < 4; frame++) {
      TestFrame f;
      f.defer_rate = what == 0;
      f.defer_bits = what == 1;
      f.subframes.assign(2, TestSubframe::fixed(1));
      c.add(f, flac_gen_tones(block, 2, bits, 0.4, (unsigned)frame));
    }
    const char* name = what ? "bit depth" : "sample rate";
    BaacFlacDecoder* dec = baac_flac_decoder_create();
    std::vector<int32_t> buf((size_t)block * 2);
    CHECK(
        baac_flac_decoder_feed(dec, c.frames.data(), (int)c.frames.size()) == (int)c.frames.size(),
        "%s left to STREAMINFO: fed", name);
    BaacFlacStreamInfo info;
    for (int attempt = 0; attempt < 3; attempt++) {
      CHECK(baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size()) == AAC_ERR_UNSUPPORTED,
            "%s left to STREAMINFO: unsupported without one", name);
    }
    CHECK(baac_flac_decoder_stream_info(dec, &info) == AAC_ERR_STATE &&
              baac_flac_decoder_lost_frames(dec) == 0,
          "%s left to STREAMINFO: nothing is known and nothing lost", name);
    const std::vector<uint8_t> streaminfo = flac_streaminfo_bytes(c.info());
    CHECK(baac_flac_decoder_set_streaminfo(dec, streaminfo.data(), 33) == AAC_ERR_INVALID_ARG &&
              baac_flac_decoder_set_streaminfo(dec, nullptr, 34) == AAC_ERR_INVALID_ARG,
          "a STREAMINFO of the wrong size is refused");
    std::vector<uint8_t> forbidden = streaminfo;
    forbidden[0] = 0;
    forbidden[1] = 1; /* a minimum block size of 1 */
    CHECK(baac_flac_decoder_set_streaminfo(dec, forbidden.data(), 34) == AAC_ERR_INVALID_ARG,
          "a STREAMINFO with forbidden values is refused");
    CHECK(baac_flac_decoder_set_streaminfo(dec, streaminfo.data(), 34) == AAC_OK,
          "%s left to STREAMINFO: one is supplied", name);
    std::vector<int32_t> pcm;
    int n;
    while ((n = baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size())) > 0) {
      pcm.insert(pcm.end(), buf.begin(), buf.begin() + (long)n * 2);
    }
    CHECK(n == 0 && pcm == c.pcm, "%s left to STREAMINFO: the frames decode with it", name);
    CHECK(baac_flac_decoder_stream_info(dec, &info) == AAC_OK && info.sample_rate == rate &&
              info.bits_per_sample == bits,
          "%s left to STREAMINFO: reported from it", name);
    CHECK(baac_flac_decoder_set_streaminfo(dec, streaminfo.data(), 34) == AAC_ERR_STATE,
          "a second STREAMINFO is refused");
    baac_flac_decoder_destroy(dec);
  }

  /* A STREAMINFO supplied before the stream: its limits apply. */
  {
    BaacFlacDecoder* dec = baac_flac_decoder_create();
    const std::vector<uint8_t> streaminfo = flac_streaminfo_bytes(b.info());
    CHECK(baac_flac_decoder_set_streaminfo(dec, streaminfo.data(), 34) == AAC_OK,
          "a STREAMINFO out of band");
    baac_flac_decoder_set_md5_check(dec, 1);
    const FlacDecoded d = flac_decode(b.frames, 500, dec);
    CHECK(d.errors.empty() && d.pcm == b.pcm && d.have_info && d.info.max_block_size == block,
          "frames decode against a STREAMINFO out of band");
    /* The stream was joined at frame 1234567, so its MD5 cannot be
     * judged. */
    CHECK(d.verdict == BAAC_FLAC_MD5_UNKNOWN, "a stream joined in the middle has no MD5 verdict");
    baac_flac_decoder_destroy(dec);
  }
}

/* ── property changes ──────────────────────────────────────────── */

/* The work of the frame search.  A stream can be made of sync codes
 * with headers whose CRC-8 holds and that claim the largest frame there
 * is, one every few bytes, none of them a frame.  Reading each to its
 * end would cost the size of a frame for every few bytes of input; the
 * decoder has to keep what it reads in vain in proportion to what it
 * passes.  The count is of bytes read, not of time. */
static void test_frame_search() {
  BaacFlacStreamInfo info = {};
  info.min_block_size = 16;
  info.max_block_size = 65535;
  info.sample_rate = 48000;
  info.channels = 8;
  info.bits_per_sample = 32;
  const size_t largest_frame = 16 + 8 * ((size_t)65535 * 8 + 16) + 2;

  /* Real frames of the stream's format, to be found behind the rest. */
  StreamBuilder real(48000, 8, 32);
  for (int frame = 0; frame < 24; frame++) {
    TestFrame f;
    f.subframes.assign(8, TestSubframe::fixed(1));
    real.add(f, flac_gen_tones(256, 8, 32, 0.4, (unsigned)frame));
  }

  /* A header of 65535 samples in eight channels of 32 bits, and the
   * start of a verbatim subframe; with a period of nine bytes the next
   * header begins inside the first subframe, with eleven behind more. */
  uint8_t pattern[11] = {0xFF, 0xF8, 0x7A, 0x7E, 0x00, 0xFF, 0xFE, 0x00, 0x02, 0x00, 0x00};
  pattern[7] = flac_crc8(pattern, 7);
  for (int period : {9, 11}) {
    std::vector<uint8_t> s = flac_stream_start(info);
    const size_t crafted = 1500000;
    for (size_t i = 0; i < crafted; i++) {
      s.push_back(pattern[i % (size_t)period]);
    }
    flac_append(s, real.frames);

    BaacFlacDecoder* dec = baac_flac_decoder_create();
    std::vector<int32_t> pcm((size_t)65535 * 8);
    std::vector<int32_t> decoded;
    uint64_t most_in_a_call = 0;
    int unsupported = 0;
    const auto drain = [&]() {
      for (;;) {
        const uint64_t before = flac_decoder_rejected_work(dec);
        const int n = baac_flac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
        most_in_a_call = std::max(most_in_a_call, flac_decoder_rejected_work(dec) - before);
        if (n > 0) {
          decoded.insert(decoded.end(), pcm.begin(), pcm.begin() + (size_t)n * 8);
        } else if (n == AAC_ERR_UNSUPPORTED) {
          unsupported++;
          break;
        } else if (n != AAC_ERR_DECODE) {
          break;
        }
      }
    };
    for (size_t pos = 0; pos < s.size();) {
      const int took = baac_flac_decoder_feed(dec, s.data() + pos, (int)(s.size() - pos));
      if (took < 0) {
        break;
      }
      pos += (size_t)took;
      drain();
      if (took == 0) {
        break;
      }
    }
    baac_flac_decoder_end_input(dec);
    drain();
    const uint64_t work = flac_decoder_rejected_work(dec);
    baac_flac_decoder_destroy(dec);
    CHECK(work <= 6 * (uint64_t)s.size() + 3 * (uint64_t)largest_frame,
          "period %d: %llu bytes were read for rejected candidates in %zu bytes of input", period,
          (unsigned long long)work, s.size());
    CHECK(most_in_a_call <= 6 * (uint64_t)largest_frame,
          "period %d: one call read %llu bytes for rejected candidates", period,
          (unsigned long long)most_in_a_call);
    /* The decoder comes out of it: the last real frames are decoded.
     * The first of them may be passed while the search pays off. */
    const size_t tail = (size_t)12 * 256 * 8;
    CHECK(unsupported == 0 && decoded.size() >= tail &&
              std::equal(decoded.end() - (long)tail, decoded.end(), real.pcm.end() - (long)tail),
          "period %d: the frames behind the crafted bytes decode (%zu samples)", period,
          decoded.size() / 8);
  }

  /* A stream that begins at a frame and has no STREAMINFO.  A sync code
   * with a header that holds and leaves its bit depth to STREAMINFO is
   * no frame unless a frame follows it: the bytes behind this one are
   * none at any depth, so it is passed over like other bytes, and the
   * frames behind it decode. */
  {
    StreamBuilder b(48000, 2, 16);
    for (int frame = 0; frame < 6; frame++) {
      b.add(TestSubframe::fixed(2, 2), flac_gen_tones(1024, 2, 16, 0.4, (unsigned)frame));
    }
    std::vector<uint8_t> s = {0xFF, 0xF8, 0x19, 0x10, 0x00, 0x00};
    s[5] = flac_crc8(s.data(), 5);
    s.insert(s.end(), 64, 0x00);
    flac_append(s, b.frames);
    for (size_t chunk : {SIZE_MAX, (size_t)7}) {
      const FlacDecoded d = flac_decode(s, chunk);
      bool unsupported = false;
      for (const FlacDecodeEvent& e : d.errors) {
        unsupported = unsupported || e.code == AAC_ERR_UNSUPPORTED;
      }
      CHECK(!unsupported && d.pcm == b.pcm,
            "a false header that leaves its depth to STREAMINFO does not stop a stream without "
            "one (%zu of %zu samples, %zu errors)",
            d.pcm.size(), b.pcm.size(), d.errors.size());
    }
  }

  /* Real frames that leave their depth to a STREAMINFO the stream does
   * not carry, larger than the FIFO holds before a frame size is known,
   * fed in pieces smaller than a frame.  However the input arrives, the
   * decoder says the stream needs its STREAMINFO: it neither waits for
   * more than it can take nor passes the frames over as false ones. */
  {
    StreamBuilder b(48000, 2, 16);
    for (int frame = 0; frame < 6; frame++) {
      TestFrame f;
      f.defer_bits = true;
      f.subframes.assign(2, TestSubframe::verbatim());
      b.add(f, flac_gen_noise(4096, 2, 16, 60u + (unsigned)frame));
    }
    std::vector<uint8_t> with_marker = {'f', 'L', 'a', 'C'};
    flac_append(with_marker,
                flac_metadata_block(FLAC_BLOCK_PADDING, std::vector<uint8_t>(16), true));
    flac_append(with_marker, b.frames);
    std::vector<uint8_t> one_frame(b.frames.begin(),
                                   b.frames.begin() + (std::ptrdiff_t)b.offsets[1]);
    for (const std::vector<uint8_t>* s : {&b.frames, &with_marker, &one_frame}) {
      for (size_t chunk : {SIZE_MAX, (size_t)8192, (size_t)4096, (size_t)1000}) {
        const FlacDecoded d = flac_decode(*s, chunk);
        bool unsupported = false;
        for (const FlacDecodeEvent& e : d.errors) {
          unsupported = unsupported || e.code == AAC_ERR_UNSUPPORTED;
        }
        CHECK(unsupported && d.pcm.empty(),
              "frames that need a missing STREAMINFO are reported as such (%s, pieces of %zu "
              "bytes, %zu samples, %zu errors)",
              s == &b.frames ? "frames only" : s == &one_frame ? "one frame" : "marker and padding",
              chunk, d.pcm.size(),
              d.errors.size());
      }
    }
  }

  /* A candidate of the search that claims the largest frame and is fed in
   * small pieces is read on from where the last piece ended, not again
   * from its start for every piece.  The stream begins at the candidate,
   * so the decoder is searching. */
  {
    std::vector<uint8_t> s(pattern, pattern + 9);
    s.insert(s.end(), (size_t)1 << 20, 0x00);
    flac_append(s, real.frames);
    BaacFlacDecoder* dec = baac_flac_decoder_create();
    std::vector<int32_t> pcm((size_t)65535 * 8);
    size_t frames = 0;
    for (size_t pos = 0; pos < s.size();) {
      const int piece = (int)std::min<size_t>(4096, s.size() - pos);
      const int took = baac_flac_decoder_feed(dec, s.data() + pos, piece);
      if (took <= 0) {
        break;
      }
      pos += (size_t)took;
      while (baac_flac_decoder_decode_next(dec, pcm.data(), (int)pcm.size()) > 0) {
        frames++;
      }
    }
    baac_flac_decoder_end_input(dec);
    for (int n; (n = baac_flac_decoder_decode_next(dec, pcm.data(), (int)pcm.size())) != 0;) {
      frames += n > 0 ? 1 : 0;
      if (n < 0 && n != AAC_ERR_DECODE) {
        break;
      }
    }
    const uint64_t work = flac_decoder_read_work(dec);
    baac_flac_decoder_destroy(dec);
    CHECK(work <= 4 * (uint64_t)s.size(),
          "a candidate fed in pieces of 4096 bytes: %llu bytes read for %zu bytes of input",
          (unsigned long long)work, s.size());
    CHECK(frames >= 12, "the frames behind the candidate decode (%zu)", frames);
  }
}

static void test_property_change() {
  const int block = 576;
  const struct {
    const char* what;
    int rate, channels, bits;
  } changes[] = {
      {"sample rate", 48000, 2, 16},      {"fewer channels", 44100, 1, 16},
      {"more channels", 44100, 6, 16},    {"lower bit depth", 44100, 2, 8},
      {"higher bit depth", 44100, 2, 24},
  };
  for (const auto& change : changes) {
    for (int with_header = 0; with_header < 2; with_header++) {
      StreamBuilder b(44100, 2, 16);
      for (int frame = 0; frame < 3; frame++) {
        b.add(TestSubframe::fixed(2, 1), flac_gen_tones(block, 2, 16, 0.4, (unsigned)frame));
      }
      /* The fourth frame and those after it have other properties. */
      StreamBuilder other(change.rate, change.channels, change.bits);
      other.frame_number = 3;
      for (int frame = 0; frame < 3; frame++) {
        other.add(TestSubframe::fixed(1),
                  flac_gen_tones(block, change.channels, change.bits, 0.4, 9u));
      }
      std::vector<uint8_t> s = with_header ? b.stream() : b.frames;
      flac_append(s, other.frames);
      for (size_t chunk : {SIZE_MAX, (size_t)97}) {
        const FlacDecoded d = flac_decode(s, chunk);
        CHECK(d.frames.size() == 3 && d.pcm == b.pcm,
              "%s changes in the fourth frame: %zu frames decoded before it", change.what,
              d.frames.size());
        CHECK(d.errors.size() == 1 && d.errors[0].code == AAC_ERR_UNSUPPORTED &&
                  d.errors[0].frames_before == 3,
              "%s changes in the fourth frame: unsupported there", change.what);
        CHECK(d.have_info && d.info.sample_rate == 44100 && d.info.channels == 2 &&
                  d.info.bits_per_sample == 16 && d.lost == 0,
              "%s changes: the stream keeps its properties and nothing counts as lost",
              change.what);
        CHECK(d.verdict == BAAC_FLAC_MD5_UNKNOWN, "%s changes: no MD5 verdict", change.what);
      }
      /* The error stays until a reset. */
      BaacFlacDecoder* dec = baac_flac_decoder_create();
      std::vector<int32_t> buf((size_t)block * 8);
      baac_flac_decoder_feed(dec, s.data(), (int)s.size());
      int frames = 0;
      int n;
      while ((n = baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size())) > 0) {
        frames++;
      }
      CHECK(frames == 3 && n == AAC_ERR_UNSUPPORTED &&
                baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size()) ==
                    AAC_ERR_UNSUPPORTED,
            "%s changes: the error repeats", change.what);
      CHECK(baac_flac_decoder_reset(dec) == AAC_OK, "reset");
      const FlacDecoded again = flac_decode(other.frames, SIZE_MAX, dec);
      CHECK(again.frames.size() == 3 && again.pcm == other.pcm && again.have_info &&
                again.info.channels == change.channels,
            "%s: after a reset the other stream decodes", change.what);
      baac_flac_decoder_destroy(dec);
    }
  }

  /* A header with other properties that passes its CRC-8 but starts no
   * frame is a false sync code in damaged data, not a property change. */
  {
    StreamBuilder b(44100, 2, 16);
    for (int frame = 0; frame < 6; frame++) {
      b.add(TestSubframe::fixed(2, 1), flac_gen_tones(block, 2, 16, 0.4, (unsigned)frame));
    }
    std::vector<uint8_t> s = flac_stream_start(b.info());
    s.insert(s.end(), b.frames.begin(), b.frames.begin() + (long)b.offsets[3]);
    for (int kind = 0; kind < 3; kind++) {
      FlacFrameHeader fake = {};
      fake.block_size = 576;
      fake.sample_rate = kind == 0 ? 96000 : 44100;
      fake.channels = kind == 1 ? 5 : 2;
      fake.bits_per_sample = kind == 2 ? 24 : 16;
      fake.number = 3;
      uint8_t header[FLAC_MAX_FRAME_HEADER_BYTES];
      const int n = flac_frame_header_write(&fake, header);
      s.insert(s.end(), header, header + n);
      for (int i = 0; i < 40; i++) {
        s.push_back((uint8_t)(i * 57 + kind));
      }
    }
    s.insert(s.end(), b.frames.begin() + (long)b.offsets[4], b.frames.end());
    const FlacDecoded d = flac_decode(s);
    CHECK(d.frames.size() == 5 && d.errors.size() == 1 && d.errors[0].code == AAC_ERR_DECODE,
          "false headers with other properties are damage, not a change: %zu frames, error %d",
          d.frames.size(), d.errors.empty() ? 0 : d.errors[0].code);
  }
}

/* ── metadata ──────────────────────────────────────────────────── */

static void test_metadata() {
  StreamBuilder b(44100, 2, 16);
  const int block = 1152;
  for (int frame = 0; frame < 5; frame++) {
    b.add(TestSubframe::fixed(2, 2), flac_gen_tones(block, 2, 16, 0.4, (unsigned)frame));
  }
  const BaacFlacStreamInfo info = b.info();

  /* A metadata block of the largest size, full of frame sync codes, is
   * passed over as it arrives: the FIFO never holds it and nothing is
   * sized for it. */
  {
    std::vector<uint8_t> body(0xFFFFFF);
    for (size_t i = 0; i < body.size(); i += 4) {
      body[i] = 0xFF;
      body[i + 1 < body.size() ? i + 1 : i] = 0xF8;
    }
    std::vector<uint8_t> s = flac_stream_start(info, true);
    flac_append(s, flac_metadata_block(FLAC_BLOCK_APPLICATION, body, false));
    flac_append(s, flac_metadata_block(FLAC_BLOCK_PADDING, std::vector<uint8_t>(100, 0), true));
    const size_t audio = s.size();
    flac_append(s, b.frames);

    BaacFlacDecoder* dec = baac_flac_decoder_create();
    baac_flac_decoder_set_md5_check(dec, 1);
    std::vector<int32_t> buf((size_t)block * 2);
    std::vector<int32_t> pcm;
    pcm.reserve(b.pcm.size());
    const size_t bytes_before = g_allocated_bytes;
    int max_pending = 0;
    int errors = 0;
    for (size_t pos = 0; pos < s.size();) {
      const int took = baac_flac_decoder_feed(dec, s.data() + pos,
                                              (int)std::min<size_t>(1 << 20, s.size() - pos));
      pos += (size_t)std::max(took, 0);
      max_pending = std::max(max_pending, baac_flac_decoder_pending_bytes(dec));
      int n;
      while ((n = baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size())) != 0) {
        if (n < 0) {
          errors++;
          break;
        }
        pcm.insert(pcm.end(), buf.begin(), buf.begin() + (long)n * 2);
      }
      if (took <= 0 && n == 0) {
        errors++;
        break;
      }
    }
    const size_t allocated = g_allocated_bytes - bytes_before;
    BaacFlacMd5Verdict verdict;
    CHECK(errors == 0 && pcm == b.pcm && baac_flac_decoder_finish(dec, &verdict) == AAC_OK &&
              verdict == BAAC_FLAC_MD5_MATCH,
          "a stream behind a 16 MB metadata block decodes");
    CHECK(max_pending <= 65536, "the FIFO held at most %d bytes of a %zu byte stream", max_pending,
          s.size());
    CHECK(allocated < 262144, "%zu bytes were allocated while a 16 MB block passed", allocated);
    CHECK(baac_flac_decoder_first_frame_offset(dec) == (int64_t)audio,
          "the audio offset counts the skipped block: %lld",
          (long long)baac_flac_decoder_first_frame_offset(dec));
    printf("16 MB metadata block: FIFO peak %d bytes, %zu bytes allocated\n", max_pending,
           allocated);
    baac_flac_decoder_destroy(dec);
  }

  /* A seek table: points in order are kept, placeholders and a point
   * out of order are not; a second table is ignored. */
  {
    const auto point = [](uint64_t sample, uint64_t offset, int samples) {
      std::vector<uint8_t> p;
      for (int shift = 56; shift >= 0; shift -= 8) {
        p.push_back((uint8_t)(sample >> shift));
      }
      for (int shift = 56; shift >= 0; shift -= 8) {
        p.push_back((uint8_t)(offset >> shift));
      }
      p.push_back((uint8_t)(samples >> 8));
      p.push_back((uint8_t)samples);
      return p;
    };
    std::vector<uint8_t> table;
    flac_append(table, point(0, 0, block));
    flac_append(table, point(2304, b.offsets[2], block));
    flac_append(table, point(1152, b.offsets[1], block)); /* out of order */
    flac_append(table, point(2304, b.offsets[2], block)); /* a repeat */
    flac_append(table, point(4608, b.offsets[4], block));
    flac_append(table, point(UINT64_MAX, 0, 0));
    flac_append(table, point(UINT64_MAX, 0, 0));
    table.push_back(0x55); /* a tail that is no whole point */
    std::vector<uint8_t> s = flac_stream_start(info, true);
    flac_append(s, flac_metadata_block(FLAC_BLOCK_SEEKTABLE, table, false));
    flac_append(s, flac_metadata_block(FLAC_BLOCK_SEEKTABLE, point(7, 7, 7), false));
    flac_append(s,
                flac_metadata_block(FLAC_BLOCK_VORBIS_COMMENT, std::vector<uint8_t>(40, 1), true));
    const size_t audio = s.size();
    flac_append(s, b.frames);
    for (size_t chunk : {SIZE_MAX, (size_t)1, (size_t)19}) {
      BaacFlacDecoder* dec = baac_flac_decoder_create();
      baac_flac_decoder_set_md5_check(dec, 1);
      const FlacDecoded d = flac_decode(s, chunk, dec);
      expect_clean("a stream with a seek table", d, b.pcm);
      const BaacFlacSeekPoint* points = nullptr;
      const int count = baac_flac_decoder_seek_points(dec, &points);
      CHECK(count == 3 && points && points[0].sample_position == 0 &&
                points[1].sample_position == 2304 && points[1].byte_offset == b.offsets[2] &&
                points[1].block_size == block && points[2].sample_position == 4608,
            "the seek table has %d usable points", count);
      CHECK(baac_flac_decoder_first_frame_offset(dec) == (int64_t)audio, "the audio offset");
      baac_flac_decoder_destroy(dec);
    }

    /* Seeking by the table: the bytes at a point's offset decode to the
     * frame at its sample position. */
    BaacFlacDecoder* dec = baac_flac_decoder_create();
    std::vector<int32_t> buf((size_t)block * 2);
    baac_flac_decoder_feed(dec, s.data(), (int)audio + 10);
    CHECK(baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size()) == 0, "header read");
    const BaacFlacSeekPoint* points = nullptr;
    baac_flac_decoder_seek_points(dec, &points);
    const size_t target =
        (size_t)baac_flac_decoder_first_frame_offset(dec) + (size_t)points[2].byte_offset;
    CHECK(baac_flac_decoder_seek_reset(dec) == AAC_OK, "seek reset");
    baac_flac_decoder_feed(dec, s.data() + target, (int)(s.size() - target));
    BaacFlacFrameInfo frame;
    CHECK(baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size()) == block &&
              baac_flac_decoder_last_frame_info(dec, &frame) == AAC_OK &&
              frame.sample_position == 4608 &&
              memcmp(buf.data(), b.pcm.data() + 4608 * 2, (size_t)block * 2 * sizeof(int32_t)) == 0,
          "a seek point leads to its frame");
    baac_flac_decoder_destroy(dec);
  }

  /* A seek table longer than the decoder keeps: the first points are
   * kept and the rest passed over. */
  {
    std::vector<uint8_t> table((size_t)(BAAC_FLAC_MAX_SEEK_POINTS + 1000) * FLAC_SEEK_POINT_BYTES);
    for (size_t i = 0; i < table.size() / FLAC_SEEK_POINT_BYTES; i++) {
      const uint64_t sample = (uint64_t)i * 4096;
      for (int byte = 0; byte < 8; byte++) {
        table[i * FLAC_SEEK_POINT_BYTES + (size_t)byte] = (uint8_t)(sample >> (56 - 8 * byte));
      }
    }
    std::vector<uint8_t> s = flac_stream_start(info, true);
    flac_append(s, flac_metadata_block(FLAC_BLOCK_SEEKTABLE, table, true));
    flac_append(s, b.frames);
    BaacFlacDecoder* dec = baac_flac_decoder_create();
    baac_flac_decoder_set_md5_check(dec, 1);
    const FlacDecoded d = flac_decode(s, 50000, dec);
    expect_clean("a stream with a very long seek table", d, b.pcm);
    const BaacFlacSeekPoint* points = nullptr;
    CHECK(baac_flac_decoder_seek_points(dec, &points) == BAAC_FLAC_MAX_SEEK_POINTS &&
              points[BAAC_FLAC_MAX_SEEK_POINTS - 1].sample_position ==
                  (uint64_t)(BAAC_FLAC_MAX_SEEK_POINTS - 1) * 4096,
          "the decoder keeps the first %d seek points", BAAC_FLAC_MAX_SEEK_POINTS);
    CHECK(d.max_pending <= 65536, "and never buffers the table (%d)", d.max_pending);
    baac_flac_decoder_destroy(dec);
  }

  /* Headers that are not as they should be. */
  const std::vector<uint8_t> streaminfo = flac_streaminfo_bytes(info);
  const std::vector<uint8_t> marker = {'f', 'L', 'a', 'C'};
  {
    /* STREAMINFO behind other blocks: read where it stands. */
    std::vector<uint8_t> s = marker;
    flac_append(s,
                flac_metadata_block(FLAC_BLOCK_VORBIS_COMMENT, std::vector<uint8_t>(30, 0), false));
    flac_append(s, flac_metadata_block(FLAC_BLOCK_PADDING, std::vector<uint8_t>(64, 0), false));
    flac_append(s, flac_metadata_block(FLAC_BLOCK_STREAMINFO, streaminfo, true));
    flac_append(s, b.frames);
    expect_clean("STREAMINFO behind other blocks", flac_decode(s), b.pcm);
  }
  {
    /* No STREAMINFO at all: the frames describe themselves. */
    std::vector<uint8_t> s = marker;
    flac_append(s,
                flac_metadata_block(FLAC_BLOCK_VORBIS_COMMENT, std::vector<uint8_t>(30, 0), false));
    flac_append(s, flac_metadata_block(FLAC_BLOCK_PADDING, std::vector<uint8_t>(64, 0), true));
    flac_append(s, b.frames);
    const FlacDecoded d = flac_decode(s);
    expect_clean("no STREAMINFO", d, b.pcm, BAAC_FLAC_MD5_UNKNOWN);
    CHECK(d.have_info && d.info.max_block_size == 0 && d.info.channels == 2,
          "no STREAMINFO: the properties of the first frame");
  }
  {
    /* A second STREAMINFO that disagrees is ignored. */
    BaacFlacStreamInfo other = info;
    other.channels = 5;
    other.bits_per_sample = 24;
    std::vector<uint8_t> s = flac_stream_start(info, true);
    flac_append(s, flac_metadata_block(FLAC_BLOCK_STREAMINFO, flac_streaminfo_bytes(other), true));
    flac_append(s, b.frames);
    expect_clean("a second STREAMINFO", flac_decode(s), b.pcm);
  }
  {
    /* A STREAMINFO of the wrong length, and one with forbidden values:
     * reported once, then the frames decode by their own headers. */
    for (int kind = 0; kind < 2; kind++) {
      std::vector<uint8_t> body = streaminfo;
      if (kind == 0) {
        body.push_back(0);
      } else {
        body[0] = 0;
        body[1] = 1; /* a block size of 1 */
        body[2] = 0;
        body[3] = 1;
      }
      std::vector<uint8_t> s = marker;
      flac_append(s, flac_metadata_block(FLAC_BLOCK_STREAMINFO, body, true));
      flac_append(s, b.frames);
      const FlacDecoded d = flac_decode(s);
      CHECK(d.errors.size() == 1 && d.errors[0].code == AAC_ERR_DECODE &&
                d.errors[0].frames_before == 0 && d.lost == 1,
            "an unreadable STREAMINFO (%d) is reported once", kind);
      CHECK(d.pcm == b.pcm && d.verdict == BAAC_FLAC_MD5_UNKNOWN,
            "an unreadable STREAMINFO (%d): the frames still decode", kind);
    }
  }
  {
    /* A block length that is wrong: what follows the block is read as a
     * block header.  Here it reads as the forbidden type 127, which is
     * reported, and the frames are found by their sync codes. */
    std::vector<uint8_t> s = flac_stream_start(info, true);
    std::vector<uint8_t> padding =
        flac_metadata_block(FLAC_BLOCK_PADDING, std::vector<uint8_t>(64, 0), true);
    padding[3] = 60;                 /* four bytes short */
    padding[0] = FLAC_BLOCK_PADDING; /* and not the last block */
    flac_append(s, padding);
    s[s.size() - 4] = 0xFF; /* the next "block header": last block, type 127 */
    flac_append(s, b.frames);
    const FlacDecoded d = flac_decode(s);
    CHECK(d.errors.size() == 1 && d.errors[0].code == AAC_ERR_DECODE && d.lost == 1,
          "a forbidden block type is reported once (%zu)", d.errors.size());
    CHECK(d.pcm == b.pcm, "and the frames behind it decode");
  }
}

/* ── MD5 ───────────────────────────────────────────────────────── */

static void test_md5() {
  StreamBuilder b(44100, 2, 24);
  for (int frame = 0; frame < 4; frame++) {
    b.add(TestSubframe::fixed(2, 2), flac_gen_tones(1000, 2, 24, 0.4, (unsigned)frame));
  }
  BaacFlacStreamInfo info = b.info();
  const auto with_info = [&](const BaacFlacStreamInfo& i) {
    std::vector<uint8_t> s = flac_stream_start(i);
    flac_append(s, b.frames);
    return s;
  };
  expect_clean("the right MD5", flac_decode(with_info(info)), b.pcm, BAAC_FLAC_MD5_MATCH);
  BaacFlacStreamInfo wrong = info;
  wrong.md5[7] ^= 0x10;
  expect_clean("a wrong MD5", flac_decode(with_info(wrong)), b.pcm, BAAC_FLAC_MD5_MISMATCH);
  BaacFlacStreamInfo zero = info;
  memset(zero.md5, 0, 16);
  expect_clean("an MD5 of zero", flac_decode(with_info(zero)), b.pcm, BAAC_FLAC_MD5_UNKNOWN);

  /* Verification is off unless asked for, and cannot be switched once
   * frames were decoded. */
  BaacFlacDecoder* dec = baac_flac_decoder_create();
  const FlacDecoded off = flac_decode(with_info(wrong), SIZE_MAX, dec);
  expect_clean("MD5 checking off", off, b.pcm, BAAC_FLAC_MD5_UNKNOWN);
  baac_flac_decoder_reset(dec);
  CHECK(baac_flac_decoder_set_md5_check(dec, 1) == AAC_OK, "MD5 checking on after a reset");
  const std::vector<uint8_t> s = with_info(info);
  std::vector<int32_t> buf(2000);
  baac_flac_decoder_feed(dec, s.data(), (int)s.size());
  CHECK(baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size()) == 1000, "one frame");
  CHECK(baac_flac_decoder_set_md5_check(dec, 0) == AAC_ERR_STATE,
        "the MD5 setting is fixed once a frame is decoded");
  while (baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size()) > 0) {
  }
  BaacFlacMd5Verdict verdict;
  CHECK(baac_flac_decoder_finish(dec, &verdict) == AAC_OK && verdict == BAAC_FLAC_MD5_MATCH,
        "the setting survives a reset and the verdict is a match");
  baac_flac_decoder_destroy(dec);
}

/* ── limits and memory ─────────────────────────────────────────── */

static void test_limits() {
  const int block = 1152;
  StreamBuilder b(44100, 2, 16);
  for (int frame = 0; frame < 6; frame++) {
    b.add(TestSubframe::fixed(2, 2), flac_gen_tones(block, 2, 16, 0.4, (unsigned)frame));
  }

  /* A frame that claims a block size beyond STREAMINFO's maximum is
   * refused as damage, and nothing is allocated for it. */
  {
    StreamBuilder big(44100, 2, 16);
    big.frame_number = 2;
    big.add(TestSubframe::fixed(2), flac_gen_tones(4608, 2, 16, 0.4, 1u));
    std::vector<uint8_t> s = flac_stream_start(b.info());
    s.insert(s.end(), b.frames.begin(), b.frames.begin() + (long)b.offsets[2]);
    flac_append(s, big.frames);
    s.insert(s.end(), b.frames.begin() + (long)b.offsets[3], b.frames.end());

    BaacFlacDecoder* dec = baac_flac_decoder_create();
    std::vector<int32_t> buf(65535 * 2);
    CHECK(baac_flac_decoder_feed(dec, s.data(), (int)s.size()) == (int)s.size(), "fed");
    CHECK(baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size()) == block &&
              baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size()) == block,
          "two frames before the oversized one");
    const long allocations = g_allocations;
    const int refused = baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size());
    CHECK(refused == AAC_ERR_DECODE && baac_flac_decoder_lost_frames(dec) == 1,
          "a block of 4608 in a stream of at most %d is refused: %d", block, refused);
    const int next = baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size());
    CHECK(g_allocations == allocations, "nothing was allocated for it (%ld)",
          g_allocations - allocations);
    BaacFlacFrameInfo frame = {};
    baac_flac_decoder_last_frame_info(dec, &frame);
    CHECK(next == block && frame.sample_position == 3 * (uint64_t)block,
          "decoding resumes at the next frame of the stream's size");
    baac_flac_decoder_destroy(dec);
  }

  /* A frame larger than 64 bits per sample (here: a Rice parameter of
   * zero on a loud signal) is refused as damage rather than buffered. */
  {
    StreamBuilder odd(44100, 1, 16);
    odd.add(TestSubframe::fixed(1), flac_gen_tones(16, 1, 16, 0.4, 1u));
    TestFrame f;
    f.subframes = {TestSubframe::fixed(0)};
    f.subframes[0].rice = 0;
    f.capacity = 1 << 20;
    odd.add(f, flac_gen_noise(16, 1, 16, 3u));
    odd.add(TestSubframe::fixed(1), flac_gen_tones(16, 1, 16, 0.4, 2u));
    CHECK(odd.offsets[2] - odd.offsets[1] > 16 * 8 + 34, "the middle frame takes %zu bytes",
          odd.offsets[2] - odd.offsets[1]);
    const FlacDecoded d = flac_decode(odd.stream(), 1000);
    CHECK(d.frames.size() == 2 && d.errors.size() == 1 && d.errors[0].frames_before == 1 &&
              d.frames[1].sample_position == 32,
          "a frame beyond the size bound is damage: %zu frames, %zu errors", d.frames.size(),
          d.errors.size());
  }

  /* An output buffer that is too small: the frame stays and decodes
   * into a larger one. */
  {
    const std::vector<uint8_t> s = b.stream();
    BaacFlacDecoder* dec = baac_flac_decoder_create();
    std::vector<int32_t> buf((size_t)block * 2);
    baac_flac_decoder_feed(dec, s.data(), (int)s.size());
    CHECK(baac_flac_decoder_decode_next(dec, buf.data(), block * 2 - 1) == AAC_ERR_OVERFLOW &&
              baac_flac_decoder_decode_next(dec, buf.data(), 1) == AAC_ERR_OVERFLOW,
          "an output buffer one value short is refused");
    BaacFlacFrameInfo frame;
    CHECK(baac_flac_decoder_last_frame_info(dec, &frame) == AAC_ERR_STATE,
          "no frame counts as decoded");
    CHECK(baac_flac_decoder_decode_next(dec, buf.data(), block * 2) == block &&
              memcmp(buf.data(), b.pcm.data(), (size_t)block * 2 * sizeof(int32_t)) == 0,
          "the frame decodes into a buffer of its size");
    baac_flac_decoder_destroy(dec);
  }

  /* The FIFO: a feed larger than it is taken in part, and decoding
   * makes room. */
  {
    StreamBuilder big(44100, 2, 16);
    for (int frame = 0; frame < 40; frame++) {
      big.add(TestSubframe::verbatim(), flac_gen_noise(4096, 2, 16, (unsigned)frame));
    }
    const std::vector<uint8_t> s = big.stream();
    BaacFlacDecoder* dec = baac_flac_decoder_create();
    baac_flac_decoder_set_md5_check(dec, 1);
    const int took = baac_flac_decoder_feed(dec, s.data(), (int)s.size());
    CHECK(took > 0 && took < (int)s.size() && baac_flac_decoder_pending_bytes(dec) <= took,
          "a feed of %zu bytes is taken in part: %d", s.size(), took);
    CHECK(baac_flac_decoder_feed(dec, s.data() + took, 1) == 0, "a full FIFO takes nothing");
    const FlacDecoded d =
        flac_decode(std::vector<uint8_t>(s.begin() + took, s.end()), SIZE_MAX, dec);
    CHECK(d.errors.empty() && d.pcm == big.pcm && d.verdict == BAAC_FLAC_MD5_MATCH,
          "the stream decodes through a FIFO smaller than it");
    baac_flac_decoder_destroy(dec);
  }

  /* Frames larger than the FIFO starts out with.  A stream that comes
   * without STREAMINFO says nothing about its frame size up front, so
   * the FIFO grows when the first frame fills it; a stream that has one
   * and then changes to more channels must still end as unsupported, not
   * as damaged, although the changed frame does not fit the FIFO that
   * was sized for the stream. */
  {
    StreamBuilder large(48000, 8, 24);
    for (int frame = 0; frame < 4; frame++) {
      large.add(TestSubframe::verbatim(), flac_gen_noise(4096, 8, 24, (unsigned)frame));
    }
    CHECK(large.offsets[1] > 90000, "a frame of %zu bytes", large.offsets[1]);
    for (size_t chunk : {SIZE_MAX, (size_t)5000}) {
      const FlacDecoded d = flac_decode(large.frames, chunk);
      CHECK(d.errors.empty() && d.lost == 0 && d.pcm == large.pcm,
            "frames of 98 KB without STREAMINFO decode (%zu frames, %zu errors)", d.frames.size(),
            d.errors.size());
    }
    StreamBuilder mono(48000, 1, 16);
    for (int frame = 0; frame < 3; frame++) {
      mono.add(TestSubframe::fixed(2, 2), flac_gen_tones(4096, 1, 16, 0.4, (unsigned)frame));
    }
    std::vector<uint8_t> s = mono.stream();
    flac_append(s, large.frames);
    for (size_t chunk : {SIZE_MAX, (size_t)5000}) {
      const FlacDecoded d = flac_decode(s, chunk);
      CHECK(d.frames.size() == 3 && d.pcm == mono.pcm && d.errors.size() == 1 &&
                d.errors[0].code == AAC_ERR_UNSUPPORTED && d.lost == 0,
            "a change to frames larger than the FIFO is unsupported, not damage (error %d)",
            d.errors.empty() ? 0 : d.errors[0].code);
    }
  }

  /* Steady state: once the first frame is out, neither feeding nor
   * decoding allocates, at 32 bits with a side channel as well. */
  for (int bits : {16, 32}) {
    StreamBuilder steady(48000, 2, bits);
    for (int frame = 0; frame < 30; frame++) {
      TestFrame f;
      f.assignment = frame % 4;
      f.subframes = {TestSubframe::fixed(1, 3), test_lpc(4, 10, (unsigned)frame, 2)};
      steady.add(f, flac_gen_tones(4096, 2, bits, 0.001, (unsigned)frame));
    }
    const std::vector<uint8_t> s = steady.stream();
    BaacFlacDecoder* dec = baac_flac_decoder_create();
    baac_flac_decoder_set_md5_check(dec, 1);
    std::vector<int32_t> buf(4096 * 2);
    std::vector<int32_t> pcm;
    pcm.reserve(steady.pcm.size());
    long steady_allocations = 0;
    int frames = 0;
    for (size_t pos = 0; pos < s.size();) {
      long before = g_allocations;
      const int took =
          baac_flac_decoder_feed(dec, s.data() + pos, (int)std::min<size_t>(3000, s.size() - pos));
      if (frames > 0) {
        steady_allocations += g_allocations - before;
      }
      pos += (size_t)took;
      for (;;) {
        before = g_allocations;
        const int n = baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size());
        if (frames > 0) {
          steady_allocations += g_allocations - before;
        }
        if (n <= 0) {
          break;
        }
        frames++;
        pcm.insert(pcm.end(), buf.begin(), buf.begin() + (long)n * 2);
      }
    }
    BaacFlacMd5Verdict verdict;
    const long before = g_allocations;
    CHECK(baac_flac_decoder_finish(dec, &verdict) == AAC_OK && verdict == BAAC_FLAC_MD5_MATCH &&
              pcm == steady.pcm && frames == 30,
          "%d bits: the steady stream decodes", bits);
    steady_allocations += g_allocations - before;
    CHECK(steady_allocations == 0, "%d bits: %ld allocations after the first frame", bits,
          steady_allocations);
    baac_flac_decoder_destroy(dec);
  }
}

/* ── seeking ───────────────────────────────────────────────────── */

static void test_seek() {
  for (int variable = 0; variable < 2; variable++) {
    StreamBuilder b(44100, 2, 16);
    b.variable_block_size = variable != 0;
    std::vector<uint64_t> positions;
    for (int frame = 0; frame < 12; frame++) {
      const int block = variable ? 500 + 97 * frame : 1152;
      positions.push_back(b.samples);
      b.add(TestSubframe::fixed(2, variable ? 0 : 2),
            flac_gen_tones(block, 2, 16, 0.4, (unsigned)frame));
    }
    const std::vector<uint8_t> s = b.stream();
    const size_t header = s.size() - b.frames.size();
    BaacFlacDecoder* dec = baac_flac_decoder_create();
    baac_flac_decoder_set_md5_check(dec, 1);
    std::vector<int32_t> buf(4096 * 2);
    /* The header and three frames. */
    baac_flac_decoder_feed(dec, s.data(), (int)(header + b.offsets[3] + 50));
    int frames = 0;
    while (baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size()) > 0) {
      frames++;
    }
    CHECK(frames == 3 && baac_flac_decoder_pending_bytes(dec) == 50, "three frames, then a seek");
    CHECK(baac_flac_decoder_seek_reset(dec) == AAC_OK && baac_flac_decoder_pending_bytes(dec) == 0,
          "the buffered input is dropped");
    /* From the middle of the eighth frame: the ninth is the next. */
    const size_t from = header + b.offsets[7] + (b.offsets[8] - b.offsets[7]) / 2;
    baac_flac_decoder_feed(dec, s.data() + from, (int)(s.size() - from));
    BaacFlacFrameInfo frame = {};
    const int n = baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size());
    baac_flac_decoder_last_frame_info(dec, &frame);
    CHECK(n == b.blocks[8] && frame.sample_position == positions[8] &&
              memcmp(buf.data(), b.pcm.data() + positions[8] * 2,
                     (size_t)n * 2 * sizeof(int32_t)) == 0,
          "%s block size: decoding resumes at the next frame, position %llu (expected %llu)",
          variable ? "variable" : "fixed", (unsigned long long)frame.sample_position,
          (unsigned long long)positions[8]);
    frames = 1;
    while (baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size()) > 0) {
      frames++;
    }
    BaacFlacMd5Verdict verdict;
    CHECK(frames == 4 && baac_flac_decoder_lost_frames(dec) == 0 &&
              baac_flac_decoder_finish(dec, &verdict) == AAC_OK && verdict == BAAC_FLAC_MD5_UNKNOWN,
          "a seek loses nothing and leaves the MD5 undecided");
    baac_flac_decoder_destroy(dec);
  }
}

/* ── the API's argument checks ─────────────────────────────────── */

static void test_arguments() {
  int32_t pcm[16];
  uint8_t byte = 0;
  BaacFlacStreamInfo info;
  BaacFlacFrameInfo frame;
  CHECK(baac_flac_decoder_feed(nullptr, &byte, 1) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_decode_next(nullptr, pcm, 16) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_reset(nullptr) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_finish(nullptr, nullptr) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_seek_reset(nullptr) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_end_input(nullptr) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_pending_bytes(nullptr) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_lost_frames(nullptr) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_stream_info(nullptr, &info) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_last_frame_info(nullptr, &frame) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_seek_points(nullptr, nullptr) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_first_frame_offset(nullptr) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_set_md5_check(nullptr, 1) == AAC_ERR_INVALID_ARG,
        "a null decoder is refused everywhere");
  baac_flac_decoder_destroy(nullptr);
  BaacFlacDecoder* dec = baac_flac_decoder_create();
  CHECK(dec != nullptr, "a decoder");
  CHECK(baac_flac_decoder_feed(dec, nullptr, 1) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_feed(dec, &byte, -1) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_feed(dec, nullptr, 0) == 0,
        "feed checks its arguments");
  CHECK(baac_flac_decoder_decode_next(dec, nullptr, 16) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_decode_next(dec, pcm, 0) == AAC_ERR_INVALID_ARG &&
            baac_flac_decoder_decode_next(dec, pcm, 16) == 0,
        "decode checks its arguments");
  CHECK(baac_flac_decoder_stream_info(dec, &info) == AAC_ERR_STATE &&
            baac_flac_decoder_last_frame_info(dec, &frame) == AAC_ERR_STATE &&
            baac_flac_decoder_seek_points(dec, nullptr) == 0 &&
            baac_flac_decoder_lost_frames(dec) == 0 && baac_flac_decoder_pending_bytes(dec) == 0,
        "a fresh decoder knows nothing");
  CHECK(baac_flac_decoder_end_input(dec) == AAC_OK &&
            baac_flac_decoder_feed(dec, &byte, 1) == AAC_ERR_STATE &&
            baac_flac_decoder_reset(dec) == AAC_OK && baac_flac_decoder_feed(dec, &byte, 1) == 1,
        "the end of the input holds until a reset");
  baac_flac_decoder_destroy(dec);
}

int main() {
  test_rfc_examples();
  test_subframe_types();
  test_stereo();
  test_layouts_and_depths();
  test_block_sizes();
  test_chunking();
  test_damaged_frame();
  test_random_damage();
  test_truncation();
  test_frame_start();
  test_frame_search();
  test_property_change();
  test_metadata();
  test_md5();
  test_limits();
  test_seek();
  test_arguments();
  return test_summary("test_flac_decoder");
}
