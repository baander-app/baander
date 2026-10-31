/* The spectral noiseless encoder of USAC (ISO/IEC 23003-3 7.4), the
 * mirror of the arithmetic decoder in usac_arith.cpp: the same context
 * from the previous window and the neighbouring tuples, the same hash to
 * one of the 64 probability models, the two most significant bits of
 * each magnitude as a 17-symbol alphabet with an escape per further bit
 * plane, the stop symbol after the last nonzero tuple, and the range
 * coder whose 16-bit interval the decoder tracks with 14-bit cumulative
 * frequencies.  The coder flushes two bits, which the decoder's
 * look-ahead of 16 bits turns into the 14 bits it hands back. */
#include <cstring>

#include "usac_enc.h"

namespace {

/* The range encoder with carry propagation by deferred bits (the
 * follow count), writing to a bit writer or only counting. */
struct RangeEncoder {
  AacBitWriter* w;
  uint32_t low, high;
  int follow;
  int bits;

  void put(int bit) {
    if (w) {
      aac_bitwriter_write(w, (uint32_t)bit, 1);
    }
    bits++;
  }
  void bit_plus_follow(int bit) {
    put(bit);
    while (follow > 0) {
      put(!bit);
      follow--;
    }
  }
  /* `cf` is the decreasing cumulative frequency table the decoder uses:
   * symbol s occupies [cf[s], cf[s - 1]) with cf[-1] taken as 16384. */
  void encode(const uint16_t* cf, int symbol) {
    const uint32_t range = high - low + 1;
    const uint32_t upper = symbol == 0 ? 16384u : cf[symbol - 1];
    high = low + ((range * upper) >> 14) - 1;
    low = low + ((range * cf[symbol]) >> 14);
    for (;;) {
      if (high < 32768) {
        bit_plus_follow(0);
      } else if (low >= 32768) {
        bit_plus_follow(1);
        low -= 32768;
        high -= 32768;
      } else if (low >= 16384 && high < 49152) {
        follow++;
        low -= 16384;
        high -= 16384;
      } else {
        break;
      }
      low <<= 1;
      high = (high << 1) | 1;
    }
  }
  void finish() {
    follow++;
    bit_plus_follow(low < 16384 ? 0 : 1);
  }
};

}  // namespace

int aac_usac_arith_encode(AacUsacArithContext* c, int reset, int n, int lg, const int* x,
                          AacBitWriter* w) {
  const int tuples = n / 2;
  if (n < 2 || n > AAC_USAC_MAX_CORE_FRAME || lg < 0 || lg > n || (lg & 1)) {
    return AAC_ERR_INVALID_ARG;
  }
  /* arith_map_context, as the decoder does it. */
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

  /* The last tuple that is not zero; arith_stop follows it. */
  int last = -1;
  for (int i = 0; i < lg / 2; i++) {
    const int a = x[2 * i] < 0 ? -x[2 * i] : x[2 * i];
    const int b = x[2 * i + 1] < 0 ? -x[2 * i + 1] : x[2 * i + 1];
    if (a > AAC_ESC_MAX_MAG || b > AAC_ESC_MAX_MAG) {
      return AAC_ERR_INVALID_ARG;
    }
    if (a || b) {
      last = i;
    }
  }

  RangeEncoder enc;
  enc.w = w;
  enc.low = 0;
  enc.high = 65535;
  enc.follow = 0;
  enc.bits = 0;
  int cur[4] = {1, 0, 0, 0};
  uint32_t pre = (uint32_t)c->prev[0] << 12;
  int i = 0;
  for (; i < lg / 2; i++) {
    uint32_t ctx = pre >> 8;
    ctx += (uint32_t)c->prev[i + 1] << 8;
    ctx <<= 4;
    ctx += (uint32_t)cur[1];
    pre = ctx;
    if (i > 3 && cur[1] + cur[2] + cur[3] < 5) {
      ctx += 0x10000;
    }
    if (i > last) {
      /* arith_stop: an escape and then the symbol 0 */
      enc.encode(aac_usac_ari_cf_m[aac_usac_arith_model(ctx)], AAC_USAC_ARITH_ESCAPE);
      enc.encode(aac_usac_ari_cf_m[aac_usac_arith_model(ctx + (1u << 17))], 0);
      break;
    }
    const int a0 = x[2 * i] < 0 ? -x[2 * i] : x[2 * i];
    const int b0 = x[2 * i + 1] < 0 ? -x[2 * i + 1] : x[2 * i + 1];
    int a = a0;
    int b = b0;
    int lev = 0;
    while (a > 3 || b > 3) {
      a >>= 1;
      b >>= 1;
      lev++;
    }
    for (int l = 0; l < lev; l++) {
      const uint32_t s = ctx + ((uint32_t)(l > 7 ? 7 : l) << 17);
      enc.encode(aac_usac_ari_cf_m[aac_usac_arith_model(s)], AAC_USAC_ARITH_ESCAPE);
    }
    const uint32_t s = ctx + ((uint32_t)(lev > 7 ? 7 : lev) << 17);
    enc.encode(aac_usac_ari_cf_m[aac_usac_arith_model(s)], a + 4 * b);
    /* The remaining bit planes from the most significant down, as the
     * decoder rebuilds the magnitudes. */
    for (int l = lev - 1; l >= 0; l--) {
      const int ha = a0 >> (l + 1);
      const int hb = b0 >> (l + 1);
      const int lsbidx = ha == 0 ? 1 : (hb == 0 ? 0 : 2);
      const int bits = ((a0 >> l) & 1) | (((b0 >> l) & 1) << 1);
      enc.encode(aac_usac_ari_cf_r[lsbidx], bits);
    }
    int q = a0 + b0 + 1;
    if (q > 0xF) {
      q = 0xF;
    }
    cur[3] = cur[2];
    cur[2] = cur[1];
    cur[1] = q;
    c->prev[i] = (uint8_t)q;
  }
  for (int k = i; k < tuples; k++) {
    c->prev[k] = 1;
  }
  for (int k = tuples; k < AAC_USAC_ARITH_MAX_TUPLES + 4; k++) {
    c->prev[k] = 0;
  }
  enc.finish();
  /* One raw bit per nonzero line, 1 for a positive one. */
  int bits = enc.bits;
  for (int k = 0; k < lg; k++) {
    if (x[k]) {
      if (w) {
        aac_bitwriter_write(w, x[k] < 0 ? 0u : 1u, 1);
      }
      bits++;
    }
  }
  return bits;
}
