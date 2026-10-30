/* SpatialFrame() of MPEG Surround (ISO/IEC 23003-1 Table 44) and the
 * mapping of its parameters over the parameter sets and bands:
 *
 *   FramingInfo(): bsFramingType (1), bsNumParamSets (3; 1 in the
 *       low-delay form); with bsFramingType 1 one bsParamSlot per set,
 *       coded as the distance to the previous slot in as many bits as
 *       the slots left allow (a fixed ceil(log2(slots)) bits in the
 *       low-delay form); with 0 the sets sit at the fixed positions
 *       ceil(slots (i + 1) / sets) - 1.
 *   bsIndependencyFlag (1).
 *   OttData(): EcData(CLD) per OTT box over its bands; EcData(ICC) once
 *       over the parameter bands with bsOneIcc, else per box that is not
 *       an LFE box.
 *   TttData(): per TTT box and band range (the low range, and the high
 *       range with bsTttDualMode): EcData(CPC1), EcData(CPC2),
 *       EcData(ICC) in the prediction modes 0 and 1, EcData(CLD1),
 *       EcData(CLD2) in the energy modes 2 to 5.
 *   SmgData(): per set bsSmoothMode (2); modes 2 and 3 add bsSmoothTime
 *       (2); mode 3 adds bsFreqResStrideSmg (2) and one bsSmgData bit
 *       per band group.
 *   TempShapeData(): with bsTempShapeConfig 1 or 2 bsTempShapeEnable (1)
 *       and, when set, one bsEnvShapeChannel bit per shaped channel of
 *       the tree, config 2 followed by the run-length Huffman coded
 *       envelope of each flagged channel.
 *   ArbitraryDownmixData(): EcData(CLD) per input channel with
 *       bsArbitraryDownmix.
 *   ByteAlign() from the frame's start, then one SpatialExtensionFrame
 *       per configured extension: bsSacExtLen (8, + 16 when 255) and
 *       for the residual extension ResidualData(): per box with
 *       residual bands, for an OTT box per set bsIccDiffPresent (1) and
 *       with it a unary bsIccDiff per residual band, then the
 *       individual_channel_stream() of each residual frame, which the
 *       caller's core decoder reads through the residual callback.
 *
 * The parse works on a copy of the frame state and commits it when the
 * whole frame reads cleanly, so a damaged frame leaves the previous
 * frame's parameters in place. */
#include <cmath>
#include <cstdlib>
#include <cstring>

#include "mps212.h"
#include "mps_internal.h"

namespace {

constexpr int kMaxPb = AAC_MPS_MAX_PARAM_BANDS;
constexpr int kMaxSets = AAC_MPS_MAX_PARAM_SETS;
constexpr int kMaxCodedSets = AAC_MPS_MAX_PARAM_SETS - 1;

enum { kCld = AAC_MPS_PARAM_CLD, kIcc = AAC_MPS_PARAM_ICC, kCpc = AAC_MPS_PARAM_CPC };

/* Default indices of a bsDataMode 0 set: CPC 1.0, TTT CLD1 +150 dB. */
constexpr int kCpcDefault = 10;
constexpr int kTttCld1Default = 15;

/* The channels TempShapeData() flags per bsTempShapeConfig (1 STP, 2
 * GES) and tree (5151, 5152, 525), and the canonical output channel of
 * each flag: the bitstream orders them L, R, C, Ls, Rs, the subband
 * domain processing of the 525 tree L, R, Ls, Rs. */
constexpr int kStpChannels[3] = {5, 5, 4};
constexpr int kGesChannels[3] = {5, 5, 5};
constexpr int kStpOrder[3][5] = {{0, 1, 2, 4, 5}, {0, 1, 2, 4, 5}, {0, 1, 4, 5, -1}};
constexpr int kGesOrder[5] = {0, 1, 2, 4, 5};

/* OTT CLD defaults per tree: +150 dB where the first output takes
 * everything. */
constexpr int kOttCldDefault[3][AAC_MPS_MAX_OTT_BOXES] = {
    {15, 15, 0, 0, 15}, {15, 0, 15, 15, 15}, {15, 15, 15, 0, 0}};

int ceil_log2(int n) {
  int b = 0;
  while ((1 << b) < n) {
    b++;
  }
  return b;
}

int ec_flags(const AacMpsState* s) {
  return s->cfg.low_delay ? AAC_MPS_EC_NO_TIME_PAIRS : AAC_MPS_EC_PILOT;
}

void coarse_to_fine(int type, int* v, int start, int stop) {
  for (int i = start; i < stop; i++) {
    v[i] <<= 1;
    if (type == kCld) {
      if (v[i] == -14) v[i] = -15;
      if (v[i] == 14) v[i] = 15;
    }
  }
}

void fine_to_coarse(int* v, int start, int stop) {
  for (int i = start; i < stop; i++) {
    v[i] /= 2;
  }
}

bool in_range(int type, int v, int coarse) {
  switch (type) {
    case kCld:
      return coarse ? (v >= -7 && v <= 7) : (v >= -15 && v <= 15);
    case kIcc:
      return coarse ? (v >= 0 && v <= 3) : (v >= 0 && v <= 7);
    default:
      return coarse ? (v >= -10 && v <= 15) : (v >= -20 && v <= 30);
  }
}

/* The working copy of a frame parse: the streams read so far and the
 * framing.  The state keeps it (it holds the whole parameter state, too
 * much for the stack), cleared before each parse. */
using Frame = AacMpsFrameWork;

Frame& begin_parse(AacMpsState* s) {
  memset(&s->work, 0, sizeof(s->work));
  return s->work;
}

/* EcData() of one parameter stream over the bands [start, stop). */
int read_ecdata(const AacMpsState* s, Frame* f, AacBitReader* r, int stream, int type, int start,
                int stop, int default_value) {
  AacMpsEcStream* t = &f->st.stream[stream];
  const int bands = stop - start;
  if (bands <= 0) {
    return 0;
  }
  for (int i = 0; i < f->num_sets; i++) {
    t->mode[i] = (int)aac_bitreader_read(r, 2);
  }
  int set = 0;
  int pair = 0;
  int old_coarse = t->coarse_prev;
  for (int i = 0; i < f->num_sets; i++) {
    if (t->mode[i] == 0) {
      for (int b = start; b < stop; b++) {
        t->last[b] = default_value;
      }
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
    if (set + pair >= kMaxCodedSets) {
      return -1;
    }
    t->coded_coarse[set] = coarse;
    t->stride_idx[set] = stride_idx;
    if (coarse != old_coarse) {
      if (old_coarse) {
        coarse_to_fine(type, t->last, start, stop);
      } else {
        fine_to_coarse(t->last, start, stop);
      }
    }
    int map[kMaxPb + 1];
    const int groups = aac_mps_band_map(bands, aac_mps_stride[stride_idx], map);
    int history[kMaxPb];
    for (int g = 0; g < groups; g++) {
      history[g] = t->last[start + map[g]];
    }
    const int time_ref = !(f->independency && i == 0) || set > 0;
    if (aac_mps_ec_pair_read(r, type, t->cmp, set, groups, pair, coarse, time_ref, history,
                             ec_flags(s))) {
      return -1;
    }
    for (int k = set; k <= set + pair; k++) {
      for (int g = 0; g < groups; g++) {
        if (!in_range(type, t->cmp[k][g], coarse)) {
          return -1;
        }
      }
    }
    for (int g = 0; g < groups; g++) {
      const int v = t->cmp[set + pair][g];
      for (int b = map[g]; b < map[g + 1]; b++) {
        t->last[start + b] = v;
      }
    }
    old_coarse = coarse;
    if (pair) {
      t->coded_coarse[set + 1] = coarse;
      t->stride_idx[set + 1] = stride_idx;
    }
    set += pair + 1;
  }
  return 0;
}

/* The mapped fine indices of one stream over [start, stop): the coded
 * sets expanded over the bands, the default, kept and interpolated sets
 * filled in, the coarse sets on the fine scale, the frame-end set
 * appended when `extend`. */
int map_stream(Frame* f, int stream, int type, int start, int stop, int default_value,
               int extend, int (*out)[kMaxPb], const int (*diff)[kMaxPb]) {
  AacMpsEcStream* t = &f->st.stream[stream];
  const int bands = stop - start;
  if (bands <= 0) {
    for (int i = 0; i < f->num_sets + extend; i++) {
      for (int b = start; b < stop; b++) {
        out[i][b] = default_value;
      }
    }
    return 0;
  }
  int interp[kMaxSets] = {0};
  int set = 0;
  for (int i = 0; i < f->num_sets; i++) {
    t->set_coarse[i] = 0;
    switch (t->mode[i]) {
      case 0:
        for (int b = start; b < stop; b++) {
          out[i][b] = default_value;
          t->idx_prev[b] = default_value;
        }
        t->coarse_prev = 0;
        break;
      case 1:
      case 2:
        for (int b = start; b < stop; b++) {
          out[i][b] = t->idx_prev[b];
        }
        t->set_coarse[i] = t->coarse_prev;
        interp[i] = t->mode[i] == 2;
        break;
      default: {
        int map[kMaxPb + 1];
        const int groups = aac_mps_band_map(bands, aac_mps_stride[t->stride_idx[set]], map);
        for (int g = 0; g < groups; g++) {
          for (int b = map[g]; b < map[g + 1]; b++) {
            out[i][start + b] = t->cmp[set][g];
          }
        }
        for (int b = start; b < stop; b++) {
          t->idx_prev[b] = out[i][b];
        }
        t->coarse_prev = t->coded_coarse[set];
        t->set_coarse[i] = t->coarse_prev;
        set++;
        break;
      }
    }
    if (diff) {
      for (int b = start; b < stop; b++) {
        out[i][b] += diff[i][b];
      }
    }
  }
  for (int i = 0; i < f->num_sets; i++) {
    if (t->set_coarse[i]) {
      coarse_to_fine(type, out[i], start, stop);
    }
  }
  int i1 = -1;
  for (int i = 0; i < f->num_sets; i++) {
    if (!interp[i]) {
      i1 = i;
      continue;
    }
    int i2 = i;
    while (i2 < f->num_sets && interp[i2]) {
      i2++;
    }
    if (i2 >= f->num_sets) {
      return -1;
    }
    const int x1 = i1 < 0 ? 0 : f->param_slot[i1];
    const int lo = i1 < 0 ? 0 : i1;
    const int xi = f->param_slot[i];
    const int x2 = f->param_slot[i2];
    for (int b = start; b < stop; b++) {
      const int y1 = out[lo][b];
      const int y2 = out[i2][b];
      out[i][b] = x2 != x1 ? y1 + (xi - x1) * (y2 - y1) / (x2 - x1) : y1;
    }
  }
  for (int i = 0; i < f->num_sets; i++) {
    for (int b = start; b < stop; b++) {
      int v = out[i][b];
      if (type == kCld) {
        v = v < -15 ? -15 : (v > 15 ? 15 : v);
      } else if (type == kIcc) {
        v = v < 0 ? 0 : (v > 7 ? 7 : v);
      } else {
        v = v < -20 ? -20 : (v > 30 ? 30 : v);
      }
      out[i][b] = v;
    }
  }
  if (extend) {
    for (int b = start; b < stop; b++) {
      out[f->num_sets][b] = out[f->num_sets - 1][b];
    }
  }
  return 0;
}

/* ── bsQuantMode: the CLD scaling of the OTT boxes of 5151 and 5152 ── */

/* The level of a box output against the box input in dB, for a CLD
 * index. */
float output_level_db(int cld_idx) {
  const double ratio = pow(10.0, aac_mps_cld_db[cld_idx + 15] / 10.0);
  return (float)(10.0 * log10(ratio / (1.0 + ratio)));
}

/* The scale factor of a CLD index for a box input `ott_vs_tot_db` below
 * the total: 1 up to 1 dB below, then growing by 0.2 (mode 1) or 7/24
 * (mode 2) per dB, at most 5 or 8. */
float quant_factor(float ott_vs_tot_db, int mode) {
  const float db_diff = -ott_vs_tot_db;
  const float slope = mode == 1 ? 0.2f : 7.0f / 24.0f;
  const float max_factor = mode == 1 ? 5.0f : 8.0f;
  float f = 1.0f;
  if (db_diff > 1.0f) {
    f = 1.0f + (db_diff - 1.0f) * slope;
  }
  return f > max_factor ? max_factor : f;
}

/* Scales the mapped CLD indices of one box by the factor of its input
 * level (`in_db` per set and band), and gives the levels of its two
 * outputs. */
void apply_quant_mode(AacMpsFrameParams* p, int box, int bands, int num_sets, int mode,
                      const float (*in_db)[kMaxPb], float (*out1_db)[kMaxPb],
                      float (*out2_db)[kMaxPb]) {
  for (int ps = 0; ps < num_sets; ps++) {
    for (int pb = 0; pb < kMaxPb; pb++) {
      int idx = p->ott_cld[box][ps][pb];
      if (pb < bands && mode != 0) {
        const float factor = quant_factor(in_db[ps][pb], mode);
        idx = (int)floorf((float)idx * factor + 0.5f);
        idx = idx < -15 ? -15 : (idx > 15 ? 15 : idx);
        p->ott_cld[box][ps][pb] = idx;
      }
      out1_db[ps][pb] = output_level_db(idx) + in_db[ps][pb];
      out2_db[ps][pb] = output_level_db(-idx) + in_db[ps][pb];
    }
  }
}

/* ── SmgData() and TempShapeData() ──────────────────────────────── */

int read_smoothing(const AacMpsState* s, Frame* f, AacBitReader* r) {
  for (int ps = 0; ps < f->num_sets; ps++) {
    f->smooth_mode[ps] = (int)aac_bitreader_read(r, 2);
    if (f->smooth_mode[ps] >= 2) {
      f->smooth_time_idx[ps] = (int)aac_bitreader_read(r, 2);
    }
    if (f->smooth_mode[ps] == 3) {
      f->smooth_stride_idx[ps] = (int)aac_bitreader_read(r, 2);
      const int groups = (s->num_bands - 1) / aac_mps_stride[f->smooth_stride_idx[ps]] + 1;
      for (int g = 0; g < groups; g++) {
        f->smg_bits[ps][g] = (int)aac_bitreader_read(r, 1);
      }
    }
  }
  return 0;
}

void map_smoothing(const AacMpsState* s, Frame* f, AacMpsFrameParams* p, int extend) {
  for (int ps = 0; ps < f->num_sets; ps++) {
    switch (f->smooth_mode[ps]) {
      case 0:
        p->smooth_time[ps] = 256;
        memset(p->smoothing[ps], 0, sizeof(p->smoothing[ps]));
        break;
      case 1:
        if (ps > 0) {
          p->smooth_time[ps] = p->smooth_time[ps - 1];
          memcpy(p->smoothing[ps], p->smoothing[ps - 1], sizeof(p->smoothing[ps]));
        } else {
          p->smooth_time[ps] = f->st.prev_smooth_time;
          memcpy(p->smoothing[ps], f->st.prev_smoothing, sizeof(p->smoothing[ps]));
        }
        break;
      case 2:
        p->smooth_time[ps] = aac_mps212_smoothing_time[f->smooth_time_idx[ps]];
        for (int pb = 0; pb < kMaxPb; pb++) {
          p->smoothing[ps][pb] = pb < s->num_bands;
        }
        break;
      default: {
        p->smooth_time[ps] = aac_mps212_smoothing_time[f->smooth_time_idx[ps]];
        int map[kMaxPb + 1];
        const int groups =
            aac_mps_band_map(s->num_bands, aac_mps_stride[f->smooth_stride_idx[ps]], map);
        memset(p->smoothing[ps], 0, sizeof(p->smoothing[ps]));
        for (int g = 0; g < groups; g++) {
          for (int pb = map[g]; pb < map[g + 1]; pb++) {
            p->smoothing[ps][pb] = f->smg_bits[ps][g];
          }
        }
        break;
      }
    }
  }
  f->st.prev_smooth_time = p->smooth_time[f->num_sets - 1];
  memcpy(f->st.prev_smoothing, p->smoothing[f->num_sets - 1], sizeof(f->st.prev_smoothing));
  if (extend) {
    p->smooth_time[f->num_sets] = p->smooth_time[f->num_sets - 1];
    memcpy(p->smoothing[f->num_sets], p->smoothing[f->num_sets - 1],
           sizeof(p->smoothing[f->num_sets]));
  }
}

/* Guided envelope shaping: run-length pairs (value, run) of the
 * quantised envelope over the slots, dequantised to the ratio. */
int read_env_shape(const AacMpsState* s, Frame* f, AacBitReader* r, int ch) {
  int n = 0;
  while (n < s->slots) {
    const int sym = aac_mps_huff_read(r, aac_mps212_huff_env_shape, 39);
    if (sym == -2) {
      return -1;
    }
    const int value = sym < 0 ? 0 : sym >> 4;
    const int run = sym < 0 ? 2 : (sym & 15) + 1;
    if (value > 4) {
      return -1;
    }
    const float ratio = aac_mps_env_shape_value[s->cfg.env_quant_mode & 1][value];
    for (int i = n; i < n + run && i < s->slots; i++) {
      f->env_shape[ch][i] = ratio;
    }
    n += run;
  }
  return 0;
}

int read_temp_shape(const AacMpsState* s, Frame* f, AacBitReader* r) {
  f->temp_shape_enable = 0;
  memset(f->stp_channel, 0, sizeof(f->stp_channel));
  memset(f->ges_channel, 0, sizeof(f->ges_channel));
  if (s->cfg.temp_shape_config == 0) {
    return 0;
  }
  f->temp_shape_enable = (int)aac_bitreader_read(r, 1);
  if (!f->temp_shape_enable) {
    return 0;
  }
  if (s->cfg.temp_shape_config == 1) {
    for (int ch = 0; ch < s->num_stp_channels; ch++) {
      f->stp_channel[ch] = (int)aac_bitreader_read(r, 1);
    }
    return 0;
  }
  for (int ch = 0; ch < s->num_ges_channels; ch++) {
    f->ges_channel[ch] = (int)aac_bitreader_read(r, 1);
  }
  for (int ch = 0; ch < s->num_ges_channels; ch++) {
    if (f->ges_channel[ch] && read_env_shape(s, f, r, ch)) {
      return -1;
    }
  }
  return 0;
}

/* ── the extension frame ────────────────────────────────────────── */

int read_icc_diff(AacBitReader* r) {
  int value = 0;
  int count = 0;
  while (aac_bitreader_read(r, 1) == 0 && count++ < 7) {
    value++;
  }
  return value;
}

/* ResidualData(): the bsIccDiff values and, through the caller's
 * reader, the residual channel streams; without a reader the data is
 * left to the extension's length skip. */
int read_residual_data(AacMpsState* s, Frame* f, AacBitReader* r, int frame_start) {
  const int num_boxes = s->cfg.num_ott_boxes + s->cfg.num_ttt_boxes;
  const int upd_qmf = s->slots / s->residual_frames;
  f->residual_present = 1;
  for (int box = 0; box < num_boxes; box++) {
    if (!s->cfg.residual_present[box] || s->cfg.residual_bands[box] <= 0) {
      continue;
    }
    if (box < s->cfg.num_ott_boxes) {
      for (int ps = 0; ps < f->num_sets; ps++) {
        if (aac_bitreader_read(r, 1)) {
          for (int pb = 0; pb < s->cfg.residual_bands[box]; pb++) {
            f->icc_diff[box][ps][pb] = read_icc_diff(r);
          }
        }
      }
    }
    if (f->residual_pos < 0) {
      f->residual_pos = r->pos - frame_start;
    }
    if (s->residual_reader == NULL) {
      return 0; /* the rest of the extension is skipped by its length */
    }
    for (int rf = 0; rf < s->residual_frames; rf++) {
      const int ret = s->residual_reader(s->residual_ctx, r, box, rf, upd_qmf);
      if (ret != AAC_OK) {
        return -1;
      }
    }
  }
  return 0;
}

int read_extension_frame(AacMpsState* s, Frame* f, AacBitReader* r, int frame_start) {
  for (int i = 0; i < s->num_ext; i++) {
    int len = (int)aac_bitreader_read(r, 8);
    if (len == 255) {
      len += (int)aac_bitreader_read(r, 16);
    }
    if (aac_bitreader_bits_left(r) < 8 * len) {
      return -1;
    }
    const int start = r->pos;
    if (s->ext_type[i] == 0) {
      f->residual_end = start + 8 * len - frame_start;
      if (read_residual_data(s, f, r, frame_start)) {
        return -1;
      }
    }
    const int used = r->pos - start;
    if (used > 8 * len) {
      return -1;
    }
    aac_bitreader_skip(r, 8 * len - used);
  }
  return 0;
}

/* ── the mapping of a whole frame into the parameters ───────────── */

int map_frame(AacMpsState* s, Frame* f, AacMpsFrameParams* p) {
  const AacMpsConfig& cfg = s->cfg;
  const int extend = f->param_slot[f->num_sets - 1] != s->slots - 1;
  if (extend && f->num_sets >= kMaxSets) {
    return -1;
  }
  memset(p, 0, sizeof(*p));
  p->num_sets = f->num_sets + extend;
  memcpy(p->param_slot, f->param_slot, sizeof(int) * (size_t)f->num_sets);
  if (extend) {
    p->param_slot[f->num_sets] = s->slots - 1;
  }
  const int tree = cfg.tree_config;
  for (int box = 0; box < cfg.num_ott_boxes; box++) {
    if (map_stream(f, AAC_MPS_STREAM_OTT_CLD + box, kCld, 0, s->ott_bands[box],
                   s->ott_cld_default[box], extend, p->ott_cld[box], NULL)) {
      return -1;
    }
    for (int ps = 0; ps < p->num_sets; ps++) {
      for (int pb = s->ott_bands[box]; pb < kMaxPb; pb++) {
        p->ott_cld[box][ps][pb] = s->ott_cld_default[box];
      }
    }
    if (cfg.ott_lfe[box]) {
      continue;
    }
    if (cfg.one_icc && box > 0) {
      /* the one ICC stream maps per box, each with its own residual
       * differences and band count */
      AacMpsEcStream* dst = &f->st.stream[AAC_MPS_STREAM_OTT_ICC + box];
      const AacMpsEcStream* src = &f->st.stream[AAC_MPS_STREAM_OTT_ICC];
      memcpy(dst->cmp, src->cmp, sizeof(dst->cmp));
      memcpy(dst->mode, src->mode, sizeof(dst->mode));
      memcpy(dst->coded_coarse, src->coded_coarse, sizeof(dst->coded_coarse));
      memcpy(dst->stride_idx, src->stride_idx, sizeof(dst->stride_idx));
    }
    if (map_stream(f, AAC_MPS_STREAM_OTT_ICC + box, kIcc, 0, s->ott_bands[box], 0, extend,
                   p->ott_icc[box], f->residual_present ? f->icc_diff[box] : NULL)) {
      return -1;
    }
  }
  /* the CLD scaling of bsQuantMode follows the tree: each box's input
   * level against the total comes from its parent's outputs; the 525
   * tree is not scaled */
  if (tree == AAC_MPS_TREE_5151 || tree == AAC_MPS_TREE_5152) {
    float level[6][kMaxSets][kMaxPb]; /* total, then the box outputs in use */
    float(*tot)[kMaxPb] = level[0];
    float(*a1)[kMaxPb] = level[1];
    float(*a2)[kMaxPb] = level[2];
    float(*b1)[kMaxPb] = level[3];
    float(*b2)[kMaxPb] = level[4];
    float(*scratch)[kMaxPb] = level[5];
    memset(level, 0, sizeof(level));
    const int mode = cfg.quant_mode;
    /* box 0 splits the total; box 1 its first output; the LFE box takes
     * the total (a factor of 1); the others split what reaches them */
    apply_quant_mode(p, 0, s->ott_bands[0], p->num_sets, mode, tot, a1, a2);
    apply_quant_mode(p, 1, s->ott_bands[1], p->num_sets, mode, a1, b1, b2);
    if (tree == AAC_MPS_TREE_5151) {
      apply_quant_mode(p, 2, s->ott_bands[2], p->num_sets, mode, a2, scratch, scratch);
      apply_quant_mode(p, 3, s->ott_bands[3], p->num_sets, mode, b1, scratch, scratch);
      apply_quant_mode(p, 4, s->ott_bands[4], p->num_sets, mode, tot, scratch, scratch);
    } else {
      apply_quant_mode(p, 2, s->ott_bands[2], p->num_sets, mode, tot, scratch, scratch);
      apply_quant_mode(p, 3, s->ott_bands[3], p->num_sets, mode, b1, scratch, scratch);
      apply_quant_mode(p, 4, s->ott_bands[4], p->num_sets, mode, b2, scratch, scratch);
    }
  }
  for (int box = 0; box < cfg.num_ttt_boxes; box++) {
    for (int ps = 0; ps < p->num_sets; ps++) {
      for (int pb = 0; pb < kMaxPb; pb++) {
        p->ttt_cpc1[box][ps][pb] = kCpcDefault;
        p->ttt_cpc2[box][ps][pb] = kCpcDefault;
        p->ttt_icc[box][ps][pb] = 0;
        p->ttt_cld1[box][ps][pb] = kTttCld1Default;
        p->ttt_cld2[box][ps][pb] = 0;
      }
    }
    for (int range = 0; range < AAC_MPS_MAX_TTT_RANGES; range++) {
      const int start = s->ttt_start[range];
      const int stop = s->ttt_stop[range];
      if (start >= stop) {
        continue;
      }
      if (s->ttt_mode[range] < 2) {
        if (map_stream(f, AAC_MPS_STREAM_TTT_CPC1 + range, kCpc, start, stop, kCpcDefault, extend,
                       p->ttt_cpc1[box], NULL) ||
            map_stream(f, AAC_MPS_STREAM_TTT_CPC2 + range, kCpc, start, stop, kCpcDefault, extend,
                       p->ttt_cpc2[box], NULL) ||
            map_stream(f, AAC_MPS_STREAM_TTT_ICC + range, kIcc, start, stop, 0, extend,
                       p->ttt_icc[box], NULL)) {
          return -1;
        }
      } else if (map_stream(f, AAC_MPS_STREAM_TTT_CLD1 + range, kCld, start, stop,
                            kTttCld1Default, extend, p->ttt_cld1[box], NULL) ||
                 map_stream(f, AAC_MPS_STREAM_TTT_CLD2 + range, kCld, start, stop, 0, extend,
                            p->ttt_cld2[box], NULL)) {
        return -1;
      }
    }
  }
  map_smoothing(s, f, p, extend);
  /* the temporal shaping flags and envelopes in the canonical channel order */
  p->temp_shape_enable = f->temp_shape_enable;
  if (tree <= AAC_MPS_TREE_525) {
    for (int ch = 0; ch < s->num_stp_channels; ch++) {
      p->stp_channel[kStpOrder[tree][ch]] = f->stp_channel[ch];
    }
    for (int ch = 0; ch < s->num_ges_channels; ch++) {
      p->ges_channel[kGesOrder[ch]] = f->ges_channel[ch];
      memcpy(p->env_shape[kGesOrder[ch]], f->env_shape[ch], sizeof(p->env_shape[0]));
    }
  } else {
    memcpy(p->stp_channel, f->stp_channel, sizeof(int) * 2);
    memcpy(p->ges_channel, f->ges_channel, sizeof(int) * 2);
    memcpy(p->env_shape, f->env_shape, sizeof(p->env_shape[0]) * 2);
  }
  if (cfg.arbitrary_downmix) {
    for (int ch = 0; ch < cfg.num_input_channels; ch++) {
      if (map_stream(f, AAC_MPS_STREAM_ARBDMX + ch, kCld, 0, s->num_bands, 0, extend,
                     p->arbdmx_gain[ch], NULL)) {
        return -1;
      }
    }
  }
  return 0;
}

/* The parameters of a frame that was not read: one set at the frame's
 * end holding the last mapped values and the last smoothing. */
void params_from_history(AacMpsState* s) {
  Frame& f = begin_parse(s);
  AacMpsFrameParams& p = s->work_params;
  f.st = s->frame;
  f.num_sets = 1;
  f.param_slot[0] = s->slots - 1;
  f.independency = 0;
  f.smooth_mode[0] = 1;
  f.residual_pos = -1;
  for (int i = 0; i < AAC_MPS_NUM_STREAMS; i++) {
    f.st.stream[i].mode[0] = 1;
  }
  if (map_frame(s, &f, &p) == 0) {
    s->params = p;
    s->frame = f.st;
    s->independency = 0;
    s->residual_present = 0;
    s->residual_pos = s->residual_end = -1;
  }
}

}  // namespace

/* ── public API ─────────────────────────────────────────────────── */

AacMpsState* aac_mps_create(const AacMpsConfig* cfg, int core_frame_length, int qmf_slots,
                            int sample_rate) {
  if (cfg == NULL || cfg->frame_length < 1 || cfg->frame_length > AAC_MPS_MAX_SLOTS ||
      qmf_slots <= 0 || cfg->frame_length % qmf_slots != 0) {
    return NULL;
  }
  const int tree = cfg->tree_config;
  if (tree != AAC_MPS_TREE_5151 && tree != AAC_MPS_TREE_5152 && tree != AAC_MPS_TREE_525 &&
      tree != AAC_MPS_TREE_212) {
    return NULL;
  }
  const int bands = cfg->low_delay ? aac_mps_param_bands_ld(cfg->freq_res)
                                   : aac_mps_param_bands(cfg->freq_res);
  if (bands == 0 || cfg->num_ott_boxes > AAC_MPS_MAX_OTT_BOXES ||
      cfg->num_ttt_boxes > AAC_MPS_MAX_TTT_BOXES || cfg->num_extensions > AAC_MPS_MAX_EXTENSIONS ||
      cfg->temp_shape_config == 3) {
    return NULL;
  }
  AacMpsState* s = (AacMpsState*)calloc(1, sizeof(AacMpsState));
  if (s == NULL) {
    return NULL;
  }
  s->cfg = *cfg;
  s->core_frame_length = core_frame_length;
  s->qmf_slots = qmf_slots;
  s->sample_rate = sample_rate;
  s->slots = cfg->frame_length;
  s->num_bands = bands;
  for (int i = 0; i < cfg->num_ott_boxes; i++) {
    s->ott_bands[i] = cfg->ott_lfe[i] ? cfg->ott_bands[i] : bands;
    if (s->ott_bands[i] > bands) {
      s->ott_bands[i] = bands;
    }
    s->ott_cld_default[i] = tree <= AAC_MPS_TREE_525 ? kOttCldDefault[tree][i] : 0;
  }
  for (int i = 0; i < cfg->num_ttt_boxes; i++) {
    s->ttt_mode[0] = cfg->ttt_mode_low[i];
    s->ttt_mode[1] = cfg->ttt_mode_high[i];
    s->ttt_start[0] = 0;
    s->ttt_stop[0] = cfg->ttt_dual_mode[i] ? cfg->ttt_bands_low[i] : bands;
    s->ttt_start[1] = s->ttt_stop[0];
    s->ttt_stop[1] = bands;
  }
  if (tree <= AAC_MPS_TREE_525) {
    s->num_stp_channels = kStpChannels[tree];
    s->num_ges_channels = kGesChannels[tree];
  } else {
    s->num_stp_channels = s->num_ges_channels = 2;
  }
  s->num_ext = cfg->num_extensions;
  memcpy(s->ext_type, cfg->extension_type, sizeof(int) * (size_t)s->num_ext);
  s->residual_frames = cfg->residual_frames_per_spatial_frame > 0
                           ? cfg->residual_frames_per_spatial_frame
                           : 1;
  aac_mps_reset(s);
  return s;
}

void aac_mps_destroy(AacMpsState* s) {
  if (s) {
    aac_mps_glue_destroy(s->glue);
    free(s);
  }
}

const AacMpsConfig* aac_mps_config(const AacMpsState* s) { return s ? &s->cfg : NULL; }

void aac_mps_reset(AacMpsState* s) {
  if (s == NULL) {
    return;
  }
  memset(&s->frame, 0, sizeof(s->frame));
  s->frame.prev_smooth_time = 256;
  s->frame.num_sets_prev = 1;
  /* the histories start at the defaults of each stream */
  for (int box = 0; box < s->cfg.num_ott_boxes; box++) {
    AacMpsEcStream* t = &s->frame.stream[AAC_MPS_STREAM_OTT_CLD + box];
    for (int pb = 0; pb < kMaxPb; pb++) {
      t->last[pb] = t->idx_prev[pb] = s->ott_cld_default[box];
    }
  }
  for (int range = 0; range < AAC_MPS_MAX_TTT_RANGES; range++) {
    for (int pb = 0; pb < kMaxPb; pb++) {
      AacMpsEcStream* c1 = &s->frame.stream[AAC_MPS_STREAM_TTT_CPC1 + range];
      AacMpsEcStream* c2 = &s->frame.stream[AAC_MPS_STREAM_TTT_CPC2 + range];
      AacMpsEcStream* l1 = &s->frame.stream[AAC_MPS_STREAM_TTT_CLD1 + range];
      c1->last[pb] = c1->idx_prev[pb] = kCpcDefault;
      c2->last[pb] = c2->idx_prev[pb] = kCpcDefault;
      l1->last[pb] = l1->idx_prev[pb] = kTttCld1Default;
    }
  }
  s->frame_valid = 0;
  s->frame_pending = 0;
  aac_mps_glue_reset(s->glue);
  params_from_history(s);
}

void aac_mps_set_residual_reader(AacMpsState* s, AacMpsResidualReader reader, void* ctx) {
  if (s) {
    s->residual_reader = reader;
    s->residual_ctx = ctx;
  }
}

int aac_mps_read_frame(AacMpsState* s, AacBitReader* r) {
  if (s == NULL || r == NULL) {
    return AAC_ERR_INVALID_ARG;
  }
  const AacMpsConfig& cfg = s->cfg;
  const int frame_start = r->pos;
  Frame& f = begin_parse(s);
  AacMpsFrameParams& p = s->work_params;
  f.st = s->frame;
  f.st.num_sets_prev = s->params.num_sets;
  f.residual_pos = f.residual_end = -1;
  /* FramingInfo() */
  const int framing_type = (int)aac_bitreader_read(r, 1);
  f.num_sets = (int)aac_bitreader_read(r, cfg.low_delay ? 1 : 3) + 1;
  int prev_slot = -1;
  for (int i = 0; i < f.num_sets; i++) {
    int slot;
    if (framing_type) {
      if (cfg.low_delay) {
        slot = (int)aac_bitreader_read(r, ceil_log2(s->slots));
      } else {
        const int bits = ceil_log2(s->slots - f.num_sets + i - prev_slot);
        slot = prev_slot + 1 + (bits > 0 ? (int)aac_bitreader_read(r, bits) : 0);
      }
    } else {
      slot = (s->slots * (i + 1) + f.num_sets - 1) / f.num_sets - 1;
    }
    if (slot <= prev_slot || slot >= s->slots) {
      aac_mps_conceal(s);
      return AAC_ERR_DECODE;
    }
    f.param_slot[i] = slot;
    prev_slot = slot;
  }
  f.independency = (int)aac_bitreader_read(r, 1);
  int ok = 1;
  /* OttData() */
  for (int box = 0; ok && box < cfg.num_ott_boxes; box++) {
    ok = read_ecdata(s, &f, r, AAC_MPS_STREAM_OTT_CLD + box, kCld, 0, s->ott_bands[box],
                     s->ott_cld_default[box]) == 0;
  }
  if (ok && cfg.one_icc) {
    ok = read_ecdata(s, &f, r, AAC_MPS_STREAM_OTT_ICC, kIcc, 0, s->num_bands, 0) == 0;
  } else {
    for (int box = 0; ok && box < cfg.num_ott_boxes; box++) {
      if (!cfg.ott_lfe[box]) {
        ok = read_ecdata(s, &f, r, AAC_MPS_STREAM_OTT_ICC + box, kIcc, 0, s->ott_bands[box], 0) == 0;
      }
    }
  }
  /* TttData() */
  for (int box = 0; ok && box < cfg.num_ttt_boxes; box++) {
    for (int range = 0; ok && range < AAC_MPS_MAX_TTT_RANGES; range++) {
      const int start = s->ttt_start[range];
      const int stop = s->ttt_stop[range];
      if (start >= stop) {
        continue;
      }
      if (s->ttt_mode[range] < 2) {
        ok = read_ecdata(s, &f, r, AAC_MPS_STREAM_TTT_CPC1 + range, kCpc, start, stop, kCpcDefault) == 0 &&
             read_ecdata(s, &f, r, AAC_MPS_STREAM_TTT_CPC2 + range, kCpc, start, stop, kCpcDefault) == 0 &&
             read_ecdata(s, &f, r, AAC_MPS_STREAM_TTT_ICC + range, kIcc, start, stop, 0) == 0;
      } else {
        ok = read_ecdata(s, &f, r, AAC_MPS_STREAM_TTT_CLD1 + range, kCld, start, stop, kTttCld1Default) == 0 &&
             read_ecdata(s, &f, r, AAC_MPS_STREAM_TTT_CLD2 + range, kCld, start, stop, 0) == 0;
      }
    }
  }
  if (ok) {
    ok = read_smoothing(s, &f, r) == 0;
  }
  if (ok) {
    ok = read_temp_shape(s, &f, r) == 0;
  }
  if (ok && cfg.arbitrary_downmix) {
    for (int ch = 0; ok && ch < cfg.num_input_channels; ch++) {
      ok = read_ecdata(s, &f, r, AAC_MPS_STREAM_ARBDMX + ch, kCld, 0, s->num_bands, 0) == 0;
    }
  }
  if (ok) {
    const int align = (8 - (r->pos - frame_start) % 8) % 8;
    aac_bitreader_skip(r, align);
    ok = read_extension_frame(s, &f, r, frame_start) == 0;
  }
  if (ok && r->overread) {
    ok = 0;
  }
  if (ok) {
    ok = map_frame(s, &f, &p) == 0;
  }
  if (!ok) {
    aac_mps_conceal(s);
    return AAC_ERR_DECODE;
  }
  s->frame = f.st;
  s->params = p;
  s->frame_valid = 1;
  s->independency = f.independency;
  s->residual_present = f.residual_present;
  s->residual_pos = f.residual_pos;
  s->residual_end = f.residual_end;
  s->frame_pending = 1;
  return AAC_OK;
}

void aac_mps_conceal(AacMpsState* s) {
  if (s == NULL) {
    return;
  }
  params_from_history(s);
}

const AacMpsFrameParams* aac_mps_frame_params(const AacMpsState* s) {
  return s ? &s->params : NULL;
}
