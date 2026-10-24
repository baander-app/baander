#include <cstdint>
#include <cstring>
#include <new>

#include "sbr.h"
#include "usac_fd.h"

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

