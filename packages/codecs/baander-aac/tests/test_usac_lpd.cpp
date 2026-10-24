/* Tests of the USAC linear prediction domain core: the mode table, the
 * LSP to LPC conversion against an LPC analysis of the synthesis filter,
 * the algebraic vector quantiser (bijective codebooks on the RE8 lattice
 * and the index syntax round trip), the ACELP pulse codes and the pitch
 * lag codes, the arithmetic decoder against an encoder built on the same
 * models, the TCX windows (perfect reconstruction over a run of frames),
 * the forward aliasing cancellation at a synthetic ACELP to TCX boundary,
 * the core channel contract (overlap, concealment), and the LPD frames
 * of two xHE-AAC speech streams, whose core output is compared with the
 * FDK decoder's output resampled to the core rate (reported, not
 * asserted, while the standard's codebooks are placeholders). */
#include <array>
#include <cmath>
#include <set>

#include "../src/usac_lpd_internal.h"
#include "test_util.h"
#include "latm.h"
#include "usac.h"
#include "usac_lpd.h"

/* ── mode table ─────────────────────────────────────────────────── */

static void test_modes() {
  int mod[4];
  CHECK(lpd_modes(0, mod) == AAC_OK && mod[0] == 0 && mod[3] == 0, "mode 0");
  CHECK(lpd_modes(9, mod) == AAC_OK && mod[0] == 1 && mod[1] == 0 && mod[2] == 0 && mod[3] == 1,
        "mode 9");
  CHECK(lpd_modes(17, mod) == AAC_OK && mod[0] == 2 && mod[1] == 2 && mod[2] == 1 && mod[3] == 0,
        "mode 17");
  CHECK(lpd_modes(22, mod) == AAC_OK && mod[0] == 0 && mod[1] == 1 && mod[2] == 2 && mod[3] == 2,
        "mode 22");
  CHECK(lpd_modes(24, mod) == AAC_OK && mod[0] == 2 && mod[2] == 2, "mode 24");
  CHECK(lpd_modes(25, mod) == AAC_OK && mod[0] == 3 && mod[3] == 3, "mode 25");
  CHECK(lpd_modes(26, mod) == AAC_ERR_DECODE, "mode 26 is reserved");
}

/* ── LSP to LPC ─────────────────────────────────────────────────── */

/* Levinson-Durbin on r[0..16]: a[0] = 1, a[1..16]. */
static void levinson(const double* r, double* a) {
  double err = r[0];
  double tmp[LPD_M + 1];
  a[0] = 1.0;
  for (int i = 1; i <= LPD_M; i++) {
    double acc = r[i];
    for (int j = 1; j < i; j++) {
      acc += a[j] * r[i - j];
    }
    const double k = -acc / err;
    for (int j = 1; j < i; j++) {
      tmp[j] = a[j] + k * a[i - j];
    }
    for (int j = 1; j < i; j++) {
      a[j] = tmp[j];
    }
    a[i] = k;
    err *= 1.0 - k * k;
  }
}

static void test_lsp_lpc() {
  unsigned seed = 99u;
  for (int trial = 0; trial < 20; trial++) {
    float lsf[LPD_M], lsp[LPD_M], a[LPD_M + 1];
    float f = 150.0f;
    for (int i = 0; i < LPD_M; i++) {
      seed = seed * 1664525u + 1013904223u;
      f += 120.0f + 400.0f * (float)(seed >> 8) / 16777216.0f;
      lsf[i] = f;
    }
    if (lsf[LPD_M - 1] > LPD_LSF_MAX - 100.0f) {
      const float s = (LPD_LSF_MAX - 100.0f) / lsf[LPD_M - 1];
      for (int i = 0; i < LPD_M; i++) {
        lsf[i] *= s;
      }
    }
    lpd_lsf_to_lsp(lsf, lsp);
    lpd_lsp_to_a(lsp, a);
    /* the LSFs come back from the LSPs */
    float back[LPD_M];
    lpd_lsp_to_lsf(lsp, back);
    for (int i = 0; i < LPD_M; i++) {
      CHECK(fabsf(back[i] - lsf[i]) < 0.05f, "lsf round trip %d: %f vs %f", i, back[i], lsf[i]);
    }
    /* the impulse response of 1 / A(z) is stable and its LPC analysis
     * returns A(z) */
    const int n = 8192;
    std::vector<float> h(n, 0.0f), x(n, 0.0f);
    x[0] = 1.0f;
    float mem[LPD_M] = {0};
    lpd_syn_filter(a, x.data(), h.data(), n, mem);
    double r[LPD_M + 1];
    for (int lag = 0; lag <= LPD_M; lag++) {
      double s = 0.0;
      for (int k = lag; k < n; k++) {
        s += (double)h[k] * h[k - lag];
      }
      r[lag] = s;
    }
    CHECK(fabs(h[n - 1]) < 1e-3 * fabs(h[0]) + 1e-6, "trial %d: unstable synthesis filter", trial);
    double est[LPD_M + 1];
    levinson(r, est);
    for (int i = 1; i <= LPD_M; i++) {
      CHECK(fabs(est[i] - a[i]) < 1e-3 * (1.0 + fabs(a[i])), "trial %d: a[%d] = %f, analysis %f",
            trial, i, a[i], est[i]);
    }
    /* the weighted filter */
    float ap[LPD_M + 1];
    lpd_weight_a(a, LPD_GAMMA1, ap);
    CHECK(fabsf(ap[2] - a[2] * LPD_GAMMA1 * LPD_GAMMA1) < 1e-6f, "weighting");
  }
  /* the stability factor */
  float same[LPD_M], far[LPD_M];
  for (int i = 0; i < LPD_M; i++) {
    same[i] = 300.0f * (float)(i + 1);
    far[i] = same[i] + 400.0f;
  }
  CHECK(lpd_stab_fac(same, same) == 1.0f, "stab_fac identical");
  CHECK(lpd_stab_fac(far, same) < 0.5f, "stab_fac distant: %f", lpd_stab_fac(far, same));
}

/* ── algebraic vector quantiser ─────────────────────────────────── */

static int in_re8(const int* y) {
  int sum = 0, odd = 0, even = 0;
  for (int i = 0; i < 8; i++) {
    sum += y[i];
    odd += y[i] & 1;
    even += !(y[i] & 1);
  }
  if (odd == 8) {
    return ((sum % 4) + 4) % 4 == 0;
  }
  return even == 8 && sum % 4 == 0;
}

static void test_avq() {
  for (int nb = 2; nb <= 4; nb++) {
    const int size = 1 << (4 * nb);
    std::set<std::array<int, 8>> seen;
    int bad = 0;
    for (int idx = 0; idx < size; idx++) {
      LpdAvqBlock b;
      memset(&b, 0, sizeof(b));
      b.qn = nb;
      b.base = (uint32_t)idx;
      int y[8];
      lpd_avq_decode(&b, y);
      if (!in_re8(y)) {
        bad++;
      }
      std::array<int, 8> key;
      for (int i = 0; i < 8; i++) {
        key[i] = y[i];
      }
      seen.insert(key);
    }
    CHECK(bad == 0, "Q%d: %d codevectors outside RE8", nb, bad);
    /* the leaders fill the codebook without gaps; the indices the
     * leaders do not reach (the base codebooks are unions of whole
     * leader orbits, which do not add up to a power of two) decode to
     * the first codevector */
    const int nl = lpd_avq_num_leaders(nb);
    const int last = nl - 1;
    const int used = lpd_avq_leader_offset(nb, last) + lpd_avq_leader_count(nb, last);
    CHECK(used <= size && used > size * 9 / 10, "Q%d: %d leaders fill %d of %d", nb, nl, used,
          size);
    CHECK((int)seen.size() == used, "Q%d: %zu distinct codevectors of %d used", nb, seen.size(),
          used);
    printf("Q%d: %d leaders, %d of %d indices used\n", nb, nl, used, size);
  }
  CHECK(lpd_avq_num_leaders(2) == 3, "Q2 has three leaders");
  /* Voronoi extension: every (base, k) gives a lattice point, distinct
   * over the Voronoi indices of one base vector: all 256 for one bit
   * per component, a random sample for two bits */
  unsigned seed = 5u;
  for (int qn = 5; qn <= 8; qn++) {
    const int nb = (qn & 1) ? 3 : 4;
    const int r = (qn - nb) / 2;
    std::set<std::array<int, 8>> seen;
    const int trials = r == 1 ? 256 : 300;
    int bad = 0;
    LpdAvqBlock b;
    memset(&b, 0, sizeof(b));
    b.qn = qn;
    b.base = 77u;
    for (int t = 0; t < trials; t++) {
      for (int i = 0; i < 8; i++) {
        seed = seed * 1664525u + 1013904223u;
        unsigned h = seed ^ (seed >> 13);
        h *= 0x5bd1e995u;
        h ^= h >> 15;
        b.kv[i] = r == 1 ? ((t >> i) & 1) : (int)(h & ((1u << r) - 1u));
      }
      int y[8];
      lpd_avq_decode(&b, y);
      bad += !in_re8(y);
      std::array<int, 8> key;
      for (int i = 0; i < 8; i++) {
        key[i] = y[i];
      }
      seen.insert(key);
    }
    CHECK(bad == 0, "Q%d: %d points outside RE8", qn, bad);
    CHECK((int)seen.size() > (r == 1 ? 255 : trials * 9 / 10), "Q%d: only %zu distinct of %d",
          qn, seen.size(), trials);
  }
  /* syntax round trip of the codebook number codes */
  for (int nk = 0; nk <= 3; nk++) {
    for (int qn = 0; qn <= 8; qn++) {
      if (qn == 1 || (nk == 2 && (qn < 2 || qn > 5))) {
        continue;
      }
      uint8_t buf[32] = {0};
      AacBitWriter w;
      aac_bitwriter_init(&w, buf, sizeof(buf));
      if (nk == 1) {
        const int u = qn == 0 ? 0 : qn - 1;
        for (int i = 0; i < u; i++) {
          aac_bitwriter_write(&w, 1, 1);
        }
        aac_bitwriter_write(&w, 0, 1);
      } else if (nk == 2) {
        aac_bitwriter_write(&w, (uint32_t)(qn - 2), 2);
      } else if (qn == 0) {
        aac_bitwriter_write(&w, 0, 2);
      } else if (qn < 4) {
        aac_bitwriter_write(&w, (uint32_t)(qn - 1), 2);
      } else {
        aac_bitwriter_write(&w, 3, 2);
        for (int i = 0; i < qn - 4; i++) {
          aac_bitwriter_write(&w, 1, 1);
        }
        aac_bitwriter_write(&w, 0, 1);
      }
      const int nb = qn == 2 ? 2 : ((qn & 1) ? 3 : 4);
      const int rb = qn ? (qn - nb) / 2 : 0;
      if (qn) {
        aac_bitwriter_write(&w, 0x5A5u & ((1u << (4 * nb)) - 1u), 4 * nb);
        for (int i = 0; i < 8; i++) {
          aac_bitwriter_write(&w, (uint32_t)i & ((1u << rb) - 1u), rb);
        }
      }
      const int bits = aac_bitwriter_bits_written(&w);
      AacBitReader r;
      aac_bitreader_init(&r, buf, sizeof(buf));
      LpdAvqBlock b;
      CHECK(lpd_read_avq_block(&r, nk, &b) == AAC_OK && b.qn == qn && r.pos == bits,
            "avq block nk %d qn %d: read qn %d, %d of %d bits", nk, qn, b.qn, r.pos, bits);
    }
  }
}

/* ── ACELP ──────────────────────────────────────────────────────── */

static void test_acelp_pulses() {
  LpdAcelpSubframe sf;
  memset(&sf, 0, sizeof(sf));
  float code[LPD_MAX_SUBFR], base[LPD_MAX_SUBFR];
  /* a zero codeword still places pulses, so the tracks under test are
   * compared against the pulses of the all-zero codewords */
  auto others_unchanged = [&](int track) {
    int diff = 0;
    for (int i = 0; i < LPD_MAX_SUBFR; i++) {
      diff += (i % 4 != track) && code[i] != base[i];
    }
    return diff == 0;
  };
  /* 20 bits: one pulse per track at the coded position with the coded sign */
  sf.icb_bits = 20;
  memset(sf.icb_index, 0, sizeof(sf.icb_index));
  lpd_acelp_pulses(&sf, base, LPD_MAX_SUBFR);
  for (int t = 0; t < 4; t++) {
    for (int p = 0; p < 16; p++) {
      for (int s = 0; s < 2; s++) {
        memset(sf.icb_index, 0, sizeof(sf.icb_index));
        sf.icb_index[t] = (uint32_t)(p | (s << 4));
        lpd_acelp_pulses(&sf, code, LPD_MAX_SUBFR);
        int nz = 0;
        for (int i = t; i < LPD_MAX_SUBFR; i += 4) {
          nz += code[i] != 0.0f;
        }
        CHECK(nz == 1 && others_unchanged(t) && code[4 * p + t] == (s ? 1.0f : -1.0f),
              "20 bit pulse t%d p%d s%d", t, p, s);
      }
    }
  }
  /* 36 bits: the 9 bit code of two pulses per track is a bijection onto
   * the 512 signed position pairs of a track */
  sf.icb_bits = 36;
  memset(sf.icb_index, 0, sizeof(sf.icb_index));
  lpd_acelp_pulses(&sf, base, LPD_MAX_SUBFR);
  {
    std::set<std::array<int, 16>> seen;
    for (int idx = 0; idx < 512; idx++) {
      memset(sf.icb_index, 0, sizeof(sf.icb_index));
      sf.icb_index[2] = (uint32_t)idx;
      lpd_acelp_pulses(&sf, code, LPD_MAX_SUBFR);
      std::array<int, 16> key;
      float sum = 0.0f;
      const int other = !others_unchanged(2);
      for (int i = 2; i < LPD_MAX_SUBFR; i += 4) {
        key[i / 4] = (int)code[i];
        sum += fabsf(code[i]);
      }
      CHECK(other == 0 && sum == 2.0f, "36 bit code %d: pulses outside the track or not two",
            idx);
      seen.insert(key);
    }
    CHECK(seen.size() == 512, "36 bit: %zu distinct vectors of 512", seen.size());
  }
  /* 52 and 64 bits: three and four pulses per track */
  for (int bits = 52; bits <= 64; bits += 12) {
    sf.icb_bits = bits;
    const int np = bits == 52 ? 3 : 4;
    const int width = bits == 52 ? 13 : 16;
    unsigned seed = 3u;
    memset(sf.icb_index, 0, sizeof(sf.icb_index));
    lpd_acelp_pulses(&sf, base, LPD_MAX_SUBFR);
    for (int trial = 0; trial < 2000; trial++) {
      seed = seed * 1664525u + 1013904223u;
      memset(sf.icb_index, 0, sizeof(sf.icb_index));
      sf.icb_index[1] = (seed >> 8) & ((1u << width) - 1u);
      lpd_acelp_pulses(&sf, code, LPD_MAX_SUBFR);
      float sum = 0.0f;
      const int other = !others_unchanged(1);
      for (int i = 1; i < LPD_MAX_SUBFR; i += 4) {
        sum += fabsf(code[i]);
      }
      /* pulses at one position add up, so only the parity and the
       * maximum of the total amplitude are fixed */
      CHECK(other == 0 && (int)sum <= np && (np - (int)sum) % 2 == 0,
            "%d bit code: %d pulses, %d outside", bits, (int)sum, other);
    }
  }
  /* 12 and 16 bits: two pulses on the even/odd tracks, one pulse per
   * track on every other position */
  sf.icb_bits = 12;
  memset(sf.icb_index, 0, sizeof(sf.icb_index));
  sf.icb_index[0] = 5u | 32u;
  sf.icb_index[1] = 7u;
  lpd_acelp_pulses(&sf, code, LPD_MAX_SUBFR);
  CHECK(code[10] == 1.0f && code[15] == -1.0f, "12 bit pulses");
  sf.icb_bits = 16;
  memset(sf.icb_index, 0, sizeof(sf.icb_index));
  sf.icb_index[3] = 6u | 8u;
  lpd_acelp_pulses(&sf, code, LPD_MAX_SUBFR);
  CHECK(code[6 * 8 + 3] == 1.0f, "16 bit pulse");
  CHECK(lpd_acelp_icb_bits(0) == 12 && lpd_acelp_icb_bits(7) == 64, "icb bits table");
}

static void test_pitch() {
  AacUsacLpdChannel* c = aac_usac_lpd_channel_create(1024, 12000);
  CHECK(c != nullptr, "channel");
  if (!c) {
    return;
  }
  printf("pitch range at 12 kHz: min %d fr2 %d fr1 %d max %d\n", c->pit_min, c->pit_fr2,
         c->pit_fr1, c->pit_max);
  CHECK(c->pit_min >= 17 && c->pit_fr2 > c->pit_min && c->pit_fr1 > c->pit_fr2 &&
            c->pit_max >= c->pit_fr1,
        "pitch range order");
  float last = -1.0f;
  int t0, frac, t0_min = 0, t0_max = 0;
  for (int idx = 0; idx < 512; idx++) {
    lpd_acelp_pitch(c, idx, 9, &t0, &frac, &t0_min, &t0_max);
    const float lag = (float)t0 + 0.25f * (float)frac;
    CHECK(lag >= last, "9 bit lag monotone at %d", idx);
    if (idx == 0) {
      CHECK(t0 == c->pit_min && frac == 0, "index 0 is pit_min");
    }
    CHECK(t0 >= c->pit_min && t0 <= c->pit_max, "lag %d in range at %d", t0, idx);
    CHECK(t0_max - t0_min == 15 && t0_min >= c->pit_min && t0_max <= c->pit_max,
          "relative range at %d", idx);
    last = lag;
  }
  lpd_acelp_pitch(c, 4 * (c->pit_fr2 - c->pit_min) - 1, 9, &t0, &frac, &t0_min, &t0_max);
  CHECK(t0 == c->pit_fr2 - 1 && frac == 3, "last quarter resolution lag");
  lpd_acelp_pitch(c, 200, 9, &t0, &frac, &t0_min, &t0_max);
  const int base = t0;
  for (int idx = 0; idx < 64; idx++) {
    int t1, f1;
    lpd_acelp_pitch(c, idx, 6, &t1, &f1, &t0_min, &t0_max);
    CHECK(t1 == t0_min + idx / 4 && f1 == idx % 4, "6 bit lag at %d", idx);
  }
  CHECK(t0_min <= base && base <= t0_max, "relative range around the lag");
  /* the adaptive codebook interpolation reproduces a shifted sinusoid */
  float exc[LPD_EXC_HIST + 64];
  for (int i = 0; i < LPD_EXC_HIST + 64; i++) {
    exc[i] = sinf(0.1f * (float)i);
  }
  float v[64];
  const int lag = 100;
  lpd_acelp_adaptive(c, exc + LPD_EXC_HIST, lag, 2, v, 64);
  for (int i = 0; i < 64; i++) {
    const float want = sinf(0.1f * ((float)(LPD_EXC_HIST + i) - (float)lag - 0.5f));
    CHECK(fabsf(v[i] - want) < 0.02f, "interpolation %d: %f vs %f", i, v[i], want);
  }
  aac_usac_lpd_channel_destroy(c);
}

/* ── arithmetic decoder ─────────────────────────────────────────── */

using ArithEnc = struct ArithEnc_ {
  AacBitWriter* w;
  uint32_t low, high;
  int follow;
};

static void enc_bit(ArithEnc* e, int bit) {
  aac_bitwriter_write(e->w, (uint32_t)bit, 1);
  while (e->follow > 0) {
    aac_bitwriter_write(e->w, (uint32_t)!bit, 1);
    e->follow--;
  }
}

static void enc_symbol(ArithEnc* e, const uint16_t* cf, int s) {
  const uint32_t range = e->high - e->low + 1;
  e->high = e->low + ((range * cf[s]) >> 14) - 1;
  e->low = e->low + ((range * cf[s + 1]) >> 14);
  for (;;) {
    if (e->high < 32768) {
      enc_bit(e, 0);
    } else if (e->low >= 32768) {
      enc_bit(e, 1);
      e->low -= 32768;
      e->high -= 32768;
    } else if (e->low >= 16384 && e->high < 49152) {
      e->follow++;
      e->low -= 16384;
      e->high -= 16384;
    } else {
      break;
    }
    e->low += e->low;
    e->high += e->high + 1;
  }
}

static void enc_finish(ArithEnc* e) {
  e->follow++;
  enc_bit(e, e->low >= 16384 ? 1 : 0);
}

static void test_arith() {
  unsigned seed = 11u;
  LpdArithState dec_state;
  lpd_arith_reset(&dec_state);
  uint8_t prev_ctx[LPD_MAX_TCX / 2 + 4] = {0};
  int lg_prev = 0;
  const int lgs[5] = {256, 1024, 512, 256, 256};
  for (int frame = 0; frame < 5; frame++) {
    const int lg = lgs[frame];
    const int n = lg / 2;
    std::vector<int> q(lg);
    for (int i = 0; i < lg; i++) {
      seed = seed * 1664525u + 1013904223u;
      const int mag = (int)((seed >> 8) % 7u) == 0 ? (int)((seed >> 12) % 40u) : (int)((seed >> 20) % 3u);
      q[i] = i > lg * 3 / 4 ? 0 : (seed & 1u ? -mag : mag);
    }
    /* encode with the decoder's context rule */
    uint8_t prev[LPD_MAX_TCX / 2 + 4], cur[LPD_MAX_TCX / 2 + 4] = {0};
    if (lg_prev == 0) {
      memset(prev, 0, sizeof(prev));
    } else {
      const int np = lg_prev / 2;
      for (int i = 0; i < n; i++) {
        prev[i] = prev_ctx[(int)(((long)i * np) / n)];
      }
      prev[n] = 0;
    }
    uint8_t buf[2048] = {0};
    AacBitWriter w;
    aac_bitwriter_init(&w, buf, sizeof(buf));
    aac_bitwriter_write(&w, 0x5u, 3); /* leading syntax */
    ArithEnc e;
    e.w = &w;
    e.low = 0;
    e.high = 65535;
    e.follow = 0;
    for (int i = 0; i < n; i++) {
      const int a = abs(q[2 * i]), b = abs(q[2 * i + 1]);
      int lev = 0;
      while ((a >> lev) > 3 || (b >> lev) > 3) {
        lev++;
      }
      for (int l = 0; l < lev; l++) {
        enc_symbol(&e, lpd_arith_cf_m(lpd_arith_pk(prev, cur, i, n, l > 7 ? 7 : l)), 16);
      }
      const int m = ((b >> lev) << 2) | (a >> lev);
      enc_symbol(&e, lpd_arith_cf_m(lpd_arith_pk(prev, cur, i, n, lev > 7 ? 7 : lev)), m);
      for (int l = lev - 1; l >= 0; l--) {
        const int rr = ((a >> l) & 1) | (((b >> l) & 1) << 1);
        enc_symbol(&e, lpd_arith_cf_r((lev - 1 - l) < 2 ? (lev - 1 - l) : 2), rr);
      }
      const int cls = a + b + 1;
      cur[i] = (uint8_t)(cls > 15 ? 15 : cls);
    }
    enc_finish(&e);
    for (int i = 0; i < lg; i++) {
      if (q[i] != 0) {
        aac_bitwriter_write(&w, q[i] < 0 ? 1u : 0u, 1);
      }
    }
    aac_bitwriter_write(&w, 0x2Au, 6); /* trailing syntax */
    const int total = aac_bitwriter_bits_written(&w);
    AacBitReader r;
    aac_bitreader_init(&r, buf, sizeof(buf));
    CHECK(aac_bitreader_read(&r, 3) == 5u, "leading bits");
    std::vector<int> out(lg, 0);
    const int err = lpd_arith_decode(&r, &dec_state, lg, out.data());
    CHECK(err == AAC_OK, "frame %d: arith decode %d", frame, err);
    int wrong = 0;
    for (int i = 0; i < lg; i++) {
      wrong += out[i] != q[i];
    }
    CHECK(wrong == 0, "frame %d (lg %d): %d lines differ", frame, lg, wrong);
    CHECK(aac_bitreader_read(&r, 6) == 0x2Au && r.pos == total,
          "frame %d: the decoder stops at the encoder's end (%d of %d bits)", frame, r.pos,
          total);
    memcpy(prev_ctx, cur, sizeof(prev_ctx));
    lg_prev = lg;
  }
  /* a stop symbol (escape then zero) ends the spectrum early */
  {
    uint8_t buf[256] = {0};
    AacBitWriter w;
    aac_bitwriter_init(&w, buf, sizeof(buf));
    ArithEnc e;
    e.w = &w;
    e.low = 0;
    e.high = 65535;
    e.follow = 0;
    uint8_t zero[LPD_MAX_TCX / 2 + 4] = {0}, cur[LPD_MAX_TCX / 2 + 4] = {0};
    enc_symbol(&e, lpd_arith_cf_m(lpd_arith_pk(zero, cur, 0, 128, 0)), 5);
    cur[0] = 3;
    enc_symbol(&e, lpd_arith_cf_m(lpd_arith_pk(zero, cur, 1, 128, 0)), 16);
    enc_symbol(&e, lpd_arith_cf_m(lpd_arith_pk(zero, cur, 1, 128, 1)), 0);
    enc_finish(&e);
    aac_bitwriter_write(&w, 1u, 1); /* sign of line 0 */
    aac_bitwriter_write(&w, 0u, 1); /* sign of line 1 */
    LpdArithState st;
    lpd_arith_reset(&st);
    AacBitReader r;
    aac_bitreader_init(&r, buf, sizeof(buf));
    int out[256];
    CHECK(lpd_arith_decode(&r, &st, 256, out) == AAC_OK, "stop: decode");
    CHECK(out[0] == -1 && out[1] == 1 && out[2] == 0 && out[255] == 0, "stop: lines %d %d %d",
          out[0], out[1], out[2]);
    CHECK(r.pos == aac_bitwriter_bits_written(&w), "stop: position");
  }
}

/* ── TCX windows ────────────────────────────────────────────────── */

static void test_tcx_windows() {
  AacMdct mdct;
  aac_mdct_init(&mdct, 512);
  const int lg = 256;
  const int n = 8 * lg;
  std::vector<float> s(n), rec(n, 0.0f);
  unsigned seed = 1u;
  for (int i = 0; i < n; i++) {
    seed = seed * 1664525u + 1013904223u;
    s[i] = 0.5f * sinf(0.03f * (float)i) + 0.2f * ((float)(seed >> 8) / 8388608.0f - 1.0f);
  }
  /* frames at b = 2 lg + j lg; the first has an FD-style left slope */
  float w[2 * LPD_MAX_TCX], seg[2 * LPD_MAX_TCX], coef[LPD_MAX_TCX], t[2 * LPD_MAX_TCX];
  const int frames = 5;
  for (int j = 0; j < frames; j++) {
    const int b = 2 * lg + j * lg;
    const int left = j == 0 ? 256 : 128;
    lpd_tcx_window(w, lg, left, 128);
    for (int i = 0; i < 2 * lg; i++) {
      seg[i] = s[b - lg / 2 + i] * w[i];
    }
    aac_mdct_forward(&mdct, seg, coef);
    aac_imdct(&mdct, coef, t);
    for (int i = 0; i < 2 * lg; i++) {
      rec[b - lg / 2 + i] += t[i] * w[i];
    }
  }
  /* perfect reconstruction between the first frame's left slope end and
   * the last frame's right slope start */
  const int from = 2 * lg + 128, to = 2 * lg + (frames - 1) * lg + lg - 64;
  double err = 0.0, ref = 0.0;
  for (int i = from; i < to; i++) {
    err += (double)(rec[i] - s[i]) * (rec[i] - s[i]);
    ref += (double)s[i] * s[i];
  }
  const double snr = 10.0 * log10(ref / (err + 1e-20));
  CHECK(snr > 80.0, "TCX overlap-add reconstruction: %.1f dB", snr);
  /* power complementary slopes */
  lpd_tcx_window(w, lg, 128, 128);
  for (int i = 0; i < 128; i++) {
    const float a = w[lg / 2 - 64 + i], b = w[3 * lg / 2 - 64 + i];
    CHECK(fabsf(a * a + w[lg / 2 - 64 + 127 - i] * w[lg / 2 - 64 + 127 - i] - 1.0f) < 1e-5f,
          "left slope power at %d", i);
    CHECK(fabsf(a - w[3 * lg / 2 - 64 + 127 - i]) < 1e-5f && b >= 0.0f, "slope symmetry at %d",
          i);
  }
  CHECK(w[0] == 0.0f && w[lg / 2 - 65] == 0.0f && w[lg] == 1.0f && w[2 * lg - 1] == 0.0f,
        "window support");
}

/* ── forward aliasing cancellation ──────────────────────────────── */

static void test_fac() {
  const int lg = 256, L = 64;
  const int n = 4 * lg;
  const int b = 2 * lg; /* the boundary: ACELP before, TCX after */
  std::vector<float> s(n), rec(n, 0.0f);
  unsigned seed = 7u;
  for (int i = 0; i < n; i++) {
    seed = seed * 1664525u + 1013904223u;
    s[i] = 0.6f * sinf(0.021f * (float)i) + 0.3f * cosf(0.3f * (float)i) +
           0.1f * ((float)(seed >> 8) / 8388608.0f - 1.0f);
  }
  /* an LPC filter for the weighting */
  float lsf[LPD_M], lsp[LPD_M], a[LPD_M + 1];
  for (int i = 0; i < LPD_M; i++) {
    lsf[i] = 200.0f + 370.0f * (float)i + 60.0f * sinf((float)i);
  }
  lpd_lsf_to_lsp(lsf, lsp);
  lpd_lsp_to_a(lsp, a);
  /* ACELP: exact before b */
  for (int i = 0; i < b; i++) {
    rec[i] = s[i];
  }
  /* TCX: windowed transform output from b - lg/2 */
  AacMdct mdct;
  aac_mdct_init(&mdct, 2 * lg);
  float w[2 * LPD_MAX_TCX], seg[2 * LPD_MAX_TCX], coef[LPD_MAX_TCX], t[2 * LPD_MAX_TCX];
  lpd_tcx_window(w, lg, 2 * L, 2 * L);
  for (int i = 0; i < 2 * lg; i++) {
    seg[i] = s[b - lg / 2 + i] * w[i];
  }
  aac_mdct_forward(&mdct, seg, coef);
  aac_imdct(&mdct, coef, t);
  for (int i = 0; i < 2 * lg; i++) {
    rec[b - lg / 2 + i] += t[i] * w[i];
  }
  /* the error over the FAC region, whitened by A(z / gamma), its first
   * half transformed */
  float e[2 * L], ew[2 * L], ap[LPD_M + 1], zero[LPD_M] = {0};
  for (int i = 0; i < 2 * L; i++) {
    e[i] = s[b - L + i] - rec[b - L + i];
  }
  lpd_weight_a(a, LPD_GAMMA1, ap);
  lpd_res_filter(ap, e, ew, 2 * L, zero);
  float c[L];
  lpd_dct4(ew, c, L);
  const float scale = 1.0f / 4096.0f;
  int q[L];
  for (int i = 0; i < L; i++) {
    q[i] = (int)lrintf(c[i] / scale);
  }
  float fac[2 * L];
  lpd_fac_synth_q(q, L, scale, a, fac);
  for (int i = 0; i < 2 * L; i++) {
    rec[b - L + i] += fac[i];
  }
  double err1 = 0.0, ref1 = 0.0, before = 0.0;
  for (int i = b - L; i < b; i++) {
    err1 += (double)(rec[i] - s[i]) * (rec[i] - s[i]);
    ref1 += (double)s[i] * s[i];
  }
  for (int i = b - L; i < b; i++) {
    before += (double)e[i - (b - L)] * e[i - (b - L)];
  }
  const double snr1 = 10.0 * log10(ref1 / (err1 + 1e-20));
  CHECK(snr1 > 50.0, "FAC cancels the aliasing before the boundary: %.1f dB (error %.1f dB "
                     "before)", snr1, 10.0 * log10(ref1 / (before + 1e-20)));
  /* the second half is the filter's response: the whitened residual of
   * the reconstruction there has no contribution from the first half */
  double tail = 0.0;
  for (int i = L; i < 2 * L; i++) {
    tail += (double)fac[i] * fac[i];
  }
  CHECK(tail > 0.0, "the FAC rings into the following segment");
  /* the DCT-IV is orthonormal: applying it twice returns the input */
  float back[L];
  lpd_dct4(c, back, L);
  for (int i = 0; i < L; i++) {
    CHECK(fabsf(back[i] - ew[i]) < 1e-3f, "DCT-IV involution at %d: %f vs %f", i, back[i],
          ew[i]);
  }
  /* the ZIR continues the synthesis filter */
  float mem[LPD_M], zir[2 * L];
  for (int i = 0; i < LPD_M; i++) {
    mem[i] = s[b - 1 - i];
  }
  lpd_zir(a, mem, zir, 2 * L);
  float mem2[LPD_M], syn[2 * L], zin[2 * L] = {0};
  memcpy(mem2, mem, sizeof(mem2));
  lpd_syn_filter(a, zin, syn, 2 * L, mem2);
  for (int i = 0; i < 2 * L; i++) {
    CHECK(syn[i] == zir[i], "zir %d", i);
  }
}

/* ── core channel contract ──────────────────────────────────────── */

static void test_contract() {
  for (int cfg = 0; cfg < 2; cfg++) {
    const int n = cfg ? 768 : 1024;
    AacUsacCoreChannel ch;
    memset(&ch, 0, sizeof(ch));
    ch.prev_core_mode = -1;
    ch.lpd = aac_usac_lpd_channel_create(n, cfg ? 18000 : 12000);
    CHECK(ch.lpd != nullptr, "create %d", n);
    std::vector<float> out(n);
    /* concealment before any frame: silence, contract kept */
    aac_usac_lpd_conceal(&ch, n, out.data());
    CHECK(ch.prev_core_mode == 1, "conceal sets prev_core_mode");
    double e = 0.0;
    for (int i = 0; i < n; i++) {
      e += fabs(out[i]);
    }
    CHECK(e == 0.0, "conceal of nothing is silent");
    /* a random bitstream parses or fails cleanly, never crashes */
    unsigned seed = 42u + (unsigned)cfg;
    int ok = 0, fail = 0;
    for (int trial = 0; trial < 200; trial++) {
      uint8_t buf[600];
      for (size_t i = 0; i < sizeof(buf); i++) {
        seed = seed * 1664525u + 1013904223u;
        buf[i] = (uint8_t)(seed >> 24);
      }
      /* keep lpd_mode valid so that most trials reach the synthesis */
      buf[0] = (uint8_t)((buf[0] & 0xE0u) | ((trial % 26) >> 1));
      buf[1] = (uint8_t)((buf[1] & 0x7Fu) | (((trial % 26) & 1u) << 7));
      AacBitReader r;
      aac_bitreader_init(&r, buf, sizeof(buf));
      const int err = aac_usac_lpd_decode(&ch, n, trial & 1, &r, out.data());
      if (err == AAC_OK) {
        ok++;
        int finite = 1;
        for (int i = 0; i < n; i++) {
          finite &= std::isfinite(out[i]) != 0;
        }
        CHECK(finite, "trial %d: finite output", trial);
        CHECK(ch.prev_core_mode == 1, "trial %d: prev_core_mode", trial);
      } else {
        fail++;
      }
      if (trial % 7 == 3) {
        aac_usac_lpd_conceal(&ch, n, out.data());
      }
      if (trial % 11 == 5) {
        /* an FD frame in between: its FAC and tail */
        int fac_q[LPD_MAX_LFAC];
        for (int i = 0; i < n / 8; i++) {
          fac_q[i] = (int)(seed >> (i % 20)) % 5 - 2;
        }
        aac_usac_lpd_fac_to_fd_gain(&ch, fac_q, n / 8, n, 40);
        for (int i = 0; i < n; i++) {
          ch.overlap[i] = 0.01f * (float)(i % 17);
        }
        ch.prev_core_mode = 0;
      }
    }
    printf("random frames at %d: %d decoded, %d refused\n", n, ok, fail);
    CHECK(ok > 0, "some random frames decode");
    aac_usac_lpd_channel_destroy(ch.lpd);
  }
}

/* ── the xHE-AAC speech streams ─────────────────────────────────── */

using LoasStream = struct LoasStream_ {
  const char* name;
  const char* ref;
  int frame_length;
  int core_rate;
  int sbr_ratio;
};

/* escapedValue(nBits1, nBits2, nBits3) of ISO/IEC 23003-3 5.2. */
static unsigned escaped_value(AacBitReader* r, int b1, int b2, int b3) {
  unsigned v = aac_bitreader_read(r, b1);
  if (v == (1u << b1) - 1u) {
    const unsigned v2 = aac_bitreader_read(r, b2);
    v += v2;
    if (v2 == (1u << b2) - 1u && b3 > 0) {
      v += aac_bitreader_read(r, b3);
    }
  }
  return v;
}

/* SNR of `ref` against `out` shifted by up to `max_shift` samples either
 * way, with the least squares gain on `out` (the core output's absolute
 * level is not pinned down by the placeholder codebooks). */
static double best_gain_snr_db(const std::vector<float>& ref, const std::vector<float>& out,
                               int max_shift, int* best_shift, double* best_gain) {
  double best = -100.0;
  const int n = (int)std::min(ref.size(), out.size());
  for (int shift = -max_shift; shift <= max_shift; shift++) {
    double rr = 0.0, ro = 0.0, oo = 0.0;
    for (int i = max_shift; i < n - max_shift; i++) {
      const double a = ref[(size_t)i], b = out[(size_t)(i + shift)];
      rr += a * a;
      ro += a * b;
      oo += b * b;
    }
    if (oo <= 0.0) {
      continue;
    }
    const double g = ro / oo;
    const double err = rr - 2.0 * g * ro + g * g * oo;
    const double snr = 10.0 * log10(rr / (err > 0.0 ? err : 1e-20));
    if (snr > best) {
      best = snr;
      *best_shift = shift;
      *best_gain = g;
    }
  }
  return best;
}

/* Walks the access units of a mono xHE-AAC LOAS stream from the FDK
 * encoder (elements: pre-roll extension, DRC extension with a default
 * length of 2, SCE, fill extension), decodes the LPD frames and reports
 * how many decode, the pitch continuity, and the SNR of the core
 * output against the FDK decoder's output resampled to the core rate. */
static void test_stream(const LoasStream& st) {
  const std::vector<uint8_t> data = read_file(data_path(st.name));
  CHECK(!data.empty(), "%s: read", st.name);
  if (data.empty()) {
    return;
  }
  const int n = st.frame_length;
  AacUsacCoreChannel ch;
  memset(&ch, 0, sizeof(ch));
  ch.prev_core_mode = -1;
  ch.lpd = aac_usac_lpd_channel_create(n, st.core_rate);
  std::vector<float> out;
  std::vector<float> frame(n);
  size_t pos = 0;
  int frames = 0, lpd_frames = 0, lpd_ok = 0, fd_frames = 0, first_run = 0, in_first_run = -1;
  int acelp_frames = 0, acelp_ok = 0;
  int lag_checks = 0, lag_near = 0;
  int last_t0 = -1;
  while (pos + 3 <= data.size()) {
    CHECK(aac_loas_is_sync(data.data() + pos, (int)(data.size() - pos)), "%s: sync at %zu",
          st.name, pos);
    const int len = aac_loas_payload_length(data.data() + pos);
    const uint8_t* el = data.data() + pos + 3;
    AacBitReader r;
    aac_bitreader_init(&r, el, len);
    const int use_same = (int)aac_bitreader_read(&r, 1);
    if (!use_same) {
      /* the first element carries the StreamMuxConfig; the frame it
       * holds is the FD immediate playout frame, skipped */
      pos += 3 + (size_t)len;
      frames++;
      fd_frames++;
      out.insert(out.end(), n, 0.0f);
      continue;
    }
    int au_len = 0;
    for (;;) {
      const int t = (int)aac_bitreader_read(&r, 8);
      au_len += t;
      if (t != 255) {
        break;
      }
    }
    /* the AU is not byte aligned in the element: copy it out */
    std::vector<uint8_t> au((size_t)au_len + 8, 0);
    for (int i = 0; i < au_len; i++) {
      au[(size_t)i] = (uint8_t)aac_bitreader_read(&r, 8);
    }
    AacBitReader a;
    aac_bitreader_init(&a, au.data(), au_len);
    const int indep = (int)aac_bitreader_read(&a, 1);
    /* extension elements: pre-roll (no default length), DRC (default 2) */
    for (int e = 0; e < 2; e++) {
      if (aac_bitreader_read(&a, 1)) {
        const int dflt = (int)aac_bitreader_read(&a, 1);
        const int bytes = dflt ? (e == 1 ? 2 : 0) : (int)escaped_value(&a, 8, 16, 0);
        aac_bitreader_skip(&a, 8 * bytes);
      }
    }
    const int core_mode = (int)aac_bitreader_read(&a, 1);
    frames++;
    if (core_mode == 1) {
      lpd_frames++;
      const int before = a.pos;
      /* the mode fields, for the verdict: a frame without TCX does not
       * depend on the arithmetic coder's models */
      AacBitReader peek = a;
      aac_bitreader_skip(&peek, 3);
      const int lpd_mode = (int)aac_bitreader_read(&peek, 5);
      const int core_mode_last = (int)(aac_bitreader_read(&peek, 2) & 1u);
      const int acelp_only = lpd_mode == 0;
      acelp_frames += acelp_only;
      const int err = aac_usac_lpd_decode(&ch, n, indep, &a, frame.data());
      /* every frame with TCX goes through the placeholder models, so
       * only frames of ACELP alone are required to parse; the pre-roll
       * frames (core_mode_last 0 after an LPD frame) are excluded */
      if (acelp_only && core_mode_last == 1) {
        CHECK(err == AAC_OK, "%s: frame %d: lpd decode %d", st.name, frames - 1, err);
      }
      if (err == AAC_OK) {
        lpd_ok++;
        acelp_ok += acelp_only;
        double energy = 0.0;
        int finite = 1;
        for (int i = 0; i < n; i++) {
          finite = finite && std::isfinite(frame[(size_t)i]);
          energy += (double)frame[(size_t)i] * frame[(size_t)i];
        }
        CHECK(finite, "%s: frame %d: output not finite", st.name, frames - 1);
        printf("%s: frame %d: lpd_mode %d, %d of %d bits, rms %.4f\n", st.name, frames - 1,
               lpd_mode, a.pos - before, 8 * au_len - before, sqrt(energy / n));
        CHECK(a.pos - before < 8 * au_len, "%s: frame %d: %d bits of %d", st.name, frames - 1,
              a.pos - before, 8 * au_len);
        if (in_first_run < 0 || in_first_run == 1) {
          in_first_run = 1;
          first_run++;
        }
        /* pitch continuity of the absolute lags */
        const LpdFrameData* f = &ch.lpd->frame;
        for (int k = 0; k < 4; k++) {
          if (f->mod[k] != 0) {
            continue;
          }
          for (int sf = 0; sf < ch.lpd->nb_subfr; sf++) {
            if (sf != 0 && sf != 2) {
              continue;
            }
            int t0, frac, t0_min, t0_max;
            lpd_acelp_pitch(ch.lpd, f->acelp[k].sf[sf].acb_index, 9, &t0, &frac, &t0_min,
                            &t0_max);
            if (last_t0 > 0) {
              lag_checks++;
              const int d = abs(t0 - last_t0);
              lag_near += d <= last_t0 / 5 || abs(t0 - 2 * last_t0) <= last_t0 / 5 ||
                          abs(2 * t0 - last_t0) <= last_t0 / 5;
            }
            last_t0 = t0;
          }
        }
        out.insert(out.end(), frame.begin(), frame.end());
      } else {
        out.insert(out.end(), n, 0.0f);
        in_first_run = 0;
      }
    } else {
      fd_frames++;
      in_first_run = in_first_run < 0 ? -1 : 0;
      /* an FD frame we cannot decode: an empty tail stands in */
      memset(ch.overlap, 0, sizeof(ch.overlap));
      ch.prev_core_mode = 0;
      out.insert(out.end(), n, 0.0f);
    }
    pos += 3 + (size_t)len;
  }
  printf("%s: %d frames, %d FD, %d LPD of which %d decoded (%d of %d ACELP-only); first LPD "
         "run %d frames; absolute pitch lags near the previous: %d of %d\n",
         st.name, frames, fd_frames, lpd_frames, lpd_ok, acelp_ok, acelp_frames, first_run,
         lag_near, lag_checks);
  CHECK(lpd_frames > 0 && lpd_ok > 0, "%s: some LPD frames parse", st.name);
  const std::vector<float> ref = read_f32(data_path(st.ref));
  if (!ref.empty() && !out.empty()) {
    int shift = 0;
    double gain = 0.0;
    const double snr = best_gain_snr_db(ref, out, n, &shift, &gain);
    printf("%s: core output against the FDK decode at the core rate: %.1f dB at shift %d, "
           "gain %.3f (informational while the codebooks are placeholders)\n",
           st.name, snr, shift, gain);
  }
  aac_usac_lpd_channel_destroy(ch.lpd);
}

int main() {
  lpd_tables_init();
  test_modes();
  test_lsp_lpc();
  test_avq();
  test_acelp_pulses();
  test_pitch();
  test_arith();
  test_tcx_windows();
  test_fac();
  test_contract();
  const LoasStream streams[2] = {
      {"usac_lpd_speech_12000.loas", "usac_lpd_speech_12000_core.f32", 1024, 12000, 4},
      {"usac_lpd_speech_24000.loas", "usac_lpd_speech_24000_core.f32", 768, 18000, 3},
  };
  for (const LoasStream& s : streams) {
    test_stream(s);
  }
  return test_summary("test_usac_lpd");
}
