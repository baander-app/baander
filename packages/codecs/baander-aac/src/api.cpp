#include <cstring>

#include "aac.h"
#include "aac_cpu.h"
#include "aac_dsp.h"
#include "bitstream.h"
#include "decoder.h"
#include "encoder.h"

/* Global DSP context — initialized once */
static AacDSP g_dsp;
static int g_dsp_initialized = 0;

static void ensure_dsp_init() {
  if (!g_dsp_initialized) {
    aac_dsp_init(&g_dsp);
    g_dsp_initialized = 1;
  }
}

/* ── Encoder API ───────────────────────────────────────────────── */

AacEncoderHandle aac_encoder_create(int sample_rate, int channels, int bitrate, AacObjectType aot,
                                    AacRateControl rc_mode) {
  if (sample_rate <= 0 || channels <= 0 || channels > 2 || bitrate <= 0) {
    return nullptr;
  }

  /* Validate AOT */
  if (aot != AAC_AOT_LC && aot != AAC_AOT_SBR && aot != AAC_AOT_PS) {
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

int aac_encoder_encode(AacEncoderHandle ctx, const float* pcm, int n_samples, uint8_t* out,
                       int out_size) {
  if (!ctx || !pcm || !out || out_size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  auto* s = static_cast<AacEncoderState*>(ctx);
  if (n_samples != 1024) {
    return AAC_ERR_INVALID_ARG;
  }

  int frame_len = aac_encode_frame_internal(s, pcm, n_samples);
  if (frame_len <= 0) {
    return AAC_ERR_ENCODE;
  }
  if (frame_len > out_size) {
    return AAC_ERR_OVERFLOW;
  }

  memcpy(out, s->output_buf, frame_len);
  return frame_len;
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
  /* AAC-LC encoder delay = 1024 samples (one frame of lookahead).
   * HE-AAC adds SBR delay (~960 samples) on top. */
  auto* s = static_cast<AacEncoderState*>(ctx);
  int delay = 1024; /* AAC-LC core delay */
  if (s->aot == AAC_AOT_SBR || s->aot == AAC_AOT_PS) {
    delay += 960; /* SBR analysis delay */
  }
  return delay;
}

int aac_encoder_flush(AacEncoderHandle ctx, uint8_t* out, int out_size) {
  if (!ctx || !out || out_size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  /* Produce a final frame with silence to drain the MDCT overlap */
  auto* s = static_cast<AacEncoderState*>(ctx);
  float silence[1024] = {0};
  return aac_encoder_encode(ctx, silence, 1024, out, out_size);
}

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

  /* Parse ADTS header if present */
  AacAdtsHeader hdr;
  int data_offset = 0;
  if (size >= 7 && data[0] == 0xFF && (data[1] & 0xF0) == 0xF0) {
    if (aac_adts_parse(&hdr, data, size) != 0) {
      return AAC_ERR_DECODE;
    }
    data_offset = AAC_ADTS_HEADER_SIZE;
    if (hdr.frame_length > size) {
      return AAC_ERR_DECODE;
    }
  }

  /* Parse raw data block */
  AacBitReader reader;
  aac_bitreader_init(&reader, data + data_offset, size - data_offset);

  int total_samples = 0;
  int max_elements = 16; /* safety limit to prevent infinite loops on malformed data */
  while (aac_bitreader_bits_left(&reader) > 3 && max_elements-- > 0) {
    int elem_type = aac_bitreader_read(&reader, 3);
    if (elem_type == AAC_ELEM_END) {
      break;
    }

    switch (elem_type) {
      case AAC_ELEM_SCE: {
        int tag = aac_bitreader_read(&reader, 4);
        int ch = (tag == 0) ? 0 : 1;
        int ret = aac_decode_sce(s, &reader, ch);
        if (ret) {
          return ret;
        }
        /* Copy decoded output to PCM */
        int n = (pcm_size - total_samples >= 1024) ? 1024 : pcm_size - total_samples;
        if (n > 0) {
          if (s->channels == 1) {
            memcpy(pcm + total_samples, s->ch[ch].output, n * sizeof(float));
          } else {
            /* Interleave for stereo output */
            for (int i = 0; i < n && (total_samples + i) * 2 + 1 < pcm_size; i++) {
              pcm[static_cast<ptrdiff_t>(total_samples + i) * 2] = s->ch[ch].output[i];
              pcm[static_cast<ptrdiff_t>(total_samples + i) * 2 + 1] = s->ch[ch].output[i];
            }
          }
          total_samples += n;
        }
        break;
      }
      case AAC_ELEM_CPE: {
        int ret = aac_decode_cpe(s, &reader);
        if (ret) {
          return ret;
        }
        int n =
            (pcm_size - total_samples * 2 >= 1024 * 2) ? 1024 : (pcm_size - total_samples * 2) / 2;
        if (n > 0) {
          for (int i = 0; i < n; i++) {
            pcm[static_cast<ptrdiff_t>(total_samples + i) * 2] = s->ch[0].output[i];
            pcm[static_cast<ptrdiff_t>(total_samples + i) * 2 + 1] = s->ch[1].output[i];
          }
          total_samples += n;
        }
        break;
      }
      case AAC_ELEM_FIL: {
        int cnt = aac_bitreader_read(&reader, 4);
        if (cnt == 15) {
          cnt += aac_bitreader_read(&reader, 8) - 1;
        }
        aac_bitreader_skip(&reader, cnt * 8);
        break;
      }
      default:
        aac_bitreader_byte_align(&reader);
        break;
    }
  }

  return total_samples;
}

int aac_decoder_frame_size(AacDecoderHandle ctx) {
  if (!ctx) {
    return AAC_ERR_INVALID_ARG;
  }
  return 1024;
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
