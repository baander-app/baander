#include <cstdint>
#include <cstring>
#include <new>

#include "mps212.h"
#include "sbr.h"
#include "unidrc.h"
#include "usac_fd.h"
#include "usac_lpd.h"

/* Placeholders for the USAC modules that are still being written: the
 * library links and the frame walker reaches each module's entry point,
 * which answers AAC_ERR_UNSUPPORTED (or does nothing where no answer is
 * expected).  Each section goes away when its module lands. */

/* ── usac_fd.h ─────────────────────────────────────────────────── */

struct AacUsacFdChannel_ {
  int frame_length;
};

AacUsacFdChannel* aac_usac_fd_channel_create(int frame_length) {
  auto* c = new (std::nothrow) AacUsacFdChannel_();
  if (c) {
    c->frame_length = frame_length;
  }
  return c;
}

void aac_usac_fd_channel_destroy(AacUsacFdChannel* c) { delete c; }

void aac_usac_fd_channel_reset(AacUsacFdChannel* c) { (void)c; }

int aac_usac_core_decode(AacUsacCoreChannel* const* ch, int nr_channels, int lfe, int noise_filling,
                         int frame_length, int indep, AacBitReader* r, float* const* out) {
  (void)ch;
  (void)nr_channels;
  (void)lfe;
  (void)noise_filling;
  (void)frame_length;
  (void)indep;
  (void)r;
  (void)out;
  return AAC_ERR_UNSUPPORTED;
}

void aac_usac_core_conceal(AacUsacCoreChannel* const* ch, int nr_channels, int frame_length,
                           float* const* out) {
  (void)ch;
  for (int c = 0; c < nr_channels; c++) {
    memset(out[c], 0, (size_t)frame_length * sizeof(float));
  }
}

/* ── usac_lpd.h ────────────────────────────────────────────────── */

struct AacUsacLpdChannel_ {
  int frame_length;
  int core_sample_rate;
};

AacUsacLpdChannel* aac_usac_lpd_channel_create(int frame_length, int core_sample_rate) {
  auto* c = new (std::nothrow) AacUsacLpdChannel_();
  if (c) {
    c->frame_length = frame_length;
    c->core_sample_rate = core_sample_rate;
  }
  return c;
}

void aac_usac_lpd_channel_destroy(AacUsacLpdChannel* c) { delete c; }

void aac_usac_lpd_channel_reset(AacUsacLpdChannel* c) { (void)c; }

int aac_usac_lpd_decode(AacUsacCoreChannel* ch, int frame_length, int indep, AacBitReader* r,
                        float* out) {
  (void)ch;
  (void)frame_length;
  (void)indep;
  (void)r;
  (void)out;
  return AAC_ERR_UNSUPPORTED;
}

void aac_usac_lpd_fac_to_fd(AacUsacCoreChannel* ch, const int* fac_q, int fac_length,
                            int frame_length) {
  (void)ch;
  (void)fac_q;
  (void)fac_length;
  (void)frame_length;
}

void aac_usac_lpd_conceal(AacUsacCoreChannel* ch, int frame_length, float* out) {
  (void)ch;
  memset(out, 0, (size_t)frame_length * sizeof(float));
}

/* ── sbr.h: aac_sbr_usac_* ─────────────────────────────────────── */

void aac_sbr_usac_init(AacSbrState* s, int core_sample_rate, int frame_length, int ratio,
                       const struct AacUsacSbrConfig_* cfg) {
  (void)s;
  (void)core_sample_rate;
  (void)frame_length;
  (void)ratio;
  (void)cfg;
}

int aac_sbr_usac_decode(AacSbrState* s, AacBitReader* r, int elem_type, int nr_channels,
                        int indep) {
  (void)s;
  (void)r;
  (void)elem_type;
  (void)nr_channels;
  (void)indep;
  return AAC_ERR_UNSUPPORTED;
}

void aac_sbr_usac_apply(AacSbrState* s, float* left, float* right) {
  (void)s;
  (void)left;
  (void)right;
}

void aac_sbr_usac_apply_to_qmf(AacSbrState* s, int ch, const float* core,
                               struct AacUsacQmfFrame_* out) {
  (void)s;
  (void)ch;
  (void)core;
  memset(out, 0, sizeof(*out));
}

void aac_sbr_usac_synthesize(AacSbrState* s, int ch, const struct AacUsacQmfFrame_* in,
                             float* out) {
  (void)s;
  (void)ch;
  (void)in;
  (void)out;
}

/* ── mps212.h ──────────────────────────────────────────────────── */

struct AacMps212State_ {
  int stereo_config_index;
};

AacMps212State* aac_mps212_create(const AacUsacMps212Config* cfg, int stereo_config_index,
                                  int frame_length, int output_frame_length, int sample_rate) {
  (void)cfg;
  (void)frame_length;
  (void)output_frame_length;
  (void)sample_rate;
  auto* s = new (std::nothrow) AacMps212State_();
  if (s) {
    s->stereo_config_index = stereo_config_index;
  }
  return s;
}

void aac_mps212_destroy(AacMps212State* s) { delete s; }

void aac_mps212_reset(AacMps212State* s) { (void)s; }

int aac_mps212_read(AacMps212State* s, AacBitReader* r, int indep) {
  (void)s;
  (void)r;
  (void)indep;
  return AAC_ERR_UNSUPPORTED;
}

void aac_mps212_apply(AacMps212State* s, const AacUsacQmfFrame* dmx, const float* residual,
                      AacUsacQmfFrame* left, AacUsacQmfFrame* right) {
  (void)s;
  (void)residual;
  if (left != dmx) {
    *left = *dmx;
  }
  *right = *dmx;
}

void aac_mps212_conceal(AacMps212State* s) { (void)s; }

/* ── unidrc.h ──────────────────────────────────────────────────── */

struct AacUniDrc_ {
  AacUniDrcRequest request;
  int sample_rate, channels, frame_length;
};

AacUniDrc* aac_unidrc_create(void) { return new (std::nothrow) AacUniDrc_(); }

void aac_unidrc_destroy(AacUniDrc* d) { delete d; }

void aac_unidrc_reset(AacUniDrc* d) { (void)d; }

int aac_unidrc_set_config(AacUniDrc* d, const uint8_t* unidrc_config, int config_len,
                          const uint8_t* loudness_info_set, int loudness_len) {
  (void)d;
  (void)unidrc_config;
  (void)config_len;
  (void)loudness_info_set;
  (void)loudness_len;
  return AAC_OK;
}

void aac_unidrc_set_stream(AacUniDrc* d, int sample_rate, int channels, int frame_length) {
  d->sample_rate = sample_rate;
  d->channels = channels;
  d->frame_length = frame_length;
}

void aac_unidrc_set_request(AacUniDrc* d, const AacUniDrcRequest* req) { d->request = *req; }

int aac_unidrc_read_gain(AacUniDrc* d, AacBitReader* r, int payload_bits, int indep) {
  (void)d;
  (void)indep;
  aac_bitreader_skip(r, payload_bits);
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

void aac_unidrc_no_gain(AacUniDrc* d) { (void)d; }

void aac_unidrc_apply(AacUniDrc* d, float* const* ch, int channels, int frame_length) {
  (void)d;
  (void)ch;
  (void)channels;
  (void)frame_length;
}

void aac_unidrc_status(const AacUniDrc* d, int* program_loudness_x100, int* norm_gain_db_x100,
                       int* drc_set_id) {
  (void)d;
  if (program_loudness_x100) {
    *program_loudness_x100 = INT32_MIN;
  }
  if (norm_gain_db_x100) {
    *norm_gain_db_x100 = 0;
  }
  if (drc_set_id) {
    *drc_set_id = -1;
  }
}
