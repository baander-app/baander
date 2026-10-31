/* MPEG Surround 212: the codebooks and the fixed tables, the mixing
 * matrices against values of the reference tables, the hybrid
 * filterbank, the decorrelator and the decode of the MPS 212 streams
 * through the public decoder.
 *
 * With arguments the program decodes a LOAS file instead:
 *   test_mps212 stream.loas out.f32
 * and writes the decoded interleaved float output. */
#include <cmath>
#include <cstdio>
#include <cstring>
#include <string>
#include <vector>

#include "aac.h"
#include "aac_dsp.h"
#include "bitstream.h"
#include "decoder.h"
#include "mps212.h"
#include "sbr.h"
#include "test_util.h"

namespace {

/* ── Huffman node tables ────────────────────────────────────────── */

/* Walks a node table: every node must lead to two entries inside the
 * table, every leaf must hold a symbol in range (or the escape of a
 * two-dimensional book), and no node may be reached twice. */
void check_tree(const char* name, const int16_t (*nodes)[2], int count, int max_symbol,
                int packed) {
  std::vector<int> seen(count, 0);
  std::vector<int> stack;
  stack.push_back(0);
  int leaves = 0;
  while (!stack.empty()) {
    const int n = stack.back();
    stack.pop_back();
    CHECK(n >= 0 && n < count, "%s: node %d outside the table of %d", name, n, count);
    if (n < 0 || n >= count) {
      return;
    }
    CHECK(!seen[n], "%s: node %d reached twice", name, n);
    seen[n] = 1;
    for (int b = 0; b < 2; b++) {
      const int e = nodes[n][b];
      if (e > 0) {
        stack.push_back(e);
      } else {
        const int sym = -e - 1;
        leaves++;
        if (packed) {
          /* a pair (a, b) as a << 4 | b, or the escape leaf (entry 0) */
          CHECK(sym == -1 || ((sym >> 4) <= max_symbol && (sym & 15) <= max_symbol),
                "%s: leaf symbol %d beyond the pairs of %d", name, sym, max_symbol);
        } else {
          CHECK(sym >= 0 && sym <= max_symbol, "%s: leaf symbol %d beyond %d", name, sym,
                max_symbol);
        }
      }
    }
  }
  /* a two-dimensional table is sized for its largest book and may hold
   * unused entries; the tree reached must be complete */
  int reached = 0;
  for (int n = 0; n < count; n++) {
    reached += seen[n];
    CHECK(packed || seen[n], "%s: node %d unreachable", name, n);
  }
  CHECK(leaves == reached + 1, "%s: %d leaves for %d nodes", name, leaves, reached);
}

template <int N>
void check_1d(const char* name, const int16_t (*nodes)[N][2], int max_symbol) {
  for (int d = 0; d < 3; d++) {
    check_tree(name, nodes[d], N, max_symbol, 0);
  }
}

template <int N>
void check_2d(const char* name, const int16_t (*nodes)[2][N][2], int lav) {
  for (int d = 0; d < 3; d++) {
    for (int p = 0; p < 2; p++) {
      /* the symbols are pairs (a, b) with a, b in 0..lav; the symmetric
       * half is signalled apart, so fewer leaves than pairs exist */
      check_tree(name, nodes[d][p], N, lav, 1);
    }
  }
}

void test_codebooks(void) {
  check_tree("cld_first", aac_mps212_huff_cld_first, 30, 30, 0);
  check_tree("icc_first", aac_mps212_huff_icc_first, 7, 7, 0);
  check_tree("ipd_first", aac_mps212_huff_ipd_first, 7, 7, 0);
  check_tree("lav", aac_mps212_huff_lav, 3, 3, 0);
  /* envelope shape: (value << 4) | (run - 1) with values 0..4, runs 1..8 */
  check_tree("env_shape", aac_mps212_huff_env_shape, 39, 7, 1);
  check_1d("cld_1d", aac_mps212_huff_cld_1d, 30);
  check_1d("icc_1d", aac_mps212_huff_icc_1d, 7);
  check_1d("ipd_1d", aac_mps212_huff_ipd_1d, 7);
  check_2d("cld_2d_lav3", aac_mps212_huff_cld_2d_lav3, 3);
  check_2d("cld_2d_lav5", aac_mps212_huff_cld_2d_lav5, 5);
  check_2d("cld_2d_lav7", aac_mps212_huff_cld_2d_lav7, 7);
  check_2d("cld_2d_lav9", aac_mps212_huff_cld_2d_lav9, 9);
  check_2d("icc_2d_lav1", aac_mps212_huff_icc_2d_lav1, 1);
  check_2d("icc_2d_lav3", aac_mps212_huff_icc_2d_lav3, 3);
  check_2d("icc_2d_lav5", aac_mps212_huff_icc_2d_lav5, 5);
  check_2d("icc_2d_lav7", aac_mps212_huff_icc_2d_lav7, 7);
  check_2d("ipd_2d_lav1", aac_mps212_huff_ipd_2d_lav1, 1);
  check_2d("ipd_2d_lav3", aac_mps212_huff_ipd_2d_lav3, 3);
  check_2d("ipd_2d_lav5", aac_mps212_huff_ipd_2d_lav5, 5);
  check_2d("ipd_2d_lav7", aac_mps212_huff_ipd_2d_lav7, 7);

  /* reading: every path of the tree yields its leaf, and a code is read
   * back from the bits that lead to it */
  for (int sym = 0; sym <= 30; sym++) {
    /* find the path to the leaf by search */
    std::vector<int> path;
    std::vector<int> walk;
    bool found = false;
    struct Frame {
      int node, bit;
    };
    std::vector<Frame> st;
    st.push_back({0, 0});
    while (!st.empty() && !found) {
      Frame& f = st.back();
      if (f.bit > 1) {
        st.pop_back();
        continue;
      }
      const int e = aac_mps212_huff_cld_first[f.node][f.bit];
      const int bit = f.bit++;
      if (e > 0) {
        st.push_back({e, 0});
      } else if (-e - 1 == sym) {
        for (size_t i = 0; i + 1 < st.size(); i++) {
          path.push_back(st[i].bit - 1);
        }
        path.push_back(bit);
        found = true;
      }
    }
    CHECK(found, "cld_first: symbol %d has no code", sym);
    if (!found) {
      continue;
    }
    uint8_t buf[8] = {0};
    for (size_t i = 0; i < path.size(); i++) {
      buf[i / 8] |= (uint8_t)(path[i] << (7 - i % 8));
    }
    AacBitReader r;
    aac_bitreader_init(&r, buf, sizeof(buf));
    const int got = aac_mps212_huff_read(&r, aac_mps212_huff_cld_first, 30);
    CHECK(got == sym, "cld_first: read %d for symbol %d", got, sym);
    CHECK((int)r.pos == (int)path.size(), "cld_first: %d bits read for a code of %zu", (int)r.pos,
          path.size());
  }
}

/* ── fixed tables ───────────────────────────────────────────────── */

void test_tables(void) {
  const int counts[8] = {0, 28, 20, 14, 10, 7, 5, 4};
  for (int i = 1; i < 8; i++) {
    CHECK(aac_mps212_freq_res_bands[i] == counts[i], "freq res %d: %d bands", i,
          aac_mps212_freq_res_bands[i]);
    const uint8_t* k = aac_mps212_kernel(counts[i]);
    CHECK(k != NULL, "no kernel for %d bands", counts[i]);
    if (!k) {
      continue;
    }
    /* hybrid bands 0 and 1 are the two mirrored quarters of QMF band 0
     * in descending frequency; from band 2 the bands ascend */
    CHECK(k[0] <= 1 && k[1] <= 1 && k[0] >= k[1], "kernel %d: hybrid 0, 1 map to %d, %d",
          counts[i], k[0], k[1]);
    int last = -1;
    for (int h = 2; h < AAC_MPS212_HYBRID_BANDS; h++) {
      CHECK(k[h] >= last && k[h] < counts[i], "kernel %d: band %d of hybrid %d after %d",
            counts[i], k[h], h, last);
      last = k[h];
    }
    CHECK(last == counts[i] - 1, "kernel %d: last band %d", counts[i], last);
  }
  CHECK(aac_mps212_kernel(28)[0] == 1 && aac_mps212_kernel(28)[1] == 0,
        "28 bands: hybrid 0 and 1 are not bands 1 and 0");
  for (int i = 1; i < 31; i++) {
    CHECK(aac_mps212_cld_db[i] > aac_mps212_cld_db[i - 1], "cld table not ascending at %d", i);
  }
  CHECK(fabsf(aac_mps212_cld_db[15]) < 1e-6f, "cld 0 is %f dB", aac_mps212_cld_db[15]);
  CHECK(fabsf(aac_mps212_icc_value[0] - 1.0f) < 1e-6f, "icc 0 is %f", aac_mps212_icc_value[0]);
  for (int i = 1; i < 8; i++) {
    CHECK(aac_mps212_icc_value[i] < aac_mps212_icc_value[i - 1], "icc table not descending at %d",
          i);
  }
  for (int i = 0; i < 16; i++) {
    CHECK(fabsf(aac_mps212_ipd_rad[i] - (float)(i * M_PI / 8.0)) < 1e-5f, "ipd %d is %f", i,
          aac_mps212_ipd_rad[i]);
  }
  CHECK(fabsf(aac_mps212_fixed_gain[0] - 1.0f) < 1e-6f, "fixed gain 0 is %f",
        aac_mps212_fixed_gain[0]);
  for (int i = 0; i < 7; i++) {
    /* steps of 1.5 dB, taken as the fourth root of two; the last index
     * is 12 dB */
    CHECK(fabsf(aac_mps212_fixed_gain[i] - powf(2.0f, (float)i / 4.0f)) < 1e-5f,
          "fixed gain %d is %f", i, aac_mps212_fixed_gain[i]);
  }
  CHECK(aac_mps212_fixed_gain[7] == 4.0f, "fixed gain 7 is %f", aac_mps212_fixed_gain[7]);
  CHECK(aac_mps212_stride[0] == 1 && aac_mps212_stride[1] == 2 && aac_mps212_stride[2] == 5 &&
            aac_mps212_stride[3] == 28,
        "stride table");
  int map[AAC_MPS212_MAX_PARAM_BANDS + 1];
  const int bands[7] = {4, 5, 7, 10, 14, 20, 28};
  for (int b = 0; b < 7; b++) {
    for (int st = 0; st < 4; st++) {
      const int groups = aac_mps212_band_map(bands[b], aac_mps212_stride[st], map);
      CHECK(groups >= 1 && groups <= bands[b], "band map %d/%d: %d groups", bands[b], st, groups);
      CHECK(map[0] == 0 && map[groups] == bands[b], "band map %d/%d: borders %d..%d", bands[b],
            st, map[0], map[groups]);
      for (int g = 0; g < groups; g++) {
        CHECK(map[g + 1] > map[g], "band map %d/%d: group %d empty", bands[b], st, g);
      }
      if (st == 0) {
        CHECK(groups == bands[b], "band map %d stride 1: %d groups", bands[b], groups);
      }
      if (st == 3) {
        CHECK(groups == 1, "band map %d stride 28: %d groups", bands[b], groups);
      }
    }
  }
  const int map20[] = {0, 2, 4, 6, 8, 10, 12, 14, 16, 18, 20};
  const int groups = aac_mps212_band_map(20, 2, map);
  CHECK(groups == 10, "20 bands stride 2: %d groups", groups);
  for (int g = 0; g <= 10 && groups == 10; g++) {
    CHECK(map[g] == map20[g], "20 bands stride 2: border %d is %d", g, map[g]);
  }
  const int map5[] = {0, 5, 10, 15, 20};
  const int groups5 = aac_mps212_band_map(20, 5, map);
  CHECK(groups5 == 4, "20 bands stride 5: %d groups", groups5);
  for (int g = 0; g <= 4 && groups5 == 4; g++) {
    CHECK(map[g] == map5[g], "20 bands stride 5: border %d is %d", g, map[g]);
  }
  /* TSD codeword lengths: the bits of the binomial counts */
  CHECK(aac_mps212_tsd_bits_32[0] == 5 && aac_mps212_tsd_bits_64[0] == 6,
        "tsd one transient: %d/%d bits", aac_mps212_tsd_bits_32[0], aac_mps212_tsd_bits_64[0]);
}

/* ── mixing matrices against the reference tables ───────────────── */

struct PredRef {
  int ipd, icc, cld;
  float w, re, im, beta;
};

void test_matrices(void) {
  /* values of the tables of the reference decoder (ISO/IEC 23003-3
   * 7.11.2.3 evaluated for the quantiser indices) */
  const PredRef refs[] = {
      {0, 0, 0, 0.7071068f, 0.0f, 0.0f, 0.0f},
      {3, 2, 5, 0.5443075f, -0.3757902f, -0.2052283f, 0.1428018f},
      {12, 7, -12, 0.5f, 0.4996839f, -0.0175994f, 0.002507776f},
      {5, 4, 14, 0.4996043f, -0.5003644f, -0.001911473f, 0.005233571f},
      {15, 1, -15, 0.5f, 0.5f, 1.12e-08f, 1.12e-08f},
      {7, 6, -5, 0.5729033f, 0.3570331f, 0.0565524f, 0.2027584f},
  };
  for (const PredRef& p : refs) {
    float w, re, im, beta;
    aac_mps212_pred_weights(p.cld, p.icc, p.ipd, 1, &w, &re, &im, &beta);
    CHECK(fabsf(w - p.w) < 1e-5f && fabsf(re - p.re) < 1e-5f && fabsf(im - p.im) < 1e-5f &&
              fabsf(beta - p.beta) < 1e-5f,
          "pred weights cld %d icc %d ipd %d: %f %f %f %f, expected %f %f %f %f", p.cld, p.icc,
          p.ipd, w, re, im, beta, p.w, p.re, p.im, p.beta);
  }
  /* without phase coding the IPD index is ignored */
  float w1, re1, im1, b1, w2, re2, im2, b2;
  aac_mps212_pred_weights(3, 2, 5, 0, &w1, &re1, &im1, &b1);
  aac_mps212_pred_weights(3, 2, 0, 1, &w2, &re2, &im2, &b2);
  CHECK(w1 == w2 && re1 == re2 && im1 == im2 && b1 == b2, "pred weights: ipd used without phase");

  /* the parametric matrix: c_l, cos and sin of the reference tables */
  struct {
    int icc, cld;
    float c, s, co;
  } ps[] = {
      {0, -15, 3.17e-08f, 0.0f, 1.0f},
      {3, 0, 0.7071068f, 0.446699f, 0.8946843f},
      {7, 15, 1.0f, 4.7e-09f, 1.0f},
      {5, 7, 0.9876723f, 0.1565355f, 0.9876723f},
      {1, -8, 0.1115022f, 0.3158984f, 0.948793f},
  };
  for (const auto& p : ps) {
    float h[2][2][2];
    aac_mps212_ott_matrix(p.cld, p.icc, h);
    CHECK(fabsf(h[0][0][0] - p.c * p.co) < 1e-5f && fabsf(h[0][1][0] - p.c * p.s) < 1e-5f,
          "ott matrix cld %d icc %d: left %f %f, expected %f %f", p.cld, p.icc, h[0][0][0],
          h[0][1][0], p.c * p.co, p.c * p.s);
    float hm[2][2][2];
    aac_mps212_ott_matrix(-p.cld, p.icc, hm);
    CHECK(fabsf(h[1][0][0] - hm[0][0][0]) < 1e-6f && fabsf(h[1][1][0] + hm[0][1][0]) < 1e-6f,
          "ott matrix cld %d icc %d: right is not the mirrored left", p.cld, p.icc);
    CHECK(h[0][0][1] == 0.0f && h[1][1][1] == 0.0f, "ott matrix: imaginary parts");
  }
  /* energy preservation: |h00|^2 + |h01|^2 = c1^2 */
  for (int cld = -15; cld <= 15; cld += 5) {
    for (int icc = 0; icc < 8; icc++) {
      float h[2][2][2];
      aac_mps212_ott_matrix(cld, icc, h);
      const float el = h[0][0][0] * h[0][0][0] + h[0][1][0] * h[0][1][0];
      const float er = h[1][0][0] * h[1][0][0] + h[1][1][0] * h[1][1][0];
      CHECK(fabsf(el + er - 1.0f) < 1e-4f, "ott matrix cld %d icc %d: energy %f", cld, icc,
            el + er);
      const float ratio = 10.0f * log10f(el / er);
      CHECK(fabsf(ratio - aac_mps212_cld_db[cld + 15]) < 0.01f,
            "ott matrix cld %d icc %d: level difference %f dB", cld, icc, ratio);
    }
  }
}

/* ── hybrid filterbank ──────────────────────────────────────────── */

void test_hybrid(void) {
  /* the synthesis of the analysis gives the centre slot back: filtered
   * for the three split QMF bands, delayed for the others */
  float in[13][64][2];
  uint32_t seed = 12345;
  for (int n = 0; n < 13; n++) {
    for (int b = 0; b < 64; b++) {
      for (int c = 0; c < 2; c++) {
        seed = seed * 1664525u + 1013904223u;
        in[n][b][c] = (float)(int32_t)seed / 2147483648.0f;
      }
    }
  }
  float hyb[AAC_MPS212_HYBRID_BANDS][2];
  float out[64][2];
  aac_mps212_hybrid_analysis(in, hyb);
  aac_mps212_hybrid_synthesis(hyb, out);
  for (int b = 0; b < 3; b++) {
    for (int c = 0; c < 2; c++) {
      CHECK(fabsf(out[b][c] - in[6][b][c]) < 1e-4f, "hybrid band %d: %f for %f", b, out[b][c],
            in[6][b][c]);
    }
  }
  for (int b = 3; b < 64; b++) {
    CHECK(out[b][0] == in[6][b][0] && out[b][1] == in[6][b][1], "hybrid band %d changed", b);
  }
  /* a complex tone at the centre of the lowest hybrid band stays in it */
  for (int n = 0; n < 13; n++) {
    const double ph = 2.0 * M_PI * (n - 6) * (0.5 + 2.0) / 8.0; /* QMF band 0, quarter 2 */
    in[n][0][0] = (float)cos(ph);
    in[n][0][1] = (float)sin(ph);
  }
  aac_mps212_hybrid_analysis(in, hyb);
  int peak = 0;
  for (int h = 1; h < 6; h++) {
    if (hyb[h][0] * hyb[h][0] + hyb[h][1] * hyb[h][1] >
        hyb[peak][0] * hyb[peak][0] + hyb[peak][1] * hyb[peak][1]) {
      peak = h;
    }
  }
  CHECK(peak == 4, "a tone of the fifth quarter of QMF band 0 peaks in hybrid band %d", peak);
}

/* ── decorrelator ───────────────────────────────────────────────── */

void test_decorrelator(void) {
  AacUsacMps212Config cfg;
  memset(&cfg, 0, sizeof(cfg));
  cfg.freq_res = 1;
  cfg.fixed_gain_dmx = 4;
  cfg.high_rate_mode = 1;
  AacMps212State* s = aac_mps212_create(&cfg, 1, 1024, 2048, 48000);
  CHECK(s != NULL, "create");
  if (!s) {
    return;
  }
  const int slots = 512;
  std::vector<float> in((size_t)slots * AAC_MPS212_HYBRID_BANDS * 2);
  std::vector<float> out(in.size());
  uint32_t seed = 777;
  for (float& v : in) {
    seed = seed * 1664525u + 1013904223u;
    v = (float)(int32_t)seed / 2147483648.0f;
  }
  for (int t = 0; t < slots; t++) {
    aac_mps212_decorrelate(s, (const float(*)[2])&in[(size_t)t * AAC_MPS212_HYBRID_BANDS * 2],
                           (float(*)[2])&out[(size_t)t * AAC_MPS212_HYBRID_BANDS * 2]);
  }
  /* per hybrid band over the second half: the output energy is near the
   * input's (all-pass lattice, no ducking on a stationary signal) and
   * the correlation with the input small */
  for (int h = 0; h < AAC_MPS212_HYBRID_BANDS; h += 7) {
    double ei = 0, eo = 0, cr = 0, ci = 0;
    for (int t = slots / 2; t < slots; t++) {
      const float* x = &in[((size_t)t * AAC_MPS212_HYBRID_BANDS + h) * 2];
      const float* y = &out[((size_t)t * AAC_MPS212_HYBRID_BANDS + h) * 2];
      ei += x[0] * x[0] + x[1] * x[1];
      eo += y[0] * y[0] + y[1] * y[1];
      cr += x[0] * y[0] + x[1] * y[1];
      ci += x[0] * y[1] - x[1] * y[0];
    }
    const double gain = 10.0 * log10(eo / ei);
    const double corr = sqrt(cr * cr + ci * ci) / sqrt(ei * eo);
    CHECK(fabs(gain) < 2.0, "decorrelator band %d: gain %.2f dB", h, gain);
    CHECK(corr < 0.3, "decorrelator band %d: correlation %.2f", h, corr);
  }
  aac_mps212_destroy(s);
}

/* ── Mps212Data() by hand ───────────────────────────────────────── */

constexpr int kBands = 7; /* bsFreqRes 5 */
constexpr int kIpdBands = 3;

/* One parameter type of a frame: at most one coded set, PCM coded at
 * stride 1 on its quantiser scale. */
struct TypeSpec {
  int mode[2]; /* bsDataMode per set */
  int coarse;  /* bsQuantCoarse of the coded set */
  int values[kBands];
};

struct FrameSpec {
  int indep;
  int num_sets;
  int slot[2];
  TypeSpec cld, icc, ipd;
  int phase_mode, opd_smoothing;
};

void write_ecdata(AacBitWriter* w, int type, int num_sets, const TypeSpec& t) {
  for (int i = 0; i < num_sets; i++) {
    aac_bitwriter_write(w, (uint32_t)t.mode[i], 2);
  }
  for (int i = 0; i < num_sets; i++) {
    if (t.mode[i] != 3) {
      continue;
    }
    aac_bitwriter_write(w, 0, 1);                  /* bsDataPair */
    aac_bitwriter_write(w, (uint32_t)t.coarse, 1); /* bsQuantCoarse */
    aac_bitwriter_write(w, 0, 2);                  /* bsFreqResStride: every band */
    aac_bitwriter_write(w, 1, 1);                  /* bsPCMCoding */
    const int bands = type == 2 ? kIpdBands : kBands;
    int bits, offset;
    if (type == 0) {
      bits = t.coarse ? 4 : 5;
      offset = t.coarse ? 7 : 15;
    } else if (type == 1) {
      bits = t.coarse ? 2 : 3;
      offset = 0;
    } else {
      bits = t.coarse ? 3 : 4;
      offset = 0;
    }
    for (int b = 0; b < bands; b++) {
      aac_bitwriter_write(w, (uint32_t)(t.values[b] + offset), bits);
    }
  }
}

/* Mps212Data() of a high-rate, phase-coded configuration without
 * temporal shaping: explicit parameter slots, no smoothing. */
int write_frame(uint8_t* buf, int cap, const FrameSpec& f) {
  AacBitWriter w;
  aac_bitwriter_init(&w, buf, cap);
  aac_bitwriter_write(&w, 1, 1); /* bsFramingType */
  aac_bitwriter_write(&w, (uint32_t)(f.num_sets - 1), 3);
  for (int i = 0; i < f.num_sets; i++) {
    aac_bitwriter_write(&w, (uint32_t)f.slot[i], 5);
  }
  if (!f.indep) {
    aac_bitwriter_write(&w, 0, 1); /* bsIndependencyFlag */
  }
  write_ecdata(&w, 0, f.num_sets, f.cld);
  write_ecdata(&w, 1, f.num_sets, f.icc);
  aac_bitwriter_write(&w, (uint32_t)f.phase_mode, 1);
  if (f.phase_mode) {
    aac_bitwriter_write(&w, (uint32_t)f.opd_smoothing, 1);
    write_ecdata(&w, 2, f.num_sets, f.ipd);
  }
  for (int i = 0; i < f.num_sets; i++) {
    aac_bitwriter_write(&w, 0, 2); /* bsSmoothMode */
  }
  aac_bitwriter_byte_align(&w);
  return aac_bitwriter_bytes_written(&w);
}

/* The quantiser flag of each parameter set (the previous set's for a
 * kept set, the last set's for the appended frame-end set) and the
 * previous parameters surviving a damaged frame. */
void test_frame_fields(void) {
  AacUsacMps212Config cfg;
  memset(&cfg, 0, sizeof(cfg));
  cfg.freq_res = 5;
  cfg.fixed_gain_dmx = 4;
  cfg.high_rate_mode = 1;
  cfg.phase_coding = 1;
  AacMps212State* s = aac_mps212_create(&cfg, 1, 1024, 2048, 48000);
  CHECK(s != NULL, "create");
  if (!s) {
    return;
  }
  CHECK(aac_mps212_num_param_bands(s) == kBands && aac_mps212_num_ipd_bands(s) == kIpdBands,
        "bands %d, ipd bands %d", aac_mps212_num_param_bands(s), aac_mps212_num_ipd_bands(s));
  uint8_t buf[64];
  AacBitReader r;
  /* frame 1: one set at the frame end, every type coded on the fine scale */
  FrameSpec a = {};
  a.indep = 1;
  a.num_sets = 1;
  a.slot[0] = 31;
  a.cld = {{3, 0}, 0, {3, -2, 0, 1, 5, -7, 15}};
  a.icc = {{3, 0}, 0, {0, 1, 2, 3, 4, 5, 7}};
  a.ipd = {{3, 0}, 0, {5, 9, 12}};
  a.phase_mode = 1;
  a.opd_smoothing = 1;
  int n = write_frame(buf, sizeof(buf), a);
  aac_bitreader_init(&r, buf, n);
  CHECK(aac_mps212_read(s, &r, 1) == AAC_OK, "frame 1");
  CHECK(aac_mps212_num_param_sets(s) == 1 && aac_mps212_param(s, 0, 0, 0) == 3 &&
            aac_mps212_param(s, 0, 0, 6) == 15 && aac_mps212_param(s, 1, 0, 6) == 7 &&
            aac_mps212_param(s, 2, 0, 1) == 9,
        "frame 1: sets %d, cld %d %d, icc %d, ipd %d", aac_mps212_num_param_sets(s),
        aac_mps212_param(s, 0, 0, 0), aac_mps212_param(s, 0, 0, 6), aac_mps212_param(s, 1, 0, 6),
        aac_mps212_param(s, 2, 0, 1));
  CHECK(aac_mps212_phase_mode(s) == 1 && aac_mps212_quant_coarse(s, 2, 0) == 0,
        "frame 1: phase mode %d, ipd quantiser %d", aac_mps212_phase_mode(s),
        aac_mps212_quant_coarse(s, 2, 0));
  /* frame 2: two sets; the IPD of the first is kept (on the fine scale
   * of frame 1), the second is coded coarse */
  FrameSpec b = {};
  b.indep = 0;
  b.num_sets = 2;
  b.slot[0] = 15;
  b.slot[1] = 31;
  b.cld = {{1, 1}, 0, {0}};
  b.icc = {{1, 1}, 0, {0}};
  b.ipd = {{1, 3}, 1, {2, 3, 1}};
  b.phase_mode = 1;
  b.opd_smoothing = 1;
  n = write_frame(buf, sizeof(buf), b);
  aac_bitreader_init(&r, buf, n);
  CHECK(aac_mps212_read(s, &r, 0) == AAC_OK, "frame 2");
  CHECK(aac_mps212_num_param_sets(s) == 2 && aac_mps212_param_slot(s, 1) == 31, "frame 2: sets");
  CHECK(aac_mps212_param(s, 2, 0, 0) == 5 && aac_mps212_param(s, 2, 1, 0) == 4 &&
            aac_mps212_param(s, 2, 1, 1) == 6 && aac_mps212_param(s, 2, 1, 2) == 2 &&
            aac_mps212_param(s, 0, 1, 0) == 3,
        "frame 2: ipd %d / %d %d %d, cld %d", aac_mps212_param(s, 2, 0, 0),
        aac_mps212_param(s, 2, 1, 0), aac_mps212_param(s, 2, 1, 1), aac_mps212_param(s, 2, 1, 2),
        aac_mps212_param(s, 0, 1, 0));
  CHECK(aac_mps212_quant_coarse(s, 2, 0) == 0 && aac_mps212_quant_coarse(s, 2, 1) == 1,
        "frame 2: the kept set reads the coded set's quantiser (%d %d)",
        aac_mps212_quant_coarse(s, 2, 0), aac_mps212_quant_coarse(s, 2, 1));
  /* frame 3: one kept set short of the frame end; the appended end set
   * carries the coarse quantiser of frame 2's last set */
  FrameSpec c = {};
  c.indep = 0;
  c.num_sets = 1;
  c.slot[0] = 20;
  c.cld = {{1, 0}, 0, {0}};
  c.icc = {{1, 0}, 0, {0}};
  c.ipd = {{1, 0}, 0, {0}};
  c.phase_mode = 1;
  c.opd_smoothing = 1;
  n = write_frame(buf, sizeof(buf), c);
  aac_bitreader_init(&r, buf, n);
  CHECK(aac_mps212_read(s, &r, 0) == AAC_OK, "frame 3");
  CHECK(aac_mps212_num_param_sets(s) == 2 && aac_mps212_param_slot(s, 0) == 20 &&
            aac_mps212_param_slot(s, 1) == 31,
        "frame 3: the frame-end set");
  CHECK(aac_mps212_quant_coarse(s, 2, 0) == 1 && aac_mps212_quant_coarse(s, 2, 1) == 1 &&
            aac_mps212_param(s, 2, 1, 0) == 4,
        "frame 3: quantisers %d %d, ipd %d", aac_mps212_quant_coarse(s, 2, 0),
        aac_mps212_quant_coarse(s, 2, 1), aac_mps212_param(s, 2, 1, 0));
  /* a damaged frame (parameter slots out of order) keeps the previous
   * parameters as one set at the frame end, on the fine scale */
  FrameSpec d = b;
  d.slot[0] = 31;
  d.slot[1] = 15;
  n = write_frame(buf, sizeof(buf), d);
  aac_bitreader_init(&r, buf, n);
  CHECK(aac_mps212_read(s, &r, 0) == AAC_ERR_DECODE, "slots out of order accepted");
  CHECK(aac_mps212_num_param_sets(s) == 1 && aac_mps212_param_slot(s, 0) == 31 &&
            aac_mps212_data_mode(s, 2, 0) == 1 && aac_mps212_param(s, 2, 0, 0) == 4 &&
            aac_mps212_param(s, 2, 0, 1) == 6 && aac_mps212_param(s, 0, 0, 0) == 3 &&
            aac_mps212_quant_coarse(s, 2, 0) == 1,
        "damaged frame: sets %d, mode %d, ipd %d %d, cld %d, quantiser %d",
        aac_mps212_num_param_sets(s), aac_mps212_data_mode(s, 2, 0), aac_mps212_param(s, 2, 0, 0),
        aac_mps212_param(s, 2, 0, 1), aac_mps212_param(s, 0, 0, 0), aac_mps212_quant_coarse(s, 2, 0));
  /* a truncated frame too */
  n = write_frame(buf, sizeof(buf), b);
  aac_bitreader_init(&r, buf, 1);
  CHECK(aac_mps212_read(s, &r, 0) == AAC_ERR_DECODE, "truncated frame accepted");
  CHECK(aac_mps212_num_param_sets(s) == 1 && aac_mps212_param(s, 2, 0, 0) == 4 &&
            aac_mps212_param(s, 0, 0, 0) == 3 && aac_mps212_quant_coarse(s, 2, 0) == 1,
        "truncated frame: ipd %d, cld %d, quantiser %d", aac_mps212_param(s, 2, 0, 0),
        aac_mps212_param(s, 0, 0, 0), aac_mps212_quant_coarse(s, 2, 0));
  aac_mps212_destroy(s);
}

/* ── the residual delay the frame walker sets ───────────────────── */

/* With the SBR in front of MPS 212 the residual lags by the SBR output's
 * lag behind the core analysis: three time slots, plus the transposer's
 * delay when the configuration runs it; with stereoConfigIndex 3 the
 * downmix arrives at the analysis timing and the residual is not
 * delayed. */
void test_residual_delay(void) {
  struct Case {
    int sci, harmonic, expect;
  } cases[] = {{2, 0, 3 * 2}, {2, 1, (3 + AAC_SBR_USAC_HBE_DELAY) * 2}, {3, 1, 0}};
  for (const Case& k : cases) {
    AacDecoderState* dec = aac_decoder_state_create(48000, 0, aac_dsp_global());
    CHECK(dec != NULL, "decoder state");
    if (!dec) {
      continue;
    }
    AacUsacConfig c = {};
    c.sample_rate = 48000;
    c.frame_length = 1024;
    c.output_frame_length = 2048;
    c.sbr_ratio_index = AAC_USAC_SBR_2_1;
    c.core_sample_rate = 24000;
    c.channel_config_index = 2;
    c.num_channels = 2;
    c.num_elements = 1;
    c.elem[0].type = AAC_USAC_CPE;
    c.elem[0].stereo_config_index = k.sci;
    c.elem[0].sbr.harmonic_sbr = k.harmonic;
    c.elem[0].sbr.dflt.start_freq = 10;
    c.elem[0].sbr.dflt.stop_freq = 10;
    c.elem[0].mps.freq_res = 5;
    c.elem[0].mps.fixed_gain_dmx = 4;
    c.elem[0].mps.high_rate_mode = 1;
    c.elem[0].mps.residual_coding = 1;
    c.elem[0].mps.residual_bands = 2;
    const int ret = aac_usac_apply_config(dec, &c);
    CHECK(ret == AAC_OK, "stereoConfigIndex %d, harmonic %d: configuration refused (%d)", k.sci,
          k.harmonic, ret);
    if (ret == AAC_OK) {
      const AacUsacElement& e = dec->usac->elem[0];
      CHECK(e.sbr != NULL && e.mps != NULL, "stereoConfigIndex %d: modules", k.sci);
      if (e.sbr && e.mps) {
        const int sbr_lag = e.sbr->usac.history - e.sbr->usac.offset;
        const int delay = aac_mps212_residual_delay(e.mps);
        CHECK(delay == k.expect, "stereoConfigIndex %d, harmonic %d: residual delay %d, expected %d",
              k.sci, k.harmonic, delay, k.expect);
        CHECK(k.sci == 3 || sbr_lag == delay,
              "stereoConfigIndex %d, harmonic %d: the SBR output lags by %d slots", k.sci,
              k.harmonic, sbr_lag);
      }
    }
    aac_decoder_state_destroy(dec);
  }
}

/* ── the MPS 212 streams through the decoder ────────────────────── */

struct Decoded {
  std::vector<float> pcm;
  int channels = 0, rate = 0, frames = 0, errors = 0;
};

Decoded decode_stream(const std::vector<uint8_t>& in) {
  Decoded d;
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
  /* the FDK reference decodes carry no loudness normalisation */
  aac_decoder_set_loudness(dec, 0, -2400);
  std::vector<float> buf(AAC_DECODER_MAX_OUTPUT * 8);
  size_t pos = 0;
  for (;;) {
    if (pos < in.size()) {
      const int t = aac_decoder_feed(dec, in.data() + pos, (int)std::min<size_t>(in.size() - pos, 65536));
      if (t < 0) {
        break;
      }
      pos += (size_t)t;
    }
    const int n = aac_decoder_decode_next(dec, buf.data(), (int)buf.size());
    if (n == 0) {
      if (pos >= in.size()) {
        break;
      }
      continue;
    }
    if (n < 0) {
      d.errors++;
      if (n == AAC_ERR_UNSUPPORTED) {
        break;
      }
      continue;
    }
    d.frames++;
    d.channels = aac_decoder_channels(dec);
    d.rate = aac_decoder_sample_rate(dec);
    d.pcm.insert(d.pcm.end(), buf.begin(), buf.begin() + (size_t)n * d.channels);
  }
  aac_decoder_destroy(dec);
  return d;
}

std::vector<uint8_t> read_file(const std::string& path) {
  std::vector<uint8_t> data;
  FILE* f = fopen(path.c_str(), "rb");
  if (!f) {
    return data;
  }
  uint8_t buf[65536];
  size_t n;
  while ((n = fread(buf, 1, sizeof(buf), f)) > 0) {
    data.insert(data.end(), buf, buf + n);
  }
  fclose(f);
  return data;
}

/* A 16-bit PCM wav as interleaved floats. */
std::vector<float> read_wav16(const std::string& path, int* channels, int* rate) {
  std::vector<float> pcm;
  const std::vector<uint8_t> f = read_file(path);
  if (f.size() < 44 || memcmp(f.data(), "RIFF", 4) != 0 || memcmp(f.data() + 8, "WAVE", 4) != 0) {
    return pcm;
  }
  auto u32 = [&](size_t p) { return (uint32_t)f[p] | (f[p + 1] << 8) | (f[p + 2] << 16) | ((uint32_t)f[p + 3] << 24); };
  auto u16 = [&](size_t p) { return (int)(f[p] | (f[p + 1] << 8)); };
  size_t pos = 12;
  int bits = 0;
  while (pos + 8 <= f.size()) {
    const uint32_t len = u32(pos + 4);
    if (memcmp(f.data() + pos, "fmt ", 4) == 0 && pos + 24 <= f.size()) {
      *channels = u16(pos + 10);
      *rate = (int)u32(pos + 12);
      bits = u16(pos + 22);
    } else if (memcmp(f.data() + pos, "data", 4) == 0) {
      if (bits != 16) {
        return pcm;
      }
      const size_t n = std::min<size_t>(len, f.size() - pos - 8) / 2;
      pcm.resize(n);
      for (size_t i = 0; i < n; i++) {
        pcm[i] = (float)(int16_t)u16(pos + 8 + 2 * i) / 32768.0f;
      }
      return pcm;
    }
    pos += 8 + len + (len & 1);
  }
  return pcm;
}

/* The decode against the FDK decoder's below `band_hz` (the band the
 * core and the MPS 212 upmix cover; above it the SBR reconstruction of
 * the two outputs is not compared here): per channel the gain and the
 * SNR at the output's delay. */
void compare_with_fdk(const char* name, const Decoded& d, const char* ref_name, float band_hz,
                      double min_snr) {
  int ref_ch = 0, ref_rate = 0;
  const std::vector<float> ref = read_wav16(data_path(ref_name), &ref_ch, &ref_rate);
  CHECK(ref_ch == 2 && ref_rate == 48000 && !ref.empty(), "%s: unusable (%d ch, %d Hz)", ref_name,
        ref_ch, ref_rate);
  if (ref_ch != 2 || ref.empty() || d.channels != 2) {
    return;
  }
  for (int ch = 0; ch < 2; ch++) {
    const std::vector<float> a = lowpass_channel(ref, 2, ch, band_hz, 48000);
    const std::vector<float> b = lowpass_channel(d.pcm, 2, ch, band_hz, 48000);
    const size_t n = std::min(a.size(), b.size()) - 512;
    double aa = 0.0, ab = 0.0, bb = 0.0;
    for (size_t i = 512; i < n; i++) {
      aa += (double)a[i] * a[i];
      ab += (double)a[i] * b[i];
      bb += (double)b[i] * b[i];
    }
    const double gain_db = 10.0 * log10(bb / std::max(aa, 1e-20));
    const double snr = snr_db(a.data() + 512, b.data() + 512, n - 512);
    printf("%s channel %d below %.0f Hz against %s: gain %+.2f dB, snr %.1f dB\n", name, ch,
           band_hz, ref_name, gain_db, snr);
    CHECK(fabs(gain_db) < 0.5, "%s channel %d: gain %.2f dB", name, ch, gain_db);
    CHECK(snr >= min_snr, "%s channel %d: %.1f dB against the FDK decode, %.0f required", name, ch,
          snr, min_snr);
    (void)ab;
  }
}

void test_stream(const char* name, int min_frames, const char* ref_name = nullptr,
                 double min_snr = 0.0) {
  const std::vector<uint8_t> in = read_file(std::string(BAAC_TEST_DATA_DIR) + "/" + name);
  CHECK(!in.empty(), "%s: not found", name);
  if (in.empty()) {
    return;
  }
  const Decoded d = decode_stream(in);
  printf("%s: %d frames, %d errors, %d channels at %d Hz\n", name, d.frames, d.errors, d.channels,
         d.rate);
  CHECK(d.errors == 0, "%s: %d decode errors", name, d.errors);
  CHECK(d.frames >= min_frames, "%s: %d frames decoded", name, d.frames);
  CHECK(d.channels == 2 && d.rate == 48000, "%s: %d channels at %d Hz", name, d.channels, d.rate);
  if (d.channels != 2 || d.pcm.empty()) {
    return;
  }
  /* the two channels differ (the stereo tool is active) and stay in range */
  double el = 0, er = 0, ed = 0;
  float peak = 0.0f;
  for (size_t i = 0; i + 1 < d.pcm.size(); i += 2) {
    el += d.pcm[i] * d.pcm[i];
    er += d.pcm[i + 1] * d.pcm[i + 1];
    ed += (d.pcm[i] - d.pcm[i + 1]) * (d.pcm[i] - d.pcm[i + 1]);
    peak = std::max(peak, std::max(fabsf(d.pcm[i]), fabsf(d.pcm[i + 1])));
  }
  CHECK(el > 0 && er > 0, "%s: silent output", name);
  CHECK(ed > 1e-3 * (el + er), "%s: the channels are identical", name);
  CHECK(peak < 1.5f, "%s: peak %f", name, peak);
  if (ref_name) {
    compare_with_fdk(name, d, ref_name, 7000.0f, min_snr);
  }
}

int decode_file(const char* path, const char* out_path) {
  const std::vector<uint8_t> in = read_file(path);
  if (in.empty()) {
    printf("cannot read %s\n", path);
    return 1;
  }
  const Decoded d = decode_stream(in);
  printf("%d frames, %d errors, %d channels at %d Hz\n", d.frames, d.errors, d.channels, d.rate);
  FILE* f = fopen(out_path, "wb");
  if (!f) {
    return 1;
  }
  fwrite(d.pcm.data(), sizeof(float), d.pcm.size(), f);
  fclose(f);
  return 0;
}

}  // namespace

int main(int argc, char** argv) {
  if (argc == 3) {
    return decode_file(argv[1], argv[2]);
  }
  test_codebooks();
  test_tables();
  test_matrices();
  test_hybrid();
  test_decorrelator();
  test_frame_fields();
  test_residual_delay();
  test_stream("usac_music_48000_48000.loas", 40, "mps212_music_48000_48000_fdk.wav", 45.0);
  test_stream("usac_music_48000_64000.loas", 40);
  /* stereoConfigIndex 1 at the 8:3 and 4:1 ratios on an LPD core: the
   * low band is the core's, the comparison is of the timing and the gain */
  test_stream("usac_music_48000_24000.loas", 40, "mps212_music_48000_24000_fdk.wav", 18.0);
  test_stream("usac_music_48000_16000.loas", 20, "mps212_music_48000_16000_fdk.wav", 20.0);
  return test_summary("test_mps212");
}
