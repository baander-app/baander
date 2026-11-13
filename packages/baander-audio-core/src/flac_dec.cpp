/* The FLAC decoder (RFC 9639): the stream header with its metadata
 * blocks, frame synchronisation over a byte FIFO, the four subframe
 * types with their Rice-coded residual, and the stereo assignments.
 *
 * Every sample is computed in integers.  A subframe's samples live in
 * 32-bit words, except the side channel of 32-bit audio, which needs 33
 * bits and is decoded in 64-bit words.  A linear prediction is summed
 * in 32 bits when the subframe's depth plus the coefficient precision
 * plus the bits the summation adds fit, and in 64 bits otherwise; the
 * 32-bit sums and the fixed predictors use unsigned arithmetic, which
 * wraps to the right value whenever the true result fits and has no
 * undefined behaviour on a stream that lies about its depth. */
#include <algorithm>
#include <cstring>
#include <new>
#include <vector>

#include "flac_internal.h"

/* ── progress through an incomplete frame ───────────────────────── */

/* Where the decoding of a frame stopped for want of input, so that the
 * next attempt goes on from there instead of from the frame's start.
 * Without it a frame fed in small pieces would be read again from its
 * first bit with every piece, and the work would grow with the square
 * of the frame size.  The subframes before `channel` are decoded and in
 * their rows; inside the subframe that was being read, the residual
 * values (or the samples of a verbatim subframe) read so far are in its
 * row, and reading resumes at `bit`. */
struct FlacProgress {
  bool valid = false;      /* describes the frame at the head of the FIFO */
  int channel = 0;         /* the subframe being read */
  size_t subframe_bit = 0; /* where it starts, in bits behind the frame header */
  bool inside = false;     /* the fields below place a point inside it */
  int partition = 0;       /* the residual partition being read */
  int done = 0;            /* values of that partition, or of a verbatim subframe, read so
                            * far; -1 when the partition's parameter is still to read */
  size_t bit = 0;          /* where the next value, or that parameter, starts */
  uint32_t parameter = 0;  /* the partition's Rice parameter or escape code */
  int width = 0;           /* bits per value of an escaped partition */
};

namespace {

typedef FlacProgress Progress;

/* The FIFO holds this much before the stream's own frame size is known,
 * and never less. */
const size_t kFifoMinBytes = 65536;

enum FrameResult { kFrameOk = 1, kFrameNeedMore = 0, kFrameInvalid = -1 };

/* The search for a frame behind damage reads each candidate, a sync
 * code with a header whose CRC-8 holds, to its end, and a stream can be
 * made of such headers that are no frames.  What is read in vain is
 * therefore kept in proportion to what is passed: beyond the size of
 * one frame, every byte read for a candidate that was rejected has to
 * be paid for by passing input, at this many bytes read to a byte
 * passed.  While the debt stands, candidates are passed unread. */
const uint64_t kSearchRate = 4;

/* The bit depths a frame can have, for one that leaves its depth to a
 * STREAMINFO the decoder was not given. */
const int kMinBits = 4;
const int kMaxBits = 32;

/* The largest frame the decoder accepts for a block size and channel
 * count: 64 bits per sample and 16 bytes of side information per
 * subframe around the header and the CRC-16.  A verbatim frame of
 * 32-bit audio takes half of that; a frame beyond it comes from an
 * encoder whose Rice parameters do not fit its residual, and is treated
 * as damage instead of being buffered without limit. */
size_t frame_bound(int block_size, int channels) {
  return FLAC_MAX_FRAME_HEADER_BYTES + (size_t)channels * ((size_t)block_size * 8 + 16) + 2;
}

/* ── residual ───────────────────────────────────────────────────── */

/* Reads `count` two's complement values of `width` bits, or as many as
 * the input still holds whole, and returns that number; when it is
 * fewer, the reader is left exhausted.  `out` may be null to pass over
 * the values. */
template <typename T>
int read_fixed_width(FlacBitReader* r, T* FLAC_RESTRICT out, int count, int width) {
  const size_t left = (size_t)(r->end - r->data) * 8 - flac_bitreader_position(r);
  const int whole = (int)std::min<size_t>((size_t)count, left / (size_t)width);
  /* The reader works on a copy for the length of the loop, so that the
   * stores to `out` cannot be taken for stores to its state. */
  if (out) {
    FlacBitReader local = *r;
    for (int i = 0; i < whole; i++) {
      out[i] = (T)flac_bitreader_read_signed(&local, width);
    }
    *r = local;
  } else {
    /* Nothing is kept, so the values are passed over in one step. */
    flac_bitreader_seek(r, flac_bitreader_position(r) + (size_t)whole * (size_t)width);
  }
  if (whole < count) {
    flac_bitreader_fail(r);
  }
  return whole;
}

/* One Rice-coded partition: `count` values of a unary quotient and `k`
 * plain bits each, folded back to signed.  Returns the number of values
 * read: all of them, or fewer when the input ends inside one, which then
 * starts at `*stop_bit`; -1 for a value outside 32 bits.
 *
 * The kernel reads every code word that lies whole in the reader's
 * cache; the rare one that does not, a very long one or one at the end
 * of the input, goes through the reader. */
int read_rice(const FlacDSP* dsp, FlacBitReader* r, int32_t* out, int count, int k,
              size_t* stop_bit) {
  const uint32_t max_quotient = 0xFFFFFFFFu >> k;
  for (int i = 0;;) {
    i += dsp->rice_decode(r, out + i, count - i, k);
    if (i == count) {
      return count;
    }
    /* A code word the kernel left: bit by bit through the reader. */
    const size_t start = flac_bitreader_position(r);
    const uint32_t quotient = flac_bitreader_read_unary(r);
    const uint32_t rest = flac_bitreader_read(r, k);
    if (r->overread) {
      *stop_bit = start;
      return i;
    }
    if (quotient > max_quotient) {
      return -1;
    }
    const uint32_t folded = (quotient << k) | rest;
    out[i++] = (int32_t)((folded >> 1) ^ (0u - (folded & 1)));
  }
}

/* The coded residual of a subframe (RFC 9639 section 9.2.7) into
 * out[order .. block_size).  Returns false for a syntax error, and for
 * input that ends early, which the reader's overread flag tells apart;
 * `pg` then says where to go on. */
bool read_residual(const FlacDSP* dsp, FlacBitReader* r, int32_t* out, int block_size, int order,
                   Progress* pg) {
  const uint32_t method = flac_bitreader_read(r, 2);
  if (method > 1) {
    return false;
  }
  const int parameter_bits = method ? 5 : 4;
  const uint32_t escape = (1u << parameter_bits) - 1;
  const int partition_order = (int)flac_bitreader_read(r, 4);
  const int partition_size = block_size >> partition_order;
  /* The block divides evenly into the partitions, and the first one has
   * room for the warm-up samples. */
  if ((partition_size << partition_order) != block_size || partition_size < order || r->overread) {
    return false;
  }
  int partition = 0;
  int done = -1;
  if (pg->inside) {
    partition = pg->partition;
    done = pg->done;
    flac_bitreader_seek(r, pg->bit);
  }
  for (; partition < (1 << partition_order); partition++, done = -1) {
    const int count = partition ? partition_size : partition_size - order;
    int32_t* p = out + (partition ? (size_t)partition * (size_t)partition_size : (size_t)order);
    pg->inside = true;
    pg->partition = partition;
    if (done < 0) {
      const size_t start = flac_bitreader_position(r);
      pg->parameter = flac_bitreader_read(r, parameter_bits);
      pg->width = pg->parameter == escape ? (int)flac_bitreader_read(r, 5) : 0;
      if (r->overread) {
        pg->done = -1;
        pg->bit = start;
        return false;
      }
      done = 0;
    }
    if (pg->parameter != escape) {
      size_t stop_bit = 0;
      const int got = read_rice(dsp, r, p + done, count - done, (int)pg->parameter, &stop_bit);
      if (got < 0) {
        return false;
      }
      if (got < count - done) {
        pg->done = done + got;
        pg->bit = stop_bit;
        return false;
      }
    } else if (pg->width == 0) {
      std::fill(p + done, p + count, 0);
    } else {
      const size_t start = flac_bitreader_position(r);
      const int got = read_fixed_width(r, p + done, count - done, pg->width);
      if (got < count - done) {
        pg->done = done + got;
        pg->bit = start + (size_t)got * (size_t)pg->width;
        return false;
      }
    }
  }
  return true;
}

/* ── prediction ─────────────────────────────────────────────────── */

/* s[order .. n) holds the residual on entry and the samples on return;
 * s[0 .. order) are the warm-up samples.  In these loops a row is reached
 * through one pointer only, and the coefficients and a separate residual
 * are other objects than the row. */
void restore_fixed32(int32_t* FLAC_RESTRICT s, int n, int order) {
  FLAC_PROFILE(FLAC_PROF_RESTORE);
  switch (order) {
    case 1:
      for (int i = 1; i < n; i++) {
        s[i] = (int32_t)((uint32_t)s[i] + (uint32_t)s[i - 1]);
      }
      break;
    case 2:
      for (int i = 2; i < n; i++) {
        s[i] = (int32_t)((uint32_t)s[i] + 2u * (uint32_t)s[i - 1] - (uint32_t)s[i - 2]);
      }
      break;
    case 3:
      for (int i = 3; i < n; i++) {
        s[i] = (int32_t)((uint32_t)s[i] + 3u * (uint32_t)s[i - 1] - 3u * (uint32_t)s[i - 2] +
                         (uint32_t)s[i - 3]);
      }
      break;
    case 4:
      for (int i = 4; i < n; i++) {
        s[i] = (int32_t)((uint32_t)s[i] + 4u * (uint32_t)s[i - 1] - 6u * (uint32_t)s[i - 2] +
                         4u * (uint32_t)s[i - 3] - (uint32_t)s[i - 4]);
      }
      break;
    default:
      break;
  }
}

/* 33-bit samples: the residual comes from `res`, the samples go to `s`,
 * whose first `order` entries are the warm-up samples. */
void restore_fixed64(int64_t* FLAC_RESTRICT s, const int32_t* FLAC_RESTRICT res, int n, int order) {
  FLAC_PROFILE(FLAC_PROF_RESTORE);
  for (int i = order; i < n; i++) {
    uint64_t v = (uint64_t)(int64_t)res[i];
    for (int j = 0; j < order; j++) {
      v += (uint64_t)(int64_t)kFlacFixedWeights[order][j] * (uint64_t)s[i - 1 - j];
    }
    s[i] = (int64_t)v;
  }
}

void restore_lpc64(int64_t* FLAC_RESTRICT s, const int32_t* FLAC_RESTRICT res, int n, int order,
                   const int32_t* FLAC_RESTRICT coefs, int shift) {
  FLAC_PROFILE(FLAC_PROF_RESTORE);
  for (int i = order; i < n; i++) {
    uint64_t sum = 0;
    for (int j = 0; j < order; j++) {
      sum += (uint64_t)(int64_t)coefs[j] * (uint64_t)s[i - 1 - j];
    }
    s[i] = (int64_t)((uint64_t)(int64_t)res[i] + (uint64_t)((int64_t)sum >> shift));
  }
}

/* ── subframe ───────────────────────────────────────────────────── */

/* Decodes one subframe of `bps` bits per sample (the frame's depth, plus
 * one for a side channel) into `out`, or into `wide` when `bps` is 33;
 * `out` then serves as room for the residual.  With `restore` off the
 * subframe is only read, which is enough to find the end of a frame:
 * `out` still takes the residual and the samples are not computed.
 *
 * Returns false for a syntax error and for input that ends inside the
 * subframe.  The caller looks at the reader's overread flag first, since
 * zeros read past the end can look like a syntax error; `pg` then holds
 * the point to resume at, and the next call, on the same subframe with
 * more input, reads its header again and continues there. */
bool decode_subframe(const FlacDSP* dsp, FlacBitReader* r, int bps, int block_size, int32_t* out,
                     int64_t* wide, bool restore, Progress* pg) {
  FLAC_PROFILE(FLAC_PROF_READ);
  if (flac_bitreader_read(r, 1)) {
    return false;
  }
  const int type = (int)flac_bitreader_read(r, 6);
  int wasted = 0;
  if (flac_bitreader_read(r, 1)) {
    /* At least one bit per sample has to remain. */
    const uint32_t more = flac_bitreader_read_unary(r);
    if (more + 1 >= (uint32_t)bps) {
      return false;
    }
    wasted = (int)more + 1;
    bps -= wasted;
  }

  if (type == 0) {
    const int64_t value = flac_bitreader_read_signed(r, bps);
    if (restore && wide) {
      std::fill(wide, wide + block_size, value);
    } else if (restore) {
      std::fill(out, out + block_size, (int32_t)value);
    }
  } else if (type == 1) {
    if (r->overread) {
      return false;
    }
    int done = 0;
    if (pg->inside) {
      done = pg->done;
      flac_bitreader_seek(r, pg->bit);
    }
    const size_t start = flac_bitreader_position(r);
    int got;
    if (restore && wide) {
      got = read_fixed_width(r, wide + done, block_size - done, bps);
    } else {
      got = read_fixed_width(r, restore ? out + done : nullptr, block_size - done, bps);
    }
    if (got < block_size - done) {
      pg->inside = true;
      pg->done = done + got;
      pg->bit = start + (size_t)got * (size_t)bps;
      return false;
    }
  } else if ((type >= 8 && type <= 8 + FLAC_MAX_FIXED_ORDER) || type >= 32) {
    const bool lpc = type >= 32;
    const int order = lpc ? type - 31 : type - 8;
    if (order > block_size) {
      return false;
    }
    int64_t warmup[FLAC_MAX_LPC_ORDER];
    for (int i = 0; i < order; i++) {
      warmup[i] = flac_bitreader_read_signed(r, bps);
    }
    int precision = 0;
    int shift = 0;
    int32_t coefs[FLAC_MAX_LPC_ORDER];
    if (lpc) {
      precision = (int)flac_bitreader_read(r, 4) + 1;
      shift = (int)flac_bitreader_read_signed(r, 5);
      if (precision == 16 || shift < 0) {
        return false;
      }
      for (int i = 0; i < order; i++) {
        coefs[i] = (int32_t)flac_bitreader_read_signed(r, precision);
      }
    }
    if (!read_residual(dsp, r, out, block_size, order, pg)) {
      return false;
    }
    /* The bits the coefficients really take, sign included: never more
     * than the declared precision, and often fewer. */
    int coef_bits = 1;
    if (lpc) {
      uint32_t magnitudes = 0;
      for (int i = 0; i < order; i++) {
        magnitudes |= (uint32_t)(coefs[i] < 0 ? ~coefs[i] : coefs[i]);
      }
      while (magnitudes >> (coef_bits - 1)) {
        coef_bits++;
      }
    }
    if (restore && wide) {
      std::copy(warmup, warmup + order, wide);
      if (lpc) {
        restore_lpc64(wide, out, block_size, order, coefs, shift);
      } else {
        restore_fixed64(wide, out, block_size, order);
      }
    } else if (restore) {
      for (int i = 0; i < order; i++) {
        out[i] = (int32_t)warmup[i];
      }
      if (!lpc) {
        restore_fixed32(out, block_size, order);
      } else if (bps + coef_bits + flac_ceil_log2(order) <= 32) {
        FLAC_PROFILE(FLAC_PROF_RESTORE);
        dsp->lpc_restore_32(out, block_size, order, coefs, shift);
      } else {
        FLAC_PROFILE(FLAC_PROF_RESTORE);
        dsp->lpc_restore_wide(out, block_size, order, coefs, shift);
      }
    }
  } else {
    return false; /* a reserved subframe type */
  }

  if (wasted && restore) {
    if (wide) {
      for (int i = 0; i < block_size; i++) {
        wide[i] = (int64_t)((uint64_t)wide[i] << wasted);
      }
    } else {
      for (int i = 0; i < block_size; i++) {
        out[i] = (int32_t)((uint32_t)out[i] << wasted);
      }
    }
  }
  return true;
}

}  // namespace

/* ── decoder state ──────────────────────────────────────────────── */

struct BaacFlacDecoder_ {
  enum Stage {
    kStart,       /* nothing read yet: the marker or a frame follows */
    kBlockHeader, /* a metadata block header follows */
    kStreamInfo,  /* the 34 bytes of a STREAMINFO block follow */
    kSeekTable,   /* seek points follow, `block_left` bytes of them */
    kCollect,     /* a tag or picture block the caller wants: `block_left` bytes of it follow */
    kFrames,      /* frames follow */
    kUnsupported, /* a frame changed the stream's properties */
    kFinished,
  };
  Stage stage = kStart;
  const FlacDSP* dsp = flac_dsp_global();

  /* Input.  `fifo` never grows beyond `fifo_limit`, which is reserved
   * up front, so appending does not allocate. */
  std::vector<uint8_t> fifo;
  size_t head = 0; /* first unread byte of `fifo` */
  size_t fifo_limit = 0;
  size_t need = 0;       /* a frame at the head is incomplete below this many bytes */
  FlacProgress progress; /* how far that frame has been read */
  uint64_t offset = 0;   /* bytes of the stream consumed so far */
  uint64_t skip = 0;     /* metadata bytes still to drop as they arrive */
  uint32_t block_left = 0;
  bool last_block = false;
  bool pending_error = false;    /* damaged metadata, reported by the next decode call */
  bool ended = false;            /* the caller has no more input: what is incomplete stays so */
  bool synced = false;           /* a frame is expected right at the head; otherwise the
                                  * decoder is searching and skips what is none */
  bool ignore_checksums = false; /* fuzzing: frames are taken whatever their CRCs say */
  uint64_t id3v2_bytes = 0;      /* of the ID3v2 tags passed over in front of the stream */
  bool id3v1 = false;            /* an ID3v1 tag behind the last frame was passed over */
  uint64_t search_debt = 0;      /* bytes read for rejected candidates and not yet paid for */
  uint64_t rejected_work = 0;    /* every byte read for a rejected candidate, for the tests */
  uint64_t read_work = 0;        /* every byte read for a frame or a candidate, for the tests */
  uint64_t probe_work = 0;       /* read so far by the depth probe of the frame at the head */

  /* The stream. */
  bool have_info = false; /* `info` is a STREAMINFO, from the stream or the caller */
  BaacFlacStreamInfo info = {};
  bool have_config = false; /* the three properties every frame has to share */
  int sample_rate = 0;
  int channels = 0;
  int bits = 0;
  int nominal_block = 0; /* block size of a fixed block size stream, 0 unknown */
  int64_t first_frame_offset = -1;
  bool have_seek_table = false;
  std::vector<BaacFlacSeekPoint> seek_points;

  /* Tags and pictures, kept only for a caller that asked: the content
   * of each block as it came, and what the caller is shown, which
   * points into those blocks.  `meta_held` counts what the kept blocks
   * cost, their bytes and their bookkeeping, and never passes
   * `meta_cap`. */
  bool collect = false;
  size_t meta_cap = 0;
  size_t meta_held = 0;
  int meta_skipped = 0;
  int collect_type = 0;         /* of the block coming in */
  size_t collect_bytes = 0;     /* its length */
  std::vector<uint8_t> current; /* what has arrived of it */
  std::vector<std::vector<uint8_t>> meta_blocks;
  bool have_comments = false;
  BaacFlacComment vendor = {};
  std::vector<BaacFlacComment> comments;
  std::vector<BaacFlacPicture> pictures;

  /* One row of `block_capacity` samples per channel, and one row of
   * 64-bit samples for a 33-bit side channel. */
  std::vector<int32_t> work;
  std::vector<int64_t> wide;
  int block_capacity = 0;
  int channel_capacity = 0;

  /* Frames. */
  bool have_frame = false;
  BaacFlacFrameInfo last = {};
  uint64_t next_position = 0;
  int lost = 0;

  /* MD5 of the decoded samples. */
  bool md5_enabled = false;
  bool md5_valid = true; /* every sample from the stream's first has been hashed */
  FlacMd5 md5;
};

namespace {

typedef BaacFlacDecoder_ Decoder;

size_t pending(const Decoder* d) { return d->fifo.size() - d->head; }
const uint8_t* fifo_data(const Decoder* d) { return d->fifo.data() + d->head; }

/* Whatever was learned about the frame at the head no longer holds: the
 * head moved, or the frame is to be read differently. */
void forget_frame(Decoder* d) {
  d->need = 0;
  d->progress.valid = false;
  d->probe_work = 0;
}

void drop(Decoder* d, size_t n) {
  d->head += n;
  d->offset += n;
  forget_frame(d);
}

/* Room for one more element, found before anything is put in: the
 * element can then be added without an allocation that might fail. */
template <typename T>
void reserve_one(std::vector<T>* v) {
  if (v->size() == v->capacity()) {
    v->reserve(std::max<size_t>(4, v->capacity() * 2));
  }
}

/* Gives a vector's memory back. */
void release(std::vector<uint8_t>* v) { std::vector<uint8_t>().swap(*v); }

/* Empties the input side: the FIFO, what is known about the frame at its
 * head, a metadata block in passage, and the flags that describe where
 * in the stream the next byte belongs.  A reset and a seek both start
 * from here. */
void clear_input(Decoder* d) {
  d->fifo.clear();
  d->head = 0;
  forget_frame(d);
  d->skip = 0;
  d->block_left = 0;
  release(&d->current);
  d->collect_bytes = 0;
  d->pending_error = false;
  d->ended = false;
  d->synced = false;
  d->search_debt = 0;
}

void fifo_append(Decoder* d, const uint8_t* data, size_t n) {
  if (d->head == d->fifo.size()) {
    d->fifo.clear();
    d->head = 0;
  } else if (d->head && d->fifo.size() + n > d->fifo_limit) {
    d->fifo.erase(d->fifo.begin(), d->fifo.begin() + (std::ptrdiff_t)d->head);
    d->head = 0;
  }
  d->fifo.insert(d->fifo.end(), data, data + n);
}

void raise_fifo_limit(Decoder* d, size_t limit) {
  if (limit > d->fifo_limit) {
    d->fifo.reserve(limit);
    d->fifo_limit = limit;
  }
}

/* Room for `channels` rows of `block_size` samples, and for the 64-bit
 * row when a stereo pair of 32-bit audio can carry a side channel. */
void ensure_buffers(Decoder* d, int channels, int block_size, int bits) {
  if (channels > d->channel_capacity || block_size > d->block_capacity) {
    const int rows = std::max(channels, d->channel_capacity);
    const int width = std::max(block_size, d->block_capacity);
    d->work.resize((size_t)rows * (size_t)width);
    d->channel_capacity = rows;
    d->block_capacity = width;
  }
  if (bits == 32 && channels == 2 && d->wide.size() < (size_t)d->block_capacity) {
    d->wide.resize((size_t)d->block_capacity);
  }
}

/* Takes a STREAMINFO as the stream's description and sizes everything
 * the stream can need from it, once. */
void apply_info(Decoder* d, const BaacFlacStreamInfo& info) {
  raise_fifo_limit(d, frame_bound(info.max_block_size, info.channels));
  ensure_buffers(d, info.channels, info.max_block_size, info.bits_per_sample);
  d->info = info;
  d->have_info = true;
  d->have_config = true;
  d->sample_rate = info.sample_rate;
  d->channels = info.channels;
  d->bits = info.bits_per_sample;
  d->nominal_block = info.min_block_size == info.max_block_size ? info.max_block_size : 0;
  /* A frame waiting at the head is read differently from here on. */
  forget_frame(d);
}

/* The block whose header was just read is behind us (but for `skip`
 * bytes of it): another block header follows, or the first frame. */
void after_block(Decoder* d) {
  if (d->last_block) {
    d->stage = Decoder::kFrames;
    d->synced = true;
    d->first_frame_offset = (int64_t)(d->offset + d->skip);
  } else {
    d->stage = Decoder::kBlockHeader;
  }
}

/* What a kept block costs against the caller's limit besides its
 * content: the bookkeeping of the block, and of each tag in it.  A
 * stream of a million empty pictures, or a tag block of a million empty
 * fields, is thus held to the limit like a large picture is. */
const size_t kBlockOverhead = 128;

/* A tag or picture block has arrived whole: it is kept when it is
 * sound and within the limit, skipped and counted when its fields would
 * take the decoder past the limit, and dropped as damage when one of
 * its lengths or its count does not fit it.  The fields are counted
 * before anything is sized for them.
 *
 * Whatever may fail for want of memory is done first and changes
 * nothing a caller can see, so that the call can be made again after
 * such a failure and finds the block as it was.  Only then is the block
 * read, which writes into it, and shown. */
void keep_block(Decoder* d) {
  const bool tags = d->collect_type == FLAC_BLOCK_VORBIS_COMMENT;
  const size_t size = d->collect_bytes;
  const size_t room = d->meta_cap - d->meta_held;
  size_t cost = size + kBlockOverhead;
  d->current.resize(size + 1); /* the spare byte that closes the last string */
  reserve_one(&d->meta_blocks);
  uint8_t* block = d->current.data();
  bool sound = true;
  if (tags) {
    const int count = flac_meta_count_comments(block, size);
    sound = count >= 0;
    cost += sound ? (size_t)count * sizeof(BaacFlacComment) : 0;
    if (sound && cost <= room) {
      d->comments.resize((size_t)count);
    }
  } else if (cost <= room) {
    reserve_one(&d->pictures);
  }
  /* Nothing below allocates. */
  const bool fits = cost <= room;
  if (sound && fits) {
    if (tags) {
      flac_meta_read_comments(block, &d->vendor, d->comments.data());
      d->have_comments = true;
    } else {
      BaacFlacPicture picture;
      sound = flac_meta_read_picture(block, size, &picture);
      if (sound) {
        d->pictures.push_back(picture);
      }
    }
  }
  if (!sound) {
    d->pending_error = true;
  } else if (!fits) {
    flac_count(&d->meta_skipped);
  } else {
    /* Moving the block keeps its bytes where they are, and with them
     * what points at them. */
    d->meta_held += cost;
    d->meta_blocks.push_back(std::move(d->current));
  }
  release(&d->current);
}

/* Whether ten bytes are the header of an ID3v2 tag, and how many bytes
 * of the tag follow them: its four size bytes carry seven bits each,
 * and a flag announces a footer of ten bytes more. */
bool id3v2_header(const uint8_t* p, uint64_t* following) {
  if (memcmp(p, "ID3", 3) != 0 || p[3] == 0xFF || p[4] == 0xFF ||
      ((p[6] | p[7] | p[8] | p[9]) & 0x80)) {
    return false;
  }
  *following = ((uint64_t)p[6] << 21 | (uint64_t)p[7] << 14 | (uint64_t)p[8] << 7 | p[9]) +
               ((p[5] & 0x10) ? 10 : 0);
  return true;
}

/* What has to arrive of a tag or picture block before the decoder
 * reserves the whole length its header states. */
const size_t kCollectTrust = 65536;

const size_t kId3v2HeaderBytes = 10;
const size_t kId3v1Bytes = 128;

/* Whether the bytes left at the head are an ID3v1 tag, which some
 * programs put behind the last frame: 128 bytes that begin with "TAG"
 * and end the input. */
bool id3v1_trailer(const Decoder* d) {
  return d->synced && pending(d) == kId3v1Bytes && memcmp(fifo_data(d), "TAG", 3) == 0;
}

/* Reads the marker and the metadata blocks as far as the FIFO allows.
 * STREAMINFO and the seek table are parsed, tags and pictures are kept
 * for a caller that asked for them; every other block is dropped by its
 * length, straight from the FIFO and then from the caller's input,
 * without being buffered.  An ID3v2 tag in front of the marker, which
 * some programs put there, is dropped the same way. */
void process_metadata(Decoder* d) {
  for (;;) {
    if (d->skip) {
      const size_t n = (size_t)std::min<uint64_t>(d->skip, pending(d));
      drop(d, n);
      d->skip -= n;
      if (d->skip) {
        return;
      }
    }
    const size_t avail = pending(d);
    const uint8_t* p = fifo_data(d);
    switch (d->stage) {
      case Decoder::kStart: {
        if (avail == 0) {
          return;
        }
        if (memcmp(p, "ID3", std::min<size_t>(avail, 3)) == 0) {
          if (avail < kId3v2HeaderBytes) {
            return;
          }
          uint64_t following = 0;
          if (id3v2_header(p, &following)) {
            drop(d, kId3v2HeaderBytes);
            d->skip = following;
            d->id3v2_bytes += kId3v2HeaderBytes + following;
            break;
          }
        }
        /* Anything that does not begin with the marker is a stream
         * joined at a frame, or somewhere before one. */
        if (memcmp(p, "fLaC", std::min<size_t>(avail, FLAC_MARKER_BYTES)) != 0) {
          d->stage = Decoder::kFrames;
          d->synced = false;
          return;
        }
        if (avail < FLAC_MARKER_BYTES) {
          return;
        }
        drop(d, FLAC_MARKER_BYTES);
        d->stage = Decoder::kBlockHeader;
        break;
      }
      case Decoder::kBlockHeader: {
        if (avail < FLAC_BLOCK_HEADER_BYTES) {
          return;
        }
        const int type = p[0] & 0x7F;
        if (type == FLAC_BLOCK_FORBIDDEN) {
          /* Not a metadata block: the lengths before it were wrong.
           * The frames are searched from here. */
          d->pending_error = true;
          d->stage = Decoder::kFrames;
          d->synced = false;
          return;
        }
        d->last_block = (p[0] & 0x80) != 0;
        d->block_left = (uint32_t)flac_load_be(p + 1, 3);
        drop(d, FLAC_BLOCK_HEADER_BYTES);
        if (type == FLAC_BLOCK_STREAMINFO && !d->have_info &&
            d->block_left == BAAC_FLAC_STREAMINFO_BYTES) {
          d->stage = Decoder::kStreamInfo;
        } else if (type == FLAC_BLOCK_SEEKTABLE && !d->have_seek_table) {
          d->have_seek_table = true;
          d->stage = Decoder::kSeekTable;
        } else if (d->collect && ((type == FLAC_BLOCK_VORBIS_COMMENT && !d->have_comments) ||
                                  type == FLAC_BLOCK_PICTURE)) {
          if (d->block_left > d->meta_cap - d->meta_held) {
            /* More than the caller wants held: skipped, and counted. */
            flac_count(&d->meta_skipped);
            d->skip = d->block_left;
            after_block(d);
          } else {
            d->collect_type = type;
            d->collect_bytes = d->block_left;
            d->current.clear();
            d->stage = Decoder::kCollect;
          }
        } else {
          /* A STREAMINFO of another length cannot be read. */
          if (type == FLAC_BLOCK_STREAMINFO && !d->have_info) {
            d->pending_error = true;
          }
          d->skip = d->block_left;
          after_block(d);
        }
        break;
      }
      case Decoder::kStreamInfo: {
        if (avail < BAAC_FLAC_STREAMINFO_BYTES) {
          return;
        }
        BaacFlacStreamInfo info;
        if (flac_streaminfo_parse(p, &info)) {
          apply_info(d, info);
        } else {
          d->pending_error = true;
        }
        drop(d, BAAC_FLAC_STREAMINFO_BYTES);
        after_block(d);
        break;
      }
      case Decoder::kCollect: {
        /* A block that claims a length has to deliver before it is
         * believed: the buffer doubles with the bytes that arrive until
         * 64 KiB of them are there, and only then takes the length the
         * header states, which the caller's limit has allowed. */
        const size_t n = std::min<size_t>(d->block_left, avail);
        const size_t wanted = d->current.size() + n + 1;
        if (d->current.capacity() < wanted) {
          const size_t whole = d->collect_bytes + 1;
          d->current.reserve(wanted > kCollectTrust
                                 ? whole
                                 : std::min(whole, std::max(wanted, d->current.capacity() * 2)));
        }
        d->current.insert(d->current.end(), p, p + n);
        drop(d, n);
        d->block_left -= (uint32_t)n;
        if (d->block_left) {
          return;
        }
        keep_block(d);
        after_block(d);
        break;
      }
      case Decoder::kSeekTable: {
        /* One point at a time, so that the table is never buffered and
         * memory follows the bytes that arrived, not the length the
         * block claims.  Points have to ascend; placeholders and
         * points out of order are left out. */
        while (d->block_left >= FLAC_SEEK_POINT_BYTES &&
               d->seek_points.size() < BAAC_FLAC_MAX_SEEK_POINTS) {
          if (pending(d) < FLAC_SEEK_POINT_BYTES) {
            return;
          }
          const uint8_t* q = fifo_data(d);
          BaacFlacSeekPoint point;
          point.sample_position = flac_load_be(q, 8);
          point.byte_offset = flac_load_be(q + 8, 8);
          point.block_size = (int)flac_load_be(q + 16, 2);
          if (point.sample_position != UINT64_MAX &&
              (d->seek_points.empty() ||
               point.sample_position > d->seek_points.back().sample_position)) {
            d->seek_points.push_back(point);
          }
          drop(d, FLAC_SEEK_POINT_BYTES);
          d->block_left -= FLAC_SEEK_POINT_BYTES;
        }
        d->skip = d->block_left;
        d->block_left = 0;
        after_block(d);
        break;
      }
      default:
        return;
    }
  }
}

/* Decodes the frame at `p` whose header is `h`, with `bits` per sample.
 * With `restore` the samples land in the channel rows (a side channel of
 * 32-bit audio in the 64-bit row); without, the frame is only read, to
 * find its end and check its CRC-16, and row 0 serves as scratch.  A
 * frame the input does not hold whole yet leaves its progress behind,
 * and the next call for the same frame continues from it. */
FrameResult decode_frame(Decoder* d, const uint8_t* p, size_t avail, const FlacFrameHeader& h,
                         int bits, bool restore, size_t* frame_bytes, size_t* examined) {
  Progress& pg = d->progress;
  FlacBitReader r;
  flac_bitreader_init(&r, p + h.header_bytes, avail - (size_t)h.header_bytes);
  /* What the attempt read of the input, for the caller's account of
   * work spent on what turns out to be no frame. */
  const auto read_so_far = [&]() {
    return std::min(avail, (size_t)h.header_bytes + (flac_bitreader_position(&r) + 7) / 8);
  };
  /* A frame read before continues from its progress: only what lies
   * beyond it is read again, which the tests' count of work records. */
  const size_t resume_bit = pg.inside ? pg.bit : pg.subframe_bit;
  const size_t from = pg.valid ? std::min(avail, (size_t)h.header_bytes + resume_bit / 8) : 0;
  const auto account = [&](size_t bytes) {
    *examined = bytes;
    d->read_work += bytes - std::min(bytes, from);
  };
  if (pg.valid) {
    flac_bitreader_seek(&r, pg.subframe_bit);
  } else {
    pg = Progress();
    pg.valid = true;
  }
  for (int ch = pg.channel; ch < h.channels; ch++) {
    const bool side =
        (h.assignment == FLAC_ASSIGN_SIDE_RIGHT && ch == 0) ||
        ((h.assignment == FLAC_ASSIGN_LEFT_SIDE || h.assignment == FLAC_ASSIGN_MID_SIDE) &&
         ch == 1);
    const int bps = bits + (side ? 1 : 0);
    int32_t* out = d->work.data() + (restore ? (size_t)ch * (size_t)d->block_capacity : 0);
    int64_t* wide = bps > 32 ? d->wide.data() : nullptr;
    pg.channel = ch;
    pg.subframe_bit = flac_bitreader_position(&r);
    const bool ok = decode_subframe(d->dsp, &r, bps, h.block_size, out, wide, restore, &pg);
    if (r.overread) {
      account(avail);
      return kFrameNeedMore;
    }
    if (!ok) {
      pg.valid = false;
      account(read_so_far());
      return kFrameInvalid;
    }
    pg.inside = false;
  }
  pg.channel = h.channels;
  pg.subframe_bit = flac_bitreader_position(&r);
  flac_bitreader_align(&r);
  const size_t crc_pos = (size_t)h.header_bytes + flac_bitreader_position(&r) / 8;
  if (avail < crc_pos + 2) {
    account(avail);
    return kFrameNeedMore;
  }
  pg.valid = false;
  /* The checksum reads the frame a second time. */
  account(2 * crc_pos + 2);
  if (!d->ignore_checksums &&
      flac_crc16(p, crc_pos) != (uint16_t)((p[crc_pos] << 8) | p[crc_pos + 1])) {
    return kFrameInvalid;
  }
  *frame_bytes = crc_pos + 2;
  return kFrameOk;
}

/* Whether a frame follows a header that leaves its bit depth to a
 * STREAMINFO the decoder does not have.  The header cannot say, but a
 * frame read at its true depth ends in its CRC-16, so the frame is read
 * at each depth there is, without keeping samples.  kFrameOk when one
 * of them ends in a checksum that holds. */
FrameResult probe_depths(Decoder* d, const uint8_t* p, size_t avail, const FlacFrameHeader& h,
                         size_t* examined) {
  bool more = false;
  *examined = 0;
  for (int bits = kMinBits; bits <= kMaxBits; bits++) {
    size_t frame_bytes = 0;
    size_t read = 0;
    d->progress.valid = false;
    const FrameResult result = decode_frame(d, p, avail, h, bits, false, &frame_bytes, &read);
    d->progress.valid = false;
    *examined += read;
    if (result == kFrameOk) {
      return kFrameOk;
    }
    more = more || result == kFrameNeedMore;
  }
  return more ? kFrameNeedMore : kFrameInvalid;
}

/* Turns the channel rows of a decoded frame back into left and right
 * (RFC 9639 section 4.2). */
void undo_stereo(Decoder* d, const FlacFrameHeader& h, int bits) {
  FLAC_PROFILE(FLAC_PROF_OUTPUT);
  const int n = h.block_size;
  /* Two rows of the sample buffer and the 64-bit row: three stretches of
   * memory that do not overlap. */
  int32_t* FLAC_RESTRICT c0 = d->work.data();
  int32_t* FLAC_RESTRICT c1 = d->work.data() + d->block_capacity;
  if (bits == 32) {
    /* The side channel is in the 64-bit row. */
    const int64_t* FLAC_RESTRICT side = d->wide.data();
    if (h.assignment == FLAC_ASSIGN_LEFT_SIDE) {
      for (int i = 0; i < n; i++) {
        c1[i] = (int32_t)(uint32_t)((uint64_t)(int64_t)c0[i] - (uint64_t)side[i]);
      }
    } else if (h.assignment == FLAC_ASSIGN_SIDE_RIGHT) {
      for (int i = 0; i < n; i++) {
        c0[i] = (int32_t)(uint32_t)((uint64_t)(int64_t)c1[i] + (uint64_t)side[i]);
      }
    } else {
      for (int i = 0; i < n; i++) {
        const uint64_t s = (uint64_t)side[i];
        const uint64_t mid = ((uint64_t)(int64_t)c0[i] << 1) | (s & 1);
        c0[i] = (int32_t)(uint32_t)(uint64_t)((int64_t)(mid + s) >> 1);
        c1[i] = (int32_t)(uint32_t)(uint64_t)((int64_t)(mid - s) >> 1);
      }
    }
  } else if (h.assignment == FLAC_ASSIGN_LEFT_SIDE) {
    for (int i = 0; i < n; i++) {
      c1[i] = (int32_t)((uint32_t)c0[i] - (uint32_t)c1[i]);
    }
  } else if (h.assignment == FLAC_ASSIGN_SIDE_RIGHT) {
    for (int i = 0; i < n; i++) {
      c0[i] = (int32_t)((uint32_t)c0[i] + (uint32_t)c1[i]);
    }
  } else if (bits <= 30) {
    /* Mid plus side takes two bits more than a sample. */
    for (int i = 0; i < n; i++) {
      const uint32_t s = (uint32_t)c1[i];
      const uint32_t mid = ((uint32_t)c0[i] << 1) | (s & 1);
      c0[i] = (int32_t)(mid + s) >> 1;
      c1[i] = (int32_t)(mid - s) >> 1;
    }
  } else {
    for (int i = 0; i < n; i++) {
      const uint64_t s = (uint64_t)(int64_t)c1[i];
      const uint64_t mid = ((uint64_t)(int64_t)c0[i] << 1) | (s & 1);
      c0[i] = (int32_t)(uint32_t)(uint64_t)((int64_t)(mid + s) >> 1);
      c1[i] = (int32_t)(uint32_t)(uint64_t)((int64_t)(mid - s) >> 1);
    }
  }
}

/* The rows are the decoder's own and `pcm` is the caller's: they do not
 * overlap. */
void interleave(const Decoder* d, int channels, int n, int32_t* FLAC_RESTRICT pcm) {
  FLAC_PROFILE(FLAC_PROF_OUTPUT);
  const int32_t* rows = d->work.data();
  const size_t stride = (size_t)d->block_capacity;
  if (channels == 1) {
    memcpy(pcm, rows, (size_t)n * sizeof(int32_t));
  } else if (channels == 2) {
    const int32_t* FLAC_RESTRICT c0 = rows;
    const int32_t* FLAC_RESTRICT c1 = rows + stride;
    for (int i = 0; i < n; i++) {
      pcm[2 * i] = c0[i];
      pcm[2 * i + 1] = c1[i];
    }
  } else {
    for (int ch = 0; ch < channels; ch++) {
      const int32_t* FLAC_RESTRICT c = rows + (size_t)ch * stride;
      for (int i = 0; i < n; i++) {
        pcm[(size_t)i * (size_t)channels + (size_t)ch] = c[i];
      }
    }
  }
}

/* The bytes at the head are no frame.  When a frame was expected there
 * this is damage: it is counted and reported, and the search starts
 * one byte on.  While searching it is only one more byte to pass. */
/* `n` bytes of input were passed in the search: they pay off what was
 * read in vain. */
void pay(Decoder* d, size_t n) {
  d->search_debt -= std::min<uint64_t>(d->search_debt, kSearchRate * (uint64_t)n);
}

/* `bytes` of input were read for a candidate that is no frame. */
void charge(Decoder* d, size_t bytes) {
  d->search_debt += bytes;
  d->rejected_work += bytes;
}

bool reject(Decoder* d) {
  const bool report = d->synced;
  if (report) {
    d->synced = false;
    flac_count(&d->lost);
  }
  drop(d, 1);
  pay(d, 1);
  return report;
}

}  // namespace

/* ── internal interface ─────────────────────────────────────────── */

BaacFlacDecoder* flac_decoder_create() {
  Decoder* d = new (std::nothrow) Decoder();
  if (!d) {
    return nullptr;
  }
  try {
    raise_fifo_limit(d, kFifoMinBytes);
  } catch (const std::bad_alloc&) {
    delete d;
    return nullptr;
  }
  flac_md5_init(&d->md5);
  return d;
}

void flac_decoder_destroy(BaacFlacDecoder* d) { delete d; }

void flac_decoder_reset(BaacFlacDecoder* d) {
  clear_input(d);
  d->stage = Decoder::kStart;
  d->offset = 0;
  d->last_block = false;
  d->have_info = false;
  d->info = BaacFlacStreamInfo();
  d->have_config = false;
  d->sample_rate = 0;
  d->channels = 0;
  d->bits = 0;
  d->nominal_block = 0;
  d->first_frame_offset = -1;
  d->have_seek_table = false;
  d->seek_points.clear();
  d->meta_held = 0;
  d->meta_skipped = 0;
  d->meta_blocks.clear();
  d->have_comments = false;
  d->vendor = BaacFlacComment();
  d->comments.clear();
  d->pictures.clear();
  d->have_frame = false;
  d->last = BaacFlacFrameInfo();
  d->next_position = 0;
  d->lost = 0;
  d->md5_valid = true;
  flac_md5_init(&d->md5);
  d->id3v2_bytes = 0;
  d->id3v1 = false;
}

int flac_decoder_set_streaminfo(BaacFlacDecoder* d, const uint8_t* data) {
  if (d->have_info || d->have_frame || d->stage == Decoder::kFinished) {
    return AAC_ERR_STATE;
  }
  BaacFlacStreamInfo info;
  if (!flac_streaminfo_parse(data, &info)) {
    return AAC_ERR_INVALID_ARG;
  }
  apply_info(d, info);
  return AAC_OK;
}

int flac_decoder_set_md5_check(BaacFlacDecoder* d, bool enabled) {
  if (d->have_frame || d->stage == Decoder::kFinished) {
    return AAC_ERR_STATE;
  }
  d->md5_enabled = enabled;
  return AAC_OK;
}

uint64_t flac_decoder_rejected_work(const BaacFlacDecoder* d) { return d->rejected_work; }

uint64_t flac_decoder_read_work(const BaacFlacDecoder* d) { return d->read_work; }

void flac_decoder_id3(const BaacFlacDecoder* d, uint64_t* v2_bytes, int* v1_trailer) {
  if (v2_bytes) {
    *v2_bytes = d->id3v2_bytes;
  }
  if (v1_trailer) {
    *v1_trailer = d->id3v1 ? 1 : 0;
  }
}

void flac_decoder_ignore_checksums(BaacFlacDecoder* d, bool ignore) {
  d->ignore_checksums = ignore;
}

int flac_decoder_set_metadata(BaacFlacDecoder* d, bool collect, size_t max_bytes) {
  if (d->stage != Decoder::kStart || d->offset || pending(d)) {
    return AAC_ERR_STATE;
  }
  d->collect = collect;
  d->meta_cap = max_bytes ? max_bytes : (size_t)BAAC_FLAC_DEFAULT_METADATA_BYTES;
  return AAC_OK;
}

int flac_decoder_comments(const BaacFlacDecoder* d, const BaacFlacComment** comments,
                          BaacFlacComment* vendor) {
  static const char kNone[1] = {0};
  *comments = d->comments.empty() ? nullptr : d->comments.data();
  if (vendor) {
    vendor->text = d->have_comments ? d->vendor.text : kNone;
    vendor->length = d->have_comments ? d->vendor.length : 0;
  }
  return (int)d->comments.size();
}

int flac_decoder_pictures(const BaacFlacDecoder* d, const BaacFlacPicture** pictures) {
  *pictures = d->pictures.empty() ? nullptr : d->pictures.data();
  return (int)d->pictures.size();
}

int flac_decoder_skipped_metadata(const BaacFlacDecoder* d) { return d->meta_skipped; }

int flac_decoder_feed(BaacFlacDecoder* d, const uint8_t* data, int size) {
  if (d->stage == Decoder::kFinished || d->ended) {
    return AAC_ERR_STATE;
  }
  size_t left = (size_t)size;
  try {
    for (;;) {
      process_metadata(d);
      if (d->skip) {
        /* The FIFO is empty: the rest of the block is dropped unseen. */
        const size_t n = (size_t)std::min<uint64_t>(d->skip, left);
        d->skip -= n;
        d->offset += n;
        data += n;
        left -= n;
        if (d->skip) {
          break;
        }
        continue;
      }
      const size_t room = d->fifo_limit - pending(d);
      const size_t take = std::min(left, room);
      if (take == 0) {
        break;
      }
      fifo_append(d, data, take);
      data += take;
      left -= take;
    }
  } catch (const std::bad_alloc&) {
    /* Reading the metadata ran out of memory.  The bytes taken before
     * that are in the FIFO and have to be reported as taken, or the
     * caller would hand them over a second time; the step that failed
     * is tried again by the next call, which reports the failure if
     * it persists and nothing was taken. */
    if (left == (size_t)size) {
      throw;
    }
  }
  return size - (int)left;
}

int flac_decoder_pending_bytes(const BaacFlacDecoder* d) { return (int)pending(d); }

int flac_decoder_decode_next(BaacFlacDecoder* d, int32_t* pcm, int pcm_size) {
  if (d->stage == Decoder::kFinished) {
    return AAC_ERR_STATE;
  }
  if (d->stage == Decoder::kUnsupported) {
    return AAC_ERR_UNSUPPORTED;
  }
  process_metadata(d);
  if (d->pending_error) {
    d->pending_error = false;
    flac_count(&d->lost);
    return AAC_ERR_DECODE;
  }
  if (d->stage != Decoder::kFrames) {
    return 0;
  }
  for (;;) {
    if (!d->synced && pending(d)) {
      /* Every frame starts with 0xFF: pass what cannot start one. */
      const uint8_t* p = fifo_data(d);
      const void* hit = memchr(p, 0xFF, pending(d));
      const size_t passed = hit ? (size_t)((const uint8_t*)hit - p) : pending(d);
      /* A candidate that waits for more input stays where it is, and
       * what was read of it still holds. */
      if (passed) {
        drop(d, passed);
        pay(d, passed);
      }
    }
    const size_t avail = pending(d);
    if (avail == 0 || avail < d->need) {
      return 0;
    }
    const uint8_t* p = fifo_data(d);
    if (d->synced && p[0] == 'T' && avail <= kId3v1Bytes) {
      /* What may be an ID3v1 tag behind the last frame: it is one when
       * the input ends with it, and is passed over then. */
      if (id3v1_trailer(d) && d->ended) {
        drop(d, kId3v1Bytes);
        d->id3v1 = true;
        return 0;
      }
      if (!d->ended && memcmp(p, "TAG", std::min<size_t>(avail, 3)) == 0) {
        return 0;
      }
    }
    FlacFrameHeader h;
    const int header_bytes = flac_frame_header_parse(p, avail, &h, !d->ignore_checksums);
    if (header_bytes == 0 && !d->ended) {
      return 0;
    }
    /* A block larger than STREAMINFO allows is refused before anything
     * is sized for it.  A header cut off by the end of the input is no
     * frame either. */
    if (header_bytes <= 0 || (d->have_info && h.block_size > d->info.max_block_size)) {
      if (reject(d)) {
        return AAC_ERR_DECODE;
      }
      continue;
    }
    /* In the search, a candidate is read only while the account of
     * what was read in vain allows it. */
    const bool searching = !d->synced;
    if (searching && d->search_debt > d->fifo_limit && !d->progress.valid) {
      reject(d);
      continue;
    }

    /* A header may leave the sample rate or the bit depth to the
     * stream's description. */
    int rate = h.sample_rate;
    int bits = h.bits_per_sample;
    const bool deferred = !rate || !bits;
    if (deferred && d->have_config) {
      rate = rate ? rate : d->sample_rate;
      bits = bits ? bits : d->bits;
    }
    const bool differs =
        d->have_config && (rate != d->sample_rate || h.channels != d->channels || bits != d->bits);
    const bool readable = !(deferred && !d->have_config);
    const size_t bound = frame_bound(h.block_size, h.channels);
    FrameResult result = kFrameOk;
    size_t frame_bytes = 0;
    size_t examined = 0;
    if (!bits) {
      /* Neither the header nor the stream says how deep the samples
       * are, so the frame cannot be decoded; whether it is a frame at
       * all still decides between a stream that needs its STREAMINFO
       * and one more false sync code. */
      ensure_buffers(d, 1, h.block_size, 0);
      result = probe_depths(d, p, avail, h, &examined);
    } else if (differs || !readable) {
      /* The frame will not be decoded, but whether it is one decides
       * between an unsupported stream and one more false sync code. */
      ensure_buffers(d, 1, h.block_size, 0);
      result = decode_frame(d, p, avail, h, bits, false, &frame_bytes, &examined);
    } else {
      /* A frame that is expected is decoded as it is read.  A candidate
       * of the search is read first and decoded once its checksum
       * holds, which is all a false one costs. */
      ensure_buffers(d, h.channels, h.block_size, bits);
      result = decode_frame(d, p, avail, h, bits, !searching, &frame_bytes, &examined);
      if (searching && result == kFrameOk) {
        result = decode_frame(d, p, avail, h, bits, true, &frame_bytes, &examined);
      }
    }
    if (result == kFrameNeedMore && avail < bound && !d->ended) {
      /* The frame is incomplete.  When it fills the FIFO, which happens
       * only to a stream that came without STREAMINFO or to a frame with
       * more channels than the stream's, the FIFO grows to what a frame
       * of this size may take. */
      if (avail >= d->fifo_limit) {
        raise_fifo_limit(d, bound);
      }
      if (bits) {
        d->need = avail + 1;
      } else {
        /* Every depth is read again from the start when more has come,
         * so the next look waits for the input to double.  What the
         * probe read is billed when the candidate is rejected. */
        d->probe_work += examined;
        d->need = std::min(bound, 2 * avail + kFifoMinBytes);
      }
      /* The FIFO has to admit what the next look waits for; `need` never
       * exceeds the bound of a frame of this size. */
      raise_fifo_limit(d, d->need);
      return 0;
    }
    if (result != kFrameOk) {
      /* A syntax error, a CRC-16 mismatch, more bytes than a frame of
       * this size may take, or a frame the input ends in. */
      charge(d, examined + d->probe_work);
      if (reject(d)) {
        return AAC_ERR_DECODE;
      }
      continue;
    }
    if (!readable) {
      return AAC_ERR_UNSUPPORTED;
    }
    if (differs) {
      d->stage = Decoder::kUnsupported;
      return AAC_ERR_UNSUPPORTED;
    }
    if ((int64_t)h.block_size * h.channels > (int64_t)pcm_size) {
      return AAC_ERR_OVERFLOW;
    }

    if (h.channels == 2 && (bits <= 30 || h.assignment == FLAC_ASSIGN_INDEPENDENT)) {
      /* The common case in one pass: the assignment undone while the
       * two rows are interleaved. */
      FLAC_PROFILE(FLAC_PROF_OUTPUT);
      d->dsp->stereo_out[h.assignment](d->work.data(), d->work.data() + d->block_capacity,
                                       h.block_size, pcm);
    } else {
      if (h.assignment != FLAC_ASSIGN_INDEPENDENT) {
        undo_stereo(d, h, bits);
      }
      interleave(d, h.channels, h.block_size, pcm);
    }

    /* The position: a sample number as coded, a frame number times the
     * stream's block size.  A stream that numbers frames of varying
     * size is counted through while it is followed without a gap. */
    uint64_t position;
    if (h.variable_block_size) {
      position = h.number;
    } else {
      if (!d->nominal_block && !d->have_info) {
        d->nominal_block = h.block_size;
      }
      if (d->nominal_block) {
        position = h.number * (uint64_t)d->nominal_block;
      } else {
        position = d->synced ? d->next_position : h.number * (uint64_t)d->info.max_block_size;
      }
    }
    if (!d->have_config) {
      d->have_config = true;
      d->sample_rate = rate;
      d->channels = h.channels;
      d->bits = bits;
    }
    if (d->md5_enabled && d->md5_valid) {
      if (!d->have_frame && position != 0) {
        d->md5_valid = false; /* joined after the stream's first frame */
      } else {
        flac_md5_update_pcm(&d->md5, pcm, (size_t)h.block_size * (size_t)h.channels, bits);
      }
    }
    d->last.sample_position = position;
    d->last.block_size = h.block_size;
    d->last.bytes = (int)frame_bytes;
    d->have_frame = true;
    d->next_position = position + (uint64_t)h.block_size;
    d->synced = true;
    d->search_debt = 0;
    drop(d, frame_bytes);
    return h.block_size;
  }
}

int flac_decoder_stream_info(const BaacFlacDecoder* d, BaacFlacStreamInfo* info) {
  if (d->have_info) {
    *info = d->info;
    return AAC_OK;
  }
  if (!d->have_config) {
    return AAC_ERR_STATE;
  }
  *info = BaacFlacStreamInfo();
  info->sample_rate = d->sample_rate;
  info->channels = d->channels;
  info->bits_per_sample = d->bits;
  return AAC_OK;
}

int flac_decoder_last_frame_info(const BaacFlacDecoder* d, BaacFlacFrameInfo* info) {
  if (!d->have_frame) {
    return AAC_ERR_STATE;
  }
  *info = d->last;
  return AAC_OK;
}

int flac_decoder_lost_frames(const BaacFlacDecoder* d) { return d->lost; }

int flac_decoder_seek_points(const BaacFlacDecoder* d, const BaacFlacSeekPoint** points) {
  if (points) {
    *points = d->seek_points.empty() ? nullptr : d->seek_points.data();
  }
  return (int)d->seek_points.size();
}

int64_t flac_decoder_first_frame_offset(const BaacFlacDecoder* d) {
  return d->first_frame_offset >= 0 ? d->first_frame_offset : (int64_t)AAC_ERR_STATE;
}

int flac_decoder_seek_reset(BaacFlacDecoder* d) {
  if (d->stage == Decoder::kFinished) {
    return AAC_ERR_STATE;
  }
  clear_input(d);
  d->stage = Decoder::kFrames;
  d->md5_valid = false;
  return AAC_OK;
}

int flac_decoder_end_input(BaacFlacDecoder* d) {
  if (d->stage == Decoder::kFinished) {
    return AAC_ERR_STATE;
  }
  d->ended = true;
  d->need = 0;
  return AAC_OK;
}

int flac_decoder_finish(BaacFlacDecoder* d, BaacFlacMd5Verdict* verdict) {
  if (d->stage == Decoder::kFinished) {
    return AAC_ERR_STATE;
  }
  process_metadata(d);
  if (d->stage == Decoder::kFrames && id3v1_trailer(d)) {
    drop(d, kId3v1Bytes);
    d->id3v1 = true;
  }
  int result = AAC_OK;
  /* What is left is damage when it was to be a frame or part of the
   * header: an unfinished metadata block, a frame cut short, or bytes
   * after the last frame that are none. */
  const bool in_header = d->stage != Decoder::kFrames && d->stage != Decoder::kUnsupported &&
                         (d->stage != Decoder::kStart || pending(d));
  const bool cut_short = d->stage == Decoder::kFrames && d->synced && pending(d);
  if (d->pending_error || d->skip || in_header || cut_short) {
    flac_count(&d->lost);
    result = AAC_ERR_DECODE;
  }
  if (verdict) {
    static const uint8_t kNoMd5[16] = {0};
    *verdict = BAAC_FLAC_MD5_UNKNOWN;
    if (d->md5_enabled && d->md5_valid && d->have_info && d->stage != Decoder::kUnsupported &&
        memcmp(d->info.md5, kNoMd5, 16) != 0) {
      uint8_t digest[16];
      flac_md5_final(&d->md5, digest);
      *verdict =
          memcmp(digest, d->info.md5, 16) == 0 ? BAAC_FLAC_MD5_MATCH : BAAC_FLAC_MD5_MISMATCH;
    }
  }
  d->pending_error = false;
  d->stage = Decoder::kFinished;
  return result;
}
