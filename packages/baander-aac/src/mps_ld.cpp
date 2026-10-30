#include "mps_ld.h"

#include <cmath>
#include <cstdlib>
#include <cstring>
#include <mutex>

#include "mps_internal.h"

/* The low-delay mode of MPEG Surround: the filterbank with the
 * low-delay prototype, the decorrelator of the low-delay mode and the
 * 2-1-2 path over them.  The mixing matrices come from the tree
 * definitions shared with the AAC form (aac_mps_tree_matrices); the
 * set building, smoothing and interpolation follow the AAC path on the
 * same matrix structure, over 64 QMF bands instead of the 71 hybrid
 * bands.  Reverb band 0 of the decorrelator is never used: the lowest
 * split is 0, so every QMF band belongs to reverb band 1 or above. */

namespace {

constexpr int kBands = AAC_MPS_LD_BANDS;
constexpr int kSlots = AAC_MPS_MAX_SLOTS;
constexpr int kSets = AAC_MPS_MAX_PARAM_SETS;
constexpr int kPb = AAC_MPS_MAX_PARAM_BANDS;
constexpr int kOut = 2;
constexpr int kMatrixFloats = (int)(sizeof(AacMpsBandMatrices) / sizeof(float));
constexpr float kAbsThr = 1e-9f * 32768.0f * 32768.0f;

float g_ana_cos[kBands][2 * kBands], g_ana_sin[kBands][2 * kBands];
float g_syn_cos[2 * kBands][kBands], g_syn_sin[2 * kBands][kBands];

void build_tables() {
  for (int k = 0; k < kBands; k++) {
    for (int n = 0; n < 2 * kBands; n++) {
      const double a = M_PI / 128.0 * (k + 0.5) * (2.0 * n + 1.0);
      g_ana_cos[k][n] = (float)cos(a);
      g_ana_sin[k][n] = (float)sin(a);
    }
  }
  for (int n = 0; n < 2 * kBands; n++) {
    for (int k = 0; k < kBands; k++) {
      const double a = M_PI / 128.0 * (k + 0.5) * (2.0 * n - 127.0);
      g_syn_cos[n][k] = (float)(cos(a) / 64.0);
      g_syn_sin[n][k] = (float)(sin(a) / 64.0);
    }
  }
}

/* The decorrelator of the low-delay mode over the 64 QMF bands. */
struct Decorr {
  int reverb_band[kBands];
  float delay[kBands][AAC_MPS_LD_DECORR_MAX_DELAY][2];
  int delay_pos[kBands];
  float state[kBands][AAC_MPS_LD_DECORR_MAX_ORDER + 1][2];
  float e_in[AAC_MPS_LD_MAX_PARAM_BANDS], e_out[AAC_MPS_LD_MAX_PARAM_BANDS];
};

void decorr_init(Decorr* d, int decorr_config) {
  memset(d, 0, sizeof(*d));
  const int cfg = decorr_config < 0 ? 0 : (decorr_config > 2 ? 2 : decorr_config);
  for (int k = 0; k < kBands; k++) {
    int rb = 0;
    while (rb < 3 && k >= (int)aac_mps_ld_decorr_split[cfg][rb] - 1) {
      rb++;
    }
    d->reverb_band[k] = rb;
  }
}

/* One slot: the pre-delay, the all-pass filter and the energy
 * adjustment per parameter band of the 23 band scale (the diffuse
 * energy held within a factor of 1.5 of the direct energy). */
void decorr_slot(Decorr* d, const float (*in)[2], float (*out)[2]) {
  const uint8_t* map = aac_mps_ld_kernel(AAC_MPS_LD_MAX_PARAM_BANDS);
  for (int k = 0; k < kBands; k++) {
    const int rb = d->reverb_band[k];
    const int delay = aac_mps_ld_decorr_delay[rb];
    const int order = aac_mps_ld_decorr_order[rb];
    const float* den = aac_mps_ld_decorr_den[rb];
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
    float(*st)[2] = d->state[k];
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
  float e_in[AAC_MPS_LD_MAX_PARAM_BANDS] = {0}, e_out[AAC_MPS_LD_MAX_PARAM_BANDS] = {0};
  for (int k = 0; k < kBands; k++) {
    const int pb = map[k];
    e_in[pb] += in[k][0] * in[k][0] + in[k][1] * in[k][1];
    e_out[pb] += out[k][0] * out[k][0] + out[k][1] * out[k][1];
  }
  float gain[AAC_MPS_LD_MAX_PARAM_BANDS];
  for (int pb = 0; pb < AAC_MPS_LD_MAX_PARAM_BANDS; pb++) {
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
  for (int k = 0; k < kBands; k++) {
    out[k][0] *= gain[map[k]];
    out[k][1] *= gain[map[k]];
  }
}

}  // namespace

struct AacMpsLdState_ {
  AacMpsConfig cfg;
  AacMpsTreeLayout t;
  int slots, num_bands;
  const uint8_t* kernel;
  float in_gain;
  Decorr decorr;
  /* matrices: per set, the previous frame's last set, per slot */
  AacMpsBandMatrices m[kSets][kPb];
  AacMpsBandMatrices m_prev[kPb];
  AacMpsBandMatrices r[kSlots][kPb];
  int num_sets, param_slot[kSets], param_diff[kSets];
  int num_sets_prev;
};

namespace {

/* The sets' matrices, the frame-end set and the slot spans; returns
 * the number of coded sets, 0 for a lost frame, -1 for bad slots. */
int build_sets(AacMpsLdState* s, const AacMpsFrameParams* p) {
  if (p == NULL || p->num_sets <= 0) {
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
 * the previous set (SmgData). */
void smooth_sets(AacMpsLdState* s, const AacMpsFrameParams* p) {
  const int coded = p->num_sets < kSets - 1 ? p->num_sets : kSets - 1;
  for (int ps = 0; ps < s->num_sets; ps++) {
    const int src = ps < coded ? ps : coded - 1;
    const int time = p->smooth_time[src];
    if (time <= 0) {
      continue;
    }
    float delta = (float)s->param_diff[ps] / (float)time;
    if (delta > 1.0f) delta = 1.0f;
    for (int pb = 0; pb < s->num_bands; pb++) {
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
void interpolate_sets(AacMpsLdState* s) {
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

void reset_state(AacMpsLdState* s) {
  decorr_init(&s->decorr, s->cfg.decorr_config);
  memset(s->m, 0, sizeof(s->m));
  memset(s->m_prev, 0, sizeof(s->m_prev));
  memset(s->r, 0, sizeof(s->r));
  s->num_sets_prev = 0;
}

}  // namespace

/* ── the filterbank ─────────────────────────────────────────────── */

void aac_mps_ld_tables_init(void) {
  static std::once_flag once;
  std::call_once(once, build_tables);
}

void aac_mps_ld_analysis_init(AacMpsLdAnalysis* a) {
  aac_mps_ld_tables_init();
  memset(a->x, 0, sizeof(a->x));
}

void aac_mps_ld_analyze(AacMpsLdAnalysis* a, const float* in, float (*W)[2]) {
  float* x = a->x;
  memmove(x + kBands, x, (size_t)(AAC_MPS_LD_WINDOW - kBands) * sizeof(float));
  for (int n = 0; n < kBands; n++) {
    x[n] = in[kBands - 1 - n];
  }
  float u[2 * kBands];
  for (int n = 0; n < 2 * kBands; n++) {
    float sum = 0.0f;
    for (int j = 0; j < 5; j++) {
      const int idx = n + 2 * kBands * j;
      sum += x[idx] * aac_mps_ld_window[idx];
    }
    u[n] = sum;
  }
  for (int k = 0; k < kBands; k++) {
    float re = 0.0f, im = 0.0f;
    const float* c = g_ana_cos[k];
    const float* s = g_ana_sin[k];
    for (int n = 0; n < 2 * kBands; n++) {
      re += u[n] * c[n];
      im += u[n] * s[n];
    }
    W[k][0] = re;
    W[k][1] = im;
  }
}

void aac_mps_ld_synthesis_init(AacMpsLdSynthesis* s) {
  aac_mps_ld_tables_init();
  memset(s->v, 0, sizeof(s->v));
  s->off = 18 * kBands;
}

void aac_mps_ld_synthesize(AacMpsLdSynthesis* s, const float (*X)[2], float* out) {
  const int block = 2 * kBands;
  const int history = 18 * kBands;
  const int size = 2 * history;
  if (s->off < block) {
    memcpy(s->v + size - history, s->v, (size_t)history * sizeof(float));
    s->off = size - history - block;
  } else {
    s->off -= block;
  }
  float* v = s->v + s->off;
  for (int n = 0; n < block; n++) {
    const float* c = g_syn_cos[n];
    const float* sn = g_syn_sin[n];
    float acc = 0.0f;
    for (int k = 0; k < kBands; k++) {
      acc += X[k][0] * c[k] - X[k][1] * sn[k];
    }
    v[n] = acc;
  }
  for (int k = 0; k < kBands; k++) {
    float acc = 0.0f;
    for (int j = 0; j < 5; j++) {
      const int g0 = 2 * block * j + k;
      const int g1 = 2 * block * j + 3 * kBands + k;
      acc += v[g0] * aac_mps_ld_window[2 * j * kBands + k] +
             v[g1] * aac_mps_ld_window[(2 * j + 1) * kBands + k];
    }
    out[k] = acc;
  }
}

/* ── the signal path ────────────────────────────────────────────── */

AacMpsLdState* aac_mps_ld_create(const AacMpsConfig* cfg) {
  if (cfg == NULL || !cfg->low_delay || cfg->tree_config != AAC_MPS_TREE_212 ||
      cfg->frame_length <= 0 || cfg->frame_length > kSlots || cfg->audio_3d_mode) {
    return NULL;
  }
  const int num_bands = aac_mps_param_bands_ld(cfg->freq_res);
  const uint8_t* kernel = aac_mps_ld_kernel(num_bands);
  if (kernel == NULL) {
    return NULL;
  }
  AacMpsTreeLayout t;
  if (aac_mps_tree_layout(cfg, &t) != AAC_OK) {
    return NULL;
  }
  AacMpsLdState* s = (AacMpsLdState*)calloc(1, sizeof(AacMpsLdState));
  if (s == NULL) {
    return NULL;
  }
  s->cfg = *cfg;
  s->t = t;
  s->slots = cfg->frame_length;
  s->num_bands = num_bands;
  s->kernel = kernel;
  s->in_gain = aac_mps_dmx_gain[cfg->fixed_gain_dmx & 7];
  aac_mps_ld_tables_init();
  reset_state(s);
  return s;
}

void aac_mps_ld_destroy(AacMpsLdState* s) { free(s); }

void aac_mps_ld_reset(AacMpsLdState* s) {
  if (s) {
    reset_state(s);
  }
}

int aac_mps_ld_slots(const AacMpsLdState* s) { return s ? s->slots : 0; }

void aac_mps_ld_apply(AacMpsLdState* s, const AacMpsFrameParams* p, const AacUsacQmfFrame* in,
                      AacUsacQmfFrame* const* out) {
  if (s == NULL || in == NULL || out == NULL) {
    return;
  }
  const int slots = s->slots;
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
  for (int n = 0; n < slots; n++) {
    float x[kBands][2], d[kBands][2];
    for (int k = 0; k < kBands; k++) {
      x[k][0] = s->in_gain * in->x[n][k][0];
      x[k][1] = s->in_gain * in->x[n][k][1];
    }
    decorr_slot(&s->decorr, x, d);
    for (int ch = 0; ch < kOut; ch++) {
      for (int k = 0; k < kBands; k++) {
        const AacMpsBandMatrices* m = &s->r[n][s->kernel[k]];
        const float dry = m->m2_dry[ch][0];
        const float wet = m->m2_wet[ch][1];
        out[ch]->x[n][k][0] = dry * x[k][0] + wet * d[k][0];
        out[ch]->x[n][k][1] = dry * x[k][1] + wet * d[k][1];
      }
    }
  }
  for (int ch = 0; ch < kOut; ch++) {
    out[ch]->slots = slots;
  }
  s->num_sets_prev = s->num_sets;
}
