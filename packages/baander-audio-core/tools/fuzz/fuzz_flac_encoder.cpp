/* FLAC encoder harness: the settings, tags and pictures from the first
 * bytes and the rest as PCM; the stream is then decoded with our
 * decoder, which has to return the input sample for sample, the tags
 * and pictures as given, and the total and MD5 of the input.
 *
 *   byte 0  the level (mod 10; 9 is refused)
 *   byte 1  channels (mod 10; 0 and 9 are refused)
 *   byte 2  the bit depth: 8, 12, 16, 20, 24, 32, and 17 and 4, which
 *           are refused (mod 8)
 *   byte 3  the sample rate from a table with the ends of what a frame
 *           header can state and rates it cannot (mod 16)
 *   byte 4  bit 0 a seek table, bit 1 the total as a hint, bit 2 the
 *           hint too small, bit 3 padding, bit 4 the final header is
 *           written over the first, bit 5 the decoder is fed one byte
 *           at a time, bit 6 a second stream from the same encoder,
 *           bit 7 a vendor string of the caller's
 *   byte 5  bits 0-2 the samples per encode call (1, 3, 100, 1151,
 *           1152, 4096, 5000, all); bits 3-5 how many low bits of every
 *           sample are cleared (wasted bits); bit 6 the right channel
 *           copies the left; bit 7 every sample is the first
 *   byte 6  the number of tags (mod 4); each a length byte and that
 *           many bytes, taken as they are: tags the format does not
 *           take are refused, and the stream is written without any
 *   then    the number of pictures (mod 3); each a type byte, a length
 *           byte and a media type, a length byte and a description, two
 *           length bytes and the data
 *   rest    the samples, little-endian in whole bytes, interleaved, each
 *           cut to the depth; at most 20000 per channel */
#include <algorithm>
#include <cstring>
#include <string>
#include <vector>

#include "flac.h"
#include "fuzz_driver.h"

namespace {

struct Input {
  std::vector<std::string> tags;
  std::string vendor;
  bool have_vendor = false;
  struct Picture {
    uint32_t type;
    std::string mime;
    std::string description;
    std::vector<uint8_t> data;
  };
  std::vector<Picture> pictures;
};

void check_code(int ret, const char* what) {
  if (ret != AAC_OK && ret != AAC_ERR_INVALID_ARG && ret != AAC_ERR_UNSUPPORTED &&
      ret != AAC_ERR_STATE && ret != AAC_ERR_OVERFLOW && ret != AAC_ERR_MEMORY) {
    fuzz_fail(what, ret);
  }
}

/* Encodes one stream and decodes it again. */
void one_stream(BacFlacEncoder* enc, const std::vector<int32_t>& pcm, int channels, int bits,
                size_t chunk, bool rewrite, bool slow_feed, const Input& meta, bool meta_taken,
                bool seek_table) {
  const size_t frames = pcm.size() / (size_t)channels;
  const int header_bytes = bac_flac_encoder_header_bytes(enc);
  if (header_bytes < 4 + 4 + BAC_FLAC_STREAMINFO_BYTES) {
    fuzz_fail("a header shorter than the marker and STREAMINFO", header_bytes);
  }
  std::vector<uint8_t> stream((size_t)header_bytes);
  if (bac_flac_encoder_write_header(enc, stream.data(), header_bytes) != header_bytes) {
    fuzz_fail("the header is not as long as announced", header_bytes);
  }
  std::vector<uint8_t> out;
  for (size_t pos = 0; pos < frames;) {
    const int n = (int)(chunk < frames - pos ? chunk : frames - pos);
    const int bound = bac_flac_encoder_max_output_bytes(enc, n);
    if (bound < 0) {
      fuzz_fail("no bound for a call of a few thousand samples", bound);
    }
    out.resize((size_t)bound + 1);
    const int got =
        bac_flac_encoder_encode(enc, pcm.data() + pos * (size_t)channels, n, out.data(), bound);
    if (got < 0 || got > bound) {
      fuzz_fail("encode fails on samples within the depth, or writes past its bound", got);
    }
    stream.insert(stream.end(), out.begin(), out.begin() + got);
    pos += (size_t)n;
  }
  const int bound = bac_flac_encoder_max_output_bytes(enc, 0);
  out.resize((size_t)(bound > 0 ? bound : 0) + 1);
  const int last = bac_flac_encoder_finish(enc, out.data(), bound);
  if (last < 0 || last > bound) {
    fuzz_fail("finish fails or writes past its bound", last);
  }
  stream.insert(stream.end(), out.begin(), out.begin() + last);
  BacFlacStreamInfo written;
  if (bac_flac_encoder_stream_info(enc, &written) != AAC_OK || written.total_samples != frames) {
    fuzz_fail("the encoder's total is not the input's", (long)written.total_samples);
  }
  if (rewrite && bac_flac_encoder_final_header(enc, stream.data(), header_bytes) != header_bytes) {
    fuzz_fail("the final header is not as long as the first", header_bytes);
  }

  /* Our decoder on the stream. */
  BacFlacDecoder* dec = bac_flac_decoder_create();
  if (!dec) {
    return;
  }
  bac_flac_decoder_set_md5_check(dec, 1);
  bac_flac_decoder_set_metadata(dec, 1, 0);
  std::vector<int32_t> block((size_t)4096 * (size_t)channels);
  std::vector<int32_t> decoded;
  decoded.reserve(pcm.size());
  const size_t piece = slow_feed ? 1 : stream.size();
  for (size_t pos = 0; pos < stream.size();) {
    const size_t want = piece < stream.size() - pos ? piece : stream.size() - pos;
    const int took = bac_flac_decoder_feed(dec, stream.data() + pos, (int)want);
    if (took < 0) {
      fuzz_fail("our decoder refuses the encoder's stream", took);
    }
    pos += (size_t)took;
    int n;
    int produced = 0;
    while ((n = bac_flac_decoder_decode_next(dec, block.data(), (int)block.size())) > 0) {
      decoded.insert(decoded.end(), block.begin(), block.begin() + (size_t)n * (size_t)channels);
      produced++;
    }
    if (n < 0) {
      fuzz_fail("our decoder fails on the encoder's stream", n);
    }
    if (took == 0 && produced == 0) {
      fuzz_fail("our decoder stalls on the encoder's stream", (long)pos);
    }
  }
  BacFlacMd5Verdict verdict = BAC_FLAC_MD5_UNKNOWN;
  BacFlacStreamInfo info;
  const bool have_info = bac_flac_decoder_stream_info(dec, &info) == AAC_OK;
  if (bac_flac_decoder_finish(dec, &verdict) != AAC_OK ||
      bac_flac_decoder_lost_frames(dec) != 0) {
    fuzz_fail("our decoder finds damage in the encoder's stream", 0);
  }
  if (decoded != pcm) {
    fuzz_fail("the decoded samples are not the input", (long)decoded.size());
  }
  if (!have_info || info.channels != channels || info.bits_per_sample != bits) {
    fuzz_fail("the stream's properties are not the encoder's", info.channels);
  }
  if (rewrite) {
    /* An MD5 that happens to be all zeros reads as none. */
    if (info.total_samples != frames ||
        (verdict != BAC_FLAC_MD5_MATCH && verdict != BAC_FLAC_MD5_UNKNOWN)) {
      fuzz_fail("the rewritten header's total or MD5 is not the input's", (long)verdict);
    }
    static const uint8_t kNoMd5[16] = {0};
    if (verdict == BAC_FLAC_MD5_UNKNOWN && memcmp(info.md5, kNoMd5, 16) != 0) {
      fuzz_fail("an MD5 in the header that was not compared", 0);
    }
  } else if (info.total_samples != 0 || verdict != BAC_FLAC_MD5_UNKNOWN) {
    fuzz_fail("a header that was not rewritten states a total or an MD5", (long)verdict);
  }
  if (bac_flac_decoder_first_frame_offset(dec) != header_bytes) {
    fuzz_fail("the first frame is not behind the header", header_bytes);
  }
  const BacFlacSeekPoint* points = nullptr;
  const int seek_points = bac_flac_decoder_seek_points(dec, &points);
  if (!(seek_table && rewrite) && seek_points != 0) {
    fuzz_fail("seek points in a stream that should have none", seek_points);
  }

  /* Tags and pictures as given, or none when they were refused. */
  const BacFlacComment* comments = nullptr;
  BacFlacComment vendor = {};
  const int count = bac_flac_decoder_comments(dec, &comments, &vendor);
  const BacFlacPicture* pictures = nullptr;
  const int picture_count = bac_flac_decoder_pictures(dec, &pictures);
  const size_t want_tags = meta_taken ? meta.tags.size() : 0;
  const size_t want_pictures = meta_taken ? meta.pictures.size() : 0;
  if ((size_t)count != want_tags || (size_t)picture_count != want_pictures) {
    fuzz_fail("the stream does not carry the tags and pictures that were taken", count);
  }
  for (size_t i = 0; i < want_tags; i++) {
    if (comments[i].length != meta.tags[i].size() ||
        memcmp(comments[i].text, meta.tags[i].data(), meta.tags[i].size()) != 0) {
      fuzz_fail("a tag came back changed", (long)i);
    }
  }
  if (meta_taken && meta.have_vendor && std::string(vendor.text, vendor.length) != meta.vendor) {
    fuzz_fail("the vendor came back changed", 0);
  }
  for (size_t i = 0; i < want_pictures; i++) {
    const Input::Picture& p = meta.pictures[i];
    if (pictures[i].type != p.type || p.mime != pictures[i].mime_type ||
        p.description != pictures[i].description || pictures[i].data_bytes != p.data.size() ||
        (p.data.size() && memcmp(pictures[i].data, p.data.data(), p.data.size()) != 0)) {
      fuzz_fail("a picture came back changed", (long)i);
    }
  }
  bac_flac_decoder_destroy(dec);
}

}  // namespace

extern "C" int LLVMFuzzerTestOneInput(const uint8_t* data, size_t size) {
  FuzzInput in(data, size);
  static const int kBits[8] = {8, 12, 16, 20, 24, 32, 17, 4};
  static const int kRates[16] = {44100, 48000, 96000,  192000, 8000,   22050,  655350, 1,
                                 65535, 65536, 700000, 0,      384000, 255000, 12345,  88200};
  const int level = in.byte() % 10;
  const int channels = in.byte() % 10;
  const int bits = kBits[in.byte() & 7];
  const int rate = kRates[in.byte() & 15];
  const uint8_t flags = in.byte();
  const uint8_t shape = in.byte();

  Input meta;
  const int tag_count = in.byte() % 4;
  for (int i = 0; i < tag_count; i++) {
    const uint8_t* text;
    const size_t got = in.take(in.byte(), &text);
    meta.tags.emplace_back((const char*)text, got);
  }
  const int picture_count = in.byte() % 3;
  for (int i = 0; i < picture_count; i++) {
    Input::Picture p;
    p.type = in.byte();
    const uint8_t* bytes;
    size_t got = in.take(in.byte() & 31, &bytes);
    p.mime.assign((const char*)bytes, got);
    got = in.take(in.byte() & 31, &bytes);
    p.description.assign((const char*)bytes, got);
    got = in.take(in.u16() & 2047, &bytes);
    p.data.assign(bytes, bytes + got);
    /* The strings go to the library as C strings: what lies behind a
     * zero byte is not part of them. */
    p.mime = p.mime.c_str();
    p.description = p.description.c_str();
    meta.pictures.push_back(p);
  }
  if (flags & 0x80) {
    meta.have_vendor = true;
    meta.vendor = "fuzz";
  }

  int error = 12345;
  BacFlacEncoder* enc = bac_flac_encoder_create(rate, channels, bits, level, &error);
  if (!enc) {
    if (error != AAC_ERR_INVALID_ARG && error != AAC_ERR_UNSUPPORTED && error != AAC_ERR_MEMORY) {
      fuzz_fail("create fails without a documented reason", error);
    }
    return 0;
  }
  if (error != AAC_OK) {
    fuzz_fail("create succeeds and reports an error", error);
  }

  /* The samples: whole bytes, cut to the depth. */
  const int width = (bits + 7) / 8;
  const size_t frames = std::min<size_t>(in.left() / (size_t)(width * channels), 20000);
  std::vector<int32_t> pcm(frames * (size_t)channels);
  const uint8_t* raw = in.rest();
  const int wasted = shape >> 3 & 7;
  for (size_t i = 0; i < pcm.size(); i++) {
    uint32_t v = 0;
    for (int b = 0; b < width; b++) {
      v |= (uint32_t)raw[i * (size_t)width + (size_t)b] << (8 * b);
    }
    int32_t s = (int32_t)(v << (32 - bits)) >> (32 - bits);
    s = (int32_t)((uint32_t)s & ~((1u << (wasted < bits ? wasted : bits - 1)) - 1));
    pcm[i] = s;
  }
  if (shape & 0x40) {
    for (size_t i = 0; i + 1 < pcm.size() && channels >= 2; i += (size_t)channels) {
      pcm[i + 1] = pcm[i];
    }
  }
  if ((shape & 0x80) && !pcm.empty()) {
    std::fill(pcm.begin(), pcm.end(), pcm[0]);
  }

  BacFlacEncoderOptions options;
  bac_flac_encoder_options_init(&options);
  const bool seek_table = (flags & 1) != 0;
  options.seek_table = seek_table ? 1 : 0;
  options.total_samples_hint = (flags & 2) ? ((flags & 4) ? frames / 3 : frames) : 0;
  options.padding_bytes = (flags & 8) ? 37 : 0;
  std::vector<BacFlacComment> comments;
  for (const std::string& tag : meta.tags) {
    comments.push_back({tag.data(), (uint32_t)tag.size()});
  }
  std::vector<BacFlacPicture> pictures;
  for (const Input::Picture& p : meta.pictures) {
    BacFlacPicture out = {};
    out.type = p.type;
    out.mime_type = p.mime.c_str();
    out.description = p.description.c_str();
    out.data = p.data.data();
    out.data_bytes = (uint32_t)p.data.size();
    pictures.push_back(out);
  }
  options.comments = comments.data();
  options.comment_count = (int)comments.size();
  options.vendor = meta.have_vendor ? meta.vendor.c_str() : nullptr;
  options.pictures = pictures.data();
  options.picture_count = (int)pictures.size();
  int ret = bac_flac_encoder_set_options(enc, &options);
  check_code(ret, "set_options return outside the documented codes");
  bool meta_taken = ret == AAC_OK;
  if (!meta_taken) {
    /* Refused: the encoder is as it was, and writes a stream without
     * the tags. */
    options.comments = nullptr;
    options.comment_count = 0;
    options.vendor = nullptr;
    options.pictures = nullptr;
    options.picture_count = 0;
    ret = bac_flac_encoder_set_options(enc, &options);
    if (ret != AAC_OK) {
      fuzz_fail("options without tags are refused", ret);
    }
  }
  /* A hint of a total sizes the seek table; without one there is none. */
  const bool have_seek_table = seek_table && options.total_samples_hint != 0;

  static const size_t kChunks[8] = {1, 3, 100, 1151, 1152, 4096, 5000, (size_t)1 << 30};
  size_t chunk = kChunks[shape & 7];
  if (chunk < 100 && frames > 3000) {
    chunk = 100; /* thousands of calls of one sample would only be slow */
  }
  const bool rewrite = (flags & 0x10) != 0;
  const bool slow_feed = (flags & 0x20) != 0 && frames < 3000;
  one_stream(enc, pcm, channels, bits, chunk, rewrite, slow_feed, meta, meta_taken,
             have_seek_table);
  if (flags & 0x40) {
    /* The encoder again: nothing of the first stream carries over. */
    one_stream(enc, pcm, channels, bits, 4096, true, false, meta, meta_taken, have_seek_table);
  }
  bac_flac_encoder_destroy(enc);
  return 0;
}
