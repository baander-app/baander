/* The DSP dispatch table: every kernel of every level this machine can
 * run (scalar, SSE2, AVX2, NEON) against the scalar kernel on random
 * data.  The transform kernels (FFT stage, MDCT twiddles) and the plain
 * products must agree bit for bit, since the encoder's output goes
 * through them; the kernels that may use a fused multiply-add agree to
 * 1e-6 relative. */
#include "aac_cpu.h"
#include "aac_dsp.h"
#include "fft.h"
#include "test_util.h"

static float rnd(unsigned* seed) {
  *seed = *seed * 1664525u + 1013904223u;
  return (float)(*seed >> 8) / 8388608.0f - 1.0f;
}

static void fill(std::vector<float>& v, unsigned* seed, float scale) {
  for (float& x : v) {
    x = scale * rnd(seed);
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
    tested++;
  }
  CHECK(tested >= 1, "at least the scalar level runs");
  return test_summary("test_dsp");
}
