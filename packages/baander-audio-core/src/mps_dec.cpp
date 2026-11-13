#include <cmath>
#include <cstdlib>
#include <cstring>

#include "mps212.h"
#include "mps_signal.h"

/* The signal path of the MPEG Surround decoder (ISO/IEC 23003-1 6.6):
 * the hybrid analysis of the inputs and the residuals, the pre-matrix M1
 * per slot (the matrices of the parameter sets interpolated over the
 * slots after the smoothing), the decorrelators with their energy
 * adjustment, the residuals in place of the decorrelated signals in
 * their bands, the post-matrix M2 to the output channels, the temporal
 * shaping (subband temporal processing or guided envelope shaping) of
 * the diffuse parts, and the hybrid synthesis back to the QMF bands with
 * the fixed gains of the LFE and surround channels. */

namespace {

constexpr int kPb = AAC_MPS_MAX_PARAM_BANDS;
constexpr int kHyb = AAC_MPS_HYBRID_BANDS;
constexpr int kSlots = AAC_MPS_MAX_SLOTS;
constexpr int kSets = AAC_MPS_MAX_PARAM_SETS;
constexpr int kOut = AAC_MPS_MAX_OUTPUT_CHANNELS;
constexpr int kIn = AAC_MPS_MAX_INPUT_CHANNELS;
constexpr int kBoxes = AAC_MPS_MAX_BOXES;
constexpr int kProto = 13;
constexpr int kResRing = 2 * kSlots;
constexpr int kStpBorder = 12; /* hybrid bands below which the diffuse part is not shaped */
constexpr int kGesFirst = 6;   /* first hybrid band the envelope shaping scales */
constexpr float kAbsThr = 1e-9f * 32768.0f * 32768.0f;
constexpr float kSqrt2 = 1.41421356f;
constexpr int kMatrixFloats = (int)(sizeof(AacMpsBandMatrices) / sizeof(float));

enum { kLfe = 3, kLs = 4, kRs = 5 };

struct Stp {
  float nrg_dir[kIn], nrg_dir_prev[kIn];
  float nrg_diff[kOut], nrg_diff_prev[kOut], scale_last[kOut];
  int update_counter;
};

struct Ges {
  float pb_energy_prev[kOut + kIn][kPb];
  float avg_energy_prev[kOut + kIn];
  float frame_energy_prev[kOut + kIn];
};

int qmf_band_of(int hyb) {
  if (hyb < 6) return 0;
  if (hyb < 8) return 1;
  if (hyb < 10) return 2;
  return hyb - 7;
}

}  // namespace

struct AacMpsSignalState_ {
  AacMpsConfig cfg;
  AacMpsTreeLayout t;
  int slots, num_bands, core_frame_length, qmf_slots;
  int res_qmf_bands, res_hyb_bands, residual_delay;
  int res_bands[kBoxes]; /* residual bands per box, 0 without */
  int max_res_bands;
  const uint8_t* kernel;
  const uint8_t* kernel28;
  const uint8_t* kernel20;
  float in_gain, sur_gain, lfe_gain;

  /* ── signal state ── */
  float x_hist[AAC_MPS_MAX_X][kProto][64][2];
  float res_hist[kBoxes][kProto][64][2];
  AacQmfAnalysis res_qmf[kBoxes];
  float res_ring[kBoxes][kResRing][64][2];
  int res_ring_pos;
  float res_pcm[kBoxes][kSlots * 64];
  int res_set[kBoxes];
  AacMpsDecorr decorr[AAC_MPS_MAX_DECORR];
  Stp stp;
  Ges ges;

  /* ── matrices: per set, the previous frame's last set, per slot ── */
  AacMpsBandMatrices m[kSets][kPb];
  AacMpsBandMatrices m_prev[kPb];
  AacMpsBandMatrices r[kSlots][kPb];
  int num_sets, param_slot[kSets], param_diff[kSets];
  int num_sets_prev;
  int stp_on[kOut];
  float ges_gain[kOut][kSlots]; /* 0 where the envelope is not shaped */

  /* ── signals of the frame ── */
  float x[AAC_MPS_MAX_X][kSlots][kHyb][2];
  float v[AAC_MPS_MAX_V][kSlots][kHyb][2];
  float d[AAC_MPS_MAX_DECORR][kSlots][kHyb][2];
  float wres[AAC_MPS_MAX_DECORR][kSlots][kHyb][2];
  float dry[kOut][kSlots][kHyb][2];
  float wet[kOut][kSlots][kHyb][2];
};

namespace {

/* Pushes a slot of `bands` QMF bands into a 13-slot history and returns
 * the hybrid bands of the slot six back: the lowest ten from the filters
 * over the three split bands, the others delayed to the same timing. */
void hybrid_push(float (*hist)[64][2], const float (*slot)[2], int bands, float (*out)[2]) {
  memmove(hist[0], hist[1], sizeof(hist[0]) * (kProto - 1));
  for (int b = 0; b < 64; b++) {
    hist[kProto - 1][b][0] = b < bands ? slot[b][0] : 0.0f;
    hist[kProto - 1][b][1] = b < bands ? slot[b][1] : 0.0f;
  }
  aac_mps212_hybrid_analysis(hist, out);
}

/* ── the matrices of the frame ──────────────────────────────────── */

/* The sets' matrices, the frame-end set, and the slot spans. */
int build_sets(AacMpsSignalState* s, const AacMpsFrameParams* p) {
  if (p == NULL || p->num_sets <= 0) {
    /* a lost frame: the previous matrices throughout */
    for (int pb = 0; pb < s->num_bands; pb++) {
      s->m[0][pb] = s->m_prev[pb];
    }
    s->num_sets = 1;
    s->param_slot[0] = s->slots - 1;
    s->param_diff[0] = s->slots;
    return 0;
  }
  int n = p->num_sets < kSets - 1 ? p->num_sets : kSets - 1;
  for (int ps = 0; ps < n; ps++) {
    int slot = p->param_slot[ps];
    if (slot < 0) slot = 0;
    if (slot > s->slots - 1) slot = s->slots - 1;
    s->param_slot[ps] = slot;
    for (int pb = 0; pb < s->num_bands; pb++) {
      aac_mps_tree_matrices(&s->cfg, &s->t, p, ps, pb, &s->m[ps][pb]);
    }
  }
  if (s->param_slot[n - 1] != s->slots - 1) {
    for (int pb = 0; pb < s->num_bands; pb++) {
      s->m[n][pb] = s->m[n - 1][pb];
    }
    s->param_slot[n] = s->slots - 1;
    n++;
  }
  s->num_sets = n;
  s->param_diff[0] = s->param_slot[0] + 1;
  for (int ps = 1; ps < n; ps++) {
    s->param_diff[ps] = s->param_slot[ps] - s->param_slot[ps - 1];
    if (s->param_diff[ps] <= 0) {
      return -1;
    }
  }
  return 1;
}

/* First-order smoothing of the matrices in the flagged bands towards
 * the previous set (SmgData), above the residual bands. */
void smooth_sets(AacMpsSignalState* s, const AacMpsFrameParams* p) {
  const int coded = p->num_sets < kSets - 1 ? p->num_sets : kSets - 1;
  for (int ps = 0; ps < s->num_sets; ps++) {
    const int src = ps < coded ? ps : coded - 1; /* the frame-end set carries the last coded one's */
    const int time = p->smooth_time[src];
    if (time <= 0) {
      continue;
    }
    float delta = (float)s->param_diff[ps] / (float)time;
    if (delta > 1.0f) delta = 1.0f;
    for (int pb = s->max_res_bands; pb < s->num_bands; pb++) {
      if (!p->smoothing[src][pb]) {
        continue;
      }
      float* cur = (float*)&s->m[ps][pb];
      const float* prev = ps > 0 ? (const float*)&s->m[ps - 1][pb] : (const float*)&s->m_prev[pb];
      for (int i = 0; i < kMatrixFloats; i++) {
        cur[i] = delta * cur[i] + (1.0f - delta) * prev[i];
      }
    }
  }
}

/* Linear interpolation over the slots from the previous frame's last
 * set through the sets, reaching each set at its slot. */
void interpolate_sets(AacMpsSignalState* s) {
  int ts = 0;
  for (int ps = 0; ps < s->num_sets; ps++) {
    const int diff = s->param_diff[ps];
    for (int pb = 0; pb < s->num_bands; pb++) {
      const float* a = ps > 0 ? (const float*)&s->m[ps - 1][pb] : (const float*)&s->m_prev[pb];
      const float* b = (const float*)&s->m[ps][pb];
      for (int i = 1; i <= diff; i++) {
        const float t = (float)i / (float)diff;
        float* out = (float*)&s->r[ts + i - 1][pb];
        for (int k = 0; k < kMatrixFloats; k++) {
          out[k] = a[k] + t * (b[k] - a[k]);
        }
      }
    }
    ts += diff;
  }
}

/* ── temporal shaping ───────────────────────────────────────────── */

/* Subband temporal processing (bsTempShapeConfig 1): the diffuse part
 * of a flagged channel follows the envelope of its input channel's
 * direct parts, measured on the hybrid bands 13..31 with band-pass
 * weights and normalised by slowly updated energies. */
void stp_slot(AacMpsSignalState* s, int ts) {
  Stp* p = &s->stp;
  const AacMpsTreeLayout* t = &s->t;
  if (p->update_counter == 32) {
    p->update_counter = 1;
    for (int g = 0; g < t->num_inputs; g++) {
      p->nrg_dir_prev[g] = p->nrg_dir[g];
    }
    for (int ch = 0; ch < t->num_outputs; ch++) {
      p->nrg_diff_prev[ch] = p->nrg_diff[ch];
    }
  } else {
    p->update_counter++;
  }
  float dir_energy[kIn] = {0.0f, 0.0f};
  for (int g = 0; g < t->num_inputs; g++) {
    float e = 0.0f;
    for (int n = 6; n < 25; n++) {
      float re = 0.0f, im = 0.0f;
      for (int ch = 0; ch < t->num_outputs; ch++) {
        if (t->stp_group[ch] == g) {
          re += s->dry[ch][ts][n + 7][0];
          im += s->dry[ch][ts][n + 7][1];
        }
      }
      const float wgt = aac_mps212_stp_bp[n] * aac_mps212_stp_bp[n] * aac_mps212_stp_gf[n] *
                        aac_mps212_stp_gf[n];
      e += (re * re + im * im) * wgt;
    }
    p->nrg_dir[g] = 0.95f * p->nrg_dir[g] + 0.05f * e;
    dir_energy[g] = e / (p->nrg_dir_prev[g] + kAbsThr);
  }
  float scale[kOut];
  for (int ch = 0; ch < t->num_outputs; ch++) {
    scale[ch] = 1.0f;
    const int g = t->stp_group[ch];
    if (g < 0) {
      continue;
    }
    float diff_energy = 0.0f;
    for (int n = 6; n < 25; n++) {
      const float re = s->wet[ch][ts][n + 7][0];
      const float im = s->wet[ch][ts][n + 7][1];
      const float wgt = aac_mps212_stp_bp[n] * aac_mps212_stp_bp[n] * aac_mps212_stp_gf[n] *
                        aac_mps212_stp_gf[n];
      diff_energy += (re * re + im * im) * wgt;
    }
    p->nrg_diff[ch] = 0.95f * p->nrg_diff[ch] + 0.05f * diff_energy;
    diff_energy /= p->nrg_diff_prev[ch] + kAbsThr;
    float sc = sqrtf(dir_energy[g] / (diff_energy + 1e-9f));
    sc = 0.1f + 0.9f * sc;
    if (sc < 1.0f / 2.82f) sc = 1.0f / 2.82f;
    if (sc > 2.82f) sc = 2.82f;
    sc = 0.45f * sc + 0.55f * p->scale_last[ch];
    p->scale_last[ch] = sc;
    scale[ch] = sc;
  }
  for (int ch = 0; ch < t->num_outputs; ch++) {
    float (*dir)[2] = s->dry[ch][ts];
    const float (*dif)[2] = s->wet[ch][ts];
    const int shaped = s->stp_on[ch] && t->stp_group[ch] >= 0;
    for (int n = 0; n < kHyb; n++) {
      float g = 1.0f;
      if (shaped) {
        if (n < kStpBorder) {
          g = 1.0f; /* already summed */
        } else if (n < 16) {
          g = scale[ch] * aac_mps212_stp_bp[n - 7];
        } else {
          g = scale[ch];
        }
      }
      dir[n][0] += g * dif[n][0];
      dir[n][1] += g * dif[n][1];
    }
  }
}

/* The normalised envelope of a signal over the slots of the frame
 * (guided envelope shaping): the energy of parameter bands 10..18 of the
 * 20 band scale, whitened by their slow averages, against its own slow
 * average.  `source` 0 is output channel `ch` (direct plus diffuse), 1
 * the input channel `ch`. */
void ges_envelope(AacMpsSignalState* s, int source, int ch, float* env) {
  Ges* g = &s->ges;
  const uint8_t* map20 = s->kernel20;
  const int slot_ch = source == 0 ? ch : kOut + ch;
  float pb_energy[kPb];
  for (int pb = 10; pb <= 18; pb++) {
    pb_energy[pb] = g->pb_energy_prev[slot_ch][pb];
  }
  float avg = g->avg_energy_prev[slot_ch];
  for (int ts = 0; ts < s->slots; ts++) {
    float slot_energy[kPb] = {0};
    for (int k = 0; k < kHyb; k++) {
      float re, im;
      if (source == 0) {
        re = s->dry[ch][ts][k][0] + s->wet[ch][ts][k][0];
        im = s->dry[ch][ts][k][1] + s->wet[ch][ts][k][1];
      } else {
        re = s->x[ch][ts][k][0];
        im = s->x[ch][ts][k][1];
      }
      slot_energy[map20[k]] += re * re + im * im;
    }
    float total = 0.0f;
    for (int pb = 10; pb <= 18; pb++) {
      pb_energy[pb] = (1.0f - 0.99637864f) * slot_energy[pb] + 0.99637864f * pb_energy[pb];
      total += slot_energy[pb];
    }
    total /= 9.0f;
    total = (1.0f - 0.99637864f) * total + 0.99637864f * g->frame_energy_prev[slot_ch];
    g->frame_energy_prev[slot_ch] = total;
    env[ts] = 0.0f;
    for (int pb = 10; pb <= 18; pb++) {
      env[ts] += slot_energy[pb] * (total / (pb_energy[pb] + kAbsThr));
    }
    avg = (1.0f - 0.9643691f) * env[ts] + 0.9643691f * avg;
    env[ts] = sqrtf(env[ts] / (avg + kAbsThr));
  }
  for (int pb = 10; pb <= 18; pb++) {
    g->pb_energy_prev[slot_ch][pb] = pb_energy[pb];
  }
  g->avg_energy_prev[slot_ch] = avg;
}

/* Guided envelope shaping (bsTempShapeConfig 2): the direct part of a
 * flagged channel above hybrid band 6 is scaled per slot towards the
 * transmitted ratio of its envelope to its input channel's envelope
 * (the mean of both inputs' for the centre of the 525 tree). */
void ges_apply(AacMpsSignalState* s) {
  const AacMpsTreeLayout* t = &s->t;
  float dmx_env[kIn][kSlots], dir_env[kSlots];
  for (int ch = 0; ch < t->num_inputs; ch++) {
    ges_envelope(s, 1, ch, dmx_env[ch]);
  }
  for (int ch = 0; ch < t->num_outputs; ch++) {
    const int ref = t->ges_ref[ch];
    if (ref < 0) {
      continue;
    }
    ges_envelope(s, 0, ch, dir_env);
    for (int ts = 0; ts < s->slots; ts++) {
      if (s->ges_gain[ch][ts] == 0.0f) {
        continue;
      }
      float reference;
      if (ref == 2) {
        reference = 0.5f * (dmx_env[0][ts] + dmx_env[1][ts]);
      } else {
        reference = dmx_env[ref][ts];
      }
      const float gain = s->ges_gain[ch][ts] * reference / (dir_env[ts] + 1e-9f);
      float amp_dir = 0.0f, amp_diff = 0.0f;
      for (int k = kGesFirst; k < kHyb; k++) {
        amp_dir += s->dry[ch][ts][k][0] * s->dry[ch][ts][k][0] +
                   s->dry[ch][ts][k][1] * s->dry[ch][ts][k][1];
        amp_diff += s->wet[ch][ts][k][0] * s->wet[ch][ts][k][0] +
                    s->wet[ch][ts][k][1] * s->wet[ch][ts][k][1];
      }
      const float amp_ratio = sqrtf(amp_diff / (amp_dir + kAbsThr));
      float ratio = gain + amp_ratio * (gain - 1.0f);
      if (ratio < 0.25f) ratio = 0.25f;
      if (ratio > 4.0f) ratio = 4.0f;
      for (int k = kGesFirst; k < kHyb; k++) {
        s->dry[ch][ts][k][0] *= ratio;
        s->dry[ch][ts][k][1] *= ratio;
      }
    }
  }
}

void reset_signal_state(AacMpsSignalState* s) {
  memset(s->x_hist, 0, sizeof(s->x_hist));
  memset(s->res_hist, 0, sizeof(s->res_hist));
  memset(s->res_ring, 0, sizeof(s->res_ring));
  s->res_ring_pos = 0;
  memset(s->res_set, 0, sizeof(s->res_set));
  for (int i = 0; i < kBoxes; i++) {
    aac_qmf_analysis_init(&s->res_qmf[i], s->res_qmf_bands);
  }
  for (int j = 0; j < AAC_MPS_MAX_DECORR; j++) {
    aac_mps_decorr_init(&s->decorr[j], j, s->cfg.decorr_config);
  }
  memset(&s->stp, 0, sizeof(s->stp));
  for (int ch = 0; ch < kOut; ch++) {
    s->stp.scale_last[ch] = 1.0f;
    s->stp.nrg_diff_prev[ch] = 32768.0f * 32768.0f;
  }
  for (int g = 0; g < kIn; g++) {
    s->stp.nrg_dir_prev[g] = 32768.0f * 32768.0f;
  }
  memset(&s->ges, 0, sizeof(s->ges));
  for (int i = 0; i < kOut + kIn; i++) {
    s->ges.avg_energy_prev[i] = 32768.0f * 32768.0f;
  }
  memset(s->m, 0, sizeof(s->m));
  memset(s->m_prev, 0, sizeof(s->m_prev));
  memset(s->r, 0, sizeof(s->r));
  memset(s->stp_on, 0, sizeof(s->stp_on));
  memset(s->ges_gain, 0, sizeof(s->ges_gain));
  s->num_sets_prev = 0;
}

}  // namespace

/* ── decorrelator (ISO/IEC 23003-1 6.6.2) ───────────────────────── */

void aac_mps_decorr_init(AacMpsDecorr* d, int index, int decorr_config) {
  memset(d, 0, sizeof(*d));
  const int cfg = decorr_config < 0 ? 0 : (decorr_config > 2 ? 2 : decorr_config);
  const int seed = index < 0 ? 0 : (index >= AAC_MPS_DECORR_SEEDS ? AAC_MPS_DECORR_SEEDS - 1 : index);
  d->ducking = 1;
  for (int k = 0; k < kHyb; k++) {
    int rb = 0;
    while (rb < 3 && qmf_band_of(k) >= (int)aac_mps_decorr_split[cfg][rb] - 1) {
      rb++;
    }
    d->reverb_band[k] = rb;
    d->den[k] = aac_mps_decorr_den[rb][seed];
  }
}

void aac_mps_decorr_slot(AacMpsDecorr* d, const float (*in)[2], float (*out)[2]) {
  for (int k = 0; k < kHyb; k++) {
    const int rb = d->reverb_band[k];
    const int delay = aac_mps_decorr_delay[rb];
    const int order = aac_mps_decorr_order[rb];
    const float* den = d->den[k];
    float x[2] = {in[k][0], in[k][1]};
    if (delay > 0) {
      const int pos = d->delay_pos[k];
      const float yr = d->delay[k][pos][0], yi = d->delay[k][pos][1];
      d->delay[k][pos][0] = x[0];
      d->delay[k][pos][1] = x[1];
      d->delay_pos[k] = pos + 1 >= delay ? 0 : pos + 1;
      x[0] = yr;
      x[1] = yi;
    }
    /* transposed direct form II; the numerator is the reversed denominator */
    float (*st)[2] = d->state[k];
    float y[2];
    for (int c = 0; c < 2; c++) {
      y[c] = st[0][c] + x[c] * den[order];
    }
    for (int j = 1; j <= order; j++) {
      for (int c = 0; c < 2; c++) {
        st[j - 1][c] = st[j][c] + den[order - j] * x[c] - den[j] * y[c];
      }
    }
    out[k][0] = y[0];
    out[k][1] = y[1];
  }
  if (!d->ducking) {
    return;
  }
  /* the energy adjustment per parameter band of the 28 band scale: the
   * diffuse energy is held within a factor of 1.5 of the direct energy */
  const uint8_t* map28 = aac_mps212_kernel(28);
  float e_in[kPb] = {0}, e_out[kPb] = {0};
  for (int k = 0; k < kHyb; k++) {
    const int pb = map28[k];
    e_in[pb] += in[k][0] * in[k][0] + in[k][1] * in[k][1];
    e_out[pb] += out[k][0] * out[k][0] + out[k][1] * out[k][1];
  }
  float gain[kPb];
  for (int pb = 0; pb < kPb; pb++) {
    d->e_in[pb] = 0.8f * d->e_in[pb] + 0.2f * e_in[pb];
    d->e_out[pb] = 0.8f * d->e_out[pb] + 0.2f * e_out[pb];
    gain[pb] = 1.0f;
    if (d->e_out[pb] > d->e_in[pb] * 1.5f) {
      gain[pb] = sqrtf(d->e_in[pb] * 1.5f / (d->e_out[pb] + kAbsThr));
    }
    if (d->e_in[pb] > d->e_out[pb] * 1.5f) {
      const float g = sqrtf(d->e_in[pb] / (1.5f * d->e_out[pb] + kAbsThr));
      gain[pb] = g < 2.0f ? g : 2.0f;
    }
  }
  for (int k = 0; k < kHyb; k++) {
    out[k][0] *= gain[map28[k]];
    out[k][1] *= gain[map28[k]];
  }
}

/* ── the state ──────────────────────────────────────────────────── */

AacMpsSignalState* aac_mps_signal_create(const AacMpsConfig* cfg, int core_frame_length,
                                         int qmf_slots) {
  if (cfg == NULL || qmf_slots <= 0 || core_frame_length <= 0 || cfg->frame_length <= 0 ||
      cfg->frame_length > kSlots || cfg->frame_length % qmf_slots != 0 ||
      core_frame_length % qmf_slots != 0) {
    return NULL;
  }
  const int res_bands = core_frame_length / qmf_slots;
  if (res_bands != 16 && res_bands != 24 && res_bands != 32 && res_bands != 64) {
    return NULL;
  }
  const int num_bands = aac_mps212_freq_res_bands[cfg->freq_res & 7];
  if (num_bands == 0 || cfg->audio_3d_mode) {
    return NULL;
  }
  AacMpsTreeLayout t;
  if (aac_mps_tree_layout(cfg, &t) != AAC_OK) {
    return NULL;
  }
  AacMpsSignalState* s = (AacMpsSignalState*)calloc(1, sizeof(AacMpsSignalState));
  if (s == NULL) {
    return NULL;
  }
  s->cfg = *cfg;
  s->t = t;
  s->slots = cfg->frame_length;
  s->num_bands = num_bands;
  s->core_frame_length = core_frame_length;
  s->qmf_slots = qmf_slots;
  s->res_qmf_bands = res_bands;
  s->res_hyb_bands = res_bands + 7;
  s->residual_delay = 0;
  s->kernel = aac_mps212_kernel(num_bands);
  s->kernel28 = aac_mps212_kernel(28);
  s->kernel20 = aac_mps212_kernel(20);
  s->max_res_bands = 0;
  for (int box = 0; box < kBoxes; box++) {
    int rb = 0;
    if (cfg->residual_coding && cfg->residual_present[box]) {
      rb = cfg->residual_bands[box];
      if (rb > num_bands) rb = num_bands;
      if (rb < 0) rb = 0;
    }
    s->res_bands[box] = rb;
    if (rb > s->max_res_bands) s->max_res_bands = rb;
  }
  s->in_gain = aac_mps_dmx_gain[cfg->fixed_gain_dmx & 7];
  s->sur_gain = aac_mps_surround_gain[cfg->fixed_gain_sur < 0 ? 0 : (cfg->fixed_gain_sur > 4 ? 4 : cfg->fixed_gain_sur)];
  s->lfe_gain = aac_mps_lfe_gain[cfg->fixed_gain_lfe < 0 ? 0 : (cfg->fixed_gain_lfe > 4 ? 4 : cfg->fixed_gain_lfe)];
  aac_qmf_tables_init();
  reset_signal_state(s);
  return s;
}

void aac_mps_signal_destroy(AacMpsSignalState* s) { free(s); }

void aac_mps_signal_reset(AacMpsSignalState* s) {
  if (s) {
    reset_signal_state(s);
  }
}

int aac_mps_signal_slots(const AacMpsSignalState* s) { return s ? s->slots : 0; }

const AacMpsTreeLayout* aac_mps_signal_layout(const AacMpsSignalState* s) {
  return s ? &s->t : NULL;
}

void aac_mps_signal_set_residual(AacMpsSignalState* s, int index, const float* pcm, int length) {
  if (s == NULL || index < 0 || index >= kBoxes) {
    return;
  }
  const int needed = s->slots * s->res_qmf_bands;
  if (pcm == NULL || length < needed) {
    s->res_set[index] = 0;
    return;
  }
  memcpy(s->res_pcm[index], pcm, sizeof(float) * needed);
  s->res_set[index] = 1;
}

void aac_mps_signal_set_residual_delay(AacMpsSignalState* s, int slots) {
  if (s && slots >= 0 && slots < kResRing - s->slots) {
    s->residual_delay = slots;
  }
}

/* ── the frame ──────────────────────────────────────────────────── */

void aac_mps_signal_apply(AacMpsSignalState* s, const AacMpsFrameParams* p,
                          const AacUsacQmfFrame* const* in, AacUsacQmfFrame* const* out) {
  if (s == NULL || in == NULL || out == NULL) {
    return;
  }
  const AacMpsTreeLayout* t = &s->t;
  const int slots = s->slots;
  const int nd = t->num_direct;

  /* the matrices of the frame */
  if (s->num_sets_prev > 0) {
    for (int pb = 0; pb < s->num_bands; pb++) {
      s->m_prev[pb] = s->m[s->num_sets_prev - 1][pb];
    }
  }
  const int coded = build_sets(s, p);
  if (coded < 0) {
    return;
  }
  if (coded > 0) {
    smooth_sets(s, p);
  }
  interpolate_sets(s);
  for (int ch = 0; ch < kOut; ch++) {
    const int shape = p != NULL && p->temp_shape_enable;
    s->stp_on[ch] = s->cfg.temp_shape_config == 1 && shape && p->stp_channel[ch];
    for (int n = 0; n < slots; n++) {
      s->ges_gain[ch][n] =
          s->cfg.temp_shape_config == 2 && shape && p->ges_channel[ch] ? p->env_shape[ch][n] : 0.0f;
    }
  }

  /* the inputs through the hybrid filterbank, with the downmix gain */
  for (int ch = 0; ch < t->num_inputs; ch++) {
    for (int n = 0; n < slots; n++) {
      float slot[64][2];
      for (int b = 0; b < 64; b++) {
        slot[b][0] = s->in_gain * in[ch]->x[n][b][0];
        slot[b][1] = s->in_gain * in[ch]->x[n][b][1];
      }
      hybrid_push(s->x_hist[ch], slot, 64, s->x[ch][n]);
    }
  }
  for (int col = t->num_inputs; col < AAC_MPS_MAX_X; col++) {
    memset(s->x[col], 0, sizeof(s->x[col]));
  }
  memset(s->wres, 0, sizeof(s->wres));

  /* the residuals: QMF analysis of the core samples (full scale 1.0 to
   * the QMF frame's 32768), delayed to the inputs' timing, the hybrid
   * bands beyond the box's residual bands cleared */
  for (int box = 0; box < kBoxes; box++) {
    if (s->res_bands[box] <= 0) {
      continue;
    }
    const int B = s->res_qmf_bands;
    for (int n = 0; n < slots; n++) {
      float W[64][2];
      memset(W, 0, sizeof(W));
      if (s->res_set[box]) {
        float scaled[64];
        for (int i = 0; i < B; i++) {
          scaled[i] = s->res_pcm[box][n * B + i] * 32768.0f;
        }
        aac_qmf_analyze(&s->res_qmf[box], scaled, W);
      }
      memcpy(s->res_ring[box][(s->res_ring_pos + n) % kResRing], W, sizeof(W));
    }
    float (*dest)[kHyb][2] = NULL;
    if (box >= AAC_MPS_MAX_OTT_BOXES) {
      dest = t->ttt_residual_col >= 0 ? s->x[t->ttt_residual_col] : NULL;
    } else {
      for (int j = 0; j < t->num_decorr; j++) {
        if (t->decorr_box[j] == box) {
          dest = s->wres[j];
        }
      }
    }
    if (dest == NULL) {
      continue;
    }
    for (int n = 0; n < slots; n++) {
      const int read = (s->res_ring_pos + n - s->residual_delay + kResRing) % kResRing;
      float slot[64][2];
      for (int b = 0; b < 64; b++) {
        slot[b][0] = s->res_ring[box][read][b][0];
        slot[b][1] = s->res_ring[box][read][b][1];
      }
      hybrid_push(s->res_hist[box], slot, B, dest[n]);
      for (int k = 0; k < kHyb; k++) {
        if (s->kernel[k] >= s->res_bands[box] || k >= s->res_hyb_bands) {
          dest[n][k][0] = dest[n][k][1] = 0.0f;
        }
      }
    }
  }
  s->res_ring_pos = (s->res_ring_pos + slots) % kResRing;
  memset(s->res_set, 0, sizeof(s->res_set));

  /* M1: the direct signals and the decorrelator inputs; hybrid bands 0
   * and 1 hold the mirrored half of QMF band 0 and take the conjugate
   * weights */
  const int nv = nd + t->num_decorr;
  for (int n = 0; n < slots; n++) {
    for (int k = 0; k < kHyb; k++) {
      const int pb = s->kernel[k];
      const float sign = k < 2 ? -1.0f : 1.0f;
      for (int row = 0; row < nv; row++) {
        float re = 0.0f, im = 0.0f;
        for (int col = 0; col < t->num_x; col++) {
          const float mr = s->r[n][pb].m1[row][col][0];
          const float mi = sign * s->r[n][pb].m1[row][col][1];
          const float xr = s->x[col][n][k][0], xi = s->x[col][n][k][1];
          re += mr * xr - mi * xi;
          im += mr * xi + mi * xr;
        }
        s->v[row][n][k][0] = re;
        s->v[row][n][k][1] = im;
      }
    }
  }

  /* the decorrelators; the 525 tree's TTT decorrelator takes the sum of
   * the three in the bands of TTT mode 0 */
  for (int j = 0; j < t->num_decorr; j++) {
    for (int n = 0; n < slots; n++) {
      aac_mps_decorr_slot(&s->decorr[j], s->v[nd + j][n], s->d[j][n]);
    }
  }
  if (t->num_decorr == 3) {
    for (int n = 0; n < slots; n++) {
      for (int k = 0; k < kHyb; k++) {
        if (!t->ttt_decorr_band[s->kernel[k]]) {
          continue;
        }
        for (int c = 0; c < 2; c++) {
          s->d[2][n][k][c] = s->d[2][n][k][c] / kSqrt2 + s->d[0][n][k][c] + s->d[1][n][k][c];
        }
      }
    }
  }

  /* M2: the direct part from the direct signals and the residuals, the
   * diffuse part from the decorrelated signals */
  for (int ch = 0; ch < t->num_outputs; ch++) {
    for (int n = 0; n < slots; n++) {
      for (int k = 0; k < kHyb; k++) {
        const AacMpsBandMatrices* m = &s->r[n][s->kernel[k]];
        float dr = 0.0f, di = 0.0f, wr = 0.0f, wi = 0.0f;
        for (int j = 0; j < nd; j++) {
          dr += m->m2_dry[ch][j] * s->v[j][n][k][0];
          di += m->m2_dry[ch][j] * s->v[j][n][k][1];
        }
        for (int j = 0; j < t->num_decorr; j++) {
          dr += m->m2_dry[ch][nd + j] * s->wres[j][n][k][0];
          di += m->m2_dry[ch][nd + j] * s->wres[j][n][k][1];
          wr += m->m2_wet[ch][nd + j] * s->d[j][n][k][0];
          wi += m->m2_wet[ch][nd + j] * s->d[j][n][k][1];
        }
        s->dry[ch][n][k][0] = dr;
        s->dry[ch][n][k][1] = di;
        s->wet[ch][n][k][0] = wr;
        s->wet[ch][n][k][1] = wi;
      }
    }
  }

  /* temporal shaping and the sum of the parts */
  if (s->cfg.temp_shape_config == 2) {
    ges_apply(s);
  }
  if (s->cfg.temp_shape_config == 1) {
    for (int ch = 0; ch < t->num_outputs; ch++) {
      for (int n = 0; n < slots; n++) {
        for (int k = 0; k < kStpBorder; k++) {
          s->dry[ch][n][k][0] += s->wet[ch][n][k][0];
          s->dry[ch][n][k][1] += s->wet[ch][n][k][1];
          s->wet[ch][n][k][0] = s->wet[ch][n][k][1] = 0.0f;
        }
      }
    }
    for (int n = 0; n < slots; n++) {
      stp_slot(s, n);
    }
  } else {
    for (int ch = 0; ch < t->num_outputs; ch++) {
      for (int n = 0; n < slots; n++) {
        for (int k = 0; k < kHyb; k++) {
          s->dry[ch][n][k][0] += s->wet[ch][n][k][0];
          s->dry[ch][n][k][1] += s->wet[ch][n][k][1];
        }
      }
    }
  }

  /* the hybrid synthesis and the fixed gains of the LFE and surrounds */
  for (int ch = 0; ch < t->num_outputs; ch++) {
    float gain = 1.0f;
    if (t->num_outputs == 6) {
      if (ch == kLfe) gain = s->lfe_gain;
      if (ch == kLs || ch == kRs) gain = s->sur_gain;
    }
    for (int n = 0; n < slots; n++) {
      aac_mps212_hybrid_synthesis(s->dry[ch][n], out[ch]->x[n]);
      if (gain != 1.0f) {
        for (int b = 0; b < 64; b++) {
          out[ch]->x[n][b][0] *= gain;
          out[ch]->x[n][b][1] *= gain;
        }
      }
    }
    out[ch]->slots = slots;
  }
  s->num_sets_prev = s->num_sets;
}
