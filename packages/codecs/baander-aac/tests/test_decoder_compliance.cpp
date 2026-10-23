/* Decoder behaviour on hand-built and damaged streams: M/S signalling,
 * CRC-protected ADTS, stream parameters taken from the header, channel
 * mapping, and robustness against truncated or random input. */
#include "bitstream.h"
#include "mdct.h"
#include "test_util.h"

/* A CPE frame at 48 kHz with common_window and one coded band: both
 * channels carry the same quad (1,0,0,0) in band 0 with codebook 4. */
static int build_cpe_frame(uint8_t* out, int out_size, int ms_present, int crc) {
  AacBitWriter w;
  aac_bitwriter_init(&w, out, out_size);
  aac_bitwriter_write(&w, 0, 56);
  if (crc) {
    aac_bitwriter_write(&w, 0x1234, 16);
  }
  aac_bitwriter_write(&w, AAC_ELEM_CPE, 3);
  aac_bitwriter_write(&w, 0, 4);
  aac_bitwriter_write(&w, 1, 1); /* common_window */
  aac_bitwriter_write(&w, 0, 1);
  aac_bitwriter_write(&w, AAC_WIN_ONLY_LONG, 2);
  aac_bitwriter_write(&w, AAC_WIN_SINE, 1);
  aac_bitwriter_write(&w, 49, 6); /* max_sfb */
  aac_bitwriter_write(&w, 0, 1);
  aac_bitwriter_write(&w, (uint32_t)ms_present, 2);
  if (ms_present == 1) {
    for (int b = 0; b < 49; b++) {
      aac_bitwriter_write(&w, 1, 1);
    }
  }
  for (int ch = 0; ch < 2; ch++) {
    aac_bitwriter_write(&w, 180, 8); /* global_gain: a clearly audible level */
    aac_bitwriter_write(&w, 4, 4);   /* section: codebook 4, length 1 */
    aac_bitwriter_write(&w, 1, 5);
    aac_bitwriter_write(&w, 0, 4); /* codebook 0 for the remaining 48 bands */
    aac_bitwriter_write(&w, 31, 5);
    aac_bitwriter_write(&w, 17, 5);
    aac_bitwriter_write_scalefactor(&w, 0);
    aac_bitwriter_write(&w, 0, 1); /* pulse */
    aac_bitwriter_write(&w, 0, 1); /* tns */
    aac_bitwriter_write(&w, 0, 1); /* gain control */
    const int quad[4] = {1, 0, 0, 0};
    aac_bitwriter_write_huffman(&w, 4, quad);
  }
  aac_bitwriter_write(&w, AAC_ELEM_END, 3);
  aac_bitwriter_byte_align(&w);
  AacAdtsHeader hdr = {};
  hdr.protection_absent = crc ? 0 : 1;
  hdr.object_type = AAC_AOT_LC;
  hdr.sample_rate_index = 3; /* 48000 Hz */
  hdr.channel_config = 2;
  hdr.frame_length = aac_bitwriter_bytes_written(&w);
  hdr.buffer_fullness = 0x7FF;
  hdr.num_raw_blocks = 1;
  aac_adts_write(&hdr, out);
  return hdr.frame_length;
}

static void energies(const float* pcm, double* l, double* r) {
  *l = *r = 0.0;
  for (int i = 0; i < 1024; i++) {
    *l += (double)pcm[2 * i] * pcm[2 * i];
    *r += (double)pcm[2 * i + 1] * pcm[2 * i + 1];
  }
}

static void test_ms_signalling() {
  uint8_t frame[256];
  float pcm[2048];
  double l, r;
  for (int mode = 0; mode < 3; mode++) {
    AacDecoderHandle dec = aac_decoder_create(48000, 2);
    const int len = build_cpe_frame(frame, sizeof(frame), mode, 0);
    const int n = aac_decoder_decode(dec, frame, len, pcm, 2048);
    CHECK(n == 1024, "ms_present=%d: decoded %d samples", mode, n);
    energies(pcm, &l, &r);
    CHECK(l > 1e-9, "ms_present=%d: left channel silent", mode);
    if (mode == 0) {
      CHECK(fabs(l - r) < 1e-3 * (l + r), "L/R: channels differ (%g vs %g)", l, r);
    } else {
      CHECK(r < 1e-6 * l, "ms_present=%d: right channel not cancelled (%g vs %g)", mode, l, r);
    }
    aac_decoder_destroy(dec);
  }
  AacDecoderHandle dec = aac_decoder_create(48000, 2);
  const int len = build_cpe_frame(frame, sizeof(frame), 3, 0);
  CHECK(aac_decoder_decode(dec, frame, len, pcm, 2048) < 0, "reserved ms_mask_present accepted");
  aac_decoder_destroy(dec);
}

static void test_crc_and_truncation() {
  uint8_t plain[256], crc[256];
  float a[2048], b[2048];
  const int len = build_cpe_frame(plain, sizeof(plain), 2, 0);
  const int len_crc = build_cpe_frame(crc, sizeof(crc), 2, 1);
  CHECK(len_crc == len + 2, "CRC frame is two bytes longer");
  AacDecoderHandle dec = aac_decoder_create(48000, 2);
  CHECK(aac_decoder_decode(dec, plain, len, a, 2048) == 1024, "plain frame");
  AacDecoderHandle dec2 = aac_decoder_create(48000, 2);
  CHECK(aac_decoder_decode(dec2, crc, len_crc, b, 2048) == 1024, "CRC-protected frame");
  CHECK(memcmp(a, b, sizeof(a)) == 0, "CRC-protected frame decodes identically");

  CHECK(aac_decoder_decode(dec, plain, 7, a, 2048) < 0, "header-only input");
  CHECK(aac_decoder_decode(dec, plain, len / 2, a, 2048) < 0, "truncated frame");
  CHECK(aac_decoder_decode(dec, plain, len, a, 100) == AAC_ERR_OVERFLOW, "small output buffer");
  CHECK(aac_decoder_decode(nullptr, plain, len, a, 2048) == AAC_ERR_INVALID_ARG, "null handle");
  aac_decoder_destroy(dec);
  aac_decoder_destroy(dec2);
}

static void test_random_input() {
  AacDecoderHandle dec = aac_decoder_create(44100, 2);
  uint8_t frame[512];
  float pcm[2048];
  unsigned seed = 99;
  int decoded = 0;
  for (int it = 0; it < 2000; it++) {
    const int len = 8 + (int)(seed % 400);
    for (int i = 0; i < len; i++) {
      seed = seed * 1664525u + 1013904223u;
      frame[i] = (uint8_t)(seed >> 24);
    }
    if (it % 2) {
      /* Valid ADTS header, random payload. */
      AacAdtsHeader hdr = {};
      hdr.protection_absent = 1;
      hdr.object_type = AAC_AOT_LC;
      hdr.sample_rate_index = 4;
      hdr.channel_config = 1 + (int)(seed % 2);
      hdr.frame_length = len;
      hdr.buffer_fullness = 0x7FF;
      hdr.num_raw_blocks = 1;
      aac_adts_write(&hdr, frame);
    }
    const int n = aac_decoder_decode(dec, frame, len, pcm, 2048);
    if (n > 0) {
      decoded++;
      for (int i = 0; i < n * 2; i++) {
        CHECK(std::isfinite(pcm[i]), "non-finite output from random input");
      }
    }
  }
  printf("random input: %d of 2000 frames decoded\n", decoded);
  aac_decoder_destroy(dec);
}

static void test_stream_parameters() {
  /* A 32 kHz mono stream decoded by a decoder created for 44.1 kHz stereo:
   * the ADTS header wins, the output is widened to the requested layout. */
  const std::vector<float> pcm = gen_harmonics(32000, 32000, 1);
  int err = 0;
  const std::vector<uint8_t> stream = encode_stream(pcm, 32000, 1, 64000, AAC_RC_CBR, 0, &err);
  CHECK(err == 0 && !stream.empty(), "encode at 32 kHz mono");
  AacDecoderHandle dec = aac_decoder_create(44100, 2);
  int errors = 0;
  const std::vector<float> out = decode_stream(stream, 44100, 2, &errors, dec);
  CHECK(errors == 0, "%d frames failed", errors);
  CHECK(aac_decoder_sample_rate(dec) == 32000, "sample rate follows the stream: %d",
        aac_decoder_sample_rate(dec));
  CHECK(aac_decoder_channels(dec) == 2, "output channels stay as requested");
  CHECK(out.size() == (size_t)split_adts(stream).size() * 2048, "one frame per ADTS frame");
  double diff = 0.0;
  for (size_t i = 0; i + 1 < out.size(); i += 2) {
    diff = std::max(diff, (double)fabs(out[i] - out[i + 1]));
  }
  CHECK(diff == 0.0, "mono stream goes to both channels");
  std::vector<float> mono(out.size() / 2);
  for (size_t i = 0; i < mono.size(); i++) {
    mono[i] = out[2 * i] / 0.70710678f;
  }
  CHECK(aligned_snr_db(pcm, mono, 1, 2048) > 20.0, "32 kHz roundtrip SNR %.1f dB",
        aligned_snr_db(pcm, mono, 1, 2048));
  aac_decoder_destroy(dec);

  /* A stereo stream through a mono decoder is downmixed. */
  const std::vector<float> st = gen_tones(44100, 44100, 2);
  const std::vector<uint8_t> st_stream = encode_stream(st, 44100, 2, 128000, AAC_RC_CBR);
  const std::vector<float> down = decode_stream(st_stream, 44100, 1, &errors);
  CHECK(errors == 0 && down.size() == split_adts(st_stream).size() * 1024, "downmix length");
  std::vector<float> ref(st.size() / 2);
  for (size_t i = 0; i < ref.size(); i++) {
    ref[i] = 0.70710678f * (st[2 * i] + st[2 * i + 1]);
  }
  CHECK(aligned_snr_db(ref, down, 1, 2048) > 20.0, "downmix SNR %.1f dB",
        aligned_snr_db(ref, down, 1, 2048));

  CHECK(aac_decoder_create(37000, 2) == nullptr, "unsupported sample rate refused");
  CHECK(aac_decoder_create(44100, 9) == nullptr, "nine channels refused");
}

int main() {
  test_ms_signalling();
  test_crc_and_truncation();
  test_random_input();
  test_stream_parameters();
  return test_summary("test_decoder_compliance");
}
