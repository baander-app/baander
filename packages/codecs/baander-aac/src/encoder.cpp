#include "encoder.h"

#include <algorithm>
#include <cmath>
#include <cstring>

AacEncoderState* aac_encoder_state_create(int sr, int ch, int br, AacObjectType aot,
                                          AacRateControl rc, const AacDSP* dsp) {
  auto* s = new AacEncoderState();
  s->sample_rate = sr;
  s->channels = ch;
  s->bitrate = br;
  s->aot = aot;
  s->rc_mode = rc;
  s->quality = 100;
  s->frame_size = (aot == AAC_AOT_LC) ? 1024 : 2048;
  s->bit_reservoir = 0;
  s->lambda = 0.0001f;
  s->dsp = dsp;
  s->target_bits_per_frame = (int)((float)br * 1024.0f / (float)sr);
  s->rate_index = 3;
  for (int i = 0; i < AAC_NUM_SAMPLE_RATES; i++) {
    if (aac_sample_rates[i] == sr) {
      s->rate_index = i;
      break;
    }
  }
  for (int c = 0; c < ch; c++) {
    aac_mdct_init(&s->mdct_ctx[c], 1024, dsp);
    aac_psycho_init(&s->psycho_state[c], sr, 1024);
  }
  s->pcm_buf_fill = 0;
  return s;
}

void aac_encoder_state_destroy(AacEncoderState* s) {
  if (!s) {
    return;
  }
  for (int c = 0; c < s->channels; c++) {
    aac_mdct_free(&s->mdct_ctx[c]);
  }
  delete s;
}

static void decide_ms(AacEncoderState* s, const float* L, const float* R, int nb, const int* sfb) {
  for (int b = 0; b < nb; b++) {
    float eL = 0, eR = 0, eM = 0, eS = 0;
    for (int i = sfb[b]; i < sfb[b + 1]; i++) {
      float m = (L[i] + R[i]) * 0.707f;
      float side = (L[i] - R[i]) * 0.707f;
      eL += L[i] * L[i];
      eR += R[i] * R[i];
      eM += m * m;
      eS += side * side;
    }
    s->ms_used[b] = (eM + eS < (eL + eR) * 1.1f) ? 1 : 0;
  }
}

/* ── Huffman bit estimation ───────────────────────────────────── */

static int estimate_sfb_bits(int cb, const int* quant, int s, int e) {
  if (cb == 0) {
    return 0;
  }
  const AacCodebookInfo* info = &aac_codebook_info[cb];
  int mv = info->max_val;
  int bits = 0;
  for (int i = s; i < e; i += 2) {
    int x = quant[i], y = (i + 1 < e) ? quant[i + 1] : 0;
    if (info->is_unsigned) {
      x = std::clamp(x, 0, mv);
      y = std::clamp(y, 0, mv);
    } else {
      x = std::clamp(x, -mv, mv);
      y = std::clamp(y, -mv, mv);
    }
    int idx = 0;
    if (info->is_unsigned) {
      idx = x * (mv + 1) + y;
    } else {
      idx = (x + mv) * (2 * mv + 1) + (y + mv);
    }
    if (idx >= 0 && idx < aac_huff_count[cb] && aac_huff_len[cb]) {
      bits += aac_huff_len[cb][idx];
    } else {
      bits += info->max_bits;
    }
  }
  return bits;
}

/* ── Codebook selection ───────────────────────────────────────── */

static int select_codebook(const int* quant, int s, int e) {
  int max_abs = 0;
  bool has_neg = false;
  for (int i = s; i < e; i++) {
    int a = std::abs(quant[i]);
    if (a > max_abs) {
      max_abs = a;
    }
    if (quant[i] < 0) {
      has_neg = true;
    }
  }
  if (max_abs == 0) {
    return 0;
  }
  if (has_neg) {
    /* Signed codebooks: CB2(±1), CB4(±2), CB6(±4), CB8(±7), CB10(±12) */
    if (max_abs <= 1) {
      return 2;
    }
    if (max_abs <= 2) {
      return 4;
    }
    if (max_abs <= 4) {
      return 6;
    }
    if (max_abs <= 7) {
      return 8;
    }
    return 10; /* CB10: signed ±12, clamp larger values */
  }
  /* Unsigned codebooks: CB1(0-1), CB3(0-2), CB5(0-4), CB7(0-7), CB9(0-12), CB11(0-16) */
  if (max_abs <= 1) {
    return 1;
  }
  if (max_abs <= 2) {
    return 3;
  }
  if (max_abs <= 4) {
    return 5;
  }
  if (max_abs <= 7) {
    return 7;
  }
  if (max_abs <= 12) {
    return 9;
  }
  return 11;
}

/* ── Quantize a single band with a given scalefactor ──────────── */

static int quantize_band_sf(const float* spec, int* qc, int s, int e, int sf, int max_q = 12) {
  float sf_scale = powf(2.0f, sf * 0.25f);
  int max_abs = 0;
  for (int i = s; i < e; i++) {
    float q = copysignf(powf(fabsf(spec[i]), 0.75f) * sf_scale, spec[i]);
    int iq = (int)roundf(q);
    iq = std::clamp(iq, -max_q, max_q);
    qc[i] = iq;
    int a = std::abs(iq);
    if (a > max_abs) {
      max_abs = a;
    }
  }
  return max_abs;
}

/* ── Compute noise energy for a band ──────────────────────────── */

static float compute_noise(const float* spec, const int* qc, int s, int e, int sf) {
  float sf_scale_inv = powf(2.0f, -sf * 0.25f);
  float noise = 0;
  for (int i = s; i < e; i++) {
    float dq = copysignf(powf(fabsf((float)qc[i] * sf_scale_inv), 4.0f / 3.0f), (float)qc[i]);
    float err = spec[i] - dq;
    noise += err * err;
  }
  return noise;
}

/* ── Per-band R-D quantization ────────────────────────────────── */

int aac_quantize_bands(AacEncoderState* s, int ch, float lambda, const float* thr, const int* sfb,
                       int nb) {
  float* spec = s->spectral[ch];
  int* qc = s->quant_coeffs[ch];
  int total_bits = 0;

  for (int b = 0; b < nb; b++) {
    int bs = sfb[b], be = sfb[b + 1];
    int bw = be - bs;

    /* Find band maximum */
    float band_max = 0;
    for (int i = bs; i < be; i++) {
      float a = fabsf(spec[i]);
      if (a > band_max) {
        band_max = a;
      }
    }

    if (band_max < 0.001f) {
      s->scalefactors[ch][b] = 0;
      s->codebooks[ch][b] = 0;
      for (int i = bs; i < be; i++) {
        qc[i] = 0;
      }
      total_bits += 4;
      continue;
    }

    /* Compute sf_center: scalefactor that maps band_max → target_q.
     * target_q = 10 gives good quality with cb=9/10.
     * q = spec^(3/4) * 2^(sf/4), so sf = 4 * log2(target_q / spec^(3/4)) */
    float spec_34 = powf(band_max, 0.75f);
    int sf_center = (int)roundf(4.0f * log2f(10.0f / spec_34));
    sf_center = std::clamp(sf_center, -100, 155);

    /* Signal energy for NMR calculation */
    float sig_energy = 0;
    for (int i = bs; i < be; i++) {
      sig_energy += spec[i] * spec[i];
    }

    /* Perceptual weight: bands with high energy relative to threshold
     * are more important and should be quantized more carefully. */
    float pe_weight = 1.0f;
    if (thr && thr[b] > 1e-20f) {
      pe_weight = sqrtf(sig_energy) / (thr[b] + 1e-10f);
      pe_weight = std::clamp(pe_weight, 0.1f, 100.0f);
    }

    /* Dense sf search — widen range to find best R-D tradeoff */
    int best_sf = sf_center;
    float best_cost = 1e30f;
    int tmp_qc[256];

    int sf_lo = sf_center - 80;
    int sf_hi = sf_center + 40;
    sf_lo = std::max(sf_lo, -100);
    sf_hi = std::min(sf_hi, 155);

    for (int sf = sf_lo; sf <= sf_hi; sf++) {
      /* Quantize into tmp_qc[0..bw-1] using spec[bs..be-1] */
      float sf_scale = powf(2.0f, sf * 0.25f);
      int max_abs = 0;
      for (int i = 0; i < bw; i++) {
        float q = copysignf(powf(fabsf(spec[bs + i]), 0.75f) * sf_scale, spec[bs + i]);
        int iq = (int)roundf(q);
        iq = std::clamp(iq, -12, 12);
        tmp_qc[i] = iq;
        int a = std::abs(iq);
        if (a > max_abs) {
          max_abs = a;
        }
      }

      /* Minimum quality: non-zero bands must use at least max_q=4 (cb≥6) */
      if (max_abs < 4 && max_abs > 0 && sf < sf_hi) {
        continue;
      }
      /* Skip if all zero (unless it's the only option) */
      if (max_abs == 0 && sf > sf_lo + 5) {
        continue;
      }

      /* Compute noise */
      float sf_scale_inv = powf(2.0f, -sf * 0.25f);
      float noise = 0;
      for (int i = 0; i < bw; i++) {
        float dq =
            copysignf(powf(fabsf((float)tmp_qc[i] * sf_scale_inv), 4.0f / 3.0f), (float)tmp_qc[i]);
        float err = spec[bs + i] - dq;
        noise += err * err;
      }
      float nmr = noise; /* Use absolute noise energy, not normalized */

      /* Select codebook and count bits */
      int cb = select_codebook(tmp_qc, 0, bw);
      int bits = estimate_sfb_bits(cb, tmp_qc, 0, bw) + 4 + (cb == 0 ? 0 : 9);

      /* R-D cost: distortion + lambda * rate.
       * Use sqrt(noise) for better perceptual weighting (closer to RMS). */
      float dist = sqrtf(nmr) * pe_weight;
      float cost = dist + lambda * (float)bits;

      if (cost < best_cost) {
        best_sf = sf;
        best_cost = cost;
      }
    }

    /* Final quantize with best_sf */
    quantize_band_sf(spec, qc, bs, be, best_sf);
    s->scalefactors[ch][b] = best_sf;
    s->codebooks[ch][b] = select_codebook(qc, bs, be);
    int bits = estimate_sfb_bits(s->codebooks[ch][b], qc, bs, be);
    total_bits += bits + 4 + (s->codebooks[ch][b] == 0 ? 0 : 9);
  }
  return total_bits;
}

/* ── Rate control ─────────────────────────────────────────────── */

float aac_rate_control_lambda(AacEncoderState* s, int used, int target) {
  if (target <= 0) {
    return s->lambda;
  }
  float ratio = (float)used / (float)target;
  if (ratio < 0.01f) {
    ratio = 0.01f;
  }
  if (ratio > 100.0f) {
    ratio = 100.0f;
  }
  /* Exponential update for faster convergence */
  float new_lambda = s->lambda * ratio;
  return std::max(1e-8f, std::min(new_lambda, 1e6f));
}

/* ── Frame encoding ───────────────────────────────────────────── */

int aac_encode_frame_internal(AacEncoderState* s, const float* pcm, int ns) {
  int ri = s->rate_index;
  int nb = aac_num_sfb_long[ri];
  const int* sfb = aac_sfb_offset_long[ri];

  /* Deinterleave stereo */
  float ch_buf[2][2048];
  if (s->channels == 1) {
    memcpy(ch_buf[0], pcm, ns * sizeof(float));
  } else {
    for (int i = 0; i < ns; i++) {
      ch_buf[0][i] = pcm[static_cast<ptrdiff_t>(i) * 2];
      ch_buf[1][i] = pcm[static_cast<ptrdiff_t>(i) * 2 + 1];
    }
  }

  /* MDCT analysis */
  for (int c = 0; c < s->channels; c++) {
    aac_mdct_forward_aac(&s->mdct_ctx[c], s->spectral[c], ch_buf[c], 1024, AAC_WIN_ONLY_LONG,
                         AAC_WIN_SINE, c);
  }

  /* M/S decision for stereo */
  if (s->channels == 2) {
    decide_ms(s, s->spectral[0], s->spectral[1], nb, sfb);
  }

  /* Psychoacoustic analysis */
  for (int c = 0; c < s->channels; c++) {
    aac_psycho_analyze(&s->psycho_state[c], s->spectral[c], nb, sfb);
  }

  /* Quantization with rate control iterations */
  for (int iter = 0; iter < 32; iter++) {
    int total_bits = 0;
    for (int c = 0; c < s->channels; c++) {
      total_bits += aac_quantize_bands(s, c, s->lambda,
                                       aac_psycho_get_thresholds(&s->psycho_state[c]), sfb, nb);
    }
    int diff = total_bits - s->target_bits_per_frame;
    if (diff > -s->target_bits_per_frame / 10 && diff < s->target_bits_per_frame / 10) {
      break;
    }
    float new_lambda = aac_rate_control_lambda(s, total_bits, s->target_bits_per_frame);
    if (new_lambda > 0 && std::isfinite(new_lambda)) {
      s->lambda = std::max(1e-8f, std::min(new_lambda, 1e6f));
    }
  }

  /* Write bitstream */
  aac_bitwriter_init(&s->writer, s->output_buf, sizeof(s->output_buf));

  /* ADTS header placeholder (7 bytes) */
  AacAdtsHeader hdr = {};  // NOLINT(bugprone-invalid-enum-default-initialization)
  hdr.id = 0;
  hdr.layer = 0;
  hdr.protection_absent = 1;
  hdr.profile = s->aot;
  hdr.sample_rate_index = ri;
  hdr.channel_config = s->channels;
  hdr.frame_length = 0;
  hdr.buffer_fullness = 0x7FF;
  hdr.num_aac_frames = 1;
  aac_bitwriter_write(&s->writer, 0, 56);

  /* Write channel elements */
  if (s->channels == 1) {
    aac_bitwriter_write(&s->writer, AAC_ELEM_SCE, 3);
    aac_bitwriter_write(&s->writer, 0, 4);                           /* tag */
    aac_bitwriter_write(&s->writer, s->scalefactors[0][0] + 100, 8); /* global_gain */
    aac_bitwriter_write(&s->writer, AAC_WIN_ONLY_LONG, 2);
    aac_bitwriter_write(&s->writer, AAC_WIN_SINE, 1);
    aac_bitwriter_write(&s->writer, nb > 63 ? 63 : nb, 6);
    aac_bitwriter_write(&s->writer, 0, 1); /* predictor */
    int prev_sf = s->scalefactors[0][0];
    for (int b = 0; b < nb; b++) {
      int cb = s->codebooks[0][b];
      aac_bitwriter_write(&s->writer, cb, 4);
      if (cb == 0 || cb >= 13) {
        continue;
      }
      int dpcm = s->scalefactors[0][b] - prev_sf;
      prev_sf = s->scalefactors[0][b];
      aac_bitwriter_write_signed(&s->writer, dpcm, 9);
      for (int i = sfb[b]; i < sfb[b + 1]; i += 2) {
        int x = s->quant_coeffs[0][i];
        int y = (i + 1 < sfb[b + 1]) ? s->quant_coeffs[0][i + 1] : 0;
        int mv = aac_codebook_info[cb].max_val;
        if (aac_codebook_info[cb].is_unsigned) {
          x = std::clamp(x, 0, mv);
          y = std::clamp(y, 0, mv);
        } else {
          x = std::clamp(x, -mv, mv);
          y = std::clamp(y, -mv, mv);
        }
        aac_bitwriter_write_huffman(&s->writer, cb, x, y);
      }
    }
  } else {
    aac_bitwriter_write(&s->writer, AAC_ELEM_CPE, 3);
    aac_bitwriter_write(&s->writer, 0, 4); /* tag */
    aac_bitwriter_write(&s->writer, 0, 1); /* common_window */
    for (int ch = 0; ch < 2; ch++) {
      aac_bitwriter_write(&s->writer, s->scalefactors[ch][0] + 100, 8);
      aac_bitwriter_write(&s->writer, AAC_WIN_ONLY_LONG, 2);
      aac_bitwriter_write(&s->writer, AAC_WIN_SINE, 1);
      aac_bitwriter_write(&s->writer, nb > 63 ? 63 : nb, 6);
      aac_bitwriter_write(&s->writer, 0, 1);
      int prev_sf_ch = s->scalefactors[ch][0];
      for (int b = 0; b < nb; b++) {
        int cb = s->codebooks[ch][b];
        aac_bitwriter_write(&s->writer, cb, 4);
        if (cb == 0 || cb >= 13) {
          continue;
        }
        int dpcm = s->scalefactors[ch][b] - prev_sf_ch;
        prev_sf_ch = s->scalefactors[ch][b];
        aac_bitwriter_write_signed(&s->writer, dpcm, 9);
        for (int i = sfb[b]; i < sfb[b + 1]; i += 2) {
          int x = s->quant_coeffs[ch][i];
          int y = (i + 1 < sfb[b + 1]) ? s->quant_coeffs[ch][i + 1] : 0;
          int mv = aac_codebook_info[cb].max_val;
          if (aac_codebook_info[cb].is_unsigned) {
            x = std::clamp(x, 0, mv);
            y = std::clamp(y, 0, mv);
          } else {
            x = std::clamp(x, -mv, mv);
            y = std::clamp(y, -mv, mv);
          }
          aac_bitwriter_write_huffman(&s->writer, cb, x, y);
        }
      }
    }
  }

  aac_bitwriter_write(&s->writer, AAC_ELEM_END, 3);
  aac_bitwriter_byte_align(&s->writer);

  int frame_len = aac_bitwriter_bytes_written(&s->writer);
  hdr.frame_length = frame_len;
  aac_adts_write(&hdr, s->output_buf);

  return frame_len;
}
