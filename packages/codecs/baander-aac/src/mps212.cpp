#include "mps212.h"

#include <cmath>
#include <cstdlib>
#include <cstring>

#include "aac_tables.h"

/* MPEG Surround 212 (ISO/IEC 23003-3 7.11 over the one-to-two box of
 * ISO/IEC 23003-1).
 *
 * Bitstream (Mps212Data(), as this implementation reads it):
 *
 *   if (bsHighRateMode) bsFramingInfo():
 *       bsFramingType (1), bsNumParamSets (1) -> 1 or 2 sets,
 *       with bsFramingType 1 one bsParamSlot per set of ceil(log2(slots))
 *       bits; with 0 the sets sit at the fixed positions of 23003-1
 *       (ceil(slots (i + 1) / sets) - 1).
 *   else one parameter set at the last slot of the frame.
 *   bsIndependencyFlag (1) unless usacIndependencyFlag is set, which
 *       implies it.
 *   OttData(): EcData(CLD), EcData(ICC); with bsPhaseCoding bsPhaseMode (1)
 *       and, when set, bsOpdSmoothingMode (1) and EcData(IPD) over
 *       bsOttBandsPhase bands (all parameter bands when the configuration
 *       does not carry the count).
 *   if (!bsHighRateMode) SmgData(): per set bsSmoothMode (2); mode >= 2
 *       adds bsSmoothTime (2); mode 3 adds bsFreqResStrideSmg (2) and one
 *       bsSmgData bit per (strided) parameter band.
 *   if (bsTempShapeConfig != 0) TempShapeData():
 *       config 1 (STP): bsTempShapeEnable (1), then one bsEnvShapeChannel
 *           bit per output channel;
 *       config 2 (GES): bsTempShapeEnable (1), one bsEnvShapeChannel bit
 *           per channel, then for each flagged channel one bsEnvShapeData
 *           codeword per slot (differences to the previous slot);
 *       config 3 (TSD): bsTsdEnable (1); when set bsTsdNumTrSlots
 *           (ceil(log2(slots)) bits, transient slots - 1), bsTsdCodedPos
 *           (ceil(log2(C(slots, transient slots))) bits, the lexicographic
 *           index of the slot set) and 3 bits of bsTsdTrPhaseData per
 *           transient slot (multiples of pi/4).
 *
 *   EcData(type, bands): bsDataMode (2) per set (0 default, 1 keep,
 *   2 interpolate, 3 read).  For each read set (or pair of read sets):
 *   bsDataPair (1), bsQuantCoarse (1), bsFreqResStride (2: 1, 2, 5, 28
 *   parameter bands per value), bsPCMCoding (1).  PCM: the quantiser
 *   indices with fixed widths (CLD 5 or 4 bits, ICC 3 or 2, IPD 4 or 3).
 *   Huffman: bsDiffType per set (1: differential to the reference set;
 *   absent for the first set when no reference exists, then differential
 *   in frequency), bsCodingScheme (1: pairwise two-dimensional codewords,
 *   present with more than one value) and the codewords: the first value
 *   of a frequency-differential set with its "first value" codebook, the
 *   two-dimensional pairs after a Huffman-coded LAV class index.
 *
 * The codebooks are built at creation from a geometric model (see
 * build_codebooks); they are consistent between this decoder and the
 * matching encoder of the test suite but they are not the standard's
 * tables, which were not available.  They are the first thing to replace
 * once the standard's tables or captured payloads are at hand.
 *
 * Processing: the downmix (and the residual, analysed with a QMF bank of
 * 32, 24 or 16 bands into the lower bands of the 64) go through the
 * hybrid filterbank of 23003-1 6.6.3 (71 hybrid bands, six slots of
 * delay); the dry hybrid signal feeds the decorrelator (pre-delay, lattice
 * all-pass filter per frequency region, energy ducker); the residual
 * replaces the decorrelator output in the residual bands; the mixing
 * matrix per parameter band is interpolated between parameter sets per
 * slot (and smoothed in low rate mode), applied, shaped (STP, GES or TSD)
 * and the hybrid synthesis sums the bands back to 64. */

extern const float aac_mps212_ges_ratio[2][8];
extern const int aac_mps212_ges_levels[2];
extern const uint8_t aac_mps212_decorr_region_edge[5];
extern const uint8_t aac_mps212_decorr_order[3][4];
extern const uint8_t aac_mps212_decorr_delay[3][4];
extern const float aac_mps212_decorr_reflection[4][10];

namespace {

constexpr int kMaxPb = AAC_MPS212_MAX_PARAM_BANDS;
constexpr int kHyb = AAC_MPS212_HYBRID_BANDS;
constexpr int kMaxSlots = AAC_USAC_MAX_QMF_SLOTS;
constexpr int kMaxSets = AAC_MPS212_MAX_PARAM_SETS;
constexpr int kHybDelay = 6; /* slots of hybrid filter delay */
constexpr int kHistory = 2 * kHybDelay + 1;
constexpr int kMaxDecorrDelay = 8;
constexpr int kMaxOrder = 10;
constexpr int kShapeFirstBand = 10; /* hybrid band of QMF band 3: shaping above ~1.1 kHz */
constexpr int kMaxCodeLen = 24;
constexpr int kNodeCount = 4096;
constexpr int kEntryCount = 2048;
constexpr int kResRing = kMaxSlots + 1;

enum { kCld = 0, kIcc = 1, kIpd = 2 };
enum { kDiffFreq = 0, kDiffTime = 1 };

constexpr int kLav[3][4] = {{3, 5, 7, 9}, {1, 3, 5, 7}, {1, 3, 5, 7}};
constexpr int kStride[4] = {1, 2, 5, 28};

struct CodebookSpec {
  int nsym;
  int offset;  /* value of symbol 0 (one-dimensional) */
  int dim;     /* 1 or 2 */
  int lav;     /* two-dimensional: the class bound */
  float decay; /* geometric model: weight 2^(-|v| / decay) */
};

struct Codebook {
  int nsym, offset, dim, lav;
  int first_node;  /* into the shared node array */
  int first_entry; /* into the shared entry array */
};

struct Entry {
  uint8_t len;
  uint32_t code;
};

struct Decorr {
  float a[4][kMaxOrder + 1]; /* all-pass denominator per region, a[0] = 1 */
  float delay[kHyb][kMaxDecorrDelay][2];
  int delay_pos[kHyb];
  float xh[kHyb][kMaxOrder][2]; /* input history (ring) */
  float yh[kHyb][kMaxOrder][2]; /* output history (ring) */
  int pos[kHyb];
  float e_in[kMaxPb], e_out[kMaxPb]; /* ducker energies per parameter band */
};

/* What a slot needs when its hybrid samples come out six slots later. */
struct SlotMeta {
  float h[kMaxPb][2][2][2];
  int ges[2];
  int tsd;
  int tsd_phase;
};

}  // namespace

struct AacMps212State_ {
  AacUsacMps212Config cfg;
  int stereo_config_index;
  int frame_length, output_frame_length, sample_rate;
  int slots;     /* QMF slots per frame */
  int slot_bits; /* ceil(log2(slots)) */
  int num_bands; /* parameter bands */
  int num_ipd_bands;
  int residual_qmf_bands; /* QMF bands of the residual's analysis bank */
  int residual_delay;     /* slots the residual is delayed against the downmix */
  const uint8_t* kernel;  /* hybrid band -> parameter band */
  float fixed_gain;

  /* codebooks */
  Codebook cb[AAC_MPS212_CB_COUNT];
  int16_t nodes[kNodeCount][2]; /* > 0 child node, < 0 -(symbol + 1), 0 none */
  int node_count;
  Entry entries[kEntryCount];
  int entry_count;

  /* ── parsed parameters of the current frame ── */
  int num_sets;
  int param_slot[kMaxSets]; /* last slot of each set */
  int independency;
  int cld[kMaxSets][kMaxPb]; /* quantiser indices, fine scale */
  int icc[kMaxSets][kMaxPb];
  int ipd[kMaxSets][kMaxPb];
  int phase_mode, opd_smoothing;
  int smooth_mode[kMaxSets], smooth_time[kMaxSets], smooth_band[kMaxSets][kMaxPb];
  int temp_shape_enable;
  int env_shape_channel[2];
  int env_shape[2][kMaxSlots]; /* GES: quantiser index per slot */
  int tsd_enable;
  int tsd_transient[kMaxSlots];
  int tsd_phase[kMaxSlots];                                 /* multiples of pi/4 */
  int prev_cld[kMaxPb], prev_icc[kMaxPb], prev_ipd[kMaxPb]; /* the reference set */
  int have_prev;
  int data_mode[3][kMaxSets];

  /* ── processing state ── */
  float hist[kHistory][64][2];     /* downmix QMF slots, newest last */
  float res_hist[kHistory][64][2]; /* residual QMF slots */
  float res_ring[kResRing][64][2]; /* residual delay line */
  int res_ring_pos;
  float res_frame[kMaxSlots][64][2];
  float m_hyb[kHyb][2];
  float r_hyb[kHyb][2];
  float w_hyb[kHyb][2];
  float dry[2][kHyb][2];
  float wet[2][kHyb][2];
  float out_hyb[2][kHyb][2];
  /* mixing matrices: set 0 is the previous frame's last set */
  float h_set[kMaxSets + 1][kMaxPb][2][2][2];
  float h_prev[kMaxPb][2][2][2]; /* the previous frame's last set */
  float h_slot[kMaxSlots][kMaxPb][2][2][2];
  float h_smooth[kMaxPb][2][2][2];
  SlotMeta tail[kHybDelay]; /* the previous frame's last six slots */
  float prev_opd[kMaxPb];
  int smoothing_on[kMaxPb];
  float smoothing_delta;
  Decorr decorr;
  float stp_dry_mean[2], stp_wet_mean[2];
  float ges_dmx_mean, ges_ch_mean[2];
  /* residual QMF analysis */
  float res_x[640];
  float res_window[320];
  float res_cos[32][64], res_sin[32][64];
  int frames;
};

namespace {

/* ── hybrid filterbank (ISO/IEC 23003-1 6.6.3, the structure of the
 * parametric stereo hybrid of ISO/IEC 14496-3 8.6.4.3) ─────────── */

const float kG0Q8[7] = {0.00746082949812f,
                        0.02270420949825f,
                        0.04546865930473f,
                        0.07266113929591f,
                        0.09885108575264f,
                        0.11793710567217f,
                        0.125f};
const float kG1Q2[7] = {0.0f, 0.01899487526049f, 0.0f, -0.07293139167538f,
                        0.0f, 0.30596630545168f, 0.5f};
float g_f8[8][13][2];
int g_hybrid_ready = 0;

void hybrid_tables_init(void) {
  if (g_hybrid_ready) {
    return;
  }
  for (int q = 0; q < 8; q++) {
    for (int n = 0; n < 13; n++) {
      const float p = n < 7 ? kG0Q8[n] : kG0Q8[12 - n];
      const double theta = 2.0 * M_PI * (q + 0.5) * (n - 6) / 8.0;
      g_f8[q][n][0] = (float)(p * cos(theta));
      g_f8[q][n][1] = (float)(p * -sin(theta));
    }
  }
  g_hybrid_ready = 1;
}

/* Analyses the slot centred in `in` (13 slots of the 64 QMF bands) into
 * 71 hybrid bands.  QMF band 0 holds 0..375 Hz as the positive half of
 * its subband spectrum; the eight-band filter's outputs 0..3 are the
 * in-band quarters in ascending order and 4..7 lie outside the band.  The
 * hybrid order is the parametric stereo one: outputs 6, 7, 0, 1, 2+5,
 * 3+4, so that bands 2..5 hold the in-band quarters ascending (bands 4
 * and 5 with an out-of-band partner) and the parameter band table's
 * first row (1, 0, 0, 1, 2, 3) gives four ascending parameter bands.
 * QMF band 1 holds its content in the negative half of the subband
 * spectrum, band 2 in the positive half; the two-band real filter's
 * low-pass output is therefore the upper half of band 1 and the lower
 * half of band 2, and the outputs are ordered ascending in frequency. */
void hybrid_analysis(const float (*in)[64][2], float (*out)[2]) {
  float t[8][2];
  for (int q = 0; q < 8; q++) {
    float re = 0.0f, im = 0.0f;
    for (int n = 0; n < 13; n++) {
      const float xr = in[n][0][0], xi = in[n][0][1];
      const float fr = g_f8[q][n][0], fi = g_f8[q][n][1];
      re += fr * xr - fi * xi;
      im += fr * xi + fi * xr;
    }
    t[q][0] = re;
    t[q][1] = im;
  }
  out[0][0] = t[6][0];
  out[0][1] = t[6][1];
  out[1][0] = t[7][0];
  out[1][1] = t[7][1];
  out[2][0] = t[0][0];
  out[2][1] = t[0][1];
  out[3][0] = t[1][0];
  out[3][1] = t[1][1];
  out[4][0] = t[2][0] + t[5][0];
  out[4][1] = t[2][1] + t[5][1];
  out[5][0] = t[3][0] + t[4][0];
  out[5][1] = t[3][1] + t[4][1];
  for (int b = 1; b <= 2; b++) {
    const float re_c = kG1Q2[6] * in[6][b][0];
    const float im_c = kG1Q2[6] * in[6][b][1];
    float re_o = 0.0f, im_o = 0.0f;
    for (int j = 1; j < 6; j += 2) {
      re_o += kG1Q2[j] * (in[j][b][0] + in[12 - j][b][0]);
      im_o += kG1Q2[j] * (in[j][b][1] + in[12 - j][b][1]);
    }
    const int lo = 6 + 2 * (b - 1);
    const int lowpass_is_lower = b == 2;
    out[lowpass_is_lower ? lo : lo + 1][0] = re_c + re_o;
    out[lowpass_is_lower ? lo : lo + 1][1] = im_c + im_o;
    out[lowpass_is_lower ? lo + 1 : lo][0] = re_c - re_o;
    out[lowpass_is_lower ? lo + 1 : lo][1] = im_c - im_o;
  }
  for (int b = 3; b < 64; b++) {
    out[7 + b][0] = in[kHybDelay][b][0];
    out[7 + b][1] = in[kHybDelay][b][1];
  }
}

void hybrid_synthesis(const float (*in)[2], float (*out)[2]) {
  for (int b = 0; b < 3; b++) {
    out[b][0] = out[b][1] = 0.0f;
  }
  for (int k = 0; k < 6; k++) {
    out[0][0] += in[k][0];
    out[0][1] += in[k][1];
  }
  for (int k = 6; k < 8; k++) {
    out[1][0] += in[k][0];
    out[1][1] += in[k][1];
  }
  for (int k = 8; k < 10; k++) {
    out[2][0] += in[k][0];
    out[2][1] += in[k][1];
  }
  for (int b = 3; b < 64; b++) {
    out[b][0] = in[7 + b][0];
    out[b][1] = in[7 + b][1];
  }
}

/* QMF band of a hybrid band. */
int qmf_band_of(int hyb) {
  if (hyb < 6) return 0;
  if (hyb < 8) return 1;
  if (hyb < 10) return 2;
  return hyb - 7;
}

/* ── codebooks ──────────────────────────────────────────────────── */

/* Huffman code lengths from weights (n <= 400) by repeated merging of
 * the two lightest trees. */
void huffman_lengths(const double* w, int n, uint8_t* len) {
  double wsum[800];
  int parent[800];
  int ids[800];
  int nids = n;
  for (int i = 0; i < n; i++) {
    wsum[i] = w[i];
    parent[i] = -1;
    ids[i] = i;
  }
  int next = n;
  while (nids > 1) {
    int a = -1, b = -1;
    for (int i = 0; i < nids; i++) {
      const int id = ids[i];
      if (a < 0 || wsum[id] < wsum[a]) {
        b = a;
        a = id;
      } else if (b < 0 || wsum[id] < wsum[b]) {
        b = id;
      }
    }
    parent[a] = next;
    parent[b] = next;
    wsum[next] = wsum[a] + wsum[b];
    parent[next] = -1;
    int k = 0;
    for (int i = 0; i < nids; i++) {
      if (ids[i] != a && ids[i] != b) {
        ids[k++] = ids[i];
      }
    }
    ids[k++] = next;
    nids = k;
    next++;
  }
  for (int i = 0; i < n; i++) {
    int l = 0;
    for (int p = parent[i]; p >= 0; p = parent[p]) {
      l++;
    }
    len[i] = (uint8_t)(l == 0 ? 1 : l);
  }
}

/* Canonical codes from lengths: symbols sorted by (length, index). */
void canonical_codes(const uint8_t* len, int n, Entry* e) {
  int count[64] = {0};
  for (int i = 0; i < n; i++) {
    count[len[i]]++;
  }
  uint32_t next_code[64];
  uint32_t code = 0;
  for (int l = 1; l < 64; l++) {
    next_code[l] = code;
    code = (code + (uint32_t)count[l]) << 1;
  }
  for (int i = 0; i < n; i++) {
    e[i].len = len[i];
    e[i].code = next_code[len[i]]++;
  }
}

int add_node(AacMps212State* s) {
  if (s->node_count >= kNodeCount) {
    return -1;
  }
  s->nodes[s->node_count][0] = 0;
  s->nodes[s->node_count][1] = 0;
  return s->node_count++;
}

/* Builds the decoding tree of codebook `id` from its entries. */
int build_tree(AacMps212State* s, int id) {
  Codebook* cb = &s->cb[id];
  cb->first_node = add_node(s);
  if (cb->first_node < 0) {
    return -1;
  }
  for (int i = 0; i < cb->nsym; i++) {
    const Entry e = s->entries[cb->first_entry + i];
    int node = cb->first_node;
    for (int b = e.len - 1; b >= 0; b--) {
      const int bit = (int)((e.code >> b) & 1u);
      if (b == 0) {
        if (s->nodes[node][bit] != 0) {
          return -1; /* not a prefix code */
        }
        s->nodes[node][bit] = (int16_t)(-(i + 1));
      } else {
        int child = s->nodes[node][bit];
        if (child < 0) {
          return -1;
        }
        if (child == 0) {
          child = add_node(s);
          if (child < 0) {
            return -1;
          }
          s->nodes[node][bit] = (int16_t)child;
        }
        node = child;
      }
    }
  }
  return 0;
}

int make_codebook(AacMps212State* s, int id, const CodebookSpec* spec) {
  Codebook* cb = &s->cb[id];
  cb->nsym = spec->nsym;
  cb->offset = spec->offset;
  cb->dim = spec->dim;
  cb->lav = spec->lav;
  if (s->entry_count + spec->nsym > kEntryCount) {
    return -1;
  }
  cb->first_entry = s->entry_count;
  s->entry_count += spec->nsym;
  double w[400];
  uint8_t len[400];
  for (int i = 0; i < spec->nsym; i++) {
    double mag;
    if (spec->dim == 2) {
      const int side = 2 * spec->lav + 1;
      const int x = i / side - spec->lav;
      const int y = i % side - spec->lav;
      mag = abs(x) + abs(y);
    } else {
      mag = abs(i + spec->offset);
    }
    w[i] = pow(2.0, -mag / spec->decay) + 1e-9;
  }
  huffman_lengths(w, spec->nsym, len);
  for (int i = 0; i < spec->nsym; i++) {
    if (len[i] > kMaxCodeLen) {
      return -1;
    }
  }
  canonical_codes(len, spec->nsym, &s->entries[cb->first_entry]);
  return build_tree(s, id);
}

/* The LAV class index code: 0, 10, 110, 111. */
int make_lav_codebook(AacMps212State* s) {
  Codebook* cb = &s->cb[AAC_MPS212_CB_LAV];
  cb->nsym = 4;
  cb->offset = 0;
  cb->dim = 1;
  cb->lav = 0;
  cb->first_entry = s->entry_count;
  s->entry_count += 4;
  const Entry e[4] = {{1, 0}, {2, 2}, {3, 6}, {3, 7}};
  for (int i = 0; i < 4; i++) {
    s->entries[cb->first_entry + i] = e[i];
  }
  return build_tree(s, AAC_MPS212_CB_LAV);
}

int build_codebooks(AacMps212State* s) {
  s->node_count = 0;
  s->entry_count = 0;
  CodebookSpec spec;
  spec = {31, -15, 1, 0, 3.0f};
  if (make_codebook(s, AAC_MPS212_CB_CLD_FIRST, &spec)) return -1;
  spec = {8, 0, 1, 0, 2.0f};
  if (make_codebook(s, AAC_MPS212_CB_ICC_FIRST, &spec)) return -1;
  spec = {16, 0, 1, 0, 100.0f};
  if (make_codebook(s, AAC_MPS212_CB_IPD_FIRST, &spec)) return -1;
  spec = {61, -30, 1, 0, 1.5f};
  if (make_codebook(s, AAC_MPS212_CB_CLD_1D, &spec)) return -1;
  spec = {15, -7, 1, 0, 1.0f};
  if (make_codebook(s, AAC_MPS212_CB_ICC_1D, &spec)) return -1;
  spec = {16, -8, 1, 0, 1.5f};
  if (make_codebook(s, AAC_MPS212_CB_IPD_1D, &spec)) return -1;
  for (int t = 0; t < 3; t++) {
    for (int l = 0; l < 4; l++) {
      const int lav = kLav[t][l];
      spec = {(2 * lav + 1) * (2 * lav + 1), 0, 2, lav, 1.5f};
      if (make_codebook(s, AAC_MPS212_CB_CLD_2D + 4 * t + l, &spec)) return -1;
    }
  }
  if (make_lav_codebook(s)) return -1;
  spec = {15, -7, 1, 0, 1.0f};
  if (make_codebook(s, AAC_MPS212_CB_ENV, &spec)) return -1;
  return 0;
}

/* Reads one codeword; returns the symbol index or -1. */
int huff_read(const AacMps212State* s, AacBitReader* r, int id) {
  const Codebook* cb = &s->cb[id];
  int node = cb->first_node;
  for (int i = 0; i < kMaxCodeLen; i++) {
    const int bit = (int)aac_bitreader_read(r, 1);
    const int next = s->nodes[node][bit];
    if (next < 0) {
      return -(next + 1);
    }
    if (next == 0) {
      return -1;
    }
    node = next;
  }
  return -1;
}

/* ── parameter reading ──────────────────────────────────────────── */

int ceil_log2(int n) {
  int b = 0;
  while ((1 << b) < n) {
    b++;
  }
  return b;
}

/* Index range of a type on the fine or coarse scale; returns the PCM
 * width. */
int type_range(int type, int coarse, int* lo, int* hi) {
  switch (type) {
    case kCld:
      *lo = coarse ? -7 : -15;
      *hi = coarse ? 7 : 15;
      return coarse ? 4 : 5;
    case kIcc:
      *lo = 0;
      *hi = coarse ? 3 : 7;
      return coarse ? 2 : 3;
    default:
      *lo = 0;
      *hi = coarse ? 7 : 15;
      return coarse ? 3 : 4;
  }
}

int wrap_ipd(int v, int coarse) {
  const int n = coarse ? 8 : 16;
  v %= n;
  return v < 0 ? v + n : v;
}

int to_coarse(int type, int v) {
  if (type == kIpd) {
    return wrap_ipd((v + 1) / 2, 1);
  }
  return v >= 0 ? (v + 1) / 2 : -((-v + 1) / 2);
}

int to_fine(int type, int v, int coarse) {
  if (!coarse) {
    return v;
  }
  return type == kIpd ? wrap_ipd(2 * v, 0) : 2 * v;
}

struct EcContext {
  AacMps212State* s;
  AacBitReader* r;
  int type;
  int coarse;
  int bands; /* data bands (after the stride) */
};

int check_value(const EcContext* c, int v) {
  int lo, hi;
  type_range(c->type, c->coarse, &lo, &hi);
  return v >= lo && v <= hi;
}

int read_first(const EcContext* c, int* v) {
  const int id = AAC_MPS212_CB_CLD_FIRST + c->type;
  const int sym = huff_read(c->s, c->r, id);
  if (sym < 0) {
    return -1;
  }
  int val = sym + c->s->cb[id].offset;
  if (c->coarse) {
    val = to_coarse(c->type, val);
  }
  *v = val;
  return check_value(c, val) ? 0 : -1;
}

int read_diff(const EcContext* c, int* d) {
  const int id = AAC_MPS212_CB_CLD_1D + c->type;
  const int sym = huff_read(c->s, c->r, id);
  if (sym < 0) {
    return -1;
  }
  *d = sym + c->s->cb[id].offset;
  return 0;
}

int apply_diff(const EcContext* c, int base, int d, int* v) {
  int val = base + d;
  if (c->type == kIpd) {
    val = wrap_ipd(val, c->coarse);
  }
  *v = val;
  return check_value(c, val) ? 0 : -1;
}

int read_pair(const EcContext* c, int lav_idx, int* dx, int* dy) {
  const int id = AAC_MPS212_CB_CLD_2D + 4 * c->type + lav_idx;
  const int sym = huff_read(c->s, c->r, id);
  if (sym < 0) {
    return -1;
  }
  const int lav = c->s->cb[id].lav;
  const int side = 2 * lav + 1;
  *dx = sym / side - lav;
  *dy = sym % side - lav;
  return 0;
}

/* One set of `bands` values, differential in frequency (ref NULL) or to
 * the reference set. */
int read_set(const EcContext* c, const int* ref, int scheme, int* out) {
  const int n = c->bands;
  int b = 0;
  if (ref == NULL) {
    if (read_first(c, &out[0])) {
      return -1;
    }
    b = 1;
  }
  if (scheme == 0) {
    for (; b < n; b++) {
      int d;
      if (read_diff(c, &d) || apply_diff(c, ref ? ref[b] : out[b - 1], d, &out[b])) {
        return -1;
      }
    }
    return 0;
  }
  if (b < n - 1) {
    const int lav_idx = huff_read(c->s, c->r, AAC_MPS212_CB_LAV);
    if (lav_idx < 0) {
      return -1;
    }
    for (; b + 1 < n; b += 2) {
      int dx, dy;
      if (read_pair(c, lav_idx, &dx, &dy)) {
        return -1;
      }
      if (ref) {
        if (apply_diff(c, ref[b], dx, &out[b]) || apply_diff(c, ref[b + 1], dy, &out[b + 1])) {
          return -1;
        }
      } else {
        if (apply_diff(c, out[b - 1], dx, &out[b]) || apply_diff(c, out[b], dy, &out[b + 1])) {
          return -1;
        }
      }
    }
  }
  if (b < n) {
    int d;
    if (read_diff(c, &d) || apply_diff(c, ref ? ref[b] : out[b - 1], d, &out[b])) {
      return -1;
    }
  }
  return 0;
}

/* EcData() of one parameter type: fills values[set][band] for all sets
 * (quantiser indices on the fine scale). */
int read_ecdata(AacMps212State* s, AacBitReader* r, int type, int nbands, int (*values)[kMaxPb],
                const int* prev, int have_prev) {
  int mode[kMaxSets];
  for (int ps = 0; ps < s->num_sets; ps++) {
    mode[ps] = (int)aac_bitreader_read(r, 2);
    s->data_mode[type][ps] = mode[ps];
  }
  int determined[kMaxSets] = {0};
  const int* frame_ref = (have_prev && !s->independency) ? prev : NULL;
  for (int ps = 0; ps < s->num_sets; ps++) {
    if (mode[ps] != 3) {
      continue;
    }
    const int pair = (int)aac_bitreader_read(r, 1);
    const int coarse = (int)aac_bitreader_read(r, 1);
    const int stride = kStride[aac_bitreader_read(r, 2)];
    const int data_bands = (nbands + stride - 1) / stride;
    if (pair && (ps + 1 >= s->num_sets || mode[ps + 1] != 3)) {
      return -1;
    }
    const int nsets = pair ? 2 : 1;
    int data[2][kMaxPb];
    const int pcm = (int)aac_bitreader_read(r, 1);
    EcContext c = {s, r, type, coarse, data_bands};
    if (pcm) {
      int lo, hi;
      const int bits = type_range(type, coarse, &lo, &hi);
      for (int j = 0; j < nsets; j++) {
        for (int b = 0; b < data_bands; b++) {
          const int v = (int)aac_bitreader_read(r, bits) + lo;
          if (v > hi) {
            return -1;
          }
          data[j][b] = v;
        }
      }
    } else {
      /* the reference of a time difference: the previous determined set
       * of this frame, else the previous frame's last set */
      const int* ref_fine = NULL;
      for (int q = ps - 1; q >= 0; q--) {
        if (determined[q]) {
          ref_fine = values[q];
          break;
        }
      }
      if (ref_fine == NULL) {
        ref_fine = frame_ref;
      }
      int ref[kMaxPb];
      if (ref_fine) {
        for (int b = 0; b < data_bands; b++) {
          const int v = ref_fine[b * stride];
          ref[b] = coarse ? to_coarse(type, v) : v;
        }
      }
      const int diff0 = ref_fine ? (int)aac_bitreader_read(r, 1) : kDiffFreq;
      const int diff1 = pair ? (int)aac_bitreader_read(r, 1) : kDiffFreq;
      const int scheme = data_bands > 1 ? (int)aac_bitreader_read(r, 1) : 0;
      if (read_set(&c, diff0 == kDiffTime ? ref : NULL, scheme, data[0])) {
        return -1;
      }
      if (pair && read_set(&c, diff1 == kDiffTime ? data[0] : NULL, scheme, data[1])) {
        return -1;
      }
    }
    for (int j = 0; j < nsets; j++) {
      for (int b = 0; b < nbands; b++) {
        values[ps + j][b] = to_fine(type, data[j][b / stride], coarse);
      }
      determined[ps + j] = 1;
    }
    ps += nsets - 1;
  }
  /* default and keep */
  for (int ps = 0; ps < s->num_sets; ps++) {
    if (mode[ps] == 0) {
      for (int b = 0; b < nbands; b++) {
        values[ps][b] = 0;
      }
      determined[ps] = 1;
    } else if (mode[ps] == 1) {
      const int* src = NULL;
      for (int q = ps - 1; q >= 0; q--) {
        if (determined[q]) {
          src = values[q];
          break;
        }
      }
      if (src == NULL && have_prev) {
        src = prev;
      }
      for (int b = 0; b < nbands; b++) {
        values[ps][b] = src ? src[b] : 0;
      }
      determined[ps] = 1;
    }
  }
  /* interpolate: between the previous determined set (or the previous
   * frame's last set) and the next determined set */
  for (int ps = 0; ps < s->num_sets; ps++) {
    if (mode[ps] != 2) {
      continue;
    }
    int lo_set = -1, hi_set = -1;
    for (int q = ps - 1; q >= 0; q--) {
      if (determined[q]) {
        lo_set = q;
        break;
      }
    }
    for (int q = ps + 1; q < s->num_sets; q++) {
      if (determined[q]) {
        hi_set = q;
        break;
      }
    }
    const int* lo = lo_set >= 0 ? values[lo_set] : (have_prev ? prev : NULL);
    const int lo_slot = lo_set >= 0 ? s->param_slot[lo_set] : -1;
    for (int b = 0; b < nbands; b++) {
      const int a = lo ? lo[b] : 0;
      if (hi_set < 0) {
        values[ps][b] = a;
        continue;
      }
      const int hi_slot = s->param_slot[hi_set];
      const float t = hi_slot > lo_slot
                          ? (float)(s->param_slot[ps] - lo_slot) / (float)(hi_slot - lo_slot)
                          : 1.0f;
      int d = values[hi_set][b] - a;
      if (type == kIpd) {
        if (d > 8) d -= 16;
        if (d < -8) d += 16;
      }
      int iv = (int)lrintf((float)a + t * (float)d);
      if (type == kIpd) {
        iv = wrap_ipd(iv, 0);
      }
      values[ps][b] = iv;
    }
    determined[ps] = 1;
  }
  return 0;
}

int read_framing(AacMps212State* s, AacBitReader* r) {
  if (!s->cfg.high_rate_mode) {
    s->num_sets = 1;
    s->param_slot[0] = s->slots - 1;
    return 0;
  }
  const int framing_type = (int)aac_bitreader_read(r, 1);
  s->num_sets = (int)aac_bitreader_read(r, 1) + 1;
  int prev = -1;
  for (int ps = 0; ps < s->num_sets; ps++) {
    int slot;
    if (framing_type) {
      slot = (int)aac_bitreader_read(r, s->slot_bits);
    } else {
      slot = (s->slots * (ps + 1) + s->num_sets - 1) / s->num_sets - 1;
    }
    if (slot <= prev || slot >= s->slots) {
      return -1;
    }
    s->param_slot[ps] = slot;
    prev = slot;
  }
  return 0;
}

int read_smoothing(AacMps212State* s, AacBitReader* r) {
  for (int ps = 0; ps < s->num_sets; ps++) {
    s->smooth_mode[ps] = (int)aac_bitreader_read(r, 2);
    s->smooth_time[ps] = -1;
    if (s->smooth_mode[ps] >= 2) {
      s->smooth_time[ps] = (int)aac_bitreader_read(r, 2);
    }
    if (s->smooth_mode[ps] == 3) {
      const int stride = kStride[aac_bitreader_read(r, 2)];
      const int data_bands = (s->num_bands + stride - 1) / stride;
      int flags[kMaxPb];
      for (int b = 0; b < data_bands; b++) {
        flags[b] = (int)aac_bitreader_read(r, 1);
      }
      for (int b = 0; b < s->num_bands; b++) {
        s->smooth_band[ps][b] = flags[b / stride];
      }
    } else {
      for (int b = 0; b < s->num_bands; b++) {
        s->smooth_band[ps][b] = s->smooth_mode[ps] != 0;
      }
    }
  }
  return 0;
}

double binomial(int n, int k) {
  if (k < 0 || k > n) {
    return 0.0;
  }
  double c = 1.0;
  for (int i = 1; i <= k; i++) {
    c = c * (double)(n - k + i) / (double)i;
  }
  return c;
}

int read_temp_shape(AacMps212State* s, AacBitReader* r) {
  s->temp_shape_enable = 0;
  s->tsd_enable = 0;
  s->env_shape_channel[0] = s->env_shape_channel[1] = 0;
  switch (s->cfg.temp_shape_config) {
    case 1:
      s->temp_shape_enable = (int)aac_bitreader_read(r, 1);
      if (s->temp_shape_enable) {
        for (int ch = 0; ch < 2; ch++) {
          s->env_shape_channel[ch] = (int)aac_bitreader_read(r, 1);
        }
      }
      return 0;
    case 2: {
      s->temp_shape_enable = (int)aac_bitreader_read(r, 1);
      if (!s->temp_shape_enable) {
        return 0;
      }
      for (int ch = 0; ch < 2; ch++) {
        s->env_shape_channel[ch] = (int)aac_bitreader_read(r, 1);
      }
      const int levels = aac_mps212_ges_levels[s->cfg.env_quant_mode ? 1 : 0];
      for (int ch = 0; ch < 2; ch++) {
        if (!s->env_shape_channel[ch]) {
          continue;
        }
        int prev = levels / 2;
        for (int n = 0; n < s->slots; n++) {
          const int sym = huff_read(s, r, AAC_MPS212_CB_ENV);
          if (sym < 0) {
            return -1;
          }
          const int v = prev + sym + s->cb[AAC_MPS212_CB_ENV].offset;
          if (v < 0 || v >= levels) {
            return -1;
          }
          s->env_shape[ch][n] = v;
          prev = v;
        }
      }
      return 0;
    }
    case 3: {
      s->tsd_enable = (int)aac_bitreader_read(r, 1);
      memset(s->tsd_transient, 0, sizeof(s->tsd_transient));
      if (!s->tsd_enable) {
        return 0;
      }
      const int num_tr = (int)aac_bitreader_read(r, s->slot_bits) + 1;
      if (num_tr > s->slots) {
        return -1;
      }
      const double combos = binomial(s->slots, num_tr);
      int bits = 0;
      while (bits < 40 && pow(2.0, bits) < combos) {
        bits++;
      }
      if (bits > 32) {
        return -1;
      }
      double index = (double)aac_bitreader_read(r, bits);
      if (index >= combos) {
        return -1;
      }
      /* lexicographic unranking of the slot set */
      int remaining = num_tr;
      for (int slot = 0; slot < s->slots && remaining > 0; slot++) {
        const double with = binomial(s->slots - slot - 1, remaining - 1);
        if (index < with) {
          s->tsd_transient[slot] = 1;
          remaining--;
        } else {
          index -= with;
        }
      }
      if (remaining != 0) {
        return -1;
      }
      for (int n = 0; n < s->slots; n++) {
        if (s->tsd_transient[n]) {
          s->tsd_phase[n] = (int)aac_bitreader_read(r, 3);
        }
      }
      return 0;
    }
    default:
      return 0;
  }
}

/* ── dequantisation and the mixing matrices ─────────────────────── */

float wrap_angle(float a) {
  while (a > (float)M_PI) a -= 2.0f * (float)M_PI;
  while (a < -(float)M_PI) a += 2.0f * (float)M_PI;
  return a;
}

/* The one-to-two box of ISO/IEC 23003-1 6.5.3.2: amplitude factors from
 * the CLD, the rotation from the ICC, and with phase coding the overall
 * phase difference the downmix implies, smoothed when the frame asks. */
void ott_matrix(float cld_db, float icc, float ipd, int has_ipd, float* opd_state, int opd_smooth,
                float h[2][2][2]) {
  const double ratio = pow(10.0, cld_db / 10.0);
  const double c1 = sqrt(ratio / (1.0 + ratio));
  const double c2 = sqrt(1.0 / (1.0 + ratio));
  double rho = icc;
  if (rho > 1.0) rho = 1.0;
  if (rho < -1.0) rho = -1.0;
  const double alpha = 0.5 * acos(rho);
  const double beta = atan(tan(alpha) * (c2 - c1) / (c1 + c2));
  const double h11 = c1 * cos(alpha + beta), h12 = c1 * sin(alpha + beta);
  const double h21 = c2 * cos(-alpha + beta), h22 = c2 * sin(-alpha + beta);
  double phi_l = 0.0, phi_r = 0.0;
  if (has_ipd) {
    /* the downmix carries the phase of c1 + c2 e^{-j IPD} relative to the
     * left channel: undo it on the left, and the right lags by the IPD */
    double opd = -atan2(-c2 * sin(ipd), c1 + c2 * cos(ipd));
    if (opd_state) {
      if (opd_smooth) {
        const float d = wrap_angle((float)opd - *opd_state);
        opd = *opd_state + 0.5 * d;
      }
      *opd_state = wrap_angle((float)opd);
    }
    phi_l = opd;
    phi_r = opd - ipd;
  }
  const double cl = cos(phi_l), sl = sin(phi_l), cr = cos(phi_r), sr = sin(phi_r);
  h[0][0][0] = (float)(h11 * cl);
  h[0][0][1] = (float)(h11 * sl);
  h[0][1][0] = (float)(h12 * cl);
  h[0][1][1] = (float)(h12 * sl);
  h[1][0][0] = (float)(h21 * cr);
  h[1][0][1] = (float)(h21 * sr);
  h[1][1][0] = (float)(h22 * cr);
  h[1][1][1] = (float)(h22 * sr);
}

void set_matrices(AacMps212State* s) {
  for (int ps = 0; ps < s->num_sets; ps++) {
    for (int pb = 0; pb < s->num_bands; pb++) {
      const int ci = s->cld[ps][pb] + 15;
      const float cld = aac_mps212_cld_db[ci < 0 ? 0 : (ci > 30 ? 30 : ci)];
      const int ii = s->icc[ps][pb];
      const float icc = aac_mps212_icc_value[ii < 0 ? 0 : (ii > 7 ? 7 : ii)];
      const int has_ipd = s->cfg.phase_coding && s->phase_mode && pb < s->num_ipd_bands;
      const float ipd = (float)(s->ipd[ps][pb] * M_PI / 8.0);
      ott_matrix(cld, icc, ipd, has_ipd, &s->prev_opd[pb], s->opd_smoothing, s->h_set[ps + 1][pb]);
    }
  }
}

/* Linear interpolation of the matrices over the slots of the frame, from
 * the previous frame's last set (at slot -1) through the parameter slots;
 * after the last set the matrix holds. */
void interpolate_matrices(AacMps212State* s) {
  int prev_slot = -1;
  int set = 0;
  for (int n = 0; n < s->slots; n++) {
    while (set < s->num_sets && n > s->param_slot[set]) {
      prev_slot = s->param_slot[set];
      set++;
    }
    for (int pb = 0; pb < s->num_bands; pb++) {
      float* out = &s->h_slot[n][pb][0][0][0];
      if (set >= s->num_sets) {
        memcpy(out, s->h_set[s->num_sets][pb], sizeof(float) * 8);
        continue;
      }
      const float t = (float)(n - prev_slot) / (float)(s->param_slot[set] - prev_slot);
      const float* a = &s->h_set[set][pb][0][0][0];
      const float* b = &s->h_set[set + 1][pb][0][0][0];
      for (int i = 0; i < 8; i++) {
        out[i] = a[i] + t * (b[i] - a[i]);
      }
    }
  }
  if (s->cfg.high_rate_mode) {
    return;
  }
  /* low rate mode: first-order smoothing of the matrices in the bands the
   * frame flags; mode 1 continues the previous settings */
  static const float kTimes[4] = {64.0f, 128.0f, 256.0f, 512.0f};
  const int mode = s->smooth_mode[0];
  if (mode >= 2 && s->smooth_time[0] >= 0) {
    s->smoothing_delta = 1.0f / kTimes[s->smooth_time[0]];
  }
  for (int pb = 0; pb < s->num_bands; pb++) {
    if (mode == 0) {
      s->smoothing_on[pb] = 0;
    } else if (mode >= 2) {
      s->smoothing_on[pb] = s->smooth_band[0][pb];
    }
    float* sm = &s->h_smooth[pb][0][0][0];
    if (!s->smoothing_on[pb]) {
      memcpy(sm, s->h_slot[s->slots - 1][pb], sizeof(float) * 8);
      continue;
    }
    for (int n = 0; n < s->slots; n++) {
      float* cur = &s->h_slot[n][pb][0][0][0];
      for (int i = 0; i < 8; i++) {
        sm[i] += s->smoothing_delta * (cur[i] - sm[i]);
        cur[i] = sm[i];
      }
    }
  }
}

/* ── residual QMF analysis (ISO/IEC 14496-3 4.6.18.4 with B bands) ── */

void residual_qmf_init(AacMps212State* s, int bands) {
  s->residual_qmf_bands = bands;
  const double step = 64.0 / bands;
  for (int i = 0; i < 10 * bands; i++) {
    /* the prototype sampled at the band ratio, linearly interpolated at
     * the fractional positions of the 24-band bank */
    const double pos = i * step;
    int p0 = (int)pos;
    if (p0 > 639) p0 = 639;
    const double f = pos - p0;
    const double a = aac_sbr_qmf_window[p0];
    const double b = aac_sbr_qmf_window[p0 + 1 < 640 ? p0 + 1 : 639];
    s->res_window[i] = (float)(a + f * (b - a));
  }
  for (int k = 0; k < bands; k++) {
    for (int n = 0; n < 2 * bands; n++) {
      const double a = M_PI / (2.0 * bands) * (k + 0.5) * (2.0 * n - 0.5);
      s->res_cos[k][n] = (float)(step * cos(a));
      s->res_sin[k][n] = (float)(step * sin(a));
    }
  }
  memset(s->res_x, 0, sizeof(s->res_x));
}

void residual_qmf_slot(AacMps212State* s, const float* in, float (*W)[2]) {
  const int B = s->residual_qmf_bands;
  const int len = 10 * B;
  float* x = s->res_x;
  memmove(x + B, x, (size_t)(len - B) * sizeof(float));
  for (int n = 0; n < B; n++) {
    x[n] = in[B - 1 - n];
  }
  float u[64];
  for (int n = 0; n < 2 * B; n++) {
    float sum = 0.0f;
    for (int j = 0; j < 5; j++) {
      const int idx = n + 2 * B * j;
      sum += x[idx] * s->res_window[idx];
    }
    u[n] = sum;
  }
  for (int k = 0; k < B; k++) {
    float re = 0.0f, im = 0.0f;
    for (int n = 0; n < 2 * B; n++) {
      re += u[n] * s->res_cos[k][n];
      im += u[n] * s->res_sin[k][n];
    }
    W[k][0] = re;
    W[k][1] = im;
  }
  for (int k = B; k < 64; k++) {
    W[k][0] = W[k][1] = 0.0f;
  }
}

/* ── decorrelator (ISO/IEC 23003-1 6.6.2) ───────────────────────── */

int region_of(int hyb) {
  for (int r = 0; r < 4; r++) {
    if (hyb < aac_mps212_decorr_region_edge[r + 1]) {
      return r;
    }
  }
  return 3;
}

/* The all-pass denominators from the reflection coefficients (step-up
 * recursion); the numerator is the reversed denominator. */
void decorr_init(AacMps212State* s) {
  Decorr* d = &s->decorr;
  const int cfg = s->cfg.decorr_config > 2 ? 2 : s->cfg.decorr_config;
  for (int reg = 0; reg < 4; reg++) {
    const int order = aac_mps212_decorr_order[cfg][reg];
    float a[kMaxOrder + 1] = {1.0f};
    for (int m = 1; m <= order; m++) {
      const float k = aac_mps212_decorr_reflection[reg][m - 1];
      float next[kMaxOrder + 1];
      for (int i = 0; i <= m; i++) {
        const float cur = i < m ? a[i] : 0.0f;
        const float rev = (m - i) < m ? a[m - i] : 0.0f;
        next[i] = cur + k * rev;
      }
      memcpy(a, next, sizeof(float) * (size_t)(m + 1));
    }
    memcpy(d->a[reg], a, sizeof(a));
  }
}

/* One slot through pre-delay, the all-pass filter of the band's region
 * and the ducker per parameter band.  `gate` zeroes the filter input (a
 * transient slot steered past the decorrelator). */
void decorrelate_slot(AacMps212State* s, const float (*in)[2], float (*out)[2], int gate) {
  Decorr* d = &s->decorr;
  const int cfg = s->cfg.decorr_config > 2 ? 2 : s->cfg.decorr_config;
  float e_in[kMaxPb] = {0}, e_out[kMaxPb] = {0};
  for (int k = 0; k < kHyb; k++) {
    const int reg = region_of(k);
    const int delay = aac_mps212_decorr_delay[cfg][reg];
    const int order = aac_mps212_decorr_order[cfg][reg];
    const float* a = d->a[reg];
    float xr = gate ? 0.0f : in[k][0], xi = gate ? 0.0f : in[k][1];
    if (delay > 0) {
      int pos = d->delay_pos[k];
      const float yr = d->delay[k][pos][0], yi = d->delay[k][pos][1];
      d->delay[k][pos][0] = xr;
      d->delay[k][pos][1] = xi;
      pos++;
      d->delay_pos[k] = pos >= delay ? 0 : pos;
      xr = yr;
      xi = yi;
    }
    /* y[n] = sum_i a[N - i] x[n - i] - sum_{i >= 1} a[i] y[n - i] */
    float yr = a[order] * xr, yi = a[order] * xi;
    const int pos = d->pos[k];
    for (int i = 1; i <= order; i++) {
      const int idx = (pos - i + order) % order;
      yr += a[order - i] * d->xh[k][idx][0] - a[i] * d->yh[k][idx][0];
      yi += a[order - i] * d->xh[k][idx][1] - a[i] * d->yh[k][idx][1];
    }
    d->xh[k][pos][0] = xr;
    d->xh[k][pos][1] = xi;
    d->yh[k][pos][0] = yr;
    d->yh[k][pos][1] = yi;
    d->pos[k] = (pos + 1) % order;
    out[k][0] = yr;
    out[k][1] = yi;
    const int pb = s->kernel[k];
    e_in[pb] += in[k][0] * in[k][0] + in[k][1] * in[k][1];
    e_out[pb] += yr * yr + yi * yi;
  }
  /* ducker: the wet energy is held below the dry energy, both smoothed */
  const float alpha = 0.8f;
  for (int pb = 0; pb < s->num_bands; pb++) {
    d->e_in[pb] = alpha * d->e_in[pb] + (1.0f - alpha) * e_in[pb];
    d->e_out[pb] = alpha * d->e_out[pb] + (1.0f - alpha) * e_out[pb];
  }
  for (int k = 0; k < kHyb; k++) {
    const int pb = s->kernel[k];
    const float ein = d->e_in[pb], eout = d->e_out[pb];
    if (eout > 1.5f * ein + 1e-9f) {
      const float g = sqrtf(1.5f * ein / eout);
      out[k][0] *= g;
      out[k][1] *= g;
    }
  }
}

float band_energy(const float (*x)[2], int from) {
  float e = 0.0f;
  for (int k = from; k < kHyb; k++) {
    e += x[k][0] * x[k][0] + x[k][1] * x[k][1];
  }
  return e;
}

void slot_meta_fill(const AacMps212State* s, int n, SlotMeta* m) {
  memcpy(m->h, s->h_slot[n], sizeof(m->h));
  m->ges[0] = s->env_shape[0][n];
  m->ges[1] = s->env_shape[1][n];
  m->tsd = s->tsd_enable ? s->tsd_transient[n] : 0;
  m->tsd_phase = s->tsd_phase[n];
}

/* Mixes one hybrid slot: m (dry), w (wet) and the matrices of `meta`
 * into the two output channels, with the temporal shaping tools. */
void mix_slot(AacMps212State* s, const SlotMeta* meta) {
  const int shaping = s->temp_shape_enable;
  for (int k = 0; k < kHyb; k++) {
    const int pb = s->kernel[k];
    const float mr = s->m_hyb[k][0], mi = s->m_hyb[k][1];
    const float wr = s->w_hyb[k][0], wi = s->w_hyb[k][1];
    for (int ch = 0; ch < 2; ch++) {
      const float* hm = meta->h[pb][ch][0];
      const float* hw = meta->h[pb][ch][1];
      s->dry[ch][k][0] = hm[0] * mr - hm[1] * mi;
      s->dry[ch][k][1] = hm[0] * mi + hm[1] * mr;
      s->wet[ch][k][0] = hw[0] * wr - hw[1] * wi;
      s->wet[ch][k][1] = hw[0] * wi + hw[1] * wr;
    }
  }
  for (int ch = 0; ch < 2; ch++) {
    float wet_gain = 1.0f;
    if (shaping && s->cfg.temp_shape_config == 1 && s->env_shape_channel[ch]) {
      /* STP: the diffuse part follows the envelope of the direct part,
       * both normalised by their running means, above ~1.1 kHz */
      const float e_dry = band_energy(s->dry[ch], kShapeFirstBand);
      const float e_wet = band_energy(s->wet[ch], kShapeFirstBand);
      s->stp_dry_mean[ch] = 0.9f * s->stp_dry_mean[ch] + 0.1f * e_dry;
      s->stp_wet_mean[ch] = 0.9f * s->stp_wet_mean[ch] + 0.1f * e_wet;
      const float num = e_dry * s->stp_wet_mean[ch];
      const float den = e_wet * s->stp_dry_mean[ch];
      if (den > 1e-12f && num > 1e-12f) {
        wet_gain = sqrtf(num / den);
        if (wet_gain < 0.5f) wet_gain = 0.5f;
        if (wet_gain > 2.0f) wet_gain = 2.0f;
      }
    }
    for (int k = 0; k < kHyb; k++) {
      const float g = k >= kShapeFirstBand ? wet_gain : 1.0f;
      s->out_hyb[ch][k][0] = s->dry[ch][k][0] + g * s->wet[ch][k][0];
      s->out_hyb[ch][k][1] = s->dry[ch][k][1] + g * s->wet[ch][k][1];
    }
  }
  if (shaping && s->cfg.temp_shape_config == 2) {
    /* GES: the upper bands of a flagged channel follow the transmitted
     * ratio of its envelope to the downmix envelope */
    const float e_dmx = band_energy(s->m_hyb, kShapeFirstBand);
    s->ges_dmx_mean = 0.9f * s->ges_dmx_mean + 0.1f * e_dmx;
    const int mode = s->cfg.env_quant_mode ? 1 : 0;
    for (int ch = 0; ch < 2; ch++) {
      const float e_ch = band_energy(s->out_hyb[ch], kShapeFirstBand);
      s->ges_ch_mean[ch] = 0.9f * s->ges_ch_mean[ch] + 0.1f * e_ch;
      if (!s->env_shape_channel[ch]) {
        continue;
      }
      const float target = aac_mps212_ges_ratio[mode][meta->ges[ch] & 7];
      const float num = e_dmx * s->ges_ch_mean[ch];
      const float den = e_ch * s->ges_dmx_mean;
      if (den <= 1e-12f || num <= 1e-12f) {
        continue;
      }
      float g = target * sqrtf(num / den);
      if (g < 0.25f) g = 0.25f;
      if (g > 4.0f) g = 4.0f;
      for (int k = kShapeFirstBand; k < kHyb; k++) {
        s->out_hyb[ch][k][0] *= g;
        s->out_hyb[ch][k][1] *= g;
      }
    }
  }
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
  s->cfg = *cfg;
  s->stereo_config_index = stereo_config_index;
  s->frame_length = frame_length;
  s->output_frame_length = output_frame_length;
  s->sample_rate = sample_rate;
  s->slots = output_frame_length / 64;
  s->slot_bits = ceil_log2(s->slots);
  s->num_bands = num_bands;
  s->num_ipd_bands = cfg->ott_bands_phase_present ? cfg->ott_bands_phase : num_bands;
  if (s->num_ipd_bands > num_bands) {
    s->num_ipd_bands = num_bands;
  }
  s->kernel = aac_mps212_kernel(num_bands);
  s->fixed_gain = powf(10.0f, aac_mps212_fixed_gain_db[cfg->fixed_gain_dmx & 7] / 20.0f);
  if (cfg->residual_coding) {
    /* the residual's analysis bank: 64 bands times the core frame over
     * the output frame (2:1 -> 32, 8:3 -> 24, 4:1 -> 16 bands) */
    const int bands = frame_length > 0 ? frame_length * 64 / output_frame_length : 0;
    if (bands != 32 && bands != 24 && bands != 16) {
      free(s);
      return NULL;
    }
    residual_qmf_init(s, bands);
  }
  if (build_codebooks(s)) {
    free(s);
    return NULL;
  }
  decorr_init(s);
  aac_mps212_reset(s);
  return s;
}

void aac_mps212_destroy(AacMps212State* s) { free(s); }

void aac_mps212_reset(AacMps212State* s) {
  if (s == NULL) {
    return;
  }
  s->num_sets = 1;
  s->param_slot[0] = s->slots - 1;
  memset(s->cld, 0, sizeof(s->cld));
  memset(s->icc, 0, sizeof(s->icc));
  memset(s->ipd, 0, sizeof(s->ipd));
  memset(s->prev_cld, 0, sizeof(s->prev_cld));
  memset(s->prev_icc, 0, sizeof(s->prev_icc));
  memset(s->prev_ipd, 0, sizeof(s->prev_ipd));
  memset(s->data_mode, 0, sizeof(s->data_mode));
  s->have_prev = 0;
  s->phase_mode = 0;
  s->opd_smoothing = 0;
  s->temp_shape_enable = 0;
  s->tsd_enable = 0;
  memset(s->env_shape, 0, sizeof(s->env_shape));
  memset(s->tsd_transient, 0, sizeof(s->tsd_transient));
  memset(s->tsd_phase, 0, sizeof(s->tsd_phase));
  memset(s->smooth_mode, 0, sizeof(s->smooth_mode));
  memset(s->smoothing_on, 0, sizeof(s->smoothing_on));
  s->smoothing_delta = 1.0f / 64.0f;
  memset(s->hist, 0, sizeof(s->hist));
  memset(s->res_hist, 0, sizeof(s->res_hist));
  memset(s->res_ring, 0, sizeof(s->res_ring));
  s->res_ring_pos = 0;
  memset(s->prev_opd, 0, sizeof(s->prev_opd));
  memset(s->res_x, 0, sizeof(s->res_x));
  memset(s->decorr.delay, 0, sizeof(s->decorr.delay));
  memset(s->decorr.delay_pos, 0, sizeof(s->decorr.delay_pos));
  memset(s->decorr.xh, 0, sizeof(s->decorr.xh));
  memset(s->decorr.yh, 0, sizeof(s->decorr.yh));
  memset(s->decorr.pos, 0, sizeof(s->decorr.pos));
  memset(s->decorr.e_in, 0, sizeof(s->decorr.e_in));
  memset(s->decorr.e_out, 0, sizeof(s->decorr.e_out));
  s->stp_dry_mean[0] = s->stp_dry_mean[1] = 1.0f;
  s->stp_wet_mean[0] = s->stp_wet_mean[1] = 1.0f;
  s->ges_dmx_mean = 1.0f;
  s->ges_ch_mean[0] = s->ges_ch_mean[1] = 1.0f;
  s->frames = 0;
  /* the matrices of CLD 0 dB, ICC 1: an equal in-phase split */
  float h[2][2][2];
  ott_matrix(0.0f, 1.0f, 0.0f, 0, NULL, 0, h);
  for (int pb = 0; pb < kMaxPb; pb++) {
    for (int ps = 0; ps <= kMaxSets; ps++) {
      memcpy(s->h_set[ps][pb], h, sizeof(h));
    }
    memcpy(s->h_smooth[pb], h, sizeof(h));
    memcpy(s->h_prev[pb], h, sizeof(h));
    for (int n = 0; n < kHybDelay; n++) {
      memcpy(s->tail[n].h[pb], h, sizeof(h));
    }
  }
  for (int n = 0; n < kHybDelay; n++) {
    s->tail[n].ges[0] = s->tail[n].ges[1] = 0;
    s->tail[n].tsd = 0;
    s->tail[n].tsd_phase = 0;
  }
}

namespace {

struct ParamBackup {
  int num_sets;
  int param_slot[kMaxSets];
  int cld[kMaxSets][kMaxPb];
  int icc[kMaxSets][kMaxPb];
  int ipd[kMaxSets][kMaxPb];
  int phase_mode, opd_smoothing;
};

}  // namespace

int aac_mps212_read(AacMps212State* s, AacBitReader* r, int indep) {
  if (s == NULL || r == NULL) {
    return AAC_ERR_INVALID_ARG;
  }
  ParamBackup backup;
  backup.num_sets = s->num_sets;
  memcpy(backup.param_slot, s->param_slot, sizeof(s->param_slot));
  memcpy(backup.cld, s->cld, sizeof(s->cld));
  memcpy(backup.icc, s->icc, sizeof(s->icc));
  memcpy(backup.ipd, s->ipd, sizeof(s->ipd));
  backup.phase_mode = s->phase_mode;
  backup.opd_smoothing = s->opd_smoothing;
  /* the previous frame's last set is the reference of time differences */
  const int last = s->num_sets - 1;
  memcpy(s->prev_cld, s->cld[last], sizeof(s->prev_cld));
  memcpy(s->prev_icc, s->icc[last], sizeof(s->prev_icc));
  memcpy(s->prev_ipd, s->ipd[last], sizeof(s->prev_ipd));
  const int have_prev = s->have_prev;

  int ok = read_framing(s, r) == 0;
  if (ok) {
    s->independency = indep ? 1 : (int)aac_bitreader_read(r, 1);
    ok = read_ecdata(s, r, kCld, s->num_bands, s->cld, s->prev_cld, have_prev) == 0;
  }
  if (ok) {
    ok = read_ecdata(s, r, kIcc, s->num_bands, s->icc, s->prev_icc, have_prev) == 0;
  }
  if (ok) {
    if (s->cfg.phase_coding) {
      s->phase_mode = (int)aac_bitreader_read(r, 1);
      if (s->phase_mode) {
        s->opd_smoothing = (int)aac_bitreader_read(r, 1);
        ok = read_ecdata(s, r, kIpd, s->num_ipd_bands, s->ipd, s->prev_ipd, have_prev) == 0;
      } else {
        memset(s->ipd, 0, sizeof(s->ipd));
      }
    } else {
      s->phase_mode = 0;
    }
  }
  if (ok && !s->cfg.high_rate_mode) {
    ok = read_smoothing(s, r) == 0;
  }
  if (ok && s->cfg.temp_shape_config != 0) {
    ok = read_temp_shape(s, r) == 0;
  }
  if (ok && r->overread) {
    ok = 0;
  }
  if (!ok) {
    s->num_sets = backup.num_sets;
    memcpy(s->param_slot, backup.param_slot, sizeof(s->param_slot));
    memcpy(s->cld, backup.cld, sizeof(s->cld));
    memcpy(s->icc, backup.icc, sizeof(s->icc));
    memcpy(s->ipd, backup.ipd, sizeof(s->ipd));
    s->phase_mode = backup.phase_mode;
    s->opd_smoothing = backup.opd_smoothing;
    aac_mps212_conceal(s);
    return AAC_ERR_DECODE;
  }
  s->have_prev = 1;
  return AAC_OK;
}

void aac_mps212_conceal(AacMps212State* s) {
  if (s == NULL) {
    return;
  }
  /* one set at the end of the frame holding the last parameters */
  const int last = s->num_sets - 1;
  if (last > 0) {
    memcpy(s->cld[0], s->cld[last], sizeof(s->cld[0]));
    memcpy(s->icc[0], s->icc[last], sizeof(s->icc[0]));
    memcpy(s->ipd[0], s->ipd[last], sizeof(s->ipd[0]));
  }
  s->num_sets = 1;
  s->param_slot[0] = s->slots - 1;
  s->temp_shape_enable = 0;
  s->tsd_enable = 0;
  s->smooth_mode[0] = 1;
  for (int t = 0; t < 3; t++) {
    s->data_mode[t][0] = 1;
  }
}

void aac_mps212_set_residual_delay(AacMps212State* s, int slots) {
  if (s && slots >= 0 && slots < kResRing) {
    s->residual_delay = slots;
  }
}

void aac_mps212_apply(AacMps212State* s, const AacUsacQmfFrame* dmx, const float* residual,
                      AacUsacQmfFrame* left, AacUsacQmfFrame* right) {
  if (s == NULL || dmx == NULL || left == NULL || right == NULL) {
    return;
  }
  const int slots = s->slots;
  const int use_res = s->cfg.residual_coding && residual != NULL && s->residual_qmf_bands > 0;

  /* the residual: QMF analysis of the core frame (full scale 1.0 to the
   * QMF frame's 32768), delayed as configured */
  if (use_res) {
    const int B = s->residual_qmf_bands;
    float scaled[64];
    for (int n = 0; n < slots; n++) {
      for (int i = 0; i < B; i++) {
        scaled[i] = residual[n * B + i] * 32768.0f;
      }
      float W[64][2];
      residual_qmf_slot(s, scaled, W);
      memcpy(s->res_ring[s->res_ring_pos], W, sizeof(W));
      const int read = (s->res_ring_pos - s->residual_delay + kResRing) % kResRing;
      memcpy(s->res_frame[n], s->res_ring[read], sizeof(W));
      s->res_ring_pos = (s->res_ring_pos + 1) % kResRing;
    }
  }

  /* the matrices of the frame: set 0 is the previous frame's last set */
  memcpy(s->h_set[0], s->h_prev, sizeof(s->h_prev));
  set_matrices(s);
  interpolate_matrices(s);
  memcpy(s->h_prev, s->h_set[s->num_sets], sizeof(s->h_prev));

  const int res_pb = s->cfg.residual_bands;
  const float g = s->fixed_gain;
  const float inv_sqrt2 = 0.70710678f;
  SlotMeta meta;
  for (int n = 0; n < slots; n++) {
    /* push the slot into the histories (newest last) */
    memmove(s->hist[0], s->hist[1], sizeof(s->hist[0]) * (kHistory - 1));
    memmove(s->res_hist[0], s->res_hist[1], sizeof(s->res_hist[0]) * (kHistory - 1));
    for (int b = 0; b < 64; b++) {
      float mr = g * dmx->x[n][b][0], mi = g * dmx->x[n][b][1];
      float rr = use_res ? s->res_frame[n][b][0] : 0.0f;
      float ri = use_res ? s->res_frame[n][b][1] : 0.0f;
      if (use_res && s->cfg.pseudo_lr) {
        /* the pseudo left/right pair back to downmix and residual */
        const float sr = (mr + rr) * inv_sqrt2, si = (mi + ri) * inv_sqrt2;
        const float dr = (mr - rr) * inv_sqrt2, di = (mi - ri) * inv_sqrt2;
        mr = sr;
        mi = si;
        rr = dr;
        ri = di;
      }
      s->hist[kHistory - 1][b][0] = mr;
      s->hist[kHistory - 1][b][1] = mi;
      s->res_hist[kHistory - 1][b][0] = rr;
      s->res_hist[kHistory - 1][b][1] = ri;
    }
    /* the hybrid samples of slot n - 6 and that slot's parameters */
    if (n < kHybDelay) {
      meta = s->tail[n];
    } else {
      slot_meta_fill(s, n - kHybDelay, &meta);
    }
    hybrid_analysis(s->hist, s->m_hyb);
    if (use_res) {
      hybrid_analysis(s->res_hist, s->r_hyb);
    }
    decorrelate_slot(s, s->m_hyb, s->w_hyb, meta.tsd);
    if (meta.tsd) {
      /* transient steering: the transient reaches the wet signal through
       * a phase rotation instead of the decorrelator */
      const float phi = (float)(meta.tsd_phase * M_PI / 4.0);
      const float c = cosf(phi), sn = sinf(phi);
      for (int k = 0; k < kHyb; k++) {
        s->w_hyb[k][0] += c * s->m_hyb[k][0] - sn * s->m_hyb[k][1];
        s->w_hyb[k][1] += c * s->m_hyb[k][1] + sn * s->m_hyb[k][0];
      }
    }
    if (use_res) {
      for (int k = 0; k < kHyb; k++) {
        if (s->kernel[k] < res_pb && qmf_band_of(k) < s->residual_qmf_bands) {
          s->w_hyb[k][0] = s->r_hyb[k][0];
          s->w_hyb[k][1] = s->r_hyb[k][1];
        }
      }
    }
    mix_slot(s, &meta);
    hybrid_synthesis(s->out_hyb[0], left->x[n]);
    hybrid_synthesis(s->out_hyb[1], right->x[n]);
  }
  left->slots = slots;
  right->slots = slots;
  /* the last six slots' parameters serve the next frame's first slots */
  for (int n = 0; n < kHybDelay; n++) {
    slot_meta_fill(s, slots - kHybDelay + n, &s->tail[n]);
  }
  s->frames++;
}

/* ── test entry points ──────────────────────────────────────────── */

int aac_mps212_huff_code(const AacMps212State* s, int codebook, int symbol, uint32_t* code,
                         int* len) {
  if (s == NULL || codebook < 0 || codebook >= AAC_MPS212_CB_COUNT) {
    return -1;
  }
  const Codebook* cb = &s->cb[codebook];
  if (symbol < 0 || symbol >= cb->nsym) {
    return -1;
  }
  *code = s->entries[cb->first_entry + symbol].code;
  *len = s->entries[cb->first_entry + symbol].len;
  return 0;
}

int aac_mps212_codebook_info(const AacMps212State* s, int codebook, int* nsym, int* offset,
                             int* lav) {
  if (s == NULL || codebook < 0 || codebook >= AAC_MPS212_CB_COUNT) {
    return -1;
  }
  *nsym = s->cb[codebook].nsym;
  *offset = s->cb[codebook].offset;
  *lav = s->cb[codebook].lav;
  return 0;
}

void aac_mps212_ott_matrix(float cld_db, float icc, float ipd, int has_ipd, float h[2][2][2]) {
  ott_matrix(cld_db, icc, ipd, has_ipd, NULL, 0, h);
}

int aac_mps212_num_param_bands(const AacMps212State* s) { return s ? s->num_bands : 0; }
int aac_mps212_num_param_sets(const AacMps212State* s) { return s ? s->num_sets : 0; }
int aac_mps212_param_slot(const AacMps212State* s, int set) {
  return (s && set >= 0 && set < s->num_sets) ? s->param_slot[set] : -1;
}
int aac_mps212_param(const AacMps212State* s, int type, int set, int band) {
  if (s == NULL || set < 0 || set >= kMaxSets || band < 0 || band >= kMaxPb) {
    return 0;
  }
  switch (type) {
    case 0:
      return s->cld[set][band];
    case 1:
      return s->icc[set][band];
    default:
      return s->ipd[set][band];
  }
}
int aac_mps212_data_mode(const AacMps212State* s, int type, int set) {
  return (s && type >= 0 && type < 3 && set >= 0 && set < kMaxSets) ? s->data_mode[type][set] : -1;
}
int aac_mps212_phase_mode(const AacMps212State* s) { return s ? s->phase_mode : 0; }
int aac_mps212_smooth_mode(const AacMps212State* s, int set) {
  return (s && set >= 0 && set < kMaxSets) ? s->smooth_mode[set] : -1;
}
int aac_mps212_temp_shape_enabled(const AacMps212State* s) {
  return s ? (s->temp_shape_enable || s->tsd_enable) : 0;
}
int aac_mps212_env_shape_channel(const AacMps212State* s, int ch) {
  return (s && ch >= 0 && ch < 2) ? s->env_shape_channel[ch] : 0;
}
int aac_mps212_env_shape(const AacMps212State* s, int ch, int slot) {
  return (s && ch >= 0 && ch < 2 && slot >= 0 && slot < kMaxSlots) ? s->env_shape[ch][slot] : 0;
}
int aac_mps212_tsd_transient(const AacMps212State* s, int slot) {
  return (s && slot >= 0 && slot < kMaxSlots) ? s->tsd_transient[slot] : 0;
}
int aac_mps212_tsd_phase(const AacMps212State* s, int slot) {
  return (s && slot >= 0 && slot < kMaxSlots) ? s->tsd_phase[slot] : 0;
}

void aac_mps212_hybrid_analysis(const float (*in)[64][2], float (*out)[2]) {
  hybrid_tables_init();
  hybrid_analysis(in, out);
}

void aac_mps212_hybrid_synthesis(const float (*in)[2], float (*out)[2]) {
  hybrid_synthesis(in, out);
}

void aac_mps212_decorrelate(AacMps212State* s, const float (*in)[2], float (*out)[2]) {
  if (s) {
    decorrelate_slot(s, in, out, 0);
  }
}
