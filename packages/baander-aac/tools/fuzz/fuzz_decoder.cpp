/* Decoder harness: the input is four option bytes, an optional
 * AudioSpecificConfig and a stream.
 *
 *   byte 0  concealment, output layout, limiter, legacy DRC, chunking
 *           (fuzz_decode_common.h)
 *   byte 1  bits 0-1: 0 the stream through the FIFO with the transport
 *           detected, 1 raw access units after an AudioSpecificConfig,
 *           2 one one-shot decode of the whole payload, 3 the FIFO with
 *           an explicit transport from bits 2-4 (ADTS, LOAS, LATM with
 *           and without in-band config, raw, detect); bits 5-6 the ELD
 *           downscale factor (1, 2, 4, 1); bit 7 960-sample frames
 *   byte 2  bits 0-2 the MPEG-D DRC effect (off, none, night .. general),
 *           bit 3 album mode, bit 4 loudness normalisation,
 *           bits 5-7 the target loudness from -31 to -10 LUFS
 *   byte 3  the AudioSpecificConfig length (mode 1 and 3), then the
 *           config bytes
 *   rest    the stream; in mode 1 access units with a 16-bit big-endian
 *           length each
 *
 * Every object type the decoder has is reachable: AAC-LC and the HE-AAC
 * pair in ADTS, LD, ELD, USAC and MPEG Surround in LOAS, and any of
 * them as raw access units with their config. */
#include "fuzz_decode_common.h"

extern "C" int LLVMFuzzerTestOneInput(const uint8_t* data, size_t size) {
  FuzzInput in(data, size);
  const uint8_t options = in.byte();
  const uint8_t transport = in.byte();
  const uint8_t drc = in.byte();
  const uint8_t asc_len = in.byte();

  FuzzDecoder d(48000, fuzz_output_channels(options));
  if (!d.dec) {
    return 0;
  }
  fuzz_apply_decoder_options(d.dec, options);
  const int mode = transport & 3;
  if (transport & 0x80) {
    aac_decoder_set_frame_length(d.dec, 960);
  }
  static const int kDownscale[4] = {1, 2, 4, 1};
  aac_decoder_set_eld_downscale(d.dec, kDownscale[transport >> 5 & 3]);
  aac_decoder_set_drc_effect(d.dec, (AacDrcEffect)((drc & 7) - 1), drc >> 3 & 1, 100, 100);
  aac_decoder_set_loudness(d.dec, drc >> 4 & 1, -3100 + 300 * (drc >> 5 & 7));

  if (mode == 1 || mode == 3) {
    const uint8_t* asc;
    const size_t got = in.take(asc_len, &asc);
    const int ret = aac_decoder_set_config(d.dec, asc, (int)got);
    if (ret != AAC_OK && ret != AAC_ERR_DECODE && ret != AAC_ERR_UNSUPPORTED &&
        ret != AAC_ERR_INVALID_ARG) {
      fuzz_fail("set_config return outside the documented codes", ret);
    }
  }
  if (mode == 3) {
    static const AacTransport kTransports[8] = {
        AAC_TRANSPORT_ADTS,      AAC_TRANSPORT_LOAS, AAC_TRANSPORT_LATM_MCP1, AAC_TRANSPORT_LATM_MCP0,
        AAC_TRANSPORT_RAW,       AAC_TRANSPORT_DETECT, AAC_TRANSPORT_ADTS,    AAC_TRANSPORT_LOAS};
    aac_decoder_set_transport(d.dec, kTransports[transport >> 2 & 7]);
  }
  const size_t chunk = (options & 0x80) ? 7 : in.left() + 1;
  switch (mode) {
    case 1: {
      /* Access units, each handed to the FIFO as one packet or to the
       * one-shot decode by turns, with a reset half way through a
       * long input so the history clears under a configuration. */
      int units = 0;
      while (in.left() >= 2) {
        const size_t len = in.u16();
        const uint8_t* au;
        const size_t got = in.take(len, &au);
        if (units == 50) {
          aac_decoder_reset(d.dec);
        }
        if (units & 1) {
          d.decode(au, (int)got);
        } else {
          d.stream(au, got, got + 1);
        }
        units++;
        if (d.calls >= FUZZ_MAX_DECODE_CALLS) {
          break;
        }
      }
      break;
    }
    case 2:
      d.decode(in.rest(), (int)in.left());
      /* The same bytes again after a reset: the second pass runs over
       * state the first one left (a configuration, SBR headers). */
      aac_decoder_reset(d.dec);
      d.decode(in.rest(), (int)in.left());
      break;
    default:
      d.stream(in.rest(), in.left(), chunk);
      break;
  }
  return 0;
}
