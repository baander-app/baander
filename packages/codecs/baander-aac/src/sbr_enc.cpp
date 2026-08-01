#include <cmath>

#include "sbr.h"

void aac_sbr_encode(AacSbrEnvelope* env, AacSbrQmf*  /*qmf*/, const float* core_low, int core_bands,
                    int sbr_bands) {
  for (int ts = 0; ts < env->time_slots; ts++) {
    for (int band = 0; band < env->freq_bands_hi; band++) {
      float energy = 0;
      int k = core_bands + band;
      if (k < core_bands + sbr_bands) { energy = core_low[k] * core_low[k];
}
      env->envelope[ts][band] = 10.0f * log10f(energy + 1e-10f);
      env->noise_floor[ts][band] = -60.0f;
    }
  }
}
