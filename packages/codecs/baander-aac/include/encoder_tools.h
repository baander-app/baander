#ifndef BAANDER_AAC_ENCODER_TOOLS_H
#define BAANDER_AAC_ENCODER_TOOLS_H

#include "encoder.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Coding tools of the encoder that take bands out of the quantizer or
 * reshape the spectrum before it: temporal noise shaping, perceptual noise
 * substitution and intensity stereo.  All follow the design of the
 * Fraunhofer FDK encoder (aacenc_tns.cpp, aacenc_pns.cpp, intensity.cpp). */

/* Configuration from the sample rate, bitrate and lowpass of `s`. */
void aac_enc_tools_init(AacEncoderState* s);

/* TNS: chooses filters for every window of the channel's current spectrum
 * (tns_* fields), without filtering yet. */
void aac_enc_tns_decide(const AacEncoderState* s, AacEncChannel* c);
/* Makes the second channel of a pair reuse the first channel's filters
 * when they are nearly the same. */
void aac_enc_tns_sync(const AacEncoderState* s, AacEncChannel* dst, const AacEncChannel* src);
/* Replaces the filtered ranges of the spectrum by the prediction residual. */
void aac_enc_tns_apply(const AacEncoderState* s, AacEncChannel* c);
/* Bits of tns_data() after the tns_data_present flag. */
int aac_enc_tns_bits(const AacEncChannel* c);
void aac_enc_tns_write(AacBitWriter* w, const AacEncChannel* c);
/* Highest band + 1 the TNS analysis covers for this window type: the
 * decoder's range is clipped to tns_max_bands and max_sfb, never beyond. */
int aac_enc_tns_top(const AacEncoderState* s, const AacEncChannel* c);

/* PNS: marks the noise-like bands of a long frame (pns_flag, pns_energy)
 * from the band energies and thresholds of the TNS-filtered spectrum. */
void aac_enc_pns_detect(const AacEncoderState* s, AacEncChannel* c);
/* Reconciles the flags with the M/S decision: a band is substituted in
 * both channels or neither, with independent noise. */
void aac_enc_pns_stereo_finish(AacEncoderState* s);
/* Turns the flags into coded noise bands (tool, tool_sf) with the noise
 * energies kept within the DPCM range. */
void aac_enc_pns_code(const AacEncoderState* s, AacEncChannel* c);

/* Intensity stereo over a channel pair: highly correlated, clearly panned
 * band regions are coded as the left channel only, with the right channel's
 * level in tool_sf (tool = AAC_CB_INTENSITY or AAC_CB_INTENSITY2). */
void aac_enc_intensity(AacEncoderState* s);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_ENCODER_TOOLS_H */
