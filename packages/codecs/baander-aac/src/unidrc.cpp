#include "unidrc.h"

#include <climits>
#include <cmath>
#include <cstdlib>
#include <cstring>

/* MPEG-D DRC decoding state, the selection process of ISO/IEC 23003-4
 * clause 6.2 and 6.3, and the application of the gains in the time
 * domain.
 *
 * Selection: the player's request names an effect; the DRC sets of the
 * stream's own layout (downmixId 0) whose drcSetEffect carries that
 * effect, or the first effect of the standard's fallback order that any
 * set carries, are the candidates; among them a set whose target
 * loudness range holds the requested target wins, then the first in the
 * stream.  Without a requested effect only a clipping prevention set is
 * used, and only when the loudness normalisation would otherwise drive
 * the signalled peak above full scale.
 *
 * Loudness: the loudnessInfo of the selected set and the output layout,
 * then the layout's general one (drcSetId 0), album entries first in
 * album mode; program loudness is preferred over anchor loudness and the
 * other level measurements.  The normalisation gain is target minus
 * program loudness, capped so that the signalled true (or sample) peak
 * stays below the limiter target when the request asks for the peak
 * protection.
 *
 * Application: the gain of every channel is the DRC gain of its channel
 * group (interpolated between the nodes of the previous and the current
 * frame, in the linear domain for linear interpolation and in dB for the
 * spline type) after the gain modifiers and the request's boost and cut
 * scaling, times the normalisation gain.  A multi-band gain set is
 * applied with the gain of its first band to the whole band width, since
 * the decoder has no DRC filter bank. */

namespace {

constexpr int kMaxGroups = AAC_UNIDRC_MAX_CHANNELS;

/* Fallback order per requested effect (Table of 6.3.2): the requested
 * effect first, then the alternatives in the standard's order. */
const unsigned kFallback[6][6] = {
    /* NIGHT */
    {AAC_UNIDRC_EFFECT_NIGHT, AAC_UNIDRC_EFFECT_NOISY, AAC_UNIDRC_EFFECT_LIMITED,
     AAC_UNIDRC_EFFECT_LOWLEVEL, AAC_UNIDRC_EFFECT_DIALOG, AAC_UNIDRC_EFFECT_GENERAL},
    /* NOISY */
    {AAC_UNIDRC_EFFECT_NOISY, AAC_UNIDRC_EFFECT_NIGHT, AAC_UNIDRC_EFFECT_LIMITED,
     AAC_UNIDRC_EFFECT_LOWLEVEL, AAC_UNIDRC_EFFECT_DIALOG, AAC_UNIDRC_EFFECT_GENERAL},
    /* LIMITED */
    {AAC_UNIDRC_EFFECT_LIMITED, AAC_UNIDRC_EFFECT_NIGHT, AAC_UNIDRC_EFFECT_NOISY,
     AAC_UNIDRC_EFFECT_LOWLEVEL, AAC_UNIDRC_EFFECT_DIALOG, AAC_UNIDRC_EFFECT_GENERAL},
    /* LOWLEVEL */
    {AAC_UNIDRC_EFFECT_LOWLEVEL, AAC_UNIDRC_EFFECT_NOISY, AAC_UNIDRC_EFFECT_NIGHT,
     AAC_UNIDRC_EFFECT_LIMITED, AAC_UNIDRC_EFFECT_DIALOG, AAC_UNIDRC_EFFECT_GENERAL},
    /* DIALOG */
    {AAC_UNIDRC_EFFECT_DIALOG, AAC_UNIDRC_EFFECT_GENERAL, AAC_UNIDRC_EFFECT_LIMITED,
     AAC_UNIDRC_EFFECT_NIGHT, AAC_UNIDRC_EFFECT_NOISY, AAC_UNIDRC_EFFECT_LOWLEVEL},
    /* GENERAL */
    {AAC_UNIDRC_EFFECT_GENERAL, AAC_UNIDRC_EFFECT_NIGHT, AAC_UNIDRC_EFFECT_NOISY,
     AAC_UNIDRC_EFFECT_LIMITED, AAC_UNIDRC_EFFECT_LOWLEVEL, AAC_UNIDRC_EFFECT_DIALOG},
};

/* Preference of the loudness measurements for the normalisation. */
const int kMethodPriority[] = {AAC_UNIDRC_METHOD_PROGRAM_LOUDNESS, AAC_UNIDRC_METHOD_ANCHOR_LOUDNESS,
                               AAC_UNIDRC_METHOD_MAX_OF_RANGE,     AAC_UNIDRC_METHOD_MOMENTARY_MAX,
                               AAC_UNIDRC_METHOD_SHORT_TERM_MAX,   AAC_UNIDRC_METHOD_SHORT_TERM_LOUDNESS};

float db_to_lin(float db) { return powf(10.0f, db * 0.05f); }

/* The node history of one gain sequence across frames. */
struct SequenceState {
  int node_count; /* nodes of the previous frame, times relative to this frame */
  AacUniDrcNode node[AAC_UNIDRC_MAX_NODES];
  float last_gain_db; /* the gain at the end of the previous frame's node list */
};

} // namespace

struct AacUniDrc_ {
  AacUniDrcRequest req;
  int sample_rate, channels, frame_length;
  int has_config, has_loudness;
  AacUniDrcConfig config;
  AacUniDrcLoudnessSet loudness;
  /* the selection */
  int selected;           /* index into config.instructions, -1 none */
  const AacUniDrcLoudnessInfo* loud_info;
  int has_program_loudness;
  float program_loudness; /* LUFS */
  float norm_gain_db;
  /* the frame's gains */
  AacUniDrcGainFrame frame;
  int frame_valid;
  SequenceState state[AAC_UNIDRC_MAX_SEQUENCES];
  float* scratch; /* AAC_USAC_MAX_OUTPUT_FRAME_ gains */
};

namespace {

constexpr int kMaxFrame = 4096;

/* The node history starts with 0 dB at the sample before the first frame. */
void reset_sequences(AacUniDrc* d) {
  for (int i = 0; i < AAC_UNIDRC_MAX_SEQUENCES; i++) {
    d->state[i].node_count = 1;
    d->state[i].node[0].time = -1;
    d->state[i].node[0].gain_db = 0.0f;
    d->state[i].node[0].slope = 0.0f;
    d->state[i].last_gain_db = 0.0f;
  }
  d->frame_valid = 0;
}

/* The loudness level of a loudnessInfo, by method preference; 0 when it
 * has none. */
int measurement(const AacUniDrcLoudnessInfo* li, float* value) {
  for (int p = 0; p < (int)(sizeof(kMethodPriority) / sizeof(kMethodPriority[0])); p++) {
    for (int i = 0; i < li->measurement_count; i++) {
      if (li->m[i].method == kMethodPriority[p]) {
        *value = li->m[i].value;
        return 1;
      }
    }
  }
  return 0;
}

int has_level(const AacUniDrcLoudnessInfo* li) {
  float v;
  return measurement(li, &v);
}

/* The loudnessInfo for a DRC set and the output layout from one list:
 * the set's own entry, then the layout's general one. */
const AacUniDrcLoudnessInfo* find_loudness(const AacUniDrcLoudnessInfo* list, int n, int drc_set_id) {
  for (int pass = 0; pass < 2; pass++) {
    const int want = pass == 0 ? drc_set_id : 0;
    if (pass == 0 && drc_set_id <= 0) {
      continue;
    }
    for (int i = 0; i < n; i++) {
      if (list[i].drc_set_id == want && list[i].downmix_id == 0 && has_level(&list[i])) {
        return &list[i];
      }
    }
  }
  /* Any entry of the layout with a loudness level. */
  for (int i = 0; i < n; i++) {
    if (list[i].downmix_id == 0 && has_level(&list[i])) {
      return &list[i];
    }
  }
  return nullptr;
}

const AacUniDrcLoudnessInfo* select_loudness(const AacUniDrc* d, int drc_set_id) {
  if (!d->has_loudness) {
    return nullptr;
  }
  const AacUniDrcLoudnessInfo* li = nullptr;
  if (d->req.album_mode) {
    li = find_loudness(d->loudness.album, d->loudness.album_count, drc_set_id);
  }
  if (!li) {
    li = find_loudness(d->loudness.info, d->loudness.count, drc_set_id);
  }
  return li;
}

/* Whether the instructions can be applied to the output layout on their own. */
int usable(const AacUniDrc* d, const AacUniDrcInstructions* in) {
  if (in->downmix_id != 0 && in->downmix_id != 0x7F) {
    return 0;
  }
  if (in->no_independent_use || in->requires_eq) {
    return 0;
  }
  if (in->drc_set_effect & (AAC_UNIDRC_EFFECT_DUCK_OTHER | AAC_UNIDRC_EFFECT_DUCK_SELF)) {
    return 0;
  }
  if (in->channel_count != d->config.base_channel_count) {
    return 0;
  }
  return in->group_count > 0;
}

/* The best candidate carrying `effect`, or -1. */
int pick(const AacUniDrc* d, unsigned effect, int has_target, float target_lufs) {
  int best = -1;
  int best_in_range = 0;
  for (int i = 0; i < d->config.instructions_count; i++) {
    const AacUniDrcInstructions* in = &d->config.instructions[i];
    if (!usable(d, in) || !(in->drc_set_effect & effect)) {
      continue;
    }
    int in_range = 0;
    if (in->target_loudness_present && has_target) {
      in_range = target_lufs <= (float)in->target_loudness_upper &&
                 target_lufs >= (float)in->target_loudness_lower;
    }
    if (best < 0 || (in_range && !best_in_range)) {
      best = i;
      best_in_range = in_range;
    }
  }
  return best;
}

/* The signalled peak: the true peak, else the sample peak; 0 when none. */
int peak_level(const AacUniDrcLoudnessInfo* li, float* peak) {
  if (li && li->true_peak_present) {
    *peak = li->true_peak_db;
    return 1;
  }
  if (li && li->sample_peak_present) {
    *peak = li->sample_peak_db;
    return 1;
  }
  return 0;
}

void select(AacUniDrc* d) {
  d->selected = -1;
  d->loud_info = nullptr;
  d->has_program_loudness = 0;
  d->program_loudness = 0.0f;
  d->norm_gain_db = 0.0f;
  if (!d->has_config && !d->has_loudness) {
    return;
  }
  const int has_target = d->req.loudness_normalization;
  const float target = (float)d->req.target_loudness_x100 / 100.0f;
  if (d->has_config && d->req.effect >= AAC_DRC_EFFECT_NIGHT && d->req.effect <= AAC_DRC_EFFECT_GENERAL) {
    const unsigned* order = kFallback[d->req.effect - AAC_DRC_EFFECT_NIGHT];
    for (int k = 0; k < 6 && d->selected < 0; k++) {
      d->selected = pick(d, order[k], has_target, target);
    }
  }
  const int set_id = d->selected >= 0 ? d->config.instructions[d->selected].drc_set_id : 0;
  d->loud_info = select_loudness(d, set_id);
  if (d->loud_info) {
    d->has_program_loudness = measurement(d->loud_info, &d->program_loudness);
  }
  if (has_target && d->has_program_loudness) {
    d->norm_gain_db = target - d->program_loudness;
  }
  /* Clipping prevention: when no effect is requested and the normalised
   * peak would exceed full scale, a clipping prevention set applies when
   * the stream has one; the peak protection caps the gain otherwise. */
  float peak = 0.0f;
  const int has_peak = peak_level(d->loud_info, &peak);
  if (d->selected < 0 && d->has_config && d->req.effect == AAC_DRC_EFFECT_NONE && has_peak &&
      peak + d->norm_gain_db > 0.0f) {
    d->selected = pick(d, AAC_UNIDRC_EFFECT_CLIPPING, has_target, target);
  }
  if (d->req.peak_limiter && has_peak) {
    float limit = 0.0f;
    if (d->selected >= 0 && d->config.instructions[d->selected].limiter_peak_target_present) {
      limit = d->config.instructions[d->selected].limiter_peak_target_db;
    }
    if (peak + d->norm_gain_db > limit) {
      d->norm_gain_db = limit - peak;
    }
  }
}

/* The gain set (flattened over the coefficients) of a channel group. */
const AacUniDrcGainSet* group_gain_set(const AacUniDrcConfig* c, const AacUniDrcInstructions* in,
                                       const AacUniDrcGroup* g, int* sequence) {
  int index = g->gain_set_index;
  for (int i = 0; i < c->coefficients_count; i++) {
    const AacUniDrcCoefficients* co = &c->coefficients[i];
    if (co->version != in->version) {
      continue;
    }
    if (index < co->gain_set_count) {
      *sequence = co->gain_set[index].band[0].gain_sequence_index;
      return &co->gain_set[index];
    }
    index -= co->gain_set_count;
  }
  return nullptr;
}

/* The DRC gain in dB after the gain modifiers and the request's scaling. */
float modify(const AacUniDrc* d, const AacUniDrcGroup* g, float gain_db) {
  if (gain_db < 0.0f) {
    gain_db *= g->attenuation_scaling * (float)d->req.cut_percent / 100.0f;
  } else {
    gain_db *= g->amplification_scaling * (float)d->req.boost_percent / 100.0f;
  }
  return gain_db + g->gain_offset_db;
}

/* Evaluates the gain curve of one sequence over the frame into out[],
 * as linear gains, from the previous frame's nodes and this frame's. */
void interpolate(const AacUniDrc* d, int seq, const AacUniDrcGainSet* gs, const AacUniDrcGroup* g,
                 float* out, int n) {
  const SequenceState* st = &d->state[seq];
  const AacUniDrcSequence* cur = d->frame_valid ? &d->frame.seq[seq] : nullptr;
  /* Gather the nodes: the previous frame's (already relative to this
   * frame), then this frame's. */
  int times[2 * AAC_UNIDRC_MAX_NODES + 2];
  float gains[2 * AAC_UNIDRC_MAX_NODES + 2];
  float slopes[2 * AAC_UNIDRC_MAX_NODES + 2];
  int count = 0;
  for (int i = 0; i < st->node_count; i++) {
    times[count] = st->node[i].time;
    gains[count] = modify(d, g, st->node[i].gain_db);
    slopes[count] = st->node[i].slope;
    count++;
  }
  if (cur) {
    for (int i = 0; i < cur->node_count; i++) {
      times[count] = cur->node[i].time;
      gains[count] = modify(d, g, cur->node[i].gain_db);
      slopes[count] = cur->node[i].slope;
      count++;
    }
  }
  if (count == 0) {
    const float hold = db_to_lin(modify(d, g, st->last_gain_db) + d->norm_gain_db);
    for (int t = 0; t < n; t++) {
      out[t] = hold;
    }
    return;
  }
  const float norm = d->norm_gain_db;
  const int spline = gs->gain_interpolation_type == 0;
  int k = 0; /* the first node at or after t */
  for (int t = 0; t < n; t++) {
    while (k < count && times[k] < t) {
      k++;
    }
    float g_db;
    if (k == 0) {
      g_db = gains[0]; /* before the first known node: hold it */
    } else if (k >= count) {
      g_db = gains[count - 1]; /* after the last node of the frame: hold */
    } else {
      const int t0 = times[k - 1], t1 = times[k];
      const float u = t1 > t0 ? (float)(t - t0) / (float)(t1 - t0) : 1.0f;
      if (spline) {
        /* cubic Hermite in dB with the node slopes in dB per sample */
        const float h = (float)(t1 - t0);
        const float u2 = u * u, u3 = u2 * u;
        g_db = (2 * u3 - 3 * u2 + 1) * gains[k - 1] + (u3 - 2 * u2 + u) * h * slopes[k - 1] +
               (-2 * u3 + 3 * u2) * gains[k] + (u3 - u2) * h * slopes[k];
      } else {
        /* linear interpolation of the linear gain */
        const float a = db_to_lin(gains[k - 1] + norm), b = db_to_lin(gains[k] + norm);
        out[t] = a + (b - a) * u;
        continue;
      }
    }
    out[t] = db_to_lin(g_db + norm);
  }
}

/* Moves this frame's nodes into the history for the next frame. */
void advance(AacUniDrc* d) {
  const int fl = d->frame_length;
  for (int seq = 0; seq < AAC_UNIDRC_MAX_SEQUENCES; seq++) {
    SequenceState* st = &d->state[seq];
    const AacUniDrcSequence* cur = (d->frame_valid && seq < d->frame.sequence_count) ? &d->frame.seq[seq]
                                                                                        : nullptr;
    if (!cur || cur->node_count == 0) {
      /* no nodes this frame: the last gain holds at the frame end */
      st->node_count = 1;
      st->node[0].time = -1;
      st->node[0].gain_db = st->last_gain_db;
      st->node[0].slope = 0.0f;
      continue;
    }
    /* Keep the last node before the next frame and every later one. */
    int first = 0;
    for (int i = 0; i < cur->node_count; i++) {
      if (cur->node[i].time < fl) {
        first = i;
      }
    }
    int n = 0;
    for (int i = first; i < cur->node_count && n < AAC_UNIDRC_MAX_NODES; i++) {
      st->node[n] = cur->node[i];
      st->node[n].time -= fl;
      n++;
    }
    st->node_count = n;
    st->last_gain_db = cur->node[cur->node_count - 1].gain_db;
  }
  d->frame_valid = 0;
}

} // namespace

AacUniDrc* aac_unidrc_create(void) {
  AacUniDrc* d = (AacUniDrc*)calloc(1, sizeof(AacUniDrc));
  if (!d) {
    return nullptr;
  }
  d->scratch = (float*)calloc((size_t)kMaxFrame, sizeof(float));
  if (!d->scratch) {
    free(d);
    return nullptr;
  }
  d->req.loudness_normalization = 1;
  d->req.target_loudness_x100 = -2400;
  d->req.effect = AAC_DRC_EFFECT_NONE;
  d->req.boost_percent = d->req.cut_percent = 100;
  d->req.peak_limiter = 1;
  d->selected = -1;
  return d;
}

void aac_unidrc_destroy(AacUniDrc* d) {
  if (d) {
    free(d->scratch);
    free(d);
  }
}

void aac_unidrc_reset(AacUniDrc* d) {
  d->has_config = d->has_loudness = 0;
  memset(&d->config, 0, sizeof(d->config));
  memset(&d->loudness, 0, sizeof(d->loudness));
  reset_sequences(d);
  select(d);
}

int aac_unidrc_set_config(AacUniDrc* d, const uint8_t* unidrc_config, int config_len,
                          const uint8_t* loudness_info_set, int loudness_len) {
  int err = AAC_OK;
  d->has_config = d->has_loudness = 0;
  memset(&d->config, 0, sizeof(d->config));
  memset(&d->loudness, 0, sizeof(d->loudness));
  if (unidrc_config && config_len > 0) {
    AacBitReader r;
    aac_bitreader_init(&r, unidrc_config, config_len);
    if (aac_unidrc_parse_config(&r, &d->config) == AAC_OK) {
      d->has_config = 1;
    } else {
      memset(&d->config, 0, sizeof(d->config));
      err = AAC_ERR_DECODE;
    }
  }
  if (loudness_info_set && loudness_len > 0) {
    AacBitReader r;
    aac_bitreader_init(&r, loudness_info_set, loudness_len);
    if (aac_unidrc_parse_loudness(&r, &d->loudness) == AAC_OK) {
      d->has_loudness = 1;
    } else {
      memset(&d->loudness, 0, sizeof(d->loudness));
      err = AAC_ERR_DECODE;
    }
  }
  reset_sequences(d);
  select(d);
  return err;
}

void aac_unidrc_set_stream(AacUniDrc* d, int sample_rate, int channels, int frame_length) {
  d->sample_rate = sample_rate;
  d->channels = channels > AAC_UNIDRC_MAX_CHANNELS ? AAC_UNIDRC_MAX_CHANNELS : channels;
  d->frame_length = frame_length > kMaxFrame ? kMaxFrame : frame_length;
  reset_sequences(d);
}

void aac_unidrc_set_request(AacUniDrc* d, const AacUniDrcRequest* req) {
  d->req = *req;
  if (d->req.boost_percent < 0) d->req.boost_percent = 0;
  if (d->req.boost_percent > 100) d->req.boost_percent = 100;
  if (d->req.cut_percent < 0) d->req.cut_percent = 0;
  if (d->req.cut_percent > 100) d->req.cut_percent = 100;
  select(d);
}

int aac_unidrc_read_gain(AacUniDrc* d, AacBitReader* r, int payload_bits, int indep) {
  (void)indep; /* every frame codes its first gain absolutely */
  if (!d->has_config || d->frame_length <= 0 || d->sample_rate <= 0) {
    aac_bitreader_skip(r, payload_bits);
    d->frame_valid = 0;
    return AAC_OK;
  }
  const int start = r->pos;
  const int err = aac_unidrc_parse_gain(&d->config, r, payload_bits, d->frame_length,
                                        d->sample_rate, &d->frame);
  r->pos = start;
  aac_bitreader_skip(r, payload_bits);
  d->frame_valid = err == AAC_OK;
  return err;
}

void aac_unidrc_no_gain(AacUniDrc* d) { d->frame_valid = 0; }

void aac_unidrc_channel_gain(const AacUniDrc* d, int channel, float* out, int frame_length) {
  const int n = frame_length > kMaxFrame ? kMaxFrame : frame_length;
  const float norm = db_to_lin(d->norm_gain_db);
  if (d->selected < 0 || d->req.effect == AAC_DRC_EFFECT_OFF || channel < 0 ||
      channel >= d->config.instructions[d->selected].channel_count) {
    for (int t = 0; t < n; t++) {
      out[t] = d->req.effect == AAC_DRC_EFFECT_OFF ? 1.0f : norm;
    }
    return;
  }
  const AacUniDrcInstructions* in = &d->config.instructions[d->selected];
  const int gsi = in->gain_set_index[channel];
  const AacUniDrcGroup* g = nullptr;
  for (int i = 0; i < in->group_count; i++) {
    if (in->group[i].gain_set_index == gsi) {
      g = &in->group[i];
    }
  }
  int seq = -1;
  const AacUniDrcGainSet* gs = g ? group_gain_set(&d->config, in, g, &seq) : nullptr;
  if (!gs || seq < 0 || seq >= AAC_UNIDRC_MAX_SEQUENCES) {
    for (int t = 0; t < n; t++) {
      out[t] = norm;
    }
    return;
  }
  interpolate(d, seq, gs, g, out, n);
}

void aac_unidrc_apply(AacUniDrc* d, float* const* ch, int channels, int frame_length) {
  const int n = frame_length > kMaxFrame ? kMaxFrame : frame_length;
  if (d->req.effect == AAC_DRC_EFFECT_OFF) {
    advance(d);
    return;
  }
  const int nch = channels > AAC_UNIDRC_MAX_CHANNELS ? AAC_UNIDRC_MAX_CHANNELS : channels;
  for (int c = 0; c < nch; c++) {
    aac_unidrc_channel_gain(d, c, d->scratch, n);
    float* x = ch[c];
    for (int t = 0; t < n; t++) {
      x[t] *= d->scratch[t];
    }
  }
  advance(d);
}

void aac_unidrc_status(const AacUniDrc* d, int* program_loudness_x100, int* norm_gain_db_x100,
                       int* drc_set_id) {
  if (program_loudness_x100) {
    *program_loudness_x100 =
        d->has_program_loudness ? (int)lrintf(d->program_loudness * 100.0f) : INT32_MIN;
  }
  if (norm_gain_db_x100) {
    *norm_gain_db_x100 = (int)lrintf(d->norm_gain_db * 100.0f);
  }
  if (drc_set_id) {
    *drc_set_id = d->selected >= 0 ? d->config.instructions[d->selected].drc_set_id : -1;
  }
}

const AacUniDrcConfig* aac_unidrc_config(const AacUniDrc* d) { return &d->config; }
const AacUniDrcLoudnessSet* aac_unidrc_loudness(const AacUniDrc* d) { return &d->loudness; }
int aac_unidrc_selected_instructions(const AacUniDrc* d) { return d->selected; }
const AacUniDrcLoudnessInfo* aac_unidrc_selected_loudness(const AacUniDrc* d) { return d->loud_info; }
