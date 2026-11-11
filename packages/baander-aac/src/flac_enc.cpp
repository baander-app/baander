/* The FLAC encoder (RFC 9639): fixed blocks in the streamable subset.
 *
 * A block is coded channel by channel.  For each channel the encoder
 * removes the low bits that are zero in every sample, tries the forms a
 * subframe can take (constant, unencoded, a fixed predictor, a linear
 * predictor from the analysis of flac_lpc.cpp), codes the residual of
 * each predictor with the Rice parameters and the partition order that
 * take the fewest bits, and keeps the form whose exact size is
 * smallest.  For a stereo pair it does so for left, right, mid and side
 * as the level allows and stores the pair of them that is smallest.
 *
 * Every size the search compares is the number of bits the writer will
 * produce, and every residual is computed in integers, in 64 bits
 * wherever 32 could overflow.  A residual has to stay below 2^31 in
 * magnitude; a predictor that breaks this is dropped, and a subframe can
 * always fall back to unencoded samples, which bounds the size of a
 * frame. */
#include <algorithm>
#include <cassert>
#include <cstring>
#include <new>
#include <vector>

#include "flac_internal.h"

namespace {

/* ── levels ─────────────────────────────────────────────────────── */

enum StereoSearch {
  kStereoIndependent, /* left and right as they are */
  kStereoAdaptive,    /* one assignment, picked by an estimate */
  kStereoFull,        /* the smallest of the four by exact size */
};

/* What a level may search: the ceilings of the reference encoder's
 * preset of the same number, so that sizes compare like for like. */
struct Level {
  int block_size;
  int max_lpc_order; /* 0: fixed predictors only */
  StereoSearch stereo;
  int max_partition_order;
  int windows; /* analysis windows per subframe */
  /* Inside the ceilings the search is the encoder's own.  Per window it
   * quantises the predictor of every order at `precisions` coefficient
   * precisions from the highest down, estimates the size of each from
   * the error the quantised predictor leaves, and codes the `candidates`
   * that look smallest; what it keeps is decided by exact size. */
  int precisions;
  int candidates;
};

const int kMaxPrecisions = 6;

const Level kLevels[BAAC_FLAC_LEVEL_MAX + 1] = {
    {1152, 0, kStereoIndependent, 3, 0, 0, 0}, {1152, 0, kStereoAdaptive, 3, 0, 0, 0},
    {1152, 0, kStereoFull, 3, 0, 0, 0},        {4096, 6, kStereoIndependent, 4, 1, 6, 1},
    {4096, 8, kStereoAdaptive, 4, 1, 6, 1},    {4096, 8, kStereoFull, 5, 1, 6, 1},
    {4096, 8, kStereoFull, 6, 3, 6, 1},        {4096, 12, kStereoFull, 6, 3, 6, 1},
    {4096, 12, kStereoFull, 6, 9, 6, 1},
};

/* What a residual sample costs beyond the logarithm of its spread, in
 * the estimate that ranks predictor orders: about the entropy of a
 * two-sided geometric distribution of unit spread. */
const double kEstimateOffset = 1.9;

/* The encoder never divides a residual into more partitions than the
 * streamable subset allows. */
const int kMaxPartitionOrder = 8;
const int kMaxPartitions = 1 << kMaxPartitionOrder;
const int kMaxRiceParameter = 30; /* with 5-bit parameters; 14 with 4-bit ones */
const uint8_t kEscaped = 0xFF;    /* in place of a partition's Rice parameter */

/* A seek point for every ten seconds, and never more of them than a
 * decoder of this library keeps. */
const int kSeekSeconds = 10;

/* The taper of the analysis windows: the share of the smallest window of
 * a set that rises and falls. */
const double kWindowTaper = 0.5;

/* Blocks shorter than this are coded without linear prediction: there
 * is too little signal to estimate a predictor from. */
const int kMinLpcBlock = 32;

/* ── a subframe as it will be written ───────────────────────────── */

enum SubframeType { kConstant, kVerbatim, kFixed, kLpc };

struct Coding {
  SubframeType type = kVerbatim;
  int order = 0;
  int precision = 0;
  int shift = 0;
  int32_t coefs[FLAC_MAX_LPC_ORDER];
  int parameter_bits = 4; /* width of a Rice parameter */
  int partition_order = 0;
  uint8_t parameters[kMaxPartitions]; /* Rice parameter of each partition, or kEscaped */
  uint8_t widths[kMaxPartitions];     /* bits per value of an escaped partition */
  uint64_t bits = 0;                  /* the whole subframe */
};

/* One channel of a block as a candidate for storing: an input channel,
 * the mid or the side channel. */
struct Slot {
  const int32_t* samples = nullptr; /* wasted bits removed; null for a 33-bit channel */
  const int64_t* wide = nullptr;    /* a 33-bit side channel */
  int bps = 0;                      /* bits per sample without the wasted bits */
  int wasted = 0;
  Coding coding;
  std::vector<int32_t> shifted;  /* the samples when wasted bits were removed */
  std::vector<int32_t> residual; /* of `coding`, when it has one */
};

int ceil_log2(int n) {
  int bits = 0;
  while ((1 << bits) < n) {
    bits++;
  }
  return bits;
}

int bit_length(uint64_t v) { return v ? 64 - flac_clz64(v) : 0; }

/* Bits the header of a subframe takes before its samples. */
uint64_t subframe_header_bits(int wasted) { return 8 + (uint64_t)wasted; }

/* ── residual coding ────────────────────────────────────────────── */

/* Working memory of the Rice search, sized once for the largest block. */
struct RiceScratch {
  std::vector<uint32_t> folded; /* the residual, zigzag folded */
  std::vector<uint64_t> sums;   /* per parameter and partition: sum of folded >> parameter */
  std::vector<uint32_t> ors;    /* per partition: all folded values or-ed together */
  uint8_t parameters[kMaxPartitions];
  uint8_t widths[kMaxPartitions];
};

/* Finds the cheapest way to code residual[order .. n): the partition
 * order up to `max_partition_order`, and for each partition the Rice
 * parameter, or unencoded values where those are smaller.  The sizes are
 * exact: for every parameter that can be the best one of some partition
 * the unary parts are summed over the finest partitions, and coarser
 * partitions are sums of those.  Fills the residual fields of `c` and
 * returns the bits of the coded residual.
 *
 * Rice parameters above 14 need the 5-bit form, which `wide_parameters`
 * allows; it is used only when it comes out smaller. */
uint64_t rice_search(RiceScratch* rs, const int32_t* residual, int n, int order,
                     int max_partition_order, bool wide_parameters, Coding* c) {
  /* The finest partitioning: the block has to divide evenly, and every
   * partition has to hold more samples than the predictor's order. */
  int finest = std::min(max_partition_order, kMaxPartitionOrder);
  while (finest > 0 && ((n & ((1 << finest) - 1)) != 0 || (n >> finest) <= order)) {
    finest--;
  }
  int parts = 1 << finest;
  const int part_size = n >> finest;

  uint32_t* folded = rs->folded.data();
  uint64_t largest_mean = 0;
  uint64_t smallest_mean = UINT64_MAX;
  for (int p = 0; p < parts; p++) {
    const int begin = p ? p * part_size : order;
    const int end = (p + 1) * part_size;
    uint64_t sum = 0;
    uint32_t bits = 0;
    for (int i = begin; i < end; i++) {
      const uint32_t r = (uint32_t)residual[i];
      const uint32_t u = (r << 1) ^ (0u - (r >> 31));
      folded[i] = u;
      sum += u;
      bits |= u;
    }
    rs->ors[(size_t)p] = bits;
    const uint64_t count = (uint64_t)(end - begin);
    if (count) {
      const uint64_t mean = sum / count;
      largest_mean = std::max(largest_mean, mean);
      smallest_mean = std::min(smallest_mean, mean);
    }
  }
  if (smallest_mean == UINT64_MAX) {
    smallest_mean = 0;
  }

  /* The parameters worth counting exactly: the best one of a partition
   * lies next to the logarithm of its mean, and the mean of a coarser
   * partition lies between the means of the finest ones. */
  const int cap = wide_parameters ? kMaxRiceParameter : 14;
  int k_low = std::max(0, bit_length(smallest_mean) - 2);
  int k_high = std::min(cap, bit_length(largest_mean) + 1);
  k_low = std::min(k_low, k_high);
  const int k_count = k_high - k_low + 1;
  uint64_t* sums = rs->sums.data();
  for (int ki = 0; ki < k_count; ki++) {
    const int k = k_low + ki;
    for (int p = 0; p < parts; p++) {
      const int begin = p ? p * part_size : order;
      const int end = (p + 1) * part_size;
      uint64_t sum = 0;
      for (int i = begin; i < end; i++) {
        sum += folded[i] >> k;
      }
      sums[(size_t)ki * kMaxPartitions + (size_t)p] = sum;
    }
  }

  /* From the finest partitioning to a single partition, merging pairs
   * on the way; with 4-bit parameters, and with 5-bit ones when the
   * parameters reach beyond 14. */
  uint64_t best = UINT64_MAX;
  for (int po = finest; po >= 0; po--) {
    const int size = n >> po;
    for (int form = k_low > 14 ? 1 : 0; form < (k_high > 14 ? 2 : 1); form++) {
      const int parameter_bits = form ? 5 : 4;
      const int form_cap = form ? kMaxRiceParameter : 14;
      uint64_t total = 2 + 4;
      for (int p = 0; p < parts; p++) {
        const uint64_t count = (uint64_t)(p ? size : size - order);
        uint64_t smallest = UINT64_MAX;
        int parameter = 0;
        for (int ki = 0; ki < k_count && k_low + ki <= form_cap; ki++) {
          const uint64_t bits =
              count * (uint64_t)(k_low + ki + 1) + sums[(size_t)ki * kMaxPartitions + (size_t)p];
          if (bits < smallest) {
            smallest = bits;
            parameter = k_low + ki;
          }
        }
        /* Unencoded: every value in the bits the largest one takes. */
        const int width = bit_length(rs->ors[(size_t)p]);
        rs->parameters[p] = (uint8_t)parameter;
        rs->widths[p] = 0;
        if (width <= 31 && 5 + count * (uint64_t)width < smallest) {
          smallest = 5 + count * (uint64_t)width;
          rs->parameters[p] = kEscaped;
          rs->widths[p] = (uint8_t)width;
        }
        total += (uint64_t)parameter_bits + smallest;
        if (total >= best) {
          break;
        }
      }
      if (total < best) {
        best = total;
        c->parameter_bits = parameter_bits;
        c->partition_order = po;
        memcpy(c->parameters, rs->parameters, (size_t)parts);
        memcpy(c->widths, rs->widths, (size_t)parts);
      }
    }
    if (po > 0) {
      parts >>= 1;
      for (int ki = 0; ki < k_count; ki++) {
        uint64_t* row = sums + (size_t)ki * kMaxPartitions;
        for (int p = 0; p < parts; p++) {
          row[p] = row[2 * p] + row[2 * p + 1];
        }
      }
      for (int p = 0; p < parts; p++) {
        rs->ors[(size_t)p] = rs->ors[(size_t)(2 * p)] | rs->ors[(size_t)(2 * p + 1)];
      }
    }
  }
  return best;
}

void write_residual(FlacBitWriter* w, const Coding& c, const int32_t* residual, int n) {
  flac_bitwriter_write(w, c.parameter_bits == 5 ? 1 : 0, 2);
  flac_bitwriter_write(w, (uint32_t)c.partition_order, 4);
  const int parts = 1 << c.partition_order;
  const int size = n >> c.partition_order;
  for (int p = 0; p < parts; p++) {
    const int begin = p ? p * size : c.order;
    const int end = (p + 1) * size;
    if (c.parameters[p] == kEscaped) {
      flac_bitwriter_write(w, (1u << c.parameter_bits) - 1, c.parameter_bits);
      flac_bitwriter_write(w, c.widths[p], 5);
      if (c.widths[p]) {
        for (int i = begin; i < end; i++) {
          flac_bitwriter_write_signed(w, residual[i], c.widths[p]);
        }
      }
      continue;
    }
    const int k = c.parameters[p];
    flac_bitwriter_write(w, (uint32_t)k, c.parameter_bits);
    for (int i = begin; i < end; i++) {
      const uint32_t r = (uint32_t)residual[i];
      const uint32_t u = (r << 1) ^ (0u - (r >> 31));
      flac_bitwriter_write_unary(w, u >> k);
      flac_bitwriter_write(w, u, k);
    }
  }
}

/* ── prediction ─────────────────────────────────────────────────── */

/* The sums of the magnitudes of the residuals of the fixed predictors of
 * order 0 to `max_order`, over the samples every one of them predicts:
 * each order is the difference of the one below it. */
template <typename S>
void fixed_magnitudes(const S* x, int n, int max_order, uint64_t sums[FLAC_MAX_FIXED_ORDER + 1]) {
  int64_t previous[FLAC_MAX_FIXED_ORDER] = {0, 0, 0, 0};
  for (int k = 0; k <= FLAC_MAX_FIXED_ORDER; k++) {
    sums[k] = 0;
  }
  for (int i = 0; i < n; i++) {
    int64_t e = (int64_t)x[i];
    for (int k = 0; k <= max_order; k++) {
      if (i >= max_order) {
        sums[k] += (uint64_t)(e < 0 ? -e : e);
      }
      if (k < max_order) {
        const int64_t next = e - previous[k];
        previous[k] = e;
        e = next;
      }
    }
  }
}

/* A residual has to be above -2^31 and below 2^31 (RFC 9639 section
 * 9.2.7.3). */
inline bool residual_in_range(int64_t r) { return (uint64_t)(r + INT32_MAX) <= 0xFFFFFFFEull; }

/* The order of the fixed predictor whose residual is smallest in
 * magnitude, and that magnitude.  Low bits that are zero in every sample
 * scale all the magnitudes alike, so the choice holds with or without
 * them. */
template <typename S>
int best_fixed_order(const S* x, int n, uint64_t* magnitude) {
  const int max_order = std::min(FLAC_MAX_FIXED_ORDER, n - 1);
  uint64_t magnitudes[FLAC_MAX_FIXED_ORDER + 1];
  fixed_magnitudes(x, n, max_order, magnitudes);
  int order = 0;
  for (int k = 1; k <= max_order; k++) {
    if (magnitudes[k] < magnitudes[order]) {
      order = k;
    }
  }
  *magnitude = magnitudes[order];
  return order;
}

/* The residual of a fixed predictor into residual[order .. n).  False
 * when a value leaves the range a residual may have. */
template <typename S>
bool fixed_residual(const S* x, int n, int order, int32_t* residual) {
  static const int kWeights[FLAC_MAX_FIXED_ORDER + 1][FLAC_MAX_FIXED_ORDER] = {
      {0, 0, 0, 0}, {1, 0, 0, 0}, {2, -1, 0, 0}, {3, -3, 1, 0}, {4, -6, 4, -1}};
  const int* weights = kWeights[order];
  bool outside = false;
  for (int i = order; i < n; i++) {
    int64_t prediction = 0;
    for (int j = 0; j < order; j++) {
      prediction += (int64_t)weights[j] * (int64_t)x[i - 1 - j];
    }
    const int64_t r = (int64_t)x[i] - prediction;
    outside |= !residual_in_range(r);
    residual[i] = (int32_t)r;
  }
  return !outside;
}

/* The residual of a linear predictor into residual[order .. n), with
 * the sum of the products in 64 bits.  False when a value leaves the
 * range a residual may have. */
template <typename S>
bool lpc_residual(const S* x, int n, int order, const int32_t* coefs, int shift,
                  int32_t* residual) {
  bool outside = false;
  for (int i = order; i < n; i++) {
    int64_t sum = 0;
    for (int j = 0; j < order; j++) {
      sum += (int64_t)coefs[j] * (int64_t)x[i - 1 - j];
    }
    const int64_t r = (int64_t)x[i] - (sum >> shift);
    outside |= !residual_in_range(r);
    residual[i] = (int32_t)r;
  }
  return !outside;
}

/* The same in 32 bits, for the caller that knows the sum of the
 * products and the residual fit: the sample depth, the width of the
 * coefficients and the bits the summation adds stay within 32. */
void lpc_residual_32(const int32_t* x, int n, int order, const int32_t* coefs, int shift,
                     int32_t* residual) {
  for (int i = order; i < n; i++) {
    int32_t sum = 0;
    for (int j = 0; j < order; j++) {
      sum += coefs[j] * x[i - 1 - j];
    }
    residual[i] = x[i] - (sum >> shift);
  }
}

}  // namespace

/* ── encoder state ──────────────────────────────────────────────── */

struct BaacFlacEncoder_ {
  enum State { kIdle, kStreaming, kFinished };
  State state = kIdle;

  int sample_rate = 0;
  int channels = 0;
  int bits = 0;
  Level level = kLevels[BAAC_FLAC_LEVEL_DEFAULT];
  BaacFlacEncoderOptions options = {};

  /* The stream being written. */
  uint64_t frame_number = 0;
  uint64_t samples = 0;     /* per channel, in the frames written */
  uint64_t audio_bytes = 0; /* of the frames written */
  int min_frame_bytes = 0;
  int max_frame_bytes = 0;
  FlacMd5 md5;
  uint8_t digest[16] = {0};
  bool have_frame = false;
  BaacFlacFrameInfo last = {};
  std::vector<BaacFlacSeekPoint> seek_points; /* those found so far */
  int header_size = 0;                        /* of the stream's header, first and final */
  int padding = 0;                            /* bytes of its padding block */
  int seek_reserved = 0;                      /* points the header has room for */
  uint64_t seek_spacing = 0;                  /* samples from one target to the next */
  uint64_t seek_target = 0;                   /* the next sample position to point at */

  /* The block being filled: one row of `block_size` samples per channel. */
  std::vector<int32_t> input;
  int fill = 0;

  /* Analysis. */
  std::vector<Slot> slots;        /* the input channels; for a pair: left, right, mid, side */
  std::vector<int64_t> side_wide; /* the side channel of 32-bit audio */
  std::vector<int64_t> side_wide_shifted;
  std::vector<int32_t> residual; /* of the form being tried */
  RiceScratch rice;
  std::vector<double> signal;   /* a channel in floating point */
  std::vector<double> windowed; /* and under a window */
  std::vector<double> windows;  /* `level.windows` rows for blocks of `window_size` */
  double window_energy[FLAC_MAX_ANALYSIS_WINDOWS] = {0}; /* the sum of each window's squares */
  int window_size = 0;
};

namespace {

typedef BaacFlacEncoder_ Encoder;

/* The size no frame of `n` samples exceeds: every channel unencoded. */
size_t frame_bound(const Encoder* e, int n) {
  return FLAC_MAX_FRAME_HEADER_BYTES +
         ((size_t)e->channels * (8 + (size_t)n * (size_t)e->bits) + 7) / 8 + 2;
}

/* ── choosing a subframe ────────────────────────────────────────── */

/* Takes `candidate`, whose residual is in the encoder's scratch row,
 * when it is smaller than what the slot holds. */
void consider(Encoder* e, Slot* slot, const Coding& candidate) {
  if (candidate.bits < slot->coding.bits) {
    slot->coding = candidate;
    std::swap(slot->residual, e->residual);
  }
}

/* A predictor's residual is in the scratch row: codes it, adds the
 * subframe's other bits and offers the result to the slot. */
void finish_candidate(Encoder* e, Slot* slot, Coding* c, int n, uint64_t side_bits) {
  const bool wide_parameters = e->bits > 16;
  c->bits = subframe_header_bits(slot->wasted) + (uint64_t)c->order * (uint64_t)slot->bps +
            side_bits +
            rice_search(&e->rice, e->residual.data(), n, c->order, e->level.max_partition_order,
                        wide_parameters, c);
  consider(e, slot, *c);
}

/* The largest coefficient precision a linear predictor of this order
 * gets on samples of this depth.  A decoder may add up the products in
 * 32 bits when depth, precision and the bits of the summation stay
 * within 32; where that leaves a useful precision the encoder keeps to
 * it, and where it does not (above 16 bits or so) it takes the format's
 * 15 bits and the 64-bit sum they need. */
int precision_ceiling(int bps, int order) {
  const int within_32 = 32 - bps - ceil_log2(order);
  return within_32 >= 10 ? std::min(15, within_32) : 15;
}

/* Linear predictors for a channel.  For each analysis window the
 * autocorrelation and the recursion give a predictor of every order and
 * its prediction error.  From the error the size of each order is
 * estimated; the orders that look smallest are quantised, their
 * residuals computed and coded, and every subframe that results is
 * offered to the slot, which keeps the smallest by exact size. */
template <typename S>
void try_lpc(Encoder* e, Slot* slot, const S* x, int n) {
  const Level& level = e->level;
  const int max_order = std::min(level.max_lpc_order, n - 1);
  double* signal = e->signal.data();
  for (int i = 0; i < n; i++) {
    signal[i] = (double)x[i];
  }
  if (e->window_size != n) {
    for (int w = 0; w < level.windows; w++) {
      double* window = e->windows.data() + (size_t)w * (size_t)level.block_size;
      flac_lpc_window(window, n, level.windows, w, kWindowTaper);
      e->window_energy[w] = flac_lpc_energy(window, n);
    }
    e->window_size = n;
  }
  for (int w = 0; w < level.windows; w++) {
    double r[FLAC_MAX_LPC_ORDER + 1];
    double coefs[FLAC_MAX_LPC_ORDER * FLAC_MAX_LPC_ORDER];
    double errors[FLAC_MAX_LPC_ORDER];
    flac_lpc_apply_window(signal, e->windows.data() + (size_t)w * (size_t)level.block_size, n,
                          e->windowed.data());
    flac_lpc_autocorrelation(e->windowed.data(), n, max_order, r);
    const int orders = flac_lpc_levinson(r, max_order, coefs, errors);

    /* Every order at every precision the level looks at is quantised,
     * and its size estimated from the error the quantised predictor
     * leaves on the windowed signal: the residual's variance is that
     * error over the window's energy, a residual sample takes about half
     * the logarithm of the variance and a constant, never less than a
     * bit, and each order adds a warm-up sample and a coefficient. */
    struct Candidate {
      double estimate;
      int order;
      int precision;
      int shift;
      int32_t coefs[FLAC_MAX_LPC_ORDER];
    };
    Candidate candidates[FLAC_MAX_LPC_ORDER * kMaxPrecisions];
    int count = 0;
    for (int order = 1; order <= orders; order++) {
      const int ceiling = precision_ceiling(slot->bps, order);
      for (int precision = ceiling; precision > std::max(ceiling - level.precisions, 1);
           precision--) {
        Candidate& c = candidates[count];
        if (!flac_lpc_quantize(coefs + (size_t)(order - 1) * FLAC_MAX_LPC_ORDER, order, precision,
                               c.coefs, &c.shift)) {
          continue;
        }
        /* The precision written is the width the coefficients have. */
        uint32_t magnitudes = 0;
        for (int j = 0; j < order; j++) {
          magnitudes |= (uint32_t)(c.coefs[j] < 0 ? ~c.coefs[j] : c.coefs[j]);
        }
        c.order = order;
        c.precision = bit_length(magnitudes) + 1;
        c.estimate = flac_lpc_estimate_bits(flac_lpc_quantized_error(r, c.coefs, c.shift, order),
                                            e->window_energy[w], n - order, kEstimateOffset) +
                     (double)order * (double)(slot->bps + c.precision);
        count++;
      }
    }
    /* The candidates that look smallest are coded; exact size decides. */
    for (int pick = 0; pick < std::min(level.candidates, count); pick++) {
      int best = pick;
      for (int i = pick + 1; i < count; i++) {
        if (candidates[i].estimate < candidates[best].estimate) {
          best = i;
        }
      }
      std::swap(candidates[pick], candidates[best]);
      const Candidate& chosen = candidates[pick];
      Coding c;
      c.type = kLpc;
      c.order = chosen.order;
      c.precision = chosen.precision;
      c.shift = chosen.shift;
      memcpy(c.coefs, chosen.coefs, sizeof(int32_t) * (size_t)chosen.order);
      const int sum_bits = slot->bps + c.precision + ceil_log2(c.order);
      if (sizeof(S) == sizeof(int32_t) && sum_bits <= 32 && slot->bps <= 30 &&
          sum_bits - c.shift <= 30) {
        lpc_residual_32((const int32_t*)(const void*)x, n, c.order, c.coefs, c.shift,
                        e->residual.data());
      } else if (!lpc_residual(x, n, c.order, c.coefs, c.shift, e->residual.data())) {
        continue;
      }
      finish_candidate(e, slot, &c, n, 4 + 5 + (uint64_t)c.order * (uint64_t)c.precision);
    }
  }
}

/* Finds the smallest subframe for the `n` samples of `x`, which have
 * `bps` bits each, and leaves it in the slot.  `fixed_order` is the
 * fixed predictor to try when the caller has picked it already, or -1. */
template <typename S>
void analyse(Encoder* e, Slot* slot, const S* x, S* shifted, int n, int bps, int fixed_order = -1) {
  /* Low bits that are zero in every sample are not coded. */
  uint64_t any = 0;
  bool constant = true;
  for (int i = 0; i < n; i++) {
    any |= (uint64_t)(int64_t)x[i];
    constant = constant && x[i] == x[0];
  }
  int wasted = 0;
  if (any) {
    while (!((any >> wasted) & 1)) {
      wasted++;
    }
    wasted = std::min(wasted, bps - 1);
  }
  if (wasted) {
    for (int i = 0; i < n; i++) {
      shifted[i] = (S)(x[i] >> wasted);
    }
    x = shifted;
  }
  slot->wasted = wasted;
  slot->bps = bps - wasted;
  slot->samples = sizeof(S) == sizeof(int32_t) ? (const int32_t*)(const void*)x : nullptr;
  slot->wide = sizeof(S) == sizeof(int64_t) ? (const int64_t*)(const void*)x : nullptr;

  Coding& best = slot->coding;
  best = Coding();
  if (constant) {
    best.type = kConstant;
    best.bits = subframe_header_bits(wasted) + (uint64_t)slot->bps;
    return;
  }
  best.type = kVerbatim;
  best.bits = subframe_header_bits(wasted) + (uint64_t)n * (uint64_t)slot->bps;

  /* The fixed predictor whose residual is smallest in magnitude. */
  if (fixed_order < 0) {
    uint64_t magnitude;
    fixed_order = best_fixed_order(x, n, &magnitude);
  }
  if (fixed_residual(x, n, fixed_order, e->residual.data())) {
    Coding c;
    c.type = kFixed;
    c.order = fixed_order;
    finish_candidate(e, slot, &c, n, 0);
  }

  if (e->level.max_lpc_order > 0 && n >= kMinLpcBlock) {
    try_lpc(e, slot, x, n);
  }
}

/* ── writing ────────────────────────────────────────────────────── */

void write_subframe(FlacBitWriter* w, const Slot& slot, int n) {
  const Coding& c = slot.coding;
  const int type_code = c.type == kConstant   ? 0
                        : c.type == kVerbatim ? 1
                        : c.type == kFixed    ? 8 + c.order
                                              : 31 + c.order;
  flac_bitwriter_write(w, (uint32_t)type_code << 1 | (slot.wasted ? 1 : 0), 8);
  if (slot.wasted) {
    flac_bitwriter_write_unary(w, (uint32_t)slot.wasted - 1);
  }
  const auto sample = [&slot](int i) {
    return slot.wide ? slot.wide[i] : (int64_t)slot.samples[i];
  };
  if (c.type == kConstant) {
    flac_bitwriter_write_signed(w, sample(0), slot.bps);
    return;
  }
  if (c.type == kVerbatim) {
    for (int i = 0; i < n; i++) {
      flac_bitwriter_write_signed(w, sample(i), slot.bps);
    }
    return;
  }
  for (int i = 0; i < c.order; i++) {
    flac_bitwriter_write_signed(w, sample(i), slot.bps);
  }
  if (c.type == kLpc) {
    flac_bitwriter_write(w, (uint32_t)c.precision - 1, 4);
    flac_bitwriter_write(w, (uint32_t)c.shift, 5);
    for (int i = 0; i < c.order; i++) {
      flac_bitwriter_write_signed(w, c.coefs[i], c.precision);
    }
  }
  write_residual(w, c, slot.residual.data(), n);
}

/* A slot that stores its channel unencoded, whatever was found for it. */
void make_verbatim(Slot* slot, const int32_t* x, int n, int bps) {
  slot->samples = x;
  slot->wide = nullptr;
  slot->wasted = 0;
  slot->bps = bps;
  slot->coding = Coding();
  slot->coding.type = kVerbatim;
  slot->coding.bits = subframe_header_bits(0) + (uint64_t)n * (uint64_t)bps;
}

/* Whether a stereo assignment stores the channel: 0 left, 1 right, 2
 * mid, 3 side. */
bool assignment_uses(int assignment, int channel) {
  switch (channel) {
    case 0:
      return assignment == FLAC_ASSIGN_INDEPENDENT || assignment == FLAC_ASSIGN_LEFT_SIDE;
    case 1:
      return assignment == FLAC_ASSIGN_INDEPENDENT || assignment == FLAC_ASSIGN_SIDE_RIGHT;
    case 2:
      return assignment == FLAC_ASSIGN_MID_SIDE;
    default:
      return assignment != FLAC_ASSIGN_INDEPENDENT;
  }
}

/* Codes the block of `n` samples in the input rows as one frame into
 * `out`, which holds frame_bound(e, n) bytes, and returns its length. */
size_t encode_block(Encoder* e, int n, uint8_t* out) {
  const int block = e->level.block_size;
  int32_t* rows = e->input.data();
  int assignment = FLAC_ASSIGN_INDEPENDENT;
  Slot* coded[BAAC_FLAC_MAX_CHANNELS];

  if (e->channels == 2 && e->level.stereo != kStereoIndependent) {
    /* Mid and side (RFC 9639 section 4.2).  The side channel has one
     * bit more than the input, 33 for 32-bit audio. */
    const int32_t* left = rows;
    const int32_t* right = rows + block;
    Slot* slot_left = &e->slots[0];
    Slot* slot_right = &e->slots[1];
    Slot* slot_mid = &e->slots[2];
    Slot* slot_side = &e->slots[3];
    int32_t* mid_row = e->input.data() + (size_t)2 * (size_t)block;
    int32_t* side_row = e->input.data() + (size_t)3 * (size_t)block;
    const bool wide = e->bits == 32;
    for (int i = 0; i < n; i++) {
      const int64_t l = left[i];
      const int64_t r = right[i];
      mid_row[i] = (int32_t)((l + r) >> 1);
      if (wide) {
        e->side_wide[(size_t)i] = l - r;
      } else {
        side_row[i] = (int32_t)(l - r);
      }
    }
    bool use[4] = {true, true, true, true};
    int fixed_orders[4] = {-1, -1, -1, -1};
    if (e->level.stereo == kStereoAdaptive) {
      /* A rough measure of each channel: the magnitude of the residual
       * of its best fixed predictor.  The two assignments that look
       * cheapest by it are coded, which takes three of the four channels
       * as a rule, and the exact sizes decide between them. */
      uint64_t rough[4];
      fixed_orders[0] = best_fixed_order(left, n, &rough[0]);
      fixed_orders[1] = best_fixed_order(right, n, &rough[1]);
      fixed_orders[2] = best_fixed_order((const int32_t*)mid_row, n, &rough[2]);
      fixed_orders[3] = wide ? best_fixed_order((const int64_t*)e->side_wide.data(), n, &rough[3])
                             : best_fixed_order((const int32_t*)side_row, n, &rough[3]);
      const uint64_t costs[4] = {rough[0] + rough[1], rough[0] + rough[3], rough[3] + rough[1],
                                 rough[2] + rough[3]};
      int first = 0;
      for (int a = 1; a < 4; a++) {
        if (costs[a] < costs[first]) {
          first = a;
        }
      }
      int second = first ? 0 : 1;
      for (int a = 0; a < 4; a++) {
        if (a != first && costs[a] < costs[second]) {
          second = a;
        }
      }
      for (int c = 0; c < 4; c++) {
        use[c] = assignment_uses(first, c) || assignment_uses(second, c);
      }
    }
    const uint64_t unused = UINT64_MAX / 4;
    if (use[0]) {
      analyse(e, slot_left, left, slot_left->shifted.data(), n, e->bits, fixed_orders[0]);
    }
    if (use[1]) {
      analyse(e, slot_right, right, slot_right->shifted.data(), n, e->bits, fixed_orders[1]);
    }
    if (use[2]) {
      analyse(e, slot_mid, (const int32_t*)mid_row, slot_mid->shifted.data(), n, e->bits,
              fixed_orders[2]);
    }
    if (use[3]) {
      if (wide) {
        analyse(e, slot_side, (const int64_t*)e->side_wide.data(), e->side_wide_shifted.data(), n,
                e->bits + 1, fixed_orders[3]);
      } else {
        analyse(e, slot_side, (const int32_t*)side_row, slot_side->shifted.data(), n, e->bits + 1,
                fixed_orders[3]);
      }
    }
    const uint64_t bl = use[0] ? slot_left->coding.bits : unused;
    const uint64_t br = use[1] ? slot_right->coding.bits : unused;
    const uint64_t bm = use[2] ? slot_mid->coding.bits : unused;
    const uint64_t bs = use[3] ? slot_side->coding.bits : unused;
    const uint64_t sizes[4] = {bl + br, bl + bs, bs + br, bm + bs};
    for (int a = 1; a < 4; a++) {
      if (sizes[a] < sizes[assignment]) {
        assignment = a;
      }
    }
    /* Never more than the two channels unencoded, which is what the
     * published bound of a frame counts on. */
    if (sizes[assignment] > 2 * (8 + (uint64_t)n * (uint64_t)e->bits)) {
      assignment = FLAC_ASSIGN_INDEPENDENT;
      make_verbatim(slot_left, left, n, e->bits);
      make_verbatim(slot_right, right, n, e->bits);
    }
    coded[0] = assignment == FLAC_ASSIGN_INDEPENDENT || assignment == FLAC_ASSIGN_LEFT_SIDE
                   ? slot_left
               : assignment == FLAC_ASSIGN_SIDE_RIGHT ? slot_side
                                                      : slot_mid;
    coded[1] = assignment == FLAC_ASSIGN_INDEPENDENT || assignment == FLAC_ASSIGN_SIDE_RIGHT
                   ? slot_right
                   : slot_side;
  } else {
    for (int ch = 0; ch < e->channels; ch++) {
      Slot* slot = &e->slots[(size_t)ch];
      analyse(e, slot, (const int32_t*)rows + (size_t)ch * (size_t)block, slot->shifted.data(), n,
              e->bits);
      coded[ch] = slot;
    }
  }

  FlacFrameHeader h = {};
  h.variable_block_size = false;
  h.block_size = n;
  h.sample_rate = e->sample_rate;
  h.channels = e->channels;
  h.assignment = assignment;
  h.bits_per_sample = e->bits;
  h.number = e->frame_number;
  const int header_bytes = flac_frame_header_write(&h, out);
  FlacBitWriter w;
  flac_bitwriter_init(&w, out + header_bytes, frame_bound(e, n) - (size_t)header_bytes - 2);
  for (int ch = 0; ch < e->channels; ch++) {
    write_subframe(&w, *coded[ch], n);
  }
  uint64_t counted = 0;
  for (int ch = 0; ch < e->channels; ch++) {
    counted += coded[ch]->coding.bits;
  }
  /* What was written is what the search counted. */
  assert(flac_bitwriter_bits(&w) == counted);
  (void)counted;
  const size_t body = flac_bitwriter_flush(&w);
  const size_t crc_pos = (size_t)header_bytes + body;
  const uint16_t crc = flac_crc16(out, crc_pos);
  out[crc_pos] = (uint8_t)(crc >> 8);
  out[crc_pos + 1] = (uint8_t)crc;
  return crc_pos + 2;
}

/* Books a finished frame: the stream's totals, the frame for the
 * caller, and a seek point when the frame holds the next target. */
void account_frame(Encoder* e, int n, size_t bytes) {
  e->last.sample_position = e->samples;
  e->last.block_size = n;
  e->last.bytes = (int)bytes;
  e->have_frame = true;
  if (e->seek_reserved && e->seek_target < e->samples + (uint64_t)n &&
      (int)e->seek_points.size() < e->seek_reserved) {
    BaacFlacSeekPoint point;
    point.sample_position = e->samples;
    point.byte_offset = e->audio_bytes;
    point.block_size = n;
    e->seek_points.push_back(point);
    /* The targets this frame holds are all served by this point. */
    const uint64_t end = e->samples + (uint64_t)n;
    e->seek_target +=
        ((end - e->seek_target + e->seek_spacing - 1) / e->seek_spacing) * e->seek_spacing;
  }
  e->min_frame_bytes = e->frame_number ? std::min(e->min_frame_bytes, (int)bytes) : (int)bytes;
  e->max_frame_bytes = std::max(e->max_frame_bytes, (int)bytes);
  e->frame_number++;
  e->samples += (uint64_t)n;
  e->audio_bytes += bytes;
}

/* How many seek points a stream of `total` samples gets, and how far
 * apart their targets lie. */
int seek_point_count(const Encoder* e, uint64_t* spacing) {
  const uint64_t total = e->options.total_samples_hint;
  if (!e->options.seek_table || total == 0) {
    *spacing = 0;
    return 0;
  }
  uint64_t step = (uint64_t)kSeekSeconds * (uint64_t)e->sample_rate;
  step =
      std::max<uint64_t>(step, (total + BAAC_FLAC_MAX_SEEK_POINTS - 1) / BAAC_FLAC_MAX_SEEK_POINTS);
  *spacing = step;
  return (int)((total + step - 1) / step);
}

void stream_info(const Encoder* e, BaacFlacStreamInfo* info, bool final) {
  *info = BaacFlacStreamInfo();
  info->min_block_size = e->level.block_size;
  info->max_block_size = e->level.block_size;
  info->sample_rate = e->sample_rate;
  info->channels = e->channels;
  info->bits_per_sample = e->bits;
  if (final) {
    info->min_frame_bytes = e->min_frame_bytes;
    info->max_frame_bytes = e->max_frame_bytes;
    /* A total beyond 36 bits cannot be stated and is left unknown. */
    info->total_samples = e->samples >> 36 ? 0 : e->samples;
    memcpy(info->md5, e->digest, 16);
  }
}

int header_bytes(const Encoder* e) {
  uint64_t spacing;
  const int points = seek_point_count(e, &spacing);
  return FLAC_MARKER_BYTES + FLAC_BLOCK_HEADER_BYTES + BAAC_FLAC_STREAMINFO_BYTES +
         (points ? FLAC_BLOCK_HEADER_BYTES + points * FLAC_SEEK_POINT_BYTES : 0) +
         (e->options.padding_bytes ? FLAC_BLOCK_HEADER_BYTES + e->options.padding_bytes : 0);
}

uint8_t* put_block_header(uint8_t* p, int type, bool last, size_t size) {
  *p++ = (uint8_t)((last ? 0x80 : 0) | type);
  *p++ = (uint8_t)(size >> 16);
  *p++ = (uint8_t)(size >> 8);
  *p++ = (uint8_t)size;
  return p;
}

uint8_t* put_be(uint8_t* p, uint64_t v, int bytes) {
  for (int i = bytes - 1; i >= 0; i--) {
    *p++ = (uint8_t)(v >> (8 * i));
  }
  return p;
}

/* The marker and the metadata blocks: STREAMINFO, the seek table when
 * one is reserved, padding when asked for.  The first header of a
 * stream and the final one differ in STREAMINFO and in the seek points
 * that are filled in, never in length. */
void write_header(const Encoder* e, uint8_t* out, bool final) {
  const int points = e->seek_reserved;
  const int padding = e->padding;
  uint8_t* p = out;
  memcpy(p, "fLaC", FLAC_MARKER_BYTES);
  p += FLAC_MARKER_BYTES;
  p = put_block_header(p, FLAC_BLOCK_STREAMINFO, !points && !padding, BAAC_FLAC_STREAMINFO_BYTES);
  BaacFlacStreamInfo info;
  stream_info(e, &info, final);
  flac_streaminfo_write(&info, p);
  p += BAAC_FLAC_STREAMINFO_BYTES;
  if (points) {
    p = put_block_header(p, FLAC_BLOCK_SEEKTABLE, !padding, (size_t)points * FLAC_SEEK_POINT_BYTES);
    for (int i = 0; i < points; i++) {
      if (final && i < (int)e->seek_points.size()) {
        const BaacFlacSeekPoint& point = e->seek_points[(size_t)i];
        p = put_be(p, point.sample_position, 8);
        p = put_be(p, point.byte_offset, 8);
        p = put_be(p, (uint64_t)point.block_size, 2);
      } else {
        /* A placeholder: room for a point, ignored by decoders. */
        p = put_be(p, UINT64_MAX, 8);
        p = put_be(p, 0, 8);
        p = put_be(p, 0, 2);
      }
    }
  }
  if (padding) {
    p = put_block_header(p, FLAC_BLOCK_PADDING, true, (size_t)padding);
    memset(p, 0, (size_t)padding);
  }
}

}  // namespace

/* ── internal interface ─────────────────────────────────────────── */

int flac_encoder_check(int sample_rate, int channels, int bits_per_sample, int level) {
  if (sample_rate <= 0 || sample_rate > BAAC_FLAC_MAX_SAMPLE_RATE || channels < 1 ||
      channels > BAAC_FLAC_MAX_CHANNELS || level < BAAC_FLAC_LEVEL_MIN ||
      level > BAAC_FLAC_LEVEL_MAX) {
    return AAC_ERR_INVALID_ARG;
  }
  if (bits_per_sample != 8 && bits_per_sample != 12 && bits_per_sample != 16 &&
      bits_per_sample != 20 && bits_per_sample != 24 && bits_per_sample != 32) {
    return AAC_ERR_INVALID_ARG;
  }
  return flac_sample_rate_is_codable(sample_rate) ? AAC_OK : AAC_ERR_UNSUPPORTED;
}

BaacFlacEncoder* flac_encoder_create(int sample_rate, int channels, int bits_per_sample,
                                     int level) {
  Encoder* e = new Encoder();
  e->sample_rate = sample_rate;
  e->channels = channels;
  e->bits = bits_per_sample;
  e->level = kLevels[level];
  baac_flac_encoder_options_init(&e->options);
  flac_md5_init(&e->md5);
  try {
    const size_t block = (size_t)e->level.block_size;
    const bool pair = channels == 2 && e->level.stereo != kStereoIndependent;
    /* A pair keeps its mid and side channels in two more rows. */
    const size_t rows = pair ? 4 : (size_t)channels;
    e->input.resize(rows * block);
    e->slots.resize(rows);
    for (Slot& slot : e->slots) {
      slot.shifted.resize(block);
      slot.residual.resize(block);
    }
    if (pair && bits_per_sample == 32) {
      e->side_wide.resize(block);
      e->side_wide_shifted.resize(block);
    }
    e->residual.resize(block);
    e->rice.folded.resize(block);
    e->rice.sums.resize((size_t)(kMaxRiceParameter + 1) * kMaxPartitions);
    e->rice.ors.resize(kMaxPartitions);
    if (e->level.max_lpc_order > 0) {
      e->signal.resize(block);
      e->windowed.resize(block);
      e->windows.resize((size_t)e->level.windows * block);
    }
  } catch (const std::bad_alloc&) {
    delete e;
    throw;
  }
  return e;
}

void flac_encoder_destroy(BaacFlacEncoder* e) { delete e; }

int flac_encoder_set_options(BaacFlacEncoder* e, const BaacFlacEncoderOptions* options) {
  if (e->state == Encoder::kStreaming) {
    return AAC_ERR_STATE;
  }
  if (options->padding_bytes < 0 || options->padding_bytes > 0xFFFFFF ||
      (options->total_samples_hint >> 36)) {
    return AAC_ERR_INVALID_ARG;
  }
  e->options = *options;
  return AAC_OK;
}

int flac_encoder_block_size(const BaacFlacEncoder* e) { return e->level.block_size; }

int flac_encoder_header_bytes(const BaacFlacEncoder* e) { return header_bytes(e); }

int flac_encoder_write_header(BaacFlacEncoder* e, uint8_t* out, int out_size) {
  if (e->state == Encoder::kStreaming) {
    return AAC_ERR_STATE;
  }
  const int bytes = header_bytes(e);
  if (out_size < bytes) {
    return AAC_ERR_OVERFLOW;
  }
  /* A new stream: nothing of the last one carries over. */
  e->frame_number = 0;
  e->samples = 0;
  e->audio_bytes = 0;
  e->min_frame_bytes = 0;
  e->max_frame_bytes = 0;
  flac_md5_init(&e->md5);
  memset(e->digest, 0, sizeof(e->digest));
  e->have_frame = false;
  e->fill = 0;
  e->header_size = bytes;
  e->padding = e->options.padding_bytes;
  e->seek_reserved = seek_point_count(e, &e->seek_spacing);
  e->seek_points.clear();
  e->seek_points.reserve((size_t)e->seek_reserved);
  e->seek_target = 0;
  write_header(e, out, false);
  e->state = Encoder::kStreaming;
  return bytes;
}

int flac_encoder_max_output_bytes(const BaacFlacEncoder* e, int n_samples) {
  if (n_samples == 0) {
    return e->fill ? (int)frame_bound(e, e->fill) : 0;
  }
  const uint64_t frames = ((uint64_t)e->fill + (uint64_t)n_samples) / (uint64_t)e->level.block_size;
  const uint64_t bytes = frames * frame_bound(e, e->level.block_size);
  return bytes > INT32_MAX ? AAC_ERR_INVALID_ARG : (int)bytes;
}

int flac_encoder_encode(BaacFlacEncoder* e, const int32_t* pcm, int n_samples, uint8_t* out,
                        int out_size) {
  if (e->state != Encoder::kStreaming) {
    return AAC_ERR_STATE;
  }
  const int bound = flac_encoder_max_output_bytes(e, n_samples);
  if (bound < 0) {
    return bound;
  }
  if (n_samples == 0) {
    return 0;
  }
  /* A frame number has 31 bits. */
  if (e->frame_number + ((uint64_t)e->fill + (uint64_t)n_samples) / (uint64_t)e->level.block_size >
      0x7FFFFFFFull) {
    return AAC_ERR_ENCODE;
  }
  if (out_size < bound) {
    return AAC_ERR_OVERFLOW;
  }
  const size_t values = (size_t)n_samples * (size_t)e->channels;
  if (e->bits < 32) {
    /* Every sample has to lie within the bit depth; the encoder codes
     * nothing with loss. */
    const uint32_t half = 1u << (e->bits - 1);
    uint32_t outside = 0;
    for (size_t i = 0; i < values; i++) {
      outside |= ((uint32_t)pcm[i] + half) >> e->bits;
    }
    if (outside) {
      return AAC_ERR_INVALID_ARG;
    }
  }
  flac_md5_update_pcm(&e->md5, pcm, values, e->bits);

  const int block = e->level.block_size;
  size_t written = 0;
  int done = 0;
  while (done < n_samples) {
    const int take = std::min(block - e->fill, n_samples - done);
    for (int ch = 0; ch < e->channels; ch++) {
      int32_t* row = e->input.data() + (size_t)ch * (size_t)block + (size_t)e->fill;
      const int32_t* in = pcm + (size_t)done * (size_t)e->channels + (size_t)ch;
      for (int i = 0; i < take; i++) {
        row[i] = in[(size_t)i * (size_t)e->channels];
      }
    }
    e->fill += take;
    done += take;
    if (e->fill == block) {
      const size_t bytes = encode_block(e, block, out + written);
      account_frame(e, block, bytes);
      written += bytes;
      e->fill = 0;
    }
  }
  return (int)written;
}

int flac_encoder_finish(BaacFlacEncoder* e, uint8_t* out, int out_size) {
  if (e->state != Encoder::kStreaming) {
    return AAC_ERR_STATE;
  }
  size_t bytes = 0;
  if (e->fill) {
    if (out_size < (int)frame_bound(e, e->fill)) {
      return AAC_ERR_OVERFLOW;
    }
    bytes = encode_block(e, e->fill, out);
    account_frame(e, e->fill, bytes);
    e->fill = 0;
  }
  flac_md5_final(&e->md5, e->digest);
  e->state = Encoder::kFinished;
  return (int)bytes;
}

int flac_encoder_last_frame_info(const BaacFlacEncoder* e, BaacFlacFrameInfo* info) {
  if (e->state == Encoder::kIdle || !e->have_frame) {
    return AAC_ERR_STATE;
  }
  *info = e->last;
  return AAC_OK;
}

int flac_encoder_final_header(const BaacFlacEncoder* e, uint8_t* out, int out_size) {
  if (e->state != Encoder::kFinished) {
    return AAC_ERR_STATE;
  }
  if (out_size < e->header_size) {
    return AAC_ERR_OVERFLOW;
  }
  write_header(e, out, true);
  return e->header_size;
}

void flac_encoder_stream_info(const BaacFlacEncoder* e, BaacFlacStreamInfo* info) {
  stream_info(e, info, e->state == Encoder::kFinished);
}
