#include "er.h"

#include <cstring>

/* Huffman codeword reordering (ISO/IEC 14496-3 subpart 4, the spectral
 * data resilience tool).  In plain spectral data one wrong bit shifts
 * every codeword after it; reordered spectral data gives the codewords
 * known starting positions.  The codewords (each Huffman codeword with
 * its sign bits and escape sequences) are presorted by codebook, the
 * books with the largest values first, and the spectrum is divided into
 * segments of length_of_longest_codeword bits: the first codewords of
 * the presorted list, the priority codewords, each start a segment, so
 * that an error in one segment cannot reach the next one's priority
 * codeword.  The other codewords fill the room left in the segments in
 * sets of one codeword per segment, written from the far end of the
 * segment for odd sets and from the near end for even ones, and a
 * codeword that does not fit the room left in its segment continues in
 * the next segment that has some. */

namespace {

constexpr int kMaxCodewords = 512;
constexpr int kMaxSegments = 512;
constexpr int kUnit = 4; /* lines per presorting unit */

/* Presorting rank of a section codebook: lower comes first.  The escape
 * codebook and its virtual codebooks lead, then the pair books by
 * decreasing largest value, then the quad books. */
int priority(int cb) {
  static const int kRank[12] = {-1, 9, 10, 7, 8, 5, 6, 3, 4, 1, 2, 0};
  if (cb >= AAC_CB_VCB11_FIRST && cb <= AAC_CB_VCB11_LAST) {
    return 0;
  }
  if (cb >= 1 && cb <= AAC_CB_ESCAPE) {
    return kRank[cb];
  }
  return -1;
}
constexpr int kNumPriorities = 11;

/* Codebook and section codebook of the unit of 4 lines at `line` of
 * window `w`, or 0 when the unit codes nothing. */
int unit_book(const AacIcsInfo* ics, const int* band_type, int group, int line) {
  const int* off = ics->swb_offset;
  for (int b = 0; b < ics->max_sfb; b++) {
    if (line >= off[b] && line < off[b + 1]) {
      const int cb = band_type[group * ics->max_sfb + b];
      return aac_cb_is_spectral(cb) ? cb : 0;
    }
  }
  return 0;
}

/* The progress of one codeword decoded bit by bit. */
struct Codeword {
  int book;       /* Huffman book 1..11 */
  int section_cb; /* the section's codebook, for the virtual codebook limits */
  int dest;       /* window * 128 + first line */
  int dim;
  int phase; /* 0 tree walk, 1 sign bits, 2 escape prefix, 3 escape mantissa, 4 done */
  int node;
  int k;        /* coefficient the sign or escape bits belong to */
  int esc_n;    /* escape prefix ones counted */
  int esc_bits; /* mantissa bits still to read */
  int esc_val;
  int vals[4];
};

/* Moves a codeword on to the part that still needs bits after the tree
 * walk or an escape: the next coefficient's sign, its escape, or done. */
void advance(Codeword* c) {
  for (;;) {
    if (c->phase == 1) {
      /* Sign bits in coefficient order, for nonzero magnitudes. */
      while (c->k < c->dim && c->vals[c->k] == 0) {
        c->k++;
      }
      if (c->k < c->dim) {
        return; /* the sign bit of vals[k] */
      }
      c->phase = c->book == AAC_CB_ESCAPE ? 2 : 4;
      c->k = 0;
      continue;
    }
    if (c->phase == 2) {
      while (c->k < c->dim && c->vals[c->k] != 16 && c->vals[c->k] != -16) {
        c->k++;
      }
      if (c->k < c->dim) {
        c->esc_n = 4;
        return; /* the escape prefix of vals[k] */
      }
      c->phase = 4;
      return;
    }
    return;
  }
}

/* Feeds one bit; returns 1 when the codeword is complete, 0 while it
 * needs more, AAC_ERR_DECODE for an invalid codeword. */
int feed(Codeword* c, int bit) {
  switch (c->phase) {
    case 0: {
      const int next = aac_huffman_step(c->book, c->node, bit);
      if (next == 0) {
        return AAC_ERR_DECODE;
      }
      if (next > 0) {
        c->node = next;
        return 0;
      }
      const int8_t* v = aac_huffman_leaf(c->book, -next - 1);
      for (int i = 0; i < c->dim; i++) {
        c->vals[i] = v[i];
      }
      const int is_unsigned = aac_codebook_info[c->book].is_unsigned;
      c->phase = is_unsigned ? 1 : 4;
      c->k = 0;
      advance(c);
      break;
    }
    case 1:
      if (bit) {
        c->vals[c->k] = -c->vals[c->k];
      }
      c->k++;
      advance(c);
      break;
    case 2:
      if (bit) {
        if (++c->esc_n > 12) {
          return AAC_ERR_DECODE;
        }
        return 0;
      }
      c->phase = 3;
      c->esc_bits = c->esc_n;
      c->esc_val = 0;
      return 0;
    case 3:
      c->esc_val = (c->esc_val << 1) | bit;
      if (--c->esc_bits == 0) {
        const int mag = (1 << c->esc_n) + c->esc_val;
        c->vals[c->k] = c->vals[c->k] < 0 ? -mag : mag;
        c->k++;
        c->phase = 2;
        advance(c);
      }
      break;
    default:
      return AAC_ERR_DECODE;
  }
  return c->phase == 4 ? 1 : 0;
}

struct Segment {
  int left;  /* next bit of a forward read */
  int right; /* one past the next bit of a backward read */
};

int bit_at(const uint8_t* data, int pos) { return (data[pos >> 3] >> (7 - (pos & 7))) & 1; }

/* Reads bits of `c` from the segment in the given direction until the
 * codeword completes or the segment is empty.  Returns 1 complete, 0
 * segment exhausted, AAC_ERR_DECODE invalid. */
int read_from_segment(Codeword* c, Segment* seg, const uint8_t* data, int backward) {
  while (seg->left < seg->right) {
    int bit = 0;
    if (backward) {
      seg->right--;
      bit = bit_at(data, seg->right);
    } else {
      bit = bit_at(data, seg->left);
      seg->left++;
    }
    const int ret = feed(c, bit);
    if (ret != 0) {
      return ret;
    }
  }
  return 0;
}

int order(const AacIcsInfo* ics, const int* band_type, int* dest, int* book) {
  int n = 0;
  const int short_len = aac_short_length(ics->frame_length);
  for (int p = 0; p < kNumPriorities; p++) {
    if (ics->num_windows == 1) {
      const int* off = ics->swb_offset;
      for (int b = 0; b < ics->max_sfb; b++) {
        const int cb = band_type[b];
        if (!aac_cb_is_spectral(cb) || priority(cb) != p) {
          continue;
        }
        const int dim = aac_codebook_info[aac_cb_huffman_book(cb)].dim;
        for (int k = off[b]; k < off[b + 1] && n < kMaxCodewords; k += dim) {
          dest[n] = k;
          book[n] = cb;
          n++;
        }
      }
      continue;
    }
    /* Short windows: the units of all windows interleave by frequency,
     * so that the low lines of every window come first. */
    int group_of[AAC_NUM_WINDOWS_SHORT];
    for (int g = 0, w = 0; g < ics->num_window_groups; g++) {
      for (int i = 0; i < ics->group_len[g]; i++) {
        group_of[w++] = g;
      }
    }
    for (int line = 0; line < short_len; line += kUnit) {
      for (int w = 0; w < ics->num_windows; w++) {
        const int cb = unit_book(ics, band_type, group_of[w], line);
        if (cb == 0 || priority(cb) != p) {
          continue;
        }
        const int dim = aac_codebook_info[aac_cb_huffman_book(cb)].dim;
        for (int k = 0; k < kUnit && n < kMaxCodewords; k += dim) {
          dest[n] = w * 128 + line + k;
          book[n] = cb;
          n++;
        }
      }
    }
  }
  return n;
}

}  // namespace

int aac_er_hcr_codeword_order(const AacIcsInfo* ics, const int* band_type, int* dest, int* book) {
  return order(ics, band_type, dest, book);
}

int aac_er_read_hcr(AacBitReader* r, const AacIcsInfo* ics, const int* band_type, int length,
                    int longest, int* quant) {
  memset(quant, 0, 1024 * sizeof(int));
  const int start = r->pos;
  if (length > aac_bitreader_bits_left(r)) {
    aac_bitreader_skip(r, length);
    return AAC_ERR_DECODE;
  }
  aac_bitreader_skip(r, length); /* the reader ends behind the data whatever happens */
  int dest[kMaxCodewords], book[kMaxCodewords];
  const int count = order(ics, band_type, dest, book);
  if (count == 0) {
    return length == 0 ? 0 : AAC_ERR_DECODE;
  }
  if (longest == 0 || length < longest) {
    return AAC_ERR_DECODE;
  }
  Codeword cw[kMaxCodewords];
  for (int i = 0; i < count; i++) {
    Codeword* c = &cw[i];
    memset(c, 0, sizeof(*c));
    c->section_cb = book[i];
    c->book = aac_cb_huffman_book(book[i]);
    c->dim = aac_codebook_info[c->book].dim;
    c->dest = dest[i];
  }
  const int num_segments = length / longest;
  if (num_segments > kMaxSegments || num_segments > count) {
    return AAC_ERR_DECODE;
  }
  Segment seg[kMaxSegments];
  for (int i = 0; i < num_segments; i++) {
    seg[i].left = start + i * longest;
    seg[i].right = i == num_segments - 1 ? start + length : seg[i].left + longest;
  }
  /* The priority codewords, one at the start of each segment. */
  for (int i = 0; i < num_segments; i++) {
    if (read_from_segment(&cw[i], &seg[i], r->data, 0) != 1) {
      return AAC_ERR_DECODE;
    }
  }
  /* The other codewords, set by set. */
  for (int set = 1; set * num_segments < count; set++) {
    const int base = set * num_segments;
    const int size = count - base < num_segments ? count - base : num_segments;
    const int backward = set & 1;
    int pending = size;
    for (int trial = 0; trial < num_segments && pending > 0; trial++) {
      for (int k = 0; k < size; k++) {
        Codeword* c = &cw[base + k];
        if (c->phase == 4) {
          continue;
        }
        const int ret = read_from_segment(c, &seg[(k + trial) % num_segments], r->data, backward);
        if (ret < 0) {
          return ret;
        }
        pending -= ret;
      }
    }
    if (pending > 0) {
      return AAC_ERR_DECODE;
    }
  }
  for (int i = 0; i < num_segments; i++) {
    if (seg[i].left != seg[i].right) {
      return AAC_ERR_DECODE; /* bits that belong to no codeword */
    }
  }
  for (int i = 0; i < count; i++) {
    const Codeword* c = &cw[i];
    const int limit = aac_vcb11_limit(c->section_cb);
    for (int k = 0; k < c->dim; k++) {
      const int v = c->vals[k];
      if (limit >= 0 && (v > limit || v < -limit)) {
        return AAC_ERR_DECODE;
      }
      quant[c->dest + k] = v;
    }
  }
  return 0;
}
