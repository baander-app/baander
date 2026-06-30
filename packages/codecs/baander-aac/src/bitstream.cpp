#include "bitstream.h"

#include <cstring>

#include "aac_cpu.h"
#include "aac_dsp.h"
#include "aac_tables.h"
#include "fft.h"
#include "mdct.h"

/* ── Bit Reader ────────────────────────────────────────────────── */

void aac_bitreader_init(AacBitReader* r, const uint8_t* d, int s) {
  r->data = d;
  r->size = s;
  r->byte_pos = 0;
  r->bit_pos = 0;
}

int aac_bitreader_bits_left(const AacBitReader* r) {
  return (r->size - r->byte_pos) * 8 - r->bit_pos;
}

uint32_t aac_bitreader_peek(AacBitReader* r, int n) {
  uint32_t v = 0;
  int bp = r->byte_pos, bitp = r->bit_pos;
  for (int i = 0; i < n; i++) {
    v <<= 1;
    if (bp < r->size) { v |= (r->data[bp] >> (7 - bitp)) & 1;
}
    bitp++;
    if (bitp >= 8) {
      bitp = 0;
      bp++;
    }
  }
  return v;
}

uint32_t aac_bitreader_read(AacBitReader* r, int n) {
  uint32_t v = aac_bitreader_peek(r, n);
  r->byte_pos += (r->bit_pos + n) / 8;
  r->bit_pos = (r->bit_pos + n) % 8;
  return v;
}

int32_t aac_bitreader_read_signed(AacBitReader* r, int n) {
  uint32_t u = aac_bitreader_read(r, n);
  if (n > 0 && (u & (1u << (n - 1)))) { u |= ~((1u << n) - 1);
}
  return (int32_t)u;
}

void aac_bitreader_skip(AacBitReader* r, int n) { aac_bitreader_read(r, n); }

void aac_bitreader_byte_align(AacBitReader* r) {
  if (r->bit_pos > 0) {
    r->byte_pos++;
    r->bit_pos = 0;
  }
}

int aac_bitreader_read_huffman(AacBitReader* r, int cb, int* x, int* y) {
  if (cb < 1 || cb > AAC_NUM_CODEBOOKS) { return AAC_ERR_INVALID_ARG;
}
  if (!aac_huff_code[cb] || !aac_huff_len[cb]) { return AAC_ERR_UNSUPPORTED;
}

  int n = aac_huff_count[cb];
  const uint32_t* codes = aac_huff_code[cb];
  const uint8_t* lens = aac_huff_len[cb];
  uint32_t peek = aac_bitreader_peek(r, 11);

  /* Find longest matching codeword (handles non-prefix-free ordering) */
  int best_idx = -1, best_len = 0;
  for (int i = 0; i < n; i++) {
    int len = lens[i];
    if (!len || len > 11) { continue;
}
    if ((peek >> (11 - len)) == (codes[i] >> (32 - len))) {
      if (len > best_len) {
        best_idx = i;
        best_len = len;
      }
    }
  }
  if (best_idx < 0) { return AAC_ERR_DECODE;
}

  aac_bitreader_skip(r, best_len);
  int mv = aac_codebook_info[cb].max_val;
  if (aac_codebook_info[cb].is_unsigned) {
    *x = best_idx / (mv + 1);
    *y = best_idx % (mv + 1);
  } else {
    int dim = 2 * mv + 1;
    *x = best_idx / dim - mv;
    *y = best_idx % dim - mv;
  }
  return 0;
}

/* ── Bit Writer ────────────────────────────────────────────────── */

void aac_bitwriter_init(AacBitWriter* w, uint8_t* d, int c) {
  w->data = d;
  w->capacity = c;
  w->byte_pos = 0;
  w->bit_pos = 0;
  memset(d, 0, c);
}

void aac_bitwriter_write(AacBitWriter* w, uint32_t v, int n) {
  for (int i = n - 1; i >= 0; i--) {
    if (w->byte_pos >= w->capacity) { return;
}
    if ((v >> i) & 1) { w->data[w->byte_pos] |= (1 << (7 - w->bit_pos));
}
    w->bit_pos++;
    if (w->bit_pos >= 8) {
      w->bit_pos = 0;
      w->byte_pos++;
    }
  }
}

void aac_bitwriter_write_signed(AacBitWriter* w, int32_t v, int n) {
  aac_bitwriter_write(w, (v < 0) ? ((1u << n) + v) : (uint32_t)v, n);
}

int aac_bitwriter_write_huffman(AacBitWriter* w, int cb, int x, int y) {
  if (cb < 1 || cb > AAC_NUM_CODEBOOKS) { return AAC_ERR_INVALID_ARG;
}
  if (!aac_huff_code[cb] || !aac_huff_len[cb]) { return AAC_ERR_UNSUPPORTED;
}

  const AacCodebookInfo* info = &aac_codebook_info[cb];
  int mv = info->max_val;
  int idx = 0;
  if (info->is_unsigned) {
    idx = x * (mv + 1) + y;
  } else {
    idx = (x + mv) * (2 * mv + 1) + (y + mv);
  }
  if (idx < 0 || idx >= aac_huff_count[cb]) { return AAC_ERR_INVALID_ARG;
}

  int len = aac_huff_len[cb][idx];
  uint32_t code = aac_huff_code[cb][idx] >> (32 - len);
  aac_bitwriter_write(w, code, len);
  return 0;
}

void aac_bitwriter_byte_align(AacBitWriter* w) {
  if (w->bit_pos > 0) {
    w->byte_pos++;
    w->bit_pos = 0;
  }
}

int aac_bitwriter_bytes_written(const AacBitWriter* w) {
  return w->byte_pos + (w->bit_pos > 0 ? 1 : 0);
}

/* ── ADTS ──────────────────────────────────────────────────────── */

int aac_adts_parse(AacAdtsHeader* h, const uint8_t* d, int s) {
  if (s < 7 || d[0] != 0xFF || (d[1] & 0xF0) != 0xF0) { return AAC_ERR_DECODE;
}
  AacBitReader r;
  aac_bitreader_init(&r, d, s);
  aac_bitreader_read(&r, 12);
  h->id = aac_bitreader_read(&r, 1);
  h->layer = aac_bitreader_read(&r, 2);
  h->protection_absent = aac_bitreader_read(&r, 1);
  h->profile = (AacObjectType)(aac_bitreader_read(&r, 2) + 1);
  h->sample_rate_index = aac_bitreader_read(&r, 4);
  h->private_bit = aac_bitreader_read(&r, 1);
  h->channel_config = aac_bitreader_read(&r, 3);
  h->original_copy = aac_bitreader_read(&r, 1);
  h->home = aac_bitreader_read(&r, 1);
  h->copyright_id_bit = aac_bitreader_read(&r, 1);
  h->copyright_id_start = aac_bitreader_read(&r, 1);
  h->frame_length = aac_bitreader_read(&r, 13);
  h->buffer_fullness = aac_bitreader_read(&r, 11);
  h->num_aac_frames = aac_bitreader_read(&r, 2);
  return 0;
}

int aac_adts_write(const AacAdtsHeader* h, uint8_t* o) {
  AacBitWriter w;
  aac_bitwriter_init(&w, o, 7);
  aac_bitwriter_write(&w, 0xFFF, 12);
  aac_bitwriter_write(&w, h->id, 1);
  aac_bitwriter_write(&w, h->layer, 2);
  aac_bitwriter_write(&w, h->protection_absent, 1);
  aac_bitwriter_write(&w, h->profile - 1, 2);
  aac_bitwriter_write(&w, h->sample_rate_index, 4);
  aac_bitwriter_write(&w, h->private_bit, 1);
  aac_bitwriter_write(&w, h->channel_config, 3);
  aac_bitwriter_write(&w, h->original_copy, 1);
  aac_bitwriter_write(&w, h->home, 1);
  aac_bitwriter_write(&w, h->copyright_id_bit, 1);
  aac_bitwriter_write(&w, h->copyright_id_start, 1);
  aac_bitwriter_write(&w, h->frame_length, 13);
  aac_bitwriter_write(&w, h->buffer_fullness, 11);
  aac_bitwriter_write(&w, h->num_aac_frames, 2);
  return aac_bitwriter_bytes_written(&w);
}

/* ── Scalar vector operation defaults ─────────────────────────────── */

static void aac_vector_fmul_c(float* dst, const float* a, const float* b, int len) {
  for (int i = 0; i < len; i++) { dst[i] = a[i] * b[i];
}
}
static void aac_vector_fmul_scalar_c(float* dst, const float* a, float scale, int len) {
  for (int i = 0; i < len; i++) { dst[i] = a[i] * scale;
}
}
static void aac_vector_fmul_add_c(float* dst, const float* a, const float* b, const float* c,
                                  int len) {
  for (int i = 0; i < len; i++) { dst[i] = a[i] * b[i] + c[i];
}
}
static void aac_vector_fmul_window_c(float* dst, const float* a, const float* b, const float* win,
                                     int n) {
  for (int i = 0; i < n; i++) { dst[i] = a[i] * win[i] + b[i] * win[n - 1 - i];
}
}
static void aac_vector_fmul_reverse_c(float* dst, const float* a, const float* b, int len) {
  for (int i = 0; i < len; i++) { dst[i] = a[i] * b[len - 1 - i];
}
}
static void aac_vector_fmul_accumulate_c(float* dst, const float* a, const float* b, int len) {
  for (int i = 0; i < len; i++) { dst[i] += a[i] * b[i];
}
}

/* ── DSP Init: wire all scalar defaults + platform overrides ────── */

void aac_dsp_init(AacDSP* dsp) {
  dsp->fft_forward = aac_fft_forward_c;
  dsp->fft_inverse = aac_fft_inverse_c;
  dsp->mdct_forward = aac_mdct_forward_c;
  dsp->imdct_half = aac_imdct_half_c;
  dsp->vector_fmul = aac_vector_fmul_c;
  dsp->vector_fmul_scalar = aac_vector_fmul_scalar_c;
  dsp->vector_fmul_add = aac_vector_fmul_add_c;
  dsp->vector_fmul_window = aac_vector_fmul_window_c;
  dsp->vector_fmul_reverse = aac_vector_fmul_reverse_c;
  dsp->vector_fmul_accumulate = aac_vector_fmul_accumulate_c;
  dsp->huffman_decode = nullptr;
  dsp->sbr_qmf_analysis = nullptr;
  dsp->sbr_qmf_synthesis = nullptr;

  /* Default scalar psycho spreading (matrix-vector) */
  dsp->psycho_spreading = [](float* spread, const float* energy, const float* /*threshold*/,
                             int n_sfb) {
    /* Note: threshold param currently unused in this default; real spreading only */
    for (int b = 0; b < n_sfb; b++) {
      float sp = 0.0f;
      for (int j = 0; j < n_sfb; j++) {
        sp += energy[j] * /* spreading would come from caller context, but for DSP we assume simple
                             identity or external */
              0.0f;       /* placeholder - real impl needs spreading matrix */
      }
      spread[b] = sp;
    }
  };

  int flags = aac_get_cpu_flags();
#if defined(BAAC_AAC_SSE2)
  if (flags & AAC_CPU_FLAG_SSE2) { aac_dsp_init_sse2(dsp);
}
#endif
#if defined(BAAC_AAC_AVX2)
  if (flags & AAC_CPU_FLAG_AVX2) { aac_dsp_init_avx2(dsp);
}
#endif
#if defined(BAAC_AAC_NEON)
  if (flags & AAC_CPU_FLAG_NEON) aac_dsp_init_neon(dsp);
#endif
#if defined(BAAC_AAC_WASM)
  if (flags & AAC_CPU_FLAG_WASM_SIMD128) aac_dsp_init_wasm(dsp);
#endif
  (void)flags;
}
