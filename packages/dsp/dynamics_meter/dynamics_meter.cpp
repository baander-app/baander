#include <cmath>
#include <cstdint>
#include <cstdlib>
#include <algorithm>

extern "C" {

static int g_sr = 48000;
static float g_att = 0.01f; // seconds
static float g_rel = 0.10f; // seconds

// State per channel
struct MeterState {
  float rms_env = 0.0f;   // smoothed RMS (linear)
  float peak_env = 0.0f;  // smoothed peak (linear)
};

static MeterState gL, gR;
static float g_a_rms_att=0, g_a_rms_rel=0, g_a_peak_att=0, g_a_peak_rel=0;

static inline float dbfs_from_rms(float x) { return x > 1e-12f ? 20.0f*std::log10(x) : -200.0f; }
static inline float lerp(float a, float b, float t) { return a + (b - a) * t; }

static void recompute_coeffs() {
  auto coeff = [&](float t)->float {
    float a = std::exp(-1.0f / std::max(1, g_sr) / std::max(1e-6f, t));
    return a;
  };
  g_a_rms_att  = coeff(std::max(1e-4f, g_att));
  g_a_rms_rel  = coeff(std::max(1e-4f, g_rel));
  g_a_peak_att = coeff(std::max(1e-4f, g_att*0.5f));
  g_a_peak_rel = coeff(std::max(1e-4f, g_rel*0.5f));
}

void init_meters(float attack_ms, float release_ms, int sample_rate) {
  g_sr = (sample_rate > 0) ? sample_rate : 48000;
  g_att = std::max(0.0f, attack_ms * 0.001f);
  g_rel = std::max(0.0f, release_ms * 0.001f);
  gL = MeterState{}; gR = MeterState{};
  recompute_coeffs();
}

void reset_meters() {
  gL = MeterState{};
  gR = MeterState{};
}

static inline void process_sample_pair(float L, float R) {
  float lrms = std::sqrt(std::max(0.0f, L*L));
  float rrms = std::sqrt(std::max(0.0f, R*R));
  float lpk  = std::fabs(L);
  float rpk  = std::fabs(R);

  // RMS smoothing
  if (lrms > gL.rms_env) gL.rms_env = lerp(lrms, gL.rms_env, g_a_rms_att);
  else                   gL.rms_env = lerp(lrms, gL.rms_env, g_a_rms_rel);

  if (rrms > gR.rms_env) gR.rms_env = lerp(rrms, gR.rms_env, g_a_rms_att);
  else                   gR.rms_env = lerp(rrms, gR.rms_env, g_a_rms_rel);

  // Peak smoothing
  if (lpk > gL.peak_env) gL.peak_env = lerp(lpk, gL.peak_env, g_a_peak_att);
  else                   gL.peak_env = lerp(lpk, gL.peak_env, g_a_peak_rel);

  if (rpk > gR.peak_env) gR.peak_env = lerp(rpk, gR.peak_env, g_a_peak_att);
  else                   gR.peak_env = lerp(rpk, gR.peak_env, g_a_peak_rel);
}

// Interleaved LR frames
void process_frames(const float* interleavedLR, int frames, int channels) {
  if (!interleavedLR || frames <= 0 || channels <= 0) return;
  for (int i = 0; i < frames; ++i) {
    float L = interleavedLR[i*channels + 0];
    float R = (channels > 1) ? interleavedLR[i*channels + 1] : L;
    process_sample_pair(L, R);
  }
}

// Getters (linear)
float get_rms_left()  { return gL.rms_env; }
float get_rms_right() { return gR.rms_env; }
float get_peak_left() { return gL.peak_env; }
float get_peak_right(){ return gR.peak_env; }

// Crest factor = Peak/RMS in dB => 20*log10(Peak/RMS) = Peak_dB - RMS_dB
float get_crest_left()  {
  float pr = (gL.rms_env > 1e-12f) ? (gL.peak_env / gL.rms_env) : 0.0f;
  return pr > 1e-12f ? 20.0f*std::log10(pr) : 0.0f;
}
float get_crest_right() {
  float pr = (gR.rms_env > 1e-12f) ? (gR.peak_env / gR.rms_env) : 0.0f;
  return pr > 1e-12f ? 20.0f*std::log10(pr) : 0.0f;
}

} // extern "C"
