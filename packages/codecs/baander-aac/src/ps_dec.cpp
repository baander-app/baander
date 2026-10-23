#include "ps.h"

#include <algorithm>
#include <cmath>
#include <cstdlib>
#include <cstring>

/* Parametric stereo decoder (ISO/IEC 14496-3 8.6.4).  The buffer layout
 * and the order of operations follow FFmpeg's float decoder, which the
 * test suite compares against: the hybrid filters are centred on the
 * current slot and read six slots ahead into the SBR output, so the QMF
 * signal handed over by the SBR decoder already carries that delay. */

namespace {

constexpr int kSlots = AAC_PS_SLOTS;
constexpr int kMaxDelay = AAC_PS_MAX_DELAY;
constexpr int kLinks = AAC_PS_LINKS;
constexpr int kLinkDelay = AAC_PS_MAX_LINK_DELAY;

/* Per band-count (20 or 34 parameter bands) constants. */
const int kNrParBands[2] = {20, 34};
const int kNrIpdopdBands[2] = {11, 17};
const int kNrBands[2] = {71, 91};        /* sub-subbands */
const int kDecayCutoff[2] = {10, 32};    /* first band of the all-pass decay slope */
const int kNrAllpassBands[2] = {30, 50};
const int kShortDelayBand[2] = {42, 62}; /* first band with the one-slot delay */
constexpr float kDecaySlope = 0.05f;

/* ── bitstream tables ──────────────────────────────────────────── */

const int8_t kNumEnvTab[2][4] = {{0, 1, 2, 4}, {1, 2, 3, 4}};
const int8_t kNrIidIccParTab[6] = {10, 20, 34, 10, 20, 34};
const int8_t kNrIpdOpdParTab[6] = {5, 11, 17, 5, 11, 17};


const int kHuffIid[4] = {AAC_PS_HUFF_IID_DF0, AAC_PS_HUFF_IID_DF1, AAC_PS_HUFF_IID_DT0, AAC_PS_HUFF_IID_DT1};

/* ── derived tables, built once ────────────────────────────────── */

struct HuffEntry {
  uint32_t code; /* left-aligned in 32 bits */
  uint8_t len;
  int8_t value;
};

struct HuffTable {
  HuffEntry entries[61];
  int count;
  int max_len;
};

HuffTable g_huff[AAC_PS_NUM_HUFF];
float g_pd_re_smooth[8 * 8 * 8];
float g_pd_im_smooth[8 * 8 * 8];
float g_HA[46][8][4];
float g_HB[46][8][4];
float g_Q_fract_allpass[2][50][3][2];
float g_phi_fract[2][50][2];
bool g_tables_ready = false;

void init_huffman(void) {
  const uint8_t (*tab)[2] = aac_ps_huff_tabs;
  for (int t = 0; t < AAC_PS_NUM_HUFF; t++) {
    HuffTable* h = &g_huff[t];
    h->count = aac_ps_huff_sizes[t];
    h->max_len = 0;
    uint32_t code = 0;
    for (int i = 0; i < h->count; i++) {
      const int len = tab[i][1];
      h->entries[i].code = code;
      h->entries[i].len = (uint8_t)len;
      h->entries[i].value = (int8_t)(tab[i][0] + aac_ps_huff_offset[t]);
      h->max_len = std::max(h->max_len, len);
      code += 1u << (32 - len);
    }
    tab += h->count;
  }
}

void init_tables(void) {
  if (g_tables_ready) {
    return;
  }
  init_huffman();

  static const float ipdopd_sin[8] = {0, (float)M_SQRT1_2, 1, (float)M_SQRT1_2,
                                      0, (float)-M_SQRT1_2, -1, (float)-M_SQRT1_2};
  static const float ipdopd_cos[8] = {1, (float)M_SQRT1_2, 0, (float)-M_SQRT1_2,
                                      -1, (float)-M_SQRT1_2, 0, (float)M_SQRT1_2};
  for (int pd0 = 0; pd0 < 8; pd0++) {
    for (int pd1 = 0; pd1 < 8; pd1++) {
      for (int pd2 = 0; pd2 < 8; pd2++) {
        const float re_smooth = 0.25f * ipdopd_cos[pd0] + 0.5f * ipdopd_cos[pd1] + ipdopd_cos[pd2];
        const float im_smooth = 0.25f * ipdopd_sin[pd0] + 0.5f * ipdopd_sin[pd1] + ipdopd_sin[pd2];
        const float pd_mag = (float)(1.0 / hypot(im_smooth, re_smooth));
        g_pd_re_smooth[pd0 * 64 + pd1 * 8 + pd2] = re_smooth * pd_mag;
        g_pd_im_smooth[pd0 * 64 + pd1 * 8 + pd2] = im_smooth * pd_mag;
      }
    }
  }

  /* Table 8.42 (coarse) and 8.43 (fine) IID quantization, as linear ratios. */
  static const float iid_par_dequant[46] = {
      0.05623413251903f, 0.12589254117942f, 0.19952623149689f, 0.31622776601684f,
      0.44668359215096f, 0.63095734448019f, 0.79432823472428f, 1.0f,
      1.25892541179417f, 1.58489319246111f, 2.23872113856834f, 3.16227766016838f,
      5.01187233627272f, 7.94328234724282f, 17.7827941003892f,
      0.00316227766017f, 0.00562341325190f, 0.01f,             0.01778279410039f,
      0.03162277660168f, 0.05623413251903f, 0.07943282347243f, 0.11220184543020f,
      0.15848931924611f, 0.22387211385683f, 0.31622776601684f, 0.39810717055350f,
      0.50118723362727f, 0.63095734448019f, 0.79432823472428f, 1.0f,
      1.25892541179417f, 1.58489319246111f, 1.99526231496888f, 2.51188643150958f,
      3.16227766016838f, 4.46683592150963f, 6.30957344480193f, 8.91250938133745f,
      12.5892541179417f, 17.7827941003892f, 31.6227766016838f, 56.2341325190349f,
      100.0f,            177.827941003892f, 316.227766016837f,
  };
  static const float icc_invq[8] = {1.0f, 0.937f, 0.84118f, 0.60092f, 0.36764f, 0.0f, -0.589f, -1.0f};
  static const float acos_icc_invq[8] = {0.0f,       0.35685527f, 0.57133466f, 0.92614472f,
                                         1.1943263f, (float)(M_PI / 2), 2.2006171f, (float)M_PI};
  for (int iid = 0; iid < 46; iid++) {
    const float c = iid_par_dequant[iid];
    const float c1 = (float)M_SQRT2 / sqrtf(1.0f + c * c);
    const float c2 = c * c1;
    for (int icc = 0; icc < 8; icc++) {
      {
        const float alpha = 0.5f * acos_icc_invq[icc];
        const float beta = alpha * (c1 - c2) * (float)M_SQRT1_2;
        g_HA[iid][icc][0] = c2 * cosf(beta + alpha);
        g_HA[iid][icc][1] = c1 * cosf(beta - alpha);
        g_HA[iid][icc][2] = c2 * sinf(beta + alpha);
        g_HA[iid][icc][3] = c1 * sinf(beta - alpha);
      }
      {
        const float rho = std::max(icc_invq[icc], 0.05f);
        float alpha = 0.5f * atan2f(2.0f * c * rho, c * c - 1.0f);
        float mu = c + 1.0f / c;
        mu = sqrtf(1 + (4 * rho * rho - 4) / (mu * mu));
        const float gamma = atanf(sqrtf((1.0f - mu) / (1.0f + mu)));
        if (alpha < 0) {
          alpha += (float)(M_PI / 2);
        }
        const float alpha_c = cosf(alpha);
        const float alpha_s = sinf(alpha);
        const float gamma_c = cosf(gamma);
        const float gamma_s = sinf(gamma);
        g_HB[iid][icc][0] = (float)M_SQRT2 * alpha_c * gamma_c;
        g_HB[iid][icc][1] = (float)M_SQRT2 * alpha_s * gamma_c;
        g_HB[iid][icc][2] = (float)-M_SQRT2 * alpha_s * gamma_s;
        g_HB[iid][icc][3] = (float)M_SQRT2 * alpha_c * gamma_s;
      }
    }
  }

  static const int8_t f_center_20[10] = {-3, -1, 1, 3, 5, 7, 10, 14, 18, 22};
  static const int8_t f_center_34[32] = {2,   6,  10, 14, 18, 22, 26, 30, 34, -10, -6,
                                         -2,  51, 57, 15, 21, 27, 33, 39, 45, 54,  66,
                                         78,  42, 102, 66, 78, 90, 102, 114, 126, 90};
  static const float fractional_delay_links[3] = {0.43f, 0.75f, 0.347f};
  const float fractional_delay_gain = 0.39f;
  for (int k = 0; k < kNrAllpassBands[0]; k++) {
    const double f_center = k < 10 ? f_center_20[k] * 0.125 : k - 6.5f;
    for (int m = 0; m < kLinks; m++) {
      const double theta = -M_PI * fractional_delay_links[m] * f_center;
      g_Q_fract_allpass[0][k][m][0] = (float)cos(theta);
      g_Q_fract_allpass[0][k][m][1] = (float)sin(theta);
    }
    const double theta = -M_PI * fractional_delay_gain * f_center;
    g_phi_fract[0][k][0] = (float)cos(theta);
    g_phi_fract[0][k][1] = (float)sin(theta);
  }
  for (int k = 0; k < kNrAllpassBands[1]; k++) {
    const double f_center = k < 32 ? f_center_34[k] / 24.0 : k - 26.5f;
    for (int m = 0; m < kLinks; m++) {
      const double theta = -M_PI * fractional_delay_links[m] * f_center;
      g_Q_fract_allpass[1][k][m][0] = (float)cos(theta);
      g_Q_fract_allpass[1][k][m][1] = (float)sin(theta);
    }
    const double theta = -M_PI * fractional_delay_gain * f_center;
    g_phi_fract[1][k][0] = (float)cos(theta);
    g_phi_fract[1][k][1] = (float)sin(theta);
  }

  aac_ps_hybrid_init();
  g_tables_ready = true;
}

/* ── bitstream ─────────────────────────────────────────────────── */

/* Decodes one codeword; an invalid code yields the table's first value
 * and sets the reader's overread flag so that the frame is rejected. */
int huff_decode(AacBitReader* r, int table) {
  const HuffTable* h = &g_huff[table];
  const uint32_t bits = aac_bitreader_peek(r, h->max_len) << (32 - h->max_len);
  for (int i = 0; i < h->count; i++) {
    const HuffEntry* e = &h->entries[i];
    if ((bits >> (32 - e->len)) == (e->code >> (32 - e->len))) {
      aac_bitreader_skip(r, e->len);
      return e->value;
    }
  }
  r->overread = 1;
  return h->entries[0].value;
}

/* Reads the IID, ICC, IPD or OPD parameters of envelope e, delta coded in
 * time (dt) from the previous envelope or in frequency.  `mask` wraps
 * phase parameters; `limit` rejects out-of-range IID and ICC values. */
int read_par_data(AacBitReader* r, const AacPsState* ps, int8_t (*par)[AAC_PS_MAX_PAR], int num,
                  int table, int e, int dt, int mask, int limit, int is_icc) {
  if (dt) {
    int e_prev = e ? e - 1 : ps->num_env_old - 1;
    e_prev = std::max(e_prev, 0);
    for (int b = 0; b < num; b++) {
      int val = par[e_prev][b] + huff_decode(r, table);
      if (mask) {
        val &= mask;
      }
      if (limit && (is_icc ? (unsigned)val > (unsigned)limit : std::abs(val) > limit)) {
        if (!ps->unreliable) {
          return AAC_ERR_DECODE;
        }
        val = is_icc ? (val < 0 ? 0 : limit) : (val < 0 ? -limit : limit);
      }
      par[e][b] = (int8_t)val;
    }
  } else {
    int val = 0;
    for (int b = 0; b < num; b++) {
      val += huff_decode(r, table);
      if (mask) {
        val &= mask;
      }
      par[e][b] = (int8_t)val;
      if (limit && (is_icc ? (unsigned)val > (unsigned)limit : std::abs(val) > limit)) {
        return AAC_ERR_DECODE;
      }
    }
  }
  return 0;
}

int read_extension_data(AacBitReader* r, AacPsState* ps, int ps_extension_id) {
  const int count = r->pos;
  if (ps_extension_id) {
    return 0;
  }
  ps->enable_ipdopd = (int)aac_bitreader_read(r, 1);
  if (ps->enable_ipdopd) {
    for (int e = 0; e < ps->num_env; e++) {
      int dt = (int)aac_bitreader_read(r, 1);
      read_par_data(r, ps, ps->ipd_par, ps->nr_ipdopd_par, dt ? AAC_PS_HUFF_IPD_DT : AAC_PS_HUFF_IPD_DF, e, dt,
                    0x07, 0, 0);
      dt = (int)aac_bitreader_read(r, 1);
      read_par_data(r, ps, ps->opd_par, ps->nr_ipdopd_par, dt ? AAC_PS_HUFF_OPD_DT : AAC_PS_HUFF_OPD_DF, e, dt,
                    0x07, 0, 0);
    }
  }
  aac_bitreader_skip(r, 1); /* reserved_ps */
  return r->pos - count;
}

void clear_parameters(AacPsState* ps) {
  memset(ps->iid_par, 0, sizeof(ps->iid_par));
  memset(ps->icc_par, 0, sizeof(ps->icc_par));
  memset(ps->ipd_par, 0, sizeof(ps->ipd_par));
  memset(ps->opd_par, 0, sizeof(ps->opd_par));
}

int log2_int(int v) {
  int n = 0;
  while (v > 1) {
    v >>= 1;
    n++;
  }
  return n;
}

/* ps_data() proper; returns 0 or an error. */
int read_data(AacPsState* ps, AacBitReader* r) {
  const int header = (int)aac_bitreader_read(r, 1);
  if (header) {
    ps->enable_iid = (int)aac_bitreader_read(r, 1);
    if (ps->enable_iid) {
      const int iid_mode = (int)aac_bitreader_read(r, 3);
      if (iid_mode > 5) {
        return AAC_ERR_DECODE;
      }
      ps->nr_iid_par = kNrIidIccParTab[iid_mode];
      ps->iid_quant = iid_mode > 2;
      ps->nr_ipdopd_par = kNrIpdOpdParTab[iid_mode];
    }
    ps->enable_icc = (int)aac_bitreader_read(r, 1);
    if (ps->enable_icc) {
      ps->icc_mode = (int)aac_bitreader_read(r, 3);
      if (ps->icc_mode > 5) {
        return AAC_ERR_DECODE;
      }
      ps->nr_icc_par = kNrIidIccParTab[ps->icc_mode];
    }
    ps->enable_ext = (int)aac_bitreader_read(r, 1);
  }

  ps->frame_class = (int)aac_bitreader_read(r, 1);
  ps->num_env_old = ps->num_env;
  ps->num_env = kNumEnvTab[ps->frame_class][aac_bitreader_read(r, 2)];

  ps->border_position[0] = -1;
  if (ps->frame_class) {
    for (int e = 1; e <= ps->num_env; e++) {
      ps->border_position[e] = (int)aac_bitreader_read(r, 5);
      if (ps->border_position[e] < ps->border_position[e - 1]) {
        return AAC_ERR_DECODE;
      }
    }
  } else {
    for (int e = 1; e <= ps->num_env; e++) {
      ps->border_position[e] = (e * kSlots >> log2_int(ps->num_env)) - 1;
    }
  }

  bool anchored = true;
  if (ps->enable_iid) {
    bool df = false;
    for (int e = 0; e < ps->num_env; e++) {
      const int dt = (int)aac_bitreader_read(r, 1);
      df = df || !dt;
      if (read_par_data(r, ps, ps->iid_par, ps->nr_iid_par, kHuffIid[2 * dt + ps->iid_quant], e,
                        dt, 0, 7 + 8 * ps->iid_quant, 0)) {
        return AAC_ERR_DECODE;
      }
    }
    anchored = anchored && df;
  } else {
    memset(ps->iid_par, 0, sizeof(ps->iid_par));
  }

  if (ps->enable_icc) {
    bool df = false;
    for (int e = 0; e < ps->num_env; e++) {
      const int dt = (int)aac_bitreader_read(r, 1);
      df = df || !dt;
      if (read_par_data(r, ps, ps->icc_par, ps->nr_icc_par, dt ? AAC_PS_HUFF_ICC_DT : AAC_PS_HUFF_ICC_DF, e, dt,
                        0, 7, 1)) {
        return AAC_ERR_DECODE;
      }
    }
    anchored = anchored && df;
  } else {
    memset(ps->icc_par, 0, sizeof(ps->icc_par));
  }

  if (ps->enable_ext) {
    int cnt = (int)aac_bitreader_read(r, 4);
    if (cnt == 15) {
      cnt += (int)aac_bitreader_read(r, 8);
    }
    cnt *= 8;
    while (cnt > 7) {
      const int ps_extension_id = (int)aac_bitreader_read(r, 2);
      cnt -= 2 + read_extension_data(r, ps, ps_extension_id);
    }
    if (cnt < 0) {
      return AAC_ERR_DECODE;
    }
    aac_bitreader_skip(r, cnt);
  }

  /* An incomplete last envelope is completed by a copy of the previous
   * one, so that the interpolation always ends at the frame border. */
  if (!ps->num_env || ps->border_position[ps->num_env] < kSlots - 1) {
    const int source = ps->num_env ? ps->num_env - 1 : ps->num_env_old - 1;
    if (source >= 0 && source != ps->num_env) {
      if (ps->enable_iid) {
        memcpy(ps->iid_par[ps->num_env], ps->iid_par[source], sizeof(ps->iid_par[0]));
      }
      if (ps->enable_icc) {
        memcpy(ps->icc_par[ps->num_env], ps->icc_par[source], sizeof(ps->icc_par[0]));
      }
      if (ps->enable_ipdopd) {
        memcpy(ps->ipd_par[ps->num_env], ps->ipd_par[source], sizeof(ps->ipd_par[0]));
        memcpy(ps->opd_par[ps->num_env], ps->opd_par[source], sizeof(ps->opd_par[0]));
      }
    }
    if (ps->enable_iid) {
      for (int b = 0; b < ps->nr_iid_par; b++) {
        if (std::abs(ps->iid_par[ps->num_env][b]) > 7 + 8 * ps->iid_quant) {
          return AAC_ERR_DECODE;
        }
      }
    }
    if (ps->enable_icc) {
      for (int b = 0; b < ps->nr_iid_par; b++) {
        if ((unsigned)ps->icc_par[ps->num_env][b] > 7u) {
          return AAC_ERR_DECODE;
        }
      }
    }
    ps->num_env++;
    ps->border_position[ps->num_env] = kSlots - 1;
  }

  ps->is34bands_old = ps->is34bands;
  if (ps->enable_iid || ps->enable_icc) {
    ps->is34bands = (ps->enable_iid && ps->nr_iid_par == 34) ||
                    (ps->enable_icc && ps->nr_icc_par == 34);
  }

  if (!ps->enable_ipdopd) {
    memset(ps->ipd_par, 0, sizeof(ps->ipd_par));
    memset(ps->opd_par, 0, sizeof(ps->opd_par));
  }

  if (header) {
    ps->start = 1;
  }
  if (anchored) {
    ps->unreliable = 0;
  }
  return r->overread ? AAC_ERR_DECODE : 0;
}

/* ── parameter mapping (Tables 8.46, 8.47) ─────────────────────── */

void map_idx_10_to_20(int8_t* par_mapped, const int8_t* par, int full) {
  int b;
  if (full) {
    b = 9;
  } else {
    b = 4;
    par_mapped[10] = 0;
  }
  for (; b >= 0; b--) {
    par_mapped[2 * b + 1] = par_mapped[2 * b] = par[b];
  }
}

void map_idx_34_to_20(int8_t* par_mapped, const int8_t* par, int full) {
  par_mapped[0] = (int8_t)((2 * par[0] + par[1]) / 3);
  par_mapped[1] = (int8_t)((par[1] + 2 * par[2]) / 3);
  par_mapped[2] = (int8_t)((2 * par[3] + par[4]) / 3);
  par_mapped[3] = (int8_t)((par[4] + 2 * par[5]) / 3);
  par_mapped[4] = (int8_t)((par[6] + par[7]) / 2);
  par_mapped[5] = (int8_t)((par[8] + par[9]) / 2);
  par_mapped[6] = par[10];
  par_mapped[7] = par[11];
  par_mapped[8] = (int8_t)((par[12] + par[13]) / 2);
  par_mapped[9] = (int8_t)((par[14] + par[15]) / 2);
  par_mapped[10] = par[16];
  if (full) {
    par_mapped[11] = par[17];
    par_mapped[12] = par[18];
    par_mapped[13] = par[19];
    par_mapped[14] = (int8_t)((par[20] + par[21]) / 2);
    par_mapped[15] = (int8_t)((par[22] + par[23]) / 2);
    par_mapped[16] = (int8_t)((par[24] + par[25]) / 2);
    par_mapped[17] = (int8_t)((par[26] + par[27]) / 2);
    par_mapped[18] = (int8_t)((par[28] + par[29] + par[30] + par[31]) / 4);
    par_mapped[19] = (int8_t)((par[32] + par[33]) / 2);
  }
}

void map_val_34_to_20(float* par) {
  par[0] = (2 * par[0] + par[1]) * 0.33333333f;
  par[1] = (par[1] + 2 * par[2]) * 0.33333333f;
  par[2] = (2 * par[3] + par[4]) * 0.33333333f;
  par[3] = (par[4] + 2 * par[5]) * 0.33333333f;
  par[4] = (par[6] + par[7]) * 0.5f;
  par[5] = (par[8] + par[9]) * 0.5f;
  par[6] = par[10];
  par[7] = par[11];
  par[8] = (par[12] + par[13]) * 0.5f;
  par[9] = (par[14] + par[15]) * 0.5f;
  par[10] = par[16];
  par[11] = par[17];
  par[12] = par[18];
  par[13] = par[19];
  par[14] = (par[20] + par[21]) * 0.5f;
  par[15] = (par[22] + par[23]) * 0.5f;
  par[16] = (par[24] + par[25]) * 0.5f;
  par[17] = (par[26] + par[27]) * 0.5f;
  par[18] = (par[28] + par[29] + par[30] + par[31]) * 0.25f;
  par[19] = (par[32] + par[33]) * 0.5f;
}

void map_idx_10_to_34(int8_t* par_mapped, const int8_t* par, int full) {
  if (full) {
    par_mapped[33] = par[9];
    par_mapped[32] = par[9];
    par_mapped[31] = par[9];
    par_mapped[30] = par[9];
    par_mapped[29] = par[9];
    par_mapped[28] = par[9];
    par_mapped[27] = par[8];
    par_mapped[26] = par[8];
    par_mapped[25] = par[8];
    par_mapped[24] = par[8];
    par_mapped[23] = par[7];
    par_mapped[22] = par[7];
    par_mapped[21] = par[7];
    par_mapped[20] = par[7];
    par_mapped[19] = par[6];
    par_mapped[18] = par[6];
    par_mapped[17] = par[5];
    par_mapped[16] = par[5];
  } else {
    par_mapped[16] = 0;
  }
  par_mapped[15] = par[4];
  par_mapped[14] = par[4];
  par_mapped[13] = par[4];
  par_mapped[12] = par[4];
  par_mapped[11] = par[3];
  par_mapped[10] = par[3];
  par_mapped[9] = par[2];
  par_mapped[8] = par[2];
  par_mapped[7] = par[2];
  par_mapped[6] = par[2];
  par_mapped[5] = par[1];
  par_mapped[4] = par[1];
  par_mapped[3] = par[1];
  par_mapped[2] = par[0];
  par_mapped[1] = par[0];
  par_mapped[0] = par[0];
}

void map_idx_20_to_34(int8_t* par_mapped, const int8_t* par, int full) {
  if (full) {
    par_mapped[33] = par[19];
    par_mapped[32] = par[19];
    par_mapped[31] = par[18];
    par_mapped[30] = par[18];
    par_mapped[29] = par[18];
    par_mapped[28] = par[18];
    par_mapped[27] = par[17];
    par_mapped[26] = par[17];
    par_mapped[25] = par[16];
    par_mapped[24] = par[16];
    par_mapped[23] = par[15];
    par_mapped[22] = par[15];
    par_mapped[21] = par[14];
    par_mapped[20] = par[14];
    par_mapped[19] = par[13];
    par_mapped[18] = par[12];
    par_mapped[17] = par[11];
  }
  par_mapped[16] = par[10];
  par_mapped[15] = par[9];
  par_mapped[14] = par[9];
  par_mapped[13] = par[8];
  par_mapped[12] = par[8];
  par_mapped[11] = par[7];
  par_mapped[10] = par[6];
  par_mapped[9] = par[5];
  par_mapped[8] = par[5];
  par_mapped[7] = par[4];
  par_mapped[6] = par[4];
  par_mapped[5] = par[3];
  par_mapped[4] = (int8_t)((par[2] + par[3]) / 2);
  par_mapped[3] = par[2];
  par_mapped[2] = par[1];
  par_mapped[1] = (int8_t)((par[0] + par[1]) / 2);
  par_mapped[0] = par[0];
}

void map_val_20_to_34(float* par) {
  par[33] = par[19];
  par[32] = par[19];
  par[31] = par[18];
  par[30] = par[18];
  par[29] = par[18];
  par[28] = par[18];
  par[27] = par[17];
  par[26] = par[17];
  par[25] = par[16];
  par[24] = par[16];
  par[23] = par[15];
  par[22] = par[15];
  par[21] = par[14];
  par[20] = par[14];
  par[19] = par[13];
  par[18] = par[12];
  par[17] = par[11];
  par[16] = par[10];
  par[15] = par[9];
  par[14] = par[9];
  par[13] = par[8];
  par[12] = par[8];
  par[11] = par[7];
  par[10] = par[6];
  par[9] = par[5];
  par[8] = par[5];
  par[7] = par[4];
  par[6] = par[4];
  par[5] = par[3];
  par[4] = (par[2] + par[3]) * 0.5f;
  par[3] = par[2];
  par[2] = par[1];
  par[1] = (par[0] + par[1]) * 0.5f;
}

/* Maps a parameter set to the band count of the processing; the pointer
 * is redirected to the original data when no mapping is needed. */
void remap34(int8_t (**p_par_mapped)[AAC_PS_MAX_PAR], int8_t (*par)[AAC_PS_MAX_PAR], int num_par,
             int num_env, int full) {
  int8_t (*par_mapped)[AAC_PS_MAX_PAR] = *p_par_mapped;
  if (num_par == 20 || num_par == 11) {
    for (int e = 0; e < num_env; e++) {
      map_idx_20_to_34(par_mapped[e], par[e], full);
    }
  } else if (num_par == 10 || num_par == 5) {
    for (int e = 0; e < num_env; e++) {
      map_idx_10_to_34(par_mapped[e], par[e], full);
    }
  } else {
    *p_par_mapped = par;
  }
}

void remap20(int8_t (**p_par_mapped)[AAC_PS_MAX_PAR], int8_t (*par)[AAC_PS_MAX_PAR], int num_par,
             int num_env, int full) {
  int8_t (*par_mapped)[AAC_PS_MAX_PAR] = *p_par_mapped;
  if (num_par == 34 || num_par == 17) {
    for (int e = 0; e < num_env; e++) {
      map_idx_34_to_20(par_mapped[e], par[e], full);
    }
  } else if (num_par == 10 || num_par == 5) {
    for (int e = 0; e < num_env; e++) {
      map_idx_10_to_20(par_mapped[e], par[e], full);
    }
  } else {
    *p_par_mapped = par;
  }
}

/* ── decorrelation (8.6.4.6.3) ─────────────────────────────────── */

/* One sub-subband through the fractional-delay all-pass cascade:
 *   H(z) = z^-2 phi_fract prod_m (Q_fract[m] z^-d[m] - a[m] g) /
 *          (1 - a[m] g Q_fract[m] z^-d[m]),
 * scaled by the transient gain. */
void decorrelate_band(float (*out)[2], const float (*delay)[2],
                      float (*ap_delay)[kSlots + kLinkDelay][2], const float phi_fract[2],
                      const float (*Q_fract)[2], const float* transient_gain, float g_decay_slope,
                      int len) {
  static const float a[kLinks] = {0.65143905753106f, 0.56471812200776f, 0.48954165955695f};
  float ag[kLinks];
  for (int m = 0; m < kLinks; m++) {
    ag[m] = a[m] * g_decay_slope;
  }
  for (int n = 0; n < len; n++) {
    float in_re = delay[n][0] * phi_fract[0] - delay[n][1] * phi_fract[1];
    float in_im = delay[n][0] * phi_fract[1] + delay[n][1] * phi_fract[0];
    for (int m = 0; m < kLinks; m++) {
      const float a_re = ag[m] * in_re;
      const float a_im = ag[m] * in_im;
      const float link_delay_re = ap_delay[m][n + 2 - m][0];
      const float link_delay_im = ap_delay[m][n + 2 - m][1];
      const float fractional_delay_re = Q_fract[m][0];
      const float fractional_delay_im = Q_fract[m][1];
      const float apd_re = in_re;
      const float apd_im = in_im;
      in_re = link_delay_re * fractional_delay_re - link_delay_im * fractional_delay_im;
      in_re -= a_re;
      in_im = link_delay_re * fractional_delay_im + link_delay_im * fractional_delay_re;
      in_im -= a_im;
      ap_delay[m][n + 5][0] = apd_re + ag[m] * in_re;
      ap_delay[m][n + 5][1] = apd_im + ag[m] * in_im;
    }
    out[n][0] = transient_gain[n] * in_re;
    out[n][1] = transient_gain[n] * in_im;
  }
}

void decorrelation(AacPsState* ps, float (*out)[kSlots][2], const float (*s)[kSlots][2],
                   int is34) {
  float power[34][kSlots];
  float transient_gain[34][kSlots];
  float* peak_decay_nrg = ps->peak_decay_nrg;
  float* power_smooth = ps->power_smooth;
  float* peak_decay_diff_smooth = ps->peak_decay_diff_smooth;
  const float transient_impact = 1.5f;
  const float a_smooth = 0.25f;
  const int8_t* const k_to_i = is34 ? aac_ps_k_to_i_34 : aac_ps_k_to_i_20;
  const int n0 = 0;
  const int nL = kSlots;
  const float peak_decay_factor = 0.76592833836465f;

  memset(power, 0, sizeof(power));
  if (is34 != ps->is34bands_old) {
    memset(ps->peak_decay_nrg, 0, sizeof(ps->peak_decay_nrg));
    memset(ps->power_smooth, 0, sizeof(ps->power_smooth));
    memset(ps->peak_decay_diff_smooth, 0, sizeof(ps->peak_decay_diff_smooth));
    memset(ps->delay, 0, sizeof(ps->delay));
    memset(ps->ap_delay, 0, sizeof(ps->ap_delay));
  }

  for (int k = 0; k < kNrBands[is34]; k++) {
    const int i = k_to_i[k];
    for (int n = n0; n < nL; n++) {
      power[i][n] += s[k][n][0] * s[k][n][0] + s[k][n][1] * s[k][n][1];
    }
  }

  /* Transient detection: the gain drops where the smoothed power falls
   * below the decaying peak. */
  for (int i = 0; i < kNrParBands[is34]; i++) {
    for (int n = n0; n < nL; n++) {
      const float decayed_peak = peak_decay_factor * peak_decay_nrg[i];
      peak_decay_nrg[i] = std::max(decayed_peak, power[i][n]);
      power_smooth[i] += a_smooth * (power[i][n] - power_smooth[i]);
      peak_decay_diff_smooth[i] +=
          a_smooth * (peak_decay_nrg[i] - power[i][n] - peak_decay_diff_smooth[i]);
      const float denom = transient_impact * peak_decay_diff_smooth[i];
      transient_gain[i][n] = (denom > power_smooth[i]) ? power_smooth[i] / denom : 1.0f;
    }
  }

  int k = 0;
  for (; k < kNrAllpassBands[is34]; k++) {
    const int b = k_to_i[k];
    float g_decay_slope = 1.0f - kDecaySlope * (float)(k - kDecayCutoff[is34]);
    g_decay_slope = std::min(std::max(g_decay_slope, 0.0f), 1.0f);
    memcpy(ps->delay[k], ps->delay[k] + nL, kMaxDelay * sizeof(ps->delay[k][0]));
    memcpy(ps->delay[k] + kMaxDelay, s[k], kSlots * sizeof(ps->delay[k][0]));
    for (int m = 0; m < kLinks; m++) {
      memcpy(ps->ap_delay[k][m], ps->ap_delay[k][m] + kSlots, 5 * sizeof(ps->ap_delay[k][m][0]));
    }
    decorrelate_band(out[k], ps->delay[k] + kMaxDelay - 2, ps->ap_delay[k], g_phi_fract[is34][k],
                     g_Q_fract_allpass[is34][k], transient_gain[b], g_decay_slope, nL - n0);
  }
  for (; k < kShortDelayBand[is34]; k++) {
    const int i = k_to_i[k];
    memcpy(ps->delay[k], ps->delay[k] + nL, kMaxDelay * sizeof(ps->delay[k][0]));
    memcpy(ps->delay[k] + kMaxDelay, s[k], kSlots * sizeof(ps->delay[k][0]));
    const float (*d)[2] = ps->delay[k] + kMaxDelay - 14;
    for (int n = 0; n < nL - n0; n++) {
      out[k][n][0] = d[n][0] * transient_gain[i][n];
      out[k][n][1] = d[n][1] * transient_gain[i][n];
    }
  }
  for (; k < kNrBands[is34]; k++) {
    const int i = k_to_i[k];
    memcpy(ps->delay[k], ps->delay[k] + nL, kMaxDelay * sizeof(ps->delay[k][0]));
    memcpy(ps->delay[k] + kMaxDelay, s[k], kSlots * sizeof(ps->delay[k][0]));
    const float (*d)[2] = ps->delay[k] + kMaxDelay - 1;
    for (int n = 0; n < nL - n0; n++) {
      out[k][n][0] = d[n][0] * transient_gain[i][n];
      out[k][n][1] = d[n][1] * transient_gain[i][n];
    }
  }
}

/* ── stereo processing (8.6.4.6.4) ─────────────────────────────── */

void stereo_interpolate(float (*l)[2], float (*r)[2], const float h[2][4], const float h_step[2][4],
                        int len) {
  float h0 = h[0][0];
  float h1 = h[0][1];
  float h2 = h[0][2];
  float h3 = h[0][3];
  for (int n = 0; n < len; n++) {
    const float l_re = l[n][0];
    const float l_im = l[n][1];
    const float r_re = r[n][0];
    const float r_im = r[n][1];
    h0 += h_step[0][0];
    h1 += h_step[0][1];
    h2 += h_step[0][2];
    h3 += h_step[0][3];
    l[n][0] = h0 * l_re + h2 * r_re;
    l[n][1] = h0 * l_im + h2 * r_im;
    r[n][0] = h1 * l_re + h3 * r_re;
    r[n][1] = h1 * l_im + h3 * r_im;
  }
}

void stereo_interpolate_ipdopd(float (*l)[2], float (*r)[2], const float h[2][4],
                               const float h_step[2][4], int len) {
  float h00 = h[0][0], h10 = h[1][0];
  float h01 = h[0][1], h11 = h[1][1];
  float h02 = h[0][2], h12 = h[1][2];
  float h03 = h[0][3], h13 = h[1][3];
  for (int n = 0; n < len; n++) {
    const float l_re = l[n][0];
    const float l_im = l[n][1];
    const float r_re = r[n][0];
    const float r_im = r[n][1];
    h00 += h_step[0][0];
    h01 += h_step[0][1];
    h02 += h_step[0][2];
    h03 += h_step[0][3];
    h10 += h_step[1][0];
    h11 += h_step[1][1];
    h12 += h_step[1][2];
    h13 += h_step[1][3];
    l[n][0] = h00 * l_re + h02 * r_re - h10 * l_im - h12 * r_im;
    l[n][1] = h00 * l_im + h02 * r_im + h10 * l_re + h12 * r_re;
    r[n][0] = h01 * l_re + h03 * r_re - h11 * l_im - h13 * r_im;
    r[n][1] = h01 * l_im + h03 * r_im + h11 * l_re + h13 * r_re;
  }
}

void stereo_processing(AacPsState* ps, float (*l)[kSlots][2], float (*r)[kSlots][2], int is34) {
  float (*H11)[AAC_PS_MAX_ENV + 1][AAC_PS_MAX_PAR] = ps->H11;
  float (*H12)[AAC_PS_MAX_ENV + 1][AAC_PS_MAX_PAR] = ps->H12;
  float (*H21)[AAC_PS_MAX_ENV + 1][AAC_PS_MAX_PAR] = ps->H21;
  float (*H22)[AAC_PS_MAX_ENV + 1][AAC_PS_MAX_PAR] = ps->H22;
  int8_t* opd_hist = ps->opd_hist;
  int8_t* ipd_hist = ps->ipd_hist;
  int8_t iid_mapped_buf[AAC_PS_MAX_ENV][AAC_PS_MAX_PAR];
  int8_t icc_mapped_buf[AAC_PS_MAX_ENV][AAC_PS_MAX_PAR];
  int8_t ipd_mapped_buf[AAC_PS_MAX_ENV][AAC_PS_MAX_PAR];
  int8_t opd_mapped_buf[AAC_PS_MAX_ENV][AAC_PS_MAX_PAR];
  int8_t (*iid_mapped)[AAC_PS_MAX_PAR] = iid_mapped_buf;
  int8_t (*icc_mapped)[AAC_PS_MAX_PAR] = icc_mapped_buf;
  int8_t (*ipd_mapped)[AAC_PS_MAX_PAR] = ipd_mapped_buf;
  int8_t (*opd_mapped)[AAC_PS_MAX_PAR] = opd_mapped_buf;
  const int8_t* const k_to_i = is34 ? aac_ps_k_to_i_34 : aac_ps_k_to_i_20;
  const float (*H_LUT)[8][4] = ps->icc_mode < 3 ? g_HA : g_HB;

  /* The last matrix of the previous frame starts the interpolation. */
  if (ps->num_env_old) {
    const int o = ps->num_env_old;
    memcpy(H11[0][0], H11[0][o], sizeof(H11[0][0]));
    memcpy(H11[1][0], H11[1][o], sizeof(H11[1][0]));
    memcpy(H12[0][0], H12[0][o], sizeof(H12[0][0]));
    memcpy(H12[1][0], H12[1][o], sizeof(H12[1][0]));
    memcpy(H21[0][0], H21[0][o], sizeof(H21[0][0]));
    memcpy(H21[1][0], H21[1][o], sizeof(H21[1][0]));
    memcpy(H22[0][0], H22[0][o], sizeof(H22[0][0]));
    memcpy(H22[1][0], H22[1][o], sizeof(H22[1][0]));
  }

  if (is34) {
    remap34(&iid_mapped, ps->iid_par, ps->nr_iid_par, ps->num_env, 1);
    remap34(&icc_mapped, ps->icc_par, ps->nr_icc_par, ps->num_env, 1);
    if (ps->enable_ipdopd) {
      remap34(&ipd_mapped, ps->ipd_par, ps->nr_ipdopd_par, ps->num_env, 0);
      remap34(&opd_mapped, ps->opd_par, ps->nr_ipdopd_par, ps->num_env, 0);
    }
    if (!ps->is34bands_old) {
      map_val_20_to_34(H11[0][0]);
      map_val_20_to_34(H11[1][0]);
      map_val_20_to_34(H12[0][0]);
      map_val_20_to_34(H12[1][0]);
      map_val_20_to_34(H21[0][0]);
      map_val_20_to_34(H21[1][0]);
      map_val_20_to_34(H22[0][0]);
      map_val_20_to_34(H22[1][0]);
      memset(ipd_hist, 0, AAC_PS_MAX_IPDOPD);
      memset(opd_hist, 0, AAC_PS_MAX_IPDOPD);
    }
  } else {
    remap20(&iid_mapped, ps->iid_par, ps->nr_iid_par, ps->num_env, 1);
    remap20(&icc_mapped, ps->icc_par, ps->nr_icc_par, ps->num_env, 1);
    if (ps->enable_ipdopd) {
      remap20(&ipd_mapped, ps->ipd_par, ps->nr_ipdopd_par, ps->num_env, 0);
      remap20(&opd_mapped, ps->opd_par, ps->nr_ipdopd_par, ps->num_env, 0);
    }
    if (ps->is34bands_old) {
      map_val_34_to_20(H11[0][0]);
      map_val_34_to_20(H11[1][0]);
      map_val_34_to_20(H12[0][0]);
      map_val_34_to_20(H12[1][0]);
      map_val_34_to_20(H21[0][0]);
      map_val_34_to_20(H21[1][0]);
      map_val_34_to_20(H22[0][0]);
      map_val_34_to_20(H22[1][0]);
      memset(ipd_hist, 0, AAC_PS_MAX_IPDOPD);
      memset(opd_hist, 0, AAC_PS_MAX_IPDOPD);
    }
  }

  for (int e = 0; e < ps->num_env; e++) {
    for (int b = 0; b < kNrParBands[is34]; b++) {
      const float* lut = H_LUT[iid_mapped[e][b] + 7 + 23 * ps->iid_quant][icc_mapped[e][b]];
      float h11 = lut[0];
      float h12 = lut[1];
      float h21 = lut[2];
      float h22 = lut[3];
      if (ps->enable_ipdopd && b < kNrIpdopdBands[is34]) {
        /* The phase parameters are smoothed over the two previous values
         * and rotate the matrix. */
        const int opd_idx = opd_hist[b] * 8 + opd_mapped[e][b];
        const int ipd_idx = ipd_hist[b] * 8 + ipd_mapped[e][b];
        const float opd_re = g_pd_re_smooth[opd_idx];
        const float opd_im = g_pd_im_smooth[opd_idx];
        const float ipd_re = g_pd_re_smooth[ipd_idx];
        const float ipd_im = g_pd_im_smooth[ipd_idx];
        opd_hist[b] = (int8_t)(opd_idx & 0x3F);
        ipd_hist[b] = (int8_t)(ipd_idx & 0x3F);
        const float ipd_adj_re = opd_re * ipd_re + opd_im * ipd_im;
        const float ipd_adj_im = opd_im * ipd_re - opd_re * ipd_im;
        H11[1][e + 1][b] = h11 * opd_im;
        h11 = h11 * opd_re;
        H12[1][e + 1][b] = h12 * ipd_adj_im;
        h12 = h12 * ipd_adj_re;
        H21[1][e + 1][b] = h21 * opd_im;
        h21 = h21 * opd_re;
        H22[1][e + 1][b] = h22 * ipd_adj_im;
        h22 = h22 * ipd_adj_re;
      }
      H11[0][e + 1][b] = h11;
      H12[0][e + 1][b] = h12;
      H21[0][e + 1][b] = h21;
      H22[0][e + 1][b] = h22;
    }
    for (int k = 0; k < kNrBands[is34]; k++) {
      float h[2][4] = {};
      float h_step[2][4] = {};
      const int start = ps->border_position[e];
      const int stop = ps->border_position[e + 1];
      const float width = 1.0f / (float)((stop - start) ? (stop - start) : 1);
      const int b = k_to_i[k];
      h[0][0] = H11[0][e][b];
      h[0][1] = H12[0][e][b];
      h[0][2] = H21[0][e][b];
      h[0][3] = H22[0][e][b];
      if (ps->enable_ipdopd) {
        /* Sub-subbands of negative centre frequency take the conjugate. */
        if ((is34 && k <= 13 && k >= 9) || (!is34 && k <= 1)) {
          h[1][0] = -H11[1][e][b];
          h[1][1] = -H12[1][e][b];
          h[1][2] = -H21[1][e][b];
          h[1][3] = -H22[1][e][b];
        } else {
          h[1][0] = H11[1][e][b];
          h[1][1] = H12[1][e][b];
          h[1][2] = H21[1][e][b];
          h[1][3] = H22[1][e][b];
        }
      }
      h_step[0][0] = (H11[0][e + 1][b] - h[0][0]) * width;
      h_step[0][1] = (H12[0][e + 1][b] - h[0][1]) * width;
      h_step[0][2] = (H21[0][e + 1][b] - h[0][2]) * width;
      h_step[0][3] = (H22[0][e + 1][b] - h[0][3]) * width;
      if (ps->enable_ipdopd) {
        h_step[1][0] = (H11[1][e + 1][b] - h[1][0]) * width;
        h_step[1][1] = (H12[1][e + 1][b] - h[1][1]) * width;
        h_step[1][2] = (H21[1][e + 1][b] - h[1][2]) * width;
        h_step[1][3] = (H22[1][e + 1][b] - h[1][3]) * width;
      }
      if (stop - start) {
        if (ps->enable_ipdopd) {
          stereo_interpolate_ipdopd(l[k] + 1 + start, r[k] + 1 + start, h, h_step, stop - start);
        } else {
          stereo_interpolate(l[k] + 1 + start, r[k] + 1 + start, h, h_step, stop - start);
        }
      }
    }
  }
}

}  // namespace

/* ── public ────────────────────────────────────────────────────── */

void aac_ps_init(AacPsState* ps) {
  init_tables();
  memset(ps, 0, sizeof(*ps));
}

int aac_ps_read_data(AacPsState* ps, AacBitReader* r, int bits_left) {
  AacBitReader local = *r;
  const int start = local.pos;
  const int ret = read_data(ps, &local);
  const int bits_consumed = local.pos - start;
  if (ret == 0 && bits_consumed <= bits_left) {
    aac_bitreader_skip(r, bits_consumed);
    return bits_consumed;
  }
  ps->start = 0;
  aac_bitreader_skip(r, bits_left);
  clear_parameters(ps);
  return bits_left;
}

void aac_ps_conceal(AacPsState* ps) {
  const int last = ps->num_env > 0 ? ps->num_env - 1 : 0;
  if (last != 0) {
    memcpy(ps->iid_par[0], ps->iid_par[last], sizeof(ps->iid_par[0]));
    memcpy(ps->icc_par[0], ps->icc_par[last], sizeof(ps->icc_par[0]));
    memcpy(ps->ipd_par[0], ps->ipd_par[last], sizeof(ps->ipd_par[0]));
    memcpy(ps->opd_par[0], ps->opd_par[last], sizeof(ps->opd_par[0]));
  }
  ps->num_env_old = ps->num_env;
  ps->num_env = 1;
  ps->frame_class = 0;
  ps->border_position[0] = -1;
  ps->border_position[1] = kSlots - 1;
  ps->is34bands_old = ps->is34bands;
  ps->unreliable = 1;
}

void aac_ps_apply(AacPsState* ps, float L[2][38][64], float R[2][38][64], int top) {
  const int is34 = ps->is34bands;
  top = std::min(std::max(top + kNrBands[is34] - 64, 0), kNrBands[is34]);
  memset(ps->delay + top, 0, (size_t)(kNrBands[is34] - top) * sizeof(ps->delay[0]));
  if (top < kNrAllpassBands[is34]) {
    memset(ps->ap_delay + top, 0, (size_t)(kNrAllpassBands[is34] - top) * sizeof(ps->ap_delay[0]));
  }
  aac_ps_hybrid_analysis(ps->Lbuf, ps->in_buf, L, is34);
  decorrelation(ps, ps->Rbuf, ps->Lbuf, is34);
  stereo_processing(ps, ps->Lbuf, ps->Rbuf, is34);
  aac_ps_hybrid_synthesis(L, ps->Lbuf, is34);
  aac_ps_hybrid_synthesis(R, ps->Rbuf, is34);
}
