#ifndef BAANDER_AAC_CPU_H
#define BAANDER_AAC_CPU_H

#include <cstdint>

#ifdef __cplusplus
extern "C" {
#endif

/* CPU Feature Flags — bitmask returned by aac_get_cpu_flags() */
using AacCpuFlags = enum AacCpuFlags_ {
  AAC_CPU_FLAG_NONE = 0,
  AAC_CPU_FLAG_SSE2 = (1 << 0),
  AAC_CPU_FLAG_AVX = (1 << 1),
  AAC_CPU_FLAG_AVX2 = (1 << 2),
  AAC_CPU_FLAG_FMA3 = (1 << 3),
  AAC_CPU_FLAG_BMI2 = (1 << 4),
  AAC_CPU_FLAG_NEON = (1 << 5),
  AAC_CPU_FLAG_WASM_SIMD128 = (1 << 6),
  AAC_CPU_FLAG_SSE41 = (1 << 7),
};

/* Returns CPU feature flags — lazily initialized, thread-safe */
int aac_get_cpu_flags(void);

/* Returns human-readable flag string (for debug logging) */
const char* aac_cpu_flags_string(int flags);

/* Force-set flags (for testing SIMD paths on non-matching hardware) */
void aac_set_cpu_flags_override(int flags);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_CPU_H */
