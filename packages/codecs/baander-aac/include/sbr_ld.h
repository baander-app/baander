#ifndef BAANDER_AAC_SBR_LD_H
#define BAANDER_AAC_SBR_LD_H

#include <cstdint>

#include "bitstream.h"
#include "qmf_ld.h"
#include "sbr.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Low-delay spectral band replication decoder of AAC-ELD (ISO/IEC 14496-3
 * 4.6.20 with the SBR tools of 4.6.18) for one SCE or CPE.  The SBR
 * header travels in the audio specific config (ld_sbr_header), so the
 * state is configured once from it; each frame then carries sbr_data()
 * after the channel elements, with an optional CRC in front.  A frame of
 * 512 or 480 core samples per channel is 16 or 15 CLDFB slots, and the
 * envelope grid counts in those slots: a frame is either FIXFIX with 1,
 * 2 or 4 equal envelopes or LDTRAN with a transient position that splits
 * it into up to three envelopes.  Envelopes never reach into the next
 * frame.  The core samples go through the 32-band CLDFB analysis, the
 * high band is generated and adjusted as in SBR, and the 64-band CLDFB
 * synthesis gives twice the core samples at twice the rate (dual rate),
 * or the 32-band synthesis gives the same number of samples at the core
 * rate (single rate), the SBR bands above the core rate's Nyquist then
 * being dropped. */

#define AAC_SBR_LD_MAX_SLOTS 16
#define AAC_SBR_LD_HISTORY 8 /* slots of the previous frame the generator keeps */
#define AAC_SBR_LD_ADJ 2     /* slots of lead-in in the generator's buffers */
#define AAC_SBR_LD_BUF (AAC_SBR_LD_MAX_SLOTS + AAC_SBR_LD_HISTORY)
#define AAC_SBR_LD_MAX_ENV 4
#define AAC_SBR_LD_FRAME_FIXFIX 0
#define AAC_SBR_LD_FRAME_LDTRAN 1

/* sbr_header() as carried in ld_sbr_header() of the audio specific
 * config; the fields the header leaves out take the standard's defaults. */
using AacSbrLdHeader = struct AacSbrLdHeader_ {
  int amp_res;
  int start_freq, stop_freq, xover_band;
  int freq_scale, alter_scale, noise_bands;
  int limiter_bands, limiter_gains, interpol_freq, smoothing_mode;
};

/* Reads one sbr_header(); returns 0 or AAC_ERR_DECODE. */
int aac_sbr_ld_header_read(AacSbrLdHeader* h, AacBitReader* r);
/* Writes one sbr_header() with the extension fields present only where
 * they differ from the defaults. */
void aac_sbr_ld_header_write(const AacSbrLdHeader* h, AacBitWriter* w);

/* The envelope grid of one frame.  The transient position of an LDTRAN
 * frame splits the slots into an envelope before it, the transient's
 * envelope of two slots and the rest; a part shorter than two slots is
 * merged into the transient's envelope. */
using AacSbrLdGrid = struct AacSbrLdGrid_ {
  int frame_class;
  int transient_position; /* LDTRAN: the slot the transient starts at */
  int num_env;
  int t_env[AAC_SBR_LD_MAX_ENV + 1];
  int freq_res[AAC_SBR_LD_MAX_ENV]; /* resolution per envelope */
  int tran_env; /* envelope of the transient, or -1 */
  int num_noise;
  int t_q[3];
};

/* Builds the borders of a frame of `slots` slots: FIXFIX with num_env
 * envelopes, or LDTRAN at transient_position; freq_res is left to the
 * caller.  Returns 0 or AAC_ERR_DECODE. */
int aac_sbr_ld_make_grid(AacSbrLdGrid* g, int slots, int frame_class, int num_env,
                         int transient_position);

using AacSbrLdChannel = struct AacSbrLdChannel_ {
  AacSbrLdGrid grid;
  int amp_res;
  int df_env[AAC_SBR_LD_MAX_ENV];
  int df_noise[2];
  int invf_mode[2][AAC_SBR_MAX_NOISE_BANDS]; /* [0] this frame, [1] previous */
  int add_harmonic_flag;
  int add_harmonic[AAC_SBR_MAX_BANDS];
  int env_facs_q[AAC_SBR_LD_MAX_ENV + 1][AAC_SBR_MAX_BANDS]; /* [0] the previous frame's last */
  float env_facs[AAC_SBR_LD_MAX_ENV + 1][AAC_SBR_MAX_BANDS];
  int noise_facs_q[3][AAC_SBR_MAX_NOISE_BANDS];
  float noise_facs[3][AAC_SBR_MAX_NOISE_BANDS];
  int prev_freq_res; /* resolution of the previous frame's last envelope */
  /* state */
  AacCldfbAnalysis analysis;
  AacCldfbSynthesis synthesis;
  float bw_array[AAC_SBR_MAX_NOISE_BANDS];
  float W[AAC_SBR_LD_MAX_SLOTS][32][2]; /* analysis output of this frame */
  float X_low[32][AAC_SBR_LD_BUF][2];  /* this frame behind the history */
  float X_high[64][AAC_SBR_LD_BUF][2];
  float alpha0[32][2];
  float alpha1[32][2];
  float g_temp[AAC_SBR_LD_MAX_SLOTS + 4][AAC_SBR_MAX_BANDS];
  float q_temp[AAC_SBR_LD_MAX_SLOTS + 4][AAC_SBR_MAX_BANDS];
  uint8_t s_indexmapped[AAC_SBR_LD_MAX_ENV + 1][AAC_SBR_MAX_BANDS];
  float e_origmapped[AAC_SBR_LD_MAX_ENV][AAC_SBR_MAX_BANDS];
  float q_mapped[AAC_SBR_LD_MAX_ENV][AAC_SBR_MAX_BANDS];
  uint8_t s_mapped[AAC_SBR_LD_MAX_ENV][AAC_SBR_MAX_BANDS];
  float e_curr[AAC_SBR_LD_MAX_ENV][AAC_SBR_MAX_BANDS];
  float q_m[AAC_SBR_LD_MAX_ENV][AAC_SBR_MAX_BANDS];
  float s_m[AAC_SBR_LD_MAX_ENV][AAC_SBR_MAX_BANDS];
  float gain[AAC_SBR_LD_MAX_ENV][AAC_SBR_MAX_BANDS];
  float Y[AAC_SBR_LD_MAX_SLOTS][64][2]; /* adjusted high band of this frame */
  unsigned f_indexnoise;
  unsigned f_indexsine;
  int smoothing_primed; /* g_temp holds the previous frame's end */
};

/* The decoder state of one element; the ELD decoder embeds one per SCE or
 * CPE and configures it from the element's ld_sbr_header. */
using AacSbrLdState = struct AacSbrLdState_ {
  int core_sample_rate;
  int frame_length; /* 512 or 480 core samples */
  int slots;        /* CLDFB slots per frame: 16 or 15 */
  int dual_rate;    /* output at twice the core rate */
  int elem_type;
  AacSbrLdHeader header;
  AacSbrFreq freq; /* the band tables of the header */
  int coupling;
  int have_data; /* sbr_data of the frame being processed was read */
  AacSbrLdChannel ch[2];
};

/* Configures the state for an element: the core sample rate, 512 or 480
 * samples per frame, dual or single rate output, and the element's
 * header.  Returns 0, AAC_ERR_INVALID_ARG or the error of the frequency
 * tables. */
int aac_sbr_ld_init(AacSbrLdState* s, int core_sample_rate, int frame_length, int dual_rate,
                    const AacSbrLdHeader* header);

/* Clears the filterbank and inter-frame state for a seek. */
void aac_sbr_ld_reset(AacSbrLdState* s);

/* Output samples per channel of one frame. */
int aac_sbr_ld_output_size(const AacSbrLdState* s);

/* Delay of the filterbanks in output samples: the output's sample
 * aac_sbr_ld_delay() + 2 i carries core sample i in dual rate, sample
 * aac_sbr_ld_delay() + i in single rate. */
int aac_sbr_ld_delay(const AacSbrLdState* s);

/* Reads the frame's SBR payload (the CRC when `crc` is set, then
 * sbr_data() for the element type) and leaves the reader after it.  On
 * AAC_ERR_DECODE the high band of the frame is muted. */
int aac_sbr_ld_read(AacSbrLdState* s, AacBitReader* r, int elem_type, int crc);

/* Turns the core output of a frame (frame_length samples per channel at
 * full scale 1.0; `core_right` NULL for an SCE) into the frame's output
 * (aac_sbr_ld_output_size samples per channel).  Without a preceding
 * aac_sbr_ld_read for the frame the core signal passes through the
 * filterbanks alone. */
void aac_sbr_ld_process(AacSbrLdState* s, const float* core_left, const float* core_right,
                        float* out_left, float* out_right);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_SBR_LD_H */
