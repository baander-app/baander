#include "sbr.h"

#include <algorithm>
#include <cmath>
#include <cstring>

/* SBR frequency band tables (ISO/IEC 14496-3 4.6.18.3), shared by the
 * decoder and the encoder: the master table from the header's start and
 * stop frequency indices, the high and low resolution tables, the noise
 * floor bands, the patches of the high-frequency generator and the
 * limiter bands. */

namespace {


const int8_t kSbrOffset[6][16] = {
    {-8, -7, -6, -5, -4, -3, -2, -1, 0, 1, 2, 3, 4, 5, 6, 7},        /* 16000 */
    {-5, -4, -3, -2, -1, 0, 1, 2, 3, 4, 5, 6, 7, 9, 11, 13},         /* 22050 */
    {-5, -3, -2, -1, 0, 1, 2, 3, 4, 5, 6, 7, 9, 11, 13, 16},         /* 24000 */
    {-6, -4, -2, -1, 0, 1, 2, 3, 4, 5, 6, 7, 9, 11, 13, 16},         /* 32000 */
    {-4, -2, -1, 0, 1, 2, 3, 4, 5, 6, 7, 9, 11, 13, 16, 20},         /* 44100..64000 */
    {-2, -1, 0, 1, 2, 3, 4, 5, 6, 7, 9, 11, 13, 16, 20, 24},         /* above 64000 */
};

void make_bands(int16_t* bands, int start, int stop, int num_bands) {
  const float base = powf((float)stop / (float)start, 1.0f / (float)num_bands);
  float prod = (float)start;
  int previous = start;
  for (int k = 0; k < num_bands - 1; k++) {
    prod *= base;
    const int present = (int)lrintf(prod);
    bands[k] = (int16_t)(present - previous);
    previous = present;
  }
  bands[num_bands - 1] = (int16_t)(stop - previous);
}

bool in_table(const int16_t* table, int last, int16_t needle) {
  for (int i = 0; i <= last; i++) {
    if (table[i] == needle) {
      return true;
    }
  }
  return false;
}

/* Limiter band table (4.6.18.3.6). */
void make_f_tablelim(AacSbrFreq* f) {
  if (f->limiter_bands > 0) {
    static const float kWarped[3] = {1.32715174233856803909f, 1.18509277094158210129f,
                                     1.11987160404675912501f};
    const float lim_bands_per_octave_warped = kWarped[f->limiter_bands - 1];
    int16_t patch_borders[7];
    patch_borders[0] = (int16_t)f->kx;
    for (int k = 1; k <= f->num_patches; k++) {
      patch_borders[k] = (int16_t)(patch_borders[k - 1] + f->patch_num_subbands[k - 1]);
    }
    memcpy(f->f_tablelim, f->f_tablelow, (size_t)(f->n[0] + 1) * sizeof(f->f_tablelow[0]));
    for (int k = 1; k < f->num_patches; k++) {
      f->f_tablelim[f->n[0] + k] = (uint16_t)patch_borders[k];
    }
    std::sort(f->f_tablelim, f->f_tablelim + f->num_patches + f->n[0]);
    f->n_lim = f->n[0] + f->num_patches - 1;
    uint16_t* in = f->f_tablelim + 1;
    uint16_t* out = f->f_tablelim;
    while (out < f->f_tablelim + f->n_lim) {
      if ((float)*in >= (float)*out * lim_bands_per_octave_warped) {
        *++out = *in++;
      } else if (*in == *out || !in_table(patch_borders, f->num_patches, (int16_t)*in)) {
        in++;
        f->n_lim--;
      } else if (!in_table(patch_borders, f->num_patches, (int16_t)*out)) {
        *out = *in++;
        f->n_lim--;
      } else {
        *++out = *in++;
      }
    }
  } else {
    f->f_tablelim[0] = f->f_tablelow[0];
    f->f_tablelim[1] = f->f_tablelow[f->n[0]];
    f->n_lim = 1;
  }
}

/* Master frequency band table (4.6.18.3.2).  The offset row and the
 * frequency constants come from `table_rate`, the band values from
 * `sample_rate`: the two are the same except at the USAC 4:1 ratio
 * (ISO/IEC 23003-3 7.5), where the indices are those of the 2:1 system
 * at twice the core rate while the bands are those of the 64-band
 * synthesis at four times the core rate. */
int make_f_master(AacSbrFreq* f) {
  const int8_t* offsets;
  const int table_rate = f->table_rate ? f->table_rate : f->sample_rate;
  switch (table_rate) {
    case 16000: offsets = kSbrOffset[0]; break;
    case 22050: offsets = kSbrOffset[1]; break;
    case 24000: offsets = kSbrOffset[2]; break;
    case 32000: offsets = kSbrOffset[3]; break;
    case 44100: case 48000: case 64000: offsets = kSbrOffset[4]; break;
    case 88200: case 96000: case 128000: case 176400: case 192000: offsets = kSbrOffset[5]; break;
    default: return AAC_ERR_UNSUPPORTED;
  }
  const int temp = table_rate < 32000 ? 3000 : (table_rate < 64000 ? 4000 : 5000);
  const int start_min = ((temp << 7) + (f->sample_rate >> 1)) / f->sample_rate;
  const int stop_min = ((temp << 8) + (f->sample_rate >> 1)) / f->sample_rate;
  f->k[0] = start_min + offsets[f->start_freq];
  if (f->stop_freq < 14) {
    int16_t stop_dk[13];
    f->k[2] = stop_min;
    make_bands(stop_dk, stop_min, 64, 13);
    std::sort(stop_dk, stop_dk + 13);
    for (int k = 0; k < f->stop_freq; k++) {
      f->k[2] += stop_dk[k];
    }
  } else if (f->stop_freq == 14) {
    f->k[2] = 2 * f->k[0];
  } else {
    f->k[2] = 3 * f->k[0];
  }
  f->k[2] = std::min(64, f->k[2]);
  const int max_qmf_subbands = table_rate <= 32000 ? 48 : (table_rate == 44100 ? 35 : 32);
  if (f->k[2] - f->k[0] > max_qmf_subbands || f->k[2] <= f->k[0] || f->k[0] <= 0) {
    return AAC_ERR_DECODE;
  }

  if (!f->freq_scale) {
    const int dk = f->alter_scale + 1;
    f->n_master = ((f->k[2] - f->k[0] + (dk & 2)) >> dk) << 1;
    if (f->n_master <= 0 || f->xover_band >= f->n_master) {
      return AAC_ERR_DECODE;
    }
    for (int k = 1; k <= f->n_master; k++) {
      f->f_master[k] = (uint16_t)dk;
    }
    const int k2diff = f->k[2] - f->k[0] - f->n_master * dk;
    if (k2diff < 0) {
      f->f_master[1]--;
      f->f_master[2] -= (k2diff < -1);
    } else if (k2diff) {
      f->f_master[f->n_master]++;
    }
    f->f_master[0] = (uint16_t)f->k[0];
    for (int k = 1; k <= f->n_master; k++) {
      f->f_master[k] += f->f_master[k - 1];
    }
    return 0;
  }

  const int half_bands = 7 - f->freq_scale;
  int two_regions;
  if (49 * f->k[2] > 110 * f->k[0]) {
    two_regions = 1;
    f->k[1] = 2 * f->k[0];
  } else {
    two_regions = 0;
    f->k[1] = f->k[2];
  }
  const int num_bands_0 = (int)lrintf((float)half_bands * log2f((float)f->k[1] / (float)f->k[0])) * 2;
  if (num_bands_0 <= 0) {
    return AAC_ERR_DECODE;
  }
  int16_t vk0[49];
  vk0[0] = 0;
  make_bands(vk0 + 1, f->k[0], f->k[1], num_bands_0);
  std::sort(vk0 + 1, vk0 + 1 + num_bands_0);
  const int vdk0_max = vk0[num_bands_0];
  vk0[0] = (int16_t)f->k[0];
  for (int k = 1; k <= num_bands_0; k++) {
    if (vk0[k] <= 0) {
      return AAC_ERR_DECODE;
    }
    vk0[k] = (int16_t)(vk0[k] + vk0[k - 1]);
  }
  if (two_regions) {
    int16_t vk1[49];
    const float invwarp = f->alter_scale ? 0.76923076923076923077f : 1.0f;
    const int num_bands_1 =
        (int)lrintf((float)half_bands * invwarp * log2f((float)f->k[2] / (float)f->k[1])) * 2;
    if (num_bands_1 <= 0) {
      return AAC_ERR_DECODE;
    }
    make_bands(vk1 + 1, f->k[1], f->k[2], num_bands_1);
    int vdk1_min = vk1[1];
    for (int k = 2; k <= num_bands_1; k++) {
      vdk1_min = std::min(vdk1_min, (int)vk1[k]);
    }
    if (vdk1_min < vdk0_max) {
      std::sort(vk1 + 1, vk1 + 1 + num_bands_1);
      const int change = std::min(vdk0_max - vk1[1], (vk1[num_bands_1] - vk1[1]) >> 1);
      vk1[1] = (int16_t)(vk1[1] + change);
      vk1[num_bands_1] = (int16_t)(vk1[num_bands_1] - change);
    }
    std::sort(vk1 + 1, vk1 + 1 + num_bands_1);
    vk1[0] = (int16_t)f->k[1];
    for (int k = 1; k <= num_bands_1; k++) {
      if (vk1[k] <= 0) {
        return AAC_ERR_DECODE;
      }
      vk1[k] = (int16_t)(vk1[k] + vk1[k - 1]);
    }
    f->n_master = num_bands_0 + num_bands_1;
    if (f->xover_band >= f->n_master) {
      return AAC_ERR_DECODE;
    }
    for (int k = 0; k <= num_bands_0; k++) {
      f->f_master[k] = (uint16_t)vk0[k];
    }
    for (int k = 1; k <= num_bands_1; k++) {
      f->f_master[num_bands_0 + k] = (uint16_t)vk1[k];
    }
  } else {
    f->n_master = num_bands_0;
    if (f->xover_band >= f->n_master) {
      return AAC_ERR_DECODE;
    }
    for (int k = 0; k <= num_bands_0; k++) {
      f->f_master[k] = (uint16_t)vk0[k];
    }
  }
  return 0;
}

/* Patch construction (4.6.18.6.3). */
int hf_calc_npatches(AacSbrFreq* f) {
  int last_k = -1;
  int last_msb = -1;
  int sb = 0;
  int msb = f->k[0];
  int usb = f->kx;
  const int goal_sb = ((1000 << 11) + (f->sample_rate >> 1)) / f->sample_rate;
  int k;
  f->num_patches = 0;
  if (goal_sb < f->kx + f->m) {
    for (k = 0; f->f_master[k] < goal_sb; k++) {
    }
  } else {
    k = f->n_master;
  }
  do {
    int odd = 0;
    if (k == last_k && msb == last_msb) {
      return AAC_ERR_DECODE;
    }
    last_k = k;
    last_msb = msb;
    for (int i = k; i == k || sb > (f->k[0] - 1 + msb - odd); i--) {
      sb = f->f_master[i];
      odd = (sb + f->k[0]) & 1;
    }
    if (f->num_patches > 5) {
      return AAC_ERR_DECODE;
    }
    f->patch_num_subbands[f->num_patches] = std::max(sb - usb, 0);
    f->patch_start_subband[f->num_patches] = f->k[0] - odd - f->patch_num_subbands[f->num_patches];
    if (f->patch_num_subbands[f->num_patches] > 0) {
      usb = sb;
      msb = sb;
      f->num_patches++;
    } else {
      msb = f->kx;
    }
    if (f->f_master[k] - sb < 3) {
      k = f->n_master;
    }
  } while (sb != f->kx + f->m);
  if (f->num_patches > 1 && f->patch_num_subbands[f->num_patches - 1] < 3) {
    f->num_patches--;
  }
  return 0;
}

/* Derived tables (4.6.18.3.2.2). */
int make_f_derived(AacSbrFreq* f) {
  f->n[1] = f->n_master - f->xover_band;
  f->n[0] = (f->n[1] + 1) >> 1;
  memcpy(f->f_tablehigh, &f->f_master[f->xover_band], (size_t)(f->n[1] + 1) * sizeof(f->f_master[0]));
  f->m = f->f_tablehigh[f->n[1]] - f->f_tablehigh[0];
  f->kx = f->f_tablehigh[0];
  if (f->kx + f->m > 64 || f->kx > 32) {
    return AAC_ERR_DECODE;
  }
  f->f_tablelow[0] = f->f_tablehigh[0];
  const int odd = f->n[1] & 1;
  for (int k = 1; k <= f->n[0]; k++) {
    f->f_tablelow[k] = f->f_tablehigh[2 * k - odd];
  }
  f->n_q = std::max(1, (int)lrintf((float)f->noise_bands * log2f((float)f->k[2] / (float)f->kx)));
  if (f->n_q > 5) {
    f->n_q = 1;
    return AAC_ERR_DECODE;
  }
  f->f_tablenoise[0] = f->f_tablelow[0];
  int temp = 0;
  for (int k = 1; k <= f->n_q; k++) {
    temp += (f->n[0] - temp) / (f->n_q + 1 - k);
    f->f_tablenoise[k] = f->f_tablelow[temp];
  }
  const int ret = hf_calc_npatches(f);
  if (ret) {
    return ret;
  }
  make_f_tablelim(f);
  return 0;
}

}  // namespace

int aac_sbr_freq_tables(AacSbrFreq* f) {
  int err = make_f_master(f);
  if (err == 0) {
    err = make_f_derived(f);
  }
  return err;
}

void aac_sbr_freq_limiter(AacSbrFreq* f) { make_f_tablelim(f); }

int aac_sbr_prev_band(int j, int res, int prev_res, int odd) {
  if (res == prev_res) {
    return j;
  }
  if (res) {
    return (j + odd) >> 1;
  }
  return j ? 2 * j - odd : 0;
}
