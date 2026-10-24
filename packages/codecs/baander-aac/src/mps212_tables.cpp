#include <cstdint>

#include "mps212.h"

extern const float aac_mps212_ges_ratio[2][8];
extern const int aac_mps212_ges_levels[2];
extern const uint8_t aac_mps212_decorr_region_edge[5];
extern const uint8_t aac_mps212_decorr_order[3][4];
extern const uint8_t aac_mps212_decorr_delay[3][4];
extern const float aac_mps212_decorr_reflection[4][10];

/* Tables of the MPEG Surround 212 decoder (ISO/IEC 23003-3 7.11 and
 * ISO/IEC 23003-1).  None of them was copied from a reference
 * implementation; the report of the implementation names the confidence
 * in each.  The hybrid band order and the parameter band partition were
 * measured against the FDK decoder's output for the 20 and 10 band
 * resolutions (level-difference staircases over the QMF bands). */

/* Parameter bands per bsFreqRes (ISO/IEC 23003-1 Table 40); 0 is
 * reserved.  The FDK encoder's 24 kbit/s streams carry bsFreqRes 2 and
 * decode with 20 bands, its 16 kbit/s streams carry 4 and decode with 10. */
const uint8_t aac_mps212_freq_res_bands[8] = {0, 28, 20, 14, 10, 7, 5, 4};

/* Hybrid band to parameter band (ISO/IEC 23003-1 Table A.30 and its
 * coarser rows).  The 71 hybrid bands follow the parametric stereo order:
 * 0..5 from the eight-band filter on QMF band 0 (0 and 1 lie outside the
 * band, 2..5 are its four quarters in ascending frequency, 4 and 5 with an
 * out-of-band partner merged in), 6..7 and 8..9 the ascending halves of
 * QMF bands 1 and 2, 10..70 QMF bands 3..63.  The 28 and 20 band rows
 * start as the parametric stereo table does (1, 0, 0, 1, 2, 3), which
 * places the four quarters of QMF band 0 in four ascending parameter
 * bands; the FDK decoder's output shows that partition for 20 bands and
 * three ascending bands for 10 (the lowest two quarters merged). */
static const uint8_t kKernels28[71] = {
    1,  0,  0,  1,  2,  3,  4,  5,  6,  7,  8,  9,  10, 11, 12, 13, 14, 15, 16, 16, 17, 17, 18, 18,
    19, 19, 19, 20, 20, 20, 21, 21, 21, 21, 22, 22, 22, 22, 23, 23, 23, 23, 23, 24, 24, 24, 24, 24,
    24, 25, 25, 25, 25, 25, 25, 25, 26, 26, 26, 26, 26, 26, 26, 27, 27, 27, 27, 27, 27, 27, 27};
static const uint8_t kKernels20[71] = {
    1,  0,  0,  1,  2,  3,  4,  5,  6,  7,  8,  9,  10, 11, 12, 13, 14, 14, 15, 15, 15, 16, 16, 16,
    16, 17, 17, 17, 17, 17, 18, 18, 18, 18, 18, 18, 18, 18, 18, 18, 18, 18, 19, 19, 19, 19, 19, 19,
    19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19, 19};
static const uint8_t kKernels14[71] = {
    1,  0,  0,  1,  2,  3,  4,  4,  5,  5,  6,  7,  8,  8,  9,  9,  10, 10, 10, 11, 11, 11, 11, 11,
    12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13,
    13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13, 13};
static const uint8_t kKernels10[71] = {0, 0, 0, 0, 1, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 8,
                                       8, 8, 8, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9,
                                       9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9,
                                       9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 9};
static const uint8_t kKernels7[71] = {0, 0, 0, 0, 1, 1, 2, 2, 2, 2, 3, 3, 3, 4, 4, 4, 4, 4,
                                      5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 6, 6, 6, 6, 6, 6, 6, 6,
                                      6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
                                      6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6};
static const uint8_t kKernels5[71] = {0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 2, 2, 2, 2, 2, 3, 3, 3,
                                      3, 3, 3, 3, 3, 3, 3, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4,
                                      4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4,
                                      4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4};
static const uint8_t kKernels4[71] = {0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2, 2, 2, 2, 3,
                                      3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3,
                                      3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3,
                                      3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3};

const uint8_t* aac_mps212_kernel(int num_bands) {
  switch (num_bands) {
    case 28:
      return kKernels28;
    case 20:
      return kKernels20;
    case 14:
      return kKernels14;
    case 10:
      return kKernels10;
    case 7:
      return kKernels7;
    case 5:
      return kKernels5;
    default:
      return kKernels4;
  }
}

/* CLD quantiser (ISO/IEC 23003-1 Table 45): index -15..15 in dB. */
const float aac_mps212_cld_db[31] = {
    -150.0f, -45.0f, -40.0f, -35.0f, -30.0f, -25.0f, -22.0f, -19.0f, -16.0f, -13.0f, -10.0f,
    -8.0f,   -6.0f,  -4.0f,  -2.0f,  0.0f,   2.0f,   4.0f,   6.0f,   8.0f,   10.0f,  13.0f,
    16.0f,   19.0f,  22.0f,  25.0f,  30.0f,  35.0f,  40.0f,  45.0f,  150.0f};

/* ICC quantiser (Table 46): index 0..7. */
const float aac_mps212_icc_value[8] = {1.0f,     0.937f, 0.84118f, 0.60092f,
                                       0.36764f, 0.0f,   -0.589f,  -0.99f};

/* bsFixedGainDMX (Table 43) in dB: the gain the encoder took out of the
 * downmix, which the decoder puts back. */
const float aac_mps212_fixed_gain_db[8] = {0.0f, 1.5f, 3.0f, 4.5f, 6.0f, 7.5f, 9.0f, 12.0f};

/* Guided envelope shaping: the envelope ratio per quantiser index for
 * bsEnvQuantMode 0 (eight levels) and 1 (five levels).  Reconstructed
 * without the standard's table: half-octave and octave steps around 1. */
const float aac_mps212_ges_ratio[2][8] = {
    {0.35355f, 0.5f, 0.70711f, 1.0f, 1.41421f, 2.0f, 2.82843f, 4.0f},
    {0.25f, 0.5f, 1.0f, 2.0f, 4.0f, 4.0f, 4.0f, 4.0f}};
const int aac_mps212_ges_levels[2] = {8, 5};

/* Decorrelator configurations (ISO/IEC 23003-3 bsDecorrConfig 0..2 over
 * the structure of ISO/IEC 23003-1 6.6.2: four hybrid band regions, each
 * with a pre-delay in slots and a lattice all-pass filter of the given
 * order).  The region edges, orders and delays are this implementation's
 * values: configuration 0 is the longest reverberation, 2 the shortest. */
const uint8_t aac_mps212_decorr_region_edge[5] = {0, 10, 23, 41, 71};
const uint8_t aac_mps212_decorr_order[3][4] = {{10, 8, 3, 2}, {8, 6, 3, 2}, {6, 4, 2, 1}};
const uint8_t aac_mps212_decorr_delay[3][4] = {{8, 7, 2, 1}, {6, 5, 2, 1}, {4, 3, 1, 1}};

/* Reflection coefficients of the lattice all-pass filters, one row per
 * region, the first `order` entries used.  Magnitudes below 0.7 keep the
 * filters stable and the impulse responses a few tens of slots long. */
const float aac_mps212_decorr_reflection[4][10] = {
    {0.62f, -0.48f, 0.41f, -0.55f, 0.33f, -0.29f, 0.46f, -0.37f, 0.25f, -0.19f},
    {-0.57f, 0.44f, -0.36f, 0.51f, -0.28f, 0.39f, -0.22f, 0.31f, 0.0f, 0.0f},
    {0.53f, -0.41f, 0.34f, 0.0f, 0.0f, 0.0f, 0.0f, 0.0f, 0.0f, 0.0f},
    {-0.47f, 0.36f, 0.0f, 0.0f, 0.0f, 0.0f, 0.0f, 0.0f, 0.0f, 0.0f}};
