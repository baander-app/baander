#ifndef BAANDER_AAC_PSYCHO_H
#define BAANDER_AAC_PSYCHO_H

#include "ics.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Per-channel state of the psychoacoustic model. */
using AacPsyChannel = struct AacPsyChannel_ {
  float prev_thr[AAC_MAX_SFB_LONG];    /* thresholds of the previous long frame */
  float prev_energy[AAC_MAX_SFB_LONG]; /* band energies of the previous long frame */
  int prev_long;                       /* previous frame used a long window */
  int forward_masking;                 /* the previous frame masks this one */
};

/* forward_masking lets each long frame's band energies mask the next
 * frame (measured on AAC-LC and HE-AAC with 1024-sample frames). */
void aac_psy_init(AacPsyChannel* p, int forward_masking);

/* Masking analysis of one channel's MDCT coefficients (full scale 32768,
 * short windows stored as [window * 128 + k]).  Per group band, indexed
 * group * num_swb + sfb, the outputs are the band energy, the allowed
 * quantization-noise energy (raised to what the previous long frame
 * still masks) and the energy spread into the band from its
 * neighbours (3GPP TS 26.403 5.4.3), which the encoder compares with the
 * band energy to decide whether the band may be left out.  channel_bitrate
 * selects the energy spreading slope. */
void aac_psy_thresholds(AacPsyChannel* p, int sample_rate, int channel_bitrate,
                        const AacIcsInfo* ics, const float* coef, float* energy, float* thr,
                        float* spread_energy);

/* Energies of the sub-blocks of sub_len samples (one short window: 128 or
 * 120) of a high-passed signal, for the blocks covering
 * x[start .. start + count); sub_energy needs count / sub_len entries. */
void aac_psy_subblock_energies(const float* x, int start, int count, int sub_len,
                               float* sub_energy);

/* Looks for an attack in x[start .. start + count): a sub-block whose
 * high-passed energy jumps well above the smoothed energy of the preceding
 * ones.  Returns the index of the last attacking sub-block plus one, or 0
 * when there is none.  x must have three sub-blocks of history before
 * start. */
int aac_psy_attack(const float* x, int start, int count, int sub_len);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_PSYCHO_H */
