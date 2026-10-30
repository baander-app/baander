/* The xHE-AAC encoder's enhanced SBR and MPEG Surround 2-1-2: the
 * configuration the tools write against the reader, the pre-roll of an
 * SBR stream, encode and decode round trips through the library's own
 * decoder, the bitrate of the raw access units, and the committed
 * streams.
 *
 * With arguments the program writes the committed streams instead:
 *   test_usac_enc_sbr --write-streams <dir>
 */
#include <cmath>
#include <cstdio>
#include <cstring>
#include <string>
#include <vector>

#include "aac.h"
#include "asc.h"
#include "bitstream.h"
#include "encoder.h"
#include "test_util.h"
#include "usac.h"
#include "usac_enc.h"

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
constexpr int kCore = 1024;     /* core samples per frame */
constexpr int kFrame = 2 * kCore; /* output samples per frame with SBR */

/* The defaults with the tools chosen explicitly: -1 leaves each to the
 * bitrate rule. */
AacUsacEncoderOptions options(int sbr, int mps) {
  AacUsacEncoderOptions o;
  o.noise_filling = 1;
  o.preroll = 1;
  o.independent_interval_frames = 0;
  o.stream_id = -1;
  o.sbr = sbr;
  o.mps212 = mps;
  return o;
}

AacEncoderHandle make_encoder(int rate, int channels, int bitrate, const AacUsacEncoderOptions* opt,
                              AacTransport transport = AAC_TRANSPORT_LOAS) {
  AacEncoderHandle enc = aac_encoder_create(rate, channels, bitrate, AAC_AOT_USAC, AAC_RC_CBR);
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

/* ── the configuration ─────────────────────────────────────────── */

struct Expected {
  int sbr;  /* the 2:1 ratio */
  int mps;  /* stereoConfigIndex 1 */
};

/* Reads the encoder's configuration back through the decoder's parser
 * and checks the tools, the element layout, and that the writer
 * reproduces the bits the reader kept. */
void check_config(int rate, int channels, int bitrate, const AacUsacEncoderOptions* opt,
                  Expected want, const char* name) {
  AacEncoderHandle enc = make_encoder(rate, channels, bitrate, opt);
  if (!enc) {
    return;
  }
  CHECK(aac_encoder_frame_size(enc) == (want.sbr ? kFrame : kCore) &&
            aac_encoder_delay(enc) == (want.sbr ? AAC_ENCODER_SBR_DELAY : AAC_ENCODER_DELAY),
        "%s: frame size %d and delay %d", name, aac_encoder_frame_size(enc), aac_encoder_delay(enc));
  uint8_t asc[AAC_USAC_MAX_CONFIG_BYTES + 16];
  const int len = aac_encoder_config(enc, asc, (int)sizeof(asc));
  CHECK(len > 0, "%s: config of %d bytes", name, len);
  AacAsc parsed;
  memset(&parsed, 0, sizeof(parsed));
  CHECK(aac_asc_parse(asc, len, &parsed) == AAC_OK, "%s: the decoder reads the config", name);
  const AacUsacConfig* c = &parsed.usac;
  CHECK(parsed.aot == AAC_AOT_USAC && parsed.sample_rate == rate && parsed.channel_config == channels,
        "%s: the outer configuration carries the output rate and channels", name);
  if (want.sbr) {
    CHECK(c->sample_rate == rate && c->core_sbr_frame_length_index == 3 &&
              c->sbr_ratio_index == AAC_USAC_SBR_2_1 && c->frame_length == kCore &&
              c->output_frame_length == kFrame && c->core_sample_rate == rate / 2,
          "%s: the 2:1 ratio with a 1024-line core", name);
  } else {
    CHECK(c->sample_rate == rate && c->core_sbr_frame_length_index == 1 &&
              c->sbr_ratio_index == AAC_USAC_SBR_NONE && c->output_frame_length == kCore,
          "%s: no SBR", name);
  }
  CHECK(c->num_channels == channels && c->channel_config_index == channels, "%s: channels", name);
  CHECK(c->num_elements == 3, "%s: %d elements", name, c->num_elements);
  if (c->num_elements == 3) {
    const AacUsacElementConfig* e = &c->elem[1];
    CHECK(c->elem[0].type == AAC_USAC_EXT && c->elem[0].ext_type == AAC_USAC_EXT_AUDIOPREROLL &&
              c->elem[2].type == AAC_USAC_EXT && c->elem[2].ext_type == AAC_USAC_EXT_FILL,
          "%s: pre-roll first, fill last", name);
    CHECK(e->type == (channels == 2 ? AAC_USAC_CPE : AAC_USAC_SCE) && e->noise_filling == 1 &&
              e->tw_mdct == 0,
          "%s: the channel element", name);
    CHECK(e->stereo_config_index == (want.mps ? 1 : 0), "%s: stereoConfigIndex %d", name,
          e->stereo_config_index);
    if (want.sbr) {
      const AacUsacSbrHeader* h = &e->sbr.dflt;
      CHECK(e->sbr.harmonic_sbr == 0 && e->sbr.inter_tes == 0 && e->sbr.pvc == 0,
            "%s: the SBR tools beyond the LPC transposer are off", name);
      CHECK(h->start_freq >= 0 && h->start_freq <= 15 && h->stop_freq >= 0 && h->stop_freq <= 13 &&
                h->freq_scale >= 0 && h->freq_scale <= 3 && h->noise_bands >= 0 &&
                h->noise_bands <= 3 && h->limiter_bands >= 0 && h->limiter_bands <= 3 &&
                h->limiter_gains >= 0 && h->limiter_gains <= 3,
            "%s: default header in range (start %d, stop %d)", name, h->start_freq, h->stop_freq);
      const int extra1 = h->freq_scale != 2 || h->alter_scale != 1 || h->noise_bands != 2;
      const int extra2 = h->limiter_bands != 2 || h->limiter_gains != 2 || h->interpol_freq != 1 ||
                         h->smoothing_mode != 1;
      CHECK(h->header_extra1 == extra1 && h->header_extra2 == extra2,
            "%s: the header's extra fields are sent only when they differ from the defaults",
            name);
    }
    if (want.mps) {
      const AacUsacMps212Config* m = &e->mps;
      CHECK(m->freq_res == 2 && m->fixed_gain_dmx == 3 && m->temp_shape_config == 0 &&
                m->decorr_config == 0 && m->high_rate_mode == 0 && m->phase_coding == 0 &&
                m->ott_bands_phase_present == 0 && m->residual_coding == 0,
            "%s: the 2-1-2 configuration", name);
    }
  }
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
  /* The bitrate rule: SBR below 64 kbit/s per channel; the 2-1-2 only
   * when asked for. */
  check_config(kRate, 1, 32000, nullptr, {1, 0}, "mono 32k");
  check_config(kRate, 1, 63000, nullptr, {1, 0}, "mono 63k");
  check_config(kRate, 1, 64000, nullptr, {0, 0}, "mono 64k");
  check_config(kRate, 2, 24000, nullptr, {1, 0}, "stereo 24k");
  check_config(kRate, 2, 32000, nullptr, {1, 0}, "stereo 32k");
  check_config(kRate, 2, 48000, nullptr, {1, 0}, "stereo 48k");
  check_config(kRate, 2, 64000, nullptr, {1, 0}, "stereo 64k");
  check_config(kRate, 2, 96000, nullptr, {1, 0}, "stereo 96k");
  check_config(kRate, 2, 128000, nullptr, {0, 0}, "stereo 128k");
  check_config(44100, 2, 32000, nullptr, {1, 0}, "stereo 32k at 44.1 kHz");
  /* The options override the rule; the 2-1-2 brings SBR with it. */
  AacUsacEncoderOptions o = options(0, 0);
  check_config(kRate, 1, 32000, &o, {0, 0}, "mono 32k without SBR");
  check_config(kRate, 2, 32000, &o, {0, 0}, "stereo 32k without either");
  o = options(-1, 0);
  check_config(kRate, 2, 32000, &o, {1, 0}, "stereo 32k without the 2-1-2");
  o = options(1, -1);
  check_config(kRate, 2, 128000, &o, {1, 0}, "stereo 128k with SBR");
  o = options(-1, 1);
  check_config(kRate, 2, 96000, &o, {1, 1}, "stereo 96k with the 2-1-2");
  /* A rate whose half the configuration cannot carry stays with the
   * plain core. */
  check_config(8000, 1, 16000, nullptr, {0, 0}, "mono 16k at 8 kHz");
}

/* stereoConfigIndex of the channel pair in the encoder's configuration,
 * or -1 when the configuration does not parse. */
int stereo_config_index(AacEncoderHandle enc) {
  uint8_t asc[AAC_USAC_MAX_CONFIG_BYTES + 16];
  const int len = aac_encoder_config(enc, asc, (int)sizeof(asc));
  AacAsc parsed;
  memset(&parsed, 0, sizeof(parsed));
  if (len <= 0 || aac_asc_parse(asc, len, &parsed) != AAC_OK || parsed.usac.num_elements != 3) {
    return -1;
  }
  return parsed.usac.elem[1].stereo_config_index;
}

/* The 2-1-2 with SBR turned off is refused, and the options the encoder
 * had stay in force. */
void test_refused_options() {
  AacUsacEncoderOptions o = options(-1, 1);
  AacEncoderHandle enc = make_encoder(kRate, 2, 32000, &o);
  if (!enc) {
    return;
  }
  CHECK(stereo_config_index(enc) == 1 && aac_encoder_frame_size(enc) == kFrame,
        "the 2-1-2 with SBR by bitrate is on");
  o = options(0, 1);
  CHECK(aac_encoder_set_usac(enc, &o) == AAC_ERR_INVALID_ARG, "the 2-1-2 without SBR is refused");
  CHECK(stereo_config_index(enc) == 1 && aac_encoder_frame_size(enc) == kFrame,
        "the refused options left the accepted ones in force");
  o = options(0, 0);
  CHECK(aac_encoder_set_usac(enc, &o) == AAC_OK && stereo_config_index(enc) == 0 &&
            aac_encoder_frame_size(enc) == kCore,
        "both tools off is accepted");
  o = options(1, 1);
  CHECK(aac_encoder_set_usac(enc, &o) == AAC_OK && stereo_config_index(enc) == 1 &&
            aac_encoder_frame_size(enc) == kFrame,
        "the 2-1-2 with SBR on is accepted");
  aac_encoder_destroy(enc);
}

/* ── encode and decode ─────────────────────────────────────────── */

struct Encoded {
  std::vector<uint8_t> stream;           /* LOAS, or the access units back to back */
  std::vector<std::vector<uint8_t>> aus; /* raw transport only */
  int frames = 0;
  int frame_size = 0;
  int delay = 0;
  int error = 0;
};

Encoded encode(const std::vector<float>& pcm, int channels, int bitrate,
               const AacUsacEncoderOptions* opt, AacTransport transport = AAC_TRANSPORT_LOAS) {
  Encoded e;
  AacEncoderHandle enc = make_encoder(kRate, channels, bitrate, opt, transport);
  if (!enc) {
    e.error = AAC_ERR_INIT;
    return e;
  }
  e.delay = aac_encoder_delay(enc);
  e.frame_size = aac_encoder_frame_size(enc);
  std::vector<float> block((size_t)e.frame_size * channels);
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
    const int len = aac_encoder_encode(enc, block.data(), e.frame_size, buf.data(), (int)buf.size());
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
  int nonfinite = 0;
};

/* Decodes a LOAS stream whole through the public FIFO, concealment off
 * so that every damaged frame counts, and counts the samples that are
 * not finite. */
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
    for (size_t i = 0; i < (size_t)got * d.channels; i++) {
      if (!std::isfinite(out[i])) {
        d.nonfinite++;
      }
    }
    d.pcm.insert(d.pcm.end(), out.begin(), out.begin() + (size_t)got * d.channels);
    d.frames++;
  }
  aac_decoder_destroy(dec);
  return d;
}

/* The pre-roll of the first access unit of a raw stream: whether the
 * extension element is present, and how many frames it carries. */
int preroll_frames(const std::vector<uint8_t>& au, int* present) {
  AacBitReader r;
  aac_bitreader_init(&r, au.data(), (int)au.size());
  aac_bitreader_read(&r, 1); /* usacIndependencyFlag */
  *present = (int)aac_bitreader_read(&r, 1);
  if (!*present) {
    return 0;
  }
  int len;
  if (aac_bitreader_read(&r, 1)) { /* usacExtElementUseDefaultLength */
    return -1;
  }
  len = aac_usac_ext_payload_length(&r);
  if (len <= 0 || len > AAC_USAC_MAX_EXT_PAYLOAD || aac_bitreader_bits_left(&r) < 8 * len) {
    return -1;
  }
  std::vector<uint8_t> payload((size_t)len);
  for (int i = 0; i < len; i++) {
    payload[(size_t)i] = (uint8_t)aac_bitreader_read(&r, 8);
  }
  static AacUsacPreroll p;
  memset(&p, 0, sizeof(p));
  if (aac_usac_preroll_parse(payload.data(), len, &p) != AAC_OK) {
    return -1;
  }
  return p.num_frames;
}

void test_preroll() {
  const std::vector<float> pcm = gen_he_signal(1);
  /* An SBR stream pre-rolls two frames, so that the decoder's SBR has
   * its history at the frame it starts on; the plain core one. */
  {
    const Encoded e = encode(pcm, 1, 32000, nullptr, AAC_TRANSPORT_RAW);
    int present = 0;
    const int n = e.aus.empty() ? -1 : preroll_frames(e.aus[0], &present);
    CHECK(e.error == 0 && present && n == AAC_USAC_ENC_PREROLL_FRAMES_SBR,
          "SBR stream: %d pre-roll frames", n);
    /* the configuration in the pre-roll is the stream's */
    AacEncoderHandle enc = make_encoder(kRate, 1, 32000, nullptr, AAC_TRANSPORT_RAW);
    uint8_t asc[64];
    const int asc_len = aac_encoder_config(enc, asc, (int)sizeof(asc));
    aac_encoder_destroy(enc);
    AacDecoderHandle dec = aac_decoder_create(kRate, 1);
    CHECK(dec && asc_len > 0 && aac_decoder_set_config(dec, asc, asc_len) == AAC_OK,
          "raw configuration accepted");
    aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
    std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 2);
    std::vector<float> all;
    int errors = 0;
    int total = 0;
    for (const std::vector<uint8_t>& au : e.aus) {
      const int got = aac_decoder_decode(dec, au.data(), (int)au.size(), out.data(), (int)out.size());
      if (got <= 0) {
        errors++;
      } else {
        total += got;
        all.insert(all.end(), out.begin(), out.begin() + (size_t)got);
      }
    }
    aac_decoder_destroy(dec);
    const double snr = aligned_snr_db(pcm, all, 1, e.delay);
    CHECK(errors == 0 && total == e.frames * kFrame && snr > 12.0,
          "raw units decode (%d errors, %d samples, %.1f dB)", errors, total, snr);
  }
  {
    const AacUsacEncoderOptions o = options(0, 0);
    const Encoded e = encode(pcm, 1, 32000, &o, AAC_TRANSPORT_RAW);
    int present = 0;
    const int n = e.aus.empty() ? -1 : preroll_frames(e.aus[0], &present);
    CHECK(e.error == 0 && present && n == AAC_USAC_ENC_PREROLL_FRAMES,
          "plain stream: %d pre-roll frames", n);
  }
  {
    AacUsacEncoderOptions o = options(-1, -1);
    o.preroll = 0;
    const Encoded e = encode(pcm, 1, 32000, &o, AAC_TRANSPORT_RAW);
    int present = 1;
    const int n = e.aus.empty() ? -1 : preroll_frames(e.aus[0], &present);
    CHECK(e.error == 0 && !present && n == 0, "without pre-roll the element is absent");
  }
}

/* Encodes, decodes, and checks the frame count, the absence of errors
 * and of non-finite samples, and the SNR against the source after the
 * encoder's delay. */
double round_trip(const char* name, const std::vector<float>& pcm, int channels, int bitrate,
                  const AacUsacEncoderOptions* opt, double min_snr, Decoded* out = nullptr) {
  const Encoded e = encode(pcm, channels, bitrate, opt);
  CHECK(e.error == 0, "%s: encodes (%d)", name, e.error);
  CHECK(e.delay == AAC_ENCODER_SBR_DELAY && e.frame_size == kFrame, "%s: delay %d, frame %d",
        name, e.delay, e.frame_size);
  const Decoded d = decode_loas(e.stream, channels);
  const int input_frames = (int)((pcm.size() / (size_t)channels + kFrame - 1) / kFrame);
  CHECK(d.errors == 0 && d.nonfinite == 0 && d.channels == channels &&
            d.frames >= input_frames + 1,
        "%s: %d frames decode without error (%d errors, %d not finite, %d channels)", name,
        d.frames, d.errors, d.nonfinite, d.channels);
  const double snr = aligned_snr_db(pcm, d.pcm, channels, e.delay);
  CHECK(snr >= min_snr, "%s: %.1f dB against the source (at least %.0f)", name, snr, min_snr);
  printf("  %s: %d frames, %zu bytes, %.1f dB\n", name, e.frames, e.stream.size(), snr);
  if (out) {
    *out = d;
  }
  return snr;
}

/* The source and the decode in a band, over a second from the frame
 * after the delay. */
double band_gap_db(const std::vector<float>& pcm, const Decoded& d, int channels, int delay,
                   float lo, float hi) {
  const size_t from = (size_t)delay + kFrame;
  const size_t to = from + (size_t)kRate / 2;
  const double src = band_db(pcm, channels, 0, lo, hi, kRate, from - (size_t)delay, to - (size_t)delay);
  const double dec = band_db(d.pcm, channels, 0, lo, hi, kRate, from, to);
  return dec - src;
}

void test_round_trips() {
  const int n = kRate;
  const std::vector<float> he_mono = gen_he_signal(1);
  const std::vector<float> he_stereo = gen_he_signal(2);
  /* Tones within the core's half of the band: coded as waveforms. */
  std::vector<float> tones_mono((size_t)n);
  for (int i = 0; i < n; i++) {
    const float t = (float)i / (float)kRate;
    tones_mono[(size_t)i] = 0.3f * sinf(2.0f * (float)M_PI * 440.0f * t) +
                            0.15f * sinf(2.0f * (float)M_PI * 3000.0f * t);
  }
  /* Mono at 32 kbit/s: the core codes the lower half, SBR the band
   * above, which stays near the source's level. */
  {
    Decoded d;
    round_trip("mono 32k SBR", he_mono, 1, 32000, nullptr, 14.0, &d);
    const double low = band_gap_db(he_mono, d, 1, AAC_ENCODER_SBR_DELAY, 10000.0f, 14000.0f);
    const double high = band_gap_db(he_mono, d, 1, AAC_ENCODER_SBR_DELAY, 14000.0f, 20000.0f);
    printf("  replicated bands: 10-14 kHz %+.1f dB, 14-20 kHz %+.1f dB from the source\n", low,
           high);
    CHECK(fabs(low) < 6.0 && fabs(high) < 8.0, "the replicated bands sit near the source's level");
  }
  round_trip("mono tones 32k SBR", tones_mono, 1, 32000, nullptr, 25.0);
  /* The suite's tones reach 9 kHz, which the replicated band carries
   * without its waveform. */
  round_trip("mono tones to 9 kHz 32k SBR", gen_tones(n, kRate, 1), 1, 32000, nullptr, 8.0);
  round_trip("mono 16k SBR", he_mono, 1, 16000, nullptr, 8.0);
  round_trip("stereo 64k SBR", he_stereo, 2, 64000, nullptr, 14.0);
  /* The 2-1-2 on a panned pair: both channels come back from the one
   * coded channel and the level differences. */
  {
    std::vector<float> panned((size_t)n * 2);
    for (int i = 0; i < n; i++) {
      panned[(size_t)i * 2] = he_mono[(size_t)i];
      panned[(size_t)i * 2 + 1] = 0.5f * he_mono[(size_t)i];
    }
    AacUsacEncoderOptions o = options(-1, 1);
    Decoded d;
    round_trip("stereo 32k 2-1-2, panned", panned, 2, 32000, &o, 10.0, &d);
    const size_t skip = (size_t)AAC_ENCODER_SBR_DELAY * 2;
    const size_t m = std::min(panned.size(), d.pcm.size() - skip) / 2;
    double el = 0.0, er = 0.0, sl = 0.0, sr = 0.0;
    for (size_t i = 0; i < m; i++) {
      el += (double)d.pcm[skip + i * 2] * d.pcm[skip + i * 2];
      er += (double)d.pcm[skip + i * 2 + 1] * d.pcm[skip + i * 2 + 1];
      sl += (double)panned[i * 2] * panned[i * 2];
      sr += (double)panned[i * 2 + 1] * panned[i * 2 + 1];
    }
    const double level_l = 10.0 * log10(el / sl);
    const double level_r = 10.0 * log10(er / sr);
    printf("  2-1-2 levels: left %+.1f dB, right %+.1f dB from the source\n", level_l, level_r);
    CHECK(fabs(level_l) < 1.5 && fabs(level_r) < 1.5, "the 2-1-2 keeps each channel's level");
  }
  /* Different content in each channel: the downmix is what the core
   * codes, so the sum of the channels is what comes back faithfully. */
  {
    Decoded d;
    const AacUsacEncoderOptions mps = options(-1, 1);
    const Encoded e = encode(he_stereo, 2, 24000, &mps);
    d = decode_loas(e.stream, 2);
    CHECK(e.error == 0 && d.errors == 0 && d.nonfinite == 0, "stereo 24k 2-1-2 decodes");
    std::vector<float> sum_src(he_stereo.size() / 2);
    std::vector<float> sum_dec(d.pcm.size() / 2);
    for (size_t i = 0; i < sum_src.size(); i++) {
      sum_src[i] = he_stereo[i * 2] + he_stereo[i * 2 + 1];
    }
    for (size_t i = 0; i < sum_dec.size(); i++) {
      sum_dec[i] = d.pcm[i * 2] + d.pcm[i * 2 + 1];
    }
    const double snr = aligned_snr_db(sum_src, sum_dec, 1, e.delay);
    printf("  stereo 24k 2-1-2: %zu bytes, the channels' sum at %.1f dB\n", e.stream.size(), snr);
    CHECK(snr > 10.0, "the sum of the channels survives the 2-1-2");
  }
  /* Frequent independent frames: every header and parameter set coded
   * afresh, the same result. */
  {
    AacUsacEncoderOptions o = options(-1, 1);
    o.independent_interval_frames = 3;
    const AacUsacEncoderOptions mps = options(-1, 1);
    const double reference = round_trip("stereo 32k 2-1-2", he_stereo, 2, 32000, &mps, -10.0);
    const double frequent =
        round_trip("stereo 32k 2-1-2, independent every 3", he_stereo, 2, 32000, &o, -10.0);
    CHECK(fabs(reference - frequent) < 1.5, "independent frames every 3 decode alike (%.1f, %.1f)",
          reference, frequent);
  }
  /* Digital silence, then a tone: the silent frames and the pre-roll's
   * silent units decode to silence with every sample finite. */
  {
    std::vector<float> pcm((size_t)kRate * 2, 0.0f);
    for (int i = kRate; i < kRate * 2; i++) {
      pcm[(size_t)i] = 0.3f * sinf(2.0f * (float)M_PI * 1000.0f * (float)i / (float)kRate);
    }
    const Encoded e = encode(pcm, 1, 32000, nullptr);
    const Decoded d = decode_loas(e.stream, 1);
    CHECK(e.error == 0 && d.errors == 0 && d.nonfinite == 0, "silence then tone decodes");
    double silent = 0.0;
    for (size_t i = 0; i < (size_t)(kRate - kFrame) && i < d.pcm.size(); i++) {
      silent = std::max(silent, (double)fabsf(d.pcm[i]));
    }
    CHECK(silent < 1e-4, "silence decodes to silence (peak %g)", silent);
    CHECK(aligned_snr_db(pcm, d.pcm, 1, e.delay) > 15.0, "the tone after the silence");
  }
  /* Encoding is deterministic. */
  {
    const Encoded a = encode(he_stereo, 2, 32000, nullptr);
    const Encoded b = encode(he_stereo, 2, 32000, nullptr);
    CHECK(a.stream == b.stream, "two encodes of one signal are byte identical");
  }
}

/* The bitrate of the raw access units at each target of the ladders. */
void test_bitrates() {
  struct Point {
    int channels;
    int bitrate;
  };
  const Point points[7] = {{2, 24000}, {2, 32000}, {2, 48000}, {2, 64000},
                           {2, 96000}, {1, 16000}, {1, 32000}};
  const std::vector<float> stereo = gen_he_signal(2);
  const std::vector<float> mono = gen_he_signal(1);
  for (const Point& p : points) {
    const Encoded e = encode(p.channels == 2 ? stereo : mono, p.channels, p.bitrate, nullptr,
                             AAC_TRANSPORT_RAW);
    CHECK(e.error == 0 && !e.aus.empty() && e.frame_size == kFrame, "%d ch %d: raw access units",
          p.channels, p.bitrate);
    size_t bytes = 0;
    size_t largest = 0;
    for (const std::vector<uint8_t>& au : e.aus) {
      bytes += au.size();
      largest = std::max(largest, au.size());
    }
    const double coded_seconds = (double)e.frames * kFrame / (double)kRate;
    const double rate = (double)bytes * 8.0 / coded_seconds;
    printf("  %d ch %d: %zu units, %.1f kbit/s, largest %zu bytes\n", p.channels, p.bitrate,
           e.aus.size(), rate / 1000.0, largest);
    CHECK(rate > 0.85 * p.bitrate && rate < 1.15 * p.bitrate, "%d ch %d: %.0f bit/s within 15%%",
          p.channels, p.bitrate, rate);
    CHECK(largest <= (size_t)AAC_USAC_ENC_MAX_IPF_BYTES(p.channels),
          "%d ch %d: largest unit %zu within the budget", p.channels, p.bitrate, largest);
  }
}

/* ── a flushed encoder starts over ─────────────────────────────── */

/* Encodes `pcm` and flushes, on the same encoder twice; both streams
 * must be the one a fresh encoder writes.  The MPS 212 and the
 * parametric stereo analyses read the left channel's filterbank ring
 * around the frame, so a ring that kept the previous stream's slots
 * would show in the first frames after the flush. */
void check_restart(AacEncoderHandle enc, const std::vector<float>& pcm, int channels,
                   const char* name) {
  if (!enc) {
    return;
  }
  const int frame = aac_encoder_frame_size(enc);
  std::vector<float> block((size_t)frame * channels);
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) * 4);
  std::vector<uint8_t> streams[2];
  for (int pass = 0; pass < 2; pass++) {
    int error = 0;
    for (size_t pos = 0; pos < pcm.size(); pos += block.size()) {
      std::fill(block.begin(), block.end(), 0.0f);
      const size_t n = std::min(block.size(), pcm.size() - pos);
      memcpy(block.data(), pcm.data() + pos, n * sizeof(float));
      const int len = aac_encoder_encode(enc, block.data(), frame, buf.data(), (int)buf.size());
      if (len < 0) {
        error = len;
        break;
      }
      streams[pass].insert(streams[pass].end(), buf.begin(), buf.begin() + len);
    }
    const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
    CHECK(!error && len > 0, "%s: pass %d encodes and flushes (%d, %d)", name, pass, error, len);
    if (len > 0) {
      streams[pass].insert(streams[pass].end(), buf.begin(), buf.begin() + len);
    }
  }
  CHECK(streams[0] == streams[1],
        "%s: the stream after a flush is the fresh encoder's (%zu against %zu bytes)", name,
        streams[1].size(), streams[0].size());
  aac_encoder_destroy(enc);
}

void test_restart() {
  const std::vector<float> pcm = gen_he_signal(2);
  const AacUsacEncoderOptions mps = options(-1, 1);
  check_restart(make_encoder(kRate, 2, 32000, &mps), pcm, 2, "stereo 32k with the 2-1-2");
  const AacUsacEncoderOptions sbr = options(1, 0);
  check_restart(make_encoder(kRate, 2, 48000, &sbr), pcm, 2, "stereo 48k with SBR");
  /* HE-AAC v2 shares the ring with its parametric stereo analysis. */
  AacEncoderHandle ps = aac_encoder_create(kRate, 2, 32000, AAC_AOT_PS, AAC_RC_CBR);
  CHECK(ps != nullptr, "HE-AAC v2 encoder");
  check_restart(ps, pcm, 2, "HE-AAC v2 at 32k");
}

/* ── the committed streams ─────────────────────────────────────── */

struct Committed {
  const char* name;
  int channels;
  int bitrate;
  int mps;
};
const Committed kCommitted[3] = {{"usac_enc_sbr_sce_32k.loas", 1, 32000, 0},
                                 {"usac_enc_sbr_cpe_64k.loas", 2, 64000, 0},
                                 {"usac_enc_mps_cpe_32k.loas", 2, 32000, 1}};

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
    const AacUsacEncoderOptions o = options(-1, s.mps ? 1 : -1);
    const Encoded e = encode(committed_signal(s.channels), s.channels, s.bitrate, &o);
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
    const double snr = aligned_snr_db(pcm, d.pcm, s.channels, AAC_ENCODER_SBR_DELAY);
    const double floor = s.mps ? -5.0 : 12.0;
    CHECK(d.errors == 0 && d.nonfinite == 0 && d.frames > 24 && snr > floor,
          "%s: %d frames, %d errors, %.1f dB", s.name, d.frames, d.errors, snr);
    const AacUsacEncoderOptions o = options(-1, s.mps ? 1 : -1);
    const Encoded e = encode(pcm, s.channels, s.bitrate, &o);
#if defined(__OPTIMIZE__) && defined(__FAST_MATH__) && !BAAC_SANITIZED
    CHECK(e.stream == stream, "%s: a fresh encode is byte identical (%zu vs %zu bytes)", s.name,
          e.stream.size(), stream.size());
#else
    /* Two encodes that differ in a quantised line also differ in every
     * noise filled line after it, so the decodes are compared through
     * the source, not with each other. */
    const Decoded again = decode_loas(e.stream, s.channels);
    const double fresh = aligned_snr_db(pcm, again.pcm, s.channels, AAC_ENCODER_SBR_DELAY);
    CHECK(again.errors == 0 && fabs(fresh - snr) < 1.5,
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
  test_config();
  test_refused_options();
  test_preroll();
  test_round_trips();
  test_restart();
  test_bitrates();
  test_committed();
  return test_summary("test_usac_enc_sbr");
}
