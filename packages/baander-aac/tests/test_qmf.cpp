/* The SBR QMF banks: each analysis and synthesis pairing reconstructs its
 * input to the accuracy of the prototype (the banks are near-perfect
 * reconstruction, about 55 dB), and the two analysis banks agree on the
 * low band so that an encoder working at the full rate sees the energies
 * the decoder will reproduce from the half-rate core. */
#include "qmf.h"
#include "test_util.h"

static std::vector<float> test_signal(int n, int sr) {
  std::vector<float> x(n);
  for (int i = 0; i < n; i++) {
    const float t = (float)i / (float)sr;
    x[i] = 10000.0f * sinf(2.0f * (float)M_PI * 1000.0f * t) +
           5000.0f * sinf(2.0f * (float)M_PI * 5000.0f * t + 1.0f) +
           3000.0f * sinf(2.0f * (float)M_PI * 300.0f * t + 2.0f);
  }
  return x;
}

/* Runs `in` through an analysis bank of `ana` bands and a synthesis bank
 * of `syn` bands and returns the output. */
static std::vector<float> chain(const std::vector<float>& in, int ana, int syn) {
  AacQmfAnalysis a;
  AacQmfSynthesis s;
  aac_qmf_analysis_init(&a, ana);
  aac_qmf_synthesis_init(&s, syn);
  std::vector<float> out(in.size() * syn / ana, 0.0f);
  for (size_t slot = 0; slot + ana <= in.size(); slot += ana) {
    float X[64][2] = {};
    aac_qmf_analyze(&a, in.data() + slot, X);
    aac_qmf_synthesize(&s, X, out.data() + slot * syn / ana);
  }
  return out;
}

/* SNR of out[i * step + d] against ref[i * ref_step + phase] at the best
 * delay d. */
static double best_snr(const std::vector<float>& ref, int ref_step, int phase,
                       const std::vector<float>& out, int step, int* delay) {
  double best = -100.0;
  const size_t n = ref.size() / ref_step - 1200;
  for (int d = 0; d < 1200; d++) {
    double sig = 0.0;
    double err = 0.0;
    for (size_t i = 100; i < n; i++) {
      const double r = ref[i * ref_step + phase];
      const double o = out[i * step + d];
      sig += r * r;
      err += (o - r) * (o - r);
    }
    const double v = 10.0 * log10(sig / std::max(err, 1e-9));
    if (v > best) {
      best = v;
      *delay = d;
    }
  }
  return best;
}

static void test_reconstruction() {
  const std::vector<float> core = test_signal(32 * 400, 24000);
  const std::vector<float> full = test_signal(64 * 400, 48000);
  int d = 0;
  /* Decoder: half-rate core in, full-rate output whose even samples are
   * the core samples. */
  double snr = best_snr(core, 1, 0, chain(core, 32, 64), 2, &d);
  CHECK(snr > 50.0, "32-band analysis to 64-band synthesis: %.1f dB", snr);
  CHECK(d == 578, "decoder bank delay %d samples", d);
  snr = best_snr(core, 1, 0, chain(core, 32, 32), 1, &d);
  CHECK(snr > 50.0, "32-band analysis to 32-band synthesis: %.1f dB", snr);
  snr = best_snr(full, 1, 0, chain(full, 64, 64), 1, &d);
  CHECK(snr > 50.0, "64-band analysis to 64-band synthesis: %.1f dB", snr);
  /* Encoder downsampling: the half-rate output sits on the odd input
   * samples. */
  snr = best_snr(full, 2, 1, chain(full, 64, 32), 1, &d);
  CHECK(snr > 50.0, "64-band analysis to 32-band synthesis: %.1f dB", snr);
}

static void test_analysis_scaling() {
  /* A full-rate signal below 10 kHz: the lower 32 bands of the 64-band
   * analysis carry the same magnitudes as the 32-band analysis of its
   * half-rate version. */
  const int n = 64 * 300;
  std::vector<float> full(n);
  std::vector<float> half(n / 2);
  for (int i = 0; i < n; i++) {
    const float t = (float)i / 48000.0f;
    full[i] = 8000.0f * sinf(2.0f * (float)M_PI * 1500.0f * t) +
              6000.0f * sinf(2.0f * (float)M_PI * 4200.0f * t + 0.5f) +
              4000.0f * sinf(2.0f * (float)M_PI * 8800.0f * t + 1.5f);
  }
  for (int i = 0; i < n / 2; i++) {
    half[i] = full[2 * i];
  }
  AacQmfAnalysis a64, a32;
  aac_qmf_analysis_init(&a64, 64);
  aac_qmf_analysis_init(&a32, 32);
  double e64 = 0.0;
  double e32 = 0.0;
  for (int slot = 0; slot < n / 64; slot++) {
    float X[64][2], W[32][2];
    aac_qmf_analyze(&a64, full.data() + 64 * slot, X);
    aac_qmf_analyze(&a32, half.data() + 32 * slot, W);
    if (slot < 20) {
      continue; /* the banks settle */
    }
    for (int k = 0; k < 32; k++) {
      e64 += (double)X[k][0] * X[k][0] + (double)X[k][1] * X[k][1];
      e32 += (double)W[k][0] * W[k][0] + (double)W[k][1] * W[k][1];
    }
  }
  const double ratio = 10.0 * log10(e64 / e32);
  CHECK(fabs(ratio) < 0.5, "64-band and 32-band low-band energies differ by %.2f dB", ratio);
}

/* The decoder's banks run their modulation through an FFT.  Against the
 * direct sums of the standard's formulas, computed here in double
 * precision with the same windowing, they agree to float precision. */
static void test_fft_form_matches_direct_sums() {
  aac_qmf_tables_init();
  unsigned seed = 99u;
  auto rnd = [&seed]() {
    seed = seed * 1664525u + 1013904223u;
    return (float)(seed >> 8) / 8388608.0f - 1.0f;
  };
  /* 32-band analysis: history newest first, u(n) folds the ten windowed
   * taps, W(k) = 2 sum_n u(n) exp(i pi / 64 (k + 1/2)(2 n - 1/2)). */
  {
    AacQmfAnalysis a;
    aac_qmf_analysis_init(&a, 32);
    std::vector<double> hist(320, 0.0);
    double err = 0.0, peak = 1e-30;
    for (int slot = 0; slot < 40; slot++) {
      float in[32];
      for (float& v : in) {
        v = 20000.0f * rnd();
      }
      for (int i = 319; i >= 32; i--) {
        hist[i] = hist[i - 32];
      }
      for (int n = 0; n < 32; n++) {
        hist[n] = in[31 - n];
      }
      float W[64][2];
      aac_qmf_analyze(&a, in, W);
      double u[64];
      for (int n = 0; n < 64; n++) {
        u[n] = 0.0;
        for (int j = 0; j < 5; j++) {
          u[n] += hist[n + 64 * j] * aac_sbr_qmf_window[2 * (n + 64 * j)];
        }
      }
      for (int k = 0; k < 32; k++) {
        double re = 0.0, im = 0.0;
        for (int n = 0; n < 64; n++) {
          const double ang = M_PI / 64.0 * (k + 0.5) * (2.0 * n - 0.5);
          re += u[n] * cos(ang);
          im += u[n] * sin(ang);
        }
        re *= 2.0;
        im *= 2.0;
        peak = std::max(peak, std::max(fabs(re), fabs(im)));
        err = std::max(err, std::max(fabs(re - W[k][0]), fabs(im - W[k][1])));
      }
    }
    printf("QMF 32-band analysis, FFT form against direct sums: %.3g relative\n", err / peak);
    CHECK(err / peak < 1e-5, "32-band analysis FFT form: %.3g relative", err / peak);
  }
  /* 64-band synthesis: v(n) = 1/64 Re sum_k X(k) exp(i pi / 128 (k + 1/2)
   * (2 n - 255)) for n < 128, the newest block in front of nine older
   * ones, and out(k) sums the ten windowed segments. */
  {
    AacQmfSynthesis s;
    aac_qmf_synthesis_init(&s, 64);
    std::vector<double> v(1280, 0.0);
    double err = 0.0, peak = 1e-30;
    for (int slot = 0; slot < 40; slot++) {
      float X[64][2];
      for (auto& x : X) {
        x[0] = 20000.0f * rnd();
        x[1] = 20000.0f * rnd();
      }
      float out[64];
      aac_qmf_synthesize(&s, X, out);
      for (int i = 1279; i >= 128; i--) {
        v[i] = v[i - 128];
      }
      for (int n = 0; n < 128; n++) {
        double acc = 0.0;
        for (int k = 0; k < 64; k++) {
          const double ang = M_PI / 128.0 * (k + 0.5) * (2.0 * n - 255.0);
          acc += X[k][0] * cos(ang) - X[k][1] * sin(ang);
        }
        v[n] = acc / 64.0;
      }
      for (int k = 0; k < 64; k++) {
        double acc = 0.0;
        for (int j = 0; j < 5; j++) {
          acc += v[256 * j + k] * aac_sbr_qmf_window[128 * j + k] +
                 v[256 * j + 192 + k] * aac_sbr_qmf_window[128 * j + 64 + k];
        }
        peak = std::max(peak, fabs(acc));
        err = std::max(err, fabs(acc - out[k]));
      }
    }
    printf("QMF 64-band synthesis, FFT form against direct sums: %.3g relative\n", err / peak);
    CHECK(err / peak < 1e-5, "64-band synthesis FFT form: %.3g relative", err / peak);
  }
}

int main() {
  test_reconstruction();
  test_analysis_scaling();
  test_fft_form_matches_direct_sums();
  return test_summary("test_qmf");
}
