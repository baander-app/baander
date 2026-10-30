#include "sbr.h"

#include <algorithm>
#include <cfloat>
#include <cmath>
#include <cstddef>
#include <cstring>

/* SBR decoder (ISO/IEC 14496-3 4.6.18).  Buffers and their offsets follow
 * FFmpeg's float decoder, which the test suite compares against:
 * X_low and X_high keep 8 slots of the previous frame in front of the
 * current 32 (the generator's history), the adjuster's buffers start 2
 * slots in, and Y holds the adjusted high band of two frames so that an
 * envelope reaching past the frame end is completed in the next one. */

namespace {

constexpr int kFixFix = 0;
constexpr int kFixVar = 1;
constexpr int kVarFix = 2;
constexpr int kVarVar = 3;
constexpr int kExtensionPs = 2;
constexpr int kNoiseFloorOffset = 6;
constexpr int kSlots = AAC_SBR_QMF_SLOTS;
constexpr int kHistory = AAC_SBR_HF_HISTORY;
constexpr int kAdj = AAC_SBR_ADJ_OFFSET;

/* ── frequency tables (4.6.18.3, sbr_freq.cpp) ─────────────────── */

}  // namespace

void aac_sbr_freq_from_state(const AacSbrState* s, AacSbrFreq* f) {
  memset(f, 0, sizeof(*f));
  f->sample_rate = s->sample_rate;
  f->start_freq = s->start_freq;
  f->stop_freq = s->stop_freq;
  f->xover_band = s->xover_band;
  f->freq_scale = s->freq_scale;
  f->alter_scale = s->alter_scale;
  f->noise_bands = s->noise_bands;
  f->limiter_bands = s->limiter_bands;
  f->kx = s->kx[1];
  f->m = s->m[1];
  f->n[0] = s->n[0];
  f->n[1] = s->n[1];
  f->num_patches = s->num_patches;
  memcpy(f->patch_num_subbands, s->patch_num_subbands, sizeof(f->patch_num_subbands));
  memcpy(f->patch_start_subband, s->patch_start_subband, sizeof(f->patch_start_subband));
  memcpy(f->f_tablelow, s->f_tablelow, sizeof(f->f_tablelow));
}

void aac_sbr_freq_to_state(const AacSbrFreq* f, AacSbrState* s) {
  memcpy(s->k, f->k, sizeof(s->k));
  s->kx[1] = f->kx;
  s->m[1] = f->m;
  s->n_master = f->n_master;
  memcpy(s->f_master, f->f_master, sizeof(s->f_master));
  s->n[0] = f->n[0];
  s->n[1] = f->n[1];
  memcpy(s->f_tablelow, f->f_tablelow, sizeof(s->f_tablelow));
  memcpy(s->f_tablehigh, f->f_tablehigh, sizeof(s->f_tablehigh));
  s->n_q = f->n_q;
  memcpy(s->f_tablenoise, f->f_tablenoise, sizeof(s->f_tablenoise));
  s->n_lim = f->n_lim;
  memcpy(s->f_tablelim, f->f_tablelim, sizeof(s->f_tablelim));
  s->num_patches = f->num_patches;
  memcpy(s->patch_num_subbands, f->patch_num_subbands, sizeof(s->patch_num_subbands));
  memcpy(s->patch_start_subband, f->patch_start_subband, sizeof(s->patch_start_subband));
}

namespace {

/* Limiter band table alone (4.6.18.3.6), after limiter_bands changed. */
void make_f_tablelim(AacSbrState* s) {
  AacSbrFreq f;
  aac_sbr_freq_from_state(s, &f);
  aac_sbr_freq_limiter(&f);
  s->n_lim = f.n_lim;
  memcpy(s->f_tablelim, f.f_tablelim, sizeof(s->f_tablelim));
}

/* Every table after a header change. */
int make_tables(AacSbrState* s) {
  AacSbrFreq f;
  aac_sbr_freq_from_state(s, &f);
  const int err = aac_sbr_freq_tables(&f);
  if (err) {
    return err;
  }
  aac_sbr_freq_to_state(&f, s);
  s->data[0].f_indexnoise = 0;
  s->data[1].f_indexnoise = 0;
  return 0;
}

/* ── bitstream (4.6.18.2 and Tables 4.56 to 4.68) ──────────────── */

int read_header(AacSbrState* s, AacBitReader* r) {
  const int old_limiter_bands = s->limiter_bands;
  const int old[6] = {s->start_freq, s->stop_freq, s->xover_band,
                      s->freq_scale, s->alter_scale, s->noise_bands};
  s->start = 1;
  s->ready_for_dequant = 0;
  s->amp_res_header = (int)aac_bitreader_read(r, 1);
  s->start_freq = (int)aac_bitreader_read(r, 4);
  s->stop_freq = (int)aac_bitreader_read(r, 4);
  s->xover_band = (int)aac_bitreader_read(r, 3);
  aac_bitreader_skip(r, 2);
  const int extra1 = (int)aac_bitreader_read(r, 1);
  const int extra2 = (int)aac_bitreader_read(r, 1);
  if (extra1) {
    s->freq_scale = (int)aac_bitreader_read(r, 2);
    s->alter_scale = (int)aac_bitreader_read(r, 1);
    s->noise_bands = (int)aac_bitreader_read(r, 2);
  } else {
    s->freq_scale = 2;
    s->alter_scale = 1;
    s->noise_bands = 2;
  }
  const int now[6] = {s->start_freq, s->stop_freq, s->xover_band,
                      s->freq_scale, s->alter_scale, s->noise_bands};
  if (memcmp(old, now, sizeof(old)) != 0) {
    s->reset = 1;
  }
  if (extra2) {
    s->limiter_bands = (int)aac_bitreader_read(r, 2);
    s->limiter_gains = (int)aac_bitreader_read(r, 2);
    s->interpol_freq = (int)aac_bitreader_read(r, 1);
    s->smoothing_mode = (int)aac_bitreader_read(r, 1);
  } else {
    s->limiter_bands = 2;
    s->limiter_gains = 2;
    s->interpol_freq = 1;
    s->smoothing_mode = 1;
  }
  if (s->limiter_bands != old_limiter_bands && !s->reset) {
    make_f_tablelim(s);
  }
  return 0;
}

const int8_t kCeilLog2[] = {0, 1, 2, 2, 3, 3};

}  // namespace

int aac_sbr_read_grid(AacSbrState* s, AacBitReader* r, AacSbrChannel* ch) {
  int bs_pointer = 0;
  int abs_bord_trail = s->time_slots;
  const int num_env_old = ch->num_env;
  ch->freq_res[0] = ch->freq_res[ch->num_env];
  ch->amp_res = s->amp_res_header;
  ch->t_env_num_env_old = ch->t_env[num_env_old];
  const int frame_class = (int)aac_bitreader_read(r, 2);
  switch (frame_class) {
    case kFixFix: {
      /* AAC allows 1, 2 or 4 envelopes; the USAC grid (ISO/IEC 23003-3
       * 7.5.2) also 8 */
      const int num_env = 1 << aac_bitreader_read(r, 2);
      if (num_env > (s->usac.active ? 8 : 4)) {
        return AAC_ERR_DECODE;
      }
      ch->num_env = num_env;
      if (num_env == 1) {
        ch->amp_res = 0;
      }
      ch->t_env[0] = 0;
      ch->t_env[num_env] = abs_bord_trail;
      abs_bord_trail = (abs_bord_trail + (num_env >> 1)) / num_env;
      for (int i = 0; i < num_env - 1; i++) {
        ch->t_env[i + 1] = ch->t_env[i] + abs_bord_trail;
      }
      ch->freq_res[1] = (int)aac_bitreader_read(r, 1);
      for (int i = 1; i < num_env; i++) {
        ch->freq_res[i + 1] = ch->freq_res[1];
      }
      break;
    }
    case kFixVar: {
      abs_bord_trail += (int)aac_bitreader_read(r, 2);
      const int num_rel_trail = (int)aac_bitreader_read(r, 2);
      ch->num_env = num_rel_trail + 1;
      ch->t_env[0] = 0;
      ch->t_env[ch->num_env] = abs_bord_trail;
      for (int i = 0; i < num_rel_trail; i++) {
        ch->t_env[ch->num_env - 1 - i] =
            ch->t_env[ch->num_env - i] - 2 * (int)aac_bitreader_read(r, 2) - 2;
      }
      bs_pointer = (int)aac_bitreader_read(r, kCeilLog2[ch->num_env]);
      for (int i = 0; i < ch->num_env; i++) {
        ch->freq_res[ch->num_env - i] = (int)aac_bitreader_read(r, 1);
      }
      break;
    }
    case kVarFix: {
      ch->t_env[0] = (int)aac_bitreader_read(r, 2);
      const int num_rel_lead = (int)aac_bitreader_read(r, 2);
      ch->num_env = num_rel_lead + 1;
      ch->t_env[ch->num_env] = abs_bord_trail;
      for (int i = 0; i < num_rel_lead; i++) {
        ch->t_env[i + 1] = ch->t_env[i] + 2 * (int)aac_bitreader_read(r, 2) + 2;
      }
      bs_pointer = (int)aac_bitreader_read(r, kCeilLog2[ch->num_env]);
      for (int i = 0; i < ch->num_env; i++) {
        ch->freq_res[i + 1] = (int)aac_bitreader_read(r, 1);
      }
      break;
    }
    default: {
      ch->t_env[0] = (int)aac_bitreader_read(r, 2);
      abs_bord_trail += (int)aac_bitreader_read(r, 2);
      const int num_rel_lead = (int)aac_bitreader_read(r, 2);
      const int num_rel_trail = (int)aac_bitreader_read(r, 2);
      const int num_env = num_rel_lead + num_rel_trail + 1;
      if (num_env > 5) {
        return AAC_ERR_DECODE;
      }
      ch->num_env = num_env;
      ch->t_env[num_env] = abs_bord_trail;
      for (int i = 0; i < num_rel_lead; i++) {
        ch->t_env[i + 1] = ch->t_env[i] + 2 * (int)aac_bitreader_read(r, 2) + 2;
      }
      for (int i = 0; i < num_rel_trail; i++) {
        ch->t_env[num_env - 1 - i] = ch->t_env[num_env - i] - 2 * (int)aac_bitreader_read(r, 2) - 2;
      }
      bs_pointer = (int)aac_bitreader_read(r, kCeilLog2[num_env]);
      for (int i = 0; i < num_env; i++) {
        ch->freq_res[i + 1] = (int)aac_bitreader_read(r, 1);
      }
      break;
    }
  }
  ch->frame_class = frame_class;
  if (bs_pointer > ch->num_env + 1) {
    return AAC_ERR_DECODE;
  }
  for (int i = 1; i <= ch->num_env; i++) {
    if (ch->t_env[i - 1] >= ch->t_env[i]) {
      return AAC_ERR_DECODE;
    }
  }
  ch->num_noise = (ch->num_env > 1) + 1;
  ch->t_q[0] = ch->t_env[0];
  ch->t_q[ch->num_noise] = ch->t_env[ch->num_env];
  if (ch->num_noise > 1) {
    int idx;
    if (ch->frame_class == kFixFix) {
      idx = ch->num_env >> 1;
    } else if (ch->frame_class & 1) {
      idx = ch->num_env - std::max(bs_pointer - 1, 1);
    } else if (!bs_pointer) {
      idx = 1;
    } else if (bs_pointer == 1) {
      idx = ch->num_env - 1;
    } else {
      idx = bs_pointer - 1;
    }
    ch->t_q[1] = ch->t_env[idx];
  }
  ch->e_a[0] = -(ch->e_a[1] != num_env_old);
  ch->e_a[1] = -1;
  if ((ch->frame_class & 1) && bs_pointer) {
    ch->e_a[1] = ch->num_env + 1 - bs_pointer;
  } else if (ch->frame_class == kVarFix && bs_pointer > 1) {
    ch->e_a[1] = bs_pointer - 1;
  }
  return 0;
}

void aac_sbr_copy_grid(AacSbrChannel* dst, const AacSbrChannel* src) {
  dst->freq_res[0] = dst->freq_res[dst->num_env];
  dst->t_env_num_env_old = dst->t_env[dst->num_env];
  dst->e_a[0] = -(dst->e_a[1] != dst->num_env);
  memcpy(dst->freq_res + 1, src->freq_res + 1, sizeof(dst->freq_res) - sizeof(dst->freq_res[0]));
  memcpy(dst->t_env, src->t_env, sizeof(dst->t_env));
  memcpy(dst->t_q, src->t_q, sizeof(dst->t_q));
  dst->num_env = src->num_env;
  dst->amp_res = src->amp_res;
  dst->num_noise = src->num_noise;
  dst->frame_class = src->frame_class;
  dst->e_a[1] = src->e_a[1];
}

void aac_sbr_read_dtdf(AacBitReader* r, AacSbrChannel* ch) {
  for (int i = 0; i < ch->num_env; i++) {
    ch->df_env[i] = (int)aac_bitreader_read(r, 1);
  }
  for (int i = 0; i < ch->num_noise; i++) {
    ch->df_noise[i] = (int)aac_bitreader_read(r, 1);
  }
}

void aac_sbr_read_invf(const AacSbrState* s, AacBitReader* r, AacSbrChannel* ch) {
  memcpy(ch->invf_mode[1], ch->invf_mode[0], sizeof(ch->invf_mode[0]));
  for (int i = 0; i < s->n_q; i++) {
    ch->invf_mode[0][i] = (int)aac_bitreader_read(r, 2);
  }
}

namespace {

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

}  // namespace

int aac_sbr_read_envelope(const AacSbrState* s, AacBitReader* r, AacSbrChannel* ch, int chan) {
  const int delta = (chan == 1 && s->coupling == 1) + 1;
  const int odd = s->n[1] & 1;
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
  for (int i = 0; i < ch->num_env; i++) {
    const int n = s->n[ch->freq_res[i + 1]];
    if (ch->df_env[i]) {
      for (int j = 0; j < n; j++) {
        const int k = aac_sbr_prev_band(j, ch->freq_res[i + 1], ch->freq_res[i], odd);
        ch->env_facs_q[i + 1][j] = ch->env_facs_q[i][k] + delta * huff(r, t_huff);
      }
    } else {
      ch->env_facs_q[i + 1][0] = delta * (int)aac_bitreader_read(r, bits);
      for (int j = 1; j < n; j++) {
        ch->env_facs_q[i + 1][j] = ch->env_facs_q[i + 1][j - 1] + delta * huff(r, f_huff);
      }
    }
    for (int j = 0; j < n; j++) {
      int* v = &ch->env_facs_q[i + 1][j];
      if (*v < 0 || *v > 127) {
        if (!s->unreliable) {
          return AAC_ERR_DECODE;
        }
        *v = *v < 0 ? 0 : 127;
      }
    }
  }
  memcpy(ch->env_facs_q[0], ch->env_facs_q[ch->num_env], sizeof(ch->env_facs_q[0]));
  return 0;
}

int aac_sbr_read_noise(const AacSbrState* s, AacBitReader* r, AacSbrChannel* ch, int chan) {
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
  for (int i = 0; i < ch->num_noise; i++) {
    if (ch->df_noise[i]) {
      for (int j = 0; j < s->n_q; j++) {
        ch->noise_facs_q[i + 1][j] = ch->noise_facs_q[i][j] + delta * huff(r, t_huff);
      }
    } else {
      ch->noise_facs_q[i + 1][0] = delta * (int)aac_bitreader_read(r, 5);
      for (int j = 1; j < s->n_q; j++) {
        ch->noise_facs_q[i + 1][j] = ch->noise_facs_q[i + 1][j - 1] + delta * huff(r, f_huff);
      }
    }
    for (int j = 0; j < s->n_q; j++) {
      int* v = &ch->noise_facs_q[i + 1][j];
      if (*v < 0 || *v > 30) {
        if (!s->unreliable) {
          return AAC_ERR_DECODE;
        }
        *v = *v < 0 ? 0 : 30;
      }
    }
  }
  memcpy(ch->noise_facs_q[0], ch->noise_facs_q[ch->num_noise], sizeof(ch->noise_facs_q[0]));
  return 0;
}

void aac_sbr_read_add_harmonic(const AacSbrState* s, AacBitReader* r, AacSbrChannel* ch) {
  ch->add_harmonic_flag = (int)aac_bitreader_read(r, 1);
  if (ch->add_harmonic_flag) {
    for (int i = 0; i < s->n[1]; i++) {
      ch->add_harmonic[i] = (int)aac_bitreader_read(r, 1);
    }
  }
}

namespace {

int read_single_channel(AacSbrState* s, AacBitReader* r) {
  if (aac_bitreader_read(r, 1)) {
    aac_bitreader_skip(r, 4); /* bs_reserved */
  }
  AacSbrChannel* ch = &s->data[0];
  int ret = aac_sbr_read_grid(s, r, ch);
  if (ret) {
    return ret;
  }
  aac_sbr_read_dtdf(r, ch);
  aac_sbr_read_invf(s, r, ch);
  if ((ret = aac_sbr_read_envelope(s, r, ch, 0)) || (ret = aac_sbr_read_noise(s, r, ch, 0))) {
    return ret;
  }
  aac_sbr_read_add_harmonic(s, r, ch);
  return 0;
}

int read_channel_pair(AacSbrState* s, AacBitReader* r) {
  if (aac_bitreader_read(r, 1)) {
    aac_bitreader_skip(r, 8); /* bs_reserved */
  }
  AacSbrChannel* l = &s->data[0];
  AacSbrChannel* rt = &s->data[1];
  int ret;
  s->coupling = (int)aac_bitreader_read(r, 1);
  if (s->coupling) {
    if ((ret = aac_sbr_read_grid(s, r, l))) {
      return ret;
    }
    aac_sbr_copy_grid(rt, l);
    aac_sbr_read_dtdf(r, l);
    aac_sbr_read_dtdf(r, rt);
    aac_sbr_read_invf(s, r, l);
    memcpy(rt->invf_mode[1], rt->invf_mode[0], sizeof(rt->invf_mode[0]));
    memcpy(rt->invf_mode[0], l->invf_mode[0], sizeof(rt->invf_mode[0]));
    if ((ret = aac_sbr_read_envelope(s, r, l, 0)) || (ret = aac_sbr_read_noise(s, r, l, 0)) ||
        (ret = aac_sbr_read_envelope(s, r, rt, 1)) || (ret = aac_sbr_read_noise(s, r, rt, 1))) {
      return ret;
    }
  } else {
    if ((ret = aac_sbr_read_grid(s, r, l)) || (ret = aac_sbr_read_grid(s, r, rt))) {
      return ret;
    }
    aac_sbr_read_dtdf(r, l);
    aac_sbr_read_dtdf(r, rt);
    aac_sbr_read_invf(s, r, l);
    aac_sbr_read_invf(s, r, rt);
    if ((ret = aac_sbr_read_envelope(s, r, l, 0)) || (ret = aac_sbr_read_envelope(s, r, rt, 1)) ||
        (ret = aac_sbr_read_noise(s, r, l, 0)) || (ret = aac_sbr_read_noise(s, r, rt, 1))) {
      return ret;
    }
  }
  aac_sbr_read_add_harmonic(s, r, l);
  aac_sbr_read_add_harmonic(s, r, rt);
  return 0;
}

/* sbr_extension(): parametric stereo data of a single channel element
 * goes to the PS decoder; anything else is padding. */
void read_extension(AacSbrState* s, AacBitReader* r, int id, int* bits_left) {
  if (id == kExtensionPs && s->elem_type == AAC_ELEM_SCE) {
    s->ps_present = 1;
    *bits_left -= aac_ps_read_data(&s->ps, r, *bits_left);
    return;
  }
  aac_bitreader_skip(r, *bits_left);
  *bits_left = 0;
}

int read_data(AacSbrState* s, AacBitReader* r, int elem_type) {
  s->elem_type = elem_type;
  s->ready_for_dequant = 1;
  int ret;
  if (elem_type == AAC_ELEM_SCE) {
    ret = read_single_channel(s, r);
  } else if (elem_type == AAC_ELEM_CPE) {
    ret = read_channel_pair(s, r);
  } else {
    ret = AAC_ERR_DECODE;
  }
  if (ret) {
    aac_sbr_turnoff(s);
    return ret;
  }
  /* A frequency-coded envelope and noise floor in every channel anchor
   * the delta-time chain again. */
  bool anchored = true;
  for (int c = 0; c < (elem_type == AAC_ELEM_CPE) + 1; c++) {
    const AacSbrChannel* ch = &s->data[c];
    bool env = false;
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
  if (aac_bitreader_read(r, 1)) { /* bs_extended_data */
    int bits_left = (int)aac_bitreader_read(r, 4);
    if (bits_left == 15) {
      bits_left += (int)aac_bitreader_read(r, 8);
    }
    bits_left <<= 3;
    while (bits_left > 7) {
      bits_left -= 2;
      read_extension(s, r, (int)aac_bitreader_read(r, 2), &bits_left);
    }
    if (bits_left > 0) {
      aac_bitreader_skip(r, bits_left);
    }
  }
  return 0;
}

/* ── dequantization (4.6.18.3.5) ───────────────────────────────── */

float exp2i(int x) { return ldexpf(1.0f, x); }

}  // namespace

void aac_sbr_dequant(AacSbrState* s) {
  static const float kExp2Half[2] = {1.0f, 1.41421356237309504880f};
  if (s->elem_type == AAC_ELEM_CPE && s->coupling) {
    AacSbrChannel* l = &s->data[0];
    AacSbrChannel* r = &s->data[1];
    const int pan_offset = l->amp_res ? 12 : 24;
    for (int e = 1; e <= l->num_env; e++) {
      for (int k = 0; k < s->n[l->freq_res[e]]; k++) {
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
    for (int e = 1; e <= l->num_noise; e++) {
      for (int k = 0; k < s->n_q; k++) {
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
    AacSbrChannel* ch = &s->data[c];
    for (int e = 1; e <= ch->num_env; e++) {
      for (int k = 0; k < s->n[ch->freq_res[e]]; k++) {
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
    for (int e = 1; e <= ch->num_noise; e++) {
      for (int k = 0; k < s->n_q; k++) {
        ch->noise_facs[e][k] = exp2i(kNoiseFloorOffset - ch->noise_facs_q[e][k]);
      }
    }
  }
}

namespace {

/* ── high-frequency generation (4.6.18.6) ──────────────────────── */

/* X_low: the current frame's lower subbands behind 8 slots of the previous
 * frame's. */
void lf_gen(const AacSbrState* s, float X_low[32][40][2], const float W[2][kSlots][32][2],
            int buf_idx) {
  const int slots = s->slots;
  memset(X_low, 0, 32 * sizeof(*X_low));
  for (int k = 0; k < s->kx[1]; k++) {
    for (int i = kHistory; i < slots + kHistory; i++) {
      X_low[k][i][0] = W[buf_idx][i - kHistory][k][0];
      X_low[k][i][1] = W[buf_idx][i - kHistory][k][1];
    }
  }
  buf_idx = 1 - buf_idx;
  for (int k = 0; k < s->kx[0]; k++) {
    for (int i = 0; i < kHistory; i++) {
      X_low[k][i][0] = W[buf_idx][i + slots - kHistory][k][0];
      X_low[k][i][1] = W[buf_idx][i + slots - kHistory][k][1];
    }
  }
}

/* Covariance of one subband over its `n` columns of X_low (the history
 * and the frame's slots), 4.6.18.6.2. */
void autocorrelate(const float x[40][2], int n, float phi[3][2][2]) {
  const int last = n - 1;
  float real_sum2 = x[0][0] * x[2][0] + x[0][1] * x[2][1];
  float imag_sum2 = x[0][0] * x[2][1] - x[0][1] * x[2][0];
  float real_sum1 = 0.0f;
  float imag_sum1 = 0.0f;
  float real_sum0 = 0.0f;
  for (int i = 1; i < last - 1; i++) {
    real_sum0 += x[i][0] * x[i][0] + x[i][1] * x[i][1];
    real_sum1 += x[i][0] * x[i + 1][0] + x[i][1] * x[i + 1][1];
    imag_sum1 += x[i][0] * x[i + 1][1] - x[i][1] * x[i + 1][0];
    real_sum2 += x[i][0] * x[i + 2][0] + x[i][1] * x[i + 2][1];
    imag_sum2 += x[i][0] * x[i + 2][1] - x[i][1] * x[i + 2][0];
  }
  phi[0][1][0] = real_sum2;
  phi[0][1][1] = imag_sum2;
  phi[2][1][0] = real_sum0 + x[0][0] * x[0][0] + x[0][1] * x[0][1];
  phi[1][0][0] = real_sum0 + x[last - 1][0] * x[last - 1][0] + x[last - 1][1] * x[last - 1][1];
  phi[1][1][0] = real_sum1 + x[0][0] * x[1][0] + x[0][1] * x[1][1];
  phi[1][1][1] = imag_sum1 + x[0][0] * x[1][1] - x[0][1] * x[1][0];
  phi[0][0][0] = real_sum1 + x[last - 1][0] * x[last][0] + x[last - 1][1] * x[last][1];
  phi[0][0][1] = imag_sum1 + x[last - 1][0] * x[last][1] - x[last - 1][1] * x[last][0];
}

}  // namespace

/* Second-order linear prediction of one subband over its `n` columns
 * (4.6.18.6.2). */
void aac_sbr_lpc_band(float alpha0[2], float alpha1[2], const float (*x)[2], int n) {
  float phi[3][2][2];
  autocorrelate(x, n, phi);
  /* A band whose energy sits far below any audible level predicts
   * nothing; its tiny correlations, divided by each other, would
   * overflow (and under -ffast-math the overflow is not caught below). */
  if (phi[1][0][0] <= 1e-15f) {
    alpha1[0] = alpha1[1] = 0.0f;
    alpha0[0] = alpha0[1] = 0.0f;
    return;
  }
  const float dk = phi[2][1][0] * phi[1][0][0] -
                   (phi[1][1][0] * phi[1][1][0] + phi[1][1][1] * phi[1][1][1]) / 1.000001f;
  if (dk == 0.0f) {
    alpha1[0] = 0.0f;
    alpha1[1] = 0.0f;
  } else {
    const float temp_real = phi[0][0][0] * phi[1][1][0] - phi[0][0][1] * phi[1][1][1] -
                            phi[0][1][0] * phi[1][0][0];
    const float temp_im = phi[0][0][0] * phi[1][1][1] + phi[0][0][1] * phi[1][1][0] -
                          phi[0][1][1] * phi[1][0][0];
    alpha1[0] = temp_real / dk;
    alpha1[1] = temp_im / dk;
  }
  if (phi[1][0][0] == 0.0f) {
    alpha0[0] = 0.0f;
    alpha0[1] = 0.0f;
  } else {
    const float temp_real =
        phi[0][0][0] + alpha1[0] * phi[1][1][0] + alpha1[1] * phi[1][1][1];
    const float temp_im =
        phi[0][0][1] + alpha1[1] * phi[1][1][0] - alpha1[0] * phi[1][1][1];
    alpha0[0] = -temp_real / phi[1][0][0];
    alpha0[1] = -temp_im / phi[1][0][0];
  }
  if (alpha1[0] * alpha1[0] + alpha1[1] * alpha1[1] >= 16.0f ||
      alpha0[0] * alpha0[0] + alpha0[1] * alpha0[1] >= 16.0f) {
    alpha1[0] = alpha1[1] = 0.0f;
    alpha0[0] = alpha0[1] = 0.0f;
  }
}

namespace {

/* Second-order linear prediction per subband (4.6.18.6.2). */
void hf_inverse_filter(float (*alpha0)[2], float (*alpha1)[2], const float X_low[32][40][2],
                       int k0, int slots) {
  for (int k = 0; k < k0; k++) {
    aac_sbr_lpc_band(alpha0[k], alpha1[k], X_low[k], slots + kHistory);
  }
}

}  // namespace

/* Chirp factors (4.6.18.6.1). */
void aac_sbr_chirp(const AacSbrState* s, AacSbrChannel* ch) {
  static const float kBw[4] = {0.0f, 0.75f, 0.9f, 0.98f};
  for (int i = 0; i < s->n_q; i++) {
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

void aac_sbr_hf_gen_band(float (*X_high)[2], const float (*X_low)[2], const float alpha0[2],
                         const float alpha1[2], float bw, int start, int end) {
  const float a0 = alpha1[0] * bw * bw;
  const float a1 = alpha1[1] * bw * bw;
  const float a2 = alpha0[0] * bw;
  const float a3 = alpha0[1] * bw;
  for (int i = start; i < end; i++) {
    X_high[i][0] = X_low[i - 2][0] * a0 - X_low[i - 2][1] * a1 + X_low[i - 1][0] * a2 -
                   X_low[i - 1][1] * a3 + X_low[i][0];
    X_high[i][1] = X_low[i - 2][1] * a0 + X_low[i - 2][0] * a1 + X_low[i - 1][1] * a2 +
                   X_low[i - 1][0] * a3 + X_low[i][1];
  }
}

namespace {

int hf_gen(AacSbrState* s, const AacSbrChannel* ch) {
  int g = 0;
  int k = s->kx[1];
  for (int j = 0; j < s->num_patches; j++) {
    for (int x = 0; x < s->patch_num_subbands[j]; x++, k++) {
      const int p = s->patch_start_subband[j] + x;
      while (g <= s->n_q && k >= s->f_tablenoise[g]) {
        g++;
      }
      g--;
      if (g < 0) {
        return AAC_ERR_DECODE;
      }
      aac_sbr_hf_gen_band(s->X_high[k] + kAdj, s->X_low[p] + kAdj, s->alpha0[p], s->alpha1[p],
                  ch->bw_array[g], 2 * ch->t_env[0], 2 * ch->t_env[ch->num_env]);
    }
  }
  if (k < s->m[1] + s->kx[1]) {
    memset(s->X_high + k, 0, (size_t)(s->m[1] + s->kx[1] - k) * sizeof(*s->X_high));
  }
  return 0;
}

/* Assembles the synthesis input from the low band and the two adjusted
 * high-band buffers (the previous frame's for slots its last envelope
 * still covers).  The low band is filled six slots past the frame for
 * the parametric stereo hybrid filters' lookahead. */
void x_gen(const AacSbrState* s, float X[2][38][64], const float Y0[38][64][2],
           const float Y1[38][64][2], const float X_low[32][40][2], int c) {
  const int slots = s->slots;
  const int i_temp = std::max(2 * s->data[c].t_env_num_env_old - slots, 0);
  memset(X, 0, 2 * sizeof(*X));
  int k;
  for (k = 0; k < s->kx[0]; k++) {
    for (int i = 0; i < i_temp; i++) {
      X[0][i][k] = X_low[k][i + kAdj][0];
      X[1][i][k] = X_low[k][i + kAdj][1];
    }
  }
  for (; k < s->kx[0] + s->m[0]; k++) {
    for (int i = 0; i < i_temp; i++) {
      X[0][i][k] = Y0[i + slots][k][0];
      X[1][i][k] = Y0[i + slots][k][1];
    }
  }
  for (k = 0; k < s->kx[1]; k++) {
    for (int i = i_temp; i < slots + 6; i++) {
      X[0][i][k] = X_low[k][i + kAdj][0];
      X[1][i][k] = X_low[k][i + kAdj][1];
    }
  }
  for (; k < s->kx[1] + s->m[1]; k++) {
    for (int i = i_temp; i < slots; i++) {
      X[0][i][k] = Y1[i][k][0];
      X[1][i][k] = Y1[i][k][1];
    }
  }
}

/* ── envelope adjustment (4.6.18.7) ────────────────────────────── */

int mapping(AacSbrState* s, AacSbrChannel* ch, const int e_a[2]) {
  memset(ch->s_indexmapped[1], 0, 7 * sizeof(ch->s_indexmapped[1]));
  for (int e = 0; e < ch->num_env; e++) {
    const int ilim = s->n[ch->freq_res[e + 1]];
    const uint16_t* table = ch->freq_res[e + 1] ? s->f_tablehigh : s->f_tablelow;
    if (s->kx[1] != table[0]) {
      aac_sbr_turnoff(s);
      return AAC_ERR_DECODE;
    }
    for (int i = 0; i < ilim; i++) {
      for (int m = table[i]; m < table[i + 1]; m++) {
        s->e_origmapped[e][m - s->kx[1]] = ch->env_facs[e + 1][i];
      }
    }
    const int k = (ch->num_noise > 1) && (ch->t_env[e] >= ch->t_q[1]);
    for (int i = 0; i < s->n_q; i++) {
      for (int m = s->f_tablenoise[i]; m < s->f_tablenoise[i + 1]; m++) {
        s->q_mapped[e][m - s->kx[1]] = ch->noise_facs[k + 1][i];
      }
    }
    for (int i = 0; i < s->n[1]; i++) {
      if (ch->add_harmonic_flag) {
        const int m_midpoint = (s->f_tablehigh[i] + s->f_tablehigh[i + 1]) >> 1;
        ch->s_indexmapped[e + 1][m_midpoint - s->kx[1]] =
            (uint8_t)(ch->add_harmonic[i] &&
                      (e >= e_a[1] || ch->s_indexmapped[0][m_midpoint - s->kx[1]] == 1));
      }
    }
    for (int i = 0; i < ilim; i++) {
      int present = 0;
      for (int m = table[i]; m < table[i + 1]; m++) {
        if (ch->s_indexmapped[e + 1][m - s->kx[1]]) {
          present = 1;
          break;
        }
      }
      memset(&s->s_mapped[e][table[i] - s->kx[1]], present,
             (size_t)(table[i + 1] - table[i]) * sizeof(s->s_mapped[e][0]));
    }
  }
  memcpy(ch->s_indexmapped[0], ch->s_indexmapped[ch->num_env], sizeof(ch->s_indexmapped[0]));
  return 0;
}

float sum_square(const float (*x)[2], int n) {
  float sum = 0.0f;
  for (int i = 0; i < n; i++) {
    sum += x[i][0] * x[i][0] + x[i][1] * x[i][1];
  }
  return sum;
}

void env_estimate(AacSbrState* s, const AacSbrChannel* ch) {
  const int kx1 = s->kx[1];
  if (s->interpol_freq) {
    for (int e = 0; e < ch->num_env; e++) {
      const float recip_env_size = 0.5f / (float)(ch->t_env[e + 1] - ch->t_env[e]);
      const int ilb = ch->t_env[e] * 2 + kAdj;
      const int iub = ch->t_env[e + 1] * 2 + kAdj;
      if (ilb >= 40) {
        return;
      }
      for (int m = 0; m < s->m[1]; m++) {
        s->e_curr[e][m] = sum_square(s->X_high[m + kx1] + ilb, iub - ilb) * recip_env_size;
      }
    }
    return;
  }
  for (int e = 0; e < ch->num_env; e++) {
    const int env_size = 2 * (ch->t_env[e + 1] - ch->t_env[e]);
    const int ilb = ch->t_env[e] * 2 + kAdj;
    const int iub = ch->t_env[e + 1] * 2 + kAdj;
    const uint16_t* table = ch->freq_res[e + 1] ? s->f_tablehigh : s->f_tablelow;
    if (ilb >= 40) {
      return;
    }
    for (int p = 0; p < s->n[ch->freq_res[e + 1]]; p++) {
      float sum = 0.0f;
      const int den = env_size * (table[p + 1] - table[p]);
      for (int k = table[p]; k < table[p + 1]; k++) {
        sum += sum_square(s->X_high[k] + ilb, iub - ilb);
      }
      sum /= (float)den;
      for (int k = table[p]; k < table[p + 1]; k++) {
        s->e_curr[e][k - kx1] = sum;
      }
    }
  }
}

void gain_calc(AacSbrState* s, const AacSbrChannel* ch, const int e_a[2]) {
  static const float kLimGain[4] = {0.70795f, 1.0f, 1.41254f, 1e10f};
  for (int e = 0; e < ch->num_env; e++) {
    const int delta = !((e == e_a[1]) || (e == e_a[0]));
    for (int k = 0; k < s->n_lim; k++) {
      const int m0 = s->f_tablelim[k] - s->kx[1];
      const int m1 = s->f_tablelim[k + 1] - s->kx[1];
      float sum[2] = {0.0f, 0.0f};
      for (int m = m0; m < m1; m++) {
        const float temp = s->e_origmapped[e][m] / (1.0f + s->q_mapped[e][m]);
        s->q_m[e][m] = sqrtf(temp * s->q_mapped[e][m]);
        s->s_m[e][m] = sqrtf(temp * (float)ch->s_indexmapped[e + 1][m]);
        if (!s->s_mapped[e][m]) {
          s->gain[e][m] = sqrtf(s->e_origmapped[e][m] /
                                ((1.0f + s->e_curr[e][m]) * (1.0f + s->q_mapped[e][m] * (float)delta)));
        } else {
          s->gain[e][m] = sqrtf(s->e_origmapped[e][m] * s->q_mapped[e][m] /
                                ((1.0f + s->e_curr[e][m]) * (1.0f + s->q_mapped[e][m])));
        }
        s->gain[e][m] += FLT_MIN;
      }
      for (int m = m0; m < m1; m++) {
        sum[0] += s->e_origmapped[e][m];
        sum[1] += s->e_curr[e][m];
      }
      float gain_max = kLimGain[s->limiter_gains] * sqrtf((FLT_EPSILON + sum[0]) / (FLT_EPSILON + sum[1]));
      gain_max = std::min(100000.0f, gain_max);
      for (int m = m0; m < m1; m++) {
        const float q_m_max = s->q_m[e][m] * gain_max / s->gain[e][m];
        s->q_m[e][m] = std::min(s->q_m[e][m], q_m_max);
        s->gain[e][m] = std::min(s->gain[e][m], gain_max);
      }
      sum[0] = sum[1] = 0.0f;
      for (int m = m0; m < m1; m++) {
        sum[0] += s->e_origmapped[e][m];
        sum[1] += s->e_curr[e][m] * s->gain[e][m] * s->gain[e][m] + s->s_m[e][m] * s->s_m[e][m] +
                  (float)(delta && !s->s_m[e][m]) * s->q_m[e][m] * s->q_m[e][m];
      }
      float gain_boost = sqrtf((FLT_EPSILON + sum[0]) / (FLT_EPSILON + sum[1]));
      gain_boost = std::min(1.584893192f, gain_boost);
      for (int m = m0; m < m1; m++) {
        s->gain[e][m] *= gain_boost;
        s->q_m[e][m] *= gain_boost;
        s->s_m[e][m] *= gain_boost;
      }
    }
  }
}

}  // namespace

/* Noise and sinusoids for one slot: the noise phase table advances per
 * band, the sinusoid phase pattern alternates with the band index. */
void aac_sbr_apply_noise(float (*Y)[2], const float* s_m, const float* q_filt, unsigned noise,
                         int kx, int m_max, int indexsine) {
  float phi_sign0;
  float phi_sign1;
  switch (indexsine) {
    case 0: phi_sign0 = 1.0f; phi_sign1 = 0.0f; break;
    case 1: phi_sign0 = 0.0f; phi_sign1 = 1.0f - 2.0f * (float)(kx & 1); break;
    case 2: phi_sign0 = -1.0f; phi_sign1 = 0.0f; break;
    default: phi_sign0 = 0.0f; phi_sign1 = -(1.0f - 2.0f * (float)(kx & 1)); break;
  }
  for (int m = 0; m < m_max; m++) {
    float y0 = Y[m][0];
    float y1 = Y[m][1];
    noise = (noise + 1) & 0x1ff;
    if (s_m[m] != 0.0f) {
      y0 += s_m[m] * phi_sign0;
      y1 += s_m[m] * phi_sign1;
    } else {
      y0 += q_filt[m] * aac_sbr_noise_table[noise][0];
      y1 += q_filt[m] * aac_sbr_noise_table[noise][1];
    }
    Y[m][0] = y0;
    Y[m][1] = y1;
    phi_sign1 = -phi_sign1;
  }
}

namespace {

void hf_assemble(AacSbrState* s, AacSbrChannel* ch, float Y1[38][64][2], const int e_a[2]) {
  const int h_SL = 4 * !s->smoothing_mode;
  const int kx = s->kx[1];
  const int m_max = s->m[1];
  static const float kSmooth[5] = {0.33333333333333f, 0.30150283239582f, 0.21816949906249f,
                                   0.11516383427084f, 0.03183050093751f};
  float (*g_temp)[AAC_SBR_MAX_BANDS] = ch->g_temp;
  float (*q_temp)[AAC_SBR_MAX_BANDS] = ch->q_temp;
  unsigned indexnoise = ch->f_indexnoise;
  int indexsine = (int)ch->f_indexsine;

  if (s->reset) {
    for (int i = 0; i < h_SL; i++) {
      memcpy(g_temp[i + 2 * ch->t_env[0]], s->gain[0], (size_t)m_max * sizeof(s->gain[0][0]));
      memcpy(q_temp[i + 2 * ch->t_env[0]], s->q_m[0], (size_t)m_max * sizeof(s->q_m[0][0]));
    }
  } else if (h_SL) {
    for (int i = 0; i < 4; i++) {
      memcpy(g_temp[i + 2 * ch->t_env[0]], g_temp[i + 2 * ch->t_env_num_env_old], sizeof(g_temp[0]));
      memcpy(q_temp[i + 2 * ch->t_env[0]], q_temp[i + 2 * ch->t_env_num_env_old], sizeof(q_temp[0]));
    }
  }
  for (int e = 0; e < ch->num_env; e++) {
    for (int i = 2 * ch->t_env[e]; i < 2 * ch->t_env[e + 1]; i++) {
      memcpy(g_temp[h_SL + i], s->gain[e], (size_t)m_max * sizeof(s->gain[0][0]));
      memcpy(q_temp[h_SL + i], s->q_m[e], (size_t)m_max * sizeof(s->q_m[0][0]));
    }
  }
  for (int e = 0; e < ch->num_env; e++) {
    for (int i = 2 * ch->t_env[e]; i < 2 * ch->t_env[e + 1]; i++) {
      float g_filt_tab[AAC_SBR_MAX_BANDS];
      float q_filt_tab[AAC_SBR_MAX_BANDS];
      const float* g_filt;
      const float* q_filt;
      if (h_SL && e != e_a[0] && e != e_a[1]) {
        g_filt = g_filt_tab;
        q_filt = q_filt_tab;
        for (int m = 0; m < m_max; m++) {
          const int idx1 = i + h_SL;
          float g = 0.0f;
          float q = 0.0f;
          for (int j = 0; j <= h_SL; j++) {
            g += g_temp[idx1 - j][m] * kSmooth[j];
            q += q_temp[idx1 - j][m] * kSmooth[j];
          }
          g_filt_tab[m] = g;
          q_filt_tab[m] = q;
        }
      } else {
        g_filt = g_temp[i + h_SL];
        q_filt = q_temp[i];
      }
      for (int m = 0; m < m_max; m++) {
        Y1[i][kx + m][0] = s->X_high[kx + m][i + kAdj][0] * g_filt[m];
        Y1[i][kx + m][1] = s->X_high[kx + m][i + kAdj][1] * g_filt[m];
      }
      if (e != e_a[0] && e != e_a[1]) {
        aac_sbr_apply_noise(Y1[i] + kx, s->s_m[e], q_filt, indexnoise, kx, m_max, indexsine);
      } else {
        const int idx = indexsine & 1;
        const int A = 1 - ((indexsine + (kx & 1)) & 2);
        const int B = (A ^ (-idx)) + idx;
        float* out = &Y1[i][kx][idx];
        const float* in = s->s_m[e];
        int m;
        for (m = 0; m + 1 < m_max; m += 2) {
          out[2 * m] += in[m] * (float)A;
          out[2 * m + 2] += in[m + 1] * (float)B;
        }
        if (m_max & 1) {
          out[2 * m] += in[m] * (float)A;
        }
      }
      indexnoise = (indexnoise + (unsigned)m_max) & 0x1ff;
      indexsine = (indexsine + 1) & 3;
    }
  }
  ch->f_indexnoise = indexnoise;
  ch->f_indexsine = (unsigned)indexsine;
}

}  // namespace

/* ── public ────────────────────────────────────────────────────── */

void aac_sbr_turnoff(AacSbrState* s) {
  s->start = 0;
  s->ready_for_dequant = 0;
  s->kx[1] = 32; /* the standard's typo: kx' starts at 32 */
  s->m[1] = 0;
  s->data[0].e_a[1] = s->data[1].e_a[1] = -1;
  s->start_freq = s->stop_freq = s->xover_band = -1;
  s->freq_scale = s->alter_scale = s->noise_bands = -1;
}

void aac_sbr_init(AacSbrState* s, int core_sample_rate, int frame_length) {
  memset(s, 0, sizeof(*s));
  s->sample_rate = 2 * core_sample_rate;
  s->slots = frame_length / 32;
  s->time_slots = s->slots / 2;
  aac_sbr_turnoff(s);
  s->kx[0] = s->kx[1];
  for (int c = 0; c < 2; c++) {
    aac_qmf_analysis_init(&s->data[c].analysis, 32);
    aac_qmf_synthesis_init(&s->data[c].synthesis, 64);
  }
  aac_ps_init(&s->ps, s->slots);
  aac_drc_qmf_init(&s->drc);
}

namespace {

/* The parameters a frame's bitstream sets: the state's leading fields
 * (header, tables, frame flags), each channel's leading fields and the PS
 * parameters.  Saved before an extension is parsed and put back when the
 * extension turns out to be damaged, so that the previous frame's data
 * stays available for concealment. */
struct SbrParams {
  uint8_t state[offsetof(AacSbrState_, data)];
  uint8_t channel[2][offsetof(AacSbrChannel_, analysis)];
  uint8_t ps[offsetof(AacPsState_, in_buf)];
};

void save_params(const AacSbrState* s, SbrParams* p) {
  memcpy(p->state, s, sizeof(p->state));
  memcpy(p->channel[0], &s->data[0], sizeof(p->channel[0]));
  memcpy(p->channel[1], &s->data[1], sizeof(p->channel[1]));
  memcpy(p->ps, &s->ps, sizeof(p->ps));
}

void restore_params(AacSbrState* s, const SbrParams* p) {
  memcpy(s, p->state, sizeof(p->state));
  memcpy(&s->data[0], p->channel[0], sizeof(p->channel[0]));
  memcpy(&s->data[1], p->channel[1], sizeof(p->channel[1]));
  memcpy(&s->ps, p->ps, sizeof(p->ps));
}

}  // namespace

int aac_sbr_decode_extension(AacSbrState* s, AacBitReader* r, int crc, int count, int elem_type) {
  AacBitReader local = *r;
  aac_bitreader_skip(r, count * 8 - 4);
  SbrParams saved;
  save_params(s, &saved);
  const int ps_was_on = s->ps.start;
  s->reset = 0;
  s->ps_present = 0;
  unsigned num_sbr_bits = 0;
  if (crc) {
    aac_bitreader_skip(&local, 10);
    num_sbr_bits += 10;
  }
  s->kx[0] = s->kx[1];
  s->m[0] = s->m[1];
  s->kx_and_m_pushed = 1;
  const int start_pos = local.pos;
  int ret = 0;
  if (aac_bitreader_read(&local, 1)) {
    read_header(s, &local);
  }
  if (s->reset) {
    /* A header whose frequency tables cannot be built (a combination
     * the standard rules out) is a damaged header: the payload is
     * discarded with it and the header in force stays, so that the
     * element is concealed on its previous parameters rather than
     * dropping to upsampling until the next header. */
    ret = make_tables(s);
  }
  if (ret == 0 && s->start) {
    ret = read_data(s, &local, elem_type);
  }
  num_sbr_bits += (unsigned)(local.pos - start_pos);
  const unsigned align = ((unsigned)(count << 3) - 4 - num_sbr_bits) & 7;
  const unsigned bytes_read = (num_sbr_bits + align + 4) >> 3;
  if (ret || local.overread || bytes_read > (unsigned)count || (ps_was_on && !s->ps.start)) {
    /* Damaged: the previous frame's parameters stand, with no data for
     * this frame. */
    restore_params(s, &saved);
    s->unreliable = 1;
    s->ps.unreliable = 1;
    return AAC_ERR_DECODE;
  }
  return 0;
}

void aac_sbr_mark_lost(AacSbrState* s) {
  s->unreliable = 1;
  s->ps.unreliable = 1;
}

void aac_sbr_conceal(AacSbrState* s) {
  if (!s->start) {
    return;
  }
  s->kx[0] = s->kx[1];
  s->m[0] = s->m[1];
  s->kx_and_m_pushed = 1;
  s->reset = 0;
  const int nch = s->elem_type == AAC_ELEM_CPE ? 2 : 1;
  for (int c = 0; c < nch; c++) {
    AacSbrChannel* ch = &s->data[c];
    const int num_env_old = ch->num_env;
    /* One fixed envelope over the frame at the resolution of the previous
     * frame's last one, whose values env_facs_q[0] holds. */
    ch->freq_res[0] = ch->freq_res[num_env_old];
    ch->t_env_num_env_old = ch->t_env[num_env_old];
    ch->e_a[0] = -(ch->e_a[1] != num_env_old);
    ch->e_a[1] = -1;
    ch->frame_class = kFixFix;
    ch->num_env = 1;
    ch->freq_res[1] = ch->freq_res[0];
    ch->t_env[0] = 0;
    ch->t_env[1] = s->time_slots;
    ch->num_noise = 1;
    ch->t_q[0] = 0;
    ch->t_q[1] = s->time_slots;
    ch->df_env[0] = 1;
    ch->df_noise[0] = 1;
    memcpy(ch->invf_mode[1], ch->invf_mode[0], sizeof(ch->invf_mode[0]));
    /* The level channel drops 3 dB (one step at amp_res 1, two at 0); the
     * balance channel of a coupled pair keeps its panning. */
    const int step = (s->coupling && c == 1) ? 0 : (ch->amp_res ? 1 : 2);
    const int n = s->n[ch->freq_res[1]];
    for (int k = 0; k < n; k++) {
      const int q = ch->env_facs_q[0][k] - step;
      ch->env_facs_q[1][k] = q < 0 ? 0 : q;
    }
    memcpy(ch->env_facs_q[0], ch->env_facs_q[1], sizeof(ch->env_facs_q[0]));
    memcpy(ch->noise_facs_q[1], ch->noise_facs_q[0], sizeof(ch->noise_facs_q[0]));
  }
  if (s->ps.start) {
    aac_ps_conceal(&s->ps);
  }
  s->unreliable = 1;
  s->ready_for_dequant = 1;
}

namespace {

/* Everything of aac_sbr_apply up to the synthesis: the reconstructed
 * frame of every output channel is left in s->X[]. */
void sbr_reconstruct(AacSbrState* s, int elem_type, const float* left, const float* right) {
  const int nch = elem_type == AAC_ELEM_CPE && right ? 2 : 1;
  const bool ps_output = elem_type == AAC_ELEM_SCE && right;
  if (elem_type != s->elem_type) {
    aac_sbr_turnoff(s);
  }
  if (s->start && !s->ready_for_dequant) {
    aac_sbr_turnoff(s);
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
  const int slots = s->slots;
  const int core_samples = 32 * slots;
  for (int c = 0; c < nch; c++) {
    AacSbrChannel* ch = &s->data[c];
    const float* pcm = c ? right : left;
    float core[AAC_FRAME_SIZE_LONG];
    for (int i = 0; i < core_samples; i++) {
      core[i] = pcm[i] * 32768.0f;
    }
    for (int i = 0; i < slots; i++) {
      aac_qmf_analyze(&ch->analysis, core + 32 * i, ch->W[ch->Ypos][i]);
    }
    lf_gen(s, s->X_low, ch->W, ch->Ypos);
    ch->Ypos ^= 1;
    if (s->start) {
      hf_inverse_filter(s->alpha0, s->alpha1, s->X_low, s->k[0], slots);
      aac_sbr_chirp(s, ch);
      if (hf_gen(s, ch) == 0 && mapping(s, ch, ch->e_a) == 0) {
        env_estimate(s, ch);
        gain_calc(s, ch, ch->e_a);
        hf_assemble(s, ch, ch->Y[ch->Ypos], ch->e_a);
      }
    }
    x_gen(s, s->X[c], ch->Y[1 - ch->Ypos], ch->Y[ch->Ypos], s->X_low, c);
    aac_drc_apply_qmf(&s->drc, c, s->X[c], slots);
  }
  s->drc.active = 0;
  if (ps_output) {
    if (s->ps.start) {
      aac_ps_apply(&s->ps, s->X[0], s->X[1], s->kx[1] + s->m[1]);
    } else {
      memcpy(s->X[1], s->X[0], sizeof(s->X[0]));
    }
  }
}

}  // namespace

void aac_sbr_apply(AacSbrState* s, int elem_type, float* left, float* right) {
  const int nch = elem_type == AAC_ELEM_CPE && right ? 2 : 1;
  const bool ps_output = elem_type == AAC_ELEM_SCE && right;
  sbr_reconstruct(s, elem_type, left, right);
  const int slots = s->slots;
  for (int c = 0; c < (ps_output ? 2 : nch); c++) {
    AacSbrChannel* ch = &s->data[c];
    float* pcm = c ? right : left;
    for (int i = 0; i < slots; i++) {
      float X[64][2];
      for (int k = 0; k < 64; k++) {
        X[k][0] = s->X[c][0][i][k];
        X[k][1] = s->X[c][1][i][k];
      }
      aac_qmf_synthesize(&ch->synthesis, X, pcm + 64 * i);
    }
    for (int i = 0; i < 64 * slots; i++) {
      pcm[i] *= 1.0f / 32768.0f;
    }
  }
}

void aac_sbr_apply_to_qmf(AacSbrState* s, int elem_type, const float* left, const float* right,
                          struct AacUsacQmfFrame_* out_left, struct AacUsacQmfFrame_* out_right) {
  const int nch = elem_type == AAC_ELEM_CPE && right ? 2 : 1;
  sbr_reconstruct(s, elem_type, left, right);
  const int slots = s->slots;
  for (int c = 0; c < nch; c++) {
    AacUsacQmfFrame* out = c ? out_right : out_left;
    if (out == nullptr) {
      continue;
    }
    out->slots = slots;
    for (int i = 0; i < slots; i++) {
      for (int k = 0; k < 64; k++) {
        out->x[i][k][0] = s->X[c][0][i][k];
        out->x[i][k][1] = s->X[c][1][i][k];
      }
    }
  }
}
