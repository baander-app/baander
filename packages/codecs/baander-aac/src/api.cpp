#include <algorithm>
#include <cstring>
#include <vector>

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
#include "loudness.h"
#include "usac.h"
#include "usac_enc.h"
#endif

/* Every codec object dispatches through the process-wide table of
 * aac_dsp_global(), which the first use fills from the CPU flags. */

#if defined(BAAC_AAC_HAVE_ENCODER)
/* ── Encoder API ───────────────────────────────────────────────── */

AacEncoderHandle aac_encoder_create(int sample_rate, int channels, int bitrate, AacObjectType aot,
                                    AacRateControl rc_mode) {
  if (sample_rate <= 0 || channels <= 0 || channels > AAC_MAX_CHANNELS || bitrate <= 0) {
    return nullptr;
  }
  if (aot != AAC_AOT_LC && aot != AAC_AOT_SBR && aot != AAC_AOT_PS && aot != AAC_AOT_ER_LD &&
      aot != AAC_AOT_ER_ELD && aot != AAC_AOT_USAC) {
    return nullptr;
  }
  if (rc_mode < AAC_RC_TVBR || rc_mode > AAC_RC_CBR) {
    return nullptr;
  }

  auto* s =
      aac_encoder_state_create(sample_rate, channels, bitrate, aot, rc_mode, aac_dsp_global());
  if (s) {
    s->transport = aac_encoder_transport_create();
    if (aac_encoder_aot_low_delay(aot) || aot == AAC_AOT_USAC) {
      /* ADTS cannot signal the low-delay object types or USAC. */
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
  aac_loudness_destroy(s->loudness);
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
  const int len = aac_encoder_transport_wrap(s, s->transport, frame_len, out, out_size);
  if (len > 0 && s->usac) {
    s->usac->last_frame_bytes = len; /* the frame as the caller receives it */
  }
  return len;
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
  /* The meter measures one stream: the first frame after creation or a
   * flush starts it over, and a refused frame (the output too small)
   * consumed no input, so nothing is fed for it. */
  const int first_frame = !s->started;
  s->pending_flush = (s->delay + s->frame_size - 1) / s->frame_size;
  const int len = encode_block(s, pcm, out, out_size);
  if (len >= 0 && s->loudness) {
    if (first_frame) {
      aac_loudness_reset(s->loudness);
    }
    aac_loudness_feed(s->loudness, pcm, s->frame_size);
  }
  return len;
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
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (s->aot == AAC_AOT_USAC && enabled) {
    return AAC_ERR_UNSUPPORTED; /* the pass re-searches the AAC codebooks, which USAC has not */
  }
  s->afterburner = enabled ? 1 : 0;
  return AAC_OK;
}

int aac_encoder_set_usac(AacEncoderHandle ctx, const AacUsacEncoderOptions* options) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (s->aot != AAC_AOT_USAC || !s->usac) {
    return AAC_ERR_UNSUPPORTED;
  }
  if (encoder_started(s)) {
    return AAC_ERR_STATE;
  }
  /* the 2-1-2 codes the pair's downmix in the SBR encoder's QMF domain,
   * so it cannot be asked for with SBR turned off */
  if (options && options->mps212 > 0 && options->sbr == 0) {
    return AAC_ERR_INVALID_ARG;
  }
  aac_usac_enc_set_options(s->usac, options);
  aac_encoder_state_reset(s);
  return AAC_OK;
}

int aac_encoder_request_independent_frame(AacEncoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (s->aot != AAC_AOT_USAC || !s->usac) {
    return AAC_ERR_UNSUPPORTED;
  }
  aac_usac_enc_request_independent(s->usac);
  return AAC_OK;
}

int aac_encoder_last_frame_info(AacEncoderHandle ctx, AacEncoderFrameInfo* info) {
  if (!ctx || !info) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (s->aot != AAC_AOT_USAC || !s->usac) {
    return AAC_ERR_UNSUPPORTED;
  }
  const AacUsacEncoder* u = s->usac;
  if (!u->has_last_frame) {
    return AAC_ERR_STATE;
  }
  info->frame_index = u->last_frame_index;
  info->independent = u->last_indep;
  info->preroll_bytes = u->last_preroll_bytes;
  info->bytes = u->last_frame_bytes;
  info->sample_position = (int64_t)u->last_frame_index * s->frame_size - s->delay;
  return AAC_OK;
}

/* Drops the configuration extension of one type from the USAC encoder's
 * list; the list keeps its order. */
static void usac_remove_config_ext(AacUsacEncoder* u, int type) {
  int kept = 0;
  for (int i = 0; i < u->num_config_exts; i++) {
    if (u->config_ext[i].type != type) {
      if (kept != i) {
        u->config_ext[kept] = u->config_ext[i];
      }
      kept++;
    }
  }
  u->num_config_exts = kept;
}

int aac_encoder_set_loudness_info(AacEncoderHandle ctx, const AacLoudnessInfo* info) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (s->aot != AAC_AOT_USAC || !s->usac) {
    return AAC_ERR_UNSUPPORTED;
  }
  if (encoder_started(s)) {
    return AAC_ERR_STATE;
  }
  if (!info) {
    usac_remove_config_ext(s->usac, AAC_USAC_CFG_EXT_LOUDNESS_INFO);
    return AAC_OK;
  }
  /* loudnessInfoSet() ends at its last bit; the configuration extension
   * carries whole bytes, so the writer's buffer is zero behind it. */
  uint8_t payload[AAC_USAC_MAX_CONFIG_EXT_BYTES];
  memset(payload, 0, sizeof(payload));
  AacBitWriter w;
  aac_bitwriter_init(&w, payload, (int)sizeof(payload));
  const int bits = aac_loudness_info_write(&w, info);
  if (bits < 0) {
    return bits;
  }
  return aac_usac_enc_set_config_ext(s->usac, AAC_USAC_CFG_EXT_LOUDNESS_INFO, payload,
                                     (bits + 7) / 8);
}

int aac_encoder_set_loudness_measurement(AacEncoderHandle ctx, int enabled) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (!enabled) {
    aac_loudness_destroy(s->loudness);
    s->loudness = nullptr;
    return AAC_OK;
  }
  if (s->loudness) {
    return AAC_OK;
  }
  if (encoder_started(s)) {
    return AAC_ERR_STATE;
  }
  /* The meter takes the input as the encoder does: interleaved in the
   * canonical order at the input rate (twice the core rate with SBR),
   * with the input's channel count (two for HE-AAC v2). */
  s->loudness = aac_loudness_create(s->input_rate, s->input_channels);
  return s->loudness ? AAC_OK : AAC_ERR_UNSUPPORTED;
}

int aac_encoder_loudness(AacEncoderHandle ctx, AacLoudnessInfo* out) {
  if (!ctx || !out) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (!s->loudness) {
    return AAC_ERR_STATE;
  }
  return aac_loudness_result(s->loudness, out);
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

int aac_encoder_set_extension_payload(AacEncoderHandle ctx, const uint8_t* data, int nbits) {
  if (!ctx || nbits < 0 || (nbits > 0 && !data)) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (s->aot != AAC_AOT_ER_ELD) {
    return AAC_ERR_UNSUPPORTED;
  }
  if (nbits > 8 * AAC_ENCODER_MAX_EXT_PAYLOAD_BYTES ||
      nbits > aac_encoder_state_extension_budget_bits(s)) {
    return AAC_ERR_INVALID_ARG; /* the frame cannot carry it next to its channels */
  }
  if (nbits > 0) {
    memcpy(s->ext_payload, data, (size_t)((nbits + 7) / 8));
  }
  s->ext_payload_bits = nbits;
  return 0;
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
  if ((aac_encoder_aot_low_delay(s->aot) || s->aot == AAC_AOT_USAC) && profile != AAC_DRC_NONE) {
    return AAC_ERR_UNSUPPORTED; /* error resilient and USAC frames carry no fill elements */
  }
  if (encoder_started(s)) {
    return AAC_ERR_STATE;
  }
  s->drc.profile = profile;
  s->drc.prog_ref_level = prog_ref_level;
  aac_drc_encoder_reset(&s->drc);
  return AAC_OK;
}

int aac_encoder_set_peak_bitrate(AacEncoderHandle ctx, int bps) {
  if (!ctx || bps < 0) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  /* a VBR mode may be lowered by the cap; a constant rate cannot */
  if (bps > 0 && !s->vbr_mode && (s->rc_mode == AAC_RC_CBR || s->rc_mode == AAC_RC_ABR) &&
      bps < s->bitrate) {
    return AAC_ERR_INVALID_ARG;
  }
  if (encoder_started(s)) {
    return AAC_ERR_STATE;
  }
  return aac_encoder_state_set_peak_bitrate(s, bps);
}

int aac_encoder_set_bandwidth(AacEncoderHandle ctx, int hz) {
  if (!ctx || hz < 0 || (hz > 0 && hz < 1000)) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  s->bandwidth_override = hz;
  aac_encoder_state_configure(s);
  return AAC_OK;
}

int aac_encoder_bandwidth(AacEncoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return static_cast<AacEncoderState*>(ctx)->bandwidth;
}

int aac_encoder_set_vbr_mode(AacEncoderHandle ctx, int mode) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (mode < 0 || mode > 5) {
    return AAC_ERR_INVALID_ARG;
  }
  if (encoder_started(s)) {
    return AAC_ERR_STATE;
  }
  return aac_encoder_state_set_vbr_mode(s, mode);
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
  if (s->aot == AAC_AOT_USAC && transport == AAC_TRANSPORT_ADTS) {
    return AAC_ERR_UNSUPPORTED; /* ADTS has no profile for USAC */
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

  auto* s = aac_decoder_state_create(sample_rate, channels, aac_dsp_global());
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
  s->decoded_since_reset = 0;
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

int aac_decoder_clear_history(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  aac_decoder_clear_history_state(s);
  aac_decoder_transport_clear(s->transport);
  aac_decoder_transport_reapply(s, s->transport);
  return AAC_OK;
}

/* The tail the decoder holds back is aac_decoder_delay samples long: the
 * decoder runs on silent frames until that many samples have come out
 * of the limiter's and the surround decoder's delay lines. */
int aac_decoder_flush(AacDecoderHandle ctx, float* out, int out_size) {
  if (!ctx || (!out && out_size > 0) || out_size < 0) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  const int delay = aac_decoder_delay(ctx);
  const int channels = aac_decoder_output_channels(s);
  int written = 0;
  if (delay > 0 && channels > 0 && s->layout_source != 0 && s->blocks_decoded > 0) {
    if ((long long)delay * channels > out_size) {
      return AAC_ERR_OVERFLOW;
    }
    std::vector<float> block((size_t)AAC_DECODER_MAX_OUTPUT * (size_t)channels);
    while (written < delay) {
      if (aac_decoder_silent_block(s) < 0 || aac_decoder_output_channels(s) != channels ||
          s->frame_size <= 0) {
        break;
      }
      aac_decoder_output(s, block.data());
      aac_decoder_apply_limiter(s, block.data(), s->frame_size, channels);
      const int take = std::min(s->frame_size, delay - written);
      memcpy(out + (size_t)written * channels, block.data(),
             (size_t)take * channels * sizeof(float));
      written += take;
    }
  }
  aac_decoder_reset(ctx);
  return written;
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
  if (s->aot == AAC_AOT_ER_LD || s->aot == AAC_AOT_ER_ELD || s->aot == AAC_AOT_USAC) {
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
  /* A reconfiguration mid-stream (a new rate or layout) clears the block
   * count but not this flag, so the factor stays fixed until a reset. */
  if (s->decoded_since_reset) {
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
  auto* s = static_cast<AacDecoderState*>(ctx);
  return aac_decoder_limiter_delay(s) + aac_decoder_mps_delay(s);
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
  if (s->aot == AAC_AOT_USAC) {
    return AAC_ERR_UNSUPPORTED; /* USAC streams carry MPEG-D DRC, not dynamic_range_info */
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

/* The MPEG-D DRC and loudness request lives in the decoder state and is
 * handed to the DRC module of the USAC stream when there is one; a
 * stream configured later takes it from the state. */
static void forward_unidrc_request(AacDecoderState* s) {
  if (s->usac) {
    s->usac->request = s->unidrc_request;
    aac_unidrc_set_request(s->usac->drc, &s->unidrc_request);
  }
}

int aac_decoder_set_loudness(AacDecoderHandle ctx, int enabled, int target_lufs_x100) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  if (target_lufs_x100 < -3100 || target_lufs_x100 > -1000) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  s->unidrc_request.loudness_normalization = enabled ? 1 : 0;
  s->unidrc_request.target_loudness_x100 = target_lufs_x100;
  forward_unidrc_request(s);
  return AAC_OK;
}

int aac_decoder_set_drc_effect(AacDecoderHandle ctx, AacDrcEffect effect, int album_mode,
                               int boost, int cut) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  if (effect < AAC_DRC_EFFECT_OFF || effect > AAC_DRC_EFFECT_GENERAL || boost < 0 ||
      boost > 100 || cut < 0 || cut > 100) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  s->unidrc_request.effect = effect;
  s->unidrc_request.album_mode = album_mode ? 1 : 0;
  s->unidrc_request.boost_percent = boost;
  s->unidrc_request.cut_percent = cut;
  forward_unidrc_request(s);
  return AAC_OK;
}

int aac_decoder_loudness(AacDecoderHandle ctx, int* program_loudness_x100,
                         int* norm_gain_db_x100) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  if (!s->usac || s->aot != AAC_AOT_USAC) {
    return AAC_ERR_STATE; /* no USAC stream, so no MPEG-D loudness */
  }
  int drc_set_id = -1;
  aac_unidrc_status(s->usac->drc, program_loudness_x100, norm_gain_db_x100, &drc_set_id);
  return AAC_OK;
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

int aac_decoder_has_surround(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return static_cast<AacDecoderState*>(ctx)->has_surround ? 1 : 0;
}
#endif /* BAAC_AAC_HAVE_DECODER */
