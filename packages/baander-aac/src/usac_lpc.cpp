/* The LPC side of the USAC linear prediction domain core (ISO/IEC
 * 23003-3 7.13): the LSF quantiser with its first stage codebook and the
 * algebraic vector quantiser on the RE8 lattice, the LSF/LSP/LPC
 * conversions, the interpolation of the filter over a frame, and the
 * synthesis, analysis and emphasis filters. */
#include <cmath>
#include <cstring>

#include "usac_lpd_internal.h"

namespace {

constexpr double kPi = 3.14159265358979323846;

/* The polynomial of the eight LSPs lsp[0], lsp[2], ... (or the odd
 * ones): f[0 .. 8] with f[0] = 1. */
/* The polynomial of the LSPs at even (or odd) positions, in double: the
 * expansion is ill conditioned for closely spaced LSPs, and the
 * synthesis filters it yields amplify rounding differences audibly. */
void lsp_poly(const double* lsp, double* f) {
  f[0] = 1.0;
  f[1] = -2.0 * lsp[0];
  for (int i = 2; i <= LPD_M / 2; i++) {
    const double b = -2.0 * lsp[2 * (i - 1)];
    f[i] = b * f[i - 1] + 2.0 * f[i - 2];
    for (int j = i - 1; j > 1; j--) {
      f[j] += b * f[j - 1] + f[j - 2];
    }
    f[1] += b;
  }
}

void lsp_to_a(const double* lsp, float* a) {
  double f1[LPD_M / 2 + 1], f2[LPD_M / 2 + 1];
  lsp_poly(lsp, f1);
  lsp_poly(lsp + 1, f2);
  for (int i = LPD_M / 2; i > 0; i--) {
    f1[i] += f1[i - 1];
    f2[i] -= f2[i - 1];
  }
  a[0] = 1.0f;
  for (int i = 1; i <= LPD_M / 2; i++) {
    a[i] = (float)(0.5 * (f1[i] + f2[i]));
    a[LPD_M + 1 - i] = (float)(0.5 * (f1[i] - f2[i]));
  }
}

}  // namespace

/* ── conversions ────────────────────────────────────────────────── */

void lpd_lsf_to_lsp(const float* lsf, float* lsp) {
  for (int i = 0; i < LPD_M; i++) {
    lsp[i] = (float)cos((double)lsf[i] * (kPi / 6400.0));
  }
}

void lpd_lsp_to_lsf(const float* lsp, float* lsf) {
  for (int i = 0; i < LPD_M; i++) {
    lsf[i] = (float)(acos((double)lsp[i]) * (6400.0 / kPi));
  }
}

void lpd_lsp_to_a(const float* lsp, float* a) {
  double l[LPD_M];
  for (int i = 0; i < LPD_M; i++) {
    l[i] = lsp[i];
  }
  lsp_to_a(l, a);
}

void lpd_weight_a(const float* a, float gamma, float* ap) {
  float g = 1.0f;
  ap[0] = a[0];
  for (int i = 1; i <= LPD_M; i++) {
    g *= gamma;
    ap[i] = g * a[i];
  }
}

void lpd_interp_lsp(const float* lsp0, const float* lsp1, int n, float a[][LPD_M + 1]) {
  for (int k = 0; k < n; k++) {
    const double w1 = (k + 0.5) / n;
    const double w0 = 1.0 - w1;
    double lsp[LPD_M];
    for (int i = 0; i < LPD_M; i++) {
      lsp[i] = w0 * lsp0[i] + w1 * lsp1[i];
    }
    lsp_to_a(lsp, a[k]);
    lpd_stabilise_a(a[k]);
  }
  lpd_lsp_to_a(lsp1, a[n]);
  lpd_stabilise_a(a[n]);
}

float lpd_stab_fac(const float* lsf, const float* lsf_old) {
  float d = 0.0f;
  for (int i = 0; i < LPD_M; i++) {
    d += (lsf[i] - lsf_old[i]) * (lsf[i] - lsf_old[i]);
  }
  const float s = 1.25f - d / 400000.0f;
  return s > 1.0f ? 1.0f : (s < 0.0f ? 0.0f : s);
}

/* ── filters ────────────────────────────────────────────────────── */

namespace {

/* Whether 1 / A(z) is stable: the step-down recursion finds every
 * reflection coefficient inside the unit interval. */
bool syn_filter_stable(const float* a) {
  double c[LPD_M + 1], t[LPD_M + 1];
  for (int i = 1; i <= LPD_M; i++) {
    c[i] = a[i];
  }
  for (int m = LPD_M; m >= 1; m--) {
    const double k = c[m];
    if (!(fabs(k) < 1.0)) {
      return false;
    }
    const double d = 1.0 - k * k;
    for (int i = 1; i < m; i++) {
      t[i] = (c[i] - k * c[m - i]) / d;
    }
    for (int i = 1; i < m; i++) {
      c[i] = t[i];
    }
  }
  return true;
}

}  // namespace

/* Ordered LSFs give a stable filter in exact arithmetic, but a corrupt
 * set packed at the minimum spacing places its poles so close together
 * that rounding the coefficients to float moves some outside the unit
 * circle, and the synthesis then grows past the float range within a
 * frame. Each pass moves every pole 1 % toward the origin; a filter
 * still unstable after 64 passes becomes flat. A conforming
 * stream's filters are stable and pass unchanged. */
void lpd_stabilise_a(float* a) {
  for (int pass = 0; pass < 64; pass++) {
    if (syn_filter_stable(a)) {
      return;
    }
    double g = 1.0;
    for (int i = 1; i <= LPD_M; i++) {
      g *= 0.99;
      a[i] = (float)(g * a[i]);
    }
  }
  if (!syn_filter_stable(a)) {
    memset(a + 1, 0, LPD_M * sizeof(float));
  }
}

void lpd_syn_filter(const float* a, float* x, int n) {
  for (int i = 0; i < n; i++) {
    float s = x[i];
    for (int j = 1; j <= LPD_M; j++) {
      s -= a[j] * x[i - j];
    }
    x[i] = s;
  }
}

void lpd_res_filter(const float* a, const float* in, float* out, int n) {
  for (int i = 0; i < n; i++) {
    float s = in[i];
    for (int j = 1; j <= LPD_M; j++) {
      s += a[j] * in[i - j];
    }
    out[i] = s;
  }
}

void lpd_preemph(float* x, int n, float mem) {
  for (int i = n - 1; i > 0; i--) {
    x[i] -= LPD_PREEMPH * x[i - 1];
  }
  x[0] -= LPD_PREEMPH * mem;
}

void lpd_deemph(float* x, int n, float mem) {
  x[0] += LPD_PREEMPH * mem;
  for (int i = 1; i < n; i++) {
    x[i] += LPD_PREEMPH * x[i - 1];
  }
}

/* ── the algebraic vector quantiser (7.13.4.3) ──────────────────── */

namespace {

/* The last index i < n with table[i] <= value (the tables ascend). */
int last_at_most(const uint32_t* table, int n, uint32_t value) {
  int i = 0;
  while (i + 1 < n && table[i + 1] <= value) {
    i++;
  }
  return i;
}

/* The number of distinct orderings of a multiset with the given counts. */
uint64_t orderings(const int* w, int classes) {
  int total = 0;
  for (int j = 0; j < classes; j++) {
    total += w[j];
  }
  uint64_t r = 1;
  int k = 1;
  for (int j = 0; j < classes; j++) {
    for (int m = 1; m <= w[j]; m++, k++) {
      r = r * (uint64_t)k / (uint64_t)m;
    }
  }
  (void)total;
  return r;
}

/* The lattice point of codebook qn (2 .. 4) with base index `base`: the
 * absolute leader, its sign class, and the permutation of the given
 * rank among the orderings of the signed leader. */
void base_point(int qn, uint32_t base, int* y) {
  int leader;
  if (qn == 4) {
    leader = lpd_avq_q4_leader[last_at_most(lpd_avq_q4_offset, LPD_AVQ_Q4_LEADERS, base)];
  } else {
    leader = lpd_avq_q3_leader[last_at_most(lpd_avq_q3_offset, LPD_AVQ_Q3_LEADERS, base)];
  }
  const int first = lpd_avq_sign_index[leader];
  const int cls = first + last_at_most(lpd_avq_sign_first + first, lpd_avq_sign_num[leader], base);
  const unsigned signs = lpd_avq_sign_data[cls];
  int signed_leader[8];
  for (int i = 0; i < 8; i++) {
    const int v = lpd_avq_leaders[leader][i];
    signed_leader[i] = (signs >> (7 - i)) & 1u ? -v : v;
  }
  uint32_t rank = base - lpd_avq_sign_first[cls];
  /* the distinct values in order of appearance, with their counts */
  int value[8], w[8], classes = 0;
  for (int i = 0; i < 8; i++) {
    if (i > 0 && signed_leader[i] == signed_leader[i - 1]) {
      w[classes - 1]++;
    } else {
      value[classes] = signed_leader[i];
      w[classes] = 1;
      classes++;
    }
  }
  for (int i = 0; i < 8; i++) {
    int j = 0;
    for (;; j++) {
      if (w[j] == 0) {
        continue;
      }
      w[j]--;
      const uint64_t count = orderings(w, classes);
      if (rank < count) {
        break;
      }
      rank -= (uint32_t)count;
      w[j]++;
    }
    y[i] = value[j];
  }
}

/* The nearest point of 2 D8 (even integers with a sum that is a multiple
 * of four) to the point z + r / m, in the integer arithmetic of the
 * standard's reference: every coordinate is taken to the even integer at
 * or above an integer part at or above zero (below for a negative odd
 * one), and when the sum is off, the coordinate with the largest
 * remainder moves by two toward its point.  r is replaced by the
 * remainders toward y, in units of 1 / m. */
void nearest_2d8(const int* z, int* r, int m, int* y) {
  int sum = 0;
  for (int i = 0; i < 8; i++) {
    if (z[i] % 2 == 0) {
      y[i] = z[i];
    } else if (z[i] > 0) {
      y[i] = z[i] + 1;
      r[i] -= m;
    } else {
      y[i] = z[i] - 1;
      r[i] = m - r[i];
    }
    sum += y[i];
  }
  if (sum % 4 != 0) {
    int j = 0, worst = -1;
    for (int i = 0; i < 8; i++) {
      const int e = r[i] < 0 ? -r[i] : r[i];
      if (e > worst) {
        worst = e;
        j = i;
      }
    }
    if (r[j] < 0) {
      y[j] -= 2;
      r[j] += 2 * m;
    } else {
      y[j] += 2;
      r[j] -= 2 * m;
    }
  }
}

/* The nearest point of RE8 = 2 D8 + (2 D8 + 1) to z + r / m: the better
 * of the two cosets' points. */
void nearest_re8(const int* z, const int* r, int m, int* y) {
  int r0[8], r1[8], z1[8], y0[8], y1[8];
  memcpy(r0, r, sizeof(r0));
  memcpy(r1, r, sizeof(r1));
  nearest_2d8(z, r0, m, y0);
  for (int i = 0; i < 8; i++) {
    if (z[i] != 0) {
      z1[i] = z[i] - 1;
    } else if (r1[i] == 0) {
      z1[i] = -1;
    } else {
      z1[i] = 0;
      r1[i] -= m;
    }
  }
  nearest_2d8(z1, r1, m, y1);
  int64_t e0 = 0, e1 = 0;
  for (int i = 0; i < 8; i++) {
    y1[i] += 1;
    e0 += (int64_t)r0[i] * r0[i];
    e1 += (int64_t)r1[i] * r1[i];
  }
  memcpy(y, e0 < e1 ? y0 : y1, sizeof(y0));
}

}  // namespace

void lpd_avq_decode(int qn, uint32_t base, const int* kv, int* y) {
  if (qn < 2) {
    memset(y, 0, 8 * sizeof(int));
    return;
  }
  if (qn <= 4) {
    base_point(qn, base, y);
    return;
  }
  /* Voronoi extension: the base codebook scaled by m = 2^r with the
   * Voronoi index kv unranked through the generator of RE8 and reduced
   * into the Voronoi region around the origin. */
  int r = 0;
  while (qn > 4) {
    r++;
    qn -= 2;
  }
  const int m = 1 << r;
  int b[8], z[8], zq[8], rem[8], v[8];
  base_point(qn, base, b);
  int sum = 0;
  for (int i = 1; i <= 6; i++) {
    z[i] = kv[7] + 2 * kv[i];
    sum += 2 * kv[i];
  }
  z[7] = kv[7];
  z[0] = kv[7] + 4 * kv[0] + sum;
  /* the point (z - 2 e0) / m as integer parts and remainders */
  for (int i = 0; i < 8; i++) {
    const int t = z[i] - (i == 0 ? 2 : 0);
    zq[i] = t >> r;
    rem[i] = i == 0 ? t % m : t & (m - 1);
  }
  nearest_re8(zq, rem, m, v);
  for (int i = 0; i < 8; i++) {
    y[i] = m * b[i] + z[i] - m * v[i];
  }
}

int lpd_read_avq(AacBitReader* r, int nk_mode, int* y) {
  int qn[2];
  if (nk_mode == 1) {
    for (int k = 0; k < 2; k++) {
      int n = 0;
      while (aac_bitreader_read(r, 1) == 1u) {
        n++;
        if (n > 40) {
          return AAC_ERR_DECODE;
        }
      }
      qn[k] = n > 0 ? n + 1 : 0;
    }
  } else {
    for (int k = 0; k < 2; k++) {
      qn[k] = 2 + (int)aac_bitreader_read(r, 2);
    }
    for (int k = 0; k < 2; k++) {
      if (qn[k] <= 4) {
        continue;
      }
      int ext = 0;
      while (aac_bitreader_read(r, 1) == 1u) {
        ext++;
        if (ext > 40) {
          return AAC_ERR_DECODE;
        }
      }
      if (nk_mode == 2) {
        qn[k] = ext > 0 ? ext + 4 : 0;
      } else {
        qn[k] = ext == 0 ? 5 : (ext == 1 ? 6 : (ext == 2 ? 0 : ext + 4));
      }
    }
  }
  for (int k = 0; k < 2; k++) {
    const int ret = lpd_read_avq_block(r, qn[k], y + 8 * k);
    if (ret != AAC_OK) {
      return ret;
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

int lpd_read_avq_block(AacBitReader* r, int qn, int* y) {
  int kv[8] = {0, 0, 0, 0, 0, 0, 0, 0};
  uint32_t base = 0;
  if (qn > 0) {
    const int nk = qn > 4 ? (qn - 3) / 2 : 0;
    const int n = qn - 2 * nk;
    if (n > 8 || nk > 8) {
      return AAC_ERR_DECODE;
    }
    base = aac_bitreader_read(r, 4 * n);
    for (int i = 0; i < 8; i++) {
      kv[i] = (int)aac_bitreader_read(r, nk);
    }
  }
  lpd_avq_decode(qn, base, kv, y);
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* ── lpc_data() (6.2.7.5, 7.13.3) ───────────────────────────────── */

namespace {

/* Reads one quantised set: the first stage index when absolute and the
 * residual in the given code. */
int read_set(AacBitReader* r, LpdLpcSet* s, int absolute, int nk_mode, int residual) {
  s->present = 1;
  s->abs_index = absolute ? (int)aac_bitreader_read(r, 8) : 0;
  s->has_residual = residual;
  if (!residual) {
    memset(s->res, 0, sizeof(s->res));
    return AAC_OK;
  }
  return lpd_read_avq(r, nk_mode, s->res);
}

}  // namespace

int lpd_read_lpc_data(AacBitReader* r, LpdFrameData* f) {
  int ret;
  memset(f->lpc, 0, sizeof(f->lpc));
  /* lpc4: always absolute */
  f->lpc[4].mode = 0;
  if ((ret = read_set(r, &f->lpc[4], 1, 0, 1)) != AAC_OK) {
    return ret;
  }
  if (f->first_lpd_flag) {
    /* lpc0: absolute, or relative to lpc4 */
    const int mode = (int)aac_bitreader_read(r, 1);
    f->lpc[0].mode = mode;
    if ((ret = read_set(r, &f->lpc[0], mode == 0, 0, 1)) != AAC_OK) {
      return ret;
    }
  }
  if (f->mod[0] < 3) {
    /* lpc2: absolute, or relative to lpc4 */
    const int mode = (int)aac_bitreader_read(r, 1);
    f->lpc[2].mode = mode;
    if ((ret = read_set(r, &f->lpc[2], mode == 0, 0, 1)) != AAC_OK) {
      return ret;
    }
  }
  if (f->mod[0] < 2) {
    /* lpc1: 0 relative to lpc2 (mode 2); 10 absolute; 11 the mean of
     * lpc0 and lpc2 without a residual (mode 1) */
    int mode, nk_mode;
    if (aac_bitreader_read(r, 1) == 0u) {
      mode = 2;
      nk_mode = 2;
    } else {
      mode = (int)aac_bitreader_read(r, 1);
      nk_mode = mode;
    }
    f->lpc[1].mode = mode;
    if ((ret = read_set(r, &f->lpc[1], mode == 0, nk_mode, mode != 1)) != AAC_OK) {
      return ret;
    }
  }
  if (f->mod[2] < 2) {
    /* lpc3: 0 the mean of lpc2 and lpc4 plus a residual (mode 1); 10
     * absolute; 110 relative to lpc2 (mode 2); 111 relative to lpc4
     * (mode 3) */
    int mode, nk_mode;
    if (aac_bitreader_read(r, 1) == 0u) {
      mode = 1;
      nk_mode = 1;
    } else if (aac_bitreader_read(r, 1) == 0u) {
      mode = 0;
      nk_mode = 0;
    } else {
      mode = 2 + (int)aac_bitreader_read(r, 1);
      nk_mode = 2;
    }
    f->lpc[3].mode = mode;
    if ((ret = read_set(r, &f->lpc[3], mode == 0, nk_mode, 1)) != AAC_OK) {
      return ret;
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

namespace {

/* The weights of the second stage: proportional to the geometric mean of
 * the distances to the neighbouring LSFs of the first approximation,
 * with the factor of the prediction mode (60 absolute, 65 the mean of the
 * neighbours, 64 relative to lpc2, 63 relative to lpc4). */
void stage2_weights(const float* lsf, double factor, double* w) {
  double d[LPD_M + 1];
  d[0] = lsf[0];
  d[LPD_M] = LPD_LSF_MAX - (double)lsf[LPD_M - 1];
  for (int i = 1; i < LPD_M; i++) {
    d[i] = (double)lsf[i] - lsf[i - 1];
  }
  for (int i = 0; i < LPD_M; i++) {
    /* a prediction outside the scale (corrupt input) gives a negative
     * distance; its weight is then zero rather than undefined */
    const double p = d[i] * d[i + 1];
    w[i] = p > 0.0 ? factor * sqrt(p) / 400.0 : 0.0;
  }
}

void reorder_up(float* lsf) {
  float lo = LPD_LSF_GAP;
  for (int i = 0; i < LPD_M; i++) {
    if (lsf[i] < lo) {
      lsf[i] = lo;
    }
    lo = lsf[i] + LPD_LSF_GAP;
  }
}

void reorder_down(float* lsf) {
  float hi = LPD_LSF_MAX - LPD_LSF_GAP;
  for (int i = LPD_M - 1; i >= 0; i--) {
    if (lsf[i] > hi) {
      lsf[i] = hi;
    }
    hi = lsf[i] - LPD_LSF_GAP;
  }
}

/* An absolute set: the codevector plus the weighted residual, kept apart
 * from both ends. */
void decode_abs(const LpdLpcSet* s, float* lsf) {
  double w[LPD_M];
  memcpy(lsf, lpd_lsf_dico[s->abs_index & 255], sizeof(float) * LPD_M);
  stage2_weights(lsf, 60.0, w);
  for (int i = 0; i < LPD_M; i++) {
    lsf[i] = (float)(lsf[i] + w[i] * s->res[i]);
  }
  reorder_up(lsf);
  reorder_down(lsf);
}

/* A relative set: lsf holds the prediction and gets the weighted
 * residual, kept ascending and, like an absolute set, apart from both
 * ends: a conforming stream never leaves the scale, a corrupt one must
 * not drift out of it from frame to frame. */
void decode_rel(const LpdLpcSet* s, double factor, float* lsf) {
  double w[LPD_M];
  stage2_weights(lsf, factor, w);
  for (int i = 0; i < LPD_M; i++) {
    lsf[i] = (float)(lsf[i] + w[i] * s->res[i]);
  }
  reorder_up(lsf);
  reorder_down(lsf);
}

}  // namespace

void lpd_lsf_decode(const AacUsacLpdChannel* c, const LpdFrameData* f, float lsf[5][LPD_M]) {
  memset(lsf, 0, 5 * LPD_M * sizeof(float));
  decode_abs(&f->lpc[4], lsf[4]);
  if (f->first_lpd_flag) {
    if (f->lpc[0].mode == 0) {
      decode_abs(&f->lpc[0], lsf[0]);
    } else {
      memcpy(lsf[0], lsf[4], sizeof(lsf[0]));
      decode_rel(&f->lpc[0], 63.0, lsf[0]);
    }
  } else {
    lpd_lsp_to_lsf(c->lsp_prev, lsf[0]);
  }
  if (f->lpc[2].present) {
    if (f->lpc[2].mode == 0) {
      decode_abs(&f->lpc[2], lsf[2]);
    } else {
      memcpy(lsf[2], lsf[4], sizeof(lsf[2]));
      decode_rel(&f->lpc[2], 63.0, lsf[2]);
    }
  }
  if (f->lpc[1].present) {
    switch (f->lpc[1].mode) {
      case 0:
        decode_abs(&f->lpc[1], lsf[1]);
        break;
      case 1:
        for (int i = 0; i < LPD_M; i++) {
          lsf[1][i] = 0.5f * (lsf[0][i] + lsf[2][i]);
        }
        break;
      default:
        memcpy(lsf[1], lsf[2], sizeof(lsf[1]));
        decode_rel(&f->lpc[1], 64.0, lsf[1]);
        break;
    }
  }
  if (f->lpc[3].present) {
    switch (f->lpc[3].mode) {
      case 0:
        decode_abs(&f->lpc[3], lsf[3]);
        break;
      case 1:
        for (int i = 0; i < LPD_M; i++) {
          lsf[3][i] = 0.5f * (lsf[2][i] + lsf[4][i]);
        }
        decode_rel(&f->lpc[3], 65.0, lsf[3]);
        break;
      case 2:
        memcpy(lsf[3], lsf[2], sizeof(lsf[3]));
        decode_rel(&f->lpc[3], 64.0, lsf[3]);
        break;
      default:
        memcpy(lsf[3], lsf[4], sizeof(lsf[3]));
        decode_rel(&f->lpc[3], 64.0, lsf[3]);
        break;
    }
  }
}
