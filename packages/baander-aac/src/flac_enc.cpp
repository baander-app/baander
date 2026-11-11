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
#include <memory>
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
   * quantises the predictors at `precisions` coefficient precisions from
   * the highest down, estimates the size of each from the error the
   * quantised predictor leaves, and keeps the one that looks smallest.
   * Of the windows' predictors the `coded` that look smallest across the
   * block are coded, and exact size decides. */
  int precisions;
  int coded;
};

/* The pieces a block is cut into where not every window's predictor is
 * coded: the windows are put together from them, and the predictors of
 * different windows compared on them. */
const int kScorePieces = 15;

const Level kLevels[BAAC_FLAC_LEVEL_MAX + 1] = {
    {1152, 0, kStereoIndependent, 3, 0, 0, 0}, {1152, 0, kStereoAdaptive, 3, 0, 0, 0},
    {1152, 0, kStereoFull, 3, 0, 0, 0},        {4096, 6, kStereoIndependent, 4, 1, 6, 1},
    {4096, 8, kStereoAdaptive, 4, 1, 6, 1},    {4096, 8, kStereoFull, 5, 1, 6, 1},
    {4096, 8, kStereoFull, 6, 3, 6, 3},        {4096, 12, kStereoFull, 6, 3, 6, 3},
    {4096, 12, kStereoFull, 6, 9, 6, 2},
};

/* What a residual sample costs beyond the logarithm of its spread, in
 * the estimate that ranks predictor orders: about the entropy of a
 * two-sided geometric distribution of unit spread. */
const double kEstimateOffset = 1.9;

/* The encoder never divides a residual into more partitions than the
 * streamable subset allows. */
const int kMaxPartitionOrder = 8;
const int kMaxPartitions = 1 << kMaxPartitionOrder;
const int kMaxRiceParameter = 30;     /* with 5-bit parameters */
const int kMaxRiceParameter4Bit = 14; /* with 4-bit ones */
const uint8_t kEscaped = 0xFF;        /* in place of a partition's Rice parameter */

/* Seconds from one seek point to the next.  seek_point_count spaces
 * them wider in a stream that would otherwise get more points than a
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
uint64_t rice_search(const FlacDSP* dsp, RiceScratch* rs, const int32_t* residual, int n, int order,
                     int max_partition_order, bool wide_parameters, Coding* c) {
  FLAC_PROFILE(FLAC_PROF_RICE);
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
    uint32_t bits = 0;
    const uint64_t sum = dsp->rice_fold(residual + begin, end - begin, folded + begin, &bits);
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
  const int cap = wide_parameters ? kMaxRiceParameter : kMaxRiceParameter4Bit;
  int k_low = std::max(0, bit_length(smallest_mean) - 2);
  int k_high = std::min(cap, bit_length(largest_mean));
  k_low = std::min(k_low, k_high);
  const int k_count = k_high - k_low + 1;
  uint64_t* sums = rs->sums.data();
  for (int p = 0; p < parts; p++) {
    const int begin = p ? p * part_size : order;
    const int end = (p + 1) * part_size;
    dsp->rice_sums(folded + begin, end - begin, rs->ors[(size_t)p], k_low, k_count, sums + p,
                   kMaxPartitions);
  }

  /* From the finest partitioning to a single partition, merging pairs
   * on the way; with 4-bit parameters, and with 5-bit ones when the
   * parameters reach beyond 14. */
  uint64_t best = UINT64_MAX;
  for (int po = finest; po >= 0; po--) {
    const int size = n >> po;
    for (int form = k_low > kMaxRiceParameter4Bit ? 1 : 0;
         form < (k_high > kMaxRiceParameter4Bit ? 2 : 1); form++) {
      const int parameter_bits = form ? 5 : 4;
      const int form_cap = form ? kMaxRiceParameter : kMaxRiceParameter4Bit;
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
    /* The writer's state stays in locals over the partition, and goes
     * back to the writer around the rare code too long for one word. */
    const uint32_t stop = 1u << k;
    uint64_t cache = w->cache;
    int bits = w->bits;
    size_t pos = w->pos;
    for (int i = begin; i < end; i++) {
      const uint32_t r = (uint32_t)residual[i];
      const uint32_t u = (r << 1) ^ (0u - (r >> 31));
      const uint32_t quotient = u >> k;
      const uint32_t length = quotient + 1 + (uint32_t)k;
      if (length > 32) {
        w->cache = cache;
        w->bits = bits;
        w->pos = pos;
        flac_bitwriter_write_unary(w, quotient);
        flac_bitwriter_write(w, u, k);
        cache = w->cache;
        bits = w->bits;
        pos = w->pos;
        continue;
      }
      /* The zeros of the unary part, its one and the low bits as one
       * number of that many bits. */
      cache = (cache << length) | (stop | (u & (stop - 1)));
      bits += (int)length;
      if (bits >= 32) {
        bits -= 32;
        if (w->capacity - pos < 4) {
          w->overflow = true;
          continue;
        }
        const uint32_t word = (uint32_t)(cache >> bits);
        w->data[pos] = (uint8_t)(word >> 24);
        w->data[pos + 1] = (uint8_t)(word >> 16);
        w->data[pos + 2] = (uint8_t)(word >> 8);
        w->data[pos + 3] = (uint8_t)word;
        pos += 4;
      }
    }
    w->cache = cache;
    w->bits = bits;
    w->pos = pos;
  }
}

/* ── prediction ─────────────────────────────────────────────────── */

/* The bits the widest weight of each fixed predictor takes. */
const int kFixedWeightBits[FLAC_MAX_FIXED_ORDER + 1] = {0, 2, 3, 3, 4};

/* A residual has to be above -2^31 and below 2^31 (RFC 9639 section
 * 9.2.7.3). */
inline bool residual_in_range(int64_t r) { return (uint64_t)(r + INT32_MAX) <= 0xFFFFFFFEull; }

/* The sums of the magnitudes of the residuals of the fixed predictors of
 * order 0 to `max_order`, over the samples every one of them predicts:
 * each order is the difference of the one below it.  In 64 bits, for
 * the samples the kernel does not take. */
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

/* The residual of a predictor on 33-bit samples into residual[order ..
 * n), with the sum of the products in 64 bits.  False when a value
 * leaves the range a residual may have. */
bool wide_residual(const int64_t* x, int n, int order, const int32_t* coefs, int shift,
                   int32_t* residual) {
  bool outside = false;
  for (int i = order; i < n; i++) {
    int64_t sum = 0;
    for (int j = 0; j < order; j++) {
      sum += (int64_t)coefs[j] * x[i - 1 - j];
    }
    const int64_t r = x[i] - (sum >> shift);
    outside |= !residual_in_range(r);
    residual[i] = (int32_t)r;
  }
  return !outside;
}

}  // namespace

/* ── encoder state ──────────────────────────────────────────────── */

struct BaacFlacEncoder_ {
  enum State { kIdle, kStreaming, kFinished };
  State state = kIdle;
  const FlacDSP* dsp = flac_dsp_global();

  int sample_rate = 0;
  int channels = 0;
  int bits = 0;
  Level level = kLevels[BAAC_FLAC_LEVEL_DEFAULT];
  BaacFlacEncoderOptions options = {};
  /* The tag and picture blocks of the options as they go into a header,
   * and where the header of the last of them begins. */
  std::shared_ptr<const std::vector<uint8_t>> meta;
  size_t meta_last = 0;

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
  std::shared_ptr<const std::vector<uint8_t>> stream_meta; /* its tags and pictures */
  size_t stream_meta_last = 0;
  int seek_reserved = 0;     /* points the header has room for */
  uint64_t seek_spacing = 0; /* samples from one target to the next */
  uint64_t seek_target = 0;  /* the next sample position to point at */

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
  std::vector<double> piece_window; /* the window of a piece, then that of the last piece */
  int piece_last = 0;               /* samples in the last piece */
  double piece_energy = 0.0;
  double piece_last_energy = 0.0;
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
            rice_search(e->dsp, &e->rice, e->residual.data(), n, c->order,
                        e->level.max_partition_order, wide_parameters, c);
  consider(e, slot, *c);
}

/* The largest coefficient precision a linear predictor of this order
 * gets on samples of this depth.  A decoder may add up the products in
 * 32 bits when depth, precision and the bits of the summation stay
 * within 32; where that leaves a useful precision the encoder keeps to
 * it, and where it does not (above 16 bits or so) it takes the format's
 * 15 bits and the 64-bit sum they need. */
int precision_ceiling(int bps, int order) {
  const int within_32 = 32 - bps - flac_ceil_log2(order);
  return within_32 >= 10 ? std::min(15, within_32) : 15;
}

/* The residual of a predictor into the scratch row, through the kernel
 * that fits: in 32 bits when the sample depth, the width of the
 * coefficients and the bits the summation adds stay within 32, which
 * keeps every sum and every residual in range, and of those in 16 by 16
 * bit products when samples and coefficients are that narrow; in 64
 * bits otherwise, with each residual checked.  False when a residual
 * leaves the range it may have. */
bool compute_residual(Encoder* e, const int32_t* x, int n, int order, const int32_t* coefs,
                      int shift, int bps, int coef_bits) {
  FLAC_PROFILE(FLAC_PROF_RESIDUAL);
  if (bps <= 30 && bps + coef_bits + flac_ceil_log2(order) <= 32) {
    if (bps <= 16 && coef_bits <= 16) {
      e->dsp->lpc_residual_16(x, n, order, coefs, shift, e->residual.data());
    } else {
      e->dsp->lpc_residual_32(x, n, order, coefs, shift, e->residual.data());
    }
    return true;
  }
  return e->dsp->lpc_residual_64(x, n, order, coefs, shift, e->residual.data());
}

bool compute_residual(Encoder* e, const int64_t* x, int n, int order, const int32_t* coefs,
                      int shift, int, int) {
  FLAC_PROFILE(FLAC_PROF_RESIDUAL);
  return wide_residual(x, n, order, coefs, shift, e->residual.data());
}

/* The order with the smallest of `max_order` + 1 magnitudes. */
int smallest_magnitude(const uint64_t* magnitudes, int max_order, uint64_t* magnitude) {
  int order = 0;
  for (int k = 1; k <= max_order; k++) {
    if (magnitudes[k] < magnitudes[order]) {
      order = k;
    }
  }
  *magnitude = magnitudes[order];
  return order;
}

/* The order of the fixed predictor whose residual is smallest in
 * magnitude, and that magnitude.  Low bits that are zero in every sample
 * scale all the magnitudes alike, so the choice holds with or without
 * them. */
int best_fixed_order(Encoder* e, const int32_t* x, int n, int bps, uint64_t* magnitude) {
  FLAC_PROFILE(FLAC_PROF_FIXED);
  const int max_order = std::min(FLAC_MAX_FIXED_ORDER, n - 1);
  uint64_t magnitudes[FLAC_MAX_FIXED_ORDER + 1];
  if (bps <= 27) {
    e->dsp->fixed_sums(x, n, max_order, magnitudes);
  } else {
    fixed_magnitudes(x, n, max_order, magnitudes);
  }
  return smallest_magnitude(magnitudes, max_order, magnitude);
}

int best_fixed_order(Encoder*, const int64_t* x, int n, int, uint64_t* magnitude) {
  FLAC_PROFILE(FLAC_PROF_FIXED);
  const int max_order = std::min(FLAC_MAX_FIXED_ORDER, n - 1);
  uint64_t magnitudes[FLAC_MAX_FIXED_ORDER + 1];
  fixed_magnitudes(x, n, max_order, magnitudes);
  return smallest_magnitude(magnitudes, max_order, magnitude);
}

/* Codes the channel with the fixed predictor of `order` and offers the
 * subframe to the slot. */
template <typename S>
void try_fixed(Encoder* e, Slot* slot, const S* x, int n, int order) {
  if (compute_residual(e, x, n, order, kFlacFixedWeights[order], 0, slot->bps,
                       kFixedWeightBits[order])) {
    Coding c;
    c.type = kFixed;
    c.order = order;
    finish_candidate(e, slot, &c, n, 0);
  }
}

/* A linear predictor as the analysis proposes it. */
struct Candidate {
  double estimate = 0.0; /* of the subframe's size in bits */
  int order = 0;
  int precision = 0;
  int shift = 0;
  int32_t coefs[FLAC_MAX_LPC_ORDER];
};

/* The predictor that looks smallest for one analysis window.  `orders`
 * predictors come from the recursion with their errors.  Each order is
 * quantised at the level's coefficient precisions, from the highest
 * down, and the size of each result estimated from the error the
 * quantised predictor leaves on the windowed signal: the residual's
 * variance is that error over the window's energy, a residual sample
 * takes about half the logarithm of the variance and a constant, never
 * less than a bit, and each order adds a warm-up sample and a
 * coefficient.
 *
 * No rounding makes a predictor better than the recursion's, so the
 * recursion's error bounds every estimate of an order from below, and
 * an order whose bound does not beat the best estimate found is not
 * quantised at all.  False when no order can be written. */
bool best_candidate(const Level& level, const double* r, const double* coefs, const double* errors,
                    int orders, double energy, int n, int bps, Candidate* best) {
  double bound[FLAC_MAX_LPC_ORDER + 1];
  bool visited[FLAC_MAX_LPC_ORDER + 1] = {false};
  for (int order = 1; order <= orders; order++) {
    bound[order] = flac_lpc_estimate_bits(errors[order - 1], energy, n - order, kEstimateOffset) +
                   (double)order * (double)(bps + 1);
  }
  bool found = false;
  for (int visit = 0; visit < orders; visit++) {
    int order = 0;
    for (int m = 1; m <= orders; m++) {
      if (!visited[m] && (order == 0 || bound[m] < bound[order])) {
        order = m;
      }
    }
    if (found && bound[order] >= best->estimate) {
      break;
    }
    visited[order] = true;
    const int ceiling = precision_ceiling(bps, order);
    for (int precision = ceiling; precision > std::max(ceiling - level.precisions, 1);
         precision--) {
      Candidate c;
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
                                          energy, n - order, kEstimateOffset) +
                   (double)order * (double)(bps + c.precision);
      if (!found || c.estimate < best->estimate) {
        *best = c;
        found = true;
      }
    }
  }
  return found;
}

/* Computes the residual of a proposed predictor, codes it and offers
 * the subframe to the slot. */
template <typename S>
void try_candidate(Encoder* e, Slot* slot, const S* x, int n, const Candidate& chosen) {
  Coding c;
  c.type = kLpc;
  c.order = chosen.order;
  c.precision = chosen.precision;
  c.shift = chosen.shift;
  memcpy(c.coefs, chosen.coefs, sizeof(int32_t) * (size_t)chosen.order);
  if (!compute_residual(e, x, n, c.order, c.coefs, c.shift, slot->bps, c.precision)) {
    return;
  }
  finish_candidate(e, slot, &c, n, 4 + 5 + (uint64_t)c.order * (uint64_t)c.precision);
}

/* The linear predictors for a channel, after the fixed one.
 *
 * For each analysis window the autocorrelation and the recursion give a
 * predictor of every order, of which the one that looks smallest on that
 * window is kept.  A level with few windows codes each window's
 * predictor.  A level with many codes only the best few, and to pick
 * them it needs an estimate that holds across windows: a predictor made
 * from one third of the block looks perfect on its own window and says
 * nothing there about the rest.  So the block is cut into short
 * overlapping pieces, each under a window of its own, and a predictor's
 * size is estimated piece by piece from the error it leaves on each,
 * which follows the residual's level along the block the way the Rice
 * partitions do.  The pieces are the analysis of such a level as well:
 * the autocorrelation of a window is the sum of those of the pieces
 * whose centres lie in it, so that the samples are visited once for all
 * the windows.  A block too short to cut up gets each window computed
 * on its own and every predictor coded.  The slot keeps the smallest
 * subframe by exact size. */
template <typename S>
void try_predictors(Encoder* e, Slot* slot, const S* x, int n) {
  const Level& level = e->level;
  const int max_order = std::min(level.max_lpc_order, n - 1);
  double* signal = e->signal.data();
  {
    FLAC_PROFILE(FLAC_PROF_WINDOW);
    for (int i = 0; i < n; i++) {
      signal[i] = (double)x[i];
    }
  }
  /* The pieces: each two hops long, a hop apart, the last one taking
   * what is left of the block. */
  const int hop = n / (kScorePieces + 1);
  if (e->window_size != n) {
    for (int w = 0; w < level.windows; w++) {
      double* window = e->windows.data() + (size_t)w * (size_t)level.block_size;
      flac_lpc_window(window, n, level.windows, w, kWindowTaper);
      e->window_energy[w] = flac_lpc_energy(window, n);
    }
    e->piece_last = n - (kScorePieces - 1) * hop;
    flac_lpc_hann(e->piece_window.data(), 2 * hop);
    flac_lpc_hann(e->piece_window.data() + 2 * hop, e->piece_last);
    e->piece_energy = flac_lpc_energy(e->piece_window.data(), 2 * hop);
    e->piece_last_energy = flac_lpc_energy(e->piece_window.data() + 2 * hop, e->piece_last);
    e->window_size = n;
  }

  Candidate candidates[FLAC_MAX_ANALYSIS_WINDOWS];
  bool found[FLAC_MAX_ANALYSIS_WINDOWS];
  const bool ranked = level.coded < level.windows && hop > 2 * max_order;
  double piece_r[kScorePieces][FLAC_MAX_LPC_ORDER + 1];
  if (ranked) {
    for (int t = 0; t < kScorePieces; t++) {
      const bool last = t == kScorePieces - 1;
      {
        FLAC_PROFILE(FLAC_PROF_WINDOW);
        e->dsp->window(signal + t * hop, e->piece_window.data() + (last ? 2 * hop : 0),
                       last ? e->piece_last : 2 * hop, e->windowed.data());
      }
      FLAC_PROFILE(FLAC_PROF_AUTOCORRELATION);
      e->dsp->autocorrelation(e->windowed.data(), last ? e->piece_last : 2 * hop, max_order,
                              piece_r[t]);
    }
  }
  for (int w = 0; w < level.windows; w++) {
    double r[FLAC_MAX_LPC_ORDER + 1] = {0.0};
    double energy = 0.0;
    int ranges[4];
    const int range_count = flac_lpc_window_ranges(n, w, ranges);
    if (ranked) {
      /* The window is the pieces whose centres lie in its ranges. */
      for (int i = 0; i < range_count; i++) {
        for (int t = 0; t < kScorePieces; t++) {
          const int centre = (t + 1) * hop;
          if (centre < ranges[2 * i] || centre > ranges[2 * i + 1]) {
            continue;
          }
          for (int lag = 0; lag <= max_order; lag++) {
            r[lag] += piece_r[t][lag];
          }
          energy += t == kScorePieces - 1 ? e->piece_last_energy : e->piece_energy;
        }
      }
    } else {
      /* The window is zero outside one or two ranges, which are all the
       * autocorrelation has to visit; two ranges lie too far apart for a
       * product across them. */
      const double* window = e->windows.data() + (size_t)w * (size_t)level.block_size;
      for (int i = 0; i < range_count; i++) {
        const int begin = ranges[2 * i];
        const int length = ranges[2 * i + 1] - begin;
        double part[FLAC_MAX_LPC_ORDER + 1];
        {
          FLAC_PROFILE(FLAC_PROF_WINDOW);
          e->dsp->window(signal + begin, window + begin, length, e->windowed.data());
        }
        FLAC_PROFILE(FLAC_PROF_AUTOCORRELATION);
        e->dsp->autocorrelation(e->windowed.data(), length, max_order, part);
        for (int lag = 0; lag <= max_order; lag++) {
          r[lag] += part[lag];
        }
      }
      energy = e->window_energy[w];
    }
    double coefs[FLAC_MAX_LPC_ORDER * FLAC_MAX_LPC_ORDER];
    double errors[FLAC_MAX_LPC_ORDER];
    const int orders = flac_lpc_levinson(r, max_order, coefs, errors);
    found[w] =
        best_candidate(level, r, coefs, errors, orders, energy, n, slot->bps, &candidates[w]);
  }

  if (!ranked) {
    for (int w = 0; w < level.windows; w++) {
      if (found[w]) {
        try_candidate(e, slot, x, n, candidates[w]);
      }
    }
    return;
  }
  /* Every window's predictor on every piece. */
  double score[FLAC_MAX_ANALYSIS_WINDOWS];
  for (int w = 0; w < level.windows; w++) {
    if (!found[w]) {
      continue;
    }
    const Candidate& c = candidates[w];
    double bits = (double)c.order * (double)(slot->bps + c.precision);
    for (int t = 0; t < kScorePieces; t++) {
      bits +=
          flac_lpc_estimate_bits(flac_lpc_quantized_error(piece_r[t], c.coefs, c.shift, c.order),
                                 t == kScorePieces - 1 ? e->piece_last_energy : e->piece_energy,
                                 n / kScorePieces, kEstimateOffset);
    }
    score[w] = bits;
  }
  for (int pick = 0; pick < level.coded; pick++) {
    int best = -1;
    for (int w = 0; w < level.windows; w++) {
      if (found[w] && (best < 0 || score[w] < score[best])) {
        best = w;
      }
    }
    if (best < 0) {
      break;
    }
    found[best] = false;
    try_candidate(e, slot, x, n, candidates[best]);
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
  int wasted = 0;
  {
    FLAC_PROFILE(FLAC_PROF_SCAN);
    uint64_t differing = 0;
    for (int i = 0; i < n; i++) {
      any |= (uint64_t)(int64_t)x[i];
      differing |= (uint64_t)(int64_t)(x[i] ^ x[0]);
    }
    constant = differing == 0;
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

  /* The fixed predictor whose residual is smallest in magnitude is
   * always coded; a level with linear prediction adds its predictors.
   * An order the caller picked on the samples as they came holds here:
   * the low bits removed above scale every magnitude alike. */
  if (fixed_order < 0) {
    uint64_t magnitude;
    fixed_order = best_fixed_order(e, x, n, slot->bps, &magnitude);
  }
  try_fixed(e, slot, x, n, fixed_order);
  if (e->level.max_lpc_order > 0 && n >= kMinLpcBlock) {
    try_predictors(e, slot, x, n);
  }
}

/* ── writing ────────────────────────────────────────────────────── */

void write_subframe(FlacBitWriter* w, const Slot& slot, int n) {
  FLAC_PROFILE(FLAC_PROF_WRITE);
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
    {
      FLAC_PROFILE(FLAC_PROF_STEREO);
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
    }
    bool use[4] = {true, true, true, true};
    int fixed_orders[4] = {-1, -1, -1, -1};
    if (e->level.stereo == kStereoAdaptive) {
      /* A rough measure of each channel: the magnitude of the residual
       * of its best fixed predictor.  The two assignments that look
       * cheapest by it are coded, which takes three of the four channels
       * as a rule, and the exact sizes decide between them. */
      uint64_t rough[4];
      fixed_orders[0] = best_fixed_order(e, left, n, e->bits, &rough[0]);
      fixed_orders[1] = best_fixed_order(e, right, n, e->bits, &rough[1]);
      fixed_orders[2] = best_fixed_order(e, (const int32_t*)mid_row, n, e->bits, &rough[2]);
      fixed_orders[3] =
          wide ? best_fixed_order(e, (const int64_t*)e->side_wide.data(), n, 33, &rough[3])
               : best_fixed_order(e, (const int32_t*)side_row, n, e->bits + 1, &rough[3]);
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
         (e->meta ? (int)e->meta->size() : 0) +
         (e->options.padding_bytes ? FLAC_BLOCK_HEADER_BYTES + e->options.padding_bytes : 0);
}

/* The marker and the metadata blocks: STREAMINFO, the seek table when
 * one is reserved, the tags and the pictures, padding when asked for.
 * The first header of a stream and the final one differ in STREAMINFO
 * and in the seek points that are filled in, never in length. */
void write_header(const Encoder* e, uint8_t* out, bool final) {
  const int points = e->seek_reserved;
  const int padding = e->padding;
  const size_t meta = e->stream_meta ? e->stream_meta->size() : 0;
  uint8_t* p = out;
  memcpy(p, "fLaC", FLAC_MARKER_BYTES);
  p += FLAC_MARKER_BYTES;
  p = flac_put_block_header(p, FLAC_BLOCK_STREAMINFO, !points && !meta && !padding,
                            BAAC_FLAC_STREAMINFO_BYTES);
  BaacFlacStreamInfo info;
  stream_info(e, &info, final);
  flac_streaminfo_write(&info, p);
  p += BAAC_FLAC_STREAMINFO_BYTES;
  if (points) {
    p = flac_put_block_header(p, FLAC_BLOCK_SEEKTABLE, !meta && !padding,
                              (size_t)points * FLAC_SEEK_POINT_BYTES);
    for (int i = 0; i < points; i++) {
      if (final && i < (int)e->seek_points.size()) {
        const BaacFlacSeekPoint& point = e->seek_points[(size_t)i];
        p = flac_put_be(p, point.sample_position, 8);
        p = flac_put_be(p, point.byte_offset, 8);
        p = flac_put_be(p, (uint64_t)point.block_size, 2);
      } else {
        /* A placeholder: room for a point, ignored by decoders. */
        p = flac_put_be(p, UINT64_MAX, 8);
        p = flac_put_be(p, 0, 8);
        p = flac_put_be(p, 0, 2);
      }
    }
  }
  if (meta) {
    /* The blocks as the options left them; the last of them closes the
     * metadata when no padding follows. */
    memcpy(p, e->stream_meta->data(), meta);
    if (!padding) {
      p[e->stream_meta_last] |= 0x80;
    }
    p += meta;
  }
  if (padding) {
    p = flac_put_block_header(p, FLAC_BLOCK_PADDING, true, (size_t)padding);
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
      e->piece_window.resize(2 * block);
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
  /* The tags and pictures are checked and written into blocks of the
   * encoder's own; the caller's memory is not kept. */
  size_t meta_bytes = 0;
  const int error = flac_meta_size(options, &meta_bytes);
  if (error != AAC_OK) {
    return error;
  }
  std::shared_ptr<std::vector<uint8_t>> meta;
  size_t meta_last = 0;
  if (meta_bytes) {
    meta = std::make_shared<std::vector<uint8_t>>(meta_bytes);
    flac_meta_write(options, meta->data(), &meta_last);
  }
  e->meta = meta;
  e->meta_last = meta_last;
  e->options = *options;
  e->options.comments = nullptr;
  e->options.comment_count = 0;
  e->options.vendor = nullptr;
  e->options.pictures = nullptr;
  e->options.picture_count = 0;
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
  e->stream_meta = e->meta;
  e->stream_meta_last = e->meta_last;
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
    FLAC_PROFILE(FLAC_PROF_INPUT);
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
      FLAC_PROFILE(FLAC_PROF_INPUT);
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
