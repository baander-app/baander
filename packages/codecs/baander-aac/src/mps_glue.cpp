#include <cstdlib>
#include <cstring>

#include "mps_internal.h"
#include "mps_ld.h"

/* From the parsed spatial frame to the signal path: the decoder hands
 * the QMF frames of one core frame at a time, and a spatial frame
 * (bsFrameLength) may span several of them.  The core frames are then
 * gathered until the spatial frame is complete, the path runs once over
 * it, and its output is handed back one core frame at a time with the
 * delay of the frames still being gathered, so that the output stays
 * continuous: with N core frames per spatial frame the output lags by
 * N - 1 core frames.  A spatial frame that is one core frame long goes
 * through without copying.  The parameters of the frame read since the
 * last apply are used once; when no frame arrived (a lost one) the path
 * keeps the previous parameters. */

struct AacMpsGlue_ {
  AacMpsSignalState* signal; /* the AAC form */
  AacMpsLdState* ld;         /* the low-delay form of ELD v2 */
  int slots;     /* QMF slots per spatial frame */
  int qmf_slots; /* QMF slots per core frame */
  int filled;    /* slots of the spatial frame gathered so far */
  int out_valid; /* `out` holds a processed spatial frame */
  AacUsacQmfFrame in[AAC_MPS_MAX_INPUT_CHANNELS];
  AacUsacQmfFrame out[AAC_MPS_MAX_OUTPUT_CHANNELS];
};

namespace {

AacMpsGlue_* glue_of(AacMpsState* s) {
  if (s->glue) {
    return s->glue;
  }
  AacMpsSignalState* sig = NULL;
  AacMpsLdState* ld = NULL;
  if (s->cfg.low_delay) {
    ld = aac_mps_ld_create(&s->cfg);
  } else {
    sig = aac_mps_signal_create(&s->cfg, s->core_frame_length, s->qmf_slots);
  }
  if (sig == NULL && ld == NULL) {
    return NULL;
  }
  AacMpsGlue_* g = (AacMpsGlue_*)calloc(1, sizeof(AacMpsGlue_));
  if (g == NULL) {
    aac_mps_signal_destroy(sig);
    aac_mps_ld_destroy(ld);
    return NULL;
  }
  g->signal = sig;
  g->ld = ld;
  g->slots = ld ? aac_mps_ld_slots(ld) : aac_mps_signal_slots(sig);
  g->qmf_slots = s->qmf_slots;
  s->glue = g;
  return g;
}

/* One spatial frame through the path of the configuration's form. */
void run_path(AacMpsGlue_* g, const AacMpsFrameParams* p, const AacUsacQmfFrame* const* in,
              AacUsacQmfFrame* const* out) {
  if (g->ld) {
    aac_mps_ld_apply(g->ld, p, in[0], out);
  } else {
    aac_mps_signal_apply(g->signal, p, in, out);
  }
}

void copy_slots(AacUsacQmfFrame* dst, int dst_slot, const AacUsacQmfFrame* src, int src_slot,
                int n) {
  memcpy(dst->x[dst_slot], src->x[src_slot], sizeof(src->x[0]) * (size_t)n);
}

}  // namespace

void aac_mps_glue_destroy(AacMpsGlue_* g) {
  if (g) {
    aac_mps_signal_destroy(g->signal);
    aac_mps_ld_destroy(g->ld);
    free(g);
  }
}

void aac_mps_glue_reset(AacMpsGlue_* g) {
  if (g) {
    aac_mps_signal_reset(g->signal);
    aac_mps_ld_reset(g->ld);
    g->filled = 0;
    g->out_valid = 0;
  }
}

void aac_mps_set_residual(AacMpsState* s, int index, const float* pcm, int length) {
  if (s == NULL) {
    return;
  }
  AacMpsGlue_* g = glue_of(s);
  if (g && g->signal) {
    aac_mps_signal_set_residual(g->signal, index, pcm, length);
  }
}

void aac_mps_apply(AacMpsState* s, const AacUsacQmfFrame* const* in, AacUsacQmfFrame* const* out) {
  if (s == NULL || in == NULL || out == NULL) {
    return;
  }
  const int nin = s->cfg.num_input_channels;
  const int nout = s->cfg.num_output_channels;
  const int q = s->qmf_slots;
  AacMpsGlue_* g = glue_of(s);
  if (g == NULL) {
    for (int ch = 0; ch < nout; ch++) {
      memset(out[ch]->x, 0, sizeof(out[ch]->x[0]) * (size_t)q);
      out[ch]->slots = q;
    }
    return;
  }
  if (g->slots == q) {
    const AacMpsFrameParams* p = s->frame_pending ? &s->params : NULL;
    s->frame_pending = 0;
    run_path(g, p, in, out);
    return;
  }
  for (int ch = 0; ch < nin; ch++) {
    copy_slots(&g->in[ch], g->filled, in[ch], 0, q);
    g->in[ch].slots = g->slots;
  }
  if (g->filled + q < g->slots) {
    /* a frame still being gathered: the output continues from the
     * previous spatial frame, one core frame further in */
    for (int ch = 0; ch < nout; ch++) {
      if (g->out_valid) {
        copy_slots(out[ch], 0, &g->out[ch], g->filled + q, q);
      } else {
        memset(out[ch]->x, 0, sizeof(out[ch]->x[0]) * (size_t)q);
      }
      out[ch]->slots = q;
    }
    g->filled += q;
    return;
  }
  const AacMpsFrameParams* p = s->frame_pending ? &s->params : NULL;
  s->frame_pending = 0;
  const AacUsacQmfFrame* ins[AAC_MPS_MAX_INPUT_CHANNELS];
  AacUsacQmfFrame* outs[AAC_MPS_MAX_OUTPUT_CHANNELS];
  for (int ch = 0; ch < AAC_MPS_MAX_INPUT_CHANNELS; ch++) {
    ins[ch] = &g->in[ch];
  }
  for (int ch = 0; ch < AAC_MPS_MAX_OUTPUT_CHANNELS; ch++) {
    outs[ch] = &g->out[ch];
  }
  run_path(g, p, ins, outs);
  g->out_valid = 1;
  g->filled = 0;
  for (int ch = 0; ch < nout; ch++) {
    copy_slots(out[ch], 0, &g->out[ch], 0, q);
    out[ch]->slots = q;
  }
}
