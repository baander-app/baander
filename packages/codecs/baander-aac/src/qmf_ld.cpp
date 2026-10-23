#include "qmf_ld.h"

#include <cmath>
#include <cstring>

/* Low-delay complex filterbank: the direct-sum structure of qmf.cpp with
 * the four windows of qmf_ld_window.inc, one per bank (analysis and
 * synthesis, 64 and 32 bands), designed together so that every pairing
 * reconstructs its input (tools/cldfb_design.py). */

namespace {

#include "qmf_ld_window.inc"

float g_ana_cos32[32][64], g_ana_sin32[32][64];
float g_ana_cos64[64][128], g_ana_sin64[64][128];
float g_syn_cos64[128][64], g_syn_sin64[128][64];
float g_syn_cos32[64][32], g_syn_sin32[64][32];
bool g_tables_ready = false;

}  // namespace

void aac_cldfb_tables_init(void) {
  if (g_tables_ready) {
    return;
  }
  for (int k = 0; k < 32; k++) {
    for (int n = 0; n < 64; n++) {
      const double a = M_PI / 64.0 * (k + 0.5) * (2.0 * n - 0.5);
      g_ana_cos32[k][n] = (float)(2.0 * cos(a));
      g_ana_sin32[k][n] = (float)(2.0 * sin(a));
    }
  }
  for (int k = 0; k < 64; k++) {
    for (int n = 0; n < 128; n++) {
      const double a = M_PI / 128.0 * (k + 0.5) * (2.0 * n - 1.0);
      g_ana_cos64[k][n] = (float)cos(a);
      g_ana_sin64[k][n] = (float)sin(a);
    }
  }
  for (int n = 0; n < 128; n++) {
    for (int k = 0; k < 64; k++) {
      const double a = M_PI / 128.0 * (k + 0.5) * (2.0 * n - 255.0);
      g_syn_cos64[n][k] = (float)(cos(a) / 64.0);
      g_syn_sin64[n][k] = (float)(sin(a) / 64.0);
    }
  }
  for (int n = 0; n < 64; n++) {
    for (int k = 0; k < 32; k++) {
      const double a = M_PI / 64.0 * (k + 0.5) * (2.0 * n - 127.5);
      g_syn_cos32[n][k] = (float)(cos(a) / 64.0);
      g_syn_sin32[n][k] = (float)(sin(a) / 64.0);
    }
  }
  g_tables_ready = true;
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
  for (int n = 0; n < 2 * B; n++) {
    float sum = 0.0f;
    for (int j = 0; j < 5; j++) {
      const int idx = n + 2 * B * j;
      sum += x[idx] * window[idx];
    }
    u[n] = sum;
  }
  if (B == 32) {
    for (int k = 0; k < 32; k++) {
      float re = 0.0f;
      float im = 0.0f;
      const float* c = g_ana_cos32[k];
      const float* s = g_ana_sin32[k];
      for (int n = 0; n < 64; n++) {
        re += u[n] * c[n];
        im += u[n] * s[n];
      }
      W[k][0] = re;
      W[k][1] = im;
    }
  } else {
    for (int k = 0; k < 64; k++) {
      float re = 0.0f;
      float im = 0.0f;
      const float* c = g_ana_cos64[k];
      const float* s = g_ana_sin64[k];
      for (int n = 0; n < 128; n++) {
        re += u[n] * c[n];
        im += u[n] * s[n];
      }
      W[k][0] = re;
      W[k][1] = im;
    }
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
    for (int n = 0; n < 128; n++) {
      const float* c = g_syn_cos64[n];
      const float* sn = g_syn_sin64[n];
      float acc = 0.0f;
      for (int k = 0; k < 64; k++) {
        acc += X[k][0] * c[k] - X[k][1] * sn[k];
      }
      v[n] = acc;
    }
  } else {
    for (int n = 0; n < 64; n++) {
      const float* c = g_syn_cos32[n];
      const float* sn = g_syn_sin32[n];
      float acc = 0.0f;
      for (int k = 0; k < 32; k++) {
        acc += X[k][0] * c[k] - X[k][1] * sn[k];
      }
      v[n] = acc;
    }
  }
  for (int k = 0; k < B; k++) {
    float acc = 0.0f;
    for (int j = 0; j < 5; j++) {
      const int g0 = 2 * block * j + k;
      const int g1 = 2 * block * j + 3 * B + k;
      acc += v[g0] * window[2 * j * B + k] + v[g1] * window[(2 * j + 1) * B + k];
    }
    out[k] = acc;
  }
}
