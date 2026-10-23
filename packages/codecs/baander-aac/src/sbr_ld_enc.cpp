#include "sbr_ld_enc.h"

#include <algorithm>
#include <cmath>
#include <cstring>

/* Low-delay SBR encoder (ISO/IEC 14496-3 4.6.20, with the estimation
 * methods of 3GPP TS 26.404).  Every analysis slot goes into a ring; the
 * data of a frame is estimated on the slots the decoder's analysis of
 * that frame's core output will see, `offset` slots before the frame's
 * own: the CLDFB chain from the input to the core signal delays it by
 * AAC_CLDFB_DELAY + 1 samples at the output rate, the core codec by its
 * own delay.  The Huffman code tables, the tonality measure, the noise
 * floor and inverse filtering decisions and the envelope delta coding
 * follow the SBR encoder in sbr_enc.cpp, which keeps them internal. */

namespace {

constexpr int kRing = AAC_SBR_LD_ENC_RING;
constexpr int kRingMask = kRing - 1;
constexpr int kNoiseFloorOffset = 6;

/* ── Huffman codes from the decoder's trees ────────────────────── */

struct HuffCode {
  uint32_t code[128]; /* indexed by value + 64 */
  uint8_t len[128];
  int lav; /* largest absolute value */
};

HuffCode g_env_15_t, g_env_15_f, g_env_30_t, g_env_30_f, g_noise_30_t;
bool g_codes_ready = false;

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

void build_codes(void) {
  if (g_codes_ready) {
    return;
  }
  walk(aac_sbr_huff_env_level_15_t, 0, 0, 0, &g_env_15_t);
  walk(aac_sbr_huff_env_level_15_f, 0, 0, 0, &g_env_15_f);
  walk(aac_sbr_huff_env_level_30_t, 0, 0, 0, &g_env_30_t);
  walk(aac_sbr_huff_env_level_30_f, 0, 0, 0, &g_env_30_f);
  walk(aac_sbr_huff_noise_level_30_t, 0, 0, 0, &g_noise_30_t);
  g_codes_ready = true;
}

int code_bits(const HuffCode* h, int value) { return h->len[value + 64]; }

void write_code(AacBitWriter* w, const HuffCode* h, int value) {
  aac_bitwriter_write(w, h->code[value + 64], h->len[value + 64]);
}

/* ── configuration ─────────────────────────────────────────────── */

/* Fills the band tables of a header; returns 0 when the standard allows
 * the combination. */
int tables_for(const AacSbrLdHeader* h, int sbr_rate, AacSbrFreq* f) {
  memset(f, 0, sizeof(*f));
  f->sample_rate = sbr_rate;
  f->start_freq = h->start_freq;
  f->stop_freq = h->stop_freq;
  f->xover_band = h->xover_band;
  f->freq_scale = h->freq_scale;
  f->alter_scale = h->alter_scale;
  f->noise_bands = h->noise_bands;
  f->limiter_bands = h->limiter_bands;
  return aac_sbr_freq_tables(f);
}

/* Chooses start and stop indices whose bands come closest to the wanted
 * crossover and upper edge (in QMF bands of the SBR rate), with the upper
 * edge never above `max_k2`. */
int choose_header(AacSbrLdHeader* h, int sbr_rate, int want_k0, int want_k2, int max_k2) {
  int best_cost = 1 << 30;
  AacSbrLdHeader best = *h;
  for (int start = 0; start < 16; start++) {
    for (int stop = 0; stop < 16; stop++) {
      AacSbrLdHeader t = *h;
      t.start_freq = start;
      t.stop_freq = stop;
      AacSbrFreq f;
      if (tables_for(&t, sbr_rate, &f) || f.kx + f.m > max_k2 || f.num_patches < 1) {
        continue;
      }
      const int cost = 4 * std::abs(f.kx - want_k0) + std::abs(f.kx + f.m - want_k2);
      if (cost < best_cost) {
        best_cost = cost;
        best = t;
      }
    }
  }
  if (best_cost == 1 << 30) {
    return AAC_ERR_UNSUPPORTED;
  }
  *h = best;
  return 0;
}

/* ── analysis helpers ──────────────────────────────────────────── */

inline const float (*slot_at(const AacSbrLdEncChannel* ch, long long t))[2] {
  return ch->W[t & kRingMask];
}

/* The slot of the strongest onset in [t0, t0 + n) relative to t0, or
 * -1: a slot whose high-band energy exceeds the mean of the eight before
 * it by far and is not negligible against the recent peak. */
int detect_transient(const AacSbrLdEncChannel* ch, long long t0, int n) {
  const float* E = ch->hf_energy;
  float peak = 0.0f;
  for (long long t = t0 - 16; t < t0 + n; t++) {
    peak = std::max(peak, E[t & kRingMask]);
  }
  if (peak <= 1e-6f) {
    return -1;
  }
  int best = -1;
  float best_ratio = 0.0f;
  for (int i = 0; i < n; i++) {
    const long long t = t0 + i;
    float past = 0.0f;
    for (int j = 1; j <= 8; j++) {
      past += E[(t - j) & kRingMask];
    }
    past *= 0.125f;
    const float e = E[t & kRingMask];
    const float ratio = e / (past + 1e-3f * peak);
    if (ratio > 8.0f && e > 0.05f * peak && ratio > best_ratio) {
      best = i;
      best_ratio = ratio;
    }
  }
  return best;
}

/* Ratio of the energy a second-order predictor explains to the residual
 * of a band over slots [t0, t1), with two slots of history. */
float tonality(const AacSbrLdEncChannel* ch, int band, long long t0, long long t1) {
  const int n = (int)(t1 - t0);
  if (n < 2) {
    return 0.0f;
  }
  double r00 = 0, r11 = 0, r22 = 0;
  double r01r = 0, r01i = 0, r02r = 0, r02i = 0, r12r = 0, r12i = 0;
  for (long long t = t0; t < t1; t++) {
    const double xr = slot_at(ch, t)[band][0], xi = slot_at(ch, t)[band][1];
    const double ar = slot_at(ch, t - 1)[band][0], ai = slot_at(ch, t - 1)[band][1];
    const double br = slot_at(ch, t - 2)[band][0], bi = slot_at(ch, t - 2)[band][1];
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
    c1r = (r11 * r02r - (r12r * r01r - r12i * r01i)) / det;
    c1i = (r11 * r02i - (r12r * r01i + r12i * r01r)) / det;
    c0r = (r01r - (r12r * c1r + r12i * c1i)) / r11;
    c0i = (r01i - (r12r * c1i - r12i * c1r)) / r11;
  }
  double residual = 0.0;
  for (long long t = t0; t < t1; t++) {
    const float (*x)[2] = slot_at(ch, t);
    const float (*a)[2] = slot_at(ch, t - 1);
    const float (*b)[2] = slot_at(ch, t - 2);
    const double pr = c0r * a[band][0] - c0i * a[band][1] + c1r * b[band][0] - c1i * b[band][1];
    const double pi = c0r * a[band][1] + c0i * a[band][0] + c1r * b[band][1] + c1i * b[band][0];
    const double er = x[band][0] - pr;
    const double ei = x[band][1] - pi;
    residual += er * er + ei * ei;
  }
  const double explained = std::max(r00 - residual, 0.0);
  const double q = explained / std::max(residual, 1e-6 * r00);
  return (float)std::min(q, 1.0e4);
}

/* Mean tonality of QMF bands [k0, k1), of the original or of the bands
 * the generator copies there. */
float band_tonality(const AacSbrLdEncoder* s, const AacSbrLdEncChannel* ch, int k0, int k1,
                    long long t0, long long t1, bool patched) {
  float sum = 0.0f;
  for (int k = k0; k < k1; k++) {
    sum += tonality(ch, patched ? s->source_band[k] : k, t0, t1);
  }
  return sum / (float)std::max(k1 - k0, 1);
}

/* ── grid and estimation ───────────────────────────────────────── */

void make_frame_grid(const AacSbrLdEncoder* s, AacSbrLdEncChannel* ch, long long base) {
  const int slots = s->slots;
  AacSbrLdGrid* g = &ch->grid;
  const int tran = detect_transient(ch, base, slots);
  if (tran >= 0) {
    aac_sbr_ld_make_grid(g, slots, AAC_SBR_LD_FRAME_LDTRAN, 0, tran);
  } else {
    /* two envelopes when the halves of the frame differ by more than
     * 6 dB, one otherwise */
    double e[2] = {0.0, 0.0};
    for (int i = 0; i < slots; i++) {
      e[2 * i >= slots] += ch->hf_energy[(base + i) & kRingMask];
    }
    const double ratio = (e[1] + 1e-3) / (e[0] + 1e-3);
    const int num_env = (ratio > 4.0 || ratio < 0.25) ? 2 : 1;
    aac_sbr_ld_make_grid(g, slots, AAC_SBR_LD_FRAME_FIXFIX, num_env, 0);
  }
  for (int e = 0; e < g->num_env; e++) {
    const int length = g->t_env[e + 1] - g->t_env[e];
    g->freq_res[e] = g->frame_class == AAC_SBR_LD_FRAME_FIXFIX ? 1 : (length >= 4);
  }
  ch->amp_res =
      (g->frame_class == AAC_SBR_LD_FRAME_FIXFIX && g->num_env == 1) ? 0 : s->header.amp_res;
}

void estimate_envelopes(const AacSbrLdEncoder* s, AacSbrLdEncChannel* ch, long long base) {
  const AacSbrLdGrid* g = &ch->grid;
  const AacSbrFreq* f = &s->freq;
  for (int e = 0; e < g->num_env; e++) {
    const long long t0 = base + g->t_env[e];
    const long long t1 = base + g->t_env[e + 1];
    const uint16_t* table = g->freq_res[e] ? f->f_tablehigh : f->f_tablelow;
    const int n = f->n[g->freq_res[e]];
    for (int b = 0; b < n; b++) {
      int k0 = table[b];
      const int k1 = table[b + 1];
      if (b == 0 && k1 - k0 > (g->freq_res[e] ? 1 : 2)) {
        k0++; /* the crossover band still carries core signal */
      }
      double sum = 0.0;
      for (long long t = t0; t < t1; t++) {
        const float (*w)[2] = slot_at(ch, t);
        for (int k = k0; k < k1; k++) {
          sum += (double)w[k][0] * w[k][0] + (double)w[k][1] * w[k][1];
        }
      }
      const double mean = sum / ((double)(t1 - t0) * (k1 - k0));
      const double level = log2(std::max(mean, 1.0)) - 6.0;
      const int q = ch->amp_res ? (int)lrint(level) : (int)lrint(2.0 * level);
      ch->env_q[e][b] = std::clamp(q, 0, 127);
    }
  }
}

void estimate_noise(const AacSbrLdEncoder* s, AacSbrLdEncChannel* ch, long long base) {
  const AacSbrLdGrid* g = &ch->grid;
  const AacSbrFreq* f = &s->freq;
  for (int i = 0; i < g->num_noise; i++) {
    long long t0 = base + g->t_q[i];
    const long long t1 = base + g->t_q[i + 1];
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
      const float q = std::min(diff / orig, s->noise_max);
      const int level = (int)lrintf(kNoiseFloorOffset - log2f(std::max(q, 1e-9f)));
      ch->noise_q[i][j] = std::clamp(level, 0, 30);
    }
  }
}

/* Inverse filtering level per noise band from how much more tonal the
 * copied bands are than the original ones. */
void decide_invf(const AacSbrLdEncoder* s, AacSbrLdEncChannel* ch, long long base) {
  const AacSbrFreq* f = &s->freq;
  static const float kUp[3] = {1.5f, 4.0f, 16.0f};
  for (int j = 0; j < f->n_q; j++) {
    const int k0 = f->f_tablenoise[j];
    const int k1 = f->f_tablenoise[j + 1];
    const float orig = band_tonality(s, ch, k0, k1, base, base + s->slots, false);
    const float sbr = band_tonality(s, ch, k0, k1, base, base + s->slots, true);
    const float ratio = sbr / std::max(orig, 1e-3f);
    int level = 0;
    while (level < 3 && ratio > kUp[level]) {
      level++;
    }
    if (sbr < 1.0f) {
      level = 0;
    }
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

int prev_band(int j, int res, int prev_res, int odd) {
  if (res == prev_res) {
    return j;
  }
  if (res) {
    return (j + odd) >> 1;
  }
  return j ? 2 * j - odd : 0;
}

void code_envelopes(const AacSbrLdEncoder* s, AacSbrLdEncChannel* ch, bool allow_time) {
  const AacSbrLdGrid* g = &ch->grid;
  const AacSbrFreq* f = &s->freq;
  const HuffCode* t_huff = ch->amp_res ? &g_env_30_t : &g_env_15_t;
  const HuffCode* f_huff = ch->amp_res ? &g_env_30_f : &g_env_15_f;
  const int start_bits = ch->amp_res ? 6 : 7;
  const int odd = f->n[1] & 1;
  int prev[AAC_SBR_MAX_BANDS];
  int prev_res = ch->prev_freq_res;
  bool have_prev = allow_time && ch->have_prev;
  memcpy(prev, ch->prev_env_q, sizeof(prev));
  for (int e = 0; e < g->num_env; e++) {
    const int res = g->freq_res[e];
    const int n = f->n[res];
    int* q = ch->env_q[e];
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
    for (int b = 0; b < n && time_ok; b++) {
      const int d = q[b] - prev[prev_band(b, res, prev_res, odd)];
      if (std::abs(d) > t_huff->lav) {
        time_ok = false;
      } else {
        bits_t += code_bits(t_huff, d);
      }
    }
    ch->df_env[e] = time_ok && bits_t < bits_f;
    memcpy(prev, q, (size_t)n * sizeof(int));
    prev_res = res;
    have_prev = true;
  }
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
}

/* ── bitstream ─────────────────────────────────────────────────── */

void write_grid(AacBitWriter* w, const AacSbrLdGrid* g) {
  aac_bitwriter_write(w, (uint32_t)g->frame_class, 1);
  if (g->frame_class == AAC_SBR_LD_FRAME_FIXFIX) {
    aac_bitwriter_write(w, g->num_env == 1 ? 0 : (g->num_env == 2 ? 1 : 2), 2);
    aac_bitwriter_write(w, (uint32_t)g->freq_res[0], 1);
  } else {
    aac_bitwriter_write(w, (uint32_t)g->transient_position, 4);
    for (int e = 0; e < g->num_env; e++) {
      aac_bitwriter_write(w, (uint32_t)g->freq_res[e], 1);
    }
  }
}

void write_dtdf(AacBitWriter* w, const AacSbrLdEncChannel* ch) {
  for (int e = 0; e < ch->grid.num_env; e++) {
    aac_bitwriter_write(w, (uint32_t)ch->df_env[e], 1);
  }
  for (int i = 0; i < ch->grid.num_noise; i++) {
    aac_bitwriter_write(w, (uint32_t)ch->df_noise[i], 1);
  }
}

void write_invf(const AacSbrLdEncoder* s, AacBitWriter* w, const AacSbrLdEncChannel* ch) {
  for (int j = 0; j < s->freq.n_q; j++) {
    aac_bitwriter_write(w, (uint32_t)ch->invf[j], 2);
  }
}

void write_envelope(const AacSbrLdEncoder* s, AacBitWriter* w, const AacSbrLdEncChannel* ch) {
  const AacSbrLdGrid* g = &ch->grid;
  const AacSbrFreq* f = &s->freq;
  const HuffCode* t_huff = ch->amp_res ? &g_env_30_t : &g_env_15_t;
  const HuffCode* f_huff = ch->amp_res ? &g_env_30_f : &g_env_15_f;
  const int start_bits = ch->amp_res ? 6 : 7;
  const int odd = f->n[1] & 1;
  int prev[AAC_SBR_MAX_BANDS];
  memcpy(prev, ch->prev_env_q, sizeof(prev));
  int prev_res = ch->prev_freq_res;
  for (int e = 0; e < g->num_env; e++) {
    const int res = g->freq_res[e];
    const int n = f->n[res];
    const int* q = ch->env_q[e];
    if (ch->df_env[e]) {
      for (int b = 0; b < n; b++) {
        write_code(w, t_huff, q[b] - prev[prev_band(b, res, prev_res, odd)]);
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

void write_noise(const AacSbrLdEncoder* s, AacBitWriter* w, const AacSbrLdEncChannel* ch) {
  const int n = s->freq.n_q;
  int prev[AAC_SBR_MAX_NOISE_BANDS];
  memcpy(prev, ch->prev_noise_q, sizeof(prev));
  for (int i = 0; i < ch->grid.num_noise; i++) {
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

/* sbr_data(): sbr_single_channel_element() or, without coupling,
 * sbr_channel_pair_element(), and no extended data. */
void write_data(AacSbrLdEncoder* s) {
  const int nch = s->channels;
  AacBitWriter w;
  aac_bitwriter_init(&w, s->payload, sizeof(s->payload));
  aac_bitwriter_write(&w, 0, 1); /* bs_data_extra */
  if (nch == 2) {
    aac_bitwriter_write(&w, 0, 1); /* bs_coupling */
  }
  for (int c = 0; c < nch; c++) {
    write_grid(&w, &s->ch[c].grid);
  }
  for (int c = 0; c < nch; c++) {
    write_dtdf(&w, &s->ch[c]);
  }
  for (int c = 0; c < nch; c++) {
    write_invf(s, &w, &s->ch[c]);
  }
  if (nch == 1) {
    write_envelope(s, &w, &s->ch[0]);
    write_noise(s, &w, &s->ch[0]);
  } else {
    write_envelope(s, &w, &s->ch[0]);
    write_envelope(s, &w, &s->ch[1]);
    write_noise(s, &w, &s->ch[0]);
    write_noise(s, &w, &s->ch[1]);
  }
  for (int c = 0; c < nch; c++) {
    aac_bitwriter_write(&w, 0, 1); /* bs_add_harmonic_flag */
  }
  aac_bitwriter_write(&w, 0, 1); /* bs_extended_data */
  s->payload_bits = aac_bitwriter_bits_written(&w);
}

/* Keeps this frame's last envelope and noise floor for the next frame's
 * delta coding. */
void advance(const AacSbrLdEncoder* s, AacSbrLdEncChannel* ch) {
  const AacSbrLdGrid* g = &ch->grid;
  const int last = g->num_env - 1;
  memset(ch->prev_env_q, 0, sizeof(ch->prev_env_q));
  memcpy(ch->prev_env_q, ch->env_q[last], (size_t)s->freq.n[g->freq_res[last]] * sizeof(int));
  ch->prev_freq_res = g->freq_res[last];
  memcpy(ch->prev_noise_q, ch->noise_q[g->num_noise - 1], sizeof(ch->prev_noise_q));
  memcpy(ch->prev_invf, ch->invf, sizeof(ch->prev_invf));
  ch->have_prev = 1;
}

}  // namespace

/* ── public ────────────────────────────────────────────────────── */

int aac_sbr_ld_enc_init(AacSbrLdEncoder* s, int core_sample_rate, int frame_length, int dual_rate,
                        int channels, int bitrate, int core_delay, const AacSbrLdHeader* header) {
  build_codes();
  memset(s, 0, sizeof(*s));
  if ((frame_length != 512 && frame_length != 480) || (channels != 1 && channels != 2) ||
      core_delay < 0 || core_sample_rate <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  s->core_sample_rate = core_sample_rate;
  s->frame_length = frame_length;
  s->slots = frame_length / 32;
  s->dual_rate = dual_rate != 0;
  s->channels = channels;
  s->core_delay = core_delay;
  /* Core sample i carries the input at the output rate's sample
   * 2 i - AAC_CLDFB_DELAY (dual rate; single rate likewise at the core
   * rate), and the core codec adds core_delay; a slot is 64 samples at
   * twice the core rate. */
  s->offset = (AAC_CLDFB_DELAY + 2 * core_delay + 32) / 64;
  if (s->offset + s->slots + 24 > kRing) {
    return AAC_ERR_INVALID_ARG;
  }
  const int sbr_rate = 2 * core_sample_rate;
  if (header) {
    s->header = *header;
  } else {
    AacSbrLdHeader* h = &s->header;
    h->amp_res = 1;
    h->xover_band = 0;
    h->freq_scale = 2;
    h->alter_scale = 1;
    h->noise_bands = 2;
    h->limiter_bands = 2;
    h->limiter_gains = 2;
    h->interpol_freq = 1;
    h->smoothing_mode = 1;
    /* Crossover from the bitrate per channel: 5 kHz at 16 kbit/s up to
     * 9 kHz from 64 kbit/s; the upper edge at 16 kHz or just below the
     * output's Nyquist frequency.  QMF bands of the SBR rate are
     * core_rate / 64 wide. */
    const double per_channel = (double)bitrate / channels;
    const double xover_hz = std::clamp(5000.0 + (per_channel - 16000.0) * (4000.0 / 48000.0),
                                       5000.0, 9000.0);
    const double band_hz = (double)core_sample_rate / 64.0;
    const int max_k2 = s->dual_rate ? 64 : 32;
    const double top_hz = s->dual_rate ? std::min(16000.0, 0.95 * core_sample_rate)
                                       : 0.95 * core_sample_rate / 2.0;
    int want_k0 = (int)lrint(xover_hz / band_hz);
    if (!s->dual_rate) {
      want_k0 = std::min(want_k0, 20);
    }
    const int want_k2 = std::min(max_k2, (int)lrint(top_hz / band_hz));
    if (choose_header(h, sbr_rate, want_k0, want_k2, max_k2)) {
      return AAC_ERR_UNSUPPORTED;
    }
  }
  if (tables_for(&s->header, sbr_rate, &s->freq)) {
    return AAC_ERR_UNSUPPORTED;
  }
  if (!s->dual_rate && s->freq.kx + s->freq.m > 32) {
    return AAC_ERR_UNSUPPORTED;
  }
  s->noise_max = channels == 2 ? 0.5f : 2.0f;
  s->refresh_interval =
      std::max(1, core_sample_rate / frame_length); /* about once a second */
  const AacSbrFreq* f = &s->freq;
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
  aac_sbr_ld_enc_reset(s);
  return 0;
}

void aac_sbr_ld_enc_reset(AacSbrLdEncoder* s) {
  for (int c = 0; c < 2; c++) {
    AacSbrLdEncChannel* ch = &s->ch[c];
    aac_cldfb_analysis_init(&ch->analysis, s->dual_rate ? 64 : 32);
    aac_cldfb_synthesis_init(&ch->synthesis, 32);
    memset(ch->W, 0, sizeof(ch->W));
    memset(ch->hf_energy, 0, sizeof(ch->hf_energy));
    memset(ch->prev_env_q, 0, sizeof(ch->prev_env_q));
    memset(ch->prev_noise_q, 0, sizeof(ch->prev_noise_q));
    memset(ch->prev_invf, 0, sizeof(ch->prev_invf));
    ch->prev_freq_res = 1;
    ch->have_prev = 0;
  }
  s->frames_since_refresh = s->refresh_interval;
  s->slot_count = 0;
  s->frame_count = 0;
  s->payload_bits = 0;
}

const AacSbrLdHeader* aac_sbr_ld_enc_header(const AacSbrLdEncoder* s) { return &s->header; }

int aac_sbr_ld_enc_input_size(const AacSbrLdEncoder* s) {
  return s->frame_length * (s->dual_rate ? 2 : 1);
}

int aac_sbr_ld_enc_delay(const AacSbrLdEncoder* s) {
  return s->dual_rate ? AAC_CLDFB_DELAY : (AAC_CLDFB_DELAY + 1) / 2;
}

void aac_sbr_ld_enc_process(AacSbrLdEncoder* s, const float* in_left, const float* in_right,
                            float* core_left, float* core_right) {
  const int bands = s->dual_rate ? 64 : 32;
  const int slots = s->slots;
  const long long first = s->slot_count;
  for (int c = 0; c < s->channels; c++) {
    AacSbrLdEncChannel* ch = &s->ch[c];
    const float* in = c ? in_right : in_left;
    float* core = c ? core_right : core_left;
    for (int i = 0; i < slots; i++) {
      float block[64];
      for (int n = 0; n < bands; n++) {
        block[n] = in ? in[bands * i + n] * 32768.0f : 0.0f;
      }
      const long long t = first + i;
      float (*W)[2] = ch->W[t & kRingMask];
      memset(W, 0, sizeof(ch->W[0]));
      aac_cldfb_analyze(&ch->analysis, block, W);
      float e = 0.0f;
      for (int k = s->freq.kx; k < s->freq.kx + s->freq.m && k < bands; k++) {
        e += W[k][0] * W[k][0] + W[k][1] * W[k][1];
      }
      ch->hf_energy[t & kRingMask] = e;
      float out[32];
      aac_cldfb_synthesize(&ch->synthesis, W, out);
      if (core) {
        for (int n = 0; n < 32; n++) {
          core[32 * i + n] = out[n] * (1.0f / 32768.0f);
        }
      }
    }
  }
  s->slot_count += slots;

  const long long base = first - s->offset;
  const bool refresh = s->frames_since_refresh >= s->refresh_interval;
  s->frames_since_refresh = refresh ? 1 : s->frames_since_refresh + 1;
  for (int c = 0; c < s->channels; c++) {
    AacSbrLdEncChannel* ch = &s->ch[c];
    make_frame_grid(s, ch, base);
    estimate_envelopes(s, ch, base);
    estimate_noise(s, ch, base);
    decide_invf(s, ch, base);
    code_envelopes(s, ch, !refresh);
  }
  write_data(s);
  for (int c = 0; c < s->channels; c++) {
    advance(s, &s->ch[c]);
  }
  s->frame_count++;
}

void aac_sbr_ld_enc_flush(AacSbrLdEncoder* s, float* core_left, float* core_right) {
  aac_sbr_ld_enc_process(s, nullptr, nullptr, core_left, core_right);
}

int aac_sbr_ld_enc_bits(const AacSbrLdEncoder* s) { return s->payload_bits; }

void aac_sbr_ld_enc_write(const AacSbrLdEncoder* s, AacBitWriter* w) {
  const int full = s->payload_bits / 8;
  for (int i = 0; i < full; i++) {
    aac_bitwriter_write(w, s->payload[i], 8);
  }
  const int rest = s->payload_bits - 8 * full;
  if (rest) {
    aac_bitwriter_write(w, (uint32_t)(s->payload[full] >> (8 - rest)), rest);
  }
}
