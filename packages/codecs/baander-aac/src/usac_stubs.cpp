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

