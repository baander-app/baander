/* The loudness metadata of the xHE-AAC encoder through the public API:
 * the two-pass form (aac_encoder_set_loudness_info writes the loudness
 * info set into the stream's configuration, which the decoder reads back
 * as the declared program loudness and normalises by), the one-pass form
 * (aac_encoder_set_loudness_measurement meters the input the encoder
 * codes, and aac_encoder_loudness reads the figures), the refusals, and
 * the output bytes, which neither form changes except for the
 * configuration extension. */
#include <cmath>
#include <cstdio>
#include <cstring>
#include <vector>

#include "aac.h"
#include "asc.h"
#include "bitstream.h"
#include "loudness.h"
#include "test_util.h"
#include "usac.h"

namespace {

constexpr int kRate = 48000;

/* A stereo 997 Hz sine at `dbfs` peak per channel. */
std::vector<float> stereo_sine(double seconds, double dbfs) {
  const size_t n = (size_t)(seconds * kRate);
  std::vector<float> pcm(n * 2);
  const double amp = pow(10.0, dbfs / 20.0);
  for (size_t i = 0; i < n; i++) {
    const float v = (float)(amp * sin(2.0 * M_PI * 997.0 * (double)i / kRate));
    pcm[i * 2] = v;
    pcm[i * 2 + 1] = v;
  }
  return pcm;
}

AacLoudnessInfo measure(const std::vector<float>& pcm, int rate, int channels) {
  AacLoudnessInfo info;
  memset(&info, 0, sizeof(info));
  AacLoudnessMeter* m = aac_loudness_create(rate, channels);
  CHECK(m != nullptr, "meter");
  if (m) {
    aac_loudness_feed(m, pcm.data(), (int)(pcm.size() / (size_t)channels));
    CHECK(aac_loudness_result(m, &info) == AAC_OK, "meter result");
    aac_loudness_destroy(m);
  }
  return info;
}

/* Encodes whole frames of `pcm` (the trailing partial frame is dropped,
 * so the meter inside the encoder sees exactly the input) and the flush
 * into one stream of the encoder's transport. */
std::vector<uint8_t> encode_all(AacEncoderHandle enc, const std::vector<float>& pcm, int channels) {
  std::vector<uint8_t> out;
  const int frame = aac_encoder_frame_size(enc);
  const size_t block = (size_t)frame * channels;
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) * 4);
  for (size_t pos = 0; pos + block <= pcm.size(); pos += block) {
    const int len = aac_encoder_encode(enc, pcm.data() + pos, frame, buf.data(), (int)buf.size());
    CHECK(len > 0, "encode (%d)", len);
    if (len < 0) {
      break;
    }
    out.insert(out.end(), buf.begin(), buf.begin() + len);
  }
  for (;;) {
    const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
    CHECK(len >= 0, "flush (%d)", len);
    if (len <= 0) {
      break;
    }
    out.insert(out.end(), buf.begin(), buf.begin() + len);
  }
  return out;
}

/* Decodes a LOAS stream through the public FIFO with concealment off and
 * returns the declared program loudness (LUFS times 100, INT32_MIN for
 * none) and the normalisation gain after the last frame. */
/* The suite exercises the plain frequency-domain core: SBR, which the
 * bitrate rule would pick for these rates, changes the frame length and
 * the delay the checks assume. */
AacEncoderHandle create_plain(int channels, int bitrate, AacObjectType aot) {
  AacEncoderHandle enc = aac_encoder_create(kRate, channels, bitrate, aot, AAC_RC_CBR);
  if (enc && aot == AAC_AOT_USAC) {
    AacUsacEncoderOptions o;
    aac_usac_encoder_options_init(&o);
    memset(&o, 0, sizeof(o));
    o.noise_filling = -1;
    o.preroll = -1;
    o.stream_id = -1;
    o.sbr = 0;
    o.mps212 = 0;
    aac_encoder_set_usac(enc, &o);
  }
  return enc;
}

int decode_loudness(const std::vector<uint8_t>& stream, int* program_x100, int* gain_x100,
                    std::vector<float>* pcm_out = nullptr) {
  AacDecoderHandle dec = aac_decoder_create(kRate, 0);
  CHECK(dec != nullptr, "decoder");
  if (!dec) {
    return -1;
  }
  aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
  CHECK(aac_decoder_feed(dec, stream.data(), (int)stream.size()) == (int)stream.size(), "fed");
  std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  int frames = 0;
  int errors = 0;
  for (;;) {
    const int got = aac_decoder_decode_next(dec, out.data(), (int)out.size());
    if (got == 0) {
      break;
    }
    if (got < 0) {
      errors++;
      continue;
    }
    frames++;
    if (pcm_out) {
      pcm_out->insert(pcm_out->end(), out.begin(),
                      out.begin() + (size_t)got * aac_decoder_channels(dec));
    }
  }
  CHECK(errors == 0, "%d decode errors", errors);
  *program_x100 = INT32_MIN;
  *gain_x100 = 0;
  CHECK(aac_decoder_loudness(dec, program_x100, gain_x100) == AAC_OK, "loudness readback");
  aac_decoder_destroy(dec);
  return frames;
}

/* The configuration extension of `type` in the encoder's
 * AudioSpecificConfig, or NULL when there is none. */
const AacUsacConfigExt* config_ext(AacEncoderHandle enc, AacAsc* asc, int type) {
  uint8_t bytes[AAC_USAC_MAX_CONFIG_BYTES + 16];
  const int len = aac_encoder_config(enc, bytes, (int)sizeof(bytes));
  CHECK(len > 0, "config of %d bytes", len);
  memset(asc, 0, sizeof(*asc));
  CHECK(aac_asc_parse(bytes, len, asc) == AAC_OK, "the decoder reads the config");
  for (int i = 0; i < asc->usac.num_config_exts; i++) {
    if (asc->usac.config_ext[i].type == type) {
      return &asc->usac.config_ext[i];
    }
  }
  return nullptr;
}

/* ── the two-pass form ─────────────────────────────────────────── */

void test_two_pass() {
  const std::vector<float> pcm = gen_wideband(kRate * 4, kRate, 2);
  const AacLoudnessInfo info = measure(pcm, kRate, 2);
  CHECK(info.has_true_peak && info.has_momentary && info.has_short_term && info.has_range,
        "a four second programme has every figure");

  AacEncoderHandle enc = create_plain(2, 96000, AAC_AOT_USAC);
  CHECK(enc != nullptr, "xHE-AAC encoder");
  if (!enc) {
    return;
  }
  CHECK(aac_encoder_set_loudness_info(enc, &info) == AAC_OK, "the set is accepted");
  /* The configuration carries the writer's bytes as the loudness info
   * extension, padded to whole bytes. */
  {
    AacAsc asc;
    const AacUsacConfigExt* ext = config_ext(enc, &asc, AAC_USAC_CFG_EXT_LOUDNESS_INFO);
    CHECK(ext != nullptr, "the config has the loudness info extension");
    std::vector<uint8_t> expected(AAC_USAC_MAX_CONFIG_EXT_BYTES, 0);
    AacBitWriter w;
    aac_bitwriter_init(&w, expected.data(), (int)expected.size());
    const int bits = aac_loudness_info_write(&w, &info);
    CHECK(bits > 0, "writer bits %d", bits);
    if (ext) {
      CHECK(ext->len == (bits + 7) / 8, "extension of %d bytes for %d bits", ext->len, bits);
      CHECK(memcmp(ext->data, expected.data(), (size_t)ext->len) == 0, "the writer's bytes");
    }
  }
  /* Once a stream has begun its configuration is fixed, until the flush
   * ends it. */
  {
    std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) * 4);
    CHECK(aac_encoder_encode(enc, pcm.data(), 1024, buf.data(), (int)buf.size()) > 0, "a frame");
    CHECK(aac_encoder_set_loudness_info(enc, &info) == AAC_ERR_STATE,
          "the set is refused once the stream has begun");
    CHECK(aac_encoder_set_loudness_info(enc, nullptr) == AAC_ERR_STATE,
          "so is removing it mid-stream");
    while (aac_encoder_flush(enc, buf.data(), (int)buf.size()) > 0) {
    }
  }
  const std::vector<uint8_t> stream = encode_all(enc, pcm, 2);
  /* The decoder reads the program loudness back at the quarter-dB code
   * resolution and normalises to its -24 LUFS default by it. */
  int program = 0;
  int gain = 0;
  std::vector<float> decoded;
  const int frames = decode_loudness(stream, &program, &gain, &decoded);
  CHECK(frames > 0, "%d frames decoded", frames);
  const int coded = (int)lround(info.integrated_lkfs * 4.0) * 25;
  CHECK(program == coded, "declared loudness %d, measured %.2f (code %d)", program,
        info.integrated_lkfs, coded);
  CHECK(gain == -2400 - coded, "normalisation gain %d for a programme at %d", gain, coded);
  /* The programme itself is coded as without the set: the decoded signal
   * matches the input through the normalisation gain. */
  {
    const double g = pow(10.0, gain / 2000.0);
    std::vector<float> scaled(pcm.size());
    for (size_t i = 0; i < pcm.size(); i++) {
      scaled[i] = (float)(pcm[i] * g);
    }
    const double snr = best_aligned_snr_db(scaled, decoded, 2 * 2048, 2 * 2048, nullptr);
    CHECK(snr > 12.0, "decoded SNR %.1f dB", snr);
  }
  /* After the flush the encoder takes a new set, or none: a stream
   * without the extension declares no loudness and is left alone. */
  CHECK(aac_encoder_set_loudness_info(enc, nullptr) == AAC_OK, "removed after the flush");
  {
    AacAsc asc;
    CHECK(config_ext(enc, &asc, AAC_USAC_CFG_EXT_LOUDNESS_INFO) == nullptr,
          "no loudness info extension once removed");
  }
  const std::vector<uint8_t> plain = encode_all(enc, pcm, 2);
  decode_loudness(plain, &program, &gain);
  CHECK(program == INT32_MIN && gain == 0, "no declared loudness without the set (%d, %d)",
        program, gain);
  /* A second set replaces the first (one extension of the type), and the
   * writer's own bounds apply. */
  AacLoudnessInfo other = info;
  other.integrated_lkfs = -20.0f;
  other.has_anchor = 1;
  other.anchor_lkfs = -19.0f;
  CHECK(aac_encoder_set_loudness_info(enc, &info) == AAC_OK &&
            aac_encoder_set_loudness_info(enc, &other) == AAC_OK,
        "a second set replaces the first");
  {
    AacAsc asc;
    const AacUsacConfigExt* ext = config_ext(enc, &asc, AAC_USAC_CFG_EXT_LOUDNESS_INFO);
    int count = 0;
    for (int i = 0; i < asc.usac.num_config_exts; i++) {
      count += asc.usac.config_ext[i].type == AAC_USAC_CFG_EXT_LOUDNESS_INFO;
    }
    CHECK(ext != nullptr && count == 1, "one loudness info extension (%d)", count);
    if (ext) {
      CHECK(ext->len == (aac_loudness_info_bits(&other) + 7) / 8, "the second set's length");
    }
  }
  const std::vector<uint8_t> replaced = encode_all(enc, pcm, 2);
  decode_loudness(replaced, &program, &gain);
  CHECK(program == -2000, "the second set's loudness (%d)", program);
  aac_encoder_destroy(enc);
  /* The stream id extension and the loudness set share the list. */
  enc = create_plain(1, 64000, AAC_AOT_USAC);
  if (enc) {
    AacUsacEncoderOptions opt;
    aac_usac_encoder_options_init(&opt);
    memset(&opt, -1, sizeof(opt));
    opt.stream_id = 42;
    CHECK(aac_encoder_set_usac(enc, &opt) == AAC_OK && aac_encoder_set_loudness_info(enc, &info) == AAC_OK,
          "stream id and loudness set together");
    AacAsc asc;
    CHECK(config_ext(enc, &asc, AAC_USAC_CFG_EXT_LOUDNESS_INFO) != nullptr && asc.usac.stream_id == 42 &&
              asc.usac.num_config_exts == 2,
          "both extensions in the config (%d)", asc.usac.num_config_exts);
    aac_encoder_destroy(enc);
  }
}

void test_two_pass_refusals() {
  AacLoudnessInfo info;
  memset(&info, 0, sizeof(info));
  info.integrated_lkfs = -23.0f;
  info.sample_peak_dbfs = -3.0f;
  CHECK(aac_encoder_set_loudness_info(nullptr, &info) == AAC_ERR_INVALID_ARG, "no handle");
  const AacObjectType others[] = {AAC_AOT_LC, AAC_AOT_SBR, AAC_AOT_PS, AAC_AOT_ER_LD, AAC_AOT_ER_ELD};
  for (AacObjectType aot : others) {
    AacEncoderHandle enc = create_plain(2, 64000, aot);
    CHECK(enc != nullptr, "encoder of type %d", (int)aot);
    if (!enc) {
      continue;
    }
    CHECK(aac_encoder_set_loudness_info(enc, &info) == AAC_ERR_UNSUPPORTED,
          "type %d has no loudness info set", (int)aot);
    CHECK(aac_encoder_set_loudness_info(enc, nullptr) == AAC_ERR_UNSUPPORTED,
          "type %d: removing one is refused as well", (int)aot);
    aac_encoder_destroy(enc);
  }
}

/* ── the one-pass form ─────────────────────────────────────────── */

void test_one_pass() {
  const std::vector<float> loud = stereo_sine(2.0, -23.0);
  const std::vector<float> quiet = stereo_sine(1.0, -33.0);
  const AacObjectType types[] = {AAC_AOT_USAC, AAC_AOT_LC};
  for (AacObjectType aot : types) {
    AacEncoderHandle enc = create_plain(2, 96000, aot);
    CHECK(enc != nullptr, "encoder of type %d", (int)aot);
    if (!enc) {
      continue;
    }
    AacLoudnessInfo info;
    CHECK(aac_encoder_loudness(enc, &info) == AAC_ERR_STATE, "no figures without a meter");
    CHECK(aac_encoder_set_loudness_measurement(enc, 1) == AAC_OK, "type %d: meter on", (int)aot);
    CHECK(aac_encoder_set_loudness_measurement(enc, 1) == AAC_OK, "enabling twice is harmless");
    CHECK(aac_encoder_loudness(enc, &info) == AAC_ERR_STATE, "no figures before any input");
    CHECK(aac_encoder_loudness(enc, nullptr) == AAC_ERR_INVALID_ARG, "no output");
    const int frame = aac_encoder_frame_size(enc);
    std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) * 4);
    /* Fewer than 400 ms: no block yet. */
    const int frames_under = (int)((0.3 * kRate) / frame);
    for (int f = 0; f < frames_under; f++) {
      CHECK(aac_encoder_encode(enc, loud.data() + (size_t)f * frame * 2, frame, buf.data(),
                               (int)buf.size()) > 0,
            "frame %d", f);
    }
    CHECK(aac_encoder_loudness(enc, &info) == AAC_ERR_STATE, "no figures under 400 ms");
    CHECK(aac_encoder_set_loudness_measurement(enc, 1) == AAC_OK, "a running meter stays");
    /* The rest of the two seconds: EBU Tech 3341 case 1 reads -23 LUFS,
     * and the figures are the standalone meter's on the same frames. */
    std::vector<float> fed(loud.begin(), loud.begin() + (size_t)frames_under * frame * 2);
    for (int f = frames_under; (size_t)(f + 1) * frame * 2 <= loud.size(); f++) {
      const float* block = loud.data() + (size_t)f * frame * 2;
      CHECK(aac_encoder_encode(enc, block, frame, buf.data(), (int)buf.size()) > 0, "frame %d", f);
      fed.insert(fed.end(), block, block + (size_t)frame * 2);
    }
    CHECK(aac_encoder_loudness(enc, &info) == AAC_OK, "figures after two seconds");
    CHECK(fabs(info.integrated_lkfs + 23.0) < 0.1, "type %d: %.2f LKFS", (int)aot,
          info.integrated_lkfs);
    CHECK(info.has_true_peak && fabs(info.true_peak_dbtp + 23.0) < 0.1, "true peak %.2f",
          info.true_peak_dbtp);
    const AacLoudnessInfo direct = measure(fed, kRate, 2);
    CHECK(memcmp(&info, &direct, sizeof(info)) == 0, "the encoder's meter reads as the standalone one");
    /* The flush adds no input; the figures stay until the next stream,
     * which starts the meter over. */
    for (;;) {
      const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
      CHECK(len >= 0, "flush");
      if (len <= 0) {
        break;
      }
    }
    AacLoudnessInfo after;
    CHECK(aac_encoder_loudness(enc, &after) == AAC_OK && memcmp(&after, &info, sizeof(info)) == 0,
          "the figures survive the flush");
    for (size_t pos = 0; pos + (size_t)frame * 2 <= quiet.size(); pos += (size_t)frame * 2) {
      CHECK(aac_encoder_encode(enc, quiet.data() + pos, frame, buf.data(), (int)buf.size()) > 0,
            "quiet frame");
    }
    CHECK(aac_encoder_loudness(enc, &after) == AAC_OK && fabs(after.integrated_lkfs + 33.0) < 0.1,
          "the next stream is measured alone (%.2f LKFS)", after.integrated_lkfs);
    CHECK(aac_encoder_set_loudness_measurement(enc, 0) == AAC_OK, "meter off mid-stream");
    CHECK(aac_encoder_loudness(enc, &after) == AAC_ERR_STATE, "no figures once off");
    CHECK(aac_encoder_set_loudness_measurement(enc, 0) == AAC_OK, "off twice is harmless");
    aac_encoder_destroy(enc);
  }
  CHECK(aac_encoder_set_loudness_measurement(nullptr, 1) == AAC_ERR_INVALID_ARG, "no handle");
  CHECK(aac_encoder_loudness(nullptr, nullptr) == AAC_ERR_INVALID_ARG, "no handle");
  /* A meter measures a whole stream, so none can start in the middle of
   * one; after the flush it can. */
  {
    AacEncoderHandle enc = create_plain(2, 96000, AAC_AOT_USAC);
    CHECK(enc != nullptr, "encoder");
    if (enc) {
      std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) * 4);
      CHECK(aac_encoder_encode(enc, loud.data(), 1024, buf.data(), (int)buf.size()) > 0, "a frame");
      CHECK(aac_encoder_set_loudness_measurement(enc, 1) == AAC_ERR_STATE,
            "a meter cannot start mid-stream");
      while (aac_encoder_flush(enc, buf.data(), (int)buf.size()) > 0) {
      }
      CHECK(aac_encoder_set_loudness_measurement(enc, 1) == AAC_OK, "it can after the flush");
      aac_encoder_destroy(enc);
    }
  }
  /* The meter has weights for up to six channels; HE-AAC v2 meters the
   * stereo input at the input rate. */
  {
    AacEncoderHandle enc = aac_encoder_create(kRate, 8, 512000, AAC_AOT_LC, AAC_RC_CBR);
    CHECK(enc && aac_encoder_set_loudness_measurement(enc, 1) == AAC_ERR_UNSUPPORTED,
          "eight channels have no meter");
    aac_encoder_destroy(enc);
    enc = aac_encoder_create(kRate, 6, 320000, AAC_AOT_LC, AAC_RC_CBR);
    CHECK(enc && aac_encoder_set_loudness_measurement(enc, 1) == AAC_OK, "5.1 has one");
    aac_encoder_destroy(enc);
    enc = aac_encoder_create(44100, 2, 32000, AAC_AOT_PS, AAC_RC_CBR);
    CHECK(enc != nullptr && aac_encoder_set_loudness_measurement(enc, 1) == AAC_OK, "HE-AAC v2 meter");
    if (enc) {
      std::vector<float> sine = stereo_sine(1.0, -23.0); /* at 48 kHz: the level reads the same */
      const int frame = aac_encoder_frame_size(enc);
      std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) * 4);
      for (size_t pos = 0; pos + (size_t)frame * 2 <= sine.size(); pos += (size_t)frame * 2) {
        aac_encoder_encode(enc, sine.data() + pos, frame, buf.data(), (int)buf.size());
      }
      AacLoudnessInfo info;
      CHECK(aac_encoder_loudness(enc, &info) == AAC_OK && fabs(info.integrated_lkfs + 23.0) < 0.2,
            "HE-AAC v2 meters the stereo input (%.2f LKFS)", info.integrated_lkfs);
      aac_encoder_destroy(enc);
    }
  }
}

/* ── the bytes ─────────────────────────────────────────────────── */

void test_bytes_unchanged() {
  /* The meter changes no output byte; the two-pass set changes the
   * configuration (the StreamMuxConfig of every LOAS frame and the
   * pre-roll's copy) and nothing else, so the raw access units of a
   * stream without pre-roll are identical. */
  const std::vector<float> pcm = gen_wideband(kRate * 2, kRate, 2);
  const AacObjectType types[] = {AAC_AOT_LC, AAC_AOT_SBR, AAC_AOT_USAC};
  for (AacObjectType aot : types) {
    AacEncoderHandle plain = create_plain(2, 96000, aot);
    AacEncoderHandle metered = create_plain(2, 96000, aot);
    CHECK(plain && metered, "encoders of type %d", (int)aot);
    if (!plain || !metered) {
      continue;
    }
    CHECK(aac_encoder_set_loudness_measurement(metered, 1) == AAC_OK, "meter");
    const std::vector<uint8_t> a = encode_all(plain, pcm, 2);
    const std::vector<uint8_t> b = encode_all(metered, pcm, 2);
    CHECK(!a.empty() && a == b, "type %d: the meter leaves the bytes alone", (int)aot);
    aac_encoder_destroy(plain);
    aac_encoder_destroy(metered);
  }
  AacEncoderHandle plain = create_plain(2, 96000, AAC_AOT_USAC);
  AacEncoderHandle with_set = create_plain(2, 96000, AAC_AOT_USAC);
  if (plain && with_set) {
    AacUsacEncoderOptions opt;
    aac_usac_encoder_options_init(&opt);
    memset(&opt, -1, sizeof(opt));
    opt.preroll = 0;
    opt.independent_interval_frames = 0;
    const AacLoudnessInfo info = measure(pcm, kRate, 2);
    for (AacEncoderHandle enc : {plain, with_set}) {
      CHECK(aac_encoder_set_usac(enc, &opt) == AAC_OK, "no pre-roll");
      CHECK(aac_encoder_set_transport(enc, AAC_TRANSPORT_RAW) == AAC_OK, "raw access units");
    }
    CHECK(aac_encoder_set_loudness_info(with_set, &info) == AAC_OK, "the set");
    const std::vector<uint8_t> a = encode_all(plain, pcm, 2);
    const std::vector<uint8_t> b = encode_all(with_set, pcm, 2);
    CHECK(!a.empty() && a == b, "the loudness set leaves the access units alone");
  }
  aac_encoder_destroy(plain);
  aac_encoder_destroy(with_set);
}

}  // namespace

int main() {
  test_two_pass();
  test_two_pass_refusals();
  test_one_pass();
  test_bytes_unchanged();
  return test_summary("test_encoder_loudness");
}
