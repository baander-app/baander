#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <vector>

#include "aac.h"
#include "aac_cpu.h"
#include "aac_dsp.h"
#include "aac_tables.h"

static std::vector<float> load_pcm(const char* path, int* sr, int* ch) {
  char cmd[1024];
  snprintf(cmd, sizeof(cmd),
           "ffmpeg -v error -t 5 -i '%s' -ar 44100 -ac 2 -f f32le -acodec pcm_f32le -", path);
  FILE* pipe = popen(cmd, "r");
  std::vector<float> pcm;
  float tmp[4096];
  while (true) {
    size_t n = fread(tmp, sizeof(float), 4096, pipe);
    if (n == 0) break;
    pcm.insert(pcm.end(), tmp, tmp + n);
  }
  pclose(pipe);
  *sr = 44100;
  *ch = 2;
  return pcm;
}

int main(int argc, char** argv) {
  if (argc < 3) {
    fprintf(stderr, "Usage: %s <input.flac> <output.aac> [bitrate]\n", argv[0]);
    return 1;
  }
  aac_tables_init();
  AacDSP dsp;
  aac_dsp_init(&dsp);

  int sr, ch;
  auto pcm = load_pcm(argv[1], &sr, &ch);
  int bitrate = 128000;
  if (argc >= 4) bitrate = atoi(argv[3]);

  AacEncoderHandle enc = aac_encoder_create(sr, ch, bitrate, AAC_AOT_LC, AAC_RC_CBR);
  if (!enc) {
    fprintf(stderr, "encoder create failed\n");
    return 1;
  }

  FILE* out = fopen(argv[2], "wb");
  if (!out) {
    fprintf(stderr, "cannot open %s\n", argv[2]);
    return 1;
  }

  std::vector<uint8_t> frame(65536);
  int frame_size = aac_encoder_frame_size(enc);
  int frames = 0;
  for (size_t pos = 0; pos + frame_size * ch <= pcm.size() && frames < 100; pos += frame_size * ch) {
    int len = aac_encoder_encode(enc, pcm.data() + pos, frame_size, frame.data(), (int)frame.size());
    if (len > 0) {
      fwrite(frame.data(), 1, len, out);
      frames++;
    }
  }
  int flush_len = aac_encoder_flush(enc, frame.data(), (int)frame.size());
  if (flush_len > 0) {
    fwrite(frame.data(), 1, flush_len, out);
  }
  fclose(out);
  aac_encoder_destroy(enc);
  printf("Wrote %d frames to %s\n", frames, argv[2]);
  return 0;
}
