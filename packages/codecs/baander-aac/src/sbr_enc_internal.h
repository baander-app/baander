#ifndef BAANDER_AAC_SBR_ENC_INTERNAL_H
#define BAANDER_AAC_SBR_ENC_INTERNAL_H

#include "sbr_enc.h"

/* The bitstream writers the AAC and USAC forms of the SBR data share
 * (ISO/IEC 14496-3 4.6.18.3, ISO/IEC 23003-3 7.5.2): the envelope grid,
 * the delta direction flags (an independent USAC frame skips the first
 * envelope's and the first noise floor's), the inverse filtering levels,
 * and the envelope and noise floor values against what the decoder holds. */
void aac_sbr_enc_write_grid(AacBitWriter* w, const AacSbrGrid* g, int slots);
void aac_sbr_enc_write_dtdf(AacBitWriter* w, const AacSbrEncChannel* ch, int skip_first);
void aac_sbr_enc_write_invf(const AacSbrEncoder* s, AacBitWriter* w, const AacSbrEncChannel* ch);
void aac_sbr_enc_write_envelope(const AacSbrEncoder* s, AacBitWriter* w,
                                const AacSbrEncChannel* ch, const int* prev, int prev_res);
void aac_sbr_enc_write_noise(const AacSbrEncoder* s, AacBitWriter* w, const AacSbrEncChannel* ch,
                             const int* prev);

#endif /* BAANDER_AAC_SBR_ENC_INTERNAL_H */
