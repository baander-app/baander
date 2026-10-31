#ifndef BAANDER_AAC_SBR_LD_ENC_H
#define BAANDER_AAC_SBR_LD_ENC_H

#include <cstdint>

#include "bitstream.h"
#include "qmf_ld.h"
#include "sbr.h"
#include "sbr_ld.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Low-delay SBR encoder of AAC-ELD (ISO/IEC 14496-3 4.6.20) for one SCE
 * or CPE, the counterpart of sbr_ld.h.  Each call takes one frame of
 * input per channel at the output rate (twice the core frame length in
 * dual rate, the core frame length in single rate), analyses it with the
 * CLDFB, hands back the core signal of the frame (the lower 32 subbands
 * resampled to the core rate in dual rate, the input through the 32-band
 * banks in single rate; the core encoder applies its own lowpass at the
 * crossover) and prepares the frame's sbr_data(): a FIXFIX grid, or an
 * LDTRAN grid at the slot of a transient the detector finds, envelope
 * energies and noise floors estimated on that grid, inverse filtering
 * levels, and delta coding in time or frequency, whichever is cheaper.
 * The header is not sent per frame: aac_sbr_ld_enc_header() gives the
 * ld_sbr_header the audio specific config carries.
 *
 * Alignment: the data of a frame describes the slots the decoder's
 * analysis of the core output of that frame sees, so the encoder needs
 * the delay between the core encoder's input and the core decoder's
 * output of the same frame (`core_delay`, in core samples, 0 when the
 * core is transparent). */

#define AAC_SBR_LD_ENC_RING 128 /* analysis slots kept per channel */

/* The payload buffer holds the largest sbr_data() the writer can produce,
 * so no frame is ever cut short.  The longest code of the envelope and
 * noise floor Huffman tables (aac_sbr_huff_env_level_15_f and _30_f) is
 * 20 bits; one channel then takes at most an LDTRAN grid with
 * AAC_SBR_LD_MAX_ENV envelopes (1 + 4 bits and one per envelope), a delta
 * flag per envelope and per noise floor, two bits of inverse filtering
 * per noise band, every band of every envelope and noise floor at the
 * longest code (the start value of a frequency-coded run, 7 or 5 bits, is
 * shorter than the code it stands in for) and the harmonic flag; a
 * channel pair adds bs_data_extra, bs_coupling and bs_extended_data. */
#define AAC_SBR_LD_ENC_MAX_CODE_BITS 20
#define AAC_SBR_LD_ENC_CHANNEL_BITS                                                      \
  (5 + AAC_SBR_LD_MAX_ENV + AAC_SBR_LD_MAX_ENV + 2 + 2 * AAC_SBR_MAX_NOISE_BANDS +       \
   AAC_SBR_LD_MAX_ENV * AAC_SBR_MAX_BANDS * AAC_SBR_LD_ENC_MAX_CODE_BITS +               \
   2 * (5 + AAC_SBR_MAX_NOISE_BANDS * AAC_SBR_LD_ENC_MAX_CODE_BITS) + 1)
#define AAC_SBR_LD_ENC_MAX_BYTES ((3 + 2 * AAC_SBR_LD_ENC_CHANNEL_BITS + 7) / 8)

using AacSbrLdEncChannel = struct AacSbrLdEncChannel_ {
  AacCldfbAnalysis analysis;   /* 64 bands (dual rate) or 32 (single rate) */
  AacCldfbSynthesis synthesis; /* 32 bands: the core signal */
  float W[AAC_SBR_LD_ENC_RING][64][2];
  float hf_energy[AAC_SBR_LD_ENC_RING]; /* high-band energy per slot */
  /* the previous frame, for delta coding */
  int prev_env_q[AAC_SBR_MAX_BANDS];
  int prev_freq_res;
  int prev_noise_q[AAC_SBR_MAX_NOISE_BANDS];
  int prev_invf[AAC_SBR_MAX_NOISE_BANDS];
  int have_prev;
  /* this frame */
  AacSbrLdGrid grid;
  int amp_res;
  int env_q[AAC_SBR_LD_MAX_ENV][AAC_SBR_MAX_BANDS];
  int noise_q[2][AAC_SBR_MAX_NOISE_BANDS];
  int invf[AAC_SBR_MAX_NOISE_BANDS];
  int df_env[AAC_SBR_LD_MAX_ENV];
  int df_noise[2];
};

using AacSbrLdEncoder = struct AacSbrLdEncoder_ {
  int core_sample_rate;
  int frame_length; /* core samples per frame: 512 or 480 */
  int slots;
  int dual_rate;
  int channels; /* of the element: 1 or 2 */
  int core_delay;
  int offset; /* analysis slots between the encoder's and the decoder's slot of the same signal */
  AacSbrLdHeader header;
  AacSbrFreq freq;
  float noise_max; /* largest noise floor ratio */
  int source_band[64]; /* QMF band each SBR band is copied from */
  int refresh_interval; /* frames between frames coded without time deltas */
  int frames_since_refresh;
  long long slot_count; /* analysis slots so far */
  int frame_count;
  AacSbrLdEncChannel ch[2];
  uint8_t payload[AAC_SBR_LD_ENC_MAX_BYTES];
  int payload_bits;
};

/* Configures the encoder for a core sample rate, 512 or 480 core samples
 * per frame, dual or single rate, 1 or 2 channels, the element's bitrate
 * (bits per second, which sets the crossover) and the core codec's
 * delay.  `header` may be NULL to derive the band layout from the
 * bitrate, or name the header to use.  Returns 0, AAC_ERR_INVALID_ARG or
 * AAC_ERR_UNSUPPORTED. */
int aac_sbr_ld_enc_init(AacSbrLdEncoder* s, int core_sample_rate, int frame_length, int dual_rate,
                        int channels, int bitrate, int core_delay, const AacSbrLdHeader* header);

/* Returns the state to that after creation. */
void aac_sbr_ld_enc_reset(AacSbrLdEncoder* s);

/* The header the audio specific config carries. */
const AacSbrLdHeader* aac_sbr_ld_enc_header(const AacSbrLdEncoder* s);

/* Input samples per channel of one call. */
int aac_sbr_ld_enc_input_size(const AacSbrLdEncoder* s);

/* Delay of the core signal behind the input, in input samples: core
 * sample i carries input sample 2 i - aac_sbr_ld_enc_delay() in dual
 * rate, i - aac_sbr_ld_enc_delay() in single rate.  With the decoder's
 * aac_sbr_ld_delay() and the core codec's delay it makes the delay of
 * the whole chain. */
int aac_sbr_ld_enc_delay(const AacSbrLdEncoder* s);

/* Analyses one frame (full scale 1.0; `in_right` ignored for one
 * channel, NULL inputs read as silence), writes the frame's core signal
 * (frame_length samples per channel) and prepares its sbr_data(). */
void aac_sbr_ld_enc_process(AacSbrLdEncoder* s, const float* in_left, const float* in_right,
                            float* core_left, float* core_right);

/* One frame of silence through the encoder, for the frames that drain
 * the delay at the end of a stream. */
void aac_sbr_ld_enc_flush(AacSbrLdEncoder* s, float* core_left, float* core_right);

/* Bits of the frame's sbr_data(). */
int aac_sbr_ld_enc_bits(const AacSbrLdEncoder* s);

/* Writes the frame's sbr_data() (without CRC). */
void aac_sbr_ld_enc_write(const AacSbrLdEncoder* s, AacBitWriter* w);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_SBR_LD_ENC_H */
