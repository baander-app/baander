/* MPEG Surround 212: the parameter syntax against a matching encoder,
 * the mixing matrices, the hybrid filterbank, the decorrelator, the
 * parameter interpolation and the upmix as a whole.
 *
 * The bitstream round trips prove that the reader and the encoder of this
 * file agree on the syntax and on the codebooks built by the decoder;
 * they do not prove the standard's codebooks, which were not available
 * when the decoder was written.  The oracle comparison against the FDK
 * decoder on real streams needs the core and SBR decoders and runs at
 * integration. */
#include <cmath>
#include <cstdio>
#include <cstring>
#include <vector>

#include "bitstream.h"
#include "mps212.h"
#include "qmf.h"
#include "test_util.h"

namespace {

const int kBands20 = 20;

AacUsacMps212Config base_config(void) {
  AacUsacMps212Config c;
  memset(&c, 0, sizeof(c));
  c.freq_res = 2; /* 20 bands */
  c.fixed_gain_dmx = 3;
  c.temp_shape_config = 3;
  c.decorr_config = 0;
  c.high_rate_mode = 1;
  c.phase_coding = 1;
  return c;
}

/* ── the matching encoder ───────────────────────────────────────── */

struct SetCoding {
  int mode; /* bsDataMode */
  int pcm;  /* with mode 3 */
  int coarse;
  int stride_idx;
  int scheme;    /* 0 one-dimensional, 1 two-dimensional */
  int diff_time; /* difference to the reference set when one exists */
  int pair;      /* this set and the next are read together */
};

struct Params {
  int num_sets;
  int framing_type;
  int slot[2];
  int indep;
  int cld[2][AAC_MPS212_MAX_PARAM_BANDS];
  int icc[2][AAC_MPS212_MAX_PARAM_BANDS];
  int ipd[2][AAC_MPS212_MAX_PARAM_BANDS];
  int phase_mode, opd_smoothing;
  SetCoding coding[3][2]; /* per type and set */
  /* low rate mode */
  int smooth_mode[2], smooth_time[2], smooth_stride_idx[2];
  int smooth_band[2][AAC_MPS212_MAX_PARAM_BANDS];
  /* temporal shaping */
  int temp_shape_enable;
  int env_shape_channel[2];
  int env_shape[2][64];
  int tsd_enable;
  int tsd_transient[64];
  int tsd_phase[64];
};

const int kStrides[4] = {1, 2, 5, 28};

void put_code(AacBitWriter* w, const AacMps212State* s, int cb, int symbol) {
  uint32_t code;
  int len;
  if (aac_mps212_huff_code(s, cb, symbol, &code, &len) != 0) {
    printf("FAIL: no code for symbol %d in codebook %d\n", symbol, cb);
    g_failures++;
    return;
  }
  aac_bitwriter_write(w, code, len);
}

int type_lo(int type, int coarse) {
  if (type == 0) return coarse ? -7 : -15;
  return 0;
}

int type_bits(int type, int coarse) {
  if (type == 0) return coarse ? 4 : 5;
  if (type == 1) return coarse ? 2 : 3;
  return coarse ? 3 : 4;
}

int wrap_ipd(int v, int coarse) {
  const int n = coarse ? 8 : 16;
  v %= n;
  return v < 0 ? v + n : v;
}

int to_coarse(int type, int v) {
  if (type == 2) return wrap_ipd((v + 1) / 2, 1);
  return v >= 0 ? (v + 1) / 2 : -((-v + 1) / 2);
}

int to_fine(int type, int v, int coarse) {
  if (!coarse) return v;
  return type == 2 ? wrap_ipd(2 * v, 0) : 2 * v;
}

/* The difference of two values of a type (IPD wraps). */
int diff_of(int type, int a, int b, int coarse) {
  int d = a - b;
  if (type == 2) {
    const int n = coarse ? 8 : 16;
    d %= n;
    if (d < 0) d += n;
    if (d >= n / 2) d -= n;
  }
  return d;
}

const int kLavTable[3][4] = {{3, 5, 7, 9}, {1, 3, 5, 7}, {1, 3, 5, 7}};

void write_first(AacBitWriter* w, const AacMps212State* s, int type, int v, int coarse) {
  int nsym, offset, lav;
  aac_mps212_codebook_info(s, AAC_MPS212_CB_CLD_FIRST + type, &nsym, &offset, &lav);
  put_code(w, s, AAC_MPS212_CB_CLD_FIRST + type, to_fine(type, v, coarse) - offset);
}

void write_diff(AacBitWriter* w, const AacMps212State* s, int type, int d) {
  int nsym, offset, lav;
  aac_mps212_codebook_info(s, AAC_MPS212_CB_CLD_1D + type, &nsym, &offset, &lav);
  put_code(w, s, AAC_MPS212_CB_CLD_1D + type, d - offset);
}

/* One set of data-band values, differential in frequency (ref NULL) or
 * to the reference. */
void write_set(AacBitWriter* w, const AacMps212State* s, int type, int coarse, const int* v,
               const int* ref, int n, int scheme) {
  int b = 0;
  if (ref == NULL) {
    write_first(w, s, type, v[0], coarse);
    b = 1;
  }
  if (scheme == 0) {
    for (; b < n; b++) {
      write_diff(w, s, type, diff_of(type, v[b], ref ? ref[b] : v[b - 1], coarse));
    }
    return;
  }
  if (b < n - 1) {
    /* the LAV class: the smallest holding every pair */
    int maxabs = 0;
    for (int k = b; k + 1 < n; k += 2) {
      const int dx = diff_of(type, v[k], ref ? ref[k] : v[k - 1], coarse);
      const int dy = diff_of(type, v[k + 1], ref ? ref[k + 1] : v[k], coarse);
      if (abs(dx) > maxabs) maxabs = abs(dx);
      if (abs(dy) > maxabs) maxabs = abs(dy);
    }
    int lav_idx = 0;
    while (lav_idx < 3 && kLavTable[type][lav_idx] < maxabs) {
      lav_idx++;
    }
    const int lav = kLavTable[type][lav_idx];
    CHECK(maxabs <= lav, "pair difference %d beyond the largest class", maxabs);
    put_code(w, s, AAC_MPS212_CB_LAV, lav_idx);
    for (; b + 1 < n; b += 2) {
      const int dx = diff_of(type, v[b], ref ? ref[b] : v[b - 1], coarse);
      const int dy = diff_of(type, v[b + 1], ref ? ref[b + 1] : v[b], coarse);
      put_code(w, s, AAC_MPS212_CB_CLD_2D + 4 * type + lav_idx,
               (dx + lav) * (2 * lav + 1) + (dy + lav));
    }
  }
  if (b < n) {
    write_diff(w, s, type, diff_of(type, v[b], ref ? ref[b] : v[b - 1], coarse));
  }
}

/* EcData() of one type.  `prev` is the previous frame's last set (fine
 * scale) or NULL. */
void write_ecdata(AacBitWriter* w, const AacMps212State* s, const Params* p, int type, int nbands,
                  const int* prev) {
  const int(*vals)[AAC_MPS212_MAX_PARAM_BANDS] = type == 0 ? p->cld : (type == 1 ? p->icc : p->ipd);
  for (int ps = 0; ps < p->num_sets; ps++) {
    aac_bitwriter_write(w, (uint32_t)p->coding[type][ps].mode, 2);
  }
  int determined[2] = {0, 0};
  for (int ps = 0; ps < p->num_sets; ps++) {
    const SetCoding* c = &p->coding[type][ps];
    if (c->mode != 3) {
      if (c->mode != 2) determined[ps] = 1;
      continue;
    }
    aac_bitwriter_write(w, (uint32_t)c->pair, 1);
    aac_bitwriter_write(w, (uint32_t)c->coarse, 1);
    aac_bitwriter_write(w, (uint32_t)c->stride_idx, 2);
    const int stride = kStrides[c->stride_idx];
    const int data_bands = (nbands + stride - 1) / stride;
    const int nsets = c->pair ? 2 : 1;
    int data[2][AAC_MPS212_MAX_PARAM_BANDS];
    for (int j = 0; j < nsets; j++) {
      for (int b = 0; b < data_bands; b++) {
        const int v = vals[ps + j][b * stride];
        data[j][b] = c->coarse ? to_coarse(type, v) : v;
      }
    }
    aac_bitwriter_write(w, (uint32_t)c->pcm, 1);
    if (c->pcm) {
      for (int j = 0; j < nsets; j++) {
        for (int b = 0; b < data_bands; b++) {
          aac_bitwriter_write(w, (uint32_t)(data[j][b] - type_lo(type, c->coarse)),
                              type_bits(type, c->coarse));
        }
      }
    } else {
      const int* ref_fine = NULL;
      for (int q = ps - 1; q >= 0; q--) {
        if (determined[q]) {
          ref_fine = vals[q];
          break;
        }
      }
      if (ref_fine == NULL && !p->indep) {
        ref_fine = prev;
      }
      int ref[AAC_MPS212_MAX_PARAM_BANDS];
      if (ref_fine) {
        for (int b = 0; b < data_bands; b++) {
          const int v = ref_fine[b * stride];
          ref[b] = c->coarse ? to_coarse(type, v) : v;
        }
      }
      const int diff0 = ref_fine ? c->diff_time : 0;
      if (ref_fine) {
        aac_bitwriter_write(w, (uint32_t)diff0, 1);
      }
      const int diff1 = c->pair ? p->coding[type][ps + 1].diff_time : 0;
      if (c->pair) {
        aac_bitwriter_write(w, (uint32_t)diff1, 1);
      }
      if (data_bands > 1) {
        aac_bitwriter_write(w, (uint32_t)c->scheme, 1);
      }
      const int scheme = data_bands > 1 ? c->scheme : 0;
      write_set(w, s, type, c->coarse, data[0], diff0 ? ref : NULL, data_bands, scheme);
      if (c->pair) {
        write_set(w, s, type, c->coarse, data[1], diff1 ? data[0] : NULL, data_bands, scheme);
      }
    }
    for (int j = 0; j < nsets; j++) {
      determined[ps + j] = 1;
    }
    ps += nsets - 1;
  }
}

int ceil_log2(int n) {
  int b = 0;
  while ((1 << b) < n) b++;
  return b;
}

double binomial(int n, int k) {
  if (k < 0 || k > n) return 0.0;
  double c = 1.0;
  for (int i = 1; i <= k; i++) c = c * (n - k + i) / i;
  return c;
}

/* Mps212Data() for the configuration and the parameters. */
std::vector<uint8_t> write_mps212(const AacMps212State* s, const AacUsacMps212Config* cfg,
                                  const Params* p, int slots, int nbands, const int* prev_cld,
                                  const int* prev_icc, const int* prev_ipd) {
  std::vector<uint8_t> buf(512, 0);
  AacBitWriter w;
  aac_bitwriter_init(&w, buf.data(), (int)buf.size());
  if (cfg->high_rate_mode) {
    aac_bitwriter_write(&w, (uint32_t)p->framing_type, 1);
    aac_bitwriter_write(&w, (uint32_t)(p->num_sets - 1), 1);
    if (p->framing_type) {
      for (int ps = 0; ps < p->num_sets; ps++) {
        aac_bitwriter_write(&w, (uint32_t)p->slot[ps], ceil_log2(slots));
      }
    }
  }
  if (!p->indep) {
    aac_bitwriter_write(&w, 0, 1); /* bsIndependencyFlag */
  }
  write_ecdata(&w, s, p, 0, nbands, prev_cld);
  write_ecdata(&w, s, p, 1, nbands, prev_icc);
  if (cfg->phase_coding) {
    aac_bitwriter_write(&w, (uint32_t)p->phase_mode, 1);
    if (p->phase_mode) {
      aac_bitwriter_write(&w, (uint32_t)p->opd_smoothing, 1);
      write_ecdata(&w, s, p, 2, nbands, prev_ipd);
    }
  }
  if (!cfg->high_rate_mode) {
    for (int ps = 0; ps < p->num_sets; ps++) {
      aac_bitwriter_write(&w, (uint32_t)p->smooth_mode[ps], 2);
      if (p->smooth_mode[ps] >= 2) {
        aac_bitwriter_write(&w, (uint32_t)p->smooth_time[ps], 2);
      }
      if (p->smooth_mode[ps] == 3) {
        aac_bitwriter_write(&w, (uint32_t)p->smooth_stride_idx[ps], 2);
        const int stride = kStrides[p->smooth_stride_idx[ps]];
        for (int b = 0; b < (nbands + stride - 1) / stride; b++) {
          aac_bitwriter_write(&w, (uint32_t)p->smooth_band[ps][b * stride], 1);
        }
      }
    }
  }
  switch (cfg->temp_shape_config) {
    case 1:
      aac_bitwriter_write(&w, (uint32_t)p->temp_shape_enable, 1);
      if (p->temp_shape_enable) {
        for (int ch = 0; ch < 2; ch++) {
          aac_bitwriter_write(&w, (uint32_t)p->env_shape_channel[ch], 1);
        }
      }
      break;
    case 2: {
      aac_bitwriter_write(&w, (uint32_t)p->temp_shape_enable, 1);
      if (!p->temp_shape_enable) break;
      for (int ch = 0; ch < 2; ch++) {
        aac_bitwriter_write(&w, (uint32_t)p->env_shape_channel[ch], 1);
      }
      const int levels = cfg->env_quant_mode ? 5 : 8;
      int nsym, offset, lav;
      aac_mps212_codebook_info(s, AAC_MPS212_CB_ENV, &nsym, &offset, &lav);
      for (int ch = 0; ch < 2; ch++) {
        if (!p->env_shape_channel[ch]) continue;
        int prev = levels / 2;
        for (int n = 0; n < slots; n++) {
          put_code(&w, s, AAC_MPS212_CB_ENV, p->env_shape[ch][n] - prev - offset);
          prev = p->env_shape[ch][n];
        }
      }
      break;
    }
    case 3: {
      aac_bitwriter_write(&w, (uint32_t)p->tsd_enable, 1);
      if (!p->tsd_enable) break;
      int num_tr = 0;
      for (int n = 0; n < slots; n++) num_tr += p->tsd_transient[n];
      aac_bitwriter_write(&w, (uint32_t)(num_tr - 1), ceil_log2(slots));
      /* lexicographic rank of the slot set */
      double index = 0.0;
      int remaining = num_tr;
      for (int n = 0; n < slots && remaining > 0; n++) {
        if (p->tsd_transient[n]) {
          remaining--;
        } else {
          index += binomial(slots - n - 1, remaining - 1);
        }
      }
      const double combos = binomial(slots, num_tr);
      int bits = 0;
      while (pow(2.0, bits) < combos) bits++;
      aac_bitwriter_write(&w, (uint32_t)index, bits);
      for (int n = 0; n < slots; n++) {
        if (p->tsd_transient[n]) {
          aac_bitwriter_write(&w, (uint32_t)p->tsd_phase[n], 3);
        }
      }
      break;
    }
    default:
      break;
  }
  /* padding, as the frame around it would leave */
  aac_bitwriter_write(&w, 0, 7);
  const int bytes = w.byte_pos + (w.bit_pos ? 1 : 0);
  buf.resize((size_t)bytes);
  CHECK(!aac_bitwriter_overflow(&w), "writer overflow");
  return buf;
}

Params default_params(int nbands) {
  Params p;
  memset(&p, 0, sizeof(p));
  p.num_sets = 1;
  p.slot[0] = 31;
  for (int b = 0; b < nbands; b++) {
    p.cld[0][b] = (b % 7) - 3;
    p.icc[0][b] = b % 8;
    p.ipd[0][b] = (3 * b) % 16;
  }
  for (int t = 0; t < 3; t++) {
    for (int ps = 0; ps < 2; ps++) {
      p.coding[t][ps].mode = 3;
    }
  }
  p.phase_mode = 1;
  return p;
}

void check_params(const AacMps212State* s, const Params* p, int nbands, int check_ipd,
                  const char* what) {
  CHECK(aac_mps212_num_param_sets(s) == p->num_sets, "%s: %d sets", what,
        aac_mps212_num_param_sets(s));
  for (int ps = 0; ps < p->num_sets; ps++) {
    CHECK(aac_mps212_param_slot(s, ps) == p->slot[ps], "%s: set %d at slot %d", what, ps,
          aac_mps212_param_slot(s, ps));
    for (int b = 0; b < nbands; b++) {
      CHECK(aac_mps212_param(s, 0, ps, b) == p->cld[ps][b], "%s: CLD set %d band %d: %d vs %d",
            what, ps, b, aac_mps212_param(s, 0, ps, b), p->cld[ps][b]);
      CHECK(aac_mps212_param(s, 1, ps, b) == p->icc[ps][b], "%s: ICC set %d band %d: %d vs %d",
            what, ps, b, aac_mps212_param(s, 1, ps, b), p->icc[ps][b]);
      if (check_ipd) {
        CHECK(aac_mps212_param(s, 2, ps, b) == p->ipd[ps][b], "%s: IPD set %d band %d: %d vs %d",
              what, ps, b, aac_mps212_param(s, 2, ps, b), p->ipd[ps][b]);
      }
    }
  }
}

/* ── tests ──────────────────────────────────────────────────────── */

void test_tables(void) {
  CHECK(aac_mps212_freq_res_bands[2] == 20 && aac_mps212_freq_res_bands[4] == 10 &&
            aac_mps212_freq_res_bands[1] == 28,
        "band counts per bsFreqRes");
  const int counts[7] = {28, 20, 14, 10, 7, 5, 4};
  for (int i = 0; i < 7; i++) {
    const uint8_t* k = aac_mps212_kernel(counts[i]);
    int used[AAC_MPS212_MAX_PARAM_BANDS] = {0};
    int max = 0;
    for (int h = 0; h < AAC_MPS212_HYBRID_BANDS; h++) {
      CHECK(k[h] < counts[i], "%d bands: hybrid %d maps to %d", counts[i], h, k[h]);
      used[k[h]] = 1;
      if (k[h] > max) max = k[h];
      /* above the hybrid bands of QMF band 0 the mapping does not decrease */
      if (h > 6) {
        CHECK(k[h] >= k[h - 1], "%d bands: hybrid %d maps below its neighbour", counts[i], h);
      }
    }
    CHECK(max == counts[i] - 1, "%d bands: highest parameter band %d", counts[i], max);
    for (int pb = 0; pb < counts[i]; pb++) {
      CHECK(used[pb], "%d bands: parameter band %d unused", counts[i], pb);
    }
  }
  /* the measured partitions of the FDK decoder's output at 20 and 10
   * bands over QMF bands 3..63 */
  const uint8_t* k20 = aac_mps212_kernel(20);
  const int edges20[] = {3, 4, 5, 6, 7, 8, 9, 11, 14, 18, 23, 35, 64};
  for (int i = 0; i + 1 < (int)(sizeof(edges20) / sizeof(int)); i++) {
    for (int q = edges20[i]; q < edges20[i + 1]; q++) {
      CHECK(k20[q + 7] == 8 + i, "20 bands: QMF %d in parameter band %d", q, k20[q + 7]);
    }
  }
  const uint8_t* k10 = aac_mps212_kernel(10);
  const int edges10[] = {3, 5, 7, 9, 14, 64};
  for (int i = 0; i + 1 < (int)(sizeof(edges10) / sizeof(int)); i++) {
    for (int q = edges10[i]; q < edges10[i + 1]; q++) {
      CHECK(k10[q + 7] == 5 + i, "10 bands: QMF %d in parameter band %d", q, k10[q + 7]);
    }
  }
  CHECK(k10[6] == 3 && k10[7] == 3 && k10[8] == 4 && k10[9] == 4, "10 bands: QMF 1 and 2");
  CHECK(k20[6] == 4 && k20[7] == 5 && k20[8] == 6 && k20[9] == 7, "20 bands: QMF 1 and 2");
  CHECK(fabsf(aac_mps212_cld_db[15]) < 1e-6f && aac_mps212_cld_db[20] == 10.0f &&
            aac_mps212_cld_db[0] == -150.0f,
        "CLD table");
  CHECK(aac_mps212_icc_value[0] == 1.0f && aac_mps212_icc_value[5] == 0.0f, "ICC table");
}

void test_codebooks(void) {
  AacUsacMps212Config cfg = base_config();
  AacMps212State* s = aac_mps212_create(&cfg, 1, 1024, 2048, 48000);
  CHECK(s != NULL, "create");
  if (!s) return;
  for (int cb = 0; cb < AAC_MPS212_CB_COUNT; cb++) {
    int nsym, offset, lav;
    CHECK(aac_mps212_codebook_info(s, cb, &nsym, &offset, &lav) == 0, "codebook %d info", cb);
    /* Kraft sum of a complete prefix code, and every symbol decodable */
    double kraft = 0.0;
    for (int sym = 0; sym < nsym; sym++) {
      uint32_t code;
      int len;
      CHECK(aac_mps212_huff_code(s, cb, sym, &code, &len) == 0, "codebook %d symbol %d", cb, sym);
      CHECK(len >= 1 && len <= 24, "codebook %d symbol %d length %d", cb, sym, len);
      kraft += pow(2.0, -len);
    }
    CHECK(kraft <= 1.0 + 1e-9, "codebook %d Kraft sum %f", cb, kraft);
  }
  /* round trip through the reader: one frame with every coding option in
   * turn */
  const int nbands = kBands20;
  Params p = default_params(nbands);
  p.indep = 1;
  struct Option {
    int pcm, coarse, stride_idx, scheme;
  } options[] = {{1, 0, 0, 0}, {1, 1, 1, 0}, {0, 0, 0, 0}, {0, 0, 0, 1},
                 {0, 1, 0, 1}, {0, 0, 2, 1}, {0, 1, 3, 0}, {0, 0, 1, 0}};
  for (const Option& o : options) {
    for (int t = 0; t < 3; t++) {
      p.coding[t][0].pcm = o.pcm;
      p.coding[t][0].coarse = o.coarse;
      p.coding[t][0].stride_idx = o.stride_idx;
      p.coding[t][0].scheme = o.scheme;
    }
    /* values representable under the option: coarse halves, stride copies */
    const int stride = kStrides[o.stride_idx];
    for (int b = 0; b < nbands; b++) {
      const int src = (b / stride) * stride;
      /* differences within the two-dimensional classes (a triangle for
       * the CLD, wrapping steps for the ICC and the IPD) */
      const int tri = (src % 6) < 3 ? (src % 6) : 5 - (src % 6);
      const int cld = tri * 4 - 6;
      p.cld[0][b] = to_fine(0, o.coarse ? to_coarse(0, cld) : cld, o.coarse);
      p.icc[0][b] = to_fine(1, o.coarse ? to_coarse(1, src % 7) : src % 7, o.coarse);
      p.ipd[0][b] = to_fine(2, o.coarse ? to_coarse(2, (3 * src) % 16) : (3 * src) % 16, o.coarse);
    }
    aac_mps212_reset(s);
    std::vector<uint8_t> bits = write_mps212(s, &cfg, &p, 32, nbands, NULL, NULL, NULL);
    AacBitReader r;
    aac_bitreader_init(&r, bits.data(), (int)bits.size());
    CHECK(aac_mps212_read(s, &r, 1) == AAC_OK, "read (pcm %d coarse %d stride %d scheme %d)", o.pcm,
          o.coarse, o.stride_idx, o.scheme);
    char what[64];
    snprintf(what, sizeof(what), "pcm %d coarse %d stride %d scheme %d", o.pcm, o.coarse,
             o.stride_idx, o.scheme);
    check_params(s, &p, nbands, 1, what);
    CHECK(aac_mps212_phase_mode(s) == 1, "phase mode");
  }
  aac_mps212_destroy(s);
}

void test_time_differences(void) {
  AacUsacMps212Config cfg = base_config();
  AacMps212State* s = aac_mps212_create(&cfg, 1, 1024, 2048, 48000);
  CHECK(s != NULL, "create");
  if (!s) return;
  const int nbands = kBands20;
  /* frame 1: independent, one set; frame 2: two sets, the first a time
   * difference to frame 1, the second a pair member differential to the
   * first; frame 3: keep and interpolate modes */
  Params p1 = default_params(nbands);
  p1.indep = 1;
  std::vector<uint8_t> b1 = write_mps212(s, &cfg, &p1, 32, nbands, NULL, NULL, NULL);
  AacBitReader r;
  aac_bitreader_init(&r, b1.data(), (int)b1.size());
  CHECK(aac_mps212_read(s, &r, 1) == AAC_OK, "frame 1");
  check_params(s, &p1, nbands, 1, "frame 1");

  Params p2 = default_params(nbands);
  p2.num_sets = 2;
  p2.framing_type = 1;
  p2.slot[0] = 13;
  p2.slot[1] = 31;
  for (int b = 0; b < nbands; b++) {
    p2.cld[0][b] = p1.cld[0][b] + (b % 3) - 1;
    p2.cld[1][b] = p2.cld[0][b] + ((b + 1) % 3) - 1;
    p2.icc[0][b] = (p1.icc[0][b] + 1) % 8;
    p2.icc[1][b] = (p2.icc[0][b] + 7) % 8;
    p2.ipd[0][b] = (p1.ipd[0][b] + 15) % 16;
    p2.ipd[1][b] = (p2.ipd[0][b] + 2) % 16;
  }
  for (int t = 0; t < 3; t++) {
    p2.coding[t][0].pair = 1;
    p2.coding[t][0].diff_time = 1;
    p2.coding[t][0].scheme = t == 1 ? 1 : 0;
    p2.coding[t][1].diff_time = 1;
  }
  std::vector<uint8_t> b2 = write_mps212(s, &cfg, &p2, 32, nbands, p1.cld[0], p1.icc[0], p1.ipd[0]);
  aac_bitreader_init(&r, b2.data(), (int)b2.size());
  CHECK(aac_mps212_read(s, &r, 0) == AAC_OK, "frame 2");
  check_params(s, &p2, nbands, 1, "frame 2");

  Params p3 = default_params(nbands);
  p3.num_sets = 2;
  p3.framing_type = 0;
  p3.slot[0] = 15;
  p3.slot[1] = 31;
  for (int t = 0; t < 3; t++) {
    p3.coding[t][0].mode = 2; /* interpolate */
    p3.coding[t][1].mode = 3;
    p3.coding[t][1].pcm = 1;
  }
  p3.coding[1][0].mode = 1; /* ICC: keep */
  for (int b = 0; b < nbands; b++) {
    p3.cld[1][b] = p2.cld[1][b] + 4 * ((b % 2) ? 1 : -1);
    p3.icc[1][b] = (b * 5) % 8;
    p3.ipd[1][b] = (p2.ipd[1][b] + 4) % 16;
    /* what the decoder derives: midway for the interpolated CLD and IPD
     * (set 0 at slot 15, between slot -1 and set 1 at slot 31), the
     * previous set for the kept ICC */
    p3.cld[0][b] = (int)lrintf((float)p2.cld[1][b] + 0.5f * (float)(p3.cld[1][b] - p2.cld[1][b]));
    p3.icc[0][b] = p2.icc[1][b];
    p3.ipd[0][b] = (p2.ipd[1][b] + 2) % 16;
  }
  std::vector<uint8_t> b3 = write_mps212(s, &cfg, &p3, 32, nbands, p2.cld[1], p2.icc[1], p2.ipd[1]);
  aac_bitreader_init(&r, b3.data(), (int)b3.size());
  CHECK(aac_mps212_read(s, &r, 0) == AAC_OK, "frame 3");
  check_params(s, &p3, nbands, 1, "frame 3");
  CHECK(aac_mps212_data_mode(s, 0, 0) == 2 && aac_mps212_data_mode(s, 1, 0) == 1, "data modes");

  /* a truncated frame keeps the previous parameters */
  std::vector<uint8_t> b4 = write_mps212(s, &cfg, &p2, 32, nbands, p3.cld[1], p3.icc[1], p3.ipd[1]);
  b4.resize(b4.size() / 2);
  aac_bitreader_init(&r, b4.data(), (int)b4.size());
  CHECK(aac_mps212_read(s, &r, 0) == AAC_ERR_DECODE, "truncated frame refused");
  CHECK(aac_mps212_num_param_sets(s) == 1, "concealed: one set");
  for (int b = 0; b < nbands; b++) {
    CHECK(aac_mps212_param(s, 0, 0, b) == p3.cld[1][b], "concealed CLD band %d", b);
  }
  aac_mps212_destroy(s);
}

void test_low_rate_and_shaping(void) {
  /* low rate mode: no framing, smoothing data; TSD, STP and GES payloads */
  AacUsacMps212Config cfg = base_config();
  cfg.high_rate_mode = 0;
  cfg.freq_res = 4; /* 10 bands */
  cfg.temp_shape_config = 3;
  AacMps212State* s = aac_mps212_create(&cfg, 1, 1024, 4096, 48000);
  CHECK(s != NULL, "create 4:1");
  if (!s) return;
  const int nbands = 10;
  Params p = default_params(nbands);
  p.indep = 1;
  p.slot[0] = 63;
  p.smooth_mode[0] = 3;
  p.smooth_time[0] = 2;
  p.smooth_stride_idx[0] = 1;
  for (int b = 0; b < nbands; b++) p.smooth_band[0][b] = ((b / 2) % 2);
  p.tsd_enable = 1;
  p.tsd_transient[5] = p.tsd_transient[17] = p.tsd_transient[63] = 1;
  p.tsd_phase[5] = 3;
  p.tsd_phase[17] = 7;
  p.tsd_phase[63] = 1;
  std::vector<uint8_t> bits = write_mps212(s, &cfg, &p, 64, nbands, NULL, NULL, NULL);
  AacBitReader r;
  aac_bitreader_init(&r, bits.data(), (int)bits.size());
  CHECK(aac_mps212_read(s, &r, 1) == AAC_OK, "low rate frame");
  check_params(s, &p, nbands, 1, "low rate");
  CHECK(aac_mps212_smooth_mode(s, 0) == 3, "smoothing mode");
  CHECK(aac_mps212_temp_shape_enabled(s), "TSD enabled");
  for (int n = 0; n < 64; n++) {
    CHECK(aac_mps212_tsd_transient(s, n) == p.tsd_transient[n], "transient slot %d", n);
    if (p.tsd_transient[n]) {
      CHECK(aac_mps212_tsd_phase(s, n) == p.tsd_phase[n], "transient phase slot %d", n);
    }
  }
  aac_mps212_destroy(s);

  for (int config = 1; config <= 2; config++) {
    cfg = base_config();
    cfg.temp_shape_config = config;
    cfg.env_quant_mode = 0;
    s = aac_mps212_create(&cfg, 1, 1024, 2048, 48000);
    CHECK(s != NULL, "create");
    if (!s) continue;
    p = default_params(kBands20);
    p.indep = 1;
    p.temp_shape_enable = 1;
    p.env_shape_channel[0] = 0;
    p.env_shape_channel[1] = 1;
    for (int n = 0; n < 32; n++) p.env_shape[1][n] = (n * 3) % 8;
    bits = write_mps212(s, &cfg, &p, 32, kBands20, NULL, NULL, NULL);
    aac_bitreader_init(&r, bits.data(), (int)bits.size());
    CHECK(aac_mps212_read(s, &r, 1) == AAC_OK, "shaping config %d", config);
    check_params(s, &p, kBands20, 1, "shaping");
    CHECK(aac_mps212_temp_shape_enabled(s) && aac_mps212_env_shape_channel(s, 1) == 1 &&
              aac_mps212_env_shape_channel(s, 0) == 0,
          "shaping flags config %d", config);
    if (config == 2) {
      for (int n = 0; n < 32; n++) {
        CHECK(aac_mps212_env_shape(s, 1, n) == p.env_shape[1][n], "envelope slot %d", n);
      }
    }
    aac_mps212_destroy(s);
  }
}

void test_ott_matrix(void) {
  const float clds[] = {-45.0f, -13.0f, -4.0f, 0.0f, 2.0f, 10.0f, 25.0f};
  const float iccs[] = {1.0f, 0.937f, 0.60092f, 0.0f, -0.589f, -0.99f};
  for (float cld : clds) {
    for (float icc : iccs) {
      float h[2][2][2];
      aac_mps212_ott_matrix(cld, icc, 0.0f, 0, h);
      const double ratio = pow(10.0, cld / 10.0);
      const double pl = h[0][0][0] * h[0][0][0] + h[0][1][0] * h[0][1][0];
      const double pr = h[1][0][0] * h[1][0][0] + h[1][1][0] * h[1][1][0];
      /* with a wet signal of the dry signal's energy, uncorrelated: the
       * output energies split as the CLD says and sum to the input's */
      CHECK(fabs(pl + pr - 1.0) < 1e-5, "energy sum %f at CLD %f ICC %f", pl + pr, cld, icc);
      CHECK(fabs(10.0 * log10(pl / pr) - cld) < 1e-3, "CLD %f reproduced as %f", cld,
            10.0 * log10(pl / pr));
      const double corr = (h[0][0][0] * h[1][0][0] + h[0][1][0] * h[1][1][0]) / sqrt(pl * pr);
      CHECK(fabs(corr - icc) < 1e-4, "ICC %f reproduced as %f (CLD %f)", icc, corr, cld);
      (void)ratio;
    }
  }
  /* phase: the left leads the right by the IPD, and the two columns of a
   * row share the phase */
  for (int i = 0; i < 16; i++) {
    const float ipd = (float)(i * M_PI / 8.0);
    float h[2][2][2];
    aac_mps212_ott_matrix(-4.0f, 0.84118f, ipd, 1, h);
    const double pl = atan2(h[0][0][1], h[0][0][0]);
    const double pr = atan2(h[1][0][1], h[1][0][0]);
    double d = pl - pr;
    while (d > M_PI) d -= 2.0 * M_PI;
    while (d < -M_PI) d += 2.0 * M_PI;
    double want = ipd;
    while (want > M_PI) want -= 2.0 * M_PI;
    CHECK(fabs(d - want) < 1e-4 || fabs(fabs(d - want) - 2.0 * M_PI) < 1e-4, "IPD %d: %f vs %f", i,
          d, want);
    const double pw = atan2(h[0][1][1], h[0][1][0]);
    double dd = pl - pw;
    while (dd > M_PI) dd -= 2.0 * M_PI;
    while (dd < -M_PI) dd += 2.0 * M_PI;
    CHECK(fabs(dd) < 1e-4 || fabs(fabs(dd) - M_PI) < 1e-4, "row phase %d", i);
  }
}

/* The hybrid filterbank: a sine lands in the hybrid band of its
 * frequency, and the synthesis of the analysis is the input delayed by
 * six slots. */
void test_hybrid(void) {
  aac_qmf_tables_init();
  struct Probe {
    double freq;
    int band;
  } probes[] = {{47.0, 2},  {141.0, 3}, {234.0, 4},  {328.0, 5},   {470.0, 6},
                {650.0, 7}, {850.0, 8}, {1030.0, 9}, {1300.0, 10}, {5000.0, 20}};
  for (const Probe& pr : probes) {
    AacQmfAnalysis a;
    aac_qmf_analysis_init(&a, 64);
    std::vector<float> slots_x(13 * 64 * 2 * 40, 0.0f);
    float(*in)[64][2] = (float(*)[64][2])slots_x.data();
    const int nslots = 40;
    for (int n = 0; n < nslots; n++) {
      float x[64];
      for (int i = 0; i < 64; i++) {
        x[i] = 10000.0f * sinf((float)(2.0 * M_PI * pr.freq * (n * 64 + i) / 48000.0));
      }
      aac_qmf_analyze(&a, x, in[n]);
    }
    double energy[AAC_MPS212_HYBRID_BANDS] = {0};
    for (int n = 20; n + 13 <= nslots; n++) {
      float out[AAC_MPS212_HYBRID_BANDS][2];
      aac_mps212_hybrid_analysis(in + n, out);
      for (int k = 0; k < AAC_MPS212_HYBRID_BANDS; k++) {
        energy[k] += out[k][0] * out[k][0] + out[k][1] * out[k][1];
      }
    }
    int best = 0;
    double total = 0.0;
    for (int k = 0; k < AAC_MPS212_HYBRID_BANDS; k++) {
      total += energy[k];
      if (energy[k] > energy[best]) best = k;
    }
    CHECK(best == pr.band, "%g Hz lands in hybrid band %d, expected %d", pr.freq, best, pr.band);
    CHECK(energy[best] > 0.5 * total, "%g Hz: band %d holds %.0f%% of the energy", pr.freq, best,
          100.0 * energy[best] / total);
  }
  /* perfect reconstruction with six slots of delay */
  std::vector<float> noise(13 * 64 * 2 * 30);
  unsigned seed = 11u;
  for (float& v : noise) {
    seed = seed * 1664525u + 1013904223u;
    v = (float)(seed >> 8) / 8388608.0f - 1.0f;
  }
  float(*in)[64][2] = (float(*)[64][2])noise.data();
  double err = 0.0, ref = 0.0;
  for (int n = 0; n + 13 <= 30; n++) {
    float hyb[AAC_MPS212_HYBRID_BANDS][2];
    float out[64][2];
    aac_mps212_hybrid_analysis(in + n, hyb);
    aac_mps212_hybrid_synthesis(hyb, out);
    for (int b = 0; b < 64; b++) {
      for (int c = 0; c < 2; c++) {
        const double d = out[b][c] - in[n + 6][b][c];
        err += d * d;
        ref += in[n + 6][b][c] * in[n + 6][b][c];
      }
    }
  }
  CHECK(err < 1e-8 * ref, "hybrid reconstruction error %g relative", err / ref);
}

/* The decorrelator: unit gain on stationary noise, no correlation with
 * its input at lag zero, finite state. */
void test_decorrelator(void) {
  for (int config = 0; config < 3; config++) {
    AacUsacMps212Config cfg = base_config();
    cfg.decorr_config = config;
    AacMps212State* s = aac_mps212_create(&cfg, 1, 1024, 2048, 48000);
    CHECK(s != NULL, "create");
    if (!s) continue;
    unsigned seed = 99u + (unsigned)config;
    double e_in[AAC_MPS212_HYBRID_BANDS] = {0}, e_out[AAC_MPS212_HYBRID_BANDS] = {0};
    double xr[AAC_MPS212_HYBRID_BANDS] = {0}, xi[AAC_MPS212_HYBRID_BANDS] = {0};
    const int nslots = 4000;
    for (int n = 0; n < nslots; n++) {
      float in[AAC_MPS212_HYBRID_BANDS][2], out[AAC_MPS212_HYBRID_BANDS][2];
      for (int k = 0; k < AAC_MPS212_HYBRID_BANDS; k++) {
        for (int c = 0; c < 2; c++) {
          seed = seed * 1664525u + 1013904223u;
          in[k][c] = 1000.0f * ((float)(seed >> 8) / 8388608.0f - 1.0f);
        }
      }
      aac_mps212_decorrelate(s, in, out);
      if (n < 200) continue;
      for (int k = 0; k < AAC_MPS212_HYBRID_BANDS; k++) {
        e_in[k] += in[k][0] * in[k][0] + in[k][1] * in[k][1];
        e_out[k] += out[k][0] * out[k][0] + out[k][1] * out[k][1];
        xr[k] += in[k][0] * out[k][0] + in[k][1] * out[k][1];
        xi[k] += in[k][1] * out[k][0] - in[k][0] * out[k][1];
        CHECK(std::isfinite(out[k][0]) && std::isfinite(out[k][1]), "finite output");
      }
    }
    for (int k = 0; k < AAC_MPS212_HYBRID_BANDS; k++) {
      const double gain = 10.0 * log10(e_out[k] / e_in[k]);
      CHECK(fabs(gain) < 1.0, "config %d band %d: gain %.2f dB", config, k, gain);
      const double corr = sqrt(xr[k] * xr[k] + xi[k] * xi[k]) / sqrt(e_in[k] * e_out[k]);
      CHECK(corr < 0.1, "config %d band %d: correlation %.3f with the input", config, k, corr);
    }
    aac_mps212_destroy(s);
  }
}

/* Measures per parameter band (QMF bands 3..63) the level difference,
 * the correlation and the phase difference of two QMF frames. */
struct BandStats {
  double pl[AAC_MPS212_MAX_PARAM_BANDS], pr[AAC_MPS212_MAX_PARAM_BANDS];
  double cr[AAC_MPS212_MAX_PARAM_BANDS], ci[AAC_MPS212_MAX_PARAM_BANDS];
};

void accumulate(BandStats* st, const AacUsacQmfFrame* l, const AacUsacQmfFrame* r,
                const uint8_t* kernel, int first_slot) {
  for (int n = first_slot; n < l->slots; n++) {
    for (int b = 3; b < 64; b++) {
      const int pb = kernel[b + 7];
      st->pl[pb] += l->x[n][b][0] * l->x[n][b][0] + l->x[n][b][1] * l->x[n][b][1];
      st->pr[pb] += r->x[n][b][0] * r->x[n][b][0] + r->x[n][b][1] * r->x[n][b][1];
      st->cr[pb] += l->x[n][b][0] * r->x[n][b][0] + l->x[n][b][1] * r->x[n][b][1];
      st->ci[pb] += l->x[n][b][1] * r->x[n][b][0] - l->x[n][b][0] * r->x[n][b][1];
    }
  }
}

void fill_noise(AacUsacQmfFrame* f, int slots, unsigned* seed, float scale, int bands) {
  f->slots = slots;
  memset(f->x, 0, sizeof(f->x));
  for (int n = 0; n < slots; n++) {
    for (int b = 0; b < bands; b++) {
      for (int c = 0; c < 2; c++) {
        *seed = *seed * 1664525u + 1013904223u;
        f->x[n][b][c] = scale * ((float)(*seed >> 8) / 8388608.0f - 1.0f);
      }
    }
  }
}

/* Feeds CLD, ICC and IPD per band through the bitstream and the upmix and
 * measures them back on the output: the self-consistency check of the
 * whole chain.  The values of the first case are the level differences
 * the FDK decoder produced for the kit's 24 kbit/s probe, per parameter
 * band. */
void test_upmix_consistency(void) {
  struct Case {
    int cld[kBands20];
    int icc[kBands20];
    int ipd[kBands20];
    int phase;
  } cases[] = {
      {{-8, -7, -5, -3, -1, 1, 3, 5, -3, -1, 1, 3, 5, 6, 0, -5, 2, 0, 0, 0},
       {0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 3, 1, 1, 4, 2, 4},
       {0},
       0},
      {{0, 0, 0, 0, 0, 0, 0, 0, 2, -2, 4, -4, 6, -6, 8, -8, 10, -10, 12, -12},
       {1, 2, 3, 4, 5, 6, 7, 0, 1, 2, 3, 4, 5, 3, 2, 1, 0, 5, 4, 3},
       {0, 0, 0, 0, 0, 0, 0, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12},
       1},
  };
  for (const Case& cs : cases) {
    AacUsacMps212Config cfg = base_config();
    cfg.fixed_gain_dmx = 3;
    AacMps212State* s = aac_mps212_create(&cfg, 1, 1024, 2048, 48000);
    CHECK(s != NULL, "create");
    if (!s) continue;
    Params p = default_params(kBands20);
    p.indep = 1;
    p.phase_mode = cs.phase;
    for (int b = 0; b < kBands20; b++) {
      p.cld[0][b] = cs.cld[b];
      p.icc[0][b] = cs.icc[b];
      p.ipd[0][b] = cs.ipd[b];
    }
    for (int t = 0; t < 3; t++) p.coding[t][0].pcm = 1;
    std::vector<uint8_t> bits = write_mps212(s, &cfg, &p, 32, kBands20, NULL, NULL, NULL);
    std::vector<AacUsacQmfFrame> frames(3);
    AacUsacQmfFrame* dmx = &frames[0];
    AacUsacQmfFrame* l = &frames[1];
    AacUsacQmfFrame* r = &frames[2];
    unsigned seed = 5u;
    BandStats st;
    memset(&st, 0, sizeof(st));
    double in_energy = 0.0, out_energy = 0.0;
    const int nframes = 60;
    for (int f = 0; f < nframes; f++) {
      AacBitReader rd;
      aac_bitreader_init(&rd, bits.data(), (int)bits.size());
      CHECK(aac_mps212_read(s, &rd, 1) == AAC_OK, "read frame %d", f);
      fill_noise(dmx, 32, &seed, 1000.0f, 64);
      aac_mps212_apply(s, dmx, NULL, l, r);
      if (f >= 10) {
        accumulate(&st, l, r, aac_mps212_kernel(kBands20), 0);
        for (int n = 0; n < 32; n++) {
          for (int b = 3; b < 64; b++) {
            in_energy += dmx->x[n][b][0] * dmx->x[n][b][0] + dmx->x[n][b][1] * dmx->x[n][b][1];
            out_energy += l->x[n][b][0] * l->x[n][b][0] + l->x[n][b][1] * l->x[n][b][1] +
                          r->x[n][b][0] * r->x[n][b][0] + r->x[n][b][1] * r->x[n][b][1];
          }
        }
      }
    }
    for (int pb = 8; pb < kBands20; pb++) {
      const double cld = 10.0 * log10(st.pl[pb] / st.pr[pb]);
      const double want_cld = aac_mps212_cld_db[cs.cld[pb] + 15];
      CHECK(fabs(cld - want_cld) < 0.7, "band %d: CLD %.2f dB, wanted %.1f", pb, cld, want_cld);
      const double icc =
          sqrt(st.cr[pb] * st.cr[pb] + st.ci[pb] * st.ci[pb]) / sqrt(st.pl[pb] * st.pr[pb]);
      const double want_icc = fabs(aac_mps212_icc_value[cs.icc[pb]]);
      CHECK(fabs(icc - want_icc) < 0.08, "band %d: ICC %.3f, wanted %.3f", pb, icc, want_icc);
      if (cs.phase && want_icc > 0.3) {
        double phase = atan2(st.ci[pb], st.cr[pb]);
        double want = cs.ipd[pb] * M_PI / 8.0;
        if (aac_mps212_icc_value[cs.icc[pb]] < 0.0) want += M_PI;
        double d = phase - want;
        while (d > M_PI) d -= 2.0 * M_PI;
        while (d < -M_PI) d += 2.0 * M_PI;
        CHECK(fabs(d) < 0.15, "band %d: phase %.3f, wanted %.3f", pb, phase, want);
      }
    }
    /* the fixed gain of 4.5 dB (bsFixedGainDMX 3) scales the downmix
     * before the energy-preserving split */
    const double gain_db = 10.0 * log10(out_energy / in_energy);
    CHECK(fabs(gain_db - 4.5) < 0.3, "overall gain %.2f dB", gain_db);
    aac_mps212_destroy(s);
  }
}

/* Two parameter sets in a frame: the level difference ramps between
 * them, slot by slot, with the six slots of hybrid delay. */
void test_interpolation(void) {
  AacUsacMps212Config cfg = base_config();
  AacMps212State* s = aac_mps212_create(&cfg, 1, 1024, 2048, 48000);
  CHECK(s != NULL, "create");
  if (!s) return;
  Params p0 = default_params(kBands20);
  p0.indep = 1;
  for (int b = 0; b < kBands20; b++) {
    p0.cld[0][b] = -5; /* -10 dB */
    p0.icc[0][b] = 0;
    p0.ipd[0][b] = 0;
  }
  for (int t = 0; t < 3; t++) p0.coding[t][0].pcm = 1;
  std::vector<uint8_t> b0 = write_mps212(s, &cfg, &p0, 32, kBands20, NULL, NULL, NULL);
  Params p1 = p0;
  p1.num_sets = 2;
  p1.framing_type = 1;
  p1.slot[0] = 15;
  p1.slot[1] = 31;
  for (int b = 0; b < kBands20; b++) {
    p1.cld[0][b] = -5;
    p1.cld[1][b] = 5; /* +10 dB */
    p1.icc[1][b] = 0;
    p1.ipd[1][b] = 0;
  }
  for (int t = 0; t < 3; t++) {
    p1.coding[t][1].mode = 3;
    p1.coding[t][1].pcm = 1;
  }
  std::vector<uint8_t> b1 = write_mps212(s, &cfg, &p1, 32, kBands20, NULL, NULL, NULL);
  Params p2 = p0;
  for (int b = 0; b < kBands20; b++) p2.cld[0][b] = 5;
  std::vector<uint8_t> b2 = write_mps212(s, &cfg, &p2, 32, kBands20, NULL, NULL, NULL);
  std::vector<AacUsacQmfFrame> frames(3);
  unsigned seed = 77u;
  double slot_cld[64] = {0};
  for (int f = 0; f < 6; f++) {
    const std::vector<uint8_t>& bits = f == 4 ? b1 : (f == 5 ? b2 : b0);
    AacBitReader rd;
    aac_bitreader_init(&rd, bits.data(), (int)bits.size());
    CHECK(aac_mps212_read(s, &rd, 1) == AAC_OK, "read");
    fill_noise(&frames[0], 32, &seed, 1000.0f, 64);
    aac_mps212_apply(s, &frames[0], NULL, &frames[1], &frames[2]);
    if (f >= 4) {
      for (int n = 0; n < 32; n++) {
        double pl = 0.0, pr = 0.0;
        for (int b = 3; b < 64; b++) {
          pl += frames[1].x[n][b][0] * frames[1].x[n][b][0] +
                frames[1].x[n][b][1] * frames[1].x[n][b][1];
          pr += frames[2].x[n][b][0] * frames[2].x[n][b][0] +
                frames[2].x[n][b][1] * frames[2].x[n][b][1];
        }
        slot_cld[(f - 4) * 32 + n] = 10.0 * log10(pl / pr);
      }
    }
  }
  /* set 0 (-10 dB) holds through slot 15 + 6, the ramp reaches 0 dB at
   * slot 23 + 6 and +10 dB at 31 + 6 */
  CHECK(fabs(slot_cld[21] + 10.0) < 2.5, "slot 21: %.2f dB", slot_cld[21]);
  CHECK(fabs(slot_cld[29]) < 3.0, "slot 29: %.2f dB", slot_cld[29]);
  CHECK(fabs(slot_cld[37] - 10.0) < 2.5, "slot 37: %.2f dB", slot_cld[37]);
  CHECK(fabs(slot_cld[50] - 10.0) < 2.5, "slot 50: %.2f dB", slot_cld[50]);
  CHECK(slot_cld[25] > slot_cld[21] + 1.0 && slot_cld[33] > slot_cld[29] + 1.0, "monotone ramp");
  aac_mps212_destroy(s);
}

/* Residual coding: with ICC 0 and CLD 0 the matrix is [[1, 1], [1, -1]]
 * / 2 on the downmix times the fixed gain, so left minus right is the
 * residual's QMF analysis (the lower 32 bands, six slots later), which
 * the library's 32-band bank gives independently, and left plus right is
 * the scaled downmix. */
void test_residual(void) {
  aac_qmf_tables_init();
  AacUsacMps212Config cfg = base_config();
  cfg.fixed_gain_dmx = 4; /* 6 dB */
  cfg.phase_coding = 0;
  cfg.temp_shape_config = 0;
  cfg.residual_coding = 1;
  cfg.residual_bands = 20;
  AacMps212State* s = aac_mps212_create(&cfg, 3, 1024, 2048, 48000);
  CHECK(s != NULL, "create with residual");
  if (!s) return;
  Params p = default_params(kBands20);
  p.indep = 1;
  for (int b = 0; b < kBands20; b++) {
    p.cld[0][b] = 0;
    p.icc[0][b] = 5; /* 0.0 */
  }
  for (int t = 0; t < 3; t++) p.coding[t][0].pcm = 1;
  std::vector<uint8_t> bits = write_mps212(s, &cfg, &p, 32, kBands20, NULL, NULL, NULL);
  std::vector<AacUsacQmfFrame> frames(3);
  std::vector<float> residual(1024);
  AacQmfAnalysis ref;
  aac_qmf_analysis_init(&ref, 32);
  std::vector<float> ref_w(40 * 32 * 32 * 2);
  float(*W)[32][2] = (float(*)[32][2])ref_w.data();
  std::vector<float> l_out(40 * 32 * 64 * 2), r_out(40 * 32 * 64 * 2), m_in(40 * 32 * 64 * 2);
  unsigned seed = 3u;
  const int nframes = 8;
  for (int f = 0; f < nframes; f++) {
    AacBitReader rd;
    aac_bitreader_init(&rd, bits.data(), (int)bits.size());
    CHECK(aac_mps212_read(s, &rd, 1) == AAC_OK, "read");
    for (float& v : residual) {
      seed = seed * 1664525u + 1013904223u;
      v = 0.1f * ((float)(seed >> 8) / 8388608.0f - 1.0f);
    }
    for (int n = 0; n < 32; n++) {
      float scaled[32];
      for (int i = 0; i < 32; i++) scaled[i] = residual[n * 32 + i] * 32768.0f;
      aac_qmf_analyze(&ref, scaled, W[f * 32 + n]);
    }
    fill_noise(&frames[0], 32, &seed, 1000.0f, 32);
    memcpy(&m_in[(size_t)f * 32 * 64 * 2], frames[0].x, sizeof(float) * 32 * 64 * 2);
    aac_mps212_apply(s, &frames[0], residual.data(), &frames[1], &frames[2]);
    memcpy(&l_out[(size_t)f * 32 * 64 * 2], frames[1].x, sizeof(float) * 32 * 64 * 2);
    memcpy(&r_out[(size_t)f * 32 * 64 * 2], frames[2].x, sizeof(float) * 32 * 64 * 2);
  }
  double err_d = 0.0, ref_d = 0.0, err_s = 0.0, ref_s = 0.0;
  for (int t = 40; t + 6 < nframes * 32; t++) {
    for (int b = 3; b < 32; b++) {
      const float* l = &l_out[(((size_t)(t + 6)) * 64 + b) * 2];
      const float* r = &r_out[(((size_t)(t + 6)) * 64 + b) * 2];
      const float* m = &m_in[(((size_t)t) * 64 + b) * 2];
      for (int c = 0; c < 2; c++) {
        const double diff = l[c] - r[c];
        const double want = W[t][b][c];
        err_d += (diff - want) * (diff - want);
        ref_d += want * want;
        const double sum = l[c] + r[c];
        const double want_sum = pow(10.0, 6.0 / 20.0) * m[c];
        err_s += (sum - want_sum) * (sum - want_sum);
        ref_s += want_sum * want_sum;
      }
    }
  }
  CHECK(err_d < 1e-6 * ref_d, "left minus right against the residual: %g relative", err_d / ref_d);
  CHECK(err_s < 1e-6 * ref_s, "left plus right against the downmix: %g relative", err_s / ref_s);
  aac_mps212_destroy(s);

  /* the 8:3 and 4:1 banks exist and run */
  cfg.freq_res = 1;
  cfg.residual_bands = 24;
  s = aac_mps212_create(&cfg, 3, 768, 2048, 48000);
  CHECK(s != NULL, "create 8:3 with residual");
  if (s) {
    std::vector<float> res768(768, 0.01f);
    fill_noise(&frames[0], 32, &seed, 1000.0f, 24);
    aac_mps212_apply(s, &frames[0], res768.data(), &frames[1], &frames[2]);
    aac_mps212_apply(s, &frames[0], res768.data(), &frames[1], &frames[2]);
    int finite = 1;
    for (int n = 0; n < 32; n++) {
      for (int b = 0; b < 64; b++) {
        finite &= std::isfinite(frames[1].x[n][b][0]) && std::isfinite(frames[2].x[n][b][1]);
      }
    }
    CHECK(finite, "8:3 output finite");
    aac_mps212_destroy(s);
  }
  s = aac_mps212_create(&cfg, 2, 1024, 4096, 48000);
  CHECK(s != NULL, "create 4:1 with residual");
  aac_mps212_destroy(s);
}

/* Concealment and aliasing: a lost frame holds the parameters, and the
 * left output may share the downmix frame's storage. */
void test_conceal_and_alias(void) {
  AacUsacMps212Config cfg = base_config();
  AacMps212State* s = aac_mps212_create(&cfg, 1, 1024, 2048, 48000);
  CHECK(s != NULL, "create");
  if (!s) return;
  Params p = default_params(kBands20);
  p.indep = 1;
  for (int t = 0; t < 3; t++) p.coding[t][0].pcm = 1;
  std::vector<uint8_t> bits = write_mps212(s, &cfg, &p, 32, kBands20, NULL, NULL, NULL);
  AacBitReader rd;
  aac_bitreader_init(&rd, bits.data(), (int)bits.size());
  CHECK(aac_mps212_read(s, &rd, 1) == AAC_OK, "read");
  aac_mps212_conceal(s);
  check_params(s, &p, kBands20, 1, "after conceal");
  std::vector<AacUsacQmfFrame> frames(3);
  unsigned seed = 1u;
  fill_noise(&frames[0], 32, &seed, 1000.0f, 64);
  frames[2] = frames[0];
  aac_mps212_apply(s, &frames[0], NULL, &frames[1], &frames[2]);
  AacMps212State* s2 = aac_mps212_create(&cfg, 1, 1024, 2048, 48000);
  aac_bitreader_init(&rd, bits.data(), (int)bits.size());
  aac_mps212_read(s2, &rd, 1);
  aac_mps212_conceal(s2);
  AacUsacQmfFrame right;
  AacUsacQmfFrame dmx2 = frames[2];
  frames[2] = frames[0];
  aac_mps212_apply(s2, &frames[2], NULL, &frames[2], &right);
  CHECK(memcmp(frames[2].x, frames[1].x, sizeof(frames[1].x)) == 0, "aliased left matches");
  (void)dmx2;
  aac_mps212_destroy(s2);
  aac_mps212_destroy(s);
}

}  // namespace

int main(void) {
  test_tables();
  test_codebooks();
  test_time_differences();
  test_low_rate_and_shaping();
  test_ott_matrix();
  test_hybrid();
  test_decorrelator();
  test_upmix_consistency();
  test_interpolation();
  test_residual();
  test_conceal_and_alias();
  return test_summary("test_mps212");
}
