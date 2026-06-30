#include "decoder.h"

#include <cmath>
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
  }
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
  int ws = aac_bitreader_read(r, 2);
  s->ch[ch].win_seq = (AacWindowSequence)ws;
  s->ch[ch].win_shape = (AacWindowShape)aac_bitreader_read(r, 1);
  int is_short = (ws == AAC_WIN_EIGHT_SHORT);
  int max_sfb =
      aac_bitreader_read(r, is_short ? 4 : 6);  // NOLINT(clang-analyzer-deadcode.DeadStores)
  (void)max_sfb;
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

  int prev_sf = gg; /* first scalefactor = global_gain */
  for (int sfb_idx = 0; sfb_idx < nsfb; sfb_idx++) {
    if (aac_bitreader_bits_left(r) < 13) {
      return AAC_ERR_DECODE;
    }
    int cb = aac_bitreader_read(r, 4);
    dc->sfb_cb[sfb_idx] = cb;
    if (cb == 0 || cb >= 13) {
      continue;
    }

    int dpcm = aac_bitreader_read_signed(r, 9);
    prev_sf += dpcm;
    dc->scalefactors[sfb_idx] = prev_sf;

    int start = sfb[sfb_idx], end = sfb[sfb_idx + 1];
    for (int bin = start; bin < end && bin < 1024; bin += 2) {
      if (bin < 0) {
        continue;
      }
      int x = 0, y = 0;
      if (aac_bitreader_read_huffman(r, cb, &x, &y) != 0) {
        return AAC_ERR_DECODE;
      }
      if (bin < 1024) {
        spec[bin] = (float)x;
      }
      if (bin + 1 < 1024) {
        spec[bin + 1] = (float)y;
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

int aac_decode_sce(AacDecoderState* s, AacBitReader* r, int ch) {
  int gg = parse_ics(s, r, ch);
  decode_spectral(s, r, ch, gg);
  aac_dequantize(&s->ch[ch], s->rate_index, 1024);
  aac_apply_tns(&s->ch[ch], s->rate_index, 1024);
  aac_imdct(&s->ch[ch].mdct_ctx, s->ch[ch].output, s->ch[ch].spectral, 1024, s->ch[ch].win_seq,
            s->ch[ch].win_shape, ch);
  return 0;
}

int aac_decode_cpe(AacDecoderState* s, AacBitReader* r) {
  int ms_mask_present = aac_bitreader_read(r, 2);
  (void)ms_mask_present;
  for (int ch = 0; ch < 2; ch++) {
    int e = aac_decode_sce(s, r, ch);
    if (e) {
      return e;
    }
  }
  return 0;
}
