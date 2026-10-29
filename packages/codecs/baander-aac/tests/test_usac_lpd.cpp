/* Tests of the USAC linear prediction domain core: the mode table, the
 * LSP to LPC conversion against an LPC analysis of the synthesis filter,
 * the algebraic vector quantiser (codevectors on the RE8 lattice and the
 * index syntax round trip), the ACELP pulse codes and the pitch lag
 * codes, the FAC transform and windows, the core channel contract
 * (overlap, concealment, random input), and the core output of six
 * xHE-AAC streams with an LPD core (12, 18 and 24 kHz, 1024 and 768
 * lines, SCE and CPE with one core channel) against stored expectations
 * that were verified against the libxaac reference decoder's core output
 * frame by frame (92 to 119 dB). */
#include <array>
#include <cmath>
#include <set>

#include "../src/usac_lpd_internal.h"
#include "asc.h"
#include "latm.h"
#include "test_util.h"
#include "usac.h"
#include "usac_fd.h"
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
  double tmp[LPD_M + 1];
  double err = r[0];
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
    float back[LPD_M];
    lpd_lsp_to_lsf(lsp, back);
    for (int i = 0; i < LPD_M; i++) {
      CHECK(fabsf(back[i] - lsf[i]) < 0.05f, "lsf round trip %d: %f vs %f", i, back[i], lsf[i]);
    }
    /* the impulse response of 1 / A(z) is stable and its LPC analysis
     * returns A(z) */
    const int n = 8192;
    std::vector<float> buf((size_t)(LPD_M + n), 0.0f);
    float* h = buf.data() + LPD_M;
    h[0] = 1.0f;
    lpd_syn_filter(a, h, n);
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
    /* the analysis filter inverts the synthesis */
    std::vector<float> res((size_t)n, 0.0f);
    lpd_res_filter(a, h, res.data(), n);
    CHECK(fabsf(res[0] - 1.0f) < 1e-4f && fabsf(res[100]) < 1e-4f, "trial %d: residual", trial);
    /* the weighted filter */
    float ap[LPD_M + 1];
    lpd_weight_a(a, LPD_GAMMA, ap);
    CHECK(fabsf(ap[2] - a[2] * LPD_GAMMA * LPD_GAMMA) < 1e-6f, "weighting");
  }
  /* the stability factor */
  float same[LPD_M], far[LPD_M];
  for (int i = 0; i < LPD_M; i++) {
    same[i] = 300.0f * (float)(i + 1);
    far[i] = same[i] + 400.0f;
  }
  CHECK(lpd_stab_fac(same, same) == 1.0f, "stab_fac identical");
  CHECK(lpd_stab_fac(far, same) < 0.5f, "stab_fac distant: %f", lpd_stab_fac(far, same));
  /* the interpolation ends at the second set */
  float lsp0[LPD_M], lsp1[LPD_M], a0[LPD_M + 1], a1[LPD_M + 1], ai[5][LPD_M + 1];
  lpd_lsf_to_lsp(same, lsp0);
  lpd_lsf_to_lsp(far, lsp1);
  lpd_lsp_to_a(lsp0, a0);
  lpd_lsp_to_a(lsp1, a1);
  lpd_interp_lsp(lsp0, lsp1, 4, ai);
  CHECK(memcmp(ai[4], a1, sizeof(a1)) == 0, "interpolation: last set");
  CHECK(fabsf(ai[0][1] - (0.875f * a0[1] + 0.125f * a1[1])) < 0.05f, "interpolation: first subframe");
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
  /* the base codebooks Q2, Q3, Q4: every index is a point of RE8 and
   * nearly all indices are distinct codevectors */
  for (int nb = 2; nb <= 4; nb++) {
    const int size = 1 << (4 * nb);
    std::set<std::array<int, 8>> seen;
    int bad = 0;
    const int kv[8] = {0};
    for (int idx = 0; idx < size; idx++) {
      int y[8];
      lpd_avq_decode(nb, (uint32_t)idx, kv, y);
      bad += !in_re8(y);
      std::array<int, 8> key;
      for (int i = 0; i < 8; i++) {
        key[i] = y[i];
      }
      seen.insert(key);
    }
    CHECK(bad == 0, "Q%d: %d codevectors outside RE8", nb, bad);
    CHECK((int)seen.size() > size * 9 / 10, "Q%d: %zu distinct codevectors of %d", nb,
          seen.size(), size);
  }
  /* Voronoi extension: every (base, k) gives a lattice point, distinct
   * over the Voronoi indices of one base vector */
  unsigned seed = 5u;
  for (int qn = 5; qn <= 8; qn++) {
    const int nb = (qn & 1) ? 3 : 4;
    const int r = (qn - nb) / 2;
    std::set<std::array<int, 8>> seen;
    const int trials = r == 1 ? 256 : 300;
    int bad = 0;
    for (int t = 0; t < trials; t++) {
      int kv[8];
      for (int i = 0; i < 8; i++) {
        seed = seed * 1664525u + 1013904223u;
        unsigned h = seed ^ (seed >> 13);
        h *= 0x5bd1e995u;
        h ^= h >> 15;
        kv[i] = r == 1 ? ((t >> i) & 1) : (int)(h & ((1u << r) - 1u));
      }
      int y[8];
      lpd_avq_decode(qn, 77u, kv, y);
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
  /* syntax round trip of the codebook number codes: two blocks of
   * eight, each with its code (unary in nk_mode 1; two bits for 2 to 4
   * else, with an escape to a unary extension) */
  for (int nk = 0; nk <= 2; nk++) {
    for (int qn = 0; qn <= 8; qn++) {
      if (qn == 1) {
        continue;
      }
      uint8_t buf[64] = {0};
      AacBitWriter w;
      aac_bitwriter_init(&w, buf, sizeof(buf));
      const int qns[2] = {qn, 3};
      for (int k = 0; k < 2; k++) {
        const int q = qns[k];
        if (nk == 1) {
          for (int i = 0; i < (q == 0 ? 0 : q - 1); i++) {
            aac_bitwriter_write(&w, 1, 1);
          }
          aac_bitwriter_write(&w, 0, 1);
        } else if (q >= 2 && q <= 4) {
          aac_bitwriter_write(&w, (uint32_t)(q - 2), 2);
        } else {
          aac_bitwriter_write(&w, 3, 2);
        }
      }
      for (int k = 0; k < 2; k++) {
        const int q = qns[k];
        if (nk == 1 || (q >= 2 && q <= 4)) {
          continue;
        }
        const int ext = nk == 2 ? (q == 0 ? 0 : q - 4)
                                : (q == 5 ? 0 : (q == 6 ? 1 : (q == 0 ? 2 : q - 4)));
        for (int i = 0; i < ext; i++) {
          aac_bitwriter_write(&w, 1, 1);
        }
        aac_bitwriter_write(&w, 0, 1);
      }
      for (int k = 0; k < 2; k++) {
        const int q = qns[k];
        const int nb = q == 2 ? 2 : ((q & 1) ? 3 : 4);
        const int rb = q ? (q - nb) / 2 : 0;
        if (q) {
          aac_bitwriter_write(&w, 0x5A5u & ((1u << (4 * nb)) - 1u), 4 * nb);
          for (int i = 0; i < 8; i++) {
            aac_bitwriter_write(&w, (uint32_t)i & ((1u << rb) - 1u), rb);
          }
        }
      }
      const int bits = aac_bitwriter_bits_written(&w);
      AacBitReader r;
      aac_bitreader_init(&r, buf, sizeof(buf));
      int y[16];
      for (int i = 0; i < 16; i++) {
        y[i] = 1;
      }
      const int ret = lpd_read_avq(&r, nk, y);
      int zero = 1;
      for (int i = 0; i < 8; i++) {
        zero = zero && y[i] == 0;
      }
      CHECK(ret == AAC_OK && r.pos == bits && (qn != 0 || zero) && (qn == 0 || in_re8(y)) &&
                in_re8(y + 8),
            "avq nk %d qn %d: ret %d, %d of %d bits", nk, qn, ret, r.pos, bits);
    }
  }
}

/* ── ACELP ──────────────────────────────────────────────────────── */

static void test_acelp_pulses() {
  int idx[8];
  float code[LPD_SUBFR];
  /* 20 bits: one pulse per track at the coded position with the coded sign */
  for (int t = 0; t < 4; t++) {
    for (int p = 0; p < 16; p++) {
      for (int s = 0; s < 2; s++) {
        memset(idx, 0, sizeof(idx));
        idx[t] = p | (s << 4);
        lpd_acelp_pulses(idx, 20, code);
        int nz = 0;
        for (int i = 0; i < LPD_SUBFR; i++) {
          nz += code[i] != 0.0f;
        }
        /* the other tracks carry the pulse of codeword 0: position 0, positive */
        CHECK(nz == 4 && code[4 * p + t] == (s ? -1.0f : 1.0f), "20 bit pulse t%d p%d s%d", t, p,
              s);
      }
    }
  }
  /* 36 bits: the 9 bit code of two pulses per track is a bijection onto
   * the 512 signed position pairs of a track */
  {
    std::set<std::array<int, 16>> seen;
    for (int i = 0; i < 512; i++) {
      memset(idx, 0, sizeof(idx));
      idx[2] = i;
      lpd_acelp_pulses(idx, 36, code);
      std::array<int, 16> key;
      float sum = 0.0f, other = 0.0f;
      for (int k = 0; k < LPD_SUBFR; k++) {
        if (k % 4 == 2) {
          key[k / 4] = (int)code[k];
          sum += fabsf(code[k]);
        } else {
          other += fabsf(code[k]);
        }
      }
      CHECK(sum == 2.0f && other == 6.0f, "36 bit code %d: pulse count", i);
      seen.insert(key);
    }
    CHECK(seen.size() == 512, "36 bit: %zu distinct vectors of 512", seen.size());
  }
  /* 52 and 64 bits: three and four pulses per track; pulses at one
   * position add up, so only the parity and the maximum of the total
   * amplitude are fixed */
  for (int bits = 52; bits <= 64; bits += 12) {
    const int np = bits == 52 ? 3 : 4;
    unsigned seed = 3u;
    for (int trial = 0; trial < 2000; trial++) {
      seed = seed * 1664525u + 1013904223u;
      memset(idx, 0, sizeof(idx));
      if (bits == 52) {
        idx[1] = (int)((seed >> 8) & 0x1FFFu);
      } else {
        idx[1] = (int)((seed >> 8) & 3u);
        idx[5] = (int)((seed >> 10) & 0x3FFFu);
      }
      lpd_acelp_pulses(idx, bits, code);
      float sum = 0.0f;
      for (int i = 1; i < LPD_SUBFR; i += 4) {
        sum += fabsf(code[i]);
      }
      CHECK((int)sum <= np && (np - (int)sum) % 2 == 0, "%d bit code: %d pulses", bits,
            (int)sum);
    }
  }
  /* 12 and 16 bits: two pulses on the even/odd tracks, one pulse per
   * track but one */
  memset(idx, 0, sizeof(idx));
  idx[0] = 1;
  idx[1] = 5 | 16;
  idx[2] = 0;
  idx[3] = 7;
  lpd_acelp_pulses(idx, 12, code);
  CHECK(code[4 * 5 + 2] == -1.0f && code[4 * 7 + 1] == 1.0f, "12 bit pulses");
  memset(idx, 0, sizeof(idx));
  idx[0] = 1;
  idx[3] = 6 | 16;
  lpd_acelp_pulses(idx, 16, code);
  CHECK(code[4 * 6 + 2] == -1.0f && code[0] == 1.0f && code[1] == 1.0f && code[3] == 0.0f,
        "16 bit pulses");
  CHECK(lpd_acelp_icb_bits(0) == 20 && lpd_acelp_icb_bits(5) == 64 && lpd_acelp_icb_bits(6) == 12,
        "icb bits table");
}

static void test_pitch() {
  AacUsacLpdChannel* c = aac_usac_lpd_channel_create(1024, 12000);
  CHECK(c != nullptr, "channel");
  if (!c) {
    return;
  }
  printf("pitch range at 12 kHz: min %d fr2 %d fr1 %d max %d\n", c->pit_min, c->pit_fr2,
         c->pit_fr1, c->pit_max);
  /* the 12.8 kHz range 34 .. 231 scaled to the core rate */
  CHECK(c->pit_min == 32 && c->pit_fr2 == 130 && c->pit_fr1 == 160 && c->pit_max == 219,
        "pitch range at 12 kHz");
  float last = -1.0f;
  int t0, frac, t0_min = 0, t0_max = 0;
  for (int idx = 0; idx < 512; idx++) {
    lpd_acelp_pitch(c, idx, 1, &t0, &frac, &t0_min, &t0_max);
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
  lpd_acelp_pitch(c, 4 * (c->pit_fr2 - c->pit_min) - 1, 1, &t0, &frac, &t0_min, &t0_max);
  CHECK(t0 == c->pit_fr2 - 1 && frac == 3, "last quarter resolution lag");
  lpd_acelp_pitch(c, 200, 1, &t0, &frac, &t0_min, &t0_max);
  const int base = t0;
  for (int idx = 0; idx < 64; idx++) {
    int t1, f1;
    lpd_acelp_pitch(c, idx, 0, &t1, &f1, &t0_min, &t0_max);
    CHECK(t1 == t0_min + idx / 4 && f1 == idx % 4, "6 bit lag at %d", idx);
  }
  CHECK(t0_min <= base && base <= t0_max, "relative range around the lag");
  aac_usac_lpd_channel_destroy(c);
  /* at 24 kHz the range scales: 64 .. 411 */
  c = aac_usac_lpd_channel_create(1024, 24000);
  CHECK(c && c->pit_min == 64 && c->pit_fr2 == 98 && c->pit_max == 411, "pitch range at 24 kHz");
  aac_usac_lpd_channel_destroy(c);
  /* the adaptive codebook interpolation reproduces a shifted sinusoid */
  float exc[LPD_EXC_HIST + 65];
  for (int i = 0; i < LPD_EXC_HIST; i++) {
    exc[i] = sinf(0.1f * (float)i);
  }
  const int lag = 100;
  lpd_acelp_adaptive(exc + LPD_EXC_HIST, lag, 2, 64);
  for (int i = 0; i < 64; i++) {
    const float want = sinf(0.1f * ((float)(LPD_EXC_HIST + i) - (float)lag - 0.5f));
    CHECK(fabsf(exc[LPD_EXC_HIST + i] - want) < 0.02f, "interpolation %d: %f vs %f", i,
          exc[LPD_EXC_HIST + i], want);
  }
}

/* ── forward aliasing cancellation ──────────────────────────────── */

static void test_fac() {
  /* the DCT-IV is its own inverse up to the scale 2 / n */
  for (int n = 64; n <= 128; n += 32) {
    std::vector<float> x((size_t)n), y((size_t)n), z((size_t)n);
    unsigned seed = 9u;
    for (int i = 0; i < n; i++) {
      seed = seed * 1664525u + 1013904223u;
      x[(size_t)i] = (float)(seed >> 8) / 8388608.0f - 1.0f;
    }
    lpd_dct4(x.data(), y.data(), n);
    lpd_dct4(y.data(), z.data(), n);
    double err = 0.0, ref = 0.0;
    for (int i = 0; i < n; i++) {
      const double d = 2.0 / n * z[(size_t)i] - x[(size_t)i];
      err += d * d;
      ref += (double)x[(size_t)i] * x[(size_t)i];
    }
    CHECK(err < 1e-8 * ref, "DCT-IV of %d is an involution (error %.1e)", n, err / ref);
  }
  /* the windows: sine slope power complementary, the FAC windows the
   * standard's products */
  float w[2 * LPD_MAX_LFAC], past_w[LPD_MAX_LFAC], zir_w[LPD_MAX_LFAC];
  const int lfac = 128;
  lpd_sine_window(2 * lfac, w);
  lpd_fac_windows(lfac, past_w, zir_w);
  for (int i = 0; i < lfac; i++) {
    const float a = w[i], b = w[2 * lfac - 1 - i];
    CHECK(fabsf(a * a + b * b - 1.0f) < 1e-5f, "sine slope power at %d", i);
    CHECK(fabsf(past_w[i] - a * b) < 1e-6f && fabsf(zir_w[i] - (1.0f - w[lfac + i] * w[lfac + i])) < 1e-6f,
          "fac windows at %d", i);
  }
  /* the FAC synthesis of zero coefficients is silent; of one
   * coefficient through the trivial filter, the scaled DCT-IV basis */
  float a[LPD_M + 1] = {1.0f};
  float x[LPD_MAX_LFAC] = {0}, out[2 * LPD_MAX_LFAC];
  lpd_fac_synth(x, lfac, a, out, 2 * lfac);
  double e = 0.0;
  for (int i = 0; i < 2 * lfac; i++) {
    e += fabs(out[i]);
  }
  CHECK(e == 0.0, "fac synth of zero is silent");
  x[3] = 1.0f;
  lpd_fac_synth(x, lfac, a, out, 2 * lfac);
  CHECK(fabsf(out[0] - 2.0f / lfac * cosf((float)(M_PI / lfac * 0.5 * 3.5))) < 1e-5f &&
            out[lfac + 5] == 0.0f,
        "fac synth basis: %f", out[0]);
}

/* ── core channel contract ──────────────────────────────────────── */

static void test_contract() {
  for (int cfg = 0; cfg < 2; cfg++) {
    const int n = cfg ? 768 : 1024;
    AacUsacCoreChannel ch;
    memset(&ch, 0, sizeof(ch));
    ch.prev_core_mode = -1;
    ch.noise_seed = 0x3039u;
    ch.lpd = aac_usac_lpd_channel_create(n, cfg ? 18000 : 12000);
    CHECK(ch.lpd != nullptr, "create %d", n);
    std::vector<float> out((size_t)n);
    /* concealment before any frame: silence, contract kept */
    aac_usac_lpd_conceal(&ch, n, out.data());
    CHECK(ch.prev_core_mode == 1, "conceal sets prev_core_mode");
    double e = 0.0;
    for (int i = 0; i < n; i++) {
      e += fabs(out[(size_t)i]);
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
          finite &= std::isfinite(out[(size_t)i]) != 0;
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

/* ── the xHE-AAC streams ────────────────────────────────────────── */

using LpdStream = struct LpdStream_ {
  const char* name;   /* the LOAS file */
  const char* expect; /* the core output, 16 bit, one channel, every frame */
  int core_rate;
  int frame_length;
};

/* The core decoder of a stream: the channels of its elements, driven
 * access unit by access unit (the pre-roll frames first) through the
 * frequency domain core, which hands LPD frames to the LPD core; the
 * first core element's first channel is collected. */
struct CoreDecoder {
  AacUsacConfig cfg;
  std::vector<AacUsacCoreChannel*> core;
  std::vector<float> out;
  int frames = 0;
  int errors = 0;
  int lpd_frames = 0;
  bool first = true;

  ~CoreDecoder() {
    for (AacUsacCoreChannel* c : core) {
      aac_usac_fd_channel_destroy(c->fd);
      aac_usac_lpd_channel_destroy(c->lpd);
      delete c;
    }
  }

  bool init() {
    for (int e = 0; e < cfg.num_elements; e++) {
      const AacUsacElementConfig& el = cfg.elem[e];
      int n = 0;
      if (el.type == AAC_USAC_SCE || el.type == AAC_USAC_LFE) {
        n = 1;
      }
      if (el.type == AAC_USAC_CPE) {
        n = el.stereo_config_index == 1 ? 1 : 2;
      }
      for (int i = 0; i < n; i++) {
        AacUsacCoreChannel* c = new AacUsacCoreChannel;
        memset(c, 0, sizeof(*c));
        c->prev_core_mode = -1;
        c->noise_seed = i ? 0x10932u : 0x3039u;
        c->fd = aac_usac_fd_channel_create(cfg.frame_length, cfg.core_sample_rate);
        c->lpd = aac_usac_lpd_channel_create(cfg.frame_length, cfg.core_sample_rate);
        if (!c->fd || !c->lpd) {
          return false;
        }
        core.push_back(c);
      }
    }
    return !core.empty();
  }

  int decode_au(const uint8_t* au, int size) {
    AacBitReader r;
    aac_bitreader_init(&r, au, size);
    const int indep = (int)aac_bitreader_read(&r, 1);
    const int fl = cfg.frame_length;
    std::vector<float> buf((size_t)fl * core.size());
    std::vector<float*> outs;
    for (size_t c = 0; c < core.size(); c++) {
      outs.push_back(buf.data() + c * (size_t)fl);
    }
    for (int e = 0; e < cfg.num_elements; e++) {
      const AacUsacElementConfig& el = cfg.elem[e];
      if (el.type == AAC_USAC_EXT) {
        if (!aac_bitreader_read(&r, 1)) {
          continue;
        }
        const int use_default = (int)aac_bitreader_read(&r, 1);
        const int len = use_default ? el.ext_default_length : aac_usac_ext_payload_length(&r);
        if (el.ext_payload_frag) {
          aac_bitreader_skip(&r, 2);
        }
        if (len * 8 > aac_bitreader_bits_left(&r)) {
          return AAC_ERR_DECODE;
        }
        std::vector<uint8_t> payload((size_t)len);
        for (int i = 0; i < len; i++) {
          payload[(size_t)i] = (uint8_t)aac_bitreader_read(&r, 8);
        }
        if (el.ext_type == AAC_USAC_EXT_AUDIOPREROLL && first) {
          first = false;
          AacUsacPreroll* p = new AacUsacPreroll;
          const int ret = aac_usac_preroll_parse(payload.data(), len, p);
          if (ret == AAC_OK) {
            for (int i = 0; i < p->num_frames; i++) {
              if (decode_au(p->data + p->au_offset[i], p->au_len[i]) != AAC_OK) {
                errors++;
              }
            }
          } else {
            errors++;
          }
          delete p;
        }
        continue;
      }
      const int n = el.type == AAC_USAC_CPE ? (el.stereo_config_index == 1 ? 1 : 2) : 1;
      const int lfe = el.type == AAC_USAC_LFE;
      AacBitReader peek = r;
      const int core_mode = lfe ? 0 : (int)aac_bitreader_read(&peek, 1);
      const int ret = aac_usac_core_decode(&core[0], n, lfe, el.noise_filling, fl, indep, &r,
                                           &outs[0]);
      if (ret != AAC_OK) {
        errors++;
      }
      frames++;
      lpd_frames += core_mode;
      out.insert(out.end(), outs[0], outs[0] + fl);
      return ret; /* the SBR and MPS data of the element follow: not decoded here */
    }
    return AAC_OK;
  }
};

static void test_stream(const LpdStream& st) {
  const std::vector<uint8_t> loas = read_file(data_path(st.name));
  CHECK(!loas.empty(), "%s: read", st.name);
  if (loas.empty()) {
    return;
  }
  CoreDecoder d;
  AacLatmConfig lcfg = {};
  bool have_cfg = false;
  size_t pos = 0;
  while (pos + AAC_LOAS_HEADER_BYTES <= loas.size() &&
         aac_loas_is_sync(loas.data() + pos, (int)(loas.size() - pos))) {
    const int plen = aac_loas_payload_length(loas.data() + pos);
    const uint8_t* payload = loas.data() + pos + AAC_LOAS_HEADER_BYTES;
    if (!have_cfg) {
      /* the StreamMuxConfig of these streams: 16 bits of fixed fields,
       * then the AudioSpecificConfig */
      AacBitReader r;
      aac_bitreader_init(&r, payload + 2, plen - 2);
      AacAsc asc;
      CHECK(aac_asc_read(&r, &asc) == AAC_OK, "%s: config", st.name);
      d.cfg = asc.usac;
      CHECK(d.cfg.core_sample_rate == st.core_rate && d.cfg.frame_length == st.frame_length,
            "%s: core %d Hz, %d lines", st.name, d.cfg.core_sample_rate, d.cfg.frame_length);
      if (!d.init()) {
        CHECK(0, "%s: channels", st.name);
        return;
      }
      have_cfg = true;
    }
    std::vector<uint8_t> au((size_t)plen);
    AacLatmPayload p[AAC_LATM_MAX_SUBFRAMES];
    int used = 0;
    const int n = aac_latm_read_mux_element(payload, plen, 1, &lcfg, au.data(), plen, p,
                                            AAC_LATM_MAX_SUBFRAMES, &used);
    CHECK(n == 1, "%s: one access unit per LOAS frame", st.name);
    if (n != 1) {
      return;
    }
    d.decode_au(au.data() + p[0].offset, p[0].size);
    pos += AAC_LOAS_HEADER_BYTES + (size_t)plen;
  }
  CHECK(d.errors == 0, "%s: %d core frames failed", st.name, d.errors);
  CHECK(d.lpd_frames > 0, "%s: LPD frames", st.name);
  /* the stored expectation: every core frame at 16 bits */
  const std::vector<uint8_t> raw = read_file(data_path(st.expect));
  CHECK(raw.size() == d.out.size() * 2, "%s: expectation of %zu samples, decoded %zu", st.expect,
        raw.size() / 2, d.out.size());
  double err = 0.0, ref = 0.0;
  const size_t count = std::min(raw.size() / 2, d.out.size());
  for (size_t i = 0; i < count; i++) {
    const float e = (float)(int16_t)(raw[2 * i] | (raw[2 * i + 1] << 8)) / 32768.0f;
    err += (double)(d.out[i] - e) * (d.out[i] - e);
    ref += (double)e * e;
  }
  const double snr = ref > 0.0 ? 10.0 * log10(ref / (err > 0.0 ? err : 1e-20)) : 0.0;
  printf("%s: %d core frames (%d LPD), %.1f dB against the stored core output\n", st.name,
         d.frames, d.lpd_frames, snr);
  /* the expectation's 16 bit rounding bounds the match near 80 dB */
  CHECK(snr > 70.0, "%s: core output %.1f dB", st.name, snr);
}

int main() {
  test_modes();
  test_lsp_lpc();
  test_avq();
  test_acelp_pulses();
  test_pitch();
  test_fac();
  test_contract();
  const LpdStream streams[6] = {
      {"usac_lpd_speech_12000.loas", "usac_lpd_speech_12000_core.s16", 12000, 1024},
      {"usac_speech_48000_16000.loas", "usac_lpd_speech_48000_16000_core.s16", 12000, 1024},
      {"usac_lpd_speech_24000.loas", "usac_lpd_speech_24000_core.s16", 24000, 1024},
      {"usac_speech_48000_24000.loas", "usac_lpd_speech_48000_24000_core.s16", 24000, 1024},
      {"usac_music_48000_24000.loas", "usac_lpd_music_48000_24000_core.s16", 18000, 768},
      {"usac_music_48000_16000.loas", "usac_lpd_music_48000_16000_core.s16", 12000, 1024},
  };
  for (const LpdStream& s : streams) {
    test_stream(s);
  }
  return test_summary("test_usac_lpd");
}
