#include "afterburner.h"

#include <algorithm>
#include <cmath>
#include <cstring>

/* Afterburner: a second quantization pass over the frame the rate loop
 * produced.
 *
 * The rate loop lifts every band's noise allowance by the same amount in
 * the fourth-root domain (floored by the band's minimum SNR) until the
 * frame fits, and stops at the first working point that fits.  Two things
 * are left on the table: the frame sits below its budget by whatever the
 * last bisection step and the quantizer's 1.5 dB scalefactor steps leave,
 * and inside the frame the real noise of each band lands anywhere between
 * half its allowance and the allowance itself.
 *
 * This pass measures the real quantization noise of every coded band
 * against the allowance the loop gave it (the masking threshold adapted
 * to the working point), and moves scalefactors one step at a time.  The
 * bands whose noise is within a few dB of their allowance are the worst
 * bands; among them the move that removes the most noise energy per bit
 * is made.  It is paid for by the budget's slack or, when that is spent,
 * by one step coarser quantization in a band whose noise stays within its
 * allowance and well below the worst ratio.  A band the loop dropped
 * (quantized to nothing within its allowance) re-enters the chain at the
 * coarsest scalefactor that keeps a nonzero line; bands the loop left out
 * entirely stay out.  After every move the channel's codebooks are
 * re-sectioned by dynamic programming and its bits re-counted exactly as
 * the bitstream writer will produce them, so no move takes the frame over
 * the budget; a move that would is undone.  Scalefactors stay within 60
 * of their chain neighbours, within the global gain range and within the
 * escape range, and the first noise band's 9-bit value stays valid when
 * the global gain moves.  The pass ends after a bounded number of moves,
 * or when no move in the worst bands fits the budget any more. */

namespace {

constexpr float kQuantRound = 0.4054f;
constexpr int kMaxSf = 255;
constexpr int kMaxSfDelta = 60;
constexpr int kInfBits = 1 << 28;
constexpr int kNumBooks = 16; /* section codebooks 0..15 */
constexpr int kPnsFirstBits = 9;
constexpr int kMaxLines = AAC_FRAME_SIZE_LONG;

/* Applied moves per frame. */
constexpr int kMaxMoves = 64;
/* Bands within this much of the worst noise-to-allowance ratio (log2
 * units; 2.0 is 6 dB) count as the worst bands. */
constexpr float kWorstWindowLog2 = 2.0f;
/* Worst bands examined per move, best noise reduction per bit first. */
constexpr int kCandidates = 6;
/* A band paying for a move keeps its noise within its allowance and its
 * ratio at least this far (log2 units; 2.0 is 6 dB) below the worst. */
constexpr float kDonorCapLog2 = 0.0f;
constexpr float kDonorMarginLog2 = 2.0f;
/* Donors tried per move before the move is given up. */
constexpr int kDonorTries = 4;
/* Ratio of a band that takes no part in the pass. */
constexpr float kIgnoredLog2 = -1.0e9f;

/* Codebook with the smallest alphabet per maximum magnitude, by index. */
const int kCodebookMaxVal[12] = {0, 1, 1, 2, 2, 4, 4, 7, 7, 12, 12, AAC_ESC_MAX_MAG};

float sf_gain(int sf) { return exp2f(0.25f * (float)(sf - 100)); }
float sf_scale34(int sf) { return exp2f(-0.1875f * (float)(sf - 100)); }

/* Per-band bookkeeping of one channel. */
struct ChannelWork {
  AacEncChannel* c;
  int nswb, ngroups, nbands;
  int w0[AAC_MAX_WINDOW_GROUPS]; /* first window of each group */
  int lines[AAC_MAX_BANDS];      /* lines over the group's windows */
  int eligible[AAC_MAX_BANDS];   /* takes part in the pass */
  int coded[AAC_MAX_BANDS];      /* carries nonzero quantized values */
  int sf[AAC_MAX_BANDS];         /* working scalefactor of a coded band */
  int sf_min[AAC_MAX_BANDS];     /* smallest scalefactor within the escape range */
  int sf_zero[AAC_MAX_BANDS];    /* smallest scalefactor that quantizes the band to nothing */
  float noise[AAC_MAX_BANDS];    /* quantization noise energy (the band energy when uncoded) */
  float ratio_log2[AAC_MAX_BANDS]; /* log2(noise / allowance), kIgnoredLog2 when out of the pass */
  int max_mag[AAC_MAX_BANDS];
  int band_bits[AAC_MAX_BANDS][AAC_NUM_CODEBOOKS + 1]; /* spectral bits per book */
  int has_noise_band;
};

/* What a move changes in one band, kept for undoing it. */
struct BandSnapshot {
  int ch, idx;
  int coded, sf, max_mag;
  float noise, ratio_log2;
  int band_bits[AAC_NUM_CODEBOOKS + 1];
  int quant[kMaxLines];
};

/* One candidate scalefactor move. */
struct Move {
  int ch, idx;
  int sf; /* target scalefactor, -1 when the band leaves the chain */
  int max_mag;
  float noise, ratio_log2;
  int local_bits; /* estimated change of the frame's bits */
  float score;
};

struct Frame {
  AacEncoderState* s;
  ChannelWork ch[AAC_MAX_CHANNELS];
  int nch;
  int budget;
  int overhead; /* frame bits outside the channels' section, scalefactor and spectral data */
  int elem_max_sfb[AAC_MAX_ELEMENTS];
  int quant_scratch[kMaxLines]; /* trial quantization of one band */
  Move donors[AAC_MAX_CHANNELS * AAC_MAX_BANDS];
  bool blocked[AAC_MAX_CHANNELS][AAC_MAX_BANDS]; /* no move of the band fits any more */
};

int group_of(const ChannelWork* w, int idx) { return idx / w->nswb; }
int band_of(const ChannelWork* w, int idx) { return idx % w->nswb; }

/* Calls fn(i) for every line of band idx. */
template <typename Fn>
void for_lines(const ChannelWork* w, int idx, Fn fn) {
  const AacIcsInfo* ics = &w->c->ics;
  const int g = group_of(w, idx);
  const int b = band_of(w, idx);
  for (int win = w->w0[g]; win < w->w0[g] + ics->group_len[g]; win++) {
    for (int i = win * 128 + ics->swb_offset[b]; i < win * 128 + ics->swb_offset[b + 1]; i++) {
      fn(i);
    }
  }
}

/* ── band helpers ──────────────────────────────────────────────── */

/* Quantizes band idx with scalefactor sf into q (indexed like the
 * spectrum); returns the largest magnitude. */
int quantize_band(const ChannelWork* w, int idx, int sf, int* q) {
  const float scale = sf_scale34(sf);
  const AacEncChannel* c = w->c;
  int max_mag = 0;
  for_lines(w, idx, [&](int i) {
    int v = (int)(c->x34[i] * scale + kQuantRound);
    if (v > AAC_ESC_MAX_MAG) {
      v = AAC_ESC_MAX_MAG;
    }
    q[i] = c->coef[i] < 0.0f ? -v : v;
    if (v > max_mag) {
      max_mag = v;
    }
  });
  return max_mag;
}

float band_noise(const ChannelWork* w, int idx, int sf, const int* q) {
  const float gain = sf_gain(sf);
  const AacEncChannel* c = w->c;
  float noise = 0.0f;
  for_lines(w, idx, [&](int i) {
    const int v = q[i] < 0 ? -q[i] : q[i];
    const float d = fabsf(c->coef[i]) - aac_pow43[v] * gain;
    noise += d * d;
  });
  return noise;
}

int band_max_mag(const ChannelWork* w, int idx, const int* q) {
  int max_mag = 0;
  for_lines(w, idx, [&](int i) {
    const int v = q[i] < 0 ? -q[i] : q[i];
    if (v > max_mag) {
      max_mag = v;
    }
  });
  return max_mag;
}

/* Spectral bits of band idx of q with every codebook whose range covers
 * max_mag, kInfBits for the others.  A zero band coded with a real book
 * also carries a scalefactor (a zero difference, one bit). */
void count_band_bits(const ChannelWork* w, int idx, const int* q, int max_mag, int* bits) {
  const AacIcsInfo* ics = &w->c->ics;
  const int g = group_of(w, idx);
  const int b = band_of(w, idx);
  bits[0] = kInfBits;
  for (int cb = 1; cb <= AAC_NUM_CODEBOOKS; cb++) {
    if (kCodebookMaxVal[cb] < max_mag) {
      bits[cb] = kInfBits;
      continue;
    }
    const int dim = aac_codebook_info[cb].dim;
    int n = 0;
    for (int win = w->w0[g]; win < w->w0[g] + ics->group_len[g]; win++) {
      for (int i = win * 128 + ics->swb_offset[b]; i < win * 128 + ics->swb_offset[b + 1];
           i += dim) {
        n += aac_huffman_bits(cb, q + i);
      }
    }
    bits[cb] = n + (max_mag == 0 ? 1 : 0);
  }
}

int best_bits(const int* bits) {
  int best = kInfBits;
  for (int cb = 1; cb <= AAC_NUM_CODEBOOKS; cb++) {
    best = std::min(best, bits[cb]);
  }
  return best;
}

float ratio_log2_of(const ChannelWork* w, int idx, float noise) {
  return log2f(fmaxf(noise, 1e-30f) / fmaxf(w->c->allowed[idx], 1e-30f));
}

/* Smallest scalefactor whose quantized magnitudes stay within the escape
 * range, and the smallest one that quantizes the whole band to zero (one
 * beyond the coarsest scalefactor that keeps a nonzero line). */
void scalefactor_limits(const ChannelWork* w, int idx, int* sf_min, int* sf_zero) {
  float max34 = 0.0f;
  for_lines(w, idx, [&](int i) { max34 = fmaxf(max34, w->c->x34[i]); });
  if (max34 <= 0.0f) {
    *sf_min = 0;
    *sf_zero = 0;
    return;
  }
  *sf_min = std::clamp(
      (int)ceilf(100.0f - (16.0f / 3.0f) * log2f(((float)AAC_ESC_MAX_MAG - 0.5f) / max34)), 0,
      kMaxSf);
  /* A line stays nonzero while x34 * scale + rounding reaches 1; the
   * floating-point edge is settled by quantizing. */
  int sf = (int)floorf(100.0f + (16.0f / 3.0f) * log2f(max34 / (1.0f - kQuantRound))) + 1;
  sf = std::clamp(sf, 0, kMaxSf + 1);
  int q[kMaxLines];
  while (sf > 0 && quantize_band(w, idx, sf - 1, q) == 0) {
    sf--;
  }
  while (sf <= kMaxSf && quantize_band(w, idx, sf, q) != 0) {
    sf++;
  }
  *sf_zero = sf;
}

/* ── exact bit count of a channel ───────────────────────────────── */

/* Sections one group below max_sfb by dynamic programming over the band
 * codebook costs, writes the chosen books to cb_out and returns the
 * section and spectral bits (ISO/IEC 14496-3 4.6.3, Table 4.46).  A band
 * with nonzero values may use any book whose range covers its maximum, a
 * zero band may also use ZERO_HCB, and a band of a coding tool keeps its
 * book and carries no spectral data. */
int section_group(const ChannelWork* w, int g, int max_sfb, int* cb_out) {
  const AacIcsInfo* ics = &w->c->ics;
  const int run_bits = ics->num_windows == 8 ? 3 : 5;
  const int header = 4 + run_bits;
  int cost[AAC_MAX_SFB_LONG][kNumBooks];
  int from[AAC_MAX_SFB_LONG][kNumBooks];

  for (int b = 0; b < max_sfb; b++) {
    const int idx = g * w->nswb + b;
    const int tool = w->c->tool[idx];
    const int max_mag = tool ? 0 : w->max_mag[idx];
    for (int cb = 0; cb < kNumBooks; cb++) {
      cost[b][cb] = kInfBits;
      from[b][cb] = -1;
      if (tool ? cb != tool
               : (cb > AAC_NUM_CODEBOOKS ||
                  (cb == 0 ? max_mag != 0 : kCodebookMaxVal[cb] < max_mag))) {
        continue;
      }
      const int band = cb == 0 || tool ? 0 : w->band_bits[idx][cb];
      if (b == 0) {
        cost[b][cb] = header + band;
        continue;
      }
      for (int prev = 0; prev < kNumBooks; prev++) {
        if (cost[b - 1][prev] >= kInfBits) {
          continue;
        }
        const int total = cost[b - 1][prev] + (prev == cb ? 0 : header) + band;
        if (total < cost[b][cb]) {
          cost[b][cb] = total;
          from[b][cb] = prev;
        }
      }
    }
  }
  if (max_sfb == 0) {
    return 0;
  }
  int best = 0;
  for (int cb = 1; cb < kNumBooks; cb++) {
    if (cost[max_sfb - 1][cb] < cost[max_sfb - 1][best]) {
      best = cb;
    }
  }
  int bits = cost[max_sfb - 1][best];
  for (int b = max_sfb - 1; b >= 0; b--) {
    cb_out[b] = best;
    best = from[b][best];
  }
  /* Runs at or beyond the escape length need extra run words. */
  const int esc = (1 << run_bits) - 1;
  for (int b = 0; b < max_sfb;) {
    int run = 1;
    while (b + run < max_sfb && cb_out[b + run] == cb_out[b]) {
      run++;
    }
    bits += (run / esc) * run_bits;
    b += run;
  }
  return bits;
}

/* Highest band + 1 that carries data or a coding tool, over all groups. */
int channel_max_sfb(const ChannelWork* w) {
  int max_sfb = 0;
  for (int idx = 0; idx < w->nbands; idx++) {
    const int b = band_of(w, idx);
    if ((w->coded[idx] || w->c->tool[idx]) && b + 1 > max_sfb) {
      max_sfb = b + 1;
    }
  }
  return max_sfb;
}

/* Writes the channel's cb, sf and global_gain for the element's max_sfb,
 * as the bitstream writer expects them, and returns the section,
 * scalefactor and spectral bits.  Scalefactors, noise energies and
 * intensity positions form three separate DPCM chains; a zero band that
 * sectioning put under a real book repeats its predecessor's scalefactor. */
int finish_channel(ChannelWork* w, int max_sfb) {
  AacEncChannel* c = w->c;
  int bits = 0;
  for (int idx = 0; idx < w->nbands; idx++) {
    c->cb[idx] = c->tool[idx];
    c->sf[idx] = c->tool[idx] ? c->tool_sf[idx] : (w->coded[idx] ? w->sf[idx] : -1);
  }
  for (int g = 0; g < w->ngroups; g++) {
    bits += section_group(w, g, max_sfb, c->cb + g * w->nswb);
    for (int b = max_sfb; b < w->nswb; b++) {
      c->cb[g * w->nswb + b] = 0;
    }
  }
  int first_coded = -1;
  for (int idx = 0; idx < w->nbands; idx++) {
    if (w->coded[idx] && band_of(w, idx) < max_sfb) {
      first_coded = idx;
      break;
    }
  }
  c->global_gain = first_coded >= 0 ? w->sf[first_coded] : 100;
  int prev = c->global_gain;
  int prev_noise = 0;
  int prev_is = 0;
  bool first_noise = true;
  for (int g = 0; g < w->ngroups; g++) {
    for (int b = 0; b < max_sfb; b++) {
      const int idx = g * w->nswb + b;
      const int cb = c->cb[idx];
      if (cb == 0) {
        continue;
      }
      if (cb == AAC_CB_NOISE) {
        if (first_noise) {
          bits += kPnsFirstBits;
          first_noise = false;
        } else {
          bits += aac_scalefactor_bits[c->sf[idx] - prev_noise + AAC_SCALE_DIFF_ZERO];
        }
        prev_noise = c->sf[idx];
        continue;
      }
      if (cb == AAC_CB_INTENSITY || cb == AAC_CB_INTENSITY2) {
        bits += aac_scalefactor_bits[c->sf[idx] - prev_is + AAC_SCALE_DIFF_ZERO];
        prev_is = c->sf[idx];
        continue;
      }
      if (c->sf[idx] < 0) {
        c->sf[idx] = prev;
      }
      bits += aac_scalefactor_bits[c->sf[idx] - prev + AAC_SCALE_DIFF_ZERO];
      prev = c->sf[idx];
    }
  }
  return bits;
}

/* Re-sections and re-counts every channel for the current state and
 * returns the frame size in bits.  The M/S mask of a pair carries one bit
 * per group band below the element's max_sfb, which may have moved. */
int recount_frame(Frame* f) {
  int total = f->overhead;
  for (int e = 0; e < f->s->num_elements; e++) {
    const AacEncElement* el = &f->s->elem[e];
    int max_sfb = 0;
    for (int k = 0; k < el->num_ch; k++) {
      max_sfb = std::max(max_sfb, channel_max_sfb(&f->ch[el->ch[k]]));
    }
    if (el->type == AAC_ELEM_CPE) {
      total += f->ch[el->ch[0]].ngroups * (max_sfb - f->elem_max_sfb[e]);
    }
    for (int k = 0; k < el->num_ch; k++) {
      total += finish_channel(&f->ch[el->ch[k]], max_sfb);
    }
  }
  return total;
}

/* Makes the elements' max_sfb of the current state the committed one. */
void commit_max_sfb(Frame* f) {
  for (int e = 0; e < f->s->num_elements; e++) {
    AacEncElement* el = &f->s->elem[e];
    int max_sfb = 0;
    for (int k = 0; k < el->num_ch; k++) {
      max_sfb = std::max(max_sfb, channel_max_sfb(&f->ch[el->ch[k]]));
    }
    if (el->type == AAC_ELEM_CPE) {
      f->overhead += f->ch[el->ch[0]].ngroups * (max_sfb - f->elem_max_sfb[e]);
    }
    f->elem_max_sfb[e] = max_sfb;
    el->max_sfb = max_sfb;
  }
}

/* ── chain constraints ─────────────────────────────────────────── */

/* The coded band before idx in the scalefactor chain, or -1. */
int chain_prev(const ChannelWork* w, int idx) {
  for (int i = idx - 1; i >= 0; i--) {
    if (w->coded[i]) {
      return i;
    }
  }
  return -1;
}

int chain_next(const ChannelWork* w, int idx) {
  for (int i = idx + 1; i < w->nbands; i++) {
    if (w->coded[i]) {
      return i;
    }
  }
  return -1;
}

/* Whether the first noise band's 9-bit value, a difference to the global
 * gain, still fits when the global gain becomes global_gain. */
bool noise_value_fits(const ChannelWork* w, int global_gain) {
  if (!w->has_noise_band) {
    return true;
  }
  for (int i = 0; i < w->nbands; i++) {
    if (w->c->tool[i] == AAC_CB_NOISE) {
      const int v = w->c->tool_sf[i] - (global_gain - 90) + 256;
      return v >= 0 && v <= 511;
    }
  }
  return true;
}

/* Whether band idx may carry scalefactor sf (or leave the chain when sf
 * < 0) next to its chain neighbours. */
bool chain_allows(const ChannelWork* w, int idx, int sf) {
  const int prev = chain_prev(w, idx);
  const int next = chain_next(w, idx);
  if (sf < 0) {
    if (prev >= 0 && next >= 0) {
      return std::abs(w->sf[next] - w->sf[prev]) <= kMaxSfDelta;
    }
    /* The successor leads the chain and becomes the global gain. */
    return prev >= 0 || next < 0 || noise_value_fits(w, w->sf[next]);
  }
  if (sf > kMaxSf) {
    return false;
  }
  if (prev >= 0 && std::abs(sf - w->sf[prev]) > kMaxSfDelta) {
    return false;
  }
  if (next >= 0 && std::abs(w->sf[next] - sf) > kMaxSfDelta) {
    return false;
  }
  return prev >= 0 || noise_value_fits(w, sf);
}

/* Scalefactor bits of band idx at sf against its chain neighbours (both
 * differences), or of the chain without it when sf < 0. */
int chain_bits(const ChannelWork* w, int idx, int sf) {
  const int prev = chain_prev(w, idx);
  const int next = chain_next(w, idx);
  if (sf < 0) {
    return prev >= 0 && next >= 0
               ? aac_scalefactor_bits[w->sf[next] - w->sf[prev] + AAC_SCALE_DIFF_ZERO]
               : 0;
  }
  int bits = 0;
  if (prev >= 0) {
    bits += aac_scalefactor_bits[sf - w->sf[prev] + AAC_SCALE_DIFF_ZERO];
  }
  if (next >= 0) {
    bits += aac_scalefactor_bits[w->sf[next] - sf + AAC_SCALE_DIFF_ZERO];
  }
  return bits;
}

/* ── moves ─────────────────────────────────────────────────────── */

void snapshot(const Frame* f, int ch, int idx, BandSnapshot* snap) {
  const ChannelWork* w = &f->ch[ch];
  snap->ch = ch;
  snap->idx = idx;
  snap->coded = w->coded[idx];
  snap->sf = w->sf[idx];
  snap->max_mag = w->max_mag[idx];
  snap->noise = w->noise[idx];
  snap->ratio_log2 = w->ratio_log2[idx];
  memcpy(snap->band_bits, w->band_bits[idx], sizeof(snap->band_bits));
  int n = 0;
  for_lines(w, idx, [&](int i) { snap->quant[n++] = w->c->quant[i]; });
}

void restore(Frame* f, const BandSnapshot* snap) {
  ChannelWork* w = &f->ch[snap->ch];
  const int idx = snap->idx;
  w->coded[idx] = snap->coded;
  w->sf[idx] = snap->sf;
  w->max_mag[idx] = snap->max_mag;
  w->noise[idx] = snap->noise;
  w->ratio_log2[idx] = snap->ratio_log2;
  memcpy(w->band_bits[idx], snap->band_bits, sizeof(snap->band_bits));
  int n = 0;
  for_lines(w, idx, [&](int i) { w->c->quant[i] = snap->quant[n++]; });
}

/* Puts band idx at scalefactor sf with the quantization in
 * f->quant_scratch, or takes it out of the chain when max_mag is 0. */
void apply_band(Frame* f, int ch, int idx, int sf, int max_mag) {
  ChannelWork* w = &f->ch[ch];
  const int* q = f->quant_scratch;
  for_lines(w, idx, [&](int i) { w->c->quant[i] = q[i]; });
  w->max_mag[idx] = max_mag;
  count_band_bits(w, idx, w->c->quant, max_mag, w->band_bits[idx]);
  if (max_mag == 0) {
    w->coded[idx] = 0;
    w->sf[idx] = -1;
    w->noise[idx] = w->c->energy[idx];
  } else {
    w->coded[idx] = 1;
    w->sf[idx] = sf;
    w->noise[idx] = band_noise(w, idx, sf, q);
  }
  w->ratio_log2[idx] = ratio_log2_of(w, idx, w->noise[idx]);
}

/* Evaluates moving band idx of channel ch to scalefactor sf: fills the
 * move, leaves the trial quantization in f->quant_scratch, and returns
 * whether the chain allows it.  The bit estimate counts the band's
 * cheapest book and its scalefactor differences; the exact count after
 * sectioning decides. */
bool evaluate_move(Frame* f, int ch, int idx, int sf, Move* m) {
  ChannelWork* w = &f->ch[ch];
  if (!chain_allows(w, idx, sf)) {
    return false;
  }
  m->ch = ch;
  m->idx = idx;
  m->max_mag = quantize_band(w, idx, sf, f->quant_scratch);
  const int old_bits = w->coded[idx] ? best_bits(w->band_bits[idx]) + chain_bits(w, idx, w->sf[idx])
                                     : chain_bits(w, idx, -1);
  if (m->max_mag == 0) {
    if (!chain_allows(w, idx, -1)) {
      return false;
    }
    m->sf = -1;
    m->noise = w->c->energy[idx];
    m->local_bits = chain_bits(w, idx, -1) - old_bits;
  } else {
    int trial_bits[AAC_NUM_CODEBOOKS + 1];
    m->sf = sf;
    m->noise = band_noise(w, idx, sf, f->quant_scratch);
    count_band_bits(w, idx, f->quant_scratch, m->max_mag, trial_bits);
    m->local_bits = best_bits(trial_bits) + chain_bits(w, idx, sf) - old_bits;
  }
  m->ratio_log2 = ratio_log2_of(w, idx, m->noise);
  return true;
}

/* Applies a move and counts the frame exactly: returns whether it still
 * fits the budget, with the band restored from snap when it does not. */
bool try_move(Frame* f, const Move* m, BandSnapshot* snap, int* frame_bits) {
  const int sf = m->sf < 0 ? f->ch[m->ch].sf[m->idx] + 1 : m->sf;
  const int max_mag = quantize_band(&f->ch[m->ch], m->idx, sf, f->quant_scratch);
  snapshot(f, m->ch, m->idx, snap);
  apply_band(f, m->ch, m->idx, sf, max_mag);
  const int total = recount_frame(f);
  if (total <= f->budget) {
    *frame_bits = total;
    commit_max_sfb(f);
    return true;
  }
  restore(f, snap);
  return false;
}

/* The worst noise-to-allowance ratio of the frame, in log2 units. */
float worst_ratio(const Frame* f) {
  float worst = kIgnoredLog2;
  for (int ch = 0; ch < f->nch; ch++) {
    for (int idx = 0; idx < f->ch[ch].nbands; idx++) {
      worst = fmaxf(worst, f->ch[ch].ratio_log2[idx]);
    }
  }
  return worst;
}

/* Collects the worst bands' refining moves, best noise reduction per bit
 * first; returns how many. */
int collect_candidates(Frame* f, float worst, Move* cands) {
  int ncand = 0;
  for (int ch = 0; ch < f->nch; ch++) {
    ChannelWork* w = &f->ch[ch];
    for (int idx = 0; idx < w->nbands; idx++) {
      if (!w->eligible[idx] || f->blocked[ch][idx] ||
          w->ratio_log2[idx] < worst - kWorstWindowLog2) {
        continue;
      }
      const int sf = w->coded[idx] ? w->sf[idx] - 1 : w->sf_zero[idx] - 1;
      if (sf < w->sf_min[idx] || sf < 0) {
        continue;
      }
      Move m;
      if (!evaluate_move(f, ch, idx, sf, &m) || m.max_mag == 0 || m.noise >= w->noise[idx]) {
        continue;
      }
      m.score = (w->noise[idx] - m.noise) / (float)std::max(m.local_bits, 1);
      int pos = ncand;
      while (pos > 0 && cands[pos - 1].score < m.score) {
        pos--;
      }
      if (pos < kCandidates) {
        for (int i = std::min(ncand, kCandidates - 1); i > pos; i--) {
          cands[i] = cands[i - 1];
        }
        cands[pos] = m;
        ncand = std::min(ncand + 1, kCandidates);
      }
    }
  }
  return ncand;
}

/* Collects the coarsening moves that could pay for the move just made:
 * bands other than the moved one whose noise stays within their
 * allowance and well below the worst ratio, most bits saved per noise
 * added first; returns how many. */
int collect_donors(Frame* f, const Move* made, Move* donors) {
  const float worst = worst_ratio(f);
  int ndonor = 0;
  for (int ch = 0; ch < f->nch; ch++) {
    ChannelWork* w = &f->ch[ch];
    for (int idx = 0; idx < w->nbands; idx++) {
      if (!w->eligible[idx] || !w->coded[idx] || (ch == made->ch && idx == made->idx)) {
        continue;
      }
      Move d;
      if (!evaluate_move(f, ch, idx, w->sf[idx] + 1, &d) || d.local_bits >= 0 ||
          d.ratio_log2 > kDonorCapLog2 || d.ratio_log2 > worst - kDonorMarginLog2) {
        continue;
      }
      d.score = (float)(-d.local_bits) / fmaxf(d.noise - w->noise[idx], 1e-9f);
      donors[ndonor++] = d;
    }
  }
  std::sort(donors, donors + ndonor, [](const Move& a, const Move& b) { return a.score > b.score; });
  return ndonor;
}

/* ── setup ─────────────────────────────────────────────────────── */

void init_channel(Frame* f, int ch) {
  ChannelWork* w = &f->ch[ch];
  AacEncChannel* c = &f->s->ch[ch];
  const AacIcsInfo* ics = &c->ics;
  w->c = c;
  w->nswb = ics->num_swb;
  w->ngroups = ics->num_window_groups;
  w->nbands = w->ngroups * w->nswb;
  const int active = ics->num_windows == 8 ? c->active_short : c->active_long;
  int win = 0;
  for (int g = 0; g < w->ngroups; g++) {
    w->w0[g] = win;
    win += ics->group_len[g];
  }
  w->has_noise_band = 0;
  for (int idx = 0; idx < w->nbands; idx++) {
    const int g = group_of(w, idx);
    const int b = band_of(w, idx);
    w->lines[idx] = ics->group_len[g] * (ics->swb_offset[b + 1] - ics->swb_offset[b]);
    if (c->tool[idx] == AAC_CB_NOISE) {
      w->has_noise_band = 1;
    }
    w->max_mag[idx] = c->tool[idx] ? 0 : band_max_mag(w, idx, c->quant);
    w->coded[idx] = w->max_mag[idx] > 0;
    w->sf[idx] = w->coded[idx] ? c->sf[idx] : -1;
    /* A band takes part when the rate loop gave it an allowance: below
     * the lowpass, not under a coding tool, and neither masked nor
     * released. */
    w->eligible[idx] = !c->tool[idx] && b < active && c->allowed[idx] >= 0.0f;
    w->sf_min[idx] = 0;
    w->sf_zero[idx] = 0;
    if (w->eligible[idx]) {
      scalefactor_limits(w, idx, &w->sf_min[idx], &w->sf_zero[idx]);
    }
    w->noise[idx] = w->coded[idx] ? band_noise(w, idx, w->sf[idx], c->quant) : c->energy[idx];
    w->ratio_log2[idx] = w->eligible[idx] ? ratio_log2_of(w, idx, w->noise[idx]) : kIgnoredLog2;
    if (c->tool[idx]) {
      for (int cb = 0; cb <= AAC_NUM_CODEBOOKS; cb++) {
        w->band_bits[idx][cb] = kInfBits;
      }
    } else {
      count_band_bits(w, idx, c->quant, w->max_mag[idx], w->band_bits[idx]);
    }
  }
}

}  // namespace

void aac_afterburner_run(AacEncoderState* s, int bits, int budget) {
  if (bits > budget) {
    return;
  }
  /* Too large for the stack; one per thread, so that encoders running in
   * different threads never share it. */
  static thread_local Frame frame;
  Frame* f = &frame;
  f->s = s;
  f->nch = s->channels;
  f->budget = budget;
  memset(f->blocked, 0, sizeof(f->blocked));
  for (int e = 0; e < s->num_elements; e++) {
    f->elem_max_sfb[e] = s->elem[e].max_sfb;
    for (int k = 0; k < s->elem[e].num_ch; k++) {
      init_channel(f, s->elem[e].ch[k]);
    }
  }
  /* The overhead is whatever the rate loop counted beyond the channels'
   * own bits, which this pass counts by the same rules. */
  f->overhead = bits;
  for (int e = 0; e < s->num_elements; e++) {
    for (int k = 0; k < s->elem[e].num_ch; k++) {
      f->overhead -= finish_channel(&f->ch[s->elem[e].ch[k]], f->elem_max_sfb[e]);
    }
  }
  if (f->overhead < 0) {
    return;
  }

  int frame_bits = bits;
  for (int move = 0; move < kMaxMoves; move++) {
    const float worst = worst_ratio(f);
    if (worst <= kIgnoredLog2) {
      break;
    }
    Move cands[kCandidates];
    const int ncand = collect_candidates(f, worst, cands);
    bool applied = false;
    for (int ci = 0; ci < ncand && !applied; ci++) {
      const Move m = cands[ci];
      BandSnapshot snap;
      if (try_move(f, &m, &snap, &frame_bits)) {
        applied = true;
        break;
      }
      /* Over the budget: hold the move and look for a band that pays for
       * it; without one the band is left as it was. */
      snapshot(f, m.ch, m.idx, &snap);
      quantize_band(&f->ch[m.ch], m.idx, m.sf, f->quant_scratch);
      apply_band(f, m.ch, m.idx, m.sf, m.max_mag);
      const int ndonor = collect_donors(f, &m, f->donors);
      for (int di = 0; di < ndonor && di < kDonorTries; di++) {
        BandSnapshot dsnap;
        if (try_move(f, &f->donors[di], &dsnap, &frame_bits)) {
          applied = true;
          break;
        }
      }
      if (!applied) {
        restore(f, &snap);
        f->blocked[m.ch][m.idx] = true;
      }
    }
    if (!applied) {
      break;
    }
  }
  /* Leave cb, sf, global_gain and max_sfb in the state of the last
   * accepted frame. */
  (void)frame_bits;
  recount_frame(f);
  commit_max_sfb(f);
}
