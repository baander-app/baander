#include <cmath>
#include <cstdlib>

#include "sbr.h"

namespace {
static thread_local unsigned int sbr_noise_seed = 54321u;
}  // namespace

void aac_sbr_decode(AacSbrEnvelope* env, AacSbrQmf* qmf, const float* core_low, int core_bands,
                    int sbr_bands, float* output) {
  float patched[64][32] = {{0}};

  /* Frequency patching: copy low-band energy to high-band */
  for (int ts = 0; ts < env->time_slots; ts++) {
    for (int band = 0; band < sbr_bands; band++) {
      int src = band % core_bands;
      patched[core_bands + band][ts] = (src < 1024) ? core_low[src] : 0.0f;
    }
  }

  /* Apply envelope and noise */
  for (int ts = 0; ts < env->time_slots; ts++) {
    for (int band = 0; band < env->freq_bands_hi; band++) {
      float scale = powf(10.0f, env->envelope[ts][band] / 10.0f);
      patched[core_bands + band][ts] *= sqrtf(scale);

      float ns = powf(10.0f, env->noise_floor[ts][band] / 10.0f);
      float noise = (float)rand_r(&sbr_noise_seed) / (float)RAND_MAX * 2.0f -
                    1.0f;  // NOLINT(concurrency-mt-unsafe)
      patched[core_bands + band][ts] += ns * noise;
    }
  }

  aac_sbr_qmf_synthesis(qmf, patched, output, nullptr);
}
