/* Rate control: the modes hit their bitrate or react to quality, frames
 * stay within the AAC limits, ADTS buffer_fullness reflects the mode, and
 * the API rejects what it does not support. */
#include "bitstream.h"
#include "test_util.h"

static double kbps_of(const std::vector<uint8_t>& stream, int sr) {
  const size_t frames = split_adts(stream).size();
  return frames ? (double)stream.size() * 8.0 * sr / 1024.0 / (double)frames / 1000.0 : 0.0;
}

static void test_cbr_abr() {
  const int sr = 44100;
  const std::vector<float> pcm = gen_noise(sr * 6, 2); /* needs every bit it can get */
  for (AacRateControl rc : {AAC_RC_CBR, AAC_RC_ABR}) {
    for (int bitrate : {64000, 128000, 256000}) {
      const std::vector<uint8_t> stream = encode_stream(pcm, sr, 2, bitrate, rc);
      const double kbps = kbps_of(stream, sr);
      printf("rc%d %d kbps: %.1f kbps\n", rc, bitrate / 1000, kbps);
      CHECK(fabs(kbps - bitrate / 1000.0) < 0.06 * bitrate / 1000.0,
            "rc%d target %d: achieved %.1f kbps", rc, bitrate, kbps);
      int fullness_vbr = 0;
      for (const AdtsFrame& f : split_adts(stream)) {
        CHECK(f.size <= 2 * 6144 / 8 + AAC_ADTS_HEADER_SIZE, "frame of %zu bytes", f.size);
        AacAdtsHeader h;
        aac_adts_parse(&h, stream.data() + f.offset, (int)f.size);
        fullness_vbr += h.buffer_fullness == 0x7FF;
      }
      CHECK(fullness_vbr == 0, "rc%d: %d frames carry the VBR marker", rc, fullness_vbr);
    }
  }
}

static void test_vbr() {
  const int sr = 44100;
  const std::vector<float> pcm = gen_harmonics(sr * 4, sr, 2);
  const std::vector<uint8_t> low = encode_stream(pcm, sr, 2, 128000, AAC_RC_TVBR, 30);
  const std::vector<uint8_t> mid = encode_stream(pcm, sr, 2, 128000, AAC_RC_TVBR, 70);
  const std::vector<uint8_t> high = encode_stream(pcm, sr, 2, 128000, AAC_RC_TVBR, 95);
  printf("tvbr q30/q70/q95: %.1f / %.1f / %.1f kbps\n", kbps_of(low, sr), kbps_of(mid, sr),
         kbps_of(high, sr));
  CHECK(low.size() < mid.size() && mid.size() < high.size(), "TVBR size grows with quality");
  int errors = 0;
  const std::vector<float> out_low = decode_stream(low, sr, 2, &errors);
  const std::vector<float> out_high = decode_stream(high, sr, 2, &errors);
  CHECK(errors == 0, "VBR streams decode");
  CHECK(aligned_snr_db(pcm, out_high, 2, 2048) > aligned_snr_db(pcm, out_low, 2, 2048) + 3.0,
        "TVBR quality improves the reconstruction");
  for (const AdtsFrame& f : split_adts(high)) {
    AacAdtsHeader h;
    aac_adts_parse(&h, high.data() + f.offset, (int)f.size);
    CHECK(h.buffer_fullness == 0x7FF, "VBR marker in TVBR frames");
  }
  const std::vector<uint8_t> cvbr = encode_stream(gen_noise(sr * 3, 2), sr, 2, 96000, AAC_RC_CVBR);
  const int avg_bytes = 96000 * 1024 / sr / 8;
  for (const AdtsFrame& f : split_adts(cvbr)) {
    CHECK((int)f.size <= avg_bytes * 3 / 2 + AAC_ADTS_HEADER_SIZE + 1,
          "CVBR frame of %zu bytes exceeds 1.5x the average", f.size);
  }
}

static void test_api_limits() {
  CHECK(aac_encoder_create(44100, 2, 128000, AAC_AOT_SBR, AAC_RC_CBR) == nullptr,
        "HE-AAC encoding is not offered");
  CHECK(aac_encoder_create(44100, 2, 128000, AAC_AOT_PS, AAC_RC_CBR) == nullptr,
        "HE-AAC v2 encoding is not offered");
  CHECK(aac_encoder_create(44100, 3, 128000, AAC_AOT_LC, AAC_RC_CBR) == nullptr, "3 channels");
  CHECK(aac_encoder_create(37000, 2, 128000, AAC_AOT_LC, AAC_RC_CBR) == nullptr, "37 kHz");
  CHECK(aac_encoder_create(44100, 2, 0, AAC_AOT_LC, AAC_RC_CBR) == nullptr, "zero bitrate");

  AacEncoderHandle enc = aac_encoder_create(44100, 1, 64000, AAC_AOT_LC, AAC_RC_CBR);
  float pcm[1024] = {0};
  uint8_t out[4096];
  CHECK(aac_encoder_encode(enc, pcm, 512, out, sizeof(out)) == AAC_ERR_INVALID_ARG,
        "partial frame refused");
  CHECK(aac_encoder_encode(enc, pcm, 1024, out, 4) == AAC_ERR_OVERFLOW, "tiny output buffer");
  CHECK(aac_encoder_set_quality(enc, 0) == AAC_ERR_INVALID_ARG &&
            aac_encoder_set_quality(enc, 101) == AAC_ERR_INVALID_ARG &&
            aac_encoder_set_quality(enc, 50) == AAC_OK,
        "quality range");
  const int len = aac_encoder_encode(enc, pcm, 1024, out, sizeof(out));
  CHECK(len > AAC_ADTS_HEADER_SIZE, "silent frame encodes (%d bytes)", len);
  const int flushed = aac_encoder_flush(enc, out, sizeof(out));
  CHECK(flushed > 0 && split_adts(std::vector<uint8_t>(out, out + flushed)).size() == 2,
        "flush emits the two frames of delay");
  CHECK(aac_encoder_flush(enc, out, sizeof(out)) == 0, "second flush has nothing left");
  aac_encoder_destroy(enc);
}

int main() {
  test_cbr_abr();
  test_vbr();
  test_api_limits();
  return test_summary("test_ratecontrol");
}
