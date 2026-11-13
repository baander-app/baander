/* Pictures for the lossless commands of the bac tool: a cover read
 * from a JPEG or PNG file with the figures its own header states, and
 * the names of the picture types. */
#ifndef BAANDER_AUDIO_CORE_TOOLS_BAC_FLAC_PICTURE_H
#define BAANDER_AUDIO_CORE_TOOLS_BAC_FLAC_PICTURE_H

#include <cstdint>
#include <string>
#include <vector>

namespace bac_flac {

struct PictureFile {
  std::string mime;
  uint32_t width = 0;
  uint32_t height = 0;
  uint32_t depth = 0;  /* bits per pixel */
  uint32_t colors = 0; /* of a palette */
  std::vector<uint8_t> data;
};

/* Reads a picture file whole and the media type, size and depth its
 * header states; it has to fit a PICTURE block, and it has to be a JPEG
 * or a PNG file.  Says what is wrong and returns false otherwise. */
bool read_picture(const char* path, PictureFile* pic);

/* The name of a picture type of the format (RFC 9639 section 8.8). */
const char* picture_type_name(uint32_t type);

}  // namespace bac_flac

#endif /* BAANDER_AUDIO_CORE_TOOLS_BAC_FLAC_PICTURE_H */
