#include <cmath>
#include <cstdlib>
#include <cstring>
#include <algorithm>

extern "C" {

// Rectangular, zero-padded RMS window and an instantaneous sample-peak
// envelope. The peak falls by exp(-1) over release_ms of processed audio.
// Neither meter depends on process_frames call boundaries. See README.md.
struct MeterState {
  double square_sum = 0.0;
  double peak_env = 0.0;
  int nonzero_samples = 0;
};

struct WindowSample {
  double left;
  double right;
};

static MeterState gL, gR;
static WindowSample* g_history = nullptr;
static int g_window_frames = 1;
static int g_position = 0;
static double g_peak_decay = 0.0;

void reset_meters() {
  gL = MeterState{};
  gR = MeterState{};
  g_position = 0;
  if (g_history) std::memset(g_history, 0, sizeof(WindowSample) * g_window_frames);
}

// rms_window_ms is rounded to the nearest frame and clamped to [1,384000].
// A nonpositive sample rate uses 48000 Hz. A nonpositive release is immediate.
// Allocate during initialization only; processing never allocates.
void init_meters(float rms_window_ms, float release_ms, int sample_rate) {
  const int sr = sample_rate > 0 ? sample_rate : 48000;
  const double requested_frames = std::round(static_cast<double>(rms_window_ms) * sr / 1000.0);
  g_window_frames = static_cast<int>(std::max(1.0, std::min(384000.0, requested_frames)));
  std::free(g_history);
  g_history = static_cast<WindowSample*>(std::calloc(g_window_frames, sizeof(WindowSample)));
  if (!g_history) std::abort();
  g_peak_decay = release_ms > 0
    ? std::exp(-1000.0 / (static_cast<double>(sr) * release_ms)) : 0.0;
  reset_meters();
}

static inline void update_channel(MeterState& state, double sample, double old_square) {
  const double square = sample * sample;
  state.square_sum += square - old_square;
  state.nonzero_samples += (square > 0) - (old_square > 0);
  // Remove floating-point residue once the last nonzero sample leaves.
  if (state.nonzero_samples == 0) state.square_sum = 0;
  state.peak_env = std::max(std::fabs(sample), state.peak_env * g_peak_decay);
}

static inline void process_sample_pair(float L, float R) {
  const WindowSample old = g_history[g_position];
  update_channel(gL, L, old.left);
  update_channel(gR, R, old.right);
  g_history[g_position] = {static_cast<double>(L) * L, static_cast<double>(R) * R};
  g_position = (g_position + 1) % g_window_frames;
}

// Interleaved frames; mono is duplicated, additional channels are ignored.
void process_frames(const float* interleavedLR, int frames, int channels) {
  if (!g_history || !interleavedLR || frames <= 0 || channels <= 0) return;
  for (int i = 0; i < frames; ++i) {
    float L = interleavedLR[i*channels + 0];
    float R = (channels > 1) ? interleavedLR[i*channels + 1] : L;
    process_sample_pair(L, R);
  }
}

static float rms(const MeterState& state) {
  return static_cast<float>(std::sqrt(std::max(0.0, state.square_sum) / g_window_frames));
}

float get_rms_left()  { return rms(gL); }
float get_rms_right() { return rms(gR); }
float get_peak_left() { return static_cast<float>(gL.peak_env); }
float get_peak_right(){ return static_cast<float>(gR.peak_env); }

// Envelope ratio in dB: these numerator/denominator use different histories.
// Return zero for a zero RMS or peak. This is not finite-window crest factor.
static float crest(const MeterState& state) {
  const float level = rms(state);
  return level > 0 && state.peak_env > 0
    ? static_cast<float>(20.0 * std::log10(state.peak_env / level)) : 0.0f;
}

float get_crest_left()  { return crest(gL); }
float get_crest_right() { return crest(gR); }

} // extern "C"
