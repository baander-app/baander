#include "decoder.h"

#include "asc.h"
#include "channel_map.h"
#include "er.h"

#include <algorithm>
#include <cmath>
#include <cstring>

/* AAC-LC decoding following ISO/IEC 14496-3 subpart 4: syntax (4.4/4.5),
 * noiseless decoding (4.6.3), dequantization and scalefactors (4.6.1/4.6.2),
 * M/S and intensity stereo (4.6.8), PNS (4.6.13), TNS (4.6.9) and the
 * filterbank with block switching (4.6.11). */

namespace {

/* Spectral values are coded for a 16-bit PCM scale; output is float with
 * full scale 1.0. */
constexpr float kOutputScale = 1.0f / 32768.0f;
constexpr unsigned kRandomSeed = 0x1f2e3d4c;

int lcg_random(unsigned* state) {
  *state = *state * 1664525u + 1013904223u;
  return (int)*state;
}

/* ── ics_info ──────────────────────────────────────────────────── */

int decode_ics_info(const AacDecoderState* s, AacBitReader* r, AacIcsInfo* ics) {
  if (s->aot == AAC_AOT_ER_ELD) {
    /* AAC-ELD: one long window of the low-delay filterbank, only max_sfb
     * is sent. */
    ics->window_shape = 0;
    ics->max_sfb = (int)aac_bitreader_read(r, 6);
    aac_ics_layout(ics, s->rate_index, s->frame_length, AAC_WIN_ONLY_LONG, 0);
    return ics->max_sfb > ics->num_swb || r->overread ? AAC_ERR_DECODE : 0;
  }
  if (aac_bitreader_read(r, 1)) { /* ics_reserved_bit */
    return AAC_ERR_DECODE;
  }
  const int seq = (int)aac_bitreader_read(r, 2);
  if (s->aot == AAC_AOT_ER_LD && seq != AAC_WIN_ONLY_LONG) {
    return AAC_ERR_DECODE; /* AAC-LD codes every frame in one long window */
  }
  ics->window_shape = (int)aac_bitreader_read(r, 1);
  if (seq == AAC_WIN_EIGHT_SHORT) {
    ics->max_sfb = (int)aac_bitreader_read(r, 4);
    aac_ics_layout(ics, s->rate_index, s->frame_length, seq, (int)aac_bitreader_read(r, 7));
  } else {
    ics->max_sfb = (int)aac_bitreader_read(r, 6);
    aac_ics_layout(ics, s->rate_index, s->frame_length, seq, 0);
    if (aac_bitreader_read(r, 1)) {
      /* predictor_data_present: only AAC Main and LTP carry predictors. */
      return AAC_ERR_UNSUPPORTED;
    }
  }
  if (ics->max_sfb > ics->num_swb) {
    return AAC_ERR_DECODE;
  }
  return r->overread ? AAC_ERR_DECODE : 0;
}

/* ── section_data / scale_factor_data ──────────────────────────── */

int decode_section_data(AacBitReader* r, AacChannelStream* cs) {
  const AacIcsInfo* ics = &cs->ics;
  const int bits = ics->num_windows == 8 ? 3 : 5;
  const int esc = (1 << bits) - 1;
  for (int g = 0; g < ics->num_window_groups; g++) {
    int k = 0;
    while (k < ics->max_sfb) {
      const int cb = (int)aac_bitreader_read(r, 4);
      if (cb == 12) {
        return AAC_ERR_DECODE;
      }
      int end = k;
      int incr = 0;
      do {
        incr = (int)aac_bitreader_read(r, bits);
        end += incr;
        if (r->overread || end > ics->max_sfb) {
          return AAC_ERR_DECODE;
        }
      } while (incr == esc);
      for (; k < end; k++) {
        cs->band_type[g * ics->max_sfb + k] = cb;
      }
    }
  }
  return 0;
}

int decode_scalefactors(AacBitReader* r, AacChannelStream* cs, int global_gain) {
  const AacIcsInfo* ics = &cs->ics;
  int sf = global_gain;
  int noise = global_gain - 90;
  int is_pos = 0;
  bool first_noise = true;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < ics->max_sfb; b++) {
      const int idx = g * ics->max_sfb + b;
      const int cb = cs->band_type[idx];
      if (cb == 0) {
        cs->sf[idx] = 0;
        continue;
      }
      int diff = 0;
      if (cb == AAC_CB_NOISE && first_noise) {
        diff = (int)aac_bitreader_read(r, 9) - 256;
        first_noise = false;
      } else {
        diff = aac_bitreader_read_scalefactor(r);
        if (diff == AAC_SF_INVALID) {
          return AAC_ERR_DECODE;
        }
      }
      if (cb == AAC_CB_INTENSITY || cb == AAC_CB_INTENSITY2) {
        is_pos += diff;
        cs->sf[idx] = is_pos < -155 ? -155 : (is_pos > 100 ? 100 : is_pos);
      } else if (cb == AAC_CB_NOISE) {
        noise += diff;
        cs->sf[idx] = noise < -100 ? -100 : (noise > 155 ? 155 : noise);
      } else {
        sf += diff;
        if (sf < 0 || sf > 255) {
          return AAC_ERR_DECODE;
        }
        cs->sf[idx] = sf;
      }
    }
  }
  return r->overread ? AAC_ERR_DECODE : 0;
}

/* ── pulse_data / tns_data ─────────────────────────────────────── */

struct PulseData {
  int num_pulse;
  int pos[4];
  int amp[4];
};

int decode_pulse_data(AacBitReader* r, const AacIcsInfo* ics, PulseData* p) {
  p->num_pulse = (int)aac_bitreader_read(r, 2) + 1;
  const int start_sfb = (int)aac_bitreader_read(r, 6);
  if (start_sfb >= ics->num_swb) {
    return AAC_ERR_DECODE;
  }
  int pos = ics->swb_offset[start_sfb];
  for (int i = 0; i < p->num_pulse; i++) {
    pos += (int)aac_bitreader_read(r, 5);
    if (pos >= ics->frame_length) {
      return AAC_ERR_DECODE;
    }
    p->pos[i] = pos;
    p->amp[i] = (int)aac_bitreader_read(r, 4);
  }
  return r->overread ? AAC_ERR_DECODE : 0;
}

int decode_tns_data(AacBitReader* r, const AacIcsInfo* ics, AacTnsData* tns) {
  const int is8 = ics->num_windows == 8;
  const int max_order = is8 ? 7 : 12;
  for (int w = 0; w < ics->num_windows; w++) {
    tns->n_filt[w] = (int)aac_bitreader_read(r, is8 ? 1 : 2);
    if (!tns->n_filt[w]) {
      continue;
    }
    const int coef_res = (int)aac_bitreader_read(r, 1);
    for (int f = 0; f < tns->n_filt[w]; f++) {
      tns->length[w][f] = (int)aac_bitreader_read(r, is8 ? 4 : 6);
      const int order = (int)aac_bitreader_read(r, is8 ? 3 : 5);
      if (order > max_order) {
        return AAC_ERR_DECODE;
      }
      tns->order[w][f] = order;
      if (!order) {
        continue;
      }
      tns->direction[w][f] = (int)aac_bitreader_read(r, 1);
      const int compress = (int)aac_bitreader_read(r, 1);
      const int coef_bits = coef_res + 3 - compress;
      /* Inverse quantization of the reflection coefficients (4.6.9.3). */
      const double res_bits = coef_res + 3;
      const double iqfac = ((1 << (int)(res_bits - 1)) - 0.5) / (M_PI / 2.0);
      const double iqfac_m = ((1 << (int)(res_bits - 1)) + 0.5) / (M_PI / 2.0);
      for (int i = 0; i < order; i++) {
        int v = (int)aac_bitreader_read(r, coef_bits);
        if (v & (1 << (coef_bits - 1))) {
          v -= 1 << coef_bits;
        }
        tns->coef[w][f][i] = (float)sin(v / (v >= 0 ? iqfac : iqfac_m));
      }
    }
  }
  return r->overread ? AAC_ERR_DECODE : 0;
}

/* ── spectral_data ─────────────────────────────────────────────── */

int decode_spectral_data(AacBitReader* r, AacChannelStream* cs) {
  const AacIcsInfo* ics = &cs->ics;
  const int* off = ics->swb_offset;
  memset(cs->quant, 0, sizeof(cs->quant));
  int win = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < ics->max_sfb; b++) {
      const int cb = cs->band_type[g * ics->max_sfb + b];
      if (!aac_cb_is_spectral(cb)) {
        continue;
      }
      /* A virtual codebook (16..31) reads with the escape codebook and
       * checks its magnitude limit. */
      const int book = aac_cb_huffman_book(cb);
      const int dim = aac_codebook_info[book].dim;
      for (int w = 0; w < ics->group_len[g]; w++) {
        int* q = cs->quant + (win + w) * 128;
        for (int k = off[b]; k < off[b + 1]; k += dim) {
          const int ret = book == cb ? aac_bitreader_read_huffman(r, cb, q + k)
                                     : aac_er_read_vcb11(r, cb, q + k);
          if (ret != 0) {
            return AAC_ERR_DECODE;
          }
        }
      }
    }
    win += ics->group_len[g];
  }
  return 0;
}

/* individual_channel_stream() (Table 4.50).  With common_window the
 * ics_info is already in cs->ics.  The error resilient object types send
 * tns_data() after the gain control flag, and AAC-ELD has neither pulse
 * nor gain control data. */
int decode_ics(AacDecoderState* s, AacBitReader* r, AacChannelStream* cs, int common_window) {
  const bool er = aac_aot_is_er(s->aot);
  const bool eld = s->aot == AAC_AOT_ER_ELD;
  const int global_gain = (int)aac_bitreader_read(r, 8);
  int ret = 0;
  if (!common_window && (ret = decode_ics_info(s, r, &cs->ics)) != 0) {
    return ret;
  }
  /* The error resilience tools replace the section, scalefactor and
   * spectral syntax when the configuration switches them on. */
  ret = er && s->section_resilience ? aac_er_read_section_data(r, &cs->ics, cs->band_type)
                                    : decode_section_data(r, cs);
  if (ret != 0) {
    return ret;
  }
  if (er && s->scalefactor_resilience) {
    int outcome = AAC_RVLC_OK;
    ret = aac_er_read_rvlc_scalefactors(r, &cs->ics, cs->band_type, global_gain, cs->sf, &outcome);
    if (ret != 0) {
      return ret;
    }
    if (outcome == AAC_RVLC_RECOVERED) {
      /* Damaged scalefactor data that the backward pass and the
       * interpolation filled in: the frame plays, as a concealed one,
       * when concealment is on. */
      if (s->conceal_mode == AAC_CONCEAL_OFF) {
        return AAC_ERR_DECODE;
      }
      s->rvlc_recovered = 1;
    }
  } else if ((ret = decode_scalefactors(r, cs, global_gain)) != 0) {
    return ret;
  }
  PulseData pulse = {};
  const int pulse_present = eld ? 0 : (int)aac_bitreader_read(r, 1);
  if (pulse_present) {
    if (cs->ics.num_windows == 8) {
      return AAC_ERR_DECODE; /* pulse data is only allowed in long windows */
    }
    if ((ret = decode_pulse_data(r, &cs->ics, &pulse)) != 0) {
      return ret;
    }
  }
  cs->tns_present = (int)aac_bitreader_read(r, 1);
  if (!er && cs->tns_present && (ret = decode_tns_data(r, &cs->ics, &cs->tns)) != 0) {
    return ret;
  }
  if (!eld && aac_bitreader_read(r, 1)) {
    return AAC_ERR_UNSUPPORTED; /* gain_control_data: AAC SSR only */
  }
  if (er && cs->tns_present && (ret = decode_tns_data(r, &cs->ics, &cs->tns)) != 0) {
    return ret;
  }
  if (er && s->spectral_resilience) {
    const int length = (int)aac_bitreader_read(r, 14); /* length_of_reordered_spectral_data */
    const int longest = (int)aac_bitreader_read(r, 6); /* length_of_longest_codeword */
    if (r->overread) {
      return AAC_ERR_DECODE;
    }
    ret = aac_er_read_hcr(r, &cs->ics, cs->band_type, length, longest, cs->quant);
  } else {
    ret = decode_spectral_data(r, cs);
  }
  if (ret != 0) {
    return ret;
  }
  for (int i = 0; i < (pulse_present ? pulse.num_pulse : 0); i++) {
    int& q = cs->quant[pulse.pos[i]];
    q += q > 0 ? pulse.amp[i] : -pulse.amp[i];
  }
  return r->overread ? AAC_ERR_DECODE : 0;
}

/* ── dequantization and PNS ────────────────────────────────────── */

float dequant(int q) {
  const int a = q < 0 ? -q : q;
  const float v = a < AAC_POW43_SIZE ? aac_pow43[a] : powf((float)a, 4.0f / 3.0f);
  return q < 0 ? -v : v;
}

/* Fills one band of noise with energy 2^(nrg/2) (4.6.13.3). */
void fill_noise(unsigned* state, float* dst, int len, int nrg) {
  float energy = 0.0f;
  for (int k = 0; k < len; k++) {
    dst[k] = (float)lcg_random(state);
    energy += dst[k] * dst[k];
  }
  const float scale = exp2f(0.25f * (float)nrg) / sqrtf(energy);
  for (int k = 0; k < len; k++) {
    dst[k] *= scale;
  }
}

/* noise_from[] supplies, per band, the coefficients of the left channel when
 * the right channel uses correlated noise (M/S-flagged PNS bands). */
void dequantize(AacDecoderState* s, AacChannelStream* cs, const int* correlated,
                const float* left) {
  const AacIcsInfo* ics = &cs->ics;
  const int* off = ics->swb_offset;
  memset(cs->coef, 0, sizeof(cs->coef));
  int win = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < ics->max_sfb; b++) {
      const int idx = g * ics->max_sfb + b;
      const int cb = cs->band_type[idx];
      const int len = off[b + 1] - off[b];
      if (cb == 0 || cb == AAC_CB_INTENSITY || cb == AAC_CB_INTENSITY2) {
        continue;
      }
      for (int w = 0; w < ics->group_len[g]; w++) {
        const int base = (win + w) * 128 + off[b];
        float* dst = cs->coef + base;
        if (cb == AAC_CB_NOISE) {
          if (correlated && correlated[idx]) {
            /* Same noise vector as the left channel, rescaled. */
            float energy = 0.0f;
            for (int k = 0; k < len; k++) {
              energy += left[base + k] * left[base + k];
            }
            const float scale =
                energy > 0.0f ? exp2f(0.25f * (float)cs->sf[idx]) / sqrtf(energy) : 0.0f;
            for (int k = 0; k < len; k++) {
              dst[k] = left[base + k] * scale;
            }
          } else {
            fill_noise(&s->random_state, dst, len, cs->sf[idx]);
          }
          continue;
        }
        const float gain = exp2f(0.25f * (float)(cs->sf[idx] - 100));
        const int* q = cs->quant + base;
        for (int k = 0; k < len; k++) {
          dst[k] = dequant(q[k]) * gain;
        }
      }
    }
    win += ics->group_len[g];
  }
}

/* ── TNS (4.6.9.3) ─────────────────────────────────────────────── */

void apply_tns(AacChannelStream* cs) {
  const AacIcsInfo* ics = &cs->ics;
  const AacTnsData* tns = &cs->tns;
  const int mmm = ics->tns_max_bands < ics->max_sfb ? ics->tns_max_bands : ics->max_sfb;
  if (!cs->tns_present || mmm == 0) {
    return;
  }
  for (int w = 0; w < ics->num_windows; w++) {
    int bottom = ics->num_swb;
    for (int f = 0; f < tns->n_filt[w]; f++) {
      const int top = bottom;
      bottom = top - tns->length[w][f];
      if (bottom < 0) {
        bottom = 0;
      }
      const int order = tns->order[w][f];
      if (!order) {
        continue;
      }
      /* Reflection coefficients to direct-form LPC (step-up recursion). */
      float lpc[AAC_TNS_MAX_ORDER + 1];
      lpc[0] = 1.0f;
      for (int m = 1; m <= order; m++) {
        const float k = tns->coef[w][f][m - 1];
        float tmp[AAC_TNS_MAX_ORDER + 1];
        for (int i = 1; i < m; i++) {
          tmp[i] = lpc[i] + k * lpc[m - i];
        }
        for (int i = 1; i < m; i++) {
          lpc[i] = tmp[i];
        }
        lpc[m] = k;
      }
      int start = ics->swb_offset[bottom < mmm ? bottom : mmm];
      const int end = ics->swb_offset[top < mmm ? top : mmm];
      const int size = end - start;
      if (size <= 0) {
        continue;
      }
      int inc = 1;
      if (tns->direction[w][f]) {
        inc = -1;
        start = end - 1;
      }
      float* x = cs->coef + w * 128;
      /* All-pole filter, run in place over the already filtered output. */
      for (int m = 0, n = start; m < size; m++, n += inc) {
        const int lim = m < order ? m : order;
        for (int i = 1; i <= lim; i++) {
          x[n] -= x[n - i * inc] * lpc[i];
        }
      }
    }
  }
}

/* ── filterbank (4.6.11) ───────────────────────────────────────── */

/* The window of a frame spans two blocks of `fl` samples (1024 or 960);
 * the eight short windows of `sl` lines sit in the middle, starting at
 * (fl - sl) / 2 (448 or 420). */
/* Samples per channel the core filterbank produces for one block: the
 * frame length, divided by the downscale factor of an AAC-ELD stream. */
int core_output_length(const AacDecoderState* s) {
  return s->aot == AAC_AOT_ER_ELD ? s->frame_length / s->eld_downscale : s->frame_length;
}

/* Whether the output runs at twice the core rate: SBR data has been seen,
 * or the configuration announced dual-rate LD-SBR. */
bool doubled_output(const AacDecoderState* s) {
  return s->sbr_mode || (s->ld_sbr_mode && s->ld_sbr_dual_rate);
}

/* Samples per channel of one block in the output. */
int output_length(const AacDecoderState* s) {
  return doubled_output(s) ? 2 * core_output_length(s) : core_output_length(s);
}

/* AAC-ELD: the low-delay synthesis of the (downscaled) frame. */
void eld_synthesis(AacDecoderState* s, AacDecoderChannel* ch) {
  const int m = core_output_length(s);
  aac_eld_synthesis(&s->mdct_eld, aac_eld_synthesis_window(s->frame_length, s->eld_downscale), m,
                    ch->cs.coef, ch->eld_overlap, ch->output, kOutputScale);
}

void imdct_and_window(AacDecoderState* s, AacDecoderChannel* ch) {
  if (s->aot == AAC_AOT_ER_ELD) {
    eld_synthesis(s, ch);
    return;
  }
  const AacIcsInfo* ics = &ch->cs.ics;
  const int seq = ics->window_sequence;
  const int fl = s->frame_length;
  const int sl = aac_short_length(fl);
  const int start = (fl - sl) / 2;
  const float* lw_prev = aac_window_long_for(fl, ch->prev_window_shape);
  const float* lw = aac_window_long_for(fl, ics->window_shape);
  const float* sw_prev = aac_window_short_for(fl, ch->prev_window_shape);
  const float* sw = aac_window_short_for(fl, ics->window_shape);
  float buf[2 * AAC_FRAME_SIZE_LONG];

  if (seq != AAC_WIN_EIGHT_SHORT) {
    aac_imdct(&s->mdct_long, ch->cs.coef, buf);
    if (seq == AAC_WIN_LONG_STOP) {
      memset(buf, 0, (size_t)start * sizeof(float));
      for (int i = 0; i < sl; i++) {
        buf[start + i] *= sw_prev[i];
      }
    } else {
      s->dsp->vector_fmul(buf, buf, lw_prev, fl);
    }
    if (seq == AAC_WIN_LONG_START) {
      for (int i = 0; i < sl; i++) {
        buf[fl + start + i] *= sw[sl - 1 - i];
      }
      memset(buf + fl + start + sl, 0, (size_t)start * sizeof(float));
    } else {
      s->dsp->vector_fmul_reverse(buf + fl, buf + fl, lw, fl);
    }
  } else {
    memset(buf, 0, sizeof(buf));
    float sb[2 * AAC_FRAME_SIZE_SHORT];
    for (int w = 0; w < 8; w++) {
      aac_imdct(&s->mdct_short, ch->cs.coef + w * AAC_FRAME_SIZE_SHORT, sb);
      const float* left = w == 0 ? sw_prev : sw;
      float* dst = buf + start + w * sl;
      for (int i = 0; i < sl; i++) {
        dst[i] += sb[i] * left[i];
        dst[sl + i] += sb[sl + i] * sw[sl - 1 - i];
      }
    }
  }
  for (int i = 0; i < fl; i++) {
    ch->output[i] = (ch->overlap[i] + buf[i]) * kOutputScale;
  }
  memcpy(ch->overlap, buf + fl, (size_t)fl * sizeof(float));
  ch->prev_window_shape = ics->window_shape;
}

/* ── stereo tools (4.6.8) ──────────────────────────────────────── */

struct MsMask {
  int present;
  int used[AAC_MAX_BANDS];
};

bool is_noise_or_intensity(int cb) { return cb >= AAC_CB_NOISE && cb <= AAC_CB_INTENSITY; }

void apply_ms(AacChannelStream* l, AacChannelStream* r, const MsMask* ms) {
  const AacIcsInfo* ics = &l->ics;
  const int* off = ics->swb_offset;
  int win = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < ics->max_sfb; b++) {
      const int idx = g * ics->max_sfb + b;
      /* Noise and intensity bands are not M/S coded; a zero band is,
       * and a virtual codebook band is spectral data like any other. */
      if (!ms->used[idx] || is_noise_or_intensity(l->band_type[idx]) ||
          is_noise_or_intensity(r->band_type[idx])) {
        continue;
      }
      for (int w = 0; w < ics->group_len[g]; w++) {
        float* a = l->coef + (win + w) * 128 + off[b];
        float* c = r->coef + (win + w) * 128 + off[b];
        for (int k = 0; k < off[b + 1] - off[b]; k++) {
          const float m = a[k];
          const float side = c[k];
          a[k] = m + side;
          c[k] = m - side;
        }
      }
    }
    win += ics->group_len[g];
  }
}

void apply_intensity(AacChannelStream* l, AacChannelStream* r, const MsMask* ms) {
  const AacIcsInfo* ics = &r->ics;
  const int* off = ics->swb_offset;
  int win = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < ics->max_sfb; b++) {
      const int idx = g * ics->max_sfb + b;
      const int cb = r->band_type[idx];
      if (cb != AAC_CB_INTENSITY && cb != AAC_CB_INTENSITY2) {
        continue;
      }
      float c = cb == AAC_CB_INTENSITY ? 1.0f : -1.0f;
      if (ms->present && ms->used[idx]) {
        c = -c;
      }
      const float scale = c * exp2f(-0.25f * (float)r->sf[idx]);
      for (int w = 0; w < ics->group_len[g]; w++) {
        const float* src = l->coef + (win + w) * 128 + off[b];
        float* dst = r->coef + (win + w) * 128 + off[b];
        for (int k = 0; k < off[b + 1] - off[b]; k++) {
          dst[k] = src[k] * scale;
        }
      }
    }
    win += ics->group_len[g];
  }
}

/* ── elements ──────────────────────────────────────────────────── */

int decode_sce(AacDecoderState* s, AacBitReader* r, AacDecoderChannel* ch) {
  AacChannelStream* cs = &ch->cs;
  int ret = decode_ics(s, r, cs, 0);
  if (ret) {
    return ret;
  }
  dequantize(s, cs, nullptr, nullptr);
  return 0;
}

int decode_cpe(AacDecoderState* s, AacBitReader* r, AacDecoderChannel* left,
               AacDecoderChannel* right) {
  AacChannelStream* l = &left->cs;
  AacChannelStream* rc = &right->cs;
  MsMask ms = {};
  /* AAC-ELD channel pairs always share one ics_info. */
  const int common_window = s->aot == AAC_AOT_ER_ELD ? 1 : (int)aac_bitreader_read(r, 1);
  int ret = 0;
  if (common_window) {
    if ((ret = decode_ics_info(s, r, &l->ics)) != 0) {
      return ret;
    }
    rc->ics = l->ics;
    ms.present = (int)aac_bitreader_read(r, 2);
    if (ms.present == 3) {
      return AAC_ERR_DECODE;
    }
    const int nbands = l->ics.num_window_groups * l->ics.max_sfb;
    for (int i = 0; i < nbands; i++) {
      ms.used[i] = ms.present == 1 ? (int)aac_bitreader_read(r, 1) : (ms.present == 2);
    }
  }
  if ((ret = decode_ics(s, r, l, common_window)) != 0) {
    return ret;
  }
  if (s->num_crc_regions > 0) {
    s->crc_region[s->num_crc_regions - 1].second = r->pos;
  }
  if ((ret = decode_ics(s, r, rc, common_window)) != 0) {
    return ret;
  }
  dequantize(s, l, nullptr, nullptr);
  int correlated[AAC_MAX_BANDS] = {};
  if (common_window && ms.present) {
    const int nbands = l->ics.num_window_groups * l->ics.max_sfb;
    for (int i = 0; i < nbands; i++) {
      correlated[i] =
          ms.used[i] && l->band_type[i] == AAC_CB_NOISE && rc->band_type[i] == AAC_CB_NOISE;
    }
  }
  dequantize(s, rc, correlated, l->coef);
  if (common_window) {
    if (ms.present) {
      apply_ms(l, rc, &ms);
    }
    apply_intensity(l, rc, &ms);
  }
  return 0;
}

/* coupling_channel_element() (Table 4.8). */
int decode_cce(AacDecoderState* s, AacBitReader* r, AacDecoderCce* cce) {
  static const float kScale[4] = {1.0905077327f, 1.1892071150f, 1.4142135624f, 2.0f};
  AacCoupling* c = &cce->coup;
  c->ind_sw = (int)aac_bitreader_read(r, 1);
  c->num_coupled = (int)aac_bitreader_read(r, 3) + 1;
  c->num_gains = 0;
  for (int i = 0; i < c->num_coupled; i++) {
    c->num_gains++;
    c->target_type[i] = aac_bitreader_read(r, 1) ? AAC_ELEM_CPE : AAC_ELEM_SCE;
    c->target_tag[i] = (int)aac_bitreader_read(r, 4);
    if (c->target_type[i] == AAC_ELEM_CPE) {
      c->ch_select[i] = (int)aac_bitreader_read(r, 2);
      if (c->ch_select[i] == 3) {
        c->num_gains++;
      }
    } else {
      c->ch_select[i] = 2;
    }
  }
  c->domain = (int)aac_bitreader_read(r, 1);
  const int sign = (int)aac_bitreader_read(r, 1);
  const float scale = kScale[aac_bitreader_read(r, 2)];
  if (r->overread) {
    return AAC_ERR_DECODE;
  }
  AacChannelStream* cs = &cce->ch.cs;
  int ret = decode_ics(s, r, cs, 0);
  if (ret) {
    return ret;
  }
  const AacIcsInfo* ics = &cs->ics;
  for (int g = 0; g < c->num_gains; g++) {
    float gain = 1.0f;
    int cge = 1;
    int acc = 0;
    if (g > 0) {
      cge = c->ind_sw ? 1 : (int)aac_bitreader_read(r, 1);
      if (cge) {
        acc = aac_bitreader_read_scalefactor(r);
        if (acc == AAC_SF_INVALID) {
          return AAC_ERR_DECODE;
        }
        gain = powf(scale, (float)-acc);
      }
    }
    if (c->ind_sw) {
      c->gain[g][0] = gain;
      continue;
    }
    for (int grp = 0; grp < ics->num_window_groups; grp++) {
      for (int b = 0; b < ics->max_sfb; b++) {
        const int idx = grp * ics->max_sfb + b;
        if (cs->band_type[idx] == 0) {
          continue;
        }
        if (!cge) {
          const int diff = aac_bitreader_read_scalefactor(r);
          if (diff == AAC_SF_INVALID) {
            return AAC_ERR_DECODE;
          }
          if (diff) {
            acc += diff;
            int t = acc;
            float sgn = 1.0f;
            if (sign) {
              sgn = (t & 1) ? -1.0f : 1.0f;
              t >>= 1;
            }
            gain = sgn * powf(scale, (float)-t);
          }
        }
        c->gain[g][idx] = gain;
      }
    }
  }
  if (r->overread) {
    return AAC_ERR_DECODE;
  }
  dequantize(s, cs, nullptr, nullptr);
  return 0;
}

/* Adds the coupling channel's spectrum, scaled per band, to a target. */
void apply_dependent_coupling(const AacDecoderCce* cce, int gain_index, AacChannelStream* target) {
  const AacChannelStream* src = &cce->ch.cs;
  const AacIcsInfo* ics = &src->ics;
  const int* off = ics->swb_offset;
  const float* gains = cce->coup.gain[gain_index];
  int win = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < ics->max_sfb; b++) {
      const int idx = g * ics->max_sfb + b;
      if (src->band_type[idx] == 0) {
        continue;
      }
      const float gain = gains[idx];
      for (int w = 0; w < ics->group_len[g]; w++) {
        const int base = (win + w) * 128;
        for (int k = off[b]; k < off[b + 1]; k++) {
          target->coef[base + k] += gain * src->coef[base + k];
        }
      }
    }
    win += ics->group_len[g];
  }
}

void apply_independent_coupling(const AacDecoderCce* cce, int gain_index, AacDecoderChannel* target,
                                int n) {
  const float gain = cce->coup.gain[gain_index][0];
  for (int i = 0; i < n; i++) {
    target->output[i] += gain * cce->ch.output[i];
  }
}

/* Runs every present coupling element of the given kind over the target
 * element: dependent couplings of `domain` on the spectra, independent
 * ones (domain < 0) on the time signal. */
void apply_couplings(AacDecoderState* s, int elem_index, int domain) {
  const AacLayoutElement* e = &s->layout.elems[elem_index];
  AacDecoderChannel* chans = &s->ch[s->elem_base[elem_index]];
  for (int k = 0; k < AAC_MAX_CCE; k++) {
    AacDecoderCce* cce = &s->cce[k];
    if (!cce->present) {
      continue;
    }
    const AacCoupling* c = &cce->coup;
    const bool independent = domain < 0;
    if (independent != (c->ind_sw != 0) || (!independent && c->domain != domain)) {
      continue;
    }
    int index = 0;
    for (int t = 0; t < c->num_coupled; t++) {
      if (c->target_type[t] == e->type && c->target_tag[t] == e->tag) {
        if (c->ch_select[t] != 1) {
          if (independent) {
            apply_independent_coupling(cce, index, &chans[0], s->frame_length);
          } else {
            apply_dependent_coupling(cce, index, &chans[0].cs);
          }
          if (c->ch_select[t] != 0) {
            index++;
          }
        }
        if (c->ch_select[t] != 2 && e->type == AAC_ELEM_CPE) {
          if (independent) {
            apply_independent_coupling(cce, index, &chans[1], s->frame_length);
          } else {
            apply_dependent_coupling(cce, index, &chans[1].cs);
          }
          index++;
        }
      } else {
        index += 1 + (c->ch_select[t] == 3);
      }
    }
  }
}

/* The SBR state of a layout element, created on first use. */
AacSbrState* sbr_state(AacDecoderState* s, int elem) {
  if (!s->sbr[elem]) {
    s->sbr[elem] = new AacSbrState();
    aac_sbr_init(s->sbr[elem], s->sample_rate, s->frame_length);
  }
  return s->sbr[elem];
}

/* fill_element() (Table 4.11).  An SBR extension (extension_type 13 or
 * 14, Table 4.56) belongs to the SCE or CPE just before it (`elem`, or -1
 * after any other element) and is parsed into that element's SBR state;
 * every other payload is skipped.  A damaged SBR payload fails the block
 * with concealment off; otherwise it is left for the SBR stage to conceal
 * and `*damaged_sbr` is set. */
int decode_fil(AacDecoderState* s, AacBitReader* r, int elem, int elem_type, bool* damaged_sbr) {
  int count = (int)aac_bitreader_read(r, 4);
  if (count == 15) {
    count += (int)aac_bitreader_read(r, 8) - 1;
  }
  if (count > 0) {
    const int ext_type = (int)aac_bitreader_peek(r, 4);
    if (ext_type == 13 || ext_type == 14) {
      s->has_sbr = 1;
      if (elem >= 0 && (elem_type == AAC_ELEM_SCE || elem_type == AAC_ELEM_CPE)) {
        aac_bitreader_skip(r, 4);
        s->sbr_mode = 1;
        AacSbrState* sbr = sbr_state(s, elem);
        const int ret = aac_sbr_decode_extension(sbr, r, ext_type == 14, count, elem_type);
        if (ret) {
          if (s->conceal_mode == AAC_CONCEAL_OFF) {
            return ret;
          }
          *damaged_sbr = true;
        } else if (sbr->ps_present) {
          s->has_ps = 1;
        }
        return r->overread ? AAC_ERR_DECODE : 0;
      }
    } else if (ext_type == AAC_EXT_DYNAMIC_RANGE) {
      return aac_drc_decode_fill(&s->drc, r, elem, count);
    }
  }
  aac_bitreader_skip(r, 8 * count);
  return r->overread ? AAC_ERR_DECODE : 0;
}

/* program_config_element() (Table 4.2) in a raw data block, which starts
 * byte aligned. */
int decode_pce(AacBitReader* r, AacLayout* layout) { return aac_pce_read(r, layout, 0); }

bool same_layout(const AacLayout* a, const AacLayout* b) {
  if (a->num_elements != b->num_elements || a->num_channels != b->num_channels) {
    return false;
  }
  for (int i = 0; i < a->num_elements; i++) {
    if (a->elems[i].type != b->elems[i].type || a->elems[i].tag != b->elems[i].tag ||
        a->elems[i].slot[0] != b->elems[i].slot[0] || a->elems[i].slot[1] != b->elems[i].slot[1]) {
      return false;
    }
  }
  return true;
}

void install_layout(AacDecoderState* s, const AacLayout* l, int source) {
  const bool changed = s->layout_source == 0 || !same_layout(&s->layout, l);
  s->layout = *l;
  s->layout_source = source;
  int base = 0;
  for (int i = 0; i < l->num_elements; i++) {
    s->elem_base[i] = base;
    base += l->elems[i].type == AAC_ELEM_CPE ? 2 : 1;
  }
  if (changed) {
    aac_decoder_reset_state(s);
  }
}

/* Position guessed for an element of a stream without configuration: the
 * leading single channel and the first pair are front, later ones back. */
int sniffed_position(const AacLayoutElement* elems, int n, int type) {
  if (type == AAC_ELEM_LFE) {
    return AAC_POS_LFE;
  }
  int front_channels = 0;
  for (int i = 0; i < n; i++) {
    if (elems[i].position == AAC_POS_FRONT) {
      front_channels += elems[i].type == AAC_ELEM_CPE ? 2 : 1;
    }
  }
  if (type == AAC_ELEM_CPE) {
    return front_channels <= 1 ? AAC_POS_FRONT : AAC_POS_BACK;
  }
  return front_channels == 0 ? AAC_POS_FRONT : AAC_POS_BACK;
}

}  // namespace

AacDecoderState* aac_decoder_state_create(int sr, int ch, const AacDSP* dsp) {
  aac_tables_init();
  auto* s = new AacDecoderState();
  s->sample_rate = sr;
  s->channels = ch;
  s->stream_channels = 0;
  s->frame_length = AAC_FRAME_SIZE_LONG;
  s->frame_size = s->frame_length;
  s->aot = 0;
  s->eld_downscale = 1;
  s->decoded_since_reset = 0;
  s->dsp = dsp;
  s->rate_index = -1;
  for (int i = 0; i < AAC_NUM_SAMPLE_RATES; i++) {
    if (aac_sample_rates[i] == sr) {
      s->rate_index = i;
      break;
    }
  }
  if (s->rate_index < 0) {
    delete s;
    return nullptr;
  }
  aac_mdct_init(&s->mdct_long, 2 * s->frame_length);
  aac_mdct_init(&s->mdct_short, 2 * aac_short_length(s->frame_length));
  s->random_state = kRandomSeed;
  s->sbr_mode = 0;
  s->ld_sbr_mode = 0;
  s->ld_sbr_dual_rate = 0;
  s->ld_sbr_crc = 0;
  for (int i = 0; i < AAC_MAX_ELEMENTS; i++) {
    s->sbr[i] = nullptr;
    s->ld_sbr[i] = nullptr;
  }
  s->conceal_mode = AAC_CONCEAL_INTERPOLATE;
  aac_drc_decoder_init(&s->drc);
  s->last_concealed = 0;
  s->concealed_frames = 0;
  s->conceal_random = kRandomSeed;
  for (int c = 0; c < AAC_MAX_CHANNELS; c++) {
    aac_conceal_reset(&s->ch[c].conceal);
  }
  return s;
}

void aac_decoder_state_destroy(AacDecoderState* s) {
  if (!s) {
    return;
  }
  for (int i = 0; i < AAC_MAX_ELEMENTS; i++) {
    delete s->sbr[i];
    delete s->ld_sbr[i];
  }
  delete s;
}

void aac_decoder_reset_state(AacDecoderState* s) {
  for (int c = 0; c < AAC_MAX_CHANNELS; c++) {
    memset(s->ch[c].overlap, 0, sizeof(s->ch[c].overlap));
    memset(s->ch[c].eld_overlap, 0, sizeof(s->ch[c].eld_overlap));
    memset(s->ch[c].output, 0, sizeof(s->ch[c].output));
    s->ch[c].prev_window_shape = 0;
    aac_conceal_reset(&s->ch[c].conceal);
  }
  s->last_concealed = 0;
  s->concealed_frames = 0;
  s->conceal_random = kRandomSeed;
  for (int k = 0; k < AAC_MAX_CCE; k++) {
    memset(s->cce[k].ch.overlap, 0, sizeof(s->cce[k].ch.overlap));
    memset(s->cce[k].ch.eld_overlap, 0, sizeof(s->cce[k].ch.eld_overlap));
    s->cce[k].ch.prev_window_shape = 0;
    s->cce[k].present = 0;
  }
  s->random_state = kRandomSeed;
  s->sbr_mode = 0;
  s->ps_output = 0;
  s->blocks_decoded = 0;
  /* The DRC readback and the held gains describe frames before the
   * reset. */
  aac_drc_frame_begin(&s->drc);
  if (s->aot == AAC_AOT_ER_ELD) {
    aac_mdct_init(&s->mdct_eld, 2 * core_output_length(s));
  }
  s->frame_size = output_length(s);
  if (s->limiter_ready) {
    aac_limiter_reset(&s->limiter);
  }
  for (int i = 0; i < AAC_MAX_ELEMENTS; i++) {
    if (s->sbr[i]) {
      aac_sbr_init(s->sbr[i], s->sample_rate, s->frame_length);
    }
    if (s->ld_sbr[i]) {
      aac_sbr_ld_reset(s->ld_sbr[i]);
    }
  }
}

namespace {

void drop_ld_sbr(AacDecoderState* s) {
  for (int i = 0; i < AAC_MAX_ELEMENTS; i++) {
    delete s->ld_sbr[i];
    s->ld_sbr[i] = nullptr;
  }
  s->ld_sbr_mode = 0;
  s->ld_sbr_dual_rate = 0;
  s->ld_sbr_crc = 0;
}

/* Whether the LD-SBR configuration in force is the one asked for, so
 * that a transport repeating its configuration every frame leaves the
 * filterbanks and the inter-frame state alone. */
bool same_ld_sbr(const AacDecoderState* s, int dual_rate, int crc, const AacSbrLdHeader* headers,
                 int num_headers) {
  if (!s->ld_sbr_mode || s->ld_sbr_dual_rate != (dual_rate ? 1 : 0) ||
      s->ld_sbr_crc != (crc ? 1 : 0)) {
    return false;
  }
  const AacLayout* l = &s->layout;
  int next_header = 0;
  for (int i = 0; i < l->num_elements; i++) {
    const AacSbrLdState* st = s->ld_sbr[i];
    const int type = l->elems[i].type;
    /* A state built for a single channel element does not serve a pair
     * at the same position (a mono stream followed by a stereo one with
     * the same header), so the element type has to match as well. */
    const int state_type = type == AAC_ELEM_CPE ? AAC_ELEM_CPE : AAC_ELEM_SCE;
    if (!st || st->core_sample_rate != s->sample_rate || st->frame_length != s->frame_length ||
        st->elem_type != state_type) {
      return false;
    }
    if (type != AAC_ELEM_SCE && type != AAC_ELEM_CPE) {
      continue;
    }
    if (next_header >= num_headers ||
        memcmp(&st->header, &headers[next_header], sizeof(st->header)) != 0) {
      return false;
    }
    next_header++;
  }
  return true;
}

}  // namespace

int aac_decoder_set_ld_sbr(AacDecoderState* s, int present, int dual_rate, int crc,
                           const AacSbrLdHeader* headers, int num_headers) {
  if (!present) {
    drop_ld_sbr(s);
    s->frame_size = output_length(s);
    return 0;
  }
  if (s->aot != AAC_AOT_ER_ELD || s->eld_downscale != 1 || s->layout_source != 1) {
    drop_ld_sbr(s);
    return AAC_ERR_UNSUPPORTED;
  }
  if (same_ld_sbr(s, dual_rate, crc, headers, num_headers)) {
    return 0;
  }
  drop_ld_sbr(s);
  const AacLayout* l = &s->layout;
  int next_header = 0;
  for (int i = 0; i < l->num_elements; i++) {
    const int type = l->elems[i].type;
    const bool has_header = type == AAC_ELEM_SCE || type == AAC_ELEM_CPE;
    if (has_header && next_header >= num_headers) {
      drop_ld_sbr(s);
      return AAC_ERR_UNSUPPORTED;
    }
    /* An LFE takes the header of the element before it: its state only
     * carries the core through the filterbanks. */
    const AacSbrLdHeader* h = &headers[has_header ? next_header : std::max(next_header - 1, 0)];
    s->ld_sbr[i] = new AacSbrLdState();
    const int ret = aac_sbr_ld_init(s->ld_sbr[i], s->sample_rate, s->frame_length, dual_rate, h);
    if (ret) {
      drop_ld_sbr(s);
      return ret;
    }
    /* The element type is known from the configuration, so a channel
     * pair produces both outputs before its first payload is read. */
    s->ld_sbr[i]->elem_type = type == AAC_ELEM_CPE ? AAC_ELEM_CPE : AAC_ELEM_SCE;
    if (has_header) {
      next_header++;
    }
  }
  s->ld_sbr_mode = 1;
  s->ld_sbr_dual_rate = dual_rate ? 1 : 0;
  s->ld_sbr_crc = crc ? 1 : 0;
  s->frame_size = output_length(s);
  return 0;
}

int aac_decoder_set_frame_length_internal(AacDecoderState* s, int frame_length) {
  if (frame_length != AAC_FRAME_SIZE_LONG && frame_length != AAC_FRAME_SIZE_LONG_960 &&
      !aac_is_low_delay_frame(frame_length)) {
    return AAC_ERR_INVALID_ARG;
  }
  if (frame_length == s->frame_length) {
    return 0;
  }
  s->frame_length = frame_length;
  aac_mdct_init(&s->mdct_long, 2 * frame_length);
  aac_mdct_init(&s->mdct_short, 2 * aac_short_length(frame_length));
  aac_decoder_reset_state(s);
  return 0;
}

int aac_decoder_set_object_type(AacDecoderState* s, int aot) {
  if (aot == s->aot) {
    return 0;
  }
  const bool low_delay = aot == AAC_AOT_ER_LD || aot == AAC_AOT_ER_ELD;
  s->aot = aot;
  if (aot != AAC_AOT_ER_ELD) {
    drop_ld_sbr(s);
  }
  if (!low_delay && aac_is_low_delay_frame(s->frame_length)) {
    return aac_decoder_set_frame_length_internal(s, AAC_FRAME_SIZE_LONG);
  }
  aac_decoder_reset_state(s);
  return 0;
}

void aac_decoder_set_eld_downscale_internal(AacDecoderState* s, int factor) {
  if (factor == s->eld_downscale) {
    return;
  }
  s->eld_downscale = factor;
  aac_decoder_reset_state(s);
}

int aac_decoder_output_rate(const AacDecoderState* s) {
  const int core = s->aot == AAC_AOT_ER_ELD ? s->sample_rate / s->eld_downscale : s->sample_rate;
  return doubled_output(s) ? 2 * core : core;
}

int aac_decoder_set_rate_index(AacDecoderState* s, int rate_index) {
  if (rate_index < 0 || rate_index >= AAC_NUM_SAMPLE_RATES) {
    return AAC_ERR_UNSUPPORTED;
  }
  const bool changed = rate_index != s->rate_index;
  s->rate_index = rate_index;
  s->sample_rate = aac_sample_rates[rate_index];
  if (changed) {
    aac_decoder_reset_state(s);
  }
  return 0;
}

int aac_decoder_set_channel_config(AacDecoderState* s, int channel_config) {
  if (channel_config == 0) {
    return 0;
  }
  if (s->layout_source == 1 && s->layout.channel_config == channel_config) {
    return 0;
  }
  AacLayout l = {};
  const int ret = aac_layout_from_config(&l, channel_config);
  if (ret) {
    return ret;
  }
  install_layout(s, &l, 1);
  return 0;
}

int aac_decoder_set_layout(AacDecoderState* s, const AacLayout* l) {
  if (l->num_elements == 0 || l->num_channels > AAC_MAX_CHANNELS) {
    return AAC_ERR_UNSUPPORTED;
  }
  install_layout(s, l, 2);
  return 0;
}

int aac_decoder_output_channels(const AacDecoderState* s) {
  if (s->channels > 0) {
    return s->channels;
  }
  if (!s->layout_source) {
    return 0;
  }
  return s->ps_output ? 2 : s->layout.num_channels;
}

int aac_decoder_output(const AacDecoderState* s, float* out) {
  const float* in[AAC_MAX_CHANNELS] = {};
  const int out_channels = aac_decoder_output_channels(s);
  if (s->ps_output) {
    /* The mono element decoded to a stereo pair: mix as a stereo stream. */
    AacLayout stereo = {};
    aac_layout_from_config(&stereo, 2);
    in[0] = s->ch[0].output;
    in[1] = s->ch[1].output;
    aac_layout_mix(&stereo, in, s->frame_size, out_channels, out);
    return out_channels;
  }
  const AacLayout* l = &s->layout;
  for (int i = 0; i < l->num_elements; i++) {
    const int nch = l->elems[i].type == AAC_ELEM_CPE ? 2 : 1;
    for (int c = 0; c < nch; c++) {
      in[l->elems[i].slot[c]] = s->ch[s->elem_base[i] + c].output;
    }
  }
  aac_layout_mix(l, in, s->frame_size, out_channels, out);
  return out_channels;
}

namespace {

/* The parsing context of one raw data block. */
struct BlockParse {
  AacLayoutElement sniffed[AAC_MAX_ELEMENTS]; /* elements of a stream without configuration */
  int num_sniffed;
  bool sniffing;
  int next_channel;
  bool pce_seen;
  int last_elem; /* layout index of the element just decoded, for its FIL */
  int last_type;
  bool damaged_sbr; /* an SBR extension was damaged and awaits concealment */
};

/* Parses one syntactic element of the block.  On an error the element
 * stays absent (its channels or coupling are not marked present) and the
 * reader is left where parsing stopped. */
int decode_element(AacDecoderState* s, AacBitReader* r, BlockParse* p, int elem) {
  int ret = 0;
  const int fil_elem = elem == AAC_ELEM_FIL ? p->last_elem : -1;
  const int fil_type = p->last_type;
  p->last_elem = fil_elem; /* consecutive fill elements follow the same channel element */
  p->last_type = elem == AAC_ELEM_FIL ? fil_type : elem;
  switch (elem) {
    case AAC_ELEM_SCE:
    case AAC_ELEM_LFE:
    case AAC_ELEM_CPE: {
      const int tag = (int)aac_bitreader_read(r, 4);
      const int nch = elem == AAC_ELEM_CPE ? 2 : 1;
      AacDecoderChannel* chans = nullptr;
      int idx = -1;
      if (p->sniffing) {
        if (p->num_sniffed >= AAC_MAX_ELEMENTS || p->next_channel + nch > AAC_MAX_CHANNELS) {
          return AAC_ERR_UNSUPPORTED;
        }
        idx = p->num_sniffed;
        p->sniffed[idx].type = elem;
        p->sniffed[idx].tag = tag;
        p->sniffed[idx].position = sniffed_position(p->sniffed, idx, elem);
        s->elem_base[idx] = p->next_channel;
        chans = &s->ch[p->next_channel];
      } else {
        idx = aac_layout_find(&s->layout, elem, tag);
        if (idx < 0 || s->elem_present[idx]) {
          return AAC_ERR_DECODE;
        }
        chans = &s->ch[s->elem_base[idx]];
      }
      ret = elem == AAC_ELEM_CPE ? decode_cpe(s, r, &chans[0], &chans[1])
                                 : decode_sce(s, r, &chans[0]);
      if (ret) {
        return ret;
      }
      if (p->sniffing) {
        p->num_sniffed++;
        p->next_channel += nch;
      } else {
        s->elem_present[idx] = 1;
      }
      p->last_elem = idx;
      break;
    }
    case AAC_ELEM_CCE: {
      const int tag = (int)aac_bitreader_read(r, 4);
      AacDecoderCce* cce = nullptr;
      for (int k = 0; k < AAC_MAX_CCE && !cce; k++) {
        if (!s->cce[k].present) {
          cce = &s->cce[k];
        }
      }
      if (!cce) {
        return AAC_ERR_UNSUPPORTED;
      }
      cce->tag = tag;
      ret = decode_cce(s, r, cce);
      if (ret) {
        return ret;
      }
      cce->present = 1;
      break;
    }
    case AAC_ELEM_DSE:
      ret = aac_drc_decode_dse(&s->drc, r);
      break;
    case AAC_ELEM_PCE: {
      AacLayout l = {};
      ret = decode_pce(r, &l);
      if (ret == 0 && !p->pce_seen) {
        if (p->num_sniffed > 0 || !p->sniffing) {
          /* A PCE after channel elements, or over an ADTS configuration:
           * the elements already decoded keep their layout. */
          if (!p->sniffing && !same_layout(&s->layout, &l)) {
            ret = AAC_ERR_DECODE;
          }
        } else {
          install_layout(s, &l, 2);
          p->sniffing = false;
        }
        p->pce_seen = true;
      }
      break;
    }
    case AAC_ELEM_FIL:
      ret = decode_fil(s, r, fil_elem, fil_type, &p->damaged_sbr);
      break;
    default:
      return AAC_ERR_UNSUPPORTED;
  }
  return ret;
}

/* Installs the layout a stream without configuration defines through the
 * elements of this block. */
int install_sniffed_layout(AacDecoderState* s, const BlockParse* p) {
  if (p->num_sniffed == 0) {
    return AAC_ERR_DECODE;
  }
  AacLayout l = {};
  const int ret = aac_layout_from_elements(&l, p->sniffed, p->num_sniffed);
  if (ret) {
    return ret;
  }
  l.channel_config = aac_layout_config_for_elements(p->sniffed, p->num_sniffed);
  const bool changed = s->layout_source == 0 || !same_layout(&s->layout, &l);
  s->layout = l;
  s->layout_source = 3;
  if (changed) {
    /* The overlap and concealment history of a different layout is
     * meaningless; the channels decoded in this block keep their spectra. */
    for (int c = 0; c < AAC_MAX_CHANNELS; c++) {
      memset(s->ch[c].overlap, 0, sizeof(s->ch[c].overlap));
      s->ch[c].prev_window_shape = 0;
      aac_conceal_reset(&s->ch[c].conceal);
    }
  }
  for (int i = 0; i < p->num_sniffed; i++) {
    s->elem_present[i] = 1;
  }
  return 0;
}

/* A damaged block of a stream without configuration: the elements parsed
 * before the damage count when they are the start of the layout the
 * earlier blocks defined; the rest of that layout is concealed. */
int keep_sniffed_prefix(AacDecoderState* s, const BlockParse* p) {
  if (s->layout_source != 3) {
    return AAC_ERR_DECODE;
  }
  const AacLayout* l = &s->layout;
  int keep = 0;
  while (keep < p->num_sniffed && keep < l->num_elements &&
         p->sniffed[keep].type == l->elems[keep].type &&
         p->sniffed[keep].tag == l->elems[keep].tag) {
    keep++;
  }
  int base = 0;
  for (int i = 0; i < l->num_elements; i++) {
    s->elem_base[i] = base;
    base += l->elems[i].type == AAC_ELEM_CPE ? 2 : 1;
    s->elem_present[i] = i < keep;
  }
  return 0;
}

int effective_conceal_mode(const AacDecoderState* s) {
  return s->conceal_mode == AAC_CONCEAL_OFF ? AAC_CONCEAL_INTERPOLATE : s->conceal_mode;
}

/* Replaces a lost channel's frame by its concealed continuation. */
void conceal_spectrum(AacDecoderState* s, AacDecoderChannel* ch) {
  aac_conceal_spectrum(&ch->conceal, effective_conceal_mode(s), s->frame_length,
                       &s->conceal_random, &ch->cs.ics,
                       ch->cs.coef);
  ch->cs.tns_present = 0;
}

/* Dynamic range control of a concealed element, whose own DRC data was
 * lost with it: in the QMF domain the previous frame's gains stay in
 * force (the SBR stage applies them without a ramp), on the spectrum the
 * gains of the DRC info in hand are applied, which for a lost block is
 * the previous frame's.  Either way the level does not pump across the
 * gap. */
void hold_drc(AacDecoderState* s, int elem, AacDecoderChannel* chans, int nch) {
  if (!s->drc.settings.enabled) {
    return;
  }
  if (s->sbr_mode && s->sbr[elem]) {
    aac_drc_qmf_hold(&s->sbr[elem]->drc);
    return;
  }
  for (int c = 0; c < nch; c++) {
    aac_drc_apply_spectrum(&s->drc, elem, s->elem_base[elem] + c, chans[c].cs.coef,
                           chans[c].cs.ics.num_windows, s->frame_length);
  }
}

/* Concealed output for every channel of an element. */
void conceal_element(AacDecoderState* s, int elem, AacDecoderChannel* chans, int nch) {
  for (int c = 0; c < nch; c++) {
    conceal_spectrum(s, &chans[c]);
  }
  hold_drc(s, elem, chans, nch);
  for (int c = 0; c < nch; c++) {
    imdct_and_window(s, &chans[c]);
  }
}

/* The first good frame after concealed ones is crossfaded from the
 * concealed continuation; that continuation's spectrum is built here,
 * before the good spectrum replaces the stored one. */
void prepare_recovery(AacDecoderState* s, AacDecoderChannel* ch, int slot) {
  if (ch->conceal.count == 0) {
    return;
  }
  AacDecoderChannel* alt = &s->recover[slot];
  AacConcealChannel fade = ch->conceal;
  aac_conceal_spectrum(&fade, effective_conceal_mode(s), s->frame_length, &s->conceal_random,
                       &alt->cs.ics,
                       alt->cs.coef);
  alt->cs.tns_present = 0;
}

/* The filterbank of a good frame, blended over the frame from the
 * concealed continuation when the previous frame was concealed. */
void filterbank(AacDecoderState* s, AacDecoderChannel* ch, int slot) {
  if (ch->conceal.count == 0) {
    imdct_and_window(s, ch);
    return;
  }
  AacDecoderChannel* alt = &s->recover[slot];
  memcpy(alt->overlap, ch->overlap, sizeof(alt->overlap));
  memcpy(alt->eld_overlap, ch->eld_overlap, sizeof(alt->eld_overlap));
  alt->prev_window_shape = ch->prev_window_shape;
  imdct_and_window(s, alt);
  imdct_and_window(s, ch);
  const int fl = core_output_length(s);
  for (int i = 0; i < fl; i++) {
    const float w = ((float)i + 0.5f) / (float)fl;
    ch->output[i] = alt->output[i] + (ch->output[i] - alt->output[i]) * w;
  }
  aac_conceal_recovered(&ch->conceal);
}

/* Dynamic range control of an element's channels: on the spectrum before
 * the inverse transform, or as QMF-band gains the SBR stage applies once
 * the high band exists.  An element that has no SBR state yet (an LFE
 * before the first SBR frame) is scaled on its spectrum, so that DRC
 * never creates a state. */
void apply_drc(AacDecoderState* s, int elem, AacDecoderChannel* chans, int nch) {
  if (!s->drc.settings.enabled) {
    return;
  }
  if (s->sbr_mode && s->sbr[elem]) {
    aac_drc_set_qmf_gains(&s->drc, elem, s->elem_base[elem], nch, &s->sbr[elem]->drc,
                          s->frame_length);
    return;
  }
  for (int c = 0; c < nch; c++) {
    aac_drc_apply_spectrum(&s->drc, elem, s->elem_base[elem] + c, chans[c].cs.coef,
                           chans[c].cs.ics.num_windows, s->frame_length);
  }
}

/* SBR last: the stream stays at twice the core rate once an element has
 * carried SBR data, and every element goes through its filterbanks so
 * that all channels keep the same delay.  With `conceal` an element
 * whose SBR is active but got no data in this frame continues on the
 * previous frame's envelope instead of dropping to upsampling. */
/* AAC-ELD with LD-SBR: every element's core output goes through its
 * CLDFB chain; an element whose payload was read gets its high band,
 * the others (an LFE, or a damaged or lost frame) pass the core through
 * with the high band muted so that the delay stays. */
void ld_sbr_stage(AacDecoderState* s) {
  const AacLayout* l = &s->layout;
  const int core = core_output_length(s);
  float left[AAC_FRAME_SIZE_LD];
  float right[AAC_FRAME_SIZE_LD];
  for (int i = 0; i < l->num_elements; i++) {
    AacDecoderChannel* chans = &s->ch[s->elem_base[i]];
    const bool pair = l->elems[i].type == AAC_ELEM_CPE;
    memcpy(left, chans[0].output, (size_t)core * sizeof(float));
    if (pair) {
      memcpy(right, chans[1].output, (size_t)core * sizeof(float));
    }
    aac_sbr_ld_process(s->ld_sbr[i], left, pair ? right : nullptr, chans[0].output,
                       pair ? chans[1].output : nullptr);
  }
}

void sbr_stage(AacDecoderState* s, bool conceal) {
  const AacLayout* l = &s->layout;
  s->frame_size = output_length(s);
  if (s->ld_sbr_mode) {
    ld_sbr_stage(s);
    return;
  }
  if (!s->sbr_mode) {
    return;
  }
  /* A mono stream whose element carries parametric stereo becomes a
   * stereo pair, with the right channel in the spare slot ch[1]. */
  const bool mono = l->num_elements == 1 && l->elems[0].type == AAC_ELEM_SCE;
  if (mono && sbr_state(s, 0)->ps_present) {
    s->ps_output = 1;
  }
  for (int i = 0; i < l->num_elements; i++) {
    AacDecoderChannel* chans = &s->ch[s->elem_base[i]];
    float* right = nullptr;
    if (l->elems[i].type == AAC_ELEM_CPE || (mono && s->ps_output)) {
      right = chans[1].output;
    }
    AacSbrState* sbr = sbr_state(s, i);
    if (conceal && sbr->start && !sbr->ready_for_dequant && sbr->elem_type == l->elems[i].type) {
      aac_sbr_conceal(sbr);
    }
    aac_sbr_apply(sbr, l->elems[i].type, chans[0].output, right);
  }
}

/* er_raw_data_block() (ISO/IEC 14496-3 Table 4.4 and 1.6.2.2): the
 * channel elements of the channel configuration in a fixed order, without
 * element ids and without END; AAC-ELD elements carry no
 * element_instance_tag either.  The layout of the configuration lists its
 * elements in that order (1: SCE; 2: CPE; 3: SCE CPE; 4: SCE CPE SCE; 5:
 * SCE CPE CPE; 6: SCE CPE CPE LFE; 7: SCE CPE CPE CPE LFE). */
/* ld_sbr_data() of an AAC-ELD frame (ISO/IEC 14496-3 4.6.20): after the
 * last channel element, one sbr_data() per SCE or CPE in element order,
 * each behind its 10-bit CRC when the configuration says so.  A damaged
 * payload mutes the high band of its element and of those after it,
 * which cannot be found any more. */
int decode_ld_sbr_data(AacDecoderState* s, AacBitReader* r) {
  const AacLayout* l = &s->layout;
  for (int i = 0; i < l->num_elements; i++) {
    const int type = l->elems[i].type;
    if (type != AAC_ELEM_SCE && type != AAC_ELEM_CPE) {
      continue;
    }
    const int ret = aac_sbr_ld_read(s->ld_sbr[i], r, type, s->ld_sbr_crc);
    if (ret) {
      return ret;
    }
    s->has_sbr = 1;
  }
  return 0;
}

int decode_er_elements(AacDecoderState* s, AacBitReader* r) {
  const AacLayout* l = &s->layout;
  for (int i = 0; i < l->num_elements; i++) {
    if (s->aot != AAC_AOT_ER_ELD) {
      aac_bitreader_read(r, 4); /* element_instance_tag */
    }
    AacDecoderChannel* chans = &s->ch[s->elem_base[i]];
    const int ret = l->elems[i].type == AAC_ELEM_CPE ? decode_cpe(s, r, &chans[0], &chans[1])
                                                     : decode_sce(s, r, &chans[0]);
    if (ret) {
      return ret;
    }
    if (r->overread) {
      return AAC_ERR_DECODE;
    }
    s->elem_present[i] = 1;
  }
  if (s->ld_sbr_mode) {
    return decode_ld_sbr_data(s, r);
  }
  return 0;
}

}  // namespace

int aac_decode_raw_data_block(AacDecoderState* s, AacBitReader* r) {
  BlockParse p = {};
  p.sniffing = s->layout_source == 0 || s->layout_source == 3;
  p.last_elem = -1;
  p.last_type = -1;
  if (p.sniffing) {
    /* Decode into channel order of arrival; the layout follows at END. */
    for (int i = 0; i < AAC_MAX_ELEMENTS; i++) {
      s->elem_base[i] = 0;
    }
  }
  memset(s->elem_present, 0, sizeof(s->elem_present));
  for (int k = 0; k < AAC_MAX_CCE; k++) {
    s->cce[k].present = 0;
  }
  s->has_sbr = 0;
  s->has_ps = 0;
  s->last_concealed = 0;
  s->rvlc_recovered = 0;
  s->num_crc_regions = 0;
  aac_drc_frame_begin(&s->drc);
  const bool conceal = s->conceal_mode != AAC_CONCEAL_OFF;
  bool damaged = false;
  if (s->aot == 0) {
    s->aot = AAC_AOT_LC; /* a stream without configuration is AAC-LC */
  }
  const bool er = aac_aot_is_er(s->aot);
  if (er) {
    /* The channel configuration names the elements: without any
     * configuration the block waits for one; with a layout from a PCE or
     * from the elements of an earlier block the stream cannot be decoded
     * (the streaming layer would otherwise wait for ever). */
    if (s->layout_source == 0) {
      return AAC_ERR_STATE;
    }
    if (s->layout_source != 1) {
      return AAC_ERR_UNSUPPORTED;
    }
    p.sniffing = false;
    const int ret = decode_er_elements(s, r);
    if (ret) {
      if (!conceal) {
        aac_decoder_note_lost_block(s);
        return ret;
      }
      damaged = true;
    }
  }
  while (!er) {
    const int elem = (int)aac_bitreader_read(r, 3);
    int ret = r->overread ? AAC_ERR_DECODE : 0;
    if (ret == 0 && elem == AAC_ELEM_END) {
      aac_bitreader_byte_align(r);
      break;
    }
    /* The element's extent, for the ADTS CRC check of the transport layer. */
    AacCrcRegion* region = nullptr;
    if (ret == 0 && s->num_crc_regions < AAC_CRC_MAX_REGIONS) {
      region = &s->crc_region[s->num_crc_regions++];
      region->type = elem;
      region->start = r->pos;
      region->second = -1;
      region->end = r->pos;
    }
    if (ret == 0) {
      ret = decode_element(s, r, &p, elem);
    }
    if (region) {
      region->end = r->pos;
    }
    if (ret) {
      if (!conceal) {
        aac_decoder_note_lost_block(s);
        return ret;
      }
      /* The elements decoded so far keep their data, the rest of the
       * layout is concealed, and nothing more of the block is read. */
      damaged = true;
      break;
    }
  }
  if (p.sniffing) {
    if (!damaged && p.num_sniffed == 0 && conceal && s->layout_source == 3) {
      /* No channel element at all: no frame of the layout seen so far. */
      damaged = true;
    }
    const int ret = damaged ? keep_sniffed_prefix(s, &p) : install_sniffed_layout(s, &p);
    if (ret) {
      return ret;
    }
  } else if (s->layout_source == 0) {
    return AAC_ERR_DECODE;
  }
  const AacLayout* l = &s->layout;
  if (conceal && !damaged) {
    /* A block without any of the layout's channel elements is no frame
     * of the stream. */
    bool any = false;
    for (int i = 0; i < l->num_elements; i++) {
      any = any || s->elem_present[i];
    }
    damaged = !any;
  }
  if (damaged || p.damaged_sbr || s->rvlc_recovered) {
    s->last_concealed = 1;
    s->concealed_frames++;
  }
  aac_drc_frame_end(&s->drc);

  /* Coupling channels first: independently switched ones need their time
   * signal before the targets are mixed. */
  for (int k = 0; k < AAC_MAX_CCE; k++) {
    AacDecoderCce* cce = &s->cce[k];
    if (cce->present && cce->coup.ind_sw) {
      apply_tns(&cce->ch.cs);
      imdct_and_window(s, &cce->ch);
    }
  }
  for (int i = 0; i < l->num_elements; i++) {
    const int nch = l->elems[i].type == AAC_ELEM_CPE ? 2 : 1;
    AacDecoderChannel* chans = &s->ch[s->elem_base[i]];
    if (!s->elem_present[i]) {
      if (damaged) {
        conceal_element(s, i, chans, nch);
      } else {
        for (int c = 0; c < nch; c++) {
          memset(chans[c].output, 0, sizeof(chans[c].output));
        }
      }
      continue;
    }
    apply_couplings(s, i, 0);
    for (int c = 0; c < nch; c++) {
      prepare_recovery(s, &chans[c], c);
      aac_conceal_store(&chans[c].conceal, &chans[c].cs.ics, chans[c].cs.coef);
      apply_tns(&chans[c].cs);
    }
    apply_couplings(s, i, 1);
    apply_drc(s, i, chans, nch);
    for (int c = 0; c < nch; c++) {
      filterbank(s, &chans[c], c);
    }
    apply_couplings(s, i, -1);
  }
  sbr_stage(s, conceal);
  s->stream_channels = l->num_channels;
  s->blocks_decoded++;
  s->decoded_since_reset = 1;
  return l->num_channels;
}

void aac_decoder_note_lost_block(AacDecoderState* s) {
  for (int i = 0; i < AAC_MAX_ELEMENTS; i++) {
    if (s->sbr[i]) {
      aac_sbr_mark_lost(s->sbr[i]);
    }
  }
}

int aac_decoder_conceal_block(AacDecoderState* s) {
  if (s->layout_source == 0 || s->rate_index < 0) {
    return AAC_ERR_STATE;
  }
  memset(s->elem_present, 0, sizeof(s->elem_present));
  for (int k = 0; k < AAC_MAX_CCE; k++) {
    s->cce[k].present = 0;
  }
  /* The lost block carried no DRC data of its own: the previous frame's
   * gains stay in force, so the level does not pump across the gap. */
  const AacLayout* l = &s->layout;
  for (int i = 0; i < l->num_elements; i++) {
    const int nch = l->elems[i].type == AAC_ELEM_CPE ? 2 : 1;
    conceal_element(s, i, &s->ch[s->elem_base[i]], nch);
  }
  sbr_stage(s, true);
  s->last_concealed = 1;
  s->concealed_frames++;
  s->blocks_decoded++;
  s->decoded_since_reset = 1;
  s->stream_channels = l->num_channels;
  return l->num_channels;
}
