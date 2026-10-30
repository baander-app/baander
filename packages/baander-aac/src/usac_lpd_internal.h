/* Internal declarations of the USAC linear prediction domain core
 * (ISO/IEC 23003-3 6.2.7, 7.11 to 7.17), shared by usac_lpd.cpp,
 * usac_lpc.cpp, usac_acelp.cpp, usac_tcx.cpp, usac_fac.cpp and
 * usac_lpd_tables.cpp.  Not part of the public API.
 *
 * Time layout.  The core output frame [0, N) of an LPD frame is the
 * second half of the previous superframe followed by the first half of
 * this one: a superframe of N samples (four subframes of L_DIV = N / 4)
 * starts at N / 2 of the output frame it is parsed with.  The channel
 * keeps the synthesis in a buffer whose index LPD_SYN_HIST is the start
 * of the current superframe, with the history before it, so that the
 * bass postfilter and the adaptive codebook can look back, and the
 * excitation in a buffer laid out the same way.  The signals inside the
 * module and the core channel contract's overlap buffer are at the FD
 * module's transform scale (32768 at full scale); the delivered frame
 * is at full scale 1.0. */
#ifndef BAANDER_AAC_USAC_LPD_INTERNAL_H
#define BAANDER_AAC_USAC_LPD_INTERNAL_H

#include <cstdint>

#include "bitstream.h"
#include "mdct.h"
#include "usac_fd.h"
#include "usac_lpd.h"

/* ── dimensions ─────────────────────────────────────────────────── */

#define LPD_M 16            /* LPC order */
#define LPD_LSF_MAX 6400.0f /* the LSF scale: 0 .. 6400 is 0 .. the 12.8 kHz Nyquist */
#define LPD_LSF_GAP 50.0f   /* minimum LSF spacing after dequantisation */
#define LPD_MAX_FRAME 1024
#define LPD_MAX_DIV 256     /* L_DIV: a quarter superframe, one ACELP frame or TCX20 */
#define LPD_SUBFR 64        /* ACELP subframe */
#define LPD_MAX_SUBFR 4     /* ACELP subframes per L_DIV */
#define LPD_MAX_LFAC 128    /* FAC length: L_DIV / 2 */
#define LPD_MAX_TCX 1024    /* TCX80 lines */
#define LPD_MAX_SLOTS 16    /* 64-sample postfilter slots per superframe */
#define LPD_PIT_MAX 411     /* the largest pitch lag: that of a 24 kHz core, the highest rate
                             * the lag range formula serves; the excitation history holds it */
#define LPD_INTERP_ORDER 16 /* half length of the 1/4 resolution interpolation filter */
#define LPD_UP_SAMP 4
#define LPD_INTERP_LEN (LPD_UP_SAMP * LPD_INTERP_ORDER + 1)
#define LPD_BPF_DELAY 12 /* half length of the postfilter low pass */
#define LPD_SYN_HIST 1024 /* synthesis history before the superframe */
#define LPD_EXC_HIST 512  /* excitation history before the superframe */
#define LPD_PREEMPH 0.68f
#define LPD_TILT_CODE 0.3f
#define LPD_PIT_SHARP 0.85f
#define LPD_GAMMA 0.92f /* A(z / gamma), the perceptual weighting of the FAC and the TCX */

#define LPD_AVQ_LEADERS 37
#define LPD_AVQ_SIGN_CLASSES 226
#define LPD_AVQ_Q3_LEADERS 9
#define LPD_AVQ_Q4_LEADERS 28

/* ── lpd_channel_stream() as parsed ─────────────────────────────── */

using LpdAcelpData = struct LpdAcelpData_ {
  int mean_energy;
  int acb_index[LPD_MAX_SUBFR];
  int ltp_filtering_flag[LPD_MAX_SUBFR];
  int icb_index[LPD_MAX_SUBFR][8]; /* the codewords of the innovation codebook */
  int gains_index[LPD_MAX_SUBFR];
};

using LpdTcxData = struct LpdTcxData_ {
  int lg;
  int noise_factor;
  int global_gain;
  int q[LPD_MAX_TCX]; /* the quantised lines */
};

using LpdLpcSet = struct LpdLpcSet_ {
  int present;
  int mode;      /* 0 absolute, 1 relative to lpc4 (lpc0, lpc2), the per set codes otherwise */
  int abs_index; /* first stage, 8 bits */
  int has_residual;
  int res[LPD_M]; /* the second stage lattice vector */
};

using LpdFrameData = struct LpdFrameData_ {
  int acelp_core_mode;
  int lpd_mode;
  int mod[4];
  int bpf_control_info;
  int core_mode_last;
  int fac_data_present;
  int first_lpd_flag;
  int arith_reset;
  LpdAcelpData acelp[4];
  LpdTcxData tcx[4];
  int fac_present[4];           /* a FAC before subframe k (an ACELP/TCX boundary) */
  int fac[4][LPD_MAX_LFAC];     /* its lattice coded coefficients */
  LpdLpcSet lpc[5];             /* lpc0 .. lpc4 */
  int fd_fac_short;             /* the FAC toward a previous FD frame: short windows */
  int fd_fac_gain;              /* its 7 bit gain index */
  int fd_fac[LPD_MAX_LFAC];     /* its coefficients */
};

/* ── channel state ──────────────────────────────────────────────── */

struct AacUsacLpdChannel_ {
  int frame_length, l_div, nb_subfr, lfac, nb_slots, synth_delay, core_sr;
  int pit_min, pit_fr2, pit_fr1, pit_max;
  AacMdct mdct[3]; /* 2 lg for TCX20, 40 and 80 */

  /* LPC: the LSF and LSP of the last filter set */
  float lsf_prev[LPD_M];
  float lsp_prev[LPD_M];
  int mode_prev; /* -1 FD (long window), -2 FD (short windows), 0 ACELP, 1 .. 3 TCX */

  /* The synthesis (final, before the postfilter) with its history:
   * syn[LPD_SYN_HIST] is the superframe start. */
  float syn[LPD_SYN_HIST + LPD_MAX_FRAME + LPD_MAX_LFAC];
  /* The excitation laid out the same way. */
  float exc[LPD_EXC_HIST + LPD_MAX_FRAME + 1];
  /* The overlap toward the next segment, 1 + 2 lfac samples starting
   * one sample before the last lfac of the segment: the unwindowed TCX
   * tail, or the ACELP end followed by the zero input response. */
  float ovl[1 + 2 * LPD_MAX_LFAC];
  /* The LPC filters at the end of the last segment (the two last ACELP
   * subframes, or the TCX end filter twice). */
  float a_prev[2][LPD_M + 1];
  float fac_gain;                      /* of the last TCX, for the FAC after it */
  float alfd_gains[LPD_MAX_LFAC / 4];  /* its low frequency de-emphasis gains */

  /* bass postfilter: the pitch and gain of the slots of the second half
   * of the last superframe, the noise memory, the gain threshold */
  int pitch_prev[LPD_MAX_SLOTS / 2];
  float gain_prev[LPD_MAX_SLOTS / 2];
  float bpf_noise[LPD_BPF_DELAY + LPD_SUBFR];
  int bpf_active_prev;
  float gain_threshold;

  /* TCX */
  AacUsacArithContext arith;
  unsigned* noise_seed; /* the core channel's generator, set per frame */

  /* concealment */
  float conceal_gain;
  LpdFrameData frame; /* the last parsed frame */
  int have_frame;
};

/* ── tables (usac_lpd_tables.cpp) ───────────────────────────────── */

extern const int lpd_avq_leaders[LPD_AVQ_LEADERS][8];
extern const uint8_t lpd_avq_sign_data[LPD_AVQ_SIGN_CLASSES];
extern const uint32_t lpd_avq_sign_first[LPD_AVQ_SIGN_CLASSES];
extern const int lpd_avq_sign_num[LPD_AVQ_LEADERS];
extern const int lpd_avq_sign_index[LPD_AVQ_LEADERS];
extern const int lpd_avq_q3_leader[LPD_AVQ_Q3_LEADERS];
extern const uint32_t lpd_avq_q3_offset[LPD_AVQ_Q3_LEADERS];
extern const int lpd_avq_q4_leader[LPD_AVQ_Q4_LEADERS];
extern const uint32_t lpd_avq_q4_offset[LPD_AVQ_Q4_LEADERS];
extern const float lpd_lsf_dico[256][LPD_M];
extern const float lpd_lsf_init[LPD_M];
extern const float lpd_gain_table[128][2];
extern const float lpd_interp_filter[LPD_INTERP_LEN];
extern const float lpd_bpf_filter[LPD_BPF_DELAY + 1];

/* ── usac_lpc.cpp ───────────────────────────────────────────────── */

void lpd_lsf_to_lsp(const float* lsf, float* lsp);
void lpd_lsp_to_lsf(const float* lsp, float* lsf);
void lpd_lsp_to_a(const float* lsp, float* a);
void lpd_weight_a(const float* a, float gamma, float* ap);
/* Interpolates between two LSP sets for `n` ACELP subframes: a[k] is the
 * filter at the centre of subframe k, a[n] the filter of lsp1. */
void lpd_interp_lsp(const float* lsp0, const float* lsp1, int n, float a[][LPD_M + 1]);
float lpd_stab_fac(const float* lsf, const float* lsf_old);
/* Synthesis 1 / A(z) in place over x[0 .. n), reading x[-16 .. 0) as the
 * memory. */
void lpd_syn_filter(const float* a, float* x, int n);
/* Analysis A(z): out[i] = in[i] + sum a[j] in[i - j], reading in[-16 .. 0). */
void lpd_res_filter(const float* a, const float* in, float* out, int n);
/* Pre-emphasis in place with the sample before x[0] given. */
void lpd_preemph(float* x, int n, float mem);
/* De-emphasis in place with the output sample before x[0] given. */
void lpd_deemph(float* x, int n, float mem);

/* The algebraic vector quantiser: reads one block of eight values in the
 * code of the given nk_mode (0: absolute and lpc4-relative sets, 1: the
 * mid-prediction set and the FAC, 2: the lpc2-relative sets) and
 * decodes it.  Returns AAC_OK or AAC_ERR_DECODE. */
int lpd_read_avq(AacBitReader* r, int nk_mode, int* y);
/* Reads the base index and the Voronoi indices of one block of codebook
 * qn (already read) and decodes it. */
int lpd_read_avq_block(AacBitReader* r, int qn, int* y);
/* The lattice point of codebook qn with base index `base` and Voronoi
 * indices kv (the tests' entry). */
void lpd_avq_decode(int qn, uint32_t base, const int* kv, int* y);
int lpd_read_lpc_data(AacBitReader* r, LpdFrameData* f);
/* The five LSF sets of the frame; lsf[0] of a continued LPD frame is the
 * channel's previous set. */
void lpd_lsf_decode(const AacUsacLpdChannel* c, const LpdFrameData* f, float lsf[5][LPD_M]);

/* ── usac_acelp.cpp ─────────────────────────────────────────────── */

int lpd_read_acelp(AacBitReader* r, int acelp_core_mode, int nb_subfr, LpdAcelpData* d);
int lpd_acelp_icb_bits(int acelp_core_mode);
/* The innovation vector (64 samples) of the codewords of one subframe. */
void lpd_acelp_pulses(const int* icb_index, int bits, float* code);
void lpd_acelp_pitch(const AacUsacLpdChannel* c, int index, int absolute, int* t0, int* frac,
                     int* t0_min, int* t0_max);
/* exc[0 .. n) from exc[-t0 - frac/4 ...] with the 1/4 resolution filter,
 * in place (a lag shorter than n repeats what the call wrote). */
void lpd_acelp_adaptive(float* exc, int t0, int frac, int n);
/* Decodes the ACELP frame of subframe k into the synthesis buffer at
 * the superframe offset k L_DIV, completing the boundary with the FAC
 * when a TCX precedes it; a[] holds the interpolated filters. */
void lpd_acelp_decode(AacUsacLpdChannel* c, const LpdFrameData* f, int k,
                      const float a[][LPD_M + 1], float stab_fac, int* pitch, float* gain);

/* ── usac_tcx.cpp ───────────────────────────────────────────────── */

int lpd_read_tcx(AacBitReader* r, int lg, int first_tcx, int indep, AacUsacLpdChannel* c,
                 LpdFrameData* f, int k);
/* Decodes the TCX of subframe k (lg lines) into the synthesis buffer,
 * with a_old / a_new the filters at its ends. */
void lpd_tcx_decode(AacUsacLpdChannel* c, const LpdFrameData* f, int k, const float* a_old,
                    const float* a_new);

/* ── usac_lpd.cpp ───────────────────────────────────────────────── */

int lpd_modes(int lpd_mode, int* mod);

/* ── usac_fac.cpp ───────────────────────────────────────────────── */

/* Reads lfac lattice coded coefficients. */
int lpd_read_fac(AacBitReader* r, int length, int* coef);
/* DCT-IV: out[n] = sum_k in[k] cos(pi/N (n + 1/2)(k + 1/2)). */
void lpd_dct4(const float* in, float* out, int n);
/* The FAC synthesis of `length` scaled coefficients: the DCT-IV scaled
 * by 2 / length, zero extended, through 1 / A(z / gamma) with zero
 * memory; out holds `out_len` (length or 2 length) samples. */
void lpd_fac_synth(const float* x, int length, const float* a, float* out, int out_len);
/* The rising half of the sine window of 2 n: w[i] = sin(pi (i + 1/2) / 2n). */
void lpd_sine_window(int n, float* w);
/* The two windows that fold the segment before an ACELP/transform
 * boundary into the transform's aliasing region: w[i] w[2 lfac - 1 - i]
 * for the mirrored past and 1 - w[lfac + i]^2 for the zero input
 * response, with w the sine slope of 2 lfac. */
void lpd_fac_windows(int lfac, float* past_w, float* zir_w);

#endif /* BAANDER_AAC_USAC_LPD_INTERNAL_H */
