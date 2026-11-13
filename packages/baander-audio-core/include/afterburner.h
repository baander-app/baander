#ifndef BAANDER_AAC_AFTERBURNER_H
#define BAANDER_AAC_AFTERBURNER_H

#include "encoder.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Second quantization pass over the frame the rate loop has just produced
 * (afterburner.cpp).  `bits` is the frame size the rate loop counted for
 * the current scalefactors, codebooks and quantized spectra; `budget` is
 * the largest frame size the pass may end with.  The pass moves
 * scalefactors one step at a time towards a lower noise-to-mask ratio in
 * the worst bands, paying with the budget's slack or with coarser
 * quantization in bands that have margin, re-sections and re-counts after
 * every move, and leaves every channel's sf, cb, quant and global_gain and
 * every element's max_sfb ready for the bitstream writer.  A frame whose
 * size already exceeds the budget is left alone. */
void aac_afterburner_run(AacEncoderState* s, int bits, int budget);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_AFTERBURNER_H */
