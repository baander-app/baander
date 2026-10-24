#ifndef BAANDER_AAC_USAC_H
#define BAANDER_AAC_USAC_H

#include <cstdint>

#include "aac.h"
#include "bitstream.h"
#include "channel_map.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Unified Speech and Audio Coding (ISO/IEC 23003-3), as the xHE-AAC
 * profile constrains it: the configuration (UsacConfig), the shared types
 * of the frame decoder and the contract between its modules.  The frame
 * decoder itself (usac_frame.cpp) walks the configured element list; the
 * frequency-domain core (usac_fd.h), the linear-prediction-domain core
 * (usac_lpd.h), the enhanced SBR (sbr.h, the aac_sbr_usac_* functions),
 * MPEG Surround 212 (mps212.h) and MPEG-D DRC (unidrc.h) are separate
 * modules behind the interfaces declared in their headers. */

#define AAC_USAC_MAX_ELEMENTS 16
#define AAC_USAC_MAX_EXT_CONFIG 512 /* bytes of one extension element's configuration */
#define AAC_USAC_MAX_CONFIG_EXTS 8
#define AAC_USAC_MAX_CONFIG_EXT_BYTES 512
#define AAC_USAC_MAX_CONFIG_BYTES 1024 /* the raw UsacConfig, kept to detect changes */
#define AAC_USAC_MAX_CORE_FRAME 1024   /* core samples per frame: 768 or 1024 */
#define AAC_USAC_MAX_OUTPUT_FRAME 4096 /* output samples per frame: core times the SBR ratio */
#define AAC_USAC_MAX_QMF_SLOTS 64      /* 64-band QMF slots per output frame */
#define AAC_USAC_MAX_PREROLL_FRAMES 4

/* usacElementType */
enum {
  AAC_USAC_SCE = 0,
  AAC_USAC_CPE = 1,
  AAC_USAC_LFE = 2,
  AAC_USAC_EXT = 3,
};

/* usacExtElementType */
enum {
  AAC_USAC_EXT_FILL = 0,
  AAC_USAC_EXT_MPEGS = 1,
  AAC_USAC_EXT_SAOC = 2,
  AAC_USAC_EXT_AUDIOPREROLL = 3,
  AAC_USAC_EXT_UNI_DRC = 4,
};

/* usacConfigExtType */
enum {
  AAC_USAC_CFG_EXT_FILL = 0,
  AAC_USAC_CFG_EXT_LOUDNESS_INFO = 2,
  AAC_USAC_CFG_EXT_STREAM_ID = 7,
};

/* sbrRatioIndex, from coreSbrFrameLengthIndex */
enum {
  AAC_USAC_SBR_NONE = 0,
  AAC_USAC_SBR_4_1 = 1, /* 1024 core, 4096 output */
  AAC_USAC_SBR_2_1 = 2, /* 1024 core, 2048 output */
  AAC_USAC_SBR_8_3 = 3, /* 768 core, 2048 output */
};

/* SbrDfltHeader() of an SbrConfig(): the header fields the configuration
 * carries; the fields left out carry the standard's defaults. */
using AacUsacSbrHeader = struct AacUsacSbrHeader_ {
  int start_freq, stop_freq;
  int header_extra1, header_extra2;
  int freq_scale, alter_scale, noise_bands;
  int limiter_bands, limiter_gains, interpol_freq, smoothing_mode;
};

using AacUsacSbrConfig = struct AacUsacSbrConfig_ {
  int harmonic_sbr; /* harmonicSBR: the harmonic transposer instead of the LPC patching */
  int inter_tes;    /* bs_interTes */
  int pvc;          /* bs_pvc: predictive vector coding of the envelopes */
  AacUsacSbrHeader dflt;
};

/* Mps212Config() of a CPE with stereoConfigIndex > 0. */
using AacUsacMps212Config = struct AacUsacMps212Config_ {
  int freq_res;          /* bsFreqRes */
  int fixed_gain_dmx;    /* bsFixedGainDMX */
  int temp_shape_config; /* bsTempShapeConfig */
  int decorr_config;     /* bsDecorrConfig */
  int high_rate_mode;    /* bsHighRateMode */
  int phase_coding;      /* bsPhaseCoding */
  int ott_bands_phase_present;
  int ott_bands_phase;
  int residual_coding; /* stereoConfigIndex > 1 */
  int residual_bands;  /* bsResidualBands */
  int pseudo_lr;       /* bsPseudoLr */
  int env_quant_mode;  /* bsEnvQuantMode, with bsTempShapeConfig 2 */
};

using AacUsacElementConfig = struct AacUsacElementConfig_ {
  int type; /* AAC_USAC_SCE, _CPE, _LFE or _EXT */
  /* UsacCoreConfig of an SCE or CPE (an LFE has neither tool) */
  int tw_mdct;       /* refused: the time-warped MDCT is outside xHE-AAC */
  int noise_filling; /* noiseFilling */
  AacUsacSbrConfig sbr; /* with sbrRatioIndex > 0 */
  int stereo_config_index; /* CPE: 0 none, 1 MPS 212 after SBR, 2 with residual, 3 residual with MPS 212 before SBR */
  AacUsacMps212Config mps;
  /* UsacExtElementConfig */
  int ext_type;           /* usacExtElementType */
  int ext_default_length; /* usacExtElementDefaultLength, 0 when not present */
  int ext_payload_frag;   /* usacExtElementPayloadFrag */
  int ext_config_len;
  uint8_t ext_config[AAC_USAC_MAX_EXT_CONFIG]; /* e.g. uniDrcConfig() */
};

using AacUsacConfigExt = struct AacUsacConfigExt_ {
  int type;
  int len;
  uint8_t data[AAC_USAC_MAX_CONFIG_EXT_BYTES]; /* e.g. loudnessInfoSet() */
};

using AacUsacConfig = struct AacUsacConfig_ {
  int sample_rate;        /* usacSamplingFrequency: the output rate */
  int core_sbr_frame_length_index;
  int frame_length;        /* core samples per frame: 768 or 1024 */
  int output_frame_length; /* 768, 1024, 2048 or 4096 */
  int sbr_ratio_index;     /* AAC_USAC_SBR_* */
  int core_sample_rate;    /* sample_rate divided by the SBR ratio */
  int channel_config_index;
  int num_channels; /* output channels */
  int channel_pos[AAC_MAX_CHANNELS]; /* bsOutputChannelPos per channel (channelConfigurationIndex 0) */
  int num_elements;
  AacUsacElementConfig elem[AAC_USAC_MAX_ELEMENTS];
  int num_config_exts;
  AacUsacConfigExt config_ext[AAC_USAC_MAX_CONFIG_EXTS];
  int stream_id; /* ID_CONFIG_EXT_STREAM_ID, -1 when absent */
  /* the UsacConfig() bits as read, for equality checks at an IPF */
  int raw_bits;
  uint8_t raw[AAC_USAC_MAX_CONFIG_BYTES];
};

/* escapedValue(nBits1, nBits2, nBits3) of ISO/IEC 23003-3 5.2. */
unsigned aac_usac_escaped_value(AacBitReader* r, int bits1, int bits2, int bits3);

/* Reads UsacConfig() (after the audioObjectType, samplingFrequency and
 * channelConfiguration of the AudioSpecificConfig) and derives the frame
 * lengths, the SBR ratio and the output channel count.  Returns AAC_OK,
 * AAC_ERR_DECODE for a malformed configuration or AAC_ERR_UNSUPPORTED
 * for one outside what the decoder implements (tw_mdct, SAOC, more
 * elements or channels than the limits). */
int aac_usac_config_read(AacBitReader* r, AacUsacConfig* c);

/* Whether two configurations are the same bits. */
int aac_usac_config_equal(const AacUsacConfig* a, const AacUsacConfig* b);

/* The layout (element to channel assignment) a configuration implies. */
int aac_usac_config_layout(const AacUsacConfig* c, AacLayout* layout);

/* One frame of the 64-band complex QMF domain, as the enhanced SBR hands
 * it to MPEG Surround 212 and takes it back: `slots` is
 * output_frame_length / 64, x[slot][band] = {re, im}, in the full scale
 * of 32768 the QMF banks use. */
using AacUsacQmfFrame = struct AacUsacQmfFrame_ {
  int slots;
  float x[AAC_USAC_MAX_QMF_SLOTS][64][2];
};

/* ── The core channel contract between the FD and LPD modules ───────
 *
 * Every SCE, CPE channel and LFE owns one AacUsacCoreChannel.  Both core
 * modules read and write it:
 *
 * - `overlap` holds, after an FD frame, the windowed second half of that
 *   frame's IMDCT output (frame_length samples), which the next FD frame
 *   adds to the first half of its own windowed IMDCT output.  After an
 *   LPD frame the LPD module leaves in `overlap` its contribution to the
 *   first frame_length samples of the following frame (the synthesis tail
 *   and zero-input response), so that an FD frame after LPD completes the
 *   transition by adding the forward aliasing cancellation it carries
 *   (aac_usac_lpd_fac_to_fd, usac_lpd.h) and then overlap-adding as
 *   usual, with the short left window slope.  An LPD frame after FD reads
 *   `overlap` as the FD tail it must continue.
 * - `prev_core_mode` says which module wrote `overlap` (0 FD, 1 LPD).
 * - `prev_window_sequence` and `prev_window_shape` are the previous FD
 *   frame's, as the FD module needs them for the window transitions.
 *
 * The per-module memories (arithmetic coder context, TNS and noise
 * filling state, LPC and excitation history) are the modules' own
 * states, created with the channel. */
struct AacUsacFdChannel_;
struct AacUsacLpdChannel_;

using AacUsacCoreChannel = struct AacUsacCoreChannel_ {
  int prev_core_mode;      /* 0 FD, 1 LPD; -1 before the first frame */
  int prev_window_sequence; /* of the last FD frame */
  int prev_window_shape;
  float overlap[AAC_USAC_MAX_CORE_FRAME];
  struct AacUsacFdChannel_* fd;
  struct AacUsacLpdChannel_* lpd;
};

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_USAC_H */
