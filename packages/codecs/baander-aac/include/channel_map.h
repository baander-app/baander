#ifndef BAANDER_AAC_CHANNEL_MAP_H
#define BAANDER_AAC_CHANNEL_MAP_H

#include "aac_tables.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Channel layouts of ISO/IEC 14496-3 Table 1.19 (channel configurations
 * 1..7, 11, 12 and 14) and of program config elements, and the order in
 * which the decoder emits and the encoder takes interleaved channels.
 *
 * Output order is the order of AacSpeaker, which is the order FFmpeg and
 * WAVE use: left, right, centre, LFE, back left, back right, the outer
 * (left/right of centre) front pair, back centre, side left, side right.
 * A layout lists its elements in bitstream order; each element's channels
 * are assigned speakers from the element positions (front, side, back,
 * LFE) with the pairing rules every decoder applies, and the output slots
 * are the speakers in AacSpeaker order.  Elements that cannot be placed
 * (a lone side channel, a centre after a pair) are emitted in element
 * order instead, with unknown speakers. */

#define AAC_MAX_ELEMENTS 16
#define AAC_MAX_CCE 4

using AacSpeaker = enum AacSpeaker_ {
  AAC_SPK_FL = 0,
  AAC_SPK_FR = 1,
  AAC_SPK_FC = 2,
  AAC_SPK_LFE = 3,
  AAC_SPK_BL = 4,
  AAC_SPK_BR = 5,
  AAC_SPK_FLC = 6,
  AAC_SPK_FRC = 7,
  AAC_SPK_BC = 8,
  AAC_SPK_SL = 9,
  AAC_SPK_SR = 10,
  AAC_SPK_COUNT = 11,
};

using AacElementPosition = enum AacElementPosition_ {
  AAC_POS_FRONT = 1,
  AAC_POS_SIDE = 2,
  AAC_POS_BACK = 3,
  AAC_POS_LFE = 4,
};

using AacLayoutElement = struct AacLayoutElement_ {
  int type;     /* AAC_ELEM_SCE, AAC_ELEM_CPE or AAC_ELEM_LFE */
  int tag;      /* element_instance_tag */
  int position; /* AacElementPosition */
  int speaker[2]; /* AacSpeaker, or -1 when the element could not be placed */
  int slot[2];    /* output channel index of each channel; slot[1] unused for SCE/LFE */
};

using AacLayout = struct AacLayout_ {
  int channel_config; /* 0 for a PCE-defined or sniffed layout */
  int num_elements;
  int num_channels;
  AacLayoutElement elems[AAC_MAX_ELEMENTS]; /* bitstream order */
  int slot_speaker[AAC_MAX_CHANNELS];       /* speaker of each output slot, or -1 */
  int num_cce;
  int cce_tag[AAC_MAX_CCE];
};

/* Fills `l` for a channel configuration; returns AAC_ERR_UNSUPPORTED for a
 * configuration without a layout or with more than AAC_MAX_CHANNELS. */
int aac_layout_from_config(AacLayout* l, int channel_config);

/* Fills `l` from element lists in bitstream order with their positions
 * (program_config_element).  Returns AAC_ERR_UNSUPPORTED when the elements
 * cannot be placed. */
int aac_layout_from_elements(AacLayout* l, const AacLayoutElement* elems, int num_elements);

/* Index of the element with this type and tag, or -1. */
int aac_layout_find(const AacLayout* l, int type, int tag);

/* The standard configuration whose element sequence (types in order,
 * ignoring tags) matches, or 0. */
int aac_layout_config_for_elements(const AacLayoutElement* elems, int num_elements);

/* Mixes `n` samples of the layout's channels (in[slot][i]) into
 * `out_channels` interleaved channels: 1 and 2 downmix with the usual
 * coefficients (centre and surrounds at -3 dB, LFE dropped, mono as the
 * sum of both stereo channels at -3 dB; a mono stream goes to both stereo
 * channels at -3 dB), larger counts copy the slots and zero the rest. */
void aac_layout_mix(const AacLayout* l, const float* const* in, int n, int out_channels,
                    float* out);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_CHANNEL_MAP_H */
