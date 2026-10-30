#ifndef BAANDER_AAC_LATM_H
#define BAANDER_AAC_LATM_H

#include <cstdint>

#include "aac.h"
#include "asc.h"
#include "bitstream.h"

#ifdef __cplusplus
extern "C" {
#endif

/* LATM (ISO/IEC 14496-3 1.7.3): the AudioMuxElement that carries access
 * units with an optional in-band StreamMuxConfig, and the AudioSyncStream
 * (LOAS) that frames one AudioMuxElement behind a sync word and length.
 * One program with one layer, all streams in the same time framing and
 * frameLengthType 0 (the payload length in bytes travels with each
 * frame) are supported, which is what AAC over LATM uses; a second
 * layer whose config is an MPEG Surround one (object type 30 with
 * sacPayloadEmbedding) merges into the first layer's AudioSpecificConfig
 * and its payload, empty when the surround data is embedded in the core's
 * access units, is dropped.  A config of audioMuxVersion 1 is read over
 * its ascLen, whatever its length within the element; one of
 * audioMuxVersion 0 has no length, so a surround config in it carries
 * no extension (its extension loop needs the length to end). */

#define AAC_LOAS_SYNC 0x2B7
#define AAC_LOAS_HEADER_BYTES 3
#define AAC_LOAS_MAX_PAYLOAD 8191 /* 13-bit audioMuxLengthBytes */
#define AAC_LATM_MAX_SUBFRAMES 4 /* numSubFrames + 1: four SBR blocks fill AAC_DECODER_MAX_OUTPUT */
/* Bytes an AudioMuxElement adds to one access unit of `payload_bytes`:
 * the LOAS header, the StreamMuxConfig with the longest config, the
 * 255-byte length chunks and the final alignment. */
#define AAC_LATM_MAX_OVERHEAD(payload_bytes) \
  (AAC_LOAS_HEADER_BYTES + AAC_ASC_MAX_BYTES + 16 + (payload_bytes) / 255 + 2)

using AacLatmConfig = struct AacLatmConfig_ {
  int valid;                /* a StreamMuxConfig has been read or filled in */
  int audio_mux_version;    /* 0 or 1 (audioMuxVersionA is 0) */
  int tara_buffer_fullness; /* audioMuxVersion 1 */
  int num_sub_frames;       /* numSubFrames: access units per element minus one */
  int frame_length_type;    /* 0 */
  int latm_buffer_fullness; /* 0xFF for a variable rate */
  int other_data_present;
  int other_data_len_bits;
  int crc_check_present;
  int crc_check_sum;
  int num_layers;           /* 1, or 2 with the MPEG Surround config in the second */
  AacAsc asc;
};

/* The configuration the muxer writes for an AudioSpecificConfig: version
 * `audio_mux_version`, one access unit per element, no other data, no
 * CRC. */
void aac_latm_config_init(AacLatmConfig* cfg, const AacAsc* asc, int audio_mux_version);

/* StreamMuxConfig() (Table 1.42). */
int aac_latm_read_stream_mux_config(AacBitReader* r, AacLatmConfig* cfg);
int aac_latm_write_stream_mux_config(AacBitWriter* w, const AacLatmConfig* cfg);

/* The byte range of one access unit the demuxer copied out of an element. */
using AacLatmPayload = struct AacLatmPayload_ {
  int offset;
  int size;
};

/* Parses one AudioMuxElement(mux_config_present).  A StreamMuxConfig in
 * the element replaces `cfg`; without one `cfg` must already be valid
 * (AAC_ERR_STATE otherwise).  The access units are copied byte-aligned
 * into `out` (the payloads of an element are not aligned in general),
 * and `payloads` receives their ranges; `used_bytes`, when given, the
 * element's length.  Returns the number of access units, or a negative
 * AacError (AAC_ERR_OVERFLOW when `out` or `payloads` is too small). */
int aac_latm_read_mux_element(const uint8_t* data, int size, int mux_config_present,
                              AacLatmConfig* cfg, uint8_t* out, int out_size,
                              AacLatmPayload* payloads, int max_payloads, int* used_bytes);

/* Writes one AudioMuxElement carrying one access unit.  With
 * mux_config_present the element begins with useSameStreamMux
 * (`use_same_stream_mux`) and, when that is 0, the StreamMuxConfig of
 * `cfg`.  Returns the element's length in bytes or AAC_ERR_OVERFLOW. */
int aac_latm_write_mux_element(const AacLatmConfig* cfg, int mux_config_present,
                               int use_same_stream_mux, const uint8_t* au, int au_size,
                               uint8_t* out, int out_size);

/* One AudioSyncStream frame: syncword, audioMuxLengthBytes and
 * AudioMuxElement(1) with the StreamMuxConfig.  Returns its length. */
int aac_loas_write_frame(const AacLatmConfig* cfg, int use_same_stream_mux, const uint8_t* au,
                         int au_size, uint8_t* out, int out_size);

/* Whether `data` starts with the LOAS syncword; the element length
 * (audioMuxLengthBytes) follows it. */
int aac_loas_is_sync(const uint8_t* data, int size);
int aac_loas_payload_length(const uint8_t* data);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_LATM_H */
