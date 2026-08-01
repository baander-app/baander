#include "ps.h"

#include <cmath>
#include <cstring>

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

/* Parametric Stereo up-mix.
 *
 * Implements the Purnhagen / Coding Technologies baseline PS decoder
 * (MPEG-4 Audio subpart 8) with a simple delay-based decorrelator:
 *   D[b] is a per-band delayed copy of the mono signal, so that
 *   E{M*D} ≈ 0 while E{D*D} ≈ E{M*M}.
 *
 * The up-mix matrix is
 *   | L |   | h11  h12 | | M |
 *   | R | = | h21  h22 | | D |
 * with cl = sqrt(2c^2/(1+c^2)), cr = sqrt(2/(1+c^2)), c = 10^(IID/20),
 * alpha = acos(ICC)/2 and beta = atan(tan(alpha)*(cr-cl)/(cr+cl)).
 *
 * This preserves total power E{L^2}+E{R^2}=2E{M^2} for the direct path,
 * applies the correct inter-channel intensity difference, and synthesizes
 * the inter-channel coherence via the decorrelated signal instead of
 * simply scaling the right channel by ICC (which loses energy and width).
 */
void aac_ps_decode(AacPsParams* ps, const float qmf_mono[64][32], float qmf_L[64][32],
                   float qmf_R[64][32], int nb) {
  if (nb > AAC_PS_MAX_BANDS) {
    nb = AAC_PS_MAX_BANDS;
  }

  if (!ps->decorr_initialized) {
    memset(ps->decorr_state, 0, sizeof(ps->decorr_state));
    memset(ps->decorr_pos, 0, sizeof(ps->decorr_pos));
    ps->decorr_initialized = 1;
  }

  for (int b = 0; b < nb; b++) {
    /* Per-band decorrelation delay: low bands get longer delay for
     * better low-frequency decorrelation, high bands shorter delay to
     * avoid smearing transients. */
    const int delay = (b < 8) ? (b / 2 + 2) : 5;

    float c = powf(10.0f, ps->iid[b] / 20.0f);
    float c2 = c * c;
    float cl = sqrtf(2.0f * c2 / (1.0f + c2));
    float cr = sqrtf(2.0f / (1.0f + c2));
    float icc = fminf(fmaxf(ps->icc[b], -1.0f), 1.0f);
    float alpha = 0.5f * acosf(icc);
    float beta = atanf(tanf(alpha) * (cr - cl) / (cr + cl));
    float h11 = cl * cosf(beta + alpha);
    float h12 = cl * sinf(beta + alpha);
    float h21 = cr * cosf(beta - alpha);
    float h22 = cr * sinf(beta - alpha);

    int pos = ps->decorr_pos[b];
    for (int ts = 0; ts < 32; ts++) {
      float m = qmf_mono[b][ts];
      /* Read the delayed sample, then write the current one */
      float d = ps->decorr_state[b][(pos - delay + AAC_PS_DECORR_SIZE) & (AAC_PS_DECORR_SIZE - 1)];
      ps->decorr_state[b][pos] = m;
      pos = (pos + 1) & (AAC_PS_DECORR_SIZE - 1);

      qmf_L[b][ts] = h11 * m + h12 * d;
      qmf_R[b][ts] = h21 * m + h22 * d;
    }
    ps->decorr_pos[b] = pos;
  }
}
