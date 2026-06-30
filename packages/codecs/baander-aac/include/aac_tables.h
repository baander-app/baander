#ifndef BAANDER_AAC_TABLES_H
#define BAANDER_AAC_TABLES_H

#include <cstdint>

#ifdef __cplusplus
extern "C" {
#endif

/* Constants */
#define AAC_MAX_CHANNELS 2
#define AAC_FRAME_SIZE_LONG 1024
#define AAC_FRAME_SIZE_SHORT 128
#define AAC_NUM_WINDOWS_SHORT 8
#define AAC_MAX_SFB_LONG 54
#define AAC_MAX_SFB_SHORT 15
#define AAC_NUM_SAMPLE_RATES 12
#define AAC_NUM_CODEBOOKS 11
#define AAC_BITS_PER_FRAME_LONG 6144
#define AAC_BITS_PER_FRAME_SHORT 768

/* Sample Rate Table */
extern const int aac_sample_rates[AAC_NUM_SAMPLE_RATES];
extern const int aac_num_sample_rates;

/* Scalefactor Band Tables — ISO 14496-3 Tables 4.53/4.54 */
extern const int aac_num_sfb_long[AAC_NUM_SAMPLE_RATES];
extern const int aac_sfb_offset_long[AAC_NUM_SAMPLE_RATES][AAC_MAX_SFB_LONG + 1];
extern const int aac_num_sfb_short[AAC_NUM_SAMPLE_RATES];
extern const int aac_sfb_offset_short[AAC_NUM_SAMPLE_RATES][AAC_MAX_SFB_SHORT + 1];

/* Huffman Codebook Metadata */
using AacCodebookInfo = struct AacCodebookInfo_ {
  int codebook, dim, max_val, is_unsigned, num_vals, max_bits;
};
extern const AacCodebookInfo aac_codebook_info[AAC_NUM_CODEBOOKS + 1];

/* Huffman VLC Tables */
extern const int aac_huff_count[AAC_NUM_CODEBOOKS + 1];
extern const uint32_t* aac_huff_code[AAC_NUM_CODEBOOKS + 1];
extern const uint8_t* aac_huff_len[AAC_NUM_CODEBOOKS + 1];

/* Window Functions */
void aac_sine_window(float* out, int n);
void aac_kbd_window(float* out, int n, float alpha);
#define AAC_KBD_ALPHA_LONG 4.0f
#define AAC_KBD_ALPHA_SHORT 6.0f

/* TNS Parameters */
extern const int aac_tns_max_bands_long[AAC_NUM_SAMPLE_RATES];
extern const int aac_tns_max_bands_short[AAC_NUM_SAMPLE_RATES];
extern const int aac_tns_max_order_long;
extern const int aac_tns_max_order_short;

/* Channel Configuration */
using AacChannelConfig = struct AacChannelConfig_ {
  int num_channels, has_cpe, has_sce, has_lfe;
};
extern const AacChannelConfig aac_channel_config[8];

/* SBR Tables */
#define AAC_SBR_NUM_FREQ_COEFFS 64
#define AAC_SBR_NUM_TIME_SLOTS 16
#define AAC_SBR_QMF_BANDS 64
#define AAC_SBR_QMF_FILTER_LENGTH 640

extern const int aac_sbr_freq_band_table_lo[AAC_NUM_SAMPLE_RATES][AAC_SBR_NUM_FREQ_COEFFS];
extern const int aac_sbr_freq_band_table_hi[AAC_NUM_SAMPLE_RATES][AAC_SBR_NUM_FREQ_COEFFS];
extern const float aac_sbr_qmf_window[AAC_SBR_QMF_FILTER_LENGTH];

/* Table auto-init */
void aac_tables_init(void);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_TABLES_H */
