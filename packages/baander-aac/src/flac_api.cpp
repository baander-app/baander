/* The public C API of the lossless mode (flac.h): argument checks around
 * the codec objects, and allocation failures turned into
 * AAC_ERR_MEMORY, so that no exception crosses the C boundary. */
#include <new>

#include "flac.h"
#include "flac_internal.h"

#if defined(BAAC_AAC_HAVE_DECODER)
/* ── Decoder API ───────────────────────────────────────────────── */

BaacFlacDecoder* baac_flac_decoder_create(void) { return flac_decoder_create(); }

void baac_flac_decoder_destroy(BaacFlacDecoder* dec) {
  if (dec) {
    flac_decoder_destroy(dec);
  }
}

int baac_flac_decoder_reset(BaacFlacDecoder* dec) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  flac_decoder_reset(dec);
  return AAC_OK;
}

int baac_flac_decoder_set_streaminfo(BaacFlacDecoder* dec, const uint8_t* streaminfo, int size) {
  if (!dec || !streaminfo || size != BAAC_FLAC_STREAMINFO_BYTES) {
    return AAC_ERR_INVALID_ARG;
  }
  try {
    return flac_decoder_set_streaminfo(dec, streaminfo);
  } catch (const std::bad_alloc&) {
    return AAC_ERR_MEMORY;
  }
}

int baac_flac_decoder_set_md5_check(BaacFlacDecoder* dec, int enabled) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_set_md5_check(dec, enabled != 0);
}

int baac_flac_decoder_feed(BaacFlacDecoder* dec, const uint8_t* data, int size) {
  if (!dec || size < 0 || (!data && size > 0)) {
    return AAC_ERR_INVALID_ARG;
  }
  try {
    return flac_decoder_feed(dec, data, size);
  } catch (const std::bad_alloc&) {
    return AAC_ERR_MEMORY;
  }
}

int baac_flac_decoder_decode_next(BaacFlacDecoder* dec, int32_t* pcm, int pcm_size) {
  if (!dec || !pcm || pcm_size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  try {
    return flac_decoder_decode_next(dec, pcm, pcm_size);
  } catch (const std::bad_alloc&) {
    return AAC_ERR_MEMORY;
  }
}

int baac_flac_decoder_pending_bytes(BaacFlacDecoder* dec) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_pending_bytes(dec);
}

int baac_flac_decoder_stream_info(BaacFlacDecoder* dec, BaacFlacStreamInfo* info) {
  if (!dec || !info) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_stream_info(dec, info);
}

int baac_flac_decoder_last_frame_info(BaacFlacDecoder* dec, BaacFlacFrameInfo* info) {
  if (!dec || !info) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_last_frame_info(dec, info);
}

int baac_flac_decoder_lost_frames(BaacFlacDecoder* dec) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_lost_frames(dec);
}

int baac_flac_decoder_seek_points(BaacFlacDecoder* dec, const BaacFlacSeekPoint** points) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_seek_points(dec, points);
}

int64_t baac_flac_decoder_first_frame_offset(BaacFlacDecoder* dec) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_first_frame_offset(dec);
}

int baac_flac_decoder_seek_reset(BaacFlacDecoder* dec) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_seek_reset(dec);
}

int baac_flac_decoder_end_input(BaacFlacDecoder* dec) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_decoder_end_input(dec);
}

int baac_flac_decoder_finish(BaacFlacDecoder* dec, BaacFlacMd5Verdict* verdict) {
  if (!dec) {
    return AAC_ERR_INVALID_ARG;
  }
  try {
    return flac_decoder_finish(dec, verdict);
  } catch (const std::bad_alloc&) {
    return AAC_ERR_MEMORY;
  }
}
#endif /* BAAC_AAC_HAVE_DECODER */
