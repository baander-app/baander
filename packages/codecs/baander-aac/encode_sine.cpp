#include <cstdio>
#include <cmath>
#include <vector>

#include "aac.h"
#include "aac_cpu.h"
#include "aac_dsp.h"
#include "aac_tables.h"

int main() {
  aac_tables_init();
  AacDSP dsp; aac_dsp_init(&dsp);
  int sr=44100, ch=1, bitrate=128000;
  AacEncoderHandle enc = aac_encoder_create(sr, ch, bitrate, AAC_AOT_LC, AAC_RC_CBR);
  if (!enc) { fprintf(stderr, "enc fail\n"); return 1; }
  std::vector<float> pcm(1024*10);
  for (size_t i=0;i<pcm.size();i++) pcm[i] = 0.5f*sinf(2*M_PI*440*i/sr);
  FILE* out = fopen("/tmp/sine.aac", "wb");
  std::vector<uint8_t> frame(65536);
  for (int f=0; f<10; f++) {
    int len = aac_encoder_encode(enc, pcm.data()+f*1024, 1024, frame.data(), frame.size());
    if (len>0) fwrite(frame.data(),1,len,out);
  }
  int len = aac_encoder_flush(enc, frame.data(), frame.size());
  if (len>0) fwrite(frame.data(),1,len,out);
  fclose(out);
  aac_encoder_destroy(enc);
  return 0;
}
