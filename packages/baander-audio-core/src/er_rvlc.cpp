#include "er.h"

#include <cstring>

/* Reversible variable length coding of the scalefactors (ISO/IEC 14496-3
 * subpart 4, the scalefactor data resilience tool).  Instead of the
 * scalefactor Huffman codebook, whose codewords decode in one direction
 * only so that one wrong bit spoils every scalefactor after it, the DPCM
 * differences are coded with symmetric codewords that decode from either
 * end, the lengths of the coded parts are sent ahead of them, and the
 * values the backward decoding starts from (the last scalefactor and the
 * last noise energy) travel in plain binary.  Differences beyond +-6 are
 * sent as an escape marker (+-7) in the reversible part plus an escape
 * value in a separate part, so that the reversible part keeps its
 * fixed-size alphabet. */

/* The reversible codebook: codewords for the differences -7..+7 (index =
 * difference + 7), each a palindrome so that the same table decodes the
 * data from the end.  The remaining palindromes of these lengths code
 * nothing and reveal an error. */
const uint16_t aac_rvlc_code[AAC_RVLC_NUM_CODES] = {
    0x101, /* -7: 100000001 */
    0x81,  /* -6: 10000001 */
    0x41,  /* -5: 1000001 */
    0x21,  /* -4: 100001 */
    0x11,  /* -3: 10001 */
    0x9,   /* -2: 1001 */
    0x5,   /* -1: 101 */
    0x0,   /*  0: 0 */
    0x7,   /* +1: 111 */
    0x1B,  /* +2: 11011 */
    0x33,  /* +3: 110011 */
    0x63,  /* +4: 1100011 */
    0xC3,  /* +5: 11000011 */
    0x183, /* +6: 110000011 */
    0x193, /* +7: 110010011 */
};
const uint8_t aac_rvlc_len[AAC_RVLC_NUM_CODES] = {9, 8, 7, 6, 5, 4, 3, 1, 3, 5, 6, 7, 8, 9, 9};

/* The escape codebook: a prefix code over the escape values 0..53 (the
 * difference is +-(7 + value), so that +-60 is reached), 2 to 17 bits
 * long, short codes for the small values; the codewords are canonical,
 * consecutive within one length and each length starting where the
 * previous one left off, doubled. */
const uint32_t aac_rvlc_escape_code[AAC_RVLC_NUM_ESCAPES] = {
    0,     1,     4,     5,     12,    26,    27,    56,    57,    58,    59,
    120,   121,   122,   123,   248,   249,   250,   251,   504,   505,   506,
    507,   1016,  1017,  1018,  1019,  2040,  2041,  2042,  2043,  4088,  4089,
    4090,  4091,  8184,  8185,  8186,  8187,  16376, 16377, 16378, 16379, 32760,
    32761, 32762, 32763, 65528, 65529, 65530, 65531, 131064, 131065, 131066};
const uint8_t aac_rvlc_escape_len[AAC_RVLC_NUM_ESCAPES] = {
    2,  2,  3,  3,  4,  5,  5,  6,  6,  6,  6,  7,  7,  7,  7,  8,  8,  8,
    8,  9,  9,  9,  9,  10, 10, 10, 10, 11, 11, 11, 11, 12, 12, 12, 12, 13,
    13, 13, 13, 14, 14, 14, 14, 15, 15, 15, 15, 16, 16, 16, 16, 17, 17, 17};

namespace {

constexpr int kRvlcMaxLen = 9;
constexpr int kEscapeMaxLen = 17;

/* A bit cursor over the bits of a buffer that moves either way. */
struct BitCursor {
  const uint8_t* data;
  int size;
  int pos;   /* next bit to read (forward: this bit; backward: the bit before it) */
  int limit; /* forward: one past the last readable bit; backward: the first readable bit */
  int backward;
  /* Reads the next bit, or returns -1 at the limit. */
  int next() {
    if (backward) {
      if (pos <= limit) {
        return -1;
      }
      pos--;
      return (data[pos >> 3] >> (7 - (pos & 7))) & 1;
    }
    if (pos >= limit) {
      return -1;
    }
    const int bit = (data[pos >> 3] >> (7 - (pos & 7))) & 1;
    pos++;
    return bit;
  }
  int left() const { return backward ? pos - limit : limit - pos; }
  /* Reads an n-bit unsigned field in stream order (a backward cursor
   * reads the field that ends at its position). */
  int field(int n) {
    if (left() < n) {
      return -1;
    }
    int v = 0;
    if (backward) {
      pos -= n;
      for (int i = 0; i < n; i++) {
        v = (v << 1) | ((data[(pos + i) >> 3] >> (7 - ((pos + i) & 7))) & 1);
      }
      return v;
    }
    for (int i = 0; i < n; i++) {
      v = (v << 1) | next();
    }
    return v;
  }
};

/* Decodes one codeword of the reversible codebook (its palindromes read
 * the same either way); returns the difference, or AAC_SF_INVALID. */
int read_rvlc(BitCursor* c) {
  uint32_t code = 0;
  for (int len = 1; len <= kRvlcMaxLen; len++) {
    const int bit = c->next();
    if (bit < 0) {
      return AAC_SF_INVALID;
    }
    code = (code << 1) | (uint32_t)bit;
    for (int i = 0; i < AAC_RVLC_NUM_CODES; i++) {
      if (aac_rvlc_len[i] == len && aac_rvlc_code[i] == code) {
        return i - AAC_RVLC_ESCAPE;
      }
    }
  }
  return AAC_SF_INVALID;
}

/* Decodes one escape value, or -1. */
int read_escape(BitCursor* c) {
  uint32_t code = 0;
  for (int len = 1; len <= kEscapeMaxLen; len++) {
    const int bit = c->next();
    if (bit < 0) {
      return -1;
    }
    code = (code << 1) | (uint32_t)bit;
    for (int i = 0; i < AAC_RVLC_NUM_ESCAPES; i++) {
      if (aac_rvlc_escape_len[i] == len && aac_rvlc_escape_code[i] == code) {
        return i;
      }
    }
  }
  return -1;
}

enum BandKind { kRegular, kNoise, kIntensity };

struct Band {
  int idx;  /* group * max_sfb + sfb */
  int kind; /* BandKind */
};

int clamp(int v, int lo, int hi) { return v < lo ? lo : (v > hi ? hi : v); }

/* The bands that carry a scalefactor, in syntax order. */
int list_bands(const AacIcsInfo* ics, const int* band_type, Band* bands) {
  int n = 0;
  for (int g = 0; g < ics->num_window_groups; g++) {
    for (int b = 0; b < ics->max_sfb; b++) {
      const int idx = g * ics->max_sfb + b;
      const int cb = band_type[idx];
      if (cb == 0) {
        continue;
      }
      bands[n].idx = idx;
      bands[n].kind = cb == AAC_CB_NOISE ? kNoise
                      : (cb == AAC_CB_INTENSITY || cb == AAC_CB_INTENSITY2) ? kIntensity
                                                                            : kRegular;
      n++;
    }
  }
  return n;
}

/* A difference of the reversible part with its escape resolved: the
 * forward pass takes the escapes in order, the backward pass from the end
 * of their list. */
int resolve(int diff, const int* escapes, int num_escapes, int* next, int backward) {
  if (diff != AAC_RVLC_ESCAPE && diff != -AAC_RVLC_ESCAPE) {
    return diff;
  }
  if (backward ? *next <= 0 : *next >= num_escapes) {
    return AAC_SF_INVALID;
  }
  const int e = backward ? escapes[--*next] : escapes[(*next)++];
  return diff > 0 ? AAC_RVLC_ESCAPE + e : -(AAC_RVLC_ESCAPE + e);
}

}  // namespace

int aac_er_read_rvlc_scalefactors(AacBitReader* r, const AacIcsInfo* ics, const int* band_type,
                                  int global_gain, int* sf, int* outcome) {
  if (outcome) {
    *outcome = AAC_RVLC_OK;
  }
  aac_bitreader_read(r, 1); /* sf_concealment: the encoder's hint, not needed here */
  const int rev_global_gain = (int)aac_bitreader_read(r, 8);
  const int length_of_rvlc_sf = (int)aac_bitreader_read(r, ics->num_windows == 8 ? 11 : 9);
  Band bands[AAC_MAX_BANDS];
  const int n = list_bands(ics, band_type, bands);
  bool noise_used = false;
  for (int i = 0; i < n; i++) {
    noise_used |= bands[i].kind == kNoise;
  }
  const int main_start = r->pos;
  const int main_end = main_start + length_of_rvlc_sf;
  /* The last noise energy, in plain binary, closes the reversible part. */
  const int codes_end = main_end - (noise_used ? 9 : 0);
  if (codes_end < main_start || main_end > r->size * 8 || r->overread) {
    aac_bitreader_skip(r, length_of_rvlc_sf);
    return AAC_ERR_DECODE;
  }

  /* ── the forward pass over the reversible part ── */
  int diff[AAC_MAX_BANDS];
  int escapes_needed_fwd = 0;
  BitCursor fwd = {r->data, r->size, main_start, codes_end, 0};
  int n_fwd = 0; /* bands decoded before the first invalid codeword */
  bool first_noise = true;
  for (; n_fwd < n; n_fwd++) {
    if (bands[n_fwd].kind == kNoise && first_noise) {
      first_noise = false;
      diff[n_fwd] = fwd.field(9);
      if (diff[n_fwd] < 0) {
        break;
      }
      continue;
    }
    diff[n_fwd] = read_rvlc(&fwd);
    if (diff[n_fwd] == AAC_SF_INVALID) {
      break;
    }
    escapes_needed_fwd += diff[n_fwd] == AAC_RVLC_ESCAPE || diff[n_fwd] == -AAC_RVLC_ESCAPE;
  }
  bool forward_ok = n_fwd == n && fwd.left() == 0;
  int noise_last = 0;
  if (noise_used) {
    BitCursor tail = {r->data, r->size, codes_end, main_end, 0};
    noise_last = global_gain - 90 + tail.field(9) - 256;
  }
  r->pos = main_end;

  /* ── the escape part ── */
  int escapes[AAC_MAX_BANDS];
  int num_escapes = 0;
  bool escapes_ok = true;
  if (aac_bitreader_read(r, 1)) { /* sf_escapes_present */
    const int length_of_rvlc_escapes = (int)aac_bitreader_read(r, 8);
    const int esc_start = r->pos;
    const int esc_end = esc_start + length_of_rvlc_escapes;
    if (esc_end > r->size * 8 || r->overread) {
      aac_bitreader_skip(r, length_of_rvlc_escapes);
      return AAC_ERR_DECODE;
    }
    BitCursor esc = {r->data, r->size, esc_start, esc_end, 0};
    while (esc.left() > 0) {
      const int e = num_escapes < AAC_MAX_BANDS ? read_escape(&esc) : -1;
      if (e < 0) {
        escapes_ok = false;
        break;
      }
      escapes[num_escapes++] = e;
    }
    r->pos = esc_end;
  }
  if (r->overread) {
    return AAC_ERR_DECODE;
  }
  forward_ok = forward_ok && escapes_ok && escapes_needed_fwd == num_escapes;

  /* ── absolute values of the forward pass ── */
  int fwd_val[AAC_MAX_BANDS];
  int fwd_valid = 0; /* bands whose forward value is usable */
  {
    int cur_sf = global_gain;
    int noise = global_gain - 90;
    int is_pos = 0;
    int next_esc = 0;
    bool first = true;
    for (; fwd_valid < n_fwd; fwd_valid++) {
      const Band& b = bands[fwd_valid];
      if (b.kind == kNoise && first) {
        first = false;
        noise += diff[fwd_valid] - 256;
        fwd_val[fwd_valid] = noise;
        continue;
      }
      const int d = resolve(diff[fwd_valid], escapes, num_escapes, &next_esc, 0);
      if (d == AAC_SF_INVALID) {
        break;
      }
      if (b.kind == kNoise) {
        noise += d;
        fwd_val[fwd_valid] = noise;
      } else if (b.kind == kIntensity) {
        is_pos += d;
        fwd_val[fwd_valid] = is_pos;
      } else {
        cur_sf += d;
        if (cur_sf < 0 || cur_sf > 255) {
          break;
        }
        fwd_val[fwd_valid] = cur_sf;
      }
    }
    if (fwd_valid < n_fwd) {
      forward_ok = false;
    }
    /* The plain values the backward decoding starts from must agree
     * with the forward result. */
    int last_sf = global_gain, last_noise = global_gain - 90;
    bool has_sf = false, has_noise = false;
    for (int i = 0; i < fwd_valid; i++) {
      if (bands[i].kind == kRegular) {
        last_sf = fwd_val[i];
        has_sf = true;
      } else if (bands[i].kind == kNoise) {
        last_noise = fwd_val[i];
        has_noise = true;
      }
    }
    if (has_sf ? last_sf != rev_global_gain : rev_global_gain != global_gain) {
      forward_ok = false;
    }
    if (has_noise && last_noise != noise_last) {
      forward_ok = false;
    }
  }
  if (forward_ok) {
    for (int i = 0; i < n; i++) {
      const Band& b = bands[i];
      sf[b.idx] = b.kind == kNoise       ? clamp(fwd_val[i], -100, 155)
                  : b.kind == kIntensity ? clamp(fwd_val[i], -155, 100)
                                         : fwd_val[i];
    }
    return 0;
  }

  /* ── the backward pass, from the end of the reversible part ── */
  int bwd_val[AAC_MAX_BANDS]; /* indexed like bands[] */
  int n_bwd = 0;              /* bands decoded from the end */
  bool backward_ok = false;   /* the backward pass reached the start consistently */
  {
    BitCursor bwd = {r->data, r->size, codes_end, main_start, 1};
    int cur_sf = rev_global_gain;
    int noise = noise_last;
    int next_esc = num_escapes;
    int first_noise_index = -1;
    for (int i = 0; i < n; i++) {
      if (bands[i].kind == kNoise) {
        first_noise_index = i;
        break;
      }
    }
    for (int i = n - 1; i >= 0; i--) {
      const Band& b = bands[i];
      /* The value of band i is known before its difference is read: the
       * difference leads to the band after it. */
      if (b.kind == kRegular) {
        bwd_val[i] = cur_sf;
      } else if (b.kind == kNoise) {
        bwd_val[i] = noise;
      } else {
        bwd_val[i] = 0; /* intensity positions are placed later */
      }
      int d = 0;
      if (i == first_noise_index) {
        const int pcm = bwd.field(9);
        if (pcm < 0 || global_gain - 90 + pcm - 256 != noise) {
          break;
        }
        d = 0;
      } else {
        const int raw = read_rvlc(&bwd);
        if (raw == AAC_SF_INVALID) {
          break;
        }
        d = resolve(raw, escapes, num_escapes, &next_esc, 1);
        if (d == AAC_SF_INVALID) {
          break;
        }
        diff[i] = d;
      }
      n_bwd = n - i;
      if (b.kind == kRegular) {
        cur_sf -= d;
        if (cur_sf < 0 || cur_sf > 255) {
          break;
        }
      } else if (b.kind == kNoise) {
        noise -= d;
      }
    }
    /* Reaching the start with every bit and escape used and the values
     * the forward decoding starts from: the backward pass is as
     * trustworthy as a clean forward pass, and the error is in the
     * forward pass alone. */
    backward_ok = n_bwd == n && bwd.left() == 0 && next_esc == 0 && cur_sf == global_gain &&
                  (first_noise_index < 0 || noise == global_gain - 90);
  }

  /* ── keep the forward prefix and the backward suffix, fill the gap ── */
  int a = fwd_valid;  /* bands [0, a) from the forward pass */
  int b0 = n - n_bwd; /* bands [b0, n) from the backward pass */
  if (backward_ok) {
    a = 0;
  } else if (a >= b0) {
    /* Both passes ran past each other without noticing the error: split
     * the overlap between them. */
    a = (a + b0) / 2;
    b0 = a;
  }
  int val[AAC_MAX_BANDS];
  for (int i = 0; i < a; i++) {
    val[i] = fwd_val[i];
  }
  for (int i = b0; i < n; i++) {
    val[i] = bwd_val[i];
  }
  /* Intensity positions of the suffix continue from the prefix's last
   * one with the differences the backward pass read. */
  {
    int is_pos = 0;
    for (int i = 0; i < a; i++) {
      if (bands[i].kind == kIntensity) {
        is_pos = val[i];
      }
    }
    for (int i = b0; i < n; i++) {
      if (bands[i].kind == kIntensity) {
        is_pos += diff[i];
        val[i] = is_pos;
      }
    }
  }
  /* The gap: each kind interpolates between its neighbours of the same
   * kind, or holds the nearest one. */
  for (int kind = kRegular; kind <= kIntensity; kind++) {
    int prev = -1, next = -1;
    for (int i = a - 1; i >= 0 && prev < 0; i--) {
      if (bands[i].kind == kind) {
        prev = i;
      }
    }
    for (int i = b0; i < n && next < 0; i++) {
      if (bands[i].kind == kind) {
        next = i;
      }
    }
    for (int i = a; i < b0; i++) {
      if (bands[i].kind != kind) {
        continue;
      }
      if (prev >= 0 && next >= 0) {
        val[i] = val[prev] + (val[next] - val[prev]) * (i - prev) / (next - prev);
      } else if (prev >= 0) {
        val[i] = val[prev];
      } else if (next >= 0) {
        val[i] = val[next];
      } else {
        val[i] = kind == kRegular ? global_gain : (kind == kNoise ? global_gain - 90 : 0);
      }
    }
  }
  for (int i = 0; i < n; i++) {
    const Band& b = bands[i];
    sf[b.idx] = b.kind == kNoise       ? clamp(val[i], -100, 155)
                : b.kind == kIntensity ? clamp(val[i], -155, 100)
                                       : clamp(val[i], 0, 255);
  }
  if (outcome) {
    *outcome = AAC_RVLC_RECOVERED;
  }
  return 0;
}
