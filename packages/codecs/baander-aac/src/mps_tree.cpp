#include <cmath>
#include <cstring>

#include "mps212.h"
#include "mps_signal.h"

/* The trees of ISO/IEC 23003-1 and their mixing matrices (6.5).
 *
 * 5151 (one input m): OTT0 splits m into the front group (L, R, C, LFE)
 * and the surround group; OTT1 splits the front group into L+R and
 * C+LFE; OTT3 gives L and R, OTT4 (the LFE box, CLD only) C and LFE,
 * OTT2 Ls and Rs.  5152: OTT0 splits m into L+R+Ls+Rs and C+LFE; OTT1
 * splits the first into L+Ls and R+Rs; OTT3 gives L and Ls, OTT4 R and
 * Rs, OTT2 (the LFE box) C and LFE.  525 (two inputs): the TTT box
 * rebuilds l, r and c from the downmix (and its residual); OTT1 splits
 * l into L and Ls, OTT2 r into R and Rs, OTT0 (the LFE box) c into C
 * and LFE.  212: OTT0 splits m into L and R.
 *
 * M1 maps the inputs x to the direct signals and the decorrelator
 * inputs v; M2 maps the direct, decorrelated and residual signals w to
 * the output channels.  A decorrelator input is the direct part of its
 * box's input (the CLD gains of the boxes above it, without their
 * decorrelated parts); M2 carries the products of the box matrices
 * along each path of the tree. */

namespace {

constexpr float kSqrt2 = 1.41421356f;
constexpr double kSqrt3 = 1.7320508075688772;

enum { kL = 0, kR = 1, kC = 2, kLfe = 3, kLs = 4, kRs = 5 };

int clamp_cld(int idx) { return idx < -15 ? -15 : (idx > 15 ? 15 : idx); }

/* 10^(CLD/10) of a CLD index. */
double cld_ratio(int idx) { return pow(10.0, aac_mps_cld_db[clamp_cld(idx) + 15] / 10.0); }

/* The amplitude of the first channel of a one-to-two split with CLD
 * `idx`: sqrt(r / (1 + r)); the second channel's is cld_amp(-idx). */
double cld_amp(int idx) {
  const double r = cld_ratio(idx);
  return sqrt(r / (1.0 + r));
}

/* The gain of an arbitrary downmix index on the CLD scale (dB). */
float arbdmx_gain(int idx) { return (float)pow(10.0, aac_mps_cld_db[clamp_cld(idx) + 15] / 20.0); }

float icc_of(int idx) { return aac_mps_icc_value[idx < 0 ? 0 : (idx > 7 ? 7 : idx)]; }

float cpc_of(int idx) {
  const int i = idx + 20;
  return aac_mps_cpc_value[i < 0 ? 0 : (i > 50 ? 50 : i)];
}

/* The mode of the TTT box in a band (bsTttModeLow below bsTttBandsLow
 * with bsTttDualMode, bsTttModeHigh above). */
int ttt_mode_of(const AacMpsConfig* cfg, int band) {
  if (cfg->ttt_dual_mode[0] && band >= cfg->ttt_bands_low[0]) {
    return cfg->ttt_mode_high[0];
  }
  return cfg->ttt_mode_low[0];
}

/* The residual bands of a box (0 without residual). */
int res_bands_of(const AacMpsConfig* cfg, int box) {
  if (!cfg->residual_coding || !cfg->residual_present[box]) {
    return 0;
  }
  return cfg->residual_bands[box];
}

/* The CLD-only gains of the LFE box: the first output's and the second
 * output's amplitude below bsOttBands, the first output alone above. */
void lfe_gains(const AacMpsConfig* cfg, int box, int cld_idx, int band, float* c_l, float* c_r) {
  if (band < cfg->ott_bands[box]) {
    *c_l = (float)cld_amp(cld_idx);
    *c_r = (float)cld_amp(-cld_idx);
  } else {
    *c_l = 1.0f;
    *c_r = 0.0f;
  }
}

/* The OTT box `box` of the frame in a band: the matrix h, the residual
 * weights (1 and -1 in the residual bands) and the first output's CLD
 * amplitude. */
struct OttBox {
  float h[2][2];
  float res1, res2;
  float c_l;
};

void ott_box(const AacMpsConfig* cfg, const AacMpsFrameParams* p, int box, int set, int band,
             OttBox* o) {
  const int cld = p->ott_cld[box][set][band];
  const int icc = p->ott_icc[box][set][band];
  const int residual = band < res_bands_of(cfg, box);
  aac_mps_ott_matrix(cld, icc, residual, o->h);
  o->res1 = residual ? 1.0f : 0.0f;
  o->res2 = residual ? -1.0f : 0.0f;
  o->c_l = (float)cld_amp(cld);
}

/* Complex multiply-accumulate out += a * b. */
void cmac(float out[2], const float a[2], const float b[2]) {
  out[0] += a[0] * b[0] - a[1] * b[1];
  out[1] += a[0] * b[1] + a[1] * b[0];
}

void matrices_5151(const AacMpsConfig* cfg, const AacMpsFrameParams* p, int set, int band,
                   AacMpsBandMatrices* m) {
  OttBox fs, c, s, f;
  ott_box(cfg, p, 0, set, band, &fs);
  ott_box(cfg, p, 1, set, band, &c);
  ott_box(cfg, p, 2, set, band, &s);
  ott_box(cfg, p, 3, set, band, &f);
  float cl_lfe, cr_lfe;
  lfe_gains(cfg, 4, p->ott_cld[4][set][band], band, &cl_lfe, &cr_lfe);
  const float c_r_fs = (float)cld_amp(-p->ott_cld[0][set][band]);
  const float c_r_c = (float)cld_amp(-p->ott_cld[1][set][band]);
  const float g = cfg->arbitrary_downmix ? arbdmx_gain(p->arbdmx_gain[0][set][band]) : 1.0f;

  /* M1: m, then the decorrelator inputs of OTT0, OTT1, OTT3, OTT2 */
  m->m1[0][0][0] = g;
  m->m1[1][0][0] = g;
  m->m1[2][0][0] = g * fs.c_l;
  m->m1[3][0][0] = g * fs.c_l * c.c_l;
  m->m1[4][0][0] = g * c_r_fs;

  /* M2 over w = (m, d_fs, d_c, d_f, d_s) */
  const float h11_fs = fs.h[0][0], h12_fs = fs.h[0][1], h21_fs = fs.h[1][0], h22_fs = fs.h[1][1];
  const float h11_c = c.h[0][0], h12_c = c.h[0][1], h21_c = c.h[1][0], h22_c = c.h[1][1];
  for (int side = 0; side < 2; side++) {
    const int out = side == 0 ? kL : kR;
    const float hf = f.h[side][0], hf_d = f.h[side][1], hf_res = side == 0 ? f.res1 : f.res2;
    m->m2_dry[out][0] = hf * h11_c * h11_fs;
    m->m2_dry[out][1] = hf * h11_c * fs.res1;
    m->m2_dry[out][2] = hf * c.res1;
    m->m2_dry[out][3] = hf_res;
    m->m2_wet[out][1] = hf * h11_c * h12_fs;
    m->m2_wet[out][2] = hf * h12_c;
    m->m2_wet[out][3] = hf_d;
  }
  m->m2_dry[kC][0] = cl_lfe * h21_c * h11_fs;
  m->m2_dry[kC][1] = cl_lfe * h21_c * fs.res1;
  m->m2_dry[kC][2] = cl_lfe * c.res2;
  m->m2_wet[kC][1] = cl_lfe * h21_c * h12_fs;
  m->m2_wet[kC][2] = cl_lfe * h22_c;
  m->m2_dry[kLfe][0] = cr_lfe * c_r_c * fs.c_l;
  for (int side = 0; side < 2; side++) {
    const int out = side == 0 ? kLs : kRs;
    const float hs = s.h[side][0], hs_d = s.h[side][1], hs_res = side == 0 ? s.res1 : s.res2;
    m->m2_dry[out][0] = hs * h21_fs;
    m->m2_dry[out][1] = hs * fs.res2;
    m->m2_dry[out][4] = hs_res;
    m->m2_wet[out][1] = hs * h22_fs;
    m->m2_wet[out][4] = hs_d;
  }
}

void matrices_5152(const AacMpsConfig* cfg, const AacMpsFrameParams* p, int set, int band,
                   AacMpsBandMatrices* m) {
  OttBox c, lr, l, r;
  ott_box(cfg, p, 0, set, band, &c);
  ott_box(cfg, p, 1, set, band, &lr);
  ott_box(cfg, p, 3, set, band, &l);
  ott_box(cfg, p, 4, set, band, &r);
  float cl_lfe, cr_lfe;
  lfe_gains(cfg, 2, p->ott_cld[2][set][band], band, &cl_lfe, &cr_lfe);
  const float c_r_lr = (float)cld_amp(-p->ott_cld[1][set][band]);
  const float c_r_c = (float)cld_amp(-p->ott_cld[0][set][band]);
  const float g = cfg->arbitrary_downmix ? arbdmx_gain(p->arbdmx_gain[0][set][band]) : 1.0f;

  /* M1: m, then the decorrelator inputs of OTT0, OTT1, OTT3, OTT4 */
  m->m1[0][0][0] = g;
  m->m1[1][0][0] = g;
  m->m1[2][0][0] = g * c.c_l;
  m->m1[3][0][0] = g * c.c_l * lr.c_l;
  m->m1[4][0][0] = g * c.c_l * c_r_lr;

  /* M2 over w = (m, d_c, d_lr, d_l, d_r) */
  const float h11_c = c.h[0][0], h12_c = c.h[0][1], h21_c = c.h[1][0], h22_c = c.h[1][1];
  for (int side = 0; side < 2; side++) {
    const int out = side == 0 ? kL : kLs;
    const float hl = l.h[side][0], hl_d = l.h[side][1], hl_res = side == 0 ? l.res1 : l.res2;
    m->m2_dry[out][0] = hl * lr.h[0][0] * h11_c;
    m->m2_dry[out][1] = hl * lr.h[0][0] * c.res1;
    m->m2_dry[out][2] = hl * lr.res1;
    m->m2_dry[out][3] = hl_res;
    m->m2_wet[out][1] = hl * lr.h[0][0] * h12_c;
    m->m2_wet[out][2] = hl * lr.h[0][1];
    m->m2_wet[out][3] = hl_d;
  }
  for (int side = 0; side < 2; side++) {
    const int out = side == 0 ? kR : kRs;
    const float hr = r.h[side][0], hr_d = r.h[side][1], hr_res = side == 0 ? r.res1 : r.res2;
    m->m2_dry[out][0] = hr * lr.h[1][0] * h11_c;
    m->m2_dry[out][1] = hr * lr.h[1][0] * c.res1;
    m->m2_dry[out][2] = hr * lr.res2;
    m->m2_dry[out][4] = hr_res;
    m->m2_wet[out][1] = hr * lr.h[1][0] * h12_c;
    m->m2_wet[out][2] = hr * lr.h[1][1];
    m->m2_wet[out][4] = hr_d;
  }
  m->m2_dry[kC][0] = cl_lfe * h21_c;
  m->m2_dry[kC][1] = cl_lfe * c.res2;
  m->m2_wet[kC][1] = cl_lfe * h22_c;
  m->m2_dry[kLfe][0] = c_r_c * cr_lfe;
}

void matrices_525(const AacMpsConfig* cfg, const AacMpsTreeLayout* t, const AacMpsFrameParams* p,
                  int set, int band, AacMpsBandMatrices* m) {
  const int ttt = AAC_MPS_MAX_OTT_BOXES; /* the TTT box in the residual numbering */
  const int mode = ttt_mode_of(cfg, band);
  const int res_ttt = res_bands_of(cfg, ttt);
  const int cpc1 = p->ttt_cpc1[0][set][band], cpc2 = p->ttt_cpc2[0][set][band];
  const int icc = p->ttt_icc[0][set][band];
  const int cld1 = p->ttt_cld1[0][set][band], cld2 = p->ttt_cld2[0][set][band];

  /* the TTT matrix on (first input, second input, residual), the
   * arbitrary downmix gains undone on the inputs */
  float mttt[3][3];
  aac_mps_ttt_matrix(mode, cpc1, cpc2, icc, cld1, cld2, band < res_ttt, mttt);
  float pre[3][3];
  memcpy(pre, mttt, sizeof(pre));
  if (cfg->arbitrary_downmix) {
    for (int ch = 0; ch < 2; ch++) {
      const float g = arbdmx_gain(p->arbdmx_gain[ch][set][band]);
      for (int row = 0; row < 3; row++) {
        pre[row][ch] *= g;
      }
    }
  }
  const int invert = cfg->matrix_mode && (mode < 2 || mode == 2 || mode == 4);
  if (invert) {
    float h[2][2][2];
    const int prediction = mode < 2;
    aac_mps_ttt_inverse(p->ott_cld[1][set][band], p->ott_cld[2][set][band], prediction,
                        prediction ? cpc1 : cld1, prediction ? cpc2 : cld2, h);
    for (int row = 0; row < 3; row++) {
      for (int col = 0; col < 2; col++) {
        for (int k = 0; k < 2; k++) {
          const float a[2] = {pre[row][k], 0.0f};
          cmac(m->m1[row][col], a, h[k][col]);
        }
      }
    }
  } else {
    for (int row = 0; row < 3; row++) {
      for (int col = 0; col < 2; col++) {
        m->m1[row][col][0] = pre[row][col];
      }
    }
  }
  for (int row = 0; row < 3; row++) {
    m->m1[row][2][0] = pre[row][2];
  }
  /* the decorrelator inputs: l, r and (TTT mode 0) c */
  memcpy(m->m1[3], m->m1[0], sizeof(m->m1[0]));
  memcpy(m->m1[4], m->m1[1], sizeof(m->m1[1]));
  memcpy(m->m1[5], m->m1[2], sizeof(m->m1[2]));

  /* the OTT boxes over w = (l, r, c, d_l, d_r, d_ttt) */
  OttBox l, r;
  ott_box(cfg, p, 1, set, band, &l);
  ott_box(cfg, p, 2, set, band, &r);
  float cl_lfe, cr_lfe;
  lfe_gains(cfg, 0, p->ott_cld[0][set][band], band, &cl_lfe, &cr_lfe);
  m->m2_dry[kL][0] = l.h[0][0];
  m->m2_dry[kL][3] = l.res1;
  m->m2_wet[kL][3] = l.h[0][1];
  m->m2_dry[kLs][0] = l.h[1][0];
  m->m2_dry[kLs][3] = l.res2;
  m->m2_wet[kLs][3] = l.h[1][1];
  m->m2_dry[kR][1] = r.h[0][0];
  m->m2_dry[kR][4] = r.res1;
  m->m2_wet[kR][4] = r.h[0][1];
  m->m2_dry[kRs][1] = r.h[1][0];
  m->m2_dry[kRs][4] = r.res2;
  m->m2_wet[kRs][4] = r.h[1][1];
  m->m2_dry[kC][2] = cl_lfe;
  m->m2_dry[kLfe][2] = cr_lfe;

  /* TTT mode 0 outside its residual bands: the direct parts take the
   * ICC, the TTT decorrelator fills the rest of the energy */
  if (t->num_decorr == 3 && t->ttt_decorr_band[band] && band >= res_ttt) {
    const float rho = icc_of(icc);
    const float g_dd = sqrtf((1.0f - rho * rho) / 3.0f);
    m->m2_dry[kL][0] *= rho;
    m->m2_dry[kR][1] *= rho;
    m->m2_dry[kC][2] *= rho;
    m->m2_wet[kL][5] = g_dd * l.c_l;
    m->m2_wet[kR][5] = g_dd * r.c_l;
    m->m2_wet[kC][5] = -kSqrt2 * g_dd * cl_lfe;
  }
}

void matrices_212(const AacMpsConfig* cfg, const AacMpsFrameParams* p, int set, int band,
                  AacMpsBandMatrices* m) {
  OttBox o;
  ott_box(cfg, p, 0, set, band, &o);
  m->m1[0][0][0] = 1.0f;
  m->m1[1][0][0] = 1.0f;
  m->m2_dry[0][0] = o.h[0][0];
  m->m2_dry[0][1] = o.res1;
  m->m2_wet[0][1] = o.h[0][1];
  m->m2_dry[1][0] = o.h[1][0];
  m->m2_dry[1][1] = o.res2;
  m->m2_wet[1][1] = o.h[1][1];
}

}  // namespace

int aac_mps_tree_layout(const AacMpsConfig* cfg, AacMpsTreeLayout* t) {
  memset(t, 0, sizeof(*t));
  for (int i = 0; i < AAC_MPS_MAX_DECORR; i++) {
    t->decorr_box[i] = -1;
  }
  for (int i = 0; i < AAC_MPS_MAX_OUTPUT_CHANNELS; i++) {
    t->stp_group[i] = -1;
    t->ges_ref[i] = -1;
  }
  t->ttt_residual_col = -1;
  switch (cfg->tree_config) {
    case AAC_MPS_TREE_5151:
    case AAC_MPS_TREE_5152:
      t->num_inputs = 1;
      t->num_x = 1;
      t->num_direct = 1;
      t->num_decorr = 4;
      t->num_outputs = 6;
      t->decorr_box[0] = 0;
      t->decorr_box[1] = 1;
      t->decorr_box[2] = 3;
      t->decorr_box[3] = cfg->tree_config == AAC_MPS_TREE_5151 ? 2 : 4;
      for (int ch = 0; ch < 6; ch++) {
        t->stp_group[ch] = ch == kLfe ? -1 : 0;
        t->ges_ref[ch] = ch == kLfe ? -1 : 0;
      }
      return AAC_OK;
    case AAC_MPS_TREE_525: {
      t->num_inputs = 2;
      t->num_x = 3;
      t->num_direct = 3;
      t->num_outputs = 6;
      t->ttt_residual_col = 2;
      t->decorr_box[0] = 1;
      t->decorr_box[1] = 2;
      const int bands = aac_mps212_freq_res_bands[cfg->freq_res & 7];
      int ttt_decorr = 0;
      for (int pb = 0; pb < bands; pb++) {
        const int mode = ttt_mode_of(cfg, pb);
        if (mode < 0 || mode > 5) {
          return AAC_ERR_UNSUPPORTED;
        }
        t->ttt_decorr_band[pb] = mode == 0;
        ttt_decorr |= mode == 0;
      }
      t->num_decorr = ttt_decorr ? 3 : 2;
      t->stp_group[kL] = t->stp_group[kLs] = 0;
      t->stp_group[kR] = t->stp_group[kRs] = 1;
      t->ges_ref[kL] = t->ges_ref[kLs] = 0;
      t->ges_ref[kR] = t->ges_ref[kRs] = 1;
      t->ges_ref[kC] = 2;
      return AAC_OK;
    }
    case AAC_MPS_TREE_212:
      t->num_inputs = 1;
      t->num_x = 1;
      t->num_direct = 1;
      t->num_decorr = 1;
      t->num_outputs = 2;
      t->decorr_box[0] = 0;
      t->stp_group[0] = t->stp_group[1] = 0;
      t->ges_ref[0] = t->ges_ref[1] = 0;
      return AAC_OK;
    default:
      return AAC_ERR_UNSUPPORTED;
  }
}

void aac_mps_ott_matrix(int cld_idx, int icc_idx, int residual_band, float h[2][2]) {
  const double c1 = cld_amp(cld_idx);
  const double c2 = cld_amp(-cld_idx);
  double rho = icc_of(icc_idx);
  if (residual_band) {
    /* the downmix of the two channels, 1 + 2 rho c1 c2 of their energy,
     * is held above 1/1.44: a gain of at most 1.2 on the prediction */
    const double floor = -(1.0 - 1.0 / 1.44) / (2.0 * c1 * c2);
    if (rho < floor) {
      rho = floor;
    }
  }
  const double alpha = 0.5 * acos(rho);
  const double beta = atan(tan(alpha) * (c2 - c1) / (c1 + c2));
  h[0][0] = (float)(c1 * cos(alpha + beta));
  h[0][1] = residual_band ? 0.0f : (float)(c1 * sin(alpha + beta));
  h[1][0] = (float)(c2 * cos(-alpha + beta));
  h[1][1] = residual_band ? 0.0f : (float)(c2 * sin(-alpha + beta));
}

void aac_mps_ttt_matrix(int mode, int cpc1_idx, int cpc2_idx, int icc_idx, int cld1_idx,
                        int cld2_idx, int residual_band, float m[3][3]) {
  memset(m, 0, sizeof(float) * 9);
  if (mode < 2) {
    /* prediction: the pseudo-inverse of the downmix corrected by the
     * CPCs, scaled by 1/ICC outside the residual bands to restore the
     * energy the prediction loses; in mode 0 M2 scales the direct parts
     * back by the ICC and the TTT decorrelator carries the rest */
    const float c1 = cpc_of(cpc1_idx), c2 = cpc_of(cpc2_idx);
    m[0][0] = (c1 + 2.0f) / 3.0f;
    m[0][1] = (c2 - 1.0f) / 3.0f;
    m[1][0] = (c1 - 1.0f) / 3.0f;
    m[1][1] = (c2 + 2.0f) / 3.0f;
    m[2][0] = (1.0f - c1) / 3.0f;
    m[2][1] = (1.0f - c2) / 3.0f;
    if (!residual_band) {
      const float rho = icc_of(icc_idx);
      const float k = rho != 0.0f ? 1.0f / rho : 0.0f;
      for (int row = 0; row < 3; row++) {
        m[row][0] *= k;
        m[row][1] *= k;
      }
    }
  } else {
    const int i1 = clamp_cld(cld1_idx), i2 = clamp_cld(cld2_idx);
    const double c1d = cld_ratio(i1), c2d = cld_ratio(i2);
    if (i1 == 15 || i2 == 15) {
      /* a channel group at the top of the CLD scale */
      double w00 = 1.0, w11 = 1.0, w20 = 0.0, w21 = 0.0;
      if (i1 == 15) {
        w00 = i2 == -15 ? 1.0 / sqrt(2.0) : 1.0;
        w20 = i2 == -15 ? 1.0 / sqrt(8.0) : 0.0;
        w11 = i2 == 15 ? 1.0 / sqrt(2.0) : 1.0;
        w21 = i2 == 15 ? 1.0 / sqrt(8.0) : 0.0;
      }
      if (i2 == 15) {
        w11 = i1 == 15 ? 1.0 / sqrt(2.0) : 0.0;
        w21 = i1 == 15 ? 1.0 / sqrt(8.0) : 0.5;
        w00 = cld_amp(i1);
        w20 = cld_amp(-i1) / 2.0;
      }
      m[0][0] = (float)w00;
      m[1][1] = (float)w11;
      m[2][0] = (float)w20;
      m[2][1] = (float)w21;
    } else {
      const double prod = c1d * c2d;
      m[0][0] = (float)sqrt(prod / (1.0 + c2d + prod));
      m[1][1] = (float)sqrt(c1d / (c1d + c2d + 1.0));
      m[2][0] = (float)(sqrt((c2d + 1.0) / (1.0 + prod + c2d)) / 2.0);
      m[2][1] = (float)(sqrt((c2d + 1.0) / (c1d + c2d + 1.0)) / 2.0);
    }
    if (mode == 2 || mode == 3) {
      /* centre subtraction: the centre's share is taken out of the
       * other input before the energy split */
      if (i1 == 15 && i2 == 15) {
        m[0][0] = 1.0f;
        m[0][1] = -0.5f;
        m[1][1] = (float)(1.0 / sqrt(2.0));
        m[1][0] = 0.0f;
      } else if (i1 == 15) {
        m[0][0] = i2 == -15 ? (float)(1.0 / sqrt(2.0)) : 1.0f;
        m[1][0] = i2 == -15 ? -0.5f : 0.0f;
        m[0][1] = 0.0f;
        m[1][1] = 1.0f;
      } else if (i2 == 15) {
        m[0][0] = 1.0f;
        m[0][1] = -1.0f;
        m[1][1] = 0.0f;
        m[1][0] = 0.0f;
      } else {
        const double c = 1.0 / (c1d + 1.0);
        const double r = c1d / (c2d + 1.0) * c;
        const double l = c2d * r;
        const double wl1 = sqrt((r + c) / (c * (r / l + 1.0) + r));
        m[0][0] = (float)wl1;
        m[0][1] = (float)(-c * wl1 / (r + c));
        const double wr1 = sqrt((l + c) / (c * (l / r + 1.0) + l));
        m[1][1] = (float)wr1;
        m[1][0] = (float)(-c * wr1 / (l + c));
      }
    }
  }
  m[0][2] = 1.0f / 3.0f;
  m[1][2] = 1.0f / 3.0f;
  m[2][2] = -1.0f / 3.0f;
  for (int col = 0; col < 3; col++) {
    m[2][col] *= kSqrt2;
  }
}

void aac_mps_ttt_inverse(int cld_l_idx, int cld_r_idx, int prediction, int p1_idx, int p2_idx,
                         float h[2][2][2]) {
  const double w1 = 1.0 / cld_ratio(cld_l_idx);
  const double w2 = 1.0 / cld_ratio(cld_r_idx);
  double c1, c2;
  if (prediction) {
    const double c[2] = {cpc_of(p1_idx), cpc_of(p2_idx)};
    double o[2];
    for (int i = 0; i < 2; i++) {
      if (fabs(c[i]) >= 1.0) {
        o[i] = 1.0;
      } else if (c[i] < -0.5) {
        o[i] = -1.0 - 2.0 * c[i];
      } else {
        o[i] = 1.0 / 3.0 + 2.0 / 3.0 * c[i];
      }
    }
    c1 = o[0];
    c2 = o[1];
  } else {
    const double c1p = cld_ratio(p1_idx), c2p = cld_ratio(p2_idx);
    c1 = sqrt(c1p * c2p / (c1p * c2p + 2.0 * (1.0 + c2p)));
    c2 = sqrt(c1p / (c1p + 2.0 * (1.0 + c2p)));
  }
  const double weight1 = c1 * w1 / (1.0 + w1);
  const double weight2 = c2 * w2 / (1.0 + w2);
  const double len1 = sqrt(1.0 - 2.0 * weight1 + 2.0 * weight1 * weight1);
  const double len2 = sqrt(1.0 - 2.0 * weight2 + 2.0 * weight2 * weight2);
  /* the matrix-compatible downmix as a complex 2x2 */
  const double h11[2] = {(1.0 - weight1) / len1, weight1 / len1};
  const double h22[2] = {(1.0 - weight2) / len2, -weight2 / len2};
  const double h12[2] = {0.0, h22[1] / kSqrt3};
  const double h21[2] = {0.0, -h11[1] / kSqrt3};
  const double det[2] = {h11[0] * h22[0] - h11[1] * h22[1] - (h12[0] * h21[0] - h12[1] * h21[1]),
                         h11[0] * h22[1] + h11[1] * h22[0] - (h12[0] * h21[1] + h12[1] * h21[0])};
  const double n = det[0] * det[0] + det[1] * det[1];
  const double inv[2] = {det[0] / n, -det[1] / n};
  const double* adj[2][2] = {{h22, h12}, {h21, h11}};
  const double sign[2][2] = {{1.0, -1.0}, {-1.0, 1.0}};
  for (int row = 0; row < 2; row++) {
    for (int col = 0; col < 2; col++) {
      const double* a = adj[row][col];
      h[row][col][0] = (float)(sign[row][col] * (a[0] * inv[0] - a[1] * inv[1]));
      h[row][col][1] = (float)(sign[row][col] * (a[0] * inv[1] + a[1] * inv[0]));
    }
  }
}

void aac_mps_tree_matrices(const AacMpsConfig* cfg, const AacMpsTreeLayout* t,
                           const AacMpsFrameParams* p, int set, int band, AacMpsBandMatrices* m) {
  memset(m, 0, sizeof(*m));
  switch (cfg->tree_config) {
    case AAC_MPS_TREE_5151:
      matrices_5151(cfg, p, set, band, m);
      break;
    case AAC_MPS_TREE_5152:
      matrices_5152(cfg, p, set, band, m);
      break;
    case AAC_MPS_TREE_525:
      matrices_525(cfg, t, p, set, band, m);
      break;
    case AAC_MPS_TREE_212:
      matrices_212(cfg, p, set, band, m);
      break;
    default:
      break;
  }
}
