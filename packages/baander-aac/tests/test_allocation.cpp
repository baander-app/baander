/* No heap use on the encode and decode paths once a codec is running:
 * the global allocation operators are replaced by counting versions, an
 * encoder and a decoder of every profile run a few frames to settle (the
 * decoder's SBR state and the transport's buffers are created on the
 * first frames), and from then on every encoded and decoded frame must
 * allocate nothing.  The decoder's input FIFO is filled before the
 * steady-state phase so that its growth, which is allowed, does not
 * count.  The FLAC decoder of the lossless mode is held to the same
 * rule, with its input fed in pieces throughout: its FIFO is sized once
 * from the stream's STREAMINFO, so feeding may not allocate either.  So
 * is the FLAC encoder at its highest level, where every search runs: its
 * rows, its analysis windows and its seek points exist before the first
 * block. */
#include <cstdlib>
#include <new>

#include "test_flac_util.h"
#include "test_util.h"

static long g_allocations = 0;
static long g_frees = 0;

void* operator new(size_t size) {
  g_allocations++;
  void* p = malloc(size ? size : 1);
  if (!p) {
    throw std::bad_alloc();
  }
  return p;
}
void* operator new[](size_t size) { return operator new(size); }
void* operator new(size_t size, const std::nothrow_t&) noexcept {
  g_allocations++;
  return malloc(size ? size : 1);
}
void* operator new[](size_t size, const std::nothrow_t& tag) noexcept {
  return operator new(size, tag);
}
void operator delete(void* p) noexcept {
  if (p) {
    g_frees++;
    free(p);
  }
}
void operator delete[](void* p) noexcept { operator delete(p); }
void operator delete(void* p, size_t) noexcept { operator delete(p); }
void operator delete[](void* p, size_t) noexcept { operator delete(p); }

struct Profile {
  const char* name;
  AacObjectType aot;
  int bitrate;
  int frame_length; /* 0 keeps the default */
  int afterburner;
  int channels;
  int drc;    /* the encoder writes DRC metadata and the decoder applies it */
  int mps212; /* xHE-AAC: the stereo pair through MPEG Surround 2-1-2 */
};

static const Profile kProfiles[] = {
    {"LC 1024 stereo", AAC_AOT_LC, 128000, 0, 0, 2, 0},
    {"LC 960 stereo", AAC_AOT_LC, 128000, 960, 0, 2, 0},
    {"LC 5.1", AAC_AOT_LC, 320000, 0, 0, 6, 0},
    {"LC afterburner", AAC_AOT_LC, 48000, 0, 1, 2, 0},
    {"HE-AAC v1", AAC_AOT_SBR, 64000, 0, 0, 2, 0},
    {"HE-AAC v2", AAC_AOT_PS, 32000, 0, 0, 2, 0},
    /* The LFE carries no SBR data; with DRC on, its gains must not make
     * the decoder build an SBR state for it after the first frames. */
    {"HE-AAC 5.1 DRC", AAC_AOT_SBR, 192000, 0, 0, 6, 1},
    {"LD 512", AAC_AOT_ER_LD, 64000, 512, 0, 2, 0},
    {"LD 480", AAC_AOT_ER_LD, 64000, 480, 0, 2, 0},
    {"ELD 512", AAC_AOT_ER_ELD, 64000, 512, 0, 2, 0},
    /* xHE-AAC in LOAS: the plain core, enhanced SBR by the bitrate rule,
     * and the pair coded as its downmix with the 2-1-2 parameters. */
    {"xHE-AAC core", AAC_AOT_USAC, 128000, 0, 0, 2, 0},
    {"xHE-AAC SBR", AAC_AOT_USAC, 48000, 0, 0, 2, 0},
    {"xHE-AAC 2-1-2", AAC_AOT_USAC, 32000, 0, 0, 2, 0, 1},
};

static const int kSettleFrames = 8;
static const int kSteadyFrames = 40;

static void test_profile(const Profile& p) {
  const int sr = 48000;
  AacEncoderHandle enc = aac_encoder_create(sr, p.channels, p.bitrate, p.aot, AAC_RC_CBR);
  CHECK(enc != nullptr, "%s: encoder", p.name);
  if (!enc) {
    return;
  }
  if (p.frame_length) {
    CHECK(aac_encoder_set_frame_length(enc, p.frame_length) == AAC_OK, "%s: frame length", p.name);
  }
  if (p.afterburner) {
    aac_encoder_set_afterburner(enc, 1);
  }
  if (p.drc) {
    CHECK(aac_encoder_set_metadata(enc, AAC_DRC_FILM_STANDARD, 124) == AAC_OK, "%s: metadata",
          p.name);
  }
  if (p.mps212) {
    AacUsacEncoderOptions o;
    aac_usac_encoder_options_init(&o);
    memset(&o, 0, sizeof(o));
    o.noise_filling = -1;
    o.preroll = -1;
    o.stream_id = -1;
    o.sbr = -1;
    o.mps212 = 1;
    CHECK(aac_encoder_set_usac(enc, &o) == AAC_OK, "%s: the 2-1-2", p.name);
  }
  const int frame = aac_encoder_frame_size(enc);
  const int total_frames = kSettleFrames + kSteadyFrames;
  const std::vector<float> pcm = gen_wideband(frame * total_frames, sr, p.channels);
  std::vector<uint8_t> stream;
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) + 64);
  std::vector<size_t> frame_offsets;
  long steady_allocations = 0;
  for (int f = 0; f < total_frames; f++) {
    g_allocations = 0;
    const int len = aac_encoder_encode(enc, pcm.data() + (size_t)f * frame * p.channels, frame,
                                       buf.data(), (int)buf.size());
    if (f >= kSettleFrames) {
      steady_allocations += g_allocations;
    }
    CHECK(len >= 0, "%s: encode frame %d", p.name, f);
    frame_offsets.push_back(stream.size());
    stream.insert(stream.end(), buf.begin(), buf.begin() + std::max(len, 0));
  }
  printf("%-16s encode: %ld allocations over %d steady frames\n", p.name, steady_allocations,
         kSteadyFrames);
  CHECK(steady_allocations == 0, "%s: the encoder allocates in steady state (%ld)", p.name,
        steady_allocations);
  aac_encoder_destroy(enc);

  /* Streaming decode: everything is fed first (the stream fits the
   * FIFO), the first frames settle the decoder, the rest must not
   * allocate. */
  AacDecoderHandle dec = aac_decoder_create(sr, 0);
  CHECK(dec != nullptr, "%s: decoder", p.name);
  if (!dec) {
    return;
  }
  if (p.frame_length == 960) {
    aac_decoder_set_frame_length(dec, 960);
  }
  if (p.drc) {
    aac_decoder_set_drc(dec, 1, 100, 100, 100, 0);
  }
  CHECK(aac_decoder_feed(dec, stream.data(), (int)stream.size()) == (int)stream.size(),
        "%s: the whole stream fits the FIFO (%zu bytes)", p.name, stream.size());
  std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  int decoded = 0;
  steady_allocations = 0;
  for (;;) {
    g_allocations = 0;
    const int got = aac_decoder_decode_next(dec, out.data(), (int)out.size());
    if (got == 0) {
      break;
    }
    CHECK(got > 0, "%s: decode frame %d: %d", p.name, decoded, got);
    if (decoded >= kSettleFrames) {
      steady_allocations += g_allocations;
    }
    decoded++;
  }
  printf("%-16s decode: %ld allocations over %d steady frames (%d decoded)\n", p.name,
         steady_allocations, decoded - kSettleFrames, decoded);
  CHECK(decoded >= total_frames, "%s: %d frames decoded of %d", p.name, decoded, total_frames);
  CHECK(steady_allocations == 0, "%s: the decoder allocates in steady state (%ld)", p.name,
        steady_allocations);
  aac_decoder_destroy(dec);

  /* The one-shot decode of single ADTS frames takes the same path
   * without the FIFO. */
  if (p.aot != AAC_AOT_ER_LD && p.aot != AAC_AOT_ER_ELD && p.aot != AAC_AOT_USAC) {
    dec = aac_decoder_create(sr, 2);
    if (p.frame_length == 960) {
      aac_decoder_set_frame_length(dec, 960);
    }
    if (p.drc) {
      aac_decoder_set_drc(dec, 1, 100, 100, 100, 0);
    }
    steady_allocations = 0;
    for (int f = 0; f < total_frames; f++) {
      const size_t end = f + 1 < total_frames ? frame_offsets[f + 1] : stream.size();
      g_allocations = 0;
      const int got =
          aac_decoder_decode(dec, stream.data() + frame_offsets[f], (int)(end - frame_offsets[f]),
                             out.data(), (int)out.size());
      CHECK(got > 0, "%s: one-shot decode frame %d: %d", p.name, f, got);
      if (f >= kSettleFrames) {
        steady_allocations += g_allocations;
      }
    }
    printf("%-16s one-shot decode: %ld allocations over %d steady frames\n", p.name,
           steady_allocations, kSteadyFrames);
    CHECK(steady_allocations == 0, "%s: the one-shot decoder allocates in steady state (%ld)",
          p.name, steady_allocations);
    aac_decoder_destroy(dec);
  }
}

/* The FLAC decoder on a stream written by the test writer: stereo
 * assignments and both predictor kinds in every frame.  At 32 bits a
 * side channel takes the decoder's 64-bit row, which must exist before
 * the steady state like everything else. */
static void test_flac_decoder(const char* name, int channels, int bits) {
  const int block = 4096;
  const int total_frames = kSettleFrames + kSteadyFrames;
  std::vector<uint8_t> frames;
  std::vector<int32_t> source;
  for (int f = 0; f < total_frames; f++) {
    const std::vector<int32_t> pcm =
        flac_gen_tones(block, channels, bits, 0.001, 10u + (unsigned)f);
    TestFrame frame;
    frame.block_size = block;
    frame.bits = bits;
    frame.sample_rate = 48000;
    frame.number = (uint64_t)f;
    frame.assignment = channels == 2 ? f % 4 : FLAC_ASSIGN_INDEPENDENT;
    std::vector<std::vector<int64_t>> rows;
    for (int c = 0; c < channels; c++) {
      TestSubframe sf = c % 2 ? TestSubframe::lpc({3, -3, 1}, 4, 0, 4) : TestSubframe::fixed(2, 3);
      sf.parameter_bits = 5;
      frame.subframes.push_back(sf);
      rows.push_back(flac_channel(pcm, channels, c));
    }
    flac_append(frames, flac_build_frame(frame, rows));
    source.insert(source.end(), pcm.begin(), pcm.end());
  }
  BaacFlacStreamInfo info =
      flac_test_info(48000, channels, bits, block, (uint64_t)block * (uint64_t)total_frames);
  flac_pcm_md5(source, bits, info.md5);
  std::vector<uint8_t> stream = flac_stream_start(info);
  flac_append(stream, frames);

  BaacFlacDecoder* dec = baac_flac_decoder_create();
  CHECK(dec != nullptr, "%s: decoder", name);
  if (!dec) {
    return;
  }
  baac_flac_decoder_set_md5_check(dec, 1);
  std::vector<int32_t> out((size_t)block * (size_t)channels);
  std::vector<int32_t> decoded;
  decoded.reserve(source.size());
  long steady_allocations = 0;
  int frames_decoded = 0;
  for (size_t pos = 0; pos < stream.size();) {
    g_allocations = 0;
    const int took = baac_flac_decoder_feed(dec, stream.data() + pos,
                                            (int)std::min<size_t>(8192, stream.size() - pos));
    if (frames_decoded >= kSettleFrames) {
      steady_allocations += g_allocations;
    }
    CHECK(took >= 0, "%s: feed: %d", name, took);
    pos += (size_t)std::max(took, 0);
    for (;;) {
      g_allocations = 0;
      const int got = baac_flac_decoder_decode_next(dec, out.data(), (int)out.size());
      if (frames_decoded >= kSettleFrames) {
        steady_allocations += g_allocations;
      }
      if (got == 0) {
        break;
      }
      CHECK(got == block, "%s: decode frame %d: %d", name, frames_decoded, got);
      if (got < 0) {
        pos = stream.size();
        break;
      }
      decoded.insert(decoded.end(), out.begin(), out.begin() + (long)got * channels);
      frames_decoded++;
    }
  }
  g_allocations = 0;
  BaacFlacMd5Verdict verdict = BAAC_FLAC_MD5_UNKNOWN;
  CHECK(baac_flac_decoder_finish(dec, &verdict) == AAC_OK && verdict == BAAC_FLAC_MD5_MATCH,
        "%s: the stream ends with its MD5 verified", name);
  steady_allocations += g_allocations;
  printf("%-16s decode: %ld allocations over %d steady frames (%d decoded)\n", name,
         steady_allocations, frames_decoded - kSettleFrames, frames_decoded);
  CHECK(frames_decoded == total_frames && decoded == source, "%s: %d frames decoded of %d", name,
        frames_decoded, total_frames);
  CHECK(steady_allocations == 0, "%s: the decoder allocates in steady state (%ld)", name,
        steady_allocations);
  baac_flac_decoder_destroy(dec);
}

/* The FLAC encoder at level 8 with a seek table, its input given in
 * pieces that do not line up with its blocks.  At 32 bits a side channel
 * takes the encoder's 64-bit rows. */
static void test_flac_encoder(const char* name, int channels, int bits) {
  const int rate = 48000;
  const int block = 4096;
  const int piece = 1000;
  const int total_frames = kSettleFrames + kSteadyFrames;
  const int total = block * total_frames + 123;
  const std::vector<int32_t> pcm = flac_gen_tones(total, channels, bits, 0.4, 5u);

  BaacFlacEncoder* enc = baac_flac_encoder_create(rate, channels, bits, 8, nullptr);
  CHECK(enc != nullptr, "%s: encoder", name);
  if (!enc) {
    return;
  }
  BaacFlacEncoderOptions options;
  baac_flac_encoder_options_init(&options);
  options.seek_table = 1;
  options.total_samples_hint = (uint64_t)total;
  CHECK(baac_flac_encoder_set_options(enc, &options) == AAC_OK, "%s: options", name);
  std::vector<uint8_t> header((size_t)baac_flac_encoder_header_bytes(enc));
  /* The bound counts what the encoder holds already, which is less than
   * a block. */
  std::vector<uint8_t> out((size_t)baac_flac_encoder_max_output_bytes(enc, piece + block));
  std::vector<uint8_t> stream;
  stream.reserve(header.size() + (size_t)total * (size_t)channels * 4 + 65536);
  CHECK(
      baac_flac_encoder_write_header(enc, header.data(), (int)header.size()) == (int)header.size(),
      "%s: header", name);
  stream.insert(stream.end(), header.begin(), header.end());

  long steady_allocations = 0;
  int steady_calls = 0;
  for (int pos = 0; pos < total; pos += piece) {
    const int count = std::min(piece, total - pos);
    g_allocations = 0;
    const int got = baac_flac_encoder_encode(enc, pcm.data() + (size_t)pos * (size_t)channels,
                                             count, out.data(), (int)out.size());
    if (pos >= kSettleFrames * block) {
      steady_allocations += g_allocations;
      steady_calls++;
    }
    CHECK(got >= 0, "%s: encode at %d: %d", name, pos, got);
    if (got < 0) {
      break;
    }
    stream.insert(stream.end(), out.begin(), out.begin() + got);
  }
  g_allocations = 0;
  const int last = baac_flac_encoder_finish(enc, out.data(), (int)out.size());
  const int final_header = baac_flac_encoder_final_header(enc, header.data(), (int)header.size());
  steady_allocations += g_allocations;
  CHECK(last >= 0 && final_header == (int)header.size(), "%s: finish %d, final header %d", name,
        last, final_header);
  if (last > 0) {
    stream.insert(stream.end(), out.begin(), out.begin() + last);
  }
  std::copy(header.begin(), header.end(), stream.begin());
  baac_flac_encoder_destroy(enc);
  printf("%-16s encode: %ld allocations over %d steady calls\n", name, steady_allocations,
         steady_calls);
  CHECK(steady_allocations == 0, "%s: the encoder allocates in steady state (%ld)", name,
        steady_allocations);

  const FlacDecoded d = flac_decode(stream, 8192);
  CHECK(d.errors.empty() && d.finish == AAC_OK && d.verdict == BAAC_FLAC_MD5_MATCH && d.pcm == pcm,
        "%s: the stream decodes to the input with its MD5 verified", name);
}

int main() {
  for (const Profile& p : kProfiles) {
    test_profile(p);
  }
  test_flac_decoder("FLAC 16 stereo", 2, 16);
  test_flac_decoder("FLAC 24 5.1", 6, 24);
  test_flac_decoder("FLAC 32 stereo", 2, 32);
  test_flac_encoder("FLAC 16 stereo", 2, 16);
  test_flac_encoder("FLAC 24 5.1", 6, 24);
  test_flac_encoder("FLAC 32 stereo", 2, 32);
  /* The counting operators are in use at all.  The operator is called by
   * name: for a new-expression a compiler may remove an allocation it can
   * pair with its release, or assume that the operator leaves every global
   * as it was (GCC's -fassume-sane-operators-new-delete), and then the
   * comparison below is decided without the counter being read. */
  const long before = g_allocations;
  ::operator delete(::operator new(sizeof(int)));
  CHECK(g_allocations == before + 1, "the allocation counter sees operator new");
  /* The steady-state checks above count the library's allocations, so the
   * counter has to see those too: creating a decoder allocates. */
  g_allocations = 0;
  aac_decoder_destroy(aac_decoder_create(48000, 2));
  CHECK(g_allocations > 0, "the allocation counter sees the library's allocations");
  return test_summary("test_allocation");
}
