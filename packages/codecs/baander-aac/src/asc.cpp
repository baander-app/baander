#include "asc.h"

#include <cstring>

#include "aac_tables.h"
#include "sbr_ld.h"

namespace {

constexpr int kSyncExtensionSbr = 0x2b7;
constexpr int kSyncExtensionPs = 0x548;
constexpr int kAotEscape = 31;
constexpr int kRateIndexEscape = 15;

/* GetAudioObjectType() (Table 1.16). */
int read_aot(AacBitReader* r) {
  int aot = (int)aac_bitreader_read(r, 5);
  if (aot == kAotEscape) {
    aot = 32 + (int)aac_bitreader_read(r, 6);
  }
  return aot;
}

void write_aot(AacBitWriter* w, int aot) {
  if (aot < kAotEscape) {
    aac_bitwriter_write(w, (uint32_t)aot, 5);
  } else {
    aac_bitwriter_write(w, kAotEscape, 5);
    aac_bitwriter_write(w, (uint32_t)(aot - 32), 6);
  }
}

/* samplingFrequencyIndex with its escape to an explicit 24-bit rate;
 * returns the rate in Hz or 0 for a reserved index. */
int read_rate(AacBitReader* r) {
  const int index = (int)aac_bitreader_read(r, 4);
  if (index == kRateIndexEscape) {
    return (int)aac_bitreader_read(r, 24);
  }
  return index < AAC_NUM_SAMPLE_RATES ? aac_sample_rates[index] : 0;
}

void write_rate(AacBitWriter* w, int sample_rate) {
  const int index = aac_asc_rate_index(sample_rate);
  if (index >= 0) {
    aac_bitwriter_write(w, (uint32_t)index, 4);
  } else {
    aac_bitwriter_write(w, kRateIndexEscape, 4);
    aac_bitwriter_write(w, (uint32_t)sample_rate, 24);
  }
}

/* The object types whose config is a GASpecificConfig (Table 1.17). */
bool is_ga_type(int aot) {
  switch (aot) {
    case 1:
    case 2:
    case 3:
    case 4:
    case 6:
    case 7:
    case 17:
    case 19:
    case 20:
    case 21:
    case 22:
    case 23:
      return true;
    default:
      return false;
  }
}

/* GASpecificConfig() (Table 4.1).  Only the object types this codec can
 * mean to decode are read past the fields they share. */
int read_ga_config(AacBitReader* r, AacAsc* asc, int align_anchor) {
  const int flag = (int)aac_bitreader_read(r, 1);
  asc->frame_length = asc->aot == AAC_AOT_ER_LD ? (flag ? 480 : 512) : (flag ? 960 : 1024);
  asc->depends_on_core_coder = (int)aac_bitreader_read(r, 1);
  if (asc->depends_on_core_coder) {
    asc->core_coder_delay = (int)aac_bitreader_read(r, 14);
  }
  asc->extension_flag = (int)aac_bitreader_read(r, 1);
  if (asc->channel_config == 0) {
    const int ret = aac_pce_read(r, &asc->layout, align_anchor);
    if (ret) {
      return ret;
    }
    asc->has_pce = 1;
  }
  if (asc->aot == 6 || asc->aot == 20) {
    aac_bitreader_skip(r, 3); /* layerNr */
  }
  if (asc->extension_flag) {
    if (asc->aot == 22) {
      aac_bitreader_skip(r, 5 + 11); /* numOfSubFrame, layer_length */
    }
    if (asc->aot == 17 || asc->aot == 19 || asc->aot == 20 || asc->aot == 23) {
      asc->section_resilience = (int)aac_bitreader_read(r, 1);
      asc->scalefactor_resilience = (int)aac_bitreader_read(r, 1);
      asc->spectral_resilience = (int)aac_bitreader_read(r, 1);
    }
    if (aac_bitreader_read(r, 1)) { /* extensionFlag3 */
      return AAC_ERR_UNSUPPORTED;
    }
  }
  return r->overread ? AAC_ERR_DECODE : 0;
}

/* sbr_header() count of ld_sbr_header(): one per SCE or CPE of the
 * channel configuration (Table 4.4). */
int ld_sbr_header_count(int channel_config) {
  switch (channel_config) {
    case 1:
    case 2:
      return 1;
    case 3:
      return 2;
    case 4:
    case 5:
    case 6:
      return 3;
    case 7:
      return 4;
    default:
      return 0;
  }
}

constexpr int kEldExtTerm = 0;

/* ELDSpecificConfig() (Table 4.180): the frame length, the resilience
 * flags, the low-delay SBR signalling with its headers, and extensions
 * (such as the SAOC and LD MPEG Surround payloads) that are skipped. */
int read_eld_config(AacBitReader* r, AacAsc* asc) {
  asc->frame_length = aac_bitreader_read(r, 1) ? 480 : 512;
  asc->section_resilience = (int)aac_bitreader_read(r, 1);
  asc->scalefactor_resilience = (int)aac_bitreader_read(r, 1);
  asc->spectral_resilience = (int)aac_bitreader_read(r, 1);
  asc->ld_sbr_present = (int)aac_bitreader_read(r, 1);
  if (asc->ld_sbr_present) {
    asc->ld_sbr_sampling_rate = (int)aac_bitreader_read(r, 1);
    asc->ld_sbr_crc = (int)aac_bitreader_read(r, 1);
    asc->num_ld_sbr_headers = ld_sbr_header_count(asc->channel_config);
    for (int i = 0; i < asc->num_ld_sbr_headers; i++) {
      aac_sbr_ld_header_read(&asc->ld_sbr_header[i], r);
    }
  }
  for (;;) {
    const int ext_type = (int)aac_bitreader_read(r, 4);
    if (ext_type == kEldExtTerm || r->overread) {
      break;
    }
    int len = (int)aac_bitreader_read(r, 4);
    if (len == 15) {
      const int add = (int)aac_bitreader_read(r, 8);
      len += add;
      if (add == 255) {
        len += (int)aac_bitreader_read(r, 16);
      }
    }
    if (aac_bitreader_bits_left(r) < 8 * len) {
      return AAC_ERR_DECODE;
    }
    aac_bitreader_skip(r, 8 * len);
  }
  return r->overread ? AAC_ERR_DECODE : 0;
}

void write_eld_config(AacBitWriter* w, const AacAsc* asc) {
  aac_bitwriter_write(w, asc->frame_length == 480 ? 1u : 0u, 1);
  aac_bitwriter_write(w, asc->section_resilience ? 1u : 0u, 1);
  aac_bitwriter_write(w, asc->scalefactor_resilience ? 1u : 0u, 1);
  aac_bitwriter_write(w, asc->spectral_resilience ? 1u : 0u, 1);
  aac_bitwriter_write(w, asc->ld_sbr_present ? 1u : 0u, 1);
  if (asc->ld_sbr_present) {
    aac_bitwriter_write(w, asc->ld_sbr_sampling_rate ? 1u : 0u, 1);
    aac_bitwriter_write(w, asc->ld_sbr_crc ? 1u : 0u, 1);
    const int n = ld_sbr_header_count(asc->channel_config);
    for (int i = 0; i < n; i++) {
      aac_sbr_ld_header_write(&asc->ld_sbr_header[i], w);
    }
  }
  aac_bitwriter_write(w, kEldExtTerm, 4);
}

bool is_low_delay_type(int aot) { return aot == AAC_AOT_ER_LD || aot == AAC_AOT_ER_ELD; }

/* The error resilient object types whose AudioSpecificConfig ends with
 * epConfig (Table 1.15). */
bool has_ep_config(int aot) { return (aot >= 17 && aot <= 27) || aot == AAC_AOT_ER_ELD; }

/* epConfig: 0 or 1 carry no error protection data; 2 and 3 are followed
 * by an ErrorProtectionSpecificConfig this codec does not read. */
int read_ep_config(AacBitReader* r) {
  const int ep_config = (int)aac_bitreader_read(r, 2);
  if (ep_config >= 2) {
    return AAC_ERR_UNSUPPORTED;
  }
  return r->overread ? AAC_ERR_DECODE : 0;
}

}  // namespace

int aac_asc_rate_index(int sample_rate) {
  for (int i = 0; i < AAC_NUM_SAMPLE_RATES; i++) {
    if (aac_sample_rates[i] == sample_rate) {
      return i;
    }
  }
  return -1;
}

void aac_asc_init(AacAsc* asc, int sample_rate, int channel_config) {
  memset(asc, 0, sizeof(*asc));
  asc->aot = AAC_AOT_LC;
  asc->sample_rate = sample_rate;
  asc->channel_config = channel_config;
  asc->frame_length = AAC_FRAME_SIZE_LONG;
  asc->signaling = AAC_SIGNAL_IMPLICIT;
  asc->sbr_present = -1;
  asc->ps_present = -1;
}

int aac_asc_read(AacBitReader* r, AacAsc* asc) {
  const int anchor = r->pos;
  aac_asc_init(asc, 0, 0);
  asc->aot = read_aot(r);
  asc->sample_rate = read_rate(r);
  asc->channel_config = (int)aac_bitreader_read(r, 4);
  if (asc->aot == AAC_AOT_SBR || asc->aot == AAC_AOT_PS) {
    /* Hierarchical signalling: the extension in front, then the core. */
    asc->signaling = AAC_SIGNAL_EXPLICIT_HIER;
    asc->sbr_present = 1;
    asc->ps_present = asc->aot == AAC_AOT_PS ? 1 : -1;
    asc->ext_sample_rate = read_rate(r);
    asc->aot = read_aot(r);
    if (asc->aot == 22) {
      aac_bitreader_skip(r, 4); /* extensionChannelConfiguration */
    }
  }
  if (r->overread || asc->sample_rate == 0) {
    return AAC_ERR_DECODE;
  }
  if (asc->aot == AAC_AOT_MPS) {
    /* MPEG Surround: sacPayloadEmbedding and the SpatialSpecificConfig,
     * whose byte alignment counts from its own start; the reader has to
     * end with the config, as its extension loop runs to the end. */
    asc->sac_payload_embedding = (int)aac_bitreader_read(r, 1);
    const int ret = aac_mps_config_read(r, &asc->mps);
    if (ret) {
      return ret;
    }
    asc->mps_present = 1;
    return r->overread ? AAC_ERR_DECODE : AAC_OK;
  }
  if (asc->aot == AAC_AOT_USAC) {
    /* UsacConfig() (ISO/IEC 23003-3 Table 1) ends the config: the frame
     * lengths and the output rate come from it. */
    const int ret = aac_usac_config_read(r, &asc->usac);
    if (ret) {
      return ret;
    }
    asc->frame_length = asc->usac.frame_length;
    return AAC_OK;
  }
  if (asc->aot == AAC_AOT_ER_ELD) {
    if (asc->channel_config == 0) {
      return AAC_ERR_UNSUPPORTED; /* the ELD config carries no program config element */
    }
    const int ret = read_eld_config(r, asc);
    return ret ? ret : read_ep_config(r);
  }
  if (!is_ga_type(asc->aot)) {
    return AAC_ERR_UNSUPPORTED;
  }
  int ret = read_ga_config(r, asc, anchor);
  if (ret == 0 && has_ep_config(asc->aot)) {
    ret = read_ep_config(r);
  }
  if (ret) {
    return ret;
  }
  /* Backward compatible signalling: a sync extension that an old decoder
   * reads as padding.  The bits are only taken when they are the
   * extension, so that a config inside a StreamMuxConfig is not read past
   * its end. */
  if (asc->signaling != AAC_SIGNAL_EXPLICIT_HIER && aac_bitreader_bits_left(r) >= 16 &&
      aac_bitreader_peek(r, 11) == kSyncExtensionSbr) {
    aac_bitreader_skip(r, 11);
    const int ext_aot = read_aot(r);
    if (ext_aot == AAC_AOT_SBR) {
      asc->signaling = AAC_SIGNAL_EXPLICIT_BC;
      asc->sbr_present = (int)aac_bitreader_read(r, 1);
      if (asc->sbr_present) {
        asc->ext_sample_rate = read_rate(r);
        if (aac_bitreader_bits_left(r) >= 12 && aac_bitreader_peek(r, 11) == kSyncExtensionPs) {
          aac_bitreader_skip(r, 11);
          asc->ps_present = (int)aac_bitreader_read(r, 1);
        }
      }
    } else if (ext_aot == 22) {
      if (aac_bitreader_read(r, 1)) {
        read_rate(r);
      }
      aac_bitreader_skip(r, 4);
    }
  }
  return r->overread ? AAC_ERR_DECODE : 0;
}

int aac_asc_parse(const uint8_t* data, int size, AacAsc* asc) {
  if (!data || size <= 0 || !asc) {
    return AAC_ERR_INVALID_ARG;
  }
  AacBitReader r;
  aac_bitreader_init(&r, data, size);
  return aac_asc_read(&r, asc);
}

int aac_asc_write_bits(const AacAsc* asc, AacBitWriter* w) {
  const bool low_delay = is_low_delay_type(asc->aot);
  const bool length_ok = low_delay ? asc->frame_length == 512 || asc->frame_length == 480
                                   : asc->frame_length == 1024 || asc->frame_length == 960;
  if ((!is_ga_type(asc->aot) && asc->aot != AAC_AOT_ER_ELD) || asc->sample_rate <= 0 ||
      asc->channel_config < 0 || asc->channel_config > 15 ||
      (asc->channel_config == 0 && !asc->has_pce) || !length_ok ||
      (asc->aot == AAC_AOT_ER_ELD && asc->channel_config == 0)) {
    return AAC_ERR_INVALID_ARG;
  }
  const bool sbr = asc->sbr_present == 1 && !low_delay;
  const bool ps = asc->ps_present == 1 && !low_delay;
  if (sbr && asc->ext_sample_rate <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  const int anchor = aac_bitwriter_bits_written(w);
  const int start = anchor;
  if (asc->signaling == AAC_SIGNAL_EXPLICIT_HIER && sbr) {
    write_aot(w, ps ? AAC_AOT_PS : AAC_AOT_SBR);
    write_rate(w, asc->sample_rate);
    aac_bitwriter_write(w, (uint32_t)asc->channel_config, 4);
    write_rate(w, asc->ext_sample_rate);
    write_aot(w, asc->aot);
  } else {
    write_aot(w, asc->aot);
    write_rate(w, asc->sample_rate);
    aac_bitwriter_write(w, (uint32_t)asc->channel_config, 4);
  }
  if (asc->aot == AAC_AOT_ER_ELD) {
    write_eld_config(w, asc);
    aac_bitwriter_write(w, 0, 2); /* epConfig */
    if (aac_bitwriter_overflow(w)) {
      return AAC_ERR_OVERFLOW;
    }
    return aac_bitwriter_bits_written(w) - start;
  }
  /* GASpecificConfig; the error resilient types carry their tool flags
   * in the extension. */
  const bool er = asc->aot == AAC_AOT_ER_LC || asc->aot == AAC_AOT_ER_LD;
  aac_bitwriter_write(w, asc->frame_length == 960 || asc->frame_length == 480 ? 1u : 0u, 1);
  aac_bitwriter_write(w, asc->depends_on_core_coder ? 1u : 0u, 1);
  if (asc->depends_on_core_coder) {
    aac_bitwriter_write(w, (uint32_t)asc->core_coder_delay, 14);
  }
  aac_bitwriter_write(w, er ? 1u : 0u, 1); /* extensionFlag */
  if (asc->channel_config == 0) {
    aac_pce_write(w, &asc->layout, aac_asc_rate_index(asc->sample_rate), anchor);
  }
  if (er) {
    aac_bitwriter_write(w, asc->section_resilience ? 1u : 0u, 1);
    aac_bitwriter_write(w, asc->scalefactor_resilience ? 1u : 0u, 1);
    aac_bitwriter_write(w, asc->spectral_resilience ? 1u : 0u, 1);
    aac_bitwriter_write(w, 0, 1); /* extensionFlag3 */
  }
  if (has_ep_config(asc->aot)) {
    aac_bitwriter_write(w, 0, 2); /* epConfig */
  }
  if (asc->signaling == AAC_SIGNAL_EXPLICIT_BC && asc->sbr_present >= 0) {
    aac_bitwriter_write(w, kSyncExtensionSbr, 11);
    write_aot(w, AAC_AOT_SBR);
    aac_bitwriter_write(w, sbr ? 1u : 0u, 1);
    if (sbr) {
      write_rate(w, asc->ext_sample_rate);
      if (asc->ps_present >= 0) {
        aac_bitwriter_write(w, kSyncExtensionPs, 11);
        aac_bitwriter_write(w, ps ? 1u : 0u, 1);
      }
    }
  }
  if (aac_bitwriter_overflow(w)) {
    return AAC_ERR_OVERFLOW;
  }
  return aac_bitwriter_bits_written(w) - start;
}

int aac_asc_write(const AacAsc* asc, uint8_t* out, int out_size) {
  if (!asc || !out || out_size <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  AacBitWriter w;
  aac_bitwriter_init(&w, out, out_size);
  const int bits = aac_asc_write_bits(asc, &w);
  if (bits < 0) {
    return bits;
  }
  aac_bitwriter_byte_align(&w);
  return aac_bitwriter_bytes_written(&w);
}

/* ── program_config_element ────────────────────────────────────── */

int aac_pce_read(AacBitReader* r, AacLayout* layout, int align_anchor) {
  aac_bitreader_skip(r, 4 + 2 + 4); /* tag, object_type, sampling_frequency_index */
  const int front = (int)aac_bitreader_read(r, 4);
  const int side = (int)aac_bitreader_read(r, 4);
  const int back = (int)aac_bitreader_read(r, 4);
  const int lfe = (int)aac_bitreader_read(r, 2);
  const int assoc = (int)aac_bitreader_read(r, 3);
  const int cc = (int)aac_bitreader_read(r, 4);
  if (aac_bitreader_read(r, 1)) {
    aac_bitreader_skip(r, 4); /* mono_mixdown_element_number */
  }
  if (aac_bitreader_read(r, 1)) {
    aac_bitreader_skip(r, 4); /* stereo_mixdown_element_number */
  }
  if (aac_bitreader_read(r, 1)) {
    aac_bitreader_skip(r, 3); /* matrix_mixdown_idx, pseudo_surround_enable */
  }
  AacLayoutElement elems[AAC_MAX_ELEMENTS] = {};
  int n = 0;
  const int counts[4] = {front, side, back, lfe};
  for (int pos = 0; pos < 4; pos++) {
    for (int i = 0; i < counts[pos]; i++) {
      const int is_cpe = pos == 3 ? 0 : (int)aac_bitreader_read(r, 1);
      const int tag = (int)aac_bitreader_read(r, 4);
      if (n >= AAC_MAX_ELEMENTS) {
        return AAC_ERR_UNSUPPORTED;
      }
      elems[n].type = pos == 3 ? AAC_ELEM_LFE : (is_cpe ? AAC_ELEM_CPE : AAC_ELEM_SCE);
      elems[n].tag = tag;
      elems[n].position = pos + 1;
      n++;
    }
  }
  aac_bitreader_skip(r, 4 * assoc);
  AacLayout l = {};
  for (int i = 0; i < cc; i++) {
    aac_bitreader_skip(r, 1); /* cc_element_is_ind_sw */
    const int tag = (int)aac_bitreader_read(r, 4);
    if (i < AAC_MAX_CCE) {
      l.cce_tag[l.num_cce++] = tag;
    }
  }
  aac_bitreader_skip(r, (8 - ((r->pos - align_anchor) & 7)) & 7);
  const int comment = (int)aac_bitreader_read(r, 8);
  /* A comment that starts with the height extension's sync byte carries
   * two bits of height per front, side and back element, byte alignment
   * and a CRC over the bytes before it; a failing CRC leaves every
   * element in the normal layer. */
  const int height_elements = front + side + back;
  const int height_bytes = 1 + (2 * height_elements + 7) / 8;
  int skipped = 0;
  if (comment >= height_bytes + 1 && aac_bitreader_peek(r, 8) == AAC_PCE_HEIGHT_EXTENSION_SYNC) {
    uint8_t bytes[1 + (2 * AAC_MAX_ELEMENTS + 7) / 8];
    for (int i = 0; i < height_bytes; i++) {
      bytes[i] = (uint8_t)aac_bitreader_read(r, 8);
    }
    const int crc = (int)aac_bitreader_read(r, 8);
    skipped = height_bytes + 1;
    if (crc == aac_pce_height_crc(bytes, height_bytes)) {
      for (int i = 0; i < height_elements; i++) {
        const int shift = 6 - 2 * (i % 4);
        elems[i].height = (bytes[1 + i / 4] >> shift) & 3;
      }
    }
  }
  aac_bitreader_skip(r, 8 * (comment - skipped));
  if (r->overread || n == 0) {
    return AAC_ERR_DECODE;
  }
  const int ret = aac_layout_from_elements(&l, elems, n);
  if (ret) {
    return ret;
  }
  *layout = l;
  return 0;
}

void aac_pce_write(AacBitWriter* w, const AacLayout* l, int rate_index, int align_anchor) {
  aac_bitwriter_write(w, 0, 4); /* element_instance_tag */
  aac_bitwriter_write(w, AAC_AOT_LC - 1, 2);
  aac_bitwriter_write(w, (uint32_t)rate_index, 4);
  static const int kPositions[4] = {AAC_POS_FRONT, AAC_POS_SIDE, AAC_POS_BACK, AAC_POS_LFE};
  for (int p = 0; p < 4; p++) {
    int n = 0;
    for (int e = 0; e < l->num_elements; e++) {
      n += l->elems[e].position == kPositions[p];
    }
    aac_bitwriter_write(w, (uint32_t)n, p == 3 ? 2 : 4);
  }
  aac_bitwriter_write(w, 0, 3); /* num_assoc_data_elements */
  aac_bitwriter_write(w, 0, 4); /* num_valid_cc_elements */
  aac_bitwriter_write(w, 0, 3); /* mono, stereo and matrix mixdown absent */
  for (int p = 0; p < 4; p++) {
    for (int e = 0; e < l->num_elements; e++) {
      const AacLayoutElement* el = &l->elems[e];
      if (el->position != kPositions[p]) {
        continue;
      }
      if (p < 3) {
        aac_bitwriter_write(w, el->type == AAC_ELEM_CPE ? 1 : 0, 1);
      }
      aac_bitwriter_write(w, (uint32_t)el->tag, 4);
    }
  }
  const int pad = (8 - ((aac_bitwriter_bits_written(w) - align_anchor) & 7)) & 7;
  aac_bitwriter_write(w, 0, pad);
  /* A layout with an element outside the normal layer carries the height
   * extension in the comment field: the sync byte, two bits per front,
   * side and back element in that order, alignment and the CRC. */
  bool height = false;
  for (int e = 0; e < l->num_elements; e++) {
    height = height || l->elems[e].height != AAC_HEIGHT_NORMAL;
  }
  if (!height) {
    aac_bitwriter_write(w, 0, 8); /* comment_field_bytes */
    return;
  }
  uint8_t bytes[1 + (2 * AAC_MAX_ELEMENTS + 7) / 8] = {AAC_PCE_HEIGHT_EXTENSION_SYNC};
  int n = 0;
  for (int p = 0; p < 3; p++) {
    for (int e = 0; e < l->num_elements; e++) {
      const AacLayoutElement* el = &l->elems[e];
      if (el->position == kPositions[p]) {
        bytes[1 + n / 4] |= (uint8_t)(el->height << (6 - 2 * (n % 4)));
        n++;
      }
    }
  }
  const int len = 1 + (2 * n + 7) / 8;
  aac_bitwriter_write(w, (uint32_t)(len + 1), 8); /* comment_field_bytes */
  for (int i = 0; i < len; i++) {
    aac_bitwriter_write(w, bytes[i], 8);
  }
  aac_bitwriter_write(w, aac_pce_height_crc(bytes, len), 8);
}
