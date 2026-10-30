/* MPEG Surround streams through the public API: the committed LOAS
 * streams decode to six channels, match the libxaac decodes per channel,
 * downmix to a stereo request, and report the surround flag, the core
 * object type and the delay. */
#include <cmath>
#include <cstdio>
#include <cstring>
#include <string>
#include <vector>

#include "asc.h"
#include "test_util.h"

namespace {

constexpr int kChannels = 6;
constexpr int kRate = 48000;
constexpr const char* kNames[kChannels] = {"L", "R", "C", "LFE", "Ls", "Rs"};

struct Decoded {
  std::vector<float> pcm; /* interleaved */
  int channels = 0;
  int frames = 0;
  int errors = 0;
  int has_surround = 0;
  int object_type = 0;
  int delay = 0;
};

/* Decodes a committed LOAS stream whole through the FIFO. */
Decoded decode_stream(const char* name, int channels) {
  Decoded d;
  const std::vector<uint8_t> data = read_file(data_path(name));
  CHECK(!data.empty(), "%s present", name);
  if (data.empty()) {
    return d;
  }
  AacDecoderHandle dec = aac_decoder_create(kRate, channels);
  CHECK(dec != nullptr, "decoder created");
  if (!dec) {
    return d;
  }
  CHECK(aac_decoder_feed(dec, data.data(), (int)data.size()) == (int)data.size(), "%s: stream fed", name);
  std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  for (;;) {
    const int got = aac_decoder_decode_next(dec, out.data(), (int)out.size());
    if (got == 0) {
      break;
    }
    if (got < 0) {
      d.errors++;
      continue;
    }
    const int nch = aac_decoder_channels(dec);
    if (d.frames == 0) {
      d.channels = nch;
      d.has_surround = aac_decoder_has_surround(dec);
      d.object_type = aac_decoder_object_type(dec);
      d.delay = aac_decoder_delay(dec);
    }
    CHECK(nch == d.channels, "%s: channel count stays %d", name, d.channels);
    d.pcm.insert(d.pcm.end(), out.begin(), out.begin() + (size_t)got * nch);
    d.frames++;
  }
  aac_decoder_destroy(dec);
  return d;
}

/* A 16-bit PCM WAV: interleaved floats and its channel count. */
std::vector<float> read_wav16(const char* name, int* channels) {
  const std::vector<uint8_t> w = read_file(data_path(name));
  std::vector<float> pcm;
  *channels = 0;
  CHECK(w.size() > 44, "%s present", name);
  if (w.size() <= 44) {
    return pcm;
  }
  *channels = w[22] | (w[23] << 8);
  size_t pos = 12;
  while (pos + 8 <= w.size()) {
    const uint32_t size = w[pos + 4] | (w[pos + 5] << 8) | (w[pos + 6] << 16) | ((uint32_t)w[pos + 7] << 24);
    if (memcmp(&w[pos], "data", 4) == 0) {
      const size_t n = std::min((size_t)size, w.size() - pos - 8) / 2;
      pcm.resize(n);
      for (size_t i = 0; i < n; i++) {
        const int16_t v = (int16_t)(w[pos + 8 + 2 * i] | (w[pos + 9 + 2 * i] << 8));
        pcm[i] = (float)v / 32768.0f;
      }
      return pcm;
    }
    pos += 8 + size + (size & 1);
  }
  CHECK(false, "%s: data chunk", name);
  return pcm;
}

/* SNR of one channel of two interleaved signals over their common length. */
double channel_snr_db(const std::vector<float>& ref, const std::vector<float>& out, int channels, int c) {
  const size_t n = std::min(ref.size(), out.size()) / channels;
  double sig = 0.0, err = 0.0;
  for (size_t i = 0; i < n; i++) {
    const double r = ref[i * channels + c];
    const double d = r - out[i * channels + c];
    sig += r * r;
    err += d * d;
  }
  return err > 0.0 ? 10.0 * log10(sig / err) : 200.0;
}

/* The decodes of the 5.1 trees against libxaac's, channel by channel,
 * with no offset: both decoders carry the same delay.  The 525 tree in
 * the TTT prediction mode (1) and, from a broadband programme, in the
 * energy mode (5); libxaac's fixed-point energy weights lose precision
 * beyond about 45 dB of level difference, so the energy stream keeps
 * every band populated. */
void test_against_libxaac() {
  const char* streams[4] = {"mps_5151_header_q.loas", "mps_5152_header_q.loas", "mps_525_header_q.loas",
                            "mps_525_energy_q.loas"};
  const char* wavs[4] = {"mps_5151_header_q_xaac.wav", "mps_5152_header_q_xaac.wav",
                         "mps_525_header_q_xaac.wav", "mps_525_energy_q_xaac.wav"};
  for (int i = 0; i < 4; i++) {
    const Decoded d = decode_stream(streams[i], 0);
    CHECK(d.errors == 0, "%s: %d errors", streams[i], d.errors);
    CHECK(d.frames == 96, "%s: %d frames", streams[i], d.frames);
    CHECK(d.channels == kChannels, "%s: %d channels", streams[i], d.channels);
    CHECK(d.has_surround == 1, "%s: surround flagged", streams[i]);
    CHECK(d.object_type == AAC_AOT_LC, "%s: core object type %d", streams[i], d.object_type);
    /* the input alignment, the hybrid filterbank and the QMF pair */
    CHECK(d.delay == 560 + 6 * 64 + 577, "%s: delay %d", streams[i], d.delay);
    int wav_channels = 0;
    const std::vector<float> ref = read_wav16(wavs[i], &wav_channels);
    CHECK(wav_channels == kChannels, "%s: %d channels", wavs[i], wav_channels);
    if (d.channels != kChannels || wav_channels != kChannels) {
      continue;
    }
    for (int c = 0; c < kChannels; c++) {
      const double snr = channel_snr_db(ref, d.pcm, kChannels, c);
      printf("%s: %s %.1f dB against libxaac\n", streams[i], kNames[c], snr);
      CHECK(snr >= 40.0, "%s: %s %.1f dB against libxaac", streams[i], kNames[c], snr);
    }
  }
}

/* A stereo request mixes the six channels as the 5.1 layout. */
void test_stereo_request() {
  const Decoded six = decode_stream("mps_5151_header_q.loas", 0);
  const Decoded two = decode_stream("mps_5151_header_q.loas", 2);
  CHECK(two.channels == 2, "%d channels", two.channels);
  CHECK(two.errors == 0 && two.frames == six.frames, "stereo decode: %d errors, %d frames", two.errors,
        two.frames);
  if (six.channels != kChannels || two.channels != 2) {
    return;
  }
  const float k = 0.70710678f;
  const size_t n = std::min(six.pcm.size() / kChannels, two.pcm.size() / 2);
  std::vector<float> mixed(n * 2), got(n * 2);
  for (size_t i = 0; i < n; i++) {
    const float* s = &six.pcm[i * kChannels];
    mixed[2 * i] = s[0] + k * s[2] + k * s[4];
    mixed[2 * i + 1] = s[1] + k * s[2] + k * s[5];
    got[2 * i] = two.pcm[2 * i];
    got[2 * i + 1] = two.pcm[2 * i + 1];
  }
  for (int c = 0; c < 2; c++) {
    const double snr = channel_snr_db(mixed, got, 2, c);
    CHECK(snr >= 100.0, "stereo %s: %.1f dB against the mixed 5.1 decode", kNames[c], snr);
  }
}

/* The configuration in the AudioSpecificConfig of a second LATM layer
 * decodes like the in-band header of the same content. */
void test_asc_form() {
  const Decoded header = decode_stream("mps_525_header_q.loas", 0);
  const Decoded asc = decode_stream("mps_525_asc_q.loas", 0);
  for (const Decoded* d : {&header, &asc}) {
    CHECK(d->errors == 0 && d->frames == 96, "525: %d errors, %d frames", d->errors, d->frames);
    CHECK(d->channels == kChannels, "525: %d channels", d->channels);
    CHECK(d->has_surround == 1, "525: surround flagged");
  }
  if (header.channels != kChannels || asc.channels != kChannels) {
    return;
  }
  for (int c = 0; c < kChannels; c++) {
    const double snr = channel_snr_db(header.pcm, asc.pcm, kChannels, c);
    printf("525 ASC form: %s %.1f dB against the header form\n", kNames[c], snr);
    CHECK(snr >= 40.0, "525 ASC form: %s %.1f dB against the header form", kNames[c], snr);
  }
}

/* The louder committed streams decode without error to six channels. */
void test_committed_streams_decode() {
  const char* streams[4] = {"mps_5151_header.loas", "mps_5152_header.loas", "mps_525_header.loas",
                            "mps_525_asc.loas"};
  for (const char* name : streams) {
    const Decoded d = decode_stream(name, 0);
    CHECK(d.errors == 0 && d.frames == 96, "%s: %d errors, %d frames", name, d.errors, d.frames);
    CHECK(d.channels == kChannels && d.has_surround == 1, "%s: %d channels, surround %d", name, d.channels,
          d.has_surround);
  }
}

/* AAC-ELD v2: the ELD core with the low-delay 2-1-2 payload decodes
 * to stereo and matches libxaac's decode per channel; both carry the
 * low-delay filterbank pair's delay over the core. */
void test_eld_v2() {
  const char* stream = "mps_212_eld.loas";
  const Decoded d = decode_stream(stream, 0);
  CHECK(d.errors == 0, "%s: %d errors", stream, d.errors);
  CHECK(d.frames == 190, "%s: %d frames", stream, d.frames);
  CHECK(d.channels == 2, "%s: %d channels", stream, d.channels);
  CHECK(d.has_surround == 1, "%s: surround flagged", stream);
  CHECK(d.object_type == AAC_AOT_ER_ELD, "%s: core object type %d", stream, d.object_type);
  CHECK(d.delay == 256, "%s: delay %d", stream, d.delay);
  int wav_channels = 0;
  const std::vector<float> ref = read_wav16("mps_212_eld_xaac.wav", &wav_channels);
  CHECK(wav_channels == 2, "eld xaac wav: %d channels", wav_channels);
  if (d.channels != 2 || wav_channels != 2) {
    return;
  }
  for (int c = 0; c < 2; c++) {
    const double snr = channel_snr_db(ref, d.pcm, 2, c);
    printf("%s: %s %.1f dB against libxaac\n", stream, kNames[c], snr);
    CHECK(snr >= 40.0, "%s: %s %.1f dB against libxaac", stream, kNames[c], snr);
  }
}

/* A reconfiguration to a stream without surround drops the surround
 * decoder: after the 525 stream configured by its AudioSpecificConfig,
 * the LOAS frames of a plain stereo stream decode to the core's two
 * channels, not to six built from the stale surround configuration. */
void test_reconfigure_without_surround() {
  const std::vector<uint8_t> data = read_file(data_path("mps_525_asc_q.loas"));
  CHECK(!data.empty(), "525 ASC stream present");
  AacDecoderHandle dec = aac_decoder_create(kRate, 0);
  CHECK(dec != nullptr, "decoder created");
  if (!dec || data.empty()) {
    return;
  }
  CHECK(aac_decoder_feed(dec, data.data(), (int)data.size()) == (int)data.size(), "surround stream fed");
  std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  int frames = 0;
  for (;;) {
    const int got = aac_decoder_decode_next(dec, out.data(), (int)out.size());
    if (got <= 0) {
      break;
    }
    frames++;
  }
  CHECK(frames == 96 && aac_decoder_channels(dec) == kChannels && aac_decoder_has_surround(dec) == 1,
        "surround stream: %d frames, %d channels, surround %d", frames, aac_decoder_channels(dec),
        aac_decoder_has_surround(dec));
  /* the plain stereo stream, each LOAS frame with its StreamMuxConfig */
  AacEncoderHandle enc = aac_encoder_create(kRate, 2, 128000, AAC_AOT_LC, AAC_RC_CBR);
  CHECK(enc != nullptr, "stereo encoder");
  if (!enc) {
    aac_decoder_destroy(dec);
    return;
  }
  CHECK(aac_encoder_set_transport(enc, AAC_TRANSPORT_LOAS) == AAC_OK, "LOAS transport");
  const int frame = aac_encoder_frame_size(enc);
  std::vector<float> block((size_t)frame * 2);
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) + 64);
  int stereo_frames = 0;
  for (int f = 0; f < 8; f++) {
    for (int i = 0; i < frame; i++) {
      const float v = 0.25f * sinf(2.0f * 3.14159265f * 440.0f * (float)(f * frame + i) / (float)kRate);
      block[(size_t)(2 * i)] = v;
      block[(size_t)(2 * i + 1)] = -v;
    }
    const int len = aac_encoder_encode(enc, block.data(), frame, buf.data(), (int)buf.size());
    CHECK(len >= 0, "stereo frame %d: %d", f, len);
    if (len <= 0) {
      continue;
    }
    CHECK(aac_decoder_feed(dec, buf.data(), len) == len, "stereo frame %d fed", f);
    const int got = aac_decoder_decode_next(dec, out.data(), (int)out.size());
    CHECK(got == frame, "stereo frame %d: %d samples", f, got);
    if (got > 0) {
      stereo_frames++;
      CHECK(aac_decoder_channels(dec) == 2 && aac_decoder_has_surround(dec) == 0,
            "stereo frame %d: %d channels, surround %d", f, aac_decoder_channels(dec),
            aac_decoder_has_surround(dec));
    }
  }
  CHECK(stereo_frames > 0, "stereo frames decoded");
  aac_encoder_destroy(enc);
  aac_decoder_destroy(dec);
}

/* An AAC-ELD stream of mono frames of `frame_length` samples from the
 * encoder, with its AudioSpecificConfig extended by a low-delay 2-1-2
 * surround configuration of `slots` QMF slots per spatial frame; the
 * frames carry no surround payload. */
struct EldStream {
  std::vector<uint8_t> asc;
  std::vector<std::vector<uint8_t>> aus;
};

EldStream eld_stream_with_surround(int frame_length, int slots) {
  EldStream s;
  AacEncoderHandle enc = aac_encoder_create(kRate, 1, 64000, AAC_AOT_ER_ELD, AAC_RC_CBR);
  CHECK(enc != nullptr, "ELD encoder");
  if (!enc) {
    return s;
  }
  CHECK(aac_encoder_set_frame_length(enc, frame_length) == AAC_OK, "ELD %d", frame_length);
  CHECK(aac_encoder_set_transport(enc, AAC_TRANSPORT_RAW) == AAC_OK, "ELD raw access units");
  uint8_t asc_bytes[AAC_ASC_MAX_BYTES];
  const int asc_len = aac_encoder_config(enc, asc_bytes, sizeof(asc_bytes));
  AacAsc asc;
  CHECK(asc_len > 0 && aac_asc_parse(asc_bytes, asc_len, &asc) == AAC_OK, "ELD %d: config", frame_length);
  asc.mps_present = 1;
  asc.sac_payload_embedding = 1;
  AacMpsConfig* m = &asc.mps;
  memset(m, 0, sizeof(*m));
  m->low_delay = 1;
  m->sampling_frequency = kRate;
  m->frame_length = slots;
  m->freq_res = 3;
  m->tree_config = AAC_MPS_TREE_212;
  m->num_ott_boxes = 1;
  m->num_input_channels = 1;
  m->num_output_channels = 2;
  m->ld_num_output_channels = 2;
  m->ott_bands[0] = 12;
  uint8_t with_surround[AAC_ASC_MAX_BYTES];
  const int len = aac_asc_write(&asc, with_surround, sizeof(with_surround));
  CHECK(len > 0, "ELD %d: config with surround (%d)", frame_length, len);
  if (len > 0) {
    s.asc.assign(with_surround, with_surround + len);
  }
  std::vector<float> block((size_t)frame_length);
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc));
  for (int f = 0; f < 12; f++) {
    for (int i = 0; i < frame_length; i++) {
      block[(size_t)i] =
          0.25f * sinf(2.0f * 3.14159265f * 440.0f * (float)(f * frame_length + i) / (float)kRate);
    }
    const int n = aac_encoder_encode(enc, block.data(), frame_length, buf.data(), (int)buf.size());
    CHECK(n >= 0, "ELD %d: frame %d encodes (%d)", frame_length, f, n);
    if (n > 0) {
      s.aus.emplace_back(buf.begin(), buf.begin() + n);
    }
  }
  aac_encoder_destroy(enc);
  return s;
}

/* An AAC-ELD stream configured for low-delay surround: on 512-sample
 * frames the surround decoder is active from the configuration on, so
 * the output is stereo and flagged on every frame, the frames without a
 * payload included (it bridges them with the parameters it has); on
 * 480-sample frames, seven and a half 64-band QMF slots, it is refused
 * and the core's mono output stays whole, frame after frame. */
void test_eld_surround_configuration() {
  const int lengths[2] = {512, 480};
  const int slots[2] = {8, 7};
  for (int i = 0; i < 2; i++) {
    const int frame_length = lengths[i];
    const EldStream st = eld_stream_with_surround(frame_length, slots[i]);
    if (st.asc.empty() || st.aus.empty()) {
      continue;
    }
    AacDecoderHandle dec = aac_decoder_create(kRate, 0);
    CHECK(dec != nullptr, "ELD %d: decoder", frame_length);
    if (!dec) {
      continue;
    }
    CHECK(aac_decoder_set_config(dec, st.asc.data(), (int)st.asc.size()) == AAC_OK, "ELD %d: config applied",
          frame_length);
    const int expect_channels = frame_length == 512 ? 2 : 1;
    std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 2);
    int frames = 0;
    for (const std::vector<uint8_t>& au : st.aus) {
      const int got = aac_decoder_decode(dec, au.data(), (int)au.size(), out.data(), (int)out.size());
      CHECK(got == frame_length, "ELD %d: frame %d decodes %d samples", frame_length, frames, got);
      CHECK(aac_decoder_channels(dec) == expect_channels, "ELD %d: frame %d has %d channels", frame_length,
            frames, aac_decoder_channels(dec));
      CHECK(aac_decoder_has_surround(dec) == (expect_channels == 2 ? 1 : 0), "ELD %d: frame %d surround %d",
            frame_length, frames, aac_decoder_has_surround(dec));
      frames++;
    }
    aac_decoder_destroy(dec);
  }
}

}  // namespace

int main() {
  test_against_libxaac();
  test_stereo_request();
  test_asc_form();
  test_committed_streams_decode();
  test_eld_v2();
  test_reconfigure_without_surround();
  test_eld_surround_configuration();
  return test_summary("test_mps_stream");
}
