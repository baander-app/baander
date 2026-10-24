#ifndef BAANDER_AAC_UNIDRC_H
#define BAANDER_AAC_UNIDRC_H

#include <cstdint>

#include "aac.h"
#include "bitstream.h"

#ifdef __cplusplus
extern "C" {
#endif

/* MPEG-D dynamic range control and loudness (ISO/IEC 23003-4): the
 * uniDrcConfig() and loudnessInfoSet() of a stream's configuration, the
 * uniDrcGain() payload of every frame, the selection process that picks a
 * DRC set and a loudness normalisation gain from the player's request,
 * the gain decoder with its interpolation and the application in the time
 * domain.  Used by the USAC decoder (the configuration arrives in the
 * UNI_DRC extension element's config and the LOUDNESS_INFO configuration
 * extension; the gains in the extension element's payload). */

typedef struct AacUniDrc_ AacUniDrc;

/* The player's request. */
using AacUniDrcRequest = struct AacUniDrcRequest_ {
  int loudness_normalization; /* 0 off, 1 on */
  int target_loudness_x100;   /* LUFS times 100, e.g. -2400 */
  int effect;                 /* AacDrcEffect: -1 DRC off, 0 none (clipping prevention only), 1..6 */
  int album_mode;
  int boost_percent, cut_percent; /* scale the boosting and attenuating gains, 0..100 */
  int peak_limiter;               /* apply the loudness-normalisation peak protection */
};

AacUniDrc* aac_unidrc_create(void);
void aac_unidrc_destroy(AacUniDrc* d);
/* Forgets the stream (configuration change or reset); the request stays. */
void aac_unidrc_reset(AacUniDrc* d);

/* Sets the uniDrcConfig() carried as the configuration of the UNI_DRC
 * extension element, and the loudnessInfoSet() of the LOUDNESS_INFO
 * configuration extension.  Either may be absent (len 0).  Returns
 * AAC_OK or AAC_ERR_DECODE; the selection process runs again
 * afterwards. */
int aac_unidrc_set_config(AacUniDrc* d, const uint8_t* unidrc_config, int config_len,
                          const uint8_t* loudness_info_set, int loudness_len);

/* The stream's layout: output channel count and sample rate, frame
 * length in output samples, used to map DRC channel groups and to size
 * the gain interpolation. */
void aac_unidrc_set_stream(AacUniDrc* d, int sample_rate, int channels, int frame_length);

void aac_unidrc_set_request(AacUniDrc* d, const AacUniDrcRequest* req);

/* Parses the uniDrcGain() payload of one frame (`payload_bits` long; the
 * reader is left past it).  `indep` is usacIndependencyFlag.  A frame
 * without the payload is noted with aac_unidrc_no_gain. */
int aac_unidrc_read_gain(AacUniDrc* d, AacBitReader* r, int payload_bits, int indep);
void aac_unidrc_no_gain(AacUniDrc* d);

/* Applies the selected DRC set's gains and the loudness normalisation to
 * one frame: ch[c] points at `frame_length` samples of channel c at full
 * scale 1.0. */
void aac_unidrc_apply(AacUniDrc* d, float* const* ch, int channels, int frame_length);

/* Readback: the program loudness the stream declares (LUFS times 100, or
 * INT32_MIN when none), the loudness normalisation gain applied (dB times
 * 100) and the id of the selected DRC set (-1 none). */
void aac_unidrc_status(const AacUniDrc* d, int* program_loudness_x100, int* norm_gain_db_x100,
                       int* drc_set_id);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_UNIDRC_H */
