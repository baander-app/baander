/* The FLAC encoder through its public API, checked with the library's
 * own decoder: every stream has to come back bit for bit, with the
 * sample total and the MD5 its header states, across bit depths, channel
 * counts, sample rates and levels; the stream's lifecycle (header,
 * frames, a short last block, the final header with its seek points);
 * what the encoder refuses; and the shape of what it writes, which has
 * to stay within the ceilings of each level.  The decoders of flac and
 * FFmpeg check the same streams in test_flac_interop. */
#include "test_flac_util.h"

/* ── helpers ───────────────────────────────────────────────────── */

/* The ceilings of each level: block size, largest linear predictor
 * order (0: fixed predictors only), largest Rice partition order, and
 * whether a stereo pair may be stored other than as left and right. */
struct LevelCeiling {
  int block_size;
  int max_lpc_order;
  int max_partition_order;
  bool stereo;
};

static const LevelCeiling kCeilings[9] = {
    {1152, 0, 3, false}, {1152, 0, 3, true},  {1152, 0, 3, true},
    {4096, 6, 4, false}, {4096, 8, 4, true},  {4096, 8, 5, true},
    {4096, 8, 6, true},  {4096, 12, 6, true}, {4096, 12, 6, true},
};

/* The size no frame may exceed: every channel unencoded. */
static size_t frame_bound(int n, int channels, int bits) {
  return FLAC_MAX_FRAME_HEADER_BYTES + ((size_t)channels * (8 + (size_t)n * (size_t)bits) + 7) / 8 +
         2;
}

/* Encodes, decodes with the library's decoder and requires the samples,
 * the properties, the total and the MD5 to be the input's. */
static FlacEncoded roundtrip(const char* name, const std::vector<int32_t>& pcm, int rate,
                             int channels, int bits, int level, size_t chunk = SIZE_MAX) {
  const FlacEncoded e = flac_encode(pcm, rate, channels, bits, level, chunk);
  CHECK(e.error == AAC_OK, "%s: encode error %d", name, e.error);
  if (e.error != AAC_OK) {
    return e;
  }
  const FlacDecoded d = flac_decode(e.stream, 4099);
  CHECK(d.errors.empty() && d.finish == AAC_OK && d.lost == 0, "%s: the stream decodes cleanly",
        name);
  CHECK(d.pcm == pcm, "%s: %zu values decoded of %zu, %s", name, d.pcm.size(), pcm.size(),
        d.pcm.size() == pcm.size() ? "and they differ" : "a different count");
  CHECK(d.verdict == BAC_FLAC_MD5_MATCH, "%s: the MD5 in the header is the input's (%d)", name,
        (int)d.verdict);
  const uint64_t total = pcm.size() / (size_t)channels;
  CHECK(d.have_info && d.info.sample_rate == rate && d.info.channels == channels &&
            d.info.bits_per_sample == bits && d.info.total_samples == total,
        "%s: the header states %d Hz, %d channels, %d bits, %llu samples", name, d.info.sample_rate,
        d.info.channels, d.info.bits_per_sample, (unsigned long long)d.info.total_samples);
  CHECK(d.info.min_block_size == kCeilings[level].block_size &&
            d.info.max_block_size == kCeilings[level].block_size,
        "%s: block size %d", name, d.info.max_block_size);
  return e;
}

/* Every frame within the ceilings of the level, in the streamable
 * subset, and no larger than its channels unencoded. */
static void check_shape(const char* name, const FlacEncoded& e, int level, int rate, int channels,
                        int bits) {
  const LevelCeiling& ceiling = kCeilings[level];
  bool complete = false;
  const std::vector<FlacFrameShape> frames = flac_inspect(e.stream, &complete);
  CHECK(complete, "%s: the stream parses to its end (%zu frames)", name, frames.size());
  bool blocks = true;
  bool numbers = true;
  bool sizes = true;
  bool assignments = true;
  bool orders = true;
  bool partitions = true;
  bool parameters = true;
  bool sums = true;
  bool headers = true;
  for (size_t i = 0; i < frames.size(); i++) {
    const FlacFrameShape& f = frames[i];
    const FlacFrameHeader& h = f.header;
    blocks = blocks && (h.block_size == ceiling.block_size ||
                        (i + 1 == frames.size() && h.block_size < ceiling.block_size));
    numbers = numbers && !h.variable_block_size && h.number == i;
    headers =
        headers && h.sample_rate == rate && h.bits_per_sample == bits && h.channels == channels;
    sizes = sizes && f.bytes <= frame_bound(h.block_size, channels, bits);
    assignments = assignments && (ceiling.stereo || h.assignment == FLAC_ASSIGN_INDEPENDENT);
    for (const FlacSubframeShape& sf : f.subframes) {
      if (sf.type == 3) {
        orders = orders && sf.order <= ceiling.max_lpc_order && sf.order <= 12;
        /* At 16 bits and below a decoder can add up the prediction in
         * 32 bits. */
        sums = sums && (bits > 16 || sf.bps + sf.precision + flac_ceil_log2(sf.order) <= 32);
      }
      if (sf.type >= 2) {
        partitions = partitions && sf.partition_order <= ceiling.max_partition_order &&
                     (h.block_size >> sf.partition_order) > sf.order;
        parameters = parameters && (sf.parameter_bits == 4 || bits > 16) &&
                     sf.max_parameter <= (sf.parameter_bits == 5 ? 30 : 14);
      }
    }
  }
  CHECK(blocks, "%s: every block but the last has %d samples", name, ceiling.block_size);
  CHECK(numbers, "%s: fixed block size frames numbered from zero", name);
  CHECK(headers, "%s: every frame header names the rate, the depth and the channels", name);
  CHECK(sizes, "%s: no frame is larger than its channels unencoded", name);
  CHECK(assignments, "%s: the level stores a pair as left and right only", name);
  CHECK(orders, "%s: no linear predictor above order %d", name, ceiling.max_lpc_order);
  CHECK(partitions, "%s: no partition order above %d", name, ceiling.max_partition_order);
  CHECK(parameters, "%s: 5-bit Rice parameters only above 16 bits", name);
  CHECK(sums, "%s: predictions that fit a 32-bit sum", name);
}

static size_t frame_bytes(const FlacEncoded& e) { return e.stream.size() - (size_t)e.header_bytes; }

/* ── round trips ───────────────────────────────────────────────── */

static void test_roundtrip_matrix() {
  /* Every depth with every channel count at one rate, and every rate at
   * two formats, at every level: 2.2 blocks of 4096, so that full blocks
   * and a short last one are coded. */
  struct Format {
    int rate, channels, bits;
  };
  std::vector<Format> formats;
  for (int bits : {8, 12, 16, 20, 24, 32}) {
    for (int channels = 1; channels <= 8; channels++) {
      formats.push_back({48000, channels, bits});
    }
  }
  for (int rate : {8000, 44100, 96000, 192000, 22254, 655350}) {
    formats.push_back({rate, 2, 16});
    formats.push_back({rate, 6, 24});
  }
  const int samples = 9000;
  int streams = 0;
  for (const Format& f : formats) {
    const std::vector<int32_t> pcm =
        flac_gen_tones(samples, f.channels, f.bits, 0.6, 11u + (unsigned)streams, 0.01);
    for (int level = 0; level <= 8; level++) {
      /* The levels between the ones every format is checked at take a
       * few formats only. */
      const bool key =
          level == 0 || level == 1 || level == 2 || level == 3 || level == 5 || level == 8;
      if (!key && !(f.rate == 48000 && (f.channels == 2 || f.channels == 5) && f.bits >= 16)) {
        continue;
      }
      char name[96];
      snprintf(name, sizeof(name), "%d Hz, %d channels, %d bits, level %d", f.rate, f.channels,
               f.bits, level);
      const FlacEncoded e =
          roundtrip(name, pcm, f.rate, f.channels, f.bits, level, 1000 + (size_t)level * 577);
      check_shape(name, e, level, f.rate, f.channels, f.bits);
      CHECK(frame_bytes(e) * 8 < (size_t)samples * (size_t)f.channels * (size_t)f.bits,
            "%s: smaller than the samples unencoded", name);
      streams++;
    }
  }
  printf("round trips: %d streams over %zu formats, exact\n", streams, formats.size());
}

/* 32-bit stereo with the most negative value and alternation between
 * the full-scale extremes: the side channel takes 33 bits, and nothing
 * may overflow on the way. */
static void test_extremes() {
  const int n = 10000;
  std::vector<int32_t> pcm((size_t)n * 2);
  unsigned seed = 3u;
  for (int i = 0; i < n; i++) {
    seed = seed * 1664525u + 1013904223u;
    const int kind = (int)(seed >> 30);
    /* Stretches that alternate in step, in opposition, and at random. */
    const int32_t a = i % 2 ? INT32_MAX : INT32_MIN;
    pcm[(size_t)i * 2] = i < 3000 ? a : (kind & 1 ? INT32_MAX : INT32_MIN);
    pcm[(size_t)i * 2 + 1] = i < 1500   ? a
                             : i < 3000 ? (i % 2 ? INT32_MIN : INT32_MAX)
                                        : (kind & 2 ? INT32_MAX : INT32_MIN);
  }
  for (int level : {0, 1, 2, 3, 5, 8}) {
    char name[64];
    snprintf(name, sizeof(name), "32-bit extremes, level %d", level);
    const FlacEncoded e = roundtrip(name, pcm, 48000, 2, 32, level);
    check_shape(name, e, level, 48000, 2, 32);
  }
  /* Full-scale 32-bit noise: no predictor has a residual that fits, and
   * the samples are stored unencoded. */
  const std::vector<int32_t> noise = flac_gen_noise(9000, 2, 32, 9u);
  for (int level : {0, 2, 5, 8}) {
    char name[64];
    snprintf(name, sizeof(name), "32-bit noise, level %d", level);
    const FlacEncoded e = roundtrip(name, noise, 48000, 2, 32, level);
    check_shape(name, e, level, 48000, 2, 32);
    bool verbatim = true;
    for (const FlacFrameShape& f : flac_inspect(e.stream)) {
      for (const FlacSubframeShape& sf : f.subframes) {
        verbatim = verbatim && sf.type == 1;
      }
    }
    CHECK(verbatim, "%s: unencoded subframes", name);
  }
  /* A smooth 32-bit signal with jumps across the whole range: where a
   * predictor's residual would reach 2^31 in a block, that predictor is
   * dropped, and the stream still comes back exactly. */
  std::vector<int32_t> jumps((size_t)n * 2);
  for (int i = 0; i < n; i++) {
    const double phase = 2.0 * M_PI * (double)i / 300.0;
    const int32_t smooth = (int32_t)(2.0e9 * sin(phase));
    const bool flip = (i / 700) % 2 == 1 && i % 700 < 2;
    jumps[(size_t)i * 2] = flip ? (smooth > 0 ? INT32_MIN : INT32_MAX) : smooth;
    jumps[(size_t)i * 2 + 1] = (int32_t)(2.1e9 * cos(phase));
  }
  for (int level : {2, 5, 8}) {
    char name[64];
    snprintf(name, sizeof(name), "32-bit jumps, level %d", level);
    const FlacEncoded e = roundtrip(name, jumps, 96000, 2, 32, level);
    check_shape(name, e, level, 96000, 2, 32);
  }
}

/* Signals with a form of their own in the format. */
static void test_signals() {
  const int n = 10000;
  for (int level : {0, 2, 5, 8}) {
    for (int bits : {16, 24}) {
      char name[96];
      /* Digital silence and a constant offset: constant subframes, a
       * few bytes per frame. */
      for (int32_t value : {0, -1234}) {
        snprintf(name, sizeof(name), "constant %d at %d bits, level %d", value, bits, level);
        const std::vector<int32_t> pcm((size_t)n * 2, value);
        const FlacEncoded e = roundtrip(name, pcm, 44100, 2, bits, level);
        bool constant = true;
        const std::vector<FlacFrameShape> frames = flac_inspect(e.stream);
        for (const FlacFrameShape& f : frames) {
          for (const FlacSubframeShape& sf : f.subframes) {
            constant = constant && sf.type == 0;
          }
        }
        /* A header, a byte and the value per channel, the checksum. */
        CHECK(constant && frame_bytes(e) <= frames.size() * 20, "%s: constant subframes, %zu bytes",
              name, frame_bytes(e));
      }
      /* Full-scale noise: nothing to gain, and no more than unencoded
       * samples plus the overhead of a frame. */
      {
        snprintf(name, sizeof(name), "noise at %d bits, level %d", bits, level);
        const std::vector<int32_t> pcm = flac_gen_noise(n, 2, bits, 21u);
        const FlacEncoded e = roundtrip(name, pcm, 44100, 2, bits, level);
        size_t bound = 0;
        for (const FlacFrameShape& f : flac_inspect(e.stream)) {
          bound += frame_bound(f.header.block_size, 2, bits);
        }
        CHECK(frame_bytes(e) <= bound, "%s: %zu bytes, unencoded frames take %zu", name,
              frame_bytes(e), bound);
        check_shape(name, e, level, 44100, 2, bits);
      }
      /* One and eight low bits that are zero in every sample: they are
       * not coded, in either channel. */
      for (int wasted : {1, 8}) {
        snprintf(name, sizeof(name), "%d wasted bits at %d bits, level %d", wasted, bits, level);
        std::vector<int32_t> pcm = flac_gen_tones(n, 2, bits - wasted, 0.7, 5u, 0.05);
        for (int32_t& v : pcm) {
          v = (int32_t)((uint32_t)v << wasted);
        }
        const FlacEncoded e = roundtrip(name, pcm, 44100, 2, bits, level);
        const FlacEncoded narrow =
            flac_encode(flac_gen_tones(n, 2, bits - wasted, 0.7, 5u, 0.05), 44100, 2,
                        bits == 16 && wasted == 1 ? 16 : bits, level);
        bool all = true;
        for (const FlacFrameShape& f : flac_inspect(e.stream)) {
          /* The side channel of a pair of even channels is even too;
           * the mid channel need not be. */
          for (size_t c = 0; c < f.subframes.size(); c++) {
            const bool mid = f.header.assignment == FLAC_ASSIGN_MID_SIDE && c == 0;
            all = all && (mid || f.subframes[c].wasted >= wasted);
          }
        }
        CHECK(all, "%s: every subframe drops them", name);
        /* About what the same signal takes without the padding bits. */
        CHECK(frame_bytes(e) < frame_bytes(narrow) + frame_bytes(narrow) / 20 + 2000,
              "%s: %zu bytes, the signal without them takes %zu", name, frame_bytes(e),
              frame_bytes(narrow));
      }
    }
  }
}

/* A pure sine is what a linear predictor is for.  220 Hz at 48 kHz: at
 * the default level it takes under a quarter of its samples unencoded,
 * at 16 bits and at 24.  (Higher tones at 24 bits do not reach a
 * quarter: the predictor's coefficients have 15 bits at most, and their
 * rounding leaves a residual in proportion to the amplitude.) */
static void test_sine() {
  for (int bits : {16, 24}) {
    const int n = 48000;
    std::vector<int32_t> pcm((size_t)n);
    for (int i = 0; i < n; i++) {
      pcm[(size_t)i] = (int32_t)llround(0.5 * (double)flac_sample_max(bits) *
                                        sin(2.0 * M_PI * 220.0 * (double)i / 48000.0));
    }
    char name[64];
    snprintf(name, sizeof(name), "a sine at %d bits", bits);
    const FlacEncoded e = roundtrip(name, pcm, 48000, 1, bits, BAC_FLAC_LEVEL_DEFAULT);
    const double ratio = (double)frame_bytes(e) * 8.0 / ((double)n * (double)bits);
    CHECK(ratio < 0.25, "%s: %.3f of its samples unencoded", name, ratio);
    const FlacEncoded fixed_only = flac_encode(pcm, 48000, 1, bits, 2);
    CHECK(frame_bytes(e) < frame_bytes(fixed_only), "%s: smaller with linear prediction", name);
    printf("%s: level 5 takes %.3f of the samples unencoded\n", name, ratio);
  }
}

/* ── short streams ─────────────────────────────────────────────── */

static void test_short_inputs() {
  for (int level : {0, 5, 8}) {
    const int block = kCeilings[level].block_size;
    for (int samples : {0, 1, 2, 15, 16, 17, 31, 33, block - 1, block, block + 1}) {
      for (int channels : {1, 2}) {
        char name[96];
        snprintf(name, sizeof(name), "%d samples, %d channels, level %d", samples, channels, level);
        const std::vector<int32_t> pcm = flac_gen_tones(samples, channels, 16, 0.5, 4u);
        const FlacEncoded e = roundtrip(name, pcm, 44100, channels, 16, level);
        const size_t frames = (size_t)((samples + block - 1) / block);
        bool complete = false;
        const std::vector<FlacFrameShape> shape = flac_inspect(e.stream, &complete);
        CHECK(complete && shape.size() == frames, "%s: %zu frames, expected %zu", name,
              shape.size(), frames);
        CHECK(frames == 0 || shape.back().header.block_size == (samples - 1) % block + 1,
              "%s: the last frame holds the rest", name);
        check_shape(name, e, level, 44100, channels, 16);
      }
    }
  }
}

/* ── refusals ──────────────────────────────────────────────────── */

static void test_refusals() {
  const struct {
    int rate, channels, bits, level, error;
    const char* what;
  } cases[] = {
      {700001, 2, 16, 5, AAC_ERR_UNSUPPORTED, "a rate no frame header can name"},
      {655351, 2, 16, 5, AAC_ERR_UNSUPPORTED, "a rate one above the largest codable"},
      {65537, 2, 16, 5, AAC_ERR_UNSUPPORTED, "a rate between the hertz and ten-hertz forms"},
      {1048576, 2, 16, 5, AAC_ERR_INVALID_ARG, "a rate above 20 bits"},
      {0, 2, 16, 5, AAC_ERR_INVALID_ARG, "no rate"},
      {-44100, 2, 16, 5, AAC_ERR_INVALID_ARG, "a negative rate"},
      {44100, 9, 16, 5, AAC_ERR_INVALID_ARG, "nine channels"},
      {44100, 0, 16, 5, AAC_ERR_INVALID_ARG, "no channel"},
      {44100, 2, 17, 5, AAC_ERR_INVALID_ARG, "17 bits"},
      {44100, 2, 4, 5, AAC_ERR_INVALID_ARG, "4 bits"},
      {44100, 2, 0, 5, AAC_ERR_INVALID_ARG, "no depth"},
      {44100, 2, 33, 5, AAC_ERR_INVALID_ARG, "33 bits"},
      {44100, 2, 16, 9, AAC_ERR_INVALID_ARG, "level 9"},
      {44100, 2, 16, -1, AAC_ERR_INVALID_ARG, "level -1"},
  };
  for (const auto& c : cases) {
    int error = 12345;
    BacFlacEncoder* enc = bac_flac_encoder_create(c.rate, c.channels, c.bits, c.level, &error);
    CHECK(enc == nullptr && error == c.error, "%s is refused with %d (got %d)", c.what, c.error,
          error);
    bac_flac_encoder_destroy(enc);
    CHECK(bac_flac_encoder_create(c.rate, c.channels, c.bits, c.level, nullptr) == nullptr,
          "%s is refused without an error pointer", c.what);
  }
  int error = 12345;
  BacFlacEncoder* ok = bac_flac_encoder_create(655350, 8, 32, 8, &error);
  CHECK(ok != nullptr && error == AAC_OK, "the largest format is accepted");
  bac_flac_encoder_destroy(ok);

  /* A sample outside the bit depth: the call is refused and takes
   * nothing, so the stream goes on as if it had not been made. */
  for (int bits : {8, 12, 16, 20, 24}) {
    const std::vector<int32_t> pcm = flac_gen_tones(3000, 2, bits, 0.5, 6u);
    const FlacEncoded clean = flac_encode(pcm, 44100, 2, bits, 5);
    BacFlacEncoder* enc = bac_flac_encoder_create(44100, 2, bits, 5, nullptr);
    std::vector<uint8_t> stream((size_t)bac_flac_encoder_header_bytes(enc));
    bac_flac_encoder_write_header(enc, stream.data(), (int)stream.size());
    std::vector<uint8_t> buf((size_t)bac_flac_encoder_max_output_bytes(enc, 8192) + 65536);
    int got = bac_flac_encoder_encode(enc, pcm.data(), 1000, buf.data(), (int)buf.size());
    stream.insert(stream.end(), buf.begin(), buf.begin() + std::max(got, 0));
    for (int64_t outside : {flac_sample_max(bits) + 1, flac_sample_min(bits) - 1,
                            (int64_t)INT32_MAX, (int64_t)INT32_MIN}) {
      std::vector<int32_t> bad(pcm.begin() + 2000, pcm.begin() + 4000);
      bad[1777] = (int32_t)outside;
      CHECK(bac_flac_encoder_encode(enc, bad.data(), 1000, buf.data(), (int)buf.size()) ==
                AAC_ERR_INVALID_ARG,
            "%d bits: a sample of %lld is refused", bits, (long long)outside);
    }
    got = bac_flac_encoder_encode(enc, pcm.data() + 2000, 2000, buf.data(), (int)buf.size());
    stream.insert(stream.end(), buf.begin(), buf.begin() + std::max(got, 0));
    got = bac_flac_encoder_finish(enc, buf.data(), (int)buf.size());
    stream.insert(stream.end(), buf.begin(), buf.begin() + std::max(got, 0));
    bac_flac_encoder_final_header(enc, stream.data(), bac_flac_encoder_header_bytes(enc));
    CHECK(stream == clean.stream, "%d bits: a refused call leaves the stream as it was", bits);
    CHECK(
        bac_flac_encoder_encode(enc, pcm.data(), 1, buf.data(), (int)buf.size()) == AAC_ERR_STATE,
        "no input after the finish");
    bac_flac_encoder_destroy(enc);
  }
  /* At 32 bits every value is a sample. */
  const std::vector<int32_t> edge = {INT32_MIN, INT32_MAX, 0, -1};
  CHECK(flac_decode(flac_encode(edge, 44100, 2, 32, 5).stream).pcm == edge,
        "at 32 bits every value is accepted");
}

/* An output buffer below the published bound is refused before any
 * input is taken. */
static void test_output_bound() {
  for (int level : {0, 5}) {
    const int block = kCeilings[level].block_size;
    const std::vector<int32_t> pcm = flac_gen_noise(block * 3, 2, 16, 8u);
    const FlacEncoded clean = flac_encode(pcm, 44100, 2, 16, level);
    BacFlacEncoder* enc = bac_flac_encoder_create(44100, 2, 16, level, nullptr);
    std::vector<uint8_t> stream((size_t)bac_flac_encoder_header_bytes(enc));
    CHECK(bac_flac_encoder_write_header(enc, stream.data(), (int)stream.size() - 1) ==
              AAC_ERR_OVERFLOW,
          "a header buffer one byte short is refused");
    CHECK(bac_flac_encoder_write_header(enc, stream.data(), (int)stream.size()) ==
              (int)stream.size(),
          "the header is written");
    CHECK(bac_flac_encoder_block_size(enc) == block, "level %d: blocks of %d", level, block);
    /* Less than a block: nothing can come out, and no room is needed. */
    CHECK(bac_flac_encoder_max_output_bytes(enc, block - 1) == 0 &&
              bac_flac_encoder_encode(enc, pcm.data(), block - 1, nullptr, 0) == 0,
          "a call that completes no block needs no output buffer");
    /* One more sample completes a block, and two more blocks follow. */
    const int need = bac_flac_encoder_max_output_bytes(enc, 2 * block + 1);
    CHECK(need == 3 * (int)frame_bound(block, 2, 16), "the bound for three frames is %d", need);
    std::vector<uint8_t> buf((size_t)need);
    const int32_t* rest = pcm.data() + (size_t)(block - 1) * 2;
    CHECK(bac_flac_encoder_encode(enc, rest, 2 * block + 1, buf.data(), need - 1) ==
              AAC_ERR_OVERFLOW,
          "a buffer one byte below the bound is refused");
    const int got = bac_flac_encoder_encode(enc, rest, 2 * block + 1, buf.data(), need);
    CHECK(got > 0 && got <= need, "the same call with the bound succeeds: %d", got);
    stream.insert(stream.end(), buf.begin(), buf.begin() + std::max(got, 0));
    CHECK(bac_flac_encoder_max_output_bytes(enc, 0) == 0, "nothing is left for the finish");
    CHECK(bac_flac_encoder_finish(enc, nullptr, 0) == 0, "the finish writes nothing");
    bac_flac_encoder_final_header(enc, stream.data(), bac_flac_encoder_header_bytes(enc));
    CHECK(stream == clean.stream, "the refused call took no input: the stream is the usual one");
    bac_flac_encoder_destroy(enc);

    /* The finish has its own bound: the short last frame. */
    enc = bac_flac_encoder_create(44100, 2, 16, level, nullptr);
    bac_flac_encoder_write_header(enc, stream.data(), (int)stream.size());
    bac_flac_encoder_encode(enc, pcm.data(), 100, nullptr, 0);
    const int last = bac_flac_encoder_max_output_bytes(enc, 0);
    CHECK(last == (int)frame_bound(100, 2, 16), "the bound of the last frame is %d", last);
    CHECK(bac_flac_encoder_finish(enc, buf.data(), last - 1) == AAC_ERR_OVERFLOW,
          "a finish buffer one byte short is refused");
    CHECK(bac_flac_encoder_finish(enc, buf.data(), last) > 0, "and the finish still succeeds");
    bac_flac_encoder_destroy(enc);
  }
}

/* ── the stream's lifecycle ────────────────────────────────────── */

static void test_lifecycle() {
  const std::vector<int32_t> pcm = flac_gen_tones(9000, 2, 16, 0.5, 31u);
  const std::vector<int32_t> other = flac_gen_tones(5000, 2, 16, 0.3, 32u);
  for (int level : {1, 4, 5, 8}) {
    /* A second stream after the finish is what a fresh encoder writes;
     * nothing of the first carries over. */
    const FlacEncoded fresh = flac_encode(other, 44100, 2, 16, level);
    BacFlacEncoder* enc = bac_flac_encoder_create(44100, 2, 16, level, nullptr);
    const FlacEncoded first = flac_encode(pcm, 44100, 2, 16, level, 777, true, nullptr, enc);
    const FlacEncoded second = flac_encode(other, 44100, 2, 16, level, 333, true, nullptr, enc);
    CHECK(first.error == AAC_OK && second.error == AAC_OK && second.stream == fresh.stream,
          "level %d: a second stream equals a fresh encoder's", level);
    bac_flac_encoder_destroy(enc);

    /* However the input is cut up, the stream is the same, and two
     * encodes of one input give the same bytes. */
    const FlacEncoded whole = flac_encode(pcm, 44100, 2, 16, level);
    for (size_t chunk : {(size_t)1, (size_t)97, (size_t)1152, (size_t)4096, (size_t)5000}) {
      CHECK(flac_encode(pcm, 44100, 2, 16, level, chunk).stream == whole.stream,
            "level %d: the same stream fed %zu samples at a time", level, chunk);
    }
  }

  /* Calls out of order. */
  BacFlacEncoder* enc = bac_flac_encoder_create(48000, 2, 16, 5, nullptr);
  std::vector<uint8_t> buf(1 << 20);
  BacFlacFrameInfo frame;
  BacFlacEncoderOptions options;
  bac_flac_encoder_options_init(&options);
  CHECK(options.padding_bytes == 0 && options.total_samples_hint == 0 && options.seek_table == 0,
        "the default options");
  CHECK(
      bac_flac_encoder_encode(enc, pcm.data(), 10, buf.data(), (int)buf.size()) == AAC_ERR_STATE &&
          bac_flac_encoder_finish(enc, buf.data(), (int)buf.size()) == AAC_ERR_STATE &&
          bac_flac_encoder_final_header(enc, buf.data(), (int)buf.size()) == AAC_ERR_STATE &&
          bac_flac_encoder_last_frame_info(enc, &frame) == AAC_ERR_STATE,
      "nothing but the header before the header");
  CHECK(bac_flac_encoder_set_options(enc, &options) == AAC_OK, "options before the header");
  CHECK(bac_flac_encoder_write_header(enc, buf.data(), (int)buf.size()) == 42,
        "a header of the marker and STREAMINFO: 42 bytes");
  CHECK(bac_flac_encoder_write_header(enc, buf.data(), (int)buf.size()) == AAC_ERR_STATE &&
            bac_flac_encoder_set_options(enc, &options) == AAC_ERR_STATE &&
            bac_flac_encoder_final_header(enc, buf.data(), (int)buf.size()) == AAC_ERR_STATE &&
            bac_flac_encoder_last_frame_info(enc, &frame) == AAC_ERR_STATE,
        "no second header, no options and no final header while the stream runs");

  /* One block per call: each call returns one frame, which the frame
   * information describes. */
  uint64_t position = 0;
  size_t offset = 0;
  bool frames_ok = true;
  for (int f = 0; f < 2; f++) {
    const int got = bac_flac_encoder_encode(enc, pcm.data() + (size_t)f * 4096 * 2, 4096,
                                             buf.data(), (int)buf.size());
    frames_ok = frames_ok && got > 0 && bac_flac_encoder_last_frame_info(enc, &frame) == AAC_OK &&
                frame.bytes == got && frame.block_size == 4096 && frame.sample_position == position;
    FlacFrameHeader h;
    frames_ok = frames_ok && flac_frame_header_parse(buf.data(), (size_t)got, &h) > 0 &&
                h.number == (uint64_t)f && h.block_size == 4096;
    position += 4096;
    offset += (size_t)std::max(got, 0);
  }
  CHECK(bac_flac_encoder_encode(enc, pcm.data() + 8192 * 2, 808, buf.data(), (int)buf.size()) == 0,
        "a partial block writes nothing");
  BacFlacStreamInfo info;
  CHECK(bac_flac_encoder_stream_info(enc, &info) == AAC_OK && info.total_samples == 0 &&
            info.sample_rate == 48000 && info.channels == 2 && info.bits_per_sample == 16 &&
            info.min_block_size == 4096 && info.max_block_size == 4096,
        "the stream information before the finish has no total");
  const int last = bac_flac_encoder_finish(enc, buf.data(), (int)buf.size());
  frames_ok = frames_ok && last > 0 && bac_flac_encoder_last_frame_info(enc, &frame) == AAC_OK &&
              frame.bytes == last && frame.block_size == 808 && frame.sample_position == 8192;
  CHECK(frames_ok, "every call that writes a frame reports its bytes, samples and position");
  uint8_t digest[16];
  flac_pcm_md5(pcm, 16, digest);
  CHECK(bac_flac_encoder_stream_info(enc, &info) == AAC_OK && info.total_samples == 9000 &&
            memcmp(info.md5, digest, 16) == 0 && info.min_frame_bytes > 0 &&
            info.max_frame_bytes >= info.min_frame_bytes && info.min_frame_bytes == last,
        "after the finish it has the total, the MD5 and the frame sizes");
  CHECK(bac_flac_encoder_finish(enc, buf.data(), (int)buf.size()) == AAC_ERR_STATE &&
            bac_flac_encoder_encode(enc, pcm.data(), 10, buf.data(), (int)buf.size()) ==
                AAC_ERR_STATE,
        "a finished stream takes nothing");
  CHECK(bac_flac_encoder_final_header(enc, buf.data(), 41) == AAC_ERR_OVERFLOW &&
            bac_flac_encoder_final_header(enc, buf.data(), 42) == 42,
        "the final header has the length of the first");
  CHECK(bac_flac_encoder_set_options(enc, &options) == AAC_OK, "options after the finish");
  bac_flac_encoder_destroy(enc);

  /* Null handles and pointers. */
  CHECK(bac_flac_encoder_encode(nullptr, pcm.data(), 1, buf.data(), 1) == AAC_ERR_INVALID_ARG &&
            bac_flac_encoder_finish(nullptr, buf.data(), 1) == AAC_ERR_INVALID_ARG &&
            bac_flac_encoder_write_header(nullptr, buf.data(), 1) == AAC_ERR_INVALID_ARG &&
            bac_flac_encoder_final_header(nullptr, buf.data(), 1) == AAC_ERR_INVALID_ARG &&
            bac_flac_encoder_header_bytes(nullptr) == AAC_ERR_INVALID_ARG &&
            bac_flac_encoder_block_size(nullptr) == AAC_ERR_INVALID_ARG &&
            bac_flac_encoder_max_output_bytes(nullptr, 1) == AAC_ERR_INVALID_ARG &&
            bac_flac_encoder_set_options(nullptr, &options) == AAC_ERR_INVALID_ARG &&
            bac_flac_encoder_last_frame_info(nullptr, &frame) == AAC_ERR_INVALID_ARG &&
            bac_flac_encoder_stream_info(nullptr, &info) == AAC_ERR_INVALID_ARG,
        "a null encoder is refused everywhere");
  bac_flac_encoder_destroy(nullptr);
  bac_flac_encoder_options_init(nullptr);
}

/* ── the header ────────────────────────────────────────────────── */

static void test_header() {
  const int rate = 8000;
  const int n = 250000; /* 31 s at 8 kHz: four seek points */
  const std::vector<int32_t> pcm = flac_gen_tones(n, 1, 16, 0.5, 44u);
  uint8_t digest[16];
  flac_pcm_md5(pcm, 16, digest);
  static const uint8_t kZero[16] = {0};

  /* Rewritten: the total and the MD5 are the input's.  Not rewritten,
   * as on a pipe: both are zero, and the stream decodes all the same. */
  for (int level : {0, 5}) {
    const FlacEncoded with = flac_encode(pcm, rate, 1, 16, level, 5000, true);
    const FlacEncoded without = flac_encode(pcm, rate, 1, 16, level, 5000, false);
    BacFlacStreamInfo info;
    CHECK(with.header_bytes == 42 && without.header_bytes == 42,
          "without options the header is the marker and STREAMINFO");
    CHECK(flac_streaminfo_parse(with.stream.data() + 8, &info) &&
              info.total_samples == (uint64_t)n && memcmp(info.md5, digest, 16) == 0 &&
              info.min_frame_bytes > 0 && info.max_frame_bytes >= info.min_frame_bytes,
          "level %d: the rewritten header has the total, the MD5 and the frame sizes", level);
    CHECK(flac_streaminfo_parse(without.stream.data() + 8, &info) && info.total_samples == 0 &&
              memcmp(info.md5, kZero, 16) == 0 && info.min_frame_bytes == 0 &&
              info.max_frame_bytes == 0 && info.sample_rate == rate &&
              info.min_block_size == kCeilings[level].block_size,
          "level %d: the first header has zero for the total and the MD5", level);
    CHECK(std::equal(with.stream.begin() + 42, with.stream.end(), without.stream.begin() + 42,
                     without.stream.end()),
          "level %d: the rewrite changes the header only", level);
    const FlacDecoded d = flac_decode(without.stream);
    CHECK(d.errors.empty() && d.pcm == pcm && d.verdict == BAC_FLAC_MD5_UNKNOWN,
          "level %d: the stream without a rewrite decodes to the input", level);
  }

  /* A seek table: reserved from the total the caller expects, filled by
   * the final header with a point for every ten seconds, each naming a
   * real frame. */
  BacFlacEncoderOptions options;
  bac_flac_encoder_options_init(&options);
  options.total_samples_hint = (uint64_t)n;
  options.seek_table = 1;
  options.padding_bytes = 100;
  for (int level : {0, 5}) {
    const FlacEncoded e = flac_encode(pcm, rate, 1, 16, level, 5000, true, &options);
    const int points_expected = 4; /* 0 s, 10 s, 20 s, 30 s */
    CHECK(e.error == AAC_OK && e.header_bytes == 42 + 4 + points_expected * 18 + 4 + 100,
          "level %d: a header of %d bytes with four seek points and padding", level,
          e.header_bytes);
    BacFlacDecoder* dec = bac_flac_decoder_create();
    bac_flac_decoder_set_md5_check(dec, 1);
    const FlacDecoded d = flac_decode(e.stream, 3000, dec);
    CHECK(d.errors.empty() && d.pcm == pcm && d.verdict == BAC_FLAC_MD5_MATCH,
          "level %d: the stream with a seek table decodes", level);
    const BacFlacSeekPoint* points = nullptr;
    const int count = bac_flac_decoder_seek_points(dec, &points);
    CHECK(count == points_expected && bac_flac_decoder_first_frame_offset(dec) == e.header_bytes,
          "level %d: %d seek points", level, count);
    const std::vector<FlacFrameShape> frames = flac_inspect(e.stream);
    const int block = kCeilings[level].block_size;
    for (int i = 0; i < count; i++) {
      /* The frame that holds the target sample, at its real offset. */
      const uint64_t target = (uint64_t)i * 10 * (uint64_t)rate;
      const size_t index = (size_t)(target / (uint64_t)block);
      CHECK(index < frames.size() &&
                points[i].sample_position == (uint64_t)index * (uint64_t)block &&
                points[i].byte_offset == frames[index].offset - (size_t)e.header_bytes &&
                points[i].block_size == frames[index].header.block_size,
            "level %d: seek point %d names the frame at sample %llu", level, i,
            (unsigned long long)points[i].sample_position);
    }
    bac_flac_decoder_destroy(dec);

    /* Not rewritten: the table holds placeholders only, which a decoder
     * passes over. */
    const FlacEncoded pipe = flac_encode(pcm, rate, 1, 16, level, 5000, false, &options);
    dec = bac_flac_decoder_create();
    const FlacDecoded p = flac_decode(pipe.stream, SIZE_MAX, dec);
    CHECK(p.errors.empty() && p.pcm == pcm && bac_flac_decoder_seek_points(dec, &points) == 0,
          "level %d: a reserved table that was never filled holds no point", level);
    bac_flac_decoder_destroy(dec);

    /* A stream shorter than announced leaves placeholders at the end;
     * a longer one has points up to the announced length. */
    const std::vector<int32_t> half(pcm.begin(), pcm.begin() + n / 2);
    const FlacEncoded shorter = flac_encode(half, rate, 1, 16, level, 5000, true, &options);
    dec = bac_flac_decoder_create();
    const FlacDecoded s = flac_decode(shorter.stream, SIZE_MAX, dec);
    CHECK(s.errors.empty() && s.pcm == half && bac_flac_decoder_seek_points(dec, &points) == 2,
          "level %d: a shorter stream fills the points it reaches", level);
    bac_flac_decoder_destroy(dec);
  }

  /* Without the total there is no table, whatever the option says; the
   * option alone reserves nothing. */
  bac_flac_encoder_options_init(&options);
  options.seek_table = 1;
  const FlacEncoded no_hint = flac_encode(pcm, rate, 1, 16, 5, 5000, true, &options);
  CHECK(no_hint.header_bytes == 42, "no total, no seek table");
  options.seek_table = 0;
  options.total_samples_hint = (uint64_t)n;
  const FlacEncoded no_table = flac_encode(pcm, rate, 1, 16, 5, 5000, true, &options);
  CHECK(no_table.header_bytes == 42 && no_table.stream == no_hint.stream,
        "a total without the option reserves nothing");

  /* Options that cannot be. */
  BacFlacEncoder* enc = bac_flac_encoder_create(rate, 1, 16, 5, nullptr);
  bac_flac_encoder_options_init(&options);
  options.padding_bytes = -1;
  CHECK(bac_flac_encoder_set_options(enc, &options) == AAC_ERR_INVALID_ARG, "negative padding");
  options.padding_bytes = 1 << 24;
  CHECK(bac_flac_encoder_set_options(enc, &options) == AAC_ERR_INVALID_ARG,
        "padding beyond a metadata block");
  options.padding_bytes = 0;
  options.total_samples_hint = 1ull << 36;
  CHECK(bac_flac_encoder_set_options(enc, &options) == AAC_ERR_INVALID_ARG,
        "a total beyond 36 bits");
  /* A very long stream gets no more points than a decoder keeps. */
  options.total_samples_hint = (1ull << 36) - 1;
  options.seek_table = 1;
  CHECK(bac_flac_encoder_set_options(enc, &options) == AAC_OK &&
            bac_flac_encoder_header_bytes(enc) ==
                42 + 4 + BAC_FLAC_MAX_SEEK_POINTS * FLAC_SEEK_POINT_BYTES,
        "the seek table is capped at %d points", BAC_FLAC_MAX_SEEK_POINTS);
  bac_flac_encoder_destroy(enc);
}

/* ── the ceilings of the levels ────────────────────────────────── */

static void test_levels() {
  /* Music-like material at several rates and depths, every level: the
   * shape stays within the level's row, and above 48 kHz the ceilings
   * are the same. */
  for (int rate : {8000, 48000, 96000, 192000, 655350}) {
    for (int bits : {16, 24}) {
      const int n = 30000;
      std::vector<int32_t> pcm = flac_gen_tones(n, 2, bits, 0.4, 70u + (unsigned)bits, 0.002);
      /* A stretch that is quiet and one that is loud noise, so that the
       * Rice parameters range widely. */
      const std::vector<int32_t> noise = flac_gen_noise(n, 2, bits, 80u);
      for (int i = 9000; i < 12000; i++) {
        pcm[(size_t)i * 2] /= 400;
        pcm[(size_t)i * 2 + 1] /= 300;
      }
      for (int i = 20000; i < 21500; i++) {
        pcm[(size_t)i * 2] = noise[(size_t)i * 2] / 2;
        pcm[(size_t)i * 2 + 1] = noise[(size_t)i * 2 + 1] / 2;
      }
      size_t sizes[9];
      for (int level = 0; level <= 8; level++) {
        char name[96];
        snprintf(name, sizeof(name), "levels: %d Hz, %d bits, level %d", rate, bits, level);
        const FlacEncoded e = roundtrip(name, pcm, rate, 2, bits, level);
        check_shape(name, e, level, rate, 2, bits);
        sizes[level] = frame_bytes(e);
      }
      /* A level that only adds to the search of another cannot be
       * larger: 2 looks at every stereo assignment where 0 has one.
       * (The higher levels differ in their windows as well, so one is
       * not contained in the next.) */
      CHECK(sizes[2] <= sizes[0], "%d Hz, %d bits: level 2 (%zu) is no larger than level 0 (%zu)",
            rate, bits, sizes[2], sizes[0]);
      CHECK(sizes[5] < sizes[2], "%d Hz, %d bits: linear prediction pays (%zu against %zu)", rate,
            bits, sizes[5], sizes[2]);
    }
  }
  /* Levels 0 and 3 store a pair as left and right; with identical
   * channels the other levels store a side channel that is silent. */
  const std::vector<int32_t> mono = flac_gen_tones(9000, 1, 16, 0.5, 90u);
  std::vector<int32_t> dual;
  for (int32_t v : mono) {
    dual.push_back(v);
    dual.push_back(v);
  }
  for (int level = 0; level <= 8; level++) {
    const FlacEncoded e = roundtrip("identical channels", dual, 44100, 2, 16, level);
    bool side = true;
    for (const FlacFrameShape& f : flac_inspect(e.stream)) {
      side = side && f.header.assignment != FLAC_ASSIGN_INDEPENDENT;
    }
    CHECK(side == kCeilings[level].stereo, "level %d: identical channels are %s as left and right",
          level, kCeilings[level].stereo ? "not stored" : "stored");
  }
}

/* What the encoder writes for a given input is the same bytes in every
 * build and on every machine: the analysis is done in a stated order,
 * and the kernels of every level agree bit for bit.  These are the
 * SHA-256 digests of whole streams, the final header included, for
 * inputs made with integers alone; a compiler, a C library or a
 * processor on which one of them comes out differently writes other
 * streams than the rest. */
struct PinnedStream {
  int sample_rate, channels, bits, frames;
  uint32_t seed;
  const char* digest[3]; /* levels 0, 5 and 8 */
};

static const PinnedStream kPinned[] = {
    {44100,
     2,
     16,
     20000,
     1,
     {"62e623de5614b170cd637d31387699ef9d3c3f88c92d60731e6ebf42a028d3f0",
      "f8826ec09138e2545a4cb6759a4d6ccfcd90c5cfa93134a5314e8d00fdf5de02",
      "b61d73372fd7caee7251f476e3cf807cab5df409826df03ba927eddc9609d45d"}},
    {96000,
     2,
     24,
     12000,
     2,
     {"2fa0c50aba068607244450fc721d99070d440469f6a60655ccbed5862f70574f",
      "ac5e520aa11524a7f8f840e217cde775ecf59341db9b8b1acd27645961e33510",
      "66fb30848692b13c9fb05e274b21cab3719165bdc5d6a001be714bdb43f1c48f"}},
    {48000,
     6,
     16,
     6000,
     3,
     {"46bace5228eefde37262de505b3af04bf33ed8471a039b1da6d8647bac1cda7c",
      "df5dfe4db557654a1173b197041a852b8de017eba86a626339d46994c169b7f9",
      "08925268e42deee249d85e2aec6a7e168e08955c0b429d566187c2f3b2896b44"}},
    {48000,
     1,
     8,
     9000,
     4,
     {"39ca8a7868a3dd78502cb8a84b77ebfb88e4b4947008be6ea352dfc2502f85f6",
      "5a8b4d36894a8aa45d515d9d46ec37e1193d4c6017a9e4748e2c6f62d5cc9ee1",
      "44d8b2d546e77839387d1c39a637832970a446e56b8cbfdf4f37986368e830cd"}},
    {192000,
     2,
     32,
     5000,
     5,
     {"3914bea6f67a0dee1e13bd96718c9f283b42caee8c40bdc485c64171a0e5d11d",
      "e53e439b860ea92f9d82194f621ff918884066ad9f4511d106bc80a3856a11f5",
      "9a12c11ab6f64cb597428c190594e5542256d0d508e32fe48cf9d4d6e7f74d95"}},
};

static void test_pinned_digests(bool print) {
  static const int kLevels[3] = {0, 5, 8};
  {
    const uint8_t abc[3] = {'a', 'b', 'c'};
    CHECK(flac_sha256_hex(abc, 3) ==
              "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad",
          "the digest of the suite is SHA-256");
  }
  for (const PinnedStream& p : kPinned) {
    const std::vector<int32_t> pcm = flac_gen_integer(p.frames, p.channels, p.bits, p.seed);
    for (int l = 0; l < 3; l++) {
      const FlacEncoded e = flac_encode(pcm, p.sample_rate, p.channels, p.bits, kLevels[l]);
      const std::string digest = flac_sha256_hex(e.stream.data(), e.stream.size());
      if (print) {
        printf("%d Hz, %d channels, %d bits, level %d: %s\n", p.sample_rate, p.channels, p.bits,
               kLevels[l], digest.c_str());
        continue;
      }
      CHECK(e.error == AAC_OK && digest == p.digest[l],
            "%d Hz, %d channels, %d bits, %d samples at level %d: the stream is the pinned one "
            "(%zu bytes, %s)",
            p.sample_rate, p.channels, p.bits, p.frames, kLevels[l], e.stream.size(),
            digest.c_str());
    }
  }
}

int main(int argc, char** argv) {
  if (argc > 1 && strcmp(argv[1], "--print-digests") == 0) {
    test_pinned_digests(true);
    return 0;
  }
  test_pinned_digests(false);
  test_roundtrip_matrix();
  test_extremes();
  test_signals();
  test_sine();
  test_short_inputs();
  test_refusals();
  test_output_bound();
  test_lifecycle();
  test_header();
  test_levels();
  return test_summary("test_flac_encoder");
}
