/* mps-enc: test tooling that encodes 5.1 PCM to LOAS with an AAC-LC
 * downmix and an MPEG Surround payload (ISO/IEC 23003-1), so that the
 * decoder has streams to be checked against the FDK and libxaac
 * decoders.  Not a product feature: the parameter estimation is the
 * plain one of the standard's informative encoder (CLD from band
 * energies, ICC from the normalised cross-correlation, CPC as the least
 * squares prediction of the centre), without any rate control.
 *
 *   mps-enc <in.wav|in.f32> <out.loas> [options]
 *     --tree 5151|5152|525|212 the spatial tree (default 525; 212 codes the front pair alone)
 *     --form header|asc        the in-band bsSacHeader in the fill element
 *                              (default) or the object type 30 config in a
 *                              second LATM layer with sacPayloadEmbedding
 *     --huffman                Huffman-coded parameters (default PCM)
 *     --pairs                  code the parameter sets in pairs (Huffman)
 *     --sets N                 parameter sets per frame, 1..8 (default 1)
 *     --framing 0|1            bsFramingType (default 0)
 *     --freq-res R             bsFreqRes 1..7 (default 2: 20 bands)
 *     --ttt-mode M             bsTttModeLow 0..5 (default 1)
 *     --dual-mode B            bsTttDualMode with bsTttBandsLow B and
 *                              bsTttModeHigh = mode + 2 (energy) when the
 *                              low mode predicts, mode - 2 otherwise
 *     --gain-sur I --gain-lfe I --gain-dmx I   the fixed gains (2, 1, 0)
 *     --one-icc                bsOneIcc
 *     --quant-mode M           bsQuantMode 0..2 (the decoder scales the
 *                              OTT CLDs of 5151 and 5152 by the box levels)
 *     --smooth                 bsSmoothMode 2 in every set
 *     --temp-shape C           bsTempShapeConfig 0..2 (never enabled)
 *     --arbdmx                 bsArbitraryDownmix with 0 dB gains
 *     --header-every N         the in-band header every N frames (default 1)
 *     --indep-every N          bsIndependencyFlag every N frames (default 1)
 *     --bitrate B              the core bitrate (default 128000)
 *     --seconds S              encode at most S seconds
 *     --param-shift N          shift the parameter analysis by N samples
 *                              against the alignment this tool derives
 *     --frame-offset F         the spatial frame carried by access unit j
 *                              is frame j - F (default 2, the encoder delay)
 *     --verify                 parse every frame back and compare the indices
 *     --dump                   print the parameters of every frame as the
 *                              decoder's parser maps them
 *
 * The input is 48 kHz, six channels in the order L, R, C, LFE, Ls, Rs:
 * a WAV file (16, 24 or 32-bit PCM or float) or raw interleaved
 * float32.  One spatial frame covers one core frame (16 QMF slots). */
#include <algorithm>
#include <cmath>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>
#include <vector>

#include "aac.h"
#include "asc.h"
#include "bitstream.h"
#include "latm.h"
#include "mps.h"
#include "mps212.h"
#include "mps_internal.h"
#include "qmf.h"

namespace mpsenc {

constexpr int kRate = 48000;
constexpr int kFrame = 1024;
constexpr int kSlots = 16;
constexpr int kHyb = AAC_MPS_HYBRID_BANDS;
constexpr int kMaxPb = AAC_MPS_MAX_PARAM_BANDS;
constexpr int kMaxSets = 8;
constexpr int kInChannels = 6;
/* QMF analysis + synthesis (577 samples) and the hybrid analysis (six
 * slots) of the downmix path, measured against an impulse. */
constexpr int kDownmixDelay = 577 + 6 * 64;

struct Options {
  std::string in, out;
  int tree = AAC_MPS_TREE_525;
  bool asc_form = false;
  bool huffman = false;
  bool pairs = false;
  int sets = 1;
  int framing = 0;
  int freq_res = 2;
  int ttt_mode = 1;
  int dual_bands = 0;
  int gain_sur = 2, gain_lfe = 1, gain_dmx = 0;
  bool one_icc = false;
  int quant_mode = 0;
  bool smooth = false;
  int temp_shape = 0;
  bool arbdmx = false;
  int header_every = 1;
  int indep_every = 1;
  int bitrate = 128000;
  double seconds = 0.0;
  int param_shift = 0;
  int frame_offset = 2;
  bool verify = false;
  bool dump = false;
  bool core_only = false; /* no spatial payload: the downmix alone, for isolating core problems */
};

/* ── input ──────────────────────────────────────────────────────── */

std::vector<uint8_t> read_file(const std::string& path) {
  std::vector<uint8_t> data;
  FILE* f = fopen(path.c_str(), "rb");
  if (!f) {
    fprintf(stderr, "mps-enc: cannot open %s\n", path.c_str());
    exit(1);
  }
  uint8_t buf[65536];
  size_t n;
  while ((n = fread(buf, 1, sizeof(buf), f)) > 0) {
    data.insert(data.end(), buf, buf + n);
  }
  fclose(f);
  return data;
}

uint32_t le32(const uint8_t* p) { return p[0] | (p[1] << 8) | (p[2] << 16) | ((uint32_t)p[3] << 24); }
uint32_t le16(const uint8_t* p) { return p[0] | (p[1] << 8); }

/* Interleaved float samples of a WAV file or a raw float32 file. */
std::vector<float> read_input(const std::string& path, int* channels, int* rate) {
  const std::vector<uint8_t> d = read_file(path);
  std::vector<float> pcm;
  if (d.size() >= 12 && memcmp(d.data(), "RIFF", 4) == 0 && memcmp(d.data() + 8, "WAVE", 4) == 0) {
    size_t pos = 12;
    int format = 0, bits = 0;
    *channels = 0;
    *rate = 0;
    while (pos + 8 <= d.size()) {
      const uint32_t len = le32(d.data() + pos + 4);
      const uint8_t* body = d.data() + pos + 8;
      if (memcmp(d.data() + pos, "fmt ", 4) == 0 && len >= 16) {
        format = (int)le16(body);
        *channels = (int)le16(body + 2);
        *rate = (int)le32(body + 4);
        bits = (int)le16(body + 14);
        if (format == 0xFFFE && len >= 26) {
          format = (int)le16(body + 24);
        }
      } else if (memcmp(d.data() + pos, "data", 4) == 0) {
        const size_t n = std::min<size_t>(len, d.size() - pos - 8);
        const int bytes = bits / 8;
        if (bytes <= 0) {
          break;
        }
        pcm.resize(n / (size_t)bytes);
        for (size_t i = 0; i < pcm.size(); i++) {
          const uint8_t* s = body + i * (size_t)bytes;
          if (format == 3 && bits == 32) {
            float v;
            memcpy(&v, s, 4);
            pcm[i] = v;
          } else if (bits == 16) {
            pcm[i] = (float)(int16_t)le16(s) / 32768.0f;
          } else if (bits == 24) {
            const int32_t v = (int32_t)((s[0] << 8) | (s[1] << 16) | ((uint32_t)s[2] << 24)) >> 8;
            pcm[i] = (float)v / 8388608.0f;
          } else if (bits == 32) {
            pcm[i] = (float)(int32_t)le32(s) / 2147483648.0f;
          }
        }
        break;
      }
      pos += 8 + len + (len & 1);
    }
    if (*channels == 0 || pcm.empty()) {
      fprintf(stderr, "mps-enc: %s: no usable WAV data\n", path.c_str());
      exit(1);
    }
    return pcm;
  }
  pcm.resize(d.size() / 4);
  memcpy(pcm.data(), d.data(), pcm.size() * 4);
  *channels = kInChannels;
  *rate = kRate;
  return pcm;
}

/* ── bit writing ────────────────────────────────────────────────── */

struct Bits {
  std::vector<uint8_t> buf;
  AacBitWriter w;
  explicit Bits(size_t capacity = 1 << 16) : buf(capacity, 0) { aac_bitwriter_init(&w, buf.data(), (int)buf.size()); }
  void put(uint32_t v, int n) { aac_bitwriter_write(&w, v, n); }
  void align() { aac_bitwriter_byte_align(&w); }
  int bits() const { return aac_bitwriter_bits_written(&w); }
  std::vector<uint8_t> bytes() const {
    if (aac_bitwriter_overflow(&w)) {
      fprintf(stderr, "mps-enc: bit writer overflow\n");
      exit(1);
    }
    return std::vector<uint8_t>(buf.begin(), buf.begin() + aac_bitwriter_bytes_written(&w));
  }
};

/* ── Huffman codes from the decoder's node tables ───────────────── */

struct Book {
  std::vector<uint32_t> code; /* per symbol; index count = the escape */
  std::vector<int> len;
  int escape = -1;
  bool has(int sym) const { return sym >= 0 && sym < (int)len.size() && len[sym] > 0; }
};

Book build_book(const int16_t (*nodes)[2], int count) {
  Book b;
  b.code.assign(257, 0);
  b.len.assign(257, 0);
  struct Item {
    int node;
    uint32_t code;
    int len;
  };
  std::vector<Item> stack = {{0, 0, 0}};
  while (!stack.empty()) {
    const Item it = stack.back();
    stack.pop_back();
    for (int bit = 0; bit < 2; bit++) {
      const int e = nodes[it.node][bit];
      const uint32_t code = (it.code << 1) | (uint32_t)bit;
      if (e > 0) {
        if (e < count) {
          stack.push_back({e, code, it.len + 1});
        }
      } else {
        const int sym = -e - 1;
        const int idx = sym < 0 ? 256 : sym;
        b.code[idx] = code;
        b.len[idx] = it.len + 1;
        if (sym < 0) {
          b.escape = 256;
        }
      }
    }
  }
  return b;
}

void put_code(Bits& b, const Book& book, int sym) {
  const int idx = sym < 0 ? 256 : sym;
  if (!book.has(idx) && !(sym < 0 && book.len[256] > 0)) {
    fprintf(stderr, "mps-enc: symbol %d has no code\n", sym);
    exit(1);
  }
  b.put(book.code[idx], book.len[idx]);
}

struct Books {
  Book first[4];     /* per type */
  Book one[4][3];    /* per type and difference direction */
  Book two[4][4][2]; /* per type, LAV class and pairing (frequency, time) */
  Book lav;
  Books() {
    first[AAC_MPS_PARAM_CLD] = build_book(aac_mps212_huff_cld_first, 30);
    first[AAC_MPS_PARAM_ICC] = build_book(aac_mps212_huff_icc_first, 7);
    first[AAC_MPS_PARAM_CPC] = build_book(aac_mps_huff_cpc_first, 25);
    lav = build_book(aac_mps212_huff_lav, 3);
    for (int d = 0; d < 3; d++) {
      one[AAC_MPS_PARAM_CLD][d] = build_book(aac_mps212_huff_cld_1d[d], 30);
      one[AAC_MPS_PARAM_ICC][d] = build_book(aac_mps212_huff_icc_1d[d], 7);
      one[AAC_MPS_PARAM_CPC][d] = build_book(aac_mps_huff_cpc_1d[d], 25);
      for (int p = 0; p < 2; p++) {
        two[AAC_MPS_PARAM_CLD][0][p] = build_book(aac_mps212_huff_cld_2d_lav3[d][p], 15);
        two[AAC_MPS_PARAM_CLD][1][p] = build_book(aac_mps212_huff_cld_2d_lav5[d][p], 35);
        two[AAC_MPS_PARAM_CLD][2][p] = build_book(aac_mps212_huff_cld_2d_lav7[d][p], 63);
        two[AAC_MPS_PARAM_CLD][3][p] = build_book(aac_mps212_huff_cld_2d_lav9[d][p], 99);
        two[AAC_MPS_PARAM_ICC][0][p] = build_book(aac_mps212_huff_icc_2d_lav1[d][p], 3);
        two[AAC_MPS_PARAM_ICC][1][p] = build_book(aac_mps212_huff_icc_2d_lav3[d][p], 15);
        two[AAC_MPS_PARAM_ICC][2][p] = build_book(aac_mps212_huff_icc_2d_lav5[d][p], 35);
        two[AAC_MPS_PARAM_ICC][3][p] = build_book(aac_mps212_huff_icc_2d_lav7[d][p], 63);
        two[AAC_MPS_PARAM_CPC][0][p] = build_book(aac_mps_huff_cpc_2d_lav3[d][p], 15);
        two[AAC_MPS_PARAM_CPC][1][p] = build_book(aac_mps_huff_cpc_2d_lav6[d][p], 48);
        two[AAC_MPS_PARAM_CPC][2][p] = build_book(aac_mps_huff_cpc_2d_lav9[d][p], 99);
        two[AAC_MPS_PARAM_CPC][3][p] = build_book(aac_mps_huff_cpc_2d_lav12[d][p], 168);
      }
      /* the two-dimensional books come per direction; keep the one of
       * the frequency direction in the table above and swap in the
       * direction's book at use */
      if (d == 0) {
        continue;
      }
    }
  }
  /* The two-dimensional book of a direction, LAV class and pairing. */
  Book two_of(int type, int d, int lav_class, int pairing) const {
    switch (type) {
      case AAC_MPS_PARAM_CLD:
        switch (lav_class) {
          case 0: return build_book(aac_mps212_huff_cld_2d_lav3[d][pairing], 15);
          case 1: return build_book(aac_mps212_huff_cld_2d_lav5[d][pairing], 35);
          case 2: return build_book(aac_mps212_huff_cld_2d_lav7[d][pairing], 63);
          default: return build_book(aac_mps212_huff_cld_2d_lav9[d][pairing], 99);
        }
      case AAC_MPS_PARAM_ICC:
        switch (lav_class) {
          case 0: return build_book(aac_mps212_huff_icc_2d_lav1[d][pairing], 3);
          case 1: return build_book(aac_mps212_huff_icc_2d_lav3[d][pairing], 15);
          case 2: return build_book(aac_mps212_huff_icc_2d_lav5[d][pairing], 35);
          default: return build_book(aac_mps212_huff_icc_2d_lav7[d][pairing], 63);
        }
      default:
        switch (lav_class) {
          case 0: return build_book(aac_mps_huff_cpc_2d_lav3[d][pairing], 15);
          case 1: return build_book(aac_mps_huff_cpc_2d_lav6[d][pairing], 48);
          case 2: return build_book(aac_mps_huff_cpc_2d_lav9[d][pairing], 99);
          default: return build_book(aac_mps_huff_cpc_2d_lav12[d][pairing], 168);
        }
    }
  }
};

int lav_of(int type, int cls) {
  switch (type) {
    case AAC_MPS_PARAM_CLD: return 2 * cls + 3;
    case AAC_MPS_PARAM_ICC: return 2 * cls + 1;
    default: return 3 * cls + 3;
  }
}

/* ── EcData writing ─────────────────────────────────────────────── */

struct Quant {
  int levels, offset, lsb;
};

Quant quant_of(int type) {
  switch (type) {
    case AAC_MPS_PARAM_CLD: return {31, 15, 0};
    case AAC_MPS_PARAM_ICC: return {8, 0, 0};
    default: return {51, 20, 1};
  }
}

int pcm_group(int levels) {
  switch (levels) {
    case 3: return 5;
    case 7: return 6;
    case 11: return 2;
    case 13:
    case 19:
    case 51: return 4;
    case 25: return 3;
    default: return 1;
  }
}

int bits_for(int levels, int group) {
  uint64_t n = 1;
  for (int i = 0; i < group; i++) {
    n *= (uint64_t)levels;
  }
  int b = 0;
  while (((uint64_t)1 << b) < n) {
    b++;
  }
  return b;
}

/* PCM words over `vals` (already offset to 0..levels-1). */
void write_pcm(Bits& b, const std::vector<int>& vals, int levels) {
  const int group = pcm_group(levels);
  const int num = (int)vals.size();
  for (int i = 0; i < num; i += group) {
    const int n = std::min(group, num - i);
    uint64_t word = 0;
    for (int k = 0; k < n; k++) {
      word = word * (uint64_t)levels + (uint64_t)vals[i + k];
    }
    b.put((uint32_t)word, bits_for(levels, n));
  }
}

/* One-dimensional codes of `diff` values with direction `d` (0
 * frequency, 1 time); `first` is coded with the first-value book. */
void write_1d(Bits& b, const Books& books, int type, int d, const int* first, const int* diff,
              int num) {
  if (first) {
    put_code(b, books.first[type], *first);
  }
  for (int i = 0; i < num; i++) {
    const int v = diff[i];
    put_code(b, books.one[type][d], std::abs(v));
    if (v != 0) {
      b.put(v < 0 ? 1u : 0u, 1);
    }
  }
}

/* Two-dimensional codes of `num` (even) differences as frequency pairs:
 * the LAV class, the first value, the pair codes with their symmetry
 * bits and the escaped pairs at the end. */
void write_2d(Bits& b, const Books& books, int type, int d, const int* first, const int* diff,
              int num) {
  int max_abs = 0;
  for (int i = 0; i < num; i++) {
    max_abs = std::max(max_abs, std::abs(diff[i]));
  }
  int cls = 0;
  while (cls < 3 && lav_of(type, cls) < max_abs) {
    cls++;
  }
  const int lav = lav_of(type, cls);
  put_code(b, books.lav, cls);
  if (first) {
    put_code(b, books.first[type], *first);
  }
  const Book book = books.two_of(type, d, cls, 0);
  std::vector<int> esc;
  for (int i = 0; i + 1 < num + 1 && i < num; i += 2) {
    const int d0 = diff[i], d1 = diff[i + 1];
    bool done = false;
    for (int sign = 0; sign < 2 && !done; sign++) {
      for (int swap = 0; swap < 2 && !done; swap++) {
        int e0 = d0, e1 = d1;
        if (swap) {
          std::swap(e0, e1);
        }
        if (sign) {
          e0 = -e0;
          e1 = -e1;
        }
        if (sign && e0 + e1 == 0) continue;
        if (swap && e0 == e1) continue;
        /* the decoder forms (sum, dif) from the leaf's (a, b) */
        for (int branch = 0; branch < 2 && !done; branch++) {
          int sum, dif;
          if (branch == 0) {
            sum = e0;
            dif = e1;
            if (sum > lav || sum < 0) continue;
          } else {
            sum = 2 * lav + 1 - e0;
            dif = -e1;
            if (sum <= lav) continue;
          }
          if ((sum + dif) % 2 != 0) continue;
          const int a = (sum + dif) / 2, bb = (sum - dif) / 2;
          if (a < 0 || bb < 0 || a > lav || bb > lav || a > 15 || bb > 15) continue;
          const int sym = (a << 4) | bb;
          if (!book.has(sym)) continue;
          put_code(b, book, sym);
          if (e0 + e1 != 0) {
            b.put(sign ? 1u : 0u, 1);
          }
          if (e0 - e1 != 0) {
            b.put(swap ? 1u : 0u, 1);
          }
          done = true;
        }
      }
    }
    if (!done) {
      if (!book.has(256)) {
        fprintf(stderr, "mps-enc: pair (%d, %d) of type %d, direction %d, LAV %d has no code and no escape\n", d0, d1,
                type, d, lav);
        exit(1);
      }
      put_code(b, book, -1);
      esc.push_back(d0 + lav);
      esc.push_back(d1 + lav);
    }
  }
  if (!esc.empty()) {
    write_pcm(b, esc, 2 * lav + 1);
  }
}

/* EcDataPair() of one set or a pair of sets of `bands` values (quantiser
 * indices on the fine scale) against `history` (the previous coded
 * values, same scale), with the AAC-form pilot flag.  The time
 * difference reference is allowed when `time_ref`; set 2 of a pair is
 * coded as a time difference to set 1. */
struct EcOpts {
  bool huffman;
  bool pairs;
  bool two_d;
  bool aac_form;
};

void write_ec_pair(Bits& b, const Books& books, int type, const int* v1, const int* v2, int bands,
                   int time_ref, const int* history, const EcOpts& o) {
  const Quant q = quant_of(type);
  const bool pair = v2 != nullptr;
  if (!o.huffman) {
    b.put(1, 1); /* bsPcmCoding */
    if (o.aac_form && bands > 4) {
      b.put(0, 1); /* bsPilotCoding */
    }
    std::vector<int> vals;
    for (int i = 0; i < bands; i++) {
      vals.push_back(v1[i] + q.offset);
      if (pair) {
        vals.push_back(v2[i] + q.offset);
      }
    }
    write_pcm(b, vals, q.levels);
    return;
  }
  b.put(0, 1); /* bsPcmCoding */
  /* the values on the coded scale: the quantiser index plus its offset,
   * without the lowest bit when it travels apart */
  std::vector<int> s1(bands), s2(bands), ref(bands);
  for (int i = 0; i < bands; i++) {
    s1[i] = (v1[i] + q.offset) >> q.lsb;
    if (pair) {
      s2[i] = (v2[i] + q.offset) >> q.lsb;
    }
    ref[i] = (history[i] + q.offset) >> q.lsb;
  }
  /* set 1 along frequency (or against the history when allowed and
   * cheaper), set 2 of a pair against set 1 */
  int diff1 = 0;
  if (time_ref) {
    int cost_f = 0, cost_t = 0;
    for (int i = 0; i < bands; i++) {
      cost_f += std::abs(s1[i] - (i ? s1[i - 1] : 0));
      cost_t += std::abs(s1[i] - ref[i]);
    }
    diff1 = cost_t < cost_f ? 1 : 0;
  }
  if (pair || time_ref) {
    b.put((uint32_t)diff1, 1); /* bsDiffType */
  }
  const int diff2 = 1;
  if (pair && (diff1 == 0 || time_ref)) {
    b.put((uint32_t)diff2, 1);
  }
  std::vector<int> d1(bands), d2(bands);
  int max_abs = 0;
  for (int i = 0; i < bands; i++) {
    d1[i] = diff1 ? s1[i] - ref[i] : (i ? s1[i] - s1[i - 1] : s1[0]);
    if (pair) {
      d2[i] = s2[i] - s1[i];
      max_abs = std::max(max_abs, std::abs(d2[i]));
    }
    if (diff1 || i > 0) {
      max_abs = std::max(max_abs, std::abs(d1[i]));
    }
  }
  /* the two-dimensional books reach the largest LAV only; beyond it the
   * one-dimensional books code the whole range */
  const bool two_d = o.two_d && max_abs <= lav_of(type, 3);
  b.put(two_d ? 1u : 0u, 1); /* bsCodingScheme */
  if (two_d && pair && o.aac_form) {
    b.put(0, 1); /* bsPairing: frequency pairs */
  }
  for (int k = 0; k < (pair ? 2 : 1); k++) {
    const std::vector<int>& d = k ? d2 : d1;
    const int dir = k ? diff2 : diff1;
    const int* first = dir == 0 ? &d[0] : nullptr;
    const int* rest = dir == 0 ? d.data() + 1 : d.data();
    const int n = dir == 0 ? bands - 1 : bands;
    if (!two_d) {
      write_1d(b, books, type, dir, first, rest, n);
    } else {
      const int even = n & ~1;
      write_2d(b, books, type, dir, first, rest, even);
      if (n & 1) {
        write_1d(b, books, type, dir, nullptr, rest + even, 1);
      }
    }
  }
  if (q.lsb) {
    for (int k = 0; k < (pair ? 2 : 1); k++) {
      const int* v = k ? v2 : v1;
      for (int i = 0; i < bands; i++) {
        b.put((uint32_t)((v[i] + q.offset) & 1), 1);
      }
    }
  }
}

/* EcData() of `num_sets` sets, every set coded (bsDataMode 3), stride
 * 1, the fine quantiser; `history` follows the decoder's. */
void write_ecdata(Bits& b, const Books& books, int type, const int (*vals)[kMaxPb], int num_sets,
                  int bands, int independency, int* history, const EcOpts& o) {
  for (int i = 0; i < num_sets; i++) {
    b.put(3, 2);
  }
  for (int set = 0; set < num_sets;) {
    const bool pair = o.pairs && o.huffman && set + 1 < num_sets;
    b.put(pair ? 1u : 0u, 1); /* bsDataPair */
    b.put(0, 1);              /* bsQuantCoarse */
    b.put(0, 2);              /* bsFreqResStride */
    const int time_ref = !(independency && set == 0);
    write_ec_pair(b, books, type, vals[set], pair ? vals[set + 1] : nullptr, bands, time_ref, history,
                  o);
    const int last = pair ? set + 1 : set;
    for (int i = 0; i < bands; i++) {
      history[i] = vals[last][i];
    }
    set += pair ? 2 : 1;
  }
}

/* ── the spatial configuration and the frame ────────────────────── */

struct Config {
  int tree, freq_res, bands;
  int num_ott, num_ttt, num_in;
  int ott_lfe[5];
  int ott_bands[5];
  int lfe_bands;
  int ttt_mode[2];
  int ttt_split; /* bsTttBandsLow, bands without the dual mode */
  int dual;
  int gain_sur, gain_lfe, gain_dmx;
  int one_icc, quant_mode, smooth, temp_shape, arbdmx;
};

Config make_config(const Options& o) {
  Config c;
  memset(&c, 0, sizeof(c));
  c.tree = o.tree;
  c.freq_res = o.freq_res;
  c.bands = aac_mps_param_bands(o.freq_res);
  int num_out;
  aac_mps_tree_properties(o.tree, &c.num_ott, &c.num_ttt, &c.num_in, &num_out, c.ott_lfe);
  c.lfe_bands = std::min(2, c.bands);
  for (int i = 0; i < c.num_ott; i++) {
    c.ott_bands[i] = c.ott_lfe[i] ? c.lfe_bands : c.bands;
  }
  c.ttt_mode[0] = o.ttt_mode;
  c.dual = o.dual_bands > 0 && o.dual_bands < c.bands;
  c.ttt_split = c.dual ? o.dual_bands : c.bands;
  c.ttt_mode[1] = o.ttt_mode < 2 ? o.ttt_mode + 2 : o.ttt_mode - 2;
  c.gain_sur = o.gain_sur;
  c.gain_lfe = o.gain_lfe;
  c.gain_dmx = o.gain_dmx;
  c.one_icc = o.one_icc;
  c.quant_mode = o.quant_mode;
  c.smooth = o.smooth;
  c.temp_shape = o.temp_shape;
  c.arbdmx = o.arbdmx;
  return c;
}

/* SpatialSpecificConfig(), byte aligned from its start, no extensions. */
void write_config(Bits& b, const Config& c) {
  const int start = b.bits();
  b.put(3, 4); /* bsSamplingFrequencyIndex: 48000 */
  b.put(kSlots - 1, 7);
  b.put((uint32_t)c.freq_res, 3);
  b.put((uint32_t)c.tree, 4);
  b.put((uint32_t)c.quant_mode, 2);
  b.put(c.one_icc ? 1u : 0u, 1);
  b.put(c.arbdmx ? 1u : 0u, 1);
  b.put((uint32_t)c.gain_sur, 3);
  b.put((uint32_t)c.gain_lfe, 3);
  b.put((uint32_t)c.gain_dmx, 3);
  b.put(0, 1); /* bsMatrixMode */
  b.put((uint32_t)c.temp_shape, 2);
  b.put(0, 2); /* bsDecorrConfig */
  b.put(0, 1); /* bs3DaudioMode */
  for (int i = 0; i < c.num_ott; i++) {
    if (c.ott_lfe[i]) {
      b.put((uint32_t)c.ott_bands[i], 5);
    }
  }
  for (int i = 0; i < c.num_ttt; i++) {
    b.put(c.dual ? 1u : 0u, 1);
    b.put((uint32_t)c.ttt_mode[0], 3);
    if (c.dual) {
      b.put((uint32_t)c.ttt_mode[1], 3);
      b.put((uint32_t)c.ttt_split, 5);
    }
  }
  if (c.temp_shape == 2) {
    b.put(0, 1); /* bsEnvQuantMode */
  }
  const int pad = (8 - (b.bits() - start) % 8) % 8;
  b.put(0, pad);
}

/* The parameters of one spatial frame as quantiser indices. */
struct FrameParams {
  int num_sets;
  int slot[kMaxSets];
  int indep;
  int ott_cld[5][kMaxSets][kMaxPb];
  int ott_icc[5][kMaxSets][kMaxPb];
  int ttt_cpc1[kMaxSets][kMaxPb], ttt_cpc2[kMaxSets][kMaxPb], ttt_icc[kMaxSets][kMaxPb];
  int ttt_cld1[kMaxSets][kMaxPb], ttt_cld2[kMaxSets][kMaxPb];
  int arb[2][kMaxSets][kMaxPb];
};

/* The coding history the writer keeps between frames. */
struct History {
  int ott_cld[5][kMaxPb], ott_icc[5][kMaxPb];
  int ttt_cpc1[2][kMaxPb], ttt_cpc2[2][kMaxPb], ttt_icc[2][kMaxPb], ttt_cld1[2][kMaxPb],
      ttt_cld2[2][kMaxPb];
  int arb[2][kMaxPb];
};

int ceil_log2(int n) {
  int b = 0;
  while ((1 << b) < n) {
    b++;
  }
  return b;
}

/* Writes one SpatialFrame() (byte aligned at its end, no extensions). */
void write_frame(Bits& b, const Books& books, const Config& c, const FrameParams& p, History& h,
                 int framing, const EcOpts& o) {
  const int start = b.bits();
  b.put((uint32_t)framing, 1);
  b.put((uint32_t)(p.num_sets - 1), 3);
  if (framing) {
    int prev = -1;
    for (int i = 0; i < p.num_sets; i++) {
      const int bits = ceil_log2(kSlots - p.num_sets + i - prev);
      if (bits > 0) {
        b.put((uint32_t)(p.slot[i] - prev - 1), bits);
      }
      prev = p.slot[i];
    }
  }
  b.put(p.indep ? 1u : 0u, 1);
  for (int box = 0; box < c.num_ott; box++) {
    write_ecdata(b, books, AAC_MPS_PARAM_CLD, p.ott_cld[box], p.num_sets, c.ott_bands[box], p.indep,
                 h.ott_cld[box], o);
  }
  if (c.one_icc) {
    write_ecdata(b, books, AAC_MPS_PARAM_ICC, p.ott_icc[0], p.num_sets, c.bands, p.indep,
                 h.ott_icc[0], o);
  } else {
    for (int box = 0; box < c.num_ott; box++) {
      if (!c.ott_lfe[box]) {
        write_ecdata(b, books, AAC_MPS_PARAM_ICC, p.ott_icc[box], p.num_sets, c.ott_bands[box],
                     p.indep, h.ott_icc[box], o);
      }
    }
  }
  for (int box = 0; box < c.num_ttt; box++) {
    for (int range = 0; range < 2; range++) {
      const int lo = range == 0 ? 0 : c.ttt_split;
      const int hi = range == 0 ? c.ttt_split : c.bands;
      if (lo >= hi) {
        continue;
      }
      /* the writer's value arrays start at band 0 of the range */
      int v1[kMaxSets][kMaxPb], v2[kMaxSets][kMaxPb], v3[kMaxSets][kMaxPb];
      const bool pred = c.ttt_mode[range] < 2;
      for (int s = 0; s < p.num_sets; s++) {
        for (int pb = lo; pb < hi; pb++) {
          v1[s][pb - lo] = pred ? p.ttt_cpc1[s][pb] : p.ttt_cld1[s][pb];
          v2[s][pb - lo] = pred ? p.ttt_cpc2[s][pb] : p.ttt_cld2[s][pb];
          v3[s][pb - lo] = p.ttt_icc[s][pb];
        }
      }
      if (pred) {
        write_ecdata(b, books, AAC_MPS_PARAM_CPC, v1, p.num_sets, hi - lo, p.indep,
                     h.ttt_cpc1[range], o);
        write_ecdata(b, books, AAC_MPS_PARAM_CPC, v2, p.num_sets, hi - lo, p.indep,
                     h.ttt_cpc2[range], o);
        write_ecdata(b, books, AAC_MPS_PARAM_ICC, v3, p.num_sets, hi - lo, p.indep,
                     h.ttt_icc[range], o);
      } else {
        write_ecdata(b, books, AAC_MPS_PARAM_CLD, v1, p.num_sets, hi - lo, p.indep,
                     h.ttt_cld1[range], o);
        write_ecdata(b, books, AAC_MPS_PARAM_CLD, v2, p.num_sets, hi - lo, p.indep,
                     h.ttt_cld2[range], o);
      }
    }
  }
  for (int s = 0; s < p.num_sets; s++) {
    if (c.smooth) {
      b.put(2, 2); /* bsSmoothMode */
      b.put(1, 2); /* bsSmoothTime: 128 slots */
    } else {
      b.put(0, 2);
    }
  }
  if (c.temp_shape != 0) {
    b.put(0, 1); /* bsTempShapeEnable */
  }
  if (c.arbdmx) {
    for (int ch = 0; ch < c.num_in; ch++) {
      write_ecdata(b, books, AAC_MPS_PARAM_CLD, p.arb[ch], p.num_sets, c.bands, p.indep, h.arb[ch],
                   o);
    }
  }
  const int pad = (8 - (b.bits() - start) % 8) % 8;
  b.put(0, pad);
}

/* ── quantisers ─────────────────────────────────────────────────── */

int quantise_cld(float db) {
  int best = 0;
  for (int i = 1; i < 31; i++) {
    if (fabsf(aac_mps212_cld_db[i] - db) < fabsf(aac_mps212_cld_db[best] - db)) {
      best = i;
    }
  }
  return best - 15;
}

int quantise_icc(float rho) {
  int best = 0;
  for (int i = 1; i < 8; i++) {
    if (fabsf(aac_mps212_icc_value[i] - rho) < fabsf(aac_mps212_icc_value[best] - rho)) {
      best = i;
    }
  }
  return best;
}

int quantise_cpc(float c) {
  const int idx = (int)lrintf(c * 10.0f);
  return std::max(-20, std::min(30, idx));
}

/* ── the analysis ───────────────────────────────────────────────── */

using Hyb = float[kHyb][2];

/* The hybrid analysis of one channel: a 64-band QMF bank and the
 * 13-slot history the hybrid filters run over. */
struct Analyser {
  AacQmfAnalysis qmf;
  float hist[13][64][2];
  Analyser() {
    aac_qmf_analysis_init(&qmf, 64);
    memset(hist, 0, sizeof(hist));
  }
  void push(const float* in, Hyb out) {
    float W[64][2];
    aac_qmf_analyze(&qmf, in, W);
    memmove(hist[0], hist[1], sizeof(hist[0]) * 12);
    memcpy(hist[12], W, sizeof(W));
    aac_mps212_hybrid_analysis(hist, out);
  }
};

/* Band energies and cross terms of two hybrid signals over a slot
 * range, per parameter band. */
struct Stats {
  double ea[kMaxPb], eb[kMaxPb], pab[kMaxPb], esum[kMaxPb];
};

void accumulate(Stats& s, const Hyb* a, const Hyb* b, int from, int to, const uint8_t* kernel) {
  memset(&s, 0, sizeof(s));
  for (int t = from; t < to; t++) {
    for (int k = 0; k < kHyb; k++) {
      const int pb = kernel[k];
      const double ar = a[t][k][0], ai = a[t][k][1], br = b[t][k][0], bi = b[t][k][1];
      s.ea[pb] += ar * ar + ai * ai;
      s.eb[pb] += br * br + bi * bi;
      s.pab[pb] += ar * br + ai * bi;
      s.esum[pb] += (ar + br) * (ar + br) + (ai + bi) * (ai + bi);
    }
  }
}

constexpr double kEps = 1e-9;

/* One OTT box over a frame: the parameters per set, and the downmix
 * with the energy-preserving gain interpolated over the slots (from the
 * previous frame's last gain). */
struct OttBox {
  float gain_prev[kMaxPb];
  OttBox() {
    for (float& g : gain_prev) g = 1.0f;
  }
  void run(const Hyb* a, const Hyb* b, const int* slot, int num_sets, const uint8_t* kernel,
           int bands, bool lfe, int lfe_bands, Hyb* out, int (*cld)[kMaxPb], int (*icc)[kMaxPb]) {
    float gain[kMaxSets][kMaxPb];
    int from = 0;
    for (int s = 0; s < num_sets; s++) {
      Stats st;
      accumulate(st, a, b, from, slot[s] + 1, kernel);
      for (int pb = 0; pb < kMaxPb; pb++) {
        const bool in_box = pb < bands && (!lfe || pb < lfe_bands);
        if (in_box) {
          cld[s][pb] = quantise_cld((float)(10.0 * log10((st.ea[pb] + kEps) / (st.eb[pb] + kEps))));
          const double rho = st.pab[pb] / sqrt((st.ea[pb] + kEps) * (st.eb[pb] + kEps));
          icc[s][pb] = quantise_icc((float)rho);
          double g = sqrt((st.ea[pb] + st.eb[pb] + kEps) / (st.esum[pb] + kEps));
          gain[s][pb] = (float)std::min(g, 4.0);
        } else {
          cld[s][pb] = lfe ? 15 : 0;
          icc[s][pb] = 0;
          gain[s][pb] = 1.0f;
        }
      }
      from = slot[s] + 1;
    }
    /* the downmix: a + b scaled per band, the gain interpolated between
     * the set positions as the decoder interpolates its parameters; an
     * LFE box above its bands passes the first input alone */
    int prev_slot = -1;
    for (int s = 0; s < num_sets; s++) {
      for (int t = prev_slot + 1; t <= slot[s]; t++) {
        const float w = (float)(t - prev_slot) / (float)(slot[s] - prev_slot);
        for (int k = 0; k < kHyb; k++) {
          const int pb = kernel[k];
          const float g = gain_prev[pb] + w * (gain[s][pb] - gain_prev[pb]);
          const bool in_box = pb < bands && (!lfe || pb < lfe_bands);
          if (in_box) {
            out[t][k][0] = g * (a[t][k][0] + b[t][k][0]);
            out[t][k][1] = g * (a[t][k][1] + b[t][k][1]);
          } else {
            out[t][k][0] = a[t][k][0];
            out[t][k][1] = a[t][k][1];
          }
        }
      }
      prev_slot = slot[s];
    }
    memcpy(gain_prev, gain[num_sets - 1], sizeof(gain_prev));
  }
};

/* The TTT box of the 525 tree: the downmix is l0 = l + c / sqrt 2 and
 * r0 = r + c / sqrt 2, the decoder rebuilding the centre as sqrt 2
 * times the scaled centre it estimates from the pair. */
constexpr float kCentreScale = 0.70710678f;

/* The TTT box parameters of a frame from its three inputs (l, r and the
 * scaled centre): the prediction coefficients as the least squares fit
 * of the centre on the downmix pair with, as ICC, the share of the
 * three signals' energy the prediction keeps (the decoder restores the
 * rest by 1 / ICC in mode 1, by its decorrelator in mode 0; an ICC
 * index above 4 would make the decoder's 1 / ICC vanish, so the share
 * is held at index 4), or the energy ratios of the energy modes. */
void ttt_params(const Hyb* l, const Hyb* r, const Hyb* c, const int* slot, int num_sets,
                const uint8_t* kernel, const Config& cfg, FrameParams& p) {
  int from = 0;
  for (int s = 0; s < num_sets; s++) {
    double pll[kMaxPb] = {0}, prr[kMaxPb] = {0}, plr[kMaxPb] = {0}, pcl[kMaxPb] = {0},
           pcr[kMaxPb] = {0}, ecc[kMaxPb] = {0}, el[kMaxPb] = {0}, er[kMaxPb] = {0};
    for (int t = from; t <= slot[s]; t++) {
      for (int k = 0; k < kHyb; k++) {
        const int pb = kernel[k];
        const double l0r = l[t][k][0] + c[t][k][0], l0i = l[t][k][1] + c[t][k][1];
        const double r0r = r[t][k][0] + c[t][k][0], r0i = r[t][k][1] + c[t][k][1];
        const double cr = c[t][k][0], ci = c[t][k][1];
        pll[pb] += l0r * l0r + l0i * l0i;
        prr[pb] += r0r * r0r + r0i * r0i;
        plr[pb] += l0r * r0r + l0i * r0i;
        pcl[pb] += cr * l0r + ci * l0i;
        pcr[pb] += cr * r0r + ci * r0i;
        ecc[pb] += cr * cr + ci * ci;
        el[pb] += l[t][k][0] * l[t][k][0] + l[t][k][1] * l[t][k][1];
        er[pb] += r[t][k][0] * r[t][k][0] + r[t][k][1] * r[t][k][1];
      }
    }
    for (int pb = 0; pb < kMaxPb; pb++) {
      const int range = pb < cfg.ttt_split ? 0 : 1;
      const bool pred = cfg.ttt_mode[range] < 2;
      p.ttt_cpc1[s][pb] = 10;
      p.ttt_cpc2[s][pb] = 10;
      p.ttt_icc[s][pb] = 0;
      p.ttt_cld1[s][pb] = 15;
      p.ttt_cld2[s][pb] = 0;
      if (pb >= cfg.bands) {
        continue;
      }
      if (pred) {
        const double det = pll[pb] * prr[pb] - plr[pb] * plr[pb];
        double a = 0.0, bb = 0.0;
        if (det > 1e-12 * (pll[pb] * prr[pb] + kEps)) {
          a = (pcl[pb] * prr[pb] - pcr[pb] * plr[pb]) / det;
          bb = (pcr[pb] * pll[pb] - pcl[pb] * plr[pb]) / det;
        }
        p.ttt_cpc1[s][pb] = quantise_cpc((float)(1.0 - 3.0 * a));
        p.ttt_cpc2[s][pb] = quantise_cpc((float)(1.0 - 3.0 * bb));
        /* the energies of the predicted centre and of l0 and r0 minus it
         * against those of the signals, the centre counted at its
         * output scale */
        const double e_c = a * a * pll[pb] + bb * bb * prr[pb] + 2.0 * a * bb * plr[pb];
        const double e_l = pll[pb] - 2.0 * (a * pll[pb] + bb * plr[pb]) + e_c;
        const double e_r = prr[pb] - 2.0 * (a * plr[pb] + bb * prr[pb]) + e_c;
        const double kept = (e_l + e_r + 2.0 * e_c) / (el[pb] + er[pb] + 2.0 * ecc[pb] + kEps);
        const double rho = sqrt(std::max(0.0, std::min(1.0, kept)));
        p.ttt_icc[s][pb] = std::min(4, quantise_icc((float)rho));
      } else {
        p.ttt_cld1[s][pb] = quantise_cld((float)(10.0 * log10((el[pb] + er[pb] + kEps) / (ecc[pb] + kEps))));
        p.ttt_cld2[s][pb] = quantise_cld((float)(10.0 * log10((el[pb] + kEps) / (er[pb] + kEps))));
      }
    }
    from = slot[s] + 1;
  }
}

/* The tree over one frame: six hybrid input channels in, the downmix
 * channel(s) and the parameters out.  Boxes keep their gain history. */
struct Tree {
  const Config& cfg;
  OttBox box[5];
  explicit Tree(const Config& c) : cfg(c) {}
  /* in[ch][slot], out[ch][slot] */
  void run(const Hyb* const* in, const int* slot, int num_sets, const uint8_t* kernel, Hyb** out,
           FrameParams& p) {
    static Hyb tmp[5][kSlots];
    auto ott = [&](int b, const Hyb* a, const Hyb* bb, Hyb* o) {
      box[b].run(a, bb, slot, num_sets, kernel, cfg.ott_bands[b], cfg.ott_lfe[b] != 0, cfg.lfe_bands,
                 o, p.ott_cld[b], p.ott_icc[b]);
    };
    const Hyb* L = in[0];
    const Hyb* R = in[1];
    const Hyb* C = in[2];
    const Hyb* LFE = in[3];
    const Hyb* Ls = in[4];
    const Hyb* Rs = in[5];
    switch (cfg.tree) {
      case AAC_MPS_TREE_212:
        /* the 2-1-2 tree in the AAC form (a signalling experiment): the
         * front pair alone */
        ott(0, L, R, out[0]);
        break;
      case AAC_MPS_TREE_525: {
        ott(0, C, LFE, tmp[0]);  /* c */
        ott(1, L, Ls, tmp[1]);   /* l */
        ott(2, R, Rs, tmp[2]);   /* r */
        for (int t = 0; t < kSlots; t++) {
          for (int k = 0; k < kHyb; k++) {
            for (int c = 0; c < 2; c++) {
              tmp[0][t][k][c] *= kCentreScale;
            }
          }
        }
        ttt_params(tmp[1], tmp[2], tmp[0], slot, num_sets, kernel, cfg, p);
        for (int t = 0; t < kSlots; t++) {
          for (int k = 0; k < kHyb; k++) {
            for (int c = 0; c < 2; c++) {
              out[0][t][k][c] = tmp[1][t][k][c] + tmp[0][t][k][c];
              out[1][t][k][c] = tmp[2][t][k][c] + tmp[0][t][k][c];
            }
          }
        }
        break;
      }
      case AAC_MPS_TREE_5151:
        ott(3, L, R, tmp[3]);         /* f */
        ott(4, C, LFE, tmp[4]);       /* c */
        ott(1, tmp[3], tmp[4], tmp[1]); /* fc */
        ott(2, Ls, Rs, tmp[2]);       /* s */
        ott(0, tmp[1], tmp[2], out[0]);
        break;
      default: /* 5152 */
        ott(3, L, Ls, tmp[3]);        /* l */
        ott(4, R, Rs, tmp[4]);        /* r */
        ott(1, tmp[3], tmp[4], tmp[1]); /* lr */
        ott(2, C, LFE, tmp[2]);       /* c */
        ott(0, tmp[1], tmp[2], out[0]);
        break;
    }
    if (cfg.one_icc) {
      /* one ICC for every box: the first non-LFE box's */
      int src = 0;
      while (cfg.ott_lfe[src]) src++;
      for (int b = 0; b < cfg.num_ott; b++) {
        memcpy(p.ott_icc[b], p.ott_icc[src], sizeof(p.ott_icc[b]));
      }
    }
    memset(p.arb, 0, sizeof(p.arb));
  }
};

/* ── transport ──────────────────────────────────────────────────── */

/* Appends the MPEG Surround payload to an AAC-LC raw data block as fill
 * elements with EXT_SAC_DATA, in front of its END element: the END is
 * the last three bits before the zero padding. */
std::vector<uint8_t> add_fill_elements(const std::vector<uint8_t>& au, const std::vector<uint8_t>& payload,
                                       int anc_type) {
  int last = -1;
  for (int i = (int)au.size() * 8 - 1; i >= 0; i--) {
    if ((au[i / 8] >> (7 - i % 8)) & 1) {
      last = i;
      break;
    }
  }
  if (last < 2) {
    fprintf(stderr, "mps-enc: no END element in the access unit\n");
    exit(1);
  }
  const int keep = last - 2;
  Bits b(au.size() + payload.size() + 64);
  for (int i = 0; i < keep; i++) {
    b.put((au[i / 8] >> (7 - i % 8)) & 1u, 1);
  }
  const int max_data = 268; /* count 269 at most: one byte of flags and the data */
  size_t pos = 0;
  bool first = true;
  while (pos < payload.size() || first) {
    const size_t n = std::min<size_t>(max_data, payload.size() - pos);
    const bool stop = pos + n >= payload.size();
    const int count = (int)n + 1;
    b.put(AAC_ELEM_FIL, 3);
    if (count < 15) {
      b.put((uint32_t)count, 4);
    } else {
      b.put(15, 4);
      b.put((uint32_t)(count - 14), 8);
    }
    b.put(12, 4); /* EXT_SAC_DATA */
    b.put((uint32_t)anc_type, 2);
    b.put(first ? 1u : 0u, 1); /* ancStart */
    b.put(stop ? 1u : 0u, 1);  /* ancStop */
    for (size_t i = 0; i < n; i++) {
      b.put(payload[pos + i], 8);
    }
    pos += n;
    first = false;
  }
  b.put(AAC_ELEM_END, 3);
  b.align();
  return b.bytes();
}

void write_latm_value(Bits& b, uint32_t v) {
  int bytes = 1;
  while (bytes < 4 && (v >> (8 * bytes)) != 0) {
    bytes++;
  }
  b.put((uint32_t)(bytes - 1), 2);
  b.put(v, 8 * bytes);
}

/* One LOAS frame: a StreamMuxConfig with one layer (the AAC-LC config)
 * or two (the object type 30 config with sacPayloadEmbedding in the
 * second layer, carried as an empty stream), then the access unit. */
std::vector<uint8_t> loas_frame(const std::vector<uint8_t>& au, const AacAsc& core,
                                const std::vector<uint8_t>* sac_asc, int sac_asc_bits) {
  Bits b(au.size() + 256);
  b.put(0, 1); /* useSameStreamMux */
  const bool two_layers = sac_asc != nullptr;
  b.put(two_layers ? 1u : 0u, 1); /* audioMuxVersion */
  if (two_layers) {
    b.put(0, 1); /* audioMuxVersionA */
    write_latm_value(b, 0xFF); /* taraBufferFullness */
  }
  b.put(1, 1); /* allStreamsSameTimeFraming */
  b.put(0, 6); /* numSubFrames */
  b.put(0, 4); /* numProgram */
  b.put(two_layers ? 1u : 0u, 3); /* numLayer */
  for (int lay = 0; lay < (two_layers ? 2 : 1); lay++) {
    if (lay > 0) {
      b.put(0, 1); /* useSameConfig */
    }
    if (!two_layers) {
      aac_asc_write_bits(&core, &b.w);
    } else if (lay == 0) {
      Bits m(64);
      const int bits = aac_asc_write_bits(&core, &m.w);
      write_latm_value(b, (uint32_t)bits);
      aac_asc_write_bits(&core, &b.w);
    } else {
      write_latm_value(b, (uint32_t)sac_asc_bits);
      for (int i = 0; i < sac_asc_bits; i++) {
        b.put(((*sac_asc)[i / 8] >> (7 - i % 8)) & 1u, 1);
      }
    }
    b.put(0, 3);    /* frameLengthType */
    b.put(0xFF, 8); /* latmBufferFullness */
  }
  b.put(0, 1); /* otherDataPresent */
  b.put(0, 1); /* crcCheckPresent */
  /* PayloadLengthInfo per layer, the second layer empty */
  size_t n = au.size();
  while (n >= 255) {
    b.put(255, 8);
    n -= 255;
  }
  b.put((uint32_t)n, 8);
  if (two_layers) {
    b.put(0, 8);
  }
  for (uint8_t v : au) {
    b.put(v, 8);
  }
  b.align();
  const std::vector<uint8_t> body = b.bytes();
  Bits h(4);
  h.put(AAC_LOAS_SYNC, 11);
  h.put((uint32_t)body.size(), 13);
  std::vector<uint8_t> out = h.bytes();
  out.insert(out.end(), body.begin(), body.end());
  return out;
}

/* ── the round trip check against the decoder's parser ──────────── */

struct Verifier {
  AacMpsState* st = nullptr;
  int frames = 0, mismatches = 0;
  void init(const std::vector<uint8_t>& ssc) {
    AacBitReader r;
    aac_bitreader_init(&r, ssc.data(), (int)ssc.size());
    AacMpsConfig cfg;
    const int ret = aac_mps_config_read(&r, &cfg);
    if (ret != AAC_OK) {
      fprintf(stderr, "mps-enc: verify: the config does not parse (%d)\n", ret);
      exit(1);
    }
    st = aac_mps_create(&cfg, kFrame, kSlots, kRate);
    if (!st) {
      fprintf(stderr, "mps-enc: verify: aac_mps_create failed\n");
      exit(1);
    }
  }
  void check(const std::vector<uint8_t>& frame, const Config& c, const FrameParams& p) {
    AacBitReader r;
    aac_bitreader_init(&r, frame.data(), (int)frame.size());
    const int ret = aac_mps_read_frame(st, &r);
    frames++;
    if (ret != AAC_OK) {
      fprintf(stderr, "mps-enc: verify: frame %d does not parse (%d)\n", frames, ret);
      mismatches++;
      return;
    }
    const AacMpsFrameParams* q = aac_mps_frame_params(st);
    int bad = 0;
    /* the parser appends the frame-end set when the last coded set
     * sits before the frame's last slot */
    const int extend = p.slot[p.num_sets - 1] != kSlots - 1 ? 1 : 0;
    if (q->num_sets != p.num_sets + extend || st->independency != p.indep) {
      bad++;
    }
    for (int s = 0; s < p.num_sets; s++) {
      if (q->param_slot[s] != p.slot[s]) bad++;
      /* with a quant mode the parser scales the OTT CLDs of the mono trees */
      const bool scaled = c.quant_mode != 0 && c.num_in == 1 && c.num_ott == 5;
      for (int box = 0; box < c.num_ott; box++) {
        for (int pb = 0; pb < c.ott_bands[box]; pb++) {
          if (!scaled && q->ott_cld[box][s][pb] != p.ott_cld[box][s][pb]) bad++;
          if (!c.ott_lfe[box] && q->ott_icc[box][s][pb] != p.ott_icc[box][s][pb]) bad++;
        }
      }
      for (int pb = 0; c.num_ttt && pb < c.bands; pb++) {
        const bool pred = c.ttt_mode[pb < c.ttt_split ? 0 : 1] < 2;
        if (pred) {
          if (q->ttt_cpc1[0][s][pb] != p.ttt_cpc1[s][pb]) bad++;
          if (q->ttt_cpc2[0][s][pb] != p.ttt_cpc2[s][pb]) bad++;
          if (q->ttt_icc[0][s][pb] != p.ttt_icc[s][pb]) bad++;
        } else {
          if (q->ttt_cld1[0][s][pb] != p.ttt_cld1[s][pb]) bad++;
          if (q->ttt_cld2[0][s][pb] != p.ttt_cld2[s][pb]) bad++;
        }
      }
      if (c.arbdmx) {
        for (int ch = 0; ch < c.num_in; ch++) {
          for (int pb = 0; pb < c.bands; pb++) {
            if (q->arbdmx_gain[ch][s][pb] != p.arb[ch][s][pb]) bad++;
          }
        }
      }
    }
    if (aac_bitreader_bits_left(&r) >= 8 || r.overread) {
      bad++;
    }
    if (bad) {
      fprintf(stderr, "mps-enc: verify: frame %d: %d parameters differ after the round trip\n",
              frames, bad);
      mismatches++;
    }
  }
  /* The parsed parameters of the last frame, one line per parameter
   * type, box and set, in the form the instrumented libxaac decoder
   * prints them. */
  void dump(const Config& c) {
    const AacMpsFrameParams* q = aac_mps_frame_params(st);
    printf("F %d sets %d slots", frames - 1, q->num_sets);
    for (int s = 0; s < q->num_sets; s++) printf(" %d", q->param_slot[s]);
    printf("\n");
    for (int box = 0; box < c.num_ott; box++) {
      for (int s = 0; s < q->num_sets; s++) {
        printf("cld %d %d:", box, s);
        for (int pb = 0; pb < c.ott_bands[box]; pb++) printf(" %d", q->ott_cld[box][s][pb]);
        printf("\n");
        if (!c.ott_lfe[box]) {
          printf("icc %d %d:", box, s);
          for (int pb = 0; pb < c.ott_bands[box]; pb++) printf(" %d", q->ott_icc[box][s][pb]);
          printf("\n");
        }
      }
    }
    for (int box = 0; box < c.num_ttt; box++) {
      for (int s = 0; s < q->num_sets; s++) {
        const char* tags[5] = {"cpc1", "cpc2", "ticc", "tcld1", "tcld2"};
        const int* arr[5] = {q->ttt_cpc1[box][s], q->ttt_cpc2[box][s], q->ttt_icc[box][s],
                             q->ttt_cld1[box][s], q->ttt_cld2[box][s]};
        for (int k = 0; k < 5; k++) {
          printf("%s %d %d:", tags[k], box, s);
          for (int pb = 0; pb < c.bands; pb++) {
            /* the arrays of the mode not in use print as 0, as libxaac leaves them */
            const bool pred = c.ttt_mode[pb < c.ttt_split ? 0 : 1] < 2;
            printf(" %d", pred == (k < 3) ? arr[k][pb] : 0);
          }
          printf("\n");
        }
      }
    }
    if (c.arbdmx) {
      for (int ch = 0; ch < c.num_in; ch++) {
        for (int s = 0; s < q->num_sets; s++) {
          printf("arb %d %d:", ch, s);
          for (int pb = 0; pb < c.bands; pb++) printf(" %d", q->arbdmx_gain[ch][s][pb]);
          printf("\n");
        }
      }
    }
  }
  ~Verifier() { aac_mps_destroy(st); }
};

/* ── the encoder ────────────────────────────────────────────────── */

int run(const Options& o) {
  int channels = 0, rate = 0;
  std::vector<float> pcm = read_input(o.in, &channels, &rate);
  if (channels != kInChannels || rate != kRate) {
    fprintf(stderr, "mps-enc: the input must be 48 kHz with six channels (got %d Hz, %d)\n", rate,
            channels);
    return 1;
  }
  size_t frames_in = pcm.size() / kInChannels;
  if (o.seconds > 0.0) {
    frames_in = std::min(frames_in, (size_t)(o.seconds * kRate));
  }
  const int num_frames = (int)((frames_in + kFrame - 1) / kFrame);
  pcm.resize((size_t)num_frames * kFrame * kInChannels, 0.0f);

  const Config cfg = make_config(o);
  const uint8_t* kernel = aac_mps212_kernel(cfg.bands);
  if (!kernel) {
    fprintf(stderr, "mps-enc: no band table for %d bands\n", cfg.bands);
    return 1;
  }
  const Books books;
  EcOpts ec;
  ec.huffman = o.huffman;
  ec.pairs = o.pairs;
  ec.two_d = o.huffman;
  ec.aac_form = true;
  aac_qmf_tables_init();

  /* the spatial configuration and its wrappers */
  Bits cb(256);
  write_config(cb, cfg);
  const std::vector<uint8_t> ssc = cb.bytes();
  std::vector<uint8_t> header; /* the in-band header: flag + length, then the config */
  header.push_back((uint8_t)(ssc.size() < 127 ? ssc.size() : 127));
  if (ssc.size() >= 127) {
    header.push_back((uint8_t)((ssc.size() - 127) >> 8));
    header.push_back((uint8_t)((ssc.size() - 127) & 0xFF));
  }
  header.insert(header.end(), ssc.begin(), ssc.end());
  std::vector<uint8_t> sac_asc;
  int sac_asc_bits = 0;
  if (o.asc_form) {
    Bits a(256);
    a.put(30, 5); /* audioObjectType MPEG Surround */
    a.put(3, 4);  /* 48 kHz */
    a.put(6, 4);  /* channelConfiguration 5.1 */
    a.put(1, 1);  /* sacPayloadEmbedding */
    write_config(a, cfg);
    sac_asc_bits = a.bits();
    sac_asc = a.bytes();
  }
  AacAsc core_asc;
  aac_asc_init(&core_asc, kRate, cfg.num_in);

  /* the core encoder on the downmix */
  AacEncoderHandle enc = aac_encoder_create(kRate, cfg.num_in, o.bitrate, AAC_AOT_LC, AAC_RC_CBR);
  if (!enc) {
    fprintf(stderr, "mps-enc: cannot create the core encoder\n");
    return 1;
  }
  aac_encoder_set_transport(enc, AAC_TRANSPORT_RAW);

  /* two analyses: the downmix path on the input, the parameter path on
   * the input delayed by the downmix path's filterbank delay */
  const int param_delay = kDownmixDelay + o.param_shift;
  std::vector<Analyser> ana_dmx(kInChannels), ana_par(kInChannels);
  Tree tree_dmx(cfg), tree_par(cfg);
  std::vector<FrameParams> params(num_frames);
  const float g_sur = aac_mps_surround_gain[cfg.gain_sur];
  const float g_lfe = aac_mps_lfe_gain[cfg.gain_lfe];
  const float g_dmx = aac_mps212_fixed_gain[cfg.gain_dmx];
  AacQmfSynthesis syn[2];
  aac_qmf_synthesis_init(&syn[0], 64);
  aac_qmf_synthesis_init(&syn[1], 64);
  std::vector<std::vector<uint8_t>> aus;
  std::vector<uint8_t> aubuf(1 << 16);
  static Hyb hyb_dmx[kInChannels][kSlots], hyb_par[kInChannels][kSlots], dmx[2][kSlots];
  const Hyb* in_dmx[kInChannels];
  const Hyb* in_par[kInChannels];
  Hyb* out_dmx[2] = {dmx[0], dmx[1]};
  for (int ch = 0; ch < kInChannels; ch++) {
    in_dmx[ch] = hyb_dmx[ch];
    in_par[ch] = hyb_par[ch];
  }
  int slot[kMaxSets];
  const int num_sets = std::max(1, std::min(kMaxSets, o.sets));
  for (int i = 0; i < num_sets; i++) {
    slot[i] = o.framing ? (kSlots * (2 * i + 1)) / (2 * num_sets) : (kSlots * (i + 1) + num_sets - 1) / num_sets - 1;
  }
  if (o.framing) {
    /* distinct, ascending positions, the last one anywhere before the end */
    for (int i = 1; i < num_sets; i++) {
      slot[i] = std::max(slot[i], slot[i - 1] + 1);
    }
  }
  auto sample = [&](int frame_idx, int ch, int i, int delay) -> float {
    const long n = (long)frame_idx * kFrame + i - delay;
    if (n < 0 || n >= (long)frames_in) {
      return 0.0f;
    }
    float v = pcm[(size_t)n * kInChannels + ch];
    if (ch == 3) v /= g_lfe;
    if (ch == 4 || ch == 5) v /= g_sur;
    return v;
  };
  std::vector<float> core_in((size_t)kFrame * cfg.num_in);
  for (int f = 0; f < num_frames; f++) {
    float in[kInChannels][64];
    for (int t = 0; t < kSlots; t++) {
      for (int ch = 0; ch < kInChannels; ch++) {
        for (int i = 0; i < 64; i++) {
          in[ch][i] = sample(f, ch, t * 64 + i, 0);
        }
        ana_dmx[ch].push(in[ch], hyb_dmx[ch][t]);
        for (int i = 0; i < 64; i++) {
          in[ch][i] = sample(f, ch, t * 64 + i, param_delay);
        }
        ana_par[ch].push(in[ch], hyb_par[ch][t]);
      }
    }
    FrameParams scratch;
    memset(&scratch, 0, sizeof(scratch));
    scratch.num_sets = num_sets;
    memcpy(scratch.slot, slot, sizeof(slot));
    tree_dmx.run(in_dmx, slot, num_sets, kernel, out_dmx, scratch);
    FrameParams& p = params[f];
    memset(&p, 0, sizeof(p));
    p.num_sets = num_sets;
    memcpy(p.slot, slot, sizeof(slot));
    p.indep = o.indep_every > 0 && f % o.indep_every == 0;
    tree_par.run(in_par, slot, num_sets, kernel, out_dmx, p);
    /* the downmix of this frame to the core: hybrid synthesis, QMF
     * synthesis, the clip protection gain */
    for (int t = 0; t < kSlots; t++) {
      for (int ch = 0; ch < cfg.num_in; ch++) {
        float X[64][2];
        aac_mps212_hybrid_synthesis(dmx[ch][t], X);
        float out[64];
        aac_qmf_synthesize(&syn[ch], X, out);
        for (int i = 0; i < 64; i++) {
          core_in[(size_t)(t * 64 + i) * cfg.num_in + ch] = out[i] / g_dmx;
        }
      }
    }
    const int n = aac_encoder_encode(enc, core_in.data(), kFrame, aubuf.data(), (int)aubuf.size());
    if (n < 0) {
      fprintf(stderr, "mps-enc: core encoder error %d\n", n);
      return 1;
    }
    aus.emplace_back(aubuf.begin(), aubuf.begin() + n);
  }
  for (;;) {
    const int n = aac_encoder_flush(enc, aubuf.data(), (int)aubuf.size());
    if (n <= 0) {
      break;
    }
    aus.emplace_back(aubuf.begin(), aubuf.begin() + n);
  }
  aac_encoder_destroy(enc);
  /* the tree ran on the downmix path too: redo the parameter path's
   * downmix outputs are not used, only its parameters */

  /* the payloads: access unit j carries the parameters of frame
   * j - frame_offset */
  History hist;
  memset(&hist, 0, sizeof(hist));
  for (int range = 0; range < 2; range++) {
    for (int pb = 0; pb < kMaxPb; pb++) {
      hist.ttt_cpc1[range][pb] = hist.ttt_cpc2[range][pb] = 10;
      hist.ttt_cld1[range][pb] = 15;
    }
  }
  for (int box = 0; box < cfg.num_ott; box++) {
    for (int pb = 0; pb < kMaxPb; pb++) {
      hist.ott_cld[box][pb] = cfg.ott_lfe[box] ? 15 : 0;
    }
  }
  Verifier verifier;
  if (o.verify || o.dump) {
    verifier.init(ssc);
  }
  FILE* out = fopen(o.out.c_str(), "wb");
  if (!out) {
    fprintf(stderr, "mps-enc: cannot create %s\n", o.out.c_str());
    return 1;
  }
  size_t payload_bytes = 0;
  for (size_t j = 0; j < aus.size(); j++) {
    const int pf = std::max(0, std::min(num_frames - 1, (int)j - o.frame_offset));
    FrameParams p = params[pf];
    if (j == 0) {
      p.indep = 1;
    }
    Bits fb;
    write_frame(fb, books, cfg, p, hist, o.framing, ec);
    const std::vector<uint8_t> frame = fb.bytes();
    if (o.verify || o.dump) {
      verifier.check(frame, cfg, p);
    }
    if (o.dump) {
      verifier.dump(cfg);
    }
    std::vector<uint8_t> payload;
    int anc_type = 0;
    if (!o.asc_form && o.header_every > 0 && (int)j % o.header_every == 0) {
      payload = header;
      anc_type = 1;
    }
    payload.insert(payload.end(), frame.begin(), frame.end());
    payload_bytes += payload.size();
    const std::vector<uint8_t> au = o.core_only ? aus[j] : add_fill_elements(aus[j], payload, anc_type);
    const std::vector<uint8_t> loas =
        loas_frame(au, core_asc, o.asc_form ? &sac_asc : nullptr, sac_asc_bits);
    fwrite(loas.data(), 1, loas.size(), out);
  }
  fclose(out);
  fprintf(stderr, "mps-enc: %zu access units, %d spatial frames, %zu bytes of spatial payload (%.1f kbit/s)\n",
          aus.size(), num_frames, payload_bytes,
          8.0 * (double)payload_bytes * kRate / (double)(aus.size() * kFrame) / 1000.0);
  if (o.verify) {
    fprintf(stderr, "mps-enc: round trip: %d frames, %d with differences\n", verifier.frames,
            verifier.mismatches);
    return verifier.mismatches ? 1 : 0;
  }
  return 0;
}

int usage() {
  fprintf(stderr, "usage: mps-enc <in.wav|in.f32> <out.loas> [--tree 5151|5152|525|212] [--form header|asc]\n"
                  "       [--huffman] [--pairs] [--sets N] [--framing 0|1] [--freq-res R] [--ttt-mode M]\n"
                  "       [--dual-mode B] [--gain-sur I] [--gain-lfe I] [--gain-dmx I] [--one-icc] [--quant-mode M]\n"
                  "       [--smooth]\n"
                  "       [--temp-shape C] [--arbdmx] [--header-every N] [--indep-every N] [--bitrate B]\n"
                  "       [--seconds S] [--param-shift N] [--frame-offset F] [--verify] [--dump]\n");
  return 2;
}

int parse_args(int argc, char** argv, Options& o) {
  if (argc < 3) {
    return usage();
  }
  o.in = argv[1];
  o.out = argv[2];
  for (int i = 3; i < argc; i++) {
    const std::string a = argv[i];
    auto value = [&]() -> const char* {
      if (i + 1 >= argc) {
        usage();
        exit(2);
      }
      return argv[++i];
    };
    if (a == "--tree") {
      const std::string t = value();
      o.tree = t == "5151" ? AAC_MPS_TREE_5151 : t == "5152" ? AAC_MPS_TREE_5152
             : t == "212" ? AAC_MPS_TREE_212 : AAC_MPS_TREE_525;
    } else if (a == "--form") {
      o.asc_form = std::string(value()) == "asc";
    } else if (a == "--huffman") {
      o.huffman = true;
    } else if (a == "--pairs") {
      o.pairs = true;
    } else if (a == "--sets") {
      o.sets = atoi(value());
    } else if (a == "--framing") {
      o.framing = atoi(value());
    } else if (a == "--freq-res") {
      o.freq_res = atoi(value());
    } else if (a == "--ttt-mode") {
      o.ttt_mode = atoi(value());
    } else if (a == "--dual-mode") {
      o.dual_bands = atoi(value());
    } else if (a == "--gain-sur") {
      o.gain_sur = atoi(value());
    } else if (a == "--gain-lfe") {
      o.gain_lfe = atoi(value());
    } else if (a == "--gain-dmx") {
      o.gain_dmx = atoi(value());
    } else if (a == "--one-icc") {
      o.one_icc = true;
    } else if (a == "--quant-mode") {
      o.quant_mode = atoi(value());
    } else if (a == "--smooth") {
      o.smooth = true;
    } else if (a == "--temp-shape") {
      o.temp_shape = atoi(value());
    } else if (a == "--arbdmx") {
      o.arbdmx = true;
    } else if (a == "--header-every") {
      o.header_every = atoi(value());
    } else if (a == "--indep-every") {
      o.indep_every = atoi(value());
    } else if (a == "--bitrate") {
      o.bitrate = atoi(value());
    } else if (a == "--seconds") {
      o.seconds = atof(value());
    } else if (a == "--param-shift") {
      o.param_shift = atoi(value());
    } else if (a == "--frame-offset") {
      o.frame_offset = atoi(value());
    } else if (a == "--verify") {
      o.verify = true;
    } else if (a == "--dump") {
      o.dump = true;
    } else if (a == "--core-only") {
      o.core_only = true;
    } else {
      return usage();
    }
  }
  if (o.freq_res < 1 || o.freq_res > 7 || o.ttt_mode < 0 || o.ttt_mode > 5 || o.temp_shape < 0 ||
      o.quant_mode < 0 || o.quant_mode > 2 ||
      o.temp_shape > 2 || o.gain_sur < 0 || o.gain_sur > 4 || o.gain_lfe < 0 || o.gain_lfe > 4 ||
      o.gain_dmx < 0 || o.gain_dmx > 7) {
    return usage();
  }
  return 0;
}

}  // namespace mpsenc

#ifndef MPS_ENC_NO_MAIN
int main(int argc, char** argv) {
  mpsenc::Options o;
  const int ret = mpsenc::parse_args(argc, argv, o);
  if (ret) {
    return ret;
  }
  return mpsenc::run(o);
}
#endif
