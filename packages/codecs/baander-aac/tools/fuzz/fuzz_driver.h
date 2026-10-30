/* The contract between a fuzz harness and its driver.
 *
 * Every harness defines LLVMFuzzerTestOneInput over one input and
 * returns 0; it reports a property violation (an output that is not
 * finite, a return outside the documented codes) with fuzz_fail, which
 * prints the message and aborts so that the driver, or libFuzzer,
 * records the input as a reproducer.  The driver is libFuzzer when the
 * harness is built with BAAC_FUZZ_LIBFUZZER, else the mutation loop of
 * fuzz_driver.cpp. */
#ifndef BAANDER_AAC_FUZZ_DRIVER_H
#define BAANDER_AAC_FUZZ_DRIVER_H

#include <cmath>
#include <cstddef>
#include <cstdint>
#include <cstdio>
#include <cstdlib>

#include "aac.h"

extern "C" int LLVMFuzzerTestOneInput(const uint8_t* data, size_t size);

/* Prints the violated property and aborts. */
static inline void fuzz_fail(const char* what, long detail) {
  fprintf(stderr, "fuzz: property violated: %s (%ld)\n", what, detail);
  fflush(stderr);
  abort();
}

/* Whether a decoder or encoder return value is one the API documents:
 * a count of samples or bytes, 0, or a negative AacError. */
static inline bool fuzz_known_return(int ret) {
  return ret >= 0 || (ret >= AAC_ERR_STATE && ret <= AAC_ERR_INVALID_ARG);
}

/* Every value of `n` floats is finite. */
static inline void fuzz_expect_finite(const float* pcm, size_t n, const char* what) {
  for (size_t i = 0; i < n; i++) {
    if (!std::isfinite(pcm[i])) {
      fuzz_fail(what, (long)i);
    }
  }
}

/* A small reader over the input bytes: a harness takes its options from
 * the first bytes and the payload from the rest; reading past the end
 * yields zero. */
struct FuzzInput {
  const uint8_t* data;
  size_t size;
  size_t pos = 0;

  FuzzInput(const uint8_t* d, size_t n) : data(d), size(n) {}
  uint8_t byte() { return pos < size ? data[pos++] : 0; }
  uint32_t u16() {
    const uint32_t hi = byte();
    return hi << 8 | byte();
  }
  size_t left() const { return pos < size ? size - pos : 0; }
  const uint8_t* rest() const { return data + (pos < size ? pos : size); }
  /* Takes up to `n` bytes; returns how many were there. */
  size_t take(size_t n, const uint8_t** out) {
    *out = rest();
    const size_t got = n < left() ? n : left();
    pos += got;
    return got;
  }
};

#endif /* BAANDER_AAC_FUZZ_DRIVER_H */
