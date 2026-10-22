#include "decoder.h"

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
  if (aac_bitreader_read(r, 1)) { /* ics_reserved_bit */
    return AAC_ERR_DECODE;
  }
  const int seq = (int)aac_bitreader_read(r, 2);
  ics->window_shape = (int)aac_bitreader_read(r, 1);
  if (seq == AAC_WIN_EIGHT_SHORT) {
    ics->max_sfb = (int)aac_bitreader_read(r, 4);
    aac_ics_layout(ics, s->rate_index, seq, (int)aac_bitreader_read(r, 7));
  } else {
    ics->max_sfb = (int)aac_bitreader_read(r, 6);
    aac_ics_layout(ics, s->rate_index, seq, 0);
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
    if (pos >= 1024) {
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
      if (cb == 0 || cb >= AAC_CB_NOISE) {
        continue;
      }
      const int dim = aac_codebook_info[cb].dim;
      for (int w = 0; w < ics->group_len[g]; w++) {
        int* q = cs->quant + (win + w) * 128;
        for (int k = off[b]; k < off[b + 1]; k += dim) {
          if (aac_bitreader_read_huffman(r, cb, q + k) != 0) {
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
 * ics_info is already in cs->ics. */
int decode_ics(AacDecoderState* s, AacBitReader* r, AacChannelStream* cs, int common_window) {
  const int global_gain = (int)aac_bitreader_read(r, 8);
  int ret = 0;
  if (!common_window && (ret = decode_ics_info(s, r, &cs->ics)) != 0) {
    return ret;
  }
  if ((ret = decode_section_data(r, cs)) != 0) {
    return ret;
  }
  if ((ret = decode_scalefactors(r, cs, global_gain)) != 0) {
    return ret;
  }
  PulseData pulse = {};
  const int pulse_present = (int)aac_bitreader_read(r, 1);
  if (pulse_present) {
    if (cs->ics.num_windows == 8) {
      return AAC_ERR_DECODE; /* pulse data is only allowed in long windows */
    }
    if ((ret = decode_pulse_data(r, &cs->ics, &pulse)) != 0) {
      return ret;
    }
  }
  cs->tns_present = (int)aac_bitreader_read(r, 1);
  if (cs->tns_present && (ret = decode_tns_data(r, &cs->ics, &cs->tns)) != 0) {
    return ret;
  }
  if (aac_bitreader_read(r, 1)) {
    return AAC_ERR_UNSUPPORTED; /* gain_control_data: AAC SSR only */
  }
  if ((ret = decode_spectral_data(r, cs)) != 0) {
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

void imdct_and_window(AacDecoderState* s, AacDecoderChannel* ch) {
  const AacIcsInfo* ics = &ch->cs.ics;
  const int seq = ics->window_sequence;
  const float* lw_prev = aac_window_long[ch->prev_window_shape];
  const float* lw = aac_window_long[ics->window_shape];
  const float* sw_prev = aac_window_short[ch->prev_window_shape];
  const float* sw = aac_window_short[ics->window_shape];
  float buf[2048];

  if (seq != AAC_WIN_EIGHT_SHORT) {
    aac_imdct(&s->mdct_long, ch->cs.coef, buf);
    if (seq == AAC_WIN_LONG_STOP) {
      memset(buf, 0, 448 * sizeof(float));
      for (int i = 0; i < 128; i++) {
        buf[448 + i] *= sw_prev[i];
      }
    } else {
      s->dsp->vector_fmul(buf, buf, lw_prev, 1024);
    }
    if (seq == AAC_WIN_LONG_START) {
      for (int i = 0; i < 128; i++) {
        buf[1472 + i] *= sw[127 - i];
      }
      memset(buf + 1600, 0, 448 * sizeof(float));
    } else {
      s->dsp->vector_fmul_reverse(buf + 1024, buf + 1024, lw, 1024);
    }
  } else {
    memset(buf, 0, sizeof(buf));
    float sb[256];
    for (int w = 0; w < 8; w++) {
      aac_imdct(&s->mdct_short, ch->cs.coef + w * 128, sb);
      const float* left = w == 0 ? sw_prev : sw;
      float* dst = buf + 448 + w * 128;
      for (int i = 0; i < 128; i++) {
        dst[i] += sb[i] * left[i];
        dst[128 + i] += sb[128 + i] * sw[127 - i];
      }
    }
  }
  for (int i = 0; i < 1024; i++) {
    ch->output[i] = (ch->overlap[i] + buf[i]) * kOutputScale;
  }
  memcpy(ch->overlap, buf + 1024, 1024 * sizeof(float));
  ch->prev_window_shape = ics->window_shape;
}

/* ── stereo tools (4.6.8) ──────────────────────────────────────── */

struct MsMask {
  int present;
  int used[AAC_MAX_BANDS];
};

void apply_ms(AacChannelStream* l, AacChannelStream* r, const MsMask* ms) {
  const AacIcsInfo* ics = &l->ics;
  const int* off = ics->swb_offset;
  int win = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < ics->max_sfb; b++) {
      const int idx = g * ics->max_sfb + b;
      if (!ms->used[idx] || l->band_type[idx] >= AAC_CB_NOISE ||
          r->band_type[idx] >= AAC_CB_NOISE) {
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

int decode_sce(AacDecoderState* s, AacBitReader* r, int c) {
  AacChannelStream* cs = &s->ch[c].cs;
  int ret = decode_ics(s, r, cs, 0);
  if (ret) {
    return ret;
  }
  dequantize(s, cs, nullptr, nullptr);
  return 0;
}

int decode_cpe(AacDecoderState* s, AacBitReader* r) {
  AacChannelStream* l = &s->ch[0].cs;
  AacChannelStream* rc = &s->ch[1].cs;
  MsMask ms = {};
  const int common_window = (int)aac_bitreader_read(r, 1);
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

int skip_dse(AacBitReader* r) {
  aac_bitreader_skip(r, 4); /* element_instance_tag */
  const int align = (int)aac_bitreader_read(r, 1);
  int count = (int)aac_bitreader_read(r, 8);
  if (count == 255) {
    count += (int)aac_bitreader_read(r, 8);
  }
  if (align) {
    aac_bitreader_byte_align(r);
  }
  aac_bitreader_skip(r, 8 * count);
  return r->overread ? AAC_ERR_DECODE : 0;
}

/* fill_element() (Table 4.11).  The payload is skipped; an SBR extension
 * (extension_type 13 or 14, Table 4.56) is only noted, so an HE-AAC stream
 * decodes as its AAC-LC core at the core sample rate. */
int skip_fil(AacDecoderState* s, AacBitReader* r) {
  int count = (int)aac_bitreader_read(r, 4);
  if (count == 15) {
    count += (int)aac_bitreader_read(r, 8) - 1;
  }
  if (count > 0) {
    const int ext_type = (int)aac_bitreader_peek(r, 4);
    if (ext_type == 13 || ext_type == 14) {
      s->ch[0].has_sbr = s->ch[1].has_sbr = 1;
    }
  }
  aac_bitreader_skip(r, 8 * count);
  return r->overread ? AAC_ERR_DECODE : 0;
}

/* program_config_element() (Table 4.2): parsed only to stay in sync. */
int skip_pce(AacBitReader* r) {
  aac_bitreader_skip(r, 4 + 2 + 4); /* tag, object_type, sampling_frequency_index */
  const int front = (int)aac_bitreader_read(r, 4);
  const int side = (int)aac_bitreader_read(r, 4);
  const int back = (int)aac_bitreader_read(r, 4);
  const int lfe = (int)aac_bitreader_read(r, 2);
  const int assoc = (int)aac_bitreader_read(r, 3);
  const int cc = (int)aac_bitreader_read(r, 4);
  if (aac_bitreader_read(r, 1)) {
    aac_bitreader_skip(r, 4); /* mono_mixdown_element_number */
  }
  if (aac_bitreader_read(r, 1)) {
    aac_bitreader_skip(r, 4); /* stereo_mixdown_element_number */
  }
  if (aac_bitreader_read(r, 1)) {
    aac_bitreader_skip(r, 3); /* matrix_mixdown_idx, pseudo_surround_enable */
  }
  aac_bitreader_skip(r, 5 * (front + side + back) + 4 * (lfe + assoc) + 5 * cc);
  aac_bitreader_byte_align(r);
  const int comment = (int)aac_bitreader_read(r, 8);
  aac_bitreader_skip(r, 8 * comment);
  return r->overread ? AAC_ERR_DECODE : 0;
}

}  // namespace

AacDecoderState* aac_decoder_state_create(int sr, int ch, const AacDSP* dsp) {
  aac_tables_init();
  auto* s = new AacDecoderState();
  s->sample_rate = sr;
  s->channels = ch;
  s->stream_channels = ch;
  s->frame_size = 1024;
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
  aac_mdct_init(&s->mdct_long, 2048);
  aac_mdct_init(&s->mdct_short, 256);
  s->random_state = kRandomSeed;
  return s;
}

void aac_decoder_state_destroy(AacDecoderState* s) { delete s; }

int aac_decoder_set_rate_index(AacDecoderState* s, int rate_index) {
  if (rate_index < 0 || rate_index >= AAC_NUM_SAMPLE_RATES) {
    return AAC_ERR_UNSUPPORTED;
  }
  s->rate_index = rate_index;
  s->sample_rate = aac_sample_rates[rate_index];
  return 0;
}

int aac_decode_raw_data_block(AacDecoderState* s, AacBitReader* r) {
  int decoded = 0; /* channels decoded in this block */
  for (;;) {
    const int elem = (int)aac_bitreader_read(r, 3);
    if (r->overread) {
      return AAC_ERR_DECODE;
    }
    if (elem == AAC_ELEM_END) {
      break;
    }
    int ret = 0;
    switch (elem) {
      case AAC_ELEM_SCE:
      case AAC_ELEM_LFE:
        aac_bitreader_skip(r, 4); /* element_instance_tag */
        if (elem == AAC_ELEM_LFE) {
          /* An LFE channel only appears with more than two main channels. */
          return AAC_ERR_UNSUPPORTED;
        }
        if (decoded >= 2) {
          return AAC_ERR_UNSUPPORTED;
        }
        ret = decode_sce(s, r, decoded);
        decoded++;
        break;
      case AAC_ELEM_CPE:
        aac_bitreader_skip(r, 4);
        if (decoded != 0) {
          return AAC_ERR_UNSUPPORTED;
        }
        ret = decode_cpe(s, r);
        decoded = 2;
        break;
      case AAC_ELEM_DSE:
        ret = skip_dse(r);
        break;
      case AAC_ELEM_PCE:
        ret = skip_pce(r);
        break;
      case AAC_ELEM_FIL:
        ret = skip_fil(s, r);
        break;
      default: /* coupling channel elements */
        return AAC_ERR_UNSUPPORTED;
    }
    if (ret) {
      return ret;
    }
  }
  if (decoded == 0) {
    return AAC_ERR_DECODE;
  }
  for (int c = 0; c < decoded; c++) {
    apply_tns(&s->ch[c].cs);
    imdct_and_window(s, &s->ch[c]);
  }
  s->stream_channels = decoded;
  return decoded;
}
