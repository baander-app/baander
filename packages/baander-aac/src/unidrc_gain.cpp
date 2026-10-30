#include "unidrc.h"

#include <cmath>
#include <cstring>

/* The uniDrcGain() payload (ISO/IEC 23003-4 clause 5.4 and the gain
 * decoding of clause 6.4): per gain sequence the node times, slopes and
 * gains of one frame.
 *
 * Node times are coded as deltas in units of deltaTmin from the last
 * node of the previous frame (which sits at the sample before the frame
 * start); a frame coded in the compact mode, or a full-frame gain set,
 * has one node at the frame end.  The first gain of a frame is coded as
 * an absolute value of the gain coding profile, the following ones as
 * Huffman-coded deltas in steps of 0.125 dB. */

namespace {

/* One Huffman codeword: `bits` bits of `code`, decoding to `value`. */
struct Code {
  uint8_t bits;
  uint16_t code;
  int16_t value;
};

/* Huffman codes of the gain delta in 1/8 dB (Table of deltaGain codes). */
const Code kDeltaGainCodes[] = {
    {2, 0x2, 1},      {2, 0x3, -1},     {3, 0x2, 0},      {3, 0x1, -2},    {4, 0x0, -16},
    {5, 0x02, -4},    {5, 0x0E, 8},     {5, 0x0F, -3},    {6, 0x06, 3},    {6, 0x18, 2},
    {6, 0x19, -5},    {7, 0x0F, -8},    {7, 0x34, -7},    {7, 0x36, -6},   {7, 0x37, 4},
    {8, 0x1D, 5},     {9, 0x039, -15},  {9, 0x0D5, -9},   {9, 0x0D4, 7},   {9, 0x0D7, 6},
    {10, 0x070, -12}, {10, 0x1AC, -11}, {10, 0x1AD, -10}, {11, 0x0E2, -14}, {11, 0x0E3, -13},
};

/* Huffman codes of the spline slope index (Table of slopeCode); the
 * index selects a slope in dB per sample from kSlopeValues.  Not
 * verified against a reference decoder: the FDK encoder writes only
 * linear interpolation. */
const Code kSlopeCodes[] = {
    {1, 0x1, 7},  {3, 0x2, 6},  {3, 0x3, 8},  {4, 0x2, 5},   {4, 0x3, 9},   {5, 0x2, 4},
    {5, 0x3, 10}, {6, 0x2, 3},  {6, 0x3, 11}, {7, 0x2, 2},   {7, 0x3, 12},  {8, 0x2, 1},
    {8, 0x3, 13}, {8, 0x0, 0},  {8, 0x1, 14},
};
const float kSlopeValues[15] = {-0.4f,   -0.2f,  -0.1f,  -0.05f, -0.025f, -0.0125f, -0.00625f, 0.0f,
                                0.00625f, 0.0125f, 0.025f, 0.05f, 0.1f,   0.2f,     0.4f};

/* Reads one codeword of a table; -1 for a code that is not in it. */
int read_code(AacBitReader* r, const Code* table, int n, int max_bits, int* value) {
  uint32_t code = 0;
  for (int bits = 1; bits <= max_bits; bits++) {
    code = (code << 1) | aac_bitreader_read(r, 1);
    for (int i = 0; i < n; i++) {
      if (table[i].bits == bits && table[i].code == code) {
        *value = table[i].value;
        return 0;
      }
    }
  }
  return -1;
}

/* The absolute gain of the first node of a frame, per gain coding profile. */
float initial_gain(AacBitReader* r, int profile) {
  switch (profile) {
    case AAC_UNIDRC_PROFILE_REGULAR: {
      const int sign = (int)aac_bitreader_read(r, 1);
      const int mag = (int)aac_bitreader_read(r, 8);
      return (sign ? -1.0f : 1.0f) * (float)mag * 0.125f;
    }
    case AAC_UNIDRC_PROFILE_FADING:
      if (aac_bitreader_read(r, 1)) {
        return -(float)((int)aac_bitreader_read(r, 10) + 1) * 0.125f;
      }
      return 0.0f;
    case AAC_UNIDRC_PROFILE_CLIPPING_DUCKING:
      if (aac_bitreader_read(r, 1)) {
        return -(float)((int)aac_bitreader_read(r, 8) + 1) * 0.125f;
      }
      return 0.0f;
    default:
      return 0.0f;
  }
}

/* deltaTime in units of deltaTmin: a two-bit prefix selects 1, a 2-bit
 * field (2..5), a 3-bit field (6..13) or a Z-bit field (14 and up). */
int time_delta(AacBitReader* r, int z_bits) {
  switch (aac_bitreader_read(r, 2)) {
    case 0:
      return 1;
    case 1:
      return 2 + (int)aac_bitreader_read(r, 2);
    case 2:
      return 6 + (int)aac_bitreader_read(r, 3);
    default:
      return 14 + (int)aac_bitreader_read(r, z_bits);
  }
}

int ceil_log2(int n) {
  int z = 0;
  while ((1 << z) < n) {
    z++;
  }
  return z;
}

/* The gain set and band that own gain sequence `seq`. */
const AacUniDrcGainSet* owner_of(const AacUniDrcConfig* c, int seq) {
  for (int i = 0; i < c->coefficients_count; i++) {
    const AacUniDrcCoefficients* co = &c->coefficients[i];
    for (int s = 0; s < co->gain_set_count; s++) {
      for (int b = 0; b < co->gain_set[s].band_count; b++) {
        if (co->gain_set[s].band[b].gain_sequence_index == seq) {
          return &co->gain_set[s];
        }
      }
    }
  }
  return nullptr;
}

int sequence_count(const AacUniDrcConfig* c) {
  int n = 0;
  for (int i = 0; i < c->coefficients_count; i++) {
    const AacUniDrcCoefficients* co = &c->coefficients[i];
    for (int s = 0; s < co->gain_set_count; s++) {
      for (int b = 0; b < co->gain_set[s].band_count; b++) {
        const int idx = co->gain_set[s].band[b].gain_sequence_index + 1;
        n = idx > n ? idx : n;
      }
    }
  }
  return n;
}

/* drcGainSequence() of one gain set in one frame. */
int gain_sequence(AacBitReader* r, const AacUniDrcGainSet* gs, int frame_length, int sample_rate,
                  AacUniDrcSequence* s) {
  s->node_count = 0;
  if (gs->gain_coding_profile == AAC_UNIDRC_PROFILE_CONSTANT) {
    s->node_count = 1;
    s->node[0].time = frame_length - 1;
    s->node[0].gain_db = 0.0f;
    s->node[0].slope = 0.0f;
    return AAC_OK;
  }
  const int delta_tmin = aac_unidrc_time_delta_min(gs, sample_rate);
  const int n_time_delta = frame_length / delta_tmin;
  if (n_time_delta < 1) {
    return AAC_ERR_DECODE;
  }
  const int z_bits = ceil_log2(n_time_delta) + 1;
  const int time_offset = gs->time_alignment ? -1 - delta_tmin / 2 : -1;
  const int coding_mode = gs->full_frame ? 0 : (int)aac_bitreader_read(r, 1);
  if (coding_mode == 0) {
    s->node_count = 1;
    s->node[0].time = n_time_delta * delta_tmin + time_offset;
    s->node[0].slope = 0.0f;
    s->node[0].gain_db = initial_gain(r, gs->gain_coding_profile);
    return AAC_OK;
  }
  int n = 1;
  while (aac_bitreader_read(r, 1) == 0) {
    if (++n > AAC_UNIDRC_MAX_NODES || r->overread) {
      return AAC_ERR_DECODE;
    }
  }
  const int frame_end = (int)aac_bitreader_read(r, 1);
  int time = time_offset;
  for (int k = 0; k < n - frame_end; k++) {
    time += time_delta(r, z_bits) * delta_tmin;
    s->node[k].time = time;
  }
  if (frame_end) {
    s->node[n - 1].time = n_time_delta * delta_tmin + time_offset;
  }
  for (int k = 0; k < n; k++) {
    s->node[k].slope = 0.0f;
    if (gs->gain_interpolation_type == 0) {
      int idx;
      if (read_code(r, kSlopeCodes, (int)(sizeof(kSlopeCodes) / sizeof(kSlopeCodes[0])), 8,
                    &idx)) {
        return AAC_ERR_DECODE;
      }
      s->node[k].slope = kSlopeValues[idx];
    }
  }
  s->node[0].gain_db = initial_gain(r, gs->gain_coding_profile);
  for (int k = 1; k < n; k++) {
    int delta;
    if (read_code(r, kDeltaGainCodes, (int)(sizeof(kDeltaGainCodes) / sizeof(kDeltaGainCodes[0])),
                  14, &delta)) {
      return AAC_ERR_DECODE;
    }
    s->node[k].gain_db = s->node[k - 1].gain_db + (float)delta * 0.125f;
  }
  s->node_count = n;
  return AAC_OK;
}

} // namespace

int aac_unidrc_time_delta_min(const AacUniDrcGainSet* gs, int sample_rate) {
  if (gs->time_delta_min > 0) {
    return gs->time_delta_min;
  }
  /* The default: the smallest power of two above half a millisecond. */
  int d = 1;
  while ((float)d <= 0.0005f * (float)sample_rate) {
    d <<= 1;
  }
  return d;
}

int aac_unidrc_parse_gain(const AacUniDrcConfig* c, AacBitReader* r, int payload_bits,
                          int frame_length, int sample_rate, AacUniDrcGainFrame* g) {
  const int start = r->pos;
  g->sequence_count = sequence_count(c);
  if (g->sequence_count > AAC_UNIDRC_MAX_SEQUENCES || frame_length <= 0 || sample_rate <= 0) {
    return AAC_ERR_DECODE;
  }
  for (int seq = 0; seq < g->sequence_count; seq++) {
    const AacUniDrcGainSet* gs = owner_of(c, seq);
    if (!gs) {
      g->seq[seq].node_count = 0;
      continue;
    }
    const int err = gain_sequence(r, gs, frame_length, sample_rate, &g->seq[seq]);
    if (err) {
      return err;
    }
    if (r->pos - start > payload_bits) {
      return AAC_ERR_DECODE;
    }
  }
  /* uniDrcGainExtension(): skipped by its signalled sizes. */
  if (aac_bitreader_read(r, 1)) {
    int type = (int)aac_bitreader_read(r, 4);
    while (type != 0) {
      const int size_bits = (int)aac_bitreader_read(r, 4) + 4;
      const int ext_bits = (int)aac_bitreader_read(r, size_bits) + 1;
      aac_bitreader_skip(r, ext_bits);
      type = (int)aac_bitreader_read(r, 4);
    }
  }
  if (r->overread || r->pos - start > payload_bits) {
    return AAC_ERR_DECODE;
  }
  return AAC_OK;
}
