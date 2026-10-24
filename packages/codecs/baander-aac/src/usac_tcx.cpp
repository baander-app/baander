/* TCX of the USAC LPD core (ISO/IEC 23003-3 6.2.7.5, 7.15): the
 * tcx_coding() syntax, the context based arithmetic decoding of the
 * spectrum (this module's own copy of the decoder of 7.4, to be unified
 * with the FD core's), the noise filling of empty regions, the adaptive
 * low frequency de-emphasis, the spectral shaping by the weighted LPC
 * envelopes at both ends of the frame, the global gain, the inverse MDCT
 * and the TCX windows with their neighbour dependent slopes.
 *
 * The probability models of the arithmetic decoder (ari_cf_m, the
 * ari_hash_m / ari_lookup_m context hashing and ari_cf_r) are the
 * standard's trained tables and are not reproduced here: the decoder
 * below is the standard's engine with a PLACEHOLDER context model whose
 * cumulative frequencies are geometric distributions, so that a frame
 * parses and reconstructs mechanically but not to the standard. */
#include <cmath>
#include <cstring>

#include "usac_lpd_internal.h"

#define ARITH_ESCAPE 16
#define ARITH_MODELS 64

/* ── the arithmetic decoder (7.4.2) ─────────────────────────────── */

using ArithDec = struct ArithDec_ {
  AacBitReader* r;
  uint32_t low, high, value;
};

static void arith_start(ArithDec* d, AacBitReader* r) {
  d->r = r;
  d->low = 0;
  d->high = 65535;
  d->value = aac_bitreader_read(r, 16);
}

/* Decodes one symbol against the descending cumulative frequency table
 * cf[0 .. n] (cf[0] = 16384, cf[n] = 0). */
static int arith_decode_symbol(ArithDec* d, const uint16_t* cf, int n) {
  const uint32_t range = d->high - d->low + 1;
  const uint32_t cum = ((((d->value - d->low) + 1) << 14) - 1) / range;
  int s = 0;
  while (s < n - 1 && cf[s + 1] > cum) {
    s++;
  }
  d->high = d->low + ((range * cf[s]) >> 14) - 1;
  d->low = d->low + ((range * cf[s + 1]) >> 14);
  for (;;) {
    if (d->high < 32768) {
      /* nothing */
    } else if (d->low >= 32768) {
      d->value -= 32768;
      d->low -= 32768;
      d->high -= 32768;
    } else if (d->low >= 16384 && d->high < 49152) {
      d->value -= 16384;
      d->low -= 16384;
      d->high -= 16384;
    } else {
      break;
    }
    d->low += d->low;
    d->high += d->high + 1;
    d->value = (d->value << 1) | aac_bitreader_read(d->r, 1);
  }
  return s;
}

/* The decoder reads 16 bits ahead; after the last symbol the 14 bits
 * beyond the encoder's flush belong to the following syntax. */
static void arith_finish(ArithDec* d) { d->r->pos -= 14; }

/* PLACEHOLDER models: 64 geometric distributions over the 16 MSB symbols
 * and the escape, one per context class. */
static uint16_t g_cf_m[ARITH_MODELS][18];
static uint16_t g_cf_r[3][5];
static int g_models_ready;

static void build_models(void) {
  for (int p = 0; p < ARITH_MODELS; p++) {
    double w[17];
    double sum = 0.0;
    const double decay = 0.9 - 0.85 * (double)p / (double)(ARITH_MODELS - 1);
    for (int s = 0; s < 17; s++) {
      w[s] = pow(decay, s);
      sum += w[s];
    }
    double acc = 0.0;
    g_cf_m[p][0] = 16384;
    for (int s = 0; s < 17; s++) {
      acc += w[s];
      int v = 16384 - (int)(acc / sum * 16384.0 + 0.5);
      if (v > g_cf_m[p][s] - 1) {
        v = g_cf_m[p][s] - 1;
      }
      if (v < 16 - s) {
        v = 16 - s;
      }
      g_cf_m[p][s + 1] = (uint16_t)v;
    }
    g_cf_m[p][17] = 0;
  }
  for (int t = 0; t < 3; t++) {
    g_cf_r[t][0] = 16384;
    g_cf_r[t][1] = 12288;
    g_cf_r[t][2] = 8192;
    g_cf_r[t][3] = 4096;
    g_cf_r[t][4] = 0;
  }
  g_models_ready = 1;
}

/* Context class of tuple i from the neighbours: the previous frame's
 * tuples i - 1, i, i + 1 and this frame's i - 1 and i - 2, each held as a
 * magnitude class (PLACEHOLDER for the standard's hashed state). */
static int arith_get_pk(const uint8_t* prev, const uint8_t* cur, int i, int n, int esc) {
  int s = 0;
  if (i > 0) {
    s += prev[i - 1] + 2 * cur[i - 1];
  }
  if (i > 1) {
    s += cur[i - 2];
  }
  s += 2 * prev[i];
  if (i + 1 < n) {
    s += prev[i + 1];
  }
  s += 8 * esc;
  return s >= ARITH_MODELS ? ARITH_MODELS - 1 : s;
}

/* The model tables and the context rule, for the tests' encoder. */
const uint16_t* lpd_arith_cf_m(int pk) {
  if (!g_models_ready) {
    build_models();
  }
  return g_cf_m[pk < 0 ? 0 : (pk >= ARITH_MODELS ? ARITH_MODELS - 1 : pk)];
}

const uint16_t* lpd_arith_cf_r(int t) {
  if (!g_models_ready) {
    build_models();
  }
  return g_cf_r[t < 0 ? 0 : (t > 2 ? 2 : t)];
}

int lpd_arith_pk(const uint8_t* prev, const uint8_t* cur, int i, int n, int esc) {
  return arith_get_pk(prev, cur, i, n, esc);
}

void lpd_arith_reset(LpdArithState* st) {
  st->lg_prev = 0;
  memset(st->ctx_prev, 0, sizeof(st->ctx_prev));
}

/* The context of the previous frame mapped to the current number of
 * tuples (7.4.3). */
static void map_context(const LpdArithState* st, int lg, uint8_t* prev) {
  const int n = lg / 2;
  if (st->lg_prev == 0) {
    memset(prev, 0, (size_t)n + 1);
    return;
  }
  const int np = st->lg_prev / 2;
  for (int i = 0; i < n; i++) {
    prev[i] = st->ctx_prev[(int)(((long)i * np) / n)];
  }
  prev[n] = 0;
}

int lpd_arith_decode(AacBitReader* r, LpdArithState* st, int lg, int* q) {
  if (!g_models_ready) {
    build_models();
  }
  const int n = lg / 2;
  uint8_t prev[LPD_MAX_TCX / 2 + 4], cur[LPD_MAX_TCX / 2 + 4];
  map_context(st, lg, prev);
  memset(cur, 0, sizeof(cur));
  ArithDec d;
  arith_start(&d, r);
  int stop = 0;
  for (int i = 0; i < n; i++) {
    int a = 0, b = 0;
    if (!stop) {
      int lev = 0, m;
      for (;;) {
        const int pk = arith_get_pk(prev, cur, i, n, lev > 7 ? 7 : lev);
        m = arith_decode_symbol(&d, g_cf_m[pk], 17);
        if (m != ARITH_ESCAPE) {
          break;
        }
        lev++;
        if (lev > 24) {
          return AAC_ERR_DECODE;
        }
      }
      if (lev > 0 && m == 0) {
        stop = 1; /* the remaining tuples are zero */
      } else {
        a = m & 3;
        b = m >> 2;
        for (int l = 0; l < lev; l++) {
          const int t = l < 2 ? l : 2;
          const int rr = arith_decode_symbol(&d, g_cf_r[t], 4);
          a = (a << 1) | (rr & 1);
          b = (b << 1) | ((rr >> 1) & 1);
        }
      }
    }
    q[2 * i] = a;
    q[2 * i + 1] = b;
    const int cls = a + b + 1;
    cur[i] = (uint8_t)(cls > 15 ? 15 : cls);
    if (r->overread) {
      return AAC_ERR_DECODE;
    }
  }
  arith_finish(&d);
  /* the signs follow as plain bits */
  for (int i = 0; i < lg; i++) {
    if (q[i] != 0 && aac_bitreader_read(r, 1)) {
      q[i] = -q[i];
    }
  }
  st->lg_prev = lg;
  memcpy(st->ctx_prev, cur, (size_t)n + 1);
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

/* ── syntax ─────────────────────────────────────────────────────── */

int lpd_read_tcx(AacBitReader* r, int lg, int first_tcx, int indep, LpdArithState* st,
                 LpdTcxData* d) {
  d->lg = lg;
  d->noise_factor = (int)aac_bitreader_read(r, 3);
  d->global_gain = (int)aac_bitreader_read(r, 7);
  d->arith_reset = 0;
  if (first_tcx) {
    d->arith_reset = indep ? 1 : (int)aac_bitreader_read(r, 1);
  }
  if (d->arith_reset) {
    lpd_arith_reset(st);
  }
  return lpd_arith_decode(r, st, lg, d->q);
}

/* ── windows (7.15.5) ───────────────────────────────────────────── */

/* The window of a TCX frame of lg lines spans 2 lg samples: zero up to
 * the left slope of `left_slope` samples centred on lg / 2, one until
 * the right slope of `right_slope` samples centred on 3 lg / 2, zero
 * after.  The slopes are sine halves, so that neighbouring frames (and
 * an FD frame with the same slope) are power complementary. */
void lpd_tcx_window(float* w, int lg, int left_slope, int right_slope) {
  const int n = 2 * lg;
  const int ls = lg / 2 - left_slope / 2;
  const int rs = 3 * lg / 2 - right_slope / 2;
  for (int i = 0; i < n; i++) {
    float v;
    if (i < ls) {
      v = 0.0f;
    } else if (i < ls + left_slope) {
      v = sinf((float)M_PI * ((float)(i - ls) + 0.5f) / (2.0f * (float)left_slope));
    } else if (i < rs) {
      v = 1.0f;
    } else if (i < rs + right_slope) {
      v = cosf((float)M_PI * ((float)(i - rs) + 0.5f) / (2.0f * (float)right_slope));
    } else {
      v = 0.0f;
    }
    w[i] = v;
  }
}

/* ── spectral shaping (7.15.3) ──────────────────────────────────── */

#define FDNS_NPTS 64

/* 1 / |A(e^jw)| of the weighted filter at FDNS_NPTS frequencies, the
 * odd DFT bins w_k = pi (k + 1/2) / FDNS_NPTS. */
static void lpc_envelope(const float* a, float* gains) {
  float ap[LPD_M + 1];
  lpd_weight_a(a, LPD_GAMMA1, ap);
  for (int k = 0; k < FDNS_NPTS; k++) {
    const float w = (float)M_PI * ((float)k + 0.5f) / (float)FDNS_NPTS;
    float re = 0.0f, im = 0.0f;
    for (int i = 0; i <= LPD_M; i++) {
      re += ap[i] * cosf(w * (float)i);
      im -= ap[i] * sinf(w * (float)i);
    }
    const float mag = sqrtf(re * re + im * im);
    gains[k] = 1.0f / (mag > 1e-6f ? mag : 1e-6f);
  }
}

/* ── decoding of one TCX frame ──────────────────────────────────── */

/* Reconstructs 2 lg windowed samples into `out`, to be overlap-added
 * at the frame start minus lg / 2. */
void lpd_tcx_decode(AacUsacLpdChannel* c, const LpdTcxData* d, const float* a_start,
                    const float* a_end, int left_slope, float* out) {
  const int lg = d->lg;
  float x[LPD_MAX_TCX];
  for (int i = 0; i < lg; i++) {
    x[i] = (float)d->q[i];
  }

  /* noise filling (7.15.2): runs of eight zero lines above lg / 6 are
   * filled with +-nf, nf = (8 - noise_factor) / 16 */
  const float nf = (float)(8 - d->noise_factor) / 16.0f;
  for (int i = lg / 6; i + 8 <= lg; i += 8) {
    int zero = 1;
    for (int k = 0; k < 8; k++) {
      zero &= d->q[i + k] == 0;
    }
    if (!zero) {
      continue;
    }
    for (int k = 0; k < 8; k++) {
      c->noise_seed = c->noise_seed * 1664525u + 1013904223u;
      x[i + k] = (c->noise_seed & 0x80000000u) ? nf : -nf;
    }
  }

  /* adaptive low frequency de-emphasis (7.15.4) over the first quarter:
   * blocks of eight below the strongest block are attenuated by the
   * square root of their energy ratio, never below 0.1, monotonically */
  {
    const int lim = lg / 4;
    float emax = 0.01f;
    for (int i = 0; i + 8 <= lim; i += 8) {
      float e = 0.01f;
      for (int k = 0; k < 8; k++) {
        e += x[i + k] * x[i + k];
      }
      if (e > emax) {
        emax = e;
      }
    }
    float fac = 10.0f;
    for (int i = 0; i + 8 <= lim; i += 8) {
      float e = 0.01f;
      for (int k = 0; k < 8; k++) {
        e += x[i + k] * x[i + k];
      }
      float t = sqrtf(e / emax);
      if (t < fac) {
        fac = t;
      }
      if (fac < 0.1f) {
        fac = 0.1f;
      }
      for (int k = 0; k < 8; k++) {
        x[i + k] *= fac;
      }
    }
  }

  /* LPC shaping: the envelopes of the weighted filters at the frame
   * start and end, combined geometrically, per band of lg / 64 lines */
  float g0[FDNS_NPTS], g1[FDNS_NPTS];
  lpc_envelope(a_start, g0);
  lpc_envelope(a_end, g1);
  const int band = lg / FDNS_NPTS;
  const float gain = powf(10.0f, (float)d->global_gain / 28.0f) / sqrtf((float)lg);
  c->tcx_gain_last = gain;
  for (int k = 0; k < FDNS_NPTS; k++) {
    const float g = sqrtf(g0[k] * g1[k]) * gain;
    for (int i = 0; i < band; i++) {
      x[k * band + i] *= g;
    }
  }

  /* inverse MDCT and window */
  int mi = 0;
  while (mi < 2 && c->mdct[mi].n != 2 * lg) {
    mi++;
  }
  float t[2 * LPD_MAX_TCX];
  aac_imdct(&c->mdct[mi], x, t);
  float w[2 * LPD_MAX_TCX];
  lpd_tcx_window(w, lg, left_slope, 2 * c->lfac);
  for (int i = 0; i < 2 * lg; i++) {
    out[i] = t[i] * w[i];
  }
}
