#ifndef BAANDER_AAC_ENCODER_H
#define BAANDER_AAC_ENCODER_H
#include <cstdint>

#include "aac.h"
#include "aac_dsp.h"
#include "aac_tables.h"
#include "bitstream.h"
#include "channel_map.h"
#include "drc.h"
#include "ics.h"
#include "lowdelay.h"
#include "mdct.h"
#include "psycho.h"
#include "sbr_enc.h"
#include "sbr_ld_enc.h"
#ifdef __cplusplus
extern "C" {
#endif

/* Samples of lookahead and transform overlap before the first input sample
 * appears in the decoded output of an AAC-LC stream: two frames, 2048 for
 * 1024-sample frames and 1920 for 960-sample frames.  An xHE-AAC stream
 * without SBR has the same delay; with SBR at 2:1 it has HE-AAC's. */
#define AAC_ENCODER_DELAY 2048
#define AAC_ENCODER_DELAY_960 1920
/* The same for an HE-AAC stream, in samples at the full rate: the core
 * delay at half rate, the encoder's QMF analysis and synthesis and the
 * decoder's QMF chain, measured against the FFmpeg decoder for 1024
 * frames; the QMF chains add the same 1539 samples to the core delay of
 * 960 frames, measured against our decoder. */
#define AAC_ENCODER_SBR_DELAY 5635
#define AAC_ENCODER_SBR_DELAY_960 5379
/* AAC-LD keeps the block of lookahead of AAC-LC: one frame plus the
 * window overlap of one frame, 1024 (960) samples.  AAC-ELD: one frame
 * plus the low-delay filterbank's delay of half a frame, 768 (720). */
#define AAC_ENCODER_LD_DELAY(frame_length) (2 * (frame_length))
#define AAC_ENCODER_ELD_DELAY(frame_length) (3 * (frame_length) / 2)
/* AAC-ELD with LD-SBR, in input samples: the core's delay at the core
 * rate, the encoder's CLDFB analysis and core synthesis
 * (aac_sbr_ld_enc_delay) and the decoder's CLDFB analysis and synthesis
 * (aac_sbr_ld_delay: AAC_CLDFB_DELAY + 1 at the doubled rate, half that at
 * the core rate). */
#define AAC_ENCODER_ELD_SBR_DELAY(frame_length) \
  (2 * AAC_ENCODER_ELD_DELAY(frame_length) + 2 * AAC_CLDFB_DELAY + 1)
#define AAC_ENCODER_ELD_SBR_SINGLE_DELAY(frame_length) \
  (AAC_ENCODER_ELD_DELAY(frame_length) + AAC_CLDFB_DELAY + 1)
/* One raw_data_block of up to 6144 bits per channel plus the ADTS header. */
#define AAC_ENCODER_MAX_FRAME_BYTES (AAC_MAX_CHANNELS * 6144 / 8 + AAC_ADTS_HEADER_SIZE)
/* The output buffer holds that, or the largest xHE-AAC immediate playout
 * frame: a stereo pair with SBR and MPS 212 data with its two pre-roll
 * access units and the embedded configuration (usac_enc.h), which is
 * the larger of the two. */
#define AAC_ENCODER_OUTPUT_BUF_BYTES \
  (3 * (2 * 6144 / 8 + 2 * AAC_SBR_ENC_MAX_BYTES + 128) + 1024 + 64 + AAC_ADTS_HEADER_SIZE)
/* The extension_payload() an AAC-ELD frame carries (ELD v2's spatial frame). */
#define AAC_ENCODER_MAX_EXT_PAYLOAD_BYTES 1024

#define AAC_ENC_TNS_MAX_ORDER 12
#define AAC_ENC_TNS_MAX_FILTERS 2

/* Whether an object type codes SBR (HE-AAC v1 or v2). */
static inline int aac_encoder_aot_has_sbr(int aot) {
  return aot == AAC_AOT_SBR || aot == AAC_AOT_PS;
}
/* Whether an object type is one of the low-delay ones (AAC-LD, AAC-ELD). */
static inline int aac_encoder_aot_low_delay(int aot) {
  return aot == AAC_AOT_ER_LD || aot == AAC_AOT_ER_ELD;
}

/* The window sequence of USAC that follows short windows and precedes
 * short windows (a long window with a short slope on both sides, ISO/IEC
 * 23003-3 7.9), which the bitstream signals as LONG_START.  The AAC
 * object types never use it. */
#define AAC_ENC_WIN_STOP_START 4

using AacEncChannel = struct AacEncChannel_ {
  /* Three blocks of frame_length input samples at full scale 32768: the
   * frame being coded spans the first two, the third is lookahead for
   * block switching.  AAC-ELD keeps four blocks of 512 or 480: its
   * analysis window spans the first three, the fourth is the lookahead. */
  float pcm[3 * AAC_FRAME_SIZE_LONG];
  AacIcsInfo ics;
  int prev_window_shape;
  int is_lfe;                     /* the channel of an LFE element */
  int active_long, active_short;  /* bands below the channel's lowpass */
  int solid_long, solid_short;    /* bands below the full-precision lowpass;
                                     the bands from there to active_* are
                                     coded coarsely or not at all */
  float coef[1024]; /* MDCT coefficients, [window * 128 + k] for short windows */
  float x34[1024];  /* |coef|^(3/4) */
  float form[AAC_MAX_BANDS]; /* sum of sqrt(|coef|) per group band */
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
  int frame_length; /* core samples per frame: 1024 or 960; 512 or 480 for AAC-LD/ELD */
  int input_rate, input_channels;
  int delay;   /* input samples before the first one is decoded */
  int started; /* a frame has been encoded since creation or the last flush */
  AacObjectType aot;
  AacRateControl rc_mode;
  int afterburner; /* second quantization pass after the rate loop (afterburner.cpp) */
  AacEncChannel ch[AAC_MAX_CHANNELS];
  AacLayout layout;
  AacEncElement elem[AAC_MAX_ELEMENTS];
  int num_elements;
  int channel_config; /* of the ADTS header: 0 when a PCE carries the layout */
  AacChannelMode channel_mode; /* the 7.1 layout of an 8-channel input */
  int mpeg2;                   /* MPEG-2 AAC: ADTS id 1, no noise substitution */
  int bandwidth;                             /* lowpass cutoff in Hz */
  int active_bands_long, active_bands_short; /* bands below the cutoff */
  float min_snr_long[AAC_MAX_SFB_LONG];      /* bitrate-dependent, per band */
  float min_snr_short[AAC_MAX_SFB_SHORT];
  /* TNS configuration: the analysed range per window type is
   * [lo_band, top) with the upper filter starting at hi_band. */
  int tns_order_long, tns_order_short;
  int tns_lo_band_long, tns_hi_band_long, tns_top_long;
  int tns_hi_band_short, tns_top_short;
  /* A filter is used when its prediction gain exceeds tns_gain_on, or,
   * with tns_coef_trigger, when its quantized coefficients are large. */
  float tns_gain_on;
  int tns_coef_trigger;
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
  /* Threshold reduction the rate loop settled on in the last frame, the
   * starting point of the next frame's search (0: none yet). */
  float last_reduction;
  /* Tuning overrides (aac_encoder_set_peak_bitrate, aac_encoder_set_bandwidth,
   * aac_encoder_set_vbr_mode): a hard cap on any frame's bits in bits per
   * second (0: none), the lowpass asked for in Hz (0: the tuning table),
   * and the VBR mode in force (0: none).  The bitrate and rate control
   * given at creation come back when the VBR mode is cleared.  A quality
   * set while a mode is in force (0: none) outlasts the mode's own when
   * a peak bitrate lowers the mode. */
  int peak_bitrate;
  int bandwidth_override;
  int vbr_mode;
  int vbr_quality_override;
  int base_bitrate, base_quality;
  AacRateControl base_rc;
  int tools_bitrate; /* the bitrate the SBR encoders were tuned for */
  float pe_min, pe_max; /* running range of the frames' perceptual entropy */
  int pending_flush;    /* frames the flush still has to emit */
  /* HE-AAC, and xHE-AAC with SBR: the SBR encoder of each SCE or CPE,
   * NULL for LFE and AAC-LC */
  AacSbrEncoder* sbr[AAC_MAX_ELEMENTS];
  AacSbrDownsampler* lfe_down[AAC_MAX_ELEMENTS]; /* HE-AAC: the LFE's half-rate signal */
  /* AAC-ELD with LD-SBR (aac_encoder_set_ld_sbr): one low-delay SBR
   * encoder per element; an LFE's only resamples its signal to the core
   * rate through the same filterbanks and writes no data.  With dual rate
   * the core runs at half the input rate. */
  int ld_sbr;
  int ld_sbr_dual_rate;
  int ld_sbr_crc; /* a 10-bit CRC in front of every element's sbr_data() */
  AacSbrLdEncoder* ld_sbr_enc[AAC_MAX_ELEMENTS];
  /* AAC-ELD: the extension_payload() of the next frame (ELD v2's
   * MPEG Surround payload), written once after the elements */
  uint8_t ext_payload[AAC_ENCODER_MAX_EXT_PAYLOAD_BYTES];
  int ext_payload_bits;
  float sbr_in[AAC_MAX_CHANNELS][2 * AAC_FRAME_SIZE_LONG]; /* full-rate input */
  float core_in[AAC_MAX_CHANNELS][AAC_FRAME_SIZE_LONG];    /* half-rate core signal */
  AacMdct mdct_long, mdct_short;
  const AacDSP* dsp;
  uint8_t output_buf[AAC_ENCODER_OUTPUT_BUF_BYTES];
  /* Element boundaries of the raw data block in output_buf (bit offsets
   * from the block's start), for the ADTS CRC of the transport layer. */
  AacCrcRegion crc_region[AAC_CRC_MAX_REGIONS];
  int num_crc_regions;
  /* Transport, signalling and CRC settings, owned by the transport layer. */
  struct AacEncoderTransport_* transport;
  /* Dynamic range control metadata (aac_encoder_set_metadata). */
  AacDrcEncoder drc;
  /* USAC: the frame writer's state (usac_enc.h), NULL for the AAC types. */
  struct AacUsacEncoder_* usac;
  /* The loudness meter of aac_encoder_set_loudness_measurement, fed with
   * every input frame; NULL while none runs.  Owned by the API layer. */
  struct AacLoudnessMeter_* loudness;
};

/* `sr` and `ch` are the input's; HE-AAC v2 takes a stereo input only. */
AacEncoderState* aac_encoder_state_create(int sr, int ch, int br, AacObjectType aot,
                                          AacRateControl rc, const AacDSP* dsp);
void aac_encoder_state_destroy(AacEncoderState* s);

/* Rebuilds the layout and its elements for a channel mode, before any
 * frame is encoded: AAC_ERR_INVALID_ARG when the mode does not fit the
 * channel count. */
int aac_encoder_state_set_channel_mode(AacEncoderState* s, AacChannelMode mode);

/* Re-derives every setting that depends on the bitrate and quality:
 * lowpass, active bands, minimum SNR tables and tool thresholds. */
void aac_encoder_state_configure(AacEncoderState* s);

/* Returns the encoder to its state after creation: history, filterbank
 * overlap, reservoir and psychoacoustic memory. */
void aac_encoder_state_reset(AacEncoderState* s);

/* Switches to 1024 or 960 core samples per frame (512 or 480 for the
 * low-delay object types; frame_size and delay follow) and resets the
 * encoder; AAC_ERR_INVALID_ARG for any other length. */
int aac_encoder_state_set_frame_length(AacEncoderState* s, int frame_length);

/* AAC-ELD: switches low-delay SBR on or off (with the core at half the
 * input rate when `dual_rate` is set) and resets the encoder; frame_size
 * and delay follow.  AAC_ERR_INVALID_ARG for another object type,
 * AAC_ERR_UNSUPPORTED when the core rate has no low-delay band tables. */
int aac_encoder_state_set_ld_sbr(AacEncoderState* s, int enabled, int dual_rate);

/* The sbr_header() count of the layout's ld_sbr_header(): one per SCE
 * or CPE. */
int aac_encoder_ld_sbr_header_count(const AacEncoderState* s);

/* Largest ADTS frame the encoder can emit for its channel count. */
int aac_encoder_state_max_frame_bytes(const AacEncoderState* s);

/* Most bits one frame may take as the rate control counts them (the raw
 * data block with the ADTS header's bits): the AAC limit per channel, or
 * the share of the peak bitrate per frame when one is set, whichever is
 * smaller.  Rounded down to whole bytes, so the written frame never
 * exceeds the share. */
int aac_encoder_state_max_frame_bits(const AacEncoderState* s);

/* Sets the peak bitrate (0: none) before any frame is encoded: the
 * per-frame limit and the reservoir's size follow it, and a VBR mode in
 * force is chosen again under it.  The encoder is reset. */
int aac_encoder_state_set_peak_bitrate(AacEncoderState* s, int bps);

/* Changes the nominal bitrate before any frame is encoded and tunes the
 * SBR encoders, the budget and every bitrate-dependent setting to it;
 * the encoder is reset.  AAC_ERR_INIT when the SBR tuning table has no
 * row for it. */
int aac_encoder_state_set_bitrate(AacEncoderState* s, int bitrate);

/* Puts a VBR mode 1..5 in force (0 clears it): the mode's bitrate target
 * for the input's channel count becomes the nominal bitrate, whose rate
 * loop bounds frames coded at the mode's quality, and a peak bitrate
 * below the target lowers the mode.  Before any frame is encoded;
 * AAC_ERR_INVALID_ARG for another mode. */
int aac_encoder_state_set_vbr_mode(AacEncoderState* s, int mode);

/* The bitrate target of a VBR mode for a channel count, in bits per
 * second: the stereo target scaled per channel. */
int aac_encoder_vbr_mode_bitrate(int mode, int channels);

/* Bits an AAC-ELD frame can give an extension payload next to its
 * channels: the frame's bit budget less what the core keeps. */
int aac_encoder_state_extension_budget_bits(const AacEncoderState* s);

/* Consumes one block of frame_size interleaved input samples (NULL for silence)
 * and writes the next ADTS frame to s->output_buf.  Returns its length in
 * bytes or a negative AacError. */
int aac_encode_frame_internal(AacEncoderState* s, const float* pcm);

#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_ENCODER_H */
