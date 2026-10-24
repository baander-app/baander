/* AAC-ELD with low-delay SBR: encode/decode roundtrips at 48 kHz in dual
 * and single rate, 512 and 480 frames, mono and stereo, over LOAS and raw
 * access units with the AudioSpecificConfig; the reported delay, frame
 * size and output rate; the SBR CRC; concealment of a damaged unit;
 * the config's round trip with its headers; the settings of the public
 * API; and the byte identity of plain ELD streams with the committed
 * references.  FFmpeg has no ELD decoder, so there is no interop. */
#include "asc.h"
#include "encoder.h"
#include "qmf_ld.h"
#include "sbr_ld_enc.h"
#include "test_util.h"

struct Encoded {
  std::vector<uint8_t> stream;           /* LOAS, or the access units back to back */
  std::vector<std::vector<uint8_t>> aus; /* raw access units */
  std::vector<uint8_t> asc;
  int delay = 0;
  int frame = 0;
};

struct Decoded {
  std::vector<float> out;
  int errors = 0;
  int concealed = 0;
  int has_sbr = 0;
  int frame_size = 0;
  int out_rate = 0;
  int core_rate = 0;
  int object_type = 0;
};

static Encoded encode(const std::vector<float>& pcm, int sr, int channels, int bitrate, int fl,
                      int ld_sbr, int dual_rate, AacTransport transport, int crc = 0) {
  Encoded e;
  AacEncoderHandle enc = aac_encoder_create(sr, channels, bitrate, AAC_AOT_ER_ELD, AAC_RC_CBR);
  CHECK(enc != nullptr, "create ELD at %d Hz", sr);
  if (!enc) {
    return e;
  }
  CHECK(aac_encoder_set_frame_length(enc, fl) == AAC_OK, "frame length %d", fl);
  CHECK(aac_encoder_set_transport(enc, transport) == AAC_OK, "transport %d", transport);
  if (ld_sbr) {
    CHECK(aac_encoder_set_ld_sbr(enc, 1, dual_rate) == AAC_OK, "LD-SBR dual %d", dual_rate);
    /* The CRC has no public switch: the stream setting is set directly. */
    static_cast<AacEncoderState*>(enc)->ld_sbr_crc = crc;
  }
  uint8_t asc[64];
  const int asc_len = aac_encoder_config(enc, asc, sizeof(asc));
  CHECK(asc_len > 0, "config %d", asc_len);
  e.asc.assign(asc, asc + std::max(asc_len, 0));
  e.delay = aac_encoder_delay(enc);
  e.frame = aac_encoder_frame_size(enc);
  std::vector<float> block((size_t)e.frame * channels);
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) * 4);
  const bool packet = transport == AAC_TRANSPORT_RAW;
  for (size_t pos = 0; pos < pcm.size(); pos += block.size()) {
    std::fill(block.begin(), block.end(), 0.0f);
    memcpy(block.data(), pcm.data() + pos,
           std::min(block.size(), pcm.size() - pos) * sizeof(float));
    const int len = aac_encoder_encode(enc, block.data(), e.frame, buf.data(), (int)buf.size());
    CHECK(len > 0, "encode %d", len);
    if (len > 0) {
      e.stream.insert(e.stream.end(), buf.begin(), buf.begin() + len);
      if (packet) {
        e.aus.emplace_back(buf.begin(), buf.begin() + len);
      }
    }
  }
  for (;;) {
    const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
    if (len <= 0) {
      CHECK(len == 0, "flush %d", len);
      break;
    }
    e.stream.insert(e.stream.end(), buf.begin(), buf.begin() + len);
    if (packet) {
      e.aus.emplace_back(buf.begin(), buf.begin() + len);
    } else {
      break;
    }
  }
  aac_encoder_destroy(enc);
  return e;
}

/* Decodes LOAS by feeding it in chunks with transport detection, or raw
 * access units after aac_decoder_set_config. */
static Decoded decode(const Encoded& e, int channels) {
  Decoded d;
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  AacDecoderHandle dec = aac_decoder_create(48000, channels);
  auto take = [&](int got) {
    if (got < 0) {
      d.errors++;
      return;
    }
    d.out.insert(d.out.end(), pcm.begin(), pcm.begin() + (size_t)got * aac_decoder_channels(dec));
    d.concealed += aac_decoder_last_frame_concealed(dec);
    int has_sbr = 0;
    aac_decoder_get_sbr_ps(dec, &has_sbr, nullptr);
    d.has_sbr += has_sbr;
  };
  if (!e.aus.empty()) {
    if (aac_decoder_set_config(dec, e.asc.data(), (int)e.asc.size()) != AAC_OK) {
      d.errors++;
    }
    for (const std::vector<uint8_t>& au : e.aus) {
      take(aac_decoder_decode(dec, au.data(), (int)au.size(), pcm.data(), (int)pcm.size()));
    }
  } else {
    for (size_t pos = 0; pos < e.stream.size();) {
      const int taken = aac_decoder_feed(dec, e.stream.data() + pos,
                                         (int)std::min((size_t)700, e.stream.size() - pos));
      if (taken <= 0) {
        d.errors++;
        break;
      }
      pos += (size_t)taken;
      for (;;) {
        const int got = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
        if (got == 0) {
          break;
        }
        take(got);
      }
    }
  }
  d.frame_size = aac_decoder_frame_size(dec);
  d.out_rate = aac_decoder_output_sample_rate(dec);
  d.core_rate = aac_decoder_sample_rate(dec);
  d.object_type = aac_decoder_object_type(dec);
  aac_decoder_destroy(dec);
  return d;
}

/* Lowpassed copies of one channel of the source and the output, for the
 * SNR of the band the core codes. */
static double low_band_snr(const std::vector<float>& ref, const std::vector<float>& out,
                           int channels, int channel, int delay, float cutoff, int sr,
                           int* best_delay = nullptr) {
  const std::vector<float> a = lowpass_channel(ref, channels, channel, cutoff, sr);
  const std::vector<float> b = lowpass_channel(out, channels, channel, cutoff, sr);
  if (best_delay) {
    best_aligned_snr_db(a, b, delay - 64, delay + 64, best_delay);
  }
  return aligned_snr_db(a, b, 1, delay);
}

static int expected_delay(int fl, int dual_rate) {
  return dual_rate ? 2 * (3 * fl / 2) + 2 * AAC_CLDFB_DELAY + 1 : 3 * fl / 2 + AAC_CLDFB_DELAY + 1;
}

/* ── roundtrips ────────────────────────────────────────────────── */

static void roundtrip(int fl, int channels, int dual_rate, AacTransport transport) {
  const char* tname = transport == AAC_TRANSPORT_RAW ? "raw" : "LOAS";
  const char* rname = dual_rate ? "dual" : "single";
  const int sr = 48000;
  const std::vector<float> pcm = gen_wideband(sr, sr, channels);
  const Encoded e = encode(pcm, sr, channels, 64000 * channels, fl, 1, dual_rate, transport);
  const int expect = expected_delay(fl, dual_rate);
  CHECK(e.delay == expect, "ELD-SBR %d %s: delay %d, expected %d", fl, rname, e.delay, expect);
  CHECK(e.frame == (dual_rate ? 2 * fl : fl), "ELD-SBR %d %s: frame %d", fl, rname, e.frame);
  const size_t in_frames = ((size_t)sr + e.frame - 1) / e.frame;
  const size_t flush_frames = ((size_t)e.delay + e.frame - 1) / e.frame;

  const Decoded d = decode(e, channels);
  CHECK(d.errors == 0 && d.concealed == 0, "ELD-SBR %d %s %s: %d errors, %d concealed", fl, rname,
        tname, d.errors, d.concealed);
  CHECK(d.object_type == AAC_AOT_ER_ELD, "object type %d", d.object_type);
  CHECK(d.frame_size == e.frame, "ELD-SBR %d %s: decoder frame %d, encoder %d", fl, rname,
        d.frame_size, e.frame);
  CHECK(d.out_rate == sr && d.core_rate == (dual_rate ? sr / 2 : sr),
        "ELD-SBR %d %s: output %d Hz, core %d Hz", fl, rname, d.out_rate, d.core_rate);
  CHECK(d.out.size() == (in_frames + flush_frames) * (size_t)e.frame * channels,
        "ELD-SBR %d %s %s: %zu output samples, expected %zu frames of %d", fl, rname, tname,
        d.out.size() / channels, in_frames + flush_frames, e.frame);
  CHECK(d.has_sbr == (int)(in_frames + flush_frames),
        "ELD-SBR %d %s %s: SBR data in %d of %zu frames", fl, rname, tname, d.has_sbr,
        in_frames + flush_frames);

  int best = 0;
  const double low = low_band_snr(pcm, d.out, channels, 0, e.delay, 4000.0f, sr, &best);
  const double overall = aligned_snr_db(pcm, d.out, channels, e.delay);
  printf(
      "ELD-SBR %d %s %d ch %s: low band %.1f dB, overall %.1f dB, best alignment %d (delay %d)\n",
      fl, rname, channels, tname, low, overall, best, e.delay);
  CHECK(low > 18.0, "ELD-SBR %d %s %d ch %s: low band %.1f dB", fl, rname, channels, tname, low);
  CHECK(overall > 10.0, "ELD-SBR %d %s %d ch %s: overall %.1f dB", fl, rname, channels, tname,
        overall);
  CHECK(best == e.delay, "ELD-SBR %d %s %d ch: best alignment %d, reported delay %d", fl, rname,
        channels, best, e.delay);
  /* The high band carries energy of the source's order: the decoder
   * reproduced it rather than leaving the core's lowpass. */
  const size_t from = (size_t)e.delay + sr / 4;
  const size_t to = from + sr / 2;
  const double src_hi =
      band_db(pcm, channels, 0, 10000.0f, 16000.0f, sr, from - e.delay, to - e.delay);
  const double out_hi = band_db(d.out, channels, 0, 10000.0f, 16000.0f, sr, from, to);
  printf("  10-16 kHz: source %.1f dB, decoded %.1f dB\n", src_hi, out_hi);
  CHECK(fabs(src_hi - out_hi) < 6.0, "ELD-SBR %d %s %d ch: high band %.1f dB against %.1f dB", fl,
        rname, channels, out_hi, src_hi);
}

/* ── CRC and concealment ───────────────────────────────────────── */

static void crc_and_concealment(int dual_rate) {
  const int sr = 48000;
  const int channels = 2;
  const std::vector<float> pcm = gen_tones(sr / 2, sr, channels);
  Encoded e = encode(pcm, sr, channels, 128000, 512, 1, dual_rate, AAC_TRANSPORT_RAW, 1);
  AacAsc asc;
  CHECK(aac_asc_parse(e.asc.data(), (int)e.asc.size(), &asc) == 0 && asc.ld_sbr_crc == 1,
        "CRC flag in the config");
  /* With the CRC on, an intact stream decodes without concealment. */
  Decoded clean = decode(e, channels);
  CHECK(clean.errors == 0 && clean.concealed == 0, "CRC stream: %d errors, %d concealed",
        clean.errors, clean.concealed);
  const size_t frames = e.aus.size();
  /* A truncated unit loses its channel elements and its SBR data. */
  Encoded cut = e;
  cut.aus[frames / 2].resize(3);
  Decoded d = decode(cut, channels);
  CHECK(d.errors == 0, "truncated unit: %d errors", d.errors);
  CHECK(d.out.size() == frames * (size_t)e.frame * channels,
        "truncated unit: %zu samples, expected %zu", d.out.size() / channels,
        frames * (size_t)e.frame);
  CHECK(d.concealed == 1, "truncated unit: %d concealed frames", d.concealed);
  /* A flipped bit at the end of a unit, inside the SBR payload, fails the
   * CRC: the frame counts as concealed, the channels keep their data. */
  Encoded flipped = e;
  std::vector<uint8_t>& au = flipped.aus[frames / 3];
  au[au.size() - 2] ^= 0x10;
  Decoded f = decode(flipped, channels);
  CHECK(f.errors == 0, "damaged SBR payload: %d errors", f.errors);
  CHECK(f.out.size() == frames * (size_t)e.frame * channels, "damaged SBR payload: %zu samples",
        f.out.size() / channels);
  CHECK(f.concealed == 1, "damaged SBR payload: %d concealed frames", f.concealed);
  CHECK(f.has_sbr == (int)frames - 1, "damaged SBR payload: has_sbr in %d of %zu frames", f.has_sbr,
        frames);
  printf("concealment %s: truncated %d, damaged payload %d concealed frame(s)\n",
         dual_rate ? "dual" : "single", d.concealed, f.concealed);
}

/* ── the config ────────────────────────────────────────────────── */

static void config_roundtrip(int channels, int dual_rate) {
  const int sr = 48000;
  const std::vector<float> pcm = gen_tones(2048, sr, channels);
  const Encoded e =
      encode(pcm, sr, channels, 64000 * channels, 480, 1, dual_rate, AAC_TRANSPORT_RAW);
  AacAsc asc;
  CHECK(aac_asc_parse(e.asc.data(), (int)e.asc.size(), &asc) == 0, "parse the config");
  CHECK(asc.aot == AAC_AOT_ER_ELD && asc.frame_length == 480 && asc.channel_config == channels,
        "config: aot %d, %d samples, config %d", asc.aot, asc.frame_length, asc.channel_config);
  CHECK(asc.sample_rate == (dual_rate ? sr / 2 : sr), "config: core rate %d", asc.sample_rate);
  CHECK(asc.ld_sbr_present == 1 && asc.ld_sbr_sampling_rate == dual_rate && asc.ld_sbr_crc == 0,
        "config: ld_sbr %d, rate flag %d, crc %d", asc.ld_sbr_present, asc.ld_sbr_sampling_rate,
        asc.ld_sbr_crc);
  CHECK(asc.num_ld_sbr_headers == 1, "config: %d headers", asc.num_ld_sbr_headers);
  const AacSbrLdHeader& h = asc.ld_sbr_header[0];
  uint8_t again[64];
  const int len = aac_asc_write(&asc, again, sizeof(again));
  CHECK(len == (int)e.asc.size() && memcmp(again, e.asc.data(), (size_t)len) == 0,
        "config: rewritten %d bytes differ from %zu", len, e.asc.size());
  printf("config %d ch %s: %zu bytes, header start %d stop %d xover %d amp_res %d\n", channels,
         dual_rate ? "dual" : "single", e.asc.size(), h.start_freq, h.stop_freq, h.xover_band,
         h.amp_res);
}

/* ── API ───────────────────────────────────────────────────────── */

static void test_api() {
  AacEncoderHandle enc = aac_encoder_create(48000, 2, 128000, AAC_AOT_ER_ELD, AAC_RC_CBR);
  CHECK(enc != nullptr, "ELD encoder");
  CHECK(aac_encoder_set_ld_sbr(enc, 1, 1) == AAC_OK, "LD-SBR dual rate");
  CHECK(aac_encoder_frame_size(enc) == 1024, "dual rate frame %d", aac_encoder_frame_size(enc));
  CHECK(aac_encoder_delay(enc) == expected_delay(512, 1), "dual rate delay %d",
        aac_encoder_delay(enc));
  CHECK(aac_encoder_set_frame_length(enc, 480) == AAC_OK, "480 with LD-SBR");
  CHECK(aac_encoder_frame_size(enc) == 960 && aac_encoder_delay(enc) == expected_delay(480, 1),
        "480 dual rate: %d, %d", aac_encoder_frame_size(enc), aac_encoder_delay(enc));
  CHECK(aac_encoder_set_ld_sbr(enc, 1, 0) == AAC_OK, "LD-SBR single rate");
  CHECK(aac_encoder_frame_size(enc) == 480 && aac_encoder_delay(enc) == expected_delay(480, 0),
        "480 single rate: %d, %d", aac_encoder_frame_size(enc), aac_encoder_delay(enc));
  CHECK(aac_encoder_set_ld_sbr(enc, 0, 0) == AAC_OK, "LD-SBR off again");
  CHECK(aac_encoder_frame_size(enc) == 480 && aac_encoder_delay(enc) == 720,
        "plain ELD 480: %d, %d", aac_encoder_frame_size(enc), aac_encoder_delay(enc));
  CHECK(aac_encoder_set_ld_sbr(enc, 1, 1) == AAC_OK, "LD-SBR on again");
  std::vector<float> block(960 * 2, 0.0f);
  std::vector<uint8_t> buf(8192);
  CHECK(aac_encoder_encode(enc, block.data(), 960, buf.data(), (int)buf.size()) > 0, "one frame");
  CHECK(aac_encoder_set_ld_sbr(enc, 0, 0) == AAC_ERR_STATE, "LD-SBR after the first frame");
  aac_encoder_destroy(enc);

  /* Dual rate from 32 kHz would put the core at 16 kHz, which has no
   * low-delay band tables. */
  enc = aac_encoder_create(32000, 1, 32000, AAC_AOT_ER_ELD, AAC_RC_CBR);
  CHECK(enc != nullptr, "ELD at 32 kHz");
  CHECK(aac_encoder_set_ld_sbr(enc, 1, 1) == AAC_ERR_UNSUPPORTED, "dual rate from 32 kHz");
  CHECK(aac_encoder_frame_size(enc) == 512 && aac_encoder_delay(enc) == 768,
        "plain ELD after the refusal: %d, %d", aac_encoder_frame_size(enc), aac_encoder_delay(enc));
  CHECK(aac_encoder_set_ld_sbr(enc, 1, 0) == AAC_OK, "single rate at 32 kHz");
  aac_encoder_destroy(enc);

  enc = aac_encoder_create(48000, 2, 128000, AAC_AOT_ER_LD, AAC_RC_CBR);
  CHECK(aac_encoder_set_ld_sbr(enc, 1, 1) == AAC_ERR_INVALID_ARG, "LD-SBR is ELD only");
  aac_encoder_destroy(enc);

  /* Downscaled playout has no form for the CLDFB chain. */
  const std::vector<float> pcm = gen_tones(2048, 48000, 1);
  const Encoded e = encode(pcm, 48000, 1, 64000, 512, 1, 1, AAC_TRANSPORT_RAW);
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  CHECK(aac_decoder_set_config(dec, e.asc.data(), (int)e.asc.size()) == AAC_OK, "LD-SBR config");
  CHECK(aac_decoder_set_eld_downscale(dec, 2) == AAC_ERR_UNSUPPORTED,
        "downscale of an LD-SBR stream");
  CHECK(aac_decoder_set_eld_downscale(dec, 1) == AAC_OK, "factor 1 stays allowed");
  CHECK(aac_decoder_frame_size(dec) == 1024 && aac_decoder_output_sample_rate(dec) == 48000 &&
            aac_decoder_sample_rate(dec) == 24000,
        "LD-SBR config: frame %d, output %d Hz, core %d Hz", aac_decoder_frame_size(dec),
        aac_decoder_output_sample_rate(dec), aac_decoder_sample_rate(dec));
  aac_decoder_destroy(dec);
  dec = aac_decoder_create(48000, 0);
  CHECK(aac_decoder_set_eld_downscale(dec, 2) == AAC_OK, "downscale before any config");
  CHECK(aac_decoder_set_config(dec, e.asc.data(), (int)e.asc.size()) == AAC_ERR_UNSUPPORTED,
        "LD-SBR config while downscaled");
  CHECK(aac_decoder_set_eld_downscale(dec, 1) == AAC_OK, "downscale back to 1");
  CHECK(aac_decoder_set_config(dec, e.asc.data(), (int)e.asc.size()) == AAC_OK,
        "LD-SBR config once undownscaled");
  /* A reset keeps the configuration and the output format. */
  std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT);
  CHECK(aac_decoder_decode(dec, e.aus[0].data(), (int)e.aus[0].size(), out.data(),
                           (int)out.size()) == 1024,
        "one unit of 1024 output samples");
  CHECK(aac_decoder_reset(dec) == AAC_OK, "reset");
  CHECK(aac_decoder_frame_size(dec) == 1024 && aac_decoder_output_sample_rate(dec) == 48000,
        "after the reset: frame %d, %d Hz", aac_decoder_frame_size(dec),
        aac_decoder_output_sample_rate(dec));
  CHECK(aac_decoder_decode(dec, e.aus[1].data(), (int)e.aus[1].size(), out.data(),
                           (int)out.size()) == 1024,
        "a unit after the reset");
  aac_decoder_destroy(dec);
}

/* ── reconfiguration from mono to stereo ───────────────────────── */

/* Decodes a LOAS stream through the streaming API, one frame per entry
 * of the result. */
static std::vector<std::vector<float>> decode_loas_frames(AacDecoderHandle dec,
                                                          const std::vector<uint8_t>& stream) {
  std::vector<std::vector<float>> frames;
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  CHECK(aac_decoder_feed(dec, stream.data(), (int)stream.size()) == (int)stream.size(),
        "the stream fits the FIFO");
  for (;;) {
    const int got = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
    if (got <= 0) {
      CHECK(got == 0, "decode_next %d", got);
      break;
    }
    frames.emplace_back(pcm.begin(), pcm.begin() + (size_t)got * aac_decoder_channels(dec));
  }
  return frames;
}

/* A mono LD-SBR stream followed by a stereo one with the same SBR header:
 * the in-band configuration switches the layout from one single channel
 * element to one channel pair, and the LD-SBR states have to follow (a
 * state built for the single channel cannot serve the pair), so the
 * stereo frames come out as from a decoder that only ever saw them. */
static void mono_then_stereo() {
  const int sr = 48000;
  const std::vector<float> mono_pcm = gen_wideband(sr / 4, sr, 1);
  const std::vector<float> stereo_pcm = gen_wideband(sr / 4, sr, 2);
  const Encoded mono = encode(mono_pcm, sr, 1, 64000, 512, 1, 1, AAC_TRANSPORT_LOAS);
  const Encoded stereo = encode(stereo_pcm, sr, 2, 128000, 512, 1, 1, AAC_TRANSPORT_LOAS);
  AacAsc a, b;
  CHECK(aac_asc_parse(mono.asc.data(), (int)mono.asc.size(), &a) == 0 &&
            aac_asc_parse(stereo.asc.data(), (int)stereo.asc.size(), &b) == 0,
        "both configs parse");
  CHECK(memcmp(&a.ld_sbr_header[0], &b.ld_sbr_header[0], sizeof(a.ld_sbr_header[0])) == 0,
        "the mono and the stereo stream share their SBR header");
  std::vector<uint8_t> both = mono.stream;
  both.insert(both.end(), stereo.stream.begin(), stereo.stream.end());

  AacDecoderHandle joint = aac_decoder_create(sr, 2);
  const std::vector<std::vector<float>> joint_frames = decode_loas_frames(joint, both);
  AacDecoderHandle fresh = aac_decoder_create(sr, 2);
  const std::vector<std::vector<float>> mono_frames = decode_loas_frames(fresh, mono.stream);
  aac_decoder_reset(fresh);
  const std::vector<std::vector<float>> stereo_frames = decode_loas_frames(fresh, stereo.stream);
  CHECK(joint_frames.size() == mono_frames.size() + stereo_frames.size(),
        "mono then stereo: %zu frames, expected %zu + %zu", joint_frames.size(),
        mono_frames.size(), stereo_frames.size());
  if (joint_frames.size() != mono_frames.size() + stereo_frames.size()) {
    aac_decoder_destroy(joint);
    aac_decoder_destroy(fresh);
    return;
  }
  for (size_t f = 0; f < 3 && f < stereo_frames.size(); f++) {
    const std::vector<float>& got = joint_frames[mono_frames.size() + f];
    const std::vector<float>& want = stereo_frames[f];
    CHECK(got.size() == want.size(), "stereo frame %zu: %zu samples, expected %zu", f, got.size(),
          want.size());
    if (got.size() != want.size()) {
      continue;
    }
    float worst = 0.0f;
    for (size_t i = 0; i < got.size(); i++) {
      worst = std::max(worst, fabsf(got[i] - want[i]));
    }
    CHECK(worst < 1e-5f, "stereo frame %zu after the mono stream differs by up to %g", f, worst);
  }
  /* The stereo frames carry two different channels: the comparison is
   * not satisfied by a decoder that duplicates one channel. */
  const std::vector<float>& last = stereo_frames.back();
  double diff = 0.0;
  for (size_t i = 0; i + 1 < last.size(); i += 2) {
    diff += fabs((double)last[i] - last[i + 1]);
  }
  CHECK(diff > 1.0, "the stereo stream's channels differ (%g)", diff);
  aac_decoder_destroy(joint);
  aac_decoder_destroy(fresh);
}

/* ── the payload buffer holds the longest sbr_data() ───────────── */

/* Every other QMF band of the SBR range driven to full scale while the
 * rest stay silent, with the band parity and the level flipping from
 * frame to frame: the envelopes take large deltas in frequency and in
 * time and every frame has a transient.  With the CRC on, a payload cut
 * short would fail it; every frame must decode without concealment and
 * the writer stays within the buffer it is sized for (the bound itself
 * rests on the code lengths test_sbr_ld checks). */
static void worst_case_payload() {
  const int sr = 48000;
  const int channels = 2;
  const int fl = 512;
  /* The low bitrate puts the crossover at 5 kHz, which gives the most bands. */
  AacEncoderHandle enc = aac_encoder_create(sr, channels, 32000, AAC_AOT_ER_ELD, AAC_RC_CBR);
  CHECK(enc != nullptr, "ELD encoder");
  CHECK(aac_encoder_set_frame_length(enc, fl) == AAC_OK, "frame length");
  CHECK(aac_encoder_set_transport(enc, AAC_TRANSPORT_RAW) == AAC_OK, "raw units");
  CHECK(aac_encoder_set_ld_sbr(enc, 1, 1) == AAC_OK, "LD-SBR dual rate");
  auto* state = static_cast<AacEncoderState*>(enc);
  state->ld_sbr_crc = 1;
  const int frame = aac_encoder_frame_size(enc);
  const int frames = 48;
  std::vector<float> pcm((size_t)frame * frames * channels, 0.0f);
  const double band_hz = (double)sr / 128.0; /* a QMF band of the 64-band bank */
  for (int f = 1; f < frames; f += 2) {
    const int parity = (f / 2) & 1;
    for (int i = 0; i < frame; i++) {
      const double t = (double)(f * frame + i) / sr;
      double v = 0.0;
      for (int k = parity; k < 64; k += 2) {
        v += sin(2.0 * M_PI * (k + 0.5) * band_hz * t + 0.7 * k);
      }
      for (int c = 0; c < channels; c++) {
        pcm[((size_t)f * frame + i) * channels + c] = (float)(0.95 * v / 6.0 * (c ? -1.0 : 1.0));
      }
    }
  }
  for (float& v : pcm) {
    v = std::max(-0.95f, std::min(0.95f, v));
  }
  uint8_t asc[64];
  const int asc_len = aac_encoder_config(enc, asc, sizeof(asc));
  std::vector<std::vector<uint8_t>> aus;
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) * 2);
  int most_bits = 0;
  for (int f = 0; f < frames; f++) {
    const int len = aac_encoder_encode(enc, pcm.data() + (size_t)f * frame * channels, frame,
                                       buf.data(), (int)buf.size());
    CHECK(len > 0, "encode frame %d: %d", f, len);
    aus.emplace_back(buf.begin(), buf.begin() + std::max(len, 0));
    for (int e = 0; e < state->num_elements; e++) {
      most_bits = std::max(most_bits, aac_sbr_ld_enc_bits(state->ld_sbr_enc[e]));
    }
  }
  aac_encoder_destroy(enc);
  printf("worst-case payload: %d bits at most, buffer %d bytes\n", most_bits,
         AAC_SBR_LD_ENC_MAX_BYTES);
  CHECK(most_bits <= 8 * AAC_SBR_LD_ENC_MAX_BYTES, "payload %d bits exceeds the buffer",
        most_bits);

  AacDecoderHandle dec = aac_decoder_create(sr, channels);
  CHECK(aac_decoder_set_config(dec, asc, asc_len) == AAC_OK, "config");
  std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  int concealed = 0;
  int errors = 0;
  int with_sbr = 0;
  for (const std::vector<uint8_t>& au : aus) {
    const int got = aac_decoder_decode(dec, au.data(), (int)au.size(), out.data(), (int)out.size());
    if (got < 0) {
      errors++;
      continue;
    }
    concealed += aac_decoder_last_frame_concealed(dec);
    int has_sbr = 0;
    aac_decoder_get_sbr_ps(dec, &has_sbr, nullptr);
    with_sbr += has_sbr;
  }
  CHECK(errors == 0 && concealed == 0, "worst-case payload: %d errors, %d concealed (CRC) frames",
        errors, concealed);
  CHECK(with_sbr == frames, "worst-case payload: SBR data in %d of %d frames", with_sbr, frames);
  aac_decoder_destroy(dec);
}

/* ── plain ELD stays byte for byte the same ────────────────────── */

/* The references were written by the release build before LD-SBR was
 * wired in; the signal and the encoder's arithmetic only reproduce them
 * under the same optimisation and fast-math flags, so other builds skip
 * the comparison. */
static void plain_eld_identity(int fl, int channels) {
#if !defined(__OPTIMIZE__) || !defined(__FAST_MATH__)
  printf("plain ELD %d %d ch: identity check needs the release build's flags, skipped\n", fl,
         channels);
  return;
#endif
  const int sr = 48000;
  const std::vector<float> pcm = gen_wideband(sr / 2, sr, channels);
  const Encoded e = encode(pcm, sr, channels, 64000 * channels, fl, 0, 0, AAC_TRANSPORT_LOAS);
  char name[64];
  snprintf(name, sizeof(name), "eld_%d_%dch_ref.loas", fl, channels);
  const std::vector<uint8_t> ref = read_file(data_path(name));
  CHECK(!ref.empty(), "reference %s", name);
  CHECK(e.stream == ref, "plain ELD %d %d ch: %zu bytes differ from the reference's %zu", fl,
        channels, e.stream.size(), ref.size());
}

int main() {
  test_api();
  for (int ch : {1, 2}) {
    for (int dual : {1, 0}) {
      config_roundtrip(ch, dual);
    }
  }
  for (int fl : {512, 480}) {
    for (int ch : {1, 2}) {
      roundtrip(fl, ch, 1, AAC_TRANSPORT_LOAS);
      roundtrip(fl, ch, 0, AAC_TRANSPORT_RAW);
    }
  }
  roundtrip(512, 2, 1, AAC_TRANSPORT_RAW);
  roundtrip(480, 1, 0, AAC_TRANSPORT_LOAS);
  crc_and_concealment(1);
  crc_and_concealment(0);
  mono_then_stereo();
  worst_case_payload();
  for (int fl : {512, 480}) {
    for (int ch : {1, 2}) {
      plain_eld_identity(fl, ch);
    }
  }
  return test_summary("test_eld_sbr");
}
