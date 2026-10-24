/* Internal declarations of the USAC linear prediction domain core
 * (ISO/IEC 23003-3 6.2.7, 7.11 to 7.17), shared by usac_lpd.cpp,
 * usac_lpc.cpp, usac_acelp.cpp, usac_tcx.cpp, usac_fac.cpp and
 * usac_lpd_tables.cpp.  Not part of the public API.
 *
 * Time layout.  Output frame coordinates run over [0, N) for the frame
 * being emitted.  An LPD superframe owns the segment [N/2, 3N/2): its
 * four subframes of N/4 samples start at the boundaries
 * B_k = N/2 + k N/4.  An FD frame owns the same segment (its window
 * slopes are centred on N/2 and 3N/2), so the overlap buffer of the
 * core channel contract always covers [N, 2N) of the current frame, the
 * next frame's [0, N). */
#ifndef BAANDER_AAC_USAC_LPD_INTERNAL_H
#define BAANDER_AAC_USAC_LPD_INTERNAL_H

#include <cstdint>

#include "bitstream.h"
#include "mdct.h"
#include "usac_lpd.h"

/* ── dimensions ─────────────────────────────────────────────────── */

#define LPD_M 16             /* LPC order */
#define LPD_LSF_MAX 6400.0f  /* the LSF scale: 0 .. 6400 maps to 0 .. core Nyquist */
#define LPD_MAX_FRAME 1024
#define LPD_MAX_DIV 256      /* ACELP/TCX subframe: frame / 4 */
#define LPD_MAX_SUBFR 64     /* ACELP subframe: frame / 16 */
#define LPD_MAX_LFAC 128     /* FAC length at an FD boundary: frame / 8 */
#define LPD_MAX_TCX 1024     /* TCX80 lines */
#define LPD_PIT_MAX_MAX 1024 /* room for the pitch range at any core rate */
#define LPD_INTERPOL 16      /* half length of the 1/4 resolution interpolation filter */
#define LPD_UP_SAMP 4
#define LPD_EXC_HIST (LPD_PIT_MAX_MAX + LPD_INTERPOL + 1)
#define LPD_SYN_HIST 1024 /* synthesis history kept for the bass postfilter */
#define LPD_X_LEN (2 * LPD_MAX_FRAME + 2 * LPD_MAX_LFAC)
#define LPD_GAMMA1 0.92f /* A(z / gamma1), the perceptual weighting */
#define LPD_PIT_SHARP 0.85f
#define LPD_BPF_TAPS 16

/* ── lpd_channel_stream() as parsed ─────────────────────────────── */

using LpdAcelpSubframe = struct LpdAcelpSubframe_ {
  int acb_index;
  int ltp_filtering_flag;
  int icb_bits;
  uint32_t icb_index[4]; /* per track codeword */
  int gains_index;
};

using LpdAcelpData = struct LpdAcelpData_ {
  int mean_energy;
  LpdAcelpSubframe sf[4];
};

using LpdTcxData = struct LpdTcxData_ {
  int lg;
  int noise_factor;
  int global_gain;
  int arith_reset;
  int q[LPD_MAX_TCX]; /* the quantised lines */
};

/* One AVQ coded block of 8 values (the LPC residual and the FAC use it). */
using LpdAvqBlock = struct LpdAvqBlock_ {
  int qn;
  uint32_t base; /* 4 * nb bits, nb = 2, 3 or 4 */
  int kv[8];     /* Voronoi indices, r bits each */
};

using LpdFacData = struct LpdFacData_ {
  int present;
  int length;     /* lfac */
  int gain_index; /* -1 when no gain is transmitted */
  LpdAvqBlock blk[LPD_MAX_LFAC / 8];
};

using LpdLpcSet = struct LpdLpcSet_ {
  int present;
  int mode; /* 0 absolute, 1 relative to lpc4, 2 relative to lpc2, 3 mid of the neighbours */
  int abs_index; /* first stage, 8 bits */
  LpdAvqBlock blk[2];
};

using LpdFrameData = struct LpdFrameData_ {
  int acelp_core_mode;
  int lpd_mode;
  int mod[4];
  int bpf_control_info;
  int core_mode_last;
  int fac_data_present;
  int first_lpd_flag;
  LpdAcelpData acelp[4];
  LpdTcxData tcx[4];
  LpdFacData fac[4]; /* the FAC before subframe k (k = 0: at the frame start, LPD neighbour) */
  LpdLpcSet lpc[5];  /* lpc0 .. lpc4 */
  LpdFacData fac_fd; /* the FAC toward the previous FD frame */
};

/* ── the arithmetic decoder of the TCX spectrum ─────────────────── */

using LpdArithState = struct LpdArithState_ {
  int lg_prev;                             /* lines of the previous spectrum, 0 after a reset */
  uint8_t ctx_prev[LPD_MAX_TCX / 2 + 4];   /* the 2-tuple context of the previous frame */
};

/* ── channel state ──────────────────────────────────────────────── */

struct AacUsacLpdChannel_ {
  int frame_length, l_div, nb_subfr, lfac, lfac_fd, core_sr; /* ACELP subframes of 64 per L_DIV */
  int pit_min, pit_fr2, pit_fr1, pit_max;
  AacMdct mdct[3];                                  /* 2 lg for TCX20, 40 and 80 */

  /* LPC */
  int have_lsf_prev;
  float lsf_prev[LPD_M];   /* lpc4 of the previous LPD frame, LSF scale */
  float lsf_old[LPD_M];    /* the LSFs the stability factor compares against */
  float a_prev[LPD_M + 1]; /* lpc4 of the previous frame, A(z) */

  /* ACELP */
  float exc[LPD_EXC_HIST + LPD_MAX_FRAME]; /* excitation, newest at exc[LPD_EXC_HIST - 1] */
  float syn_mem[LPD_M];                    /* synthesis filter memory (last outputs) */
  float tilt_code;
  float gain_code_prev;
  int t0_prev;
  float gain_pit_prev;
  int last_mode; /* -1 none, 0 ACELP, 1..3 TCX, 4 FD */
  int bpf_T[2 * LPD_MAX_FRAME / LPD_MAX_SUBFR];     /* pitch lag per 64-sample slot of the */
  float bpf_gain[2 * LPD_MAX_FRAME / LPD_MAX_SUBFR]; /* previous and the current superframe */

  /* TCX */
  LpdArithState arith;
  unsigned noise_seed;
  float tcx_gain_last;

  /* the synthesis as it lands: output frame coordinates, 2 N plus margin */
  float x[LPD_X_LEN];
  /* history of the final (pre postfilter) synthesis before x[0] */
  float hist[LPD_SYN_HIST];
  /* hist followed by x, for the memories and the postfilter */
  float tmp[LPD_SYN_HIST + LPD_X_LEN];

  /* concealment */
  float conceal_gain;
  LpdFrameData frame; /* the last parsed frame, repeated by the concealment */
  int have_frame;
};

/* ── tables (usac_lpd_tables.cpp) ───────────────────────────────── */

/* Builds the derived tables (AVQ leaders, interpolation and postfilter
 * filters, the placeholder codebooks); idempotent. */
void lpd_tables_init(void);
const float* lpd_lsf_mean(void);
/* First stage of the LSF quantiser: codevector `index` (8 bits). */
void lpd_lsf_first_stage(int index, float* lsf);
/* The 7 bit joint gain codebook: pitch gain and innovation gain factor. */
void lpd_gain_dequant(int index, float* gain_pit, float* gain_code_factor);
extern const int lpd_re8_gen[8][8];
const float* lpd_bpf_filter(void);
const float* lpd_interp_filter(void);

/* AVQ leaders of RE8: the absolute leaders of Q2, Q3 and Q4 in their
 * index order; each leader has its first index and the number of
 * codevectors (signed permutations) it stands for. */
int lpd_avq_num_leaders(int nb);
const int* lpd_avq_leader(int nb, int k);
int lpd_avq_leader_offset(int nb, int k);
int lpd_avq_leader_count(int nb, int k);

/* ── usac_lpc.cpp ───────────────────────────────────────────────── */

void lpd_lsf_to_lsp(const float* lsf, float* lsp);
void lpd_lsp_to_lsf(const float* lsp, float* lsf);
void lpd_lsp_to_a(const float* lsp, float* a);
void lpd_weight_a(const float* a, float gamma, float* ap);
int lpd_read_lpc_data(AacBitReader* r, LpdFrameData* f);
void lpd_lsf_decode(AacUsacLpdChannel* c, const LpdFrameData* f, float lsf[5][LPD_M]);
void lpd_avq_decode(const LpdAvqBlock* b, int* y);
int lpd_read_avq_block(AacBitReader* r, int nk_mode, LpdAvqBlock* b);
void lpd_syn_filter(const float* a, const float* in, float* out, int n, float* mem);
void lpd_res_filter(const float* a, const float* in, float* out, int n, const float* mem);
void lpd_interp_lsp(const float* lsp0, const float* lsp1, float frac, float* a);
float lpd_stab_fac(const float* lsf, const float* lsf_old);

/* ── usac_acelp.cpp ─────────────────────────────────────────────── */

int lpd_read_acelp(AacBitReader* r, int acelp_core_mode, int nb_subfr, LpdAcelpData* d);
void lpd_acelp_decode(AacUsacLpdChannel* c, const LpdAcelpData* d, const float* lsp0,
                      const float* lsp1, float stab_fac, float* out, int* t_sf, float* g_sf);
int lpd_acelp_icb_bits(int acelp_core_mode);
void lpd_acelp_pulses(const LpdAcelpSubframe* sf, float* code, int l_subfr);
void lpd_acelp_pitch(const AacUsacLpdChannel* c, int index, int bits, int* t0, int* frac,
                     int* t0_min, int* t0_max);
void lpd_acelp_adaptive(const AacUsacLpdChannel* c, const float* exc, int t0, int frac,
                        float* v, int n);
void lpd_acelp_memory_from_signal(AacUsacLpdChannel* c, const float* a, const float* sig,
                                  int n);

/* ── usac_tcx.cpp ───────────────────────────────────────────────── */

int lpd_read_tcx(AacBitReader* r, int lg, int first_tcx, int indep, LpdArithState* st,
                 LpdTcxData* d);
void lpd_tcx_decode(AacUsacLpdChannel* c, const LpdTcxData* d, const float* a_start,
                    const float* a_end, int left_slope, float* out);
void lpd_tcx_window(float* w, int lg, int left_slope, int right_slope);
void lpd_arith_reset(LpdArithState* st);
int lpd_arith_decode(AacBitReader* r, LpdArithState* st, int lg, int* q);
const uint16_t* lpd_arith_cf_m(int pk);
const uint16_t* lpd_arith_cf_r(int t);
int lpd_arith_pk(const uint8_t* prev, const uint8_t* cur, int i, int n, int esc);

/* ── usac_lpd.cpp ───────────────────────────────────────────────── */

int lpd_modes(int lpd_mode, int* mod);

/* ── usac_fac.cpp ───────────────────────────────────────────────── */

int lpd_read_fac(AacBitReader* r, int use_gain, int length, LpdFacData* d);
void lpd_fac_synth(const LpdFacData* d, float gain, const float* a, float* out);
void lpd_fac_synth_q(const int* fac_q, int length, float gain, const float* a, float* out);
void lpd_dct4(const float* in, float* out, int n);
void lpd_zir(const float* a, const float* mem, float* out, int n);

#endif /* BAANDER_AAC_USAC_LPD_INTERNAL_H */
