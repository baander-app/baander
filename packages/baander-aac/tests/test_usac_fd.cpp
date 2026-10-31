/* The USAC frequency-domain core: the arithmetic decoder against a
 * matching encoder written here (a consistency check of the decoder
 * logic, not a proof of the standard's tables), the window shapes, the
 * MDST estimate against the true MDST, the complex prediction on
 * constructed spectra, and the decode of FDK-encoded FD-only streams
 * against the FDK decoder's output, driving the LATM payload here.
 *
 * With arguments the program decodes a LOAS file instead:
 *   test_usac_fd stream.loas [reference.wav|-] [out.f32] [-v]
 * prints the configuration and per-frame notes (every access unit's walk
 * with -v), the aligned SNR against the reference and writes the decoded
 * float32 PCM. */
#include "bitstream.h"
#include "latm.h"
#include "mdct.h"
#include "test_util.h"
#include "usac_fd.h"

/* ── an arithmetic encoder matching the decoder ─────────────────── */

struct ArithEncoder {
  AacBitWriter* w;
  uint32_t low = 0, high = 65535;
  int follow = 0;

  void bit_plus_follow(int bit) {
    aac_bitwriter_write(w, (uint32_t)bit, 1);
    while (follow > 0) {
      aac_bitwriter_write(w, (uint32_t)!bit, 1);
      follow--;
    }
  }
  void encode(const uint16_t* cf, int symbol) {
    const uint32_t range = high - low + 1;
    const uint32_t upper = symbol == 0 ? 16384u : cf[symbol - 1];
    high = low + ((range * upper) >> 14) - 1;
    low = low + ((range * cf[symbol]) >> 14);
    for (;;) {
      if (high < 32768) {
        bit_plus_follow(0);
      } else if (low >= 32768) {
        bit_plus_follow(1);
        low -= 32768;
        high -= 32768;
      } else if (low >= 16384 && high < 49152) {
        follow++;
        low -= 16384;
        high -= 16384;
      } else {
        break;
      }
      low <<= 1;
      high = (high << 1) | 1;
    }
  }
  void done() {
    follow++;
    bit_plus_follow(low < 16384 ? 0 : 1);
  }
};

/* Encodes arith_data() of one window from the quantised lines x[0..lg),
 * with the same context modelling as the decoder (the context state is
 * kept in an AacUsacArithContext driven in parallel). */
static void arith_encode_window(AacBitWriter* w, AacUsacArithContext* c, int reset, int n, int lg,
                                const int* x) {
  const int tuples = n / 2;
  if (reset || c->prev_n == 0) {
    memset(c->prev, 0, sizeof(c->prev));
  } else if (c->prev_n != n) {
    uint8_t mapped[AAC_USAC_ARITH_MAX_TUPLES + 4];
    memset(mapped, 0, sizeof(mapped));
    const float ratio = (float)c->prev_n / (float)n;
    for (int i = 0; i < tuples; i++) {
      mapped[i] = c->prev[(int)((float)i * ratio)];
    }
    memcpy(c->prev, mapped, sizeof(mapped));
  }
  c->prev_n = n;
  int cur[4] = {1, 0, 0, 0};
  uint32_t pre = (uint32_t)c->prev[0] << 12;
  ArithEncoder enc;
  enc.w = w;
  /* the last tuple that is not zero, after which arith_stop is sent */
  int last = -1;
  for (int i = 0; i < lg / 2; i++) {
    if (x[2 * i] || x[2 * i + 1]) {
      last = i;
    }
  }
  int i = 0;
  for (; i < lg / 2; i++) {
    uint32_t ctx = pre >> 8;
    ctx += (uint32_t)c->prev[i + 1] << 8;
    ctx <<= 4;
    ctx += (uint32_t)cur[1];
    pre = ctx;
    if (i > 3 && cur[1] + cur[2] + cur[3] < 5) {
      ctx += 0x10000;
    }
    if (i > last) {
      /* arith_stop: an escape followed by symbol 0 */
      enc.encode(aac_usac_ari_cf_m[aac_usac_arith_model(ctx)], AAC_USAC_ARITH_ESCAPE);
      enc.encode(aac_usac_ari_cf_m[aac_usac_arith_model(ctx + (1u << 17))], 0);
      break;
    }
    const int a0 = abs(x[2 * i]), b0 = abs(x[2 * i + 1]);
    int a = a0, b = b0, lev = 0;
    while (a > 3 || b > 3) {
      a >>= 1;
      b >>= 1;
      lev++;
    }
    for (int l = 0; l < lev; l++) {
      const uint32_t s = ctx + ((uint32_t)(l > 7 ? 7 : l) << 17);
      enc.encode(aac_usac_ari_cf_m[aac_usac_arith_model(s)], AAC_USAC_ARITH_ESCAPE);
    }
    const uint32_t s = ctx + ((uint32_t)(lev > 7 ? 7 : lev) << 17);
    enc.encode(aac_usac_ari_cf_m[aac_usac_arith_model(s)], a + 4 * b);
    /* the remaining bit planes from the most significant down, as the
     * decoder rebuilds the values */
    for (int l = lev - 1; l >= 0; l--) {
      const int ha = a0 >> (l + 1), hb = b0 >> (l + 1); /* value known so far */
      const int lsbidx = ha == 0 ? 1 : (hb == 0 ? 0 : 2);
      const int bits = ((a0 >> l) & 1) | (((b0 >> l) & 1) << 1);
      enc.encode(aac_usac_ari_cf_r[lsbidx], bits);
    }
    int q = a0 + b0 + 1;
    if (q > 0xF) {
      q = 0xF;
    }
    cur[3] = cur[2];
    cur[2] = cur[1];
    cur[1] = q;
    c->prev[i] = (uint8_t)q;
  }
  for (int k = i; k < tuples; k++) {
    c->prev[k] = 1;
  }
  for (int k = tuples; k < AAC_USAC_ARITH_MAX_TUPLES + 4; k++) {
    c->prev[k] = 0;
  }
  enc.done();
  /* one raw bit per nonzero line, 1 for a positive one */
  for (int k = 0; k < lg; k++) {
    if (x[k]) {
      aac_bitwriter_write(w, x[k] < 0 ? 0u : 1u, 1);
    }
  }
}

static void test_arith_round_trip() {
  unsigned seed = 11;
  const int lengths[3] = {1024, 128, 768};
  AacUsacArithContext enc_ctx, dec_ctx;
  aac_usac_arith_reset(&enc_ctx);
  aac_usac_arith_reset(&dec_ctx);
  for (int trial = 0; trial < 60; trial++) {
    const int n = lengths[trial % 3];
    const int lg = (trial % 5 == 0) ? n : (n / 4) * 2 + 2 * (trial % 7);
    std::vector<int> x(n, 0);
    /* a spectrum with dense small values, sparse large ones and a quiet
     * tail; some windows are silent */
    const int kind = trial % 4;
    for (int k = 0; k < lg; k++) {
      seed = seed * 1664525u + 1013904223u;
      const int r = (int)(seed >> 8) % 1000;
      if (kind == 3) {
        x[k] = 0;
      } else if (k > lg * 3 / 4) {
        x[k] = r < 50 ? 1 : 0;
      } else if (r < 400) {
        x[k] = 0;
      } else if (r < 900) {
        x[k] = (int)(seed >> 20) % 7 - 3;
      } else if (r < 990) {
        x[k] = (int)(seed >> 20) % 61 - 30;
      } else {
        x[k] = (int)(seed >> 16) % 8191 - 4095;
      }
    }
    if (trial == 7) {
      x[0] = 8191;
      x[1] = -8191;
    }
    std::vector<uint8_t> buf(16384);
    AacBitWriter w;
    aac_bitwriter_init(&w, buf.data(), (int)buf.size());
    aac_bitwriter_write(&w, 0x5A5, 11); /* leading bits of another field */
    const int reset = trial % 9 == 0;
    arith_encode_window(&w, &enc_ctx, reset, n, lg, x.data());
    aac_bitwriter_write(&w, 0x2C, 6); /* a trailing field */
    aac_bitwriter_byte_align(&w);
    CHECK(!aac_bitwriter_overflow(&w), "writer overflow");

    AacBitReader r;
    aac_bitreader_init(&r, buf.data(), aac_bitwriter_bytes_written(&w));
    CHECK(aac_bitreader_read(&r, 11) == 0x5A5, "prefix");
    std::vector<int> y(n, -1);
    const int ret = aac_usac_arith_decode(&dec_ctx, reset, n, lg, &r, y.data());
    CHECK(ret == AAC_OK, "trial %d: decode returned %d", trial, ret);
    int diffs = 0;
    for (int k = 0; k < n; k++) {
      if (x[k] != y[k] && diffs < 4) {
        printf("trial %d: line %d is %d, decoded %d\n", trial, k, x[k], y[k]);
      }
      diffs += x[k] != y[k];
    }
    CHECK(diffs == 0, "trial %d (n %d lg %d): %d lines differ", trial, n, lg, diffs);
    CHECK(aac_bitreader_read(&r, 6) == 0x2C, "trial %d: the field after the data is misread",
          trial);
    CHECK(memcmp(enc_ctx.prev, dec_ctx.prev, sizeof(enc_ctx.prev)) == 0,
          "trial %d: the contexts diverge", trial);
  }
}

static void test_arith_tables_shape() {
  for (int m = 0; m < 64; m++) {
    const uint16_t* cf = aac_usac_ari_cf_m[m];
    CHECK(cf[16] == 0 && cf[0] <= 16384, "model %d: bounds", m);
    for (int i = 0; i < 16; i++) {
      CHECK(cf[i] >= cf[i + 1], "model %d: not decreasing at %d", m, i);
    }
  }
  for (int i = 0; i < 741; i++) {
    CHECK((aac_usac_ari_hash_m[i] >> 8) < (aac_usac_ari_hash_m[i + 1] >> 8), "hash order %d", i);
  }
  for (int i = 0; i < 742; i++) {
    CHECK((aac_usac_ari_hash_m[i] & 0xFF) < 64 && aac_usac_ari_lookup_m[i] < 64, "model range %d",
          i);
  }
  /* the all-zero context takes the first model, whose zero symbol is by
   * far the most probable */
  CHECK(aac_usac_arith_model(0) < 64 && aac_usac_arith_model(0xFFFFFFFFu) < 64, "model range");
}

/* ── windows ───────────────────────────────────────────────────── */

static void test_window_shapes() {
  const int lengths[4] = {1024, 768, 128, 96};
  for (int li = 0; li < 4; li++) {
    const int n = lengths[li];
    std::vector<float> sine(2 * n), kbd(2 * n);
    aac_sine_window(sine.data(), 2 * n);
    aac_kbd_window(kbd.data(), 2 * n, n >= 768 ? AAC_KBD_ALPHA_LONG : AAC_KBD_ALPHA_SHORT);
    double err_s = 0.0, err_k = 0.0;
    for (int i = 0; i < n; i++) {
      err_s = std::max(err_s, fabs(sine[i] * sine[i] + sine[n - 1 - i] * sine[n - 1 - i] - 1.0));
      err_k = std::max(err_k, fabs(kbd[i] * kbd[i] + kbd[n - 1 - i] * kbd[n - 1 - i] - 1.0));
    }
    CHECK(err_s < 1e-5, "sine %d: power complementarity error %.3g", n, err_s);
    CHECK(err_k < 1e-5, "kbd %d: power complementarity error %.3g", n, err_k);
    CHECK(kbd[0] < sine[0] && kbd[n / 2] > sine[n / 2], "kbd %d: shape", n);
  }
}

/* ── the MDST estimate ─────────────────────────────────────────── */

static void mdct_mdst(const float* z, int n, float* X, float* S) {
  const double n0 = n / 4.0 + 0.5;
  for (int k = 0; k < n / 2; k++) {
    double c = 0.0, s = 0.0;
    for (int j = 0; j < n; j++) {
      const double arg = 2.0 * M_PI / n * (j + n0) * (k + 0.5);
      c += z[j] * cos(arg);
      s += z[j] * sin(arg);
    }
    X[k] = (float)(2.0 * c);
    S[k] = (float)(2.0 * s);
  }
}

static double estimate_snr(const float* truth, const float* est, int n) {
  double sig = 0.0, err = 0.0;
  for (int k = 0; k < n; k++) {
    sig += (double)truth[k] * truth[k];
    err += (double)(truth[k] - est[k]) * (truth[k] - est[k]);
  }
  return 10.0 * log10(sig / std::max(err, 1e-30));
}

/* The estimate from the current and the previous window's MDCT must
 * approach the true MDST of the windowed signal; a wrong sign or
 * orientation of a filter gives a negative SNR. */
static void test_mdst_estimate() {
  const int n = 2048; /* the long window of a 1024 frame */
  const int half = n / 2;
  /* a stationary multi-tone signal between 100 Hz and 12 kHz at 48 kHz,
   * the case the filters are made for */
  std::vector<float> x(3 * half + n);
  unsigned seed = 5;
  for (int tone = 0; tone < 24; tone++) {
    seed = seed * 1664525u + 1013904223u;
    const double f = (100.0 + 11900.0 * (double)(seed >> 8) / 16777216.0) / 48000.0;
    seed = seed * 1664525u + 1013904223u;
    const double phase = 6.2831853 * (double)(seed >> 8) / 16777216.0;
    seed = seed * 1664525u + 1013904223u;
    const double amp = 0.05 + 0.2 * (double)(seed >> 8) / 16777216.0;
    for (size_t i = 0; i < x.size(); i++) {
      x[i] += (float)(amp * sin(6.2831853 * f * (double)i + phase));
    }
  }
  for (int shape = 0; shape < 2; shape++) {
    std::vector<float> w(n);
    if (shape == 0) {
      aac_sine_window(w.data(), n);
    } else {
      aac_kbd_window(w.data(), n, AAC_KBD_ALPHA_LONG);
    }
    std::vector<float> z(n), X0(half), S0(half), X1(half), S1(half), est(half);
    for (int j = 0; j < n; j++) {
      z[j] = x[j] * w[j];
    }
    mdct_mdst(z.data(), n, X0.data(), S0.data());
    for (int j = 0; j < n; j++) {
      z[j] = x[half + j] * w[j];
    }
    mdct_mdst(z.data(), n, X1.data(), S1.data());
    aac_usac_fd_mdst_estimate(X1.data(), nullptr, half, AAC_WIN_ONLY_LONG, shape, shape,
                              est.data());
    const double snr_cur = estimate_snr(S1.data(), est.data(), half);
    aac_usac_fd_mdst_estimate(X1.data(), X0.data(), half, AAC_WIN_ONLY_LONG, shape, shape,
                              est.data());
    const double snr_both = estimate_snr(S1.data(), est.data(), half);
    printf("mdst estimate, %s window: current only %.1f dB, with previous %.1f dB\n",
           shape ? "kbd" : "sine", snr_cur, snr_both);
    /* Seven taps over two windows give a coarse estimate (about 2 dB
     * from the current window alone, 6 dB with the previous one, by a
     * least-squares fit of the same filter lengths); the signs and the
     * orientation of the filters show in these margins. */
    CHECK(snr_cur > 1.0, "%s: the current-window estimate does not follow the MDST (%.1f dB)",
          shape ? "kbd" : "sine", snr_cur);
    CHECK(snr_both > snr_cur + 2.0, "%s: the previous window does not improve the estimate",
          shape ? "kbd" : "sine");
  }
}

static void test_cplx_prediction() {
  const int len = 16;
  float l[len], r[len], dmx[len], res[len], im[len];
  unsigned seed = 99;
  for (int k = 0; k < len; k++) {
    seed = seed * 1664525u + 1013904223u;
    l[k] = (float)(seed >> 8) / 8388608.0f - 1.0f;
    seed = seed * 1664525u + 1013904223u;
    r[k] = (float)(seed >> 8) / 8388608.0f - 1.0f;
    seed = seed * 1664525u + 1013904223u;
    im[k] = (float)(seed >> 8) / 8388608.0f - 1.0f;
  }
  for (int dir = 0; dir < 2; dir++) {
    const float alpha_re = 0.7f, alpha_im = -0.4f;
    float ch0[len], ch1[len];
    for (int k = 0; k < len; k++) {
      const float mid = 0.5f * (l[k] + r[k]);
      const float side = 0.5f * (l[k] - r[k]);
      dmx[k] = dir ? side : mid;
      /* the encoder leaves the residual after the prediction */
      res[k] = (dir ? mid : side) + alpha_re * dmx[k] + alpha_im * im[k];
      ch0[k] = dmx[k];
      ch1[k] = res[k];
    }
    aac_usac_fd_cplx_predict(ch0, ch1, dmx, im, len, alpha_re, alpha_im, dir);
    double err = 0.0;
    for (int k = 0; k < len; k++) {
      err = std::max(err, (double)std::max(fabsf(ch0[k] - l[k]), fabsf(ch1[k] - r[k])));
    }
    CHECK(err < 1e-5, "pred_dir %d: reconstruction error %.3g", dir, err);
  }
  /* M/S without prediction is the alpha = 0 case */
  float ch0[len], ch1[len];
  for (int k = 0; k < len; k++) {
    ch0[k] = 0.5f * (l[k] + r[k]);
    ch1[k] = 0.5f * (l[k] - r[k]);
  }
  aac_usac_fd_cplx_predict(ch0, ch1, ch0, im, len, 0.0f, 0.0f, 0);
  CHECK(fabsf(ch0[3] - l[3]) < 1e-6f && fabsf(ch1[5] - r[5]) < 1e-6f, "plain M/S");
}

/* The 768-sample frame length: channels are created with the shorter
 * band tables and the concealment path runs the filterbank at that
 * length. */
static void test_frame_768() {
  AacUsacCoreChannel ch;
  memset(&ch, 0, sizeof(ch));
  ch.prev_core_mode = -1;
  ch.noise_seed = 0x3039u;
  ch.fd = aac_usac_fd_channel_create(768, 48000);
  CHECK(ch.fd != nullptr, "768-frame channel");
  CHECK(aac_usac_fd_channel_create(960, 48000) == nullptr, "960 is not a USAC frame length");
  if (!ch.fd) {
    return;
  }
  std::vector<float> out(768, 1.0f);
  AacUsacCoreChannel* chs[1] = {&ch};
  float* outs[1] = {out.data()};
  aac_usac_core_conceal(chs, 1, 768, outs);
  float energy = 0.0f;
  for (float v : out) {
    energy += v * v;
  }
  CHECK(energy == 0.0f, "concealment of a silent 768 channel is silent (%g)", energy);
  CHECK(ch.prev_core_mode == 0, "concealment leaves an FD frame behind");
  aac_usac_fd_channel_destroy(ch.fd);
}

/* ── the LATM payload and the USAC frame ───────────────────────── */

static unsigned escaped(AacBitReader* r, int b1, int b2, int b3) {
  unsigned v = aac_bitreader_read(r, b1);
  if (v == (1u << b1) - 1) {
    const unsigned v2 = aac_bitreader_read(r, b2);
    v += v2;
    if (b3 && v2 == (1u << b2) - 1) {
      v += aac_bitreader_read(r, b3);
    }
  }
  return v;
}

/* usacExtElementPayloadLength: 8 bits, and when they read 255 a 16-bit
 * value is added minus 2, so the escaped form starts at 255 (the FDK
 * encoder's AudioPreRoll elements rely on the minus 2). */
static int ext_payload_length(AacBitReader* r) {
  int len = (int)aac_bitreader_read(r, 8);
  if (len == 255) {
    len += (int)aac_bitreader_read(r, 16) - 2;
  }
  return len;
}

struct UsacElem {
  int type; /* 0 SCE, 1 CPE, 2 LFE, 3 EXT */
  int noise_filling;
  int stereo_config_index;
  int ext_type;
  int ext_default_length; /* 0 when absent */
  int ext_payload_frag;
};

struct UsacCfg {
  int sample_rate = 0;
  int frame_length = 0;
  int sbr_ratio_index = 0;
  int channel_config = 0;
  int channels = 0;
  std::vector<UsacElem> elems;
};

static const int kRates[] = {96000, 88200, 64000, 48000, 44100, 32000, 24000,
                             22050, 16000, 12000, 11025, 8000,  7350};

static void skip_sbr_config(AacBitReader* r) {
  aac_bitreader_skip(r, 3); /* harmonicSBR, bs_interTes, bs_pvc */
  aac_bitreader_skip(r, 8); /* start and stop frequency */
  const int extra1 = (int)aac_bitreader_read(r, 1);
  const int extra2 = (int)aac_bitreader_read(r, 1);
  if (extra1) {
    aac_bitreader_skip(r, 5);
  }
  if (extra2) {
    aac_bitreader_skip(r, 6);
  }
}

static void skip_mps212_config(AacBitReader* r, int sci) {
  aac_bitreader_skip(r, 3 + 3); /* bsFreqRes, bsFixedGainDMX */
  const int temp_shape_config = (int)aac_bitreader_read(r, 2);
  aac_bitreader_skip(r, 2 + 1 + 1); /* bsDecorrConfig, bsHighRateMode, bsPhaseCoding */
  if (aac_bitreader_read(r, 1)) {   /* bsOttBandsPhasePresent */
    aac_bitreader_skip(r, 5);
  }
  if (sci == 2 || sci == 3) {
    aac_bitreader_skip(r, 5 + 1); /* bsResidualBands, bsPseudoLr */
  }
  if (temp_shape_config == 2) {
    aac_bitreader_skip(r, 1); /* bsEnvQuantMode */
  }
}

/* LatmGetValue(): a byte count and that many bytes. */
static unsigned latm_value(AacBitReader* r) {
  const int nb = (int)aac_bitreader_read(r, 2);
  unsigned v = 0;
  for (int i = 0; i <= nb; i++) {
    v = (v << 8) | aac_bitreader_read(r, 8);
  }
  return v;
}

/* AudioSpecificConfig of a USAC stream: the object type, the rate and
 * channel fields and UsacConfig() (ISO/IEC 23003-3 5.x). */
static bool parse_usac_asc(AacBitReader* r, UsacCfg* cfg) {
  *cfg = UsacCfg();
  unsigned aot = aac_bitreader_read(r, 5);
  if (aot == 31) {
    aot = 32 + aac_bitreader_read(r, 6);
  }
  if (aot != 42) {
    printf("object type %u is not USAC\n", aot);
    return false;
  }
  unsigned sfi = aac_bitreader_read(r, 4);
  if (sfi == 15) {
    aac_bitreader_skip(r, 24);
  }
  aac_bitreader_skip(r, 4); /* channelConfiguration */
  /* UsacConfig */
  unsigned usfi = aac_bitreader_read(r, 5);
  cfg->sample_rate = usfi == 0x1f ? (int)aac_bitreader_read(r, 24) : (usfi < 13 ? kRates[usfi] : 0);
  const int csfli = (int)aac_bitreader_read(r, 3);
  static const int frame_len[8] = {768, 1024, 768, 768, 768, 1024, 1024, 1024};
  static const int sbr_ratio[8] = {0, 0, 2, 3, 1, 1, 2, 0};
  cfg->frame_length = frame_len[csfli];
  cfg->sbr_ratio_index = sbr_ratio[csfli];
  cfg->channel_config = (int)aac_bitreader_read(r, 5);
  static const int cfg_channels[21] = {0, 1, 2,  3, 4,  5,  6,  8,  2,  3, 4,
                                       7, 8, 24, 8, 12, 10, 12, 14, 12, 14};
  if (cfg->channel_config == 0) {
    const unsigned num = escaped(r, 5, 8, 16);
    for (unsigned i = 0; i < num; i++) {
      aac_bitreader_skip(r, 5);
    }
    cfg->channels = (int)num;
  } else if (cfg->channel_config < 21) {
    cfg->channels = cfg_channels[cfg->channel_config];
  }
  const unsigned num_elems = escaped(r, 4, 8, 16) + 1;
  for (unsigned e = 0; e < num_elems; e++) {
    UsacElem el;
    memset(&el, 0, sizeof(el));
    el.type = (int)aac_bitreader_read(r, 2);
    if (el.type == 0 || el.type == 1) {
      const int tw = (int)aac_bitreader_read(r, 1);
      el.noise_filling = (int)aac_bitreader_read(r, 1);
      if (tw) {
        printf("tw_mdct is not supported\n");
        return false;
      }
      if (cfg->sbr_ratio_index > 0) {
        skip_sbr_config(r);
      }
      if (el.type == 1) {
        el.stereo_config_index = cfg->sbr_ratio_index > 0 ? (int)aac_bitreader_read(r, 2) : 0;
        if (el.stereo_config_index > 0) {
          skip_mps212_config(r, el.stereo_config_index);
        }
      }
    } else if (el.type == 3) {
      el.ext_type = (int)escaped(r, 4, 8, 16);
      const unsigned cfg_len = escaped(r, 4, 8, 16);
      if (aac_bitreader_read(r, 1)) {
        el.ext_default_length = (int)escaped(r, 8, 16, 0) + 1;
      }
      el.ext_payload_frag = (int)aac_bitreader_read(r, 1);
      aac_bitreader_skip(r, 8 * (int)cfg_len);
    }
    cfg->elems.push_back(el);
  }
  if (aac_bitreader_read(r, 1)) {
    const unsigned num_ext = escaped(r, 2, 4, 8) + 1;
    for (unsigned i = 0; i < num_ext; i++) {
      (void)escaped(r, 4, 8, 16);
      const unsigned len = escaped(r, 4, 8, 16);
      aac_bitreader_skip(r, 8 * (int)len);
    }
  }
  return !r->overread;
}

static std::vector<uint8_t> copy_bytes(AacBitReader* r, int count) {
  std::vector<uint8_t> out((size_t)count);
  for (int i = 0; i < count; i++) {
    out[(size_t)i] = (uint8_t)aac_bitreader_read(r, 8);
  }
  return out;
}

/* One AudioMuxElement(1) of a LOAS frame: the StreamMuxConfig when it
 * carries one, then the single access unit, copied out byte aligned. */
static bool parse_latm(const uint8_t* data, int size, UsacCfg* cfg, bool* have_cfg,
                       std::vector<uint8_t>* au) {
  AacBitReader r;
  aac_bitreader_init(&r, data, size);
  const int use_same = (int)aac_bitreader_read(&r, 1);
  if (!use_same) {
    const int version = (int)aac_bitreader_read(&r, 1);
    int version_a = 0;
    if (version) {
      version_a = (int)aac_bitreader_read(&r, 1);
    }
    if (version_a) {
      return false;
    }
    if (version) {
      (void)latm_value(&r); /* taraBufferFullness */
    }
    aac_bitreader_skip(&r, 1); /* allStreamsSameTimeFraming */
    const int num_sub_frames = (int)aac_bitreader_read(&r, 6);
    const int num_program = (int)aac_bitreader_read(&r, 4);
    const int num_layer = (int)aac_bitreader_read(&r, 3);
    if (num_sub_frames || num_program || num_layer) {
      return false;
    }
    if (version) {
      const unsigned len = latm_value(&r); /* ascLen */
      const int start = r.pos;
      if (!parse_usac_asc(&r, cfg)) {
        return false;
      }
      r.pos = start + (int)len;
    } else if (!parse_usac_asc(&r, cfg)) {
      return false;
    }
    const int frame_length_type = (int)aac_bitreader_read(&r, 3);
    if (frame_length_type != 0) {
      return false;
    }
    aac_bitreader_skip(&r, 8);       /* latmBufferFullness */
    if (aac_bitreader_read(&r, 1)) { /* otherDataPresent */
      if (version) {
        (void)latm_value(&r);
      } else {
        int esc;
        do {
          esc = (int)aac_bitreader_read(&r, 1);
          aac_bitreader_skip(&r, 8);
        } while (esc);
      }
    }
    if (aac_bitreader_read(&r, 1)) { /* crcCheckPresent */
      aac_bitreader_skip(&r, 8);
    }
    *have_cfg = true;
  } else if (!*have_cfg) {
    return false;
  }
  int len = 0, tmp;
  do {
    tmp = (int)aac_bitreader_read(&r, 8);
    len += tmp;
  } while (tmp == 255);
  if (len * 8 > aac_bitreader_bits_left(&r)) {
    return false;
  }
  *au = copy_bytes(&r, len);
  return true;
}

/* Set by the command line to print every access unit's walk. */
static bool g_trace = false;

struct UsacDecoder {
  UsacCfg cfg;
  std::vector<AacUsacCoreChannel*> core;
  int lpd_frames = 0;
  int fd_frames = 0;
  bool first = true;

  ~UsacDecoder() {
    for (AacUsacCoreChannel* c : core) {
      aac_usac_fd_channel_destroy(c->fd);
      delete c;
    }
  }
  bool init() {
    for (const UsacElem& e : cfg.elems) {
      const int n = e.type == 1 ? 2 : (e.type == 3 ? 0 : 1);
      for (int i = 0; i < n; i++) {
        AacUsacCoreChannel* c = new AacUsacCoreChannel;
        memset(c, 0, sizeof(*c));
        c->prev_core_mode = -1;
        c->noise_seed = i ? 0x10932u : 0x3039u;
        c->fd = aac_usac_fd_channel_create(cfg.frame_length, cfg.sample_rate);
        if (!c->fd) {
          return false;
        }
        core.push_back(c);
      }
    }
    return !core.empty();
  }
  /* Decodes one access unit into `out` (channels x frame_length,
   * interleaved).  Returns AAC_OK, AAC_ERR_UNSUPPORTED at an LPD frame,
   * or the decode error. */
  int decode_au(const uint8_t* au, int size, std::vector<float>* out, bool discard) {
    AacBitReader r;
    aac_bitreader_init(&r, au, size);
    const int indep = (int)aac_bitreader_read(&r, 1);
    const int fl = cfg.frame_length;
    const bool dbg = g_trace;
    if (dbg) {
      printf("au of %d bytes: indep %d%s\n", size, indep, discard ? " (preroll)" : "");
    }
    std::vector<float> buf((size_t)fl * (size_t)core.size());
    std::vector<float*> outs;
    for (size_t c = 0; c < core.size(); c++) {
      outs.push_back(buf.data() + c * (size_t)fl);
    }
    size_t ch = 0;
    for (const UsacElem& e : cfg.elems) {
      if (e.type == 3) {
        if (!aac_bitreader_read(&r, 1)) {
          continue;
        }
        const int use_default = (int)aac_bitreader_read(&r, 1);
        int len = use_default ? e.ext_default_length : ext_payload_length(&r);
        if (e.ext_payload_frag) {
          aac_bitreader_skip(&r, 2);
        }
        if (len * 8 > aac_bitreader_bits_left(&r)) {
          return AAC_ERR_DECODE;
        }
        std::vector<uint8_t> payload = copy_bytes(&r, len);
        if (dbg) {
          printf("  ext %d: %d bytes, now at bit %d\n", e.ext_type, len, r.pos);
        }
        if (e.ext_type == 3 && first) {
          const int ret = decode_preroll(payload);
          if (ret != AAC_OK) {
            return ret;
          }
        }
        continue;
      }
      const int n = e.type == 1 ? 2 : 1;
      if (e.type != 2 && aac_bitreader_peek(&r, n) != 0) {
        lpd_frames++;
        return AAC_ERR_UNSUPPORTED;
      }
      const int start = r.pos;
      const int ret = aac_usac_core_decode(&core[ch], n, e.type == 2, e.noise_filling, fl, indep,
                                           &r, &outs[ch]);
      if (dbg) {
        printf("  element type %d (ch %zu of %zu): bits %d to %d of %d, ret %d\n", e.type, ch,
               core.size(), start, r.pos, 8 * size, ret);
      }
      if (ret != AAC_OK) {
        return ret;
      }
      ch += (size_t)n;
    }
    first = false;
    /* the frame ends within the last byte */
    const int left = aac_bitreader_bits_left(&r);
    if (left < 0 || left > 7) {
      printf("note: %d bits left after the frame\n", left);
      if (dbg) {
        printf("  next bytes:");
        for (int i = r.pos / 8; i < size && i < r.pos / 8 + 24; i++) {
          printf(" %02x", au[i]);
        }
        printf("\n");
      }
    }
    fd_frames++;
    if (!discard) {
      const size_t nch = core.size();
      for (int i = 0; i < fl; i++) {
        for (size_t c = 0; c < nch; c++) {
          out->push_back(buf[c * (size_t)fl + (size_t)i]);
        }
      }
    }
    return AAC_OK;
  }
  /* AudioPreRoll(): the configuration and the access units that warm up
   * the decoder; their output is dropped. */
  int decode_preroll(const std::vector<uint8_t>& payload) {
    AacBitReader r;
    aac_bitreader_init(&r, payload.data(), (int)payload.size());
    const unsigned cfg_len = escaped(&r, 4, 4, 8);
    aac_bitreader_skip(&r, 8 * (int)cfg_len);
    aac_bitreader_skip(&r, 2); /* applyCrossfade, reserved */
    const unsigned frames = escaped(&r, 2, 4, 0);
    if (g_trace) {
      printf("preroll: config %u bytes, %u frames\n", cfg_len, frames);
    }
    first = false;
    for (unsigned i = 0; i < frames; i++) {
      const unsigned len = escaped(&r, 16, 16, 0);
      if ((int)len * 8 > aac_bitreader_bits_left(&r)) {
        return AAC_ERR_DECODE;
      }
      std::vector<uint8_t> au = copy_bytes(&r, (int)len);
      const int ret = decode_au(au.data(), (int)au.size(), nullptr, true);
      if (ret != AAC_OK) {
        return ret;
      }
      fd_frames--;
    }
    return AAC_OK;
  }
};

struct StreamResult {
  bool ok = false;
  int frames = 0;
  int fd_frames = 0;
  int lpd_stop = 0;
  int errors = 0;
  UsacCfg cfg;
  std::vector<float> pcm;
};

static StreamResult decode_loas(const std::vector<uint8_t>& stream, bool verbose) {
  StreamResult res;
  UsacDecoder dec;
  bool have_cfg = false;
  size_t pos = 0;
  while (pos + 3 <= stream.size()) {
    if (!aac_loas_is_sync(stream.data() + pos, (int)(stream.size() - pos))) {
      pos++;
      continue;
    }
    const int len = aac_loas_payload_length(stream.data() + pos);
    if (pos + 3 + (size_t)len > stream.size()) {
      break;
    }
    std::vector<uint8_t> au;
    const bool had_cfg = have_cfg;
    if (!parse_latm(stream.data() + pos + 3, len, &dec.cfg, &have_cfg, &au)) {
      printf("LATM parse failed at byte %zu\n", pos);
      res.errors++;
      break;
    }
    if (!had_cfg) {
      if (verbose) {
        printf("config: rate %d, frame %d, sbr ratio %d, %d channels, %zu elements:",
               dec.cfg.sample_rate, dec.cfg.frame_length, dec.cfg.sbr_ratio_index, dec.cfg.channels,
               dec.cfg.elems.size());
        for (const UsacElem& e : dec.cfg.elems) {
          printf(" %s", e.type == 0 ? "SCE" : e.type == 1 ? "CPE" : e.type == 2 ? "LFE" : "EXT");
          if (e.type == 3) {
            printf("(%d,len %d)", e.ext_type, e.ext_default_length);
          } else {
            printf("(nf %d)", e.noise_filling);
          }
        }
        printf("\n");
      }
      if (dec.cfg.sbr_ratio_index != 0) {
        printf("the stream uses SBR; only the core is decoded here\n");
      }
      if (!dec.init()) {
        res.errors++;
        break;
      }
    }
    res.frames++;
    const int ret = dec.decode_au(au.data(), (int)au.size(), &res.pcm, false);
    if (ret == AAC_ERR_UNSUPPORTED) {
      res.lpd_stop = 1;
      break;
    }
    if (ret != AAC_OK) {
      printf("frame %d: decode error %d\n", res.frames, ret);
      res.errors++;
      break;
    }
    pos += 3 + (size_t)len;
  }
  res.cfg = dec.cfg;
  res.fd_frames = dec.fd_frames;
  res.ok = res.errors == 0 && res.fd_frames > 0;
  return res;
}

/* ── the reference comparison ──────────────────────────────────── */

static std::vector<float> read_wav16(const std::string& path, int* channels, int* rate) {
  std::vector<uint8_t> d = read_file(path);
  std::vector<float> out;
  if (d.size() < 44 || memcmp(d.data(), "RIFF", 4) != 0) {
    return out;
  }
  size_t pos = 12;
  int ch = 0, bits = 0;
  while (pos + 8 <= d.size()) {
    const uint32_t len = (uint32_t)d[pos + 4] | ((uint32_t)d[pos + 5] << 8) |
                         ((uint32_t)d[pos + 6] << 16) | ((uint32_t)d[pos + 7] << 24);
    if (memcmp(d.data() + pos, "fmt ", 4) == 0 && len >= 16) {
      ch = d[pos + 10] | (d[pos + 11] << 8);
      *rate = (int)(d[pos + 12] | (d[pos + 13] << 8) | (d[pos + 14] << 16) | (d[pos + 15] << 24));
      bits = d[pos + 22] | (d[pos + 23] << 8);
    } else if (memcmp(d.data() + pos, "data", 4) == 0) {
      if (bits != 16 || ch == 0) {
        return out;
      }
      const size_t n = std::min((size_t)len, d.size() - pos - 8) / 2;
      out.resize(n);
      for (size_t i = 0; i < n; i++) {
        const int16_t v = (int16_t)(d[pos + 8 + 2 * i] | (d[pos + 9 + 2 * i] << 8));
        out[i] = (float)v / 32768.0f;
      }
      *channels = ch;
      return out;
    }
    pos += 8 + len + (len & 1);
  }
  return out;
}

struct Alignment {
  int delay; /* test is delayed by this many samples against the reference */
  double gain_db;
  double snr_db;
  size_t samples;
};

/* Delay by cross-correlation of the first channel, then a least-squares
 * gain and the SNR over all channels. */
/* Aligns `test` to `ref` by the delay of best correlation and compares
 * them over whole frames (the reference decoder's final partial frame is
 * its own flush behaviour). */
static Alignment align_and_compare(const std::vector<float>& ref, const std::vector<float>& test,
                                   int channels, int frame, int max_delay) {
  Alignment a;
  const size_t nr = ref.size() / (size_t)channels, nt = test.size() / (size_t)channels;
  const size_t n = std::min(std::min(nr, nt), (size_t)48000);
  double best = -1e300;
  a.delay = 0;
  for (int d = 0; d <= max_delay; d++) {
    double acc = 0.0;
    for (size_t i = 0; i + (size_t)d < n; i++) {
      acc += (double)ref[i * (size_t)channels] * test[(i + (size_t)d) * (size_t)channels];
    }
    if (acc > best) {
      best = acc;
      a.delay = d;
    }
  }
  const size_t m = std::min(nr, nt - (size_t)a.delay) / (size_t)frame * (size_t)frame;
  double rt = 0.0, rr = 0.0;
  for (size_t i = 0; i < m * (size_t)channels; i++) {
    const double x = ref[i], y = test[i + (size_t)a.delay * (size_t)channels];
    rt += x * y;
    rr += x * x;
  }
  const double g = rr > 0 ? rt / rr : 0.0;
  double sig = 0.0, err = 0.0;
  for (size_t i = 0; i < m * (size_t)channels; i++) {
    const double x = g * ref[i], y = test[i + (size_t)a.delay * (size_t)channels];
    sig += x * x;
    err += (y - x) * (y - x);
  }
  a.gain_db = g > 0 ? 20.0 * log10(g) : -999.0;
  a.snr_db = 10.0 * log10(sig / std::max(err, 1e-30));
  a.samples = m;
  return a;
}

static void test_stream(const char* name, const char* ref_name, int channels, double min_snr) {
  std::vector<uint8_t> stream = read_file(data_path(name));
  CHECK(!stream.empty(), "%s: missing", name);
  if (stream.empty()) {
    return;
  }
  StreamResult res = decode_loas(stream, true);
  CHECK(res.ok, "%s: decode failed (%d errors)", name, res.errors);
  CHECK(res.lpd_stop == 0, "%s: an LPD frame", name);
  CHECK(res.cfg.channels == channels, "%s: %d channels", name, res.cfg.channels);
  printf("%s: %d frames, %d decoded\n", name, res.frames, res.fd_frames);
  int ref_ch = 0, ref_rate = 0;
  std::vector<float> ref = read_wav16(data_path(ref_name), &ref_ch, &ref_rate);
  CHECK(ref_ch == channels && ref_rate == 48000, "%s: reference %d ch %d Hz", ref_name, ref_ch,
        ref_rate);
  if (ref.empty() || res.pcm.empty()) {
    return;
  }
  const Alignment a = align_and_compare(ref, res.pcm, channels, res.cfg.frame_length, 4096);
  printf("%s against the FDK decoder: delay %d, gain %+.2f dB, snr %.1f dB over %zu samples\n",
         name, a.delay, a.gain_db, a.snr_db, a.samples);
  CHECK(a.snr_db >= min_snr, "%s: %.1f dB against the FDK decode, %.0f required", name, a.snr_db,
        min_snr);
  CHECK(fabs(a.gain_db) < 0.5, "%s: gain %.2f dB", name, a.gain_db);
}

static int decode_file(const char* path, const char* ref_path, const char* out_path) {
  std::vector<uint8_t> stream = read_file(path);
  if (stream.empty()) {
    printf("cannot read %s\n", path);
    return 1;
  }
  StreamResult res = decode_loas(stream, true);
  printf("%d LOAS frames, %d FD frames decoded%s, %d errors\n", res.frames, res.fd_frames,
         res.lpd_stop ? " (stopped at an LPD frame)" : "", res.errors);
  const int channels = res.cfg.channels ? res.cfg.channels : 1;
  printf("%zu samples per channel\n", res.pcm.size() / (size_t)channels);
  if (out_path) {
    write_file(out_path, res.pcm.data(), res.pcm.size() * sizeof(float));
  }
  if (ref_path && strcmp(ref_path, "-") != 0) {
    int ref_ch = 0, ref_rate = 0;
    std::vector<float> ref = read_wav16(ref_path, &ref_ch, &ref_rate);
    if (ref.empty() || ref_ch != channels) {
      printf("reference unusable (%d channels)\n", ref_ch);
      return 1;
    }
    /* compare only the frames decoded */
    const Alignment a = align_and_compare(ref, res.pcm, channels, res.cfg.frame_length, 4096);
    printf("against the reference: delay %d, gain %+.2f dB, snr %.1f dB over %zu samples\n",
           a.delay, a.gain_db, a.snr_db, a.samples);
  }
  return res.errors ? 1 : 0;
}

int main(int argc, char** argv) {
  aac_tables_init();
  if (argc >= 2) {
    const char* args[3] = {nullptr, nullptr, nullptr};
    int n = 0;
    for (int i = 1; i < argc; i++) {
      if (strcmp(argv[i], "-v") == 0) {
        g_trace = true;
      } else if (n < 3) {
        args[n++] = argv[i];
      }
    }
    return decode_file(args[0], args[1], args[2]);
  }
  test_arith_tables_shape();
  test_arith_round_trip();
  test_window_shapes();
  test_mdst_estimate();
  test_cplx_prediction();
  test_frame_768();
  test_stream("usac_fd_cpe_96k.loas", "usac_fd_cpe_96k_fdk.wav", 2, 40.0);
  test_stream("usac_fd_sce_64k.loas", "usac_fd_sce_64k_fdk.wav", 1, 40.0);
  return test_summary("test_usac_fd");
}
