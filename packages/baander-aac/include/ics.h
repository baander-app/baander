#ifndef BAANDER_AAC_ICS_H
#define BAANDER_AAC_ICS_H

#include "aac_tables.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Largest band count of any group/window layout: 8 groups x 15 short bands
 * or one group of 51 long bands. */
#define AAC_MAX_BANDS 128

/* ics_info() of ISO/IEC 14496-3 Table 4.6 plus the layout it implies.
 * Band-indexed arrays of a channel use index group * max_sfb + sfb in the
 * decoder and group * num_swb + sfb in the encoder.  Spectra are stored
 * with a stride of AAC_FRAME_SIZE_SHORT lines per short window whatever
 * the frame length: the 120-line windows of a 960 frame leave the last
 * eight lines of each window unused. */
using AacIcsInfo = struct AacIcsInfo_ {
  int frame_length; /* 1024 or 960 lines per long window */
  int window_sequence;
  int window_shape;
  int max_sfb;
  int num_windows;
  int num_window_groups;
  int group_len[AAC_MAX_WINDOW_GROUPS];
  int num_swb;
  const int* swb_offset;
  int tns_max_bands;
};

/* Fills the layout fields (everything but window_shape and max_sfb) for a
 * window sequence of a frame length (1024 or 960); scale_factor_grouping
 * is the 7-bit field of short windows, bit 6 - w set when window w + 1
 * joins window w's group. */
void aac_ics_layout(AacIcsInfo* ics, int rate_index, int frame_length, int window_sequence,
                    int scale_factor_grouping);

/* Lines of one short window of a frame length: 128 or 120. */
static inline int aac_short_length(int frame_length) {
  return frame_length / AAC_NUM_WINDOWS_SHORT;
}

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_ICS_H */
