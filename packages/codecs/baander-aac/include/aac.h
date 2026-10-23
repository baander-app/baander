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

/* Transport formats.  The decoder detects ADTS and LOAS from their sync
 * words; raw access units need an AudioSpecificConfig
 * (aac_decoder_set_config) or the creation parameters. */
typedef enum AacTransport_ {
  AAC_TRANSPORT_DETECT = 0,    /* decoder only: ADTS or LOAS by sync word, else raw */
  AAC_TRANSPORT_ADTS = 1,
  AAC_TRANSPORT_LOAS = 2,      /* AudioSyncStream carrying AudioMuxElement(1) */
  AAC_TRANSPORT_LATM_MCP1 = 3, /* AudioMuxElement with in-band StreamMuxConfig */
  AAC_TRANSPORT_LATM_MCP0 = 4, /* AudioMuxElement, StreamMuxConfig out of band */
  AAC_TRANSPORT_RAW = 5,       /* raw_data_block access units (MP4 samples) */
} AacTransport;

/* How SBR and PS are signalled in an AudioSpecificConfig (ISO/IEC 14496-3
 * 1.6.5).  ADTS can only signal implicitly. */
typedef enum AacSignaling_ {
  AAC_SIGNAL_IMPLICIT = 0,      /* core AOT only; decoders detect SBR from the data */
  AAC_SIGNAL_EXPLICIT_BC = 1,   /* backward compatible: core AOT plus a sync extension */
  AAC_SIGNAL_EXPLICIT_HIER = 2, /* hierarchical: AOT 5 or 29 in front of the core */
} AacSignaling;

/* Decoder error concealment (ISO/IEC 14496-3 4.5.2.x leaves the method to
 * the decoder). */
typedef enum AacConcealment_ {
  AAC_CONCEAL_OFF = 0,         /* a damaged frame returns AAC_ERR_DECODE */
  AAC_CONCEAL_NOISE = 1,       /* the last good band energies are refilled with noise */
  AAC_CONCEAL_INTERPOLATE = 2, /* the last good spectrum fades out over a few frames, then noise */
} AacConcealment;

/* Channel modes beyond the plain channel counts: the 7.1 layouts of
 * ISO/IEC 14496-3 Table 1.19 (channel configurations 7, 12 and 14) and the
 * two PCE-signalled 7.1 layouts of ETSI TS 101 154.  The canonical
 * interleaved order is L, R, C, LFE, Ls, Rs, then the layout's extra pair:
 * Lc, Rc (front centre), Lrs, Rrs (back of the side pair), or Ltf, Rtf (top
 * front). */
typedef enum AacChannelMode_ {
  AAC_MODE_DEFAULT = 0,           /* by channel count: 1..6 as their configuration, 7 as 6.1, 8 as 7.1 back (12) */
  AAC_MODE_7_1_FRONT_CENTER = 7,  /* configuration 7: C, Lc+Rc, L+R, Ls+Rs, LFE */
  AAC_MODE_7_1_BACK = 12,         /* configuration 12: C, L+R, Ls+Rs, Lrs+Rrs, LFE */
  AAC_MODE_7_1_TOP_FRONT = 14,    /* configuration 14: C, L+R, Ls+Rs, LFE, Ltf+Rtf */
  AAC_MODE_7_1_REAR_SURROUND = 33 /* PCE: C, L+R, Ls+Rs, Lrs+Rrs, LFE with the side pair as side */
} AacChannelMode;

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
/* Largest frame aac_encoder_encode can produce; a call with a smaller
 * output buffer is refused with AAC_ERR_OVERFLOW before it consumes any
 * input. */
int aac_encoder_max_frame_bytes(AacEncoderHandle ctx);
int aac_encoder_flush(AacEncoderHandle ctx, uint8_t* out, int out_size);

/* Stream format settings.  Each must be called before the first
 * aac_encoder_encode (or after a flush); otherwise AAC_ERR_STATE. */
/* Default AAC_TRANSPORT_ADTS; AAC_TRANSPORT_DETECT is refused. */
int aac_encoder_set_transport(AacEncoderHandle ctx, AacTransport transport);
/* Default implicit.  ADTS always signals implicitly; ASC and LATM carry the
 * chosen mode. */
int aac_encoder_set_signaling(AacEncoderHandle ctx, AacSignaling signaling);
/* 1024 (default) or 960 core samples per frame; aac_encoder_frame_size
 * follows (960, or 1920 with SBR). */
int aac_encoder_set_frame_length(AacEncoderHandle ctx, int frame_length);
/* Writes the ADTS CRC (protection_absent = 0) or the LATM CRC. */
int aac_encoder_set_crc(AacEncoderHandle ctx, int enabled);
/* MPEG-2 AAC-LC/HE (ADTS id 1): no perceptual noise substitution. */
int aac_encoder_set_mpeg2(AacEncoderHandle ctx, int enabled);
/* Chooses the 7.1 layout of an 8-channel input (AAC_MODE_7_1_BACK by
 * default); AAC_ERR_INVALID_ARG when the mode needs another channel count. */
int aac_encoder_set_channel_mode(AacEncoderHandle ctx, AacChannelMode mode);
/* Writes the AudioSpecificConfig (for an MP4 esds box or out-of-band
 * StreamMuxConfig) and returns its length in bytes, or AAC_ERR_OVERFLOW. */
int aac_encoder_config(AacEncoderHandle ctx, uint8_t* out, int out_size);

/* ── Decoder API ─────────────────────────────────────────────────── */

/* Samples per channel one call to aac_decoder_decode can produce: up to
 * four raw data blocks of 2048 samples (SBR) in one ADTS frame. */
#define AAC_DECODER_MAX_OUTPUT 8192

/* `channels` is the output layout: 0 emits the stream's own channels in
 * canonical order (L, R, C, LFE, Ls, Rs, Lc, Rc), 1 and 2 downmix larger
 * layouts (a mono stream goes to both stereo channels at -3 dB, a stereo
 * stream becomes 0.707 (L + R)); 3..8 copy the stream's channels and zero
 * the rest. */
AacDecoderHandle aac_decoder_create(int sample_rate, int channels);
void aac_decoder_destroy(AacDecoderHandle ctx);

/* Decodes one ADTS frame (every raw data block in it) or one raw data
 * block; returns the samples per channel written to pcm, interleaved with
 * aac_decoder_channels() channels, or a negative AacError.  pcm_size must
 * hold the whole output: AAC_DECODER_MAX_OUTPUT * channels floats always
 * suffice. */
int aac_decoder_decode(AacDecoderHandle ctx, const uint8_t* data, int size, float* pcm,
                       int pcm_size);

/* Clears the filterbank history, for a seek or a new stream. */
int aac_decoder_reset(AacDecoderHandle ctx);

/* Samples per channel of one raw data block in the output: 1024, or 2048
 * while SBR is decoded. */
int aac_decoder_frame_size(AacDecoderHandle ctx);
/* Sample rate of the core stream. */
int aac_decoder_sample_rate(AacDecoderHandle ctx);
/* Sample rate of the output: twice the core rate while SBR is decoded. */
int aac_decoder_output_sample_rate(AacDecoderHandle ctx);
/* Channels of the output: the requested count, or with 0 the stream's,
 * which is 2 for a mono stream once it has carried parametric stereo. */
int aac_decoder_channels(AacDecoderHandle ctx);
/* Channels the last decoded frame carried. */
int aac_decoder_stream_channels(AacDecoderHandle ctx);
/* Whether the last decoded frame carried SBR and parametric stereo data. */
int aac_decoder_get_sbr_ps(AacDecoderHandle ctx, int* has_sbr, int* has_ps);

/* ── Decoder stream settings ─────────────────────────────────────── */

/* Default AAC_TRANSPORT_DETECT.  LATM without in-band configuration needs
 * aac_decoder_set_config first. */
int aac_decoder_set_transport(AacDecoderHandle ctx, AacTransport transport);
/* Applies an AudioSpecificConfig: sample rate, layout, frame length and
 * explicit SBR/PS signalling.  Sets the transport to raw access units when
 * it is still DETECT.  Returns AAC_ERR_UNSUPPORTED for an object type
 * the decoder lacks. */
int aac_decoder_set_config(AacDecoderHandle ctx, const uint8_t* asc, int size);
/* 1024 (default) or 960 samples per raw data block, for ADTS streams
 * that cannot say; an AudioSpecificConfig overrides it. */
int aac_decoder_set_frame_length(AacDecoderHandle ctx, int frame_length);
/* Default AAC_CONCEAL_INTERPOLATE.  With concealment on, a damaged frame
 * decodes to concealed audio and counts in aac_decoder_concealed_frames. */
int aac_decoder_set_concealment(AacDecoderHandle ctx, AacConcealment mode);
/* Whether the last frame returned was concealed, and how many were since
 * creation or reset. */
int aac_decoder_last_frame_concealed(AacDecoderHandle ctx);
int aac_decoder_concealed_frames(AacDecoderHandle ctx);
/* Peak limiter on the output (off by default): attack_ms of look-ahead
 * delay, release_ms of gain recovery; aac_decoder_delay reports the
 * delay in output samples. */
int aac_decoder_set_limiter(AacDecoderHandle ctx, int enabled, int attack_ms, int release_ms);
int aac_decoder_delay(AacDecoderHandle ctx);
/* The channel mode of the stream's layout (AAC_MODE_DEFAULT for the plain
 * configurations 1..6 and 6.1). */
int aac_decoder_channel_mode(AacDecoderHandle ctx);

/* ── Streaming decode ────────────────────────────────────────────── */

/* Appends bytes to the decoder's input FIFO; returns the number taken
 * (fewer than size when the FIFO is full).  aac_decoder_decode_next then
 * decodes the next complete frame of the transport (ADTS, LOAS, LATM or
 * raw access units with an AudioSpecificConfig), resynchronising across
 * damaged bytes, and returns the samples per channel written, 0 when no
 * complete frame is pending, or a negative AacError (only with
 * concealment off, or for an unsupported stream). */
int aac_decoder_feed(AacDecoderHandle ctx, const uint8_t* data, int size);
int aac_decoder_decode_next(AacDecoderHandle ctx, float* pcm, int pcm_size);
/* Bytes waiting in the FIFO. */
int aac_decoder_pending_bytes(AacDecoderHandle ctx);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_H */
