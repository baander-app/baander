/* Tables of the USAC linear prediction domain core (ISO/IEC 23003-3
 * 7.13 to 7.17).
 *
 * The standard's trained codebooks are not reproduced here: the first
 * stage LSF codebook (256 vectors of 16), the 7 bit joint gain codebook
 * (128 pairs) and the mean LSF vector are placeholders with the shape
 * and range of the standard's tables, built once at init, so that the
 * decoding pipeline runs end to end and a stream decoded with them is
 * wrong in level and timbre but structurally complete.  Each placeholder
 * is confined to one accessor below; replacing it with the standard's
 * values changes nothing else.
 *
 * The RE8 lattice leaders of the algebraic vector quantiser are derived
 * from the lattice itself: the absolute leaders are enumerated by
 * increasing norm and each codebook Q2, Q3, Q4 takes the leaders that
 * still fit its size (256, 4096 and 65536 codevectors).  This yields the
 * three leaders of Q2 (1^8, 2 2 0^6, 4 0^7), the nine of Q3 and the
 * twenty-three of Q4 exactly filling the sizes; the order of the leaders
 * of equal norm, which fixes the index offsets, is a reconstruction. */
#include <cmath>
#include <cstring>

#include "usac_lpd_internal.h"

/* Generator matrix of RE8 (ISO/IEC 23003-3 7.13.4, the Voronoi
 * extension): the lattice is 2 D8 together with 2 D8 + (1 .. 1). */
const int lpd_re8_gen[8][8] = {
    {4, 0, 0, 0, 0, 0, 0, 0}, {2, 2, 0, 0, 0, 0, 0, 0}, {2, 0, 2, 0, 0, 0, 0, 0},
    {2, 0, 0, 2, 0, 0, 0, 0}, {2, 0, 0, 0, 2, 0, 0, 0}, {2, 0, 0, 0, 0, 2, 0, 0},
    {2, 0, 0, 0, 0, 0, 2, 0}, {1, 1, 1, 1, 1, 1, 1, 1},
};

/* ── RE8 absolute leaders ───────────────────────────────────────── */

#define LPD_MAX_LEADERS 96

using Leader = struct Leader_ {
  int v[8];  /* components, descending */
  int norm;
  int count; /* signed permutations in RE8 */
};

static Leader g_leaders[LPD_MAX_LEADERS];
static int g_num_leaders;
/* per codebook: indices into g_leaders, first index and count */
static int g_cb_leader[3][LPD_MAX_LEADERS];
static int g_cb_offset[3][LPD_MAX_LEADERS];
static int g_cb_num[3];
static int g_tables_ready;

static int factorial(int n) {
  int f = 1;
  for (int i = 2; i <= n; i++) {
    f *= i;
  }
  return f;
}

/* Signed permutations of an absolute leader that lie in RE8: every
 * arrangement of the multiset of components, with free signs on the
 * nonzero components of an even leader (2 D8), and 2^7 sign patterns
 * for an odd leader, whose last sign the parity of the sum fixes. */
static int leader_count(const int* v) {
  int perms = factorial(8);
  int i = 0;
  while (i < 8) {
    int j = i;
    while (j < 8 && v[j] == v[i]) {
      j++;
    }
    perms /= factorial(j - i);
    i = j;
  }
  int nonzero = 0;
  for (int k = 0; k < 8; k++) {
    nonzero += v[k] != 0;
  }
  const int odd = v[0] & 1;
  return perms * (odd ? 128 : (1 << nonzero));
}

static void add_leader(const int* v) {
  if (g_num_leaders >= LPD_MAX_LEADERS) {
    return;
  }
  Leader* l = &g_leaders[g_num_leaders++];
  memcpy(l->v, v, sizeof(l->v));
  l->norm = 0;
  for (int k = 0; k < 8; k++) {
    l->norm += v[k] * v[k];
  }
  l->count = leader_count(v);
}

/* Enumerates the descending nonnegative 8-tuples of RE8 up to `max_norm`:
 * all even with the sum a multiple of 4, or all odd. */
static void enumerate(int* v, int pos, int max_val, int norm, int max_norm) {
  if (pos == 8) {
    if (norm == 0) {
      return;
    }
    if (v[0] & 1) {
      for (int k = 1; k < 8; k++) {
        if (!(v[k] & 1)) {
          return;
        }
      }
      add_leader(v);
    } else {
      int sum = 0;
      for (int k = 0; k < 8; k++) {
        sum += v[k];
      }
      if (sum % 4 == 0) {
        add_leader(v);
      }
    }
    return;
  }
  for (int x = max_val; x >= 0; x--) {
    if (norm + x * x > max_norm) {
      continue;
    }
    if (pos > 0 && ((x & 1) != (v[0] & 1))) {
      continue;
    }
    v[pos] = x;
    enumerate(v, pos + 1, x, norm + x * x, max_norm);
  }
}

static int leader_less(const Leader* a, const Leader* b) {
  if (a->norm != b->norm) {
    return a->norm < b->norm;
  }
  /* equal norm: the peakier leader first (lexicographically larger) */
  for (int k = 0; k < 8; k++) {
    if (a->v[k] != b->v[k]) {
      return a->v[k] > b->v[k];
    }
  }
  return 0;
}

static void build_leaders(void) {
  int v[8];
  g_num_leaders = 0;
  enumerate(v, 0, 12, 0, 160);
  /* insertion sort by (norm, lexicographic) */
  for (int i = 1; i < g_num_leaders; i++) {
    Leader t = g_leaders[i];
    int j = i - 1;
    while (j >= 0 && leader_less(&t, &g_leaders[j])) {
      g_leaders[j + 1] = g_leaders[j];
      j--;
    }
    g_leaders[j + 1] = t;
  }
  const int sizes[3] = {256, 4096, 65536};
  for (int cb = 0; cb < 3; cb++) {
    int used = 0;
    g_cb_num[cb] = 0;
    for (int i = 0; i < g_num_leaders && used < sizes[cb]; i++) {
      if (used + g_leaders[i].count <= sizes[cb]) {
        g_cb_leader[cb][g_cb_num[cb]] = i;
        g_cb_offset[cb][g_cb_num[cb]] = used;
        g_cb_num[cb]++;
        used += g_leaders[i].count;
      }
    }
  }
}

int lpd_avq_num_leaders(int nb) { return g_cb_num[nb - 2]; }
const int* lpd_avq_leader(int nb, int k) { return g_leaders[g_cb_leader[nb - 2][k]].v; }
int lpd_avq_leader_offset(int nb, int k) { return g_cb_offset[nb - 2][k]; }
int lpd_avq_leader_count(int nb, int k) { return g_leaders[g_cb_leader[nb - 2][k]].count; }

/* ── placeholder codebooks ──────────────────────────────────────── */

static float g_lsf_mean[LPD_M];
static float g_lsf_dico[256][LPD_M];
static float g_gain7b[128][2];
static float g_bpf_lp[LPD_BPF_TAPS];
static float g_interp[LPD_UP_SAMP * LPD_INTERPOL * 2 + 1];

static void build_placeholders(void) {
  /* Mean LSF vector: even spacing over the band.  PLACEHOLDER for the
   * standard's trained mean vector. */
  for (int i = 0; i < LPD_M; i++) {
    g_lsf_mean[i] = LPD_LSF_MAX * (float)(i + 1) / (float)(LPD_M + 1);
  }
  /* First stage codebook: 256 ordered vectors spread around the mean by
   * eight smooth basis shapes keyed by the index bits.  PLACEHOLDER for
   * the standard's dico_lsf_abs_8b. */
  for (int idx = 0; idx < 256; idx++) {
    for (int i = 0; i < LPD_M; i++) {
      float v = g_lsf_mean[i];
      for (int b = 0; b < 8; b++) {
        const float sign = (idx >> b) & 1 ? 1.0f : -1.0f;
        const float amp = 110.0f / (1.0f + 0.35f * (float)b);
        v += sign * amp * cosf((float)M_PI * (float)(b + 1) * ((float)i + 0.5f) / (float)LPD_M);
      }
      g_lsf_dico[idx][i] = v;
    }
    /* keep the vector ordered with a minimum spacing */
    float lo = 50.0f;
    for (int i = 0; i < LPD_M; i++) {
      if (g_lsf_dico[idx][i] < lo) {
        g_lsf_dico[idx][i] = lo;
      }
      lo = g_lsf_dico[idx][i] + 50.0f;
    }
    float hi = LPD_LSF_MAX - 50.0f;
    for (int i = LPD_M - 1; i >= 0; i--) {
      if (g_lsf_dico[idx][i] > hi) {
        g_lsf_dico[idx][i] = hi;
      }
      hi = g_lsf_dico[idx][i] - 50.0f;
    }
  }
  /* Joint gain codebook: 16 pitch gains over 0 .. 1.2 by 8 innovation
   * gain factors over 1/8 .. 8.  PLACEHOLDER for the standard's 7 bit
   * table. */
  for (int idx = 0; idx < 128; idx++) {
    g_gain7b[idx][0] = 1.2f * (float)(idx >> 3) / 15.0f;
    g_gain7b[idx][1] = exp2f(((float)(idx & 7) - 3.5f) * 6.0f / 7.0f);
  }
  /* Bass postfilter low pass (7.17): a Hamming windowed sinc at a cutoff
   * of 1/16 of the sampling rate, symmetric, LPD_BPF_TAPS + 1 taps with
   * g_bpf_lp[k] the coefficient at lag k (k = 0 is the centre). */
  float sum = 0.0f;
  for (int k = 0; k < LPD_BPF_TAPS; k++) {
    const float x = (float)k;
    const float w = 0.54f + 0.46f * cosf((float)M_PI * x / (float)LPD_BPF_TAPS);
    const float s = k == 0 ? 1.0f : sinf((float)M_PI * x / 8.0f) / ((float)M_PI * x / 8.0f);
    g_bpf_lp[k] = w * s;
    sum += k == 0 ? g_bpf_lp[k] : 2.0f * g_bpf_lp[k];
  }
  for (int k = 0; k < LPD_BPF_TAPS; k++) {
    g_bpf_lp[k] /= sum;
  }
  /* The 1/4 resolution interpolation filter of the adaptive codebook
   * (7.14.3): a Hamming windowed sinc over +-LPD_INTERPOL samples with the
   * cutoff at 0.9 of the Nyquist frequency, indexed in quarter samples. */
  const int half = LPD_UP_SAMP * LPD_INTERPOL;
  for (int k = -half; k <= half; k++) {
    const float x = (float)k / (float)LPD_UP_SAMP;
    const float w = 0.54f + 0.46f * cosf((float)M_PI * (float)k / (float)(half + 1));
    const float s = k == 0 ? 0.9f : sinf(0.9f * (float)M_PI * x) / ((float)M_PI * x);
    g_interp[k + half] = w * s;
  }
}

void lpd_tables_init(void) {
  if (g_tables_ready) {
    return;
  }
  build_leaders();
  build_placeholders();
  g_tables_ready = 1;
}

const float* lpd_lsf_mean(void) { return g_lsf_mean; }

void lpd_lsf_first_stage(int index, float* lsf) {
  memcpy(lsf, g_lsf_dico[index & 255], sizeof(float) * LPD_M);
}

void lpd_gain_dequant(int index, float* gain_pit, float* gain_code_factor) {
  *gain_pit = g_gain7b[index & 127][0];
  *gain_code_factor = g_gain7b[index & 127][1];
}

const float* lpd_bpf_filter(void) { return g_bpf_lp; }
const float* lpd_interp_filter(void) { return g_interp; }
