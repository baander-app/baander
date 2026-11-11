/* Writes the seed corpus of the fuzz harnesses:
 *
 *   fuzz_seeds <tests/data> <out>
 *
 * creates <out>/decoder, <out>/asc, <out>/encoder, <out>/unidrc,
 * <out>/mps, <out>/flac_decoder and <out>/flac_encoder, each in its
 * harness's input layout (see the harness sources).  The seeds come from every committed stream under
 * tests/data (its first frames, a truncated copy, its access units with
 * their configuration, its configuration on its own, the DRC
 * configuration and gain payloads of the USAC streams), from encoder
 * inputs synthesised per profile, and from hand-built inputs for the
 * fields a decoder has to bound: the escape prefix of a Huffman
 * codeword, the HCR lengths, the ascLen of a StreamMuxConfig, the
 * element counts of a program config element, sampling frequencies
 * above 96 kHz, the extension lengths of an ELD and a surround
 * configuration, and configurations larger than any the codec writes.
 *
 * The FLAC seeds come from the committed streams under tests/data/flac
 * (whole, cut short, in small pieces, and as frames with their
 * STREAMINFO out of band), from streams the library's encoder writes
 * with tags and pictures, and from frames and metadata blocks built by
 * hand at the format's bounds: the largest block, the highest partition
 * order, a predictor of order 32, a side channel of 33 bits, a metadata
 * block longer than the input, tag and picture blocks whose inner
 * lengths lie, and ID3 tags around a stream. */
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
#include "flac.h"
#include "flac_internal.h"
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

/* ── FLAC ──────────────────────────────────────────────────────── */

/* The option bytes of the FLAC decoder harness. */
const uint8_t kFlacMd5 = 0x01;
const uint8_t kFlacKeepTags = 0x02;
const uint8_t kFlacSmallLimit = 0x04;
const uint8_t kFlacOutOfBand = 0x08;
const uint8_t kFlacChecksums = 0x10;

Bytes flac_block(int type, const Bytes& body, bool last, uint32_t claimed = 0xFFFFFFFFu) {
  const uint32_t length = claimed == 0xFFFFFFFFu ? (uint32_t)body.size() : claimed;
  Bytes out = {(uint8_t)((last ? 0x80 : 0) | type), (uint8_t)(length >> 16), (uint8_t)(length >> 8),
               (uint8_t)length};
  out.insert(out.end(), body.begin(), body.end());
  return out;
}

Bytes flac_streaminfo(int rate, int channels, int bits, int block, uint64_t total = 0) {
  BaacFlacStreamInfo info = {};
  info.min_block_size = block;
  info.max_block_size = block;
  info.sample_rate = rate;
  info.channels = channels;
  info.bits_per_sample = bits;
  info.total_samples = total;
  Bytes body(BAAC_FLAC_STREAMINFO_BYTES);
  flac_streaminfo_write(&info, body.data());
  return body;
}

/* The marker, STREAMINFO and `blocks`, the last of which closes the
 * metadata. */
Bytes flac_head(const Bytes& streaminfo, const std::vector<Bytes>& blocks = {}) {
  Bytes out = {'f', 'L', 'a', 'C'};
  const Bytes first = flac_block(FLAC_BLOCK_STREAMINFO, streaminfo, blocks.empty());
  out.insert(out.end(), first.begin(), first.end());
  for (size_t i = 0; i < blocks.size(); i++) {
    Bytes block = blocks[i];
    if (i + 1 == blocks.size()) {
      block[0] |= 0x80;
    }
    out.insert(out.end(), block.begin(), block.end());
  }
  return out;
}

/* One frame: its header, the subframes `body` writes, the padding to a
 * byte and the CRC-16. */
template <typename Body>
Bytes flac_frame(int block, int rate, int channels, int assignment, int bits, uint64_t number,
                 Body body) {
  FlacFrameHeader h = {};
  h.variable_block_size = false;
  h.block_size = block;
  h.sample_rate = rate;
  h.channels = channels;
  h.assignment = assignment;
  h.bits_per_sample = bits;
  h.number = number;
  Bytes out(FLAC_MAX_FRAME_HEADER_BYTES + (size_t)block * (size_t)channels * 9 + 4096);
  const int header = flac_frame_header_write(&h, out.data());
  if (header < 0) {
    fprintf(stderr, "fuzz_seeds: a FLAC frame header that cannot be written\n");
    exit(1);
  }
  FlacBitWriter w;
  flac_bitwriter_init(&w, out.data() + header, out.size() - (size_t)header - 2);
  body(&w);
  const size_t size = (size_t)header + flac_bitwriter_flush(&w);
  const uint16_t crc = flac_crc16(out.data(), size);
  out[size] = (uint8_t)(crc >> 8);
  out[size + 1] = (uint8_t)crc;
  out.resize(size + 2);
  return out;
}

/* Subframe headers: a zero bit, the type and the wasted-bits flag. */
void flac_constant(FlacBitWriter* w, int64_t value, int bps) {
  flac_bitwriter_write(w, 0x00, 8);
  flac_bitwriter_write_signed(w, value, bps);
}

/* A fixed predictor of order 0 whose residual is all zeros, in
 * 2^partition_order partitions of Rice parameter 0. */
void flac_zero_residual(FlacBitWriter* w, int block, int partition_order) {
  flac_bitwriter_write(w, 8 << 1, 8);
  flac_bitwriter_write(w, 0, 2);
  flac_bitwriter_write(w, (uint32_t)partition_order, 4);
  const int parts = 1 << partition_order;
  for (int p = 0; p < parts; p++) {
    flac_bitwriter_write(w, 0, 4);
    for (int i = 0; i < block / parts; i++) {
      flac_bitwriter_write(w, 1, 1);
    }
  }
}

/* A linear predictor of order 32 with the widest coefficients, on warm-up
 * samples at the ends of the range, and a small residual. */
void flac_order_32(FlacBitWriter* w, int block, int bps) {
  const int order = 32;
  flac_bitwriter_write(w, (uint32_t)(31 + order) << 1, 8);
  for (int i = 0; i < order; i++) {
    const int64_t top = (int64_t)1 << (bps - 1);
    flac_bitwriter_write_signed(w, (i & 1) ? top - 1 : -top, bps);
  }
  flac_bitwriter_write(w, 14, 4); /* a precision of 15 bits */
  flac_bitwriter_write(w, 14, 5);
  for (int i = 0; i < order; i++) {
    flac_bitwriter_write_signed(w, (i & 1) ? 16383 : -16384, 15);
  }
  flac_bitwriter_write(w, 0, 2);
  flac_bitwriter_write(w, 0, 4);
  flac_bitwriter_write(w, 3, 4);
  for (int i = order; i < block; i++) {
    flac_bitwriter_write(w, 0x8 | (uint32_t)(i & 7), 4); /* quotient 0, three low bits */
  }
}

Bytes le32_bytes(uint32_t v) {
  return {(uint8_t)v, (uint8_t)(v >> 8), (uint8_t)(v >> 16), (uint8_t)(v >> 24)};
}
Bytes be32_bytes(uint32_t v) {
  return {(uint8_t)(v >> 24), (uint8_t)(v >> 16), (uint8_t)(v >> 8), (uint8_t)v};
}
Bytes text_bytes(const char* s) { return Bytes(s, s + strlen(s)); }

/* A tag block and a picture block, each with one inner length to
 * choose. */
Bytes flac_tags(uint32_t vendor_length, uint32_t count, uint32_t field_length) {
  return cat({le32_bytes(vendor_length), text_bytes("seed"), le32_bytes(count),
              le32_bytes(field_length), text_bytes("TITLE=A seed"), le32_bytes(4),
              text_bytes("A=b")});
}
Bytes flac_picture(uint32_t mime_length, uint32_t description_length, uint32_t data_length) {
  return cat({be32_bytes(3), be32_bytes(mime_length), text_bytes("image/png"),
              be32_bytes(description_length), text_bytes("cover"), be32_bytes(32), be32_bytes(32),
              be32_bytes(24), be32_bytes(0), be32_bytes(data_length), Bytes(64, 0x89)});
}

/* Encodes `frames` samples per channel of a generated signal with the
 * library, tags and pictures included, and returns the stream with its
 * final header. */
Bytes flac_encoded(int rate, int channels, int bits, int level, int frames, bool tagged) {
  BaacFlacEncoder* enc = baac_flac_encoder_create(rate, channels, bits, level, nullptr);
  if (!enc) {
    fprintf(stderr, "fuzz_seeds: no FLAC encoder for %d Hz, %d channels, %d bits\n", rate, channels,
            bits);
    exit(1);
  }
  BaacFlacEncoderOptions options;
  baac_flac_encoder_options_init(&options);
  options.seek_table = 1;
  options.total_samples_hint = (uint64_t)frames;
  options.padding_bytes = tagged ? 16 : 0;
  const BaacFlacComment comments[3] = {{"TITLE=A seed", 12}, {"GENRE=x", 7}, {"GENRE=y", 7}};
  const Bytes cover(300, 0x42);
  BaacFlacPicture picture = {};
  picture.type = 3;
  picture.mime_type = "image/jpeg";
  picture.description = "cover";
  picture.data = cover.data();
  picture.data_bytes = (uint32_t)cover.size();
  if (tagged) {
    options.comments = comments;
    options.comment_count = 3;
    options.pictures = &picture;
    options.picture_count = 1;
  }
  baac_flac_encoder_set_options(enc, &options);
  std::vector<int32_t> pcm((size_t)frames * (size_t)channels);
  const double peak = 0.6 * (double)((1ll << (bits - 1)) - 1);
  unsigned seed = 7;
  for (int i = 0; i < frames; i++) {
    for (int c = 0; c < channels; c++) {
      seed = seed * 1664525u + 1013904223u;
      const double v =
          sin(i * (0.03 + 0.01 * c)) * 0.9 + ((double)(seed >> 8) / 8388608.0 - 1.0) * 0.05;
      pcm[(size_t)i * (size_t)channels + (size_t)c] = (int32_t)llround(v * peak);
    }
  }
  Bytes stream((size_t)baac_flac_encoder_header_bytes(enc));
  const int header = (int)stream.size();
  baac_flac_encoder_write_header(enc, stream.data(), header);
  Bytes out((size_t)baac_flac_encoder_max_output_bytes(enc, frames + 4096));
  int n = baac_flac_encoder_encode(enc, pcm.data(), frames, out.data(), (int)out.size());
  stream.insert(stream.end(), out.begin(), out.begin() + std::max(n, 0));
  n = baac_flac_encoder_finish(enc, out.data(), (int)out.size());
  stream.insert(stream.end(), out.begin(), out.begin() + std::max(n, 0));
  baac_flac_encoder_final_header(enc, stream.data(), header);
  baac_flac_encoder_destroy(enc);
  return stream;
}

/* Where the frames of a stream begin: behind the marker and the blocks. */
size_t flac_frames_at(const Bytes& s) {
  size_t pos = 4;
  while (pos + 4 <= s.size()) {
    const bool last = (s[pos] & 0x80) != 0;
    pos += 4 + ((size_t)s[pos + 1] << 16 | (size_t)s[pos + 2] << 8 | s[pos + 3]);
    if (last) {
      break;
    }
  }
  return std::min(pos, s.size());
}

void flac_decoder_seeds(const std::string& data) {
  const char* h = "flac_decoder";
  const uint8_t checked = kFlacMd5 | kFlacKeepTags | kFlacChecksums;
  /* The committed streams. */
  std::vector<std::string> names;
  if (DIR* d = opendir((data + "/flac").c_str())) {
    while (dirent* e = readdir(d)) {
      const std::string n = e->d_name;
      if (n.size() > 5 && n.compare(n.size() - 5, 5, ".flac") == 0) {
        names.push_back(n);
      }
    }
    closedir(d);
  }
  std::sort(names.begin(), names.end());
  for (const std::string& n : names) {
    const Bytes s = read_file(data + "/flac/" + n);
    const std::string base = n.substr(0, n.size() - 5);
    const Bytes head = slice(s, 0, 9000);
    put(h, base + "_whole.bin", cat({{checked, 0x07}, head}));
    put(h, base + "_pieces.bin", cat({{kFlacKeepTags, 0x02}, slice(s, 0, 3000)}));
    put(h, base + "_cut.bin", cat({{checked, 0x05}, slice(head, 0, head.size() * 2 / 3)}));
    const size_t frames = flac_frames_at(s);
    if (s.size() > frames && frames >= 42) {
      put(h, base + "_frames.bin",
          cat({{(uint8_t)(kFlacOutOfBand | kFlacChecksums | kFlacMd5), 0x04},
               slice(s, 8, 8 + BAAC_FLAC_STREAMINFO_BYTES),
               slice(s, frames, frames + 6000)}));
    }
  }
  /* The library's own streams, with tags and a picture. */
  for (int level : {0, 5, 8}) {
    const Bytes s = flac_encoded(44100, 2, 16, level, 9000, true);
    put(h, "enc_tagged_l" + std::to_string(level) + ".bin", cat({{checked, 0x05}, s}));
    put(h, "enc_tagged_small_limit_l" + std::to_string(level) + ".bin",
        cat({{(uint8_t)(kFlacKeepTags | kFlacSmallLimit | kFlacChecksums), 0x03}, s}));
  }
  put(h, "enc_32_bits.bin", cat({{checked, 0x06}, flac_encoded(96000, 2, 32, 5, 5000, false)}));
  put(h, "enc_8_channels.bin", cat({{checked, 0x06}, flac_encoded(48000, 8, 24, 8, 4500, false)}));

  /* The largest block there is: 65535 samples of eight channels at 32
   * bits, in constant subframes: 50 bytes for two megabytes of samples. */
  {
    const Bytes frame =
        flac_frame(65535, 0, 8, FLAC_ASSIGN_INDEPENDENT, 32, 0, [](FlacBitWriter* w) {
          for (int c = 0; c < 8; c++) {
            flac_constant(w, (c & 1) ? INT32_MIN : INT32_MAX, 32);
          }
        });
    const Bytes info = flac_streaminfo(1048575, 8, 32, 65535);
    put(h, "bound_largest_block.bin", cat({{checked, 0x07}, flac_head(info), frame, frame}));
    put(h, "bound_largest_block_small_buffer.bin", cat({{checked, 0x0F}, flac_head(info), frame}));
    /* The same frame against a STREAMINFO that allows less. */
    put(h, "bound_block_past_streaminfo.bin",
        cat({{checked, 0x07}, flac_head(flac_streaminfo(48000, 8, 32, 4096)), frame}));
  }
  /* The highest partition order: 32768 partitions of one sample. */
  {
    const Bytes frame = flac_frame(32768, 44100, 1, FLAC_ASSIGN_INDEPENDENT, 16, 0,
                                   [](FlacBitWriter* w) { flac_zero_residual(w, 32768, 15); });
    put(h, "bound_partition_order_15.bin",
        cat({{checked, 0x07}, flac_head(flac_streaminfo(44100, 1, 16, 32768)), frame}));
    /* An order the block size does not divide into. */
    const Bytes odd = flac_frame(4097, 44100, 1, FLAC_ASSIGN_INDEPENDENT, 16, 0,
                                 [](FlacBitWriter* w) { flac_zero_residual(w, 4096, 12); });
    put(h, "bound_partition_order_odd_block.bin",
        cat({{checked, 0x07}, flac_head(flac_streaminfo(44100, 1, 16, 4097)), odd}));
  }
  /* A predictor of order 32 with the widest coefficients: at 16 bits
   * and, beyond a 32-bit sum, at 32. */
  for (int bits : {16, 32}) {
    const Bytes frame =
        flac_frame(256, 0, 2, FLAC_ASSIGN_INDEPENDENT, bits, 0, [bits](FlacBitWriter* w) {
          flac_order_32(w, 256, bits);
          flac_order_32(w, 256, bits);
        });
    put(h, "bound_order_32_" + std::to_string(bits) + "_bits.bin",
        cat({{checked, 0x06}, flac_head(flac_streaminfo(96000, 2, bits, 256)), frame}));
  }
  /* A side channel of 33 bits at both ends of its range, in each of the
   * three stereo forms. */
  for (int assignment : {FLAC_ASSIGN_LEFT_SIDE, FLAC_ASSIGN_SIDE_RIGHT, FLAC_ASSIGN_MID_SIDE}) {
    const Bytes frame = flac_frame(4096, 0, 2, assignment, 32, 0, [assignment](FlacBitWriter* w) {
      const int64_t side = assignment == FLAC_ASSIGN_MID_SIDE ? -4294967295ll : 4294967295ll;
      if (assignment == FLAC_ASSIGN_SIDE_RIGHT) {
        flac_constant(w, side, 33);
        flac_constant(w, INT32_MIN, 32);
      } else {
        flac_constant(w, assignment == FLAC_ASSIGN_MID_SIDE ? -1 : INT32_MAX, 32);
        flac_constant(w, side, 33);
      }
    });
    put(h, "bound_side_33_bits_" + std::to_string(assignment) + ".bin",
        cat({{checked, 0x06}, flac_head(flac_streaminfo(192000, 2, 32, 4096)), frame}));
  }
  /* Metadata: a block longer than the input, and tag and picture
   * blocks whose inner lengths and counts reach past them, with the
   * decoder keeping tags and without. */
  const Bytes info = flac_streaminfo(44100, 2, 16, 1152);
  const Bytes plain = flac_encoded(44100, 2, 16, 3, 3000, false);
  const Bytes frames = slice(plain, flac_frames_at(plain), plain.size());
  put(h, "bound_block_past_input.bin",
      cat({{checked, 0x05},
           flac_head(info, {flac_block(FLAC_BLOCK_PICTURE, Bytes(200, 1), true, 0xFFFFFF)})}));
  put(h, "bound_padding_past_input.bin",
      cat({{checked, 0x05},
           flac_head(info, {flac_block(FLAC_BLOCK_PADDING, Bytes(10, 0), true, 0x800000)}),
           frames}));
  struct Lie {
    const char* name;
    int type;
    Bytes body;
  };
  const Lie lies[] = {
      {"tags_vendor", FLAC_BLOCK_VORBIS_COMMENT, flac_tags(0xFFFFFFFFu, 2, 12)},
      {"tags_vendor_past_block", FLAC_BLOCK_VORBIS_COMMENT, flac_tags(4000, 2, 12)},
      {"tags_count", FLAC_BLOCK_VORBIS_COMMENT, flac_tags(4, 0xFFFFFFFFu, 12)},
      {"tags_count_one_more", FLAC_BLOCK_VORBIS_COMMENT, flac_tags(4, 3, 12)},
      {"tags_field", FLAC_BLOCK_VORBIS_COMMENT, flac_tags(4, 2, 0x00FFFFFFu)},
      {"tags_sound", FLAC_BLOCK_VORBIS_COMMENT, flac_tags(4, 2, 12)},
      {"picture_mime", FLAC_BLOCK_PICTURE, flac_picture(0xFFFFFFFFu, 5, 64)},
      {"picture_description", FLAC_BLOCK_PICTURE, flac_picture(9, 0x7FFFFFFFu, 64)},
      {"picture_data", FLAC_BLOCK_PICTURE, flac_picture(9, 5, 0x00FFFFFFu)},
      {"picture_data_short", FLAC_BLOCK_PICTURE, flac_picture(9, 5, 3)},
      {"picture_sound", FLAC_BLOCK_PICTURE, flac_picture(9, 5, 64)},
  };
  for (const Lie& lie : lies) {
    const Bytes stream = cat({flac_head(info, {flac_block(lie.type, lie.body, false),
                                               flac_block(FLAC_BLOCK_PADDING, Bytes(8, 0), true)}),
                              frames});
    put(h, std::string("bound_") + lie.name + ".bin", cat({{checked, 0x05}, stream}));
    put(h, std::string("bound_") + lie.name + "_bytewise.bin",
        cat({{(uint8_t)(kFlacKeepTags | kFlacSmallLimit), 0x00}, stream}));
    put(h, std::string("bound_") + lie.name + "_skipped.bin",
        cat({{kFlacChecksums, 0x05}, stream}));
  }
  /* ID3 tags around a stream, and a stream without its marker. */
  const Bytes id3v2 = cat({{'I', 'D', '3', 4, 0, 0, 0, 0, 0x02, 0x00}, Bytes(256, 0xFF)});
  Bytes id3v1(128, 0);
  memcpy(id3v1.data(), "TAG", 3);
  put(h, "id3v2_front.bin", cat({{checked, 0x05}, id3v2, plain}));
  put(h, "id3v1_behind.bin", cat({{checked, 0x04}, plain, id3v1}));
  put(h, "id3v2_length_past_input.bin",
      cat({{checked, 0x05}, {'I', 'D', '3', 4, 0, 0x10, 0x7F, 0x7F, 0x7F, 0x7F}, plain}));
  put(h, "frames_only.bin", cat({{checked, 0x05}, frames}));
  put(h, "seek_reset.bin", cat({{(uint8_t)(checked | 0x20), 0x05}, plain}));
  put(h, "two_passes.bin", cat({{(uint8_t)(checked | 0x40), 0x03}, plain}));
  put(h, "no_end_of_input.bin", cat({{(uint8_t)(checked | 0x80), 0x04}, plain}));
}

void flac_encoder_seeds() {
  const char* h = "flac_encoder";
  struct Case {
    const char* name;
    uint8_t level, channels, bits, rate, flags, shape;
    int frames;
  };
  /* bits: 0 8, 1 12, 2 16, 3 20, 4 24, 5 32; rate: an index into the
   * harness's table. */
  const Case cases[] = {
      {"l0_stereo_16", 0, 2, 2, 0, 0x13, 0x07, 3000},
      {"l1_stereo_16", 1, 2, 2, 0, 0x10, 0x04, 3000},
      {"l2_stereo_24", 2, 2, 4, 1, 0x13, 0x05, 3000},
      {"l3_mono_8", 3, 1, 0, 4, 0x13, 0x02, 5000},
      {"l4_stereo_12", 4, 2, 1, 5, 0x10, 0x06, 9000},
      {"l5_stereo_16", 5, 2, 2, 0, 0x13, 0x05, 9000},
      {"l6_stereo_20", 6, 2, 3, 2, 0x1B, 0x05, 9000},
      {"l7_6ch_24", 7, 6, 4, 1, 0x13, 0x06, 5000},
      {"l8_stereo_32", 8, 2, 5, 3, 0x13, 0x05, 9000},
      {"l8_8ch_16", 8, 8, 2, 1, 0x13, 0x07, 4500},
      {"l5_wasted_bits", 5, 2, 4, 1, 0x13, 0x1D, 5000},
      {"l5_same_channels", 5, 2, 2, 0, 0x13, 0x45, 5000},
      {"l8_constant", 8, 2, 2, 0, 0x13, 0x85, 5000},
      {"l5_piped", 5, 2, 2, 0, 0x00, 0x05, 5000},
      {"l5_hint_too_small", 5, 2, 2, 0, 0x17, 0x05, 9000},
      {"l5_two_streams", 5, 2, 2, 0, 0x53, 0x03, 2000},
      {"l5_bytewise_decode", 5, 2, 2, 0, 0x33, 0x00, 1500},
      {"l5_rate_655350", 5, 1, 2, 6, 0x13, 0x05, 3000},
      {"l5_rate_1", 5, 1, 2, 7, 0x13, 0x05, 3000},
      {"level_9", 9, 2, 2, 0, 0x13, 0x05, 100},
      {"nine_channels", 5, 9, 2, 0, 0x13, 0x05, 100},
      {"seventeen_bits", 5, 2, 6, 0, 0x13, 0x05, 100},
      {"rate_700000", 5, 2, 2, 10, 0x13, 0x05, 100},
      {"empty_input", 5, 2, 2, 0, 0x13, 0x05, 0},
      {"one_sample", 8, 2, 2, 0, 0x13, 0x05, 1},
  };
  static const int kWidth[8] = {1, 2, 2, 3, 3, 4, 3, 1};
  for (const Case& c : cases) {
    Bytes in;
    for (uint8_t option : {c.level, c.channels, c.bits, c.rate, c.flags, c.shape}) {
      in.push_back(option);
    }
    in.push_back(0); /* no tags */
    in.push_back(0); /* no pictures */
    unsigned seed = 3;
    for (int i = 0; i < c.frames * c.channels; i++) {
      seed = seed * 1664525u + 1013904223u;
      const double v = sin(i * 0.011) * 0.7 + ((double)(seed >> 8) / 8388608.0 - 1.0) * 0.1;
      const int32_t s = (int32_t)llround(v * 2147483000.0) >> (32 - 8 * kWidth[c.bits & 7]);
      for (int b = 0; b < kWidth[c.bits & 7]; b++) {
        in.push_back((uint8_t)((uint32_t)s >> (8 * b)));
      }
    }
    put(h, std::string(c.name) + ".bin", in);
  }
  /* Tags and pictures: sound ones, and each kind the format refuses. */
  struct Tagged {
    const char* name;
    std::vector<std::string> tags;
    uint8_t picture_type;
    const char* mime;
    const char* description;
    int pictures;
  };
  const Tagged tagged[] = {
      {"sound", {"TITLE=A seed", "GENRE=x", "GENRE=y"}, 3, "image/jpeg", "cover", 1},
      {"two_pictures", {"A=b"}, 4, "image/png", "", 2},
      {"no_equals", {"TITLE"}, 3, "image/jpeg", "", 0},
      {"empty_name", {"=x"}, 3, "image/jpeg", "", 0},
      {"name_not_ascii", {"T\xC3\xA4G=x"}, 3, "image/jpeg", "", 0},
      {"value_not_utf8", {"A=\xFF\xFE"}, 3, "image/jpeg", "", 0},
      {"value_utf8", {"A=\xE2\x99\xAB \xF0\x9F\x8E\xBB"}, 3, "image/jpeg", "", 0},
      {"picture_type_21", {}, 21, "image/jpeg", "", 1},
      {"two_icons", {}, 1, "image/png", "", 2},
      {"mime_not_ascii", {}, 3, "image/\xC3\xA9", "", 1},
      {"description_not_utf8", {}, 3, "image/png", "\xC3", 1},
  };
  for (const Tagged& t : tagged) {
    Bytes in;
    for (int option : {5, 2, 2, 0, 0x93, 0x05}) {
      in.push_back((uint8_t)option);
    }
    in.push_back((uint8_t)t.tags.size());
    for (const std::string& tag : t.tags) {
      in.push_back((uint8_t)tag.size());
      in.insert(in.end(), tag.begin(), tag.end());
    }
    in.push_back((uint8_t)t.pictures);
    for (int i = 0; i < t.pictures; i++) {
      in.push_back(t.picture_type);
      in.push_back((uint8_t)strlen(t.mime));
      in.insert(in.end(), t.mime, t.mime + strlen(t.mime));
      in.push_back((uint8_t)strlen(t.description));
      in.insert(in.end(), t.description, t.description + strlen(t.description));
      in.push_back(0);
      in.push_back(40);
      in.insert(in.end(), 40, (uint8_t)(0x30 + i));
    }
    for (int i = 0; i < 2500 * 2; i++) {
      const int32_t s = (int32_t)llround(sin(i * 0.02) * 20000.0);
      in.push_back((uint8_t)s);
      in.push_back((uint8_t)(s >> 8));
    }
    put(h, std::string("tags_") + t.name + ".bin", in);
  }
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
  for (const char* h :
       {"decoder", "asc", "encoder", "unidrc", "mps", "flac_decoder", "flac_encoder"}) {
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
  flac_decoder_seeds(data);
  flac_encoder_seeds();
  /* Empty and minimal inputs, so that the mutator starts from the
   * option bytes alone as well, and the reproducers of past findings
   * (tests/data/fuzz/<harness>/), so that every run replays them. */
  for (const char* h :
       {"decoder", "asc", "encoder", "unidrc", "mps", "flac_decoder", "flac_encoder"}) {
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
