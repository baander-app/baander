#include <cmath>
#include <cstdint>
#include <cstdlib>
#include <algorithm>
#include <vector>
#include <limits>

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

// Exact programme gating distribution. Storage is allocated only at init.
// Distinct block energies share no quantized bins: threshold comparisons stay
// exact even when many blocks lie close to the relative gate.
#ifndef LOUDNESS_GATING_CAPACITY
#define LOUDNESS_GATING_CAPACITY 262144
#endif
struct GateDistribution {
  struct Node {
    double energy = 0, sum = 0;
    uint64_t count = 0, total_count = 0;
    uint32_t left = 0, right = 0;
    int height = 1;
  };
  struct Aggregate { double sum = 0; uint64_t count = 0; };
  std::vector<Node> nodes;
  uint32_t root = 0, used = 0;
  bool exhausted = false;

  void init() {
    nodes.resize(LOUDNESS_GATING_CAPACITY + 1);
    root = used = 0;
    exhausted = false;
    nodes[0] = Node{};
    nodes[0].height = 0;
  }
  int height(uint32_t i) const { return nodes[i].height; }
  void update(uint32_t i) {
    Node& n = nodes[i];
    n.height = 1 + std::max(height(n.left), height(n.right));
    n.sum = n.energy * n.count + nodes[n.left].sum + nodes[n.right].sum;
    n.total_count = n.count + nodes[n.left].total_count + nodes[n.right].total_count;
  }
  uint32_t rotate_left(uint32_t i) {
    uint32_t j = nodes[i].right;
    nodes[i].right = nodes[j].left;
    nodes[j].left = i;
    update(i); update(j);
    return j;
  }
  uint32_t rotate_right(uint32_t i) {
    uint32_t j = nodes[i].left;
    nodes[i].left = nodes[j].right;
    nodes[j].right = i;
    update(i); update(j);
    return j;
  }
  uint32_t insert(uint32_t i, double energy) {
    if (!i) {
      if (used == LOUDNESS_GATING_CAPACITY) {
        exhausted = true;
        return 0;
      }
      i = ++used;
      nodes[i] = Node{};
      nodes[i].energy = nodes[i].sum = energy;
      nodes[i].count = nodes[i].total_count = 1;
      return i;
    }
    Node& n = nodes[i];
    if (energy < n.energy) n.left = insert(n.left, energy);
    else if (energy > n.energy) n.right = insert(n.right, energy);
    else ++n.count;
    update(i);
    int balance = height(n.left) - height(n.right);
    if (balance > 1) {
      if (energy > nodes[n.left].energy) n.left = rotate_left(n.left);
      return rotate_right(i);
    }
    if (balance < -1) {
      if (energy < nodes[n.right].energy) n.right = rotate_right(n.right);
      return rotate_left(i);
    }
    return i;
  }
  void add(double energy) {
    if (exhausted) return;
    if (nodes[root].total_count == std::numeric_limits<uint64_t>::max()) {
      exhausted = true;
      return;
    }
    root = insert(root, energy);
  }
  Aggregate above(double threshold) const {
    Aggregate result;
    uint32_t i = root;
    while (i) {
      const Node& n = nodes[i];
      if (n.energy > threshold) {
        result.sum += n.energy * n.count + nodes[n.right].sum;
        result.count += n.count + nodes[n.right].total_count;
        i = n.left;
      } else i = n.right;
    }
    return result;
  }
  // LRA uses inclusive gates; integrated loudness retains above()'s strict gate.
  Aggregate at_least(double threshold) const {
    Aggregate result;
    uint32_t i = root;
    while (i) {
      const Node& n = nodes[i];
      if (n.energy >= threshold) {
        result.sum += n.energy * n.count + nodes[n.right].sum;
        result.count += n.count + nodes[n.right].total_count;
        i = n.left;
      } else i = n.right;
    }
    return result;
  }
  // Zero-based order statistic including repeated energies, O(log distinct keys).
  double select(uint64_t rank) const {
    uint32_t i = root;
    while (i) {
      const Node& n = nodes[i];
      const uint64_t left_count = nodes[n.left].total_count;
      if (rank < left_count) i = n.left;
      else if (rank - left_count < n.count) return n.energy;
      else { rank -= left_count + n.count; i = n.right; }
    }
    return std::numeric_limits<double>::quiet_NaN();
  }

};
static GateDistribution g_integrated;
static size_t g_gate_remaining = 0;
static size_t g_gate_hop = 0;
static const double g_absolute_gate = std::pow(10.0, (-70.0 + 0.691) / 10.0);

// Exact LRA distribution has its own independently exhausted/reset pool.
static GateDistribution g_shortterm;
static size_t g_shortterm_remaining = 0;
static size_t g_shortterm_hop = 0;

// EBU Tech 3342 MATLAB: inclusive cascaded gates and rounded sample ranks.
// Log conversion after rank selection is equivalent to sorting log levels.
static float calculate_lra() {
  if (g_shortterm.exhausted) return std::numeric_limits<float>::quiet_NaN();
  const auto absolute = g_shortterm.at_least(g_absolute_gate);
  if (!absolute.count) return 0.0f;
  const double threshold = std::max(g_absolute_gate, absolute.sum / absolute.count * 0.01);
  const auto gated = g_shortterm.at_least(threshold);
  if (!gated.count) return 0.0f;
  const uint64_t offset = g_shortterm.nodes[g_shortterm.root].total_count - gated.count;
  // Exact rounded ranks without losing integer precision or overflowing at
  // uint64 limits: round(N/10) and round(19*N/20), ties rounded upward.
  const uint64_t n = gated.count - 1;
  const uint64_t low_rank = n / 10 + (n % 10 + 5) / 10;
  const uint64_t high_rank = (n / 20) * 19 + ((n % 20) * 19 + 10) / 20;
  const double low = g_shortterm.select(offset + low_rank);
  const double high = g_shortterm.select(offset + high_rank);
  return (float)(10.0 * (std::log10(high) - std::log10(low)));
}

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

  g_shortterm.init();
  g_shortterm_remaining = g_s_win.size(); // First observation needs all 3 s.
  g_shortterm_hop = std::max(1, g_sr / 10); // At least 10 Hz, independent of calls.
  g_integrated.init();
  g_gate_remaining = g_m_win.size(); // First gate needs a complete 400 ms.
  g_gate_hop = std::max<size_t>(1, (size_t)std::round(g_m_win.size() / 4.0));

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

// Feed interleaved frames. K-weight, compute energy, update windows, programme distributions, and true-peak estimate.
void process_frames(const float* interleavedLR, int frames, int channels) {
  if (!interleavedLR || frames <= 0 || channels <= 0) return;

  float tp = truepeak_estimate(interleavedLR, frames, channels);

  bool shortterm_updated = false;
  bool integrated_updated = false;

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

    // BS.1770-5 Annex 1: complete 400 ms windows, overlapping by 75%.
    if (--g_gate_remaining == 0) {
      double block_energy = g_m_win.sum() / g_m_win.size();
      if (std::isfinite(block_energy) && block_energy > g_absolute_gate) {
        g_integrated.add(block_energy);
      }
      integrated_updated = true;
      g_gate_remaining = g_gate_hop;
    }

    // Tech 3342: complete 3 s windows, sampled at least 10 times per second.
    if (--g_shortterm_remaining == 0) {
      const double shortterm_energy = g_s_win.sum() / g_s_win.size();
      if (std::isfinite(shortterm_energy) && shortterm_energy >= g_absolute_gate) {
        g_shortterm.add(shortterm_energy);
      }
      shortterm_updated = true;
      g_shortterm_remaining = g_shortterm_hop;
    }
  }

  // Update momentary/short-term LUFS
  float Em = g_m_win.sum() / std::max<size_t>(1, g_m_win.filled);
  float Es = g_s_win.sum() / std::max<size_t>(1, g_s_win.filled);
  g_lufs_m = -0.691f + 10.0f * std::log10(std::max(Em, 1e-12f));
  g_lufs_s = -0.691f + 10.0f * std::log10(std::max(Es, 1e-12f));

  if (integrated_updated) {
    if (g_integrated.exhausted) {
      // Never silently truncate a programme when the fixed pool fills.
      g_lufs_i = std::numeric_limits<float>::quiet_NaN();
    } else {
      const auto absolute = g_integrated.above(g_absolute_gate);
      if (absolute.count) {
        const double relative_gate = absolute.sum / absolute.count * 0.1;
        const auto gated = g_integrated.above(std::max(g_absolute_gate, relative_gate));
        g_lufs_i = gated.count
          ? (float)(-0.691 + 10.0 * std::log10(gated.sum / gated.count))
          : -70.0f;
      } else g_lufs_i = -70.0f;
    }
  }

  if (shortterm_updated) g_lra = calculate_lra();

  g_truepk = 20.0f * std::log10(std::max(tp, 1e-9f)); // dBFS
}

float get_lufs_momentary() { return g_lufs_m; }
float get_lufs_shortterm() { return g_lufs_s; }
float get_lufs_integrated(){ return g_lufs_i; }
float get_lra()            { return g_lra; }
float get_true_peak_dbfs() { return g_truepk; }

} // extern "C"
