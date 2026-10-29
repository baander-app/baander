/* The frequency-domain core coder of USAC (ISO/IEC 23003-3 6.2, 7.2 to
 * 7.9): UsacCoreCoderData() of an SCE, CPE or LFE, the fd_channel_stream
 * syntax, the dequantisation with noise filling, the stereo tools, TNS
 * and the inverse filterbank with the USAC window sequences. */
#include "usac_fd.h"

#include <cmath>
#include <cstring>

#include "ics.h"
#include "mdct.h"
#include "usac_lpd.h"

namespace {

constexpr int kMaxFrame = AAC_USAC_MAX_CORE_FRAME;
constexpr int kMaxShort = AAC_FRAME_SIZE_SHORT;
constexpr int kMaxBands = AAC_MAX_BANDS;
constexpr int kTnsMaxOrder = 15;
constexpr float kOutputScale = 1.0f / 32768.0f;
constexpr float kConcealFade = 0.70794578f; /* -3 dB per frame */

/* The band layout of a frame length: the AAC tables of the 1024 and 128
 * line transforms, cut at 768 and 96 for the shorter frame. */
struct BandTable {
  int num_long, num_short;
  int off_long[AAC_MAX_SFB_LONG + 2];
  int off_short[AAC_MAX_SFB_SHORT + 2];
  int tns_max_long, tns_max_short;
};

void cut_offsets(const int* src, int count, int limit, int* dst, int* num) {
  int n = 0;
  dst[0] = 0;
  for (int b = 0; b < count; b++) {
    if (src[b + 1] >= limit) {
      dst[++n] = limit;
      break;
    }
    dst[++n] = src[b + 1];
  }
  *num = n;
}

/* The sampling frequency index of a core rate, by the ranges of
 * ISO/IEC 14496-3 Table 4.80 (the index whose range holds the rate),
 * so that a rate between two table rates, as the 8:3 SBR ratio
 * produces, selects a defined table rather than the nearest one. */
int rate_index(int sample_rate) {
  static const int kLowerBound[AAC_NUM_SAMPLE_RATES] = {92017, 75132, 55426, 46009, 37566, 27713,
                                                        23004, 18783, 13856, 11502, 9391,  0};
  for (int i = 0; i < AAC_NUM_SAMPLE_RATES; i++) {
    if (sample_rate >= kLowerBound[i]) {
      return i;
    }
  }
  return AAC_NUM_SAMPLE_RATES - 1;
}

/* TNS_MAX_BANDS for the USAC core (ISO/IEC 23003-3, the TNS tool): unlike
 * the AAC limits, every band at 24 kHz and below may carry TNS. */
const int kUsacTnsMaxBandsLong[AAC_NUM_SAMPLE_RATES] = {31, 31, 34, 40, 42, 51,
                                                       47, 47, 43, 43, 43, 40};
const int kUsacTnsMaxBandsShort[AAC_NUM_SAMPLE_RATES] = {9,  9,  10, 14, 14, 14,
                                                        15, 15, 15, 15, 15, 15};

void band_table_init(BandTable* t, int frame_length, int ri) {
  const int sl = frame_length / 8;
  cut_offsets(aac_sfb_offset(AAC_FRAME_SIZE_LONG, ri, 0), aac_num_sfb(AAC_FRAME_SIZE_LONG, ri, 0),
              frame_length, t->off_long, &t->num_long);
  cut_offsets(aac_sfb_offset(AAC_FRAME_SIZE_LONG, ri, 1), aac_num_sfb(AAC_FRAME_SIZE_LONG, ri, 1),
              sl, t->off_short, &t->num_short);
  t->tns_max_long = kUsacTnsMaxBandsLong[ri];
  t->tns_max_short = kUsacTnsMaxBandsShort[ri];
  if (t->tns_max_long > t->num_long) {
    t->tns_max_long = t->num_long;
  }
  if (t->tns_max_short > t->num_short) {
    t->tns_max_short = t->num_short;
  }
}

/* ics_info() and the layout it implies. */
struct Ics {
  int window_sequence; /* as signalled: AAC_WIN_* */
  int window_shape;
  int max_sfb;
  int grouping;
  int num_windows;
  int num_groups;
  int group_len[8];
  int num_swb;
  const int* swb_offset;
  int window_length; /* lines per window */
  int tns_max_bands;
};

struct Tns {
  int n_filt[8];
  int length[8][4];
  int order[8][4];
  int direction[8][4];
  float coef[8][4][kTnsMaxOrder];
};

struct Channel {
  int core_mode;
  int tns_present;
  int fac_present;
  int global_gain;
  int noise_level, noise_offset;
  Ics ics;
  int sf[kMaxBands];
  Tns tns;
  int quant[kMaxFrame];
  float spec[kMaxFrame];
};

struct Stereo {
  int common_window;
  int common_tns;
  int tns_on_lr;
  int ms_mask_present;
  int ms_used[kMaxBands];
  int pred_dir, complex_coef, use_prev_frame;
  float alpha_re[kMaxBands], alpha_im[kMaxBands];
};

}  // namespace

struct AacUsacFdChannel_ {
  int frame_length;
  int short_length;
  int rate_index;
  BandTable bands;
  AacUsacArithContext arith;
  AacMdct mdct_long, mdct_short;
  float win_long[2][kMaxFrame]; /* rising halves, sine and KBD */
  float win_short[2][kMaxShort];
  unsigned noise_seed;
  /* the previous frame, for complex prediction and concealment */
  int have_prev;
  float prev_spec[kMaxFrame]; /* the final spectrum of the last FD frame */
  int prev_alpha_re[kMaxBands], prev_alpha_im[kMaxBands];
  /* concealment: the last good spectrum and its layout */
  float last_spec[kMaxFrame];
  int last_window_sequence; /* effective sequence (AAC_WIN_* or STOP_START) */
  int last_window_shape;
  float fade;
};

namespace {

int effective_sequence(const AacUsacCoreChannel* ch, int signalled) {
  /* 7.9: the left slope is long only when the previous frame ended with a
   * long slope and this frame starts with one; a LONG_START after a short
   * slope is the STOP_START_SEQUENCE. */
  const int prev = ch->prev_window_sequence;
  const int prev_long_end =
      ch->prev_core_mode < 0 ||
      (ch->prev_core_mode == 0 && (prev == AAC_WIN_ONLY_LONG || prev == AAC_WIN_LONG_STOP));
  if (signalled == AAC_WIN_LONG_START && !prev_long_end) {
    return AAC_USAC_WIN_STOP_START;
  }
  if (signalled == AAC_WIN_ONLY_LONG && !prev_long_end) {
    return AAC_WIN_LONG_STOP;
  }
  return signalled;
}

int left_slope_short(int effective) {
  return effective == AAC_WIN_LONG_STOP || effective == AAC_USAC_WIN_STOP_START ||
         effective == AAC_WIN_EIGHT_SHORT;
}

int right_slope_short(int effective) {
  return effective == AAC_WIN_LONG_START || effective == AAC_USAC_WIN_STOP_START ||
         effective == AAC_WIN_EIGHT_SHORT;
}

/* ── syntax ────────────────────────────────────────────────────── */

int read_ics_info(AacBitReader* r, const AacUsacFdChannel* fd, int lfe, Ics* ics) {
  ics->window_sequence = (int)aac_bitreader_read(r, 2);
  ics->window_shape = (int)aac_bitreader_read(r, 1);
  const BandTable* t = &fd->bands;
  if (ics->window_sequence == AAC_WIN_EIGHT_SHORT) {
    ics->max_sfb = (int)aac_bitreader_read(r, 4);
    ics->grouping = (int)aac_bitreader_read(r, 7);
    ics->num_windows = 8;
    ics->num_groups = 1;
    ics->group_len[0] = 1;
    for (int w = 0; w < 7; w++) {
      if (ics->grouping & (1 << (6 - w))) {
        ics->group_len[ics->num_groups - 1]++;
      } else {
        ics->group_len[ics->num_groups++] = 1;
      }
    }
    ics->num_swb = t->num_short;
    ics->swb_offset = t->off_short;
    ics->window_length = fd->short_length;
    ics->tns_max_bands = t->tns_max_short;
  } else {
    ics->max_sfb = (int)aac_bitreader_read(r, 6);
    ics->grouping = 0;
    ics->num_windows = 1;
    ics->num_groups = 1;
    ics->group_len[0] = 1;
    ics->num_swb = t->num_long;
    ics->swb_offset = t->off_long;
    ics->window_length = fd->frame_length;
    ics->tns_max_bands = t->tns_max_long;
  }
  if (ics->max_sfb > ics->num_swb) {
    return AAC_ERR_DECODE;
  }
  if (lfe && ics->window_sequence != AAC_WIN_ONLY_LONG) {
    return AAC_ERR_DECODE;
  }
  return AAC_OK;
}

/* tns_data() of ISO/IEC 14496-3 Table 4.48, with the USAC order limits
 * (15 for a long window, 7 for a short one). */
int read_tns_data(AacBitReader* r, const Ics* ics, Tns* tns) {
  const int is8 = ics->num_windows == 8;
  const int max_order = is8 ? 7 : kTnsMaxOrder;
  memset(tns, 0, sizeof(*tns));
  for (int w = 0; w < ics->num_windows; w++) {
    tns->n_filt[w] = (int)aac_bitreader_read(r, is8 ? 1 : 2);
    if (!tns->n_filt[w]) {
      continue;
    }
    const int coef_res = (int)aac_bitreader_read(r, 1);
    for (int f = 0; f < tns->n_filt[w]; f++) {
      tns->length[w][f] = (int)aac_bitreader_read(r, is8 ? 4 : 6);
      const int order = (int)aac_bitreader_read(r, is8 ? 3 : 4);
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
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* scale_factor_data(): the first band's scale factor is global_gain
 * itself; every further band carries a delta coded scale factor. */
int read_scale_factors(AacBitReader* r, Channel* c) {
  const Ics* ics = &c->ics;
  int sf = c->global_gain;
  for (int g = 0; g < ics->num_groups; g++) {
    for (int b = 0; b < ics->max_sfb; b++) {
      const int diff = g == 0 && b == 0 ? 0 : aac_bitreader_read_scalefactor(r);
      if (diff == AAC_SF_INVALID) {
        return AAC_ERR_DECODE;
      }
      sf += diff;
      if (sf < 0 || sf > 255) {
        return AAC_ERR_DECODE;
      }
      c->sf[g * ics->max_sfb + b] = sf;
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* ac_spectral_data(): every window of the frame is coded separately, with
 * the context carried from one to the next. */
int read_spectral_data(AacBitReader* r, AacUsacFdChannel* fd, Channel* c, int indep) {
  const Ics* ics = &c->ics;
  const int reset = indep ? 1 : (int)aac_bitreader_read(r, 1);
  const int lg = ics->swb_offset[ics->max_sfb];
  const int n = ics->window_length;
  memset(c->quant, 0, sizeof(c->quant));
  for (int w = 0; w < ics->num_windows; w++) {
    const int ret = aac_usac_arith_decode(&fd->arith, reset && w == 0, n, lg, r, c->quant + w * n);
    if (ret != AAC_OK) {
      return ret;
    }
  }
  return AAC_OK;
}

/* cplx_pred_data() (6.2.x): the band pairs that use the prediction, its
 * direction, whether the imaginary part is sent and from where, then the
 * delta coded coefficients (the scale factor codebook) over frequency or
 * time. */
int read_cplx_pred_data(AacBitReader* r, const Ics* ics, int indep, AacUsacFdChannel* fd,
                        Stereo* st) {
  const int max_sfb = ics->max_sfb;
  const int all = (int)aac_bitreader_read(r, 1);
  int used[kMaxBands];
  memset(used, 0, sizeof(used));
  for (int g = 0; g < ics->num_groups; g++) {
    for (int b = 0; b < max_sfb; b += 2) {
      const int u = all ? 1 : (int)aac_bitreader_read(r, 1);
      used[g * max_sfb + b] = u;
      if (b + 1 < max_sfb) {
        used[g * max_sfb + b + 1] = u;
      }
    }
  }
  st->pred_dir = (int)aac_bitreader_read(r, 1);
  st->complex_coef = (int)aac_bitreader_read(r, 1);
  st->use_prev_frame = st->complex_coef && !indep ? (int)aac_bitreader_read(r, 1) : 0;
  const int delta_code_time = indep ? 0 : (int)aac_bitreader_read(r, 1);
  int alpha_re[kMaxBands], alpha_im[kMaxBands];
  memset(alpha_re, 0, sizeof(alpha_re));
  memset(alpha_im, 0, sizeof(alpha_im));
  for (int g = 0; g < ics->num_groups; g++) {
    for (int b = 0; b < max_sfb; b += 2) {
      const int idx = g * max_sfb + b;
      int re = 0, im = 0;
      if (used[idx]) {
        int ref_re = 0, ref_im = 0;
        if (delta_code_time) {
          ref_re = fd->prev_alpha_re[b];
          ref_im = fd->prev_alpha_im[b];
        } else if (b > 0) {
          ref_re = alpha_re[idx - 2];
          ref_im = alpha_im[idx - 2];
        }
        /* The codeword index i of the scale factor codebook stands for
         * the difference 60 - i here, the negative of its scale factor
         * meaning (verified against the reference decoder). */
        const int d_re = aac_bitreader_read_scalefactor(r);
        if (d_re == AAC_SF_INVALID) {
          return AAC_ERR_DECODE;
        }
        re = ref_re - d_re;
        if (st->complex_coef) {
          const int d_im = aac_bitreader_read_scalefactor(r);
          if (d_im == AAC_SF_INVALID) {
            return AAC_ERR_DECODE;
          }
          im = ref_im - d_im;
        }
      }
      alpha_re[idx] = re;
      alpha_im[idx] = im;
      if (b + 1 < max_sfb) {
        alpha_re[idx + 1] = re;
        alpha_im[idx + 1] = im;
      }
      fd->prev_alpha_re[b] = re;
      fd->prev_alpha_im[b] = im;
    }
  }
  for (int b = max_sfb; b < kMaxBands; b++) {
    fd->prev_alpha_re[b] = 0;
    fd->prev_alpha_im[b] = 0;
  }
  for (int i = 0; i < kMaxBands; i++) {
    st->ms_used[i] = used[i];
    st->alpha_re[i] = 0.1f * (float)alpha_re[i];
    st->alpha_im[i] = 0.1f * (float)alpha_im[i];
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* StereoCoreToolInfo() of a CPE whose channels are both FD. */
int read_stereo_info(AacBitReader* r, AacUsacFdChannel* fd0, Channel* c0, Channel* c1, int indep,
                     Stereo* st) {
  const int tns_active = (int)aac_bitreader_read(r, 1);
  st->common_window = (int)aac_bitreader_read(r, 1);
  if (st->common_window) {
    int ret = read_ics_info(r, fd0, 0, &c0->ics);
    if (ret != AAC_OK) {
      return ret;
    }
    c1->ics = c0->ics;
    const int common_max_sfb = (int)aac_bitreader_read(r, 1);
    if (!common_max_sfb) {
      c1->ics.max_sfb =
          (int)aac_bitreader_read(r, c0->ics.window_sequence == AAC_WIN_EIGHT_SHORT ? 4 : 6);
      if (c1->ics.max_sfb > c1->ics.num_swb) {
        return AAC_ERR_DECODE;
      }
    }
    st->ms_mask_present = (int)aac_bitreader_read(r, 2);
    if (st->ms_mask_present == 1) {
      for (int g = 0; g < c0->ics.num_groups; g++) {
        for (int b = 0; b < c0->ics.max_sfb; b++) {
          st->ms_used[g * c0->ics.max_sfb + b] = (int)aac_bitreader_read(r, 1);
        }
      }
    } else if (st->ms_mask_present == 2) {
      for (int i = 0; i < kMaxBands; i++) {
        st->ms_used[i] = 1;
      }
    } else if (st->ms_mask_present == 3) {
      ret = read_cplx_pred_data(r, &c0->ics, indep, fd0, st);
      if (ret != AAC_OK) {
        return ret;
      }
    }
  }
  if (st->ms_mask_present != 3) {
    memset(fd0->prev_alpha_re, 0, sizeof(fd0->prev_alpha_re));
    memset(fd0->prev_alpha_im, 0, sizeof(fd0->prev_alpha_im));
  }
  if (tns_active) {
    st->common_tns = st->common_window ? (int)aac_bitreader_read(r, 1) : 0;
    st->tns_on_lr = (int)aac_bitreader_read(r, 1);
    if (st->common_tns) {
      const int ret = read_tns_data(r, &c0->ics, &c0->tns);
      if (ret != AAC_OK) {
        return ret;
      }
      c1->tns = c0->tns;
      c0->tns_present = c1->tns_present = 1;
    } else {
      const int both = (int)aac_bitreader_read(r, 1);
      if (both) {
        c0->tns_present = c1->tns_present = 1;
      } else {
        c1->tns_present = (int)aac_bitreader_read(r, 1);
        c0->tns_present = 1 - c1->tns_present;
      }
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* fd_channel_stream() */
int read_fd_channel_stream(AacBitReader* r, AacUsacFdChannel* fd, AacUsacCoreChannel* ch,
                           Channel* c, int lfe, int noise_filling, int common_window,
                           int common_tns, int indep) {
  c->global_gain = (int)aac_bitreader_read(r, 8);
  c->noise_level = 0;
  c->noise_offset = 0;
  if (noise_filling) {
    c->noise_level = (int)aac_bitreader_read(r, 3);
    c->noise_offset = (int)aac_bitreader_read(r, 5);
  }
  int ret;
  if (!common_window && (ret = read_ics_info(r, fd, lfe, &c->ics)) != AAC_OK) {
    return ret;
  }
  if ((ret = read_scale_factors(r, c)) != AAC_OK) {
    return ret;
  }
  if (c->tns_present && !common_tns && (ret = read_tns_data(r, &c->ics, &c->tns)) != AAC_OK) {
    return ret;
  }
  if ((ret = read_spectral_data(r, fd, c, indep)) != AAC_OK) {
    return ret;
  }
  c->fac_present = (int)aac_bitreader_read(r, 1);
  if (c->fac_present) {
    const int fac_length = c->ics.window_sequence == AAC_WIN_EIGHT_SHORT ? fd->frame_length / 16
                                                                         : fd->frame_length / 8;
    if ((ret = aac_usac_lpd_fd_fac(ch, r, fac_length, fd->frame_length)) != AAC_OK) {
      return ret;
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* ── reconstruction ────────────────────────────────────────────── */

float dequant(int q) {
  const int a = q < 0 ? -q : q;
  const float v = a < AAC_POW43_SIZE ? aac_pow43[a] : powf((float)a, 4.0f / 3.0f);
  return q < 0 ? -v : v;
}

/* 7.2 and 7.3: x = sign(q) |q|^(4/3) 2^((sf - 100) / 4); the lines
 * quantised to zero above the noise filling start take, with random
 * sign, 2^((noise_level - 14) / 3) at the band's gain, raised by
 * noise_offset - 16 in a band whose every line is zero. */
void dequantize(AacUsacFdChannel* fd, Channel* c, int noise_filling) {
  const Ics* ics = &c->ics;
  const int* off = ics->swb_offset;
  const int n = ics->window_length;
  const int nf_start = (ics->num_windows == 8 ? 20 : 160) * fd->frame_length / AAC_FRAME_SIZE_LONG;
  const int nf_on = noise_filling && c->noise_level > 0;
  const float noise_val = nf_on ? exp2f((float)(c->noise_level - 14) / 3.0f) : 0.0f;
  memset(c->spec, 0, sizeof(c->spec));
  int win = 0;
  for (int g = 0; g < ics->num_groups; g++) {
    for (int b = 0; b < ics->max_sfb; b++) {
      const int idx = g * ics->max_sfb + b;
      const int len = off[b + 1] - off[b];
      int all_zero = 1;
      for (int w = 0; w < ics->group_len[g] && all_zero; w++) {
        const int* q = c->quant + (win + w) * n + off[b];
        for (int k = 0; k < len; k++) {
          if (q[k]) {
            all_zero = 0;
            break;
          }
        }
      }
      const int fill = nf_on && off[b] >= nf_start;
      int sf = c->sf[idx];
      if (fill && all_zero) {
        sf += c->noise_offset - 16;
      }
      const float gain = exp2f(0.25f * (float)(sf - 100));
      for (int w = 0; w < ics->group_len[g]; w++) {
        const int base = (win + w) * n + off[b];
        const int* q = c->quant + base;
        float* dst = c->spec + base;
        for (int k = 0; k < len; k++) {
          if (q[k]) {
            dst[k] = dequant(q[k]) * gain;
          } else if (fill) {
            fd->noise_seed = fd->noise_seed * 1664525u + 1013904223u;
            dst[k] = (fd->noise_seed & 0x10000u) ? -noise_val * gain : noise_val * gain;
          }
        }
      }
    }
    win += ics->group_len[g];
  }
}

/* TNS synthesis (ISO/IEC 14496-3 4.6.9.3) on the reconstructed spectrum. */
void apply_tns(const Channel* c, float* spec) {
  const Ics* ics = &c->ics;
  const Tns* tns = &c->tns;
  const int mmm = ics->tns_max_bands < ics->max_sfb ? ics->tns_max_bands : ics->max_sfb;
  if (!c->tns_present || mmm == 0) {
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
      float lpc[kTnsMaxOrder + 1];
      lpc[0] = 1.0f;
      for (int m = 1; m <= order; m++) {
        const float k = tns->coef[w][f][m - 1];
        float tmp[kTnsMaxOrder + 1];
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
      float* x = spec + w * ics->window_length;
      for (int m = 0, pos = start; m < size; m++, pos += inc) {
        const int lim = m < order ? m : order;
        for (int i = 1; i <= lim; i++) {
          x[pos] -= x[pos - i * inc] * lpc[i];
        }
      }
    }
  }
}

void apply_ms(const Ics* ics, const int* ms_used, float* l, float* r) {
  const int* off = ics->swb_offset;
  int win = 0;
  for (int g = 0; g < ics->num_groups; g++) {
    for (int b = 0; b < ics->max_sfb; b++) {
      if (!ms_used[g * ics->max_sfb + b]) {
        continue;
      }
      for (int w = 0; w < ics->group_len[g]; w++) {
        float* a = l + (win + w) * ics->window_length + off[b];
        float* c = r + (win + w) * ics->window_length + off[b];
        for (int k = 0; k < off[b + 1] - off[b]; k++) {
          const float m = a[k];
          const float s = c[k];
          a[k] = m + s;
          c[k] = m - s;
        }
      }
    }
    win += ics->group_len[g];
  }
}

/* 7.7.2: the downmix of every window is channel 0 where the prediction
 * is used and the mid (or side) of both channels elsewhere; its MDST is
 * estimated from this window and, with use_prev_frame, from the previous
 * window (the previous frame's last window for the first one). */
void apply_cplx_pred(const AacUsacFdChannel* fd0, const AacUsacFdChannel* fd1,
                     const AacUsacCoreChannel* ch0, int effective_sequence, const Channel* c0,
                     const Stereo* st, float* l, float* r) {
  const Ics* ics = &c0->ics;
  const int* off = ics->swb_offset;
  const int n = ics->window_length;
  const float dir = st->pred_dir ? -1.0f : 1.0f;
  float dmx_re[kMaxFrame], dmx_im[kMaxFrame], prev_dmx[kMaxFrame];
  const float* prev = nullptr;
  if (st->complex_coef && st->use_prev_frame && fd0->have_prev) {
    /* the previous frame's last window, as this frame's windows see it */
    const int offs = fd0->frame_length - n;
    for (int k = 0; k < n; k++) {
      prev_dmx[k] = 0.5f * (fd0->prev_spec[offs + k] + dir * fd1->prev_spec[offs + k]);
    }
    prev = prev_dmx;
  }
  int win = 0;
  for (int g = 0; g < ics->num_groups; g++) {
    for (int w = 0; w < ics->group_len[g]; w++) {
      float* lw = l + (win + w) * n;
      float* rw = r + (win + w) * n;
      for (int b = 0; b < ics->max_sfb; b++) {
        const int used = st->ms_used[g * ics->max_sfb + b];
        for (int k = off[b]; k < off[b + 1]; k++) {
          dmx_re[k] = used ? lw[k] : 0.5f * (lw[k] + dir * rw[k]);
        }
      }
      for (int k = off[ics->max_sfb]; k < n; k++) {
        dmx_re[k] = 0.5f * (lw[k] + dir * rw[k]);
      }
      if (st->complex_coef) {
        const int prev_shape = ch0->prev_core_mode == 1 ? AAC_WIN_SINE : ch0->prev_window_shape;
        aac_usac_fd_mdst_estimate(dmx_re, prev, n, effective_sequence, prev_shape,
                                  ics->window_shape, dmx_im);
      } else {
        memset(dmx_im, 0, (size_t)n * sizeof(float));
      }
      for (int b = 0; b < ics->max_sfb; b++) {
        const int idx = g * ics->max_sfb + b;
        if (!st->ms_used[idx]) {
          continue;
        }
        aac_usac_fd_cplx_predict(lw + off[b], rw + off[b], dmx_re + off[b], dmx_im + off[b],
                                 off[b + 1] - off[b], st->alpha_re[idx], st->alpha_im[idx],
                                 st->pred_dir);
      }
      /* the next short window's previous window is this one */
      if (st->complex_coef && st->use_prev_frame) {
        memcpy(prev_dmx, dmx_re, (size_t)n * sizeof(float));
        prev = prev_dmx;
      }
    }
    win += ics->group_len[g];
  }
}

/* ── filterbank (7.9) ──────────────────────────────────────────── */

/* The windowed IMDCT of one frame into buf[0..2 fl), with the slopes the
 * effective window sequence and the shapes imply; `left_shape` is the
 * previous frame's. */
void imdct_frame(const AacUsacFdChannel* fd, const float* spec, int effective, int left_shape,
                 int shape, float* buf) {
  const int fl = fd->frame_length;
  const int sl = fd->short_length;
  const int start = (fl - sl) / 2;
  const float* lw_left = fd->win_long[left_shape];
  const float* lw_right = fd->win_long[shape];
  const float* sw_left = fd->win_short[left_shape];
  const float* sw_right = fd->win_short[shape];

  if (effective != AAC_WIN_EIGHT_SHORT) {
    aac_imdct(&fd->mdct_long, spec, buf);
    if (left_slope_short(effective)) {
      memset(buf, 0, (size_t)start * sizeof(float));
      for (int i = 0; i < sl; i++) {
        buf[start + i] *= sw_left[i];
      }
    } else {
      for (int i = 0; i < fl; i++) {
        buf[i] *= lw_left[i];
      }
    }
    if (right_slope_short(effective)) {
      for (int i = 0; i < sl; i++) {
        buf[fl + start + i] *= sw_right[sl - 1 - i];
      }
      memset(buf + fl + start + sl, 0, (size_t)start * sizeof(float));
    } else {
      for (int i = 0; i < fl; i++) {
        buf[fl + i] *= lw_right[fl - 1 - i];
      }
    }
    return;
  }
  memset(buf, 0, (size_t)(2 * fl) * sizeof(float));
  float sb[2 * kMaxShort];
  for (int w = 0; w < 8; w++) {
    aac_imdct(&fd->mdct_short, spec + w * sl, sb);
    const float* left = w == 0 ? sw_left : sw_right;
    float* dst = buf + start + w * sl;
    for (int i = 0; i < sl; i++) {
      dst[i] += sb[i] * left[i];
      dst[sl + i] += sb[sl + i] * sw_right[sl - 1 - i];
    }
  }
}

/* Overlap-adds one frame and leaves the second half for the next, with
 * the core channel state the contract in usac.h describes. */
void synthesize(AacUsacFdChannel* fd, AacUsacCoreChannel* ch, const float* spec, int effective,
                int shape, float* out) {
  const int fl = fd->frame_length;
  const int left_shape = ch->prev_core_mode == 1 ? AAC_WIN_SINE : ch->prev_window_shape;
  float buf[2 * kMaxFrame];
  imdct_frame(fd, spec, effective, left_shape, shape, buf);
  for (int i = 0; i < fl; i++) {
    out[i] = (ch->overlap[i] + buf[i]) * kOutputScale;
  }
  memcpy(ch->overlap, buf + fl, (size_t)fl * sizeof(float));
  ch->prev_core_mode = 0;
  ch->prev_window_sequence = effective;
  ch->prev_window_shape = shape;
}

void remember(AacUsacFdChannel* fd, const float* spec, int effective, int shape) {
  memcpy(fd->last_spec, spec, (size_t)fd->frame_length * sizeof(float));
  fd->last_window_sequence = effective;
  fd->last_window_shape = shape;
  fd->fade = 1.0f;
}

}  // namespace

/* ── the module interface ──────────────────────────────────────── */

AacUsacFdChannel* aac_usac_fd_channel_create(int frame_length, int core_sample_rate) {
  if (frame_length != 1024 && frame_length != 768) {
    return nullptr;
  }
  aac_tables_init();
  AacUsacFdChannel* fd = (AacUsacFdChannel*)calloc(1, sizeof(AacUsacFdChannel));
  if (!fd) {
    return nullptr;
  }
  fd->frame_length = frame_length;
  fd->short_length = frame_length / 8;
  /* A 768-line frame uses the 1024-line tables of the rate at which a
   * 1024-line frame has the same line spacing: four thirds of the core
   * rate (the output rate at the 8:3 SBR ratio divided by two). */
  fd->rate_index = rate_index(frame_length == 768 ? core_sample_rate * 4 / 3 : core_sample_rate);
  band_table_init(&fd->bands, frame_length, fd->rate_index);
  aac_mdct_init(&fd->mdct_long, 2 * frame_length);
  aac_mdct_init(&fd->mdct_short, 2 * fd->short_length);
  float full[2 * kMaxFrame];
  aac_sine_window(full, 2 * frame_length);
  memcpy(fd->win_long[AAC_WIN_SINE], full, (size_t)frame_length * sizeof(float));
  aac_sine_window(full, 2 * fd->short_length);
  memcpy(fd->win_short[AAC_WIN_SINE], full, (size_t)fd->short_length * sizeof(float));
  aac_kbd_window(full, 2 * frame_length, AAC_KBD_ALPHA_LONG);
  memcpy(fd->win_long[AAC_WIN_KBD], full, (size_t)frame_length * sizeof(float));
  aac_kbd_window(full, 2 * fd->short_length, AAC_KBD_ALPHA_SHORT);
  memcpy(fd->win_short[AAC_WIN_KBD], full, (size_t)fd->short_length * sizeof(float));
  aac_usac_fd_channel_reset(fd);
  return fd;
}

void aac_usac_fd_channel_destroy(AacUsacFdChannel* c) { free(c); }

void aac_usac_fd_channel_reset(AacUsacFdChannel* c) {
  if (!c) {
    return;
  }
  aac_usac_arith_reset(&c->arith);
  c->noise_seed = 0x2545F491u;
  c->have_prev = 0;
  memset(c->prev_spec, 0, sizeof(c->prev_spec));
  memset(c->prev_alpha_re, 0, sizeof(c->prev_alpha_re));
  memset(c->prev_alpha_im, 0, sizeof(c->prev_alpha_im));
  memset(c->last_spec, 0, sizeof(c->last_spec));
  c->last_window_sequence = AAC_WIN_ONLY_LONG;
  c->last_window_shape = AAC_WIN_SINE;
  c->fade = 0.0f;
}

int aac_usac_core_decode(AacUsacCoreChannel* const* ch, int nr_channels, int lfe, int noise_filling,
                         int frame_length, int indep, AacBitReader* r, float* const* out) {
  if (nr_channels < 1 || nr_channels > 2 || (lfe && nr_channels != 1)) {
    return AAC_ERR_INVALID_ARG;
  }
  for (int i = 0; i < nr_channels; i++) {
    if (!ch[i] || !ch[i]->fd || ch[i]->fd->frame_length != frame_length) {
      return AAC_ERR_INVALID_ARG;
    }
  }
  Channel c[2];
  Stereo st;
  memset(c, 0, sizeof(c));
  memset(&st, 0, sizeof(st));
  if (lfe) {
    noise_filling = 0;
  } else {
    for (int i = 0; i < nr_channels; i++) {
      c[i].core_mode = (int)aac_bitreader_read(r, 1);
    }
  }
  const int both_fd = nr_channels == 2 && !c[0].core_mode && !c[1].core_mode;
  int ret;
  if (both_fd && (ret = read_stereo_info(r, ch[0]->fd, &c[0], &c[1], indep, &st)) != AAC_OK) {
    return ret;
  }
  for (int i = 0; i < nr_channels; i++) {
    if (c[i].core_mode) {
      if ((ret = aac_usac_lpd_decode(ch[i], frame_length, indep, r, out[i])) != AAC_OK) {
        return ret;
      }
      /* the FD memories of an LPD channel start afresh at the next FD frame */
      ch[i]->fd->have_prev = 0;
      continue;
    }
    if (!both_fd && !lfe) {
      c[i].tns_present = (int)aac_bitreader_read(r, 1);
    }
    if ((ret = read_fd_channel_stream(r, ch[i]->fd, ch[i], &c[i], lfe, noise_filling,
                                      both_fd && st.common_window, both_fd && st.common_tns,
                                      indep)) != AAC_OK) {
      return ret;
    }
    dequantize(ch[i]->fd, &c[i], noise_filling);
  }
  /* the stereo tools, with TNS before them (tns_on_lr 0) or after */
  if (both_fd && !st.tns_on_lr) {
    apply_tns(&c[0], c[0].spec);
    apply_tns(&c[1], c[1].spec);
  }
  if (both_fd && st.common_window && st.ms_mask_present) {
    const int effective = effective_sequence(ch[0], c[0].ics.window_sequence);
    if (st.ms_mask_present == 3) {
      apply_cplx_pred(ch[0]->fd, ch[1]->fd, ch[0], effective, &c[0], &st, c[0].spec, c[1].spec);
    } else {
      apply_ms(&c[0].ics, st.ms_used, c[0].spec, c[1].spec);
    }
  }
  for (int i = 0; i < nr_channels; i++) {
    if (c[i].core_mode) {
      continue;
    }
    AacUsacFdChannel* fd = ch[i]->fd;
    if (!both_fd || st.tns_on_lr) {
      apply_tns(&c[i], c[i].spec);
    }
    memcpy(fd->prev_spec, c[i].spec, (size_t)frame_length * sizeof(float));
    fd->have_prev = 1;
    const int effective = effective_sequence(ch[i], c[i].ics.window_sequence);
    remember(fd, c[i].spec, effective, c[i].ics.window_shape);
    synthesize(fd, ch[i], c[i].spec, effective, c[i].ics.window_shape, out[i]);
  }
  return AAC_OK;
}

void aac_usac_core_conceal(AacUsacCoreChannel* const* ch, int nr_channels, int frame_length,
                           float* const* out) {
  for (int i = 0; i < nr_channels; i++) {
    AacUsacFdChannel* fd = ch[i]->fd;
    if (!fd || fd->frame_length != frame_length) {
      continue;
    }
    float spec[kMaxFrame];
    /* The left slope follows the previous frame; the right one is long,
     * unless the repeated spectrum is one of short windows. */
    int effective = effective_sequence(ch[i], AAC_WIN_ONLY_LONG);
    if (ch[i]->prev_core_mode != 0 || fd->fade <= 0.0f) {
      /* nothing to repeat: let the overlap ring out */
      memset(spec, 0, sizeof(spec));
    } else {
      fd->fade *= kConcealFade;
      for (int k = 0; k < frame_length; k++) {
        spec[k] = fd->last_spec[k] * fd->fade;
      }
      if (fd->last_window_sequence == AAC_WIN_EIGHT_SHORT) {
        effective = AAC_WIN_EIGHT_SHORT;
      }
    }
    fd->have_prev = 0;
    synthesize(fd, ch[i], spec, effective, fd->last_window_shape, out[i]);
  }
}
