#ifndef BAANDER_AAC_ASC_H
#define BAANDER_AAC_ASC_H

#include <cstdint>

#include "aac.h"
#include "bitstream.h"
#include "channel_map.h"
#include "usac.h"

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

/* One sbr_header() of the ld_sbr_header() in an ELDSpecificConfig
 * (ISO/IEC 14496-3 4.6.18.3.1), as the LD-SBR decoder and encoder keep
 * it: the fields the header leaves out carry the standard's defaults
 * (freq_scale 2, alter_scale 1, noise_bands 2, limiter_bands 2,
 * limiter_gains 2, interpol_freq 1, smoothing_mode 1), and the writer
 * sends the extension fields only where a value differs from them.
 * aac_sbr_ld_header_read and aac_sbr_ld_header_write (sbr_ld.h) are the
 * bitstream form. */
using AacSbrLdHeader = struct AacSbrLdHeader_ {
  int amp_res;
  int start_freq, stop_freq, xover_band;
  int freq_scale, alter_scale, noise_bands;
  int limiter_bands, limiter_gains, interpol_freq, smoothing_mode;
};
#define AAC_ASC_MAX_LD_SBR_HEADERS 4

using AacAsc = struct AacAsc_ {
  int aot;              /* core audioObjectType: AAC_AOT_LC, _ER_LC, _ER_LD or _ER_ELD */
  int sample_rate;      /* core sampling frequency in Hz */
  int channel_config;   /* channelConfiguration, 0 with a PCE or an element-defined layout */
  int has_pce;          /* a program_config_element defines `layout` */
  AacLayout layout;
  int frame_length;     /* 1024 or 960 (frameLengthFlag); 512 or 480 for AAC-LD/ELD */
  int depends_on_core_coder;
  int core_coder_delay;
  int extension_flag;
  AacSignaling signaling; /* how the extension is (to be) written */
  int sbr_present;        /* -1 not signalled, 0 absent, 1 present */
  int ps_present;         /* -1 not signalled, 0 absent, 1 present */
  int ext_sample_rate;    /* extensionSamplingFrequency in Hz, 0 when not signalled */
  /* Error resilient object types: the tool flags of the GASpecificConfig
   * extension or the ELDSpecificConfig. */
  int section_resilience;     /* aacSectionDataResilienceFlag */
  int scalefactor_resilience; /* aacScalefactorDataResilienceFlag */
  int spectral_resilience;    /* aacSpectralDataResilienceFlag */
  /* AAC-ELD: the low-delay SBR signalling of the ELDSpecificConfig. */
  int ld_sbr_present;       /* ldSbrPresentFlag */
  int ld_sbr_sampling_rate; /* ldSbrSamplingRate: 1 when the SBR runs at twice the core rate */
  int ld_sbr_crc;           /* ldSbrCrcFlag */
  int num_ld_sbr_headers;   /* one per SCE or CPE of the channel configuration */
  AacSbrLdHeader ld_sbr_header[AAC_ASC_MAX_LD_SBR_HEADERS];
  /* USAC (audioObjectType 42): the UsacConfig() that follows the channel
   * configuration.  Its usacSamplingFrequency is the output rate and its
   * frame lengths replace `frame_length`; `sample_rate` and
   * `channel_config` above hold the AudioSpecificConfig's own fields.
   * The writer does not express this type. */
  AacUsacConfig usac;
};

/* Fills `asc` with the settings of a plain AAC-LC stream without any
 * extension signalling. */
void aac_asc_init(AacAsc* asc, int sample_rate, int channel_config);

/* Parses an AudioSpecificConfig from a bit reader (the config may start
 * at any bit, as in a StreamMuxConfig; the program config element's byte
 * alignment is relative to the config's start) or from whole bytes.
 * Returns 0, AAC_ERR_DECODE for a malformed config or AAC_ERR_UNSUPPORTED
 * for an object type outside the General Audio family, AAC-ELD and
 * USAC. */
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
