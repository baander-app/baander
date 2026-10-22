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
 * decoder and group * num_swb + sfb in the encoder. */
using AacIcsInfo = struct AacIcsInfo_ {
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
 * window sequence; scale_factor_grouping is the 7-bit field of short
 * windows, bit 6 - w set when window w + 1 joins window w's group. */
void aac_ics_layout(AacIcsInfo* ics, int rate_index, int window_sequence,
                    int scale_factor_grouping);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_ICS_H */
