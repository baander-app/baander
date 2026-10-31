/* AAC-LD and AAC-ELD: the low-delay windows and filterbanks, the
 * AudioSpecificConfig of both object types, the settings of the public
 * API, encode/decode roundtrips over LOAS and raw access units, ELD
 * downscaled playout and, when ffmpeg is on PATH, FFmpeg's decode of our
 * streams. */
#include "aac_tables.h"
#include "asc.h"
#include "bitstream.h"
#include "decoder.h"
#include "lowdelay.h"
#include "mdct.h"
#include "test_util.h"

static float rnd(unsigned* seed) {
  *seed = *seed * 1664525u + 1013904223u;
  return (float)(*seed >> 8) / 8388608.0f - 1.0f;
}

/* ── windows and filterbanks ───────────────────────────────────── */

/* AAC-LD frames of 512 or 480 lines: windowed MDCT, IMDCT and overlap-add
 * reproduce the signal through the sine and low-overlap windows, also when
 * the shape changes from frame to frame (both halves of an overlap use the
 * earlier frame's shape). */
static void test_ld_tdac(int half) {
  const int n = 2 * half;
  AacMdct m;
  aac_mdct_init(&m, n);
  for (int shape = 0; shape < 2; shape++) {
    const float* w = aac_window_long_for(half, shape);
    for (int i = 0; i < half; i++) {
      const double a = w[i];
      const double b = w[half - 1 - i];
      CHECK(fabs(a * a + b * b - 1.0) < 1e-5, "LD window %d/%d sample %d", shape, half, i);
    }
  }
  const float* low = aac_window_long_for(half, 1);
  CHECK(low[3 * n / 16 - 1] == 0.0f && low[5 * n / 16] == 1.0f && low[3 * n / 16] > 0.0f,
        "low-overlap window %d: zeros below 3N/16, ones from 5N/16", half);
  const int frames = 8;
  const int shapes[frames] = {0, 1, 1, 0, 1, 0, 0, 1};
  std::vector<float> x((size_t)(frames + 1) * half);
  unsigned seed = 21;
  for (float& v : x) {
    v = rnd(&seed);
  }
  std::vector<float> out(x.size(), 0.0f);
  std::vector<float> in(n), X(half), y(n);
  int prev = 0;
  for (int f = 0; f < frames; f++) {
    const float* wl = aac_window_long_for(half, prev);
    const float* wr = aac_window_long_for(half, shapes[f]);
    for (int i = 0; i < half; i++) {
      in[i] = x[(size_t)f * half + i] * wl[i];
      in[half + i] = x[(size_t)f * half + half + i] * wr[half - 1 - i];
    }
    aac_mdct_forward(&m, in.data(), X.data());
    aac_imdct(&m, X.data(), y.data());
    for (int i = 0; i < half; i++) {
      out[(size_t)f * half + i] += y[i] * wl[i];
      out[(size_t)f * half + half + i] += y[half + i] * wr[half - 1 - i];
    }
    prev = shapes[f];
  }
  double err = 0.0;
  for (size_t i = half; i < (size_t)frames * half; i++) {
    err = std::max(err, fabs((double)out[i] - x[i]));
  }
  CHECK(err < 1e-4, "LD TDAC %d: error %.3g", half, err);
}

/* One frame of ELD synthesis against its definition:
 * s[i] = -2/N sum X[k] cos(2 pi / N (i - M/4 + 1/2)(k + 1/2)), windowed. */
static void test_eld_synthesis_definition(int m) {
  AacMdct mdct;
  aac_mdct_init(&mdct, 2 * m);
  std::vector<float> spec(m), overlap(3 * m, 0.0f), out(m);
  unsigned seed = 5;
  for (float& v : spec) {
    v = rnd(&seed);
  }
  const float* w = aac_eld_synthesis_window(m, 1);
  aac_eld_synthesis(&mdct, w, m, spec.data(), overlap.data(), out.data(), 1.0f);
  const int n = 2 * m;
  double err = 0.0;
  double peak = 0.0;
  for (int i = 0; i < 4 * m; i++) {
    double s = 0.0;
    for (int k = 0; k < m; k++) {
      s += spec[k] * cos(2.0 * M_PI / n * (i - m / 4.0 + 0.5) * (k + 0.5));
    }
    const double want = i < AAC_ELD_WINDOW_LENGTH(m) ? -2.0 / n * s * w[i] : 0.0;
    const double got = i < m ? out[i] : overlap[i - m];
    err = std::max(err, fabs(want - got));
    peak = std::max(peak, fabs(want));
  }
  CHECK(err / peak < 1e-5, "ELD %d synthesis relative error %.3g", m, err / peak);
}

/* Analysis followed by synthesis reproduces a signal 1.5 frames later. */
static void test_eld_reconstruction(int m) {
  AacMdct mdct;
  aac_mdct_init(&mdct, 2 * m);
  const int frames = 24;
  std::vector<float> x((size_t)frames * m);
  unsigned seed = 9;
  for (float& v : x) {
    v = rnd(&seed);
  }
  std::vector<float> in(4 * m, 0.0f), spec(m), overlap(3 * m, 0.0f), out((size_t)frames * m);
  for (int f = 0; f < frames; f++) {
    memmove(in.data(), in.data() + m, (size_t)(3 * m) * sizeof(float));
    memcpy(in.data() + 3 * m, x.data() + (size_t)f * m, (size_t)m * sizeof(float));
    aac_eld_analysis(&mdct, m, in.data(), spec.data());
    aac_eld_synthesis(&mdct, aac_eld_synthesis_window(m, 1), m, spec.data(), overlap.data(),
                      out.data() + (size_t)f * m, 1.0f);
  }
  const int delay = 3 * m / 2;
  const size_t from = (size_t)4 * m;
  const double snr = snr_db(x.data() + from - delay, out.data() + from, out.size() - from);
  printf("ELD %d analysis + synthesis: %.1f dB at a delay of %d\n", m, snr, delay);
  CHECK(snr > 60.0, "ELD %d reconstruction %.1f dB", m, snr);
}

/* Downscaled synthesis of the lowest m / factor lines plays a band-limited
 * signal at 1 / factor of the rate: sample n stands for the full-rate
 * position factor * n + (factor - 1) / 2. */
static void test_eld_downscaled_synthesis(int m, int factor) {
  const int md = m / factor;
  AacMdct full, down;
  aac_mdct_init(&full, 2 * m);
  aac_mdct_init(&down, 2 * md);
  const int frames = 24;
  const double f[3] = {0.013, 0.051, 0.09};
  auto sig = [&](double pos) {
    return 0.5 * sin(2.0 * M_PI * f[0] / factor * pos) +
           0.3 * sin(2.0 * M_PI * f[1] / factor * pos + 1.0) +
           0.2 * sin(2.0 * M_PI * f[2] / factor * pos + 2.0);
  };
  std::vector<float> in(4 * m, 0.0f), spec(m), overlap(3 * md, 0.0f), out((size_t)frames * md);
  for (int fr = 0; fr < frames; fr++) {
    memmove(in.data(), in.data() + m, (size_t)(3 * m) * sizeof(float));
    for (int i = 0; i < m; i++) {
      in[3 * m + i] = (float)sig((double)fr * m + i);
    }
    aac_eld_analysis(&full, m, in.data(), spec.data());
    aac_eld_synthesis(&down, aac_eld_synthesis_window(m, factor), md, spec.data(), overlap.data(),
                      out.data() + (size_t)fr * md, 1.0f);
  }
  const int delay = 3 * m / 2;
  std::vector<float> ref;
  for (size_t i = (size_t)4 * md; i < out.size(); i++) {
    ref.push_back((float)sig((double)factor * i + (factor - 1) / 2.0 - delay));
  }
  const double snr = snr_db(ref.data(), out.data() + 4 * md, ref.size());
  printf("ELD %d downscaled by %d: %.1f dB\n", m, factor, snr);
  CHECK(snr > 50.0, "ELD %d downscaled by %d: %.1f dB", m, factor, snr);
}

/* ── AudioSpecificConfig ───────────────────────────────────────── */

static void test_asc() {
  const int aots[2] = {AAC_AOT_ER_LD, AAC_AOT_ER_ELD};
  for (int aot : aots) {
    for (int fl : {512, 480}) {
      AacAsc asc;
      aac_asc_init(&asc, 48000, 2);
      asc.aot = aot;
      asc.frame_length = fl;
      uint8_t buf[32];
      const int len = aac_asc_write(&asc, buf, sizeof(buf));
      CHECK(len > 0, "aot %d/%d: write %d", aot, fl, len);
      AacAsc back;
      const int ret = aac_asc_parse(buf, len, &back);
      CHECK(ret >= 0, "aot %d/%d: read %d", aot, fl, ret);
      CHECK(back.aot == aot && back.frame_length == fl && back.sample_rate == 48000 &&
                back.channel_config == 2 && !back.section_resilience &&
                !back.scalefactor_resilience && !back.spectral_resilience && !back.ld_sbr_present,
            "aot %d/%d: read back aot %d, %d samples, %d Hz, config %d", aot, fl, back.aot,
            back.frame_length, back.sample_rate, back.channel_config);
    }
  }
  /* ELDSpecificConfig with LD-SBR (one header for a stereo stream) and an
   * extension entry before the terminator: parsed, then accepted by the
   * decoder, which outputs at twice the 24 kHz core rate
   * (ldSbrSamplingRate 1). */
  AacAsc asc;
  aac_asc_init(&asc, 24000, 2);
  asc.aot = AAC_AOT_ER_ELD;
  asc.frame_length = 512;
  asc.ld_sbr_present = 1;
  asc.ld_sbr_sampling_rate = 1;
  asc.ld_sbr_crc = 0;
  asc.num_ld_sbr_headers = 1;
  asc.ld_sbr_header[0] = {};
  asc.ld_sbr_header[0].amp_res = 1;
  asc.ld_sbr_header[0].start_freq = 5;
  asc.ld_sbr_header[0].stop_freq = 9;
  asc.ld_sbr_header[0].freq_scale = 2;
  asc.ld_sbr_header[0].alter_scale = 1;
  asc.ld_sbr_header[0].noise_bands = 2;
  asc.ld_sbr_header[0].limiter_bands = 2;
  asc.ld_sbr_header[0].limiter_gains = 2;
  asc.ld_sbr_header[0].interpol_freq = 1;
  asc.ld_sbr_header[0].smoothing_mode = 1;
  uint8_t buf[32];
  const int len = aac_asc_write(&asc, buf, sizeof(buf));
  CHECK(len > 0, "ELD with LD-SBR: write %d", len);
  AacAsc back;
  CHECK(aac_asc_parse(buf, len, &back) >= 0 && back.ld_sbr_present == 1 &&
            back.num_ld_sbr_headers == 1 && back.ld_sbr_header[0].start_freq == 5 &&
            back.ld_sbr_header[0].stop_freq == 9,
        "ELD with LD-SBR: read back");
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  CHECK(aac_decoder_set_config(dec, buf, len) == AAC_OK, "LD-SBR config accepted");
  CHECK(aac_decoder_frame_size(dec) == 1024 && aac_decoder_output_sample_rate(dec) == 48000 &&
            aac_decoder_sample_rate(dec) == 24000,
        "dual-rate LD-SBR: frame %d at %d Hz, core %d Hz", aac_decoder_frame_size(dec),
        aac_decoder_output_sample_rate(dec), aac_decoder_sample_rate(dec));
  /* An AAC-LD config with the section data resilience flag. */
  aac_asc_init(&asc, 48000, 1);
  asc.aot = AAC_AOT_ER_LD;
  asc.frame_length = 512;
  asc.section_resilience = 1;
  const int len2 = aac_asc_write(&asc, buf, sizeof(buf));
  CHECK(aac_asc_parse(buf, len2, &back) >= 0 && back.section_resilience == 1,
        "resilience flag read back");
  CHECK(aac_decoder_set_config(dec, buf, len2) == AAC_OK, "resilience tools accepted");
  /* A low-delay config at a rate without band tables. */
  aac_asc_init(&asc, 16000, 1);
  asc.aot = AAC_AOT_ER_LD;
  asc.frame_length = 512;
  const int len3 = aac_asc_write(&asc, buf, sizeof(buf));
  CHECK(aac_decoder_set_config(dec, buf, len3) == AAC_ERR_UNSUPPORTED, "16 kHz LD refused");
  aac_decoder_destroy(dec);
}

/* ── encoding and decoding ─────────────────────────────────────── */

struct Encoded {
  std::vector<uint8_t> stream;           /* LOAS, or the access units back to back */
  std::vector<std::vector<uint8_t>> aus; /* raw access units */
  std::vector<uint8_t> asc;
  int delay = 0;
  int frame = 0;
};

static Encoded encode(const std::vector<float>& pcm, int sr, int channels, int bitrate,
                      AacObjectType aot, int frame_length, AacTransport transport) {
  Encoded e;
  AacEncoderHandle enc = aac_encoder_create(sr, channels, bitrate, aot, AAC_RC_CBR);
  CHECK(enc != nullptr, "create aot %d at %d Hz", aot, sr);
  if (!enc) {
    return e;
  }
  CHECK(aac_encoder_set_frame_length(enc, frame_length) == AAC_OK, "frame length %d", frame_length);
  CHECK(aac_encoder_set_transport(enc, transport) == AAC_OK, "transport %d", transport);
  uint8_t asc[64];
  const int asc_len = aac_encoder_config(enc, asc, sizeof(asc));
  CHECK(asc_len > 0, "config %d", asc_len);
  e.asc.assign(asc, asc + std::max(asc_len, 0));
  e.delay = aac_encoder_delay(enc);
  e.frame = aac_encoder_frame_size(enc);
  std::vector<float> block((size_t)e.frame * channels);
  /* room for the flush, which writes the last frames of a stream transport at once */
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
static std::vector<float> decode(const Encoded& e, int channels, int downscale, int* errors,
                                 int* object_type = nullptr, int* frame_size = nullptr,
                                 int* out_rate = nullptr) {
  std::vector<float> out;
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  AacDecoderHandle dec = aac_decoder_create(48000, channels);
  *errors = 0;
  if (downscale > 1 && aac_decoder_set_eld_downscale(dec, downscale) != AAC_OK) {
    (*errors)++;
  }
  if (!e.aus.empty()) {
    if (aac_decoder_set_config(dec, e.asc.data(), (int)e.asc.size()) != AAC_OK) {
      (*errors)++;
    }
    for (const std::vector<uint8_t>& au : e.aus) {
      const int got =
          aac_decoder_decode(dec, au.data(), (int)au.size(), pcm.data(), (int)pcm.size());
      if (got < 0) {
        (*errors)++;
        continue;
      }
      out.insert(out.end(), pcm.begin(), pcm.begin() + (size_t)got * aac_decoder_channels(dec));
    }
  } else {
    for (size_t pos = 0; pos < e.stream.size();) {
      const int taken = aac_decoder_feed(dec, e.stream.data() + pos,
                                         (int)std::min((size_t)700, e.stream.size() - pos));
      if (taken <= 0) {
        (*errors)++;
        break;
      }
      pos += (size_t)taken;
      for (;;) {
        const int got = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
        if (got == 0) {
          break;
        }
        if (got < 0) {
          (*errors)++;
          continue;
        }
        out.insert(out.end(), pcm.begin(), pcm.begin() + (size_t)got * aac_decoder_channels(dec));
      }
    }
  }
  if (object_type) {
    *object_type = aac_decoder_object_type(dec);
  }
  if (frame_size) {
    *frame_size = aac_decoder_frame_size(dec);
  }
  if (out_rate) {
    *out_rate = aac_decoder_output_sample_rate(dec);
  }
  aac_decoder_destroy(dec);
  return out;
}

static const char* aot_name(AacObjectType aot) { return aot == AAC_AOT_ER_LD ? "LD" : "ELD"; }

/* Encode and decode at the encoder's delay; the best alignment must be
 * that delay. */
static void roundtrip(AacObjectType aot, int fl, int sr, int channels, AacTransport transport) {
  const char* tname = transport == AAC_TRANSPORT_RAW ? "raw" : "LOAS";
  const std::vector<float> pcm = gen_tones(sr * 2, sr, channels);
  const Encoded e = encode(pcm, sr, channels, 64000 * channels, aot, fl, transport);
  const int expect_delay = aot == AAC_AOT_ER_LD ? 2 * fl : 3 * fl / 2;
  CHECK(e.delay == expect_delay, "%s %d: delay %d, expected %d", aot_name(aot), fl, e.delay,
        expect_delay);
  CHECK(e.frame == fl, "%s %d: frame size %d", aot_name(aot), fl, e.frame);
  int errors = 0;
  int object_type = 0;
  const std::vector<float> out = decode(e, channels, 1, &errors, &object_type);
  CHECK(errors == 0, "%s %d %s: %d decode errors", aot_name(aot), fl, tname, errors);
  CHECK(object_type == aot, "%s %d %s: object type %d", aot_name(aot), fl, tname, object_type);
  const size_t expect_len = ((pcm.size() / channels + fl - 1) / fl + 2) * fl * channels;
  CHECK(out.size() == expect_len, "%s %d %s: %zu samples, expected %zu", aot_name(aot), fl, tname,
        out.size(), expect_len);
  const double snr = aligned_snr_db(pcm, out, channels, e.delay);
  int best = -1;
  if (channels == 1) {
    best_aligned_snr_db(pcm, out, e.delay - 64, e.delay + 64, &best);
  }
  printf("%-4s %d %5d Hz %d ch %-4s: %.1f dB\n", aot_name(aot), fl, sr, channels, tname, snr);
  CHECK(snr > 25.0, "%s %d %d Hz %d ch %s: %.1f dB", aot_name(aot), fl, sr, channels, tname, snr);
  CHECK(channels != 1 || best == e.delay, "%s %d %d Hz: best alignment %d, reported delay %d",
        aot_name(aot), fl, sr, best, e.delay);
}

/* Downscaled playout of an ELD stream against the full-rate decode,
 * band-limited and resampled at the downscaled output's positions. */
static void downscaled(int fl, int factor) {
  const int sr = 48000;
  std::vector<float> pcm((size_t)sr * 2);
  for (size_t i = 0; i < pcm.size(); i++) {
    const double t = (double)i / sr;
    pcm[i] = (float)(0.4 * sin(2 * M_PI * 500.0 * t) + 0.2 * sin(2 * M_PI * 1700.0 * t) +
                     0.1 * sin(2 * M_PI * 4100.0 / factor * t));
  }
  const Encoded e = encode(pcm, sr, 1, 96000, AAC_AOT_ER_ELD, fl, AAC_TRANSPORT_LOAS);
  int errors = 0;
  int frame_size = 0;
  int rate = 0;
  const std::vector<float> full = decode(e, 1, 1, &errors);
  CHECK(errors == 0, "ELD %d full-rate decode errors %d", fl, errors);
  const std::vector<float> down = decode(e, 1, factor, &errors, nullptr, &frame_size, &rate);
  CHECK(errors == 0, "ELD %d downscaled by %d: %d errors", fl, factor, errors);
  CHECK(frame_size == fl / factor && rate == sr / factor,
        "ELD %d downscaled by %d: frame size %d, rate %d", fl, factor, frame_size, rate);
  CHECK(down.size() * factor == full.size(), "ELD %d by %d: %zu vs %zu samples", fl, factor,
        down.size(), full.size());
  /* Reference: the full-rate output through a windowed-sinc lowpass at
   * 0.8 of the downscaled Nyquist frequency, evaluated at position
   * factor * n + (factor - 1) / 2. */
  const double cutoff = 0.8 * 0.5 / factor;
  const int half = 64 * factor;
  std::vector<float> ref, test;
  for (size_t nd = 64; nd + 64 < down.size(); nd++) {
    const double pos = (double)factor * nd + (factor - 1) / 2.0;
    double acc = 0.0;
    for (long k = (long)pos - half; k <= (long)pos + half; k++) {
      const double d = pos - (double)k;
      const double sinc = fabs(d) < 1e-9 ? 2 * cutoff : sin(2 * M_PI * cutoff * d) / (M_PI * d);
      const double win = 0.5 + 0.5 * cos(M_PI * d / (half + 1));
      if (k >= 0 && (size_t)k < full.size()) {
        acc += full[(size_t)k] * sinc * win;
      }
    }
    ref.push_back((float)acc);
    test.push_back(down[nd]);
  }
  const double snr = snr_db(ref.data(), test.data(), ref.size());
  printf("ELD %d downscaled by %d vs the full-rate decode: %.1f dB\n", fl, factor, snr);
  CHECK(snr > 30.0, "ELD %d downscaled by %d: %.1f dB", fl, factor, snr);
}

/* A damaged access unit is concealed: the decoder keeps producing whole
 * frames (downscaled ones for ELD playout) and recovers afterwards. */
static void concealment(AacObjectType aot, int downscale) {
  const int sr = 48000;
  const std::vector<float> pcm = gen_tones(sr / 2, sr, 2);
  Encoded e = encode(pcm, sr, 2, 128000, aot, 512, AAC_TRANSPORT_RAW);
  const size_t frames = e.aus.size();
  e.aus[frames / 2].resize(3);
  AacDecoderHandle dec = aac_decoder_create(sr, 0);
  CHECK(aac_decoder_set_eld_downscale(dec, downscale) == AAC_OK, "downscale %d", downscale);
  CHECK(aac_decoder_set_config(dec, e.asc.data(), (int)e.asc.size()) == AAC_OK, "config");
  std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 2);
  size_t total = 0;
  int concealed = 0;
  for (const std::vector<uint8_t>& au : e.aus) {
    const int got = aac_decoder_decode(dec, au.data(), (int)au.size(), out.data(), (int)out.size());
    CHECK(got > 0, "%s: decode %d", aot_name(aot), got);
    total += got > 0 ? (size_t)got : 0;
    concealed += aac_decoder_last_frame_concealed(dec);
  }
  const int frame = 512 / (aot == AAC_AOT_ER_ELD ? downscale : 1);
  CHECK(total == frames * (size_t)frame, "%s: %zu samples, expected %zu", aot_name(aot), total,
        frames * (size_t)frame);
  CHECK(concealed == 1 && aac_decoder_concealed_frames(dec) == 1, "%s: %d concealed frames",
        aot_name(aot), concealed);
  aac_decoder_destroy(dec);
}

/* ── API ───────────────────────────────────────────────────────── */

/* The extension payload of ELD v2 is bounded by the frame's bit budget:
 * a mono 512-sample frame has 3072 bits, of which the core keeps 512, so
 * a payload of 2560 bits is the most it carries.  One over that is
 * refused when set, one at it is budgeted by the rate control: the
 * frame that carries it (an EXT_FIL payload, which the decoder passes
 * as padding) stays within the frame's bound and decodes. */
static void test_extension_payload_budget() {
  AacEncoderHandle enc = aac_encoder_create(48000, 1, 64000, AAC_AOT_ER_ELD, AAC_RC_CBR);
  CHECK(enc != nullptr, "ELD mono encoder");
  CHECK(aac_encoder_set_transport(enc, AAC_TRANSPORT_RAW) == AAC_OK, "raw access units");
  const int budget = 6144 * 512 / 1024 - 512;
  std::vector<uint8_t> payload(1024, 0); /* extension_type 0 (EXT_FIL), then zeros */
  CHECK(aac_encoder_set_extension_payload(enc, payload.data(), budget + 1) == AAC_ERR_INVALID_ARG,
        "payload of %d bits refused", budget + 1);
  CHECK(aac_encoder_set_extension_payload(enc, payload.data(), 8 * 1024 + 8) == AAC_ERR_INVALID_ARG,
        "payload beyond the buffer refused");
  CHECK(aac_encoder_set_extension_payload(enc, payload.data(), budget) == AAC_OK, "payload of %d bits taken",
        budget);
  uint8_t asc[AAC_ASC_MAX_BYTES];
  const int asc_len = aac_encoder_config(enc, asc, sizeof(asc));
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  CHECK(asc_len > 0 && aac_decoder_set_config(dec, asc, asc_len) == AAC_OK, "decoder configured");
  const int max_bytes = aac_encoder_max_frame_bytes(enc);
  std::vector<uint8_t> buf((size_t)max_bytes);
  std::vector<float> block(512);
  std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 2);
  for (int f = 0; f < 4; f++) {
    if (f == 2) {
      CHECK(aac_encoder_set_extension_payload(enc, payload.data(), budget) == AAC_OK, "payload set again");
    }
    for (int i = 0; i < 512; i++) {
      block[(size_t)i] = 0.5f * sinf(2.0f * 3.14159265f * 1000.0f * (float)(f * 512 + i) / 48000.0f) +
                         0.3f * sinf(2.0f * 3.14159265f * 3700.0f * (float)(f * 512 + i) / 48000.0f);
    }
    const int len = aac_encoder_encode(enc, block.data(), 512, buf.data(), max_bytes);
    CHECK(len > 0 && 8 * len <= 6144 * 512 / 1024, "frame %d: %d bytes within the frame's bound", f, len);
    if (len <= 0) {
      continue;
    }
    const int got = aac_decoder_decode(dec, buf.data(), len, out.data(), (int)out.size());
    CHECK(got == 512, "frame %d with the payload decodes (%d)", f, got);
  }
  aac_decoder_destroy(dec);
  aac_encoder_destroy(enc);
}

static void test_api() {
  AacEncoderHandle enc = aac_encoder_create(48000, 2, 128000, AAC_AOT_ER_ELD, AAC_RC_CBR);
  CHECK(enc != nullptr, "ELD encoder");
  CHECK(aac_encoder_frame_size(enc) == 512, "ELD frame size %d", aac_encoder_frame_size(enc));
  CHECK(aac_encoder_delay(enc) == 768, "ELD delay %d", aac_encoder_delay(enc));
  CHECK(aac_encoder_set_transport(enc, AAC_TRANSPORT_ADTS) == AAC_ERR_INVALID_ARG,
        "ADTS refused for ELD");
  CHECK(aac_encoder_set_frame_length(enc, 1024) == AAC_ERR_INVALID_ARG, "1024 refused for ELD");
  CHECK(aac_encoder_set_frame_length(enc, 480) == AAC_OK, "480 for ELD");
  CHECK(aac_encoder_frame_size(enc) == 480 && aac_encoder_delay(enc) == 720, "ELD 480: %d, %d",
        aac_encoder_frame_size(enc), aac_encoder_delay(enc));
  CHECK(aac_encoder_set_ld_sbr(enc, 1, 1) == AAC_OK, "LD-SBR on");
  CHECK(aac_encoder_frame_size(enc) == 960, "LD-SBR dual rate frame %d",
        aac_encoder_frame_size(enc));
  CHECK(aac_encoder_set_ld_sbr(enc, 0, 0) == AAC_OK, "LD-SBR off");
  CHECK(aac_encoder_frame_size(enc) == 480 && aac_encoder_delay(enc) == 720,
        "ELD 480 after LD-SBR off: %d, %d", aac_encoder_frame_size(enc), aac_encoder_delay(enc));
  CHECK(aac_encoder_set_metadata(enc, AAC_DRC_MUSIC_STANDARD, 124) == AAC_ERR_UNSUPPORTED,
        "no DRC metadata in error resilient frames");
  uint8_t asc_bytes[16];
  const int asc_len = aac_encoder_config(enc, asc_bytes, sizeof(asc_bytes));
  AacAsc asc;
  CHECK(asc_len > 0 && aac_asc_parse(asc_bytes, asc_len, &asc) >= 0 && asc.aot == AAC_AOT_ER_ELD &&
            asc.frame_length == 480 && asc.channel_config == 2,
        "ELD config: aot %d, %d samples", asc.aot, asc.frame_length);
  /* LOAS is the default: the first frame carries the LOAS sync word. */
  std::vector<float> block(480 * 2, 0.0f);
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc));
  const int len = aac_encoder_encode(enc, block.data(), 480, buf.data(), (int)buf.size());
  CHECK(len > 3 && buf[0] == 0x56 && (buf[1] & 0xE0) == 0xE0, "LOAS by default");
  aac_encoder_destroy(enc);

  enc = aac_encoder_create(44100, 1, 64000, AAC_AOT_ER_LD, AAC_RC_CBR);
  CHECK(enc != nullptr, "LD encoder");
  CHECK(aac_encoder_delay(enc) == 1024, "LD delay %d", aac_encoder_delay(enc));
  CHECK(aac_encoder_set_ld_sbr(enc, 1, 0) == AAC_ERR_INVALID_ARG, "LD-SBR is ELD only");
  aac_encoder_destroy(enc);
  CHECK(aac_encoder_create(16000, 1, 32000, AAC_AOT_ER_LD, AAC_RC_CBR) == nullptr,
        "no LD tables at 16 kHz");
  CHECK(aac_encoder_create(48000, 8, 256000, AAC_AOT_ER_ELD, AAC_RC_CBR) == nullptr,
        "eight channels need a PCE, which ER frames cannot carry");
  enc = aac_encoder_create(48000, 2, 128000, AAC_AOT_LC, AAC_RC_CBR);
  CHECK(aac_encoder_set_frame_length(enc, 512) == AAC_ERR_INVALID_ARG, "512 is low-delay only");
  CHECK(aac_encoder_set_ld_sbr(enc, 1, 0) == AAC_ERR_INVALID_ARG, "LD-SBR on AAC-LC");
  aac_encoder_destroy(enc);

  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  CHECK(aac_decoder_object_type(dec) == 0, "no object type before a stream");
  CHECK(aac_decoder_set_frame_length(dec, 512) == AAC_ERR_INVALID_ARG, "512 only through a config");
  CHECK(aac_decoder_set_eld_downscale(dec, 3) == AAC_ERR_INVALID_ARG, "factor 3");
  CHECK(aac_decoder_set_eld_downscale(dec, 2) == AAC_OK, "factor 2");
  CHECK(aac_decoder_set_config(dec, asc_bytes, asc_len) == AAC_OK, "ELD config applied");
  CHECK(aac_decoder_object_type(dec) == AAC_AOT_ER_ELD, "object type from the config");
  CHECK(aac_decoder_frame_size(dec) == 240 && aac_decoder_output_sample_rate(dec) == 24000,
        "downscaled ELD 480: frame %d, rate %d", aac_decoder_frame_size(dec),
        aac_decoder_output_sample_rate(dec));
  CHECK(aac_decoder_set_frame_length(dec, 1024) == AAC_ERR_STATE,
        "the config's frame length stands");
  aac_decoder_destroy(dec);

  /* A configuration the decoder refuses leaves it as it was: an LD-SBR
   * config against a downscaled decoder is refused before anything of it
   * is applied, and a plain ELD config afterwards decodes as in a fresh
   * decoder. */
  enc = aac_encoder_create(48000, 2, 128000, AAC_AOT_ER_ELD, AAC_RC_CBR);
  CHECK(aac_encoder_set_ld_sbr(enc, 1, 1) == AAC_OK, "LD-SBR for the refused config");
  uint8_t sbr_asc[16];
  const int sbr_asc_len = aac_encoder_config(enc, sbr_asc, sizeof(sbr_asc));
  CHECK(sbr_asc_len > 0, "LD-SBR config %d", sbr_asc_len);
  aac_encoder_destroy(enc);
  const Encoded plain = encode(gen_tones(2048, 48000, 2), 48000, 2, 128000, AAC_AOT_ER_ELD, 480,
                               AAC_TRANSPORT_RAW);
  dec = aac_decoder_create(48000, 0);
  CHECK(aac_decoder_set_eld_downscale(dec, 2) == AAC_OK, "downscale before the configs");
  CHECK(aac_decoder_set_config(dec, sbr_asc, sbr_asc_len) == AAC_ERR_UNSUPPORTED,
        "LD-SBR config refused while downscaled");
  CHECK(aac_decoder_object_type(dec) == 0 && aac_decoder_sample_rate(dec) == 48000 &&
            aac_decoder_frame_size(dec) == 1024,
        "nothing of the refused config applied: aot %d, %d Hz, frame %d",
        aac_decoder_object_type(dec), aac_decoder_sample_rate(dec), aac_decoder_frame_size(dec));
  CHECK(aac_decoder_set_config(dec, plain.asc.data(), (int)plain.asc.size()) == AAC_OK,
        "plain ELD config after the refusal");
  AacDecoderHandle fresh = aac_decoder_create(48000, 0);
  CHECK(aac_decoder_set_eld_downscale(fresh, 2) == AAC_OK, "downscale of the fresh decoder");
  CHECK(aac_decoder_set_config(fresh, plain.asc.data(), (int)plain.asc.size()) == AAC_OK,
        "plain ELD config of the fresh decoder");
  std::vector<float> got((size_t)AAC_DECODER_MAX_OUTPUT * 2);
  std::vector<float> want((size_t)AAC_DECODER_MAX_OUTPUT * 2);
  for (size_t i = 0; i < plain.aus.size() && i < 3; i++) {
    const int n = aac_decoder_decode(dec, plain.aus[i].data(), (int)plain.aus[i].size(),
                                     got.data(), (int)got.size());
    const int m = aac_decoder_decode(fresh, plain.aus[i].data(), (int)plain.aus[i].size(),
                                     want.data(), (int)want.size());
    CHECK(n == 240 && m == 240, "unit %zu after the refusal: %d samples, fresh %d", i, n, m);
    CHECK(n == m && memcmp(got.data(), want.data(), (size_t)std::max(n, 0) * 2 * sizeof(float)) == 0,
          "unit %zu after the refusal decodes as in a fresh decoder", i);
  }
  CHECK(aac_decoder_output_sample_rate(dec) == 24000, "downscaled rate %d",
        aac_decoder_output_sample_rate(dec));
  aac_decoder_destroy(fresh);
  aac_decoder_destroy(dec);

  /* The downscale factor is fixed from the first frame until a reset,
   * whatever reconfiguration the stream goes through. */
  dec = aac_decoder_create(48000, 0);
  CHECK(aac_decoder_set_config(dec, plain.asc.data(), (int)plain.asc.size()) == AAC_OK,
        "config before the first frame");
  CHECK(aac_decoder_decode(dec, plain.aus[0].data(), (int)plain.aus[0].size(), got.data(),
                           (int)got.size()) == 480,
        "one full-rate unit");
  CHECK(aac_decoder_set_eld_downscale(dec, 2) == AAC_ERR_STATE, "factor after the first frame");
  const Encoded other = encode(gen_tones(2048, 48000, 1), 48000, 1, 64000, AAC_AOT_ER_ELD, 512,
                               AAC_TRANSPORT_RAW);
  CHECK(aac_decoder_set_config(dec, other.asc.data(), (int)other.asc.size()) == AAC_OK,
        "a new layout and frame length mid-stream");
  CHECK(aac_decoder_set_eld_downscale(dec, 2) == AAC_ERR_STATE,
        "factor after a reconfiguration mid-stream");
  CHECK(aac_decoder_reset(dec) == AAC_OK, "reset");
  CHECK(aac_decoder_set_eld_downscale(dec, 2) == AAC_OK, "factor after the reset");
  CHECK(aac_decoder_decode(dec, other.aus[0].data(), (int)other.aus[0].size(), got.data(),
                           (int)got.size()) == 256,
        "a downscaled unit after the reset");
  aac_decoder_destroy(dec);

  /* An error resilient block needs a layout from a channel configuration:
   * without any layout it waits for one, with a layout taken from the
   * elements of an earlier (non-ER) block the stream cannot be decoded and
   * says so, rather than waiting for ever. */
  dec = aac_decoder_create(48000, 0);
  auto* state = static_cast<AacDecoderState*>(dec);
  AacBitReader reader;
  aac_bitreader_init(&reader, other.aus[0].data(), (int)other.aus[0].size());
  aac_decoder_set_object_type(state, AAC_AOT_ER_LD);
  CHECK(aac_decode_raw_data_block(state, &reader) == AAC_ERR_STATE,
        "ER block without a layout waits for a configuration");
  aac_decoder_destroy(dec);
  dec = aac_decoder_create(48000, 0);
  state = static_cast<AacDecoderState*>(dec);
  const std::vector<uint8_t> lc = encode_stream(gen_tones(2048, 48000, 1), 48000, 1, 64000,
                                                AAC_RC_CBR);
  CHECK(decode_stream(lc, 48000, 0, nullptr, dec).size() > 0, "an LC frame sets a layout");
  state->layout_source = 3; /* as if the elements of the block had defined it */
  aac_decoder_set_object_type(state, AAC_AOT_ER_LD);
  aac_bitreader_init(&reader, other.aus[0].data(), (int)other.aus[0].size());
  CHECK(aac_decode_raw_data_block(state, &reader) == AAC_ERR_UNSUPPORTED,
        "ER block with a sniffed layout is refused for good");
  aac_decoder_destroy(dec);
}

/* ── FFmpeg ────────────────────────────────────────────────────── */

static std::string g_dir;

static std::string path(const char* name) { return g_dir + "/" + name; }

/* FFmpeg reads the AudioSpecificConfig of a low-delay stream from the
 * esds box of an MP4 file (its LOAS demuxer leaves the sample rate of
 * error resilient streams unset). */
static std::vector<uint8_t> mp4(const Encoded& e, int rate, int channels) {
  return mp4_file(e.aus, e.asc, e.frame, rate, channels);
}

/* FFmpeg decodes our raw access units (in MP4) as our decoder does. */
static void ffmpeg_decodes(AacObjectType aot, int fl, int sr, int channels) {
  const std::vector<float> pcm =
      channels > 2 ? gen_multichannel(sr, sr, channels) : gen_tones(sr, sr, channels);
  const Encoded e = encode(pcm, sr, channels, 64000 * channels, aot, fl, AAC_TRANSPORT_RAW);
  const std::vector<uint8_t> file = mp4(e, sr, channels);
  CHECK(write_file(path("ours.mp4"), file.data(), file.size()), "write mp4");
  const std::string cmd = "ffmpeg -nostdin -v error -y -i '" + path("ours.mp4") + "' -f f32le '" +
                          path("ours.ff.f32") + "'";
  CHECK(run_command(cmd), "%s %d: ffmpeg refused our stream", aot_name(aot), fl);
  int errors = 0;
  const std::vector<float> ours = decode(e, channels, 1, &errors);
  const std::vector<float> theirs = read_f32(path("ours.ff.f32"));
  CHECK(errors == 0, "%s %d: %d errors in our decoder", aot_name(aot), fl, errors);
  CHECK(!theirs.empty() && theirs.size() == ours.size(), "%s %d: %zu vs %zu samples", aot_name(aot),
        fl, theirs.size(), ours.size());
  const size_t n = std::min(ours.size(), theirs.size());
  const double d = snr_db(theirs.data(), ours.data(), n);
  printf("%-4s %d %5d Hz %d ch: ffmpeg vs ours %.1f dB\n", aot_name(aot), fl, sr, channels, d);
  CHECK(d > 90.0, "%s %d %d Hz %d ch: decoders disagree (%.1f dB)", aot_name(aot), fl, sr, channels,
        d);
}

/* Our encoder only uses the sine window; a hand-built AAC-LD stream that
 * switches between the sine and the low-overlap window checks the
 * decoding of both against FFmpeg.  Each frame codes every line with
 * codebook 1. */
static void ffmpeg_window_shapes() {
  const int fl = 512;
  const int num_swb = aac_num_sfb(fl, 3, 0);
  const int shapes[10] = {0, 1, 1, 0, 1, 0, 0, 1, 1, 0};
  Encoded e;
  e.frame = fl;
  AacAsc asc;
  aac_asc_init(&asc, 48000, 1);
  asc.aot = AAC_AOT_ER_LD;
  asc.frame_length = fl;
  uint8_t asc_bytes[16];
  const int asc_len = aac_asc_write(&asc, asc_bytes, sizeof(asc_bytes));
  e.asc.assign(asc_bytes, asc_bytes + std::max(asc_len, 0));
  unsigned seed = 77;
  for (int f = 0; f < 10; f++) {
    std::vector<uint8_t> au(2048);
    AacBitWriter w;
    aac_bitwriter_init(&w, au.data(), (int)au.size());
    aac_bitwriter_write(&w, 0, 4);   /* element_instance_tag */
    aac_bitwriter_write(&w, 100, 8); /* global_gain */
    aac_bitwriter_write(&w, 0, 1);   /* ics_reserved_bit */
    aac_bitwriter_write(&w, 0, 2);   /* ONLY_LONG_SEQUENCE */
    aac_bitwriter_write(&w, (uint32_t)shapes[f], 1);
    aac_bitwriter_write(&w, (uint32_t)num_swb, 6);
    aac_bitwriter_write(&w, 0, 1); /* predictor_data_present */
    aac_bitwriter_write(&w, 1, 4); /* one section of codebook 1 */
    aac_bitwriter_write(&w, 31, 5);
    aac_bitwriter_write(&w, (uint32_t)(num_swb - 31), 5);
    for (int b = 0; b < num_swb; b++) {
      aac_bitwriter_write_scalefactor(&w, 0);
    }
    aac_bitwriter_write(&w, 0, 3); /* no pulse, TNS or gain control data */
    for (int k = 0; k < fl; k += 4) {
      int q[4];
      for (int& v : q) {
        v = (int)((rnd(&seed) + 1.0f) * 1.5f) - 1;
        v = std::clamp(v, -1, 1);
      }
      aac_bitwriter_write_huffman(&w, 1, q);
    }
    aac_bitwriter_byte_align(&w);
    au.resize((size_t)aac_bitwriter_bytes_written(&w));
    e.aus.push_back(au);
  }
  const std::vector<uint8_t> file = mp4(e, 48000, 1);
  CHECK(write_file(path("shapes.mp4"), file.data(), file.size()), "write mp4");
  const std::string cmd = "ffmpeg -nostdin -v error -y -i '" + path("shapes.mp4") + "' -f f32le '" +
                          path("shapes.f32") + "'";
  CHECK(run_command(cmd), "ffmpeg refused the window shape stream");
  int errors = 0;
  const std::vector<float> ours = decode(e, 1, 1, &errors);
  const std::vector<float> theirs = read_f32(path("shapes.f32"));
  CHECK(errors == 0 && !theirs.empty() && theirs.size() == ours.size(),
        "window shapes: %d errors, %zu vs %zu samples", errors, theirs.size(), ours.size());
  const double d = snr_db(theirs.data(), ours.data(), std::min(ours.size(), theirs.size()));
  printf("LD 512 sine/low-overlap windows: ffmpeg vs ours %.1f dB\n", d);
  CHECK(d > 90.0, "window shapes: decoders disagree (%.1f dB)", d);
}

int main() {
  aac_tables_init();
  test_ld_tdac(512);
  test_ld_tdac(480);
  for (int m : {512, 480}) {
    test_eld_synthesis_definition(m);
    test_eld_reconstruction(m);
    test_eld_downscaled_synthesis(m, 2);
    test_eld_downscaled_synthesis(m, 4);
  }
  test_asc();
  test_api();
  test_extension_payload_budget();
  for (int fl : {512, 480}) {
    for (int sr : {48000, 32000}) {
      for (int channels : {1, 2}) {
        roundtrip(AAC_AOT_ER_LD, fl, sr, channels, AAC_TRANSPORT_LOAS);
        roundtrip(AAC_AOT_ER_LD, fl, sr, channels, AAC_TRANSPORT_RAW);
      }
    }
    roundtrip(AAC_AOT_ER_ELD, fl, 48000, 1, AAC_TRANSPORT_LOAS);
    roundtrip(AAC_AOT_ER_ELD, fl, 48000, 2, AAC_TRANSPORT_RAW);
    roundtrip(AAC_AOT_ER_ELD, fl, 32000, 2, AAC_TRANSPORT_LOAS);
    downscaled(fl, 2);
    downscaled(fl, 4);
  }
  concealment(AAC_AOT_ER_LD, 1);
  concealment(AAC_AOT_ER_ELD, 1);
  concealment(AAC_AOT_ER_ELD, 4);
  if (!have_ffmpeg()) {
    printf("ffmpeg not found: interoperability skipped\n");
    return test_summary("test_lowdelay");
  }
  g_dir = make_temp_dir();
  CHECK(!g_dir.empty(), "temp dir");
  /* FFmpeg 6.1 decodes AAC-LD with 480-sample frames through a 512-line
   * transform, so only the 512 frames of AAC-LD are compared. */
  ffmpeg_decodes(AAC_AOT_ER_LD, 512, 48000, 1);
  ffmpeg_decodes(AAC_AOT_ER_LD, 512, 44100, 2);
  ffmpeg_decodes(AAC_AOT_ER_LD, 512, 24000, 1);
  ffmpeg_decodes(AAC_AOT_ER_ELD, 512, 48000, 2);
  ffmpeg_decodes(AAC_AOT_ER_ELD, 480, 48000, 1);
  ffmpeg_decodes(AAC_AOT_ER_ELD, 512, 32000, 1);
  ffmpeg_decodes(AAC_AOT_ER_LD, 512, 48000, 6);
  ffmpeg_decodes(AAC_AOT_ER_ELD, 512, 48000, 6);
  ffmpeg_window_shapes();
  remove_temp_dir(g_dir);
  return test_summary("test_lowdelay");
}
