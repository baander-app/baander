/* Consistency of the standard tables: Huffman codebooks are prefix-free and
 * complete, scalefactor bands tile the spectrum, and the windows satisfy
 * the Princen-Bradley condition. */
#include "aac_tables.h"
#include "bitstream.h"
#include "test_util.h"

static void check_prefix_free(const char* name, const uint32_t* codes, const uint8_t* lens, int n,
                              bool left_aligned) {
  double kraft = 0.0;
  for (int i = 0; i < n; i++) {
    CHECK(lens[i] >= 1 && lens[i] <= 19, "%s[%d]: length %d", name, i, lens[i]);
    kraft += pow(2.0, -(double)lens[i]);
  }
  CHECK(kraft <= 1.0 + 1e-9, "%s: Kraft sum %.6f exceeds 1", name, kraft);
  CHECK(kraft > 0.99, "%s: Kraft sum %.6f, table incomplete", name, kraft);
  for (int i = 0; i < n; i++) {
    const uint32_t ci = left_aligned ? codes[i] >> (32 - lens[i]) : codes[i];
    for (int j = 0; j < n; j++) {
      if (i == j || lens[j] < lens[i]) {
        continue;
      }
      const uint32_t cj = left_aligned ? codes[j] >> (32 - lens[j]) : codes[j];
      CHECK((cj >> (lens[j] - lens[i])) != ci || (lens[i] == lens[j] && i == j),
            "%s: codeword %d is a prefix of %d", name, i, j);
    }
  }
}

static void test_huffman_tables() {
  aac_tables_init();
  for (int cb = 1; cb <= AAC_NUM_CODEBOOKS; cb++) {
    char name[16];
    snprintf(name, sizeof(name), "codebook %d", cb);
    const AacCodebookInfo* info = &aac_codebook_info[cb];
    const int expect = info->is_unsigned ? (int)pow(info->max_val + 1, info->dim)
                                         : (int)pow(2 * info->max_val + 1, info->dim);
    CHECK(aac_huff_count[cb] == expect, "%s: %d entries, expected %d", name, aac_huff_count[cb],
          expect);
    check_prefix_free(name, aac_huff_code[cb], aac_huff_len[cb], aac_huff_count[cb], true);
    int longest = 0;
    for (int i = 0; i < aac_huff_count[cb]; i++) {
      longest = std::max(longest, (int)aac_huff_len[cb][i]);
    }
    CHECK(longest == info->max_bits, "%s: longest code %d, info says %d", name, longest,
          info->max_bits);
  }
  check_prefix_free("scalefactors", aac_scalefactor_code, aac_scalefactor_bits, 121, false);
  CHECK(aac_scalefactor_bits[AAC_SCALE_DIFF_ZERO] == 1, "zero scalefactor difference is 1 bit");
}

static void test_band_tables() {
  for (int ri = 0; ri < AAC_NUM_SAMPLE_RATES; ri++) {
    const int nl = aac_num_sfb_long[ri];
    const int ns = aac_num_sfb_short[ri];
    CHECK(nl > 0 && nl <= AAC_MAX_SFB_LONG, "rate %d: %d long bands", ri, nl);
    CHECK(ns > 0 && ns <= AAC_MAX_SFB_SHORT, "rate %d: %d short bands", ri, ns);
    CHECK(aac_sfb_offset_long[ri][0] == 0 && aac_sfb_offset_long[ri][nl] == 1024,
          "rate %d: long bands do not span 0..1024", ri);
    CHECK(aac_sfb_offset_short[ri][0] == 0 && aac_sfb_offset_short[ri][ns] == 128,
          "rate %d: short bands do not span 0..128", ri);
    for (int b = 0; b < nl; b++) {
      const int w = aac_sfb_offset_long[ri][b + 1] - aac_sfb_offset_long[ri][b];
      CHECK(w > 0 && w % 4 == 0, "rate %d long band %d width %d", ri, b, w);
    }
    for (int b = 0; b < ns; b++) {
      const int w = aac_sfb_offset_short[ri][b + 1] - aac_sfb_offset_short[ri][b];
      CHECK(w > 0 && w % 4 == 0, "rate %d short band %d width %d", ri, b, w);
    }
    CHECK(aac_tns_max_bands_long[ri] <= nl && aac_tns_max_bands_short[ri] <= ns,
          "rate %d: TNS band limits exceed the band count", ri);
  }
}

static void test_windows_and_pow43() {
  aac_tables_init();
  for (int shape = 0; shape < 2; shape++) {
    for (int i = 0; i < AAC_FRAME_SIZE_LONG; i++) {
      const double a = aac_window_long[shape][i];
      const double b = aac_window_long[shape][AAC_FRAME_SIZE_LONG - 1 - i];
      CHECK(fabs(a * a + b * b - 1.0) < 1e-5, "long window shape %d sample %d", shape, i);
    }
    for (int i = 0; i < AAC_FRAME_SIZE_SHORT; i++) {
      const double a = aac_window_short[shape][i];
      const double b = aac_window_short[shape][AAC_FRAME_SIZE_SHORT - 1 - i];
      CHECK(fabs(a * a + b * b - 1.0) < 1e-5, "short window shape %d sample %d", shape, i);
    }
    CHECK(aac_window_long[shape][0] < aac_window_long[shape][AAC_FRAME_SIZE_LONG - 1],
          "long window shape %d is not rising", shape);
  }
  CHECK(aac_pow43[0] == 0.0f && aac_pow43[1] == 1.0f, "pow43 origin");
  CHECK(fabs(aac_pow43[8] - 16.0f) < 1e-4, "8^(4/3) = 16, got %f", aac_pow43[8]);
  CHECK(fabs(aac_pow43[27] - 81.0f) < 1e-3, "27^(4/3) = 81, got %f", aac_pow43[27]);
}

int main() {
  test_huffman_tables();
  test_band_tables();
  test_windows_and_pow43();
  return test_summary("test_tables");
}
