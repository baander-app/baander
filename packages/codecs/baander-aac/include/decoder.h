#ifndef BAANDER_AAC_DECODER_H
#define BAANDER_AAC_DECODER_H
#include "aac.h"
#include "aac_dsp.h"
#include "aac_tables.h"
#include "bitstream.h"
#include "channel_map.h"
#include "conceal.h"
#include "ics.h"
#include "limiter.h"
#include "mdct.h"
#include "sbr.h"
#ifdef __cplusplus
extern "C" {
#endif

#define AAC_TNS_MAX_ORDER 20

/* The decoder conceals damaged and lost frames (aac_decoder_conceal_block,
 * conceal_mode). */
#define BAAC_AAC_HAVE_CONCEAL 1

using AacTnsData = struct AacTnsData_ {
  int n_filt[8];
  int length[8][4];
  int order[8][4];
  int direction[8][4];
  float coef[8][4][AAC_TNS_MAX_ORDER];
};

/* One individual_channel_stream as parsed from the current frame. */
using AacChannelStream = struct AacChannelStream_ {
  AacIcsInfo ics;
  int band_type[AAC_MAX_BANDS]; /* [group * max_sfb + sfb] */
  int sf[AAC_MAX_BANDS];        /* scalefactor, noise energy or intensity position */
  int quant[1024];              /* quantized values, [window * 128 + k] for short windows */
  float coef[1024];             /* dequantized spectrum */
  int tns_present;
  AacTnsData tns;
};

using AacDecoderChannel = struct AacDecoderChannel_ {
  AacChannelStream cs;
  float overlap[1024];   /* second half of the previous windowed IMDCT */
  int prev_window_shape; /* window_shape of the previous frame */
  float output[2048];    /* decoded PCM of the current frame, full scale = 1.0;
                            2048 samples while SBR is decoded */
  AacConcealChannel conceal; /* the last good spectrum and the state of a fade */
};

/* coupling_channel_element() (Table 4.8): the coupling channel and the
 * gains applied to each target channel. */
#define AAC_CCE_MAX_TARGETS 8
#define AAC_CCE_MAX_GAINS 16

using AacCoupling = struct AacCoupling_ {
  int ind_sw;      /* independently switched: added to the time signal */
  int domain;      /* dependent coupling: 0 before TNS, 1 after TNS */
  int num_coupled; /* target elements */
  int target_type[AAC_CCE_MAX_TARGETS];
  int target_tag[AAC_CCE_MAX_TARGETS];
  int ch_select[AAC_CCE_MAX_TARGETS]; /* CPE targets: bit 1 left, bit 0 right */
  int num_gains;
  float gain[AAC_CCE_MAX_GAINS][AAC_MAX_BANDS]; /* per band; [c][0] when independent */
};

using AacDecoderCce = struct AacDecoderCce_ {
  AacDecoderChannel ch;
  AacCoupling coup;
  int tag;
  int present;
};

using AacDecoderState = struct AacDecoderState_ {
  int sample_rate;    /* sample rate of the stream */
  int channels;       /* output channels requested by the caller, 0 for the stream's */
  int rate_index;     /* sampling_frequency_index of the stream */
  int frame_size;     /* samples per block in the output: frame_length, or twice it while SBR is decoded */
  int frame_length;   /* 1024 or 960 samples per raw data block of the core */
  AacLayout layout;   /* element to channel assignment */
  int layout_source;  /* 0 none yet, 1 channel configuration, 2 PCE, 3 taken from the elements */
  int elem_base[AAC_MAX_ELEMENTS]; /* first channel of each layout element in ch[] */
  int elem_present[AAC_MAX_ELEMENTS];
  AacDecoderChannel ch[AAC_MAX_CHANNELS]; /* in layout element order */
  AacDecoderCce cce[AAC_MAX_CCE];
  int stream_channels; /* channels carried by the last decoded frame */
  int has_sbr, has_ps; /* the last frame carried SBR / PS extension data */
  /* SBR: once a frame carries SBR data the stream is decoded at twice the
   * core rate until a reset, every element through its own SBR state (an
   * element without SBR data is only upsampled). */
  int sbr_mode;
  AacSbrState* sbr[AAC_MAX_ELEMENTS]; /* allocated on first use */
  /* PS: once the single channel element of a mono stream has carried
   * parametric stereo data the output is a stereo pair (ch[1] holds the
   * right channel) until a reset. */
  int ps_output;
  AacMdct mdct_long, mdct_short;
  unsigned random_state; /* PNS noise generator */
  const AacDSP* dsp;
  /* Peak limiter on the output, configured by aac_decoder_set_limiter and
   * (re)initialised by aac_decoder_apply_limiter when the output channel
   * count or rate changes. */
  int limiter_enabled;
  int limiter_attack_ms, limiter_release_ms;
  int limiter_ready; /* the limiter state matches limiter_channels and limiter_rate */
  int limiter_channels, limiter_rate;
  AacLimiter limiter;
  /* Error concealment (AacConcealment): a block that fails to parse, or
   * a lost one (aac_decoder_conceal_block), is replaced by concealed
   * audio and counted instead of returning an error. */
  int conceal_mode;
  int last_concealed;      /* the last block was concealed */
  int concealed_frames;    /* concealed blocks since creation or reset */
  unsigned conceal_random; /* noise generator of the concealment, apart from PNS */
  /* Scratch for the recovery crossfade: the concealed continuation that
   * the first good frame after a fade is blended from. */
  AacDecoderChannel recover[2];
};

AacDecoderState* aac_decoder_state_create(int sr, int ch, const AacDSP* dsp);
void aac_decoder_state_destroy(AacDecoderState* s);

/* Decodes one raw_data_block() (ISO/IEC 14496-3 Table 4.3) and leaves the
 * PCM of each channel in ch[].output, in layout element order.  Without a
 * layout the elements of this block define one.  Returns the number of
 * channels of the layout or a negative AacError. */
int aac_decode_raw_data_block(AacDecoderState* s, AacBitReader* r);

/* Produces one frame of concealed output in ch[].output for a lost block,
 * as aac_decode_raw_data_block leaves it (SBR and PS included, so that
 * the frame size and rate stay consistent), and counts it.  Returns the
 * number of channels of the layout, or AAC_ERR_STATE while no layout is
 * known. */
int aac_decoder_conceal_block(AacDecoderState* s);

/* Notes a lost or failed block without producing output (concealment
 * off): the SBR and PS states then clamp the delta-time coded values of
 * the next frames, which refer to the lost data, instead of refusing
 * them. */
void aac_decoder_note_lost_block(AacDecoderState* s);

/* Selects the sampling_frequency_index used for band tables, resetting the
 * filterbank state when it changes; returns AAC_ERR_UNSUPPORTED for an
 * index without tables. */
int aac_decoder_set_rate_index(AacDecoderState* s, int rate_index);

/* Selects the frame length (1024 or 960 samples per raw data block),
 * resetting the filterbank state when it changes; returns
 * AAC_ERR_INVALID_ARG for any other length. */
int aac_decoder_set_frame_length_internal(AacDecoderState* s, int frame_length);

/* Selects the layout of a channel configuration (1..7, 11, 12), resetting
 * state when it differs from the current one; 0 keeps a PCE-defined or
 * element-defined layout. */
int aac_decoder_set_channel_config(AacDecoderState* s, int channel_config);

/* Clears the filterbank overlap, window-shape history and concealment
 * state of every channel and the concealment counters. */
void aac_decoder_reset_state(AacDecoderState* s);

/* Mixes the decoded frame (ch[].output) into `out` with the requested
 * output channel count; returns that count. */
int aac_decoder_output(const AacDecoderState* s, float* out);

/* Output channels for the current layout and request. */
int aac_decoder_output_channels(const AacDecoderState* s);

/* Runs the output limiter, when enabled, over `frames` interleaved frames
 * of `channels` channels that aac_decoder_output produced, in place.  The
 * limiter restarts when the channel count or the output rate changes. */
void aac_decoder_apply_limiter(AacDecoderState* s, float* pcm, int frames, int channels);

/* Delay the limiter adds to the output, in frames at the output rate. */
int aac_decoder_limiter_delay(const AacDecoderState* s);

#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_DECODER_H */
