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
};
void aac_psycho_init(AacPsychoState* s, int sr, int fs);
void aac_psycho_analyze(AacPsychoState* s, const float* mdct, int nb, const int* sfb);
float aac_psycho_get_pe(const AacPsychoState* s);
const float* aac_psycho_get_thresholds(const AacPsychoState* s);
#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_PSYCHO_H */
