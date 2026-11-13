#include "qmf_ld.h"

#include <cmath>
#include <cstring>
#include <mutex>

#include "qmf_sums.h"

/* Low-delay complex filterbank: the direct-sum structure of qmf.cpp with
 * the four windows of qmf_ld_window.inc, one per bank (analysis and
 * synthesis, 64 and 32 bands), designed together so that every pairing
 * reconstructs its input (tools/cldfb_design.py).  The sums and the
 * layout of their tables are those of qmf_sums.h. */

namespace {

#include "qmf_ld_window.inc"

float g_ana_cos32[64][32], g_ana_sin32[64][32];
float g_ana_cos64[128][64], g_ana_sin64[128][64];
float g_syn_cos64[64][128], g_syn_sin64[64][128];
float g_syn_cos32[32][64], g_syn_sin32[32][64];
void build_tables(void) {
  for (int k = 0; k < 32; k++) {
    for (int n = 0; n < 64; n++) {
      const double a = M_PI / 64.0 * (k + 0.5) * (2.0 * n - 0.5);
      g_ana_cos32[n][k] = (float)(2.0 * cos(a));
      g_ana_sin32[n][k] = (float)(2.0 * sin(a));
    }
  }
  for (int k = 0; k < 64; k++) {
    for (int n = 0; n < 128; n++) {
      const double a = M_PI / 128.0 * (k + 0.5) * (2.0 * n - 1.0);
      g_ana_cos64[n][k] = (float)cos(a);
      g_ana_sin64[n][k] = (float)sin(a);
    }
  }
  for (int n = 0; n < 128; n++) {
    for (int k = 0; k < 64; k++) {
      const double a = M_PI / 128.0 * (k + 0.5) * (2.0 * n - 255.0);
      g_syn_cos64[k][n] = (float)(cos(a) / 64.0);
      g_syn_sin64[k][n] = (float)(sin(a) / 64.0);
    }
  }
  for (int n = 0; n < 64; n++) {
    for (int k = 0; k < 32; k++) {
      const double a = M_PI / 64.0 * (k + 0.5) * (2.0 * n - 127.5);
      g_syn_cos32[k][n] = (float)(cos(a) / 64.0);
      g_syn_sin32[k][n] = (float)(sin(a) / 64.0);
    }
  }
}

}  // namespace

void aac_cldfb_tables_init(void) {
  static std::once_flag once;
  std::call_once(once, build_tables);
}

void aac_cldfb_analysis_init(AacCldfbAnalysis* a, int bands) {
  aac_cldfb_tables_init();
  a->bands = bands;
  memset(a->x, 0, sizeof(a->x));
}

void aac_cldfb_analyze(AacCldfbAnalysis* a, const float* in, float (*W)[2]) {
  const int B = a->bands;
  const int len = 10 * B;
  const float* window = B == 64 ? kCldfbAnalysis64 : kCldfbAnalysis32;
  float* x = a->x;
  memmove(x + B, x, (size_t)(len - B) * sizeof(float));
  for (int n = 0; n < B; n++) {
    x[n] = in[B - 1 - n];
  }
  float u[128];
  aac_qmf_fold(u, x, window, 2 * B);
  if (B == 32) {
    aac_qmf_modulate(W, u, &g_ana_cos32[0][0], &g_ana_sin32[0][0], 32, 64);
  } else {
    aac_qmf_modulate(W, u, &g_ana_cos64[0][0], &g_ana_sin64[0][0], 64, 128);
  }
}

void aac_cldfb_synthesis_init(AacCldfbSynthesis* s, int bands) {
  aac_cldfb_tables_init();
  s->bands = bands;
  memset(s->v, 0, sizeof(s->v));
  s->off = 18 * bands;
}

void aac_cldfb_synthesize(AacCldfbSynthesis* s, const float (*X)[2], float* out) {
  const int B = s->bands;
  const int block = 2 * B;
  const int history = 18 * B;
  const int size = 2 * history;
  const float* window = B == 64 ? kCldfbSynthesis64 : kCldfbSynthesis32;
  if (s->off < block) {
    memcpy(s->v + size - history, s->v, (size_t)history * sizeof(float));
    s->off = size - history - block;
  } else {
    s->off -= block;
  }
  float* v = s->v + s->off;
  if (B == 64) {
    aac_qmf_demodulate(v, X, &g_syn_cos64[0][0], &g_syn_sin64[0][0], 64, 128);
  } else {
    aac_qmf_demodulate(v, X, &g_syn_cos32[0][0], &g_syn_sin32[0][0], 32, 64);
  }
  aac_qmf_overlap(out, v, window, B);
}
