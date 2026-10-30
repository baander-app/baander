#include "latm.h"

#include <cstring>

namespace {

/* LatmGetValue() (Table 1.44): a byte count and that many bytes. */
uint32_t read_latm_value(AacBitReader* r) {
  const int bytes = (int)aac_bitreader_read(r, 2) + 1;
  uint32_t value = 0;
  for (int i = 0; i < bytes; i++) {
    value = (value << 8) | aac_bitreader_read(r, 8);
  }
  return value;
}

void write_latm_value(AacBitWriter* w, uint32_t value) {
  int bytes = 1;
  while (bytes < 4 && (value >> (8 * bytes)) != 0) {
    bytes++;
  }
  aac_bitwriter_write(w, (uint32_t)(bytes - 1), 2);
  for (int i = bytes - 1; i >= 0; i--) {
    aac_bitwriter_write(w, (value >> (8 * i)) & 0xFF, 8);
  }
}

/* The bits of `nbits` from the reader's position, byte-aligned into out. */
void copy_bits(AacBitReader* r, uint8_t* out, int nbits) {
  int i = 0;
  for (; i + 8 <= nbits; i += 8) {
    out[i >> 3] = (uint8_t)aac_bitreader_read(r, 8);
  }
  if (i < nbits) {
    out[i >> 3] = (uint8_t)(aac_bitreader_read(r, nbits - i) << (8 - (nbits - i)));
  }
}

/* PayloadLengthInfo() for frameLengthType 0: MuxSlotLengthBytes as a run
 * of 255s and a final shorter byte. */
int read_slot_length(AacBitReader* r) {
  int length = 0;
  int tmp;
  do {
    tmp = (int)aac_bitreader_read(r, 8);
    length += tmp;
  } while (tmp == 255 && !r->overread);
  return length;
}

void write_slot_length(AacBitWriter* w, int length) {
  while (length >= 255) {
    aac_bitwriter_write(w, 255, 8);
    length -= 255;
  }
  aac_bitwriter_write(w, (uint32_t)length, 8);
}

/* The body of an AudioMuxElement after the mux config: one access unit's
 * length and bytes, then the alignment. */
int write_payload(AacBitWriter* w, const AacLatmConfig* cfg, const uint8_t* au, int au_size) {
  write_slot_length(w, au_size);
  for (int i = 0; i < au_size; i++) {
    aac_bitwriter_write(w, au[i], 8);
  }
  if (cfg->other_data_present) {
    for (int i = 0; i < cfg->other_data_len_bits; i++) {
      aac_bitwriter_write(w, 0, 1);
    }
  }
  aac_bitwriter_byte_align(w);
  return aac_bitwriter_overflow(w) ? AAC_ERR_OVERFLOW : aac_bitwriter_bytes_written(w);
}

}  // namespace

void aac_latm_config_init(AacLatmConfig* cfg, const AacAsc* asc, int audio_mux_version) {
  memset(cfg, 0, sizeof(*cfg));
  cfg->valid = 1;
  cfg->audio_mux_version = audio_mux_version ? 1 : 0;
  cfg->tara_buffer_fullness = 0xFF;
  cfg->latm_buffer_fullness = 0xFF;
  cfg->num_layers = 1;
  cfg->asc = *asc;
}

int aac_latm_read_stream_mux_config(AacBitReader* r, AacLatmConfig* cfg) {
  AacLatmConfig c = {};
  c.audio_mux_version = (int)aac_bitreader_read(r, 1);
  if (c.audio_mux_version == 1 && aac_bitreader_read(r, 1) != 0) {
    return AAC_ERR_UNSUPPORTED; /* audioMuxVersionA */
  }
  if (c.audio_mux_version == 1) {
    c.tara_buffer_fullness = (int)read_latm_value(r);
  }
  if (aac_bitreader_read(r, 1) != 1) { /* allStreamsSameTimeFraming */
    return AAC_ERR_UNSUPPORTED;
  }
  c.num_sub_frames = (int)aac_bitreader_read(r, 6);
  if (c.num_sub_frames + 1 > AAC_LATM_MAX_SUBFRAMES) {
    return AAC_ERR_UNSUPPORTED; /* more access units than one output buffer holds */
  }
  if (aac_bitreader_read(r, 4) != 0) {
    return AAC_ERR_UNSUPPORTED; /* numProgram: one program only */
  }
  c.num_layers = (int)aac_bitreader_read(r, 3) + 1;
  if (c.num_layers > 2) {
    return AAC_ERR_UNSUPPORTED;
  }
  for (int layer = 0; layer < c.num_layers; layer++) {
    AacAsc second = {};
    AacAsc* asc = layer == 0 ? &c.asc : &second;
    if (layer > 0 && aac_bitreader_read(r, 1)) {
      return AAC_ERR_UNSUPPORTED; /* useSameConfig: a layer without a config of its own */
    }
    if (c.audio_mux_version == 0) {
      const int ret = aac_asc_read(r, asc);
      if (ret) {
        return ret;
      }
    } else {
      /* The config is read from a copy of its ascLen bits, so that a
       * reader that runs to the end of the config (the surround config's
       * extension loop) stops where the config does. */
      const uint32_t asc_len = read_latm_value(r);
      uint8_t copy[AAC_LATM_MAX_ASC_BYTES];
      if (asc_len > (uint32_t)aac_bitreader_bits_left(r) ||
          asc_len > 8u * AAC_LATM_MAX_ASC_BYTES) {
        return AAC_ERR_DECODE;
      }
      memset(copy, 0, sizeof(copy));
      copy_bits(r, copy, (int)asc_len);
      AacBitReader sub;
      aac_bitreader_init(&sub, copy, (int)((asc_len + 7) / 8));
      const int ret = aac_asc_read(&sub, asc);
      if (ret) {
        return ret;
      }
      if (sub.pos > (int)asc_len) {
        return AAC_ERR_DECODE;
      }
    }
    if (layer > 0) {
      /* the second layer: the MPEG Surround config over the first's core */
      if (!second.mps_present || c.asc.mps_present) {
        return AAC_ERR_UNSUPPORTED;
      }
      c.asc.mps_present = 1;
      c.asc.sac_payload_embedding = second.sac_payload_embedding;
      c.asc.mps = second.mps;
    }
    c.frame_length_type = (int)aac_bitreader_read(r, 3);
    if (c.frame_length_type != 0) {
      return AAC_ERR_UNSUPPORTED;
    }
    c.latm_buffer_fullness = (int)aac_bitreader_read(r, 8);
  }
  c.other_data_present = (int)aac_bitreader_read(r, 1);
  if (c.other_data_present) {
    uint32_t len = 0;
    if (c.audio_mux_version == 1) {
      len = read_latm_value(r);
    } else {
      int esc;
      int bytes = 0;
      do {
        len <<= 8;
        esc = (int)aac_bitreader_read(r, 1);
        len += aac_bitreader_read(r, 8);
      } while (esc && !r->overread && ++bytes < 4);
    }
    /* The other data follows the payload in every element, so it can
     * never exceed the largest element. */
    if (len > 8u * AAC_LOAS_MAX_PAYLOAD) {
      return AAC_ERR_DECODE;
    }
    c.other_data_len_bits = (int)len;
  }
  c.crc_check_present = (int)aac_bitreader_read(r, 1);
  if (c.crc_check_present) {
    c.crc_check_sum = (int)aac_bitreader_read(r, 8);
  }
  if (r->overread) {
    return AAC_ERR_DECODE;
  }
  c.valid = 1;
  *cfg = c;
  return 0;
}

int aac_latm_write_stream_mux_config(AacBitWriter* w, const AacLatmConfig* cfg) {
  aac_bitwriter_write(w, cfg->audio_mux_version ? 1u : 0u, 1);
  if (cfg->audio_mux_version) {
    aac_bitwriter_write(w, 0, 1); /* audioMuxVersionA */
    write_latm_value(w, (uint32_t)cfg->tara_buffer_fullness);
  }
  aac_bitwriter_write(w, 1, 1); /* allStreamsSameTimeFraming */
  aac_bitwriter_write(w, (uint32_t)cfg->num_sub_frames, 6);
  aac_bitwriter_write(w, 0, 4); /* numProgram */
  aac_bitwriter_write(w, 0, 3); /* numLayer */
  if (cfg->audio_mux_version == 0) {
    const int bits = aac_asc_write_bits(&cfg->asc, w);
    if (bits < 0) {
      return bits;
    }
  } else {
    /* The config's length in bits precedes it: measure it first. */
    uint8_t scratch[AAC_ASC_MAX_BYTES];
    AacBitWriter m;
    aac_bitwriter_init(&m, scratch, sizeof(scratch));
    const int bits = aac_asc_write_bits(&cfg->asc, &m);
    if (bits < 0) {
      return bits;
    }
    write_latm_value(w, (uint32_t)bits);
    aac_asc_write_bits(&cfg->asc, w);
  }
  aac_bitwriter_write(w, 0, 3); /* frameLengthType */
  aac_bitwriter_write(w, (uint32_t)cfg->latm_buffer_fullness, 8);
  aac_bitwriter_write(w, cfg->other_data_present ? 1u : 0u, 1);
  if (cfg->other_data_present) {
    if (cfg->audio_mux_version) {
      write_latm_value(w, (uint32_t)cfg->other_data_len_bits);
    } else {
      int bytes = 1;
      while (bytes < 4 && (cfg->other_data_len_bits >> (8 * bytes)) != 0) {
        bytes++;
      }
      for (int i = bytes - 1; i >= 0; i--) {
        aac_bitwriter_write(w, i > 0 ? 1u : 0u, 1); /* otherDataLenEsc */
        aac_bitwriter_write(w, ((uint32_t)cfg->other_data_len_bits >> (8 * i)) & 0xFF, 8);
      }
    }
  }
  aac_bitwriter_write(w, cfg->crc_check_present ? 1u : 0u, 1);
  if (cfg->crc_check_present) {
    aac_bitwriter_write(w, (uint32_t)cfg->crc_check_sum, 8);
  }
  return aac_bitwriter_overflow(w) ? AAC_ERR_OVERFLOW : 0;
}

int aac_latm_read_mux_element(const uint8_t* data, int size, int mux_config_present,
                              AacLatmConfig* cfg, uint8_t* out, int out_size,
                              AacLatmPayload* payloads, int max_payloads, int* used_bytes) {
  if (!data || size <= 0 || !cfg || !out || !payloads || max_payloads <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  AacBitReader r;
  aac_bitreader_init(&r, data, size);
  if (mux_config_present) {
    const int use_same = (int)aac_bitreader_read(&r, 1);
    if (!use_same) {
      const int ret = aac_latm_read_stream_mux_config(&r, cfg);
      if (ret) {
        return ret;
      }
    }
  }
  if (!cfg->valid) {
    return AAC_ERR_STATE;
  }
  const int count = cfg->num_sub_frames + 1;
  if (count > max_payloads) {
    return AAC_ERR_OVERFLOW;
  }
  int used = 0;
  const int layers = cfg->num_layers > 1 ? cfg->num_layers : 1;
  for (int i = 0; i < count; i++) {
    /* the lengths of every layer's payload, then the payloads: the
     * first layer's is the access unit, a second layer's is dropped */
    int length[2] = {0, 0};
    for (int layer = 0; layer < layers; layer++) {
      length[layer] = read_slot_length(&r);
    }
    if (r.overread || (length[0] + length[1]) * 8 > aac_bitreader_bits_left(&r)) {
      return AAC_ERR_DECODE;
    }
    if (used + length[0] > out_size) {
      return AAC_ERR_OVERFLOW;
    }
    copy_bits(&r, out + used, length[0] * 8);
    payloads[i].offset = used;
    payloads[i].size = length[0];
    used += length[0];
    aac_bitreader_skip(&r, length[1] * 8);
  }
  if (cfg->other_data_present) {
    aac_bitreader_skip(&r, cfg->other_data_len_bits);
  }
  aac_bitreader_byte_align(&r);
  if (used_bytes) {
    *used_bytes = r.pos / 8;
  }
  return r.overread ? AAC_ERR_DECODE : count;
}

int aac_latm_write_mux_element(const AacLatmConfig* cfg, int mux_config_present,
                               int use_same_stream_mux, const uint8_t* au, int au_size,
                               uint8_t* out, int out_size) {
  if (!cfg || !cfg->valid || !au || au_size < 0 || !out || out_size <= 0 ||
      cfg->num_sub_frames != 0) {
    return AAC_ERR_INVALID_ARG;
  }
  AacBitWriter w;
  aac_bitwriter_init(&w, out, out_size);
  if (mux_config_present) {
    aac_bitwriter_write(&w, use_same_stream_mux ? 1u : 0u, 1);
    if (!use_same_stream_mux) {
      const int ret = aac_latm_write_stream_mux_config(&w, cfg);
      if (ret) {
        return ret;
      }
    }
  }
  return write_payload(&w, cfg, au, au_size);
}

int aac_loas_write_frame(const AacLatmConfig* cfg, int use_same_stream_mux, const uint8_t* au,
                         int au_size, uint8_t* out, int out_size) {
  if (!out || out_size <= AAC_LOAS_HEADER_BYTES) {
    return AAC_ERR_INVALID_ARG;
  }
  const int len = aac_latm_write_mux_element(cfg, 1, use_same_stream_mux, au, au_size,
                                             out + AAC_LOAS_HEADER_BYTES,
                                             out_size - AAC_LOAS_HEADER_BYTES);
  if (len < 0) {
    return len;
  }
  if (len > AAC_LOAS_MAX_PAYLOAD) {
    return AAC_ERR_OVERFLOW;
  }
  AacBitWriter w;
  aac_bitwriter_init(&w, out, AAC_LOAS_HEADER_BYTES);
  aac_bitwriter_write(&w, AAC_LOAS_SYNC, 11);
  aac_bitwriter_write(&w, (uint32_t)len, 13);
  return AAC_LOAS_HEADER_BYTES + len;
}

int aac_loas_is_sync(const uint8_t* data, int size) {
  return size >= 2 && data[0] == 0x56 && (data[1] & 0xE0) == 0xE0;
}

int aac_loas_payload_length(const uint8_t* data) { return ((data[1] & 0x1F) << 8) | data[2]; }
