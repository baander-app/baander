#include <cstdio>
#include "aac_tables.h"
#include "bitstream.h"
int main() {
  aac_tables_init();
  uint8_t buf[256];
  AacBitWriter w; aac_bitwriter_init(&w, buf, 256);
  for (int d=-60; d<=60; d++) aac_bitwriter_write_scalefactor(&w, d);
  AacBitReader r; aac_bitreader_init(&r, buf, 256);
  int fail=0;
  for (int d=-60; d<=60; d++) {
    int got = aac_bitreader_read_scalefactor(&r);
    if (got != d) { printf("fail d=%d got=%d\n", d, got); fail++; }
  }
  printf("scalefactor roundtrip failures: %d\n", fail);
  return fail;
}
