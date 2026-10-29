#ifndef BAANDER_AAC_MPS_H
#define BAANDER_AAC_MPS_H

#include <stdint.h>

#include "bitstream.h"
#include "usac.h"

#ifdef __cplusplus
extern "C" {
#endif

/* MPEG Surround (ISO/IEC 23003-1) as AAC carries it: a stereo (or mono)
 * downmix coded by the core plus a spatial payload from which the
 * decoder rebuilds 5.1 with the 5151, 5152 and 525 trees, or stereo
 * with the 2-1-2 tree of ELD v2.  The configuration
 * (SpatialSpecificConfig) travels in the AudioSpecificConfig of object
 * type 30, in the ELD-specific configuration, or in band ahead of the
 * payload (bsSacHeader); the frames (SpatialFrame) travel as an
 * extension payload of type EXT_SAC_DATA (12) in a fill element or in
 * the ELD extension element. */

#define AAC_MPS_MAX_PARAM_BANDS 28
#define AAC_MPS_HYBRID_BANDS 71
#define AAC_MPS_MAX_PARAM_SETS 9 /* eight coded sets and the frame-end set */
#define AAC_MPS_MAX_OTT_BOXES 5
#define AAC_MPS_MAX_TTT_BOXES 1
#define AAC_MPS_MAX_OUTPUT_CHANNELS 6
#define AAC_MPS_MAX_INPUT_CHANNELS 2

/* bsTreeConfig */
enum {
  AAC_MPS_TREE_5151 = 0,
  AAC_MPS_TREE_5152 = 1,
  AAC_MPS_TREE_525 = 2,
  AAC_MPS_TREE_212 = 7
};

/* SpatialSpecificConfig(), the fields the baseline profile and the 2-1-2
 * tool use; HRTF and 3D modes are read and refused. */
using AacMpsConfig = struct AacMpsConfig_ {
  int sampling_frequency; /* bsSamplingFrequency, Hz */
  int frame_length;       /* bsFrameLength + 1: QMF slots per spatial frame */
  int freq_res;           /* bsFreqRes */
  int tree_config;        /* bsTreeConfig */
  int quant_mode;         /* bsQuantMode */
  int one_icc;            /* bsOneIcc */
  int arbitrary_downmix;  /* bsArbitraryDownmix */
  int fixed_gain_sur, fixed_gain_center, fixed_gain_lfe, fixed_gain_dmx;
  int matrix_mode;        /* bsMatrixMode */
  int temp_shape_config;  /* bsTempShapeConfig */
  int decorr_config;      /* bsDecorrConfig */
  int audio_3d_mode;      /* bs3DaudioMode, refused when set */
  int num_ott_boxes, num_ttt_boxes;
  int num_input_channels, num_output_channels;
  int ott_bands[AAC_MPS_MAX_OTT_BOXES];       /* bsOttBands of the LFE boxes, else the band count */
  int ott_lfe[AAC_MPS_MAX_OTT_BOXES];         /* the box feeds the LFE */
  int ttt_mode_low[AAC_MPS_MAX_TTT_BOXES];    /* bsTttModeLow */
  int ttt_mode_high[AAC_MPS_MAX_TTT_BOXES];   /* bsTttModeHigh */
  int ttt_dual_mode[AAC_MPS_MAX_TTT_BOXES];   /* bsTttDualMode */
  int ttt_bands_low[AAC_MPS_MAX_TTT_BOXES];   /* bsTttBandsLow */
  int residual_coding;                        /* bsResidualCoding */
  int residual_sample_rate_index;             /* bsResidualSamplingFrequencyIndex: the band tables of the residual ICS */
  int residual_frames_per_spatial_frame;      /* bsResidualFramesPerSpatialFrame + 1 */
  int residual_present[AAC_MPS_MAX_OTT_BOXES + AAC_MPS_MAX_TTT_BOXES];
  int residual_bands[AAC_MPS_MAX_OTT_BOXES + AAC_MPS_MAX_TTT_BOXES]; /* bsResidualBands */
  int env_quant_mode;                         /* bsEnvQuantMode */
  /* the SacExtensionConfig entries in their order: each has its data in
   * every SpatialFrame's extension part */
  int num_extensions;
  int extension_type[8];
  /* the low-delay variant of ELD v2 */
  int low_delay;
  int ld_num_output_channels; /* 2 for the 2-1-2 tool */
};

/* Parses SpatialSpecificConfig() (also the in-band bsSacHeader form,
 * which carries the same syntax).  The reader must be bounded to the
 * config (the SacExtensionConfig loop runs over the bits that remain:
 * the ASC's length, or bsSacHeaderLen bytes of the in-band header) and
 * its position on entry is the anchor of the config's byte alignment.
 * Returns AAC_OK, AAC_ERR_DECODE on a syntax error, AAC_ERR_UNSUPPORTED
 * for a tree or mode outside this decoder (7.1 trees, HRTF, 3D audio,
 * the arbitrary downmix residual and the arbitrary tree extensions). */
int aac_mps_config_read(AacBitReader* r, AacMpsConfig* cfg);

/* The number of parameter bands of a bsFreqRes value (0 reserved). */
int aac_mps_param_bands(int freq_res);

typedef struct AacMpsState_ AacMpsState;

/* `core_frame_length` is the core frame (1024, 960 or 512), `qmf_slots`
 * the QMF slots per core frame as the downmix arrives (16 for a 1024
 * frame without SBR, 32 with 2:1 SBR; the spatial frame length is a
 * multiple of it), `sample_rate` the output rate. */
AacMpsState* aac_mps_create(const AacMpsConfig* cfg, int core_frame_length, int qmf_slots,
                            int sample_rate);
void aac_mps_destroy(AacMpsState* s);
void aac_mps_reset(AacMpsState* s);
const AacMpsConfig* aac_mps_config(const AacMpsState* s);

/* Parses one SpatialFrame() from the payload (the in-band header, when
 * present, has been read with aac_mps_config_read by the caller).
 * Returns AAC_OK or AAC_ERR_DECODE; a damaged frame keeps the previous
 * parameters, as does aac_mps_conceal for a lost one. */
int aac_mps_read_frame(AacMpsState* s, AacBitReader* r);
void aac_mps_conceal(AacMpsState* s);

/* Residual channels (bsResidualCoding): ResidualData() interleaves the
 * bsIccDiff values with AAC-LC individual_channel_stream() elements, so
 * the frame parser reads them through the caller's core decoder: the
 * reader is called for every residual frame of every box that codes a
 * residual (box order: OTT boxes then the TTT box; `frame` counts the
 * residual frames of the spatial frame) with the bit reader at the
 * element's start, and leaves it after the element: one
 * individual_channel_stream(common_window 0) at the config's residual
 * sampling frequency index, followed by a second one when its window
 * sequence is EIGHT_SHORT_SEQUENCE and `upd_qmf` (the QMF slots per
 * residual frame) is 18, 24 or 30.  It returns AAC_OK or an error, which
 * fails the frame.  Without a reader the residual data is skipped.  The
 * caller then hands the time samples of residual `index` for the frame,
 * full scale 1.0, before aac_mps_apply.  NULL clears it. */
typedef int (*AacMpsResidualReader)(void* ctx, AacBitReader* r, int box, int frame, int upd_qmf);
void aac_mps_set_residual_reader(AacMpsState* s, AacMpsResidualReader reader, void* ctx);
void aac_mps_set_residual(AacMpsState* s, int index, const float* pcm, int length);

/* Builds the output channels from the downmix.  `in` holds the input
 * channels' QMF frames of the core frame (the plain analysis of the core
 * when the core has no SBR, the SBR output otherwise); `out` receives
 * cfg->num_output_channels QMF frames in the canonical order L, R, C,
 * LFE, Ls, Rs (or L, R for the 2-1-2 tree).  The caller synthesises
 * them.  The output lags the input by the hybrid delay of six slots in
 * every band. */
void aac_mps_apply(AacMpsState* s, const AacUsacQmfFrame* const* in,
                   AacUsacQmfFrame* const* out);

#ifdef __cplusplus
}
#endif

#endif
