/* Tags and pictures of the lossless mode through the C API: what the
 * encoder writes and the decoder reads back, in order and byte for
 * byte; what the encoder refuses before it writes anything; blocks whose
 * inner lengths lie, which the decoder drops without sizing anything
 * from them; the caller's limit on what is held; ID3 tags around a
 * stream; and the same data through metaflac, flac and FFmpeg.
 *
 *   BAAC_FLAC_REFERENCE   the path of the flac 1.4.3 binary; metaflac
 *                         is taken from the same build
 *   BAAC_REQUIRE_ORACLES  set: a missing flac, metaflac, ffmpeg or
 *                         ffprobe fails the suite instead of skipping */
#include <sys/wait.h>

#include <new>

#include "test_flac_util.h"

/* ── allocation watch ──────────────────────────────────────────── */

/* Every allocation carries its size in front, so that the bytes in use
 * and their highest mark are known.  `g_fail_in` makes allocations
 * fail: `g_fail_count` of them in a row, beginning that many
 * allocations from now (0 the next; negative, none). */
static size_t g_live = 0;
static size_t g_peak = 0;
static long g_fail_in = -1;
static int g_fail_count = 0;
static int g_failed = 0;

static void* counted_alloc(size_t size) {
  if (g_fail_in == 0) {
    if (g_fail_count > 0) {
      g_fail_count--;
      g_failed++;
      return nullptr;
    }
    g_fail_in = -1;
  } else if (g_fail_in > 0) {
    g_fail_in--;
  }
  size_t* p = (size_t*)malloc(size + 16);
  if (!p) {
    return nullptr;
  }
  p[0] = size;
  g_live += size;
  g_peak = std::max(g_peak, g_live);
  return (char*)p + 16;
}

void* operator new(size_t size) {
  void* p = counted_alloc(size);
  if (!p) {
    throw std::bad_alloc();
  }
  return p;
}
void* operator new[](size_t size) { return operator new(size); }
void* operator new(size_t size, const std::nothrow_t&) noexcept { return counted_alloc(size); }
void* operator new[](size_t size, const std::nothrow_t&) noexcept { return counted_alloc(size); }
void operator delete(void* p) noexcept {
  if (p) {
    size_t* q = (size_t*)((char*)p - 16);
    g_live -= q[0];
    free(q);
  }
}
void operator delete[](void* p) noexcept { operator delete(p); }
void operator delete(void* p, size_t) noexcept { operator delete(p); }
void operator delete[](void* p, size_t) noexcept { operator delete(p); }

/* ── helpers ───────────────────────────────────────────────────── */

static std::string g_tmp;
static std::string g_flac; /* the reference binaries, empty when missing */
static std::string g_metaflac;

struct Picture {
  uint32_t type = 3;
  std::string mime = "image/jpeg";
  std::string description;
  uint32_t width = 0, height = 0, depth = 0, colors = 0;
  std::vector<uint8_t> data;
};

struct Tags {
  bool have_vendor = false;
  std::string vendor;
  std::vector<std::string> comments;
  std::vector<Picture> pictures;
};

/* The options that carry `tags`; the vectors keep what they point at. */
struct TagOptions {
  BaacFlacEncoderOptions options;
  std::vector<BaacFlacComment> comments;
  std::vector<BaacFlacPicture> pictures;

  explicit TagOptions(const Tags& tags) {
    baac_flac_encoder_options_init(&options);
    for (const std::string& c : tags.comments) {
      comments.push_back({c.data(), (uint32_t)c.size()});
    }
    for (const Picture& p : tags.pictures) {
      BaacFlacPicture out = {};
      out.type = p.type;
      out.mime_type = p.mime.c_str();
      out.description = p.description.empty() ? nullptr : p.description.c_str();
      out.width = p.width;
      out.height = p.height;
      out.depth = p.depth;
      out.colors = p.colors;
      out.data = p.data.data();
      out.data_bytes = (uint32_t)p.data.size();
      pictures.push_back(out);
    }
    options.comments = comments.data();
    options.comment_count = (int)comments.size();
    options.vendor = tags.have_vendor ? tags.vendor.c_str() : nullptr;
    options.pictures = pictures.data();
    options.picture_count = (int)pictures.size();
  }
};

struct Read {
  FlacDecoded decoded;
  Tags tags;
  int skipped = 0;
  int64_t first_frame = -1;
  size_t peak = 0; /* the most bytes in use above those before the decode */
};

/* Decodes a stream fed in pieces of `chunk`; with `collect`, keeps tags
 * and pictures up to `cap` bytes. */
static Read read_stream(const std::vector<uint8_t>& stream, bool collect, size_t cap = 0,
                        size_t chunk = 4096) {
  Read r;
  BaacFlacDecoder* dec = baac_flac_decoder_create();
  baac_flac_decoder_set_md5_check(dec, 1);
  CHECK(baac_flac_decoder_set_metadata(dec, collect ? 1 : 0, cap) == AAC_OK,
        "the metadata setting is taken before the first byte");
  const size_t before = g_live;
  g_peak = g_live;
  r.decoded = flac_decode(stream, chunk, dec);
  /* What flac_decode itself holds (its sample buffer, the samples) is
   * not the decoder's; it is the same for every stream of a test. */
  r.peak = g_peak - before;
  const BaacFlacComment* comments = nullptr;
  BaacFlacComment vendor = {};
  const int count = baac_flac_decoder_comments(dec, &comments, &vendor);
  r.tags.vendor.assign(vendor.text, vendor.length);
  for (int i = 0; i < count; i++) {
    r.tags.comments.emplace_back(comments[i].text, comments[i].length);
    CHECK(comments[i].text[comments[i].length] == 0, "a tag is followed by a zero byte");
  }
  const BaacFlacPicture* pictures = nullptr;
  const int picture_count = baac_flac_decoder_pictures(dec, &pictures);
  for (int i = 0; i < picture_count; i++) {
    Picture p;
    p.type = pictures[i].type;
    p.mime = pictures[i].mime_type;
    p.description = pictures[i].description;
    p.width = pictures[i].width;
    p.height = pictures[i].height;
    p.depth = pictures[i].depth;
    p.colors = pictures[i].colors;
    p.data.assign(pictures[i].data, pictures[i].data + pictures[i].data_bytes);
    r.tags.pictures.push_back(p);
  }
  r.skipped = baac_flac_decoder_skipped_metadata(dec);
  r.first_frame = baac_flac_decoder_first_frame_offset(dec);
  baac_flac_decoder_destroy(dec);
  return r;
}

static bool same_picture(const Picture& a, const Picture& b) {
  return a.type == b.type && a.mime == b.mime && a.description == b.description &&
         a.width == b.width && a.height == b.height && a.depth == b.depth && a.colors == b.colors &&
         a.data == b.data;
}

static bool same_pictures(const std::vector<Picture>& a, const std::vector<Picture>& b) {
  if (a.size() != b.size()) {
    return false;
  }
  for (size_t i = 0; i < a.size(); i++) {
    if (!same_picture(a[i], b[i])) {
      return false;
    }
  }
  return true;
}

/* The metadata blocks of a stream: type and last flag of each, in
 * order. */
static std::vector<int> block_list(const std::vector<uint8_t>& s, int* last_flags) {
  std::vector<int> types;
  *last_flags = 0;
  size_t pos = 4;
  while (pos + 4 <= s.size()) {
    types.push_back(s[pos] & 0x7F);
    const bool last = (s[pos] & 0x80) != 0;
    *last_flags += last ? 1 : 0;
    pos += 4 + ((size_t)s[pos + 1] << 16 | (size_t)s[pos + 2] << 8 | s[pos + 3]);
    if (last) {
      break;
    }
  }
  return types;
}

static std::vector<uint8_t> bytes_of(size_t n, unsigned seed) {
  std::vector<uint8_t> v(n);
  for (uint8_t& b : v) {
    seed = seed * 1664525u + 1013904223u;
    b = (uint8_t)(seed >> 24);
  }
  return v;
}

static Tags sample_tags() {
  Tags t;
  t.comments = {"ARTIST=Maurice Ravel",
                "GENRE=Classical",
                "GENRE=Orchestral",
                "COMMENT=",
                "TITLE=Boléro – \xE3\x83\x9C\xE3\x83\xAC\xE3\x83\xAD \xF0\x9F\x8E\xBB",
                "LYRICS=first line\nsecond line\n",
                "~odd name!=a value with = signs == inside",
                "artist=lower case names are kept as written"};
  Picture cover;
  cover.type = 3;
  cover.mime = "image/jpeg";
  cover.description = "Front cover – \xC3\xA9";
  cover.width = 600;
  cover.height = 598;
  cover.depth = 24;
  cover.data = bytes_of(70001, 3u);
  Picture back;
  back.type = 4;
  back.mime = "image/png";
  back.width = 32;
  back.height = 32;
  back.depth = 8;
  back.colors = 16;
  back.data = bytes_of(999, 4u);
  t.pictures = {cover, back};
  return t;
}

/* ── tests ─────────────────────────────────────────────────────── */

static void test_roundtrip() {
  const std::vector<int32_t> pcm = flac_gen_tones(4096 * 3 + 100, 2, 16, 0.5, 4u);
  const FlacEncoded plain = flac_encode(pcm, 44100, 2, 16, 5);
  const Tags tags = sample_tags();
  for (int variant = 0; variant < 4; variant++) {
    TagOptions to(tags);
    const bool seek = (variant & 1) != 0;
    const bool padding = (variant & 2) != 0;
    to.options.seek_table = seek ? 1 : 0;
    to.options.total_samples_hint = seek ? pcm.size() / 2 : 0;
    to.options.padding_bytes = padding ? 1000 : 0;
    const FlacEncoded e = flac_encode(pcm, 44100, 2, 16, 5, 5000, true, &to.options);
    CHECK(e.error == AAC_OK, "variant %d: encode error %d", variant, e.error);

    /* The frames are the ones of the stream without tags. */
    CHECK(std::vector<uint8_t>(e.stream.begin() + e.header_bytes, e.stream.end()) ==
              std::vector<uint8_t>(plain.stream.begin() + plain.header_bytes, plain.stream.end()),
          "variant %d: tags and pictures leave the frames as they are", variant);

    std::vector<int> want = {FLAC_BLOCK_STREAMINFO};
    if (seek) {
      want.push_back(FLAC_BLOCK_SEEKTABLE);
    }
    want.insert(want.end(), {FLAC_BLOCK_VORBIS_COMMENT, FLAC_BLOCK_PICTURE, FLAC_BLOCK_PICTURE});
    if (padding) {
      want.push_back(FLAC_BLOCK_PADDING);
    }
    int last_flags = 0;
    CHECK(block_list(e.stream, &last_flags) == want && last_flags == 1,
          "variant %d: the blocks are in order and the last one says so", variant);

    const Read r = read_stream(e.stream, true);
    CHECK(r.decoded.errors.empty() && r.decoded.pcm == pcm &&
              r.decoded.verdict == BAAC_FLAC_MD5_MATCH,
          "variant %d: the audio decodes with its MD5 verified", variant);
    CHECK(r.tags.comments == tags.comments, "variant %d: the tags come back in order (%zu of %zu)",
          variant, r.tags.comments.size(), tags.comments.size());
    CHECK(r.tags.vendor == "bAAC", "variant %d: the vendor is the library's (%s)", variant,
          r.tags.vendor.c_str());
    CHECK(same_pictures(r.tags.pictures, tags.pictures),
          "variant %d: the pictures come back with their type, text, figures and bytes", variant);
    CHECK(r.skipped == 0 && r.first_frame == e.header_bytes,
          "variant %d: nothing skipped, and the first frame is behind the header", variant);

    /* A decoder that was not asked keeps nothing. */
    const Read quiet = read_stream(e.stream, false);
    CHECK(quiet.decoded.pcm == pcm && quiet.tags.comments.empty() && quiet.tags.pictures.empty() &&
              quiet.tags.vendor.empty() && quiet.skipped == 0,
          "variant %d: without the setting the decoder holds no tags", variant);
  }

  /* A vendor of the caller's and no tags: a block with the vendor
   * alone.  No vendor and no tags: no block. */
  Tags only_vendor;
  only_vendor.have_vendor = true;
  only_vendor.vendor = "Baander \xE2\x99\xAB";
  TagOptions to(only_vendor);
  const FlacEncoded e = flac_encode(pcm, 44100, 2, 16, 5, 5000, true, &to.options);
  const Read r = read_stream(e.stream, true, 0, 1);
  int last_flags = 0;
  CHECK(block_list(e.stream, &last_flags) ==
            std::vector<int>({FLAC_BLOCK_STREAMINFO, FLAC_BLOCK_VORBIS_COMMENT}),
        "a vendor alone makes a tag block");
  CHECK(r.tags.vendor == only_vendor.vendor && r.tags.comments.empty() && r.decoded.pcm == pcm,
        "the caller's vendor comes back, fed a byte at a time");
  CHECK(block_list(plain.stream, &last_flags) == std::vector<int>({FLAC_BLOCK_STREAMINFO}),
        "without tags the stream has no tag block");

  /* The options are copied: the caller's memory may go. */
  BaacFlacEncoder* enc = baac_flac_encoder_create(44100, 2, 16, 5, nullptr);
  {
    Tags gone = sample_tags();
    TagOptions scoped(gone);
    CHECK(baac_flac_encoder_set_options(enc, &scoped.options) == AAC_OK, "tags are accepted");
    for (std::string& c : gone.comments) {
      std::fill(c.begin(), c.end(), '#');
    }
    std::fill(gone.pictures[0].data.begin(), gone.pictures[0].data.end(), 0);
  }
  const FlacEncoded kept = flac_encode(pcm, 44100, 2, 16, 5, 5000, true, nullptr, enc);
  const Read k = read_stream(kept.stream, true);
  CHECK(k.tags.comments == tags.comments && same_pictures(k.tags.pictures, tags.pictures),
        "the encoder keeps its own copy of tags and pictures");
  /* A second stream of the same encoder carries them again, and options
   * without tags take them away. */
  const FlacEncoded second = flac_encode(pcm, 44100, 2, 16, 5, 5000, true, nullptr, enc);
  CHECK(second.stream == kept.stream, "a second stream of the encoder is the first again");
  BaacFlacEncoderOptions none;
  baac_flac_encoder_options_init(&none);
  const FlacEncoded bare = flac_encode(pcm, 44100, 2, 16, 5, 5000, true, &none, enc);
  CHECK(bare.stream == plain.stream, "options without tags give the plain stream");
  baac_flac_encoder_destroy(enc);
}

/* Whether the encoder takes the tags. */
static int accepts(const Tags& tags) {
  BaacFlacEncoder* enc = baac_flac_encoder_create(44100, 2, 16, 5, nullptr);
  TagOptions to(tags);
  const int before = baac_flac_encoder_header_bytes(enc);
  const int result = baac_flac_encoder_set_options(enc, &to.options);
  if (result != AAC_OK) {
    /* Refused before anything changed: the header is the one before. */
    CHECK(baac_flac_encoder_header_bytes(enc) == before, "a refusal leaves the encoder as it was");
    std::vector<uint8_t> header((size_t)before);
    CHECK(baac_flac_encoder_write_header(enc, header.data(), before) == before,
          "and the stream begins without the refused tags");
  }
  baac_flac_encoder_destroy(enc);
  return result;
}

static Tags with_comment(const std::string& c) {
  Tags t;
  t.comments = {"TITLE=fine", c};
  return t;
}

static Tags with_picture(const Picture& p) {
  Tags t;
  t.pictures = {p};
  return t;
}

static void test_refusals() {
  CHECK(accepts(sample_tags()) == AAC_OK, "the sample tags are accepted");
  const char* bad_comments[] = {
      "NOEQUALS",           /* no '=' */
      "=value",             /* no name */
      "NA\x7FME=x",         /* a name character past 0x7E */
      "NA\nME=x",           /* a control character in the name */
      "N\xC3\xA4ME=x",      /* a name that is not ASCII */
      "A=\xC3\x28",         /* a broken continuation byte */
      "A=\xC0\xAF",         /* a shorter form of '/' */
      "A=\xED\xA0\x80",     /* a surrogate */
      "A=\xF4\x90\x80\x80", /* past U+10FFFF */
      "A=\xE2\x82",         /* cut off */
      "A=\xFF",             /* never a UTF-8 byte */
  };
  for (const char* c : bad_comments) {
    CHECK(accepts(with_comment(c)) == AAC_ERR_INVALID_ARG, "the comment \"%s\" is refused", c);
  }
  CHECK(accepts(with_comment("A=\xF4\x8F\xBF\xBF")) == AAC_OK, "U+10FFFF is UTF-8");
  CHECK(accepts(with_comment(std::string("A=x\0y", 5))) == AAC_OK,
        "a zero byte inside a value is UTF-8 too");

  Tags vendor;
  vendor.have_vendor = true;
  vendor.vendor = "bad \xFF vendor";
  CHECK(accepts(vendor) == AAC_ERR_INVALID_ARG, "a vendor that is not UTF-8 is refused");

  Picture p;
  p.data = bytes_of(100, 1u);
  CHECK(accepts(with_picture(p)) == AAC_OK, "a plain picture is accepted");
  Picture bad = p;
  bad.type = BAAC_FLAC_MAX_PICTURE_TYPE + 1;
  CHECK(accepts(with_picture(bad)) == AAC_ERR_INVALID_ARG, "picture type 21 is refused");
  bad = p;
  bad.mime = "image/jp\x7Fg";
  CHECK(accepts(with_picture(bad)) == AAC_ERR_INVALID_ARG,
        "a media type with a character past 0x7E is refused");
  bad = p;
  bad.mime = "image/\xC3\xA9";
  CHECK(accepts(with_picture(bad)) == AAC_ERR_INVALID_ARG,
        "a media type that is not ASCII is refused");
  bad = p;
  bad.description = "broken \xC3";
  CHECK(accepts(with_picture(bad)) == AAC_ERR_INVALID_ARG,
        "a description that is not UTF-8 is refused");
  for (uint32_t type : {1u, 2u}) {
    Tags two;
    Picture icon = p;
    icon.type = type;
    icon.mime = "image/png";
    two.pictures = {icon, p, icon};
    CHECK(accepts(two) == AAC_ERR_INVALID_ARG, "a second picture of type %u is refused", type);
    two.pictures = {icon, p};
    CHECK(accepts(two) == AAC_OK, "one picture of type %u is accepted", type);
  }
  Tags covers;
  covers.pictures = {p, p, p};
  CHECK(accepts(covers) == AAC_OK, "several front covers are accepted");

  /* The block limit: a picture that fills its block to the last byte is
   * taken, one byte more is not. */
  Picture large;
  large.mime = "image/png";
  large.description = "d";
  large.data.assign((size_t)BAAC_FLAC_MAX_PICTURE_BYTES - large.mime.size() - 1, 0x5A);
  CHECK(accepts(with_picture(large)) == AAC_OK, "a picture that fills a block is accepted");
  large.data.push_back(0);
  CHECK(accepts(with_picture(large)) == AAC_ERR_INVALID_ARG,
        "a picture one byte past the block limit is refused");
  Tags lyrics;
  lyrics.comments = {"LYRICS=" + std::string((size_t)BAAC_FLAC_MAX_METADATA_BLOCK_BYTES, 'x')};
  CHECK(accepts(lyrics) == AAC_ERR_INVALID_ARG, "tags past the block limit are refused");

  /* While a stream runs the options are closed. */
  BaacFlacEncoder* enc = baac_flac_encoder_create(44100, 2, 16, 5, nullptr);
  std::vector<uint8_t> header((size_t)baac_flac_encoder_header_bytes(enc));
  baac_flac_encoder_write_header(enc, header.data(), (int)header.size());
  TagOptions to(sample_tags());
  CHECK(baac_flac_encoder_set_options(enc, &to.options) == AAC_ERR_STATE,
        "tags cannot be set while a stream runs");
  baac_flac_encoder_destroy(enc);
}

static void le32(std::vector<uint8_t>& v, uint32_t x) {
  for (int b = 0; b < 4; b++) {
    v.push_back((uint8_t)(x >> (8 * b)));
  }
}
static void be32(std::vector<uint8_t>& v, uint32_t x) {
  for (int b = 3; b >= 0; b--) {
    v.push_back((uint8_t)(x >> (8 * b)));
  }
}
static void text(std::vector<uint8_t>& v, const char* s) { v.insert(v.end(), s, s + strlen(s)); }

/* A stream by the test writer with `blocks` between STREAMINFO and the
 * frames. */
static std::vector<uint8_t> stream_with(const std::vector<std::vector<uint8_t>>& blocks,
                                        const std::vector<int32_t>& pcm) {
  const std::vector<uint8_t> plain =
      flac_build_stream(pcm, 44100, 2, 16, 1024, TestSubframe::fixed(2));
  const size_t frames_at = 4 + 4 + BAAC_FLAC_STREAMINFO_BYTES;
  std::vector<uint8_t> out(plain.begin(), plain.begin() + (long)frames_at);
  out[4] &= 0x7F; /* STREAMINFO is no longer the last block */
  for (size_t i = 0; i < blocks.size(); i++) {
    std::vector<uint8_t> block = blocks[i];
    if (i + 1 == blocks.size()) {
      block[0] |= 0x80;
    }
    flac_append(out, block);
  }
  out.insert(out.end(), plain.begin() + (long)frames_at, plain.end());
  return out;
}

/* What flac_decode holds whatever the stream: a buffer for the largest
 * block there is. */
static const size_t kHarnessBytes =
    (size_t)BAAC_FLAC_MAX_BLOCK_SIZE * BAAC_FLAC_MAX_CHANNELS * sizeof(int32_t);

/* Blocks whose lengths and counts do not fit them. */
static void test_hostile_blocks() {
  const std::vector<int32_t> pcm = flac_gen_tones(1024 * 4, 2, 16, 0.3, 6u);
  struct Case {
    const char* name;
    int type;
    std::vector<uint8_t> body;
  };
  std::vector<Case> cases;
  {
    std::vector<uint8_t> b;
    le32(b, 1000); /* a vendor longer than the block */
    text(b, "short");
    le32(b, 0);
    cases.push_back({"a vendor length past the block", FLAC_BLOCK_VORBIS_COMMENT, b});
    b.clear();
    le32(b, 0xFFFFFFFFu);
    le32(b, 0);
    cases.push_back({"a vendor length of 4 GiB", FLAC_BLOCK_VORBIS_COMMENT, b});
    b.clear();
    le32(b, 4);
    text(b, "bAAC");
    le32(b, 0xFFFFFFFFu); /* four billion fields in a block of a few bytes */
    le32(b, 3);
    text(b, "A=b");
    cases.push_back({"a field count of 4 billion", FLAC_BLOCK_VORBIS_COMMENT, b});
    b.clear();
    le32(b, 4);
    text(b, "bAAC");
    le32(b, 3); /* three fields announced, one there */
    le32(b, 3);
    text(b, "A=b");
    cases.push_back({"more fields than the block holds", FLAC_BLOCK_VORBIS_COMMENT, b});
    b.clear();
    le32(b, 4);
    text(b, "bAAC");
    le32(b, 2);
    le32(b, 3);
    text(b, "A=b");
    le32(b, 0x00FFFFFFu); /* a field of 16 MiB in a block of a few bytes */
    text(b, "C=d");
    cases.push_back({"a field length past the block", FLAC_BLOCK_VORBIS_COMMENT, b});
    cases.push_back({"a tag block of five bytes", FLAC_BLOCK_VORBIS_COMMENT, {1, 0, 0, 0, 'x'}});
    cases.push_back({"an empty tag block", FLAC_BLOCK_VORBIS_COMMENT, {}});
  }
  {
    const auto picture = [](uint32_t mime, uint32_t description, uint32_t data, size_t actual) {
      std::vector<uint8_t> b;
      be32(b, 3);
      be32(b, mime);
      text(b, "image/png");
      be32(b, description);
      text(b, "cover");
      be32(b, 1);
      be32(b, 2);
      be32(b, 24);
      be32(b, 0);
      be32(b, data);
      b.insert(b.end(), actual, 0x77);
      return b;
    };
    cases.push_back(
        {"a media type length past the block", FLAC_BLOCK_PICTURE, picture(5000, 5, 10, 10)});
    cases.push_back(
        {"a media type length of 4 GiB", FLAC_BLOCK_PICTURE, picture(0xFFFFFFFFu, 5, 10, 10)});
    cases.push_back({"a description length past the block", FLAC_BLOCK_PICTURE,
                     picture(9, 0xFFFFFFF0u, 10, 10)});
    cases.push_back(
        {"picture data longer than the block", FLAC_BLOCK_PICTURE, picture(9, 5, 0x00FFFFFFu, 10)});
    cases.push_back(
        {"picture data shorter than the block", FLAC_BLOCK_PICTURE, picture(9, 5, 4, 10)});
    cases.push_back(
        {"a picture block of 31 bytes", FLAC_BLOCK_PICTURE, std::vector<uint8_t>(31, 0)});
    cases.push_back({"an empty picture block", FLAC_BLOCK_PICTURE, {}});
  }
  /* A sound tag block rides along: it is kept whatever the block beside
   * it turns out to be, unless that one is a tag block too. */
  std::vector<uint8_t> sound;
  le32(sound, 4);
  text(sound, "good");
  le32(sound, 1);
  le32(sound, 7);
  text(sound, "KEPT=ok");
  for (const Case& c : cases) {
    std::vector<std::vector<uint8_t>> blocks = {flac_metadata_block(c.type, c.body, false)};
    if (c.type == FLAC_BLOCK_PICTURE) {
      blocks.push_back(flac_metadata_block(FLAC_BLOCK_VORBIS_COMMENT, sound, false));
    }
    const std::vector<uint8_t> stream = stream_with(blocks, pcm);
    for (size_t chunk : {(size_t)1, (size_t)7, (size_t)4096}) {
      const Read r = read_stream(stream, true, 1 << 20, chunk);
      CHECK(r.decoded.errors.size() == 1 && r.decoded.errors[0].code == AAC_ERR_DECODE &&
                r.decoded.errors[0].frames_before == 0 && r.decoded.lost == 1,
            "%s: one decode error, before the first frame (%zu errors)", c.name,
            r.decoded.errors.size());
      CHECK(r.decoded.pcm == pcm, "%s: the audio decodes all the same", c.name);
      CHECK(
          r.tags.pictures.empty() && (c.type == FLAC_BLOCK_PICTURE
                                          ? r.tags.comments == std::vector<std::string>({"KEPT=ok"})
                                          : r.tags.comments.empty()),
          "%s: the block is dropped and nothing else", c.name);
      /* The decoder sized nothing from the lengths inside the block:
       * beyond what flac_decode holds, a megabyte at most. */
      CHECK(r.peak < kHarnessBytes + pcm.size() * sizeof(int32_t) * 3 + (1u << 20),
            "%s: %zu bytes in use at most", c.name, r.peak);
    }
    /* Without the setting the block is not even looked at. */
    const Read quiet = read_stream(stream, false);
    CHECK(quiet.decoded.errors.empty() && quiet.decoded.pcm == pcm,
          "%s: a decoder that keeps no tags skips the block unread", c.name);
  }

  /* Sound blocks at the edges: no fields, an empty vendor, a field
   * without '=' (which a reader takes as it is), an empty picture. */
  std::vector<uint8_t> empty_tags;
  le32(empty_tags, 0);
  le32(empty_tags, 2);
  le32(empty_tags, 0);
  le32(empty_tags, 9);
  text(empty_tags, "no equals");
  std::vector<uint8_t> empty_picture;
  for (int i = 0; i < 8; i++) {
    be32(empty_picture, 0);
  }
  const std::vector<uint8_t> edge =
      stream_with({flac_metadata_block(FLAC_BLOCK_VORBIS_COMMENT, empty_tags, false),
                   flac_metadata_block(FLAC_BLOCK_PICTURE, empty_picture, false),
                   /* a second tag block, which a stream may not have, is passed over */
                   flac_metadata_block(FLAC_BLOCK_VORBIS_COMMENT, sound, false)},
                  pcm);
  const Read r = read_stream(edge, true, 0, 3);
  CHECK(r.decoded.errors.empty() && r.decoded.pcm == pcm, "blocks at the edges decode");
  CHECK(r.tags.vendor.empty() && r.tags.comments == std::vector<std::string>({"", "no equals"}),
        "an empty vendor, an empty field and a field without '=' are read as they are");
  CHECK(r.tags.pictures.size() == 1 && r.tags.pictures[0].mime.empty() &&
            r.tags.pictures[0].data.empty(),
        "an empty picture is read as one");

  /* A block that claims more than the input holds: the stream ends in
   * it, and the bytes that came were all that was ever held. */
  std::vector<uint8_t> claims = {'f', 'L', 'a', 'C'};
  BaacFlacStreamInfo info = flac_test_info(44100, 2, 16, 1024);
  flac_append(claims,
              flac_metadata_block(FLAC_BLOCK_STREAMINFO, flac_streaminfo_bytes(info), false));
  claims.insert(claims.end(), {(uint8_t)(0x80 | FLAC_BLOCK_PICTURE), 0xFF, 0xFF, 0xFF});
  claims.insert(claims.end(), 5000, 0x11);
  const Read cut = read_stream(claims, true, 0, 512);
  CHECK(cut.decoded.finish == AAC_ERR_DECODE && cut.tags.pictures.empty(),
        "a stream that ends inside a picture block ends in an error");
  CHECK(cut.peak < kHarnessBytes + (1u << 20),
        "a block that claims 16 MiB and delivers 5000 bytes takes %zu bytes", cut.peak);
}

/* The caller's limit, and a decoder that was not asked. */
static void test_limit() {
  const std::vector<int32_t> pcm = flac_gen_tones(4096 * 2, 2, 16, 0.5, 8u);
  Tags tags;
  tags.comments = {"TITLE=Large cover"};
  Picture large;
  large.data = bytes_of(5u << 20, 9u);
  Picture small;
  small.type = 4;
  small.data = bytes_of(2000, 10u);
  tags.pictures = {large, small};
  TagOptions to(tags);
  const FlacEncoded e = flac_encode(pcm, 44100, 2, 16, 5, 5000, true, &to.options);
  CHECK(e.error == AAC_OK, "a stream with a 5 MiB picture");
  const size_t samples = pcm.size() * sizeof(int32_t);
  /* flac_decode holds its buffer and the samples it returns. */
  const size_t harness = kHarnessBytes + 3 * samples;

  /* Not asked: nothing held, and the FIFO never has more than its
   * smallest size. */
  const Read quiet = read_stream(e.stream, false, 0, 65536);
  CHECK(quiet.decoded.pcm == pcm && quiet.decoded.verdict == BAAC_FLAC_MD5_MATCH,
        "the audio decodes past the picture");
  CHECK(quiet.decoded.max_pending <= 65536, "the FIFO held %d bytes at most",
        quiet.decoded.max_pending);
  CHECK(quiet.peak < harness + (1u << 20), "a decoder that keeps no tags took %zu bytes",
        quiet.peak);

  /* A limit below the picture: it is skipped and counted, the rest is
   * kept, the audio decodes. */
  const Read limited = read_stream(e.stream, true, 1 << 20, 65536);
  CHECK(limited.skipped == 1 && limited.tags.comments == tags.comments &&
            limited.tags.pictures.size() == 1 && same_picture(limited.tags.pictures[0], small),
        "a picture past the limit is skipped and reported, the others are kept (%d skipped)",
        limited.skipped);
  CHECK(limited.decoded.errors.empty() && limited.decoded.pcm == pcm &&
            limited.decoded.verdict == BAAC_FLAC_MD5_MATCH,
        "the audio decodes all the same");
  CHECK(limited.peak < harness + (2u << 20), "with a limit of 1 MiB the decoder took %zu bytes",
        limited.peak);

  /* A limit a little above the blocks: all kept, in memory of their
   * size and no second copy.  A limit one byte below them: the last
   * one is skipped. */
  size_t blocks = 0;
  for (size_t pos = 4; pos + 4 <= (size_t)e.header_bytes;) {
    const size_t length =
        (size_t)e.stream[pos + 1] << 16 | (size_t)e.stream[pos + 2] << 8 | e.stream[pos + 3];
    const int type = e.stream[pos] & 0x7F;
    blocks += type == FLAC_BLOCK_VORBIS_COMMENT || type == FLAC_BLOCK_PICTURE ? length : 0;
    pos += 4 + length;
  }
  const Read roomy = read_stream(e.stream, true, blocks + 4096, 65536);
  CHECK(roomy.skipped == 0 && same_pictures(roomy.tags.pictures, tags.pictures),
        "a limit a little above the blocks keeps them all");
  CHECK(roomy.peak < harness + blocks + (1u << 20),
        "holding %zu bytes of blocks took %zu bytes: no second copy", blocks, roomy.peak);
  const Read short_by_one = read_stream(e.stream, true, blocks - 1, 65536);
  CHECK(short_by_one.skipped == 1 && short_by_one.tags.pictures.size() == 1,
        "one byte less than the blocks and the last one is skipped");

  /* The bookkeeping counts as well: a tag block of 100000 empty fields
   * is 400 kB of content and four times that to point at, and a limit
   * of 1 MiB does not hold it. */
  std::vector<uint8_t> many;
  le32(many, 0);
  le32(many, 100000);
  many.insert(many.end(), 400000, 0);
  const std::vector<uint8_t> flood =
      stream_with({flac_metadata_block(FLAC_BLOCK_VORBIS_COMMENT, many, false)}, pcm);
  const Read flooded = read_stream(flood, true, 1 << 20, 65536);
  CHECK(flooded.skipped == 1 && flooded.tags.comments.empty() && flooded.decoded.errors.empty() &&
            flooded.decoded.pcm == pcm,
        "a block whose fields would take more than the limit to point at is skipped (%d)",
        flooded.skipped);
  CHECK(flooded.peak < kHarnessBytes + 3 * samples + (1u << 20) + many.size() * 2,
        "and took %zu bytes", flooded.peak);
  const Read held = read_stream(flood, true, 4 << 20, 65536);
  CHECK(held.skipped == 0 && held.tags.comments.size() == 100000,
        "with a limit that covers the fields it is kept");

  /* A block that claims 5 MiB is not given 5 MiB before 64 KiB of it
   * have come. */
  std::vector<uint8_t> begun(e.stream.begin(), e.stream.begin() + 4 + 4 + 34);
  size_t picture_at = begun.size();
  while ((e.stream[picture_at] & 0x7F) != FLAC_BLOCK_PICTURE) {
    picture_at += 4 + ((size_t)e.stream[picture_at + 1] << 16 |
                       (size_t)e.stream[picture_at + 2] << 8 | e.stream[picture_at + 3]);
  }
  begun.insert(begun.end(), e.stream.begin() + (long)picture_at,
               e.stream.begin() + (long)picture_at + 60000);
  const Read partial = read_stream(begun, true, 0, 4096);
  CHECK(partial.decoded.finish == AAC_ERR_DECODE && partial.peak < kHarnessBytes + (1u << 20),
        "60000 bytes of a block that claims 5 MiB took %zu bytes", partial.peak);

  /* The setting belongs before the first byte, and a reset drops what
   * was kept but not the setting. */
  BaacFlacDecoder* dec = baac_flac_decoder_create();
  baac_flac_decoder_set_metadata(dec, 1, 0);
  flac_decode(e.stream, 65536, dec);
  const BaacFlacPicture* pictures = nullptr;
  CHECK(baac_flac_decoder_pictures(dec, &pictures) == 2, "a decoder with the setting keeps both");
  CHECK(baac_flac_decoder_set_metadata(dec, 0, 0) == AAC_ERR_STATE,
        "the setting cannot change once the stream has begun");
  baac_flac_decoder_reset(dec);
  CHECK(baac_flac_decoder_pictures(dec, &pictures) == 0 && pictures == nullptr,
        "a reset drops the pictures");
  flac_decode(e.stream, 65536, dec);
  CHECK(baac_flac_decoder_pictures(dec, &pictures) == 2, "and the next stream is collected again");
  baac_flac_decoder_destroy(dec);
}

/* ── memory that runs out, and input that is dropped ───────────── */

/* Decodes a stream with tags kept, letting the allocation that comes
 * `fail_in` allocations into the decode fail, and the two after it; a
 * call that reports the failure is made again.  Returns false when no
 * allocation was left to fail. */
static bool decode_with_failure(const std::vector<uint8_t>& stream, long fail_in, Tags* tags,
                                std::vector<int32_t>* pcm, int* memory_errors) {
  BaacFlacDecoder* dec = baac_flac_decoder_create();
  baac_flac_decoder_set_metadata(dec, 1, 0);
  std::vector<int32_t> block((size_t)4096 * 2);
  pcm->clear();
  pcm->reserve(1 << 16);
  tags->comments.reserve(64);
  g_fail_in = fail_in;
  g_fail_count = 3;
  g_failed = 0;
  size_t pos = 0;
  int guard = 0;
  while (pos < stream.size() && guard++ < 100000) {
    const int took = baac_flac_decoder_feed(dec, stream.data() + pos,
                                            (int)std::min<size_t>(stream.size() - pos, 97));
    if (took == AAC_ERR_MEMORY) {
      (*memory_errors)++;
      continue;
    }
    pos += (size_t)std::max(took, 0);
    for (;;) {
      const int n = baac_flac_decoder_decode_next(dec, block.data(), (int)block.size());
      if (n == AAC_ERR_MEMORY) {
        (*memory_errors)++;
        continue;
      }
      if (n <= 0) {
        break;
      }
      pcm->insert(pcm->end(), block.begin(), block.begin() + (size_t)n * 2);
    }
  }
  const bool fired = g_failed > 0;
  g_fail_in = -1;
  g_fail_count = 0;
  const BaacFlacComment* comments = nullptr;
  BaacFlacComment vendor = {};
  const int count = baac_flac_decoder_comments(dec, &comments, &vendor);
  tags->comments.clear();
  tags->pictures.clear();
  tags->vendor.assign(vendor.text, vendor.length);
  for (int i = 0; i < count; i++) {
    tags->comments.emplace_back(comments[i].text, comments[i].length);
  }
  const BaacFlacPicture* pictures = nullptr;
  const int picture_count = baac_flac_decoder_pictures(dec, &pictures);
  for (int i = 0; i < picture_count; i++) {
    Picture p;
    p.type = pictures[i].type;
    p.mime = pictures[i].mime_type;
    p.description = pictures[i].description;
    p.width = pictures[i].width;
    p.height = pictures[i].height;
    p.depth = pictures[i].depth;
    p.colors = pictures[i].colors;
    p.data.assign(pictures[i].data, pictures[i].data + pictures[i].data_bytes);
    tags->pictures.push_back(p);
  }
  baac_flac_decoder_destroy(dec);
  return fired;
}

/* An allocation may fail anywhere while tags and pictures are taken in:
 * the call says so, and the same call made again goes on as if nothing
 * had happened, with every tag and picture kept once. */
static void test_memory_failure() {
  const std::vector<int32_t> pcm = flac_gen_tones(4096 + 300, 2, 16, 0.5, 21u);
  Tags tags = sample_tags();
  tags.pictures[0].data = bytes_of(3000, 5u);
  tags.pictures.push_back(tags.pictures[1]);
  tags.pictures.back().type = 5;
  TagOptions to(tags);
  const FlacEncoded e = flac_encode(pcm, 44100, 2, 16, 5, 5000, true, &to.options);
  int failures_seen = 0;
  int wrong = 0;
  long tried = 0;
  for (long fail_in = 0; fail_in < 2000; fail_in++) {
    Tags got;
    std::vector<int32_t> decoded;
    int memory_errors = 0;
    const bool fired = decode_with_failure(e.stream, fail_in, &got, &decoded, &memory_errors);
    if (!fired) {
      break;
    }
    tried++;
    failures_seen += memory_errors;
    if (got.comments != tags.comments || !same_pictures(got.pictures, tags.pictures) ||
        decoded != pcm) {
      wrong++;
      if (wrong == 1) {
        printf("allocation %ld failing: %zu tags of %zu, %zu pictures of %zu, %zu samples of %zu\n",
               fail_in, got.comments.size(), tags.comments.size(), got.pictures.size(),
               tags.pictures.size(), decoded.size(), pcm.size());
      }
    }
  }
  CHECK(tried >= 5 && failures_seen >= 5,
        "allocations fail during the decode (%ld places tried, %d calls reported "
        "AAC_ERR_MEMORY)",
        tried, failures_seen);
  CHECK(wrong == 0,
        "after a failed allocation the call made again keeps every tag and picture "
        "once (%d of %ld runs differ)",
        wrong, tried);
}

/* A seek or a reset in the middle of a picture block gives back what
 * had been taken in of it. */
static void test_dropped_input() {
  const std::vector<int32_t> pcm = flac_gen_tones(4096, 2, 16, 0.5, 22u);
  Tags tags;
  Picture large;
  large.data = bytes_of(4u << 20, 6u);
  tags.pictures = {large};
  TagOptions to(tags);
  const FlacEncoded e = flac_encode(pcm, 44100, 2, 16, 5, 5000, true, &to.options);
  for (int reset = 0; reset < 2; reset++) {
    BaacFlacDecoder* dec = baac_flac_decoder_create();
    baac_flac_decoder_set_metadata(dec, 1, 0);
    const size_t before = g_live;
    size_t pos = 0;
    while (pos < (3u << 20)) {
      pos += (size_t)baac_flac_decoder_feed(dec, e.stream.data() + pos, 65536);
    }
    const size_t held = g_live - before;
    CHECK(held > (2u << 20), "three megabytes into the picture the decoder holds %zu bytes", held);
    if (reset) {
      baac_flac_decoder_reset(dec);
    } else {
      baac_flac_decoder_seek_reset(dec);
    }
    CHECK(g_live - before < (1u << 20), "after a %s it holds %zu bytes", reset ? "reset" : "seek",
          g_live - before);
    baac_flac_decoder_destroy(dec);
  }
}

/* A count of fields no caller could mean: the check stops as soon as
 * the tags are past the block limit, and reads no field beyond. */
static void test_field_count() {
  const std::string field = "LYRICS=" + std::string(6u << 20, 'x');
  /* Three fields pass the limit.  The fourth points nowhere: reading it
   * would end the test. */
  std::vector<BaacFlacComment> fields(3, BaacFlacComment{field.data(), (uint32_t)field.size()});
  fields.push_back(BaacFlacComment{(const char*)(uintptr_t)8, 16});
  BaacFlacEncoder* enc = baac_flac_encoder_create(44100, 2, 16, 5, nullptr);
  BaacFlacEncoderOptions options;
  baac_flac_encoder_options_init(&options);
  options.comments = fields.data();
  options.comment_count = INT32_MAX; /* far more than are there */
  CHECK(baac_flac_encoder_set_options(enc, &options) == AAC_ERR_INVALID_ARG,
        "tags past the block limit are refused at the field that passes it");
  baac_flac_encoder_destroy(enc);

  /* The counters of the decoder stop at their largest value. */
  int counter = INT32_MAX - 1;
  flac_count(&counter);
  flac_count(&counter);
  flac_count(&counter);
  CHECK(counter == INT32_MAX, "a counter at its limit stays there (%d)", counter);
}

/* ── ID3 tags around a stream ──────────────────────────────────── */

static std::vector<uint8_t> id3v2(size_t body, bool footer) {
  std::vector<uint8_t> tag = {'I',
                              'D',
                              '3',
                              4,
                              0,
                              (uint8_t)(footer ? 0x10 : 0),
                              (uint8_t)(body >> 21 & 0x7F),
                              (uint8_t)(body >> 14 & 0x7F),
                              (uint8_t)(body >> 7 & 0x7F),
                              (uint8_t)(body & 0x7F)};
  /* The body holds what looks like a marker and like frames: none of it
   * is looked at. */
  for (size_t i = 0; i < body; i++) {
    tag.push_back((uint8_t)("fLaC\xFF\xF8"[i % 6]));
  }
  if (footer) {
    tag.insert(tag.end(), {'3', 'D', 'I', 4, 0, 0x10, 0, 0, 0, 0});
  }
  return tag;
}

struct Run {
  int status = -1;
  std::string out;
  std::string err;
};

static Run run(const std::string& cmd) {
  Run r;
  const std::string out_path = g_tmp + "/stdout";
  const std::string err_path = g_tmp + "/stderr";
  r.status = system((cmd + " > " + shell_quote(out_path) + " 2> " + shell_quote(err_path)).c_str());
  const std::vector<uint8_t> out = read_file(out_path);
  const std::vector<uint8_t> err = read_file(err_path);
  r.out.assign(out.begin(), out.end());
  r.err.assign(err.begin(), err.end());
  return r;
}

static void test_id3() {
  const std::vector<int32_t> pcm = flac_gen_tones(4096 * 2 + 55, 2, 16, 0.5, 12u);
  Tags tags;
  tags.comments = {"TITLE=Behind an ID3 tag"};
  TagOptions to(tags);
  const FlacEncoded e = flac_encode(pcm, 44100, 2, 16, 5, 5000, true, &to.options);

  /* In front: passed over, and the stream is read as if it began at
   * its marker. */
  for (int variant = 0; variant < 3; variant++) {
    std::vector<uint8_t> front = id3v2(variant == 2 ? 0 : 3000, variant == 1);
    if (variant == 2) {
      flac_append(front, id3v2(70000, false)); /* two tags, the first one empty */
    }
    std::vector<uint8_t> stream = front;
    flac_append(stream, e.stream);
    for (size_t chunk : {(size_t)1, (size_t)4096}) {
      const Read r = read_stream(stream, true, 0, chunk);
      CHECK(r.decoded.errors.empty() && r.decoded.pcm == pcm &&
                r.decoded.verdict == BAAC_FLAC_MD5_MATCH &&
                r.decoded.info.total_samples == pcm.size() / 2,
            "ID3v2 variant %d: the stream behind the tag decodes with its total and MD5", variant);
      CHECK(r.tags.comments == tags.comments, "ID3v2 variant %d: its tags are read", variant);
      CHECK(r.first_frame == (int64_t)(front.size() + (size_t)e.header_bytes),
            "ID3v2 variant %d: the first frame is at byte %lld", variant, (long long)r.first_frame);
    }
  }
  /* A stream that ends inside the tag is cut short. */
  std::vector<uint8_t> inside = id3v2(3000, false);
  inside.resize(1500);
  CHECK(read_stream(inside, false).decoded.finish == AAC_ERR_DECODE,
        "a stream that ends inside an ID3v2 tag ends in an error");
  /* "ID3" that is no tag header is no reason to skip anything. */
  std::vector<uint8_t> fake = {'I', 'D', '3', 0xFF, 0xFF, 0, 0xFF, 0xFF, 0xFF, 0xFF};
  flac_append(fake, std::vector<uint8_t>(e.stream.begin() + e.header_bytes, e.stream.end()));
  const Read f = read_stream(fake, false);
  CHECK(f.decoded.pcm == pcm, "bytes that only begin like an ID3v2 tag do not hide the frames");

  /* Behind the last frame: an ID3v1 tag is 128 bytes that begin with
   * "TAG" and end the input.  It is passed over; anything else there
   * is damage. */
  std::vector<uint8_t> v1(128, 0);
  memcpy(v1.data(), "TAGTitle", 8);
  std::vector<uint8_t> tail = e.stream;
  flac_append(tail, v1);
  for (size_t chunk : {(size_t)1, (size_t)100, (size_t)1 << 20}) {
    const Read r = read_stream(tail, false, 0, chunk);
    CHECK(r.decoded.errors.empty() && r.decoded.finish == AAC_OK && r.decoded.lost == 0 &&
              r.decoded.pcm == pcm && r.decoded.verdict == BAAC_FLAC_MD5_MATCH,
          "an ID3v1 tag behind the last frame is passed over (chunks of %zu)", chunk);
  }
  /* Without the word that the input ended, the finish finds it. */
  BaacFlacDecoder* dec = baac_flac_decoder_create();
  std::vector<int32_t> buf(4096 * 2);
  size_t decoded = 0;
  for (size_t pos = 0; pos < tail.size();) {
    pos += (size_t)baac_flac_decoder_feed(dec, tail.data() + pos, (int)(tail.size() - pos));
    int n;
    while ((n = baac_flac_decoder_decode_next(dec, buf.data(), (int)buf.size())) > 0) {
      decoded += (size_t)n;
    }
    CHECK(n == 0, "nothing is reported while the tag may still be one (%d)", n);
  }
  CHECK(baac_flac_decoder_finish(dec, nullptr) == AAC_OK && decoded == pcm.size() / 2 &&
            baac_flac_decoder_lost_frames(dec) == 0,
        "a finish without end_input passes the ID3v1 tag over too");
  baac_flac_decoder_destroy(dec);

  std::vector<uint8_t> longer = tail;
  longer.push_back(0);
  Read r = read_stream(longer, false);
  CHECK(r.decoded.lost == 1 && r.decoded.pcm == pcm, "129 bytes behind the last frame are damage");
  std::vector<uint8_t> shorter = tail;
  shorter.pop_back();
  r = read_stream(shorter, false);
  CHECK(r.decoded.lost == 1 && r.decoded.pcm == pcm, "127 bytes behind the last frame are damage");
  std::vector<uint8_t> other = e.stream;
  other.insert(other.end(), 128, 0x55);
  r = read_stream(other, false);
  CHECK(r.decoded.lost == 1 && r.decoded.pcm == pcm, "128 bytes that are no ID3v1 tag are damage");
  /* "TAG" in the middle of a stream is damage like anything else. */
  std::vector<uint8_t> middle(e.stream.begin(), e.stream.begin() + e.header_bytes);
  flac_append(middle, v1);
  middle.insert(middle.end(), e.stream.begin() + e.header_bytes, e.stream.end());
  r = read_stream(middle, false);
  CHECK(r.decoded.lost == 1 && r.decoded.pcm == pcm,
        "an ID3v1 tag in front of the frames is damage, and the frames are found behind it");

  /* What the reference decoder does with the same files, for the
   * record: it takes the ID3v2 tag and calls the ID3v1 tag lost sync. */
  if (g_flac.empty()) {
    return;
  }
  std::vector<uint8_t> front = id3v2(3000, false);
  flac_append(front, e.stream);
  write_file(g_tmp + "/id3v2.flac", front.data(), front.size());
  write_file(g_tmp + "/id3v1.flac", tail.data(), tail.size());
  const Run v2 = run(shell_quote(g_flac) + " -t -s " + shell_quote(g_tmp + "/id3v2.flac"));
  CHECK(v2.status == 0, "flac -t accepts the stream behind an ID3v2 tag: %s", v2.err.c_str());
  const Run trailer = run(shell_quote(g_flac) + " -t -s " + shell_quote(g_tmp + "/id3v1.flac"));
  printf("flac -t on a stream with an ID3v1 tag behind it: exit %d, %s\n",
         WEXITSTATUS(trailer.status),
         trailer.err.find("LOST_SYNC") != std::string::npos ? "reports lost sync" : "no complaint");
}

/* ── the oracles ───────────────────────────────────────────────── */

static std::vector<std::string> lines_of(const std::string& text) {
  std::vector<std::string> lines;
  size_t pos = 0;
  while (pos < text.size()) {
    size_t end = text.find('\n', pos);
    if (end == std::string::npos) {
      end = text.size();
    }
    lines.push_back(text.substr(pos, end - pos));
    pos = end + 1;
  }
  return lines;
}

static bool contains(const std::string& text, const std::string& what) {
  return text.find(what) != std::string::npos;
}

static std::vector<uint8_t> raw16(const std::vector<int32_t>& pcm) {
  std::vector<uint8_t> out;
  for (int32_t v : pcm) {
    out.push_back((uint8_t)v);
    out.push_back((uint8_t)(v >> 8));
  }
  return out;
}

static void test_oracles() {
  const bool have_reference =
      oracle_present(!g_flac.empty() && !g_metaflac.empty(),
                     "the reference flac and metaflac (BAAC_FLAC_REFERENCE)");
  const bool ffmpeg_tools = oracle_present(
      have_ffmpeg() && system("ffprobe -version > /dev/null 2>&1") == 0, "ffmpeg and ffprobe");
  if (!have_reference || !ffmpeg_tools) {
    return;
  }
  /* A real JPEG file for a cover, 64 by 48 pixels. */
  const std::string cover_path = g_tmp + "/cover.jpg";
  Run r = run("ffmpeg -v error -nostdin -f lavfi -i color=c=red:s=64x48 -frames:v 1 -y " +
              shell_quote(cover_path));
  const std::vector<uint8_t> cover = read_file(cover_path);
  CHECK(r.status == 0 && cover.size() > 100, "ffmpeg makes a JPEG cover");

  const std::vector<int32_t> pcm = flac_gen_tones(44100, 2, 16, 0.5, 14u);
  Tags tags;
  tags.comments = {"ARTIST=Ravel", "GENRE=Classical", "GENRE=Orchestral", "TITLE=Boléro",
                   "COMMENT="};
  Picture front;
  front.type = 3;
  front.mime = "image/jpeg";
  front.description = "Front";
  front.width = 64;
  front.height = 48;
  front.depth = 24;
  front.data = cover;
  tags.pictures = {front};
  TagOptions to(tags);
  to.options.seek_table = 1;
  to.options.total_samples_hint = pcm.size() / 2;
  const FlacEncoded e = flac_encode(pcm, 44100, 2, 16, 5, 5000, true, &to.options);
  const std::string ours = g_tmp + "/ours.flac";
  write_file(ours, e.stream.data(), e.stream.size());
  const std::string flac = shell_quote(g_flac);
  const std::string metaflac = shell_quote(g_metaflac);
  const std::string file = shell_quote(ours);

  /* metaflac: the tags in order, the picture's figures and its bytes. */
  r = run(metaflac + " --export-tags-to=- " + file);
  CHECK(r.status == 0 && lines_of(r.out) == tags.comments,
        "metaflac lists the tags bAAC wrote, in order:\n%s", r.out.c_str());
  r = run(metaflac + " --list --block-type=VORBIS_COMMENT " + file);
  CHECK(contains(r.out, "vendor string: bAAC") && contains(r.out, "comments: 5") &&
            contains(r.out, "comment[2]: GENRE=Orchestral"),
        "metaflac --list shows the vendor and the fields:\n%s", r.out.c_str());
  r = run(metaflac + " --list --block-type=PICTURE " + file);
  CHECK(contains(r.out, "type: 3 (Cover (front))") && contains(r.out, "MIME type: image/jpeg") &&
            contains(r.out, "description: Front") && contains(r.out, "width: 64") &&
            contains(r.out, "height: 48") && contains(r.out, "depth: 24") &&
            contains(r.out, "data length: " + std::to_string(cover.size())),
        "metaflac --list shows the picture as written:\n%s", r.out.c_str());
  const std::string exported = g_tmp + "/exported.jpg";
  r = run(metaflac + " --export-picture-to=" + shell_quote(exported) + " " + file);
  CHECK(r.status == 0 && read_file(exported) == cover, "metaflac exports the picture's bytes");

  /* flac: the stream tests clean and decodes to the input. */
  r = run(flac + " -t -s " + file);
  CHECK(r.status == 0 && r.err.empty(), "flac -t accepts the tagged stream: %s", r.err.c_str());
  write_file(g_tmp + "/in.raw", raw16(pcm).data(), pcm.size() * 2);
  r = run(flac + " -d -s -c --force-raw-format --endian=little --sign=signed " + file +
          " | cmp - " + shell_quote(g_tmp + "/in.raw"));
  CHECK(r.status == 0, "flac -d decodes the tagged stream to the input");

  /* FFmpeg: the tags (it joins the values of a repeated name), the
   * cover as an attached picture, and the audio. */
  r = run("ffprobe -v error -show_entries format_tags -of default=nw=1 " + file);
  CHECK(contains(r.out, "TAG:ARTIST=Ravel") && contains(r.out, "TAG:TITLE=Boléro") &&
            contains(r.out, "Classical") && contains(r.out, "Orchestral"),
        "ffprobe lists the tags bAAC wrote:\n%s%s", r.out.c_str(), r.err.c_str());
  r =
      run("ffprobe -v error -select_streams v -show_entries "
          "stream=codec_name,width,height:stream_disposition=attached_pic -of default=nw=1 " +
          file);
  CHECK(contains(r.out, "codec_name=mjpeg") && contains(r.out, "width=64") &&
            contains(r.out, "height=48") && contains(r.out, "attached_pic=1"),
        "ffprobe finds the cover as an attached picture:\n%s%s", r.out.c_str(), r.err.c_str());
  r = run("ffmpeg -v error -nostdin -i " + file + " -map 0:a -f s16le -c:a pcm_s16le - | cmp - " +
          shell_quote(g_tmp + "/in.raw"));
  CHECK(r.status == 0 && r.err.empty(), "FFmpeg decodes the tagged stream to the input: %s",
        r.err.c_str());
  r = run("ffmpeg -v error -nostdin -i " + file + " -map 0:v -c copy -f image2pipe - | cmp - " +
          shell_quote(cover_path));
  CHECK(r.status == 0, "FFmpeg hands the cover's bytes back");

  /* What flac writes: tags by -T, in order, and a picture. */
  const std::string wav_raw = g_tmp + "/in.raw";
  const std::string theirs = g_tmp + "/theirs.flac";
  r = run(flac +
          " -s -f --force-raw-format --endian=little --sign=signed --channels=2 --bps=16 "
          "--sample-rate=44100 -T ARTIST=Ravel -T GENRE=Classical -T GENRE=Orchestral "
          "-T 'TITLE=Boléro' -T COMMENT= --picture=" +
          shell_quote(cover_path) + " -o " + shell_quote(theirs) + " " + shell_quote(wav_raw));
  CHECK(r.status == 0, "flac writes a tagged file: %s", r.err.c_str());
  Read read = read_stream(read_file(theirs), true);
  CHECK(read.decoded.pcm == pcm && read.decoded.verdict == BAAC_FLAC_MD5_MATCH,
        "bAAC decodes flac's tagged file");
  /* metaflac says what flac wrote (it leaves out the tag without a
   * value), and bAAC has to read the same. */
  r = run(metaflac + " --export-tags-to=- " + shell_quote(theirs));
  const std::vector<std::string> written = lines_of(r.out);
  CHECK(written.size() >= 4 && written[0] == "ARTIST=Ravel" && written[2] == "GENRE=Orchestral" &&
            written[3] == "TITLE=Boléro",
        "flac wrote the tags it was given:\n%s", r.out.c_str());
  CHECK(read.tags.comments == written, "bAAC reads flac's tags in order (%zu of %zu)",
        read.tags.comments.size(), written.size());
  CHECK(contains(read.tags.vendor, "libFLAC 1.4.3"), "bAAC reads flac's vendor: %s",
        read.tags.vendor.c_str());
  CHECK(read.tags.pictures.size() == 1 && read.tags.pictures[0].type == 3 &&
            read.tags.pictures[0].mime == "image/jpeg" && read.tags.pictures[0].width == 64 &&
            read.tags.pictures[0].height == 48 && read.tags.pictures[0].data == cover,
        "bAAC reads flac's picture: its type, media type, size and bytes");

  /* What FFmpeg writes. */
  const std::string lavf = g_tmp + "/lavf.flac";
  r = run("ffmpeg -v error -nostdin -f s16le -ar 44100 -ac 2 -i " + shell_quote(wav_raw) + " -i " +
          shell_quote(cover_path) +
          " -map 0:a -map 1:v -c:a flac -c:v copy -disposition:v attached_pic "
          "-metadata ARTIST=Ravel -metadata 'TITLE=Boléro' -y " +
          shell_quote(lavf));
  CHECK(r.status == 0, "FFmpeg writes a tagged file: %s", r.err.c_str());
  read = read_stream(read_file(lavf), true);
  bool artist = false;
  bool title = false;
  for (const std::string& c : read.tags.comments) {
    artist = artist || c == "ARTIST=Ravel" || c == "artist=Ravel";
    title = title || c == "TITLE=Boléro" || c == "title=Boléro";
  }
  CHECK(read.decoded.pcm == pcm && artist && title, "bAAC reads FFmpeg's tags (%zu fields)",
        read.tags.comments.size());
  CHECK(read.tags.pictures.size() == 1 && read.tags.pictures[0].data == cover &&
            read.tags.pictures[0].mime == "image/jpeg",
        "bAAC reads FFmpeg's picture");
}

int main() {
  g_tmp = make_temp_dir();
  const char* reference = getenv("BAAC_FLAC_REFERENCE");
  if (reference && *reference) {
    g_flac = reference;
    const std::string dir = g_flac.substr(0, g_flac.find_last_of('/'));
    g_metaflac = dir + "/../metaflac/metaflac";
    if (run(shell_quote(g_metaflac) + " --version").status != 0) {
      g_metaflac.clear();
    }
  }
  test_roundtrip();
  test_refusals();
  test_hostile_blocks();
  test_limit();
  test_memory_failure();
  test_dropped_input();
  test_field_count();
  test_id3();
  test_oracles();
  remove_temp_dir(g_tmp);
  return test_summary("test_flac_meta");
}
