#include "bitstream.h"

#include <algorithm>
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
  if (r->byte_pos >= r->size) {
    return 0;
  }
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

int aac_bitreader_read_huffman(AacBitReader* r, int cb, int* out) {
  if (cb < 1 || cb > AAC_NUM_CODEBOOKS) { return AAC_ERR_INVALID_ARG;
}
  if (!aac_huff_code[cb] || !aac_huff_len[cb]) { return AAC_ERR_UNSUPPORTED;
}

  int n = aac_huff_count[cb];
  const uint32_t* codes = aac_huff_code[cb];
  const uint8_t* lens = aac_huff_len[cb];
  /* Peek enough bits for the longest ISO codeword (16 in CB11). */
  uint32_t peek = aac_bitreader_peek(r, 16);

  /* Find longest matching codeword (handles non-prefix-free ordering). */
  int best_idx = -1, best_len = 0;
  for (int i = 0; i < n; i++) {
    int len = lens[i];
    if (!len || len > 16) { continue;
}
    if ((peek >> (16 - len)) == (codes[i] >> (32 - len))) {
      if (len > best_len) {
        best_idx = i;
        best_len = len;
      }
    }
  }
  if (best_idx < 0) { return AAC_ERR_DECODE;
}

  aac_bitreader_skip(r, best_len);
  const AacCodebookInfo* info = &aac_codebook_info[cb];
  int mv = info->max_val;
  int dim = info->dim;
  int v[4] = {0, 0, 0, 0};
  int neg[4] = {0, 0, 0, 0};

  if (info->is_unsigned) {
    int mod = mv + 1;
    int t = best_idx;
    for (int k = dim - 1; k >= 0; k--) {
      v[k] = t % mod;
      t /= mod;
    }
    /* Sign bits for every nonzero coefficient, in v[0]..v[dim-1] order. */
    for (int k = 0; k < dim; k++) {
      if (v[k] != 0) {
        neg[k] = (int)aac_bitreader_read(r, 1);
      }
    }
  } else {
    int mod = 2 * mv + 1;
    int t = best_idx;
    for (int k = dim - 1; k >= 0; k--) {
      v[k] = (t % mod) - mv;
      t /= mod;
    }
  }

  /* Escape extension for unsigned escape book (cb 11).  Magnitude mv (16)
   * is ESC and carries (N-4) one-bits + terminating zero + N-bit word;
   * decoded magnitude is 2^N + word. */
  if (cb == AAC_CB_ESCAPE) {
    for (int k = 0; k < dim; k++) {
      if (v[k] == mv) {
        int n = 4;
        while (aac_bitreader_read(r, 1)) {
          if (++n > 16) {
            return AAC_ERR_DECODE; /* corrupt escape prefix */
          }
        }
        v[k] = (1 << n) + (int)aac_bitreader_read(r, n);
      }
    }
  }

  for (int k = 0; k < dim; k++) {
    if (neg[k]) {
      v[k] = -v[k];
    }
    out[k] = v[k];
  }
  return 0;
}

int aac_bitreader_read_scalefactor(AacBitReader* r) {
  /* ISO 14496-3 Table 4.47: index 60 corresponds to a zero DPCM
   * difference.  Codewords are up to 19 bits long. */
  uint32_t peek = aac_bitreader_peek(r, 19);
  int best_idx = -1;
  int best_len = 0;
  for (int i = 0; i < 121; i++) {
    int len = aac_scalefactor_bits[i];
    if (!len || len > 19) {
      continue;
    }
    if ((peek >> (19 - len)) == aac_scalefactor_code[i]) {
      if (len > best_len) {
        best_idx = i;
        best_len = len;
      }
    }
  }
  if (best_idx < 0) {
    return AAC_ERR_DECODE;
  }
  aac_bitreader_skip(r, best_len);
  return best_idx - AAC_SCALE_DIFF_ZERO;
}

int aac_bitreader_read_sections(AacBitReader* r, int is_short, int max_sfb,
                                int* out_codebooks, int max_bands) {
  if (max_sfb > max_bands) {
    return AAC_ERR_INVALID_ARG;
  }
  int run_bits = is_short ? 3 : 5;
  int run_esc = (1 << run_bits) - 1;
  int band = 0;
  while (band < max_sfb) {
    if (aac_bitreader_bits_left(r) < 4) {
      return AAC_ERR_DECODE;
    }
    int cb = aac_bitreader_read(r, 4);
    if (cb == 12) {
      return AAC_ERR_DECODE; /* reserved */
    }
    int run = 0;
    int incr = 0;
    do {
      if (aac_bitreader_bits_left(r) < run_bits) {
        return AAC_ERR_DECODE;
      }
      incr = (int)aac_bitreader_read(r, run_bits);
      run += incr;
    } while (incr == run_esc);
    if (band + run > max_sfb) {
      return AAC_ERR_DECODE;
    }
    for (int i = 0; i < run; i++) {
      out_codebooks[band + i] = cb;
    }
    band += run;
  }
  for (int i = band; i < max_bands; i++) {
    out_codebooks[i] = 0;
  }
  return 0;
}

/* ── Bit Writer ────────────────────────────────────────────────── */

void aac_bitwriter_init(AacBitWriter* w, uint8_t* d, int c) {
  w->data = d;
  w->capacity = c;
  w->byte_pos = 0;
  w->bit_pos = 0;
  w->overflow = 0;
  memset(d, 0, c);
}

void aac_bitwriter_write(AacBitWriter* w, uint32_t v, int n) {
  for (int i = n - 1; i >= 0; i--) {
    if (w->byte_pos >= w->capacity) {
      w->overflow = 1;
      return;
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

int aac_bitwriter_overflow(const AacBitWriter* w) { return w->overflow; }

void aac_bitwriter_write_signed(AacBitWriter* w, int32_t v, int n) {
  aac_bitwriter_write(w, (v < 0) ? ((1u << n) + v) : (uint32_t)v, n);
}

int aac_bitwriter_write_huffman(AacBitWriter* w, int cb, const int* v_in) {
  if (cb < 1 || cb > AAC_NUM_CODEBOOKS) { return AAC_ERR_INVALID_ARG;
}
  if (!aac_huff_code[cb] || !aac_huff_len[cb]) { return AAC_ERR_UNSUPPORTED;
}

  const AacCodebookInfo* info = &aac_codebook_info[cb];
  int mv = info->max_val;
  int dim = info->dim;
  int v[4] = {0, 0, 0, 0};
  int mag[4] = {0, 0, 0, 0};

  for (int k = 0; k < dim; k++) {
    v[k] = v_in[k];
    mag[k] = v[k] < 0 ? -v[k] : v[k];
  }

  int idx = 0;
  if (info->is_unsigned) {
    int max_mag = (cb == AAC_CB_ESCAPE) ? AAC_ESC_MAX_MAG : mv;
    int mod = mv + 1;
    for (int k = 0; k < dim; k++) {
      if (mag[k] > max_mag) { mag[k] = max_mag;
}
      idx = idx * mod + std::min(mag[k], mv);
    }
  } else {
    int mod = 2 * mv + 1;
    for (int k = 0; k < dim; k++) {
      int sv = v[k];
      if (sv < -mv) { sv = -mv;
}
      if (sv > mv) { sv = mv;
}
      idx = idx * mod + (sv + mv);
    }
  }
  if (idx < 0 || idx >= aac_huff_count[cb]) { return AAC_ERR_INVALID_ARG;
}

  int len = aac_huff_len[cb][idx];
  aac_bitwriter_write(w, aac_huff_code[cb][idx] >> (32 - len), len);

  /* Sign bits for unsigned books, in v[0]..v[dim-1] order. */
  if (info->is_unsigned) {
    for (int k = 0; k < dim; k++) {
      if (v[k] != 0) {
        aac_bitwriter_write(w, v[k] < 0 ? 1u : 0u, 1);
      }
    }
  }

  /* Escape extension for unsigned escape book. */
  if (cb == AAC_CB_ESCAPE) {
    for (int k = 0; k < dim; k++) {
      if (mag[k] >= mv) {
        /* value = 2^n + word with word < 2^n; prefix is (n-4) ones + zero */
        int n = 4;
        while (mag[k] >= (1 << (n + 1))) {
          n++;
        }
        aac_bitwriter_write(w, (uint32_t)((1 << (n - 4)) - 1), n - 4);
        aac_bitwriter_write(w, 0, 1);
        aac_bitwriter_write(w, (uint32_t)(mag[k] - (1 << n)), n);
      }
    }
  }
  return 0;
}

void aac_bitwriter_write_scalefactor(AacBitWriter* w, int diff) {
  int idx = diff + AAC_SCALE_DIFF_ZERO;
  if (idx < 0) {
    idx = 0;
  } else if (idx > 120) {
    idx = 120;
  }
  aac_bitwriter_write(w, aac_scalefactor_code[idx], aac_scalefactor_bits[idx]);
}

void aac_bitwriter_write_sections(AacBitWriter* w, int is_short, const int* codebooks, int nb) {
  if (nb <= 0) {
    return;
  }
  int run_bits = is_short ? 3 : 5;
  int run_esc = (1 << run_bits) - 1;
  int i = 0;
  while (i < nb) {
    int cb = codebooks[i];
    if (cb == 12) {
      cb = 0; /* map reserved to zero */
    }
    aac_bitwriter_write(w, cb, 4);
    int run = 1;
    while (i + run < nb && codebooks[i + run] == cb) {
      run++;
    }
    int count = run;
    while (count >= run_esc) {
      aac_bitwriter_write(w, run_esc, run_bits);
      count -= run_esc;
    }
    aac_bitwriter_write(w, count, run_bits);
    i += run;
  }
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
  /* Sanity: frame_length covers the header itself plus (when
   * protection_absent == 0) the 16-bit CRC check that follows it.
   * ISO 13818-7 §8.1.1: aac_frame_length = header + CRC + raw data. */
  int min_len = AAC_ADTS_HEADER_SIZE + (h->protection_absent ? 0 : 2);
  if (h->frame_length < min_len) {
    return AAC_ERR_DECODE;
  }
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
