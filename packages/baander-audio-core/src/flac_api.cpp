/* The public C API of the lossless mode (flac.h): argument checks around
 * the codec objects, and allocation failures turned into
 * AAC_ERR_MEMORY, so that no exception crosses the C boundary. */
#include <new>

#include "flac.h"
#include "flac_internal.h"

#if defined(BAC_AAC_HAVE_DECODER)
/* ── Decoder API ───────────────────────────────────────────────── */

BacFlacDecoder* bac_flac_decoder_create(void) { return flac_decoder_create(); }

void bac_flac_decoder_destroy(BacFlacDecoder* dec) {
  if (dec) {
    flac_decoder_destroy(dec);
  }
}

int bac_flac_decoder_reset(BacFlacDecoder* dec) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  flac_decoder_reset(dec);
  return AAC_OK;
}

int bac_flac_decoder_set_streaminfo(BacFlacDecoder* dec, const uint8_t* streaminfo, int size) {
  if (!dec || !streaminfo || size != BAC_FLAC_STREAMINFO_BYTES) {
    return AAC_ERR_INVALID_ARG;
  }
  try {
    return flac_decoder_set_streaminfo(dec, streaminfo);
  } catch (const std::bad_alloc&) {
    return AAC_ERR_MEMORY;
  }
}

int bac_flac_decoder_set_md5_check(BacFlacDecoder* dec, int enabled) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_set_md5_check(dec, enabled != 0);
}

int bac_flac_decoder_set_metadata(BacFlacDecoder* dec, int collect, size_t max_bytes) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_set_metadata(dec, collect != 0, max_bytes);
}

int bac_flac_decoder_comments(BacFlacDecoder* dec, const BacFlacComment** comments,
                               BacFlacComment* vendor) {
  if (!dec || !comments) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_comments(dec, comments, vendor);
}

int bac_flac_decoder_pictures(BacFlacDecoder* dec, const BacFlacPicture** pictures) {
  if (!dec || !pictures) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_pictures(dec, pictures);
}

int bac_flac_decoder_skipped_metadata(BacFlacDecoder* dec) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_skipped_metadata(dec);
}

int bac_flac_decoder_id3(BacFlacDecoder* dec, uint64_t* id3v2_bytes, int* id3v1_trailer) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  flac_decoder_id3(dec, id3v2_bytes, id3v1_trailer);
  return AAC_OK;
}

int bac_flac_decoder_feed(BacFlacDecoder* dec, const uint8_t* data, int size) {
  if (!dec || size < 0 || (!data && size > 0)) {
    return AAC_ERR_INVALID_ARG;
  }
  try {
    return flac_decoder_feed(dec, data, size);
  } catch (const std::bad_alloc&) {
    return AAC_ERR_MEMORY;
  }
}

int bac_flac_decoder_decode_next(BacFlacDecoder* dec, int32_t* pcm, int pcm_size) {
  if (!dec || !pcm || pcm_size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  try {
    return flac_decoder_decode_next(dec, pcm, pcm_size);
  } catch (const std::bad_alloc&) {
    return AAC_ERR_MEMORY;
  }
}

int bac_flac_decoder_pending_bytes(BacFlacDecoder* dec) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_pending_bytes(dec);
}

int bac_flac_decoder_stream_info(BacFlacDecoder* dec, BacFlacStreamInfo* info) {
  if (!dec || !info) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_stream_info(dec, info);
}

int bac_flac_decoder_last_frame_info(BacFlacDecoder* dec, BacFlacFrameInfo* info) {
  if (!dec || !info) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_last_frame_info(dec, info);
}

int bac_flac_decoder_lost_frames(BacFlacDecoder* dec) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_lost_frames(dec);
}

int bac_flac_decoder_seek_points(BacFlacDecoder* dec, const BacFlacSeekPoint** points) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_seek_points(dec, points);
}

int64_t bac_flac_decoder_first_frame_offset(BacFlacDecoder* dec) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_first_frame_offset(dec);
}

int bac_flac_decoder_seek_reset(BacFlacDecoder* dec) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_seek_reset(dec);
}

int bac_flac_decoder_end_input(BacFlacDecoder* dec) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_end_input(dec);
}

int bac_flac_decoder_finish(BacFlacDecoder* dec, BacFlacMd5Verdict* verdict) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  try {
    return flac_decoder_finish(dec, verdict);
  } catch (const std::bad_alloc&) {
    return AAC_ERR_MEMORY;
  }
}
#endif /* BAC_AAC_HAVE_DECODER */

#if defined(BAC_AAC_HAVE_ENCODER)
/* ── Encoder API ───────────────────────────────────────────────── */

void bac_flac_encoder_options_init(BacFlacEncoderOptions* options) {
  if (!options) {
    return;
  }
  options->padding_bytes = 0;
  options->total_samples_hint = 0;
  options->seek_table = 0;
  options->comments = nullptr;
  options->comment_count = 0;
  options->vendor = nullptr;
  options->pictures = nullptr;
  options->picture_count = 0;
}

BacFlacEncoder* bac_flac_encoder_create(int sample_rate, int channels, int bits_per_sample,
                                          int level, int* error) {
  int result = flac_encoder_check(sample_rate, channels, bits_per_sample, level);
  BacFlacEncoder* enc = nullptr;
  if (result == AAC_OK) {
    try {
      enc = flac_encoder_create(sample_rate, channels, bits_per_sample, level);
    } catch (const std::bad_alloc&) {
      result = AAC_ERR_MEMORY;
    }
  }
  if (error) {
    *error = result;
  }
  return enc;
}

void bac_flac_encoder_destroy(BacFlacEncoder* enc) {
  if (enc) {
    flac_encoder_destroy(enc);
  }
}

int bac_flac_encoder_set_options(BacFlacEncoder* enc, const BacFlacEncoderOptions* options) {
  if (!enc || !options) {
    return AAC_ERR_INVALID_ARG;
  }
  try {
    return flac_encoder_set_options(enc, options);
  } catch (const std::bad_alloc&) {
    return AAC_ERR_MEMORY;
  }
}

int bac_flac_encoder_block_size(BacFlacEncoder* enc) {
  if (!enc) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_encoder_block_size(enc);
}

int bac_flac_encoder_header_bytes(BacFlacEncoder* enc) {
  if (!enc) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_encoder_header_bytes(enc);
}

int bac_flac_encoder_write_header(BacFlacEncoder* enc, uint8_t* out, int out_size) {
  if (!enc || !out || out_size < 0) {
    return AAC_ERR_INVALID_ARG;
  }
  try {
    return flac_encoder_write_header(enc, out, out_size);
  } catch (const std::bad_alloc&) {
    return AAC_ERR_MEMORY;
  }
}

int bac_flac_encoder_max_output_bytes(BacFlacEncoder* enc, int n_samples) {
  if (!enc || n_samples < 0) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_encoder_max_output_bytes(enc, n_samples);
}

int bac_flac_encoder_encode(BacFlacEncoder* enc, const int32_t* pcm, int n_samples, uint8_t* out,
                             int out_size) {
  if (!enc || n_samples < 0 || (!pcm && n_samples > 0) || (!out && out_size > 0) || out_size < 0) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_encoder_encode(enc, pcm, n_samples, out, out_size);
}

int bac_flac_encoder_finish(BacFlacEncoder* enc, uint8_t* out, int out_size) {
  if (!enc || (!out && out_size > 0) || out_size < 0) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_encoder_finish(enc, out, out_size);
}

int bac_flac_encoder_last_frame_info(BacFlacEncoder* enc, BacFlacFrameInfo* info) {
  if (!enc || !info) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_encoder_last_frame_info(enc, info);
}

int bac_flac_encoder_final_header(BacFlacEncoder* enc, uint8_t* out, int out_size) {
  if (!enc || !out || out_size < 0) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_encoder_final_header(enc, out, out_size);
}

int bac_flac_encoder_stream_info(BacFlacEncoder* enc, BacFlacStreamInfo* info) {
  if (!enc || !info) {
    return AAC_ERR_INVALID_ARG;
  }
  flac_encoder_stream_info(enc, info);
  return AAC_OK;
}
#endif /* BAC_AAC_HAVE_ENCODER */
