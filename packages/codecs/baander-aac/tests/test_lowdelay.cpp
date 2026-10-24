/* AAC-LD and AAC-ELD: the low-delay windows and filterbanks, the
 * AudioSpecificConfig of both object types, the settings of the public
 * API, encode/decode roundtrips over LOAS and raw access units, ELD
 * downscaled playout and, when ffmpeg is on PATH, FFmpeg's decode of our
 * streams. */
#include "aac_tables.h"
#include "asc.h"
#include "bitstream.h"
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
   * extension entry before the terminator: parsed, then refused by the
   * decoder because LD-SBR is not decoded. */
  AacAsc asc;
  aac_asc_init(&asc, 48000, 2);
  asc.aot = AAC_AOT_ER_ELD;
  asc.frame_length = 512;
  asc.ld_sbr_present = 1;
  asc.ld_sbr_sampling_rate = 0;
  asc.ld_sbr_crc = 0;
  asc.num_ld_sbr_headers = 1;
  asc.ld_sbr_header[0] = {};
  asc.ld_sbr_header[0].amp_res = 1;
  asc.ld_sbr_header[0].start_freq = 5;
  asc.ld_sbr_header[0].stop_freq = 9;
  uint8_t buf[32];
  const int len = aac_asc_write(&asc, buf, sizeof(buf));
  CHECK(len > 0, "ELD with LD-SBR: write %d", len);
  AacAsc back;
  CHECK(aac_asc_parse(buf, len, &back) >= 0 && back.ld_sbr_present == 1 &&
            back.num_ld_sbr_headers == 1 && back.ld_sbr_header[0].start_freq == 5 &&
            back.ld_sbr_header[0].stop_freq == 9,
        "ELD with LD-SBR: read back");
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  CHECK(aac_decoder_set_config(dec, buf, len) == AAC_ERR_UNSUPPORTED, "LD-SBR refused");
  /* An AAC-LD config with the section data resilience flag. */
  aac_asc_init(&asc, 48000, 1);
  asc.aot = AAC_AOT_ER_LD;
  asc.frame_length = 512;
  asc.section_resilience = 1;
  const int len2 = aac_asc_write(&asc, buf, sizeof(buf));
  CHECK(aac_asc_parse(buf, len2, &back) >= 0 && back.section_resilience == 1,
        "resilience flag read back");
  CHECK(aac_decoder_set_config(dec, buf, len2) == AAC_ERR_UNSUPPORTED, "resilience refused");
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
  CHECK(aac_encoder_set_ld_sbr(enc, 1, 1) == AAC_ERR_UNSUPPORTED, "LD-SBR not available");
  CHECK(aac_encoder_set_ld_sbr(enc, 0, 0) == AAC_OK, "LD-SBR off");
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
}

/* ── FFmpeg ────────────────────────────────────────────────────── */

static std::string g_dir;

static std::string path(const char* name) { return g_dir + "/" + name; }

static void put32(std::vector<uint8_t>& v, uint32_t x) {
  for (int s = 24; s >= 0; s -= 8) {
    v.push_back((uint8_t)(x >> s));
  }
}

static void put16(std::vector<uint8_t>& v, uint32_t x) {
  v.push_back((uint8_t)(x >> 8));
  v.push_back((uint8_t)x);
}

static std::vector<uint8_t> box(const char* type, const std::vector<uint8_t>& payload,
                                int full = -1, uint32_t flags = 0) {
  std::vector<uint8_t> v;
  put32(v, (uint32_t)(8 + payload.size() + (full >= 0 ? 4 : 0)));
  v.insert(v.end(), type, type + 4);
  if (full >= 0) {
    put32(v, ((uint32_t)full << 24) | flags);
  }
  v.insert(v.end(), payload.begin(), payload.end());
  return v;
}

static std::vector<uint8_t> descriptor(uint8_t tag, const std::vector<uint8_t>& payload) {
  std::vector<uint8_t> v = {tag, 0x80, 0x80, 0x80, (uint8_t)payload.size()};
  v.insert(v.end(), payload.begin(), payload.end());
  return v;
}

static std::vector<uint8_t> cat(std::initializer_list<std::vector<uint8_t>> parts) {
  std::vector<uint8_t> v;
  for (const auto& p : parts) {
    v.insert(v.end(), p.begin(), p.end());
  }
  return v;
}

/* A minimal MP4 file around raw access units: FFmpeg reads the
 * AudioSpecificConfig of a low-delay stream from the esds box (its LOAS
 * demuxer leaves the sample rate of error resilient streams unset). */
static std::vector<uint8_t> mp4(const Encoded& e, int rate, int channels) {
  const uint32_t n = (uint32_t)e.aus.size();
  const uint32_t duration = n * (uint32_t)e.frame;
  std::vector<uint8_t> dcd_payload = {0x40, 0x15, 0, 0, 0};
  put32(dcd_payload, 0);
  put32(dcd_payload, 0);
  const std::vector<uint8_t> dcd = descriptor(0x04, cat({dcd_payload, descriptor(0x05, e.asc)}));
  const std::vector<uint8_t> es = descriptor(0x03, cat({{0, 0, 0}, dcd, descriptor(0x06, {2})}));
  std::vector<uint8_t> mp4a_payload(6, 0);
  put16(mp4a_payload, 1);
  mp4a_payload.insert(mp4a_payload.end(), 8, 0);
  put16(mp4a_payload, (uint32_t)channels);
  put16(mp4a_payload, 16);
  put32(mp4a_payload, 0);
  put32(mp4a_payload, (uint32_t)rate << 16);
  const std::vector<uint8_t> mp4a = box("mp4a", cat({mp4a_payload, box("esds", es, 0)}));
  std::vector<uint8_t> v;
  put32(v, 1);
  const std::vector<uint8_t> stsd = box("stsd", cat({v, mp4a}), 0);
  v.clear();
  put32(v, 1);
  put32(v, n);
  put32(v, (uint32_t)e.frame);
  const std::vector<uint8_t> stts = box("stts", v, 0);
  v.clear();
  put32(v, 1);
  put32(v, 1);
  put32(v, n);
  put32(v, 1);
  const std::vector<uint8_t> stsc = box("stsc", v, 0);
  v.clear();
  put32(v, 0);
  put32(v, n);
  for (const auto& au : e.aus) {
    put32(v, (uint32_t)au.size());
  }
  const std::vector<uint8_t> stsz = box("stsz", v, 0);
  std::vector<uint8_t> matrix;
  for (uint32_t x : {0x10000u, 0u, 0u, 0u, 0x10000u, 0u, 0u, 0u, 0x40000000u}) {
    put32(matrix, x);
  }
  std::vector<uint8_t> ftyp_payload = {'i', 's', 'o', 'm'};
  put32(ftyp_payload, 0x200);
  for (const char* b : {"isom", "iso2", "mp41"}) {
    ftyp_payload.insert(ftyp_payload.end(), b, b + 4);
  }
  const std::vector<uint8_t> ftyp = box("ftyp", ftyp_payload);
  auto moov = [&](uint32_t offset) {
    std::vector<uint8_t> co;
    put32(co, 1);
    put32(co, offset);
    const std::vector<uint8_t> stbl =
        box("stbl", cat({stsd, stts, stsc, stsz, box("stco", co, 0)}));
    std::vector<uint8_t> dref_payload;
    put32(dref_payload, 1);
    const std::vector<uint8_t> dinf =
        box("dinf", box("dref", cat({dref_payload, box("url ", {}, 0, 1)}), 0));
    const std::vector<uint8_t> minf = box("minf", cat({box("smhd", {0, 0, 0, 0}, 0), dinf, stbl}));
    std::vector<uint8_t> hdlr_payload(4, 0);
    hdlr_payload.insert(hdlr_payload.end(), {'s', 'o', 'u', 'n'});
    hdlr_payload.insert(hdlr_payload.end(), 12, 0);
    const char* handler = "SoundHandler";
    hdlr_payload.insert(hdlr_payload.end(), handler, handler + strlen(handler) + 1);
    std::vector<uint8_t> mdhd_payload;
    put32(mdhd_payload, 0);
    put32(mdhd_payload, 0);
    put32(mdhd_payload, (uint32_t)rate);
    put32(mdhd_payload, duration);
    put16(mdhd_payload, 0x55c4);
    put16(mdhd_payload, 0);
    const std::vector<uint8_t> mdia =
        box("mdia", cat({box("mdhd", mdhd_payload, 0), box("hdlr", hdlr_payload, 0), minf}));
    const uint32_t ms = (uint32_t)((uint64_t)duration * 1000 / rate);
    std::vector<uint8_t> tkhd_payload;
    for (uint32_t x : {0u, 0u, 1u, 0u, ms, 0u, 0u}) {
      put32(tkhd_payload, x);
    }
    put16(tkhd_payload, 0);
    put16(tkhd_payload, 0);
    put16(tkhd_payload, 0x100);
    put16(tkhd_payload, 0);
    tkhd_payload.insert(tkhd_payload.end(), matrix.begin(), matrix.end());
    put32(tkhd_payload, 0);
    put32(tkhd_payload, 0);
    const std::vector<uint8_t> trak = box("trak", cat({box("tkhd", tkhd_payload, 0, 3), mdia}));
    std::vector<uint8_t> mvhd_payload;
    for (uint32_t x : {0u, 0u, 1000u, ms, 0x10000u}) {
      put32(mvhd_payload, x);
    }
    put16(mvhd_payload, 0x100);
    mvhd_payload.insert(mvhd_payload.end(), 10, 0);
    mvhd_payload.insert(mvhd_payload.end(), matrix.begin(), matrix.end());
    mvhd_payload.insert(mvhd_payload.end(), 24, 0);
    put32(mvhd_payload, 2);
    return box("moov", cat({box("mvhd", mvhd_payload, 0), trak}));
  };
  const size_t offset = ftyp.size() + moov(0).size() + 8;
  std::vector<uint8_t> mdat_payload;
  for (const auto& au : e.aus) {
    mdat_payload.insert(mdat_payload.end(), au.begin(), au.end());
  }
  return cat({ftyp, moov((uint32_t)offset), box("mdat", mdat_payload)});
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
