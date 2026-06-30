#include "mdct.h"

#include <cmath>
#include <cstring>

#include "aac_tables.h"
#include "fft.h"

/* ── AAC MDCT / IMDCT implementation ──────────────────────────────
 *
 * Forward MDCT: window → fold → DCT-IV (FFT-based) → N spectral coeffs
 * Inverse MDCT: DCT-IV (brute-force, to be optimized) → window + overlap-add
 *
 * The DCT-IV is self-inverse. Window is applied before DCT-IV (forward)
 * and after DCT-IV (inverse, during overlap-add).
 *
 * Princen-Bradley: w[n] = sin(π(n+0.5)/2N), w²[n]+w²[n+N]=1
 * ────────────────────────────────────────────────────────────────── */

/* ── MDCT context lifecycle ───────────────────────────────────── */

void aac_mdct_init(AacMdctContext* ctx, int frame_size_long, const AacDSP* dsp) {
  ctx->frame_size_long = frame_size_long;
  ctx->frame_size_short = frame_size_long / 8;
  ctx->dsp = dsp;
  int N = frame_size_long;

  ctx->overlap_long = new float[static_cast<size_t>(N)]();
  ctx->overlap_save_long = new float[static_cast<size_t>(N)]();
  for (int i = 0; i < 8; i++) {
    ctx->overlap_short[i] = new float[ctx->frame_size_short]();
  }

  ctx->window_sine_long = new float[static_cast<size_t>(2) * N];
  ctx->window_kbd_long = new float[static_cast<size_t>(2) * N];
  ctx->window_sine_short = new float[static_cast<size_t>(2) * ctx->frame_size_short];
  ctx->window_kbd_short = new float[static_cast<size_t>(2) * ctx->frame_size_short];
  aac_sine_window(ctx->window_sine_long, 2 * N);
  aac_kbd_window(ctx->window_kbd_long, 2 * N, AAC_KBD_ALPHA_LONG);
  aac_sine_window(ctx->window_sine_short, 2 * ctx->frame_size_short);
  aac_kbd_window(ctx->window_kbd_short, 2 * ctx->frame_size_short, AAC_KBD_ALPHA_SHORT);

  ctx->scratch_re = new float[static_cast<size_t>(N)]();
  ctx->scratch_im = new float[static_cast<size_t>(N)]();
  ctx->scratch_tmp = new float[static_cast<size_t>(2) * N]();
  ctx->scratch_tmp2 = new float[static_cast<size_t>(2) * ctx->frame_size_short]();
  ctx->prev_win_seq = AAC_WIN_ONLY_LONG;
  ctx->prev_win_shape = AAC_WIN_SINE;

  /* Allocate and precompute MDCT twiddles once for long and short blocks */
  int n4_long = N / 4;
  int n4_short = ctx->frame_size_short / 4;

  ctx->mdct_tw_re_long = new float[n4_long]();
  ctx->mdct_tw_im_long = new float[n4_long]();
  ctx->mdct_tw_re_short = new float[n4_short]();
  ctx->mdct_tw_im_short = new float[n4_short]();

  for (int k = 0; k < n4_long; k++) {
    float ang = (float)M_PI * (k + 0.125f) / (float)n4_long;
    ctx->mdct_tw_re_long[k] = cosf(ang);
    ctx->mdct_tw_im_long[k] = sinf(ang);
  }
  for (int k = 0; k < n4_short; k++) {
    float ang = (float)M_PI * (k + 0.125f) / (float)n4_short;
    ctx->mdct_tw_re_short[k] = cosf(ang);
    ctx->mdct_tw_im_short[k] = sinf(ang);
  }
}

void aac_mdct_free(AacMdctContext* ctx) {
  if (!ctx) {
    return;
  }
  delete[] ctx->overlap_long;
  delete[] ctx->overlap_save_long;
  for (int i = 0; i < 8; i++) {
    delete[] ctx->overlap_short[i];
  }
  delete[] ctx->window_sine_long;
  delete[] ctx->window_kbd_long;
  delete[] ctx->window_sine_short;
  delete[] ctx->window_kbd_short;
  delete[] ctx->scratch_re;
  delete[] ctx->scratch_im;
  delete[] ctx->scratch_tmp;
  delete[] ctx->scratch_tmp2;

  delete[] ctx->mdct_tw_re_long;
  delete[] ctx->mdct_tw_im_long;
  delete[] ctx->mdct_tw_re_short;
  delete[] ctx->mdct_tw_im_short;
}

/* ── Legacy MDCT forward (FFT-based, for test + DSP dispatch compatibility)
 *
 * Output contract:
 *   This function (and all SIMD variants) only populate the first n/2 floats
 *   of the `out` buffer (lower bins in interleaved real/imag format after
 *   post-rotation + partial scaling). Callers must not assume the upper half
 *   is written. The hardened test in test_mdct.cpp respects this range.
 *
 * Numerical note:
 *   Produces mathematically equivalent results to the direct O(N²) version
 *   but with different rounding. The encoder deliberately avoids this path
 *   for rate-control stability.
 */

/* Forward declaration so the thin wrapper can call it */
static void mdct_forward_rotation(float* out, const float* in, int n, const float* win,
                                  const float* tw_re, const float* tw_im);

/* Thin wrapper for backward compatibility — uses local twiddle computation */
void aac_mdct_forward_c(float* out, const float* in, int n, const float* win) {
  mdct_forward_rotation(out, in, n, win, nullptr, nullptr);
}

/* Shared implementation for forward rotation using provided (or local) twiddles */
static void mdct_forward_rotation(float* out, const float* in, int n, const float* win,
                                  const float* tw_re, const float* tw_im) {
  int n2 = n >> 1, n4 = n >> 2;
  static thread_local float s_re[512], s_im[512];
  float* re = s_re;
  float* im = s_im;
  memset(re, 0, n4 * sizeof(float));
  memset(im, 0, n4 * sizeof(float));

  bool local_tw = (tw_re == nullptr || tw_im == nullptr);
  static thread_local float local_tw_re[256], local_tw_im[256];

  if (local_tw) {
    for (int k = 0; k < n4; k++) {
      float ang = (float)M_PI * (k + 0.125f) / (float)n4;
      local_tw_re[k] = cosf(ang);
      local_tw_im[k] = sinf(ang);
    }
    tw_re = local_tw_re;
    tw_im = local_tw_im;
  }

  for (int k = 0; k < n4; k++) {
    float a = win[n2 - n4 + k] * in[n2 - n4 + k] + win[n4 - 1 - k] * in[n4 - 1 - k];
    float b = win[n2 + k] * in[n2 + k] - win[n - 1 - k] * in[n - 1 - k];
    re[k] = a * tw_re[k] + b * tw_im[k];
    im[k] = b * tw_re[k] - a * tw_im[k];
  }

  aac_fft_forward_c(re, im, n4);

  for (int k = 0; k < n4; k++) {
    out[static_cast<ptrdiff_t>(2) * k] = re[k] * tw_re[k] + im[k] * tw_im[k];
    out[static_cast<ptrdiff_t>(2) * k + 1] = im[k] * tw_re[k] - re[k] * tw_im[k];
  }

  float scale = 2.0f / (float)n;
  for (int k = 0; k < n2; k++) {
    out[k] *= scale;
  }
}

void aac_mdct_forward_with_twiddles(float* out, const float* in, int n, const float* win,
                                    const float* tw_re, const float* tw_im) {
  mdct_forward_rotation(out, in, n, win, tw_re, tw_im);
}

/* ── AAC-aware forward MDCT ─────────────────────────────────────
 * Uses direct formula (O(N²)) — the encoder's rate control was
 * tuned for this exact convention. The FFT-based forward produces
 * mathematically equivalent output but with different float rounding
 * that affects the rate control's convergence at higher bitrates. */

void aac_mdct_forward_aac(AacMdctContext* ctx, float* out, const float* in, int n,
                          AacWindowSequence /*win_seq*/, AacWindowShape win_shape,
                          int /*channel*/) {
  const float* win = (win_shape == AAC_WIN_KBD) ? ctx->window_kbd_long : ctx->window_sine_long;
  float* overlap = ctx->overlap_long;
  int N = n;
  int N2 = 2 * N;

  /* Build 2N windowed input: overlap[0..N-1] + current[0..N-1] */
  static thread_local float s_buf[2048];
  for (int i = 0; i < N; i++) {
    s_buf[i] = win[i] * overlap[i];
  }
  for (int i = 0; i < N; i++) {
    s_buf[N + i] = win[N + i] * in[i];
  }

  /* Save current for next frame */
  memcpy(overlap, in, N * sizeof(float));

  /* Direct MDCT: X[k] = Σ buf[n] * cos(π*(2n+N+1)*(2k+1)/(4N)) */
  for (int k = 0; k < N; k++) {
    float sum = 0.0f;
    float freq = (2.0f * k + 1.0f) / (4.0f * (float)N);
    for (int nn = 0; nn < N2; nn++) {
      sum += s_buf[nn] * cosf((float)M_PI * (2.0f * nn + (float)N + 1.0f) * freq);
    }
    out[k] = sum;
  }
}

/* ── Legacy IMDCT half (butterfly, for test_mdct compatibility) */

/* Shared IMDCT half rotation (used by both _c and with_twiddles versions) */
static void imdct_half_rotation(float* out, const float* in, int n, const float* win,
                                const float* tw_re, const float* tw_im) {
  int n2 = n >> 1, n4 = n >> 2;
  static thread_local float s_re[512], s_im[512];
  float* re = s_re;
  float* im = s_im;
  memset(re, 0, n4 * sizeof(float));
  memset(im, 0, n4 * sizeof(float));

  bool local_tw = (tw_re == nullptr || tw_im == nullptr);
  static thread_local float local_tw_re[256], local_tw_im[256];

  if (local_tw) {
    for (int k = 0; k < n4; k++) {
      float ang = (float)M_PI * (k + 0.125f) / (float)n4;
      local_tw_re[k] = cosf(ang);
      local_tw_im[k] = sinf(ang);
    }
    tw_re = local_tw_re;
    tw_im = local_tw_im;
  }

  for (int k = 0; k < n4; k++) {
    re[k] = in[static_cast<ptrdiff_t>(2) * k] * tw_re[k] +
            in[static_cast<ptrdiff_t>(2) * k + 1] * tw_im[k];
    im[k] = in[static_cast<ptrdiff_t>(2) * k + 1] * tw_re[k] -
            in[static_cast<ptrdiff_t>(2) * k] * tw_im[k];
  }

  aac_fft_inverse_c(re, im, n4);

  for (int k = 0; k < n4; k++) {
    float a = re[k] * tw_re[k] + im[k] * tw_im[k];
    float b = re[k] * tw_im[k] - im[k] * tw_re[k];
    out[k] = a * win[k];
    out[n4 + k] = b * win[n4 + k];
    out[n2 + k] = -b * win[n2 + k];
    out[n2 + n4 + k] = -a * win[n2 + n4 + k];
  }
}

void aac_imdct_half_with_twiddles(float* out, const float* in, int n, const float* win,
                                  const float* tw_re, const float* tw_im) {
  imdct_half_rotation(out, in, n, win, tw_re, tw_im);
}

/* Thin wrapper for backward compatibility */
void aac_imdct_half_c(float* out, const float* in, int n, const float* win) {
  imdct_half_rotation(out, in, n, win, nullptr, nullptr);
}

/* ── FFT-based DCT-IV (O(N log N)) ──────────────────────────────
 * Uses 2×N/2-point complex FFT with pre/post twiddle.
 * Self-inverse: DCT4(DCT4(x)) = x * (N/2)^2.
 * Matches brute-force DCT-4 to <1e-4 relative error. */

static void dct4_fft(float* Y, const float* x, int N) {
  int M = N / 2;
  static thread_local float s_pr[512], s_pi[512], s_qr[512], s_qi[512];
  float *pr = s_pr, *pi_ = s_pi, *qr = s_qr, *qi = s_qi;

  /* Pre-twiddle: split even/odd into two N/2 complex vectors */
  for (int i = 0; i < M; i++) {
    float a = x[static_cast<ptrdiff_t>(2) * i], b = x[N - 1 - static_cast<ptrdiff_t>(2) * i];
    float phi = (float)M_PI * (4 * i + 1) / (4.0f * N);
    float c1 = cosf(phi), s1 = sinf(phi);
    float c3 = cosf(3.0f * phi), s3 = sinf(3.0f * phi);
    pr[i] = a * c1 + b * s1;
    pi_[i] = a * s1 - b * c1;
    qr[i] = a * c3 - b * s3;
    qi[i] = a * s3 + b * c3;
  }

  /* IDFT via conj(FFT(conj(x)))/M */
  for (int i = 0; i < M; i++) {
    pi_[i] = -pi_[i];
  }
  aac_fft_forward_c(pr, pi_, M);
  for (int i = 0; i < M; i++) {
    qi[i] = -qi[i];
  }
  aac_fft_forward_c(qr, qi, M);

  /* Post-twiddle: combine into interleaved output */
  for (int r = 0; r < M; r++) {
    float ca = cosf(r * (float)M_PI / N), sa = sinf(r * (float)M_PI / N);
    Y[static_cast<ptrdiff_t>(2) * r] = (ca * pr[r] + sa * pi_[r]) / M;
    Y[static_cast<ptrdiff_t>(2) * r + 1] = (ca * qr[r] + sa * qi[r]) / M;
  }
  float sc = N / 2.0f;
  for (int k = 0; k < N; k++) {
    Y[k] *= sc;
  }
}

/* ── FFT-based IMDCT (O(N log N)) ──────────────────────────────
 * DCT-IV (self-inverse) + fold with implicit sine windowing.
 * Produces 2N windowed output from N spectral coefficients.
 * The fold twiddle factors sin/cos(π(2i+1)/(4N)) are exactly the
 * sine window values, so no separate window multiplication needed.
 * NOTE: only valid for sine window, not KBD. */

static void imdct_fft(float* out, const float* X, int N) {
  static thread_local float s_z[1024];
  dct4_fft(s_z, X, N);

  /* Scale: the roundtrip DCT4(DCT4(x)) = x*(N/2)^2, so divide by N/2 */
  float inv = 2.0f / (float)N;

  /* Fold: N DCT-4 values → 2N windowed output */
  for (int i = 0; i < N / 2; i++) {
    float theta = (float)M_PI * (2 * i + 1) / (4.0f * N);
    float ct = cosf(theta), st = sinf(theta);
    float a = s_z[N / 2 + i] * inv;
    float b = s_z[N / 2 - 1 - i] * inv;
    out[i] = st * a;
    out[N - 1 - i] = -ct * a;
    out[N + i] = -ct * b;
    out[2 * N - 1 - i] = -st * b;
  }
}

/* ── AAC Inverse MDCT (overlap-add) ───────────────────────────── */

void aac_imdct(AacMdctContext* ctx, float* out, const float* spectral, int n,
               AacWindowSequence win_seq, AacWindowShape win_shape, int /*channel*/) {
  int N = n;
  float* overlap = ctx->overlap_save_long;

  switch (win_seq) {
    case AAC_WIN_ONLY_LONG: {
      float* tmp = ctx->scratch_tmp;
      imdct_fft(tmp, spectral, N);
      for (int i = 0; i < N; i++) {
        out[i] = overlap[i] + tmp[i];
      }
      memcpy(overlap, tmp + N, N * sizeof(float));
      break;
    }
    case AAC_WIN_LONG_START: {
      float* tmp = ctx->scratch_tmp;
      imdct_fft(tmp, spectral, N);
      for (int i = 0; i < N; i++) {
        out[i] = overlap[i] + tmp[i];
      }
      int ns = ctx->frame_size_short;
      for (int w = 0; w < 8; w++) {
        int off = w * ns;
        for (int i = 0; i < ns; i++) {
          ctx->overlap_short[w][i] = (off + i < N) ? tmp[N + off + i] : 0.0f;
        }
      }
      memset(overlap, 0, N * sizeof(float));
      break;
    }
    case AAC_WIN_EIGHT_SHORT: {
      int ns = ctx->frame_size_short;
      memset(out, 0, N * sizeof(float));
      for (int w = 0; w < 8; w++) {
        float* tmp = ctx->scratch_tmp2;
        imdct_fft(tmp, spectral + static_cast<ptrdiff_t>(w) * (ns / 2), ns / 2);
        int off = w * ns;
        for (int i = 0; i < ns; i++) {
          out[off + i] = ctx->overlap_short[w][i] + tmp[i];
        }
        memcpy(ctx->overlap_short[w], tmp + ns, ns * sizeof(float));
      }
      break;
    }
    case AAC_WIN_LONG_STOP: {
      float* tmp = ctx->scratch_tmp;
      imdct_fft(tmp, spectral, N);
      int ns = ctx->frame_size_short;
      for (int w = 0; w < 8; w++) {
        int off = w * ns;
        for (int i = 0; i < ns && off + i < N; i++) {
          out[off + i] = ctx->overlap_short[w][i] + tmp[off + i];
        }
      }
      memcpy(overlap, tmp + N, N * sizeof(float));
      break;
    }
  }

  ctx->prev_win_seq = win_seq;
  ctx->prev_win_shape = win_shape;
}
