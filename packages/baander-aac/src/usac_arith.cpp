/* The spectral noiseless decoder of USAC (ISO/IEC 23003-3 7.4): a
 * context based arithmetic decoder over 2-tuples of quantised lines.  The
 * two most significant bits of each magnitude form a 17-symbol alphabet
 * (16 value pairs and an escape); every escape adds one more bit plane,
 * decoded afterwards from the least significant end with a 4-symbol
 * alphabet.  The context of a tuple is formed from three tuples of the
 * previous window (the same position and its two neighbours) and the
 * tuple below it in the current window, and it selects one of 64
 * probability models through a hash. */
#include <cstring>

#include "usac_fd.h"

namespace {

constexpr int kModels = 64;
constexpr int kHashSize = 742;

/* The range decoder of 7.4.2.x: a 16-bit range with 14-bit cumulative
 * frequencies.  Its look-ahead may reach past the end of the data (the
 * encoder flushed fewer bits than the decoder holds), where it reads
 * zeros; the position is kept here, as the bit reader clamps its own. */
uint32_t fetch_bit(const AacBitReader* r, int* pos) {
  const int p = (*pos)++;
  const int byte = p >> 3;
  if (p < 0 || byte >= r->size) {
    return 0;
  }
  return (r->data[byte] >> (7 - (p & 7))) & 1u;
}

}  // namespace

void aac_usac_arith_start(AacUsacArithState* s, const AacBitReader* r) {
  s->low = 0;
  s->high = 65535;
  s->pos = r->pos;
  s->value = 0;
  for (int i = 0; i < 16; i++) {
    s->value = (s->value << 1) | fetch_bit(r, &s->pos);
  }
}

int aac_usac_arith_symbol(AacUsacArithState* s, const AacBitReader* r, const uint16_t* cf,
                          int len) {
  const uint32_t range = s->high - s->low + 1;
  const uint32_t cum = ((((s->value - s->low) + 1) << 14) - 1) / range;
  /* cf is decreasing; the symbol is the first index whose frequency is
   * not above cum (the last entry is 0, so the search always ends). */
  int symbol = 0;
  while (cf[symbol] > cum) {
    symbol++;
  }
  if (symbol >= len) {
    return -1;
  }
  if (symbol > 0) {
    s->high = s->low + ((range * cf[symbol - 1]) >> 14) - 1;
  }
  s->low = s->low + ((range * cf[symbol]) >> 14);
  for (;;) {
    if (s->high < 32768) {
      /* the top bit is settled */
    } else if (s->low >= 32768) {
      s->value -= 32768;
      s->low -= 32768;
      s->high -= 32768;
    } else if (s->low >= 16384 && s->high < 49152) {
      s->value -= 16384;
      s->low -= 16384;
      s->high -= 16384;
    } else {
      break;
    }
    s->low += s->low;
    s->high += s->high + 1;
    s->value = (s->value << 1) | fetch_bit(r, &s->pos);
  }
  return symbol;
}

void aac_usac_arith_finish(const AacUsacArithState* s, AacBitReader* r) {
  const int end = 8 * r->size;
  const int pos = s->pos - 14;
  if (pos > end) {
    r->pos = end;
    r->overread = 1;
  } else {
    r->pos = pos < 0 ? 0 : pos;
  }
}

int aac_usac_arith_model(uint32_t context) {
  int lo = -1, hi = kHashSize - 1;
  while (hi - lo > 1) {
    const int mid = lo + (hi - lo) / 2;
    const uint32_t entry = aac_usac_ari_hash_m[mid];
    if (context < (entry >> 8)) {
      hi = mid;
    } else if (context > (entry >> 8)) {
      lo = mid;
    } else {
      return (int)(entry & 0xFF);
    }
  }
  return aac_usac_ari_lookup_m[hi];
}

void aac_usac_arith_reset(AacUsacArithContext* c) { memset(c, 0, sizeof(*c)); }

int aac_usac_arith_decode(AacUsacArithContext* c, int reset, int n, int lg, AacBitReader* r,
                          int* x) {
  const int tuples = n / 2;
  if (n < 2 || n > AAC_USAC_MAX_CORE_FRAME || lg < 0 || lg > n || (lg & 1)) {
    return AAC_ERR_DECODE;
  }
  memset(x, 0, (size_t)n * sizeof(int));
  /* arith_map_context: the previous window's quantities, stretched or
   * compressed to this window's tuple count when the lengths differ. */
  if (reset || c->prev_n == 0) {
    memset(c->prev, 0, sizeof(c->prev));
  } else if (c->prev_n != n) {
    uint8_t mapped[AAC_USAC_ARITH_MAX_TUPLES + 4];
    memset(mapped, 0, sizeof(mapped));
    const float ratio = (float)c->prev_n / (float)n;
    for (int i = 0; i < tuples; i++) {
      const int k = (int)((float)i * ratio);
      mapped[i] = c->prev[k < AAC_USAC_ARITH_MAX_TUPLES ? k : AAC_USAC_ARITH_MAX_TUPLES - 1];
    }
    memcpy(c->prev, mapped, sizeof(mapped));
  }
  c->prev_n = n;

  /* cur[1..3] are the quantities of the last three decoded tuples. */
  int cur[4] = {1, 0, 0, 0};
  uint32_t pre = (uint32_t)c->prev[0] << 12;
  AacUsacArithState st;
  aac_usac_arith_start(&st, r);
  int i = 0;
  for (; i < lg / 2; i++) {
    /* arith_get_context: the previous window's tuples i - 1, i, i + 1 in
     * bits 4 to 15, the current window's tuple i - 1 in bits 0 to 3, and
     * a flag for a quiet neighbourhood. */
    uint32_t ctx = pre >> 8;
    ctx += (uint32_t)c->prev[i + 1] << 8;
    ctx <<= 4;
    ctx += (uint32_t)cur[1];
    pre = ctx;
    if (i > 3 && cur[1] + cur[2] + cur[3] < 5) {
      ctx += 0x10000;
    }
    int lev = 0, esc_nb = 0, m;
    for (;;) {
      const int pki = aac_usac_arith_model(ctx + ((uint32_t)esc_nb << 17));
      m = aac_usac_arith_symbol(&st, r, aac_usac_ari_cf_m[pki], 17);
      if (m < 0) {
        return AAC_ERR_DECODE;
      }
      if (m < AAC_USAC_ARITH_ESCAPE) {
        break;
      }
      lev++;
      esc_nb = lev > 7 ? 7 : lev;
      if (lev > 20) {
        return AAC_ERR_DECODE;
      }
    }
    if (m == 0 && lev > 0) {
      break; /* arith_stop: the remaining lines are zero */
    }
    int b = m >> 2;
    int a = m - (b << 2);
    for (int l = lev; l > 0; l--) {
      const int lsbidx = a == 0 ? 1 : (b == 0 ? 0 : 2);
      const int rr = aac_usac_arith_symbol(&st, r, aac_usac_ari_cf_r[lsbidx], 4);
      if (rr < 0) {
        return AAC_ERR_DECODE;
      }
      a = (a << 1) | (rr & 1);
      b = (b << 1) | (rr >> 1);
    }
    x[2 * i] = a;
    x[2 * i + 1] = b;
    /* arith_update_context */
    int q = a + b + 1;
    if (q > 0xF) {
      q = 0xF;
    }
    cur[3] = cur[2];
    cur[2] = cur[1];
    cur[1] = q;
    c->prev[i] = (uint8_t)q;
  }
  /* The tuples after the stop, and the lines above lg, are zero; their
   * quantity is 1. */
  for (int k = i; k < tuples; k++) {
    c->prev[k] = 1;
  }
  for (int k = tuples; k < AAC_USAC_ARITH_MAX_TUPLES + 4; k++) {
    c->prev[k] = 0;
  }
  aac_usac_arith_finish(&st, r);
  /* One raw bit per nonzero line: a 0 makes the line negative. */
  for (int k = 0; k < lg; k++) {
    if (x[k] != 0 && !aac_bitreader_read(r, 1)) {
      x[k] = -x[k];
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}
