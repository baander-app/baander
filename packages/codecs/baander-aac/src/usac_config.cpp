#include <cstring>

#include "bitstream.h"
#include "channel_map.h"
#include "usac.h"

/* UsacConfig() (ISO/IEC 23003-3 5.1 and Tables 1 to 14): the sampling
 * frequency, the core and SBR frame lengths, the channel configuration,
 * the element list with each element's configuration, and the
 * configuration extensions.  Every field is read as the standard lays
 * it out, including the ones the decoder refuses, so that the raw bits
 * are the whole configuration and an IPF can compare them. */

namespace {

/* usacSamplingFrequencyIndex (Table 5.1): the 14496-3 rates and the
 * extra rates of USAC; 0 marks a reserved index, 31 is the escape to a
 * 24-bit rate. */
constexpr int kUsacSamplingFrequencyEscape = 31;
const int kUsacSampleRates[31] = {96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050,
                                  16000, 12000, 11025, 8000,  7350,  0,     0,     57600,
                                  51200, 40000, 38400, 34150, 28800, 25600, 20000, 19200,
                                  17075, 14400, 12800, 9600,  0,     0,     0};

/* coreSbrFrameLengthIndex (Table 70): core frame length, output frame
 * length and sbrRatioIndex. */
constexpr int kNumCoreSbrFrameLengths = 5;
const int kCoreFrameLength[kNumCoreSbrFrameLengths] = {768, 1024, 768, 1024, 1024};
const int kOutputFrameLength[kNumCoreSbrFrameLengths] = {768, 1024, 2048, 2048, 4096};
const int kSbrRatioIndex[kNumCoreSbrFrameLengths] = {
    AAC_USAC_SBR_NONE, AAC_USAC_SBR_NONE, AAC_USAC_SBR_8_3, AAC_USAC_SBR_2_1, AAC_USAC_SBR_4_1};

/* Output channels of channelConfigurationIndex 1..20 (Table 5.3 /
 * ISO/IEC 23001-8); 0 is defined by the bsOutputChannelPos list. */
constexpr int kNumChannelConfigs = 21;
const int kChannelConfigChannels[kNumChannelConfigs] = {0, 1, 2,  3, 4,  5,  6,  8,  2,  3, 4,
                                                        7, 8, 24, 8, 12, 10, 12, 14, 12, 14};

/* bsOutputChannelPos (ISO/IEC 23001-8 Table 7 as USAC uses it): the
 * positions the decoder's layouts can hold, as AacSpeaker; -1 refused. */
constexpr int kNumOutputChannelPos = 32;
const int kOutputChannelPosSpeaker[kNumOutputChannelPos] = {
    AAC_SPK_FL,  AAC_SPK_FR,  AAC_SPK_FC, AAC_SPK_LFE, AAC_SPK_BL, AAC_SPK_BR,  /* 0..5 */
    AAC_SPK_FLC, AAC_SPK_FRC, -1,         -1,          AAC_SPK_BC, -1,          /* 6..11 */
    -1,          AAC_SPK_SL,  AAC_SPK_SR, -1,          -1,         AAC_SPK_TFL, /* 12..17 */
    AAC_SPK_TFR, -1,          -1,         -1,          -1,         -1,          /* 18..23 */
    -1,          -1,          -1,         -1,          -1,         -1,          /* 24..29 */
    -1,          -1};

/* SbrDfltHeader() defaults for the fields a header leaves out
 * (ISO/IEC 14496-3 4.6.18.3.1). */
void sbr_header_defaults(AacUsacSbrHeader* h) {
  h->freq_scale = 2;
  h->alter_scale = 1;
  h->noise_bands = 2;
  h->limiter_bands = 2;
  h->limiter_gains = 2;
  h->interpol_freq = 1;
  h->smoothing_mode = 1;
}

/* SbrConfig() (Table 10) with SbrDfltHeader() (Table 11). */
void read_sbr_config(AacBitReader* r, AacUsacSbrConfig* c) {
  c->harmonic_sbr = (int)aac_bitreader_read(r, 1);
  c->inter_tes = (int)aac_bitreader_read(r, 1);
  c->pvc = (int)aac_bitreader_read(r, 1);
  AacUsacSbrHeader* h = &c->dflt;
  sbr_header_defaults(h);
  h->start_freq = (int)aac_bitreader_read(r, 4);
  h->stop_freq = (int)aac_bitreader_read(r, 4);
  h->header_extra1 = (int)aac_bitreader_read(r, 1);
  h->header_extra2 = (int)aac_bitreader_read(r, 1);
  if (h->header_extra1) {
    h->freq_scale = (int)aac_bitreader_read(r, 2);
    h->alter_scale = (int)aac_bitreader_read(r, 1);
    h->noise_bands = (int)aac_bitreader_read(r, 2);
  }
  if (h->header_extra2) {
    h->limiter_bands = (int)aac_bitreader_read(r, 2);
    h->limiter_gains = (int)aac_bitreader_read(r, 2);
    h->interpol_freq = (int)aac_bitreader_read(r, 1);
    h->smoothing_mode = (int)aac_bitreader_read(r, 1);
  }
}

/* Mps212Config() (Table 12).  Returns AAC_ERR_DECODE for the reserved
 * bsFreqRes 0 (no parameter band count). */
int read_mps212_config(AacBitReader* r, int stereo_config_index, AacUsacMps212Config* m) {
  m->freq_res = (int)aac_bitreader_read(r, 3);
  m->fixed_gain_dmx = (int)aac_bitreader_read(r, 3);
  m->temp_shape_config = (int)aac_bitreader_read(r, 2);
  m->decorr_config = (int)aac_bitreader_read(r, 2);
  m->high_rate_mode = (int)aac_bitreader_read(r, 1);
  m->phase_coding = (int)aac_bitreader_read(r, 1);
  m->ott_bands_phase_present = (int)aac_bitreader_read(r, 1);
  if (m->ott_bands_phase_present) {
    m->ott_bands_phase = (int)aac_bitreader_read(r, 5);
  }
  m->residual_coding = stereo_config_index > 1;
  if (m->residual_coding) {
    m->residual_bands = (int)aac_bitreader_read(r, 5);
    m->pseudo_lr = (int)aac_bitreader_read(r, 1);
  }
  if (m->temp_shape_config == 2) {
    m->env_quant_mode = (int)aac_bitreader_read(r, 1);
  }
  return m->freq_res == 0 ? AAC_ERR_DECODE : AAC_OK;
}

/* `len` whole bytes into `out` (capacity `cap`); AAC_ERR_UNSUPPORTED when
 * they do not fit, AAC_ERR_DECODE when the stream ends first. */
int read_bytes(AacBitReader* r, int len, uint8_t* out, int cap) {
  if (len > cap) {
    return AAC_ERR_UNSUPPORTED;
  }
  if (aac_bitreader_bits_left(r) < 8 * len) {
    return AAC_ERR_DECODE;
  }
  for (int i = 0; i < len; i++) {
    out[i] = (uint8_t)aac_bitreader_read(r, 8);
  }
  return AAC_OK;
}

/* UsacCoreConfig() (Table 8), SbrConfig and, for a CPE, the stereo
 * configuration with Mps212Config (Tables 9 and 12). */
int read_channel_element_config(AacBitReader* r, const AacUsacConfig* c, AacUsacElementConfig* e) {
  e->tw_mdct = (int)aac_bitreader_read(r, 1);
  e->noise_filling = (int)aac_bitreader_read(r, 1);
  if (c->sbr_ratio_index > 0) {
    read_sbr_config(r, &e->sbr);
  }
  if (e->type == AAC_USAC_CPE) {
    e->stereo_config_index = c->sbr_ratio_index > 0 ? (int)aac_bitreader_read(r, 2) : 0;
    if (e->stereo_config_index > 0) {
      const int ret = read_mps212_config(r, e->stereo_config_index, &e->mps);
      if (ret) {
        return ret;
      }
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* UsacExtElementConfig() (Table 13). */
int read_ext_element_config(AacBitReader* r, AacUsacElementConfig* e) {
  e->ext_type = (int)aac_usac_escaped_value(r, 4, 8, 16);
  const int len = (int)aac_usac_escaped_value(r, 4, 8, 16);
  if (aac_bitreader_read(r, 1)) { /* usacExtElementDefaultLengthPresent */
    e->ext_default_length = (int)aac_usac_escaped_value(r, 8, 16, 0) + 1;
  }
  e->ext_payload_frag = (int)aac_bitreader_read(r, 1);
  if (r->overread) {
    return AAC_ERR_DECODE;
  }
  const int ret = read_bytes(r, len, e->ext_config, AAC_USAC_MAX_EXT_CONFIG);
  e->ext_config_len = ret == AAC_OK ? len : 0;
  return ret;
}

/* UsacDecoderConfig() (Table 6): the element list. */
int read_decoder_config(AacBitReader* r, AacUsacConfig* c) {
  const int num_elements = (int)aac_usac_escaped_value(r, 4, 8, 16) + 1;
  if (r->overread) {
    return AAC_ERR_DECODE;
  }
  if (num_elements > AAC_USAC_MAX_ELEMENTS) {
    return AAC_ERR_UNSUPPORTED;
  }
  c->num_elements = num_elements;
  for (int i = 0; i < num_elements; i++) {
    AacUsacElementConfig* e = &c->elem[i];
    e->type = (int)aac_bitreader_read(r, 2);
    int ret = AAC_OK;
    switch (e->type) {
      case AAC_USAC_SCE:
      case AAC_USAC_CPE:
        ret = read_channel_element_config(r, c, e);
        break;
      case AAC_USAC_LFE:
        break; /* UsacLfeElementConfig() carries nothing */
      default:
        ret = read_ext_element_config(r, e);
        break;
    }
    if (ret) {
      return ret;
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* UsacConfigExtension() (Table 15). */
int read_config_extension(AacBitReader* r, AacUsacConfig* c) {
  const int n = (int)aac_usac_escaped_value(r, 2, 4, 8) + 1;
  if (r->overread) {
    return AAC_ERR_DECODE;
  }
  if (n > AAC_USAC_MAX_CONFIG_EXTS) {
    return AAC_ERR_UNSUPPORTED;
  }
  c->num_config_exts = n;
  for (int i = 0; i < n; i++) {
    AacUsacConfigExt* x = &c->config_ext[i];
    x->type = (int)aac_usac_escaped_value(r, 4, 8, 16);
    const int len = (int)aac_usac_escaped_value(r, 4, 8, 16);
    if (r->overread) {
      return AAC_ERR_DECODE;
    }
    const int ret = read_bytes(r, len, x->data, AAC_USAC_MAX_CONFIG_EXT_BYTES);
    if (ret) {
      return ret;
    }
    x->len = len;
    if (x->type == AAC_USAC_CFG_EXT_STREAM_ID && len >= 2) {
      c->stream_id = (x->data[0] << 8) | x->data[1];
    }
  }
  return AAC_OK;
}

/* The bits from `start` to the reader's position, left aligned into
 * c->raw. */
int keep_raw_bits(AacBitReader* r, int start, AacUsacConfig* c) {
  const int bits = r->pos - start;
  if (bits > 8 * AAC_USAC_MAX_CONFIG_BYTES) {
    return AAC_ERR_UNSUPPORTED;
  }
  AacBitReader copy = *r;
  copy.pos = start;
  copy.overread = 0;
  int i = 0;
  for (; i + 8 <= bits; i += 8) {
    c->raw[i >> 3] = (uint8_t)aac_bitreader_read(&copy, 8);
  }
  if (i < bits) {
    c->raw[i >> 3] = (uint8_t)(aac_bitreader_read(&copy, bits - i) << (8 - (bits - i)));
  }
  c->raw_bits = bits;
  return AAC_OK;
}

/* The AacElementPosition and height a pair of speakers belongs to in a
 * layout, or 0 for a pair no layout element can hold. */
int pair_position(int left, int right, int* height) {
  *height = AAC_HEIGHT_NORMAL;
  if (left == AAC_SPK_FL && right == AAC_SPK_FR) {
    return AAC_POS_FRONT;
  }
  if (left == AAC_SPK_FLC && right == AAC_SPK_FRC) {
    return AAC_POS_FRONT;
  }
  if (left == AAC_SPK_BL && right == AAC_SPK_BR) {
    return AAC_POS_BACK;
  }
  if (left == AAC_SPK_SL && right == AAC_SPK_SR) {
    return AAC_POS_SIDE;
  }
  if (left == AAC_SPK_TFL && right == AAC_SPK_TFR) {
    *height = AAC_HEIGHT_TOP;
    return AAC_POS_FRONT;
  }
  return 0;
}

/* The layout of channelConfigurationIndex 0: the channel elements take
 * the bsOutputChannelPos list in order, each becomes a layout element at
 * the position its speakers belong to, and the layout rules must then
 * hand every channel the speaker the stream named. */
int layout_from_positions(const AacUsacConfig* c, AacLayout* layout) {
  AacLayoutElement elems[AAC_MAX_ELEMENTS] = {};
  int wanted[AAC_MAX_ELEMENTS][2];
  int n = 0;
  int next_pos = 0;
  int tags[4] = {0, 0, 0, 0};
  for (int i = 0; i < c->num_elements; i++) {
    const int type = c->elem[i].type;
    if (type == AAC_USAC_EXT) {
      continue;
    }
    const int nch = type == AAC_USAC_CPE ? 2 : 1;
    if (next_pos + nch > c->num_channels) {
      return AAC_ERR_DECODE; /* more channels in the elements than positions */
    }
    int spk[2] = {-1, -1};
    for (int k = 0; k < nch; k++) {
      const int pos = c->channel_pos[next_pos + k];
      spk[k] = pos >= 0 && pos < kNumOutputChannelPos ? kOutputChannelPosSpeaker[pos] : -1;
      if (spk[k] < 0) {
        return AAC_ERR_UNSUPPORTED;
      }
    }
    next_pos += nch;
    AacLayoutElement* e = &elems[n];
    e->height = AAC_HEIGHT_NORMAL;
    if (type == AAC_USAC_LFE) {
      if (spk[0] != AAC_SPK_LFE) {
        return AAC_ERR_UNSUPPORTED;
      }
      e->type = AAC_ELEM_LFE;
      e->position = AAC_POS_LFE;
    } else if (type == AAC_USAC_SCE) {
      e->type = AAC_ELEM_SCE;
      if (spk[0] == AAC_SPK_FC) {
        e->position = AAC_POS_FRONT;
      } else if (spk[0] == AAC_SPK_BC) {
        e->position = AAC_POS_BACK;
      } else {
        return AAC_ERR_UNSUPPORTED;
      }
    } else {
      e->type = AAC_ELEM_CPE;
      e->position = pair_position(spk[0], spk[1], &e->height);
      if (!e->position) {
        return AAC_ERR_UNSUPPORTED;
      }
    }
    e->tag = tags[e->type]++;
    wanted[n][0] = spk[0];
    wanted[n][1] = spk[1];
    n++;
  }
  if (next_pos != c->num_channels || n == 0) {
    return AAC_ERR_DECODE;
  }
  AacLayout l = {};
  const int ret = aac_layout_from_elements(&l, elems, n);
  if (ret) {
    return ret;
  }
  for (int i = 0; i < n; i++) {
    const int nch = l.elems[i].type == AAC_ELEM_CPE ? 2 : 1;
    for (int k = 0; k < nch; k++) {
      if (l.elems[i].speaker[k] != wanted[i][k]) {
        return AAC_ERR_UNSUPPORTED; /* a placement the layout rules do not reproduce */
      }
    }
  }
  l.channel_config = 0;
  *layout = l;
  return AAC_OK;
}

/* Whether the channel elements of the configuration, in order, are the
 * elements of the layout. */
bool elements_match_layout(const AacUsacConfig* c, const AacLayout* l) {
  int n = 0;
  for (int i = 0; i < c->num_elements; i++) {
    const int type = c->elem[i].type;
    if (type == AAC_USAC_EXT) {
      continue;
    }
    if (n >= l->num_elements) {
      return false;
    }
    const int want = type == AAC_USAC_SCE   ? AAC_ELEM_SCE
                     : type == AAC_USAC_CPE ? AAC_ELEM_CPE
                                            : AAC_ELEM_LFE;
    if (l->elems[n].type != want) {
      return false;
    }
    n++;
  }
  return n == l->num_elements;
}

}  // namespace

unsigned aac_usac_escaped_value(AacBitReader* r, int bits1, int bits2, int bits3) {
  unsigned value = aac_bitreader_read(r, bits1);
  if (value == (1u << bits1) - 1u) {
    const unsigned value2 = aac_bitreader_read(r, bits2);
    value += value2;
    if (value2 == (1u << bits2) - 1u && bits3 > 0) {
      value += aac_bitreader_read(r, bits3);
    }
  }
  return value;
}

int aac_usac_config_read(AacBitReader* r, AacUsacConfig* c) {
  memset(c, 0, sizeof(*c));
  c->stream_id = -1;
  const int start = r->pos;
  const int rate_index = (int)aac_bitreader_read(r, 5);
  if (rate_index == kUsacSamplingFrequencyEscape) {
    c->sample_rate = (int)aac_bitreader_read(r, 24);
  } else {
    c->sample_rate = kUsacSampleRates[rate_index];
  }
  c->core_sbr_frame_length_index = (int)aac_bitreader_read(r, 3);
  c->channel_config_index = (int)aac_bitreader_read(r, 5);
  if (r->overread || c->sample_rate <= 0 ||
      c->core_sbr_frame_length_index >= kNumCoreSbrFrameLengths ||
      c->channel_config_index >= kNumChannelConfigs) {
    return AAC_ERR_DECODE;
  }
  c->frame_length = kCoreFrameLength[c->core_sbr_frame_length_index];
  c->output_frame_length = kOutputFrameLength[c->core_sbr_frame_length_index];
  c->sbr_ratio_index = kSbrRatioIndex[c->core_sbr_frame_length_index];
  c->core_sample_rate = c->sample_rate * c->frame_length / c->output_frame_length;
  if (c->channel_config_index == 0) {
    /* UsacChannelConfig() (Table 4): numOutChannels and a position each. */
    const int n = (int)aac_usac_escaped_value(r, 5, 8, 16);
    if (r->overread) {
      return AAC_ERR_DECODE;
    }
    if (n > AAC_MAX_CHANNELS) {
      return AAC_ERR_UNSUPPORTED;
    }
    c->num_channels = n;
    for (int i = 0; i < n; i++) {
      c->channel_pos[i] = (int)aac_bitreader_read(r, 5);
    }
  } else {
    c->num_channels = kChannelConfigChannels[c->channel_config_index];
    if (c->num_channels > AAC_MAX_CHANNELS) {
      return AAC_ERR_UNSUPPORTED;
    }
  }
  int ret = read_decoder_config(r, c);
  if (ret) {
    return ret;
  }
  if (aac_bitreader_read(r, 1)) { /* usacConfigExtensionPresent */
    ret = read_config_extension(r, c);
    if (ret) {
      return ret;
    }
  }
  if (r->overread) {
    return AAC_ERR_DECODE;
  }
  ret = keep_raw_bits(r, start, c);
  if (ret) {
    return ret;
  }
  /* Whole and kept; now the tools outside xHE-AAC: the time-warped MDCT
   * and the MPEG Surround and SAOC extension elements. */
  for (int i = 0; i < c->num_elements; i++) {
    const AacUsacElementConfig* e = &c->elem[i];
    const bool channel = e->type == AAC_USAC_SCE || e->type == AAC_USAC_CPE;
    if ((channel && e->tw_mdct) ||
        (e->type == AAC_USAC_EXT &&
         (e->ext_type == AAC_USAC_EXT_MPEGS || e->ext_type == AAC_USAC_EXT_SAOC))) {
      return AAC_ERR_UNSUPPORTED;
    }
  }
  return AAC_OK;
}

int aac_usac_config_equal(const AacUsacConfig* a, const AacUsacConfig* b) {
  return a->raw_bits == b->raw_bits && memcmp(a->raw, b->raw, (size_t)((a->raw_bits + 7) / 8)) == 0;
}

int aac_usac_config_layout(const AacUsacConfig* c, AacLayout* layout) {
  AacLayout l = {};
  int ret;
  switch (c->channel_config_index) {
    case 0:
      ret = layout_from_positions(c, &l);
      break;
    case 1:
    case 2:
    case 3:
    case 4:
    case 5:
    case 6:
    case 7:
    case 11:
    case 12:
    case 14:
      ret = aac_layout_from_config(&l, c->channel_config_index);
      break;
    default:
      ret = AAC_ERR_UNSUPPORTED;
      break;
  }
  if (ret) {
    return ret;
  }
  if (l.num_channels != c->num_channels || !elements_match_layout(c, &l)) {
    return AAC_ERR_DECODE;
  }
  *layout = l;
  return AAC_OK;
}
