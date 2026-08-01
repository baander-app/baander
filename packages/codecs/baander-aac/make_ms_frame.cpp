#include "mdct.h"
#include <cstdio>
#include <cstring>
#include "aac.h"
#include "aac_tables.h"
#include "bitstream.h"

int main() {
  aac_tables_init();
  uint8_t out[256];
  AacBitWriter w;
  aac_bitwriter_init(&w, out, sizeof(out));
  aac_bitwriter_write(&w, 0, 56); // ADTS placeholder
  aac_bitwriter_write(&w, AAC_ELEM_SCE, 3);
  aac_bitwriter_write(&w, 0, 4);
  aac_bitwriter_write(&w, 100, 8);
  aac_bitwriter_write(&w, 0, 1); // reserved
  aac_bitwriter_write(&w, AAC_WIN_ONLY_LONG, 2);
  aac_bitwriter_write(&w, AAC_WIN_SINE, 1);
  aac_bitwriter_write(&w, 49, 6);
  aac_bitwriter_write(&w, 0, 1); // predictor
  // section: cb=4 len=1, cb=0 len=48
  aac_bitwriter_write(&w, 4, 4);
  aac_bitwriter_write(&w, 1, 5);
  aac_bitwriter_write(&w, 0, 4);
  aac_bitwriter_write(&w, 31, 5);
  aac_bitwriter_write(&w, 17, 5);
  // scalefactor: one diff=0
  aac_bitwriter_write(&w, 0, 1);
  // pulse, tns, gain
  aac_bitwriter_write(&w, 0, 1);
  aac_bitwriter_write(&w, 0, 1);
  aac_bitwriter_write(&w, 0, 1);
  // spectral: band 0, 4 bins, cb=4, pairs (1,0) and (0,0)
  aac_bitwriter_write_huffman(&w, 4, 1, 0);
  aac_bitwriter_write_huffman(&w, 4, 0, 0);
  // END
  aac_bitwriter_write(&w, AAC_ELEM_END, 3);
  aac_bitwriter_byte_align(&w);
  int frame_len = aac_bitwriter_bytes_written(&w);
  AacAdtsHeader hdr = {};
  hdr.id=0; hdr.layer=0; hdr.protection_absent=1; hdr.profile=AAC_AOT_LC;
  hdr.sample_rate_index=3; hdr.channel_config=1; hdr.frame_length=frame_len;
  hdr.buffer_fullness=0x7FF; hdr.num_aac_frames=0;
  aac_adts_write(&hdr, out);
  FILE* f = fopen("/tmp/ms_frame.aac", "wb");
  fwrite(out, 1, frame_len, f);
  fclose(f);
  printf("wrote %d bytes\n", frame_len);
  return 0;
}
