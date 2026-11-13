/* MPEG Surround harness: the committed surround streams (the 5151, 5152
 * and 525 trees with the in-band header and the object type 30
 * configuration, and ELD v2) through the public decoder, so that the
 * SpatialSpecificConfig and SpatialFrame parsers and the spatial
 * decoder behind them see mutated payloads inside valid cores.
 *
 *   byte 0  concealment, output layout, limiter, legacy DRC, chunking
 *           (fuzz_decode_common.h); the layouts of interest are 0 (the
 *           stream's six channels), 2 (the downmix stays) and 6
 *   byte 1  bit 0 one-shot decode per LOAS frame instead of the FIFO,
 *           bit 1 a reset after the first ten frames
 *   rest    the LOAS stream
 *
 * The LOAS frames of a one-shot input are split by their sync word and
 * length, so a mutation inside one frame reaches the spatial payload
 * rather than the framing. */
#include "fuzz_decode_common.h"

extern "C" int LLVMFuzzerTestOneInput(const uint8_t* data, size_t size) {
  FuzzInput in(data, size);
  const uint8_t options = in.byte();
  const uint8_t mode = in.byte();

  FuzzDecoder d(48000, fuzz_output_channels(options));
  if (!d.dec) {
    return 0;
  }
  fuzz_apply_decoder_options(d.dec, options);
  const uint8_t* s = in.rest();
  const size_t n = in.left();
  if (!(mode & 1)) {
    d.stream(s, n, (options & 0x80) ? 7 : n + 1);
    return 0;
  }
  size_t pos = 0;
  int frames = 0;
  while (pos + 3 <= n && d.calls < FUZZ_MAX_DECODE_CALLS) {
    /* AudioSyncStream: 11 bits of sync word, 13 bits of length. */
    const bool sync = s[pos] == 0x56 && (s[pos + 1] & 0xE0) == 0xE0;
    size_t len = sync ? 3 + (((size_t)(s[pos + 1] & 0x1F) << 8) | s[pos + 2]) : n - pos;
    if (len == 0 || pos + len > n) {
      len = n - pos;
    }
    if ((mode & 2) && frames == 10) {
      aac_decoder_reset(d.dec);
    }
    d.decode(s + pos, (int)len);
    pos += len;
    frames++;
  }
  return 0;
}
