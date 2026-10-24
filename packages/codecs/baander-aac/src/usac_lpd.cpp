/* The linear prediction domain core of USAC (ISO/IEC 23003-3 6.2.7,
 * 7.11 to 7.17): lpd_channel_stream() with its mode field, the frame
 * synthesis over the four subframes with the ACELP and TCX decoders, the
 * forward aliasing cancellation and the zero input response at every
 * mode boundary, the bass postfilter, and the core channel contract of
 * usac.h (the overlap buffer toward the next frame, the FAC an FD frame
 * carries after an LPD frame, concealment).
 *
 * Time layout, in the coordinates of the output frame [0, N): the LPD
 * superframe owns [N/2, 3N/2) with the subframe boundaries
 * B_k = N/2 + k N/4.  Transform outputs (TCX, FD) extend half a slope
 * beyond their segment on each side and overlap-add; an ACELP segment is
 * exact inside its boundaries.  At a boundary between ACELP and a
 * transform segment the FAC adds 2 lfac samples centred on the boundary
 * and, after ACELP, the windowed zero input response of the synthesis
 * filter.  The output frame is final once the frame is decoded, because
 * the next frame reaches at most 3N/2 - N/8 back; [N, 2N) goes to the
 * overlap buffer. */
#include <cmath>
#include <cstring>

#include "usac_lpd_internal.h"

/* ── mode field (Table 6.2.7.1) ─────────────────────────────────── */

/* lpd_mode (5 bits) to the coding mode of the four subframes: 0 ACELP,
 * 1 TCX20, 2 TCX40 (two subframes), 3 TCX80 (the superframe). */
int lpd_modes(int lpd_mode, int* mod) {
  if (lpd_mode < 16) {
    for (int k = 0; k < 4; k++) {
      mod[k] = (lpd_mode >> k) & 1;
    }
  } else if (lpd_mode < 20) {
    mod[0] = mod[1] = 2;
    mod[2] = lpd_mode & 1;
    mod[3] = (lpd_mode >> 1) & 1;
  } else if (lpd_mode < 24) {
    mod[0] = lpd_mode & 1;
    mod[1] = (lpd_mode >> 1) & 1;
    mod[2] = mod[3] = 2;
  } else if (lpd_mode == 24) {
    mod[0] = mod[1] = mod[2] = mod[3] = 2;
  } else if (lpd_mode == 25) {
    mod[0] = mod[1] = mod[2] = mod[3] = 3;
  } else {
    return AAC_ERR_DECODE;
  }
  return AAC_OK;
}

/* ── channel ────────────────────────────────────────────────────── */

AacUsacLpdChannel* aac_usac_lpd_channel_create(int frame_length, int core_sample_rate) {
  if ((frame_length != 1024 && frame_length != 768) || core_sample_rate <= 0) {
    return nullptr;
  }
  lpd_tables_init();
  auto* c = new AacUsacLpdChannel_();
  c->frame_length = frame_length;
  c->l_div = frame_length / 4;
  c->nb_subfr = c->l_div / LPD_MAX_SUBFR;
  c->lfac = c->l_div / 4;
  c->lfac_fd = frame_length / 8;
  c->core_sr = core_sample_rate;
  /* pitch range (7.14.2): the 12.8 kHz range of 34 .. 231 samples scaled
   * to the core rate, keeping the 9 bit index space */
  const int fscale = (core_sample_rate * 512) / 12800;
  c->pit_min = (34 * fscale) / 512;
  if (c->pit_min < LPD_INTERPOL + 1) {
    c->pit_min = LPD_INTERPOL + 1;
  }
  const int delta = c->pit_min - 34;
  c->pit_fr2 = 128 - delta;
  c->pit_fr1 = 160 - delta;
  c->pit_max = 231 + 6 * delta;
  if (c->pit_max > LPD_PIT_MAX_MAX - 32) {
    c->pit_max = LPD_PIT_MAX_MAX - 32;
  }
  for (int i = 0; i < 3; i++) {
    aac_mdct_init(&c->mdct[i], 2 * (c->l_div << i));
  }
  aac_usac_lpd_channel_reset(c);
  return c;
}

void aac_usac_lpd_channel_destroy(AacUsacLpdChannel* c) { delete c; }

void aac_usac_lpd_channel_reset(AacUsacLpdChannel* c) {
  c->have_lsf_prev = 0;
  memcpy(c->lsf_prev, lpd_lsf_mean(), sizeof(c->lsf_prev));
  memcpy(c->lsf_old, lpd_lsf_mean(), sizeof(c->lsf_old));
  memset(c->a_prev, 0, sizeof(c->a_prev));
  c->a_prev[0] = 1.0f;
  memset(c->exc, 0, sizeof(c->exc));
  memset(c->syn_mem, 0, sizeof(c->syn_mem));
  c->tilt_code = 0.0f;
  c->gain_code_prev = 0.0f;
  c->t0_prev = c->pit_min;
  c->gain_pit_prev = 0.0f;
  c->last_mode = -1;
  memset(c->bpf_T, 0, sizeof(c->bpf_T));
  memset(c->bpf_gain, 0, sizeof(c->bpf_gain));
  lpd_arith_reset(&c->arith);
  c->noise_seed = 0x1234567u;
  c->tcx_gain_last = 1.0f;
  memset(c->x, 0, sizeof(c->x));
  memset(c->hist, 0, sizeof(c->hist));
  c->conceal_gain = 1.0f;
  c->have_frame = 0;
}

/* ── syntax (Table 6.2.7.2) ─────────────────────────────────────── */

static int parse_frame(AacUsacLpdChannel* c, AacBitReader* r, int indep, LpdFrameData* f) {
  memset(f, 0, sizeof(*f));
  f->acelp_core_mode = (int)aac_bitreader_read(r, 3);
  f->lpd_mode = (int)aac_bitreader_read(r, 5);
  f->bpf_control_info = (int)aac_bitreader_read(r, 1);
  f->core_mode_last = (int)aac_bitreader_read(r, 1);
  f->fac_data_present = (int)aac_bitreader_read(r, 1);
  if (lpd_modes(f->lpd_mode, f->mod)) {
    return AAC_ERR_DECODE;
  }
  f->first_lpd_flag = f->core_mode_last == 0;
  int last_lpd_mode = c->last_mode;
  int first_tcx = 1;
  int k = 0;
  while (k < 4) {
    int err;
    if (k == 0) {
      /* a FAC at the frame start toward an LPD neighbour */
      if (f->core_mode_last == 1 && f->fac_data_present) {
        err = lpd_read_fac(r, 0, c->lfac, &f->fac[0]);
        if (err) {
          return err;
        }
      }
    } else if ((last_lpd_mode == 0 && f->mod[k] > 0) || (last_lpd_mode > 0 && f->mod[k] == 0)) {
      err = lpd_read_fac(r, 0, c->lfac, &f->fac[k]);
      if (err) {
        return err;
      }
    }
    if (f->mod[k] == 0) {
      err = lpd_read_acelp(r, f->acelp_core_mode, c->nb_subfr, &f->acelp[k]);
      if (err) {
        return err;
      }
      last_lpd_mode = 0;
      k++;
    } else {
      const int lg = c->l_div << (f->mod[k] - 1);
      err = lpd_read_tcx(r, lg, first_tcx, indep, &c->arith, &f->tcx[k]);
      if (err) {
        return err;
      }
      last_lpd_mode = f->mod[k];
      first_tcx = 0;
      k += 1 << (f->mod[k] - 1);
    }
  }
  const int err = lpd_read_lpc_data(r, f);
  if (err) {
    return err;
  }
  if (f->core_mode_last == 0 && f->fac_data_present) {
    /* the FAC toward the previous FD frame, with its own gain */
    const int e = lpd_read_fac(r, 1, c->lfac_fd, &f->fac_fd);
    if (e) {
      return e;
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* ── synthesis ──────────────────────────────────────────────────── */

/* Adds `n` samples of `src` at x[pos]. */
static void add_at(float* x, int pos, const float* src, int n) {
  for (int i = 0; i < n; i++) {
    x[pos + i] += src[i];
  }
}

/* The windowed zero input response after an ACELP segment ending at
 * x[pos], over the `slope` / 2 samples after the boundary of a transform
 * neighbour whose slope of `slope` samples is centred on the boundary:
 * weighted by 1 - w^2 of the rising sine slope. */
static void add_zir(const float* a, const float* syn_mem, float* x, int pos, int slope) {
  const int half = slope / 2;
  float zir[2 * LPD_MAX_LFAC];
  lpd_zir(a, syn_mem, zir, half);
  for (int i = 0; i < half; i++) {
    const float w = sinf((float)M_PI * ((float)(half + i) + 0.5f) / (2.0f * (float)slope));
    x[pos + i] += zir[i] * (1.0f - w * w);
  }
}

/* The ACELP memories from the final signal before x[pos]: the history
 * buffer followed by x supplies LPD_EXC_HIST samples. */
static void memories_from_x(AacUsacLpdChannel* c, const float* a, int pos) {
  memcpy(c->tmp, c->hist, sizeof(c->hist));
  memcpy(c->tmp + LPD_SYN_HIST, c->x, sizeof(float) * (size_t)pos);
  lpd_acelp_memory_from_signal(c, a, c->tmp + LPD_SYN_HIST + pos, LPD_EXC_HIST);
}

/* Bass postfilter (7.17) over the output region [0, N) of x: per
 * 64-sample slot with a pitch lag T and gain g, the aperiodic part
 * s(n) - (s(n - T) + s(n + T)) / 2, scaled by the gain and the measured
 * correlation, is low pass filtered and subtracted. */
static void bass_postfilter(AacUsacLpdChannel* c, float* out) {
  const int n = c->frame_length;
  const int slots = n / LPD_MAX_SUBFR;
  const float* s = c->tmp + LPD_SYN_HIST; /* x with the history before it */
  const float* lp = lpd_bpf_filter();
  float noise[LPD_MAX_FRAME + 2 * LPD_BPF_TAPS];
  const int margin = LPD_BPF_TAPS;
  for (int i = -margin; i < n + margin; i++) {
    noise[i + margin] = 0.0f;
  }
  for (int slot = -1; slot <= slots; slot++) {
    const int ci = slot + slots / 2; /* combined slot index */
    if (ci < 0 || ci >= 2 * slots) {
      continue;
    }
    const int T = c->bpf_T[ci];
    const float g = c->bpf_gain[ci];
    if (T <= 0 || g <= 0.0f) {
      continue;
    }
    int from = slot * LPD_MAX_SUBFR, to = from + LPD_MAX_SUBFR;
    if (from < -margin) {
      from = -margin;
    }
    if (to > n + margin) {
      to = n + margin;
    }
    float corr = 0.0f, e_s = 0.0f, e_p = 0.0f;
    for (int i = from; i < to; i++) {
      const float p = 0.5f * (s[i - T] + s[i + T]);
      corr += s[i] * p;
      e_s += s[i] * s[i];
      e_p += p * p;
    }
    float ratio = corr / (sqrtf(e_s * e_p) + 1e-9f);
    if (ratio < 0.0f) {
      ratio = 0.0f;
    }
    const float geff = g * ratio;
    for (int i = from; i < to; i++) {
      const float p = 0.5f * (s[i - T] + s[i + T]);
      noise[i + margin] = geff * (s[i] - p);
    }
  }
  for (int i = 0; i < n; i++) {
    float v = lp[0] * noise[i + margin];
    for (int k = 1; k < LPD_BPF_TAPS; k++) {
      v += lp[k] * (noise[i + margin - k] + noise[i + margin + k]);
    }
    out[i] = s[i] - v;
  }
}

static void finish_frame(AacUsacLpdChannel* c, AacUsacCoreChannel* ch, float* out) {
  const int n = c->frame_length;
  const int slots = n / LPD_MAX_SUBFR;
  /* the output region with its history, for the postfilter */
  memcpy(c->tmp, c->hist, sizeof(c->hist));
  memcpy(c->tmp + LPD_SYN_HIST, c->x, sizeof(float) * (size_t)LPD_X_LEN);
  bass_postfilter(c, out);
  /* history: the last LPD_SYN_HIST samples of the final output region */
  memmove(c->hist, c->hist + n, sizeof(float) * (size_t)(LPD_SYN_HIST - n));
  memcpy(c->hist + LPD_SYN_HIST - n, c->x, sizeof(float) * (size_t)n);
  /* the contribution to the next frame */
  memcpy(ch->overlap, c->x + n, sizeof(float) * (size_t)n);
  ch->prev_core_mode = 1;
  /* the postfilter slots of this frame become the previous ones */
  memmove(c->bpf_T, c->bpf_T + slots, sizeof(int) * (size_t)slots);
  memmove(c->bpf_gain, c->bpf_gain + slots, sizeof(float) * (size_t)slots);
  for (int i = 0; i < slots; i++) {
    c->bpf_T[slots + i] = 0;
    c->bpf_gain[slots + i] = 0.0f;
  }
}

static void synth_frame(AacUsacLpdChannel* c, AacUsacCoreChannel* ch, const LpdFrameData* f,
                        float* out) {
  const int n = c->frame_length;
  const int half = n / 2;
  const int l = c->l_div;
  const int slots = n / LPD_MAX_SUBFR;
  float* x = c->x;
  memset(x, 0, sizeof(c->x));
  if (ch->prev_core_mode >= 0) {
    memcpy(x, ch->overlap, sizeof(float) * (size_t)n);
  }
  const int prev_fd = ch->prev_core_mode == 0;
  if (prev_fd || ch->prev_core_mode < 0) {
    c->last_mode = prev_fd ? 4 : -1;
  }

  /* the five filter sets */
  float lsf[5][LPD_M], lsp[5][LPD_M], a[5][LPD_M + 1];
  lpd_lsf_decode(c, f, lsf);
  for (int i = 0; i < 5; i++) {
    lpd_lsf_to_lsp(lsf[i], lsp[i]);
    lpd_lsp_to_a(lsp[i], a[i]);
  }

  /* the FAC toward a previous FD frame completes the signal before B_0 */
  if (f->fac_fd.present) {
    float fac[2 * LPD_MAX_LFAC];
    lpd_fac_synth(&f->fac_fd, 1.0f, a[0], fac);
    add_at(x, half - f->fac_fd.length, fac, 2 * f->fac_fd.length);
  }

  for (int i = 0; i < slots; i++) {
    c->bpf_T[slots + i] = 0;
    c->bpf_gain[slots + i] = 0.0f;
  }

  int k = 0;
  while (k < 4) {
    const int b = half + k * l;
    const int m = f->mod[k];
    if (m == 0) {
      if (f->fac[k].present) {
        /* a transform segment before: the FAC, with the gain of the TCX
         * it belongs to, then the memories from the completed signal */
        float fac[2 * LPD_MAX_LFAC];
        lpd_fac_synth(&f->fac[k], c->tcx_gain_last, a[k], fac);
        add_at(x, b - c->lfac, fac, 2 * c->lfac);
      }
      if (c->last_mode != 0) {
        memories_from_x(c, a[k], b);
      }
      float syn[LPD_MAX_DIV];
      int t_sf[4];
      float g_sf[4];
      const float stab = lpd_stab_fac(lsf[k + 1], c->lsf_old);
      lpd_acelp_decode(c, &f->acelp[k], lsp[k], lsp[k + 1], stab, syn, t_sf, g_sf);
      add_at(x, b, syn, l);
      for (int j = 0; j < c->nb_subfr; j++) {
        const int slot = slots + k * c->nb_subfr + j;
        c->bpf_T[slot] = t_sf[j];
        c->bpf_gain[slot] = f->bpf_control_info ? g_sf[j] : 0.0f;
      }
      memcpy(c->lsf_old, lsf[k + 1], sizeof(c->lsf_old));
      c->last_mode = 0;
      k++;
    } else {
      const int lg = l << (m - 1);
      const int left_slope = (k == 0 && c->last_mode == 4) ? 2 * c->lfac_fd : 2 * c->lfac;
      if (f->fac[k].present) {
        /* ACELP before: the FAC with this TCX's gain and the windowed
         * zero input response of the synthesis filter */
        const float gain = powf(10.0f, (float)f->tcx[k].global_gain / 28.0f) / sqrtf((float)lg);
        float fac[2 * LPD_MAX_LFAC];
        lpd_fac_synth(&f->fac[k], gain, a[k], fac);
        add_at(x, b - c->lfac, fac, 2 * c->lfac);
        add_zir(a[k], c->syn_mem, x, b, 2 * c->lfac);
      }
      float t[2 * LPD_MAX_TCX];
      lpd_tcx_decode(c, &f->tcx[k], a[k], a[k + (1 << (m - 1))], left_slope, t);
      add_at(x, b - lg / 2, t, 2 * lg);
      k += 1 << (m - 1);
      memcpy(c->lsf_old, lsf[k], sizeof(c->lsf_old));
      c->last_mode = m;
    }
  }

  memcpy(c->lsf_prev, lsf[4], sizeof(c->lsf_prev));
  c->have_lsf_prev = 1;
  memcpy(c->a_prev, a[4], sizeof(c->a_prev));
  c->conceal_gain = 1.0f;
  finish_frame(c, ch, out);
}

int aac_usac_lpd_decode(AacUsacCoreChannel* ch, int frame_length, int indep, AacBitReader* r,
                        float* out) {
  AacUsacLpdChannel* c = ch->lpd;
  if (!c || c->frame_length != frame_length) {
    return AAC_ERR_INVALID_ARG;
  }
  LpdFrameData* f = &c->frame;
  const int err = parse_frame(c, r, indep, f);
  if (err) {
    c->have_frame = 0;
    return err;
  }
  c->have_frame = 1;
  synth_frame(c, ch, f, out);
  return AAC_OK;
}

/* ── the FAC an FD frame carries after an LPD frame ─────────────── */

void aac_usac_lpd_fac_to_fd_gain(AacUsacCoreChannel* ch, const int* fac_q, int fac_length,
                                 int frame_length, int fac_gain_index) {
  AacUsacLpdChannel* c = ch->lpd;
  if (!c || fac_length <= 0 || fac_length > LPD_MAX_LFAC || c->frame_length != frame_length) {
    return;
  }
  const int half = frame_length / 2;
  const float gain = powf(10.0f, (float)fac_gain_index / 28.0f) / sqrtf((float)fac_length);
  float fac[2 * LPD_MAX_LFAC];
  lpd_fac_synth_q(fac_q, fac_length, gain, c->a_prev, fac);
  add_at(ch->overlap, half - fac_length, fac, 2 * fac_length);
  if (c->last_mode == 0) {
    add_zir(c->a_prev, c->syn_mem, ch->overlap, half, 2 * fac_length);
  }
  c->last_mode = 4;
}

void aac_usac_lpd_fac_to_fd(AacUsacCoreChannel* ch, const int* fac_q, int fac_length,
                            int frame_length) {
  aac_usac_lpd_fac_to_fd_gain(ch, fac_q, fac_length, frame_length, 0);
}

int aac_usac_lpd_fd_fac(AacUsacCoreChannel* ch, AacBitReader* r, int fac_length,
                        int frame_length) {
  if (fac_length <= 0 || fac_length > LPD_MAX_LFAC || fac_length % 8 != 0) {
    return AAC_ERR_DECODE;
  }
  LpdFacData d;
  const int err = lpd_read_fac(r, 1, fac_length, &d);
  if (err) {
    return err;
  }
  AacUsacLpdChannel* c = ch->lpd;
  if (!c || c->frame_length != frame_length) {
    return AAC_OK; /* nothing to cancel against */
  }
  int q[LPD_MAX_LFAC];
  for (int i = 0; i < fac_length / 8; i++) {
    lpd_avq_decode(&d.blk[i], q + 8 * i);
  }
  aac_usac_lpd_fac_to_fd_gain(ch, q, fac_length, frame_length, d.gain_index);
  return AAC_OK;
}

/* ── concealment ────────────────────────────────────────────────── */

/* The last excitation is repeated with the last pitch lag and a gain
 * decaying by 0.7 per lost frame, through the last LPC filter; the
 * contract (overlap, prev_core_mode) is kept. */
void aac_usac_lpd_conceal(AacUsacCoreChannel* ch, int frame_length, float* out) {
  AacUsacLpdChannel* c = ch->lpd;
  if (!c || c->frame_length != frame_length) {
    memset(out, 0, sizeof(float) * (size_t)frame_length);
    return;
  }
  const int n = frame_length;
  const int half = n / 2;
  const int slots = n / LPD_MAX_SUBFR;
  float* x = c->x;
  memset(x, 0, sizeof(c->x));
  if (ch->prev_core_mode >= 0) {
    memcpy(x, ch->overlap, sizeof(float) * (size_t)n);
  }
  if (c->last_mode != 0 && c->last_mode != -1) {
    memories_from_x(c, c->a_prev, half);
  }
  c->conceal_gain *= 0.7f;
  int period = c->t0_prev;
  if (period < c->pit_min || period > c->pit_max) {
    period = c->l_div;
  }
  float* exc = c->exc + LPD_EXC_HIST;
  for (int i = 0; i < n; i++) {
    exc[i] = c->conceal_gain * exc[i - period];
  }
  float syn[LPD_MAX_FRAME];
  lpd_syn_filter(c->a_prev, exc, syn, n, c->syn_mem);
  add_at(x, half, syn, n);
  memmove(c->exc, c->exc + n, sizeof(float) * (size_t)LPD_EXC_HIST);
  for (int i = 0; i < slots; i++) {
    c->bpf_T[slots + i] = 0;
    c->bpf_gain[slots + i] = 0.0f;
  }
  c->last_mode = 0;
  finish_frame(c, ch, out);
}
