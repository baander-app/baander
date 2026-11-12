/* Files and integer PCM for the lossless commands of the baac tool
 * (baac_flac_pcm.h). */
#include "baac_flac_pcm.h"

#include <sys/stat.h>

#include <algorithm>
#include <cstring>

#include "baac_common.h"
#include "flac.h"

namespace baac_flac {

namespace {

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

void put_le16(uint8_t* p, uint32_t v) {
  p[0] = (uint8_t)v;
  p[1] = (uint8_t)(v >> 8);
}
void put_le32(uint8_t* p, uint32_t v) {
  put_le16(p, v);
  put_le16(p + 2, v >> 16);
}

/* The channel mask the extensible format states for FLAC's order of
 * each channel count (RFC 9639 section 9.1.3), which is the order WAV
 * lists those speakers in as well. */
const uint32_t kChannelMask[BAAC_FLAC_MAX_CHANNELS + 1] = {0,    0x4,  0x3,   0x7,  0x33,
                                                           0x37, 0x3F, 0x70F, 0x63F};

const uint8_t kPcmSubformat[14] = {0x00, 0x00, 0x00, 0x00, 0x10, 0x00, 0x80,
                                   0x00, 0x00, 0xAA, 0x00, 0x38, 0x9B, 0x71};

}  // namespace

/* ── files ─────────────────────────────────────────────────────── */

bool is_dash(const char* path) { return strcmp(path, "-") == 0; }

bool is_regular_file(const char* path) {
  struct stat st;
  return stat(path, &st) == 0 && (st.st_mode & S_IFMT) == S_IFREG;
}

bool read_failed(const File& in) {
  if (!ferror(in.f)) {
    return false;
  }
  fprintf(stderr, "baac: cannot read %s\n", in.name.c_str());
  return true;
}

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

/* ── integer PCM in files ──────────────────────────────────────── */

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

/* The containers are read in loops with nothing else in them, and the
 * bits below the samples are dealt with in a pass of their own, which
 * most files do not need. */
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

PcmLayout raw_layout(int sample_rate, int channels, int bits) {
  PcmLayout layout;
  layout.sample_rate = sample_rate;
  layout.channels = channels;
  layout.bits = bits;
  layout.container = (bits + 7) / 8;
  return layout;
}

}  // namespace baac_flac
