#ifndef BAANDER_AAC_MPS212_ENC_H
#define BAANDER_AAC_MPS212_ENC_H

#include <cstdint>

#include "bitstream.h"
#include "mps212.h"
#include "usac.h"

#ifdef __cplusplus
extern "C" {
#endif

/* MPEG Surround 212 encoder (ISO/IEC 23003-3 7.11 with the one-to-two
 * box of ISO/IEC 23003-1) for a USAC channel pair with stereoConfigIndex
 * 1: the stereo input is analysed in the 64-band QMF domain of the SBR
 * encoder, the channel level difference and the inter-channel coherence
 * are estimated per parameter band, and the energy-preserving mono
 * downmix is what the SBR and core encoders code.  One parameter set per frame, no residual, no phase coding, no
 * temporal shaping; Mps212Data() carries the parameters with the
 * Huffman codes of the decoder's tables. */

#define AAC_MPS212_ENC_MAX_BYTES 128
#define AAC_MPS212_ENC_LOOKAROUND 16 /* QMF slots on each side of a parameter frame the analysis window covers */

using AacMps212Encoder = struct AacMps212Encoder_ {
  int freq_res;       /* bsFreqRes */
  int num_bands;      /* parameter bands */
  int fixed_gain_dmx; /* bsFixedGainDMX */
  float inv_gain_dmx; /* the downmix is divided by the fixed gain the decoder applies */
  const uint8_t* kernel; /* hybrid band to parameter band */
  int dmx_group[64];     /* downmix gain group of each QMF band */
  int num_dmx_groups;
  float gain_prev[3 + AAC_MPS212_MAX_PARAM_BANDS]; /* downmix gain per group at the end of the last block */
  /* this frame's quantiser indices and the decoder's history */
  int cld[AAC_MPS212_MAX_PARAM_BANDS];
  int icc[AAC_MPS212_MAX_PARAM_BANDS];
  int hist_cld[AAC_MPS212_MAX_PARAM_BANDS];
  int hist_icc[AAC_MPS212_MAX_PARAM_BANDS];
  int have_hist; /* delta coding in time is possible */
  uint8_t payload[AAC_MPS212_ENC_MAX_BYTES];
  int payload_bits;
  int error; /* the last analysed frame's Mps212Data() could not be written */
};

/* Sets the fixed configuration (20 parameter bands, 4.5 dB of downmix
 * headroom) and clears the state. */
void aac_mps212_enc_init(AacMps212Encoder* m);
void aac_mps212_enc_reset(AacMps212Encoder* m);

/* Mps212Config() of the stream. */
void aac_mps212_enc_config(const AacMps212Encoder* m, AacUsacMps212Config* c);

/* The downmix of one block of `slots` QMF slots (64 bands, {re, im}) of
 * the left and right channel: the sum scaled per band group so that its
 * energy is that of both inputs, the scaling interpolated across the
 * block from the previous one's, divided by the fixed downmix gain.
 * `out` may alias `l`. */
void aac_mps212_enc_downmix(AacMps212Encoder* m, const float (*l)[64][2], const float (*r)[64][2],
                            float (*out)[64][2], int slots);

/* Estimates the parameters of one frame from the rings `l` and `r` of
 * QMF slots of the left and right channel (a power of two slots long,
 * `mask` one less than that): the frame's `slots` slots from ring
 * position `base`, with AAC_MPS212_ENC_LOOKAROUND slots of history
 * before and lookahead after them (the analysis window of the split
 * bands is centred on the frame), and writes Mps212Data() into the
 * payload; `indep` is the frame's usacIndependencyFlag.  Returns 0, or
 * AAC_ERR_ENCODE / AAC_ERR_OVERFLOW when the data cannot be written,
 * which aac_mps212_enc_error repeats until the next frame. */
int aac_mps212_enc_analyze(AacMps212Encoder* m, const float (*l)[64][2], const float (*r)[64][2],
                           int base, int mask, int slots, int indep);

/* Bits of the Mps212Data() of this frame (0 after a failure), the
 * failure of its analysis, and its writer. */
int aac_mps212_enc_bits(const AacMps212Encoder* m);
int aac_mps212_enc_error(const AacMps212Encoder* m);
void aac_mps212_enc_write(const AacMps212Encoder* m, AacBitWriter* w);

/* Mps212Data() of an independent frame with the default parameters
 * (bsDataMode 0: a centred, coherent image), for a silent access unit. */
void aac_mps212_enc_write_silent(AacBitWriter* w);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_MPS212_ENC_H */
