/* MPEG-D DRC gain generation and the writers of uniDrcConfig(),
 * uniDrcGain() and loudnessInfoSet() (ISO/IEC 23003-4 clauses 5 and 6);
 * see drc_gen.h for the overview.  The writers follow the readers of
 * unidrc_parse.cpp and unidrc_gain.cpp field for field. */
#include "drc_gen.h"

#include <algorithm>
#include <cmath>
#include <cstdlib>
#include <cstring>

#include "loudness.h"

namespace {

/* ── the syntax ────────────────────────────────────────────────── */

/* drcLocation 1: the gains travel in the uniDrc payload of the audio
 * stream (Table 5 of the standard). */
constexpr int kDrcLocationPayload = 1;
/* uniDrcConfigExtType of the version 1 coefficients and instructions. */
constexpr int kConfExtV1 = 2;
/* drcSetComplexityLevel of the sets: one band, linear interpolation,
 * time-domain gains on up to two channels (the FDK encoder signals the
 * same level for such sets). */
constexpr int kComplexityLevel = 2;
/* The limits of the gain codes, in 0.125 dB steps: the initial gain of
 * the regular profile is a sign and an 8-bit magnitude; the gain delta
 * table spans -2 dB to +1 dB. */
constexpr int kMaxGainCode = 255;
constexpr int kMinDeltaCode = -16;
constexpr int kMaxDeltaCode = 8;

/* The Huffman codes of the gain delta in 0.125 dB steps (the deltaGain
 * code table of the standard, as unidrc_gain.cpp decodes it): bits,
 * code, value. */
struct Code {
  uint8_t bits;
  uint16_t code;
  int16_t value;
};
const Code kDeltaGainCodes[] = {
    {2, 0x2, 1},      {2, 0x3, -1},     {3, 0x2, 0},      {3, 0x1, -2},     {4, 0x0, -16},
    {5, 0x02, -4},    {5, 0x0E, 8},     {5, 0x0F, -3},    {6, 0x06, 3},     {6, 0x18, 2},
    {6, 0x19, -5},    {7, 0x0F, -8},    {7, 0x34, -7},    {7, 0x36, -6},    {7, 0x37, 4},
    {8, 0x1D, 5},     {9, 0x039, -15},  {9, 0x0D5, -9},   {9, 0x0D4, 7},    {9, 0x0D7, 6},
    {10, 0x070, -12}, {10, 0x1AC, -11}, {10, 0x1AD, -10}, {11, 0x0E2, -14}, {11, 0x0E3, -13},
};

const Code* delta_code(int delta) {
  for (const Code& c : kDeltaGainCodes) {
    if (c.value == delta) {
      return &c;
    }
  }
  return nullptr;
}

int ceil_log2(int n) {
  int z = 0;
  while ((1 << z) < n) {
    z++;
  }
  return z;
}

int gain_code(float db) { return (int)lrintf(db * 8.0f); }

/* The absolute gain of a frame's first node per gain coding profile;
 * false when the profile cannot carry it. */
bool write_initial_gain(AacBitWriter* w, int profile, int code) {
  switch (profile) {
    case AAC_UNIDRC_PROFILE_REGULAR:
      if (code < -kMaxGainCode || code > kMaxGainCode) {
        return false;
      }
      aac_bitwriter_write(w, code < 0 ? 1u : 0u, 1);
      aac_bitwriter_write(w, (uint32_t)std::abs(code), 8);
      return true;
    case AAC_UNIDRC_PROFILE_FADING:
    case AAC_UNIDRC_PROFILE_CLIPPING_DUCKING: {
      const int bits = profile == AAC_UNIDRC_PROFILE_FADING ? 10 : 8;
      if (code > 0 || -code - 1 >= (1 << bits)) {
        return false;
      }
      aac_bitwriter_write(w, code < 0 ? 1u : 0u, 1);
      if (code < 0) {
        aac_bitwriter_write(w, (uint32_t)(-code - 1), bits);
      }
      return true;
    }
    default:
      return false;
  }
}

/* deltaTime in units of deltaTmin: 1, 2 to 5, 6 to 13, or 14 and up in
 * a Z-bit field. */
bool write_time_delta(AacBitWriter* w, int d, int z_bits) {
  if (d == 1) {
    aac_bitwriter_write(w, 0, 2);
  } else if (d >= 2 && d <= 5) {
    aac_bitwriter_write(w, 1, 2);
    aac_bitwriter_write(w, (uint32_t)(d - 2), 2);
  } else if (d >= 6 && d <= 13) {
    aac_bitwriter_write(w, 2, 2);
    aac_bitwriter_write(w, (uint32_t)(d - 6), 3);
  } else if (d >= 14 && d - 14 < (1 << z_bits)) {
    aac_bitwriter_write(w, 3, 2);
    aac_bitwriter_write(w, (uint32_t)(d - 14), z_bits);
  } else {
    return false;
  }
  return true;
}

/* drcGainSequence() of one gain set in one frame. */
int write_sequence(AacBitWriter* w, const AacUniDrcGainSet* gs, const AacUniDrcSequence* s,
                   int frame_length, int sample_rate) {
  const int dtmin = aac_unidrc_time_delta_min(gs, sample_rate);
  const int n_time_delta = frame_length / dtmin;
  const int n = s->node_count;
  if (n_time_delta < 1 || n < 1 || n > AAC_UNIDRC_MAX_NODES) {
    return AAC_ERR_INVALID_ARG;
  }
  const int z_bits = ceil_log2(n_time_delta) + 1;
  const int offset = gs->time_alignment ? -1 - dtmin / 2 : -1;
  const int end_time = n_time_delta * dtmin + offset;
  const int compact = n == 1 && s->node[0].time == end_time;
  if (gs->full_frame && !compact) {
    return AAC_ERR_INVALID_ARG;
  }
  if (!gs->full_frame) {
    aac_bitwriter_write(w, compact ? 0u : 1u, 1); /* drcGainCodingMode */
  }
  if (compact) {
    return write_initial_gain(w, gs->gain_coding_profile, gain_code(s->node[0].gain_db))
               ? AAC_OK
               : AAC_ERR_INVALID_ARG;
  }
  if (gs->gain_interpolation_type == 0) {
    return AAC_ERR_INVALID_ARG; /* the slopes of the spline type are not written */
  }
  /* the node count: a zero per node beyond the first, then a one */
  for (int k = 1; k < n; k++) {
    aac_bitwriter_write(w, 0, 1);
  }
  aac_bitwriter_write(w, 1, 1);
  const int frame_end = s->node[n - 1].time == end_time;
  aac_bitwriter_write(w, frame_end ? 1u : 0u, 1);
  int prev = offset;
  for (int k = 0; k < n - frame_end; k++) {
    const int dt = s->node[k].time - prev;
    if (dt <= 0 || dt % dtmin != 0 || s->node[k].time > end_time ||
        !write_time_delta(w, dt / dtmin, z_bits)) {
      return AAC_ERR_INVALID_ARG;
    }
    prev = s->node[k].time;
  }
  if (frame_end && n > 1 && s->node[n - 2].time >= end_time) {
    return AAC_ERR_INVALID_ARG;
  }
  int code = gain_code(s->node[0].gain_db);
  if (!write_initial_gain(w, gs->gain_coding_profile, code)) {
    return AAC_ERR_INVALID_ARG;
  }
  for (int k = 1; k < n; k++) {
    const int next = gain_code(s->node[k].gain_db);
    const Code* c = delta_code(next - code);
    if (!c) {
      return AAC_ERR_INVALID_ARG;
    }
    aac_bitwriter_write(w, c->code, c->bits);
    code = next;
  }
  return AAC_OK;
}

/* The gain set that owns gain sequence `seq`, and the sequence count of
 * a configuration, as the reader finds them. */
const AacUniDrcGainSet* owner_of(const AacUniDrcConfig* c, int seq) {
  for (int i = 0; i < c->coefficients_count; i++) {
    const AacUniDrcCoefficients* co = &c->coefficients[i];
    for (int s = 0; s < co->gain_set_count; s++) {
      for (int b = 0; b < co->gain_set[s].band_count; b++) {
        if (co->gain_set[s].band[b].gain_sequence_index == seq) {
          return &co->gain_set[s];
        }
      }
    }
  }
  return nullptr;
}

int sequence_count(const AacUniDrcConfig* c) {
  int n = 0;
  for (int i = 0; i < c->coefficients_count; i++) {
    const AacUniDrcCoefficients* co = &c->coefficients[i];
    for (int s = 0; s < co->gain_set_count; s++) {
      for (int b = 0; b < co->gain_set[s].band_count; b++) {
        n = std::max(n, co->gain_set[s].band[b].gain_sequence_index + 1);
      }
    }
  }
  return n;
}

/* gainSetParams() of version 0 or 1; `next_sequence` numbers the gain
 * sequences as the reader does when no index is signalled. */
bool write_gain_set(AacBitWriter* w, const AacUniDrcGainSet* gs, int version, int* next_sequence) {
  if (gs->gain_coding_profile < 0 || gs->gain_coding_profile > 3 || gs->time_delta_min < 0 ||
      gs->time_delta_min > 2048) {
    return false;
  }
  aac_bitwriter_write(w, (uint32_t)gs->gain_coding_profile, 2);
  aac_bitwriter_write(w, gs->gain_interpolation_type ? 1u : 0u, 1);
  aac_bitwriter_write(w, gs->full_frame ? 1u : 0u, 1);
  aac_bitwriter_write(w, gs->time_alignment ? 1u : 0u, 1);
  aac_bitwriter_write(w, gs->time_delta_min > 0 ? 1u : 0u, 1);
  if (gs->time_delta_min > 0) {
    aac_bitwriter_write(w, (uint32_t)(gs->time_delta_min - 1), 11);
  }
  if (gs->gain_coding_profile == AAC_UNIDRC_PROFILE_CONSTANT) {
    (*next_sequence)++;
    return true;
  }
  if (gs->band_count < 1 || gs->band_count > AAC_UNIDRC_MAX_BANDS) {
    return false;
  }
  aac_bitwriter_write(w, (uint32_t)gs->band_count, 4);
  if (gs->band_count > 1) {
    aac_bitwriter_write(w, gs->drc_band_type ? 1u : 0u, 1);
  }
  for (int b = 0; b < gs->band_count; b++) {
    const AacUniDrcBand* band = &gs->band[b];
    if (version == 0) {
      if (band->gain_sequence_index != *next_sequence) {
        return false; /* version 0 numbers the sequences in order */
      }
      (*next_sequence)++;
      aac_bitwriter_write(w, (uint32_t)(band->characteristic & 0x7F), 7);
      continue;
    }
    const int indexed = band->gain_sequence_index != *next_sequence;
    aac_bitwriter_write(w, indexed ? 1u : 0u, 1); /* indexPresent */
    if (indexed) {
      aac_bitwriter_write(w, (uint32_t)(band->gain_sequence_index & 0x3F), 6);
    }
    *next_sequence = band->gain_sequence_index + 1;
    const int cicp = band->characteristic > 0;
    const int custom = band->characteristic_left >= 0 || band->characteristic_right >= 0;
    aac_bitwriter_write(w, cicp || custom ? 1u : 0u, 1); /* drcCharacteristicPresent */
    if (cicp) {
      aac_bitwriter_write(w, 1, 1); /* drcCharacteristicFormatIsCICP */
      aac_bitwriter_write(w, (uint32_t)(band->characteristic & 0x7F), 7);
    } else if (custom) {
      aac_bitwriter_write(w, 0, 1);
      aac_bitwriter_write(w, (uint32_t)(std::max(0, band->characteristic_left) & 0xF), 4);
      aac_bitwriter_write(w, (uint32_t)(std::max(0, band->characteristic_right) & 0xF), 4);
    }
  }
  for (int b = 1; b < gs->band_count; b++) {
    if (gs->drc_band_type) {
      aac_bitwriter_write(w, (uint32_t)(gs->band[b].crossover_freq_index & 0xF), 4);
    } else {
      aac_bitwriter_write(w, (uint32_t)(gs->band[b].start_sub_band_index & 0x3FF), 10);
    }
  }
  return true;
}

/* drcCoefficientsUniDrc() (version 0) and drcCoefficientsUniDrcV1()
 * without custom characteristics or shape filters. */
bool write_coefficients(AacBitWriter* w, const AacUniDrcCoefficients* co) {
  if (co->gain_set_count < 0 || co->gain_set_count > AAC_UNIDRC_MAX_GAIN_SETS ||
      co->drc_frame_size < 0 || co->drc_frame_size > (1 << 15) || co->characteristic_left_count ||
      co->characteristic_right_count || co->shape_filter_count) {
    return false;
  }
  aac_bitwriter_write(w, (uint32_t)(co->drc_location & 0xF), 4);
  aac_bitwriter_write(w, co->drc_frame_size > 0 ? 1u : 0u, 1);
  if (co->drc_frame_size > 0) {
    aac_bitwriter_write(w, (uint32_t)(co->drc_frame_size - 1), 15);
  }
  if (co->version == 1) {
    aac_bitwriter_write(w, 0, 3); /* drcCharacteristicLeft/RightPresent, shapeFilterPresent */
    aac_bitwriter_write(w, (uint32_t)(co->gain_sequence_count & 0x3F), 6);
  }
  aac_bitwriter_write(w, (uint32_t)co->gain_set_count, 6);
  int next_sequence = 0;
  for (int s = 0; s < co->gain_set_count; s++) {
    if (!write_gain_set(w, &co->gain_set[s], co->version, &next_sequence)) {
      return false;
    }
  }
  return true;
}

/* The bands of the gain set a channel group uses (gain modifiers of
 * version 1 come per band). */
int group_band_count(const AacUniDrcConfig* c, int version, int gain_set_index) {
  for (int i = 0; i < c->coefficients_count; i++) {
    const AacUniDrcCoefficients* co = &c->coefficients[i];
    if (co->version == version && gain_set_index < co->gain_set_count) {
      return co->gain_set[gain_set_index].band_count;
    }
  }
  return -1;
}

/* drcInstructionsUniDrc() (version 0) and drcInstructionsUniDrcV1() on
 * the base layout without ducking; the modifiers of the first band are
 * repeated for every band of a version 1 group. */
bool write_instructions(AacBitWriter* w, const AacUniDrcConfig* c,
                        const AacUniDrcInstructions* in) {
  const int channels = c->base_channel_count;
  const int v1 = in->version == 1;
  if (in->downmix_id != 0 ||
      (in->drc_set_effect & (AAC_UNIDRC_EFFECT_DUCK_OTHER | AAC_UNIDRC_EFFECT_DUCK_SELF)) ||
      in->channel_count != channels || in->group_count < 0) {
    return false;
  }
  aac_bitwriter_write(w, (uint32_t)(in->drc_set_id & 0x3F), 6);
  if (v1) {
    aac_bitwriter_write(w, (uint32_t)(in->complexity_level & 0xF), 4);
  }
  aac_bitwriter_write(w, (uint32_t)(in->drc_location & 0xF), 4);
  aac_bitwriter_write(w, 0, 1); /* downmixIdPresent: the base layout */
  aac_bitwriter_write(w, in->drc_set_effect & 0xFFFFu, 16);
  aac_bitwriter_write(w, in->limiter_peak_target_present ? 1u : 0u, 1);
  if (in->limiter_peak_target_present) {
    const int code = (int)lrintf(-in->limiter_peak_target_db * 8.0f);
    if (code < 0 || code > 255) {
      return false;
    }
    aac_bitwriter_write(w, (uint32_t)code, 8);
  }
  aac_bitwriter_write(w, in->target_loudness_present ? 1u : 0u, 1);
  if (in->target_loudness_present) {
    aac_bitwriter_write(w, (uint32_t)((in->target_loudness_upper + 63) & 0x3F), 6);
    const int lower = in->target_loudness_lower != -63;
    aac_bitwriter_write(w, lower ? 1u : 0u, 1);
    if (lower) {
      aac_bitwriter_write(w, (uint32_t)((in->target_loudness_lower + 63) & 0x3F), 6);
    }
  }
  aac_bitwriter_write(w, in->depends_on_drc_set >= 0 ? 1u : 0u, 1);
  if (in->depends_on_drc_set >= 0) {
    aac_bitwriter_write(w, (uint32_t)(in->depends_on_drc_set & 0x3F), 6);
  } else {
    aac_bitwriter_write(w, in->no_independent_use ? 1u : 0u, 1);
  }
  if (v1) {
    aac_bitwriter_write(w, in->requires_eq ? 1u : 0u, 1);
  }
  /* bsGainSetIndex per channel, runs of the same index repeated */
  for (int ch = 0; ch < channels;) {
    const int gsi = in->gain_set_index[ch];
    int run = 1;
    while (ch + run < channels && in->gain_set_index[ch + run] == gsi && run < 33) {
      run++;
    }
    aac_bitwriter_write(w, (uint32_t)((gsi + 1) & 0x3F), 6);
    aac_bitwriter_write(w, run > 1 ? 1u : 0u, 1); /* repeatGainSetIndex */
    if (run > 1) {
      aac_bitwriter_write(w, (uint32_t)(run - 2), 5);
    }
    ch += run;
  }
  /* gainModifiers() per channel group, in order of first use */
  for (int g = 0; g < in->group_count; g++) {
    const AacUniDrcGroup* grp = &in->group[g];
    const int bands = v1 ? group_band_count(c, 1, grp->gain_set_index) : 1;
    if (bands < 1) {
      return false;
    }
    for (int b = 0; b < bands; b++) {
      if (v1) {
        aac_bitwriter_write(w, 0, 2); /* targetCharacteristicLeft/RightPresent */
      }
      const int scaling = grp->attenuation_scaling != 1.0f || grp->amplification_scaling != 1.0f;
      aac_bitwriter_write(w, scaling ? 1u : 0u, 1);
      if (scaling) {
        const int att = (int)lrintf(grp->attenuation_scaling * 8.0f);
        const int amp = (int)lrintf(grp->amplification_scaling * 8.0f);
        if (att < 0 || att > 15 || amp < 0 || amp > 15) {
          return false;
        }
        aac_bitwriter_write(w, (uint32_t)att, 4);
        aac_bitwriter_write(w, (uint32_t)amp, 4);
      }
      aac_bitwriter_write(w, grp->gain_offset_db != 0.0f ? 1u : 0u, 1);
      if (grp->gain_offset_db != 0.0f) {
        const int mag = (int)lrintf(fabsf(grp->gain_offset_db) * 4.0f) - 1;
        if (mag < 0 || mag > 31) {
          return false;
        }
        aac_bitwriter_write(w, (uint32_t)((grp->gain_offset_db < 0.0f ? 32 : 0) | mag), 6);
      }
    }
    if (v1) {
      aac_bitwriter_write(w, 0, 1); /* shapeFilterPresent */
    }
  }
  return true;
}

/* The coefficients and instructions of one version, as the base syntax
 * (version 0) or the V1 configuration extension lists them. */
bool write_version(AacBitWriter* w, const AacUniDrcConfig* c, int version, int count_bits_coef,
                   int count_bits_instr) {
  int nc = 0, ni = 0;
  for (int i = 0; i < c->coefficients_count; i++) {
    nc += c->coefficients[i].version == version;
  }
  for (int i = 0; i < c->instructions_count; i++) {
    ni += c->instructions[i].version == version;
  }
  if (nc >= (1 << count_bits_coef) || ni >= (1 << count_bits_instr)) {
    return false;
  }
  aac_bitwriter_write(w, (uint32_t)nc, count_bits_coef);
  if (version == 0) {
    /* the base syntax: both counts, then the channel layout, then both lists */
    aac_bitwriter_write(w, (uint32_t)ni, count_bits_instr);
    aac_bitwriter_write(w, (uint32_t)c->base_channel_count, 7);
    aac_bitwriter_write(w, 0, 1); /* layoutSignalingPresent */
  }
  for (int i = 0; i < c->coefficients_count; i++) {
    if (c->coefficients[i].version == version && !write_coefficients(w, &c->coefficients[i])) {
      return false;
    }
  }
  if (version == 1) {
    aac_bitwriter_write(w, (uint32_t)ni, count_bits_instr);
  }
  for (int i = 0; i < c->instructions_count; i++) {
    if (c->instructions[i].version == version && !write_instructions(w, c, &c->instructions[i])) {
      return false;
    }
  }
  return true;
}

/* ── the generator ─────────────────────────────────────────────── */

constexpr int kMaxChannels = 6;
/* K-weighting (BS.1770-4 clause 2): the channel weights of the canonical
 * order L, R, C, LFE, Ls, Rs and the loudness formula's offset. */
const double kChannelWeight[kMaxChannels] = {1.0, 1.0, 1.0, 0.0, 1.41, 1.41};
constexpr double kLoudnessOffsetDb = -0.691;
constexpr float kSilenceLkfs = -70.0f; /* the absolute gate */
constexpr float kRelativeGateLu = -10.0f;
/* The compressor: the level window, the smoothing, the limits of the
 * static curve and the band below the anchor where the boost fades out. */
constexpr double kWindowSeconds = 0.4;
constexpr double kHopSeconds = 0.1;
constexpr double kAttackSeconds = 0.05;
constexpr double kReleaseSeconds = 1.5;
constexpr double kLookaheadSeconds = 0.02;
constexpr float kMaxBoostDb = 12.0f;
constexpr float kMaxCutDb = 24.0f;
constexpr float kBoostFadeStartLu = -30.0f;
constexpr float kBoostFadeEndLu = -40.0f;
constexpr float kMaxRatio = 8.0f;
/* The loudness range the attack and release leave above the static
 * curve's, in LU, which the ratio makes up for. */
constexpr float kSmoothingLossLu = 1.5f;
/* The limiter: its lead-in per grid step, its release, and the grid
 * steps ahead of a frame it looks at (enough lead-in for the whole gain
 * range). */
constexpr float kLeadInDbPerStep = 1.0f;
constexpr double kLimiterReleaseSeconds = 0.05;
constexpr int kHorizonSteps = 24;
/* The true-peak interpolator: 4 times oversampling through a
 * Hann-windowed sinc of 16 taps per phase (x[t - 7] .. x[t + 8]). */
constexpr int kOversample = 4;
constexpr int kTpTaps = 16;
constexpr int kTpBefore = 7;
constexpr int kTpAfter = kTpTaps - kTpBefore - 1; /* 8 samples of lookahead */
constexpr double kTpCutoff = 0.9;
/* Thinning tolerances of the node lists (dB), and the payload size per
 * frame beyond which they widen. */
constexpr float kTolerance = 0.25f;
constexpr int kPayloadBudgetBytes = 128;
/* The integrated loudness estimate: histogram bins per LU over the gated
 * range. */
constexpr int kHistBinsPerLu = 10;
constexpr float kHistMinLkfs = -70.0f;
constexpr float kHistMaxLkfs = 10.0f;
constexpr int kHistBins = (int)((kHistMaxLkfs - kHistMinLkfs) * kHistBinsPerLu);

struct Biquad {
  double b0, b1, b2, a1, a2;
};

/* The K-weighting's two biquads, derived for the sample rate from the
 * analogue prototypes behind the 48 kHz table of BS.1770-4: a +4 dB high
 * shelf at 1681.97 Hz and a high-pass at 38.135 Hz, through the bilinear
 * transform with pre-warping. */
Biquad k_shelf(double rate) {
  const double f0 = 1681.974450955533, gain_db = 3.999843853973347, q = 0.7071752369554196;
  const double k = tan(M_PI * f0 / rate);
  const double vh = pow(10.0, gain_db / 20.0);
  const double vb = pow(vh, 0.4996667741545416);
  const double a0 = 1.0 + k / q + k * k;
  return {(vh + vb * k / q + k * k) / a0, 2.0 * (k * k - vh) / a0, (vh - vb * k / q + k * k) / a0,
          2.0 * (k * k - 1.0) / a0, (1.0 - k / q + k * k) / a0};
}

Biquad k_highpass(double rate) {
  const double f0 = 38.13547087602444, q = 0.5003270373238773;
  const double k = tan(M_PI * f0 / rate);
  const double a0 = 1.0 + k / q + k * k;
  return {1.0, -2.0, 1.0, 2.0 * (k * k - 1.0) / a0, (1.0 - k / q + k * k) / a0};
}

struct BiquadState {
  double z1, z2;
};

double run_biquad(const Biquad& b, BiquadState* s, double x) {
  const double y = b.b0 * x + s->z1;
  s->z1 = b.b1 * x - b.a1 * y + s->z2;
  s->z2 = b.b2 * x - b.a2 * y;
  return y;
}

double power_to_lkfs(double p) { return p > 1e-20 ? kLoudnessOffsetDb + 10.0 * log10(p) : -200.0; }

float lin_to_db(float v) { return v > 1e-10f ? 20.0f * log10f(v) : -200.0f; }

float code_to_lin(int code) { return powf(10.0f, (float)code / 160.0f); }

}  // namespace

struct AacDrcGen_ {
  AacDrcGenParams p;
  int grid;          /* deltaTmin: samples per grid step and per level block */
  int steps;         /* grid steps per frame */
  int window_blocks; /* blocks of the 400 ms level window */
  int hop_blocks;    /* blocks per 100 ms, the integrated estimate's step */
  int lag;           /* input samples from an input sample to the grid point its gain sits on */
  int lookahead_blocks;
  float attack, release; /* smoothing coefficients per block */
  float limiter_release; /* per grid step */
  float ratio;
  /* the input history and its true peak per sample, by input sample index */
  int hist_len;
  float* hist;   /* hist_len * channels, interleaved */
  float* peak;   /* hist_len: the true peak in dBTP */
  long long fed; /* input samples fed */
  long long frame_index;
  /* the level detector */
  Biquad shelf, highpass;
  BiquadState ks[kMaxChannels][2];
  double block_energy; /* weighted sum of squares of the open block */
  double* window;      /* window_blocks block energies, a ring */
  double window_sum;
  long long blocks; /* completed blocks */
  float smooth_db;  /* the compressor's smoothed gain */
  int gain_len;
  float* gain; /* compressor gain per block, by block index (ring of gain_len) */
  /* the integrated loudness estimate of a one-pass generator */
  double hist_power[kHistBins];
  int hist_count[kHistBins];
  float anchor;
  int anchor_valid;
  /* the peak bounds' reduction at the last emitted grid point (the
   * general set's, the night set's), and the last node code of each
   * sequence */
  float limiter_last[2];
  int last_code[2];
  /* per-frame scratch */
  float* comp_db;
  float* night_db;
  int* codes;
  float* scratch; /* frame_length * channels: a DRC set's output for its meter */
  float* gain_curve;
  float tp_taps[kOversample][kTpTaps];
  struct AacLoudnessMeter_* meter[AAC_DRC_GEN_SETS];
  AacUniDrcConfig config;
};

namespace {

float hist_sample(const AacDrcGen* g, long long t, int ch) {
  if (t < 0 || t >= g->fed || t < g->fed - g->hist_len) {
    return 0.0f;
  }
  return g->hist[(size_t)(t % g->hist_len) * g->p.channels + ch];
}

/* The true peak at input sample t in dBTP (-200 for silence or outside
 * the history). */
float peak_at(const AacDrcGen* g, long long t) {
  if (t < 0 || t >= g->fed - kTpAfter || t < g->fed - g->hist_len) {
    return -200.0f;
  }
  return g->peak[t % g->hist_len];
}

/* The compressor's gain at input sample t: the gain of its block. */
float comp_gain_at(const AacDrcGen* g, long long t) {
  if (t < 0) {
    return 0.0f;
  }
  const long long b = t / g->grid;
  if (b + g->lookahead_blocks >= g->blocks || b < g->blocks - g->lookahead_blocks - g->gain_len) {
    return 0.0f; /* not available: never asked for when the lookahead fits the delay */
  }
  return g->gain[b % g->gain_len];
}

/* The interpolation taps of the true-peak phases between two samples. */
void true_peak_taps(float taps[kOversample][kTpTaps]) {
  for (int ph = 1; ph < kOversample; ph++) {
    const double frac = (double)ph / kOversample;
    double sum = 0.0;
    for (int i = 0; i < kTpTaps; i++) {
      const double x = (double)(i - kTpBefore) - frac;
      const double sinc = sin(M_PI * kTpCutoff * x) / (M_PI * kTpCutoff * x);
      const double win = 0.5 * (1.0 + cos(M_PI * x / (kTpBefore + 1.5)));
      taps[ph][i] = (float)(sinc * win);
      sum += taps[ph][i];
    }
    for (int i = 0; i < kTpTaps; i++) {
      taps[ph][i] = (float)(taps[ph][i] / sum); /* unity gain at DC */
    }
  }
}

/* The 4-times-oversampled peak of sample t, from x[t - 7] .. x[t + 8]. */
float true_peak(const AacDrcGen* g, long long t) {
  float peak = 0.0f;
  for (int c = 0; c < g->p.channels; c++) {
    float x[kTpTaps];
    for (int i = 0; i < kTpTaps; i++) {
      x[i] = hist_sample(g, t - kTpBefore + i, c);
    }
    peak = std::max(peak, fabsf(x[kTpBefore]));
    for (int ph = 1; ph < kOversample; ph++) {
      float v = 0.0f;
      for (int i = 0; i < kTpTaps; i++) {
        v += g->tp_taps[ph][i] * x[i];
      }
      peak = std::max(peak, fabsf(v));
    }
  }
  return lin_to_db(peak);
}

/* The integrated loudness of the blocks seen so far: the absolute gate,
 * then the relative gate 10 LU below their mean (BS.1770-4 clause 4). */
void update_anchor(AacDrcGen* g) {
  double sum = 0.0;
  int count = 0;
  for (int i = 0; i < kHistBins; i++) {
    sum += g->hist_power[i];
    count += g->hist_count[i];
  }
  if (count == 0) {
    return;
  }
  const double gate = power_to_lkfs(sum / count) + kRelativeGateLu;
  const int first = std::clamp((int)ceil((gate - kHistMinLkfs) * kHistBinsPerLu), 0, kHistBins);
  sum = 0.0;
  count = 0;
  for (int i = first; i < kHistBins; i++) {
    sum += g->hist_power[i];
    count += g->hist_count[i];
  }
  if (count > 0) {
    g->anchor = (float)power_to_lkfs(sum / count);
    g->anchor_valid = 1;
  }
}

/* A completed block: the window's level, the curve, the smoothing. */
void finish_block(AacDrcGen* g) {
  const int slot = (int)(g->blocks % g->window_blocks);
  g->window_sum += g->block_energy - g->window[slot];
  g->window[slot] = g->block_energy;
  g->block_energy = 0.0;
  g->blocks++;
  if (g->blocks % 4096 == 0) {
    /* bound the rounding drift of the running sum */
    g->window_sum = 0.0;
    for (int i = 0; i < g->window_blocks; i++) {
      g->window_sum += g->window[i];
    }
  }
  const double power = std::max(0.0, g->window_sum) / ((double)g->window_blocks * g->grid);
  const float level = (float)power_to_lkfs(power);
  if (!g->p.has_anchor && g->blocks >= g->window_blocks && g->blocks % g->hop_blocks == 0 &&
      level > kHistMinLkfs) {
    const int bin = std::min(kHistBins - 1, (int)((level - kHistMinLkfs) * kHistBinsPerLu));
    g->hist_power[bin] += power;
    g->hist_count[bin]++;
    update_anchor(g);
  }
  float target = 0.0f;
  if (g->anchor_valid) {
    target = aac_drc_gen_static_gain(level, g->anchor, g->p.target_range_lu, g->ratio);
  }
  g->smooth_db += (target - g->smooth_db) * (target < g->smooth_db ? g->attack : g->release);
  /* the gain belongs to the block the lookahead puts it on */
  const long long b = g->blocks - 1 - g->lookahead_blocks;
  if (b >= 0) {
    g->gain[b % g->gain_len] = g->smooth_db;
  }
}

void feed(AacDrcGen* g, const float* pcm, int n) {
  const int nch = g->p.channels;
  for (int i = 0; i < n; i++) {
    const long long t = g->fed;
    float* slot = g->hist + (size_t)(t % g->hist_len) * nch;
    double e = 0.0;
    for (int c = 0; c < nch; c++) {
      const float x = pcm ? pcm[(size_t)i * nch + c] : 0.0f;
      slot[c] = x;
      const double y = run_biquad(g->highpass, &g->ks[c][1], run_biquad(g->shelf, &g->ks[c][0], x));
      e += kChannelWeight[c] * y * y;
    }
    g->block_energy += e;
    g->fed++;
    if (g->fed % g->grid == 0) {
      finish_block(g);
    }
    const long long tp = g->fed - 1 - kTpAfter;
    if (tp >= 0) {
      g->peak[tp % g->hist_len] = true_peak(g, tp);
    }
  }
}

/* Keeps the codes within the coding's gain delta range from one grid
 * step to the next (rise at most 1 dB, fall at most 2 dB), lowering
 * gains only. */
void constrain(int* codes, int n) {
  for (int iter = 0; iter < 8; iter++) {
    bool changed = false;
    for (int i = n - 2; i >= 0; i--) {
      if (codes[i] > codes[i + 1] - kMinDeltaCode) {
        codes[i] = codes[i + 1] - kMinDeltaCode;
        changed = true;
      }
    }
    for (int i = 1; i < n; i++) {
      if (codes[i] > codes[i - 1] + kMaxDeltaCode) {
        codes[i] = codes[i - 1] + kMaxDeltaCode;
        changed = true;
      }
    }
    if (!changed) {
      break;
    }
  }
}

/* Picks the nodes of one frame from the grid codes: from the previous
 * frame's last node (at time -1) the farthest grid point whose line
 * (linear in the linear gain, as the decoder interpolates) stays within
 * [-tol_below, tol_above] dB of every grid point it passes (never above
 * the points a peak bound holds, where `bound` is set), and whose code
 * is within the delta range of the node before it. */
void thin(const int* codes, const bool* bound, int steps, int grid, int prev_code, float tol_below,
          float tol_above, AacUniDrcSequence* s) {
  s->node_count = 0;
  int cur = 0; /* grid index of the last node, 0 the previous frame's */
  int cur_code = prev_code;
  while (cur < steps) {
    int best = cur + 1;
    for (int j = cur + 2; j <= steps; j++) {
      const int cj = codes[j - 1];
      if (cur > 0 && (cj - cur_code < kMinDeltaCode || cj - cur_code > kMaxDeltaCode)) {
        continue;
      }
      const float a = code_to_lin(cur_code), b = code_to_lin(cj);
      bool ok = true;
      for (int i = cur + 1; i < j && ok; i++) {
        const float u = (float)(i - cur) / (float)(j - cur);
        const float diff = lin_to_db(a + (b - a) * u) - (float)codes[i - 1] / 8.0f;
        ok = diff <= (bound[i - 1] ? 0.0f : tol_above) + 1e-4f && diff >= -tol_below - 1e-4f;
      }
      if (ok) {
        best = j;
      }
    }
    AacUniDrcNode* node = &s->node[s->node_count++];
    node->time = best * grid - 1;
    node->gain_db = (float)codes[best - 1] / 8.0f;
    node->slope = 0.0f;
    cur = best;
    cur_code = codes[best - 1];
  }
}

/* The decoder's gain over the frame for one sequence: linear
 * interpolation of the linear gain from the previous frame's last node
 * (at -1) through the frame's nodes. */
void decoded_curve(int prev_code, const AacUniDrcSequence* s, float* out, int n) {
  int t0 = -1;
  float g0 = code_to_lin(prev_code);
  int k = 0;
  for (int t = 0; t < n; t++) {
    while (k < s->node_count && s->node[k].time < t) {
      t0 = s->node[k].time;
      g0 = powf(10.0f, s->node[k].gain_db / 20.0f);
      k++;
    }
    if (k >= s->node_count) {
      out[t] = g0;
      continue;
    }
    const float g1 = powf(10.0f, s->node[k].gain_db / 20.0f);
    const float u = (float)(t - t0) / (float)(s->node[k].time - t0);
    out[t] = g0 + (g1 - g0) * u;
  }
}

}  // namespace

float aac_drc_gen_static_gain(float level, float anchor, int target_range_lu, float ratio) {
  if (level <= kSilenceLkfs || ratio <= 1.0f) {
    return 0.0f;
  }
  const float d = level - anchor;
  const float band = (float)target_range_lu / 4.0f;
  const float slope = 1.0f - 1.0f / ratio;
  if (d > band) {
    return std::max(-kMaxCutDb, -(d - band) * slope);
  }
  if (d < -band) {
    float boost = std::min(kMaxBoostDb, (-d - band) * slope);
    if (d < kBoostFadeStartLu) {
      boost *=
          std::clamp((d - kBoostFadeEndLu) / (kBoostFadeStartLu - kBoostFadeEndLu), 0.0f, 1.0f);
    }
    return boost;
  }
  return 0.0f;
}

void aac_drc_gen_config(AacUniDrcConfig* c, int channels) {
  memset(c, 0, sizeof(*c));
  c->base_channel_count = channels;
  c->coefficients_count = 1;
  AacUniDrcCoefficients* co = &c->coefficients[0];
  co->version = 1;
  co->drc_location = kDrcLocationPayload;
  co->gain_set_count = 2;
  co->gain_sequence_count = 2;
  for (int s = 0; s < 2; s++) {
    AacUniDrcGainSet* gs = &co->gain_set[s];
    gs->gain_coding_profile = AAC_UNIDRC_PROFILE_REGULAR;
    gs->gain_interpolation_type = 1;
    gs->band_count = 1;
    gs->band[0].gain_sequence_index = s;
    gs->band[0].characteristic = 0; /* not signalled */
    gs->band[0].characteristic_left = gs->band[0].characteristic_right = -1;
  }
  c->instructions_count = 2;
  for (int i = 0; i < 2; i++) {
    AacUniDrcInstructions* in = &c->instructions[i];
    in->version = 1;
    in->complexity_level = kComplexityLevel;
    in->drc_set_id = i == 0 ? AAC_DRC_GEN_SET_GENERAL : AAC_DRC_GEN_SET_NIGHT;
    in->drc_location = kDrcLocationPayload;
    in->drc_set_effect = i == 0 ? AAC_UNIDRC_EFFECT_GENERAL : AAC_UNIDRC_EFFECT_NIGHT;
    in->limiter_peak_target_present = i == 1;
    in->limiter_peak_target_db = i == 1 ? AAC_DRC_GEN_PEAK_TARGET_DB : 0.0f;
    in->target_loudness_upper = 0;
    in->target_loudness_lower = -63;
    in->depends_on_drc_set = -1;
    in->channel_count = channels;
    for (int ch = 0; ch < channels; ch++) {
      in->gain_set_index[ch] = i;
      in->ducking_scaling[ch] = 1.0f;
    }
    in->group_count = 1;
    in->group[0].gain_set_index = i;
    in->group[0].attenuation_scaling = in->group[0].amplification_scaling = 1.0f;
    in->group[0].shape_filter_index = -1;
  }
}

int aac_drc_gen_write_config(AacBitWriter* w, const AacUniDrcConfig* c) {
  if (!w || !c || c->base_channel_count < 1 || c->base_channel_count > 127 ||
      c->downmix_count != 0 || c->coefficients_count < 0 || c->coefficients_count > 2 ||
      c->instructions_count < 0 || c->instructions_count > AAC_UNIDRC_MAX_INSTRUCTIONS ||
      c->sample_rate < 0 ||
      (c->sample_rate > 0 && (c->sample_rate < 1000 || c->sample_rate - 1000 >= (1 << 18)))) {
    return AAC_ERR_INVALID_ARG;
  }
  int has_v1 = 0;
  for (int i = 0; i < c->coefficients_count; i++) {
    if (c->coefficients[i].version < 0 || c->coefficients[i].version > 1) {
      return AAC_ERR_INVALID_ARG;
    }
    has_v1 |= c->coefficients[i].version;
  }
  for (int i = 0; i < c->instructions_count; i++) {
    if (c->instructions[i].version < 0 || c->instructions[i].version > 1) {
      return AAC_ERR_INVALID_ARG;
    }
    has_v1 |= c->instructions[i].version;
  }
  const int start = aac_bitwriter_bits_written(w);
  aac_bitwriter_write(w, c->sample_rate > 0 ? 1u : 0u, 1);
  if (c->sample_rate > 0) {
    aac_bitwriter_write(w, (uint32_t)(c->sample_rate - 1000), 18);
  }
  aac_bitwriter_write(w, 0, 7); /* downmixInstructionsCount */
  aac_bitwriter_write(w, 0, 1); /* drcDescriptionBasicPresent */
  if (!write_version(w, c, 0, 3, 6)) {
    return AAC_ERR_INVALID_ARG;
  }
  aac_bitwriter_write(w, has_v1 ? 1u : 0u, 1); /* uniDrcConfigExtPresent */
  if (has_v1) {
    /* uniDrcConfigExtension() with UNIDRCCONFEXT_V1: its payload is
     * written aside first, for its size */
    uint8_t ext[512];
    AacBitWriter xw;
    aac_bitwriter_init(&xw, ext, (int)sizeof(ext));
    aac_bitwriter_write(&xw, 0, 1); /* downmixInstructionsV1Present */
    aac_bitwriter_write(&xw, 1, 1); /* drcCoeffsAndInstructionsUniDrcV1Present */
    if (!write_version(&xw, c, 1, 3, 6)) {
      return AAC_ERR_INVALID_ARG;
    }
    aac_bitwriter_write(&xw, 0, 1); /* loudEqInstructionsPresent */
    aac_bitwriter_write(&xw, 0, 1); /* eqPresent */
    if (aac_bitwriter_overflow(&xw)) {
      return AAC_ERR_OVERFLOW;
    }
    const int bits = aac_bitwriter_bits_written(&xw);
    const int size_bits = std::max(4, ceil_log2(bits)); /* bitSize holds bits - 1 */
    aac_bitwriter_write(w, kConfExtV1, 4);
    aac_bitwriter_write(w, (uint32_t)(size_bits - 4), 4); /* extBitSizeBits */
    aac_bitwriter_write(w, (uint32_t)(bits - 1), size_bits);
    aac_bitwriter_write_bits(w, ext, bits);
    aac_bitwriter_write(w, 0, 4); /* UNIDRCCONFEXT_TERM */
  }
  if (aac_bitwriter_overflow(w)) {
    return AAC_ERR_OVERFLOW;
  }
  return aac_bitwriter_bits_written(w) - start;
}

int aac_drc_gen_write_gain(AacBitWriter* w, const AacUniDrcConfig* c, const AacUniDrcGainFrame* g,
                           int frame_length, int sample_rate) {
  if (!w || !c || !g || frame_length <= 0 || sample_rate <= 0) {
    return AAC_ERR_INVALID_ARG;
  }
  const int n = sequence_count(c);
  if (n > AAC_UNIDRC_MAX_SEQUENCES || g->sequence_count < n) {
    return AAC_ERR_INVALID_ARG;
  }
  const int start = aac_bitwriter_bits_written(w);
  for (int seq = 0; seq < n; seq++) {
    const AacUniDrcGainSet* gs = owner_of(c, seq);
    if (!gs || gs->gain_coding_profile == AAC_UNIDRC_PROFILE_CONSTANT) {
      continue;
    }
    const int err = write_sequence(w, gs, &g->seq[seq], frame_length, sample_rate);
    if (err) {
      return err;
    }
  }
  aac_bitwriter_write(w, 0, 1); /* uniDrcGainExtPresent */
  if (aac_bitwriter_overflow(w)) {
    return AAC_ERR_OVERFLOW;
  }
  return aac_bitwriter_bits_written(w) - start;
}

int aac_drc_gen_write_loudness(AacBitWriter* w, const AacLoudnessInfo* info, const int* ids,
                               int n) {
  if (!w || n < 0 || n > 63 || (n > 0 && (!info || !ids))) {
    return AAC_ERR_INVALID_ARG;
  }
  const int start = aac_bitwriter_bits_written(w);
  aac_bitwriter_write(w, 0, 6); /* loudnessInfoAlbumCount */
  aac_bitwriter_write(w, (uint32_t)n, 6);
  for (int i = 0; i < n; i++) {
    if (ids[i] < 0 || ids[i] > 63) {
      return AAC_ERR_INVALID_ARG;
    }
    /* aac_loudness_info_write codes a set of one loudnessInfo() with
     * drcSetId 0: the two 6-bit counts, the entry, and the extension
     * flag.  The entry is taken from it with its drcSetId replaced. */
    uint8_t tmp[128]; /* one entry: at most 300 bits */
    memset(tmp, 0, sizeof(tmp));
    AacBitWriter tw;
    aac_bitwriter_init(&tw, tmp, (int)sizeof(tmp));
    const int bits = aac_loudness_info_write(&tw, &info[i]);
    if (bits < 0) {
      return bits;
    }
    AacBitReader r;
    aac_bitreader_init(&r, tmp, (bits + 7) / 8);
    aac_bitreader_skip(&r, 12 + 6); /* the counts and drcSetId */
    aac_bitwriter_write(w, (uint32_t)ids[i], 6);
    int left = bits - 12 - 6 - 1;
    while (left > 0) {
      const int k = std::min(left, 16);
      aac_bitwriter_write(w, aac_bitreader_read(&r, k), k);
      left -= k;
    }
  }
  aac_bitwriter_write(w, 0, 1); /* loudnessInfoSetExtPresent */
  if (aac_bitwriter_overflow(w)) {
    return AAC_ERR_OVERFLOW;
  }
  return aac_bitwriter_bits_written(w) - start;
}

AacDrcGen* aac_drc_gen_create(const AacDrcGenParams* p) {
  if (!p || p->sample_rate < 8000 || p->sample_rate > 192000 || p->channels < 1 ||
      p->channels > kMaxChannels || p->frame_length < 64 || p->frame_length > 4096 ||
      p->delay < p->frame_length || p->target_range_lu < AAC_DRC_GEN_MIN_RANGE_LU ||
      p->target_range_lu > AAC_DRC_GEN_MAX_RANGE_LU) {
    return nullptr;
  }
  AacUniDrcGainSet dflt;
  memset(&dflt, 0, sizeof(dflt));
  const int grid = aac_unidrc_time_delta_min(&dflt, p->sample_rate);
  if (p->frame_length % grid != 0 || p->frame_length / grid > AAC_UNIDRC_MAX_NODES) {
    return nullptr;
  }
  auto* g = (AacDrcGen*)calloc(1, sizeof(AacDrcGen));
  if (!g) {
    return nullptr;
  }
  g->p = *p;
  g->grid = grid;
  g->steps = p->frame_length / grid;
  g->window_blocks = std::max(1, (int)lround(kWindowSeconds * p->sample_rate / grid));
  g->hop_blocks = std::max(1, (int)lround(kHopSeconds * p->sample_rate / grid));
  /* The decoder applies the gains a frame carries to the output of the
   * next DRC frame (the default delay mode of the gain decoder of
   * ISO/IEC 23003-4), so a frame's nodes describe the input one frame
   * later than the codec delay alone would put them.  What the delay
   * leaves beyond that frame is the lookahead: the limiter's horizon and
   * the peak interpolator first, the compressor's lookahead within the
   * rest. */
  g->lag = p->delay - p->frame_length;
  const int fit = (g->lag - kTpAfter - 1) / grid - kHorizonSteps - 2;
  g->lookahead_blocks =
      std::clamp((int)lround(kLookaheadSeconds * p->sample_rate / grid), 0, std::max(0, fit));
  g->attack = (float)(1.0 - exp(-(double)grid / (kAttackSeconds * p->sample_rate)));
  g->release = (float)(1.0 - exp(-(double)grid / (kReleaseSeconds * p->sample_rate)));
  g->limiter_release = (float)exp(-(double)grid / (kLimiterReleaseSeconds * p->sample_rate));
  const float range = p->has_range ? p->range_lu : AAC_DRC_GEN_ASSUMED_RANGE_LU;
  /* Outside the dead band (half the target range) the ratio maps the
   * rest of the programme's range onto the rest of the target, less what
   * the smoothing leaves uncompressed. */
  const float half = (float)p->target_range_lu / 2.0f;
  g->ratio = std::clamp((range - half) / (half - kSmoothingLossLu), 1.0f, kMaxRatio);
  g->anchor = p->has_anchor ? p->anchor_lkfs : 0.0f;
  g->anchor_valid = p->has_anchor && p->anchor_lkfs > kSilenceLkfs;
  g->hist_len = p->delay + 2 * p->frame_length + 4 * grid + kTpTaps;
  g->gain_len = g->hist_len / grid + g->lookahead_blocks + 4;
  true_peak_taps(g->tp_taps);
  g->shelf = k_shelf(p->sample_rate);
  g->highpass = k_highpass(p->sample_rate);
  const int nq = g->steps + kHorizonSteps;
  g->hist = (float*)calloc((size_t)g->hist_len * p->channels, sizeof(float));
  g->peak = (float*)calloc((size_t)g->hist_len, sizeof(float));
  g->window = (double*)calloc((size_t)g->window_blocks, sizeof(double));
  g->gain = (float*)calloc((size_t)g->gain_len, sizeof(float));
  g->comp_db = (float*)calloc((size_t)nq, sizeof(float));
  g->night_db = (float*)calloc((size_t)nq, sizeof(float));
  g->codes = (int*)calloc((size_t)nq, sizeof(int));
  g->scratch = (float*)calloc((size_t)p->frame_length * p->channels, sizeof(float));
  g->gain_curve = (float*)calloc((size_t)p->frame_length, sizeof(float));
  bool ok = g->hist && g->peak && g->window && g->gain && g->comp_db && g->night_db && g->codes &&
            g->scratch && g->gain_curve;
  if (ok && p->measure) {
    for (int s = 0; s < AAC_DRC_GEN_SETS; s++) {
      g->meter[s] = aac_loudness_create(p->sample_rate, p->channels);
      ok = ok && g->meter[s];
    }
  }
  if (!ok) {
    aac_drc_gen_destroy(g);
    return nullptr;
  }
  aac_drc_gen_config(&g->config, p->channels);
  return g;
}

void aac_drc_gen_destroy(AacDrcGen* g) {
  if (!g) {
    return;
  }
  for (int s = 0; s < AAC_DRC_GEN_SETS; s++) {
    aac_loudness_destroy(g->meter[s]);
  }
  free(g->hist);
  free(g->peak);
  free(g->window);
  free(g->gain);
  free(g->comp_db);
  free(g->night_db);
  free(g->codes);
  free(g->scratch);
  free(g->gain_curve);
  free(g);
}

int aac_drc_gen_frame(AacDrcGen* g, const float* pcm, AacUniDrcGainFrame* out) {
  const int n = g->p.frame_length;
  const int steps = g->steps;
  const int nq = steps + kHorizonSteps;
  feed(g, pcm, n);
  /* The frame's grid points and the horizon after them, on the input's
   * timeline: grid point q of the frames' nodes sits at
   * (q + 1) * grid - 1 on the timeline of the gains, which trails the
   * output by one frame, input sample (q + 1) * grid - 1 - lag. */
  const long long q0 = g->frame_index * steps;
  /* The ceilings: the general set's at the programme's own level, where
   * only the compressor's boost can take a peak above it; the night
   * set's after the programme is normalised to the reference level. */
  float ceiling[2] = {AAC_DRC_GEN_PEAK_TARGET_DB, AAC_DRC_GEN_PEAK_TARGET_DB};
  if (g->anchor_valid) {
    ceiling[1] =
        std::min(ceiling[1], AAC_DRC_GEN_PEAK_TARGET_DB - (AAC_DRC_GEN_REFERENCE_LKFS - g->anchor));
  }
  float highest[AAC_UNIDRC_MAX_NODES + kHorizonSteps];
  for (int i = 0; i < nq; i++) {
    const long long t = (q0 + i + 1) * g->grid - 1 - g->lag;
    g->comp_db[i] = comp_gain_at(g, t);
    /* the peak over the samples either side of the point, which the
     * decoder's interpolation from its neighbours spans */
    highest[i] = -200.0f;
    for (long long u = t - g->grid; u <= t + g->grid; u++) {
      highest[i] = std::max(highest[i], peak_at(g, u));
    }
  }
  float reduction[2][AAC_UNIDRC_MAX_NODES + kHorizonSteps];
  for (int k = 0; k < 2; k++) {
    float* r = reduction[k];
    for (int i = 0; i < nq; i++) {
      r[i] = std::min(0.0f, ceiling[k] - highest[i] - g->comp_db[i]);
      if (k == 0) {
        /* the general set takes back boost only */
        r[i] = std::max(r[i], std::min(0.0f, -g->comp_db[i]));
      }
    }
    /* the release after a peak, then the lead-in before one */
    float last = g->limiter_last[k];
    for (int i = 0; i < nq; i++) {
      r[i] = std::min(r[i], last * g->limiter_release);
      last = r[i];
    }
    for (int i = nq - 2; i >= 0; i--) {
      r[i] = std::min(r[i], r[i + 1] + kLeadInDbPerStep);
    }
    g->limiter_last[k] = r[steps - 1];
  }
  for (int i = 0; i < steps; i++) {
    g->night_db[i] = g->comp_db[i] + std::min(reduction[0][i], reduction[1][i]);
    g->comp_db[i] += reduction[0][i];
  }
  /* The codes (rounded, downwards where a peak bound holds), the
   * delta range, and the node lists, thinned further while the payload
   * exceeds its budget. */
  out->sequence_count = 2;
  int comp_codes[AAC_UNIDRC_MAX_NODES], night_codes[AAC_UNIDRC_MAX_NODES];
  bool comp_bound[AAC_UNIDRC_MAX_NODES], night_bound[AAC_UNIDRC_MAX_NODES];
  for (int i = 0; i < steps; i++) {
    comp_bound[i] = reduction[0][i] < 0.0f;
    night_bound[i] = true;
    const int comp =
        comp_bound[i] ? (int)floorf(g->comp_db[i] * 8.0f + 1e-3f) : gain_code(g->comp_db[i]);
    comp_codes[i] = std::clamp(comp, -kMaxGainCode, kMaxGainCode);
    night_codes[i] =
        std::clamp((int)floorf(g->night_db[i] * 8.0f + 1e-3f), -kMaxGainCode, kMaxGainCode);
  }
  constrain(comp_codes, steps);
  constrain(night_codes, steps);
  float tol = kTolerance;
  for (int attempt = 0; attempt < 6; attempt++) {
    thin(comp_codes, comp_bound, steps, g->grid, g->last_code[0], tol, tol, &out->seq[0]);
    thin(night_codes, night_bound, steps, g->grid, g->last_code[1], tol, tol, &out->seq[1]);
    uint8_t buf[AAC_DRC_GEN_MAX_PAYLOAD_BYTES];
    AacBitWriter w;
    aac_bitwriter_init(&w, buf, (int)sizeof(buf));
    const int bits = aac_drc_gen_write_gain(&w, &g->config, out, n, g->p.sample_rate);
    if (bits >= 0 && bits <= 8 * kPayloadBudgetBytes) {
      break;
    }
    tol *= 2.0f;
  }
  /* The DRC sets' output for their meters: the input this frame's
   * output carries, times the gains as the decoder interpolates them. */
  if (g->meter[0]) {
    const long long t0 = g->frame_index * n - g->lag;
    for (int s = 0; s < AAC_DRC_GEN_SETS; s++) {
      decoded_curve(g->last_code[s], &out->seq[s], g->gain_curve, n);
      for (int t = 0; t < n; t++) {
        for (int c = 0; c < g->p.channels; c++) {
          g->scratch[(size_t)t * g->p.channels + c] = hist_sample(g, t0 + t, c) * g->gain_curve[t];
        }
      }
      if (t0 + n > 0) {
        const int skip = t0 < 0 ? (int)-t0 : 0;
        aac_loudness_feed(g->meter[s], g->scratch + (size_t)skip * g->p.channels, n - skip);
      }
    }
  }
  for (int s = 0; s < 2; s++) {
    g->last_code[s] = gain_code(out->seq[s].node[out->seq[s].node_count - 1].gain_db);
  }
  g->frame_index++;
  return AAC_OK;
}

int aac_drc_gen_loudness(const AacDrcGen* g, int drc_set_id, AacLoudnessInfo* out) {
  if (!g || !out || drc_set_id < 1 || drc_set_id > AAC_DRC_GEN_SETS) {
    return AAC_ERR_INVALID_ARG;
  }
  const AacLoudnessMeter* m = g->meter[drc_set_id - 1];
  if (!m) {
    return AAC_ERR_STATE;
  }
  return aac_loudness_result(m, out);
}

void aac_drc_gen_curve(const AacDrcGen* g, float* anchor_lkfs, float* ratio) {
  if (anchor_lkfs) {
    *anchor_lkfs = g->anchor_valid ? g->anchor : AAC_LOUDNESS_SILENCE;
  }
  if (ratio) {
    *ratio = g->ratio;
  }
}
