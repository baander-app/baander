/* The complex stereo prediction of USAC (ISO/IEC 23003-3 7.7.2): the
 * MDST of the downmix is estimated from its MDCT with short filters over
 * frequency, one over the current window and one over the previous
 * window, whose taps depend on the window sequence and on the shapes of
 * the two window slopes (Tables 7.x).  The residual channel is then
 * completed with the real and imaginary prediction. */
#include <cstring>

#include "mdct.h"
#include "usac_fd.h"

namespace {

/* Current-window filters, antisymmetric; index [previous shape][current
 * shape] with 0 sine and 1 KBD. */
const float kFiltLong[2][2][7] = {
    {{0.000000f, 0.000000f, -0.500000f, 0.000000f, 0.500000f, 0.000000f, 0.000000f},
     {-0.045746f, -0.057251f, -0.540710f, 0.000000f, 0.540710f, 0.057251f, 0.045746f}},
    {{-0.045746f, 0.057251f, -0.540710f, 0.000000f, 0.540710f, -0.057251f, 0.045746f},
     {-0.091492f, 0.000000f, -0.581421f, 0.000000f, 0.581421f, 0.000000f, 0.091492f}},
};
const float kFiltStart[2][2][7] = {
    {{-0.102661f, -0.103790f, -0.567139f, 0.000000f, 0.567139f, 0.103790f, 0.102661f},
     {-0.104767f, -0.105194f, -0.567871f, 0.000000f, 0.567871f, 0.105194f, 0.104767f}},
    {{-0.148407f, -0.046539f, -0.607849f, 0.000000f, 0.607849f, 0.046539f, 0.148407f},
     {-0.150513f, -0.047974f, -0.608582f, 0.000000f, 0.608582f, 0.047974f, 0.150513f}},
};
const float kFiltStop[2][2][7] = {
    {{-0.102661f, 0.103790f, -0.567139f, 0.000000f, 0.567139f, -0.103790f, 0.102661f},
     {-0.148407f, 0.046539f, -0.607849f, 0.000000f, 0.607849f, -0.046539f, 0.148407f}},
    {{-0.104767f, 0.105194f, -0.567871f, 0.000000f, 0.567871f, -0.105194f, 0.104767f},
     {-0.150513f, 0.047974f, -0.608582f, 0.000000f, 0.608582f, -0.047974f, 0.150513f}},
};
const float kFiltStopStart[2][2][7] = {
    {{-0.205322f, 0.000000f, -0.634308f, 0.000000f, 0.634308f, 0.000000f, 0.205322f},
     {-0.207428f, -0.001404f, -0.635010f, 0.000000f, 0.635010f, 0.001404f, 0.207428f}},
    {{-0.207428f, 0.001404f, -0.635010f, 0.000000f, 0.635010f, -0.001404f, 0.207428f},
     {-0.209534f, 0.000000f, -0.635712f, 0.000000f, 0.635712f, 0.000000f, 0.209534f}},
};
/* Previous-window filters, symmetric; index [previous shape].  The first
 * set serves a long left slope (ONLY_LONG, LONG_START, EIGHT_SHORT), the
 * second a short one (LONG_STOP, STOP_START). */
const float kFiltPrevLong[2][7] = {
    {0.000000f, 0.106110f, 0.250000f, 0.318298f, 0.250000f, 0.106110f, 0.000000f},
    {0.059509f, 0.123718f, 0.186584f, 0.213074f, 0.186584f, 0.123718f, 0.059509f},
};
const float kFiltPrevShort[2][7] = {
    {0.038513f, 0.039215f, 0.039642f, 0.039795f, 0.039642f, 0.039215f, 0.038513f},
    {0.026154f, 0.026428f, 0.026581f, 0.026642f, 0.026581f, 0.026428f, 0.026154f},
};

/* in[k] for k outside [0, n) is the mirror image: in[-1 - m] = in[m],
 * in[n + m] = in[n - 1 - m]. */
inline float at(const float* in, int n, int k) {
  if (k < 0) {
    k = -1 - k;
  } else if (k >= n) {
    k = 2 * n - 1 - k;
  }
  return in[k];
}

void filter_add(const float* in, int n, const float* filt, float sign_even, float sign_odd,
                float* out) {
  for (int k = 0; k < n; k++) {
    float sum = 0.0f;
    for (int j = 0; j < 7; j++) {
      sum += filt[j] * at(in, n, k - 3 + j);
    }
    out[k] += (k & 1) ? sign_odd * sum : sign_even * sum;
  }
}

}  // namespace

void aac_usac_fd_mdst_estimate(const float* dmx_re, const float* dmx_prev, int n,
                               int window_sequence, int prev_shape, int shape, float* dmx_im) {
  const float* curr;
  const float* prev;
  prev_shape = prev_shape ? 1 : 0;
  shape = shape ? 1 : 0;
  switch (window_sequence) {
    case AAC_WIN_LONG_START:
      curr = kFiltStart[prev_shape][shape];
      prev = kFiltPrevLong[prev_shape];
      break;
    case AAC_WIN_LONG_STOP:
      curr = kFiltStop[prev_shape][shape];
      prev = kFiltPrevShort[prev_shape];
      break;
    case AAC_USAC_WIN_STOP_START:
      curr = kFiltStopStart[prev_shape][shape];
      prev = kFiltPrevShort[prev_shape];
      break;
    default: /* ONLY_LONG and the short windows */
      curr = kFiltLong[prev_shape][shape];
      prev = kFiltPrevLong[prev_shape];
      break;
  }
  memset(dmx_im, 0, (size_t)n * sizeof(float));
  filter_add(dmx_re, n, curr, 1.0f, 1.0f, dmx_im);
  if (dmx_prev) {
    filter_add(dmx_prev, n, prev, -1.0f, 1.0f, dmx_im);
  }
}

void aac_usac_fd_cplx_predict(float* ch0, float* ch1, const float* dmx_re, const float* dmx_im,
                              int len, float alpha_re, float alpha_im, int pred_dir) {
  for (int k = 0; k < len; k++) {
    /* ch0 carries the downmix (mid, or side with pred_dir), ch1 the
     * residual of the other signal after the prediction. */
    const float other = ch1[k] - alpha_re * dmx_re[k] - alpha_im * dmx_im[k];
    const float dmx = ch0[k];
    if (pred_dir) {
      ch0[k] = other + dmx;
      ch1[k] = other - dmx;
    } else {
      ch0[k] = dmx + other;
      ch1[k] = dmx - other;
    }
  }
}
