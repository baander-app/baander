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

AacUsacFdChannel* aac_usac_fd_channel_create(int frame_length);
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
int aac_usac_core_decode(AacUsacCoreChannel* const* ch, int nr_channels, int lfe,
                         int noise_filling, int frame_length, int indep, AacBitReader* r,
                         float* const* out);

/* Concealment of a lost frame for FD channels: repeats the previous
 * spectrum with a fade, as the LC concealment does, and keeps the
 * overlap contract. */
void aac_usac_core_conceal(AacUsacCoreChannel* const* ch, int nr_channels, int frame_length,
                           float* const* out);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_USAC_FD_H */
