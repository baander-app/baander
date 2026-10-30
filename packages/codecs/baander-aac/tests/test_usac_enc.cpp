/* The xHE-AAC encoder's core: the arithmetic coder against the
 * decoder's, the UsacConfig writer against the reader, encode and decode
 * round trips through the library's own decoder, the bitrate of the raw
 * access units, the API's refusals, and the committed streams.  The
 * tests that need the plain core ask for it, since SBR and the 2-1-2 are
 * on by default below 64 kbit/s per channel (test_usac_enc_sbr covers
 * them).
 *
 * With arguments the program writes the committed streams instead:
 *   test_usac_enc --write-streams <dir>
 */
#include <cmath>
#include <cstdio>
#include <cstring>
#include <string>
#include <vector>

#include "aac.h"
#include "asc.h"
#include "bitstream.h"
#include "test_util.h"
#include "usac.h"
#include "usac_enc.h"
#include "usac_fd.h"

/* The committed streams are the release build's bytes; a sanitizer
 * build keeps the same flags but not the same vectorised arithmetic. */
#if defined(__SANITIZE_ADDRESS__) || defined(__SANITIZE_UNDEFINED__)
#define BAAC_SANITIZED 1
#elif defined(__has_feature)
#if __has_feature(address_sanitizer) || __has_feature(undefined_behavior_sanitizer)
#define BAAC_SANITIZED 1
#endif
#endif
#ifndef BAAC_SANITIZED
#define BAAC_SANITIZED 0
#endif

namespace {

constexpr int kRate = 48000;
constexpr int kFrame = 1024;

/* The defaults with SBR and the 2-1-2 off: the plain core. */
AacUsacEncoderOptions plain_options() {
  AacUsacEncoderOptions o;
  memset(&o, 0, sizeof(o));
  o.noise_filling = 1;
  o.preroll = 1;
  o.independent_interval_frames = 0;
  o.stream_id = -1;
  o.sbr = 0;
  o.mps212 = 0;
  return o;
}
const AacUsacEncoderOptions kPlain = plain_options();

/* ── the arithmetic coder ───────────────────────────────────────── */

/* Encodes every window of `spec` (lg lines coded of n each) with the
 * context carried from one to the next, decodes the bits with the
 * decoder's coder, and checks the values and the bit count. */
void arith_round_trip(const char* name, int n, int lg, const std::vector<std::vector<int>>& spec,
                      int reset_first) {
  std::vector<uint8_t> buf(65536);
  AacBitWriter w;
  aac_bitwriter_init(&w, buf.data(), (int)buf.size());
  AacUsacArithContext enc;
  aac_usac_arith_reset(&enc);
  std::vector<int> counted;
  for (size_t k = 0; k < spec.size(); k++) {
    AacUsacArithContext copy = enc;
    const int count = aac_usac_arith_encode(&copy, reset_first && k == 0, n, lg, spec[k].data(),
                                            nullptr);
    const int written = aac_usac_arith_encode(&enc, reset_first && k == 0, n, lg, spec[k].data(), &w);
    CHECK(count == written && written > 0, "%s: window %zu counts %d bits, writes %d", name, k,
          count, written);
    counted.push_back(written);
  }
  const int total = aac_bitwriter_bits_written(&w);
  AacBitReader r;
  aac_bitreader_init(&r, buf.data(), (total + 7) / 8);
  AacUsacArithContext dec;
  aac_usac_arith_reset(&dec);
  std::vector<int> x((size_t)n);
  int pos = 0;
  for (size_t k = 0; k < spec.size(); k++) {
    const int ret = aac_usac_arith_decode(&dec, reset_first && k == 0, n, lg, &r, x.data());
    CHECK(ret == AAC_OK, "%s: window %zu decodes (%d)", name, k, ret);
    CHECK(r.pos - pos == counted[k], "%s: window %zu reads %d bits of %d", name, k, r.pos - pos,
          counted[k]);
    pos = r.pos;
    CHECK(memcmp(x.data(), spec[k].data(), (size_t)n * sizeof(int)) == 0,
          "%s: window %zu round trips", name, k);
  }
  CHECK(memcmp(enc.prev, dec.prev, sizeof(enc.prev)) == 0 && enc.prev_n == dec.prev_n,
        "%s: the contexts agree after the frame", name);
}

std::vector<int> random_window(int n, int lg, unsigned* seed, int max_mag, int density) {
  std::vector<int> x((size_t)n, 0);
  for (int i = 0; i < lg; i++) {
    *seed = *seed * 1664525u + 1013904223u;
    const int r = (int)(*seed >> 8) % 100;
    if (r >= density) {
      continue;
    }
    *seed = *seed * 1664525u + 1013904223u;
    const int mag = (int)(*seed >> 8) % (max_mag + 1);
    x[(size_t)i] = (*seed & 0x80000000u) ? -mag : mag;
  }
  return x;
}

void test_arith() {
  unsigned seed = 11u;
  /* Long windows of small values, then of values deep in the escape
   * planes, then sparse spectra with runs of zeros before the stop. */
  arith_round_trip("long small", kFrame, 800, {random_window(kFrame, 800, &seed, 3, 80)}, 1);
  arith_round_trip("long escapes", kFrame, 1024, {random_window(kFrame, 1024, &seed, 500, 90)}, 1);
  arith_round_trip("long sparse", kFrame, 1024, {random_window(kFrame, 1024, &seed, 40, 8)}, 1);
  /* The largest magnitude the coder carries, and one beyond it. */
  {
    std::vector<int> x((size_t)kFrame, 0);
    x[0] = AAC_ESC_MAX_MAG;
    x[1] = -AAC_ESC_MAX_MAG;
    x[500] = AAC_ESC_MAX_MAG;
    arith_round_trip("max magnitude", kFrame, 1024, {x}, 1);
    x[2] = AAC_ESC_MAX_MAG + 1;
    AacUsacArithContext c;
    aac_usac_arith_reset(&c);
    CHECK(aac_usac_arith_encode(&c, 1, kFrame, 1024, x.data(), nullptr) == AAC_ERR_INVALID_ARG,
          "a magnitude beyond the escape planes is refused");
    CHECK(aac_usac_arith_encode(&c, 1, kFrame, 1023, x.data(), nullptr) == AAC_ERR_INVALID_ARG,
          "an odd lg is refused");
  }
  /* All zero, lg zero, and one line: the stop symbol at the start, the
   * flush alone, and the shortest payload with a sign bit. */
  {
    std::vector<int> zero((size_t)kFrame, 0);
    arith_round_trip("all zero", kFrame, 608, {zero}, 1);
    arith_round_trip("lg zero", kFrame, 0, {zero}, 1);
    std::vector<int> one = zero;
    one[3] = -1;
    arith_round_trip("one line", kFrame, 4, {one}, 1);
    AacUsacArithContext c;
    aac_usac_arith_reset(&c);
    CHECK(aac_usac_arith_encode(&c, 1, kFrame, 0, zero.data(), nullptr) == 2,
          "lg zero is the two flushed bits");
  }
  /* Eight short windows with the context carried across them, after a
   * long window: the context is mapped to the new length. */
  {
    std::vector<std::vector<int>> frame;
    for (int k = 0; k < 8; k++) {
      frame.push_back(random_window(128, 96, &seed, 20, 60 - 5 * k));
    }
    arith_round_trip("short windows", 128, 96, frame, 1);
    arith_round_trip("short windows, no reset", 128, 96, frame, 0);
  }
  /* A sequence of frames without reset: the long window's context, then
   * a short frame, then long again, in one coder. */
  {
    std::vector<uint8_t> buf(65536);
    AacBitWriter w;
    aac_bitwriter_init(&w, buf.data(), (int)buf.size());
    AacUsacArithContext enc;
    AacUsacArithContext dec;
    aac_usac_arith_reset(&enc);
    aac_usac_arith_reset(&dec);
    std::vector<std::vector<int>> windows;
    std::vector<int> lengths;
    std::vector<int> lgs;
    windows.push_back(random_window(kFrame, 1024, &seed, 30, 50));
    lengths.push_back(kFrame);
    lgs.push_back(1024);
    for (int k = 0; k < 8; k++) {
      windows.push_back(random_window(128, 128, &seed, 10, 50));
      lengths.push_back(128);
      lgs.push_back(128);
    }
    windows.push_back(random_window(kFrame, 1024, &seed, 30, 50));
    lengths.push_back(kFrame);
    lgs.push_back(1024);
    std::vector<int> bits;
    for (size_t k = 0; k < windows.size(); k++) {
      bits.push_back(aac_usac_arith_encode(&enc, k == 0, lengths[k], lgs[k], windows[k].data(), &w));
    }
    AacBitReader r;
    aac_bitreader_init(&r, buf.data(), (aac_bitwriter_bits_written(&w) + 7) / 8);
    std::vector<int> x((size_t)kFrame);
    int pos = 0;
    for (size_t k = 0; k < windows.size(); k++) {
      CHECK(aac_usac_arith_decode(&dec, k == 0, lengths[k], lgs[k], &r, x.data()) == AAC_OK &&
                r.pos - pos == bits[k] &&
                memcmp(x.data(), windows[k].data(), (size_t)lengths[k] * sizeof(int)) == 0,
            "mixed lengths: window %zu round trips", k);
      pos = r.pos;
    }
  }
}

/* ── the configuration ─────────────────────────────────────────── */

AacEncoderHandle make_encoder(int channels, int bitrate, const AacUsacEncoderOptions* opt,
                              AacTransport transport = AAC_TRANSPORT_LOAS) {
  AacEncoderHandle enc = aac_encoder_create(kRate, channels, bitrate, AAC_AOT_USAC, AAC_RC_CBR);
  CHECK(enc != nullptr, "encoder for %d channels at %d", channels, bitrate);
  if (!enc) {
    return nullptr;
  }
  if (opt) {
    CHECK(aac_encoder_set_usac(enc, opt) == AAC_OK, "options accepted");
  }
  if (transport != AAC_TRANSPORT_LOAS) {
    CHECK(aac_encoder_set_transport(enc, transport) == AAC_OK, "transport %d", (int)transport);
  }
  return enc;
}

void check_config(int channels, int bitrate, const AacUsacEncoderOptions* opt, const char* name) {
  AacEncoderHandle enc = make_encoder(channels, bitrate, opt);
  if (!enc) {
    return;
  }
  uint8_t asc[AAC_USAC_MAX_CONFIG_BYTES + 16];
  const int len = aac_encoder_config(enc, asc, (int)sizeof(asc));
  CHECK(len > 0, "%s: config of %d bytes", name, len);
  AacAsc parsed;
  memset(&parsed, 0, sizeof(parsed));
  CHECK(aac_asc_parse(asc, len, &parsed) == AAC_OK, "%s: the decoder reads the config", name);
  const AacUsacConfig* c = &parsed.usac;
  CHECK(parsed.aot == AAC_AOT_USAC && c->sample_rate == kRate && c->frame_length == kFrame &&
            c->output_frame_length == kFrame && c->sbr_ratio_index == AAC_USAC_SBR_NONE &&
            c->num_channels == channels && c->channel_config_index == channels,
        "%s: rate, frame and channels", name);
  const int preroll = !opt || opt->preroll;
  const int nf = !opt || opt->noise_filling;
  const int expected = (preroll ? 1 : 0) + 1 + 1;
  CHECK(c->num_elements == expected, "%s: %d elements", name, c->num_elements);
  if (c->num_elements == expected) {
    int i = 0;
    if (preroll) {
      CHECK(c->elem[i].type == AAC_USAC_EXT && c->elem[i].ext_type == AAC_USAC_EXT_AUDIOPREROLL &&
                c->elem[i].ext_default_length == 0 && c->elem[i].ext_payload_frag == 0,
            "%s: the pre-roll element comes first", name);
      i++;
    }
    CHECK(c->elem[i].type == (channels == 2 ? AAC_USAC_CPE : AAC_USAC_SCE) &&
              c->elem[i].noise_filling == nf && c->elem[i].tw_mdct == 0 &&
              c->elem[i].stereo_config_index == 0,
          "%s: the channel element", name);
    i++;
    CHECK(c->elem[i].type == AAC_USAC_EXT && c->elem[i].ext_type == AAC_USAC_EXT_FILL,
          "%s: the fill element comes last", name);
  }
  const int stream_id = opt ? opt->stream_id : -1;
  CHECK(c->stream_id == stream_id, "%s: stream id %d", name, c->stream_id);
  CHECK(c->num_config_exts == (stream_id >= 0 ? 1 : 0), "%s: configuration extensions", name);
  /* The writer reproduces the bits the reader kept. */
  uint8_t again[AAC_USAC_MAX_CONFIG_BYTES];
  memset(again, 0, sizeof(again));
  AacBitWriter w;
  aac_bitwriter_init(&w, again, (int)sizeof(again));
  const int bits = aac_usac_config_write(c, &w);
  CHECK(bits == c->raw_bits, "%s: the writer's %d bits are the reader's %d", name, bits, c->raw_bits);
  aac_bitwriter_byte_align(&w);
  CHECK(memcmp(again, c->raw, (size_t)((c->raw_bits + 7) / 8)) == 0, "%s: same config bytes", name);
  aac_encoder_destroy(enc);
}

void test_config() {
  /* The defaults at the lowest bitrates that keep the plain core. */
  check_config(1, 64000, nullptr, "mono defaults");
  check_config(2, 128000, nullptr, "stereo defaults");
  AacUsacEncoderOptions o = kPlain;
  o.noise_filling = 0;
  o.preroll = 0;
  o.independent_interval_frames = 10;
  o.stream_id = 0x1234;
  check_config(2, 64000, &o, "stereo without pre-roll or noise filling");
  o.noise_filling = 1;
  o.preroll = 1;
  o.stream_id = 0;
  check_config(1, 64000, &o, "mono with stream id 0");
  /* The reader's own structure writes back to the same bits: every
   * element kind the writer covers. */
  {
    AacUsacConfig c;
    memset(&c, 0, sizeof(c));
    c.sample_rate = 44100;
    c.core_sbr_frame_length_index = 1;
    c.channel_config_index = 0;
    c.num_channels = 2;
    c.channel_pos[0] = 0;
    c.channel_pos[1] = 1;
    c.num_elements = 4;
    c.elem[0].type = AAC_USAC_EXT;
    c.elem[0].ext_type = AAC_USAC_EXT_UNI_DRC;
    c.elem[0].ext_default_length = 2;
    c.elem[0].ext_config_len = 3;
    c.elem[0].ext_config[0] = 0x10;
    c.elem[0].ext_config[1] = 0x20;
    c.elem[0].ext_config[2] = 0x30;
    c.elem[1].type = AAC_USAC_SCE;
    c.elem[1].noise_filling = 1;
    c.elem[2].type = AAC_USAC_LFE;
    c.elem[3].type = AAC_USAC_CPE;
    c.num_config_exts = 2;
    c.config_ext[0].type = AAC_USAC_CFG_EXT_STREAM_ID;
    c.config_ext[0].len = 2;
    c.config_ext[0].data[0] = 0xAB;
    c.config_ext[0].data[1] = 0xCD;
    c.config_ext[1].type = 9;
    c.config_ext[1].len = 1;
    c.config_ext[1].data[0] = 0x55;
    uint8_t buf[AAC_USAC_MAX_CONFIG_BYTES];
    memset(buf, 0, sizeof(buf));
    AacBitWriter w;
    aac_bitwriter_init(&w, buf, (int)sizeof(buf));
    const int bits = aac_usac_config_write(&c, &w);
    CHECK(bits > 0, "constructed config writes %d bits", bits);
    AacBitReader r;
    aac_bitreader_init(&r, buf, (bits + 7) / 8);
    AacUsacConfig back;
    memset(&back, 0, sizeof(back));
    const int ret = aac_usac_config_read(&r, &back);
    CHECK(ret == AAC_OK && r.pos == bits, "constructed config reads back (%d, %d bits of %d)", ret,
          r.pos, bits);
    CHECK(back.sample_rate == 44100 && back.channel_config_index == 0 && back.num_channels == 2 &&
              back.num_elements == 4 && back.elem[0].type == AAC_USAC_EXT &&
              back.elem[0].ext_type == AAC_USAC_EXT_UNI_DRC && back.elem[0].ext_default_length == 2 &&
              back.elem[0].ext_config_len == 3 && back.elem[0].ext_config[2] == 0x30 &&
              back.elem[1].type == AAC_USAC_SCE && back.elem[1].noise_filling == 1 &&
              back.elem[2].type == AAC_USAC_LFE && back.elem[3].type == AAC_USAC_CPE &&
              back.elem[3].noise_filling == 0 && back.num_config_exts == 2 &&
              back.stream_id == 0xABCD && back.config_ext[1].type == 9 &&
              back.config_ext[1].len == 1 && back.config_ext[1].data[0] == 0x55,
          "constructed config fields survive");
    uint8_t twice[AAC_USAC_MAX_CONFIG_BYTES];
    memset(twice, 0, sizeof(twice));
    aac_bitwriter_init(&w, twice, (int)sizeof(twice));
    CHECK(aac_usac_config_write(&back, &w) == bits && memcmp(twice, buf, (size_t)((bits + 7) / 8)) == 0,
          "constructed config writes the same bits again");
  }
}

/* ── encode and decode ─────────────────────────────────────────── */

struct Encoded {
  std::vector<uint8_t> stream;        /* LOAS, or the access units back to back */
  std::vector<std::vector<uint8_t>> aus; /* raw transport only */
  int frames = 0;
  int delay = 0;
  int error = 0;
};

Encoded encode(const std::vector<float>& pcm, int channels, int bitrate,
               const AacUsacEncoderOptions* opt, AacTransport transport = AAC_TRANSPORT_LOAS) {
  Encoded e;
  AacEncoderHandle enc = make_encoder(channels, bitrate, opt, transport);
  if (!enc) {
    e.error = AAC_ERR_INIT;
    return e;
  }
  e.delay = aac_encoder_delay(enc);
  const int frame = aac_encoder_frame_size(enc);
  CHECK(frame == kFrame, "frame size %d", frame);
  std::vector<float> block((size_t)frame * channels);
  /* The flush of a stream transport writes the delay's frames in one call. */
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) * 4);
  auto take = [&](int len) {
    if (transport == AAC_TRANSPORT_RAW) {
      e.aus.emplace_back(buf.begin(), buf.begin() + len);
    }
    e.stream.insert(e.stream.end(), buf.begin(), buf.begin() + len);
    e.frames++;
  };
  for (size_t pos = 0; pos < pcm.size() && !e.error; pos += block.size()) {
    std::fill(block.begin(), block.end(), 0.0f);
    const size_t n = std::min(block.size(), pcm.size() - pos);
    memcpy(block.data(), pcm.data() + pos, n * sizeof(float));
    const int len = aac_encoder_encode(enc, block.data(), frame, buf.data(), (int)buf.size());
    if (len < 0) {
      e.error = len;
    } else {
      take(len);
    }
  }
  for (;;) {
    const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
    if (len <= 0) {
      if (len < 0) {
        e.error = len;
      }
      break;
    }
    take(len);
    if (transport == AAC_TRANSPORT_LOAS) {
      break; /* a stream transport drains in one call */
    }
  }
  aac_encoder_destroy(enc);
  return e;
}

struct Decoded {
  std::vector<float> pcm;
  int frames = 0;
  int errors = 0;
  int channels = 0;
};

/* Decodes a LOAS stream whole through the public FIFO, concealment off
 * so that every damaged frame counts. */
Decoded decode_loas(const std::vector<uint8_t>& stream, int channels) {
  Decoded d;
  AacDecoderHandle dec = aac_decoder_create(kRate, channels);
  CHECK(dec != nullptr, "decoder");
  if (!dec) {
    d.errors = -1;
    return d;
  }
  aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
  CHECK(aac_decoder_feed(dec, stream.data(), (int)stream.size()) == (int)stream.size(), "stream fed");
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
    d.channels = aac_decoder_channels(dec);
    d.pcm.insert(d.pcm.end(), out.begin(), out.begin() + (size_t)got * d.channels);
    d.frames++;
  }
  aac_decoder_destroy(dec);
  return d;
}

/* Encodes, decodes, and checks the frame count, the absence of errors
 * and the SNR against the source after the encoder's delay: above a
 * floor, and within a few dB of AAC-LC at the same bitrate, whose
 * analysis and quantisation the xHE-AAC core shares. */
double round_trip(const char* name, const std::vector<float>& pcm, int channels, int bitrate,
                  const AacUsacEncoderOptions* opt, double min_snr) {
  const Encoded e = encode(pcm, channels, bitrate, opt);
  CHECK(e.error == 0, "%s: encodes (%d)", name, e.error);
  CHECK(e.delay == 2 * kFrame, "%s: delay %d", name, e.delay);
  const Decoded d = decode_loas(e.stream, channels);
  const int input_frames = (int)((pcm.size() / (size_t)channels + kFrame - 1) / kFrame);
  CHECK(d.errors == 0 && d.channels == channels && d.frames >= input_frames + 1,
        "%s: %d frames decode without error (%d errors, %d channels)", name, d.frames, d.errors,
        d.channels);
  const double snr = aligned_snr_db(pcm, d.pcm, channels, e.delay);
  CHECK(snr >= min_snr, "%s: %.1f dB against the source (at least %.0f)", name, snr, min_snr);
  int lc_error = 0;
  const std::vector<uint8_t> lc = encode_stream(pcm, kRate, channels, bitrate, AAC_RC_CBR, 0,
                                                &lc_error, AAC_AOT_LC);
  int lc_errors = 0;
  const std::vector<float> lc_pcm = decode_stream(lc, kRate, channels, &lc_errors);
  const double lc_snr = aligned_snr_db(pcm, lc_pcm, channels, e.delay);
  CHECK(lc_error == 0 && lc_errors == 0 && snr >= lc_snr - 2.5,
        "%s: %.1f dB against AAC-LC's %.1f dB", name, snr, lc_snr);
  printf("  %s: %d frames, %zu bytes, %.1f dB (AAC-LC %zu bytes, %.1f dB)\n", name, e.frames,
         e.stream.size(), snr, lc.size(), lc_snr);
  return snr;
}

void test_round_trips() {
  const int n = kRate * 2;
  const std::vector<float> tones_mono = gen_tones(n, kRate, 1);
  const std::vector<float> tones_stereo = gen_tones(n, kRate, 2);
  const std::vector<float> noise_mono = gen_noise(n, 1);
  const std::vector<float> noise_stereo = gen_noise(n, 2, 777u);
  const std::vector<float> he_stereo = gen_he_signal(2);
  round_trip("mono tones 64k", tones_mono, 1, 64000, &kPlain, 30.0);
  round_trip("stereo tones 96k", tones_stereo, 2, 96000, &kPlain, 30.0);
  /* White noise loses what lies above the encoder's lowpass, so its SNR
   * is bounded by the bandwidth rather than the quantisation. */
  round_trip("mono noise 64k", noise_mono, 1, 64000, &kPlain, 2.0);
  round_trip("stereo noise 128k", noise_stereo, 2, 128000, &kPlain, 2.0);
  round_trip("stereo transients 96k", he_stereo, 2, 96000, &kPlain, 18.0);
  /* The same without noise filling and pre-roll, and with independent
   * frames every eight frames. */
  AacUsacEncoderOptions o = kPlain;
  o.noise_filling = 0;
  o.preroll = 0;
  o.independent_interval_frames = 8;
  o.stream_id = 7;
  round_trip("stereo tones 96k, plain", tones_stereo, 2, 96000, &o, 30.0);
  o.noise_filling = 1;
  o.preroll = 1;
  round_trip("stereo transients 96k, frequent pre-roll", he_stereo, 2, 96000, &o, 18.0);
  /* Digital silence, then a tone: silent frames code their lowest band
   * only and decode to silence. */
  {
    std::vector<float> pcm((size_t)kRate * 2 * 2, 0.0f);
    for (int i = kRate; i < kRate * 2; i++) {
      const float v = 0.3f * sinf(2.0f * (float)M_PI * 440.0f * (float)i / (float)kRate);
      pcm[(size_t)i * 2] = v;
      pcm[(size_t)i * 2 + 1] = -v;
    }
    const Encoded e = encode(pcm, 2, 64000, &kPlain);
    const Decoded d = decode_loas(e.stream, 2);
    CHECK(e.error == 0 && d.errors == 0, "silence then tone decodes");
    double silent = 0.0;
    for (size_t i = 0; i < (size_t)(kRate - 2 * kFrame) * 2 && i < d.pcm.size(); i++) {
      silent = std::max(silent, (double)fabsf(d.pcm[i]));
    }
    CHECK(silent < 1e-4, "silence decodes to silence (peak %g)", silent);
    CHECK(aligned_snr_db(pcm, d.pcm, 2, e.delay) > 25.0, "the tone after the silence");
  }
  /* Encoding is deterministic. */
  {
    const Encoded a = encode(he_stereo, 2, 64000, &kPlain);
    const Encoded b = encode(he_stereo, 2, 64000, &kPlain);
    CHECK(a.stream == b.stream, "two encodes of one signal are byte identical");
  }
}

/* Noise filling: at a low bitrate the rate loop leaves the top of the
 * coded range mostly unquantised; with noise filling the decoder keeps
 * that band near the source's level instead of letting it fall away. */
void test_noise_filling() {
  const int n = kRate * 2;
  const std::vector<float> pcm = gen_noise(n, 1);
  AacUsacEncoderOptions off = kPlain;
  off.noise_filling = 0;
  const Encoded with = encode(pcm, 1, 24000, &kPlain);
  const Encoded without = encode(pcm, 1, 24000, &off);
  const Decoded dw = decode_loas(with.stream, 1);
  const Decoded dwo = decode_loas(without.stream, 1);
  CHECK(with.error == 0 && without.error == 0 && dw.errors == 0 && dwo.errors == 0,
        "noise filling streams decode");
  const size_t from = (size_t)with.delay + kFrame;
  const size_t to = from + (size_t)kRate;
  const double src = band_db(pcm, 1, 0, 6000.0f, 8000.0f, kRate, from - (size_t)with.delay,
                             to - (size_t)with.delay);
  const double on = band_db(dw.pcm, 1, 0, 6000.0f, 8000.0f, kRate, from, to);
  const double offdb = band_db(dwo.pcm, 1, 0, 6000.0f, 8000.0f, kRate, from, to);
  printf("  6-8 kHz band at 24 kbit/s: source %.1f dB, noise filling on %.1f dB, off %.1f dB\n", src,
         on, offdb);
  CHECK(on > offdb + 3.0, "noise filling raises the band the rate loop left empty");
  CHECK(fabs(on - src) < 3.0, "the filled band sits near the source's level");
}

/* The bitrate of the raw access units of a stereo signal at each target. */
void test_bitrates() {
  const std::vector<float> pcm = gen_he_signal(2);
  const double seconds = (double)pcm.size() / 2.0 / (double)kRate;
  const int targets[4] = {48000, 64000, 96000, 128000};
  for (int target : targets) {
    const Encoded e = encode(pcm, 2, target, &kPlain, AAC_TRANSPORT_RAW);
    CHECK(e.error == 0 && !e.aus.empty(), "%d: raw access units", target);
    size_t bytes = 0;
    size_t largest = 0;
    for (const std::vector<uint8_t>& au : e.aus) {
      bytes += au.size();
      largest = std::max(largest, au.size());
    }
    /* The flush adds the delay's frames to the signal's; the pre-roll
     * frame carries one access unit twice. */
    const double coded_seconds = (double)e.frames * kFrame / (double)kRate;
    const double rate = (double)bytes * 8.0 / coded_seconds;
    printf("  %d: %zu units, %.1f kbit/s, largest %zu bytes over %.2f s\n", target, e.aus.size(),
           rate / 1000.0, largest, seconds);
    CHECK(rate > 0.85 * target && rate < 1.15 * target, "%d: %.0f bit/s within 15%%", target, rate);
    CHECK(largest <= 2 * 768 * 2 + 64, "%d: largest unit %zu within the budget", target, largest);
    /* Each unit decodes on its own call, after the configuration. */
    AacDecoderHandle dec = aac_decoder_create(kRate, 2);
    AacEncoderHandle enc = make_encoder(2, target, &kPlain, AAC_TRANSPORT_RAW);
    uint8_t asc[64];
    const int asc_len = aac_encoder_config(enc, asc, (int)sizeof(asc));
    aac_encoder_destroy(enc);
    CHECK(dec && asc_len > 0 && aac_decoder_set_config(dec, asc, asc_len) == AAC_OK,
          "%d: raw configuration accepted", target);
    aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
    std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 2);
    std::vector<float> all;
    int errors = 0;
    for (const std::vector<uint8_t>& au : e.aus) {
      const int got = aac_decoder_decode(dec, au.data(), (int)au.size(), out.data(), (int)out.size());
      if (got != kFrame) {
        errors++;
      } else {
        all.insert(all.end(), out.begin(), out.begin() + (size_t)kFrame * 2);
      }
    }
    const double snr = aligned_snr_db(pcm, all, 2, e.delay);
    CHECK(errors == 0 && snr > 8.0, "%d: raw units decode (%d errors, %.1f dB)", target, errors,
          snr);
    aac_decoder_destroy(dec);
  }
}

/* ── the API ───────────────────────────────────────────────────── */

void test_api() {
  AacEncoderHandle enc = aac_encoder_create(kRate, 2, 128000, AAC_AOT_USAC, AAC_RC_CBR);
  CHECK(enc != nullptr, "stereo encoder");
  if (!enc) {
    return;
  }
  CHECK(aac_encoder_set_transport(enc, AAC_TRANSPORT_ADTS) == AAC_ERR_UNSUPPORTED, "ADTS refused");
  CHECK(aac_encoder_set_transport(enc, AAC_TRANSPORT_LATM_MCP1) == AAC_OK, "LATM accepted");
  CHECK(aac_encoder_set_transport(enc, AAC_TRANSPORT_RAW) == AAC_OK, "raw accepted");
  CHECK(aac_encoder_set_transport(enc, AAC_TRANSPORT_LOAS) == AAC_OK, "LOAS accepted");
  CHECK(aac_encoder_set_afterburner(enc, 1) == AAC_ERR_UNSUPPORTED, "afterburner refused");
  CHECK(aac_encoder_set_afterburner(enc, 0) == AAC_OK, "afterburner off accepted");
  CHECK(aac_encoder_set_metadata(enc, AAC_DRC_MUSIC_STANDARD, 100) == AAC_ERR_UNSUPPORTED,
        "AAC DRC metadata refused");
  CHECK(aac_encoder_delay(enc) == 2 * kFrame && aac_encoder_frame_size(enc) == kFrame,
        "delay and frame size");
  CHECK(aac_encoder_set_frame_length(enc, 960) != AAC_OK, "960 frames refused");
  AacUsacEncoderOptions o = kPlain;
  o.independent_interval_frames = 4;
  o.stream_id = 5;
  CHECK(aac_encoder_set_usac(enc, &o) == AAC_OK, "options before the first frame");
  std::vector<float> block((size_t)kFrame * 2, 0.0f);
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) * 4);
  CHECK(aac_encoder_encode(enc, block.data(), kFrame, buf.data(), (int)buf.size()) > 0,
        "a frame encodes");
  CHECK(aac_encoder_set_usac(enc, &o) == AAC_ERR_STATE, "options after the first frame refused");
  CHECK(aac_encoder_set_transport(enc, AAC_TRANSPORT_RAW) == AAC_ERR_STATE,
        "transport after the first frame refused");
  CHECK(aac_encoder_flush(enc, buf.data(), (int)buf.size()) > 0, "flush");
  CHECK(aac_encoder_set_usac(enc, &o) == AAC_OK, "options after a flush");
  aac_encoder_destroy(enc);

  AacEncoderHandle lc = aac_encoder_create(kRate, 2, 128000, AAC_AOT_LC, AAC_RC_CBR);
  CHECK(lc && aac_encoder_set_usac(lc, &o) == AAC_ERR_UNSUPPORTED, "options refused for AAC-LC");
  aac_encoder_destroy(lc);
  CHECK(aac_encoder_create(kRate, 6, 256000, AAC_AOT_USAC, AAC_RC_CBR) == nullptr,
        "six channels refused");
  AacEncoderHandle mono = aac_encoder_create(kRate, 1, 64000, AAC_AOT_USAC, AAC_RC_CBR);
  CHECK(mono != nullptr, "mono encoder");
  aac_encoder_destroy(mono);
}

/* ── the committed streams ─────────────────────────────────────── */

struct Committed {
  const char* name;
  int channels;
  int bitrate;
};
const Committed kCommitted[2] = {{"usac_enc_sce_64k.loas", 1, 64000},
                                 {"usac_enc_cpe_96k.loas", 2, 96000}};

/* The committed streams' signal: the HE-AAC suite's tones, noise and
 * clicks at half level, so that the reference decoders' 16-bit output
 * does not clip on the decoded clicks. */
std::vector<float> committed_signal(int channels) {
  std::vector<float> pcm = gen_he_signal(channels);
  for (float& v : pcm) {
    v *= 0.5f;
  }
  return pcm;
}

int write_streams(const char* dir) {
  for (const Committed& s : kCommitted) {
    const Encoded e = encode(committed_signal(s.channels), s.channels, s.bitrate, &kPlain);
    const std::string path = std::string(dir) + "/" + s.name;
    if (e.error || !write_file(path, e.stream.data(), e.stream.size())) {
      fprintf(stderr, "%s: not written\n", path.c_str());
      return 1;
    }
    printf("%s: %d frames, %zu bytes\n", path.c_str(), e.frames, e.stream.size());
  }
  return 0;
}

/* The streams under tests/data decode without error and match the
 * generator's signal; a fresh encode of that signal is byte identical,
 * so a change of the encoder's output shows up here and the streams are
 * regenerated with --write-streams on purpose.  The streams were written
 * by the release build, whose arithmetic only another build with its
 * optimisation and fast-math flags and without sanitizers reproduces;
 * other builds compare the decodes instead. */
void test_committed() {
  for (const Committed& s : kCommitted) {
    const std::vector<uint8_t> stream = read_file(data_path(s.name));
    CHECK(!stream.empty(), "%s present", s.name);
    if (stream.empty()) {
      continue;
    }
    const std::vector<float> pcm = committed_signal(s.channels);
    const Decoded d = decode_loas(stream, s.channels);
    const double snr = aligned_snr_db(pcm, d.pcm, s.channels, 2 * kFrame);
    CHECK(d.errors == 0 && d.frames > 48 && snr > 18.0, "%s: %d frames, %d errors, %.1f dB", s.name,
          d.frames, d.errors, snr);
    const Encoded e = encode(pcm, s.channels, s.bitrate, &kPlain);
#if defined(__OPTIMIZE__) && defined(__FAST_MATH__) && !BAAC_SANITIZED
    CHECK(e.stream == stream, "%s: a fresh encode is byte identical (%zu vs %zu bytes)", s.name,
          e.stream.size(), stream.size());
#else
    /* Two encodes that differ in a quantised line also differ in every
     * noise filled line after it, so the decodes are compared through
     * the source, not with each other. */
    const Decoded again = decode_loas(e.stream, s.channels);
    const double fresh = aligned_snr_db(pcm, again.pcm, s.channels, 2 * kFrame);
    CHECK(again.errors == 0 && fabs(fresh - snr) < 1.0,
          "%s: a fresh encode of this build decodes like the committed stream (%.1f against "
          "%.1f dB; the byte identity check needs the release build's flags)",
          s.name, fresh, snr);
#endif
  }
}

}  // namespace

int main(int argc, char** argv) {
  aac_tables_init();
  if (argc >= 3 && strcmp(argv[1], "--write-streams") == 0) {
    return write_streams(argv[2]);
  }
  test_arith();
  test_config();
  test_api();
  test_round_trips();
  test_noise_filling();
  test_bitrates();
  test_committed();
  return test_summary("test_usac_enc");
}
