#include "mps212.h"

#include <cmath>
#include <cstdlib>
#include <cstring>

#include "qmf.h"

/* MPEG Surround 212 (ISO/IEC 23003-3 7.11 over the one-to-two box of
 * ISO/IEC 23003-1).
 *
 * Bitstream (Mps212Data()):
 *
 *   with bsHighRateMode bsFramingInfo(): bsFramingType (1), bsNumParamSets
 *       (3) -> 1..8 sets; with bsFramingType 1 one bsParamSlot per set of
 *       ceil(log2(slots)) bits, with 0 the sets sit at the fixed positions
 *       ceil(slots (i + 1) / sets) - 1; without bsHighRateMode one set at
 *       the last slot.
 *   bsIndependencyFlag (1) unless usacIndependencyFlag implies it.
 *   OttData(): EcData(CLD), EcData(ICC); with bsPhaseCoding bsPhaseMode (1)
 *       and, when set, bsOpdSmoothingMode (1) and EcData(IPD) over the
 *       IPD bands (bsOttBandsPhase, else half the parameter bands, at
 *       least the residual bands).
 *   with bsHighRateMode SmgData(): per set bsSmoothMode (2); mode 2 and 3
 *       add bsSmoothTime (2); mode 3 adds bsFreqResStrideSmg (2) and one
 *       bsSmgData bit per band group.
 *   TempShapeData(): bsTempShapeConfig 3: bsTsdEnable (1); 1 and 2:
 *       bsTempShapeEnable (1) and, when set, one bsEnvShapeChannel bit
 *       per output channel, with config 2 followed by the run-length
 *       Huffman coded envelope of each flagged channel.  With bsTsdEnable
 *       bsTsdNumTrSlots (4 or 5 bits), bsTsdCodedPos (the combinatorial
 *       index of the transient slot set) and 3 bits of bsTsdTrPhaseData
 *       per transient slot.
 *
 *   EcData(type): bsDataMode (2) per set (0 default, 1 keep, 2 interpolate,
 *   3 read).  For each read set (or pair of read sets): bsDataPair (1),
 *   bsQuantCoarse (1), bsFreqResStride (2) and EcDataPair(): bsPCMCoding
 *   (1); PCM packs groups of values into fixed-width words; Huffman codes
 *   the differences in frequency or time (bsDiffType per set), either one
 *   value at a time (magnitude and sign) or as pairs (bsCodingScheme):
 *   pairs along frequency or between the two sets, each pair with a LAV
 *   class, the pair codebook, the symmetry bits and an escape to PCM.
 *   Fine IPD values carry their lowest bit separately after the codes.
 *
 * Processing: the downmix (gain restored) and the residual (analysed with
 * the core band QMF of 32, 24 or 16 bands, delayed to the downmix's slot
 * timing) pass the hybrid filterbank (71 bands; the ten lowest lag by six
 * slots, the others keep their timing); the decorrelator (pre-delay and a
 * lattice all-pass filter per reverb band, energy adjusted per parameter
 * band) makes the diffuse signal, replaced by the residual in the residual
 * bands; the mixing matrices per parameter band are interpolated between
 * parameter sets per slot and applied (with the complex prediction
 * weights of the residual mode or the phases of the IPD/OPD mode); STP or
 * GES shape the diffuse part; the hybrid synthesis sums the bands back. */

namespace {

constexpr int kMaxPb = AAC_MPS212_MAX_PARAM_BANDS;
constexpr int kHyb = AAC_MPS212_HYBRID_BANDS;
constexpr int kMaxSlots = AAC_USAC_MAX_QMF_SLOTS;
constexpr int kMaxSets = AAC_MPS212_MAX_PARAM_SETS;
constexpr int kProto = 13;
constexpr int kHybDelay = (kProto - 1) / 2;
constexpr int kLfBands = 3;         /* QMF bands split into hybrid bands */
constexpr int kLfHybBands = 10;     /* hybrid bands of the split */
constexpr int kMaxOrder = 10;
constexpr int kMaxDecorrDelay = 11;
constexpr int kResRing = 2 * kMaxSlots;
constexpr float kAbsThr = 1e-9f * 32768.0f * 32768.0f;
constexpr float kPi = 3.14159265358979f;

enum { kCld = 0, kIcc = 1, kIpd = 2 };
enum { kDiffFreq = 0, kDiffTime = 1 };
enum { kPairFreq = 0, kPairTime = 1 };
enum { kBackwards = 0, kForwards = 1 };

/* The parameters of one type in transit: the coded sets, the coded
 * history (on the coded set's quantiser scale) and the mapped indices. */
struct TypeData {
  int mode[kMaxSets];        /* bsDataMode per parameter set */
  int coarse[kMaxSets];      /* bsQuantCoarse per coded set */
  int stride_idx[kMaxSets];  /* bsFreqResStride per coded set */
  int cmp[kMaxSets][kMaxPb]; /* coded values per coded set */
  int last[kMaxPb];          /* the coded history */
  int coarse_prev;
  int idx[kMaxSets][kMaxPb]; /* mapped fine indices per parameter set */
  int idx_prev[kMaxPb];
};

struct Decorr {
  int reverb_band[kHyb];
  float delay[kHyb][kMaxDecorrDelay][2];
  int delay_pos[kHyb];
  float state[kHyb][kMaxOrder + 1][2];
  float e_in[kMaxPb], e_out[kMaxPb];
};

struct Stp {
  float nrg_dir, nrg_diff[2], nrg_dir_prev, nrg_diff_prev[2], scale_last[2];
  int update_counter;
};

struct Ges {
  float pb_energy_prev[3][kMaxPb];
  float avg_energy_prev[3];
  float frame_energy_prev[3];
};

}  // namespace

struct AacMps212State_ {
  AacUsacMps212Config cfg;
  int stereo_config_index;
  int frame_length, output_frame_length, sample_rate;
  int slots, slot_bits, rate;
  int num_bands, num_ipd_bands, res_bands;
  int phase_coding; /* bsPhaseCoding, 2 with residual coding (prediction mode) */
  int residual_qmf_bands, residual_hyb_bands, residual_delay;
  int low_ahead; /* the three lowest downmix bands arrive six slots ahead (see hybrid_push) */
  const uint8_t* kernel;
  float gain;

  /* ── bitstream data ── */
  int num_sets; /* coded sets plus the frame-end set when added */
  int param_slot[kMaxSets];
  int param_diff[kMaxSets];
  int independency;
  TypeData td[3];
  int smooth_mode[kMaxSets], smooth_time_idx[kMaxSets], smooth_stride_idx[kMaxSets];
  int smg_bits[kMaxSets][kMaxPb];
  int smoothing[kMaxSets][kMaxPb];
  float inv_smooth_time[kMaxSets];
  int prev_smg[kMaxPb];
  float prev_inv_smooth_time;
  int phase_mode, opd_smoothing;
  int temp_shape_enable;
  int stp_channel[2], ges_channel[2];
  float env_shape[2][kMaxSlots];
  int tsd_enable;
  int tsd_transient[kMaxSlots];
  int tsd_phase[kMaxSlots];

  /* ── mixing matrices: [set][band][row][col][re/im] on the direct
   * signals (downmix, residual) and [set][band][row][re/im] on the
   * diffuse signal ── */
  float m_res[kMaxSets][kMaxPb][2][2][2];
  float m_dec[kMaxSets][kMaxPb][2][2];
  float m_res_prev[kMaxPb][2][2][2];
  float m_dec_prev[kMaxPb][2][2];
  float phase_l[kMaxSets][kMaxPb], phase_r[kMaxSets][kMaxPb];
  float phase_l_prev[kMaxPb], phase_r_prev[kMaxPb];
  float opd_smooth_l[kMaxPb], opd_smooth_r[kMaxPb];
  int num_sets_prev;

  /* ── per-slot trajectories: entry n belongs to parameter slot n, the
   * hybrid slot the parameter grid sits on ── */
  float r_res[kMaxSlots][kMaxPb][2][2][2];
  float r_dec[kMaxSlots][kMaxPb][2][2];
  float ges_gain[2][kMaxSlots]; /* 0 where the envelope is not shaped */
  int stp_on[2][kMaxSlots];
  int tsd_on[kMaxSlots];
  int tsd_ph[kMaxSlots];

  /* ── signal state ── */
  float lf_hist[kProto][64][2]; /* the last 13 QMF slots, oldest first */
  float res_lf_hist[kProto][64][2];
  AacQmfAnalysis res_qmf;
  float res_ring[kResRing][64][2];
  int res_ring_pos;
  Decorr decorr;
  Stp stp;
  Ges ges;
  float v[kMaxSlots][kHyb][2];      /* hybrid downmix */
  float w_res[kMaxSlots][kHyb][2];  /* hybrid residual in its bands */
  float w_diff[kMaxSlots][kHyb][2]; /* diffuse signal */
  float dir_out[2][kMaxSlots][kHyb][2];
  float diff_out[2][kMaxSlots][kHyb][2];
};

namespace {

/* ── Huffman reading ────────────────────────────────────────────── */

int huff_read(AacBitReader* r, const int16_t (*nodes)[2], int count) {
  int node = 0;
  for (int i = 0; i < 64; i++) {
    if (node < 0 || node >= count) {
      return -2;
    }
    const int next = nodes[node][aac_bitreader_read(r, 1)];
    if (next <= 0) {
      return -next - 1;
    }
    node = next;
  }
  return -2;
}

const int16_t (*first_book(int type, int* count))[2] {
  switch (type) {
    case kCld:
      *count = 30;
      return aac_mps212_huff_cld_first;
    case kIcc:
      *count = 7;
      return aac_mps212_huff_icc_first;
    default:
      *count = 7;
      return aac_mps212_huff_ipd_first;
  }
}

const int16_t (*book_1d(int type, int diff, int* count))[2] {
  switch (type) {
    case kCld:
      *count = 30;
      return aac_mps212_huff_cld_1d[diff];
    case kIcc:
      *count = 7;
      return aac_mps212_huff_icc_1d[diff];
    default:
      *count = 7;
      return aac_mps212_huff_ipd_1d[diff];
  }
}

const int16_t (*book_2d(int type, int diff, int pairing, int lav, int* count))[2] {
  if (type == kCld) {
    switch (lav) {
      case 3:
        *count = 15;
        return aac_mps212_huff_cld_2d_lav3[diff][pairing];
      case 5:
        *count = 35;
        return aac_mps212_huff_cld_2d_lav5[diff][pairing];
      case 7:
        *count = 63;
        return aac_mps212_huff_cld_2d_lav7[diff][pairing];
      default:
        *count = 99;
        return aac_mps212_huff_cld_2d_lav9[diff][pairing];
    }
  }
  if (type == kIcc) {
    switch (lav) {
      case 1:
        *count = 3;
        return aac_mps212_huff_icc_2d_lav1[diff][pairing];
      case 3:
        *count = 15;
        return aac_mps212_huff_icc_2d_lav3[diff][pairing];
      case 5:
        *count = 35;
        return aac_mps212_huff_icc_2d_lav5[diff][pairing];
      default:
        *count = 63;
        return aac_mps212_huff_icc_2d_lav7[diff][pairing];
    }
  }
  switch (lav) {
    case 1:
      *count = 3;
      return aac_mps212_huff_ipd_2d_lav1[diff][pairing];
    case 3:
      *count = 15;
      return aac_mps212_huff_ipd_2d_lav3[diff][pairing];
    case 5:
      *count = 35;
      return aac_mps212_huff_ipd_2d_lav5[diff][pairing];
    default:
      *count = 63;
      return aac_mps212_huff_ipd_2d_lav7[diff][pairing];
  }
}

/* ── EcDataPair(): the non-linear coding of one or two sets ─────── */

struct EcReader {
  AacBitReader* r;
  int type;
  int bands;       /* coded values per set */
  int levels;      /* quantiser levels */
  int offset;      /* index of the lowest level */
  int lsb;         /* 1 when the fine IPD's lowest bit is coded apart */
  int err;
};

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

/* PCM coded values in groups: `num` values into out1 (and out2 when both
 * are given, alternating), minus `offset`. */
void pcm_decode(EcReader* c, int* out1, int* out2, int offset, int num, int levels) {
  int group;
  switch (levels) {
    case 3:
      group = 5;
      break;
    case 7:
      group = 6;
      break;
    case 11:
      group = 2;
      break;
    case 13:
    case 19:
      group = 4;
      break;
    case 25:
      group = 3;
      break;
    default:
      group = 1;
      break;
  }
  for (int i = 0; i < num; i += group) {
    const int n = num - i < group ? num - i : group;
    uint32_t word = aac_bitreader_read(c->r, bits_for(levels, n));
    for (int j = 0; j < n; j++) {
      const int idx = i + (n - j - 1);
      const int v = (int)(word % (uint32_t)levels) - offset;
      word /= (uint32_t)levels;
      if (out2 == NULL) {
        out1[idx] = v;
      } else if (out1 == NULL) {
        out2[idx] = v;
      } else if (idx & 1) {
        out2[idx / 2] = v;
      } else {
        out1[idx / 2] = v;
      }
    }
  }
}

/* One-dimensional codes: `p0` reads the first value with its own book,
 * the rest are magnitudes with a sign bit (CLD, ICC) or IPD differences. */
void dec_1d(EcReader* c, int diff, int* out, int num, int p0) {
  int i = 0;
  if (p0) {
    int count;
    const int16_t (*book)[2] = first_book(c->type, &count);
    const int sym = huff_read(c->r, book, count);
    if (sym < 0) {
      c->err = 1;
      return;
    }
    out[0] = sym;
    i = 1;
  }
  int count;
  const int16_t (*book)[2] = book_1d(c->type, diff, &count);
  for (; i < num; i++) {
    int v = huff_read(c->r, book, count);
    if (v < 0) {
      c->err = 1;
      return;
    }
    if (c->type != kIpd && v != 0 && aac_bitreader_read(c->r, 1)) {
      v = -v;
    }
    out[i] = v;
  }
}

/* Two-dimensional codes over `num` values at `stride` into pairs[i]:
 * the LAV class, the first values, the pair codes with their symmetry
 * bits, and the escaped pairs in PCM at the end. */
void dec_2d(EcReader* c, int diff, int pairing, int (*pairs)[2], int num, int stride, int* p0a,
            int* p0b) {
  const int lav_sym = huff_read(c->r, aac_mps212_huff_lav, 3);
  if (lav_sym < 0) {
    c->err = 1;
    return;
  }
  int lav;
  if (c->type == kCld) {
    lav = 2 * lav_sym + 3;
  } else if (c->type == kIcc) {
    lav = 2 * lav_sym + 1;
  } else {
    lav = lav_sym == 0 ? 7 : 2 * (lav_sym - 1) + 1;
  }
  int count;
  const int16_t (*first)[2] = first_book(c->type, &count);
  if (p0a) {
    const int sym = huff_read(c->r, first, count);
    if (sym < 0) {
      c->err = 1;
      return;
    }
    *p0a = sym;
  }
  if (p0b) {
    const int sym = huff_read(c->r, first, count);
    if (sym < 0) {
      c->err = 1;
      return;
    }
    *p0b = sym;
  }
  const int16_t (*book)[2] = book_2d(c->type, diff, pairing, lav, &count);
  int esc_idx[kMaxPb];
  int esc = 0;
  for (int i = 0; i < num; i += stride) {
    const int sym = huff_read(c->r, book, count);
    if (sym == -2) {
      c->err = 1;
      return;
    }
    if (sym == -1) {
      if (esc >= kMaxPb) {
        c->err = 1;
        return;
      }
      esc_idx[esc++] = i;
      continue;
    }
    int* d = pairs[i];
    d[0] = sym >> 4;
    d[1] = sym & 15;
    const int sum = d[0] + d[1];
    const int dif = d[0] - d[1];
    if (sum > lav) {
      d[0] = -sum + (2 * lav + 1);
      d[1] = -dif;
    } else {
      d[0] = sum;
      d[1] = dif;
    }
    if (c->type != kIpd && d[0] + d[1] != 0 && aac_bitreader_read(c->r, 1)) {
      d[0] = -d[0];
      d[1] = -d[1];
    }
    if (d[0] - d[1] != 0 && aac_bitreader_read(c->r, 1)) {
      const int t = d[0];
      d[0] = d[1];
      d[1] = t;
    }
  }
  if (esc > 0) {
    int ex[kMaxPb], ey[kMaxPb];
    pcm_decode(c, ex, ey, 0, 2 * esc, 2 * lav + 1);
    for (int i = 0; i < esc; i++) {
      pairs[esc_idx[i]][0] = ex[i] - lav;
      pairs[esc_idx[i]][1] = ey[i] - lav;
    }
  }
}

/* The Huffman part of EcDataPair(): the differences of set 1 (and set 2)
 * with bsCodingScheme and bsPairing; returns the pairing. */
int huff_decode(EcReader* c, int* out1, int* out2, int diff1, int diff2, int* pairing) {
  const int num = c->bands;
  const int scheme = (int)aac_bitreader_read(c->r, 1);
  *pairing = kPairFreq;
  if (scheme && out2 != NULL) {
    *pairing = (int)aac_bitreader_read(c->r, 1);
  }
  int pairs[kMaxPb + 1][2];
  if (!scheme) {
    dec_1d(c, diff1, out1, num, diff1 == kDiffFreq);
    if (out2 && !c->err) {
      dec_1d(c, diff2, out2, num, diff2 == kDiffFreq);
    }
    return 0;
  }
  if (*pairing == kPairFreq) {
    int* outs[2] = {out1, out2};
    const int diffs[2] = {diff1, diff2};
    for (int k = 0; k < 2 && !c->err; k++) {
      if (outs[k] == NULL) {
        continue;
      }
      int* o = outs[k];
      int n = num;
      int* p0 = NULL;
      if (diffs[k] == kDiffFreq) {
        p0 = &outs[k][0];
        o++;
        n--;
      }
      const int rest = n & 1;
      n -= rest;
      dec_2d(c, diffs[k], kPairFreq, pairs + k, n, 2, k == 0 ? p0 : NULL, k == 1 ? p0 : NULL);
      if (c->err) {
        return 0;
      }
      if (rest) {
        dec_1d(c, diffs[k], o + n, 1, 0);
      }
      for (int i = 0; i + 1 < n + 1; i += 2) {
        o[i] = pairs[i + k][0];
        o[i + 1] = pairs[i + k][1];
      }
    }
    return 0;
  }
  /* time pairs: the first values of both sets first when one of them is
   * frequency-differential, the pairs then with the time book */
  int* o1 = out1;
  int* o2 = out2;
  int n = num;
  int* p0a = NULL;
  int* p0b = NULL;
  if (diff1 == kDiffFreq || diff2 == kDiffFreq) {
    p0a = &out1[0];
    p0b = &out2[0];
    o1++;
    o2++;
    n--;
  }
  const int diff = (diff1 == kDiffTime || diff2 == kDiffTime) ? kDiffTime : kDiffFreq;
  dec_2d(c, diff, kPairTime, pairs, n, 1, p0a, p0b);
  if (c->err) {
    return 0;
  }
  for (int i = 0; i < n; i++) {
    o1[i] = pairs[i][0];
    o2[i] = pairs[i][1];
  }
  return 0;
}

void diff_freq_decode(const int* diff, int* out, int n) {
  out[0] = diff[0];
  for (int i = 1; i < n; i++) {
    out[i] = out[i - 1] + diff[i];
  }
}

void diff_time_decode(const int* prev, const int* diff, int* out, int mixed, int n, int sign) {
  int i = 0;
  if (mixed) {
    out[0] = diff[0];
    i = 1;
  }
  for (; i < n; i++) {
    out[i] = prev[i] + sign * diff[i];
  }
}

/* EcDataPair() of `pair ? 2 : 1` sets of `bands` values (set index
 * `set`), `history` being the coded values of the reference set on the
 * same quantiser scale; `time_ref` says a time difference to it is
 * allowed. */
int ec_data_pair(AacBitReader* r, int type, int (*out)[kMaxPb], int set, int bands, int pair,
                 int coarse, int time_ref, const int* history) {
  EcReader c;
  c.r = r;
  c.type = type;
  c.bands = bands;
  c.err = 0;
  c.lsb = 0;
  switch (type) {
    case kCld:
      c.levels = coarse ? 15 : 31;
      c.offset = coarse ? 7 : 15;
      break;
    case kIcc:
      c.levels = coarse ? 4 : 8;
      c.offset = 0;
      break;
    default:
      c.levels = coarse ? 8 : 16;
      c.offset = 0;
      c.lsb = coarse ? 0 : 1;
      break;
  }
  if (bands <= 0) {
    return -1;
  }
  int data[2][kMaxPb];
  const int pcm = (int)aac_bitreader_read(r, 1);
  if (pcm) {
    pcm_decode(&c, data[0], pair ? data[1] : NULL, c.offset, pair ? 2 * bands : bands, c.levels);
  } else {
    int diff[2][kMaxPb + 1];
    int diff_type[2] = {kDiffFreq, kDiffFreq};
    if (pair || time_ref) {
      diff_type[0] = (int)aac_bitreader_read(r, 1);
    }
    if (pair && (diff_type[0] == kDiffFreq || time_ref)) {
      diff_type[1] = (int)aac_bitreader_read(r, 1);
    }
    int pairing;
    huff_decode(&c, diff[0], pair ? diff[1] : NULL, diff_type[0], diff_type[1], &pairing);
    if (c.err) {
      return -1;
    }
    int direction = kBackwards;
    if (pair && (diff_type[0] == kDiffTime || diff_type[1] == kDiffTime)) {
      if (diff_type[0] == kDiffTime && !time_ref) {
        direction = kForwards;
      } else if (diff_type[1] == kDiffTime) {
        direction = kBackwards;
      } else {
        direction = (int)aac_bitreader_read(r, 1);
      }
    }
    const int mixed = diff_type[0] != diff_type[1] && pairing == kPairTime;
    if (direction == kBackwards) {
      if (diff_type[0] == kDiffFreq) {
        diff_freq_decode(diff[0], data[0], bands);
      } else {
        int ref[kMaxPb];
        for (int i = 0; i < bands; i++) {
          ref[i] = (history[i] + c.offset) >> c.lsb;
        }
        diff_time_decode(ref, diff[0], data[0], mixed, bands, 1);
      }
      if (pair) {
        if (diff_type[1] == kDiffFreq) {
          diff_freq_decode(diff[1], data[1], bands);
        } else {
          diff_time_decode(data[0], diff[1], data[1], mixed, bands, 1);
        }
      }
    } else {
      diff_freq_decode(diff[1], data[1], bands);
      if (diff_type[0] == kDiffFreq) {
        diff_freq_decode(diff[0], data[0], bands);
      } else {
        diff_time_decode(data[1], diff[0], data[0], mixed, bands, -1);
      }
    }
    for (int j = 0; j < (pair ? 2 : 1); j++) {
      for (int i = 0; i < bands; i++) {
        int v = data[j][i];
        if (c.lsb) {
          v = (v << 1) | (int)aac_bitreader_read(r, 1);
        }
        data[j][i] = v - c.offset;
      }
    }
  }
  memcpy(out[set], data[0], sizeof(int) * (size_t)bands);
  if (pair) {
    memcpy(out[set + 1], data[1], sizeof(int) * (size_t)bands);
  }
  return 0;
}

/* ── EcData() and the mapping to parameter bands ────────────────── */

int band_map(int bands, int stride, int* map) {
  int groups = (bands - 1) / stride + 1;
  if (groups < 1) {
    groups = 1;
  }
  int width[kMaxPb + 1];
  for (int i = 0; i < groups; i++) {
    width[i] = stride;
  }
  int diff = bands - groups * stride;
  const int incr = diff > 0 ? -1 : 1;
  int k = diff > 0 ? groups - 1 : 0;
  while (diff != 0) {
    width[k] -= incr;
    k += incr;
    diff += incr;
    if (k >= groups) {
      k = diff > 0 ? groups - 1 : 0;
    }
  }
  map[0] = 0;
  for (int i = 0; i < groups; i++) {
    map[i + 1] = map[i] + width[i];
  }
  return groups;
}

void coarse_to_fine(int type, int* v, int n) {
  for (int i = 0; i < n; i++) {
    v[i] <<= 1;
    if (type == kCld) {
      if (v[i] == -14) v[i] = -15;
      if (v[i] == 14) v[i] = 15;
    }
  }
}

void fine_to_coarse(int* v, int n) {
  for (int i = 0; i < n; i++) {
    v[i] /= 2;
  }
}

int read_ecdata(AacMps212State* s, AacBitReader* r, int type) {
  TypeData* t = &s->td[type];
  const int bands = type == kIpd ? s->num_ipd_bands : s->num_bands;
  for (int i = 0; i < s->num_sets; i++) {
    t->mode[i] = (int)aac_bitreader_read(r, 2);
  }
  int set = 0;
  int pair = 0;
  int old_coarse = t->coarse_prev;
  for (int i = 0; i < s->num_sets; i++) {
    if (t->mode[i] == 0) {
      memset(t->last, 0, sizeof(int) * (size_t)bands);
      old_coarse = 0;
    }
    if (t->mode[i] != 3) {
      continue;
    }
    if (pair) {
      pair = 0;
      continue;
    }
    pair = (int)aac_bitreader_read(r, 1);
    const int coarse = (int)aac_bitreader_read(r, 1);
    const int stride_idx = (int)aac_bitreader_read(r, 2);
    if (set + pair >= kMaxSets - 1) {
      return -1;
    }
    t->coarse[set] = coarse;
    t->stride_idx[set] = stride_idx;
    if (coarse != old_coarse) {
      if (old_coarse) {
        coarse_to_fine(type, t->last, bands);
      } else {
        fine_to_coarse(t->last, bands);
      }
    }
    int map[kMaxPb + 1];
    const int groups = band_map(bands, aac_mps212_stride[stride_idx], map);
    int history[kMaxPb];
    for (int g = 0; g < groups; g++) {
      history[g] = t->last[map[g]];
    }
    const int time_ref = !(s->independency && i == 0) || set > 0;
    if (ec_data_pair(r, type, t->cmp, set, groups, pair, coarse, time_ref, history)) {
      return -1;
    }
    for (int g = 0; g < groups; g++) {
      int v = t->cmp[set + pair][g];
      if (type == kIpd) {
        v &= coarse ? 7 : 15;
      }
      for (int b = map[g]; b < map[g + 1]; b++) {
        t->last[b] = v;
      }
    }
    old_coarse = coarse;
    if (pair) {
      t->coarse[set + 1] = coarse;
      t->stride_idx[set + 1] = stride_idx;
    }
    set += pair + 1;
  }
  return 0;
}

/* The mapped indices of one type: the coded sets expanded over the
 * parameter bands, the kept and the interpolated sets filled in, the
 * coarse sets on the fine scale. */
int map_type(AacMps212State* s, int type) {
  TypeData* t = &s->td[type];
  const int bands = type == kIpd ? s->num_ipd_bands : s->num_bands;
  int interp[kMaxSets] = {0};
  int coarse_flag[kMaxSets] = {0};
  int set = 0;
  for (int i = 0; i < s->num_sets; i++) {
    switch (t->mode[i]) {
      case 0:
        memset(t->idx[i], 0, sizeof(int) * (size_t)bands);
        memset(t->idx_prev, 0, sizeof(int) * (size_t)bands);
        t->coarse_prev = 0;
        break;
      case 1:
      case 2:
        memcpy(t->idx[i], t->idx_prev, sizeof(int) * (size_t)bands);
        coarse_flag[i] = t->coarse_prev;
        interp[i] = t->mode[i] == 2;
        break;
      default: {
        int map[kMaxPb + 1];
        const int groups = band_map(bands, aac_mps212_stride[t->stride_idx[set]], map);
        for (int g = 0; g < groups; g++) {
          for (int b = map[g]; b < map[g + 1]; b++) {
            t->idx[i][b] = t->cmp[set][g];
          }
        }
        memcpy(t->idx_prev, t->idx[i], sizeof(int) * (size_t)bands);
        t->coarse_prev = t->coarse[set];
        coarse_flag[i] = t->coarse[set];
        set++;
        break;
      }
    }
  }
  for (int i = 0; i < s->num_sets; i++) {
    if (coarse_flag[i]) {
      coarse_to_fine(type, t->idx[i], bands);
    }
  }
  int i1 = -1;
  for (int i = 0; i < s->num_sets; i++) {
    if (!interp[i]) {
      i1 = i;
      continue;
    }
    int i2 = i;
    while (i2 < s->num_sets && interp[i2]) {
      i2++;
    }
    if (i2 >= s->num_sets) {
      return -1;
    }
    const int x1 = i1 < 0 ? 0 : s->param_slot[i1];
    const int lo = i1 < 0 ? 0 : i1;
    const int xi = s->param_slot[i];
    const int x2 = s->param_slot[i2];
    for (int b = 0; b < bands; b++) {
      int y1 = t->idx[lo][b];
      int y2 = t->idx[i2][b];
      int yi = 0;
      if (type == kIpd) {
        if (y2 - y1 > 8) y1 += 16;
        if (y1 - y2 > 8) y2 += 16;
        if (x2 != x1) yi = (y1 + (xi - x1) * (y2 - y1) / (x2 - x1)) % 16;
      } else if (x2 != x1) {
        yi = y1 + (xi - x1) * (y2 - y1) / (x2 - x1);
      }
      t->idx[i][b] = yi;
    }
  }
  for (int i = 0; i < s->num_sets; i++) {
    for (int b = 0; b < bands; b++) {
      int v = t->idx[i][b];
      if (type == kCld) {
        v = v < -15 ? -15 : (v > 15 ? 15 : v);
      } else if (type == kIcc) {
        v = v < 0 ? 0 : (v > 7 ? 7 : v);
      }
      t->idx[i][b] = v;
    }
  }
  return 0;
}

void clear_ipd(AacMps212State* s) {
  TypeData* t = &s->td[kIpd];
  memset(t->last, 0, sizeof(t->last));
  memset(t->cmp, 0, sizeof(t->cmp));
  memset(t->idx, 0, sizeof(t->idx));
  memset(t->idx_prev, 0, sizeof(t->idx_prev));
  memset(t->mode, 0, sizeof(t->mode));
}

/* ── SmgData() and TempShapeData() ──────────────────────────────── */

int read_smoothing(AacMps212State* s, AacBitReader* r) {
  for (int ps = 0; ps < s->num_sets; ps++) {
    s->smooth_mode[ps] = (int)aac_bitreader_read(r, 2);
    if (s->smooth_mode[ps] >= 2) {
      s->smooth_time_idx[ps] = (int)aac_bitreader_read(r, 2);
    }
    if (s->smooth_mode[ps] == 3) {
      s->smooth_stride_idx[ps] = (int)aac_bitreader_read(r, 2);
      const int groups = (s->num_bands - 1) / aac_mps212_stride[s->smooth_stride_idx[ps]] + 1;
      for (int g = 0; g < groups; g++) {
        s->smg_bits[ps][g] = (int)aac_bitreader_read(r, 1);
      }
    }
  }
  return 0;
}

/* The smoothing flags and times per set from SmgData(); mode 1 continues
 * the previous set's. */
void map_smoothing(AacMps212State* s) {
  for (int ps = 0; ps < s->num_sets; ps++) {
    switch (s->smooth_mode[ps]) {
      case 0:
        s->inv_smooth_time[ps] = 1.0f / 256.0f;
        memset(s->smoothing[ps], 0, sizeof(s->smoothing[ps]));
        break;
      case 1:
        if (ps > 0) {
          s->inv_smooth_time[ps] = s->inv_smooth_time[ps - 1];
          memcpy(s->smoothing[ps], s->smoothing[ps - 1], sizeof(s->smoothing[ps]));
        } else {
          s->inv_smooth_time[ps] = s->prev_inv_smooth_time;
          memcpy(s->smoothing[ps], s->prev_smg, sizeof(s->smoothing[ps]));
        }
        break;
      case 2:
        s->inv_smooth_time[ps] = 1.0f / (float)aac_mps212_smoothing_time[s->smooth_time_idx[ps]];
        for (int pb = 0; pb < s->num_bands; pb++) {
          s->smoothing[ps][pb] = 1;
        }
        break;
      default: {
        s->inv_smooth_time[ps] = 1.0f / (float)aac_mps212_smoothing_time[s->smooth_time_idx[ps]];
        int map[kMaxPb + 1];
        const int groups =
            band_map(s->num_bands, aac_mps212_stride[s->smooth_stride_idx[ps]], map);
        for (int g = 0; g < groups; g++) {
          for (int pb = map[g]; pb < map[g + 1]; pb++) {
            s->smoothing[ps][pb] = s->smg_bits[ps][g];
          }
        }
        break;
      }
    }
  }
  s->prev_inv_smooth_time = s->inv_smooth_time[s->num_sets - 1];
  memcpy(s->prev_smg, s->smoothing[s->num_sets - 1], sizeof(s->prev_smg));
}

/* Guided envelope shaping: run-length pairs (value, run) of the
 * quantised envelope over the slots, dequantised to the ratio. */
int read_env_shape(AacMps212State* s, AacBitReader* r, int ch) {
  int n = 0;
  while (n < s->slots) {
    const int sym = huff_read(r, aac_mps212_huff_env_shape, 39);
    if (sym == -2) {
      return -1;
    }
    const int value = sym < 0 ? 0 : sym >> 4;
    const int run = sym < 0 ? 2 : (sym & 15) + 1;
    const float ratio = powf(2.0f, (float)value / (float)(s->cfg.env_quant_mode + 2) - 1.0f);
    for (int i = n; i < n + run && i < s->slots; i++) {
      s->env_shape[ch][i] = ratio;
    }
    n += run;
  }
  return 0;
}

uint64_t binomial(int n, int k) {
  if (k < 0 || k > n) {
    return 0;
  }
  uint64_t c = 1;
  for (int i = 1; i <= k; i++) {
    c = c * (uint64_t)(n - k + i) / (uint64_t)i;
  }
  return c;
}

uint64_t read_wide(AacBitReader* r, int bits) {
  uint64_t v = 0;
  while (bits > 0) {
    const int n = bits > 16 ? 16 : bits;
    v = (v << n) | aac_bitreader_read(r, n);
    bits -= n;
  }
  return v;
}

int read_temp_shape(AacMps212State* s, AacBitReader* r) {
  s->temp_shape_enable = 0;
  s->tsd_enable = 0;
  s->stp_channel[0] = s->stp_channel[1] = 0;
  s->ges_channel[0] = s->ges_channel[1] = 0;
  memset(s->tsd_transient, 0, sizeof(s->tsd_transient));
  if (s->cfg.temp_shape_config == 3) {
    s->tsd_enable = (int)aac_bitreader_read(r, 1);
  } else if (s->cfg.temp_shape_config != 0) {
    s->temp_shape_enable = (int)aac_bitreader_read(r, 1);
    if (s->temp_shape_enable) {
      int* flags = s->cfg.temp_shape_config == 1 ? s->stp_channel : s->ges_channel;
      flags[0] = (int)aac_bitreader_read(r, 1);
      flags[1] = (int)aac_bitreader_read(r, 1);
      if (s->cfg.temp_shape_config == 2) {
        for (int ch = 0; ch < 2; ch++) {
          if (flags[ch] && read_env_shape(s, r, ch)) {
            return -1;
          }
        }
      }
    }
  }
  if (!s->tsd_enable) {
    return 0;
  }
  const int count_bits = s->slots == 64 ? 5 : 4;
  const int num_tr = (int)aac_bitreader_read(r, count_bits) + 1;
  const uint8_t* table = s->slots == 64 ? aac_mps212_tsd_bits_64 : aac_mps212_tsd_bits_32;
  if (num_tr > s->slots || num_tr > (s->slots == 64 ? 32 : 16)) {
    return -1;
  }
  uint64_t index = read_wide(r, table[num_tr - 1]);
  /* the combinatorial number system: the set of slots from the index,
   * highest slot first */
  int p = num_tr;
  for (int k = s->slots - 1; k >= 0 && p > 0; k--) {
    if (p > k) {
      for (; k >= 0; k--) {
        s->tsd_transient[k] = 1;
      }
      break;
    }
    const uint64_t c = binomial(k, p);
    if (index >= c) {
      index -= c;
      s->tsd_transient[k] = 1;
      p--;
    }
  }
  for (int i = 0; i < s->slots; i++) {
    if (s->tsd_transient[i]) {
      s->tsd_phase[i] = (int)aac_bitreader_read(r, 3);
    }
  }
  return 0;
}

/* ── the mixing matrices ────────────────────────────────────────── */

double cld_amplitude(int cld_idx) {
  const double ratio = pow(10.0, aac_mps212_cld_db[cld_idx] / 10.0);
  return sqrt(ratio / (1.0 + ratio));
}

/* The parametric one-to-two matrix of ISO/IEC 23003-1 6.5.3.2 for the
 * quantiser indices: amplitude factors from the CLD, the rotation from
 * the ICC. */
void ott_matrix(int cld_idx, int icc_idx, float h[2][2][2]) {
  const int ci = cld_idx + 15;
  const float c1 = cld_amplitude(ci < 0 ? 0 : (ci > 30 ? 30 : ci));
  const float c2 = cld_amplitude(30 - (ci < 0 ? 0 : (ci > 30 ? 30 : ci)));
  const double rho = aac_mps212_icc_value[icc_idx & 7];
  const double alpha = 0.5 * acos(rho);
  const double beta = atan(tan(alpha) * (c2 - c1) / (c1 + c2));
  memset(h, 0, sizeof(float) * 8);
  h[0][0][0] = (float)(c1 * cos(alpha + beta));
  h[0][1][0] = (float)(c1 * sin(alpha + beta));
  h[1][0][0] = (float)(c2 * cos(-alpha + beta));
  h[1][1][0] = (float)(c2 * sin(-alpha + beta));
}

/* The prediction mode weights (ISO/IEC 23003-3 7.11.2.3): the downmix is
 * the energy-normalised sum of the channels (its gain limited to 1.2),
 * the complex prediction of the difference from it follows from the
 * CLD, ICC and IPD, and the diffuse weight carries what the prediction
 * leaves. */
void pred_weights(int cld_idx, int icc_idx, int ipd_idx, int has_ipd, float* w, float* re,
                  float* im, float* beta) {
  const int ci = cld_idx + 15;
  const double c1 = cld_amplitude(ci < 0 ? 0 : (ci > 30 ? 30 : ci));
  const double c2 = cld_amplitude(30 - (ci < 0 ? 0 : (ci > 30 ? 30 : ci)));
  const double rho = aac_mps212_icc_value[icc_idx & 7];
  const double phi = has_ipd ? aac_mps212_ipd_rad[ipd_idx & 15] : 0.0;
  const double cc = c1 * c2;
  const double x = 1.0 + 2.0 * rho * cc * cos(phi);
  const double y = 1.0 - 2.0 * rho * cc * cos(phi);
  const double zr = c1 * c1 - c2 * c2;
  const double zi = -2.0 * rho * cc * sin(phi);
  double g = x > 1e-12 ? 1.0 / sqrt(x) : 1.2;
  if (g > 1.2) {
    g = 1.2;
  }
  *w = (float)(0.5 / g);
  *re = (float)(-0.5 / g * zr / x);
  *im = (float)(0.5 / g * zi / x);
  double rest = x * y - (zr * zr + zi * zi);
  if (rest < 0.0) {
    rest = 0.0;
  }
  *beta = (float)(sqrt(rest) / (2.0 * x * g));
}

float wrap_two_pi(float a) {
  while (a < 0.0f) a += 2.0f * kPi;
  while (a >= 2.0f * kPi) a -= 2.0f * kPi;
  return a;
}

float wrap_pi(float a) {
  while (a > kPi) a -= 2.0f * kPi;
  while (a < -kPi) a += 2.0f * kPi;
  return a;
}

/* The matrices of every parameter set from the mapped indices. */
void set_matrices(AacMps212State* s) {
  for (int ps = 0; ps < s->num_sets; ps++) {
    for (int pb = 0; pb < s->num_bands; pb++) {
      const int cld = s->td[kCld].idx[ps][pb];
      const int icc = s->td[kIcc].idx[ps][pb];
      const int ipd = pb < s->num_ipd_bands ? (s->td[kIpd].idx[ps][pb] & 15) : 0;
      float (*mr)[2][2] = s->m_res[ps][pb];
      float (*md)[2] = s->m_dec[ps][pb];
      memset(mr, 0, sizeof(float) * 8);
      memset(md, 0, sizeof(float) * 4);
      if (s->phase_coding == 2) {
        float w, re, im, beta;
        if (pb < s->num_ipd_bands && cld == 0 && icc == 0 && ipd == 8) {
          w = beta = 0.416666667f;
          re = im = 0.0f;
        } else {
          pred_weights(cld, icc, ipd, pb < s->num_ipd_bands, &w, &re, &im, &beta);
        }
        mr[0][0][0] = w - re;
        mr[0][0][1] = -im;
        mr[1][0][0] = w + re;
        mr[1][0][1] = im;
        if (pb < s->res_bands) {
          mr[0][1][0] = w;
          mr[1][1][0] = -w;
        } else {
          md[0][0] = beta;
          md[1][0] = -beta;
        }
      } else {
        float h[2][2][2];
        ott_matrix(cld, icc, h);
        mr[0][0][0] = h[0][0][0];
        mr[1][0][0] = h[1][0][0];
        md[0][0] = h[0][1][0];
        md[1][0] = h[1][1][0];
      }
      float pl = 0.0f, pr = 0.0f;
      if (s->phase_coding == 1 && s->phase_mode && pb < s->num_ipd_bands) {
        const int ci = cld + 15;
        float opd = 0.0f;
        if (!(ci == 15 && ipd == 8)) {
          opd = (float)aac_mps212_opd_q28[ipd][icc & 7][ci] / 268435456.0f;
        }
        pl = wrap_two_pi(opd);
        pr = wrap_two_pi(opd - aac_mps212_ipd_rad[ipd]);
      }
      s->phase_l[ps][pb] = pl;
      s->phase_r[ps][pb] = pr;
    }
  }
  if (s->phase_coding != 1) {
    return;
  }
  /* OPD smoothing: the phases follow a first-order smoother over the
   * slots unless the smoothed phase difference leaves the quantiser step */
  if (!s->opd_smoothing) {
    for (int pb = 0; pb < s->num_bands; pb++) {
      s->opd_smooth_l[pb] = s->phase_l[s->num_sets - 1][pb];
      s->opd_smooth_r[pb] = s->phase_r[s->num_sets - 1][pb];
    }
    return;
  }
  for (int ps = 0; ps < s->num_sets; ps++) {
    const float thr = s->td[kIpd].coarse[ps] ? 50.0f * kPi / 180.0f : 25.0f * kPi / 180.0f;
    const float delta = (float)s->param_diff[ps] / 128.0f;
    for (int pb = 0; pb < s->num_bands; pb++) {
      float l = s->phase_l[ps][pb];
      float r = s->phase_r[ps][pb];
      while (l > s->opd_smooth_l[pb] + kPi) l -= 2.0f * kPi;
      while (l < s->opd_smooth_l[pb] - kPi) l += 2.0f * kPi;
      while (r > s->opd_smooth_r[pb] + kPi) r -= 2.0f * kPi;
      while (r < s->opd_smooth_r[pb] - kPi) r += 2.0f * kPi;
      s->opd_smooth_l[pb] = delta * l + (1.0f - delta) * s->opd_smooth_l[pb];
      s->opd_smooth_r[pb] = delta * r + (1.0f - delta) * s->opd_smooth_r[pb];
      const float d = wrap_pi((l - r) - (s->opd_smooth_l[pb] - s->opd_smooth_r[pb]));
      if (fabsf(d) > thr) {
        s->opd_smooth_l[pb] = l;
        s->opd_smooth_r[pb] = r;
      }
      s->opd_smooth_l[pb] = wrap_two_pi(s->opd_smooth_l[pb]);
      s->opd_smooth_r[pb] = wrap_two_pi(s->opd_smooth_r[pb]);
      s->phase_l[ps][pb] = s->opd_smooth_l[pb];
      s->phase_r[ps][pb] = s->opd_smooth_r[pb];
    }
  }
}

/* First-order smoothing of the matrices in the flagged bands towards
 * the previous set (bsSmoothMode). */
void smooth_matrices(AacMps212State* s) {
  for (int ps = 0; ps < s->num_sets; ps++) {
    const float delta = (float)s->param_diff[ps] * s->inv_smooth_time[ps];
    for (int pb = s->res_bands; pb < s->num_bands; pb++) {
      if (!s->smoothing[ps][pb]) {
        continue;
      }
      float* cur = &s->m_res[ps][pb][0][0][0];
      const float* prev = ps > 0 ? &s->m_res[ps - 1][pb][0][0][0] : &s->m_res_prev[pb][0][0][0];
      for (int i = 0; i < 8; i++) {
        cur[i] = delta * cur[i] + (1.0f - delta) * prev[i];
      }
      float* dc = &s->m_dec[ps][pb][0][0];
      const float* dp = ps > 0 ? &s->m_dec[ps - 1][pb][0][0] : &s->m_dec_prev[pb][0][0];
      for (int i = 0; i < 4; i++) {
        dc[i] = delta * dc[i] + (1.0f - delta) * dp[i];
      }
    }
  }
}

float angle_step(float a1, float a2, float* start, int diff) {
  while (a2 - a1 > kPi) a1 += 2.0f * kPi;
  while (a1 - a2 > kPi) a2 += 2.0f * kPi;
  const float step = (a2 - a1) / (float)diff;
  *start = a1 + step;
  return step;
}

/* Linear interpolation of the matrices over the slots: from the previous
 * frame's last set through the parameter slots, reaching each set at its
 * slot; with the IPD/OPD mode the phases are interpolated as angles and
 * turn the matrices complex. */
void interpolate_matrices(AacMps212State* s) {
  int ts = 0;
  for (int ps = 0; ps < s->num_sets; ps++) {
    const int diff = s->param_diff[ps];
    for (int pb = 0; pb < s->num_bands; pb++) {
      const float* a = ps > 0 ? &s->m_res[ps - 1][pb][0][0][0] : &s->m_res_prev[pb][0][0][0];
      const float* b = &s->m_res[ps][pb][0][0][0];
      const float* da = ps > 0 ? &s->m_dec[ps - 1][pb][0][0] : &s->m_dec_prev[pb][0][0];
      const float* db = &s->m_dec[ps][pb][0][0];
      for (int i = 1; i <= diff; i++) {
        const float t = (float)i / (float)diff;
        float* out = &s->r_res[ts + i - 1][pb][0][0][0];
        for (int k = 0; k < 8; k++) {
          out[k] = a[k] + t * (b[k] - a[k]);
        }
        float* od = &s->r_dec[ts + i - 1][pb][0][0];
        for (int k = 0; k < 4; k++) {
          od[k] = da[k] + t * (db[k] - da[k]);
        }
      }
      if (s->phase_coding == 1) {
        float tl, tr;
        const float sl =
            angle_step(ps > 0 ? s->phase_l[ps - 1][pb] : s->phase_l_prev[pb], s->phase_l[ps][pb],
                       &tl, diff);
        const float sr =
            angle_step(ps > 0 ? s->phase_r[ps - 1][pb] : s->phase_r_prev[pb], s->phase_r[ps][pb],
                       &tr, diff);
        for (int i = 0; i < diff; i++) {
          float (*mr)[2][2] = s->r_res[ts + i][pb];
          float (*md)[2] = s->r_dec[ts + i][pb];
          const float cl = cosf(tl), snl = sinf(tl), cr = cosf(tr), snr = sinf(tr);
          mr[0][0][1] = mr[0][0][0] * snl;
          mr[0][0][0] *= cl;
          mr[1][0][1] = mr[1][0][0] * snr;
          mr[1][0][0] *= cr;
          md[0][1] = md[0][0] * snl;
          md[0][0] *= cl;
          md[1][1] = md[1][0] * snr;
          md[1][0] *= cr;
          tl += sl;
          tr += sr;
        }
      }
    }
    ts += diff;
  }
}

/* ── hybrid filterbank (ISO/IEC 23003-1 6.6.3) ──────────────────── */

float g_f8[8][kProto][2];
int g_hybrid_ready = 0;

void hybrid_tables_init(void) {
  if (g_hybrid_ready) {
    return;
  }
  for (int q = 0; q < 8; q++) {
    for (int n = 0; n < kProto; n++) {
      const double theta = 2.0 * M_PI * (q + 0.5) * (n - kHybDelay) / 8.0;
      g_f8[q][n][0] = (float)(aac_mps212_hybrid_proto8[n] * cos(theta));
      g_f8[q][n][1] = (float)(aac_mps212_hybrid_proto8[n] * -sin(theta));
    }
  }
  g_hybrid_ready = 1;
}

/* The ten lowest hybrid bands from 13 slots of QMF bands 0..2 (oldest
 * first).  QMF band 0 splits into eight; its outputs 0..3 are the in-band
 * quarters in ascending order, 4..7 lie outside the band and fold onto
 * their neighbours: the hybrid order is 6, 7, 0, 1, 2+5, 3+4.  QMF bands
 * 1 and 2 split in two; band 1 holds its content mirrored, so its
 * low-pass output is the upper hybrid band. */
void hybrid_low(const float (*in)[64][2], float (*out)[2]) {
  float t[8][2];
  for (int q = 0; q < 8; q++) {
    float re = 0.0f, im = 0.0f;
    for (int n = 0; n < kProto; n++) {
      const float xr = in[n][0][0], xi = in[n][0][1];
      const float fr = g_f8[q][n][0], fi = g_f8[q][n][1];
      re += fr * xr - fi * xi;
      im += fr * xi + fi * xr;
    }
    t[q][0] = re;
    t[q][1] = im;
  }
  for (int c = 0; c < 2; c++) {
    out[0][c] = t[6][c];
    out[1][c] = t[7][c];
    out[2][c] = t[0][c];
    out[3][c] = t[1][c];
    out[4][c] = t[2][c] + t[5][c];
    out[5][c] = t[3][c] + t[4][c];
  }
  for (int b = 1; b <= 2; b++) {
    float lp[2], hp[2];
    for (int c = 0; c < 2; c++) {
      float odd = 0.0f;
      for (int n = 1; n < kHybDelay; n += 2) {
        odd += aac_mps212_hybrid_proto2[n] * (in[n][b][c] + in[kProto - 1 - n][b][c]);
      }
      const float centre = aac_mps212_hybrid_proto2[kHybDelay] * in[kHybDelay][b][c];
      lp[c] = centre + odd;
      hp[c] = centre - odd;
    }
    const int lo = 6 + 2 * (b - 1);
    for (int c = 0; c < 2; c++) {
      out[b == 1 ? lo + 1 : lo][c] = lp[c];
      out[b == 1 ? lo : lo + 1][c] = hp[c];
    }
  }
}

/* Pushes a slot of `bands` QMF bands into the history and returns the
 * hybrid bands: the lowest ten from the filters over the three split
 * bands, centred six slots back (ISO/IEC 23003-1 6.6.3).  The others
 * are delayed to the same timing, or taken from the slot itself when
 * the split bands arrive six slots ahead (`ahead`). */
void hybrid_push(float (*hist)[64][2], const float (*slot)[2], int bands, int ahead,
                 float (*out)[2]) {
  memmove(hist[0], hist[1], sizeof(hist[0]) * (kProto - 1));
  for (int b = 0; b < 64; b++) {
    hist[kProto - 1][b][0] = b < bands ? slot[b][0] : 0.0f;
    hist[kProto - 1][b][1] = b < bands ? slot[b][1] : 0.0f;
  }
  hybrid_low(hist, out);
  const float (*rest)[2] = hist[ahead ? kProto - 1 : kHybDelay];
  for (int b = kLfBands; b < 64; b++) {
    out[7 + b][0] = rest[b][0];
    out[7 + b][1] = rest[b][1];
  }
}

void hybrid_synthesis(const float (*in)[2], float (*out)[2]) {
  for (int c = 0; c < 2; c++) {
    out[0][c] = in[0][c] + in[1][c] + in[2][c] + in[3][c] + in[4][c] + in[5][c];
    out[1][c] = in[6][c] + in[7][c];
    out[2][c] = in[8][c] + in[9][c];
  }
  for (int b = kLfBands; b < 64; b++) {
    out[b][0] = in[7 + b][0];
    out[b][1] = in[7 + b][1];
  }
}

int qmf_band_of(int hyb) {
  if (hyb < 6) return 0;
  if (hyb < 8) return 1;
  if (hyb < 10) return 2;
  return hyb - 7;
}

/* ── decorrelator (ISO/IEC 23003-1 6.6.2) ───────────────────────── */

void decorr_init(AacMps212State* s) {
  Decorr* d = &s->decorr;
  memset(d, 0, sizeof(*d));
  const int cfg = s->cfg.decorr_config > 2 ? 2 : s->cfg.decorr_config;
  for (int k = 0; k < kHyb; k++) {
    int rb = 0;
    while (rb < 3 && qmf_band_of(k) >= (int)aac_mps212_decorr_split[cfg][rb] - 1) {
      rb++;
    }
    d->reverb_band[k] = rb;
  }
}

/* One slot through the pre-delay and the all-pass filter of each band
 * from `first` up, then the energy adjustment per parameter band of the
 * 28 band scale: the diffuse energy is held within a factor of 1.5 of
 * the direct energy. */
void decorrelate_slot(AacMps212State* s, const float (*in)[2], float (*out)[2], int first) {
  Decorr* d = &s->decorr;
  for (int k = first; k < kHyb; k++) {
    const int rb = d->reverb_band[k];
    const int delay = aac_mps212_decorr_delay[rb];
    const int order = aac_mps212_decorr_order[rb];
    const float* den = aac_mps212_decorr_den[rb];
    float x[2] = {in[k][0], in[k][1]};
    if (delay > 0) {
      const int pos = d->delay_pos[k];
      const float yr = d->delay[k][pos][0], yi = d->delay[k][pos][1];
      d->delay[k][pos][0] = x[0];
      d->delay[k][pos][1] = x[1];
      d->delay_pos[k] = pos + 1 >= delay ? 0 : pos + 1;
      x[0] = yr;
      x[1] = yi;
    }
    /* transposed direct form II; the numerator is the reversed denominator */
    float (*st)[2] = d->state[k];
    float y[2];
    for (int c = 0; c < 2; c++) {
      y[c] = st[0][c] + x[c] * den[order];
    }
    for (int j = 1; j <= order; j++) {
      for (int c = 0; c < 2; c++) {
        st[j - 1][c] = st[j][c] + den[order - j] * x[c] - den[j] * y[c];
      }
    }
    out[k][0] = y[0];
    out[k][1] = y[1];
  }
  const uint8_t* map28 = aac_mps212_kernel(28);
  float e_in[kMaxPb] = {0}, e_out[kMaxPb] = {0};
  for (int k = first; k < kHyb; k++) {
    const int pb = map28[k];
    e_in[pb] += in[k][0] * in[k][0] + in[k][1] * in[k][1];
    e_out[pb] += out[k][0] * out[k][0] + out[k][1] * out[k][1];
  }
  const int first_pb = s->res_bands;
  float gain[kMaxPb];
  for (int pb = first_pb; pb < kMaxPb; pb++) {
    d->e_in[pb] = 0.8f * d->e_in[pb] + 0.2f * e_in[pb];
    d->e_out[pb] = 0.8f * d->e_out[pb] + 0.2f * e_out[pb];
    gain[pb] = 1.0f;
    if (d->e_out[pb] > d->e_in[pb] * 1.5f) {
      gain[pb] = sqrtf(d->e_in[pb] * 1.5f / (d->e_out[pb] + kAbsThr));
    }
    if (d->e_in[pb] > d->e_out[pb] * 1.5f) {
      const float g = sqrtf(d->e_in[pb] / (1.5f * d->e_out[pb] + kAbsThr));
      gain[pb] = g < 2.0f ? g : 2.0f;
    }
  }
  for (int k = first; k < kHyb; k++) {
    const int pb = map28[k];
    if (pb >= first_pb) {
      out[k][0] *= gain[pb];
      out[k][1] *= gain[pb];
    }
  }
}

/* ── temporal shaping ───────────────────────────────────────────── */

constexpr int kTsdFirstBand = 7;
constexpr int kStpBorder = 12;

/* Subband temporal processing (bsTempShapeConfig 1): the diffuse part of
 * a flagged channel follows the direct part's envelope, measured on the
 * hybrid bands 13..31 with band-pass weights and normalised by slowly
 * updated energies. */
void stp_slot(AacMps212State* s, int ts) {
  Stp* p = &s->stp;
  if (p->update_counter == 32) {
    p->update_counter = 1;
    p->nrg_dir_prev = p->nrg_dir;
    p->nrg_diff_prev[0] = p->nrg_diff[0];
    p->nrg_diff_prev[1] = p->nrg_diff[1];
  } else {
    p->update_counter++;
  }
  float dir_energy = 0.0f;
  for (int n = 6; n < 25; n++) {
    const float re = s->dir_out[0][ts][n + 7][0] + s->dir_out[1][ts][n + 7][0];
    const float im = s->dir_out[0][ts][n + 7][1] + s->dir_out[1][ts][n + 7][1];
    const float wgt = aac_mps212_stp_bp[n] * aac_mps212_stp_bp[n] * aac_mps212_stp_gf[n] *
                      aac_mps212_stp_gf[n];
    dir_energy += (re * re + im * im) * wgt;
  }
  p->nrg_dir = 0.95f * p->nrg_dir + 0.05f * dir_energy;
  dir_energy /= p->nrg_dir_prev + kAbsThr;
  float scale[2];
  for (int ch = 0; ch < 2; ch++) {
    float diff_energy = 0.0f;
    for (int n = 6; n < 25; n++) {
      const float re = s->diff_out[ch][ts][n + 7][0];
      const float im = s->diff_out[ch][ts][n + 7][1];
      const float wgt = aac_mps212_stp_bp[n] * aac_mps212_stp_bp[n] * aac_mps212_stp_gf[n] *
                        aac_mps212_stp_gf[n];
      diff_energy += (re * re + im * im) * wgt;
    }
    p->nrg_diff[ch] = 0.95f * p->nrg_diff[ch] + 0.05f * diff_energy;
    diff_energy /= p->nrg_diff_prev[ch] + kAbsThr;
    float sc = sqrtf(dir_energy / (diff_energy + 1e-9f));
    sc = 0.1f + 0.9f * sc;
    if (sc < 1.0f / 2.82f) sc = 1.0f / 2.82f;
    if (sc > 2.82f) sc = 2.82f;
    sc = 0.45f * sc + 0.55f * p->scale_last[ch];
    p->scale_last[ch] = sc;
    scale[ch] = sc;
  }
  for (int ch = 0; ch < 2; ch++) {
    float (*dir)[2] = s->dir_out[ch][ts];
    const float (*dif)[2] = s->diff_out[ch][ts];
    for (int n = 0; n < kHyb; n++) {
      float g = 1.0f;
      if (s->stp_on[ch][ts]) {
        if (n < kStpBorder) {
          g = 1.0f; /* already summed */
        } else if (n < 16) {
          g = scale[ch] * aac_mps212_stp_bp[n - 7];
        } else {
          g = scale[ch];
        }
      }
      dir[n][0] += g * dif[n][0];
      dir[n][1] += g * dif[n][1];
    }
  }
}

/* The normalised envelope of a signal over the slots of the frame
 * (guided envelope shaping): the energy of parameter bands 10..18 of the
 * 20 band scale, whitened by their slow averages, against its own slow
 * average. */
void ges_envelope(AacMps212State* s, int source, int ch, float* env) {
  Ges* g = &s->ges;
  const uint8_t* map20 = aac_mps212_kernel(20);
  const int slot_ch = source == 0 ? ch : 2;
  float pb_energy[kMaxPb];
  for (int pb = 10; pb <= 18; pb++) {
    pb_energy[pb] = g->pb_energy_prev[slot_ch][pb];
  }
  float avg = g->avg_energy_prev[slot_ch];
  for (int ts = 0; ts < s->slots; ts++) {
    float slot_energy[kMaxPb] = {0};
    for (int k = 0; k < kHyb; k++) {
      float re, im;
      if (source == 0) {
        re = s->dir_out[ch][ts][k][0] + s->diff_out[ch][ts][k][0];
        im = s->dir_out[ch][ts][k][1] + s->diff_out[ch][ts][k][1];
      } else {
        re = s->v[ts][k][0];
        im = s->v[ts][k][1];
      }
      slot_energy[map20[k]] += re * re + im * im;
    }
    float total = 0.0f;
    for (int pb = 10; pb <= 18; pb++) {
      pb_energy[pb] = (1.0f - 0.99637864f) * slot_energy[pb] + 0.99637864f * pb_energy[pb];
      total += slot_energy[pb];
    }
    total /= 9.0f;
    total = (1.0f - 0.99637864f) * total + 0.99637864f * g->frame_energy_prev[slot_ch];
    g->frame_energy_prev[slot_ch] = total;
    env[ts] = 0.0f;
    for (int pb = 10; pb <= 18; pb++) {
      env[ts] += slot_energy[pb] * (total / (pb_energy[pb] + kAbsThr));
    }
    avg = (1.0f - 0.9643691f) * env[ts] + 0.9643691f * avg;
    env[ts] = sqrtf(env[ts] / (avg + kAbsThr));
  }
  for (int pb = 10; pb <= 18; pb++) {
    g->pb_energy_prev[slot_ch][pb] = pb_energy[pb];
  }
  g->avg_energy_prev[slot_ch] = avg;
}

/* Guided envelope shaping (bsTempShapeConfig 2): the direct part of a
 * flagged channel above hybrid band 6 is scaled per slot towards the
 * transmitted ratio of its envelope to the downmix envelope. */
void ges_apply(AacMps212State* s) {
  float dmx_env[kMaxSlots], dir_env[kMaxSlots];
  ges_envelope(s, 1, 0, dmx_env);
  for (int ch = 0; ch < 2; ch++) {
    ges_envelope(s, 0, ch, dir_env);
    for (int ts = 0; ts < s->slots; ts++) {
      if (s->ges_gain[ch][ts] == 0.0f) {
        continue;
      }
      const float gain = s->ges_gain[ch][ts] * dmx_env[ts] / (dir_env[ts] + 1e-9f);
      float amp_dir = 0.0f, amp_diff = 0.0f;
      for (int k = 6; k < kHyb; k++) {
        amp_dir += s->dir_out[ch][ts][k][0] * s->dir_out[ch][ts][k][0] +
                   s->dir_out[ch][ts][k][1] * s->dir_out[ch][ts][k][1];
        amp_diff += s->diff_out[ch][ts][k][0] * s->diff_out[ch][ts][k][0] +
                    s->diff_out[ch][ts][k][1] * s->diff_out[ch][ts][k][1];
      }
      const float amp_ratio = sqrtf(amp_diff / (amp_dir + kAbsThr));
      float ratio = gain + amp_ratio * (gain - 1.0f);
      if (ratio < 0.25f) ratio = 0.25f;
      if (ratio > 4.0f) ratio = 4.0f;
      for (int k = 6; k < kHyb; k++) {
        s->dir_out[ch][ts][k][0] *= ratio;
        s->dir_out[ch][ts][k][1] *= ratio;
      }
    }
  }
}

/* ── frame housekeeping ─────────────────────────────────────────── */

int ceil_log2(int n) {
  int b = 0;
  while ((1 << b) < n) {
    b++;
  }
  return b;
}

/* The frame-end set and the slot spans once the sets of a frame are
 * known. */
int finish_sets(AacMps212State* s) {
  if (s->param_slot[s->num_sets - 1] != s->slots - 1) {
    if (s->num_sets >= kMaxSets) {
      return -1;
    }
    const int last = s->num_sets - 1;
    for (int t = 0; t < 3; t++) {
      memcpy(s->td[t].idx[last + 1], s->td[t].idx[last], sizeof(s->td[t].idx[last]));
    }
    s->inv_smooth_time[last + 1] = s->inv_smooth_time[last];
    memcpy(s->smoothing[last + 1], s->smoothing[last], sizeof(s->smoothing[last]));
    s->smooth_mode[last + 1] = s->smooth_mode[last];
    s->num_sets++;
    s->param_slot[s->num_sets - 1] = s->slots - 1;
  }
  s->param_diff[0] = s->param_slot[0] + 1;
  for (int i = 1; i < s->num_sets; i++) {
    s->param_diff[i] = s->param_slot[i] - s->param_slot[i - 1];
    if (s->param_diff[i] <= 0) {
      return -1;
    }
  }
  return 0;
}

void reset_signal_state(AacMps212State* s) {
  memset(s->lf_hist, 0, sizeof(s->lf_hist));
  memset(s->res_lf_hist, 0, sizeof(s->res_lf_hist));
  memset(s->res_ring, 0, sizeof(s->res_ring));
  s->res_ring_pos = 0;
  if (s->residual_qmf_bands > 0) {
    aac_qmf_analysis_init(&s->res_qmf, s->residual_qmf_bands);
  }
  decorr_init(s);
  memset(&s->stp, 0, sizeof(s->stp));
  s->stp.scale_last[0] = s->stp.scale_last[1] = 1.0f;
  s->stp.nrg_dir_prev = 32768.0f * 32768.0f;
  s->stp.nrg_diff_prev[0] = s->stp.nrg_diff_prev[1] = 32768.0f * 32768.0f;
  memset(&s->ges, 0, sizeof(s->ges));
  for (int i = 0; i < 3; i++) {
    s->ges.avg_energy_prev[i] = 32768.0f * 32768.0f;
  }
  memset(s->m_res, 0, sizeof(s->m_res));
  memset(s->m_dec, 0, sizeof(s->m_dec));
  memset(s->phase_l, 0, sizeof(s->phase_l));
  memset(s->phase_r, 0, sizeof(s->phase_r));
  memset(s->m_res_prev, 0, sizeof(s->m_res_prev));
  memset(s->m_dec_prev, 0, sizeof(s->m_dec_prev));
  memset(s->phase_l_prev, 0, sizeof(s->phase_l_prev));
  memset(s->phase_r_prev, 0, sizeof(s->phase_r_prev));
  memset(s->opd_smooth_l, 0, sizeof(s->opd_smooth_l));
  memset(s->opd_smooth_r, 0, sizeof(s->opd_smooth_r));
  memset(s->r_res, 0, sizeof(s->r_res));
  memset(s->r_dec, 0, sizeof(s->r_dec));
  memset(s->ges_gain, 0, sizeof(s->ges_gain));
  memset(s->stp_on, 0, sizeof(s->stp_on));
  memset(s->tsd_on, 0, sizeof(s->tsd_on));
  memset(s->tsd_ph, 0, sizeof(s->tsd_ph));
  s->num_sets_prev = 1;
}

}  // namespace

/* ── public API ─────────────────────────────────────────────────── */

AacMps212State* aac_mps212_create(const AacUsacMps212Config* cfg, int stereo_config_index,
                                  int frame_length, int output_frame_length, int sample_rate) {
  if (cfg == NULL || output_frame_length <= 0 || output_frame_length % 64 != 0 ||
      output_frame_length / 64 > kMaxSlots) {
    return NULL;
  }
  const int num_bands = aac_mps212_freq_res_bands[cfg->freq_res & 7];
  if (num_bands == 0) {
    return NULL;
  }
  AacMps212State* s = (AacMps212State*)calloc(1, sizeof(AacMps212State));
  if (s == NULL) {
    return NULL;
  }
  hybrid_tables_init();
  aac_qmf_tables_init();
  s->cfg = *cfg;
  s->stereo_config_index = stereo_config_index;
  s->frame_length = frame_length;
  s->output_frame_length = output_frame_length;
  s->sample_rate = sample_rate;
  s->slots = output_frame_length / 64;
  s->slot_bits = ceil_log2(s->slots);
  s->rate = s->slots / 16;
  s->num_bands = num_bands;
  s->kernel = aac_mps212_kernel(num_bands);
  s->gain = aac_mps212_fixed_gain[cfg->fixed_gain_dmx & 7];
  s->phase_coding = cfg->phase_coding ? 1 : 0;
  if (cfg->ott_bands_phase_present) {
    s->num_ipd_bands = cfg->ott_bands_phase;
  } else {
    switch (num_bands) {
      case 4:
      case 5:
        s->num_ipd_bands = 2;
        break;
      case 7:
        s->num_ipd_bands = 3;
        break;
      case 10:
        s->num_ipd_bands = 5;
        break;
      case 14:
        s->num_ipd_bands = 7;
        break;
      default:
        s->num_ipd_bands = 10;
        break;
    }
  }
  if (cfg->residual_coding) {
    s->res_bands = cfg->residual_bands;
    if (s->res_bands > num_bands) {
      s->res_bands = num_bands;
    }
    if (s->num_ipd_bands < s->res_bands) {
      s->num_ipd_bands = s->res_bands;
    }
    if (s->phase_coding) {
      s->phase_coding = 2;
    }
    /* the residual's analysis bank: 64 bands times the core frame over
     * the output frame (2:1 -> 32, 8:3 -> 24, 4:1 -> 16 bands) */
    const int bands = frame_length > 0 ? frame_length * 64 / output_frame_length : 0;
    if (bands != 32 && bands != 24 && bands != 16) {
      free(s);
      return NULL;
    }
    s->residual_qmf_bands = bands;
    s->residual_hyb_bands = bands - kLfBands + kLfHybBands;
  }
  if (s->num_ipd_bands > num_bands) {
    s->num_ipd_bands = num_bands;
  }
  /* the downmix of stereoConfigIndex 3 arrives at the core analysis
   * timing (MPS 212 runs before the SBR) in every band; that of 1 and 2
   * at the SBR output timing, three envelope time slots later, with the
   * three lowest bands six slots ahead of the others */
  if (stereo_config_index == 3) {
    s->low_ahead = 0;
    s->residual_delay = 0;
  } else {
    s->low_ahead = 1;
    s->residual_delay = 3 * s->rate;
  }
  aac_mps212_reset(s);
  return s;
}

void aac_mps212_destroy(AacMps212State* s) { free(s); }

void aac_mps212_reset(AacMps212State* s) {
  if (s == NULL) {
    return;
  }
  memset(s->td, 0, sizeof(s->td));
  memset(s->smooth_mode, 0, sizeof(s->smooth_mode));
  memset(s->smoothing, 0, sizeof(s->smoothing));
  memset(s->prev_smg, 0, sizeof(s->prev_smg));
  s->prev_inv_smooth_time = 1.0f / 256.0f;
  s->phase_mode = 0;
  s->opd_smoothing = 0;
  s->temp_shape_enable = 0;
  s->tsd_enable = 0;
  s->stp_channel[0] = s->stp_channel[1] = 0;
  s->ges_channel[0] = s->ges_channel[1] = 0;
  memset(s->env_shape, 0, sizeof(s->env_shape));
  memset(s->tsd_transient, 0, sizeof(s->tsd_transient));
  memset(s->tsd_phase, 0, sizeof(s->tsd_phase));
  s->num_sets = 1;
  s->param_slot[0] = s->slots - 1;
  s->param_diff[0] = s->slots;
  s->independency = 1;
  reset_signal_state(s);
}

void aac_mps212_set_residual_delay(AacMps212State* s, int slots) {
  if (s && slots >= 0 && slots < kResRing) {
    s->residual_delay = slots;
  }
}

int aac_mps212_read(AacMps212State* s, AacBitReader* r, int indep) {
  if (s == NULL || r == NULL) {
    return AAC_ERR_INVALID_ARG;
  }
  /* the parse works on a copy; a damaged frame keeps the state */
  AacMps212State* w = (AacMps212State*)malloc(sizeof(AacMps212State));
  if (w == NULL) {
    return AAC_ERR_MEMORY;
  }
  memcpy(w, s, sizeof(AacMps212State));
  w->num_sets_prev = s->num_sets;
  int ok = 1;
  int framing_type = 0;
  if (w->cfg.high_rate_mode) {
    framing_type = (int)aac_bitreader_read(r, 1);
    w->num_sets = (int)aac_bitreader_read(r, 3) + 1;
  } else {
    w->num_sets = 1;
  }
  int prev_slot = -1;
  for (int i = 0; i < w->num_sets; i++) {
    int slot;
    if (framing_type) {
      slot = (int)aac_bitreader_read(r, w->slot_bits);
    } else {
      slot = (w->slots * (i + 1) + w->num_sets - 1) / w->num_sets - 1;
    }
    if (slot <= prev_slot || slot >= w->slots) {
      ok = 0;
      break;
    }
    w->param_slot[i] = slot;
    prev_slot = slot;
  }
  if (ok) {
    w->independency = indep ? 1 : (int)aac_bitreader_read(r, 1);
    ok = read_ecdata(w, r, kCld) == 0 && read_ecdata(w, r, kIcc) == 0;
  }
  if (ok) {
    w->phase_mode = 0;
    w->opd_smoothing = 0;
    if (w->cfg.phase_coding) {
      w->phase_mode = (int)aac_bitreader_read(r, 1);
      if (w->phase_mode) {
        w->opd_smoothing = (int)aac_bitreader_read(r, 1);
        ok = read_ecdata(w, r, kIpd) == 0;
      } else {
        clear_ipd(w);
      }
    } else {
      clear_ipd(w);
    }
  }
  if (ok && w->cfg.high_rate_mode) {
    ok = read_smoothing(w, r) == 0;
  } else if (ok) {
    memset(w->smooth_mode, 0, sizeof(w->smooth_mode));
  }
  if (ok) {
    ok = read_temp_shape(w, r) == 0;
  }
  if (ok && r->overread) {
    ok = 0;
  }
  if (ok) {
    ok = map_type(w, kCld) == 0 && map_type(w, kIcc) == 0 &&
         (!w->cfg.phase_coding || map_type(w, kIpd) == 0);
  }
  if (ok) {
    map_smoothing(w);
    ok = finish_sets(w) == 0;
  }
  if (!ok) {
    free(w);
    aac_mps212_conceal(s);
    return AAC_ERR_DECODE;
  }
  memcpy(s, w, sizeof(AacMps212State));
  free(w);
  return AAC_OK;
}

void aac_mps212_conceal(AacMps212State* s) {
  if (s == NULL) {
    return;
  }
  /* one set at the end of the frame holding the last parameters */
  s->num_sets_prev = s->num_sets;
  s->num_sets = 1;
  s->param_slot[0] = s->slots - 1;
  s->param_diff[0] = s->slots;
  for (int t = 0; t < 3; t++) {
    memcpy(s->td[t].idx[0], s->td[t].idx_prev, sizeof(s->td[t].idx[0]));
    s->td[t].mode[0] = 1;
  }
  s->temp_shape_enable = 0;
  s->tsd_enable = 0;
  s->stp_channel[0] = s->stp_channel[1] = 0;
  s->ges_channel[0] = s->ges_channel[1] = 0;
  memset(s->tsd_transient, 0, sizeof(s->tsd_transient));
  s->smooth_mode[0] = 1;
  s->inv_smooth_time[0] = s->prev_inv_smooth_time;
  memcpy(s->smoothing[0], s->prev_smg, sizeof(s->smoothing[0]));
}

void aac_mps212_apply(AacMps212State* s, const AacUsacQmfFrame* dmx, const float* residual,
                      AacUsacQmfFrame* left, AacUsacQmfFrame* right) {
  if (s == NULL || dmx == NULL || left == NULL || right == NULL) {
    return;
  }
  const int slots = s->slots;
  const int use_res = s->residual_qmf_bands > 0 && residual != NULL;
  const int res_bands = use_res ? s->res_bands : 0;

  /* the matrices of the frame */
  memcpy(s->m_res_prev, s->m_res[s->num_sets_prev - 1], sizeof(s->m_res_prev));
  memcpy(s->m_dec_prev, s->m_dec[s->num_sets_prev - 1], sizeof(s->m_dec_prev));
  memcpy(s->phase_l_prev, s->phase_l[s->num_sets_prev - 1], sizeof(s->phase_l_prev));
  memcpy(s->phase_r_prev, s->phase_r[s->num_sets_prev - 1], sizeof(s->phase_r_prev));
  set_matrices(s);
  smooth_matrices(s);
  interpolate_matrices(s);
  for (int n = 0; n < slots; n++) {
    for (int ch = 0; ch < 2; ch++) {
      s->ges_gain[ch][n] =
          s->cfg.temp_shape_config == 2 && s->temp_shape_enable && s->ges_channel[ch]
              ? s->env_shape[ch][n]
              : 0.0f;
      s->stp_on[ch][n] = s->temp_shape_enable && s->stp_channel[ch];
    }
    s->tsd_on[n] = s->tsd_enable && s->tsd_transient[n];
    s->tsd_ph[n] = s->tsd_phase[n];
  }

  /* the residual: QMF analysis of the core frame (full scale 1.0 to the
   * QMF frame's 32768), delayed to the downmix's timing band by band */
  if (use_res) {
    const int B = s->residual_qmf_bands;
    float scaled[64];
    for (int n = 0; n < slots; n++) {
      for (int i = 0; i < B; i++) {
        scaled[i] = residual[n * B + i] * 32768.0f;
      }
      float W[64][2];
      memset(W, 0, sizeof(W));
      aac_qmf_analyze(&s->res_qmf, scaled, W);
      memcpy(s->res_ring[s->res_ring_pos], W, sizeof(W));
      s->res_ring_pos = (s->res_ring_pos + 1) % kResRing;
    }
  }

  /* the hybrid signals: v the downmix, w_res the residual in its bands,
   * w_diff the decorrelated downmix elsewhere */
  const float g = s->gain;
  const int first_diff = res_bands > 0 ? aac_mps212_param_band_first_hybrid[res_bands] : 0;
  for (int n = 0; n < slots; n++) {
    float slot[64][2];
    for (int b = 0; b < 64; b++) {
      slot[b][0] = g * dmx->x[n][b][0];
      slot[b][1] = g * dmx->x[n][b][1];
    }
    float res_slot[64][2];
    if (use_res) {
      /* its three lowest bands six slots ahead like the downmix's */
      int low_delay = s->residual_delay;
      if (s->low_ahead) {
        low_delay = low_delay > kHybDelay ? low_delay - kHybDelay : 0;
      }
      for (int b = 0; b < 64; b++) {
        const int delay = b < kLfBands ? low_delay : s->residual_delay;
        const int read = (s->res_ring_pos - slots + n - delay + 2 * kResRing) % kResRing;
        res_slot[b][0] = g * s->res_ring[read][b][0];
        res_slot[b][1] = g * s->res_ring[read][b][1];
      }
      if (s->cfg.pseudo_lr) {
        /* the pseudo left/right pair back to downmix and residual */
        for (int b = 0; b < 64; b++) {
          for (int c = 0; c < 2; c++) {
            const float sum = (slot[b][c] + res_slot[b][c]) * 0.70710678f;
            const float dif = (slot[b][c] - res_slot[b][c]) * 0.70710678f;
            slot[b][c] = sum;
            res_slot[b][c] = dif;
          }
        }
      }
    }
    hybrid_push(s->lf_hist, slot, 64, s->low_ahead, s->v[n]);
    if (use_res) {
      hybrid_push(s->res_lf_hist, res_slot, s->residual_qmf_bands, s->low_ahead, s->w_res[n]);
      for (int k = 0; k < kHyb; k++) {
        if (s->kernel[k] >= res_bands || k >= s->residual_hyb_bands) {
          s->w_res[n][k][0] = s->w_res[n][k][1] = 0.0f;
        }
      }
    } else {
      memset(s->w_res[n], 0, sizeof(s->w_res[n]));
    }
    /* the decorrelator; a transient slot bypasses it above band 7 and
     * enters the diffuse signal with a phase rotation instead */
    float in[kHyb][2];
    memcpy(in, s->v[n], sizeof(in));
    const int transient = s->tsd_on[n];
    if (transient) {
      for (int k = kTsdFirstBand; k < kHyb; k++) {
        in[k][0] = in[k][1] = 0.0f;
      }
    }
    decorrelate_slot(s, in, s->w_diff[n], first_diff);
    for (int k = 0; k < first_diff; k++) {
      s->w_diff[n][k][0] = s->w_diff[n][k][1] = 0.0f;
    }
    if (transient) {
      const float c = cosf((float)s->tsd_ph[n] * kPi / 4.0f);
      const float sn = sinf((float)s->tsd_ph[n] * kPi / 4.0f);
      for (int k = kTsdFirstBand; k < kHyb; k++) {
        s->w_diff[n][k][0] += c * s->v[n][k][0] - sn * s->v[n][k][1];
        s->w_diff[n][k][1] += sn * s->v[n][k][0] + c * s->v[n][k][1];
      }
    }
  }

  /* the mix: the direct part from the downmix and the residual, the
   * diffuse part from the decorrelated signal; hybrid bands 0 and 1 hold
   * the mirrored half of QMF band 0 and take the conjugate weights */
  for (int n = 0; n < slots; n++) {
    for (int k = 0; k < kHyb; k++) {
      const int pb = s->kernel[k];
      const float sign = k < 2 ? -1.0f : 1.0f;
      const float vr = s->v[n][k][0], vi = s->v[n][k][1];
      const float rr = s->w_res[n][k][0], ri = s->w_res[n][k][1];
      const float wr = s->w_diff[n][k][0], wi = s->w_diff[n][k][1];
      for (int row = 0; row < 2; row++) {
        const float* m0 = s->r_res[n][pb][row][0];
        const float* m1 = s->r_res[n][pb][row][1];
        const float* md = s->r_dec[n][pb][row];
        const float m0i = sign * m0[1], mdi = sign * md[1];
        s->dir_out[row][n][k][0] = m0[0] * vr - m0i * vi + m1[0] * rr;
        s->dir_out[row][n][k][1] = m0[0] * vi + m0i * vr + m1[0] * ri;
        s->diff_out[row][n][k][0] = md[0] * wr - mdi * wi;
        s->diff_out[row][n][k][1] = md[0] * wi + mdi * wr;
      }
    }
  }

  /* temporal shaping and the sum of the parts */
  if (s->cfg.temp_shape_config == 2) {
    int shaped = 0;
    for (int n = 0; n < slots; n++) {
      shaped |= s->ges_gain[0][n] != 0.0f || s->ges_gain[1][n] != 0.0f;
    }
    if (shaped) {
      ges_apply(s);
    }
  }
  if (s->cfg.temp_shape_config == 1) {
    for (int ch = 0; ch < 2; ch++) {
      for (int n = 0; n < slots; n++) {
        for (int k = first_diff; k < kStpBorder; k++) {
          s->dir_out[ch][n][k][0] += s->diff_out[ch][n][k][0];
          s->dir_out[ch][n][k][1] += s->diff_out[ch][n][k][1];
          s->diff_out[ch][n][k][0] = s->diff_out[ch][n][k][1] = 0.0f;
        }
      }
    }
    for (int n = 0; n < slots; n++) {
      stp_slot(s, n);
    }
  } else {
    for (int ch = 0; ch < 2; ch++) {
      for (int n = 0; n < slots; n++) {
        for (int k = first_diff; k < kHyb; k++) {
          s->dir_out[ch][n][k][0] += s->diff_out[ch][n][k][0];
          s->dir_out[ch][n][k][1] += s->diff_out[ch][n][k][1];
        }
      }
    }
  }
  for (int n = 0; n < slots; n++) {
    hybrid_synthesis(s->dir_out[0][n], left->x[n]);
    hybrid_synthesis(s->dir_out[1][n], right->x[n]);
  }
  left->slots = slots;
  right->slots = slots;
  s->num_sets_prev = s->num_sets;
}

/* ── test entry points ──────────────────────────────────────────── */

int aac_mps212_huff_read(AacBitReader* r, const int16_t (*nodes)[2], int count) {
  return huff_read(r, nodes, count);
}

int aac_mps212_band_map(int bands, int stride, int* map) { return band_map(bands, stride, map); }

void aac_mps212_ott_matrix(int cld_idx, int icc_idx, float h[2][2][2]) {
  ott_matrix(cld_idx, icc_idx, h);
}

void aac_mps212_pred_weights(int cld_idx, int icc_idx, int ipd_idx, int has_ipd, float* w,
                             float* re, float* im, float* beta) {
  pred_weights(cld_idx, icc_idx, ipd_idx, has_ipd, w, re, im, beta);
}

int aac_mps212_num_param_bands(const AacMps212State* s) { return s ? s->num_bands : 0; }
int aac_mps212_num_ipd_bands(const AacMps212State* s) { return s ? s->num_ipd_bands : 0; }
int aac_mps212_num_param_sets(const AacMps212State* s) { return s ? s->num_sets : 0; }
int aac_mps212_param_slot(const AacMps212State* s, int set) {
  return (s && set >= 0 && set < s->num_sets) ? s->param_slot[set] : -1;
}
int aac_mps212_param(const AacMps212State* s, int type, int set, int band) {
  if (s == NULL || type < 0 || type > 2 || set < 0 || set >= kMaxSets || band < 0 ||
      band >= kMaxPb) {
    return 0;
  }
  return s->td[type].idx[set][band];
}
int aac_mps212_data_mode(const AacMps212State* s, int type, int set) {
  return (s && type >= 0 && type < 3 && set >= 0 && set < kMaxSets) ? s->td[type].mode[set] : -1;
}
int aac_mps212_phase_mode(const AacMps212State* s) { return s ? s->phase_mode : 0; }
int aac_mps212_smooth_mode(const AacMps212State* s, int set) {
  return (s && set >= 0 && set < kMaxSets) ? s->smooth_mode[set] : -1;
}
int aac_mps212_smoothing(const AacMps212State* s, int set, int band) {
  return (s && set >= 0 && set < kMaxSets && band >= 0 && band < kMaxPb) ? s->smoothing[set][band]
                                                                        : 0;
}
int aac_mps212_temp_shape_enabled(const AacMps212State* s) {
  return s ? (s->temp_shape_enable || s->tsd_enable) : 0;
}
int aac_mps212_env_shape_channel(const AacMps212State* s, int ch) {
  return (s && ch >= 0 && ch < 2) ? (s->stp_channel[ch] || s->ges_channel[ch]) : 0;
}
float aac_mps212_env_shape(const AacMps212State* s, int ch, int slot) {
  return (s && ch >= 0 && ch < 2 && slot >= 0 && slot < kMaxSlots) ? s->env_shape[ch][slot] : 0.0f;
}
int aac_mps212_tsd_transient(const AacMps212State* s, int slot) {
  return (s && slot >= 0 && slot < kMaxSlots) ? s->tsd_transient[slot] : 0;
}
int aac_mps212_tsd_phase(const AacMps212State* s, int slot) {
  return (s && slot >= 0 && slot < kMaxSlots) ? s->tsd_phase[slot] : 0;
}

void aac_mps212_hybrid_analysis(const float (*in)[64][2], float (*out)[2]) {
  hybrid_tables_init();
  hybrid_low(in, out);
  for (int b = kLfBands; b < 64; b++) {
    out[7 + b][0] = in[kHybDelay][b][0];
    out[7 + b][1] = in[kHybDelay][b][1];
  }
}

void aac_mps212_hybrid_synthesis(const float (*in)[2], float (*out)[2]) {
  hybrid_synthesis(in, out);
}

void aac_mps212_decorrelate(AacMps212State* s, const float (*in)[2], float (*out)[2]) {
  if (s) {
    decorrelate_slot(s, in, out, 0);
  }
}
