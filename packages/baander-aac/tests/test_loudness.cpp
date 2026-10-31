/* The loudness meter against the known answers of ITU-R BS.1770-4 and
 * the EBU Tech 3341/3342 test signals that can be synthesised (sine
 * levels in mono, stereo and 5.1, the gating cases, the loudness range
 * pairs, the momentary and short-term maxima, the true peak of
 * inter-sample peaks), the derived K-weighting against the standard's
 * 48 kHz table, the invariance to the feed chunking, and the
 * loudnessInfoSet() writer against hand-computed bit layouts, the FDK
 * encoder's own loudnessInfoSet() bytes and the decoder's parser, for
 * the single program and for entries per DRC set. */
#include "loudness.h"
#include "test_util.h"
#include "unidrc.h"

/* ── signals ───────────────────────────────────────────────────── */

/* A sine per channel at the given peak levels in dBFS (kSilent for a
 * silent channel), interleaved, with a phase offset in samples. */
static const double kSilent = -999.0;
static std::vector<float> sine(double seconds, int rate, int channels, const double* dbfs,
                               double freq = 997.0, double phase_samples = 0.0) {
  const size_t n = (size_t)(seconds * rate);
  std::vector<float> pcm(n * (size_t)channels);
  for (size_t i = 0; i < n; i++) {
    const double v = sin(2.0 * M_PI * freq * ((double)i + phase_samples) / (double)rate);
    for (int c = 0; c < channels; c++) {
      const double amp = dbfs[c] > kSilent ? pow(10.0, dbfs[c] / 20.0) : 0.0;
      pcm[i * (size_t)channels + (size_t)c] = (float)(amp * v);
    }
  }
  return pcm;
}

static std::vector<float> stereo_sine(double seconds, double dbfs, int rate = 48000) {
  const double levels[2] = {dbfs, dbfs};
  return sine(seconds, rate, 2, levels);
}

static void append(std::vector<float>& to, const std::vector<float>& part) {
  to.insert(to.end(), part.begin(), part.end());
}

static AacLoudnessInfo measure(const std::vector<float>& pcm, int rate, int channels,
                               int chunk = 4096) {
  AacLoudnessMeter* m = aac_loudness_create(rate, channels);
  CHECK(m != nullptr, "meter for %d Hz %d channels", rate, channels);
  const size_t frames = pcm.size() / (size_t)channels;
  for (size_t pos = 0; pos < frames; pos += (size_t)chunk) {
    const int n = (int)std::min((size_t)chunk, frames - pos);
    aac_loudness_feed(m, pcm.data() + pos * (size_t)channels, n);
  }
  AacLoudnessInfo info;
  memset(&info, 0, sizeof(info));
  CHECK(aac_loudness_result(m, &info) == AAC_OK, "result");
  aac_loudness_destroy(m);
  return info;
}

/* ── the K-weighting ───────────────────────────────────────────── */

/* The 48 kHz coefficients of BS.1770-4 Tables 1 and 2. */
static const double kShelf48[5] = {1.53512485958697, -2.69169618940638, 1.19839281085285,
                                   -1.69065929318241, 0.73248077421585};
static const double kHighpass48[5] = {1.0, -2.0, 1.0, -1.99004745483398, 0.99007225036621};

/* |H|^2 of a biquad {b0, b1, b2, a1, a2} at `freq`. */
static double biquad_power_gain(const double* c, double freq, double rate) {
  const double w = 2.0 * M_PI * freq / rate;
  const double cw = cos(w), sw = sin(w), c2w = cos(2.0 * w), s2w = sin(2.0 * w);
  const double nr = c[0] + c[1] * cw + c[2] * c2w, ni = -(c[1] * sw + c[2] * s2w);
  const double dr = 1.0 + c[3] * cw + c[4] * c2w, di = -(c[3] * sw + c[4] * s2w);
  return (nr * nr + ni * ni) / (dr * dr + di * di);
}

static void test_k_weighting() {
  /* A mono sine at every frequency reads the level the standard's own
   * 48 kHz table predicts, so the derived coefficients match it; at
   * 44.1 kHz the same analogue curve applies (the response differs
   * only by the slight frequency warping of the bilinear transform). */
  const double freqs[] = {60.0, 100.0, 300.0, 997.0, 3000.0, 8000.0, 15000.0};
  for (double f : freqs) {
    const double gain = 10.0 * log10(biquad_power_gain(kShelf48, f, 48000.0) *
                                     biquad_power_gain(kHighpass48, f, 48000.0));
    const double expected = -0.691 + 10.0 * log10(0.5 * pow(10.0, -20.0 / 10.0)) + gain;
    const double level = -20.0;
    const AacLoudnessInfo info = measure(sine(10.0, 48000, 1, &level, f), 48000, 1);
    CHECK(fabs(info.integrated_lkfs - expected) < 0.05, "%g Hz at 48 kHz: %.3f LKFS, table %.3f",
          f, info.integrated_lkfs, expected);
    if (f <= 3000.0) {
      const AacLoudnessInfo info44 = measure(sine(10.0, 44100, 1, &level, f), 44100, 1);
      CHECK(fabs(info44.integrated_lkfs - expected) < 0.1,
            "%g Hz at 44.1 kHz: %.3f LKFS, table %.3f", f, info44.integrated_lkfs, expected);
    }
  }
  /* The K-weighting puts 997 Hz 0.691 dB up, which the offset of the
   * loudness formula takes out again: a stereo -23 dBFS sine reads -23. */
  const double gain = 10.0 * log10(biquad_power_gain(kShelf48, 997.0, 48000.0) *
                                   biquad_power_gain(kHighpass48, 997.0, 48000.0));
  CHECK(fabs(gain - 0.691) < 0.01, "K-weighting at 997 Hz is +0.691 dB (%.3f)", gain);
  /* The shared filter's coefficients at 48 kHz are the table's, and its
   * impulse response starts with the shelf's b0 (the high-pass's is 1). */
  AacKWeighting k;
  aac_k_weighting_init(&k, 48000);
  for (int i = 0; i < 5; i++) {
    CHECK(fabs(k.shelf[i] - kShelf48[i]) < 1e-7 && fabs(k.highpass[i] - kHighpass48[i]) < 1e-7,
          "coefficient %d: %.12f %.12f against the table", i, k.shelf[i], k.highpass[i]);
  }
  AacKWeightingState st;
  memset(&st, 0, sizeof(st));
  const double y0 = aac_k_weighting_run(&k, &st, 1.0);
  const double y1 = aac_k_weighting_run(&k, &st, 0.0);
  const double expected1 = k.shelf[1] - k.shelf[3] * k.shelf[0] + k.shelf[0] * (-2.0 - k.highpass[3]);
  CHECK(fabs(y0 - k.shelf[0]) < 1e-12 && fabs(y1 - expected1) < 1e-12,
        "impulse response %.9f %.9f", y0, y1);
}

/* ── EBU Tech 3341: levels, gating, maxima ─────────────────────── */

static void test_levels() {
  /* EBU Tech 3341 cases 1 and 2: a stereo 997 Hz sine at -23 and
   * -33 dBFS for 20 s reads -23.0 and -33.0 LUFS, and the momentary and
   * short-term meters read the same on a steady signal. */
  AacLoudnessInfo info = measure(stereo_sine(20.0, -23.0), 48000, 2);
  CHECK(fabs(info.integrated_lkfs + 23.0) < 0.1, "stereo -23 dBFS: %.3f LKFS", info.integrated_lkfs);
  CHECK(fabs(info.max_momentary_lkfs + 23.0) < 0.1, "momentary maximum %.3f", info.max_momentary_lkfs);
  CHECK(fabs(info.max_short_term_lkfs + 23.0) < 0.1, "short-term maximum %.3f",
        info.max_short_term_lkfs);
  CHECK(info.has_range && info.range_lu < 0.1, "steady signal has no range (%.3f LU)", info.range_lu);
  CHECK(fabs(info.sample_peak_dbfs + 23.0) < 0.05, "sample peak %.3f dBFS", info.sample_peak_dbfs);
  CHECK(info.has_true_peak && fabs(info.true_peak_dbtp + 23.0) < 0.1, "true peak %.3f dBTP",
        info.true_peak_dbtp);
  info = measure(stereo_sine(20.0, -33.0), 48000, 2);
  CHECK(fabs(info.integrated_lkfs + 33.0) < 0.1, "stereo -33 dBFS: %.3f LKFS", info.integrated_lkfs);
  /* The same at 44.1 kHz: the block length and the filters follow the rate. */
  info = measure(stereo_sine(20.0, -23.0, 44100), 44100, 2);
  CHECK(fabs(info.integrated_lkfs + 23.0) < 0.1, "stereo -23 dBFS at 44.1 kHz: %.3f LKFS",
        info.integrated_lkfs);
  /* One channel alone carries half the power: -26.0 LKFS, in mono and
   * as the left channel of a stereo pair. */
  const double mono = -23.0;
  info = measure(sine(10.0, 48000, 1, &mono), 48000, 1);
  CHECK(fabs(info.integrated_lkfs + 26.0) < 0.1, "mono -23 dBFS: %.3f LKFS", info.integrated_lkfs);
  const double left_only[2] = {-23.0, kSilent};
  info = measure(sine(10.0, 48000, 2, left_only), 48000, 2);
  CHECK(fabs(info.integrated_lkfs + 26.0) < 0.1, "left only -23 dBFS: %.3f LKFS",
        info.integrated_lkfs);
}

static void test_surround() {
  /* EBU Tech 3341 case 6 in the canonical 5.1 order: L and R at -28,
   * C at -24, Ls and Rs at -30 dBFS read -23.0 LUFS; the LFE carries a
   * loud 60 Hz tone that must not count. */
  const double levels[6] = {-28.0, -28.0, -24.0, kSilent, -30.0, -30.0};
  AacLoudnessInfo info = measure(sine(10.0, 48000, 6, levels), 48000, 6);
  CHECK(fabs(info.integrated_lkfs + 23.0) < 0.1, "5.1 case: %.3f LKFS", info.integrated_lkfs);
  std::vector<float> with_lfe = sine(10.0, 48000, 6, levels);
  const double lfe = -6.0;
  const std::vector<float> tone = sine(10.0, 48000, 1, &lfe, 60.0);
  for (size_t i = 0; i < tone.size(); i++) {
    with_lfe[i * 6 + 3] = tone[i];
  }
  const AacLoudnessInfo info_lfe = measure(with_lfe, 48000, 6);
  CHECK(fabs(info_lfe.integrated_lkfs - info.integrated_lkfs) < 1e-3,
        "the LFE does not count (%.3f against %.3f)", info_lfe.integrated_lkfs,
        info.integrated_lkfs);
  CHECK(fabs(info_lfe.sample_peak_dbfs + 6.0) < 0.05, "the LFE counts for the peak (%.3f)",
        info_lfe.sample_peak_dbfs);
  /* The surround weight alone: a -23 dBFS sine in Ls reads -26 + 1.5. */
  const double ls_only[6] = {kSilent, kSilent, kSilent, kSilent, -23.0, kSilent};
  info = measure(sine(10.0, 48000, 6, ls_only), 48000, 6);
  const double expected = -26.0 + 10.0 * log10(1.41);
  CHECK(fabs(info.integrated_lkfs - expected) < 0.1, "Ls alone: %.3f LKFS, expected %.3f",
        info.integrated_lkfs, expected);
}

static void test_gating() {
  /* EBU Tech 3341 cases 3 to 5 at shorter lengths: the quiet parts fall
   * under the relative gate (case 3), under the absolute gate (case 4),
   * or no part is gated and the mean is the answer (case 5). */
  std::vector<float> pcm = stereo_sine(5.0, -36.0);
  append(pcm, stereo_sine(30.0, -23.0));
  append(pcm, stereo_sine(5.0, -36.0));
  AacLoudnessInfo info = measure(pcm, 48000, 2);
  CHECK(fabs(info.integrated_lkfs + 23.0) < 0.1, "relative gate: %.3f LKFS", info.integrated_lkfs);
  pcm = stereo_sine(5.0, -72.0);
  append(pcm, stereo_sine(30.0, -23.0));
  append(pcm, stereo_sine(5.0, -72.0));
  info = measure(pcm, 48000, 2);
  CHECK(fabs(info.integrated_lkfs + 23.0) < 0.1, "absolute gate: %.3f LKFS", info.integrated_lkfs);
  pcm = stereo_sine(10.0, -26.0);
  append(pcm, stereo_sine(10.0, -20.0));
  append(pcm, stereo_sine(10.0, -26.0));
  info = measure(pcm, 48000, 2);
  CHECK(fabs(info.integrated_lkfs + 23.0) < 0.1, "no gating: %.3f LKFS", info.integrated_lkfs);
  /* Digital silence: nothing passes the absolute gate. */
  info = measure(std::vector<float>(48000 * 2, 0.0f), 48000, 2);
  CHECK(info.integrated_lkfs == AAC_LOUDNESS_SILENCE, "silence reads the floor (%g)",
        info.integrated_lkfs);
  CHECK(info.sample_peak_dbfs == AAC_LOUDNESS_SILENCE && info.true_peak_dbtp == AAC_LOUDNESS_SILENCE,
        "silence has no peak");
  CHECK(info.has_momentary && info.max_momentary_lkfs == AAC_LOUDNESS_SILENCE &&
            !info.has_short_term && !info.has_range,
        "silence has a momentary window at the floor and no 3 s window");
}

static void test_maxima() {
  /* A -23 dBFS bed with one second at -13 dBFS: the momentary maximum
   * is a block inside the burst, the short-term maximum a 3 s window
   * with the burst and two seconds of bed. */
  std::vector<float> pcm = stereo_sine(5.0, -23.0);
  append(pcm, stereo_sine(1.0, -13.0));
  append(pcm, stereo_sine(5.0, -23.0));
  const AacLoudnessInfo info = measure(pcm, 48000, 2);
  CHECK(fabs(info.max_momentary_lkfs + 13.0) < 0.1, "momentary maximum %.3f", info.max_momentary_lkfs);
  const double expected = 10.0 * log10((pow(10.0, -1.3) + 2.0 * pow(10.0, -2.3)) / 3.0);
  CHECK(fabs(info.max_short_term_lkfs - expected) < 0.1, "short-term maximum %.3f, expected %.3f",
        info.max_short_term_lkfs, expected);
  CHECK(info.integrated_lkfs > -23.0 && info.integrated_lkfs < -13.0, "integrated %.3f between",
        info.integrated_lkfs);
}

/* ── EBU Tech 3342: loudness range ─────────────────────────────── */

static void test_range() {
  const double cases[][3] = {{-20.0, -30.0, 10.0}, {-20.0, -15.0, 5.0}, {-40.0, -20.0, 20.0},
                             {-50.0, -35.0, 15.0}};
  for (const double* c : cases) {
    std::vector<float> pcm = stereo_sine(20.0, c[0]);
    append(pcm, stereo_sine(20.0, c[1]));
    const AacLoudnessInfo info = measure(pcm, 48000, 2);
    CHECK(info.has_range && fabs(info.range_lu - c[2]) < 1.0, "%g/%g dBFS: range %.2f LU, expected %g",
          c[0], c[1], info.range_lu, c[2]);
  }
  /* Silence in the middle falls under the absolute gate and changes
   * nothing; a short excursion to -70 dBFS does not widen the range
   * because the relative gate drops it. */
  std::vector<float> pcm = stereo_sine(20.0, -20.0);
  append(pcm, std::vector<float>(48000 * 2 * 10, 0.0f));
  append(pcm, stereo_sine(20.0, -30.0));
  AacLoudnessInfo info = measure(pcm, 48000, 2);
  CHECK(fabs(info.range_lu - 10.0) < 1.0, "range with silence %.2f LU", info.range_lu);
  pcm = stereo_sine(20.0, -20.0);
  append(pcm, stereo_sine(5.0, -60.0));
  append(pcm, stereo_sine(20.0, -30.0));
  info = measure(pcm, 48000, 2);
  CHECK(fabs(info.range_lu - 10.0) < 1.0, "range with a gated dip %.2f LU", info.range_lu);
  /* Under three seconds there is no window yet. */
  info = measure(stereo_sine(2.0, -20.0), 48000, 2);
  CHECK(!info.has_range && !info.has_short_term, "no range under 3 s");
  CHECK(info.has_momentary && fabs(info.max_momentary_lkfs + 20.0) < 0.1,
        "momentary maximum after 2 s");
}

/* ── true peak ─────────────────────────────────────────────────── */

static void test_true_peak() {
  /* A 997 Hz sine at -6 dBFS: the true peak is the amplitude, whatever
   * the sampling phase. */
  AacLoudnessInfo info = measure(stereo_sine(2.0, -6.0), 48000, 2);
  CHECK(fabs(info.true_peak_dbtp + 6.0) < 0.2, "997 Hz at -6 dBFS: %.3f dBTP", info.true_peak_dbtp);
  CHECK(info.true_peak_dbtp >= info.sample_peak_dbfs, "true peak above the sample peak");
  /* The EBU Tech 3341 true-peak case: +0.5 dBFS at 997 Hz with the
   * peaks a quarter sample off the grid. */
  const double half_db[2] = {0.5, 0.5};
  info = measure(sine(2.0, 48000, 2, half_db, 997.0, 0.25), 48000, 2);
  CHECK(fabs(info.true_peak_dbtp - 0.5) < 0.2, "997 Hz at +0.5 dBFS: %.3f dBTP", info.true_peak_dbtp);
  /* A quarter-rate sine sampled at 45 degrees: every sample sits 3 dB
   * under the peak, which lies half way between samples. */
  const double level = -6.0;
  info = measure(sine(2.0, 48000, 1, &level, 12000.0, 0.5), 48000, 1);
  CHECK(fabs(info.sample_peak_dbfs + 9.03) < 0.05, "12 kHz sample peak %.3f dBFS", info.sample_peak_dbfs);
  CHECK(fabs(info.true_peak_dbtp + 6.0) < 0.2, "12 kHz true peak %.3f dBTP", info.true_peak_dbtp);
  /* The same at 96 kHz, where twice oversampling is enough. */
  info = measure(sine(2.0, 96000, 1, &level, 24000.0, 0.5), 96000, 1);
  CHECK(fabs(info.sample_peak_dbfs + 9.03) < 0.05, "24 kHz at 96 kHz sample peak %.3f",
        info.sample_peak_dbfs);
  CHECK(fabs(info.true_peak_dbtp + 6.0) < 0.2, "24 kHz at 96 kHz true peak %.3f dBTP",
        info.true_peak_dbtp);
  /* A single full-scale sample: the interpolation overshoots a little
   * but not the 1 dB the standard tolerates for its filter. */
  std::vector<float> click(48000, 0.0f);
  click[24000] = 1.0f;
  info = measure(click, 48000, 1);
  CHECK(fabs(info.sample_peak_dbfs) < 1e-4, "click sample peak %.4f", info.sample_peak_dbfs);
  CHECK(info.true_peak_dbtp >= 0.0f && info.true_peak_dbtp < 1.0f, "click true peak %.3f dBTP",
        info.true_peak_dbtp);
}

/* ── the API ───────────────────────────────────────────────────── */

static void test_api() {
  CHECK(aac_loudness_create(48000, 0) == nullptr, "no channels refused");
  CHECK(aac_loudness_create(48000, 7) == nullptr, "seven channels refused");
  CHECK(aac_loudness_create(1000, 2) == nullptr, "1 kHz refused");
  AacLoudnessMeter* m = aac_loudness_create(48000, 2);
  CHECK(m != nullptr, "meter");
  AacLoudnessInfo info;
  CHECK(aac_loudness_result(m, &info) == AAC_ERR_STATE, "no result before any block");
  const std::vector<float> pcm = stereo_sine(0.39, -23.0);
  aac_loudness_feed(m, pcm.data(), (int)pcm.size() / 2);
  CHECK(aac_loudness_result(m, &info) == AAC_ERR_STATE, "no result under 400 ms");
  aac_loudness_feed(m, pcm.data(), 480);
  CHECK(aac_loudness_result(m, &info) == AAC_OK, "one block after 400 ms");
  CHECK(fabs(info.integrated_lkfs + 23.0) < 0.3, "one block reads the level (%.3f)",
        info.integrated_lkfs);
  aac_loudness_reset(m);
  CHECK(aac_loudness_result(m, &info) == AAC_ERR_STATE, "reset clears the blocks");
  aac_loudness_destroy(m);
  aac_loudness_destroy(nullptr);
  /* The chunking of the feed does not change a single bit of the result. */
  std::vector<float> mixed = stereo_sine(3.0, -20.0);
  append(mixed, gen_wideband(48000 * 2, 48000, 2));
  append(mixed, stereo_sine(2.0, -40.0));
  const AacLoudnessInfo a = measure(mixed, 48000, 2, 1);
  const AacLoudnessInfo b = measure(mixed, 48000, 2, 1000000);
  const AacLoudnessInfo c = measure(mixed, 48000, 2, 1023);
  CHECK(memcmp(&a, &b, sizeof(a)) == 0 && memcmp(&a, &c, sizeof(a)) == 0,
        "chunking changes nothing (%.6f %.6f %.6f)", a.integrated_lkfs, b.integrated_lkfs,
        c.integrated_lkfs);
}

/* ── loudnessInfoSet() ─────────────────────────────────────────── */

struct Bits {
  std::vector<uint8_t> buf = std::vector<uint8_t>(256);
  AacBitWriter w;
  Bits() { aac_bitwriter_init(&w, buf.data(), (int)buf.size()); }
  std::string string() const {
    std::string s;
    const int n = aac_bitwriter_bits_written(&w);
    for (int i = 0; i < n; i++) {
      s += (buf[(size_t)i / 8] >> (7 - i % 8)) & 1 ? '1' : '0';
    }
    return s;
  }
};

static std::string hex_string(const std::vector<uint8_t>& buf, int bytes) {
  std::string s;
  for (int i = 0; i < bytes; i++) {
    char tmp[3];
    snprintf(tmp, sizeof(tmp), "%02x", buf[(size_t)i]);
    s += tmp;
  }
  return s;
}

static void test_writer_layout() {
  /* The FDK xHE-AAC encoder's loudnessInfoSet() of the committed music
   * stream: sample peak -3.09 dBFS, no true peak, program loudness
   * -14.25 LUFS with BS.1770-4 and "accurate", 62 bits. */
  AacLoudnessInfo info;
  memset(&info, 0, sizeof(info));
  info.sample_peak_dbfs = -3.09f;
  info.integrated_lkfs = -14.25f;
  Bits b;
  const int bits = aac_loudness_info_write(&b.w, &info);
  CHECK(bits == 62, "the FDK layout is 62 bits (%d)", bits);
  CHECK(aac_loudness_info_bits(&info) == 62, "counted bits %d", aac_loudness_info_bits(&info));
  CHECK(hex_string(b.buf, 8) == "0010004b8c235c58", "the FDK bytes (%s)", hex_string(b.buf, 8).c_str());
  /* Every field: sample peak -1 dBFS (code 672), true peak +0.5 dBTP
   * (code 624), program loudness -23 LUFS (code 139), anchor -22 (143),
   * range 7.5 LU (code 30), momentary maximum -15.25 (170), short-term
   * maximum -18.5 (157). */
  info.sample_peak_dbfs = -1.0f;
  info.true_peak_dbtp = 0.5f;
  info.has_true_peak = 1;
  info.integrated_lkfs = -23.0f;
  info.anchor_lkfs = -22.0f;
  info.has_anchor = 1;
  info.range_lu = 7.5f;
  info.has_range = 1;
  info.max_momentary_lkfs = -15.25f;
  info.has_momentary = 1;
  info.max_short_term_lkfs = -18.5f;
  info.has_short_term = 1;
  const std::string expected = std::string("000000") + "000001" + /* album count 0, count 1 */
                               "000000" + "0000000" +            /* drcSetId, downmixId */
                               "1" + "001010100000" +            /* sample peak 672 */
                               "1" + "001001110000" + "0010" + "11" + /* true peak 624, BS.1770-4, accurate */
                               "0101" +                                /* five measurements */
                               "0001" + "10001011" + "0010" + "11" +   /* program loudness 139 */
                               "0010" + "10001111" + "0010" + "11" +   /* anchor loudness 143 */
                               "0110" + "00011110" + "0010" + "11" +   /* loudness range 30 */
                               "0100" + "10101010" + "0010" + "11" +   /* momentary maximum 170 */
                               "0101" + "10011101" + "0010" + "11" +   /* short-term maximum 157 */
                               "0";                                    /* no extension */
  Bits full;
  const int full_bits = aac_loudness_info_write(&full.w, &info);
  CHECK(full_bits == (int)expected.size(), "full layout bits %d, expected %d", full_bits,
        (int)expected.size());
  CHECK(aac_loudness_info_bits(&info) == full_bits, "counted bits of the full layout");
  CHECK(full.string() == expected, "full layout\n  %s\n  %s", full.string().c_str(),
        expected.c_str());
  /* The decoder's parser reads the values back at the code resolution. */
  AacBitReader r;
  aac_bitreader_init(&r, full.buf.data(), (int)full.buf.size());
  AacUniDrcLoudnessSet set;
  CHECK(aac_unidrc_parse_loudness(&r, &set) == AAC_OK, "parse the full layout");
  CHECK(r.pos == full_bits, "the parser consumed %d of %d bits", r.pos, full_bits);
  CHECK(set.album_count == 0 && set.count == 1, "one program entry");
  const AacUniDrcLoudnessInfo* li = &set.info[0];
  CHECK(li->drc_set_id == 0 && li->downmix_id == 0, "ids");
  CHECK(li->sample_peak_present && fabsf(li->sample_peak_db + 1.0f) < 1e-4f, "sample peak %g",
        li->sample_peak_db);
  CHECK(li->true_peak_present && fabsf(li->true_peak_db - 0.5f) < 1e-4f, "true peak %g",
        li->true_peak_db);
  CHECK(li->true_peak_system == 2 && li->true_peak_reliability == 3, "true peak system");
  CHECK(li->measurement_count == 5, "five measurements (%d)", li->measurement_count);
  const int methods[5] = {AAC_UNIDRC_METHOD_PROGRAM_LOUDNESS, AAC_UNIDRC_METHOD_ANCHOR_LOUDNESS,
                          AAC_UNIDRC_METHOD_LOUDNESS_RANGE, AAC_UNIDRC_METHOD_MOMENTARY_MAX,
                          AAC_UNIDRC_METHOD_SHORT_TERM_MAX};
  const float values[5] = {-23.0f, -22.0f, 7.5f, -15.25f, -18.5f};
  for (int i = 0; i < 5; i++) {
    CHECK(li->m[i].method == methods[i], "measurement %d method %d", i, li->m[i].method);
    CHECK(fabsf(li->m[i].value - values[i]) < 1e-4f, "measurement %d value %g", i, li->m[i].value);
    CHECK(li->m[i].system == 2 && li->m[i].reliability == 3, "measurement %d system", i);
  }
  /* Clamping at the ends of the scales: a peak above +20 dBFS and a
   * loudness under -57.75 take the end codes, as does silence, and the
   * range's three segments round at their own steps. */
  info.sample_peak_dbfs = 25.0f;
  info.true_peak_dbtp = AAC_LOUDNESS_SILENCE;
  info.integrated_lkfs = -80.0f;
  info.anchor_lkfs = 10.0f;
  info.range_lu = 150.0f;
  info.has_momentary = 0;
  info.max_short_term_lkfs = 40.3f;
  Bits clamp;
  aac_loudness_info_write(&clamp.w, &info);
  aac_bitreader_init(&r, clamp.buf.data(), (int)clamp.buf.size());
  CHECK(aac_unidrc_parse_loudness(&r, &set) == AAC_OK, "parse the clamped layout");
  li = &set.info[0];
  CHECK(fabsf(li->sample_peak_db - 20.0f) < 1e-4f, "peak clamps to +20 (%g)", li->sample_peak_db);
  CHECK(fabsf(li->true_peak_db - (20.0f - 4095.0f * 0.03125f)) < 1e-3f, "silent true peak %g",
        li->true_peak_db);
  CHECK(li->measurement_count == 4, "the momentary maximum is left out (%d)",
        li->measurement_count);
  CHECK(fabsf(li->m[0].value + 57.75f) < 1e-4f, "loudness clamps to -57.75 (%g)", li->m[0].value);
  CHECK(fabsf(li->m[1].value - 6.0f) < 1e-4f, "loudness clamps to +6 (%g)", li->m[1].value);
  CHECK(fabsf(li->m[2].value - 121.0f) < 1e-4f, "range clamps to 121 (%g)", li->m[2].value);
  CHECK(fabsf(li->m[3].value - 6.0f) < 1e-4f, "short-term maximum clamps to +6 (%g)", li->m[3].value);
  for (float lu : {31.75f, 31.9f, 45.3f, 69.8f, 70.0f, 100.4f}) {
    info.range_lu = lu;
    Bits one;
    aac_loudness_info_write(&one.w, &info);
    aac_bitreader_init(&r, one.buf.data(), (int)one.buf.size());
    aac_unidrc_parse_loudness(&r, &set);
    const float step = lu < 31.8f ? 0.25f : lu < 69.9f ? 0.5f : 1.0f;
    CHECK(fabsf(set.info[0].m[2].value - lu) <= step / 2.0f + 1e-4f, "range %g LU codes as %g", lu,
          set.info[0].m[2].value);
  }
  /* A buffer too small for the set is an overflow, not a partial write
   * reported as success. */
  uint8_t small[4];
  AacBitWriter w;
  aac_bitwriter_init(&w, small, (int)sizeof(small));
  CHECK(aac_loudness_info_write(&w, &info) == AAC_ERR_OVERFLOW, "overflow");
  CHECK(aac_loudness_info_write(nullptr, &info) == AAC_ERR_INVALID_ARG, "no writer");
  CHECK(aac_loudness_info_write(&w, nullptr) == AAC_ERR_INVALID_ARG, "no info");
}

static void test_writer_sets() {
  /* Entries per DRC set: each is the single program's entry with its
   * drcSetId, and the parser reads the ids and figures back. */
  AacLoudnessInfo info[3];
  memset(info, 0, sizeof(info));
  for (int i = 0; i < 3; i++) {
    info[i].sample_peak_dbfs = -1.0f - (float)i;
    info[i].integrated_lkfs = -20.0f - (float)i;
    info[i].true_peak_dbtp = -0.5f - (float)i;
    info[i].has_true_peak = i != 1;
    info[i].range_lu = 5.0f + (float)i;
    info[i].has_range = i == 2;
  }
  const int ids[3] = {0, 1, 2};
  Bits b;
  const int bits = aac_loudness_info_set_write(&b.w, info, ids, 3);
  CHECK(bits > 0 && bits == aac_loudness_info_set_bits(info, ids, 3), "set of three: %d bits", bits);
  int entries = 0;
  for (int i = 0; i < 3; i++) {
    Bits one;
    const int one_bits = aac_loudness_info_write(&one.w, &info[i]);
    /* the single set: two counts, the entry, the extension flag */
    std::string entry = one.string().substr(12, (size_t)one_bits - 13);
    for (int k = 0; k < 6; k++) {
      entry[(size_t)k] = (ids[i] >> (5 - k)) & 1 ? '1' : '0';
    }
    const std::string all = b.string();
    const size_t at = 12 + (size_t)entries;
    CHECK(all.compare(at, entry.size(), entry) == 0, "entry %d is the single entry with drcSetId %d",
          i, ids[i]);
    entries += (int)entry.size();
  }
  CHECK(bits == 12 + entries + 1, "two counts, three entries and the extension flag");
  AacBitReader r;
  aac_bitreader_init(&r, b.buf.data(), (int)b.buf.size());
  AacUniDrcLoudnessSet set;
  CHECK(aac_unidrc_parse_loudness(&r, &set) == AAC_OK && r.pos == bits, "parse the set");
  CHECK(set.album_count == 0 && set.count == 3, "three entries (%d)", set.count);
  for (int i = 0; i < 3 && i < set.count; i++) {
    const AacUniDrcLoudnessInfo* li = &set.info[i];
    CHECK(li->drc_set_id == ids[i] && li->downmix_id == 0, "entry %d ids", i);
    CHECK(li->true_peak_present == info[i].has_true_peak && li->measurement_count == 1 + info[i].has_range,
          "entry %d fields", i);
    CHECK(fabsf(li->m[0].value - info[i].integrated_lkfs) < 1e-4f, "entry %d loudness %g", i,
          li->m[0].value);
  }
  /* An empty set, and the limits of the ids and the count. */
  Bits empty;
  CHECK(aac_loudness_info_set_write(&empty.w, nullptr, nullptr, 0) == 13 &&
            empty.string() == "0000000000000",
        "empty set");
  const int bad_id = 64;
  CHECK(aac_loudness_info_set_write(&b.w, info, &bad_id, 1) == AAC_ERR_INVALID_ARG, "drcSetId 64");
  CHECK(aac_loudness_info_set_bits(info, &bad_id, 1) == AAC_ERR_INVALID_ARG, "drcSetId 64 counted");
  CHECK(aac_loudness_info_set_write(&b.w, info, ids, 64) == AAC_ERR_INVALID_ARG, "64 entries");
  CHECK(aac_loudness_info_set_write(&b.w, nullptr, ids, 1) == AAC_ERR_INVALID_ARG, "no figures");
}

static void test_measure_and_write() {
  /* The meter's result of a shaped signal, written and read back. */
  std::vector<float> pcm = stereo_sine(4.0, -20.0);
  append(pcm, stereo_sine(4.0, -30.0));
  const AacLoudnessInfo info = measure(pcm, 48000, 2);
  Bits b;
  const int bits = aac_loudness_info_write(&b.w, &info);
  CHECK(bits > 0 && bits == aac_loudness_info_bits(&info), "written %d bits", bits);
  AacBitReader r;
  aac_bitreader_init(&r, b.buf.data(), (int)b.buf.size());
  AacUniDrcLoudnessSet set;
  CHECK(aac_unidrc_parse_loudness(&r, &set) == AAC_OK && r.pos == bits, "parse");
  const AacUniDrcLoudnessInfo* li = &set.info[0];
  CHECK(fabsf(li->sample_peak_db - info.sample_peak_dbfs) <= 0.016f, "sample peak %g against %g",
        li->sample_peak_db, info.sample_peak_dbfs);
  CHECK(li->true_peak_present && fabsf(li->true_peak_db - info.true_peak_dbtp) <= 0.016f,
        "true peak %g against %g", li->true_peak_db, info.true_peak_dbtp);
  CHECK(li->measurement_count == 4, "program loudness, range and the two maxima (%d)",
        li->measurement_count);
  CHECK(fabsf(li->m[0].value - info.integrated_lkfs) <= 0.125f, "program loudness %g against %g",
        li->m[0].value, info.integrated_lkfs);
  CHECK(li->m[1].method == AAC_UNIDRC_METHOD_LOUDNESS_RANGE &&
            fabsf(li->m[1].value - info.range_lu) <= 0.125f,
        "range %g against %g", li->m[1].value, info.range_lu);
  CHECK(fabsf(li->m[2].value - info.max_momentary_lkfs) <= 0.125f, "momentary %g against %g",
        li->m[2].value, info.max_momentary_lkfs);
  CHECK(fabsf(li->m[3].value - info.max_short_term_lkfs) <= 0.125f, "short-term %g against %g",
        li->m[3].value, info.max_short_term_lkfs);
}

int main() {
  test_k_weighting();
  test_levels();
  test_surround();
  test_gating();
  test_maxima();
  test_range();
  test_true_peak();
  test_api();
  test_writer_layout();
  test_writer_sets();
  test_measure_and_write();
  return test_summary("test_loudness");
}
