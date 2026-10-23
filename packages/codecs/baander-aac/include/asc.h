#ifndef BAANDER_AAC_ASC_H
#define BAANDER_AAC_ASC_H

#include <cstdint>

#include "aac.h"
#include "bitstream.h"
#include "channel_map.h"

#ifdef __cplusplus
extern "C" {
#endif

/* AudioSpecificConfig (ISO/IEC 14496-3 1.6.2.1) of an AAC stream: the
 * core object type with its sampling frequency and channel configuration,
 * the GASpecificConfig, and the way SBR and parametric stereo are
 * signalled (1.6.5): implicitly (the core type alone; a decoder finds the
 * extension in the data), explicitly and backward compatible (a sync
 * extension after the GASpecificConfig that an old decoder skips), or
 * hierarchically (object type 5 or 29 in front of the core type). */

/* Longest config this codec writes or accepts, in bytes: a program config
 * element with every element and both sync extensions fit. */
#define AAC_ASC_MAX_BYTES 64

using AacAsc = struct AacAsc_ {
  int aot;              /* core audioObjectType: AAC_AOT_LC */
  int sample_rate;      /* core sampling frequency in Hz */
  int channel_config;   /* channelConfiguration, 0 with a PCE or an element-defined layout */
  int has_pce;          /* a program_config_element defines `layout` */
  AacLayout layout;
  int frame_length;     /* 1024, or 960 with frameLengthFlag */
  int depends_on_core_coder;
  int core_coder_delay;
  int extension_flag;
  AacSignaling signaling; /* how the extension is (to be) written */
  int sbr_present;        /* -1 not signalled, 0 absent, 1 present */
  int ps_present;         /* -1 not signalled, 0 absent, 1 present */
  int ext_sample_rate;    /* extensionSamplingFrequency in Hz, 0 when not signalled */
};

/* Fills `asc` with the settings of a plain AAC-LC stream without any
 * extension signalling. */
void aac_asc_init(AacAsc* asc, int sample_rate, int channel_config);

/* Parses an AudioSpecificConfig from a bit reader (the config may start
 * at any bit, as in a StreamMuxConfig; the program config element's byte
 * alignment is relative to the config's start) or from whole bytes.
 * Returns 0, AAC_ERR_DECODE for a malformed config or AAC_ERR_UNSUPPORTED
 * for an object type outside the General Audio family. */
int aac_asc_read(AacBitReader* r, AacAsc* asc);
int aac_asc_parse(const uint8_t* data, int size, AacAsc* asc);

/* Writes the config with the signalling `asc->signaling` names.  The byte
 * form is padded to a whole byte; returns its length in bytes, the bit
 * form the number of bits.  AAC_ERR_OVERFLOW when `out` is too small,
 * AAC_ERR_INVALID_ARG for a config this codec cannot express. */
int aac_asc_write_bits(const AacAsc* asc, AacBitWriter* w);
int aac_asc_write(const AacAsc* asc, uint8_t* out, int out_size);

/* Sampling frequency index of a rate (ISO/IEC 14496-3 Table 1.18), or -1. */
int aac_asc_rate_index(int sample_rate);

/* program_config_element() (ISO/IEC 14496-3 Table 4.2), after its
 * id_syn_ele: the element lists define the layout; mixdown data,
 * associated data and the comment are skipped.  `align_anchor` is the bit
 * position the element's byte alignment counts from (0 in a raw data
 * block, the config's start inside an AudioSpecificConfig).  The writer
 * emits the layout's elements by position with no mixdowns, associated
 * data or comment, with the AAC-LC profile and `rate_index`. */
int aac_pce_read(AacBitReader* r, AacLayout* layout, int align_anchor);
void aac_pce_write(AacBitWriter* w, const AacLayout* layout, int rate_index, int align_anchor);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_ASC_H */
