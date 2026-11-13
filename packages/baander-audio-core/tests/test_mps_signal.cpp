/* MPEG Surround signal path: the decorrelator tables against their
 * lattice form, the one-to-two and TTT matrices against hand-computed
 * values and the reference tables, the energy preservation of the trees,
 * the decorrelator's delay, all-pass gain and ducking, the residual path
 * against a direct QMF analysis, and synthetic 5.1 round trips through
 * the 525 and 5151 trees. */
#include <cmath>
#include <cstdio>
#include <cstring>
#include <vector>

#include "aac.h"
#include "mps212.h"
#include "../src/mps_signal.h"
#include "qmf.h"
#include "test_util.h"

namespace {

constexpr int kHyb = AAC_MPS_HYBRID_BANDS;
constexpr int kDelay = 6; /* hybrid delay in slots */

/* ── reference data ─────────────────────────────────────────────── */

/* The lattice reflection coefficients per reverb band and decorrelator
 * index, Q15, as libxaac's ixheaacd_mps_rom.c lists them
 * (lattice_coeff_0..3): the direct-form denominators of the decoder's
 * table are their step-up. */
const int16_t kLattice[4][10][20] = {
    {
        {8110, 14854, -721, -10099, -184, 12432, -11331, 12465, -10682, 16043, -7497, 11629, 6354, -4591, 1265, -13287, -9988, -8176, 1288, 8058},
        {12950, -13910, -940, 14139, 2363, -3795, 2241, -416, -8408, 6344, -13153, -8369, -1937, 11085, -6200, -2395, 6891, 14742, -11911, -6236},
        {-3093, -6822, -3280, -14320, -13615, 12472, -4846, -14818, 13379, 2005, 15988, 5390, -5426, 5669, 655, -8588, 11459, -7471, 10286, -10476},
        {2366, 6049, -13756, 2913, -8625, -8723, -8985, -15342, 9227, 10155, -7468, 2831, 891, -6691, 7556, 400, -7569, -8657, -12966, -11367},
        {-11282, -14428, 7268, -4787, -3090, -3437, -4548, 14585, -2585, 15548, -4660, -2907, 2025, 5685, 5069, 780, -3444, -3559, 15594, -4122},
        {2169, -4335, -11393, -12544, -2497, 10335, -14241, -8978, 12124, -3916, 557, 12809, -14038, -1488, -8897, -16158, 3775, 10115, -12445, -11236},
        {-15480, 11128, 5679, -12829, 9162, 9552, -79, -5695, 9434, 2943, 9378, -7576, 14333, -14831, 12429, 8710, 15670, -5983, 5400, 6747},
        {5168, 12943, 14877, 9817, -16381, 12904, 6187, 15637, 12085, -1642, -15797, 242, 1645, -7468, -13622, 5289, 4378, 9352, 11459, 10266},
        {-5177, -6344, -4778, -9231, -10040, 3244, 15611, -8235, 14035, -12101, 8575, 12016, 5492, 4961, 246, -3192, 531, -3660, -13481, 11426},
        {865, 7376, 15113, -6272, -11954, 7887, 1638, 10122, 10106, -15381, 11793, -14248, -13045, 13582, -6134, 5941, -5476, -10197, 3195, -13189},
    },
    {
        {9696, -12373, -11407, 10066, 13058, 11521, 5531, -4610, -49, 1681, 8736, -9739, -6380, 15594, -6622, 0, 0, 0, 0, 0},
        {1376, -4994, 11167, 16030, 5823, -1304, -1278, -2317, -12586, 15073, 5790, 8575, 2323, -5659, 2654, 0, 0, 0, 0, 0},
        {13281, 7124, -15263, 1579, 1697, 2363, -12757, -1897, 2969, -14818, -10751, 6213, -2081, 39, 2107, 0, 0, 0, 0, 0},
        {12796, 3251, -5400, 6370, -2051, 12812, -13110, 10125, 2949, -3775, -10020, -15283, -12875, -9293, -8962, 0, 0, 0, 0, 0},
        {-8592, 7700, 5780, -9899, 5774, 9568, 15758, -3939, -9159, -6187, 6111, 197, 11534, -3634, -9660, 0, 0, 0, 0, 0},
        {-15817, 7006, -8592, -15994, 15539, 12272, 4165, 15784, -7111, -197, 5993, -5482, -154, 12979, -14257, 0, 0, 0, 0, 0},
        {-12724, 1042, -9418, -1776, -9028, -6613, 15286, 13897, 14887, 11620, 2048, 682, -475, 8821, -7943, 0, 0, 0, 0, 0},
        {-10519, -14595, 5675, 5089, -9067, 1769, -5990, 8015, 4771, 3827, -11692, 8172, -8962, 12855, 918, 0, 0, 0, 0, 0},
        {-12970, -6629, 8929, -3883, 11213, -8192, 3883, 11993, -3418, -16024, -1153, -9670, -3831, -6013, 15643, 0, 0, 0, 0, 0},
        {1602, 15018, 14483, 3660, -9136, -7540, -6331, -15099, 770, -11600, 14267, -331, 4361, 12737, -16161, 0, 0, 0, 0, 0},
    },
    {
        {-5741, 5420, -2454, -6603, 826, -151, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {16289, -6898, -13556, -6062, 7278, 12629, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {15185, 11541, 13314, 14909, -14762, -14841, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {10093, 10335, 3205, 2372, 3480, 12583, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {-4797, -5043, 3903, -9916, 8392, 12167, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {5725, 8788, -7678, 16246, -15509, 13949, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {-16187, 3467, -9067, -3156, -14382, 3362, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {986, -1101, -1511, 15906, 10053, -15152, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {4037, -1655, 2697, 6111, 4755, 15670, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {-9470, 6619, 13471, -13690, -8235, 15801, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
    },
    {
        {4450, -1222, 1170, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {-14510, -7491, -15368, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {13242, -6698, 15204, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {-14379, 12465, -15699, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {9844, 7782, 14418, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {14202, 10882, -5259, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {-4951, 832, -5243, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {-12255, 4083, 8087, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {-16194, -9958, 15663, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
        {12504, -12783, -14529, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0},
    },
};


/* Entries of libxaac's cos_table / sin_table (Q15, [ICC index][CLD
 * index + 15]): the OTT matrix is c1 * (cos, sin) of alpha + beta. */
struct RefOtt {
  int icc, cld;
  int cos_q15, sin_q15;
};
const RefOtt kRefOtt[] = {
    {0, 0, 32768, 0},       {1, 0, 32248, 5816},    {1, -15, 30704, 11447}, {2, 5, 32473, 4386},
    {3, -5, 24703, 21529},  {4, 0, 27097, 18425},   {5, 0, 23170, 23170},   {6, 0, 14854, 29208},
    {7, 0, 2317, 32686},    {6, -15, -19300, 26481}, {7, 15, 32768, 0},     {4, 15, 32768, 0},
};

/* The residual-band entries for the negative ICCs (libxaac cos_table
 * rows 8..15 at [row][col] and [row][30 - col]): cos of the limited
 * ICC per CLD index. */
struct RefRes {
  int icc, cld;
  int cos_q15;
};
const RefRes kRefRes[] = {
    {7, 0, 19309},  {7, -1, 14799}, {7, -2, 9725},   {7, -3, 4211},  {7, -4, -1698},
    {7, -5, -8069}, {7, -6, -18908}, {7, -7, -32222}, {7, 7, 32754},  {7, 5, 31191},
    {6, 0, 19309},  {6, -5, -8069}, {6, 3, 28434},
};

/* ── helpers ────────────────────────────────────────────────────── */

double db(double p) { return 10.0 * log10(p + 1e-30); }

int nearest_cld_idx(double cld_db) {
  int best = 0;
  for (int i = 0; i < 31; i++) {
    if (fabs(aac_mps_cld_db[i] - cld_db) < fabs(aac_mps_cld_db[best] - cld_db)) {
      best = i;
    }
  }
  return best - 15;
}

int nearest_icc_idx(double icc) {
  int best = 0;
  for (int i = 0; i < 8; i++) {
    if (fabs(aac_mps_icc_value[i] - icc) < fabs(aac_mps_icc_value[best] - icc)) {
      best = i;
    }
  }
  return best;
}

AacMpsConfig base_config(int tree, int bands_res) {
  AacMpsConfig cfg;
  memset(&cfg, 0, sizeof(cfg));
  cfg.sampling_frequency = 48000;
  cfg.frame_length = 32;
  cfg.freq_res = bands_res;
  cfg.tree_config = tree;
  switch (tree) {
    case AAC_MPS_TREE_5151:
    case AAC_MPS_TREE_5152:
      cfg.num_ott_boxes = 5;
      cfg.num_input_channels = 1;
      cfg.num_output_channels = 6;
      break;
    case AAC_MPS_TREE_525:
      cfg.num_ott_boxes = 3;
      cfg.num_ttt_boxes = 1;
      cfg.num_input_channels = 2;
      cfg.num_output_channels = 6;
      break;
    default:
      cfg.num_ott_boxes = 1;
      cfg.num_input_channels = 1;
      cfg.num_output_channels = 2;
      break;
  }
  for (int i = 0; i < AAC_MPS_MAX_OTT_BOXES; i++) {
    cfg.ott_bands[i] = aac_mps212_freq_res_bands[bands_res];
  }
  cfg.ttt_mode_low[0] = 4;
  cfg.ttt_mode_high[0] = 4;
  return cfg;
}

/* Parameters with CLD 0, ICC 1 and CPC 0 everywhere, one set at the last
 * slot. */
void default_params(AacMpsFrameParams* p, int slots) {
  memset(p, 0, sizeof(*p));
  p->num_sets = 1;
  p->param_slot[0] = slots - 1;
}

/* The energy of the outputs of a band's matrices for a unit-energy
 * input per input channel (uncorrelated), with every decorrelated signal
 * uncorrelated and of the energy of its input: the sum over the outputs
 * of the direct and diffuse energies. */
double tree_energy(const AacMpsTreeLayout* t, const AacMpsBandMatrices* m, double* per_out) {
  double v[AAC_MPS_MAX_V] = {0};
  for (int row = 0; row < t->num_direct + t->num_decorr; row++) {
    for (int col = 0; col < t->num_inputs; col++) {
      v[row] += m->m1[row][col][0] * m->m1[row][col][0] + m->m1[row][col][1] * m->m1[row][col][1];
    }
  }
  double total = 0.0;
  for (int o = 0; o < t->num_outputs; o++) {
    /* the direct signals are coherent across the direct rows only when
     * they come from the same input; with one input the direct rows are
     * scaled copies of it */
    double e = 0.0;
    if (t->num_inputs == 1) {
      double a = 0.0;
      for (int j = 0; j < t->num_direct; j++) {
        a += m->m2_dry[o][j] * m->m1[j][0][0];
      }
      e += a * a;
    } else {
      for (int j = 0; j < t->num_direct; j++) {
        e += m->m2_dry[o][j] * m->m2_dry[o][j] * v[j];
      }
    }
    for (int j = 0; j < t->num_decorr; j++) {
      const double w = m->m2_wet[o][t->num_direct + j];
      e += w * w * v[t->num_direct + j];
    }
    if (per_out) per_out[o] = e;
    total += e;
  }
  return total;
}

/* 64-band QMF analysis of a channel (full scale 1.0 to 32768) into a
 * sequence of QMF frames of `slots` slots. */
std::vector<AacUsacQmfFrame> analyse(const std::vector<float>& pcm, int slots) {
  AacQmfAnalysis a;
  aac_qmf_analysis_init(&a, 64);
  const int n = (int)pcm.size() / 64 / slots;
  std::vector<AacUsacQmfFrame> frames(n);
  for (int f = 0; f < n; f++) {
    frames[f].slots = slots;
    for (int s = 0; s < slots; s++) {
      float in[64];
      for (int i = 0; i < 64; i++) {
        in[i] = pcm[(size_t)(f * slots + s) * 64 + i] * 32768.0f;
      }
      aac_qmf_analyze(&a, in, frames[f].x[s]);
    }
  }
  return frames;
}

std::vector<float> noise(int n, unsigned seed, float amp) {
  std::vector<float> x(n);
  for (int i = 0; i < n; i++) {
    seed = seed * 1664525u + 1013904223u;
    const float u1 = ((float)(seed >> 8) + 0.5f) / 16777216.0f;
    seed = seed * 1664525u + 1013904223u;
    const float u2 = ((float)(seed >> 8) + 0.5f) / 16777216.0f;
    x[i] = amp * sqrtf(-2.0f * logf(u1)) * cosf(2.0f * 3.14159265f * u2) * 0.3f;
  }
  return x;
}

/* A frame of hybrid bands: the hybrid analysis of a QMF frame, six
 * slots late. */
struct HybFrame {
  int slots;
  float x[AAC_MPS_MAX_SLOTS][kHyb][2];
};

std::vector<HybFrame> hybridise(const std::vector<AacUsacQmfFrame>& q) {
  float hist[13][64][2];
  memset(hist, 0, sizeof(hist));
  std::vector<HybFrame> h(q.size());
  for (size_t f = 0; f < q.size(); f++) {
    h[f].slots = q[f].slots;
    for (int n = 0; n < q[f].slots; n++) {
      memmove(hist[0], hist[1], sizeof(hist[0]) * 12);
      memcpy(hist[12], q[f].x[n], sizeof(hist[12]));
      aac_mps212_hybrid_analysis(hist, h[f].x[n]);
    }
  }
  return h;
}

void add_hyb(HybFrame* dst, const HybFrame& a, float ga, const HybFrame& b, float gb) {
  dst->slots = a.slots;
  for (int n = 0; n < a.slots; n++) {
    for (int k = 0; k < kHyb; k++) {
      for (int c = 0; c < 2; c++) {
        dst->x[n][k][c] = ga * a.x[n][k][c] + gb * b.x[n][k][c];
      }
    }
  }
}

/* The energy per parameter band of hybrid frames `first` to `last`
 * (exclusive). */
void band_energies(const std::vector<HybFrame>& f, int first, int last, int bands, double* e) {
  const uint8_t* kernel = aac_mps212_kernel(bands);
  for (int pb = 0; pb < bands; pb++) e[pb] = 0.0;
  for (int fr = first; fr < last; fr++) {
    for (int n = 0; n < f[fr].slots; n++) {
      for (int k = 0; k < kHyb; k++) {
        e[kernel[k]] += f[fr].x[n][k][0] * f[fr].x[n][k][0] + f[fr].x[n][k][1] * f[fr].x[n][k][1];
      }
    }
  }
}

/* ── the tables ─────────────────────────────────────────────────── */

void test_tables() {
  /* the denominators are the step-up of the lattice coefficients */
  double max_err = 0.0;
  for (int rb = 0; rb < 4; rb++) {
    const int order = aac_mps_decorr_order[rb];
    for (int seed = 0; seed < AAC_MPS_DECORR_SEEDS; seed++) {
      double a[21] = {1.0}, tmp[21] = {0};
      for (int i = 0; i < order; i++) {
        const double k = kLattice[rb][seed][i] / 32768.0;
        a[i + 1] = k;
        for (int j = 0; j < i; j++) {
          a[j + 1] = tmp[j] + k * tmp[i - j - 1];
        }
        for (int j = 0; j <= i; j++) {
          tmp[j] = a[j + 1];
        }
      }
      for (int i = 0; i <= order; i++) {
        const double err = fabs(a[i] - aac_mps_decorr_den[rb][seed][i]);
        if (err > max_err) max_err = err;
      }
      for (int i = order + 1; i <= AAC_MPS_DECORR_MAX_ORDER; i++) {
        CHECK(aac_mps_decorr_den[rb][seed][i] == 0.0f, "den[%d][%d][%d] beyond the order", rb,
              seed, i);
      }
      /* the all-pass is stable: every reflection coefficient inside the
       * unit circle (step-down of the table's denominator) */
      double b[21];
      for (int i = 0; i <= order; i++) b[i] = aac_mps_decorr_den[rb][seed][i];
      for (int n = order; n >= 1; n--) {
        const double k = b[n];
        CHECK(fabs(k) < 1.0, "den[%d][%d]: reflection %d of %f outside the unit circle", rb, seed,
              n, k);
        double nb[21];
        for (int j = 1; j < n; j++) {
          nb[j] = (b[j] - k * b[n - j]) / (1.0 - k * k);
        }
        for (int j = 1; j < n; j++) b[j] = nb[j];
      }
    }
  }
  printf("decorrelator denominators vs lattice step-up: max error %.5f\n", max_err);
  CHECK(max_err < 0.02, "decorrelator denominators differ from the lattice form by %f", max_err);
  CHECK(aac_mps_cld_db[15] == 0.0f && aac_mps_cld_db[30] == 150.0f, "CLD scale");
  CHECK(fabsf(aac_mps_cpc_value[20]) < 1e-6f && fabsf(aac_mps_cpc_value[0] + 2.0f) < 1e-6f &&
            fabsf(aac_mps_cpc_value[30] - 1.0f) < 1e-6f && fabsf(aac_mps_cpc_value[50] - 3.0f) < 1e-6f,
        "CPC scale: 0.1 per index from -2.0 to 3.0");
  CHECK(fabsf(aac_mps_lfe_gain[2] - 10.0f) < 1e-5f, "LFE gain 20 dB");
}

/* ── the box matrices ───────────────────────────────────────────── */

void test_ott_matrix() {
  float h[2][2];
  /* CLD 0, ICC 1: the trivial split, nothing from the decorrelator */
  aac_mps_ott_matrix(0, 0, 0, h);
  CHECK(fabsf(h[0][0] - 0.70710678f) < 1e-5f && fabsf(h[1][0] - 0.70710678f) < 1e-5f,
        "trivial split direct %f %f", h[0][0], h[1][0]);
  CHECK(fabsf(h[0][1]) < 1e-5f && fabsf(h[1][1]) < 1e-5f, "trivial split diffuse");
  /* extreme CLD: one-sided */
  aac_mps_ott_matrix(15, 3, 0, h);
  CHECK(fabsf(h[0][0] - 1.0f) < 1e-6f && fabsf(h[1][0]) < 1e-6f && fabsf(h[1][1]) < 1e-6f,
        "CLD +150 one-sided %f %f %f", h[0][0], h[1][0], h[1][1]);
  aac_mps_ott_matrix(-15, 3, 0, h);
  CHECK(fabsf(h[1][0] - 1.0f) < 1e-6f && fabsf(h[0][0]) < 1e-6f, "CLD -150 one-sided");
  /* ICC 0 at CLD 0: the orthogonal split */
  aac_mps_ott_matrix(0, 5, 0, h);
  CHECK(fabsf(h[0][0] - 0.5f) < 1e-5f && fabsf(h[0][1] - 0.5f) < 1e-5f &&
            fabsf(h[1][0] - 0.5f) < 1e-5f && fabsf(h[1][1] + 0.5f) < 1e-5f,
        "ICC 0 split %f %f %f %f", h[0][0], h[0][1], h[1][0], h[1][1]);
  /* energy preservation for every index pair */
  for (int cld = -15; cld <= 15; cld++) {
    for (int icc = 0; icc < 8; icc++) {
      aac_mps_ott_matrix(cld, icc, 0, h);
      const double e = h[0][0] * h[0][0] + h[0][1] * h[0][1] + h[1][0] * h[1][0] + h[1][1] * h[1][1];
      CHECK(fabs(e - 1.0) < 1e-5, "OTT cld %d icc %d energy %f", cld, icc, e);
      const double r = pow(10.0, aac_mps_cld_db[cld + 15] / 10.0);
      const double c1sq = h[0][0] * h[0][0] + h[0][1] * h[0][1];
      CHECK(fabs(c1sq - r / (1.0 + r)) < 1e-5, "OTT cld %d icc %d level", cld, icc);
    }
  }
  /* against the reference tables */
  for (const RefOtt& ref : kRefOtt) {
    aac_mps_ott_matrix(ref.cld, ref.icc, 0, h);
    const double r = pow(10.0, aac_mps_cld_db[ref.cld + 15] / 10.0);
    const double c1 = sqrt(r / (1.0 + r));
    CHECK(fabs(h[0][0] - c1 * ref.cos_q15 / 32768.0) < 2e-4,
          "OTT ref icc %d cld %d cos: %f vs %f", ref.icc, ref.cld, h[0][0],
          c1 * ref.cos_q15 / 32768.0);
    CHECK(fabs(h[0][1] - c1 * ref.sin_q15 / 32768.0) < 2e-4, "OTT ref icc %d cld %d sin: %f vs %f",
          ref.icc, ref.cld, h[0][1], c1 * ref.sin_q15 / 32768.0);
  }
  /* residual bands: no diffuse part, and the negative ICCs limited near
   * CLD 0 as the reference tables have them */
  for (const RefRes& ref : kRefRes) {
    aac_mps_ott_matrix(ref.cld, ref.icc, 1, h);
    const double r = pow(10.0, aac_mps_cld_db[ref.cld + 15] / 10.0);
    const double c1 = sqrt(r / (1.0 + r));
    CHECK(h[0][1] == 0.0f && h[1][1] == 0.0f, "residual band diffuse weights");
    CHECK(fabs(h[0][0] - c1 * ref.cos_q15 / 32768.0) < 3e-4,
          "OTT residual icc %d cld %d: %f vs %f", ref.icc, ref.cld, h[0][0],
          c1 * ref.cos_q15 / 32768.0);
  }
  /* positive ICCs and large CLDs are not limited */
  float h2[2][2];
  aac_mps_ott_matrix(3, 2, 0, h);
  aac_mps_ott_matrix(3, 2, 1, h2);
  CHECK(fabsf(h[0][0] - h2[0][0]) < 1e-6f && fabsf(h[1][0] - h2[1][0]) < 1e-6f,
        "residual band keeps a positive ICC");
  aac_mps_ott_matrix(-9, 7, 0, h);
  aac_mps_ott_matrix(-9, 7, 1, h2);
  CHECK(fabsf(h[0][0] - h2[0][0]) < 1e-6f, "residual band keeps ICC -0.99 at -22 dB");
}

void test_ttt_matrix() {
  float m[3][3];
  /* prediction with zero CPCs: the pseudo-inverse of the downmix
   * (l + c, r + c), the centre row scaled by sqrt 2 */
  aac_mps_ttt_matrix(1, 0, 0, 0, 0, 0, 0, m);
  const float l = 1.0f, r = 2.0f, c = 3.0f; /* c = l + r: in the row space */
  const float L0 = l + c, R0 = r + c;
  CHECK(fabsf(m[0][0] * L0 + m[0][1] * R0 - l) < 1e-5f, "TTT pseudo-inverse l");
  CHECK(fabsf(m[1][0] * L0 + m[1][1] * R0 - r) < 1e-5f, "TTT pseudo-inverse r");
  CHECK(fabsf(m[2][0] * L0 + m[2][1] * R0 - sqrtf(2.0f) * c) < 1e-5f, "TTT pseudo-inverse c");
  /* the residual column completes the null space */
  const float res = 1.0f;
  CHECK(fabsf(m[0][2] - res / 3.0f) < 1e-6f && fabsf(m[1][2] - res / 3.0f) < 1e-6f &&
            fabsf(m[2][2] + sqrtf(2.0f) / 3.0f) < 1e-6f,
        "TTT residual column");
  /* CPC 1, 1 (index 10): the inputs pass straight through */
  aac_mps_ttt_matrix(1, 10, 10, 0, 0, 0, 0, m);
  CHECK(fabsf(m[0][0] - 1.0f) < 1e-6f && fabsf(m[0][1]) < 1e-6f && fabsf(m[1][1] - 1.0f) < 1e-6f &&
            fabsf(m[2][0]) < 1e-6f && fabsf(m[2][1]) < 1e-6f,
        "TTT CPC 1 pass-through");
  /* both prediction modes divide the input columns by the ICC outside
   * the residual bands (the energy compensation); mode 0 has M2 scale
   * the direct parts back by it; a residual band is not scaled */
  float m0[3][3], m1[3][3], mr[3][3];
  aac_mps_ttt_matrix(0, 0, 0, 3, 0, 0, 0, m0);
  aac_mps_ttt_matrix(1, 0, 0, 3, 0, 0, 0, m1);
  aac_mps_ttt_matrix(1, 0, 0, 3, 0, 0, 1, mr);
  CHECK(fabsf(m0[0][0] * aac_mps_icc_value[3] - m[0][0] * 2.0f / 3.0f) < 1e-5f,
        "TTT mode 0 ICC scaling");
  CHECK(fabsf(m1[0][0] - m0[0][0]) < 1e-6f && fabsf(m1[2][1] - m0[2][1]) < 1e-6f,
        "TTT mode 1 ICC scaling");
  CHECK(fabsf(mr[0][0] - 2.0f / 3.0f) < 1e-6f && fabsf(m1[0][2] - mr[0][2]) < 1e-6f,
        "TTT residual band unscaled");
  /* energy modes: with CLD1 the level of l + r against the centre
   * input and CLD2 the level of l against r, uncorrelated l and r come
   * back at their energies; the centre is the energy-weighted mean of
   * the two downmix channels times sqrt 2, so its energy is
   * p_c (1 + a b) with a, b its weights, between p_c and the doubled
   * p_c of a lone centre */
  const int cld1 = 3, cld2 = 2; /* 6 dB, 4 dB */
  const double p_r = 1.0, p_l = pow(10.0, 0.4), p_c = (p_l + p_r) / pow(10.0, 0.6);
  const double cov[2][2] = {{p_l + p_c, p_c}, {p_c, p_r + p_c}};
  const double a = sqrt(p_c / (p_l + p_c)), b = sqrt(p_c / (p_r + p_c));
  const double e_c = p_c * (1.0 + a * b);
  for (int mode = 2; mode <= 5; mode++) {
    aac_mps_ttt_matrix(mode, 0, 0, 0, cld1, cld2, 0, m);
    double e[3];
    for (int row = 0; row < 3; row++) {
      e[row] = 0.0;
      for (int i = 0; i < 2; i++) {
        for (int j = 0; j < 2; j++) {
          e[row] += m[row][i] * cov[i][j] * m[row][j];
        }
      }
    }
    CHECK(fabs(e[0] / p_l - 1.0) < 0.02, "TTT mode %d l energy %f vs %f", mode, e[0], p_l);
    CHECK(fabs(e[1] / p_r - 1.0) < 0.02, "TTT mode %d r energy %f vs %f", mode, e[1], p_r);
    CHECK(fabs(e[2] / e_c - 1.0) < 0.02, "TTT mode %d c energy %f vs %f", mode, e[2], e_c);
  }
  /* a lone centre (CLD1 -150 dB) comes back doubled */
  aac_mps_ttt_matrix(4, 0, 0, 0, -15, 0, 0, m);
  CHECK(fabsf(m[0][0]) < 1e-6f && fabsf(m[1][1]) < 1e-6f &&
            fabs(m[2][0] * m[2][0] + 2.0 * m[2][0] * m[2][1] + m[2][1] * m[2][1] - 2.0) < 1e-4,
        "TTT lone centre %f %f %f %f", m[0][0], m[1][1], m[2][0], m[2][1]);
  /* centre subtraction removes the centre from l: with CLD2 0 and a
   * loud centre the off-diagonal weight is negative */
  aac_mps_ttt_matrix(2, 0, 0, 0, -5, 0, 0, m);
  CHECK(m[0][1] < -0.1f && m[1][0] < -0.1f, "TTT centre subtraction weights %f %f", m[0][1],
        m[1][0]);
  /* the matrix inversion: identity when the surrounds are silent */
  float h[2][2][2];
  aac_mps_ttt_inverse(15, 15, 1, 0, 0, h);
  CHECK(fabsf(h[0][0][0] - 1.0f) < 1e-4f && fabsf(h[1][1][0] - 1.0f) < 1e-4f &&
            fabsf(h[0][1][0]) < 1e-4f && fabsf(h[1][0][0]) < 1e-4f && fabsf(h[0][0][1]) < 1e-4f,
        "TTT inverse identity");
  /* and the inverse of the matrix-compatible downmix otherwise */
  aac_mps_ttt_inverse(2, -3, 1, 4, -7, h);
  {
    /* rebuild the downmix from the same weights */
    const double w1 = pow(10.0, -aac_mps_cld_db[2 + 15] / 10.0);
    const double w2 = pow(10.0, -aac_mps_cld_db[-3 + 15] / 10.0);
    const double c1 = 1.0 / 3.0 + 2.0 / 3.0 * aac_mps_cpc_value[4 + 20];
    const double c2 = -1.0 - 2.0 * aac_mps_cpc_value[-7 + 20];
    const double g1 = c1 * w1 / (1.0 + w1), g2 = c2 * w2 / (1.0 + w2);
    const double l1 = sqrt(1.0 - 2.0 * g1 + 2.0 * g1 * g1), l2 = sqrt(1.0 - 2.0 * g2 + 2.0 * g2 * g2);
    const double d[2][2][2] = {{{(1.0 - g1) / l1, g1 / l1}, {0.0, -g2 / l2 / sqrt(3.0)}},
                               {{0.0, -g1 / l1 / sqrt(3.0)}, {(1.0 - g2) / l2, -g2 / l2}}};
    for (int i = 0; i < 2; i++) {
      for (int j = 0; j < 2; j++) {
        double re = 0.0, im = 0.0;
        for (int k = 0; k < 2; k++) {
          re += d[i][k][0] * h[k][j][0] - d[i][k][1] * h[k][j][1];
          im += d[i][k][0] * h[k][j][1] + d[i][k][1] * h[k][j][0];
        }
        CHECK(fabs(re - (i == j ? 1.0 : 0.0)) < 1e-4 && fabs(im) < 1e-4,
              "TTT inverse product [%d][%d] = %f + j%f", i, j, re, im);
      }
    }
  }
}

/* ── the trees ──────────────────────────────────────────────────── */

void test_tree_energy() {
  const int trees[3] = {AAC_MPS_TREE_5151, AAC_MPS_TREE_5152, AAC_MPS_TREE_212};
  for (int ti = 0; ti < 3; ti++) {
    AacMpsConfig cfg = base_config(trees[ti], 2); /* 20 bands */
    AacMpsTreeLayout t;
    CHECK(aac_mps_tree_layout(&cfg, &t) == AAC_OK, "layout of tree %d", trees[ti]);
    AacMpsFrameParams p;
    default_params(&p, 32);
    unsigned seed = 99u + (unsigned)ti;
    for (int box = 0; box < AAC_MPS_MAX_OTT_BOXES; box++) {
      for (int pb = 0; pb < 20; pb++) {
        seed = seed * 1664525u + 1013904223u;
        p.ott_cld[box][0][pb] = (int)((seed >> 8) % 31) - 15;
        seed = seed * 1664525u + 1013904223u;
        p.ott_icc[box][0][pb] = (int)((seed >> 8) % 8);
      }
    }
    double worst = 0.0;
    for (int pb = 0; pb < 20; pb++) {
      AacMpsBandMatrices m;
      aac_mps_tree_matrices(&cfg, &t, &p, 0, pb, &m);
      double per_out[6];
      const double e = tree_energy(&t, &m, per_out);
      if (fabs(e - 1.0) > worst) worst = fabs(e - 1.0);
      if (trees[ti] != AAC_MPS_TREE_212) {
        /* the LFE box's share: C against LFE by its CLD alone */
        const int lfe_box = trees[ti] == AAC_MPS_TREE_5151 ? 4 : 2;
        const double r = pow(10.0, aac_mps_cld_db[p.ott_cld[lfe_box][0][pb] + 15] / 10.0);
        const double share = per_out[3] / (per_out[2] + per_out[3]);
        CHECK(fabs(share - 1.0 / (1.0 + r)) < 1e-4, "tree %d band %d LFE share %f vs %f",
              trees[ti], pb, share, 1.0 / (1.0 + r));
      }
    }
    printf("tree %d: worst energy deviation %.2e\n", trees[ti], worst);
    CHECK(worst < 1e-4, "tree %d does not preserve energy (%e)", trees[ti], worst);
  }
  /* 525 with the OTT boxes alone (CPC 1: the inputs pass through the
   * TTT): the energy of each input lands on its pair */
  {
    AacMpsConfig cfg = base_config(AAC_MPS_TREE_525, 2);
    cfg.ttt_mode_low[0] = cfg.ttt_mode_high[0] = 1;
    AacMpsTreeLayout t;
    CHECK(aac_mps_tree_layout(&cfg, &t) == AAC_OK, "525 layout");
    CHECK(t.num_direct == 3 && t.num_decorr == 2 && t.num_inputs == 2, "525 layout counts");
    AacMpsFrameParams p;
    default_params(&p, 32);
    for (int pb = 0; pb < 20; pb++) {
      p.ttt_cpc1[0][0][pb] = p.ttt_cpc2[0][0][pb] = 10;
      p.ott_cld[1][0][pb] = 4;
      p.ott_icc[1][0][pb] = 3;
      p.ott_cld[2][0][pb] = -6;
      p.ott_icc[2][0][pb] = 6;
    }
    AacMpsBandMatrices m;
    aac_mps_tree_matrices(&cfg, &t, &p, 0, 5, &m);
    double per_out[6];
    const double e = tree_energy(&t, &m, per_out);
    CHECK(fabs(e - 2.0) < 1e-4, "525 pass-through energy %f", e);
    const double r = pow(10.0, aac_mps_cld_db[4 + 15] / 10.0);
    CHECK(fabs(per_out[0] / (per_out[0] + per_out[4]) - r / (1.0 + r)) < 1e-4, "525 L/Ls level");
    CHECK(fabs(per_out[0] + per_out[4] - 1.0) < 1e-4 && fabs(per_out[1] + per_out[5] - 1.0) < 1e-4,
          "525 pair energies %f %f", per_out[0] + per_out[4], per_out[1] + per_out[5]);
    CHECK(per_out[2] < 1e-6 && per_out[3] < 1e-6, "525 centre silent with CPC 1");
    /* TTT mode 0 adds the third decorrelator */
    cfg.ttt_mode_low[0] = 0;
    CHECK(aac_mps_tree_layout(&cfg, &t) == AAC_OK && t.num_decorr == 3 && t.ttt_decorr_band[3],
          "525 mode 0 layout");
    for (int pb = 0; pb < 20; pb++) p.ttt_icc[0][0][pb] = 2;
    AacMpsBandMatrices m1;
    aac_mps_tree_matrices(&cfg, &t, &p, 0, 5, &m1);
    cfg.ttt_mode_low[0] = 1;
    CHECK(aac_mps_tree_layout(&cfg, &t) == AAC_OK, "525 mode 1 layout");
    aac_mps_tree_matrices(&cfg, &t, &p, 0, 5, &m);
    cfg.ttt_mode_low[0] = 0;
    CHECK(aac_mps_tree_layout(&cfg, &t) == AAC_OK, "525 mode 0 layout again");
    const float rho = aac_mps_icc_value[2];
    const float g_dd = sqrtf((1.0f - rho * rho) / 3.0f);
    /* the TTT decorrelator feeds L, R and C with the energy the
     * prediction loses, the centre's weight carrying the sqrt 2 of the
     * centre row and the C/LFE split; the direct parts of those three
     * carry the ICC on top of the mode 1 matrices (M1 is shared) */
    const float c_lfe = sqrtf(pow(10.0, aac_mps_cld_db[0 + 15] / 10.0) / 2.0);
    CHECK(fabsf(m1.m2_wet[0][5] - g_dd * sqrtf(r / (1.0 + r))) < 1e-5f,
          "525 TTT decorrelator weight %f", m1.m2_wet[0][5]);
    CHECK(fabsf(m1.m2_wet[2][5] + sqrtf(2.0f) * g_dd * c_lfe) < 1e-5f,
          "525 TTT decorrelator centre weight %f", m1.m2_wet[2][5]);
    CHECK(fabsf(m1.m2_wet[4][5]) < 1e-6f && fabsf(m1.m2_wet[3][5]) < 1e-6f,
          "525 TTT decorrelator reaches no surround or LFE");
    CHECK(fabsf(m1.m2_dry[0][0] - rho * m.m2_dry[0][0]) < 1e-6f &&
              fabsf(m1.m2_dry[1][1] - rho * m.m2_dry[1][1]) < 1e-6f &&
              fabsf(m1.m2_dry[2][2] - rho * m.m2_dry[2][2]) < 1e-6f &&
              fabsf(m1.m2_dry[4][0] - m.m2_dry[4][0]) < 1e-6f,
          "525 mode 0 direct parts scaled by the ICC");
    for (int row = 0; row < 3; row++) {
      for (int col = 0; col < 3; col++) {
        CHECK(fabsf(m1.m1[row][col][0] - m.m1[row][col][0]) < 1e-6f, "525 mode 0 and 1 share M1");
      }
    }
    CHECK(fabsf(m1.m1[5][0][0] - m1.m1[2][0][0]) < 1e-6f, "525 TTT decorrelator input");
  }
}

/* ── the decorrelator ───────────────────────────────────────────── */

void test_decorrelator() {
  AacMpsDecorr d;
  aac_mps_decorr_init(&d, 0, 0);
  /* the splits are QMF bands: hybrid 10 is QMF band 3, 25 is 18, 30 is 23 */
  CHECK(d.reverb_band[0] == 0 && d.reverb_band[7] == 0 && d.reverb_band[8] == 1 &&
            d.reverb_band[25] == 2 && d.reverb_band[30] == 3 && d.reverb_band[70] == 3,
        "reverb bands %d %d %d %d %d", d.reverb_band[0], d.reverb_band[10], d.reverb_band[25],
        d.reverb_band[30], d.reverb_band[70]);
  aac_mps_decorr_init(&d, 2, 2);
  CHECK(d.reverb_band[0] == 1 && d.reverb_band[30] == 2, "decorr config 2 reverb bands");
  /* an impulse appears after the band's delay, and nowhere before; the
   * all-pass passes its energy (without the energy adjustment, which
   * would duck the ringing of an impulse) */
  const int probe[3] = {12, 25, 70};
  for (int pi = 0; pi < 3; pi++) {
    aac_mps_decorr_init(&d, 1, 0);
    d.ducking = 0;
    const int k = probe[pi];
    const int delay = aac_mps_decorr_delay[d.reverb_band[k]];
    float in[kHyb][2], out[kHyb][2];
    int first = -1;
    double energy = 0.0;
    for (int n = 0; n < 400; n++) {
      memset(in, 0, sizeof(in));
      if (n == 0) in[k][0] = 1.0f;
      aac_mps_decorr_slot(&d, in, out);
      if (first < 0 && fabsf(out[k][0]) > 1e-6f) first = n;
      energy += out[k][0] * out[k][0] + out[k][1] * out[k][1];
      for (int j = 0; j < kHyb; j++) {
        if (j != k) CHECK(out[j][0] == 0.0f && out[j][1] == 0.0f, "band %d leaks", j);
      }
    }
    CHECK(first == delay, "band %d: first output at slot %d, delay %d", k, first, delay);
    CHECK(energy > 0.95 && energy < 1.001, "band %d: impulse response energy %f", k, energy);
  }
  /* stationary noise: unit gain within the ducking tolerance, and the
   * output decorrelated from the input */
  aac_mps_decorr_init(&d, 3, 0);
  double e_in = 0.0, e_out = 0.0, cross_re = 0.0, cross_im = 0.0;
  unsigned seed = 5u;
  for (int n = 0; n < 2000; n++) {
    float in[kHyb][2], out[kHyb][2];
    for (int k = 0; k < kHyb; k++) {
      for (int c = 0; c < 2; c++) {
        seed = seed * 1664525u + 1013904223u;
        in[k][c] = (float)(seed >> 8) / 8388608.0f - 1.0f;
      }
    }
    aac_mps_decorr_slot(&d, in, out);
    if (n < 200) continue;
    for (int k = 0; k < kHyb; k++) {
      e_in += in[k][0] * in[k][0] + in[k][1] * in[k][1];
      e_out += out[k][0] * out[k][0] + out[k][1] * out[k][1];
      cross_re += in[k][0] * out[k][0] + in[k][1] * out[k][1];
      cross_im += in[k][0] * out[k][1] - in[k][1] * out[k][0];
    }
  }
  const double gain = e_out / e_in;
  const double corr = sqrt(cross_re * cross_re + cross_im * cross_im) / sqrt(e_in * e_out);
  printf("decorrelator on noise: gain %.3f, correlation %.3f\n", gain, corr);
  CHECK(fabs(gain - 1.0) < 0.1, "decorrelator gain %f", gain);
  CHECK(corr < 0.1, "decorrelator correlation %f", corr);
  /* ducking: on steady noise the energy adjustment is near unity; after
   * a drop of the input by 40 dB it holds the ringing tail of the long
   * filters of the lowest reverb band well under the free tail */
  {
    AacMpsDecorr raw, duck;
    aac_mps_decorr_init(&raw, 0, 0);
    aac_mps_decorr_init(&duck, 0, 0);
    raw.ducking = 0;
    float in[kHyb][2], out_raw[kHyb][2], out_duck[kHyb][2];
    double loud_raw = 0.0, loud_duck = 0.0, tail_raw = 0.0, tail_duck = 0.0;
    for (int n = 0; n < 330; n++) {
      const float amp = n < 300 ? 1.0f : 0.01f;
      for (int k = 0; k < kHyb; k++) {
        for (int c = 0; c < 2; c++) {
          seed = seed * 1664525u + 1013904223u;
          in[k][c] = amp * ((float)(seed >> 8) / 8388608.0f - 1.0f);
        }
      }
      aac_mps_decorr_slot(&raw, in, out_raw);
      aac_mps_decorr_slot(&duck, in, out_duck);
      if (n < 100) continue;
      for (int k = 0; k < kHyb; k++) {
        if (raw.reverb_band[k] != 0) continue;
        const double er = out_raw[k][0] * out_raw[k][0] + out_raw[k][1] * out_raw[k][1];
        const double ed = out_duck[k][0] * out_duck[k][0] + out_duck[k][1] * out_duck[k][1];
        if (n < 300) {
          loud_raw += er;
          loud_duck += ed;
        } else {
          tail_raw += er;
          tail_duck += ed;
        }
      }
    }
    printf("decorrelator ducking: steady gain %.3f, tail %.3f of the free tail\n",
           loud_duck / loud_raw, tail_duck / tail_raw);
    CHECK(fabs(loud_duck / loud_raw - 1.0) < 0.2, "ducking on steady noise %f", loud_duck / loud_raw);
    CHECK(tail_duck < 0.5 * tail_raw, "ducking leaves %f of the tail", tail_duck / tail_raw);
  }
}

/* ── the residual path ──────────────────────────────────────────── */

/* The residual of the 212 box reaches the output through the hybrid
 * filterbank as the QMF analysis of its samples, delayed by the hybrid
 * delay, with the weights 1 (left) and -1 (right). */
void test_residual_path() {
  AacMpsConfig cfg = base_config(AAC_MPS_TREE_212, 2);
  cfg.residual_coding = 1;
  cfg.residual_present[0] = 1;
  cfg.residual_bands[0] = 20;
  cfg.residual_frames_per_spatial_frame = 2;
  AacMpsSignalState* s = aac_mps_signal_create(&cfg, 1024, 16);
  CHECK(s != NULL, "212 state with residual");
  if (!s) return;
  const int slots = 32, frames = 6;
  std::vector<float> res = noise(slots * 64 * frames, 77u, 0.5f);
  std::vector<AacUsacQmfFrame> ref = analyse(res, slots);
  AacUsacQmfFrame dmx, left, right;
  memset(&dmx, 0, sizeof(dmx));
  dmx.slots = slots;
  AacMpsFrameParams p;
  default_params(&p, slots);
  const AacUsacQmfFrame* in[1] = {&dmx};
  AacUsacQmfFrame* out[2] = {&left, &right};
  double num = 0.0, den = 0.0, anti = 0.0;
  /* a flat sequence of output slots against the reference delayed by
   * the hybrid delay */
  std::vector<float> got, want;
  for (int f = 0; f < frames; f++) {
    aac_mps_signal_set_residual(s, 0, &res[(size_t)f * slots * 64], slots * 64);
    aac_mps_signal_apply(s, &p, in, out);
    for (int n = 0; n < slots; n++) {
      for (int b = 0; b < 64; b++) {
        got.push_back(left.x[n][b][0]);
        got.push_back(left.x[n][b][1]);
        anti += (left.x[n][b][0] + right.x[n][b][0]) * (left.x[n][b][0] + right.x[n][b][0]);
      }
    }
    for (int n = 0; n < slots; n++) {
      for (int b = 0; b < 64; b++) {
        want.push_back(ref[f].x[n][b][0]);
        want.push_back(ref[f].x[n][b][1]);
      }
    }
  }
  const size_t stride = 128; /* floats per slot */
  /* from the second frame: the first interpolates its weights up from
   * the silence before it */
  for (size_t i = (size_t)(slots + kDelay + 2) * stride; i + kDelay * stride < got.size(); i++) {
    const double w = want[i], g = got[i + kDelay * stride];
    num += w * w;
    den += (w - g) * (w - g);
  }
  const double snr = 10.0 * log10(num / (den + 1e-20));
  printf("residual path vs direct QMF analysis: %.1f dB\n", snr);
  CHECK(snr > 40.0, "residual path SNR %.1f dB", snr);
  CHECK(anti < 1e-6 * num, "right is not the negated residual (%e)", anti);
  aac_mps_signal_destroy(s);
}

/* ── synthetic 5.1 round trips ──────────────────────────────────── */

struct Program {
  std::vector<float> ch[6]; /* L, R, C, LFE, Ls, Rs at full scale 1.0 */
  std::vector<AacUsacQmfFrame> q[6];
  std::vector<HybFrame> h[6];
};

Program make_program(int frames, int slots) {
  Program pr;
  const int n = frames * slots * 64;
  const float amp[6] = {1.0f, 0.7f, 0.5f, 0.3f, 0.4f, 0.6f};
  for (int c = 0; c < 6; c++) {
    pr.ch[c] = noise(n, 1000u + (unsigned)c, amp[c]);
  }
  /* the LFE: a 50 Hz tone in the lowest QMF band */
  for (int i = 0; i < n; i++) {
    pr.ch[3][i] = 0.3f * sinf(2.0f * 3.14159265f * 50.0f * (float)i / 48000.0f);
  }
  for (int c = 0; c < 6; c++) {
    pr.q[c] = analyse(pr.ch[c], slots);
    pr.h[c] = hybridise(pr.q[c]);
  }
  return pr;
}

/* Energy and cross terms of two hybrid frames per parameter band. */
void band_stats(const HybFrame& a, const HybFrame& b, int bands, double* ea, double* eb,
                double* cross) {
  const uint8_t* kernel = aac_mps212_kernel(bands);
  for (int pb = 0; pb < bands; pb++) ea[pb] = eb[pb] = cross[pb] = 0.0;
  for (int n = 0; n < a.slots; n++) {
    for (int k = 0; k < kHyb; k++) {
      const int pb = kernel[k];
      ea[pb] += a.x[n][k][0] * a.x[n][k][0] + a.x[n][k][1] * a.x[n][k][1];
      eb[pb] += b.x[n][k][0] * b.x[n][k][0] + b.x[n][k][1] * b.x[n][k][1];
      cross[pb] += a.x[n][k][0] * b.x[n][k][0] + a.x[n][k][1] * b.x[n][k][1];
    }
  }
}

void add_frames(AacUsacQmfFrame* dst, const AacUsacQmfFrame& a, float ga, const AacUsacQmfFrame& b,
                float gb) {
  dst->slots = a.slots;
  for (int s = 0; s < a.slots; s++) {
    for (int q = 0; q < 64; q++) {
      for (int c = 0; c < 2; c++) {
        dst->x[s][q][c] = ga * a.x[s][q][c] + gb * b.x[s][q][c];
      }
    }
  }
}

/* OTT parameters of a pair of hybrid frames: CLD and ICC indices per
 * band. */
void ott_params(const HybFrame& a, const HybFrame& b, int bands, int* cld, int* icc) {
  double ea[AAC_MPS_MAX_PARAM_BANDS], eb[AAC_MPS_MAX_PARAM_BANDS], cr[AAC_MPS_MAX_PARAM_BANDS];
  band_stats(a, b, bands, ea, eb, cr);
  for (int pb = 0; pb < bands; pb++) {
    cld[pb] = nearest_cld_idx(db(ea[pb]) - db(eb[pb]));
    icc[pb] = nearest_icc_idx(cr[pb] / sqrt(ea[pb] * eb[pb] + 1e-30));
  }
}

/* The 525 tree in the energy mode (4) with ICC 0 decorrelation: band
 * energies and inter-channel levels come back within tolerance. The
 * energy mode returns the centre as the energy-weighted mean of the two
 * downmix channels, so C and LFE are expected at (1 + ab) / 2 of their
 * energy, with a and b the centre's weights per band. */
void test_roundtrip_525() {
  const int slots = 32, frames = 12, bands = 20;
  Program pr = make_program(frames, slots);
  AacMpsConfig cfg = base_config(AAC_MPS_TREE_525, 2);
  cfg.ott_bands[0] = 2; /* the LFE box */
  cfg.ttt_mode_low[0] = cfg.ttt_mode_high[0] = 4;
  cfg.fixed_gain_sur = 2; /* 3 dB on the surrounds */
  cfg.fixed_gain_lfe = 1; /* 10 dB on the LFE */
  cfg.fixed_gain_dmx = 2; /* 3 dB clipping protection */
  AacMpsSignalState* s = aac_mps_signal_create(&cfg, 1024, 16);
  CHECK(s != NULL, "525 state");
  if (!s) return;
  const float g_sur = 1.0f / aac_mps_surround_gain[2], g_lfe = 1.0f / aac_mps_lfe_gain[1];
  const float g_dmx = 1.0f / aac_mps_dmx_gain[2];
  std::vector<AacUsacQmfFrame> outq[6];
  AacUsacQmfFrame outf[6];
  AacUsacQmfFrame* out[6];
  for (int c = 0; c < 6; c++) out[c] = &outf[c];
  double expected[6][AAC_MPS_MAX_PARAM_BANDS] = {{0}};
  for (int f = 0; f < frames; f++) {
    /* the encoder: the tree's downmix and its parameters */
    AacUsacQmfFrame l, r, c_in, L0, R0, ls, rs, lfe;
    add_frames(&ls, pr.q[4][f], g_sur, pr.q[4][f], 0.0f);
    add_frames(&rs, pr.q[5][f], g_sur, pr.q[5][f], 0.0f);
    add_frames(&lfe, pr.q[3][f], g_lfe, pr.q[3][f], 0.0f);
    add_frames(&l, pr.q[0][f], 1.0f, ls, 1.0f);
    add_frames(&r, pr.q[1][f], 1.0f, rs, 1.0f);
    add_frames(&c_in, pr.q[2][f], 1.0f / sqrtf(2.0f), lfe, 1.0f / sqrtf(2.0f));
    add_frames(&L0, l, g_dmx, c_in, g_dmx);
    add_frames(&R0, r, g_dmx, c_in, g_dmx);
    HybFrame hl, hr, hc, hls, hrs, hlfe;
    add_hyb(&hls, pr.h[4][f], g_sur, pr.h[4][f], 0.0f);
    add_hyb(&hrs, pr.h[5][f], g_sur, pr.h[5][f], 0.0f);
    add_hyb(&hlfe, pr.h[3][f], g_lfe, pr.h[3][f], 0.0f);
    add_hyb(&hl, pr.h[0][f], 1.0f, hls, 1.0f);
    add_hyb(&hr, pr.h[1][f], 1.0f, hrs, 1.0f);
    add_hyb(&hc, pr.h[2][f], 1.0f / sqrtf(2.0f), hlfe, 1.0f / sqrtf(2.0f));
    AacMpsFrameParams p;
    default_params(&p, slots);
    ott_params(pr.h[0][f], hls, bands, p.ott_cld[1][0], p.ott_icc[1][0]);
    ott_params(pr.h[1][f], hrs, bands, p.ott_cld[2][0], p.ott_icc[2][0]);
    ott_params(pr.h[2][f], hlfe, bands, p.ott_cld[0][0], p.ott_icc[0][0]);
    double el[bands], er[bands], ec[bands], cr[bands], dummy[bands];
    band_stats(hl, hr, bands, el, er, cr);
    band_stats(hc, hc, bands, ec, dummy, dummy);
    for (int pb = 0; pb < bands; pb++) {
      p.ttt_cld1[0][0][pb] = nearest_cld_idx(db(el[pb] + er[pb]) - db(ec[pb]));
      p.ttt_cld2[0][0][pb] = nearest_cld_idx(db(el[pb]) - db(er[pb]));
    }
    if (f >= 3 && f < frames - 1) {
      double e[6][AAC_MPS_MAX_PARAM_BANDS];
      for (int c = 0; c < 6; c++) band_stats(pr.h[c][f], pr.h[c][f], bands, e[c], dummy, dummy);
      for (int pb = 0; pb < bands; pb++) {
        const double a = sqrt(ec[pb] / (el[pb] + ec[pb])), b = sqrt(ec[pb] / (er[pb] + ec[pb]));
        for (int c = 0; c < 6; c++) {
          expected[c][pb] += (c == 2 || c == 3 ? (1.0 + a * b) / 2.0 : 1.0) * e[c][pb];
        }
      }
    }
    const AacUsacQmfFrame* in[2] = {&L0, &R0};
    aac_mps_signal_apply(s, &p, in, out);
    for (int c = 0; c < 6; c++) outq[c].push_back(outf[c]);
  }
  /* band energies over the settled frames, the output delayed by the
   * hybrid delay (six slots of a 32 slot frame: compare whole frames
   * from the third on, the drift is within the tolerance) */
  const char* names[6] = {"L", "R", "C", "LFE", "Ls", "Rs"};
  double worst = 0.0, tot_in[6], tot_out[6];
  for (int c = 0; c < 6; c++) {
    std::vector<HybFrame> outh = hybridise(outq[c]);
    double e_out[bands];
    band_energies(outh, 3, frames - 1, bands, e_out);
    double worst_ch = 0.0;
    tot_in[c] = tot_out[c] = 0.0;
    for (int pb = 0; pb < bands; pb++) {
      tot_in[c] += expected[c][pb];
      tot_out[c] += e_out[pb];
      if (c == 3 && pb >= 1) continue; /* the LFE tone lives in the lowest band */
      if (c != 3 && pb < 2) continue;  /* the LFE tone dominates the lowest bands */
      const double diff = db(e_out[pb]) - db(expected[c][pb]);
      if (fabs(diff) > worst_ch) worst_ch = fabs(diff);
    }
    printf("525 round trip %s: worst band energy deviation %.2f dB\n", names[c], worst_ch);
    if (worst_ch > worst) worst = worst_ch;
  }
  CHECK(worst < 2.0, "525 round trip band energies off by %.2f dB", worst);
  /* the inter-channel levels of the whole programme */
  for (int c = 1; c < 6; c++) {
    const double diff = (db(tot_out[c]) - db(tot_out[0])) - (db(tot_in[c]) - db(tot_in[0]));
    CHECK(fabs(diff) < 1.0, "525 level of %s against L off by %.2f dB", names[c], diff);
  }
  aac_mps_signal_destroy(s);
}

/* The 525 tree in the prediction mode with the exact residual and
 * silent surrounds and LFE: L, R and C come back as sent. */
void test_roundtrip_525_residual() {
  const int slots = 32, frames = 8, bands = 20;
  Program pr = make_program(frames, slots);
  AacMpsConfig cfg = base_config(AAC_MPS_TREE_525, 2);
  cfg.ttt_mode_low[0] = cfg.ttt_mode_high[0] = 1;
  cfg.residual_coding = 1;
  cfg.residual_present[AAC_MPS_MAX_OTT_BOXES] = 1;
  cfg.residual_bands[AAC_MPS_MAX_OTT_BOXES] = bands;
  cfg.residual_frames_per_spatial_frame = 2;
  AacMpsSignalState* s = aac_mps_signal_create(&cfg, 1024, 16);
  CHECK(s != NULL, "525 residual state");
  if (!s) return;
  std::vector<float> got[3], want[3];
  AacUsacQmfFrame outf[6];
  AacUsacQmfFrame* out[6];
  for (int c = 0; c < 6; c++) out[c] = &outf[c];
  for (int f = 0; f < frames; f++) {
    /* c_in = C / sqrt 2; L0 = L + c_in; R0 = R + c_in; the residual with
     * CPC 0 is l + r - c_in, formed in the time domain */
    std::vector<float> res((size_t)slots * 64);
    for (int i = 0; i < slots * 64; i++) {
      const size_t k = (size_t)f * slots * 64 + i;
      res[i] = pr.ch[0][k] + pr.ch[1][k] - pr.ch[2][k] / sqrtf(2.0f);
    }
    AacUsacQmfFrame L0, R0;
    add_frames(&L0, pr.q[0][f], 1.0f, pr.q[2][f], 1.0f / sqrtf(2.0f));
    add_frames(&R0, pr.q[1][f], 1.0f, pr.q[2][f], 1.0f / sqrtf(2.0f));
    AacMpsFrameParams p;
    default_params(&p, slots);
    for (int pb = 0; pb < bands; pb++) {
      p.ott_cld[0][0][pb] = 15; /* all to C, L, R */
      p.ott_cld[1][0][pb] = 15;
      p.ott_cld[2][0][pb] = 15;
    }
    aac_mps_signal_set_residual(s, AAC_MPS_MAX_OTT_BOXES, res.data(), (int)res.size());
    const AacUsacQmfFrame* in[2] = {&L0, &R0};
    aac_mps_signal_apply(s, &p, in, out);
    for (int c = 0; c < 3; c++) {
      for (int n = 0; n < slots; n++) {
        for (int b = 0; b < 64; b++) {
          got[c].push_back(outf[c].x[n][b][0]);
          got[c].push_back(outf[c].x[n][b][1]);
          want[c].push_back(pr.q[c][f].x[n][b][0]);
          want[c].push_back(pr.q[c][f].x[n][b][1]);
        }
      }
    }
  }
  const char* names[3] = {"L", "R", "C"};
  for (int c = 0; c < 3; c++) {
    double num = 0.0, den = 0.0;
    const size_t stride = 128;
    for (size_t i = (size_t)2 * slots * stride; i + kDelay * stride < got[c].size(); i++) {
      const double w = want[c][i], g = got[c][i + kDelay * stride];
      num += w * w;
      den += (w - g) * (w - g);
    }
    const double snr = 10.0 * log10(num / (den + 1e-20));
    printf("525 prediction with residual, %s: %.1f dB\n", names[c], snr);
    CHECK(snr > 45.0, "525 residual round trip %s: %.1f dB", names[c], snr);
  }
  aac_mps_signal_destroy(s);
}

/* The 5151 tree from a mono downmix. With the ICCs as measured the
 * output is the cascade of three one-to-two splits with their
 * decorrelators; the standard's decorrelators of the highest reverb band
 * are near-delays and correlate with each other (0.6 to 0.8 between
 * filter sets 0, 1 and 2), so the diffuse parts of the boxes add partly
 * coherently and the band energies of independent channels come back
 * within a few dB. With the ICCs forced to 1 the dry cascade alone
 * carries the channels and their energies come back within the CLD
 * quantisation. */
void test_roundtrip_5151(int dry_only) {
  const int slots = 32, frames = 12, bands = 20;
  Program pr = make_program(frames, slots);
  AacMpsConfig cfg = base_config(AAC_MPS_TREE_5151, 2);
  cfg.ott_bands[4] = 2;
  cfg.temp_shape_config = 1; /* STP on a stationary programme: a smoke test */
  AacMpsSignalState* s = aac_mps_signal_create(&cfg, 1024, 16);
  CHECK(s != NULL, "5151 state");
  if (!s) return;
  std::vector<AacUsacQmfFrame> outq[6];
  AacUsacQmfFrame outf[6];
  AacUsacQmfFrame* out[6];
  for (int c = 0; c < 6; c++) out[c] = &outf[c];
  for (int f = 0; f < frames; f++) {
    AacUsacQmfFrame front, clfe, fc, sur, m;
    add_frames(&front, pr.q[0][f], 1.0f, pr.q[1][f], 1.0f);
    add_frames(&clfe, pr.q[2][f], 1.0f, pr.q[3][f], 1.0f);
    add_frames(&fc, front, 1.0f, clfe, 1.0f);
    add_frames(&sur, pr.q[4][f], 1.0f, pr.q[5][f], 1.0f);
    add_frames(&m, fc, 1.0f, sur, 1.0f);
    HybFrame hfront, hclfe, hfc, hsur;
    add_hyb(&hfront, pr.h[0][f], 1.0f, pr.h[1][f], 1.0f);
    add_hyb(&hclfe, pr.h[2][f], 1.0f, pr.h[3][f], 1.0f);
    add_hyb(&hfc, hfront, 1.0f, hclfe, 1.0f);
    add_hyb(&hsur, pr.h[4][f], 1.0f, pr.h[5][f], 1.0f);
    AacMpsFrameParams p;
    default_params(&p, slots);
    p.num_sets = 2; /* two sets with the same values: exercises the interpolation */
    p.param_slot[0] = 15;
    p.param_slot[1] = 31;
    ott_params(hfc, hsur, bands, p.ott_cld[0][0], p.ott_icc[0][0]);
    ott_params(hfront, hclfe, bands, p.ott_cld[1][0], p.ott_icc[1][0]);
    ott_params(pr.h[4][f], pr.h[5][f], bands, p.ott_cld[2][0], p.ott_icc[2][0]);
    ott_params(pr.h[0][f], pr.h[1][f], bands, p.ott_cld[3][0], p.ott_icc[3][0]);
    ott_params(pr.h[2][f], pr.h[3][f], bands, p.ott_cld[4][0], p.ott_icc[4][0]);
    for (int box = 0; box < 5; box++) {
      if (dry_only) {
        memset(p.ott_icc[box][0], 0, sizeof(p.ott_icc[box][0]));
      }
      memcpy(p.ott_cld[box][1], p.ott_cld[box][0], sizeof(p.ott_cld[box][0]));
      memcpy(p.ott_icc[box][1], p.ott_icc[box][0], sizeof(p.ott_icc[box][0]));
    }
    p.temp_shape_enable = 1;
    for (int c = 0; c < 6; c++) p.stp_channel[c] = c != 3;
    const AacUsacQmfFrame* in[1] = {&m};
    aac_mps_signal_apply(s, &p, in, out);
    for (int c = 0; c < 6; c++) outq[c].push_back(outf[c]);
  }
  const char* names[6] = {"L", "R", "C", "LFE", "Ls", "Rs"};
  double worst = 0.0;
  for (int c = 0; c < 6; c++) {
    double e_in[bands], e_out[bands];
    band_energies(pr.h[c], 3, frames - 1, bands, e_in);
    band_energies(hybridise(outq[c]), 3, frames - 1, bands, e_out);
    double worst_ch = 0.0;
    for (int pb = 0; pb < bands; pb++) {
      if (c == 3 && pb >= 1) continue; /* the LFE tone lives in the lowest band */
      if (c != 3 && pb < 2) continue;
      const double diff = db(e_out[pb]) - db(e_in[pb]);
      if (fabs(diff) > worst_ch) worst_ch = fabs(diff);
    }
    printf("5151 round trip%s %s: worst band energy deviation %.2f dB\n",
           dry_only ? " (dry)" : "", names[c], worst_ch);
    if (worst_ch > worst) worst = worst_ch;
  }
  CHECK(worst < (dry_only ? 1.5 : 3.5), "5151 round trip%s band energies off by %.2f dB",
        dry_only ? " (dry)" : "", worst);
  aac_mps_signal_destroy(s);
}

/* A lost frame keeps the matrices; a reset clears the state. */
void test_housekeeping() {
  AacMpsConfig cfg = base_config(AAC_MPS_TREE_5152, 3);
  cfg.temp_shape_config = 2;
  AacMpsSignalState* s = aac_mps_signal_create(&cfg, 1024, 32);
  CHECK(s != NULL, "5152 state");
  if (!s) return;
  CHECK(aac_mps_signal_slots(s) == 32, "slots");
  CHECK(aac_mps_signal_layout(s)->decorr_box[3] == 4, "5152 decorrelator boxes");
  std::vector<float> pcm = noise(32 * 64 * 2, 3u, 0.5f);
  std::vector<AacUsacQmfFrame> q = analyse(pcm, 32);
  AacUsacQmfFrame outf[6];
  AacUsacQmfFrame* out[6];
  for (int c = 0; c < 6; c++) out[c] = &outf[c];
  AacMpsFrameParams p;
  default_params(&p, 32);
  for (int pb = 0; pb < 14; pb++) p.ott_cld[3][0][pb] = 15; /* L only, no Ls */
  p.temp_shape_enable = 1;
  p.ges_channel[0] = 1;
  for (int n = 0; n < 32; n++) p.env_shape[0][n] = 1.0f;
  const AacUsacQmfFrame* in[1] = {&q[0]};
  aac_mps_signal_apply(s, &p, in, out);
  const AacUsacQmfFrame* in2[1] = {&q[1]};
  aac_mps_signal_apply(s, NULL, in2, out);
  double e_l = 0.0, e_ls = 0.0;
  for (int n = 8; n < 32; n++) {
    for (int b = 0; b < 64; b++) {
      e_l += outf[0].x[n][b][0] * outf[0].x[n][b][0];
      e_ls += outf[4].x[n][b][0] * outf[4].x[n][b][0];
    }
  }
  CHECK(e_l > 0.0 && e_ls < 1e-6 * e_l, "lost frame keeps the one-sided split (%e %e)", e_l, e_ls);
  CHECK(aac_mps_signal_create(&cfg, 1024, 24) == NULL, "slots must divide the frame");
  cfg.tree_config = 3;
  CHECK(aac_mps_signal_create(&cfg, 1024, 32) == NULL, "7.1 trees refused");
  aac_mps_signal_reset(s);
  aac_mps_signal_destroy(s);
}

}  // namespace

int main() {
  test_tables();
  test_ott_matrix();
  test_ttt_matrix();
  test_tree_energy();
  test_decorrelator();
  test_residual_path();
  test_roundtrip_525();
  test_roundtrip_525_residual();
  test_roundtrip_5151(0);
  test_roundtrip_5151(1);
  test_housekeeping();
  return test_summary("test_mps_signal");
}
