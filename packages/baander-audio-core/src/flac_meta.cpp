/* Tags and pictures of a FLAC stream: the VORBIS_COMMENT block (RFC 9639
 * section 8.6) and the PICTURE block (section 8.8).
 *
 * The writing side checks what a caller hands the encoder before a byte
 * is written.  The reading side works on a block the decoder has taken
 * in whole, and treats it as hostile: every length and every count is
 * held against what is left of the block before it is used, the block
 * is found sound before anything is sized from its content, and nothing
 * here allocates. */
#include "flac_internal.h"

namespace {

/* The name the library writes as the vendor of a tag block when the
 * caller names none. */
const char kVendor[] = "Baander Audio Core";

uint8_t* put_bytes(uint8_t* p, const void* data, size_t size) {
  if (size) {
    memcpy(p, data, size);
  }
  return p + size;
}

/* A printable ASCII character: 0x20 to 0x7E. */
bool printable(char c) { return (unsigned char)c >= 0x20 && (unsigned char)c <= 0x7E; }

/* A field of a tag block: a name of printable ASCII without '=', the
 * '=', and a value in UTF-8. */
bool comment_valid(const BacFlacComment& c) {
  if (!c.text) {
    return false;
  }
  uint32_t name = 0;
  while (name < c.length && c.text[name] != '=') {
    if (!printable(c.text[name])) {
      return false;
    }
    name++;
  }
  return name > 0 && name < c.length && flac_utf8_valid(c.text + name + 1, c.length - name - 1);
}

bool printable_ascii(const char* s) {
  for (; *s; s++) {
    if (!printable(*s)) {
      return false;
    }
  }
  return true;
}

/* The length of the content of a PICTURE block, or 0 for a picture the
 * format does not take. */
uint64_t picture_block_bytes(const BacFlacPicture& p) {
  if (p.type > BAC_FLAC_MAX_PICTURE_TYPE || !p.mime_type || !printable_ascii(p.mime_type) ||
      (p.data_bytes && !p.data)) {
    return 0;
  }
  const size_t description = p.description ? strlen(p.description) : 0;
  if (description && !flac_utf8_valid(p.description, description)) {
    return 0;
  }
  return 32 + (uint64_t)strlen(p.mime_type) + description + p.data_bytes;
}

const char* vendor_of(const BacFlacEncoderOptions* o) { return o->vendor ? o->vendor : kVendor; }

bool has_comment_block(const BacFlacEncoderOptions* o) {
  return o->comment_count > 0 || o->vendor != nullptr;
}

/* The length of the content of the tag block, or 0 for tags the format
 * does not take. */
uint64_t comment_block_bytes(const BacFlacEncoderOptions* o) {
  const char* vendor = vendor_of(o);
  const size_t vendor_bytes = strlen(vendor);
  if (!flac_utf8_valid(vendor, vendor_bytes) || (o->comment_count > 0 && !o->comments)) {
    return 0;
  }
  uint64_t bytes = 4 + (uint64_t)vendor_bytes + 4;
  for (int i = 0; i < o->comment_count; i++) {
    if (!comment_valid(o->comments[i])) {
      return 0;
    }
    bytes += 4 + (uint64_t)o->comments[i].length;
    /* Past the limit the block is refused whatever follows: no count,
     * however large, makes this loop read on. */
    if (bytes > BAC_FLAC_MAX_METADATA_BLOCK_BYTES) {
      break;
    }
  }
  return bytes;
}

}  // namespace

bool flac_utf8_valid(const char* text, size_t size) {
  const unsigned char* s = (const unsigned char*)text;
  size_t i = 0;
  while (i < size) {
    const unsigned char lead = s[i];
    if (lead < 0x80) {
      i++;
      continue;
    }
    /* The lead byte says how many bytes follow and what the smallest
     * code point of that length is; shorter forms of a code point,
     * surrogates and anything past U+10FFFF are not UTF-8. */
    int more;
    uint32_t code;
    uint32_t smallest;
    if ((lead & 0xE0) == 0xC0) {
      more = 1;
      code = lead & 0x1F;
      smallest = 0x80;
    } else if ((lead & 0xF0) == 0xE0) {
      more = 2;
      code = lead & 0x0F;
      smallest = 0x800;
    } else if ((lead & 0xF8) == 0xF0) {
      more = 3;
      code = lead & 0x07;
      smallest = 0x10000;
    } else {
      return false;
    }
    if (size - i <= (size_t)more) {
      return false;
    }
    for (int k = 1; k <= more; k++) {
      if ((s[i + (size_t)k] & 0xC0) != 0x80) {
        return false;
      }
      code = (code << 6) | (s[i + (size_t)k] & 0x3F);
    }
    if (code < smallest || code > 0x10FFFF || (code >= 0xD800 && code <= 0xDFFF)) {
      return false;
    }
    i += (size_t)more + 1;
  }
  return true;
}

/* ── writing ────────────────────────────────────────────────────── */

int flac_meta_size(const BacFlacEncoderOptions* o, size_t* bytes) {
  *bytes = 0;
  if (o->comment_count < 0 || o->picture_count < 0 || (o->picture_count > 0 && !o->pictures)) {
    return AAC_ERR_INVALID_ARG;
  }
  uint64_t total = 0;
  if (has_comment_block(o)) {
    const uint64_t block = comment_block_bytes(o);
    if (block == 0 || block > BAC_FLAC_MAX_METADATA_BLOCK_BYTES) {
      return AAC_ERR_INVALID_ARG;
    }
    total += FLAC_BLOCK_HEADER_BYTES + block;
  }
  int icons[3] = {0, 0, 0};
  for (int i = 0; i < o->picture_count; i++) {
    const BacFlacPicture& p = o->pictures[i];
    const uint64_t block = picture_block_bytes(p);
    if (block == 0 || block > BAC_FLAC_MAX_METADATA_BLOCK_BYTES ||
        ((p.type == 1 || p.type == 2) && ++icons[p.type] > 1)) {
      return AAC_ERR_INVALID_ARG;
    }
    total += FLAC_BLOCK_HEADER_BYTES + block;
    /* A header has to stay within what a caller's int can state, with
     * the seek table and the padding it may have besides. */
    if (total > 0x7E000000u) {
      return AAC_ERR_INVALID_ARG;
    }
  }
  *bytes = (size_t)total;
  return AAC_OK;
}

void flac_meta_write(const BacFlacEncoderOptions* o, uint8_t* out, size_t* last_header) {
  uint8_t* p = out;
  *last_header = 0;
  if (has_comment_block(o)) {
    const char* vendor = vendor_of(o);
    const size_t vendor_bytes = strlen(vendor);
    p = flac_put_block_header(p, FLAC_BLOCK_VORBIS_COMMENT, false, (size_t)comment_block_bytes(o));
    p = flac_put_le32(p, (uint32_t)vendor_bytes);
    p = put_bytes(p, vendor, vendor_bytes);
    p = flac_put_le32(p, (uint32_t)o->comment_count);
    for (int i = 0; i < o->comment_count; i++) {
      p = flac_put_le32(p, o->comments[i].length);
      p = put_bytes(p, o->comments[i].text, o->comments[i].length);
    }
  }
  for (int i = 0; i < o->picture_count; i++) {
    const BacFlacPicture& pic = o->pictures[i];
    const size_t mime = strlen(pic.mime_type);
    const size_t description = pic.description ? strlen(pic.description) : 0;
    *last_header = (size_t)(p - out);
    p = flac_put_block_header(p, FLAC_BLOCK_PICTURE, false, (size_t)picture_block_bytes(pic));
    p = flac_put_be(p, pic.type, 4);
    p = flac_put_be(p, (uint32_t)mime, 4);
    p = put_bytes(p, pic.mime_type, mime);
    p = flac_put_be(p, (uint32_t)description, 4);
    p = put_bytes(p, pic.description, description);
    p = flac_put_be(p, pic.width, 4);
    p = flac_put_be(p, pic.height, 4);
    p = flac_put_be(p, pic.depth, 4);
    p = flac_put_be(p, pic.colors, 4);
    p = flac_put_be(p, pic.data_bytes, 4);
    p = put_bytes(p, pic.data, pic.data_bytes);
  }
}

/* ── reading ────────────────────────────────────────────────────── */

int flac_meta_count_comments(const uint8_t* block, size_t size) {
  /* The vendor string and the number of fields. */
  if (size < 8) {
    return -1;
  }
  const uint32_t vendor = flac_load_le32(block);
  if (vendor > size - 8) {
    return -1;
  }
  size_t pos = 4 + (size_t)vendor;
  const uint32_t count = flac_load_le32(block + pos);
  pos += 4;
  /* Every field has a length of four bytes at least, so a count beyond
   * a quarter of what is left cannot be true. */
  if (count > (size - pos) / 4) {
    return -1;
  }
  for (uint32_t i = 0; i < count; i++) {
    if (size - pos < 4) {
      return -1;
    }
    const uint32_t length = flac_load_le32(block + pos);
    pos += 4;
    if (length > size - pos) {
      return -1;
    }
    pos += length;
  }
  return (int)count;
}

void flac_meta_read_comments(uint8_t* block, BacFlacComment* vendor, BacFlacComment* comments) {
  /* Each string gets its closing zero from the byte behind it, which
   * holds a length that has been read by then, or is the spare byte
   * behind the block. */
  vendor->length = flac_load_le32(block);
  vendor->text = (const char*)block + 4;
  size_t pos = 4 + (size_t)vendor->length;
  const uint32_t count = flac_load_le32(block + pos);
  block[pos] = 0;
  pos += 4;
  for (uint32_t i = 0; i < count; i++) {
    comments[i].length = flac_load_le32(block + pos);
    comments[i].text = (const char*)block + pos + 4;
    if (i > 0) {
      block[pos] = 0; /* closes the field before */
    }
    pos += 4 + (size_t)comments[i].length;
  }
  if (count > 0) {
    block[pos] = 0;
  }
}

bool flac_meta_read_picture(uint8_t* block, size_t size, BacFlacPicture* picture) {
  /* Type and media type, description, four figures and the data: every
   * length against what is left once the fixed fields behind it are
   * counted. */
  if (size < 32) {
    return false;
  }
  size_t left = size - 32;
  picture->type = (uint32_t)flac_load_be(block, 4);
  const uint32_t mime = (uint32_t)flac_load_be(block + 4, 4);
  size_t pos = 8;
  if (mime > left) {
    return false;
  }
  left -= mime;
  const size_t mime_at = pos;
  pos += mime;
  const uint32_t description = (uint32_t)flac_load_be(block + pos, 4);
  if (description > left) {
    return false;
  }
  left -= description;
  const size_t description_length_at = pos;
  const size_t description_at = pos + 4;
  pos = description_at + description;
  picture->width = (uint32_t)flac_load_be(block + pos, 4);
  picture->height = (uint32_t)flac_load_be(block + pos + 4, 4);
  picture->depth = (uint32_t)flac_load_be(block + pos + 8, 4);
  picture->colors = (uint32_t)flac_load_be(block + pos + 12, 4);
  const uint32_t data = (uint32_t)flac_load_be(block + pos + 16, 4);
  const size_t figures_at = pos;
  pos += 20;
  /* The data is the rest of the block, no more and no less. */
  if (data != left) {
    return false;
  }
  picture->mime_type = (const char*)block + mime_at;
  picture->description = (const char*)block + description_at;
  picture->data = block + pos;
  picture->data_bytes = data;
  /* The closing zeros go into the length fields behind the strings,
   * which have been read. */
  block[description_length_at] = 0;
  block[figures_at] = 0;
  return true;
}
