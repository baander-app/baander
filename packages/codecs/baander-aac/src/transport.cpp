#include "transport.h"

#include <algorithm>
#include <cstring>
#include <deque>
#include <vector>

#include "bitstream.h"
#if defined(BAAC_AAC_HAVE_DECODER)
#include "decoder.h"
#endif
#if defined(BAAC_AAC_HAVE_ENCODER)
#include "encoder.h"
#endif

namespace {

bool is_adts_sync(const uint8_t* d, int size) {
  return size >= 2 && d[0] == 0xFF && (d[1] & 0xF6) == 0xF0;
}

/* Whether the transport delivers one unit per feed (LATM elements and raw
 * access units) rather than a byte stream. */
bool is_packet_transport(AacTransport t) {
  return t == AAC_TRANSPORT_LATM_MCP1 || t == AAC_TRANSPORT_LATM_MCP0 || t == AAC_TRANSPORT_RAW;
}

}  // namespace

#if defined(BAAC_AAC_HAVE_DECODER)

/* ── decoder side ──────────────────────────────────────────────── */

struct AacDecoderTransport_ {
  AacTransport requested; /* what the caller set */
  AacTransport active;    /* the transport in use; DETECT until the data says */
  std::vector<uint8_t> fifo;
  size_t head;             /* first unread byte of fifo */
  std::deque<int> packets; /* sizes of the fed packets from head */
  int has_asc;
  AacLatmConfig latm; /* the configuration in use: in-band, or from aac_decoder_set_config */
  std::vector<uint8_t> au_buf; /* byte-aligned access units of a LATM element */
  int synced;    /* a frame has been decoded since the last loss */
  AacAdtsHeader last_hdr; /* header of the last decoded ADTS frame, while synced */
  int discarded; /* bytes skipped since the last frame */
};

namespace {

/* Whether a damaged frame is concealed rather than refused. */
int concealment_enabled(const AacDecoderState* s) { return s->conceal_mode != AAC_CONCEAL_OFF; }

/* The one place the frame length of a configuration reaches the decoder. */
int set_frame_length(AacDecoderState* s, int frame_length) {
  return aac_decoder_set_frame_length_internal(s, frame_length);
}

/* The block's PCM, mixed to the output layout and limited when the
 * caller asked for it. */
void emit_block(AacDecoderState* s, float* pcm, int out_channels) {
  aac_decoder_output(s, pcm);
  aac_decoder_apply_limiter(s, pcm, s->frame_size, out_channels);
}

/* Explicit SBR puts the stream in SBR mode (upsampling until data
 * arrives), explicit PS makes a single channel element a stereo pair. */
void preset_extension(AacDecoderState* s, const AacAsc* asc) {
  if (asc->sbr_present != 1) {
    return;
  }
  if (!s->sbr_mode) {
    s->sbr_mode = 1;
    s->frame_size *= 2;
  }
  const bool mono = s->layout_source != 0 && s->layout.num_elements == 1 &&
                    s->layout.elems[0].type == AAC_ELEM_SCE;
  if (asc->ps_present == 1 && mono) {
    s->ps_output = 1;
  }
}

/* Whether the decoder handles a configuration's object type and its
 * error resilience and low-delay options. */
bool asc_supported(const AacAsc* asc, int rate_index) {
  const bool low_delay = asc->aot == AAC_AOT_ER_LD || asc->aot == AAC_AOT_ER_ELD;
  if (asc->aot != AAC_AOT_LC && asc->aot != AAC_AOT_ER_LC && !low_delay) {
    return false;
  }
  if (rate_index < 0 || (low_delay && !aac_low_delay_rate_supported(rate_index))) {
    return false;
  }
  if (aac_aot_is_er(asc->aot)) {
    /* The fixed element order of the error resilient syntax needs a
     * channel configuration. */
    if (asc->channel_config < 1 || asc->channel_config > 7) {
      return false;
    }
  }
  return true;
}

/* Whether the decoder can take the LD-SBR part of an AAC-ELD
 * configuration, checked before anything of the configuration is
 * applied: no downscale factor is set, the configuration carries one
 * header per SCE or CPE of its channel configuration, and every header's
 * band tables build at the configuration's rate and frame length. */
int check_ld_sbr(const AacDecoderState* s, const AacAsc* asc) {
  if (asc->aot != AAC_AOT_ER_ELD || !asc->ld_sbr_present) {
    return AAC_OK;
  }
  if (s->eld_downscale != 1) {
    return AAC_ERR_UNSUPPORTED; /* the CLDFB chain has no downscaled form */
  }
  AacLayout l = {};
  if (aac_layout_from_config(&l, asc->channel_config)) {
    return AAC_ERR_UNSUPPORTED;
  }
  int needed = 0;
  for (int i = 0; i < l.num_elements; i++) {
    needed += l.elems[i].type == AAC_ELEM_SCE || l.elems[i].type == AAC_ELEM_CPE;
  }
  if (needed > asc->num_ld_sbr_headers || needed > AAC_ASC_MAX_LD_SBR_HEADERS) {
    return AAC_ERR_UNSUPPORTED;
  }
  for (int i = 0; i < needed; i++) {
    const int ret = aac_sbr_ld_header_check(&asc->ld_sbr_header[i], asc->sample_rate,
                                            asc->frame_length, asc->ld_sbr_sampling_rate);
    if (ret) {
      return ret;
    }
  }
  return AAC_OK;
}

/* Applies a configuration: sample rate, layout, object type, frame length
 * and the extension modes.  Repeating the same configuration changes
 * nothing.  Everything that can be refused is checked first, so a refused
 * configuration leaves the decoder as it was; should a step still fail
 * while it is applied, the decoder is left without a layout, so that the
 * following frames wait for a configuration instead of decoding against
 * a half-applied one. */
int apply_asc(AacDecoderState* s, const AacAsc* asc) {
  const int index = aac_asc_rate_index(asc->sample_rate);
  if (!asc_supported(asc, index)) {
    return AAC_ERR_UNSUPPORTED;
  }
  if (asc->sbr_present == 1 && asc->ext_sample_rate != 2 * asc->sample_rate) {
    return AAC_ERR_UNSUPPORTED;
  }
  int ret = check_ld_sbr(s, asc);
  if (ret) {
    return ret;
  }
  ret = aac_decoder_set_rate_index(s, index);
  if (ret == 0) {
    if (asc->channel_config != 0) {
      ret = aac_decoder_set_channel_config(s, asc->channel_config);
    } else if (asc->has_pce) {
      ret = aac_decoder_set_layout(s, &asc->layout);
    }
  }
  if (ret == 0) {
    ret = aac_decoder_set_object_type(s, asc->aot);
  }
  if (ret == 0) {
    ret = set_frame_length(s, asc->frame_length);
  }
  if (ret == 0) {
    /* The error resilience tools of an error resilient stream (the flags
     * mean nothing to the plain syntax). */
    const bool er = aac_aot_is_er(asc->aot);
    s->section_resilience = er && asc->section_resilience;
    s->scalefactor_resilience = er && asc->scalefactor_resilience;
    s->spectral_resilience = er && asc->spectral_resilience;
    /* AAC-ELD: the low-delay SBR of the stream, configured last so that
     * it sees the final rate, layout and frame length. */
    const bool ld_sbr = asc->aot == AAC_AOT_ER_ELD && asc->ld_sbr_present;
    const int num_headers = std::min(asc->num_ld_sbr_headers, AAC_ASC_MAX_LD_SBR_HEADERS);
    ret = aac_decoder_set_ld_sbr(s, ld_sbr ? 1 : 0, asc->ld_sbr_sampling_rate, asc->ld_sbr_crc,
                                 asc->ld_sbr_header, num_headers);
  }
  if (ret) {
    aac_decoder_set_ld_sbr(s, 0, 0, 0, nullptr, 0);
    s->layout_source = 0;
    return ret;
  }
  preset_extension(s, asc);
  return AAC_OK;
}

/* Decodes one raw_data_block() from the reader and appends its PCM to
 * pcm[*written..]. */
int decode_block(AacDecoderState* s, AacBitReader* r, float* pcm, int pcm_size, int* written) {
  if (s->layout_source != 0 &&
      (*written + s->frame_size) * aac_decoder_output_channels(s) > pcm_size) {
    return AAC_ERR_OVERFLOW; /* known before the state advances, so the frame can be retried */
  }
  const int decoded = aac_decode_raw_data_block(s, r);
  if (decoded < 0) {
    return decoded;
  }
  const int out_channels = aac_decoder_output_channels(s);
  if ((*written + s->frame_size) * out_channels > pcm_size) {
    return AAC_ERR_OVERFLOW;
  }
  emit_block(s, pcm + (size_t)*written * out_channels, out_channels);
  *written += s->frame_size;
  return 0;
}

/* Replaces the block that would start at pcm[*written..] with a
 * concealed one. */
int conceal_block(AacDecoderState* s, float* pcm, int pcm_size, int* written) {
  const int ret = aac_decoder_conceal_block(s);
  if (ret < 0) {
    return ret;
  }
  const int out_channels = aac_decoder_output_channels(s);
  if ((*written + s->frame_size) * out_channels > pcm_size) {
    return AAC_ERR_OVERFLOW;
  }
  emit_block(s, pcm + (size_t)*written * out_channels, out_channels);
  *written += s->frame_size;
  return 0;
}

/* One ADTS frame: every raw data block in it, with the CRC verified when
 * the header carries one. */
int decode_adts_frame(AacDecoderState* s, const uint8_t* data, int size, float* pcm,
                      int pcm_size) {
  AacAdtsHeader hdr;
  if (aac_adts_parse(&hdr, data, size) != 0 || hdr.frame_length > size) {
    return AAC_ERR_DECODE;
  }
  if (hdr.object_type != AAC_AOT_LC) {
    return AAC_ERR_UNSUPPORTED;
  }
  int ret = 0;
  if ((ret = aac_decoder_set_object_type(s, AAC_AOT_LC)) != 0 ||
      (ret = aac_decoder_set_rate_index(s, hdr.sample_rate_index)) != 0 ||
      (ret = aac_decoder_set_channel_config(s, hdr.channel_config)) != 0) {
    return ret;
  }
  const int blocks = hdr.num_raw_blocks;
  const int crc = !hdr.protection_absent;
  const int header_bits = 8 * AAC_ADTS_HEADER_SIZE + (crc ? 16 * (blocks - 1) : 0);
  const int begin = header_bits / 8 + (crc ? 2 : 0);
  const int end = hdr.frame_length;
  if (begin > end) {
    return AAC_ERR_DECODE;
  }
  const int conceal = concealment_enabled(s);
  /* With several blocks the header and its position words have a CRC of
   * their own; a mismatch loses the whole frame. */
  bool header_bad = false;
  if (crc && blocks > 1) {
    const uint16_t expect = (uint16_t)((data[header_bits / 8] << 8) | data[header_bits / 8 + 1]);
    header_bad = aac_adts_crc(data, header_bits, nullptr, nullptr, 0) != expect;
    if (header_bad && !conceal) {
      return AAC_ERR_DECODE;
    }
  }
  AacBitReader reader;
  aac_bitreader_init(&reader, data + begin, end - begin);
  int written = 0;
  for (int b = 0; b < blocks; b++) {
    if (header_bad) {
      if ((ret = conceal_block(s, pcm, pcm_size, &written)) != 0) {
        return ret;
      }
      continue;
    }
    const int block_start = written;
    ret = decode_block(s, &reader, pcm, pcm_size, &written);
    if (ret) {
      if (!conceal || ret == AAC_ERR_OVERFLOW || ret == AAC_ERR_STATE) {
        return ret;
      }
      /* The blocks decoded so far stay; this one and the rest, whose
       * positions are lost with it, are concealed. */
      header_bad = true;
      written = block_start;
      if ((ret = conceal_block(s, pcm, pcm_size, &written)) != 0) {
        return ret;
      }
      continue;
    }
    if (crc) {
      uint16_t expect;
      uint16_t actual;
      if (blocks == 1) {
        expect = (uint16_t)((data[AAC_ADTS_HEADER_SIZE] << 8) | data[AAC_ADTS_HEADER_SIZE + 1]);
        actual = aac_adts_crc(data, header_bits, data + begin, s->crc_region, s->num_crc_regions);
      } else {
        expect = (uint16_t)aac_bitreader_read(&reader, 16); /* adts_raw_data_block_error_check */
        actual = aac_adts_crc(nullptr, 0, data + begin, s->crc_region, s->num_crc_regions);
      }
      if (actual != expect) {
        if (!conceal) {
          return AAC_ERR_DECODE;
        }
        /* The block's data is suspect: its output is replaced by a
         * concealed block, counted once even when the decoder already
         * concealed part of it. */
        if (s->last_concealed) {
          s->concealed_frames--;
        }
        written = block_start;
        if ((ret = conceal_block(s, pcm, pcm_size, &written)) != 0) {
          return ret;
        }
      }
    }
  }
  return written;
}

/* One raw access unit. */
int decode_raw_au(AacDecoderState* s, const uint8_t* data, int size, float* pcm, int pcm_size) {
  AacBitReader reader;
  aac_bitreader_init(&reader, data, size);
  int written = 0;
  const int ret = decode_block(s, &reader, pcm, pcm_size, &written);
  return ret ? ret : written;
}

/* One AudioMuxElement: its configuration, when it carries one, is
 * applied, then every access unit in it is decoded.  AAC_ERR_STATE when
 * no configuration is known yet. */
int decode_latm_element(AacDecoderState* s, AacDecoderTransport* t, const uint8_t* data, int size,
                        int mux_config_present, float* pcm, int pcm_size) {
  AacLatmPayload payloads[AAC_LATM_MAX_SUBFRAMES];
  if (t->au_buf.size() < (size_t)size) {
    t->au_buf.resize((size_t)size);
  }
  /* An in-band configuration replaces the one in use only once the whole
   * element parses and the decoder accepts its AudioSpecificConfig. */
  AacLatmConfig cfg = t->latm;
  const int count = aac_latm_read_mux_element(data, size, mux_config_present, &cfg,
                                              t->au_buf.data(), size, payloads,
                                              AAC_LATM_MAX_SUBFRAMES, nullptr);
  if (count < 0) {
    return count;
  }
  const bool carried_config = mux_config_present && (data[0] & 0x80) == 0;
  if (carried_config) {
    const int ret = apply_asc(s, &cfg.asc);
    if (ret) {
      return ret;
    }
  }
  t->latm = cfg;
  int written = 0;
  for (int i = 0; i < count; i++) {
    AacBitReader reader;
    aac_bitreader_init(&reader, t->au_buf.data() + payloads[i].offset, payloads[i].size);
    const int ret = decode_block(s, &reader, pcm, pcm_size, &written);
    if (ret) {
      return ret;
    }
  }
  return written;
}

/* Whether `data` is one complete AudioMuxElement(1) whose configuration
 * this decoder handles: the test for LATM when nothing else says. */
bool looks_like_latm(const uint8_t* data, int size, AacLatmConfig* cfg) {
  if (size < 4 || (data[0] & 0x80) != 0) {
    return false;
  }
  AacLatmPayload payloads[AAC_LATM_MAX_SUBFRAMES];
  std::vector<uint8_t> scratch((size_t)size);
  AacLatmConfig c = {};
  int used = 0;
  const int count = aac_latm_read_mux_element(data, size, 1, &c, scratch.data(), size, payloads,
                                              AAC_LATM_MAX_SUBFRAMES, &used);
  if (count <= 0 || used != size || !asc_supported(&c.asc, aac_asc_rate_index(c.asc.sample_rate))) {
    return false;
  }
  *cfg = c;
  return true;
}

/* Decodes one frame of a known transport. */
int decode_frame(AacDecoderState* s, AacDecoderTransport* t, AacTransport kind, const uint8_t* data,
                 int size, float* pcm, int pcm_size) {
  switch (kind) {
    case AAC_TRANSPORT_ADTS:
      return decode_adts_frame(s, data, size, pcm, pcm_size);
    case AAC_TRANSPORT_LOAS: {
      if (!aac_loas_is_sync(data, size) || size < AAC_LOAS_HEADER_BYTES) {
        return AAC_ERR_DECODE;
      }
      const int len = aac_loas_payload_length(data);
      if (len == 0 || AAC_LOAS_HEADER_BYTES + len > size) {
        return AAC_ERR_DECODE;
      }
      return decode_latm_element(s, t, data + AAC_LOAS_HEADER_BYTES, len, 1, pcm, pcm_size);
    }
    case AAC_TRANSPORT_LATM_MCP1:
      return decode_latm_element(s, t, data, size, 1, pcm, pcm_size);
    case AAC_TRANSPORT_LATM_MCP0:
      return decode_latm_element(s, t, data, size, 0, pcm, pcm_size);
    case AAC_TRANSPORT_RAW:
      return decode_raw_au(s, data, size, pcm, pcm_size);
    default:
      return AAC_ERR_STATE;
  }
}

/* The transport of data that arrived without one being set: a sync word
 * at the front of `size` bytes, raw access units once a configuration is
 * known, or a LATM element when the first packet (`packet` bytes) is a
 * complete one. */
AacTransport detect_transport(AacDecoderTransport* t, const uint8_t* data, int size, int packet) {
  AacAdtsHeader hdr;
  if (is_adts_sync(data, size) && aac_adts_parse(&hdr, data, size) == 0) {
    return AAC_TRANSPORT_ADTS;
  }
  if (aac_loas_is_sync(data, size)) {
    return AAC_TRANSPORT_LOAS;
  }
  if (t->has_asc) {
    return AAC_TRANSPORT_RAW;
  }
  AacLatmConfig cfg;
  if (looks_like_latm(data, packet, &cfg)) {
    return AAC_TRANSPORT_LATM_MCP1;
  }
  return AAC_TRANSPORT_DETECT;
}

/* ── FIFO ─────────────────────────────────────────────────────── */

int pending(const AacDecoderTransport* t) { return (int)(t->fifo.size() - t->head); }

const uint8_t* fifo_data(const AacDecoderTransport* t) { return t->fifo.data() + t->head; }

void consume(AacDecoderTransport* t, int n) {
  n = std::min(n, pending(t));
  t->head += (size_t)n;
  while (n > 0 && !t->packets.empty()) {
    if (t->packets.front() <= n) {
      n -= t->packets.front();
      t->packets.pop_front();
    } else {
      t->packets.front() -= n;
      n = 0;
    }
  }
  if (t->head == t->fifo.size()) {
    t->fifo.clear();
    t->head = 0;
  } else if (t->head >= 32768) {
    t->fifo.erase(t->fifo.begin(), t->fifo.begin() + (long)t->head);
    t->head = 0;
  }
}

/* The first packet's size, or everything pending when the boundaries
 * were lost. */
int first_packet(const AacDecoderTransport* t) {
  if (t->packets.empty()) {
    return pending(t);
  }
  return std::min(t->packets.front(), pending(t));
}

/* Whether two ADTS headers describe the same stream: rate, layer, profile
 * and channel configuration. */
bool same_stream(const AacAdtsHeader* a, const AacAdtsHeader* b) {
  return a->sample_rate_index == b->sample_rate_index && a->layer == b->layer &&
         a->object_type == b->object_type && a->channel_config == b->channel_config;
}

/* Whether the `avail` bytes at `d` begin with a header that agrees with
 * `hdr`, as the frame after a real one must.  With fewer bytes than a
 * header only the sync word is checked, and `partial` says so. */
bool next_header_agrees(AacTransport kind, const uint8_t* d, int avail, const AacAdtsHeader* hdr,
                        bool* partial) {
  const int header = kind == AAC_TRANSPORT_ADTS ? AAC_ADTS_HEADER_SIZE : AAC_LOAS_HEADER_BYTES;
  *partial = avail < header;
  if (kind == AAC_TRANSPORT_LOAS) {
    return d[0] == 0x56 && (avail < 2 || (d[1] & 0xE0) == 0xE0);
  }
  if (d[0] != 0xFF || (avail >= 2 && (d[1] & 0xF6) != 0xF0)) {
    return false;
  }
  if (*partial) {
    return true;
  }
  AacAdtsHeader next;
  return aac_adts_parse(&next, d, avail) == 0 && same_stream(&next, hdr);
}

/* Finds the next ADTS or LOAS frame: a header that parses and, when bytes
 * follow the frame, a next header that agrees with it.  The earliest
 * candidate whose frame is not complete yet is waited for, unless a
 * later complete frame is confirmed by a whole successor header, which
 * shows the earlier one was a false sync in damaged bytes.  A frame at
 * the front of the buffer that continues the stream (the same parameters
 * as the frame just decoded, or the first frame with nothing discarded)
 * is taken without that confirmation, so that a frame followed by
 * garbage, by the end of the data or by a partial header is not lost.
 * Returns 1 with the frame's offset and length; 0 when more bytes are
 * needed (with the offset of the candidate) or nothing was found (offset
 * is what can be discarded). */
int find_frame(const AacDecoderTransport* t, AacTransport kind, int* offset, int* length,
               int* confirmed_out) {
  const uint8_t* d = fifo_data(t);
  const int avail = pending(t);
  const int header = kind == AAC_TRANSPORT_ADTS ? AAC_ADTS_HEADER_SIZE : AAC_LOAS_HEADER_BYTES;
  int pending_at = -1;
  int deferred_len = 0;
  int deferred_end = -1;
  bool deferred_ok = false;
  for (int i = 0; i + header <= avail; i++) {
    AacAdtsHeader hdr = {};
    int len;
    if (kind == AAC_TRANSPORT_ADTS) {
      if (!is_adts_sync(d + i, avail - i) || aac_adts_parse(&hdr, d + i, avail - i) != 0) {
        continue;
      }
      len = hdr.frame_length;
    } else {
      if (!aac_loas_is_sync(d + i, avail - i)) {
        continue;
      }
      len = AAC_LOAS_HEADER_BYTES + aac_loas_payload_length(d + i);
      if (len == AAC_LOAS_HEADER_BYTES) {
        continue;
      }
    }
    const int end = i + len;
    if (end > avail) {
      if (pending_at < 0) {
        pending_at = i;
      }
      continue;
    }
    const bool continues =
        i == 0 && (t->synced ? kind != AAC_TRANSPORT_ADTS || same_stream(&hdr, &t->last_hdr)
                             : t->discarded == 0);
    bool confirmed = false;
    if (end < avail) {
      bool partial = false;
      const bool agrees = next_header_agrees(kind, d + end, avail - end, &hdr, &partial);
      if (!agrees) {
        /* A continuing frame whose successor is not a header is either
         * truncated (a confirmed frame then starts inside its claimed
         * extent) or followed by garbage: the scan decides, or the
         * frame is taken once enough bytes have come without a frame. */
        if (continues && deferred_end < 0) {
          deferred_len = len;
          deferred_end = end;
        }
        continue;
      }
      confirmed = agrees && !partial;
    }
    if (!confirmed && !continues) {
      /* Not provable yet: wait for the bytes that would confirm it. */
      if (pending_at < 0) {
        pending_at = i;
      }
      continue;
    }
    if (deferred_end >= 0 && i >= deferred_end) {
      deferred_ok = true; /* the deferred frame was whole after all */
      break;
    }
    *offset = i;
    *length = len;
    *confirmed_out = confirmed;
    return 1;
  }
  if (deferred_end >= 0) {
    /* Nothing after the deferred frame contradicts it; once two largest
     * frames' worth of bytes have passed without any frame, it is taken
     * rather than stalling on a garbage trailer. */
    *offset = 0;
    *length = deferred_len;
    *confirmed_out = 0;
    return deferred_ok || avail - deferred_end >= 2 * AAC_LOAS_MAX_PAYLOAD ? 1 : 0;
  }
  *offset = pending_at >= 0 ? pending_at : std::max(0, avail - (header - 1));
  return 0;
}

}  // namespace

AacDecoderTransport* aac_decoder_transport_create(void) {
  auto* t = new AacDecoderTransport_();
  t->requested = AAC_TRANSPORT_DETECT;
  t->active = AAC_TRANSPORT_DETECT;
  t->head = 0;
  t->has_asc = 0;
  t->synced = 0;
  t->discarded = 0;
  memset(&t->latm, 0, sizeof(t->latm));
  /* Sized once for any frame the FIFO can deliver; only a larger element
   * handed to the one-shot decode grows it. */
  t->au_buf.resize(AAC_TRANSPORT_MAX_FRAME_BYTES);
  t->fifo.reserve(AAC_TRANSPORT_FIFO_BYTES);
  return t;
}

void aac_decoder_transport_destroy(AacDecoderTransport* t) { delete t; }

void aac_decoder_transport_reset(AacDecoderTransport* t) {
  t->active = t->requested;
  if (t->active == AAC_TRANSPORT_DETECT && t->has_asc) {
    t->active = AAC_TRANSPORT_RAW;
  }
  t->fifo.clear();
  t->head = 0;
  t->packets.clear();
  t->synced = 0;
  t->discarded = 0;
}

int aac_decoder_transport_set(AacDecoderTransport* t, AacTransport transport) {
  if (transport < AAC_TRANSPORT_DETECT || transport > AAC_TRANSPORT_RAW) {
    return AAC_ERR_INVALID_ARG;
  }
  t->requested = transport;
  aac_decoder_transport_reset(t);
  return AAC_OK;
}

int aac_decoder_transport_set_config(AacDecoderState* s, AacDecoderTransport* t,
                                     const uint8_t* asc, int size) {
  AacAsc parsed;
  int ret = aac_asc_parse(asc, size, &parsed);
  if (ret) {
    return ret;
  }
  if ((ret = apply_asc(s, &parsed)) != 0) {
    return ret;
  }
  t->has_asc = 1;
  aac_latm_config_init(&t->latm, &parsed, 0);
  if (t->requested == AAC_TRANSPORT_DETECT) {
    t->active = AAC_TRANSPORT_RAW;
  }
  return AAC_OK;
}

void aac_decoder_transport_reapply(AacDecoderState* s, const AacDecoderTransport* t) {
  if (t->latm.valid) {
    preset_extension(s, &t->latm.asc);
  }
}

int aac_decoder_transport_feed(AacDecoderTransport* t, const uint8_t* data, int size) {
  if (!data || size < 0) {
    return AAC_ERR_INVALID_ARG;
  }
  if (size == 0) {
    return 0;
  }
  const int room = AAC_TRANSPORT_FIFO_BYTES - pending(t);
  const AacTransport kind = t->active != AAC_TRANSPORT_DETECT ? t->active : t->requested;
  const bool packet = is_packet_transport(kind) || (kind == AAC_TRANSPORT_DETECT && t->has_asc);
  int take = std::min(size, room);
  if (packet && take < size) {
    return 0; /* a packet goes in whole or not at all */
  }
  if (take <= 0) {
    return 0;
  }
  t->fifo.insert(t->fifo.end(), data, data + take);
  t->packets.push_back(take);
  return take;
}

int aac_decoder_transport_pending(const AacDecoderTransport* t) { return pending(t); }

/* decode_frame with the frame-level failures handled: a frame the decoder
 * could not take apart (a damaged header, a truncated or unparseable
 * frame) becomes one concealed block once the layout is known, or the
 * error with concealment off, in which case the following frames' delta
 * coded data is treated as unreliable. */
int decode_frame_or_conceal(AacDecoderState* s, AacDecoderTransport* t, AacTransport kind,
                            const uint8_t* data, int size, float* pcm, int pcm_size) {
  const int ret = decode_frame(s, t, kind, data, size, pcm, pcm_size);
  if (ret >= 0 || ret == AAC_ERR_OVERFLOW || ret == AAC_ERR_STATE) {
    return ret;
  }
  if (!concealment_enabled(s)) {
    aac_decoder_note_lost_block(s);
    return ret;
  }
  int written = 0;
  if (conceal_block(s, pcm, pcm_size, &written) < 0) {
    return ret;
  }
  return written;
}

int aac_decoder_transport_decode_next(AacDecoderState* s, AacDecoderTransport* t, float* pcm,
                                      int pcm_size) {
  s->last_concealed = 0;
  for (;;) {
    const int avail = pending(t);
    if (avail == 0) {
      return 0;
    }
    if (t->active == AAC_TRANSPORT_DETECT) {
      if (avail < AAC_ADTS_HEADER_SIZE && !t->has_asc) {
        return 0; /* too little to tell a header from garbage */
      }
      t->active = detect_transport(t, fifo_data(t), avail, first_packet(t));
      if (t->active == AAC_TRANSPORT_DETECT) {
        /* Nothing recognisable at the front: skip to the next possible
         * sync word, or drop the packet. */
        const uint8_t* d = fifo_data(t);
        int skip = avail - 1;
        for (int i = 1; i + 1 < avail; i++) {
          if (is_adts_sync(d + i, avail - i) || aac_loas_is_sync(d + i, avail - i)) {
            skip = i;
            break;
          }
        }
        if (skip <= 0) {
          return 0;
        }
        consume(t, skip);
        t->discarded += skip;
        continue;
      }
    }
    if (is_packet_transport(t->active)) {
      const int size = first_packet(t);
      const int ret = decode_frame_or_conceal(s, t, t->active, fifo_data(t), size, pcm, pcm_size);
      if (ret == AAC_ERR_OVERFLOW) {
        return ret; /* the packet waits for a larger buffer */
      }
      consume(t, size);
      if (ret == AAC_ERR_STATE) {
        continue; /* a LATM element before any configuration */
      }
      return ret;
    }
    int offset = 0;
    int length = 0;
    int confirmed = 0;
    const int found = find_frame(t, t->active, &offset, &length, &confirmed);
    if (offset > 0) {
      consume(t, offset);
      t->discarded += offset;
    }
    if (!found) {
      return 0;
    }
    if (t->discarded > 0 && t->synced && s->layout_source != 0 && concealment_enabled(s)) {
      /* Bytes went missing between two frames: one concealed frame stands
       * in for the lost one; the found frame waits for the next call. */
      t->discarded = 0;
      int written = 0;
      const int ret = conceal_block(s, pcm, pcm_size, &written);
      return ret ? ret : written;
    }
    t->discarded = 0;
    AacAdtsHeader hdr = {};
    if (t->active == AAC_TRANSPORT_ADTS) {
      aac_adts_parse(&hdr, fifo_data(t), length);
    }
    const int ret = decode_frame_or_conceal(s, t, t->active, fifo_data(t), length, pcm, pcm_size);
    if (ret == AAC_ERR_OVERFLOW) {
      return ret; /* the frame waits for a larger buffer */
    }
    if (ret < 0 && !confirmed) {
      /* A frame taken on trust that does not decode may have been cut
       * short: only its sync word goes, so that a real frame inside its
       * claimed extent is found by the next scan. */
      consume(t, 1);
    } else {
      consume(t, length);
    }
    if (ret == AAC_ERR_STATE) {
      continue; /* a LOAS frame before any configuration */
    }
    if (ret < 0) {
      t->synced = 0;
      return ret;
    }
    t->last_hdr = hdr;
    t->synced = 1;
    return ret;
  }
}

int aac_decoder_transport_decode(AacDecoderState* s, AacDecoderTransport* t, const uint8_t* data,
                                 int size, float* pcm, int pcm_size) {
  s->last_concealed = 0;
  AacTransport kind = t->requested;
  if (kind == AAC_TRANSPORT_DETECT) {
    kind = detect_transport(t, data, size, size);
    if (kind == AAC_TRANSPORT_DETECT) {
      kind = AAC_TRANSPORT_RAW;
    }
  }
  return decode_frame_or_conceal(s, t, kind, data, size, pcm, pcm_size);
}

#endif /* BAAC_AAC_HAVE_DECODER */

#if defined(BAAC_AAC_HAVE_ENCODER)

/* ── encoder side ──────────────────────────────────────────────── */

struct AacEncoderTransport_ {
  AacTransport transport;
  AacSignaling signaling;
  int crc;
  int frames; /* frames since the last flush */
};

AacEncoderTransport* aac_encoder_transport_create(void) {
  auto* t = new AacEncoderTransport_();
  t->transport = AAC_TRANSPORT_ADTS;
  t->signaling = AAC_SIGNAL_IMPLICIT;
  t->crc = 0;
  t->frames = 0;
  return t;
}

void aac_encoder_transport_destroy(AacEncoderTransport* t) { delete t; }

int aac_encoder_transport_set(AacEncoderTransport* t, AacTransport transport) {
  if (transport <= AAC_TRANSPORT_DETECT || transport > AAC_TRANSPORT_RAW) {
    return AAC_ERR_INVALID_ARG;
  }
  if (t->frames > 0) {
    return AAC_ERR_STATE;
  }
  t->transport = transport;
  return AAC_OK;
}

int aac_encoder_transport_set_signaling(AacEncoderTransport* t, AacSignaling signaling) {
  if (signaling < AAC_SIGNAL_IMPLICIT || signaling > AAC_SIGNAL_EXPLICIT_HIER) {
    return AAC_ERR_INVALID_ARG;
  }
  if (t->frames > 0) {
    return AAC_ERR_STATE;
  }
  t->signaling = signaling;
  return AAC_OK;
}

int aac_encoder_transport_set_crc(AacEncoderTransport* t, int enabled) {
  if (t->frames > 0) {
    return AAC_ERR_STATE;
  }
  t->crc = enabled ? 1 : 0;
  return AAC_OK;
}

int aac_encoder_transport_is_packet(const AacEncoderTransport* t) {
  return is_packet_transport(t->transport);
}

void aac_encoder_transport_flushed(AacEncoderTransport* t) { t->frames = 0; }

int aac_encoder_transport_asc(const AacEncoderState* s, const AacEncoderTransport* t,
                              AacAsc* asc) {
  aac_asc_init(asc, s->sample_rate, s->channel_config);
  if (s->channel_config == 0) {
    asc->has_pce = 1;
    asc->layout = s->layout;
  }
  const bool sbr = aac_encoder_aot_has_sbr(s->aot);
  asc->frame_length = sbr ? s->frame_size / 2 : s->frame_size;
  asc->signaling = t->signaling;
  if (s->aot == AAC_AOT_ER_LD || s->aot == AAC_AOT_ER_ELD) {
    asc->aot = s->aot;
    asc->frame_length = s->frame_length;
  }
  if (s->ld_sbr) {
    /* AAC-ELD with LD-SBR: the sampling frequency is the core's, and the
     * config carries the header of every SCE and CPE in element order.
     * A layout with more of them than the config can hold has no valid
     * config, so it is refused rather than written short. */
    if (aac_encoder_ld_sbr_header_count(s) > AAC_ASC_MAX_LD_SBR_HEADERS) {
      return AAC_ERR_UNSUPPORTED;
    }
    asc->ld_sbr_present = 1;
    asc->ld_sbr_sampling_rate = s->ld_sbr_dual_rate;
    asc->ld_sbr_crc = s->ld_sbr_crc;
    asc->num_ld_sbr_headers = 0;
    for (int e = 0; e < s->num_elements; e++) {
      if (s->elem[e].type == AAC_ELEM_LFE) {
        continue;
      }
      asc->ld_sbr_header[asc->num_ld_sbr_headers++] = *aac_sbr_ld_enc_header(s->ld_sbr_enc[e]);
    }
  }
  if (sbr) {
    asc->sbr_present = 1;
    asc->ps_present = s->aot == AAC_AOT_PS ? 1 : -1;
    asc->ext_sample_rate = s->input_rate;
  }
  return AAC_OK;
}

int aac_encoder_transport_max_bytes(const AacEncoderState* s, const AacEncoderTransport* t) {
  const int adts = aac_encoder_state_max_frame_bytes(s);
  switch (t->transport) {
    case AAC_TRANSPORT_ADTS:
      return adts + (t->crc ? 2 : 0);
    case AAC_TRANSPORT_RAW:
      return adts - AAC_ADTS_HEADER_SIZE;
    default:
      return adts - AAC_ADTS_HEADER_SIZE + AAC_LATM_MAX_OVERHEAD(adts);
  }
}

int aac_encoder_transport_wrap(AacEncoderState* s, AacEncoderTransport* t, int frame_len,
                               uint8_t* out, int out_size) {
  const uint8_t* raw = s->output_buf + AAC_ADTS_HEADER_SIZE;
  const int raw_len = frame_len - AAC_ADTS_HEADER_SIZE;
  int len = AAC_ERR_OVERFLOW;
  switch (t->transport) {
    case AAC_TRANSPORT_ADTS: {
      if (!t->crc) {
        if (frame_len <= out_size) {
          memcpy(out, s->output_buf, (size_t)frame_len);
          len = frame_len;
        }
        break;
      }
      if (frame_len + 2 > out_size) {
        break;
      }
      AacAdtsHeader hdr;
      aac_adts_parse(&hdr, s->output_buf, frame_len);
      hdr.protection_absent = 0;
      hdr.frame_length = frame_len + 2;
      aac_adts_write(&hdr, out);
      const uint16_t crc =
          aac_adts_crc(out, 8 * AAC_ADTS_HEADER_SIZE, raw, s->crc_region, s->num_crc_regions);
      out[AAC_ADTS_HEADER_SIZE] = (uint8_t)(crc >> 8);
      out[AAC_ADTS_HEADER_SIZE + 1] = (uint8_t)(crc & 0xFF);
      memcpy(out + AAC_ADTS_HEADER_SIZE + 2, raw, (size_t)raw_len);
      len = hdr.frame_length;
      break;
    }
    case AAC_TRANSPORT_RAW:
      if (raw_len <= out_size) {
        memcpy(out, raw, (size_t)raw_len);
        len = raw_len;
      }
      break;
    default: {
      AacAsc asc;
      aac_encoder_transport_asc(s, t, &asc);
      /* A backward compatible sync extension can only be told from the
       * mux fields that follow when the config's length is explicit, so
       * such a config travels in audioMuxVersion 1. */
      const int version = asc.signaling == AAC_SIGNAL_EXPLICIT_BC && asc.sbr_present >= 0;
      AacLatmConfig cfg;
      aac_latm_config_init(&cfg, &asc, version);
      if (t->transport == AAC_TRANSPORT_LOAS) {
        len = aac_loas_write_frame(&cfg, 0, raw, raw_len, out, out_size);
      } else {
        const int mcp = t->transport == AAC_TRANSPORT_LATM_MCP1;
        len = aac_latm_write_mux_element(&cfg, mcp, 0, raw, raw_len, out, out_size);
      }
      break;
    }
  }
  if (len > 0) {
    t->frames++;
  }
  return len;
}

#endif /* BAAC_AAC_HAVE_ENCODER */
