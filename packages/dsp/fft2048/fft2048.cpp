#include <cmath>
#include <cstdint>
#include <cstdlib>

extern "C" {

// Constants
static const int N = 2048;     // FFT size
static const int HN = N / 2;   // Half (magnitude bins)

// Windowing
enum WindowType {
  WINDOW_NONE = 0,
  WINDOW_HANN = 1,
};

// Internal state
static WindowType g_windowType = WINDOW_HANN;
static float g_window[N];               // window coefficients
static bool g_windowReady = false;

// Streaming accumulator (optional)
static float g_acc[N];
static int   g_accIdx = 0;
static bool  g_lastReady = false;
static uint8_t g_lastMag[HN];
static uint8_t g_lastWave[N];

// Utilities
static inline float clamp01(float x) {
  if (x < 0.0f) return 0.0f;
  if (x > 1.0f) return 1.0f;
  return x;
}

static void init_window(WindowType type) {
  g_windowType = type;
  if (type == WINDOW_HANN) {
    // Hann: w[n] = 0.5 * (1 - cos(2*pi*n/(N-1)))
    const float twoPi = 6.283185307179586476925286766559f;
    for (int n = 0; n < N; ++n) {
      g_window[n] = 0.5f * (1.0f - std::cos(twoPi * n / (N - 1)));
    }
  } else {
    for (int n = 0; n < N; ++n) g_window[n] = 1.0f;
  }
  g_windowReady = true;
}

// Bit-reversal permutation (11-bit reverse for N=2048)
static inline unsigned bit_reverse11(unsigned x) {
  x = ((x & 0x555) << 1) | ((x & 0xAAA) >> 1);
  x = ((x & 0x333) << 2) | ((x & 0xCCC) >> 2);
  x = ((x & 0x0F0) << 4) | ((x & 0xF00) >> 4) | (x & 0x00F);
  unsigned low = x & 0x00F;
  low = ((low & 0x1) << 3) | ((low & 0x2) << 1) | ((low & 0x4) >> 1) | ((low & 0x8) >> 3);
  x = (x & ~0x00F) | low;
  return x & 0x7FF;
}

// Core FFT: inplace, separate real/imag, decimation-in-time Cooley–Tukey
static void fft_radix2(float* re, float* im) {
  // Bit-reversal reordering
  for (unsigned i = 0; i < (unsigned)N; ++i) {
    unsigned j = bit_reverse11(i);
    if (j > i) {
      float tr = re[i]; re[i] = re[j]; re[j] = tr;
      float ti = im[i]; im[i] = im[j]; im[j] = ti;
    }
  }

  // Iterative stages
  const float PI = 3.1415926535897932384626433832795f;
  for (int len = 2; len <= N; len <<= 1) {
    int half = len >> 1;
    float ang = -2.0f * PI / (float)len;
    float wlen_cos = std::cos(ang);
    float wlen_sin = std::sin(ang);

    for (int i = 0; i < N; i += len) {
      float wr = 1.0f, wi = 0.0f;
      for (int j = 0; j < half; ++j) {
        int i0 = i + j;
        int i1 = i0 + half;

        float ur = re[i0];
        float ui = im[i0];
        float vr = re[i1] * wr - im[i1] * wi;
        float vi = re[i1] * wi + im[i1] * wr;

        re[i0] = ur + vr;
        im[i0] = ui + vi;
        re[i1] = ur - vr;
        im[i1] = ui - vi;

        // w *= wlen
        float tmp = wr * wlen_cos - wi * wlen_sin;
        wi = wr * wlen_sin + wi * wlen_cos;
        wr = tmp;
      }
    }
  }
}

// Exported API

// Simple heap helpers to make JS side easier (optional)
void* wasm_malloc(size_t n) {
  return std::malloc(n);
}

void wasm_free(void* p) {
  std::free(p);
}

// Initialize FFT module (window selection)
void init_fft(int window_type /*0:none, 1:hann*/) {
  WindowType wt = (window_type == WINDOW_HANN) ? WINDOW_HANN : WINDOW_NONE;
  init_window(wt);
  // Reset streaming accumulator as well
  g_accIdx = 0;
  g_lastReady = false;
}

// Reset streaming state
void reset_fft() {
  g_accIdx = 0;
  g_lastReady = false;
}

// Process a full 2048-sample mono frame:
// - input: float[N] in [-1, 1]
// - magOut: uint8_t[1024] magnitude (scaled 0..255-ish)
// - waveOut: uint8_t[N] display waveform (0..255)
void process_spectrum(const float* input, uint8_t* magOut, uint8_t* waveOut) {
  if (!g_windowReady) init_window(g_windowType);

  static float re[N];
  static float im[N];

  // Copy, window, and create display waveform
  for (int i = 0; i < N; ++i) {
    float x = input[i];
    float y = (x * 0.5f + 0.5f) * 255.0f; // [-1,1] -> [0,255]
    if (y < 0.0f) y = 0.0f; else if (y > 255.0f) y = 255.0f;
    waveOut[i] = (uint8_t)(y + 0.5f);

    float w = g_window[i];
    re[i] = x * w;
    im[i] = 0.0f;
  }

  // FFT
  fft_radix2(re, im);

  // Magnitude of 0..N/2-1 (real input symmetry)
  const float scale = 2.0f / (float)N; // simple normalization
  for (int k = 0; k < HN; ++k) {
    float mag = std::sqrt(re[k]*re[k] + im[k]*im[k]) * scale;
    float v = mag * 255.0f; // map to byte range
    if (v < 0.0f) v = 0.0f; else if (v > 255.0f) v = 255.0f;
    magOut[k] = (uint8_t)(v + 0.5f);
  }
}

// Optional: push a small block of interleaved audio, accumulate to 2048 mono samples.
// Returns 1 if a new spectrum was computed and stored internally, else 0.
// When it returns 1, call consume_spectrum() to copy results out.
int push_block(const float* interleaved, int frames, int channels) {
  if (channels <= 0) return 0;
  if (!g_windowReady) init_window(g_windowType);

  int i = 0;
  while (i < frames && g_accIdx < N) {
    float sum = 0.0f;
    if (channels == 1) {
      sum = interleaved[i];
    } else {
      int base = i * channels;
      float l = interleaved[base];
      float r = interleaved[base + 1];
      sum = l + r;
    }
    g_acc[g_accIdx++] = sum;
    ++i;
  }

  if (g_accIdx >= N) {
    process_spectrum(g_acc, g_lastMag, g_lastWave);
    g_accIdx = 0;
    g_lastReady = true;
    return 1;
  }
  return 0;
}

// Copy last computed spectrum if available; returns 1 if copied, else 0
int consume_spectrum(uint8_t* magOut, uint8_t* waveOut) {
  if (!g_lastReady) return 0;
  for (int i = 0; i < HN; ++i) magOut[i] = g_lastMag[i];
  for (int i = 0; i < N;  ++i) waveOut[i] = g_lastWave[i];
  g_lastReady = false; // mark consumed
  return 1;
}

// Optional: downsample helpers (stride=4 picks 256 freq bins, 512 waveform samples)
void downsample_mag_stride4(const uint8_t* magIn1024, uint8_t* magOut256) {
  for (int o = 0, i = 0; o < 256; ++o, i += 4) {
    magOut256[o] = magIn1024[i];
  }
}
void downsample_wave_stride4(const uint8_t* waveIn2048, uint8_t* waveOut512) {
  for (int o = 0, i = 0; o < 512; ++o, i += 4) {
    waveOut512[o] = waveIn2048[i];
  }
}

} // extern "C"
