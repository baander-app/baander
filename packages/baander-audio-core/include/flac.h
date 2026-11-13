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
#ifndef BAANDER_AUDIO_CORE_FLAC_H
#define BAANDER_AUDIO_CORE_FLAC_H

#include "aac.h"

#ifdef __cplusplus
extern "C" {
#endif

/* What the functions return.  A function that returns int gives a
 * count or AAC_OK (0) when it succeeds and a negative AacError when it
 * does not.  Every function answers a null handle or a null pointer it
 * needs with AAC_ERR_INVALID_ARG and changes nothing.  The other codes
 * mean the same wherever they occur, and each function below names the
 * ones it can return:
 *
 *   AAC_ERR_INVALID_ARG  a value outside the range the function states
 *   AAC_ERR_STATE        the call does not fit where the stream is
 *                        (before its header, after its finish, ...);
 *                        nothing was changed
 *   AAC_ERR_OVERFLOW     the caller's buffer is smaller than the function
 *                        requires; nothing was consumed or written
 *   AAC_ERR_MEMORY       an allocation failed; the call can be made again
 *   AAC_ERR_DECODE       decoder: a damaged stretch of the stream
 *   AAC_ERR_UNSUPPORTED  a stream or a configuration the library does
 *                        not handle
 *   AAC_ERR_ENCODE       encoder: the stream cannot take more frames */

/* Limits of the format. */
#define BAC_FLAC_MAX_CHANNELS 8
#define BAC_FLAC_MIN_BITS_PER_SAMPLE 4
#define BAC_FLAC_MAX_BITS_PER_SAMPLE 32
#define BAC_FLAC_MIN_BLOCK_SIZE 16       /* of every block but a stream's last */
#define BAC_FLAC_MAX_BLOCK_SIZE 65535    /* samples per channel in one frame */
#define BAC_FLAC_MAX_SAMPLE_RATE 1048575 /* Hz; a frame header names rates up to 655350 */
#define BAC_FLAC_STREAMINFO_BYTES 34     /* a STREAMINFO block without its 4-byte header */

/* Compression levels of the encoder. */
#define BAC_FLAC_LEVEL_MIN 0
#define BAC_FLAC_LEVEL_DEFAULT 5
#define BAC_FLAC_LEVEL_MAX 8

/* The most seek points the decoder keeps from a stream's seek table; a
 * longer table is read up to here and the rest skipped. */
#define BAC_FLAC_MAX_SEEK_POINTS 32768

/* A metadata block is at most this long: its length is a 24-bit number.
 * A stream's tags have to fit one block, and so has each picture with
 * its text: a picture of more than BAC_FLAC_MAX_PICTURE_BYTES cannot be
 * stored in a FLAC stream at all, and one with a media type or a
 * description has that many bytes less. */
#define BAC_FLAC_MAX_METADATA_BLOCK_BYTES 16777215
#define BAC_FLAC_MAX_PICTURE_BYTES (BAC_FLAC_MAX_METADATA_BLOCK_BYTES - 32)
#define BAC_FLAC_MAX_PICTURE_TYPE 20

/* The bytes of tags and pictures the decoder holds for a caller that
 * asks for them and names no limit of its own. */
#define BAC_FLAC_DEFAULT_METADATA_BYTES (32 * 1024 * 1024)

/* The properties a STREAMINFO block states about a stream. */
typedef struct BacFlacStreamInfo_ {
  int min_block_size;     /* samples per channel; 0 when the stream carried no STREAMINFO */
  int max_block_size;     /* samples per channel; 0 when the stream carried no STREAMINFO */
  int min_frame_bytes;    /* 0: unknown */
  int max_frame_bytes;    /* 0: unknown */
  int sample_rate;        /* Hz */
  int channels;           /* 1..8 */
  int bits_per_sample;    /* 4..32 */
  uint64_t total_samples; /* per channel; 0: unknown */
  uint8_t md5[16];        /* of the unencoded samples; all zero: unknown */
} BacFlacStreamInfo;

/* One frame as the encoder wrote it or the decoder read it. */
typedef struct BacFlacFrameInfo_ {
  uint64_t sample_position; /* of the frame's first sample, counted per channel from the
                             * start of the stream */
  int block_size;           /* samples per channel in the frame */
  int bytes;                /* the frame from its sync code to its CRC-16 */
} BacFlacFrameInfo;

/* One entry of a stream's seek table. */
typedef struct BacFlacSeekPoint_ {
  uint64_t sample_position; /* of the first sample of the target frame */
  uint64_t byte_offset;     /* of the target frame, from the first byte of the first frame */
  int block_size;           /* samples per channel in the target frame */
} BacFlacSeekPoint;

/* One tag: a field of a stream's VORBIS_COMMENT block, "NAME=value" in
 * UTF-8.  `length` counts its bytes.  What the decoder hands out is
 * followed by a zero byte, so that a tag without one inside reads as a
 * C string; the encoder takes `length` bytes and looks for no end. */
typedef struct BacFlacComment_ {
  const char* text;
  uint32_t length;
} BacFlacComment;

/* One picture: the content of a PICTURE block (RFC 9639 section 8.8).
 * The strings end with a zero byte. */
typedef struct BacFlacPicture_ {
  uint32_t type;           /* 0..20 by RFC 9639 Table 13: 3 is the front cover, 4 the back */
  const char* mime_type;   /* "image/jpeg", "image/png", ...; "-->" when `data` is a URI */
  const char* description; /* UTF-8; the encoder takes NULL as none */
  uint32_t width;          /* pixels; 0: unknown */
  uint32_t height;         /* pixels; 0: unknown */
  uint32_t depth;          /* bits per pixel; 0: unknown */
  uint32_t colors;         /* of an indexed picture; 0 otherwise */
  const uint8_t* data;     /* the picture file as it is */
  uint32_t data_bytes;
} BacFlacPicture;

/* What the decoder found when it compared the MD5 of the decoded samples
 * with the one in STREAMINFO. */
typedef enum BacFlacMd5Verdict_ {
  BAC_FLAC_MD5_UNKNOWN = 0,  /* not compared: checking was off, the stream states no MD5
                               * (or carried no STREAMINFO), or decoding did not run from
                               * the stream's first frame to its last: a stream joined in
                               * the middle, a seek, a stream that became unsupported */
  BAC_FLAC_MD5_MATCH = 1,    /* the decoded samples are the encoded ones */
  BAC_FLAC_MD5_MISMATCH = 2, /* they are not: the stream is damaged or was cut short */
} BacFlacMd5Verdict;

typedef struct BacFlacEncoder_ BacFlacEncoder;
typedef struct BacFlacDecoder_ BacFlacDecoder;

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
 * with "TAG" and end the input.  In the second case this decoder and
 * the reference one differ: `flac` reports those bytes as lost sync,
 * while here every sample is decoded and verified before them and no
 * error is raised.  Neither tag is read, and neither goes unsaid:
 * bac_flac_decoder_id3 tells what was passed over.  Any other bytes
 * where a frame was expected are damage.
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

BacFlacDecoder* bac_flac_decoder_create(void);
void bac_flac_decoder_destroy(BacFlacDecoder* dec);

/* Starts over for a new stream: buffered input, the stream's properties
 * and the counters are dropped, the MD5 setting is kept.  AAC_OK. */
int bac_flac_decoder_reset(BacFlacDecoder* dec);

/* Supplies a STREAMINFO block out of band (its 34 bytes without the
 * metadata block header, as an MP4 dfLa box or a Matroska CodecPrivate
 * carries it), for a stream that starts at a frame.  Accepted until a
 * frame is decoded or the stream delivers its own STREAMINFO, then
 * AAC_ERR_STATE; AAC_ERR_INVALID_ARG for another size or for values the
 * format forbids; AAC_ERR_MEMORY when the buffers the stream needs could
 * not be sized. */
int bac_flac_decoder_set_streaminfo(BacFlacDecoder* dec, const uint8_t* streaminfo, int size);

/* Tags and pictures.  By default the decoder skips them unread, however
 * large.  With `collect` 1 it keeps the stream's VORBIS_COMMENT block
 * and its PICTURE blocks, up to `max_bytes` in all (0:
 * BAC_FLAC_DEFAULT_METADATA_BYTES): the content of each block counts,
 * and a little for each block and each tag besides, so that a stream of
 * a million empty fields is held to the limit too.  A block that would
 * go past the limit is skipped whole and counted by
 * bac_flac_decoder_skipped_metadata; the audio decodes all the same.
 * A block is held in memory of its own length, which is reserved once
 * 64 KiB of it have arrived and not on the word of its header alone,
 * and nothing is sized from a length or a count inside a block: one
 * whose inner lengths or counts do not fit it is dropped and reported
 * as one AAC_ERR_DECODE.  Before the first byte is
 * fed, then AAC_ERR_STATE. */
int bac_flac_decoder_set_metadata(BacFlacDecoder* dec, int collect, size_t max_bytes);

/* The tags of the stream, in the order the stream has them: returns
 * their number and points `*comments` at them.  `*vendor` (may be NULL)
 * receives the vendor string of the block, which names the program that
 * wrote the stream.  Zero, and a vendor without text, when the stream
 * has no tags, when they were not collected, and until the metadata has
 * been read (bac_flac_decoder_first_frame_offset says when).  Valid
 * until the next reset or destroy. */
int bac_flac_decoder_comments(BacFlacDecoder* dec, const BacFlacComment** comments,
                               BacFlacComment* vendor);

/* The pictures of the stream in its order: returns their number and
 * points `*pictures` at them, on the same terms as the tags. */
int bac_flac_decoder_pictures(BacFlacDecoder* dec, const BacFlacPicture** pictures);

/* How many tag and picture blocks were skipped because they would have
 * gone past the limit of bac_flac_decoder_set_metadata. */
int bac_flac_decoder_skipped_metadata(BacFlacDecoder* dec);

/* What the decoder passed over around the stream that is not FLAC.
 * `*id3v2_bytes` (may be NULL) receives the bytes of the ID3v2 tags in
 * front of the fLaC marker, headers included; 0 when the input began
 * with the stream.  The offsets the decoder reports count from the
 * first byte it was fed, so they include these bytes.  `*id3v1_trailer`
 * (may be NULL) receives 1 when an ID3v1 tag of 128 bytes behind the
 * last frame was passed over, which is known once the input has ended
 * (bac_flac_decoder_end_input and the calls that drain the decoder, or
 * bac_flac_decoder_finish), and 0 otherwise.  AAC_OK.  A reset clears
 * both; a seek keeps them. */
int bac_flac_decoder_id3(BacFlacDecoder* dec, uint64_t* id3v2_bytes, int* id3v1_trailer);

/* MD5 verification of the decoded samples against STREAMINFO: 1 on, 0
 * off (default).  Before the first frame is decoded, then
 * AAC_ERR_STATE.  The frame checksums are verified in either case. */
int bac_flac_decoder_set_md5_check(BacFlacDecoder* dec, int enabled);

/* Appends bytes to the input FIFO and returns the number taken: fewer
 * than `size` when the FIFO is full, which means a frame is waiting for
 * bac_flac_decoder_decode_next.  Metadata that is being skipped is
 * taken without being stored.  AAC_ERR_STATE after
 * bac_flac_decoder_end_input and after bac_flac_decoder_finish, until
 * a reset or a seek; AAC_ERR_MEMORY when memory for a block of tags or
 * pictures could not be had and no byte was taken, in which case the
 * same bytes can be fed again. */
int bac_flac_decoder_feed(BacFlacDecoder* dec, const uint8_t* data, int size);

/* Decodes the next frame into `pcm`, interleaved, and returns its
 * samples per channel (its block size).  `pcm_size` counts the int32
 * values `pcm` holds; a frame needs its block size times the channel
 * count, at most max_block_size times channels of the stream's
 * STREAMINFO, and never more than BAC_FLAC_MAX_BLOCK_SIZE times
 * BAC_FLAC_MAX_CHANNELS.  A frame is returned as soon as its last byte
 * is in the FIFO; the decoder does not wait for the frame after it, so a
 * caller may feed one frame at a time.
 *
 * Returns 0 when no complete frame is pending (feed more),
 * AAC_ERR_OVERFLOW when `pcm` is too small (the frame stays pending),
 * AAC_ERR_DECODE once for each damaged stretch of the stream (the call
 * after it continues at the next valid frame; a metadata block that
 * cannot be read counts as one), and AAC_ERR_UNSUPPORTED for a frame
 * that leaves its sample rate or bit depth to a STREAMINFO the decoder
 * was not given (until bac_flac_decoder_set_streaminfo supplies one)
 * or whose sample rate, channel count or bit depth differs from the
 * stream's (then on every call until a reset or a seek).  A sync code
 * with a header that leaves the depth open counts as such a frame only
 * when a frame follows it: the decoder reads it at every depth and
 * takes the one whose CRC-16 holds.  AAC_ERR_STATE after
 * bac_flac_decoder_finish, and AAC_ERR_MEMORY when the buffers for a
 * stream that came without STREAMINFO could not be sized.
 *
 * In the search for a frame behind damage the decoder reads what looks
 * like one to its end.  What it reads in vain is bounded: beyond the
 * size of one frame, four bytes at most for each byte of input it
 * passes.  While that account is overdrawn it passes sync codes unread,
 * so a stream built to make the search expensive may lose a frame that
 * follows such a stretch closely; it cannot stall the call. */
int bac_flac_decoder_decode_next(BacFlacDecoder* dec, int32_t* pcm, int pcm_size);

/* Bytes waiting in the FIFO. */
int bac_flac_decoder_pending_bytes(BacFlacDecoder* dec);

/* The stream's properties: STREAMINFO when the stream carried one or
 * the caller supplied it; otherwise the sample rate, channel count and
 * bit depth of the first decoded frame with every other field zero.
 * AAC_ERR_STATE while neither is known. */
int bac_flac_decoder_stream_info(BacFlacDecoder* dec, BacFlacStreamInfo* info);

/* The last frame decoded.  AAC_ERR_STATE before the first. */
int bac_flac_decoder_last_frame_info(BacFlacDecoder* dec, BacFlacFrameInfo* info);

/* How many damaged stretches were reported as AAC_ERR_DECODE since the
 * stream began.  Each covers one frame or several that follow each
 * other. */
int bac_flac_decoder_lost_frames(BacFlacDecoder* dec);

/* The seek points of the stream's seek table, in ascending order of
 * sample position, placeholders left out: returns their number and
 * points `*points` at them (valid until the next reset or destroy).
 * Zero when the stream has no seek table. */
int bac_flac_decoder_seek_points(BacFlacDecoder* dec, const BacFlacSeekPoint** points);

/* The byte offset of the first frame from the start of the stream (the
 * length of the marker and the metadata), which the byte offsets of the
 * seek points count from.  AAC_ERR_STATE until the metadata has been
 * read, and for a stream that began at a frame. */
int64_t bac_flac_decoder_first_frame_offset(BacFlacDecoder* dec);

/* For a seek: drops the buffered input.  The bytes fed next may begin
 * anywhere in the stream; decoding resumes at the first valid frame
 * found, whose position bac_flac_decoder_last_frame_info reports, and
 * nothing is reported as lost on the way to it.  The stream's properties
 * and its seek table are kept.  The MD5 verdict of the stream becomes
 * unknown.  AAC_OK, or AAC_ERR_STATE after bac_flac_decoder_finish. */
int bac_flac_decoder_seek_reset(BacFlacDecoder* dec);

/* Tells the decoder that no more bytes follow.  A frame the input ends
 * in is then damage and not a wait for more:
 * bac_flac_decoder_decode_next reports it, goes on to any valid frame
 * behind it, and returns 0 once the FIFO is spent.  Without this call a
 * damaged frame near the end of a stream can hold back the valid frames
 * after it, because its garbled length reaches past the last byte.
 * bac_flac_decoder_feed returns AAC_ERR_STATE afterwards, until a reset
 * or a seek.  AAC_OK, or AAC_ERR_STATE after bac_flac_decoder_finish. */
int bac_flac_decoder_end_input(BacFlacDecoder* dec);

/* Ends the stream.  Bytes still in the FIFO where a frame was expected
 * are a frame cut short, and a metadata block the input ended in is no
 * better: either counts as one more damaged stretch and the call
 * returns AAC_ERR_DECODE, otherwise AAC_OK.  `*verdict` (may be NULL)
 * receives the result of the MD5 comparison, with either of those two
 * returns.  Afterwards the decoder takes no input until
 * bac_flac_decoder_reset, and a second call returns AAC_ERR_STATE and
 * leaves `*verdict` as it is. */
int bac_flac_decoder_finish(BacFlacDecoder* dec, BacFlacMd5Verdict* verdict);

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
 * A stream is written in this order: bac_flac_encoder_write_header,
 * bac_flac_encoder_encode any number of times, bac_flac_encoder_finish.
 * A caller whose output can seek then writes the bytes of
 * bac_flac_encoder_final_header over the first header, which puts the
 * sample total, the MD5 and the seek points into the stream; without
 * that step the stream is complete and decodable, with a total and an
 * MD5 of zero. */

typedef struct BacFlacEncoderOptions_ {
  /* Bytes of a PADDING block that closes the metadata, as room for tags
   * added later without rewriting the file.  0 (default) writes none. */
  int padding_bytes;
  /* Samples per channel the stream will hold, when the caller knows it
   * up front; 0 (default) for unknown.  It sizes the seek table. */
  uint64_t total_samples_hint;
  /* 1 reserves a seek table in the header, with a point for every ten
   * seconds of `total_samples_hint` (spaced wider when that would take
   * more than BAC_FLAC_MAX_SEEK_POINTS), to be filled by the final
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
  const BacFlacComment* comments;
  int comment_count;
  const char* vendor;
  /* Pictures: `picture_count` PICTURE blocks behind the tags, in this
   * order.  A type up to BAC_FLAC_MAX_PICTURE_TYPE, of the types 1 and
   * 2 (the file icons) one each at most, a media type of printable
   * ASCII, a description in UTF-8, and a block within
   * BAC_FLAC_MAX_METADATA_BLOCK_BYTES.  The library stores the bytes
   * and the figures as given; it does not look into the picture. */
  const BacFlacPicture* pictures;
  int picture_count;
} BacFlacEncoderOptions;

/* Fills every field with its default, so that a caller sets only what it
 * changes and stays correct when the structure grows. */
void bac_flac_encoder_options_init(BacFlacEncoderOptions* options);

/* `level` is BAC_FLAC_LEVEL_MIN..BAC_FLAC_LEVEL_MAX.  Returns NULL on
 * failure with the reason in `*error` (may be NULL): AAC_ERR_INVALID_ARG
 * for a channel count, bit depth, sample rate or level outside the
 * ranges above, AAC_ERR_UNSUPPORTED for a sample rate within
 * BAC_FLAC_MAX_SAMPLE_RATE that no frame header can express,
 * AAC_ERR_MEMORY. */
BacFlacEncoder* bac_flac_encoder_create(int sample_rate, int channels, int bits_per_sample,
                                          int level, int* error);
void bac_flac_encoder_destroy(BacFlacEncoder* enc);

/* Tags and pictures are checked here, before a byte of the stream is
 * written, and copied: the caller's memory is not used after the call.
 * AAC_ERR_INVALID_ARG names a comment without '=' or with a character a
 * name may not have, text that is not UTF-8, a picture type or media
 * type the format does not allow, a second picture of type 1 or 2, and
 * a block longer than the format's limit.  The header then grows by the
 * blocks, which bac_flac_encoder_header_bytes reports.
 *
 * Before the header of a stream is written, or after its finish for the
 * next stream; AAC_ERR_STATE while a stream runs.  AAC_ERR_INVALID_ARG
 * for padding outside 0 to 16777215 bytes and for a total beyond 36
 * bits. */
int bac_flac_encoder_set_options(BacFlacEncoder* enc, const BacFlacEncoderOptions* options);

/* Samples per channel in every frame but the last of a stream; never an
 * error for a valid handle. */
int bac_flac_encoder_block_size(BacFlacEncoder* enc);

/* Starts a stream: writes the fLaC marker and the metadata blocks and
 * returns their length, which bac_flac_encoder_header_bytes reports
 * beforehand; AAC_ERR_OVERFLOW when `out_size` is smaller, AAC_ERR_STATE
 * while a stream runs, AAC_ERR_MEMORY when the points of a seek table
 * could not be reserved.  A caller that stores frames without the header,
 * in a container, writes it to a buffer of its own. */
int bac_flac_encoder_header_bytes(BacFlacEncoder* enc);
int bac_flac_encoder_write_header(BacFlacEncoder* enc, uint8_t* out, int out_size);

/* Takes `n_samples` samples per channel from `pcm`, interleaved, and
 * writes every whole frame they complete; returns the bytes written,
 * which is 0 while a block is still filling.  A sample outside the bit
 * depth is refused with AAC_ERR_INVALID_ARG, a call before the header
 * or after the finish with AAC_ERR_STATE, an output buffer smaller than
 * bac_flac_encoder_max_output_bytes(enc, n_samples) with
 * AAC_ERR_OVERFLOW, and samples that would take the stream past the
 * last frame number the format has (2^31 - 1) with AAC_ERR_ENCODE; a
 * refused call consumes no input.
 *
 * bac_flac_encoder_max_output_bytes is the most a call with that many
 * samples can write, counting what is buffered already: every channel
 * of every frame unencoded, which no frame exceeds.  With 0 samples it
 * is the bound of bac_flac_encoder_finish.  AAC_ERR_INVALID_ARG when
 * the bound does not fit an int; feed fewer samples per call then. */
int bac_flac_encoder_max_output_bytes(BacFlacEncoder* enc, int n_samples);
int bac_flac_encoder_encode(BacFlacEncoder* enc, const int32_t* pcm, int n_samples, uint8_t* out,
                             int out_size);

/* Writes the samples still buffered as the stream's last, shorter
 * frame and returns its bytes (0 when none were buffered); the output
 * buffer follows bac_flac_encoder_max_output_bytes(enc, 0) and a
 * smaller one is refused with AAC_ERR_OVERFLOW.  AAC_ERR_STATE when no
 * stream runs: before the header, and for a second finish.  The next
 * bac_flac_encoder_write_header starts a new stream, identical to a
 * fresh encoder's. */
int bac_flac_encoder_finish(BacFlacEncoder* enc, uint8_t* out, int out_size);

/* The last frame written: its bytes, its samples and its position, as a
 * container's muxer needs them (a call that completes several frames
 * leaves the last of them here; feed a block per call to see each).
 * AAC_ERR_STATE before the first frame of a stream. */
int bac_flac_encoder_last_frame_info(BacFlacEncoder* enc, BacFlacFrameInfo* info);

/* After bac_flac_encoder_finish: the header again, of the same length,
 * with the sample total, the MD5, the frame size range and the seek
 * points of the finished stream, for the caller to write at offset 0.
 * Returns its length.  AAC_ERR_STATE before the finish and once the
 * next stream has begun; AAC_ERR_OVERFLOW when `out_size` is smaller
 * than bac_flac_encoder_header_bytes. */
int bac_flac_encoder_final_header(BacFlacEncoder* enc, uint8_t* out, int out_size);

/* The STREAMINFO of the stream as far as it is known: the totals and the
 * MD5 are zero until bac_flac_encoder_finish. */
int bac_flac_encoder_stream_info(BacFlacEncoder* enc, BacFlacStreamInfo* info);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AUDIO_CORE_FLAC_H */
