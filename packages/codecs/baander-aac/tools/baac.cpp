/* baac: command-line encoder/decoder for baander-aac.
 *
 *   baac enc <in.f32le> <out.aac> <rate> <channels> [bitrate] [mode] [quality]
 *   baac dec <in.aac> <out.f32le> [rate] [channels]
 *
 * Raw PCM is interleaved 32-bit float; the AAC side is an ADTS stream.
 * mode: cbr (default), abr, cvbr, tvbr.  The decoder follows the ADTS
 * headers, so rate and channels only set the output layout. */
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>
#include <vector>

#include "aac.h"

static std::vector<uint8_t> read_all(const char* path) {
  std::vector<uint8_t> data;
  FILE* f = strcmp(path, "-") == 0 ? stdin : fopen(path, "rb");
  if (!f) {
    fprintf(stderr, "baac: cannot open %s\n", path);
    exit(1);
  }
  uint8_t buf[65536];
  size_t n;
  while ((n = fread(buf, 1, sizeof(buf), f)) > 0) {
    data.insert(data.end(), buf, buf + n);
  }
  if (f != stdin) {
    fclose(f);
  }
  return data;
}

static FILE* open_out(const char* path) {
  FILE* f = strcmp(path, "-") == 0 ? stdout : fopen(path, "wb");
  if (!f) {
    fprintf(stderr, "baac: cannot create %s\n", path);
    exit(1);
  }
  return f;
}

static int usage() {
  fprintf(stderr,
          "usage: baac enc <in.f32le> <out.aac> <rate> <channels> [bitrate] [cbr|abr|cvbr|tvbr] "
          "[quality]\n"
          "       baac dec <in.aac> <out.f32le> [rate] [channels]\n");
  return 2;
}

static int encode(int argc, char** argv) {
  if (argc < 6) {
    return usage();
  }
  const int sr = atoi(argv[4]);
  const int channels = atoi(argv[5]);
  const int bitrate = argc > 6 ? atoi(argv[6]) : 128000;
  AacRateControl rc = AAC_RC_CBR;
  if (argc > 7) {
    const std::string mode = argv[7];
    rc = mode == "abr"    ? AAC_RC_ABR
         : mode == "cvbr" ? AAC_RC_CVBR
         : mode == "tvbr" ? AAC_RC_TVBR
                          : AAC_RC_CBR;
  }
  AacEncoderHandle enc = aac_encoder_create(sr, channels, bitrate, AAC_AOT_LC, rc);
  if (!enc) {
    fprintf(stderr, "baac: unsupported encoder configuration\n");
    return 1;
  }
  if (argc > 8) {
    aac_encoder_set_quality(enc, atoi(argv[8]));
  }
  const std::vector<uint8_t> in = read_all(argv[2]);
  FILE* out = open_out(argv[3]);
  const int frame = aac_encoder_frame_size(enc);
  const size_t block = (size_t)frame * channels;
  const size_t total = in.size() / sizeof(float);
  std::vector<float> pcm(block);
  std::vector<uint8_t> buf(16384);
  long bytes = 0;
  int frames = 0;
  for (size_t pos = 0; pos < total; pos += block) {
    std::fill(pcm.begin(), pcm.end(), 0.0f);
    const size_t n = std::min(block, total - pos);
    memcpy(pcm.data(), in.data() + pos * sizeof(float), n * sizeof(float));
    const int len = aac_encoder_encode(enc, pcm.data(), frame, buf.data(), (int)buf.size());
    if (len < 0) {
      fprintf(stderr, "baac: encode error %d at frame %d\n", len, frames);
      return 1;
    }
    fwrite(buf.data(), 1, (size_t)len, out);
    bytes += len;
    frames++;
  }
  const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
  if (len > 0) {
    fwrite(buf.data(), 1, (size_t)len, out);
    bytes += len;
  }
  if (out != stdout) {
    fclose(out);
  }
  aac_encoder_destroy(enc);
  const double seconds = (double)total / channels / sr;
  fprintf(stderr, "baac: %d frames, %ld bytes, %.1f kbit/s\n", frames, bytes,
          seconds > 0 ? bytes * 8.0 / seconds / 1000.0 : 0.0);
  return 0;
}

static int decode(int argc, char** argv) {
  if (argc < 4) {
    return usage();
  }
  const int sr = argc > 4 ? atoi(argv[4]) : 44100;
  const int channels = argc > 5 ? atoi(argv[5]) : 2;
  AacDecoderHandle dec = aac_decoder_create(sr, channels);
  if (!dec) {
    fprintf(stderr, "baac: unsupported decoder configuration\n");
    return 1;
  }
  const std::vector<uint8_t> in = read_all(argv[2]);
  FILE* out = open_out(argv[3]);
  std::vector<float> pcm((size_t)aac_decoder_frame_size(dec) * channels);
  size_t pos = 0;
  int frames = 0;
  int errors = 0;
  while (pos + 7 <= in.size()) {
    if (in[pos] != 0xFF || (in[pos + 1] & 0xF6) != 0xF0) {
      pos++; /* resynchronize */
      continue;
    }
    const size_t len = ((in[pos + 3] & 3) << 11) | (in[pos + 4] << 3) | (in[pos + 5] >> 5);
    if (len < 7 || pos + len > in.size()) {
      break;
    }
    const int n = aac_decoder_decode(dec, in.data() + pos, (int)len, pcm.data(), (int)pcm.size());
    if (n < 0) {
      errors++;
    } else {
      fwrite(pcm.data(), sizeof(float), (size_t)n * channels, out);
    }
    pos += len;
    frames++;
  }
  if (out != stdout) {
    fclose(out);
  }
  fprintf(stderr, "baac: %d frames, %d errors, %d Hz\n", frames, errors,
          aac_decoder_sample_rate(dec));
  aac_decoder_destroy(dec);
  return errors ? 1 : 0;
}

int main(int argc, char** argv) {
  if (argc < 2) {
    return usage();
  }
  if (strcmp(argv[1], "enc") == 0) {
    return encode(argc, argv);
  }
  if (strcmp(argv[1], "dec") == 0) {
    return decode(argc, argv);
  }
  return usage();
}
