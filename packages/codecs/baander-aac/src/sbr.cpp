#include "sbr.h"

#include <cmath>
#include <cstring>

#include "aac_tables.h"

#if defined(BAAC_AAC_AVX2) || defined(__AVX2__)
#include <immintrin.h>
#elif defined(BAAC_AAC_SSE2) || defined(__SSE2__)
#include <emmintrin.h>
#elif defined(BAAC_AAC_NEON) || defined(__ARM_NEON) || defined(__aarch64__)
#include <arm_neon.h>
#endif

void aac_sbr_qmf_analysis(AacSbrQmf*  /*qmf*/, const float* time_in, float qmf_out[64][32],
                          const AacDSP* dsp) {
  (void)dsp; /* for future use with vector ops */

  for (int ts = 0; ts < 32; ts++) {
#if defined(BAAC_AAC_AVX2) || defined(__AVX2__)
    for (int k = 0; k < 64; k += 8) {
      __m256 vacc = _mm256_setzero_ps();
      for (int n = 0; n < 10; n++) {
        float w[8], t[8];
        for (int j = 0; j < 8; j++) {
          int kk = k + j;
          int idx = (ts * 64 + n * 64 + kk) % 1024;
          w[j] = aac_sbr_qmf_window[n * 64 + kk];
          t[j] = (idx < AAC_SBR_QMF_FILTER_LENGTH) ? time_in[idx] : 0.0f;
        }
        __m256 vw = _mm256_loadu_ps(w);
        __m256 vt = _mm256_loadu_ps(t);
        vacc = _mm256_add_ps(vacc, _mm256_mul_ps(vw, vt));
      }
      _mm256_storeu_ps(&qmf_out[k][ts], vacc);
    }
#elif defined(BAAC_AAC_SSE2) || defined(__SSE2__)
    for (int k = 0; k < 64; k += 4) {
      __m128 vacc = _mm_setzero_ps();
      for (int n = 0; n < 10; n++) {
        float w[4];
        float t[4];
        for (int j = 0; j < 4; j++) {
          int kk = k + j;
          int idx = (ts * 64 + n * 64 + kk) % 1024;
          w[j] = aac_sbr_qmf_window[n * 64 + kk];
          t[j] = (idx < AAC_SBR_QMF_FILTER_LENGTH) ? time_in[idx] : 0.0f;
        }
        __m128 vw = _mm_loadu_ps(w);
        __m128 vt = _mm_loadu_ps(t);
        vacc = _mm_add_ps(vacc, _mm_mul_ps(vw, vt));
      }
      _mm_storeu_ps(&qmf_out[k][ts], vacc);
    }
#elif defined(BAAC_AAC_NEON) || defined(__ARM_NEON) || defined(__aarch64__)
    for (int k = 0; k < 64; k += 4) {
      float32x4_t vacc = vdupq_n_f32(0.0f);
      for (int n = 0; n < 10; n++) {
        float w[4], t[4];
        for (int j = 0; j < 4; j++) {
          int kk = k + j;
          int idx = (ts * 64 + n * 64 + kk) % 1024;
          w[j] = aac_sbr_qmf_window[n * 64 + kk];
          t[j] = (idx < AAC_SBR_QMF_FILTER_LENGTH) ? time_in[idx] : 0.0f;
        }
        float32x4_t vw = vld1q_f32(w);
        float32x4_t vt = vld1q_f32(t);
        vacc = vmlaq_f32(vacc, vw, vt);
      }
      vst1q_f32(&qmf_out[k][ts], vacc);
    }
#else
    for (int k = 0; k < 64; k += 4) {
      for (int j = 0; j < 4; j++) {
        int kk = k + j;
        float acc = 0;
        for (int n = 0; n < 10; n++) {
          int idx = (ts * 64 + n * 64 + kk) % 1024;
          float w = aac_sbr_qmf_window[n * 64 + kk];
          float t = (idx < AAC_SBR_QMF_FILTER_LENGTH) ? time_in[idx] : 0.0f;
          acc += t * w;
        }
        qmf_out[kk][ts] = acc;
      }
    }
#endif
  }
}

void aac_sbr_qmf_synthesis(AacSbrQmf* qmf, const float qmf_in[64][32], float* time_out,
                           const AacDSP* dsp) {
  (void)dsp;

  for (int ts = 0; ts < 32; ts++) {
#if defined(BAAC_AAC_AVX2) || defined(__AVX2__)
    for (int k = 0; k < 64; k += 8) {
      __m256 vacc = _mm256_setzero_ps();
      for (int n = 0; n < 10; n++) {
        float w[8], q[8];
        for (int j = 0; j < 8; j++) {
          int kk = k + j;
          int widx = n * 64 + kk;
          w[j] = (widx < AAC_SBR_QMF_FILTER_LENGTH) ? aac_sbr_qmf_window[widx] : 0.0f;
          q[j] = qmf_in[kk][ts];
        }
        __m256 vw = _mm256_loadu_ps(w);
        __m256 vq = _mm256_loadu_ps(q);
        vacc = _mm256_add_ps(vacc, _mm256_mul_ps(vw, vq));
      }
      __m256 voverlap = _mm256_loadu_ps(&qmf->overlap[k][ts]);
      __m256 vresult = _mm256_add_ps(vacc, voverlap);
      _mm256_storeu_ps(&time_out[ts * 64 + k], vresult);
      _mm256_storeu_ps(&qmf->overlap[k][ts], vacc);
    }
#elif defined(BAAC_AAC_SSE2) || defined(__SSE2__)
    for (int k = 0; k < 64; k += 4) {
      __m128 vacc = _mm_setzero_ps();
      for (int n = 0; n < 10; n++) {
        float w[4];
        float q[4];
        for (int j = 0; j < 4; j++) {
          int kk = k + j;
          int widx = n * 64 + kk;
          w[j] = (widx < AAC_SBR_QMF_FILTER_LENGTH) ? aac_sbr_qmf_window[widx] : 0.0f;
          q[j] = qmf_in[kk][ts];
        }
        __m128 vw = _mm_loadu_ps(w);
        __m128 vq = _mm_loadu_ps(q);
        vacc = _mm_add_ps(vacc, _mm_mul_ps(vw, vq));
      }
      __m128 voverlap = _mm_loadu_ps(&qmf->overlap[k][ts]);
      __m128 vresult = _mm_add_ps(vacc, voverlap);
      _mm_storeu_ps(&time_out[ts * 64 + k], vresult);
      _mm_storeu_ps(&qmf->overlap[k][ts], vacc);
    }
#elif defined(BAAC_AAC_NEON) || defined(__ARM_NEON) || defined(__aarch64__)
    for (int k = 0; k < 64; k += 4) {
      float32x4_t vacc = vdupq_n_f32(0.0f);
      for (int n = 0; n < 10; n++) {
        float w[4], q[4];
        for (int j = 0; j < 4; j++) {
          int kk = k + j;
          int widx = n * 64 + kk;
          w[j] = (widx < AAC_SBR_QMF_FILTER_LENGTH) ? aac_sbr_qmf_window[widx] : 0.0f;
          q[j] = qmf_in[kk][ts];
        }
        float32x4_t vw = vld1q_f32(w);
        float32x4_t vq = vld1q_f32(q);
        vacc = vmlaq_f32(vacc, vw, vq);
      }
      float32x4_t voverlap = vld1q_f32(&qmf->overlap[k][ts]);
      float32x4_t vresult = vaddq_f32(vacc, voverlap);
      vst1q_f32(&time_out[ts * 64 + k], vresult);
      vst1q_f32(&qmf->overlap[k][ts], vacc);
    }
#else
    for (int k = 0; k < 64; k += 4) {
      for (int j = 0; j < 4; j++) {
        int kk = k + j;
        float acc = 0;
        for (int n = 0; n < 10; n++) {
          int widx = n * 64 + kk;
          float w = (widx < AAC_SBR_QMF_FILTER_LENGTH) ? aac_sbr_qmf_window[widx] : 0.0f;
          acc += qmf_in[kk][ts] * w;
        }
        time_out[ts * 64 + kk] = acc + qmf->overlap[kk][ts];
        qmf->overlap[kk][ts] = acc;
      }
    }
#endif
  }
}
