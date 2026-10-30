/* UsacConfig() writer (ISO/IEC 23003-3 5.1 and Tables 1 to 15), the
 * mirror of the reader in usac_config.cpp: every field is written from
 * the configuration's fields in the order the standard lays it out, so
 * that aac_usac_config_read gives back the same configuration. */
#include "usac_enc.h"

namespace {

/* usacSamplingFrequencyIndex (Table 5.1), with 31 the escape to an
 * explicit 24-bit rate. */
constexpr int kUsacSamplingFrequencyEscape = 31;
const int kUsacSampleRates[31] = {96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050,
                                  16000, 12000, 11025, 8000,  7350,  0,     0,     57600,
                                  51200, 40000, 38400, 34150, 28800, 25600, 20000, 19200,
                                  17075, 14400, 12800, 9600,  0,     0,     0};

/* coreSbrFrameLengthIndex (Table 70) to sbrRatioIndex. */
const int kSbrRatioIndex[5] = {AAC_USAC_SBR_NONE, AAC_USAC_SBR_NONE, AAC_USAC_SBR_8_3,
                               AAC_USAC_SBR_2_1, AAC_USAC_SBR_4_1};

/* SbrConfig() (Table 10) with SbrDfltHeader() (Table 11): the extension
 * fields are sent only where a value differs from the defaults. */
void write_sbr_config(AacBitWriter* w, const AacUsacSbrConfig* c) {
  const AacUsacSbrHeader* h = &c->dflt;
  aac_bitwriter_write(w, c->harmonic_sbr ? 1u : 0u, 1);
  aac_bitwriter_write(w, c->inter_tes ? 1u : 0u, 1);
  aac_bitwriter_write(w, c->pvc ? 1u : 0u, 1);
  aac_bitwriter_write(w, (uint32_t)h->start_freq, 4);
  aac_bitwriter_write(w, (uint32_t)h->stop_freq, 4);
  const int extra1 = h->freq_scale != 2 || h->alter_scale != 1 || h->noise_bands != 2;
  const int extra2 = h->limiter_bands != 2 || h->limiter_gains != 2 || h->interpol_freq != 1 ||
                     h->smoothing_mode != 1;
  aac_bitwriter_write(w, extra1 ? 1u : 0u, 1);
  aac_bitwriter_write(w, extra2 ? 1u : 0u, 1);
  if (extra1) {
    aac_bitwriter_write(w, (uint32_t)h->freq_scale, 2);
    aac_bitwriter_write(w, (uint32_t)h->alter_scale, 1);
    aac_bitwriter_write(w, (uint32_t)h->noise_bands, 2);
  }
  if (extra2) {
    aac_bitwriter_write(w, (uint32_t)h->limiter_bands, 2);
    aac_bitwriter_write(w, (uint32_t)h->limiter_gains, 2);
    aac_bitwriter_write(w, (uint32_t)h->interpol_freq, 1);
    aac_bitwriter_write(w, (uint32_t)h->smoothing_mode, 1);
  }
}

/* Mps212Config() (Table 12). */
void write_mps212_config(AacBitWriter* w, int stereo_config_index, const AacUsacMps212Config* m) {
  aac_bitwriter_write(w, (uint32_t)m->freq_res, 3);
  aac_bitwriter_write(w, (uint32_t)m->fixed_gain_dmx, 3);
  aac_bitwriter_write(w, (uint32_t)m->temp_shape_config, 2);
  aac_bitwriter_write(w, (uint32_t)m->decorr_config, 2);
  aac_bitwriter_write(w, m->high_rate_mode ? 1u : 0u, 1);
  aac_bitwriter_write(w, m->phase_coding ? 1u : 0u, 1);
  aac_bitwriter_write(w, m->ott_bands_phase_present ? 1u : 0u, 1);
  if (m->ott_bands_phase_present) {
    aac_bitwriter_write(w, (uint32_t)m->ott_bands_phase, 5);
  }
  if (stereo_config_index > 1) {
    aac_bitwriter_write(w, (uint32_t)m->residual_bands, 5);
    aac_bitwriter_write(w, m->pseudo_lr ? 1u : 0u, 1);
  }
  if (m->temp_shape_config == 2) {
    aac_bitwriter_write(w, m->env_quant_mode ? 1u : 0u, 1);
  }
}

/* UsacCoreConfig() (Table 8), SbrConfig and the stereo configuration of
 * a CPE (Tables 9 and 12). */
void write_channel_element_config(AacBitWriter* w, int sbr_ratio_index,
                                  const AacUsacElementConfig* e) {
  aac_bitwriter_write(w, e->tw_mdct ? 1u : 0u, 1);
  aac_bitwriter_write(w, e->noise_filling ? 1u : 0u, 1);
  if (sbr_ratio_index > 0) {
    write_sbr_config(w, &e->sbr);
  }
  if (e->type == AAC_USAC_CPE && sbr_ratio_index > 0) {
    aac_bitwriter_write(w, (uint32_t)e->stereo_config_index, 2);
    if (e->stereo_config_index > 0) {
      write_mps212_config(w, e->stereo_config_index, &e->mps);
    }
  }
}

/* UsacExtElementConfig() (Table 13). */
void write_ext_element_config(AacBitWriter* w, const AacUsacElementConfig* e) {
  aac_usac_write_escaped(w, (unsigned)e->ext_type, 4, 8, 16);
  aac_usac_write_escaped(w, (unsigned)e->ext_config_len, 4, 8, 16);
  aac_bitwriter_write(w, e->ext_default_length > 0 ? 1u : 0u, 1);
  if (e->ext_default_length > 0) {
    aac_usac_write_escaped(w, (unsigned)(e->ext_default_length - 1), 8, 16, 0);
  }
  aac_bitwriter_write(w, e->ext_payload_frag ? 1u : 0u, 1);
  aac_bitwriter_write_bytes(w, e->ext_config, e->ext_config_len);
}

}  // namespace

void aac_usac_write_escaped(AacBitWriter* w, unsigned value, int bits1, int bits2, int bits3) {
  const unsigned max1 = (1u << bits1) - 1u;
  const unsigned max2 = (1u << bits2) - 1u;
  if (value < max1) {
    aac_bitwriter_write(w, value, bits1);
    return;
  }
  aac_bitwriter_write(w, max1, bits1);
  value -= max1;
  if (value < max2 || bits3 == 0) {
    aac_bitwriter_write(w, value, bits2);
    return;
  }
  aac_bitwriter_write(w, max2, bits2);
  aac_bitwriter_write(w, value - max2, bits3);
}

int aac_usac_config_write(const AacUsacConfig* c, AacBitWriter* w) {
  if (!c || !w || c->sample_rate <= 0 || c->core_sbr_frame_length_index < 0 ||
      c->core_sbr_frame_length_index > 4 || c->channel_config_index < 0 ||
      c->channel_config_index > 20 || c->num_elements < 1 ||
      c->num_elements > AAC_USAC_MAX_ELEMENTS || c->num_config_exts < 0 ||
      c->num_config_exts > AAC_USAC_MAX_CONFIG_EXTS) {
    return AAC_ERR_INVALID_ARG;
  }
  const int start = aac_bitwriter_bits_written(w);
  int rate_index = kUsacSamplingFrequencyEscape;
  for (int i = 0; i < 31; i++) {
    if (kUsacSampleRates[i] == c->sample_rate) {
      rate_index = i;
      break;
    }
  }
  aac_bitwriter_write(w, (uint32_t)rate_index, 5);
  if (rate_index == kUsacSamplingFrequencyEscape) {
    aac_bitwriter_write(w, (uint32_t)c->sample_rate, 24);
  }
  aac_bitwriter_write(w, (uint32_t)c->core_sbr_frame_length_index, 3);
  aac_bitwriter_write(w, (uint32_t)c->channel_config_index, 5);
  if (c->channel_config_index == 0) {
    /* UsacChannelConfig() (Table 4): numOutChannels and a position each. */
    if (c->num_channels < 0 || c->num_channels > AAC_MAX_CHANNELS) {
      return AAC_ERR_INVALID_ARG;
    }
    aac_usac_write_escaped(w, (unsigned)c->num_channels, 5, 8, 16);
    for (int i = 0; i < c->num_channels; i++) {
      aac_bitwriter_write(w, (uint32_t)c->channel_pos[i], 5);
    }
  }
  /* UsacDecoderConfig() (Table 6): the element list. */
  const int sbr_ratio_index = kSbrRatioIndex[c->core_sbr_frame_length_index];
  aac_usac_write_escaped(w, (unsigned)(c->num_elements - 1), 4, 8, 16);
  for (int i = 0; i < c->num_elements; i++) {
    const AacUsacElementConfig* e = &c->elem[i];
    if (e->type < 0 || e->type > 3 ||
        (e->type == AAC_USAC_EXT &&
         (e->ext_config_len < 0 || e->ext_config_len > AAC_USAC_MAX_EXT_CONFIG))) {
      return AAC_ERR_INVALID_ARG;
    }
    aac_bitwriter_write(w, (uint32_t)e->type, 2);
    switch (e->type) {
      case AAC_USAC_SCE:
      case AAC_USAC_CPE:
        write_channel_element_config(w, sbr_ratio_index, e);
        break;
      case AAC_USAC_LFE:
        break; /* UsacLfeElementConfig() carries nothing */
      default:
        write_ext_element_config(w, e);
        break;
    }
  }
  /* UsacConfigExtension() (Table 15). */
  aac_bitwriter_write(w, c->num_config_exts > 0 ? 1u : 0u, 1);
  if (c->num_config_exts > 0) {
    aac_usac_write_escaped(w, (unsigned)(c->num_config_exts - 1), 2, 4, 8);
    for (int i = 0; i < c->num_config_exts; i++) {
      const AacUsacConfigExt* x = &c->config_ext[i];
      if (x->len < 0 || x->len > AAC_USAC_MAX_CONFIG_EXT_BYTES) {
        return AAC_ERR_INVALID_ARG;
      }
      aac_usac_write_escaped(w, (unsigned)x->type, 4, 8, 16);
      aac_usac_write_escaped(w, (unsigned)x->len, 4, 8, 16);
      aac_bitwriter_write_bytes(w, x->data, x->len);
    }
  }
  if (aac_bitwriter_overflow(w)) {
    return AAC_ERR_OVERFLOW;
  }
  return aac_bitwriter_bits_written(w) - start;
}
