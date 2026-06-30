#ifndef BAANDER_AAC_ENCODER_H
#define BAANDER_AAC_ENCODER_H
#include "aac.h"
#include "aac_dsp.h"
#include "aac_tables.h"
#include "bitstream.h"
#include "mdct.h"
#include "psycho.h"
#ifdef __cplusplus
extern "C" {
#endif
using AacEncoderState = struct AacEncoderState_ {
  int sample_rate, channels, bitrate, quality, frame_size, rate_index;
  AacObjectType aot;
  AacRateControl rc_mode;
  AacMdctContext mdct_ctx[2];
  AacPsychoState psycho_state[2];
  const AacDSP* dsp;
  float lambda;
  int bit_reservoir, target_bits_per_frame;
  float spectral[2][1024];
  int scalefactors[2][49];
  int codebooks[2][49];
  int ms_used[49];
  int quant_coeffs[2][1024]; /* quantized spectral coefficients */
  AacBitWriter writer;
  uint8_t output_buf[8192];
  float pcm_buf[2][2048];
  int pcm_buf_fill;
};
AacEncoderState* aac_encoder_state_create(int sr, int ch, int br, AacObjectType aot,
                                          AacRateControl rc, const AacDSP* dsp);
void aac_encoder_state_destroy(AacEncoderState* s);
int aac_encode_frame_internal(AacEncoderState* s, const float* pcm, int n_samples);
int aac_quantize_bands(AacEncoderState* s, int ch, float lambda, const float* thr, const int* sfb,
                       int nb);
float aac_rate_control_lambda(AacEncoderState* s, int bits_used, int bits_target);
#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_ENCODER_H */
