/* The scalar kernels of the FLAC codec and the table that dispatches to
 * them or to the SSE2, AVX2 and NEON ones (src/simd/flac_*.cpp).
 *
 * This file is compiled in exact arithmetic and without contraction,
 * like the other files of the analysis: the two floating-point kernels
 * state the order of their arithmetic, and every level has to reproduce
 * it bit for bit. */
#include "aac_cpu.h"
#include "flac_internal.h"

namespace {

/* ── analysis ───────────────────────────────────────────────────── */

void window_c(const double* x, const double* w, int n, double* y) {
  for (int i = 0; i < n; i++) {
    y[i] = x[i] * w[i];
  }
}

void autocorrelation_c(const double* y, int n, int max_lag, double* r) {
  /* Two partial sums per lag, over the even and the odd i. */
  double sums[2][FLAC_MAX_LPC_ORDER + 1];
  for (int lag = 0; lag <= max_lag; lag++) {
    sums[0][lag] = 0.0;
    sums[1][lag] = 0.0;
  }
  for (int i = 0; i < n; i++) {
    double* sum = sums[i & 1];
    const double v = y[i];
    const int top = i < max_lag ? i : max_lag;
    for (int lag = 0; lag <= top; lag++) {
      sum[lag] += v * y[i - lag];
    }
  }
  for (int lag = 0; lag <= max_lag; lag++) {
    r[lag] = sums[0][lag] + sums[1][lag];
  }
}

/* ── encoder ────────────────────────────────────────────────────── */

void fixed_sums_c(const int32_t* x, int n, int max_order, uint64_t* sums) {
  /* Each order is the difference of the one below it. */
  int32_t previous[FLAC_MAX_FIXED_ORDER] = {0, 0, 0, 0};
  for (int k = 0; k <= FLAC_MAX_FIXED_ORDER; k++) {
    sums[k] = 0;
  }
  for (int i = 0; i < n; i++) {
    int32_t e = x[i];
    for (int k = 0; k <= max_order; k++) {
      if (i >= max_order) {
        sums[k] += (uint64_t)(e < 0 ? -(int64_t)e : (int64_t)e);
      }
      if (k < max_order) {
        const int32_t next = (int32_t)((uint32_t)e - (uint32_t)previous[k]);
        previous[k] = e;
        e = next;
      }
    }
  }
}

void lpc_residual_32_c(const int32_t* x, int n, int order, const int32_t* coefs, int shift,
                       int32_t* residual) {
  for (int i = order; i < n; i++) {
    uint32_t sum = 0;
    for (int j = 0; j < order; j++) {
      sum += (uint32_t)coefs[j] * (uint32_t)x[i - 1 - j];
    }
    residual[i] = (int32_t)((uint32_t)x[i] - (uint32_t)((int32_t)sum >> shift));
  }
}

bool lpc_residual_64_c(const int32_t* x, int n, int order, const int32_t* coefs, int shift,
                       int32_t* residual) {
  bool outside = false;
  for (int i = order; i < n; i++) {
    int64_t sum = 0;
    for (int j = 0; j < order; j++) {
      sum += (int64_t)coefs[j] * (int64_t)x[i - 1 - j];
    }
    const int64_t r = (int64_t)x[i] - (sum >> shift);
    outside |= (uint64_t)(r + INT32_MAX) > 0xFFFFFFFEull;
    residual[i] = (int32_t)r;
  }
  return !outside;
}

uint64_t rice_fold_c(const int32_t* residual, int n, uint32_t* folded, uint32_t* bits) {
  uint64_t sum = 0;
  uint32_t all = 0;
  for (int i = 0; i < n; i++) {
    const uint32_t r = (uint32_t)residual[i];
    const uint32_t u = (r << 1) ^ (0u - (r >> 31));
    folded[i] = u;
    sum += u;
    all |= u;
  }
  *bits = all;
  return sum;
}

void rice_sums_c(const uint32_t* folded, int n, uint32_t, int k_low, int k_count, uint64_t* sums,
                 size_t stride) {
  for (int j = 0; j < k_count; j++) {
    const int k = k_low + j;
    uint64_t sum = 0;
    for (int i = 0; i < n; i++) {
      sum += folded[i] >> k;
    }
    sums[(size_t)j * stride] = sum;
  }
}

void rice_encode_c(FlacBitWriter* w, const int32_t* residual, int n, int k) {
  flac_rice_encode_words(w, residual, n, k);
}

/* ── decoder ────────────────────────────────────────────────────── */

int rice_decode_c(FlacBitReader* r, int32_t* out, int count, int k) {
  return flac_rice_decode_words(r, out, count, k);
}

/* The prediction for a known order: the loop over the taps has a fixed
 * length, so the compiler unrolls it, and it adds the newest sample's
 * product last, which keeps the dependency from one sample to the next
 * to a multiplication and an addition. */
template <int ORDER>
void restore_32(int32_t* s, int n, const int32_t* c, int shift) {
  for (int i = ORDER; i < n; i++) {
    uint32_t sum = 0;
    for (int j = ORDER - 1; j >= 0; j--) {
      sum += (uint32_t)c[j] * (uint32_t)s[i - 1 - j];
    }
    s[i] = (int32_t)((uint32_t)s[i] + (uint32_t)((int32_t)sum >> shift));
  }
}

template <int ORDER>
void restore_wide(int32_t* s, int n, const int32_t* c, int shift) {
  for (int i = ORDER; i < n; i++) {
    int64_t sum = 0;
    for (int j = ORDER - 1; j >= 0; j--) {
      sum += (int64_t)c[j] * (int64_t)s[i - 1 - j];
    }
    s[i] = (int32_t)((uint32_t)s[i] + (uint32_t)(uint64_t)(sum >> shift));
  }
}

typedef void (*RestoreFn)(int32_t* s, int n, const int32_t* c, int shift);

#define FLAC_ORDERS(f)                                                                            \
  f<1>, f<2>, f<3>, f<4>, f<5>, f<6>, f<7>, f<8>, f<9>, f<10>, f<11>, f<12>, f<13>, f<14>, f<15>, \
      f<16>, f<17>, f<18>, f<19>, f<20>, f<21>, f<22>, f<23>, f<24>, f<25>, f<26>, f<27>, f<28>,  \
      f<29>, f<30>, f<31>, f<32>

const RestoreFn kRestore32[FLAC_MAX_LPC_ORDER] = {FLAC_ORDERS(restore_32)};
const RestoreFn kRestoreWide[FLAC_MAX_LPC_ORDER] = {FLAC_ORDERS(restore_wide)};

void lpc_restore_32_c(int32_t* s, int n, int order, const int32_t* coefs, int shift) {
  kRestore32[order - 1](s, n, coefs, shift);
}

void lpc_restore_wide_c(int32_t* s, int n, int order, const int32_t* coefs, int shift) {
  kRestoreWide[order - 1](s, n, coefs, shift);
}

void stereo_out_independent_c(const int32_t* c0, const int32_t* c1, int n, int32_t* pcm) {
  for (int i = 0; i < n; i++) {
    pcm[2 * i] = c0[i];
    pcm[2 * i + 1] = c1[i];
  }
}

void stereo_out_left_side_c(const int32_t* c0, const int32_t* c1, int n, int32_t* pcm) {
  for (int i = 0; i < n; i++) {
    pcm[2 * i] = c0[i];
    pcm[2 * i + 1] = (int32_t)((uint32_t)c0[i] - (uint32_t)c1[i]);
  }
}

void stereo_out_side_right_c(const int32_t* c0, const int32_t* c1, int n, int32_t* pcm) {
  for (int i = 0; i < n; i++) {
    pcm[2 * i] = (int32_t)((uint32_t)c0[i] + (uint32_t)c1[i]);
    pcm[2 * i + 1] = c1[i];
  }
}

void stereo_out_mid_side_c(const int32_t* c0, const int32_t* c1, int n, int32_t* pcm) {
  for (int i = 0; i < n; i++) {
    const uint32_t side = (uint32_t)c1[i];
    const uint32_t mid = ((uint32_t)c0[i] << 1) | (side & 1);
    pcm[2 * i] = (int32_t)(mid + side) >> 1;
    pcm[2 * i + 1] = (int32_t)(mid - side) >> 1;
  }
}

/* ── the levels ─────────────────────────────────────────────────── */

void init_scalar(FlacDSP* dsp, int) {
  dsp->window = window_c;
  dsp->autocorrelation = autocorrelation_c;
  dsp->fixed_sums = fixed_sums_c;
  dsp->lpc_residual_16 = lpc_residual_32_c;
  dsp->lpc_residual_32 = lpc_residual_32_c;
  dsp->lpc_residual_64 = lpc_residual_64_c;
  dsp->rice_fold = rice_fold_c;
  dsp->rice_sums = rice_sums_c;
  dsp->rice_encode = rice_encode_c;
  dsp->rice_decode = rice_decode_c;
  dsp->lpc_restore_32 = lpc_restore_32_c;
  dsp->lpc_restore_wide = lpc_restore_wide_c;
  dsp->stereo_out[FLAC_ASSIGN_INDEPENDENT] = stereo_out_independent_c;
  dsp->stereo_out[FLAC_ASSIGN_LEFT_SIDE] = stereo_out_left_side_c;
  dsp->stereo_out[FLAC_ASSIGN_SIDE_RIGHT] = stereo_out_side_right_c;
  dsp->stereo_out[FLAC_ASSIGN_MID_SIDE] = stereo_out_mid_side_c;
}

const FlacDspLevel kLevels[] = {
    {"scalar", AAC_CPU_FLAG_NONE, init_scalar},
#if defined(BAC_AAC_SSE2)
    {"sse2", AAC_CPU_FLAG_SSE2, flac_dsp_init_sse2},
#endif
#if defined(BAC_AAC_AVX2)
    {"avx2", AAC_CPU_FLAG_SSE2 | AAC_CPU_FLAG_AVX | AAC_CPU_FLAG_AVX2, flac_dsp_init_avx2},
#endif
#if defined(BAC_AAC_NEON)
    {"neon", AAC_CPU_FLAG_NEON, flac_dsp_init_neon},
#endif
};

}  // namespace

const FlacDspLevel* flac_dsp_levels(int* count) {
  *count = (int)(sizeof(kLevels) / sizeof(kLevels[0]));
  return kLevels;
}

void flac_dsp_init_for(FlacDSP* dsp, int flags) {
  for (const FlacDspLevel& level : kLevels) {
    if ((flags & level.flags) == level.flags) {
      level.init(dsp, flags);
    }
  }
}

void flac_dsp_init(FlacDSP* dsp) { flac_dsp_init_for(dsp, aac_get_cpu_flags()); }

const FlacDSP* flac_dsp_global(void) {
  static const FlacDSP table = [] {
    FlacDSP d;
    flac_dsp_init(&d);
    return d;
  }();
  return &table;
}
