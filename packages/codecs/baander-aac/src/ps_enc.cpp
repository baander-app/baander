#include "ps_enc.h"

#include <algorithm>
#include <cmath>
#include <cstring>

/* Parametric stereo encoder (ISO/IEC 14496-3 8.6.4, 3GPP TS 26.405).
 * The parameters of a frame are estimated over the same output slots the
 * decoder applies them to (32, or 30 for 960-sample frames): the SBR
 * encoder hands over the slots its grid covers plus six of lookahead for
 * the hybrid filters. */

namespace {

constexpr int kBands = AAC_PS_ENC_BANDS;
constexpr int kSlots = AAC_PS_SLOTS;
constexpr int kSubbands = 71; /* hybrid sub-subbands in 20-band mode */
constexpr int kIidMode = 1;   /* 20 bands, coarse quantization */
constexpr int kIccMode = 1;   /* 20 bands, mixing mode A */
constexpr int kIccRealBands = 11; /* bands whose coherence is the real part of the cross term */

/* Table 8.42: the coarse IID quantization grid in dB. */
const float kIidGrid[15] = {-25.0f, -18.0f, -14.0f, -10.0f, -7.0f, -4.0f, -2.0f, 0.0f,
                            2.0f,   4.0f,   7.0f,   10.0f,  14.0f, 18.0f, 25.0f};
/* Table 8.44: the ICC quantization grid. */
const float kIccGrid[8] = {1.0f, 0.937f, 0.84118f, 0.60092f, 0.36764f, 0.0f, -0.589f, -1.0f};

struct HuffCode {
  uint32_t code[32]; /* indexed by value + 16 */
  uint8_t len[32];
};

HuffCode g_iid_df, g_iid_dt, g_icc_df, g_icc_dt;
bool g_codes_ready = false;

void build_code(int table, HuffCode* out) {
  const uint8_t (*tab)[2] = aac_ps_huff_tabs;
  for (int t = 0; t < table; t++) {
    tab += aac_ps_huff_sizes[t];
  }
  memset(out, 0, sizeof(*out));
  uint32_t code = 0;
  for (int i = 0; i < aac_ps_huff_sizes[table]; i++) {
    const int len = tab[i][1];
    const int value = tab[i][0] + aac_ps_huff_offset[table];
    out->code[value + 16] = code >> (32 - len);
    out->len[value + 16] = (uint8_t)len;
    code += 1u << (32 - len);
  }
}

void build_codes(void) {
  if (g_codes_ready) {
    return;
  }
  build_code(AAC_PS_HUFF_IID_DF0, &g_iid_df);
  build_code(AAC_PS_HUFF_IID_DT0, &g_iid_dt);
  build_code(AAC_PS_HUFF_ICC_DF, &g_icc_df);
  build_code(AAC_PS_HUFF_ICC_DT, &g_icc_dt);
  g_codes_ready = true;
}

int code_bits(const HuffCode* h, int value) { return h->len[value + 16]; }

void write_code(AacBitWriter* w, const HuffCode* h, int value) {
  aac_bitwriter_write(w, h->code[value + 16], h->len[value + 16]);
}

int nearest(const float* grid, int n, float v) {
  int best = 0;
  for (int i = 1; i < n; i++) {
    if (fabsf(v - grid[i]) < fabsf(v - grid[best])) {
      best = i;
    }
  }
  return best;
}

/* Delta coding of one parameter set over the envelopes: frequency
 * deltas from zero, or time deltas against the previous envelope when
 * they are cheaper and in range.  Leaves the deltas and returns the bits. */
int code_parameters(int (*idx)[kBands], int num_env, const int* prev, int have_prev, int lav,
                    const HuffCode* df, const HuffCode* dt, int* use_dt, int (*delta)[kBands]) {
  int total = 0;
  const int* last = prev;
  for (int e = 0; e < num_env; e++) {
    int bits_f = 0;
    int bits_t = 0;
    bool time_ok = have_prev || e > 0;
    int v = 0;
    for (int b = 0; b < kBands; b++) {
      bits_f += code_bits(df, idx[e][b] - v);
      v = idx[e][b];
      if (time_ok) {
        const int d = idx[e][b] - last[b];
        if (std::abs(d) > lav) {
          time_ok = false;
        } else {
          bits_t += code_bits(dt, d);
        }
      }
    }
    use_dt[e] = time_ok && bits_t < bits_f;
    total += 1 + (use_dt[e] ? bits_t : bits_f);
    v = 0;
    for (int b = 0; b < kBands; b++) {
      delta[e][b] = idx[e][b] - (use_dt[e] ? last[b] : v);
      v = idx[e][b];
    }
    last = idx[e];
  }
  return total;
}

}  // namespace

void aac_ps_enc_init(AacPsEncoder* ps, int bitrate) {
  build_codes();
  aac_ps_hybrid_init();
  memset(ps, 0, sizeof(*ps));
  ps->num_env = bitrate < 28000 ? 1 : (bitrate < 36000 ? 2 : 4);
  aac_ps_enc_reset(ps);
}

void aac_ps_enc_reset(AacPsEncoder* ps) {
  memset(ps->in_buf, 0, sizeof(ps->in_buf));
  memset(ps->dmx_lr, 0, sizeof(ps->dmx_lr));
  memset(ps->dmx_m, 0, sizeof(ps->dmx_m));
  memset(ps->prev_iid, 0, sizeof(ps->prev_iid));
  memset(ps->prev_icc, 0, sizeof(ps->prev_icc));
  ps->have_prev = 0;
  ps->bits = 0;
  ps->header = 1;
}

void aac_ps_enc_downmix(AacPsEncoder* ps, const float L[64][2], const float R[64][2],
                        float M[64][2]) {
  const float alpha = 0.7f;
  for (int k = 0; k < 64; k++) {
    const float mr = 0.5f * (L[k][0] + R[k][0]);
    const float mi = 0.5f * (L[k][1] + R[k][1]);
    const float lr = L[k][0] * L[k][0] + L[k][1] * L[k][1] + R[k][0] * R[k][0] + R[k][1] * R[k][1];
    const float m = mr * mr + mi * mi;
    ps->dmx_lr[k] = alpha * ps->dmx_lr[k] + (1.0f - alpha) * lr;
    ps->dmx_m[k] = alpha * ps->dmx_m[k] + (1.0f - alpha) * m;
    /* the decoder's mixing restores the pair's energy from twice the
     * downmix energy */
    float gain = sqrtf(ps->dmx_lr[k] / (2.0f * ps->dmx_m[k] + 1e-9f));
    gain = std::min(std::max(gain, 1.0f), 2.0f);
    M[k][0] = mr * gain;
    M[k][1] = mi * gain;
  }
}

void aac_ps_enc_analyze(AacPsEncoder* ps, const float L[2][38][64], const float R[2][38][64],
                        int header, int slots) {
  aac_ps_hybrid_analysis(ps->hyb[0], ps->in_buf[0], L, 0, slots);
  aac_ps_hybrid_analysis(ps->hyb[1], ps->in_buf[1], R, 0, slots);
  const int num_env = ps->num_env;
  const int log2_env = num_env == 4 ? 2 : (num_env == 2 ? 1 : 0);
  for (int e = 0; e < num_env; e++) {
    const int t0 = (e * slots) >> log2_env;
    const int t1 = ((e + 1) * slots) >> log2_env;
    double el[kBands] = {}, er[kBands] = {}, cr[kBands] = {}, ci[kBands] = {};
    for (int k = 0; k < kSubbands; k++) {
      const int b = aac_ps_k_to_i_20[k];
      for (int t = t0; t < t1; t++) {
        const float* l = ps->hyb[0][k][t];
        const float* r = ps->hyb[1][k][t];
        el[b] += (double)l[0] * l[0] + (double)l[1] * l[1];
        er[b] += (double)r[0] * r[0] + (double)r[1] * r[1];
        cr[b] += (double)l[0] * r[0] + (double)l[1] * r[1];
        ci[b] += (double)l[1] * r[0] - (double)l[0] * r[1];
      }
    }
    for (int b = 0; b < kBands; b++) {
      const double floor = 1e-6;
      const float iid = 10.0f * (float)log10((el[b] + floor) / (er[b] + floor));
      ps->iid_idx[e][b] = nearest(kIidGrid, 15, iid) - 7;
      const double norm = sqrt((el[b] + floor) * (er[b] + floor));
      const double icc = b < kIccRealBands ? cr[b] / norm : sqrt(cr[b] * cr[b] + ci[b] * ci[b]) / norm;
      ps->icc_idx[e][b] = nearest(kIccGrid, 8, (float)std::min(std::max(icc, -1.0), 1.0));
    }
  }
  const int have_prev = ps->have_prev && !header;
  int bits = 1 + 1 + 2; /* header flag, frame_class, num_env */
  if (header) {
    bits += 1 + 3 + 1 + 3 + 1;
  }
  bits += code_parameters(ps->iid_idx, num_env, ps->prev_iid, have_prev, 14, &g_iid_df, &g_iid_dt,
                          ps->iid_dt, ps->iid_delta);
  bits += code_parameters(ps->icc_idx, num_env, ps->prev_icc, have_prev, 7, &g_icc_df, &g_icc_dt,
                          ps->icc_dt, ps->icc_delta);
  ps->bits = bits;
  ps->header = header;
  memcpy(ps->prev_iid, ps->iid_idx[num_env - 1], sizeof(ps->prev_iid));
  memcpy(ps->prev_icc, ps->icc_idx[num_env - 1], sizeof(ps->prev_icc));
  ps->have_prev = 1;
}

void aac_ps_enc_write(const AacPsEncoder* ps, AacBitWriter* w) {
  aac_bitwriter_write(w, (uint32_t)ps->header, 1);
  if (ps->header) {
    aac_bitwriter_write(w, 1, 1); /* enable_iid */
    aac_bitwriter_write(w, kIidMode, 3);
    aac_bitwriter_write(w, 1, 1); /* enable_icc */
    aac_bitwriter_write(w, kIccMode, 3);
    aac_bitwriter_write(w, 0, 1); /* enable_ext */
  }
  aac_bitwriter_write(w, 0, 1); /* frame_class: fixed borders */
  aac_bitwriter_write(w, ps->num_env == 4 ? 3 : (ps->num_env == 2 ? 2 : 1), 2);
  for (int e = 0; e < ps->num_env; e++) {
    aac_bitwriter_write(w, (uint32_t)ps->iid_dt[e], 1);
    for (int b = 0; b < kBands; b++) {
      write_code(w, ps->iid_dt[e] ? &g_iid_dt : &g_iid_df, ps->iid_delta[e][b]);
    }
  }
  for (int e = 0; e < ps->num_env; e++) {
    aac_bitwriter_write(w, (uint32_t)ps->icc_dt[e], 1);
    for (int b = 0; b < kBands; b++) {
      write_code(w, ps->icc_dt[e] ? &g_icc_dt : &g_icc_df, ps->icc_delta[e][b]);
    }
  }
}
