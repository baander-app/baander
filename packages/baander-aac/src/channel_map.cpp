#include "channel_map.h"

#include <cstring>

#include "bitstream.h"

namespace {

constexpr int kNone = -1;
constexpr int kUnused = -2;

/* Speakers available to the n-th channel of a position: the first entry
 * takes a leading single channel, entries 1..4 the pairs, entry 5 a
 * trailing single channel. */
const int kSpeakerMap[4][6] = {
    {AAC_SPK_FC, AAC_SPK_FLC, AAC_SPK_FRC, AAC_SPK_FL, AAC_SPK_FR, kNone}, /* front */
    {kUnused, AAC_SPK_SL, AAC_SPK_SR, kNone, kNone, kNone},               /* side */
    {kUnused, AAC_SPK_SL, AAC_SPK_SR, AAC_SPK_BL, AAC_SPK_BR, AAC_SPK_BC}, /* back */
    {AAC_SPK_LFE, kNone, kNone, kNone, kNone, kNone},                     /* lfe */
};

struct ConfigElement {
  int type, tag, position;
  int height = AAC_HEIGHT_NORMAL;
};

const ConfigElement kConfig1[] = {{AAC_ELEM_SCE, 0, AAC_POS_FRONT}};
const ConfigElement kConfig2[] = {{AAC_ELEM_CPE, 0, AAC_POS_FRONT}};
const ConfigElement kConfig3[] = {{AAC_ELEM_SCE, 0, AAC_POS_FRONT}, {AAC_ELEM_CPE, 0, AAC_POS_FRONT}};
const ConfigElement kConfig4[] = {{AAC_ELEM_SCE, 0, AAC_POS_FRONT},
                                  {AAC_ELEM_CPE, 0, AAC_POS_FRONT},
                                  {AAC_ELEM_SCE, 1, AAC_POS_BACK}};
const ConfigElement kConfig5[] = {{AAC_ELEM_SCE, 0, AAC_POS_FRONT},
                                  {AAC_ELEM_CPE, 0, AAC_POS_FRONT},
                                  {AAC_ELEM_CPE, 1, AAC_POS_BACK}};
const ConfigElement kConfig6[] = {{AAC_ELEM_SCE, 0, AAC_POS_FRONT},
                                  {AAC_ELEM_CPE, 0, AAC_POS_FRONT},
                                  {AAC_ELEM_CPE, 1, AAC_POS_BACK},
                                  {AAC_ELEM_LFE, 0, AAC_POS_LFE}};
const ConfigElement kConfig7[] = {{AAC_ELEM_SCE, 0, AAC_POS_FRONT},
                                  {AAC_ELEM_CPE, 0, AAC_POS_FRONT},
                                  {AAC_ELEM_CPE, 1, AAC_POS_FRONT},
                                  {AAC_ELEM_CPE, 2, AAC_POS_BACK},
                                  {AAC_ELEM_LFE, 0, AAC_POS_LFE}};
const ConfigElement kConfig11[] = {{AAC_ELEM_SCE, 0, AAC_POS_FRONT},
                                   {AAC_ELEM_CPE, 0, AAC_POS_FRONT},
                                   {AAC_ELEM_CPE, 1, AAC_POS_BACK},
                                   {AAC_ELEM_SCE, 1, AAC_POS_BACK},
                                   {AAC_ELEM_LFE, 0, AAC_POS_LFE}};
const ConfigElement kConfig12[] = {{AAC_ELEM_SCE, 0, AAC_POS_FRONT},
                                   {AAC_ELEM_CPE, 0, AAC_POS_FRONT},
                                   {AAC_ELEM_CPE, 1, AAC_POS_BACK},
                                   {AAC_ELEM_CPE, 2, AAC_POS_BACK},
                                   {AAC_ELEM_LFE, 0, AAC_POS_LFE}};
/* Configuration 14: 5.1 with a front pair in the top layer after the LFE. */
const ConfigElement kConfig14[] = {{AAC_ELEM_SCE, 0, AAC_POS_FRONT},
                                   {AAC_ELEM_CPE, 0, AAC_POS_FRONT},
                                   {AAC_ELEM_CPE, 1, AAC_POS_BACK},
                                   {AAC_ELEM_LFE, 0, AAC_POS_LFE},
                                   {AAC_ELEM_CPE, 2, AAC_POS_FRONT, AAC_HEIGHT_TOP}};
/* The rear-surround 7.1 mode has no configuration number: C, L+R, a side
 * pair, a back pair and LFE, signalled by a program config element. */
const ConfigElement kRearSurround[] = {{AAC_ELEM_SCE, 0, AAC_POS_FRONT},
                                       {AAC_ELEM_CPE, 0, AAC_POS_FRONT},
                                       {AAC_ELEM_CPE, 1, AAC_POS_SIDE},
                                       {AAC_ELEM_CPE, 2, AAC_POS_BACK},
                                       {AAC_ELEM_LFE, 0, AAC_POS_LFE}};

struct Config {
  const ConfigElement* elems;
  int count;
};

const Config kConfigs[16] = {
    {nullptr, 0},
    {kConfig1, 1},
    {kConfig2, 1},
    {kConfig3, 2},
    {kConfig4, 3},
    {kConfig5, 3},
    {kConfig6, 4},
    {kConfig7, 5},
    {nullptr, 0},
    {nullptr, 0},
    {nullptr, 0},
    {kConfig11, 5},
    {kConfig12, 5},
    {nullptr, 0},
    {kConfig14, 5},
    {nullptr, 0},
};

int element_channels(int type) { return type == AAC_ELEM_CPE ? 2 : 1; }

/* Channels of the elements at `pos` starting at index `first`, or -1 when
 * single channels and pairs are mixed in a way that cannot be placed. */
int count_position_channels(const AacLayoutElement* e, int n, int pos, int first) {
  int channels = 0;
  int first_cpe = 0;
  int sce_parity = 0;
  for (int i = first; i < n; i++) {
    if (e[i].position != pos) {
      break;
    }
    if (e[i].type == AAC_ELEM_CPE) {
      if (sce_parity) {
        if (pos == AAC_POS_FRONT && !first_cpe) {
          sce_parity = 0;
        } else {
          return -1;
        }
      }
      channels += 2;
      first_cpe = 1;
    } else {
      channels++;
      sce_parity ^= pos != AAC_POS_LFE;
    }
  }
  if (sce_parity && pos == AAC_POS_FRONT && first_cpe) {
    return -1;
  }
  return channels;
}

/* Assigns speakers to the elements at `pos` from index *current on. */
int assign_position(AacLayoutElement* e, int n, int pos, int* current) {
  const int* map = kSpeakerMap[pos - 1];
  int i = *current;
  int channels = count_position_channels(e, n, pos, i);
  if (channels < 0 || channels > 5) {
    return -1;
  }
  if (pos == AAC_POS_LFE) {
    for (int j = 0; channels > 0; j++, channels--, i++) {
      if (map[j] == kNone) {
        return -1;
      }
      e[i].speaker[0] = map[j];
    }
    *current = i;
    return 0;
  }
  while (channels & 1) {
    if (map[0] == kNone) {
      return -1;
    }
    if (map[0] == kUnused) {
      break;
    }
    e[i].speaker[0] = map[0];
    i++;
    channels--;
  }
  int j = (pos != AAC_POS_SIDE && channels <= 3) ? 3 : 1;
  while (channels >= 2) {
    if (map[j] == kNone || map[j + 1] == kNone) {
      return -1;
    }
    if (e[i].type == AAC_ELEM_CPE) {
      e[i].speaker[0] = map[j];
      e[i].speaker[1] = map[j + 1];
      i++;
    } else {
      e[i].speaker[0] = map[j];
      e[i + 1].speaker[0] = map[j + 1];
      i += 2;
    }
    j += 2;
    channels -= 2;
  }
  while (channels & 1) {
    if (map[5] == kNone) {
      return -1;
    }
    e[i].speaker[0] = map[5];
    i++;
    channels--;
  }
  *current = i;
  return 0;
}

}  // namespace

int aac_layout_from_elements(AacLayout* l, const AacLayoutElement* elems, int num_elements) {
  if (num_elements <= 0 || num_elements > AAC_MAX_ELEMENTS) {
    return AAC_ERR_UNSUPPORTED;
  }
  AacLayout out = {};
  out.num_elements = num_elements;
  memcpy(out.elems, elems, sizeof(AacLayoutElement) * (size_t)num_elements);
  for (int i = 0; i < num_elements; i++) {
    out.elems[i].speaker[0] = out.elems[i].speaker[1] = kNone;
    out.elems[i].slot[0] = out.elems[i].slot[1] = -1;
    out.num_channels += element_channels(out.elems[i].type);
  }
  if (out.num_channels > AAC_MAX_CHANNELS) {
    return AAC_ERR_UNSUPPORTED;
  }
  /* The normal layer is placed by position; the only height element with
   * a speaker pair is a single front pair in the top layer. */
  AacLayoutElement normal[AAC_MAX_ELEMENTS];
  int normal_index[AAC_MAX_ELEMENTS];
  int num_normal = 0;
  int top_front = -1;
  bool placed = true;
  for (int i = 0; i < num_elements; i++) {
    const AacLayoutElement* e = &out.elems[i];
    if (e->height == AAC_HEIGHT_NORMAL) {
      normal_index[num_normal] = i;
      normal[num_normal++] = *e;
    } else if (e->height == AAC_HEIGHT_TOP && e->type == AAC_ELEM_CPE &&
               e->position == AAC_POS_FRONT && top_front < 0) {
      top_front = i;
      out.elems[i].speaker[0] = AAC_SPK_TFL;
      out.elems[i].speaker[1] = AAC_SPK_TFR;
    } else {
      placed = false;
    }
  }
  int current = 0;
  for (int pos = AAC_POS_FRONT; pos <= AAC_POS_LFE && placed; pos++) {
    placed = assign_position(normal, num_normal, pos, &current) == 0;
  }
  placed = placed && current == num_normal;
  for (int i = 0; i < num_normal; i++) {
    out.elems[normal_index[i]].speaker[0] = normal[i].speaker[0];
    out.elems[normal_index[i]].speaker[1] = normal[i].speaker[1];
  }
  int used[AAC_SPK_COUNT] = {};
  for (int i = 0; i < num_elements && placed; i++) {
    for (int c = 0; c < element_channels(out.elems[i].type); c++) {
      const int spk = out.elems[i].speaker[c];
      if (spk < 0 || used[spk]) {
        placed = false;
        break;
      }
      used[spk] = 1;
    }
  }
  if (!placed) {
    /* No speaker assignment fits (positions out of order, a lone side
     * channel, a centre after a pair): the channels are emitted in
     * element order with unknown speakers, as other decoders do. */
    int slot = 0;
    for (int i = 0; i < num_elements; i++) {
      for (int c = 0; c < element_channels(out.elems[i].type); c++) {
        out.elems[i].speaker[c] = kNone;
        out.elems[i].slot[c] = slot;
        out.slot_speaker[slot++] = kNone;
      }
    }
    out.num_cce = l->num_cce;
    memcpy(out.cce_tag, l->cce_tag, sizeof(out.cce_tag));
    *l = out;
    return 0;
  }
  /* Output slots in speaker order. */
  int slot = 0;
  int slot_of[AAC_SPK_COUNT];
  for (int spk = 0; spk < AAC_SPK_COUNT; spk++) {
    slot_of[spk] = used[spk] ? slot : -1;
    if (used[spk]) {
      out.slot_speaker[slot++] = spk;
    }
  }
  for (int i = 0; i < num_elements; i++) {
    for (int c = 0; c < element_channels(out.elems[i].type); c++) {
      out.elems[i].slot[c] = slot_of[out.elems[i].speaker[c]];
    }
  }
  out.num_cce = l->num_cce;
  memcpy(out.cce_tag, l->cce_tag, sizeof(out.cce_tag));
  *l = out;
  return 0;
}

int aac_layout_from_config(AacLayout* l, int channel_config) {
  if (channel_config < 0 || channel_config >= 16 || kConfigs[channel_config].count == 0) {
    return AAC_ERR_UNSUPPORTED;
  }
  const Config& cfg = kConfigs[channel_config];
  AacLayoutElement elems[AAC_MAX_ELEMENTS] = {};
  for (int i = 0; i < cfg.count; i++) {
    elems[i].type = cfg.elems[i].type;
    elems[i].tag = cfg.elems[i].tag;
    elems[i].position = cfg.elems[i].position;
    elems[i].height = cfg.elems[i].height;
  }
  l->num_cce = 0;
  const int ret = aac_layout_from_elements(l, elems, cfg.count);
  if (ret == 0) {
    l->channel_config = channel_config;
  }
  if (ret == 0 && channel_config == 7) {
    /* Configuration 7 as FFmpeg decodes it: the first front pair is
     * left/right and the second the outer pair, the reverse of the
     * placement used for program config elements. */
    l->elems[1].speaker[0] = AAC_SPK_FL;
    l->elems[1].speaker[1] = AAC_SPK_FR;
    l->elems[1].slot[0] = 0;
    l->elems[1].slot[1] = 1;
    l->elems[2].speaker[0] = AAC_SPK_FLC;
    l->elems[2].speaker[1] = AAC_SPK_FRC;
    l->elems[2].slot[0] = 6;
    l->elems[2].slot[1] = 7;
  }
  return ret;
}

int aac_layout_from_mode(AacLayout* l, AacChannelMode mode) {
  switch (mode) {
    case AAC_MODE_7_1_FRONT_CENTER:
    case AAC_MODE_7_1_BACK:
    case AAC_MODE_7_1_TOP_FRONT:
      return aac_layout_from_config(l, (int)mode);
    case AAC_MODE_7_1_REAR_SURROUND: {
      AacLayoutElement elems[AAC_MAX_ELEMENTS] = {};
      const int n = (int)(sizeof(kRearSurround) / sizeof(kRearSurround[0]));
      for (int i = 0; i < n; i++) {
        elems[i].type = kRearSurround[i].type;
        elems[i].tag = kRearSurround[i].tag;
        elems[i].position = kRearSurround[i].position;
      }
      l->num_cce = 0;
      const int ret = aac_layout_from_elements(l, elems, n);
      if (ret == 0) {
        l->channel_config = 0;
      }
      return ret;
    }
    default:
      return AAC_ERR_INVALID_ARG;
  }
}

AacChannelMode aac_layout_mode(const AacLayout* l) {
  if (l->num_channels != 8) {
    return AAC_MODE_DEFAULT;
  }
  int front_pairs = 0;
  int side_pairs = 0;
  int back_pairs = 0;
  int top_front_pairs = 0;
  for (int i = 0; i < l->num_elements; i++) {
    const AacLayoutElement* e = &l->elems[i];
    if (e->type != AAC_ELEM_CPE || e->speaker[0] < 0) {
      continue;
    }
    if (e->height == AAC_HEIGHT_TOP) {
      top_front_pairs += e->position == AAC_POS_FRONT;
    } else if (e->position == AAC_POS_FRONT) {
      front_pairs++;
    } else if (e->position == AAC_POS_SIDE) {
      side_pairs++;
    } else if (e->position == AAC_POS_BACK) {
      back_pairs++;
    }
  }
  if (top_front_pairs == 1) {
    return AAC_MODE_7_1_TOP_FRONT;
  }
  if (side_pairs == 1 && back_pairs == 1) {
    return AAC_MODE_7_1_REAR_SURROUND;
  }
  if (front_pairs == 2) {
    return AAC_MODE_7_1_FRONT_CENTER;
  }
  if (back_pairs == 2) {
    return AAC_MODE_7_1_BACK;
  }
  return AAC_MODE_DEFAULT;
}

uint8_t aac_pce_height_crc(const uint8_t* bytes, int n) {
  uint8_t crc = 0xFF;
  for (int i = 0; i < n; i++) {
    crc ^= bytes[i];
    for (int b = 0; b < 8; b++) {
      crc = (uint8_t)((crc & 0x80) ? (crc << 1) ^ 0x07 : (crc << 1));
    }
  }
  return crc;
}

int aac_layout_find(const AacLayout* l, int type, int tag) {
  for (int i = 0; i < l->num_elements; i++) {
    if (l->elems[i].type == type && l->elems[i].tag == tag) {
      return i;
    }
  }
  return -1;
}

int aac_layout_config_for_elements(const AacLayoutElement* elems, int num_elements) {
  for (int c = 1; c < 16; c++) {
    const Config& cfg = kConfigs[c];
    if (cfg.count != num_elements) {
      continue;
    }
    bool same = true;
    for (int i = 0; i < num_elements && same; i++) {
      same = cfg.elems[i].type == elems[i].type;
    }
    if (same) {
      return c;
    }
  }
  return 0;
}

void aac_layout_mix(const AacLayout* l, const float* const* in, int n, int out_channels,
                    float* out) {
  const int nc = l->num_channels;
  if (out_channels > 2 || (nc <= 2 && out_channels == nc)) {
    for (int i = 0; i < n; i++) {
      for (int c = 0; c < out_channels; c++) {
        out[i * out_channels + c] = c < nc ? in[c][i] : 0.0f;
      }
    }
    return;
  }
  /* Left and right weights per speaker (FFmpeg's default matrix: a mono
   * stream goes to both sides at -3 dB), mono as the stereo sum at -3 dB
   * (so a stereo stream becomes 0.707 (L + R), as FFmpeg mixes it). */
  static const float kWeights[AAC_SPK_COUNT][2] = {
      {1.0f, 0.0f},             /* FL */
      {0.0f, 1.0f},             /* FR */
      {0.70710678f, 0.70710678f}, /* FC */
      {0.0f, 0.0f},             /* LFE */
      {0.70710678f, 0.0f},      /* BL */
      {0.0f, 0.70710678f},      /* BR */
      {1.0f, 0.0f},             /* FLC */
      {0.0f, 1.0f},             /* FRC */
      {0.5f, 0.5f},             /* BC */
      {0.70710678f, 0.0f},      /* SL */
      {0.0f, 0.70710678f},      /* SR */
      {0.70710678f, 0.0f},      /* TFL: a front pair in the top layer, ITU-R BS.775 */
      {0.0f, 0.70710678f},      /* TFR */
  };
  /* Unknown speakers: a pair's channels go left and right, a single
   * channel to both sides at -3 dB, an LFE nowhere. */
  float weights[AAC_MAX_CHANNELS][2];
  for (int e = 0; e < l->num_elements; e++) {
    const AacLayoutElement* el = &l->elems[e];
    for (int c = 0; c < element_channels(el->type); c++) {
      const int spk = el->speaker[c];
      float* w = weights[el->slot[c]];
      if (spk >= 0) {
        w[0] = kWeights[spk][0];
        w[1] = kWeights[spk][1];
      } else if (el->type == AAC_ELEM_LFE) {
        w[0] = w[1] = 0.0f;
      } else if (el->type == AAC_ELEM_CPE) {
        w[0] = c == 0 ? 1.0f : 0.0f;
        w[1] = c == 0 ? 0.0f : 1.0f;
      } else {
        w[0] = w[1] = 0.70710678f;
      }
    }
  }
  for (int i = 0; i < n; i++) {
    float left = 0.0f;
    float right = 0.0f;
    for (int c = 0; c < nc; c++) {
      left += weights[c][0] * in[c][i];
      right += weights[c][1] * in[c][i];
    }
    if (out_channels == 2) {
      out[2 * i] = left;
      out[2 * i + 1] = right;
    } else {
      out[i] = 0.70710678f * (left + right);
    }
  }
}
