#include "aac_cpu.h"

#if defined(_WIN32)
#include <intrin.h>
#elif defined(__x86_64__) || defined(_M_X64) || defined(__i386__) || defined(_M_IX86)
#include <cpuid.h>
#endif

#include <atomic>
#include <cstdio>

static std::atomic<int> g_cpu_flags{-1};
static std::atomic<int> g_cpu_flags_override{-1};

void aac_set_cpu_flags_override(int flags) {
  g_cpu_flags_override.store(flags, std::memory_order_release);
  g_cpu_flags.store(flags, std::memory_order_release);
}

/* ── x86: CPUID detection ─────────────────────────────────────── */

#if defined(__x86_64__) || defined(_M_X64) || defined(__i386__) || defined(_M_IX86)

static void cpuid(unsigned int leaf, unsigned int subleaf, unsigned int* eax, unsigned int* ebx,
                  unsigned int* ecx, unsigned int* edx) {
#if defined(_WIN32)
  int regs[4];
  __cpuidex(regs, (int)leaf, (int)subleaf);
  *eax = (unsigned int)regs[0];
  *ebx = (unsigned int)regs[1];
  *ecx = (unsigned int)regs[2];
  *edx = (unsigned int)regs[3];
#else
  unsigned int a = 0, b = 0, c = 0, d = 0;
  __cpuid_count(leaf, subleaf, a, b, c, d);
  *eax = a;
  *ebx = b;
  *ecx = c;
  *edx = d;
#endif
}

static int detect_x86_flags() {
  int flags = 0;
  unsigned int eax = 0, ebx = 0, ecx = 0, edx = 0;

  cpuid(0, 0, &eax, &ebx, &ecx, &edx);
  unsigned int max_leaf = eax;
  if (max_leaf < 1) { return 0;
}

  /* Leaf 1: SSE2, SSE4.1, AVX, FMA3 */
  cpuid(1, 0, &eax, &ebx, &ecx, &edx);
  if (edx & (1u << 26)) { flags |= AAC_CPU_FLAG_SSE2;
}
  if (ecx & (1u << 19)) { flags |= AAC_CPU_FLAG_SSE41;
}

  /* AVX + FMA3: require OSXSAVE + XGETBV OS support */
  int has_osxsave = (ecx & (1u << 27)) != 0;
  int has_avx_cpu = (ecx & (1u << 28)) != 0;
  int has_fma3_cpu = (ecx & (1u << 12)) != 0;

  if (has_osxsave && has_avx_cpu) {
#if defined(_WIN32)
    unsigned long long xcr0 = _xgetbv(0);
#else
    unsigned int xcr0_lo = 0, xcr0_hi = 0;
    __asm__ volatile("xgetbv" : "=a"(xcr0_lo), "=d"(xcr0_hi) : "c"(0));
    unsigned long long xcr0 = ((unsigned long long)xcr0_hi << 32) | xcr0_lo;
#endif
    if ((xcr0 & 0x6) == 0x6) {
      flags |= AAC_CPU_FLAG_AVX;
      if (has_fma3_cpu) { flags |= AAC_CPU_FLAG_FMA3;
}
    }
  }

  /* Leaf 7: AVX2, BMI2 */
  if (max_leaf >= 7) {
    cpuid(7, 0, &eax, &ebx, &ecx, &edx);
    if ((flags & AAC_CPU_FLAG_AVX) && (ebx & (1u << 5))) { flags |= AAC_CPU_FLAG_AVX2;
}
    if ((ebx & (1u << 8)) && (ebx & (1u << 3))) { flags |= AAC_CPU_FLAG_BMI2;
}
  }

  return flags;
}

/* ── ARM: getauxval / sysctlbyname ─────────────────────────────── */

#elif defined(__arm__) || defined(__aarch64__) || defined(_M_ARM64)

static int detect_arm_flags(void) {
  int flags = 0;

#if defined(__linux__) || defined(__ANDROID__)
#include <sys/auxv.h>
#if defined(HWCAP_NEON)
  unsigned long hwcap = getauxval(AT_HWCAP);
  if (hwcap & HWCAP_NEON) flags |= AAC_CPU_FLAG_NEON;
#elif defined(HWCAP_ASIMD)
  unsigned long hwcap = getauxval(AT_HWCAP);
  if (hwcap & HWCAP_ASIMD) flags |= AAC_CPU_FLAG_NEON;
#endif

#elif defined(__APPLE__)
#include <sys/sysctl.h>
  int32_t value = 0;
  size_t size = sizeof(value);
  if (sysctlbyname("hw.optional.neon64", &value, &size, NULL, 0) == 0) {
    if (value) flags |= AAC_CPU_FLAG_NEON;
  } else {
#if defined(__aarch64__)
    flags |= AAC_CPU_FLAG_NEON;
#endif
  }

#elif defined(_WIN32)
  flags |= AAC_CPU_FLAG_NEON;
#endif

  return flags;
}

/* ── WASM: compile-time ────────────────────────────────────────── */

#elif defined(__wasm__)

static int detect_wasm_flags(void) {
  int flags = 0;
#if defined(__wasm_simd128__)
  flags |= AAC_CPU_FLAG_WASM_SIMD128;
#endif
  return flags;
}

#else
static int detect_generic_flags(void) { return 0; }
#endif

/* ── Public API ────────────────────────────────────────────────── */

int aac_get_cpu_flags(void) {
  int flags = g_cpu_flags.load(std::memory_order_acquire);
  if (flags >= 0) { return flags;
}

  int ov = g_cpu_flags_override.load(std::memory_order_acquire);
  if (ov >= 0) {
    g_cpu_flags.store(ov, std::memory_order_release);
    return ov;
  }

#if defined(__x86_64__) || defined(_M_X64) || defined(__i386__) || defined(_M_IX86)
  flags = detect_x86_flags();
#elif defined(__arm__) || defined(__aarch64__) || defined(_M_ARM64)
  flags = detect_arm_flags();
#elif defined(__wasm__)
  flags = detect_wasm_flags();
#else
  flags = 0;
#endif

  g_cpu_flags.store(flags, std::memory_order_release);
  return flags;
}

const char* aac_cpu_flags_string(int flags) {
  static thread_local char buf[256];
  int pos = 0;
  buf[0] = '\0';
#define F(f, s) \
  if (flags & (f)) pos += snprintf(buf + pos, sizeof(buf) - pos, "%s%s", pos ? ", " : "", s)
  F(AAC_CPU_FLAG_SSE2, "SSE2");
  F(AAC_CPU_FLAG_SSE41, "SSE4.1");
  F(AAC_CPU_FLAG_AVX, "AVX");
  F(AAC_CPU_FLAG_AVX2, "AVX2");
  F(AAC_CPU_FLAG_FMA3, "FMA3");
  F(AAC_CPU_FLAG_BMI2, "BMI2");
  F(AAC_CPU_FLAG_NEON, "NEON");
  F(AAC_CPU_FLAG_WASM_SIMD128, "WASM-SIMD128");
#undef F
  if (pos == 0) { snprintf(buf, sizeof(buf), "scalar-only");
}
  return buf;
}
