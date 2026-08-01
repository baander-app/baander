#include "decoder.h"

#include <cmath>
#include <cstdio>
#include <cstring>

AacDecoderState* aac_decoder_state_create(int sr, int ch, const AacDSP* dsp) {
  auto* s = new AacDecoderState();
  s->sample_rate = sr;
  s->channels = ch;
  s->frame_size = 1024;
  s->dsp = dsp;
  s->rate_index = 3; /* default 48000 */
  for (int i = 0; i < AAC_NUM_SAMPLE_RATES; i++) {
    if (aac_sample_rates[i] == sr) {
      s->rate_index = i;
      break;
    }
  }
  for (int c = 0; c < 2; c++) {
    aac_mdct_init(&s->ch[c].mdct_ctx, 1024, dsp);
    s->ch[c].win_seq = AAC_WIN_ONLY_LONG;
    s->ch[c].win_shape = AAC_WIN_SINE;
    s->ch[c].max_sfb = 49;
  }
  s->ms_mask_present = 0;
  memset(s->ms_mask, 0, sizeof(s->ms_mask));
  return s;
}

void aac_decoder_state_destroy(AacDecoderState* s) {
  if (!s) {
    return;
  }
  for (int c = 0; c < 2; c++) {
    aac_mdct_free(&s->ch[c].mdct_ctx);
  }
  delete s;
}

static int parse_ics(AacDecoderState* s, AacBitReader* r, int ch) {
  int global_gain = aac_bitreader_read(r, 8) - 100; /* subtract offset */
  /* ISO 14496-3 ics_info: ics_reserved_bit (1) must be 0 for non-ELD AOTs. */
  aac_bitreader_read(r, 1);
  int ws = aac_bitreader_read(r, 2);
  s->ch[ch].win_seq = (AacWindowSequence)ws;
  s->ch[ch].win_shape = (AacWindowShape)aac_bitreader_read(r, 1);
  int is_short = (ws == AAC_WIN_EIGHT_SHORT);
  s->ch[ch].max_sfb = aac_bitreader_read(r, is_short ? 4 : 6);
  if (s->ch[ch].max_sfb > AAC_MAX_SFB_LONG) {
    s->ch[ch].max_sfb = AAC_MAX_SFB_LONG;
  }
  if (is_short) {
    aac_bitreader_read(r, 7); /* scale_factor_grouping */
  } else {
    if (aac_bitreader_read(r, 1)) {
      /* predictor data present */
      int pred = aac_bitreader_read(r, 1);
      if (pred) {
        aac_bitreader_read(r, 5); /* prediction reset */
                                  /* skip predictor data */
      }
    }
  }
  return global_gain;
}

static int decode_spectral(AacDecoderState* s, AacBitReader* r, int ch, int gg) {
  AacDecoderChannel* dc = &s->ch[ch];
  int ri = s->rate_index;
  float* spec = dc->spectral;
  memset(spec, 0, 1024 * sizeof(float));

  int is_short = (dc->win_seq == AAC_WIN_EIGHT_SHORT);
  int nsfb = 0;
  const int* sfb = nullptr;

  if (is_short) {
    nsfb = aac_num_sfb_short[ri];
    sfb = aac_sfb_offset_short[ri];
  } else {
    nsfb = aac_num_sfb_long[ri];
    sfb = aac_sfb_offset_long[ri];
  }

  int max_sfb = dc->max_sfb;
  if (max_sfb > nsfb) {
    max_sfb = nsfb;
  }

  /* section_data (ISO 14496-3 Table 4.46) */
  int ret = aac_bitreader_read_sections(r, is_short, max_sfb, dc->sfb_cb, nsfb);
  if (ret) {
    return ret;
  }

  /* scale_factor_data (ISO 14496-3 Table 4.47).  DPCM is relative to the
   * previous non-zero-band scalefactor; the first is global_gain. */
  int prev_sf = gg;
  for (int sfb_idx = 0; sfb_idx < max_sfb; sfb_idx++) {
    int cb = dc->sfb_cb[sfb_idx];
    if (cb == 0 || cb >= 13) {
      dc->scalefactors[sfb_idx] = 0;
      continue;
    }
    int dpcm = aac_bitreader_read_scalefactor(r);
    if (dpcm < -60 || dpcm > 60) {
      return AAC_ERR_DECODE;
    }
    prev_sf += dpcm;
    if (prev_sf < -100) {
      prev_sf = -100;
    } else if (prev_sf > 155) {
      prev_sf = 155;
    }
    dc->scalefactors[sfb_idx] = prev_sf;
  }
  for (int sfb_idx = max_sfb; sfb_idx < nsfb; sfb_idx++) {
    dc->sfb_cb[sfb_idx] = 0;
    dc->scalefactors[sfb_idx] = 0;
  }

  /* pulse_data (ISO Table 4.7) */
  if (aac_bitreader_read(r, 1)) {
    /* pulse data present - not supported, skip it */
    int num_pulse = aac_bitreader_read(r, 2) + 1;
    aac_bitreader_read(r, 6); /* pulse_swb */
    for (int i = 0; i < num_pulse; i++) {
      aac_bitreader_read(r, 5); /* pulse_pos */
      aac_bitreader_read(r, 4); /* pulse_amp */
    }
  }

  /* tns_data (ISO Table 4.48) */
  dc->tns_present = (int)aac_bitreader_read(r, 1);
  if (dc->tns_present) {
    /* TNS not implemented in this decoder; skip enough bits for the
     * long-window syntax so the spectral data stays aligned. */
    const int is8 = is_short ? 1 : 0;
    const int max_order = is8 ? 7 : 12;
    for (int w = 0; w < (is8 ? 8 : 1); w++) {
      int n_filt = (int)aac_bitreader_read(r, 2 - is8);
      if (n_filt) {
        aac_bitreader_read(r, 1); /* coef_res */
        for (int filt = 0; filt < n_filt; filt++) {
          aac_bitreader_read(r, 6 - 2 * is8); /* length */
          int order = (int)aac_bitreader_read(r, 5 - 2 * is8);
          if (order > max_order) {
            return AAC_ERR_DECODE;
          }
          if (order) {
            aac_bitreader_read(r, 1); /* coef_compress */
            int coef_bits = (int)aac_bitreader_read(r, 1) + 3;
            aac_bitreader_skip(r, order * coef_bits);
          }
        }
      }
    }
  }

  /* gain_control_data_present (SSR) */
  if (aac_bitreader_read(r, 1)) {
    /* SSR not supported */
    return AAC_ERR_DECODE;
  }

  /* spectral_data */
  for (int sfb_idx = 0; sfb_idx < max_sfb; sfb_idx++) {
    int cb = dc->sfb_cb[sfb_idx];
    if (cb == 0 || cb >= 13) {
      continue;
    }
    const AacCodebookInfo* info = &aac_codebook_info[cb];
    int dim = info->dim;
    int start = sfb[sfb_idx], end = sfb[sfb_idx + 1];
    for (int bin = start; bin < end && bin < 1024; bin += dim) {
      if (bin < 0) {
        continue;
      }
      int v[4] = {0, 0, 0, 0};
      if (aac_bitreader_read_huffman(r, cb, v) != 0) {
        return AAC_ERR_DECODE;
      }
      for (int k = 0; k < dim && bin + k < 1024; k++) {
        spec[bin + k] = (float)v[k];
      }
    }
  }
  return 0;
}

void aac_dequantize(AacDecoderChannel* ch, int ri, int /*fs*/) {
  float* spec = ch->spectral;
  int nsfb = aac_num_sfb_long[ri];
  for (int sfb = 0; sfb < nsfb; sfb++) {
    int cb = ch->sfb_cb[sfb];
    if (cb == 0 || cb >= 13) {
      continue;
    }
    float sf_scale_inv = powf(2.0f, -0.25f * ch->scalefactors[sfb]);
    int s = aac_sfb_offset_long[ri][sfb];
    int e = aac_sfb_offset_long[ri][sfb + 1];
    for (int i = s; i < e; i++) {
      /* Dequantize: dq = sign(iq) * |iq * 2^(-sf/4)|^(4/3)
       * Matches encoder: q = spec^(3/4) * 2^(sf/4), so inverse is dq = (q * 2^(-sf/4))^(4/3) */
      float iq = spec[i];
      spec[i] = copysignf(powf(fabsf(iq * sf_scale_inv), 4.0f / 3.0f), iq);
    }
  }
}

void aac_apply_tns(AacDecoderChannel* ch, int ri, int /*fs*/) {
  if (!ch->tns_present) {
    return;
  }
  int end = aac_sfb_offset_long[ri][aac_tns_max_bands_long[ri]];
  for (int i = 0; i < end; i++) {
    for (int k = 0; k < ch->tns_ncoef[0] && k < 20; k++) {
      if (i - k - 1 >= 0) {
        ch->spectral[i] += ch->tns_lpc[0][k] * ch->spectral[i - k - 1];
      }
    }
  }
}

/* Apply M/S stereo decoding in the spectral domain.  Per ISO 14496-3 the
 * transform L/R <-> M/S is orthogonal, so it can be applied after inverse
 * quantization.  ms_mask[b] == 1 selects the bands where M/S is active. */
static void aac_apply_ms(AacDecoderState* s) {
  const int ri = s->rate_index;
  int nsfb = 0;
  const int* sfb = nullptr;

  if (s->ch[0].win_seq == AAC_WIN_EIGHT_SHORT) {
    nsfb = aac_num_sfb_short[ri];
    sfb = aac_sfb_offset_short[ri];
  } else {
    nsfb = aac_num_sfb_long[ri];
    sfb = aac_sfb_offset_long[ri];
  }

  const float inv_sqrt2 = 0.707106781f;
  for (int b = 0; b < nsfb && b < 49; b++) {
    if (!s->ms_mask[b]) {
      continue;
    }
    int start = sfb[b];
    int end = sfb[b + 1];
    for (int i = start; i < end && i < 1024; i++) {
      float m = s->ch[0].spectral[i];
      float side = s->ch[1].spectral[i];
      s->ch[0].spectral[i] = (m + side) * inv_sqrt2;
      s->ch[1].spectral[i] = (m - side) * inv_sqrt2;
    }
  }
}

int aac_decode_sce(AacDecoderState* s, AacBitReader* r, int ch) {
  int gg = parse_ics(s, r, ch);
  int ret = decode_spectral(s, r, ch, gg);
  if (ret) {
    return ret; /* don't dequant/IMDCT garbage or advance past a corrupt stream */
  }
  aac_dequantize(&s->ch[ch], s->rate_index, 1024);
  aac_apply_tns(&s->ch[ch], s->rate_index, 1024);
  aac_imdct(&s->ch[ch].mdct_ctx, s->ch[ch].output, s->ch[ch].spectral, 1024, s->ch[ch].win_seq,
            s->ch[ch].win_shape, ch);
  return 0;
}

int aac_decode_cpe(AacDecoderState* s, AacBitReader* r) {
  /* channel_pair_element(): the caller (api.cpp) consumes the 3-bit element id
   * but, unlike the SCE path, does NOT consume element_instance_tag — read it
   * here. ISO 14496-3 syntax is then:
   *   common_window(1)
   *   if (common_window) { ics_info(); ms_mask_present(2); [ms_used] }
   *   individual_channel_stream(ch=0)
   *   individual_channel_stream(ch=1)
   *
   * For common_window == 0 the encoder uses two independent SCE-like streams,
   * each carrying its own ics_info, so we delegate to aac_decode_sce.
   *
   * For common_window == 1 the ics_info is shared and the individual channel
   * streams only contain global_gain plus spectral data.  We also parse and
   * apply M/S stereo decoding when ms_mask_present signals it; previously the
   * mask bits were read but never acted upon, so external CPE streams using
   * M/S decoded as plain L/R. */
  aac_bitreader_read(r, 4); /* element_instance_tag */

  s->ms_mask_present = 0;
  memset(s->ms_mask, 0, sizeof(s->ms_mask));

  int common_window = aac_bitreader_read(r, 1);
  int shared_is_short = 0;

  if (common_window) {
    /* Shared ics_info.  We keep the parsed values so both channels decode with
     * the same window sequence/shape, which is required for M/S. */
    aac_bitreader_read(r, 1); /* ics_reserved_bit */
    int ws = aac_bitreader_read(r, 2);
    int wshape = aac_bitreader_read(r, 1);
    shared_is_short = (ws == AAC_WIN_EIGHT_SHORT);
    int max_sfb = aac_bitreader_read(r, shared_is_short ? 4 : 6);
    if (max_sfb > AAC_MAX_SFB_LONG) {
      max_sfb = AAC_MAX_SFB_LONG;
    }
    if (shared_is_short) {
      aac_bitreader_read(r, 7); /* scale_factor_grouping */
    } else if (aac_bitreader_read(r, 1)) {       /* predictor_data_present */
      if (aac_bitreader_read(r, 1)) {            /* predictor_reset */
        aac_bitreader_read(r, 5);                /* predictor_reset_group_number */
      }
    }

    for (int c = 0; c < 2; c++) {
      s->ch[c].win_seq = (AacWindowSequence)ws;
      s->ch[c].win_shape = (AacWindowShape)wshape;
      s->ch[c].max_sfb = max_sfb;
    }

    s->ms_mask_present = aac_bitreader_read(r, 2);
    if (s->ms_mask_present == 3) {
      /* ISO 14496-3: ms_mask_present == 3 is reserved. */
      return AAC_ERR_DECODE;
    }
    if (s->ms_mask_present == 1) {
      int nsfb = shared_is_short ? aac_num_sfb_short[s->rate_index]
                                 : aac_num_sfb_long[s->rate_index];
      for (int b = 0; b < nsfb && b < 49; b++) {
        s->ms_mask[b] = aac_bitreader_read(r, 1);
      }
    } else if (s->ms_mask_present == 2) {
      int nsfb = shared_is_short ? aac_num_sfb_short[s->rate_index]
                                 : aac_num_sfb_long[s->rate_index];
      for (int b = 0; b < nsfb && b < 49; b++) {
        s->ms_mask[b] = 1;
      }
    }
  }

  if (common_window) {
    /* Decode only spectral data (global_gain + huffman), then dequantize both
     * channels before applying the shared M/S transform. */
    for (int ch = 0; ch < 2; ch++) {
      int gg = aac_bitreader_read(r, 8) - 100;
      int ret = decode_spectral(s, r, ch, gg);
      if (ret) {
        return ret;
      }
    }
    for (int ch = 0; ch < 2; ch++) {
      aac_dequantize(&s->ch[ch], s->rate_index, 1024);
    }
    if (s->ms_mask_present) {
      aac_apply_ms(s);
    }
    for (int ch = 0; ch < 2; ch++) {
      aac_apply_tns(&s->ch[ch], s->rate_index, 1024);
      aac_imdct(&s->ch[ch].mdct_ctx, s->ch[ch].output, s->ch[ch].spectral, 1024,
                s->ch[ch].win_seq, s->ch[ch].win_shape, ch);
    }
  } else {
    for (int ch = 0; ch < 2; ch++) {
      int e = aac_decode_sce(s, r, ch);
      if (e) {
        return e;
      }
    }
  }
  return 0;
}
