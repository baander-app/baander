/* The USAC frame writer (ISO/IEC 23003-3 6.1 and 6.2): UsacFrame() with
 * the frequency-domain core over the AAC-LC encoder's analysis and
 * quantisation.  The AAC-LC encoder chooses the window sequence, the
 * TNS filters, the M/S bands, the scale factors and the quantised
 * spectrum within its rate loop; this module replaces the codebook
 * sectioning and the Huffman coding by the USAC scale factor rule (a
 * delta coded scale factor for every band below max_sfb) and the
 * context based arithmetic coder, adds the noise filling parameters,
 * and writes the element syntax: UsacSingleChannelElement,
 * UsacChannelPairElement with StereoCoreToolInfo (or with one core
 * channel when MPS 212 codes the pair), the UsacSbrData() and
 * Mps212Data() the SBR and MPS 212 encoders prepared for the element,
 * the audio pre-roll extension element of the immediate playout frames,
 * and with the drc option the MPEG-D DRC gains of every frame in the
 * UNI_DRC extension element that follows the pre-roll's. */
#include "usac_enc.h"

#include <algorithm>
#include <cmath>
#include <cstdlib>
#include <cstring>

#include "asc.h"
#include "drc_gen.h"
#include "encoder.h"
#include "loudness.h"

namespace {

constexpr int kShort = AAC_FRAME_SIZE_SHORT;
constexpr int kLong = AAC_FRAME_SIZE_LONG;
/* The transport's bytes per frame as the rate loop budgets them: the
 * AAC-LC path counts an ADTS header, and the LOAS header with the mux
 * element's fields is of the same order. */
constexpr int kTransportBits = 8 * AAC_ADTS_HEADER_SIZE;
constexpr int kMaxSfDelta = 60;
constexpr int kMaxSf = 255;
constexpr int kUnaffordableBits = 1 << 24; /* more than any frame budget */
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
/* Bits a channel's lowest band costs at most when every other band is
 * released: the arithmetic coded zeros of its lines, which the segment
 * ceiling's floor allows on top of the side information. */
constexpr int kMinChannelBits = 32;
/* An empty band whose lines are quieter than this (about 150 dB below
 * full scale in the coefficient domain) is silence and gets no noise:
 * digital silence decodes to digital silence. */
constexpr double kSilentBandRms = 1e-3;

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
 * bands' scale factors on their neighbours.  Every other empty band,
 * and one holding silence, repeats the previous scale factor.  The
 * chain of differences is kept within the codebook's range of 60 in
 * both directions. */
void assign_scale_factors(const AacEncChannel* c, AacUsacEncChannel* uc, int max_sfb,
                          int noise_filling) {
  const AacIcsInfo* ics = &c->ics;
  const int nswb = ics->num_swb;
  const int* off = ics->swb_offset;
  const int is_short = ics->num_windows == 8;
  const int start_line = noise_fill_start(is_short);
  const int ngroups = ics->num_window_groups;

  /* the level of the zeroed lines inside the coded bands, and the
   * level of every empty band */
  double zero_sum = 0.0;
  int zero_count = 0;
  int empty_bands = 0;
  double band_rms[AAC_MAX_BANDS];
  int w0 = 0;
  for (int g = 0; g < ngroups; g++) {
    for (int b = 0; b < max_sfb; b++) {
      const int idx = g * nswb + b;
      band_rms[idx] = 0.0;
      if (off[b] < start_line) {
        continue;
      }
      if (c->cb[idx] == 0) {
        double energy = 0.0;
        for (int w = w0; w < w0 + ics->group_len[g]; w++) {
          for (int i = w * kShort + off[b]; i < w * kShort + off[b + 1]; i++) {
            energy += (double)c->coef[i] * c->coef[i];
          }
        }
        const double lines = (double)ics->group_len[g] * (off[b + 1] - off[b]);
        band_rms[idx] = sqrt(energy / lines);
        empty_bands += band_rms[idx] > kSilentBandRms;
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
      if (noise_level == 0 || off[b] < start_line || band_rms[idx] <= kSilentBandRms) {
        continue;
      }
      fill[idx] = true;
      target[idx] = std::clamp(
          (int)lround(100.0 + 4.0 * log2(band_rms[idx] / (double)noise_val)), 0, kMaxSf);
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

/* The SBR and MPS 212 data of the element after its core (Tables 17
 * and 18): UsacSbrData() of the SBR encoder's channels, then Mps212Data()
 * when the pair is coded as its downmix.  A frame whose Mps212Data()
 * could not be written fails here. */
int write_element_tools(AacEncoderState* s, int e, AacBitWriter* w) {
  AacUsacEncoder* u = s->usac;
  const AacSbrEncoder* sbr = s->sbr[e];
  if (!sbr) {
    return AAC_OK;
  }
  aac_sbr_enc_usac_write(sbr, w);
  u->tail_bits += sbr->payload_bits;
  if (sbr->mps) {
    const int err = aac_mps212_enc_error(&u->mps);
    if (err) {
      return err;
    }
    aac_mps212_enc_write(&u->mps, w);
    u->tail_bits += aac_mps212_enc_bits(&u->mps);
  }
  return AAC_OK;
}

int element_tool_bits(const AacEncoderState* s, int e) {
  const AacSbrEncoder* sbr = s->sbr[e];
  if (!sbr) {
    return 0;
  }
  return sbr->payload_bits + (sbr->mps ? aac_mps212_enc_bits(&s->usac->mps) : 0);
}

/* UsacSingleChannelElement() and UsacChannelPairElement() (Tables 17
 * and 18) with both channels in the frequency domain.  A pair coded
 * through MPS 212 (stereoConfigIndex 1) carries one core channel, whose
 * syntax is the single channel's. */
int write_element(AacEncoderState* s, int e, AacBitWriter* w) {
  AacUsacEncoder* u = s->usac;
  const AacEncElement* el = &s->elem[e];
  const int nf = u->opt.noise_filling;
  const int indep = u->indep;
  const int max_sfb = el->max_sfb;
  if (el->num_ch == 1) {
    const AacEncChannel* c = &s->ch[el->ch[0]];
    AacUsacEncChannel* uc = &u->ch[el->ch[0]];
    aac_bitwriter_write(w, 0, 1); /* core_mode */
    aac_bitwriter_write(w, c->tns_present ? 1u : 0u, 1);
    const int ret = write_fd_channel_stream(w, c, uc, max_sfb, 0, 0, nf, indep, &u->tail_bits);
    if (ret) {
      return ret;
    }
    return write_element_tools(s, e, w);
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
  return write_element_tools(s, e, w);
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

/* The UNI_DRC extension element of a frame (Table 21 with the
 * uniDrcGain() payload): present in every frame, with its length. */
void write_drc_element(AacBitWriter* w, const uint8_t* payload, int len) {
  aac_bitwriter_write(w, 1, 1); /* usacExtElementPresent */
  aac_bitwriter_write(w, 0, 1); /* usacExtElementUseDefaultLength */
  write_ext_payload_length(w, len);
  aac_bitwriter_write_bytes(w, payload, len);
}

int drc_element_bits(int len) { return 1 + 1 + (len < 255 ? 8 : 24) + 8 * len; }

/* uniDrcGain() of a frame of silence: every gain sequence holds 0 dB
 * at the frame end, in the compact coding mode. */
int silent_drc_payload(const AacEncoderState* s, uint8_t* out, int cap) {
  AacUniDrcConfig c;
  aac_drc_gen_config(&c, s->input_channels);
  AacUniDrcGainFrame g;
  g.sequence_count = AAC_DRC_GEN_SETS;
  for (int i = 0; i < AAC_DRC_GEN_SETS; i++) {
    g.seq[i].node_count = 1;
    g.seq[i].node[0].time = s->frame_size - 1;
    g.seq[i].node[0].gain_db = 0.0f;
    g.seq[i].node[0].slope = 0.0f;
  }
  memset(out, 0, (size_t)cap);
  AacBitWriter w;
  aac_bitwriter_init(&w, out, cap);
  const int bits = aac_drc_gen_write_gain(&w, &c, &g, s->frame_size, s->input_rate);
  return bits < 0 ? bits : (bits + 7) / 8;
}

/* An access unit of silence with the stream's element list: one long
 * window per channel with the lowest band coded as zeros, independent,
 * with the SBR and MPS 212 data of silence.  The pre-roll of the first
 * immediate playout frame, which warms the decoder's filterbanks with
 * nothing. */
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
  if (u->opt.drc) {
    uint8_t payload[AAC_DRC_GEN_MAX_PAYLOAD_BYTES];
    const int len = silent_drc_payload(s, payload, (int)sizeof(payload));
    if (len < 0) {
      return len;
    }
    write_drc_element(&w, payload, len);
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
    const AacSbrEncoder* sbr = s->sbr[e];
    if (sbr) {
      const int before = aac_bitwriter_bits_written(&w);
      aac_sbr_enc_usac_write_silent(sbr, &w);
      if (sbr->mps) {
        aac_mps212_enc_write_silent(&w);
      }
      tail_bits += aac_bitwriter_bits_written(&w) - before;
    }
  }
  write_fill_element(&w, tail_bits);
  aac_bitwriter_byte_align(&w);
  return aac_bitwriter_overflow(&w) ? AAC_ERR_OVERFLOW : aac_bitwriter_bytes_written(&w);
}

/* AudioPreRoll() (5.5.2.2): the configuration in whole bytes, no
 * crossfade, and the access units before the frame, oldest first. */
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
  aac_bitwriter_write_bytes(&w, cfg, cfg_len);
  aac_bitwriter_write(&w, 0, 1); /* applyCrossfade */
  aac_bitwriter_write(&w, 0, 1); /* reserved */
  const int n = u->preroll_frames;
  aac_usac_write_escaped(&w, (unsigned)n, 2, 4, 0); /* numPreRollFrames */
  for (int i = 0; i < n; i++) {
    aac_usac_write_escaped(&w, (unsigned)u->last_au_len[i], 16, 16, 0);
    aac_bitwriter_write_bytes(&w, u->last_au[i], u->last_au_len[i]);
  }
  aac_bitwriter_byte_align(&w);
  return aac_bitwriter_overflow(&w) ? AAC_ERR_OVERFLOW : aac_bitwriter_bytes_written(&w);
}

/* The loudnessInfoSet() of a stream with DRC sets: the programme's
 * entry (drcSetId 0) and one per DRC set, the measured figures where the
 * caller gave them and the estimate otherwise.  Without any figures the
 * set is empty but present: libxaac applies no DRC set to a stream
 * without one. */
int drc_loudness_set(const AacUsacEncoder* u, uint8_t* out, int cap) {
  AacLoudnessInfo info[1 + AAC_DRC_GEN_SETS];
  int ids[1 + AAC_DRC_GEN_SETS];
  int n = 0;
  if (u->has_program_loudness) {
    info[n] = u->program_loudness;
    ids[n++] = 0;
  }
  for (int id = 1; id <= AAC_DRC_GEN_SETS; id++) {
    if (aac_usac_enc_drc_set_figures(u, id, &info[n])) {
      ids[n++] = id;
    }
  }
  memset(out, 0, (size_t)cap);
  AacBitWriter w;
  aac_bitwriter_init(&w, out, cap);
  const int bits = aac_drc_gen_write_loudness(&w, info, ids, n);
  return bits < 0 ? bits : (bits + 7) / 8;
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
  u->preroll_cap = AAC_USAC_ENC_MAX_IPF_BYTES(AAC_MAX_CHANNELS);
  bool ok = true;
  for (int i = 0; i < AAC_USAC_MAX_PREROLL_FRAMES; i++) {
    u->last_au[i] = (uint8_t*)calloc((size_t)u->last_au_cap, 1);
    ok = ok && u->last_au[i];
  }
  u->scratch = (uint8_t*)calloc((size_t)u->scratch_cap, 1);
  u->preroll = (uint8_t*)calloc((size_t)u->preroll_cap, 1);
  if (!ok || !u->scratch || !u->preroll) {
    aac_usac_enc_destroy(u);
    return nullptr;
  }
  u->preroll_frames = AAC_USAC_ENC_PREROLL_FRAMES;
  aac_usac_enc_set_options(u, nullptr);
  aac_usac_enc_reset(u, AAC_MAX_CHANNELS);
  return u;
}

void aac_usac_enc_destroy(AacUsacEncoder* u) {
  if (!u) {
    return;
  }
  for (int i = 0; i < AAC_USAC_MAX_PREROLL_FRAMES; i++) {
    free(u->last_au[i]);
  }
  aac_drc_gen_destroy(u->drc);
  free(u->scratch);
  free(u->preroll);
  free(u);
}

void aac_usac_enc_set_options(AacUsacEncoder* u, const AacUsacEncoderOptions* opt) {
  AacUsacEncoderOptions o;
  o.noise_filling = !opt || opt->noise_filling < 0 ? 1 : (opt->noise_filling != 0);
  o.preroll = !opt || opt->preroll < 0 ? 1 : (opt->preroll != 0);
  /* 0 in every schedule field stands for the default of one second,
   * which aac_usac_enc_interval_frames turns into frames */
  o.independent_interval_frames =
      opt && opt->independent_interval_frames > 0 ? opt->independent_interval_frames : 0;
  o.independent_interval_ms = opt && opt->independent_interval_ms > 0 ? opt->independent_interval_ms : 0;
  o.independent_interval_samples =
      opt && opt->independent_interval_samples > 0 ? opt->independent_interval_samples : 0;
  o.independent_max_distance_frames =
      opt && opt->independent_max_distance_frames > 0 ? opt->independent_max_distance_frames : 0;
  o.segment_max_bytes = opt && opt->segment_max_bytes > 0 ? opt->segment_max_bytes : 0;
  o.stream_id = opt && opt->stream_id >= 0 && opt->stream_id <= 65535 ? opt->stream_id : -1;
  o.sbr = !opt || opt->sbr < 0 ? -1 : (opt->sbr != 0);
  o.mps212 = !opt || opt->mps212 < 0 ? -1 : (opt->mps212 != 0);
  o.drc = opt && opt->drc == 1 ? 1 : 0;
  o.drc_target_range_lu = opt && opt->drc_target_range_lu >= AAC_DRC_GEN_MIN_RANGE_LU &&
                                  opt->drc_target_range_lu <= AAC_DRC_GEN_MAX_RANGE_LU
                              ? opt->drc_target_range_lu
                              : AAC_DRC_GEN_DEFAULT_RANGE_LU;
  u->opt = o;
}

int aac_usac_enc_interval_frames(const AacUsacEncoderOptions* o, int output_rate,
                                 int output_frame) {
  output_frame = std::max(1, output_frame);
  long long frames;
  if (o->independent_interval_samples > 0) {
    frames = ((long long)o->independent_interval_samples + output_frame - 1) / output_frame;
  } else if (o->independent_interval_ms > 0) {
    /* ms * rate / 1000 samples, rounded up to whole frames */
    const long long per_frame = 1000LL * output_frame;
    frames = ((long long)o->independent_interval_ms * output_rate + per_frame - 1) / per_frame;
  } else if (o->independent_interval_frames > 0) {
    frames = o->independent_interval_frames;
  } else {
    frames = ((long long)output_rate + output_frame - 1) / output_frame;
  }
  if (o->independent_max_distance_frames > 0) {
    frames = std::min(frames, (long long)o->independent_max_distance_frames);
  }
  return (int)std::clamp(frames, 1LL, (long long)INT32_MAX / 2);
}

void aac_usac_enc_request_independent(AacUsacEncoder* u) { u->requested = 1; }

void aac_usac_enc_choose_tools(const AacUsacEncoder* u, int input_rate, int input_channels,
                               int bitrate, int* sbr, int* mps) {
  const AacUsacEncoderOptions* o = &u->opt;
  int want_sbr = o->sbr >= 0 ? o->sbr : bitrate < 64000 * input_channels;
  /* The 2-1-2 is never chosen by bitrate: FFmpeg's USAC decoder rejects
   * every stereoConfigIndex 1 stream, so a caller opts in knowing the
   * players.  stereoConfigIndex exists only with an SBR ratio: the
   * 2-1-2 brings SBR with it where the bitrate rule decides, and stays
   * off where SBR was turned off (aac_encoder_set_usac refuses that
   * pair of options). */
  int want_mps = input_channels == 2 && o->mps212 > 0 && o->sbr != 0;
  if (want_mps) {
    want_sbr = 1;
  }
  if (want_sbr && (input_rate % 2 || aac_asc_rate_index(input_rate / 2) < 0)) {
    want_sbr = 0;
    want_mps = 0;
  }
  *sbr = want_sbr;
  *mps = want_mps;
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
  u->next_ipf = 0;
  u->requested = 0;
  u->segment_used = 0;
  u->segment_reserved = 0;
  for (int i = 0; i < AAC_USAC_MAX_PREROLL_FRAMES; i++) {
    u->last_au_len[i] = 0;
  }
  u->preroll_len = 0;
  u->preroll_bytes = 0;
  u->drc_payload_len = 0;
  u->drc_payload_frame = 0;
  if (u->mps_active) {
    aac_mps212_enc_reset(&u->mps);
  }
}

/* ── the configuration ─────────────────────────────────────────── */

void aac_usac_enc_config(const AacEncoderState* s, AacUsacConfig* c) {
  const AacUsacEncoder* u = s->usac;
  const int sbr = u->sbr_active;
  memset(c, 0, sizeof(*c));
  /* the output rate is the input's; with SBR the core runs at half of it
   * and a frame yields 2048 samples (coreSbrFrameLengthIndex 3) */
  c->sample_rate = s->input_rate;
  c->core_sbr_frame_length_index = sbr ? 3 : 1;
  c->frame_length = kLong;
  c->output_frame_length = sbr ? 2 * kLong : kLong;
  c->sbr_ratio_index = sbr ? AAC_USAC_SBR_2_1 : AAC_USAC_SBR_NONE;
  c->core_sample_rate = s->sample_rate;
  c->channel_config_index = s->input_channels;
  c->num_channels = s->input_channels;
  int n = 0;
  if (u->opt.preroll) {
    AacUsacElementConfig* e = &c->elem[n++];
    e->type = AAC_USAC_EXT;
    e->ext_type = AAC_USAC_EXT_AUDIOPREROLL;
  }
  if (u->opt.drc) {
    /* the DRC gains of every frame, configured by the uniDrcConfig() */
    AacUsacElementConfig* e = &c->elem[n++];
    e->type = AAC_USAC_EXT;
    e->ext_type = AAC_USAC_EXT_UNI_DRC;
    AacUniDrcConfig drc;
    aac_drc_gen_config(&drc, s->input_channels);
    AacBitWriter w;
    aac_bitwriter_init(&w, e->ext_config, (int)sizeof(e->ext_config));
    const int bits = aac_drc_gen_write_config(&w, &drc);
    e->ext_config_len = bits > 0 ? (bits + 7) / 8 : 0;
  }
  for (int i = 0; i < s->num_elements && n < AAC_USAC_MAX_ELEMENTS; i++) {
    AacUsacElementConfig* e = &c->elem[n++];
    const int type = s->elem[i].type;
    const AacSbrEncoder* sbr_enc = s->sbr[i];
    e->type = type == AAC_ELEM_CPE ? AAC_USAC_CPE : type == AAC_ELEM_LFE ? AAC_USAC_LFE : AAC_USAC_SCE;
    e->noise_filling = e->type == AAC_USAC_LFE ? 0 : u->opt.noise_filling;
    if (sbr_enc) {
      aac_sbr_enc_usac_config(sbr_enc, &e->sbr);
      if (sbr_enc->mps) {
        /* the stereo pair coded as its downmix: a channel pair element
         * with one core channel and the MPS 212 parameters */
        e->type = AAC_USAC_CPE;
        e->stereo_config_index = 1;
        aac_mps212_enc_config(&u->mps, &e->mps);
      }
    }
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
  /* With DRC sets the loudness info set gains their entries: it takes
   * the place of the programme's own set, or follows the others. */
  int loudness_slot = -1;
  for (int i = 0; i < u->num_config_exts && x < AAC_USAC_MAX_CONFIG_EXTS; i++) {
    if (u->opt.drc && u->config_ext[i].type == AAC_USAC_CFG_EXT_LOUDNESS_INFO) {
      loudness_slot = x;
    }
    c->config_ext[x++] = u->config_ext[i];
  }
  if (u->opt.drc) {
    if (loudness_slot < 0 && x < AAC_USAC_MAX_CONFIG_EXTS) {
      loudness_slot = x++;
    }
    if (loudness_slot >= 0) {
      AacUsacConfigExt* ext = &c->config_ext[loudness_slot];
      ext->type = AAC_USAC_CFG_EXT_LOUDNESS_INFO;
      ext->len = std::max(0, drc_loudness_set(u, ext->data, (int)sizeof(ext->data)));
      if (ext->len == 0) {
        for (int i = loudness_slot; i + 1 < x; i++) {
          c->config_ext[i] = c->config_ext[i + 1];
        }
        x--;
      }
    }
  }
  c->num_config_exts = x;
}

int aac_usac_enc_max_au_bytes(const AacEncoderState* s) {
  const AacUsacEncoder* u = s->usac;
  int per_au = (6144 / 8) * s->channels;
  for (int e = 0; e < s->num_elements; e++) {
    if (s->sbr[e]) {
      per_au += AAC_SBR_ENC_MAX_BYTES * (s->sbr[e]->mps ? 1 : s->elem[e].num_ch) +
                (s->sbr[e]->mps ? AAC_MPS212_ENC_MAX_BYTES : 0);
    }
  }
  if (u->opt.drc) {
    per_au += AAC_USAC_ENC_MAX_DRC_BYTES;
  }
  const int preroll = u->opt.preroll ? u->preroll_frames : 0;
  return (1 + preroll) * per_au + AAC_USAC_MAX_CONFIG_BYTES + 32;
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
  const int n = u->preroll_frames;
  const int k = u->frame_index;
  /* The pre-roll of an immediate playout frame repeats the frames
   * before it, and a decoder starts from the first of them, so that one
   * is independent too: the independent frame leads the immediate
   * playout frame by the pre-roll's frame count.  An on-demand request
   * brings the next immediate playout frame to that lead from now,
   * unless one is already due sooner. */
  const int lead = u->opt.preroll ? n : 0;
  if (u->requested && k + lead < u->next_ipf) {
    u->next_ipf = k + lead;
  }
  u->requested = 0;
  const int starts_segment = k == u->next_ipf;
  u->ipf = starts_segment && u->opt.preroll;
  u->indep = starts_segment || (u->opt.preroll && k == u->next_ipf - lead);
  if (k == 0 && u->opt.preroll) {
    const int len = write_silent_au(s, u->last_au[0], u->last_au_cap);
    u->last_au_len[0] = len > 0 ? len : 0;
    for (int i = 1; i < n; i++) {
      memcpy(u->last_au[i], u->last_au[0], (size_t)u->last_au_len[0]);
      u->last_au_len[i] = u->last_au_len[0];
    }
  }
  if (starts_segment) {
    /* a new segment: the interval counts from here, and the pre-roll
     * this frame carries is the first charge against the ceiling */
    u->next_ipf = k + aac_usac_enc_interval_frames(&u->opt, s->input_rate, s->frame_size);
    u->segment_used = 0;
    u->segment_reserved = 0;
  }
  u->preroll_len = 0;
  if (u->ipf) {
    const int len = write_preroll_payload(s, u->preroll, u->preroll_cap);
    u->preroll_len = len > 0 ? len : 0;
    u->segment_reserved = u->preroll_len + (u->preroll_len < 255 ? 1 : 3);
  }
  for (int e = 0; e < s->num_elements; e++) {
    if (s->sbr[e]) {
      s->sbr[e]->usac_indep = u->indep;
    }
  }
  if (u->opt.drc && u->drc_payload_frame != k + 1) {
    aac_usac_enc_drc_input(s, nullptr);
  }
}

int aac_usac_enc_frame_bit_ceiling(const AacEncoderState* s) {
  const AacUsacEncoder* u = s->usac;
  if (u->opt.segment_max_bytes <= 0) {
    return 0;
  }
  /* The floor: the frame's side information with every channel coding
   * its lowest band alone, as the rate loop reaches it when it releases
   * every band. */
  const int floor_bits = aac_usac_enc_overhead_bits(s) + kMinChannelBits * s->channels;
  const int remaining_frames = std::max(1, u->next_ipf - u->frame_index);
  const long long remaining =
      (long long)u->opt.segment_max_bytes - u->segment_used - u->segment_reserved;
  if (remaining <= 0) {
    return floor_bits;
  }
  /* The share is in access unit bytes; the rate loop's count carries the
   * transport estimate on top of the access unit. */
  const long long share = remaining * 8 / remaining_frames + kTransportBits;
  return (int)std::max((long long)floor_bits, std::min(share, (long long)kUnaffordableBits));
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
    /* a spectrum the arithmetic coder cannot carry (a line beyond the
     * escape range) counts as unaffordable, so the rate loop moves to a
     * coarser quantisation instead of writing a frame that fails */
    bits += spectral < 0 ? kUnaffordableBits : spectral;
  }
  if (el == &s->elem[s->num_elements - 1]) {
    bits += fill_element_bits(signs + 1 + element_tool_bits(s, (int)(el - s->elem)));
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
  if (u->opt.drc) {
    bits += drc_element_bits(u->drc_payload_len);
  }
  /* The fill element's bits are counted with the last channel element. */
  for (int e = 0; e < s->num_elements; e++) {
    const AacEncElement* el = &s->elem[e];
    const AacEncChannel* c0 = &s->ch[el->ch[0]];
    bits += element_tool_bits(s, e);
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
  if (u->opt.drc) {
    write_drc_element(&ew, u->drc_payload, u->drc_payload_len);
  }
  for (int e = 0; e < s->num_elements; e++) {
    const int ret = write_element(s, e, &ew);
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
      /* The payload, prepared when the frame began, is byte aligned in
       * itself, not in the frame. */
      const int len = u->preroll_len;
      if (len <= 0) {
        return AAC_ERR_OVERFLOW;
      }
      aac_bitwriter_write(&w, 0, 1); /* usacExtElementUseDefaultLength */
      write_ext_payload_length(&w, len);
      aac_bitwriter_write_bytes(&w, u->preroll, len);
      u->preroll_bytes = len + (len < 255 ? 1 : 3);
    }
  }
  aac_bitwriter_write_bits(&w, u->scratch, elem_bits);
  aac_bitwriter_byte_align(&w);
  if (aac_bitwriter_overflow(&w)) {
    return AAC_ERR_OVERFLOW;
  }
  const int au_len = aac_bitwriter_bytes_written(&w);

  /* The frame without its pre-roll into the ring of the last access
   * units, for the next immediate playout frame. */
  const int n = u->preroll_frames;
  uint8_t* oldest = u->last_au[0];
  for (int i = 0; i + 1 < n; i++) {
    u->last_au[i] = u->last_au[i + 1];
    u->last_au_len[i] = u->last_au_len[i + 1];
  }
  u->last_au[n - 1] = oldest;
  AacBitWriter lw;
  aac_bitwriter_init(&lw, oldest, u->last_au_cap);
  aac_bitwriter_write(&lw, u->indep ? 1u : 0u, 1);
  if (u->opt.preroll) {
    aac_bitwriter_write(&lw, 0, 1);
  }
  aac_bitwriter_write_bits(&lw, u->scratch, elem_bits);
  aac_bitwriter_byte_align(&lw);
  u->last_au_len[n - 1] = aac_bitwriter_overflow(&lw) ? 0 : aac_bitwriter_bytes_written(&lw);
  /* The segment's bytes, and the frame for aac_encoder_last_frame_info. */
  u->segment_used += au_len;
  u->segment_reserved = 0;
  u->has_last_frame = 1;
  u->last_frame_index = u->frame_index;
  u->last_indep = u->indep;
  u->last_preroll_bytes = u->preroll_bytes;
  u->last_au_bytes = au_len;
  u->last_frame_bytes = au_len;
  u->frame_index++;
  return au_len + AAC_ADTS_HEADER_SIZE;
}

/* ── MPEG-D DRC ────────────────────────────────────────────────── */

int aac_usac_enc_drc_input(AacEncoderState* s, const float* pcm) {
  AacUsacEncoder* u = s->usac;
  if (!u || !u->opt.drc) {
    return AAC_OK;
  }
  if (u->frame_index == 0 || !u->drc) {
    /* a new stream: the generator starts over, anchored on the
     * programme's figures when the caller measured them */
    aac_drc_gen_destroy(u->drc);
    AacDrcGenParams p;
    memset(&p, 0, sizeof(p));
    p.sample_rate = s->input_rate;
    p.channels = s->input_channels;
    p.frame_length = s->frame_size;
    p.delay = s->delay;
    p.target_range_lu = u->opt.drc_target_range_lu;
    if (u->has_program_loudness) {
      p.has_anchor = 1;
      p.anchor_lkfs = u->program_loudness.integrated_lkfs;
      p.has_range = u->program_loudness.has_range;
      p.range_lu = u->program_loudness.range_lu;
    }
    p.measure = s->loudness != nullptr;
    u->drc = aac_drc_gen_create(&p);
    if (!u->drc) {
      return AAC_ERR_MEMORY;
    }
  }
  AacUniDrcGainFrame g;
  aac_drc_gen_frame(u->drc, pcm, &g);
  AacUniDrcConfig c;
  aac_drc_gen_config(&c, s->input_channels);
  memset(u->drc_payload, 0, sizeof(u->drc_payload));
  AacBitWriter w;
  aac_bitwriter_init(&w, u->drc_payload, (int)sizeof(u->drc_payload));
  const int bits = aac_drc_gen_write_gain(&w, &c, &g, s->frame_size, s->input_rate);
  if (bits < 0) {
    /* never for the generator's nodes; the frame keeps the gains of
     * silence rather than fail */
    u->drc_payload_len = silent_drc_payload(s, u->drc_payload, (int)sizeof(u->drc_payload));
  } else {
    u->drc_payload_len = (bits + 7) / 8;
  }
  u->drc_payload_frame = u->frame_index + 1;
  return AAC_OK;
}

void aac_usac_enc_set_program_loudness(AacUsacEncoder* u, const AacLoudnessInfo* info) {
  u->has_program_loudness = info != nullptr;
  if (info) {
    u->program_loudness = *info;
  }
}

int aac_usac_enc_set_drc_loudness(AacUsacEncoder* u, int drc_set_id, const AacLoudnessInfo* info) {
  if (drc_set_id < 1 || drc_set_id > AAC_DRC_GEN_SETS) {
    return AAC_ERR_INVALID_ARG;
  }
  u->has_set_loudness[drc_set_id - 1] = info != nullptr;
  if (info) {
    u->set_loudness[drc_set_id - 1] = *info;
  }
  return AAC_OK;
}

int aac_usac_enc_drc_loudness(const AacUsacEncoder* u, int drc_set_id, AacLoudnessInfo* out) {
  if (drc_set_id < 1 || drc_set_id > AAC_DRC_GEN_SETS) {
    return AAC_ERR_INVALID_ARG;
  }
  if (!u->drc) {
    return AAC_ERR_STATE;
  }
  return aac_drc_gen_loudness(u->drc, drc_set_id, out);
}

int aac_usac_enc_drc_set_figures(const AacUsacEncoder* u, int drc_set_id, AacLoudnessInfo* out) {
  if (drc_set_id < 1 || drc_set_id > AAC_DRC_GEN_SETS) {
    return 0;
  }
  /* A set's entry carries what the selection and the normalisation
   * read, its program loudness and peaks; the LOAS transport repeats the
   * configuration in every frame, so the other figures stay out. */
  if (u->has_set_loudness[drc_set_id - 1]) {
    *out = u->set_loudness[drc_set_id - 1];
  } else if (u->has_program_loudness) {
    *out = u->program_loudness;
  } else {
    return 0;
  }
  out->has_anchor = 0;
  out->has_range = 0;
  out->has_momentary = 0;
  out->has_short_term = 0;
  if (u->has_set_loudness[drc_set_id - 1]) {
    return 1;
  }
  /* The estimate: the curve is centred on the programme's integrated
   * loudness, which it roughly keeps; the peaks are bounds.  The general
   * set's boost stops at -1 dBTP, so its peak is the programme's or that
   * bound; the night set's limiter holds every peak to its ceiling, which
   * the boost can reach. */
  float ceiling = AAC_DRC_GEN_PEAK_TARGET_DB;
  if (drc_set_id == AAC_DRC_GEN_SET_NIGHT) {
    if (out->integrated_lkfs > -70.0f) {
      ceiling = std::min(ceiling, AAC_DRC_GEN_PEAK_TARGET_DB -
                                      (AAC_DRC_GEN_REFERENCE_LKFS - out->integrated_lkfs));
    }
    out->sample_peak_dbfs = ceiling;
    out->true_peak_dbtp = ceiling;
  } else {
    out->sample_peak_dbfs = std::max(out->sample_peak_dbfs, ceiling);
    out->true_peak_dbtp = std::max(out->true_peak_dbtp, ceiling);
  }
  return 1;
}
