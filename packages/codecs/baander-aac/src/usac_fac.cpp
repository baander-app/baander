/* Forward aliasing cancellation of the USAC LPD core (ISO/IEC 23003-3
 * 6.2.7.6, 7.16): the fac_data() syntax (an optional gain and the FAC
 * coefficients in algebraic vector quantised blocks of eight), and the
 * FAC synthesis: the lfac coefficients are transformed by an inverse
 * DCT-IV, the lfac samples extended with zeros to 2 lfac and filtered by
 * the weighted LPC synthesis filter 1 / A(z / gamma) of the boundary with
 * zero memory, so that the first half corrects the windowed and aliased
 * transform output before the boundary and the second half carries the
 * filter's response into the segment after it.  The zero input response
 * of the LPC synthesis filter after an ACELP segment is computed here as
 * well. */
#include <cmath>
#include <cstring>

#include "usac_lpd_internal.h"

int lpd_read_fac(AacBitReader* r, int use_gain, int length, LpdFacData* d) {
  d->present = 1;
  d->length = length;
  d->gain_index = use_gain ? (int)aac_bitreader_read(r, 7) : -1;
  for (int i = 0; i < length / 8; i++) {
    const int err = lpd_read_avq_block(r, 1, &d->blk[i]);
    if (err) {
      return err;
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* Orthonormal DCT-IV: out[n] = sqrt(2/N) sum_k in[k] cos(pi/N (n+1/2)(k+1/2)). */
void lpd_dct4(const float* in, float* out, int n) {
  const double scale = sqrt(2.0 / (double)n);
  for (int i = 0; i < n; i++) {
    double s = 0.0;
    for (int k = 0; k < n; k++) {
      s += (double)in[k] * cos(M_PI / (double)n * ((double)i + 0.5) * ((double)k + 0.5));
    }
    out[i] = (float)(s * scale);
  }
}

void lpd_fac_synth_q(const int* fac_q, int length, float gain, const float* a, float* out) {
  float coef[LPD_MAX_LFAC], t[2 * LPD_MAX_LFAC];
  for (int i = 0; i < length; i++) {
    coef[i] = (float)fac_q[i] * gain;
  }
  lpd_dct4(coef, t, length);
  memset(t + length, 0, sizeof(float) * (size_t)length);
  float ap[LPD_M + 1], mem[LPD_M];
  lpd_weight_a(a, LPD_GAMMA1, ap);
  memset(mem, 0, sizeof(mem));
  lpd_syn_filter(ap, t, out, 2 * length, mem);
}

/* `gain` applies when the data carries no gain of its own. */
void lpd_fac_synth(const LpdFacData* d, float gain, const float* a, float* out) {
  int q[LPD_MAX_LFAC];
  for (int i = 0; i < d->length / 8; i++) {
    lpd_avq_decode(&d->blk[i], q + 8 * i);
  }
  const float g = d->gain_index >= 0
                      ? powf(10.0f, (float)d->gain_index / 28.0f) / sqrtf((float)d->length)
                      : gain;
  lpd_fac_synth_q(q, d->length, g, a, out);
}

/* Zero input response of 1 / A(z) from the memory `mem` (last outputs,
 * most recent first). */
void lpd_zir(const float* a, const float* mem, float* out, int n) {
  float m[LPD_M];
  memcpy(m, mem, sizeof(m));
  float zero[2 * LPD_MAX_LFAC];
  memset(zero, 0, sizeof(float) * (size_t)n);
  lpd_syn_filter(a, zero, out, n, m);
}
