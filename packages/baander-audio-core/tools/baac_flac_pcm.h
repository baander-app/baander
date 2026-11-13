/* Files and integer PCM for the lossless commands of the baac tool:
 * inputs and outputs, where a dash is standard input or output, the
 * header of a WAV file, and samples between their bytes in a file and
 * the codec's 32-bit integers. */
#ifndef BAANDER_AAC_TOOLS_BAAC_FLAC_PCM_H
#define BAANDER_AAC_TOOLS_BAAC_FLAC_PCM_H

#include <cstddef>
#include <cstdint>
#include <cstdio>
#include <memory>
#include <string>

namespace baac_flac {

const size_t kIoBuffer = 1 << 18; /* of an output stream, and of what dec reads at a time */

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

bool is_dash(const char* path);

/* Whether `path` names a file that holds data of its own: not a device,
 * a pipe or a directory, which a failed command must not remove. */
bool is_regular_file(const char* path);

/* Says so when a read from `in` failed, as opposed to having reached
 * the end of the input; true when it did. */
bool read_failed(const File& in);

/* Opens `path` for reading or writing, or takes standard input or
 * output for a dash.  An output is gathered into large writes. */
bool open_file(File* file, const char* path, bool write);

/* Reads until `size` bytes are there or the input ends. */
size_t read_fully(File* in, uint8_t* p, size_t size);

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

const uint64_t kUnknownLength = UINT64_MAX;

/* Reads a WAV file up to the first byte of its samples.  `*data_bytes`
 * is the length the data chunk states, or kUnknownLength for a file
 * written into a pipe.  Says what is wrong and returns false for
 * anything that is not integer PCM the encoder takes. */
bool read_wav_header(File* in, PcmLayout* layout, uint64_t* data_bytes);

/* The header of a WAV file of `data_bytes` of samples: the plain format
 * for one or two channels of 8 or 16 bits, the extensible one for
 * everything else.  Returns its length. */
size_t wav_header(const PcmLayout& layout, uint64_t data_bytes, uint8_t out[68]);

/* `count` samples from their bytes in a file.  False when a sample has
 * bits set below its depth: the file is then not audio of that depth,
 * and dropping the bits would lose them. */
bool unpack(const PcmLayout& layout, const uint8_t* bytes, size_t count, int32_t* pcm);

/* `count` samples to their bytes in a file. */
void pack(const PcmLayout& layout, const int32_t* pcm, size_t count, uint8_t* bytes);

/* The layout of raw PCM of a depth: the value itself in whole bytes. */
PcmLayout raw_layout(int sample_rate, int channels, int bits);

}  // namespace baac_flac

#endif /* BAANDER_AAC_TOOLS_BAAC_FLAC_PCM_H */
