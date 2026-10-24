#ifndef BAANDER_AAC_TABLES_H
#define BAANDER_AAC_TABLES_H

#include <cstdint>

#ifdef __cplusplus
extern "C" {
#endif

/* Constants */
#define AAC_MAX_CHANNELS 8
/* Frame lengths: 1024 lines per long window (128 per short window) or,
 * with the frameLengthFlag of ISO/IEC 14496-3 1.6.2.1, 960 (120).  The
 * 1024 values size every buffer; a 960 stream uses the lower part. */
#define AAC_FRAME_SIZE_LONG 1024
#define AAC_FRAME_SIZE_SHORT 128
#define AAC_FRAME_SIZE_LONG_960 960
#define AAC_FRAME_SIZE_SHORT_120 120
/* The low-delay object types (AAC-LD, AAC-ELD) code 512 or 480 lines per
 * frame in one long window. */
#define AAC_FRAME_SIZE_LD 512
#define AAC_FRAME_SIZE_LD_480 480
#define AAC_NUM_WINDOWS_SHORT 8
#define AAC_MAX_SFB_LONG 51
#define AAC_MAX_SFB_SHORT 15
#define AAC_MAX_WINDOW_GROUPS 8
#define AAC_NUM_SAMPLE_RATES 12
#define AAC_NUM_CODEBOOKS 11
/* Largest raw_data_block per channel (4.5.3.1): 6144 bits per 1024
 * samples, scaled with the frame length (5760 bits for 960). */
#define AAC_BITS_PER_FRAME_LONG 6144
#define AAC_BITS_PER_FRAME_SHORT 768
static inline int aac_max_bits_per_channel(int frame_length) {
  return AAC_BITS_PER_FRAME_LONG * frame_length / AAC_FRAME_SIZE_LONG;
}

/* Sample Rate Table */
extern const int aac_sample_rates[AAC_NUM_SAMPLE_RATES];
extern const int aac_num_sample_rates;

/* Scalefactor band tables, ISO/IEC 14496-3 Tables 4.128-4.140: the 1024
 * and 128 line transforms, and the 960 and 120 line columns. */
extern const int aac_num_sfb_long[AAC_NUM_SAMPLE_RATES];
extern const int aac_sfb_offset_long[AAC_NUM_SAMPLE_RATES][AAC_MAX_SFB_LONG + 1];
extern const int aac_num_sfb_short[AAC_NUM_SAMPLE_RATES];
extern const int aac_sfb_offset_short[AAC_NUM_SAMPLE_RATES][AAC_MAX_SFB_SHORT + 1];
extern const int aac_num_sfb_long_960[AAC_NUM_SAMPLE_RATES];
extern const int aac_sfb_offset_long_960[AAC_NUM_SAMPLE_RATES][AAC_MAX_SFB_LONG + 1];
extern const int aac_num_sfb_short_960[AAC_NUM_SAMPLE_RATES];
extern const int aac_sfb_offset_short_960[AAC_NUM_SAMPLE_RATES][AAC_MAX_SFB_SHORT + 1];

/* Scalefactor bands of the low-delay frame lengths, ISO/IEC 14496-3
 * Tables 4.141-4.150 (512 and 480 lines); 0 bands for the rates without
 * a table. */
extern const int aac_num_sfb_512[AAC_NUM_SAMPLE_RATES];
extern const int aac_sfb_offset_512[AAC_NUM_SAMPLE_RATES][AAC_MAX_SFB_LONG + 1];
extern const int aac_num_sfb_480[AAC_NUM_SAMPLE_RATES];
extern const int aac_sfb_offset_480[AAC_NUM_SAMPLE_RATES][AAC_MAX_SFB_LONG + 1];

/* Whether a frame length is one of the low-delay ones (512 or 480). */
static inline int aac_is_low_delay_frame(int frame_length) {
  return frame_length == AAC_FRAME_SIZE_LD || frame_length == AAC_FRAME_SIZE_LD_480;
}
/* Whether the low-delay frame lengths have band tables for a rate: 48,
 * 44.1, 32, 24 and 22.05 kHz. */
int aac_low_delay_rate_supported(int rate_index);

/* The band count and offsets of a frame length (1024, 960, 512 or 480) and
 * window type (is_short: the 128 or 120 line windows).  The low-delay
 * lengths have no short windows and answer such a query with the 128-line
 * table; at a rate without low-delay tables they answer with the nearest
 * rate's. */
int aac_num_sfb(int frame_length, int rate_index, int is_short);
const int* aac_sfb_offset(int frame_length, int rate_index, int is_short);

/* Huffman Codebook Metadata */
using AacCodebookInfo = struct AacCodebookInfo_ {
  int codebook, dim, max_val, is_unsigned, num_vals, max_bits;
};
extern const AacCodebookInfo aac_codebook_info[AAC_NUM_CODEBOOKS + 1];

/* Huffman VLC Tables */
extern const int aac_huff_count[AAC_NUM_CODEBOOKS + 1];
extern const uint32_t* aac_huff_code[AAC_NUM_CODEBOOKS + 1];
extern const uint8_t* aac_huff_len[AAC_NUM_CODEBOOKS + 1];

/* ISO 14496-3 scalefactor Huffman table (Table 4.47).  Index 60 codes a
 * zero DPCM difference; indices 0..120 represent differences -60..+60. */
#define AAC_SCALE_DIFF_ZERO 60
extern const uint32_t aac_scalefactor_code[121];
extern const uint8_t aac_scalefactor_bits[121];

/* Window Functions */
void aac_sine_window(float* out, int n);
void aac_kbd_window(float* out, int n, float alpha);
#define AAC_KBD_ALPHA_LONG 4.0f
#define AAC_KBD_ALPHA_SHORT 6.0f

/* Rising window halves, filled by aac_tables_init(): index 0 is the sine
 * window, index 1 the KBD window (the window_shape bit).  The falling half
 * is the mirror image.  The 960-frame windows are 960 and 120 long.  The
 * AAC-LD windows (512 and 480 long) have the sine window at index 0 and
 * the low-overlap window of ISO/IEC 14496-3 4.6.11.3.2 at index 1. */
extern float aac_window_long[2][AAC_FRAME_SIZE_LONG];
extern float aac_window_short[2][AAC_FRAME_SIZE_SHORT];
extern float aac_window_long_960[2][AAC_FRAME_SIZE_LONG_960];
extern float aac_window_short_120[2][AAC_FRAME_SIZE_SHORT_120];
extern float aac_window_ld_512[2][AAC_FRAME_SIZE_LD];
extern float aac_window_ld_480[2][AAC_FRAME_SIZE_LD_480];
/* The rising half for a frame length (1024, 960, 512 or 480) and window
 * shape.  The low-delay lengths have no short windows; the short window
 * query answers with the 128-line one, which they never use. */
const float* aac_window_long_for(int frame_length, int shape);
const float* aac_window_short_for(int frame_length, int shape);

/* |q|^(4/3) for every magnitude the escape codebook can carry. */
#define AAC_POW43_SIZE 8192
extern float aac_pow43[AAC_POW43_SIZE];

/* TNS Parameters (Table 4.139).  TNS_MAX_BANDS is the same for the 1024
 * and 960 frame lengths; the decoder clips it to the band count. */
extern const int aac_tns_max_bands_long[AAC_NUM_SAMPLE_RATES];
extern const int aac_tns_max_bands_short[AAC_NUM_SAMPLE_RATES];
extern const int aac_tns_max_order_long;
extern const int aac_tns_max_order_short;
/* TNS_MAX_BANDS of the low-delay frame lengths (Table 4.139). */
extern const int aac_tns_max_bands_512[AAC_NUM_SAMPLE_RATES];
extern const int aac_tns_max_bands_480[AAC_NUM_SAMPLE_RATES];
/* TNS_MAX_BANDS for a frame length and window type. */
int aac_tns_max_bands(int frame_length, int rate_index, int is_short);

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

extern const float aac_sbr_qmf_window[AAC_SBR_QMF_FILTER_LENGTH];

/* Table auto-init */
void aac_tables_init(void);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_TABLES_H */
