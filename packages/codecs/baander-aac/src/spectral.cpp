#include "spectral.h"

#include <cmath>
#include <cstdlib>

namespace {
/** Thread-local PNS seed for reproducible, thread-safe noise generation. */
static thread_local unsigned int pns_seed = 12345u;
}  // namespace

#include "aac_tables.h"

void aac_tns_encode(AacTnsInfo* tns, float* spec, int /*nb*/, const int* sfb, int ri, int /*ws*/) {
  int max_order = aac_tns_max_order_long;
  int end = sfb[aac_tns_max_bands_long[ri]];

  /* Compute autocorrelation */
  float ac[20] = {};
  for (int lag = 0; lag <= max_order; lag++) {
    ac[lag] = 0;
    for (int i = 0; i < end - lag; i++) {
      ac[lag] += spec[i] * spec[i + lag];
    }
  }

  /* Levinson-Durbin recursion */
  float a[20] = {};
  float error = ac[0];
  int order = 0;
  for (int m = 1; m <= max_order && error > 1e-10f; m++) {
    float km = ac[m];
    for (int k = 0; k < m - 1; k++) {
      km -= a[k] * ac[m - 1 - k];
    }
    km /= error;
    a[m - 1] = km;
    for (int k = 0; k < (m - 1) / 2; k++) {
      float t = a[k];
      a[k] = a[m - 2 - k];
      a[m - 2 - k] = t - km * t;
    }
    error *= (1.0f - km * km);
    if (fabsf(km) > 0.1f) {
      order = m;
    }
  }

  /* Apply all-zero filter */
  for (int i = end - 1; i >= 0; i--) {
    for (int k = 0; k < order; k++) {
      if (i + k + 1 < end) {
        spec[i] -= a[k] * spec[i + k + 1];
      }
    }
  }

  tns->order[0][0] = order;
  for (int k = 0; k < order; k++) {
    tns->lpc[0][0][k] = a[k];
  }
}

void aac_tns_decode(AacTnsInfo* tns, float* spec, int /*nb*/, const int* sfb, int ri, int /*ws*/) {
  int order = tns->order[0][0];
  if (order <= 0) {
    return;
  }
  int end = sfb[aac_tns_max_bands_long[ri]];
  for (int i = 0; i < end; i++) {
    for (int k = 0; k < order; k++) {
      if (i - k - 1 >= 0) {
        spec[i] += tns->lpc[0][0][k] * spec[i - k - 1];
      }
    }
  }
}

void aac_pns_replace(float* spec, int sfb, const int* sfb_off, float energy, int /*fs*/) {
  int s = sfb_off[sfb], e = sfb_off[sfb + 1];
  float sc = sqrtf(energy / (float)(e - s));
  /* Simple PRNG — not thread-safe, replace with per-instance xorshift for production */
  for (int i = s; i < e; i++) {
    float r =
        (float)rand_r(&pns_seed) / (float)RAND_MAX * 2.0f - 1.0f;  // NOLINT(concurrency-mt-unsafe)
    spec[i] = sc * r;
  }
}

void aac_pns_restore(float* spec, int sfb, const int* sfb_off, float energy, int fs) {
  aac_pns_replace(spec, sfb, sfb_off, energy, fs);
}

void aac_ms_encode(float* mid, float* side, const float* L, const float* R, int n) {
  for (int i = 0; i < n; i++) {
    mid[i] = (L[i] + R[i]) * 0.707f;
    side[i] = (L[i] - R[i]) * 0.707f;
  }
}

void aac_ms_decode(float* L, float* R, const float* mid, const float* side, int n) {
  for (int i = 0; i < n; i++) {
    L[i] = (mid[i] + side[i]) * 0.707f;
    R[i] = (mid[i] - side[i]) * 0.707f;
  }
}

void aac_intensity_decode(float* L, float* R, const float* spec, float scale, int start, int end) {
  for (int i = start; i < end; i++) {
    L[i] = spec[i] * scale;
    R[i] = spec[i] * (1.0f - scale);
  }
}
