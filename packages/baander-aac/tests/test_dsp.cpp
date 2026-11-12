/* The DSP dispatch table: every kernel of every level this machine can
 * run (scalar, SSE2, AVX2, NEON) against the scalar kernel on random
 * data.  The transform kernels (FFT stage, MDCT twiddles), the plain
 * products and the encoder's quantization noise must agree bit for bit,
 * since the encoder's output goes through them; the kernels that may use
 * a fused multiply-add agree to 1e-6 relative. */
#include "aac_cpu.h"
#include "aac_dsp.h"
#include "fft.h"
#include "test_util.h"

static void fill(std::vector<float>& v, unsigned* seed, float scale) {
  for (float& x : v) {
    x = scale * lcg_float(seed);
  }
}

/* Largest difference relative to the largest reference magnitude. */
static double relative_error(const std::vector<float>& ref, const std::vector<float>& got) {
  double peak = 1e-30;
  double err = 0.0;
  for (size_t i = 0; i < ref.size(); i++) {
    peak = std::max(peak, (double)fabs(ref[i]));
    err = std::max(err, (double)fabs(ref[i] - got[i]));
  }
  return err / peak;
}

static bool bit_exact(const std::vector<float>& a, const std::vector<float>& b) {
  return memcmp(a.data(), b.data(), a.size() * sizeof(float)) == 0;
}

struct Level {
  const char* name;
  int flags;
};

static void test_vector_ops(const char* name, const AacDSP* ref, const AacDSP* dsp) {
  unsigned seed = 11;
  const int lens[] = {1, 3, 7, 8, 15, 16, 31, 64, 120, 128, 480, 1024};
  for (int len : lens) {
    std::vector<float> a(len), b(len), c(len), win(len), r1(len), r2(len);
    fill(a, &seed, 2.0f);
    fill(b, &seed, 2.0f);
    fill(c, &seed, 2.0f);
    fill(win, &seed, 1.0f);

    ref->vector_fmul(r1.data(), a.data(), b.data(), len);
    dsp->vector_fmul(r2.data(), a.data(), b.data(), len);
    CHECK(bit_exact(r1, r2), "%s vector_fmul len %d", name, len);

    ref->vector_fmul_scalar(r1.data(), a.data(), 0.37f, len);
    dsp->vector_fmul_scalar(r2.data(), a.data(), 0.37f, len);
    CHECK(bit_exact(r1, r2), "%s vector_fmul_scalar len %d", name, len);

    ref->vector_fmul_reverse(r1.data(), a.data(), b.data(), len);
    dsp->vector_fmul_reverse(r2.data(), a.data(), b.data(), len);
    CHECK(bit_exact(r1, r2), "%s vector_fmul_reverse len %d", name, len);

    ref->vector_fmul_add(r1.data(), a.data(), b.data(), c.data(), len);
    dsp->vector_fmul_add(r2.data(), a.data(), b.data(), c.data(), len);
    CHECK(relative_error(r1, r2) <= 1e-6, "%s vector_fmul_add len %d: %.3g", name, len,
          relative_error(r1, r2));

    ref->vector_fmul_window(r1.data(), a.data(), b.data(), win.data(), len);
    dsp->vector_fmul_window(r2.data(), a.data(), b.data(), win.data(), len);
    CHECK(relative_error(r1, r2) <= 1e-6, "%s vector_fmul_window len %d: %.3g", name, len,
          relative_error(r1, r2));

    r1 = c;
    r2 = c;
    ref->vector_fmul_accumulate(r1.data(), a.data(), b.data(), len);
    dsp->vector_fmul_accumulate(r2.data(), a.data(), b.data(), len);
    CHECK(relative_error(r1, r2) <= 1e-6, "%s vector_fmul_accumulate len %d: %.3g", name, len,
          relative_error(r1, r2));
  }
}

static void test_fft_stage(const char* name, const AacDSP* ref, const AacDSP* dsp) {
  unsigned seed = 23;
  const int sizes[] = {2, 4, 8, 16, 32, 64, 128, 256, 512};
  for (int n : sizes) {
    AacFft f;
    aac_fft_init(&f, n);
    for (int half = 1; half < n; half <<= 1) {
      std::vector<float> re(n), im(n);
      fill(re, &seed, 1000.0f);
      fill(im, &seed, 1000.0f);
      std::vector<float> re1 = re, im1 = im, re2 = re, im2 = im;
      ref->fft_radix2_stage(re1.data(), im1.data(), n, half, f.stage_re + half - 1,
                            f.stage_im + half - 1);
      dsp->fft_radix2_stage(re2.data(), im2.data(), n, half, f.stage_re + half - 1,
                            f.stage_im + half - 1);
      CHECK(bit_exact(re1, re2) && bit_exact(im1, im2), "%s fft stage n %d half %d", name, n,
            half);
    }
  }
}

static void test_mdct_twiddles(const char* name, const AacDSP* ref, const AacDSP* dsp) {
  unsigned seed = 37;
  /* Every DCT-IV length of the codec: long and short windows of the 1024,
   * 960, 512 and 480 frames. */
  const int halves[] = {1024, 128, 960, 120, 512, 480, 256, 240};
  for (int half : halves) {
    const int quarter = half / 2;
    std::vector<float> c(quarter), s(quarter), in(half);
    for (int j = 0; j < quarter; j++) {
      c[j] = (float)cos(-M_PI * (j + 0.25) / half);
      s[j] = (float)sin(-M_PI * (j + 0.25) / half);
    }
    fill(in, &seed, 30000.0f);
    std::vector<float> re1(quarter), im1(quarter), re2(quarter), im2(quarter);
    ref->mdct_pre_twiddle(re1.data(), im1.data(), in.data(), c.data(), s.data(), half);
    dsp->mdct_pre_twiddle(re2.data(), im2.data(), in.data(), c.data(), s.data(), half);
    CHECK(bit_exact(re1, re2) && bit_exact(im1, im2), "%s mdct pre-twiddle half %d", name, half);
    std::vector<float> out1(half, 12345.0f), out2(half, 12345.0f);
    ref->mdct_post_twiddle(out1.data(), re1.data(), im1.data(), c.data(), s.data(), half);
    dsp->mdct_post_twiddle(out2.data(), re1.data(), im1.data(), c.data(), s.data(), half);
    CHECK(bit_exact(out1, out2), "%s mdct post-twiddle half %d", name, half);
    bool all_written = true;
    for (float v : out2) {
      all_written = all_written && v != 12345.0f;
    }
    CHECK(all_written, "%s mdct post-twiddle half %d writes every output", name, half);
  }
}

/* ── quantization noise ──────────────────────────────────────────── */

/* A random float with a random exponent in [lo, hi], either sign. */
static float rnd_exp(unsigned* seed, int lo, int hi) {
  const float m = 1.0f + 0.5f * (lcg_float(seed) + 1.0f); /* [1, 2) */
  const unsigned r = lcg_next(seed);
  const int e = lo + (int)((r >> 8) % (unsigned)(hi - lo + 1));
  const float v = ldexpf(m, e);
  return (r & 0x80u) ? -v : v;
}

static float x34_of(float coef) { return (float)pow(fabs((double)coef), 0.75); }

/* The inputs of one case: lines of every magnitude class the kernel
 * must agree on. */
enum NoiseInput {
  kTypical, /* encoder spectra: |coef| up to 30000, x34 = |coef|^(3/4) */
  kNearMax, /* d^2 close to FLT_MAX, so that the order decides overflow */
  kTiny,    /* denormal and near-FLT_MIN lines */
  kAnyExp,  /* every exponent from denormal to FLT_MAX */
  kWildX34, /* x34 unrelated to coef: negative, NaN, infinite, huge */
  kZeros,   /* +0 and -0 lines among others */
  kNoiseInputs
};

static void fill_noise_input(NoiseInput kind, std::vector<float>& coef, std::vector<float>& x34,
                             unsigned* seed) {
  for (size_t i = 0; i < coef.size(); i++) {
    float c = 0.0f;
    switch (kind) {
      case kTypical:
        c = 30000.0f * lcg_float(seed) * fabsf(lcg_float(seed));
        break;
      case kNearMax:
        c = rnd_exp(seed, 60, 63);
        break;
      case kTiny:
        c = rnd_exp(seed, -149, -60);
        break;
      case kAnyExp:
        c = rnd_exp(seed, -149, 127);
        break;
      case kWildX34:
        c = 1000.0f * lcg_float(seed);
        break;
      case kZeros:
        c = (i % 3 == 0) ? 0.0f : (i % 3 == 1) ? -0.0f : 100.0f * lcg_float(seed);
        break;
      case kNoiseInputs:
        break;
    }
    coef[i] = c;
    x34[i] = x34_of(c);
    if (kind == kWildX34) {
      const float wild[] = {-1.0f, -1e30f, NAN, INFINITY, -INFINITY, 1e30f, 8191.5f, 2.5e9f};
      const unsigned pick = (*seed >> 12) % 16u;
      if (pick < 8) {
        x34[i] = wild[pick];
      }
    }
  }
}

/* The documented sum order, written out on its own: line i of every span
 * into accumulator i % 8, then ((a0 + a4) + (a2 + a6)) + ((a1 + a5) +
 * (a3 + a7)). */
static float documented_noise(const std::vector<float>& coef, const std::vector<float>& x34,
                              int len, int windows, int stride, float scale, float round,
                              float gain, const std::vector<float>& pow43) {
  float a[8] = {0.0f, 0.0f, 0.0f, 0.0f, 0.0f, 0.0f, 0.0f, 0.0f};
  for (int w = 0; w < windows; w++) {
    for (int i = 0; i < len; i++) {
      float v = x34[(size_t)w * stride + i] * scale + round;
      v = std::isnan(v) ? 8191.0f : std::min(std::max(v, 0.0f), 8191.0f);
      const float d = fabsf(coef[(size_t)w * stride + i]) - pow43[(int)v] * gain;
      a[i % 8] += d * d;
    }
  }
  return ((a[0] + a[4]) + (a[2] + a[6])) + ((a[1] + a[5]) + (a[3] + a[7]));
}

static bool same_bits(float a, float b) { return memcmp(&a, &b, sizeof a) == 0; }

/* q^(4/3) for the 8192 quantized magnitudes, as the codec's table. */
static std::vector<float> make_pow43() {
  std::vector<float> pow43(8192);
  for (int q = 0; q < 8192; q++) {
    pow43[q] = (float)pow((double)q, 4.0 / 3.0);
  }
  return pow43;
}

/* Every span length from 0 to 128 (every tail of one to seven lines, and
 * every band width of the tables, the widest 96 lines), one to eight spans
 * that touch or lie 128 apart, the codec's scalefactor range and a few
 * beyond it.  The buffers end at the last line, so that a kernel reading
 * past a span shows under the sanitizers. */
static void test_quant_noise(const char* name, const AacDSP* ref, const AacDSP* dsp,
                             bool check_order) {
  const std::vector<float> pow43 = make_pow43();
  unsigned seed = 53;
  int cases = 0, finite = 0, mismatches = 0;
  for (int kind = 0; kind < kNoiseInputs; kind++) {
    for (int len = 0; len <= 128; len++) {
      for (int windows = 1; windows <= 8; windows++) {
        for (int stride : {len, 128}) {
          const size_t size = (size_t)stride * (windows - 1) + len;
          std::vector<float> coef(size), x34(size);
          fill_noise_input((NoiseInput)kind, coef, x34, &seed);
          const int sf = (int)((lcg_next(&seed) >> 8) % 300u) - 20; /* 0..255 in the codec */
          const float scale = exp2f(-0.1875f * (float)(sf - 100));
          const float gain = exp2f(0.25f * (float)(sf - 100));
          const float round = kind == kWildX34 ? 0.5f * (lcg_float(&seed) + 1.0f) : 0.4054f;
          const float r1 = ref->quant_noise(coef.data(), x34.data(), len, windows, stride, scale,
                                            round, gain, pow43.data());
          const float r2 = dsp->quant_noise(coef.data(), x34.data(), len, windows, stride, scale,
                                            round, gain, pow43.data());
          cases++;
          finite += std::isfinite(r1) && r1 > 0.0f;
          if (!same_bits(r1, r2) && mismatches++ < 10) {
            CHECK(false, "%s quant_noise input %d len %d windows %d stride %d sf %d: %.9g vs %.9g",
                  name, kind, len, windows, stride, sf, r1, r2);
          }
          if (check_order) {
            const float r0 =
                documented_noise(coef, x34, len, windows, stride, scale, round, gain, pow43);
            CHECK(same_bits(r0, r1),
                  "%s quant_noise order, input %d len %d windows %d: %.9g vs %.9g", name, kind, len,
                  windows, r0, r1);
          }
        }
      }
    }
  }
  CHECK(mismatches == 0, "%s quant_noise: %d of %d cases differ", name, mismatches, cases);
  /* Most cases must give a finite positive sum, or the comparison above
   * would only compare infinities and zeros. */
  CHECK(finite * 2 > cases, "%s quant_noise: %d of %d sums finite and positive", name, finite,
        cases);
  printf("  %s quant_noise: %d cases, %d finite and positive\n", name, cases, finite);
}

/* The sum the encoder means, accumulated in double from the same float
 * errors: the scalar kernel stays within float rounding of it on encoder
 * spectra. */
static void test_quant_noise_value(const AacDSP* ref) {
  const std::vector<float> pow43 = make_pow43();
  unsigned seed = 71;
  for (int len : {4, 12, 32, 96}) {
    for (int sf : {60, 100, 140}) {
      std::vector<float> coef(len), x34(len);
      fill_noise_input(kTypical, coef, x34, &seed);
      const float scale = exp2f(-0.1875f * (float)(sf - 100));
      const float gain = exp2f(0.25f * (float)(sf - 100));
      double want = 0.0;
      for (int i = 0; i < len; i++) {
        const int q = std::min((int)(x34[i] * scale + 0.4054f), 8191);
        const float d = fabsf(coef[i]) - pow43[q] * gain;
        want += (double)d * d;
      }
      const float got = ref->quant_noise(coef.data(), x34.data(), len, 1, len, scale, 0.4054f, gain,
                                         pow43.data());
      CHECK(fabs(got - want) <= 1e-5 * want + 1e-30, "quant_noise len %d sf %d: %.9g, want %.9g",
            len, sf, got, want);
    }
  }
}

int main() {
  const int detected = aac_get_cpu_flags();
  printf("CPU: %s\n", aac_cpu_flags_string(detected));
  aac_set_cpu_flags_override(AAC_CPU_FLAG_NONE);
  AacDSP scalar;
  aac_dsp_init(&scalar);

  const Level levels[] = {
      {"scalar", AAC_CPU_FLAG_NONE},
      {"sse2", AAC_CPU_FLAG_SSE2},
      {"avx2", AAC_CPU_FLAG_SSE2 | AAC_CPU_FLAG_AVX | AAC_CPU_FLAG_AVX2 | AAC_CPU_FLAG_FMA3},
      {"neon", AAC_CPU_FLAG_NEON},
  };
  int tested = 0;
  for (const Level& level : levels) {
    if ((level.flags & detected) != level.flags) {
      continue;
    }
    aac_set_cpu_flags_override(level.flags);
    AacDSP dsp;
    aac_dsp_init(&dsp);
    printf("testing %s kernels\n", level.name);
    test_vector_ops(level.name, &scalar, &dsp);
    test_fft_stage(level.name, &scalar, &dsp);
    test_mdct_twiddles(level.name, &scalar, &dsp);
    test_quant_noise(level.name, &scalar, &dsp, level.flags == AAC_CPU_FLAG_NONE);
    tested++;
  }
  test_quant_noise_value(&scalar);
  CHECK(tested >= 1, "at least the scalar level runs");
  return test_summary("test_dsp");
}
