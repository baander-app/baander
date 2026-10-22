#include "psycho.h"

#include <cmath>
#include <cstring>

/* Psychoacoustic model after 3GPP TS 26.403 section 5.4: an initial
 * signal-to-mask ratio per band, threshold spreading across neighbouring
 * bands on the Bark scale, a PCM-resolution floor, pre-echo control
 * between long frames and the spread energy used for hole avoidance.  The
 * encoder scales the thresholds to meet its bit budget, so only their shape
 * matters here. */

namespace {

constexpr float kInitialSmr = 0.001258925f; /* -29 dB */
/* Threshold spreading slopes in dB per Bark. */
constexpr float kThrSpreadHigh = 15.0f; /* towards higher frequencies */
constexpr float kThrSpreadLow = 30.0f;  /* towards lower frequencies */
/* Energy spreading slopes in dB per Bark. */
constexpr float kEnSpreadHighLong = 20.0f;
constexpr float kEnSpreadHighLowRate = 15.0f; /* long windows below 22 kbit/s per channel */
constexpr float kEnSpreadHighShort = 15.0f;
constexpr float kEnSpreadLowLong = 30.0f;
constexpr float kEnSpreadLowShort = 20.0f;
constexpr float kPreEchoRise = 2.0f;
constexpr float kPreEchoMin = 0.01f;
constexpr float kInputScale = 32768.0f;

float bark(float f) {
  return 13.3f * atanf(0.00076f * f) + 3.5f * atanf((f / 7500.0f) * (f / 7500.0f));
}

/* Floor of the thresholds: the quantization noise of 16-bit PCM, as in
 * libfdk-aac (psy_configuration.cpp, "minimum PCM resolution").  Uniform
 * noise of one LSB has variance 1/12 per sample and lands with the window
 * length as gain in each spectral line. */
float pcm_floor_energy(int lines, int window_len) {
  return (float)lines * (float)window_len / 12.0f;
}

float db_per_bark_to_factor(float db_per_bark, float width) {
  return powf(10.0f, -db_per_bark * width / 10.0f);
}

}  // namespace

void aac_psy_init(AacPsyChannel* p) {
  memset(p, 0, sizeof(*p));
  p->prev_long = 0;
}

void aac_psy_thresholds(AacPsyChannel* p, int sample_rate, int channel_bitrate,
                        const AacIcsInfo* ics, const float* coef, float* energy, float* thr,
                        float* spread_energy) {
  const int is_short = ics->num_windows == 8;
  const int nswb = ics->num_swb;
  const int* off = ics->swb_offset;
  const int window_len = is_short ? 256 : 2048;
  const float line_hz = (float)sample_rate / (float)window_len;
  const float en_high = is_short                   ? kEnSpreadHighShort
                        : channel_bitrate <= 22000 ? kEnSpreadHighLowRate
                                                   : kEnSpreadHighLong;
  const float en_low = is_short ? kEnSpreadLowShort : kEnSpreadLowLong;

  float thr_hi[AAC_MAX_SFB_LONG], thr_lo[AAC_MAX_SFB_LONG];
  float en_hi[AAC_MAX_SFB_LONG], en_lo[AAC_MAX_SFB_LONG];
  float ath[AAC_MAX_SFB_LONG];
  float prev_bark = 0.0f;
  for (int b = 0; b < nswb; b++) {
    const float fc = 0.5f * (float)(off[b] + off[b + 1]) * line_hz;
    const float bk = bark(fc);
    const float width = b ? bk - prev_bark : 0.0f;
    thr_hi[b] = db_per_bark_to_factor(kThrSpreadHigh, width);
    thr_lo[b] = db_per_bark_to_factor(kThrSpreadLow, width);
    en_hi[b] = db_per_bark_to_factor(en_high, width);
    en_lo[b] = db_per_bark_to_factor(en_low, width);
    ath[b] = pcm_floor_energy(off[b + 1] - off[b], window_len);
    prev_bark = bk;
  }

  int w0 = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    float* e = energy + g * nswb;
    float* t = thr + g * nswb;
    float* se = spread_energy + g * nswb;
    for (int b = 0; b < nswb; b++) {
      float sum = 0.0f;
      for (int w = w0; w < w0 + ics->group_len[g]; w++) {
        const float* x = coef + w * 128;
        for (int i = off[b]; i < off[b + 1]; i++) {
          sum += x[i] * x[i];
        }
      }
      e[b] = sum;
      t[b] = sum * kInitialSmr;
      se[b] = sum;
    }
    for (int b = 1; b < nswb; b++) {
      t[b] = fmaxf(t[b], t[b - 1] * thr_hi[b]);
      se[b] = fmaxf(se[b], se[b - 1] * en_hi[b]);
    }
    for (int b = nswb - 2; b >= 0; b--) {
      t[b] = fmaxf(t[b], t[b + 1] * thr_lo[b + 1]);
      se[b] = fmaxf(se[b], se[b + 1] * en_lo[b + 1]);
    }
    for (int b = 0; b < nswb; b++) {
      t[b] = fmaxf(t[b], ath[b]);
      if (!is_short && p->prev_long) {
        t[b] = fmaxf(kPreEchoMin * t[b], fminf(t[b], kPreEchoRise * p->prev_thr[b]));
      }
    }
    if (!is_short) {
      memcpy(p->prev_thr, t, (size_t)nswb * sizeof(float));
    }
    w0 += ics->group_len[g];
  }
  p->prev_long = !is_short;
}

void aac_psy_subblock_energies(const float* x, int start, int count, float* sub_energy) {
  /* The high-pass of libfdk-aac block_switch.cpp, run from rest at the
   * start of the region. */
  float y = 0.0f;
  float prev = x[start - 1];
  for (int j = 0; j < count / 128; j++) {
    const float* s = x + start + j * 128;
    float e = 0.0f;
    for (int i = 0; i < 128; i++) {
      y = 0.7548f * (s[i] - prev) + 0.5095f * y;
      prev = s[i];
      e += y * y;
    }
    sub_energy[j] = e;
  }
}

int aac_psy_attack(const float* x, int start, int count) {
  /* Sub-blocks below about -51 dBFS RMS never count as attacks, and an
   * attack must exceed ten times the smoothed energy of what came before
   * (libfdk-aac block_switch.cpp). */
  const float min_energy = 128.0f * (kInputScale * 0.0028f) * (kInputScale * 0.0028f);
  const float ratio = 10.0f;
  float e[64];
  const int history = 3;
  const int n = count / 128 + history;
  aac_psy_subblock_energies(x, start - history * 128, n * 128, e);
  float acc = (e[0] + e[1] + e[2]) / 3.0f;
  int attack = 0;
  for (int j = history; j < n; j++) {
    if (e[j] > min_energy && e[j] > ratio * acc) {
      attack = j - history + 1;
    }
    acc = 0.7f * acc + 0.3f * e[j];
  }
  return attack;
}
