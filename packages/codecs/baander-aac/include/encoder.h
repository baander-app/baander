#ifndef BAANDER_AAC_ENCODER_H
#define BAANDER_AAC_ENCODER_H
#include <cstdint>

#include "aac.h"
#include "aac_dsp.h"
#include "aac_tables.h"
#include "bitstream.h"
#include "ics.h"
#include "mdct.h"
#include "psycho.h"
#ifdef __cplusplus
extern "C" {
#endif

/* Samples of lookahead and transform overlap before the first input sample
 * appears in the decoded output. */
#define AAC_ENCODER_DELAY 2048
/* One raw_data_block of up to 6144 bits per channel plus the ADTS header. */
#define AAC_ENCODER_MAX_FRAME_BYTES (2 * 6144 / 8 + AAC_ADTS_HEADER_SIZE)

using AacEncChannel = struct AacEncChannel_ {
  /* Three blocks of input at full scale 32768: the frame being coded spans
   * the first two, the third is lookahead for block switching. */
  float pcm[3 * AAC_FRAME_SIZE_LONG];
  AacIcsInfo ics;
  int prev_window_shape;
  float coef[1024]; /* MDCT coefficients, [window * 128 + k] for short windows */
  float x34[1024];  /* |coef|^(3/4) */
  /* Per group band, indexed group * num_swb + sfb. */
  float energy[AAC_MAX_BANDS];
  float thr[AAC_MAX_BANDS];      /* masking threshold */
  float spread[AAC_MAX_BANDS];   /* energy spread in from neighbouring bands */
  float min_snr[AAC_MAX_BANDS];  /* largest allowed noise-to-energy ratio */
  float allowed[AAC_MAX_BANDS];  /* noise energy allowed by the rate loop */
  int avoid_hole[AAC_MAX_BANDS]; /* the band should not be left out */
  int sf[AAC_MAX_BANDS];
  int cb[AAC_MAX_BANDS];
  int quant[1024];
  int global_gain;
  /* TNS filter of a long window (ISO/IEC 14496-3 4.6.9): one upward filter
   * from tns_start_band to the top of the TNS range. */
  int tns_present;
  int tns_order;
  int tns_coef[12]; /* quantized reflection coefficients, 4-bit two's complement */
  AacPsyChannel psy;
};

using AacEncoderState = struct AacEncoderState_ {
  int sample_rate, channels, bitrate, quality, frame_size, rate_index;
  AacObjectType aot;
  AacRateControl rc_mode;
  AacEncChannel ch[2];
  int ms_used[AAC_MAX_BANDS];
  int max_sfb;
  int bandwidth;                             /* lowpass cutoff in Hz */
  int active_bands_long, active_bands_short; /* bands below the cutoff */
  int tns_start_band;                        /* first long band TNS filters */
  float min_snr_long[AAC_MAX_SFB_LONG];      /* bitrate-dependent, per band */
  float min_snr_short[AAC_MAX_SFB_SHORT];
  int avg_frame_bits; /* bits per frame at the nominal bitrate, ADTS included */
  int reservoir;      /* unspent bits available to later frames */
  int reservoir_max;
  float pe_min, pe_max; /* running range of the frames' perceptual entropy */
  int pending_flush;    /* frames the flush still has to emit */
  AacMdct mdct_long, mdct_short;
  const AacDSP* dsp;
  uint8_t output_buf[AAC_ENCODER_MAX_FRAME_BYTES];
};

AacEncoderState* aac_encoder_state_create(int sr, int ch, int br, AacObjectType aot,
                                          AacRateControl rc, const AacDSP* dsp);
void aac_encoder_state_destroy(AacEncoderState* s);

/* Consumes one block of frame_size interleaved samples (NULL for silence)
 * and writes the next ADTS frame to s->output_buf.  Returns its length in
 * bytes or a negative AacError. */
int aac_encode_frame_internal(AacEncoderState* s, const float* pcm);

#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_ENCODER_H */
