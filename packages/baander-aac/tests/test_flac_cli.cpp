/* The `baac flac` commands, run as the built tool: WAV and raw PCM in
 * and out, standard input and output, what is refused and what is left
 * behind then, the exit status of `test` and `dec` on streams that are
 * damaged or whose MD5 is wrong, tags and pictures written by `enc` and
 * listed by `info`, the commands the tool had before, and the memory of
 * a long encode and decode against a short one.
 *
 *   BAAC_FLAC_REFERENCE   the path of the flac 1.4.3 binary, which
 *                         decodes what `enc` wrote into a pipe; the
 *                         metaflac of the same build reads and writes
 *                         the tags and pictures
 *   BAAC_REQUIRE_ORACLES  set: a missing flac fails the suite instead of
 *                         skipping that check */
#include <signal.h>
#include <sys/resource.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <unistd.h>

#include <functional>

#include "test_flac_util.h"

static std::string g_tool; /* the baac binary, quoted for the shell */
static std::string g_tmp;  /* a directory for this run */

/* ── running the tool ──────────────────────────────────────────── */

typedef CommandRun Run;

static Run run(const std::string& cmd) { return run_command(cmd, g_tmp); }

static std::string path(const char* name) { return g_tmp + "/" + name; }
static std::string quoted(const char* name) { return shell_quote(path(name)); }

static bool exists(const std::string& file) {
  struct stat st;
  return stat(file.c_str(), &st) == 0;
}

/* ── WAV files ─────────────────────────────────────────────────── */

static void le(std::vector<uint8_t>& v, uint32_t x, int bytes) {
  for (int b = 0; b < bytes; b++) {
    v.push_back((uint8_t)(x >> (8 * b)));
  }
}

static const uint8_t kSubformatTail[14] = {0x00, 0x00, 0x00, 0x00, 0x10, 0x00, 0x80,
                                           0x00, 0x00, 0xAA, 0x00, 0x38, 0x9B, 0x71};

/* The samples as a WAV file would store them: at the top of `container`
 * bytes, one-byte samples unsigned. */
static std::vector<uint8_t> wav_data(const std::vector<int32_t>& pcm, int bits, int container) {
  std::vector<uint8_t> data;
  data.reserve(pcm.size() * (size_t)container);
  for (int32_t v : pcm) {
    uint32_t u = (uint32_t)v << (container * 8 - bits);
    if (container == 1) {
      u += 128;
    }
    le(data, u, container);
  }
  return data;
}

/* A WAV file: the plain format, or the extensible one with its valid
 * bits and a speaker mask; `tag` is the sample format (1 integer, 3
 * float).  `stated` overrides the length the data chunk states. */
static std::vector<uint8_t> wav_file(const std::vector<uint8_t>& data, int rate, int channels,
                                     int bits, int container, bool extensible, uint32_t mask = 0,
                                     int tag = 1, int64_t stated = -1) {
  std::vector<uint8_t> v;
  const uint32_t length = stated < 0 ? (uint32_t)data.size() : (uint32_t)stated;
  const uint32_t fmt = extensible ? 40 : 16;
  v.insert(v.end(), {'R', 'I', 'F', 'F'});
  le(v, length == 0xFFFFFFFFu ? length : 4 + 8 + fmt + 8 + length + (length & 1), 4);
  v.insert(v.end(), {'W', 'A', 'V', 'E', 'f', 'm', 't', ' '});
  le(v, fmt, 4);
  le(v, extensible ? 0xFFFE : (uint32_t)tag, 2);
  le(v, (uint32_t)channels, 2);
  le(v, (uint32_t)rate, 4);
  le(v, (uint32_t)(rate * channels * container), 4);
  le(v, (uint32_t)(channels * container), 2);
  le(v, (uint32_t)(extensible ? container * 8 : bits), 2);
  if (extensible) {
    le(v, 22, 2);
    le(v, (uint32_t)bits, 2);
    le(v, mask, 4);
    le(v, (uint32_t)tag, 2);
    v.insert(v.end(), kSubformatTail, kSubformatTail + 14);
  }
  v.insert(v.end(), {'d', 'a', 't', 'a'});
  le(v, length, 4);
  v.insert(v.end(), data.begin(), data.end());
  if (data.size() & 1) {
    v.push_back(0); /* a chunk of odd length is padded */
  }
  return v;
}

struct Wav {
  bool ok = false;
  int tag = 0; /* 1, 3 or 0xFFFE */
  int channels = 0;
  int rate = 0;
  int container_bits = 0;
  int valid_bits = 0; /* extensible only */
  uint32_t mask = 0;  /* extensible only */
  int subformat = 0;  /* extensible only */
  uint32_t riff_length = 0;
  uint32_t data_length = 0;
  std::vector<uint8_t> data;
};

static uint32_t rd(const std::vector<uint8_t>& f, size_t at, int bytes) {
  uint32_t v = 0;
  for (int b = 0; b < bytes && at + (size_t)b < f.size(); b++) {
    v |= (uint32_t)f[at + (size_t)b] << (8 * b);
  }
  return v;
}

/* Reads back a WAV file as this tool writes it: a format chunk and then
 * the data chunk. */
static Wav parse_wav(const std::vector<uint8_t>& f) {
  Wav w;
  if (f.size() < 44 || memcmp(f.data(), "RIFF", 4) != 0 ||
      memcmp(f.data() + 8, "WAVEfmt ", 8) != 0) {
    return w;
  }
  w.riff_length = rd(f, 4, 4);
  const uint32_t fmt = rd(f, 16, 4);
  w.tag = (int)rd(f, 20, 2);
  w.channels = (int)rd(f, 22, 2);
  w.rate = (int)rd(f, 24, 4);
  w.container_bits = (int)rd(f, 34, 2);
  if (w.tag == 0xFFFE) {
    w.valid_bits = (int)rd(f, 38, 2);
    w.mask = rd(f, 40, 4);
    w.subformat = (int)rd(f, 44, 2);
  }
  const size_t data = 20 + (size_t)fmt;
  if (f.size() < data + 8 || memcmp(f.data() + data, "data", 4) != 0) {
    return w;
  }
  w.data_length = rd(f, data + 4, 4);
  /* The data chunk as long as it says, or to the end of the file. */
  const size_t available = f.size() - data - 8;
  const size_t length = std::min<size_t>(w.data_length, available);
  w.data.assign(f.begin() + (long)data + 8, f.begin() + (long)(data + 8 + length));
  w.ok = true;
  return w;
}

/* ── round trips ───────────────────────────────────────────────── */

/* Encodes a WAV file of the given shape at a level and decodes it
 * again; the data chunk has to come back byte for byte, in a file of
 * the same kind. */
static void wav_roundtrip(const char* name, int rate, int channels, int bits, int container,
                          bool extensible, uint32_t mask, int level, int frames = 12345) {
  const std::vector<int32_t> pcm = flac_gen_tones(frames, channels, bits, 0.5, 3u);
  const std::vector<uint8_t> data = wav_data(pcm, bits, container);
  const std::vector<uint8_t> file =
      wav_file(data, rate, channels, bits, container, extensible, mask);
  write_file(path("in.wav"), file.data(), file.size());
  remove(path("out.flac").c_str());
  Run r = run(g_tool + " flac enc --level " + std::to_string(level) + " " + quoted("in.wav") + " " +
              quoted("out.flac"));
  CHECK(r.status == 0 && r.out.empty(), "%s: enc exits %d: %s", name, r.status, r.err.c_str());

  /* The file states its total and its MD5 and has a seek table: the
   * output could seek. */
  const std::vector<uint8_t> stream = read_file(path("out.flac"));
  BaacFlacDecoder* dec = baac_flac_decoder_create();
  baac_flac_decoder_set_md5_check(dec, 1);
  const FlacDecoded d = flac_decode(stream, 8192, dec);
  const BaacFlacSeekPoint* points = nullptr;
  const int seek_points = baac_flac_decoder_seek_points(dec, &points);
  baac_flac_decoder_destroy(dec);
  CHECK(d.errors.empty() && d.pcm == pcm && d.verdict == BAAC_FLAC_MD5_MATCH &&
            d.info.total_samples == (uint64_t)frames && d.info.sample_rate == rate &&
            d.info.channels == channels && d.info.bits_per_sample == bits,
        "%s: the stream holds the input, its total and its MD5", name);
  CHECK(seek_points >= 1, "%s: the stream has a seek table (%d points)", name, seek_points);

  r = run(g_tool + " flac test " + quoted("out.flac"));
  CHECK(r.status == 0, "%s: test exits %d: %s", name, r.status, r.err.c_str());
  r = run(g_tool + " flac dec " + quoted("out.flac") + " " + quoted("out.wav"));
  CHECK(r.status == 0 && r.err.empty(), "%s: dec exits %d: %s", name, r.status, r.err.c_str());
  const std::vector<uint8_t> back = read_file(path("out.wav"));
  const Wav w = parse_wav(back);
  CHECK(w.ok && w.data == data, "%s: the decoded data chunk is the input's (%zu bytes of %zu)",
        name, w.data.size(), data.size());
  CHECK(w.data_length == data.size() && w.riff_length == back.size() - 8,
        "%s: the decoded file states its lengths", name);
  CHECK(w.channels == channels && w.rate == rate && w.container_bits == container * 8,
        "%s: the decoded file has %d channels at %d Hz in %d bits", name, w.channels, w.rate,
        w.container_bits);
  const bool want_extensible = channels > 2 || bits > 16 || bits != container * 8;
  if (want_extensible) {
    /* The speakers of FLAC's order for the channel count. */
    static const uint32_t kMasks[9] = {0, 0x4, 0x3, 0x7, 0x33, 0x37, 0x3F, 0x70F, 0x63F};
    CHECK(w.tag == 0xFFFE && w.valid_bits == bits && w.subformat == 1,
          "%s: the decoded file is extensible with %d valid bits (format 0x%x, %d bits)", name,
          bits, w.tag, w.valid_bits);
    CHECK(w.mask == kMasks[channels], "%s: the decoded file states the speakers 0x%x", name,
          w.mask);
  } else {
    CHECK(w.tag == 1 && back == file, "%s: the decoded file is the input file", name);
  }
}

static void test_wav() {
  wav_roundtrip("16-bit stereo", 44100, 2, 16, 2, false, 0, 5);
  wav_roundtrip("16-bit stereo at level 0", 48000, 2, 16, 2, false, 0, 0);
  wav_roundtrip("16-bit stereo at level 8", 48000, 2, 16, 2, false, 0, 8);
  wav_roundtrip("8-bit mono", 8000, 1, 8, 1, false, 0, 5, 12347);
  wav_roundtrip("24-bit 5.1 extensible", 48000, 6, 24, 3, true, 0x3F, 5);
  wav_roundtrip("20 bits in 24, extensible", 96000, 2, 20, 3, true, 0x3, 5);
  wav_roundtrip("12 bits in 16, extensible", 32000, 1, 12, 2, true, 0x4, 3);
  wav_roundtrip("32-bit stereo", 192000, 2, 32, 4, true, 0x3, 5);
  wav_roundtrip("24-bit 7.1", 48000, 8, 24, 3, true, 0x63F, 4, 5000);
}

/* Raw PCM in and out, standard input and standard output. */
static void test_raw_and_pipes() {
  for (int bits : {8, 12, 16, 24, 32}) {
    const int width = (bits + 7) / 8;
    const int frames = 9000;
    const std::vector<int32_t> pcm = flac_gen_tones(frames, 2, bits, 0.7, 5u);
    std::vector<uint8_t> raw;
    for (int32_t v : pcm) {
      le(raw, (uint32_t)v, width);
    }
    write_file(path("in.raw"), raw.data(), raw.size());
    const std::string shape = " --raw 44100 2 " + std::to_string(bits) + " ";
    Run r = run(g_tool + " flac enc" + shape + quoted("in.raw") + " " + quoted("raw.flac"));
    CHECK(r.status == 0, "raw %d bits: enc exits %d: %s", bits, r.status, r.err.c_str());
    r = run(g_tool + " flac dec --raw " + quoted("raw.flac") + " -");
    CHECK(r.status == 0 && r.out == raw, "raw %d bits: dec --raw to standard output is the input",
          bits);
    const FlacDecoded d = flac_decode(read_file(path("raw.flac")), 4096);
    CHECK(d.info.total_samples == (uint64_t)frames && d.info.bits_per_sample == bits,
          "raw %d bits: a raw file gives the stream its total", bits);

    /* Through pipes both ways. */
    r = run("cat " + quoted("in.raw") + " | " + g_tool + " flac enc" + shape + "- - | " + g_tool +
            " flac dec --raw - -");
    CHECK(r.status == 0 && r.out == raw, "raw %d bits: enc and dec through pipes", bits);
  }

  /* A raw sample outside the depth is an error, not something to clip. */
  std::vector<uint8_t> raw;
  for (int i = 0; i < 4000; i++) {
    le(raw, (uint32_t)(i == 3000 ? 2048 : i % 2000), 2);
  }
  write_file(path("in.raw"), raw.data(), raw.size());
  remove(path("raw.flac").c_str());
  const Run r =
      run(g_tool + " flac enc --raw 48000 1 12 " + quoted("in.raw") + " " + quoted("raw.flac"));
  CHECK(r.status == 1 && !exists(path("raw.flac")),
        "a 12-bit raw sample of 2048 is refused and no file remains (exit %d)", r.status);
}

/* The metadata blocks of a stream: their types in order. */
static std::vector<int> block_types(const std::vector<uint8_t>& s) {
  std::vector<int> types;
  for (const FlacBlockShape& b : flac_blocks(s)) {
    types.push_back(b.type);
  }
  return types;
}

/* Into a pipe: no total, no MD5, no seek table, and a stream the
 * reference decoder takes. */
static void test_pipe_output() {
  const int frames = 30000;
  const std::vector<int32_t> pcm = flac_gen_tones(frames, 2, 16, 0.5, 9u);
  const std::vector<uint8_t> data = wav_data(pcm, 16, 2);
  const std::vector<uint8_t> file = wav_file(data, 44100, 2, 16, 2, false);
  write_file(path("in.wav"), file.data(), file.size());
  Run r = run(g_tool + " flac enc " + quoted("in.wav") + " - | cat");
  CHECK(r.status == 0, "enc to a pipe exits %d: %s", r.status, r.err.c_str());
  const std::vector<uint8_t> stream = r.out;
  const FlacDecoded d = flac_decode(stream, 4096);
  bool md5_zero = true;
  for (uint8_t b : d.info.md5) {
    md5_zero = md5_zero && b == 0;
  }
  CHECK(d.errors.empty() && d.pcm == pcm, "a stream written to a pipe decodes to the input");
  CHECK(d.have_info && d.info.total_samples == 0 && md5_zero,
        "a stream written to a pipe states no total and no MD5");
  CHECK(block_types(stream) == std::vector<int>{FLAC_BLOCK_STREAMINFO},
        "a stream written to a pipe has STREAMINFO and nothing else, no seek table");

  /* A WAV file from a pipe states no length; it is read to its end. */
  const std::vector<uint8_t> open = wav_file(data, 44100, 2, 16, 2, false, 0, 1, 0xFFFFFFFFll);
  write_file(path("open.wav"), open.data(), open.size());
  r = run("cat " + quoted("open.wav") + " | " + g_tool + " flac enc - " + quoted("open.flac"));
  const FlacDecoded o = flac_decode(read_file(path("open.flac")), 4096);
  CHECK(r.status == 0 && o.pcm == pcm && o.info.total_samples == (uint64_t)frames &&
            o.verdict == BAAC_FLAC_MD5_MATCH,
        "a WAV file of unstated length from standard input is coded to its end");

  /* dec into a pipe states the lengths the stream announces. */
  r = run(g_tool + " flac dec " + quoted("open.flac") + " - | cat");
  const Wav w = parse_wav(r.out);
  CHECK(r.status == 0 && w.ok && w.data == data && w.data_length == data.size(),
        "dec to a pipe writes the lengths the stream states");

  const char* reference = getenv("BAAC_FLAC_REFERENCE");
  if (!oracle_present(reference && *reference, "the reference flac (BAAC_FLAC_REFERENCE)")) {
    return;
  }
  write_file(path("pipe.flac"), stream.data(), stream.size());
  r = run(shell_quote(reference) + " -d -s -c --force-raw-format --endian=little --sign=signed " +
          quoted("pipe.flac"));
  CHECK(r.status == 0 && r.out == data, "flac -d decodes the piped stream to the input");
  CHECK(r.err.empty() ||
            (r.err.find("MD5") != std::string::npos && r.err.find("ERROR") == std::string::npos),
        "flac -d says at most that the MD5 is unset: %s", r.err.c_str());
}

/* What the encoder refuses, and that nothing is left behind. */
static void test_refusals() {
  /* 32-bit float samples. */
  std::vector<uint8_t> data(4 * 2 * 1000, 0);
  std::vector<uint8_t> file = wav_file(data, 48000, 2, 32, 4, false, 0, 3);
  write_file(path("float.wav"), file.data(), file.size());
  remove(path("refused.flac").c_str());
  Run r = run(g_tool + " flac enc " + quoted("float.wav") + " " + quoted("refused.flac"));
  CHECK(r.status == 1 && r.err.find("float") != std::string::npos,
        "a float WAV file is refused with a message that names the format (exit %d): %s", r.status,
        r.err.c_str());
  CHECK(!exists(path("refused.flac")), "no output file remains after a float input");

  /* The same in the extensible format. */
  file = wav_file(data, 48000, 2, 32, 4, true, 0x3, 3);
  write_file(path("float.wav"), file.data(), file.size());
  r = run(g_tool + " flac enc " + quoted("float.wav") + " " + quoted("refused.flac"));
  CHECK(r.status == 1 && r.err.find("float") != std::string::npos && !exists(path("refused.flac")),
        "an extensible float WAV file is refused (exit %d)", r.status);

  /* A file that ends before its data chunk does. */
  const std::vector<int32_t> pcm = flac_gen_tones(20000, 2, 16, 0.5, 2u);
  file = wav_file(wav_data(pcm, 16, 2), 44100, 2, 16, 2, false);
  file.resize(file.size() - 10001);
  write_file(path("short.wav"), file.data(), file.size());
  r = run(g_tool + " flac enc " + quoted("short.wav") + " " + quoted("refused.flac"));
  CHECK(r.status == 1 && !exists(path("refused.flac")),
        "a truncated WAV file is an error and leaves no output (exit %d)", r.status);

  /* Samples that have bits below the depth the file states. */
  std::vector<int32_t> odd = flac_gen_tones(5000, 2, 24, 0.5, 2u);
  odd[777] |= 1;
  file = wav_file(wav_data(odd, 24, 3), 48000, 2, 20, 3, true, 0x3);
  write_file(path("odd.wav"), file.data(), file.size());
  r = run(g_tool + " flac enc " + quoted("odd.wav") + " " + quoted("refused.flac"));
  CHECK(r.status == 1 && !exists(path("refused.flac")),
        "24-bit data in a file that states 20 valid bits is refused, not truncated (exit %d)",
        r.status);

  /* Nine channels, a file that is no WAV file, a bad level, a bad option. */
  file = wav_file(std::vector<uint8_t>(9 * 2 * 100, 0), 48000, 9, 16, 2, true, 0);
  write_file(path("nine.wav"), file.data(), file.size());
  r = run(g_tool + " flac enc " + quoted("nine.wav") + " " + quoted("refused.flac"));
  CHECK(r.status == 1 && !exists(path("refused.flac")), "nine channels are refused");
  write_file(path("text.wav"), "not a wave file at all", 22);
  r = run(g_tool + " flac enc " + quoted("text.wav") + " " + quoted("refused.flac"));
  CHECK(r.status == 1 && !exists(path("refused.flac")), "a file that is no WAV file is refused");
  r = run(g_tool + " flac enc --level 9 " + quoted("short.wav") + " " + quoted("refused.flac"));
  CHECK(r.status == 2 && !exists(path("refused.flac")), "level 9 is a usage error (exit %d)",
        r.status);
  r = run(g_tool + " flac enc --bogus " + quoted("short.wav") + " " + quoted("refused.flac"));
  CHECK(r.status == 2 && r.err.find("baac flac enc") != std::string::npos,
        "an unknown option prints the usage (exit %d)", r.status);
  r = run(g_tool + " flac dec " + quoted("text.wav") + " " + quoted("refused.wav"));
  CHECK(r.status == 1, "dec of a file that is no FLAC stream exits %d", r.status);
}

/* `test` and `dec` on streams that are wrong. */
static void test_integrity() {
  const int frames = 4096 * 6;
  const std::vector<int32_t> pcm = flac_gen_tones(frames, 2, 16, 0.5, 7u);
  const std::vector<uint8_t> file = wav_file(wav_data(pcm, 16, 2), 44100, 2, 16, 2, false);
  write_file(path("in.wav"), file.data(), file.size());
  Run r = run(g_tool + " flac enc " + quoted("in.wav") + " " + quoted("good.flac"));
  const std::vector<uint8_t> good = read_file(path("good.flac"));
  CHECK(r.status == 0 && good.size() > 1000, "a stream to damage");
  r = run(g_tool + " flac test " + quoted("good.flac"));
  CHECK(r.status == 0 &&
            std::string(r.out.begin(), r.out.end()).find("MD5 verified") != std::string::npos,
        "test accepts a good stream and says the MD5 was verified (exit %d)", r.status);
  r = run("cat " + quoted("good.flac") + " | " + g_tool + " flac test -");
  CHECK(r.status == 0, "test reads standard input (exit %d)", r.status);

  /* A wrong MD5: the last byte of STREAMINFO. */
  std::vector<uint8_t> wrong = good;
  wrong[4 + 4 + BAAC_FLAC_STREAMINFO_BYTES - 1] ^= 0x01;
  write_file(path("md5.flac"), wrong.data(), wrong.size());
  r = run(g_tool + " flac test " + quoted("md5.flac"));
  CHECK(r.status == 1 && r.err.find("MD5") != std::string::npos,
        "test rejects a stream with a wrong MD5 (exit %d): %s", r.status, r.err.c_str());
  r = run(g_tool + " flac dec " + quoted("md5.flac") + " " + quoted("md5.wav"));
  CHECK(r.status == 1, "dec exits non-zero on a wrong MD5 (exit %d)", r.status);
  r = run(g_tool + " flac dec --no-md5 " + quoted("md5.flac") + " " + quoted("md5.wav"));
  const Wav w = parse_wav(read_file(path("md5.wav")));
  CHECK(r.status == 0 && w.ok && w.data == wav_data(pcm, 16, 2),
        "dec --no-md5 decodes a stream with a wrong MD5 and exits zero (exit %d): %s", r.status,
        r.err.c_str());
  r = run(g_tool + " flac test --no-md5 " + quoted("md5.flac"));
  CHECK(r.status == 0, "test --no-md5 accepts it too (exit %d)", r.status);

  /* A bit flipped in a frame in the middle of the stream. */
  std::vector<uint8_t> damaged = good;
  damaged[good.size() / 2] ^= 0x10;
  write_file(path("damaged.flac"), damaged.data(), damaged.size());
  r = run(g_tool + " flac test " + quoted("damaged.flac"));
  CHECK(r.status == 1 && r.err.find("damaged") != std::string::npos,
        "test rejects a stream with a damaged frame (exit %d): %s", r.status, r.err.c_str());
  r = run(g_tool + " flac dec --no-md5 " + quoted("damaged.flac") + " " + quoted("damaged.wav"));
  const Wav partial = parse_wav(read_file(path("damaged.wav")));
  CHECK(r.status == 1 && partial.ok && !partial.data.empty() &&
            partial.data.size() < (size_t)frames * 4 && partial.data_length == partial.data.size(),
        "dec exits non-zero on a damaged frame and leaves the frames that decode (exit %d, %zu "
        "bytes)",
        r.status, partial.data.size());
  CHECK(r.err.find("resumes at sample") != std::string::npos, "dec says where decoding resumes: %s",
        r.err.c_str());

  /* A stream cut off at a frame boundary still has its checksums right;
   * the total in the header gives it away. */
  std::vector<uint8_t> cut = good;
  cut.resize(good.size() * 2 / 3);
  write_file(path("cut.flac"), cut.data(), cut.size());
  r = run(g_tool + " flac test --no-md5 " + quoted("cut.flac"));
  CHECK(r.status == 1, "test rejects a stream that is cut short (exit %d)", r.status);
}

/* The commands the tool had before the lossless mode. */
static void test_existing_commands() {
  const std::vector<float> pcm = gen_tones(48000, 48000, 2);
  write_file(path("in.f32"), pcm.data(), pcm.size() * sizeof(float));
  Run r = run(g_tool + " enc " + quoted("in.f32") + " " + quoted("out.aac") + " 48000 2");
  const std::vector<uint8_t> aac = read_file(path("out.aac"));
  CHECK(r.status == 0 && split_adts(aac).size() >= 40, "baac enc writes an ADTS stream (exit %d)",
        r.status);
  r = run(g_tool + " dec " + quoted("out.aac") + " " + quoted("out.f32"));
  const std::vector<float> back = read_f32(path("out.f32"));
  CHECK(r.status == 0 && back.size() >= pcm.size(), "baac dec decodes it (exit %d, %zu values)",
        r.status, back.size());
  r = run(g_tool + " loudness " + quoted("in.f32") + " 48000 2");
  CHECK(r.status == 0 && std::string(r.out.begin(), r.out.end()).find("integrated loudness") !=
                             std::string::npos,
        "baac loudness prints its figures (exit %d)", r.status);
  r = run(g_tool + " bench --seconds 1 --only 'LC 128k'");
  std::string text(r.out.begin(), r.out.end());
  CHECK(r.status == 0 && text.find("LC 128k") != std::string::npos &&
            text.find("FLAC") == std::string::npos,
        "baac bench --only runs the one configuration (exit %d)", r.status);
  r = run(g_tool + " bench --seconds 1 --only 'FLAC level 5'");
  text.assign(r.out.begin(), r.out.end());
  CHECK(r.status == 0 && text.find("FLAC level 5") != std::string::npos &&
            text.find("NOT") == std::string::npos,
        "baac bench has the lossless rows (exit %d): %s", r.status, text.c_str());
  r = run(g_tool);
  CHECK(r.status == 2 && r.err.find("baac enc ") != std::string::npos &&
            r.err.find("baac flac enc") != std::string::npos &&
            r.err.find("baac flac dec") != std::string::npos &&
            r.err.find("baac flac test") != std::string::npos &&
            r.err.find("baac flac info") != std::string::npos,
        "the usage lists the old and the new commands (exit %d)", r.status);
  r = run(g_tool + " flac");
  CHECK(r.status == 2, "baac flac without a command is a usage error (exit %d)", r.status);
}

/* ── tags and pictures ─────────────────────────────────────────── */

static void be(std::vector<uint8_t>& v, uint32_t x, int bytes) {
  for (int b = bytes - 1; b >= 0; b--) {
    v.push_back((uint8_t)(x >> (8 * b)));
  }
}

/* The head of a JPEG file as far as a reader of its size goes: an
 * application segment, a comment with a marker byte inside, and the
 * frame header. */
static std::vector<uint8_t> jpeg_head(int width, int height, int components) {
  std::vector<uint8_t> v = {0xFF, 0xD8, 0xFF, 0xE0, 0x00, 0x10, 'J',  'F',  'I',  'F',
                            0,    1,    1,    0,    0,    1,    0,    1,    0,    0,
                            0xFF, 0xFE, 0x00, 0x06, 0xFF, 0xC0, 0x12, 0x34, 0xFF, 0xC0};
  be(v, (uint32_t)(8 + 3 * components), 2);
  v.push_back(8);
  be(v, (uint32_t)height, 2);
  be(v, (uint32_t)width, 2);
  v.push_back((uint8_t)components);
  for (int c = 0; c < components; c++) {
    v.insert(v.end(), {(uint8_t)(c + 1), 0x11, 0});
  }
  v.insert(v.end(), {0xFF, 0xD9});
  return v;
}

/* The head of a PNG file: its signature, the header chunk and, for a
 * palette image, a palette of `colors` entries.  The checksums are not
 * what is under test. */
static std::vector<uint8_t> png_head(int width, int height, int bits, int color_type,
                                     int colors = 0) {
  std::vector<uint8_t> v = {0x89, 'P', 'N', 'G', 0x0D, 0x0A, 0x1A, 0x0A};
  be(v, 13, 4);
  v.insert(v.end(), {'I', 'H', 'D', 'R'});
  be(v, (uint32_t)width, 4);
  be(v, (uint32_t)height, 4);
  v.insert(v.end(), {(uint8_t)bits, (uint8_t)color_type, 0, 0, 0});
  be(v, 0, 4);
  if (colors) {
    be(v, (uint32_t)colors * 3, 4);
    v.insert(v.end(), {'P', 'L', 'T', 'E'});
    v.insert(v.end(), (size_t)colors * 3, 0x40);
    be(v, 0, 4);
  }
  be(v, 0, 4);
  v.insert(v.end(), {'I', 'E', 'N', 'D'});
  be(v, 0, 4);
  return v;
}

/* The tag lines `baac flac info` prints: the indented lines between
 * "tags:" and "pictures:". */
static std::vector<std::string> info_tags(const std::string& text) {
  std::vector<std::string> tags;
  bool inside = false;
  size_t pos = 0;
  while (pos < text.size()) {
    size_t end = text.find('\n', pos);
    if (end == std::string::npos) {
      end = text.size();
    }
    const std::string line = text.substr(pos, end - pos);
    if (line.rfind("tags:", 0) == 0) {
      inside = true;
    } else if (line.rfind("pictures:", 0) == 0) {
      inside = false;
    } else if (inside && line.rfind("  ", 0) == 0) {
      tags.push_back(line.substr(2));
    }
    pos = end + 1;
  }
  return tags;
}

static std::string text_of(const std::vector<uint8_t>& bytes) {
  return std::string(bytes.begin(), bytes.end());
}

static bool has(const std::string& text, const std::string& what) {
  return text.find(what) != std::string::npos;
}

static void test_tags_and_pictures() {
  const int frames = 20000;
  const std::vector<int32_t> pcm = flac_gen_tones(frames, 2, 16, 0.5, 11u);
  const std::vector<uint8_t> data = wav_data(pcm, 16, 2);
  const std::vector<uint8_t> file = wav_file(data, 44100, 2, 16, 2, false);
  write_file(path("in.wav"), file.data(), file.size());
  const std::vector<uint8_t> jpeg = jpeg_head(640, 480, 3);
  write_file(path("cover.jpg"), jpeg.data(), jpeg.size());
  const std::vector<uint8_t> png = png_head(32, 16, 4, 3, 13);
  write_file(path("icon.png"), png.data(), png.size());

  /* Tags in the order given, one name twice, and two pictures. */
  const std::vector<std::string> tags = {"ARTIST=Ravel", "GENRE=Classical", "GENRE=Orchestral",
                                         "TITLE=Bol\xC3\xA9ro = dance"};
  std::string options;
  for (const std::string& tag : tags) {
    options += " --tag " + shell_quote(tag);
  }
  Run r = run(g_tool + " flac enc" + options + " --picture " + quoted("cover.jpg") + " --picture " +
              quoted("icon.png") + " " + quoted("in.wav") + " " + quoted("tagged.flac"));
  CHECK(r.status == 0, "enc with tags and pictures exits %d: %s", r.status, r.err.c_str());
  {
    /* The tool writes only the beginning of the final header over the
     * first one.  The file has to be what the library gives when the
     * whole final header stands in front of the frames: the seek table
     * filled, and the tags and both pictures as they were. */
    std::vector<BaacFlacComment> comments;
    for (const std::string& tag : tags) {
      comments.push_back({tag.data(), (uint32_t)tag.size()});
    }
    BaacFlacPicture pictures[2] = {};
    pictures[0].type = 3;
    pictures[0].mime_type = "image/jpeg";
    pictures[0].width = 640;
    pictures[0].height = 480;
    pictures[0].depth = 24;
    pictures[0].data = jpeg.data();
    pictures[0].data_bytes = (uint32_t)jpeg.size();
    pictures[1].type = 3;
    pictures[1].mime_type = "image/png";
    pictures[1].width = 32;
    pictures[1].height = 16;
    pictures[1].depth = 24;
    pictures[1].colors = 13;
    pictures[1].data = png.data();
    pictures[1].data_bytes = (uint32_t)png.size();
    BaacFlacEncoderOptions wanted;
    baac_flac_encoder_options_init(&wanted);
    wanted.total_samples_hint = (uint64_t)frames;
    wanted.seek_table = 1;
    wanted.comments = comments.data();
    wanted.comment_count = (int)comments.size();
    wanted.pictures = pictures;
    wanted.picture_count = 2;
    BaacFlacEncoder* enc = baac_flac_encoder_create(44100, 2, 16, BAAC_FLAC_LEVEL_DEFAULT, nullptr);
    std::vector<uint8_t> expected;
    bool built = enc && baac_flac_encoder_set_options(enc, &wanted) == AAC_OK;
    if (built) {
      const int header = baac_flac_encoder_header_bytes(enc);
      expected.resize((size_t)header);
      std::vector<uint8_t> coded((size_t)baac_flac_encoder_max_output_bytes(enc, frames));
      built = baac_flac_encoder_write_header(enc, expected.data(), header) == header;
      int n =
          built ? baac_flac_encoder_encode(enc, pcm.data(), frames, coded.data(), (int)coded.size())
                : -1;
      built = n >= 0;
      expected.insert(expected.end(), coded.begin(), coded.begin() + std::max(n, 0));
      n = built ? baac_flac_encoder_finish(enc, coded.data(), (int)coded.size()) : -1;
      built = n >= 0;
      expected.insert(expected.end(), coded.begin(), coded.begin() + std::max(n, 0));
      built = built && baac_flac_encoder_final_header(enc, expected.data(), header) == header;
    }
    baac_flac_encoder_destroy(enc);
    const std::vector<uint8_t> written = read_file(path("tagged.flac"));
    CHECK(built && written == expected,
          "the file with a seek table, tags and pictures is the library's stream with its "
          "final header, byte for byte (%zu and %zu bytes)",
          written.size(), expected.size());
  }
  r = run(g_tool + " flac info " + quoted("tagged.flac"));
  std::string text = text_of(r.out);
  CHECK(r.status == 0 && info_tags(text) == tags, "info lists the tags in order:\n%s",
        text.c_str());
  CHECK(has(text, "FLAC, 44100 Hz, 2 channels, 16 bits, 20000 samples") &&
            has(text, "vendor: bAAC") && has(text, "tags: 4") && has(text, "pictures: 2") &&
            has(text, "MD5: ") && has(text, "seek points: 1"),
        "info prints the stream's properties:\n%s", text.c_str());
  CHECK(has(text, "1: type 3 (front cover), image/jpeg, 640x480, 24 bits, " +
                      std::to_string(jpeg.size()) + " bytes"),
        "info shows the JPEG cover with the size its header states:\n%s", text.c_str());
  CHECK(has(text, "2: type 3 (front cover), image/png, 32x16, 24 bits, 13 colors, " +
                      std::to_string(png.size()) + " bytes"),
        "info shows the PNG picture with its palette:\n%s", text.c_str());
  r = run(g_tool + " flac info --export-picture " + quoted("first.bin") + " " +
          quoted("tagged.flac"));
  CHECK(r.status == 0 && read_file(path("first.bin")) == jpeg, "info exports the first picture");
  r = run("cat " + quoted("tagged.flac") + " | " + g_tool +
          " flac info --export-picture - --picture-number 2 -");
  CHECK(r.status == 0 && r.out == png,
        "info reads standard input and writes a picture, and nothing else, to standard output");
  r = run(g_tool + " flac info --export-picture " + quoted("none.bin") + " --picture-number 3 " +
          quoted("tagged.flac"));
  CHECK(r.status == 1, "a picture the stream does not have is an error (exit %d)", r.status);

  /* The audio is what it was. */
  r = run(g_tool + " flac test " + quoted("tagged.flac"));
  CHECK(r.status == 0, "the tagged stream tests clean (exit %d): %s", r.status, r.err.c_str());
  r = run(g_tool + " flac dec " + quoted("tagged.flac") + " " + quoted("tagged.wav"));
  CHECK(r.status == 0 && read_file(path("tagged.wav")) == file,
        "the tagged stream decodes to the input file");

  /* A stream without tags, and what is no stream. */
  r = run(g_tool + " flac enc " + quoted("in.wav") + " " + quoted("plain.flac"));
  r = run(g_tool + " flac info " + quoted("plain.flac"));
  text = text_of(r.out);
  CHECK(r.status == 0 && has(text, "tags: 0") && has(text, "pictures: 0") && !has(text, "vendor:"),
        "info on a stream without tags:\n%s", text.c_str());
  r = run(g_tool + " flac info " + quoted("in.wav"));
  CHECK(r.status == 1, "info on a file that is no FLAC stream exits %d", r.status);

  /* What is refused, with no output file left. */
  remove(path("refused.flac").c_str());
  r = run(g_tool + " flac enc --tag NOEQUALS " + quoted("in.wav") + " " + quoted("refused.flac"));
  CHECK(r.status == 2 && !exists(path("refused.flac")), "a tag without '=' is refused (exit %d)",
        r.status);
  r = run(g_tool + " flac enc --tag 'N\xC3\x84ME=x' " + quoted("in.wav") + " " +
          quoted("refused.flac"));
  CHECK(r.status == 1 && !exists(path("refused.flac")),
        "a tag name that is not ASCII is refused (exit %d)", r.status);
  r = run("bash -c " + shell_quote(BAAC_TOOL + std::string(" flac enc --tag $'A=\\xff\\xfe' ") +
                                   quoted("in.wav") + " " + quoted("refused.flac")));
  CHECK(r.status == 1 && !exists(path("refused.flac")),
        "a tag value that is not UTF-8 is refused (exit %d)", r.status);
  r = run(g_tool + " flac enc --picture " + quoted("in.wav") + " " + quoted("in.wav") + " " +
          quoted("refused.flac"));
  CHECK(r.status == 1 && has(r.err, "JPEG") && !exists(path("refused.flac")),
        "a picture that is neither JPEG nor PNG is refused (exit %d)", r.status);
  r = run(g_tool + " flac enc --picture " + quoted("missing.jpg") + " " + quoted("in.wav") + " " +
          quoted("refused.flac"));
  CHECK(r.status == 1 && !exists(path("refused.flac")), "a missing picture file is an error");

  /* metaflac reads the same tags in the same order and the same
   * picture. */
  const char* reference = getenv("BAAC_FLAC_REFERENCE");
  std::string metaflac;
  if (reference && *reference) {
    const std::string flac = reference;
    metaflac = flac.substr(0, flac.find_last_of('/')) + "/../metaflac/metaflac";
    if (system((shell_quote(metaflac) + " --version > /dev/null 2>&1").c_str()) != 0) {
      metaflac.clear();
    }
  }
  if (!oracle_present(!metaflac.empty(), "the reference metaflac (beside BAAC_FLAC_REFERENCE)")) {
    return;
  }
  r = run(shell_quote(metaflac) + " --export-tags-to=- " + quoted("tagged.flac"));
  std::vector<std::string> listed;
  text = text_of(r.out);
  for (size_t pos = 0; pos < text.size();) {
    const size_t end = std::min(text.find('\n', pos), text.size());
    listed.push_back(text.substr(pos, end - pos));
    pos = end + 1;
  }
  CHECK(r.status == 0 && listed == tags, "metaflac lists the same tags in the same order:\n%s",
        text.c_str());
  r = run(shell_quote(metaflac) + " --list --block-type=PICTURE " + quoted("tagged.flac"));
  text = text_of(r.out);
  CHECK(has(text, "type: 3 (Cover (front))") && has(text, "MIME type: image/jpeg") &&
            has(text, "width: 640") && has(text, "height: 480") &&
            has(text, "MIME type: image/png") && has(text, "colors: 13"),
        "metaflac shows the pictures with the same type, media type and size:\n%s", text.c_str());
  r = run(shell_quote(metaflac) + " --block-number=3 --export-picture-to=" + quoted("meta.bin") +
          " " + quoted("tagged.flac"));
  CHECK(r.status == 0 && read_file(path("meta.bin")) == jpeg,
        "metaflac exports the same picture bytes: %s", r.err.c_str());

  /* And the other way: what metaflac writes into a plain stream, info
   * reads. */
  r = run("cp " + quoted("plain.flac") + " " + quoted("meta.flac") + " && " +
          shell_quote(metaflac) +
          " --set-tag=ARTIST=Ravel --set-tag=GENRE=Classical --set-tag=GENRE=Orchestral "
          "--import-picture-from=" +
          quoted("cover.jpg") + " " + quoted("meta.flac"));
  CHECK(r.status == 0, "metaflac tags a stream bAAC wrote: %s", r.err.c_str());
  r = run(g_tool + " flac info --export-picture " + quoted("back.bin") + " " + quoted("meta.flac"));
  text = text_of(r.out);
  CHECK(r.status == 0 &&
            info_tags(text) ==
                std::vector<std::string>({"ARTIST=Ravel", "GENRE=Classical", "GENRE=Orchestral"}) &&
            has(text, "type 3 (front cover), image/jpeg, 640x480") &&
            read_file(path("back.bin")) == jpeg,
        "info reads the tags and the picture metaflac wrote:\n%s%s", text.c_str(), r.err.c_str());
  r = run(g_tool + " flac test " + quoted("meta.flac"));
  CHECK(r.status == 0, "and the stream still tests clean");
}

/* ── memory ────────────────────────────────────────────────────── */

/* Runs the tool with `feed` writing its standard input (or nothing, when
 * it is null), its output discarded, and returns its peak resident
 * memory in KiB, or -1 when it did not exit with status zero. */
static long peak_memory(const std::vector<std::string>& arguments,
                        const std::function<void(int)>& feed) {
  int fds[2];
  if (pipe(fds) != 0) {
    return -1;
  }
  const pid_t pid = fork();
  if (pid < 0) {
    return -1;
  }
  if (pid == 0) {
    dup2(fds[0], 0);
    close(fds[0]);
    close(fds[1]);
    std::vector<char*> argv;
    for (const std::string& a : arguments) {
      argv.push_back(const_cast<char*>(a.c_str()));
    }
    argv.push_back(nullptr);
    execv(argv[0], argv.data());
    _exit(127);
  }
  close(fds[0]);
  if (feed) {
    feed(fds[1]);
  }
  close(fds[1]);
  int status = 0;
  struct rusage usage;
  if (wait4(pid, &status, 0, &usage) != pid || !WIFEXITED(status) || WEXITSTATUS(status) != 0) {
    return -1;
  }
#if defined(__APPLE__)
  return (long)(usage.ru_maxrss / 1024);
#else
  return (long)usage.ru_maxrss;
#endif
}

/* Writes a WAV file of unstated length with `seconds` of 16-bit stereo
 * at 44.1 kHz: two slow triangle waves and a little noise. */
static void feed_wav(int fd, int seconds) {
  const std::vector<uint8_t> header = wav_file({}, 44100, 2, 16, 2, false, 0, 1, 0xFFFFFFFFll);
  if (write(fd, header.data(), header.size()) != (ssize_t)header.size()) {
    return;
  }
  std::vector<uint8_t> chunk(44100 * 4);
  uint32_t seed = 1;
  uint32_t phase = 0;
  for (int s = 0; s < seconds; s++) {
    for (int i = 0; i < 44100; i++) {
      phase += 300;
      seed = seed * 1664525u + 1013904223u;
      const int32_t ramp = (int32_t)(phase >> 8 & 0xFFFF);
      const int32_t triangle = (ramp < 32768 ? ramp : 65535 - ramp) - 16384;
      const int32_t left = triangle / 2 + (int32_t)(seed >> 30);
      const int32_t right = -triangle / 3 + (int32_t)(seed >> 29 & 3);
      chunk[(size_t)i * 4] = (uint8_t)left;
      chunk[(size_t)i * 4 + 1] = (uint8_t)(left >> 8);
      chunk[(size_t)i * 4 + 2] = (uint8_t)right;
      chunk[(size_t)i * 4 + 3] = (uint8_t)(right >> 8);
    }
    size_t done = 0;
    while (done < chunk.size()) {
      const ssize_t n = write(fd, chunk.data() + done, chunk.size() - done);
      if (n <= 0) {
        return;
      }
      done += (size_t)n;
    }
  }
}

/* Twenty minutes against ten seconds, encoded from a pipe and decoded
 * from a file: the tool's memory may not follow the length. */
static void test_memory(const std::string& tool) {
#if defined(BAAC_FUZZ_SANITIZED)
  (void)tool;
  printf("memory of a long run: not measured in a sanitized build\n");
#else
  const long kSlack = 4096; /* KiB */
  const std::string short_flac = path("short.flac");
  const std::string long_flac = path("long.flac");
  const long enc_short =
      peak_memory({tool, "flac", "enc", "-", short_flac}, [](int fd) { feed_wav(fd, 10); });
  const long enc_long =
      peak_memory({tool, "flac", "enc", "-", long_flac}, [](int fd) { feed_wav(fd, 20 * 60); });
  printf("enc: peak memory %ld KiB for 10 s, %ld KiB for 20 min\n", enc_short, enc_long);
  CHECK(enc_short > 0 && enc_long > 0, "the encodes run");
  CHECK(enc_long - enc_short < kSlack, "a 20-minute encode takes %ld KiB more than a 10-second one",
        enc_long - enc_short);
  const long dec_short = peak_memory({tool, "flac", "dec", short_flac, "/dev/null"}, nullptr);
  const long dec_long = peak_memory({tool, "flac", "dec", long_flac, "/dev/null"}, nullptr);
  printf("dec: peak memory %ld KiB for 10 s, %ld KiB for 20 min\n", dec_short, dec_long);
  CHECK(dec_short > 0 && dec_long > 0, "the decodes run and verify");
  CHECK(dec_long - dec_short < kSlack, "a 20-minute decode takes %ld KiB more than a 10-second one",
        dec_long - dec_short);
  remove(long_flac.c_str());
#endif
}

int main() {
  signal(SIGPIPE, SIG_IGN);
  g_tool = shell_quote(BAAC_TOOL);
  g_tmp = make_temp_dir();
  CHECK(!g_tmp.empty(), "a temporary directory");
  if (g_tmp.empty()) {
    return test_summary("test_flac_cli");
  }
  test_wav();
  test_raw_and_pipes();
  test_pipe_output();
  test_refusals();
  test_integrity();
  test_existing_commands();
  test_tags_and_pictures();
  test_memory(BAAC_TOOL);
  remove_temp_dir(g_tmp);
  return test_summary("test_flac_cli");
}
