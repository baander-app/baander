#ifndef BAANDER_AAC_USAC_ENC_H
#define BAANDER_AAC_USAC_ENC_H

#include <cstdint>

#include "aac.h"
#include "bitstream.h"
#include "ics.h"
#include "mps212_enc.h"
#include "usac.h"
#include "usac_fd.h"

#ifdef __cplusplus
extern "C" {
#endif

/* The USAC (xHE-AAC, ISO/IEC 23003-3) encoder side: the UsacConfig()
 * writer that mirrors the decoder's reader, the spectral noiseless
 * encoder that mirrors the arithmetic decoder, and the frame writer that
 * turns the AAC-LC encoder's analysis and quantisation of a frame into
 * UsacFrame() with the frequency-domain core, followed in each element
 * by the enhanced SBR data of the SBR encoder (sbr_enc_usac.cpp) and
 * the MPS 212 data of a parametric stereo pair (mps212_enc.cpp).  The
 * encoder state of encoder.h owns one AacUsacEncoder when its object
 * type is USAC. */

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
/* Pre-roll access units an immediate playout frame carries: the one
 * frame the core's overlap needs, two when the SBR's filterbank and
 * envelope history have to be warm too. */
#define AAC_USAC_ENC_PREROLL_FRAMES 1
#define AAC_USAC_ENC_PREROLL_FRAMES_SBR 2
/* The largest access unit of a stream without its pre-roll: the bit
 * budget of the core channels, the SBR data of up to `channels` SBR
 * channels and the MPS 212 data. */
#define AAC_USAC_ENC_MAX_AU_BYTES(channels) \
  ((6144 / 8) * (channels) + AAC_SBR_ENC_MAX_BYTES * (channels) + AAC_MPS212_ENC_MAX_BYTES)
/* ... and with the pre-roll of an immediate playout frame: the embedded
 * configuration and the previous access units. */
#define AAC_USAC_ENC_MAX_IPF_BYTES(channels) \
  ((1 + AAC_USAC_ENC_PREROLL_FRAMES_SBR) * AAC_USAC_ENC_MAX_AU_BYTES(channels) + \
   AAC_USAC_MAX_CONFIG_BYTES + 32)

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
  int frame_index; /* frames since the start of the stream */
  int indep;       /* the frame being coded has usacIndependencyFlag set */
  int ipf;         /* ... and carries the audio pre-roll */
  /* The independent frame schedule: the index of the next immediate
   * playout frame (the next independent frame when the pre-roll is off),
   * which opens a segment, and whether aac_encoder_request_independent_frame
   * asked for one before the frame being coded. */
  int next_ipf;
  int requested;
  /* The segment size ceiling: the bytes of the access units written
   * since the segment's first frame, and the bytes the pre-roll of the
   * frame being coded will add to them. */
  int segment_used;
  int segment_reserved;
  /* The last written frame, for aac_encoder_last_frame_info: its index,
   * flags and access unit bytes, and the bytes of its transport frame,
   * which the API layer fills in after wrapping it. */
  int has_last_frame;
  int last_frame_index;
  int last_indep;
  int last_preroll_bytes;
  int last_au_bytes;
  int last_frame_bytes;
  /* The tools in use, resolved from the options and the bitrate by the
   * encoder state (encoder.cpp): enhanced SBR at the 2:1 ratio, and MPS
   * 212 coding a stereo input as one core channel through `mps`. */
  int sbr_active;
  int mps_active;
  AacMps212Encoder mps;
  int preroll_frames; /* access units an immediate playout frame carries */
  AacUsacEncChannel ch[AAC_MAX_CHANNELS];
  int num_config_exts;
  AacUsacConfigExt config_ext[AAC_USAC_ENC_MAX_CONFIG_EXTS];
  /* The last written access units without their pre-roll element,
   * oldest first, which the next immediate playout frame carries as its
   * pre-roll. */
  uint8_t* last_au[AAC_USAC_MAX_PREROLL_FRAMES];
  int last_au_len[AAC_USAC_MAX_PREROLL_FRAMES];
  int last_au_cap;
  uint8_t* scratch; /* the channel elements of the frame being written */
  int scratch_cap;
  uint8_t* preroll; /* the AudioPreRoll() payload of the frame being written */
  int preroll_cap;
  int preroll_len;   /* its length, prepared when the frame begins */
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
 * frames with no distance bound and no segment ceiling, no stream id,
 * SBR and MPS 212 by bitrate).  Before the first frame only.  The SBR
 * and MPS 212 choices are resolved by the encoder state
 * (aac_usac_enc_choose_tools), which configures the core rate and the
 * element layout for them; the schedule's interval is turned into
 * frames from the output rate and frame size as each frame begins. */
void aac_usac_enc_set_options(AacUsacEncoder* u, const AacUsacEncoderOptions* opt);
/* The frames between immediate playout frames the options give at an
 * output rate and frame size: the samples, milliseconds or frames
 * setting in that order of precedence, rounded up to whole frames, one
 * second when none is set, and never more than the distance bound. */
int aac_usac_enc_interval_frames(const AacUsacEncoderOptions* opt, int output_rate,
                                 int output_frame);
/* Asks for an independent frame on demand: the next frame begun is
 * independent and the immediate playout frame follows it by the
 * pre-roll's frame count, unless one is due sooner. */
void aac_usac_enc_request_independent(AacUsacEncoder* u);
/* The tools the options and the bitrate ask for: SBR below 64 kbit/s
 * per input channel unless its option decides, MPS 212 for a stereo
 * input only when its option asks for it, and then with SBR, which it
 * needs (never with SBR turned off); SBR only when the input rate halves
 * to a rate with core tables. */
void aac_usac_enc_choose_tools(const AacUsacEncoder* u, int input_rate, int input_channels,
                               int bitrate, int* sbr, int* mps);
/* Keeps a configuration extension (usacConfigExtType, payload bytes)
 * for the UsacConfig; a second one of the same type replaces the first.
 * AAC_ERR_INVALID_ARG when it does not fit, AAC_ERR_OVERFLOW when the
 * list is full. */
int aac_usac_enc_set_config_ext(AacUsacEncoder* u, int type, const uint8_t* data, int len);
/* Forgets every frame: the next frame is the first of a stream. */
void aac_usac_enc_reset(AacUsacEncoder* u, int channels);

/* The UsacConfig() of the encoder's stream. */
void aac_usac_enc_config(const struct AacEncoderState_* s, AacUsacConfig* c);
/* Largest access unit the encoder's stream can produce, pre-roll
 * included. */
int aac_usac_enc_max_au_bytes(const struct AacEncoderState_* s);
/* TNS_MAX_BANDS of the USAC core for a sampling frequency index. */
int aac_usac_enc_tns_max_bands(int rate_index, int is_short);

/* Decides whether the frame about to be coded is independent and whether
 * it carries the pre-roll, prepares the pre-roll payload, and opens a
 * new segment at an immediate playout frame; called once per frame
 * before the rate loop. */
void aac_usac_enc_begin_frame(struct AacEncoderState_* s);
/* The bit ceiling of the frame being coded under the segment size
 * ceiling, as the rate loop counts bits (the transport estimate
 * included): the segment's remaining budget spread over its remaining
 * frames, never below what the frame's side information needs.  0 when
 * no ceiling is set. */
int aac_usac_enc_frame_bit_ceiling(const struct AacEncoderState_* s);
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
