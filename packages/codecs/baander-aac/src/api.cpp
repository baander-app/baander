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
  if (sample_rate <= 0 || channels <= 0 || channels > 2 || bitrate <= 0) {
    return nullptr;
  }
  /* Only AAC-LC is implemented; HE-AAC v1/v2 need an SBR/PS encoder. */
  if (aot != AAC_AOT_LC) {
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

static int encode_block(AacEncoderState* s, const float* pcm, uint8_t* out, int out_size) {
  const int frame_len = aac_encode_frame_internal(s, pcm);
  if (frame_len < 0) {
    return frame_len;
  }
  if (frame_len > out_size) {
    return AAC_ERR_OVERFLOW;
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
  s->pending_flush = AAC_ENCODER_DELAY / s->frame_size;
  return encode_block(s, pcm, out, out_size);
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
  return AAC_ENCODER_DELAY;
}

int aac_encoder_flush(AacEncoderHandle ctx, uint8_t* out, int out_size) {
  if (!ctx || !out || out_size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  /* Drain the lookahead and transform overlap with silent blocks; the
   * frames are written back to back as a continuation of the ADTS stream. */
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
  return total;
}

#endif /* BAAC_AAC_HAVE_ENCODER */

#if defined(BAAC_AAC_HAVE_DECODER)
/* ── Decoder API ───────────────────────────────────────────────── */

AacDecoderHandle aac_decoder_create(int sample_rate, int channels) {
  if (sample_rate <= 0 || channels <= 0 || channels > 2) {
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

int aac_decoder_decode(AacDecoderHandle ctx, const uint8_t* data, int size, float* pcm,
                       int pcm_size) {
  if (!ctx || !data || !pcm || size <= 0 || pcm_size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  const int frame = s->frame_size;
  if (pcm_size < frame * s->channels) {
    return AAC_ERR_OVERFLOW;
  }

  /* ADTS-wrapped frames carry their own stream parameters; raw frames use the
   * sample rate given at creation. */
  int begin = 0;
  int end = size;
  if (size >= 2 && data[0] == 0xFF && (data[1] & 0xF6) == 0xF0) {
    AacAdtsHeader hdr;
    if (aac_adts_parse(&hdr, data, size) != 0 || hdr.frame_length > size) {
      return AAC_ERR_DECODE;
    }
    if (hdr.object_type != AAC_AOT_LC || hdr.num_raw_blocks != 1 || hdr.channel_config > 2) {
      return AAC_ERR_UNSUPPORTED;
    }
    if (hdr.sample_rate_index != s->rate_index &&
        aac_decoder_set_rate_index(s, hdr.sample_rate_index) != 0) {
      return AAC_ERR_UNSUPPORTED;
    }
    /* With protection_absent == 0 a 16-bit CRC follows the header; it is not
     * verified. */
    begin = AAC_ADTS_HEADER_SIZE + (hdr.protection_absent ? 0 : 2);
    end = hdr.frame_length;
  }

  AacBitReader reader;
  aac_bitreader_init(&reader, data + begin, end - begin);
  const int decoded = aac_decode_raw_data_block(s, &reader);
  if (decoded < 0) {
    return decoded;
  }

  /* Map the stream's channels onto the requested output layout. */
  const float* l = s->ch[0].output;
  const float* r = decoded == 2 ? s->ch[1].output : s->ch[0].output;
  if (s->channels == 1) {
    for (int i = 0; i < frame; i++) {
      pcm[i] = decoded == 2 ? 0.5f * (l[i] + r[i]) : l[i];
    }
  } else {
    for (int i = 0; i < frame; i++) {
      pcm[2 * i] = l[i];
      pcm[2 * i + 1] = r[i];
    }
  }
  return frame;
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

int aac_decoder_channels(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return static_cast<AacDecoderState*>(ctx)->channels;
}

int aac_decoder_get_sbr_ps(AacDecoderHandle ctx, int* has_sbr, int* has_ps) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacDecoderState*>(ctx);
  if (has_sbr) {
    *has_sbr = s->ch[0].has_sbr;
  }
  if (has_ps) {
    *has_ps = s->ch[0].has_ps;
  }
  return AAC_OK;
}
#endif /* BAAC_AAC_HAVE_DECODER */
