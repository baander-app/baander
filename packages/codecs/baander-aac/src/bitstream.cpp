#include "bitstream.h"

#include <algorithm>
#include <cstdlib>
#include <cstring>

#include "aac_tables.h"

/* ── Bit Reader ────────────────────────────────────────────────── */

void aac_bitreader_init(AacBitReader* r, const uint8_t* d, int s) {
  r->data = d;
  r->size = s;
  r->pos = 0;
  r->overread = 0;
}

int aac_bitreader_bits_left(const AacBitReader* r) { return r->size * 8 - r->pos; }

uint32_t aac_bitreader_peek(const AacBitReader* r, int n) {
  if (n <= 0) {
    return 0;
  }
  const int byte = r->pos >> 3;
  const int shift = r->pos & 7;
  /* Up to 25 bits come out of the next four bytes when they exist. */
  if (n <= 25 && byte + 4 <= r->size) {
    const uint8_t* p = r->data + byte;
    const uint32_t w =
        ((uint32_t)p[0] << 24) | ((uint32_t)p[1] << 16) | ((uint32_t)p[2] << 8) | (uint32_t)p[3];
    return (w << shift) >> (32 - n);
  }
  uint64_t cache = 0;
  if (byte + 8 <= r->size) {
    for (int i = 0; i < 8; i++) {
      cache = (cache << 8) | r->data[byte + i];
    }
  } else {
    for (int i = 0; i < 8; i++) {
      const int b = byte + i;
      cache = (cache << 8) | ((b >= 0 && b < r->size) ? r->data[b] : 0);
    }
  }
  return (uint32_t)((cache << shift) >> (64 - n));
}

uint32_t aac_bitreader_read(AacBitReader* r, int n) {
  uint32_t v = aac_bitreader_peek(r, n);
  aac_bitreader_skip(r, n);
  return v;
}

/* A negative or overlong skip counts as reading past the end. */
void aac_bitreader_skip(AacBitReader* r, int n) {
  const int end = r->size * 8;
  if (n < 0 || n > end - r->pos) {
    r->overread = 1;
    r->pos = end;
    return;
  }
  r->pos += n;
}

void aac_bitreader_byte_align(AacBitReader* r) { aac_bitreader_skip(r, (8 - (r->pos & 7)) & 7); }

/* ── Huffman decoding trees ────────────────────────────────────── */

/* Binary decoding tree per codebook: node i has children child[i][0/1];
 * a negative child encodes the leaf -(index + 1). */
namespace {
struct HuffTree {
  short child[2 * 289][2];
  int nodes;
};

HuffTree g_spec_trees[AAC_NUM_CODEBOOKS + 1];
HuffTree g_sf_tree;

void tree_build(HuffTree* t, const uint32_t* codes_left_aligned, const uint32_t* codes_right,
                const uint8_t* lens, int count) {
  t->nodes = 1;
  t->child[0][0] = t->child[0][1] = 0;
  for (int i = 0; i < count; i++) {
    const int len = lens[i];
    const uint32_t code = codes_left_aligned ? codes_left_aligned[i] >> (32 - len) : codes_right[i];
    int node = 0;
    for (int b = len - 1; b >= 0; b--) {
      const int bit = (int)((code >> b) & 1);
      if (b == 0) {
        t->child[node][bit] = (short)-(i + 1);
      } else {
        if (t->child[node][bit] <= 0) {
          const int next = t->nodes++;
          t->child[next][0] = t->child[next][1] = 0;
          t->child[node][bit] = (short)next;
        }
        node = t->child[node][bit];
      }
    }
  }
}

/* Returns the leaf index or -1 for a codeword that is not in the table. */
int tree_decode(const HuffTree* t, AacBitReader* r) {
  int node = 0;
  for (int depth = 0; depth < 20; depth++) {
    const int next = t->child[node][aac_bitreader_read(r, 1)];
    if (next < 0) {
      return -next - 1;
    }
    if (next == 0) {
      return -1;
    }
    node = next;
  }
  return -1;
}

/* Fast path of the decoding: the next kLookupBits bits index a table whose
 * entry names the leaf and the codeword length for every codeword of at
 * most that length; an entry of zero means the codeword is longer and the
 * tree walk takes over (such codewords are the rare ones). */
constexpr int kLookupBits = 12;
struct HuffLookup {
  uint16_t entry[1 << kLookupBits]; /* (leaf index << 5) | length */
};

HuffLookup g_spec_lookup[AAC_NUM_CODEBOOKS + 1];
HuffLookup g_sf_lookup;
/* The values of every leaf of the spectral books: the magnitudes of an
 * unsigned book, the signed values of a signed one. */
int8_t g_spec_values[AAC_NUM_CODEBOOKS + 1][289][4];

void lookup_build(HuffLookup* t, const uint32_t* codes_left_aligned, const uint32_t* codes_right,
                  const uint8_t* lens, int count) {
  memset(t->entry, 0, sizeof(t->entry));
  for (int i = 0; i < count; i++) {
    const int len = lens[i];
    if (len > kLookupBits) {
      continue;
    }
    const uint32_t code = codes_left_aligned ? codes_left_aligned[i] >> (32 - len) : codes_right[i];
    const uint32_t base = code << (kLookupBits - len);
    const uint32_t span = 1u << (kLookupBits - len);
    for (uint32_t j = 0; j < span; j++) {
      t->entry[base + j] = (uint16_t)((i << 5) | len);
    }
  }
}

int lookup_decode(const HuffLookup* t, const HuffTree* tree, AacBitReader* r) {
  const uint16_t e = t->entry[aac_bitreader_peek(r, kLookupBits)];
  if (e) {
    aac_bitreader_skip(r, e & 31);
    return e >> 5;
  }
  return tree_decode(tree, r);
}

struct TreeInit {
  TreeInit() {
    aac_tables_init();
    for (int cb = 1; cb <= AAC_NUM_CODEBOOKS; cb++) {
      tree_build(&g_spec_trees[cb], aac_huff_code[cb], nullptr, aac_huff_len[cb],
                 aac_huff_count[cb]);
      lookup_build(&g_spec_lookup[cb], aac_huff_code[cb], nullptr, aac_huff_len[cb],
                   aac_huff_count[cb]);
      const AacCodebookInfo* info = &aac_codebook_info[cb];
      const int mod = info->is_unsigned ? info->max_val + 1 : 2 * info->max_val + 1;
      for (int idx = 0; idx < aac_huff_count[cb]; idx++) {
        int t = idx;
        for (int k = info->dim - 1; k >= 0; k--) {
          g_spec_values[cb][idx][k] = (int8_t)(t % mod - (info->is_unsigned ? 0 : info->max_val));
          t /= mod;
        }
      }
    }
    tree_build(&g_sf_tree, nullptr, aac_scalefactor_code, aac_scalefactor_bits, 121);
    lookup_build(&g_sf_lookup, nullptr, aac_scalefactor_code, aac_scalefactor_bits, 121);
  }
} g_tree_init;  // NOLINT(bugprone-throwing-static-initialization)
}  // namespace

int aac_bitreader_read_huffman(AacBitReader* r, int cb, int* out) {
  if (cb < 1 || cb > AAC_NUM_CODEBOOKS) {
    return AAC_ERR_INVALID_ARG;
  }
  const int idx = lookup_decode(&g_spec_lookup[cb], &g_spec_trees[cb], r);
  if (idx < 0) {
    return AAC_ERR_DECODE;
  }
  const AacCodebookInfo* info = &aac_codebook_info[cb];
  const int dim = info->dim;
  const int8_t* vals = g_spec_values[cb][idx];
  int signs = 0;
  for (int k = 0; k < dim; k++) {
    out[k] = vals[k];
    signs += vals[k] != 0;
  }
  if (info->is_unsigned) {
    /* One sign bit per nonzero magnitude, in coefficient order. */
    if (signs) {
      const uint32_t s = aac_bitreader_read(r, signs);
      int bit = signs - 1;
      for (int k = 0; k < dim; k++) {
        if (out[k] != 0) {
          if ((s >> bit) & 1u) {
            out[k] = -out[k];
          }
          bit--;
        }
      }
    }
    if (cb == AAC_CB_ESCAPE) {
      for (int k = 0; k < dim; k++) {
        if (out[k] == 16 || out[k] == -16) {
          int n = 4;
          while (aac_bitreader_read(r, 1)) {
            if (++n > 12) {
              return AAC_ERR_DECODE;
            }
          }
          const int mag = (1 << n) + (int)aac_bitreader_read(r, n);
          out[k] = out[k] < 0 ? -mag : mag;
        }
      }
    }
  }
  return r->overread ? AAC_ERR_DECODE : 0;
}

int aac_bitreader_read_scalefactor(AacBitReader* r) {
  const int idx = lookup_decode(&g_sf_lookup, &g_sf_tree, r);
  if (idx < 0) {
    return AAC_SF_INVALID;
  }
  return idx - AAC_SCALE_DIFF_ZERO;
}

/* ── Bit Writer ────────────────────────────────────────────────── */

void aac_bitwriter_init(AacBitWriter* w, uint8_t* d, int c) {
  w->data = d;
  w->capacity = c;
  w->byte_pos = 0;
  w->bit_pos = 0;
  w->overflow = 0;
  memset(d, 0, c);
}

/* Fields wider than 32 bits are written with leading zero bits.  The bits
 * go out a byte at a time; a write that runs out of buffer stops there and
 * flags the overflow. */
void aac_bitwriter_write(AacBitWriter* w, uint32_t v, int n) {
  while (n > 0) {
    if (w->byte_pos >= w->capacity) {
      w->overflow = 1;
      return;
    }
    const int room = 8 - w->bit_pos;
    const int take = n < room ? n : room;
    const int low = n - take; /* the lowest bit of v that goes out now */
    const uint32_t bits = low >= 32 ? 0u : (v >> low) & ((1u << take) - 1u);
    w->data[w->byte_pos] |= (uint8_t)(bits << (room - take));
    w->bit_pos += take;
    n -= take;
    if (w->bit_pos == 8) {
      w->bit_pos = 0;
      w->byte_pos++;
    }
  }
}

int aac_bitwriter_overflow(const AacBitWriter* w) { return w->overflow; }

/* Codebook index of v, or -1 when the values do not fit the book. */
static int huffman_index(int cb, const int* v) {
  const AacCodebookInfo* info = &aac_codebook_info[cb];
  const int mv = info->max_val;
  int idx = 0;
  for (int k = 0; k < info->dim; k++) {
    if (info->is_unsigned) {
      int mag = std::abs(v[k]);
      if (cb == AAC_CB_ESCAPE) {
        if (mag > AAC_ESC_MAX_MAG) {
          return -1;
        }
        if (mag > 15) {
          mag = 16;
        }
      } else if (mag > mv) {
        return -1;
      }
      idx = idx * (mv + 1) + mag;
    } else {
      if (v[k] < -mv || v[k] > mv) {
        return -1;
      }
      idx = idx * (2 * mv + 1) + v[k] + mv;
    }
  }
  return idx;
}

int aac_bitwriter_write_huffman(AacBitWriter* w, int cb, const int* v) {
  if (cb < 1 || cb > AAC_NUM_CODEBOOKS) {
    return AAC_ERR_INVALID_ARG;
  }
  const int idx = huffman_index(cb, v);
  if (idx < 0) {
    return AAC_ERR_INVALID_ARG;
  }
  const AacCodebookInfo* info = &aac_codebook_info[cb];
  const int len = aac_huff_len[cb][idx];
  aac_bitwriter_write(w, aac_huff_code[cb][idx] >> (32 - len), len);
  if (!info->is_unsigned) {
    return 0;
  }
  for (int k = 0; k < info->dim; k++) {
    if (v[k] != 0) {
      aac_bitwriter_write(w, v[k] < 0 ? 1u : 0u, 1);
    }
  }
  if (cb == AAC_CB_ESCAPE) {
    for (int k = 0; k < info->dim; k++) {
      const int mag = std::abs(v[k]);
      if (mag > 15) {
        /* value = 2^n + word, word < 2^n; prefix is (n - 4) ones + a zero */
        int n = 4;
        while (mag >= (1 << (n + 1))) {
          n++;
        }
        aac_bitwriter_write(w, (1u << (n - 4)) - 1, n - 4);
        aac_bitwriter_write(w, 0, 1);
        aac_bitwriter_write(w, (uint32_t)(mag - (1 << n)), n);
      }
    }
  }
  return 0;
}

void aac_bitwriter_write_scalefactor(AacBitWriter* w, int diff) {
  const int idx = diff + AAC_SCALE_DIFF_ZERO;
  if (idx < 0 || idx > 120) {
    w->overflow = 1; /* callers keep differences within +-60 */
    return;
  }
  aac_bitwriter_write(w, aac_scalefactor_code[idx], aac_scalefactor_bits[idx]);
}

void aac_bitwriter_byte_align(AacBitWriter* w) {
  if (w->bit_pos > 0) {
    w->byte_pos++;
    w->bit_pos = 0;
  }
}

int aac_bitwriter_bits_written(const AacBitWriter* w) { return w->byte_pos * 8 + w->bit_pos; }

int aac_bitwriter_bytes_written(const AacBitWriter* w) {
  return w->byte_pos + (w->bit_pos > 0 ? 1 : 0);
}

/* ── ADTS (ISO/IEC 13818-7 6.2 / ISO/IEC 14496-3 1.A.2) ─────────── */

int aac_adts_parse(AacAdtsHeader* h, const uint8_t* d, int s) {
  if (s < AAC_ADTS_HEADER_SIZE || d[0] != 0xFF || (d[1] & 0xF6) != 0xF0) {
    return AAC_ERR_DECODE;
  }
  AacBitReader r;
  aac_bitreader_init(&r, d, s);
  aac_bitreader_skip(&r, 12);
  h->id = (int)aac_bitreader_read(&r, 1);
  h->layer = (int)aac_bitreader_read(&r, 2);
  h->protection_absent = (int)aac_bitreader_read(&r, 1);
  h->object_type = (int)aac_bitreader_read(&r, 2) + 1;
  h->sample_rate_index = (int)aac_bitreader_read(&r, 4);
  h->private_bit = (int)aac_bitreader_read(&r, 1);
  h->channel_config = (int)aac_bitreader_read(&r, 3);
  h->original_copy = (int)aac_bitreader_read(&r, 1);
  h->home = (int)aac_bitreader_read(&r, 1);
  h->copyright_id_bit = (int)aac_bitreader_read(&r, 1);
  h->copyright_id_start = (int)aac_bitreader_read(&r, 1);
  h->frame_length = (int)aac_bitreader_read(&r, 13);
  h->buffer_fullness = (int)aac_bitreader_read(&r, 11);
  h->num_raw_blocks = (int)aac_bitreader_read(&r, 2) + 1;
  const int min_len = AAC_ADTS_HEADER_SIZE + (h->protection_absent ? 0 : 2);
  if (h->frame_length < min_len || h->sample_rate_index >= AAC_NUM_SAMPLE_RATES) {
    return AAC_ERR_DECODE;
  }
  return 0;
}

int aac_adts_write(const AacAdtsHeader* h, uint8_t* o) {
  AacBitWriter w;
  aac_bitwriter_init(&w, o, AAC_ADTS_HEADER_SIZE);
  aac_bitwriter_write(&w, 0xFFF, 12);
  aac_bitwriter_write(&w, h->id, 1);
  aac_bitwriter_write(&w, h->layer, 2);
  aac_bitwriter_write(&w, h->protection_absent, 1);
  aac_bitwriter_write(&w, h->object_type - 1, 2);
  aac_bitwriter_write(&w, h->sample_rate_index, 4);
  aac_bitwriter_write(&w, h->private_bit, 1);
  aac_bitwriter_write(&w, h->channel_config, 3);
  aac_bitwriter_write(&w, h->original_copy, 1);
  aac_bitwriter_write(&w, h->home, 1);
  aac_bitwriter_write(&w, h->copyright_id_bit, 1);
  aac_bitwriter_write(&w, h->copyright_id_start, 1);
  aac_bitwriter_write(&w, h->frame_length, 13);
  aac_bitwriter_write(&w, h->buffer_fullness, 11);
  aac_bitwriter_write(&w, h->num_raw_blocks - 1, 2);
  return aac_bitwriter_bytes_written(&w);
}

/* ── ADTS CRC ─────────────────────────────────────────────────────── */

uint16_t aac_crc16_bits(uint16_t crc, const uint8_t* data, int bit_offset, int nbits) {
  for (int i = 0; i < nbits; i++) {
    const int pos = bit_offset + i;
    const unsigned bit = (data[pos >> 3] >> (7 - (pos & 7))) & 1u;
    const unsigned feedback = ((crc >> 15) & 1u) ^ bit;
    crc = (uint16_t)((crc << 1) ^ (feedback ? 0x8005u : 0u));
  }
  return crc;
}

uint16_t aac_crc16_zero_bits(uint16_t crc, int nbits) {
  for (int i = 0; i < nbits; i++) {
    const unsigned feedback = (crc >> 15) & 1u;
    crc = (uint16_t)((crc << 1) ^ (feedback ? 0x8005u : 0u));
  }
  return crc;
}

/* `nbits` bits of the block from `start`, with zero bits past `end`. */
static uint16_t crc_region(uint16_t crc, const uint8_t* block, int start, int end, int nbits) {
  const int present = std::max(0, std::min(nbits, end - start));
  crc = aac_crc16_bits(crc, block, start, present);
  return aac_crc16_zero_bits(crc, nbits - present);
}

uint16_t aac_adts_crc(const uint8_t* header, int header_bits, const uint8_t* block,
                      const AacCrcRegion* regions, int num_regions) {
  uint16_t crc = aac_crc16_bits(AAC_CRC16_INIT, header, 0, header_bits);
  for (int i = 0; i < num_regions; i++) {
    const AacCrcRegion* reg = &regions[i];
    switch (reg->type) {
      case AAC_ELEM_SCE:
      case AAC_ELEM_LFE:
      case AAC_ELEM_CCE:
        crc = crc_region(crc, block, reg->start, reg->end, 192);
        break;
      case AAC_ELEM_CPE:
        crc = crc_region(crc, block, reg->start, reg->end, 192);
        if (reg->second >= 0) {
          crc = crc_region(crc, block, reg->second, reg->end, 128);
        }
        break;
      case AAC_ELEM_PCE:
      case AAC_ELEM_DSE:
        crc = aac_crc16_bits(crc, block, reg->start, reg->end - reg->start);
        break;
      default:
        break;
    }
  }
  return crc;
}
