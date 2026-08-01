#ifndef BAANDER_AAC_H
#define BAANDER_AAC_H

#ifdef __cplusplus
#include <cstddef>
#include <cstdint>
#else
#include <stddef.h>
#include <stdint.h>
#endif

#ifdef __cplusplus
extern "C" {
#endif

/* Audio Object Types (ISO 14496-3) */
typedef enum AacObjectType_ {
  AAC_AOT_LC = 2,  /* AAC-LC */
  AAC_AOT_SBR = 5, /* HE-AAC v1 (SBR) */
  AAC_AOT_PS = 29, /* HE-AAC v2 (SBR + Parametric Stereo) */
} AacObjectType;

/* Rate Control Modes */
typedef enum AacRateControl_ {
  AAC_RC_TVBR = 0, /* True VBR — quality-based */
  AAC_RC_CVBR = 1, /* Constrained VBR */
  AAC_RC_ABR = 2,  /* Average Bitrate */
  AAC_RC_CBR = 3,  /* Constant Bitrate */
} AacRateControl;

/* Error Codes */
typedef enum AacError_ {
  AAC_OK = 0,
  AAC_ERR_INVALID_ARG = -1,
  AAC_ERR_MEMORY = -2,
  AAC_ERR_INIT = -3,
  AAC_ERR_ENCODE = -4,
  AAC_ERR_DECODE = -5,
  AAC_ERR_UNSUPPORTED = -6,
  AAC_ERR_OVERFLOW = -7,
  AAC_ERR_STATE = -8,
} AacError;

/* Opaque Handles */
typedef void* AacEncoderHandle;
typedef void* AacDecoderHandle;

/* ── Encoder API ─────────────────────────────────────────────────── */

AacEncoderHandle aac_encoder_create(int sample_rate, int channels, int bitrate, AacObjectType aot,
                                    AacRateControl rc_mode);

void aac_encoder_destroy(AacEncoderHandle ctx);

int aac_encoder_encode(AacEncoderHandle ctx, const float* pcm, int n_samples, uint8_t* out,
                       int out_size);

int aac_encoder_set_quality(AacEncoderHandle ctx, int quality);
int aac_encoder_frame_size(AacEncoderHandle ctx);
int aac_encoder_delay(AacEncoderHandle ctx);
int aac_encoder_flush(AacEncoderHandle ctx, uint8_t* out, int out_size);

/* ── Decoder API ─────────────────────────────────────────────────── */

AacDecoderHandle aac_decoder_create(int sample_rate, int channels);
void aac_decoder_destroy(AacDecoderHandle ctx);

int aac_decoder_decode(AacDecoderHandle ctx, const uint8_t* data, int size, float* pcm,
                       int pcm_size);

int aac_decoder_frame_size(AacDecoderHandle ctx);
int aac_decoder_sample_rate(AacDecoderHandle ctx);
int aac_decoder_channels(AacDecoderHandle ctx);
int aac_decoder_get_sbr_ps(AacDecoderHandle ctx, int* has_sbr, int* has_ps);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_H */
