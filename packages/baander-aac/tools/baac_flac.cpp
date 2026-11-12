/* baac flac: the lossless mode on the command line.
 *
 *   baac flac enc [--level 0..8] [--raw RATE CHANNELS BITS] [--tag NAME=VALUE]...
 *                 [--picture FILE]... <in.wav | -> <out.flac | ->
 *   baac flac dec [--no-md5] [--raw] <in.flac | -> <out.wav | ->
 *   baac flac test [--no-md5] <in.flac | ->
 *   baac flac info [--export-picture FILE [--picture-number N]] <in.flac | ->
 *
 * enc reads an integer WAV file (PCM, or the extensible format with its
 * valid-bits field) or, with --raw, headerless PCM, and writes a native
 * FLAC stream; dec does the reverse; test decodes without writing.  A
 * dash is standard input or standard output.  Raw PCM is interleaved
 * little-endian signed integers, one to four bytes each (the bit depth
 * rounded up to whole bytes), holding the sample value itself.
 *
 * Input and output are streamed: nothing here holds more than a few
 * blocks, however long the file.  When the output can seek, enc reserves
 * a seek table and, after the last frame, rewrites the header with the
 * sample total, the MD5 of the input and the seek points.  Into a pipe
 * the stream is complete without them.
 *
 * --tag adds a tag (a Vorbis comment) and may be repeated, also with one
 * name; the tags are written in the order given.  --picture adds a JPEG
 * or PNG file as a front cover, with the media type and the size read
 * from the file's own header.  info prints what a stream's header says,
 * its tags and its pictures without decoding the audio, and writes one
 * picture's bytes to a file when asked.
 *
 * dec and test verify every frame checksum, the sample total the header
 * states and, unless --no-md5 is given, the MD5 of the decoded samples.
 * Every command exits non-zero on any error or mismatch; enc then leaves
 * no output file, dec leaves what could be decoded.
 *
 * This file holds the commands.  The files and the PCM they read and
 * write are in baac_flac_pcm.cpp, the picture files in
 * baac_flac_picture.cpp. */
#include "baac_flac.h"

#include <algorithm>
#include <cmath>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <memory>
#include <string>
#include <vector>

#include "baac_common.h"
#include "baac_flac_pcm.h"
#include "baac_flac_picture.h"
#include "flac.h"

namespace baac_flac {

namespace {

/* The samples of the largest frame there is: the largest block in the
 * most channels. */
const size_t kLargestFrame = (size_t)BAAC_FLAC_MAX_BLOCK_SIZE * BAAC_FLAC_MAX_CHANNELS;

/* The codec's handles, destroyed when they go out of scope. */
struct EncoderDestroy {
  void operator()(BaacFlacEncoder* enc) const { baac_flac_encoder_destroy(enc); }
};
struct DecoderDestroy {
  void operator()(BaacFlacDecoder* dec) const { baac_flac_decoder_destroy(dec); }
};
typedef std::unique_ptr<BaacFlacEncoder, EncoderDestroy> EncoderHandle;
typedef std::unique_ptr<BaacFlacDecoder, DecoderDestroy> DecoderHandle;

const int kChunkFrames = 16384; /* samples per channel enc reads at a time */

/* ── arguments ─────────────────────────────────────────────────── */

bool parse_int(const char* text, int* out) {
  char* end = nullptr;
  const long v = strtol(text, &end, 10);
  if (end == text || *end || v < -2147483647L || v > 2147483647L) {
    return false;
  }
  *out = (int)v;
  return true;
}

const char* error_name(int error) {
  switch (error) {
    case AAC_ERR_INVALID_ARG:
      return "invalid argument";
    case AAC_ERR_MEMORY:
      return "out of memory";
    case AAC_ERR_UNSUPPORTED:
      return "unsupported";
    case AAC_ERR_OVERFLOW:
      return "buffer too small";
    case AAC_ERR_STATE:
      return "wrong state";
    case AAC_ERR_DECODE:
      return "damaged data";
    default:
      return "error";
  }
}

int usage() {
  fprintf(stderr, "usage: %s", baac_flac_usage());
  return 2;
}

/* ── enc ───────────────────────────────────────────────────────── */

struct EncodeArgs {
  int level = BAAC_FLAC_LEVEL_DEFAULT;
  bool raw = false;
  int raw_rate = 0;
  int raw_channels = 0;
  int raw_bits = 0;
  std::vector<std::string> tags;
  std::vector<const char*> pictures; /* files */
  const char* in = nullptr;
  const char* out = nullptr;
};

/* Whether a rewrite of the first bytes will reach the file: a named
 * output that can seek. */
bool can_rewrite(const File& out) { return out.owned && fseek(out.f, 0, SEEK_CUR) == 0; }

/* The length of the part of a stream's header that its final form can
 * change: the marker, STREAMINFO (block type 0) and the seek table
 * (type 3), which lead the metadata.  The tags, the pictures and the
 * padding behind them are the same bytes in both forms, and a cover is
 * not written twice for the sake of a few numbers. */
size_t header_prefix(const std::vector<uint8_t>& header) {
  size_t pos = 4;
  while (pos + 4 <= header.size()) {
    const int type = header[pos] & 0x7F;
    if (type != 0 && type != 3) {
      break;
    }
    const bool last = (header[pos] & 0x80) != 0;
    pos += 4 + ((size_t)header[pos + 1] << 16 | (size_t)header[pos + 2] << 8 | header[pos + 3]);
    if (last) {
      break;
    }
  }
  return std::min(pos, header.size());
}

int encode_stream(const EncodeArgs& args, File* in, File* out, bool* created) {
  PcmLayout layout;
  uint64_t data_bytes = kUnknownLength;
  if (args.raw) {
    layout = raw_layout(args.raw_rate, args.raw_channels, args.raw_bits);
    /* A regular file says how long it is; a pipe does not, and a
     * directory on ext4 seeks to an end offset that is no length. */
    if (in->owned && is_regular_file(args.in) && fseek(in->f, 0, SEEK_END) == 0) {
      const long size = ftell(in->f);
      if (size >= 0 && fseek(in->f, 0, SEEK_SET) == 0) {
        data_bytes = (uint64_t)size;
      }
    }
  } else if (!read_wav_header(in, &layout, &data_bytes)) {
    return 1;
  }
  int error = AAC_OK;
  BaacFlacEncoder* enc = baac_flac_encoder_create(layout.sample_rate, layout.channels, layout.bits,
                                                  args.level, &error);
  if (!enc) {
    fprintf(stderr,
            "baac: %s: %d Hz, %d channels, %d bits cannot be coded as FLAC (%s); the encoder "
            "takes 1 to 8 channels of 8, 12, 16, 20, 24 or 32 bits\n",
            in->name.c_str(), layout.sample_rate, layout.channels, layout.bits, error_name(error));
    return 1;
  }
  const EncoderHandle owner(enc);

  /* Tags and pictures are settled before the output exists: what the
   * format does not take is refused with nothing written. */
  BaacFlacEncoderOptions options;
  baac_flac_encoder_options_init(&options);
  std::vector<BaacFlacComment> comments;
  for (const std::string& tag : args.tags) {
    comments.push_back({tag.data(), (uint32_t)tag.size()});
  }
  std::vector<PictureFile> files(args.pictures.size());
  std::vector<BaacFlacPicture> pictures(args.pictures.size());
  for (size_t i = 0; i < files.size(); i++) {
    if (!read_picture(args.pictures[i], &files[i])) {
      return 1;
    }
    BaacFlacPicture& p = pictures[i];
    p = BaacFlacPicture();
    p.type = 3;
    p.mime_type = files[i].mime.c_str();
    p.width = files[i].width;
    p.height = files[i].height;
    p.depth = files[i].depth;
    p.colors = files[i].colors;
    p.data = files[i].data.data();
    p.data_bytes = (uint32_t)files[i].data.size();
  }
  options.comments = comments.data();
  options.comment_count = (int)comments.size();
  options.pictures = pictures.data();
  options.picture_count = (int)pictures.size();
  if (baac_flac_encoder_set_options(enc, &options) != AAC_OK) {
    fprintf(stderr,
            "baac: the tags or pictures cannot be stored: a tag is NAME=VALUE with a name of "
            "printable ASCII and a value in UTF-8, and tags and each picture have to fit a "
            "block of %d bytes\n",
            BAAC_FLAC_MAX_METADATA_BLOCK_BYTES);
    return 1;
  }

  const size_t frame_bytes = (size_t)layout.channels * (size_t)layout.container;
  if (!open_file(out, args.out, true)) {
    return 1;
  }
  *created = true;
  const bool rewrite = can_rewrite(*out);
  /* STREAMINFO counts samples in 36 bits; a longer input is coded without
   * the count, as from a pipe. */
  if (rewrite && data_bytes != kUnknownLength && (data_bytes / frame_bytes) >> 36 == 0) {
    options.total_samples_hint = data_bytes / frame_bytes;
    options.seek_table = 1;
  }
  if ((error = baac_flac_encoder_set_options(enc, &options)) != AAC_OK) {
    fprintf(stderr, "baac: encoder options: %s\n", error_name(error));
    return 1;
  }

  std::vector<uint8_t> header((size_t)baac_flac_encoder_header_bytes(enc));
  const int block = baac_flac_encoder_block_size(enc);
  const int bound = baac_flac_encoder_max_output_bytes(enc, kChunkFrames + block);
  if (bound < 0) {
    fprintf(stderr, "baac: encoder: %s\n", error_name(bound));
    return 1;
  }
  std::vector<uint8_t> bytes((size_t)kChunkFrames * frame_bytes);
  std::vector<int32_t> pcm((size_t)kChunkFrames * (size_t)layout.channels);
  std::vector<uint8_t> coded((size_t)bound);

  int n = baac_flac_encoder_write_header(enc, header.data(), (int)header.size());
  if (n < 0 || fwrite(header.data(), 1, (size_t)n, out->f) != (size_t)n) {
    fprintf(stderr, "baac: cannot write %s\n", out->name.c_str());
    return 1;
  }
  uint64_t left = data_bytes;
  for (;;) {
    const size_t want = (size_t)std::min<uint64_t>(bytes.size(), left);
    const size_t got = want ? read_fully(in, bytes.data(), want) : 0;
    if (got < want && read_failed(*in)) {
      return 1;
    }
    if (left != kUnknownLength) {
      left -= got;
    }
    const size_t frames = got / frame_bytes;
    if (got % frame_bytes != 0 || (got < want && data_bytes != kUnknownLength)) {
      fprintf(stderr, "baac: %s ends inside its samples\n", in->name.c_str());
      return 1;
    }
    if (frames == 0) {
      break;
    }
    if (!unpack(layout, bytes.data(), frames * (size_t)layout.channels, pcm.data())) {
      fprintf(stderr,
              "baac: %s states %d-bit samples but has bits set below them; nothing is coded "
              "with loss\n",
              in->name.c_str(), layout.bits);
      return 1;
    }
    n = baac_flac_encoder_encode(enc, pcm.data(), (int)frames, coded.data(), (int)coded.size());
    if (n < 0) {
      fprintf(stderr, "baac: %s: %s\n", in->name.c_str(),
              n == AAC_ERR_INVALID_ARG ? "a sample lies outside the bit depth" : error_name(n));
      return 1;
    }
    if (fwrite(coded.data(), 1, (size_t)n, out->f) != (size_t)n) {
      fprintf(stderr, "baac: cannot write %s\n", out->name.c_str());
      return 1;
    }
  }
  n = baac_flac_encoder_finish(enc, coded.data(), (int)coded.size());
  if (n < 0 || fwrite(coded.data(), 1, (size_t)n, out->f) != (size_t)n) {
    fprintf(stderr, "baac: cannot finish %s\n", out->name.c_str());
    return 1;
  }
  if (rewrite) {
    /* The header again, now with the total, the MD5 and the seek
     * points; it has the length of the first one, and only its
     * beginning differs from it. */
    n = baac_flac_encoder_final_header(enc, header.data(), (int)header.size());
    const size_t changed = n == (int)header.size() ? header_prefix(header) : 0;
    if (n != (int)header.size() || fflush(out->f) != 0 || fseek(out->f, 0, SEEK_SET) != 0 ||
        fwrite(header.data(), 1, changed, out->f) != changed) {
      fprintf(stderr, "baac: cannot rewrite the header of %s\n", out->name.c_str());
      return 1;
    }
  }
  if (!out->close()) {
    fprintf(stderr, "baac: cannot write %s\n", out->name.c_str());
    return 1;
  }
  return 0;
}

int encode(int argc, char** argv) {
  EncodeArgs args;
  int positional = 0;
  for (int i = 0; i < argc; i++) {
    const char* arg = argv[i];
    if (strcmp(arg, "--level") == 0 && i + 1 < argc) {
      if (!parse_int(argv[++i], &args.level) || args.level < BAAC_FLAC_LEVEL_MIN ||
          args.level > BAAC_FLAC_LEVEL_MAX) {
        fprintf(stderr, "baac: --level takes %d to %d\n", BAAC_FLAC_LEVEL_MIN, BAAC_FLAC_LEVEL_MAX);
        return 2;
      }
    } else if (strcmp(arg, "--raw") == 0 && i + 3 < argc) {
      args.raw = true;
      if (!parse_int(argv[i + 1], &args.raw_rate) || !parse_int(argv[i + 2], &args.raw_channels) ||
          !parse_int(argv[i + 3], &args.raw_bits) || args.raw_bits < 1 || args.raw_bits > 32 ||
          args.raw_channels < 1) {
        fprintf(stderr, "baac: --raw takes a sample rate, a channel count and a bit depth\n");
        return 2;
      }
      i += 3;
    } else if (strcmp(arg, "--tag") == 0 && i + 1 < argc) {
      args.tags.push_back(argv[++i]);
      if (args.tags.back().find('=') == std::string::npos) {
        fprintf(stderr, "baac: --tag takes NAME=VALUE, and \"%s\" has no '='\n", argv[i]);
        return 2;
      }
    } else if (strcmp(arg, "--picture") == 0 && i + 1 < argc) {
      args.pictures.push_back(argv[++i]);
    } else if (arg[0] == '-' && arg[1] != '\0') {
      return usage();
    } else if (positional == 0) {
      args.in = arg;
      positional++;
    } else if (positional == 1) {
      args.out = arg;
      positional++;
    } else {
      return usage();
    }
  }
  if (positional != 2) {
    return usage();
  }
  File in;
  File out;
  if (!open_file(&in, args.in, false)) {
    return 1;
  }
  bool created = false;
  const int status = encode_stream(args, &in, &out, &created);
  if (status != 0 && created) {
    out.close();
    if (!is_dash(args.out) && is_regular_file(args.out)) {
      remove(args.out);
    }
  }
  return status;
}

/* ── dec and test ──────────────────────────────────────────────── */

struct DecodeArgs {
  bool md5 = true;
  bool raw = false;
  const char* in = nullptr;
  const char* out = nullptr; /* none: test */
};

int decode_stream(const DecodeArgs& args, File* in, File* out) {
  const char* name = in->name.c_str();
  BaacFlacDecoder* dec = baac_flac_decoder_create();
  if (!dec) {
    fprintf(stderr, "baac: out of memory\n");
    return 1;
  }
  const DecoderHandle owner(dec);
  baac_flac_decoder_set_md5_check(dec, args.md5 ? 1 : 0);

  /* The sample buffer starts at a block of the usual size and grows
   * once to the largest a stream can have, when a frame asks for it. */
  size_t pcm_size = (size_t)4096 * BAAC_FLAC_MAX_CHANNELS;
  std::unique_ptr<int32_t[]> pcm(new int32_t[pcm_size]);
  std::unique_ptr<uint8_t[]> input(new uint8_t[kIoBuffer]);
  std::vector<uint8_t> bytes;

  PcmLayout layout;
  bool have_layout = false;
  bool can_patch = false;
  size_t header_length = 0;
  BaacFlacStreamInfo info = {};
  uint64_t decoded = 0;  /* samples per channel */
  uint64_t expected = 0; /* position the next frame should have */
  int failures = 0;
  bool ended = false;
  bool fatal = false;

  /* Knows the stream's format from here on, and starts the output. */
  const auto begin_output = [&]() -> bool {
    if (baac_flac_decoder_stream_info(dec, &info) != AAC_OK) {
      return false;
    }
    layout = raw_layout(info.sample_rate, info.channels, info.bits_per_sample);
    if (!args.raw) {
      layout.shift = layout.container * 8 - layout.bits;
      layout.offset = layout.container == 1;
    }
    have_layout = true;
    if (!out || args.raw) {
      return true;
    }
    /* The lengths are patched at the end when the file can seek; into a
     * pipe the header states the total the stream announces, or leaves
     * the lengths open. */
    can_patch = can_rewrite(*out);
    const uint64_t frame_bytes = (uint64_t)layout.channels * (uint64_t)layout.container;
    const uint64_t data =
        info.total_samples && !can_patch ? info.total_samples * frame_bytes : 0xFFFFFFFFull;
    uint8_t header[68];
    header_length = wav_header(layout, data, header);
    return fwrite(header, 1, header_length, out->f) == header_length;
  };

  while (!fatal) {
    size_t got = 0;
    if (!ended) {
      got = fread(input.get(), 1, kIoBuffer, in->f);
      if (got == 0) {
        if (read_failed(*in)) {
          fatal = true;
          break;
        }
        baac_flac_decoder_end_input(dec);
        ended = true;
      }
    }
    size_t pos = 0;
    bool idle = false;
    while (!fatal && !idle) {
      int took = 0;
      if (pos < got) {
        took = baac_flac_decoder_feed(dec, input.get() + pos, (int)(got - pos));
        if (took < 0) {
          fprintf(stderr, "baac: %s: %s\n", name, error_name(took));
          fatal = true;
          break;
        }
        pos += (size_t)took;
      }
      const int n = baac_flac_decoder_decode_next(dec, pcm.get(), (int)pcm_size);
      if (n == 0) {
        idle = pos == got;
        if (!idle && took == 0) {
          /* A full FIFO always holds a frame; this cannot happen. */
          fprintf(stderr, "baac: %s: the decoder takes no more input\n", name);
          fatal = true;
        }
        continue;
      }
      if (n == AAC_ERR_OVERFLOW && pcm_size < kLargestFrame) {
        pcm_size = kLargestFrame;
        pcm.reset(new int32_t[pcm_size]);
        continue;
      }
      if (n == AAC_ERR_DECODE) {
        fprintf(stderr, "baac: %s: damaged data after sample %llu\n", name,
                (unsigned long long)expected);
        failures++;
        continue;
      }
      if (n < 0) {
        fprintf(stderr, "baac: %s: %s\n", name,
                n == AAC_ERR_UNSUPPORTED
                    ? "the stream changes its format or lacks its STREAMINFO, which is unsupported"
                    : error_name(n));
        fatal = true;
        break;
      }
      if (!have_layout && !begin_output()) {
        fprintf(stderr, "baac: cannot write %s\n", out ? out->name.c_str() : name);
        fatal = true;
        break;
      }
      BaacFlacFrameInfo frame;
      if (baac_flac_decoder_last_frame_info(dec, &frame) == AAC_OK) {
        if (frame.sample_position != expected) {
          fprintf(stderr, "baac: %s: decoding resumes at sample %llu\n", name,
                  (unsigned long long)frame.sample_position);
        }
        expected = frame.sample_position + (uint64_t)n;
      }
      decoded += (uint64_t)n;
      if (out) {
        const size_t count = (size_t)n * (size_t)layout.channels;
        bytes.resize(std::max(bytes.size(), count * (size_t)layout.container));
        pack(layout, pcm.get(), count, bytes.data());
        if (fwrite(bytes.data(), 1, count * (size_t)layout.container, out->f) !=
            count * (size_t)layout.container) {
          fprintf(stderr, "baac: cannot write %s\n", out->name.c_str());
          fatal = true;
        }
      }
    }
    if (ended) {
      break;
    }
  }
  if (fatal) {
    return 1;
  }

  BaacFlacMd5Verdict verdict = BAAC_FLAC_MD5_UNKNOWN;
  if (baac_flac_decoder_finish(dec, &verdict) != AAC_OK) {
    fprintf(stderr, "baac: %s ends inside a frame or a metadata block\n", name);
    failures++;
  }
  if (!have_layout && !begin_output()) {
    fprintf(stderr, "baac: %s is not a FLAC stream\n", name);
    return 1;
  }
  if (info.total_samples && info.total_samples != decoded) {
    fprintf(stderr, "baac: %s holds %llu samples and states %llu\n", name,
            (unsigned long long)decoded, (unsigned long long)info.total_samples);
    failures++;
  }
  if (verdict == BAAC_FLAC_MD5_MISMATCH) {
    fprintf(stderr, "baac: %s: the MD5 of the decoded samples is not the one in the header\n",
            name);
    failures++;
  }
  if (out && !args.raw) {
    const uint64_t data = decoded * (uint64_t)layout.channels * (uint64_t)layout.container;
    if (can_patch) {
      uint8_t header[68];
      wav_header(layout, data, header);
      if (data > 0xFFFFFFFFull - header_length) {
        fprintf(stderr, "baac: %s is too long for the length fields of a WAV file\n",
                out->name.c_str());
        failures++;
      }
      if (fflush(out->f) != 0 || fseek(out->f, 0, SEEK_SET) != 0 ||
          fwrite(header, 1, header_length, out->f) != header_length ||
          fseek(out->f, 0, SEEK_END) != 0) {
        fprintf(stderr, "baac: cannot rewrite the header of %s\n", out->name.c_str());
        failures++;
      }
    }
    if (data & 1) {
      fputc(0, out->f); /* a chunk of odd length is padded */
    }
  }
  if (out && !out->close()) {
    fprintf(stderr, "baac: cannot write %s\n", out->name.c_str());
    failures++;
  }
  if (!out && failures == 0) {
    printf("%s: ok, %llu samples of %d channels at %d Hz and %d bits, %s\n", name,
           (unsigned long long)decoded, info.channels, info.sample_rate, info.bits_per_sample,
           verdict == BAAC_FLAC_MD5_MATCH ? "MD5 verified"
           : args.md5                     ? "no MD5 in the stream"
                                          : "MD5 not checked");
  }
  /* ID3 tags around the stream are no FLAC and no damage: they are
   * passed over, and said.  test reports them with its result; dec,
   * whose standard output may be the audio, on standard error. */
  uint64_t id3v2_bytes = 0;
  int id3v1 = 0;
  baac_flac_decoder_id3(dec, &id3v2_bytes, &id3v1);
  FILE* notes = out ? stderr : stdout;
  if (id3v2_bytes) {
    fprintf(notes, "%s: an ID3v2 tag of %llu bytes in front of the stream was passed over\n", name,
            (unsigned long long)id3v2_bytes);
  }
  if (id3v1) {
    fprintf(notes,
            "%s: an ID3v1 tag of 128 bytes behind the last frame was passed over (flac reports "
            "such a tag as lost sync)\n",
            name);
  }
  return failures ? 1 : 0;
}

int decode(int argc, char** argv, bool test) {
  DecodeArgs args;
  int positional = 0;
  for (int i = 0; i < argc; i++) {
    const char* arg = argv[i];
    if (strcmp(arg, "--no-md5") == 0) {
      args.md5 = false;
    } else if (strcmp(arg, "--raw") == 0 && !test) {
      args.raw = true;
    } else if (arg[0] == '-' && arg[1] != '\0') {
      return usage();
    } else if (positional == 0) {
      args.in = arg;
      positional++;
    } else if (positional == 1 && !test) {
      args.out = arg;
      positional++;
    } else {
      return usage();
    }
  }
  if (positional != (test ? 1 : 2)) {
    return usage();
  }
  File in;
  File out;
  if (!open_file(&in, args.in, false) || (!test && !open_file(&out, args.out, true))) {
    return 1;
  }
  return decode_stream(args, &in, test ? nullptr : &out);
}

/* ── info ──────────────────────────────────────────────────────── */

/* Text from a stream as it goes to a terminal: line breaks and tabs
 * stay, any other control character, which could steer the terminal,
 * is shown as a question mark. */
void print_text(const char* text, size_t length) {
  for (size_t i = 0; i < length; i++) {
    const unsigned char ch = (unsigned char)text[i];
    fputc((ch < 0x20 && ch != '\n' && ch != '\t') || ch == 0x7F ? '?' : ch, stdout);
  }
}

int info(int argc, char** argv) {
  const char* in_path = nullptr;
  const char* export_path = nullptr;
  int picture_number = 1;
  for (int i = 0; i < argc; i++) {
    const char* arg = argv[i];
    if (strcmp(arg, "--export-picture") == 0 && i + 1 < argc) {
      export_path = argv[++i];
    } else if (strcmp(arg, "--picture-number") == 0 && i + 1 < argc) {
      if (!parse_int(argv[++i], &picture_number) || picture_number < 1) {
        fprintf(stderr, "baac: --picture-number counts from 1\n");
        return 2;
      }
    } else if (arg[0] == '-' && arg[1] != '\0') {
      return usage();
    } else if (!in_path) {
      in_path = arg;
    } else {
      return usage();
    }
  }
  if (!in_path) {
    return usage();
  }
  File in;
  if (!open_file(&in, in_path, false)) {
    return 1;
  }
  const char* name = in.name.c_str();
  BaacFlacDecoder* dec = baac_flac_decoder_create();
  if (!dec) {
    fprintf(stderr, "baac: out of memory\n");
    return 1;
  }
  const DecoderHandle owner(dec);
  baac_flac_decoder_set_metadata(dec, 1, 0);

  /* The header is all that is read: the input is fed until the first
   * frame is there, and that frame only says that the header is over. */
  std::unique_ptr<uint8_t[]> input(new uint8_t[kIoBuffer]);
  std::unique_ptr<int32_t[]> pcm(new int32_t[kLargestFrame]);
  int damaged = 0;
  bool done = false;
  while (!done) {
    const size_t got = fread(input.get(), 1, kIoBuffer, in.f);
    if (got == 0) {
      if (read_failed(in)) {
        return 1;
      }
      baac_flac_decoder_end_input(dec);
    }
    size_t pos = 0;
    do {
      int took = 0;
      if (pos < got) {
        took = baac_flac_decoder_feed(dec, input.get() + pos, (int)(got - pos));
        if (took < 0) {
          fprintf(stderr, "baac: %s: %s\n", name, error_name(took));
          return 1;
        }
        pos += (size_t)took;
      }
      const int n = baac_flac_decoder_decode_next(dec, pcm.get(), (int)kLargestFrame);
      if (n == AAC_ERR_DECODE) {
        damaged++;
      } else if (n != 0 || (took == 0 && pos < got)) {
        done = true; /* a frame, or a stream that cannot be decoded */
      }
    } while (!done && pos < got);
    done = done || got == 0;
  }
  BaacFlacStreamInfo stream;
  if (baac_flac_decoder_stream_info(dec, &stream) != AAC_OK) {
    fprintf(stderr, "baac: %s is not a FLAC stream\n", name);
    return 1;
  }
  const BaacFlacComment* comments = nullptr;
  BaacFlacComment vendor = {};
  const int comment_count = baac_flac_decoder_comments(dec, &comments, &vendor);
  const BaacFlacPicture* pictures = nullptr;
  const int picture_count = baac_flac_decoder_pictures(dec, &pictures);
  /* A picture sent to standard output goes there alone. */
  if (!(export_path && is_dash(export_path))) {
    printf("%s: FLAC, %d Hz, %d channels, %d bits", name, stream.sample_rate, stream.channels,
           stream.bits_per_sample);
    if (stream.total_samples && stream.sample_rate > 0) {
      const double seconds = (double)stream.total_samples / (double)stream.sample_rate;
      printf(", %llu samples (%d:%06.3f)", (unsigned long long)stream.total_samples,
             (int)(seconds / 60.0), fmod(seconds, 60.0));
    } else if (stream.total_samples) {
      /* A stream that states no sample rate has no duration to print. */
      printf(", %llu samples", (unsigned long long)stream.total_samples);
    }
    printf("\n");
    uint64_t id3v2_bytes = 0;
    baac_flac_decoder_id3(dec, &id3v2_bytes, nullptr);
    if (id3v2_bytes) {
      printf("ID3v2 tag in front of the stream: %llu bytes, passed over\n",
             (unsigned long long)id3v2_bytes);
    }
    /* info reads the header only.  The end of a file that can seek is
     * looked at for the other tag dec and test pass over. */
    uint8_t tail[3];
    if (in.owned && fseek(in.f, -128, SEEK_END) == 0 && fread(tail, 1, 3, in.f) == 3 &&
        memcmp(tail, "TAG", 3) == 0) {
      printf(
          "ID3v1 tag at the end of the file: 128 bytes, which dec and test pass over (flac "
          "reports such a tag as lost sync)\n");
    }
    if (stream.max_block_size) {
      printf("block size: %d", stream.min_block_size);
      if (stream.max_block_size != stream.min_block_size) {
        printf(" to %d", stream.max_block_size);
      }
      printf("\n");
      if (stream.max_frame_bytes) {
        printf("frame bytes: %d to %d\n", stream.min_frame_bytes, stream.max_frame_bytes);
      }
      static const uint8_t kNoMd5[16] = {0};
      if (memcmp(stream.md5, kNoMd5, 16) != 0) {
        printf("MD5: ");
        for (uint8_t b : stream.md5) {
          printf("%02x", b);
        }
        printf("\n");
      }
    }
    const BaacFlacSeekPoint* points = nullptr;
    const int seek_points = baac_flac_decoder_seek_points(dec, &points);
    if (seek_points) {
      printf("seek points: %d\n", seek_points);
    }
    if (vendor.length) {
      printf("vendor: ");
      print_text(vendor.text, vendor.length);
      printf("\n");
    }
    printf("tags: %d\n", comment_count);
    for (int i = 0; i < comment_count; i++) {
      printf("  ");
      print_text(comments[i].text, comments[i].length);
      printf("\n");
    }
    printf("pictures: %d\n", picture_count);
    for (int i = 0; i < picture_count; i++) {
      const BaacFlacPicture& p = pictures[i];
      printf("  %d: type %u (%s), ", i + 1, p.type, picture_type_name(p.type));
      print_text(p.mime_type, strlen(p.mime_type));
      printf(", %ux%u, %u bits", p.width, p.height, p.depth);
      if (p.colors) {
        printf(", %u colors", p.colors);
      }
      printf(", %u bytes", p.data_bytes);
      if (p.description[0]) {
        printf(", \"");
        print_text(p.description, strlen(p.description));
        printf("\"");
      }
      printf("\n");
    }
  }
  int status = 0;
  const int skipped = baac_flac_decoder_skipped_metadata(dec);
  if (skipped) {
    fprintf(stderr, "baac: %s: %d tag or picture blocks beyond %d bytes in all were not read\n",
            name, skipped, BAAC_FLAC_DEFAULT_METADATA_BYTES);
    status = 1;
  }
  if (damaged) {
    fprintf(stderr, "baac: %s: a metadata block is damaged\n", name);
    status = 1;
  }
  if (export_path) {
    if (picture_number > picture_count) {
      fprintf(stderr, "baac: %s has no picture %d\n", name, picture_number);
      return 1;
    }
    File out;
    const BaacFlacPicture& p = pictures[picture_number - 1];
    if (!open_file(&out, export_path, true) ||
        fwrite(p.data, 1, p.data_bytes, out.f) != p.data_bytes || !out.close()) {
      fprintf(stderr, "baac: cannot write %s\n", export_path);
      return 1;
    }
  }
  return status;
}

}  // namespace

}  // namespace baac_flac

const char* baac_flac_usage(void) {
  return "baac flac enc [--level 0..8] [--raw RATE CHANNELS BITS] [--tag NAME=VALUE]...\n"
         "                     [--picture FILE]... <in.wav | -> <out.flac | ->\n"
         "       baac flac dec [--no-md5] [--raw] <in.flac | -> <out.wav | ->\n"
         "       baac flac test [--no-md5] <in.flac | ->\n"
         "       baac flac info [--export-picture FILE [--picture-number N]] <in.flac | ->\n"
         "         integer WAV in and out (- is standard input or output); --raw is headerless\n"
         "         little-endian signed PCM, each sample in its depth rounded up to bytes;\n"
         "         --picture takes a JPEG or PNG file as a front cover\n";
}

int baac_flac_main(int argc, char** argv) {
  if (argc < 1) {
    return baac_flac::usage();
  }
  if (strcmp(argv[0], "enc") == 0) {
    return baac_flac::encode(argc - 1, argv + 1);
  }
  if (strcmp(argv[0], "dec") == 0) {
    return baac_flac::decode(argc - 1, argv + 1, false);
  }
  if (strcmp(argv[0], "test") == 0) {
    return baac_flac::decode(argc - 1, argv + 1, true);
  }
  if (strcmp(argv[0], "info") == 0) {
    return baac_flac::info(argc - 1, argv + 1);
  }
  return baac_flac::usage();
}

/* The FLAC rows of `baac bench`: the signal at 16 bits through levels 0,
 * 5 and 8, each decoded again with the MD5 check on and compared with
 * the input.  Returns the number of rows that failed. */
int baac_flac_bench(const std::vector<float>& signal, int sample_rate, int seconds,
                    const std::string& only, const std::string& dump) {
  using baac_flac::kChunkFrames;
  const int channels = 2;
  std::vector<int32_t> pcm(signal.size());
  for (size_t i = 0; i < signal.size(); i++) {
    const float v = std::max(-1.0f, std::min(1.0f, signal[i]));
    pcm[i] = (int32_t)lrintf(v * 32767.0f);
  }
  const int frames = (int)(pcm.size() / channels);
  int failures = 0;
  for (int level : {0, 5, 8}) {
    const std::string name = "FLAC level " + std::to_string(level);
    if (!only.empty() && only != name) {
      continue;
    }
    BaacFlacEncoder* enc = baac_flac_encoder_create(sample_rate, channels, 16, level, nullptr);
    if (!enc) {
      printf("%-22s unsupported\n", name.c_str());
      failures++;
      continue;
    }
    std::vector<uint8_t> stream((size_t)baac_flac_encoder_header_bytes(enc));
    const size_t header = stream.size();
    std::vector<uint8_t> coded((size_t)baac_flac_encoder_max_output_bytes(enc, 2 * kChunkFrames));
    bool ok = baac_flac_encoder_write_header(enc, stream.data(), (int)header) == (int)header;
    const double enc_start = now_seconds();
    for (int pos = 0; ok && pos < frames; pos += kChunkFrames) {
      const int n = baac_flac_encoder_encode(enc, pcm.data() + (size_t)pos * channels,
                                             std::min(kChunkFrames, frames - pos), coded.data(),
                                             (int)coded.size());
      ok = n >= 0;
      stream.insert(stream.end(), coded.begin(), coded.begin() + std::max(n, 0));
    }
    const int last = baac_flac_encoder_finish(enc, coded.data(), (int)coded.size());
    const double enc_s = now_seconds() - enc_start;
    ok = ok && last >= 0 &&
         baac_flac_encoder_final_header(enc, stream.data(), (int)header) == (int)header;
    stream.insert(stream.end(), coded.begin(), coded.begin() + std::max(last, 0));
    baac_flac_encoder_destroy(enc);

    BaacFlacDecoder* dec = baac_flac_decoder_create();
    std::vector<int32_t> out;
    out.reserve(pcm.size());
    std::vector<int32_t> block((size_t)BAAC_FLAC_MAX_BLOCK_SIZE * channels);
    BaacFlacMd5Verdict verdict = BAAC_FLAC_MD5_UNKNOWN;
    const double dec_start = now_seconds();
    ok = ok && dec && baac_flac_decoder_set_md5_check(dec, 1) == AAC_OK;
    for (size_t pos = 0; ok && pos < stream.size();) {
      const int took = baac_flac_decoder_feed(dec, stream.data() + pos,
                                              (int)std::min<size_t>(stream.size() - pos, 65536));
      pos += (size_t)std::max(took, 0);
      int n;
      int frames_out = 0;
      while ((n = baac_flac_decoder_decode_next(dec, block.data(), (int)block.size())) > 0) {
        out.insert(out.end(), block.begin(), block.begin() + (size_t)n * channels);
        frames_out++;
      }
      ok = took >= 0 && n == 0 && (took > 0 || frames_out > 0);
    }
    ok = ok && baac_flac_decoder_finish(dec, &verdict) == AAC_OK;
    const double dec_s = now_seconds() - dec_start;
    baac_flac_decoder_destroy(dec);
    ok = ok && verdict == BAAC_FLAC_MD5_MATCH && out == pcm;

    printf("%-22s %8.1f %9.3f %8.1fx %9.3f %8.1fx%s\n", name.c_str(),
           (double)stream.size() * 8.0 / (double)seconds / 1000.0, enc_s, (double)seconds / enc_s,
           dec_s, (double)seconds / dec_s, ok ? "" : "  NOT BIT-EXACT");
    if (!ok) {
      failures++;
    }
    if (!dump.empty()) {
      std::string base = dump + "/" + name;
      std::replace(base.begin(), base.end(), ' ', '_');
      FILE* f = fopen((base + ".flac").c_str(), "wb");
      if (f) {
        fwrite(stream.data(), 1, stream.size(), f);
        fclose(f);
      }
    }
  }
  return failures;
}
