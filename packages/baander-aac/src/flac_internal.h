/* Internal declarations of the lossless mode (RFC 9639), shared by
 * flac_format.cpp, flac_dec.cpp and flac_api.cpp: the checksums, the MD5
 * of RFC 1321, the coded numbers, STREAMINFO and frame headers, the
 * decoder behind the public API, and a bit reader and writer with a
 * 64-bit cache.  Not part of the public API.
 *
 * The bit reader and writer are separate from the ones in bitstream.h,
 * which were built for Huffman codes: Rice codes are a unary run
 * followed by a few plain bits, and the unary run is read with one
 * count-leading-zeros over the cache instead of bit by bit. */
#ifndef BAANDER_AAC_FLAC_INTERNAL_H
#define BAANDER_AAC_FLAC_INTERNAL_H

#include <cstddef>
#include <cstdint>
#include <cstring>

#if defined(_MSC_VER)
#include <intrin.h>
#endif

#include "flac.h"

/* A pointer through which alone its object is reached in a function: the
 * compiler may then keep values in registers across stores through other
 * pointers.  Used only where the buffers cannot overlap. */
#define FLAC_RESTRICT __restrict

/* ── stage timers ───────────────────────────────────────────────── */

/* With BAAC_FLAC_PROFILE defined at compile time the codec accounts the
 * time it spends per stage, each stage without the stages it calls, and
 * flac_profile_report prints the table.  A development aid for finding
 * where an encode or a decode spends its time: the timers are process-
 * wide, not for more than one thread, and absent from a normal build,
 * where FLAC_PROFILE expands to nothing. */
#if defined(BAAC_FLAC_PROFILE)
#include <cstdio>
enum FlacProfileStage {
  FLAC_PROF_OTHER, /* whatever no other stage claims */
  FLAC_PROF_INPUT, /* range check and de-interleaving of the input */
  FLAC_PROF_MD5,
  FLAC_PROF_SCAN,   /* wasted bits and constant detection */
  FLAC_PROF_STEREO, /* mid and side channels, and the adaptive estimate */
  FLAC_PROF_FIXED,  /* fixed predictors: magnitudes and residual */
  FLAC_PROF_WINDOW, /* conversion to floating point and windowing */
  FLAC_PROF_AUTOCORRELATION,
  FLAC_PROF_LEVINSON,
  FLAC_PROF_QUANTISE, /* quantisation, error estimate and ranking */
  FLAC_PROF_RESIDUAL, /* residual of a linear predictor */
  FLAC_PROF_RICE,     /* Rice parameter and partition search */
  FLAC_PROF_WRITE,    /* bit writing */
  FLAC_PROF_CRC,
  FLAC_PROF_READ,    /* decoder: subframe headers and residual */
  FLAC_PROF_RESTORE, /* decoder: prediction */
  FLAC_PROF_OUTPUT,  /* decoder: stereo and interleaving */
  FLAC_PROF_COUNT
};
void flac_profile_enter(int stage);
void flac_profile_leave();
void flac_profile_reset();
void flac_profile_report(FILE* out);
struct FlacProfileScope {
  explicit FlacProfileScope(int stage) { flac_profile_enter(stage); }
  ~FlacProfileScope() { flac_profile_leave(); }
};
#define FLAC_PROFILE_NAME2(line) flac_profile_scope_##line
#define FLAC_PROFILE_NAME(line) FLAC_PROFILE_NAME2(line)
#define FLAC_PROFILE(stage) FlacProfileScope FLAC_PROFILE_NAME(__LINE__)(stage)
#else
#define FLAC_PROFILE(stage)
#endif

/* ── format constants ───────────────────────────────────────────── */

#define FLAC_MARKER_BYTES 4       /* "fLaC" */
#define FLAC_BLOCK_HEADER_BYTES 4 /* last flag and type, 24-bit length */
#define FLAC_SEEK_POINT_BYTES 18
#define FLAC_MAX_FRAME_HEADER_BYTES 16 /* sync to CRC-8 with every optional field */
#define FLAC_MAX_FIXED_ORDER 4
#define FLAC_MAX_LPC_ORDER 32
#define FLAC_MAX_PARTITION_ORDER 15

/* The fixed predictors as coefficients (RFC 9639 section 9.2.5): row
 * `order` weights the sample before, the one before that and so on. */
static const int32_t kFlacFixedWeights[FLAC_MAX_FIXED_ORDER + 1][FLAC_MAX_FIXED_ORDER] = {
    {0, 0, 0, 0}, {1, 0, 0, 0}, {2, -1, 0, 0}, {3, -3, 1, 0}, {4, -6, 4, -1}};

/* Bits a sum of `n` terms adds to the width of one term. */
static inline int flac_ceil_log2(int n) {
  int bits = 0;
  while ((1 << bits) < n) {
    bits++;
  }
  return bits;
}

enum FlacBlockType {
  FLAC_BLOCK_STREAMINFO = 0,
  FLAC_BLOCK_PADDING = 1,
  FLAC_BLOCK_APPLICATION = 2,
  FLAC_BLOCK_SEEKTABLE = 3,
  FLAC_BLOCK_VORBIS_COMMENT = 4,
  FLAC_BLOCK_CUESHEET = 5,
  FLAC_BLOCK_PICTURE = 6,
  FLAC_BLOCK_FORBIDDEN = 127, /* would read as a frame sync code */
};

/* How the channels of a frame are stored (RFC 9639 section 4.2). */
enum FlacChannelAssignment {
  FLAC_ASSIGN_INDEPENDENT = 0,
  FLAC_ASSIGN_LEFT_SIDE = 1,
  FLAC_ASSIGN_SIDE_RIGHT = 2,
  FLAC_ASSIGN_MID_SIDE = 3,
};

/* ── checksums ──────────────────────────────────────────────────── */

/* CRC-8 of a frame header: polynomial x^8 + x^2 + x + 1, initial value
 * 0, most significant bit first. */
uint8_t flac_crc8(const uint8_t* data, size_t size);
/* CRC-16 of a frame: polynomial x^16 + x^15 + x^2 + 1, initial value 0,
 * most significant bit first.  The update form continues a checksum. */
uint16_t flac_crc16_update(uint16_t crc, const uint8_t* data, size_t size);
static inline uint16_t flac_crc16(const uint8_t* data, size_t size) {
  return flac_crc16_update(0, data, size);
}

/* MD5 (RFC 1321). */
struct FlacMd5 {
  uint32_t state[4];
  uint64_t bytes;     /* message length so far */
  uint8_t buffer[64]; /* the block being filled */
};
void flac_md5_init(FlacMd5* m);
void flac_md5_update(FlacMd5* m, const uint8_t* data, size_t size);
void flac_md5_final(FlacMd5* m, uint8_t digest[16]);
/* Feeds interleaved samples the way STREAMINFO's MD5 is defined: each
 * sign-extended to a whole number of bytes, least significant byte
 * first (RFC 9639 section 8.2). */
void flac_md5_update_pcm(FlacMd5* m, const int32_t* samples, size_t count, int bits_per_sample);

/* ── coded numbers ──────────────────────────────────────────────── */

/* The frame or sample number of a frame header: the UTF-8 scheme
 * extended to 36 bits in 7 bytes (RFC 9639 section 9.1.5).  The writer
 * returns the bytes written (1 to 7; `out` holds 7), or 0 for a value
 * above 36 bits.  The reader returns the bytes consumed, 0 when `size`
 * bytes do not hold the whole number yet, or -1 for an invalid
 * sequence. */
int flac_coded_number_write(uint64_t value, uint8_t* out);
int flac_coded_number_read(const uint8_t* data, size_t size, uint64_t* value);

/* ── STREAMINFO ─────────────────────────────────────────────────── */

/* Reads and writes the 34 bytes of a STREAMINFO block.  The parser
 * returns false for values the format forbids: a block size below 16, a
 * minimum above the maximum, a bit depth below 4.  The writer returns
 * false for a field outside its range. */
bool flac_streaminfo_parse(const uint8_t* data, BaacFlacStreamInfo* info);
bool flac_streaminfo_write(const BaacFlacStreamInfo* info, uint8_t* out);

/* ── frame header ───────────────────────────────────────────────── */

struct FlacFrameHeader {
  bool variable_block_size; /* the blocking strategy: `number` counts samples, else frames */
  int block_size;           /* samples per channel, 1..65535 */
  int sample_rate;          /* Hz; 0: the header leaves it to STREAMINFO */
  int channels;             /* 1..8 */
  int assignment;           /* FlacChannelAssignment */
  int bits_per_sample;      /* 0: the header leaves it to STREAMINFO */
  uint64_t number;          /* frame number (31 bits) or sample number (36 bits) */
  int header_bytes;         /* sync code to CRC-8 */
};

/* Parses a frame header at `data`.  Returns its length, 0 when `size`
 * bytes do not hold the whole header yet, or -1 when the bytes are not
 * a frame header: no sync code, a reserved or forbidden field value, an
 * invalid coded number, or a CRC-8 mismatch.  Without `check_crc` the
 * CRC-8 is read past and not compared. */
int flac_frame_header_parse(const uint8_t* data, size_t size, FlacFrameHeader* h,
                            bool check_crc = true);

/* Writes a frame header with its CRC-8 into `out`
 * (FLAC_MAX_FRAME_HEADER_BYTES) and returns its length, or -1 when a
 * field cannot be expressed: a block size outside 1..65535, a sample
 * rate or bit depth no code exists for (0 for either writes "see
 * STREAMINFO"), a channel count or assignment out of range, a number
 * too large for the blocking strategy. */
int flac_frame_header_write(const FlacFrameHeader* h, uint8_t* out);

/* Whether a frame header can name the sample rate itself, as the
 * streamable subset demands. */
bool flac_sample_rate_is_codable(int sample_rate);

/* ── decoder (flac_dec.cpp) ─────────────────────────────────────── */

/* The decoder behind the public functions of flac.h, which check their
 * arguments and turn an allocation failure into AAC_ERR_MEMORY before
 * and after these.  Functions that size buffers may throw
 * std::bad_alloc. */
BaacFlacDecoder* flac_decoder_create();
void flac_decoder_destroy(BaacFlacDecoder* d);
void flac_decoder_reset(BaacFlacDecoder* d);
int flac_decoder_set_streaminfo(BaacFlacDecoder* d, const uint8_t* data);
int flac_decoder_set_md5_check(BaacFlacDecoder* d, bool enabled);
/* For fuzzing only, and not reachable through the public API: a frame
 * is taken whatever its CRC-8 and CRC-16 say, so that mutated input
 * reaches the code behind the checksums (RFC 9639 section 11 advises
 * this for fuzz testing). */
void flac_decoder_ignore_checksums(BaacFlacDecoder* d, bool ignore);
int flac_decoder_set_metadata(BaacFlacDecoder* d, bool collect, size_t max_bytes);
int flac_decoder_comments(const BaacFlacDecoder* d, const BaacFlacComment** comments,
                          BaacFlacComment* vendor);
int flac_decoder_pictures(const BaacFlacDecoder* d, const BaacFlacPicture** pictures);
int flac_decoder_skipped_metadata(const BaacFlacDecoder* d);
int flac_decoder_feed(BaacFlacDecoder* d, const uint8_t* data, int size);
int flac_decoder_decode_next(BaacFlacDecoder* d, int32_t* pcm, int pcm_size);
int flac_decoder_pending_bytes(const BaacFlacDecoder* d);
int flac_decoder_stream_info(const BaacFlacDecoder* d, BaacFlacStreamInfo* info);
int flac_decoder_last_frame_info(const BaacFlacDecoder* d, BaacFlacFrameInfo* info);
int flac_decoder_lost_frames(const BaacFlacDecoder* d);
int flac_decoder_seek_points(const BaacFlacDecoder* d, const BaacFlacSeekPoint** points);
int64_t flac_decoder_first_frame_offset(const BaacFlacDecoder* d);
int flac_decoder_seek_reset(BaacFlacDecoder* d);
int flac_decoder_end_input(BaacFlacDecoder* d);
int flac_decoder_finish(BaacFlacDecoder* d, BaacFlacMd5Verdict* verdict);

/* ── encoder (flac_enc.cpp) ─────────────────────────────────────── */

/* The encoder behind the public functions of flac.h, which check their
 * arguments first.  flac_encoder_check says whether a format can be
 * encoded (AAC_OK or the error for it); flac_encoder_create may throw
 * std::bad_alloc. */
int flac_encoder_check(int sample_rate, int channels, int bits_per_sample, int level);
BaacFlacEncoder* flac_encoder_create(int sample_rate, int channels, int bits_per_sample, int level);
void flac_encoder_destroy(BaacFlacEncoder* e);
int flac_encoder_set_options(BaacFlacEncoder* e, const BaacFlacEncoderOptions* options);
int flac_encoder_block_size(const BaacFlacEncoder* e);
int flac_encoder_header_bytes(const BaacFlacEncoder* e);
int flac_encoder_write_header(BaacFlacEncoder* e, uint8_t* out, int out_size);
int flac_encoder_max_output_bytes(const BaacFlacEncoder* e, int n_samples);
int flac_encoder_encode(BaacFlacEncoder* e, const int32_t* pcm, int n_samples, uint8_t* out,
                        int out_size);
int flac_encoder_finish(BaacFlacEncoder* e, uint8_t* out, int out_size);
int flac_encoder_last_frame_info(const BaacFlacEncoder* e, BaacFlacFrameInfo* info);
int flac_encoder_final_header(const BaacFlacEncoder* e, uint8_t* out, int out_size);
void flac_encoder_stream_info(const BaacFlacEncoder* e, BaacFlacStreamInfo* info);

/* ── linear prediction analysis (flac_lpc.cpp) ──────────────────── */

/* The floating-point side of the encoder.  It only proposes: every
 * residual is computed from the quantised coefficients in integers. */

#define FLAC_MAX_ANALYSIS_WINDOWS 9

/* Fills w[0 .. n) with window `index` of a set of `windows`: 0 is the
 * whole block, 1 and 2 its halves, 3 to 5 its thirds, 6 to 8 the block
 * without one third.  `taper_fraction` of the smallest part rises and
 * falls. */
void flac_lpc_window(double* w, int n, int windows, int index, double taper_fraction);
/* Where that window is not zero: one or two ranges, each a begin and an
 * end in `ranges`; returns their number.  Two ranges lie a third of the
 * block apart. */
int flac_lpc_window_ranges(int n, int index, int* ranges);
/* A raised cosine over n samples, which rises to its middle and falls
 * from there. */
void flac_lpc_hann(double* w, int n);
/* The Levinson-Durbin recursion.  Returns the highest order reached
 * (fewer than max_order when the prediction error runs out); the
 * predictor of order m is coefs[(m - 1) * FLAC_MAX_LPC_ORDER + j] for
 * j below m, the coefficient of the sample j + 1 back, and errors[m - 1]
 * its prediction error. */
int flac_lpc_levinson(const double* r, int max_order, double* coefs, double* errors);
/* Quantises a predictor to `precision` bits with the largest shift that
 * keeps its largest coefficient in range.  False when the coefficients
 * cannot be written: too large for the precision, or all zero. */
bool flac_lpc_quantize(const double* a, int order, int precision, int32_t* q, int* shift);
/* The prediction error the quantised predictor q / 2^shift leaves on the
 * windowed signal whose autocorrelation is r[0 .. order]: what the
 * recursion's error becomes once the coefficients are rounded. */
double flac_lpc_quantized_error(const double* r, const int32_t* q, int shift, int order);
/* The sum of the squares of a window. */
double flac_lpc_energy(const double* w, int n);
/* An estimate of the bits `samples` residual samples take when the
 * windowed prediction error is `error`: per sample half the logarithm of
 * the residual's variance plus `offset`, and never less than one bit. */
double flac_lpc_estimate_bits(double error, double window_energy, int samples, double offset);

/* ── tags and pictures (flac_meta.cpp) ──────────────────────────── */

/* Whether the bytes are well-formed UTF-8: no shorter forms, no
 * surrogates, nothing past U+10FFFF. */
bool flac_utf8_valid(const char* text, size_t size);

/* The encoder's side.  flac_meta_size checks the tags and pictures of
 * the options and gives the bytes their blocks take with their headers
 * (0 when there are none), or AAC_ERR_INVALID_ARG for what the format
 * does not take.  flac_meta_write then writes those blocks, each with
 * its header and without the last-block flag, and says where the header
 * of the last one begins. */
int flac_meta_size(const BaacFlacEncoderOptions* options, size_t* bytes);
void flac_meta_write(const BaacFlacEncoderOptions* options, uint8_t* out, size_t* last_header);

/* The decoder's side, on the content of a block that is there whole.
 * flac_meta_count_comments finds a VORBIS_COMMENT block sound and
 * returns its number of fields, or -1 when a length or the count does
 * not fit the block.  flac_meta_read_comments then fills the vendor and
 * that many fields with pointers into the block, and closes each string
 * with a zero byte in place: the block needs one spare byte behind its
 * `size` bytes for the last one.  flac_meta_read_picture does both for
 * a PICTURE block and returns false for one that is not sound. */
int flac_meta_count_comments(const uint8_t* block, size_t size);
void flac_meta_read_comments(uint8_t* block, BaacFlacComment* vendor, BaacFlacComment* comments);
bool flac_meta_read_picture(uint8_t* block, size_t size, BaacFlacPicture* picture);

/* ── kernels (flac_dsp.cpp and src/simd/flac_*.cpp) ─────────────── */

/* The hot loops of the codec behind one table of function pointers,
 * filled with the scalar kernels and then, as the processor allows, with
 * the SSE2, AVX2 or NEON ones (aac_get_cpu_flags, which BAAC_AAC_CPU
 * caps).  Every level computes the same values bit for bit, so that the
 * encoder writes the same stream whichever kernels run:
 *
 * - the integer kernels wrap like unsigned arithmetic or are given
 *   inputs that cannot overflow, so any order of evaluation agrees;
 * - the two floating-point kernels are elementwise or accumulate in one
 *   stated order, are compiled without the package's Release math
 *   options and without contraction, and use no fused multiply-add. */
struct FlacBitReader;

struct FlacDSP {
  /* y[i] = x[i] * w[i]. */
  void (*window)(const double* x, const double* w, int n, double* y);
  /* r[lag] = sum of y[i] * y[i - lag] for lag 0 to max_lag (at most
   * FLAC_MAX_LPC_ORDER).  The order of the accumulation is part of the
   * kernel: for each lag two partial sums, one over the even i and one
   * over the odd i, each taken with i ascending, and r[lag] is the even
   * sum plus the odd one. */
  void (*autocorrelation)(const double* y, int n, int max_lag, double* r);

  /* The magnitudes of the residuals of the fixed predictors of order 0
   * to max_order summed over x[max_order .. n), for samples of at most
   * 27 bits (so that no difference leaves 32 bits). */
  void (*fixed_sums)(const int32_t* x, int n, int max_order, uint64_t* sums);
  /* residual[i] = x[i] - ((sum over j of coefs[j] * x[i - 1 - j]) >>
   * shift) for i from order to n.  The 32-bit form is for a caller that
   * knows every sum and every residual to fit 32 bits; the 16-bit form
   * for one that knows in addition that every sample and every
   * coefficient fits 16.  The 64-bit form takes samples and coefficients
   * of any 32-bit value and returns whether every residual lies above
   * -2^31 and below 2^31. */
  void (*lpc_residual_16)(const int32_t* x, int n, int order, const int32_t* coefs, int shift,
                          int32_t* residual);
  void (*lpc_residual_32)(const int32_t* x, int n, int order, const int32_t* coefs, int shift,
                          int32_t* residual);
  bool (*lpc_residual_64)(const int32_t* x, int n, int order, const int32_t* coefs, int shift,
                          int32_t* residual);
  /* Folds n residual values to unsigned (zigzag), and returns their sum;
   * `*bits` receives all of them or-ed together. */
  uint64_t (*rice_fold)(const int32_t* residual, int n, uint32_t* folded, uint32_t* bits);
  /* For each of k_count parameters from k_low up, the sum of
   * folded[i] >> k over n values, into sums[0], sums[stride], ...
   * `bits` is what rice_fold gave for the values: all of them or-ed
   * together, which bounds each one. */
  void (*rice_sums)(const uint32_t* folded, int n, uint32_t bits, int k_low, int k_count,
                    uint64_t* sums, size_t stride);

  /* Rice code words of parameter k from the reader into out[0 ..
   * count), for as long as each lies whole in the reader's cache, which
   * is topped up on the way.  Returns how many were read: fewer than
   * `count` when the next code word is longer than the cache can hold,
   * runs past the input or has a value outside 32 bits, which is left
   * unread for the caller to take bit by bit. */
  int (*rice_decode)(FlacBitReader* r, int32_t* out, int count, int k);
  /* The decoder's prediction: s[order .. n) holds the residual on entry
   * and the samples on return, s[0 .. order) the warm-up samples.  The
   * 32-bit form wraps; the wide form sums in 64 bits. */
  void (*lpc_restore_32)(int32_t* s, int n, int order, const int32_t* coefs, int shift);
  void (*lpc_restore_wide)(int32_t* s, int n, int order, const int32_t* coefs, int shift);
  /* Two channel rows of up to 30-bit audio to interleaved left and
   * right, by FlacChannelAssignment: the stereo assignment is undone on
   * the way. */
  void (*stereo_out[4])(const int32_t* c0, const int32_t* c1, int n, int32_t* pcm);
};

/* Fills a table with the scalar kernels, then with those of the levels
 * the processor flags allow. */
void flac_dsp_init(FlacDSP* dsp);
/* The process-wide table, filled once on first use. */
const FlacDSP* flac_dsp_global(void);
void flac_dsp_init_sse2(FlacDSP* dsp);
void flac_dsp_init_avx2(FlacDSP* dsp);
void flac_dsp_init_neon(FlacDSP* dsp);

/* ── bit reader ─────────────────────────────────────────────────── */

/* Number of leading zero bits of a nonzero value. */
static inline int flac_clz64(uint64_t x) {
#if defined(_MSC_VER) && !defined(__clang__)
  unsigned long index;
#if defined(_M_X64) || defined(_M_ARM64)
  _BitScanReverse64(&index, x);
  return 63 - (int)index;
#else
  if (_BitScanReverse(&index, (unsigned long)(x >> 32))) {
    return 31 - (int)index;
  }
  _BitScanReverse(&index, (unsigned long)x);
  return 63 - (int)index;
#endif
#else
  return __builtin_clzll(x);
#endif
}

/* Numbers in the two byte orders the format uses: big-endian nearly
 * everywhere, little-endian in the tag block and in MD5.  The writers
 * return the position behind what they wrote. */
static inline uint64_t flac_load_be(const uint8_t* p, int bytes) {
  uint64_t v = 0;
  for (int i = 0; i < bytes; i++) {
    v = (v << 8) | p[i];
  }
  return v;
}

static inline uint8_t* flac_put_be(uint8_t* p, uint64_t v, int bytes) {
  for (int i = bytes - 1; i >= 0; i--) {
    *p++ = (uint8_t)(v >> (8 * i));
  }
  return p;
}

static inline uint32_t flac_load_le32(const uint8_t* p) {
  return (uint32_t)p[0] | ((uint32_t)p[1] << 8) | ((uint32_t)p[2] << 16) | ((uint32_t)p[3] << 24);
}

static inline uint8_t* flac_put_le32(uint8_t* p, uint32_t v) {
  p[0] = (uint8_t)v;
  p[1] = (uint8_t)(v >> 8);
  p[2] = (uint8_t)(v >> 16);
  p[3] = (uint8_t)(v >> 24);
  return p + 4;
}

/* The header of a metadata block: the flag of the last block, the type
 * and the length of the content in 24 bits. */
static inline uint8_t* flac_put_block_header(uint8_t* p, int type, bool last, size_t size) {
  *p++ = (uint8_t)((last ? 0x80 : 0) | type);
  return flac_put_be(p, size, 3);
}

/* One more in a counter that stops at the largest value it can hold: a
 * stream long enough to count past it cannot make it wrap. */
static inline void flac_count(int* counter) {
  if (*counter < INT32_MAX) {
    (*counter)++;
  }
}

static inline uint64_t flac_load_be64(const uint8_t* p) {
  return ((uint64_t)p[0] << 56) | ((uint64_t)p[1] << 48) | ((uint64_t)p[2] << 40) |
         ((uint64_t)p[3] << 32) | ((uint64_t)p[4] << 24) | ((uint64_t)p[5] << 16) |
         ((uint64_t)p[6] << 8) | (uint64_t)p[7];
}

/* Reads bits most significant first.  `cache` holds the unread bits with
 * the next one in bit 63; `bits` of them are counted as available, and
 * whatever lies below them is either zero or a preview of the bytes at
 * `next`, which a later refill supplies again.  Reading past the end
 * yields zeros and sets `overread`; nothing is ever read outside
 * [data, end). */
struct FlacBitReader {
  const uint8_t* data;
  const uint8_t* next; /* first byte not counted in `bits` yet */
  const uint8_t* end;
  uint64_t cache;
  int bits; /* 0..63 */
  bool overread;
};

static inline void flac_bitreader_init(FlacBitReader* r, const uint8_t* data, size_t size) {
  r->data = data;
  r->next = data;
  r->end = data + size;
  r->cache = 0;
  r->bits = 0;
  r->overread = false;
}

/* Tops a cache up to at least 56 bits while input remains.  The state
 * is passed piece by piece so that a loop can keep it in locals, where a
 * store to its output cannot make the compiler load it again. */
static inline void flac_refill(uint64_t& cache, int& bits, const uint8_t*& next,
                               const uint8_t* end) {
  if (end - next >= 8) {
    cache |= flac_load_be64(next) >> bits;
    next += (63 - bits) >> 3;
    bits |= 56;
    return;
  }
  while (bits <= 55 && next < end) {
    cache |= (uint64_t)*next++ << (56 - bits);
    bits += 8;
  }
}

static inline void flac_bitreader_refill(FlacBitReader* r) {
  flac_refill(r->cache, r->bits, r->next, r->end);
}

/* Moves to `bit` bits behind the start of the input, a position inside
 * it. */
static inline void flac_bitreader_seek(FlacBitReader* r, size_t bit) {
  r->next = r->data + bit / 8;
  r->cache = 0;
  r->bits = 0;
  r->overread = false;
  const int odd = (int)(bit & 7);
  if (odd) {
    r->cache = (uint64_t)*r->next++ << (56 + odd);
    r->bits = 8 - odd;
  }
}

/* Marks the reader as exhausted: every further read yields zeros. */
static inline void flac_bitreader_fail(FlacBitReader* r) {
  r->overread = true;
  r->cache = 0;
  r->bits = 0;
}

/* An unsigned value of 0 to 32 bits. */
static inline uint32_t flac_bitreader_read(FlacBitReader* r, int n) {
  if (n == 0) {
    return 0;
  }
  if (r->bits < n) {
    flac_bitreader_refill(r);
    if (r->bits < n) {
      /* The tail of the input: its bits, then zeros. */
      const uint64_t have = r->bits ? r->cache >> (64 - r->bits) << (64 - r->bits) : 0;
      flac_bitreader_fail(r);
      return (uint32_t)(have >> (64 - n));
    }
  }
  const uint32_t v = (uint32_t)(r->cache >> (64 - n));
  r->cache <<= n;
  r->bits -= n;
  return v;
}

/* A two's complement value of 1 to 33 bits (33 for the side channel of
 * 32-bit audio). */
static inline int64_t flac_bitreader_read_signed(FlacBitReader* r, int n) {
  uint64_t v;
  if (n > 32) {
    v = (uint64_t)flac_bitreader_read(r, n - 32) << 32;
    v |= flac_bitreader_read(r, 32);
  } else {
    v = flac_bitreader_read(r, n);
  }
  const uint64_t sign = (uint64_t)1 << (n - 1);
  return (int64_t)((v ^ sign) - sign);
}

/* A unary number: the count of zero bits before the next one bit, which
 * is consumed as well. */
static inline uint32_t flac_bitreader_read_unary(FlacBitReader* r) {
  uint32_t count = 0;
  for (;;) {
    if (r->cache) {
      const int zeros = flac_clz64(r->cache);
      if (zeros < r->bits) {
        r->cache <<= zeros + 1;
        r->bits -= zeros + 1;
        return count + (uint32_t)zeros;
      }
    }
    /* Every available bit is zero: take them all and look further. */
    count += (uint32_t)r->bits;
    r->cache <<= r->bits;
    r->bits = 0;
    flac_bitreader_refill(r);
    if (r->bits == 0) {
      flac_bitreader_fail(r);
      return count;
    }
  }
}

/* The loop of the rice_decode kernel, here so that every level compiles
 * the same statements, each with the instructions it may use: counting
 * leading zeros is one instruction on a processor that has it and a
 * slower sequence on one that does not.  The reader's cache, bit count
 * and position live in locals inside the loop, where a store to the
 * output cannot make the compiler load them again. */
#if defined(__GNUC__) || defined(__clang__)
#define FLAC_ALWAYS_INLINE inline __attribute__((always_inline))
#else
#define FLAC_ALWAYS_INLINE inline
#endif

static FLAC_ALWAYS_INLINE int flac_rice_decode_words(FlacBitReader* r, int32_t* FLAC_RESTRICT out,
                                                     int count, int k) {
  const uint32_t max_quotient = 0xFFFFFFFFu >> k;
  const uint8_t* const end = r->end;
  uint64_t cache = r->cache;
  int bits = r->bits;
  const uint8_t* next = r->next;
  int i = 0;
  for (; i < count; i++) {
    int zeros = cache ? flac_clz64(cache) : 64;
    int used = zeros + 1 + k;
    if (used > bits) {
      /* The cache may only have run low: top it up and look again. */
      flac_refill(cache, bits, next, end);
      zeros = cache ? flac_clz64(cache) : 64;
      used = zeros + 1 + k;
      if (used > bits) {
        break;
      }
    }
    if ((uint32_t)zeros > max_quotient) {
      break;
    }
    const uint32_t rest = k ? (uint32_t)((cache << (zeros + 1)) >> (64 - k)) : 0;
    cache <<= used;
    bits -= used;
    const uint32_t folded = ((uint32_t)zeros << k) | rest;
    out[i] = (int32_t)((folded >> 1) ^ (0u - (folded & 1)));
  }
  r->cache = cache;
  r->bits = bits;
  r->next = next;
  return i;
}

/* Bits consumed since the reader was initialised. */
static inline size_t flac_bitreader_position(const FlacBitReader* r) {
  return (size_t)(r->next - r->data) * 8 - (size_t)r->bits;
}

/* Skips the padding up to the next byte boundary. */
static inline void flac_bitreader_align(FlacBitReader* r) {
  const int pad = r->bits & 7;
  r->cache <<= pad;
  r->bits -= pad;
}

/* ── bit writer ─────────────────────────────────────────────────── */

/* Writes bits most significant first.  Up to 63 bits wait in the low
 * end of `cache`; whole 32-bit words are stored as soon as they are
 * complete.  Writing past the capacity sets `overflow` and stores
 * nothing further. */
struct FlacBitWriter {
  uint8_t* data;
  size_t capacity;
  size_t pos; /* bytes stored */
  uint64_t cache;
  int bits; /* pending in `cache`, 0..31 between calls */
  bool overflow;
};

static inline void flac_bitwriter_init(FlacBitWriter* w, uint8_t* data, size_t capacity) {
  w->data = data;
  w->capacity = capacity;
  w->pos = 0;
  w->cache = 0;
  w->bits = 0;
  w->overflow = false;
}

/* The low `n` bits of `value`, 0 to 32 of them. */
static inline void flac_bitwriter_write(FlacBitWriter* w, uint32_t value, int n) {
  if (n == 0) {
    return;
  }
  const uint64_t mask = n == 32 ? 0xFFFFFFFFu : (((uint64_t)1 << n) - 1);
  w->cache = (w->cache << n) | (value & mask);
  w->bits += n;
  if (w->bits >= 32) {
    const uint32_t word = (uint32_t)(w->cache >> (w->bits - 32));
    w->bits -= 32;
    if (w->capacity - w->pos < 4) {
      w->overflow = true;
      return;
    }
    w->data[w->pos] = (uint8_t)(word >> 24);
    w->data[w->pos + 1] = (uint8_t)(word >> 16);
    w->data[w->pos + 2] = (uint8_t)(word >> 8);
    w->data[w->pos + 3] = (uint8_t)word;
    w->pos += 4;
  }
}

/* A two's complement value in 1 to 33 bits. */
static inline void flac_bitwriter_write_signed(FlacBitWriter* w, int64_t value, int n) {
  if (n > 32) {
    flac_bitwriter_write(w, (uint32_t)((uint64_t)value >> 32), n - 32);
    n = 32;
  }
  flac_bitwriter_write(w, (uint32_t)(uint64_t)value, n);
}

/* `count` zero bits and a one bit. */
static inline void flac_bitwriter_write_unary(FlacBitWriter* w, uint32_t count) {
  while (count >= 32) {
    flac_bitwriter_write(w, 0, 32);
    count -= 32;
  }
  flac_bitwriter_write(w, 1, (int)count + 1);
}

/* Bits written so far. */
static inline size_t flac_bitwriter_bits(const FlacBitWriter* w) {
  return w->pos * 8 + (size_t)w->bits;
}

/* Pads with zero bits to a byte boundary and stores the pending bytes;
 * returns the bytes written in total. */
static inline size_t flac_bitwriter_flush(FlacBitWriter* w) {
  const int pad = (8 - (w->bits & 7)) & 7;
  w->cache <<= pad;
  w->bits += pad;
  while (w->bits > 0) {
    if (w->pos >= w->capacity) {
      w->overflow = true;
      w->bits = 0;
      break;
    }
    w->bits -= 8;
    w->data[w->pos++] = (uint8_t)(w->cache >> w->bits);
  }
  return w->pos;
}

#endif /* BAANDER_AAC_FLAC_INTERNAL_H */
