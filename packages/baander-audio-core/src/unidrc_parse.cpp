#include "unidrc.h"

#include <cstring>

/* MPEG-D DRC configuration parsing, written from ISO/IEC 23003-4:2020
 * clause 5 (uniDrcConfig(), loudnessInfoSet() and their extensions).
 *
 * The decoder keeps what it applies: the gain sets and their bands, the
 * DRC instructions with their channel groups and gain modifiers, the
 * downmix ids, and the loudness information.  The tools it does not
 * apply (parametric DRC, the shape filters, the custom characteristics,
 * the equaliser and the loudness equaliser) are parsed over so that the
 * bit count stays exact, or skipped by their signalled extension size. */

namespace {

constexpr int kConfExtParametricDrc = 1;
constexpr int kConfExtV1 = 2;
constexpr int kLoudExtEq = 1;

void channel_layout(AacBitReader* r, AacUniDrcConfig* c) {
  c->base_channel_count = (int)aac_bitreader_read(r, 7);
  if (aac_bitreader_read(r, 1)) { /* layoutSignalingPresent */
    const int defined_layout = (int)aac_bitreader_read(r, 8);
    if (defined_layout == 0) {
      for (int i = 0; i < c->base_channel_count; i++) {
        aac_bitreader_skip(r, 7); /* speakerPosition */
      }
    }
  }
}

/* downmixInstructions() of version 0 (4-bit coefficients) or 1 (an offset
 * and 5-bit coefficients). */
int downmix_instructions(AacBitReader* r, AacUniDrcConfig* c, int version) {
  if (c->downmix_count >= AAC_UNIDRC_MAX_DOWNMIXES) {
    return AAC_ERR_DECODE;
  }
  AacUniDrcDownmix* d = &c->downmix[c->downmix_count++];
  d->downmix_id = (int)aac_bitreader_read(r, 7);
  d->target_channel_count = (int)aac_bitreader_read(r, 7);
  d->target_layout = (int)aac_bitreader_read(r, 8);
  d->coefficients_present = (int)aac_bitreader_read(r, 1);
  if (d->coefficients_present) {
    const int n = d->target_channel_count * c->base_channel_count;
    if (version == 1) {
      aac_bitreader_skip(r, 4); /* bsDownmixOffset */
    }
    aac_bitreader_skip(r, n * (version == 1 ? 5 : 4));
  }
  return AAC_OK;
}

/* drcCoefficientsBasic() and drcInstructionsBasic(): the basic DRC of
 * ISO/IEC 23003-4 clause 5.2 is parsed over and not applied. */
void coefficients_basic(AacBitReader* r) {
  aac_bitreader_skip(r, 4 + 7); /* drcLocation, drcCharacteristic */
}

void instructions_basic(AacBitReader* r) {
  aac_bitreader_skip(r, 6 + 4 + 7); /* drcSetId, drcLocation, downmixId */
  if (aac_bitreader_read(r, 1)) {    /* additionalDownmixIdPresent */
    const int n = (int)aac_bitreader_read(r, 3);
    aac_bitreader_skip(r, 7 * n);
  }
  aac_bitreader_skip(r, 16); /* drcSetEffect */
  if (aac_bitreader_read(r, 1)) {
    aac_bitreader_skip(r, 8); /* bsLimiterPeakTarget */
  }
  if (aac_bitreader_read(r, 1)) {
    aac_bitreader_skip(r, 6); /* bsDrcSetTargetLoudnessValueUpper */
    if (aac_bitreader_read(r, 1)) {
      aac_bitreader_skip(r, 6);
    }
  }
}

/* splitDrcCharacteristic(): the node list of a custom characteristic
 * half (the characteristic itself is not applied). */
void split_characteristic(AacBitReader* r) {
  const int nodes = (int)aac_bitreader_read(r, 2) + 1;
  for (int i = 0; i < nodes; i++) {
    aac_bitreader_skip(r, 5 + 6); /* nodeLevelDelta, nodeGain */
  }
}

int characteristic_table(AacBitReader* r) {
  const int n = (int)aac_bitreader_read(r, 4);
  for (int i = 0; i < n; i++) {
    if (aac_bitreader_read(r, 1) == 0) { /* characteristicFormat: 0 CICP */
      aac_bitreader_skip(r, 7);
    } else {
      split_characteristic(r);
    }
  }
  return n;
}

/* shapeFilterBlockParams(): four optional first and second order shelves. */
void shape_filter(AacBitReader* r) {
  for (int i = 0; i < 4; i++) {
    if (aac_bitreader_read(r, 1)) {
      aac_bitreader_skip(r, 3 + 2); /* cornerFreqIndex, filterStrengthIndex */
    }
  }
}

/* gainSetParams(); `next_sequence` numbers the gain sequences in order
 * when no index is signalled. */
int gain_set_params(AacBitReader* r, AacUniDrcGainSet* gs, int version, int* next_sequence) {
  memset(gs, 0, sizeof(*gs));
  gs->gain_coding_profile = (int)aac_bitreader_read(r, 2);
  gs->gain_interpolation_type = (int)aac_bitreader_read(r, 1);
  gs->full_frame = (int)aac_bitreader_read(r, 1);
  gs->time_alignment = (int)aac_bitreader_read(r, 1);
  if (aac_bitreader_read(r, 1)) { /* timeDeltaMinPresent */
    gs->time_delta_min = (int)aac_bitreader_read(r, 11) + 1;
  }
  if (gs->gain_coding_profile == AAC_UNIDRC_PROFILE_CONSTANT) {
    /* A constant gain set still owns one gain sequence (its gain is in
     * the gain modifiers, nothing in the payload). */
    gs->band_count = 1;
    gs->band[0].gain_sequence_index = (*next_sequence)++;
    gs->band[0].characteristic_left = gs->band[0].characteristic_right = -1;
    return AAC_OK;
  }
  gs->band_count = (int)aac_bitreader_read(r, 4);
  if (gs->band_count < 1 || gs->band_count > AAC_UNIDRC_MAX_BANDS) {
    return AAC_ERR_DECODE;
  }
  if (gs->band_count > 1) {
    gs->drc_band_type = (int)aac_bitreader_read(r, 1);
  }
  for (int b = 0; b < gs->band_count; b++) {
    AacUniDrcBand* band = &gs->band[b];
    band->characteristic_left = band->characteristic_right = -1;
    if (version == 0) {
      band->gain_sequence_index = (*next_sequence)++;
      band->characteristic = (int)aac_bitreader_read(r, 7);
    } else {
      if (aac_bitreader_read(r, 1)) { /* indexPresent */
        band->gain_sequence_index = (int)aac_bitreader_read(r, 6);
        *next_sequence = band->gain_sequence_index + 1;
      } else {
        band->gain_sequence_index = (*next_sequence)++;
      }
      if (aac_bitreader_read(r, 1)) {   /* drcCharacteristicPresent */
        if (aac_bitreader_read(r, 1)) { /* drcCharacteristicFormatIsCICP */
          band->characteristic = (int)aac_bitreader_read(r, 7);
        } else {
          band->characteristic_left = (int)aac_bitreader_read(r, 4);
          band->characteristic_right = (int)aac_bitreader_read(r, 4);
        }
      }
    }
    if (band->gain_sequence_index >= AAC_UNIDRC_MAX_SEQUENCES) {
      return AAC_ERR_DECODE;
    }
  }
  for (int b = 1; b < gs->band_count; b++) {
    if (gs->drc_band_type) {
      gs->band[b].crossover_freq_index = (int)aac_bitreader_read(r, 4);
    } else {
      gs->band[b].start_sub_band_index = (int)aac_bitreader_read(r, 10);
    }
  }
  return AAC_OK;
}

/* drcCoefficientsUniDrc() (version 0) and drcCoefficientsUniDrcV1(). */
int coefficients_unidrc(AacBitReader* r, AacUniDrcConfig* c, int version) {
  if (c->coefficients_count >= 2) {
    return AAC_ERR_DECODE;
  }
  AacUniDrcCoefficients* co = &c->coefficients[c->coefficients_count++];
  memset(co, 0, sizeof(*co));
  co->version = version;
  co->drc_location = (int)aac_bitreader_read(r, 4);
  if (aac_bitreader_read(r, 1)) { /* drcFrameSizePresent */
    co->drc_frame_size = (int)aac_bitreader_read(r, 15) + 1;
  }
  if (version == 1) {
    if (aac_bitreader_read(r, 1)) { /* drcCharacteristicLeftPresent */
      co->characteristic_left_count = characteristic_table(r);
    }
    if (aac_bitreader_read(r, 1)) { /* drcCharacteristicRightPresent */
      co->characteristic_right_count = characteristic_table(r);
    }
    if (aac_bitreader_read(r, 1)) { /* shapeFilterPresent */
      co->shape_filter_count = (int)aac_bitreader_read(r, 4);
      for (int i = 0; i < co->shape_filter_count; i++) {
        shape_filter(r);
      }
    }
    co->gain_sequence_count = (int)aac_bitreader_read(r, 6);
  }
  co->gain_set_count = (int)aac_bitreader_read(r, 6);
  if (co->gain_set_count > AAC_UNIDRC_MAX_GAIN_SETS) {
    return AAC_ERR_DECODE;
  }
  int next_sequence = 0;
  for (int i = 0; i < co->gain_set_count; i++) {
    const int err = gain_set_params(r, &co->gain_set[i], version, &next_sequence);
    if (err) {
      return err;
    }
  }
  if (version == 0) {
    co->gain_sequence_count = next_sequence;
  }
  return AAC_OK;
}

/* The channel count an instructions element describes: the base layout,
 * or the target layout of the downmix it belongs to. */
int instructions_channel_count(const AacUniDrcConfig* c, const AacUniDrcInstructions* in) {
  if (in->downmix_id == 0 || in->downmix_id == 0x7F) {
    return c->base_channel_count;
  }
  for (int i = 0; i < c->downmix_count; i++) {
    if (c->downmix[i].downmix_id == in->downmix_id) {
      return c->downmix[i].target_channel_count;
    }
  }
  return c->base_channel_count;
}

const AacUniDrcGainSet* find_gain_set(const AacUniDrcConfig* c, int version, int index) {
  for (int i = 0; i < c->coefficients_count; i++) {
    if (c->coefficients[i].version == version && index < c->coefficients[i].gain_set_count) {
      return &c->coefficients[i].gain_set[index];
    }
  }
  return nullptr;
}

/* gainModifiers() of one channel group. */
void gain_modifiers(AacBitReader* r, AacUniDrcGroup* g, int version, int band_count) {
  g->attenuation_scaling = g->amplification_scaling = 1.0f;
  g->gain_offset_db = 0.0f;
  g->shape_filter_index = -1;
  const int bands = version == 1 ? band_count : 1;
  for (int b = 0; b < bands; b++) {
    if (version == 1) {
      if (aac_bitreader_read(r, 1)) { /* targetCharacteristicLeftPresent */
        aac_bitreader_skip(r, 4);
      }
      if (aac_bitreader_read(r, 1)) { /* targetCharacteristicRightPresent */
        aac_bitreader_skip(r, 4);
      }
    }
    if (aac_bitreader_read(r, 1)) { /* gainScalingPresent */
      const float att = (float)aac_bitreader_read(r, 4) * 0.125f;
      const float amp = (float)aac_bitreader_read(r, 4) * 0.125f;
      if (b == 0) {
        g->attenuation_scaling = att;
        g->amplification_scaling = amp;
      }
    }
    if (aac_bitreader_read(r, 1)) { /* gainOffsetPresent */
      const int bs = (int)aac_bitreader_read(r, 6);
      const float off = (float)((bs & 31) + 1) * 0.25f;
      if (b == 0) {
        g->gain_offset_db = (bs & 32) ? -off : off;
      }
    }
  }
  if (version == 1 && aac_bitreader_read(r, 1)) { /* shapeFilterPresent */
    g->shape_filter_index = (int)aac_bitreader_read(r, 4);
  }
}

/* drcInstructionsUniDrc() (version 0) and drcInstructionsUniDrcV1(). */
int instructions_unidrc(AacBitReader* r, AacUniDrcConfig* c, int version) {
  if (c->instructions_count >= AAC_UNIDRC_MAX_INSTRUCTIONS) {
    return AAC_ERR_DECODE;
  }
  AacUniDrcInstructions* in = &c->instructions[c->instructions_count++];
  memset(in, 0, sizeof(*in));
  in->version = version;
  in->drc_set_id = (int)aac_bitreader_read(r, 6);
  if (version == 1) {
    in->complexity_level = (int)aac_bitreader_read(r, 4);
  }
  in->drc_location = (int)aac_bitreader_read(r, 4);
  if (aac_bitreader_read(r, 1)) { /* downmixIdPresent */
    in->downmix_id = (int)aac_bitreader_read(r, 7);
    if (version == 1) {
      in->apply_to_downmix = (int)aac_bitreader_read(r, 1);
    }
    if (aac_bitreader_read(r, 1)) { /* additionalDownmixIdPresent */
      in->additional_downmix_count = (int)aac_bitreader_read(r, 3);
      for (int i = 0; i < in->additional_downmix_count; i++) {
        in->additional_downmix_id[i] = (int)aac_bitreader_read(r, 7);
      }
    }
  }
  in->drc_set_effect = aac_bitreader_read(r, 16);
  const int ducking =
      (in->drc_set_effect & (AAC_UNIDRC_EFFECT_DUCK_OTHER | AAC_UNIDRC_EFFECT_DUCK_SELF)) != 0;
  if (!ducking) {
    in->limiter_peak_target_present = (int)aac_bitreader_read(r, 1);
    if (in->limiter_peak_target_present) {
      in->limiter_peak_target_db = -(float)aac_bitreader_read(r, 8) * 0.125f;
    }
  }
  in->target_loudness_present = (int)aac_bitreader_read(r, 1);
  in->target_loudness_upper = 0;
  in->target_loudness_lower = -63;
  if (in->target_loudness_present) {
    in->target_loudness_upper = (int)aac_bitreader_read(r, 6) - 63;
    if (aac_bitreader_read(r, 1)) {
      in->target_loudness_lower = (int)aac_bitreader_read(r, 6) - 63;
    }
  }
  in->depends_on_drc_set = -1;
  if (aac_bitreader_read(r, 1)) { /* dependsOnDrcSetPresent */
    in->depends_on_drc_set = (int)aac_bitreader_read(r, 6);
  } else {
    in->no_independent_use = (int)aac_bitreader_read(r, 1);
  }
  if (version == 1) {
    in->requires_eq = (int)aac_bitreader_read(r, 1);
  }
  in->channel_count = instructions_channel_count(c, in);
  if (in->channel_count > AAC_UNIDRC_MAX_CHANNELS) {
    return AAC_ERR_DECODE;
  }
  for (int ch = 0; ch < in->channel_count;) {
    const int bs = (int)aac_bitreader_read(r, 6);
    float duck = 1.0f;
    if (ducking && bs > 0 && aac_bitreader_read(r, 1)) { /* duckingScalingPresent */
      const int code = (int)aac_bitreader_read(r, 4);
      const float mag = (float)((code & 7) + 1) * 0.125f;
      duck = (code & 8) ? 1.0f - mag : 1.0f + mag;
    }
    int repeat = 1;
    if (aac_bitreader_read(r, 1)) { /* repeatGainSetIndex */
      repeat += (int)aac_bitreader_read(r, 5) + 1;
    }
    for (int k = 0; k < repeat && ch < in->channel_count; k++, ch++) {
      in->gain_set_index[ch] = bs - 1;
      in->ducking_scaling[ch] = duck;
    }
  }
  /* The channel groups: the distinct gain sets, in order of first use. */
  in->group_count = 0;
  for (int ch = 0; ch < in->channel_count; ch++) {
    const int gsi = in->gain_set_index[ch];
    if (gsi < 0) {
      continue;
    }
    int found = 0;
    for (int g = 0; g < in->group_count; g++) {
      found |= in->group[g].gain_set_index == gsi;
    }
    if (!found) {
      in->group[in->group_count++].gain_set_index = gsi;
    }
  }
  for (int g = 0; g < in->group_count; g++) {
    AacUniDrcGroup* grp = &in->group[g];
    const AacUniDrcGainSet* gs = find_gain_set(c, version, grp->gain_set_index);
    if (!gs) {
      return AAC_ERR_DECODE;
    }
    if (ducking) {
      grp->attenuation_scaling = grp->amplification_scaling = 1.0f;
      grp->gain_offset_db = 0.0f;
      grp->shape_filter_index = -1;
    } else {
      gain_modifiers(r, grp, version, gs->band_count);
    }
  }
  return AAC_OK;
}

/* uniDrcConfigExtension() */
int config_extension(AacBitReader* r, AacUniDrcConfig* c) {
  int type = (int)aac_bitreader_read(r, 4);
  while (type != 0) {
    const int size_bits = (int)aac_bitreader_read(r, 4) + 4;
    const int ext_bits = (int)aac_bitreader_read(r, size_bits) + 1;
    const int start = r->pos;
    if (ext_bits > aac_bitreader_bits_left(r)) {
      return AAC_ERR_DECODE;
    }
    if (type == kConfExtParametricDrc) {
      c->has_parametric_drc = 1;
    } else if (type == kConfExtV1) {
      if (aac_bitreader_read(r, 1)) { /* downmixInstructionsV1Present */
        const int n = (int)aac_bitreader_read(r, 7);
        for (int i = 0; i < n; i++) {
          if (downmix_instructions(r, c, 1)) {
            return AAC_ERR_DECODE;
          }
        }
      }
      if (aac_bitreader_read(r, 1)) { /* drcCoeffsAndInstructionsUniDrcV1Present */
        const int nc = (int)aac_bitreader_read(r, 3);
        for (int i = 0; i < nc; i++) {
          if (coefficients_unidrc(r, c, 1)) {
            return AAC_ERR_DECODE;
          }
        }
        const int ni = (int)aac_bitreader_read(r, 6);
        for (int i = 0; i < ni; i++) {
          if (instructions_unidrc(r, c, 1)) {
            return AAC_ERR_DECODE;
          }
        }
      }
      c->has_loud_eq = (int)aac_bitreader_read(r, 1);
      if (!c->has_loud_eq) {
        c->has_eq = (int)aac_bitreader_read(r, 1);
      }
      if (r->pos - start > ext_bits) {
        return AAC_ERR_DECODE;
      }
    }
    /* The equaliser tools and unknown extensions end at the signalled size. */
    r->pos = start + ext_bits;
    type = (int)aac_bitreader_read(r, 4);
  }
  return AAC_OK;
}

/* loudnessInfo() of version 0 or 1 */
int loudness_info(AacBitReader* r, AacUniDrcLoudnessInfo* li, int version) {
  memset(li, 0, sizeof(*li));
  li->drc_set_id = (int)aac_bitreader_read(r, 6);
  if (version == 1) {
    li->eq_set_id = (int)aac_bitreader_read(r, 6);
  }
  li->downmix_id = (int)aac_bitreader_read(r, 7);
  li->sample_peak_present = (int)aac_bitreader_read(r, 1);
  if (li->sample_peak_present) {
    li->sample_peak_db = 20.0f - (float)aac_bitreader_read(r, 12) * 0.03125f;
  }
  li->true_peak_present = (int)aac_bitreader_read(r, 1);
  if (li->true_peak_present) {
    li->true_peak_db = 20.0f - (float)aac_bitreader_read(r, 12) * 0.03125f;
    li->true_peak_system = (int)aac_bitreader_read(r, 4);
    li->true_peak_reliability = (int)aac_bitreader_read(r, 2);
  }
  li->measurement_count = (int)aac_bitreader_read(r, 4);
  if (li->measurement_count > AAC_UNIDRC_MAX_MEASUREMENTS) {
    return AAC_ERR_DECODE;
  }
  for (int i = 0; i < li->measurement_count; i++) {
    AacUniDrcMeasurement* m = &li->m[i];
    m->method = (int)aac_bitreader_read(r, 4);
    switch (m->method) {
      case AAC_UNIDRC_METHOD_LOUDNESS_RANGE: {
        const int bs = (int)aac_bitreader_read(r, 8);
        if (bs < 128) {
          m->value = (float)bs * 0.25f;
        } else if (bs < 204) {
          m->value = 0.5f * (float)(bs - 128) + 32.0f;
        } else {
          m->value = (float)(bs - 204) + 70.0f;
        }
        break;
      }
      case AAC_UNIDRC_METHOD_MIXING_LEVEL:
        m->value = (float)aac_bitreader_read(r, 5) + 80.0f;
        break;
      case AAC_UNIDRC_METHOD_ROOM_TYPE:
        m->value = (float)aac_bitreader_read(r, 2);
        break;
      default: /* a loudness level in LUFS */
        m->value = -57.75f + (float)aac_bitreader_read(r, 8) * 0.25f;
        break;
    }
    m->system = (int)aac_bitreader_read(r, 4);
    m->reliability = (int)aac_bitreader_read(r, 2);
  }
  return AAC_OK;
}

int loudness_list(AacBitReader* r, AacUniDrcLoudnessInfo* list, int* count, int n, int version) {
  for (int i = 0; i < n; i++) {
    if (*count >= AAC_UNIDRC_MAX_LOUDNESS_INFO) {
      return AAC_ERR_DECODE;
    }
    if (loudness_info(r, &list[(*count)++], version)) {
      return AAC_ERR_DECODE;
    }
  }
  return AAC_OK;
}

/* loudnessInfoSetExtension(): the version 1 lists are appended to the
 * version 0 ones. */
int loudness_extension(AacBitReader* r, AacUniDrcLoudnessSet* l) {
  int type = (int)aac_bitreader_read(r, 4);
  while (type != 0) {
    const int size_bits = (int)aac_bitreader_read(r, 4) + 4;
    const int ext_bits = (int)aac_bitreader_read(r, size_bits) + 1;
    const int start = r->pos;
    if (ext_bits > aac_bitreader_bits_left(r)) {
      return AAC_ERR_DECODE;
    }
    if (type == kLoudExtEq) {
      const int na = (int)aac_bitreader_read(r, 6);
      const int n = (int)aac_bitreader_read(r, 6);
      if (loudness_list(r, l->album, &l->album_count, na, 1) ||
          loudness_list(r, l->info, &l->count, n, 1)) {
        return AAC_ERR_DECODE;
      }
      if (r->pos - start > ext_bits) {
        return AAC_ERR_DECODE;
      }
    }
    r->pos = start + ext_bits;
    type = (int)aac_bitreader_read(r, 4);
  }
  return AAC_OK;
}

} // namespace

int aac_unidrc_parse_config(AacBitReader* r, AacUniDrcConfig* c) {
  memset(c, 0, sizeof(*c));
  if (aac_bitreader_read(r, 1)) { /* sampleRatePresent */
    c->sample_rate = (int)aac_bitreader_read(r, 18) + 1000;
  }
  const int downmix_count = (int)aac_bitreader_read(r, 7);
  int basic_coefficients = 0, basic_instructions = 0;
  if (aac_bitreader_read(r, 1)) { /* drcDescriptionBasicPresent */
    basic_coefficients = (int)aac_bitreader_read(r, 3);
    basic_instructions = (int)aac_bitreader_read(r, 4);
  }
  const int coefficients_count = (int)aac_bitreader_read(r, 3);
  const int instructions_count = (int)aac_bitreader_read(r, 6);
  channel_layout(r, c);
  if (c->base_channel_count > AAC_UNIDRC_MAX_CHANNELS) {
    return AAC_ERR_DECODE;
  }
  for (int i = 0; i < downmix_count; i++) {
    if (downmix_instructions(r, c, 0)) {
      return AAC_ERR_DECODE;
    }
  }
  for (int i = 0; i < basic_coefficients; i++) {
    coefficients_basic(r);
  }
  for (int i = 0; i < basic_instructions; i++) {
    instructions_basic(r);
  }
  for (int i = 0; i < coefficients_count; i++) {
    if (coefficients_unidrc(r, c, 0)) {
      return AAC_ERR_DECODE;
    }
  }
  for (int i = 0; i < instructions_count; i++) {
    if (instructions_unidrc(r, c, 0)) {
      return AAC_ERR_DECODE;
    }
  }
  if (aac_bitreader_read(r, 1)) { /* uniDrcConfigExtPresent */
    if (config_extension(r, c)) {
      return AAC_ERR_DECODE;
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

int aac_unidrc_parse_loudness(AacBitReader* r, AacUniDrcLoudnessSet* l) {
  memset(l, 0, sizeof(*l));
  const int na = (int)aac_bitreader_read(r, 6);
  const int n = (int)aac_bitreader_read(r, 6);
  if (loudness_list(r, l->album, &l->album_count, na, 0) ||
      loudness_list(r, l->info, &l->count, n, 0)) {
    return AAC_ERR_DECODE;
  }
  if (aac_bitreader_read(r, 1)) { /* loudnessInfoSetExtPresent */
    if (loudness_extension(r, l)) {
      return AAC_ERR_DECODE;
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

int aac_unidrc_sequence_index(const AacUniDrcConfig* c, int set, int band) {
  for (int i = 0; i < c->coefficients_count; i++) {
    const AacUniDrcCoefficients* co = &c->coefficients[i];
    if (set < co->gain_set_count) {
      if (band < 0 || band >= co->gain_set[set].band_count) {
        return -1;
      }
      return co->gain_set[set].band[band].gain_sequence_index;
    }
    set -= co->gain_set_count;
  }
  return -1;
}
