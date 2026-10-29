/* Enhanced SBR of USAC (ISO/IEC 23003-3 7.5): the 24- and 16-band QMF
 * analysis, the frequency tables of the 2:1, 8:3 and 4:1 ratios against
 * the patch structure observed in FDK decodes, the UsacSbrData() syntax
 * through a writer and the parser, the harmonic transposer, PVC and
 * inter-TES on constructed frames, the split QMF-frame interface, and the
 * low band of a real 8:3 and 4:1 stream against the FDK decoder's output
 * through the upsampling path.  Decoding the SBR payload of a real stream
 * needs the core decoder; that check runs after integration. */
#include <complex>

#include "qmf.h"
#include "sbr.h"
#include "test_util.h"
#include "usac.h"

/* ── QMF: small analysis banks into the 64-band synthesis ─────────── */

static double probe(double t) {
  return 10000.0 * sin(2 * M_PI * 1000.0 * t) + 5000.0 * sin(2 * M_PI * 3100.0 * t + 1.0) +
         3000.0 * sin(2 * M_PI * 300.0 * t + 2.0) + 2000.0 * sin(2 * M_PI * 5200.0 * t + 0.4);
}

/* Upsamples a core signal of B-band slots through analysis and the
 * 64-band synthesis and returns the SNR against the signal regenerated at
 * the best (fractional) delay. */
static double upsample_snr(int B, double* delay) {
  const int slots = 400;
  const int core_sr = 48000 * B / 64;
  std::vector<float> core(B * slots);
  for (int i = 0; i < B * slots; i++) {
    core[i] = (float)probe((double)i / core_sr);
  }
  AacQmfAnalysis a;
  AacQmfSynthesis s;
  aac_qmf_analysis_init(&a, B);
  aac_qmf_synthesis_init(&s, 64);
  std::vector<float> out(64 * slots);
  for (int i = 0; i < slots; i++) {
    float X[64][2] = {};
    aac_qmf_analyze(&a, core.data() + B * i, X);
    aac_qmf_synthesize(&s, X, out.data() + 64 * i);
  }
  double best = -1e9;
  for (double d = 570.0; d <= 590.0; d += 1.0 / 24) {
    double sg = 0.0;
    double er = 0.0;
    for (int i = 3000; i < 64 * slots - 2000; i++) {
      const double r = probe((i - d) / 48000.0);
      sg += r * r;
      er += (out[i] - r) * (out[i] - r);
    }
    const double v = 10 * log10(sg / (er + 1e-9));
    if (v > best) {
      best = v;
      *delay = d;
    }
  }
  return best;
}

static void test_qmf_banks() {
  double d = 0.0;
  double snr = upsample_snr(16, &d);
  printf("16-band analysis to 64-band synthesis: %.1f dB at %.3f output samples\n", snr, d);
  CHECK(snr > 50.0, "16-band upsampling SNR %.1f dB", snr);
  CHECK(fabs(d - 580.0) < 0.05, "16-band upsampling delay %.3f", d);
  snr = upsample_snr(24, &d);
  printf("24-band analysis to 64-band synthesis: %.1f dB at %.3f output samples\n", snr, d);
  CHECK(snr > 50.0, "24-band upsampling SNR %.1f dB", snr);
  CHECK(fabs(d - 578.0 - 2.0 / 3) < 0.05, "24-band upsampling delay %.3f", d);
  snr = upsample_snr(32, &d);
  CHECK(snr > 50.0 && fabs(d - 578.0) < 0.05, "32-band upsampling %.1f dB at %.3f", snr, d);
}

/* ── frequency tables ──────────────────────────────────────────── */

static AacUsacSbrConfig config(int start, int stop, int freq_scale, int noise_bands,
                               int harmonic = 0, int tes = 0, int pvc = 0) {
  AacUsacSbrConfig c;
  memset(&c, 0, sizeof(c));
  c.harmonic_sbr = harmonic;
  c.inter_tes = tes;
  c.pvc = pvc;
  c.dflt.start_freq = start;
  c.dflt.stop_freq = stop;
  c.dflt.header_extra1 = 1;
  c.dflt.freq_scale = freq_scale;
  c.dflt.alter_scale = 1;
  c.dflt.noise_bands = noise_bands;
  return c;
}

struct Expected {
  const char* name;
  int ratio, core_rate, frame;
  int start, stop, freq_scale, noise_bands;
  int k0, k2;
  int patches;
  int num[6], src[6];
};

/* The structures the probe decodes of the FDK encoder and decoder show
 * (spectral lines copied band by band). */
static const Expected kExpected[] = {
    {"8:3 music_48000_24000 (10,10)", AAC_USAC_SBR_8_3, 18000, 768, 10, 10, 2, 2, 18, 49, 3,
     {15, 12, 4}, {2, 5, 13}},
    {"2:1 speech_48000_24000 (7,8)", AAC_USAC_SBR_2_1, 24000, 1024, 7, 8, 2, 2, 15, 41, 2,
     {13, 13}, {1, 2}},
    {"2:1 speech_48000_32000 (10,9)", AAC_USAC_SBR_2_1, 24000, 1024, 10, 9, 2, 2, 18, 45, 2,
     {15, 12}, {2, 5}},
    {"2:1 music_48000_48000 (12,9)", AAC_USAC_SBR_2_1, 24000, 1024, 12, 9, 1, 2, 22, 45, 2,
     {20, 3}, {2, 18}},
    {"2:1 music_48000_64000 (14,11)", AAC_USAC_SBR_2_1, 24000, 1024, 14, 11, 1, 3, 27, 54, 2,
     {18, 9}, {9, 17}},
    {"4:1 speech_48000_12000 (5,9)", AAC_USAC_SBR_4_1, 12000, 1024, 5, 9, 3, 1, 9, 42, 6,
     {7, 6, 5, 7, 4, 4}, {1, 2, 4, 1, 4, 4}},
    {"4:1 speech_48000_16000 (6,8)", AAC_USAC_SBR_4_1, 12000, 1024, 6, 8, 3, 1, 10, 38, 4,
     {8, 7, 9, 4}, {2, 2, 1, 6}},
    {"4:1 music_48000_16000 (11,11)", AAC_USAC_SBR_4_1, 12000, 1024, 11, 11, 3, 1, 15, 52, 4,
     {12, 12, 6, 7}, {3, 3, 9, 7}},
};

static void test_freq_tables() {
  static AacSbrState state;
  for (const Expected& e : kExpected) {
    const AacUsacSbrConfig cfg = config(e.start, e.stop, e.freq_scale, e.noise_bands);
    aac_sbr_usac_init(&state, e.core_rate, e.frame, e.ratio, &cfg);
    printf("%s: k0 %d kx %d k2 %d m %d n_master %d bands %d/%d noise %d limiter %d patches",
           e.name, state.k[0], state.kx[1], state.k[2], state.m[1], state.n_master, state.n[0],
           state.n[1], state.n_q, state.n_lim);
    for (int p = 0; p < state.num_patches; p++) {
      printf(" [%d from %d]", state.patch_num_subbands[p], state.patch_start_subband[p]);
    }
    printf("\n");
    CHECK(state.start, "%s: tables refused", e.name);
    CHECK(state.k[0] == e.k0 && state.k[2] == e.k2, "%s: k0 %d k2 %d", e.name, state.k[0],
          state.k[2]);
    CHECK(state.num_patches == e.patches, "%s: %d patches", e.name, state.num_patches);
    for (int p = 0; p < std::min(state.num_patches, e.patches); p++) {
      CHECK(state.patch_num_subbands[p] == e.num[p] && state.patch_start_subband[p] == e.src[p],
            "%s: patch %d is %d from %d", e.name, p, state.patch_num_subbands[p],
            state.patch_start_subband[p]);
    }
  }
}

/* ── a UsacSbrData() writer ───────────────────────────────────────── */

/* The codeword of `value` (as the decoder returns it, 64 + leaf) in a
 * decoding tree. */
static bool huff_code(const int8_t (*tree)[2], int nodes, int value, uint32_t* code, int* len) {
  /* depth-first search over the tree */
  struct Node {
    int idx, depth;
    uint32_t code;
  };
  std::vector<Node> stack = {{0, 0, 0}};
  while (!stack.empty()) {
    const Node n = stack.back();
    stack.pop_back();
    if (n.idx >= nodes) {
      continue;
    }
    for (int bit = 0; bit < 2; bit++) {
      const int child = tree[n.idx][bit];
      const uint32_t c = (n.code << 1) | (uint32_t)bit;
      if (child < 0) {
        if (child + 64 == value) {
          *code = c;
          *len = n.depth + 1;
          return true;
        }
      } else {
        stack.push_back({child, n.depth + 1, c});
      }
    }
  }
  return false;
}

static void put_huff(AacBitWriter* w, const int8_t (*tree)[2], int nodes, int value) {
  uint32_t code = 0;
  int len = 0;
  const bool ok = huff_code(tree, nodes, value, &code, &len);
  CHECK(ok, "no codeword for %d", value);
  aac_bitwriter_write(w, code, len);
}

struct TestEnvelope {
  int freq_res;
  int delta_time; /* bs_df_env */
  int values[AAC_SBR_MAX_BANDS];
  int temp_shape, temp_shape_mode;
};

struct TestFrame {
  int indep;
  int info_present, header_present, use_dflt;
  int amp_res, xover, preprocessing, pvc_mode;
  int header_start, header_stop; /* an explicit header with extra1 = extra2 = 0 */
  int patching_mode, oversampling, pitch;
  int num_env; /* FixFix: 1, 2 or 4 */
  TestEnvelope env[4];
  int num_noise_values;
  int noise[2][AAC_SBR_MAX_NOISE_BANDS];
  int invf[AAC_SBR_MAX_NOISE_BANDS];
  int add_harmonic;
  int harmonics[AAC_SBR_MAX_BANDS];
  /* PVC */
  int pvc_noise_position, pvc_div_mode, pvc_ns_mode;
  int pvc_borders[8], pvc_ids[9];
};

/* sbr_single_channel_element() with the USAC additions, for a state
 * whose tables are current. */
static void write_sce(AacBitWriter* w, const AacSbrState* s, const AacUsacSbrConfig* cfg,
                      const TestFrame& f) {
  if (cfg->harmonic_sbr) {
    aac_bitwriter_write(w, (uint32_t)f.patching_mode, 1);
    if (!f.patching_mode) {
      aac_bitwriter_write(w, (uint32_t)f.oversampling, 1);
      aac_bitwriter_write(w, f.pitch > 0, 1);
      if (f.pitch > 0) {
        aac_bitwriter_write(w, (uint32_t)f.pitch, 7);
      }
    }
  }
  const int num_noise = f.num_env > 1 ? 2 : 1;
  if (f.pvc_mode == 0) {
    /* sbr_grid: FIXFIX */
    aac_bitwriter_write(w, 0, 2);
    aac_bitwriter_write(w, f.num_env == 1 ? 0 : (f.num_env == 2 ? 1 : 2), 2);
    aac_bitwriter_write(w, (uint32_t)f.env[0].freq_res, 1);
    /* sbr_dtdf: an independent frame omits the first direction bits */
    for (int e = f.indep ? 1 : 0; e < f.num_env; e++) {
      aac_bitwriter_write(w, (uint32_t)f.env[e].delta_time, 1);
    }
    for (int i = f.indep ? 1 : 0; i < num_noise; i++) {
      aac_bitwriter_write(w, 0, 1);
    }
    for (int i = 0; i < s->n_q; i++) {
      aac_bitwriter_write(w, (uint32_t)f.invf[i], 2);
    }
    /* sbr_envelope */
    const int amp_res = f.num_env == 1 ? 0 : f.amp_res;
    const int8_t(*t_huff)[2] = amp_res ? aac_sbr_huff_env_level_30_t : aac_sbr_huff_env_level_15_t;
    const int8_t(*f_huff)[2] = amp_res ? aac_sbr_huff_env_level_30_f : aac_sbr_huff_env_level_15_f;
    const int nodes = amp_res ? 62 : 120;
    const int odd = s->n[1] & 1;
    for (int e = 0; e < f.num_env; e++) {
      const int n = s->n[f.env[0].freq_res];
      if (f.env[e].delta_time) {
        for (int j = 0; j < n; j++) {
          const int k = aac_sbr_prev_band(j, f.env[0].freq_res, f.env[0].freq_res, odd);
          put_huff(w, t_huff, nodes, f.env[e].values[j] - f.env[e - 1].values[k]);
        }
      } else {
        aac_bitwriter_write(w, (uint32_t)f.env[e].values[0], amp_res ? 6 : 7);
        for (int j = 1; j < n; j++) {
          put_huff(w, f_huff, nodes, f.env[e].values[j] - f.env[e].values[j - 1]);
        }
      }
    }
    if (cfg->inter_tes) {
      for (int e = 0; e < f.num_env; e++) {
        aac_bitwriter_write(w, (uint32_t)f.env[e].temp_shape, 1);
        if (f.env[e].temp_shape) {
          aac_bitwriter_write(w, (uint32_t)f.env[e].temp_shape_mode, 2);
        }
      }
    }
  } else {
    aac_bitwriter_write(w, (uint32_t)f.pvc_noise_position, 4);
    aac_bitwriter_write(w, 0, 1); /* bs_var_len_hf_flag */
    for (int i = 0; i < (f.pvc_noise_position ? 2 : 1); i++) {
      aac_bitwriter_write(w, 0, 1); /* bs_df_noise */
    }
    for (int i = 0; i < s->n_q; i++) {
      aac_bitwriter_write(w, (uint32_t)f.invf[i], 2);
    }
    aac_bitwriter_write(w, (uint32_t)f.pvc_div_mode, 3);
    aac_bitwriter_write(w, (uint32_t)f.pvc_ns_mode, 1);
    for (int i = 0; i < f.pvc_div_mode; i++) {
      aac_bitwriter_write(w, (uint32_t)f.pvc_borders[i], 4);
    }
    for (int i = 0; i <= f.pvc_div_mode; i++) {
      aac_bitwriter_write(w, 0, 1); /* bs_reuse_pvcID */
      aac_bitwriter_write(w, (uint32_t)f.pvc_ids[i], 7);
    }
  }
  /* sbr_noise, frequency coded */
  const int noise_envs = f.pvc_mode ? (f.pvc_noise_position ? 2 : 1) : num_noise;
  for (int i = 0; i < noise_envs; i++) {
    aac_bitwriter_write(w, (uint32_t)f.noise[i][0], 5);
    for (int j = 1; j < s->n_q; j++) {
      put_huff(w, aac_sbr_huff_env_level_30_f, 62, f.noise[i][j] - f.noise[i][j - 1]);
    }
  }
  /* sbr_sinusoidal_coding */
  aac_bitwriter_write(w, (uint32_t)f.add_harmonic, 1);
  if (f.add_harmonic) {
    for (int i = 0; i < s->n[1]; i++) {
      aac_bitwriter_write(w, (uint32_t)f.harmonics[i], 1);
    }
  }
}

static int write_usac_sbr_data(uint8_t* buf, int cap, const AacSbrState* s,
                               const AacUsacSbrConfig* cfg, const TestFrame& f) {
  AacBitWriter w;
  aac_bitwriter_init(&w, buf, cap);
  if (!f.indep) {
    aac_bitwriter_write(&w, (uint32_t)f.info_present, 1);
    if (f.info_present) {
      aac_bitwriter_write(&w, (uint32_t)f.header_present, 1);
    }
  }
  if (f.indep || f.info_present) {
    aac_bitwriter_write(&w, (uint32_t)f.amp_res, 1);
    aac_bitwriter_write(&w, (uint32_t)f.xover, 4);
    aac_bitwriter_write(&w, (uint32_t)f.preprocessing, 1);
    if (cfg->pvc) {
      aac_bitwriter_write(&w, (uint32_t)f.pvc_mode, 2);
    }
  }
  if (f.indep || f.header_present) {
    aac_bitwriter_write(&w, (uint32_t)f.use_dflt, 1);
    if (!f.use_dflt) {
      aac_bitwriter_write(&w, (uint32_t)f.header_start, 4);
      aac_bitwriter_write(&w, (uint32_t)f.header_stop, 4);
      aac_bitwriter_write(&w, 0, 2); /* no extra fields */
    }
  }
  write_sce(&w, s, cfg, f);
  const int bits = aac_bitwriter_bits_written(&w);
  aac_bitwriter_write(&w, 0x5a5a, 16); /* a trailer the parser must not touch */
  aac_bitwriter_byte_align(&w);
  return bits;
}

/* A state carrying the tables of a header and crossover, for the writer
 * when the frame changes them. */
static const AacSbrState* tables_for(int core_rate, int frame, int ratio, int start, int stop,
                                     int freq_scale, int noise_bands, int xover) {
  static AacSbrState probe_state;
  const AacUsacSbrConfig cfg = config(start, stop, freq_scale, noise_bands);
  aac_sbr_usac_init(&probe_state, core_rate, frame, ratio, &cfg);
  probe_state.xover_band = xover;
  AacSbrFreq fq;
  aac_sbr_freq_from_state(&probe_state, &fq);
  CHECK(aac_sbr_freq_tables(&fq) == 0, "tables for the writer");
  aac_sbr_freq_to_state(&fq, &probe_state);
  return &probe_state;
}

static TestFrame plain_frame(int num_env, int level) {
  TestFrame f;
  memset(&f, 0, sizeof(f));
  f.indep = 1;
  f.use_dflt = 1;
  f.amp_res = 1;
  f.patching_mode = 1;
  f.num_env = num_env;
  for (int e = 0; e < num_env; e++) {
    f.env[e].freq_res = 1;
    for (int j = 0; j < AAC_SBR_MAX_BANDS; j++) {
      f.env[e].values[j] = level - (j & 3) + e;
    }
  }
  for (int i = 0; i < 2; i++) {
    for (int j = 0; j < AAC_SBR_MAX_NOISE_BANDS; j++) {
      f.noise[i][j] = 28 - j;
    }
  }
  return f;
}

/* Parses a written frame; returns the bits the parser consumed. */
static int decode_frame(AacSbrState* s, const uint8_t* buf, int size, int indep, int* ret) {
  AacBitReader r;
  aac_bitreader_init(&r, buf, size);
  *ret = aac_sbr_usac_decode(s, &r, AAC_ELEM_SCE, 1, indep);
  return r.pos;
}

static void test_syntax_roundtrip() {
  static AacSbrState s;
  const AacUsacSbrConfig cfg = config(10, 10, 2, 2, 1, 1, 1);
  aac_sbr_usac_init(&s, 24000, 1024, AAC_USAC_SBR_2_1, &cfg);
  uint8_t buf[512];
  /* 1. an independent frame on the default header, two envelopes, the
   *    second delta-time coded, inter-TES on the second, LPC patching */
  TestFrame f = plain_frame(2, 40);
  f.xover = 3;
  f.env[1].delta_time = 1;
  f.env[1].temp_shape = 1;
  f.env[1].temp_shape_mode = 2;
  f.invf[0] = 2;
  f.invf[1] = 1;
  f.add_harmonic = 1;
  f.harmonics[2] = 1;
  int bits = write_usac_sbr_data(buf, sizeof(buf),
                                 tables_for(24000, 1024, AAC_USAC_SBR_2_1, 10, 10, 2, 2, 3), &cfg, f);
  int ret;
  int used = decode_frame(&s, buf, sizeof(buf), 1, &ret);
  CHECK(ret == AAC_OK, "frame 1 refused (%d)", ret);
  CHECK(used == bits, "frame 1: parser consumed %d of %d bits", used, bits);
  CHECK(s.xover_band == 3 && s.kx[1] == s.f_master[3], "xover 3: kx %d", s.kx[1]);
  const AacSbrChannel* ch = &s.data[0];
  CHECK(ch->num_env == 2 && ch->t_env[0] == 0 && ch->t_env[1] == 8 && ch->t_env[2] == 16,
        "grid %d envelopes %d %d %d", ch->num_env, ch->t_env[0], ch->t_env[1], ch->t_env[2]);
  bool env_ok = true;
  for (int e = 0; e < 2; e++) {
    for (int j = 0; j < s.n[1]; j++) {
      env_ok = env_ok && ch->env_facs_q[e + 1][j] == f.env[e].values[j];
    }
  }
  CHECK(env_ok, "envelope values");
  CHECK(ch->noise_facs_q[1][0] == 28 && ch->noise_facs_q[2][1] == 27, "noise values %d %d",
        ch->noise_facs_q[1][0], ch->noise_facs_q[2][1]);
  CHECK(ch->invf_mode[0][0] == 2 && ch->invf_mode[0][1] == 1, "invf modes");
  CHECK(ch->add_harmonic_flag && ch->add_harmonic[2] == 1 && ch->add_harmonic[1] == 0, "sinusoids");
  CHECK(s.usac.data[0].temp_shape[0] == 0 && s.usac.data[0].temp_shape[1] == 1 &&
            s.usac.data[0].temp_shape_mode[1] == 2,
        "inter-TES flags");
  CHECK(s.usac.data[0].patching_mode == 1, "patching mode");
  /* 2. a dependent frame without info or header, harmonic patching with
   *    a pitch, a single envelope */
  f = plain_frame(1, 45);
  f.indep = 0;
  f.info_present = 0;
  f.patching_mode = 0;
  f.pitch = 37;
  f.oversampling = 1;
  bits = write_usac_sbr_data(buf, sizeof(buf), &s, &cfg, f);
  used = decode_frame(&s, buf, sizeof(buf), 0, &ret);
  CHECK(ret == AAC_OK && used == bits, "frame 2: ret %d, %d of %d bits", ret, used, bits);
  CHECK(s.usac.data[0].patching_mode == 0 && s.usac.data[0].pitch_in_bins == 37 &&
            s.usac.data[0].oversampling == 1,
        "harmonic fields %d %d %d", s.usac.data[0].patching_mode, s.usac.data[0].pitch_in_bins,
        s.usac.data[0].oversampling);
  CHECK(s.data[0].num_env == 1 && s.data[0].amp_res == 0, "single envelope at amp_res 0");
  /* 3. info and an explicit header: a new table set */
  f = plain_frame(4, 38);
  f.indep = 0;
  f.info_present = 1;
  f.header_present = 1;
  f.use_dflt = 0;
  f.header_start = 8;
  f.header_stop = 9;
  f.xover = 1;
  bits = write_usac_sbr_data(buf, sizeof(buf),
                             tables_for(24000, 1024, AAC_USAC_SBR_2_1, 8, 9, 2, 2, 1), &cfg, f);
  used = decode_frame(&s, buf, sizeof(buf), 0, &ret);
  CHECK(ret == AAC_OK && used == bits, "frame 3: ret %d, %d of %d bits", ret, used, bits);
  CHECK(s.start_freq == 8 && s.stop_freq == 9 && s.freq_scale == 2 && s.xover_band == 1,
        "header fields %d %d %d %d", s.start_freq, s.stop_freq, s.freq_scale, s.xover_band);
  CHECK(s.data[0].num_env == 4 && s.data[0].t_env[1] == 4, "four envelopes");
  /* 4. a PVC frame */
  f = plain_frame(1, 40);
  f.indep = 1;
  f.pvc_mode = 1;
  f.pvc_noise_position = 6;
  f.pvc_div_mode = 2;
  f.pvc_ns_mode = 1;
  f.pvc_borders[0] = 5;
  f.pvc_borders[1] = 11;
  f.pvc_ids[0] = 17;
  f.pvc_ids[1] = 99;
  f.pvc_ids[2] = 3;
  bits = write_usac_sbr_data(buf, sizeof(buf), &s, &cfg, f);
  used = decode_frame(&s, buf, sizeof(buf), 1, &ret);
  CHECK(ret == AAC_OK && used == bits, "PVC frame: ret %d, %d of %d bits", ret, used, bits);
  const AacSbrUsacChannelData* d = &s.usac.data[0];
  CHECK(d->pvc_active && d->pvc_ns_mode == 1 && d->pvc_id[0] == 17 && d->pvc_id[4] == 17 &&
            d->pvc_id[5] == 99 && d->pvc_id[10] == 99 && d->pvc_id[11] == 3 && d->pvc_id[15] == 3,
        "PVC ids");
  CHECK(s.data[0].num_noise == 2 && s.data[0].t_q[1] == 6, "PVC noise envelopes");
  /* 5. a damaged frame keeps the previous parameters */
  memset(buf, 0xff, sizeof(buf));
  used = decode_frame(&s, buf, 2, 0, &ret);
  CHECK(ret == AAC_ERR_DECODE, "damaged frame accepted");
  CHECK(d->pvc_active && d->pvc_id[5] == 99, "parameters restored after a damaged frame");
}

/* ── signal path ───────────────────────────────────────────────── */

/* Runs `frames` frames of `core` (full scale 1.0) through the state with
 * one parameter frame per core frame and returns the output. */
static std::vector<float> run_frames(AacSbrState* s, const AacUsacSbrConfig* cfg,
                                     const std::vector<float>& core, int frame_length,
                                     const TestFrame& f) {
  const int frames = (int)core.size() / frame_length;
  const int out_len = s->usac.output_frame;
  std::vector<float> out((size_t)frames * out_len);
  std::vector<float> buf(out_len);
  uint8_t bits[512];
  for (int n = 0; n < frames; n++) {
    TestFrame fr = f;
    fr.indep = n == 0;
    if (!fr.indep) {
      fr.info_present = 0;
    }
    write_usac_sbr_data(bits, sizeof(bits), s, cfg, fr);
    AacBitReader r;
    aac_bitreader_init(&r, bits, sizeof(bits));
    const int ret = aac_sbr_usac_decode(s, &r, AAC_ELEM_SCE, 1, fr.indep);
    CHECK(ret == AAC_OK, "frame %d refused", n);
    memcpy(buf.data(), core.data() + (size_t)n * frame_length, frame_length * sizeof(float));
    aac_sbr_usac_apply(s, buf.data(), nullptr);
    memcpy(out.data() + (size_t)n * out_len, buf.data(), out_len * sizeof(float));
  }
  return out;
}

/* In-place radix-2 FFT for the spectra below. */
static void test_fft(std::vector<std::complex<double>>& x) {
  const size_t n = x.size();
  for (size_t i = 1, j = 0; i < n; i++) {
    size_t bit = n >> 1;
    for (; j & bit; bit >>= 1) {
      j ^= bit;
    }
    j ^= bit;
    if (i < j) {
      std::swap(x[i], x[j]);
    }
  }
  for (size_t len = 2; len <= n; len <<= 1) {
    const std::complex<double> wl(cos(-2 * M_PI / (double)len), sin(-2 * M_PI / (double)len));
    for (size_t i = 0; i < n; i += len) {
      std::complex<double> w(1.0, 0.0);
      for (size_t j = 0; j < len / 2; j++) {
        const std::complex<double> u = x[i + j];
        const std::complex<double> v = x[i + j + len / 2] * w;
        x[i + j] = u + v;
        x[i + j + len / 2] = u - v;
        w *= wl;
      }
    }
  }
}

/* Power spectrum (dB) of a signal in bins of `bin_hz`, averaged over
 * 4096-point blocks after the first `skip` samples. */
static std::vector<double> spectrum_db(const std::vector<float>& x, int sr, int skip, double bin_hz) {
  const int N = 4096;
  std::vector<double> P(N / 2 + 1, 0.0);
  int blocks = 0;
  std::vector<std::complex<double>> blk(N);
  for (size_t start = skip; start + N <= x.size(); start += N / 2, blocks++) {
    for (int n = 0; n < N; n++) {
      const double w = 0.5 - 0.5 * cos(2 * M_PI * (n + 0.5) / N);
      blk[n] = std::complex<double>(x[start + n] * w, 0.0);
    }
    test_fft(blk);
    for (int k = 0; k <= N / 2; k++) {
      P[k] += std::norm(blk[k]);
    }
  }
  const int bins = (int)(sr / 2 / bin_hz);
  std::vector<double> out(bins, 0.0);
  for (int b = 0; b < bins; b++) {
    const int k0 = (int)(b * bin_hz / sr * N);
    const int k1 = (int)((b + 1) * bin_hz / sr * N);
    double sum = 0.0;
    for (int k = k0; k < k1 && k <= N / 2; k++) {
      sum += P[k];
    }
    out[b] = 10 * log10(sum / std::max(blocks, 1) + 1e-12);
  }
  return out;
}

static void test_harmonic_transposer() {
  /* 4:1 at a 12 kHz core: a 4.8 kHz tone must come out at 9.6, 14.4 and
   * 19.2 kHz (orders 2, 3 and 4) when the frame asks for harmonic
   * patching, and not when it asks for the LPC patching. */
  static AacSbrState s;
  const AacUsacSbrConfig cfg = config(8, 11, 3, 1, 1, 0, 0); /* k0 12, k2 52 */
  const int frame = 1024;
  const int frames = 24;
  std::vector<float> core((size_t)frame * frames);
  for (size_t i = 0; i < core.size(); i++) {
    /* the 4.8 kHz tone sits in the SBR range itself (above k0), the 3 kHz
     * one is a source band of the LPC patches */
    core[i] = 0.5f * sinf(2.0f * (float)M_PI * 4800.0f * (float)i / 12000.0f) +
              0.25f * sinf(2.0f * (float)M_PI * 3000.0f * (float)i / 12000.0f);
  }
  for (int mode = 0; mode < 2; mode++) {
    aac_sbr_usac_init(&s, 12000, frame, AAC_USAC_SBR_4_1, &cfg);
    CHECK(s.start && s.k[2] >= 52, "4:1 tables for the transposer test: k2 %d", s.k[2]);
    TestFrame f = plain_frame(1, 48);
    f.patching_mode = mode;
    f.pitch = 0;
    for (int j = 0; j < AAC_SBR_MAX_BANDS; j++) {
      f.env[0].values[j] = 50;
    }
    for (int j = 0; j < AAC_SBR_MAX_NOISE_BANDS; j++) {
      f.noise[0][j] = 30; /* lowest noise floor */
    }
    const std::vector<float> out = run_frames(&s, &cfg, core, frame, f);
    bool finite = true;
    for (float v : out) {
      finite = finite && std::isfinite(v);
    }
    CHECK(finite, "mode %d: non-finite output", mode);
    const std::vector<double> sp = spectrum_db(out, 48000, 8 * 4096, 250.0);
    auto at = [&](double hz) { return sp[(int)(hz / 250.0)]; };
    auto around = [&](double hz) {
      /* the level 1 kHz away on both sides */
      return std::max(at(hz - 1000.0), at(hz + 1000.0));
    };
    /* The envelope adjuster shapes the output to the (flat) test envelope,
     * so the transposer itself is checked on its QMF analysis, still in the
     * channel buffer after the last frame: a harmonic at band b (375 Hz per
     * band) against the bands two to four away on both sides. */
    double band_db[64];
    for (int k = 0; k < 64; k++) {
      double e = 0.0;
      for (int i = 0; i < AAC_SBR_USAC_COLS; i++) {
        const float* w = s.usac.ch[0].W_hbe[i][k];
        e += (double)w[0] * w[0] + (double)w[1] * w[1];
      }
      band_db[k] = 10 * log10(e + 1e-9);
    }
    auto hbe_peak = [&](double hz) {
      const int b = (int)(hz / 375.0);
      return std::max(band_db[b], band_db[b + 1]);
    };
    auto hbe_near = [&](double hz) {
      const int b = (int)(hz / 375.0);
      double m = -1e9;
      for (int d = 2; d <= 4; d++) {
        m = std::max(m, std::max(band_db[b - d], band_db[std::min(b + 1 + d, 63)]));
      }
      return m;
    };
    printf("patching mode %d: output 3 kHz %.0f dB; 9.6 kHz %.0f (near %.0f); 14.4 kHz %.0f "
           "(near %.0f); 19.2 kHz %.0f (near %.0f)\n",
           mode, at(3000), at(9600), around(9600), at(14400), around(14400), at(19200),
           around(19200));
    if (mode == 0) {
      printf("  transposer bands: 9.6 kHz %.0f dB (near %.0f); 14.4 kHz %.0f (near %.0f); "
             "19.2 kHz %.0f (near %.0f)\n",
             hbe_peak(9600), hbe_near(9600), hbe_peak(14400), hbe_near(14400), hbe_peak(19200),
             hbe_near(19200));
      CHECK(hbe_peak(9600) > hbe_near(9600) + 20.0, "second harmonic absent");
      CHECK(hbe_peak(14400) > hbe_near(14400) + 20.0, "third harmonic absent");
      CHECK(hbe_peak(19200) > hbe_near(19200) + 20.0, "fourth harmonic absent");
      CHECK(at(9600) > at(3000) - 40.0 && at(19200) > at(3000) - 40.0,
            "harmonics missing from the output");
    } else {
      /* the LPC patch copies the tone to shifted positions: a copy of band
       * 12 (4.8 kHz) lands 375 Hz steps away, never on a harmonic */
      CHECK(at(14400) < around(14400) + 15.0 || at(19200) < around(19200) + 15.0,
            "LPC patching produced harmonics");
      const double hb = *std::max_element(sp.begin() + 30, sp.end());
      CHECK(hb > at(3000) - 40.0, "LPC high band too quiet: %.0f dB", hb);
    }
  }
}

static void test_pvc() {
  /* two frames at a loud and a quiet low band: the predicted high band
   * follows the low band energy */
  static AacSbrState s;
  const AacUsacSbrConfig cfg = config(7, 8, 2, 2, 0, 0, 1);
  const int frame = 768;
  aac_sbr_usac_init(&s, 18000, frame, AAC_USAC_SBR_8_3, &cfg);
  const int frames = 16;
  std::vector<float> core((size_t)frame * frames);
  for (size_t i = 0; i < core.size(); i++) {
    const float amp = i < core.size() / 2 ? 0.5f : 0.02f;
    core[i] = amp * sinf(2.0f * (float)M_PI * 4000.0f * (float)i / 18000.0f) +
              amp * 0.5f * sinf(2.0f * (float)M_PI * 2500.0f * (float)i / 18000.0f);
  }
  TestFrame f = plain_frame(1, 40);
  f.pvc_mode = 1;
  f.pvc_noise_position = 0;
  f.pvc_div_mode = 0;
  f.pvc_ids[0] = 5;
  for (int j = 0; j < AAC_SBR_MAX_NOISE_BANDS; j++) {
    f.noise[0][j] = 30;
  }
  const std::vector<float> out = run_frames(&s, &cfg, core, frame, f);
  bool finite = true;
  for (float v : out) {
    finite = finite && std::isfinite(v);
  }
  CHECK(finite, "PVC: non-finite output");
  const int out_frame = s.usac.output_frame;
  const std::vector<float> loud(out.begin() + 4 * out_frame, out.begin() + 7 * out_frame);
  const std::vector<float> quiet(out.begin() + 12 * out_frame, out.begin() + 15 * out_frame);
  const double hb_loud = band_db(loud, 1, 0, 8000.0f, 14000.0f, 48000, 0, loud.size());
  const double hb_quiet = band_db(quiet, 1, 0, 8000.0f, 14000.0f, 48000, 0, quiet.size());
  const double lb_loud = band_db(loud, 1, 0, 2000.0f, 5000.0f, 48000, 0, loud.size());
  printf("PVC: high band %.1f dB (loud) %.1f dB (quiet), low band %.1f dB (loud)\n", hb_loud,
         hb_quiet, lb_loud);
  CHECK(hb_loud > -60.0, "PVC produced no high band");
  CHECK(hb_loud - hb_quiet > 15.0, "PVC high band does not follow the low band");
  CHECK(hb_loud < lb_loud, "PVC high band louder than the low band");
}

static void test_inter_tes() {
  /* a low band that bursts in the second half of every envelope: with
   * inter-TES the high band follows the burst, without it stays flat */
  static AacSbrState s;
  const AacUsacSbrConfig cfg = config(10, 10, 2, 2, 0, 1, 0);
  const int frame = 1024;
  const int frames = 12;
  std::vector<float> core((size_t)frame * frames);
  unsigned seed = 7u;
  for (size_t i = 0; i < core.size(); i++) {
    seed = seed * 1664525u + 1013904223u;
    const float n = (float)(seed >> 8) / 8388608.0f - 1.0f;
    const int pos = (int)(i % frame);
    core[i] = (pos >= frame / 2 ? 0.5f : 0.02f) * n;
  }
  double ratio[2] = {0.0, 0.0};
  for (int tes = 0; tes < 2; tes++) {
    aac_sbr_usac_init(&s, 24000, frame, AAC_USAC_SBR_2_1, &cfg);
    TestFrame f = plain_frame(1, 50);
    f.env[0].temp_shape = tes;
    f.env[0].temp_shape_mode = 3;
    for (int j = 0; j < AAC_SBR_MAX_NOISE_BANDS; j++) {
      f.noise[0][j] = 30;
    }
    const std::vector<float> out = run_frames(&s, &cfg, core, frame, f);
    /* the SBR output of a frame starts 3 time slots (384 samples) after
     * the frame plus the filterbank delay; measure the high band energy
     * of the two halves of the burst period over the steady frames */
    const std::vector<float> hb = lowpass_channel(out, 1, 0, 6000.0f, 48000);
    double first = 0.0, second = 0.0;
    const int delay = 384 + 578;
    for (int n = 4; n < frames - 1; n++) {
      for (int i = 0; i < 2048; i++) {
        const size_t idx = (size_t)n * 2048 + delay + i;
        if (idx >= hb.size()) {
          continue;
        }
        const double v = (double)out[idx] - hb[idx];
        /* margins against the filterbank's smearing of the burst edges */
        if (i >= 128 && i < 768) {
          first += v * v;
        } else if (i >= 1280 && i < 1920) {
          second += v * v;
        }
      }
    }
    ratio[tes] = 10 * log10((second + 1e-12) / (first + 1e-12));
    printf("inter-TES %d: high band second half over first half %.1f dB\n", tes, ratio[tes]);
  }
  CHECK(ratio[1] > ratio[0] + 6.0, "inter-TES did not sharpen the high band: %.1f vs %.1f dB",
        ratio[1], ratio[0]);
}

static void test_split_interface() {
  /* apply_to_qmf followed by synthesize equals apply, and a second
   * synthesis history produces the same output for the same frame */
  static AacSbrState a, b;
  const AacUsacSbrConfig cfg = config(10, 10, 2, 2);
  aac_sbr_usac_init(&a, 24000, 1024, AAC_USAC_SBR_2_1, &cfg);
  aac_sbr_usac_init(&b, 24000, 1024, AAC_USAC_SBR_2_1, &cfg);
  std::vector<float> core = gen_wideband(1024 * 6, 24000, 1);
  TestFrame f = plain_frame(2, 45);
  uint8_t bits[512];
  double diff = 0.0, ref = 0.0, diff2 = 0.0;
  static AacUsacQmfFrame q;
  for (int n = 0; n < 6; n++) {
    f.indep = n == 0;
    f.info_present = 0;
    write_usac_sbr_data(bits, sizeof(bits), &a, &cfg, f);
    AacBitReader r;
    aac_bitreader_init(&r, bits, sizeof(bits));
    CHECK(aac_sbr_usac_decode(&a, &r, AAC_ELEM_CPE, 1, f.indep) == AAC_OK, "frame %d (a)", n);
    aac_bitreader_init(&r, bits, sizeof(bits));
    CHECK(aac_sbr_usac_decode(&b, &r, AAC_ELEM_SCE, 1, f.indep) == AAC_OK, "frame %d (b)", n);
    std::vector<float> x(2048), y(2048), z(2048);
    memcpy(x.data(), core.data() + n * 1024, 1024 * sizeof(float));
    aac_sbr_usac_apply(&a, x.data(), nullptr);
    aac_sbr_usac_apply_to_qmf(&b, 0, core.data() + n * 1024, &q);
    CHECK(q.slots == 32, "QMF frame slots %d", q.slots);
    aac_sbr_usac_synthesize(&b, 0, &q, y.data());
    aac_sbr_usac_synthesize(&b, 1, &q, z.data());
    for (int i = 0; i < 2048; i++) {
      diff += ((double)x[i] - y[i]) * ((double)x[i] - y[i]);
      diff2 += ((double)z[i] - y[i]) * ((double)z[i] - y[i]);
      ref += (double)x[i] * x[i];
    }
  }
  CHECK(ref > 0.0 && diff < 1e-10 * ref, "split interface differs from apply: %g", diff / ref);
  CHECK(diff2 < 1e-10 * ref, "second synthesis history differs: %g", diff2 / ref);
}

/* ── the low band of real streams against the FDK decoder ────────── */

static std::vector<float> read_wav_mono(const std::string& path, int* sr) {
  const std::vector<uint8_t> d = read_file(path);
  std::vector<float> out;
  if (d.size() < 44) {
    return out;
  }
  *sr = (int)(d[24] | (d[25] << 8) | (d[26] << 16) | (d[27] << 24));
  const int channels = d[22] | (d[23] << 8);
  size_t pos = 12;
  while (pos + 8 <= d.size()) {
    const uint32_t size = d[pos + 4] | (d[pos + 5] << 8) | (d[pos + 6] << 16) | ((uint32_t)d[pos + 7] << 24);
    if (memcmp(&d[pos], "data", 4) == 0) {
      const size_t n = std::min((size_t)size, d.size() - pos - 8) / 2 / channels;
      out.resize(n);
      for (size_t i = 0; i < n; i++) {
        const size_t off = pos + 8 + i * 2 * channels;
        out[i] = (float)(int16_t)(d[off] | (d[off + 1] << 8)) / 32768.0f;
      }
      return out;
    }
    pos += 8 + size + (size & 1);
  }
  return out;
}

/* Resamples by the rational factor up/down with a windowed sinc. */
static std::vector<float> resample(const std::vector<float>& x, int up, int down) {
  const size_t n = x.size() * up / down;
  std::vector<float> out(n);
  const int taps = 48;
  const double cutoff = 0.45 * std::min(1.0, (double)up / down);
  for (size_t j = 0; j < n; j++) {
    const double pos = (double)j * down / up;
    const int c = (int)floor(pos);
    double acc = 0.0;
    for (int i = c - taps; i <= c + taps; i++) {
      if (i < 0 || (size_t)i >= x.size()) {
        continue;
      }
      const double t = i - pos;
      const double w = 0.5 + 0.5 * cos(M_PI * t / (taps + 1));
      const double h = t == 0.0 ? 2.0 * cutoff : sin(2 * M_PI * cutoff * t) / (M_PI * t);
      acc += x[i] * h * w;
    }
    out[j] = (float)acc;
  }
  return out;
}

/* Low band SNR between the FDK decode and our upsampling of its core
 * stand-in (the decode decimated to the core rate) at the best delay
 * (searched in thirds of a sample) within the band below `band_hz`. */
static void fdk_lowband(const char* file, int ratio, int core_rate, int frame, int up, int down,
                        int start, int stop, int freq_scale, int noise_bands, double band_hz,
                        double expected_delay) {
  int sr = 0;
  const std::vector<float> fdk = read_wav_mono(data_path(file), &sr);
  CHECK(sr == 48000 && fdk.size() > 48000, "%s: missing or short", file);
  if (fdk.size() < 48000) {
    return;
  }
  const std::vector<float> core = resample(fdk, down, up); /* 48000 -> core rate */
  static AacSbrState s;
  const AacUsacSbrConfig cfg = config(start, stop, freq_scale, noise_bands);
  aac_sbr_usac_init(&s, core_rate, frame, ratio, &cfg);
  /* upsampling only: no SBR data decoded, the state stays off */
  aac_sbr_turnoff(&s);
  const int frames = (int)core.size() / frame;
  const int out_frame = s.usac.output_frame;
  std::vector<float> out((size_t)frames * out_frame);
  for (int n = 0; n < frames; n++) {
    std::vector<float> buf(out_frame);
    memcpy(buf.data(), core.data() + (size_t)n * frame, frame * sizeof(float));
    aac_sbr_usac_apply(&s, buf.data(), nullptr);
    memcpy(out.data() + (size_t)n * out_frame, buf.data(), out_frame * sizeof(float));
  }
  const std::vector<float> ref_lb = lowpass_channel(fdk, 1, 0, (float)band_hz, 48000);
  const std::vector<float> out_lb = lowpass_channel(out, 1, 0, (float)band_hz, 48000);
  /* the delay search in thirds of a sample: compare against the reference
   * resampled by 3 */
  const std::vector<float> ref3 = resample(ref_lb, 3, 1);
  const size_t n = std::min(out_lb.size(), ref_lb.size()) - 6000;
  /* out is delayed by d3 / 3 samples relative to the reference */
  auto snr_at = [&](int d3, size_t step) {
    double sig = 0.0, err = 0.0;
    for (size_t i = 4000; i < n; i += step) {
      const size_t ri = 3 * i - (size_t)d3;
      if (ri >= ref3.size()) {
        continue;
      }
      const double r = ref3[ri];
      const double o = out_lb[i];
      sig += r * r;
      err += (o - r) * (o - r);
    }
    return 10 * log10(sig / std::max(err, 1e-12));
  };
  double best = -100.0;
  int best_d3 = 0;
  for (int d = 400; d <= 1600; d++) {
    const double v = snr_at(3 * d, 16);
    if (v > best) {
      best = v;
      best_d3 = 3 * d;
    }
  }
  const int centre = best_d3;
  best = -100.0;
  for (int d3 = centre - 6; d3 <= centre + 6; d3++) {
    const double v = snr_at(d3, 1);
    if (v > best) {
      best = v;
      best_d3 = d3;
    }
  }
  const double best_delay = best_d3 / 3.0;
  printf("%s: low band (< %.0f Hz) against the FDK decode %.1f dB at %.2f samples delay\n", file,
         band_hz, best, best_delay);
  CHECK(best > 25.0, "%s: low band SNR %.1f dB", file, best);
  CHECK(fabs(best_delay - expected_delay) < 1.0, "%s: delay %.2f, expected %.2f", file,
        best_delay, expected_delay);
}

static void test_fdk_lowband() {
  /* speech_48000_32000: 8:3, SCE, header (10, 9); speech_48000_16000: 4:1,
   * SCE, header (6, 8).  The band below the crossover (k0 x 375 Hz) is the
   * core; the stand-in core is the decode decimated with a 0.45 Nyquist
   * cutoff, so compare below that. */
  fdk_lowband("usac_speech_48000_32000_fdk.wav", AAC_USAC_SBR_8_3, 18000, 768, 8, 3, 10, 9, 2, 2,
              3800.0, 384.0 + 578.667);
  fdk_lowband("usac_speech_48000_16000_fdk.wav", AAC_USAC_SBR_4_1, 12000, 1024, 4, 1, 6, 8, 3, 1,
              2600.0, 768.0 + 580.0);
}

int main() {
  test_qmf_banks();
  test_freq_tables();
  test_syntax_roundtrip();
  test_harmonic_transposer();
  test_pvc();
  test_inter_tes();
  test_split_interface();
  test_fdk_lowband();
  return test_summary("test_usac_sbr");
}
