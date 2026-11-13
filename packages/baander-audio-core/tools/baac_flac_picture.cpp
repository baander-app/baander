/* Pictures for the lossless commands of the baac tool
 * (baac_flac_picture.h). */
#include "baac_flac_picture.h"

#include <algorithm>
#include <cstdio>
#include <cstring>

#include "baac_flac_pcm.h"
#include "flac.h"

namespace baac_flac {

namespace {

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

}  // namespace

bool read_picture(const char* path, PictureFile* pic) {
  File in;
  if (!open_file(&in, path, false)) {
    return false;
  }
  const auto too_large = [path]() {
    fprintf(stderr,
            "baac: %s is larger than a FLAC picture block holds (%d bytes with its media type)\n",
            path, BAAC_FLAC_MAX_PICTURE_BYTES);
    return false;
  };
  /* A file says how long it is, and gets a buffer of that length and
   * one byte, read at once: the byte shows a file that has grown since,
   * which is then read on like input of unknown length.  That is read
   * in steps that grow with it, up to one byte past what a block holds,
   * so that a small cover takes a small buffer. */
  const size_t limit = (size_t)BAAC_FLAC_MAX_PICTURE_BYTES + 1;
  size_t step = 65536;
  if (in.owned && fseek(in.f, 0, SEEK_END) == 0) {
    const long length = ftell(in.f);
    if (length < 0 || fseek(in.f, 0, SEEK_SET) != 0) {
      fprintf(stderr, "baac: cannot open %s\n", path);
      return false;
    }
    if ((unsigned long)length > (unsigned long)BAAC_FLAC_MAX_PICTURE_BYTES) {
      return too_large();
    }
    step = (size_t)length + 1;
  }
  size_t got = 0;
  for (; got < limit; step *= 2) {
    const size_t want = std::min(step, limit - got);
    pic->data.resize(got + want);
    const size_t n = read_fully(&in, pic->data.data() + got, want);
    got += n;
    if (n < want) {
      break;
    }
  }
  if (read_failed(in)) {
    return false;
  }
  if (got > BAAC_FLAC_MAX_PICTURE_BYTES) {
    return too_large();
  }
  pic->data.resize(got);
  if (pic->data.capacity() > got + 1) {
    pic->data.shrink_to_fit();
  }
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

}  // namespace baac_flac
