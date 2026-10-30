/* Encoder harness: the options from the first bytes, the rest as PCM;
 * encode and flush, then decode the stream with our decoder, which must
 * accept every frame and produce finite output.
 *
 *   byte 0   the profile: LC, HE-AAC v1, v2, LD, ELD, USAC (mod 6)
 *   byte 1   the sample rate from a table that ends above 96 kHz and
 *            below 8 kHz (mod 16)
 *   byte 2   channels (mod 9; 0 is refused)
 *   bytes 3-4  the bitrate in units of 64 bit/s (0 to 4.2 Mbit/s)
 *   byte 5   the rate control mode (mod 5; 4 is refused)
 *   byte 6   bits 0-3 quality, bits 4-5 the input gain (1, 1/4, 2, 8)
 *   byte 7   bits 0-1 frame length (default, 960/480, 1024/512, 777),
 *            bit 2 afterburner, bit 3 ADTS CRC, bit 4 MPEG-2, bits 5-7
 *            transport (default, ADTS, LOAS, LATM in-band, LATM out of
 *            band, raw, detect)
 *   byte 8   bits 0-1 signalling, bits 2-4 channel mode (default and
 *            the 7.1 modes), bit 5 LD-SBR, bit 6 dual rate, bit 7 the
 *            one-pass loudness meter
 *   byte 9   USAC: bit 0 noise filling, bit 1 pre-roll, bits 2-3 SBR
 *            (-1, 0, 1, 2), bits 4-5 MPS 212, bits 6-7 independent
 *            interval (0, 1, 4, 37)
 *   byte 10  the DRC profile (mod 7; 6 is refused)
 *   byte 11  bits 0-6 prog_ref_level, bit 7 an invalid level
 *   byte 12  the ELD extension payload length (bytes follow)
 *   rest     16-bit little-endian PCM, interleaved; at most six frames
 *            are encoded */
#include <cstring>
#include <vector>

#include "fuzz_driver.h"

static void check_setter(int ret) {
  if (ret != AAC_OK && ret != AAC_ERR_INVALID_ARG && ret != AAC_ERR_UNSUPPORTED &&
      ret != AAC_ERR_STATE && ret != AAC_ERR_OVERFLOW) {
    fuzz_fail("encoder setter return outside the documented codes", ret);
  }
}

extern "C" int LLVMFuzzerTestOneInput(const uint8_t* data, size_t size) {
  FuzzInput in(data, size);
  static const AacObjectType kAot[6] = {AAC_AOT_LC,    AAC_AOT_SBR,    AAC_AOT_PS,
                                        AAC_AOT_ER_LD, AAC_AOT_ER_ELD, AAC_AOT_USAC};
  static const int kRates[16] = {8000,  11025, 12000, 16000, 22050, 24000, 32000,  44100,
                                 48000, 64000, 88200, 96000, 7350,  192000, 7999, 0};
  const AacObjectType aot = kAot[in.byte() % 6];
  const int rate = kRates[in.byte() & 15];
  const int channels = in.byte() % 9;
  const int bitrate = (int)in.u16() * 64;
  const int rc = in.byte() % 5;
  const uint8_t quality = in.byte();
  const uint8_t frame = in.byte();
  const uint8_t ext = in.byte();
  const uint8_t usac = in.byte();
  const uint8_t drc = in.byte();
  const uint8_t level = in.byte();
  const uint8_t payload_len = in.byte();
  const uint8_t* payload;
  const size_t payload_got = in.take(payload_len, &payload);

  AacEncoderHandle enc = aac_encoder_create(rate, channels, bitrate, aot, (AacRateControl)rc);
  if (!enc) {
    return 0;
  }
  const bool low_delay = aot == AAC_AOT_ER_LD || aot == AAC_AOT_ER_ELD;
  static const int kFrames[2][4] = {{0, 960, 1024, 777}, {0, 480, 512, 777}};
  const int frame_length = kFrames[low_delay ? 1 : 0][frame & 3];
  static const AacTransport kTransports[8] = {
      AAC_TRANSPORT_DETECT, AAC_TRANSPORT_ADTS, AAC_TRANSPORT_LOAS,     AAC_TRANSPORT_LATM_MCP1,
      AAC_TRANSPORT_LATM_MCP0, AAC_TRANSPORT_RAW, AAC_TRANSPORT_DETECT, AAC_TRANSPORT_ADTS};
  AacTransport transport = kTransports[frame >> 5 & 7];
  static const AacChannelMode kModes[8] = {
      AAC_MODE_DEFAULT,       AAC_MODE_7_1_FRONT_CENTER,  AAC_MODE_7_1_BACK, AAC_MODE_7_1_TOP_FRONT,
      AAC_MODE_7_1_REAR_SURROUND, AAC_MODE_DEFAULT,       (AacChannelMode)9, AAC_MODE_DEFAULT};

  check_setter(aac_encoder_set_quality(enc, quality & 15));
  if (frame_length) {
    check_setter(aac_encoder_set_frame_length(enc, frame_length));
  }
  if (transport != AAC_TRANSPORT_DETECT) {
    const int ret = aac_encoder_set_transport(enc, transport);
    check_setter(ret);
    if (ret != AAC_OK) {
      transport = AAC_TRANSPORT_DETECT;
    }
  }
  check_setter(aac_encoder_set_signaling(enc, (AacSignaling)(ext & 3)));
  check_setter(aac_encoder_set_channel_mode(enc, kModes[ext >> 2 & 7]));
  check_setter(aac_encoder_set_afterburner(enc, frame >> 2 & 1));
  check_setter(aac_encoder_set_crc(enc, frame >> 3 & 1));
  check_setter(aac_encoder_set_mpeg2(enc, frame >> 4 & 1));
  if (ext & 0x20) {
    check_setter(aac_encoder_set_ld_sbr(enc, 1, ext >> 6 & 1));
  }
  check_setter(aac_encoder_set_loudness_measurement(enc, ext >> 7 & 1));
  check_setter(aac_encoder_set_metadata(enc, (AacDrcProfile)(drc % 7),
                                        (level & 0x80) ? 200 : (level & 0x7F)));
  if (payload_len) {
    check_setter(aac_encoder_set_extension_payload(enc, payload, (int)payload_got * 8));
  }
  if (aot == AAC_AOT_USAC) {
    static const int kSbr[4] = {-1, 0, 1, 2};
    static const int kInterval[4] = {0, 1, 4, 37};
    AacUsacEncoderOptions o;
    o.noise_filling = usac & 1;
    o.preroll = usac >> 1 & 1;
    o.sbr = kSbr[usac >> 2 & 3];
    o.mps212 = kSbr[usac >> 4 & 3];
    o.independent_interval_frames = kInterval[usac >> 6 & 3];
    o.stream_id = (ext & 0x80) ? bitrate & 0xFFFF : -1;
    check_setter(aac_encoder_set_usac(enc, &o));
  }

  const int frame_size = aac_encoder_frame_size(enc);
  const int max_bytes = aac_encoder_max_frame_bytes(enc);
  if (frame_size <= 0 || frame_size > 4096 || max_bytes <= 0) {
    fuzz_fail("encoder frame size or max frame bytes out of range", frame_size);
  }
  static const float kGain[4] = {1.0f, 0.25f, 2.0f, 8.0f};
  const float gain = kGain[quality >> 4 & 3] / 32768.0f;
  const size_t block = (size_t)frame_size * (size_t)channels;
  std::vector<float> pcm(block);
  std::vector<uint8_t> buf((size_t)max_bytes * 4 + 1024);
  /* The encoder's output per call: a stream transport (ADTS, LOAS) is
   * their concatenation, a packet transport (LATM, raw) hands the
   * decoder one unit at a time. */
  std::vector<std::vector<uint8_t>> units;
  const bool packet = transport == AAC_TRANSPORT_LATM_MCP1 ||
                      transport == AAC_TRANSPORT_LATM_MCP0 || transport == AAC_TRANSPORT_RAW;
  const uint8_t* samples = in.rest();
  size_t left = in.left() / 2;
  int frames = 0;
  while (left > 0 && frames < 6) {
    const size_t n = left < block ? left : block;
    for (size_t i = 0; i < block; i++) {
      const int16_t s = i < n ? (int16_t)(samples[2 * i] | samples[2 * i + 1] << 8) : 0;
      pcm[i] = (float)s * gain;
    }
    samples += 2 * n;
    left -= n;
    const int len = aac_encoder_encode(enc, pcm.data(), frame_size, buf.data(), (int)buf.size());
    if (len < 0) {
      fuzz_fail("encode refused a frame", len);
    }
    if (len > max_bytes) {
      fuzz_fail("a frame larger than aac_encoder_max_frame_bytes", len);
    }
    units.emplace_back(buf.begin(), buf.begin() + len);
    frames++;
    if (frames == 2) {
      /* Readbacks and the setters that are refused once a stream runs. */
      AacLoudnessInfo info;
      aac_encoder_loudness(enc, &info);
      check_setter(aac_encoder_set_transport(enc, AAC_TRANSPORT_LOAS));
      check_setter(aac_encoder_set_frame_length(enc, 960));
      check_setter(aac_encoder_set_afterburner(enc, (frame >> 2 & 1) ^ 1));
    }
  }
  for (int i = 0; i < 64; i++) {
    const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
    if (len < 0) {
      fuzz_fail("flush refused", len);
    }
    if (len == 0) {
      break;
    }
    units.emplace_back(buf.begin(), buf.begin() + len);
  }
  uint8_t asc[64];
  const int asc_len = aac_encoder_config(enc, asc, (int)sizeof(asc));
  if (asc_len < 0 && asc_len != AAC_ERR_OVERFLOW) {
    fuzz_fail("config return outside the documented codes", asc_len);
  }
  aac_encoder_delay(enc);
  aac_encoder_destroy(enc);
  size_t total = 0;
  for (const std::vector<uint8_t>& u : units) {
    total += u.size();
  }
  if (frames == 0 || total == 0) {
    return 0;
  }

  /* Our decoder on the result: a raw or out-of-band stream needs the
   * configuration, the others are detected. */
  AacDecoderHandle dec = aac_decoder_create(rate, 0);
  if (!dec) {
    fuzz_fail("decoder refuses the encoder's rate", rate);
  }
  if (frame_length == 960) {
    aac_decoder_set_frame_length(dec, 960);
  }
  if (transport == AAC_TRANSPORT_RAW || transport == AAC_TRANSPORT_LATM_MCP0) {
    if (asc_len <= 0) {
      fuzz_fail("no configuration for a raw or out-of-band stream", asc_len);
    }
    const int ret = aac_decoder_set_config(dec, asc, asc_len);
    if (ret != AAC_OK) {
      fuzz_fail("the decoder refuses the encoder's configuration", ret);
    }
    aac_decoder_set_transport(dec, transport);
  }
  std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  int decoded = 0;
  int errors = 0;
  if (packet) {
    for (const std::vector<uint8_t>& u : units) {
      const int n = aac_decoder_decode(dec, u.data(), (int)u.size(), out.data(), (int)out.size());
      if (n < 0) {
        errors++;
      } else if (n > 0) {
        fuzz_expect_finite(out.data(), (size_t)n * (size_t)aac_decoder_channels(dec),
                           "decoded output not finite");
        decoded++;
      }
    }
  } else {
    std::vector<uint8_t> stream;
    stream.reserve(total);
    for (const std::vector<uint8_t>& u : units) {
      stream.insert(stream.end(), u.begin(), u.end());
    }
    size_t pos = 0;
    for (int calls = 0; calls < 256; calls++) {
      if (pos < stream.size()) {
        const int taken = aac_decoder_feed(dec, stream.data() + pos, (int)(stream.size() - pos));
        if (taken < 0) {
          fuzz_fail("feed refused", taken);
        }
        pos += (size_t)taken;
      }
      const int n = aac_decoder_decode_next(dec, out.data(), (int)out.size());
      if (n < 0) {
        errors++;
        if (n == AAC_ERR_UNSUPPORTED) {
          break;
        }
        continue;
      }
      if (n == 0) {
        if (pos >= stream.size()) {
          break;
        }
        continue;
      }
      fuzz_expect_finite(out.data(), (size_t)n * (size_t)aac_decoder_channels(dec),
                         "decoded output not finite");
      decoded++;
    }
  }
  aac_decoder_destroy(dec);
  if (errors) {
    fuzz_fail("our decoder reports errors on our encoder's stream", errors);
  }
  if (decoded == 0) {
    fuzz_fail("our decoder decodes nothing of our encoder's stream", (long)total);
  }
  return 0;
}
