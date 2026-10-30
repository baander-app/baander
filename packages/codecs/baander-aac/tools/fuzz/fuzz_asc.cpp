/* AudioSpecificConfig harness: the parser over whole bytes, over a bit
 * offset and a signalled length as a StreamMuxConfig of audioMuxVersion
 * 1 gives it, the StreamMuxConfig reader itself, and the public
 * aac_decoder_set_config that applies the parsed configuration (the
 * USAC, MPEG Surround and ELD configurations reach their modules there).
 * A configuration the parser accepts and the writer can express is
 * written back and parsed again, and the two must agree on the core
 * fields.
 *
 *   byte 0  bits 0-1 the mode: 0 aac_asc_parse, 1 aac_asc_read_bits at a
 *           bit offset with a length, 2 StreamMuxConfig, 3 set_config
 *           and one access unit
 *           bits 2-4 the bit offset of mode 1
 *   bytes 1-2  the length in bits of mode 1 (0xFFFF: unknown), or the
 *           config length in bytes of mode 3
 *   rest    the configuration bytes (mode 3: then the access unit) */
#include <cstring>
#include <vector>

#include "asc.h"
#include "fuzz_driver.h"
#include "latm.h"

static void check_parse_return(int ret) {
  if (ret != AAC_OK && ret != AAC_ERR_DECODE && ret != AAC_ERR_UNSUPPORTED &&
      ret != AAC_ERR_INVALID_ARG && ret != AAC_ERR_STATE) {
    fuzz_fail("config parser return outside the documented codes", ret);
  }
}

static void check_fields(const AacAsc* a) {
  if (a->frame_length != 1024 && a->frame_length != 960 && a->frame_length != 512 &&
      a->frame_length != 480 && a->aot != AAC_AOT_USAC && a->aot != AAC_AOT_MPS) {
    fuzz_fail("parsed frame length outside the four lengths", a->frame_length);
  }
  if (a->sample_rate < 0) {
    fuzz_fail("negative sample rate", a->sample_rate);
  }
  if (a->layout.num_channels < 0 || a->layout.num_channels > AAC_MAX_CHANNELS) {
    fuzz_fail("layout channel count out of range", a->layout.num_channels);
  }
  if (a->num_ld_sbr_headers < 0 || a->num_ld_sbr_headers > AAC_ASC_MAX_LD_SBR_HEADERS) {
    fuzz_fail("LD-SBR header count out of range", a->num_ld_sbr_headers);
  }
  if (a->aot == AAC_AOT_USAC) {
    if (a->usac.num_elements < 0 || a->usac.num_elements > AAC_USAC_MAX_ELEMENTS) {
      fuzz_fail("USAC element count out of range", a->usac.num_elements);
    }
    if (a->usac.num_config_exts < 0 || a->usac.num_config_exts > AAC_USAC_MAX_CONFIG_EXTS) {
      fuzz_fail("USAC config extension count out of range", a->usac.num_config_exts);
    }
    for (int i = 0; i < a->usac.num_elements; i++) {
      if (a->usac.elem[i].ext_config_len < 0 ||
          a->usac.elem[i].ext_config_len > AAC_USAC_MAX_EXT_CONFIG) {
        fuzz_fail("USAC extension config length out of range", a->usac.elem[i].ext_config_len);
      }
    }
  }
}

/* The writer on an accepted configuration of a type the decoder plays
 * (AAC-LC with its SBR and PS signalling, ER AAC-LC, AAC-LD, AAC-ELD),
 * and the parse of its output.  The scalable types the parser also
 * reads (their layerNr included) are refused by the decoder and are not
 * the writer's to express. */
static void roundtrip(const AacAsc* a) {
  if (a->aot != AAC_AOT_LC && a->aot != AAC_AOT_ER_LC && a->aot != AAC_AOT_ER_LD &&
      a->aot != AAC_AOT_ER_ELD) {
    return;
  }
  uint8_t out[AAC_ASC_MAX_BYTES];
  const int len = aac_asc_write(a, out, (int)sizeof(out));
  if (len == AAC_ERR_INVALID_ARG || len == AAC_ERR_OVERFLOW) {
    return;
  }
  if (len <= 0 || len > (int)sizeof(out)) {
    fuzz_fail("writer length out of range", len);
  }
  AacAsc b;
  const int ret = aac_asc_parse(out, len, &b);
  if (ret != AAC_OK) {
    fuzz_fail("the writer's output does not parse", ret);
  }
  if (b.aot != a->aot || b.sample_rate != a->sample_rate || b.frame_length != a->frame_length ||
      b.layout.num_channels != a->layout.num_channels) {
    fuzz_fail("the writer's output parses to another configuration", b.aot);
  }
}

extern "C" int LLVMFuzzerTestOneInput(const uint8_t* data, size_t size) {
  FuzzInput in(data, size);
  const uint8_t options = in.byte();
  const uint32_t word = in.u16();
  const uint8_t* cfg = in.rest();
  const int cfg_len = (int)in.left();
  AacAsc a;
  memset(&a, 0, sizeof(a));
  switch (options & 3) {
    case 0: {
      const int ret = aac_asc_parse(cfg, cfg_len, &a);
      check_parse_return(ret);
      if (ret == AAC_OK) {
        check_fields(&a);
        roundtrip(&a);
      }
      break;
    }
    case 1: {
      AacBitReader r;
      aac_bitreader_init(&r, cfg, cfg_len);
      aac_bitreader_skip(&r, options >> 2 & 7);
      const int bits = word == 0xFFFF ? AAC_ASC_LENGTH_UNKNOWN : (int)word;
      const int ret = aac_asc_read_bits(&r, &a, bits);
      check_parse_return(ret);
      if (ret == AAC_OK) {
        check_fields(&a);
        if (bits >= 0 && r.pos > (options >> 2 & 7) + bits) {
          fuzz_fail("read_bits ran past the signalled length", r.pos);
        }
      }
      break;
    }
    case 2: {
      AacBitReader r;
      aac_bitreader_init(&r, cfg, cfg_len);
      AacLatmConfig l;
      memset(&l, 0, sizeof(l));
      const int ret = aac_latm_read_stream_mux_config(&r, &l);
      check_parse_return(ret);
      if (ret == AAC_OK) {
        check_fields(&l.asc);
        uint8_t out[AAC_ASC_MAX_BYTES + 32];
        AacBitWriter w;
        aac_bitwriter_init(&w, out, (int)sizeof(out));
        const int wret = aac_latm_write_stream_mux_config(&w, &l);
        if (wret != AAC_OK && wret != AAC_ERR_INVALID_ARG && wret != AAC_ERR_OVERFLOW) {
          fuzz_fail("StreamMuxConfig writer return outside the documented codes", wret);
        }
      }
      break;
    }
    default: {
      AacDecoderHandle dec = aac_decoder_create(48000, 0);
      if (!dec) {
        return 0;
      }
      const uint8_t* asc;
      const size_t got = in.take(word, &asc);
      const int ret = aac_decoder_set_config(dec, asc, (int)got);
      check_parse_return(ret);
      std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
      const int n = aac_decoder_decode(dec, in.rest(), (int)in.left(), pcm.data(), (int)pcm.size());
      if (!fuzz_known_return(n)) {
        fuzz_fail("decode return outside the documented codes", n);
      }
      if (n > 0) {
        fuzz_expect_finite(pcm.data(), (size_t)n * (size_t)aac_decoder_channels(dec),
                           "decoded output not finite");
      }
      aac_decoder_destroy(dec);
      break;
    }
  }
  return 0;
}
