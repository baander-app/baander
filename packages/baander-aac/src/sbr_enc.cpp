#include "sbr_enc.h"

#include <algorithm>
#include <cstdlib>
#include <cmath>
#include <cstring>
#include <mutex>

#include "mps212_enc.h"
#include "sbr_enc_internal.h"

/* SBR encoder (ISO/IEC 14496-3 4.6.18, 3GPP TS 26.404).  The frame the
 * core encoder emits for an input block decodes two blocks later, so the
 * extension of a frame describes the QMF slots of the block before the
 * previous one, shifted by the offset at which the decoder's analysis of
 * the core output lines up with the encoder's analysis of the input.
 * Every analysis slot is kept in a ring, which also gives the transient
 * detector and the envelope grid their lookahead. */

namespace {

constexpr int kSlots = AAC_SBR_QMF_SLOTS; /* largest frame, for buffers */
constexpr int kRing = AAC_SBR_ENC_RING;
constexpr int kRingMask = kRing - 1;
/* Blocks between an input block and the frame that decodes it. */
constexpr int kCoreDelayBlocks = 2;
/* Analysis slot of a block that the decoder's first output slot of the
 * frame coincides with. */
constexpr int kGridOffset = -13;
constexpr int kNoiseFloorOffset = 6;
constexpr int kExtensionSbr = 13;
constexpr int kExtensionPs = 2;

/* ── tuning (3GPP TS 26.404 Annex, as the FDK encoder) ─────────── */

struct Tuning {
  int core_rate;
  int channels;
  int bitrate_from, bitrate_to;
  int start_freq, stop_freq;
  int noise_bands;
  int noise_max; /* 6: none, 3: a half, -3: an eighth of the signal energy */
  int freq_scale;
};

/* Three stereo rows at a 24 kHz core differ from that table.  They stop
 * SBR higher, near Apple's 20.3 kHz at 48 kbit/s, with two noise bands:
 * 40 kbit/s stop 10, 48 kbit/s stop 11 and 56 kbit/s stop 11.  Measured
 * PEAQ gains over the table's rows, ten items, paired 95 % intervals:
 * +0.13 ± 0.05 at 40, +0.36 ± 0.15 at 48 and +0.28 ± 0.08 at 56; ViSQOL
 * +0.10, +0.15 and +0.02. */
const Tuning kTuning[] = {
    /* mono */
    {16000, 1, 8000, 10000, 1, 0, 1, 6, 3},     {16000, 1, 10000, 12000, 2, 6, 1, 6, 3},
    {16000, 1, 12000, 16000, 4, 6, 1, 6, 3},    {16000, 1, 16000, 18000, 4, 8, 1, 6, 3},
    {16000, 1, 18000, 22000, 6, 11, 2, 6, 2},   {16000, 1, 22000, 28000, 10, 12, 2, 6, 2},
    {16000, 1, 28000, 36000, 12, 13, 2, 3, 2},  {16000, 1, 36000, 44000, 14, 13, 2, 3, 1},
    {16000, 1, 44000, 64001, 14, 13, 2, 3, 1},
    {22050, 1, 11369, 16000, 3, 4, 1, 6, 3},    {22050, 1, 16000, 18000, 3, 5, 1, 6, 3},
    {22050, 1, 18000, 22000, 4, 8, 2, 6, 2},    {22050, 1, 22000, 28000, 7, 8, 2, 6, 2},
    {22050, 1, 28000, 36000, 10, 9, 2, 3, 2},   {22050, 1, 36000, 44000, 11, 10, 2, 3, 1},
    {22050, 1, 44000, 64001, 13, 12, 2, 3, 1},
    {24000, 1, 12000, 16000, 3, 4, 1, 6, 3},    {24000, 1, 16000, 18000, 3, 5, 1, 6, 3},
    {24000, 1, 18000, 22000, 4, 8, 2, 6, 2},    {24000, 1, 22000, 28000, 7, 8, 2, 6, 2},
    {24000, 1, 28000, 36000, 10, 9, 2, 3, 2},   {24000, 1, 36000, 44000, 11, 10, 2, 3, 1},
    {24000, 1, 44000, 64001, 13, 11, 2, 3, 1},
    {32000, 1, 24000, 36000, 4, 4, 2, 3, 3},    {32000, 1, 36000, 60000, 7, 6, 2, 3, 2},
    {32000, 1, 60000, 72000, 9, 8, 2, 3, 1},    {32000, 1, 72000, 100000, 11, 10, 2, 3, 1},
    {32000, 1, 100000, 160001, 13, 11, 2, 3, 1},
    {44100, 1, 24000, 36000, 4, 4, 2, 3, 3},    {44100, 1, 36000, 60000, 7, 6, 2, 3, 2},
    {44100, 1, 60000, 72000, 9, 8, 2, 3, 1},    {44100, 1, 72000, 100000, 11, 10, 2, 3, 1},
    {44100, 1, 100000, 160001, 13, 11, 2, 3, 1},
    {48000, 1, 32000, 36000, 4, 9, 2, 3, 3},    {48000, 1, 36000, 60000, 7, 10, 2, 3, 2},
    {48000, 1, 60000, 72000, 9, 10, 2, 3, 1},   {48000, 1, 72000, 100000, 11, 11, 2, 3, 1},
    {48000, 1, 100000, 160001, 13, 11, 2, 3, 1},
    /* stereo */
    {16000, 2, 16000, 24000, 4, 1, 1, -3, 3},   {16000, 2, 24000, 28000, 8, 10, 1, -3, 3},
    {16000, 2, 28000, 36000, 10, 12, 2, -3, 2}, {16000, 2, 36000, 44000, 13, 13, 2, -3, 2},
    {16000, 2, 44000, 52000, 14, 13, 2, -3, 2}, {16000, 2, 52000, 60000, 14, 13, 3, -3, 1},
    {16000, 2, 60000, 76000, 14, 13, 3, -3, 1}, {16000, 2, 76000, 128001, 14, 13, 3, -3, 1},
    {22050, 2, 16000, 24000, 2, 1, 1, -3, 3},   {22050, 2, 24000, 28000, 5, 6, 1, -3, 3},
    {22050, 2, 28000, 32000, 5, 8, 2, -3, 2},   {22050, 2, 32000, 36000, 7, 8, 2, -3, 2},
    {22050, 2, 36000, 44000, 10, 9, 2, -3, 2},  {22050, 2, 44000, 52000, 12, 9, 3, -3, 2},
    {22050, 2, 52000, 60000, 13, 10, 3, -3, 1}, {22050, 2, 60000, 76000, 14, 12, 3, -3, 1},
    {22050, 2, 76000, 128001, 14, 12, 3, -3, 1},
    {24000, 2, 16000, 24000, 2, 1, 1, -3, 3},   {24000, 2, 24000, 28000, 5, 6, 1, -3, 3},
    {24000, 2, 28000, 36000, 7, 8, 2, -3, 2},   {24000, 2, 36000, 44000, 10, 10, 2, -3, 2},
    {24000, 2, 44000, 52000, 12, 11, 2, -3, 2}, {24000, 2, 52000, 60000, 13, 11, 2, -3, 1},
    {24000, 2, 60000, 76000, 14, 12, 3, -3, 1}, {24000, 2, 76000, 128001, 14, 12, 3, -3, 1},
    {32000, 2, 32000, 60000, 4, 4, 2, -3, 3},   {32000, 2, 60000, 80000, 7, 6, 3, -3, 2},
    {32000, 2, 80000, 112000, 9, 8, 3, -3, 1},  {32000, 2, 112000, 144000, 11, 10, 3, -3, 1},
    {32000, 2, 144000, 256001, 13, 11, 3, -3, 1},
    {44100, 2, 32000, 60000, 4, 4, 2, -3, 3},   {44100, 2, 60000, 80000, 7, 6, 3, -3, 2},
    {44100, 2, 80000, 112000, 9, 8, 3, -3, 1},  {44100, 2, 112000, 144000, 11, 10, 3, -3, 1},
    {44100, 2, 144000, 256001, 13, 11, 3, -3, 1},
    {48000, 2, 36000, 60000, 4, 9, 2, -3, 3},   {48000, 2, 60000, 80000, 7, 9, 3, -3, 2},
    {48000, 2, 80000, 112000, 9, 10, 3, -3, 1}, {48000, 2, 112000, 144000, 11, 11, 3, -3, 1},
    {48000, 2, 144000, 256001, 13, 11, 3, -3, 1},
};

/* The row for a core rate and channel count whose bitrate range holds
 * the bitrate, or the nearest range. */
const Tuning* find_tuning(int core_rate, int channels, int bitrate) {
  const Tuning* best = nullptr;
  int best_distance = 0;
  for (const Tuning& t : kTuning) {
    if (t.core_rate != core_rate || t.channels != channels) {
      continue;
    }
    int distance = 0;
    if (bitrate < t.bitrate_from) {
      distance = t.bitrate_from - bitrate;
    } else if (bitrate >= t.bitrate_to) {
      distance = bitrate - t.bitrate_to + 1;
    }
    if (!best || distance < best_distance) {
      best = &t;
      best_distance = distance;
    }
  }
  return best;
}

/* ── Huffman codes from the decoder's trees ────────────────────── */

struct HuffCode {
  uint32_t code[128]; /* indexed by value + 64 */
  uint8_t len[128];
  int lav; /* largest absolute value */
};

HuffCode g_env_15_t, g_env_15_f, g_env_30_t, g_env_30_f, g_noise_30_t;

void walk(const int8_t (*tree)[2], int node, uint32_t code, int len, HuffCode* out) {
  for (int bit = 0; bit < 2; bit++) {
    const int next = tree[node][bit];
    const uint32_t c = (code << 1) | (uint32_t)bit;
    if (next < 0) {
      const int value = next + 64;
      out->code[value + 64] = c;
      out->len[value + 64] = (uint8_t)(len + 1);
      out->lav = std::max(out->lav, std::abs(value));
    } else {
      walk(tree, next, c, len + 1, out);
    }
  }
}

void build_code_tables(void) {
  walk(aac_sbr_huff_env_level_15_t, 0, 0, 0, &g_env_15_t);
  walk(aac_sbr_huff_env_level_15_f, 0, 0, 0, &g_env_15_f);
  walk(aac_sbr_huff_env_level_30_t, 0, 0, 0, &g_env_30_t);
  walk(aac_sbr_huff_env_level_30_f, 0, 0, 0, &g_env_30_f);
  walk(aac_sbr_huff_noise_level_30_t, 0, 0, 0, &g_noise_30_t);
}

void build_codes(void) {
  static std::once_flag once;
  std::call_once(once, build_code_tables);
}

int code_bits(const HuffCode* h, int value) { return h->len[value + 64]; }

void write_code(AacBitWriter* w, const HuffCode* h, int value) {
  aac_bitwriter_write(w, h->code[value + 64], h->len[value + 64]);
}

/* ── ring access ───────────────────────────────────────────────── */

inline const float (*slot(const AacSbrEncChannel* ch, int t))[2] { return ch->W[t & kRingMask]; }

/* ── transient detection ───────────────────────────────────────── */

/* The slot of the strongest transient in [t0, t0 + slots), or -1: a slot
 * whose high-band energy exceeds the mean of the eight before it by far
 * and is not negligible against the recent peak. */
int detect_transient(const AacSbrEncChannel* ch, int t0, int slots) {
  const float* E = ch->hf_energy;
  float peak = 0.0f;
  for (int t = t0 - slots; t < t0 + slots; t++) {
    peak = std::max(peak, E[t & kRingMask]);
  }
  if (peak <= 1e-6f) {
    return -1;
  }
  int best = -1;
  float best_ratio = 0.0f;
  for (int t = t0; t < t0 + slots; t++) {
    float past = 0.0f;
    for (int j = 1; j <= 8; j++) {
      past += E[(t - j) & kRingMask];
    }
    past *= 0.125f;
    const float e = E[t & kRingMask];
    const float ratio = e / (past + 1e-3f * peak);
    if (ratio > 8.0f && e > 0.05f * peak && ratio > best_ratio) {
      best = t;
      best_ratio = ratio;
    }
  }
  return best;
}

/* ── envelope grid (4.6.18.3.3) ────────────────────────────────── */

const int8_t kCeilLog2[] = {0, 1, 2, 2, 3, 3};

/* Splits an even length into relative borders of 2 to 8 slots. */
int split_rels(int length, int* rels, int max_rels) {
  int n = 0;
  while (length > 0 && n < max_rels) {
    const int r = std::min(8, length);
    rels[n++] = r;
    length -= r;
  }
  return n;
}

/* Builds the grid of a frame of `slots` envelope time slots (16 or 15)
 * that starts at envelope slot `start` (carried over from the previous
 * frame), with a transient at envelope slot `tran` (or -1).  The
 * envelope of a transient spans four slots from it; a transient near the
 * frame end stretches the envelope into the next frame. */
void make_grid(AacSbrGrid* g, int start, int tran, int amp_res_header, int slots) {
  memset(g, 0, sizeof(*g));
  g->tran_env = -1;
  g->short_env = -1;
  g->bs_pointer = 0;
  /* Relative borders are even, so the end border takes the parity of
   * the transient slot the borders are counted back from. */
  int end = slots;
  if (tran >= 0) {
    end = std::max(tran + 4, slots + ((tran + slots) & 1)); /* slots .. slots + 3 */
  }
  if (tran >= 0) {
    /* trailing part after the transient envelope */
    const int tail = end - (tran + 4);
    int rels[3];
    /* at most three relative borders, one of them the transient's */
    const int num_trail = tail > 0 ? split_rels(tail, rels, tran > start ? 2 : 3) : 0;
    /* borders from the end: the trail rels, then the transient envelope */
    g->num_rel_trail = 0;
    for (int i = num_trail - 1; i >= 0; i--) {
      g->rel_trail[g->num_rel_trail++] = rels[i];
    }
    if (tran > start) {
      g->rel_trail[g->num_rel_trail++] = 4;
      g->tran_env = 1;
    } else {
      g->tran_env = 0;
    }
    g->num_env = g->num_rel_trail + 1;
    if (start == 0) {
      g->frame_class = 1; /* FIXVAR */
    } else {
      g->frame_class = 3; /* VARVAR */
      g->abs_bord_0 = start;
    }
    g->abs_bord_1 = end;
    g->t_env[0] = start;
    g->t_env[g->num_env] = end;
    for (int i = 0; i < g->num_rel_trail; i++) {
      g->t_env[g->num_env - 1 - i] = g->t_env[g->num_env - i] - g->rel_trail[i];
    }
    g->bs_pointer = g->num_env + 1 - g->tran_env;
    if (g->tran_env > 0) {
      g->short_env = g->tran_env - 1;
    }
  } else if (start == 0 && end == slots) {
    g->frame_class = 0; /* FIXFIX, one envelope */
    g->num_env = 1;
    g->t_env[0] = 0;
    g->t_env[1] = slots;
  } else if (end == slots) {
    g->frame_class = 2; /* VARFIX */
    g->abs_bord_0 = start;
    g->num_env = 1;
    g->t_env[0] = start;
    g->t_env[1] = slots;
  } else {
    g->frame_class = start ? 3 : 1;
    g->abs_bord_0 = start;
    g->abs_bord_1 = end;
    g->num_env = 1;
    g->t_env[0] = start;
    g->t_env[1] = end;
  }
  g->amp_res = (g->frame_class == 0 && g->num_env == 1) ? 0 : amp_res_header;
  for (int e = 0; e < g->num_env; e++) {
    const int length = g->t_env[e + 1] - g->t_env[e];
    g->freq_res[e] = length > 5;
  }
  /* noise floor segments as the decoder derives them */
  g->num_noise = (g->num_env > 1) + 1;
  g->t_q[0] = g->t_env[0];
  g->t_q[g->num_noise] = g->t_env[g->num_env];
  if (g->num_noise > 1) {
    int idx;
    if (g->frame_class == 0) {
      idx = g->num_env >> 1;
    } else if (g->frame_class & 1) {
      idx = g->num_env - std::max(g->bs_pointer - 1, 1);
    } else if (!g->bs_pointer) {
      idx = 1;
    } else if (g->bs_pointer == 1) {
      idx = g->num_env - 1;
    } else {
      idx = g->bs_pointer - 1;
    }
    g->t_q[1] = g->t_env[idx];
  }
}

}  // namespace

void aac_sbr_enc_write_grid(AacBitWriter* w, const AacSbrGrid* g, int slots) {
  aac_bitwriter_write(w, (uint32_t)g->frame_class, 2);
  switch (g->frame_class) {
    case 0:
      aac_bitwriter_write(w, g->num_env == 1 ? 0 : (g->num_env == 2 ? 1 : 2), 2);
      aac_bitwriter_write(w, (uint32_t)g->freq_res[0], 1);
      return;
    case 1:
      aac_bitwriter_write(w, (uint32_t)(g->abs_bord_1 - slots), 2);
      aac_bitwriter_write(w, (uint32_t)g->num_rel_trail, 2);
      for (int i = 0; i < g->num_rel_trail; i++) {
        aac_bitwriter_write(w, (uint32_t)(g->rel_trail[i] / 2 - 1), 2);
      }
      aac_bitwriter_write(w, (uint32_t)g->bs_pointer, kCeilLog2[g->num_env]);
      for (int i = 0; i < g->num_env; i++) {
        aac_bitwriter_write(w, (uint32_t)g->freq_res[g->num_env - 1 - i], 1);
      }
      return;
    case 2:
      aac_bitwriter_write(w, (uint32_t)g->abs_bord_0, 2);
      aac_bitwriter_write(w, (uint32_t)g->num_rel_lead, 2);
      for (int i = 0; i < g->num_rel_lead; i++) {
        aac_bitwriter_write(w, (uint32_t)(g->rel_lead[i] / 2 - 1), 2);
      }
      aac_bitwriter_write(w, (uint32_t)g->bs_pointer, kCeilLog2[g->num_env]);
      for (int i = 0; i < g->num_env; i++) {
        aac_bitwriter_write(w, (uint32_t)g->freq_res[i], 1);
      }
      return;
    default:
      aac_bitwriter_write(w, (uint32_t)g->abs_bord_0, 2);
      aac_bitwriter_write(w, (uint32_t)(g->abs_bord_1 - slots), 2);
      aac_bitwriter_write(w, (uint32_t)g->num_rel_lead, 2);
      aac_bitwriter_write(w, (uint32_t)g->num_rel_trail, 2);
      for (int i = 0; i < g->num_rel_lead; i++) {
        aac_bitwriter_write(w, (uint32_t)(g->rel_lead[i] / 2 - 1), 2);
      }
      for (int i = 0; i < g->num_rel_trail; i++) {
        aac_bitwriter_write(w, (uint32_t)(g->rel_trail[i] / 2 - 1), 2);
      }
      aac_bitwriter_write(w, (uint32_t)g->bs_pointer, kCeilLog2[g->num_env]);
      for (int i = 0; i < g->num_env; i++) {
        aac_bitwriter_write(w, (uint32_t)g->freq_res[i], 1);
      }
      return;
  }
}

namespace {

/* ── tonality (4.6.18.6.2 prediction, as the detector of TS 26.404) ── */

/* Ratio of the energy a second-order predictor explains to the residual
 * of a band over slots [t0, t1), with two slots of history. */
float tonality(const AacSbrEncChannel* ch, int band, int t0, int t1) {
  const int n = t1 - t0;
  if (n < 2) {
    return 0.0f;
  }
  float x[kSlots + 8][2];
  for (int i = 0; i < n + 2; i++) {
    const float (*w)[2] = slot(ch, t0 - 2 + i);
    x[i][0] = w[band][0];
    x[i][1] = w[band][1];
  }
  /* normal equations of the predictor x_t ~ -a0 x_{t-1} - a1 x_{t-2} */
  double r00 = 0, r11 = 0, r22 = 0;
  double r01r = 0, r01i = 0, r02r = 0, r02i = 0, r12r = 0, r12i = 0;
  for (int i = 2; i < n + 2; i++) {
    const double xr = x[i][0], xi = x[i][1];
    const double ar = x[i - 1][0], ai = x[i - 1][1];
    const double br = x[i - 2][0], bi = x[i - 2][1];
    r00 += xr * xr + xi * xi;
    r11 += ar * ar + ai * ai;
    r22 += br * br + bi * bi;
    r01r += xr * ar + xi * ai;
    r01i += xi * ar - xr * ai;
    r02r += xr * br + xi * bi;
    r02i += xi * br - xr * bi;
    r12r += ar * br + ai * bi;
    r12i += ai * br - ar * bi;
  }
  if (r00 <= 0.0) {
    return 0.0f;
  }
  /* solve [r11 r12; r12* r22] [c0; c1] = [r01; r02] for the predictor
   * coefficients c (x_t ~ c0 x_{t-1} + c1 x_{t-2}) */
  const double det = r11 * r22 - (r12r * r12r + r12i * r12i);
  double c0r, c0i, c1r, c1i;
  if (det <= 1e-9 * r11 * r22 || r22 <= 0.0) {
    if (r11 <= 0.0) {
      return 0.0f;
    }
    c0r = r01r / r11;
    c0i = r01i / r11;
    c1r = c1i = 0.0;
  } else {
    /* c1 = (r11 r02 - r12 r01) / det, c0 = (r01 - r12* c1) / r11 */
    c1r = (r11 * r02r - (r12r * r01r - r12i * r01i)) / det;
    c1i = (r11 * r02i - (r12r * r01i + r12i * r01r)) / det;
    c0r = (r01r - (r12r * c1r + r12i * c1i)) / r11;
    c0i = (r01i - (r12r * c1i - r12i * c1r)) / r11;
  }
  double residual = 0.0;
  for (int i = 2; i < n + 2; i++) {
    const double pr = c0r * x[i - 1][0] - c0i * x[i - 1][1] + c1r * x[i - 2][0] - c1i * x[i - 2][1];
    const double pi = c0r * x[i - 1][1] + c0i * x[i - 1][0] + c1r * x[i - 2][1] + c1i * x[i - 2][0];
    const double er = x[i][0] - pr;
    const double ei = x[i][1] - pi;
    residual += er * er + ei * ei;
  }
  const double explained = std::max(r00 - residual, 0.0);
  const double q = explained / std::max(residual, 1e-6 * r00);
  return (float)std::min(q, 1.0e4);
}

/* Mean tonality of QMF bands [k0, k1), of the original or of the bands
 * the generator copies there. */
float band_tonality(const AacSbrEncoder* s, const AacSbrEncChannel* ch, int k0, int k1, int t0,
                    int t1, bool patched) {
  float sum = 0.0f;
  for (int k = k0; k < k1; k++) {
    sum += tonality(ch, patched ? s->source_band[k] : k, t0, t1);
  }
  return sum / (float)std::max(k1 - k0, 1);
}

/* ── envelope and noise floor estimation (TS 26.404 5.6, 5.7) ──── */

void estimate_envelopes(const AacSbrEncoder* s, AacSbrEncChannel* ch, int base) {
  const AacSbrGrid* g = &ch->grid;
  const AacSbrFreq* f = &s->freq;
  for (int e = 0; e < g->num_env; e++) {
    int t0 = base + 2 * g->t_env[e];
    int t1 = base + 2 * g->t_env[e + 1];
    if (e == g->short_env && t1 - t0 > 2) {
      t1 -= 2; /* the analysis window smears the transient backwards */
    }
    const uint16_t* table = g->freq_res[e] ? f->f_tablehigh : f->f_tablelow;
    const int n = f->n[g->freq_res[e]];
    for (int b = 0; b < n; b++) {
      int k0 = table[b];
      const int k1 = table[b + 1];
      if (b == 0 && k1 - k0 > (g->freq_res[e] ? 1 : 2)) {
        k0++; /* the crossover band still carries core signal */
      }
      double sum = 0.0;
      for (int t = t0; t < t1; t++) {
        const float (*w)[2] = slot(ch, t);
        for (int k = k0; k < k1; k++) {
          sum += (double)w[k][0] * w[k][0] + (double)w[k][1] * w[k][1];
        }
      }
      const double mean = sum / ((double)(t1 - t0) * (k1 - k0));
      const double level = log2(std::max(mean, 1.0)) - 6.0;
      int q = g->amp_res ? (int)lrint(level) : (int)lrint(2.0 * level);
      ch->env_q[e][b] = std::clamp(q, 0, 127);
    }
  }
}

void estimate_noise(const AacSbrEncoder* s, AacSbrEncChannel* ch, int base) {
  const AacSbrGrid* g = &ch->grid;
  const AacSbrFreq* f = &s->freq;
  for (int i = 0; i < g->num_noise; i++) {
    int t0 = base + 2 * g->t_q[i];
    int t1 = base + 2 * g->t_q[i + 1];
    if (t1 - t0 < 4) {
      t0 = t1 - 4;
    }
    for (int j = 0; j < f->n_q; j++) {
      const int k0 = f->f_tablenoise[j];
      const int k1 = f->f_tablenoise[j + 1];
      float orig = band_tonality(s, ch, k0, k1, t0, t1, false);
      float sbr = band_tonality(s, ch, k0, k1, t0, t1, true);
      if (orig <= 1e-3f && sbr <= 1e-3f) {
        orig = sbr = 100.0f; /* silence: no noise */
      }
      orig = std::max(orig, 1e-6f);
      sbr = std::max(sbr, 1e-6f);
      const float diff = std::max(1e-6f, 0.25f * sbr / orig);
      float q = std::min(diff / orig, s->noise_max);
      const int level = (int)lrintf(kNoiseFloorOffset - log2f(std::max(q, 1e-9f)));
      ch->noise_q[i][j] = std::clamp(level, 0, 30);
    }
  }
}

/* Inverse filtering level per noise band from how much more tonal the
 * copied bands are than the original ones. */
void decide_invf(const AacSbrEncoder* s, AacSbrEncChannel* ch, int base) {
  const AacSbrFreq* f = &s->freq;
  for (int j = 0; j < f->n_q; j++) {
    const int k0 = f->f_tablenoise[j];
    const int k1 = f->f_tablenoise[j + 1];
    const float orig = band_tonality(s, ch, k0, k1, base, base + s->slots, false);
    const float sbr = band_tonality(s, ch, k0, k1, base, base + s->slots, true);
    const float ratio = sbr / std::max(orig, 1e-3f);
    static const float kUp[3] = {1.5f, 4.0f, 16.0f};
    int level = 0;
    while (level < 3 && ratio > kUp[level]) {
      level++;
    }
    if (sbr < 1.0f) {
      level = 0;
    }
    /* hysteresis: a change of one level needs the ratio to clear the
     * threshold by a margin */
    const int prev = ch->prev_invf[j];
    if (level == prev + 1 && ratio < kUp[prev] * 1.25f) {
      level = prev;
    } else if (level == prev - 1 && prev > 0 && ratio > kUp[prev - 1] / 1.25f) {
      level = prev;
    }
    ch->invf[j] = level;
  }
}

/* ── delta coding (4.6.18.3.4) ─────────────────────────────────── */

void code_envelopes(const AacSbrEncoder* s, AacSbrEncChannel* ch, bool allow_time) {
  const AacSbrGrid* g = &ch->grid;
  const AacSbrFreq* f = &s->freq;
  const HuffCode* t_huff = g->amp_res ? &g_env_30_t : &g_env_15_t;
  const HuffCode* f_huff = g->amp_res ? &g_env_30_f : &g_env_15_f;
  const int start_bits = g->amp_res ? 6 : 7;
  const int odd = f->n[1] & 1;
  int prev[AAC_SBR_MAX_BANDS];
  int prev_res = ch->prev_freq_res;
  bool have_prev = allow_time && ch->have_prev;
  memcpy(prev, ch->prev_env_q, sizeof(prev));
  for (int e = 0; e < g->num_env; e++) {
    const int res = g->freq_res[e];
    const int n = f->n[res];
    int* q = ch->env_q[e];
    /* keep neighbouring bands within the codebook's range */
    for (int b = n - 1; b > 0; b--) {
      q[b - 1] = std::max(q[b - 1], q[b] - f_huff->lav);
    }
    for (int b = 1; b < n; b++) {
      q[b] = std::max(q[b], q[b - 1] - f_huff->lav);
    }
    int bits_f = start_bits;
    for (int b = 1; b < n; b++) {
      bits_f += code_bits(f_huff, q[b] - q[b - 1]);
    }
    int bits_t = 0;
    bool time_ok = have_prev;
    if (time_ok) {
      for (int b = 0; b < n && time_ok; b++) {
        const int d = q[b] - prev[aac_sbr_prev_band(b, res, prev_res, odd)];
        if (std::abs(d) > t_huff->lav) {
          time_ok = false;
        } else {
          bits_t += code_bits(t_huff, d);
        }
      }
    }
    ch->df_env[e] = time_ok && bits_t < bits_f;
    memcpy(prev, q, (size_t)n * sizeof(int));
    prev_res = res;
    have_prev = true;
  }
  ch->prev_num_env = g->num_env;
  ch->prev_freq_res = prev_res;
  memcpy(ch->prev_env_q, prev, sizeof(prev));
  /* noise floors: delta in time against the previous segment */
  int prev_noise[AAC_SBR_MAX_NOISE_BANDS];
  memcpy(prev_noise, ch->prev_noise_q, sizeof(prev_noise));
  bool noise_prev = allow_time && ch->have_prev;
  for (int i = 0; i < g->num_noise; i++) {
    const int n = f->n_q;
    int* q = ch->noise_q[i];
    for (int b = n - 1; b > 0; b--) {
      q[b - 1] = std::max(q[b - 1], q[b] - g_env_30_f.lav);
    }
    for (int b = 1; b < n; b++) {
      q[b] = std::max(q[b], q[b - 1] - g_env_30_f.lav);
    }
    int bits_f = 5;
    for (int b = 1; b < n; b++) {
      bits_f += code_bits(&g_env_30_f, q[b] - q[b - 1]);
    }
    int bits_t = 0;
    bool time_ok = noise_prev;
    for (int b = 0; b < n && time_ok; b++) {
      const int d = q[b] - prev_noise[b];
      if (std::abs(d) > g_noise_30_t.lav) {
        time_ok = false;
      } else {
        bits_t += code_bits(&g_noise_30_t, d);
      }
    }
    ch->df_noise[i] = time_ok && bits_t < bits_f;
    memcpy(prev_noise, q, (size_t)n * sizeof(int));
    noise_prev = true;
  }
  memcpy(ch->prev_noise_q, prev_noise, sizeof(prev_noise));
  ch->have_prev = 1;
}

/* ── bitstream (4.6.18.2, Tables 4.56 to 4.68) ─────────────────── */

void write_header(const AacSbrEncoder* s, AacBitWriter* w) {
  const AacSbrFreq* f = &s->freq;
  aac_bitwriter_write(w, (uint32_t)s->amp_res_header, 1);
  aac_bitwriter_write(w, (uint32_t)f->start_freq, 4);
  aac_bitwriter_write(w, (uint32_t)f->stop_freq, 4);
  aac_bitwriter_write(w, (uint32_t)f->xover_band, 3);
  aac_bitwriter_write(w, 0, 2);
  const int extra1 = f->freq_scale != 2 || f->alter_scale != 1 || f->noise_bands != 2;
  const int extra2 = f->limiter_bands != 2 || s->limiter_gains != 2 || s->interpol_freq != 1 ||
                     s->smoothing_mode != 1;
  aac_bitwriter_write(w, (uint32_t)extra1, 1);
  aac_bitwriter_write(w, (uint32_t)extra2, 1);
  if (extra1) {
    aac_bitwriter_write(w, (uint32_t)f->freq_scale, 2);
    aac_bitwriter_write(w, (uint32_t)f->alter_scale, 1);
    aac_bitwriter_write(w, (uint32_t)f->noise_bands, 2);
  }
  if (extra2) {
    aac_bitwriter_write(w, (uint32_t)f->limiter_bands, 2);
    aac_bitwriter_write(w, (uint32_t)s->limiter_gains, 2);
    aac_bitwriter_write(w, (uint32_t)s->interpol_freq, 1);
    aac_bitwriter_write(w, (uint32_t)s->smoothing_mode, 1);
  }
}

}  // namespace

void aac_sbr_enc_write_dtdf(AacBitWriter* w, const AacSbrEncChannel* ch, int skip_first) {
  for (int e = skip_first ? 1 : 0; e < ch->grid.num_env; e++) {
    aac_bitwriter_write(w, (uint32_t)ch->df_env[e], 1);
  }
  for (int i = skip_first ? 1 : 0; i < ch->grid.num_noise; i++) {
    aac_bitwriter_write(w, (uint32_t)ch->df_noise[i], 1);
  }
}

void aac_sbr_enc_write_invf(const AacSbrEncoder* s, AacBitWriter* w, const AacSbrEncChannel* ch) {
  for (int j = 0; j < s->freq.n_q; j++) {
    aac_bitwriter_write(w, (uint32_t)ch->invf[j], 2);
  }
}

void aac_sbr_enc_write_envelope(const AacSbrEncoder* s, AacBitWriter* w,
                                const AacSbrEncChannel* ch, const int* prev_in, int prev_res_in) {
  const AacSbrGrid* g = &ch->grid;
  const AacSbrFreq* f = &s->freq;
  const HuffCode* t_huff = g->amp_res ? &g_env_30_t : &g_env_15_t;
  const HuffCode* f_huff = g->amp_res ? &g_env_30_f : &g_env_15_f;
  const int start_bits = g->amp_res ? 6 : 7;
  const int odd = f->n[1] & 1;
  int prev[AAC_SBR_MAX_BANDS];
  memcpy(prev, prev_in, sizeof(prev));
  int prev_res = prev_res_in;
  for (int e = 0; e < g->num_env; e++) {
    const int res = g->freq_res[e];
    const int n = f->n[res];
    const int* q = ch->env_q[e];
    if (ch->df_env[e]) {
      for (int b = 0; b < n; b++) {
        write_code(w, t_huff, q[b] - prev[aac_sbr_prev_band(b, res, prev_res, odd)]);
      }
    } else {
      aac_bitwriter_write(w, (uint32_t)q[0], start_bits);
      for (int b = 1; b < n; b++) {
        write_code(w, f_huff, q[b] - q[b - 1]);
      }
    }
    memcpy(prev, q, (size_t)n * sizeof(int));
    prev_res = res;
  }
}

void aac_sbr_enc_write_noise(const AacSbrEncoder* s, AacBitWriter* w, const AacSbrEncChannel* ch,
                             const int* prev_in) {
  const AacSbrGrid* g = &ch->grid;
  const int n = s->freq.n_q;
  int prev[AAC_SBR_MAX_NOISE_BANDS];
  memcpy(prev, prev_in, sizeof(prev));
  for (int i = 0; i < g->num_noise; i++) {
    const int* q = ch->noise_q[i];
    if (ch->df_noise[i]) {
      for (int b = 0; b < n; b++) {
        write_code(w, &g_noise_30_t, q[b] - prev[b]);
      }
    } else {
      aac_bitwriter_write(w, (uint32_t)q[0], 5);
      for (int b = 1; b < n; b++) {
        write_code(w, &g_env_30_f, q[b] - q[b - 1]);
      }
    }
    memcpy(prev, q, (size_t)n * sizeof(int));
  }
}

namespace {

/* The per-channel data of the element in the order of
 * sbr_single_channel_element() / sbr_channel_pair_element() without
 * coupling.  `prev_env`/`prev_noise` are the values the decoder holds
 * before this frame (the last envelope and segment of the previous one). */
void write_channel_data(const AacSbrEncoder* s, AacBitWriter* w, int nch,
                        const int (*prev_env)[AAC_SBR_MAX_BANDS], const int* prev_res,
                        const int (*prev_noise)[AAC_SBR_MAX_NOISE_BANDS]) {
  aac_bitwriter_write(w, 0, 1); /* bs_data_extra */
  if (nch == 2) {
    aac_bitwriter_write(w, 0, 1); /* bs_coupling */
  }
  for (int c = 0; c < nch; c++) {
    aac_sbr_enc_write_grid(w, &s->ch[c].grid, s->time_slots);
  }
  for (int c = 0; c < nch; c++) {
    aac_sbr_enc_write_dtdf(w, &s->ch[c], 0);
  }
  for (int c = 0; c < nch; c++) {
    aac_sbr_enc_write_invf(s, w, &s->ch[c]);
  }
  if (nch == 1) {
    aac_sbr_enc_write_envelope(s, w, &s->ch[0], prev_env[0], prev_res[0]);
    aac_sbr_enc_write_noise(s, w, &s->ch[0], prev_noise[0]);
  } else {
    aac_sbr_enc_write_envelope(s, w, &s->ch[0], prev_env[0], prev_res[0]);
    aac_sbr_enc_write_envelope(s, w, &s->ch[1], prev_env[1], prev_res[1]);
    aac_sbr_enc_write_noise(s, w, &s->ch[0], prev_noise[0]);
    aac_sbr_enc_write_noise(s, w, &s->ch[1], prev_noise[1]);
  }
  for (int c = 0; c < nch; c++) {
    aac_bitwriter_write(w, 0, 1); /* bs_add_harmonic_flag */
  }
}

}  // namespace

/* ── public ────────────────────────────────────────────────────── */

int aac_sbr_enc_init(AacSbrEncoder* s, int sample_rate, int channels, int bitrate, int ps,
                     int frame_length) {
  build_codes();
  memset(s, 0, sizeof(*s));
  if (sample_rate % 2 || (channels != 1 && channels != 2) || (ps && channels != 2)) {
    return AAC_ERR_UNSUPPORTED;
  }
  if (frame_length != AAC_FRAME_SIZE_LONG && frame_length != AAC_FRAME_SIZE_LONG_960) {
    return AAC_ERR_UNSUPPORTED;
  }
  const int coded = ps ? 1 : channels;
  const Tuning* t = find_tuning(sample_rate / 2, coded, bitrate);
  if (!t) {
    return AAC_ERR_UNSUPPORTED;
  }
  s->sample_rate = sample_rate;
  s->channels = channels;
  s->bitrate = bitrate;
  s->ps = ps;
  s->frame_length = frame_length;
  s->slots = frame_length / 32;
  s->time_slots = s->slots / 2;
  AacSbrFreq* f = &s->freq;
  f->sample_rate = sample_rate;
  f->start_freq = t->start_freq;
  f->stop_freq = t->stop_freq;
  f->xover_band = 0;
  f->freq_scale = t->freq_scale;
  f->alter_scale = 1;
  f->noise_bands = t->noise_bands;
  f->limiter_bands = 2;
  if (aac_sbr_freq_tables(f)) {
    return AAC_ERR_UNSUPPORTED;
  }
  s->amp_res_header = 1;
  s->limiter_gains = 2;
  s->interpol_freq = 1;
  s->smoothing_mode = 0;
  s->noise_max = t->noise_max == 6 ? 64.0f : (t->noise_max == 3 ? 2.0f : 0.5f);
  /* about once a second */
  s->header_interval = std::max(1, (sample_rate + 2 * frame_length - 1) / (2 * frame_length));
  /* the band each SBR band is copied from (4.6.18.6.3) */
  int target = f->kx;
  for (int p = 0; p < f->num_patches; p++) {
    for (int i = 0; i < f->patch_num_subbands[p]; i++) {
      if (target < 64) {
        s->source_band[target] = f->patch_start_subband[p] + i;
      }
      target++;
    }
  }
  for (int k = 0; k < 64; k++) {
    if (k < f->kx) {
      s->source_band[k] = k;
    } else if (k >= target) {
      s->source_band[k] = std::max(k - f->kx, 0);
    }
  }
  if (ps) {
    aac_ps_enc_init(&s->ps_enc, bitrate);
  }
  aac_sbr_enc_reset(s);
  return 0;
}

void aac_sbr_enc_reset(AacSbrEncoder* s) {
  for (int c = 0; c < 2; c++) {
    AacSbrEncChannel* ch = &s->ch[c];
    aac_qmf_analysis_init(&ch->analysis, 64);
    aac_qmf_synthesis_init(&ch->synthesis, 32);
    memset(ch->W, 0, sizeof(ch->W));
    memset(ch->hf_energy, 0, sizeof(ch->hf_energy));
    ch->tran_next = -1;
    ch->start_bord = 0;
    ch->have_prev = 0;
    ch->prev_num_env = 0;
    ch->prev_freq_res = 1;
    memset(ch->prev_env_q, 0, sizeof(ch->prev_env_q));
    memset(ch->prev_noise_q, 0, sizeof(ch->prev_noise_q));
    memset(ch->prev_invf, 0, sizeof(ch->prev_invf));
  }
  /* the left channel's ring, which the PS and MPS 212 analyses read
   * around the frame, holds nothing of the previous stream either */
  memset(s->left, 0, sizeof(s->left));
  s->frames_since_header = s->header_interval; /* the first frame carries one */
  s->frame_count = 0;
  s->pos = 0;
  s->payload_bits = 0;
  if (s->ps) {
    aac_ps_enc_reset(&s->ps_enc);
  }
}

namespace {

/* Analyses one block of a channel into the ring at `pos`. */
void analyze_block(AacSbrEncoder* s, AacSbrEncChannel* ch, const float* in, int pos) {
  float silence[64] = {};
  for (int i = 0; i < s->slots; i++) {
    float (*W)[2] = ch->W[(pos + i) & kRingMask];
    aac_qmf_analyze(&ch->analysis, in ? in + 64 * i : silence, W);
  }
}

void slot_energy(AacSbrEncoder* s, AacSbrEncChannel* ch, int pos) {
  const AacSbrFreq* f = &s->freq;
  for (int i = 0; i < s->slots; i++) {
    const float (*W)[2] = ch->W[(pos + i) & kRingMask];
    float e = 0.0f;
    for (int k = f->kx; k < f->kx + f->m; k++) {
      e += W[k][0] * W[k][0] + W[k][1] * W[k][1];
    }
    ch->hf_energy[(pos + i) & kRingMask] = e;
  }
}

/* The core signal of a block: the lower 32 bands through the half-rate
 * synthesis bank. */
void synthesize_core(AacSbrEncChannel* ch, int pos, float* core, int slots) {
  for (int i = 0; i < slots; i++) {
    aac_qmf_synthesize(&ch->synthesis, ch->W[(pos + i) & kRingMask], core + 32 * i);
  }
}

/* A steady frame whose envelope changes between its halves gets two
 * envelopes instead of one, as the reference encoders' frame splitter
 * does: when the energy-weighted level change across the low-resolution
 * bands exceeds kSplitChangeDb.  Measured over HE-AAC at 32, 48 and 64
 * kbit/s stereo (30 item pairs): PEAQ +0.012 ± 0.010, ViSQOL
 * +0.017 ± 0.008, with no rate worse. */
constexpr double kSplitChangeDb = 3.0;

void split_steady_frame(const AacSbrEncoder* s, AacSbrEncChannel* ch, int base) {
  AacSbrGrid* g = &ch->grid;
  if (g->frame_class != 0 || g->num_env != 1 || s->time_slots != 16) {
    return;
  }
  const AacSbrFreq* f = &s->freq;
  double weight = 0.0;
  double change = 0.0;
  for (int b = 0; b < f->n[0]; b++) {
    double e[2] = {0.0, 0.0};
    for (int h = 0; h < 2; h++) {
      for (int t = base + 16 * h; t < base + 16 * h + 16; t++) {
        const float (*w)[2] = slot(ch, t);
        for (int k = f->f_tablelow[b]; k < f->f_tablelow[b + 1]; k++) {
          e[h] += (double)w[k][0] * w[k][0] + (double)w[k][1] * w[k][1];
        }
      }
    }
    const double band = e[0] + e[1];
    if (band <= 0.0) {
      continue;
    }
    change += band * std::fabs(10.0 * log10((e[0] + 1e-3) / (e[1] + 1e-3)));
    weight += band;
  }
  if (weight <= 0.0 || change / weight <= kSplitChangeDb) {
    return;
  }
  g->num_env = 2;
  g->t_env[0] = 0;
  g->t_env[1] = 8;
  g->t_env[2] = 16;
  g->freq_res[0] = g->freq_res[1] = 1;
  g->amp_res = s->amp_res_header;
  g->num_noise = 2;
  g->t_q[0] = 0;
  g->t_q[1] = 8;
  g->t_q[2] = 16;
}

void frame_data(AacSbrEncoder* s, AacSbrEncChannel* ch, int base, bool header) {
  /* transient slots relative to the frame's first slot */
  const int tran = ch->tran_next;
  const int found = detect_transient(ch, base + s->slots, s->slots);
  const int tran_next = found >= 0 ? found - base - s->slots : -1;
  ch->tran_next = tran_next;
  const int tran_env = tran >= 0 ? tran >> 1 : -1;
  make_grid(&ch->grid, ch->start_bord, tran_env, s->amp_res_header, s->time_slots);
  if (!s->usac) {
    split_steady_frame(s, ch, base);
  }
  ch->start_bord = std::max(0, ch->grid.t_env[ch->grid.num_env] - s->time_slots);
  estimate_envelopes(s, ch, base);
  estimate_noise(s, ch, base);
  decide_invf(s, ch, base);
  code_envelopes(s, ch, !header);
  memcpy(ch->prev_invf, ch->invf, sizeof(ch->invf));
}

}  // namespace

void aac_sbr_enc_process(AacSbrEncoder* s, const float* const* in, float* const* core) {
  const int pos = s->pos;
  const int nch = s->channels;
  const int slots = s->slots;
  if (s->ps || s->mps) {
    /* both channels are analysed for the parameters; their downmix is
     * coded as channel 0, the left channel kept aside */
    AacSbrEncChannel* l = &s->ch[0];
    AacSbrEncChannel* r = &s->ch[1];
    analyze_block(s, l, in ? in[0] : nullptr, pos);
    analyze_block(s, r, in ? in[1] : nullptr, pos);
    for (int i = 0; i < slots; i++) {
      const int idx = (pos + i) & kRingMask;
      memcpy(s->left[idx], l->W[idx], sizeof(s->left[idx]));
      if (s->ps) {
        aac_ps_enc_downmix(&s->ps_enc, s->left[idx], r->W[idx], l->W[idx]);
      }
    }
    if (s->mps) {
      /* a block never wraps the ring: 1024-line frames fill 32 of its
       * 128 slots and start at a multiple of 32 */
      aac_mps212_enc_downmix(s->mps, s->left + pos, r->W + pos, l->W + pos, slots);
    }
    slot_energy(s, l, pos);
    synthesize_core(l, pos, core[0], slots);
  } else {
    for (int c = 0; c < nch; c++) {
      analyze_block(s, &s->ch[c], in ? in[c] : nullptr, pos);
      slot_energy(s, &s->ch[c], pos);
      synthesize_core(&s->ch[c], pos, core[c], slots);
    }
  }
  s->pos = (pos + slots) & kRingMask;

  /* the extension of the frame emitted now */
  const int base = (pos - kCoreDelayBlocks * slots + kGridOffset) & kRingMask;
  /* xHE-AAC sends the header with every independent frame and codes
   * nothing against the previous frame there */
  const bool header = s->usac ? s->usac_indep != 0 : s->frames_since_header >= s->header_interval;
  s->frames_since_header = header ? 1 : s->frames_since_header + 1;
  const int coded = s->ps || s->mps ? 1 : nch;
  int prev_env[2][AAC_SBR_MAX_BANDS];
  int prev_res[2];
  int prev_noise[2][AAC_SBR_MAX_NOISE_BANDS];
  for (int c = 0; c < coded; c++) {
    memcpy(prev_env[c], s->ch[c].prev_env_q, sizeof(prev_env[c]));
    prev_res[c] = s->ch[c].prev_freq_res;
    memcpy(prev_noise[c], s->ch[c].prev_noise_q, sizeof(prev_noise[c]));
    frame_data(s, &s->ch[c], base, header);
  }
  if (s->usac) {
    aac_sbr_enc_usac_frame(s, base, prev_env, prev_res, prev_noise);
    s->frame_count++;
    return;
  }
  if (s->ps) {
    float L[2][38][64], R[2][38][64];
    for (int i = 0; i < slots + 6; i++) {
      const int idx = (base + i) & kRingMask;
      for (int k = 0; k < 64; k++) {
        L[0][i][k] = s->left[idx][k][0];
        L[1][i][k] = s->left[idx][k][1];
        R[0][i][k] = s->ch[1].W[idx][k][0];
        R[1][i][k] = s->ch[1].W[idx][k][1];
      }
    }
    aac_ps_enc_analyze(&s->ps_enc, L, R, header, slots);
  }

  AacBitWriter w;
  aac_bitwriter_init(&w, s->payload, sizeof(s->payload));
  aac_bitwriter_write(&w, kExtensionSbr, 4);
  aac_bitwriter_write(&w, (uint32_t)header, 1);
  if (header) {
    write_header(s, &w);
  }
  write_channel_data(s, &w, coded, prev_env, prev_res, prev_noise);
  if (s->ps) {
    aac_bitwriter_write(&w, 1, 1); /* bs_extended_data */
    const int ext_bits = 2 + s->ps_enc.bits;
    const int ext_bytes = (ext_bits + 7) / 8;
    if (ext_bytes < 15) {
      aac_bitwriter_write(&w, (uint32_t)ext_bytes, 4);
    } else {
      aac_bitwriter_write(&w, 15, 4);
      aac_bitwriter_write(&w, (uint32_t)(ext_bytes - 15), 8);
    }
    aac_bitwriter_write(&w, kExtensionPs, 2);
    aac_ps_enc_write(&s->ps_enc, &w);
    aac_bitwriter_write(&w, 0, ext_bytes * 8 - ext_bits);
  } else {
    aac_bitwriter_write(&w, 0, 1); /* bs_extended_data */
  }
  const int bits = aac_bitwriter_bits_written(&w);
  const int bytes = (bits + 7) / 8;
  aac_bitwriter_write(&w, 0, bytes * 8 - bits);
  s->payload_bits = bytes * 8;
  s->frame_count++;
}

void aac_sbr_downsampler_init(AacSbrDownsampler* d, int frame_length) {
  aac_qmf_tables_init();
  aac_qmf_analysis_init(&d->analysis, 64);
  aac_qmf_synthesis_init(&d->synthesis, 32);
  d->slots = frame_length / 32;
}

void aac_sbr_downsample(AacSbrDownsampler* d, const float* in, float* out) {
  const float silence[64] = {};
  for (int i = 0; i < d->slots; i++) {
    float W[64][2];
    aac_qmf_analyze(&d->analysis, in ? in + 64 * i : silence, W);
    aac_qmf_synthesize(&d->synthesis, W, out + 32 * i);
  }
}

int aac_sbr_enc_bits(const AacSbrEncoder* s) {
  const int count = s->payload_bits / 8;
  return 3 + 4 + (count >= 15 ? 8 : 0) + s->payload_bits;
}

void aac_sbr_enc_write(const AacSbrEncoder* s, AacBitWriter* w) {
  const int count = s->payload_bits / 8;
  aac_bitwriter_write(w, AAC_ELEM_FIL, 3);
  if (count < 15) {
    aac_bitwriter_write(w, (uint32_t)count, 4);
  } else {
    aac_bitwriter_write(w, 15, 4);
    aac_bitwriter_write(w, (uint32_t)(count - 14), 8);
  }
  for (int i = 0; i < count; i++) {
    aac_bitwriter_write(w, s->payload[i], 8);
  }
}
