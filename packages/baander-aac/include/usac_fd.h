#ifndef BAANDER_AAC_USAC_FD_H
#define BAANDER_AAC_USAC_FD_H

#include "usac.h"

#ifdef __cplusplus
extern "C" {
#endif

/* The USAC core coder of one element (ISO/IEC 23003-3 6.2): parses
 * UsacCoreCoderData() for the element's channels (core_mode per channel,
 * the StereoCoreToolInfo of a CPE, then fd_channel_stream() or
 * lpd_channel_stream() per channel), reconstructs the frequency-domain
 * channels (the spectral noiseless decoding with the context based
 * arithmetic coder, scale factors, noise filling, M/S and complex stereo
 * prediction, TNS, the IMDCT with the USAC window sequences and shapes)
 * and leaves one frame of time samples per channel.  LPD channels are
 * handed to the LPD module (usac_lpd.h) with the shared core channel
 * state; the transitions follow the contract in usac.h. */

/* The FD memories of one channel: arithmetic coder context of the
 * previous frame, the previous spectrum for complex prediction (the MDST
 * estimate uses the previous frame), TNS and noise filling state.  Defined
 * in the module; created per channel. */
typedef struct AacUsacFdChannel_ AacUsacFdChannel;

/* `core_sample_rate` selects the scale factor band tables (the AAC
 * tables of the 1024 and 128 line transforms; the 768 and 96 line ones
 * are the same offsets cut at the shorter length). */
AacUsacFdChannel* aac_usac_fd_channel_create(int frame_length, int core_sample_rate);
void aac_usac_fd_channel_destroy(AacUsacFdChannel* c);
/* Forgets the previous frame (configuration change, reset, or the first
 * frame of a stream); an independent frame resets the arithmetic context
 * by itself. */
void aac_usac_fd_channel_reset(AacUsacFdChannel* c);

/* Parses UsacCoreCoderData() of an SCE (nr_channels 1), CPE (2) or LFE
 * (1 with `lfe` set: FD only, no noise filling, no TNS) and reconstructs
 * `nr_channels` frames of `frame_length` core samples at full scale 1.0
 * into out[ch].  `indep` is usacIndependencyFlag.  `noise_filling` is the
 * element's configuration flag.  Returns AAC_OK, AAC_ERR_DECODE for a
 * malformed stream or AAC_ERR_UNSUPPORTED for tw_mdct. */
int aac_usac_core_decode(AacUsacCoreChannel* const* ch, int nr_channels, int lfe, int noise_filling,
                         int frame_length, int indep, AacBitReader* r, float* const* out);

/* Concealment of a lost frame for FD channels: repeats the previous
 * spectrum with a fade, as the LC concealment does, and keeps the
 * overlap contract. */
void aac_usac_core_conceal(AacUsacCoreChannel* const* ch, int nr_channels, int frame_length,
                           float* const* out);

/* ── The spectral noiseless decoder (ISO/IEC 23003-3 7.4) ──────────
 *
 * Shared with the LPD module's TCX and with the tests.  The context of a
 * channel is the quantity min(a + b + 1, 15) of every 2-tuple of the
 * previous window (frame, or short window of the same frame), mapped to
 * the current window length when that differs. */
#define AAC_USAC_ARITH_MAX_TUPLES (AAC_USAC_MAX_CORE_FRAME / 2)
#define AAC_USAC_ARITH_ESCAPE 16

using AacUsacArithContext = struct AacUsacArithContext_ {
  int prev_n;                                  /* lines of the previous window; 0 at a reset */
  uint8_t prev[AAC_USAC_ARITH_MAX_TUPLES + 4]; /* its tuple quantities, zero beyond prev_n/2 */
};

void aac_usac_arith_reset(AacUsacArithContext* c);
/* Decodes arith_data(): the quantised values of the lowest `lg` lines of
 * a window of `n` lines into x[0..n-1] (lines above lg are zero), with the
 * sign bits that follow the arithmetic coded data (one per nonzero line, 1
 * for a positive one), and leaves the context for the next window.  `reset` is arith_reset_flag
 * (for the first window of the frame).  Returns AAC_OK or AAC_ERR_DECODE. */
int aac_usac_arith_decode(AacUsacArithContext* c, int reset, int n, int lg, AacBitReader* r,
                          int* x);

/* The range decoder itself, as the FAC and TCX paths of the LPD module
 * may use it: `cf` is a decreasing cumulative frequency table in units of
 * 1/16384 with cf[len - 1] == 0; returns the symbol index. */
using AacUsacArithState = struct AacUsacArithState_ {
  uint32_t low, high, value;
  int pos; /* the bit position of the look-ahead, which may pass the end of the data */
};
void aac_usac_arith_start(AacUsacArithState* s, const AacBitReader* r);
int aac_usac_arith_symbol(AacUsacArithState* s, const AacBitReader* r, const uint16_t* cf, int len);
/* Leaves the reader after the coded data: the decoder read 16 bits ahead
 * of it where the encoder flushed two, so 14 of the bits consumed belong
 * to what follows. */
void aac_usac_arith_finish(const AacUsacArithState* s, AacBitReader* r);
/* The probability model of a context value (with esc_nb << 17 added). */
int aac_usac_arith_model(uint32_t context);

extern const uint16_t aac_usac_ari_cf_m[64][17];
extern const uint16_t aac_usac_ari_cf_r[3][4];
extern const uint32_t aac_usac_ari_hash_m[742];
extern const uint8_t aac_usac_ari_lookup_m[742];

/* ── The stereo tools (ISO/IEC 23003-3 7.7), exposed for the tests ──
 *
 * Window sequences: the four of AAC (mdct.h) and the STOP_START_SEQUENCE
 * of USAC, a long window with a short slope on both sides.  The bitstream
 * carries LONG_START; the decoder derives STOP_START from the previous
 * frame (7.9). */
#define AAC_USAC_WIN_STOP_START 4

/* The MDST estimate of a window of `n` lines (7.7.2.3): a 7-tap filter
 * over the current window's downmix MDCT `dmx_re`, mirrored at the
 * edges, plus, when `dmx_prev` is not null, a 7-tap filter over the
 * previous window's downmix with alternating sign.  The filters follow
 * the window sequence and the shapes of the left (previous) and right
 * (current) slopes. */
void aac_usac_fd_mdst_estimate(const float* dmx_re, const float* dmx_prev, int n,
                               int window_sequence, int prev_shape, int shape, float* dmx_im);

/* Complex prediction of `len` lines: the residual in ch1 less
 * alpha_re * dmx_re + alpha_im * dmx_im is the side (pred_dir 0) or mid
 * (pred_dir 1) signal, and ch0, ch1 become left and right. */
void aac_usac_fd_cplx_predict(float* ch0, float* ch1, const float* dmx_re, const float* dmx_im,
                              int len, float alpha_re, float alpha_im, int pred_dir);

/* ── Provided by the LPD module ─────────────────────────────────────
 *
 * fac_data() of an FD frame that follows an LPD frame: fac_gain (7 bits)
 * and the AVQ coded coefficients, whose lattice decoder lives with the
 * LPD module; `fac_length` is frame_length / 8 for a long window and
 * frame_length / 16 for short ones.  Parses it from `r` and adds the
 * forward aliasing cancellation to ch->overlap before the FD overlap-add.
 * Returns AAC_OK or AAC_ERR_DECODE. */
int aac_usac_lpd_fd_fac(AacUsacCoreChannel* ch, AacBitReader* r, int fac_length, int frame_length);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_USAC_FD_H */
