/* MPEG Surround streams through the public API: the committed LOAS
 * streams decode to six channels, match the libxaac decodes per channel,
 * downmix to a stereo request, and report the surround flag, the core
 * object type and the delay. */
#include <cmath>
#include <cstdio>
#include <cstring>
#include <string>
#include <vector>

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
 * with no offset: both decoders carry the same delay. */
void test_against_libxaac() {
  const char* streams[2] = {"mps_5151_header_q.loas", "mps_5152_header_q.loas"};
  const char* wavs[2] = {"mps_5151_header_q_xaac.wav", "mps_5152_header_q_xaac.wav"};
  for (int i = 0; i < 2; i++) {
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

}  // namespace

int main() {
  test_against_libxaac();
  test_stereo_request();
  test_asc_form();
  test_committed_streams_decode();
  return test_summary("test_mps_stream");
}
