/* FLAC decoder harness: two option bytes, an optional STREAMINFO block
 * given out of band, and a stream.
 *
 *   byte 0  bit 0 MD5 verification; bits 1-2 tags and pictures: not
 *           kept, kept up to the default limit, up to 64 bytes, up to
 *           4096 bytes; bit 3 the 34 bytes behind the options are a
 *           STREAMINFO handed to baac_flac_decoder_set_streaminfo;
 *           bit 4 the frame checksums are verified (clear: a frame is
 *           taken whatever its CRCs say, so that mutated bytes reach
 *           the code behind them, as RFC 9639 section 11 advises);
 *           bit 5 a seek reset half way through the input; bit 6 a
 *           reset at the end and the stream once more; bit 7 the input
 *           is not declared ended before the finish
 *   byte 1  bits 0-2 the size of the pieces the stream is fed in (1, 2,
 *           7, 64, 500, 4096, 65536, all at once); bit 3 a sample
 *           buffer too small for a large block, which the decoder has
 *           to refuse and keep; bits 4-7 unused
 *   rest    the stream
 *
 * Whatever the bytes, every return is one the API documents, a frame
 * has a block size and channels within the format's limits and fits
 * the buffer, the stream's properties stay inside their ranges, what
 * was kept of tags and pictures stays within the limit, every string
 * handed out ends with its zero byte, and the decoder comes to an end:
 * each call either takes input, produces a frame or an error, or says
 * that it needs more. */
#include <cstring>
#include <vector>

#include "flac.h"
#include "flac_internal.h"
#include "fuzz_driver.h"

namespace {

/* The most frames one input may decode, and the most samples: a few
 * bytes can stand for a block of half a million samples, and the
 * harness has to stay fast. */
const int kMaxFrames = 96;
const size_t kMaxSamples = 4u << 20;

int32_t g_pcm[(size_t)BAAC_FLAC_MAX_BLOCK_SIZE * BAAC_FLAC_MAX_CHANNELS];

struct Session {
  BaacFlacDecoder* dec;
  size_t pcm_size;
  size_t limit; /* of tags and pictures; 0: not kept */
  int frames = 0;
  size_t samples = 0;
  bool spent = false;

  /* Decodes what is ready.  Returns false when the decoder has stopped
   * for good or the budget of this input is used up. */
  bool drain() {
    for (;;) {
      const int n = baac_flac_decoder_decode_next(dec, g_pcm, (int)pcm_size);
      if (n == 0) {
        return true;
      }
      if (n == AAC_ERR_UNSUPPORTED || n == AAC_ERR_STATE || n == AAC_ERR_MEMORY) {
        return false;
      }
      if (n == AAC_ERR_OVERFLOW) {
        /* The frame stays pending: with room for any frame it comes. */
        if (pcm_size == sizeof(g_pcm) / sizeof(g_pcm[0])) {
          fuzz_fail("a frame larger than the largest there is", n);
        }
        pcm_size = sizeof(g_pcm) / sizeof(g_pcm[0]);
        continue;
      }
      if (n == AAC_ERR_DECODE) {
        continue;
      }
      if (n < 0 || n > BAAC_FLAC_MAX_BLOCK_SIZE) {
        fuzz_fail("decode_next return outside the documented values", n);
      }
      BaacFlacStreamInfo info;
      BaacFlacFrameInfo frame;
      if (baac_flac_decoder_stream_info(dec, &info) != AAC_OK ||
          baac_flac_decoder_last_frame_info(dec, &frame) != AAC_OK) {
        fuzz_fail("a frame without properties", n);
      }
      if (info.channels < 1 || info.channels > BAAC_FLAC_MAX_CHANNELS ||
          info.bits_per_sample < BAAC_FLAC_MIN_BITS_PER_SAMPLE ||
          info.bits_per_sample > BAAC_FLAC_MAX_BITS_PER_SAMPLE || info.sample_rate < 0 ||
          info.sample_rate > BAAC_FLAC_MAX_SAMPLE_RATE) {
        fuzz_fail("stream properties outside the format", info.channels);
      }
      if (frame.block_size != n || frame.bytes <= 0 ||
          (size_t)n * (size_t)info.channels > pcm_size) {
        fuzz_fail("a frame that does not fit what it reports", frame.block_size);
      }
      /* Every sample is read, so that one left unwritten shows. */
      uint32_t all = 0;
      for (size_t i = 0; i < (size_t)n * (size_t)info.channels; i++) {
        all |= (uint32_t)g_pcm[i];
      }
      (void)all;
      frames++;
      samples += (size_t)n * (size_t)info.channels;
      if (frames >= kMaxFrames || samples >= kMaxSamples) {
        spent = true;
        return false;
      }
    }
  }

  /* Feeds the stream in pieces and decodes on the way. */
  bool feed(const uint8_t* data, size_t size, size_t piece) {
    size_t pos = 0;
    while (pos < size) {
      const size_t want = piece < size - pos ? piece : size - pos;
      const int took = baac_flac_decoder_feed(dec, data + pos, (int)want);
      if (took == AAC_ERR_STATE || took == AAC_ERR_MEMORY) {
        return false;
      }
      if (took < 0 || (size_t)took > want) {
        fuzz_fail("feed return outside the documented values", took);
      }
      pos += (size_t)took;
      if (baac_flac_decoder_pending_bytes(dec) < 0) {
        fuzz_fail("a negative count of pending bytes", 0);
      }
      const int before = frames;
      const int lost = baac_flac_decoder_lost_frames(dec);
      if (!drain()) {
        return false;
      }
      if (took == 0 && frames == before && baac_flac_decoder_lost_frames(dec) == lost) {
        fuzz_fail("the decoder neither takes input nor produces output", (long)pos);
      }
    }
    return true;
  }

  /* What the decoder kept of tags and pictures: within the limit, and
   * every byte of it readable. */
  void check_metadata() {
    const BaacFlacComment* comments = nullptr;
    BaacFlacComment vendor = {};
    const int count = baac_flac_decoder_comments(dec, &comments, &vendor);
    const BaacFlacPicture* pictures = nullptr;
    const int picture_count = baac_flac_decoder_pictures(dec, &pictures);
    if (count < 0 || picture_count < 0 || baac_flac_decoder_skipped_metadata(dec) < 0) {
      fuzz_fail("a negative count of tags or pictures", count);
    }
    if (limit == 0 && (count || picture_count || vendor.length)) {
      fuzz_fail("tags kept by a decoder that was not asked", count);
    }
    size_t held = vendor.length;
    uint32_t sum = 0;
    if (vendor.text[vendor.length] != 0) {
      fuzz_fail("a vendor string without its closing zero", 0);
    }
    for (uint32_t k = 0; k < vendor.length; k++) {
      sum += (uint8_t)vendor.text[k];
    }
    for (int i = 0; i < count; i++) {
      if (comments[i].text[comments[i].length] != 0) {
        fuzz_fail("a tag without its closing zero", i);
      }
      for (uint32_t k = 0; k < comments[i].length; k++) {
        sum += (uint8_t)comments[i].text[k];
      }
      held += comments[i].length;
    }
    for (int i = 0; i < picture_count; i++) {
      const BaacFlacPicture& p = pictures[i];
      sum += (uint32_t)strlen(p.mime_type) + (uint32_t)strlen(p.description);
      for (uint32_t k = 0; k < p.data_bytes; k++) {
        sum += p.data[k];
      }
      held += strlen(p.mime_type) + strlen(p.description) + p.data_bytes;
    }
    (void)sum;
    if (held > limit) {
      fuzz_fail("more tags and pictures kept than the limit allows", (long)held);
    }
  }
};

}  // namespace

extern "C" int LLVMFuzzerTestOneInput(const uint8_t* data, size_t size) {
  FuzzInput in(data, size);
  const uint8_t options = in.byte();
  const uint8_t feeding = in.byte();
  const uint8_t* streaminfo = nullptr;
  size_t streaminfo_size = 0;
  if (options & 0x08) {
    streaminfo_size = in.take(BAAC_FLAC_STREAMINFO_BYTES, &streaminfo);
  }

  Session s;
  s.dec = baac_flac_decoder_create();
  if (!s.dec) {
    return 0;
  }
  static const size_t kLimits[4] = {0, (size_t)BAAC_FLAC_DEFAULT_METADATA_BYTES, 64, 4096};
  s.limit = kLimits[options >> 1 & 3];
  s.pcm_size = (feeding & 0x08) ? 1024 : sizeof(g_pcm) / sizeof(g_pcm[0]);
  if (baac_flac_decoder_set_md5_check(s.dec, options & 1) != AAC_OK ||
      baac_flac_decoder_set_metadata(s.dec, s.limit ? 1 : 0, s.limit) != AAC_OK) {
    fuzz_fail("a fresh decoder refuses its settings", 0);
  }
  flac_decoder_ignore_checksums(s.dec, !(options & 0x10));
  if (streaminfo) {
    const int ret = baac_flac_decoder_set_streaminfo(s.dec, streaminfo, (int)streaminfo_size);
    if (ret != AAC_OK && ret != AAC_ERR_INVALID_ARG) {
      fuzz_fail("set_streaminfo return outside the documented codes", ret);
    }
  }

  static const size_t kPieces[8] = {1, 2, 7, 64, 500, 4096, 65536, (size_t)1 << 30};
  const size_t piece = kPieces[feeding & 7];
  const uint8_t* stream = in.rest();
  const size_t length = in.left();
  for (int pass = 0; pass < ((options & 0x40) ? 2 : 1) && !s.spent; pass++) {
    bool alive = true;
    if (options & 0x20) {
      alive = s.feed(stream, length / 2, piece);
      const int ret = baac_flac_decoder_seek_reset(s.dec);
      if (ret != AAC_OK && ret != AAC_ERR_STATE) {
        fuzz_fail("seek_reset return outside the documented codes", ret);
      }
      alive = alive && s.feed(stream + length / 2, length - length / 2, piece);
    } else {
      alive = s.feed(stream, length, piece);
    }
    if (alive && !(options & 0x80)) {
      baac_flac_decoder_end_input(s.dec);
      s.drain();
    }
    s.check_metadata();
    BaacFlacMd5Verdict verdict = BAAC_FLAC_MD5_UNKNOWN;
    const int finish = baac_flac_decoder_finish(s.dec, &verdict);
    if (finish != AAC_OK && finish != AAC_ERR_DECODE) {
      fuzz_fail("finish return outside the documented codes", finish);
    }
    if (verdict != BAAC_FLAC_MD5_UNKNOWN && verdict != BAAC_FLAC_MD5_MATCH &&
        verdict != BAAC_FLAC_MD5_MISMATCH) {
      fuzz_fail("an MD5 verdict outside its values", (long)verdict);
    }
    if (baac_flac_decoder_feed(s.dec, stream, length ? 1 : 0) != AAC_ERR_STATE && length) {
      fuzz_fail("a finished decoder takes input", 0);
    }
    s.check_metadata();
    const BaacFlacSeekPoint* points = nullptr;
    const int seek_points = baac_flac_decoder_seek_points(s.dec, &points);
    if (seek_points < 0 || seek_points > BAAC_FLAC_MAX_SEEK_POINTS) {
      fuzz_fail("more seek points than the decoder keeps", seek_points);
    }
    for (int i = 1; i < seek_points; i++) {
      if (points[i].sample_position <= points[i - 1].sample_position) {
        fuzz_fail("seek points out of order", i);
      }
    }
    if (baac_flac_decoder_reset(s.dec) != AAC_OK) {
      fuzz_fail("a reset that fails", 0);
    }
  }
  baac_flac_decoder_destroy(s.dec);
  return 0;
}
