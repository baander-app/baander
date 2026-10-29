/* The USAC linear prediction domain core (ISO/IEC 23003-3 6.2.7, 7.11,
 * 7.12, 7.17): lpd_channel_stream() with its mode field, the frame
 * synthesis over the four subframes with the LPC sets interpolated
 * between them, the transitions from and to the frequency domain core,
 * the bass postfilter, and the core channel contract. */
#include <cmath>
#include <cstdlib>
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

/* The LPD synthesis runs at the scale of the FD core's transform output
 * (the scale the TCX and ACELP gains are defined at, 32768 at full
 * scale); the core output and ch->overlap exchange it unscaled, the
 * delivered frame is at full scale 1.0. */
constexpr float kOutputScale = 1.0f / 32768.0f;
constexpr float kInputScale = 32768.0f;

float* syn0(AacUsacLpdChannel* c) { return c->syn + LPD_SYN_HIST; }
float* exc0(AacUsacLpdChannel* c) { return c->exc + LPD_EXC_HIST; }

/* The pitch range at the core rate: the offset of the sampling rate
 * against 12.8 kHz scales the lag limits. */
void pitch_range(AacUsacLpdChannel* c) {
  const int i = (c->core_sr * 34 + 6400) / 12800 - 34;
  c->pit_min = 34 + i;
  c->pit_fr2 = 128 - i;
  c->pit_fr1 = 160;
  c->pit_max = 231 + 6 * i;
  if (c->pit_max > LPD_PIT_MAX) {
    c->pit_max = LPD_PIT_MAX;
  }
}

}  // namespace

/* ── the channel ────────────────────────────────────────────────── */

extern "C" AacUsacLpdChannel* aac_usac_lpd_channel_create(int frame_length, int core_sample_rate) {
  if ((frame_length != 1024 && frame_length != 768) || core_sample_rate <= 0) {
    return nullptr;
  }
  AacUsacLpdChannel* c = (AacUsacLpdChannel*)calloc(1, sizeof(AacUsacLpdChannel));
  if (!c) {
    return nullptr;
  }
  c->frame_length = frame_length;
  c->l_div = frame_length / 4;
  c->nb_subfr = c->l_div / LPD_SUBFR;
  c->lfac = c->l_div / 2;
  c->nb_slots = frame_length / LPD_SUBFR;
  c->synth_delay = (c->nb_slots / 2 - 1) * LPD_SUBFR;
  c->core_sr = core_sample_rate;
  pitch_range(c);
  for (int m = 0; m < 3; m++) {
    aac_mdct_init(&c->mdct[m], 2 * c->l_div << m);
  }
  aac_usac_lpd_channel_reset(c);
  return c;
}

extern "C" void aac_usac_lpd_channel_destroy(AacUsacLpdChannel* c) { free(c); }

extern "C" void aac_usac_lpd_channel_reset(AacUsacLpdChannel* c) {
  memset(c->syn, 0, sizeof(c->syn));
  memset(c->exc, 0, sizeof(c->exc));
  memset(c->ovl, 0, sizeof(c->ovl));
  memset(c->a_prev, 0, sizeof(c->a_prev));
  memset(c->alfd_gains, 0, sizeof(c->alfd_gains));
  memset(c->bpf_noise, 0, sizeof(c->bpf_noise));
  c->fac_gain = 0.0f;
  c->gain_threshold = 0.0f;
  c->bpf_active_prev = 0;
  for (int i = 0; i < LPD_MAX_SLOTS / 2; i++) {
    c->pitch_prev[i] = LPD_SUBFR;
    c->gain_prev[i] = 0.0f;
  }
  /* the LSP memory starts at zero: the LSFs at the middle of the scale */
  memset(c->lsp_prev, 0, sizeof(c->lsp_prev));
  lpd_lsp_to_lsf(c->lsp_prev, c->lsf_prev);
  c->mode_prev = -1;
  aac_usac_arith_reset(&c->arith);
  c->noise_seed = nullptr;
  c->conceal_gain = 1.0f;
  c->have_frame = 0;
}

/* ── the syntax ─────────────────────────────────────────────────── */

int lpd_modes(int lpd_mode, int* mod) {
  if (lpd_mode < 0 || lpd_mode > 25) {
    return AAC_ERR_DECODE;
  }
  if (lpd_mode == 25) {
    mod[0] = mod[1] = mod[2] = mod[3] = 3;
  } else if (lpd_mode == 24) {
    mod[0] = mod[1] = mod[2] = mod[3] = 2;
  } else if (lpd_mode >= 20) {
    mod[0] = lpd_mode & 1;
    mod[1] = (lpd_mode >> 1) & 1;
    mod[2] = mod[3] = 2;
  } else if (lpd_mode >= 16) {
    mod[0] = mod[1] = 2;
    mod[2] = lpd_mode & 1;
    mod[3] = (lpd_mode >> 1) & 1;
  } else {
    for (int k = 0; k < 4; k++) {
      mod[k] = (lpd_mode >> k) & 1;
    }
  }
  return AAC_OK;
}

namespace {

int parse_frame(AacUsacLpdChannel* c, AacBitReader* r, int indep, LpdFrameData* f) {
  int ret;
  f->acelp_core_mode = (int)aac_bitreader_read(r, 3);
  f->lpd_mode = (int)aac_bitreader_read(r, 5);
  if ((ret = lpd_modes(f->lpd_mode, f->mod)) != AAC_OK) {
    return ret;
  }
  f->bpf_control_info = (int)aac_bitreader_read(r, 1);
  f->core_mode_last = (int)aac_bitreader_read(r, 1);
  f->fac_data_present = (int)aac_bitreader_read(r, 1);
  f->first_lpd_flag = !f->core_mode_last;
  f->arith_reset = 0;
  LPD_T("XLPD f=%d hdr acelp_core_mode=%d lpd_mode=%d mod=%d%d%d%d bpf=%d core_mode_last=%d fac_present=%d left=%d\n", lpd_trace_frame, f->acelp_core_mode, f->lpd_mode, f->mod[0], f->mod[1], f->mod[2], f->mod[3], f->bpf_control_info, f->core_mode_last, f->fac_data_present, aac_bitreader_bits_left(r));
  memset(f->fac_present, 0, sizeof(f->fac_present));
  int last = c->mode_prev;
  int first_tcx = 1;
  int k = 0;
  while (k < 4) {
    /* a FAC at every ACELP/TCX boundary, which at the frame start the
     * flag announces */
    const int fac = k == 0 ? (f->core_mode_last && f->fac_data_present)
                           : ((last == 0 && f->mod[k] > 0) || (last > 0 && f->mod[k] == 0));
    if (fac) {
      f->fac_present[k] = 1;
      if ((ret = lpd_read_fac(r, c->lfac, f->fac[k])) != AAC_OK) {
        return ret;
      }
    }
    LPD_T("XLPD f=%d k=%d before_core left=%d\n", lpd_trace_frame, k, aac_bitreader_bits_left(r));
    if (f->mod[k] == 0) {
      if ((ret = lpd_read_acelp(r, f->acelp_core_mode, c->nb_subfr, &f->acelp[k])) != AAC_OK) {
        return ret;
      }
      LPD_T("XLPD f=%d k=%d acelp mean_energy=%d left=%d\n", lpd_trace_frame, k, f->acelp[k].mean_energy, aac_bitreader_bits_left(r));
      last = 0;
      k++;
    } else {
      const int lg = c->l_div << (f->mod[k] - 1);
      if ((ret = lpd_read_tcx(r, lg, first_tcx, indep, c, f, k)) != AAC_OK) {
        return ret;
      }
#ifdef LPD_TRACE
      LPD_T("XLPD f=%d k=%d tcx lg=%d noise_factor=%d global_gain=%d arith_reset=%d first=%d left=%d\n", lpd_trace_frame, k, lg, f->tcx[k].noise_factor, f->tcx[k].global_gain, f->arith_reset, first_tcx, aac_bitreader_bits_left(r));
      LPD_T("XLPD f=%d k=%d tcxq", lpd_trace_frame, k);
      for (int ii = 0; ii < lg; ii++) LPD_T(" %d", f->tcx[k].q[ii]);
      LPD_T("\n");
#endif
      last = f->mod[k];
      k += 1 << (f->mod[k] - 1);
      first_tcx = 0;
    }
  }
  if ((ret = lpd_read_lpc_data(r, f)) != AAC_OK) {
    return ret;
  }
  LPD_T("XLPD f=%d lpc_data left=%d\n", lpd_trace_frame, aac_bitreader_bits_left(r));
  f->fd_fac_short = 0;
  f->fd_fac_gain = 0;
  if (!f->core_mode_last && f->fac_data_present) {
    f->fd_fac_short = (int)aac_bitreader_read(r, 1);
    const int length = f->fd_fac_short ? c->frame_length / 16 : c->frame_length / 8;
    f->fd_fac_gain = (int)aac_bitreader_read(r, 7);
    if ((ret = lpd_read_fac(r, length, f->fd_fac)) != AAC_OK) {
      return ret;
    }
    LPD_T("XLPD f=%d fdfac short=%d gain=%d left=%d\n", lpd_trace_frame, f->fd_fac_short, f->fd_fac_gain, aac_bitreader_bits_left(r));
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* ── the bass postfilter (7.17) ─────────────────────────────────── */

/* Filters `len` samples: s is the signal with the slots at multiples of
 * 64 and the history before it, out[i] = s[i - 64] minus the low passed
 * pitch enhancement, whose filter delay of one slot the noise memory
 * carries across calls; `len2` bounds the look-ahead to the samples
 * that exist beyond `len`. */
void bass_postfilter(const float* s, const int* pitch, const float* pitch_gain, int len,
                     int len2, float* noise_mem, float* out) {
  memcpy(out, s - LPD_SUBFR, (size_t)len * sizeof(float));
  float noise[LPD_BPF_DELAY + 2 * LPD_SUBFR];
  float* cur = noise + LPD_BPF_DELAY + LPD_SUBFR;
  for (int n = 0, sf = 0; n < len; n += LPD_SUBFR, sf++) {
    int t = pitch[sf];
    float gain = pitch_gain[sf];
    if (gain > 1.0f) {
      gain = 1.0f;
    }
    if (gain < 0.0f) {
      gain = 0.0f;
    }
    /* the lag is halved when the signal correlates with its half */
    {
      const float* x = s + n - 96;
      const float* y = s + n - t / 2 - 96;
      float xx = 0.01f, xy = 0.01f, yy = 0.01f;
      for (int i = 0; i < LPD_SUBFR + 96; i++) {
        xx += x[i] * x[i];
        xy += x[i] * y[i];
        yy += y[i] * y[i];
      }
      if (xy / (float)sqrt(xx * yy) > 0.95f) {
        t >>= 1;
      }
    }
    int lg = len + len2 - t - n;
    if (lg < 0) {
      lg = 0;
    }
    if (lg > LPD_SUBFR) {
      lg = LPD_SUBFR;
    }
    if (gain > 0.0f) {
      if (lg > 0) {
        float e0 = 0.01f, e1 = 0.01f;
        for (int i = 0; i < lg; i++) {
          e0 += s[n + i] * s[n + i];
          e1 += s[n + i + t] * s[n + i + t];
        }
        const float g = (float)sqrt(e0 / e1);
        if (g < gain) {
          gain = g;
        }
      }
      const float alpha = 0.5f * gain;
      for (int i = 0; i < lg; i++) {
        cur[i] = alpha * (s[n + i] - 0.5f * s[n + i - t] - 0.5f * s[n + i + t]);
      }
      for (int i = lg; i < LPD_SUBFR; i++) {
        cur[i] = alpha * (s[n + i] - s[n + i - t]);
      }
    } else {
      memset(cur, 0, LPD_SUBFR * sizeof(float));
    }
    memcpy(noise, noise_mem, (LPD_BPF_DELAY + LPD_SUBFR) * sizeof(float));
    memcpy(noise_mem, noise + LPD_SUBFR, (LPD_BPF_DELAY + LPD_SUBFR) * sizeof(float));
    const float* prev = noise + LPD_BPF_DELAY;
    for (int i = 0; i < LPD_SUBFR; i++) {
      float v = lpd_bpf_filter[0] * prev[i];
      for (int j = 1; j <= LPD_BPF_DELAY; j++) {
        v += lpd_bpf_filter[j] * (prev[i - j] + prev[i + j]);
      }
      out[n + i] -= v;
    }
  }
}

/* The pitch gain of a slot refined on the synthesis itself. */
void refine_gain(const float* s, int tp, float* gain) {
  if (*gain <= 0.0f) {
    return;
  }
  float corr = 0.0f, energy = 1e-6f;
  for (int i = 0; i < LPD_SUBFR; i++) {
    corr += s[i] * s[i - tp];
    energy += s[i - tp] * s[i - tp];
  }
  *gain = corr / energy;
}

/* ── the transition from the frequency domain core ──────────────── */

/* Prepares the channel for an LPD frame after an FD frame: the FD
 * overlap gets the LPD slope of 2 fp (fp = lfac, or N / 16 after short
 * windows) toward the superframe and feeds the overlap buffer, the
 * memories start afresh. */
void from_fd(AacUsacLpdChannel* c, const AacUsacCoreChannel* ch, float* fd, int* fp_out) {
  const int n = c->frame_length;
  const int short_prev =
      ch->prev_core_mode == 0 && ch->prev_window_sequence == AAC_WIN_EIGHT_SHORT;
  const int fp = short_prev ? n / 16 : c->lfac;
  c->mode_prev = short_prev ? -2 : -1;
  for (int i = 0; i < LPD_MAX_SLOTS / 2; i++) {
    c->pitch_prev[i] = LPD_SUBFR;
    c->gain_prev[i] = 0.0f;
  }
  c->bpf_active_prev = 0;
  memset(c->syn, 0, sizeof(c->syn));
  memset(c->exc, 0, sizeof(c->exc));
  memset(c->a_prev, 0, sizeof(c->a_prev));
  memset(c->bpf_noise, 0, sizeof(c->bpf_noise));
  c->gain_threshold = 0.0f;
  if (ch->prev_core_mode == 0) {
    memcpy(fd, ch->overlap, (size_t)n * sizeof(float));
  } else {
    memset(fd, 0, (size_t)n * sizeof(float));
  }
  float w[2 * LPD_MAX_LFAC];
  lpd_sine_window(2 * fp, w);
  for (int i = 0; i < 2 * fp; i++) {
    fd[n / 2 - fp + i] *= w[2 * fp - 1 - i];
  }
  memset(fd + n / 2 + fp, 0, (size_t)(n / 2 - fp) * sizeof(float));
  memset(c->ovl, 0, sizeof(c->ovl));
  for (int i = 0; i <= 2 * fp; i++) {
    c->ovl[c->lfac - fp + i] = fd[n / 2 - fp - 1 + i];
  }
  *fp_out = fp;
}

/* The FAC of an FD to ACELP transition and the ACELP memories from the
 * FD signal: the synthesis history, and the excitation as the residual
 * of the pre-emphasised signal through the first LPC set. */
void fd_memories(AacUsacLpdChannel* c, const LpdFrameData* f, float* fd, int fp) {
  const int n = c->frame_length;
  float a0[LPD_M + 1];
  lpd_lsp_to_a(c->lsp_prev, a0);
  memcpy(c->a_prev[0], a0, sizeof(a0));
  memcpy(c->a_prev[1], a0, sizeof(a0));
  if (f->mod[0] == 0) {
    if (f->fac_data_present) {
      float x[LPD_MAX_LFAC], y[LPD_MAX_LFAC];
      const float gain = (float)pow(10.0, (double)f->fd_fac_gain / 28.0);
      for (int i = 0; i < fp; i++) {
        x[i] = gain * (float)f->fd_fac[i];
      }
      lpd_fac_synth(x, fp, a0, y, fp);
      for (int i = 0; i < fp; i++) {
        fd[n / 2 - fp + i] += y[i];
      }
    }
    memset(fd + n / 2, 0, (size_t)fp * sizeof(float));
  }
  memcpy(syn0(c) - n / 2, fd, (size_t)(n / 2) * sizeof(float));
  float pre[LPD_M + LPD_MAX_FRAME / 2], res[LPD_MAX_FRAME / 2];
  memset(pre, 0, LPD_M * sizeof(float));
  memcpy(pre + LPD_M, fd, (size_t)(n / 2) * sizeof(float));
  lpd_preemph(pre + LPD_M, n / 2, 0.0f);
  lpd_res_filter(a0, pre + LPD_M, res, n / 2);
  int num = f->mod[0] != 0 && (c->nb_subfr == 4 || f->mod[1] != 0) ? c->l_div : 2 * c->l_div;
  if (num > LPD_PIT_MAX + LPD_INTERP_ORDER + 1) {
    num = LPD_PIT_MAX + LPD_INTERP_ORDER + 1;
  }
  memcpy(exc0(c) - num, res + n / 2 - num, (size_t)num * sizeof(float));
}

/* ── the frame ──────────────────────────────────────────────────── */

void decode_frame(AacUsacLpdChannel* c, AacUsacCoreChannel* ch, const LpdFrameData* f,
                  float* out) {
  const int n = c->frame_length;
  const int s0 = c->nb_slots / 2 - 1;
  float fd[LPD_MAX_FRAME];
  int fp = 0;
  const int after_fd = ch->prev_core_mode != 1;
  if (after_fd) {
    from_fd(c, ch, fd, &fp);
  }
  memset(syn0(c), 0, (size_t)(n + c->lfac) * sizeof(float));
  memset(exc0(c), 0, (size_t)(n + 1) * sizeof(float));

  /* the LPC sets */
  float lsf[5][LPD_M];
  lpd_lsf_decode(c, f, lsf);
#ifdef LPD_TRACE
  for (int a = 0; a < 5; a++) {
    LPD_T("XLPD f=%d lsf%d", lpd_trace_frame, a);
    for (int b = 0; b < LPD_M; b++) LPD_T(" %.6f", lsf[a][b]);
    LPD_T("\n");
  }
#endif
  if (f->first_lpd_flag) {
    memcpy(c->lsf_prev, lsf[0], sizeof(c->lsf_prev));
    lpd_lsf_to_lsp(c->lsf_prev, c->lsp_prev);
  }
  if (after_fd) {
    if (f->mod[0] == 0 || f->mod[1] == 0 || (f->mod[2] == 0 && c->nb_subfr == 3)) {
      fd_memories(c, f, fd, fp);
    }
  }

  /* the pitch slots: the second half of the last superframe, then this one */
  int pitch[LPD_MAX_SLOTS / 2 + LPD_MAX_SLOTS];
  float gain[LPD_MAX_SLOTS / 2 + LPD_MAX_SLOTS];
  for (int i = 0; i < s0; i++) {
    pitch[i] = c->pitch_prev[i];
    gain[i] = c->gain_prev[i];
  }
  for (int i = 0; i < c->nb_slots; i++) {
    pitch[s0 + i] = LPD_SUBFR;
    gain[s0 + i] = 0.0f;
  }

  int k = 0;
  while (k < 4) {
    const int mode = f->mod[k];
    if (c->mode_prev == 0 && mode > 0 && (k != 0 || c->bpf_active_prev)) {
      /* the ACELP's pitch carries into the first two slots of the TCX */
      const int i = k * c->nb_subfr + s0;
      pitch[i + 1] = pitch[i] = pitch[i - 1];
      gain[i + 1] = gain[i] = gain[i - 1];
    }
    const int set = mode <= 1 ? k + 1 : (mode == 2 ? k + 2 : 4);
    float lsp[LPD_M];
    lpd_lsf_to_lsp(lsf[set], lsp);
    const float stab = lpd_stab_fac(lsf[set], c->lsf_prev);
    LPD_T("XLPD f=%d k=%d mode=%d mode_prev=%d stab=%.5f\n", lpd_trace_frame, k, mode, c->mode_prev, stab);
    if (mode == 0) {
      float a[LPD_MAX_SUBFR + 1][LPD_M + 1];
      lpd_interp_lsp(c->lsp_prev, lsp, c->nb_subfr, a);
      const int i = k * c->nb_subfr + s0;
      lpd_acelp_decode(c, f, k, a, stab, pitch + i, gain + i);
      if (c->mode_prev != 0 && f->bpf_control_info) {
        /* and back into the slots before it */
        pitch[i - 1] = pitch[i];
        gain[i - 1] = gain[i];
        if (c->mode_prev != -2) {
          pitch[i - 2] = pitch[i];
          gain[i - 2] = gain[i];
        }
      }
      k++;
    } else {
      float a_old[LPD_M + 1], a_new[LPD_M + 1];
      lpd_lsp_to_a(c->lsp_prev, a_old);
      lpd_lsp_to_a(lsp, a_new);
      lpd_tcx_decode(c, f, k, a_old, a_new);
      k += 1 << (mode - 1);
    }
    c->mode_prev = mode;
    memcpy(c->lsp_prev, lsp, sizeof(c->lsp_prev));
    memcpy(c->lsf_prev, lsf[set], sizeof(c->lsf_prev));
  }

  /* the postfilter over the output frame, the second half of the last
   * superframe and the first of this one */
  if (!f->bpf_control_info) {
    const int from = f->mod[0] != 0 && c->bpf_active_prev ? 2 : 0;
    for (int i = from; i < c->nb_slots; i++) {
      gain[s0 + i] = 0.0f;
    }
  }
  c->bpf_active_prev = f->bpf_control_info;
  for (int i = 0; i < s0; i++) {
    c->pitch_prev[i] = pitch[c->nb_slots + i];
    c->gain_prev[i] = gain[c->nb_slots + i];
  }
  const float* s = syn0(c) - c->synth_delay;
  for (int i = 0; i < c->nb_slots; i++) {
    refine_gain(s + i * LPD_SUBFR, pitch[i], &gain[i]);
  }
#ifdef LPD_TRACE
  LPD_T("XLPD f=%d pitch", lpd_trace_frame);
  for (int a = 0; a < c->nb_slots + s0; a++) LPD_T(" %d/%.4f", pitch[a], gain[a]);
  LPD_T("\n");
#endif
  float lpd_out[LPD_MAX_FRAME];
  const int len2 = f->mod[3] == 0 ? c->synth_delay : c->synth_delay - c->lfac;
  bass_postfilter(s, pitch, gain, n, len2, c->bpf_noise, lpd_out);
  if (after_fd) {
    /* the FD signal stands up to the FAC region, and before an ACELP
     * segment a slot earlier */
    const int keep = n / 2 - fp - (f->mod[0] == 0 ? LPD_SUBFR : 0);
    memcpy(lpd_out, fd, (size_t)keep * sizeof(float));
  }
  for (int i = 0; i < n; i++) {
    out[i] = lpd_out[i] * kOutputScale;
  }

  /* the core channel contract toward an FD frame: the second half of
   * the superframe, then the unwindowed tail around its end; the FD
   * frame applies the slope its window sequence implies (2 lfac, or
   * frame_length / 8 for short windows), or after ACELP replaces the
   * region by the FAC terms */
  memcpy(ch->overlap, syn0(c) + n / 2, (size_t)(n / 2 - c->lfac) * sizeof(float));
  memcpy(ch->overlap + n / 2 - c->lfac, c->ovl + 1, (size_t)(2 * c->lfac) * sizeof(float));
  memset(ch->overlap + n / 2 + c->lfac, 0, (size_t)(n / 2 - c->lfac) * sizeof(float));
  ch->prev_core_mode = 1;
  ch->prev_window_sequence = AAC_WIN_EIGHT_SHORT;
  ch->prev_window_shape = AAC_WIN_SINE;

  /* the histories move on by one superframe */
  memmove(c->syn, c->syn + n, LPD_SYN_HIST * sizeof(float));
  memmove(c->exc, c->exc + n, LPD_EXC_HIST * sizeof(float));
}

}  // namespace

extern "C" int aac_usac_lpd_decode(AacUsacCoreChannel* ch, int frame_length, int indep,
                                   AacBitReader* r, float* out) {
  AacUsacLpdChannel* c = ch->lpd;
  if (!c || c->frame_length != frame_length) {
    return AAC_ERR_INVALID_ARG;
  }
  c->noise_seed = &ch->noise_seed;
  const int ret = parse_frame(c, r, indep, &c->frame);
  if (ret != AAC_OK) {
    c->have_frame = 0;
    return ret;
  }
  c->have_frame = 1;
  c->conceal_gain = 1.0f;
  decode_frame(c, ch, &c->frame, out);
  return AAC_OK;
}

/* ── the transition to the frequency domain core ────────────────── */

extern "C" void aac_usac_lpd_fac_to_fd_gain(AacUsacCoreChannel* ch, const int* fac_q,
                                            int fac_length, int frame_length, int fac_gain_index) {
  AacUsacLpdChannel* c = ch->lpd;
  if (!c || fac_length > LPD_MAX_LFAC || fac_length > c->lfac) {
    return;
  }
  const int n = frame_length;
  const int lfac = fac_length;
  /* the FAC through the last ACELP filter, halved; the ACELP's end and
   * its zero input response folded into the first lfac samples */
  float x[LPD_MAX_LFAC], y[2 * LPD_MAX_LFAC];
  const float gain = (float)pow(10.0, (double)fac_gain_index / 28.0);
  for (int i = 0; i < lfac; i++) {
    x[i] = gain * (float)fac_q[i];
  }
  lpd_fac_synth(x, lfac, c->a_prev[1], y, 2 * lfac);
  float past_w[LPD_MAX_LFAC], zir_w[LPD_MAX_LFAC];
  lpd_fac_windows(lfac, past_w, zir_w);
  /* ovl is laid out for the channel's lfac; a shorter FAC (short
   * windows) uses the samples nearest the boundary */
  const float* at_end = c->ovl + 1 + c->lfac; /* the first sample after the superframe */
  for (int i = 0; i < lfac; i++) {
    y[i] = 0.5f * y[i] + at_end[i] * zir_w[i] + at_end[-1 - i] * past_w[lfac - 1 - i];
  }
  for (int i = lfac; i < 2 * lfac; i++) {
    y[i] *= 0.5f;
  }
  memcpy(ch->overlap + n / 2 - lfac, at_end - lfac, (size_t)lfac * sizeof(float));
  memcpy(ch->overlap + n / 2, y, (size_t)(2 * lfac) * sizeof(float));
}

extern "C" void aac_usac_lpd_fac_to_fd(AacUsacCoreChannel* ch, const int* fac_q, int fac_length,
                                       int frame_length) {
  aac_usac_lpd_fac_to_fd_gain(ch, fac_q, fac_length, frame_length, 0);
}

extern "C" int aac_usac_lpd_fd_fac(AacUsacCoreChannel* ch, AacBitReader* r, int fac_length,
                                   int frame_length) {
  if (!ch->lpd || fac_length > LPD_MAX_LFAC) {
    return AAC_ERR_DECODE;
  }
  const int gain_index = (int)aac_bitreader_read(r, 7);
  int coef[LPD_MAX_LFAC];
  const int ret = lpd_read_fac(r, fac_length, coef);
  if (ret != AAC_OK) {
    return ret;
  }
  aac_usac_lpd_fac_to_fd_gain(ch, coef, fac_length, frame_length, gain_index);
  return AAC_OK;
}

extern "C" void aac_usac_lpd_fd_postfilter(AacUsacCoreChannel* ch, float* out, int frame_length,
                                           int short_windows) {
  AacUsacLpdChannel* c = ch->lpd;
  if (!c || c->frame_length != frame_length) {
    return;
  }
  const int n = frame_length;
  const int s0 = c->nb_slots / 2 - 1;
  /* the synthesis: the history up to the superframe end, then the FD
   * output from the superframe's middle */
  float buf[LPD_SYN_HIST + LPD_MAX_FRAME];
  memcpy(buf, c->syn, LPD_SYN_HIST * sizeof(float));
  memset(buf + LPD_SYN_HIST, 0, LPD_MAX_FRAME * sizeof(float));
  for (int i = 0; i < n; i++) {
    buf[LPD_SYN_HIST - n / 2 + i] = out[i] * kInputScale;
  }
  int pitch[LPD_MAX_SLOTS / 2 + 3];
  float gain[LPD_MAX_SLOTS / 2 + 3];
  for (int i = 0; i < s0; i++) {
    pitch[i] = c->pitch_prev[i];
    gain[i] = c->gain_prev[i];
  }
  for (int i = s0; i < s0 + 4; i++) {
    pitch[i] = LPD_SUBFR;
    gain[i] = 0.0f;
  }
  if (c->mode_prev == 0) {
    pitch[s0] = pitch[s0 - 1];
    gain[s0] = gain[s0 - 1];
    if (!short_windows) {
      pitch[s0 + 1] = pitch[s0];
      gain[s0 + 1] = gain[s0];
    }
  }
  const float* s = buf + LPD_SYN_HIST - c->synth_delay;
  for (int i = 0; i < s0 + 2; i++) {
    refine_gain(s + i * LPD_SUBFR, pitch[i], &gain[i]);
  }
  const int len = (s0 + 3) * LPD_SUBFR;
  const int len2 = n - (s0 + 4) * LPD_SUBFR;
  float filtered[LPD_MAX_FRAME];
  bass_postfilter(s, pitch, gain, len, len2, c->bpf_noise, filtered);
  for (int i = 0; i < len; i++) {
    out[i] = filtered[i] * kOutputScale;
  }
}

/* ── concealment ────────────────────────────────────────────────── */

extern "C" void aac_usac_lpd_conceal(AacUsacCoreChannel* ch, int frame_length, float* out) {
  AacUsacLpdChannel* c = ch->lpd;
  const int n = frame_length;
  memset(out, 0, (size_t)n * sizeof(float));
  if (!c || c->frame_length != n) {
    return;
  }
  /* the pending second half of the last superframe fades out */
  c->conceal_gain *= 0.5f;
  const float* second = c->syn + LPD_SYN_HIST - n / 2;
  for (int i = 0; i < n / 2; i++) {
    out[i] = second[i] * c->conceal_gain * kOutputScale;
  }
  memset(c->ovl, 0, sizeof(c->ovl));
  memset(ch->overlap, 0, (size_t)n * sizeof(float));
  memmove(c->syn, c->syn + n, LPD_SYN_HIST * sizeof(float));
  memset(c->syn + LPD_SYN_HIST, 0, (size_t)(n + c->lfac) * sizeof(float));
  memmove(c->exc, c->exc + n, LPD_EXC_HIST * sizeof(float));
  memset(c->exc + LPD_EXC_HIST, 0, (size_t)(n + 1) * sizeof(float));
  for (int i = 0; i < LPD_MAX_SLOTS / 2; i++) {
    c->gain_prev[i] = 0.0f;
  }
  ch->prev_core_mode = 1;
}
