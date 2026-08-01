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
  int max_sfb;
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
  /* CPE state: M/S mask decoded from the shared channel_pair_element header.
   * Populated when common_window == 1 and applied after both channels'
   * spectral data has been decoded. */
  int ms_mask[49];
  int ms_mask_present;
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
