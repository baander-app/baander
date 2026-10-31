#include <cstring>
#include <mutex>

#include "mps212_enc.h"
#include "sbr_enc.h"
#include "sbr_enc_internal.h"

/* The USAC form of the SBR encoder's output (ISO/IEC 23003-3 7.5):
 * UsacSbrData() at the 2:1 ratio over the analysis, grid and envelope
 * estimation of the HE-AAC encoder.  An independent frame carries
 * SbrInfo() and the default header and codes its first envelope and
 * noise floor along frequency; every other frame sends neither, so the
 * decoder keeps the configuration's header.  The harmonic transposer,
 * inter-TES and PVC are off, the sinusoid flags clear, and there is no
 * bs_data_extra or extended data.  With an MPS 212 encoder the frame's
 * parameters are estimated here from the two input channels at the
 * slots the frame's envelopes describe. */

namespace {

constexpr int kRingMask = AAC_SBR_ENC_RING - 1;

void write_sbr_info_and_header(const AacSbrEncoder* s, AacBitWriter* w) {
  /* SbrInfo(): amplitude resolution, crossover band, no pre-flattening;
   * then sbrUseDfltHeader */
  aac_bitwriter_write(w, (uint32_t)s->amp_res_header, 1);
  aac_bitwriter_write(w, (uint32_t)s->freq.xover_band, 4);
  aac_bitwriter_write(w, 0, 1);
  aac_bitwriter_write(w, 1, 1);
}

int coded_channels(const AacSbrEncoder* s) { return s->mps ? 1 : s->channels; }

/* The MPS 212 parameters of the frame from the left and right channel
 * rings at the frame's slots, with the analysis window's reach on either
 * side; a failure stays in the MPS encoder for the frame writer. */
void analyze_mps(AacSbrEncoder* s, int base) {
  aac_mps212_enc_analyze(s->mps, s->left, s->ch[1].W, base, kRingMask, s->slots, s->usac_indep);
}

/* The data of a silent channel: one envelope over the frame at the
 * lowest level, the noise floor at its lowest ratio, no inverse
 * filtering, coded along frequency as the first frame after a start
 * must be.  The USAC form always runs 1024-line core frames, so the
 * grid's length is fixed and the channel is built once. */
constexpr int kUsacTimeSlots = AAC_FRAME_SIZE_LONG / 64;

const AacSbrEncChannel* silent_channel(void) {
  static AacSbrEncChannel z;
  static std::once_flag once;
  std::call_once(once, [] {
    AacSbrGrid* g = &z.grid;
    g->frame_class = 0;
    g->num_env = 1;
    g->t_env[0] = 0;
    g->t_env[1] = kUsacTimeSlots;
    g->freq_res[0] = 0;
    g->amp_res = 0;
    g->num_noise = 1;
    g->t_q[0] = 0;
    g->t_q[1] = kUsacTimeSlots;
    for (int j = 0; j < AAC_SBR_MAX_NOISE_BANDS; j++) {
      z.noise_q[0][j] = 30;
    }
  });
  return &z;
}

}  // namespace

int aac_sbr_enc_init_usac(AacSbrEncoder* s, int sample_rate, int channels, int bitrate,
                          AacMps212Encoder* mps) {
  if (mps && channels != 2) {
    return AAC_ERR_UNSUPPORTED;
  }
  const int ret =
      aac_sbr_enc_init(s, sample_rate, mps ? 1 : channels, bitrate, 0, AAC_FRAME_SIZE_LONG);
  if (ret) {
    return ret;
  }
  s->channels = channels;
  s->mps = mps;
  s->usac = 1;
  s->usac_indep = 1;
  return 0;
}

void aac_sbr_enc_usac_config(const AacSbrEncoder* s, AacUsacSbrConfig* c) {
  const AacSbrFreq* f = &s->freq;
  memset(c, 0, sizeof(*c));
  c->harmonic_sbr = 0;
  c->inter_tes = 0;
  c->pvc = 0;
  AacUsacSbrHeader* h = &c->dflt;
  h->start_freq = f->start_freq;
  h->stop_freq = f->stop_freq;
  h->freq_scale = f->freq_scale;
  h->alter_scale = f->alter_scale;
  h->noise_bands = f->noise_bands;
  h->limiter_bands = f->limiter_bands;
  h->limiter_gains = s->limiter_gains;
  h->interpol_freq = s->interpol_freq;
  h->smoothing_mode = s->smoothing_mode;
  h->header_extra1 = h->freq_scale != 2 || h->alter_scale != 1 || h->noise_bands != 2;
  h->header_extra2 = h->limiter_bands != 2 || h->limiter_gains != 2 || h->interpol_freq != 1 ||
                     h->smoothing_mode != 1;
}

void aac_sbr_enc_usac_frame(AacSbrEncoder* s, int base, const int (*prev_env)[AAC_SBR_MAX_BANDS],
                            const int* prev_res, const int (*prev_noise)[AAC_SBR_MAX_NOISE_BANDS]) {
  if (s->mps) {
    analyze_mps(s, base);
  }
  const int indep = s->usac_indep != 0;
  const int nch = coded_channels(s);
  AacBitWriter w;
  aac_bitwriter_init(&w, s->payload, sizeof(s->payload));
  if (!indep) {
    aac_bitwriter_write(&w, 0, 1); /* sbrInfoPresent */
  } else {
    write_sbr_info_and_header(s, &w);
  }
  /* sbr_single_channel_element() / sbr_channel_pair_element() without
   * coupling and without the harmonic patching fields */
  if (nch == 2) {
    aac_bitwriter_write(&w, 0, 1); /* bs_coupling */
  }
  for (int c = 0; c < nch; c++) {
    aac_sbr_enc_write_grid(&w, &s->ch[c].grid, s->time_slots);
  }
  for (int c = 0; c < nch; c++) {
    aac_sbr_enc_write_dtdf(&w, &s->ch[c], indep);
  }
  for (int c = 0; c < nch; c++) {
    aac_sbr_enc_write_invf(s, &w, &s->ch[c]);
  }
  for (int c = 0; c < nch; c++) {
    aac_sbr_enc_write_envelope(s, &w, &s->ch[c], prev_env[c], prev_res[c]);
  }
  for (int c = 0; c < nch; c++) {
    aac_sbr_enc_write_noise(s, &w, &s->ch[c], prev_noise[c]);
  }
  for (int c = 0; c < nch; c++) {
    aac_bitwriter_write(&w, 0, 1); /* bs_add_harmonic_flag */
  }
  s->payload_bits = aac_bitwriter_bits_written(&w);
}

void aac_sbr_enc_usac_write(const AacSbrEncoder* s, AacBitWriter* w) {
  aac_bitwriter_write_bits(w, s->payload, s->payload_bits);
}

void aac_sbr_enc_usac_write_silent(const AacSbrEncoder* s, AacBitWriter* w) {
  const AacSbrEncChannel* z = silent_channel();
  const AacSbrGrid* g = &z->grid;
  const int nch = coded_channels(s);
  const int zero_env[AAC_SBR_MAX_BANDS] = {0};
  const int zero_noise[AAC_SBR_MAX_NOISE_BANDS] = {0};
  write_sbr_info_and_header(s, w);
  if (nch == 2) {
    aac_bitwriter_write(w, 0, 1); /* bs_coupling */
  }
  for (int c = 0; c < nch; c++) {
    aac_sbr_enc_write_grid(w, g, s->time_slots);
  }
  /* no direction flags: an independent frame's only envelope and noise
   * floor are coded along frequency */
  for (int c = 0; c < nch; c++) {
    aac_sbr_enc_write_invf(s, w, z);
  }
  for (int c = 0; c < nch; c++) {
    aac_sbr_enc_write_envelope(s, w, z, zero_env, 1);
  }
  for (int c = 0; c < nch; c++) {
    aac_sbr_enc_write_noise(s, w, z, zero_noise);
  }
  for (int c = 0; c < nch; c++) {
    aac_bitwriter_write(w, 0, 1); /* bs_add_harmonic_flag */
  }
}
