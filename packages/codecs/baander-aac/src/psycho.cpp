#include "psycho.h"

#include <cmath>
#include <cstring>

#include "aac_tables.h"

/* ── 3GPP TS 26.403-inspired psychoacoustic model ──────────────────
 *
 * The previous model mixed units: band energy was per-bin, the ATH came from
 * a dB-domain formula, and the encoder summed quantization noise as total band
 * energy. "noise < threshold" was therefore meaningless and the encoder could
 * not allocate bits by audibility.
 *
 * This model keeps every quantity in total-MDCT-band-energy units (sum of
 * coefficient squared), so the thresholds it emits are directly comparable to
 * the per-band quantization noise the two-loop-search quantizer computes:
 *
 *   band.energy   = Σ coef²                         (total band energy)
 *   band.thr      = energy × 0.001258925            (≈ -29 dB initial SNR)
 *                 → two-slope Bark spreading (forward + backward)
 *                 → ATH floor (energy units)
 *                 → pre-echo limiting against the previous frame
 *
 * The TLS quantizer then drives each band's quantization noise down to its
 * threshold, spending bits only on audible distortion.
 */

/* Schroeder/Bark scale. */
static float calc_bark(float f) {
  return 13.3f * atanf(0.00076f * f) + 3.5f * atanf((f / 7500.0f) * (f / 7500.0f));
}

/* ATH in dB (LAME-style). Anchored so a full-scale tone sits well above it. */
static float ath_db(float f) {
  f /= 1000.0f;
  return 3.64f * powf(f, -0.8f) - 6.8f * expf(-0.6f * (f - 3.4f) * (f - 3.4f)) +
         6.0f * expf(-0.15f * (f - 8.7f) * (f - 8.7f)) + 0.6f * 0.001f * f * f * f * f;
}

/* MDCT band energy of a full-scale (~1.0 amplitude) sine, used to map the
 * dB-domain ATH into the encoder's energy scale. A sine of amplitude A produces
 * an MDCT peak of order A*N/2 with a sine window; its single-bin energy is
 * roughly (A*N/4)². This anchor is approximate and intentionally conservative
 * (ATH should only floor near-silent bands; masking dominates for real audio). */
#define FS_SINE_BIN_ENERGY (0.25f * 1024.0f * 1024.0f / 4.0f)
/* SPL of a full-scale sine (≈ 16-bit PCM dynamic range). */
#define FS_SPL 96.0f

void aac_psycho_init(AacPsychoState* s, int sr, int fs) {
  memset(s, 0, sizeof(*s));
  s->sample_rate = sr;
  s->frame_size = fs;
  s->preecho_factor = 0.3f;
  for (int i = 0; i < AAC_NUM_SAMPLE_RATES; i++) {
    if (aac_sample_rates[i] == sr) {
      s->rate_index = i;
      break;
    }
  }
  s->num_bands = aac_num_sfb_long[s->rate_index];
  s->threshold_scale = 1.0f;
  /* threshold_previous is compared against in aac_psycho_analyze.  Zero would
   * collapse the first frame's thresholds (min(thr, 0) = 0), so start high
   * enough that the pre-echo clamp is inactive on the first frame. */
  for (int b = 0; b < 49; b++) {
    s->threshold_previous[b] = 1e30f;
  }
}

void aac_psycho_analyze(AacPsychoState* s, const float* spec, int nb, const int* sfb) {
  const float line_freq = (float)s->sample_rate / (2.0f * (float)s->frame_size);

  /* 1. Per-band energy, initial threshold (-29 dB SNR), Bark value, ATH floor. */
  float bark[49];
  float ath[49];
  for (int b = 0; b < nb; b++) {
    float e = 0.0f;
    for (int i = sfb[b]; i < sfb[b + 1]; i++) {
      e += spec[i] * spec[i];
    }
    s->bands[b].energy = e;
    s->bands[b].spreaded_energy = e;
    /* Initial threshold: energy × 10^(-29/10) × threshold_scale.  The scale
     * lets the encoder trade quantization noise for bitrate (see
     * aac_encoder_state_create). */
    s->bands[b].threshold = e * 0.001258925f * s->threshold_scale;

    float fcenter = (float)(sfb[b] + sfb[b + 1]) * 0.5f * line_freq;
    bark[b] = calc_bark(fcenter);
    /* ATH (dB SPL) → dBFS → energy in the encoder's MDCT scale. */
    float dbfs = ath_db(fcenter) - FS_SPL;
    ath[b] = FS_SINE_BIN_ENERGY * powf(10.0f, dbfs / 10.0f);
  }

  /* 2. Forward spreading (low → high frequency): uphill 1.5 dB/Bark on
     threshold, 2.0 dB/Bark on energy. */
  for (int b = 1; b < nb; b++) {
    float db = bark[b] - bark[b - 1];
    float th_slope = powf(10.0f, -db * 1.5f / 10.0f);
    float en_slope = powf(10.0f, -db * 2.0f / 10.0f);
    s->bands[b].threshold = fmaxf(s->bands[b].threshold, s->bands[b - 1].threshold * th_slope);
    s->bands[b].spreaded_energy =
        fmaxf(s->bands[b].spreaded_energy, s->bands[b - 1].spreaded_energy * en_slope);
  }
  /* 3. Backward spreading (high → low frequency): downhill 3.0 dB/Bark. */
  for (int b = nb - 2; b >= 0; b--) {
    float db = bark[b + 1] - bark[b];
    float th_slope = powf(10.0f, -db * 3.0f / 10.0f);
    s->bands[b].threshold = fmaxf(s->bands[b].threshold, s->bands[b + 1].threshold * th_slope);
  }

  /* 4. ATH floor, pre-echo limiting, publish. */
  s->total_pe = 0;
  for (int b = 0; b < nb; b++) {
    float thr = fmaxf(s->bands[b].threshold, ath[b]);
    /* Pre-echo: keep threshold from dropping too fast frame-to-frame, but allow
     * it to rise instantly on transients.  Skip the clamp for the first frame
     * (threshold_previous sentinel is -1); 3GPP 26.403 5.4.2.5 does not apply
     * pre-echo control when no previous frame exists. */
    if (s->threshold_previous[b] >= 0.0f) {
      thr = fmaxf(0.01f * thr, fminf(thr, 2.0f * s->threshold_previous[b]));
    }
    s->threshold_previous[b] = thr;
    s->bands[b].threshold = thr;
    s->thresholds[b] = thr;
    if (s->bands[b].energy > thr) {
      s->total_pe += 6.0f * log2f(s->bands[b].energy / thr);
    }
  }
}

float aac_psycho_get_pe(const AacPsychoState* s) { return s->total_pe; }
const float* aac_psycho_get_thresholds(const AacPsychoState* s) { return s->thresholds; }
