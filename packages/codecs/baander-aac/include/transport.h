#ifndef BAANDER_AAC_TRANSPORT_H
#define BAANDER_AAC_TRANSPORT_H

#include <cstdint>

#include "aac.h"
#include "asc.h"
#include "latm.h"

#ifdef __cplusplus
extern "C" {
#endif

/* The transport layer between the public API and the raw data block
 * codecs: the decoder side owns the input FIFO, finds ADTS and LOAS
 * frames in it, demultiplexes LATM elements, verifies the ADTS CRC and
 * applies an AudioSpecificConfig; the encoder side wraps each raw data
 * block in the chosen transport and writes the config. */

/* Bytes the decoder FIFO holds: 64 KiB plus one largest frame. */
#define AAC_TRANSPORT_FIFO_BYTES (65536 + 8192)

#if defined(BAAC_AAC_HAVE_DECODER)
struct AacDecoderState_;
using AacDecoderTransport = struct AacDecoderTransport_;

AacDecoderTransport* aac_decoder_transport_create(void);
void aac_decoder_transport_destroy(AacDecoderTransport* t);

/* Selects the transport and empties the FIFO. */
int aac_decoder_transport_set(AacDecoderTransport* t, AacTransport transport);
/* Applies an AudioSpecificConfig to the decoder state and keeps it for raw
 * access units and LATM without in-band configuration. */
int aac_decoder_transport_set_config(struct AacDecoderState_* s, AacDecoderTransport* t,
                                     const uint8_t* asc, int size);
/* Restores what the configuration presets (explicit SBR and PS modes)
 * after the decoder state was reset. */
void aac_decoder_transport_reapply(struct AacDecoderState_* s, const AacDecoderTransport* t);

/* The streaming interface: bytes in, the next complete frame out.  Each
 * feed of a packet transport (LATM, raw access units) is one packet. */
int aac_decoder_transport_feed(AacDecoderTransport* t, const uint8_t* data, int size);
int aac_decoder_transport_pending(const AacDecoderTransport* t);
int aac_decoder_transport_decode_next(struct AacDecoderState_* s, AacDecoderTransport* t,
                                      float* pcm, int pcm_size);

/* Decodes one whole frame of the transport (detected from the data when
 * the transport is AAC_TRANSPORT_DETECT) without the FIFO. */
int aac_decoder_transport_decode(struct AacDecoderState_* s, AacDecoderTransport* t,
                                 const uint8_t* data, int size, float* pcm, int pcm_size);
#endif /* BAAC_AAC_HAVE_DECODER */

#if defined(BAAC_AAC_HAVE_ENCODER)
struct AacEncoderState_;
using AacEncoderTransport = struct AacEncoderTransport_;

AacEncoderTransport* aac_encoder_transport_create(void);
void aac_encoder_transport_destroy(AacEncoderTransport* t);

/* The stream settings; each is refused with AAC_ERR_STATE once a frame
 * has been encoded and until the stream is flushed. */
int aac_encoder_transport_set(AacEncoderTransport* t, AacTransport transport);
int aac_encoder_transport_set_signaling(AacEncoderTransport* t, AacSignaling signaling);
int aac_encoder_transport_set_crc(AacEncoderTransport* t, int enabled);
void aac_encoder_transport_flushed(AacEncoderTransport* t);
/* Whether the transport delivers packets (LATM elements, raw access
 * units) rather than a self-synchronising stream. */
int aac_encoder_transport_is_packet(const AacEncoderTransport* t);

/* The AudioSpecificConfig of the encoder's stream. */
int aac_encoder_transport_asc(const struct AacEncoderState_* s, const AacEncoderTransport* t,
                              AacAsc* asc);
/* Largest output frame of the transport. */
int aac_encoder_transport_max_bytes(const struct AacEncoderState_* s,
                                    const AacEncoderTransport* t);
/* Wraps the ADTS frame of `frame_len` bytes in the encoder's output buffer
 * into the transport and writes it to `out`; returns its length or
 * AAC_ERR_OVERFLOW. */
int aac_encoder_transport_wrap(struct AacEncoderState_* s, AacEncoderTransport* t, int frame_len,
                               uint8_t* out, int out_size);
#endif /* BAAC_AAC_HAVE_ENCODER */

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_TRANSPORT_H */
