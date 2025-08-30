#include <cmath>
#include <cstdint>
#include <cstdlib>
#include <algorithm>
#include <vector>

extern "C" {

static int g_sr = 48000;
static int g_tp_os = 1; // true-peak oversample factor (1/2/4)

struct Biquad {
  float b0=1, b1=0, b2=0, a1=0, a2=0;
  float z1L=0, z2L=0, z1R=0, z2R=0;

  inline void set(float B0,float B1,float B2,float A1,float A2){
    b0=B0; b1=B1; b2=B2; a1=A1; a2=A2;
    z1L=z2L=z1R=z2R=0.0f;
  }

  inline void reset(){ z1L=z2L=z1R=z2R=0.0f; }

  inline float processL(float x){
    float y = b0*x + z1L;
    z1L = b1*x + z2L - a1*y;
    z2L = b2*x - a2*y;
    return y;
  }
  inline float processR(float x){
    float y = b0*x + z1R;
    z1R = b1*x + z2R - a1*y;
    z2R = b2*x - a2*y;
    return y;
  }
};

// K-weighting: 1st-order highpass (pre) + 2nd-order high-shelf approx (RLB)
static Biquad g_pre; // highpass ~ 60 Hz
static Biquad g_rlb; // high-shelf approx per ITU-R BS.1770-ish

// Energy ring buffers
struct Ring {
  std::vector<float> buf;
  size_t idx = 0;
  size_t filled = 0;

  void init(size_t n) { buf.assign(n, 0.0f); idx=0; filled=0; }
  inline void push(float v){
    if (buf.empty()) return;
    buf[idx] = v;
    idx = (idx + 1) % buf.size();
    if (filled < buf.size()) filled++;
  }
  inline float sum() const {
    float s=0; for (size_t i=0;i<filled;i++) s+=buf[i]; return s;
  }
  inline size_t size() const { return buf.size(); }
};

// Windows
static Ring g_m_win; // 400 ms momentary
static Ring g_s_win; // 3 s short-term

// Integrated storage for gating (coarse)
static std::vector<float> g_hist; // store block energies (e.g., 100 ms blocks)
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

// Rough butterworth 1st-order HPF at 60 Hz (z-plane bilinear transform)
static void design_pre(int sr) {
  const float fc = 60.0f;
  float k = std::tan((float)M_PI * fc / (float)sr);
  float norm = 1.0f / (1.0f + k);
  float b0 = 1.0f * norm;
  float b1 = -1.0f * norm;
  float a1 = (1.0f - k) * norm;
  g_pre.set(b0, b1, 0.0f, -a1, 0.0f);
}

// Simple high-shelf approximation near 4 kHz
static void design_rlb(int sr) {
  // Using a gentle shelf approximation (not exact ITU)
  const float fc = 1500.0f;
  const float gain_db = 4.0f; // modest pre-emphasis
  float A = std::pow(10.0f, gain_db / 40.0f);
  float w0 = 2.0f*(float)M_PI*fc/(float)sr;
  float alpha = std::sin(w0)/2.0f * std::sqrt((A + 1/A)*(1/0.707f - 1) + 2.0f);
  float cosw0 = std::cos(w0);

  float b0 =    A*( (A+1) + (A-1)*cosw0 + 2*std::sqrt(A)*alpha );
  float b1 = -2*A*( (A-1) + (A+1)*cosw0 );
  float b2 =    A*( (A+1) + (A-1)*cosw0 - 2*std::sqrt(A)*alpha );
  float a0 =        (A+1) - (A-1)*cosw0 + 2*std::sqrt(A)*alpha;
  float a1 =  2*( (A-1) - (A+1)*cosw0 );
  float a2 =        (A+1) - (A-1)*cosw0 - 2*std::sqrt(A)*alpha;

  // normalize
  b0/=a0; b1/=a0; b2/=a0; a1/=a0; a2/=a0;
  g_rlb.set(b0, b1, b2, a1, a2);
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

  // Per-sample processing
  for (int i = 0; i < frames; ++i) {
    float l = interleavedLR[i*channels + 0];
    float r = (channels > 1) ? interleavedLR[i*channels + 1] : l;

    // K-weighting
    float lp = g_pre.processL(l);
    float rp = g_pre.processR(r);
    float lk = g_rlb.processL(lp);
    float rk = g_rlb.processR(rp);

    // Energy (mean of channels)
    float e = 0.5f * (lk*lk + rk*rk);

    g_m_win.push(e);
    g_s_win.push(e);

    // Integrated block storage (100ms)
    g_block_samples++;
    if (g_block_samples >= g_block_target) {
      // average energy of last 100 ms approx
      float m = g_m_win.sum() / std::max<size_t>(1, g_m_win.filled);
      // store as LUFS-like (log domain) proxy or keep energy and log later
      g_hist.push_back(m);
      if (g_hist.size() > g_hist_max) {
        g_hist.erase(g_hist.begin(), g_hist.begin() + (g_hist.size() - g_hist_max));
      }
      g_block_samples = 0;
    }
  }

  // Update momentary/short-term LUFS
  float Em = g_m_win.sum() / std::max<size_t>(1, g_m_win.filled);
  float Es = g_s_win.sum() / std::max<size_t>(1, g_s_win.filled);
  g_lufs_m = -0.691f + 10.0f * std::log10(std::max(Em, 1e-12f));
  g_lufs_s = -0.691f + 10.0f * std::log10(std::max(Es, 1e-12f));

  // Integrated with simple absolute and relative gating
  if (!g_hist.empty()) {
    // Convert energies to LUFS-like per block
    std::vector<float> lufs(g_hist.size());
    for (size_t i=0;i<g_hist.size();++i) {
      lufs[i] = -0.691f + 10.0f * std::log10(std::max(g_hist[i], 1e-12f));
    }
    // Absolute gate -70 LUFS
    std::vector<float> gated;
    gated.reserve(lufs.size());
    for (float v : lufs) if (v > -70.0f) gated.push_back(v);

    float mean = -70.0f;
    if (!gated.empty()) {
      double s=0; for (float v : gated) s+=v;
      mean = (float)(s / gated.size());
      // Relative gate: discard blocks more than 10 LU below current mean
      std::vector<float> gated2; gated2.reserve(gated.size());
      for (float v : gated) if (v > mean - 10.0f) gated2.push_back(v);
      if (!gated2.empty()) {
        s=0; for (float v : gated2) s+=v;
        mean = (float)(s / gated2.size());
      }
    }
    g_lufs_i = mean;

    // LRA: interpercentile range over short-term history
    if (lufs.size() >= 20) {
      std::vector<float> tmp = lufs;
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
