/* The decode loop shared by the decoder and the MPEG Surround harnesses:
 * a decoder configured from option bytes, fed a byte stream or packets,
 * with every output checked finite and every return checked against the
 * documented codes. */
#ifndef BAANDER_AAC_FUZZ_DECODE_COMMON_H
#define BAANDER_AAC_FUZZ_DECODE_COMMON_H

#include <vector>

#include "fuzz_driver.h"

/* Caps the decode work of one input: a mutated stream can carry many
 * tiny frames, and the time matters more than the tail of a long one. */
#define FUZZ_MAX_DECODE_CALLS 400

struct FuzzDecoder {
  AacDecoderHandle dec = nullptr;
  std::vector<float> pcm;
  int calls = 0;

  explicit FuzzDecoder(int sample_rate, int channels)
      : dec(aac_decoder_create(sample_rate, channels)), pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8) {}
  ~FuzzDecoder() {
    if (dec) {
      aac_decoder_destroy(dec);
    }
  }
  FuzzDecoder(const FuzzDecoder&) = delete;
  FuzzDecoder& operator=(const FuzzDecoder&) = delete;

  /* The output of one decode call: the sample count, the channels and
   * the values within bounds; the readback calls afterwards. */
  void check(int ret) {
    if (!fuzz_known_return(ret)) {
      fuzz_fail("decoder return outside the documented codes", ret);
    }
    const int channels = aac_decoder_channels(dec);
    if (ret > 0) {
      if (ret > AAC_DECODER_MAX_OUTPUT) {
        fuzz_fail("more samples than AAC_DECODER_MAX_OUTPUT", ret);
      }
      if (channels < 1 || channels > 8) {
        fuzz_fail("output channel count out of range", channels);
      }
      fuzz_expect_finite(pcm.data(), (size_t)ret * (size_t)channels, "decoded output not finite");
    }
    const int stream_channels = aac_decoder_stream_channels(dec);
    if (stream_channels < 0 || stream_channels > 8) {
      fuzz_fail("stream channel count out of range", stream_channels);
    }
    int sbr = 0, ps = 0;
    aac_decoder_get_sbr_ps(dec, &sbr, &ps);
    aac_decoder_has_surround(dec);
    aac_decoder_delay(dec);
    aac_decoder_channel_mode(dec);
    aac_decoder_object_type(dec);
    aac_decoder_frame_size(dec);
    aac_decoder_sample_rate(dec);
    aac_decoder_output_sample_rate(dec);
    aac_decoder_program_ref_level(dec);
    aac_decoder_has_drc(dec);
    aac_decoder_last_frame_concealed(dec);
    aac_decoder_concealed_frames(dec);
    int program = 0, gain = 0;
    aac_decoder_loudness(dec, &program, &gain);
  }

  /* One-shot decode of one frame, element or access unit. */
  void decode(const uint8_t* data, int size) {
    if (calls++ >= FUZZ_MAX_DECODE_CALLS) {
      return;
    }
    check(aac_decoder_decode(dec, data, size, pcm.data(), (int)pcm.size()));
  }

  /* Feeds a byte range to the FIFO in `chunk`-sized pieces and drains it. */
  void stream(const uint8_t* data, size_t size, size_t chunk) {
    size_t pos = 0;
    for (;;) {
      if (pos < size) {
        const size_t n = chunk < size - pos ? chunk : size - pos;
        const int taken = aac_decoder_feed(dec, data + pos, (int)n);
        if (taken < 0) {
          fuzz_fail("feed refused", taken);
        }
        pos += (size_t)taken;
      }
      if (calls++ >= FUZZ_MAX_DECODE_CALLS) {
        return;
      }
      const int ret = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
      check(ret);
      if (ret == 0 && pos >= size) {
        return;
      }
      if (ret == AAC_ERR_UNSUPPORTED) {
        return;
      }
    }
  }
};

/* The first option byte of both harnesses: bits 0-1 concealment (3 is
 * interpolate as well), bits 2-4 the output layout, bit 5 the limiter,
 * bit 6 legacy MPEG-4 DRC, bit 7 feeding in 7-byte pieces instead of
 * one chunk. */
static inline void fuzz_apply_decoder_options(AacDecoderHandle dec, uint8_t options) {
  static const AacConcealment kConceal[4] = {AAC_CONCEAL_OFF, AAC_CONCEAL_NOISE,
                                             AAC_CONCEAL_INTERPOLATE, AAC_CONCEAL_INTERPOLATE};
  aac_decoder_set_concealment(dec, kConceal[options & 3]);
  if (options & 0x20) {
    aac_decoder_set_limiter(dec, 1, 1 + (options >> 2 & 3), 50 + 50 * (options >> 4 & 1));
  }
  if (options & 0x40) {
    aac_decoder_set_drc(dec, 1, 100, 100, (options & 0x10) ? 124 : -1, options >> 5 & 1);
  }
}

static inline int fuzz_output_channels(uint8_t options) {
  static const int kChannels[8] = {0, 1, 2, 6, 8, 0, 2, 3};
  return kChannels[options >> 2 & 7];
}

#endif /* BAANDER_AAC_FUZZ_DECODE_COMMON_H */
