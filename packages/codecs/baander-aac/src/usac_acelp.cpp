/* ACELP of the USAC LPD core (ISO/IEC 23003-3 7.14): the syntax, the
 * adaptive codebook with its 1/4 sample interpolation and low pass, the
 * algebraic innovation codebook of 12 to 64 bits, the joint gain
 * codebook with the energy prediction, the excitation post-processing
 * (tilt, pitch sharpening, gain smoothing), the synthesis in the
 * pre-emphasised domain, and the transition from a preceding TCX with
 * its FAC. */
#include <cmath>
#include <cstring>

#include "usac_lpd_internal.h"

#ifdef LPD_TRACE
#include <cstdio>
extern int lpd_trace_frame;
#define LPD_T(...) fprintf(stderr, __VA_ARGS__)
#else
#define LPD_T(...) ((void)0)
#endif

namespace {

constexpr int kIcbBits[8] = {20, 28, 36, 44, 52, 64, 12, 16};
constexpr int kIcbWidths[8][4] = {{5, 5, 5, 5},     {9, 9, 5, 5},     {9, 9, 9, 9},
                                  {13, 13, 9, 9},   {13, 13, 13, 13}, {0, 0, 0, 0},
                                  {1, 5, 1, 5},     {1, 5, 5, 5}};

}  // namespace

int lpd_acelp_icb_bits(int acelp_core_mode) { return kIcbBits[acelp_core_mode & 7]; }

int lpd_read_acelp(AacBitReader* r, int acelp_core_mode, int nb_subfr, LpdAcelpData* d) {
  d->mean_energy = (int)aac_bitreader_read(r, 2);
  for (int sf = 0; sf < nb_subfr; sf++) {
    const int absolute = sf == 0 || (nb_subfr == 4 && sf == 2);
    d->acb_index[sf] = (int)aac_bitreader_read(r, absolute ? 9 : 6);
    d->ltp_filtering_flag[sf] = (int)aac_bitreader_read(r, 1);
    memset(d->icb_index[sf], 0, sizeof(d->icb_index[sf]));
    if (acelp_core_mode == 5) {
      for (int t = 0; t < 4; t++) {
        d->icb_index[sf][t] = (int)aac_bitreader_read(r, 2);
      }
      for (int t = 0; t < 4; t++) {
        d->icb_index[sf][4 + t] = (int)aac_bitreader_read(r, 14);
      }
    } else {
      for (int t = 0; t < 4; t++) {
        d->icb_index[sf][t] = (int)aac_bitreader_read(r, kIcbWidths[acelp_core_mode & 7][t]);
      }
    }
    d->gains_index[sf] = (int)aac_bitreader_read(r, 7);
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* ── the innovation codebook ────────────────────────────────────── */

namespace {

/* A pulse at position `pos` of `track` (interleaved tracks of 16). */
void add_pulse(float* code, int track, int pos, int negative) {
  code[4 * pos + track] += negative ? -1.0f : 1.0f;
}

/* One pulse: m position bits and a sign bit above them. */
void pulses_1(float* code, int track, int index, int m, int offset) {
  const int pos = (index & ((1 << m) - 1)) + offset;
  add_pulse(code, track, pos, (index >> m) & 1);
}

/* Two pulses: two positions of m bits and one sign bit; the second
 * pulse takes the opposite sign when its position is below the first. */
void pulses_2(float* code, int track, int index, int m, int offset) {
  const int mask = (1 << m) - 1;
  const int pos0 = ((index >> m) & mask) + offset;
  const int pos1 = (index & mask) + offset;
  const int neg0 = (index >> (2 * m)) & 1;
  add_pulse(code, track, pos0, neg0);
  add_pulse(code, track, pos1, pos1 < pos0 ? !neg0 : neg0);
}

/* Three pulses: a pair in one half of the range (2m - 1 bits plus the
 * half) and one pulse over the whole range (m + 1 bits). */
void pulses_3(float* code, int track, int index, int m, int offset) {
  const int pair = index & ((1 << (2 * m - 1)) - 1);
  const int half = (index >> (2 * m - 1)) & 1 ? 1 << (m - 1) : 0;
  pulses_2(code, track, pair, m - 1, offset + half);
  pulses_1(code, track, (index >> (2 * m)) & ((1 << (m + 1)) - 1), m, offset);
}

/* Four pulses of one track in 16 bits: the two top bits pick the split
 * between the halves of the range. */
void pulses_4(float* code, int track, int index) {
  switch ((index >> 14) & 3) {
    case 0: {
      const int offset = (index >> 13) & 1 ? 8 : 0;
      const int quarter = (index >> 5) & 1 ? 4 : 0;
      pulses_2(code, track, index & 31, 2, offset + quarter);
      pulses_2(code, track, (index >> 6) & 127, 3, offset);
      break;
    }
    case 1:
      pulses_1(code, track, index >> 10, 3, 0);
      pulses_3(code, track, index, 3, 8);
      break;
    case 2:
      pulses_2(code, track, index >> 7, 3, 0);
      pulses_2(code, track, index, 3, 8);
      break;
    default:
      pulses_3(code, track, index >> 4, 3, 0);
      pulses_1(code, track, index, 3, 8);
      break;
  }
}

}  // namespace

void lpd_acelp_pulses(const int* icb_index, int bits, float* code) {
  memset(code, 0, LPD_SUBFR * sizeof(float));
  switch (bits) {
    case 12:
      /* two pulses, each on one of two tracks: a track bit and 5 bits */
      for (int p = 0; p < 2; p++) {
        pulses_1(code, 2 * (icb_index[2 * p] & 1) + p, icb_index[2 * p + 1], 4, 0);
      }
      break;
    case 16: {
      /* three pulses on the tracks but one, which the first bit names */
      const int skipped = icb_index[0] & 1 ? 3 : 1;
      int i = 1;
      for (int t = 0; t < 4; t++) {
        if (t != skipped) {
          pulses_1(code, t, icb_index[i++], 4, 0);
        }
      }
      break;
    }
    case 20:
      for (int t = 0; t < 4; t++) {
        pulses_1(code, t, icb_index[t], 4, 0);
      }
      break;
    case 28:
      for (int t = 0; t < 2; t++) {
        pulses_2(code, t, icb_index[t], 4, 0);
      }
      for (int t = 2; t < 4; t++) {
        pulses_1(code, t, icb_index[t], 4, 0);
      }
      break;
    case 36:
      for (int t = 0; t < 4; t++) {
        pulses_2(code, t, icb_index[t], 4, 0);
      }
      break;
    case 44:
      for (int t = 0; t < 2; t++) {
        pulses_3(code, t, icb_index[t], 4, 0);
      }
      for (int t = 2; t < 4; t++) {
        pulses_2(code, t, icb_index[t], 4, 0);
      }
      break;
    case 52:
      for (int t = 0; t < 4; t++) {
        pulses_3(code, t, icb_index[t], 4, 0);
      }
      break;
    case 64:
      for (int t = 0; t < 4; t++) {
        pulses_4(code, t, ((icb_index[t] & 3) << 14) + (icb_index[4 + t] & 16383));
      }
      break;
    default:
      break;
  }
}

/* ── the adaptive codebook ──────────────────────────────────────── */

void lpd_acelp_pitch(const AacUsacLpdChannel* c, int index, int absolute, int* t0, int* frac,
                     int* t0_min, int* t0_max) {
  if (!absolute) {
    *t0 = *t0_min + index / 4;
    *frac = index - (*t0 - *t0_min) * 4;
    return;
  }
  const int n_fr4 = (c->pit_fr2 - c->pit_min) * 4;
  const int n_fr2 = (c->pit_fr1 - c->pit_fr2) * 2;
  if (index < n_fr4) {
    *t0 = c->pit_min + index / 4;
    *frac = index - (*t0 - c->pit_min) * 4;
  } else if (index < n_fr4 + n_fr2) {
    const int i = index - n_fr4;
    *t0 = c->pit_fr2 + i / 2;
    *frac = 2 * (i - (*t0 - c->pit_fr2) * 2);
  } else {
    *t0 = index - n_fr4 - n_fr2 + c->pit_fr1;
    *frac = 0;
  }
  *t0_min = *t0 - 8;
  if (*t0_min < c->pit_min) {
    *t0_min = c->pit_min;
  }
  *t0_max = *t0_min + 15;
  if (*t0_max > c->pit_max) {
    *t0_max = c->pit_max;
    *t0_min = *t0_max - 15;
  }
}

void lpd_acelp_adaptive(float* exc, int t0, int frac, int n) {
  /* the sample t0 + frac/4 before: between x[-t0 - 1] and x[-t0] when
   * frac > 0, so the filter phase is 4 - frac on the earlier sample */
  const float* x0 = exc - t0;
  int phase = -frac;
  if (phase < 0) {
    phase += LPD_UP_SAMP;
    x0--;
  }
  for (int j = 0; j < n; j++) {
    const float* x1 = x0 + j;
    const float* x2 = x1 + 1;
    const float* c1 = lpd_interp_filter + phase;
    const float* c2 = lpd_interp_filter + LPD_UP_SAMP - phase;
    float s = 0.0f;
    for (int i = 0; i < LPD_INTERP_ORDER; i++) {
      s += x1[-i] * c1[LPD_UP_SAMP * i] + x2[i] * c2[LPD_UP_SAMP * i];
    }
    exc[j] = s;
  }
}

/* ── the frame ──────────────────────────────────────────────────── */

namespace {

/* The TCX to ACELP transition: the FAC of the boundary, synthesised
 * through the TCX's end filter, is added to the windowed TCX end, which
 * completes the last lfac samples before the segment, and the
 * excitation of those samples is recomputed for the adaptive codebook. */
void tcx_to_acelp(AacUsacLpdChannel* c, const LpdFrameData* f, int k, float* synth, float* exc) {
  const int lfac = c->lfac;
  float x[LPD_MAX_LFAC], xn[2 * LPD_MAX_LFAC];
  for (int i = 0; i < lfac; i++) {
    x[i] = c->fac_gain * (float)f->fac[k][i];
  }
  for (int i = 0; i < lfac / 4; i++) {
    x[i] *= c->alfd_gains[i];
  }
  lpd_fac_synth(x, lfac, c->a_prev[1], xn + lfac, lfac);
  memset(xn, 0, (size_t)lfac * sizeof(float));
  for (int i = 0; i < 2 * lfac; i++) {
    xn[i] += synth[i - 2 * lfac];
  }
  memcpy(synth - lfac, xn + lfac, (size_t)lfac * sizeof(float));
  /* the excitation of the last lfac samples: the pre-emphasis runs over
   * both halves from a zero memory */
  float pre[2 * LPD_MAX_LFAC];
  memcpy(pre, xn, (size_t)(2 * lfac) * sizeof(float));
  lpd_preemph(pre, 2 * lfac, 0.0f);
  float buf[LPD_M + LPD_MAX_LFAC];
  memcpy(buf, pre + lfac - LPD_M, (size_t)(LPD_M + lfac) * sizeof(float));
  const int head = lfac % LPD_SUBFR;
  int pos = 0, filt = 0;
  if (head != 0) {
    lpd_res_filter(c->a_prev[0], buf + LPD_M, exc - lfac, head);
    pos = head;
    filt = 1;
  }
  while (pos < lfac) {
    lpd_res_filter(c->a_prev[filt < 2 ? filt : 1], buf + LPD_M + pos, exc - lfac + pos, LPD_SUBFR);
    pos += LPD_SUBFR;
    filt++;
  }
}

}  // namespace

void lpd_acelp_decode(AacUsacLpdChannel* c, const LpdFrameData* f, int k,
                      const float a[][LPD_M + 1], float stab_fac, int* pitch, float* gain) {
  const LpdAcelpData* d = &f->acelp[k];
  const int bits = kIcbBits[f->acelp_core_mode & 7];
  float* synth = c->syn + LPD_SYN_HIST + k * c->l_div;
  float* exc = c->exc + LPD_EXC_HIST + k * c->l_div;

  if (c->mode_prev > 0) {
    tcx_to_acelp(c, f, k, synth, exc);
  }
  /* the synthesis memory: the last 16 samples, pre-emphasised */
  float mem[LPD_M];
  for (int i = 0; i < LPD_M; i++) {
    mem[i] = synth[i - LPD_M] - LPD_PREEMPH * synth[i - LPD_M - 1];
  }
  const float mean_energy = 12.0f * (float)d->mean_energy + 18.0f;
  int t0 = 0, frac = 0, t0_min = 0, t0_max = 0;
  for (int sf = 0; sf < c->nb_subfr; sf++) {
    const int off = sf * LPD_SUBFR;
    float* e = exc + off;
    const int absolute = sf == 0 || (c->nb_subfr == 4 && sf == 2);
    lpd_acelp_pitch(c, d->acb_index[sf], absolute, &t0, &frac, &t0_min, &t0_max);
    /* the adaptive codebook, one sample more for the low pass */
    lpd_acelp_adaptive(e, t0, frac, LPD_SUBFR + 1);
    float code[LPD_SUBFR];
    if (d->ltp_filtering_flag[sf] == 0) {
      for (int i = 0; i < LPD_SUBFR; i++) {
        code[i] = 0.18f * e[i - 1] + 0.64f * e[i] + 0.18f * e[i + 1];
      }
      memcpy(e, code, sizeof(code));
    }
    /* the innovation: pulses, tilt, pitch sharpening */
    lpd_acelp_pulses(d->icb_index[sf], bits, code);
    for (int i = LPD_SUBFR - 1; i > 0; i--) {
      code[i] -= LPD_TILT_CODE * code[i - 1];
    }
    const int t_int = t0 + (frac > 2 ? 1 : 0);
    for (int i = t_int; i < LPD_SUBFR; i++) {
      code[i] += LPD_PIT_SHARP * code[i - t_int];
    }
    /* the gains: the pitch gain from the codebook, the innovation gain
     * as the codebook's factor on the predicted energy */
    float ener = 0.01f;
    for (int i = 0; i < LPD_SUBFR; i++) {
      ener += code[i] * code[i];
    }
    const int gi = d->gains_index[sf] & 127;
    const float gain_pit = lpd_gain_table[gi][0];
    const float est = mean_energy - (float)(10.0 * log10(ener / (double)LPD_SUBFR));
    const float gain_code = lpd_gain_table[gi][1] * (float)pow(10.0, 0.05 * (double)est);
    LPD_T("XLPD f=%d k=%d sf=%d T=%d frac=%d gp=%.5f gc=%.5f ltp=%d code0=%.2f code1=%.2f\n", lpd_trace_frame, k, sf, t0, frac, gain_pit, gain_code, d->ltp_filtering_flag[sf], code[0], code[1]);
    /* the voicing factor of the two contributions */
    float ener_pit = 0.0f;
    for (int i = 0; i < LPD_SUBFR; i++) {
      ener_pit += e[i] * e[i];
    }
    ener_pit *= gain_pit * gain_pit;
    const float ener_code = ener * gain_code * gain_code;
    const float r_v = (ener_pit - ener_code) / (ener_pit + ener_code);
    float post[LPD_SUBFR];
    for (int i = 0; i < LPD_SUBFR; i++) {
      post[i] = gain_pit * e[i];
      e[i] = gain_pit * e[i] + gain_code * code[i];
    }
    pitch[sf] = t_int > c->pit_max ? c->pit_max : t_int;
    gain[sf] = gain_pit;
    /* gain smoothing toward a slowly moving threshold, by the stability
     * and the voicing */
    const float smooth = stab_fac * 0.5f * (1.0f - r_v);
    float g0 = gain_code;
    if (g0 < c->gain_threshold) {
      g0 *= 1.19f;
      if (g0 > c->gain_threshold) {
        g0 = c->gain_threshold;
      }
    } else {
      g0 /= 1.19f;
      if (g0 < c->gain_threshold) {
        g0 = c->gain_threshold;
      }
    }
    c->gain_threshold = g0;
    const float gain_smooth = smooth * g0 + (1.0f - smooth) * gain_code;
    for (int i = 0; i < LPD_SUBFR; i++) {
      code[i] *= gain_smooth;
    }
    /* the innovation's high pass by the voicing */
    const float cpe = 0.125f * (1.0f + r_v);
    post[0] += code[0] - cpe * code[1];
    for (int i = 1; i < LPD_SUBFR - 1; i++) {
      post[i] += code[i] - cpe * (code[i - 1] + code[i + 1]);
    }
    post[LPD_SUBFR - 1] += code[LPD_SUBFR - 1] - cpe * code[LPD_SUBFR - 2];
    /* the synthesis in the pre-emphasised domain */
    float buf[LPD_M + LPD_SUBFR];
    memcpy(buf, mem, sizeof(mem));
    memcpy(buf + LPD_M, post, sizeof(post));
    lpd_syn_filter(a[sf], buf + LPD_M, LPD_SUBFR);
    memcpy(synth + off, buf + LPD_M, LPD_SUBFR * sizeof(float));
    memcpy(mem, buf + LPD_SUBFR, sizeof(mem));

  }
  /* the zero input response of the end filter from the pre-emphasised
   * memory, then the de-emphasis of the segment and the response */
  float zir[LPD_M + LPD_MAX_LFAC];
  memcpy(zir, mem, sizeof(mem));
  memset(zir + LPD_M, 0, LPD_MAX_LFAC * sizeof(float));
  lpd_syn_filter(a[c->nb_subfr], zir + LPD_M, c->lfac);
  lpd_deemph(synth, c->l_div, synth[-1]);
  memcpy(c->ovl, synth + c->l_div - 1 - c->lfac, (size_t)(1 + c->lfac) * sizeof(float));
  memcpy(c->ovl + 1 + c->lfac, zir + LPD_M, (size_t)c->lfac * sizeof(float));
  lpd_deemph(c->ovl + 1 + c->lfac, c->lfac, synth[c->l_div - 1]);
  memcpy(c->a_prev[0], a[c->nb_subfr - 2], sizeof(c->a_prev[0]));
  memcpy(c->a_prev[1], a[c->nb_subfr - 1], sizeof(c->a_prev[1]));
}
