#include <cmath>
#include <cstdint>
#include <cstdlib>
#include <algorithm>
#include <vector>

extern "C" {

struct Resampler {
  int in_rate=48000, out_rate=48000, ch=1, taps=32;
  double ratio=1.0;
  std::vector<float> delay; // ring buffer per channel
  std::vector<int>   head;  // write index per channel
  std::vector<float> win;   // window (Hann)
};

static inline float sinc(float x) {
  if (std::fabs(x) < 1e-8f) return 1.0f;
  return std::sin((float)M_PI * x) / ((float)M_PI * x);
}

static void ensure(Resampler* rs) {
  if ((int)rs->win.size() != rs->taps) {
    rs->win.resize(rs->taps);
    for (int i=0;i<rs->taps;i++) {
      rs->win[i] = 0.5f*(1.0f - std::cos(2.0f*(float)M_PI * i/(rs->taps-1)));
    }
  }
  if ((int)rs->delay.size() != rs->taps * rs->ch) {
    rs->delay.assign(rs->taps * rs->ch, 0.0f);
    rs->head.assign(rs->ch, 0);
  }
}

void* create_resampler(int in_rate, int out_rate, int channels, int quality) {
  Resampler* rs = new Resampler();
  rs->in_rate  = (in_rate  > 0) ? in_rate  : 48000;
  rs->out_rate = (out_rate > 0) ? out_rate : 48000;
  rs->ch = std::max(1, channels);
  rs->taps = std::max(8, std::min(128, quality>0 ? quality : 32));
  rs->ratio = (double)rs->out_rate / (double)rs->in_rate;
  ensure(rs);
  return (void*)rs;
}

void destroy_resampler(void* ctx) {
  if (!ctx) return;
  delete (Resampler*)ctx;
}

// Direct-form windowed-sinc (per sample) — simple and high-quality, not the fastest.
// Returns number of output frames written (<= out_capacity_frames)
int resample(void* ctx, const float* in, int in_frames, float* out, int out_capacity_frames) {
  if (!ctx || !in || !out || in_frames <= 0 || out_capacity_frames <= 0) return 0;
  Resampler* rs = (Resampler*)ctx;
  ensure(rs);

  const int C = rs->ch;
  const int T = rs->taps;
  const int half = T/2;

  // Consume all input into per-channel ring buffers
  for (int i=0;i<in_frames;i++) {
    for (int c=0;c<C;c++) {
      int idx = c*T + rs->head[c];
      rs->delay[idx] = in[i*C + c];
    }
    for (int c=0;c<C;c++) {
      rs->head[c] = (rs->head[c] + 1) % T;
    }
  }

  // Number of output frames corresponding to these input frames
  int out_frames = std::min(out_capacity_frames, (int)std::floor(in_frames * rs->ratio + 1));
  double t = 0.0;

  // Synthesize output by sampling at fractional positions
  for (int o=0;o<out_frames;o++) {
    // Corresponding input position back in time (in samples)
    double pos_in = (double)o / rs->ratio;
    int ipos = (int)std::floor(pos_in);
    double frac = pos_in - ipos;

    for (int c=0;c<C;c++) {
      // Centered at -frac to align kernel
      double acc = 0.0;
      for (int k=-half; k<half; ++k) {
        int tap = k + half;
        // sample from delay ring: current head is newest; go back ipos+k
        int back = (rs->head[c] - 1 - (ipos + k)) % T;
        if (back < 0) back += T;
        float x = rs->delay[c*T + back];

        // Windowed-sinc kernel (normalized to taps, scaled by ratio)
        double xarg = (double)k - frac;
        double h = sinc(xarg) * rs->win[tap];
        acc += (double)x * h;
      }
      out[o*C + c] = (float)acc;
    }
  }

  return out_frames;
}

} // extern "C"
