#include "er.h"

/* Virtual codebook 11 (ISO/IEC 14496-3 subpart 4, the section data
 * resilience tool).  The escape codebook can carry any magnitude up to
 * 8191, so a transmission error that lands in an escape sequence is
 * invisible to a plain decoder.  With aacSectionDataResilienceFlag the
 * encoder names, instead of codebook 11, the virtual codebook 16..31
 * whose limit is the smallest one at or above the largest magnitude of
 * the section; the decoder checks every value against that limit and
 * knows the section is damaged when one exceeds it.  Section data with
 * the flag also gives each escape or virtual codebook section exactly
 * one scalefactor band, so that a damaged section length cannot spread a
 * wrong codebook over its neighbours. */

namespace {

/* Magnitude limits of the virtual codebooks 16..31. */
constexpr int kVcb11Limit[16] = {15,  31,  47,  63,  95,  127, 159,  191,
                                 223, 255, 319, 383, 511, 767, 1023, 2047};

}  // namespace

int aac_vcb11_limit(int cb) {
  if (cb < AAC_CB_VCB11_FIRST || cb > AAC_CB_VCB11_LAST) {
    return -1;
  }
  return kVcb11Limit[cb - AAC_CB_VCB11_FIRST];
}

int aac_er_read_section_data(AacBitReader* r, const AacIcsInfo* ics, int* band_type) {
  const int bits = ics->num_windows == 8 ? 3 : 5;
  const int esc = (1 << bits) - 1;
  for (int g = 0; g < ics->num_window_groups; g++) {
    int k = 0;
    while (k < ics->max_sfb) {
      const int cb = (int)aac_bitreader_read(r, 5);
      if (cb == 12) {
        return AAC_ERR_DECODE; /* reserved */
      }
      int end = k;
      if (cb == AAC_CB_ESCAPE || cb >= AAC_CB_VCB11_FIRST) {
        /* One band per section, no length field. */
        end = k + 1;
      } else {
        int incr = 0;
        do {
          incr = (int)aac_bitreader_read(r, bits);
          end += incr;
          if (r->overread || end > ics->max_sfb) {
            return AAC_ERR_DECODE;
          }
        } while (incr == esc);
      }
      if (end > ics->max_sfb || r->overread) {
        return AAC_ERR_DECODE;
      }
      for (; k < end; k++) {
        band_type[g * ics->max_sfb + k] = cb;
      }
    }
  }
  return 0;
}

int aac_er_read_vcb11(AacBitReader* r, int cb, int* out) {
  const int limit = aac_vcb11_limit(cb);
  if (limit < 0) {
    return AAC_ERR_INVALID_ARG;
  }
  const int ret = aac_bitreader_read_huffman(r, AAC_CB_ESCAPE, out);
  if (ret) {
    return ret;
  }
  for (int k = 0; k < 2; k++) {
    const int mag = out[k] < 0 ? -out[k] : out[k];
    if (mag > limit) {
      return AAC_ERR_DECODE;
    }
  }
  return 0;
}
