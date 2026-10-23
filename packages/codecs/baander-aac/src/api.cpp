#include <cstring>

#include "aac.h"
#include "aac_cpu.h"
#include "aac_dsp.h"
#include "bitstream.h"
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
  if (aot != AAC_AOT_LC && aot != AAC_AOT_SBR && aot != AAC_AOT_PS) {
    return nullptr;
  }
  if (rc_mode < AAC_RC_TVBR || rc_mode > AAC_RC_CBR) {
    return nullptr;
  }

  ensure_dsp_init();
  auto* s = aac_encoder_state_create(sample_rate, channels, bitrate, aot, rc_mode, &g_dsp);
  return (AacEncoderHandle)s;
}

void aac_encoder_destroy(AacEncoderHandle ctx) {
  if (!ctx) {
    return;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  aac_encoder_state_destroy(s);
}

/* The output must hold the largest frame before any input is consumed, so
 * that a refused call loses nothing. */
static int encode_block(AacEncoderState* s, const float* pcm, uint8_t* out, int out_size) {
  if (out_size < aac_encoder_state_max_frame_bytes(s)) {
    return AAC_ERR_OVERFLOW;
  }
  const int frame_len = aac_encode_frame_internal(s, pcm);
  if (frame_len < 0) {
    return frame_len;
  }
  memcpy(out, s->output_buf, frame_len);
  return frame_len;
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

/* The stream format settings apply to a stream that has not started:
 * before the first frame, or after a flush. */
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
  return aac_encoder_state_max_frame_bytes(static_cast<AacEncoderState*>(ctx));
}

int aac_encoder_flush(AacEncoderHandle ctx, uint8_t* out, int out_size) {
  if (!ctx || !out || out_size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  /* Drain the lookahead and transform overlap with silent blocks; the
   * frames are written back to back as a continuation of the ADTS stream.
   * Once drained the encoder starts over, so the next input opens a clean
   * stream. */
  auto* s = static_cast<AacEncoderState*>(ctx);
  int total = 0;
  while (s->pending_flush > 0) {
    const int len = encode_block(s, nullptr, out + total, out_size - total);
    if (len < 0) {
      return len;
    }
    total += len;
    s->pending_flush--;
  }
  if (total > 0) {
    aac_encoder_state_reset(s);
    s->pending_flush = 0;
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
  return (AacDecoderHandle)s;
}

void aac_decoder_destroy(AacDecoderHandle ctx) {
  if (!ctx) {
    return;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  aac_decoder_state_destroy(s);
}

int aac_decoder_reset(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  aac_decoder_reset_state(static_cast<AacDecoderState*>(ctx));
  return AAC_OK;
}

/* Decodes one raw_data_block() and appends its PCM to out[*written..]. */
static int decode_block(AacDecoderState* s, AacBitReader* reader, float* pcm, int pcm_size,
                        int* written) {
  const int decoded = aac_decode_raw_data_block(s, reader);
  if (decoded < 0) {
    return decoded;
  }
  const int out_channels = aac_decoder_output_channels(s);
  if ((*written + s->frame_size) * out_channels > pcm_size) {
    return AAC_ERR_OVERFLOW;
  }
  aac_decoder_output(s, pcm + (size_t)*written * out_channels);
  *written += s->frame_size;
  return 0;
}

int aac_decoder_decode(AacDecoderHandle ctx, const uint8_t* data, int size, float* pcm,
                       int pcm_size) {
  if (!ctx || !data || !pcm || size <= 0 || pcm_size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);

  /* ADTS-wrapped frames carry their own stream parameters; raw frames use the
   * sample rate given at creation and the layout of their elements. */
  int begin = 0;
  int end = size;
  int blocks = 1;
  int crc = 0;
  if (size >= 2 && data[0] == 0xFF && (data[1] & 0xF6) == 0xF0) {
    AacAdtsHeader hdr;
    if (aac_adts_parse(&hdr, data, size) != 0 || hdr.frame_length > size) {
      return AAC_ERR_DECODE;
    }
    if (hdr.object_type != AAC_AOT_LC) {
      return AAC_ERR_UNSUPPORTED;
    }
    int ret = 0;
    if ((ret = aac_decoder_set_rate_index(s, hdr.sample_rate_index)) != 0 ||
        (ret = aac_decoder_set_channel_config(s, hdr.channel_config)) != 0) {
      return ret;
    }
    blocks = hdr.num_raw_blocks;
    crc = !hdr.protection_absent;
    /* With protection a 16-bit CRC follows the header, preceded by the
     * positions of the second and later raw data blocks; none of it is
     * verified. */
    begin = AAC_ADTS_HEADER_SIZE + (crc ? 2 * (blocks - 1) + 2 : 0);
    end = hdr.frame_length;
    if (begin > end) {
      return AAC_ERR_DECODE;
    }
  }

  AacBitReader reader;
  aac_bitreader_init(&reader, data + begin, end - begin);
  int written = 0;
  for (int b = 0; b < blocks; b++) {
    const int ret = decode_block(s, &reader, pcm, pcm_size, &written);
    if (ret) {
      return ret;
    }
    if (crc && blocks > 1) {
      aac_bitreader_skip(&reader, 16); /* adts_raw_data_block_error_check */
    }
  }
  return written;
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
  auto* s = static_cast<AacDecoderState*>(ctx);
  return s->sbr_mode ? 2 * s->sample_rate : s->sample_rate;
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
  /* The attack window must fit the limiter at the doubled rate SBR may
   * bring. */
  if (attack_ms < 0 || release_ms < 0 ||
      (long long)attack_ms * 2 * s->sample_rate / 1000 > AAC_LIMITER_MAX_LOOKAHEAD) {
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
