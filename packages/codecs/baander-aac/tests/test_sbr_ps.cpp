/*
 * SBR QMF and Parametric Stereo unit tests.
 * Verifies the SBR QMF window is populated and the PS decoder
 * produces non-silent, power-consistent, decorrelated output.
 */
#include <cmath>
#include <cstdio>
#include <cstring>

#include "aac.h"
#include "ps.h"
#include "sbr.h"

static float vector_rms(const float* v, int n) {
  float acc = 0.0f;
  for (int i = 0; i < n; i++) {
    acc += v[i] * v[i];
  }
  return sqrtf(acc / (float)n);
}

static float vector_energy(const float* v, int n) {
  float acc = 0.0f;
  for (int i = 0; i < n; i++) {
    acc += v[i] * v[i];
  }
  return acc;
}

static int test_qmf_analysis_nonzero() {
  printf("=== QMF analysis non-zero ===\n");

  AacSbrQmf qmf;
  memset(&qmf, 0, sizeof(qmf));

  float time_in[1024];
  float qmf_out[64][32];

  for (int i = 0; i < 1024; i++) {
    time_in[i] = expf(-0.01f * (float)i) * 0.5f;
  }

  aac_sbr_qmf_analysis(&qmf, time_in, qmf_out, nullptr);

  float rms = vector_rms(&qmf_out[0][0], 64 * 32);
  printf("QMF analysis RMS: %f\n", rms);

  if (rms < 1e-6f) {
    printf("FAIL: QMF analysis output is silent\n");
    return 1;
  }
  printf("PASS\n\n");
  return 0;
}

static int test_qmf_synthesis_nonzero() {
  printf("=== QMF synthesis non-zero ===\n");

  AacSbrQmf qmf;
  memset(&qmf, 0, sizeof(qmf));

  float qmf_in[64][32];
  float time_out[2048];

  for (int k = 0; k < 64; k++) {
    for (int ts = 0; ts < 32; ts++) {
      qmf_in[k][ts] = 0.01f * sinf(0.1f * (float)(k * ts + 1));
    }
  }

  aac_sbr_qmf_synthesis(&qmf, qmf_in, time_out, nullptr);

  float rms = vector_rms(time_out, 2048);
  printf("QMF synthesis RMS: %f\n", rms);

  if (rms < 1e-6f) {
    printf("FAIL: QMF synthesis output is silent\n");
    return 1;
  }
  printf("PASS\n\n");
  return 0;
}

static int test_ps_decode_energy() {
  printf("=== PS decode energy & stereo ===\n");

  AacPsParams ps;
  memset(&ps, 0, sizeof(ps));
  ps.enable_iid = 1;
  ps.enable_icc = 1;
  ps.num_iid_bands = 8;
  ps.num_icc_bands = 8;
  for (int b = 0; b < 8; b++) {
    ps.iid[b] = 3.0f;
    ps.icc[b] = 0.5f;
  }

  float qmf_mono[64][32];
  float qmf_L[64][32];
  float qmf_R[64][32];

  for (int b = 0; b < 64; b++) {
    for (int ts = 0; ts < 32; ts++) {
      qmf_mono[b][ts] = 0.2f * sinf(0.05f * (float)(b * 32 + ts));
    }
  }

  aac_ps_decode(&ps, qmf_mono, qmf_L, qmf_R, 8);

  float rmsL = vector_rms(&qmf_L[0][0], 64 * 32);
  float rmsR = vector_rms(&qmf_R[0][0], 64 * 32);
  float rmsM = vector_rms(&qmf_mono[0][0], 64 * 32);

  printf("Mono RMS:  %f\n", rmsM);
  printf("Left RMS:  %f\n", rmsL);
  printf("Right RMS: %f\n", rmsR);

  if (rmsL < 1e-6f || rmsR < 1e-6f) {
    printf("FAIL: PS decode produced silent channel\n");
    return 1;
  }
  if (fabsf(rmsL - rmsR) < 1e-6f) {
    printf("FAIL: PS decode did not apply IID (L == R)\n");
    return 1;
  }
  printf("PASS\n\n");
  return 0;
}

static int test_ps_decode_power_preserving() {
  printf("=== PS decode power preservation (ICC=0) ===\n");

  AacPsParams ps;
  memset(&ps, 0, sizeof(ps));
  ps.enable_iid = 1;
  ps.enable_icc = 1;
  ps.num_iid_bands = 8;
  ps.num_icc_bands = 8;
  for (int b = 0; b < 8; b++) {
    ps.iid[b] = 0.0f;
    ps.icc[b] = 0.0f;
  }

  float qmf_mono[64][32];
  float qmf_L[64][32];
  float qmf_R[64][32];

  for (int b = 0; b < 64; b++) {
    for (int ts = 0; ts < 32; ts++) {
      qmf_mono[b][ts] = 0.2f * sinf(0.07f * (float)(b * 19 + ts));
    }
  }

  /* Feed the delay line enough history to be representative */
  aac_ps_decode(&ps, qmf_mono, qmf_L, qmf_R, 8);
  aac_ps_decode(&ps, qmf_mono, qmf_L, qmf_R, 8);

  float eL = 0.0f, eR = 0.0f, eM = 0.0f;
  for (int b = 0; b < 8; b++) {
    for (int ts = 0; ts < 32; ts++) {
      eL += qmf_L[b][ts] * qmf_L[b][ts];
      eR += qmf_R[b][ts] * qmf_R[b][ts];
      eM += qmf_mono[b][ts] * qmf_mono[b][ts];
    }
  }

  printf("Energy M=%f L=%f R=%f, L+R=%f, 2M=%f\n", eM, eL, eR, eL + eR, 2.0f * eM);

  if (eL < 1e-6f || eR < 1e-6f) {
    printf("FAIL: silent channel\n");
    return 1;
  }
  /* Direct path preserves power when D is truly decorrelated; with a
   * delay decorrelator the sum should be within ~2 dB of 2*E{M^2}. */
  float ratio = (eL + eR) / (2.0f * eM);
  printf("Power ratio: %f (target ~1.0)\n", ratio);
  if (ratio < 0.5f || ratio > 2.0f) {
    printf("FAIL: power preservation ratio out of range\n");
    return 1;
  }

  float ab = 0.0f, aa = 0.0f, bb = 0.0f;
  for (int b = 0; b < 8; b++) {
    for (int ts = 0; ts < 32; ts++) {
      ab += qmf_L[b][ts] * qmf_R[b][ts];
      aa += qmf_L[b][ts] * qmf_L[b][ts];
      bb += qmf_R[b][ts] * qmf_R[b][ts];
    }
  }
  float corr = ab / (sqrtf(aa * bb) + 1e-10f);
  printf("L/R correlation: %f (target low for ICC=0)\n", corr);
  if (corr > 0.9f) {
    printf("FAIL: channels remain highly correlated for ICC=0\n");
    return 1;
  }
  printf("PASS\n\n");
  return 0;
}

static int test_ps_encode_decode() {
  printf("=== PS encode/decode roundtrip ===\n");

  float qmf_L[64][32];
  float qmf_R[64][32];
  float qmf_L2[64][32];
  float qmf_R2[64][32];
  AacPsParams ps;

  for (int b = 0; b < 64; b++) {
    for (int ts = 0; ts < 32; ts++) {
      qmf_L[b][ts] = 0.3f * sinf(0.03f * (float)(b * 17 + ts));
      qmf_R[b][ts] = 0.1f * sinf(0.03f * (float)(b * 17 + ts) + 0.5f);
    }
  }

  memset(&ps, 0, sizeof(ps));
  aac_ps_encode(&ps, qmf_L, qmf_R, 8);

  printf("Encoded IID[0]=%f dB, ICC[0]=%f\n", ps.iid[0], ps.icc[0]);

  aac_ps_decode(&ps, (const float (*)[32])qmf_L, qmf_L2, qmf_R2, 8);

  float eL = 0.0f, eR = 0.0f;
  for (int b = 0; b < 8; b++) {
    for (int ts = 0; ts < 32; ts++) {
      eL += qmf_L2[b][ts] * qmf_L2[b][ts];
      eR += qmf_R2[b][ts] * qmf_R2[b][ts];
    }
  }
  printf("Decoded energy L=%f R=%f\n", eL, eR);

  if (eL < 1e-6f || eR < 1e-6f) {
    printf("FAIL: PS roundtrip produced silent channel\n");
    return 1;
  }
  printf("PASS\n\n");
  return 0;
}

int main() {
  int failures = 0;
  failures += test_qmf_analysis_nonzero();
  failures += test_qmf_synthesis_nonzero();
  failures += test_ps_decode_energy();
  failures += test_ps_decode_power_preserving();
  failures += test_ps_encode_decode();
  printf("=== %d test(s) failed ===\n", failures);
  return failures;
}
