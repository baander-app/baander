#include <cmath>
#include <cstdint>
#include <cstdlib>
#include <algorithm>
#include <vector>

extern "C" {

static int g_sr = 48000;
static int g_tp_os = 1; // true-peak oversample factor (1/2/4)

struct Biquad {
  double b0=1, b1=0, b2=0, a1=0, a2=0;
  double z1L=0, z2L=0, z1R=0, z2R=0;

  inline void set(double B0,double B1,double B2,double A1,double A2){
    b0=B0; b1=B1; b2=B2; a1=A1; a2=A2;
    z1L=z2L=z1R=z2R=0.0f;
  }

  inline void reset(){ z1L=z2L=z1R=z2R=0.0f; }

  inline double processL(double x){
    double y = b0*x + z1L;
    z1L = b1*x + z2L - a1*y;
    z2L = b2*x - a2*y;
    return y;
  }
  inline double processR(double x){
    double y = b0*x + z1R;
    z1R = b1*x + z2R - a1*y;
    z2R = b2*x - a2*y;
    return y;
  }
};

// ITU-R BS.1770-5 Annex 1: head-model shelf followed by RLB highpass.
static Biquad g_pre;
static Biquad g_rlb;

// Energy ring buffers
struct Ring {
  std::vector<float> buf;
  size_t idx = 0;
  size_t filled = 0;
  double total = 0.0;

  void init(size_t n) { buf.assign(n, 0.0f); idx=0; filled=0; total=0.0; }
  inline void push(float v){
    if (buf.empty()) return;
    total += (double)v - (double)buf[idx];
    buf[idx] = v;
    if (++idx == buf.size()) idx = 0;
    if (filled < buf.size()) filled++;
  }
  inline double sum() const { return total; }
  inline size_t size() const { return buf.size(); }
};

// Windows
static Ring g_m_win; // 400 ms momentary
static Ring g_s_win; // 3 s short-term

// Integrated storage for gating (coarse)
static std::vector<float> g_hist; // store block energies (e.g., 100 ms blocks)
static std::vector<float> g_lufs_scratch;
static std::vector<float> g_percentile_scratch;
static size_t g_hist_max = 3000;  // ~5 minutes at 100 ms
static int g_block_samples = 0;
static int g_block_target = 0;

// Outputs
static float g_lufs_m = -70.0f;
static float g_lufs_s = -70.0f;
static float g_lufs_i = -70.0f;
static float g_lra    = 0.0f;
static float g_truepk = -90.0f;

// Helpers
static inline float db2(float db){ return std::pow(10.0f, db/10.0f); }
static inline float lin2db(float v){ return (v > 1e-20f) ? 10.0f*std::log10(v) : -200.0f; }

// Reconstruct the bilinear design from the normative 48 kHz coefficients.
// The cutoff is prewarped at each rate; the reference gains are preserved.
// Double precision avoids cancellation around the RLB's low-frequency poles.
static void design_pre(int sr) {
  // Table 1: stage 1 shelving filter.
  constexpr double b0 = 1.53512485958697;
  constexpr double b1 = -2.69169618940638;
  constexpr double b2 = 1.19839281085285;
  constexpr double a1 = -1.69065929318241;
  constexpr double a2 = 0.73248077421585;
  if (sr == 48000) {
    g_pre.set(b0, b1, b2, a1, a2);
    return;
  }
  const double k48 = std::sqrt((1 + a1 + a2) / (1 - a1 + a2));
  const double q = std::sqrt((1 + a1 + a2) * (1 - a1 + a2)) / (2 * (1 - a2));
  const double k = std::tan(std::atan(k48) * 48000.0 / sr);
  const double vh = (b0 - b1 + b2) / (1 - a1 + a2);
  const double vb = (b0 - b2) / (1 - a2);
  const double vl = (b0 + b1 + b2) / (1 + a1 + a2);
  const double norm = 1 + k / q + k * k;
  g_pre.set((vh + vb * k / q + vl * k * k) / norm,
            2 * (vl * k * k - vh) / norm,
            (vh - vb * k / q + vl * k * k) / norm,
            2 * (k * k - 1) / norm,
            (1 - k / q + k * k) / norm);
}

static void design_rlb(int sr) {
  // Table 2: stage 2 RLB highpass, including its unnormalized numerator.
  constexpr double a1 = -1.99004745483398;
  constexpr double a2 = 0.99007225036621;
  if (sr == 48000) {
    g_rlb.set(1, -2, 1, a1, a2);
    return;
  }
  const double k48 = std::sqrt((1 + a1 + a2) / (1 - a1 + a2));
  const double q = std::sqrt((1 + a1 + a2) * (1 - a1 + a2)) / (2 * (1 - a2));
  const double k = std::tan(std::atan(k48) * 48000.0 / sr);
  const double norm = 1 + k / q + k * k;
  const double gain = (1 + k48 / q + k48 * k48) / norm;
  g_rlb.set(gain, -2 * gain, gain,
            2 * (k * k - 1) / norm,
            (1 - k / q + k * k) / norm);
}

void init_loudness(int sample_rate, int truepeak_oversample) {
  g_sr = (sample_rate > 0) ? sample_rate : 48000;
  g_tp_os = (truepeak_oversample==4) ? 4 : (truepeak_oversample==2 ? 2 : 1);

  design_pre(g_sr);
  design_rlb(g_sr);
  g_pre.reset(); g_rlb.reset();

  // Windows: use energy per sample; we’ll maintain running sums by pushing per-sample energies
  g_m_win.init((size_t)std::max(1, (int)std::round(g_sr * 0.400f))); // 400 ms
  g_s_win.init((size_t)std::max(1, (int)std::round(g_sr * 3.000f))); // 3 s

  g_hist.clear();
  g_hist.reserve(g_hist_max);
  g_lufs_scratch.clear();
  g_lufs_scratch.reserve(g_hist_max);
  g_percentile_scratch.clear();
  g_percentile_scratch.reserve(g_hist_max);
  g_block_samples = 0;
  g_block_target = std::max(1, g_sr / 10); // 100 ms blocks

  g_lufs_m = g_lufs_s = g_lufs_i = -70.0f;
  g_lra = 0.0f;
  g_truepk = -90.0f;
}

void reset_loudness() {
  init_loudness(g_sr, g_tp_os);
}

// Very simple 4x oversample true-peak with linear interpolation
static inline float truepeak_estimate(const float* in, int n, int ch) {
  float tp = 0.0f;
  if (g_tp_os <= 1) {
    for (int i = 0; i < n*ch; i+=ch) {
      float a = std::max(std::abs(in[i]), (ch>1? std::abs(in[i+1]) : 0.0f));
      tp = std::max(tp, a);
    }
    return tp;
  }
  int C = ch;
  for (int c = 0; c < std::min(2,C); ++c) {
    // Linear interpolation cannot exceed endpoints; include the final sample,
    // including singleton buffers. This remains an approximate peak estimator.
    tp = std::max(tp, std::abs(in[(n-1)*C + c]));
    for (int i = 0; i < n-1; ++i) {
      float s0 = in[i*C + c];
      float s1 = in[(i+1)*C + c];
      // upsample linearly
      int OS = g_tp_os;
      for (int k = 0; k < OS; ++k) {
        float t = (float)k / (float)OS;
        float y = s0 + (s1 - s0) * t;
        tp = std::max(tp, std::abs(y));
      }
    }
  }
  return tp;
}

// Feed interleaved frames. K-weight, compute energy, update windows, gating hist, and true-peak estimate.
void process_frames(const float* interleavedLR, int frames, int channels) {
  if (!interleavedLR || frames <= 0 || channels <= 0) return;

  float tp = truepeak_estimate(interleavedLR, frames, channels);

  bool history_updated = false;

  // Per-sample processing
  for (int i = 0; i < frames; ++i) {
    float l = interleavedLR[i*channels + 0];
    // Mono contributes once; stereo L/R each have unit channel weight.
    double lk = g_rlb.processL(g_pre.processL(l));
    double energy = lk * lk;
    // Advance an absent right channel with silence so topology changes cannot
    // resurrect frozen filter history; its decay does not count as mono energy.
    double r = channels > 1 ? interleavedLR[i*channels + 1] : 0.0;
    double rk = g_rlb.processR(g_pre.processR(r));
    if (channels > 1) {
      energy += rk * rk;
    }
    float e = (float)energy;

    g_m_win.push(e);
    g_s_win.push(e);

    // Integrated block storage (100ms)
    g_block_samples++;
    if (g_block_samples >= g_block_target) {
      // average energy of last 100 ms approx
      float m = g_m_win.sum() / std::max<size_t>(1, g_m_win.filled);
      // store as LUFS-like (log domain) proxy or keep energy and log later
      // Keep capacity bounded without allocating on the render thread.
      if (g_hist.size() == g_hist_max) g_hist.erase(g_hist.begin());
      g_hist.push_back(m);
      history_updated = true;
      g_block_samples = 0;
    }
  }

  // Update momentary/short-term LUFS
  float Em = g_m_win.sum() / std::max<size_t>(1, g_m_win.filled);
  float Es = g_s_win.sum() / std::max<size_t>(1, g_s_win.filled);
  g_lufs_m = -0.691f + 10.0f * std::log10(std::max(Em, 1e-12f));
  g_lufs_s = -0.691f + 10.0f * std::log10(std::max(Es, 1e-12f));

  // Integrated with simple absolute and relative gating
  if (history_updated) {
    // Convert energies to LUFS-like per block
    auto& lufs = g_lufs_scratch;
    lufs.resize(g_hist.size());
    for (size_t i=0;i<g_hist.size();++i) {
      lufs[i] = -0.691f + 10.0f * std::log10(std::max(g_hist[i], 1e-12f));
    }
    // Absolute gate -70 LUFS
    double absolute_sum = 0;
    size_t absolute_count = 0;
    for (float v : lufs) if (v > -70.0f) { absolute_sum += v; ++absolute_count; }

    float mean = -70.0f;
    if (absolute_count > 0) {
      mean = (float)(absolute_sum / absolute_count);
      // Relative gate: discard blocks more than 10 LU below current mean
      double relative_sum = 0;
      size_t relative_count = 0;
      for (float v : lufs) if (v > -70.0f && v > mean - 10.0f) {
        relative_sum += v; ++relative_count;
      }
      if (relative_count > 0) mean = (float)(relative_sum / relative_count);
    }
    g_lufs_i = mean;

    // LRA: interpercentile range over short-term history
    if (lufs.size() >= 20) {
      auto& tmp = g_percentile_scratch;
      tmp.assign(lufs.begin(), lufs.end());
      std::sort(tmp.begin(), tmp.end());
      auto pct = [&](double p)->float{
        double x = p * (tmp.size()-1);
        size_t i0 = (size_t)std::floor(x);
        size_t i1 = std::min(tmp.size()-1, i0+1);
        float t = (float)(x - i0);
        return tmp[i0]*(1-t) + tmp[i1]*t;
      };
      float p10 = pct(0.10), p95 = pct(0.95);
      g_lra = p95 - p10;
      if (g_lra < 0) g_lra = 0;
    }
  }

  g_truepk = 20.0f * std::log10(std::max(tp, 1e-9f)); // dBFS
}

float get_lufs_momentary() { return g_lufs_m; }
float get_lufs_shortterm() { return g_lufs_s; }
float get_lufs_integrated(){ return g_lufs_i; }
float get_lra()            { return g_lra; }
float get_true_peak_dbfs() { return g_truepk; }

} // extern "C"
