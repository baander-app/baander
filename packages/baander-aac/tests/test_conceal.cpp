/* Error concealment: damaged, truncated and lost frames of AAC-LC and
 * HE-AAC streams decode to continuous audio instead of errors, the
 * concealed frames are counted, a run of lost frames fades out, an
 * undamaged stream is unaffected, and with concealment off the damaged
 * frames are reported as errors. */
#include "bitstream.h"
#include "decoder.h"
#include "test_util.h"

enum Damage { kIntact = 0, kCorrupt, kTruncate, kHeaderOnly, kLost };

struct Run {
  std::vector<float> out;
  std::vector<int> concealed; /* per frame: aac_decoder_last_frame_concealed */
  std::vector<int> failed;    /* per frame: the decode returned an error */
  std::vector<size_t> start;  /* output offset (in samples per channel) of each frame */
  int channels = 0;
  int errors = 0;
  int concealed_frames = 0;
  int frame_size = 0;
};

static unsigned next(unsigned* seed) {
  *seed = *seed * 1664525u + 1013904223u;
  return *seed >> 8;
}

/* A damage plan: `percent` of the frames (never the first two, which carry
 * the stream's configuration and the SBR header) are damaged, by turns
 * corrupted, truncated and reduced to their header, or all lost. */
static std::vector<Damage> plan_damage(size_t frames, int percent, unsigned seed, bool lost) {
  std::vector<Damage> plan(frames, kIntact);
  const size_t count = std::max<size_t>(1, frames * (size_t)percent / 100);
  size_t done = 0;
  int kind = 0;
  while (done < count) {
    const size_t f = 2 + next(&seed) % (frames - 2);
    if (plan[f] != kIntact) {
      continue;
    }
    kind = kind % 3 + 1;
    plan[f] = lost ? kLost : (Damage)kind;
    done++;
  }
  return plan;
}

/* The frame as the decoder gets it: eight random bytes overwritten
 * somewhere in the first 32 bytes of the payload (the side information
 * of the first channel element, which the parser checks strictly; damage
 * further in may still parse as some other valid spectrum, which no
 * decoder can tell apart), the frame cut in the middle, or only the
 * header. */
static std::vector<uint8_t> damaged_frame(const std::vector<uint8_t>& stream, const AdtsFrame& f,
                                          Damage d, unsigned* seed) {
  std::vector<uint8_t> frame(stream.begin() + (long)f.offset, stream.begin() + (long)(f.offset + f.size));
  switch (d) {
    case kCorrupt: {
      const size_t payload = 9;
      const size_t span = std::max<size_t>(1, std::min<size_t>(32, frame.size() - payload) - 8);
      const size_t at = payload + next(seed) % span;
      for (size_t i = 0; i < 8 && at + i < frame.size(); i++) {
        frame[at + i] = (uint8_t)next(seed);
      }
      break;
    }
    case kTruncate:
      frame.resize(std::max<size_t>(8, frame.size() / 2));
      break;
    case kHeaderOnly:
      frame.resize(7);
      break;
    default:
      break;
  }
  return frame;
}

/* Decodes a stream frame by frame through the public API with the damage
 * plan applied (a lost frame is skipped). */
static Run decode_public(const std::vector<uint8_t>& stream, const std::vector<Damage>& plan, int sr,
                         AacConcealment mode, unsigned seed) {
  Run run;
  AacDecoderHandle dec = aac_decoder_create(sr, 0);
  aac_decoder_set_concealment(dec, mode);
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  const std::vector<AdtsFrame> frames = split_adts(stream);
  for (size_t i = 0; i < frames.size(); i++) {
    if (plan[i] == kLost) {
      continue;
    }
    const std::vector<uint8_t> frame = damaged_frame(stream, frames[i], plan[i], &seed);
    run.start.push_back(run.out.size() / std::max(1, run.channels));
    const int n = aac_decoder_decode(dec, frame.data(), (int)frame.size(), pcm.data(), (int)pcm.size());
    run.concealed.push_back(aac_decoder_last_frame_concealed(dec));
    run.failed.push_back(n < 0);
    if (n < 0) {
      run.errors++;
      continue;
    }
    run.channels = aac_decoder_channels(dec);
    run.frame_size = aac_decoder_frame_size(dec);
    run.out.insert(run.out.end(), pcm.begin(), pcm.begin() + (size_t)n * run.channels);
  }
  run.concealed_frames = aac_decoder_concealed_frames(dec);
  aac_decoder_destroy(dec);
  return run;
}

/* Decodes a stream through the block decoder, replacing lost frames by
 * aac_decoder_conceal_block as a transport layer does. */
static Run decode_internal(const std::vector<uint8_t>& stream, const std::vector<Damage>& plan,
                           int sr, AacConcealment mode) {
  Run run;
  AacDSP dsp;
  aac_dsp_init(&dsp);
  AacDecoderState* s = aac_decoder_state_create(sr, 0, &dsp);
  s->conceal_mode = mode;
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  const std::vector<AdtsFrame> frames = split_adts(stream);
  for (size_t i = 0; i < frames.size(); i++) {
    run.start.push_back(run.out.size() / std::max(1, run.channels));
    int n = 0;
    if (plan[i] == kLost) {
      n = aac_decoder_conceal_block(s);
    } else {
      AacAdtsHeader hdr;
      CHECK(aac_adts_parse(&hdr, stream.data() + frames[i].offset, (int)frames[i].size) == 0,
            "frame %zu: header", i);
      CHECK(aac_decoder_set_rate_index(s, hdr.sample_rate_index) == 0, "rate index");
      CHECK(aac_decoder_set_channel_config(s, hdr.channel_config) == 0, "channel config");
      const int begin = AAC_ADTS_HEADER_SIZE + (hdr.protection_absent ? 0 : 2);
      AacBitReader r;
      aac_bitreader_init(&r, stream.data() + frames[i].offset + begin, (int)frames[i].size - begin);
      n = aac_decode_raw_data_block(s, &r);
    }
    run.concealed.push_back(s->last_concealed);
    run.failed.push_back(n < 0);
    if (n < 0) {
      run.errors++;
      continue;
    }
    run.channels = aac_decoder_output_channels(s);
    run.frame_size = s->frame_size;
    const size_t have = run.out.size();
    run.out.resize(have + (size_t)s->frame_size * run.channels);
    aac_decoder_output(s, run.out.data() + have);
  }
  run.concealed_frames = s->concealed_frames;
  aac_decoder_state_destroy(s);
  return run;
}

static double rms(const float* x, size_t n, int channels, int channel) {
  double e = 0.0;
  for (size_t i = 0; i < n; i++) {
    e += (double)x[i * channels + channel] * x[i * channels + channel];
  }
  return sqrt(e / (double)std::max<size_t>(1, n));
}

static double max_jump(const std::vector<float>& x, int channels, int channel, size_t from, size_t to) {
  const size_t n = x.size() / channels;
  double m = 0.0;
  for (size_t i = std::max<size_t>(from, 1); i < to && i < n; i++) {
    m = std::max(m, fabs((double)x[i * channels + channel] - x[(i - 1) * channels + channel]));
  }
  return m;
}

/* Every concealed frame sits in continuous audio: no sample-to-sample
 * jump above a quarter of full scale (or above three times the largest
 * jump of the undamaged decode there, for signals whose own jumps are
 * that large) from a little before the frame to a little after the
 * first good frame that follows it.  A frame concealed just after a
 * full-band noise burst continues the burst's spectrum where the clean
 * decode has its decaying tail; its first differences reach about two
 * and a half times the tail's. */
static void check_continuity(const char* name, const Run& run, const Run& clean) {
  const int ch = run.channels;
  CHECK(ch == clean.channels, "%s: %d channels against %d", name, ch, clean.channels);
  const size_t n = std::min(run.out.size(), clean.out.size()) / ch;
  for (size_t f = 0; f < run.concealed.size(); f++) {
    if (!run.concealed[f]) {
      continue;
    }
    const size_t from = run.start[f] > 32 ? run.start[f] - 32 : 0;
    size_t to = f + 1 < run.start.size() ? run.start[f + 1] : n;
    to = std::min(n, to + (size_t)run.frame_size + 32);
    for (int c = 0; c < ch; c++) {
      const double jump = max_jump(run.out, ch, c, from, to);
      const double own = max_jump(clean.out, ch, c, from, to);
      CHECK(jump <= std::max(0.25, 3.0 * own), "%s: frame %zu channel %d: jump %.3f (clean %.3f)",
            name, f, c, jump, own);
    }
  }
}

static int count_damage(const std::vector<Damage>& plan) {
  int n = 0;
  for (Damage d : plan) {
    n += d != kIntact;
  }
  return n;
}

/* Frame SNR of a decode against the undamaged one. */
static double frame_snr_db(const Run& run, const Run& clean, size_t f) {
  const size_t a = run.start[f] * run.channels;
  const size_t b = clean.start[f] * clean.channels;
  const size_t n = (size_t)run.frame_size * run.channels;
  if (a + n > run.out.size() || b + n > clean.out.size()) {
    return -100.0;
  }
  return snr_db(clean.out.data() + b, run.out.data() + a, n);
}

/* Damage through the public API.  With concealment off the damaged frames
 * fail (a corruption that still parses as a valid frame is one no decoder
 * can tell from the stream, so a few may slip through; truncation never
 * does) and an intact frame fails only right after a damaged one, when
 * its delta-coded SBR or PS parameters refer to the lost frame.  With
 * concealment on nothing fails, the frames that would have failed and
 * only those are concealed, the output keeps its length and stays
 * continuous, and the good frames away from the damage are unchanged
 * (bit-exact for a stream without perceptual noise substitution, whose
 * noise generator a shorter parse leaves at another point). */
static void check_damaged_stream(const char* name, const std::vector<uint8_t>& stream, int sr,
                                 int percent, unsigned seed, bool exact) {
  const std::vector<AdtsFrame> frames = split_adts(stream);
  const std::vector<Damage> plan = plan_damage(frames.size(), percent, seed, false);
  const std::vector<Damage> intact(frames.size(), kIntact);
  const Run clean = decode_public(stream, intact, sr, AAC_CONCEAL_INTERPOLATE, seed);
  CHECK(clean.errors == 0 && clean.concealed_frames == 0, "%s: clean decode: %d errors, %d concealed",
        name, clean.errors, clean.concealed_frames);

  const Run off = decode_public(stream, plan, sr, AAC_CONCEAL_OFF, seed);
  CHECK(off.concealed_frames == 0, "%s off: %d concealed", name, off.concealed_frames);
  std::vector<int> detected(frames.size(), 0);
  int num_detected = 0;
  int undetected = 0;
  for (size_t f = 0; f < frames.size(); f++) {
    detected[f] = off.failed[f] && plan[f] != kIntact;
    num_detected += detected[f];
    if (plan[f] != kIntact && !off.failed[f]) {
      undetected++;
      CHECK(plan[f] == kCorrupt, "%s off: frame %zu with damage %d decoded without error", name, f,
            plan[f]);
    }
    CHECK(!off.failed[f] || plan[f] != kIntact, "%s off: intact frame %zu failed", name, f);
  }
  CHECK(undetected * 5 <= count_damage(plan), "%s off: %d of %d damaged frames decoded without error",
        name, undetected, count_damage(plan));

  for (int mode = AAC_CONCEAL_NOISE; mode <= AAC_CONCEAL_INTERPOLATE; mode++) {
    const Run run = decode_public(stream, plan, sr, (AacConcealment)mode, seed);
    CHECK(run.errors == 0, "%s %d%% mode %d: %d errors", name, percent, mode, run.errors);
    CHECK(run.concealed_frames == num_detected, "%s %d%% mode %d: %d concealed, %d detected", name,
          percent, mode, run.concealed_frames, num_detected);
    for (size_t f = 0; f < frames.size(); f++) {
      CHECK(run.concealed[f] == detected[f], "%s mode %d: frame %zu concealed %d, detected %d", name,
            mode, f, run.concealed[f], detected[f]);
    }
    CHECK(run.out.size() == clean.out.size(), "%s mode %d: %zu samples, clean %zu", name, mode,
          run.out.size(), clean.out.size());
    CHECK(run.frame_size == clean.frame_size, "%s mode %d: frame size %d", name, mode, run.frame_size);
    for (float v : run.out) {
      if (!std::isfinite(v)) {
        CHECK(false, "%s mode %d: non-finite output", name, mode);
        break;
      }
    }
    check_continuity(name, run, clean);
    double worst = 200.0;
    double worst_level = 0.0;
    size_t worst_frame = 0;
    for (size_t f = 4; f + 1 < frames.size(); f++) {
      if (plan[f] != kIntact || plan[f - 1] != kIntact || plan[f - 2] != kIntact ||
          plan[f - 3] != kIntact || plan[f + 1] != kIntact) {
        continue;
      }
      const size_t n = (size_t)clean.frame_size * clean.channels;
      const double level = energy_db(run.out.data() + run.start[f] * run.channels, n) -
                           energy_db(clean.out.data() + clean.start[f] * clean.channels, n);
      if (fabs(level) > fabs(worst_level)) {
        worst_level = level;
      }
      const double snr = frame_snr_db(run, clean, f);
      if (snr < worst) {
        worst = snr;
        worst_frame = f;
      }
    }
    if (exact) {
      CHECK(worst >= 200.0, "%s mode %d: undisturbed frame %zu at %.1f dB from the clean decode",
            name, mode, worst_frame, worst);
    } else {
      CHECK(fabs(worst_level) < 3.0, "%s mode %d: an undisturbed frame is %.1f dB off the clean decode",
            name, mode, worst_level);
    }
  }
  printf("%s: %d%% damage: %d of %d damaged frames detected and concealed\n", name, percent,
         num_detected, count_damage(plan));
}

/* Lost frames through the block decoder: a run of eight lost frames
 * fades out along the 3 dB per frame line (a random-phase continuation
 * of a sparse spectrum varies in level from frame to frame, so each
 * frame is allowed 6 dB above the line), the stream around it is
 * continuous, and the frame size stays the stream's (SBR does not drop
 * out). */
static void check_lost_frames(const char* name, const std::vector<uint8_t>& stream, int sr,
                              AacConcealment mode) {
  const std::vector<AdtsFrame> frames = split_adts(stream);
  std::vector<Damage> plan = plan_damage(frames.size(), 5, 31u + (unsigned)mode, true);
  const size_t run_start = frames.size() / 2;
  const size_t run_len = 8;
  for (size_t f = run_start - 2; f < run_start + run_len + 2 && f < frames.size(); f++) {
    plan[f] = f >= run_start && f < run_start + run_len ? kLost : kIntact;
  }
  const std::vector<Damage> intact(frames.size(), kIntact);
  const Run clean = decode_internal(stream, intact, sr, mode);
  const Run run = decode_internal(stream, plan, sr, mode);
  CHECK(run.errors == 0, "%s lost mode %d: %d errors", name, mode, run.errors);
  CHECK(run.concealed_frames == count_damage(plan), "%s lost mode %d: %d concealed of %d", name,
        mode, run.concealed_frames, count_damage(plan));
  CHECK(run.out.size() == clean.out.size(), "%s lost mode %d: length %zu against %zu", name, mode,
        run.out.size(), clean.out.size());
  CHECK(run.frame_size == clean.frame_size, "%s lost: frame size %d", name, run.frame_size);
  check_continuity(name, run, clean);
  const int ch = run.channels;
  const size_t fs = (size_t)run.frame_size;
  for (int c = 0; c < ch; c++) {
    const double good = rms(run.out.data() + run.start[run_start - 1] * ch, fs, ch, c);
    const double first = rms(run.out.data() + run.start[run_start + 1] * ch, fs, ch, c);
    for (size_t f = run_start + 2; f < run_start + run_len; f++) {
      const double now = rms(run.out.data() + run.start[f] * ch, fs, ch, c);
      const double line = first * pow(0.70710678, (double)(f - run_start - 1));
      CHECK(now < 2.0 * line, "%s mode %d ch %d: frame %zu RMS %.4f, fade line %.4f", name, mode,
            c, f, now, line);
    }
    const double last = rms(run.out.data() + run.start[run_start + run_len - 1] * ch, fs, ch, c);
    CHECK(last < 0.3 * good, "%s mode %d ch %d: after %zu lost frames RMS %.4f of %.4f", name, mode,
          c, run_len, last, good);
  }
  printf("%s: %d lost frames (mode %d): fade from %.4f to %.4f\n", name, count_damage(plan), mode,
         rms(run.out.data() + run.start[run_start - 1] * ch, fs, ch, 0),
         rms(run.out.data() + run.start[run_start + run_len - 1] * ch, fs, ch, 0));
}

/* An undamaged stream decodes the same with concealment on and off. */
static void check_transparent(const char* name, const std::vector<uint8_t>& stream, int sr) {
  const std::vector<AdtsFrame> frames = split_adts(stream);
  const std::vector<Damage> intact(frames.size(), kIntact);
  const Run on = decode_public(stream, intact, sr, AAC_CONCEAL_INTERPOLATE, 1u);
  const Run off = decode_public(stream, intact, sr, AAC_CONCEAL_OFF, 1u);
  CHECK(on.errors == 0 && off.errors == 0, "%s: errors %d / %d", name, on.errors, off.errors);
  CHECK(on.concealed_frames == 0 && off.concealed_frames == 0, "%s: concealed %d / %d", name,
        on.concealed_frames, off.concealed_frames);
  CHECK(on.out.size() == off.out.size() && !on.out.empty(), "%s: lengths %zu / %zu", name,
        on.out.size(), off.out.size());
  CHECK(on.out.size() == off.out.size() &&
            memcmp(on.out.data(), off.out.data(), on.out.size() * sizeof(float)) == 0,
        "%s: concealment on and off differ on an undamaged stream", name);
}

static void test_api() {
  AacDecoderHandle dec = aac_decoder_create(44100, 2);
  CHECK(aac_decoder_set_concealment(dec, (AacConcealment)7) == AAC_ERR_INVALID_ARG, "bad mode");
  CHECK(aac_decoder_set_concealment(dec, AAC_CONCEAL_NOISE) == AAC_OK, "set mode");
  CHECK(aac_decoder_last_frame_concealed(dec) == 0, "fresh decoder: last frame");
  CHECK(aac_decoder_concealed_frames(dec) == 0, "fresh decoder: count");
  CHECK(aac_decoder_set_concealment(nullptr, AAC_CONCEAL_OFF) == AAC_ERR_INVALID_ARG, "null");
  /* Before any frame there is nothing to conceal with: errors stay. */
  const uint8_t junk[16] = {0xFF, 0xF1, 0x50, 0x80, 0x02, 0x1F, 0xFC, 0x00, 0x00};
  float pcm[8192];
  CHECK(aac_decoder_decode(dec, junk, 9, pcm, 8192) < 0, "junk before a layout is known");
  CHECK(aac_decoder_concealed_frames(dec) == 0, "nothing concealed before a layout is known");
  aac_decoder_destroy(dec);

  AacDSP dsp;
  aac_dsp_init(&dsp);
  AacDecoderState* s = aac_decoder_state_create(44100, 0, &dsp);
  CHECK(s->conceal_mode == AAC_CONCEAL_INTERPOLATE, "default mode");
  CHECK(aac_decoder_conceal_block(s) < 0, "conceal_block without a layout");
  aac_decoder_state_destroy(s);
}

int main() {
  test_api();

  const int sr = 44100;
  const std::vector<float> pcm = gen_tones(sr * 3, sr, 2);
  int err = 0;
  const std::vector<uint8_t> lc = encode_stream(pcm, sr, 2, 128000, AAC_RC_CBR, 0, &err);
  CHECK(err == 0 && !lc.empty(), "encode the LC test stream");
  const std::vector<float> bursts = gen_transients(sr * 3, sr, 2);
  const std::vector<uint8_t> lc_short = encode_stream(bursts, sr, 2, 96000, AAC_RC_CBR, 0, &err);
  CHECK(err == 0 && !lc_short.empty(), "encode the LC transient stream");
  const std::vector<uint8_t> he_stereo = read_file(data_path("he_stereo_96k.aac"));
  const std::vector<uint8_t> he_mono = read_file(data_path("he_mono_24k.aac"));
  const std::vector<uint8_t> hev2 = read_file(data_path("hev2_stereo_32k.aac"));
  CHECK(!he_stereo.empty() && !he_mono.empty() && !hev2.empty(), "HE-AAC streams present");

  check_transparent("lc", lc, sr);
  check_transparent("lc-short", lc_short, sr);
  check_transparent("he-stereo", he_stereo, 48000);
  check_transparent("hev2", hev2, 48000);

  check_damaged_stream("lc", lc, sr, 1, 11u, true);
  check_damaged_stream("lc", lc, sr, 10, 12u, true);
  check_damaged_stream("lc-short", lc_short, sr, 10, 13u, false);
  check_damaged_stream("he-stereo", he_stereo, 48000, 10, 14u, false);
  check_damaged_stream("he-mono", he_mono, 48000, 10, 15u, false);
  check_damaged_stream("hev2", hev2, 48000, 10, 16u, false);

  check_lost_frames("lc", lc, sr, AAC_CONCEAL_INTERPOLATE);
  check_lost_frames("lc", lc, sr, AAC_CONCEAL_NOISE);
  check_lost_frames("lc-short", lc_short, sr, AAC_CONCEAL_INTERPOLATE);
  check_lost_frames("he-stereo", he_stereo, 48000, AAC_CONCEAL_INTERPOLATE);
  check_lost_frames("hev2", hev2, 48000, AAC_CONCEAL_INTERPOLATE);
  return test_summary("test_conceal");
}
