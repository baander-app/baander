#include "sbr_ld.h"

#include <algorithm>
#include <cfloat>
#include <cmath>
#include <cstring>

/* Low-delay SBR decoder (ISO/IEC 14496-3 4.6.20).  The tools are those
 * of the SBR decoder in sbr_dec.cpp with the frame reduced to 16 or 15
 * CLDFB slots whose envelope borders count single slots, and with every
 * envelope ending at the frame end: the generator keeps 8 slots of the
 * previous frame for its predictor and the adjuster's smoothing keeps the
 * last four slots of gains, nothing else crosses a frame.  The per-band
 * arithmetic (dequantization, inverse filtering, chirp factors, patching,
 * gain calculation, noise and sinusoid insertion) repeats the SBR
 * decoder's, which does not export it. */

namespace {

constexpr int kHistory = AAC_SBR_LD_HISTORY;
constexpr int kAdj = AAC_SBR_LD_ADJ;
constexpr int kNoiseFloorOffset = 6;
constexpr int kSmoothSlots = 4;

/* ── header (4.6.18.3.1) ───────────────────────────────────────── */

}  // namespace

int aac_sbr_ld_header_read(AacSbrLdHeader* h, AacBitReader* r) {
  h->amp_res = (int)aac_bitreader_read(r, 1);
  h->start_freq = (int)aac_bitreader_read(r, 4);
  h->stop_freq = (int)aac_bitreader_read(r, 4);
  h->xover_band = (int)aac_bitreader_read(r, 3);
  aac_bitreader_skip(r, 2); /* bs_reserved */
  const int extra1 = (int)aac_bitreader_read(r, 1);
  const int extra2 = (int)aac_bitreader_read(r, 1);
  if (extra1) {
    h->freq_scale = (int)aac_bitreader_read(r, 2);
    h->alter_scale = (int)aac_bitreader_read(r, 1);
    h->noise_bands = (int)aac_bitreader_read(r, 2);
  } else {
    h->freq_scale = 2;
    h->alter_scale = 1;
    h->noise_bands = 2;
  }
  if (extra2) {
    h->limiter_bands = (int)aac_bitreader_read(r, 2);
    h->limiter_gains = (int)aac_bitreader_read(r, 2);
    h->interpol_freq = (int)aac_bitreader_read(r, 1);
    h->smoothing_mode = (int)aac_bitreader_read(r, 1);
  } else {
    h->limiter_bands = 2;
    h->limiter_gains = 2;
    h->interpol_freq = 1;
    h->smoothing_mode = 1;
  }
  return r->overread ? AAC_ERR_DECODE : 0;
}

void aac_sbr_ld_header_write(const AacSbrLdHeader* h, AacBitWriter* w) {
  aac_bitwriter_write(w, (uint32_t)h->amp_res, 1);
  aac_bitwriter_write(w, (uint32_t)h->start_freq, 4);
  aac_bitwriter_write(w, (uint32_t)h->stop_freq, 4);
  aac_bitwriter_write(w, (uint32_t)h->xover_band, 3);
  aac_bitwriter_write(w, 0, 2);
  const int extra1 = h->freq_scale != 2 || h->alter_scale != 1 || h->noise_bands != 2;
  const int extra2 = h->limiter_bands != 2 || h->limiter_gains != 2 || h->interpol_freq != 1 ||
                     h->smoothing_mode != 1;
  aac_bitwriter_write(w, (uint32_t)extra1, 1);
  aac_bitwriter_write(w, (uint32_t)extra2, 1);
  if (extra1) {
    aac_bitwriter_write(w, (uint32_t)h->freq_scale, 2);
    aac_bitwriter_write(w, (uint32_t)h->alter_scale, 1);
    aac_bitwriter_write(w, (uint32_t)h->noise_bands, 2);
  }
  if (extra2) {
    aac_bitwriter_write(w, (uint32_t)h->limiter_bands, 2);
    aac_bitwriter_write(w, (uint32_t)h->limiter_gains, 2);
    aac_bitwriter_write(w, (uint32_t)h->interpol_freq, 1);
    aac_bitwriter_write(w, (uint32_t)h->smoothing_mode, 1);
  }
}

/* ── envelope grid ─────────────────────────────────────────────── */

int aac_sbr_ld_make_grid(AacSbrLdGrid* g, int slots, int frame_class, int num_env,
                         int transient_position) {
  memset(g, 0, sizeof(*g));
  g->frame_class = frame_class;
  g->tran_env = -1;
  if (frame_class == AAC_SBR_LD_FRAME_FIXFIX) {
    if (num_env != 1 && num_env != 2 && num_env != 4) {
      return AAC_ERR_DECODE;
    }
    g->num_env = num_env;
    const int step = (slots + (num_env >> 1)) / num_env;
    g->t_env[0] = 0;
    for (int e = 1; e < num_env; e++) {
      g->t_env[e] = g->t_env[e - 1] + step;
    }
    g->t_env[num_env] = slots;
  } else {
    if (transient_position < 0 || transient_position >= slots) {
      return AAC_ERR_DECODE;
    }
    g->transient_position = transient_position;
    /* The transient's envelope starts at the transient and lasts two
     * slots; a leading or trailing part shorter than two slots joins
     * it. */
    const int start = std::min(transient_position, slots - 2);
    const int end = start + 2;
    int n = 0;
    g->t_env[n++] = 0;
    if (start >= 2) {
      g->t_env[n++] = start;
    }
    g->tran_env = n - 1;
    if (slots - end >= 2) {
      g->t_env[n++] = end;
    }
    g->t_env[n] = slots;
    g->num_env = n;
  }
  g->num_noise = (g->num_env > 1) + 1;
  g->t_q[0] = 0;
  g->t_q[g->num_noise] = slots;
  if (g->num_noise > 1) {
    const int idx = g->tran_env > 0 ? g->tran_env : (g->tran_env == 0 ? 1 : g->num_env >> 1);
    g->t_q[1] = g->t_env[idx];
  }
  return 0;
}

namespace {

/* ── bitstream ─────────────────────────────────────────────────── */

int read_grid(const AacSbrLdState* s, AacBitReader* r, AacSbrLdChannel* ch) {
  AacSbrLdGrid* g = &ch->grid;
  ch->prev_freq_res = g->num_env ? g->freq_res[g->num_env - 1] : 1;
  const int frame_class = (int)aac_bitreader_read(r, 1);
  int ret;
  if (frame_class == AAC_SBR_LD_FRAME_FIXFIX) {
    const int num_env = 1 << aac_bitreader_read(r, 2);
    ret = aac_sbr_ld_make_grid(g, s->slots, frame_class, num_env, 0);
    if (ret) {
      return ret;
    }
    const int res = (int)aac_bitreader_read(r, 1);
    for (int e = 0; e < g->num_env; e++) {
      g->freq_res[e] = res;
    }
  } else {
    const int pos = (int)aac_bitreader_read(r, 4);
    ret = aac_sbr_ld_make_grid(g, s->slots, frame_class, 0, pos);
    if (ret) {
      return ret;
    }
    for (int e = 0; e < g->num_env; e++) {
      g->freq_res[e] = (int)aac_bitreader_read(r, 1);
    }
  }
  ch->amp_res = (g->frame_class == AAC_SBR_LD_FRAME_FIXFIX && g->num_env == 1) ? 0
                                                                               : s->header.amp_res;
  return 0;
}

void copy_grid(AacSbrLdChannel* dst, const AacSbrLdChannel* src) {
  dst->prev_freq_res = dst->grid.num_env ? dst->grid.freq_res[dst->grid.num_env - 1] : 1;
  dst->grid = src->grid;
  dst->amp_res = src->amp_res;
}

void read_dtdf(AacBitReader* r, AacSbrLdChannel* ch) {
  for (int i = 0; i < ch->grid.num_env; i++) {
    ch->df_env[i] = (int)aac_bitreader_read(r, 1);
  }
  for (int i = 0; i < ch->grid.num_noise; i++) {
    ch->df_noise[i] = (int)aac_bitreader_read(r, 1);
  }
}

void read_invf(const AacSbrLdState* s, AacBitReader* r, AacSbrLdChannel* ch) {
  memcpy(ch->invf_mode[1], ch->invf_mode[0], sizeof(ch->invf_mode[0]));
  for (int i = 0; i < s->freq.n_q; i++) {
    ch->invf_mode[0][i] = (int)aac_bitreader_read(r, 2);
  }
}

int huff(AacBitReader* r, const int8_t (*tab)[2]) {
  int idx = 0;
  while (idx >= 0) {
    idx = tab[idx][aac_bitreader_read(r, 1)];
    if (r->overread) {
      return 0;
    }
  }
  return idx + 64;
}

/* Band of the previous envelope that band j of an envelope of resolution
 * `res` is delta-coded against when the previous one has `prev_res`. */
int prev_band(int j, int res, int prev_res, int odd) {
  if (res == prev_res) {
    return j;
  }
  if (res) {
    return (j + odd) >> 1;
  }
  return j ? 2 * j - odd : 0;
}

int read_envelope(const AacSbrLdState* s, AacBitReader* r, AacSbrLdChannel* ch, int chan) {
  const int delta = (chan == 1 && s->coupling == 1) + 1;
  const int odd = s->freq.n[1] & 1;
  int bits;
  const int8_t (*t_huff)[2];
  const int8_t (*f_huff)[2];
  if (s->coupling && chan) {
    if (ch->amp_res) {
      bits = 5;
      t_huff = aac_sbr_huff_env_balance_30_t;
      f_huff = aac_sbr_huff_env_balance_30_f;
    } else {
      bits = 6;
      t_huff = aac_sbr_huff_env_balance_15_t;
      f_huff = aac_sbr_huff_env_balance_15_f;
    }
  } else if (ch->amp_res) {
    bits = 6;
    t_huff = aac_sbr_huff_env_level_30_t;
    f_huff = aac_sbr_huff_env_level_30_f;
  } else {
    bits = 7;
    t_huff = aac_sbr_huff_env_level_15_t;
    f_huff = aac_sbr_huff_env_level_15_f;
  }
  const AacSbrLdGrid* g = &ch->grid;
  int prev_res = ch->prev_freq_res;
  for (int i = 0; i < g->num_env; i++) {
    const int res = g->freq_res[i];
    const int n = s->freq.n[res];
    if (ch->df_env[i]) {
      for (int j = 0; j < n; j++) {
        ch->env_facs_q[i + 1][j] =
            ch->env_facs_q[i][prev_band(j, res, prev_res, odd)] + delta * huff(r, t_huff);
      }
    } else {
      ch->env_facs_q[i + 1][0] = delta * (int)aac_bitreader_read(r, bits);
      for (int j = 1; j < n; j++) {
        ch->env_facs_q[i + 1][j] = ch->env_facs_q[i + 1][j - 1] + delta * huff(r, f_huff);
      }
    }
    for (int j = 0; j < n; j++) {
      if (ch->env_facs_q[i + 1][j] < 0 || ch->env_facs_q[i + 1][j] > 127) {
        return AAC_ERR_DECODE;
      }
    }
    prev_res = res;
  }
  memcpy(ch->env_facs_q[0], ch->env_facs_q[g->num_env], sizeof(ch->env_facs_q[0]));
  return 0;
}

int read_noise(const AacSbrLdState* s, AacBitReader* r, AacSbrLdChannel* ch, int chan) {
  const int delta = (chan == 1 && s->coupling == 1) + 1;
  const int8_t (*t_huff)[2];
  const int8_t (*f_huff)[2];
  if (s->coupling && chan) {
    t_huff = aac_sbr_huff_noise_balance_30_t;
    f_huff = aac_sbr_huff_env_balance_30_f;
  } else {
    t_huff = aac_sbr_huff_noise_level_30_t;
    f_huff = aac_sbr_huff_env_level_30_f;
  }
  const int n_q = s->freq.n_q;
  for (int i = 0; i < ch->grid.num_noise; i++) {
    if (ch->df_noise[i]) {
      for (int j = 0; j < n_q; j++) {
        ch->noise_facs_q[i + 1][j] = ch->noise_facs_q[i][j] + delta * huff(r, t_huff);
      }
    } else {
      ch->noise_facs_q[i + 1][0] = delta * (int)aac_bitreader_read(r, 5);
      for (int j = 1; j < n_q; j++) {
        ch->noise_facs_q[i + 1][j] = ch->noise_facs_q[i + 1][j - 1] + delta * huff(r, f_huff);
      }
    }
    for (int j = 0; j < n_q; j++) {
      if (ch->noise_facs_q[i + 1][j] < 0 || ch->noise_facs_q[i + 1][j] > 30) {
        return AAC_ERR_DECODE;
      }
    }
  }
  memcpy(ch->noise_facs_q[0], ch->noise_facs_q[ch->grid.num_noise], sizeof(ch->noise_facs_q[0]));
  return 0;
}

void read_add_harmonic(const AacSbrLdState* s, AacBitReader* r, AacSbrLdChannel* ch) {
  ch->add_harmonic_flag = (int)aac_bitreader_read(r, 1);
  memset(ch->add_harmonic, 0, sizeof(ch->add_harmonic));
  if (ch->add_harmonic_flag) {
    for (int i = 0; i < s->freq.n[1]; i++) {
      ch->add_harmonic[i] = (int)aac_bitreader_read(r, 1);
    }
  }
}

int read_single_channel(AacSbrLdState* s, AacBitReader* r) {
  if (aac_bitreader_read(r, 1)) {
    aac_bitreader_skip(r, 4); /* bs_reserved */
  }
  AacSbrLdChannel* ch = &s->ch[0];
  int ret = read_grid(s, r, ch);
  if (ret) {
    return ret;
  }
  read_dtdf(r, ch);
  read_invf(s, r, ch);
  if ((ret = read_envelope(s, r, ch, 0)) || (ret = read_noise(s, r, ch, 0))) {
    return ret;
  }
  read_add_harmonic(s, r, ch);
  return 0;
}

int read_channel_pair(AacSbrLdState* s, AacBitReader* r) {
  if (aac_bitreader_read(r, 1)) {
    aac_bitreader_skip(r, 8); /* bs_reserved */
  }
  AacSbrLdChannel* l = &s->ch[0];
  AacSbrLdChannel* rt = &s->ch[1];
  int ret;
  s->coupling = (int)aac_bitreader_read(r, 1);
  if (s->coupling) {
    if ((ret = read_grid(s, r, l))) {
      return ret;
    }
    copy_grid(rt, l);
    read_dtdf(r, l);
    read_dtdf(r, rt);
    read_invf(s, r, l);
    memcpy(rt->invf_mode[1], rt->invf_mode[0], sizeof(rt->invf_mode[0]));
    memcpy(rt->invf_mode[0], l->invf_mode[0], sizeof(rt->invf_mode[0]));
    if ((ret = read_envelope(s, r, l, 0)) || (ret = read_noise(s, r, l, 0)) ||
        (ret = read_envelope(s, r, rt, 1)) || (ret = read_noise(s, r, rt, 1))) {
      return ret;
    }
  } else {
    if ((ret = read_grid(s, r, l)) || (ret = read_grid(s, r, rt))) {
      return ret;
    }
    read_dtdf(r, l);
    read_dtdf(r, rt);
    read_invf(s, r, l);
    read_invf(s, r, rt);
    if ((ret = read_envelope(s, r, l, 0)) || (ret = read_envelope(s, r, rt, 1)) ||
        (ret = read_noise(s, r, l, 0)) || (ret = read_noise(s, r, rt, 1))) {
      return ret;
    }
  }
  read_add_harmonic(s, r, l);
  read_add_harmonic(s, r, rt);
  return 0;
}

/* ── dequantization (4.6.18.3.5) ───────────────────────────────── */

float exp2i(int x) { return ldexpf(1.0f, x); }

void dequant(AacSbrLdState* s) {
  static const float kExp2Half[2] = {1.0f, 1.41421356237309504880f};
  const int n_q = s->freq.n_q;
  if (s->elem_type == AAC_ELEM_CPE && s->coupling) {
    AacSbrLdChannel* l = &s->ch[0];
    AacSbrLdChannel* r = &s->ch[1];
    const int pan_offset = l->amp_res ? 12 : 24;
    for (int e = 1; e <= l->grid.num_env; e++) {
      for (int k = 0; k < s->freq.n[l->grid.freq_res[e - 1]]; k++) {
        float temp1;
        float temp2;
        if (l->amp_res) {
          temp1 = exp2i(l->env_facs_q[e][k] + 7);
          temp2 = exp2i(pan_offset - r->env_facs_q[e][k]);
        } else {
          temp1 = exp2i((l->env_facs_q[e][k] >> 1) + 7) * kExp2Half[l->env_facs_q[e][k] & 1];
          const int p = pan_offset - r->env_facs_q[e][k];
          temp2 = exp2i(p >> 1) * kExp2Half[p & 1];
        }
        if (temp1 > 1e20f) {
          temp1 = 1.0f;
        }
        const float fac = temp1 / (1.0f + temp2);
        l->env_facs[e][k] = fac;
        r->env_facs[e][k] = fac * temp2;
      }
    }
    for (int e = 1; e <= l->grid.num_noise; e++) {
      for (int k = 0; k < n_q; k++) {
        const float temp1 = exp2i(kNoiseFloorOffset - l->noise_facs_q[e][k] + 1);
        const float temp2 = exp2i(12 - r->noise_facs_q[e][k]);
        const float fac = temp1 / (1.0f + temp2);
        l->noise_facs[e][k] = fac;
        r->noise_facs[e][k] = fac * temp2;
      }
    }
    return;
  }
  for (int c = 0; c < (s->elem_type == AAC_ELEM_CPE) + 1; c++) {
    AacSbrLdChannel* ch = &s->ch[c];
    for (int e = 1; e <= ch->grid.num_env; e++) {
      for (int k = 0; k < s->freq.n[ch->grid.freq_res[e - 1]]; k++) {
        if (ch->amp_res) {
          ch->env_facs[e][k] = exp2i(ch->env_facs_q[e][k] + 6);
        } else {
          ch->env_facs[e][k] =
              exp2i((ch->env_facs_q[e][k] >> 1) + 6) * kExp2Half[ch->env_facs_q[e][k] & 1];
        }
        if (ch->env_facs[e][k] > 1e20f) {
          ch->env_facs[e][k] = 1.0f;
        }
      }
    }
    for (int e = 1; e <= ch->grid.num_noise; e++) {
      for (int k = 0; k < n_q; k++) {
        ch->noise_facs[e][k] = exp2i(kNoiseFloorOffset - ch->noise_facs_q[e][k]);
      }
    }
  }
}

/* ── high-frequency generation (4.6.18.6) ──────────────────────── */

/* Moves the last 8 slots of the frame to the front of X_low and places
 * the new analysis slots behind them. */
void lf_gen(const AacSbrLdState* s, AacSbrLdChannel* ch) {
  const int slots = s->slots;
  for (int k = 0; k < 32; k++) {
    for (int i = 0; i < kHistory; i++) {
      ch->X_low[k][i][0] = ch->X_low[k][i + slots][0];
      ch->X_low[k][i][1] = ch->X_low[k][i + slots][1];
    }
    for (int i = 0; i < slots; i++) {
      ch->X_low[k][kHistory + i][0] = ch->W[i][k][0];
      ch->X_low[k][kHistory + i][1] = ch->W[i][k][1];
    }
  }
}

/* Covariance of one subband over the window of `len` slots starting two
 * slots into the buffer (the predictor needs two slots of history). */
void autocorrelate(const float (*x)[2], int len, float phi[3][2][2]) {
  /* phi[0][0] = <x_n, x_{n-1}>, phi[0][1] = <x_n, x_{n-2}>,
   * phi[1][0] = <x_{n-1}, x_{n-1}>, phi[1][1] = <x_{n-1}, x_{n-2}>,
   * phi[2][1] = <x_{n-2}, x_{n-2}> over n = 2 .. len + 1 */
  float r00 = 0.0f, i00 = 0.0f, r01 = 0.0f, i01 = 0.0f;
  float r10 = 0.0f, r11 = 0.0f, i11 = 0.0f, r21 = 0.0f;
  for (int n = 2; n < len + 2; n++) {
    const float ar = x[n][0], ai = x[n][1];
    const float br = x[n - 1][0], bi = x[n - 1][1];
    const float cr = x[n - 2][0], ci = x[n - 2][1];
    r00 += ar * br + ai * bi;
    i00 += ai * br - ar * bi;
    r01 += ar * cr + ai * ci;
    i01 += ai * cr - ar * ci;
    r10 += br * br + bi * bi;
    r11 += br * cr + bi * ci;
    i11 += bi * cr - br * ci;
    r21 += cr * cr + ci * ci;
  }
  phi[0][0][0] = r00;
  phi[0][0][1] = i00;
  phi[0][1][0] = r01;
  phi[0][1][1] = i01;
  phi[1][0][0] = r10;
  phi[1][0][1] = 0.0f;
  phi[1][1][0] = r11;
  phi[1][1][1] = i11;
  phi[2][1][0] = r21;
  phi[2][1][1] = 0.0f;
}

/* Second-order linear prediction per subband (4.6.18.6.2). */
void hf_inverse_filter(AacSbrLdChannel* ch, int k0, int len) {
  for (int k = 0; k < k0; k++) {
    float phi[3][2][2];
    autocorrelate(ch->X_low[k], len, phi);
    float* a0 = ch->alpha0[k];
    float* a1 = ch->alpha1[k];
    const float dk = phi[2][1][0] * phi[1][0][0] -
                     (phi[1][1][0] * phi[1][1][0] + phi[1][1][1] * phi[1][1][1]) / 1.000001f;
    if (dk == 0.0f) {
      a1[0] = a1[1] = 0.0f;
    } else {
      const float temp_real = phi[0][0][0] * phi[1][1][0] - phi[0][0][1] * phi[1][1][1] -
                              phi[0][1][0] * phi[1][0][0];
      const float temp_im = phi[0][0][0] * phi[1][1][1] + phi[0][0][1] * phi[1][1][0] -
                            phi[0][1][1] * phi[1][0][0];
      a1[0] = temp_real / dk;
      a1[1] = temp_im / dk;
    }
    if (phi[1][0][0] == 0.0f) {
      a0[0] = a0[1] = 0.0f;
    } else {
      const float temp_real = phi[0][0][0] + a1[0] * phi[1][1][0] + a1[1] * phi[1][1][1];
      const float temp_im = phi[0][0][1] + a1[1] * phi[1][1][0] - a1[0] * phi[1][1][1];
      a0[0] = -temp_real / phi[1][0][0];
      a0[1] = -temp_im / phi[1][0][0];
    }
    if (a1[0] * a1[0] + a1[1] * a1[1] >= 16.0f || a0[0] * a0[0] + a0[1] * a0[1] >= 16.0f) {
      a1[0] = a1[1] = 0.0f;
      a0[0] = a0[1] = 0.0f;
    }
  }
}

/* Chirp factors (4.6.18.6.1). */
void chirp(const AacSbrLdState* s, AacSbrLdChannel* ch) {
  static const float kBw[4] = {0.0f, 0.75f, 0.9f, 0.98f};
  for (int i = 0; i < s->freq.n_q; i++) {
    float new_bw;
    if (ch->invf_mode[0][i] + ch->invf_mode[1][i] == 1) {
      new_bw = 0.6f;
    } else {
      new_bw = kBw[ch->invf_mode[0][i]];
    }
    if (new_bw < ch->bw_array[i]) {
      new_bw = 0.75f * new_bw + 0.25f * ch->bw_array[i];
    } else {
      new_bw = 0.90625f * new_bw + 0.09375f * ch->bw_array[i];
    }
    ch->bw_array[i] = new_bw < 0.015625f ? 0.0f : new_bw;
  }
}

/* Patches the frame's slots of the low band into the high band with the
 * inverse filter of the source band (4.6.18.6.3). */
int hf_gen(const AacSbrLdState* s, AacSbrLdChannel* ch) {
  const AacSbrFreq* f = &s->freq;
  const int start = kHistory;
  const int end = kHistory + s->slots;
  int g = 0;
  int k = f->kx;
  for (int j = 0; j < f->num_patches; j++) {
    for (int x = 0; x < f->patch_num_subbands[j]; x++, k++) {
      const int p = f->patch_start_subband[j] + x;
      while (g <= f->n_q && k >= f->f_tablenoise[g]) {
        g++;
      }
      g--;
      if (g < 0 || p < 0 || p >= 32 || k >= 64) {
        return AAC_ERR_DECODE;
      }
      const float bw = ch->bw_array[g];
      const float a0 = ch->alpha1[p][0] * bw * bw;
      const float a1 = ch->alpha1[p][1] * bw * bw;
      const float a2 = ch->alpha0[p][0] * bw;
      const float a3 = ch->alpha0[p][1] * bw;
      const float (*X_low)[2] = ch->X_low[p];
      float (*X_high)[2] = ch->X_high[k];
      for (int i = start; i < end; i++) {
        X_high[i][0] = X_low[i - 2][0] * a0 - X_low[i - 2][1] * a1 + X_low[i - 1][0] * a2 -
                       X_low[i - 1][1] * a3 + X_low[i][0];
        X_high[i][1] = X_low[i - 2][1] * a0 + X_low[i - 2][0] * a1 + X_low[i - 1][1] * a2 +
                       X_low[i - 1][0] * a3 + X_low[i][1];
      }
    }
  }
  for (; k < f->kx + f->m; k++) {
    memset(ch->X_high[k], 0, sizeof(ch->X_high[k]));
  }
  return 0;
}

/* ── envelope adjustment (4.6.18.7) ────────────────────────────── */

int mapping(const AacSbrLdState* s, AacSbrLdChannel* ch) {
  const AacSbrFreq* f = &s->freq;
  const AacSbrLdGrid* g = &ch->grid;
  const int kx = f->kx;
  memset(ch->s_indexmapped[1], 0, sizeof(ch->s_indexmapped) - sizeof(ch->s_indexmapped[0]));
  for (int e = 0; e < g->num_env; e++) {
    const int res = g->freq_res[e];
    const int ilim = f->n[res];
    const uint16_t* table = res ? f->f_tablehigh : f->f_tablelow;
    if (kx != table[0]) {
      return AAC_ERR_DECODE;
    }
    for (int i = 0; i < ilim; i++) {
      for (int m = table[i]; m < table[i + 1]; m++) {
        ch->e_origmapped[e][m - kx] = ch->env_facs[e + 1][i];
      }
    }
    const int q = (g->num_noise > 1) && (g->t_env[e] >= g->t_q[1]);
    for (int i = 0; i < f->n_q; i++) {
      for (int m = f->f_tablenoise[i]; m < f->f_tablenoise[i + 1]; m++) {
        ch->q_mapped[e][m - kx] = ch->noise_facs[q + 1][i];
      }
    }
    if (ch->add_harmonic_flag) {
      for (int i = 0; i < f->n[1]; i++) {
        const int m_midpoint = (f->f_tablehigh[i] + f->f_tablehigh[i + 1]) >> 1;
        ch->s_indexmapped[e + 1][m_midpoint - kx] =
            (uint8_t)(ch->add_harmonic[i] &&
                      (e >= g->tran_env || ch->s_indexmapped[0][m_midpoint - kx] == 1));
      }
    }
    for (int i = 0; i < ilim; i++) {
      int present = 0;
      for (int m = table[i]; m < table[i + 1]; m++) {
        if (ch->s_indexmapped[e + 1][m - kx]) {
          present = 1;
          break;
        }
      }
      memset(&ch->s_mapped[e][table[i] - kx], present, (size_t)(table[i + 1] - table[i]));
    }
  }
  memcpy(ch->s_indexmapped[0], ch->s_indexmapped[g->num_env], sizeof(ch->s_indexmapped[0]));
  return 0;
}

float sum_square(const float (*x)[2], int n) {
  float sum = 0.0f;
  for (int i = 0; i < n; i++) {
    sum += x[i][0] * x[i][0] + x[i][1] * x[i][1];
  }
  return sum;
}

void env_estimate(const AacSbrLdState* s, AacSbrLdChannel* ch) {
  const AacSbrFreq* f = &s->freq;
  const AacSbrLdGrid* g = &ch->grid;
  const int kx = f->kx;
  for (int e = 0; e < g->num_env; e++) {
    const int ilb = g->t_env[e] + kHistory;
    const int iub = g->t_env[e + 1] + kHistory;
    const float recip_env_size = 1.0f / (float)(iub - ilb);
    if (s->header.interpol_freq) {
      for (int m = 0; m < f->m; m++) {
        ch->e_curr[e][m] = sum_square(ch->X_high[m + kx] + ilb, iub - ilb) * recip_env_size;
      }
      continue;
    }
    const uint16_t* table = g->freq_res[e] ? f->f_tablehigh : f->f_tablelow;
    for (int p = 0; p < f->n[g->freq_res[e]]; p++) {
      float sum = 0.0f;
      for (int k = table[p]; k < table[p + 1]; k++) {
        sum += sum_square(ch->X_high[k] + ilb, iub - ilb);
      }
      sum *= recip_env_size / (float)(table[p + 1] - table[p]);
      for (int k = table[p]; k < table[p + 1]; k++) {
        ch->e_curr[e][k - kx] = sum;
      }
    }
  }
}

void gain_calc(const AacSbrLdState* s, AacSbrLdChannel* ch) {
  static const float kLimGain[4] = {0.70795f, 1.0f, 1.41254f, 1e10f};
  const AacSbrFreq* f = &s->freq;
  const AacSbrLdGrid* g = &ch->grid;
  for (int e = 0; e < g->num_env; e++) {
    const int delta = e != g->tran_env;
    for (int k = 0; k < f->n_lim; k++) {
      const int m0 = f->f_tablelim[k] - f->kx;
      const int m1 = f->f_tablelim[k + 1] - f->kx;
      float sum[2] = {0.0f, 0.0f};
      for (int m = m0; m < m1; m++) {
        const float temp = ch->e_origmapped[e][m] / (1.0f + ch->q_mapped[e][m]);
        ch->q_m[e][m] = sqrtf(temp * ch->q_mapped[e][m]);
        ch->s_m[e][m] = sqrtf(temp * (float)ch->s_indexmapped[e + 1][m]);
        if (!ch->s_mapped[e][m]) {
          ch->gain[e][m] =
              sqrtf(ch->e_origmapped[e][m] /
                    ((1.0f + ch->e_curr[e][m]) * (1.0f + ch->q_mapped[e][m] * (float)delta)));
        } else {
          ch->gain[e][m] = sqrtf(ch->e_origmapped[e][m] * ch->q_mapped[e][m] /
                                 ((1.0f + ch->e_curr[e][m]) * (1.0f + ch->q_mapped[e][m])));
        }
        ch->gain[e][m] += FLT_MIN;
      }
      for (int m = m0; m < m1; m++) {
        sum[0] += ch->e_origmapped[e][m];
        sum[1] += ch->e_curr[e][m];
      }
      float gain_max = kLimGain[s->header.limiter_gains] *
                       sqrtf((FLT_EPSILON + sum[0]) / (FLT_EPSILON + sum[1]));
      gain_max = std::min(100000.0f, gain_max);
      for (int m = m0; m < m1; m++) {
        const float q_m_max = ch->q_m[e][m] * gain_max / ch->gain[e][m];
        ch->q_m[e][m] = std::min(ch->q_m[e][m], q_m_max);
        ch->gain[e][m] = std::min(ch->gain[e][m], gain_max);
      }
      sum[0] = sum[1] = 0.0f;
      for (int m = m0; m < m1; m++) {
        sum[0] += ch->e_origmapped[e][m];
        sum[1] += ch->e_curr[e][m] * ch->gain[e][m] * ch->gain[e][m] +
                  ch->s_m[e][m] * ch->s_m[e][m] +
                  (float)(delta && !ch->s_m[e][m]) * ch->q_m[e][m] * ch->q_m[e][m];
      }
      float gain_boost = sqrtf((FLT_EPSILON + sum[0]) / (FLT_EPSILON + sum[1]));
      gain_boost = std::min(1.584893192f, gain_boost);
      for (int m = m0; m < m1; m++) {
        ch->gain[e][m] *= gain_boost;
        ch->q_m[e][m] *= gain_boost;
        ch->s_m[e][m] *= gain_boost;
      }
    }
  }
}

/* Noise and sinusoids for one slot: the noise phase table advances per
 * band, the sinusoid phase pattern alternates with the band index. */
void apply_noise(float (*Y)[2], const float* s_m, const float* q_filt, unsigned noise, int kx,
                 int m_max, int indexsine) {
  float phi_sign0;
  float phi_sign1;
  switch (indexsine) {
    case 0: phi_sign0 = 1.0f; phi_sign1 = 0.0f; break;
    case 1: phi_sign0 = 0.0f; phi_sign1 = 1.0f - 2.0f * (float)(kx & 1); break;
    case 2: phi_sign0 = -1.0f; phi_sign1 = 0.0f; break;
    default: phi_sign0 = 0.0f; phi_sign1 = -(1.0f - 2.0f * (float)(kx & 1)); break;
  }
  for (int m = 0; m < m_max; m++) {
    noise = (noise + 1) & 0x1ff;
    if (s_m[m] != 0.0f) {
      Y[m][0] += s_m[m] * phi_sign0;
      Y[m][1] += s_m[m] * phi_sign1;
    } else {
      Y[m][0] += q_filt[m] * aac_sbr_noise_table[noise][0];
      Y[m][1] += q_filt[m] * aac_sbr_noise_table[noise][1];
    }
    phi_sign1 = -phi_sign1;
  }
}

/* Sinusoids alone for a slot of the transient's envelope. */
void apply_sinusoids(float (*Y)[2], const float* s_m, int kx, int m_max, int indexsine) {
  const int idx = indexsine & 1;
  const int A = 1 - ((indexsine + (kx & 1)) & 2);
  const int B = (A ^ (-idx)) + idx;
  for (int m = 0; m < m_max; m++) {
    Y[m][idx] += s_m[m] * (float)((m & 1) ? B : A);
  }
}

void hf_assemble(const AacSbrLdState* s, AacSbrLdChannel* ch) {
  static const float kSmooth[5] = {0.33333333333333f, 0.30150283239582f, 0.21816949906249f,
                                   0.11516383427084f, 0.03183050093751f};
  const AacSbrFreq* f = &s->freq;
  const AacSbrLdGrid* g = &ch->grid;
  const int h_SL = kSmoothSlots * !s->header.smoothing_mode;
  const int kx = f->kx;
  const int m_max = f->m;
  float (*g_temp)[AAC_SBR_MAX_BANDS] = ch->g_temp;
  float (*q_temp)[AAC_SBR_MAX_BANDS] = ch->q_temp;
  unsigned indexnoise = ch->f_indexnoise;
  int indexsine = (int)ch->f_indexsine;
  /* rows 0..3 hold the four slots before the frame, row 4 + i slot i */
  if (!ch->smoothing_primed) {
    for (int i = 0; i < kSmoothSlots; i++) {
      memcpy(g_temp[i], ch->gain[0], (size_t)m_max * sizeof(float));
      memcpy(q_temp[i], ch->q_m[0], (size_t)m_max * sizeof(float));
    }
    ch->smoothing_primed = 1;
  } else {
    for (int i = 0; i < kSmoothSlots; i++) {
      memcpy(g_temp[i], g_temp[s->slots + i], sizeof(g_temp[0]));
      memcpy(q_temp[i], q_temp[s->slots + i], sizeof(q_temp[0]));
    }
  }
  for (int e = 0; e < g->num_env; e++) {
    for (int i = g->t_env[e]; i < g->t_env[e + 1]; i++) {
      memcpy(g_temp[kSmoothSlots + i], ch->gain[e], (size_t)m_max * sizeof(float));
      memcpy(q_temp[kSmoothSlots + i], ch->q_m[e], (size_t)m_max * sizeof(float));
    }
  }
  for (int e = 0; e < g->num_env; e++) {
    for (int i = g->t_env[e]; i < g->t_env[e + 1]; i++) {
      float g_filt_tab[AAC_SBR_MAX_BANDS];
      float q_filt_tab[AAC_SBR_MAX_BANDS];
      const float* g_filt;
      const float* q_filt;
      if (h_SL && e != g->tran_env) {
        g_filt = g_filt_tab;
        q_filt = q_filt_tab;
        for (int m = 0; m < m_max; m++) {
          float gs = 0.0f;
          float qs = 0.0f;
          for (int j = 0; j <= h_SL; j++) {
            gs += g_temp[kSmoothSlots + i - j][m] * kSmooth[j];
            qs += q_temp[kSmoothSlots + i - j][m] * kSmooth[j];
          }
          g_filt_tab[m] = gs;
          q_filt_tab[m] = qs;
        }
      } else {
        g_filt = g_temp[kSmoothSlots + i];
        q_filt = q_temp[kSmoothSlots + i];
      }
      float (*Y)[2] = ch->Y[i];
      memset(Y, 0, sizeof(ch->Y[i]));
      for (int m = 0; m < m_max; m++) {
        Y[kx + m][0] = ch->X_high[kx + m][i + kHistory][0] * g_filt[m];
        Y[kx + m][1] = ch->X_high[kx + m][i + kHistory][1] * g_filt[m];
      }
      if (e != g->tran_env) {
        apply_noise(Y + kx, ch->s_m[e], q_filt, indexnoise, kx, m_max, indexsine);
      } else {
        apply_sinusoids(Y + kx, ch->s_m[e], kx, m_max, indexsine);
      }
      indexnoise = (indexnoise + (unsigned)m_max) & 0x1ff;
      indexsine = (indexsine + 1) & 3;
    }
  }
  ch->f_indexnoise = indexnoise;
  ch->f_indexsine = (unsigned)indexsine;
}

void reset_channel(AacSbrLdChannel* ch) {
  aac_cldfb_analysis_init(&ch->analysis, 32);
  memset(ch->bw_array, 0, sizeof(ch->bw_array));
  memset(ch->W, 0, sizeof(ch->W));
  memset(ch->X_low, 0, sizeof(ch->X_low));
  memset(ch->X_high, 0, sizeof(ch->X_high));
  memset(ch->Y, 0, sizeof(ch->Y));
  memset(ch->g_temp, 0, sizeof(ch->g_temp));
  memset(ch->q_temp, 0, sizeof(ch->q_temp));
  memset(ch->s_indexmapped, 0, sizeof(ch->s_indexmapped));
  memset(ch->env_facs_q, 0, sizeof(ch->env_facs_q));
  memset(ch->noise_facs_q, 0, sizeof(ch->noise_facs_q));
  memset(ch->invf_mode, 0, sizeof(ch->invf_mode));
  memset(&ch->grid, 0, sizeof(ch->grid));
  ch->grid.tran_env = -1;
  ch->prev_freq_res = 1;
  ch->f_indexnoise = 0;
  ch->f_indexsine = 0;
  ch->smoothing_primed = 0;
}

}  // namespace

/* ── public ────────────────────────────────────────────────────── */

int aac_sbr_ld_init(AacSbrLdState* s, int core_sample_rate, int frame_length, int dual_rate,
                    const AacSbrLdHeader* header) {
  memset(s, 0, sizeof(*s));
  if ((frame_length != 512 && frame_length != 480) || !header) {
    return AAC_ERR_INVALID_ARG;
  }
  s->core_sample_rate = core_sample_rate;
  s->frame_length = frame_length;
  s->slots = frame_length / 32;
  s->dual_rate = dual_rate != 0;
  s->elem_type = -1;
  s->header = *header;
  AacSbrFreq* f = &s->freq;
  memset(f, 0, sizeof(*f));
  f->sample_rate = 2 * core_sample_rate;
  f->start_freq = header->start_freq;
  f->stop_freq = header->stop_freq;
  f->xover_band = header->xover_band;
  f->freq_scale = header->freq_scale;
  f->alter_scale = header->alter_scale;
  f->noise_bands = header->noise_bands;
  f->limiter_bands = header->limiter_bands;
  const int err = aac_sbr_freq_tables(f);
  if (err) {
    return err;
  }
  aac_sbr_ld_reset(s);
  return 0;
}

void aac_sbr_ld_reset(AacSbrLdState* s) {
  for (int c = 0; c < 2; c++) {
    reset_channel(&s->ch[c]);
    aac_cldfb_synthesis_init(&s->ch[c].synthesis, s->dual_rate ? 64 : 32);
  }
  s->have_data = 0;
  s->coupling = 0;
}

int aac_sbr_ld_output_size(const AacSbrLdState* s) {
  return s->frame_length * (s->dual_rate ? 2 : 1);
}

int aac_sbr_ld_delay(const AacSbrLdState* s) {
  return s->dual_rate ? AAC_CLDFB_DELAY + 1 : (AAC_CLDFB_DELAY + 1) / 2;
}

uint16_t aac_sbr_ld_crc(const uint8_t* data, int bit_offset, int nbits) {
  uint16_t crc = 0x3FF;
  for (int i = 0; i < nbits; i++) {
    const int pos = bit_offset + i;
    const int bit = (data[pos >> 3] >> (7 - (pos & 7))) & 1;
    const int feedback = ((crc >> 9) & 1) ^ bit;
    crc = (uint16_t)((crc << 1) & 0x3FF);
    if (feedback) {
      crc ^= 0x233;
    }
  }
  return crc;
}

int aac_sbr_ld_read(AacSbrLdState* s, AacBitReader* r, int elem_type, int crc) {
  if (elem_type != s->elem_type) {
    /* delta coding in time starts over */
    for (int c = 0; c < 2; c++) {
      memset(s->ch[c].env_facs_q, 0, sizeof(s->ch[c].env_facs_q));
      memset(s->ch[c].noise_facs_q, 0, sizeof(s->ch[c].noise_facs_q));
    }
    s->elem_type = elem_type;
  }
  uint32_t crc_sent = 0;
  if (crc) {
    crc_sent = aac_bitreader_read(r, 10);
  }
  const int data_start = r->pos;
  int ret;
  if (elem_type == AAC_ELEM_SCE) {
    ret = read_single_channel(s, r);
  } else if (elem_type == AAC_ELEM_CPE) {
    ret = read_channel_pair(s, r);
  } else {
    ret = AAC_ERR_DECODE;
  }
  if (ret == 0 && aac_bitreader_read(r, 1)) { /* bs_extended_data */
    int bytes = (int)aac_bitreader_read(r, 4);
    if (bytes == 15) {
      bytes += (int)aac_bitreader_read(r, 8);
    }
    aac_bitreader_skip(r, bytes * 8);
  }
  if (ret == 0 && r->overread) {
    ret = AAC_ERR_DECODE;
  }
  if (ret == 0 && crc && aac_sbr_ld_crc(r->data, data_start, r->pos - data_start) != crc_sent) {
    ret = AAC_ERR_DECODE;
  }
  s->have_data = ret == 0;
  return ret;
}

void aac_sbr_ld_process(AacSbrLdState* s, const float* core_left, const float* core_right,
                        float* out_left, float* out_right) {
  const int nch = s->elem_type == AAC_ELEM_CPE && core_right && out_right ? 2 : 1;
  const int slots = s->slots;
  const int out_bands = s->dual_rate ? 64 : 32;
  const int active = s->have_data && s->elem_type >= 0;
  if (active) {
    dequant(s);
  }
  for (int c = 0; c < nch; c++) {
    AacSbrLdChannel* ch = &s->ch[c];
    const float* core = c ? core_right : core_left;
    float* out = c ? out_right : out_left;
    float block[32];
    for (int i = 0; i < slots; i++) {
      for (int n = 0; n < 32; n++) {
        block[n] = core[32 * i + n] * 32768.0f;
      }
      aac_cldfb_analyze(&ch->analysis, block, ch->W[i]);
    }
    lf_gen(s, ch);
    int high = 0;
    if (active) {
      hf_inverse_filter(ch, s->freq.k[0], slots + 6);
      chirp(s, ch);
      if (hf_gen(s, ch) == 0 && mapping(s, ch) == 0) {
        env_estimate(s, ch);
        gain_calc(s, ch);
        hf_assemble(s, ch);
        high = 1;
      }
    }
    const int kx = s->freq.kx;
    const int m = s->freq.m;
    for (int i = 0; i < slots; i++) {
      float X[64][2] = {};
      for (int k = 0; k < kx && k < 32; k++) {
        X[k][0] = ch->X_low[k][kHistory + i][0];
        X[k][1] = ch->X_low[k][kHistory + i][1];
      }
      if (high) {
        for (int k = kx; k < kx + m && k < 64; k++) {
          X[k][0] = ch->Y[i][k][0];
          X[k][1] = ch->Y[i][k][1];
        }
      }
      aac_cldfb_synthesize(&ch->synthesis, X, out + out_bands * i);
    }
    for (int i = 0; i < out_bands * slots; i++) {
      out[i] *= 1.0f / 32768.0f;
    }
  }
  s->have_data = 0;
}
