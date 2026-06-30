#include <cmath>

#include "aac_tables.h"

const int aac_num_sample_rates = 12;
const int aac_sample_rates[AAC_NUM_SAMPLE_RATES] = {96000, 88200, 64000, 48000, 44100, 32000,
                                                    24000, 22050, 16000, 12000, 11025, 8000};

const int aac_num_sfb_long[AAC_NUM_SAMPLE_RATES] = {40, 43, 49, 49, 49, 46, 46, 46, 37, 37, 37, 34};

const int aac_sfb_offset_long[AAC_NUM_SAMPLE_RATES][AAC_MAX_SFB_LONG + 1] = {
    /* 96000 */ {0,   4,   8,   12,  16,  20,  24,  28,  32,  36,  40,  44,  48,  52,  56,
                 64,  72,  80,  88,  96,  108, 120, 132, 144, 160, 176, 192, 212, 236, 260,
                 288, 320, 352, 384, 416, 448, 480, 512, 544, 576, 640, 768, 896, 1024},
    /* 88200 */ {0,   4,   8,   12,  16,  20,  24,  28,  32,  36,  40,  44,  48,  52,  56,  64,
                 72,  80,  88,  96,  108, 120, 132, 144, 160, 176, 192, 212, 236, 260, 288, 320,
                 352, 384, 416, 448, 480, 512, 544, 576, 608, 640, 704, 768, 896, 1024},
    /* 64000 */ {0,   4,   8,   12,  16,  20,  24,  28,  32,  36,  40,  44,  48,  52,
                 56,  64,  72,  80,  88,  96,  108, 120, 132, 144, 160, 176, 192, 212,
                 236, 260, 288, 320, 352, 384, 416, 448, 480, 512, 544, 576, 608, 640,
                 672, 704, 736, 768, 800, 832, 864, 896, 928, 960, 992, 1024},
    /* 48000 */ {0,   4,   8,   12,  16,  20,  24,  28,  32,  36,  40,  44,  48,  52,
                 56,  64,  72,  80,  88,  96,  108, 120, 132, 144, 160, 176, 192, 212,
                 236, 260, 288, 320, 352, 384, 416, 448, 480, 512, 544, 576, 608, 640,
                 672, 704, 736, 768, 800, 832, 864, 896, 928, 960, 992, 1024},
    /* 44100 */ {0,   4,   8,   12,  16,  20,  24,  28,  32,  36,  40,  44,  48,  52,
                 56,  64,  72,  80,  88,  96,  108, 120, 132, 144, 160, 176, 192, 212,
                 236, 260, 288, 320, 352, 384, 416, 448, 480, 512, 544, 576, 608, 640,
                 672, 704, 736, 768, 800, 832, 864, 896, 928, 960, 992, 1024},
    /* 32000 */ {0,   4,   8,   12,  16,  20,  24,  28,  32,  36,  40,  44,  48,  52,
                 56,  64,  72,  80,  88,  96,  108, 120, 132, 144, 160, 176, 192, 220,
                 248, 276, 304, 336, 368, 400, 432, 464, 496, 528, 560, 592, 624, 656,
                 688, 720, 752, 784, 816, 848, 880, 912, 944, 976, 1024},
    /* 24000 */ {0,   4,   8,   12,  16,  20,  24,  28,  32,  36,  40,  44,  48,  52,  56,
                 60,  64,  72,  80,  88,  100, 112, 124, 140, 156, 172, 192, 216, 240, 268,
                 300, 332, 368, 404, 444, 488, 536, 588, 644, 704, 768, 840, 920, 1024},
    /* 22050 */ {0,   4,   8,   12,  16,  20,  24,  28,  32,  36,  40,  44,  48,  52,  56,
                 60,  64,  72,  80,  88,  100, 112, 124, 140, 156, 172, 192, 216, 240, 268,
                 300, 332, 368, 404, 444, 488, 536, 588, 644, 704, 768, 840, 920, 1024},
    /* 16000 */ {0,   8,   16,  24,  32,  40,  48,  56,  64,  72,  80,  88,
                 100, 112, 124, 140, 156, 172, 192, 216, 240, 268, 300, 332,
                 368, 404, 444, 488, 536, 588, 644, 704, 768, 840, 920, 1024},
    /* 12000 */ {0,   8,   16,  24,  32,  40,  48,  56,  64,  72,  80,  88,
                 100, 112, 124, 140, 156, 172, 192, 216, 240, 268, 300, 332,
                 368, 404, 444, 488, 536, 588, 644, 704, 768, 840, 920, 1024},
    /* 11025 */ {0,   8,   16,  24,  32,  40,  48,  56,  64,  72,  80,  88,
                 100, 112, 124, 140, 156, 172, 192, 216, 240, 268, 300, 332,
                 368, 404, 444, 488, 536, 588, 644, 704, 768, 840, 920, 1024},
    /* 8000  */ {0,   12,  24,  36,  48,  60,  72,  84,  96,  108, 120, 132, 144, 156, 172, 188,
                 204, 220, 236, 252, 268, 288, 308, 328, 348, 372, 396, 420, 448, 476, 504, 536,
                 568, 600, 632, 664, 696, 728, 760, 792, 824, 856, 888, 920, 952, 984, 1024},
};

const int aac_num_sfb_short[AAC_NUM_SAMPLE_RATES] = {12, 12, 14, 14, 14, 14,
                                                     14, 14, 12, 12, 12, 12};
const int aac_sfb_offset_short[AAC_NUM_SAMPLE_RATES][AAC_MAX_SFB_SHORT + 1] = {
    /* 96000 */ {0, 4, 8, 12, 16, 20, 24, 28, 36, 44, 52, 60, 72, 84, 128},
    /* 88200 */ {0, 4, 8, 12, 16, 20, 24, 28, 36, 44, 52, 60, 72, 84, 128},
    /* 64000 */ {0, 4, 8, 12, 16, 20, 24, 28, 32, 36, 44, 52, 60, 72, 84, 128},
    /* 48000 */ {0, 4, 8, 12, 16, 20, 24, 28, 32, 36, 44, 52, 60, 72, 84, 128},
    /* 44100 */ {0, 4, 8, 12, 16, 20, 24, 28, 32, 36, 44, 52, 60, 72, 84, 128},
    /* 32000 */ {0, 4, 8, 12, 16, 20, 24, 28, 32, 36, 44, 52, 60, 72, 84, 128},
    /* 24000 */ {0, 4, 8, 12, 16, 20, 24, 28, 32, 36, 44, 52, 60, 72, 84, 128},
    /* 22050 */ {0, 4, 8, 12, 16, 20, 24, 28, 32, 36, 44, 52, 60, 72, 84, 128},
    /* 16000 */ {0, 4, 8, 12, 16, 20, 24, 28, 36, 44, 52, 60, 72, 128},
    /* 12000 */ {0, 4, 8, 12, 16, 20, 24, 28, 36, 44, 52, 60, 72, 128},
    /* 11025 */ {0, 4, 8, 12, 16, 20, 24, 28, 36, 44, 52, 60, 72, 128},
    /* 8000  */ {0, 4, 8, 12, 16, 20, 24, 28, 36, 44, 52, 60, 72, 128},
};

/* Huffman Codebook Metadata — ISO 14496-3 Table 4.45 */
const AacCodebookInfo aac_codebook_info[AAC_NUM_CODEBOOKS + 1] = {
    {0, 0, 0, 0, 0, 0},     {1, 2, 1, 1, 4, 1}, /* unsigned, 0..1 */
    {2, 2, 1, 0, 9, 1},                         /* signed, ±1 */
    {3, 2, 2, 1, 9, 2},                         /* unsigned, 0..2 */
    {4, 2, 2, 0, 25, 2},                        /* signed, ±2 */
    {5, 2, 4, 1, 25, 3},                        /* unsigned, 0..4 */
    {6, 2, 4, 0, 81, 3},                        /* signed, ±4, ISO prefix-free VLC */
    {7, 2, 7, 1, 64, 4},                        /* unsigned, 0..7, ISO prefix-free VLC */
    {8, 2, 7, 0, 225, 4},                       /* signed, ±7, uniform (to be replaced) */
    {9, 2, 12, 1, 169, 5},                      /* unsigned, 0..12, ISO prefix-free VLC */
    {10, 2, 12, 0, 625, 5},                     /* signed, ±12, uniform (to be replaced) */
    {11, 2, 16, 1, 289, 7},                     /* unsigned, 0..16, ISO prefix-free VLC */
};

/* Huffman VLC — codebooks 1-5 with valid prefix-free codes */
/* Codebook 1: unsigned, dim=2, max_val=1, 4 entries (x,y) ∈ {0,1}² */
static const uint32_t huff1_code[4] = {
    0x00000000, /* idx=0: 0    (1 bit)  → (0,0) */
    0x80000000, /* idx=1: 10   (2 bits) → (0,1) */
    0xC0000000, /* idx=2: 110  (3 bits) → (1,0) */
    0xE0000000, /* idx=3: 111  (3 bits) → (1,1) */
};
static const uint8_t huff1_len[4] = {1, 2, 3, 3};

/* Codebook 2: signed, dim=2, max_val=1, 9 entries (x,y) ∈ {-1,0,1}² */
static const uint32_t huff2_code[9] = {
    0x00000000, /* idx=0: 00      (2 bits) → (-1,-1) */
    0x40000000, /* idx=1: 01      (2 bits) → (-1, 0) */
    0x80000000, /* idx=2: 100     (3 bits) → (-1, 1) */
    0xA0000000, /* idx=3: 101     (3 bits) → ( 0,-1) */
    0xC0000000, /* idx=4: 110     (3 bits) → ( 0, 0) */
    0xE0000000, /* idx=5: 1110    (4 bits) → ( 0, 1) */
    0xF0000000, /* idx=6: 11110   (5 bits) → ( 1,-1) */
    0xF8000000, /* idx=7: 111110  (6 bits) → ( 1, 0) */
    0xFC000000, /* idx=8: 111111  (6 bits) → ( 1, 1) */
};
static const uint8_t huff2_len[9] = {2, 2, 3, 3, 3, 4, 5, 6, 6};

/* Codebook 3: unsigned, dim=2, max_val=2, 9 entries (x,y) ∈ {0,1,2}² */
static const uint32_t huff3_code[9] = {
    0x00000000, /* idx=0: 00      (2 bits) → (0,0) */
    0x40000000, /* idx=1: 01      (2 bits) → (0,1) */
    0x80000000, /* idx=2: 100     (3 bits) → (0,2) */
    0xA0000000, /* idx=3: 101     (3 bits) → (1,0) */
    0xC0000000, /* idx=4: 110     (3 bits) → (1,1) */
    0xE0000000, /* idx=5: 1110    (4 bits) → (1,2) */
    0xF0000000, /* idx=6: 11110   (5 bits) → (2,0) */
    0xF8000000, /* idx=7: 111110  (6 bits) → (2,1) */
    0xFC000000, /* idx=8: 111111  (6 bits) → (2,2) */
};
static const uint8_t huff3_len[9] = {2, 2, 3, 3, 3, 4, 5, 6, 6};

/* Codebook 4: signed, dim=2, max_val=2, 25 entries (x,y) ∈ {-2..2}² — uniform 5-bit */
static const uint32_t huff4_code[25] = {0x00000000, 0x08000000, 0x10000000, 0x18000000, 0x20000000,
                                        0x28000000, 0x30000000, 0x38000000, 0x40000000, 0x48000000,
                                        0x50000000, 0x58000000, 0x60000000, 0x68000000, 0x70000000,
                                        0x78000000, 0x80000000, 0x88000000, 0x90000000, 0x98000000,
                                        0xA0000000, 0xA8000000, 0xB0000000, 0xB8000000, 0xC0000000};
static const uint8_t huff4_len[25] = {5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5,
                                      5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5};

/* Codebook 5: unsigned, dim=2, max_val=4, 25 entries (x,y) ∈ {0..4}² — uniform 5-bit */
static const uint32_t huff5_code[25] = {0x00000000, 0x08000000, 0x10000000, 0x18000000, 0x20000000,
                                        0x28000000, 0x30000000, 0x38000000, 0x40000000, 0x48000000,
                                        0x50000000, 0x58000000, 0x60000000, 0x68000000, 0x70000000,
                                        0x78000000, 0x80000000, 0x88000000, 0x90000000, 0x98000000,
                                        0xA0000000, 0xA8000000, 0xB0000000, 0xB8000000, 0xC0000000};
static const uint8_t huff5_len[25] = {5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5,
                                      5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5};

/* Codebooks 6-11: generated uniform VLC tables */
#include "huff_tables_6_11.inc"

const int aac_huff_count[AAC_NUM_CODEBOOKS + 1] = {0, 4, 9, 9, 25, 25, 81, 64, 225, 169, 625, 289};
static const uint32_t* huff_code_ptrs[AAC_NUM_CODEBOOKS + 1] = {
    nullptr,    huff1_code, huff2_code, huff3_code, huff4_code,  huff5_code,
    huff6_code, huff7_code, huff8_code, huff9_code, huff10_code, huff11_code};
static const uint8_t* huff_len_ptrs[AAC_NUM_CODEBOOKS + 1] = {
    nullptr,   huff1_len, huff2_len, huff3_len, huff4_len,  huff5_len,
    huff6_len, huff7_len, huff8_len, huff9_len, huff10_len, huff11_len};
const uint32_t* aac_huff_code[AAC_NUM_CODEBOOKS + 1];
const uint8_t* aac_huff_len[AAC_NUM_CODEBOOKS + 1];

/* Window Functions */
void aac_sine_window(float* out, int n) {
  for (int i = 0; i < n; i++) {
    out[i] = sinf((float)M_PI * (i + 0.5f) / (float)n);
  }
}

void aac_kbd_window(float* out, int n, float alpha) {
  auto i0 = [](float x) -> float {
    float sum = 1.0f, term = 1.0f;
    for (int k = 1; k <= 30; k++) {
      term *= (x / (2.0f * k)) * (x / (2.0f * k));
      sum += term;
      if (term < 1e-12f) {
        break;
      }
    }
    return sum;
  };
  float a = alpha * (float)M_PI / (float)n;
  auto* w = new float[n];
  float sum = 0.0f;
  for (int i = 0; i < n; i++) {
    w[i] = i0(a * sqrtf(1.0f - powf((i - n / 2.0f + 0.5f) / (n / 2.0f), 2)));
    sum += w[i];
  }
  for (int i = 0; i < n; i++) {
    w[i] = sqrtf(w[i] / sum);
  }
  sum = 0.0f;
  for (int i = 0; i < n; i++) {
    sum += w[i];
    out[i] = sqrtf(sum);
  }
  delete[] w;
}

/* TNS, Channel Config */
const int aac_tns_max_bands_long[AAC_NUM_SAMPLE_RATES] = {31, 31, 34, 40, 42, 40,
                                                          37, 37, 31, 31, 31, 29};
const int aac_tns_max_bands_short[AAC_NUM_SAMPLE_RATES] = {9,  9,  10, 14, 14, 14,
                                                           14, 14, 12, 12, 12, 11};
const int aac_tns_max_order_long = 20;
const int aac_tns_max_order_short = 7;
const AacChannelConfig aac_channel_config[8] = {{0, 0, 0, 0}, {1, 0, 1, 0}, {2, 1, 0, 0},
                                                {3, 1, 1, 0}, {4, 1, 1, 0}, {5, 2, 1, 0},
                                                {6, 2, 1, 1}, {8, 3, 1, 1}};

/* SBR tables — placeholder zero-initialized, to be populated in Phase 8 */
const int aac_sbr_freq_band_table_lo[AAC_NUM_SAMPLE_RATES][AAC_SBR_NUM_FREQ_COEFFS] = {{0}};
const int aac_sbr_freq_band_table_hi[AAC_NUM_SAMPLE_RATES][AAC_SBR_NUM_FREQ_COEFFS] = {{0}};
const float aac_sbr_qmf_window[AAC_SBR_QMF_FILTER_LENGTH] = {0};

void aac_tables_init(void) {
  for (int cb = 1; cb <= AAC_NUM_CODEBOOKS; cb++) {
    aac_huff_code[cb] = huff_code_ptrs[cb];
    aac_huff_len[cb] = huff_len_ptrs[cb];
  }
}

namespace {
struct TablesInit {
  TablesInit() { aac_tables_init(); }
} g_tables_init;  // NOLINT(bugprone-throwing-static-initialization)
}  // namespace
