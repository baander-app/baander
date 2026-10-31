/* No heap use on the encode and decode paths once a codec is running:
 * the global allocation operators are replaced by counting versions, an
 * encoder and a decoder of every profile run a few frames to settle (the
 * decoder's SBR state and the transport's buffers are created on the
 * first frames), and from then on every encoded and decoded frame must
 * allocate nothing.  The decoder's input FIFO is filled before the
 * steady-state phase so that its growth, which is allowed, does not
 * count. */
#include <cstdlib>
#include <new>

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
      const int got = aac_decoder_decode(dec, stream.data() + frame_offsets[f],
                                         (int)(end - frame_offsets[f]), out.data(), (int)out.size());
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

int main() {
  for (const Profile& p : kProfiles) {
    test_profile(p);
  }
  /* The counting operators are in use at all. */
  const long before = g_allocations;
  delete new int(1);
  CHECK(g_allocations == before + 1, "the allocation counter sees operator new");
  return test_summary("test_allocation");
}
