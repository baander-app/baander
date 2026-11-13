/* The FLAC encoder against decoders it does not share a line with: the
 * reference decoder, flac 1.4.3, and FFmpeg.  Every stream the encoder
 * writes here is tested by `flac -t` (which decodes it and compares the
 * MD5 in its header, the MD5 of the input), decoded by `flac -d` and by
 * FFmpeg, and the samples of both are compared with the input.  A
 * stream whose header was rewritten has to pass without a word from
 * either tool.
 *
 *   BAAC_FLAC_REFERENCE   the path of the flac 1.4.3 binary
 *                         (tests/flac-reference.sh prints it)
 *   BAAC_REQUIRE_ORACLES  set: a missing flac or ffmpeg fails the suite
 *                         instead of skipping it
 *   BAAC_FLAC_CORPUS      optional: a directory with the benchmark
 *                         corpus (bench/corpus.sh fetches it).  The
 *                         suite then encodes its ten music items at
 *                         levels 5 and 8 with this encoder and with
 *                         flac, prints the frame bytes of both, and
 *                         fails when this encoder's total is larger. */
#include <sys/stat.h>

#include "test_flac_util.h"

static std::string g_reference; /* the flac binary */
static std::string g_tmp;       /* a directory for this run */
static int g_checked = 0;       /* streams the oracles looked at */

/* ── running the oracles ───────────────────────────────────────── */

typedef CommandRun Run;

static Run run(const std::string& cmd) { return run_command(cmd, g_tmp); }

/* The samples as raw little-endian PCM of `width` bytes, each shifted
 * left by `shift` bits. */
static std::vector<uint8_t> pack(const std::vector<int32_t>& pcm, int width, int shift) {
  std::vector<uint8_t> out;
  out.reserve(pcm.size() * (size_t)width);
  for (int32_t v : pcm) {
    const uint32_t u = (uint32_t)v << shift;
    for (int b = 0; b < width; b++) {
      out.push_back((uint8_t)(u >> (8 * b)));
    }
  }
  return out;
}

/* What a tool may say about a stream whose header carries no MD5: flac
 * notes that it cannot check it.  Anything else is a complaint. */
static bool only_md5_notice(const std::string& err) {
  size_t pos = 0;
  while (pos < err.size()) {
    size_t end = err.find('\n', pos);
    if (end == std::string::npos) {
      end = err.size();
    }
    const std::string line = err.substr(pos, end - pos);
    if (line.find_first_not_of(" \t\r") != std::string::npos &&
        line.find("MD5") == std::string::npos) {
      return false;
    }
    pos = end + 1;
  }
  return true;
}

/* Hands a stream to both oracles and compares what they decode with the
 * input.  `rewritten` says that the header carries the total and the
 * MD5, in which case neither tool may print anything. */
static void check_oracles(const char* name, const std::vector<uint8_t>& stream,
                          const std::vector<int32_t>& pcm, int bits, bool rewritten = true) {
  const std::string path = g_tmp + "/stream.flac";
  write_file(path, stream.data(), stream.size());
  const std::string flac = shell_quote(g_reference);
  const std::string file = shell_quote(path);
  g_checked++;

  /* flac -t: decodes, checks every frame and, with an MD5 in the header,
   * that the decoded samples are the ones the encoder was given. */
  Run r = run(flac + " -t -s " + file);
  CHECK(r.status == 0, "%s: flac -t fails: %s", name, r.err.c_str());
  CHECK(rewritten ? r.err.empty() : only_md5_notice(r.err), "%s: flac -t says: %s", name,
        r.err.c_str());

  /* flac -d writes raw samples for depths of whole bytes. */
  if (bits % 8 == 0) {
    r = run(flac + " -d -s -c --force-raw-format --endian=little --sign=signed " + file);
    CHECK(r.status == 0 && r.out == pack(pcm, bits / 8, 0),
          "%s: flac -d decodes %zu bytes, the input has %zu%s", name, r.out.size(),
          pcm.size() * (size_t)(bits / 8),
          r.out.size() == pcm.size() * (size_t)(bits / 8) ? ", and they differ" : "");
    CHECK(rewritten ? r.err.empty() : only_md5_notice(r.err), "%s: flac -d says: %s", name,
          r.err.c_str());
  }

  /* FFmpeg hands out samples at the top of 16 or 32 bits. */
  if (!pcm.empty()) {
    const int width = bits <= 16 ? 2 : 4;
    const std::string format = width == 2 ? "s16le -c:a pcm_s16le" : "s32le -c:a pcm_s32le";
    r = run("ffmpeg -v warning -nostdin -i " + file + " -f " + format + " -");
    CHECK(r.status == 0 && r.out == pack(pcm, width, width * 8 - bits),
          "%s: FFmpeg decodes %zu bytes, the input has %zu%s", name, r.out.size(),
          pcm.size() * (size_t)width,
          r.out.size() == pcm.size() * (size_t)width ? ", and they differ" : "");
    CHECK(r.err.empty(), "%s: FFmpeg says: %s", name, r.err.c_str());
  }
}

/* Encodes with the header rewritten, requires the library's own decoder
 * to return the input, then asks the oracles. */
static FlacEncoded encode_and_check(const char* name, const std::vector<int32_t>& pcm, int rate,
                                    int channels, int bits, int level,
                                    const BaacFlacEncoderOptions* options = nullptr) {
  const FlacEncoded e = flac_encode(pcm, rate, channels, bits, level, 3001, true, options);
  CHECK(e.error == AAC_OK, "%s: encode error %d", name, e.error);
  if (e.error == AAC_OK) {
    const FlacDecoded d = flac_decode(e.stream);
    CHECK(d.errors.empty() && d.pcm == pcm && d.verdict == BAAC_FLAC_MD5_MATCH,
          "%s: the library's decoder returns the input", name);
    check_oracles(name, e.stream, pcm, bits);
  }
  return e;
}

/* ── formats and levels ────────────────────────────────────────── */

static void test_formats() {
  struct Format {
    int rate, channels, bits;
  };
  std::vector<Format> formats;
  for (int channels = 1; channels <= 8; channels++) {
    formats.push_back({48000, channels, 16});
  }
  for (int bits : {8, 12, 20, 24, 32}) {
    for (int channels : {1, 2, 6, 8}) {
      formats.push_back({48000, channels, bits});
    }
  }
  for (int rate : {8000, 44100, 96000, 192000, 22254, 655350}) {
    formats.push_back({rate, 2, 16});
    formats.push_back({rate, 2, 24});
  }
  int index = 0;
  for (const Format& f : formats) {
    const std::vector<int32_t> pcm =
        flac_gen_tones(9000, f.channels, f.bits, 0.6, 200u + (unsigned)index, 0.01);
    for (int level = 0; level <= 8; level++) {
      /* Levels 0, 5 and 8 for every format, the others for stereo at 16
       * and 24 bits. */
      if (level != 0 && level != 5 && level != 8 &&
          !(f.rate == 48000 && f.channels == 2 && (f.bits == 16 || f.bits == 24))) {
        continue;
      }
      char name[96];
      snprintf(name, sizeof(name), "%d Hz, %d channels, %d bits, level %d", f.rate, f.channels,
               f.bits, level);
      encode_and_check(name, pcm, f.rate, f.channels, f.bits, level);
    }
    index++;
  }
}

/* Signals at the edges of what the format holds. */
static void test_edges() {
  for (int level : {0, 5, 8}) {
    char name[96];
    /* 32-bit stereo with the most negative value and alternation
     * between the extremes, in step and in opposition. */
    const int n = 9000;
    std::vector<int32_t> extremes((size_t)n * 2);
    for (int i = 0; i < n; i++) {
      const int32_t a = i % 2 ? INT32_MAX : INT32_MIN;
      extremes[(size_t)i * 2] = a;
      extremes[(size_t)i * 2 + 1] = i < n / 2 ? a : (i % 2 ? INT32_MIN : INT32_MAX);
    }
    snprintf(name, sizeof(name), "32-bit extremes, level %d", level);
    encode_and_check(name, extremes, 48000, 2, 32, level);

    for (int bits : {16, 24, 32}) {
      snprintf(name, sizeof(name), "full-scale noise at %d bits, level %d", bits, level);
      encode_and_check(name, flac_gen_noise(n, 2, bits, 17u), 44100, 2, bits, level);
    }
    snprintf(name, sizeof(name), "silence, level %d", level);
    encode_and_check(name, std::vector<int32_t>((size_t)n * 2, 0), 44100, 2, 16, level);
    snprintf(name, sizeof(name), "a constant offset, level %d", level);
    encode_and_check(name, std::vector<int32_t>((size_t)n * 2, -4321), 44100, 2, 24, level);
    for (int wasted : {1, 8}) {
      std::vector<int32_t> pcm = flac_gen_tones(n, 2, 16 - wasted, 0.7, 5u, 0.05);
      for (int32_t& v : pcm) {
        v = (int32_t)((uint32_t)v << wasted);
      }
      snprintf(name, sizeof(name), "%d wasted bits, level %d", wasted, level);
      encode_and_check(name, pcm, 44100, 2, 16, level);
    }
    /* Quiet and loud stretches in one block, and digital silence in the
     * middle of sound: wide ranges of Rice parameters, and partitions
     * that are stored unencoded. */
    std::vector<int32_t> mixed = flac_gen_tones(n, 2, 16, 0.5, 8u, 0.05);
    for (int i = 2000; i < 4500; i++) {
      mixed[(size_t)i * 2] = 0;
      mixed[(size_t)i * 2 + 1] = 0;
    }
    for (int i = 6000; i < 6600; i++) {
      mixed[(size_t)i * 2] /= 500;
    }
    snprintf(name, sizeof(name), "silence inside sound, level %d", level);
    encode_and_check(name, mixed, 44100, 2, 16, level);

    /* Streams of a few samples, of a block and of one sample more. */
    const int block = level == 0 ? 1152 : 4096;
    for (int samples : {1, 15, 16, block, block + 1}) {
      snprintf(name, sizeof(name), "%d samples, level %d", samples, level);
      encode_and_check(name, flac_gen_tones(samples, 2, 16, 0.5, 2u), 44100, 2, 16, level);
    }
  }
  /* 96 kHz at 24 bits, a second of it. */
  for (int level : {3, 5, 8}) {
    char name[96];
    snprintf(name, sizeof(name), "96 kHz, 24 bits, level %d", level);
    encode_and_check(name, flac_gen_tones(96000, 2, 24, 0.5, 96u, 0.001), 96000, 2, 24, level);
  }
}

/* The header: a stream that was not rewritten, as on a pipe, and one
 * with a seek table. */
static void test_header() {
  const int rate = 8000;
  const int n = 250000;
  const std::vector<int32_t> pcm = flac_gen_tones(n, 2, 16, 0.5, 44u);
  for (int level : {0, 5}) {
    char name[96];
    /* Not rewritten: no total, no MD5, no seek table; both oracles
     * decode it to the input, and flac may note the missing MD5. */
    const FlacEncoded pipe = flac_encode(pcm, rate, 2, 16, level, 4096, false);
    snprintf(name, sizeof(name), "a stream without a rewritten header, level %d", level);
    CHECK(pipe.error == AAC_OK && pipe.header_bytes == 42, "%s: 42 bytes of header", name);
    check_oracles(name, pipe.stream, pcm, 16, false);

    /* A seek table and padding: flac accepts the stream, and seeks by
     * the table to the right samples. */
    BaacFlacEncoderOptions options;
    baac_flac_encoder_options_init(&options);
    options.total_samples_hint = (uint64_t)n;
    options.seek_table = 1;
    options.padding_bytes = 512;
    snprintf(name, sizeof(name), "a stream with a seek table, level %d", level);
    const FlacEncoded e = encode_and_check(name, pcm, rate, 2, 16, level, &options);
    const std::string path = g_tmp + "/stream.flac";
    write_file(path, e.stream.data(), e.stream.size());
    for (int from : {0, 80000, 123457, 200001, n - 50}) {
      const int count = std::min(3000, n - from);
      const Run r = run(shell_quote(g_reference) + " -d -s -c --force-raw-format --endian=little" +
                        " --sign=signed --skip=" + std::to_string(from) + " --until=+" +
                        std::to_string(count) + " " + shell_quote(path));
      const std::vector<int32_t> part(pcm.begin() + (long)from * 2,
                                      pcm.begin() + (long)(from + count) * 2);
      CHECK(r.status == 0 && r.out == pack(part, 2, 0) && r.err.empty(),
            "%s: flac seeks to sample %d (%s)", name, from, r.err.c_str());
    }
    /* The reserved table that is never filled is accepted as well. */
    const FlacEncoded reserved = flac_encode(pcm, rate, 2, 16, level, 4096, false, &options);
    snprintf(name, sizeof(name), "a reserved seek table, level %d", level);
    check_oracles(name, reserved.stream, pcm, 16, false);
  }
}

/* ── the corpus ────────────────────────────────────────────────── */

/* The samples of a 16-bit PCM WAVE file. */
static bool read_wav16(const std::string& path, std::vector<int32_t>* pcm, int* rate,
                       int* channels) {
  const std::vector<uint8_t> d = read_file(path);
  int bits = 0;
  for (size_t pos = 12; pos + 8 <= d.size();) {
    size_t size = (size_t)d[pos + 4] | ((size_t)d[pos + 5] << 8) | ((size_t)d[pos + 6] << 16) |
                  ((size_t)d[pos + 7] << 24);
    if (memcmp(&d[pos], "fmt ", 4) == 0 && pos + 24 <= d.size()) {
      *channels = d[pos + 10] | (d[pos + 11] << 8);
      *rate = d[pos + 12] | (d[pos + 13] << 8) | (d[pos + 14] << 16) | (d[pos + 15] << 24);
      bits = d[pos + 22] | (d[pos + 23] << 8);
    } else if (memcmp(&d[pos], "data", 4) == 0) {
      if (bits != 16) {
        return false;
      }
      size = std::min(size, d.size() - pos - 8);
      for (size_t i = 0; i + 1 < size; i += 2) {
        pcm->push_back((int16_t)(d[pos + 8 + i] | (d[pos + 9 + i] << 8)));
      }
      return true;
    }
    pos += 8 + size + (size & 1);
  }
  return false;
}

/* The frame bytes of a FLAC file: its size without the marker and the
 * metadata. */
static size_t file_frame_bytes(const std::string& path) {
  const std::vector<uint8_t> s = read_file(path);
  BaacFlacDecoder* dec = baac_flac_decoder_create();
  size_t pos = 0;
  int64_t offset = AAC_ERR_STATE;
  while (pos < s.size() && offset < 0) {
    pos += (size_t)std::max(0, baac_flac_decoder_feed(dec, s.data() + pos,
                                                      (int)std::min<size_t>(4096, s.size() - pos)));
    offset = baac_flac_decoder_first_frame_offset(dec);
  }
  baac_flac_decoder_destroy(dec);
  return offset < 0 ? 0 : s.size() - (size_t)offset;
}

/* The ten music items of the benchmark corpus, each encoded at levels 5
 * and 8 by this encoder and by flac at -5 and -8, without padding and
 * seek table, and all ten back to back as one stream, as the benchmark
 * measures them.  Sizes are frame bytes.  This encoder may not need
 * more than flac at either level. */
static void test_corpus(const std::string& dir) {
  static const char* kItems[10] = {
      "strauss48_stereo", "sopr48_stereo",      "contrabassoon48_stereo", "guitar48_stereo",
      "glock48_stereo",   "castanets48_stereo", "harpsichord48_stereo",   "steely48_stereo",
      "ravel48_stereo",   "moonlight48_stereo"};
  const int levels[2] = {5, 8};
  std::vector<int32_t> all;
  size_t total_ours[2] = {0, 0};
  size_t total_flac[2] = {0, 0};
  const std::string raw_path = g_tmp + "/corpus.raw";
  const std::string flac_path = g_tmp + "/corpus.flac";
  printf("corpus: frame bytes, bAAC against flac 1.4.3\n");
  printf("%-26s %12s %12s %12s %12s\n", "item", "bAAC 5", "flac -5", "bAAC 8", "flac -8");
  for (int item = 0; item <= 10; item++) {
    std::vector<int32_t> pcm;
    int rate = 48000;
    int channels = 2;
    if (item < 10) {
      if (!read_wav16(dir + "/" + kItems[item] + ".wav", &pcm, &rate, &channels) || rate != 48000 ||
          channels != 2) {
        CHECK(false, "corpus: %s is not a 48 kHz 16-bit stereo WAVE file in %s", kItems[item],
              dir.c_str());
        return;
      }
      all.insert(all.end(), pcm.begin(), pcm.end());
    } else {
      pcm = all;
    }
    const std::vector<uint8_t> raw = pack(pcm, 2, 0);
    write_file(raw_path, raw.data(), raw.size());
    size_t ours[2];
    size_t theirs[2];
    for (int l = 0; l < 2; l++) {
      const FlacEncoded e = flac_encode(pcm, rate, channels, 16, levels[l], 4096 * 16);
      ours[l] = e.stream.size() - (size_t)e.header_bytes;
      char name[96];
      snprintf(name, sizeof(name), "corpus %s, level %d", item < 10 ? kItems[item] : "back to back",
               levels[l]);
      CHECK(e.error == AAC_OK && flac_decode(e.stream).pcm == pcm, "%s: decodes to the input",
            name);
      const std::string path = g_tmp + "/stream.flac";
      write_file(path, e.stream.data(), e.stream.size());
      const Run t = run(shell_quote(g_reference) + " -t -s " + shell_quote(path));
      CHECK(t.status == 0 && t.err.empty(), "%s: flac -t: %s", name, t.err.c_str());
      const Run r = run(shell_quote(g_reference) +
                        " -s -f --no-padding --no-seektable --force-raw-format --endian=little"
                        " --sign=signed --channels=2 --bps=16 --sample-rate=48000 -" +
                        std::to_string(levels[l]) + " -o " + shell_quote(flac_path) + " " +
                        shell_quote(raw_path));
      theirs[l] = r.status == 0 ? file_frame_bytes(flac_path) : 0;
      CHECK(theirs[l] > 0, "%s: flac encodes the item: %s", name, r.err.c_str());
      if (item < 10) {
        total_ours[l] += ours[l];
        total_flac[l] += theirs[l];
      }
    }
    if (item == 10) {
      printf("%-26s %12zu %12zu %12zu %12zu\n", "sum of the ten", total_ours[0], total_flac[0],
             total_ours[1], total_flac[1]);
    }
    printf("%-26s %12zu %12zu %12zu %12zu\n", item < 10 ? kItems[item] : "ten back to back",
           ours[0], theirs[0], ours[1], theirs[1]);
    if (item == 10) {
      for (int l = 0; l < 2; l++) {
        CHECK(total_ours[l] <= total_flac[l],
              "corpus: at level %d the ten items take %zu frame bytes, flac -%d takes %zu",
              levels[l], total_ours[l], levels[l], total_flac[l]);
        CHECK(ours[l] <= theirs[l],
              "corpus: at level %d the ten back to back take %zu frame bytes, flac -%d takes %zu",
              levels[l], ours[l], levels[l], theirs[l]);
        printf("level %d: %+.3f %% against flac -%d on the sum, %+.3f %% back to back\n", levels[l],
               100.0 * ((double)total_ours[l] / (double)total_flac[l] - 1.0), levels[l],
               100.0 * ((double)ours[l] / (double)theirs[l] - 1.0));
      }
    }
  }
}

int main() {
  const char* reference = getenv("BAAC_FLAC_REFERENCE");
  if (reference && *reference) {
    const std::string version = run_capture(shell_quote(reference) + " --version 2>/dev/null");
    CHECK(version == "flac 1.4.3",
          "BAAC_FLAC_REFERENCE (%s) reports \"%s\", expected \"flac 1.4.3\"", reference,
          version.c_str());
    if (version == "flac 1.4.3") {
      g_reference = reference;
    }
  }
  const bool have_reference = oracle_present(!g_reference.empty(),
                                             "the reference flac 1.4.3 "
                                             "(BAAC_FLAC_REFERENCE, from tests/flac-reference.sh)");
  const bool ffmpeg = oracle_present(have_ffmpeg(), "ffmpeg");
  if (!have_reference || !ffmpeg) {
    return test_summary("test_flac_interop");
  }
  g_tmp = make_temp_dir();
  CHECK(!g_tmp.empty(), "a temporary directory");
  if (g_tmp.empty()) {
    return test_summary("test_flac_interop");
  }
  test_formats();
  test_edges();
  test_header();
  printf("interop: %d streams tested by flac -t and decoded by flac and FFmpeg\n", g_checked);
  const char* corpus = getenv("BAAC_FLAC_CORPUS");
  if (corpus && *corpus) {
    test_corpus(corpus);
  } else {
    printf("the corpus size reading is skipped: BAAC_FLAC_CORPUS is not set\n");
  }
  remove_temp_dir(g_tmp);
  return test_summary("test_flac_interop");
}
