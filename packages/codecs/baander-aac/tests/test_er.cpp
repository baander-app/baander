/* The error resilience tools of the error resilient object types: virtual
 * codebook 11, reversible variable length coded scalefactors and Huffman
 * codeword reordering.  No other decoder at hand implements them (FFmpeg
 * decodes the error resilient framing only), so the streams are
 * hand-built: a test-only writer produces each tool's syntax from known
 * scalefactors and spectra, the unit tests read it back through the tool
 * readers, also after corruption, and the end-to-end tests decode whole
 * error resilient AAC-LC frames over LOAS with each tool on and compare
 * the PCM with the plain decode of the same frames. */
#include <algorithm>

#include "asc.h"
#include "bitstream.h"
#include "er.h"
#include "ics.h"
#include "latm.h"
#include "mdct.h"
#include "test_util.h"

namespace {

constexpr int kRate = 48000;
constexpr int kRateIndex = 3;

unsigned g_seed = 0x2468ace1u;
int rnd(int n) {
  g_seed = g_seed * 1664525u + 1013904223u;
  return (int)((g_seed >> 8) % (unsigned)n);
}

/* ── bit helpers ────────────────────────────────────────────────── */

struct Bits {
  std::vector<int> b;
  void put(uint32_t v, int n) {
    for (int i = n - 1; i >= 0; i--) {
      b.push_back((int)((v >> i) & 1u));
    }
  }
  void append(const Bits& o) { b.insert(b.end(), o.b.begin(), o.b.end()); }
  int size() const { return (int)b.size(); }
  std::vector<uint8_t> bytes() const {
    std::vector<uint8_t> out((b.size() + 7) / 8, 0);
    for (size_t i = 0; i < b.size(); i++) {
      out[i / 8] |= (uint8_t)(b[i] << (7 - (i % 8)));
    }
    return out;
  }
};

/* The bits a codeword writer produced. */
Bits huffman_bits(int book, const int* v) {
  uint8_t buf[32];
  AacBitWriter w;
  aac_bitwriter_init(&w, buf, sizeof(buf));
  const int ret = aac_bitwriter_write_huffman(&w, book, v);
  CHECK(ret == 0, "codeword of book %d (%d %d %d %d)", book, v[0], v[1], v[2], v[3]);
  Bits out;
  const int n = aac_bitwriter_bits_written(&w);
  for (int i = 0; i < n; i++) {
    out.b.push_back((buf[i / 8] >> (7 - (i % 8))) & 1);
  }
  return out;
}

Bits scalefactor_bits(int diff) {
  uint8_t buf[8];
  AacBitWriter w;
  aac_bitwriter_init(&w, buf, sizeof(buf));
  aac_bitwriter_write_scalefactor(&w, diff);
  Bits out;
  const int n = aac_bitwriter_bits_written(&w);
  for (int i = 0; i < n; i++) {
    out.b.push_back((buf[i / 8] >> (7 - (i % 8))) & 1);
  }
  return out;
}

/* ── a frame's content ─────────────────────────────────────────── */

struct Frame {
  AacIcsInfo ics;
  int global_gain;
  int band_type[AAC_MAX_BANDS]; /* the plain section codebooks (11 for escapes) */
  int sf[AAC_MAX_BANDS];
  int quant[1024];
  /* The section codebooks with aacSectionDataResilienceFlag: escape
   * bands name the smallest virtual codebook that fits their values. */
  int er_band_type[AAC_MAX_BANDS];
};

int vcb_for(int max_mag) {
  for (int cb = AAC_CB_VCB11_FIRST; cb <= AAC_CB_VCB11_LAST; cb++) {
    if (aac_vcb11_limit(cb) >= max_mag) {
      return cb;
    }
  }
  return AAC_CB_VCB11_LAST;
}

int band_index(const AacIcsInfo* ics, int g, int b) { return g * ics->max_sfb + b; }

/* A random value a codebook can carry; the escape book spreads up to
 * 2047, the largest value the virtual codebooks can promise, so that
 * escape sequences of several lengths occur. */
int random_value(int cb) {
  if (cb == AAC_CB_ESCAPE) {
    const int pick = rnd(10);
    const int mag = pick < 5 ? rnd(16) : (pick < 8 ? 16 + rnd(200) : rnd(2048));
    return rnd(2) ? -mag : mag;
  }
  const int m = aac_codebook_info[cb].max_val;
  return rnd(2 * m + 1) - m;
}

/* Fills a frame: window layout, codebooks per band (zero, the eleven
 * spectral books, noise and, for the right channel of a pair, intensity),
 * scalefactors with the occasional large step and random values. */
Frame make_frame(int window_sequence, int right_channel) {
  Frame f = {};
  const int grouping = window_sequence == AAC_WIN_EIGHT_SHORT ? 0x5a : 0;
  aac_ics_layout(&f.ics, kRateIndex, 1024, window_sequence, grouping);
  f.ics.window_shape = rnd(2);
  f.ics.max_sfb = window_sequence == AAC_WIN_EIGHT_SHORT ? 12 : 40;
  f.global_gain = 80 + rnd(30);
  int sf = f.global_gain;
  int noise = f.global_gain - 90;
  int is_pos = 0;
  bool first_noise = true;
  for (int g = 0; g < f.ics.num_window_groups; g++) {
    int cb = 0;
    int run = 0;
    for (int b = 0; b < f.ics.max_sfb; b++) {
      const int idx = band_index(&f.ics, g, b);
      if (run-- <= 0) {
        const int pick = rnd(16);
        cb = pick == 0 ? 0 : (pick <= 11 ? pick : (pick == 12 ? AAC_CB_NOISE : 0));
        if (right_channel && pick >= 13 && b > 4) {
          cb = pick == 13 ? AAC_CB_INTENSITY : AAC_CB_INTENSITY2;
        }
        if (cb == 0 && pick >= 13) {
          cb = 1 + rnd(11);
        }
        run = rnd(3);
      }
      f.band_type[idx] = cb;
      f.er_band_type[idx] = cb;
      if (cb == 0) {
        continue;
      }
      /* The scalefactor: small steps, now and then a jump beyond the
       * reversible codebook's range. */
      const int step = rnd(10) == 0 ? (rnd(2) ? 1 : -1) * (8 + rnd(40)) : rnd(7) - 3;
      if (cb == AAC_CB_NOISE) {
        noise = first_noise ? noise + rnd(21) - 10 : std::max(-100, std::min(155, noise + step));
        first_noise = false;
        f.sf[idx] = noise;
        continue;
      }
      if (cb == AAC_CB_INTENSITY || cb == AAC_CB_INTENSITY2) {
        is_pos = std::max(-20, std::min(20, is_pos + step));
        f.sf[idx] = is_pos;
        continue;
      }
      sf = std::max(40, std::min(140, sf + step));
      f.sf[idx] = sf;
      int max_mag = 0;
      int win = 0;
      for (int gg = 0; gg < g; gg++) {
        win += f.ics.group_len[gg];
      }
      for (int w = 0; w < f.ics.group_len[g]; w++) {
        for (int k = f.ics.swb_offset[b]; k < f.ics.swb_offset[b + 1]; k++) {
          const int v = random_value(cb);
          f.quant[(win + w) * 128 + k] = v;
          max_mag = std::max(max_mag, v < 0 ? -v : v);
        }
      }
      if (cb == AAC_CB_ESCAPE) {
        f.er_band_type[idx] = vcb_for(max_mag);
      }
    }
  }
  return f;
}

/* ── writers ───────────────────────────────────────────────────── */

void write_ics_info(Bits* o, const AacIcsInfo* ics) {
  o->put(0, 1);
  o->put((uint32_t)ics->window_sequence, 2);
  o->put((uint32_t)ics->window_shape, 1);
  if (ics->window_sequence == AAC_WIN_EIGHT_SHORT) {
    o->put((uint32_t)ics->max_sfb, 4);
    uint32_t grouping = 0;
    int w = 0;
    for (int g = 0; g < ics->num_window_groups; g++) {
      for (int i = 0; i < ics->group_len[g]; i++, w++) {
        if (i > 0) {
          grouping |= 1u << (7 - w);
        }
      }
    }
    o->put(grouping, 7);
  } else {
    o->put((uint32_t)ics->max_sfb, 6);
    o->put(0, 1); /* predictor_data_present */
  }
}

void write_section_length(Bits* o, int len, int bits) {
  const int esc = (1 << bits) - 1;
  while (len >= esc) {
    o->put((uint32_t)esc, bits);
    len -= esc;
  }
  o->put((uint32_t)len, bits);
}

/* section_data(), plain or with the section data resilience. */
void write_sections(Bits* o, const AacIcsInfo* ics, const int* band_type, int er) {
  const int bits = ics->num_windows == 8 ? 3 : 5;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < ics->max_sfb;) {
      const int cb = band_type[band_index(ics, g, b)];
      int end = b + 1;
      const bool single = er && (cb == AAC_CB_ESCAPE || cb >= AAC_CB_VCB11_FIRST);
      while (!single && end < ics->max_sfb && band_type[band_index(ics, g, end)] == cb) {
        end++;
      }
      o->put((uint32_t)cb, er ? 5 : 4);
      if (!single) {
        write_section_length(o, end - b, bits);
      }
      b = end;
    }
  }
}

/* The scalefactor differences in syntax order, with the first noise
 * energy as its plain 9-bit value. */
struct SfItem {
  int idx;
  int diff;
  int pcm; /* the first noise band sends its energy as 9 bits */
  int kind;
};

std::vector<SfItem> sf_items(const AacIcsInfo* ics, const int* band_type, int global_gain,
                             const int* sf) {
  std::vector<SfItem> items;
  int cur = global_gain, noise = global_gain - 90, is_pos = 0;
  bool first_noise = true;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < ics->max_sfb; b++) {
      const int idx = band_index(ics, g, b);
      const int cb = band_type[idx];
      if (cb == 0) {
        continue;
      }
      SfItem it = {idx, 0, 0, 0};
      if (cb == AAC_CB_NOISE) {
        it.kind = 1;
        if (first_noise) {
          it.pcm = 1;
          it.diff = sf[idx] - noise + 256;
          first_noise = false;
        } else {
          it.diff = sf[idx] - noise;
        }
        noise = sf[idx];
      } else if (cb == AAC_CB_INTENSITY || cb == AAC_CB_INTENSITY2) {
        it.kind = 2;
        it.diff = sf[idx] - is_pos;
        is_pos = sf[idx];
      } else {
        it.diff = sf[idx] - cur;
        cur = sf[idx];
      }
      items.push_back(it);
    }
  }
  return items;
}

void write_plain_scalefactors(Bits* o, const AacIcsInfo* ics, const int* band_type,
                              int global_gain, const int* sf) {
  for (const SfItem& it : sf_items(ics, band_type, global_gain, sf)) {
    if (it.pcm) {
      o->put((uint32_t)it.diff, 9);
    } else {
      o->append(scalefactor_bits(it.diff));
    }
  }
}

/* rvlc_scale_factor_data(). */
void write_rvlc_scalefactors(Bits* o, const AacIcsInfo* ics, const int* band_type,
                             int global_gain, const int* sf) {
  const std::vector<SfItem> items = sf_items(ics, band_type, global_gain, sf);
  Bits codes, escapes;
  int rev_global_gain = global_gain;
  int last_noise = global_gain - 90;
  bool noise_used = false;
  for (const SfItem& it : items) {
    if (it.kind == 0) {
      rev_global_gain = sf[it.idx];
    } else if (it.kind == 1) {
      last_noise = sf[it.idx];
      noise_used = true;
    }
    if (it.pcm) {
      codes.put((uint32_t)it.diff, 9);
      continue;
    }
    /* A difference of 7 or more in magnitude is the escape marker plus
     * the excess as an escape value. */
    int d = it.diff;
    if (d >= AAC_RVLC_ESCAPE || d <= -AAC_RVLC_ESCAPE) {
      const int e = (d > 0 ? d : -d) - AAC_RVLC_ESCAPE;
      escapes.put(aac_rvlc_escape_code[e], aac_rvlc_escape_len[e]);
      d = d > 0 ? AAC_RVLC_ESCAPE : -AAC_RVLC_ESCAPE;
    }
    codes.put(aac_rvlc_code[d + AAC_RVLC_ESCAPE], aac_rvlc_len[d + AAC_RVLC_ESCAPE]);
  }
  if (noise_used) {
    codes.put((uint32_t)(last_noise - (global_gain - 90) + 256), 9);
  }
  o->put(0, 1); /* sf_concealment */
  o->put((uint32_t)rev_global_gain, 8);
  o->put((uint32_t)codes.size(), ics->num_windows == 8 ? 11 : 9);
  o->append(codes);
  o->put(escapes.size() > 0 ? 1u : 0u, 1);
  if (escapes.size() > 0) {
    o->put((uint32_t)escapes.size(), 8);
    o->append(escapes);
  }
}

void write_plain_spectrum(Bits* o, const AacIcsInfo* ics, const int* band_type,
                          const int* quant) {
  int win = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < ics->max_sfb; b++) {
      const int cb = band_type[band_index(ics, g, b)];
      if (!aac_cb_is_spectral(cb)) {
        continue;
      }
      const int book = aac_cb_huffman_book(cb);
      const int dim = aac_codebook_info[book].dim;
      for (int w = 0; w < ics->group_len[g]; w++) {
        for (int k = ics->swb_offset[b]; k < ics->swb_offset[b + 1]; k += dim) {
          o->append(huffman_bits(book, quant + (win + w) * 128 + k));
        }
      }
    }
    win += ics->group_len[g];
  }
}

/* length_of_reordered_spectral_data, length_of_longest_codeword and
 * reordered_spectral_data(): the codewords in presorted order, the
 * priority codewords at the segment starts, the rest in sets that
 * alternate direction and hop to the next segment when one is full. */
void write_hcr_spectrum(Bits* o, const AacIcsInfo* ics, const int* band_type, const int* quant) {
  int dest[512], book[512];
  const int count = aac_er_hcr_codeword_order(ics, band_type, dest, book);
  std::vector<Bits> cw((size_t)count);
  int total = 0, longest = 0;
  for (int i = 0; i < count; i++) {
    cw[i] = huffman_bits(aac_cb_huffman_book(book[i]), quant + dest[i]);
    total += cw[i].size();
    longest = std::max(longest, cw[i].size());
  }
  o->put((uint32_t)total, 14);
  o->put((uint32_t)longest, 6);
  if (count == 0) {
    return;
  }
  const int num_segments = total / longest;
  std::vector<int> bits((size_t)total, 0), left(num_segments), right(num_segments);
  for (int i = 0; i < num_segments; i++) {
    left[i] = i * longest;
    right[i] = i == num_segments - 1 ? total : left[i] + longest;
  }
  std::vector<int> written((size_t)count, 0);
  for (int i = 0; i < num_segments; i++) {
    for (int bit : cw[i].b) {
      bits[left[i]++] = bit;
    }
    written[i] = cw[i].size();
  }
  for (int set = 1; set * num_segments < count; set++) {
    const int base = set * num_segments;
    const int size = std::min(num_segments, count - base);
    const int backward = set & 1;
    for (int trial = 0; trial < num_segments; trial++) {
      for (int k = 0; k < size; k++) {
        const int i = base + k;
        const int seg = (k + trial) % num_segments;
        while (written[i] < cw[i].size() && left[seg] < right[seg]) {
          const int bit = cw[i].b[written[i]++];
          if (backward) {
            bits[--right[seg]] = bit;
          } else {
            bits[left[seg]++] = bit;
          }
        }
      }
    }
    for (int k = 0; k < size; k++) {
      CHECK(written[base + k] == cw[base + k].size(), "codeword %d placed", base + k);
    }
  }
  for (int bit : bits) {
    o->b.push_back(bit);
  }
}

struct Tools {
  int section, scalefactor, spectral;
};

/* individual_channel_stream() of the error resilient syntax. */
void write_ics(Bits* o, const Frame& f, const Tools& t, int common_window) {
  const int* band_type = t.section ? f.er_band_type : f.band_type;
  o->put((uint32_t)f.global_gain, 8);
  if (!common_window) {
    write_ics_info(o, &f.ics);
  }
  write_sections(o, &f.ics, band_type, t.section);
  if (t.scalefactor) {
    write_rvlc_scalefactors(o, &f.ics, band_type, f.global_gain, f.sf);
  } else {
    write_plain_scalefactors(o, &f.ics, band_type, f.global_gain, f.sf);
  }
  o->put(0, 1); /* pulse_data_present */
  o->put(0, 1); /* tns_data_present */
  o->put(0, 1); /* gain_control_data_present */
  if (t.spectral) {
    write_hcr_spectrum(o, &f.ics, band_type, f.quant);
  } else {
    write_plain_spectrum(o, &f.ics, band_type, f.quant);
  }
}

/* One er_raw_data_block() of a mono (SCE) or stereo (CPE with a common
 * window and M/S on every other band) stream. */
std::vector<uint8_t> write_block(const std::vector<Frame>& chans, const Tools& t) {
  Bits o;
  o.put(0, 4); /* element_instance_tag */
  if (chans.size() == 1) {
    write_ics(&o, chans[0], t, 0);
  } else {
    o.put(1, 1); /* common_window */
    write_ics_info(&o, &chans[0].ics);
    o.put(1, 2); /* ms_mask_present: per band */
    const int nbands = chans[0].ics.num_window_groups * chans[0].ics.max_sfb;
    for (int i = 0; i < nbands; i++) {
      o.put((uint32_t)(i & 1), 1);
    }
    write_ics(&o, chans[0], t, 1);
    write_ics(&o, chans[1], t, 1);
  }
  return o.bytes();
}

std::vector<uint8_t> write_asc(int channels, const Tools& t) {
  AacAsc asc;
  aac_asc_init(&asc, kRate, channels);
  asc.aot = AAC_AOT_ER_LC;
  asc.section_resilience = t.section;
  asc.scalefactor_resilience = t.scalefactor;
  asc.spectral_resilience = t.spectral;
  uint8_t buf[AAC_ASC_MAX_BYTES];
  const int len = aac_asc_write(&asc, buf, sizeof(buf));
  CHECK(len > 0, "ASC written");
  return std::vector<uint8_t>(buf, buf + std::max(len, 0));
}

std::vector<uint8_t> loas_stream(const std::vector<uint8_t>& asc_bytes,
                                 const std::vector<std::vector<uint8_t>>& aus) {
  AacAsc asc;
  CHECK(aac_asc_parse(asc_bytes.data(), (int)asc_bytes.size(), &asc) == 0, "ASC parses");
  AacLatmConfig cfg;
  aac_latm_config_init(&cfg, &asc, 0);
  std::vector<uint8_t> out;
  std::vector<uint8_t> frame(16384);
  for (const auto& au : aus) {
    const int n = aac_loas_write_frame(&cfg, 0, au.data(), (int)au.size(), frame.data(),
                                       (int)frame.size());
    CHECK(n > 0, "LOAS frame");
    out.insert(out.end(), frame.begin(), frame.begin() + std::max(n, 0));
  }
  return out;
}

struct Decoded {
  std::vector<float> pcm;
  int errors;
  int concealed;
};

Decoded decode_loas(const std::vector<uint8_t>& stream, AacConcealment conceal) {
  Decoded d = {{}, 0, 0};
  AacDecoderHandle dec = aac_decoder_create(kRate, 0);
  aac_decoder_set_concealment(dec, conceal);
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  for (size_t pos = 0; pos < stream.size();) {
    const int taken =
        aac_decoder_feed(dec, stream.data() + pos, (int)std::min((size_t)500, stream.size() - pos));
    if (taken <= 0) {
      d.errors++;
      break;
    }
    pos += (size_t)taken;
    for (;;) {
      const int got = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
      if (got == 0) {
        break;
      }
      if (got < 0) {
        d.errors++;
        continue;
      }
      d.concealed += aac_decoder_last_frame_concealed(dec);
      d.pcm.insert(d.pcm.end(), pcm.begin(), pcm.begin() + (size_t)got * aac_decoder_channels(dec));
    }
  }
  CHECK(aac_decoder_object_type(dec) == AAC_AOT_ER_LC, "object type %d",
        aac_decoder_object_type(dec));
  aac_decoder_destroy(dec);
  return d;
}

/* ── virtual codebook 11 ───────────────────────────────────────── */

void test_vcb11_limits() {
  const int want[16] = {15,  31,  47,  63,  95,  127, 159,  191,
                        223, 255, 319, 383, 511, 767, 1023, 2047};
  for (int cb = 16; cb <= 31; cb++) {
    CHECK(aac_vcb11_limit(cb) == want[cb - 16], "limit of %d", cb);
    CHECK(aac_cb_is_spectral(cb) && aac_cb_huffman_book(cb) == 11, "book %d is spectral", cb);
  }
  CHECK(aac_vcb11_limit(11) == -1 && aac_vcb11_limit(15) == -1 && aac_vcb11_limit(32) == -1,
        "no limit outside 16..31");
  CHECK(!aac_cb_is_spectral(0) && !aac_cb_is_spectral(13) && !aac_cb_is_spectral(15),
        "zero, noise and intensity are not spectral");
}

void test_vcb11_values() {
  /* Every pair up to the limit reads back; one above it is refused. */
  for (int cb = 16; cb <= 31; cb++) {
    const int limit = aac_vcb11_limit(cb);
    const int pairs[4][2] = {{limit, -limit}, {0, limit}, {-3, 7}, {limit / 2, -1}};
    for (auto& p : pairs) {
      const Bits bits = huffman_bits(11, p);
      const std::vector<uint8_t> bytes = bits.bytes();
      AacBitReader r;
      aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
      int out[2] = {0, 0};
      CHECK(aac_er_read_vcb11(&r, cb, out) == 0 && out[0] == p[0] && out[1] == p[1] &&
                r.pos == bits.size(),
            "book %d reads (%d, %d): got (%d, %d)", cb, p[0], p[1], out[0], out[1]);
    }
    const int over[2] = {limit + 1, 0};
    const std::vector<uint8_t> bytes = huffman_bits(11, over).bytes();
    AacBitReader r;
    aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
    int out[2];
    CHECK(aac_er_read_vcb11(&r, cb, out) == AAC_ERR_DECODE, "book %d refuses %d", cb, limit + 1);
  }
}

void test_er_section_data() {
  /* Long window: runs of plain books with a 31-long escape run, single
   * bands of the escape and a virtual codebook, noise and zero. */
  AacIcsInfo ics;
  aac_ics_layout(&ics, kRateIndex, 1024, AAC_WIN_ONLY_LONG, 0);
  ics.max_sfb = 45;
  int band_type[AAC_MAX_BANDS] = {};
  for (int b = 0; b < 45; b++) {
    band_type[b] = b < 32 ? 3 : (b == 32 ? 11 : (b == 33 ? 11 : (b == 34 ? 20 : (b < 40 ? 13 : 0))));
  }
  Bits o;
  write_sections(&o, &ics, band_type, 1);
  const std::vector<uint8_t> bytes = o.bytes();
  AacBitReader r;
  aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
  int got[AAC_MAX_BANDS] = {};
  CHECK(aac_er_read_section_data(&r, &ics, got) == 0 && r.pos == o.size(),
        "ER section data reads (%d of %d bits)", r.pos, o.size());
  CHECK(memcmp(got, band_type, 45 * sizeof(int)) == 0, "ER section codebooks");
  /* Short windows with groups. */
  aac_ics_layout(&ics, kRateIndex, 1024, AAC_WIN_EIGHT_SHORT, 0x5a);
  ics.max_sfb = 10;
  for (int g = 0; g < ics.num_window_groups; g++) {
    for (int b = 0; b < 10; b++) {
      band_type[g * 10 + b] = (b + g) % 4 == 0 ? 25 + g : (b < 7 ? 5 : 0);
    }
  }
  Bits o2;
  write_sections(&o2, &ics, band_type, 1);
  const std::vector<uint8_t> bytes2 = o2.bytes();
  aac_bitreader_init(&r, bytes2.data(), (int)bytes2.size());
  CHECK(aac_er_read_section_data(&r, &ics, got) == 0 && r.pos == o2.size(),
        "ER section data of short windows reads");
  CHECK(memcmp(got, band_type, (size_t)ics.num_window_groups * 10 * sizeof(int)) == 0,
        "ER section codebooks of short windows");
  /* The reserved codebook 12 is refused. */
  Bits bad;
  bad.put(12, 5);
  bad.put(3, 5);
  const std::vector<uint8_t> bytes3 = bad.bytes();
  aac_bitreader_init(&r, bytes3.data(), (int)bytes3.size());
  aac_ics_layout(&ics, kRateIndex, 1024, AAC_WIN_ONLY_LONG, 0);
  ics.max_sfb = 3;
  CHECK(aac_er_read_section_data(&r, &ics, got) == AAC_ERR_DECODE, "codebook 12 refused");
}

/* ── RVLC ───────────────────────────────────────────────────────── */

void test_rvlc_tables() {
  /* Palindromes, prefix free, and the escape codebook canonical. */
  for (int i = 0; i < AAC_RVLC_NUM_CODES; i++) {
    uint32_t rev = 0;
    for (int b = 0; b < aac_rvlc_len[i]; b++) {
      rev = (rev << 1) | ((aac_rvlc_code[i] >> b) & 1u);
    }
    CHECK(rev == aac_rvlc_code[i], "RVLC code %d is a palindrome", i - 7);
    for (int j = 0; j < AAC_RVLC_NUM_CODES; j++) {
      if (i == j || aac_rvlc_len[j] < aac_rvlc_len[i]) {
        continue;
      }
      CHECK((aac_rvlc_code[j] >> (aac_rvlc_len[j] - aac_rvlc_len[i])) != aac_rvlc_code[i],
            "RVLC code %d is no prefix of %d", i - 7, j - 7);
    }
  }
  uint32_t code = 0;
  int len = 0;
  for (int i = 0; i < AAC_RVLC_NUM_ESCAPES; i++) {
    code <<= aac_rvlc_escape_len[i] - len;
    len = aac_rvlc_escape_len[i];
    CHECK(aac_rvlc_escape_code[i] == code, "escape code %d canonical", i);
    code++;
  }
  CHECK(code <= (1u << len), "escape codebook fits");
}

struct RvlcCase {
  AacIcsInfo ics;
  int band_type[AAC_MAX_BANDS];
  int sf[AAC_MAX_BANDS];
  int global_gain;
};

RvlcCase rvlc_case(int window_sequence, int with_noise, int with_intensity) {
  Frame f = make_frame(window_sequence, with_intensity);
  RvlcCase c = {};
  c.ics = f.ics;
  c.global_gain = f.global_gain;
  memcpy(c.band_type, f.band_type, sizeof(c.band_type));
  memcpy(c.sf, f.sf, sizeof(c.sf));
  if (!with_noise) {
    int sf = c.global_gain;
    for (int g = 0; g < c.ics.num_window_groups; g++) {
      for (int b = 0; b < c.ics.max_sfb; b++) {
        const int idx = band_index(&c.ics, g, b);
        if (c.band_type[idx] == AAC_CB_NOISE) {
          c.band_type[idx] = 1;
          sf = std::max(0, std::min(255, sf + rnd(5) - 2));
          c.sf[idx] = sf;
        } else if (aac_cb_is_spectral(c.band_type[idx])) {
          sf = c.sf[idx];
        }
      }
    }
  }
  return c;
}

/* Reads a case's RVLC data followed by a 16-bit marker. */
void read_rvlc(const Bits& data, const RvlcCase& c, int* sf, int* ret, int* outcome,
               int* marker_ok) {
  Bits o = data;
  o.put(0xBEEF, 16);
  const std::vector<uint8_t> bytes = o.bytes();
  AacBitReader r;
  aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
  memset(sf, 0, AAC_MAX_BANDS * sizeof(int));
  *ret = aac_er_read_rvlc_scalefactors(&r, &c.ics, c.band_type, c.global_gain, sf, outcome);
  *marker_ok = r.pos == data.size() && aac_bitreader_read(&r, 16) == 0xBEEF;
}

void test_rvlc_forward(int window_sequence, int with_noise, int with_intensity) {
  const RvlcCase c = rvlc_case(window_sequence, with_noise, with_intensity);
  Bits o;
  write_rvlc_scalefactors(&o, &c.ics, c.band_type, c.global_gain, c.sf);
  int sf[AAC_MAX_BANDS], ret, outcome, marker;
  read_rvlc(o, c, sf, &ret, &outcome, &marker);
  CHECK(ret == 0 && outcome == AAC_RVLC_OK && marker,
        "RVLC forward (%d/%d/%d): ret %d outcome %d marker %d", window_sequence, with_noise,
        with_intensity, ret, outcome, marker);
  int bad = 0;
  for (int i = 0; i < c.ics.num_window_groups * c.ics.max_sfb; i++) {
    bad += c.band_type[i] != 0 && sf[i] != c.sf[i];
  }
  CHECK(bad == 0, "RVLC forward (%d/%d/%d): %d scalefactors differ", window_sequence, with_noise,
        with_intensity, bad);
}

/* A bit flipped inside the reversible part: the forward pass fails its
 * checks, the backward pass recovers the bands after the error, the
 * bands before it keep their forward values, and the reader lands
 * behind the data. */
void test_rvlc_backward(int window_sequence, int with_noise) {
  const RvlcCase c = rvlc_case(window_sequence, with_noise, 0);
  Bits o;
  write_rvlc_scalefactors(&o, &c.ics, c.band_type, c.global_gain, c.sf);
  const int header = 1 + 8 + (c.ics.num_windows == 8 ? 11 : 9);
  int codes_len = 0;
  for (int i = 0; i < (c.ics.num_windows == 8 ? 11 : 9); i++) {
    codes_len = (codes_len << 1) | o.b[9 + i];
  }
  int n = 0;
  for (int i = 0; i < c.ics.num_window_groups * c.ics.max_sfb; i++) {
    n += c.band_type[i] != 0;
  }
  int recovered = 0, exact_before = 0, exact_after = 0, total_before = 0, total_after = 0;
  for (int trial = 0; trial < 12; trial++) {
    Bits bad = o;
    /* Flip a bit in the middle third of the codewords. */
    const int at = header + codes_len / 3 + rnd(std::max(1, codes_len / 3));
    bad.b[at] ^= 1;
    int sf[AAC_MAX_BANDS], ret, outcome, marker;
    read_rvlc(bad, c, sf, &ret, &outcome, &marker);
    CHECK(ret == 0 && marker, "RVLC damaged (%d/%d) trial %d: ret %d marker %d", window_sequence,
          with_noise, trial, ret, marker);
    if (outcome != AAC_RVLC_RECOVERED) {
      /* The flip produced another valid stream that passed every check
       * (possible, but rare); nothing to measure. */
      continue;
    }
    recovered++;
    /* Count the exact bands in the first and the last quarter. */
    int k = 0;
    for (int i = 0; i < c.ics.num_window_groups * c.ics.max_sfb; i++) {
      if (c.band_type[i] == 0) {
        continue;
      }
      if (k < n / 4) {
        total_before++;
        exact_before += sf[i] == c.sf[i];
      } else if (k >= n - n / 4) {
        total_after++;
        exact_after += sf[i] == c.sf[i];
      }
      k++;
    }
  }
  /* When a flipped bit keeps every codeword valid, neither pass can say
   * where the error is and the overlap is split in the middle, so a few
   * bands on the wrong side of the split are lost; a flip in the middle
   * third of the bits may also land in the first quarter of the bands
   * when the early codewords are long.  The quarters stay almost all
   * exact. */
  CHECK(recovered >= 8, "RVLC damaged (%d/%d): %d of 12 recovered", window_sequence, with_noise,
        recovered);
  CHECK(exact_before * 10 >= total_before * 9,
        "RVLC damaged (%d/%d): bands before the error %d/%d exact", window_sequence, with_noise,
        exact_before, total_before);
  CHECK(exact_after * 10 >= total_after * 9, "RVLC damaged (%d/%d): bands after the error %d/%d exact",
        window_sequence, with_noise, exact_after, total_after);
  printf("RVLC damaged (%d/%d): %d of 12 recovered, first quarter %d/%d and last quarter %d/%d "
         "exact\n",
         window_sequence, with_noise, recovered, exact_before, total_before, exact_after,
         total_after);
}

/* The forward pass alone cannot tell when a flipped bit turns one
 * codeword into another of the same length: the reverse global gain
 * does.  A difference changed by hand must be noticed. */
void test_rvlc_rev_global_gain() {
  AacIcsInfo ics;
  aac_ics_layout(&ics, kRateIndex, 1024, AAC_WIN_ONLY_LONG, 0);
  ics.max_sfb = 6;
  const int band_type[6] = {1, 1, 1, 1, 1, 1};
  const int sf[6] = {100, 101, 102, 101, 100, 99};
  Bits o;
  write_rvlc_scalefactors(&o, &ics, band_type, 100, sf);
  /* Change the third difference (+1 -> -1): codes 111 and 101 share a
   * length, so the lengths stay consistent. */
  const int at = 1 + 8 + 9 + 1 + 3;
  CHECK(o.b[at] == 1 && o.b[at + 1] == 1 && o.b[at + 2] == 1 && o.b[at + 3] == 1,
        "third difference is +1 (111)");
  o.b[at + 1] = 0;
  RvlcCase c = {};
  c.ics = ics;
  c.global_gain = 100;
  memcpy(c.band_type, band_type, sizeof(band_type));
  memcpy(c.sf, sf, sizeof(sf));
  int got[AAC_MAX_BANDS], ret, outcome, marker;
  read_rvlc(o, c, got, &ret, &outcome, &marker);
  CHECK(ret == 0 && outcome == AAC_RVLC_RECOVERED && marker, "changed difference noticed");
  CHECK(got[0] == 100 && got[1] == 101 && got[5] == 99 && got[4] == 100,
        "ends kept: %d %d .. %d %d", got[0], got[1], got[4], got[5]);
}

/* ── HCR ────────────────────────────────────────────────────────── */

void test_hcr_roundtrip(int window_sequence, int er_sections) {
  const Frame f = make_frame(window_sequence, 0);
  const int* band_type = er_sections ? f.er_band_type : f.band_type;
  Bits o;
  write_hcr_spectrum(&o, &f.ics, band_type, f.quant);
  o.put(0xACE, 12);
  const std::vector<uint8_t> bytes = o.bytes();
  AacBitReader r;
  aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
  const int length = (int)aac_bitreader_read(&r, 14);
  const int longest = (int)aac_bitreader_read(&r, 6);
  int quant[1024];
  const int ret = aac_er_read_hcr(&r, &f.ics, band_type, length, longest, quant);
  CHECK(ret == 0, "HCR (%d/%d): %d bits, longest %d, ret %d", window_sequence, er_sections, length,
        longest, ret);
  CHECK(r.pos == o.size() - 12 && aac_bitreader_read(&r, 12) == 0xACE,
        "HCR (%d/%d): reader behind the data", window_sequence, er_sections);
  int diff = 0;
  for (int i = 0; i < 1024; i++) {
    diff += quant[i] != f.quant[i];
  }
  CHECK(diff == 0, "HCR (%d/%d): %d lines differ", window_sequence, er_sections, diff);
  printf("HCR %d/%d: %d codewords in %d bits, longest %d\n", window_sequence, er_sections,
         aac_er_hcr_codeword_order(&f.ics, band_type, quant, quant + 512), length, longest);
}

/* A hand-laid frame whose placement is known: three pairs of book 7 and
 * two quads of book 1 in a long window.  The order has the pairs first,
 * the number of segments is the bits over the longest codeword, and a
 * damaged priority codeword is refused. */
void test_hcr_layout() {
  AacIcsInfo ics;
  aac_ics_layout(&ics, kRateIndex, 1024, AAC_WIN_ONLY_LONG, 0);
  ics.max_sfb = 3; /* bands of 4 lines each at 48 kHz */
  const int band_type[3] = {1, 7, 7};
  int quant[1024] = {};
  const int vals[12] = {1, 0, -1, 1, 7, -7, 0, 3, -2, 1, 0, 0};
  memcpy(quant, vals, sizeof(vals));
  int dest[512], book[512];
  const int n = aac_er_hcr_codeword_order(&ics, band_type, dest, book);
  CHECK(n == 5 && book[0] == 7 && dest[0] == 4 && dest[3] == 10 && book[4] == 1 && dest[4] == 0,
        "presorted order: %d codewords, first %d at %d, last %d at %d", n, book[0], dest[0],
        book[n - 1], dest[n - 1]);
  Bits o;
  write_hcr_spectrum(&o, &ics, band_type, quant);
  std::vector<uint8_t> bytes = o.bytes();
  AacBitReader r;
  aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
  const int length = (int)aac_bitreader_read(&r, 14);
  const int longest = (int)aac_bitreader_read(&r, 6);
  int got[1024];
  CHECK(aac_er_read_hcr(&r, &ics, band_type, length, longest, got) == 0 &&
            memcmp(got, quant, sizeof(got)) == 0,
        "hand-laid frame reads back");
  /* The longest codeword is the (7, -7) pair: its code plus two signs. */
  const int pair[2] = {7, -7};
  CHECK(longest == huffman_bits(7, pair).size(), "longest codeword %d", longest);
  /* Flip the first bit of the second segment: its priority codeword is
   * damaged and the error is reported. */
  Bits bad = o;
  bad.b[20 + longest] ^= 1;
  bytes = bad.bytes();
  aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
  aac_bitreader_skip(&r, 20);
  const int ret = aac_er_read_hcr(&r, &ics, band_type, length, longest, got);
  CHECK(ret == AAC_ERR_DECODE || memcmp(got, quant, sizeof(got)) != 0,
        "damaged priority codeword noticed");
  CHECK(r.pos == 20 + length, "reader behind the damaged data");
}

/* ── end to end ────────────────────────────────────────────────── */

std::vector<std::vector<Frame>> make_frames(int channels, int count) {
  std::vector<std::vector<Frame>> frames;
  const int seqs[4] = {AAC_WIN_ONLY_LONG, AAC_WIN_LONG_START, AAC_WIN_EIGHT_SHORT,
                       AAC_WIN_LONG_STOP};
  for (int i = 0; i < count; i++) {
    std::vector<Frame> ch;
    const int seq = seqs[i % 4];
    for (int c = 0; c < channels; c++) {
      ch.push_back(make_frame(seq, c));
      if (c > 0) {
        ch[c].ics = ch[0].ics; /* a channel pair shares one ics_info */
      }
    }
    frames.push_back(ch);
  }
  return frames;
}

std::vector<uint8_t> stream_of(const std::vector<std::vector<Frame>>& frames, const Tools& t) {
  std::vector<std::vector<uint8_t>> aus;
  for (const auto& f : frames) {
    aus.push_back(write_block(f, t));
  }
  return loas_stream(write_asc((int)frames[0].size(), t), aus);
}

void test_end_to_end(int channels) {
  const std::vector<std::vector<Frame>> frames = make_frames(channels, 24);
  const Tools plain = {0, 0, 0};
  const std::vector<uint8_t> ref_stream = stream_of(frames, plain);
  const Decoded ref = decode_loas(ref_stream, AAC_CONCEAL_OFF);
  CHECK(ref.errors == 0 && ref.concealed == 0, "%d ch plain: %d errors, %d concealed", channels,
        ref.errors, ref.concealed);
  CHECK(ref.pcm.size() == (size_t)24 * 1024 * channels, "%d ch plain: %zu samples", channels,
        ref.pcm.size());
  double peak = 0.0;
  for (float v : ref.pcm) {
    peak = std::max(peak, (double)fabs(v));
  }
  CHECK(peak > 0.01, "%d ch plain: signal present (peak %.3f)", channels, peak);
  const Tools cases[7] = {{1, 0, 0}, {0, 1, 0}, {0, 0, 1}, {1, 1, 0}, {1, 0, 1}, {0, 1, 1}, {1, 1, 1}};
  for (const Tools& t : cases) {
    const std::vector<uint8_t> stream = stream_of(frames, t);
    const Decoded d = decode_loas(stream, AAC_CONCEAL_OFF);
    CHECK(d.errors == 0 && d.concealed == 0, "%d ch tools %d%d%d: %d errors, %d concealed", channels,
          t.section, t.scalefactor, t.spectral, d.errors, d.concealed);
    CHECK(d.pcm.size() == ref.pcm.size(), "%d ch tools %d%d%d: %zu vs %zu samples", channels,
          t.section, t.scalefactor, t.spectral, d.pcm.size(), ref.pcm.size());
    double worst = 0.0;
    for (size_t i = 0; i < std::min(d.pcm.size(), ref.pcm.size()); i++) {
      worst = std::max(worst, (double)fabs(d.pcm[i] - ref.pcm[i]));
    }
    CHECK(worst <= 1e-6, "%d ch tools %d%d%d: PCM differs by %.3g", channels, t.section,
          t.scalefactor, t.spectral, worst);
    printf("%d ch, tools %d%d%d: %zu bytes (plain %zu), max difference %.3g\n", channels, t.section,
           t.scalefactor, t.spectral, stream.size(), ref_stream.size(), worst);
  }
}

/* A value above its virtual codebook's limit: the frame is refused with
 * concealment off and concealed with it on. */
void test_end_to_end_over_limit() {
  std::vector<std::vector<Frame>> frames = make_frames(1, 6);
  Frame& f = frames[3][0];
  int idx = -1;
  for (int b = 0; b < f.ics.max_sfb && idx < 0; b++) {
    if (f.band_type[b] == 1) {
      idx = b;
    }
  }
  CHECK(idx >= 0, "a band of book 1 to turn into a virtual codebook");
  if (idx < 0) {
    return;
  }
  /* Codebook 16 promises values up to 15; the band carries 20. */
  f.er_band_type[idx] = 16;
  for (int k = f.ics.swb_offset[idx]; k < f.ics.swb_offset[idx + 1]; k++) {
    f.quant[k] = k == f.ics.swb_offset[idx] ? 20 : 0;
  }
  const Tools t = {1, 0, 0};
  const std::vector<uint8_t> stream = stream_of(frames, t);
  const Decoded strict = decode_loas(stream, AAC_CONCEAL_OFF);
  CHECK(strict.errors == 1 && strict.pcm.size() == (size_t)5 * 1024,
        "over limit, concealment off: %d errors, %zu samples", strict.errors, strict.pcm.size());
  const Decoded lenient = decode_loas(stream, AAC_CONCEAL_INTERPOLATE);
  CHECK(lenient.errors == 0 && lenient.concealed == 1 && lenient.pcm.size() == (size_t)6 * 1024,
        "over limit, concealment on: %d errors, %d concealed, %zu samples", lenient.errors,
        lenient.concealed, lenient.pcm.size());
  /* The same frame with HCR on top: the limit holds there too. */
  const Tools both = {1, 0, 1};
  const Decoded hcr = decode_loas(stream_of(frames, both), AAC_CONCEAL_OFF);
  CHECK(hcr.errors == 1, "over limit inside reordered data: %d errors", hcr.errors);
}

/* A damaged RVLC part in one frame: with concealment on the frame plays
 * from the recovered scalefactors and counts as concealed; off, it is
 * an error. */
void test_end_to_end_rvlc_damage() {
  const std::vector<std::vector<Frame>> frames = make_frames(1, 6);
  const Tools t = {0, 1, 0};
  std::vector<std::vector<uint8_t>> aus;
  for (const auto& f : frames) {
    aus.push_back(write_block(f, t));
  }
  /* Frame 4 (a long window): a bit in the middle of the reversible part.
   * The fields before it: tag 4, global gain 8, ics_info 11, the
   * sections, then 1 + 8 + 9 bits of RVLC header. */
  Bits sections;
  write_sections(&sections, &frames[4][0].ics, frames[4][0].band_type, 0);
  int codes_len = 0;
  const int header = 4 + 8 + 11 + sections.size() + 1 + 8;
  for (int i = 0; i < 9; i++) {
    codes_len = (codes_len << 1) | ((aus[4][(header + i) / 8] >> (7 - (header + i) % 8)) & 1);
  }
  const int at = header + 9 + codes_len / 2;
  aus[4][at / 8] ^= (uint8_t)(0x80 >> (at % 8));
  const std::vector<uint8_t> stream = loas_stream(write_asc(1, t), aus);
  const Decoded strict = decode_loas(stream, AAC_CONCEAL_OFF);
  const Decoded lenient = decode_loas(stream, AAC_CONCEAL_INTERPOLATE);
  CHECK(strict.errors == 1 || (strict.errors == 0 && lenient.concealed == 0),
        "damaged RVLC, concealment off: %d errors", strict.errors);
  CHECK(lenient.errors == 0 && lenient.pcm.size() == (size_t)6 * 1024,
        "damaged RVLC, concealment on: %d errors, %zu samples", lenient.errors,
        lenient.pcm.size());
  printf("damaged RVLC frame: strict %d errors, lenient %d concealed\n", strict.errors,
         lenient.concealed);
}

/* FFmpeg decodes the error resilient framing without the tools: the
 * plain stream cross-checks the hand-built frames themselves.  Its LOAS
 * demuxer does not set up error resilient streams, so the access units
 * go through a minimal MP4 file. */
void test_ffmpeg_plain(int channels) {
  if (!have_ffmpeg()) {
    printf("ffmpeg not found: skipping the cross-check of the plain ER-LC stream\n");
    return;
  }
  const std::vector<std::vector<Frame>> frames = make_frames(channels, 16);
  const Tools plain = {0, 0, 0};
  const Decoded ours = decode_loas(stream_of(frames, plain), AAC_CONCEAL_OFF);
  std::vector<std::vector<uint8_t>> aus;
  for (const auto& f : frames) {
    aus.push_back(write_block(f, plain));
  }
  const std::vector<uint8_t> file = mp4_file(aus, write_asc(channels, plain), 1024, kRate, channels);
  const std::string dir = make_temp_dir();
  const std::string in = dir + "/er.mp4";
  const std::string out = dir + "/er.f32";
  CHECK(write_file(in, file.data(), file.size()), "write MP4");
  const bool ok =
      run_command("ffmpeg -nostdin -v error -y -i '" + in + "' -f f32le '" + out + "'");
  CHECK(ok, "ffmpeg refused the plain ER-LC stream (%d ch)", channels);
  const std::vector<float> theirs = read_f32(out);
  remove_temp_dir(dir);
  const size_t n = std::min(theirs.size(), ours.pcm.size());
  CHECK(theirs.size() == ours.pcm.size(), "%d ch: ffmpeg %zu samples, ours %zu", channels,
        theirs.size(), ours.pcm.size());
  const double d = n ? snr_db(theirs.data(), ours.pcm.data(), n) : -100.0;
  printf("plain ER-LC %d ch: ffmpeg vs ours %.1f dB over %zu samples\n", channels, d, n);
  CHECK(d > 60.0, "ffmpeg and our decoder disagree on the plain ER-LC frames (%.1f dB)", d);
}

}  // namespace

int main() {
  test_vcb11_limits();
  test_vcb11_values();
  test_er_section_data();
  test_rvlc_tables();
  for (int seq : {AAC_WIN_ONLY_LONG, AAC_WIN_EIGHT_SHORT}) {
    for (int noise = 0; noise < 2; noise++) {
      test_rvlc_forward(seq, noise, 0);
      test_rvlc_forward(seq, noise, 1);
      test_rvlc_backward(seq, noise);
    }
  }
  test_rvlc_rev_global_gain();
  test_hcr_layout();
  for (int seq : {AAC_WIN_ONLY_LONG, AAC_WIN_EIGHT_SHORT, AAC_WIN_LONG_START}) {
    test_hcr_roundtrip(seq, 0);
    test_hcr_roundtrip(seq, 1);
  }
  test_end_to_end(1);
  test_end_to_end(2);
  test_end_to_end_over_limit();
  test_end_to_end_rvlc_damage();
  test_ffmpeg_plain(1);
  test_ffmpeg_plain(2);
  return test_summary("test_er");
}
