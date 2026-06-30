#include "psycho.h"

#include <cmath>
#include <cstring>

#include "aac_tables.h"

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
  for (int i = 0; i < s->num_bands; i++) {
    for (int j = 0; j < s->num_bands; j++) {
      float d = 1.0f * (j - i);
      s->spreading[i][j] = powf(10.0f, (d >= 0 ? -27.0f * d : 27.0f * d - 6.0f) / 10.0f);
    }
  }
}

void aac_psycho_analyze(AacPsychoState* s, const float* spec, int nb, const int* sfb) {
  s->total_pe = 0;
  for (int b = 0; b < nb; b++) {
    float e = 0;
    for (int i = sfb[b]; i < sfb[b + 1]; i++) {
      e += spec[i] * spec[i];
    }
    s->bands[b].energy = e / (sfb[b + 1] - sfb[b]);
    float sfm = (s->bands[b].energy > 0 && s->prev_energy[b] > 0)
                    ? s->bands[b].energy / (s->prev_energy[b] + 1e-20f)
                    : 1.0f;
    s->bands[b].tonality = (sfm < 0.5f) ? 1.0f : 0.0f;
    s->prev_energy[b] = s->bands[b].energy;
  }
  for (int b = 0; b < nb; b++) {
    float sp = 0;
    for (int j = 0; j < nb; j++) {
      sp += s->bands[j].energy * s->spreading[j][b];
    }
    s->bands[b].spreaded_energy = sp;
  }
  for (int b = 0; b < nb; b++) {
    float thr = s->bands[b].spreaded_energy * powf(10.0f, -s->bands[b].tonality * 29.0f / 10.0f);
    float freq = (float)(sfb[b]) * s->sample_rate / (float)(s->frame_size) / 1000.0f;
    float ath = powf(10.0f, (-5.0f - 3.64f * powf(freq, 0.8f)) / 10.0f);
    thr = fmaxf(thr, ath);
    thr = fmaxf(thr, s->preecho_factor * s->threshold_previous[b]);
    s->threshold_previous[b] = thr;
    s->bands[b].threshold = thr;
    s->thresholds[b] = thr;
    s->bands[b].pe = (s->bands[b].energy > thr) ? 6.0f * log2f(s->bands[b].energy / thr) : 0;
    s->total_pe += s->bands[b].pe;
  }
}

float aac_psycho_get_pe(const AacPsychoState* s) { return s->total_pe; }
const float* aac_psycho_get_thresholds(const AacPsychoState* s) { return s->thresholds; }
