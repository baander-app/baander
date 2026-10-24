#include <cstring>

#include "aac.h"
#include "aac_cpu.h"
#include "aac_dsp.h"
#include "bitstream.h"
#include "transport.h"
#if defined(BAAC_AAC_HAVE_DECODER)
#include "decoder.h"
#endif
#if defined(BAAC_AAC_HAVE_ENCODER)
#include "encoder.h"
#endif

/* Global DSP context — initialized once */
static AacDSP g_dsp;
static int g_dsp_initialized = 0;

static void ensure_dsp_init() {
  if (!g_dsp_initialized) {
    aac_dsp_init(&g_dsp);
    g_dsp_initialized = 1;
  }
}

#if defined(BAAC_AAC_HAVE_ENCODER)
/* ── Encoder API ───────────────────────────────────────────────── */

AacEncoderHandle aac_encoder_create(int sample_rate, int channels, int bitrate, AacObjectType aot,
                                    AacRateControl rc_mode) {
  if (sample_rate <= 0 || channels <= 0 || channels > AAC_MAX_CHANNELS || bitrate <= 0) {
    return nullptr;
  }
  if (aot != AAC_AOT_LC && aot != AAC_AOT_SBR && aot != AAC_AOT_PS && aot != AAC_AOT_ER_LD &&
      aot != AAC_AOT_ER_ELD) {
    return nullptr;
  }
  if (rc_mode < AAC_RC_TVBR || rc_mode > AAC_RC_CBR) {
    return nullptr;
  }

  ensure_dsp_init();
  auto* s = aac_encoder_state_create(sample_rate, channels, bitrate, aot, rc_mode, &g_dsp);
  if (s) {
    s->transport = aac_encoder_transport_create();
    if (aac_encoder_aot_low_delay(aot)) {
      /* ADTS cannot signal the low-delay object types. */
      aac_encoder_transport_set(s->transport, AAC_TRANSPORT_LOAS);
    }
  }
  return (AacEncoderHandle)s;
}

void aac_encoder_destroy(AacEncoderHandle ctx) {
  if (!ctx) {
    return;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  aac_encoder_transport_destroy(s->transport);
  aac_encoder_state_destroy(s);
}

/* The output must hold the largest frame before any input is consumed, so
 * that a refused call loses nothing.  The encoder writes an ADTS frame;
 * the transport layer rewraps it. */
static int encode_block(AacEncoderState* s, const float* pcm, uint8_t* out, int out_size) {
  if (out_size < aac_encoder_transport_max_bytes(s, s->transport)) {
    return AAC_ERR_OVERFLOW;
  }
  const int frame_len = aac_encode_frame_internal(s, pcm);
  if (frame_len < 0) {
    return frame_len;
  }
  return aac_encoder_transport_wrap(s, s->transport, frame_len, out, out_size);
}

int aac_encoder_encode(AacEncoderHandle ctx, const float* pcm, int n_samples, uint8_t* out,
                       int out_size) {
  if (!ctx || !pcm || !out || out_size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (n_samples != s->frame_size) {
    return AAC_ERR_INVALID_ARG;
  }
  s->pending_flush = (s->delay + s->frame_size - 1) / s->frame_size;
  return encode_block(s, pcm, out, out_size);
}

static int encoder_started(const AacEncoderState* s) { return s->started; }

int aac_encoder_set_mpeg2(AacEncoderHandle ctx, int enabled) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (encoder_started(s)) {
    return AAC_ERR_STATE;
  }
  s->mpeg2 = enabled ? 1 : 0;
  aac_encoder_state_configure(s);
  return AAC_OK;
}

int aac_encoder_set_channel_mode(AacEncoderHandle ctx, AacChannelMode mode) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (mode != AAC_MODE_DEFAULT && mode != AAC_MODE_7_1_FRONT_CENTER && mode != AAC_MODE_7_1_BACK &&
      mode != AAC_MODE_7_1_TOP_FRONT && mode != AAC_MODE_7_1_REAR_SURROUND) {
    return AAC_ERR_INVALID_ARG;
  }
  if (encoder_started(s)) {
    return AAC_ERR_STATE;
  }
  return aac_encoder_state_set_channel_mode(s, mode);
}

int aac_encoder_set_quality(AacEncoderHandle ctx, int quality) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (quality < 1 || quality > 100) {
    return AAC_ERR_INVALID_ARG;
  }
  s->quality = quality;
  aac_encoder_state_configure(s);
  return AAC_OK;
}

int aac_encoder_set_afterburner(AacEncoderHandle ctx, int enabled) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  static_cast<AacEncoderState*>(ctx)->afterburner = enabled ? 1 : 0;
  return AAC_OK;
}

int aac_encoder_set_frame_length(AacEncoderHandle ctx, int frame_length) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (s->started) {
    return AAC_ERR_STATE;
  }
  return aac_encoder_state_set_frame_length(s, frame_length);
}

int aac_encoder_set_metadata(AacEncoderHandle ctx, AacDrcProfile profile, int prog_ref_level) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (profile < AAC_DRC_NONE || profile > AAC_DRC_SPEECH || prog_ref_level < 0 ||
      prog_ref_level > 127) {
    return AAC_ERR_INVALID_ARG;
  }
  if (aac_encoder_aot_low_delay(s->aot) && profile != AAC_DRC_NONE) {
    return AAC_ERR_UNSUPPORTED; /* error resilient frames carry no fill elements */
  }
  if (encoder_started(s)) {
    return AAC_ERR_STATE;
  }
  s->drc.profile = profile;
  s->drc.prog_ref_level = prog_ref_level;
  aac_drc_encoder_reset(&s->drc);
  return AAC_OK;
}

int aac_encoder_frame_size(AacEncoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  return s->frame_size;
}

int aac_encoder_delay(AacEncoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return static_cast<AacEncoderState*>(ctx)->delay;
}

int aac_encoder_max_frame_bytes(AacEncoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  return aac_encoder_transport_max_bytes(s, s->transport);
}

int aac_encoder_set_transport(AacEncoderHandle ctx, AacTransport transport) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (aac_encoder_aot_low_delay(s->aot) && transport == AAC_TRANSPORT_ADTS) {
    return AAC_ERR_INVALID_ARG;
  }
  return aac_encoder_transport_set(s->transport, transport);
}

int aac_encoder_set_ld_sbr(AacEncoderHandle ctx, int enabled, int dual_rate) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (s->aot != AAC_AOT_ER_ELD) {
    return AAC_ERR_INVALID_ARG;
  }
  if (s->started) {
    return AAC_ERR_STATE;
  }
  return aac_encoder_state_set_ld_sbr(s, enabled, dual_rate);
}

int aac_encoder_set_signaling(AacEncoderHandle ctx, AacSignaling signaling) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return aac_encoder_transport_set_signaling(static_cast<AacEncoderState*>(ctx)->transport,
                                             signaling);
}

int aac_encoder_set_crc(AacEncoderHandle ctx, int enabled) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return aac_encoder_transport_set_crc(static_cast<AacEncoderState*>(ctx)->transport, enabled);
}

int aac_encoder_config(AacEncoderHandle ctx, uint8_t* out, int out_size) {
  if (!ctx || !out || out_size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  AacAsc asc;
  const int ret = aac_encoder_transport_asc(s, s->transport, &asc);
  if (ret) {
    return ret;
  }
  return aac_asc_write(&asc, out, out_size);
}

int aac_encoder_flush(AacEncoderHandle ctx, uint8_t* out, int out_size) {
  if (!ctx || !out || out_size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  /* Drain the lookahead and transform overlap with silent blocks; the
   * frames of a stream transport are written back to back as a
   * continuation of the stream, a packet transport delivers one unit per
   * call.  Once drained the encoder starts over, so the next input opens
   * a clean stream. */
  auto* s = static_cast<AacEncoderState*>(ctx);
  const bool one_unit = aac_encoder_transport_is_packet(s->transport);
  int total = 0;
  while (s->pending_flush > 0) {
    const int len = encode_block(s, nullptr, out + total, out_size - total);
    if (len < 0) {
      return len;
    }
    total += len;
    s->pending_flush--;
    if (one_unit) {
      break;
    }
  }
  if (total > 0 && s->pending_flush == 0) {
    aac_encoder_state_reset(s);
    s->pending_flush = 0;
    aac_encoder_transport_flushed(s->transport);
  }
  return total;
}

#endif /* BAAC_AAC_HAVE_ENCODER */

#if defined(BAAC_AAC_HAVE_DECODER)
/* ── Decoder API ───────────────────────────────────────────────── */

AacDecoderHandle aac_decoder_create(int sample_rate, int channels) {
  if (sample_rate <= 0 || channels < 0 || channels > AAC_MAX_CHANNELS) {
    return nullptr;
  }

  ensure_dsp_init();
  auto* s = aac_decoder_state_create(sample_rate, channels, &g_dsp);
  if (s) {
    s->transport = aac_decoder_transport_create();
  }
  return (AacDecoderHandle)s;
}

void aac_decoder_destroy(AacDecoderHandle ctx) {
  if (!ctx) {
    return;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  aac_decoder_transport_destroy(s->transport);
  aac_decoder_state_destroy(s);
}

int aac_decoder_reset(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  aac_decoder_reset_state(s);
  aac_decoder_transport_reset(s->transport);
  aac_decoder_transport_reapply(s, s->transport);
  return AAC_OK;
}

int aac_decoder_decode(AacDecoderHandle ctx, const uint8_t* data, int size, float* pcm,
                       int pcm_size) {
  if (!ctx || !data || !pcm || size <= 0 || pcm_size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  return aac_decoder_transport_decode(s, s->transport, data, size, pcm, pcm_size);
}

int aac_decoder_set_frame_length(AacDecoderHandle ctx, int frame_length) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  /* 512 and 480 only come with the low-delay object types, from their
   * AudioSpecificConfig. */
  if (frame_length != AAC_FRAME_SIZE_LONG && frame_length != AAC_FRAME_SIZE_LONG_960) {
    return AAC_ERR_INVALID_ARG;
  }
  if (s->aot == AAC_AOT_ER_LD || s->aot == AAC_AOT_ER_ELD) {
    return AAC_ERR_STATE;
  }
  return aac_decoder_set_frame_length_internal(s, frame_length);
}

int aac_decoder_object_type(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return static_cast<AacDecoderState*>(ctx)->aot;
}

int aac_decoder_set_eld_downscale(AacDecoderHandle ctx, int factor) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  if (factor != 1 && factor != 2 && factor != 4) {
    return AAC_ERR_INVALID_ARG;
  }
  if (s->blocks_decoded > 0) {
    return AAC_ERR_STATE;
  }
  if (s->ld_sbr_mode && factor != 1) {
    return AAC_ERR_UNSUPPORTED; /* the CLDFB chain has no downscaled form */
  }
  aac_decoder_set_eld_downscale_internal(s, factor);
  return AAC_OK;
}

int aac_decoder_set_concealment(AacDecoderHandle ctx, AacConcealment mode) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  if (mode != AAC_CONCEAL_OFF && mode != AAC_CONCEAL_NOISE && mode != AAC_CONCEAL_INTERPOLATE) {
    return AAC_ERR_INVALID_ARG;
  }
  static_cast<AacDecoderState*>(ctx)->conceal_mode = mode;
  return AAC_OK;
}

int aac_decoder_last_frame_concealed(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return static_cast<AacDecoderState*>(ctx)->last_concealed;
}

int aac_decoder_concealed_frames(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return static_cast<AacDecoderState*>(ctx)->concealed_frames;
}

int aac_decoder_set_transport(AacDecoderHandle ctx, AacTransport transport) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return aac_decoder_transport_set(static_cast<AacDecoderState*>(ctx)->transport, transport);
}

int aac_decoder_set_config(AacDecoderHandle ctx, const uint8_t* asc, int size) {
  if (!ctx || !asc || size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  return aac_decoder_transport_set_config(s, s->transport, asc, size);
}

int aac_decoder_feed(AacDecoderHandle ctx, const uint8_t* data, int size) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return aac_decoder_transport_feed(static_cast<AacDecoderState*>(ctx)->transport, data, size);
}

int aac_decoder_decode_next(AacDecoderHandle ctx, float* pcm, int pcm_size) {
  if (!ctx || !pcm || pcm_size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  return aac_decoder_transport_decode_next(s, s->transport, pcm, pcm_size);
}

int aac_decoder_pending_bytes(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return aac_decoder_transport_pending(static_cast<AacDecoderState*>(ctx)->transport);
}

int aac_decoder_frame_size(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return static_cast<AacDecoderState*>(ctx)->frame_size;
}

int aac_decoder_sample_rate(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return static_cast<AacDecoderState*>(ctx)->sample_rate;
}

int aac_decoder_output_sample_rate(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return aac_decoder_output_rate(static_cast<AacDecoderState*>(ctx));
}

int aac_decoder_channels(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return aac_decoder_output_channels(static_cast<AacDecoderState*>(ctx));
}

int aac_decoder_stream_channels(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return static_cast<AacDecoderState*>(ctx)->stream_channels;
}

int aac_decoder_set_limiter(AacDecoderHandle ctx, int enabled, int attack_ms, int release_ms) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  /* The attack window must fit the limiter at any output rate the stream
   * may bring, up to 96 kHz doubled by SBR. */
  if (attack_ms < 0 || release_ms < 0 ||
      aac_limiter_lookahead(192000, attack_ms) > AAC_LIMITER_MAX_LOOKAHEAD) {
    return AAC_ERR_INVALID_ARG;
  }
  s->limiter_enabled = enabled ? 1 : 0;
  s->limiter_attack_ms = attack_ms;
  s->limiter_release_ms = release_ms;
  s->limiter_ready = 0; /* reconfigured on the next frame */
  return AAC_OK;
}

int aac_decoder_delay(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return aac_decoder_limiter_delay(static_cast<AacDecoderState*>(ctx));
}

int aac_decoder_channel_mode(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  if (!s->layout_source || s->ps_output) {
    return AAC_MODE_DEFAULT;
  }
  return aac_layout_mode(&s->layout);
}

int aac_decoder_set_drc(AacDecoderHandle ctx, int enabled, int boost, int cut, int target_ref_level,
                        int heavy) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  if (boost < 0 || boost > 100 || cut < 0 || cut > 100 || target_ref_level < -1 ||
      target_ref_level > 127) {
    return AAC_ERR_INVALID_ARG;
  }
  s->drc.settings.enabled = enabled ? 1 : 0;
  s->drc.settings.boost = boost;
  s->drc.settings.cut = cut;
  s->drc.settings.target_ref_level = target_ref_level;
  s->drc.settings.heavy = heavy ? 1 : 0;
  return AAC_OK;
}

int aac_decoder_program_ref_level(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return static_cast<AacDecoderState*>(ctx)->drc.prog_ref_level;
}

int aac_decoder_has_drc(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return static_cast<AacDecoderState*>(ctx)->drc.has_drc;
}

int aac_decoder_get_sbr_ps(AacDecoderHandle ctx, int* has_sbr, int* has_ps) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  if (has_sbr) {
    *has_sbr = s->has_sbr;
  }
  if (has_ps) {
    *has_ps = s->has_ps;
  }
  return AAC_OK;
}
#endif /* BAAC_AAC_HAVE_DECODER */
