/* MPEG-D DRC harness: the uniDrcConfig(), loudnessInfoSet() and
 * uniDrcGain() parsers, and the whole module (configuration, stream
 * layout, the player's request, the gain of every frame and its
 * application), whose output must stay finite.
 *
 *   byte 0  bits 0-1 the mode: 0 parse a uniDrcConfig then a
 *           loudnessInfoSet from the same bits, 1 parse uniDrcGain
 *           payloads against the built-in stereo configuration, 2 the
 *           module with the configuration from the input, 3 the module
 *           with the built-in configuration
 *           bits 2-3 the frame length (1024, 2048, 4096, 768)
 *           bits 4-6 the sample rate (48000, 44100, 32000, 24000,
 *           16000, 96000, 8000, 22050)
 *   byte 1  the output channel count (mod 9)
 *   bytes 2-3  the request: effect, album mode, normalisation, target
 *   byte 4  the uniDrcConfig length (mode 2), byte 5 the
 *           loudnessInfoSet length (mode 2); the bytes follow
 *   rest    gain payloads, each a byte of length then the bytes */
#include <cstring>
#include <vector>

#include "bitstream.h"
#include "fuzz_driver.h"
#include "unidrc.h"

/* The uniDrcConfig() and loudnessInfoSet() the FDK xHE-AAC encoder
 * writes for a stereo programme (the configuration of
 * tests/data/usac_music_48000_64000.loas). */
static const uint8_t kConfigStereo[] = {
    0x00, 0x00, 0x01, 0x24, 0xb6, 0x68, 0x10, 0x20, 0x40, 0x60, 0xb5, 0xc8, 0x80, 0x20, 0x90, 0x20,
    0x80, 0x90, 0x80, 0x00, 0x9f, 0xc0, 0x18, 0x00, 0x11, 0x08, 0x00, 0x55, 0xfc, 0x01, 0x80, 0xc2,
    0x81, 0x90, 0x80, 0x08, 0x1f, 0xf2, 0x00, 0x60, 0x28, 0x60, 0x84, 0x20, 0x02, 0x06, 0x40, 0x06,
    0x02, 0x00, 0x00};
static const uint8_t kLoudness[] = {0x00, 0x10, 0x00, 0x4b, 0x8c, 0x23, 0x5c, 0x58};

static void check_parse_return(int ret) {
  if (ret != AAC_OK && ret != AAC_ERR_DECODE && ret != AAC_ERR_UNSUPPORTED) {
    fuzz_fail("DRC parser return outside the documented codes", ret);
  }
}

static void check_config(const AacUniDrcConfig* c) {
  if (c->coefficients_count < 0 || c->instructions_count < 0 || c->downmix_count < 0 ||
      c->instructions_count > AAC_UNIDRC_MAX_INSTRUCTIONS ||
      c->downmix_count > AAC_UNIDRC_MAX_DOWNMIXES) {
    fuzz_fail("uniDrcConfig counts out of range", c->instructions_count);
  }
  for (int i = 0; i < c->coefficients_count; i++) {
    if (c->coefficients[i].gain_set_count < 0 ||
        c->coefficients[i].gain_set_count > AAC_UNIDRC_MAX_GAIN_SETS) {
      fuzz_fail("gain set count out of range", c->coefficients[i].gain_set_count);
    }
    for (int g = 0; g < c->coefficients[i].gain_set_count; g++) {
      const AacUniDrcGainSet* gs = &c->coefficients[i].gain_set[g];
      if (gs->band_count < 0 || gs->band_count > AAC_UNIDRC_MAX_BANDS) {
        fuzz_fail("band count out of range", gs->band_count);
      }
      aac_unidrc_time_delta_min(gs, 48000);
    }
  }
  for (int i = 0; i < c->instructions_count; i++) {
    for (int b = 0; b < AAC_UNIDRC_MAX_BANDS; b++) {
      aac_unidrc_sequence_index(c, i, b);
    }
  }
}

static void check_gain(const AacUniDrcGainFrame* g) {
  if (g->sequence_count < 0 || g->sequence_count > AAC_UNIDRC_MAX_SEQUENCES) {
    fuzz_fail("gain sequence count out of range", g->sequence_count);
  }
  for (int s = 0; s < g->sequence_count; s++) {
    if (g->seq[s].node_count < 0 || g->seq[s].node_count > AAC_UNIDRC_MAX_NODES) {
      fuzz_fail("node count out of range", g->seq[s].node_count);
    }
    for (int q = 0; q < g->seq[s].node_count; q++) {
      if (!std::isfinite(g->seq[s].node[q].gain_db)) {
        fuzz_fail("node gain not finite", q);
      }
    }
  }
}

extern "C" int LLVMFuzzerTestOneInput(const uint8_t* data, size_t size) {
  FuzzInput in(data, size);
  const uint8_t options = in.byte();
  const int channels = in.byte() % 9;
  const uint8_t req0 = in.byte();
  const uint8_t req1 = in.byte();
  static const int kFrames[4] = {1024, 2048, 4096, 768};
  static const int kRates[8] = {48000, 44100, 32000, 24000, 16000, 96000, 8000, 22050};
  const int frame_length = kFrames[options >> 2 & 3];
  const int sample_rate = kRates[options >> 4 & 7];
  const int mode = options & 3;

  if (mode == 0) {
    AacBitReader r;
    aac_bitreader_init(&r, in.rest(), (int)in.left());
    AacUniDrcConfig c;
    const int ret = aac_unidrc_parse_config(&r, &c);
    check_parse_return(ret);
    if (ret == AAC_OK) {
      check_config(&c);
    }
    AacUniDrcLoudnessSet l;
    const int lret = aac_unidrc_parse_loudness(&r, &l);
    check_parse_return(lret);
    if (lret == AAC_OK && (l.count < 0 || l.count > AAC_UNIDRC_MAX_LOUDNESS_INFO ||
                           l.album_count < 0 || l.album_count > AAC_UNIDRC_MAX_LOUDNESS_INFO)) {
      fuzz_fail("loudnessInfo counts out of range", l.count);
    }
    return 0;
  }
  if (mode == 1) {
    static AacUniDrcConfig cfg;
    static bool cfg_ready = false;
    if (!cfg_ready) {
      AacBitReader r;
      aac_bitreader_init(&r, kConfigStereo, (int)sizeof(kConfigStereo));
      if (aac_unidrc_parse_config(&r, &cfg) != AAC_OK) {
        fuzz_fail("the built-in configuration does not parse", 0);
      }
      cfg_ready = true;
    }
    while (in.left() > 0) {
      const uint8_t* payload;
      const size_t got = in.take(in.byte(), &payload);
      AacBitReader r;
      aac_bitreader_init(&r, payload, (int)got);
      AacUniDrcGainFrame g;
      const int bits = (int)got * 8 - (int)(req0 & 7);
      const int ret = aac_unidrc_parse_gain(&cfg, &r, bits < 0 ? 0 : bits, frame_length,
                                            sample_rate, &g);
      check_parse_return(ret);
      if (ret == AAC_OK) {
        check_gain(&g);
      }
    }
    return 0;
  }

  const uint8_t* cfg = kConfigStereo;
  size_t cfg_len = sizeof(kConfigStereo);
  const uint8_t* loud = kLoudness;
  size_t loud_len = sizeof(kLoudness);
  if (mode == 2) {
    const uint8_t c_len = in.byte();
    const uint8_t l_len = in.byte();
    cfg_len = in.take(c_len, &cfg);
    loud_len = in.take(l_len, &loud);
  }
  AacUniDrc* d = aac_unidrc_create();
  if (!d) {
    return 0;
  }
  const int ret = aac_unidrc_set_config(d, cfg, (int)cfg_len, loud, (int)loud_len);
  check_parse_return(ret);
  if (ret == AAC_OK) {
    check_config(aac_unidrc_config(d));
  }
  const int stream_channels = channels ? channels : 2;
  aac_unidrc_set_stream(d, sample_rate, stream_channels, frame_length);
  AacUniDrcRequest q;
  q.effect = (int)(req0 & 7) - 1;
  q.album_mode = req0 >> 3 & 1;
  q.loudness_normalization = req0 >> 4 & 1;
  q.peak_limiter = req0 >> 5 & 1;
  q.target_loudness_x100 = -3100 + 100 * (int)(req1 & 31);
  q.boost_percent = (req1 >> 5 & 7) * 100 / 7;
  q.cut_percent = 100 - q.boost_percent;
  aac_unidrc_set_request(d, &q);
  int pl = 0, ng = 0, id = 0;
  aac_unidrc_status(d, &pl, &ng, &id);
  aac_unidrc_selected_instructions(d);
  aac_unidrc_selected_loudness(d);

  std::vector<float> pcm((size_t)frame_length * (size_t)stream_channels, 0.5f);
  std::vector<float*> ch((size_t)stream_channels);
  for (int c = 0; c < stream_channels; c++) {
    ch[(size_t)c] = pcm.data() + (size_t)c * (size_t)frame_length;
  }
  std::vector<float> gains((size_t)frame_length);
  int frame = 0;
  while (in.left() > 0 && frame < 64) {
    const uint8_t* payload;
    const size_t got = in.take(in.byte(), &payload);
    if (got == 0) {
      aac_unidrc_no_gain(d);
    } else {
      AacBitReader r;
      aac_bitreader_init(&r, payload, (int)got);
      check_parse_return(aac_unidrc_read_gain(d, &r, (int)got * 8, frame == 0 || (payload[0] & 1)));
    }
    aac_unidrc_apply(d, ch.data(), stream_channels, frame_length);
    fuzz_expect_finite(pcm.data(), pcm.size(), "DRC output not finite");
    for (int c = 0; c < stream_channels; c++) {
      aac_unidrc_channel_gain(d, c, gains.data(), frame_length);
      fuzz_expect_finite(gains.data(), gains.size(), "DRC channel gain not finite");
    }
    if (frame == 7) {
      aac_unidrc_reset(d);
      aac_unidrc_set_stream(d, sample_rate, stream_channels, frame_length);
    }
    frame++;
  }
  aac_unidrc_status(d, &pl, &ng, &id);
  aac_unidrc_destroy(d);
  return 0;
}
