/* TCX of the USAC LPD core (ISO/IEC 23003-3 7.15): the arithmetic coded
 * spectrum, the noise filling, the adaptive low frequency de-emphasis,
 * the LPC envelope shaping of the spectrum, the global gain, the inverse
 * transform with the sine slopes of 2 lfac at both ends, the overlap with
 * what precedes (a TCX tail, the FD overlap, or an ACELP segment folded
 * through its FAC), and the excitation the segment leaves for a
 * following ACELP. */
#include <cfloat>
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

constexpr double kPi = 3.14159265358979323846;

/* A random sign from the channel's generator. */
float random_sign(unsigned* seed) {
  *seed = *seed * 69069u + 5u;
  return (*seed & 0x10000u) ? -1.0f : 1.0f;
}

/* The LPC envelope 1 / |A(z / gamma)| at m frequencies between the DC
 * and the Nyquist: the odd multiples of pi / 2m. */
void envelope(const float* a, int m, float* g) {
  float ap[LPD_M + 1];
  lpd_weight_a(a, LPD_GAMMA, ap);
  for (int k = 0; k < m; k++) {
    const double w = kPi * (2 * k + 1) / (2.0 * m);
    double re = 0.0, im = 0.0;
    for (int i = 0; i <= LPD_M; i++) {
      re += ap[i] * cos(w * i);
      im -= ap[i] * sin(w * i);
    }
    g[k] = (float)(1.0 / (sqrt(re * re + im * im) + FLT_EPSILON));
  }
}

/* The excitation of the lfac samples before the segment from the
 * synthesis around it, through the two filters the last segment left. */
void boundary_excitation(const AacUsacLpdChannel* c, const float* synth, float* exc) {
  const int lfac = c->lfac;
  float buf[LPD_M + LPD_MAX_LFAC];
  for (int i = 0; i < LPD_M + lfac; i++) {
    buf[i] = synth[i - LPD_M - lfac] - LPD_PREEMPH * synth[i - LPD_M - lfac - 1];
  }
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

int lpd_read_tcx(AacBitReader* r, int lg, int first_tcx, int indep, AacUsacLpdChannel* c,
                 LpdFrameData* f, int k) {
  LpdTcxData* t = &f->tcx[k];
  t->lg = lg;
  t->noise_factor = (int)aac_bitreader_read(r, 3);
  t->global_gain = (int)aac_bitreader_read(r, 7);
  if (first_tcx) {
    f->arith_reset = indep ? 1 : (int)aac_bitreader_read(r, 1);
  }
  const int ret = aac_usac_arith_decode(&c->arith, first_tcx && f->arith_reset, lg, lg, r, t->q);
  if (ret != AAC_OK) {
    return ret;
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

void lpd_tcx_decode(AacUsacLpdChannel* c, const LpdFrameData* f, int k, const float* a_old,
                    const float* a_new) {
  const LpdTcxData* t = &f->tcx[k];
  const int lg = t->lg;
  const int lfac = c->lfac;
  const int mode = lg / c->l_div; /* 1, 2 or 4 */
  float* synth = c->syn + LPD_SYN_HIST + k * c->l_div;
  float* exc = c->exc + LPD_EXC_HIST + k * c->l_div;
  /* the slope toward what precedes: 2 lfac, or the FD frame's short
   * window after an EIGHT_SHORT_SEQUENCE */
  const int fp = c->mode_prev == -2 ? c->frame_length / 16 : lfac;
  float w[2 * LPD_MAX_LFAC], wp[2 * LPD_MAX_LFAC];
  lpd_sine_window(2 * lfac, w);
  lpd_sine_window(2 * fp, wp);

  /* the previous overlap: a TCX tail is windowed here, the FD overlap
   * came windowed, an ACELP end enters through the FAC */
  if (c->mode_prev > 0) {
    for (int i = 0; i < 2 * fp; i++) {
      c->ovl[i + lfac - fp + 1] *= wp[2 * fp - 1 - i];
    }
  }
  if (c->mode_prev != 0) {
    for (int i = 0; i < lfac - fp; i++) {
      c->ovl[i + lfac + fp + 1] = 0.0f;
    }
  }

  /* the spectrum: noise filling of the empty 8-tuples above lg / 6 */
  float x[LPD_MAX_TCX];
  const float noise_level = 0.0625f * (8.0f - (float)t->noise_factor);
  for (int i = 0; i < lg; i++) {
    x[i] = (float)t->q[i];
  }
  for (int i = lg / 6; i < lg; i += 8) {
    const int end = i + 8 < lg ? i + 8 : lg;
    int empty = 1;
    for (int j = i; j < end; j++) {
      empty = empty && t->q[j] == 0;
    }
    if (empty) {
      for (int j = i; j < end; j++) {
        x[j] = noise_level * random_sign(c->noise_seed);
      }
    }
  }
  /* adaptive low frequency de-emphasis: the lowest quarter in bands of
   * eight, each scaled by its level against the loudest, with the
   * factor never falling */
  float alfd[LPD_MAX_TCX / 32];
  {
    const int top = lg / 4;
    float max = 0.01f;
    for (int i = 0; i < top; i += 8) {
      float rm = 0.01f;
      for (int j = i; j < i + 8; j++) {
        rm += x[j] * x[j];
      }
      if (rm > max) {
        max = rm;
      }
    }
    float factor = 0.1f;
    for (int i = 0; i < top; i += 8) {
      float rm = 0.01f;
      for (int j = i; j < i + 8; j++) {
        rm += x[j] * x[j];
      }
      rm = (float)sqrt(rm / max);
      if (rm > factor) {
        factor = rm;
      }
      for (int j = i; j < i + 8; j++) {
        x[j] *= factor;
      }
      alfd[i / 8] = factor;
    }
  }
  /* the envelopes of the filters at both ends, at l_div / 4 points */
  const int m = c->l_div / 4;
  float g1[LPD_MAX_DIV / 4], g2[LPD_MAX_DIV / 4];
  envelope(a_old, m, g1);
  envelope(a_new, m, g2);
  /* the gain: global_gain in quarter dB steps against the rms of the
   * spectrum before shaping */
  float energy = 0.01f;
  for (int i = 0; i < lg; i++) {
    energy += x[i] * x[i];
  }
  const float rms = (float)sqrt(energy) / (float)lg;
  const float gain = (float)pow(10.0, (double)t->global_gain / 28.0) / (rms * 2.0f);
  /* the shaping: a first order filter across frequency whose
   * coefficients follow the two envelopes band by band */
  {
    const int per_band = lg / m;
    float prev = 0.0f, ca = 0.0f, cb = 0.0f;
    for (int i = 0; i < lg; i++) {
      if (i % per_band == 0) {
        const int b = i / per_band;
        ca = 2.0f * g1[b] * g2[b] / (g1[b] + g2[b]);
        cb = (g2[b] - g1[b]) / (g1[b] + g2[b]);
      }
      x[i] = ca * x[i] + cb * prev;
      prev = x[i];
    }
  }
  /* the inverse transform: the lg + 2 lfac samples around the segment
   * are the middle of the 2 lg sample IMDCT, at twice its scale */
  float y[2 * LPD_MAX_TCX];
  float xn_buf[LPD_MAX_TCX + 2 * LPD_MAX_LFAC];
  float* xn = xn_buf + lfac;
  aac_imdct(&c->mdct[mode == 4 ? 2 : mode - 1], x, y);
  const float scale = 2.0f * gain;
  for (int i = 0; i < lg + 2 * lfac; i++) {
    xn_buf[i] = scale * y[i - lfac + lg / 2];
  }
  c->fac_gain = gain * 0.5f * (float)sqrt((double)lfac / (double)lg);
  LPD_T("XLPD f=%d k=%d tcx gain_tcx=%.6f energy=%.3f noise_level=%.4f mode_prev=%d xn0=%.6f xn1=%.6f xnmid=%.6f\n", lpd_trace_frame, k, gain, energy, noise_level, c->mode_prev, xn_buf[0] / gain, xn_buf[1] / gain, xn_buf[lg / 2] / gain);
  for (int i = 0; i < lfac / 4; i++) {
    c->alfd_gains[i] = alfd[i * lg / (8 * lfac)];
  }

  /* the FAC after an ACELP segment: its synthesis through the old
   * filter plus the ACELP's end and zero input response folded into the
   * aliasing region */
  float xn1[2 * LPD_MAX_LFAC];
  if (c->mode_prev == 0) {
    float xf[LPD_MAX_LFAC], past_w[LPD_MAX_LFAC], zir_w[LPD_MAX_LFAC];
    for (int i = 0; i < lfac; i++) {
      xf[i] = c->fac_gain * (float)f->fac[k][i];
    }
    for (int i = 0; i < lfac / 4; i++) {
      xf[i] *= c->alfd_gains[i];
    }
    lpd_fac_synth(xf, lfac, a_old, xn1, 2 * lfac);
    lpd_fac_windows(lfac, past_w, zir_w);
    for (int i = 0; i < lfac; i++) {
      xn1[i] += c->ovl[1 + lfac + i] * zir_w[i] + c->ovl[lfac - i] * past_w[lfac - 1 - i];
    }
  }
  /* the left slope and the overlap */
  for (int i = 0; i < 2 * fp; i++) {
    xn_buf[i + lfac - fp] *= wp[i];
  }
  for (int i = 0; i < lfac - fp; i++) {
    xn_buf[i] = 0.0f;
  }
  if (c->mode_prev != 0) {
    for (int i = lfac - fp; i < lfac + fp; i++) {
      xn_buf[i] += c->ovl[1 + i];
    }
  } else {
    for (int i = 0; i < 2 * lfac; i++) {
      xn_buf[lfac + i] += xn1[i];
    }
  }
  /* the tail for the next segment, before the right slope */
  memcpy(c->ovl, xn_buf + lg - 1, (size_t)(1 + 2 * lfac) * sizeof(float));
  for (int i = 0; i < 2 * lfac; i++) {
    xn_buf[lg + i] *= w[2 * lfac - 1 - i];
  }
  /* the completed samples before the segment and their excitation */
  if (c->mode_prev != 0) {
    memcpy(synth - fp, xn_buf + lfac - fp, (size_t)fp * sizeof(float));
    boundary_excitation(c, synth, exc);
  }
  memcpy(synth, xn, (size_t)lg * sizeof(float));
  /* the excitation of the segment through the new filter */
  {
    float buf[LPD_M + LPD_MAX_TCX];
    for (int i = 0; i < LPD_M + lg; i++) {
      buf[i] = synth[i - LPD_M] - LPD_PREEMPH * synth[i - LPD_M - 1];
    }
    lpd_res_filter(a_new, buf + LPD_M, exc, lg);
  }
  memcpy(c->a_prev[0], a_new, sizeof(c->a_prev[0]));
  memcpy(c->a_prev[1], a_new, sizeof(c->a_prev[1]));
}
