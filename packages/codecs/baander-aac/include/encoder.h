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

#define AAC_ENC_TNS_MAX_ORDER 12
#define AAC_ENC_TNS_MAX_FILTERS 2

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
  /* Bands taken out of quantization by a coding tool: 0, AAC_CB_NOISE,
   * AAC_CB_INTENSITY or AAC_CB_INTENSITY2, with the noise energy or
   * intensity position in tool_sf. */
  int tool[AAC_MAX_BANDS];
  int tool_sf[AAC_MAX_BANDS];
  int pns_flag[AAC_MAX_BANDS];     /* noise substitution candidates */
  float pns_energy[AAC_MAX_BANDS]; /* band energy before stereo processing */
  int sf[AAC_MAX_BANDS];
  int cb[AAC_MAX_BANDS];
  int quant[1024];
  int global_gain;
  /* TNS filters (ISO/IEC 14496-3 4.6.9) per window: one window for long
   * frames with up to two filters (upper and lower range), eight windows
   * with one filter each for short frames. */
  int tns_present;
  int tns_coef_res; /* 1: 4-bit coefficients, 0: 3-bit */
  int tns_n_filt[8];
  int tns_order[8][AAC_ENC_TNS_MAX_FILTERS];
  int tns_length[8][AAC_ENC_TNS_MAX_FILTERS]; /* in scalefactor bands */
  int tns_coef[8][AAC_ENC_TNS_MAX_FILTERS][AAC_ENC_TNS_MAX_ORDER];
  int tns_merged; /* a long frame's two filters were merged into one */
  float tns_gain; /* prediction gain of the upper filter (long frames) */
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
  float min_snr_long[AAC_MAX_SFB_LONG];      /* bitrate-dependent, per band */
  float min_snr_short[AAC_MAX_SFB_SHORT];
  /* TNS configuration: the analysed range per window type is
   * [lo_band, top) with the upper filter starting at hi_band. */
  int tns_order_long, tns_order_short;
  int tns_lo_band_long, tns_hi_band_long, tns_top_long;
  int tns_hi_band_short, tns_top_short;
  /* PNS configuration (libfdk-aac pnsparam.cpp, AAC-LC rows). */
  int use_pns;
  int pns_start_band;
  int pns_min_width;
  float pns_ref_tonality;
  float pns_tns_gain_thr;
  float pns_pow_dist[AAC_MAX_SFB_LONG + 1];
  int allow_is;       /* intensity stereo permitted at this bitrate and bandwidth */
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
