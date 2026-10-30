/* The MPEG-D DRC gain sets of the xHE-AAC encoder (ISO/IEC 23003-4):
 * the writers of uniDrcConfig(), uniDrcGain() and loudnessInfoSet()
 * against the decoder's parsers, the gain generator on a signal that
 * alternates between loud and quiet passages (the direction and bounds
 * of its gains, its smoothing, the limiter's ceiling, the loudness range
 * of its output), and whole streams with the gain sets on and off
 * through the public API and the decoder's DRC requests.
 *
 * `test_drc_gen --write-streams DIR` writes the committed stream. */
#include <cmath>
#include <cstdio>
#include <cstring>
#include <string>
#include <vector>

#include "aac.h"
#include "asc.h"
#include "bitstream.h"
#include "drc_gen.h"
#include "encoder.h"
#include "test_util.h"
#include "unidrc.h"
#include "usac.h"

/* The committed stream is the release build's bytes; a sanitizer build
 * compares its decode instead. */
#if defined(__SANITIZE_ADDRESS__) || defined(__SANITIZE_UNDEFINED__)
#define BAAC_SANITIZED 1
#elif defined(__has_feature)
#if __has_feature(address_sanitizer) || __has_feature(undefined_behavior_sanitizer)
#define BAAC_SANITIZED 1
#endif
#endif
#ifndef BAAC_SANITIZED
#define BAAC_SANITIZED 0
#endif

namespace {

constexpr int kRate = 48000;
constexpr int kSbrFrame = 2048;
constexpr const char* kCommittedName = "usac_enc_drc_cpe_64k.loas";

/* ── signals ───────────────────────────────────────────────────── */

/* The passages of the test signal: a medium level, loud passages 10 dB
 * above it and quiet ones 12 dB below it.  The programme's integrated
 * loudness lands a few LU under the loud passages (the quiet ones fall
 * under its relative gate), so the curve cuts the loud passages and
 * boosts the quiet ones. */
enum Passage { kMedium, kLoud, kQuiet };
const Passage kPattern[] = {kMedium, kLoud, kMedium, kQuiet, kMedium, kLoud, kQuiet};
constexpr int kPassages = (int)(sizeof(kPattern) / sizeof(kPattern[0]));

/* Wideband harmonics and noise in the passages of kPattern, `seconds`
 * each, with a 2 ms burst at 3 kHz peaking at -2.5 dBFS every 370 ms
 * in the loud and medium passages, for the peak bounds. */
std::vector<float> gen_alternation(int passages, double seconds, int channels) {
  const int per = (int)(seconds * kRate);
  std::vector<float> pcm = gen_wideband(per * passages, kRate, channels);
  const float level_db[] = {-6.0f, 4.0f, -18.0f};
  for (int p = 0; p < passages; p++) {
    const float g = powf(10.0f, level_db[kPattern[p % kPassages]] / 20.0f);
    for (size_t i = (size_t)p * per * channels; i < (size_t)(p + 1) * per * channels; i++) {
      pcm[i] *= g;
    }
  }
  const int burst = kRate / 500, every = kRate * 37 / 100;
  for (int start = every / 2; start + burst < per * passages; start += every) {
    if (kPattern[(start / per) % kPassages] == kQuiet) {
      continue;
    }
    for (int i = 0; i < burst; i++) {
      const float w = sinf((float)M_PI * (float)i / (float)burst);
      const float v = 0.75f * w * w * sinf(2.0f * (float)M_PI * 3000.0f * (float)i / kRate);
      for (int c = 0; c < channels; c++) {
        pcm[(size_t)(start + i) * channels + c] += v;
      }
    }
  }
  return pcm;
}

const char* passage_name(int p) {
  return kPattern[p] == kLoud ? "loud" : kPattern[p] == kQuiet ? "quiet" : "medium";
}

AacLoudnessInfo measure(const std::vector<float>& pcm, int channels) {
  AacLoudnessInfo info;
  memset(&info, 0, sizeof(info));
  AacLoudnessMeter* m = aac_loudness_create(kRate, channels);
  CHECK(m != nullptr, "meter");
  if (m) {
    aac_loudness_feed(m, pcm.data(), (int)(pcm.size() / channels));
    CHECK(aac_loudness_result(m, &info) == AAC_OK, "loudness of the signal");
    aac_loudness_destroy(m);
  }
  return info;
}

/* ── the writers against the parsers ───────────────────────────── */

void test_config_round_trip() {
  for (int channels : {1, 2, 6}) {
    AacUniDrcConfig c;
    aac_drc_gen_config(&c, channels);
    uint8_t buf[512];
    memset(buf, 0, sizeof(buf));
    AacBitWriter w;
    aac_bitwriter_init(&w, buf, (int)sizeof(buf));
    const int bits = aac_drc_gen_write_config(&w, &c);
    CHECK(bits > 0, "%d channels: config written (%d)", channels, bits);
    if (bits <= 0) {
      continue;
    }
    AacBitReader r;
    aac_bitreader_init(&r, buf, (bits + 7) / 8);
    AacUniDrcConfig p;
    memset(&p, 0, sizeof(p));
    CHECK(aac_unidrc_parse_config(&r, &p) == AAC_OK, "%d channels: the parser reads it", channels);
    CHECK(r.pos == bits, "%d channels: %d bits read of %d", channels, r.pos, bits);
    CHECK(p.base_channel_count == channels && p.coefficients_count == 1 &&
              p.instructions_count == AAC_DRC_GEN_SETS && p.downmix_count == 0,
          "%d channels: layout %d, %d coefficients, %d instructions", channels,
          p.base_channel_count, p.coefficients_count, p.instructions_count);
    if (p.coefficients_count != 1 || p.instructions_count != AAC_DRC_GEN_SETS) {
      continue;
    }
    const AacUniDrcCoefficients& co = p.coefficients[0];
    CHECK(co.drc_location == 1 && co.gain_set_count == 2, "%d channels: location %d, %d gain sets",
          channels, co.drc_location, co.gain_set_count);
    for (int s = 0; s < co.gain_set_count && s < 2; s++) {
      const AacUniDrcGainSet& gs = co.gain_set[s];
      CHECK(gs.gain_coding_profile == 0 && gs.gain_interpolation_type == 1 && gs.full_frame == 0 &&
                gs.time_alignment == 0 && gs.band_count == 1 && gs.band[0].gain_sequence_index == s,
            "%d channels: gain set %d (profile %d, interpolation %d, bands %d, sequence %d)",
            channels, s, gs.gain_coding_profile, gs.gain_interpolation_type, gs.band_count,
            gs.band[0].gain_sequence_index);
    }
    const AacUniDrcInstructions& general = p.instructions[0];
    const AacUniDrcInstructions& night = p.instructions[1];
    CHECK(general.drc_set_id == AAC_DRC_GEN_SET_GENERAL &&
              general.drc_set_effect == AAC_UNIDRC_EFFECT_GENERAL &&
              general.gain_set_index[0] == 0 && general.downmix_id == 0,
          "%d channels: the general set (id %d, effect 0x%x)", channels, general.drc_set_id,
          general.drc_set_effect);
    CHECK(night.drc_set_id == AAC_DRC_GEN_SET_NIGHT &&
              night.drc_set_effect == AAC_UNIDRC_EFFECT_NIGHT && night.gain_set_index[0] == 1 &&
              night.limiter_peak_target_present &&
              fabsf(night.limiter_peak_target_db - AAC_DRC_GEN_PEAK_TARGET_DB) < 0.01f,
          "%d channels: the night set (id %d, effect 0x%x, limiter %.2f dB)", channels,
          night.drc_set_id, night.drc_set_effect, night.limiter_peak_target_db);
  }
}

/* Random node lists the regular profile can carry: times on the grid,
 * gain steps within the delta table, the last node at the frame end or
 * before it. */
void random_sequence(unsigned* seed, int steps, int grid, AacUniDrcSequence* s) {
  auto next = [seed](int n) {
    *seed = *seed * 1664525u + 1013904223u;
    return (int)((*seed >> 8) % (unsigned)n);
  };
  s->node_count = 0;
  int code = next(400) - 200;
  int t = 0;
  const int mode = next(4);
  while (t < steps && s->node_count < AAC_UNIDRC_MAX_NODES) {
    /* gaps of one step, a few, or long ones that need the escape */
    const int gap = mode == 0 ? 1 : mode == 1 ? 1 + next(4) : 1 + next(steps);
    t = std::min(steps, t + gap);
    if (s->node_count > 0) {
      code = std::clamp(code + next(25) - 16, -255, 255);
    }
    AacUniDrcNode* n = &s->node[s->node_count++];
    n->time = t * grid - 1;
    n->gain_db = (float)code / 8.0f;
    n->slope = 0.0f;
    if (mode == 3 && next(6) == 0) {
      break; /* ends before the frame end */
    }
  }
}

void test_gain_round_trip() {
  AacUniDrcConfig c;
  aac_drc_gen_config(&c, 2);
  unsigned seed = 4242u;
  int frames = 0, bad = 0;
  for (int frame_length : {2048, 1024}) {
    for (int rate : {48000, 44100, 32000}) {
      const int grid = aac_unidrc_time_delta_min(&c.coefficients[0].gain_set[0], rate);
      const int steps = frame_length / grid;
      for (int k = 0; k < 300; k++) {
        AacUniDrcGainFrame g;
        memset(&g, 0, sizeof(g));
        g.sequence_count = 2;
        random_sequence(&seed, steps, grid, &g.seq[0]);
        random_sequence(&seed, steps, grid, &g.seq[1]);
        uint8_t buf[1024];
        memset(buf, 0, sizeof(buf));
        AacBitWriter w;
        aac_bitwriter_init(&w, buf, (int)sizeof(buf));
        const int bits = aac_drc_gen_write_gain(&w, &c, &g, frame_length, rate);
        frames++;
        if (bits <= 0) {
          bad++;
          continue;
        }
        AacBitReader r;
        aac_bitreader_init(&r, buf, (bits + 7) / 8);
        AacUniDrcGainFrame p;
        memset(&p, 0, sizeof(p));
        const int err = aac_unidrc_parse_gain(&c, &r, bits, frame_length, rate, &p);
        bool same = err == AAC_OK && r.pos == bits && p.sequence_count == 2;
        for (int s = 0; s < 2 && same; s++) {
          same = p.seq[s].node_count == g.seq[s].node_count;
          for (int i = 0; i < g.seq[s].node_count && same; i++) {
            same = p.seq[s].node[i].time == g.seq[s].node[i].time &&
                   fabsf(p.seq[s].node[i].gain_db - g.seq[s].node[i].gain_db) < 1e-4f;
          }
        }
        if (!same) {
          bad++;
        }
      }
    }
  }
  CHECK(bad == 0, "uniDrcGain round trips: %d of %d frames differ", bad, frames);
  /* What the syntax cannot carry is refused. */
  const int grid = 32;
  AacUniDrcGainFrame g;
  memset(&g, 0, sizeof(g));
  g.sequence_count = 2;
  for (int s = 0; s < 2; s++) {
    g.seq[s].node_count = 2;
    g.seq[s].node[0] = {grid - 1, 0.0f, 0.0f};
    g.seq[s].node[1] = {kSbrFrame - 1, 0.0f, 0.0f};
  }
  g.seq[1].node[1].gain_db = 5.0f; /* a step beyond the delta table */
  uint8_t buf[256];
  AacBitWriter w;
  aac_bitwriter_init(&w, buf, (int)sizeof(buf));
  CHECK(aac_drc_gen_write_gain(&w, &c, &g, kSbrFrame, kRate) == AAC_ERR_INVALID_ARG,
        "a gain step beyond the table is refused");
  g.seq[1].node[1].gain_db = 0.0f;
  g.seq[1].node[0].time = grid; /* off the grid */
  aac_bitwriter_init(&w, buf, (int)sizeof(buf));
  CHECK(aac_drc_gen_write_gain(&w, &c, &g, kSbrFrame, kRate) == AAC_ERR_INVALID_ARG,
        "a node off the grid is refused");
}

void test_loudness_round_trip() {
  AacLoudnessInfo info[3];
  memset(info, 0, sizeof(info));
  const float lkfs[3] = {-16.25f, -17.5f, -18.0f};
  const float peak[3] = {-0.5f, -1.0f, -3.25f};
  for (int i = 0; i < 3; i++) {
    info[i].integrated_lkfs = lkfs[i];
    info[i].sample_peak_dbfs = peak[i] - 0.25f;
    info[i].true_peak_dbtp = peak[i];
    info[i].has_true_peak = 1;
    info[i].range_lu = 12.0f;
    info[i].has_range = i == 0;
  }
  const int ids[3] = {0, AAC_DRC_GEN_SET_GENERAL, AAC_DRC_GEN_SET_NIGHT};
  for (int n : {0, 1, 3}) {
    uint8_t buf[256];
    memset(buf, 0, sizeof(buf));
    AacBitWriter w;
    aac_bitwriter_init(&w, buf, (int)sizeof(buf));
    const int bits = aac_drc_gen_write_loudness(&w, info, ids, n);
    CHECK(bits > 0, "%d entries written", n);
    AacBitReader r;
    aac_bitreader_init(&r, buf, (bits + 7) / 8);
    AacUniDrcLoudnessSet set;
    memset(&set, 0, sizeof(set));
    CHECK(aac_unidrc_parse_loudness(&r, &set) == AAC_OK && r.pos == bits,
          "%d entries: the parser reads %d of %d bits", n, r.pos, bits);
    CHECK(set.album_count == 0 && set.count == n, "%d entries read back as %d", n, set.count);
    for (int i = 0; i < n && i < set.count; i++) {
      const AacUniDrcLoudnessInfo& li = set.info[i];
      float program = 0.0f;
      bool has_program = false;
      for (int k = 0; k < li.measurement_count; k++) {
        if (li.m[k].method == 1) {
          program = li.m[k].value;
          has_program = true;
        }
      }
      CHECK(li.drc_set_id == ids[i] && li.downmix_id == 0 && li.true_peak_present &&
                fabsf(li.true_peak_db - peak[i]) < 0.04f && has_program &&
                fabsf(program - lkfs[i]) < 0.13f,
            "entry %d: drcSetId %d, true peak %.2f, program loudness %.2f", i, li.drc_set_id,
            li.true_peak_db, program);
    }
  }
}

void test_static_curve() {
  const float anchor = -20.0f;
  const int range = 10;
  CHECK(aac_drc_gen_static_gain(anchor, anchor, range, 3.0f) == 0.0f &&
            aac_drc_gen_static_gain(anchor + 2.4f, anchor, range, 3.0f) == 0.0f &&
            aac_drc_gen_static_gain(anchor - 2.4f, anchor, range, 3.0f) == 0.0f,
        "no gain in the dead band");
  const float cut = aac_drc_gen_static_gain(anchor + 8.5f, anchor, range, 3.0f);
  const float boost = aac_drc_gen_static_gain(anchor - 8.5f, anchor, range, 3.0f);
  CHECK(fabsf(cut + 4.0f) < 1e-3f && fabsf(boost - 4.0f) < 1e-3f,
        "6 LU beyond the band at 3:1: cut %.2f, boost %.2f dB", cut, boost);
  CHECK(aac_drc_gen_static_gain(anchor - 25.0f, anchor, range, 8.0f) <= 12.0f &&
            aac_drc_gen_static_gain(anchor + 60.0f, anchor, range, 8.0f) >= -24.0f,
        "boost and cut within their limits");
  CHECK(aac_drc_gen_static_gain(anchor - 45.0f, anchor, range, 8.0f) == 0.0f &&
            aac_drc_gen_static_gain(anchor - 35.0f, anchor, range, 8.0f) <
                aac_drc_gen_static_gain(anchor - 25.0f, anchor, range, 8.0f),
        "the boost fades out far below the anchor");
  CHECK(aac_drc_gen_static_gain(anchor + 10.0f, anchor, range, 1.0f) == 0.0f,
        "a ratio of 1 leaves the level alone");
}

/* ── the generator ─────────────────────────────────────────────── */

/* The decoder's linear interpolation of one sequence over a frame from
 * the previous frame's last gain. */
void interpolate(float prev_db, const AacUniDrcSequence* s, std::vector<float>* out, int n) {
  int t0 = -1;
  float g0 = powf(10.0f, prev_db / 20.0f);
  int k = 0;
  for (int t = 0; t < n; t++) {
    while (k < s->node_count && s->node[k].time < t) {
      t0 = s->node[k].time;
      g0 = powf(10.0f, s->node[k].gain_db / 20.0f);
      k++;
    }
    float g = g0;
    if (k < s->node_count) {
      const float g1 = powf(10.0f, s->node[k].gain_db / 20.0f);
      g = g0 + (g1 - g0) * (float)(t - t0) / (float)(s->node[k].time - t0);
    }
    out->push_back(20.0f * log10f(g));
  }
}

struct Curves {
  std::vector<float> gain[2]; /* dB per input sample, from input sample 0 */
  AacLoudnessInfo set[2];
  float anchor, ratio;
};

/* Runs the generator over `pcm` as the encoder would (`delay` input
 * samples to the decoded output), round-trips every frame's payload
 * through the parser, and lays the decoded gains of both sequences on
 * the input's timeline. */
Curves run_generator(const std::vector<float>& pcm, int channels, int delay, int frame_length,
                     const AacLoudnessInfo* programme) {
  Curves out;
  memset(out.set, 0, sizeof(out.set));
  AacDrcGenParams p;
  memset(&p, 0, sizeof(p));
  p.sample_rate = kRate;
  p.channels = channels;
  p.frame_length = frame_length;
  p.delay = delay;
  p.target_range_lu = AAC_DRC_GEN_DEFAULT_RANGE_LU;
  if (programme) {
    p.has_anchor = 1;
    p.anchor_lkfs = programme->integrated_lkfs;
    p.has_range = programme->has_range;
    p.range_lu = programme->range_lu;
  }
  p.measure = 1;
  AacDrcGen* g = aac_drc_gen_create(&p);
  CHECK(g != nullptr, "generator");
  if (!g) {
    return out;
  }
  AacUniDrcConfig c;
  aac_drc_gen_config(&c, channels);
  const size_t frames = (pcm.size() / channels + delay) / frame_length + 2;
  std::vector<float> block((size_t)frame_length * channels);
  float prev[2] = {0.0f, 0.0f};
  int bad = 0, largest = 0;
  /* the gains the k-th frame carries are those of output frame k + 1 */
  const long long lead = (long long)frame_length - delay;
  for (int s = 0; s < 2; s++) {
    out.gain[s].assign((size_t)std::max(0LL, lead + frame_length), 0.0f);
  }
  for (size_t k = 0; k < frames; k++) {
    std::fill(block.begin(), block.end(), 0.0f);
    const size_t at = k * block.size();
    if (at < pcm.size()) {
      memcpy(block.data(), pcm.data() + at,
             std::min(block.size(), pcm.size() - at) * sizeof(float));
    }
    AacUniDrcGainFrame f;
    CHECK(aac_drc_gen_frame(g, block.data(), &f) == AAC_OK, "frame %zu", k);
    uint8_t buf[AAC_DRC_GEN_MAX_PAYLOAD_BYTES];
    memset(buf, 0, sizeof(buf));
    AacBitWriter w;
    aac_bitwriter_init(&w, buf, (int)sizeof(buf));
    const int bits = aac_drc_gen_write_gain(&w, &c, &f, frame_length, kRate);
    AacUniDrcGainFrame parsed;
    AacBitReader r;
    aac_bitreader_init(&r, buf, (int)sizeof(buf));
    if (bits <= 0 || aac_unidrc_parse_gain(&c, &r, bits, frame_length, kRate, &parsed) != AAC_OK ||
        r.pos != bits) {
      bad++;
      continue;
    }
    largest = std::max(largest, (bits + 7) / 8);
    for (int s = 0; s < 2; s++) {
      std::vector<float> curve;
      interpolate(prev[s], &parsed.seq[s], &curve, frame_length);
      prev[s] = parsed.seq[s].node[parsed.seq[s].node_count - 1].gain_db;
      /* output frame k + 1 starts at input sample (k + 1) * N - delay */
      const long long start = (long long)(k + 1) * frame_length - delay;
      for (int t = 0; t < frame_length; t++) {
        if (start + t >= 0 && start + t < (long long)out.gain[s].size()) {
          out.gain[s][(size_t)(start + t)] = curve[(size_t)t];
        } else if (start + t >= (long long)out.gain[s].size()) {
          out.gain[s].push_back(curve[(size_t)t]);
        }
      }
    }
  }
  CHECK(bad == 0, "%d of %zu generated frames failed the parser", bad, frames);
  CHECK(largest <= AAC_DRC_GEN_MAX_PAYLOAD_BYTES, "largest payload %d bytes", largest);
  for (int s = 0; s < 2; s++) {
    CHECK(aac_drc_gen_loudness(g, s + 1, &out.set[s]) == AAC_OK, "set %d metered", s + 1);
  }
  CHECK(aac_drc_gen_loudness(g, 3, &out.set[0]) == AAC_ERR_INVALID_ARG, "set 3 refused");
  aac_drc_gen_curve(g, &out.anchor, &out.ratio);
  aac_drc_gen_destroy(g);
  return out;
}

float mean(const std::vector<float>& v, size_t from, size_t to) {
  double sum = 0.0;
  to = std::min(to, v.size());
  for (size_t i = from; i < to; i++) {
    sum += v[i];
  }
  return to > from ? (float)(sum / (double)(to - from)) : 0.0f;
}

void test_generator() {
  constexpr int kChannels = 2;
  constexpr double kPassage = 3.0;
  const std::vector<float> pcm = gen_alternation(kPassages, kPassage, kChannels);
  const AacLoudnessInfo programme = measure(pcm, kChannels);
  const size_t per = (size_t)(kPassage * kRate);
  for (int pass = 0; pass < 2; pass++) {
    const char* name = pass ? "one-pass" : "two-pass";
    const Curves c = run_generator(pcm, kChannels, AAC_ENCODER_SBR_DELAY, kSbrFrame,
                                   pass ? nullptr : &programme);
    if (c.gain[0].size() < pcm.size() / kChannels) {
      CHECK(false, "%s: gains cover the input", name);
      continue;
    }
    if (!pass) {
      CHECK(fabsf(c.anchor - programme.integrated_lkfs) < 1e-3f && c.ratio > 1.0f,
            "%s: anchored on the programme (%.2f, ratio %.2f)", name, c.anchor, c.ratio);
    } else {
      CHECK(fabsf(c.anchor - programme.integrated_lkfs) < 3.0f,
            "%s: the running anchor %.2f near the programme's %.2f", name, c.anchor,
            programme.integrated_lkfs);
    }
    /* Boost in the quiet passages, cut in the loud ones, past the first
     * one, where a one-pass anchor is still settling. */
    for (int p = 1; p < kPassages; p++) {
      const float g = mean(c.gain[0], p * per + per / 2, (p + 1) * per);
      CHECK(kPattern[p] == kQuiet  ? g > 2.0f
            : kPattern[p] == kLoud ? g < -0.3f
                                   : true,
            "%s: passage %d (%s) gain %.2f dB", name, p, passage_name(p), g);
    }
    float lowest = 0.0f, highest = 0.0f, night_over = -100.0f;
    for (size_t i = 0; i < pcm.size() / kChannels; i++) {
      lowest = std::min(lowest, c.gain[0][i]);
      highest = std::max(highest, c.gain[0][i]);
      night_over = std::max(night_over, c.gain[1][i] - c.gain[0][i]);
    }
    CHECK(lowest >= -24.5f && highest <= 12.5f, "%s: gains within %.2f .. %.2f dB", name, lowest,
          highest);
    CHECK(night_over <= 0.4f, "%s: the night set never above the general one (%.2f dB)", name,
          night_over);
    /* The release: the gain rises over seconds, not at once, where the
     * level falls; the attack: the gain falls within the level window and
     * the attack where it rises. */
    for (int p = 2; p < kPassages; p++) {
      const size_t at = p * per;
      const float before = c.gain[0][at - kRate / 10];
      const float settled = c.gain[0][at + per - kRate / 10];
      const float soon = c.gain[0][at + kRate / 10];
      const float later = c.gain[0][at + kRate * 6 / 10];
      if (fabsf(settled - before) < 1.0f) {
        continue;
      }
      if (settled > before) {
        CHECK(soon - before < 0.3f * (settled - before),
              "%s: the release at %d: %.2f -> %.2f dB after 100 ms, %.2f settled", name, p, before,
              soon, settled);
      } else {
        CHECK(before - later > 0.7f * (before - settled),
              "%s: the attack at %d: %.2f -> %.2f dB after 600 ms, %.2f settled", name, p, before,
              later, settled);
      }
    }
    /* The ceilings: the general set's boost never takes a sample above
     * -1 dBFS; the night set's output, normalised from the anchor to
     * -16 LUFS, never above -1 dBFS either (sample peaks, which the true
     * peak bounds), once the first passage has set the anchor. */
    const float norm = AAC_DRC_GEN_REFERENCE_LKFS - c.anchor;
    float general_peak = -200.0f, night_peak = -200.0f, input_peak = -200.0f;
    for (size_t i = per * kChannels; i < pcm.size(); i++) {
      const float x = 20.0f * log10f(fabsf(pcm[i]) + 1e-12f);
      input_peak = std::max(input_peak, x);
      if (c.gain[0][i / kChannels] > 0.0f) {
        general_peak = std::max(general_peak, x + c.gain[0][i / kChannels]);
      }
      night_peak = std::max(night_peak, x + c.gain[1][i / kChannels] + norm);
    }
    CHECK(general_peak <= AAC_DRC_GEN_PEAK_TARGET_DB + 0.3f,
          "%s: the general set's boosted peak %.2f dBFS", name, general_peak);
    /* a one-pass ceiling follows the anchor as it was when the gains
     * went out */
    CHECK(night_peak <= AAC_DRC_GEN_PEAK_TARGET_DB + (pass ? 0.5f : 0.3f),
          "%s: the night set's peak after normalisation %.2f dBFS (input %.2f, gain %.2f)", name,
          night_peak, input_peak, norm);
    /* The loudness range moves to the target. */
    for (int s = 0; s < 2; s++) {
      const float before = programme.range_lu, after = c.set[s].range_lu;
      CHECK(c.set[s].has_range &&
                fabsf(after - AAC_DRC_GEN_DEFAULT_RANGE_LU) <
                    fabsf(before - AAC_DRC_GEN_DEFAULT_RANGE_LU) &&
                after < before - 1.5f,
            "%s: set %d loudness range %.1f LU (input %.1f)", name, s + 1, after, before);
    }
    printf(
        "  %s: anchor %.2f LKFS, ratio %.2f, range %.1f -> %.1f / %.1f LU, peaks %.2f -> %.2f / "
        "%.2f\n",
        name, c.anchor, c.ratio, programme.range_lu, c.set[0].range_lu, c.set[1].range_lu,
        input_peak, general_peak, night_peak);
  }
  /* The frame and the delay the encoder without SBR runs. */
  const Curves core = run_generator(pcm, kChannels, 2048, 1024, &programme);
  CHECK(core.set[1].has_range && core.set[1].range_lu < programme.range_lu - 1.5f,
        "1024-sample frames: range %.1f LU", core.set[1].range_lu);
  AacDrcGenParams bad;
  memset(&bad, 0, sizeof(bad));
  bad.sample_rate = kRate;
  bad.channels = 2;
  bad.frame_length = kSbrFrame;
  bad.delay = kSbrFrame - 1;
  bad.target_range_lu = AAC_DRC_GEN_DEFAULT_RANGE_LU;
  CHECK(aac_drc_gen_create(&bad) == nullptr, "a delay shorter than a frame is refused");
  bad.delay = kSbrFrame;
  bad.target_range_lu = 17;
  CHECK(aac_drc_gen_create(&bad) == nullptr, "a target range beyond 16 LU is refused");
}

/* ── streams ───────────────────────────────────────────────────── */

AacUsacEncoderOptions options(int drc, int range = 0) {
  AacUsacEncoderOptions o;
  aac_usac_encoder_options_init(&o);
  memset(&o, 0, sizeof(o));
  o.noise_filling = 1;
  o.preroll = 1;
  o.stream_id = -1;
  o.sbr = -1;
  o.drc = drc;
  o.drc_target_range_lu = range;
  return o;
}

struct Encoded {
  std::vector<uint8_t> stream;
  std::vector<uint8_t> asc;
  int error = 0;
};

Encoded encode(const std::vector<float>& pcm, int channels, int bitrate,
               const AacUsacEncoderOptions& opt, const AacLoudnessInfo* programme = nullptr) {
  Encoded e;
  AacEncoderHandle enc = aac_encoder_create(kRate, channels, bitrate, AAC_AOT_USAC, AAC_RC_CBR);
  CHECK(enc != nullptr, "encoder");
  if (!enc) {
    e.error = AAC_ERR_INIT;
    return e;
  }
  CHECK(aac_encoder_set_usac(enc, &opt) == AAC_OK, "options");
  CHECK(aac_encoder_set_transport(enc, AAC_TRANSPORT_LOAS) == AAC_OK, "LOAS");
  if (programme) {
    CHECK(aac_encoder_set_loudness_info(enc, programme) == AAC_OK, "programme loudness");
  }
  e.asc.resize(AAC_USAC_MAX_CONFIG_BYTES + 16);
  const int asc_len = aac_encoder_config(enc, e.asc.data(), (int)e.asc.size());
  CHECK(asc_len > 0, "config");
  e.asc.resize((size_t)std::max(0, asc_len));
  const int frame = aac_encoder_frame_size(enc);
  std::vector<float> block((size_t)frame * channels);
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) * 4);
  for (size_t pos = 0; pos < pcm.size(); pos += block.size()) {
    std::fill(block.begin(), block.end(), 0.0f);
    memcpy(block.data(), pcm.data() + pos,
           std::min(block.size(), pcm.size() - pos) * sizeof(float));
    const int len = aac_encoder_encode(enc, block.data(), frame, buf.data(), (int)buf.size());
    if (len < 0) {
      e.error = len;
      break;
    }
    e.stream.insert(e.stream.end(), buf.begin(), buf.begin() + len);
  }
  for (int len; !e.error && (len = aac_encoder_flush(enc, buf.data(), (int)buf.size())) != 0;) {
    if (len < 0) {
      e.error = len;
      break;
    }
    e.stream.insert(e.stream.end(), buf.begin(), buf.begin() + len);
  }
  aac_encoder_destroy(enc);
  return e;
}

std::vector<float> decode(const std::vector<uint8_t>& stream, int channels, AacDrcEffect effect,
                          int* errors) {
  std::vector<float> pcm;
  AacDecoderHandle dec = aac_decoder_create(kRate, channels);
  CHECK(dec != nullptr, "decoder");
  if (!dec) {
    *errors = -1;
    return pcm;
  }
  aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
  aac_decoder_set_loudness(dec, 0, -2400);
  aac_decoder_set_drc_effect(dec, effect, 0, 100, 100);
  std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  *errors = 0;
  size_t fed = 0;
  for (;;) {
    if (fed < stream.size()) {
      const int took = aac_decoder_feed(dec, stream.data() + fed, (int)(stream.size() - fed));
      fed += (size_t)std::max(0, took);
    }
    const int got = aac_decoder_decode_next(dec, out.data(), (int)out.size());
    if (got == 0) {
      if (fed >= stream.size()) {
        break;
      }
      continue;
    }
    if (got < 0) {
      (*errors)++;
      continue;
    }
    pcm.insert(pcm.end(), out.begin(), out.begin() + (size_t)got * aac_decoder_channels(dec));
  }
  aac_decoder_destroy(dec);
  return pcm;
}

/* The level of a passage of a decode (the codec delay removed), in dB. */
float passage_db(const std::vector<float>& pcm, int channels, int delay, size_t from, size_t to) {
  double e = 0.0;
  size_t n = 0;
  for (size_t i = from; i < to; i++) {
    const size_t at = (i + (size_t)delay) * channels;
    for (int c = 0; c < channels && at + c < pcm.size(); c++, n++) {
      e += (double)pcm[at + c] * pcm[at + c];
    }
  }
  return n ? (float)(10.0 * log10(e / (double)n + 1e-20)) : -200.0f;
}

/* The elements of a stream's configuration: whether the UNI_DRC
 * extension sits after the pre-roll and carries a configuration the
 * parser reads with the two sets. */
void check_stream_config(const std::vector<uint8_t>& asc, bool drc, const char* name) {
  AacAsc parsed;
  memset(&parsed, 0, sizeof(parsed));
  CHECK(aac_asc_parse(asc.data(), (int)asc.size(), &parsed) == AAC_OK, "%s: config read", name);
  const AacUsacConfig* c = &parsed.usac;
  int drc_at = -1;
  for (int i = 0; i < c->num_elements; i++) {
    if (c->elem[i].type == AAC_USAC_EXT && c->elem[i].ext_type == AAC_USAC_EXT_UNI_DRC) {
      drc_at = i;
    }
  }
  if (!drc) {
    CHECK(drc_at < 0, "%s: no UNI_DRC element", name);
    return;
  }
  CHECK(drc_at == 1 && c->elem[0].ext_type == AAC_USAC_EXT_AUDIOPREROLL &&
            c->elem[c->num_elements - 1].ext_type == AAC_USAC_EXT_FILL,
        "%s: pre-roll, UNI_DRC, cores, fill (UNI_DRC at %d of %d)", name, drc_at, c->num_elements);
  if (drc_at < 0) {
    return;
  }
  AacBitReader r;
  aac_bitreader_init(&r, c->elem[drc_at].ext_config, c->elem[drc_at].ext_config_len);
  AacUniDrcConfig u;
  memset(&u, 0, sizeof(u));
  CHECK(aac_unidrc_parse_config(&r, &u) == AAC_OK && u.instructions_count == AAC_DRC_GEN_SETS,
        "%s: the uniDrcConfig reads with %d sets", name, u.instructions_count);
  bool loudness = false;
  for (int i = 0; i < c->num_config_exts; i++) {
    if (c->config_ext[i].type == AAC_USAC_CFG_EXT_LOUDNESS_INFO) {
      AacBitReader lr;
      aac_bitreader_init(&lr, c->config_ext[i].data, c->config_ext[i].len);
      AacUniDrcLoudnessSet set;
      loudness = aac_unidrc_parse_loudness(&lr, &set) == AAC_OK;
    }
  }
  CHECK(loudness, "%s: a loudness info set accompanies the DRC sets", name);
}

void test_streams() {
  constexpr int kChannels = 2;
  constexpr double kPassage = 2.0;
  constexpr int kStreamPassages = 5;
  const std::vector<float> pcm = gen_alternation(kStreamPassages, kPassage, kChannels);
  const size_t per = (size_t)(kPassage * kRate);
  const Encoded off = encode(pcm, kChannels, 64000, options(0));
  const Encoded off_range = encode(pcm, kChannels, 64000, options(0, 6));
  const Encoded on = encode(pcm, kChannels, 64000, options(1));
  CHECK(!off.error && !on.error && !off_range.error, "encodes (%d, %d)", off.error, on.error);
  CHECK(off.stream == off_range.stream, "with drc off the target range changes nothing");
  check_stream_config(off.asc, false, "drc off");
  check_stream_config(on.asc, true, "drc on");
  CHECK(on.stream.size() < off.stream.size() * 11 / 10, "the gains cost %zu against %zu bytes",
        on.stream.size(), off.stream.size());
  int errors = 0;
  const std::vector<float> plain = decode(on.stream, kChannels, AAC_DRC_EFFECT_OFF, &errors);
  CHECK(errors == 0, "plain decode: %d errors", errors);
  const std::vector<float> reference = decode(off.stream, kChannels, AAC_DRC_EFFECT_OFF, &errors);
  const double snr_on = aligned_snr_db(pcm, plain, kChannels, AAC_ENCODER_SBR_DELAY);
  const double snr_off = aligned_snr_db(pcm, reference, kChannels, AAC_ENCODER_SBR_DELAY);
  CHECK(snr_on > snr_off - 1.0, "the audio is as good with the gains: %.1f against %.1f dB", snr_on,
        snr_off);
  int none_errors = 0;
  const std::vector<float> none = decode(on.stream, kChannels, AAC_DRC_EFFECT_NONE, &none_errors);
  CHECK(none_errors == 0 && none.size() == plain.size() &&
            snr_db(plain.data(), none.data(), plain.size()) > 60.0,
        "no effect requested: no set applied");
  const int delay = AAC_ENCODER_SBR_DELAY;
  for (AacDrcEffect effect : {AAC_DRC_EFFECT_NIGHT, AAC_DRC_EFFECT_GENERAL}) {
    const std::vector<float> out = decode(on.stream, kChannels, effect, &errors);
    CHECK(errors == 0 && out.size() == plain.size(), "effect %d: %d errors", (int)effect, errors);
    for (int p = 1; p < kStreamPassages; p++) {
      const size_t from = p * per + per / 2, to = (p + 1) * per;
      const float change = passage_db(out, kChannels, delay, from, to) -
                           passage_db(plain, kChannels, delay, from, to);
      CHECK(kPattern[p] == kQuiet  ? change > 2.0f
            : kPattern[p] == kLoud ? change < -1.0f
                                   : true,
            "effect %d, passage %d (%s): %.2f dB", (int)effect, p, passage_name(p), change);
    }
    const std::vector<float> ignored = decode(off.stream, kChannels, effect, &errors);
    CHECK(ignored.size() == reference.size() &&
              snr_db(reference.data(), ignored.data(), reference.size()) > 60.0,
          "effect %d on a stream without gains changes nothing", (int)effect);
  }
  /* Two-pass: the programme's figures anchor the curve and the loudness
   * set gains an entry per DRC set. */
  const AacLoudnessInfo programme = measure(pcm, kChannels);
  const Encoded measured = encode(pcm, kChannels, 64000, options(1, 8), &programme);
  CHECK(!measured.error, "two-pass encode");
  check_stream_config(measured.asc, true, "two-pass");
  AacAsc parsed;
  memset(&parsed, 0, sizeof(parsed));
  int set_entries = 0;
  if (aac_asc_parse(measured.asc.data(), (int)measured.asc.size(), &parsed) == AAC_OK) {
    for (int i = 0; i < parsed.usac.num_config_exts; i++) {
      if (parsed.usac.config_ext[i].type == AAC_USAC_CFG_EXT_LOUDNESS_INFO) {
        AacBitReader r;
        aac_bitreader_init(&r, parsed.usac.config_ext[i].data, parsed.usac.config_ext[i].len);
        AacUniDrcLoudnessSet set;
        if (aac_unidrc_parse_loudness(&r, &set) == AAC_OK) {
          for (int k = 0; k < set.count; k++) {
            set_entries |= 1 << set.info[k].drc_set_id;
          }
        }
      }
    }
  }
  CHECK(set_entries == 7, "loudness entries for drcSetId 0, 1 and 2 (mask %d)", set_entries);
}

void test_api() {
  const std::vector<float> pcm = gen_alternation(2, 0.5, 2);
  AacEncoderHandle lc = aac_encoder_create(kRate, 2, 128000, AAC_AOT_LC, AAC_RC_CBR);
  AacLoudnessInfo info;
  memset(&info, 0, sizeof(info));
  info.integrated_lkfs = -18.0f;
  info.sample_peak_dbfs = -2.0f;
  info.true_peak_dbtp = -1.5f;
  info.has_true_peak = 1;
  CHECK(aac_encoder_set_drc_loudness_info(lc, AAC_USAC_DRC_SET_NIGHT, &info) == AAC_ERR_UNSUPPORTED,
        "AAC-LC has no DRC sets");
  aac_encoder_destroy(lc);
  AacEncoderHandle enc = aac_encoder_create(kRate, 2, 64000, AAC_AOT_USAC, AAC_RC_CBR);
  AacUsacEncoderOptions o = options(1);
  CHECK(aac_encoder_set_usac(enc, &o) == AAC_OK, "drc on");
  CHECK(aac_encoder_set_drc_loudness_info(enc, 3, &info) == AAC_ERR_INVALID_ARG, "set 3 refused");
  CHECK(aac_encoder_set_drc_loudness_info(enc, AAC_USAC_DRC_SET_NIGHT, &info) == AAC_OK,
        "night set figures");
  CHECK(aac_encoder_set_drc_loudness_info(enc, AAC_USAC_DRC_SET_NIGHT, nullptr) == AAC_OK,
        "back to the estimate");
  AacLoudnessInfo out;
  CHECK(aac_encoder_drc_loudness(enc, AAC_USAC_DRC_SET_GENERAL, &out) == AAC_ERR_STATE,
        "no figures before the first frame");
  CHECK(aac_encoder_set_loudness_measurement(enc, 1) == AAC_OK, "measurement on");
  const int frame = aac_encoder_frame_size(enc);
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc));
  for (size_t pos = 0; pos + (size_t)frame * 2 <= pcm.size(); pos += (size_t)frame * 2) {
    CHECK(aac_encoder_encode(enc, pcm.data() + pos, frame, buf.data(), (int)buf.size()) >= 0,
          "frame at %zu", pos);
  }
  CHECK(aac_encoder_set_drc_loudness_info(enc, AAC_USAC_DRC_SET_NIGHT, &info) == AAC_ERR_STATE,
        "figures refused once the stream started");
  CHECK(aac_encoder_drc_loudness(enc, AAC_USAC_DRC_SET_GENERAL, &out) == AAC_OK &&
            out.integrated_lkfs < 0.0f,
        "the general set metered (%.2f)", out.integrated_lkfs);
  CHECK(aac_encoder_drc_loudness(enc, 0, &out) == AAC_ERR_INVALID_ARG, "set 0 is not a DRC set");
  aac_encoder_destroy(enc);
}

/* ── the committed stream ──────────────────────────────────────── */

std::vector<float> committed_signal() { return gen_alternation(kPassages, 0.5, 2); }

int write_streams(const char* dir) {
  const Encoded e = encode(committed_signal(), 2, 64000, options(1));
  const std::string path = std::string(dir) + "/" + kCommittedName;
  if (e.error || !write_file(path, e.stream.data(), e.stream.size())) {
    fprintf(stderr, "%s: not written\n", path.c_str());
    return 1;
  }
  printf("%s: %zu bytes\n", path.c_str(), e.stream.size());
  return 0;
}

void test_committed() {
  const std::vector<uint8_t> stream = read_file(data_path(kCommittedName));
  CHECK(!stream.empty(), "%s present", kCommittedName);
  if (stream.empty()) {
    return;
  }
  const std::vector<float> pcm = committed_signal();
  int errors = 0;
  const std::vector<float> plain = decode(stream, 2, AAC_DRC_EFFECT_OFF, &errors);
  const double snr = aligned_snr_db(pcm, plain, 2, AAC_ENCODER_SBR_DELAY);
  CHECK(errors == 0 && snr > 9.0, "%s: %d errors, %.1f dB", kCommittedName, errors, snr);
  const std::vector<float> night = decode(stream, 2, AAC_DRC_EFFECT_NIGHT, &errors);
  CHECK(errors == 0 && night.size() == plain.size() &&
            snr_db(plain.data(), night.data(), plain.size()) < 20.0,
        "%s: the night set applies", kCommittedName);
  const Encoded e = encode(pcm, 2, 64000, options(1));
#if defined(__OPTIMIZE__) && BAAC_RELEASE_MATH && !BAAC_SANITIZED
  CHECK(e.stream == stream, "%s: a fresh encode is byte identical (%zu vs %zu bytes)",
        kCommittedName, e.stream.size(), stream.size());
#else
  int again_errors = 0;
  const std::vector<float> again = decode(e.stream, 2, AAC_DRC_EFFECT_OFF, &again_errors);
  const double fresh = aligned_snr_db(pcm, again, 2, AAC_ENCODER_SBR_DELAY);
  CHECK(again_errors == 0 && fabs(fresh - snr) < 1.5,
        "%s: a fresh encode of this build decodes like the committed stream (%.1f against %.1f dB; "
        "the byte identity check needs the release build's flags)",
        kCommittedName, fresh, snr);
#endif
  printf("  %s: %.1f dB\n", kCommittedName, snr);
}

}  // namespace

int main(int argc, char** argv) {
  aac_tables_init();
  if (argc >= 3 && strcmp(argv[1], "--write-streams") == 0) {
    return write_streams(argv[2]);
  }
  test_config_round_trip();
  test_gain_round_trip();
  test_loudness_round_trip();
  test_static_curve();
  test_generator();
  test_streams();
  test_api();
  test_committed();
  return test_summary("test_drc_gen");
}
