/* SpatialSpecificConfig() of MPEG Surround (ISO/IEC 23003-1 Table 37 and
 * the low-delay variant of the ELD specific configuration):
 *
 *   bsSamplingFrequencyIndex (4), bsSamplingFrequency (24) when 15,
 *   bsFrameLength (7; 5 in the low-delay form), bsFreqRes (3),
 *   bsTreeConfig (4), bsQuantMode (2), bsOneIcc (1), bsArbitraryDownmix
 *   (1), bsFixedGainSur (3), bsFixedGainLfe (3), bsFixedGainDmx (3),
 *   bsMatrixMode (1), bsTempShapeConfig (2), bsDecorrConfig (2),
 *   bs3DaudioMode (1); the low-delay form leaves out bsOneIcc, the
 *   surround and LFE gains, bsMatrixMode and bs3DaudioMode for the 2-1-2
 *   tree.  OttConfig per box: bsOttBands (5) for an LFE box.  TttConfig
 *   per box: bsTttDualMode (1), bsTttModeLow (3) and with the dual mode
 *   bsTttModeHigh (3), bsTttBandsLow (5).  bsEnvQuantMode (1) with
 *   bsTempShapeConfig 2.  The HRTF parameter set with bs3DaudioMode.
 *   ByteAlign() from the config's start, then SpatialExtensionConfig()
 *   over the bits that remain: bsSacExtType (4), bsSacExtLen (4, + 8
 *   when 15, + 16 when 270) and the extension: ResidualConfig (type 0:
 *   bsResidualSamplingFrequencyIndex (4), bsResidualFramesPerSpatialFrame
 *   (2), per box bsResidualPresent (1) and bsResidualBands (5)), the
 *   arbitrary downmix residual (1) and the arbitrary tree (2), both
 *   outside this decoder, others skipped by their length. */
#include <cstring>

#include "mps212.h"
#include "mps_internal.h"

namespace {

constexpr int kSampleRates[13] = {96000, 88200, 64000, 48000, 44100, 32000, 24000,
                                  22050, 16000, 12000, 11025, 8000,  7350};

/* Parameter bands per bsFreqRes of the low-delay form, which has no
 * hybrid bands below the QMF bands. */
constexpr uint8_t kLdFreqResBands[8] = {0, 23, 15, 12, 9, 7, 5, 4};

struct Tree {
  int num_in, num_out, num_ott, num_ttt;
  int lfe[AAC_MPS_MAX_OTT_BOXES];
};

/* bsTreeConfig 0..7: 5151, 5152, 525, 7271, 7272, 7571, 7572, 212. */
constexpr Tree kTrees[8] = {
    {1, 6, 5, 0, {0, 0, 0, 0, 1}}, {1, 6, 5, 0, {0, 0, 1, 0, 0}}, {2, 6, 3, 1, {1, 0, 0, 0, 0}},
    {2, 8, 5, 1, {1, 0, 0, 0, 0}}, {2, 8, 5, 1, {1, 0, 0, 0, 0}}, {6, 8, 2, 0, {0, 0, 0, 0, 0}},
    {6, 8, 2, 0, {0, 0, 0, 0, 0}}, {1, 2, 1, 0, {0, 0, 0, 0, 0}},
};

bool tree_supported(int tree) {
  return tree == AAC_MPS_TREE_5151 || tree == AAC_MPS_TREE_5152 || tree == AAC_MPS_TREE_525 ||
         tree == AAC_MPS_TREE_212;
}

int read_len(AacBitReader* r) {
  int len = (int)aac_bitreader_read(r, 4);
  if (len == 15) {
    len += (int)aac_bitreader_read(r, 8);
    if (len == 15 + 255) {
      len += (int)aac_bitreader_read(r, 16);
    }
  }
  return len;
}

/* SpatialExtensionConfig() over `bits_available` bits. */
int read_extension_config(AacBitReader* r, AacMpsConfig* cfg, int bits_available) {
  const int num_boxes = cfg->num_ott_boxes + cfg->num_ttt_boxes;
  while (bits_available >= 8) {
    const int start = r->pos;
    const int type = (int)aac_bitreader_read(r, 4);
    const int len = read_len(r);
    bits_available -= r->pos - start;
    if (bits_available < 8 * len || r->overread) {
      return AAC_ERR_DECODE;
    }
    const int data_start = r->pos;
    switch (type) {
      case 0:
        cfg->residual_coding = 1;
        cfg->residual_sample_rate_index = (int)aac_bitreader_read(r, 4);
        cfg->residual_frames_per_spatial_frame = (int)aac_bitreader_read(r, 2) + 1;
        if (cfg->residual_sample_rate_index > 11 ||
            cfg->frame_length % cfg->residual_frames_per_spatial_frame != 0) {
          return AAC_ERR_DECODE;
        }
        for (int i = 0; i < num_boxes; i++) {
          cfg->residual_present[i] = (int)aac_bitreader_read(r, 1);
          if (cfg->residual_present[i]) {
            cfg->residual_bands[i] = (int)aac_bitreader_read(r, 5);
            if (cfg->residual_bands[i] > AAC_MPS_MAX_PARAM_BANDS) {
              return AAC_ERR_DECODE;
            }
          }
        }
        break;
      case 1: /* the residual of an arbitrary downmix */
      case 2: /* an arbitrary tree behind the standard one */
        return AAC_ERR_UNSUPPORTED;
      default:
        break;
    }
    const int used = r->pos - data_start;
    if (used > 8 * len) {
      return AAC_ERR_DECODE;
    }
    aac_bitreader_skip(r, 8 * len - used);
    bits_available -= 8 * len;
    if (cfg->num_extensions < AAC_MPS_MAX_EXTENSIONS) {
      cfg->extension_type[cfg->num_extensions++] = type;
    } else {
      return AAC_ERR_DECODE;
    }
  }
  return r->overread ? AAC_ERR_DECODE : AAC_OK;
}

int read_config(AacBitReader* r, AacMpsConfig* cfg, int low_delay) {
  const int anchor = r->pos;
  memset(cfg, 0, sizeof(*cfg));
  cfg->low_delay = low_delay;
  const int sfi = (int)aac_bitreader_read(r, 4);
  if (sfi == 15) {
    cfg->sampling_frequency = (int)aac_bitreader_read(r, 24);
  } else if (sfi < 13) {
    cfg->sampling_frequency = kSampleRates[sfi];
  } else {
    return AAC_ERR_DECODE;
  }
  cfg->frame_length = (int)aac_bitreader_read(r, low_delay ? 5 : 7) + 1;
  cfg->freq_res = (int)aac_bitreader_read(r, 3);
  if (cfg->freq_res == 0) {
    return AAC_ERR_DECODE;
  }
  cfg->tree_config = (int)aac_bitreader_read(r, 4);
  if (cfg->tree_config > 7) {
    return AAC_ERR_DECODE;
  }
  if (!tree_supported(cfg->tree_config)) {
    return AAC_ERR_UNSUPPORTED;
  }
  const Tree& tree = kTrees[cfg->tree_config];
  cfg->num_ott_boxes = tree.num_ott;
  cfg->num_ttt_boxes = tree.num_ttt;
  cfg->num_input_channels = tree.num_in;
  cfg->num_output_channels = tree.num_out;
  cfg->ld_num_output_channels = tree.num_out;
  const bool full = !(low_delay && cfg->tree_config == AAC_MPS_TREE_212);
  cfg->quant_mode = (int)aac_bitreader_read(r, 2);
  if (full) {
    cfg->one_icc = (int)aac_bitreader_read(r, 1);
  }
  cfg->arbitrary_downmix = (int)aac_bitreader_read(r, 1);
  if (full) {
    cfg->fixed_gain_sur = (int)aac_bitreader_read(r, 3);
    cfg->fixed_gain_lfe = (int)aac_bitreader_read(r, 3);
  }
  cfg->fixed_gain_dmx = (int)aac_bitreader_read(r, 3);
  if (full) {
    cfg->matrix_mode = (int)aac_bitreader_read(r, 1);
  }
  cfg->temp_shape_config = (int)aac_bitreader_read(r, 2);
  cfg->decorr_config = (int)aac_bitreader_read(r, 2);
  if (full) {
    cfg->audio_3d_mode = (int)aac_bitreader_read(r, 1);
  }
  const int bands = low_delay ? kLdFreqResBands[cfg->freq_res] : aac_mps_param_bands(cfg->freq_res);
  for (int i = 0; i < cfg->num_ott_boxes; i++) {
    cfg->ott_lfe[i] = tree.lfe[i];
    if (tree.lfe[i]) {
      cfg->ott_bands[i] = (int)aac_bitreader_read(r, 5);
      if (cfg->ott_bands[i] > bands) {
        return AAC_ERR_DECODE;
      }
    } else {
      cfg->ott_bands[i] = bands;
    }
  }
  for (int i = 0; i < cfg->num_ttt_boxes; i++) {
    cfg->ttt_dual_mode[i] = (int)aac_bitreader_read(r, 1);
    cfg->ttt_mode_low[i] = (int)aac_bitreader_read(r, 3);
    cfg->ttt_bands_low[i] = bands;
    if (cfg->ttt_dual_mode[i]) {
      cfg->ttt_mode_high[i] = (int)aac_bitreader_read(r, 3);
      cfg->ttt_bands_low[i] = (int)aac_bitreader_read(r, 5);
      if (cfg->ttt_bands_low[i] > bands) {
        return AAC_ERR_DECODE;
      }
    }
    if (cfg->ttt_mode_low[i] > 5 || (cfg->ttt_dual_mode[i] && cfg->ttt_mode_high[i] > 5)) {
      return AAC_ERR_DECODE;
    }
  }
  if (cfg->temp_shape_config == 2) {
    cfg->env_quant_mode = (int)aac_bitreader_read(r, 1);
  }
  if (cfg->audio_3d_mode) {
    /* the HRTF parameter set is read past so that a stream carrying it
     * fails cleanly, then refused */
    const int hrtf_set = (int)aac_bitreader_read(r, 2);
    if (hrtf_set == 0) {
      const int hrtf_freq_res = (int)aac_bitreader_read(r, 3);
      const int asymmetric = (int)aac_bitreader_read(r, 1);
      constexpr int kHrtfBands[8] = {0, 28, 20, 14, 10, 7, 5, 4};
      constexpr int kHrtfPhaseBands[8] = {0, 13, 13, 8, 7, 4, 3, 3};
      const int hb = kHrtfBands[hrtf_freq_res];
      const int phase_bands = low_delay ? kHrtfPhaseBands[hrtf_freq_res] : hb;
      for (int hc = 0; hc < 5; hc++) {
        aac_bitreader_skip(r, 6 * hb);
        if (asymmetric) {
          aac_bitreader_skip(r, 6 * hb);
        }
        if (aac_bitreader_read(r, 1)) {
          aac_bitreader_skip(r, 6 * phase_bands);
        }
        if (low_delay && aac_bitreader_read(r, 1)) {
          aac_bitreader_skip(r, 3 * hb);
        }
      }
    }
    return r->overread ? AAC_ERR_DECODE : AAC_ERR_UNSUPPORTED;
  }
  if (r->overread) {
    return AAC_ERR_DECODE;
  }
  if (cfg->decorr_config == 3) {
    return AAC_ERR_DECODE; /* reserved */
  }
  if (cfg->temp_shape_config == 3) {
    return AAC_ERR_UNSUPPORTED; /* the transient steering of USAC, not an AAC tool */
  }
  const int align = (8 - (r->pos - anchor) % 8) % 8;
  aac_bitreader_skip(r, align);
  return read_extension_config(r, cfg, aac_bitreader_bits_left(r));
}

}  // namespace

int aac_mps_param_bands(int freq_res) {
  return freq_res >= 0 && freq_res < 8 ? aac_mps212_freq_res_bands[freq_res] : 0;
}

int aac_mps_param_bands_ld(int freq_res) {
  return freq_res >= 0 && freq_res < 8 ? kLdFreqResBands[freq_res] : 0;
}

int aac_mps_tree_properties(int tree_config, int* num_ott, int* num_ttt, int* num_in, int* num_out,
                            int* ott_lfe) {
  if (tree_config < 0 || tree_config > 7 || !tree_supported(tree_config)) {
    return AAC_ERR_UNSUPPORTED;
  }
  const Tree& t = kTrees[tree_config];
  if (num_ott) *num_ott = t.num_ott;
  if (num_ttt) *num_ttt = t.num_ttt;
  if (num_in) *num_in = t.num_in;
  if (num_out) *num_out = t.num_out;
  if (ott_lfe) memcpy(ott_lfe, t.lfe, sizeof(t.lfe));
  return AAC_OK;
}

int aac_mps_config_read(AacBitReader* r, AacMpsConfig* cfg) {
  if (r == NULL || cfg == NULL) {
    return AAC_ERR_INVALID_ARG;
  }
  return read_config(r, cfg, 0);
}

int aac_mps_config_read_ld(AacBitReader* r, AacMpsConfig* cfg) {
  if (r == NULL || cfg == NULL) {
    return AAC_ERR_INVALID_ARG;
  }
  return read_config(r, cfg, 1);
}

int aac_mps_config_write_ld(const AacMpsConfig* cfg, AacBitWriter* w) {
  if (cfg == NULL || w == NULL || !tree_supported(cfg->tree_config) || cfg->audio_3d_mode ||
      cfg->frame_length < 1 || cfg->frame_length > 32 || cfg->freq_res < 1 || cfg->freq_res > 7) {
    return AAC_ERR_INVALID_ARG;
  }
  const int start = aac_bitwriter_bits_written(w);
  int sfi = -1;
  for (int i = 0; i < 13; i++) {
    if (kSampleRates[i] == cfg->sampling_frequency) {
      sfi = i;
    }
  }
  aac_bitwriter_write(w, sfi < 0 ? 15u : (uint32_t)sfi, 4);
  if (sfi < 0) {
    aac_bitwriter_write(w, (uint32_t)cfg->sampling_frequency, 24);
  }
  aac_bitwriter_write(w, (uint32_t)(cfg->frame_length - 1), 5);
  aac_bitwriter_write(w, (uint32_t)cfg->freq_res, 3);
  aac_bitwriter_write(w, (uint32_t)cfg->tree_config, 4);
  const Tree& tree = kTrees[cfg->tree_config];
  const bool full = cfg->tree_config != AAC_MPS_TREE_212;
  aac_bitwriter_write(w, (uint32_t)cfg->quant_mode, 2);
  if (full) {
    aac_bitwriter_write(w, cfg->one_icc ? 1u : 0u, 1);
  }
  aac_bitwriter_write(w, cfg->arbitrary_downmix ? 1u : 0u, 1);
  if (full) {
    aac_bitwriter_write(w, (uint32_t)cfg->fixed_gain_sur, 3);
    aac_bitwriter_write(w, (uint32_t)cfg->fixed_gain_lfe, 3);
  }
  aac_bitwriter_write(w, (uint32_t)cfg->fixed_gain_dmx, 3);
  if (full) {
    aac_bitwriter_write(w, cfg->matrix_mode ? 1u : 0u, 1);
  }
  aac_bitwriter_write(w, (uint32_t)cfg->temp_shape_config, 2);
  aac_bitwriter_write(w, (uint32_t)cfg->decorr_config, 2);
  if (full) {
    aac_bitwriter_write(w, 0, 1); /* bs3DaudioMode */
  }
  for (int i = 0; i < tree.num_ott; i++) {
    if (tree.lfe[i]) {
      aac_bitwriter_write(w, (uint32_t)cfg->ott_bands[i], 5);
    }
  }
  for (int i = 0; i < tree.num_ttt; i++) {
    aac_bitwriter_write(w, cfg->ttt_dual_mode[i] ? 1u : 0u, 1);
    aac_bitwriter_write(w, (uint32_t)cfg->ttt_mode_low[i], 3);
    if (cfg->ttt_dual_mode[i]) {
      aac_bitwriter_write(w, (uint32_t)cfg->ttt_mode_high[i], 3);
      aac_bitwriter_write(w, (uint32_t)cfg->ttt_bands_low[i], 5);
    }
  }
  if (cfg->temp_shape_config == 2) {
    aac_bitwriter_write(w, cfg->env_quant_mode ? 1u : 0u, 1);
  }
  const int pad = (8 - (aac_bitwriter_bits_written(w) - start) % 8) % 8;
  aac_bitwriter_write(w, 0, pad);
  if (cfg->residual_coding) {
    /* ResidualConfig: its length in bytes covers the fields and their
     * padding */
    const int boxes = tree.num_ott + tree.num_ttt;
    int bits = 6;
    for (int i = 0; i < boxes; i++) {
      bits += cfg->residual_present[i] ? 6 : 1;
    }
    const int len = (bits + 7) / 8;
    aac_bitwriter_write(w, 0, 4); /* bsSacExtType: residual coding */
    aac_bitwriter_write(w, (uint32_t)len, 4);
    aac_bitwriter_write(w, (uint32_t)cfg->residual_sample_rate_index, 4);
    aac_bitwriter_write(w, (uint32_t)(cfg->residual_frames_per_spatial_frame - 1), 2);
    for (int i = 0; i < boxes; i++) {
      aac_bitwriter_write(w, cfg->residual_present[i] ? 1u : 0u, 1);
      if (cfg->residual_present[i]) {
        aac_bitwriter_write(w, (uint32_t)cfg->residual_bands[i], 5);
      }
    }
    aac_bitwriter_write(w, 0, 8 * len - bits);
  }
  if (aac_bitwriter_overflow(w)) {
    return AAC_ERR_OVERFLOW;
  }
  return (aac_bitwriter_bits_written(w) - start) / 8;
}
