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
 * no output file, dec leaves what could be decoded. */
#include <algorithm>
#include <chrono>
#include <cmath>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <memory>
#include <string>
#include <vector>

#include "flac.h"

int baac_flac_main(int argc, char** argv);
const char* baac_flac_usage(void);
int baac_flac_bench(const std::vector<float>& pcm, int sample_rate, int seconds,
                    const std::string& only, const std::string& dump);

namespace {

const size_t kIoBuffer = 1 << 18; /* of an output stream, and of what dec reads at a time */
const int kChunkFrames = 16384;   /* samples per channel enc reads at a time */

/* ── files ─────────────────────────────────────────────────────── */

struct File {
  FILE* f = nullptr;
  std::string name; /* for messages */
  bool owned = false;
  std::unique_ptr<char[]> buffer;

  ~File() { close(); }
  bool close() {
    bool ok = true;
    if (f) {
      ok = fflush(f) == 0 && !ferror(f);
      if (owned) {
        ok = fclose(f) == 0 && ok;
      }
      f = nullptr;
    }
    return ok;
  }
};

bool is_dash(const char* path) { return strcmp(path, "-") == 0; }

bool open_file(File* file, const char* path, bool write) {
  if (is_dash(path)) {
    file->f = write ? stdout : stdin;
    file->name = write ? "standard output" : "standard input";
  } else {
    file->f = fopen(path, write ? "wb" : "rb");
    file->name = path;
    file->owned = true;
  }
  if (!file->f) {
    fprintf(stderr, "baac: cannot %s %s\n", write ? "create" : "open", path);
    return false;
  }
  /* Output is gathered into large writes.  Input is read in pieces as
   * large, which go from the file straight into the caller's memory. */
  if (write) {
    file->buffer.reset(new char[kIoBuffer]);
    setvbuf(file->f, file->buffer.get(), _IOFBF, kIoBuffer);
  }
  return true;
}

/* Reads until `size` bytes are there or the input ends. */
size_t read_fully(File* in, uint8_t* p, size_t size) {
  size_t got = 0;
  while (got < size) {
    const size_t n = fread(p + got, 1, size - got, in->f);
    if (n == 0) {
      break;
    }
    got += n;
  }
  return got;
}

bool skip_bytes(File* in, uint64_t count) {
  uint8_t buf[4096];
  while (count) {
    const size_t want = (size_t)std::min<uint64_t>(count, sizeof(buf));
    if (read_fully(in, buf, want) != want) {
      return false;
    }
    count -= want;
  }
  return true;
}

uint32_t le16(const uint8_t* p) { return (uint32_t)p[0] | (uint32_t)p[1] << 8; }
uint32_t le32(const uint8_t* p) {
  return (uint32_t)p[0] | (uint32_t)p[1] << 8 | (uint32_t)p[2] << 16 | (uint32_t)p[3] << 24;
}
void put_le16(uint8_t* p, uint32_t v) {
  p[0] = (uint8_t)v;
  p[1] = (uint8_t)(v >> 8);
}
void put_le32(uint8_t* p, uint32_t v) {
  put_le16(p, v);
  put_le16(p + 2, v >> 16);
}

/* ── integer PCM in files ──────────────────────────────────────── */

/* How samples lie in a file.  A WAV file stores a sample at the top of
 * its container (a 20-bit sample in three bytes has four zero bits
 * below it) and one-byte samples unsigned; raw PCM stores the value
 * itself, signed. */
struct PcmLayout {
  int sample_rate = 0;
  int channels = 0;
  int bits = 0;        /* of a sample */
  int container = 0;   /* bytes per sample in the file */
  int shift = 0;       /* bits below the sample in its container */
  bool offset = false; /* one-byte samples are unsigned */
};

/* The channel mask the extensible format states for FLAC's order of
 * each channel count (RFC 9639 section 9.1.3), which is the order WAV
 * lists those speakers in as well. */
const uint32_t kChannelMask[BAAC_FLAC_MAX_CHANNELS + 1] = {0,    0x4,  0x3,   0x7,  0x33,
                                                           0x37, 0x3F, 0x70F, 0x63F};

const uint8_t kPcmSubformat[14] = {0x00, 0x00, 0x00, 0x00, 0x10, 0x00, 0x80,
                                   0x00, 0x00, 0xAA, 0x00, 0x38, 0x9B, 0x71};

const uint64_t kUnknownLength = UINT64_MAX;

/* Reads a WAV file up to the first byte of its samples.  `*data_bytes`
 * is the length the data chunk states, or kUnknownLength for a file
 * written into a pipe.  Says what is wrong and returns false for
 * anything that is not integer PCM the encoder takes. */
bool read_wav_header(File* in, PcmLayout* layout, uint64_t* data_bytes) {
  const char* name = in->name.c_str();
  uint8_t head[12];
  if (read_fully(in, head, 12) != 12 || memcmp(head, "RIFF", 4) != 0 ||
      memcmp(head + 8, "WAVE", 4) != 0) {
    fprintf(stderr, "baac: %s is not a WAV file (use --raw for headerless PCM)\n", name);
    return false;
  }
  bool have_format = false;
  for (;;) {
    uint8_t chunk[8];
    if (read_fully(in, chunk, 8) != 8) {
      fprintf(stderr, "baac: %s has no %s chunk\n", name, have_format ? "data" : "fmt");
      return false;
    }
    const uint32_t size = le32(chunk + 4);
    if (memcmp(chunk, "data", 4) == 0) {
      if (!have_format) {
        fprintf(stderr, "baac: %s has its samples before its format\n", name);
        return false;
      }
      *data_bytes = size == 0xFFFFFFFFu ? kUnknownLength : size;
      return true;
    }
    if (memcmp(chunk, "fmt ", 4) != 0) {
      if (!skip_bytes(in, (uint64_t)size + (size & 1))) {
        fprintf(stderr, "baac: %s ends inside a chunk\n", name);
        return false;
      }
      continue;
    }
    uint8_t fmt[40];
    if (size < 16 ||
        read_fully(in, fmt, std::min<size_t>(size, sizeof(fmt))) !=
            std::min<size_t>(size, sizeof(fmt)) ||
        (size > sizeof(fmt) && !skip_bytes(in, size - sizeof(fmt))) ||
        ((size & 1) && !skip_bytes(in, 1))) {
      fprintf(stderr, "baac: %s has a format chunk that is too short\n", name);
      return false;
    }
    uint32_t tag = le16(fmt);
    const uint32_t channels = le16(fmt + 2);
    const uint32_t rate = le32(fmt + 4);
    const uint32_t block_align = le16(fmt + 12);
    uint32_t bits = le16(fmt + 14);
    uint32_t mask = 0;
    if (tag == 0xFFFE) {
      /* Extensible: the valid bits of a sample, the speakers, and the
       * format proper as the first two bytes of a GUID. */
      if (size < 40 || le16(fmt + 16) < 22) {
        fprintf(stderr, "baac: %s has an extensible format chunk that is too short\n", name);
        return false;
      }
      const uint32_t valid = le16(fmt + 18);
      mask = le32(fmt + 20);
      tag = le16(fmt + 24);
      if (memcmp(fmt + 26, kPcmSubformat, sizeof(kPcmSubformat)) != 0) {
        tag = 0; /* not one of the registered sample formats */
      }
      if (valid) {
        bits = valid;
      }
    }
    if (tag == 3) {
      fprintf(stderr,
              "baac: %s holds IEEE float samples (WAV format 3); the lossless mode takes "
              "integer PCM only\n",
              name);
      return false;
    }
    if (tag != 1) {
      fprintf(stderr, "baac: %s is WAV format 0x%04x; the lossless mode takes integer PCM only\n",
              name, tag);
      return false;
    }
    if (channels < 1 || channels > BAAC_FLAC_MAX_CHANNELS || block_align % channels != 0) {
      fprintf(stderr, "baac: %s has %u channels; FLAC holds 1 to %d\n", name, channels,
              BAAC_FLAC_MAX_CHANNELS);
      return false;
    }
    const uint32_t container = block_align / channels;
    if (container < 1 || container > 4 || bits < 1 || bits > container * 8) {
      fprintf(stderr, "baac: %s has %u-bit samples in %u bytes, which is not integer PCM\n", name,
              bits, container);
      return false;
    }
    layout->sample_rate = (int)rate;
    layout->channels = (int)channels;
    layout->bits = (int)bits;
    layout->container = (int)container;
    layout->shift = (int)(container * 8 - bits);
    layout->offset = container == 1;
    if (mask && mask != kChannelMask[channels] && !(channels == 6 && mask == 0x60F) &&
        !(channels == 5 && mask == 0x607)) {
      fprintf(stderr,
              "baac: note: %s states the speakers 0x%x; FLAC keeps the channels in their order "
              "and not this mask\n",
              name, mask);
    }
    have_format = true;
  }
}

/* The header of a WAV file of `data_bytes` of samples: the plain format
 * for one or two channels of 8 or 16 bits, the extensible one for
 * everything else.  Returns its length. */
size_t wav_header(const PcmLayout& layout, uint64_t data_bytes, uint8_t out[68]) {
  const bool extensible =
      layout.channels > 2 || layout.bits > 16 || layout.bits != layout.container * 8;
  const size_t length = extensible ? 68 : 44;
  const uint32_t data = data_bytes > 0xFFFFFFFFull - length ? 0xFFFFFFFFu : (uint32_t)data_bytes;
  memcpy(out, "RIFF", 4);
  put_le32(out + 4, data == 0xFFFFFFFFu ? data : data + (uint32_t)length - 8 + (data & 1));
  memcpy(out + 8, "WAVEfmt ", 8);
  put_le32(out + 16, extensible ? 40 : 16);
  put_le16(out + 20, extensible ? 0xFFFE : 1);
  put_le16(out + 22, (uint32_t)layout.channels);
  put_le32(out + 24, (uint32_t)layout.sample_rate);
  put_le32(out + 28, (uint32_t)(layout.sample_rate * layout.channels * layout.container));
  put_le16(out + 32, (uint32_t)(layout.channels * layout.container));
  put_le16(out + 34, (uint32_t)(layout.container * 8));
  uint8_t* p = out + 36;
  if (extensible) {
    put_le16(p, 22);
    put_le16(p + 2, (uint32_t)layout.bits);
    put_le32(p + 4, kChannelMask[layout.channels]);
    put_le16(p + 8, 1);
    memcpy(p + 10, kPcmSubformat, sizeof(kPcmSubformat));
    p += 24;
  }
  memcpy(p, "data", 4);
  put_le32(p + 4, data);
  return length;
}

/* `count` samples from their bytes in a file.  False when a sample has
 * bits set below its depth: the file is then not audio of that depth,
 * and dropping the bits would lose them.  The containers are read in
 * loops with nothing else in them, and the bits below the samples are
 * dealt with in a pass of their own, which most files do not need. */
bool unpack(const PcmLayout& layout, const uint8_t* bytes, size_t count, int32_t* pcm) {
  switch (layout.container) {
    case 1:
      if (layout.offset) {
        for (size_t i = 0; i < count; i++) {
          pcm[i] = (int32_t)bytes[i] - 128;
        }
      } else {
        for (size_t i = 0; i < count; i++) {
          pcm[i] = (int8_t)bytes[i];
        }
      }
      break;
    case 2:
      for (size_t i = 0; i < count; i++) {
        pcm[i] = (int16_t)(bytes[2 * i] | bytes[2 * i + 1] << 8);
      }
      break;
    case 3:
      for (size_t i = 0; i < count; i++) {
        const uint32_t u = (uint32_t)bytes[3 * i] | (uint32_t)bytes[3 * i + 1] << 8 |
                           (uint32_t)bytes[3 * i + 2] << 16;
        pcm[i] = (int32_t)(u << 8) >> 8;
      }
      break;
    default:
      for (size_t i = 0; i < count; i++) {
        pcm[i] = (int32_t)le32(bytes + 4 * i);
      }
      break;
  }
  const int shift = layout.shift;
  if (shift == 0) {
    return true;
  }
  uint32_t low = 0;
  for (size_t i = 0; i < count; i++) {
    low |= (uint32_t)pcm[i];
    pcm[i] >>= shift;
  }
  return (low & ((1u << shift) - 1)) == 0;
}

/* `count` samples to their bytes in a file. */
void pack(const PcmLayout& layout, const int32_t* pcm, size_t count, uint8_t* bytes) {
  const int shift = layout.shift;
  switch (layout.container) {
    case 1:
      for (size_t i = 0; i < count; i++) {
        bytes[i] = (uint8_t)(((uint32_t)pcm[i] << shift) + (layout.offset ? 128u : 0u));
      }
      break;
    case 2:
      for (size_t i = 0; i < count; i++) {
        const uint32_t u = (uint32_t)pcm[i] << shift;
        bytes[2 * i] = (uint8_t)u;
        bytes[2 * i + 1] = (uint8_t)(u >> 8);
      }
      break;
    case 3:
      for (size_t i = 0; i < count; i++) {
        const uint32_t u = (uint32_t)pcm[i] << shift;
        bytes[3 * i] = (uint8_t)u;
        bytes[3 * i + 1] = (uint8_t)(u >> 8);
        bytes[3 * i + 2] = (uint8_t)(u >> 16);
      }
      break;
    default:
      for (size_t i = 0; i < count; i++) {
        put_le32(bytes + 4 * i, (uint32_t)pcm[i] << shift);
      }
      break;
  }
}

/* The layout of raw PCM of a depth: the value itself in whole bytes. */
PcmLayout raw_layout(int sample_rate, int channels, int bits) {
  PcmLayout layout;
  layout.sample_rate = sample_rate;
  layout.channels = channels;
  layout.bits = bits;
  layout.container = (bits + 7) / 8;
  return layout;
}

/* ── pictures ──────────────────────────────────────────────────── */

struct PictureFile {
  std::string mime;
  uint32_t width = 0;
  uint32_t height = 0;
  uint32_t depth = 0;  /* bits per pixel */
  uint32_t colors = 0; /* of a palette */
  std::vector<uint8_t> data;
};

uint32_t be16(const uint8_t* p) { return (uint32_t)p[0] << 8 | p[1]; }
uint32_t be32(const uint8_t* p) {
  return (uint32_t)p[0] << 24 | (uint32_t)p[1] << 16 | (uint32_t)p[2] << 8 | p[3];
}

/* The size and depth a PNG file states in its first chunk, and the
 * length of its palette when it has one. */
bool png_figures(PictureFile* pic) {
  static const uint8_t kSignature[8] = {0x89, 'P', 'N', 'G', 0x0D, 0x0A, 0x1A, 0x0A};
  const std::vector<uint8_t>& d = pic->data;
  if (d.size() < 33 || memcmp(d.data(), kSignature, 8) != 0 || memcmp(&d[12], "IHDR", 4) != 0) {
    return false;
  }
  pic->mime = "image/png";
  pic->width = be32(&d[16]);
  pic->height = be32(&d[20]);
  const uint32_t bits = d[24];
  const uint32_t color_type = d[25];
  /* Samples per pixel by colour type: grey, RGB, palette, grey with
   * alpha, RGB with alpha.  A palette entry is three bytes. */
  static const uint32_t kSamples[7] = {1, 0, 3, 1, 2, 0, 4};
  pic->depth = color_type == 3 ? 24 : bits * (color_type < 7 ? kSamples[color_type] : 0);
  if (color_type == 3) {
    for (size_t pos = 8; pos + 12 <= d.size();) {
      const uint32_t length = be32(&d[pos]);
      if (memcmp(&d[pos + 4], "PLTE", 4) == 0) {
        pic->colors = length / 3;
        break;
      }
      if (length > d.size() - pos - 12) {
        break;
      }
      pos += 12 + (size_t)length;
    }
  }
  return true;
}

/* The size and depth a JPEG file states in its frame header, which
 * follows segments that are stepped over by their lengths. */
bool jpeg_figures(PictureFile* pic) {
  const std::vector<uint8_t>& d = pic->data;
  if (d.size() < 4 || d[0] != 0xFF || d[1] != 0xD8) {
    return false;
  }
  pic->mime = "image/jpeg";
  for (size_t pos = 2; pos + 4 <= d.size();) {
    if (d[pos] != 0xFF) {
      break;
    }
    const uint8_t marker = d[pos + 1];
    if (marker == 0xFF) {
      pos++; /* a fill byte */
      continue;
    }
    if (marker == 0xD8 || (marker >= 0xD0 && marker <= 0xD7) || marker == 0x01) {
      pos += 2; /* markers without a segment */
      continue;
    }
    const size_t length = be16(&d[pos + 2]);
    const bool frame =
        marker >= 0xC0 && marker <= 0xCF && marker != 0xC4 && marker != 0xC8 && marker != 0xCC;
    if (frame && length >= 8 && pos + 10 <= d.size()) {
      pic->height = be16(&d[pos + 5]);
      pic->width = be16(&d[pos + 7]);
      pic->depth = (uint32_t)d[pos + 4] * d[pos + 9];
      break;
    }
    if (marker == 0xDA || length < 2) {
      break; /* the scan: no frame header came before it */
    }
    pos += 2 + length;
  }
  return true;
}

/* Reads a picture file whole; it has to fit a PICTURE block. */
bool read_picture(const char* path, PictureFile* pic) {
  File in;
  if (!open_file(&in, path, false)) {
    return false;
  }
  pic->data.resize((size_t)BAAC_FLAC_MAX_PICTURE_BYTES + 1);
  const size_t got = read_fully(&in, pic->data.data(), pic->data.size());
  if (got > BAAC_FLAC_MAX_PICTURE_BYTES) {
    fprintf(stderr,
            "baac: %s is larger than a FLAC picture block holds (%d bytes with its media type)\n",
            path, BAAC_FLAC_MAX_PICTURE_BYTES);
    return false;
  }
  pic->data.resize(got);
  if (!png_figures(pic) && !jpeg_figures(pic)) {
    fprintf(stderr, "baac: %s is neither a JPEG nor a PNG file\n", path);
    return false;
  }
  return true;
}

const char* picture_type_name(uint32_t type) {
  static const char* const kNames[BAAC_FLAC_MAX_PICTURE_TYPE + 1] = {
      "other",
      "PNG file icon of 32x32 pixels",
      "general file icon",
      "front cover",
      "back cover",
      "liner notes page",
      "media label",
      "lead artist, lead performer or soloist",
      "artist or performer",
      "conductor",
      "band or orchestra",
      "composer",
      "lyricist or text writer",
      "recording location",
      "during recording",
      "during performance",
      "movie or video screen capture",
      "a bright colored fish",
      "illustration",
      "band or artist logotype",
      "publisher or studio logotype",
  };
  return type <= BAAC_FLAC_MAX_PICTURE_TYPE ? kNames[type] : "reserved";
}

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

int encode_stream(const EncodeArgs& args, File* in, File* out, bool* created) {
  PcmLayout layout;
  uint64_t data_bytes = kUnknownLength;
  if (args.raw) {
    layout = raw_layout(args.raw_rate, args.raw_channels, args.raw_bits);
    /* A file says how long it is; a pipe does not. */
    if (in->owned && fseek(in->f, 0, SEEK_END) == 0) {
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
  struct Destroy {
    BaacFlacEncoder* enc;
    ~Destroy() { baac_flac_encoder_destroy(enc); }
  } destroy{enc};

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
  if (rewrite && data_bytes != kUnknownLength) {
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
     * points; it has the length of the first one. */
    n = baac_flac_encoder_final_header(enc, header.data(), (int)header.size());
    if (n != (int)header.size() || fflush(out->f) != 0 || fseek(out->f, 0, SEEK_SET) != 0 ||
        fwrite(header.data(), 1, header.size(), out->f) != header.size()) {
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
    if (!is_dash(args.out)) {
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
  struct Destroy {
    BaacFlacDecoder* dec;
    ~Destroy() { baac_flac_decoder_destroy(dec); }
  } destroy{dec};
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
      if (n == AAC_ERR_OVERFLOW && pcm_size < (size_t)BAAC_FLAC_MAX_BLOCK_SIZE * 8) {
        pcm_size = (size_t)BAAC_FLAC_MAX_BLOCK_SIZE * BAAC_FLAC_MAX_CHANNELS;
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
  struct Destroy {
    BaacFlacDecoder* dec;
    ~Destroy() { baac_flac_decoder_destroy(dec); }
  } destroy{dec};
  baac_flac_decoder_set_metadata(dec, 1, 0);

  /* The header is all that is read: the input is fed until the first
   * frame is there, and that frame only says that the header is over. */
  std::unique_ptr<uint8_t[]> input(new uint8_t[kIoBuffer]);
  std::unique_ptr<int32_t[]> pcm(new int32_t[(size_t)BAAC_FLAC_MAX_BLOCK_SIZE * 8]);
  int damaged = 0;
  bool done = false;
  while (!done) {
    const size_t got = fread(input.get(), 1, kIoBuffer, in.f);
    if (got == 0) {
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
      const int n = baac_flac_decoder_decode_next(dec, pcm.get(), BAAC_FLAC_MAX_BLOCK_SIZE * 8);
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
    if (stream.total_samples) {
      const double seconds = (double)stream.total_samples / (double)stream.sample_rate;
      printf(", %llu samples (%d:%06.3f)", (unsigned long long)stream.total_samples,
             (int)(seconds / 60.0), fmod(seconds, 60.0));
    }
    printf("\n");
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

double now_seconds() {
  using clock = std::chrono::steady_clock;
  return std::chrono::duration<double>(clock::now().time_since_epoch()).count();
}

}  // namespace

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
    return usage();
  }
  if (strcmp(argv[0], "enc") == 0) {
    return encode(argc - 1, argv + 1);
  }
  if (strcmp(argv[0], "dec") == 0) {
    return decode(argc - 1, argv + 1, false);
  }
  if (strcmp(argv[0], "test") == 0) {
    return decode(argc - 1, argv + 1, true);
  }
  if (strcmp(argv[0], "info") == 0) {
    return info(argc - 1, argv + 1);
  }
  return usage();
}

/* The FLAC rows of `baac bench`: the signal at 16 bits through levels 0,
 * 5 and 8, each decoded again with the MD5 check on and compared with
 * the input.  Returns the number of rows that failed. */
int baac_flac_bench(const std::vector<float>& signal, int sample_rate, int seconds,
                    const std::string& only, const std::string& dump) {
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
