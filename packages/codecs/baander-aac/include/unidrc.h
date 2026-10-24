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

/* Limits of what the decoder stores of a configuration; a stream beyond
 * them is refused with AAC_ERR_DECODE. */
#define AAC_UNIDRC_MAX_GAIN_SETS 12
#define AAC_UNIDRC_MAX_BANDS 4
#define AAC_UNIDRC_MAX_SEQUENCES 48
#define AAC_UNIDRC_MAX_INSTRUCTIONS 32
#define AAC_UNIDRC_MAX_DOWNMIXES 32
#define AAC_UNIDRC_MAX_ADDITIONAL_DOWNMIXES 7
#define AAC_UNIDRC_MAX_CHANNELS AAC_MAX_CHANNELS
#define AAC_UNIDRC_MAX_LOUDNESS_INFO 32 /* each of the album and the program lists */
#define AAC_UNIDRC_MAX_MEASUREMENTS 16
#define AAC_UNIDRC_MAX_NODES 128 /* nodes of one gain sequence in one frame */

/* drcSetEffect bits (ISO/IEC 23003-4 Table 15). */
enum {
  AAC_UNIDRC_EFFECT_NIGHT = 1 << 0,
  AAC_UNIDRC_EFFECT_NOISY = 1 << 1,
  AAC_UNIDRC_EFFECT_LIMITED = 1 << 2,
  AAC_UNIDRC_EFFECT_LOWLEVEL = 1 << 3,
  AAC_UNIDRC_EFFECT_DIALOG = 1 << 4,
  AAC_UNIDRC_EFFECT_GENERAL = 1 << 5,
  AAC_UNIDRC_EFFECT_EXPAND = 1 << 6,
  AAC_UNIDRC_EFFECT_ARTISTIC = 1 << 7,
  AAC_UNIDRC_EFFECT_CLIPPING = 1 << 8,
  AAC_UNIDRC_EFFECT_FADE = 1 << 9,
  AAC_UNIDRC_EFFECT_DUCK_OTHER = 1 << 10,
  AAC_UNIDRC_EFFECT_DUCK_SELF = 1 << 11,
};

/* gainCodingProfile */
enum {
  AAC_UNIDRC_PROFILE_REGULAR = 0,
  AAC_UNIDRC_PROFILE_FADING = 1,
  AAC_UNIDRC_PROFILE_CLIPPING_DUCKING = 2,
  AAC_UNIDRC_PROFILE_CONSTANT = 3,
};

/* methodDefinition of a loudness measurement (Table 25). */
enum {
  AAC_UNIDRC_METHOD_UNKNOWN = 0,
  AAC_UNIDRC_METHOD_PROGRAM_LOUDNESS = 1,
  AAC_UNIDRC_METHOD_ANCHOR_LOUDNESS = 2,
  AAC_UNIDRC_METHOD_MAX_OF_RANGE = 3,
  AAC_UNIDRC_METHOD_MOMENTARY_MAX = 4,
  AAC_UNIDRC_METHOD_SHORT_TERM_MAX = 5,
  AAC_UNIDRC_METHOD_LOUDNESS_RANGE = 6,
  AAC_UNIDRC_METHOD_MIXING_LEVEL = 7,
  AAC_UNIDRC_METHOD_ROOM_TYPE = 8,
  AAC_UNIDRC_METHOD_SHORT_TERM_LOUDNESS = 9,
};

/* One band of a gain set: its gain sequence and characteristic. */
using AacUniDrcBand = struct AacUniDrcBand_ {
  int gain_sequence_index;
  int characteristic;         /* CICP drcCharacteristic, 0 none */
  int characteristic_left;    /* index into the custom left/right tables, -1 none */
  int characteristic_right;
  int crossover_freq_index;   /* drcBandType 1 */
  int start_sub_band_index;   /* drcBandType 0 */
};

/* gainSetParams() */
using AacUniDrcGainSet = struct AacUniDrcGainSet_ {
  int gain_coding_profile;
  int gain_interpolation_type; /* 0 spline, 1 linear */
  int full_frame;
  int time_alignment;
  int time_delta_min; /* samples; 0 when the default of the sample rate applies */
  int band_count;
  int drc_band_type;
  AacUniDrcBand band[AAC_UNIDRC_MAX_BANDS];
};

/* drcCoefficientsUniDrc() of version 0 or 1 */
using AacUniDrcCoefficients = struct AacUniDrcCoefficients_ {
  int version;
  int drc_location;
  int drc_frame_size; /* 0 when the codec frame applies */
  int characteristic_left_count, characteristic_right_count;
  int shape_filter_count;
  int gain_sequence_count; /* v1: as signalled; v0: the sum of the bands */
  int gain_set_count;
  AacUniDrcGainSet gain_set[AAC_UNIDRC_MAX_GAIN_SETS];
};

/* The gain modifiers of one channel group (gainModifiers()). */
using AacUniDrcGroup = struct AacUniDrcGroup_ {
  int gain_set_index; /* 0-based into the coefficients' gain sets */
  float attenuation_scaling, amplification_scaling; /* 1.0 when absent */
  float gain_offset_db;                             /* 0 when absent */
  int shape_filter_index;                           /* -1 none */
};

/* drcInstructionsUniDrc() of version 0 or 1 */
using AacUniDrcInstructions = struct AacUniDrcInstructions_ {
  int version;
  int drc_set_id;
  int complexity_level;
  int drc_location;
  int downmix_id; /* 0 when not present */
  int apply_to_downmix;
  int additional_downmix_count;
  int additional_downmix_id[AAC_UNIDRC_MAX_ADDITIONAL_DOWNMIXES];
  unsigned drc_set_effect;
  int limiter_peak_target_present;
  float limiter_peak_target_db;
  int target_loudness_present;
  int target_loudness_upper, target_loudness_lower; /* LUFS */
  int depends_on_drc_set; /* -1 none */
  int no_independent_use;
  int requires_eq;
  int channel_count;
  int gain_set_index[AAC_UNIDRC_MAX_CHANNELS]; /* per channel, -1 none */
  float ducking_scaling[AAC_UNIDRC_MAX_CHANNELS];
  int group_count;
  AacUniDrcGroup group[AAC_UNIDRC_MAX_CHANNELS];
};

using AacUniDrcDownmix = struct AacUniDrcDownmix_ {
  int downmix_id;
  int target_channel_count;
  int target_layout;
  int coefficients_present;
};

/* uniDrcConfig() */
using AacUniDrcConfig = struct AacUniDrcConfig_ {
  int sample_rate; /* 0 when not signalled */
  int base_channel_count;
  int downmix_count;
  AacUniDrcDownmix downmix[AAC_UNIDRC_MAX_DOWNMIXES];
  int coefficients_count; /* v0 and v1 together; the v1 ones follow the v0 ones */
  AacUniDrcCoefficients coefficients[2];
  int instructions_count;
  AacUniDrcInstructions instructions[AAC_UNIDRC_MAX_INSTRUCTIONS];
  int has_parametric_drc, has_loud_eq, has_eq; /* tools parsed over and not applied */
};

using AacUniDrcMeasurement = struct AacUniDrcMeasurement_ {
  int method;
  float value; /* LUFS, LU, dB or the mixing level and room type codes */
  int system;
  int reliability;
};

/* loudnessInfo() */
using AacUniDrcLoudnessInfo = struct AacUniDrcLoudnessInfo_ {
  int drc_set_id;
  int eq_set_id; /* v1, 0 otherwise */
  int downmix_id;
  int sample_peak_present;
  float sample_peak_db;
  int true_peak_present;
  float true_peak_db;
  int true_peak_system, true_peak_reliability;
  int measurement_count;
  AacUniDrcMeasurement m[AAC_UNIDRC_MAX_MEASUREMENTS];
};

/* loudnessInfoSet() */
using AacUniDrcLoudnessSet = struct AacUniDrcLoudnessSet_ {
  int album_count;
  AacUniDrcLoudnessInfo album[AAC_UNIDRC_MAX_LOUDNESS_INFO];
  int count;
  AacUniDrcLoudnessInfo info[AAC_UNIDRC_MAX_LOUDNESS_INFO];
};

/* One node of a gain sequence: its time in samples from the frame start
 * (the last node of a frame is at frame_length - 1), the gain in dB and
 * the slope (spline interpolation). */
using AacUniDrcNode = struct AacUniDrcNode_ {
  int time;
  float gain_db;
  float slope;
};

using AacUniDrcSequence = struct AacUniDrcSequence_ {
  int node_count;
  AacUniDrcNode node[AAC_UNIDRC_MAX_NODES];
};

/* uniDrcGain() of one frame */
using AacUniDrcGainFrame = struct AacUniDrcGainFrame_ {
  int sequence_count;
  AacUniDrcSequence seq[AAC_UNIDRC_MAX_SEQUENCES];
};

/* The parsers, also usable on their own: each reads one syntax element
 * from the reader and returns AAC_OK or AAC_ERR_DECODE. */
int aac_unidrc_parse_config(AacBitReader* r, AacUniDrcConfig* c);
int aac_unidrc_parse_loudness(AacBitReader* r, AacUniDrcLoudnessSet* l);
/* Reads uniDrcGain() for the gain sets of `c` at `frame_length` output
 * samples and `sample_rate` (the default deltaTmin follows from it);
 * `payload_bits` bounds the read, and the reader is left after the last
 * field read, before the payload's padding. */
int aac_unidrc_parse_gain(const AacUniDrcConfig* c, AacBitReader* r, int payload_bits,
                          int frame_length, int sample_rate, AacUniDrcGainFrame* g);
/* The gain sequence the band `band` of gain set `set` uses. */
int aac_unidrc_sequence_index(const AacUniDrcConfig* c, int set, int band);
/* deltaTmin of a gain set: the signalled value or the default of the rate. */
int aac_unidrc_time_delta_min(const AacUniDrcGainSet* gs, int sample_rate);

/* Readback of the parsed configuration and the selection, for tests and
 * diagnostics; the pointers stay valid until the next set_config. */
const AacUniDrcConfig* aac_unidrc_config(const AacUniDrc* d);
const AacUniDrcLoudnessSet* aac_unidrc_loudness(const AacUniDrc* d);
/* The index of the selected instructions in the config (-1 none), and
 * the loudnessInfo the normalisation used (NULL none). */
int aac_unidrc_selected_instructions(const AacUniDrc* d);
const AacUniDrcLoudnessInfo* aac_unidrc_selected_loudness(const AacUniDrc* d);
/* The gain of the current frame per sample for channel `channel`, as the
 * next aac_unidrc_apply would apply it (the DRC gain times the
 * normalisation gain), written to out[0..frame_length-1]. */
void aac_unidrc_channel_gain(const AacUniDrc* d, int channel, float* out, int frame_length);

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
