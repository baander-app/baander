#ifndef BAANDER_AAC_SBR_H
#define BAANDER_AAC_SBR_H

#include <cstdint>

#include "aac_tables.h"
#include "bitstream.h"
#include "drc.h"
#include "ps.h"
#include "qmf.h"
#include "usac.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Spectral band replication decoder (ISO/IEC 14496-3 4.6.18) for one SCE
 * or CPE: parses the sbr_extension_data of the element's FIL element,
 * and turns the element's 1024 (or 960) core samples per channel into
 * 2048 (1920) samples at twice the core rate.  The design follows the standard and the
 * structure of FFmpeg's decoder: 32-band QMF analysis of the core, the
 * LPC-based high-frequency generator with inverse filtering per patch,
 * envelope and noise-floor adjustment with gain limiting and sinusoid
 * insertion, and 64-band synthesis.  An element whose stream carries no
 * SBR data is upsampled through the same filterbanks so that every
 * channel of a frame keeps the same delay.  A single channel element
 * that carries parametric stereo data can be asked for a stereo pair:
 * the PS decoder then builds the right channel from the left one. */

/* Slots of a 1024-sample frame; a 960-sample frame has 15 envelope time
 * slots and 30 QMF slots, and every buffer is sized for the larger. */
#define AAC_SBR_SLOTS 16          /* envelope time slots per frame */
#define AAC_SBR_QMF_SLOTS 32      /* QMF slots per frame */
#define AAC_SBR_HF_HISTORY 8      /* QMF slots of the previous frame kept for the generator */
#define AAC_SBR_ADJ_OFFSET 2      /* slots of lead-in in the adjuster's buffers */
#define AAC_SBR_MAX_BANDS 48      /* high-resolution envelope bands */
#define AAC_SBR_MAX_ENV 8
#define AAC_SBR_MAX_NOISE_BANDS 5
#define AAC_SBR_MAX_PATCHES 6
#define AAC_SBR_MAX_LIMITER 30

/* The frequency band tables of one SBR configuration (4.6.18.3), built
 * from the header fields and the SBR sample rate. */
using AacSbrFreq = struct AacSbrFreq_ {
  int sample_rate; /* the SBR rate: the rate of the 64-band synthesis output */
  int table_rate;  /* the rate the start and stop frequency indices are read for: 0 for
                      sample_rate itself (AAC and the USAC 2:1 and 8:3 ratios); the USAC
                      4:1 ratio reads them for twice its core rate */
  int start_freq, stop_freq, xover_band, freq_scale, alter_scale, noise_bands;
  int limiter_bands;
  int k[3];  /* k0, k1, k2 */
  int kx, m; /* first SBR band and the number of bands */
  int n_master;
  uint16_t f_master[49];
  int n[2]; /* bands at low and high resolution */
  uint16_t f_tablelow[25];
  uint16_t f_tablehigh[49];
  int n_q;
  uint16_t f_tablenoise[AAC_SBR_MAX_NOISE_BANDS + 1];
  int n_lim;
  uint16_t f_tablelim[AAC_SBR_MAX_LIMITER];
  int num_patches;
  int patch_num_subbands[AAC_SBR_MAX_PATCHES];
  int patch_start_subband[AAC_SBR_MAX_PATCHES];
};

/* Builds every table from the header fields; returns 0, AAC_ERR_DECODE for
 * a combination the standard rules out or AAC_ERR_UNSUPPORTED for a rate
 * without offset table. */
int aac_sbr_freq_tables(AacSbrFreq* f);

/* Rebuilds the limiter table alone after limiter_bands changed. */
void aac_sbr_freq_limiter(AacSbrFreq* f);

/* Delta coding in time (4.6.18.3.4): the band of the previous envelope
 * that band j of an envelope of resolution `res` is coded against when
 * the previous envelope has resolution `prev_res`; `odd` is whether the
 * high-resolution band count is odd. */
int aac_sbr_prev_band(int j, int res, int prev_res, int odd);

/* The fields up to `analysis` are what the bitstream of a frame sets (or
 * a header change derives); the decoder snapshots that prefix before
 * parsing an extension so that a damaged one leaves the previous frame's
 * parameters for concealment. */
using AacSbrChannel = struct AacSbrChannel_ {
  /* bitstream data of the frame */
  int frame_class;
  int add_harmonic_flag;
  int num_env;
  int freq_res[AAC_SBR_MAX_ENV + 1];
  int num_noise;
  int df_env[AAC_SBR_MAX_ENV + 1];
  int df_noise[2];
  int invf_mode[2][AAC_SBR_MAX_NOISE_BANDS]; /* [0] this frame, [1] previous */
  int add_harmonic[AAC_SBR_MAX_BANDS];
  int amp_res;
  int e_a[2]; /* envelope of the transient: [0] previous frame, [1] this one, or -1 */
  int env_facs_q[AAC_SBR_MAX_ENV + 1][AAC_SBR_MAX_BANDS]; /* [0] the last envelope of the previous frame */
  int noise_facs_q[3][AAC_SBR_MAX_NOISE_BANDS];
  int t_env[AAC_SBR_MAX_ENV + 1];
  int t_env_num_env_old;
  int t_q[3];
  unsigned f_indexnoise;
  /* state */
  AacQmfAnalysis analysis;
  AacQmfSynthesis synthesis;
  float bw_array[AAC_SBR_MAX_NOISE_BANDS];
  float W[2][AAC_SBR_QMF_SLOTS][32][2]; /* analysis output of this and the previous frame */
  int Ypos;
  float Y[2][38][64][2]; /* adjusted high band of this and the previous frame */
  float g_temp[42][AAC_SBR_MAX_BANDS];
  float q_temp[42][AAC_SBR_MAX_BANDS];
  uint8_t s_indexmapped[AAC_SBR_MAX_ENV + 1][AAC_SBR_MAX_BANDS];
  float env_facs[AAC_SBR_MAX_ENV + 1][AAC_SBR_MAX_BANDS];
  float noise_facs[3][AAC_SBR_MAX_NOISE_BANDS];
  unsigned f_indexsine;
};

/* ── Enhanced SBR of USAC (ISO/IEC 23003-3 7.5): sizes and state ─────
 * Every ratio has 16 envelope time slots per frame; a time slot is RATE
 * QMF slots (2 at 2:1 and 8:3, 4 at 4:1).  The QMF-domain buffers keep
 * the frame behind a history of (4 + D) time slots, D being 0 or the
 * delay the harmonic transposer adds: the frame delivered to the
 * synthesis starts 3 (+ D) time slots before the core frame, as the AAC
 * decoder's does, which is where the envelope grid places it. */
#define AAC_SBR_USAC_TIME_SLOTS 16
#define AAC_SBR_USAC_MAX_RATE 4
#define AAC_SBR_USAC_MAX_SLOTS 64 /* QMF slots per frame at 4:1 */
#define AAC_SBR_USAC_HBE_DELAY 8  /* time slots the harmonic transposer delays the frame by */
#define AAC_SBR_USAC_MAX_HISTORY ((4 + AAC_SBR_USAC_HBE_DELAY) * AAC_SBR_USAC_MAX_RATE)
#define AAC_SBR_USAC_COLS (AAC_SBR_USAC_MAX_HISTORY + AAC_SBR_USAC_MAX_SLOTS)
#define AAC_SBR_USAC_Y_SLOTS (AAC_SBR_USAC_MAX_RATE * (AAC_SBR_USAC_TIME_SLOTS + 3))
#define AAC_SBR_USAC_MAX_ENV 16 /* with PVC every time slot is an envelope */
#define AAC_SBR_USAC_HBE_N 512  /* transposer analysis window and DFT, core samples (384 at 8:3) */
#define AAC_SBR_USAC_HBE_NS 2048 /* synthesis DFT at the output rate, 4 x 512 */
#define AAC_SBR_USAC_HBE_HOPS 8  /* analysis hops per window */
#define AAC_SBR_USAC_PVC_NB_LOW 3
#define AAC_SBR_USAC_PVC_NB_HIGH 8

/* Complex FFT of the transposer: n = 2^k or 3 x 2^k up to 2048. */
using AacSbrUsacFft = struct AacSbrUsacFft_ {
  int n, m, p; /* n = m p, m the odd factor (1 or 3) */
  float tw_re[AAC_SBR_USAC_HBE_NS], tw_im[AAC_SBR_USAC_HBE_NS];
  uint16_t rev[AAC_SBR_USAC_HBE_NS];
};

/* The USAC-only frame data of one channel. */
using AacSbrUsacChannelData = struct AacSbrUsacChannelData_ {
  /* the channel's harmonic patching data (shared by a coupled pair) */
  int patching_mode;   /* 1: LPC patching, 0: harmonic */
  int oversampling;
  int pitch_in_bins;
  int temp_shape[AAC_SBR_MAX_ENV + 1];      /* bs_temp_shape per envelope (inter-TES) */
  int temp_shape_mode[AAC_SBR_MAX_ENV + 1]; /* bs_inter_temp_shape_mode */
  /* PVC (7.5.6): the frame's pvcID per time slot and the smoothing mode */
  int pvc_active;
  int pvc_id[AAC_SBR_USAC_TIME_SLOTS];
  int pvc_ns_mode;
  int pvc_noise_position;
  float pvc_env[AAC_SBR_USAC_TIME_SLOTS][AAC_SBR_MAX_BANDS]; /* predicted energies per QMF band */
  int pvc_id_prev;
};

/* The USAC-only signal state of one channel. */
using AacSbrUsacChannel = struct AacSbrUsacChannel_ {
  float W_low[AAC_SBR_USAC_COLS][32][2]; /* core analysis, history then frame */
  float W_hbe[AAC_SBR_USAC_COLS][64][2]; /* analysis of the transposed signal */
  int Ypos;
  float Y[2][AAC_SBR_USAC_Y_SLOTS][64][2];
  float g_temp[AAC_SBR_USAC_Y_SLOTS + 4][AAC_SBR_MAX_BANDS];
  float q_temp[AAC_SBR_USAC_Y_SLOTS + 4][AAC_SBR_MAX_BANDS];
  float bw_array[AAC_SBR_MAX_NOISE_BANDS];
  unsigned f_indexnoise, f_indexsine;
  /* harmonic transposer: core input history and the overlap-add output */
  float hbe_in[AAC_USAC_MAX_CORE_FRAME + AAC_SBR_USAC_HBE_N];
  float hbe_out[AAC_USAC_MAX_OUTPUT_FRAME + AAC_SBR_USAC_HBE_NS];
  AacQmfAnalysis hbe_analysis;
  /* PVC: smoothed low-band energies (dB) of the previous slots */
  float pvc_low_hist[AAC_SBR_USAC_TIME_SLOTS][AAC_SBR_USAC_PVC_NB_LOW];
  int pvc_hist_len;
  /* inter-TES: per-slot gains of the frame */
  float tes_gain[AAC_SBR_USAC_Y_SLOTS];
};

using AacSbrUsac = struct AacSbrUsac_ {
  int active;          /* the state decodes UsacSbrData() */
  int ratio;           /* AAC_USAC_SBR_2_1, _8_3 or _4_1 */
  int rate;            /* QMF slots per time slot */
  int slots_of_frame;  /* QMF slots per frame: 32 or 64 */
  int core_frame;      /* 768 or 1024 */
  int output_frame;    /* 2048 or 4096 */
  int analysis_bands;  /* 32, 24 or 16 */
  int elem_type;       /* the element's type as the caller names it */
  int history;         /* history columns in front of the frame */
  int offset;          /* column of the frame's first output slot */
  int mps_low_ahead;   /* MPS 212 of stereoConfigIndex 1 or 2 takes the output frame:
                          its three lowest bands go six slots ahead (see x_gen) */
  int harmonic_sbr, inter_tes, pvc;
  int hbe_n, hbe_ns;   /* transposer DFT sizes */
  int max_stretch;     /* highest transposition order */
  /* configuration default header */
  int dflt_start_freq, dflt_stop_freq, dflt_freq_scale, dflt_alter_scale, dflt_noise_bands;
  int dflt_limiter_bands, dflt_limiter_gains, dflt_interpol_freq, dflt_smoothing_mode;
  /* SbrInfo of the frame */
  int preprocessing;
  int pvc_mode;
  int frames;          /* frames processed since the header */
  AacSbrUsacChannelData data[2];
  AacSbrUsacChannel ch[2];
  /* scratch */
  float X_low[32][AAC_SBR_USAC_COLS][2];
  float X_high[64][AAC_SBR_USAC_COLS][2];
  float alpha0[64][2], alpha1[64][2];
  float e_origmapped[AAC_SBR_USAC_MAX_ENV][AAC_SBR_MAX_BANDS];
  float q_mapped[AAC_SBR_USAC_MAX_ENV][AAC_SBR_MAX_BANDS];
  uint8_t s_mapped[AAC_SBR_USAC_MAX_ENV][AAC_SBR_MAX_BANDS];
  uint8_t s_indexmapped[AAC_SBR_USAC_MAX_ENV + 1][AAC_SBR_MAX_BANDS];
  float e_curr[AAC_SBR_USAC_MAX_ENV][AAC_SBR_MAX_BANDS];
  float q_m[AAC_SBR_USAC_MAX_ENV][AAC_SBR_MAX_BANDS];
  float s_m[AAC_SBR_USAC_MAX_ENV][AAC_SBR_MAX_BANDS];
  float gain[AAC_SBR_USAC_MAX_ENV][AAC_SBR_MAX_BANDS];
  float X[2][AAC_SBR_USAC_MAX_SLOTS][64]; /* [re/im][slot][band] */
  float hbe_re[AAC_SBR_USAC_HBE_NS], hbe_im[AAC_SBR_USAC_HBE_NS];
  float hbe_re2[AAC_SBR_USAC_HBE_NS], hbe_im2[AAC_SBR_USAC_HBE_NS];
  float hbe_win_a[AAC_SBR_USAC_HBE_N], hbe_win_s[AAC_SBR_USAC_HBE_NS];
  AacSbrUsacFft fft_a, fft_s;
  AacUsacQmfFrame frame; /* scratch of aac_sbr_usac_apply */
};

using AacSbrState = struct AacSbrState_ {
  int sample_rate; /* the SBR rate, twice the core rate */
  int slots;       /* QMF slots per frame: 32, or 30 for 960-sample frames */
  int time_slots;  /* envelope time slots per frame: 16 or 15 */
  int start;       /* a header has been seen: the high band is generated */
  int ready_for_dequant;
  int elem_type;
  int reset;
  /* header: spectrum parameters, whose change resets the tables */
  int start_freq, stop_freq, xover_band, freq_scale, alter_scale, noise_bands;
  int amp_res_header;
  int limiter_bands, limiter_gains, interpol_freq, smoothing_mode;
  int coupling;
  int k[3];  /* k0, k1, k2 */
  int kx[2]; /* [0] previous frame, [1] this frame */
  int m[2];
  int kx_and_m_pushed;
  int n_master;
  int n[2]; /* bands at low and high resolution */
  int n_q;
  int n_lim;
  uint16_t f_master[49];
  uint16_t f_tablelow[25];
  uint16_t f_tablehigh[49];
  uint16_t f_tablenoise[AAC_SBR_MAX_NOISE_BANDS + 1];
  uint16_t f_tablelim[AAC_SBR_MAX_LIMITER];
  int num_patches;
  int patch_num_subbands[AAC_SBR_MAX_PATCHES];
  int patch_start_subband[AAC_SBR_MAX_PATCHES];
  int ps_present; /* the frame carried parametric stereo data */
  int unreliable; /* a frame's data was lost and no frequency-coded envelope has anchored the
                     delta-time chain since: out-of-range values are clamped, not refused */
  /* Everything above is set by the bitstream (header, tables, frame
   * flags) and snapshotted around the parsing of an extension; the
   * channel data and the signal buffers follow. */
  AacSbrChannel data[2];
  float X_low[32][40][2];
  float X_high[64][40][2];
  float X[2][2][38][64]; /* [channel][re/im][slot][band] */
  float alpha0[64][2];
  float alpha1[64][2];
  float e_origmapped[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  float q_mapped[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  uint8_t s_mapped[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  float e_curr[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  float q_m[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  float s_m[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  float gain[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  AacPsState ps; /* parametric stereo of a single channel element */
  AacDrcQmf drc; /* dynamic range control gains per QMF band, set per frame by the decoder */
  AacSbrUsac usac; /* the enhanced SBR of USAC, unused by the AAC decoder */
};

/* Prepares the state for a core sample rate and frame length (1024 or
 * 960 core samples); the SBR rate is twice the core rate. */
void aac_sbr_init(AacSbrState* s, int core_sample_rate, int frame_length);

/* Places the element in pure upsampling mode until the next header. */
void aac_sbr_turnoff(AacSbrState* s);

/* Parses sbr_extension_data() (Table 4.55) of `count` payload bytes after
 * the extension_type field, with `crc` set for extension type 14.  The
 * reader is left just past the payload.  On a damaged payload the
 * parameters of the previous frame are restored and AAC_ERR_DECODE is
 * returned: the element then has no data for this frame, which
 * aac_sbr_apply answers by switching to upsampling unless
 * aac_sbr_conceal is called first. */
int aac_sbr_decode_extension(AacSbrState* s, AacBitReader* r, int crc, int count, int elem_type);

/* Supplies the frame data of a lost or damaged extension while SBR is
 * active: one envelope over the whole frame at the previous frame's last
 * envelope less 3 dB (each further concealed frame takes another 3 dB
 * off), the previous noise floor, inverse filtering modes and frequency
 * tables, and the previous parametric stereo parameters.  Without an
 * active header nothing changes (the element is upsampled only). */
void aac_sbr_conceal(AacSbrState* s);

/* Notes that a frame of the element was lost without concealment (the
 * decoder returned an error): the next frames' delta-time coded
 * envelopes, noise floors and PS parameters refer to data the decoder
 * never had and are clamped into range instead of refused. */
void aac_sbr_mark_lost(AacSbrState* s);

/* Upsamples and, when SBR data is active, reconstructs the high band of
 * the element's channels in place: each buffer holds one frame of core
 * samples (1024 or 960) at full scale 1.0 on entry and twice as many
 * output samples on return.  For a CPE
 * `right` is the second channel; for an SCE a non-NULL `right` requests
 * parametric stereo output, which is a copy of the left channel while no
 * PS data is active. */
void aac_sbr_apply(AacSbrState* s, int elem_type, float* left, float* right);

/* ── Enhanced SBR for USAC (ISO/IEC 23003-3 7.5) ───────────────────
 * The same state decodes UsacSbrData(): the sbr_info and sbr_header of
 * the configuration's default header and of the frame, the sbr_data with
 * the harmonic transposer, PVC envelopes and inter-TES, at the ratios
 * 2:1 (32-band analysis), 8:3 (24-band analysis of a 768 frame) and 4:1
 * (16-band analysis); the synthesis is always 64 bands. */
struct AacUsacSbrConfig_;

/* Prepares the state for a USAC element: `ratio` is AAC_USAC_SBR_2_1,
 * _8_3 or _4_1, `frame_length` the core frame (768 or 1024), `cfg` the
 * element's SbrConfig with the default header, `stereo_config_index`
 * the element's (0 for an SCE). */
void aac_sbr_usac_init(AacSbrState* s, int core_sample_rate, int frame_length, int ratio,
                       const struct AacUsacSbrConfig_* cfg, int stereo_config_index);

/* Parses UsacSbrData() of an SCE or CPE (`elem_type` AAC_ELEM_SCE or
 * AAC_ELEM_CPE; for a CPE with MPS 212 the data is that of the single
 * downmix channel, `nr_channels` 1).  `indep` is usacIndependencyFlag.
 * Returns AAC_OK or AAC_ERR_DECODE (previous parameters kept). */
int aac_sbr_usac_decode(AacSbrState* s, AacBitReader* r, int elem_type, int nr_channels,
                        int indep);

/* Reconstructs the element in place: each buffer holds frame_length core
 * samples at full scale 1.0 on entry and output_frame_length samples on
 * return.  `right` is NULL for one channel. */
void aac_sbr_usac_apply(AacSbrState* s, float* left, float* right);

/* The two halves of aac_sbr_usac_apply for the MPS 212 coupling: the
 * first takes the core samples of channel `ch` (0 or 1) and leaves the
 * reconstructed 64-band QMF frame; the second synthesises a QMF frame
 * into output_frame_length samples with channel `ch`'s synthesis
 * history.  With stereoConfigIndex 1 or 2 the frame carries QMF bands
 * 0..2 six slots ahead of the others, the delay of the MPS 212 hybrid
 * filter on them, so that the MPS output sits at the frame's timing. */
void aac_sbr_usac_apply_to_qmf(AacSbrState* s, int ch, const float* core, struct AacUsacQmfFrame_* out);
void aac_sbr_usac_synthesize(AacSbrState* s, int ch, const struct AacUsacQmfFrame_* in, float* out);

/* The order of stereoConfigIndex 3, where MPS 212 runs on the core band
 * of the downmix and the two-channel SBR data reconstructs each output:
 * the first analyses the downmix frame into its QMF low band (the high
 * band zero) with the frame's housekeeping; the second takes the MPS
 * output of channel `ch` as that channel's low band, generates its high
 * band from channel `ch`'s SBR data and leaves the frame for
 * aac_sbr_usac_synthesize.  The harmonic transposer has no time signal
 * here, so the patching generates the high band. */
void aac_sbr_usac_analyze_core(AacSbrState* s, const float* core, struct AacUsacQmfFrame_* out);
void aac_sbr_usac_apply_low_to_qmf(AacSbrState* s, int ch, const struct AacUsacQmfFrame_* in,
                                   struct AacUsacQmfFrame_* out);

/* ── Shared internals of sbr_dec.cpp and sbr_esbr.cpp ──────────────
 * The bitstream elements both syntaxes share (sbr_grid, sbr_dtdf,
 * sbr_invf, sbr_envelope, sbr_noise, sbr_sinusoidal_coding of 14496-3
 * Tables 4.60 to 4.65), the dequantisation and the per-band pieces of
 * the high-frequency generator and the assembler.  The readers work on
 * `s->time_slots` envelope slots and the state's frequency tables. */
void aac_sbr_freq_from_state(const AacSbrState* s, AacSbrFreq* f);
void aac_sbr_freq_to_state(const AacSbrFreq* f, AacSbrState* s);
int aac_sbr_read_grid(AacSbrState* s, AacBitReader* r, AacSbrChannel* ch);
void aac_sbr_copy_grid(AacSbrChannel* dst, const AacSbrChannel* src);
void aac_sbr_read_dtdf(AacBitReader* r, AacSbrChannel* ch);
void aac_sbr_read_invf(const AacSbrState* s, AacBitReader* r, AacSbrChannel* ch);
int aac_sbr_read_envelope(const AacSbrState* s, AacBitReader* r, AacSbrChannel* ch, int chan);
int aac_sbr_read_noise(const AacSbrState* s, AacBitReader* r, AacSbrChannel* ch, int chan);
void aac_sbr_read_add_harmonic(const AacSbrState* s, AacBitReader* r, AacSbrChannel* ch);
void aac_sbr_dequant(AacSbrState* s);
/* Second-order linear prediction of one subband over its `n` columns. */
void aac_sbr_lpc_band(float alpha0[2], float alpha1[2], const float (*x)[2], int n);
void aac_sbr_chirp(const AacSbrState* s, AacSbrChannel* ch);
/* One patched band: X_high[i] from X_low[i - 2..i] with the chirped
 * predictor for i in [start, end). */
void aac_sbr_hf_gen_band(float (*X_high)[2], const float (*X_low)[2], const float alpha0[2],
                         const float alpha1[2], float bw, int start, int end);
/* Noise and sinusoids of one slot added to Y[0..m_max) (the bands from kx). */
void aac_sbr_apply_noise(float (*Y)[2], const float* s_m, const float* q_filt, unsigned noise,
                         int kx, int m_max, int indexsine);

/* Tables (sbr_tables.cpp). */
extern const int8_t aac_sbr_huff_env_level_15_t[120][2];
extern const int8_t aac_sbr_huff_env_level_15_f[120][2];
extern const int8_t aac_sbr_huff_env_balance_15_t[48][2];
extern const int8_t aac_sbr_huff_env_balance_15_f[48][2];
extern const int8_t aac_sbr_huff_env_level_30_t[62][2];
extern const int8_t aac_sbr_huff_env_level_30_f[62][2];
extern const int8_t aac_sbr_huff_env_balance_30_t[24][2];
extern const int8_t aac_sbr_huff_env_balance_30_f[24][2];
extern const int8_t aac_sbr_huff_noise_level_30_t[62][2];
extern const int8_t aac_sbr_huff_noise_balance_30_t[24][2];
extern const float aac_sbr_noise_table[512][2];

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_SBR_H */
