/* The USAC frame writer (ISO/IEC 23003-3 6.1 and 6.2): UsacFrame() with
 * the frequency-domain core over the AAC-LC encoder's analysis and
 * quantisation.  The AAC-LC encoder chooses the window sequence, the
 * TNS filters, the M/S bands, the scale factors and the quantised
 * spectrum within its rate loop; this module replaces the codebook
 * sectioning and the Huffman coding by the USAC scale factor rule (a
 * delta coded scale factor for every band below max_sfb) and the
 * context based arithmetic coder, adds the noise filling parameters,
 * and writes the element syntax: UsacSingleChannelElement,
 * UsacChannelPairElement with StereoCoreToolInfo, and the audio
 * pre-roll extension element of the immediate playout frames. */
#include "usac_enc.h"

#include <algorithm>
#include <cmath>
#include <cstdlib>
#include <cstring>

#include "encoder.h"

namespace {

constexpr int kShort = AAC_FRAME_SIZE_SHORT;
constexpr int kLong = AAC_FRAME_SIZE_LONG;
/* The transport's bytes per frame as the rate loop budgets them: the
 * AAC-LC path counts an ADTS header, and the LOAS header with the mux
 * element's fields is of the same order. */
constexpr int kTransportBits = 8 * AAC_ADTS_HEADER_SIZE;
constexpr int kMaxSfDelta = 60;
constexpr int kMaxSf = 255;
constexpr int kNeutralNoiseOffset = 16;
constexpr int kMaxNoiseLevel = 7;
/* The noise level when no coded band above the start has a line
 * quantised to zero yet whole bands wait for noise: a fifth of a
 * quantiser step. */
constexpr int kDefaultNoiseLevel = 7;
/* The range decoder reads 16 bits ahead and hands 14 back, so its reader
 * reaches 14 bits past the coded spectrum.  A frame whose last spectrum
 * ends within a few bits of its end carries a fill element (Table 22), so
 * that a decoder which clamps that look-ahead at the frame's end (FFmpeg
 * allows 8 bits past it) keeps its position: at least this many bits must
 * follow the last flush before the fill element's own bits. */
constexpr int kMinTailBits = 5;
constexpr int kFillByte = 0xA5;
/* No channel codes fewer bands than this: lg stays above zero, which
 * decoders treat alike only for a coded range. */
constexpr int kMinMaxSfb = 1;

/* TNS_MAX_BANDS of the USAC core (ISO/IEC 23003-3, the TNS tool); the
 * decoder's table, repeated here. */
const int kUsacTnsMaxBandsLong[AAC_NUM_SAMPLE_RATES] = {31, 31, 34, 40, 42, 51,
                                                       47, 47, 43, 43, 43, 40};
const int kUsacTnsMaxBandsShort[AAC_NUM_SAMPLE_RATES] = {9,  9,  10, 14, 14, 14,
                                                        15, 15, 15, 15, 15, 15};

/* The first line of the noise filling range (7.2): 160 of a long window,
 * 20 of a short one. */
int noise_fill_start(int is_short) { return is_short ? 20 : 160; }

float sf_gain(int sf) { return exp2f(0.25f * (float)(sf - 100)); }

/* ── bit copying ───────────────────────────────────────────────── */

void copy_bits(AacBitWriter* w, const uint8_t* src, int nbits) {
  int i = 0;
  for (; i + 8 <= nbits; i += 8) {
    aac_bitwriter_write(w, src[i >> 3], 8);
  }
  if (i < nbits) {
    const int rest = nbits - i;
    aac_bitwriter_write(w, (uint32_t)(src[i >> 3] >> (8 - rest)), rest);
  }
}

void write_bytes(AacBitWriter* w, const uint8_t* data, int len) {
  for (int i = 0; i < len; i++) {
    aac_bitwriter_write(w, data[i], 8);
  }
}

/* ── ics_info() and tns_data() ─────────────────────────────────── */

/* The window_sequence field: the stop-start window travels as
 * LONG_START, which the decoder turns back into it from the previous
 * frame (7.9). */
int signalled_sequence(const AacIcsInfo* ics) {
  return ics->window_sequence == AAC_ENC_WIN_STOP_START ? AAC_WIN_LONG_START
                                                        : ics->window_sequence;
}

int ics_info_bits(const AacIcsInfo* ics) { return 3 + (ics->num_windows == 8 ? 11 : 6); }

void write_ics_info(AacBitWriter* w, const AacIcsInfo* ics, int max_sfb) {
  aac_bitwriter_write(w, (uint32_t)signalled_sequence(ics), 2);
  aac_bitwriter_write(w, (uint32_t)ics->window_shape, 1);
  if (ics->num_windows == 8) {
    aac_bitwriter_write(w, (uint32_t)max_sfb, 4);
    int grouping = 0;
    int win = 0;
    for (int g = 0; g < ics->num_window_groups; g++) {
      for (int i = 1; i < ics->group_len[g]; i++) {
        grouping |= 1 << (6 - (win + i - 1));
      }
      win += ics->group_len[g];
    }
    aac_bitwriter_write(w, (uint32_t)grouping, 7);
  } else {
    aac_bitwriter_write(w, (uint32_t)max_sfb, 6);
  }
}

/* tns_data() as the USAC core reads it: the order of a long window has
 * four bits (up to 15), of a short one three. */
int tns_bits(const AacEncChannel* c) {
  if (!c->tns_present) {
    return 0;
  }
  const int is8 = c->ics.num_windows == 8;
  const int windows = is8 ? 8 : 1;
  const int coef_bits = c->tns_coef_res + 3;
  int bits = 0;
  for (int w = 0; w < windows; w++) {
    bits += is8 ? 1 : 2;
    if (!c->tns_n_filt[w]) {
      continue;
    }
    bits += 1;
    for (int f = 0; f < c->tns_n_filt[w]; f++) {
      bits += (is8 ? 4 : 6) + (is8 ? 3 : 4);
      if (c->tns_order[w][f]) {
        bits += 2 + c->tns_order[w][f] * coef_bits;
      }
    }
  }
  return bits;
}

void write_tns(AacBitWriter* w, const AacEncChannel* c) {
  const int is8 = c->ics.num_windows == 8;
  const int windows = is8 ? 8 : 1;
  const int coef_bits = c->tns_coef_res + 3;
  for (int win = 0; win < windows; win++) {
    aac_bitwriter_write(w, (uint32_t)c->tns_n_filt[win], is8 ? 1 : 2);
    if (!c->tns_n_filt[win]) {
      continue;
    }
    aac_bitwriter_write(w, (uint32_t)c->tns_coef_res, 1);
    for (int f = 0; f < c->tns_n_filt[win]; f++) {
      aac_bitwriter_write(w, (uint32_t)c->tns_length[win][f], is8 ? 4 : 6);
      aac_bitwriter_write(w, (uint32_t)c->tns_order[win][f], is8 ? 3 : 4);
      if (!c->tns_order[win][f]) {
        continue;
      }
      aac_bitwriter_write(w, 0, 1); /* direction: upward */
      aac_bitwriter_write(w, 0, 1); /* coef_compress */
      for (int i = 0; i < c->tns_order[win][f]; i++) {
        aac_bitwriter_write(w, (uint32_t)(c->tns_coef[win][f][i] & ((1 << coef_bits) - 1)),
                            coef_bits);
      }
    }
  }
}

/* ── scale factors and noise filling ───────────────────────────── */

/* ms_mask_present of a pair: 0 without M/S bands below max_sfb, 2 when
 * every band uses it, 1 with the mask. */
int ms_mode(const AacEncElement* el, const AacIcsInfo* ics, int max_sfb) {
  const int nbands = ics->num_window_groups * max_sfb;
  int used = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < max_sfb; b++) {
      used += el->ms_used[g * ics->num_swb + b] ? 1 : 0;
    }
  }
  return used == 0 ? 0 : (used == nbands ? 2 : 1);
}

/* The scale factor of every band below max_sfb and the noise filling
 * parameters of a channel (7.2, 7.3).  Coded bands keep the scale
 * factor of the quantisation.  A band quantised to nothing above the
 * noise filling start takes the scale factor at which the noise the
 * decoder inserts has the band's energy; the noise level is the mean
 * level, in quantiser steps, of the lines quantised to zero inside the
 * coded bands above the start, and the noise offset centres the zero
 * bands' scale factors on their neighbours.  Every other empty band
 * repeats the previous scale factor.  The chain of differences is kept
 * within the codebook's range of 60 in both directions. */
void assign_scale_factors(const AacEncChannel* c, AacUsacEncChannel* uc, int max_sfb,
                          int noise_filling) {
  const AacIcsInfo* ics = &c->ics;
  const int nswb = ics->num_swb;
  const int* off = ics->swb_offset;
  const int is_short = ics->num_windows == 8;
  const int start_line = noise_fill_start(is_short);
  const int ngroups = ics->num_window_groups;

  /* the level of the zeroed lines inside the coded bands */
  double zero_sum = 0.0;
  int zero_count = 0;
  int empty_bands = 0;
  int w0 = 0;
  for (int g = 0; g < ngroups; g++) {
    for (int b = 0; b < max_sfb; b++) {
      const int idx = g * nswb + b;
      if (off[b] < start_line) {
        continue;
      }
      if (c->cb[idx] == 0) {
        empty_bands++;
        continue;
      }
      const float inv_gain = 1.0f / sf_gain(c->sf[idx]);
      for (int w = w0; w < w0 + ics->group_len[g]; w++) {
        for (int i = w * kShort + off[b]; i < w * kShort + off[b + 1]; i++) {
          if (c->quant[i] == 0) {
            const float v = fabsf(c->coef[i]) * inv_gain;
            zero_sum += (double)v * v;
            zero_count++;
          }
        }
      }
    }
    w0 += ics->group_len[g];
  }
  int noise_level = 0;
  if (noise_filling) {
    if (zero_count > 0) {
      const double rms = sqrt(zero_sum / (double)zero_count);
      const double level = rms > 0.0 ? 14.0 + 3.0 * log2(rms) : 0.0;
      noise_level = std::clamp((int)lround(level), 0, kMaxNoiseLevel);
    }
    if (noise_level == 0 && empty_bands > 0) {
      noise_level = zero_count > 0 ? 1 : kDefaultNoiseLevel;
    }
  }
  const float noise_val = noise_level > 0 ? exp2f((float)(noise_level - 14) / 3.0f) : 0.0f;

  /* the scale factor each empty band above the start wants */
  int target[AAC_MAX_BANDS];
  bool fill[AAC_MAX_BANDS];
  double offset_sum = 0.0;
  int offset_count = 0;
  int ref = -1; /* the last coded band's scale factor */
  w0 = 0;
  for (int g = 0; g < ngroups; g++) {
    for (int b = 0; b < max_sfb; b++) {
      const int idx = g * nswb + b;
      fill[idx] = false;
      target[idx] = 0;
      if (c->cb[idx] != 0) {
        ref = c->sf[idx];
        continue;
      }
      if (noise_level == 0 || off[b] < start_line) {
        continue;
      }
      double energy = 0.0;
      for (int w = w0; w < w0 + ics->group_len[g]; w++) {
        for (int i = w * kShort + off[b]; i < w * kShort + off[b + 1]; i++) {
          energy += (double)c->coef[i] * c->coef[i];
        }
      }
      const double lines = (double)ics->group_len[g] * (off[b + 1] - off[b]);
      const double rms = sqrt(energy / lines);
      fill[idx] = true;
      target[idx] = rms > 0.0
                        ? std::clamp((int)lround(100.0 + 4.0 * log2(rms / (double)noise_val)),
                                     0, kMaxSf)
                        : 0;
      if (ref >= 0) {
        offset_sum += target[idx] - ref;
        offset_count++;
      }
    }
    w0 += ics->group_len[g];
  }
  int noise_offset = kNeutralNoiseOffset;
  if (offset_count > 0) {
    noise_offset = std::clamp(kNeutralNoiseOffset + (int)lround(offset_sum / offset_count), 0, 31);
  }

  /* the chain: the next coded band bounds every empty band before it */
  const int nbands = ngroups * max_sfb;
  int next_coded[AAC_MAX_BANDS];
  int next = -1;
  for (int g = ngroups - 1; g >= 0; g--) {
    for (int b = max_sfb - 1; b >= 0; b--) {
      const int idx = g * nswb + b;
      if (c->cb[idx] != 0) {
        next = c->sf[idx];
      }
      next_coded[idx] = next;
    }
  }
  int prev = -1;
  for (int g = 0; g < ngroups; g++) {
    for (int b = 0; b < max_sfb; b++) {
      const int idx = g * nswb + b;
      int v;
      if (c->cb[idx] != 0) {
        v = c->sf[idx];
      } else {
        if (fill[idx]) {
          v = target[idx] - (noise_offset - kNeutralNoiseOffset);
        } else if (prev >= 0) {
          v = prev;
        } else {
          v = next_coded[idx] >= 0 ? next_coded[idx] : 100;
        }
        int lo = 0;
        int hi = kMaxSf;
        if (prev >= 0) {
          lo = std::max(lo, prev - kMaxSfDelta);
          hi = std::min(hi, prev + kMaxSfDelta);
        }
        if (next_coded[idx] >= 0) {
          lo = std::max(lo, next_coded[idx] - kMaxSfDelta);
          hi = std::min(hi, next_coded[idx] + kMaxSfDelta);
        }
        v = std::clamp(v, lo, hi);
      }
      uc->sf[idx] = v;
      prev = v;
    }
  }
  uc->global_gain = nbands > 0 ? uc->sf[0] : 0;
  uc->noise_level = noise_filling ? noise_level : 0;
  uc->noise_offset = noise_filling ? noise_offset : 0;
}

int scale_factor_bits(const AacEncChannel* c, const AacUsacEncChannel* uc, int max_sfb) {
  const AacIcsInfo* ics = &c->ics;
  int bits = 0;
  int prev = uc->global_gain;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < max_sfb; b++) {
      if (g == 0 && b == 0) {
        continue;
      }
      const int sf = uc->sf[g * ics->num_swb + b];
      bits += aac_scalefactor_bits[sf - prev + AAC_SCALE_DIFF_ZERO];
      prev = sf;
    }
  }
  return bits;
}

void write_scale_factors(AacBitWriter* w, const AacEncChannel* c, const AacUsacEncChannel* uc,
                         int max_sfb) {
  const AacIcsInfo* ics = &c->ics;
  int prev = uc->global_gain;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < max_sfb; b++) {
      if (g == 0 && b == 0) {
        continue;
      }
      const int sf = uc->sf[g * ics->num_swb + b];
      aac_bitwriter_write_scalefactor(w, sf - prev);
      prev = sf;
    }
  }
}

/* ac_spectral_data(): every window of the frame coded in turn with the
 * context carried from one to the next; with `w` null the bits are
 * counted on a copy of the context. */
int spectral_data(AacUsacArithContext* ctx, const AacEncChannel* c, int max_sfb, int indep,
                  AacBitWriter* w, int* last_signs) {
  const AacIcsInfo* ics = &c->ics;
  const int n = ics->num_windows == 8 ? kShort : kLong;
  const int lg = ics->swb_offset[max_sfb];
  if (last_signs) {
    const int* last = c->quant + (ics->num_windows - 1) * kShort;
    int signs = 0;
    for (int k = 0; k < lg; k++) {
      signs += last[k] != 0;
    }
    *last_signs = signs;
  }
  int bits = 0;
  if (!indep) {
    if (w) {
      aac_bitwriter_write(w, 0, 1); /* arith_reset_flag */
    }
    bits++;
  }
  for (int win = 0; win < ics->num_windows; win++) {
    const int ret = aac_usac_arith_encode(ctx, indep && win == 0, n, lg, c->quant + win * kShort, w);
    if (ret < 0) {
      return ret;
    }
    bits += ret;
  }
  return bits;
}

/* ── the element syntax ────────────────────────────────────────── */

/* fd_channel_stream() (Table 20). */
int write_fd_channel_stream(AacBitWriter* w, const AacEncChannel* c, AacUsacEncChannel* uc,
                            int max_sfb, int common_window, int common_tns, int noise_filling,
                            int indep, int* tail_bits) {
  aac_bitwriter_write(w, (uint32_t)uc->global_gain, 8);
  if (noise_filling) {
    aac_bitwriter_write(w, (uint32_t)uc->noise_level, 3);
    aac_bitwriter_write(w, (uint32_t)uc->noise_offset, 5);
  }
  if (!common_window) {
    write_ics_info(w, &c->ics, max_sfb);
  }
  write_scale_factors(w, c, uc, max_sfb);
  if (c->tns_present && !common_tns) {
    write_tns(w, c);
  }
  int signs = 0;
  const int ret = spectral_data(&uc->arith, c, max_sfb, indep, w, &signs);
  if (ret < 0) {
    return ret;
  }
  aac_bitwriter_write(w, 0, 1); /* fac_data_present */
  *tail_bits = signs + 1;
  return AAC_OK;
}

/* The fill element (usacExtElementType ID_EXT_ELE_FILL) that closes the
 * element stream: absent when enough bits follow the last range coder
 * flush, otherwise one fill byte. */
void write_fill_element(AacBitWriter* w, int tail_bits) {
  if (tail_bits >= kMinTailBits) {
    aac_bitwriter_write(w, 0, 1); /* usacExtElementPresent */
    return;
  }
  aac_bitwriter_write(w, 1, 1); /* usacExtElementPresent */
  aac_bitwriter_write(w, 0, 1); /* usacExtElementUseDefaultLength */
  aac_bitwriter_write(w, 1, 8); /* usacExtElementPayloadLength */
  aac_bitwriter_write(w, kFillByte, 8);
}

int fill_element_bits(int tail_bits) { return tail_bits >= kMinTailBits ? 1 : 18; }

/* UsacSingleChannelElement() and UsacChannelPairElement() (Tables 17
 * and 18) with both channels in the frequency domain. */
int write_element(AacEncoderState* s, const AacEncElement* el, AacBitWriter* w) {
  AacUsacEncoder* u = s->usac;
  const int nf = u->opt.noise_filling;
  const int indep = u->indep;
  const int max_sfb = el->max_sfb;
  if (el->num_ch == 1) {
    const AacEncChannel* c = &s->ch[el->ch[0]];
    AacUsacEncChannel* uc = &u->ch[el->ch[0]];
    aac_bitwriter_write(w, 0, 1); /* core_mode */
    aac_bitwriter_write(w, c->tns_present ? 1u : 0u, 1);
    return write_fd_channel_stream(w, c, uc, max_sfb, 0, 0, nf, indep, &u->tail_bits);
  }
  const AacEncChannel* c0 = &s->ch[el->ch[0]];
  const AacEncChannel* c1 = &s->ch[el->ch[1]];
  const AacIcsInfo* ics = &c0->ics;
  aac_bitwriter_write(w, 0, 2); /* core_mode0, core_mode1 */
  /* StereoCoreToolInfo(): one window for both channels, M/S per band,
   * no complex prediction, and TNS on the left and right channels after
   * the stereo processing, as the encoder applied it before its M/S
   * decision. */
  const int tns_active = c0->tns_present || c1->tns_present;
  aac_bitwriter_write(w, tns_active ? 1u : 0u, 1);
  aac_bitwriter_write(w, 1, 1); /* common_window */
  write_ics_info(w, ics, max_sfb);
  aac_bitwriter_write(w, 1, 1); /* common_max_sfb */
  const int ms = ms_mode(el, ics, max_sfb);
  aac_bitwriter_write(w, (uint32_t)ms, 2);
  if (ms == 1) {
    for (int g = 0; g < ics->num_window_groups; g++) {
      for (int b = 0; b < max_sfb; b++) {
        aac_bitwriter_write(w, el->ms_used[g * ics->num_swb + b] ? 1u : 0u, 1);
      }
    }
  }
  if (tns_active) {
    aac_bitwriter_write(w, 0, 1); /* common_tns */
    aac_bitwriter_write(w, 1, 1); /* tns_on_lr */
    const int both = c0->tns_present && c1->tns_present;
    aac_bitwriter_write(w, both ? 1u : 0u, 1);
    if (!both) {
      aac_bitwriter_write(w, c1->tns_present ? 1u : 0u, 1);
    }
  }
  for (int k = 0; k < 2; k++) {
    const int ret = write_fd_channel_stream(w, &s->ch[el->ch[k]], &u->ch[el->ch[k]], max_sfb, 1, 0,
                                            nf, indep, &u->tail_bits);
    if (ret) {
      return ret;
    }
  }
  return AAC_OK;
}

/* An access unit of silence with the stream's element list: one long
 * window per channel with the lowest band coded as zeros, independent.
 * The pre-roll of the first immediate playout frame, which warms the
 * decoder's filterbank with nothing. */
int write_silent_au(const AacEncoderState* s, uint8_t* out, int cap) {
  const AacUsacEncoder* u = s->usac;
  static const int kZero[kLong] = {0};
  int tail_bits = 0;
  AacBitWriter w;
  aac_bitwriter_init(&w, out, cap);
  aac_bitwriter_write(&w, 1, 1); /* usacIndependencyFlag */
  if (u->opt.preroll) {
    aac_bitwriter_write(&w, 0, 1); /* usacExtElementPresent */
  }
  for (int e = 0; e < s->num_elements; e++) {
    const AacEncElement* el = &s->elem[e];
    const int pair = el->num_ch == 2;
    aac_bitwriter_write(&w, 0, pair ? 2 : 1); /* core_mode */
    if (pair) {
      aac_bitwriter_write(&w, 0, 1); /* tns_active */
      aac_bitwriter_write(&w, 1, 1); /* common_window */
    } else {
      aac_bitwriter_write(&w, 0, 1); /* tns_data_present */
    }
    AacIcsInfo ics;
    memset(&ics, 0, sizeof(ics));
    aac_ics_layout(&ics, s->rate_index, kLong, AAC_WIN_ONLY_LONG, 0);
    ics.window_shape = AAC_WIN_KBD;
    const int lg = ics.swb_offset[kMinMaxSfb];
    if (pair) {
      write_ics_info(&w, &ics, kMinMaxSfb);
      aac_bitwriter_write(&w, 1, 1); /* common_max_sfb */
      aac_bitwriter_write(&w, 0, 2); /* ms_mask_present */
    }
    for (int k = 0; k < el->num_ch; k++) {
      aac_bitwriter_write(&w, 0, 8); /* global_gain */
      if (u->opt.noise_filling) {
        aac_bitwriter_write(&w, 0, 8); /* noise_level, noise_offset */
      }
      if (!pair) {
        write_ics_info(&w, &ics, kMinMaxSfb);
      }
      AacUsacArithContext ctx;
      aac_usac_arith_reset(&ctx);
      aac_usac_arith_encode(&ctx, 1, kLong, lg, kZero, &w);
      aac_bitwriter_write(&w, 0, 1); /* fac_data_present */
      tail_bits = 1;
    }
  }
  write_fill_element(&w, tail_bits);
  aac_bitwriter_byte_align(&w);
  return aac_bitwriter_overflow(&w) ? AAC_ERR_OVERFLOW : aac_bitwriter_bytes_written(&w);
}

/* AudioPreRoll() (5.5.2.2): the configuration in whole bytes, no
 * crossfade, and the one access unit before the frame. */
int write_preroll_payload(const AacEncoderState* s, uint8_t* out, int cap) {
  const AacUsacEncoder* u = s->usac;
  uint8_t cfg[AAC_USAC_MAX_CONFIG_BYTES];
  AacUsacConfig c;
  aac_usac_enc_config(s, &c);
  AacBitWriter cw;
  aac_bitwriter_init(&cw, cfg, sizeof(cfg));
  if (aac_usac_config_write(&c, &cw) < 0) {
    return AAC_ERR_OVERFLOW;
  }
  aac_bitwriter_byte_align(&cw);
  const int cfg_len = aac_bitwriter_bytes_written(&cw);
  AacBitWriter w;
  aac_bitwriter_init(&w, out, cap);
  aac_usac_write_escaped(&w, (unsigned)cfg_len, 4, 4, 8);
  write_bytes(&w, cfg, cfg_len);
  aac_bitwriter_write(&w, 0, 1); /* applyCrossfade */
  aac_bitwriter_write(&w, 0, 1); /* reserved */
  aac_usac_write_escaped(&w, 1, 2, 4, 0); /* numPreRollFrames */
  aac_usac_write_escaped(&w, (unsigned)u->last_au_len, 16, 16, 0);
  write_bytes(&w, u->last_au, u->last_au_len);
  aac_bitwriter_byte_align(&w);
  return aac_bitwriter_overflow(&w) ? AAC_ERR_OVERFLOW : aac_bitwriter_bytes_written(&w);
}

/* usacExtElementPayloadLength (Table 21). */
void write_ext_payload_length(AacBitWriter* w, int len) {
  if (len < 255) {
    aac_bitwriter_write(w, (uint32_t)len, 8);
  } else {
    aac_bitwriter_write(w, 255, 8);
    aac_bitwriter_write(w, (uint32_t)(len - 255 + 2), 16);
  }
}

}  // namespace

/* ── the state ─────────────────────────────────────────────────── */

AacUsacEncoder* aac_usac_enc_create(void) {
  auto* u = (AacUsacEncoder*)calloc(1, sizeof(AacUsacEncoder));
  if (!u) {
    return nullptr;
  }
  u->last_au_cap = AAC_USAC_ENC_MAX_AU_BYTES(AAC_MAX_CHANNELS);
  u->scratch_cap = AAC_USAC_ENC_MAX_AU_BYTES(AAC_MAX_CHANNELS);
  u->preroll_cap = AAC_USAC_ENC_MAX_AU_BYTES(AAC_MAX_CHANNELS);
  u->last_au = (uint8_t*)calloc((size_t)u->last_au_cap, 1);
  u->scratch = (uint8_t*)calloc((size_t)u->scratch_cap, 1);
  u->preroll = (uint8_t*)calloc((size_t)u->preroll_cap, 1);
  if (!u->last_au || !u->scratch || !u->preroll) {
    aac_usac_enc_destroy(u);
    return nullptr;
  }
  aac_usac_enc_set_options(u, nullptr, 48000);
  aac_usac_enc_reset(u, AAC_MAX_CHANNELS);
  return u;
}

void aac_usac_enc_destroy(AacUsacEncoder* u) {
  if (!u) {
    return;
  }
  free(u->last_au);
  free(u->scratch);
  free(u->preroll);
  free(u);
}

void aac_usac_enc_set_options(AacUsacEncoder* u, const AacUsacEncoderOptions* opt,
                              int sample_rate) {
  AacUsacEncoderOptions o;
  o.noise_filling = !opt || opt->noise_filling < 0 ? 1 : (opt->noise_filling != 0);
  o.preroll = !opt || opt->preroll < 0 ? 1 : (opt->preroll != 0);
  o.independent_interval_frames = opt && opt->independent_interval_frames > 0
                                      ? opt->independent_interval_frames
                                      : (sample_rate + kLong - 1) / kLong;
  o.stream_id = opt && opt->stream_id >= 0 && opt->stream_id <= 65535 ? opt->stream_id : -1;
  o.sbr = !opt || opt->sbr < 0 ? -1 : (opt->sbr != 0);
  o.mps212 = !opt || opt->mps212 < 0 ? -1 : (opt->mps212 != 0);
  u->opt = o;
  u->interval = std::max(1, o.independent_interval_frames);
}

int aac_usac_enc_set_config_ext(AacUsacEncoder* u, int type, const uint8_t* data, int len) {
  if (!u || type < 0 || len < 0 || len > AAC_USAC_MAX_CONFIG_EXT_BYTES || (len > 0 && !data)) {
    return AAC_ERR_INVALID_ARG;
  }
  int slot = u->num_config_exts;
  for (int i = 0; i < u->num_config_exts; i++) {
    if (u->config_ext[i].type == type) {
      slot = i;
      break;
    }
  }
  if (slot >= AAC_USAC_ENC_MAX_CONFIG_EXTS) {
    return AAC_ERR_OVERFLOW;
  }
  AacUsacConfigExt* x = &u->config_ext[slot];
  x->type = type;
  x->len = len;
  if (len > 0) {
    memcpy(x->data, data, (size_t)len);
  }
  if (slot == u->num_config_exts) {
    u->num_config_exts++;
  }
  return AAC_OK;
}

void aac_usac_enc_reset(AacUsacEncoder* u, int channels) {
  for (int c = 0; c < AAC_MAX_CHANNELS && c < channels; c++) {
    aac_usac_arith_reset(&u->ch[c].arith);
    memset(u->ch[c].sf, 0, sizeof(u->ch[c].sf));
    u->ch[c].global_gain = 0;
    u->ch[c].noise_level = 0;
    u->ch[c].noise_offset = 0;
  }
  u->frame_index = 0;
  u->indep = 1;
  u->ipf = 0;
  u->last_au_len = 0;
  u->preroll_bytes = 0;
}

/* ── the configuration ─────────────────────────────────────────── */

void aac_usac_enc_config(const AacEncoderState* s, AacUsacConfig* c) {
  const AacUsacEncoder* u = s->usac;
  memset(c, 0, sizeof(*c));
  c->sample_rate = s->sample_rate;
  c->core_sbr_frame_length_index = 1; /* 1024-line core, no SBR */
  c->frame_length = kLong;
  c->output_frame_length = kLong;
  c->sbr_ratio_index = AAC_USAC_SBR_NONE;
  c->core_sample_rate = s->sample_rate;
  c->channel_config_index = s->channels;
  c->num_channels = s->channels;
  int n = 0;
  if (u->opt.preroll) {
    AacUsacElementConfig* e = &c->elem[n++];
    e->type = AAC_USAC_EXT;
    e->ext_type = AAC_USAC_EXT_AUDIOPREROLL;
  }
  for (int i = 0; i < s->num_elements && n < AAC_USAC_MAX_ELEMENTS; i++) {
    AacUsacElementConfig* e = &c->elem[n++];
    const int type = s->elem[i].type;
    e->type = type == AAC_ELEM_CPE ? AAC_USAC_CPE : type == AAC_ELEM_LFE ? AAC_USAC_LFE : AAC_USAC_SCE;
    e->noise_filling = e->type == AAC_USAC_LFE ? 0 : u->opt.noise_filling;
  }
  if (n < AAC_USAC_MAX_ELEMENTS) {
    AacUsacElementConfig* e = &c->elem[n++];
    e->type = AAC_USAC_EXT;
    e->ext_type = AAC_USAC_EXT_FILL;
  }
  c->num_elements = n;
  c->stream_id = u->opt.stream_id;
  int x = 0;
  if (u->opt.stream_id >= 0) {
    AacUsacConfigExt* ext = &c->config_ext[x++];
    ext->type = AAC_USAC_CFG_EXT_STREAM_ID;
    ext->len = 2;
    ext->data[0] = (uint8_t)(u->opt.stream_id >> 8);
    ext->data[1] = (uint8_t)(u->opt.stream_id & 0xFF);
  }
  for (int i = 0; i < u->num_config_exts && x < AAC_USAC_MAX_CONFIG_EXTS; i++) {
    c->config_ext[x++] = u->config_ext[i];
  }
  c->num_config_exts = x;
}

int aac_usac_enc_max_au_bytes(const AacEncoderState* s) {
  return AAC_USAC_ENC_MAX_AU_BYTES(s->channels);
}

int aac_usac_enc_tns_max_bands(int rate_index, int is_short) {
  if (rate_index < 0 || rate_index >= AAC_NUM_SAMPLE_RATES) {
    return 0;
  }
  return is_short ? kUsacTnsMaxBandsShort[rate_index] : kUsacTnsMaxBandsLong[rate_index];
}

/* ── the frame ─────────────────────────────────────────────────── */

void aac_usac_enc_begin_frame(AacEncoderState* s) {
  AacUsacEncoder* u = s->usac;
  const int k = u->frame_index % u->interval;
  u->ipf = k == 0 && u->opt.preroll;
  /* The frame before an immediate playout frame is independent too, as
   * it is the pre-roll a decoder starts from. */
  u->indep = k == 0 || (u->opt.preroll && k == u->interval - 1);
  if (u->frame_index == 0 && u->opt.preroll) {
    const int len = write_silent_au(s, u->last_au, u->last_au_cap);
    u->last_au_len = len > 0 ? len : 0;
  }
}

int aac_usac_enc_element_bits(AacEncoderState* s, AacEncElement* el) {
  AacUsacEncoder* u = s->usac;
  const AacEncChannel* c0 = &s->ch[el->ch[0]];
  const AacIcsInfo* ics = &c0->ics;
  const int is_short = ics->num_windows == 8;
  const int nf = u->opt.noise_filling && el->type != AAC_ELEM_LFE;
  int max_sfb = el->max_sfb;
  if (nf) {
    /* every band up to the lowpass carries a scale factor, so that the
     * empty ones above the noise filling start are filled */
    for (int k = 0; k < el->num_ch; k++) {
      const AacEncChannel* c = &s->ch[el->ch[k]];
      max_sfb = std::max(max_sfb, is_short ? c->active_short : c->active_long);
    }
  }
  max_sfb = std::max(kMinMaxSfb, std::min(max_sfb, ics->num_swb));
  el->max_sfb = max_sfb;
  int bits = 0;
  if (el->num_ch == 2 && ms_mode(el, ics, max_sfb) == 1) {
    bits += ics->num_window_groups * max_sfb;
  }
  int signs = 0;
  for (int k = 0; k < el->num_ch; k++) {
    const AacEncChannel* c = &s->ch[el->ch[k]];
    AacUsacEncChannel* uc = &u->ch[el->ch[k]];
    assign_scale_factors(c, uc, max_sfb, nf);
    bits += scale_factor_bits(c, uc, max_sfb);
    AacUsacArithContext ctx = uc->arith;
    const int spectral = spectral_data(&ctx, c, max_sfb, u->indep, nullptr, &signs);
    bits += spectral < 0 ? 0 : spectral;
  }
  if (el == &s->elem[s->num_elements - 1]) {
    bits += fill_element_bits(signs + 1);
  }
  return bits;
}

int aac_usac_enc_overhead_bits(const AacEncoderState* s) {
  const AacUsacEncoder* u = s->usac;
  const int nf_bits = u->opt.noise_filling ? 8 : 0;
  const int reset_bits = u->indep ? 0 : 1;
  int bits = kTransportBits + 1 + 7; /* transport, usacIndependencyFlag, alignment */
  if (u->opt.preroll) {
    bits += 1; /* usacExtElementPresent; the pre-roll itself is outside the budget */
  }
  /* The fill element's bits are counted with the last channel element. */
  for (int e = 0; e < s->num_elements; e++) {
    const AacEncElement* el = &s->elem[e];
    const AacEncChannel* c0 = &s->ch[el->ch[0]];
    if (el->num_ch == 1) {
      bits += 1 + 1 + 8 + nf_bits + ics_info_bits(&c0->ics) + tns_bits(c0) + reset_bits + 1;
      continue;
    }
    const AacEncChannel* c1 = &s->ch[el->ch[1]];
    bits += 2 + 1 + 1 + ics_info_bits(&c0->ics) + 1 + 2;
    if (c0->tns_present || c1->tns_present) {
      bits += 1 + 1 + 1 + (c0->tns_present && c1->tns_present ? 0 : 1) + tns_bits(c0) + tns_bits(c1);
    }
    bits += 2 * (8 + nf_bits + reset_bits + 1);
  }
  return bits;
}

int aac_usac_enc_write_frame(AacEncoderState* s) {
  AacUsacEncoder* u = s->usac;
  /* The channel elements first, so that the access unit with and without
   * the pre-roll element in front can both be assembled from them. */
  AacBitWriter ew;
  aac_bitwriter_init(&ew, u->scratch, u->scratch_cap);
  for (int e = 0; e < s->num_elements; e++) {
    const int ret = write_element(s, &s->elem[e], &ew);
    if (ret) {
      return ret;
    }
  }
  write_fill_element(&ew, u->tail_bits);
  if (aac_bitwriter_overflow(&ew)) {
    return AAC_ERR_OVERFLOW;
  }
  const int elem_bits = aac_bitwriter_bits_written(&ew);

  AacBitWriter w;
  aac_bitwriter_init(&w, s->output_buf + AAC_ADTS_HEADER_SIZE,
                     (int)sizeof(s->output_buf) - AAC_ADTS_HEADER_SIZE);
  aac_bitwriter_write(&w, u->indep ? 1u : 0u, 1);
  u->preroll_bytes = 0;
  if (u->opt.preroll) {
    aac_bitwriter_write(&w, u->ipf ? 1u : 0u, 1); /* usacExtElementPresent */
    if (u->ipf) {
      /* The payload is byte aligned in itself, not in the frame. */
      const int len = write_preroll_payload(s, u->preroll, u->preroll_cap);
      if (len < 0) {
        return len;
      }
      aac_bitwriter_write(&w, 0, 1); /* usacExtElementUseDefaultLength */
      write_ext_payload_length(&w, len);
      write_bytes(&w, u->preroll, len);
      u->preroll_bytes = len + (len < 255 ? 1 : 3);
    }
  }
  copy_bits(&w, u->scratch, elem_bits);
  aac_bitwriter_byte_align(&w);
  if (aac_bitwriter_overflow(&w)) {
    return AAC_ERR_OVERFLOW;
  }
  const int au_len = aac_bitwriter_bytes_written(&w);

  /* The frame without its pre-roll, for the next immediate playout frame. */
  AacBitWriter lw;
  aac_bitwriter_init(&lw, u->last_au, u->last_au_cap);
  aac_bitwriter_write(&lw, u->indep ? 1u : 0u, 1);
  if (u->opt.preroll) {
    aac_bitwriter_write(&lw, 0, 1);
  }
  copy_bits(&lw, u->scratch, elem_bits);
  aac_bitwriter_byte_align(&lw);
  u->last_au_len = aac_bitwriter_overflow(&lw) ? 0 : aac_bitwriter_bytes_written(&lw);
  u->frame_index++;
  return au_len + AAC_ADTS_HEADER_SIZE;
}
