#include <cmath>
#include <cstdint>
#include <cstdlib>
#include <algorithm>

extern "C" {

static int   g_fft_size = 2048;   // full fft size (expects N/2 magnitudes)
static int   g_bins = 1024;       // N/2
static int   g_sr = 48000;

static float g_centroid_hz = 0.0f;
static float g_rolloff_hz  = 0.0f;  // updated when get_rolloff_hz is called
static float g_flux        = 0.0f;
static float g_flatness    = 0.0f;
static int   g_peak_idx    = 0;

// Keep previous normalized spectrum for flux
static float* g_prev = nullptr;
static bool   g_prev_ready = false;

// Simple heap helpers
void* wasm_malloc(size_t n) { return std::malloc(n); }
void  wasm_free(void* p) { std::free(p); }

static inline float bin_to_hz(int k) {
  // bins cover [0 .. sr/2] across g_bins bins
  if (g_bins <= 1) return 0.0f;
  float frac = (float)k / (float)(g_bins - 1);
  return frac * (g_sr * 0.5f);
}

void init_features(int fft_size, int sample_rate) {
  if (fft_size > 0) {
    g_fft_size = fft_size;
    g_bins = fft_size / 2;
  } else {
    g_fft_size = 2048;
    g_bins = 1024;
  }
  g_sr = (sample_rate > 0) ? sample_rate : 48000;

  if (g_prev) { std::free(g_prev); g_prev = nullptr; }
  g_prev = (float*)std::malloc(sizeof(float) * g_bins);
  for (int i = 0; i < g_bins; ++i) g_prev[i] = 0.0f;
  g_prev_ready = false;

  g_centroid_hz = 0.0f;
  g_rolloff_hz  = 0.0f;
  g_flux        = 0.0f;
  g_flatness    = 0.0f;
  g_peak_idx    = 0;
}

// Input: mag1024 is uint8_t magnitude for N/2 bins (0..255)
// Internally we normalize to [0..1], compute features, and keep a copy for flux
void compute_from_mag(const uint8_t* mag1024) {
  if (!mag1024) return;
  const int B = g_bins;

  // Find peak and compute sums
  int peakIdx = 0;
  float peakVal = -1.0f;

  // Convert to float [0..1]
  // Accumulate: sum, sum(log), weighted sum for centroid, and total energy for rolloff
  double sum = 0.0;
  double sum_log = 0.0;
  double weighted = 0.0;
  double total_energy = 0.0;

  // Flux
  double flux_acc = 0.0;

  for (int k = 0; k < B; ++k) {
    float v = (float)mag1024[k] / 255.0f;
    if (v > peakVal) { peakVal = v; peakIdx = k; }

    sum += v;
    // avoid log(0)
    sum_log += (v > 1e-12f) ? std::log((double)v) : std::log(1e-12);
    weighted += (double)v * (double)k;
    total_energy += (double)v;

    if (g_prev_ready) {
      double d = (double)v - (double)g_prev[k];
      if (d > 0.0) flux_acc += d;
    }
    // store normalized for next flux
    g_prev[k] = v;
  }
  g_prev_ready = true;

  // Centroid
  if (sum > 1e-12) {
    double centroid_bin = weighted / sum;
    g_centroid_hz = (float)bin_to_hz((int)(centroid_bin + 0.5));
  } else {
    g_centroid_hz = 0.0f;
  }

  // Flux
  g_flux = (float)flux_acc;

  // Flatness (geometric mean / arithmetic mean)
  double geo = 0.0;
  if (B > 0) {
    geo = std::exp(sum_log / (double)B);
  }
  double arith = (B > 0) ? (sum / (double)B) : 0.0;
  g_flatness = (arith > 1e-12) ? (float)(geo / arith) : 0.0f;

  // Peak index
  g_peak_idx = peakIdx;
}

// Rolloff: find smallest k such that cumulative sum reaches percentile of total
float get_rolloff_hz(float percentile) {
  if (percentile < 0.0f) percentile = 0.0f;
  if (percentile > 1.0f) percentile = 1.0f;

  const int B = g_bins;
  // Build cumulative
  double total = 0.0;
  static double csum[8192]; // supports fft up to 16384
  if (B > 8192) return 0.0f;

  for (int k = 0; k < B; ++k) {
    double v = (double)g_prev[k]; // last normalized spectrum
    total += v;
    csum[k] = total;
  }
  if (total <= 1e-12) { g_rolloff_hz = 0.0f; return 0.0f; }

  double target = total * (double)percentile;
  int kroll = 0;
  while (kroll < B && csum[kroll] < target) ++kroll;

  float hz = (kroll >= B) ? (g_sr * 0.5f) : (float)bin_to_hz(kroll);
  g_rolloff_hz = hz;
  return hz;
}

float get_centroid_hz() { return g_centroid_hz; }
float get_flux() { return g_flux; }
float get_flatness() { return g_flatness; }
int   get_peak_index() { return g_peak_idx; }

// Log-spaced band energies (compact vector). Output is uint8_t[bands] scaled to 0..255
void get_band_energies(uint8_t* out, int bands) {
  if (!out || bands <= 0) return;
  const int B = g_bins;
  // Bounds in Hz
  const float fmin = 20.0f;
  const float fmax = (float)g_sr * 0.5f;
  const float log_min = std::log(std::max(fmin, 1.0f));
  const float log_max = std::log(fmax);
  const float step = (log_max - log_min) / (float)bands;

  for (int b = 0; b < bands; ++b) {
    float lo_hz = std::exp(log_min + step * b);
    float hi_hz = std::exp(log_min + step * (b + 1));
    // Convert to bin range
    int lo = (int)std::floor((lo_hz / (g_sr * 0.5f)) * (B - 1));
    int hi = (int)std::ceil ((hi_hz / (g_sr * 0.5f)) * (B - 1));
    if (lo < 0) lo = 0;
    if (hi >= B) hi = B - 1;
    if (hi < lo) hi = lo;

    double sum = 0.0;
    int count = 0;
    for (int k = lo; k <= hi; ++k) {
      sum += (double)g_prev[k];
      count++;
    }
    double avg = (count > 0) ? (sum / (double)count) : 0.0;
    int v = (int)std::round(std::max(0.0, std::min(255.0, avg * 255.0)));
    out[b] = (uint8_t)v;
  }
}

} // extern "C"
