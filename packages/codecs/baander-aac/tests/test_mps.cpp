/* MPEG Surround syntax: the SpatialSpecificConfig reader (the AAC form,
 * the in-band header, the low-delay form and the refusals), the
 * SpatialFrame reader over round trips through the test encoder's
 * writer (PCM and Huffman coding, pairs, several parameter sets, the
 * framing types, the TTT modes, one ICC, the arbitrary downmix, the
 * quantiser modes), the residual extension positions, concealment and
 * the committed streams.  The writer is the test tool compiled in as a
 * library. */
#define MPS_ENC_NO_MAIN
#include "../tools/mps-enc.cpp"

#include "test_util.h"

namespace {

using mpsenc::Bits;
using mpsenc::Books;
using mpsenc::Config;
using mpsenc::EcOpts;
using mpsenc::FrameParams;
using mpsenc::History;
using mpsenc::Options;
using mpsenc::kMaxPb;
using mpsenc::kMaxSets;
using mpsenc::kSlots;

int parse_config(const std::vector<uint8_t>& bytes, AacMpsConfig* cfg) {
  AacBitReader r;
  aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
  return aac_mps_config_read(&r, cfg);
}

/* ── the configuration ──────────────────────────────────────────── */

void test_config_round_trip() {
  const int trees[4] = {AAC_MPS_TREE_5151, AAC_MPS_TREE_5152, AAC_MPS_TREE_525, AAC_MPS_TREE_212};
  for (int t = 0; t < 4; t++) {
    for (int variant = 0; variant < 3; variant++) {
      Options o;
      o.tree = trees[t];
      o.freq_res = 1 + variant * 2;
      o.one_icc = variant == 1;
      o.arbdmx = variant == 2;
      o.gain_sur = variant;
      o.gain_lfe = 2 - variant;
      o.gain_dmx = 3 + variant;
      o.temp_shape = variant;
      o.quant_mode = variant;
      o.ttt_mode = variant == 2 ? 4 : variant;
      o.dual_bands = variant == 1 ? 6 : 0;
      const Config c = mpsenc::make_config(o);
      Bits b;
      mpsenc::write_config(b, c);
      const std::vector<uint8_t> bytes = b.bytes();
      AacMpsConfig cfg;
      CHECK(parse_config(bytes, &cfg) == AAC_OK, "tree %d variant %d parses", trees[t], variant);
      CHECK(cfg.sampling_frequency == 48000, "sampling frequency");
      CHECK(cfg.frame_length == kSlots, "frame length %d", cfg.frame_length);
      CHECK(cfg.freq_res == o.freq_res, "freq res");
      CHECK(cfg.tree_config == trees[t], "tree");
      CHECK(cfg.quant_mode == o.quant_mode, "quant mode");
      CHECK(cfg.one_icc == (o.one_icc ? 1 : 0), "one icc");
      CHECK(cfg.arbitrary_downmix == (o.arbdmx ? 1 : 0), "arbitrary downmix");
      CHECK(cfg.fixed_gain_sur == o.gain_sur && cfg.fixed_gain_lfe == o.gain_lfe &&
                cfg.fixed_gain_dmx == o.gain_dmx,
            "fixed gains");
      CHECK(cfg.temp_shape_config == o.temp_shape, "temp shape config");
      CHECK(cfg.env_quant_mode == 0, "env quant mode");
      CHECK(cfg.num_ott_boxes == c.num_ott && cfg.num_ttt_boxes == c.num_ttt, "box counts");
      CHECK(cfg.num_input_channels == c.num_in, "input channels");
      CHECK(cfg.num_output_channels == (trees[t] == AAC_MPS_TREE_212 ? 2 : 6), "output channels");
      for (int i = 0; i < c.num_ott; i++) {
        CHECK(cfg.ott_lfe[i] == c.ott_lfe[i], "lfe box %d", i);
        CHECK(cfg.ott_bands[i] == c.ott_bands[i], "ott bands %d: %d", i, cfg.ott_bands[i]);
      }
      if (c.num_ttt) {
        CHECK(cfg.ttt_mode_low[0] == c.ttt_mode[0], "ttt mode low");
        CHECK(cfg.ttt_dual_mode[0] == c.dual, "ttt dual mode");
        CHECK(cfg.ttt_bands_low[0] == c.ttt_split, "ttt bands low %d", cfg.ttt_bands_low[0]);
        if (c.dual) {
          CHECK(cfg.ttt_mode_high[0] == c.ttt_mode[1], "ttt mode high");
        }
      }
      CHECK(cfg.num_extensions == 0 && cfg.residual_coding == 0, "no extensions");
      CHECK(cfg.low_delay == 0, "not low delay");
    }
  }
}

/* A configuration written bit by bit. */
struct ConfigBits {
  Bits b;
  int tree;
  ConfigBits(int tree_config, int freq_res, int audio_3d, int temp_shape, int low_delay)
      : tree(tree_config) {
    b.put(3, 4);
    b.put(low_delay ? 15u : 15u, low_delay ? 5 : 7);
    b.put((uint32_t)freq_res, 3);
    b.put((uint32_t)tree_config, 4);
    b.put(1, 2); /* bsQuantMode */
    const bool full = !(low_delay && tree_config == AAC_MPS_TREE_212);
    if (full) b.put(1, 1); /* bsOneIcc */
    b.put(0, 1);           /* bsArbitraryDownmix */
    if (full) {
      b.put(2, 3);
      b.put(1, 3);
    }
    b.put(0, 3); /* bsFixedGainDmx */
    if (full) b.put(0, 1);
    b.put((uint32_t)temp_shape, 2);
    b.put(1, 2); /* bsDecorrConfig */
    if (full) b.put((uint32_t)audio_3d, 1);
  }
};

void test_config_refusals() {
  {
    /* a 7.1 tree is read and refused */
    ConfigBits c(3, 2, 0, 0, 0);
    c.b.align();
    AacMpsConfig cfg;
    CHECK(parse_config(c.b.bytes(), &cfg) == AAC_ERR_UNSUPPORTED, "7271 refused");
  }
  {
    /* the 3D audio mode with its HRTF parameter set */
    ConfigBits c(AAC_MPS_TREE_5151, 2, 1, 0, 0);
    c.b.put(2, 5); /* bsOttBands of the LFE box */
    c.b.put(1, 2); /* bs3DaudioHRTFset 1: no parameters */
    c.b.align();
    AacMpsConfig cfg;
    CHECK(parse_config(c.b.bytes(), &cfg) == AAC_ERR_UNSUPPORTED, "3D audio refused");
  }
  {
    /* bsFreqRes 0 is reserved */
    ConfigBits c(AAC_MPS_TREE_5151, 0, 0, 0, 0);
    c.b.align();
    AacMpsConfig cfg;
    CHECK(parse_config(c.b.bytes(), &cfg) == AAC_ERR_DECODE, "freq res 0 refused");
  }
  {
    /* a truncated configuration */
    ConfigBits c(AAC_MPS_TREE_525, 2, 0, 0, 0);
    std::vector<uint8_t> bytes = c.b.bytes();
    bytes.resize(2);
    AacMpsConfig cfg;
    CHECK(parse_config(bytes, &cfg) == AAC_ERR_DECODE, "truncated config refused");
  }
  {
    /* the arbitrary downmix residual extension */
    ConfigBits c(AAC_MPS_TREE_525, 2, 0, 0, 0);
    c.b.put(2, 5); /* bsOttBands of the LFE box */
    c.b.put(0, 1); /* bsTttDualMode */
    c.b.put(1, 3); /* bsTttModeLow */
    c.b.align();
    c.b.put(1, 4); /* bsSacExtType 1 */
    c.b.put(2, 4); /* bsSacExtLen */
    c.b.put(3, 4);
    c.b.put(0, 2);
    c.b.put(5, 5);
    c.b.align();
    AacMpsConfig cfg;
    CHECK(parse_config(c.b.bytes(), &cfg) == AAC_ERR_UNSUPPORTED, "arbitrary downmix residual refused");
  }
}

void test_config_extensions() {
  /* the residual configuration and an unknown extension skipped by its length */
  ConfigBits c(AAC_MPS_TREE_525, 2, 0, 2, 0);
  c.b.put(2, 5); /* bsOttBands of the LFE box */
  c.b.put(1, 1); /* bsTttDualMode */
  c.b.put(0, 3); /* bsTttModeLow */
  c.b.put(2, 3); /* bsTttModeHigh */
  c.b.put(8, 5); /* bsTttBandsLow */
  c.b.put(1, 1); /* bsEnvQuantMode */
  c.b.align();
  c.b.put(0, 4); /* bsSacExtType 0: ResidualConfig */
  c.b.put(4, 4); /* bsSacExtLen */
  c.b.put(3, 4); /* bsResidualSamplingFrequencyIndex */
  c.b.put(1, 2); /* bsResidualFramesPerSpatialFrame: 2 */
  c.b.put(0, 1); /* box 0 */
  c.b.put(1, 1); /* box 1 */
  c.b.put(6, 5);
  c.b.put(0, 1); /* box 2 */
  c.b.put(1, 1); /* the TTT box */
  c.b.put(4, 5);
  c.b.align(); /* 3 bytes used of 4 */
  c.b.put(0, 8);
  c.b.put(7, 4); /* an unknown type */
  c.b.put(15, 4);
  c.b.put(1, 8); /* 16 bytes */
  for (int i = 0; i < 16; i++) c.b.put(0xA5, 8);
  const std::vector<uint8_t> bytes = c.b.bytes();
  AacMpsConfig cfg;
  CHECK(parse_config(bytes, &cfg) == AAC_OK, "extension config parses");
  CHECK(cfg.num_extensions == 2 && cfg.extension_type[0] == 0 && cfg.extension_type[1] == 7,
        "extension types %d %d %d", cfg.num_extensions, cfg.extension_type[0], cfg.extension_type[1]);
  CHECK(cfg.residual_coding == 1, "residual coding");
  CHECK(cfg.residual_sample_rate_index == 3, "residual sampling frequency index");
  CHECK(cfg.residual_frames_per_spatial_frame == 2, "residual frames %d",
        cfg.residual_frames_per_spatial_frame);
  CHECK(cfg.residual_present[0] == 0 && cfg.residual_present[1] == 1 && cfg.residual_present[2] == 0 &&
            cfg.residual_present[3] == 1,
        "residual present");
  CHECK(cfg.residual_bands[1] == 6 && cfg.residual_bands[3] == 4, "residual bands");
  CHECK(cfg.env_quant_mode == 1, "env quant mode");
  CHECK(cfg.ttt_dual_mode[0] == 1 && cfg.ttt_mode_high[0] == 2 && cfg.ttt_bands_low[0] == 8,
        "ttt dual mode");
  CHECK(cfg.decorr_config == 1, "decorr config");
  /* the in-band header form: the same bytes behind a reader bounded to
   * the header length */
  AacBitReader r;
  std::vector<uint8_t> framed = bytes;
  framed.push_back(0xFF);
  framed.push_back(0xFF);
  aac_bitreader_init(&r, framed.data(), (int)bytes.size());
  AacMpsConfig cfg2;
  CHECK(aac_mps_config_read(&r, &cfg2) == AAC_OK, "bounded header parses");
  CHECK(cfg2.num_extensions == 2, "bounded header extensions");
  /* the state takes the extension list */
  AacMpsState* s = aac_mps_create(&cfg, 1024, 16, 48000);
  CHECK(s != NULL, "state with residual config");
  aac_mps_destroy(s);
}

void test_config_low_delay() {
  /* the ELD v2 form: a five-bit frame length, no bsOneIcc, surround and
   * LFE gains, matrix mode or 3D mode for the 2-1-2 tree */
  ConfigBits c(AAC_MPS_TREE_212, 3, 0, 1, 1);
  c.b.align();
  AacBitReader r;
  const std::vector<uint8_t> bytes = c.b.bytes();
  aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
  AacMpsConfig cfg;
  CHECK(aac_mps_config_read_ld(&r, &cfg) == AAC_OK, "low-delay config parses");
  CHECK(cfg.low_delay == 1, "low delay set");
  CHECK(cfg.frame_length == 16, "ld frame length %d", cfg.frame_length);
  CHECK(cfg.tree_config == AAC_MPS_TREE_212, "ld tree");
  CHECK(cfg.freq_res == 3 && aac_mps_param_bands_ld(3) == 12, "ld bands");
  CHECK(cfg.quant_mode == 1 && cfg.one_icc == 0 && cfg.fixed_gain_dmx == 0, "ld fields");
  CHECK(cfg.temp_shape_config == 1 && cfg.decorr_config == 1, "ld shaping and decorrelator");
  CHECK(cfg.num_input_channels == 1 && cfg.num_output_channels == 2, "ld channels");
  CHECK(bytes.size() == 4, "ld config is %zu bytes", bytes.size());
  /* the 5.1 trees in the low-delay form keep the full field set */
  ConfigBits d(AAC_MPS_TREE_5151, 2, 0, 0, 1);
  d.b.put(2, 5);
  d.b.align();
  aac_bitreader_init(&r, d.b.bytes().data(), (int)d.b.bytes().size());
  const std::vector<uint8_t> dbytes = d.b.bytes();
  aac_bitreader_init(&r, dbytes.data(), (int)dbytes.size());
  CHECK(aac_mps_config_read_ld(&r, &cfg) == AAC_OK, "low-delay 5151 parses");
  CHECK(cfg.one_icc == 1 && cfg.fixed_gain_sur == 2 && cfg.fixed_gain_lfe == 1, "ld 5151 gains");
  CHECK(cfg.ott_bands[4] == 2, "ld lfe bands");
  /* the library's writer of the low-delay form against its reader */
  for (int variant = 0; variant < 3; variant++) {
    Options o;
    o.tree = AAC_MPS_TREE_212;
    o.freq_res = 1 + variant * 2;
    o.arbdmx = variant == 2;
    o.gain_dmx = 3 + variant;
    o.temp_shape = variant;
    o.quant_mode = variant;
    const AacMpsConfig written = mpsenc::make_config_ld(o);
    Bits b(64);
    const int len = aac_mps_config_write_ld(&written, &b.w);
    CHECK(len > 0, "ld variant %d writes (%d)", variant, len);
    const std::vector<uint8_t> wbytes = b.bytes();
    CHECK((int)wbytes.size() == len, "ld variant %d: %d bytes", variant, len);
    aac_bitreader_init(&r, wbytes.data(), (int)wbytes.size());
    CHECK(aac_mps_config_read_ld(&r, &cfg) == AAC_OK, "ld variant %d parses", variant);
    CHECK(cfg.low_delay == 1 && cfg.sampling_frequency == 48000, "ld variant %d rate", variant);
    CHECK(cfg.frame_length == mpsenc::kLdSlots && cfg.tree_config == AAC_MPS_TREE_212, "ld variant %d frame",
          variant);
    CHECK(cfg.freq_res == o.freq_res && cfg.ott_bands[0] == aac_mps_param_bands_ld(o.freq_res),
          "ld variant %d bands %d", variant, cfg.ott_bands[0]);
    CHECK(cfg.quant_mode == o.quant_mode && cfg.arbitrary_downmix == (o.arbdmx ? 1 : 0) &&
              cfg.fixed_gain_dmx == o.gain_dmx && cfg.temp_shape_config == o.temp_shape,
          "ld variant %d fields", variant);
    CHECK(cfg.decorr_config == 0 && cfg.num_input_channels == 1 && cfg.num_output_channels == 2,
          "ld variant %d layout", variant);
  }
}

/* ── the frame ──────────────────────────────────────────────────── */

uint32_t g_seed = 12345u;
int rnd(int lo, int hi) {
  g_seed = g_seed * 1664525u + 1013904223u;
  return lo + (int)((g_seed >> 8) % (uint32_t)(hi - lo + 1));
}

/* Random parameters of one frame, smooth over the bands so that the
 * Huffman codes stay inside their range. */
void random_params(const Config& c, int num_sets, int framing, FrameParams& p) {
  memset(&p, 0, sizeof(p));
  p.num_sets = num_sets;
  for (int i = 0; i < num_sets; i++) {
    p.slot[i] = framing ? (kSlots * (2 * i + 1)) / (2 * num_sets)
                        : (kSlots * (i + 1) + num_sets - 1) / num_sets - 1;
  }
  for (int i = 1; framing && i < num_sets; i++) {
    p.slot[i] = std::max(p.slot[i], p.slot[i - 1] + 1);
  }
  auto fill = [&](int (*v)[kMaxPb], int lo, int hi, int bands) {
    for (int s = 0; s < num_sets; s++) {
      int x = rnd(lo, hi);
      for (int pb = 0; pb < bands; pb++) {
        x = std::max(lo, std::min(hi, x + rnd(-3, 3)));
        v[s][pb] = x;
      }
    }
  };
  for (int box = 0; box < c.num_ott; box++) {
    fill(p.ott_cld[box], -15, 15, c.ott_bands[box]);
    fill(p.ott_icc[box], 0, 7, c.ott_bands[box]);
  }
  if (c.one_icc) {
    for (int box = 1; box < c.num_ott; box++) {
      memcpy(p.ott_icc[box], p.ott_icc[0], sizeof(p.ott_icc[0]));
    }
  }
  fill(p.ttt_cpc1, -20, 30, c.bands);
  fill(p.ttt_cpc2, -20, 30, c.bands);
  fill(p.ttt_icc, 0, 7, c.bands);
  fill(p.ttt_cld1, -15, 15, c.bands);
  fill(p.ttt_cld2, -15, 15, c.bands);
  fill(p.arb[0], -15, 15, c.bands);
  fill(p.arb[1], -15, 15, c.bands);
}

void init_history(const Config& c, History& h) {
  memset(&h, 0, sizeof(h));
  for (int range = 0; range < 2; range++) {
    for (int pb = 0; pb < kMaxPb; pb++) {
      h.ttt_cpc1[range][pb] = h.ttt_cpc2[range][pb] = 10;
      h.ttt_cld1[range][pb] = 15;
    }
  }
  for (int box = 0; box < c.num_ott; box++) {
    for (int pb = 0; pb < kMaxPb; pb++) {
      h.ott_cld[box][pb] = c.ott_lfe[box] ? 15 : 0;
    }
  }
}

/* Compares the parser's output with the written parameters; returns
 * the number of differences. */
int compare(const AacMpsState* st, const Config& c, const FrameParams& p) {
  const AacMpsFrameParams* q = aac_mps_frame_params(st);
  int bad = 0;
  auto miss = [&](const char* what, int box, int set, int band, int got, int want) {
    if (bad++ < 4) {
      printf("  mismatch: %s box %d set %d band %d: %d, written %d\n", what, box, set, band, got, want);
    }
  };
  const int extend = p.slot[p.num_sets - 1] != kSlots - 1 ? 1 : 0;
  if (q->num_sets != p.num_sets + extend) bad++;
  if (st->independency != p.indep) bad++;
  if (extend && q->param_slot[p.num_sets] != kSlots - 1) bad++;
  for (int s = 0; s < p.num_sets; s++) {
    if (q->param_slot[s] != p.slot[s]) bad++;
    for (int box = 0; box < c.num_ott; box++) {
      for (int pb = 0; pb < c.ott_bands[box]; pb++) {
        if (q->ott_cld[box][s][pb] != p.ott_cld[box][s][pb]) {
          miss("cld", box, s, pb, q->ott_cld[box][s][pb], p.ott_cld[box][s][pb]);
        }
        if (!c.ott_lfe[box] && q->ott_icc[box][s][pb] != p.ott_icc[box][s][pb]) {
          miss("icc", box, s, pb, q->ott_icc[box][s][pb], p.ott_icc[box][s][pb]);
        }
      }
    }
    for (int pb = 0; c.num_ttt && pb < c.bands; pb++) {
      const bool pred = c.ttt_mode[pb < c.ttt_split ? 0 : 1] < 2;
      if (pred) {
        if (q->ttt_cpc1[0][s][pb] != p.ttt_cpc1[s][pb]) miss("cpc1", 0, s, pb, q->ttt_cpc1[0][s][pb], p.ttt_cpc1[s][pb]);
        if (q->ttt_cpc2[0][s][pb] != p.ttt_cpc2[s][pb]) miss("cpc2", 0, s, pb, q->ttt_cpc2[0][s][pb], p.ttt_cpc2[s][pb]);
        if (q->ttt_icc[0][s][pb] != p.ttt_icc[s][pb]) miss("ttt icc", 0, s, pb, q->ttt_icc[0][s][pb], p.ttt_icc[s][pb]);
      } else {
        if (q->ttt_cld1[0][s][pb] != p.ttt_cld1[s][pb]) miss("cld1", 0, s, pb, q->ttt_cld1[0][s][pb], p.ttt_cld1[s][pb]);
        if (q->ttt_cld2[0][s][pb] != p.ttt_cld2[s][pb]) miss("cld2", 0, s, pb, q->ttt_cld2[0][s][pb], p.ttt_cld2[s][pb]);
      }
    }
    for (int ch = 0; c.arbdmx && ch < c.num_in; ch++) {
      for (int pb = 0; pb < c.bands; pb++) {
        if (q->arbdmx_gain[ch][s][pb] != p.arb[ch][s][pb]) {
          miss("arbdmx", ch, s, pb, q->arbdmx_gain[ch][s][pb], p.arb[ch][s][pb]);
        }
      }
    }
  }
  if (extend) {
    /* the appended set repeats the last coded one */
    for (int box = 0; box < c.num_ott; box++) {
      for (int pb = 0; pb < c.ott_bands[box]; pb++) {
        if (q->ott_cld[box][p.num_sets][pb] != q->ott_cld[box][p.num_sets - 1][pb]) bad++;
      }
    }
  }
  return bad;
}

/* The low-delay frame: the tool's writer of the low-delay SpatialFrame
 * against the reader, with one or two parameter sets, both framing
 * types, PCM and Huffman coding, smoothing and the arbitrary downmix. */
void test_ld_frame_round_trips() {
  using mpsenc::kLdSlots;
  const Books books;
  for (int variant = 0; variant < 6; variant++) {
    Options o;
    o.tree = AAC_MPS_TREE_212;
    o.freq_res = 1 + (variant % 3) * 2;
    o.arbdmx = variant == 5;
    o.smooth = variant == 4;
    o.temp_shape = variant == 3 ? 1 : 0;
    const int sets = 1 + variant % 2;
    const int framing = variant >= 3 ? 1 : 0;
    const AacMpsConfig mps = mpsenc::make_config_ld(o);
    Config c;
    memset(&c, 0, sizeof(c));
    c.tree = AAC_MPS_TREE_212;
    c.freq_res = o.freq_res;
    c.bands = mps.ott_bands[0];
    c.num_ott = 1;
    c.num_in = 1;
    c.ott_bands[0] = c.bands;
    c.gain_dmx = o.gain_dmx;
    c.smooth = o.smooth;
    c.temp_shape = o.temp_shape;
    c.arbdmx = o.arbdmx;
    AacMpsState* st = aac_mps_create(&mps, mpsenc::kLdFrame, kLdSlots, 48000);
    CHECK(st != NULL, "ld variant %d: state", variant);
    if (!st) continue;
    EcOpts ec;
    ec.huffman = variant % 2 == 1;
    ec.pairs = false;
    ec.two_d = ec.huffman;
    ec.aac_form = false;
    History h;
    init_history(c, h);
    int bad = 0;
    for (int frame = 0; frame < 12; frame++) {
      FrameParams p;
      random_params(c, sets, framing, p);
      for (int i = 0; i < sets; i++) {
        p.slot[i] = framing ? (kLdSlots * (2 * i + 1)) / (2 * sets) : (kLdSlots * (i + 1)) / sets - 1;
      }
      p.indep = frame % 5 == 0;
      Bits fb;
      mpsenc::write_frame_ld(fb, books, c, p, h, framing, ec, kLdSlots);
      const std::vector<uint8_t> bytes = fb.bytes();
      AacBitReader r;
      aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
      const int ret = aac_mps_read_frame(st, &r);
      CHECK(ret == AAC_OK, "ld variant %d: frame %d parses (%d)", variant, frame, ret);
      CHECK(aac_bitreader_bits_left(&r) == 0 && !r.overread, "ld variant %d: frame %d ends on the frame",
            variant, frame);
      const AacMpsFrameParams* q = aac_mps_frame_params(st);
      const int extend = p.slot[sets - 1] != kLdSlots - 1 ? 1 : 0;
      if (q->num_sets != sets + extend || st->independency != p.indep) bad++;
      if (extend && q->param_slot[sets] != kLdSlots - 1) bad++;
      for (int s = 0; s < sets; s++) {
        if (q->param_slot[s] != p.slot[s]) bad++;
        for (int pb = 0; pb < c.bands; pb++) {
          if (q->ott_cld[0][s][pb] != p.ott_cld[0][s][pb]) bad++;
          if (q->ott_icc[0][s][pb] != p.ott_icc[0][s][pb]) bad++;
          if (c.arbdmx && q->arbdmx_gain[0][s][pb] != p.arb[0][s][pb]) bad++;
        }
      }
      CHECK(q->temp_shape_enable == 0, "ld variant %d: no temporal shaping", variant);
      if (c.smooth) {
        CHECK(q->smooth_time[0] == 128 && q->smoothing[0][0] == 1, "ld variant %d: smoothing", variant);
      }
    }
    CHECK(bad == 0, "ld variant %d: %d parameters differ after the round trip", variant, bad);
    aac_mps_destroy(st);
  }
}

/* The low-delay filterbank pair reconstructs its input at the documented
 * delay with unit gain. */
void test_ld_filterbank() {
  constexpr int kLdTestSlots = 64;
  AacMpsLdAnalysis ana;
  AacMpsLdSynthesis syn;
  aac_mps_ld_analysis_init(&ana);
  aac_mps_ld_synthesis_init(&syn);
  std::vector<float> in((size_t)kLdTestSlots * AAC_MPS_LD_BANDS), out(in.size());
  for (size_t i = 0; i < in.size(); i++) {
    in[i] = (float)(rnd(-10000, 10000) / 10000.0) * 0.5f + 0.3f * sinf(0.013f * (float)i);
  }
  for (int t = 0; t < kLdTestSlots; t++) {
    float W[AAC_MPS_LD_BANDS][2];
    aac_mps_ld_analyze(&ana, &in[(size_t)t * AAC_MPS_LD_BANDS], W);
    aac_mps_ld_synthesize(&syn, W, &out[(size_t)t * AAC_MPS_LD_BANDS]);
  }
  double sig = 0.0, err = 0.0;
  for (size_t i = AAC_MPS_LD_DELAY; i < out.size(); i++) {
    const double r = in[i - AAC_MPS_LD_DELAY];
    sig += r * r;
    err += (r - out[i]) * (r - out[i]);
  }
  const double snr = err > 0.0 ? 10.0 * log10(sig / err) : 200.0;
  CHECK(snr >= 60.0, "ld filterbank: %.1f dB at delay %d", snr, AAC_MPS_LD_DELAY);
}

struct FrameCase {
  const char* name;
  int tree, huffman, pairs, sets, framing, freq_res, ttt_mode, dual, one_icc, arbdmx, smooth;
};

void test_frame_round_trips() {
  const FrameCase cases[] = {
      {"5151 pcm", AAC_MPS_TREE_5151, 0, 0, 1, 0, 2, 0, 0, 0, 0, 0},
      {"5151 huffman", AAC_MPS_TREE_5151, 1, 0, 1, 0, 2, 0, 0, 0, 0, 0},
      {"5151 pairs 8 sets framing", AAC_MPS_TREE_5151, 1, 1, 8, 1, 5, 0, 0, 0, 0, 1},
      {"5152 pairs 3 sets one icc", AAC_MPS_TREE_5152, 1, 1, 3, 1, 1, 0, 0, 1, 0, 0},
      {"5152 pcm 2 sets", AAC_MPS_TREE_5152, 0, 0, 2, 0, 4, 0, 0, 0, 0, 0},
      {"525 prediction icc", AAC_MPS_TREE_525, 1, 1, 2, 0, 2, 0, 0, 0, 1, 0},
      {"525 prediction", AAC_MPS_TREE_525, 0, 0, 1, 0, 3, 1, 0, 0, 0, 0},
      {"525 energy", AAC_MPS_TREE_525, 1, 0, 2, 1, 2, 4, 0, 1, 1, 1},
      {"525 dual mode", AAC_MPS_TREE_525, 1, 1, 4, 1, 1, 0, 8, 0, 0, 0},
      {"525 dual mode energy low", AAC_MPS_TREE_525, 1, 1, 5, 0, 2, 5, 7, 0, 1, 0},
      {"212", AAC_MPS_TREE_212, 1, 1, 2, 0, 2, 0, 0, 0, 0, 0},
  };
  const Books books;
  for (const FrameCase& fc : cases) {
    Options o;
    o.tree = fc.tree;
    o.freq_res = fc.freq_res;
    o.ttt_mode = fc.ttt_mode;
    o.dual_bands = fc.dual;
    o.one_icc = fc.one_icc;
    o.arbdmx = fc.arbdmx;
    o.smooth = fc.smooth;
    const Config c = mpsenc::make_config(o);
    Bits cb;
    mpsenc::write_config(cb, c);
    AacMpsConfig cfg;
    CHECK(parse_config(cb.bytes(), &cfg) == AAC_OK, "%s: config", fc.name);
    AacMpsState* st = aac_mps_create(&cfg, 1024, kSlots, 48000);
    CHECK(st != NULL, "%s: state", fc.name);
    if (!st) continue;
    EcOpts ec;
    ec.huffman = fc.huffman != 0;
    ec.pairs = fc.pairs != 0;
    ec.two_d = fc.huffman != 0;
    ec.aac_form = true;
    History h;
    init_history(c, h);
    int bad = 0;
    for (int frame = 0; frame < 12; frame++) {
      FrameParams p;
      random_params(c, fc.sets, fc.framing, p);
      p.indep = frame % 5 == 0;
      Bits fb;
      mpsenc::write_frame(fb, books, c, p, h, fc.framing, ec);
      const std::vector<uint8_t> bytes = fb.bytes();
      AacBitReader r;
      aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
      const int ret = aac_mps_read_frame(st, &r);
      CHECK(ret == AAC_OK, "%s: frame %d parses (%d)", fc.name, frame, ret);
      CHECK(aac_bitreader_bits_left(&r) < 8 && !r.overread, "%s: frame %d ends on the frame (%d bits left)",
            fc.name, frame, aac_bitreader_bits_left(&r));
      bad += compare(st, c, p);
      const AacMpsFrameParams* q = aac_mps_frame_params(st);
      if (fc.smooth) {
        CHECK(q->smooth_time[0] == 128 && q->smoothing[0][0] == 1, "%s: smoothing", fc.name);
      } else {
        CHECK(q->smooth_time[0] == 256 && q->smoothing[0][0] == 0, "%s: no smoothing", fc.name);
      }
      CHECK(q->temp_shape_enable == 0, "%s: no temporal shaping", fc.name);
      if (c.one_icc) {
        /* every box that is not an LFE box carries the one ICC */
        int src = 0;
        while (c.ott_lfe[src]) src++;
        for (int box = src + 1; box < c.num_ott; box++) {
          if (!c.ott_lfe[box] && memcmp(q->ott_icc[box], q->ott_icc[src], sizeof(q->ott_icc[0])) != 0) {
            bad++;
          }
        }
      }
    }
    CHECK(bad == 0, "%s: %d parameters differ after the round trip", fc.name, bad);
    aac_mps_destroy(st);
  }
}

void test_quant_mode() {
  /* bsQuantMode 1 scales the CLDs of the mono trees by the level of the
   * box input against the total: box 0 splits the total (a factor of
   * 1), a box fed by a quiet output gets its index widened */
  Options o;
  o.tree = AAC_MPS_TREE_5151;
  o.quant_mode = 1;
  const Config c = mpsenc::make_config(o);
  Bits cb;
  mpsenc::write_config(cb, c);
  AacMpsConfig cfg;
  CHECK(parse_config(cb.bytes(), &cfg) == AAC_OK, "quant mode config");
  AacMpsState* st = aac_mps_create(&cfg, 1024, kSlots, 48000);
  const Books books;
  EcOpts ec;
  ec.huffman = false;
  ec.pairs = false;
  ec.two_d = false;
  ec.aac_form = true;
  History h;
  init_history(c, h);
  FrameParams p;
  random_params(c, 1, 0, p);
  p.indep = 1;
  for (int pb = 0; pb < c.bands; pb++) {
    p.ott_cld[0][0][pb] = 5; /* index 5: the surround side 10 dB... */
    p.ott_cld[1][0][pb] = 0;
    p.ott_cld[2][0][pb] = 4; /* ...carries a 4 dB CLD: the input sits 10.4 dB below */
    p.ott_cld[3][0][pb] = 4;
  }
  Bits fb;
  mpsenc::write_frame(fb, books, c, p, h, 0, ec);
  AacBitReader r;
  const std::vector<uint8_t> bytes = fb.bytes();
  aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
  CHECK(aac_mps_read_frame(st, &r) == AAC_OK, "quant mode frame");
  const AacMpsFrameParams* q = aac_mps_frame_params(st);
  CHECK(q->ott_cld[0][0][0] == 5, "box 0 unscaled: %d", q->ott_cld[0][0][0]);
  /* box 2 input: 10 log10(1/(1+10)) = -10.41 dB; factor 1 + 9.41 * 0.2 = 2.88;
   * 4 * 2.88 = 11.5 -> 12 */
  CHECK(q->ott_cld[2][0][0] == 12, "box 2 scaled: %d", q->ott_cld[2][0][0]);
  /* box 3 input: box 1 output 1 at -3.01 dB of box 0's output 1 at
   * -0.41 dB: -3.42 dB; factor 1.48; 4 * 1.48 = 5.9 -> 6 */
  CHECK(q->ott_cld[3][0][0] == 6, "box 3 scaled: %d", q->ott_cld[3][0][0]);
  aac_mps_destroy(st);
}

void test_conceal_and_reset() {
  Options o;
  o.tree = AAC_MPS_TREE_525;
  o.ttt_mode = 1;
  const Config c = mpsenc::make_config(o);
  Bits cb;
  mpsenc::write_config(cb, c);
  AacMpsConfig cfg;
  CHECK(parse_config(cb.bytes(), &cfg) == AAC_OK, "conceal config");
  AacMpsState* st = aac_mps_create(&cfg, 1024, kSlots, 48000);
  /* before any frame: one set at the frame end holding the defaults */
  const AacMpsFrameParams* q = aac_mps_frame_params(st);
  CHECK(q->num_sets == 1 && q->param_slot[0] == kSlots - 1, "initial set");
  /* the LFE box and the L/Ls box default to everything on the first output */
  CHECK(q->ott_cld[0][0][0] == 15 && q->ott_cld[1][0][0] == 15 && q->ott_icc[1][0][0] == 0 &&
            q->ttt_cpc1[0][0][0] == 10 && q->ttt_cld1[0][0][0] == 15,
        "initial defaults %d %d %d", q->ott_cld[0][0][0], q->ott_cld[1][0][0], q->ttt_cpc1[0][0][0]);
  const Books books;
  EcOpts ec;
  ec.huffman = true;
  ec.pairs = true;
  ec.two_d = true;
  ec.aac_form = true;
  History h;
  init_history(c, h);
  FrameParams p;
  random_params(c, 3, 1, p);
  p.indep = 1;
  Bits fb;
  mpsenc::write_frame(fb, books, c, p, h, 1, ec);
  const std::vector<uint8_t> bytes = fb.bytes();
  AacBitReader r;
  aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
  CHECK(aac_mps_read_frame(st, &r) == AAC_OK, "conceal: frame parses");
  CHECK(compare(st, c, p) == 0, "conceal: frame round trip");
  const int last = p.num_sets - 1;
  /* a lost frame keeps the last set */
  aac_mps_conceal(st);
  CHECK(q->num_sets == 1 && q->param_slot[0] == kSlots - 1, "concealed set");
  CHECK(q->ott_cld[1][0][3] == p.ott_cld[1][last][3] && q->ttt_cpc2[0][0][5] == p.ttt_cpc2[last][5],
        "concealed values");
  /* a damaged frame does the same */
  std::vector<uint8_t> damaged = bytes;
  damaged.resize(damaged.size() / 3);
  aac_bitreader_init(&r, damaged.data(), (int)damaged.size());
  CHECK(aac_mps_read_frame(st, &r) == AAC_ERR_DECODE, "truncated frame refused");
  CHECK(q->num_sets == 1 && q->ott_cld[1][0][3] == p.ott_cld[1][last][3], "truncated frame concealed");
  /* the reset returns to the defaults */
  aac_mps_reset(st);
  CHECK(q->ott_cld[0][0][0] == 15 && q->ott_cld[1][0][0] == 15 && q->ttt_cpc2[0][0][0] == 10,
        "reset defaults");
  /* a frame after the reset must be independent to parse: a time
   * difference to a history the writer did not share parses into other
   * values, which the round trip shows */
  init_history(c, h);
  random_params(c, 1, 0, p);
  p.indep = 1;
  Bits fb2;
  mpsenc::write_frame(fb2, books, c, p, h, 0, ec);
  const std::vector<uint8_t> bytes2 = fb2.bytes();
  aac_bitreader_init(&r, bytes2.data(), (int)bytes2.size());
  CHECK(aac_mps_read_frame(st, &r) == AAC_OK, "frame after reset");
  CHECK(compare(st, c, p) == 0, "round trip after reset");
  aac_mps_destroy(st);
}

int g_residual_calls = 0;
int residual_reader(void* ctx, AacBitReader* r, int box, int frame, int upd_qmf) {
  (void)ctx;
  g_residual_calls++;
  if (box != 1 || upd_qmf != 8 || frame < 0 || frame > 1) {
    return AAC_ERR_DECODE;
  }
  /* the stand-in for individual_channel_stream(): a 12-bit marker */
  return aac_bitreader_read(r, 12) == 0xABC ? AAC_OK : AAC_ERR_DECODE;
}

void test_residual_positions() {
  /* a 5151 configuration with a residual on box 1 (6 bands, 2 residual
   * frames) and a frame carrying the extension: the parser reports
   * where the channel elements start and hands them to the reader */
  ConfigBits cb(AAC_MPS_TREE_5151, 2, 0, 0, 0);
  cb.b.put(2, 5);
  cb.b.align();
  cb.b.put(0, 4);
  cb.b.put(2, 4);
  cb.b.put(3, 4);
  cb.b.put(1, 2); /* two residual frames per spatial frame */
  cb.b.put(0, 1);
  cb.b.put(1, 1);
  cb.b.put(6, 5);
  cb.b.put(0, 1);
  cb.b.put(0, 1);
  cb.b.put(0, 1);
  cb.b.align();
  AacMpsConfig cfg;
  CHECK(parse_config(cb.b.bytes(), &cfg) == AAC_OK, "residual config");
  CHECK(cfg.residual_present[1] == 1 && cfg.residual_bands[1] == 6, "residual box 1");
  AacMpsState* st = aac_mps_create(&cfg, 1024, kSlots, 48000);
  CHECK(st != NULL, "residual state");
  /* the frame: the plain part from the writer, then the extension frame */
  Options o;
  o.tree = AAC_MPS_TREE_5151;
  o.one_icc = true;
  const Config c = mpsenc::make_config(o);
  const Books books;
  EcOpts ec;
  ec.huffman = false;
  ec.pairs = false;
  ec.two_d = false;
  ec.aac_form = true;
  History h;
  init_history(c, h);
  FrameParams p;
  random_params(c, 1, 0, p);
  p.indep = 1;
  Bits fb;
  mpsenc::write_frame(fb, books, c, p, h, 0, ec);
  const int plain_bits = fb.bits();
  /* ResidualData(): box 1: bsIccDiffPresent 1 and six unary bsIccDiff
   * values, then two residual frames of 12 bits, byte aligned */
  Bits ext;
  ext.put(1, 1);
  const int diffs[6] = {0, 1, 2, 0, 3, 1};
  for (int pb = 0; pb < 6; pb++) {
    for (int i = 0; i < diffs[pb]; i++) ext.put(0, 1);
    ext.put(1, 1);
  }
  const int icc_bits = ext.bits();
  ext.put(0xABC, 12);
  ext.put(0xABC, 12);
  ext.align();
  const std::vector<uint8_t> ext_bytes = ext.bytes();
  fb.put((uint32_t)ext_bytes.size(), 8);
  for (uint8_t v : ext_bytes) fb.put(v, 8);
  const std::vector<uint8_t> bytes = fb.bytes();
  /* without a reader the extension is skipped by its length */
  AacBitReader r;
  aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
  CHECK(aac_mps_read_frame(st, &r) == AAC_OK, "residual frame parses without a reader");
  CHECK(aac_bitreader_bits_left(&r) == 0, "residual frame consumed (%d left)", aac_bitreader_bits_left(&r));
  CHECK(st->residual_present == 1, "residual present");
  CHECK(st->residual_pos == plain_bits + 8 + icc_bits, "residual position %d (expected %d)",
        st->residual_pos, plain_bits + 8 + icc_bits);
  CHECK(st->residual_end == (int)bytes.size() * 8, "residual end %d", st->residual_end);
  const AacMpsFrameParams* q = aac_mps_frame_params(st);
  for (int pb = 0; pb < 6; pb++) {
    const int expect = std::min(7, p.ott_icc[1][0][pb] + diffs[pb]);
    CHECK(q->ott_icc[1][0][pb] == expect, "icc diff band %d: %d (expected %d)", pb,
          q->ott_icc[1][0][pb], expect);
  }
  CHECK(q->ott_icc[1][0][6] == p.ott_icc[1][0][6], "icc beyond the residual bands");
  /* with a reader the channel elements go through it */
  aac_mps_set_residual_reader(st, residual_reader, NULL);
  init_history(c, h);
  Bits fb2;
  mpsenc::write_frame(fb2, books, c, p, h, 0, ec);
  fb2.put((uint32_t)ext_bytes.size(), 8);
  for (uint8_t v : ext_bytes) fb2.put(v, 8);
  const std::vector<uint8_t> bytes2 = fb2.bytes();
  aac_bitreader_init(&r, bytes2.data(), (int)bytes2.size());
  g_residual_calls = 0;
  CHECK(aac_mps_read_frame(st, &r) == AAC_OK, "residual frame parses with the reader");
  CHECK(g_residual_calls == 2, "reader called %d times", g_residual_calls);
  /* a reader failure fails the frame */
  std::vector<uint8_t> broken = bytes2;
  broken[(size_t)(st->residual_pos / 8)] ^= 0x80;
  aac_bitreader_init(&r, broken.data(), (int)broken.size());
  CHECK(aac_mps_read_frame(st, &r) == AAC_ERR_DECODE, "reader failure fails the frame");
  aac_mps_destroy(st);
}

void test_temp_shape() {
  /* the GES flags and envelopes land on the canonical channels */
  Options o;
  o.tree = AAC_MPS_TREE_525;
  o.temp_shape = 2;
  const Config c = mpsenc::make_config(o);
  Bits cb;
  mpsenc::write_config(cb, c);
  AacMpsConfig cfg;
  CHECK(parse_config(cb.bytes(), &cfg) == AAC_OK, "temp shape config");
  AacMpsState* st = aac_mps_create(&cfg, 1024, kSlots, 48000);
  /* a frame by hand: one set, every stream bsDataMode 0, no smoothing,
   * the envelope of the fourth flagged channel (Ls) coded as 16 slots
   * of bsEnvShapeData 2 in one run, no arbitrary downmix */
  Bits fb;
  fb.put(0, 1); /* bsFramingType */
  fb.put(0, 3); /* one set */
  fb.put(1, 1); /* bsIndependencyFlag */
  for (int i = 0; i < 3; i++) fb.put(0, 2);  /* OTT CLDs */
  for (int i = 0; i < 2; i++) fb.put(0, 2);  /* OTT ICCs of the non-LFE boxes */
  for (int i = 0; i < 3; i++) fb.put(0, 2);  /* TTT CPC1, CPC2, ICC */
  fb.put(0, 2);                              /* bsSmoothMode */
  fb.put(1, 1);                              /* bsTempShapeEnable */
  fb.put(0, 1); fb.put(0, 1); fb.put(0, 1); fb.put(1, 1); fb.put(0, 1); /* L R C Ls Rs */
  /* the run-length code of (value 2, run 16): the node walk of the
   * envelope book is driven through the shared reader, so the test
   * writes the symbol with the tool's book */
  const mpsenc::Book env = mpsenc::build_book(aac_mps212_huff_env_shape, 39);
  for (int n = 0; n < kSlots;) {
    int run = std::min(16, kSlots - n);
    while (run > 1 && !env.has((2 << 4) | (run - 1))) run--;
    CHECK(env.has((2 << 4) | (run - 1)), "envelope run %d has a code", run);
    mpsenc::put_code(fb, env, (2 << 4) | (run - 1));
    n += run;
  }
  fb.align();
  const std::vector<uint8_t> bytes = fb.bytes();
  AacBitReader r;
  aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
  CHECK(aac_mps_read_frame(st, &r) == AAC_OK, "temp shape frame parses");
  const AacMpsFrameParams* q = aac_mps_frame_params(st);
  CHECK(q->temp_shape_enable == 1, "temp shape enabled");
  CHECK(q->ges_channel[4] == 1 && q->ges_channel[0] == 0 && q->ges_channel[3] == 0 && q->ges_channel[5] == 0,
        "ges flag on Ls");
  CHECK(fabsf(q->env_shape[4][0] - 2.0f) < 1e-6f && fabsf(q->env_shape[4][15] - 2.0f) < 1e-6f,
        "ges envelope %f", q->env_shape[4][0]);
  CHECK(q->ott_cld[0][0][0] == 15 && q->ttt_cpc1[0][0][0] == 10, "default sets");
  aac_mps_destroy(st);
}

/* ── the committed streams ──────────────────────────────────────── */

void test_committed_streams() {
  const char* names[4] = {"mps_5151_header.loas", "mps_5152_header.loas", "mps_525_header.loas",
                          "mps_525_asc.loas"};
  for (int i = 0; i < 4; i++) {
    const std::vector<uint8_t> data = read_file(data_path(names[i]));
    CHECK(!data.empty(), "%s present", names[i]);
    if (data.empty()) continue;
    size_t pos = 0;
    int frames = 0;
    int mux_version = -1;
    while (pos + 3 <= data.size()) {
      const int sync = (data[pos] << 3) | (data[pos + 1] >> 5);
      if (sync != 0x2B7) break;
      const size_t len = (size_t)((data[pos + 1] & 0x1F) << 8 | data[pos + 2]);
      if (frames == 0) {
        mux_version = (data[pos + 3] >> 6) & 1;
      }
      pos += 3 + len;
      frames++;
    }
    CHECK(pos == data.size(), "%s: LOAS frames cover the file", names[i]);
    CHECK(frames == 96, "%s: %d frames", names[i], frames);
    CHECK(mux_version == (i == 3 ? 1 : 0), "%s: audioMuxVersion %d", names[i], mux_version);
  }
  /* the libxaac decodes are six channels of the quiet streams' first second */
  const char* wavs[4] = {"mps_5151_header_q_xaac.wav", "mps_5152_header_q_xaac.wav",
                         "mps_525_header_q_xaac.wav", "mps_525_energy_q_xaac.wav"};
  for (int i = 0; i < 4; i++) {
    const std::vector<uint8_t> w = read_file(data_path(wavs[i]));
    CHECK(w.size() > 44, "%s present", wavs[i]);
    if (w.size() <= 44) continue;
    const int channels = w[22] | (w[23] << 8);
    CHECK(channels == 6, "%s: %d channels", wavs[i], channels);
  }
}

}  // namespace

int main() {
  test_config_round_trip();
  test_config_refusals();
  test_config_extensions();
  test_config_low_delay();
  test_frame_round_trips();
  test_ld_frame_round_trips();
  test_ld_filterbank();
  test_quant_mode();
  test_conceal_and_reset();
  test_residual_positions();
  test_temp_shape();
  test_committed_streams();
  return test_summary("test_mps");
}
