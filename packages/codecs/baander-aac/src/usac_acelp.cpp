/* ACELP of the USAC LPD core (ISO/IEC 23003-3 6.2.7.3, 7.14): the
 * acelp_coding() syntax, the adaptive codebook with its 1/4 sample
 * resolution and the pitch lag coding, the algebraic innovation codebook
 * (four interleaved tracks of 16 positions, 12 to 64 bits per subframe),
 * the joint gain decoding against the transmitted mean energy, the
 * innovation pre-emphasis, pitch sharpening and enhancement, the LPC
 * synthesis per 64-sample subframe. */
#include <cmath>
#include <cstring>

#include "usac_lpd_internal.h"

#define NB_POS 16  /* positions per track */
#define NB_TRACK 4
#define L_SUBFR LPD_MAX_SUBFR

/* Innovation codebook bits per subframe for acelp_core_mode 0..7 (Table
 * 7.14.1). */
static const int kIcbBits[8] = {12, 16, 20, 28, 36, 44, 52, 64};

int lpd_acelp_icb_bits(int acelp_core_mode) { return kIcbBits[acelp_core_mode & 7]; }

/* ── syntax ─────────────────────────────────────────────────────── */

/* Codeword widths per track for each codebook size: the richer tracks
 * come first. */
static void icb_layout(int nbits, int* words, int* widths) {
  switch (nbits) {
    case 12: /* two pulses, one per pair of tracks, 5 position bits and a sign each */
      *words = 2;
      widths[0] = widths[1] = 6;
      break;
    case 16: /* one pulse per track in 8 positions */
      *words = 4;
      widths[0] = widths[1] = widths[2] = widths[3] = 4;
      break;
    case 20: /* one pulse per track */
      *words = 4;
      widths[0] = widths[1] = widths[2] = widths[3] = 5;
      break;
    case 28: /* two pulses in tracks 0 and 1, one in tracks 2 and 3 */
      *words = 4;
      widths[0] = widths[1] = 9;
      widths[2] = widths[3] = 5;
      break;
    case 36:
      *words = 4;
      widths[0] = widths[1] = widths[2] = widths[3] = 9;
      break;
    case 44: /* three pulses in tracks 0 and 1, two in tracks 2 and 3 */
      *words = 4;
      widths[0] = widths[1] = 13;
      widths[2] = widths[3] = 9;
      break;
    case 52:
      *words = 4;
      widths[0] = widths[1] = widths[2] = widths[3] = 13;
      break;
    default: /* 64: four pulses per track */
      *words = 4;
      widths[0] = widths[1] = widths[2] = widths[3] = 16;
      break;
  }
}

int lpd_read_acelp(AacBitReader* r, int acelp_core_mode, int nb_subfr, LpdAcelpData* d) {
  const int nbits = lpd_acelp_icb_bits(acelp_core_mode);
  int words, widths[4];
  icb_layout(nbits, &words, widths);
  d->mean_energy = (int)aac_bitreader_read(r, 2);
  for (int sf = 0; sf < nb_subfr; sf++) {
    LpdAcelpSubframe* s = &d->sf[sf];
    s->acb_index = (int)aac_bitreader_read(r, (sf == 0 || sf == 2) ? 9 : 6);
    s->ltp_filtering_flag = (int)aac_bitreader_read(r, 1);
    s->icb_bits = nbits;
    for (int t = 0; t < 4; t++) {
      s->icb_index[t] = t < words ? aac_bitreader_read(r, widths[t]) : 0;
    }
    s->gains_index = (int)aac_bitreader_read(r, 7);
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* ── pitch lag (7.14.2) ─────────────────────────────────────────── */

/* The 9 bit absolute lag covers pit_min .. pit_fr2 in quarter samples,
 * pit_fr2 .. pit_fr1 in half samples and pit_fr1 .. pit_max in whole
 * samples; the 6 bit relative lag covers 16 samples around the previous
 * subframe's lag in quarter samples. */
void lpd_acelp_pitch(const AacUsacLpdChannel* c, int index, int bits, int* t0, int* frac,
                     int* t0_min, int* t0_max) {
  if (bits == 9) {
    const int n_quarter = (c->pit_fr2 - c->pit_min) * 4;
    const int n_half = (c->pit_fr1 - c->pit_fr2) * 2;
    if (index < n_quarter) {
      *t0 = c->pit_min + index / 4;
      *frac = index & 3;
    } else if (index < n_quarter + n_half) {
      const int i = index - n_quarter;
      *t0 = c->pit_fr2 + i / 2;
      *frac = (i & 1) * 2;
    } else {
      *t0 = c->pit_fr1 + index - n_quarter - n_half;
      *frac = 0;
    }
    if (*t0 > c->pit_max) {
      *t0 = c->pit_max;
      *frac = 0;
    }
    /* the range of the following relative lag */
    *t0_min = *t0 - 8;
    if (*t0_min < c->pit_min) {
      *t0_min = c->pit_min;
    }
    *t0_max = *t0_min + 15;
    if (*t0_max > c->pit_max) {
      *t0_max = c->pit_max;
      *t0_min = *t0_max - 15;
    }
  } else {
    *t0 = *t0_min + index / 4;
    *frac = index & 3;
    if (*t0 > *t0_max) {
      *t0 = *t0_max;
      *frac = 0;
    }
  }
}

/* v[n] = exc[n - t0 - frac/4] by the 1/4 resolution interpolation filter
 * (7.14.3); `exc` points at the sample of the subframe start, with the
 * history before it, and v may be the same buffer (the vector is written
 * in place to extend the excitation when the lag is shorter than the
 * subframe). */
void lpd_acelp_adaptive(const AacUsacLpdChannel* c, const float* exc, int t0, int frac,
                        float* v, int n) {
  (void)c;
  const float* h = lpd_interp_filter();
  const int half = LPD_UP_SAMP * LPD_INTERPOL;
  for (int k = 0; k < n; k++) {
    float s = 0.0f;
    for (int j = -LPD_INTERPOL + 1; j <= LPD_INTERPOL; j++) {
      s += exc[k - t0 - j] * h[4 * j - frac + half];
    }
    v[k] = s;
  }
}

/* ── algebraic codebook (7.14.4) ────────────────────────────────── */

/* The position codes of ISO/IEC 23003-3 7.14.4.2 (as in 3GPP AMR-WB):
 * a position value carries the track position in its low 4 bits and the
 * sign in bit 4 (set: positive). */
static void dec_1p_n1(uint32_t index, int n, int offset, int* pos) {
  const uint32_t mask = (1u << n) - 1u;
  int p = (int)(index & mask) + offset;
  if ((index >> n) & 1u) {
    p += NB_POS;
  }
  pos[0] = p;
}

static void dec_2p_2n1(uint32_t index, int n, int offset, int* pos) {
  const uint32_t mask = (1u << n) - 1u;
  int p0 = (int)((index >> n) & mask) + offset;
  const int i = (int)((index >> (2 * n)) & 1u);
  int p1 = (int)(index & mask) + offset;
  if ((p1 - offset) < (p0 - offset)) {
    if (i == 1) {
      p0 += NB_POS;
    } else {
      p1 += NB_POS;
    }
  } else if (i == 1) {
    p0 += NB_POS;
    p1 += NB_POS;
  }
  pos[0] = p0;
  pos[1] = p1;
}

static void dec_3p_3n1(uint32_t index, int n, int offset, int* pos) {
  const uint32_t mask = (1u << (2 * n - 1)) - 1u;
  int j = offset;
  if ((index >> (2 * n - 1)) & 1u) {
    j += 1 << (n - 1);
  }
  dec_2p_2n1(index & mask, n - 1, j, pos);
  const uint32_t mask2 = (1u << (n + 1)) - 1u;
  dec_1p_n1((index >> (2 * n)) & mask2, n, offset, pos + 2);
}

static void dec_4p_4n1(uint32_t index, int n, int offset, int* pos) {
  const uint32_t mask = (1u << (2 * n - 1)) - 1u;
  int j = offset;
  if ((index >> (2 * n - 1)) & 1u) {
    j += 1 << (n - 1);
  }
  dec_2p_2n1(index & mask, n - 1, j, pos);
  const uint32_t mask2 = (1u << (2 * n + 1)) - 1u;
  dec_2p_2n1((index >> (2 * n)) & mask2, n, offset, pos + 2);
}

static void dec_4p_4n(uint32_t index, int n, int offset, int* pos) {
  const int n1 = n - 1;
  const int j = offset + (1 << n1);
  switch ((index >> (4 * n - 2)) & 3u) {
    case 0:
      if (((index >> (4 * n - 3)) & 1u) == 0) {
        dec_4p_4n1(index, n1, offset, pos);
      } else {
        dec_4p_4n1(index, n1, j, pos);
      }
      break;
    case 1:
      dec_1p_n1(index >> (3 * n1 + 1), n1, offset, pos);
      dec_3p_3n1(index, n1, j, pos + 1);
      break;
    case 2:
      dec_2p_2n1(index >> (2 * n1 + 1), n1, offset, pos);
      dec_2p_2n1(index, n1, j, pos + 2);
      break;
    default:
      dec_3p_3n1(index >> (n1 + 1), n1, offset, pos);
      dec_1p_n1(index, n1, j, pos + 3);
      break;
  }
}

static void add_pulses(const int* pos, int nb_pulse, int track, float* code) {
  for (int k = 0; k < nb_pulse; k++) {
    const int i = (pos[k] & (NB_POS - 1)) * NB_TRACK + track;
    code[i] += (pos[k] & NB_POS) ? 1.0f : -1.0f;
  }
}

/* The innovation vector of one subframe from the codeword(s). */
void lpd_acelp_pulses(const LpdAcelpSubframe* sf, float* code, int l_subfr) {
  memset(code, 0, sizeof(float) * (size_t)l_subfr);
  int pos[6];
  switch (sf->icb_bits) {
    case 12:
      /* two pulses, each on one of two 32-position tracks (even and odd
       * positions), 5 position bits and a sign */
      for (int k = 0; k < 2; k++) {
        const uint32_t idx = sf->icb_index[k];
        const int p = (int)(idx & 31u);
        const int i = 2 * p + k;
        code[i] += (idx >> 5) & 1u ? 1.0f : -1.0f;
      }
      break;
    case 16:
      /* one pulse per track on every other position (8 positions), a sign */
      for (int t = 0; t < 4; t++) {
        const uint32_t idx = sf->icb_index[t];
        const int i = (int)(idx & 7u) * 2 * NB_TRACK + t;
        code[i] += (idx >> 3) & 1u ? 1.0f : -1.0f;
      }
      break;
    case 20:
      for (int t = 0; t < 4; t++) {
        dec_1p_n1(sf->icb_index[t], 4, 0, pos);
        add_pulses(pos, 1, t, code);
      }
      break;
    case 28:
      for (int t = 0; t < 2; t++) {
        dec_2p_2n1(sf->icb_index[t], 4, 0, pos);
        add_pulses(pos, 2, t, code);
      }
      for (int t = 2; t < 4; t++) {
        dec_1p_n1(sf->icb_index[t], 4, 0, pos);
        add_pulses(pos, 1, t, code);
      }
      break;
    case 36:
      for (int t = 0; t < 4; t++) {
        dec_2p_2n1(sf->icb_index[t], 4, 0, pos);
        add_pulses(pos, 2, t, code);
      }
      break;
    case 44:
      for (int t = 0; t < 2; t++) {
        dec_3p_3n1(sf->icb_index[t], 4, 0, pos);
        add_pulses(pos, 3, t, code);
      }
      for (int t = 2; t < 4; t++) {
        dec_2p_2n1(sf->icb_index[t], 4, 0, pos);
        add_pulses(pos, 2, t, code);
      }
      break;
    case 52:
      for (int t = 0; t < 4; t++) {
        dec_3p_3n1(sf->icb_index[t], 4, 0, pos);
        add_pulses(pos, 3, t, code);
      }
      break;
    default: /* 64 */
      for (int t = 0; t < 4; t++) {
        dec_4p_4n(sf->icb_index[t], 4, 0, pos);
        add_pulses(pos, 4, t, code);
      }
      break;
  }
}

/* ── memories (7.14.1) ──────────────────────────────────────────── */

/* After a TCX or FD segment the adaptive codebook holds the LPC residual
 * of the decoded signal and the synthesis filter its last outputs:
 * `sig` points at the sample following the segment, with at least
 * LPD_EXC_HIST samples before it. */
void lpd_acelp_memory_from_signal(AacUsacLpdChannel* c, const float* a, const float* sig,
                                  int n) {
  float zero[LPD_M];
  memset(zero, 0, sizeof(zero));
  const int len = n < LPD_EXC_HIST ? n : LPD_EXC_HIST;
  memset(c->exc, 0, sizeof(float) * (size_t)(LPD_EXC_HIST - len));
  lpd_res_filter(a, sig - len, c->exc + LPD_EXC_HIST - len, len, zero);
  for (int i = 0; i < LPD_M; i++) {
    c->syn_mem[i] = sig[-1 - i];
  }
}

/* ── decoding of one ACELP frame (7.14) ─────────────────────────── */

void lpd_acelp_decode(AacUsacLpdChannel* c, const LpdAcelpData* d, const float* lsp0,
                      const float* lsp1, float stab_fac, float* out, int* t_sf, float* g_sf) {
  const float mean_ener = 18.0f + 12.0f * (float)d->mean_energy;
  int t0 = c->t0_prev, frac = 0, t0_min = c->pit_min, t0_max = c->pit_min + 15;
  float a[LPD_M + 1];
  for (int sf = 0; sf < c->nb_subfr; sf++) {
    const LpdAcelpSubframe* s = &d->sf[sf];
    lpd_interp_lsp(lsp0, lsp1, (float)(sf + 1) / (float)c->nb_subfr, a);
    lpd_acelp_pitch(c, s->acb_index, (sf == 0 || sf == 2) ? 9 : 6, &t0, &frac, &t0_min, &t0_max);

    /* adaptive codebook, written in place at the end of the excitation */
    float* exc = c->exc + LPD_EXC_HIST;
    memset(exc, 0, sizeof(float) * (L_SUBFR + LPD_INTERPOL));
    lpd_acelp_adaptive(c, exc, t0, frac, exc, L_SUBFR);
    float v[L_SUBFR];
    if (s->ltp_filtering_flag == 0) {
      /* low pass B(z) = 0.18 + 0.64 z^-1 + 0.18 z^-2 centred */
      for (int n = 0; n < L_SUBFR; n++) {
        const float next = n + 1 < L_SUBFR ? exc[n + 1] : exc[n];
        v[n] = 0.18f * exc[n - 1] + 0.64f * exc[n] + 0.18f * next;
      }
    } else {
      memcpy(v, exc, sizeof(v));
    }

    /* innovation: pulses, pre-emphasis with the tilt of the previous
     * subframe, pitch sharpening */
    float code[L_SUBFR];
    lpd_acelp_pulses(s, code, L_SUBFR);
    for (int n = L_SUBFR - 1; n > 0; n--) {
      code[n] -= c->tilt_code * code[n - 1];
    }
    const int t_int = t0 + (frac > 2 ? 1 : 0);
    for (int n = t_int; n < L_SUBFR; n++) {
      code[n] += LPD_PIT_SHARP * code[n - t_int];
    }

    /* gains: the innovation gain is a factor on the gain predicted from
     * the mean energy and the innovation energy */
    float gain_pit, gain_code_fac;
    lpd_gain_dequant(s->gains_index, &gain_pit, &gain_code_fac);
    float ener_code = 0.0f;
    for (int n = 0; n < L_SUBFR; n++) {
      ener_code += code[n] * code[n];
    }
    ener_code = 10.0f * log10f(ener_code / (float)L_SUBFR + 1e-9f);
    const float gcode0 = powf(10.0f, (mean_ener - ener_code) / 20.0f);
    float gain_code = gain_code_fac * gcode0;

    /* voicing, the tilt for the next subframe */
    float ener_v = 0.0f, ener_c = 0.0f;
    for (int n = 0; n < L_SUBFR; n++) {
      ener_v += v[n] * v[n];
      ener_c += code[n] * code[n];
    }
    ener_v *= gain_pit * gain_pit;
    ener_c *= gain_code * gain_code;
    const float voice_fac = (ener_v - ener_c) / (ener_v + ener_c + 1e-9f);
    c->tilt_code = 0.25f * (1.0f + voice_fac);

    /* innovation enhancement (7.14.5): the innovation gain is smoothed
     * in stable unvoiced segments, the innovation is high-pass filtered
     * in voiced ones */
    const float smooth = stab_fac * 0.5f * (1.0f - voice_fac);
    const float gain_code_sm = (1.0f - smooth) * gain_code + smooth * c->gain_code_prev;
    c->gain_code_prev = gain_code_sm;
    const float cpit = 0.125f * (1.0f + voice_fac);
    float code2[L_SUBFR];
    for (int n = 0; n < L_SUBFR; n++) {
      const float prev = n > 0 ? code[n - 1] : 0.0f;
      const float next = n + 1 < L_SUBFR ? code[n + 1] : 0.0f;
      code2[n] = code[n] - cpit * (prev + next);
    }

    /* excitation: the plain one feeds the adaptive codebook, the enhanced
     * one the synthesis */
    float exc2[L_SUBFR];
    for (int n = 0; n < L_SUBFR; n++) {
      exc[n] = gain_pit * v[n] + gain_code * code[n];
      exc2[n] = gain_pit * v[n] + gain_code_sm * code2[n];
    }
    lpd_syn_filter(a, exc2, out + sf * L_SUBFR, L_SUBFR, c->syn_mem);

    t_sf[sf] = t_int;
    g_sf[sf] = gain_pit > 1.0f ? 1.0f : (gain_pit < 0.0f ? 0.0f : gain_pit);

    /* shift the excitation history */
    memmove(c->exc, c->exc + L_SUBFR, sizeof(float) * (size_t)LPD_EXC_HIST);
    c->t0_prev = t0;
    c->gain_pit_prev = gain_pit;
  }
}
