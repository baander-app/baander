#ifndef BAANDER_AAC_USAC_ENC_H
#define BAANDER_AAC_USAC_ENC_H

#include <cstdint>

#include "aac.h"
#include "bitstream.h"
#include "ics.h"
#include "usac.h"
#include "usac_fd.h"

#ifdef __cplusplus
extern "C" {
#endif

/* The USAC (xHE-AAC, ISO/IEC 23003-3) encoder side: the UsacConfig()
 * writer that mirrors the decoder's reader, the spectral noiseless
 * encoder that mirrors the arithmetic decoder, and the frame writer that
 * turns the AAC-LC encoder's analysis and quantisation of a frame into
 * UsacFrame() with the frequency-domain core.  The encoder state of
 * encoder.h owns one AacUsacEncoder when its object type is USAC. */

/* ── UsacConfig() (usac_enc_config.cpp) ───────────────────────── */

/* escapedValue(nBits1, nBits2, nBits3) of ISO/IEC 23003-3 5.2. */
void aac_usac_write_escaped(AacBitWriter* w, unsigned value, int bits1, int bits2, int bits3);

/* Writes UsacConfig() from the fields of `c` (not its raw bits): the
 * sampling frequency, coreSbrFrameLengthIndex, the channel configuration
 * (index 1 to 20, or 0 with the bsOutputChannelPos list), the element
 * list with each element's configuration, and the configuration
 * extensions.  Returns the bits written or AAC_ERR_INVALID_ARG for a
 * configuration the writer cannot express. */
int aac_usac_config_write(const AacUsacConfig* c, AacBitWriter* w);

/* ── the spectral noiseless encoder (usac_arith_enc.cpp) ──────── */

/* Encodes arith_data() of one window of `n` lines whose lowest `lg`
 * lines are x[0..lg) (the rest must be zero), with the context model of
 * aac_usac_arith_decode, followed by one sign bit per nonzero line, and
 * leaves the context for the next window.  `reset` is arith_reset_flag
 * for the first window of the frame.  With `w` null nothing is written
 * and only the bits are counted.  Returns the number of bits, or
 * AAC_ERR_INVALID_ARG for a magnitude the coder cannot carry. */
int aac_usac_arith_encode(AacUsacArithContext* c, int reset, int n, int lg, const int* x,
                          AacBitWriter* w);

/* ── the frame writer (usac_enc.cpp) ───────────────────────────── */

struct AacEncoderState_;
struct AacEncElement_;

#define AAC_USAC_ENC_MAX_CONFIG_EXTS 4
/* The largest access unit: the bit budget of the channels, written twice
 * at an immediate playout frame (the frame itself and the pre-roll
 * access unit it carries) plus the embedded configuration. */
#define AAC_USAC_ENC_MAX_AU_BYTES(channels) \
  (2 * (6144 / 8) * (channels) + AAC_USAC_MAX_CONFIG_BYTES + 32)

/* Per channel: the arithmetic coder context after the last written
 * frame, and the scale factors and noise filling parameters of the
 * current frame as the bitstream carries them. */
using AacUsacEncChannel = struct AacUsacEncChannel_ {
  AacUsacArithContext arith;
  int sf[AAC_MAX_BANDS]; /* every band below max_sfb, group * num_swb + band */
  int global_gain;
  int noise_level, noise_offset;
};

using AacUsacEncoder = struct AacUsacEncoder_ {
  AacUsacEncoderOptions opt;
  int interval;    /* frames from one independent frame to the next */
  int frame_index; /* frames since the start of the stream */
  int indep;       /* the frame being coded has usacIndependencyFlag set */
  int ipf;         /* ... and carries the audio pre-roll */
  AacUsacEncChannel ch[AAC_MAX_CHANNELS];
  int num_config_exts;
  AacUsacConfigExt config_ext[AAC_USAC_ENC_MAX_CONFIG_EXTS];
  /* The last written access unit without its pre-roll element, which
   * the next immediate playout frame carries as its pre-roll. */
  uint8_t* last_au;
  int last_au_len;
  int last_au_cap;
  uint8_t* scratch; /* the channel elements of the frame being written */
  int scratch_cap;
  uint8_t* preroll; /* the AudioPreRoll() payload of the frame being written */
  int preroll_cap;
  int preroll_bytes; /* bytes of the pre-roll extension in the last written frame */
  /* Bits of the element stream behind its last range coder flush: the
   * sign bits of the last window and fac_data_present.  The fill element
   * pads a short tail so that a decoder's look-ahead stays in the frame. */
  int tail_bits;
};

AacUsacEncoder* aac_usac_enc_create(void);
void aac_usac_enc_destroy(AacUsacEncoder* u);

/* Applies the options, with the defaults for every field outside its
 * range (noise filling on, pre-roll on, one second between independent
 * frames, no stream id); `sample_rate` turns the default interval into
 * frames.  Before the first frame only. */
void aac_usac_enc_set_options(AacUsacEncoder* u, const AacUsacEncoderOptions* opt,
                              int sample_rate);
/* Keeps a configuration extension (usacConfigExtType, payload bytes)
 * for the UsacConfig; a second one of the same type replaces the first.
 * AAC_ERR_INVALID_ARG when it does not fit, AAC_ERR_OVERFLOW when the
 * list is full. */
int aac_usac_enc_set_config_ext(AacUsacEncoder* u, int type, const uint8_t* data, int len);
/* Forgets every frame: the next frame is the first of a stream. */
void aac_usac_enc_reset(AacUsacEncoder* u, int channels);

/* The UsacConfig() of the encoder's stream. */
void aac_usac_enc_config(const struct AacEncoderState_* s, AacUsacConfig* c);
/* Largest access unit the encoder's stream can produce. */
int aac_usac_enc_max_au_bytes(const struct AacEncoderState_* s);
/* TNS_MAX_BANDS of the USAC core for a sampling frequency index. */
int aac_usac_enc_tns_max_bands(int rate_index, int is_short);

/* Decides whether the frame about to be coded is independent and whether
 * it carries the pre-roll; called once per frame before the rate loop. */
void aac_usac_enc_begin_frame(struct AacEncoderState_* s);
/* The scale factors of every band (zero bands included) and the noise
 * filling parameters of the element's channels for the current
 * quantisation, with max_sfb widened to the noise filled range; returns
 * the bits of the scale factors, the M/S mask and the arithmetic coded
 * spectra.  Called after every quantisation of the rate loop. */
int aac_usac_enc_element_bits(struct AacEncoderState_* s, struct AacEncElement_* el);
/* Bits of the frame apart from what aac_usac_enc_element_bits counts:
 * the transport estimate, the flags, ics_info, TNS, and the alignment. */
int aac_usac_enc_overhead_bits(const struct AacEncoderState_* s);
/* Writes UsacFrame() of the current quantisation behind the ADTS header
 * space of the encoder's output buffer and returns the length with that
 * space, or AAC_ERR_OVERFLOW. */
int aac_usac_enc_write_frame(struct AacEncoderState_* s);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_USAC_ENC_H */
