#ifndef BAANDER_AAC_ER_H
#define BAANDER_AAC_ER_H

#include "bitstream.h"
#include "ics.h"

#ifdef __cplusplus
extern "C" {
#endif

/* The error resilience tools of the error resilient object types
 * (ISO/IEC 14496-3 subpart 4): virtual codebook 11, reversible variable
 * length coding of the scalefactors and Huffman codeword reordering of
 * the spectral data.  Each tool is switched on by its flag in the
 * AudioSpecificConfig (aacSectionDataResilienceFlag,
 * aacScalefactorDataResilienceFlag, aacSpectralDataResilienceFlag) and
 * replaces the plain section, scalefactor or spectral syntax of the
 * individual channel stream.  Decoding only. */

/* ── virtual codebook 11 ───────────────────────────────────────── */

/* Section codebooks 16..31 code their spectral values with the Huffman
 * table of the escape codebook 11 but each promise a limit on the
 * magnitude of the values: 15, 31, 47, 63, 95, 127, 159, 191, 223, 255,
 * 319, 383, 511, 767, 1023 and 2047.  A decoded value above the limit
 * reveals a transmission error. */
#define AAC_CB_VCB11_FIRST 16
#define AAC_CB_VCB11_LAST 31

/* The magnitude limit of a virtual codebook, or -1 for any other book. */
int aac_vcb11_limit(int codebook);

/* Whether a section codebook codes spectral values with a Huffman table
 * (1..11 or a virtual codebook); zero, noise and intensity do not. */
static inline int aac_cb_is_spectral(int cb) {
  return (cb >= 1 && cb <= AAC_CB_ESCAPE) || (cb >= AAC_CB_VCB11_FIRST && cb <= AAC_CB_VCB11_LAST);
}

/* The Huffman table a spectral section codebook decodes with: itself for
 * 1..11, the escape codebook for a virtual codebook. */
static inline int aac_cb_huffman_book(int cb) {
  return cb >= AAC_CB_VCB11_FIRST ? AAC_CB_ESCAPE : cb;
}

/* section_data() with aacSectionDataResilienceFlag set: the codebook of a
 * section takes five bits, and a section of the escape codebook or of a
 * virtual codebook covers one scalefactor band without a length field.
 * Fills band_type[group * max_sfb + sfb].  Returns 0 or AAC_ERR_DECODE. */
int aac_er_read_section_data(AacBitReader* r, const AacIcsInfo* ics, int* band_type);

/* Decodes one pair of a virtual codebook (16..31) with the escape
 * codebook, including sign bits and escape sequences, and checks the
 * magnitudes against the book's limit.  Returns 0, or AAC_ERR_DECODE for
 * an invalid codeword or a value above the limit. */
int aac_er_read_vcb11(AacBitReader* r, int codebook, int* out);

/* ── reversible variable length coding of the scalefactors ─────── */

/* The RVLC codebook: the DPCM differences -7..+7 of the scalefactors,
 * noise energies and intensity positions, in symmetric codewords that
 * decode in both directions.  The codewords for +7 and -7 are escape
 * markers: a difference of 7 or more in magnitude is sent as the marker
 * plus, in the escape part, an escape value e of the escape codebook,
 * and stands for +(7 + e) or -(7 + e).  Both tables are exposed for the
 * tests' stream writer. */
#define AAC_RVLC_ESCAPE 7
#define AAC_RVLC_NUM_CODES 15 /* differences -7..+7, index = difference + 7 */
#define AAC_RVLC_NUM_ESCAPES 54
extern const uint16_t aac_rvlc_code[AAC_RVLC_NUM_CODES];
extern const uint8_t aac_rvlc_len[AAC_RVLC_NUM_CODES];
extern const uint32_t aac_rvlc_escape_code[AAC_RVLC_NUM_ESCAPES];
extern const uint8_t aac_rvlc_escape_len[AAC_RVLC_NUM_ESCAPES];

/* Outcome of rvlc_scale_factor_data(): how the scalefactors were
 * recovered. */
#define AAC_RVLC_OK 0        /* the forward decoding was consistent */
#define AAC_RVLC_RECOVERED 1 /* the forward pass failed; the backward pass and interpolation filled the gaps */

/* rvlc_scale_factor_data() with aacScalefactorDataResilienceFlag set.
 * Decodes the scalefactors of the non-zero bands of band_type[] into
 * sf[group * max_sfb + sfb]: scalefactors as such, noise energies for
 * noise bands and intensity positions for intensity bands, as the plain
 * scale_factor_data() leaves them.  The forward pass is checked against
 * the lengths of the two coded parts, the reverse global gain and the
 * last noise energy; when it fails, the data is decoded backward from
 * its end as well, the longest consistent prefix and suffix are kept and
 * the bands between them are interpolated.  Leaves the reader behind the
 * scalefactor data whatever happened.  `outcome` (may be NULL) receives
 * AAC_RVLC_OK or AAC_RVLC_RECOVERED.  Returns 0, or AAC_ERR_DECODE when
 * the data is too damaged to place the reader behind it. */
int aac_er_read_rvlc_scalefactors(AacBitReader* r, const AacIcsInfo* ics, const int* band_type,
                                  int global_gain, int* sf, int* outcome);

/* ── Huffman codeword reordering of the spectral data ──────────── */

/* reordered_spectral_data() with aacSpectralDataResilienceFlag set, after
 * length_of_reordered_spectral_data (14 bits) and
 * length_of_longest_codeword (6 bits) have been read.  The codewords of
 * the spectrum (each Huffman codeword with its sign bits and escape
 * sequences) are sorted by codebook priority, the first one per segment
 * is written at the start of a segment of length_of_longest_codeword
 * bits and the rest fill the remaining space of the segments in sets,
 * forward and backward in turn, hopping to the next segment when one is
 * full.  Decodes them back into quant[window * 128 + line] (zero
 * elsewhere), checks virtual codebook limits, and leaves the reader
 * behind the reordered data.  Returns 0 or AAC_ERR_DECODE. */
int aac_er_read_hcr(AacBitReader* r, const AacIcsInfo* ics, const int* band_type,
                    int length_of_reordered_spectral_data, int length_of_longest_codeword,
                    int* quant);

/* Order of the codewords in the reordered spectral data, for a writer:
 * fills `dest` with the first line of each codeword in presorted order
 * (window * 128 + line) and `book` with its section codebook, returns the
 * count (at most 512).  The lines of a codeword are consecutive. */
int aac_er_hcr_codeword_order(const AacIcsInfo* ics, const int* band_type, int* dest, int* book);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_ER_H */
