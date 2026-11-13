/* The FLAC decoder against streams it did not write.
 *
 * The committed fixtures (tests/data/flac) are short synthetic signals
 * encoded by the reference encoder, flac 1.4.3, and by FFmpeg, each with
 * the MD5 of its source recorded in fixtures.txt; they need nothing from
 * outside the repository.
 *
 * The FLAC decoder testbench (ietf-wg-cellar/flac-test-files) is fetched
 * by tests/flac-testbench.sh and the reference decoder is built by
 * tests/flac-reference.sh; the suite takes them from the environment:
 *
 *   BAAC_FLAC_TESTBENCH   the testbench directory
 *   BAAC_FLAC_REFERENCE   the path of the flac 1.4.3 binary
 *   BAAC_REQUIRE_ORACLES  set: a missing one of the two fails the suite
 *                         instead of skipping its checks
 *
 * Every file of the testbench's `subset` and the decodable files of
 * `uncommon` must decode without an error to the MD5 their STREAMINFO
 * states and, byte for byte, to what `flac -d` writes.  The four
 * `uncommon` files that change their sample rate, channel count or bit
 * depth must decode up to the change and stop there as unsupported, and
 * the `faulty` files must end in an error or a clean finish.
 *
 * `test_flac_conformance --make-fixtures` regenerates the fixtures with
 * the reference encoder named by BAAC_FLAC_REFERENCE and the ffmpeg on
 * the path. */
#include <dirent.h>
#include <sys/stat.h>
#include <unistd.h>

#include "test_flac_util.h"

static std::string g_reference; /* the flac binary, empty when missing */
static std::string g_testbench; /* the testbench directory, empty when missing */

/* ── decoding a file, with or without the reference beside it ──── */

/* The reference decoder's output as a stream of bytes.  flac writes raw
 * samples only for depths of whole bytes and only with a STREAMINFO to
 * take the depth from; otherwise it writes a WAVE file, whose samples
 * sit left-justified in whole bytes behind the header. */
struct Reference {
  FILE* pipe = nullptr;
  bool wave = false;  /* a WAVE file: a header before the samples, a pad byte after an odd
                       * number of them */
  int shift = 0;      /* left shift of each sample in its container */
  uint64_t bytes = 0; /* compared so far */
  bool equal = true;
  bool complete = false; /* the reference ended where the decode did */

  bool open(const std::string& path, const char* options, bool raw, int bits) {
    std::string cmd = shell_quote(g_reference) + " -d -s -c " + options;
    if (raw) {
      cmd += " --force-raw-format --endian=little --sign=signed";
    }
    cmd += " " + shell_quote(path) + " 2>/dev/null";
    pipe = popen(cmd.c_str(), "r");
    if (!pipe) {
      return false;
    }
    wave = !raw;
    if (wave) {
      /* Skip to the samples: RIFF, a size, WAVE, then chunks up to
       * "data". */
      uint8_t head[12];
      if (fread(head, 1, 12, pipe) != 12 || memcmp(head, "RIFF", 4) != 0) {
        return false;
      }
      for (;;) {
        uint8_t chunk[8];
        if (fread(chunk, 1, 8, pipe) != 8) {
          return false;
        }
        if (memcmp(chunk, "data", 4) == 0) {
          break;
        }
        size_t size = (size_t)chunk[4] | ((size_t)chunk[5] << 8) | ((size_t)chunk[6] << 16) |
                      ((size_t)chunk[7] << 24);
        uint8_t skip[256];
        while (size) {
          const size_t n = fread(skip, 1, std::min(size, sizeof(skip)), pipe);
          if (n == 0) {
            return false;
          }
          size -= n;
        }
      }
      shift = (bits + 7) / 8 * 8 - bits;
    }
    return true;
  }

  /* Compares interleaved samples with the next bytes of the reference. */
  void compare(const int32_t* pcm, size_t count, int bits) {
    const int width = (bits + 7) / 8;
    std::vector<uint8_t> ours(count * (size_t)width);
    for (size_t i = 0; i < count; i++) {
      const uint32_t v = (uint32_t)pcm[i] << shift;
      for (int b = 0; b < width; b++) {
        ours[i * (size_t)width + (size_t)b] = (uint8_t)(v >> (8 * b));
      }
    }
    std::vector<uint8_t> theirs(ours.size());
    const size_t got = fread(theirs.data(), 1, theirs.size(), pipe);
    if (got != ours.size() || memcmp(ours.data(), theirs.data(), got) != 0) {
      equal = false;
    }
    bytes += got;
  }

  void close() {
    if (pipe) {
      uint8_t extra;
      if (wave && (bytes & 1)) {
        /* A chunk of odd length is followed by a pad byte. */
        equal = equal && fread(&extra, 1, 1, pipe) == 1 && extra == 0;
      }
      complete = fread(&extra, 1, 1, pipe) == 0;
      pclose(pipe);
      pipe = nullptr;
    }
  }
};

struct Outcome {
  bool opened = false;
  long frames = 0;
  uint64_t samples = 0;
  std::vector<int> errors;
  int finish = AAC_OK;
  BaacFlacMd5Verdict verdict = BAAC_FLAC_MD5_UNKNOWN;
  int lost = 0;
  bool have_info = false;
  BaacFlacStreamInfo info = {};
  bool positions_run = true; /* every frame begins where the one before it ended */
  int block_sizes = 0;       /* distinct block sizes among all frames but the last */
  bool compared = false;     /* the reference decoder ran beside the decode */
  bool equal = false;        /* and wrote the same bytes, no more and no fewer */
  uint64_t reference_bytes = 0;
};

/* Decodes a file through the streaming API with MD5 verification, fed
 * `chunk` bytes at a time.  With `reference_options` (extra options for
 * `flac -d`, possibly none) the reference decoder runs on the same file
 * and its output is compared as the frames come. */
static Outcome decode_file(const std::string& path, const char* reference_options,
                           size_t chunk = 65536) {
  Outcome out;
  FILE* f = fopen(path.c_str(), "rb");
  if (!f) {
    return out;
  }
  out.opened = true;
  BaacFlacDecoder* dec = baac_flac_decoder_create();
  baac_flac_decoder_set_md5_check(dec, 1);
  std::vector<int32_t> pcm((size_t)BAAC_FLAC_MAX_BLOCK_SIZE * BAAC_FLAC_MAX_CHANNELS);
  std::vector<uint8_t> in(chunk);
  Reference reference;
  uint64_t expected_position = 0;
  int previous_block = 0;
  std::vector<int> sizes;
  bool alive = true;
  bool eof = false;
  size_t have = 0;
  size_t used = 0;
  while (alive) {
    bool progress = false;
    if (used == have && !eof) {
      have = fread(in.data(), 1, in.size(), f);
      used = 0;
      progress = true;
      if (have == 0) {
        eof = true;
        baac_flac_decoder_end_input(dec);
      }
    }
    if (used < have) {
      const int took = baac_flac_decoder_feed(dec, in.data() + used, (int)(have - used));
      if (took < 0) {
        out.errors.push_back(took);
        break;
      }
      used += (size_t)took;
      progress = progress || took > 0;
    }
    for (;;) {
      const int n = baac_flac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
      if (n == 0) {
        break;
      }
      progress = true;
      if (n < 0) {
        out.errors.push_back(n);
        if (n != AAC_ERR_DECODE) {
          alive = false;
          break;
        }
        continue;
      }
      BaacFlacStreamInfo info;
      BaacFlacFrameInfo frame;
      baac_flac_decoder_stream_info(dec, &info);
      baac_flac_decoder_last_frame_info(dec, &frame);
      if (out.frames == 0) {
        expected_position = frame.sample_position;
        if (reference_options && !g_reference.empty()) {
          const bool raw = info.max_block_size != 0 && info.bits_per_sample % 8 == 0;
          out.compared = reference.open(path, reference_options, raw, info.bits_per_sample);
        }
      } else if (std::find(sizes.begin(), sizes.end(), previous_block) == sizes.end()) {
        sizes.push_back(previous_block);
      }
      if (frame.sample_position != expected_position || frame.block_size != n) {
        out.positions_run = false;
      }
      expected_position = frame.sample_position + (uint64_t)n;
      previous_block = n;
      if (out.compared) {
        reference.compare(pcm.data(), (size_t)n * (size_t)info.channels, info.bits_per_sample);
      }
      out.frames++;
      out.samples += (uint64_t)n;
    }
    if (eof && used == have) {
      break;
    }
    if (!progress) {
      /* The decoder neither takes input nor gives output. */
      out.errors.push_back(AAC_ERR_STATE);
      break;
    }
  }
  fclose(f);
  out.block_sizes = (int)sizes.size();
  out.have_info = baac_flac_decoder_stream_info(dec, &out.info) == AAC_OK;
  out.finish = baac_flac_decoder_finish(dec, &out.verdict);
  out.lost = baac_flac_decoder_lost_frames(dec);
  baac_flac_decoder_destroy(dec);
  if (out.compared) {
    reference.close();
    out.equal = reference.equal && reference.complete;
    out.reference_bytes = reference.bytes;
  }
  return out;
}

static std::string describe(const Outcome& o) {
  char buf[256];
  std::string errors;
  for (size_t i = 0; i < o.errors.size() && i < 4; i++) {
    errors += (i ? "," : "") + std::to_string(o.errors[i]);
  }
  if (o.errors.size() > 4) {
    errors += ",...";
  }
  snprintf(buf, sizeof(buf), "%ld frames, %llu samples, errors [%s], %d lost, finish %d, MD5 %s",
           o.frames, (unsigned long long)o.samples, errors.c_str(), o.lost, o.finish,
           o.verdict == BAAC_FLAC_MD5_MATCH      ? "match"
           : o.verdict == BAAC_FLAC_MD5_MISMATCH ? "mismatch"
                                                 : "unknown");
  return buf;
}

/* The .flac files of a directory in name order. */
static std::vector<std::string> flac_files(const std::string& dir) {
  std::vector<std::string> names;
  DIR* d = opendir(dir.c_str());
  if (!d) {
    return names;
  }
  while (const dirent* e = readdir(d)) {
    const std::string name = e->d_name;
    if (name.size() > 5 && name.compare(name.size() - 5, 5, ".flac") == 0) {
      names.push_back(name);
    }
  }
  closedir(d);
  std::sort(names.begin(), names.end());
  return names;
}

/* ── oracles ───────────────────────────────────────────────────── */

/* The reference decoder is the binary BAAC_FLAC_REFERENCE names, and it
 * has to report the pinned version. */
static void find_reference() {
  const char* path = getenv("BAAC_FLAC_REFERENCE");
  if (!path || !*path) {
    return;
  }
  const std::string version = run_capture(shell_quote(path) + " --version 2>/dev/null");
  CHECK(version == "flac 1.4.3", "BAAC_FLAC_REFERENCE (%s) reports \"%s\", expected \"flac 1.4.3\"",
        path, version.c_str());
  if (version == "flac 1.4.3") {
    g_reference = path;
  }
}

static void find_testbench() {
  const char* path = getenv("BAAC_FLAC_TESTBENCH");
  if (!path || !*path) {
    return;
  }
  const size_t subset = flac_files(std::string(path) + "/subset").size();
  const size_t uncommon = flac_files(std::string(path) + "/uncommon").size();
  const size_t faulty = flac_files(std::string(path) + "/faulty").size();
  CHECK(subset == 64 && uncommon == 11 && faulty == 11,
        "BAAC_FLAC_TESTBENCH (%s) holds %zu, %zu and %zu files in subset, uncommon and faulty, "
        "expected 64, 11 and 11",
        path, subset, uncommon, faulty);
  if (subset == 64 && uncommon == 11 && faulty == 11) {
    g_testbench = path;
  }
}

/* ── committed fixtures ────────────────────────────────────────── */

struct Fixture {
  const char* name;
  const char* encoder; /* "flac" or "ffmpeg" */
  int sample_rate;
  int channels;
  int bits;
  int samples;
  const char* options; /* of the encoder */
  int signal;          /* 0 tones; 1 tones with silence and low bits that are zero */
};

/* Both encoders across the depths, layouts and rates they take, with
 * options that make them use different parts of the format: fixed and
 * linear predictors, every stereo assignment, small and large blocks,
 * partition orders and predictor orders beyond the streamable subset. */
static const Fixture kFixtures[] = {
    {"flac_8k_mono_8", "flac", 8000, 1, 8, 6000, "-0", 0},
    {"flac_44k_stereo_16", "flac", 44100, 2, 16, 9000, "-5", 0},
    {"flac_48k_stereo_24", "flac", 48000, 2, 24, 5000, "-8", 0},
    {"flac_96k_stereo_32", "flac", 96000, 2, 32, 3000, "-5", 0},
    {"flac_192k_mono_24", "flac", 192000, 1, 24, 6000, "-7 -b 1024", 0},
    {"flac_22k_3ch_16", "flac", 22050, 3, 16, 3000, "-3", 0},
    {"flac_32k_4ch_8", "flac", 32000, 4, 8, 3000, "-2", 0},
    {"flac_16k_5ch_16", "flac", 16000, 5, 16, 2000, "-1", 0},
    {"flac_48k_6ch_16", "flac", 48000, 6, 16, 3000, "-5", 0},
    {"flac_44k_7ch_16", "flac", 44100, 7, 16, 2500, "-4", 0},
    {"flac_48k_8ch_24", "flac", 48000, 8, 24, 1500, "-8", 0},
    {"flac_44k_stereo_16_wasted", "flac", 44100, 2, 16, 9000, "-6", 1},
    {"flac_44k_mono_16_lax", "flac", 44100, 1, 16, 12000, "--lax -l 32 -r 15 -b 8192 -q 15", 0},
    {"flac_11k_stereo_16_small_blocks", "flac", 11025, 2, 16, 4000, "-5 -b 192 -m", 0},
    {"ffmpeg_44k_stereo_16", "ffmpeg", 44100, 2, 16, 9000, "-compression_level 5", 0},
    {"ffmpeg_48k_stereo_24", "ffmpeg", 48000, 2, 24, 5000, "-compression_level 8", 0},
    {"ffmpeg_192k_stereo_32", "ffmpeg", 192000, 2, 32, 3000,
     "-compression_level 5 -strict experimental", 0},
    {"ffmpeg_8k_mono_16", "ffmpeg", 8000, 1, 16, 6000, "-compression_level 0", 0},
    {"ffmpeg_96k_6ch_24", "ffmpeg", 96000, 6, 24, 1500, "-compression_level 12", 0},
    {"ffmpeg_48k_8ch_16", "ffmpeg", 48000, 8, 16, 2000, "-compression_level 5", 0},
    {"ffmpeg_44k_stereo_16_left_side", "ffmpeg", 44100, 2, 16, 5000,
     "-ch_mode left_side -frame_size 256", 0},
    {"ffmpeg_44k_stereo_16_right_side", "ffmpeg", 44100, 2, 16, 5000,
     "-ch_mode right_side -lpc_type fixed", 0},
    {"ffmpeg_44k_stereo_16_mid_side", "ffmpeg", 44100, 2, 16, 5000,
     "-ch_mode mid_side -lpc_type cholesky -lpc_passes 2 -max_partition_order 8", 1},
};

static std::vector<int32_t> fixture_signal(const Fixture& fx, unsigned seed) {
  std::vector<int32_t> pcm = flac_gen_tones(fx.samples, fx.channels, fx.bits, 0.5, seed, 0.0006);
  if (fx.signal == 1) {
    /* The four low bits zero, as 12-bit audio in a 16-bit file has them,
     * and a stretch of digital silence. */
    for (size_t i = 0; i < pcm.size(); i++) {
      pcm[i] = (int32_t)((uint32_t)pcm[i] & ~15u);
      if (i / (size_t)fx.channels >= 2000 && i / (size_t)fx.channels < 6500) {
        pcm[i] = 0;
      }
    }
  }
  return pcm;
}

static std::string fixture_dir() { return data_path("flac"); }

struct ManifestEntry {
  std::string name;
  std::string md5;
  int sample_rate = 0;
  int channels = 0;
  int bits = 0;
  long samples = 0;
};

static std::vector<ManifestEntry> read_manifest() {
  std::vector<ManifestEntry> entries;
  FILE* f = fopen((fixture_dir() + "/fixtures.txt").c_str(), "r");
  if (!f) {
    return entries;
  }
  char line[512];
  while (fgets(line, sizeof(line), f)) {
    char name[128];
    char md5[64];
    ManifestEntry e;
    if (line[0] != '#' && sscanf(line, "%127s %63s %d %d %d %ld", name, md5, &e.sample_rate,
                                 &e.channels, &e.bits, &e.samples) == 6) {
      e.name = name;
      e.md5 = md5;
      entries.push_back(e);
    }
  }
  fclose(f);
  return entries;
}

/* Every committed stream decodes, in two chunkings, to samples whose
 * MD5 is the one recorded from the source signal when the stream was
 * made, and agrees with what its STREAMINFO says. */
static void test_fixtures() {
  const std::vector<ManifestEntry> entries = read_manifest();
  CHECK(entries.size() == sizeof(kFixtures) / sizeof(kFixtures[0]),
        "fixtures.txt lists %zu streams, expected %zu", entries.size(),
        sizeof(kFixtures) / sizeof(kFixtures[0]));
  int checked = 0;
  int from_flac = 0;
  int from_ffmpeg = 0;
  bool depths[33] = {false};
  bool layouts[9] = {false};
  int min_rate = INT32_MAX;
  int max_rate = 0;
  size_t total_bytes = 0;
  for (const ManifestEntry& e : entries) {
    const std::vector<uint8_t> stream = read_file(fixture_dir() + "/" + e.name + ".flac");
    CHECK(!stream.empty(), "fixture %s is missing", e.name.c_str());
    if (stream.empty()) {
      continue;
    }
    total_bytes += stream.size();
    for (size_t chunk : {SIZE_MAX, (size_t)511}) {
      const FlacDecoded d = flac_decode(stream, chunk);
      CHECK(d.errors.empty() && d.finish == AAC_OK && d.lost == 0, "fixture %s: decodes cleanly",
            e.name.c_str());
      CHECK(d.have_info && d.info.sample_rate == e.sample_rate && d.info.channels == e.channels &&
                d.info.bits_per_sample == e.bits,
            "fixture %s: %d Hz, %d channels, %d bits", e.name.c_str(), d.info.sample_rate,
            d.info.channels, d.info.bits_per_sample);
      CHECK(d.pcm.size() == (size_t)e.samples * (size_t)e.channels, "fixture %s: %zu values of %ld",
            e.name.c_str(), d.pcm.size(), e.samples * e.channels);
      uint8_t digest[16];
      flac_pcm_md5(d.pcm, e.bits, digest);
      CHECK(hex_string(digest, 16) == e.md5, "fixture %s: decodes to MD5 %s, its source has %s",
            e.name.c_str(), hex_string(digest, 16).c_str(), e.md5.c_str());
      CHECK(d.verdict == BAAC_FLAC_MD5_MATCH, "fixture %s: the MD5 in STREAMINFO matches",
            e.name.c_str());
    }
    checked++;
    from_flac += e.name.compare(0, 5, "flac_") == 0;
    from_ffmpeg += e.name.compare(0, 7, "ffmpeg_") == 0;
    if (e.bits >= 0 && e.bits <= 32) {
      depths[e.bits] = true;
    }
    if (e.channels >= 0 && e.channels <= 8) {
      layouts[e.channels] = true;
    }
    min_rate = std::min(min_rate, e.sample_rate);
    max_rate = std::max(max_rate, e.sample_rate);
  }
  bool all_layouts = true;
  for (int c = 1; c <= 8; c++) {
    all_layouts = all_layouts && layouts[c];
  }
  CHECK(depths[8] && depths[16] && depths[24] && depths[32],
        "the fixtures cover 8, 16, 24 and 32 bits");
  CHECK(all_layouts, "the fixtures cover one to eight channels");
  CHECK(min_rate == 8000 && max_rate == 192000, "the fixtures span 8 kHz to 192 kHz (%d to %d)",
        min_rate, max_rate);
  CHECK(from_flac > 0 && from_ffmpeg > 0, "the fixtures come from both encoders");
  CHECK(total_bytes < 300000, "the fixtures take %zu bytes, the limit is 300 KB", total_bytes);
  printf(
      "fixtures: %d streams decoded to their recorded MD5 (%d from flac, %d from FFmpeg), "
      "%zu bytes\n",
      checked, from_flac, from_ffmpeg, total_bytes);
}

/* Writes the fixtures and fixtures.txt.  The MD5 of each source is
 * computed here from the signal and checked against md5sum over the raw
 * file the encoder was given. */
static int make_fixtures() {
  find_reference();
  if (g_reference.empty() || !have_ffmpeg()) {
    printf("--make-fixtures needs BAAC_FLAC_REFERENCE (flac 1.4.3) and ffmpeg\n");
    return 1;
  }
  const std::string dir = fixture_dir();
  mkdir(dir.c_str(), 0755);
  const std::string tmp = make_temp_dir();
  FILE* manifest = fopen((dir + "/fixtures.txt").c_str(), "w");
  if (tmp.empty() || !manifest) {
    printf("cannot write to %s\n", dir.c_str());
    return 1;
  }
  fprintf(
      manifest,
      "# Streams for test_flac_conformance, written by `test_flac_conformance --make-fixtures`\n"
      "# with flac 1.4.3 and FFmpeg from the suite's synthetic signals.\n"
      "# name, MD5 of the source samples, sample rate, channels, bits per sample, samples\n");
  unsigned seed = 1000u;
  for (const Fixture& fx : kFixtures) {
    const std::vector<int32_t> pcm = fixture_signal(fx, seed++);
    const int width = fx.bits / 8;
    std::vector<uint8_t> raw;
    for (int32_t v : pcm) {
      for (int b = 0; b < width; b++) {
        raw.push_back((uint8_t)((uint32_t)v >> (8 * b)));
      }
    }
    const std::string raw_path = tmp + "/in.raw";
    const std::string out_path = dir + "/" + fx.name + ".flac";
    write_file(raw_path, raw.data(), raw.size());
    uint8_t digest[16];
    flac_pcm_md5(pcm, fx.bits, digest);
    const std::string md5 = hex_string(digest, 16);
    const std::string md5sum = run_capture("md5sum < " + shell_quote(raw_path)).substr(0, 32);
    CHECK(md5 == md5sum, "%s: MD5 %s, md5sum says %s", fx.name, md5.c_str(), md5sum.c_str());
    std::string cmd;
    if (strcmp(fx.encoder, "flac") == 0) {
      cmd = shell_quote(g_reference) + " -s -f --no-padding --force-raw-format --endian=little" +
            " --sign=signed --channels=" + std::to_string(fx.channels) +
            " --bps=" + std::to_string(fx.bits) +
            " --sample-rate=" + std::to_string(fx.sample_rate) + " " + fx.options + " -o " +
            shell_quote(out_path) + " " + shell_quote(raw_path);
    } else {
      const char* layout = fx.channels == 6   ? " -channel_layout 5.1"
                           : fx.channels == 8 ? " -channel_layout 7.1"
                                              : "";
      cmd = "ffmpeg -v error -y -f s" + std::to_string(fx.bits) + "le -ar " +
            std::to_string(fx.sample_rate) + " -ac " + std::to_string(fx.channels) + layout +
            " -i " + shell_quote(raw_path) + " -c:a flac " + fx.options +
            " -bitexact -metadata_header_padding 0 " + shell_quote(out_path);
    }
    CHECK(system(cmd.c_str()) == 0, "%s: %s", fx.name, cmd.c_str());
    CHECK(run_command(shell_quote(g_reference) + " -t -s " + shell_quote(out_path)),
          "%s: flac -t accepts the stream", fx.name);
    fprintf(manifest, "%s %s %d %d %d %d\n", fx.name, md5.c_str(), fx.sample_rate, fx.channels,
            fx.bits, fx.samples);
    printf("%-36s %7zu bytes  %s\n", fx.name, read_file(out_path).size(), cmd.c_str());
  }
  fclose(manifest);
  remove_temp_dir(tmp);
  return test_summary("test_flac_conformance --make-fixtures");
}

/* ── the testbench ─────────────────────────────────────────────── */

/* A file that has to decode in full: no error, every frame where the one
 * before it ended, the MD5 of STREAMINFO matched when it states one, and
 * the reference decoder's bytes.  Returns whether the bytes were
 * compared and equal. */
static bool expect_decodes(const std::string& dir, const std::string& name,
                           const char* reference_options, bool has_md5) {
  const Outcome o = decode_file(dir + "/" + name, reference_options);
  CHECK(o.opened && o.frames > 0 && o.errors.empty() && o.lost == 0 && o.finish == AAC_OK, "%s: %s",
        name.c_str(), describe(o).c_str());
  CHECK(o.positions_run, "%s: a frame does not begin where the one before it ended", name.c_str());
  CHECK(o.verdict == (has_md5 ? BAAC_FLAC_MD5_MATCH : BAAC_FLAC_MD5_UNKNOWN), "%s: MD5 verdict %d",
        name.c_str(), (int)o.verdict);
  if (o.have_info && o.info.total_samples) {
    CHECK(o.samples == o.info.total_samples, "%s: %llu samples decoded, STREAMINFO says %llu",
          name.c_str(), (unsigned long long)o.samples, (unsigned long long)o.info.total_samples);
  }
  if (o.compared) {
    CHECK(o.equal, "%s: differs from flac -d within %llu bytes", name.c_str(),
          (unsigned long long)o.reference_bytes);
  }
  return o.compared && o.equal;
}

static void test_subset() {
  const std::string dir = g_testbench + "/subset";
  int decoded = 0;
  int equal = 0;
  int variable = 0;
  for (const std::string& name : flac_files(dir)) {
    const Outcome o = decode_file(dir + "/" + name, "");
    const bool clean = o.opened && o.frames > 0 && o.errors.empty() && o.lost == 0 &&
                       o.finish == AAC_OK && o.verdict == BAAC_FLAC_MD5_MATCH && o.positions_run;
    CHECK(clean, "subset/%s: %s", name.c_str(), describe(o).c_str());
    if (o.have_info && o.info.total_samples) {
      CHECK(o.samples == o.info.total_samples, "subset/%s: %llu samples, STREAMINFO says %llu",
            name.c_str(), (unsigned long long)o.samples, (unsigned long long)o.info.total_samples);
    }
    decoded += clean;
    if (o.compared) {
      CHECK(o.equal, "subset/%s: differs from flac -d within %llu bytes", name.c_str(),
            (unsigned long long)o.reference_bytes);
      equal += o.equal;
    }
    /* Files 24 to 27 have blocks of varying size: each frame reported
     * its own size and position, which positions_run has checked. */
    const int number = atoi(name.c_str());
    if (number >= 24 && number <= 27) {
      CHECK(o.block_sizes > 1, "subset/%s: %d block sizes, expected a variable block size",
            name.c_str(), o.block_sizes);
      variable += o.block_sizes > 1;
    }
  }
  CHECK(decoded == 64, "subset: %d of 64 files decode to the MD5 in their STREAMINFO", decoded);
  printf(
      "subset: %d of 64 files decode without error to the MD5 in their STREAMINFO; "
      "%d of 64 equal flac -d byte for byte; %d of 4 variable block size files report "
      "varying frames\n",
      decoded, equal, variable);
  if (!g_reference.empty()) {
    CHECK(equal == 64, "subset: %d of 64 files equal flac -d", equal);
  }
  /* A second pass in small, odd pieces over the files that stress the
   * FIFO and the metadata parser: large blocks, large metadata, eight
   * channels. */
  int pieces = 0;
  for (const std::string& name : flac_files(dir)) {
    const int number = atoi(name.c_str());
    if (number == 29 || number == 44 || (number >= 48 && number <= 55)) {
      const Outcome o = decode_file(dir + "/" + name, nullptr, 1021);
      CHECK(o.errors.empty() && o.finish == AAC_OK && o.verdict == BAAC_FLAC_MD5_MATCH,
            "subset/%s in pieces of 1021 bytes: %s", name.c_str(), describe(o).c_str());
      pieces++;
    }
  }
  printf("subset: %d files decoded again in pieces of 1021 bytes\n", pieces);
}

static void test_uncommon() {
  const std::string dir = g_testbench + "/uncommon";
  int changing = 0;
  int changing_equal = 0;
  int decoded = 0;
  int equal = 0;
  for (const std::string& name : flac_files(dir)) {
    const int number = atoi(name.c_str());
    if (number >= 1 && number <= 4) {
      /* The sample rate, channel count or bit depth changes: the frames
       * before the change decode, then the stream is unsupported.  The
       * reference decoder stops at the same frame, so its output is the
       * same bytes. */
      const Outcome o = decode_file(dir + "/" + name, "");
      const bool stopped = o.frames > 0 && o.errors.size() == 1 &&
                           o.errors[0] == AAC_ERR_UNSUPPORTED && o.lost == 0 && o.positions_run;
      CHECK(stopped, "uncommon/%s: %s", name.c_str(), describe(o).c_str());
      changing += stopped;
      if (o.compared) {
        CHECK(o.equal, "uncommon/%s: the frames before the change differ from flac -d (%llu bytes)",
              name.c_str(), (unsigned long long)o.reference_bytes);
        changing_equal += o.equal;
      }
      printf("uncommon/%s: %ld frames (%llu samples), then unsupported\n", name.c_str(), o.frames,
             (unsigned long long)o.samples);
    } else if (number >= 5 && number <= 9) {
      equal += expect_decodes(dir, name, "", true);
      decoded++;
    } else if (number == 10) {
      /* Starts at a frame header: no STREAMINFO, so no MD5. */
      equal += expect_decodes(dir, name, "", false);
      decoded++;
    } else if (number == 11) {
      /* Starts with unparsable data: decoded from the first valid frame,
       * as the reference decoder does when told to decode through
       * errors. */
      equal += expect_decodes(dir, name, "-F", false);
      decoded++;
    }
  }
  CHECK(changing == 4 && decoded == 7, "uncommon: %d of 4 changing and %d of 7 other files checked",
        changing, decoded);
  printf(
      "uncommon: files 05 to 11: %d of 7 decode, %d of 7 equal flac -d (file 11: flac -d -F); "
      "files 01 to 04: %d of 4 stop as unsupported at the change, %d of 4 equal flac -d up to "
      "it\n",
      decoded, equal, changing, changing_equal);
  if (!g_reference.empty()) {
    CHECK(equal == 7 && changing_equal == 4, "uncommon: %d of 7 and %d of 4 equal flac -d", equal,
          changing_equal);
  }
}

/* The faulty files: whatever is wrong with them, decoding ends, in an
 * error or cleanly, in every chunking.  The sanitizer build of this
 * suite is what shows that nothing was read or written out of bounds on
 * the way.
 *
 * Which of the two it is follows from the decoder's rules, file by file:
 *   01  blocks larger than STREAMINFO's maximum are refused: error
 *   02  the frame size range of STREAMINFO is not relied on: clean
 *   03  the frames' bit depth is not the stream's: unsupported
 *   04  the frames' channel count is not the stream's: unsupported
 *   05  the sample total of STREAMINFO is not relied on: clean
 *   06  without STREAMINFO the frames describe themselves: clean
 *   07  STREAMINFO is read wherever it stands: clean
 *   08  a block of 65536 samples is no valid frame header: error
 *   09  a STREAMINFO with a block size of 1 is forbidden: error
 *   10  a Vorbis comment is passed over by its length: clean
 *   11  a wrong block length leads into bytes that are no block: error */
static void test_faulty() {
  static const bool kError[12] = {false, true,  false, true, true,  false,
                                  false, false, true,  true, false, true};
  const std::string dir = g_testbench + "/faulty";
  int ended = 0;
  for (const std::string& name : flac_files(dir)) {
    const Outcome whole = decode_file(dir + "/" + name, nullptr);
    const Outcome pieces = decode_file(dir + "/" + name, nullptr, 97);
    CHECK(whole.opened, "faulty/%s: opened", name.c_str());
    CHECK(whole.frames == pieces.frames && whole.errors == pieces.errors &&
              whole.finish == pieces.finish,
          "faulty/%s: the same outcome in pieces of 97 bytes", name.c_str());
    const bool error = !whole.errors.empty() || whole.finish != AAC_OK;
    printf("faulty/%s: %s: %s\n", name.c_str(), error ? "error" : "clean finish",
           describe(whole).c_str());
    const int number = atoi(name.c_str());
    if (number >= 1 && number <= 11) {
      CHECK(error == kError[number], "faulty/%s: expected %s", name.c_str(),
            kError[number] ? "an error" : "a clean finish");
    }
    ended++;
  }
  CHECK(ended == 11, "faulty: %d of 11 files end", ended);
  printf("faulty: %d of 11 files end in an error or a clean finish\n", ended);
}

/* ── a missing oracle ──────────────────────────────────────────── */

/* Runs this suite's oracle checks alone in a child process without the
 * two variables that name the oracles: by default that is a skip and
 * the child succeeds, with BAAC_REQUIRE_ORACLES it has to fail. */
static void test_missing_oracles(const char* self) {
  const std::string probe = "env -u BAAC_FLAC_TESTBENCH -u BAAC_FLAC_REFERENCE ";
  const std::string child = " " + shell_quote(self) + " --probe-oracles > /dev/null 2>&1";
  CHECK(system((probe + "-u BAAC_REQUIRE_ORACLES" + child).c_str()) == 0,
        "without the oracles the checks that need them are skipped");
  CHECK(system((probe + "BAAC_REQUIRE_ORACLES=1" + child).c_str()) != 0,
        "with BAAC_REQUIRE_ORACLES the suite fails when the oracles are missing");
  CHECK(system((probe + "BAAC_REQUIRE_ORACLES=0" + child).c_str()) == 0,
        "BAAC_REQUIRE_ORACLES=0 does not require them");
  const std::string only_testbench = "env -u BAAC_FLAC_TESTBENCH BAAC_REQUIRE_ORACLES=1";
  if (!g_reference.empty()) {
    CHECK(system((only_testbench + child).c_str()) != 0,
          "with BAAC_REQUIRE_ORACLES the suite fails when only the testbench is missing");
  }
}

int main(int argc, char** argv) {
  if (argc > 1 && strcmp(argv[1], "--make-fixtures") == 0) {
    return make_fixtures();
  }
  const bool probe = argc > 1 && strcmp(argv[1], "--probe-oracles") == 0;
  find_reference();
  find_testbench();
  const bool have_reference = oracle_present(!g_reference.empty(),
                                             "the reference flac 1.4.3 "
                                             "(BAAC_FLAC_REFERENCE, from tests/flac-reference.sh)");
  const bool have_testbench = oracle_present(!g_testbench.empty(),
                                             "the FLAC decoder testbench "
                                             "(BAAC_FLAC_TESTBENCH, from tests/flac-testbench.sh)");
  if (probe) {
    return test_summary("test_flac_conformance --probe-oracles");
  }
  test_fixtures();
  if (have_testbench) {
    if (!have_reference) {
      printf("the testbench is checked against the MD5 in each STREAMINFO only\n");
    }
    test_subset();
    test_uncommon();
    test_faulty();
  }
  test_missing_oracles(argv[0]);
  return test_summary("test_flac_conformance");
}
