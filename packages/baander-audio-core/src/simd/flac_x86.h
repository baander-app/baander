/* What the SSE2 and the AVX2 kernels of the FLAC codec have in common.
 *
 * Every function here is `static`: each of the two files gets a copy of
 * its own, compiled with that file's instruction set.  One copy shared
 * between them could be the one encoded for AVX2, and the SSE2 kernels
 * would then run it on a processor without AVX. */
#ifndef BAANDER_AUDIO_CORE_FLAC_X86_H
#define BAANDER_AUDIO_CORE_FLAC_X86_H

#include <emmintrin.h>

#include "../flac_internal.h"

/* The magnitudes of the fixed predictors' residuals at the samples
 * x[i .. n), added to `sums` sample by sample: what a vector loop leaves
 * over, and the whole of a block too short for order 4. */
static inline void flac_x86_fixed_sums_tail(const int32_t* x, int i, int n, int max_order,
                                            uint64_t* sums) {
  for (; i < n; i++) {
    int32_t e = x[i];
    for (int k = 0; k <= max_order; k++) {
      sums[k] += (uint64_t)(e < 0 ? -(int64_t)e : (int64_t)e);
      if (k < max_order) {
        /* The difference of order k at the sample before, from the
         * samples themselves. */
        static const int kBinomial[4][5] = {
            {1, 0, 0, 0, 0}, {1, -1, 0, 0, 0}, {1, -2, 1, 0, 0}, {1, -3, 3, -1, 0}};
        uint32_t before = 0;
        for (int j = 0; j <= k; j++) {
          before += (uint32_t)kBinomial[k][j] * (uint32_t)x[i - 1 - j];
        }
        e = (int32_t)((uint32_t)e - before);
      }
    }
  }
}

/* The samples x[base .. base + count) narrowed to 16 bits each, a zero
 * standing in for an index below the row. */
static inline void flac_x86_narrow_samples(const int32_t* x, int base, int count, int16_t* packed) {
  int m = 0;
  if (base < 0) {
    packed[m++] = 0;
  }
  for (; m + 8 <= count; m += 8) {
    _mm_storeu_si128((__m128i*)(packed + m),
                     _mm_packs_epi32(_mm_loadu_si128((const __m128i*)(x + base + m)),
                                     _mm_loadu_si128((const __m128i*)(x + base + m + 4))));
  }
  for (; m < count; m++) {
    packed[m] = (int16_t)x[base + m];
  }
}

#endif /* BAANDER_AUDIO_CORE_FLAC_X86_H */
