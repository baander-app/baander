/* The kernel table of the FLAC codec: every kernel of every level this
 * machine can run (scalar, SSE2, AVX2, NEON) against the scalar kernel
 * on random data, bit for bit, at lengths that leave a vector tail; the
 * residual and the restore kernels against each other where the 32-bit
 * and the 64-bit forms both apply; and whole streams, which have to come
 * out the same byte for byte whatever level the process is capped at. */
#include "aac_cpu.h"
#include "test_flac_util.h"

struct Level {
  const char* name;
  int flags;
};

static uint32_t next(uint32_t* seed) {
  *seed = *seed * 1664525u + 1013904223u;
  uint32_t x = *seed;
  *seed = *seed * 1664525u + 1013904223u;
  return x ^ (*seed >> 16);
}

/* A value of `bits` bits, two's complement; now and then one of the two
 * ends of the range. */
static int32_t sample(uint32_t* seed, int bits) {
  const uint32_t x = next(seed);
  if ((x & 0x3F) == 0) {
    return (x & 0x40) ? (int32_t)flac_sample_max(bits) : (int32_t)flac_sample_min(bits);
  }
  return (int32_t)next(seed) >> (32 - bits);
}

static std::vector<int32_t> samples(uint32_t* seed, int n, int bits) {
  std::vector<int32_t> v((size_t)n);
  for (int32_t& x : v) {
    x = sample(seed, bits);
  }
  return v;
}

/* A signal that moves slowly through the whole 32-bit range, so that a
 * predictor leaves a residual that can be stored. */
static std::vector<int32_t> wandering(uint32_t* seed, int n) {
  std::vector<int32_t> v((size_t)n);
  int64_t x = (int32_t)next(seed);
  int64_t step = 0;
  for (int32_t& out : v) {
    step += (int32_t)next(seed) >> 12;
    x += step;
    if (x > INT32_MAX || x < INT32_MIN) {
      x = x > 0 ? INT32_MAX : INT32_MIN;
      step = -step / 2;
    }
    out = (int32_t)x;
  }
  return v;
}

static const int kLengths[] = {1,  2,  3,  4,  5,  7,   8,   9,    15,   16,  17,
                               31, 32, 33, 63, 64, 100, 257, 1000, 4096, 4099};

template <typename T>
static bool same(const std::vector<T>& a, const std::vector<T>& b) {
  return a.size() == b.size() && memcmp(a.data(), b.data(), a.size() * sizeof(T)) == 0;
}

/* ── analysis ──────────────────────────────────────────────────── */

static void test_analysis(const char* name, const FlacDSP* ref, const FlacDSP* dsp) {
  uint32_t seed = 3;
  for (int n : kLengths) {
    std::vector<double> x((size_t)n), w((size_t)n), a((size_t)n), b((size_t)n);
    for (int i = 0; i < n; i++) {
      x[(size_t)i] = (double)sample(&seed, 24);
      w[(size_t)i] = (double)(next(&seed) >> 8) / 16777216.0;
    }
    ref->window(x.data(), w.data(), n, a.data());
    dsp->window(x.data(), w.data(), n, b.data());
    CHECK(same(a, b), "%s window, %d samples", name, n);

    for (int max_lag = 0; max_lag <= FLAC_MAX_LPC_ORDER; max_lag++) {
      std::vector<double> r1((size_t)FLAC_MAX_LPC_ORDER + 1, -1.0);
      std::vector<double> r2((size_t)FLAC_MAX_LPC_ORDER + 1, -1.0);
      ref->autocorrelation(a.data(), n, max_lag, r1.data());
      dsp->autocorrelation(a.data(), n, max_lag, r2.data());
      CHECK(same(r1, r2), "%s autocorrelation, %d samples, %d lags", name, n, max_lag);
    }
  }
}

/* ── encoder ───────────────────────────────────────────────────── */

static void test_fixed_sums(const char* name, const FlacDSP* ref, const FlacDSP* dsp) {
  uint32_t seed = 5;
  for (int n : kLengths) {
    for (int bits : {8, 16, 24, 27}) {
      const std::vector<int32_t> x = samples(&seed, n, bits);
      for (int max_order = 0; max_order <= std::min(FLAC_MAX_FIXED_ORDER, n - 1); max_order++) {
        std::vector<uint64_t> s1(FLAC_MAX_FIXED_ORDER + 1, 7), s2(FLAC_MAX_FIXED_ORDER + 1, 7);
        ref->fixed_sums(x.data(), n, max_order, s1.data());
        dsp->fixed_sums(x.data(), n, max_order, s2.data());
        bool equal = true;
        for (int k = 0; k <= max_order; k++) {
          equal = equal && s1[(size_t)k] == s2[(size_t)k];
        }
        CHECK(equal, "%s fixed_sums, %d samples of %d bits, order %d", name, n, bits, max_order);
      }
    }
  }
}

/* The residual as the format defines it, in 64-bit arithmetic. */
static std::vector<int64_t> residual_reference(const std::vector<int32_t>& x, int order,
                                               const std::vector<int32_t>& coefs, int shift) {
  std::vector<int64_t> out(x.size(), 0);
  for (size_t i = (size_t)order; i < x.size(); i++) {
    int64_t sum = 0;
    for (int j = 0; j < order; j++) {
      sum += (int64_t)coefs[(size_t)j] * (int64_t)x[i - 1 - (size_t)j];
    }
    out[i] = (int64_t)x[i] - (sum >> shift);
  }
  return out;
}

static bool matches(const std::vector<int32_t>& got, const std::vector<int64_t>& want, int order) {
  for (size_t i = (size_t)order; i < got.size(); i++) {
    if ((int64_t)got[i] != want[i]) {
      return false;
    }
  }
  return true;
}

/* One predictor on one signal through every residual kernel that may
 * take it, and back through the restore kernels. */
static void check_prediction(const char* name, const FlacDSP* ref, const FlacDSP* dsp,
                             const std::vector<int32_t>& x, int bps, int order, int precision,
                             int shift, uint32_t* seed, bool extreme) {
  const int n = (int)x.size();
  std::vector<int32_t> coefs((size_t)order);
  for (int32_t& c : coefs) {
    c = extreme ? (int32_t)flac_sample_min(precision) : sample(seed, precision);
  }
  const std::vector<int64_t> want = residual_reference(x, order, coefs, shift);
  const bool fits_32 = bps <= 30 && bps + precision + flac_ceil_log2(order) <= 32;
  const int kSentinel = 0x5A5A5A5A;
  char what[160];
  snprintf(what, sizeof(what), "%s: %d samples of %d bits, order %d, precision %d, shift %d%s",
           name, n, bps, order, precision, shift, extreme ? ", extremes" : "");

  std::vector<int32_t> wide((size_t)n, kSentinel), wide_ref((size_t)n, kSentinel);
  const bool ok = dsp->lpc_residual_64(x.data(), n, order, coefs.data(), shift, wide.data());
  const bool ok_ref =
      ref->lpc_residual_64(x.data(), n, order, coefs.data(), shift, wide_ref.data());
  bool in_range = true;
  for (size_t i = (size_t)order; i < want.size(); i++) {
    in_range = in_range && want[i] > INT32_MIN && want[i] <= INT32_MAX;
  }
  CHECK(ok == in_range && ok_ref == in_range, "lpc_residual_64 verdict, %s", what);
  if (!in_range) {
    return;
  }
  CHECK(matches(wide, want, order) && same(wide, wide_ref), "lpc_residual_64, %s", what);

  if (fits_32) {
    std::vector<int32_t> narrow((size_t)n, kSentinel);
    dsp->lpc_residual_32(x.data(), n, order, coefs.data(), shift, narrow.data());
    CHECK(same(narrow, wide), "lpc_residual_32 against the 64-bit form, %s", what);
    if (bps <= 16) {
      std::vector<int32_t> narrowest((size_t)n, kSentinel);
      dsp->lpc_residual_16(x.data(), n, order, coefs.data(), shift, narrowest.data());
      CHECK(same(narrowest, wide), "lpc_residual_16 against the 64-bit form, %s", what);
    }
  }

  /* Back: the warm-up samples and the residual give the signal. */
  std::vector<int32_t> s = wide;
  for (int i = 0; i < order; i++) {
    s[(size_t)i] = x[(size_t)i];
  }
  std::vector<int32_t> restored = s;
  dsp->lpc_restore_wide(restored.data(), n, order, coefs.data(), shift);
  CHECK(same(restored, x), "lpc_restore_wide, %s", what);
  if (fits_32) {
    restored = s;
    dsp->lpc_restore_32(restored.data(), n, order, coefs.data(), shift);
    CHECK(same(restored, x), "lpc_restore_32, %s", what);
  }
}

static void test_prediction(const char* name, const FlacDSP* ref, const FlacDSP* dsp) {
  uint32_t seed = 7;
  const int orders[] = {1, 2, 3, 4, 5, 7, 8, 9, 12, 15, 16, 17, 31, 32};
  for (int n : {33, 40, 47, 48, 49, 64, 100, 1031, 1100, 4096}) {
    for (int order : orders) {
      if (order >= n) {
        continue;
      }
      /* 16-bit samples with the widest coefficients the 32-bit sum
       * leaves room for: every form applies. */
      const int room = 32 - 16 - flac_ceil_log2(order);
      for (int precision : {2, 7, std::min(15, room)}) {
        check_prediction(name, ref, dsp, samples(&seed, n, 16), 16, order, precision,
                         (int)(next(&seed) % 15), &seed, false);
      }
      /* At the edge of 32 bits: depth, coefficient width and summation
       * add up to exactly 32, with the signal and the coefficients at
       * the most negative value, where the sum is largest. */
      for (int bps : {8, 12, 17, 20, 24}) {
        const int precision = 32 - bps - flac_ceil_log2(order);
        if (precision < 2 || precision > 15) {
          continue;
        }
        check_prediction(name, ref, dsp, samples(&seed, n, bps), bps, order, precision,
                         precision - 1, &seed, false);
        const std::vector<int32_t> lowest((size_t)n, (int32_t)flac_sample_min(bps));
        check_prediction(name, ref, dsp, lowest, bps, order, precision, precision - 1, &seed, true);
      }
      /* Past 32 bits: 24-bit samples with 15-bit coefficients, and a
       * signal over the whole 32-bit range, whose sums need 64 bits. */
      check_prediction(name, ref, dsp, samples(&seed, n, 24), 24, order, 15, 14, &seed, false);
      check_prediction(name, ref, dsp, wandering(&seed, n), 32, order, 12, 11, &seed, false);
      check_prediction(name, ref, dsp, samples(&seed, n, 32), 32, order, 15, 3, &seed, false);
    }
  }
}

static void test_rice(const char* name, const FlacDSP* ref, const FlacDSP* dsp) {
  uint32_t seed = 9;
  for (int n : kLengths) {
    for (int bits : {3, 12, 24, 32}) {
      std::vector<int32_t> residual = samples(&seed, n, bits);
      for (int32_t& r : residual) {
        if (r == INT32_MIN) {
          r = INT32_MIN + 1; /* the one value a residual never has */
        }
      }
      std::vector<uint32_t> f1((size_t)n), f2((size_t)n);
      uint32_t any1 = 0;
      uint32_t any2 = 0;
      const uint64_t t1 = ref->rice_fold(residual.data(), n, f1.data(), &any1);
      const uint64_t t2 = dsp->rice_fold(residual.data(), n, f2.data(), &any2);
      uint64_t total = 0;
      for (uint32_t u : f1) {
        total += u;
      }
      CHECK(same(f1, f2) && t1 == t2 && t1 == total && any1 == any2,
            "%s rice_fold, %d residuals of %d bits", name, n, bits);
      /* Every run of parameters, the sums a few slots apart; the values
       * of 32 bits are too large to add up in 32. */
      const size_t stride = 3;
      for (int k_low = 0; k_low <= 31; k_low += 5) {
        for (int k_count = 1; k_low + k_count <= 32; k_count += k_count < 6 ? 1 : 7) {
          std::vector<uint64_t> s1((size_t)k_count * stride, 9), s2((size_t)k_count * stride, 9);
          ref->rice_sums(f1.data(), n, any1, k_low, k_count, s1.data(), stride);
          dsp->rice_sums(f1.data(), n, any1, k_low, k_count, s2.data(), stride);
          bool right = true;
          for (int j = 0; j < k_count; j++) {
            uint64_t want = 0;
            for (uint32_t u : f1) {
              want += u >> (k_low + j);
            }
            right = right && s1[(size_t)j * stride] == want && s2[(size_t)j * stride] == want;
          }
          CHECK(right && same(s1, s2),
                "%s rice_sums, %d residuals of %d bits, %d parameters from %d", name, n, bits,
                k_count, k_low);
        }
      }
    }
  }
}

/* ── decoder ───────────────────────────────────────────────────── */

/* Reads `count` Rice code words the way the decoder does: the kernel for
 * as many as it takes, the reader for the one it leaves.  Returns how
 * many were read before the input ended. */
static int rice_decode_all(const FlacDSP* dsp, FlacBitReader* r, int32_t* out, int count, int k,
                           int* left_by_kernel) {
  *left_by_kernel = 0;
  for (int i = 0;;) {
    const int got = dsp->rice_decode(r, out + i, count - i, k);
    if (got < 0 || got > count - i) {
      return -1;
    }
    i += got;
    if (i == count) {
      return count;
    }
    (*left_by_kernel)++;
    const uint32_t quotient = flac_bitreader_read_unary(r);
    const uint32_t rest = flac_bitreader_read(r, k);
    if (r->overread) {
      return i;
    }
    const uint32_t folded = (quotient << k) | rest;
    out[i++] = (int32_t)((folded >> 1) ^ (0u - (folded & 1)));
  }
}

static void test_rice_decode(const char* name, const FlacDSP* ref, const FlacDSP* dsp) {
  uint32_t seed = 11;
  for (int k : {0, 1, 4, 9, 14, 20, 30}) {
    for (int n : {1, 7, 64, 1000}) {
      /* Residuals near the size the parameter suits, and now and then
       * one far larger, whose unary part is longer than the reader's
       * cache. */
      std::vector<int32_t> residual((size_t)n);
      for (int32_t& v : residual) {
        const int bits = next(&seed) % 16 == 0 ? k + 9 : k + 2;
        v = sample(&seed, std::min(bits, 31));
      }
      std::vector<uint8_t> bytes((size_t)n * 48 + 16);
      FlacBitWriter w;
      flac_bitwriter_init(&w, bytes.data(), bytes.size());
      for (int32_t v : residual) {
        const uint32_t folded = ((uint32_t)v << 1) ^ (0u - ((uint32_t)v >> 31));
        flac_bitwriter_write_unary(&w, folded >> k);
        flac_bitwriter_write(&w, folded, k);
      }
      const size_t bits_written = flac_bitwriter_bits(&w);
      const size_t size = flac_bitwriter_flush(&w);
      CHECK(!w.overflow, "%s rice_decode: the test stream fits", name);

      /* The whole stream, then one cut short inside a code word. */
      for (const size_t available : {size, size / 2}) {
        std::vector<int32_t> o1((size_t)n, -1), o2((size_t)n, -1);
        FlacBitReader r1, r2;
        flac_bitreader_init(&r1, bytes.data(), available);
        flac_bitreader_init(&r2, bytes.data(), available);
        int left1 = 0;
        int left2 = 0;
        const int got1 = rice_decode_all(ref, &r1, o1.data(), n, k, &left1);
        const int got2 = rice_decode_all(dsp, &r2, o2.data(), n, k, &left2);
        bool equal = got1 == got2 && got1 >= 0 && left1 == left2;
        for (int i = 0; equal && i < got1; i++) {
          equal = o1[(size_t)i] == residual[(size_t)i] && o2[(size_t)i] == residual[(size_t)i];
        }
        CHECK(equal, "%s rice_decode, parameter %d, %d values, %zu of %zu bytes: %d and %d read",
              name, k, n, available, size, got1, got2);
        if (available == size) {
          CHECK(got2 == n && flac_bitreader_position(&r2) == bits_written &&
                    flac_bitreader_position(&r1) == bits_written,
                "%s rice_decode, parameter %d, %d values: every bit read and none further", name, k,
                n);
        } else {
          CHECK(got2 < n || size < 2, "%s rice_decode, parameter %d: the cut stream ends early",
                name, k);
        }
      }
    }
  }
}

static void test_stereo_out(const char* name, const FlacDSP* ref, const FlacDSP* dsp) {
  uint32_t seed = 13;
  for (int n : kLengths) {
    for (int bits : {8, 16, 24, 30}) {
      /* Left and right of `bits` bits, stored each of the four ways; the
       * side channel has one bit more. */
      const std::vector<int32_t> left = samples(&seed, n, bits);
      const std::vector<int32_t> right = samples(&seed, n, bits);
      std::vector<int32_t> want((size_t)n * 2);
      std::vector<int32_t> side((size_t)n), mid((size_t)n);
      for (int i = 0; i < n; i++) {
        want[(size_t)i * 2] = left[(size_t)i];
        want[(size_t)i * 2 + 1] = right[(size_t)i];
        side[(size_t)i] = left[(size_t)i] - right[(size_t)i];
        mid[(size_t)i] = (left[(size_t)i] + right[(size_t)i]) >> 1;
      }
      const std::vector<int32_t>* rows[4][2] = {
          {&left, &right}, {&left, &side}, {&side, &right}, {&mid, &side}};
      const int assignments[4] = {FLAC_ASSIGN_INDEPENDENT, FLAC_ASSIGN_LEFT_SIDE,
                                  FLAC_ASSIGN_SIDE_RIGHT, FLAC_ASSIGN_MID_SIDE};
      for (int a = 0; a < 4; a++) {
        std::vector<int32_t> p1((size_t)n * 2, -1), p2((size_t)n * 2, -1);
        ref->stereo_out[assignments[a]](rows[a][0]->data(), rows[a][1]->data(), n, p1.data());
        dsp->stereo_out[assignments[a]](rows[a][0]->data(), rows[a][1]->data(), n, p2.data());
        CHECK(same(p1, want) && same(p2, want), "%s stereo_out %d, %d samples of %d bits", name,
              assignments[a], n, bits);
      }
    }
  }
}

/* ── whole streams ─────────────────────────────────────────────── */

struct StreamCase {
  int level;
  int channels;
  int bits;
};

/* Levels without linear prediction, with one analysis window and with
 * the full search; depths on the 16-bit, the 32-bit and the 64-bit
 * paths (at 32 bits the side channel has 33). */
static const StreamCase kStreamCases[] = {
    {0, 2, 16}, {5, 2, 16}, {8, 2, 16}, {0, 2, 24}, {5, 2, 24},
    {8, 2, 24}, {0, 2, 32}, {5, 2, 32}, {8, 2, 32}, {8, 6, 24},
};

/* A line per case: the MD5 of the encoded stream, which is also decoded
 * and compared with the input.  This is what a child process prints. */
static int print_stream_digests() {
  int failures = 0;
  for (const StreamCase& c : kStreamCases) {
    std::vector<int32_t> pcm = flac_gen_tones(3 * 4096 + 777, c.channels, c.bits, 0.6, 31u, 0.05);
    const std::vector<int32_t> noise = flac_gen_noise(2000, c.channels, c.bits, 5u);
    pcm.insert(pcm.end(), noise.begin(), noise.end());
    const FlacEncoded e = flac_encode(pcm, 48000, c.channels, c.bits, c.level, 4000);
    const FlacDecoded d = flac_decode(e.stream, 8192);
    const bool exact = e.error == AAC_OK && d.errors.empty() && d.finish == AAC_OK &&
                       d.verdict == BAAC_FLAC_MD5_MATCH && d.pcm == pcm;
    failures += exact ? 0 : 1;
    FlacMd5 md5;
    uint8_t digest[16];
    flac_md5_init(&md5);
    flac_md5_update(&md5, e.stream.data(), e.stream.size());
    flac_md5_final(&md5, digest);
    printf("level %d, %d channels, %d bits: %zu bytes %s %s\n", c.level, c.channels, c.bits,
           e.stream.size(), hex_string(digest, 16).c_str(), exact ? "exact" : "NOT EXACT");
  }
  return failures;
}

static size_t count_lines(const std::string& text) {
  size_t lines = text.empty() ? 0 : 1;
  for (char c : text) {
    lines += c == '\n' ? 1 : 0;
  }
  return lines;
}

/* The same streams from this program run once per dispatch level: the
 * kernel table of a process is filled once, so each level needs a
 * process of its own. */
static void test_stream_identity(const char* self, const std::vector<const char*>& caps) {
#if defined(_WIN32)
  (void)self;
  (void)caps;
  printf("stream identity across levels: not run on this platform\n");
#else
  const size_t cases = sizeof(kStreamCases) / sizeof(kStreamCases[0]);
  const std::string program = shell_quote(self) + " --stream-digests";
  int status = -1;
  const std::string uncapped = run_capture("env -u BAAC_AAC_CPU " + program, &status);
  CHECK(status == 0 && count_lines(uncapped) == cases,
        "the streams of the uncapped process are exact (status %d):\n%s", status, uncapped.c_str());
  for (const char* cap : caps) {
    const std::string capped =
        run_capture(std::string("BAAC_AAC_CPU=") + cap + " " + program, &status);
    CHECK(status == 0 && capped == uncapped,
          "capped at %s the streams are the uncapped ones (status %d):\n%s\nagainst\n%s", cap,
          status, capped.c_str(), uncapped.c_str());
  }
  printf("stream identity: %zu streams, uncapped and capped at", cases);
  for (const char* cap : caps) {
    printf(" %s", cap);
  }
  printf("\n");
#endif
}

int main(int argc, char** argv) {
  if (argc > 1 && strcmp(argv[1], "--stream-digests") == 0) {
    return print_stream_digests() ? 1 : 0;
  }
  const int detected = aac_get_cpu_flags();
  printf("CPU: %s\n", aac_cpu_flags_string(detected));
  aac_set_cpu_flags_override(AAC_CPU_FLAG_NONE);
  FlacDSP scalar;
  flac_dsp_init(&scalar);

  const Level levels[] = {
      {"scalar", AAC_CPU_FLAG_NONE},
      {"sse2", AAC_CPU_FLAG_SSE2},
      {"avx2", AAC_CPU_FLAG_SSE2 | AAC_CPU_FLAG_AVX | AAC_CPU_FLAG_AVX2},
      {"neon", AAC_CPU_FLAG_NEON},
  };
  std::vector<const char*> caps;
  for (const Level& level : levels) {
    if ((level.flags & detected) != level.flags) {
      continue;
    }
    /* The AVX2 level takes a kernel of its own when the processor has
     * BMI2 and LZCNT as well. */
    const int extra = (level.flags & AAC_CPU_FLAG_AVX2)
                          ? (detected & (AAC_CPU_FLAG_BMI2 | AAC_CPU_FLAG_LZCNT))
                          : 0;
    aac_set_cpu_flags_override(level.flags | extra);
    FlacDSP dsp;
    flac_dsp_init(&dsp);
    printf("testing %s kernels%s\n", level.name,
           dsp.rice_decode != scalar.rice_decode ? ", with a Rice reader of the level's own" : "");
    test_analysis(level.name, &scalar, &dsp);
    test_fixed_sums(level.name, &scalar, &dsp);
    test_prediction(level.name, &scalar, &dsp);
    test_rice(level.name, &scalar, &dsp);
    test_rice_decode(level.name, &scalar, &dsp);
    test_stereo_out(level.name, &scalar, &dsp);
    caps.push_back(level.name);
  }
  CHECK(!caps.empty(), "at least the scalar level runs");
  test_stream_identity(argv[0], caps);
  return test_summary("test_flac_dsp");
}
