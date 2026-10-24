/* USAC configuration, transport and frame walker: the UsacConfig parse
 * of xHE-AAC streams against values read off the reference parser, the
 * escapedValue edge cases, the LATM demultiplexing and raw access unit
 * paths, the audio pre-roll payload, configuration equality and layout
 * mapping, the walker over the module stubs, and bit-flip fuzzing of the
 * configuration and the first access unit. */
#include <climits>

#include "asc.h"
#include "bitstream.h"
#include "decoder.h"
#include "latm.h"
#include "test_util.h"
#include "usac.h"

namespace {

std::string data_path(const char* name) { return std::string(BAAC_TEST_DATA_DIR "/") + name; }

/* The LOAS frames of a stream, header included. */
std::vector<std::vector<uint8_t>> split_loas(const std::vector<uint8_t>& s) {
  std::vector<std::vector<uint8_t>> frames;
  size_t pos = 0;
  while (pos + AAC_LOAS_HEADER_BYTES <= s.size() &&
         aac_loas_is_sync(s.data() + pos, (int)(s.size() - pos))) {
    const size_t len = AAC_LOAS_HEADER_BYTES + (size_t)aac_loas_payload_length(s.data() + pos);
    if (pos + len > s.size()) {
      break;
    }
    frames.emplace_back(s.begin() + (long)pos, s.begin() + (long)(pos + len));
    pos += len;
  }
  return frames;
}

/* The AudioSpecificConfig bytes of a stream's first LOAS frame: the
 * kit's StreamMuxConfig is 16 bits of fixed fields, so the config starts
 * byte aligned at the third payload byte. */
std::vector<uint8_t> asc_of(const std::vector<uint8_t>& frame0) {
  const uint8_t* payload = frame0.data() + AAC_LOAS_HEADER_BYTES;
  const int size = (int)frame0.size() - AAC_LOAS_HEADER_BYTES;
  CHECK(size > 2 && payload[0] == 0x20 && payload[1] == 0x00, "16-bit mux header");
  AacBitReader r;
  aac_bitreader_init(&r, payload + 2, size - 2);
  AacAsc asc;
  CHECK(aac_asc_read(&r, &asc) == AAC_OK, "asc of first frame parses");
  const int bytes = (r.pos + 7) / 8;
  return std::vector<uint8_t>(payload + 2, payload + 2 + bytes);
}

/* The access units of a LOAS stream through the library's demultiplexer. */
std::vector<std::vector<uint8_t>> access_units(const std::vector<std::vector<uint8_t>>& frames) {
  std::vector<std::vector<uint8_t>> aus;
  AacLatmConfig cfg = {};
  for (const std::vector<uint8_t>& f : frames) {
    const uint8_t* payload = f.data() + AAC_LOAS_HEADER_BYTES;
    const int size = (int)f.size() - AAC_LOAS_HEADER_BYTES;
    std::vector<uint8_t> out((size_t)size);
    AacLatmPayload p[AAC_LATM_MAX_SUBFRAMES];
    int used = 0;
    const int n = aac_latm_read_mux_element(payload, size, 1, &cfg, out.data(), size, p,
                                            AAC_LATM_MAX_SUBFRAMES, &used);
    CHECK(n == 1, "one access unit per element, got %d", n);
    if (n == 1) {
      aus.emplace_back(out.begin() + p[0].offset, out.begin() + p[0].offset + p[0].size);
    }
  }
  return aus;
}

struct Stream {
  std::string name;
  std::vector<uint8_t> loas;
  std::vector<std::vector<uint8_t>> frames;
  std::vector<uint8_t> asc;
  AacAsc parsed;
};

Stream load_stream(const char* name) {
  Stream s;
  s.name = name;
  s.loas = read_file(data_path(name));
  CHECK(!s.loas.empty(), "%s: stream present", name);
  s.frames = split_loas(s.loas);
  if (!s.frames.empty()) {
    s.asc = asc_of(s.frames[0]);
    CHECK(aac_asc_parse(s.asc.data(), (int)s.asc.size(), &s.parsed) == AAC_OK, "%s: asc parses",
          name);
  }
  return s;
}

void write_escaped(AacBitWriter* w, unsigned v, int b1, int b2, int b3) {
  const unsigned max1 = (1u << b1) - 1u;
  const unsigned max2 = (1u << b2) - 1u;
  if (v < max1) {
    aac_bitwriter_write(w, v, b1);
    return;
  }
  aac_bitwriter_write(w, max1, b1);
  v -= max1;
  if (v < max2 || b3 == 0) {
    aac_bitwriter_write(w, v, b2);
    return;
  }
  aac_bitwriter_write(w, max2, b2);
  aac_bitwriter_write(w, v - max2, b3);
}

/* ── escapedValue ──────────────────────────────────────────────── */

void test_escaped_value() {
  struct Case {
    unsigned value;
    int b1, b2, b3;
    int bits;
  };
  const Case cases[] = {
      {0, 4, 8, 16, 4},
      {14, 4, 8, 16, 4},
      {15, 4, 8, 16, 12},
      {269, 4, 8, 16, 12},
      {270, 4, 8, 16, 28},
      {270 + 65535, 4, 8, 16, 28},
      {254, 8, 16, 0, 8},
      {255, 8, 16, 0, 24},
      {255 + 65535, 8, 16, 0, 24},
      {2, 2, 4, 0, 2},
      {3, 2, 4, 0, 6},
      {3 + 15, 2, 4, 0, 6},
      {14, 4, 4, 8, 4},
      {15, 4, 4, 8, 8},
      {29, 4, 4, 8, 8},
      {30, 4, 4, 8, 16},
      {30 + 255, 4, 4, 8, 16},
      {65534, 16, 16, 0, 16},
      {65535, 16, 16, 0, 32},
      {65535 + 65535, 16, 16, 0, 32},
  };
  for (const Case& c : cases) {
    uint8_t buf[8] = {};
    AacBitWriter w;
    aac_bitwriter_init(&w, buf, sizeof(buf));
    write_escaped(&w, c.value, c.b1, c.b2, c.b3);
    CHECK(aac_bitwriter_bits_written(&w) == c.bits, "escaped %u (%d,%d,%d) writes %d bits", c.value,
          c.b1, c.b2, c.b3, aac_bitwriter_bits_written(&w));
    AacBitReader r;
    aac_bitreader_init(&r, buf, sizeof(buf));
    const unsigned v = aac_usac_escaped_value(&r, c.b1, c.b2, c.b3);
    CHECK(v == c.value && r.pos == c.bits, "escaped (%d,%d,%d): %u read as %u after %d bits", c.b1,
          c.b2, c.b3, c.value, v, r.pos);
  }
  /* The third field is not read when the second does not saturate, and
   * a zero third width stops at the second. */
  const uint8_t ff[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
  AacBitReader r;
  aac_bitreader_init(&r, ff, sizeof(ff));
  CHECK(aac_usac_escaped_value(&r, 8, 16, 0) == 255u + 65535u && r.pos == 24, "no third field");
  aac_bitreader_init(&r, ff, 2);
  aac_usac_escaped_value(&r, 4, 8, 16);
  CHECK(r.overread, "escape past the end sets overread");
}

/* ── the kit configurations ────────────────────────────────────── */

void check_common(const Stream& s, int rate, int csfl, int frame, int out, int ratio, int cci,
                  int channels, int drc_config_len, uint8_t loudness_byte3) {
  const AacAsc& a = s.parsed;
  const AacUsacConfig& c = a.usac;
  CHECK(a.aot == AAC_AOT_USAC, "%s: aot %d", s.name.c_str(), a.aot);
  CHECK(a.sample_rate == rate, "%s: asc rate %d", s.name.c_str(), a.sample_rate);
  CHECK(a.channel_config == cci, "%s: asc channel config %d", s.name.c_str(), a.channel_config);
  CHECK(a.frame_length == frame, "%s: asc frame length %d", s.name.c_str(), a.frame_length);
  CHECK(c.sample_rate == rate, "%s: usac rate %d", s.name.c_str(), c.sample_rate);
  CHECK(c.core_sbr_frame_length_index == csfl, "%s: csfl %d", s.name.c_str(),
        c.core_sbr_frame_length_index);
  CHECK(c.frame_length == frame && c.output_frame_length == out, "%s: frame %d out %d",
        s.name.c_str(), c.frame_length, c.output_frame_length);
  CHECK(c.sbr_ratio_index == ratio, "%s: sbr ratio %d", s.name.c_str(), c.sbr_ratio_index);
  CHECK(c.core_sample_rate == rate * frame / out, "%s: core rate %d", s.name.c_str(),
        c.core_sample_rate);
  CHECK(c.channel_config_index == cci && c.num_channels == channels, "%s: cci %d channels %d",
        s.name.c_str(), c.channel_config_index, c.num_channels);
  CHECK(c.num_elements == 4, "%s: %d elements", s.name.c_str(), c.num_elements);
  /* The FDK layout: pre-roll, DRC, the channel element, fill. */
  CHECK(c.elem[0].type == AAC_USAC_EXT && c.elem[0].ext_type == AAC_USAC_EXT_AUDIOPREROLL &&
            c.elem[0].ext_default_length == 0 && c.elem[0].ext_payload_frag == 0 &&
            c.elem[0].ext_config_len == 0,
        "%s: element 0 is the pre-roll", s.name.c_str());
  CHECK(c.elem[1].type == AAC_USAC_EXT && c.elem[1].ext_type == AAC_USAC_EXT_UNI_DRC &&
            c.elem[1].ext_default_length == 2 && c.elem[1].ext_payload_frag == 0 &&
            c.elem[1].ext_config_len == drc_config_len,
        "%s: element 1 is UNI_DRC with %d config bytes (default length %d)", s.name.c_str(),
        c.elem[1].ext_config_len, c.elem[1].ext_default_length);
  CHECK(c.elem[3].type == AAC_USAC_EXT && c.elem[3].ext_type == AAC_USAC_EXT_FILL &&
            c.elem[3].ext_default_length == 0,
        "%s: element 3 is fill", s.name.c_str());
  CHECK(c.num_config_exts == 2, "%s: %d config extensions", s.name.c_str(), c.num_config_exts);
  CHECK(c.config_ext[0].type == AAC_USAC_CFG_EXT_STREAM_ID && c.config_ext[0].len == 2 &&
            c.stream_id == 0,
        "%s: stream id extension", s.name.c_str());
  CHECK(c.config_ext[1].type == AAC_USAC_CFG_EXT_LOUDNESS_INFO && c.config_ext[1].len == 8 &&
            c.config_ext[1].data[0] == 0x00 && c.config_ext[1].data[1] == 0x10 &&
            c.config_ext[1].data[2] == 0x00 && c.config_ext[1].data[3] == loudness_byte3,
        "%s: loudness info extension", s.name.c_str());
  CHECK(c.raw_bits == 8 * (int)s.asc.size() - 19 - 7 || c.raw_bits > 8 * ((int)s.asc.size() - 4),
        "%s: raw bits %d for %zu asc bytes", s.name.c_str(), c.raw_bits, s.asc.size());
  /* Element 2 is the channel element; whatever its type, no time warping. */
  CHECK(c.elem[2].tw_mdct == 0, "%s: no tw_mdct", s.name.c_str());
}

void check_sbr(const Stream& s, const AacUsacSbrConfig& sbr, int start, int stop, int extra1,
               int extra2) {
  CHECK(sbr.harmonic_sbr == 0 && sbr.inter_tes == 0 && sbr.pvc == 0, "%s: plain eSBR tools",
        s.name.c_str());
  CHECK(sbr.dflt.start_freq == start && sbr.dflt.stop_freq == stop, "%s: sbr start %d stop %d",
        s.name.c_str(), sbr.dflt.start_freq, sbr.dflt.stop_freq);
  CHECK(sbr.dflt.header_extra1 == extra1 && sbr.dflt.header_extra2 == extra2, "%s: sbr extra %d %d",
        s.name.c_str(), sbr.dflt.header_extra1, sbr.dflt.header_extra2);
  if (!extra2) {
    CHECK(sbr.dflt.limiter_bands == 2 && sbr.dflt.limiter_gains == 2 &&
              sbr.dflt.interpol_freq == 1 && sbr.dflt.smoothing_mode == 1,
          "%s: sbr extra2 defaults", s.name.c_str());
  }
  if (!extra1) {
    CHECK(sbr.dflt.freq_scale == 2 && sbr.dflt.alter_scale == 1 && sbr.dflt.noise_bands == 2,
          "%s: sbr extra1 defaults", s.name.c_str());
  }
}

void check_mps(const Stream& s, const AacUsacMps212Config& m, int freq_res, int gain, int shape,
               int decorr, int high_rate, int phase, int residual_bands) {
  CHECK(m.freq_res == freq_res && m.fixed_gain_dmx == gain && m.temp_shape_config == shape &&
            m.decorr_config == decorr && m.high_rate_mode == high_rate && m.phase_coding == phase &&
            m.ott_bands_phase_present == 0,
        "%s: mps212 config %d %d %d %d %d %d", s.name.c_str(), m.freq_res, m.fixed_gain_dmx,
        m.temp_shape_config, m.decorr_config, m.high_rate_mode, m.phase_coding);
  CHECK(m.residual_coding == (residual_bands >= 0), "%s: residual coding %d", s.name.c_str(),
        m.residual_coding);
  if (residual_bands >= 0) {
    CHECK(m.residual_bands == residual_bands && m.pseudo_lr == 0, "%s: residual bands %d",
          s.name.c_str(), m.residual_bands);
  }
}

void test_kit_configs(const std::vector<Stream>& streams) {
  for (const Stream& s : streams) {
    const AacUsacConfig& c = s.parsed.usac;
    const AacUsacElementConfig& e = c.elem[2];
    if (s.name == "usac_music_48000_16000.loas") {
      check_common(s, 48000, 4, 1024, 4096, AAC_USAC_SBR_4_1, 2, 2, 51, 0x4b);
      CHECK(e.type == AAC_USAC_CPE && e.noise_filling == 1 && e.stereo_config_index == 1, "%s: cpe",
            s.name.c_str());
      check_sbr(s, e.sbr, 11, 11, 1, 0);
      check_mps(s, e.mps, 4, 3, 3, 0, 0, 1, -1);
      CHECK((int)s.asc.size() == 79, "%s: asc %zu bytes", s.name.c_str(), s.asc.size());
      CHECK(s.frames.size() == 23, "%s: %zu frames", s.name.c_str(), s.frames.size());
    } else if (s.name == "usac_music_48000_24000.loas") {
      check_common(s, 48000, 2, 1024, 2048, AAC_USAC_SBR_2_1, 2, 2, 51, 0x4b);
      CHECK(e.type == AAC_USAC_CPE && e.noise_filling == 1 && e.stereo_config_index == 1, "%s: cpe",
            s.name.c_str());
      check_sbr(s, e.sbr, 10, 10, 0, 0);
      check_mps(s, e.mps, 2, 3, 3, 0, 1, 1, -1);
      CHECK(s.frames.size() == 47, "%s: %zu frames", s.name.c_str(), s.frames.size());
    } else if (s.name == "usac_music_48000_48000.loas") {
      check_common(s, 48000, 3, 768, 2048, AAC_USAC_SBR_8_3, 2, 2, 51, 0x4b);
      CHECK(e.type == AAC_USAC_CPE && e.noise_filling == 0 && e.stereo_config_index == 3, "%s: cpe",
            s.name.c_str());
      check_sbr(s, e.sbr, 12, 9, 1, 0);
      check_mps(s, e.mps, 1, 4, 0, 0, 1, 1, 20);
      CHECK(s.frames.size() == 47, "%s: %zu frames", s.name.c_str(), s.frames.size());
    } else if (s.name == "usac_music_48000_96000.loas") {
      check_common(s, 48000, 1, 1024, 1024, AAC_USAC_SBR_NONE, 2, 2, 51, 0x4b);
      CHECK(e.type == AAC_USAC_CPE && e.noise_filling == 0 && e.stereo_config_index == 0, "%s: cpe",
            s.name.c_str());
      CHECK(s.frames.size() == 94, "%s: %zu frames", s.name.c_str(), s.frames.size());
    } else if (s.name == "usac_speech_48000_16000.loas") {
      check_common(s, 48000, 4, 1024, 4096, AAC_USAC_SBR_4_1, 1, 1, 43, 0x4c);
      CHECK(e.type == AAC_USAC_SCE && e.noise_filling == 1, "%s: sce", s.name.c_str());
      check_sbr(s, e.sbr, 6, 8, 1, 0);
      CHECK(s.frames.size() == 23, "%s: %zu frames", s.name.c_str(), s.frames.size());
    } else if (s.name == "usac_speech_48000_64000.loas") {
      check_common(s, 48000, 1, 1024, 1024, AAC_USAC_SBR_NONE, 1, 1, 43, 0x4c);
      CHECK(e.type == AAC_USAC_SCE && e.noise_filling == 0, "%s: sce", s.name.c_str());
      CHECK((int)s.asc.size() == 67, "%s: asc %zu bytes", s.name.c_str(), s.asc.size());
      CHECK(s.frames.size() == 94, "%s: %zu frames", s.name.c_str(), s.frames.size());
    } else {
      CHECK(false, "unexpected stream %s", s.name.c_str());
    }
  }
}

/* ── LATM demultiplexing and the access units ──────────────────── */

void test_latm_demux(const std::vector<Stream>& streams) {
  struct Expect {
    const char* name;
    int count;
    int first[8];
    int total;
  };
  const Expect expect[] = {
      {"usac_music_48000_16000.loas", 23, {484, 165, 166, 201, 168, 164, 181, 202}, 5216},
      {"usac_music_48000_24000.loas", 47, {520, 108, 119, 146, 169, 149, 119, 119}, 7166},
      {"usac_music_48000_48000.loas", 47, {882, 247, 238, 251, 342, 258, 244, 254}, 13743},
      {"usac_music_48000_96000.loas", 94, {599, 281, 256, 247, 248, 263, 259, 245}, 24072},
      {"usac_speech_48000_16000.loas", 23, {474, 148, 166, 165, 225, 263, 221, 205}, 5291},
      {"usac_speech_48000_64000.loas", 94, {476, 209, 184, 180, 174, 199, 6, 143}, 16751},
  };
  for (const Expect& x : expect) {
    const Stream* s = nullptr;
    for (const Stream& t : streams) {
      if (t.name == x.name) {
        s = &t;
      }
    }
    if (!s) {
      CHECK(false, "%s missing", x.name);
      continue;
    }
    const std::vector<std::vector<uint8_t>> aus = access_units(s->frames);
    CHECK((int)aus.size() == x.count, "%s: %zu access units", x.name, aus.size());
    int total = 0;
    for (size_t i = 0; i < aus.size(); i++) {
      total += (int)aus[i].size();
      if (i < 8) {
        CHECK((int)aus[i].size() == x.first[i], "%s: au %zu has %zu bytes", x.name, i,
              aus[i].size());
      }
    }
    CHECK(total == x.total, "%s: %d bytes of access units", x.name, total);
    /* Every access unit is an independent frame in this short stream's
     * first position only: the first bit of the first one is set. */
    CHECK(!aus.empty() && (aus[0][0] & 0x80), "%s: IPF is independent", x.name);
  }
}

/* ── the decoder over the module stubs ─────────────────────────── */

const Stream* find(const std::vector<Stream>& streams, const char* name) {
  for (const Stream& s : streams) {
    if (s.name == name) {
      return &s;
    }
  }
  return nullptr;
}

void test_raw_au_path(const std::vector<Stream>& streams) {
  const Stream* s = find(streams, "usac_music_48000_16000.loas");
  const Stream* mono = find(streams, "usac_speech_48000_64000.loas");
  if (!s || !mono) {
    return;
  }
  const std::vector<std::vector<uint8_t>> aus = access_units(s->frames);
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  CHECK(dec != nullptr, "decoder");
  CHECK(aac_decoder_object_type(dec) == 0, "no object type before a configuration");
  CHECK(aac_decoder_loudness(dec, nullptr, nullptr) == AAC_ERR_STATE, "no loudness yet");
  CHECK(aac_decoder_set_drc(dec, 1, 100, 100, -1, 0) == AAC_OK, "MPEG-4 DRC on a fresh decoder");
  CHECK(aac_decoder_set_config(dec, s->asc.data(), (int)s->asc.size()) == AAC_OK, "set_config");
  CHECK(aac_decoder_object_type(dec) == AAC_AOT_USAC, "object type 42");
  CHECK(aac_decoder_frame_size(dec) == 4096, "frame size %d", aac_decoder_frame_size(dec));
  CHECK(aac_decoder_output_sample_rate(dec) == 48000, "output rate %d",
        aac_decoder_output_sample_rate(dec));
  CHECK(aac_decoder_channels(dec) == 2, "channels %d", aac_decoder_channels(dec));
  CHECK(aac_decoder_set_drc(dec, 1, 100, 100, -1, 0) == AAC_ERR_UNSUPPORTED,
        "MPEG-4 DRC refused on USAC");
  CHECK(aac_decoder_set_frame_length(dec, 960) == AAC_ERR_STATE, "frame length fixed by USAC");
  int program = 0;
  int gain = 1;
  /* the stream declares -14.25 LUFS; the default request normalises to -24 */
  CHECK(aac_decoder_loudness(dec, &program, &gain) == AAC_OK && program == -1425 && gain == -975,
        "loudness readback %d %d", program, gain);
  CHECK(aac_decoder_set_loudness(dec, 1, -2300) == AAC_OK, "set loudness");
  CHECK(aac_decoder_set_loudness(dec, 1, -900) == AAC_ERR_INVALID_ARG, "loudness range");
  CHECK(aac_decoder_set_drc_effect(dec, AAC_DRC_EFFECT_NIGHT, 1, 100, 50) == AAC_OK, "effect");
  CHECK(aac_decoder_set_drc_effect(dec, (AacDrcEffect)7, 0, 100, 100) == AAC_ERR_INVALID_ARG,
        "effect range");
  CHECK(aac_decoder_set_drc_effect(dec, AAC_DRC_EFFECT_NONE, 0, 101, 100) == AAC_ERR_INVALID_ARG,
        "boost range");
  /* With the core module stubbed the first access unit (an IPF) reaches
   * the stub in its first pre-roll frame: unsupported, not damaged. */
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 2);
  CHECK(aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF) == AAC_OK, "concealment off");
  CHECK(aac_decoder_decode(dec, aus[0].data(), (int)aus[0].size(), pcm.data(), (int)pcm.size()) ==
            AAC_ERR_UNSUPPORTED,
        "raw IPF reaches the core stub");
  /* With concealment on, the unsupported frame is concealed to a full
   * frame of the configured size. */
  CHECK(aac_decoder_set_concealment(dec, AAC_CONCEAL_INTERPOLATE) == AAC_OK, "concealment on");
  const int got =
      aac_decoder_decode(dec, aus[1].data(), (int)aus[1].size(), pcm.data(), (int)pcm.size());
  CHECK(got == 4096, "concealed USAC frame of 4096 samples, got %d", got);
  CHECK(aac_decoder_last_frame_concealed(dec) == 1, "counted as concealed");
  /* A second configuration replaces the first; the first again is accepted. */
  CHECK(aac_decoder_set_config(dec, mono->asc.data(), (int)mono->asc.size()) == AAC_OK,
        "reconfigure");
  CHECK(aac_decoder_frame_size(dec) == 1024 && aac_decoder_channels(dec) == 1,
        "mono FD stream: %d samples %d channels", aac_decoder_frame_size(dec),
        aac_decoder_channels(dec));
  CHECK(aac_decoder_set_config(dec, s->asc.data(), (int)s->asc.size()) == AAC_OK, "back");
  CHECK(aac_decoder_frame_size(dec) == 4096 && aac_decoder_channels(dec) == 2, "stereo again");
  CHECK(aac_decoder_set_config(dec, s->asc.data(), (int)s->asc.size()) == AAC_OK, "same again");
  aac_decoder_destroy(dec);
}

/* An IPF of another stream arriving as a raw access unit reconfigures the
 * decoder from the pre-roll's embedded configuration before its frames
 * are decoded. */
void test_preroll_reconfigures(const std::vector<Stream>& streams) {
  const Stream* stereo = find(streams, "usac_music_48000_16000.loas");
  const Stream* mono = find(streams, "usac_speech_48000_64000.loas");
  if (!stereo || !mono) {
    return;
  }
  const std::vector<std::vector<uint8_t>> aus = access_units(stereo->frames);
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  CHECK(aac_decoder_set_config(dec, mono->asc.data(), (int)mono->asc.size()) == AAC_OK,
        "mono config");
  CHECK(aac_decoder_frame_size(dec) == 1024 && aac_decoder_channels(dec) == 1, "mono first");
  aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 2);
  const int ret =
      aac_decoder_decode(dec, aus[0].data(), (int)aus[0].size(), pcm.data(), (int)pcm.size());
  CHECK(ret == AAC_ERR_UNSUPPORTED, "the stereo IPF reaches the core stub: %d", ret);
  CHECK(aac_decoder_frame_size(dec) == 4096 && aac_decoder_channels(dec) == 2,
        "reconfigured by the pre-roll: %d samples %d channels", aac_decoder_frame_size(dec),
        aac_decoder_channels(dec));
  aac_decoder_destroy(dec);
}

void test_loas_path(const std::vector<Stream>& streams) {
  for (const Stream& s : streams) {
    AacDecoderHandle dec = aac_decoder_create(48000, 0);
    aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
    CHECK(aac_decoder_feed(dec, s.loas.data(), (int)s.loas.size()) == (int)s.loas.size(),
          "%s: feed", s.name.c_str());
    std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 2);
    const int ret = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
    CHECK(ret == AAC_ERR_UNSUPPORTED, "%s: LOAS frame reaches the core stub: %d", s.name.c_str(),
          ret);
    CHECK(aac_decoder_object_type(dec) == AAC_AOT_USAC, "%s: configured from LATM", s.name.c_str());
    CHECK(aac_decoder_frame_size(dec) == s.parsed.usac.output_frame_length, "%s: frame size %d",
          s.name.c_str(), aac_decoder_frame_size(dec));
    CHECK(aac_decoder_channels(dec) == s.parsed.usac.num_channels, "%s: channels %d",
          s.name.c_str(), aac_decoder_channels(dec));
    /* Concealment on: every remaining frame comes out as a concealed
     * frame of the configured size. */
    aac_decoder_set_concealment(dec, AAC_CONCEAL_INTERPOLATE);
    int frames = 0;
    for (;;) {
      const int got = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
      if (got <= 0) {
        CHECK(got == 0, "%s: error %d while concealing", s.name.c_str(), got);
        break;
      }
      CHECK(got == s.parsed.usac.output_frame_length, "%s: concealed frame of %d", s.name.c_str(),
            got);
      frames++;
    }
    CHECK(frames == (int)s.frames.size() - 1, "%s: %d concealed frames", s.name.c_str(), frames);
    CHECK(aac_decoder_concealed_frames(dec) == frames, "%s: concealed count", s.name.c_str());
    aac_decoder_destroy(dec);
  }
}

/* ── the audio pre-roll payload ────────────────────────────────── */

/* The pre-roll payload of an IPF: after usacIndependencyFlag, the
 * present flag, the default-length flag and the escaped length. */
std::vector<uint8_t> preroll_payload(const std::vector<uint8_t>& au, int* ext_len) {
  AacBitReader r;
  aac_bitreader_init(&r, au.data(), (int)au.size());
  CHECK(aac_bitreader_read(&r, 1) == 1, "IPF independent");
  CHECK(aac_bitreader_read(&r, 1) == 1, "pre-roll present");
  CHECK(aac_bitreader_read(&r, 1) == 0, "explicit length");
  const int len = aac_usac_ext_payload_length(&r);
  *ext_len = len;
  std::vector<uint8_t> payload((size_t)len);
  for (int i = 0; i < len; i++) {
    payload[(size_t)i] = (uint8_t)aac_bitreader_read(&r, 8);
  }
  CHECK(!r.overread, "payload within the access unit");
  return payload;
}

void test_preroll_parse(const std::vector<Stream>& streams) {
  struct Expect {
    const char* name;
    int ext_len, config_len, frames, au0, au1;
  };
  const Expect expect[] = {
      {"usac_music_48000_16000.loas", 316, 77, 2, 52, 180},
      {"usac_music_48000_48000.loas", 613, 78, 2, 271, 257},
      {"usac_music_48000_96000.loas", 285, 73, 1, 207, 0},
      {"usac_speech_48000_64000.loas", 246, 65, 1, 176, 0},
  };
  static AacUsacPreroll p; /* large: the access unit buffer */
  for (const Expect& x : expect) {
    const Stream* s = find(streams, x.name);
    if (!s) {
      continue;
    }
    const std::vector<std::vector<uint8_t>> aus = access_units(s->frames);
    int ext_len = 0;
    const std::vector<uint8_t> payload = preroll_payload(aus[0], &ext_len);
    CHECK(ext_len == x.ext_len, "%s: pre-roll extension of %d bytes", x.name, ext_len);
    const int ret = aac_usac_preroll_parse(payload.data(), (int)payload.size(), &p);
    CHECK(ret == AAC_OK, "%s: pre-roll parses (%d)", x.name, ret);
    if (ret) {
      continue;
    }
    CHECK(p.has_config && aac_usac_config_equal(&p.cfg, &s->parsed.usac),
          "%s: embedded config equals the stream's", x.name);
    CHECK((p.cfg.raw_bits + 7) / 8 <= x.config_len && (p.cfg.raw_bits + 7) / 8 >= x.config_len - 1,
          "%s: config of %d bits in %d bytes", x.name, p.cfg.raw_bits, x.config_len);
    CHECK(p.apply_crossfade == 0, "%s: no crossfade", x.name);
    CHECK(p.num_frames == x.frames, "%s: %d pre-roll frames", x.name, p.num_frames);
    CHECK(p.au_len[0] == x.au0, "%s: first pre-roll au %d bytes", x.name, p.au_len[0]);
    if (x.frames > 1) {
      CHECK(p.au_len[1] == x.au1 && p.au_offset[1] == x.au0, "%s: second pre-roll au %d bytes",
            x.name, p.au_len[1]);
    }
    /* The pre-roll access units are frames with the pre-roll element
     * absent (their first bits: independent, not present). */
    for (int i = 0; i < p.num_frames; i++) {
      const uint8_t first = p.data[p.au_offset[i]];
      CHECK((first & 0xC0) == 0x80, "%s: pre-roll au %d starts independent without pre-roll",
            x.name, i);
    }
  }
  /* Malformed payloads. */
  const uint8_t bad_frames[] = {0x01, 0x00}; /* configLen 0, one frame, auLen cut short */
  CHECK(aac_usac_preroll_parse(bad_frames, sizeof(bad_frames), &p) == AAC_ERR_DECODE,
        "truncated pre-roll");
  const uint8_t too_many[] = {0x0F, 0xFF, 0xFF}; /* configLen 0, 3 + 15 frames */
  CHECK(aac_usac_preroll_parse(too_many, sizeof(too_many), &p) == AAC_ERR_UNSUPPORTED,
        "more pre-roll frames than the decoder holds");
  const uint8_t none[] = {0x00}; /* configLen 0, no crossfade, 0 frames */
  CHECK(aac_usac_preroll_parse(none, sizeof(none), &p) == AAC_OK && !p.has_config &&
            p.num_frames == 0,
        "empty pre-roll");
}

/* ── equality and layouts ──────────────────────────────────────── */

void test_equality(const std::vector<Stream>& streams) {
  if (streams.size() < 2) {
    return;
  }
  AacAsc a;
  AacAsc b;
  CHECK(aac_asc_parse(streams[0].asc.data(), (int)streams[0].asc.size(), &a) == AAC_OK, "a");
  CHECK(aac_asc_parse(streams[0].asc.data(), (int)streams[0].asc.size(), &b) == AAC_OK, "b");
  CHECK(aac_usac_config_equal(&a.usac, &b.usac), "same bits are equal");
  CHECK(!aac_usac_config_equal(&a.usac, &streams[1].parsed.usac), "different streams differ");
  b.usac.raw[b.usac.raw_bits / 16] ^= 0x01;
  CHECK(!aac_usac_config_equal(&a.usac, &b.usac), "one flipped bit differs");
  b = a;
  b.usac.raw_bits--;
  CHECK(!aac_usac_config_equal(&a.usac, &b.usac), "a different length differs");
}

AacUsacConfig config_of(int cci, int channels, const int* types, int n) {
  AacUsacConfig c = {};
  c.channel_config_index = cci;
  c.num_channels = channels;
  c.num_elements = n;
  for (int i = 0; i < n; i++) {
    c.elem[i].type = types[i];
  }
  return c;
}

void test_layouts() {
  const int sce[] = {AAC_USAC_SCE};
  const int cpe[] = {AAC_USAC_EXT, AAC_USAC_CPE, AAC_USAC_EXT};
  const int c6[] = {AAC_USAC_SCE, AAC_USAC_CPE, AAC_USAC_CPE, AAC_USAC_LFE};
  const int c7[] = {AAC_USAC_SCE, AAC_USAC_CPE, AAC_USAC_CPE, AAC_USAC_CPE, AAC_USAC_LFE};
  const int c11[] = {AAC_USAC_SCE, AAC_USAC_CPE, AAC_USAC_CPE, AAC_USAC_SCE, AAC_USAC_LFE};
  struct Case {
    int cci, channels;
    const int* types;
    int n;
    int ret;
    int speakers[8];
  };
  const Case cases[] = {
      {1, 1, sce, 1, AAC_OK, {AAC_SPK_FC}},
      {2, 2, cpe, 3, AAC_OK, {AAC_SPK_FL, AAC_SPK_FR}},
      {6,
       6,
       c6,
       4,
       AAC_OK,
       {AAC_SPK_FL, AAC_SPK_FR, AAC_SPK_FC, AAC_SPK_LFE, AAC_SPK_BL, AAC_SPK_BR}},
      {7,
       8,
       c7,
       5,
       AAC_OK,
       {AAC_SPK_FL, AAC_SPK_FR, AAC_SPK_FC, AAC_SPK_LFE, AAC_SPK_BL, AAC_SPK_BR, AAC_SPK_FLC,
        AAC_SPK_FRC}},
      {11,
       7,
       c11,
       5,
       AAC_OK,
       {AAC_SPK_FL, AAC_SPK_FR, AAC_SPK_FC, AAC_SPK_LFE, AAC_SPK_BL, AAC_SPK_BR, AAC_SPK_BC}},
      /* Two surround pairs: the layouts place the second in slots 4 and 5
       * and the first in 6 and 7, as the decoder does for AAC. */
      {12,
       8,
       c7,
       5,
       AAC_OK,
       {AAC_SPK_FL, AAC_SPK_FR, AAC_SPK_FC, AAC_SPK_LFE, AAC_SPK_BL, AAC_SPK_BR, AAC_SPK_SL,
        AAC_SPK_SR}},
      {2, 2, sce, 1, AAC_ERR_DECODE, {}},      /* a lone SCE is not configuration 2 */
      {8, 2, cpe, 3, AAC_ERR_UNSUPPORTED, {}}, /* configuration 8 has no layout */
      {13, 24, c7, 5, AAC_ERR_UNSUPPORTED, {}},
  };
  for (const Case& k : cases) {
    const AacUsacConfig c = config_of(k.cci, k.channels, k.types, k.n);
    AacLayout l = {};
    const int ret = aac_usac_config_layout(&c, &l);
    CHECK(ret == k.ret, "cci %d: layout %d", k.cci, ret);
    if (ret) {
      continue;
    }
    CHECK(l.num_channels == k.channels && l.channel_config == k.cci, "cci %d: %d channels", k.cci,
          l.num_channels);
    for (int i = 0; i < k.channels; i++) {
      CHECK(l.slot_speaker[i] == k.speakers[i], "cci %d: slot %d speaker %d", k.cci, i,
            l.slot_speaker[i]);
    }
  }
  /* channelConfigurationIndex 0 with bsOutputChannelPos. */
  {
    AacUsacConfig c = config_of(0, 2, cpe, 3);
    c.channel_pos[0] = 0;
    c.channel_pos[1] = 1;
    AacLayout l = {};
    CHECK(aac_usac_config_layout(&c, &l) == AAC_OK && l.num_channels == 2 &&
              l.slot_speaker[0] == AAC_SPK_FL && l.slot_speaker[1] == AAC_SPK_FR,
          "positions L R");
    c.channel_pos[0] = 1;
    c.channel_pos[1] = 0;
    CHECK(aac_usac_config_layout(&c, &l) == AAC_ERR_UNSUPPORTED, "R L is not a pair");
    c.channel_pos[0] = 13;
    c.channel_pos[1] = 14;
    CHECK(aac_usac_config_layout(&c, &l) == AAC_OK && l.slot_speaker[0] == AAC_SPK_SL &&
              l.slot_speaker[1] == AAC_SPK_SR,
          "a side pair alone");
    c.channel_pos[0] = 25;
    c.channel_pos[1] = 26;
    CHECK(aac_usac_config_layout(&c, &l) == AAC_ERR_UNSUPPORTED, "positions outside the layouts");
  }
  {
    const int types[] = {AAC_USAC_SCE, AAC_USAC_CPE, AAC_USAC_EXT, AAC_USAC_CPE, AAC_USAC_LFE};
    AacUsacConfig c = config_of(0, 6, types, 5);
    const int pos[6] = {2, 0, 1, 4, 5, 3};
    for (int i = 0; i < 6; i++) {
      c.channel_pos[i] = pos[i];
    }
    AacLayout l = {};
    CHECK(aac_usac_config_layout(&c, &l) == AAC_OK && l.num_channels == 6, "5.1 by positions");
    const int want[6] = {AAC_SPK_FL, AAC_SPK_FR, AAC_SPK_FC, AAC_SPK_LFE, AAC_SPK_BL, AAC_SPK_BR};
    for (int i = 0; i < 6; i++) {
      CHECK(l.slot_speaker[i] == want[i], "5.1 by positions: slot %d is %d", i, l.slot_speaker[i]);
    }
    c.num_channels = 5; /* fewer positions than channels in the elements */
    CHECK(aac_usac_config_layout(&c, &l) == AAC_ERR_DECODE, "positions short of the elements");
    const int tops[] = {AAC_USAC_CPE, AAC_USAC_CPE};
    AacUsacConfig t = config_of(0, 4, tops, 2);
    const int tpos[4] = {0, 1, 17, 18};
    for (int i = 0; i < 4; i++) {
      t.channel_pos[i] = tpos[i];
    }
    CHECK(aac_usac_config_layout(&t, &l) == AAC_OK && l.slot_speaker[2] == AAC_SPK_TFL &&
              l.slot_speaker[3] == AAC_SPK_TFR,
          "a top front pair by positions");
  }
}

/* ── constructed configurations and frames ─────────────────────── */

/* An AudioSpecificConfig for USAC at 48 kHz: the header, then
 * `usac_bits` written by the caller through the writer. */
void write_asc_header(AacBitWriter* w, int cci) {
  aac_bitwriter_write(w, 31, 5); /* audioObjectType escape */
  aac_bitwriter_write(w, AAC_AOT_USAC - 32, 6);
  aac_bitwriter_write(w, 3, 4); /* 48000 */
  aac_bitwriter_write(w, (uint32_t)cci, 4);
  aac_bitwriter_write(w, 3, 5); /* usacSamplingFrequencyIndex 48000 */
  aac_bitwriter_write(w, 1, 3); /* coreSbrFrameLengthIndex: 1024, no SBR */
  aac_bitwriter_write(w, (uint32_t)cci, 5);
}

void test_constructed_configs() {
  uint8_t buf[64];
  AacBitWriter w;
  /* One SCE without tools. */
  aac_bitwriter_init(&w, buf, sizeof(buf));
  write_asc_header(&w, 1);
  write_escaped(&w, 0, 4, 8, 16); /* one element */
  aac_bitwriter_write(&w, AAC_USAC_SCE, 2);
  aac_bitwriter_write(&w, 0, 1); /* tw_mdct */
  aac_bitwriter_write(&w, 0, 1); /* noiseFilling */
  aac_bitwriter_write(&w, 0, 1); /* usacConfigExtensionPresent */
  aac_bitwriter_byte_align(&w);
  const int sce_len = aac_bitwriter_bytes_written(&w);
  AacAsc asc;
  CHECK(aac_asc_parse(buf, sce_len, &asc) == AAC_OK && asc.aot == AAC_AOT_USAC &&
            asc.usac.num_elements == 1 && asc.usac.elem[0].type == AAC_USAC_SCE &&
            asc.usac.num_channels == 1 && asc.usac.output_frame_length == 1024 &&
            asc.usac.raw_bits == 5 + 3 + 5 + 4 + 2 + 1 + 1 + 1,
        "constructed SCE config");
  {
    AacDecoderHandle dec = aac_decoder_create(48000, 0);
    aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
    CHECK(aac_decoder_set_config(dec, buf, sce_len) == AAC_OK, "SCE config applied");
    CHECK(aac_decoder_frame_size(dec) == 1024 && aac_decoder_channels(dec) == 1 &&
              aac_decoder_object_type(dec) == AAC_AOT_USAC,
          "SCE stream parameters");
    const uint8_t au[4] = {0x80, 0x00, 0x00, 0x00}; /* independent frame */
    std::vector<float> pcm(2048);
    CHECK(
        aac_decoder_decode(dec, au, sizeof(au), pcm.data(), (int)pcm.size()) == AAC_ERR_UNSUPPORTED,
        "the walker reaches the core stub for the first element");
    aac_decoder_destroy(dec);
  }
  /* The same with the time-warped MDCT: refused. */
  aac_bitwriter_init(&w, buf, sizeof(buf));
  write_asc_header(&w, 1);
  write_escaped(&w, 0, 4, 8, 16);
  aac_bitwriter_write(&w, AAC_USAC_SCE, 2);
  aac_bitwriter_write(&w, 1, 1); /* tw_mdct */
  aac_bitwriter_write(&w, 0, 1);
  aac_bitwriter_write(&w, 0, 1);
  aac_bitwriter_byte_align(&w);
  CHECK(aac_asc_parse(buf, aac_bitwriter_bytes_written(&w), &asc) == AAC_ERR_UNSUPPORTED,
        "tw_mdct refused");
  /* A CPE announced as configuration 1: the elements do not fit. */
  aac_bitwriter_init(&w, buf, sizeof(buf));
  write_asc_header(&w, 1);
  write_escaped(&w, 0, 4, 8, 16);
  aac_bitwriter_write(&w, AAC_USAC_CPE, 2);
  aac_bitwriter_write(&w, 0, 1);
  aac_bitwriter_write(&w, 0, 1);
  aac_bitwriter_write(&w, 0, 1);
  aac_bitwriter_byte_align(&w);
  {
    const int len = aac_bitwriter_bytes_written(&w);
    CHECK(aac_asc_parse(buf, len, &asc) == AAC_OK, "a CPE under configuration 1 parses");
    AacDecoderHandle dec = aac_decoder_create(48000, 0);
    CHECK(aac_decoder_set_config(dec, buf, len) == AAC_ERR_DECODE, "but has no layout");
    CHECK(aac_decoder_object_type(dec) == 0, "and leaves the decoder unconfigured");
    aac_decoder_destroy(dec);
  }
  /* An SAOC extension element: outside xHE-AAC. */
  aac_bitwriter_init(&w, buf, sizeof(buf));
  write_asc_header(&w, 1);
  write_escaped(&w, 1, 4, 8, 16); /* two elements */
  aac_bitwriter_write(&w, AAC_USAC_SCE, 2);
  aac_bitwriter_write(&w, 0, 2);
  aac_bitwriter_write(&w, AAC_USAC_EXT, 2);
  write_escaped(&w, AAC_USAC_EXT_SAOC, 4, 8, 16);
  write_escaped(&w, 0, 4, 8, 16);
  aac_bitwriter_write(&w, 0, 1);
  aac_bitwriter_write(&w, 0, 1);
  aac_bitwriter_write(&w, 0, 1);
  aac_bitwriter_byte_align(&w);
  CHECK(aac_asc_parse(buf, aac_bitwriter_bytes_written(&w), &asc) == AAC_ERR_UNSUPPORTED,
        "SAOC refused");
  /* A reserved sampling frequency index. */
  aac_bitwriter_init(&w, buf, sizeof(buf));
  aac_bitwriter_write(&w, 31, 5);
  aac_bitwriter_write(&w, AAC_AOT_USAC - 32, 6);
  aac_bitwriter_write(&w, 3, 4);
  aac_bitwriter_write(&w, 1, 4);
  aac_bitwriter_write(&w, 13, 5); /* reserved */
  aac_bitwriter_write(&w, 1, 3);
  aac_bitwriter_write(&w, 1, 5);
  write_escaped(&w, 0, 4, 8, 16);
  aac_bitwriter_write(&w, AAC_USAC_SCE, 2);
  aac_bitwriter_write(&w, 0, 3);
  aac_bitwriter_byte_align(&w);
  CHECK(aac_asc_parse(buf, aac_bitwriter_bytes_written(&w), &asc) == AAC_ERR_DECODE,
        "reserved rate index refused");
  /* The escaped rate and the extra USAC rates. */
  aac_bitwriter_init(&w, buf, sizeof(buf));
  aac_bitwriter_write(&w, 31, 5);
  aac_bitwriter_write(&w, AAC_AOT_USAC - 32, 6);
  aac_bitwriter_write(&w, 15, 4);
  aac_bitwriter_write(&w, 57600, 24);
  aac_bitwriter_write(&w, 1, 4);
  aac_bitwriter_write(&w, 15, 5); /* 57600 */
  aac_bitwriter_write(&w, 1, 3);
  aac_bitwriter_write(&w, 1, 5);
  write_escaped(&w, 0, 4, 8, 16);
  aac_bitwriter_write(&w, AAC_USAC_SCE, 2);
  aac_bitwriter_write(&w, 0, 3);
  aac_bitwriter_byte_align(&w);
  {
    const int len = aac_bitwriter_bytes_written(&w);
    CHECK(aac_asc_parse(buf, len, &asc) == AAC_OK && asc.usac.sample_rate == 57600,
          "57600 Hz index");
    AacDecoderHandle dec = aac_decoder_create(48000, 0);
    CHECK(aac_decoder_set_config(dec, buf, len) == AAC_ERR_UNSUPPORTED, "no tables at 57600 Hz");
    aac_decoder_destroy(dec);
  }
  aac_bitwriter_init(&w, buf, sizeof(buf));
  aac_bitwriter_write(&w, 31, 5);
  aac_bitwriter_write(&w, AAC_AOT_USAC - 32, 6);
  aac_bitwriter_write(&w, 3, 4);
  aac_bitwriter_write(&w, 1, 4);
  aac_bitwriter_write(&w, 31, 5); /* escape */
  aac_bitwriter_write(&w, 48000, 24);
  aac_bitwriter_write(&w, 4, 3); /* 4:1 SBR */
  aac_bitwriter_write(&w, 1, 5);
  write_escaped(&w, 0, 4, 8, 16);
  aac_bitwriter_write(&w, AAC_USAC_SCE, 2);
  aac_bitwriter_write(&w, 0, 1);
  aac_bitwriter_write(&w, 1, 1);
  /* SbrConfig: harmonicSBR, interTes, pvc, start, stop, extra1, extra2 and both extras. */
  aac_bitwriter_write(&w, 1, 1);
  aac_bitwriter_write(&w, 0, 1);
  aac_bitwriter_write(&w, 1, 1);
  aac_bitwriter_write(&w, 7, 4);
  aac_bitwriter_write(&w, 9, 4);
  aac_bitwriter_write(&w, 1, 1);
  aac_bitwriter_write(&w, 1, 1);
  aac_bitwriter_write(&w, 1, 2); /* freq_scale */
  aac_bitwriter_write(&w, 0, 1); /* alter_scale */
  aac_bitwriter_write(&w, 3, 2); /* noise_bands */
  aac_bitwriter_write(&w, 0, 2); /* limiter_bands */
  aac_bitwriter_write(&w, 1, 2); /* limiter_gains */
  aac_bitwriter_write(&w, 0, 1); /* interpol_freq */
  aac_bitwriter_write(&w, 0, 1); /* smoothing_mode */
  aac_bitwriter_write(&w, 0, 1); /* no config extension */
  aac_bitwriter_byte_align(&w);
  CHECK(aac_asc_parse(buf, aac_bitwriter_bytes_written(&w), &asc) == AAC_OK, "escaped rate parses");
  CHECK(asc.usac.sample_rate == 48000 && asc.usac.core_sample_rate == 12000 &&
            asc.usac.output_frame_length == 4096 && asc.usac.sbr_ratio_index == AAC_USAC_SBR_4_1,
        "escaped rate and 4:1");
  const AacUsacSbrConfig& sbr = asc.usac.elem[0].sbr;
  CHECK(sbr.harmonic_sbr == 1 && sbr.inter_tes == 0 && sbr.pvc == 1 && sbr.dflt.start_freq == 7 &&
            sbr.dflt.stop_freq == 9 && sbr.dflt.freq_scale == 1 && sbr.dflt.alter_scale == 0 &&
            sbr.dflt.noise_bands == 3 && sbr.dflt.limiter_bands == 0 &&
            sbr.dflt.limiter_gains == 1 && sbr.dflt.interpol_freq == 0 &&
            sbr.dflt.smoothing_mode == 0,
        "sbr header extras");
}

/* A frame of extension elements alone walks to its end: fill with an
 * explicit length, UNI_DRC with its default length, an unknown type
 * absent.  The state is assembled by hand around a configured decoder. */
void test_ext_only_walk() {
  AacDecoderState* s = aac_decoder_state_create(48000, 0, aac_dsp_global());
  CHECK(s != nullptr, "state");
  CHECK(aac_decoder_set_channel_config(s, 1) == AAC_OK, "layout");
  AacUsacState* u = aac_usac_state_create();
  CHECK(u != nullptr, "usac state");
  u->cfg.sample_rate = 48000;
  u->cfg.frame_length = 1024;
  u->cfg.output_frame_length = 1024;
  u->cfg.num_channels = 1;
  u->cfg.num_elements = 3;
  u->num_elements = 3;
  const int types[3] = {AAC_USAC_EXT_FILL, AAC_USAC_EXT_UNI_DRC, 9};
  for (int i = 0; i < 3; i++) {
    u->cfg.elem[i].type = AAC_USAC_EXT;
    u->cfg.elem[i].ext_type = types[i];
    u->elem[i].type = AAC_USAC_EXT;
    u->elem[i].layout_index = -1;
  }
  u->cfg.elem[1].ext_default_length = 2;
  u->unidrc_elem = 1;
  s->usac = u;
  CHECK(aac_decoder_set_object_type(s, AAC_AOT_USAC) == AAC_OK, "object type");
  uint8_t buf[16];
  AacBitWriter w;
  aac_bitwriter_init(&w, buf, sizeof(buf));
  aac_bitwriter_write(&w, 1, 1); /* usacIndependencyFlag */
  aac_bitwriter_write(&w, 1, 1); /* fill present */
  aac_bitwriter_write(&w, 0, 1); /* explicit length */
  write_escaped(&w, 3, 8, 16, 0);
  aac_bitwriter_write(&w, 0xA5A5A5, 24);
  aac_bitwriter_write(&w, 1, 1); /* DRC present */
  aac_bitwriter_write(&w, 1, 1); /* default length 2 */
  aac_bitwriter_write(&w, 0x1234, 16);
  aac_bitwriter_write(&w, 0, 1); /* unknown type absent */
  const int bits = aac_bitwriter_bits_written(&w);
  aac_bitwriter_byte_align(&w);
  AacBitReader r;
  aac_bitreader_init(&r, buf, aac_bitwriter_bytes_written(&w));
  CHECK(aac_usac_decode_frame(s, &r) == AAC_OK, "extension-only frame walks to the end");
  CHECK(r.pos == bits, "every payload consumed: at bit %d of %d", r.pos, bits);
  CHECK(u->warm == 1 && u->indep == 1, "the frame warmed the state");
  /* The default length flag without a configured default is a damaged frame. */
  u->cfg.elem[1].ext_default_length = 0;
  aac_bitreader_init(&r, buf, aac_bitwriter_bytes_written(&w));
  CHECK(aac_usac_decode_frame(s, &r) == AAC_ERR_DECODE, "default length without a default");
  /* A payload past the end of the frame. */
  u->cfg.elem[1].ext_default_length = 2;
  aac_bitreader_init(&r, buf, 4);
  CHECK(aac_usac_decode_frame(s, &r) == AAC_ERR_DECODE, "truncated frame");
  /* Fragmented payloads are outside the decoder. */
  u->cfg.elem[0].ext_payload_frag = 1;
  aac_bitreader_init(&r, buf, aac_bitwriter_bytes_written(&w));
  CHECK(aac_usac_decode_frame(s, &r) == AAC_ERR_UNSUPPORTED, "fragmented payload refused");
  u->cfg.elem[0].ext_payload_frag = 0;
  /* Concealment of a frame of extension elements produces the (silent) frame. */
  CHECK(aac_usac_conceal_frame(s) == AAC_OK, "concealment walks the elements");
  aac_decoder_state_destroy(s); /* destroys the USAC state with it */
}

/* ── fuzzing ───────────────────────────────────────────────────── */

void test_fuzz(const std::vector<Stream>& streams) {
  int parsed = 0;
  int refused = 0;
  for (const Stream& s : streams) {
    std::vector<uint8_t> asc = s.asc;
    for (size_t bit = 0; bit < 8 * asc.size(); bit++) {
      asc[bit / 8] ^= (uint8_t)(0x80 >> (bit % 8));
      AacAsc a;
      const int ret = aac_asc_parse(asc.data(), (int)asc.size(), &a);
      CHECK(ret == AAC_OK || ret == AAC_ERR_DECODE || ret == AAC_ERR_UNSUPPORTED,
            "%s: flipped bit %zu gives %d", s.name.c_str(), bit, ret);
      if (ret == AAC_OK) {
        parsed++;
        AacLayout l;
        aac_usac_config_layout(&a.usac, &l);
      } else {
        refused++;
      }
      asc[bit / 8] ^= (uint8_t)(0x80 >> (bit % 8));
    }
    for (size_t len = 0; len < asc.size(); len++) {
      AacAsc a;
      const int ret = aac_asc_parse(asc.data(), (int)len, &a);
      CHECK(ret != AAC_OK || len > asc.size() - 2, "%s: truncated to %zu bytes parses",
            s.name.c_str(), len);
    }
  }
  CHECK(parsed > 0 && refused > 0, "flips both parse (%d) and refuse (%d)", parsed, refused);
  /* The first access unit of a stream through the decoder, with every
   * bit flipped once: the walker, the pre-roll parse and a possible
   * reconfiguration from a damaged embedded configuration must refuse
   * or decode (up to the stubs), never fault. */
  const Stream* s = find(streams, "usac_music_48000_16000.loas");
  if (!s) {
    return;
  }
  const std::vector<std::vector<uint8_t>> aus = access_units(s->frames);
  std::vector<uint8_t> au = aus[0];
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 2);
  int errors[4] = {0, 0, 0, 0};
  for (size_t bit = 0; bit < 8 * au.size(); bit++) {
    au[bit / 8] ^= (uint8_t)(0x80 >> (bit % 8));
    const int cfg = aac_decoder_set_config(dec, s->asc.data(), (int)s->asc.size());
    CHECK(cfg == AAC_OK, "reconfigure before flip %zu: %d", bit, cfg);
    const int ret = aac_decoder_decode(dec, au.data(), (int)au.size(), pcm.data(), (int)pcm.size());
    CHECK(ret == AAC_ERR_UNSUPPORTED || ret == AAC_ERR_DECODE || ret == AAC_ERR_STATE,
          "flipped bit %zu of the IPF gives %d", bit, ret);
    errors[ret == AAC_ERR_UNSUPPORTED ? 0
           : ret == AAC_ERR_DECODE    ? 1
           : ret == AAC_ERR_STATE     ? 2
                                      : 3]++;
    au[bit / 8] ^= (uint8_t)(0x80 >> (bit % 8));
  }
  CHECK(errors[0] > 0 && errors[1] > 0, "flips reach the stub (%d) and are refused (%d), %d state",
        errors[0], errors[1], errors[2]);
  aac_decoder_destroy(dec);
}

}  // namespace

int main() {
  const char* names[] = {"usac_music_48000_16000.loas",  "usac_music_48000_24000.loas",
                         "usac_music_48000_48000.loas",  "usac_music_48000_96000.loas",
                         "usac_speech_48000_16000.loas", "usac_speech_48000_64000.loas"};
  std::vector<Stream> streams;
  for (const char* n : names) {
    streams.push_back(load_stream(n));
  }
  test_escaped_value();
  test_kit_configs(streams);
  test_latm_demux(streams);
  test_raw_au_path(streams);
  test_preroll_reconfigures(streams);
  test_loas_path(streams);
  test_preroll_parse(streams);
  test_equality(streams);
  test_layouts();
  test_constructed_configs();
  test_ext_only_walk();
  test_fuzz(streams);
  return test_summary("test_usac_config");
}
