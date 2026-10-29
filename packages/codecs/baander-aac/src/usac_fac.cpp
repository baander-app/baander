/* The forward aliasing cancellation of the USAC LPD core (ISO/IEC
 * 23003-3 7.16): the lattice coded FAC coefficients, their DCT-IV and
 * the weighted synthesis filter, and the windows that fold a segment
 * into a transform's aliasing region. */
#include <cmath>
#include <cstring>

#include "usac_lpd_internal.h"

namespace {

constexpr double kPi = 3.14159265358979323846;

/* The cosine tables of the DCT-IV lengths in use, built on first use. */
struct Dct4Table {
  int n;
  float* cos_table;
};

const float* dct4_table(int n) {
  static Dct4Table tables[8];
  static float storage[8][LPD_MAX_LFAC * LPD_MAX_LFAC];
  for (int t = 0; t < 8; t++) {
    if (tables[t].n == n) {
      return tables[t].cos_table;
    }
    if (tables[t].n == 0) {
      float* c = storage[t];
      for (int k = 0; k < n; k++) {
        for (int j = 0; j < n; j++) {
          c[k * n + j] = (float)cos(kPi / n * (j + 0.5) * (k + 0.5));
        }
      }
      tables[t].cos_table = c;
      tables[t].n = n;
      return c;
    }
  }
  return nullptr;
}

}  // namespace

void lpd_sine_window(int n, float* w) {
  for (int i = 0; i < n; i++) {
    w[i] = (float)sin(kPi * (i + 0.5) / (2.0 * n));
  }
}

int lpd_read_fac(AacBitReader* r, int length, int* coef) {
  for (int i = 0; i < length; i += 8) {
    int qn = 0;
    while (aac_bitreader_read(r, 1) == 1u) {
      qn++;
      if (qn > 40) {
        return AAC_ERR_DECODE;
      }
    }
    if (qn > 0) {
      qn++;
    }
    const int ret = lpd_read_avq_block(r, qn, coef + i);
    if (ret != AAC_OK) {
      return ret;
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

void lpd_dct4(const float* in, float* out, int n) {
  const float* c = dct4_table(n);
  for (int k = 0; k < n; k++) {
    const float* row = c + k * n;
    float s = 0.0f;
    for (int j = 0; j < n; j++) {
      s += in[j] * row[j];
    }
    out[k] = s;
  }
}

void lpd_fac_synth(const float* x, int length, const float* a, float* out, int out_len) {
  float ap[LPD_M + 1];
  float buf[LPD_M + 2 * LPD_MAX_LFAC];
  float* y = buf + LPD_M;
  memset(buf, 0, sizeof(buf));
  lpd_dct4(x, y, length);
  const float scale = 2.0f / (float)length;
  for (int i = 0; i < length; i++) {
    y[i] *= scale;
  }
  lpd_weight_a(a, LPD_GAMMA, ap);
  lpd_syn_filter(ap, y, out_len);
  memcpy(out, y, (size_t)out_len * sizeof(float));
}

void lpd_fac_windows(int lfac, float* past_w, float* zir_w) {
  float w[2 * LPD_MAX_LFAC];
  lpd_sine_window(2 * lfac, w);
  for (int i = 0; i < lfac; i++) {
    past_w[i] = w[i] * w[2 * lfac - 1 - i];
    zir_w[i] = 1.0f - w[lfac + i] * w[lfac + i];
  }
}
