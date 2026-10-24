#include "ics.h"

#include "mdct.h"

void aac_ics_layout(AacIcsInfo* ics, int rate_index, int frame_length, int window_sequence,
                    int scale_factor_grouping) {
  ics->frame_length = frame_length;
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
    ics->num_swb = aac_num_sfb(frame_length, rate_index, 1);
    ics->swb_offset = aac_sfb_offset(frame_length, rate_index, 1);
    ics->tns_max_bands = aac_tns_max_bands(frame_length, rate_index, 1);
  } else {
    ics->num_windows = 1;
    ics->num_window_groups = 1;
    ics->group_len[0] = 1;
    ics->num_swb = aac_num_sfb(frame_length, rate_index, 0);
    ics->swb_offset = aac_sfb_offset(frame_length, rate_index, 0);
    ics->tns_max_bands = aac_tns_max_bands(frame_length, rate_index, 0);
  }
}
