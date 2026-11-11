/* Lossless mode: an encoder and a decoder for native FLAC streams
 * (RFC 9639).  The handles and functions here are separate from the AAC
 * ones in aac.h: PCM crosses this API as interleaved signed 32-bit
 * integers at the stream's bit depth (a 16-bit sample is a value in
 * -32768..32767), never as float, so that every sample comes back bit
 * for bit.  The functions return the AacError codes of aac.h.
 *
 * Channel order is the one RFC 9639 section 9.1.3 assigns to each channel
 * count, and the library does not reorder channels:
 *   1  mono
 *   2  left, right
 *   3  left, right, centre
 *   4  front left, front right, back left, back right
 *   5  front left, front right, front centre, back left, back right
 *   6  front left, front right, front centre, LFE, back left, back right
 *   7  front left, front right, front centre, LFE, back centre, side left,
 *      side right
 *   8  front left, front right, front centre, LFE, back left, back right,
 *      side left, side right */
#ifndef BAANDER_AAC_FLAC_H
#define BAANDER_AAC_FLAC_H

#include "aac.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Limits of the format. */
#define BAAC_FLAC_MAX_CHANNELS 8
#define BAAC_FLAC_MIN_BITS_PER_SAMPLE 4
#define BAAC_FLAC_MAX_BITS_PER_SAMPLE 32
#define BAAC_FLAC_MIN_BLOCK_SIZE 16       /* of every block but a stream's last */
#define BAAC_FLAC_MAX_BLOCK_SIZE 65535    /* samples per channel in one frame */
#define BAAC_FLAC_MAX_SAMPLE_RATE 1048575 /* Hz; a frame header names rates up to 655350 */
#define BAAC_FLAC_STREAMINFO_BYTES 34     /* a STREAMINFO block without its 4-byte header */

/* Compression levels of the encoder. */
#define BAAC_FLAC_LEVEL_MIN 0
#define BAAC_FLAC_LEVEL_DEFAULT 5
#define BAAC_FLAC_LEVEL_MAX 8

/* The most seek points the decoder keeps from a stream's seek table; a
 * longer table is read up to here and the rest skipped. */
#define BAAC_FLAC_MAX_SEEK_POINTS 32768

/* A metadata block is at most this long: its length is a 24-bit number.
 * A stream's tags have to fit one block, and so has each picture with
 * its text: a picture of more than BAAC_FLAC_MAX_PICTURE_BYTES cannot be
 * stored in a FLAC stream at all, and one with a media type or a
 * description has that many bytes less. */
#define BAAC_FLAC_MAX_METADATA_BLOCK_BYTES 16777215
#define BAAC_FLAC_MAX_PICTURE_BYTES (BAAC_FLAC_MAX_METADATA_BLOCK_BYTES - 32)
#define BAAC_FLAC_MAX_PICTURE_TYPE 20

/* The bytes of tags and pictures the decoder holds for a caller that
 * asks for them and names no limit of its own. */
#define BAAC_FLAC_DEFAULT_METADATA_BYTES (32 * 1024 * 1024)

/* The properties a STREAMINFO block states about a stream. */
typedef struct BaacFlacStreamInfo_ {
  int min_block_size;     /* samples per channel; 0 when the stream carried no STREAMINFO */
  int max_block_size;     /* samples per channel; 0 when the stream carried no STREAMINFO */
  int min_frame_bytes;    /* 0: unknown */
  int max_frame_bytes;    /* 0: unknown */
  int sample_rate;        /* Hz */
  int channels;           /* 1..8 */
  int bits_per_sample;    /* 4..32 */
  uint64_t total_samples; /* per channel; 0: unknown */
  uint8_t md5[16];        /* of the unencoded samples; all zero: unknown */
} BaacFlacStreamInfo;

/* One frame as the encoder wrote it or the decoder read it. */
typedef struct BaacFlacFrameInfo_ {
  uint64_t sample_position; /* of the frame's first sample, counted per channel from the
                             * start of the stream */
  int block_size;           /* samples per channel in the frame */
  int bytes;                /* the frame from its sync code to its CRC-16 */
} BaacFlacFrameInfo;

/* One entry of a stream's seek table. */
typedef struct BaacFlacSeekPoint_ {
  uint64_t sample_position; /* of the first sample of the target frame */
  uint64_t byte_offset;     /* of the target frame, from the first byte of the first frame */
  int block_size;           /* samples per channel in the target frame */
} BaacFlacSeekPoint;

/* One tag: a field of a stream's VORBIS_COMMENT block, "NAME=value" in
 * UTF-8.  `length` counts its bytes.  What the decoder hands out is
 * followed by a zero byte, so that a tag without one inside reads as a
 * C string; the encoder takes `length` bytes and looks for no end. */
typedef struct BaacFlacComment_ {
  const char* text;
  uint32_t length;
} BaacFlacComment;

/* One picture: the content of a PICTURE block (RFC 9639 section 8.8).
 * The strings end with a zero byte. */
typedef struct BaacFlacPicture_ {
  uint32_t type;           /* 0..20 by RFC 9639 Table 13: 3 is the front cover, 4 the back */
  const char* mime_type;   /* "image/jpeg", "image/png", ...; "-->" when `data` is a URI */
  const char* description; /* UTF-8; the encoder takes NULL as none */
  uint32_t width;          /* pixels; 0: unknown */
  uint32_t height;         /* pixels; 0: unknown */
  uint32_t depth;          /* bits per pixel; 0: unknown */
  uint32_t colors;         /* of an indexed picture; 0 otherwise */
  const uint8_t* data;     /* the picture file as it is */
  uint32_t data_bytes;
} BaacFlacPicture;

/* What the decoder found when it compared the MD5 of the decoded samples
 * with the one in STREAMINFO. */
typedef enum BaacFlacMd5Verdict_ {
  BAAC_FLAC_MD5_UNKNOWN = 0,  /* not compared: checking was off, the stream states no MD5
                               * (or carried no STREAMINFO), or decoding did not run from
                               * the stream's first frame to its last: a stream joined in
                               * the middle, a seek, a stream that became unsupported */
  BAAC_FLAC_MD5_MATCH = 1,    /* the decoded samples are the encoded ones */
  BAAC_FLAC_MD5_MISMATCH = 2, /* they are not: the stream is damaged or was cut short */
} BaacFlacMd5Verdict;

typedef struct BaacFlacEncoder_ BaacFlacEncoder;
typedef struct BaacFlacDecoder_ BaacFlacDecoder;

/* ── Decoder ─────────────────────────────────────────────────────── */

/* The decoder reads a native FLAC stream from a byte FIFO in any
 * chunking: the fLaC marker with its metadata blocks and then frames, or
 * frames alone (a stream joined in the middle, or the samples of a
 * container that keeps STREAMINFO out of band).  It decodes fixed and
 * variable block sizes, every subframe type, 1 to 8 channels and 4 to 32
 * bits per sample, inside and outside the streamable subset.
 *
 * Two things that are not FLAC but are found around FLAC streams in
 * files are passed over: an ID3v2 tag in front of the marker, skipped by
 * the length it states (the reference decoder accepts such files too),
 * and an ID3v1 tag behind the last frame, which is 128 bytes that begin
 * with "TAG" and end the input (the reference decoder reports those as
 * lost sync; here every sample is decoded and verified before them, and
 * nothing is reported).  Any other bytes where a frame was expected are
 * damage.
 *
 * It verifies the CRC-8 of every frame header and the CRC-16 of every
 * frame.  A damaged frame is reported once as AAC_ERR_DECODE and never
 * concealed; the decoder then finds the next valid frame by itself, and
 * that frame's position says how many samples were lost.  A stream whose
 * sample rate, channel count or bit depth changes between frames ends
 * with AAC_ERR_UNSUPPORTED at the first frame that differs.
 *
 * Memory is bounded by the stream's own limits: metadata the decoder
 * does not use is skipped as it arrives, a frame that claims more
 * samples than STREAMINFO permits is refused before anything is sized
 * for it, and the FIFO holds 64 KiB or one frame of the largest size the
 * decoder accepts, whichever is more: 64 bits per sample of the stream's
 * largest block, twice what unencoded 32-bit audio takes (a frame beyond
 * that is treated as damaged).  Once the first frames are decoded the
 * decoder allocates nothing.  One decoder runs on the calling thread;
 * decoders are independent of each other. */

BaacFlacDecoder* baac_flac_decoder_create(void);
void baac_flac_decoder_destroy(BaacFlacDecoder* dec);

/* Starts over for a new stream: buffered input, the stream's properties
 * and the counters are dropped, the MD5 setting is kept. */
int baac_flac_decoder_reset(BaacFlacDecoder* dec);

/* Supplies a STREAMINFO block out of band (its 34 bytes without the
 * metadata block header, as an MP4 dfLa box or a Matroska CodecPrivate
 * carries it), for a stream that starts at a frame.  Accepted until a
 * frame is decoded or the stream delivers its own STREAMINFO, then
 * AAC_ERR_STATE; AAC_ERR_INVALID_ARG for another size or for values the
 * format forbids. */
int baac_flac_decoder_set_streaminfo(BaacFlacDecoder* dec, const uint8_t* streaminfo, int size);

/* Tags and pictures.  By default the decoder skips them unread, however
 * large.  With `collect` 1 it keeps the stream's VORBIS_COMMENT block
 * and its PICTURE blocks, up to `max_bytes` in all (0:
 * BAAC_FLAC_DEFAULT_METADATA_BYTES): the content of each block counts,
 * and a little for each block and each tag besides, so that a stream of
 * a million empty fields is held to the limit too.  A block that would
 * go past the limit is skipped whole and counted by
 * baac_flac_decoder_skipped_metadata; the audio decodes all the same.
 * A block is held in memory of its own length, which is reserved once
 * 64 KiB of it have arrived and not on the word of its header alone,
 * and nothing is sized from a length or a count inside a block: one
 * whose inner lengths or counts do not fit it is dropped and reported
 * as one AAC_ERR_DECODE.  Before the first byte is
 * fed, then AAC_ERR_STATE. */
int baac_flac_decoder_set_metadata(BaacFlacDecoder* dec, int collect, size_t max_bytes);

/* The tags of the stream, in the order the stream has them: returns
 * their number and points `*comments` at them.  `*vendor` (may be NULL)
 * receives the vendor string of the block, which names the program that
 * wrote the stream.  Zero, and a vendor without text, when the stream
 * has no tags, when they were not collected, and until the metadata has
 * been read (baac_flac_decoder_first_frame_offset says when).  Valid
 * until the next reset or destroy. */
int baac_flac_decoder_comments(BaacFlacDecoder* dec, const BaacFlacComment** comments,
                               BaacFlacComment* vendor);

/* The pictures of the stream in its order: returns their number and
 * points `*pictures` at them, on the same terms as the tags. */
int baac_flac_decoder_pictures(BaacFlacDecoder* dec, const BaacFlacPicture** pictures);

/* How many tag and picture blocks were skipped because they would have
 * gone past the limit of baac_flac_decoder_set_metadata. */
int baac_flac_decoder_skipped_metadata(BaacFlacDecoder* dec);

/* MD5 verification of the decoded samples against STREAMINFO: 1 on, 0
 * off (default).  Before the first frame is decoded, then
 * AAC_ERR_STATE.  The frame checksums are verified in either case. */
int baac_flac_decoder_set_md5_check(BaacFlacDecoder* dec, int enabled);

/* Appends bytes to the input FIFO and returns the number taken: fewer
 * than `size` when the FIFO is full, which means a frame is waiting for
 * baac_flac_decoder_decode_next.  Metadata that is being skipped is
 * taken without being stored. */
int baac_flac_decoder_feed(BaacFlacDecoder* dec, const uint8_t* data, int size);

/* Decodes the next frame into `pcm`, interleaved, and returns its
 * samples per channel (its block size).  `pcm_size` counts the int32
 * values `pcm` holds; a frame needs its block size times the channel
 * count, at most max_block_size times channels of the stream's
 * STREAMINFO, and never more than BAAC_FLAC_MAX_BLOCK_SIZE times
 * BAAC_FLAC_MAX_CHANNELS.  A frame is returned as soon as its last byte
 * is in the FIFO; the decoder does not wait for the frame after it, so a
 * caller may feed one frame at a time.
 *
 * Returns 0 when no complete frame is pending (feed more),
 * AAC_ERR_OVERFLOW when `pcm` is too small (the frame stays pending),
 * AAC_ERR_DECODE once for each damaged stretch of the stream (the call
 * after it continues at the next valid frame; a metadata block that
 * cannot be read counts as one), and AAC_ERR_UNSUPPORTED for a frame
 * that leaves its sample rate or bit depth to a STREAMINFO the decoder
 * was not given (until baac_flac_decoder_set_streaminfo supplies one)
 * or whose sample rate, channel count or bit depth differs from the
 * stream's (then on every call until a reset or a seek). */
int baac_flac_decoder_decode_next(BaacFlacDecoder* dec, int32_t* pcm, int pcm_size);

/* Bytes waiting in the FIFO. */
int baac_flac_decoder_pending_bytes(BaacFlacDecoder* dec);

/* The stream's properties: STREAMINFO when the stream carried one or
 * the caller supplied it; otherwise the sample rate, channel count and
 * bit depth of the first decoded frame with every other field zero.
 * AAC_ERR_STATE while neither is known. */
int baac_flac_decoder_stream_info(BaacFlacDecoder* dec, BaacFlacStreamInfo* info);

/* The last frame decoded.  AAC_ERR_STATE before the first. */
int baac_flac_decoder_last_frame_info(BaacFlacDecoder* dec, BaacFlacFrameInfo* info);

/* How many damaged stretches were reported as AAC_ERR_DECODE since the
 * stream began.  Each covers one frame or several that follow each
 * other. */
int baac_flac_decoder_lost_frames(BaacFlacDecoder* dec);

/* The seek points of the stream's seek table, in ascending order of
 * sample position, placeholders left out: returns their number and
 * points `*points` at them (valid until the next reset or destroy).
 * Zero when the stream has no seek table. */
int baac_flac_decoder_seek_points(BaacFlacDecoder* dec, const BaacFlacSeekPoint** points);

/* The byte offset of the first frame from the start of the stream (the
 * length of the marker and the metadata), which the byte offsets of the
 * seek points count from.  AAC_ERR_STATE until the metadata has been
 * read, and for a stream that began at a frame. */
int64_t baac_flac_decoder_first_frame_offset(BaacFlacDecoder* dec);

/* For a seek: drops the buffered input.  The bytes fed next may begin
 * anywhere in the stream; decoding resumes at the first valid frame
 * found, whose position baac_flac_decoder_last_frame_info reports, and
 * nothing is reported as lost on the way to it.  The stream's properties
 * and its seek table are kept.  The MD5 verdict of the stream becomes
 * unknown. */
int baac_flac_decoder_seek_reset(BaacFlacDecoder* dec);

/* Tells the decoder that no more bytes follow.  A frame the input ends
 * in is then damage and not a wait for more:
 * baac_flac_decoder_decode_next reports it, goes on to any valid frame
 * behind it, and returns 0 once the FIFO is spent.  Without this call a
 * damaged frame near the end of a stream can hold back the valid frames
 * after it, because its garbled length reaches past the last byte.
 * baac_flac_decoder_feed returns AAC_ERR_STATE afterwards, until a reset
 * or a seek. */
int baac_flac_decoder_end_input(BaacFlacDecoder* dec);

/* Ends the stream.  Bytes still in the FIFO where a frame was expected
 * are a frame cut short, and a metadata block the input ended in is no
 * better: either counts as one more damaged stretch and the call
 * returns AAC_ERR_DECODE, otherwise AAC_OK.  `*verdict` (may be NULL)
 * receives the result of the MD5 comparison.  Afterwards the decoder
 * takes no input until baac_flac_decoder_reset. */
int baac_flac_decoder_finish(BaacFlacDecoder* dec, BaacFlacMd5Verdict* verdict);

/* ── Encoder ─────────────────────────────────────────────────────── */

/* The encoder writes native FLAC in the streamable subset with one fixed
 * block size per stream.  It takes 1 to 8 channels, bit depths of 8, 12,
 * 16, 20, 24 and 32, and every sample rate a frame header can express:
 * the rates of RFC 9639 Table 15, whole kilohertz up to 255 kHz, any
 * rate up to 65535 Hz and multiples of 10 Hz up to 655350 Hz.  Anything
 * else is refused; nothing is ever coded with loss.
 *
 * The level sets how much the encoder searches, within the ceilings of
 * the reference encoder's preset of the same number: blocks of 1152
 * samples and fixed predictors at levels 0 to 2, blocks of 4096 and
 * linear predictors up to order 6, 8 or 12 from level 3, 4 and 7, and
 * one, three or nine analysis windows up to level 5, at 6 and 7, and at
 * 8.  Linear predictors stay at order 12 or below at every sample rate,
 * 5-bit Rice parameters are used above 16 bits only, and at 16 bits and
 * below every prediction fits a 32-bit sum.  The encoder sizes its
 * buffers when it is created and allocates nothing while a stream runs
 * (the header of a stream with a seek table reserves its points).  One
 * encoder runs on the calling thread; encoders are independent of each
 * other.
 *
 * A stream is written in this order: baac_flac_encoder_write_header,
 * baac_flac_encoder_encode any number of times, baac_flac_encoder_finish.
 * A caller whose output can seek then writes the bytes of
 * baac_flac_encoder_final_header over the first header, which puts the
 * sample total, the MD5 and the seek points into the stream; without
 * that step the stream is complete and decodable, with a total and an
 * MD5 of zero. */

typedef struct BaacFlacEncoderOptions_ {
  /* Bytes of a PADDING block that closes the metadata, as room for tags
   * added later without rewriting the file.  0 (default) writes none. */
  int padding_bytes;
  /* Samples per channel the stream will hold, when the caller knows it
   * up front; 0 (default) for unknown.  It sizes the seek table. */
  uint64_t total_samples_hint;
  /* 1 reserves a seek table in the header, with a point for every ten
   * seconds of `total_samples_hint` (spaced wider when that would take
   * more than BAAC_FLAC_MAX_SEEK_POINTS), to be filled by the final
   * header; 0 (default) writes none, and so does a hint of 0.  For
   * callers that will rewrite the header: a reserved table that is never
   * filled holds placeholders only. */
  int seek_table;
  /* Tags: `comment_count` fields of "NAME=value" for a VORBIS_COMMENT
   * block, written in this order behind the seek table.  A name is one
   * or more printable ASCII characters other than '=' (0x20 to 0x7E),
   * a value any UTF-8, and repeated names are fine.  `vendor` names the
   * writing program in that block; NULL is the library's own name.
   * Without comments and without a vendor no block is written. */
  const BaacFlacComment* comments;
  int comment_count;
  const char* vendor;
  /* Pictures: `picture_count` PICTURE blocks behind the tags, in this
   * order.  A type up to BAAC_FLAC_MAX_PICTURE_TYPE, of the types 1 and
   * 2 (the file icons) one each at most, a media type of printable
   * ASCII, a description in UTF-8, and a block within
   * BAAC_FLAC_MAX_METADATA_BLOCK_BYTES.  The library stores the bytes
   * and the figures as given; it does not look into the picture. */
  const BaacFlacPicture* pictures;
  int picture_count;
} BaacFlacEncoderOptions;

/* Fills every field with its default, so that a caller sets only what it
 * changes and stays correct when the structure grows. */
void baac_flac_encoder_options_init(BaacFlacEncoderOptions* options);

/* `level` is BAAC_FLAC_LEVEL_MIN..BAAC_FLAC_LEVEL_MAX.  Returns NULL on
 * failure with the reason in `*error` (may be NULL): AAC_ERR_INVALID_ARG
 * for a channel count, bit depth, sample rate or level outside the
 * ranges above, AAC_ERR_UNSUPPORTED for a sample rate within
 * BAAC_FLAC_MAX_SAMPLE_RATE that no frame header can express,
 * AAC_ERR_MEMORY. */
BaacFlacEncoder* baac_flac_encoder_create(int sample_rate, int channels, int bits_per_sample,
                                          int level, int* error);
void baac_flac_encoder_destroy(BaacFlacEncoder* enc);

/* Tags and pictures are checked here, before a byte of the stream is
 * written, and copied: the caller's memory is not used after the call.
 * AAC_ERR_INVALID_ARG names a comment without '=' or with a character a
 * name may not have, text that is not UTF-8, a picture type or media
 * type the format does not allow, a second picture of type 1 or 2, and
 * a block longer than the format's limit.  The header then grows by the
 * blocks, which baac_flac_encoder_header_bytes reports.
 *
 * Before the header of a stream is written, or after its finish for the
 * next stream; AAC_ERR_STATE while a stream runs.  AAC_ERR_INVALID_ARG
 * for padding outside 0 to 16777215 bytes and for a total beyond 36
 * bits. */
int baac_flac_encoder_set_options(BaacFlacEncoder* enc, const BaacFlacEncoderOptions* options);

/* Samples per channel in every frame but the last of a stream. */
int baac_flac_encoder_block_size(BaacFlacEncoder* enc);

/* Starts a stream: writes the fLaC marker and the metadata blocks and
 * returns their length, which baac_flac_encoder_header_bytes reports
 * beforehand; AAC_ERR_OVERFLOW when `out_size` is smaller, AAC_ERR_STATE
 * while a stream runs.  A caller that stores frames without the header,
 * in a container, writes it to a buffer of its own. */
int baac_flac_encoder_header_bytes(BaacFlacEncoder* enc);
int baac_flac_encoder_write_header(BaacFlacEncoder* enc, uint8_t* out, int out_size);

/* Takes `n_samples` samples per channel from `pcm`, interleaved, and
 * writes every whole frame they complete; returns the bytes written,
 * which is 0 while a block is still filling.  A sample outside the bit
 * depth is refused with AAC_ERR_INVALID_ARG, a call before the header
 * with AAC_ERR_STATE, and an output buffer smaller than
 * baac_flac_encoder_max_output_bytes(enc, n_samples) with
 * AAC_ERR_OVERFLOW; a refused call consumes no input.
 *
 * baac_flac_encoder_max_output_bytes is the most a call with that many
 * samples can write, counting what is buffered already: every channel
 * of every frame unencoded, which no frame exceeds.  With 0 samples it
 * is the bound of baac_flac_encoder_finish.  AAC_ERR_INVALID_ARG when
 * the bound does not fit an int; feed fewer samples per call then. */
int baac_flac_encoder_max_output_bytes(BaacFlacEncoder* enc, int n_samples);
int baac_flac_encoder_encode(BaacFlacEncoder* enc, const int32_t* pcm, int n_samples, uint8_t* out,
                             int out_size);

/* Writes the samples still buffered as the stream's last, shorter
 * frame and returns its bytes (0 when none were buffered); the output
 * buffer follows baac_flac_encoder_max_output_bytes(enc, 0).  The next
 * baac_flac_encoder_write_header starts a new stream, identical to a
 * fresh encoder's. */
int baac_flac_encoder_finish(BaacFlacEncoder* enc, uint8_t* out, int out_size);

/* The last frame written: its bytes, its samples and its position, as a
 * container's muxer needs them (a call that completes several frames
 * leaves the last of them here; feed a block per call to see each).
 * AAC_ERR_STATE before the first frame of a stream. */
int baac_flac_encoder_last_frame_info(BaacFlacEncoder* enc, BaacFlacFrameInfo* info);

/* After baac_flac_encoder_finish: the header again, of the same length,
 * with the sample total, the MD5, the frame size range and the seek
 * points of the finished stream, for the caller to write at offset 0.
 * AAC_ERR_STATE before the finish. */
int baac_flac_encoder_final_header(BaacFlacEncoder* enc, uint8_t* out, int out_size);

/* The STREAMINFO of the stream as far as it is known: the totals and the
 * MD5 are zero until baac_flac_encoder_finish. */
int baac_flac_encoder_stream_info(BaacFlacEncoder* enc, BaacFlacStreamInfo* info);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_FLAC_H */
