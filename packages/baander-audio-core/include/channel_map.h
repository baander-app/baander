#ifndef BAANDER_AAC_CHANNEL_MAP_H
#define BAANDER_AAC_CHANNEL_MAP_H

#include <cstdint>

#include "aac.h"
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
 * (left/right of centre) front pair, back centre, side left, side right,
 * then the top front pair.  A layout lists its elements in bitstream
 * order; each element's channels are assigned speakers from the element
 * positions (front, side, back, LFE) with the pairing rules every decoder
 * applies, and the output slots are the speakers in AacSpeaker order.
 * Two pairs at the back are a side pair followed by a back pair, as
 * FFmpeg places them, so the 7.1 layouts with a second surround pair put
 * that pair in slots 4 and 5 and the first in slots 6 and 7.  A front
 * pair in the top layer (the program config element's height extension,
 * channel configuration 14) takes the top front speakers.  Elements that
 * cannot be placed (a lone side channel, a centre after a pair, any other
 * height element) are emitted in element order instead, with unknown
 * speakers. */

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
  AAC_SPK_TFL = 11,
  AAC_SPK_TFR = 12,
  AAC_SPK_COUNT = 13,
};

using AacElementPosition = enum AacElementPosition_ {
  AAC_POS_FRONT = 1,
  AAC_POS_SIDE = 2,
  AAC_POS_BACK = 3,
  AAC_POS_LFE = 4,
};

using AacElementHeight = enum AacElementHeight_ {
  AAC_HEIGHT_NORMAL = 0,
  AAC_HEIGHT_TOP = 1,
  AAC_HEIGHT_BOTTOM = 2,
};

using AacLayoutElement = struct AacLayoutElement_ {
  int type;     /* AAC_ELEM_SCE, AAC_ELEM_CPE or AAC_ELEM_LFE */
  int tag;      /* element_instance_tag */
  int position; /* AacElementPosition */
  int speaker[2]; /* AacSpeaker, or -1 when the element could not be placed */
  int slot[2];    /* output channel index of each channel; slot[1] unused for SCE/LFE */
  int height;     /* AacElementHeight: the layer of the program config element's height extension */
};

/* The height extension of a program config element (ISO/IEC 14496-3
 * 4.4.1.1 as amended): the comment field starts with this byte, followed
 * by two bits of AacElementHeight per front, side and back element, byte
 * alignment and an 8-bit CRC (polynomial x^8 + x^2 + x + 1, initial value
 * 0xFF) over the bytes before it. */
#define AAC_PCE_HEIGHT_EXTENSION_SYNC 0xAC

/* The CRC of the height extension over `n` bytes. */
uint8_t aac_pce_height_crc(const uint8_t* bytes, int n);

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

/* Fills `l` for one of the 7.1 channel modes: the channel configuration
 * of modes 7, 12 and 14, and for the rear-surround mode the elements C,
 * L+R, a side pair, a back pair and LFE with channel_config 0.  Returns
 * AAC_ERR_INVALID_ARG for AAC_MODE_DEFAULT, which names no layout. */
int aac_layout_from_mode(AacLayout* l, AacChannelMode mode);

/* The channel mode a layout belongs to: one of the 7.1 modes when the
 * layout has eight placed channels with a top front pair, a side pair, a
 * second front pair or a second back pair, AAC_MODE_DEFAULT for every
 * other layout. */
AacChannelMode aac_layout_mode(const AacLayout* l);

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
 * coefficients (centre, surrounds and the top front pair at -3 dB, LFE
 * dropped, mono as the sum of both stereo channels at -3 dB; a mono
 * stream goes to both stereo channels at -3 dB), larger counts copy the
 * slots and zero the rest. */
void aac_layout_mix(const AacLayout* l, const float* const* in, int n, int out_channels,
                    float* out);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_CHANNEL_MAP_H */
