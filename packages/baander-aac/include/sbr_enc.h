#ifndef BAANDER_AAC_SBR_ENC_H
#define BAANDER_AAC_SBR_ENC_H

#include <cstdint>

#include "bitstream.h"
#include "ps_enc.h"
#include "qmf.h"
#include "sbr.h"
#include "usac.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Spectral band replication encoder (ISO/IEC 14496-3 4.6.18, with the
 * structure of 3GPP TS 26.404) for one SCE or CPE of an HE-AAC stream.
 * The element's channels arrive as 2048 (or 1920, for 960-sample core
 * frames) samples per frame at the full rate; the encoder analyses them
 * with the 64-band QMF bank, hands the core encoder the lower half of
 * the spectrum resampled to half rate,
 * and describes the upper half as envelope energies, noise floors and
 * inverse filtering levels per frame.  For HE-AAC v2 the stereo pair is
 * downmixed to one channel and its parametric stereo data is added to
 * the SBR extension.  For xHE-AAC (sbr_enc_usac.cpp) the same analysis
 * is written as UsacSbrData() at the 2:1 ratio, and a stereo pair may
 * be downmixed by the MPS 212 encoder instead. */

#define AAC_SBR_ENC_RING 128   /* QMF slots kept per channel */
#define AAC_SBR_ENC_MAX_BYTES 270 /* largest fill element payload */

/* The envelope grid of one frame (4.6.18.3.3). */
using AacSbrGrid = struct AacSbrGrid_ {
  int frame_class;
  int num_env;
  int t_env[AAC_SBR_MAX_ENV + 1]; /* borders in envelope slots, 0..19 (0..18 with 15 slots) */
  int freq_res[AAC_SBR_MAX_ENV + 1];
  int abs_bord_0, abs_bord_1;
  int num_rel_lead, rel_lead[3];
  int num_rel_trail, rel_trail[3];
  int bs_pointer;
  int tran_env; /* envelope index of the transient, or -1 */
  int short_env; /* envelope whose end is trimmed before a transient, or -1 */
  int num_noise;
  int t_q[3];
  int amp_res;
};

using AacSbrEncChannel = struct AacSbrEncChannel_ {
  AacQmfAnalysis analysis;  /* 64 bands at the full rate */
  AacQmfSynthesis synthesis; /* 32 bands: the core signal */
  float W[AAC_SBR_ENC_RING][64][2]; /* ring of analysis slots */
  /* transient detector */
  float hf_energy[AAC_SBR_ENC_RING];
  int tran_next; /* transient slot of the next frame relative to its first, or -1 */
  /* previous frame, for delta coding and the grid */
  int start_bord;  /* envelope border carried into this frame */
  int prev_num_env;
  int prev_freq_res;
  int prev_env_q[AAC_SBR_MAX_BANDS];
  int prev_noise_q[AAC_SBR_MAX_NOISE_BANDS];
  int prev_invf[AAC_SBR_MAX_NOISE_BANDS];
  int have_prev; /* delta coding in time is possible */
  /* this frame's data */
  AacSbrGrid grid;
  int env_q[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  int noise_q[2][AAC_SBR_MAX_NOISE_BANDS];
  int invf[AAC_SBR_MAX_NOISE_BANDS];
  int df_env[AAC_SBR_MAX_ENV];
  int df_noise[2];
};

using AacSbrEncoder = struct AacSbrEncoder_ {
  int sample_rate; /* full rate */
  int channels;    /* of the element: 1 or 2 */
  int bitrate;     /* of the element */
  int ps;          /* parametric stereo: two inputs, one coded channel */
  int frame_length; /* core samples per frame: 1024 or 960 */
  int slots;        /* QMF slots per frame: 32 or 30 */
  int time_slots;   /* envelope time slots per frame: 16 or 15 */
  AacSbrFreq freq;
  int amp_res_header;
  int limiter_gains, interpol_freq, smoothing_mode;
  float noise_max; /* largest noise floor ratio */
  int header_interval; /* frames between headers */
  int frames_since_header;
  int frame_count;
  int pos; /* ring position of the next analysis slot */
  int source_band[64]; /* QMF band each SBR band is copied from */
  AacSbrEncChannel ch[2]; /* with PS or MPS 212: [0] the downmix, [1] the right channel */
  float left[AAC_SBR_ENC_RING][64][2]; /* the left channel with PS or MPS 212 */
  AacPsEncoder ps_enc;
  /* xHE-AAC: the payload is UsacSbrData() of the element (its exact bit
   * count in payload_bits), written with the frame's usacIndependencyFlag
   * in usac_indep; with `mps` the stereo input is downmixed by the MPS
   * 212 encoder (owned by the caller) and coded as one channel. */
  int usac;
  int usac_indep;
  struct AacMps212Encoder_* mps;
  /* the extension payload of the frame */
  uint8_t payload[AAC_SBR_ENC_MAX_BYTES];
  int payload_bits;
};

/* Chooses the SBR configuration for the full sample rate, the element's
 * channel count and bitrate (bits per second for the element) and
 * prepares the state for core frames of frame_length samples (1024 or
 * 960); `ps` codes a stereo pair as one channel with parametric stereo.
 * Returns 0 or AAC_ERR_UNSUPPORTED. */
int aac_sbr_enc_init(AacSbrEncoder* s, int sample_rate, int channels, int bitrate, int ps,
                     int frame_length);

/* Returns the state to that after creation. */
void aac_sbr_enc_reset(AacSbrEncoder* s);

/* Analyses one frame of 2 * frame_length full-rate samples per input
 * channel at full scale 32768, writes the half-rate core signal
 * (frame_length samples per coded channel; one channel with PS) and
 * prepares the extension payload of the frame the core encoder emits for
 * this input.  `in` may be NULL for silence. */
void aac_sbr_enc_process(AacSbrEncoder* s, const float* const* in, float* const* core);

/* The QMF chain of a channel without SBR data (an LFE): its lower half
 * at half rate, with the same delay as the coded channels. */
using AacSbrDownsampler = struct AacSbrDownsampler_ {
  AacQmfAnalysis analysis;
  AacQmfSynthesis synthesis;
  int slots; /* QMF slots per frame: 32 or 30 */
};
void aac_sbr_downsampler_init(AacSbrDownsampler* d, int frame_length);
/* One frame of full-rate samples (NULL for silence) to half as many at
 * half rate. */
void aac_sbr_downsample(AacSbrDownsampler* d, const float* in, float* out);

/* Bits the fill element of this frame takes, including its header. */
int aac_sbr_enc_bits(const AacSbrEncoder* s);

/* Writes the fill element carrying this frame's extension. */
void aac_sbr_enc_write(const AacSbrEncoder* s, AacBitWriter* w);

/* ── xHE-AAC (sbr_enc_usac.cpp) ─────────────────────────────────── */

/* Prepares the encoder for UsacSbrData() at the 2:1 ratio with 1024-line
 * core frames: `channels` input channels (1 or 2), coded as one channel
 * through the MPS 212 encoder `mps` when it is given (stereo input only).
 * Returns 0 or AAC_ERR_UNSUPPORTED. */
int aac_sbr_enc_init_usac(AacSbrEncoder* s, int sample_rate, int channels, int bitrate,
                          struct AacMps212Encoder_* mps);

/* SbrConfig() of the stream: harmonic SBR, inter-TES and PVC off, the
 * encoder's header as the default header. */
void aac_sbr_enc_usac_config(const AacSbrEncoder* s, AacUsacSbrConfig* c);

/* The frame's UsacSbrData() into the payload, and the MPS 212 analysis
 * of the frame when the encoder has one; called by aac_sbr_enc_process
 * after the envelope data of every coded channel is known.  `base` is
 * the ring position of the frame's first slot, `prev_*` the values the
 * decoder holds before the frame. */
void aac_sbr_enc_usac_frame(AacSbrEncoder* s, int base, const int (*prev_env)[AAC_SBR_MAX_BANDS],
                            const int* prev_res, const int (*prev_noise)[AAC_SBR_MAX_NOISE_BANDS]);

/* The payload bits of the frame into the element's bitstream. */
void aac_sbr_enc_usac_write(const AacSbrEncoder* s, AacBitWriter* w);

/* UsacSbrData() of an independent frame describing silence: the header
 * and one flat envelope at the lowest level, for a silent access unit. */
void aac_sbr_enc_usac_write_silent(const AacSbrEncoder* s, AacBitWriter* w);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_SBR_ENC_H */
