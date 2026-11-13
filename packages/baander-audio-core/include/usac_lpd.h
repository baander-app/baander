#ifndef BAANDER_AAC_USAC_LPD_H
#define BAANDER_AAC_USAC_LPD_H

#include "usac.h"

#ifdef __cplusplus
extern "C" {
#endif

/* The linear prediction domain core of USAC (ISO/IEC 23003-3 6.2.7 and
 * 7.11 to 7.17): lpd_channel_stream() with its mode field (ACELP, TCX 20,
 * 40 and 80 ms per subframe), the LPC filter sets with the LSF quantiser,
 * ACELP (adaptive and innovation codebooks, gains, the bass post filter),
 * TCX (the arithmetic coded spectrum with its LPC-shaped noise filling
 * and gain), the forward aliasing cancellation at every FD/LPD and
 * ACELP/TCX boundary and the windowing across the mode switches. */

/* The LPD memories of one channel: LPC and LSF history, the excitation
 * and synthesis history, the last mode, the TCX overlap.  Defined in the
 * module; created per channel. */
typedef struct AacUsacLpdChannel_ AacUsacLpdChannel;

/* Creates the channel for a frame length of 1024 or 768 and any core
 * sample rate, so that an element whose frames stay in the frequency
 * domain has its channel too.  The ACELP pitch lag range follows the
 * standard's scaling of the 12.8 kHz range up to a 24 kHz core, the
 * highest rate that scaling serves; above it the range is held in order
 * within the excitation history and every lag code decodes inside it.
 * Returns null for another frame length or a rate of zero or less. */
AacUsacLpdChannel* aac_usac_lpd_channel_create(int frame_length, int core_sample_rate);
void aac_usac_lpd_channel_destroy(AacUsacLpdChannel* c);
void aac_usac_lpd_channel_reset(AacUsacLpdChannel* c);

/* Parses lpd_channel_stream() for one channel and reconstructs one frame
 * of `frame_length` core samples at full scale 1.0 into `out`.  When the
 * previous frame was FD (ch->prev_core_mode == 0) the FD tail in
 * ch->overlap is continued and the fac_data of this stream completes the
 * transition.  On return ch->overlap holds the LPD contribution to the
 * next frame and ch->prev_core_mode is 1.  `indep` is
 * usacIndependencyFlag.  Returns AAC_OK or AAC_ERR_DECODE. */
int aac_usac_lpd_decode(AacUsacCoreChannel* ch, int frame_length, int indep, AacBitReader* r,
                        float* out);

/* An FD frame after an LPD frame carries fac_data (fac_length values,
 * frame_length / 8 for a long window, frame_length / 16 per short
 * window); the FD module hands the dequantised FAC coefficients here, and
 * this adds the FAC synthesis to ch->overlap before the FD overlap-add. */
void aac_usac_lpd_fac_to_fd(AacUsacCoreChannel* ch, const int* fac_q, int fac_length,
                            int frame_length);
/* The same with the fac_gain index (7 bits) the FD frame's fac_data
 * carries; aac_usac_lpd_fac_to_fd applies a gain of one. */
void aac_usac_lpd_fac_to_fd_gain(AacUsacCoreChannel* ch, const int* fac_q, int fac_length,
                                 int frame_length, int fac_gain_index);

/* An FD frame after an LPD frame carries fac_data(): fac_gain (7 bits)
 * and fac_length lattice-coded coefficients.  Reads it from the stream
 * and adds the FAC synthesis to ch->overlap; the FD module calls this
 * when fac_data_present is set.  Returns AAC_OK or AAC_ERR_DECODE. */
int aac_usac_lpd_fd_fac(AacUsacCoreChannel* ch, AacBitReader* r, int fac_length,
                        int frame_length);

/* The bass postfilter continues into the FD frame that follows an LPD
 * frame: the FD module calls this on that frame's core output (full
 * scale, `frame_length` samples) after its overlap-add, with
 * `short_windows` set for an EIGHT_SHORT_SEQUENCE frame. */
void aac_usac_lpd_fd_postfilter(AacUsacCoreChannel* ch, float* out, int frame_length,
                                int short_windows);

/* Concealment of a lost LPD frame: repeats the last excitation with
 * decaying gain and keeps the contract. */
void aac_usac_lpd_conceal(AacUsacCoreChannel* ch, int frame_length, float* out);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_USAC_LPD_H */
