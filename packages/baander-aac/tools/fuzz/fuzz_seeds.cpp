/* Writes the seed corpus of the fuzz harnesses:
 *
 *   fuzz_seeds <tests/data> <out>
 *
 * creates <out>/decoder, <out>/asc, <out>/encoder, <out>/unidrc and
 * <out>/mps, each in its harness's input layout (see the harness
 * sources).  The seeds come from every committed stream under
 * tests/data (its first frames, a truncated copy, its access units with
 * their configuration, its configuration on its own, the DRC
 * configuration and gain payloads of the USAC streams), from encoder
 * inputs synthesised per profile, and from hand-built inputs for the
 * fields a decoder has to bound: the escape prefix of a Huffman
 * codeword, the HCR lengths, the ascLen of a StreamMuxConfig, the
 * element counts of a program config element, sampling frequencies
 * above 96 kHz, the extension lengths of an ELD and a surround
 * configuration, and configurations larger than any the codec writes. */
#include <dirent.h>
#include <sys/stat.h>

#include <algorithm>
#include <cmath>
#include <cstdio>
#include <cstring>
#include <string>
#include <vector>

#include "aac_tables.h"
#include "asc.h"
#include "bitstream.h"
#include "latm.h"
#include "usac.h"

using Bytes = std::vector<uint8_t>;

namespace {

std::string g_out;
int g_written = 0;

void put(const char* harness, const std::string& name, const Bytes& data) {
  const std::string dir = g_out + "/" + harness;
  mkdir(dir.c_str(), 0755);
  const std::string path = dir + "/" + name;
  FILE* f = fopen(path.c_str(), "wb");
  if (!f) {
    fprintf(stderr, "fuzz_seeds: cannot create %s\n", path.c_str());
    exit(1);
  }
  if (!data.empty()) {
    fwrite(data.data(), 1, data.size(), f);
  }
  fclose(f);
  g_written++;
}

Bytes read_file(const std::string& path) {
  Bytes data;
  FILE* f = fopen(path.c_str(), "rb");
  if (!f) {
    return data;
  }
  uint8_t buf[65536];
  size_t n;
  while ((n = fread(buf, 1, sizeof(buf), f)) > 0) {
    data.insert(data.end(), buf, buf + n);
  }
  fclose(f);
  return data;
}

Bytes cat(std::initializer_list<Bytes> parts) {
  Bytes v;
  for (const Bytes& p : parts) {
    v.insert(v.end(), p.begin(), p.end());
  }
  return v;
}

Bytes slice(const Bytes& v, size_t from, size_t to) {
  to = std::min(to, v.size());
  from = std::min(from, to);
  return Bytes(v.begin() + (long)from, v.begin() + (long)to);
}

/* A bit writer over a growing buffer. */
struct Bits {
  Bytes buf;
  AacBitWriter w;
  Bits() {
    buf.resize(65536);
    aac_bitwriter_init(&w, buf.data(), (int)buf.size());
  }
  Bits& put(uint32_t v, int n) {
    aac_bitwriter_write(&w, v, n);
    return *this;
  }
  Bits& ones(int n) {
    for (int i = 0; i < n; i++) {
      put(1, 1);
    }
    return *this;
  }
  Bits& zeros(int n) {
    for (int i = 0; i < n; i++) {
      put(0, 1);
    }
    return *this;
  }
  Bits& bytes(const Bytes& b) {
    aac_bitwriter_write_bytes(&w, b.data(), (int)b.size());
    return *this;
  }
  Bits& align() {
    aac_bitwriter_byte_align(&w);
    return *this;
  }
  Bytes done() {
    aac_bitwriter_byte_align(&w);
    return slice(buf, 0, (size_t)aac_bitwriter_bytes_written(&w));
  }
};

/* The bits [from, to) of a buffer, byte aligned into a new one. */
Bytes bits_of(const uint8_t* data, int size, int from, int to) {
  AacBitReader r;
  aac_bitreader_init(&r, data, size);
  aac_bitreader_skip(&r, from);
  Bits b;
  for (int pos = from; pos < to; pos += 32) {
    const int n = std::min(32, to - pos);
    b.put(aac_bitreader_read(&r, n), n);
  }
  return b.done();
}

/* ── the committed streams ─────────────────────────────────────── */

struct Frame {
  size_t offset, size;
};

bool is_loas(const Bytes& s) { return s.size() >= 3 && aac_loas_is_sync(s.data(), (int)s.size()); }

std::vector<Frame> split_frames(const Bytes& s) {
  std::vector<Frame> frames;
  size_t pos = 0;
  if (is_loas(s)) {
    while (pos + 3 <= s.size() && aac_loas_is_sync(s.data() + pos, (int)(s.size() - pos))) {
      const size_t len = 3 + (size_t)aac_loas_payload_length(s.data() + pos);
      if (pos + len > s.size()) {
        break;
      }
      frames.push_back({pos, len});
      pos += len;
    }
  } else {
    AacAdtsHeader h;
    while (pos + 7 <= s.size() && aac_adts_parse(&h, s.data() + pos, (int)(s.size() - pos)) == 0) {
      if (pos + (size_t)h.frame_length > s.size()) {
        break;
      }
      frames.push_back({pos, (size_t)h.frame_length});
      pos += (size_t)h.frame_length;
    }
  }
  return frames;
}

/* The first frames up to about `budget` bytes, at least two. */
Bytes head_frames(const Bytes& s, const std::vector<Frame>& frames, size_t budget) {
  size_t end = 0;
  for (size_t i = 0; i < frames.size(); i++) {
    if (i >= 2 && frames[i].offset + frames[i].size > budget) {
      break;
    }
    end = frames[i].offset + frames[i].size;
  }
  return slice(s, 0, end);
}

uint32_t latm_get_value(AacBitReader* r) {
  const int bytes = (int)aac_bitreader_read(r, 2) + 1;
  uint32_t v = 0;
  for (int i = 0; i < bytes; i++) {
    v = v << 8 | aac_bitreader_read(r, 8);
  }
  return v;
}

/* The AudioSpecificConfig bytes of a LOAS frame's StreamMuxConfig (one
 * program, one layer): the fixed fields ahead of it are walked, the
 * config's extent comes from its own parse or from ascLen. */
Bytes asc_of_loas(const Bytes& frame, int* mux_config_bit) {
  const uint8_t* p = frame.data() + 3;
  const int size = (int)frame.size() - 3;
  AacBitReader r;
  aac_bitreader_init(&r, p, size);
  if (aac_bitreader_read(&r, 1) != 0) { /* useSameStreamMux */
    return Bytes();
  }
  *mux_config_bit = 1;
  const int version = (int)aac_bitreader_read(&r, 1);
  int version_a = 0;
  if (version) {
    version_a = (int)aac_bitreader_read(&r, 1);
  }
  if (version_a) {
    return Bytes();
  }
  if (version) {
    latm_get_value(&r); /* taraBufferFullness */
  }
  aac_bitreader_read(&r, 1);  /* allStreamsSameTimeFraming */
  aac_bitreader_read(&r, 6);  /* numSubFrames */
  aac_bitreader_read(&r, 4);  /* numProgram */
  aac_bitreader_read(&r, 3);  /* numLayer */
  int length_bits = AAC_ASC_LENGTH_UNKNOWN;
  if (version) {
    length_bits = (int)latm_get_value(&r) * 8;
  }
  const int start = r.pos;
  AacAsc asc;
  if (aac_asc_read_bits(&r, &asc, length_bits) != AAC_OK) {
    return Bytes();
  }
  const int end = length_bits >= 0 ? start + length_bits : r.pos;
  return bits_of(p, size, start, end);
}

/* The access units of a stream and its configuration (an ASC written
 * for an ADTS stream's header; the StreamMuxConfig's for LOAS). */
struct Units {
  Bytes asc;
  Bytes mux_config; /* the StreamMuxConfig bits of LOAS, from audioMuxVersion */
  std::vector<Bytes> aus;
};

Units units_of(const Bytes& s, const std::vector<Frame>& frames) {
  Units u;
  if (is_loas(s)) {
    AacLatmConfig cfg;
    memset(&cfg, 0, sizeof(cfg));
    for (const Frame& f : frames) {
      const uint8_t* payload = s.data() + f.offset + 3;
      const int size = (int)f.size - 3;
      Bytes out((size_t)size);
      AacLatmPayload p[AAC_LATM_MAX_SUBFRAMES];
      int used = 0;
      const int n = aac_latm_read_mux_element(payload, size, 1, &cfg, out.data(), size, p,
                                              AAC_LATM_MAX_SUBFRAMES, &used);
      for (int i = 0; i < n; i++) {
        u.aus.push_back(slice(out, (size_t)p[i].offset, (size_t)(p[i].offset + p[i].size)));
      }
      if (u.asc.empty()) {
        int mux_bit = 0;
        u.asc = asc_of_loas(slice(s, f.offset, f.offset + f.size), &mux_bit);
        if (!u.asc.empty()) {
          u.mux_config = bits_of(payload, size, mux_bit, size * 8);
        }
      }
    }
  } else {
    AacAdtsHeader h;
    for (const Frame& f : frames) {
      if (aac_adts_parse(&h, s.data() + f.offset, (int)f.size) != 0) {
        continue;
      }
      const size_t header = (size_t)AAC_ADTS_HEADER_SIZE + (h.protection_absent ? 0 : 2);
      u.aus.push_back(slice(s, f.offset + header, f.offset + f.size));
      if (u.asc.empty()) {
        AacAsc asc;
        aac_asc_init(&asc, aac_sample_rates[h.sample_rate_index], h.channel_config);
        uint8_t buf[AAC_ASC_MAX_BYTES];
        const int len = aac_asc_write(&asc, buf, (int)sizeof(buf));
        if (len > 0) {
          u.asc.assign(buf, buf + len);
        }
      }
    }
  }
  return u;
}

Bytes be16(size_t v) { return {(uint8_t)(v >> 8), (uint8_t)v}; }

/* Decoder harness: four option bytes, the config (mode 1) and the data. */
Bytes decoder_input(uint8_t options, uint8_t transport, uint8_t drc, const Bytes& asc_in,
                    const Bytes& payload) {
  const Bytes asc = slice(asc_in, 0, 255); /* the length travels in one byte */
  return cat({{options, transport, drc, (uint8_t)asc.size()}, asc, payload});
}

Bytes raw_units(const std::vector<Bytes>& aus, size_t budget) {
  Bytes v;
  for (const Bytes& au : aus) {
    if (!v.empty() && v.size() + au.size() > budget) {
      break;
    }
    v = cat({v, be16(au.size()), au});
  }
  return v;
}

void seeds_of_stream(const std::string& name, const Bytes& s) {
  const std::vector<Frame> frames = split_frames(s);
  if (frames.empty()) {
    fprintf(stderr, "fuzz_seeds: %s: no frames\n", name.c_str());
    return;
  }
  const std::string base = name.substr(0, name.find_last_of('.'));
  const Bytes head = head_frames(s, frames, 6000);
  const bool mps = name.rfind("mps_", 0) == 0;
  const Units u = units_of(s, frames);

  /* decoder: the stream, chunked with concealment off and a downmix,
   * truncated inside a frame, the first frame one-shot, the access
   * units with the config, and an explicit transport. */
  put("decoder", base + "_stream.bin", decoder_input(0x00, 0x00, 0x00, {}, head));
  put("decoder", base + "_chunked.bin", decoder_input(0x88, 0x00, 0x02, {}, head));
  put("decoder", base + "_truncated.bin",
      decoder_input(0x22, 0x00, 0x00, {}, slice(head, 0, head.size() > 37 ? head.size() - 37 : 1)));
  put("decoder", base + "_oneshot.bin",
      decoder_input(0x00, 0x02, 0x00, {}, slice(s, frames[0].offset, frames[0].offset + frames[0].size)));
  if (!u.asc.empty() && !u.aus.empty()) {
    put("decoder", base + "_raw.bin", decoder_input(0x00, 0x01, 0x00, u.asc, raw_units(u.aus, 4000)));
    put("decoder", base + "_raw_conceal_off.bin",
        decoder_input(0x40, 0x01, 0x11, u.asc, raw_units(u.aus, 4000)));
    put("decoder", base + "_explicit.bin",
        decoder_input(0x08, (uint8_t)(0x03 | (is_loas(s) ? 1 : 0) << 2), 0x00, u.asc, head));
    /* asc: the config alone, through the bit reader with its length,
     * and applied to a decoder with the first access unit. */
    put("asc", base + "_parse.bin", cat({{0x00, 0x00, 0x00}, u.asc}));
    put("asc", base + "_bits.bin",
        cat({{0x01, (uint8_t)(u.asc.size() * 8 >> 8), (uint8_t)(u.asc.size() * 8)}, u.asc}));
    put("asc", base + "_apply.bin",
        cat({{0x03, (uint8_t)(u.asc.size() >> 8), (uint8_t)u.asc.size()}, u.asc, u.aus[0]}));
  }
  if (!u.mux_config.empty()) {
    put("asc", base + "_mux.bin", cat({{0x02, 0x00, 0x00}, u.mux_config}));
  }
  if (mps) {
    put("mps", base + "_fifo.bin", cat({{0x00, 0x00}, head}));
    put("mps", base + "_stereo.bin", cat({{0x08, 0x00}, head}));
    put("mps", base + "_oneshot.bin", cat({{0x0c, 0x03}, head}));
    put("mps", base + "_truncated.bin", cat({{0x81, 0x01}, slice(head, 0, head.size() - 101)}));
  }

  /* unidrc: the configuration and the gain payloads of a USAC stream. */
  AacAsc asc;
  if (!u.asc.empty() && aac_asc_parse(u.asc.data(), (int)u.asc.size(), &asc) == AAC_OK &&
      asc.aot == AAC_AOT_USAC) {
    Bytes cfg, loud;
    int drc_elem = -1;
    for (int i = 0; i < asc.usac.num_elements; i++) {
      const AacUsacElementConfig& e = asc.usac.elem[i];
      if (e.type == AAC_USAC_EXT && e.ext_type == AAC_USAC_EXT_UNI_DRC) {
        cfg.assign(e.ext_config, e.ext_config + e.ext_config_len);
        drc_elem = i;
      }
    }
    for (int i = 0; i < asc.usac.num_config_exts; i++) {
      if (asc.usac.config_ext[i].type == AAC_USAC_CFG_EXT_LOUDNESS_INFO) {
        loud.assign(asc.usac.config_ext[i].data,
                    asc.usac.config_ext[i].data + asc.usac.config_ext[i].len);
      }
    }
    if (drc_elem < 0) {
      return;
    }
    /* The payload of the DRC extension element in each access unit:
     * the extension elements ahead of the first channel element are
     * walked in configuration order. */
    Bytes gains;
    int frames_with_gain = 0;
    for (const Bytes& au : u.aus) {
      AacBitReader r;
      aac_bitreader_init(&r, au.data(), (int)au.size());
      aac_bitreader_read(&r, 1); /* usacIndependencyFlag */
      Bytes gain;
      bool found = false;
      for (int i = 0; i < asc.usac.num_elements && !r.overread; i++) {
        const AacUsacElementConfig& e = asc.usac.elem[i];
        if (e.type != AAC_USAC_EXT) {
          break;
        }
        if (!aac_bitreader_read(&r, 1)) { /* usacExtElementPresent */
          continue;
        }
        int len = e.ext_default_length;
        if (!aac_bitreader_read(&r, 1)) { /* usacExtElementUseDefaultLength */
          len = aac_usac_ext_payload_length(&r);
        }
        if (e.ext_payload_frag) {
          aac_bitreader_read(&r, 2);
        }
        if (len < 0 || len > aac_bitreader_bits_left(&r) / 8) {
          break;
        }
        const Bytes payload = bits_of(au.data(), (int)au.size(), r.pos, r.pos + len * 8);
        aac_bitreader_skip(&r, len * 8);
        if (i == drc_elem) {
          gain = payload;
          found = true;
        }
      }
      if (found) {
        frames_with_gain++;
      }
      gains = cat({gains, {(uint8_t)gain.size()}, gain});
      if (gains.size() > 3000) {
        break;
      }
    }
    put("unidrc", base + "_config.bin", cat({{0x00, 0x02, 0x00, 0x00}, cfg, loud}));
    put("unidrc", base + "_gains.bin", cat({{0x01, 0x02, 0x00, 0x00}, gains}));
    put("unidrc", base + "_module.bin",
        cat({{0x02, 0x02, 0x12, 0x18, (uint8_t)cfg.size(), (uint8_t)loud.size()}, cfg, loud, gains}));
    put("unidrc", base + "_builtin.bin", cat({{0x03, 0x02, 0x21, 0x07}, gains}));
    if (frames_with_gain == 0) {
      fprintf(stderr, "fuzz_seeds: %s: no DRC gain payloads found\n", name.c_str());
    }
  }
}

/* ── encoder inputs ───────────────────────────────────────────── */

/* A tone with a click, as 16-bit samples; `frames` of `frame` samples. */
Bytes tone_pcm(int channels, int samples) {
  Bytes v;
  for (int i = 0; i < samples; i++) {
    for (int c = 0; c < channels; c++) {
      const float t = (float)i / 48000.0f;
      float s = 0.3f * sinf(2.0f * (float)M_PI * (220.0f * (float)(c + 1)) * t) +
                0.1f * sinf(2.0f * (float)M_PI * 3000.0f * t);
      if (i >= 400 && i < 440) {
        s += 0.5f;
      }
      const int16_t q = (int16_t)lrintf(s * 32767.0f);
      v.push_back((uint8_t)q);
      v.push_back((uint8_t)(q >> 8));
    }
  }
  return v;
}

void encoder_seeds() {
  struct Config {
    const char* name;
    uint8_t profile, rate, channels;
    uint16_t bitrate_units;
    uint8_t rc, quality, frame, ext, usac, drc, level, payload_len;
    int samples;
  };
  /* profile: 0 LC, 1 HE v1, 2 HE v2, 3 LD, 4 ELD, 5 USAC; rate index 8
   * is 48 kHz, 7 is 44.1, 3 is 16, 13 is 192 kHz; frame: bits 5-7 the
   * transport (1 ADTS, 2 LOAS, 3 LATM in-band, 4 out of band, 5 raw). */
  const Config configs[] = {
      {"lc_stereo_128k", 0, 8, 2, 2000, 3, 0, 0x00, 0x00, 0x00, 0, 0, 0, 1536},
      {"lc_mono_64k_960", 0, 7, 1, 1000, 0, 5, 0x01, 0x00, 0x00, 0, 0, 0, 1440},
      {"lc_51_320k_loas", 0, 8, 6, 5000, 3, 0, 0x40, 0x01, 0x00, 3, 124, 0, 1536},
      {"lc_71_back_raw", 0, 8, 8, 6000, 2, 0, 0xa0, 0x08, 0x00, 0, 0, 0, 1100},
      {"lc_crc_mpeg2_abr", 0, 6, 2, 1500, 2, 3, 0x1c, 0x00, 0x00, 1, 100, 0, 1536},
      {"he_stereo_64k", 1, 8, 2, 1000, 3, 0, 0x00, 0x00, 0x00, 0, 0, 0, 3072},
      {"he_stereo_64k_latm_hier", 1, 8, 2, 1000, 3, 0, 0x60, 0x02, 0x00, 0, 0, 0, 3072},
      {"hev2_stereo_32k", 2, 8, 2, 500, 3, 0, 0x00, 0x00, 0x00, 0, 0, 0, 3072},
      {"hev2_stereo_32k_mcp0", 2, 7, 2, 500, 1, 0, 0x80, 0x01, 0x00, 0, 0, 0, 3072},
      {"ld_stereo_64k", 3, 8, 2, 1000, 3, 0, 0x00, 0x00, 0x00, 0, 0, 0, 768},
      {"ld_mono_480", 3, 8, 1, 500, 3, 0, 0x01, 0x00, 0x00, 0, 0, 0, 720},
      {"eld_stereo_64k", 4, 8, 2, 1000, 3, 0, 0x00, 0x00, 0x00, 0, 0, 0, 768},
      {"eld_sbr_dual_rate", 4, 8, 2, 500, 3, 0, 0x00, 0x60, 0x00, 0, 0, 0, 1536},
      {"eld_sbr_single_rate", 4, 8, 1, 500, 3, 0, 0x02, 0x20, 0x00, 0, 0, 0, 768},
      {"eld_payload", 4, 8, 2, 1000, 3, 0, 0x00, 0x00, 0x00, 0, 0, 17, 768},
      {"usac_stereo_96k", 5, 8, 2, 1500, 3, 0, 0x00, 0x00, 0x03, 0, 0, 0, 1536},
      {"usac_mono_32k_sbr", 5, 8, 1, 500, 3, 0, 0x00, 0x00, 0x0b, 0, 0, 0, 3072},
      {"usac_stereo_32k_mps", 5, 8, 2, 500, 3, 0, 0x00, 0x80, 0x2b, 0, 0, 0, 3072},
      {"usac_stereo_64k_raw_indep1", 5, 8, 2, 1000, 3, 0, 0xa0, 0x80, 0x43, 0, 0, 0, 3072},
      {"usac_stereo_48k_no_preroll", 5, 7, 2, 750, 3, 0, 0x00, 0x00, 0x05, 0, 0, 0, 3072},
      {"usac_loudness_meter", 5, 8, 2, 1500, 3, 0, 0x00, 0x80, 0x03, 0, 0, 0, 1536},
      {"lc_192khz", 0, 13, 2, 2000, 3, 0, 0x00, 0x00, 0x00, 0, 0, 0, 64},
      {"he_7999hz", 1, 14, 2, 500, 3, 0, 0x00, 0x00, 0x00, 0, 0, 0, 64},
      {"lc_zero_channels", 0, 8, 0, 2000, 3, 0, 0x00, 0x00, 0x00, 0, 0, 0, 64},
      {"lc_bad_rc", 0, 8, 2, 2000, 4, 0, 0x00, 0x00, 0x00, 0, 0, 0, 64},
      {"lc_loud_8x", 0, 8, 2, 2000, 0, 0x20, 0x00, 0x00, 0x00, 0, 0, 0, 1536},
  };
  for (const Config& c : configs) {
    Bytes header = {c.profile,
                    c.rate,
                    c.channels,
                    (uint8_t)(c.bitrate_units >> 8),
                    (uint8_t)c.bitrate_units,
                    c.rc,
                    c.quality,
                    c.frame,
                    c.ext,
                    c.usac,
                    c.drc,
                    c.level,
                    c.payload_len};
    Bytes payload;
    for (int i = 0; i < c.payload_len; i++) {
      payload.push_back((uint8_t)(0x90 + i * 7));
    }
    put("encoder", std::string(c.name) + ".bin",
        cat({header, payload, tone_pcm(c.channels ? c.channels : 1, c.samples)}));
  }
}

/* ── hand-built bounds ─────────────────────────────────────────── */

/* The codeword bits of the book 11 leaf for (16, 16): a walk of the
 * decoding tree. */
bool find_codeword(int node, int depth, uint32_t path, int* out_len, uint32_t* out_path) {
  if (depth > 24) {
    return false;
  }
  for (int bit = 0; bit < 2; bit++) {
    const int next = aac_huffman_step(11, node, bit);
    if (next == 0) {
      continue;
    }
    if (next < 0) {
      const int8_t* v = aac_huffman_leaf(11, -next - 1);
      if (v[0] == 16 && v[1] == 16) {
        *out_len = depth + 1;
        *out_path = path << 1 | (uint32_t)bit;
        return true;
      }
      continue;
    }
    if (find_codeword(next, depth + 1, path << 1 | (uint32_t)bit, out_len, out_path)) {
      return true;
    }
  }
  return false;
}

Bytes adts_frame(int channel_config, const Bytes& block) {
  AacAdtsHeader h;
  memset(&h, 0, sizeof(h));
  h.id = 0;
  h.protection_absent = 1;
  h.sample_rate_index = 3;
  h.channel_config = channel_config;
  h.object_type = 2;
  h.frame_length = AAC_ADTS_HEADER_SIZE + (int)block.size();
  h.buffer_fullness = 0x7FF;
  uint8_t header[AAC_ADTS_HEADER_SIZE];
  aac_adts_write(&h, header);
  return cat({Bytes(header, header + AAC_ADTS_HEADER_SIZE), block});
}

/* A single channel element up to its spectral data: long window, one
 * scalefactor band in codebook 11, no tools. */
void sce_head(Bits& b) {
  b.put(0, 3);   /* id_syn_ele SCE */
  b.put(0, 4);   /* element_instance_tag */
  b.put(100, 8); /* global_gain */
  b.put(0, 1);   /* ics_reserved_bit */
  b.put(0, 2);   /* window_sequence: long */
  b.put(0, 1);   /* window_shape */
  b.put(1, 6);   /* max_sfb */
  b.put(0, 1);   /* predictor_data_present */
  b.put(11, 4);  /* sect_cb */
  b.put(1, 5);   /* sect_len_incr */
  aac_bitwriter_write_scalefactor(&b.w, 0);
  b.put(0, 1); /* pulse_data_present */
  b.put(0, 1); /* tns_data_present */
  b.put(0, 1); /* gain_control_data_present */
}

void huffman_escape_seeds() {
  int len = 0;
  uint32_t path = 0;
  if (!find_codeword(0, 0, 0, &len, &path)) {
    fprintf(stderr, "fuzz_seeds: no (16, 16) codeword in book 11\n");
    return;
  }
  /* The escape prefix of ISO/IEC 14496-3 4.6.3.3 may carry at most
   * nine ones for a magnitude up to 8191; the seeds carry 9, 13 and 24. */
  for (int prefix : {9, 13, 24}) {
    Bits b;
    sce_head(b);
    for (int pair = 0; pair < 2; pair++) { /* the four lines of band 0 */
      b.put(path, len);
      b.put(0, 2); /* sign bits */
      b.ones(prefix).put(0, 1).put(0x5A5A, prefix + 4 > 16 ? 16 : prefix + 4);
      b.ones(4).put(0, 1).put(0xA5, 8);
    }
    b.put(7, 3); /* ID_END */
    const Bytes frame = adts_frame(1, b.done());
    put("decoder", "bound_huffman_escape_" + std::to_string(prefix) + ".bin",
        decoder_input(0x00, 0x00, 0x00, {}, cat({frame, frame})));
  }
}

/* ER AAC-LC with the Huffman codeword reordering: the reordered
 * spectral data's lengths at their maxima. */
void hcr_seeds() {
  Bits a;
  a.put(17, 5); /* audioObjectType ER AAC-LC */
  a.put(3, 4);  /* 48 kHz */
  a.put(1, 4);  /* mono */
  a.put(0, 1);  /* frameLengthFlag */
  a.put(0, 1);  /* dependsOnCoreCoder */
  a.put(1, 1);  /* extensionFlag */
  a.put(0, 1);  /* aacSectionDataResilienceFlag */
  a.put(0, 1);  /* aacScalefactorDataResilienceFlag */
  a.put(1, 1);  /* aacSpectralDataResilienceFlag */
  a.put(0, 1);  /* extensionFlag3 */
  a.put(0, 2);  /* epConfig */
  const Bytes asc = a.done();
  for (int variant = 0; variant < 3; variant++) {
    Bits b;
    b.put(100, 8); /* global_gain */
    b.put(0, 1).put(0, 2).put(0, 1).put(variant == 2 ? 40 : 1, 6).put(0, 1); /* ics_info */
    b.put(11, 4).put(variant == 2 ? 31 : 1, 5);
    if (variant == 2) {
      b.put(9, 5); /* a second section */
    }
    aac_bitwriter_write_scalefactor(&b.w, 0);
    b.put(0, 1).put(0, 1).put(0, 1);
    b.put(variant == 1 ? 16383 : 200, 14); /* length_of_reordered_spectral_data */
    b.put(63, 6);                           /* length_of_longest_codeword */
    for (int i = 0; i < 40; i++) {
      b.ones(13).put(0, 1).put(0x1234, 16);
    }
    const Bytes au = b.done();
    put("decoder", "bound_hcr_" + std::to_string(variant) + ".bin",
        decoder_input(0x00, 0x01, 0x00, asc, cat({be16(au.size()), au, be16(au.size()), au})));
  }
  put("asc", "bound_er_lc.bin", cat({{0x00, 0x00, 0x00}, asc}));
}

/* A LOAS frame of audioMuxVersion 1 whose ascLen claims more, less and
 * nothing of the config that follows. */
void latm_asclen_seeds(const Bytes& au) {
  for (uint32_t asc_len : {0xFFFFFFu, 7u, 0u, 2u}) {
    Bits b;
    b.put(0, 1);  /* useSameStreamMux */
    b.put(1, 1);  /* audioMuxVersion */
    b.put(0, 1);  /* audioMuxVersionA */
    b.put(0, 2).put(0xFF, 8); /* taraBufferFullness */
    b.put(1, 1);  /* allStreamsSameTimeFraming */
    b.put(0, 6);  /* numSubFrames */
    b.put(0, 4);  /* numProgram */
    b.put(0, 3);  /* numLayer */
    b.put(2, 2).put(asc_len, 24); /* ascLen over three bytes */
    b.put(0x11, 8).put(0x90, 8);  /* AAC-LC 48 kHz stereo */
    b.put(0, 3);  /* frameLengthType */
    b.put(0xFF, 8); /* latmBufferFullness */
    b.put(0, 1);  /* otherDataPresent */
    b.put(0, 1);  /* crcCheckPresent */
    /* PayloadLengthInfo: 255-byte chunks and the remainder. */
    size_t n = au.size();
    while (n >= 255) {
      b.put(255, 8);
      n -= 255;
    }
    b.put((uint32_t)n, 8);
    b.align().bytes(au);
    const Bytes payload = b.done();
    Bits h;
    h.put(AAC_LOAS_SYNC, 11).put((uint32_t)payload.size(), 13);
    const Bytes frame = cat({h.done(), payload});
    const std::string tag = std::to_string(asc_len);
    put("decoder", "bound_latm_asclen_" + tag + ".bin", decoder_input(0x00, 0x00, 0x00, {}, cat({frame, frame})));
    put("asc", "bound_latm_asclen_" + tag + ".bin", cat({{0x02, 0x00, 0x00}, bits_of(payload.data(), (int)payload.size(), 1, (int)payload.size() * 8)}));
  }
}

/* A program config element with every count at its maximum. */
void pce_body(Bits& b) {
  b.put(0, 4);  /* element_instance_tag */
  b.put(1, 2);  /* object_type LC */
  b.put(3, 4);  /* sampling_frequency_index */
  b.put(15, 4); /* num_front_channel_elements */
  b.put(15, 4); /* num_side_channel_elements */
  b.put(15, 4); /* num_back_channel_elements */
  b.put(3, 2);  /* num_lfe_channel_elements */
  b.put(7, 3);  /* num_assoc_data_elements */
  b.put(15, 4); /* num_valid_cc_elements */
  b.put(0, 1).put(0, 1).put(0, 1); /* no mixdowns */
  for (int i = 0; i < 45; i++) {
    b.put((uint32_t)(i & 1), 1).put((uint32_t)(i & 15), 4);
  }
  for (int i = 0; i < 3; i++) {
    b.put((uint32_t)i, 4);
  }
  for (int i = 0; i < 7; i++) {
    b.put((uint32_t)i, 4);
  }
  for (int i = 0; i < 15; i++) {
    b.put((uint32_t)(i & 1), 1).put((uint32_t)i, 4);
  }
}

void element_count_seeds() {
  Bits a;
  a.put(2, 5).put(3, 4).put(0, 4); /* AAC-LC, 48 kHz, channelConfiguration 0 */
  a.put(0, 1).put(0, 1).put(0, 1); /* GASpecificConfig flags */
  pce_body(a);
  a.align();
  a.put(0, 8); /* comment_field_bytes */
  const Bytes asc = a.done();
  put("asc", "bound_pce_counts.bin", cat({{0x00, 0x00, 0x00}, asc}));
  Bits r;
  r.put(5, 3); /* id_syn_ele PCE */
  pce_body(r);
  r.align();
  r.put(0, 8);
  r.put(7, 3); /* ID_END */
  const Bytes frame = adts_frame(0, r.done());
  put("decoder", "bound_pce_counts.bin", decoder_input(0x00, 0x00, 0x00, {}, cat({frame, frame})));
  /* The same config with the longest comment: larger than any config
   * the codec writes. */
  Bits c;
  c.put(2, 5).put(3, 4).put(0, 4).put(0, 1).put(0, 1).put(0, 1);
  pce_body(c);
  c.align();
  c.put(255, 8);
  for (int i = 0; i < 255; i++) {
    c.put((uint32_t)('A' + i % 26), 8);
  }
  const Bytes big = c.done();
  put("asc", "bound_config_size.bin", cat({{0x00, 0x00, 0x00}, big}));
  put("asc", "bound_config_size_apply.bin",
      cat({{0x03, (uint8_t)(big.size() >> 8), (uint8_t)big.size()}, big, frame}));
  put("decoder", "bound_config_size_raw.bin",
      decoder_input(0x00, 0x01, 0x00, slice(big, 0, 255), cat({be16(frame.size()), frame})));
}

/* Sampling frequencies above 96 kHz in every config that carries one. */
void high_rate_seeds() {
  for (uint32_t rate : {192000u, 0xFFFFFFu, 96001u}) {
    const std::string tag = std::to_string(rate);
    {
      Bits a;
      a.put(2, 5).put(15, 4).put(rate, 24).put(2, 4).put(0, 3);
      put("asc", "bound_rate_lc_" + tag + ".bin", cat({{0x00, 0x00, 0x00}, a.done()}));
    }
    {
      /* HE-AAC v1 hierarchical: the SBR output rate above 96 kHz. */
      Bits a;
      a.put(5, 5).put(3, 4).put(2, 4).put(15, 4).put(rate, 24).put(2, 5).put(0, 3);
      put("asc", "bound_rate_sbr_" + tag + ".bin", cat({{0x00, 0x00, 0x00}, a.done()}));
    }
    {
      /* USAC: usacSamplingFrequencyIndex escaped. */
      Bits a;
      a.put(31, 5).put(42 - 32, 6).put(15, 4).put(rate, 24).put(2, 4);
      a.put(0x1f, 5).put(rate, 24).put(1, 3).put(2, 5);
      a.put(0, 4);        /* numElements - 1 */
      a.put(1, 2);        /* CPE */
      a.put(0, 1).put(1, 1); /* tw_mdct, noiseFilling */
      a.put(0, 1);        /* usacConfigExtensionPresent */
      put("asc", "bound_rate_usac_" + tag + ".bin", cat({{0x00, 0x00, 0x00}, a.done()}));
    }
    {
      /* ELD with LD-SBR at twice the rate. */
      Bits a;
      a.put(31, 5).put(39 - 32, 6).put(15, 4).put(rate, 24).put(1, 4);
      a.put(0, 1).put(0, 1).put(0, 1).put(0, 1); /* frameLengthFlag, resilience */
      a.put(1, 1).put(1, 1).put(0, 1);           /* ldSbrPresent, ldSbrSamplingRate, ldSbrCrc */
      a.put(1, 1).put(7, 4).put(9, 4).put(3, 3).put(0, 2).put(0, 1).put(0, 1); /* sbr_header */
      a.put(0, 4); /* ELDEXT_TERM */
      a.put(0, 2); /* epConfig */
      put("asc", "bound_rate_eld_" + tag + ".bin", cat({{0x00, 0x00, 0x00}, a.done()}));
    }
  }
}

/* ELD extensions with the escaped lengths, and the surround
 * configuration's extension length. */
void extension_length_seeds() {
  for (int variant = 0; variant < 3; variant++) {
    Bits a;
    a.put(31, 5).put(39 - 32, 6).put(3, 4).put(2, 4);
    a.put(0, 1).put(0, 1).put(0, 1).put(0, 1);
    a.put(0, 1); /* ldSbrPresentFlag */
    const uint32_t type = variant == 2 ? 9 : 1; /* ELDEXT_LDSAC or a reserved type */
    a.put(type, 4);
    a.put(15, 4);  /* eldExtLen escape */
    a.put(255, 8); /* eldExtLenAdd escape */
    a.put(variant == 0 ? 0xFFFF : 300, 16);
    for (int i = 0; i < 64; i++) {
      a.put((uint32_t)(i * 37), 8);
    }
    a.put(0, 4); /* ELDEXT_TERM, past the claimed length */
    a.put(0, 2);
    put("asc", "bound_eld_ext_" + std::to_string(variant) + ".bin", cat({{0x00, 0x00, 0x00}, a.done()}));
  }
  /* MPEG Surround as object type 30 with the payload embedded: the 5151
   * tree, then a SacExtensionConfig of the longest claimed length. */
  Bits m;
  m.put(30, 5).put(3, 4).put(1, 4);
  m.put(1, 1);                     /* sacPayloadEmbedding */
  m.put(3, 4);                     /* bsSamplingFrequencyIndex 48 kHz */
  m.put(31, 7);                    /* bsFrameLength */
  m.put(0, 3);                     /* bsFreqRes 28 bands */
  m.put(0, 4);                     /* bsTreeConfig 5151 */
  m.put(0, 2);                     /* bsQuantMode */
  m.put(0, 1);                     /* bsOneIcc */
  m.put(0, 1);                     /* bsArbitraryDownmix */
  m.put(2, 3).put(2, 3).put(2, 3).put(2, 3); /* fixed gains */
  m.put(0, 1);                     /* bsMatrixMode */
  m.put(0, 2);                     /* bsTempShapeConfig */
  m.put(0, 2);                     /* bsDecorrConfig */
  m.put(0, 1);                     /* bs3DaudioMode */
  m.put(31, 5);                    /* bsOttBands of the LFE box */
  m.put(0, 1);                     /* bsResidualCoding */
  m.put(0, 1);                     /* bsEnvQuantMode */
  m.align();
  m.put(1, 4).put(255, 8).put(0xFFFF, 16); /* bsSacExtType, bsSacExtLen escaped */
  for (int i = 0; i < 32; i++) {
    m.put((uint32_t)(i * 53), 8);
  }
  const Bytes asc = m.done();
  put("asc", "bound_mps_ext.bin", cat({{0x00, 0x00, 0x00}, asc}));
  put("asc", "bound_mps_ext_bits.bin",
      cat({{0x01, (uint8_t)(asc.size() * 8 >> 8), (uint8_t)(asc.size() * 8)}, asc}));
}

/* USAC configuration extensions and extension elements of the largest
 * lengths. */
void usac_extension_seeds() {
  Bits a;
  a.put(31, 5).put(42 - 32, 6).put(3, 4).put(2, 4);
  a.put(3, 5).put(1, 3).put(2, 5); /* 48 kHz, no SBR, stereo */
  a.put(1, 4);                     /* two elements */
  a.put(1, 2).put(0, 1).put(1, 1); /* CPE */
  a.put(3, 2);                     /* EXT */
  a.put(4, 4);                     /* usacExtElementType UNI_DRC */
  a.put(15, 4).put(255, 8).put(0xFFFF, 16); /* usacExtElementConfigLength escaped */
  for (int i = 0; i < 48; i++) {
    a.put((uint32_t)(i * 29), 8);
  }
  a.put(1, 1);                     /* usacConfigExtensionPresent */
  a.put(15, 4).put(255, 8).put(0xFFFF, 16); /* numConfigExtensions escaped */
  a.put(2, 4);                     /* ID_CONFIG_EXT_LOUDNESS_INFO */
  a.put(15, 4).put(255, 8).put(0xFFFF, 16); /* usacConfigExtLength escaped */
  const Bytes asc = a.done();
  put("asc", "bound_usac_ext.bin", cat({{0x00, 0x00, 0x00}, asc}));
  put("decoder", "bound_usac_ext_raw.bin",
      decoder_input(0x00, 0x01, 0x00, asc, cat({be16(8), {0x80, 0x3F, 0xFF, 0xFF, 0x12, 0x34, 0x56, 0x78}})));
}

/* ADTS frames with several raw data blocks, a CRC and a 960-sample flag. */
void adts_variant_seeds(const Bytes& au) {
  AacAdtsHeader h;
  memset(&h, 0, sizeof(h));
  h.protection_absent = 0;
  h.sample_rate_index = 3;
  h.channel_config = 2;
  h.object_type = 2;
  h.num_raw_blocks = 3;
  h.buffer_fullness = 0x7FF;
  h.frame_length = AAC_ADTS_HEADER_SIZE + 2 + 2 * 3 + 2 * 4 + (int)au.size() * 4;
  uint8_t header[AAC_ADTS_HEADER_SIZE];
  aac_adts_write(&h, header);
  Bytes frame(header, header + AAC_ADTS_HEADER_SIZE);
  frame.insert(frame.end(), {0xAB, 0xCD}); /* adts_error_check */
  for (int i = 1; i <= 3; i++) {
    const Bytes pos = be16((size_t)i * (au.size() + 2));
    frame.insert(frame.end(), pos.begin(), pos.end()); /* raw_data_block_position */
  }
  for (int i = 0; i < 4; i++) {
    frame.insert(frame.end(), au.begin(), au.end());
    frame.insert(frame.end(), {0x12, 0x34}); /* adts_raw_data_block_error_check */
  }
  put("decoder", "bound_adts_blocks_crc.bin", decoder_input(0x00, 0x00, 0x00, {}, cat({frame, frame})));
  put("decoder", "bound_adts_960.bin", decoder_input(0x00, 0x80, 0x00, {}, cat({frame, frame})));
}

void unidrc_bound_seeds() {
  /* A uniDrcConfig whose counts sit at the parser's limits and beyond. */
  Bits c;
  c.put(0, 1);  /* sampleRatePresent */
  c.put(2, 7);  /* downmixInstructionsCount: 32 fits, 100 beyond */
  c.put(0, 1);  /* drcDescriptionBasicPresent */
  c.put(100, 6); /* drcCoefficientsUniDrcCount */
  c.put(0, 6);  /* drcInstructionsUniDrcCount */
  for (int i = 0; i < 40; i++) {
    c.put((uint32_t)(i * 71), 8);
  }
  const Bytes cfg = c.done();
  put("unidrc", "bound_counts.bin", cat({{0x00, 0x02, 0x00, 0x00}, cfg}));
  put("unidrc", "bound_counts_module.bin",
      cat({{0x02, 0x06, 0x11, 0x1f, (uint8_t)cfg.size(), 0x00}, cfg, {0x03, 0xFF, 0xFF, 0xFF}}));
  /* Gain payloads of every length against the built-in configuration. */
  Bytes gains;
  for (int len = 0; len < 24; len++) {
    gains.push_back((uint8_t)len);
    for (int i = 0; i < len; i++) {
      gains.push_back((uint8_t)(0xFF - i * 13));
    }
  }
  put("unidrc", "bound_gain_lengths.bin", cat({{0x01, 0x02, 0x00, 0x00}, gains}));
  put("unidrc", "bound_gain_lengths_module.bin", cat({{0x03, 0x01, 0x01, 0x1f}, gains}));
}

}  // namespace

int main(int argc, char** argv) {
  if (argc != 3) {
    fprintf(stderr, "usage: fuzz_seeds <tests/data> <out>\n");
    return 2;
  }
  const std::string data = argv[1];
  g_out = argv[2];
  mkdir(g_out.c_str(), 0755);
  for (const char* h : {"decoder", "asc", "encoder", "unidrc", "mps"}) {
    mkdir((g_out + "/" + h).c_str(), 0755);
  }
  DIR* d = opendir(data.c_str());
  if (!d) {
    fprintf(stderr, "fuzz_seeds: cannot read %s\n", data.c_str());
    return 1;
  }
  std::vector<std::string> names;
  while (dirent* e = readdir(d)) {
    const std::string n = e->d_name;
    if (n.size() > 5 && (n.compare(n.size() - 5, 5, ".loas") == 0 || n.compare(n.size() - 4, 4, ".aac") == 0)) {
      names.push_back(n);
    }
  }
  closedir(d);
  std::sort(names.begin(), names.end());
  Bytes first_lc_au;
  for (const std::string& n : names) {
    const Bytes s = read_file(data + "/" + n);
    seeds_of_stream(n, s);
    if (first_lc_au.empty() && n.compare(n.size() - 4, 4, ".aac") == 0) {
      const Units u = units_of(s, split_frames(s));
      if (!u.aus.empty()) {
        first_lc_au = u.aus[0];
      }
    }
  }
  if (first_lc_au.empty()) {
    first_lc_au = {0x21, 0x00, 0x03, 0x40, 0x68, 0x1C};
  }
  encoder_seeds();
  huffman_escape_seeds();
  hcr_seeds();
  latm_asclen_seeds(first_lc_au);
  element_count_seeds();
  high_rate_seeds();
  extension_length_seeds();
  usac_extension_seeds();
  adts_variant_seeds(first_lc_au);
  unidrc_bound_seeds();
  /* Empty and minimal inputs, so that the mutator starts from the
   * option bytes alone as well, and the reproducers of past findings
   * (tests/data/fuzz/<harness>/), so that every run replays them. */
  for (const char* h : {"decoder", "asc", "encoder", "unidrc", "mps"}) {
    if (DIR* past = opendir((data + "/fuzz/" + h).c_str())) {
      std::vector<std::string> found;
      while (dirent* e = readdir(past)) {
        if (e->d_name[0] != '.') {
          found.push_back(e->d_name);
        }
      }
      closedir(past);
      std::sort(found.begin(), found.end());
      for (const std::string& n : found) {
        put(h, "finding_" + n, read_file(data + "/fuzz/" + h + "/" + n));
      }
    }
    put(h, "empty.bin", {});
    put(h, "options_only.bin", {0xFF, 0xFF, 0xFF, 0xFF});
  }
  printf("fuzz_seeds: %d seeds under %s\n", g_written, g_out.c_str());
  return 0;
}
