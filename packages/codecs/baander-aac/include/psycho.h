#ifndef BAANDER_AAC_PSYCHO_H
#define BAANDER_AAC_PSYCHO_H
#include <cstdint>
#ifdef __cplusplus
extern "C" {
#endif
using AacPsychoBand = struct AacPsychoBand_ {
  float energy, threshold, pe, tonality, spreaded_energy;
};
using AacPsychoState = struct AacPsychoState_ {
  int sample_rate, rate_index, frame_size, num_bands;
  float prev_energy[49];
  float spreading[49][49];
  AacPsychoBand bands[49];
  float thresholds[49]; /* dedicated threshold array for encoder */
  float total_pe;
  float threshold_previous[49];
  float preecho_factor;
  /* Multiplier applied to the initial -29 dB SNR threshold.  Values < 1
   * demand lower quantization noise (finer coding, more bits); > 1 allows
   * more noise (coarser coding, fewer bits).  The encoder sets this from the
   * target bitrate so quality scales with the available bit budget, like the
   * "reduction of psychoacoustic requirements" in 3GPP TS 26.403 §5.6.1. */
  float threshold_scale;
};
void aac_psycho_init(AacPsychoState* s, int sr, int fs);
void aac_psycho_analyze(AacPsychoState* s, const float* mdct, int nb, const int* sfb);
float aac_psycho_get_pe(const AacPsychoState* s);
const float* aac_psycho_get_thresholds(const AacPsychoState* s);
#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_PSYCHO_H */
