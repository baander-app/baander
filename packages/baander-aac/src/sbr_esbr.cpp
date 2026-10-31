#include "sbr.h"

#include <algorithm>
#include <cfloat>
#include <cmath>
#include <cstddef>
#include <cstring>

/* Enhanced SBR of USAC (ISO/IEC 23003-3 7.5) on the AAC SBR decoder's
 * state: UsacSbrData() with the SbrInfo and SbrHeader elements, the
 * 2:1, 8:3 and 4:1 ratios, the harmonic transposer (7.5.4), inter-TES
 * (7.5.5) and PVC (7.5.6).  The bitstream elements the two syntaxes share
 * and the dequantisation come from sbr_dec.cpp; the signal path is this
 * file's because its buffers hold 64 QMF slots per frame, a longer
 * history and up to 16 adjustment envelopes.
 *
 * Buffer layout (per channel, columns of QMF slots): the W_low and W_hbe
 * buffers keep `history` columns of the previous frames in front of the
 * `slots` columns of the current frame; the output frame delivered to the
 * synthesis is the `slots` columns from `offset`, which is 3 time slots
 * (plus the transposer delay) before the core frame, where the envelope
 * grid of the bitstream places it.  Y holds the adjusted high band of the
 * current and the previous frame; an envelope reaching past the frame end
 * is completed in the next one. */

namespace {

constexpr int kFixFix = 0;
constexpr int kCols = AAC_SBR_USAC_COLS;
/* QMF slots of the previous frame in the LPC covariance: the three time
 * slots the output frame leads the core frame by, plus the predictor's
 * two taps (8 at 2:1 and 8:3, 14 at 4:1). */
int lpc_history(const AacSbrUsac* u) { return 3 * u->rate + 2; }
/* The hybrid filter of MPS 212 delays the three lowest QMF bands by six
 * slots (ISO/IEC 23003-1 6.6.3); with stereoConfigIndex 1 or 2 the
 * output frame hands them that much ahead. */
constexpr int kMpsHybridDelay = 6;
constexpr int kMpsLowBands = 3;
constexpr float kInterTesGamma[4] = {0.0f, 1.0f, 2.0f, 4.0f};

/* ── complex FFT of n = 2^k or 3 x 2^k for the transposer ───────── */

using Fft = AacSbrUsacFft;

void fft_init(Fft* f, int n) {
  f->n = n;
  f->m = n % 3 == 0 ? 3 : 1;
  f->p = n / f->m;
  for (int k = 0; k < n; k++) {
    f->tw_re[k] = (float)cos(-2.0 * M_PI * k / n);
    f->tw_im[k] = (float)sin(-2.0 * M_PI * k / n);
  }
  int bits = 0;
  while ((1 << bits) < f->p) {
    bits++;
  }
  for (int i = 0; i < f->p; i++) {
    int r = 0;
    for (int b = 0; b < bits; b++) {
      r |= ((i >> b) & 1) << (bits - 1 - b);
    }
    f->rev[i] = (uint16_t)r;
  }
}

/* Radix-2 transform of the p points at re/im (twiddles of the n-point
 * table read every m-th entry). */
void fft_pow2(const Fft* f, float* re, float* im) {
  const int p = f->p;
  for (int i = 0; i < p; i++) {
    const int j = f->rev[i];
    if (j > i) {
      std::swap(re[i], re[j]);
      std::swap(im[i], im[j]);
    }
  }
  for (int len = 2; len <= p; len <<= 1) {
    const int half = len / 2;
    const int step = f->n / len;
    for (int start = 0; start < p; start += len) {
      for (int j = 0; j < half; j++) {
        const float wr = f->tw_re[j * step];
        const float wi = f->tw_im[j * step];
        const int a = start + j;
        const int b = a + half;
        const float tr = re[b] * wr - im[b] * wi;
        const float ti = re[b] * wi + im[b] * wr;
        re[b] = re[a] - tr;
        im[b] = im[a] - ti;
        re[a] += tr;
        im[a] += ti;
      }
    }
  }
}

/* Forward transform in place; `sr`/`si` are scratch of n floats. */
void fft_forward(const Fft* f, float* re, float* im, float* sr, float* si) {
  if (f->m == 1) {
    fft_pow2(f, re, im);
    return;
  }
  const int p = f->p;
  const int n = f->n;
  for (int r = 0; r < 3; r++) {
    for (int j = 0; j < p; j++) {
      sr[r * p + j] = re[3 * j + r];
      si[r * p + j] = im[3 * j + r];
    }
    fft_pow2(f, sr + r * p, si + r * p);
  }
  for (int k = 0; k < n; k++) {
    const int kp = k % p;
    float ar = sr[kp];
    float ai = si[kp];
    for (int r = 1; r < 3; r++) {
      const int t = (r * k) % n;
      const float br = sr[r * p + kp];
      const float bi = si[r * p + kp];
      ar += br * f->tw_re[t] - bi * f->tw_im[t];
      ai += br * f->tw_im[t] + bi * f->tw_re[t];
    }
    re[k] = ar;
    im[k] = ai;
  }
}

/* ── frequency tables ──────────────────────────────────────────── */

int usac_make_tables(AacSbrState* s) {
  AacSbrUsac* u = &s->usac;
  AacSbrFreq f;
  aac_sbr_freq_from_state(s, &f);
  /* At 4:1 the start and stop indices are those of the 2:1 system at
   * twice the core rate. */
  f.table_rate = u->ratio == AAC_USAC_SBR_4_1 ? s->sample_rate / 2 : 0;
  const int err = aac_sbr_freq_tables(&f);
  if (err) {
    return err;
  }
  aac_sbr_freq_to_state(&f, s);
  for (int c = 0; c < 2; c++) {
    u->ch[c].f_indexnoise = 0;
  }
  return 0;
}

void usac_turnoff(AacSbrState* s) {
  aac_sbr_turnoff(s);
  s->kx[0] = s->kx[1];
  s->m[0] = s->m[1];
}

/* Applies a header's fields; a changed spectrum parameter asks for new
 * tables (the limiter table alone follows limiter_bands). */
void apply_header(AacSbrState* s, int start_freq, int stop_freq, int freq_scale, int alter_scale,
                  int noise_bands, int limiter_bands, int limiter_gains, int interpol_freq,
                  int smoothing_mode) {
  const int old[5] = {s->start_freq, s->stop_freq, s->freq_scale, s->alter_scale, s->noise_bands};
  const int old_limiter = s->limiter_bands;
  s->start = 1;
  s->start_freq = start_freq;
  s->stop_freq = stop_freq;
  s->freq_scale = freq_scale;
  s->alter_scale = alter_scale;
  s->noise_bands = noise_bands;
  s->limiter_bands = limiter_bands;
  s->limiter_gains = limiter_gains;
  s->interpol_freq = interpol_freq;
  s->smoothing_mode = smoothing_mode;
  const int now[5] = {s->start_freq, s->stop_freq, s->freq_scale, s->alter_scale, s->noise_bands};
  if (memcmp(old, now, sizeof(old)) != 0) {
    s->reset = 1;
  } else if (limiter_bands != old_limiter) {
    AacSbrFreq f;
    aac_sbr_freq_from_state(s, &f);
    aac_sbr_freq_limiter(&f);
    s->n_lim = f.n_lim;
    memcpy(s->f_tablelim, f.f_tablelim, sizeof(s->f_tablelim));
  }
}

void apply_default_header(AacSbrState* s) {
  const AacSbrUsac* u = &s->usac;
  apply_header(s, u->dflt_start_freq, u->dflt_stop_freq, u->dflt_freq_scale, u->dflt_alter_scale,
               u->dflt_noise_bands, u->dflt_limiter_bands, u->dflt_limiter_gains,
               u->dflt_interpol_freq, u->dflt_smoothing_mode);
}

/* SbrHeader() of Table 7.x: the AAC header without amp_res, xover_band
 * and the reserved bits, which SbrInfo() carries. */
void read_header(AacSbrState* s, AacBitReader* r) {
  const int start_freq = (int)aac_bitreader_read(r, 4);
  const int stop_freq = (int)aac_bitreader_read(r, 4);
  const int extra1 = (int)aac_bitreader_read(r, 1);
  const int extra2 = (int)aac_bitreader_read(r, 1);
  int freq_scale = 2, alter_scale = 1, noise_bands = 2;
  int limiter_bands = 2, limiter_gains = 2, interpol_freq = 1, smoothing_mode = 1;
  if (extra1) {
    freq_scale = (int)aac_bitreader_read(r, 2);
    alter_scale = (int)aac_bitreader_read(r, 1);
    noise_bands = (int)aac_bitreader_read(r, 2);
  }
  if (extra2) {
    limiter_bands = (int)aac_bitreader_read(r, 2);
    limiter_gains = (int)aac_bitreader_read(r, 2);
    interpol_freq = (int)aac_bitreader_read(r, 1);
    smoothing_mode = (int)aac_bitreader_read(r, 1);
  }
  apply_header(s, start_freq, stop_freq, freq_scale, alter_scale, noise_bands, limiter_bands,
               limiter_gains, interpol_freq, smoothing_mode);
}

/* ── bitstream: the USAC additions ─────────────────────────────── */

/* sbr_dtdf() of the USAC syntax: an independent frame carries no
 * direction bit for its first envelope and first noise floor, both of
 * which are coded in frequency. */
void read_dtdf(AacBitReader* r, AacSbrChannel* ch, int indep) {
  for (int i = 0; i < ch->num_env; i++) {
    ch->df_env[i] = indep && i == 0 ? 0 : (int)aac_bitreader_read(r, 1);
  }
  for (int i = 0; i < ch->num_noise; i++) {
    ch->df_noise[i] = indep && i == 0 ? 0 : (int)aac_bitreader_read(r, 1);
  }
}

/* bs_temp_shape and bs_inter_temp_shape_mode per envelope, at the end of
 * sbr_envelope() when the configuration enables inter-TES (7.5.5). */
void read_inter_tes(AacBitReader* r, const AacSbrChannel* ch, AacSbrUsacChannelData* d) {
  for (int e = 0; e < ch->num_env; e++) {
    d->temp_shape[e] = (int)aac_bitreader_read(r, 1);
    d->temp_shape_mode[e] = d->temp_shape[e] ? (int)aac_bitreader_read(r, 2) : 0;
  }
}

void clear_inter_tes(AacSbrUsacChannelData* d) {
  memset(d->temp_shape, 0, sizeof(d->temp_shape));
  memset(d->temp_shape_mode, 0, sizeof(d->temp_shape_mode));
}

/* The harmonic patching fields of an element (7.5.4): bs_sbr_patching_mode
 * selects the LPC patching (1) or the transposer (0); the transposer's
 * frame carries bs_oversampling_flag and, behind bs_pitch_in_bins_flag,
 * bs_pitch_in_bins. */
void read_patching(AacBitReader* r, const AacSbrUsac* u, AacSbrUsacChannelData* d) {
  if (!u->harmonic_sbr) {
    d->patching_mode = 1;
    d->oversampling = 0;
    d->pitch_in_bins = 0;
    return;
  }
  d->patching_mode = (int)aac_bitreader_read(r, 1);
  if (!d->patching_mode) {
    d->oversampling = (int)aac_bitreader_read(r, 1);
    d->pitch_in_bins = aac_bitreader_read(r, 1) ? (int)aac_bitreader_read(r, 7) : 0;
  } else {
    d->oversampling = 0;
    d->pitch_in_bins = 0;
  }
}

/* The grid of a PVC frame (7.5.6): one envelope over the frame for the
 * shared syntax, with the noise floor split at bs_noise_position; the
 * envelope values themselves come per time slot from the predictor. */
void pvc_grid(AacSbrState* s, AacSbrChannel* ch, int noise_position) {
  const int num_env_old = ch->num_env;
  ch->freq_res[0] = ch->freq_res[ch->num_env];
  ch->t_env_num_env_old = ch->t_env[num_env_old];
  ch->amp_res = s->amp_res_header;
  ch->frame_class = kFixFix;
  ch->num_env = 1;
  ch->freq_res[1] = 1;
  ch->t_env[0] = 0;
  ch->t_env[1] = s->time_slots;
  ch->num_noise = noise_position > 0 ? 2 : 1;
  ch->t_q[0] = 0;
  ch->t_q[1] = noise_position > 0 ? noise_position : s->time_slots;
  ch->t_q[2] = s->time_slots;
  ch->e_a[0] = -(ch->e_a[1] != num_env_old);
  ch->e_a[1] = -1;
}

/* The PVC envelope element: bs_div_mode splits the 16 time slots into
 * bs_div_mode + 1 segments at the bs_grid_info borders, each segment
 * either reusing the previous pvcID or carrying its own 7-bit one;
 * bs_ns_mode picks the smoothing length. */
int read_pvc_envelope(AacBitReader* r, AacSbrUsacChannelData* d) {
  const int div_mode = (int)aac_bitreader_read(r, 3);
  d->pvc_ns_mode = (int)aac_bitreader_read(r, 1);
  int borders[9];
  borders[0] = 0;
  for (int i = 1; i <= div_mode; i++) {
    borders[i] = (int)aac_bitreader_read(r, 4);
    if (borders[i] <= borders[i - 1] || borders[i] >= AAC_SBR_USAC_TIME_SLOTS) {
      return AAC_ERR_DECODE;
    }
  }
  borders[div_mode + 1] = AAC_SBR_USAC_TIME_SLOTS;
  int id = d->pvc_id_prev;
  for (int i = 0; i <= div_mode; i++) {
    if (!aac_bitreader_read(r, 1)) { /* bs_reuse_pvcID */
      id = (int)aac_bitreader_read(r, 7);
    }
    for (int t = borders[i]; t < borders[i + 1]; t++) {
      d->pvc_id[t] = id;
    }
  }
  d->pvc_id_prev = id;
  return 0;
}

int read_single_channel(AacSbrState* s, AacBitReader* r, int indep) {
  AacSbrUsac* u = &s->usac;
  AacSbrChannel* ch = &s->data[0];
  AacSbrUsacChannelData* d = &u->data[0];
  /* Unlike the AAC element there is no bs_data_extra flag: the USAC
   * element opens with the harmonic patching fields. */
  read_patching(r, u, d);
  int ret;
  if (u->pvc_mode == 0) {
    d->pvc_active = 0;
    if ((ret = aac_sbr_read_grid(s, r, ch))) {
      return ret;
    }
    read_dtdf(r, ch, indep);
    aac_sbr_read_invf(s, r, ch);
    if ((ret = aac_sbr_read_envelope(s, r, ch, 0))) {
      return ret;
    }
    if (u->inter_tes) {
      read_inter_tes(r, ch, d);
    } else {
      clear_inter_tes(d);
    }
    if ((ret = aac_sbr_read_noise(s, r, ch, 0))) {
      return ret;
    }
  } else {
    d->pvc_active = 1;
    clear_inter_tes(d);
    d->pvc_noise_position = (int)aac_bitreader_read(r, 4);
    aac_bitreader_skip(r, 1); /* bs_var_len_hf_flag */
    pvc_grid(s, ch, d->pvc_noise_position);
    for (int i = 0; i < ch->num_noise; i++) {
      ch->df_noise[i] = (int)aac_bitreader_read(r, 1);
    }
    aac_sbr_read_invf(s, r, ch);
    if ((ret = read_pvc_envelope(r, d)) || (ret = aac_sbr_read_noise(s, r, ch, 0))) {
      return ret;
    }
  }
  aac_sbr_read_add_harmonic(s, r, ch);
  return 0;
}

int read_channel_pair(AacSbrState* s, AacBitReader* r, int indep) {
  AacSbrUsac* u = &s->usac;
  AacSbrChannel* l = &s->data[0];
  AacSbrChannel* rt = &s->data[1];
  u->data[0].pvc_active = u->data[1].pvc_active = 0;
  int ret;
  /* The USAC pair element opens with bs_coupling; the harmonic patching
   * fields follow, once for a coupled pair and per channel otherwise. */
  s->coupling = (int)aac_bitreader_read(r, 1);
  read_patching(r, u, &u->data[0]);
  if (s->coupling) {
    u->data[1].patching_mode = u->data[0].patching_mode;
    u->data[1].oversampling = u->data[0].oversampling;
    u->data[1].pitch_in_bins = u->data[0].pitch_in_bins;
  } else {
    read_patching(r, u, &u->data[1]);
  }
  if (s->coupling) {
    if ((ret = aac_sbr_read_grid(s, r, l))) {
      return ret;
    }
    aac_sbr_copy_grid(rt, l);
    read_dtdf(r, l, indep);
    read_dtdf(r, rt, indep);
    aac_sbr_read_invf(s, r, l);
    memcpy(rt->invf_mode[1], rt->invf_mode[0], sizeof(rt->invf_mode[0]));
    memcpy(rt->invf_mode[0], l->invf_mode[0], sizeof(rt->invf_mode[0]));
    if ((ret = aac_sbr_read_envelope(s, r, l, 0))) {
      return ret;
    }
    if (u->inter_tes) {
      read_inter_tes(r, l, &u->data[0]);
    }
    if ((ret = aac_sbr_read_noise(s, r, l, 0)) || (ret = aac_sbr_read_envelope(s, r, rt, 1))) {
      return ret;
    }
    if (u->inter_tes) {
      read_inter_tes(r, rt, &u->data[1]);
    }
    if ((ret = aac_sbr_read_noise(s, r, rt, 1))) {
      return ret;
    }
  } else {
    if ((ret = aac_sbr_read_grid(s, r, l)) || (ret = aac_sbr_read_grid(s, r, rt))) {
      return ret;
    }
    read_dtdf(r, l, indep);
    read_dtdf(r, rt, indep);
    aac_sbr_read_invf(s, r, l);
    aac_sbr_read_invf(s, r, rt);
    if ((ret = aac_sbr_read_envelope(s, r, l, 0))) {
      return ret;
    }
    if (u->inter_tes) {
      read_inter_tes(r, l, &u->data[0]);
    }
    if ((ret = aac_sbr_read_envelope(s, r, rt, 1))) {
      return ret;
    }
    if (u->inter_tes) {
      read_inter_tes(r, rt, &u->data[1]);
    }
    if ((ret = aac_sbr_read_noise(s, r, l, 0)) || (ret = aac_sbr_read_noise(s, r, rt, 1))) {
      return ret;
    }
  }
  if (!u->inter_tes) {
    clear_inter_tes(&u->data[0]);
    clear_inter_tes(&u->data[1]);
  }
  aac_sbr_read_add_harmonic(s, r, l);
  aac_sbr_read_add_harmonic(s, r, rt);
  return 0;
}

/* The parameters a frame's bitstream sets, saved before parsing and put
 * back when the frame turns out damaged. */
struct SavedParams {
  uint8_t state[offsetof(AacSbrState_, data)];
  uint8_t channel[2][offsetof(AacSbrChannel_, analysis)];
  uint8_t usac[offsetof(AacSbrUsac_, ch)];
};

void save_params(const AacSbrState* s, SavedParams* p) {
  memcpy(p->state, s, sizeof(p->state));
  memcpy(p->channel[0], &s->data[0], sizeof(p->channel[0]));
  memcpy(p->channel[1], &s->data[1], sizeof(p->channel[1]));
  memcpy(p->usac, &s->usac, sizeof(p->usac));
}

void restore_params(AacSbrState* s, const SavedParams* p) {
  memcpy(s, p->state, sizeof(p->state));
  memcpy(&s->data[0], p->channel[0], sizeof(p->channel[0]));
  memcpy(&s->data[1], p->channel[1], sizeof(p->channel[1]));
  memcpy(&s->usac, p->usac, sizeof(p->usac));
}

/* ── harmonic transposer (7.5.4) ───────────────────────────────── */

/* Phase vocoder transposition of the core signal: windows of hbe_n core
 * samples (sine window, hop hbe_n / 8) are transformed; the output
 * spectrum of hbe_ns = ratio x hbe_n bins at the output rate takes, for
 * each order T from 2 to max_stretch, the region of output bins
 * [(T - 1) n / 2, T n / 2) (the second order's from the crossover) from
 * input bin q / T with the phase multiplied by T, and, with a pitch given, the cross products of bins k and
 * k + pitch at the bins between the multiples of T.  The synthesis
 * windows overlap-add at hop ratio x hbe_n / 8; the output is complete up
 * to 7 time slots behind the core input, which is why the frame is
 * delayed by AAC_SBR_USAC_HBE_DELAY time slots when the transposer is
 * configured. */
void hbe_window(float* w, int n) {
  for (int i = 0; i < n; i++) {
    w[i] = (float)sin(M_PI * (i + 0.5) / n);
  }
}

/* Transposes the frame's windows; `core` holds the frame's core_frame
 * samples (full scale 32768) and `out` receives output_frame samples of
 * the transposed signal lagging the core by (hbe_n - hbe_n / 8) core
 * samples. */
void hbe_process(AacSbrUsac* u, AacSbrUsacChannel* ch, const AacSbrUsacChannelData* d,
                 const float* core, float* out, int kx) {
  const int n = u->hbe_n;
  const int ns = u->hbe_ns;
  const int hop = n / AAC_SBR_USAC_HBE_HOPS;
  const int hop_s = ns / AAC_SBR_USAC_HBE_HOPS;
  const int lc = u->core_frame;
  const int lo = u->output_frame;
  /* input: the previous n samples, then the frame */
  memcpy(ch->hbe_in + n, core, (size_t)lc * sizeof(float));
  float* re = u->hbe_re;
  float* im = u->hbe_im;
  const int pitch = d->pitch_in_bins > 0 ? std::max(1, (d->pitch_in_bins * n + 512) / 1024) : 0;
  const float ola_norm = 2.0f / AAC_SBR_USAC_HBE_HOPS; /* sum of the squared sine windows per hop */
  for (int w = 0; w < lc / hop; w++) {
    /* window start p = hop + w hop into hbe_in (positions relative to the
     * frame start: p - n) */
    const int p = hop * (w + 1);
    for (int i = 0; i < n; i++) {
      re[i] = ch->hbe_in[p + i] * u->hbe_win_a[i];
      im[i] = 0.0f;
    }
    fft_forward(&u->fft_a, re, im, u->hbe_re2, u->hbe_im2);
    float* yr = u->hbe_re2;
    float* yi = u->hbe_im2;
    memset(yr, 0, (size_t)ns * sizeof(float));
    memset(yi, 0, (size_t)ns * sizeof(float));
    for (int T = 2; T <= u->max_stretch; T++) {
      /* order T serves the output from (T - 1) times the core bandwidth
       * up to T times it; the second order starts at the crossover */
      const int q0 = T == 2 ? kx * ns / 128 : (T - 1) * n / 2;
      const int q1 = std::min(T * n / 2, ns / 2);
      for (int q = q0; q < q1; q++) {
        if (q % T == 0) {
          const int k = q / T;
          const float mag = sqrtf(re[k] * re[k] + im[k] * im[k]);
          if (mag > 1e-9f) {
            const float ph = T * atan2f(im[k], re[k]);
            yr[q] += mag * cosf(ph);
            yi[q] += mag * sinf(ph);
          }
        } else if (pitch > 0) {
          /* q = T k + r pitch for the smallest r in 1..T-1 that fits */
          for (int rr = 1; rr < T; rr++) {
            const int rem = q - rr * pitch;
            if (rem < 0 || rem % T) {
              continue;
            }
            const int k = rem / T;
            if (k + pitch >= n / 2) {
              break;
            }
            const float m0 = sqrtf(re[k] * re[k] + im[k] * im[k]);
            const float m1 = sqrtf(re[k + pitch] * re[k + pitch] + im[k + pitch] * im[k + pitch]);
            if (m0 > 1e-9f && m1 > 1e-9f) {
              const float ph = (T - rr) * atan2f(im[k], re[k]) + rr * atan2f(im[k + pitch], re[k + pitch]);
              const float mag = 0.5f * sqrtf(m0 * m1);
              yr[q] += mag * cosf(ph);
              yi[q] += mag * sinf(ph);
            }
            break;
          }
        }
      }
    }
    /* real output: mirror the spectrum, inverse transform through the
     * forward one on the conjugate */
    for (int q = 1; q < ns / 2; q++) {
      yr[ns - q] = yr[q];
      yi[ns - q] = -yi[q];
    }
    yi[0] = 0.0f;
    yi[ns / 2] = 0.0f;
    for (int q = 0; q < ns; q++) {
      yi[q] = -yi[q];
    }
    fft_forward(&u->fft_s, yr, yi, re, im);
    const float scale = ola_norm / (float)ns;
    float* dst = ch->hbe_out + (ptrdiff_t)(p - hop) * lo / lc;
    for (int i = 0; i < ns; i++) {
      dst[i] += yr[i] * u->hbe_win_s[i] * scale;
    }
  }
  /* The complete part: the first output_frame samples of the buffer. */
  memcpy(out, ch->hbe_out, (size_t)lo * sizeof(float));
  const int keep = (n + lc - hop) * lo / lc - lo;
  memmove(ch->hbe_out, ch->hbe_out + lo, (size_t)keep * sizeof(float));
  memset(ch->hbe_out + keep, 0, (size_t)lo * sizeof(float));
  memmove(ch->hbe_in, ch->hbe_in + lc, (size_t)n * sizeof(float));
}

/* ── PVC (7.5.6) ───────────────────────────────────────────────── */

/* Predicts the high band energies of every time slot from the smoothed
 * energies of three groups of low-band QMF bands below the crossover.
 * The standard's predictor tables (the coefficients and biases selected
 * by pvcID) are not reproduced here: the prediction continues the
 * low-band spectrum with a 3 dB roll-off per high-band group, so PVC
 * frames decode with approximate high-band levels. */
void pvc_predict(const AacSbrState* s, const AacSbrUsac* u, AacSbrUsacChannel* ch,
                 AacSbrUsacChannelData* d, const float X_low[32][kCols][2]) {
  const int kx = s->kx[1];
  const int m = s->m[1];
  const int rate = u->rate;
  const int low_bands = std::min(kx, 8);
  const int first = kx - low_bands;
  const int ns = d->pvc_ns_mode ? 4 : 16;
  for (int t = 0; t < AAC_SBR_USAC_TIME_SLOTS; t++) {
    float e_low[AAC_SBR_USAC_PVC_NB_LOW];
    for (int g = 0; g < AAC_SBR_USAC_PVC_NB_LOW; g++) {
      const int b0 = first + g * low_bands / AAC_SBR_USAC_PVC_NB_LOW;
      const int b1 = first + (g + 1) * low_bands / AAC_SBR_USAC_PVC_NB_LOW;
      float sum = 0.0f;
      int cnt = 0;
      for (int k = b0; k < b1; k++) {
        for (int i = 0; i < rate; i++) {
          const int col = u->offset + rate * t + i;
          sum += X_low[k][col][0] * X_low[k][col][0] + X_low[k][col][1] * X_low[k][col][1];
          cnt++;
        }
      }
      e_low[g] = 10.0f * log10f(sum / (float)std::max(cnt, 1) + 1e-3f);
    }
    /* history of the last 16 slots for the smoothing */
    memmove(ch->pvc_low_hist[1], ch->pvc_low_hist[0],
            (AAC_SBR_USAC_TIME_SLOTS - 1) * sizeof(ch->pvc_low_hist[0]));
    memcpy(ch->pvc_low_hist[0], e_low, sizeof(e_low));
    ch->pvc_hist_len = std::min(ch->pvc_hist_len + 1, AAC_SBR_USAC_TIME_SLOTS);
    const int len = std::min(ns, ch->pvc_hist_len);
    float sm[AAC_SBR_USAC_PVC_NB_LOW] = {0.0f, 0.0f, 0.0f};
    for (int j = 0; j < len; j++) {
      for (int g = 0; g < AAC_SBR_USAC_PVC_NB_LOW; g++) {
        sm[g] += ch->pvc_low_hist[j][g];
      }
    }
    for (int g = 0; g < AAC_SBR_USAC_PVC_NB_LOW; g++) {
      sm[g] /= (float)len;
    }
    const float base = 0.7f * sm[2] + 0.3f * sm[1];
    for (int h = 0; h < AAC_SBR_USAC_PVC_NB_HIGH; h++) {
      const float e_db = base - 3.0f * (float)(h + 1);
      const float e = powf(10.0f, e_db / 10.0f);
      const int m0 = h * m / AAC_SBR_USAC_PVC_NB_HIGH;
      const int m1 = (h + 1) * m / AAC_SBR_USAC_PVC_NB_HIGH;
      for (int b = m0; b < m1; b++) {
        d->pvc_env[t][b] = e;
      }
    }
  }
}

/* ── signal path ───────────────────────────────────────────────── */

/* The adjustment envelopes of a frame: the bitstream's, or one per time
 * slot with PVC. */
struct AdjGrid {
  int n;
  int t0[AAC_SBR_USAC_MAX_ENV];
  int t1[AAC_SBR_USAC_MAX_ENV];
  int src[AAC_SBR_USAC_MAX_ENV]; /* bitstream envelope */
};

void adj_grid(const AacSbrChannel* ch, const AacSbrUsacChannelData* d, AdjGrid* g) {
  if (d->pvc_active) {
    g->n = AAC_SBR_USAC_TIME_SLOTS;
    for (int t = 0; t < g->n; t++) {
      g->t0[t] = t;
      g->t1[t] = t + 1;
      g->src[t] = 0;
    }
    return;
  }
  g->n = ch->num_env;
  /* a concealed frame may have no envelope: the grid then starts at 0 */
  g->t0[0] = g->t1[0] = 0;
  g->src[0] = 0;
  for (int e = 0; e < g->n; e++) {
    g->t0[e] = ch->t_env[e];
    g->t1[e] = ch->t_env[e + 1];
    g->src[e] = e;
  }
}

/* X_low (band-major) from the channel's analysis buffer. */
void lf_gen(const AacSbrUsac* u, const AacSbrUsacChannel* ch, float X_low[32][kCols][2]) {
  const int cols = u->history + u->slots_of_frame;
  for (int k = 0; k < 32; k++) {
    for (int i = 0; i < cols; i++) {
      X_low[k][i][0] = ch->W_low[i][k][0];
      X_low[k][i][1] = ch->W_low[i][k][1];
    }
  }
}

/* Pre-flattening of the low band (7.5.2.4, bs_sbr_preprocessing): a
 * cubic polynomial fitted to the energies in dB of the k0 analysis bands
 * over the frame's envelope span gives, per source band, the gain that
 * levels the fitted slope to the mean energy before the patching. */
void pre_flatten(const float X_low[32][kCols][2], int bands, int start, int end, float* gain) {
  double env[32];
  double mean = 0.0;
  for (int k = 0; k < bands; k++) {
    double e = 0.0;
    for (int i = start; i < end; i++) {
      e += X_low[k][i][0] * X_low[k][i][0] + X_low[k][i][1] * X_low[k][i][1];
    }
    env[k] = 10.0 * log10(e / (double)(end - start) + 1.0);
    mean += env[k];
  }
  mean /= (double)bands;
  /* the normal equations of the least-squares cubic in k, solved by
   * Gaussian elimination with pivoting */
  double a[4][5] = {};
  for (int k = 0; k < bands; k++) {
    double v[7];
    v[0] = 1.0;
    for (int i = 1; i < 7; i++) {
      v[i] = v[i - 1] * (double)k;
    }
    for (int i = 0; i < 4; i++) {
      a[i][4] += v[i] * env[k];
      for (int j = 0; j < 4; j++) {
        a[i][j] += v[i + j];
      }
    }
  }
  for (int i = 0; i < 4; i++) {
    int pivot = i;
    for (int r = i + 1; r < 4; r++) {
      if (fabs(a[r][i]) > fabs(a[pivot][i])) {
        pivot = r;
      }
    }
    if (pivot != i) {
      for (int j = 0; j < 5; j++) {
        std::swap(a[i][j], a[pivot][j]);
      }
    }
    if (a[i][i] == 0.0) {
      break;
    }
    for (int r = i + 1; r < 4; r++) {
      const double f = a[r][i] / a[i][i];
      for (int j = i; j < 5; j++) {
        a[r][j] -= f * a[i][j];
      }
    }
  }
  double p[4] = {};
  for (int i = 3; i >= 0; i--) {
    double v = a[i][4];
    for (int j = i + 1; j < 4; j++) {
      v -= a[i][j] * p[j];
    }
    p[i] = a[i][i] != 0.0 ? v / a[i][i] : 0.0;
  }
  for (int k = 0; k < bands; k++) {
    const double x = (double)k;
    const double slope = p[0] + x * (p[1] + x * (p[2] + x * p[3]));
    gain[k] = (float)pow(10.0, (mean - slope) / 20.0);
  }
}

/* The high band for the frame's envelopes: the LPC patching with the
 * chirped predictors (the source bands pre-flattened when the frame's
 * SbrInfo asks for it), or the analysed transposer output.  A channel
 * whose low band came in as QMF samples (stereoConfigIndex 3, the MPS
 * 212 output) gave the transposer nothing to work on, so its frames are
 * patched even when bs_sbr_patching_mode asks for the transposer; the
 * reference decoder has no transposer buffer for that channel either. */
int hf_gen(AacSbrState* s, const AacSbrChannel* ch, const AacSbrUsacChannel* uc,
           const AacSbrUsacChannelData* d) {
  AacSbrUsac* u = &s->usac;
  const int a = u->offset;
  const int rate = u->rate;
  const int start = a + rate * ch->t_env[0];
  const int end = a + rate * ch->t_env[ch->num_env];
  const int kx = s->kx[1];
  const int m = s->m[1];
  if (end > kCols || start < 2) {
    return AAC_ERR_DECODE;
  }
  if (u->harmonic_sbr && !d->patching_mode && !uc->low_band_taken) {
    for (int k = kx; k < kx + m; k++) {
      for (int i = start; i < end; i++) {
        u->X_high[k][i][0] = uc->W_hbe[i][k][0];
        u->X_high[k][i][1] = uc->W_hbe[i][k][1];
      }
    }
    return 0;
  }
  const int hist = lpc_history(u);
  const int cov_cols = hist + u->slots_of_frame;
  const int k0 = std::min(s->k[0], 32);
  for (int k = 0; k < k0; k++) {
    aac_sbr_lpc_band(u->alpha0[k], u->alpha1[k], u->X_low[k] + (u->history - hist), cov_cols);
  }
  float pre_gain[32];
  if (u->preprocessing && k0 > 0 && end > start) {
    pre_flatten(u->X_low, k0, start, end, pre_gain);
  } else {
    std::fill(pre_gain, pre_gain + 32, 1.0f);
  }
  int g = 0;
  int k = kx;
  for (int j = 0; j < s->num_patches; j++) {
    for (int x = 0; x < s->patch_num_subbands[j]; x++, k++) {
      const int p = s->patch_start_subband[j] + x;
      while (g <= s->n_q && k >= s->f_tablenoise[g]) {
        g++;
      }
      g--;
      if (g < 0 || p < 0 || p >= 32) {
        return AAC_ERR_DECODE;
      }
      /* a source band above the core's analysis bands is silent, which
       * an 8:3 header may ask for */
      aac_sbr_hf_gen_band(u->X_high[k], u->X_low[p], u->alpha0[p], u->alpha1[p], uc->bw_array[g],
                          start, end);
      if (pre_gain[p] != 1.0f) {
        for (int i = start; i < end; i++) {
          u->X_high[k][i][0] *= pre_gain[p];
          u->X_high[k][i][1] *= pre_gain[p];
        }
      }
    }
  }
  for (; k < kx + m; k++) {
    memset(u->X_high[k], 0, sizeof(u->X_high[k]));
  }
  return 0;
}

int mapping(AacSbrState* s, AacSbrChannel* ch, const AacSbrUsacChannelData* d, const AdjGrid* g) {
  AacSbrUsac* u = &s->usac;
  const int kx = s->kx[1];
  const int m = s->m[1];
  memset(u->s_indexmapped[1], 0, (AAC_SBR_USAC_MAX_ENV) * sizeof(u->s_indexmapped[1]));
  for (int e = 0; e < g->n; e++) {
    const int src = g->src[e];
    const int res = ch->freq_res[src + 1];
    const uint16_t* table = res ? s->f_tablehigh : s->f_tablelow;
    const int ilim = s->n[res];
    if (kx != table[0]) {
      return AAC_ERR_DECODE;
    }
    if (d->pvc_active) {
      memcpy(u->e_origmapped[e], d->pvc_env[e], (size_t)m * sizeof(float));
    } else {
      for (int i = 0; i < ilim; i++) {
        for (int b = table[i]; b < table[i + 1]; b++) {
          u->e_origmapped[e][b - kx] = ch->env_facs[src + 1][i];
        }
      }
    }
    const int k = (ch->num_noise > 1) && (g->t0[e] >= ch->t_q[1]);
    for (int i = 0; i < s->n_q; i++) {
      for (int b = s->f_tablenoise[i]; b < s->f_tablenoise[i + 1]; b++) {
        u->q_mapped[e][b - kx] = ch->noise_facs[k + 1][i];
      }
    }
    if (ch->add_harmonic_flag) {
      for (int i = 0; i < s->n[1]; i++) {
        const int mid = (s->f_tablehigh[i] + s->f_tablehigh[i + 1]) >> 1;
        u->s_indexmapped[e + 1][mid - kx] =
            (uint8_t)(ch->add_harmonic[i] &&
                      (src >= ch->e_a[1] || u->s_indexmapped[0][mid - kx] == 1));
      }
    }
    if (d->pvc_active) {
      /* per QMF band: a sinusoid marks its own band */
      memcpy(u->s_mapped[e], u->s_indexmapped[e + 1], (size_t)m);
    } else {
      for (int i = 0; i < ilim; i++) {
        int present = 0;
        for (int b = table[i]; b < table[i + 1]; b++) {
          present |= u->s_indexmapped[e + 1][b - kx];
        }
        memset(&u->s_mapped[e][table[i] - kx], present, (size_t)(table[i + 1] - table[i]));
      }
    }
  }
  memcpy(u->s_indexmapped[0], u->s_indexmapped[g->n], sizeof(u->s_indexmapped[0]));
  return 0;
}

float sum_square(const float (*x)[2], int n) {
  float sum = 0.0f;
  for (int i = 0; i < n; i++) {
    sum += x[i][0] * x[i][0] + x[i][1] * x[i][1];
  }
  return sum;
}

void env_estimate(AacSbrState* s, const AacSbrChannel* ch, const AdjGrid* g) {
  AacSbrUsac* u = &s->usac;
  const int kx = s->kx[1];
  const int rate = u->rate;
  for (int e = 0; e < g->n; e++) {
    const int ilb = u->offset + rate * g->t0[e];
    const int iub = u->offset + rate * g->t1[e];
    const float recip = 1.0f / (float)(iub - ilb);
    const int res = ch->freq_res[g->src[e] + 1];
    if (s->interpol_freq || u->data[0].pvc_active) {
      for (int b = 0; b < s->m[1]; b++) {
        u->e_curr[e][b] = sum_square(u->X_high[b + kx] + ilb, iub - ilb) * recip;
      }
      continue;
    }
    const uint16_t* table = res ? s->f_tablehigh : s->f_tablelow;
    for (int p = 0; p < s->n[res]; p++) {
      float sum = 0.0f;
      for (int k = table[p]; k < table[p + 1]; k++) {
        sum += sum_square(u->X_high[k] + ilb, iub - ilb);
      }
      sum *= recip / (float)(table[p + 1] - table[p]);
      for (int k = table[p]; k < table[p + 1]; k++) {
        u->e_curr[e][k - kx] = sum;
      }
    }
  }
}

void gain_calc(AacSbrState* s, const AacSbrChannel* ch, const AdjGrid* g) {
  AacSbrUsac* u = &s->usac;
  static const float kLimGain[4] = {0.70795f, 1.0f, 1.41254f, 1e10f};
  const int kx = s->kx[1];
  for (int e = 0; e < g->n; e++) {
    const int src = g->src[e];
    const int delta = !((src == ch->e_a[1]) || (src == ch->e_a[0]));
    for (int k = 0; k < s->n_lim; k++) {
      const int m0 = s->f_tablelim[k] - kx;
      const int m1 = s->f_tablelim[k + 1] - kx;
      float sum[2] = {0.0f, 0.0f};
      for (int b = m0; b < m1; b++) {
        const float temp = u->e_origmapped[e][b] / (1.0f + u->q_mapped[e][b]);
        u->q_m[e][b] = sqrtf(temp * u->q_mapped[e][b]);
        u->s_m[e][b] = sqrtf(temp * (float)u->s_indexmapped[e + 1][b]);
        if (!u->s_mapped[e][b]) {
          u->gain[e][b] = sqrtf(u->e_origmapped[e][b] /
                                ((1.0f + u->e_curr[e][b]) * (1.0f + u->q_mapped[e][b] * (float)delta)));
        } else {
          u->gain[e][b] = sqrtf(u->e_origmapped[e][b] * u->q_mapped[e][b] /
                                ((1.0f + u->e_curr[e][b]) * (1.0f + u->q_mapped[e][b])));
        }
        u->gain[e][b] += FLT_MIN;
        sum[0] += u->e_origmapped[e][b];
        sum[1] += u->e_curr[e][b];
      }
      float gain_max = kLimGain[s->limiter_gains] * sqrtf((FLT_EPSILON + sum[0]) / (FLT_EPSILON + sum[1]));
      gain_max = std::min(100000.0f, gain_max);
      for (int b = m0; b < m1; b++) {
        const float q_m_max = u->q_m[e][b] * gain_max / u->gain[e][b];
        u->q_m[e][b] = std::min(u->q_m[e][b], q_m_max);
        u->gain[e][b] = std::min(u->gain[e][b], gain_max);
      }
      sum[0] = sum[1] = 0.0f;
      for (int b = m0; b < m1; b++) {
        sum[0] += u->e_origmapped[e][b];
        sum[1] += u->e_curr[e][b] * u->gain[e][b] * u->gain[e][b] + u->s_m[e][b] * u->s_m[e][b] +
                  (float)(delta && !u->s_m[e][b]) * u->q_m[e][b] * u->q_m[e][b];
      }
      float gain_boost = sqrtf((FLT_EPSILON + sum[0]) / (FLT_EPSILON + sum[1]));
      gain_boost = std::min(1.584893192f, gain_boost);
      for (int b = m0; b < m1; b++) {
        u->gain[e][b] *= gain_boost;
        u->q_m[e][b] *= gain_boost;
        u->s_m[e][b] *= gain_boost;
      }
    }
  }
}

/* Inter-TES (7.5.5): within an envelope flagged bs_temp_shape, the gain
 * of each QMF slot follows the low band's temporal envelope raised to
 * the mode's gamma, normalised to keep the envelope's energy. */
void inter_tes_gains(AacSbrState* s, const AacSbrChannel* ch, const AacSbrUsacChannelData* d,
                     AacSbrUsacChannel* uc, const AdjGrid* g) {
  AacSbrUsac* u = &s->usac;
  const int kx = s->kx[1];
  const int m = s->m[1];
  const int rate = u->rate;
  for (int i = 0; i < AAC_SBR_USAC_Y_SLOTS; i++) {
    uc->tes_gain[i] = 1.0f;
  }
  if (!u->inter_tes || d->pvc_active) {
    return;
  }
  for (int e = 0; e < g->n; e++) {
    const int src = g->src[e];
    if (!d->temp_shape[src]) {
      continue;
    }
    const float gamma = kInterTesGamma[d->temp_shape_mode[src] & 3];
    const int i0 = rate * g->t0[e];
    const int i1 = rate * g->t1[e];
    float e_low[AAC_SBR_USAC_Y_SLOTS];
    float e_high[AAC_SBR_USAC_Y_SLOTS];
    double mean_low = 0.0;
    for (int i = i0; i < i1; i++) {
      const int col = u->offset + i;
      float sl = 0.0f;
      for (int k = 0; k < kx; k++) {
        sl += u->X_low[k][col][0] * u->X_low[k][col][0] + u->X_low[k][col][1] * u->X_low[k][col][1];
      }
      float sh = 0.0f;
      for (int b = 0; b < m; b++) {
        const float gn = u->gain[e][b];
        sh += gn * gn * (u->X_high[kx + b][col][0] * u->X_high[kx + b][col][0] +
                         u->X_high[kx + b][col][1] * u->X_high[kx + b][col][1]);
      }
      e_low[i] = sl;
      e_high[i] = sh;
      mean_low += sl;
    }
    mean_low /= (double)(i1 - i0);
    if (mean_low <= 0.0) {
      continue;
    }
    double before = 0.0;
    double after = 0.0;
    for (int i = i0; i < i1; i++) {
      const float shape = powf((float)(e_low[i] / mean_low) + 1e-9f, 0.5f * gamma);
      uc->tes_gain[i] = shape;
      before += e_high[i];
      after += (double)shape * shape * e_high[i];
    }
    const float norm = after > 0.0 ? (float)sqrt(before / after) : 1.0f;
    for (int i = i0; i < i1; i++) {
      uc->tes_gain[i] *= norm;
    }
  }
}

void hf_assemble(AacSbrState* s, const AacSbrChannel* ch, AacSbrUsacChannel* uc,
                 float Y1[AAC_SBR_USAC_Y_SLOTS][64][2], const AdjGrid* g) {
  AacSbrUsac* u = &s->usac;
  const int h_SL = 4 * !s->smoothing_mode;
  const int kx = s->kx[1];
  const int m_max = s->m[1];
  const int rate = u->rate;
  const int a = u->offset;
  static const float kSmooth[5] = {0.33333333333333f, 0.30150283239582f, 0.21816949906249f,
                                   0.11516383427084f, 0.03183050093751f};
  float (*g_temp)[AAC_SBR_MAX_BANDS] = uc->g_temp;
  float (*q_temp)[AAC_SBR_MAX_BANDS] = uc->q_temp;
  unsigned indexnoise = uc->f_indexnoise;
  int indexsine = (int)uc->f_indexsine;
  const int first = rate * g->t0[0];
  if (s->reset) {
    for (int i = 0; i < h_SL; i++) {
      memcpy(g_temp[i + first], u->gain[0], (size_t)m_max * sizeof(float));
      memcpy(q_temp[i + first], u->q_m[0], (size_t)m_max * sizeof(float));
    }
  } else if (h_SL) {
    const int old = rate * ch->t_env_num_env_old;
    for (int i = 0; i < 4; i++) {
      memcpy(g_temp[i + first], g_temp[i + old], sizeof(g_temp[0]));
      memcpy(q_temp[i + first], q_temp[i + old], sizeof(q_temp[0]));
    }
  }
  for (int e = 0; e < g->n; e++) {
    for (int i = rate * g->t0[e]; i < rate * g->t1[e]; i++) {
      memcpy(g_temp[h_SL + i], u->gain[e], (size_t)m_max * sizeof(float));
      memcpy(q_temp[h_SL + i], u->q_m[e], (size_t)m_max * sizeof(float));
    }
  }
  for (int e = 0; e < g->n; e++) {
    const int src = g->src[e];
    const bool transient = src == ch->e_a[0] || src == ch->e_a[1];
    for (int i = rate * g->t0[e]; i < rate * g->t1[e]; i++) {
      float g_filt_tab[AAC_SBR_MAX_BANDS];
      float q_filt_tab[AAC_SBR_MAX_BANDS];
      const float* g_filt;
      const float* q_filt;
      if (h_SL && !transient) {
        g_filt = g_filt_tab;
        q_filt = q_filt_tab;
        for (int b = 0; b < m_max; b++) {
          float gs = 0.0f;
          float qs = 0.0f;
          for (int j = 0; j <= h_SL; j++) {
            gs += g_temp[i + h_SL - j][b] * kSmooth[j];
            qs += q_temp[i + h_SL - j][b] * kSmooth[j];
          }
          g_filt_tab[b] = gs;
          q_filt_tab[b] = qs;
        }
      } else {
        g_filt = g_temp[i + h_SL];
        q_filt = q_temp[i];
      }
      const float tes = uc->tes_gain[i];
      for (int b = 0; b < m_max; b++) {
        Y1[i][kx + b][0] = u->X_high[kx + b][i + a][0] * g_filt[b] * tes;
        Y1[i][kx + b][1] = u->X_high[kx + b][i + a][1] * g_filt[b] * tes;
      }
      if (!transient) {
        aac_sbr_apply_noise(Y1[i] + kx, u->s_m[e], q_filt, indexnoise, kx, m_max, indexsine);
      } else {
        const int idx = indexsine & 1;
        const int A = 1 - ((indexsine + (kx & 1)) & 2);
        const int B = (A ^ (-idx)) + idx;
        float* out = &Y1[i][kx][idx];
        const float* in = u->s_m[e];
        int b;
        for (b = 0; b + 1 < m_max; b += 2) {
          out[2 * b] += in[b] * (float)A;
          out[2 * b + 2] += in[b + 1] * (float)B;
        }
        if (m_max & 1) {
          out[2 * b] += in[b] * (float)A;
        }
      }
      indexnoise = (indexnoise + (unsigned)m_max) & 0x1ff;
      indexsine = (indexsine + 1) & 3;
    }
  }
  uc->f_indexnoise = indexnoise;
  uc->f_indexsine = (unsigned)indexsine;
}

/* The synthesis input: the low band from the analysis, the high band
 * from the adjusted buffers (the previous frame's for the slots its last
 * envelope still covers). */
void x_gen(const AacSbrState* s, const AacSbrChannel* ch, const AacSbrUsacChannel* uc,
           float X[2][AAC_SBR_USAC_MAX_SLOTS][64]) {
  const AacSbrUsac* u = &s->usac;
  const int slots = u->slots_of_frame;
  const int a = u->offset;
  const int i_temp = std::max(u->rate * ch->t_env_num_env_old - slots, 0);
  const float (*Y0)[64][2] = uc->Y[1 - uc->Ypos];
  const float (*Y1)[64][2] = uc->Y[uc->Ypos];
  memset(X, 0, 2 * sizeof(*X));
  /* the three lowest bands six slots ahead when MPS 212 follows: its
   * hybrid filter puts them back in step with the others */
  const int lead = u->mps_low_ahead ? kMpsHybridDelay : 0;
  int k;
  for (k = 0; k < s->kx[0] && k < 32; k++) {
    const int col = a + (k < kMpsLowBands ? lead : 0);
    for (int i = 0; i < i_temp; i++) {
      X[0][i][k] = u->X_low[k][i + col][0];
      X[1][i][k] = u->X_low[k][i + col][1];
    }
  }
  for (; k < s->kx[0] + s->m[0]; k++) {
    for (int i = 0; i < i_temp; i++) {
      X[0][i][k] = Y0[i + slots][k][0];
      X[1][i][k] = Y0[i + slots][k][1];
    }
  }
  for (k = 0; k < s->kx[1] && k < 32; k++) {
    const int col = a + (k < kMpsLowBands ? lead : 0);
    for (int i = i_temp; i < slots; i++) {
      X[0][i][k] = u->X_low[k][i + col][0];
      X[1][i][k] = u->X_low[k][i + col][1];
    }
  }
  for (; k < s->kx[1] + s->m[1]; k++) {
    for (int i = i_temp; i < slots; i++) {
      X[0][i][k] = Y1[i][k][0];
      X[1][i][k] = Y1[i][k][1];
    }
  }
}

/* Analysis of the frame into the channel's buffers: the core samples
 * (full scale 1.0) into the low-band history, and the transposer's
 * output when the harmonic SBR is on. */
void analyze_channel(AacSbrState* s, int c, const float* pcm) {
  AacSbrUsac* u = &s->usac;
  AacSbrUsacChannel* uc = &u->ch[c];
  AacSbrChannel* ch = &s->data[c];
  AacSbrUsacChannelData* d = &u->data[c];
  const int slots = u->slots_of_frame;
  const int bands = u->analysis_bands;
  const int hist = u->history;
  float core[AAC_USAC_MAX_CORE_FRAME];
  for (int i = 0; i < u->core_frame; i++) {
    core[i] = pcm[i] * 32768.0f;
  }
  uc->low_band_taken = 0;
  memmove(uc->W_low, uc->W_low + slots, (size_t)hist * sizeof(uc->W_low[0]));
  for (int i = 0; i < slots; i++) {
    float W[64][2];
    memset(W, 0, sizeof(W));
    aac_qmf_analyze(&ch->analysis, core + bands * i, W);
    memcpy(uc->W_low[hist + i], W, sizeof(uc->W_low[0]));
  }
  if (u->harmonic_sbr) {
    float transposed[AAC_USAC_MAX_OUTPUT_FRAME];
    hbe_process(u, uc, d, core, transposed, s->start ? s->kx[1] : 0);
    memmove(uc->W_hbe, uc->W_hbe + slots, (size_t)hist * sizeof(uc->W_hbe[0]));
    /* the transposed signal lags the core by 7 time slots */
    const int lag = 7 * u->rate;
    for (int i = 0; i < slots; i++) {
      aac_qmf_analyze(&uc->hbe_analysis, transposed + 64 * i, uc->W_hbe[hist - lag + i]);
    }
  }
}

/* The low band of the frame handed in as QMF samples (the MPS 212
 * output of a stereoConfigIndex 3 element) into the channel's history,
 * in place of its own analysis.  The transposer has no time signal to
 * work on here, so the channel is flagged for hf_gen to patch. */
void take_low_band(AacSbrState* s, int c, const struct AacUsacQmfFrame_* in) {
  AacSbrUsac* u = &s->usac;
  AacSbrUsacChannel* uc = &u->ch[c];
  const int slots = u->slots_of_frame;
  const int hist = u->history;
  uc->low_band_taken = 1;
  memmove(uc->W_low, uc->W_low + slots, (size_t)hist * sizeof(uc->W_low[0]));
  for (int i = 0; i < slots; i++) {
    for (int k = 0; k < 32; k++) {
      uc->W_low[hist + i][k][0] = in->x[i][k][0];
      uc->W_low[hist + i][k][1] = in->x[i][k][1];
    }
  }
}

/* From the channel's low-band history to the high band and the
 * adjusted output: the rest of the QMF-domain processing of one channel. */
void reconstruct_channel(AacSbrState* s, int c) {
  AacSbrUsac* u = &s->usac;
  AacSbrUsacChannel* uc = &u->ch[c];
  AacSbrChannel* ch = &s->data[c];
  AacSbrUsacChannelData* d = &u->data[c];
  lf_gen(u, uc, u->X_low);
  uc->Ypos ^= 1;
  if (s->start) {
    AdjGrid g;
    adj_grid(ch, d, &g);
    aac_sbr_chirp(s, ch);
    memcpy(uc->bw_array, ch->bw_array, sizeof(uc->bw_array));
    if (d->pvc_active) {
      pvc_predict(s, u, uc, d, u->X_low);
    }
    if (hf_gen(s, ch, uc, d) == 0 && mapping(s, ch, d, &g) == 0) {
      env_estimate(s, ch, &g);
      gain_calc(s, ch, &g);
      inter_tes_gains(s, ch, d, uc, &g);
      hf_assemble(s, ch, uc, uc->Y[uc->Ypos], &g);
    } else {
      usac_turnoff(s);
    }
  }
  x_gen(s, ch, uc, u->X);
}

/* The frame-level housekeeping before the first channel of a frame. */
void frame_begin(AacSbrState* s) {
  if (s->start && !s->ready_for_dequant) {
    usac_turnoff(s);
  }
  if (!s->kx_and_m_pushed) {
    s->kx[0] = s->kx[1];
    s->m[0] = s->m[1];
  } else {
    s->kx_and_m_pushed = 0;
  }
  if (s->start) {
    aac_sbr_dequant(s);
    s->ready_for_dequant = 0;
  }
}

void copy_x(const AacSbrUsac* u, struct AacUsacQmfFrame_* out) {
  out->slots = u->slots_of_frame;
  for (int i = 0; i < u->slots_of_frame; i++) {
    for (int k = 0; k < 64; k++) {
      out->x[i][k][0] = u->X[0][i][k];
      out->x[i][k][1] = u->X[1][i][k];
    }
  }
}

void anchor_check(AacSbrState* s, int nch) {
  bool anchored = true;
  for (int c = 0; c < nch; c++) {
    const AacSbrChannel* ch = &s->data[c];
    bool env = s->usac.data[c].pvc_active;
    bool noise = false;
    for (int i = 0; i < ch->num_env; i++) {
      env = env || !ch->df_env[i];
    }
    for (int i = 0; i < ch->num_noise; i++) {
      noise = noise || !ch->df_noise[i];
    }
    anchored = anchored && env && noise;
  }
  if (anchored) {
    s->unreliable = 0;
  }
}

}  // namespace

/* ── public ────────────────────────────────────────────────────── */

void aac_sbr_usac_init(AacSbrState* s, int core_sample_rate, int frame_length, int ratio,
                       const struct AacUsacSbrConfig_* cfg, int stereo_config_index) {
  memset(s, 0, sizeof(*s));
  AacSbrUsac* u = &s->usac;
  u->active = 1;
  u->mps_low_ahead = stereo_config_index == 1 || stereo_config_index == 2;
  u->ratio = ratio;
  u->core_frame = frame_length;
  switch (ratio) {
    case AAC_USAC_SBR_4_1:
      s->sample_rate = 4 * core_sample_rate;
      u->output_frame = 4 * frame_length;
      u->max_stretch = 4;
      break;
    case AAC_USAC_SBR_8_3:
      s->sample_rate = core_sample_rate * 8 / 3;
      u->output_frame = frame_length * 8 / 3;
      u->max_stretch = 3;
      break;
    default:
      s->sample_rate = 2 * core_sample_rate;
      u->output_frame = 2 * frame_length;
      u->max_stretch = 2;
      break;
  }
  u->slots_of_frame = u->output_frame / 64;
  s->slots = u->slots_of_frame;
  s->time_slots = AAC_SBR_USAC_TIME_SLOTS;
  u->rate = s->slots / s->time_slots;
  u->analysis_bands = frame_length / s->slots;
  u->harmonic_sbr = cfg->harmonic_sbr;
  u->inter_tes = cfg->inter_tes;
  u->pvc = cfg->pvc;
  const int delay = u->harmonic_sbr ? AAC_SBR_USAC_HBE_DELAY : 0;
  u->history = (4 + delay) * u->rate;
  u->offset = u->rate;
  u->hbe_n = frame_length / 2;
  u->hbe_ns = u->hbe_n * u->output_frame / frame_length;
  fft_init(&u->fft_a, u->hbe_n);
  fft_init(&u->fft_s, u->hbe_ns);
  hbe_window(u->hbe_win_a, u->hbe_n);
  hbe_window(u->hbe_win_s, u->hbe_ns);
  u->dflt_start_freq = cfg->dflt.start_freq;
  u->dflt_stop_freq = cfg->dflt.stop_freq;
  if (cfg->dflt.header_extra1) {
    u->dflt_freq_scale = cfg->dflt.freq_scale;
    u->dflt_alter_scale = cfg->dflt.alter_scale;
    u->dflt_noise_bands = cfg->dflt.noise_bands;
  } else {
    u->dflt_freq_scale = 2;
    u->dflt_alter_scale = 1;
    u->dflt_noise_bands = 2;
  }
  if (cfg->dflt.header_extra2) {
    u->dflt_limiter_bands = cfg->dflt.limiter_bands;
    u->dflt_limiter_gains = cfg->dflt.limiter_gains;
    u->dflt_interpol_freq = cfg->dflt.interpol_freq;
    u->dflt_smoothing_mode = cfg->dflt.smoothing_mode;
  } else {
    u->dflt_limiter_bands = 2;
    u->dflt_limiter_gains = 2;
    u->dflt_interpol_freq = 1;
    u->dflt_smoothing_mode = 1;
  }
  aac_sbr_turnoff(s);
  s->kx[0] = s->kx[1];
  for (int c = 0; c < 2; c++) {
    aac_qmf_analysis_init(&s->data[c].analysis, u->analysis_bands);
    aac_qmf_synthesis_init(&s->data[c].synthesis, 64);
    aac_qmf_analysis_init(&u->ch[c].hbe_analysis, 64);
    s->data[c].e_a[1] = -1;
  }
  s->elem_type = AAC_ELEM_SCE;
  /* the default header stands until a frame replaces it */
  s->amp_res_header = 1;
  s->xover_band = 0;
  apply_default_header(s);
  s->reset = 1;
  if (usac_make_tables(s)) {
    usac_turnoff(s);
  }
  s->ready_for_dequant = 0;
}

int aac_sbr_usac_decode(AacSbrState* s, AacBitReader* r, int elem_type, int nr_channels,
                        int indep) {
  AacSbrUsac* u = &s->usac;
  if (!u->active || (nr_channels != 1 && nr_channels != 2)) {
    return AAC_ERR_DECODE;
  }
  SavedParams saved;
  save_params(s, &saved);
  s->reset = 0;
  s->kx[0] = s->kx[1];
  s->m[0] = s->m[1];
  s->kx_and_m_pushed = 1;
  u->elem_type = elem_type;
  s->elem_type = nr_channels == 2 ? AAC_ELEM_CPE : AAC_ELEM_SCE;
  int info_present = 1;
  int header_present = 1;
  if (!indep) {
    info_present = (int)aac_bitreader_read(r, 1);
    header_present = info_present ? (int)aac_bitreader_read(r, 1) : 0;
  }
  if (info_present) {
    const int old_xover = s->xover_band;
    s->amp_res_header = (int)aac_bitreader_read(r, 1);
    s->xover_band = (int)aac_bitreader_read(r, 4);
    u->preprocessing = (int)aac_bitreader_read(r, 1);
    u->pvc_mode = u->pvc ? (int)aac_bitreader_read(r, 2) : 0;
    if (s->xover_band != old_xover) {
      s->reset = 1;
    }
  }
  if (header_present) {
    if (aac_bitreader_read(r, 1)) { /* sbrUseDfltHeader */
      apply_default_header(s);
    } else {
      read_header(s, r);
    }
  }
  int ret = 0;
  if (!s->start) {
    ret = AAC_ERR_DECODE;
  } else if (s->reset && (ret = usac_make_tables(s)) != 0) {
    ret = AAC_ERR_DECODE;
  }
  if (ret == 0) {
    s->ready_for_dequant = 1;
    ret = nr_channels == 1 ? read_single_channel(s, r, indep) : read_channel_pair(s, r, indep);
  }
  if (ret || r->overread) {
    restore_params(s, &saved);
    s->unreliable = 1;
    return AAC_ERR_DECODE;
  }
  anchor_check(s, nr_channels);
  u->frames++;
  return AAC_OK;
}

void aac_sbr_usac_apply_to_qmf(AacSbrState* s, int ch, const float* core,
                               struct AacUsacQmfFrame_* out) {
  if (ch == 0) {
    frame_begin(s);
  }
  analyze_channel(s, ch, core);
  reconstruct_channel(s, ch);
  copy_x(&s->usac, out);
}

void aac_sbr_usac_analyze_core(AacSbrState* s, const float* core, struct AacUsacQmfFrame_* out) {
  AacSbrUsac* u = &s->usac;
  const int slots = u->slots_of_frame;
  const int bands = u->analysis_bands;
  frame_begin(s);
  float pcm[AAC_USAC_MAX_CORE_FRAME];
  for (int i = 0; i < u->core_frame; i++) {
    pcm[i] = core[i] * 32768.0f;
  }
  /* the analysis at its own timing; the high band is the SBR's to make
   * per output channel */
  out->slots = slots;
  for (int i = 0; i < slots; i++) {
    memset(out->x[i], 0, sizeof(out->x[i]));
    aac_qmf_analyze(&s->data[0].analysis, pcm + bands * i, out->x[i]);
    for (int k = 32; k < 64; k++) {
      out->x[i][k][0] = out->x[i][k][1] = 0.0f;
    }
  }
}

void aac_sbr_usac_apply_low_to_qmf(AacSbrState* s, int ch, const struct AacUsacQmfFrame_* in,
                                   struct AacUsacQmfFrame_* out) {
  take_low_band(s, ch, in);
  reconstruct_channel(s, ch);
  copy_x(&s->usac, out);
}

void aac_sbr_usac_synthesize(AacSbrState* s, int ch, const struct AacUsacQmfFrame_* in,
                             float* out) {
  AacSbrUsac* u = &s->usac;
  for (int i = 0; i < u->slots_of_frame; i++) {
    aac_qmf_synthesize(&s->data[ch].synthesis, in->x[i], out + 64 * i);
  }
  for (int i = 0; i < u->output_frame; i++) {
    out[i] *= 1.0f / 32768.0f;
  }
}

void aac_sbr_usac_apply(AacSbrState* s, float* left, float* right) {
  const int nch = right ? 2 : 1;
  for (int c = 0; c < nch; c++) {
    float* pcm = c ? right : left;
    aac_sbr_usac_apply_to_qmf(s, c, pcm, &s->usac.frame);
    aac_sbr_usac_synthesize(s, c, &s->usac.frame, pcm);
  }
}
