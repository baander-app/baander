#include "ics.h"

#include "mdct.h"

void aac_ics_layout(AacIcsInfo* ics, int rate_index, int window_sequence,
                    int scale_factor_grouping) {
  ics->window_sequence = window_sequence;
  if (window_sequence == AAC_WIN_EIGHT_SHORT) {
    ics->num_windows = 8;
    ics->num_window_groups = 1;
    ics->group_len[0] = 1;
    for (int w = 0; w < 7; w++) {
      if (scale_factor_grouping & (1 << (6 - w))) {
        ics->group_len[ics->num_window_groups - 1]++;
      } else {
        ics->group_len[ics->num_window_groups++] = 1;
      }
    }
    ics->num_swb = aac_num_sfb_short[rate_index];
    ics->swb_offset = aac_sfb_offset_short[rate_index];
    ics->tns_max_bands = aac_tns_max_bands_short[rate_index];
  } else {
    ics->num_windows = 1;
    ics->num_window_groups = 1;
    ics->group_len[0] = 1;
    ics->num_swb = aac_num_sfb_long[rate_index];
    ics->swb_offset = aac_sfb_offset_long[rate_index];
    ics->tns_max_bands = aac_tns_max_bands_long[rate_index];
  }
}
