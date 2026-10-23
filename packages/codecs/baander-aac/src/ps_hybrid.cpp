#include "ps.h"

#include <cmath>
#include <cstring>

/* The hybrid filterbank of parametric stereo (ISO/IEC 14496-3 8.6.4.3),
 * shared by the decoder and the encoder: the lowest three (20-band mode)
 * or five (34-band mode) QMF bands are split into sub-subbands with
 * 13-tap complex filters centred on the current slot, the other bands
 * pass through, and the synthesis sums the sub-subbands back. */

namespace {

constexpr int kSlots = AAC_PS_SLOTS;

float g_f20_0_8[8][8][2];
float g_f34_0_12[12][8][2];
float g_f34_1_8[8][8][2];
float g_f34_2_4[4][8][2];

const float kG0Q8[7] = {0.00746082949812f, 0.02270420949825f, 0.04546865930473f, 0.07266113929591f,
                        0.09885108575264f, 0.11793710567217f, 0.125f};
const float kG0Q12[7] = {0.04081179924692f, 0.03812810994926f, 0.05144908135699f,
                         0.06399831151592f, 0.07428313801106f, 0.08100347892914f,
                         0.08333333333333f};
const float kG1Q8[7] = {0.01565675600122f, 0.03752716391991f, 0.05417891378782f, 0.08417044116767f,
                        0.10307344158036f, 0.12222452249753f, 0.125f};
const float kG2Q4[7] = {-0.05908211155639f, -0.04871498374946f, 0.0f,  0.07778723915851f,
                        0.16486303567403f,  0.23279856662996f,  0.25f};
const float kG1Q2[7] = {0.0f, 0.01899487526049f, 0.0f, -0.07293139167538f,
                        0.0f, 0.30596630545168f, 0.5f};

void make_filters_from_proto(float (*filter)[8][2], const float* proto, int bands) {
  for (int q = 0; q < bands; q++) {
    for (int n = 0; n < 7; n++) {
      const double theta = 2.0 * M_PI * (q + 0.5) * (n - 6) / bands;
      filter[q][n][0] = (float)(proto[n] * cos(theta));
      filter[q][n][1] = (float)(proto[n] * -sin(theta));
    }
  }
}


/* Splits one QMF band into N sub-subbands with a 13-tap complex filter
 * centred on the current slot. */
void hybrid_analysis_n(float (*out)[2], const float (*in)[2], const float (*filter)[8][2],
                       int stride, int N) {
  float inre0[6], inre1[6], inim0[6], inim1[6];
  for (int j = 0; j < 6; j++) {
    inre0[j] = in[j][0] + in[12 - j][0];
    inre1[j] = in[j][1] - in[12 - j][1];
    inim0[j] = in[j][1] + in[12 - j][1];
    inim1[j] = in[j][0] - in[12 - j][0];
  }
  for (int i = 0; i < N; i++) {
    float sum_re = filter[i][6][0] * in[6][0];
    float sum_im = filter[i][6][0] * in[6][1];
    for (int j = 0; j < 6; j++) {
      sum_re += filter[i][j][0] * inre0[j] - filter[i][j][1] * inre1[j];
      sum_im += filter[i][j][0] * inim0[j] + filter[i][j][1] * inim1[j];
    }
    out[i * stride][0] = sum_re;
    out[i * stride][1] = sum_im;
  }
}

/* Splits one band into two with a symmetric real filter whose non-centre
 * even taps are zero. */
void hybrid2_re(const float (*in)[2], float (*out)[kSlots][2], const float filter[7], int len,
                int reverse) {
  for (int i = 0; i < len; i++, in++) {
    const float re_in = filter[6] * in[6][0];
    const float im_in = filter[6] * in[6][1];
    float re_op = 0.0f;
    float im_op = 0.0f;
    for (int j = 0; j < 6; j += 2) {
      re_op += filter[j + 1] * (in[j + 1][0] + in[12 - j - 1][0]);
      im_op += filter[j + 1] * (in[j + 1][1] + in[12 - j - 1][1]);
    }
    out[reverse][i][0] = re_in + re_op;
    out[reverse][i][1] = im_in + im_op;
    out[!reverse][i][0] = re_in - re_op;
    out[!reverse][i][1] = im_in - im_op;
  }
}

/* Splits the lowest band into six sub-subbands (eight filters, with the
 * outer pairs merged). */
void hybrid6_cx(const float (*in)[2], float (*out)[kSlots][2], const float (*filter)[8][2],
                int len) {
  for (int i = 0; i < len; i++, in++) {
    float temp[8][2];
    hybrid_analysis_n(temp, in, filter, 1, 8);
    out[0][i][0] = temp[6][0];
    out[0][i][1] = temp[6][1];
    out[1][i][0] = temp[7][0];
    out[1][i][1] = temp[7][1];
    out[2][i][0] = temp[0][0];
    out[2][i][1] = temp[0][1];
    out[3][i][0] = temp[1][0];
    out[3][i][1] = temp[1][1];
    out[4][i][0] = temp[2][0] + temp[5][0];
    out[4][i][1] = temp[2][1] + temp[5][1];
    out[5][i][0] = temp[3][0] + temp[4][0];
    out[5][i][1] = temp[3][1] + temp[4][1];
  }
}

void hybrid4_8_12_cx(const float (*in)[2], float (*out)[kSlots][2], const float (*filter)[8][2],
                     int N, int len) {
  for (int i = 0; i < len; i++, in++) {
    hybrid_analysis_n(out[0] + i, in, filter, kSlots, N);
  }
}

}  // namespace

void aac_ps_hybrid_analysis(float out[AAC_PS_MAX_SUBBANDS][AAC_PS_SLOTS][2], float in[5][44][2],
                            const float L[2][38][64], int is34) {
  const int len = kSlots;
  for (int i = 0; i < 5; i++) {
    for (int j = 0; j < 38; j++) {
      in[i][j + 6][0] = L[0][j][i];
      in[i][j + 6][1] = L[1][j][i];
    }
  }
  int first_plain; /* first QMF band passed through unsplit */
  if (is34) {
    hybrid4_8_12_cx(in[0], out, g_f34_0_12, 12, len);
    hybrid4_8_12_cx(in[1], out + 12, g_f34_1_8, 8, len);
    hybrid4_8_12_cx(in[2], out + 20, g_f34_2_4, 4, len);
    hybrid4_8_12_cx(in[3], out + 24, g_f34_2_4, 4, len);
    hybrid4_8_12_cx(in[4], out + 28, g_f34_2_4, 4, len);
    first_plain = 5;
  } else {
    hybrid6_cx(in[0], out, g_f20_0_8, len);
    hybrid2_re(in[1], out + 6, kG1Q2, len, 1);
    hybrid2_re(in[2], out + 8, kG1Q2, len, 0);
    first_plain = 3;
  }
  const int offset = is34 ? 27 : 7; /* sub-subband index of band first_plain */
  for (int i = first_plain; i < 64; i++) {
    for (int j = 0; j < len; j++) {
      out[offset + i][j][0] = L[0][j][i];
      out[offset + i][j][1] = L[1][j][i];
    }
  }
  for (int i = 0; i < 5; i++) {
    memcpy(in[i], in[i] + 32, 6 * sizeof(in[i][0]));
  }
}

void aac_ps_hybrid_synthesis(float out[2][38][64],
                             const float in[AAC_PS_MAX_SUBBANDS][AAC_PS_SLOTS][2], int is34) {
  const int len = kSlots;
  int first_plain;
  if (is34) {
    for (int n = 0; n < len; n++) {
      for (int b = 0; b < 5; b++) {
        out[0][n][b] = 0.0f;
        out[1][n][b] = 0.0f;
      }
      for (int i = 0; i < 12; i++) {
        out[0][n][0] += in[i][n][0];
        out[1][n][0] += in[i][n][1];
      }
      for (int i = 0; i < 8; i++) {
        out[0][n][1] += in[12 + i][n][0];
        out[1][n][1] += in[12 + i][n][1];
      }
      for (int i = 0; i < 4; i++) {
        out[0][n][2] += in[20 + i][n][0];
        out[1][n][2] += in[20 + i][n][1];
        out[0][n][3] += in[24 + i][n][0];
        out[1][n][3] += in[24 + i][n][1];
        out[0][n][4] += in[28 + i][n][0];
        out[1][n][4] += in[28 + i][n][1];
      }
    }
    first_plain = 5;
  } else {
    for (int n = 0; n < len; n++) {
      out[0][n][0] = in[0][n][0] + in[1][n][0] + in[2][n][0] + in[3][n][0] + in[4][n][0] +
                     in[5][n][0];
      out[1][n][0] = in[0][n][1] + in[1][n][1] + in[2][n][1] + in[3][n][1] + in[4][n][1] +
                     in[5][n][1];
      out[0][n][1] = in[6][n][0] + in[7][n][0];
      out[1][n][1] = in[6][n][1] + in[7][n][1];
      out[0][n][2] = in[8][n][0] + in[9][n][0];
      out[1][n][2] = in[8][n][1] + in[9][n][1];
    }
    first_plain = 3;
  }
  const int offset = is34 ? 27 : 7;
  for (int i = first_plain; i < 64; i++) {
    for (int n = 0; n < len; n++) {
      out[0][n][i] = in[offset + i][n][0];
      out[1][n][i] = in[offset + i][n][1];
    }
  }
}

void aac_ps_hybrid_init(void) {
  static bool ready = false;
  if (ready) {
    return;
  }
  make_filters_from_proto(g_f20_0_8, kG0Q8, 8);
  make_filters_from_proto(g_f34_0_12, kG0Q12, 12);
  make_filters_from_proto(g_f34_1_8, kG1Q8, 8);
  make_filters_from_proto(g_f34_2_4, kG2Q4, 4);
  ready = true;
}

/* Table 8.48: parameter band of each sub-subband, 20-band mode. */
const int8_t aac_ps_k_to_i_20[71] = {1,  0,  0,  1,  2,  3,  4,  5,  6,  7,  8,  9,  10, 11, 12, 13, 14, 14,
                            15, 15, 15, 16, 16, 16, 16, 17, 17, 17, 17, 17, 18, 18, 18, 18, 18, 18,
                            18, 18, 18, 18, 18, 18, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19,
                            19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19};
/* Table 8.49: the same for 34 bands. */
const int8_t aac_ps_k_to_i_34[91] = {0,  1,  2,  3,  4,  5,  6,  6,  7,  2,  1,  0,  10, 10, 4,  5,  6,  7,  8,
                            9,  10, 11, 12, 9,  14, 11, 12, 13, 14, 15, 16, 13, 16, 17, 18, 19, 20, 21,
                            22, 22, 23, 23, 24, 24, 25, 25, 26, 26, 27, 27, 27, 28, 28, 28, 29, 29, 29,
                            30, 30, 30, 31, 31, 31, 31, 32, 32, 32, 32, 33, 33, 33, 33, 33, 33, 33, 33,
                            33, 33, 33, 33, 33, 33, 33, 33, 33, 33, 33, 33, 33, 33, 33};

/* Tables 8.20 to 8.29 as {symbol, code length} in canonical order: the
 * codes are assigned in table order, each one the next value of its
 * length after the previous code. */
const uint8_t aac_ps_huff_sizes[AAC_PS_NUM_HUFF] = {61, 61, 29, 29, 15, 15, 8, 8, 8, 8};
const int8_t aac_ps_huff_offset[AAC_PS_NUM_HUFF] = {-30, -30, -14, -14, -7, -7, 0, 0, 0, 0};

const uint8_t aac_ps_huff_tabs[][2] = {
    /* iid_df1 - 61 entries */
    {28, 4}, {32, 4}, {29, 3}, {31, 3}, {27, 5}, {33, 5}, {26, 6}, {34, 6}, {25, 7}, {35, 7},
    {24, 8}, {36, 8}, {37, 9}, {40, 11}, {19, 12}, {41, 12}, {22, 10}, {38, 10}, {9, 17},
    {51, 17}, {11, 17}, {49, 17}, {13, 16}, {47, 16}, {16, 14}, {18, 13}, {42, 13}, {44, 14},
    {12, 17}, {48, 17}, {4, 18}, {5, 18}, {2, 18}, {3, 18}, {15, 15}, {21, 11}, {39, 11},
    {45, 15}, {8, 18}, {52, 18}, {6, 18}, {7, 18}, {55, 18}, {56, 18}, {53, 18}, {54, 18},
    {17, 14}, {43, 14}, {59, 18}, {60, 18}, {57, 18}, {58, 18}, {0, 18}, {1, 18}, {10, 18},
    {50, 18}, {14, 16}, {46, 16}, {20, 12}, {23, 10}, {30, 1},
    /* iid_dt1 - 61 entries */
    {31, 2}, {26, 7}, {34, 7}, {27, 6}, {33, 6}, {35, 8}, {24, 9}, {36, 9}, {39, 11}, {41, 12},
    {9, 15}, {10, 15}, {48, 15}, {49, 15}, {17, 13}, {23, 10}, {37, 10}, {43, 13}, {11, 15},
    {12, 15}, {4, 16}, {56, 16}, {2, 16}, {3, 16}, {59, 16}, {60, 16}, {57, 16}, {58, 16},
    {0, 16}, {1, 16}, {5, 16}, {55, 16}, {6, 16}, {54, 16}, {13, 15}, {15, 14}, {20, 12},
    {40, 12}, {22, 11}, {38, 11}, {45, 14}, {47, 15}, {7, 16}, {53, 16}, {18, 13}, {42, 13},
    {16, 14}, {44, 14}, {8, 16}, {52, 16}, {14, 15}, {46, 15}, {50, 16}, {51, 16}, {19, 13},
    {21, 12}, {25, 9}, {28, 5}, {32, 5}, {29, 3}, {30, 1},
    /* iid_df0 - 29 entries */
    {14, 1}, {15, 3}, {13, 3}, {16, 4}, {12, 4}, {17, 5}, {11, 5}, {10, 6}, {18, 6}, {19, 6},
    {9, 7}, {20, 8}, {8, 9}, {7, 10}, {21, 11}, {22, 13}, {6, 13}, {23, 14}, {24, 14}, {5, 15},
    {25, 15}, {4, 16}, {3, 17}, {0, 17}, {1, 17}, {2, 17}, {26, 17}, {27, 18}, {28, 18},
    /* iid_dt0 - 29 entries */
    {14, 1}, {13, 2}, {15, 3}, {12, 4}, {16, 5}, {11, 6}, {17, 7}, {10, 8}, {18, 9}, {9, 10},
    {19, 11}, {8, 12}, {20, 13}, {21, 14}, {7, 15}, {22, 17}, {6, 17}, {23, 19}, {0, 19},
    {1, 19}, {2, 19}, {3, 20}, {4, 20}, {5, 20}, {24, 20}, {25, 20}, {26, 20}, {27, 20},
    {28, 20},
    /* icc_df - 15 entries */
    {7, 1}, {8, 2}, {6, 3}, {9, 4}, {5, 5}, {10, 6}, {4, 7}, {11, 8}, {12, 9}, {3, 10},
    {13, 11}, {2, 12}, {14, 13}, {1, 14}, {0, 14},
    /* icc_dt - 15 entries */
    {7, 1}, {8, 2}, {6, 3}, {9, 4}, {5, 5}, {10, 6}, {4, 7}, {11, 8}, {3, 9}, {12, 10},
    {2, 11}, {13, 12}, {1, 13}, {0, 14}, {14, 14},
    /* ipd_df - 8 entries */
    {1, 3}, {4, 4}, {5, 4}, {3, 4}, {6, 4}, {2, 4}, {7, 4}, {0, 1},
    /* ipd_dt - 8 entries */
    {5, 4}, {4, 5}, {3, 5}, {2, 4}, {6, 4}, {1, 3}, {7, 3}, {0, 1},
    /* opd_df - 8 entries */
    {7, 3}, {1, 3}, {3, 4}, {6, 4}, {2, 4}, {5, 5}, {4, 5}, {0, 1},
    /* opd_dt - 8 entries */
    {5, 4}, {2, 4}, {6, 4}, {4, 5}, {3, 5}, {1, 3}, {7, 3}, {0, 1},
};
