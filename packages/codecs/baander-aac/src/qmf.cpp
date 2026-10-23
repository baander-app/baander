#include "qmf.h"

#include <cmath>
#include <cstring>

/* Complex QMF banks (ISO/IEC 14496-3 4.6.18.4 and 4.6.18.8, 4.B.18.2) as
 * direct sums over the modulation tables: a slot costs bands * 2 * bands
 * multiplications per direction, which is small next to the rest of the
 * decoder.
 *
 * Analysis with B bands: the window of 10 B taps (the prototype, or every
 * second tap for 32 bands) weights the newest 10 B input samples, the
 * products are folded into 2 B values u(n), and
 *   W(k) = g sum_n u(n) exp(i pi / (2 B) (k + 1/2) (2 n - d)),
 * with g = 2 and d = 1/2 for the 32-band bank on the half-rate core signal
 * (4.6.18.4.1) and g = 1 and d = 1 for the 64-band bank on the full-rate
 * signal (4.B.18.2), so that both banks give the low band the same
 * magnitudes.
 *
 * Synthesis with B bands: v(n) = 1/64 Re{sum_k X(k) exp(i pi / (2 B)
 * (k + 1/2) (2 n - c))} for n < 2 B, with c = 255 for 64 bands and
 * c = 127.5 for the downsampled 32-band bank; the newest block of v is
 * windowed together with alternating halves of the nine older ones, and
 * the ten windowed blocks are summed to B output samples.  Both
 * conventions were pinned against FFmpeg's and FAAD2's filterbanks. */

namespace {


float g_ana_cos32[32][64], g_ana_sin32[32][64];
float g_ana_cos64[64][128], g_ana_sin64[64][128];
float g_syn_cos64[128][64], g_syn_sin64[128][64];
float g_syn_cos32[64][32], g_syn_sin32[64][32];
bool g_tables_ready = false;

}  // namespace

void aac_qmf_tables_init(void) {
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

void aac_qmf_analysis_init(AacQmfAnalysis* a, int bands) {
  aac_qmf_tables_init();
  a->bands = bands;
  memset(a->x, 0, sizeof(a->x));
}

void aac_qmf_analyze(AacQmfAnalysis* a, const float* in, float (*W)[2]) {
  const int B = a->bands;
  const int len = 10 * B;
  const int step = 640 / len; /* prototype taps per window tap */
  float* x = a->x;
  /* Newest sample first: shift the history up and insert the block
   * reversed. */
  memmove(x + B, x, (size_t)(len - B) * sizeof(float));
  for (int n = 0; n < B; n++) {
    x[n] = in[B - 1 - n];
  }
  float u[128];
  for (int n = 0; n < 2 * B; n++) {
    float sum = 0.0f;
    for (int j = 0; j < 5; j++) {
      const int idx = n + 2 * B * j;
      sum += x[idx] * aac_sbr_qmf_window[idx * step];
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

void aac_qmf_synthesis_init(AacQmfSynthesis* s, int bands) {
  aac_qmf_tables_init();
  s->bands = bands;
  memset(s->v, 0, sizeof(s->v));
  s->off = 18 * bands;
}

void aac_qmf_synthesize(AacQmfSynthesis* s, const float (*X)[2], float* out) {
  const int B = s->bands;
  const int block = 2 * B;
  const int history = 18 * B; /* nine older blocks */
  const int size = 2 * history;
  const int step = 640 / (10 * B);
  /* The newest block goes below the older ones; when the space in front
   * runs out, the nine blocks at the start move to the end. */
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
  /* g takes the first half of even blocks and the second half of odd
   * ones; w = g * c; the output sums the ten windowed segments. */
  for (int k = 0; k < B; k++) {
    float acc = 0.0f;
    for (int j = 0; j < 5; j++) {
      const int g0 = 2 * block * j + k;         /* g(2 B (2 j) + k) */
      const int g1 = 2 * block * j + 3 * B + k; /* g(2 B (2 j + 1) + k) */
      acc += v[g0] * aac_sbr_qmf_window[(2 * j * B + k) * step] +
             v[g1] * aac_sbr_qmf_window[((2 * j + 1) * B + k) * step];
    }
    out[k] = acc;
  }
}
