#include "encoder.h"

#include <algorithm>
#include <cmath>
#include <cstdio>
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
    /* Bitrate-dependent threshold scaling (cf. 3GPP TS 26.403 §5.6.1
     * "reduction of psychoacoustic requirements"): when fewer bits are
     * available per sample, allow more quantization noise; when bits are
     * plentiful, demand less.  Without this the encoder quantizes to a fixed
     * SNR regardless of target bitrate, so quality does not scale. */
    float bps = (float)br / (float)ch / (float)sr; /* bits per sample per channel */
    float scale = 2.0f / bps;
    if (scale < 0.25f) {
      scale = 0.25f; /* don't demand better than ~-35 dB SNR from the model */
    }
    if (scale > 4.0f) {
      scale = 4.0f; /* don't allow worse than ~-23 dB SNR */
    }
    s->psycho_state[c].threshold_scale = scale;
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

/* M/S stereo decision per scalefactor band.  M/S coding pays off only when
 * the side channel is much weaker than the mid channel (strong stereo
 * correlation): then the side channel needs few bits and the joint coding
 * saves overall.  Encoding a strong side channel as M/S wastes bits versus
 * plain L/R, because the two M/S channels carry roughly independent content.
 * libfdk-aac and Apple AAC use the same correlation-style criterion (see
 * 3GPP TS 26.403 §5.5.2, which also attenuates the side channel at low
 * bitrates when correlation is weak). */
static void decide_ms(AacEncoderState* s, const float* L, const float* R, int nb, const int* sfb) {
  for (int b = 0; b < nb; b++) {
    float eM = 0, eS = 0;
    for (int i = sfb[b]; i < sfb[b + 1]; i++) {
      float m = (L[i] + R[i]) * 0.707f;
      float side = (L[i] - R[i]) * 0.707f;
      eM += m * m;
      eS += side * side;
    }
    /* Use M/S only when the side energy is well below the mid energy
     * (side/mid ratio < ~-4.5 dB).  For decorrelated content eS ≈ eM and the
     * band stays L/R. */
    s->ms_used[b] = (eS < eM * 0.35f) ? 1 : 0;
  }
}

/* ── Huffman bit estimation ───────────────────────────────────── */

static int estimate_sfb_bits(int cb, const int* quant, int s, int e) {
  if (cb == 0) {
    return 0;
  }
  const AacCodebookInfo* info = &aac_codebook_info[cb];
  int mv = info->max_val;
  int dim = info->dim;
  int bits = 0;
  for (int i = s; i < e; i += dim) {
    int v[4] = {0, 0, 0, 0};
    for (int k = 0; k < dim && i + k < e; k++) {
      v[k] = quant[i + k];
    }
    if (info->is_unsigned) {
      /* Unsigned book: code magnitudes, then sign bits per nonzero. */
      int max_mag = (cb == AAC_CB_ESCAPE) ? AAC_ESC_MAX_MAG : mv;
      int idx = 0;
      for (int k = 0; k < dim; k++) {
        int mag = std::abs(v[k]);
        if (mag > max_mag) { mag = max_mag;
}
        idx = idx * (mv + 1) + std::min(mag, mv);
        if (v[k] != 0) {
          bits++;
        }
      }
      bits += aac_huff_len[cb][idx];
      if (cb == AAC_CB_ESCAPE) {
        for (int k = 0; k < dim; k++) {
          int mag = std::abs(v[k]);
          if (mag > max_mag) { mag = max_mag;
}
          if (mag >= mv) {
            int n = 4;
            while (mag >= (1 << (n + 1))) {
              n++;
            }
            bits += (n - 4) + 1 + n;
          }
        }
      }
    } else {
      /* Signed-in-table book: values (and their signs) are in the index. */
      int mod = 2 * mv + 1;
      int idx = 0;
      for (int k = 0; k < dim; k++) {
        int sv = std::clamp(v[k], -mv, mv);
        idx = idx * mod + (sv + mv);
      }
      if (idx >= 0 && idx < aac_huff_count[cb] && aac_huff_len[cb]) {
        bits += aac_huff_len[cb][idx];
      } else {
        bits += info->max_bits;
      }
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
    /* Mixed-sign band: signed-in-table QUAD books (CB2 ±1, CB4 ±2), then
     * signed-in-table PAIR books (CB5/CB6 ±4), then unsigned magnitude PAIR
     * books with per-coefficient sign bits (CB8 ±7, CB10 ±12), then escape. */
    if (max_abs <= 1) {
      return 2;
    }
    if (max_abs <= 2) {
      return 4;
    }
    if (max_abs <= 4) {
      return 5;
    }
    if (max_abs <= 7) {
      return 8;
    }
    if (max_abs <= 12) {
      return 10;
    }
    return AAC_CB_ESCAPE;
  }
  /* Non-negative band: unsigned QUAD books (CB1 0..1, CB3 0..2), then
   * unsigned/signed-in-table PAIR books (CB5/CB6 ±4), then unsigned PAIR
   * books (CB7 0..7, CB9 0..12), then escape. */
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
  return AAC_CB_ESCAPE;
}

/* ── Quantize a single band with a given scalefactor ──────────── */

static int quantize_band_sf(const float* spec, int* qc, int s, int e, int sf, int max_q = AAC_ESC_MAX_MAG) {
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

/* ── Two-loop-search quantization (ISO 13818-7 App. C / 3GPP) ────
 *
 * Inner loop: shift every band's scalefactor together (equivalent to changing
 *   the global quantization step) until the frame's bit count lands inside
 *   [toofewbits, toomanybits]. Coarser scalefactor → fewer bits.
 * Outer loop: for each band whose quantization-noise energy still exceeds its
 *   psychoacoustic threshold, decrement that band's scalefactor (finer step →
 *   less noise) until the noise is under the threshold or the scalefactor
 *   bottoms out.
 *
 * zero_masked selects how bands with energy below their masking threshold are
 * treated: with zero_masked=false they are coded like any other band (spare
 * budget keeps the reconstruction noise floor low — required for high-bitrate
 * SNR scaling); with zero_masked=true they are pre-zeroed, freeing their bits
 * for audible bands under bit pressure (libfdk-aac gives them ZERO_HCB in
 * that regime).  aac_quantize_bands picks between the two passes.
 *
 * This replaces the old per-band lambda R-D search, which optimized absolute
 * (not masking-aware) distortion and could neither spend bits nor stop spending
 * them on inaudible bands. */
static int run_tls(AacEncoderState* s, int ch, const float* thr, const int* sfb, int nb,
                   int target_bits, bool zero_masked) {
  float* spec = s->spectral[ch];
  int* qc = s->quant_coeffs[ch];
  int total_bits = 0;

  if (target_bits < 64) {
    target_bits = 64;
  }
  int toomanybits = target_bits + target_bits / 8;
  int toofewbits = target_bits - target_bits / 8;

  int sf[49];
  bool zero[49];

  /* Per-band energy, zero rule, and initial scalefactor (target q≈10 for the
   * band maximum — the TLS then refines it toward the masking threshold). */
  for (int b = 0; b < nb; b++) {
    int bs = sfb[b], be = sfb[b + 1];
    float emax = 0, e = 0;
    for (int i = bs; i < be; i++) {
      float a = fabsf(spec[i]);
      if (a > emax) {
        emax = a;
      }
      e += spec[i] * spec[i];
    }
    if (emax < 1e-4f || (zero_masked && thr && e <= thr[b] * 0.25f)) {
      /* Near-silent band — or, in the zero_masked pass, a band whose energy
       * sits ≥ 6 dB below its masking threshold: zeroing it is inaudible with
       * margin, and its bits go to audible bands.  Bands closer to their
       * threshold stay coded — zeroing those is audible under the model. */
      zero[b] = true;
      sf[b] = 0;
      s->scalefactors[ch][b] = 0;
      s->codebooks[ch][b] = 0;
      for (int i = bs; i < be; i++) {
        qc[i] = 0;
      }
      continue;
    }
    zero[b] = false;
    /* Initial scalefactor targets the masking threshold, not a fixed q. The
     * outer loop refines it, but starting from the threshold differentiates
     * bands by importance (important bands fine, inaudible bands coarse) so the
     * inner loop's global shift can't collapse them all together. */
    int bw = be - bs;
    float tb = fmaxf(thr ? thr[b] : 1e-6f, 1e-12f);
    int sfi = (int)roundf(4.0f * log2f(powf(tb / (float)bw, 0.75f) / fmaxf(emax, 1e-12f)) + 4.0f);
    /* Above is derived from q = spec^0.75 * 2^(sf/4): choose sf so that a
     * coefficient at the per-bin noise floor sqrt(tb/bw) lands near q≈1. Tune
     * the trailing +4 empirically. Fall back to the q≈10 estimate if it looks
     * pathological. */
    if (!std::isfinite(sfi) || sfi < -100 || sfi > 155) {
      float spec_34 = powf(emax, 0.75f);
      sfi = (int)roundf(4.0f * log2f(10.0f / spec_34));
    }
    sf[b] = std::clamp(sfi, -100, 155);
    /* Climb sf until this band's quantization noise is under its threshold —
     * this is the per-band starting point the TLS refines.  Because the
     * quantizer clamps q to the codebook range, too-large sf makes the
     * dequantized value collapse (q=12, tiny 2^(-sf/4)), so noise rises again
     * after the sweet spot.  Track the best sf and stop when it stops
     * improving, otherwise the loop overshoots into the clamped region and
     * returns sf=155 with all coefficients quantized to zero. */
    quantize_band_sf(spec, qc, bs, be, sf[b]);
    float n = compute_noise(spec, qc, bs, be, sf[b]);
    int best_sf = sf[b];
    float best_noise = n;
    int stall = 0;
    int guard = 80;
    while (n > tb && sf[b] < 155 && guard-- > 0) {
      sf[b]++;
      quantize_band_sf(spec, qc, bs, be, sf[b]);
      n = compute_noise(spec, qc, bs, be, sf[b]);
      if (n < best_noise) {
        best_noise = n;
        best_sf = sf[b];
        stall = 0;
      } else if (++stall > 8) {
        break; /* noise not improving — past the sweet spot */
      }
    }
    sf[b] = best_sf;
    quantize_band_sf(spec, qc, bs, be, sf[b]);
  }

  int its = 0;
  bool fflag = true;
  while (fflag && its++ < 30) {
    /* ── inner loop: hit the bit budget via a global scalefactor shift ── */
    int qstep = (its == 1) ? 32 : 1;
    bool inner_changed;
    int last_tbits = -1;
    do {
      int tbits = 0;
      for (int b = 0; b < nb; b++) {
        if (zero[b]) {
          continue;
        }
        int bs = sfb[b], be = sfb[b + 1];
        quantize_band_sf(spec, qc, bs, be, sf[b]);
        int cb = select_codebook(qc, bs, be);
        tbits += estimate_sfb_bits(cb, qc, bs, be) + 4 + (cb == 0 ? 0 : 9);
      }
      inner_changed = false;
      /* Baander's quantizer is q = spec^0.75 * 2^(sf/4): higher sf → larger q
       * → more quantization levels → finer → MORE bits (inverted from the ISO
       * convention). So to shed bits, shift sf DOWN (coarser); to spend bits,
       * shift sf UP (finer).  Once every band is clamped at the codebook
       * maximum, shifting up no longer adds bits — detect that and stop,
       * otherwise sf is driven to 155 and the dequantized output collapses to
       * silence. */
      if (tbits > toomanybits) {
        for (int b = 0; b < nb; b++) {
          if (!zero[b] && sf[b] > -100) {
            sf[b] = std::max(-100, sf[b] - qstep);
            inner_changed = true;
          }
        }
      } else if (tbits < toofewbits) {
        if (tbits == last_tbits) {
          break; /* bit count saturated; further sf shifts change nothing */
        }
        for (int b = 0; b < nb; b++) {
          if (!zero[b] && sf[b] < 155) {
            sf[b] = std::min(155, sf[b] + qstep);
            inner_changed = true;
          }
        }
      }
      last_tbits = tbits;
      qstep >>= 1;
      if (qstep == 0 && tbits > toomanybits && inner_changed) {
        qstep = 1; /* one more nudge if still over budget */
      }
    } while (qstep > 0);

    /* ── outer loop: per-band, drive quantization noise below threshold ── */
    fflag = false;
    for (int b = 0; b < nb; b++) {
      if (zero[b]) {
        continue;
      }
      int bs = sfb[b], be = sfb[b + 1];
      quantize_band_sf(spec, qc, bs, be, sf[b]);
      float noise = compute_noise(spec, qc, bs, be, sf[b]);
      /* Same clamped-quantizer overshoot guard as the initial climb: keep the
       * best sf seen and stop when the noise stops improving, otherwise the
       * loop walks into the q=12 clamp region and produces sf=155 with all
       * coefficients quantized to zero. */
      int best_sf = sf[b];
      float best_noise = noise;
      int stall = 0;
      int guard = 80;
      /* Noise above threshold → quantize finer (higher sf → more levels). */
      while (noise > thr[b] && sf[b] < 155 && guard-- > 0) {
        sf[b]++;
        quantize_band_sf(spec, qc, bs, be, sf[b]);
        noise = compute_noise(spec, qc, bs, be, sf[b]);
        if (noise < best_noise) {
          best_noise = noise;
          best_sf = sf[b];
          stall = 0;
        } else if (++stall > 8) {
          break;
        }
        fflag = true;
      }
      sf[b] = best_sf;
      quantize_band_sf(spec, qc, bs, be, sf[b]);
    }
    /* Loop back to the inner loop so the bits the outer loop spent get
     * rebalanced against the budget. */
  }

  /* Clamp scalefactors to avoid the q=max clamp region.  When sf is so high
   * that q = spec^0.75 * 2^(sf/4) exceeds the largest escape-sequence
   * magnitude, the dequantized value collapses toward zero and the
   * quantization noise rises steeply.  Cap each band's sf at the point where
   * its largest coefficient quantizes near AAC_ESC_MAX_MAG.  The escape book
   * (ISO 14496-3 §4.6.3.3, as in libfdk-aac/Apple AAC) moved this ceiling far
   * above the old ±12 signed-book clamp, so the inner loop can now spend a
   * high-bitrate budget on real precision instead of saturating. */
  for (int b = 0; b < nb; b++) {
    if (zero[b]) {
      continue;
    }
    int bs = sfb[b], be = sfb[b + 1];
    float emax = 0.0f;
    for (int i = bs; i < be; i++) {
      float a = fabsf(spec[i]);
      if (a > emax) {
        emax = a;
      }
    }
    if (emax > 1e-12f) {
      float spec_34 = powf(emax, 0.75f);
      int sf_cap = (int)floorf(4.0f * log2f((float)AAC_ESC_MAX_MAG / spec_34));
      if (sf[b] > sf_cap) {
        sf[b] = sf_cap;
      }
    }
  }

  /* Finalize: requantize every band with its chosen scalefactor and count. */
  for (int b = 0; b < nb; b++) {
    int bs = sfb[b], be = sfb[b + 1];
    if (zero[b]) {
      s->scalefactors[ch][b] = 0;
      s->codebooks[ch][b] = 0;
      for (int i = bs; i < be; i++) {
        qc[i] = 0;
      }
      total_bits += 4;
      continue;
    }
    quantize_band_sf(spec, qc, bs, be, sf[b]);
    int cb = select_codebook(qc, bs, be);
    s->scalefactors[ch][b] = sf[b];
    s->codebooks[ch][b] = cb;
    if (cb == 0) {
      total_bits += 4;
    } else {
      total_bits += estimate_sfb_bits(cb, qc, bs, be) + 4 + 9;
    }
  }
  return total_bits;
}

/* Total audibility-normalized quantization noise of the quantization stored
 * in the state: sum over ALL bands of noise/threshold.  A zeroed band
 * contributes its energy — so zeroing a band at e=0.9*thr costs ~0.9, about
 * as much as leaving an audible band's noise at its threshold.  This is the
 * metric the two-pass masked-band selection below minimizes. */
static float tls_total_noise_ratio(const AacEncoderState* s, int ch, const float* thr,
                                   const int* sfb, int nb) {
  const float* spec = s->spectral[ch];
  const int* qc = s->quant_coeffs[ch];
  float m = 0.0f;
  for (int b = 0; b < nb; b++) {
    int bs = sfb[b], be = sfb[b + 1];
    float noise = compute_noise(spec, qc, bs, be, s->scalefactors[ch][b]);
    m += noise / fmaxf(thr[b], 1e-12f);
  }
  return m;
}

int aac_quantize_bands(AacEncoderState* s, int ch, const float* thr, const int* sfb, int nb,
                       int target_bits) {
  /* Pass 1: code every band, masked or not. */
  int bits1 = run_tls(s, ch, thr, sfb, nb, target_bits, false);

  /* Budget-pressure check.  When the budget is (nearly) exhausted while bands
   * sitting well below their masking threshold still carry spectral bits,
   * those bits are stolen from audible bands — the inner loop's global sf
   * shift cannot push masked bands to exact zero without coarsening audible
   * bands too.  Rerun with deeply-masked bands pre-zeroed (libfdk-aac gives
   * such bands ZERO_HCB under bit pressure) and keep whichever pass has less
   * total audibility-normalized noise.  With a generous budget, zeroing the
   * masked bands costs more (their full energy) than the audible-band
   * refinement buys, so pass 1 wins and masked bands stay coded — which is
   * what lets high-bitrate SNR keep scaling. */
  int toofewbits = target_bits - target_bits / 8;
  if (!thr || bits1 < toofewbits) {
    return bits1;
  }
  bool masked_coded = false;
  for (int b = 0; b < nb && !masked_coded; b++) {
    if (s->codebooks[ch][b] != 0) {
      const float* spec = s->spectral[ch];
      float e = 0.0f;
      for (int i = sfb[b]; i < sfb[b + 1]; i++) {
        e += spec[i] * spec[i];
      }
      masked_coded = (e <= thr[b] * 0.25f);
    }
  }
  if (!masked_coded) {
    return bits1;
  }

  /* Save pass 1, rerun with deeply-masked bands pre-zeroed. */
  int sf1[49], cb1[49];
  int qc1[1024];
  memcpy(sf1, s->scalefactors[ch], (size_t)nb * sizeof(int));
  memcpy(cb1, s->codebooks[ch], (size_t)nb * sizeof(int));
  memcpy(qc1, s->quant_coeffs[ch], 1024 * sizeof(int));
  float n1 = tls_total_noise_ratio(s, ch, thr, sfb, nb);
  int bits2 = run_tls(s, ch, thr, sfb, nb, target_bits, true);
  float n2 = tls_total_noise_ratio(s, ch, thr, sfb, nb);

  if (n2 < n1 * 0.98f) {
    return bits2; /* pass 2: masked-band bits better spent on audible bands */
  }
  memcpy(s->scalefactors[ch], sf1, (size_t)nb * sizeof(int));
  memcpy(s->codebooks[ch], cb1, (size_t)nb * sizeof(int));
  memcpy(s->quant_coeffs[ch], qc1, 1024 * sizeof(int));
  return bits1;
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

/* ── Rate-control helpers ───────────────────────────────────────
 *
 * libfdk-aac and Apple's AAC encoder both use a bit reservoir to let
 * easy frames save bits for hard frames while keeping the long-term
 * bitrate on target.  baander-aac previously ignored the rc_mode and
 * the bit_reservoir field, quantizing every channel to a fixed share
 * of the nominal frame budget.  The helpers below select a per-frame
 * target and reservoir size from the mode, then update the reservoir
 * after the real frame size is known. */

static int aac_rate_control_max_reservoir(const AacEncoderState* s) {
  /* Reservoir size in frames of nominal bitrate.  These match the
   * general magnitude used by FDK (up to several frames) and Apple
   * (buffer up to 6144 bits/channel, i.e. a few LC frames). */
  switch (s->rc_mode) {
    case AAC_RC_CBR:
      return s->target_bits_per_frame * 5;
    case AAC_RC_ABR:
      return s->target_bits_per_frame * 10;
    case AAC_RC_CVBR:
      return s->target_bits_per_frame * 20;
    case AAC_RC_TVBR:
    default:
      return 0;
  }
}

static int aac_rate_control_frame_target(AacEncoderState* s, int* out_min,
                                         int* out_max) {
  int target = s->target_bits_per_frame;
  int reservoir = s->bit_reservoir;
  int max_res = aac_rate_control_max_reservoir(s);
  int frame_min = 64;
  int frame_max = (1 << 13) - 1; /* ADTS frame_length is 13 bits */

  switch (s->rc_mode) {
    case AAC_RC_CBR:
      /* Tight control: aim at the nominal frame size, only gently
       * correct the reservoir so that the bitrate stays constant. */
      target += (int)(reservoir * 0.25f);
      break;
    case AAC_RC_ABR:
      /* Average bitrate: allow more borrowing from the reservoir. */
      target += (int)(reservoir * 0.5f);
      break;
    case AAC_RC_CVBR:
      /* Constrained VBR: spend the whole reservoir on difficult frames
       * but cap the frame size to the configured peak. */
      target += reservoir;
      frame_max = std::min(frame_max, target * 2);
      break;
    case AAC_RC_TVBR:
      /* True VBR: quality-based, ignore long-term bitrate target. */
      target = (int)(target * (0.5f + s->quality / 100.0f));
      reservoir = 0;
      max_res = 0;
      break;
  }

  /* Clamp target to the reservoir bounds.  After spending actual_bits,
   * the new reservoir = reservoir + target_bits_per_frame - actual_bits
   * must stay inside [-max_res, max_res].  This implies:
   *   actual_bits <= target_bits_per_frame + max_res - reservoir
   *   actual_bits >= target_bits_per_frame - max_res - reservoir
   * Here we translate that into the allowed range for this frame. */
  if (max_res > 0) {
    int spendable = s->target_bits_per_frame + max_res - reservoir;
    int saveable = s->target_bits_per_frame - max_res - reservoir;
    frame_max = std::min(frame_max, spendable);
    frame_min = std::max(frame_min, saveable);
  }

  if (target < frame_min) {
    target = frame_min;
  }
  if (target > frame_max) {
    target = frame_max;
  }

  *out_min = frame_min;
  *out_max = frame_max;
  return target;
}

static int aac_rate_control_update_reservoir(AacEncoderState* s, int actual_bits) {
  int max_res = aac_rate_control_max_reservoir(s);
  if (max_res <= 0) {
    s->bit_reservoir = 0;
    return 0;
  }
  s->bit_reservoir += s->target_bits_per_frame - actual_bits;
  if (s->bit_reservoir > max_res) {
    s->bit_reservoir = max_res;
  }
  if (s->bit_reservoir < -max_res) {
    s->bit_reservoir = -max_res;
  }
  return s->bit_reservoir;
}

static int aac_rate_control_buffer_fullness(const AacEncoderState* s) {
  /* ADTS buffer_fullness: 0x7FF means variable bitrate; otherwise it
   * reports the encoder buffer fullness on a 0..0x7FE scale.  Apple
   * and FDK both emit 0x7FF for VBR and a real value for CBR/ABR. */
  if (s->rc_mode == AAC_RC_TVBR || s->rc_mode == AAC_RC_CVBR) {
    return 0x7FF;
  }
  int max_res = aac_rate_control_max_reservoir(s);
  if (max_res <= 0) {
    return 0x7FF;
  }
  int fullness = (int)(((long long)s->bit_reservoir + max_res) * 0x7FE /
                       (2LL * max_res));
  if (fullness < 0) {
    fullness = 0;
  }
  if (fullness > 0x7FE) {
    fullness = 0x7FE;
  }
  return fullness;
}

/* ── ISO 14496-3 individual_channel_stream writing helpers ─────── */

static void write_ics_info(AacBitWriter* w, int nb) {
  /* ISO 14496-3 ics_info: ics_reserved_bit (1) must be 0 for non-ELD AOTs. */
  aac_bitwriter_write(w, 0, 1);
  aac_bitwriter_write(w, AAC_WIN_ONLY_LONG, 2);
  aac_bitwriter_write(w, AAC_WIN_SINE, 1);
  aac_bitwriter_write(w, nb > 63 ? 63 : nb, 6);
  aac_bitwriter_write(w, 0, 1); /* predictor_data_present */
}

static void write_spectral_data(AacBitWriter* w, int cb, const int* quant, int start, int end) {
  const AacCodebookInfo* info = &aac_codebook_info[cb];
  int mv = info->max_val;
  int dim = info->dim;
  for (int i = start; i < end; i += dim) {
    int v[4] = {0, 0, 0, 0};
    for (int k = 0; k < dim && i + k < end; k++) {
      v[k] = quant[i + k];
    }
    if (cb == AAC_CB_ESCAPE) {
      for (int k = 0; k < dim; k++) {
        v[k] = std::clamp(v[k], -AAC_ESC_MAX_MAG, AAC_ESC_MAX_MAG);
      }
    } else if (info->is_unsigned) {
      for (int k = 0; k < dim; k++) {
        v[k] = std::clamp(v[k], -mv, mv);
      }
    } else {
      for (int k = 0; k < dim; k++) {
        v[k] = std::clamp(v[k], -mv, mv);
      }
    }
    aac_bitwriter_write_huffman(w, cb, v);
  }
}

static void write_individual_channel_stream(AacEncoderState* s, AacBitWriter* w, int ch,
                                            int nb, const int* sfb, int write_ics) {
  aac_bitwriter_write(w, s->scalefactors[ch][0] + 100, 8); /* global_gain */
  if (write_ics) {
    write_ics_info(w, nb);
  }

  /* section_data: group consecutive scalefactor bands with the same codebook */
  aac_bitwriter_write_sections(w, 0, s->codebooks[ch], nb);

  /* scale_factor_data: DPCM scalefactors, Huffman coded (ISO Table 4.47) */
  int prev_sf = s->scalefactors[ch][0];
  for (int b = 0; b < nb; b++) {
    int cb = s->codebooks[ch][b];
    if (cb == 0 || cb >= 13) {
      continue;
    }
    int diff = s->scalefactors[ch][b] - prev_sf;
    aac_bitwriter_write_scalefactor(w, diff);
    prev_sf = s->scalefactors[ch][b];
  }

  aac_bitwriter_write(w, 0, 1); /* pulse_data_present */
  aac_bitwriter_write(w, 0, 1); /* tns_data_present */
  aac_bitwriter_write(w, 0, 1); /* gain_control_data_present / ssr */

  /* spectral_data */
  for (int b = 0; b < nb; b++) {
    int cb = s->codebooks[ch][b];
    if (cb == 0 || cb >= 13) {
      continue;
    }
    write_spectral_data(w, cb, s->quant_coeffs[ch], sfb[b], sfb[b + 1]);
  }
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

  /* M/S decision and transform for stereo.  libfdk-aac and Apple's encoder
   * both apply M/S selectively per scalefactor band to exploit stereo
   * correlation; signaling it through the CPE common_window reduces the bits
   * spent on identical side information. */
  if (s->channels == 2) {
    decide_ms(s, s->spectral[0], s->spectral[1], nb, sfb);
    for (int b = 0; b < nb; b++) {
      if (s->ms_used[b]) {
        aac_ms_encode(&s->spectral[0][sfb[b]], &s->spectral[1][sfb[b]],
                      &s->spectral[0][sfb[b]], &s->spectral[1][sfb[b]],
                      sfb[b + 1] - sfb[b]);
      }
    }
  }

  /* Psychoacoustic analysis */
  for (int c = 0; c < s->channels; c++) {
    aac_psycho_analyze(&s->psycho_state[c], s->spectral[c], nb, sfb);
  }

  /* Two-loop-search quantization. The TLS hits the per-channel bit budget
   * internally, so each channel is quantized against its share of the frame
   * budget chosen by the rate-control mode and the current bit reservoir. */
  int frame_min = 0, frame_max = 0;
  int frame_target = aac_rate_control_frame_target(s, &frame_min, &frame_max);
  int per_ch_target = frame_target / s->channels;
  if (per_ch_target < 64) {
    per_ch_target = 64;
  }
  for (int c = 0; c < s->channels; c++) {
    aac_quantize_bands(s, c, aac_psycho_get_thresholds(&s->psycho_state[c]), sfb, nb, per_ch_target);
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
  /* number_of_raw_data_blocks_in_frame is (block count - 1) per ISO 13818-7
   * §8.1.1; this frame carries exactly one raw_data_block, so write 0. */
  hdr.num_aac_frames = 0;
  aac_bitwriter_write(&s->writer, 0, 56);

  /* Write channel elements */
  if (s->channels == 1) {
    aac_bitwriter_write(&s->writer, AAC_ELEM_SCE, 3);
    aac_bitwriter_write(&s->writer, 0, 4); /* tag */
    write_individual_channel_stream(s, &s->writer, 0, nb, sfb, 1);
  } else {
    aac_bitwriter_write(&s->writer, AAC_ELEM_CPE, 3);
    aac_bitwriter_write(&s->writer, 0, 4); /* tag */
    aac_bitwriter_write(&s->writer, 1, 1); /* common_window */

    /* Shared ics_info for the channel pair.  Both channels use the same
     * window sequence/shape, so they can share the side information. */
    write_ics_info(&s->writer, nb);

    /* ms_mask_present == 1 signals per-band ms_used flags.  Bands where
     * M/S was applied (ms_used[b]) carry mid/side instead of left/right. */
    aac_bitwriter_write(&s->writer, 1, 2);
    for (int b = 0; b < nb; b++) {
      aac_bitwriter_write(&s->writer, s->ms_used[b], 1);
    }

    /* Per-channel individual_channel_stream (ics_info omitted because
     * common_window == 1).  Scalefactors remain channel-specific. */
    for (int ch = 0; ch < 2; ch++) {
      write_individual_channel_stream(s, &s->writer, ch, nb, sfb, 0);
    }
  }

  aac_bitwriter_write(&s->writer, AAC_ELEM_END, 3);
  aac_bitwriter_byte_align(&s->writer);

  if (aac_bitwriter_overflow(&s->writer)) {
    return AAC_ERR_OVERFLOW;
  }

  int frame_len = aac_bitwriter_bytes_written(&s->writer);
  hdr.frame_length = frame_len;

  /* Update reservoir with the actual frame size in bits.  The header bytes
   * themselves are part of the ADTS frame, so count the whole frame. */
  aac_rate_control_update_reservoir(s, frame_len * 8);

  /* ADTS buffer_fullness reports the encoder buffer state after this frame;
   * 0x7FF is the VBR marker. */
  hdr.buffer_fullness = aac_rate_control_buffer_fullness(s);
  aac_adts_write(&hdr, s->output_buf);

  return frame_len;
}
