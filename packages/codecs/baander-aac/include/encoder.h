#ifndef BAANDER_AAC_ENCODER_H
#define BAANDER_AAC_ENCODER_H
#include <cstdint>

#include "aac.h"
#include "aac_dsp.h"
#include "aac_tables.h"
#include "bitstream.h"
#include "channel_map.h"
#include "ics.h"
#include "mdct.h"
#include "psycho.h"
#include "sbr_enc.h"
#ifdef __cplusplus
extern "C" {
#endif

/* Samples of lookahead and transform overlap before the first input sample
 * appears in the decoded output of an AAC-LC stream. */
#define AAC_ENCODER_DELAY 2048
/* The same for an HE-AAC stream, in samples at the full rate: the core
 * delay at half rate, the encoder's QMF analysis and synthesis and the
 * decoder's QMF chain, measured against the FFmpeg decoder. */
#define AAC_ENCODER_SBR_DELAY 5635
/* One raw_data_block of up to 6144 bits per channel plus the ADTS header. */
#define AAC_ENCODER_MAX_FRAME_BYTES (AAC_MAX_CHANNELS * 6144 / 8 + AAC_ADTS_HEADER_SIZE)

#define AAC_ENC_TNS_MAX_ORDER 12
#define AAC_ENC_TNS_MAX_FILTERS 2

using AacEncChannel = struct AacEncChannel_ {
  /* Three blocks of input at full scale 32768: the frame being coded spans
   * the first two, the third is lookahead for block switching. */
  float pcm[3 * AAC_FRAME_SIZE_LONG];
  AacIcsInfo ics;
  int prev_window_shape;
  int is_lfe;                     /* the channel of an LFE element */
  int active_long, active_short;  /* bands below the channel's lowpass */
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

/* One syntactic element of the raw data block and the input channels it
 * codes (indices into the interleaved input, in the canonical order). */
using AacEncElement = struct AacEncElement_ {
  int type; /* AAC_ELEM_SCE, AAC_ELEM_CPE or AAC_ELEM_LFE */
  int tag;
  int num_ch;
  int ch[2];
  int ms_used[AAC_MAX_BANDS]; /* per group band of a CPE */
  int max_sfb;                /* of the last quantization */
};

using AacEncoderState = struct AacEncoderState_ {
  /* sample_rate and channels are those of the core coder; with SBR the
   * input runs at twice the rate, and with parametric stereo a stereo
   * input is coded as one channel. */
  int sample_rate, channels, bitrate, quality, frame_size, rate_index;
  int input_rate, input_channels;
  int delay; /* input samples before the first one is decoded */
  AacObjectType aot;
  AacRateControl rc_mode;
  AacEncChannel ch[AAC_MAX_CHANNELS];
  AacLayout layout;
  AacEncElement elem[AAC_MAX_ELEMENTS];
  int num_elements;
  int channel_config; /* of the ADTS header: 0 when a PCE carries the layout */
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
  /* HE-AAC: the SBR encoder of each SCE or CPE, NULL for LFE and AAC-LC */
  AacSbrEncoder* sbr[AAC_MAX_ELEMENTS];
  AacSbrDownsampler* lfe_down[AAC_MAX_ELEMENTS]; /* HE-AAC: the LFE's half-rate signal */
  float sbr_in[AAC_MAX_CHANNELS][2 * AAC_FRAME_SIZE_LONG]; /* full-rate input */
  float core_in[AAC_MAX_CHANNELS][AAC_FRAME_SIZE_LONG];    /* half-rate core signal */
  AacMdct mdct_long, mdct_short;
  const AacDSP* dsp;
  uint8_t output_buf[AAC_ENCODER_MAX_FRAME_BYTES];
};

/* `sr` and `ch` are the input's; HE-AAC v2 takes a stereo input only. */
AacEncoderState* aac_encoder_state_create(int sr, int ch, int br, AacObjectType aot,
                                          AacRateControl rc, const AacDSP* dsp);
void aac_encoder_state_destroy(AacEncoderState* s);

/* Re-derives every setting that depends on the bitrate and quality:
 * lowpass, active bands, minimum SNR tables and tool thresholds. */
void aac_encoder_state_configure(AacEncoderState* s);

/* Returns the encoder to its state after creation: history, filterbank
 * overlap, reservoir and psychoacoustic memory. */
void aac_encoder_state_reset(AacEncoderState* s);

/* Largest ADTS frame the encoder can emit for its channel count. */
int aac_encoder_state_max_frame_bytes(const AacEncoderState* s);

/* Consumes one block of frame_size interleaved input samples (NULL for silence)
 * and writes the next ADTS frame to s->output_buf.  Returns its length in
 * bytes or a negative AacError. */
int aac_encode_frame_internal(AacEncoderState* s, const float* pcm);

#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_ENCODER_H */
