/* LPC filter sets of the USAC LPD core (ISO/IEC 23003-3 6.2.7.4,
 * 7.13): the lpc_data() syntax with its five filter sets per superframe,
 * the two stage LSF quantiser (first stage vector codebook, second stage
 * algebraic vector quantiser on the RE8 lattice with Voronoi extension),
 * the LSF to LPC conversion, the interpolation between filter sets, the
 * weighting A(z / gamma) and the synthesis and analysis filters. */
#include <cmath>
#include <cstring>

#include "usac_lpd_internal.h"

/* ── LSF, LSP and LPC conversions ───────────────────────────────── */

void lpd_lsf_to_lsp(const float* lsf, float* lsp) {
  for (int i = 0; i < LPD_M; i++) {
    lsp[i] = cosf(lsf[i] * ((float)M_PI / LPD_LSF_MAX));
  }
}

void lpd_lsp_to_lsf(const float* lsp, float* lsf) {
  for (int i = 0; i < LPD_M; i++) {
    float v = lsp[i];
    if (v > 1.0f) {
      v = 1.0f;
    } else if (v < -1.0f) {
      v = -1.0f;
    }
    lsf[i] = acosf(v) * (LPD_LSF_MAX / (float)M_PI);
  }
}

/* Expands the symmetric polynomial prod_i (1 - 2 lsp_i z^-1 + z^-2) over
 * the `n` given line spectral pairs: f[0 .. n] are the first half of its
 * 2n + 1 coefficients (f[0] = 1), the rest mirror them. */
static void lsp_poly(const float* lsp, int n, double* f) {
  f[0] = 1.0;
  f[1] = -2.0 * lsp[0];
  for (int i = 2; i <= n; i++) {
    const double b = -2.0 * lsp[i - 1];
    f[i] = 2.0 * f[i - 2] + b * f[i - 1];
    for (int j = i - 1; j > 1; j--) {
      f[j] += b * f[j - 1] + f[j - 2];
    }
    f[1] += b;
  }
}

/* LSP (cosine domain, descending) to the prediction filter A(z) =
 * 1 + sum_{i=1}^{16} a[i] z^-i (7.13.5): A(z) = (F1(z)(1 + z^-1) +
 * F2(z)(1 - z^-1)) / 2 with F1 over the even, F2 over the odd pairs. */
void lpd_lsp_to_a(const float* lsp, float* a) {
  float even[LPD_M / 2], odd[LPD_M / 2];
  for (int i = 0; i < LPD_M / 2; i++) {
    even[i] = lsp[2 * i];
    odd[i] = lsp[2 * i + 1];
  }
  double h1[LPD_M / 2 + 1], h2[LPD_M / 2 + 1];
  lsp_poly(even, LPD_M / 2, h1);
  lsp_poly(odd, LPD_M / 2, h2);
  double f1[LPD_M + 2], f2[LPD_M + 2];
  for (int i = 0; i <= LPD_M; i++) {
    const int k = i <= LPD_M / 2 ? i : LPD_M - i;
    f1[i] = h1[k];
    f2[i] = h2[k];
  }
  f1[LPD_M + 1] = 0.0;
  f2[LPD_M + 1] = 0.0;
  a[0] = 1.0f;
  for (int i = 1; i <= LPD_M; i++) {
    a[i] = (float)(0.5 * ((f1[i] + f1[i - 1]) + (f2[i] - f2[i - 1])));
  }
}

/* A(z / gamma): a[i] gamma^i. */
void lpd_weight_a(const float* a, float gamma, float* ap) {
  float g = 1.0f;
  for (int i = 0; i <= LPD_M; i++) {
    ap[i] = a[i] * g;
    g *= gamma;
  }
}

/* A(z) of the filter interpolated between two LSP sets at fraction
 * `frac` of the way from lsp0 to lsp1 (7.14.1). */
void lpd_interp_lsp(const float* lsp0, const float* lsp1, float frac, float* a) {
  float lsp[LPD_M];
  for (int i = 0; i < LPD_M; i++) {
    lsp[i] = (1.0f - frac) * lsp0[i] + frac * lsp1[i];
  }
  lpd_lsp_to_a(lsp, a);
}

/* Stability factor of the LSF change, 0 (unstable) to 1 (7.14.6). */
float lpd_stab_fac(const float* lsf, const float* lsf_old) {
  float d = 0.0f;
  for (int i = 0; i < LPD_M; i++) {
    const float t = lsf[i] - lsf_old[i];
    d += t * t;
  }
  float s = 1.25f - d / 400000.0f;
  if (s > 1.0f) {
    s = 1.0f;
  } else if (s < 0.0f) {
    s = 0.0f;
  }
  return s;
}

/* Synthesis 1 / A(z): out[n] = in[n] - sum a[i] out[n - i]; mem[i] holds
 * out[-1 - i] on entry and the last outputs on return.  in and out may
 * alias; any length. */
void lpd_syn_filter(const float* a, const float* in, float* out, int n, float* mem) {
  for (int k = 0; k < n; k++) {
    float s = in[k];
    for (int i = 1; i <= LPD_M; i++) {
      s -= a[i] * mem[i - 1];
    }
    for (int i = LPD_M - 1; i > 0; i--) {
      mem[i] = mem[i - 1];
    }
    mem[0] = s;
    out[k] = s;
  }
}

/* Analysis A(z): out[n] = in[n] + sum a[i] in[n - i] with mem[i] =
 * in[-1 - i]. */
void lpd_res_filter(const float* a, const float* in, float* out, int n, const float* mem) {
  for (int k = 0; k < n; k++) {
    float s = in[k];
    for (int i = 1; i <= LPD_M; i++) {
      const int j = k - i;
      s += a[i] * (j >= 0 ? in[j] : mem[-1 - j]);
    }
    out[k] = s;
  }
}

/* ── algebraic vector quantiser (7.13.4) ────────────────────────── */

/* Codebook number qn of one 8-dimensional block.  The code depends on the
 * prediction mode of the filter set (nk_mode): the mid prediction uses a
 * unary code, the relative modes two bits with an offset of 2, the
 * absolute and lpc4-relative modes two bits with a unary escape.  Q1 does
 * not exist, so the codes skip the value 1. */
static int read_qn(AacBitReader* r, int nk_mode) {
  if (nk_mode == 1) {
    int u = 0;
    while (u < 16 && aac_bitreader_read(r, 1)) {
      u++;
    }
    return u == 0 ? 0 : u + 1;
  }
  int v = (int)aac_bitreader_read(r, 2);
  if (nk_mode == 2) {
    return v + 2;
  }
  if (v == 0) {
    return 0;
  }
  if (v < 3) {
    return v + 1;
  }
  int u = 0;
  while (u < 16 && aac_bitreader_read(r, 1)) {
    u++;
  }
  return 4 + u;
}

int lpd_read_avq_block(AacBitReader* r, int nk_mode, LpdAvqBlock* b) {
  memset(b, 0, sizeof(*b));
  b->qn = read_qn(r, nk_mode);
  if (b->qn == 0) {
    return AAC_OK;
  }
  if (b->qn > 16) {
    return AAC_ERR_DECODE;
  }
  const int nb = b->qn == 2 ? 2 : ((b->qn & 1) ? 3 : 4);
  b->base = aac_bitreader_read(r, 4 * nb);
  const int rbits = (b->qn - nb) / 2;
  for (int i = 0; i < 8; i++) {
    b->kv[i] = rbits ? (int)aac_bitreader_read(r, rbits) : 0;
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* Rank `rank` among the permutations of the multiset `leader` (sorted
 * descending), in lexicographic order with the larger value first. */
static void unrank_permutation(const int* leader, int rank, int* out) {
  int vals[8], mult[8], nv = 0;
  for (int i = 0; i < 8; i++) {
    if (nv > 0 && vals[nv - 1] == leader[i]) {
      mult[nv - 1]++;
    } else {
      vals[nv] = leader[i];
      mult[nv] = 1;
      nv++;
    }
  }
  int fact[9] = {1, 1, 2, 6, 24, 120, 720, 5040, 40320};
  for (int pos = 0; pos < 8; pos++) {
    const int remaining = 8 - pos;
    for (int v = 0; v < nv; v++) {
      if (mult[v] == 0) {
        continue;
      }
      /* permutations with vals[v] at this position */
      int count = fact[remaining - 1];
      for (int w = 0; w < nv; w++) {
        count /= fact[mult[w] - (w == v ? 1 : 0)];
      }
      if (rank < count) {
        out[pos] = vals[v];
        mult[v]--;
        break;
      }
      rank -= count;
    }
  }
}

/* The codevector of index `index` in Q`nb` (nb = 2, 3 or 4). */
static void avq_base_decode(int nb, uint32_t index, int* y) {
  const int n = lpd_avq_num_leaders(nb);
  int k = 0;
  for (int i = 0; i < n; i++) {
    if ((int)index >= lpd_avq_leader_offset(nb, i)) {
      k = i;
    }
  }
  const int* leader = lpd_avq_leader(nb, k);
  int rank = (int)index - lpd_avq_leader_offset(nb, k);
  if (rank >= lpd_avq_leader_count(nb, k)) {
    rank = 0;
  }
  const int odd = leader[0] & 1;
  int nonzero = 0;
  for (int i = 0; i < 8; i++) {
    nonzero += leader[i] != 0;
  }
  const int ns = odd ? 7 : nonzero;
  const int sign_rank = rank & ((1 << ns) - 1);
  unrank_permutation(leader, rank >> ns, y);
  int bit = ns - 1;
  int sum = 0;
  for (int i = 0; i < 8; i++) {
    if (y[i] != 0 && bit >= 0) {
      if ((sign_rank >> bit) & 1) {
        y[i] = -y[i];
      }
      bit--;
    }
    sum += y[i];
  }
  if (odd) {
    /* the last component's sign makes the sum a multiple of 4 */
    if (((sum % 4) + 4) % 4 != 0) {
      y[7] = -y[7];
    }
  }
}

/* Nearest point of RE8 to the real vector x (7.13.4.3): the nearest in
 * 2 D8 and in 2 D8 + 1, whichever is closer. */
static void re8_nearest(const double* x, int* y) {
  int cand[2][8];
  double err[2] = {0.0, 0.0};
  for (int t = 0; t < 2; t++) {
    const double off = t ? 1.0 : 0.0;
    int sum = 0;
    int worst = 0;
    double worst_err = -1.0;
    for (int i = 0; i < 8; i++) {
      const double v = (x[i] - off) * 0.5;
      const int q = (int)floor(v + 0.5);
      cand[t][i] = q;
      sum += q;
      const double e = fabs(v - (double)q);
      if (e > worst_err) {
        worst_err = e;
        worst = i;
      }
    }
    if (sum & 1) {
      const double v = (x[worst] - off) * 0.5;
      cand[t][worst] += v > (double)cand[t][worst] ? 1 : -1;
    }
    for (int i = 0; i < 8; i++) {
      cand[t][i] = 2 * cand[t][i] + (int)off;
      const double d = x[i] - (double)cand[t][i];
      err[t] += d * d;
    }
  }
  memcpy(y, cand[err[1] < err[0] ? 1 : 0], sizeof(int) * 8);
}

void lpd_avq_decode(const LpdAvqBlock* b, int* y) {
  if (b->qn == 0) {
    memset(y, 0, sizeof(int) * 8);
    return;
  }
  const int nb = b->qn == 2 ? 2 : ((b->qn & 1) ? 3 : 4);
  int c[8];
  avq_base_decode(nb, b->base, c);
  const int r = (b->qn - nb) / 2;
  if (r == 0) {
    memcpy(y, c, sizeof(int) * 8);
    return;
  }
  const int m = 1 << r;
  /* Voronoi extension: z = k G, v = z - m nearest(z / m), y = m c + v */
  int z[8];
  double zm[8];
  for (int j = 0; j < 8; j++) {
    int s = 0;
    for (int i = 0; i < 8; i++) {
      s += b->kv[i] * lpd_re8_gen[i][j];
    }
    z[j] = s;
    zm[j] = (double)s / (double)m;
  }
  int near[8];
  re8_nearest(zm, near);
  for (int j = 0; j < 8; j++) {
    y[j] = m * c[j] + (z[j] - m * near[j]);
  }
}

/* ── lpc_data() ─────────────────────────────────────────────────── */

/* nk_mode of the second stage per filter set and prediction mode. */
static int nk_mode_of(int mode) {
  switch (mode) {
    case 0:
      return 0; /* absolute */
    case 1:
      return 3; /* relative to lpc4 */
    case 2:
      return 2; /* relative to lpc2 (or lpc4 for lpc3) */
    default:
      return 1; /* mid of the neighbours */
  }
}

static int read_lpc_set(AacBitReader* r, LpdLpcSet* s, int mode) {
  s->present = 1;
  s->mode = mode;
  if (mode == 0) {
    s->abs_index = (int)aac_bitreader_read(r, 8);
  }
  const int nk = nk_mode_of(mode);
  for (int k = 0; k < 2; k++) {
    const int err = lpd_read_avq_block(r, nk, &s->blk[k]);
    if (err) {
      return err;
    }
  }
  return AAC_OK;
}

/* Table 6.2.7.4: lpc4 first, then lpc0 (first LPD frame only), lpc2 (not
 * for a TCX80 superframe), lpc1 (when the first half has two subframes)
 * and lpc3 (when the second half has). */
int lpd_read_lpc_data(AacBitReader* r, LpdFrameData* f) {
  for (int i = 0; i < 5; i++) {
    f->lpc[i].present = 0;
  }
  int err = read_lpc_set(r, &f->lpc[4], 0);
  if (err) {
    return err;
  }
  if (f->first_lpd_flag) {
    const int mode_lpc = (int)aac_bitreader_read(r, 1);
    err = read_lpc_set(r, &f->lpc[0], mode_lpc ? 1 : 0);
    if (err) {
      return err;
    }
  }
  if (f->mod[0] < 3) {
    const int mode_lpc = (int)aac_bitreader_read(r, 1);
    err = read_lpc_set(r, &f->lpc[2], mode_lpc ? 1 : 0);
    if (err) {
      return err;
    }
  }
  if (f->mod[0] < 2) {
    int mode;
    if (aac_bitreader_read(r, 1) == 0) {
      mode = 3; /* mid of lpc0 and lpc2 */
    } else {
      mode = aac_bitreader_read(r, 1) ? 2 : 0; /* relative to lpc2, or absolute */
    }
    err = read_lpc_set(r, &f->lpc[1], mode);
    if (err) {
      return err;
    }
  }
  if (f->mod[2] < 2) {
    int mode;
    if (aac_bitreader_read(r, 1) == 0) {
      mode = 3; /* mid of lpc2 and lpc4 */
    } else if (aac_bitreader_read(r, 1) == 0) {
      mode = 0;
    } else {
      mode = aac_bitreader_read(r, 1) ? 1 : 2; /* relative to lpc4, or to lpc2 */
    }
    err = read_lpc_set(r, &f->lpc[3], mode);
    if (err) {
      return err;
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* Weights of the second stage residual from the spacing of the
 * reference vector (7.13.3): w[i] = sqrt(d[i] d[i+1]) / scale. */
static void lsf_weights(const float* ref, float scale, float* w) {
  float d[LPD_M + 1];
  d[0] = ref[0];
  for (int i = 1; i < LPD_M; i++) {
    d[i] = ref[i] - ref[i - 1];
  }
  d[LPD_M] = LPD_LSF_MAX - ref[LPD_M - 1];
  for (int i = 0; i <= LPD_M; i++) {
    if (d[i] < 1.0f) {
      d[i] = 1.0f;
    }
  }
  for (int i = 0; i < LPD_M; i++) {
    w[i] = sqrtf(d[i] * d[i + 1]) / scale;
  }
}

/* Keeps the LSFs ascending with a minimum gap, inside the band. */
static void lsf_reorder(float* lsf, float min_gap) {
  float lo = min_gap;
  for (int i = 0; i < LPD_M; i++) {
    if (lsf[i] < lo) {
      lsf[i] = lo;
    }
    lo = lsf[i] + min_gap;
  }
  float hi = LPD_LSF_MAX - min_gap;
  for (int i = LPD_M - 1; i >= 0; i--) {
    if (lsf[i] > hi) {
      lsf[i] = hi;
    }
    hi = lsf[i] - min_gap;
  }
}

static void decode_set(const LpdLpcSet* s, const float* ref_a, const float* ref_b, float* lsf) {
  float ref[LPD_M];
  float scale;
  if (s->mode == 0) {
    lpd_lsf_first_stage(s->abs_index, ref);
    scale = 60.0f;
  } else if (s->mode == 3) {
    for (int i = 0; i < LPD_M; i++) {
      ref[i] = 0.5f * (ref_a[i] + ref_b[i]);
    }
    scale = 65.0f;
  } else {
    memcpy(ref, ref_a, sizeof(ref));
    scale = 64.0f;
  }
  float w[LPD_M];
  lsf_weights(ref, scale, w);
  int y[LPD_M];
  lpd_avq_decode(&s->blk[0], y);
  lpd_avq_decode(&s->blk[1], y + 8);
  for (int i = 0; i < LPD_M; i++) {
    lsf[i] = ref[i] + w[i] * (float)y[i];
  }
  lsf_reorder(lsf, 50.0f);
}

/* Decodes the five filter sets of the frame into lsf[0..4]; a set that is
 * not transmitted is the neighbour it is defined by (lpc0 of a continued
 * LPD frame is the previous lpc4; a filter inside a long TCX frame is
 * not needed and is set to the frame end's). */
void lpd_lsf_decode(AacUsacLpdChannel* c, const LpdFrameData* f, float lsf[5][LPD_M]) {
  /* lpc4 is absolute */
  decode_set(&f->lpc[4], nullptr, nullptr, lsf[4]);
  if (f->lpc[0].present) {
    decode_set(&f->lpc[0], lsf[4], nullptr, lsf[0]);
  } else if (c->have_lsf_prev) {
    memcpy(lsf[0], c->lsf_prev, sizeof(lsf[0]));
  } else {
    memcpy(lsf[0], lsf[4], sizeof(lsf[0]));
  }
  if (f->lpc[2].present) {
    decode_set(&f->lpc[2], lsf[4], nullptr, lsf[2]);
  } else {
    memcpy(lsf[2], lsf[4], sizeof(lsf[2]));
  }
  if (f->lpc[1].present) {
    const LpdLpcSet* s = &f->lpc[1];
    decode_set(s, s->mode == 3 ? lsf[0] : lsf[2], lsf[2], lsf[1]);
  } else {
    memcpy(lsf[1], lsf[2], sizeof(lsf[1]));
  }
  if (f->lpc[3].present) {
    const LpdLpcSet* s = &f->lpc[3];
    decode_set(s, s->mode == 1 ? lsf[4] : lsf[2], lsf[4], lsf[3]);
  } else {
    memcpy(lsf[3], lsf[4], sizeof(lsf[3]));
  }
}
