#include "ps.h"

#include <cmath>

void aac_ps_encode(AacPsParams* ps, const float qmf_L[64][32], const float qmf_R[64][32], int nb) {
  ps->enable_iid = 1;
  ps->enable_icc = 1;
  ps->num_iid_bands = nb;
  ps->num_icc_bands = nb;
  for (int b = 0; b < nb; b++) {
    float eL = 0, eR = 0, cross = 0;
    for (int ts = 0; ts < 32; ts++) {
      eL += qmf_L[b][ts] * qmf_L[b][ts];
      eR += qmf_R[b][ts] * qmf_R[b][ts];
      cross += qmf_L[b][ts] * qmf_R[b][ts];
    }
    ps->iid[b] = 10.0f * log10f((eL + 1e-10f) / (eR + 1e-10f));
    ps->icc[b] = cross / (sqrtf(eL * eR) + 1e-10f);
  }
}

void aac_ps_decode(AacPsParams* ps, const float qmf_mono[64][32], float qmf_L[64][32],
                   float qmf_R[64][32], int nb) {
  for (int b = 0; b < nb; b++) {
    float iid = powf(10.0f, ps->iid[b] / 20.0f);
    float h11 = sqrtf(2.0f / (1.0f + iid));
    float h12 = sqrtf(2.0f * iid / (1.0f + iid));
    float c = ps->icc[b];
    for (int ts = 0; ts < 32; ts++) {
      float m = qmf_mono[b][ts];
      qmf_L[b][ts] = h11 * m;
      qmf_R[b][ts] = h12 * m * c;
    }
  }
}
