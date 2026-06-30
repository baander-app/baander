#ifndef BAANDER_AAC_DECODER_H
#define BAANDER_AAC_DECODER_H
#include "aac.h"
#include "aac_dsp.h"
#include "aac_tables.h"
#include "bitstream.h"
#include "mdct.h"
#ifdef __cplusplus
extern "C" {
#endif
using AacDecoderChannel = struct AacDecoderChannel_ {
  float spectral[1024];
  float output[2048];
  int scalefactors[49];
  int sfb_cb[49];
  AacWindowSequence win_seq;
  AacWindowShape win_shape;
  AacMdctContext mdct_ctx;
  int tns_ncoef[8];
  float tns_lpc[8][20];
  int tns_present;
  int has_sbr, has_ps;
};
using AacDecoderState = struct AacDecoderState_ {
  int sample_rate, channels, aot, rate_index, frame_size;
  AacDecoderChannel ch[2];
  const AacDSP* dsp;
};
AacDecoderState* aac_decoder_state_create(int sr, int ch, const AacDSP* dsp);
void aac_decoder_state_destroy(AacDecoderState* s);
int aac_decode_sce(AacDecoderState* s, AacBitReader* r, int ch);
int aac_decode_cpe(AacDecoderState* s, AacBitReader* r);
void aac_dequantize(AacDecoderChannel* ch, int ri, int frame_size);
void aac_apply_tns(AacDecoderChannel* ch, int ri, int frame_size);
#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_DECODER_H */
