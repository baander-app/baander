/* Low-delay SBR of AAC-ELD: the CLDFB banks reconstruct their input with
 * the documented delays; the LD envelope grids are well formed for every
 * transient position of 512 and 480 sample frames; sbr_data written by
 * the encoder reads back in the decoder with the same grid, envelope,
 * noise floor and inverse filtering values; and a signal coded through
 * the module alone (an ideal lowpass standing in for the core codec)
 * comes back with its low band intact and its high band's spectral
 * envelope restored, in dual and single rate. */
#include <complex>

#include "qmf_ld.h"
#include "sbr_ld.h"
#include "sbr_ld_enc.h"
#include "test_util.h"

/* ── CLDFB ─────────────────────────────────────────────────────── */

static std::vector<float> bank_signal(int n, double rel_top, unsigned seed) {
  /* noise band-limited to rel_top of the Nyquist frequency, as a sum of
   * random-phase sinusoids */
  std::vector<float> x(n, 0.0f);
  for (int j = 0; j < 60; j++) {
    seed = seed * 1664525u + 1013904223u;
    const double f = rel_top * 0.5 * (double)(seed >> 8) / 16777216.0;
    seed = seed * 1664525u + 1013904223u;
    const double ph = 2.0 * M_PI * (double)(seed >> 8) / 16777216.0;
    for (int i = 0; i < n; i++) {
      x[i] += (float)(300.0 * sin(2.0 * M_PI * f * i + ph));
    }
  }
  return x;
}

static std::vector<float> chain(const std::vector<float>& in, int ana, int syn) {
  AacCldfbAnalysis a;
  AacCldfbSynthesis s;
  aac_cldfb_analysis_init(&a, ana);
  aac_cldfb_synthesis_init(&s, syn);
  std::vector<float> out(in.size() * syn / ana, 0.0f);
  for (size_t slot = 0; slot + ana <= in.size(); slot += ana) {
    float X[64][2] = {};
    aac_cldfb_analyze(&a, in.data() + slot, X);
    aac_cldfb_synthesize(&s, X, out.data() + slot * syn / ana);
  }
  return out;
}

/* SNR of out[i + d] against ref[i * ref_step + phase] at the best delay
 * d. */
static double best_snr(const std::vector<float>& ref, int ref_step, int phase,
                       const std::vector<float>& out, int* delay) {
  double best = -100.0;
  const size_t n = ref.size() / ref_step - 800;
  for (int d = 0; d < 700; d++) {
    double sig = 0.0;
    double err = 0.0;
    for (size_t i = 200; i < n; i++) {
      const double r = ref[i * ref_step + phase];
      const double o = out[i + d];
      sig += r * r;
      err += (o - r) * (o - r);
    }
    const double v = 10.0 * log10(sig / std::max(err, 1e-9));
    if (v > best) {
      best = v;
      *delay = d;
    }
  }
  return best;
}

/* ── grid ──────────────────────────────────────────────────────── */

static void test_grid() {
  for (int slots : {16, 15}) {
    for (int num_env : {1, 2, 4}) {
      AacSbrLdGrid g;
      CHECK(aac_sbr_ld_make_grid(&g, slots, AAC_SBR_LD_FRAME_FIXFIX, num_env, 0) == 0,
            "FIXFIX %d envelopes", num_env);
      CHECK(g.num_env == num_env && g.t_env[0] == 0 && g.t_env[num_env] == slots,
            "FIXFIX %d of %d slots: borders 0..%d", num_env, slots, g.t_env[g.num_env]);
      for (int e = 0; e < g.num_env; e++) {
        CHECK(g.t_env[e + 1] - g.t_env[e] >= slots / num_env - 1, "FIXFIX envelope length");
      }
    }
    AacSbrLdGrid g;
    CHECK(aac_sbr_ld_make_grid(&g, slots, AAC_SBR_LD_FRAME_FIXFIX, 3, 0) != 0,
          "FIXFIX with three envelopes is refused");
    for (int pos = 0; pos < slots; pos++) {
      CHECK(aac_sbr_ld_make_grid(&g, slots, AAC_SBR_LD_FRAME_LDTRAN, 0, pos) == 0,
            "LDTRAN at %d", pos);
      CHECK(g.t_env[0] == 0 && g.t_env[g.num_env] == slots, "LDTRAN at %d spans the frame", pos);
      CHECK(g.num_env >= 1 && g.num_env <= 3, "LDTRAN at %d: %d envelopes", pos, g.num_env);
      for (int e = 0; e < g.num_env; e++) {
        CHECK(g.t_env[e + 1] - g.t_env[e] >= 2, "LDTRAN at %d: envelope %d shorter than 2", pos, e);
      }
      CHECK(g.tran_env >= 0 && g.t_env[g.tran_env] <= pos && pos < g.t_env[g.tran_env + 1] + 1,
            "LDTRAN at %d: the transient's envelope [%d, %d)", pos, g.t_env[g.tran_env],
            g.t_env[g.tran_env + 1]);
      if (g.num_env > 1) {
        CHECK(g.num_noise == 2 && g.t_q[1] > 0 && g.t_q[1] < slots,
              "LDTRAN at %d: noise border %d", pos, g.t_q[1]);
      }
    }
    CHECK(aac_sbr_ld_make_grid(&g, slots, AAC_SBR_LD_FRAME_LDTRAN, 0, slots) != 0,
          "a transient past the frame is refused");
  }
}

/* ── signals ───────────────────────────────────────────────────── */

/* Two seconds at 48 kHz: harmonics of 220 Hz up to 15 kHz with falling
 * level, a noise bed, and decaying noise bursts every 300 ms that make
 * the transient detector switch to LDTRAN frames. */
static std::vector<float> test_signal_48k(int n) {
  std::vector<float> x(n, 0.0f);
  unsigned seed = 2024u;
  for (int i = 0; i < n; i++) {
    const double t = (double)i / 48000.0;
    double v = 0.0;
    for (int h = 1; h * 220 < 15000; h++) {
      v += (0.12 / sqrt((double)h)) * sin(2.0 * M_PI * 220.0 * h * t + 0.37 * h);
    }
    seed = seed * 1664525u + 1013904223u;
    v += 0.02 * ((double)(seed >> 8) / 8388608.0 - 1.0);
    x[i] = (float)(0.5 * v);
  }
  for (int start = 9000; start + 2400 < n; start += 14400) {
    for (int i = 0; i < 2400; i++) {
      seed = seed * 1664525u + 1013904223u;
      x[start + i] += (float)(0.4 * ((double)(seed >> 8) / 8388608.0 - 1.0) * exp(-i / 300.0));
    }
  }
  return x;
}

/* Ideal lowpass (and optional decimation by two) through one FFT of the
 * whole signal: zero phase, so it adds no delay. */
static std::vector<float> ideal_lowpass(const std::vector<float>& x, double cutoff_rel,
                                        bool decimate) {
  size_t n = 1;
  while (n < x.size()) {
    n <<= 1;
  }
  std::vector<std::complex<double>> a(n, 0.0);
  for (size_t i = 0; i < x.size(); i++) {
    a[i] = x[i];
  }
  /* iterative radix-2 FFT */
  auto fft = [](std::vector<std::complex<double>>& v, bool inverse) {
    const size_t N = v.size();
    for (size_t i = 1, j = 0; i < N; i++) {
      size_t bit = N >> 1;
      for (; j & bit; bit >>= 1) {
        j ^= bit;
      }
      j ^= bit;
      if (i < j) {
        std::swap(v[i], v[j]);
      }
    }
    for (size_t len = 2; len <= N; len <<= 1) {
      const double ang = 2.0 * M_PI / (double)len * (inverse ? 1.0 : -1.0);
      const std::complex<double> wl(cos(ang), sin(ang));
      for (size_t i = 0; i < N; i += len) {
        std::complex<double> w(1.0);
        for (size_t j = 0; j < len / 2; j++) {
          const std::complex<double> u = v[i + j];
          const std::complex<double> t = v[i + j + len / 2] * w;
          v[i + j] = u + t;
          v[i + j + len / 2] = u - t;
          w *= wl;
        }
      }
    }
    if (inverse) {
      for (auto& c : v) {
        c /= (double)N;
      }
    }
  };
  fft(a, false);
  const size_t cut = (size_t)(cutoff_rel * (double)(n / 2));
  for (size_t k = cut; k <= n - cut; k++) {
    a[k] = 0.0;
  }
  fft(a, true);
  std::vector<float> out;
  for (size_t i = 0; i < x.size(); i += decimate ? 2 : 1) {
    out.push_back((float)a[i].real());
  }
  return out;
}

/* Energy in dB of x[from, to) between lo and hi (fractions of the
 * Nyquist frequency), from the ideal filters above. */
static double band_energy_db(const std::vector<float>& x, double lo, double hi, size_t from,
                             size_t to) {
  const std::vector<float> a = ideal_lowpass(x, hi, false);
  const std::vector<float> b = ideal_lowpass(x, lo, false);
  double e = 0.0;
  for (size_t i = from; i < to && i < x.size(); i++) {
    const double v = (double)a[i] - b[i];
    e += v * v;
  }
  return 10.0 * log10(e + 1e-20);
}

static void test_cldfb() {
  const int D = AAC_CLDFB_DELAY;
  int d = 0;
  const std::vector<float> full = bank_signal(64 * 300, 0.96, 11u);
  double snr = best_snr(full, 1, 0, chain(full, 64, 64), &d);
  printf("CLDFB 64 to 64: %.1f dB at delay %d\n", snr, d);
  CHECK(snr > 60.0, "64-band analysis to 64-band synthesis: %.1f dB", snr);
  CHECK(d == D, "64-band chain delay %d, documented %d", d, D);

  const std::vector<float> core = bank_signal(32 * 300, 0.96, 12u);
  snr = best_snr(core, 1, 0, chain(core, 32, 32), &d);
  printf("CLDFB 32 to 32: %.1f dB at delay %d\n", snr, d);
  CHECK(snr > 60.0, "32-band analysis to 32-band synthesis: %.1f dB", snr);
  CHECK(d == (D + 1) / 2, "32-band chain delay %d, documented %d", d, (D + 1) / 2);

  /* Dual rate: a half-rate signal comes out at the full rate, its samples
   * on the even output samples. */
  const std::vector<float> wide = bank_signal(64 * 300, 0.45, 13u);
  std::vector<float> half(wide.size() / 2);
  for (size_t i = 0; i < half.size(); i++) {
    half[i] = wide[2 * i];
  }
  /* the images around the half-rate Nyquist frequency are left to the
   * SBR bands: the comparison covers the half-rate band */
  const std::vector<float> up = ideal_lowpass(chain(half, 32, 64), 0.5, false);
  snr = best_snr(wide, 1, 0, up, &d);
  printf("CLDFB 32 to 64: %.1f dB at delay %d\n", snr, d);
  CHECK(snr > 60.0, "32-band analysis to 64-band synthesis: %.1f dB", snr);
  CHECK(d == D + 1, "dual-rate chain delay %d, documented %d", d, D + 1);

  /* Encoder resampling: the half-rate output sits on the odd input
   * samples. */
  snr = best_snr(wide, 2, 1, chain(wide, 64, 32), &d);
  printf("CLDFB 64 to 32: %.1f dB at delay %d\n", snr, d);
  CHECK(snr > 60.0, "64-band analysis to 32-band synthesis: %.1f dB", snr);
  CHECK(d == (D + 1) / 2, "resampling chain delay %d, documented %d", d, (D + 1) / 2);
}

/* ── bitstream roundtrip ───────────────────────────────────────── */

static void test_bitstream(int frame_length, int dual_rate, int channels) {
  AacSbrLdEncoder* enc = new AacSbrLdEncoder;
  AacSbrLdState* dec = new AacSbrLdState;
  const int core_rate = dual_rate ? 24000 : 48000;
  int ret = aac_sbr_ld_enc_init(enc, core_rate, frame_length, dual_rate, channels,
                                32000 * channels, 0, nullptr);
  CHECK(ret == 0, "encoder init %d", ret);
  if (ret) {
    delete enc;
    delete dec;
    return;
  }
  /* the header travels through the audio specific config */
  uint8_t hdr_buf[8] = {};
  AacBitWriter hw;
  aac_bitwriter_init(&hw, hdr_buf, sizeof(hdr_buf));
  aac_sbr_ld_header_write(aac_sbr_ld_enc_header(enc), &hw);
  const int hdr_bits = aac_bitwriter_bits_written(&hw);
  AacBitReader hr;
  aac_bitreader_init(&hr, hdr_buf, sizeof(hdr_buf));
  AacSbrLdHeader header;
  CHECK(aac_sbr_ld_header_read(&header, &hr) == 0 && hr.pos == hdr_bits, "header roundtrip");
  CHECK(memcmp(&header, aac_sbr_ld_enc_header(enc), sizeof(header)) == 0,
        "header fields survive the roundtrip");
  ret = aac_sbr_ld_init(dec, core_rate, frame_length, dual_rate, &header);
  CHECK(ret == 0, "decoder init %d", ret);

  const int in_size = aac_sbr_ld_enc_input_size(enc);
  const int frames = 60;
  const std::vector<float> src = test_signal_48k(in_size * frames + 64);
  std::vector<float> core_l(frame_length), core_r(frame_length);
  std::vector<float> out_l(aac_sbr_ld_output_size(dec)), out_r(aac_sbr_ld_output_size(dec));
  std::vector<float> right(in_size);
  int ldtran = 0;
  int fixfix = 0;
  int mismatches = 0;
  int time_deltas = 0;
  for (int f = 0; f < frames; f++) {
    const float* in = src.data() + (size_t)f * in_size;
    for (int i = 0; i < in_size; i++) {
      right[i] = 0.6f * src[(size_t)f * in_size + i + 17];
    }
    aac_sbr_ld_enc_process(enc, in, right.data(), core_l.data(), core_r.data());
    uint8_t buf[AAC_SBR_LD_ENC_MAX_BYTES + 4] = {};
    AacBitWriter w;
    aac_bitwriter_init(&w, buf, sizeof(buf));
    aac_sbr_ld_enc_write(enc, &w);
    const int bits = aac_bitwriter_bits_written(&w);
    CHECK(bits == aac_sbr_ld_enc_bits(enc), "frame %d: %d bits written, %d announced", f, bits,
          aac_sbr_ld_enc_bits(enc));
    AacBitReader r;
    aac_bitreader_init(&r, buf, (bits + 7) / 8);
    ret = aac_sbr_ld_read(dec, &r, channels == 2 ? AAC_ELEM_CPE : AAC_ELEM_SCE, 0);
    CHECK(ret == 0, "frame %d: read %d", f, ret);
    CHECK(r.pos == bits, "frame %d: read %d of %d bits", f, r.pos, bits);
    for (int c = 0; c < channels; c++) {
      const AacSbrLdEncChannel* e = &enc->ch[c];
      const AacSbrLdChannel* d = &dec->ch[c];
      const AacSbrLdGrid* ge = &e->grid;
      const AacSbrLdGrid* gd = &d->grid;
      bool same = ge->frame_class == gd->frame_class && ge->num_env == gd->num_env &&
                  ge->num_noise == gd->num_noise && e->amp_res == d->amp_res;
      for (int i = 0; same && i <= ge->num_env; i++) {
        same = ge->t_env[i] == gd->t_env[i];
      }
      for (int i = 0; same && i < ge->num_env; i++) {
        same = ge->freq_res[i] == gd->freq_res[i];
        const int n = enc->freq.n[ge->freq_res[i]];
        for (int b = 0; same && b < n; b++) {
          same = e->env_q[i][b] == d->env_facs_q[i + 1][b];
        }
        time_deltas += e->df_env[i];
      }
      for (int i = 0; same && i < ge->num_noise; i++) {
        for (int b = 0; same && b < enc->freq.n_q; b++) {
          same = e->noise_q[i][b] == d->noise_facs_q[i + 1][b];
        }
      }
      for (int b = 0; same && b < enc->freq.n_q; b++) {
        same = e->invf[b] == d->invf_mode[0][b];
      }
      if (!same) {
        mismatches++;
      }
      if (ge->frame_class == AAC_SBR_LD_FRAME_LDTRAN) {
        ldtran++;
      } else {
        fixfix++;
      }
    }
    aac_sbr_ld_process(dec, core_l.data(), core_r.data(), out_l.data(), out_r.data());
  }
  printf("bitstream %d %s %s: %d LDTRAN, %d FIXFIX frames, %d time-delta envelopes\n",
         frame_length, dual_rate ? "dual" : "single", channels == 2 ? "stereo" : "mono", ldtran,
         fixfix, time_deltas);
  CHECK(mismatches == 0, "%d channel frames decoded other parameters than encoded", mismatches);
  CHECK(ldtran > 0 && fixfix > 0, "both frame classes occur (%d LDTRAN, %d FIXFIX)", ldtran,
        fixfix);
  CHECK(time_deltas > 0, "delta coding in time is used");
  delete enc;
  delete dec;
}

/* A damaged payload is refused and the frame still produces output. */
static void test_damaged() {
  AacSbrLdEncoder* enc = new AacSbrLdEncoder;
  AacSbrLdState* dec = new AacSbrLdState;
  CHECK(aac_sbr_ld_enc_init(enc, 24000, 512, 1, 1, 32000, 0, nullptr) == 0, "encoder init");
  CHECK(aac_sbr_ld_init(dec, 24000, 512, 1, aac_sbr_ld_enc_header(enc)) == 0, "decoder init");
  const uint8_t junk[2] = {0xff, 0xff};
  AacBitReader r;
  aac_bitreader_init(&r, junk, sizeof(junk));
  CHECK(aac_sbr_ld_read(dec, &r, AAC_ELEM_SCE, 0) != 0, "a truncated payload is refused");
  std::vector<float> core(512, 0.1f), out(1024, 1.0f);
  aac_sbr_ld_process(dec, core.data(), nullptr, out.data(), nullptr);
  bool finite = true;
  for (float v : out) {
    finite = finite && std::isfinite(v);
  }
  CHECK(finite, "output after a damaged payload is finite");
  CHECK(aac_sbr_ld_init(dec, 24000, 1024, 1, aac_sbr_ld_enc_header(enc)) != 0,
        "a frame length other than 512 or 480 is refused");
  delete enc;
  delete dec;
}

/* ── coding roundtrip ──────────────────────────────────────────── */

static void test_roundtrip(int frame_length, int dual_rate) {
  const int src_rate = 48000;
  const int seconds_samples = src_rate * 2;
  const std::vector<float> src48 = test_signal_48k(seconds_samples);
  /* dual rate: 48 kHz in, 24 kHz core, 48 kHz out; single rate: the
   * signal at 24 kHz in, core and out */
  const std::vector<float> input = dual_rate ? src48 : ideal_lowpass(src48, 0.5, true);
  const int rate = dual_rate ? 48000 : 24000;
  const int core_rate = 24000;

  AacSbrLdEncoder* enc = new AacSbrLdEncoder;
  AacSbrLdState* dec = new AacSbrLdState;
  int ret = aac_sbr_ld_enc_init(enc, core_rate, frame_length, dual_rate, 1, 32000, 0, nullptr);
  CHECK(ret == 0, "encoder init %d", ret);
  if (ret) {
    delete enc;
    delete dec;
    return;
  }
  ret = aac_sbr_ld_init(dec, core_rate, frame_length, dual_rate, aac_sbr_ld_enc_header(enc));
  CHECK(ret == 0, "decoder init %d", ret);
  const int in_size = aac_sbr_ld_enc_input_size(enc);
  const int frames = (int)input.size() / in_size;
  std::vector<float> core((size_t)frames * frame_length);
  std::vector<std::vector<uint8_t>> payloads;
  std::vector<int> payload_bits;
  for (int f = 0; f < frames; f++) {
    aac_sbr_ld_enc_process(enc, input.data() + (size_t)f * in_size, nullptr,
                           core.data() + (size_t)f * frame_length, nullptr);
    std::vector<uint8_t> buf(AAC_SBR_LD_ENC_MAX_BYTES + 4, 0);
    AacBitWriter w;
    aac_bitwriter_init(&w, buf.data(), (int)buf.size());
    aac_sbr_ld_enc_write(enc, &w);
    payloads.push_back(buf);
    payload_bits.push_back(aac_bitwriter_bits_written(&w));
  }
  /* the core codec: everything below the crossover, nothing above */
  const int kx = enc->freq.kx;
  const int top = enc->freq.kx + enc->freq.m;
  const double xover_rel = (double)kx / (dual_rate ? 32.0 : 32.0); /* of the core Nyquist */
  const std::vector<float> coded = ideal_lowpass(core, xover_rel, false);

  std::vector<float> out((size_t)frames * aac_sbr_ld_output_size(dec));
  for (int f = 0; f < frames; f++) {
    AacBitReader r;
    aac_bitreader_init(&r, payloads[f].data(), (payload_bits[f] + 7) / 8);
    ret = aac_sbr_ld_read(dec, &r, AAC_ELEM_SCE, 0);
    CHECK(ret == 0, "frame %d read %d", f, ret);
    aac_sbr_ld_process(dec, coded.data() + (size_t)f * frame_length, nullptr,
                       out.data() + (size_t)f * aac_sbr_ld_output_size(dec), nullptr);
  }
  double bits = 0.0;
  for (int b : payload_bits) {
    bits += b;
  }
  const double kbps = bits / frames * core_rate / frame_length / 1000.0;

  /* delay of the whole chain at the output rate: the encoder's, then the
   * decoder's (core samples count twice in dual rate) */
  const int delay = aac_sbr_ld_enc_delay(enc) + aac_sbr_ld_delay(dec);
  CHECK(delay == (dual_rate ? 2 * AAC_CLDFB_DELAY + 1 : AAC_CLDFB_DELAY + 1),
        "chain delay %d", delay);
  std::vector<float> aligned(input.size(), 0.0f);
  for (size_t i = 0; i + delay < out.size() && i < aligned.size(); i++) {
    aligned[i] = out[i + delay];
  }
  const size_t from = (size_t)rate / 4;
  const size_t to = input.size() - (size_t)rate / 4;
  /* output-rate band edges as fractions of its Nyquist frequency */
  const double nyq_bands = dual_rate ? 64.0 : 32.0;
  const double lo_edge = (double)kx / nyq_bands;
  const double hi_edge = std::min((double)top / nyq_bands, 1.0);

  /* low band: the core's part comes back sample for sample */
  const std::vector<float> ref_low = ideal_lowpass(input, 0.9 * lo_edge, false);
  const std::vector<float> out_low = ideal_lowpass(aligned, 0.9 * lo_edge, false);
  const double low_snr =
      snr_db(ref_low.data() + from, out_low.data() + from, to - from);

  /* high band: per sixth of the SBR range, the level within a
   * few dB of the source */
  double worst = 0.0;
  const int parts = 6;
  for (int p = 0; p < parts; p++) {
    const double lo = lo_edge + (hi_edge - lo_edge) * p / parts;
    const double hi = lo_edge + (hi_edge - lo_edge) * (p + 1) / parts;
    const double e_in = band_energy_db(input, lo, hi, from, to);
    const double e_out = band_energy_db(aligned, lo, hi, from, to);
    printf("  band %.0f-%.0f Hz: source %.1f dB, decoded %.1f dB\n", lo * rate / 2,
           hi * rate / 2, e_in, e_out);
    worst = std::max(worst, fabs(e_out - e_in));
  }
  /* above the SBR range the output stays empty */
  printf("roundtrip %d %s: kx %d, top %d, %.1f kbit/s of SBR data, low band %.1f dB SNR, "
         "worst high band level error %.2f dB\n",
         frame_length, dual_rate ? "dual" : "single", kx, top, kbps, low_snr, worst);
  CHECK(low_snr > 50.0, "low band SNR %.1f dB", low_snr);
  CHECK(worst < 3.0, "high band level error %.2f dB", worst);

  /* without SBR data the high band stays empty */
  aac_sbr_ld_reset(dec);
  std::vector<float> plain(out.size());
  for (int f = 0; f < frames; f++) {
    aac_sbr_ld_process(dec, coded.data() + (size_t)f * frame_length, nullptr,
                       plain.data() + (size_t)f * aac_sbr_ld_output_size(dec), nullptr);
  }
  std::vector<float> plain_aligned(input.size(), 0.0f);
  for (size_t i = 0; i + delay < plain.size() && i < plain_aligned.size(); i++) {
    plain_aligned[i] = plain[i + delay];
  }
  const double e_plain = band_energy_db(plain_aligned, lo_edge * 1.1, hi_edge, from, to);
  const double e_src = band_energy_db(input, lo_edge * 1.1, hi_edge, from, to);
  CHECK(e_plain < e_src - 30.0, "without SBR data the high band is %.1f dB below the source",
        e_src - e_plain);
  delete enc;
  delete dec;
}

int main() {
  test_cldfb();
  test_grid();
  for (int fl : {512, 480}) {
    for (int dual : {1, 0}) {
      test_bitstream(fl, dual, 1);
      test_bitstream(fl, dual, 2);
    }
  }
  test_damaged();
  for (int fl : {512, 480}) {
    for (int dual : {1, 0}) {
      test_roundtrip(fl, dual);
    }
  }
  return test_summary("test_sbr_ld");
}
