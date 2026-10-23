#include "drc.h"

#include <algorithm>
#include <cmath>
#include <cstring>

#include "aac_tables.h"

/* MPEG-4 dynamic range control, written from ISO/IEC 14496-3 4.5.2.7
 * (dynamic_range_info and excluded_channels syntax and semantics) and
 * ETSI TS 101 154 (the MPEG-4 audio ancillary data of a data stream
 * element with the heavy compression gain).
 *
 * The decoder keeps the DRC info of each frame per layout element (the
 * fill element follows the channel element it describes) or for the whole
 * program when it comes before any channel element.  A gain is applied
 * per spectral band of the core: on the MDCT coefficients before the
 * inverse transform, where the overlap of consecutive windows blends one
 * frame's gain into the next; with SBR on the 64 QMF bands after the high
 * band is generated, so that the replicated band is scaled as well, with
 * the gains interpolated across the frame's slots from the previous
 * frame's. */

namespace {

constexpr int kFilBits = 3 + 4; /* element id and count of a fill element */
constexpr int kMaxCtl = 127;

float db_to_linear(float db) { return powf(10.0f, db / 20.0f); }

/* The number of lines of a band of a frame: tops are in units of four
 * lines of the 1024-line spectrum and a 960-line frame clamps them. */
int band_end(const AacDrcInfo* info, int band, int frame_length) {
  if (!info->bands_present) {
    return frame_length;
  }
  return std::min(frame_length, (info->band_top[band] + 1) * 4);
}

/* The DRC info that applies to element `elem`: its own, else the program's. */
const AacDrcInfo* info_for(const AacDrcDecoder* d, int elem) {
  if (elem >= 0 && elem < AAC_MAX_ELEMENTS && d->info[elem].present) {
    return &d->info[elem];
  }
  return d->global.present ? &d->global : nullptr;
}

/* The program reference level of the frame: the first one signalled. */
int frame_prog_ref_level(const AacDrcDecoder* d) {
  if (d->global.present && d->global.prog_ref_level >= 0) {
    return d->global.prog_ref_level;
  }
  for (int i = 0; i < AAC_MAX_ELEMENTS; i++) {
    if (d->info[i].present && d->info[i].prog_ref_level >= 0) {
      return d->info[i].prog_ref_level;
    }
  }
  return -1;
}

bool excluded(const AacDrcInfo* info, int channel) {
  return info->excluded_present && channel >= 0 && channel < AAC_DRC_MAX_EXCLUDED &&
         ((info->exclude_mask >> channel) & 1u);
}

/* The gains of a frame for one channel per DRC band, plus the broadband
 * reference-level gain.  Returns the number of bands (0 when nothing
 * applies, so the caller can skip the channel). */
int channel_band_gains(const AacDrcDecoder* d, int elem, int channel, float* band_gain,
                       float* broadband) {
  const AacDrcSettings* st = &d->settings;
  *broadband = 1.0f;
  if (!st->enabled) {
    return 0;
  }
  const AacDrcInfo* info = info_for(d, elem);
  if (info && excluded(info, channel)) {
    return 0;
  }
  const int prl = frame_prog_ref_level(d);
  *broadband = aac_drc_reference_gain(prl, st->target_ref_level);
  const bool heavy = st->heavy && d->ancillary.present && d->ancillary.compression_on;
  if (heavy) {
    band_gain[0] = aac_dvb_compression_gain(d->ancillary.compression_value);
    return 1;
  }
  if (!info) {
    return *broadband != 1.0f ? -1 : 0;
  }
  for (int b = 0; b < info->num_bands; b++) {
    band_gain[b] = aac_drc_band_gain(info->sign[b], info->ctl[b], st->boost, st->cut);
  }
  return info->num_bands;
}

}  // namespace

/* ── bitstream ────────────────────────────────────────────────────── */

int aac_drc_parse(AacDrcInfo* d, AacBitReader* r) {
  memset(d, 0, sizeof(*d));
  d->prog_ref_level = -1;
  d->num_bands = 1;
  int n = 1;
  d->pce_tag_present = (int)aac_bitreader_read(r, 1);
  if (d->pce_tag_present) {
    d->pce_instance_tag = (int)aac_bitreader_read(r, 4);
    aac_bitreader_skip(r, 4); /* drc_tag_reserved_bits */
    n++;
  }
  d->excluded_present = (int)aac_bitreader_read(r, 1);
  if (d->excluded_present) {
    /* excluded_channels(): seven flags and an "additional" bit per byte. */
    int more = 1;
    while (more) {
      for (int i = 0; i < 7; i++) {
        const uint32_t bit = aac_bitreader_read(r, 1);
        if (d->num_excluded < AAC_DRC_MAX_EXCLUDED) {
          d->exclude_mask |= bit << d->num_excluded;
        }
        d->num_excluded++;
      }
      more = (int)aac_bitreader_read(r, 1);
      n++;
      if (r->overread) {
        return AAC_ERR_DECODE;
      }
    }
  }
  d->bands_present = (int)aac_bitreader_read(r, 1);
  if (d->bands_present) {
    d->num_bands = 1 + (int)aac_bitreader_read(r, 4);
    d->interpolation_scheme = (int)aac_bitreader_read(r, 4);
    n++;
    for (int i = 0; i < d->num_bands; i++) {
      d->band_top[i] = (int)aac_bitreader_read(r, 8);
      n++;
    }
  }
  if (aac_bitreader_read(r, 1)) {
    d->prog_ref_level = (int)aac_bitreader_read(r, 7);
    aac_bitreader_skip(r, 1); /* prog_ref_level_reserved_bits */
    n++;
  }
  for (int i = 0; i < d->num_bands; i++) {
    d->sign[i] = (int)aac_bitreader_read(r, 1);
    d->ctl[i] = (int)aac_bitreader_read(r, 7);
    n++;
  }
  if (r->overread) {
    return AAC_ERR_DECODE;
  }
  d->present = 1;
  return n;
}

int aac_drc_bytes(const AacDrcInfo* d) {
  int n = 1 + d->num_bands;
  if (d->pce_tag_present) {
    n++;
  }
  if (d->excluded_present) {
    n += std::max(1, (d->num_excluded + 6) / 7);
  }
  if (d->bands_present) {
    n += 1 + d->num_bands;
  }
  if (d->prog_ref_level >= 0) {
    n++;
  }
  return n;
}

int aac_drc_write(const AacDrcInfo* d, AacBitWriter* w) {
  aac_bitwriter_write(w, AAC_EXT_DYNAMIC_RANGE, 4);
  aac_bitwriter_write(w, d->pce_tag_present ? 1 : 0, 1);
  if (d->pce_tag_present) {
    aac_bitwriter_write(w, (uint32_t)d->pce_instance_tag, 4);
    aac_bitwriter_write(w, 0, 4);
  }
  aac_bitwriter_write(w, d->excluded_present ? 1 : 0, 1);
  if (d->excluded_present) {
    const int bytes = std::max(1, (d->num_excluded + 6) / 7);
    for (int k = 0; k < bytes; k++) {
      for (int i = 0; i < 7; i++) {
        const int c = 7 * k + i;
        aac_bitwriter_write(w, c < AAC_DRC_MAX_EXCLUDED ? (d->exclude_mask >> c) & 1u : 0, 1);
      }
      aac_bitwriter_write(w, k + 1 < bytes ? 1 : 0, 1);
    }
  }
  aac_bitwriter_write(w, d->bands_present ? 1 : 0, 1);
  if (d->bands_present) {
    aac_bitwriter_write(w, (uint32_t)(d->num_bands - 1), 4);
    aac_bitwriter_write(w, (uint32_t)d->interpolation_scheme, 4);
    for (int i = 0; i < d->num_bands; i++) {
      aac_bitwriter_write(w, (uint32_t)d->band_top[i], 8);
    }
  }
  aac_bitwriter_write(w, d->prog_ref_level >= 0 ? 1 : 0, 1);
  if (d->prog_ref_level >= 0) {
    aac_bitwriter_write(w, (uint32_t)d->prog_ref_level, 7);
    aac_bitwriter_write(w, 0, 1);
  }
  for (int i = 0; i < d->num_bands; i++) {
    aac_bitwriter_write(w, d->sign[i] ? 1 : 0, 1);
    aac_bitwriter_write(w, (uint32_t)d->ctl[i], 7);
  }
  return aac_drc_bytes(d);
}

/* The DVB ancillary data of a data stream element (ETSI TS 101 154,
 * MPEG-4 audio ancillary data): a sync byte, bs_info (mpeg_audio_type,
 * dolby_surround_mode, drc_presentation_mode, two reserved bits), the
 * ancillary_data_status byte whose flags announce the optional parts
 * (downmixing levels, audio coding mode and compression, coarse and fine
 * grain timecodes), then those parts in that order.  The compression
 * part is a byte of audio coding mode and reserved bits ending in
 * compression_on, followed by the compression_value byte. */
int aac_dvb_ancillary_parse(AacDvbAncillary* a, const uint8_t* data, int size) {
  memset(a, 0, sizeof(*a));
  if (size < 3 || data[0] != AAC_DVB_ANCILLARY_SYNC) {
    return 0;
  }
  AacBitReader r;
  aac_bitreader_init(&r, data, size);
  aac_bitreader_skip(&r, 8);
  a->mpeg_audio_type = (int)aac_bitreader_read(&r, 2);
  a->dolby_surround_mode = (int)aac_bitreader_read(&r, 2);
  a->drc_presentation_mode = (int)aac_bitreader_read(&r, 2);
  aac_bitreader_skip(&r, 2);
  aac_bitreader_skip(&r, 3); /* reserved */
  const int downmix_present = (int)aac_bitreader_read(&r, 1);
  aac_bitreader_skip(&r, 1); /* ancillary data extension status */
  const int compression_present = (int)aac_bitreader_read(&r, 1);
  aac_bitreader_skip(&r, 2); /* coarse and fine grain timecode status */
  if (downmix_present) {
    aac_bitreader_skip(&r, 8); /* downmixing_levels_MPEG4 */
  }
  if (compression_present) {
    aac_bitreader_skip(&r, 7); /* reserved and audio_coding_mode */
    a->compression_on = (int)aac_bitreader_read(&r, 1);
    a->compression_value = (int)aac_bitreader_read(&r, 8);
  }
  if (r.overread) {
    return 0;
  }
  a->present = 1;
  return 1;
}

int aac_dvb_ancillary_write(const AacDvbAncillary* a, uint8_t* out, int out_size) {
  if (out_size < 5) {
    return 0;
  }
  AacBitWriter w;
  aac_bitwriter_init(&w, out, out_size);
  aac_bitwriter_write(&w, AAC_DVB_ANCILLARY_SYNC, 8);
  aac_bitwriter_write(&w, (uint32_t)a->mpeg_audio_type, 2);
  aac_bitwriter_write(&w, (uint32_t)a->dolby_surround_mode, 2);
  aac_bitwriter_write(&w, (uint32_t)a->drc_presentation_mode, 2);
  aac_bitwriter_write(&w, 0, 2);
  aac_bitwriter_write(&w, 0, 3);
  aac_bitwriter_write(&w, 0, 1); /* no downmixing levels */
  aac_bitwriter_write(&w, 0, 1); /* no extension */
  aac_bitwriter_write(&w, 1, 1); /* audio coding mode and compression */
  aac_bitwriter_write(&w, 0, 2); /* no timecodes */
  aac_bitwriter_write(&w, 0, 7);
  aac_bitwriter_write(&w, a->compression_on ? 1 : 0, 1);
  aac_bitwriter_write(&w, (uint32_t)a->compression_value, 8);
  return aac_bitwriter_bytes_written(&w);
}

/* ── gains ────────────────────────────────────────────────────────── */

float aac_drc_band_gain(int sign, int ctl, int boost, int cut) {
  const float factor = (float)(sign ? cut : boost) / 100.0f;
  const float exponent = (float)ctl * factor / 24.0f;
  return exp2f(sign ? -exponent : exponent);
}

float aac_drc_reference_gain(int prog_ref_level, int target_ref_level) {
  if (prog_ref_level < 0 || target_ref_level < 0) {
    return 1.0f;
  }
  /* Both levels are in quarter dB below full scale: a program further
   * below full scale than the target is raised by the difference. */
  return db_to_linear((float)(prog_ref_level - target_ref_level) / 4.0f);
}

float aac_dvb_compression_gain(int compression_value) {
  /* The compr word: a two's complement four-bit exponent in the upper
   * nibble and a four-bit mantissa below it, 2^X (1 + Y / 16), which
   * spans -48.2 to +47.9 dB. */
  const int x = ((compression_value >> 4) & 15) - (compression_value & 0x80 ? 16 : 0);
  const int y = compression_value & 15;
  return ldexpf(1.0f + (float)y / 16.0f, x);
}

/* ── decoder ──────────────────────────────────────────────────────── */

void aac_drc_decoder_init(AacDrcDecoder* d) {
  memset(d, 0, sizeof(*d));
  d->settings.boost = 100;
  d->settings.cut = 100;
  d->settings.target_ref_level = -1;
  d->prog_ref_level = -1;
  aac_drc_frame_begin(d);
}

void aac_drc_frame_begin(AacDrcDecoder* d) {
  for (int i = 0; i < AAC_MAX_ELEMENTS; i++) {
    d->info[i].present = 0;
  }
  d->global.present = 0;
  d->ancillary.present = 0;
  d->has_drc = 0;
  d->prog_ref_level = -1;
}

int aac_drc_decode_fill(AacDrcDecoder* d, AacBitReader* r, int elem, int count) {
  const int start = r->pos;
  aac_bitreader_skip(r, 4); /* extension_type */
  AacDrcInfo* info = elem >= 0 && elem < AAC_MAX_ELEMENTS ? &d->info[elem] : &d->global;
  const int n = aac_drc_parse(info, r);
  if (n < 0 || n > count) {
    info->present = 0;
    return AAC_ERR_DECODE;
  }
  /* The rest of the payload is further extension payloads or fill. */
  aac_bitreader_skip(r, start + 8 * count - r->pos);
  return r->overread ? AAC_ERR_DECODE : 0;
}

int aac_drc_decode_dse(AacDrcDecoder* d, AacBitReader* r) {
  aac_bitreader_skip(r, 4); /* element_instance_tag */
  const int align = (int)aac_bitreader_read(r, 1);
  int count = (int)aac_bitreader_read(r, 8);
  if (count == 255) {
    count += (int)aac_bitreader_read(r, 8);
  }
  if (align) {
    aac_bitreader_byte_align(r);
  }
  if (r->overread || aac_bitreader_bits_left(r) < 8 * count) {
    aac_bitreader_skip(r, 8 * count);
    return AAC_ERR_DECODE;
  }
  uint8_t bytes[510];
  for (int i = 0; i < count; i++) {
    bytes[i] = (uint8_t)aac_bitreader_read(r, 8);
  }
  if (count > 0 && bytes[0] == AAC_DVB_ANCILLARY_SYNC) {
    AacDvbAncillary a;
    if (aac_dvb_ancillary_parse(&a, bytes, count)) {
      d->ancillary = a;
    }
  }
  return 0;
}

void aac_drc_frame_end(AacDrcDecoder* d) {
  d->prog_ref_level = frame_prog_ref_level(d);
  d->has_drc = d->global.present || (d->ancillary.present && d->ancillary.compression_on);
  for (int i = 0; i < AAC_MAX_ELEMENTS && !d->has_drc; i++) {
    d->has_drc = d->info[i].present;
  }
}

int aac_drc_line_gains(const AacDrcDecoder* d, int elem, int channel, float* gain,
                       int frame_length) {
  float band_gain[AAC_DRC_MAX_BANDS];
  float broadband;
  const int bands = channel_band_gains(d, elem, channel, band_gain, &broadband);
  if (bands == 0) {
    return 0;
  }
  if (bands < 0) {
    /* Reference-level normalisation alone. */
    for (int i = 0; i < frame_length; i++) {
      gain[i] = broadband;
    }
    return 1;
  }
  const AacDrcInfo* info = info_for(d, elem);
  int from = 0;
  for (int b = 0; b < bands; b++) {
    const int to = (bands == 1 || !info) ? frame_length : band_end(info, b, frame_length);
    for (int i = from; i < to; i++) {
      gain[i] = band_gain[b] * broadband;
    }
    from = std::max(from, to);
  }
  /* Lines above the last band top keep the last band's gain. */
  for (int i = from; i < frame_length; i++) {
    gain[i] = band_gain[bands - 1] * broadband;
  }
  return 1;
}

void aac_drc_apply_spectrum(const AacDrcDecoder* d, int elem, int channel, float* coef,
                            int num_windows, int frame_length) {
  float gain[AAC_FRAME_SIZE_LONG];
  if (!aac_drc_line_gains(d, elem, channel, gain, frame_length)) {
    return;
  }
  if (num_windows == 1) {
    for (int i = 0; i < frame_length; i++) {
      coef[i] *= gain[i];
    }
    return;
  }
  /* Short windows: line k of a window sits at the frequency of line 8 k
   * of the long spectrum. */
  const int short_length = frame_length / 8;
  for (int w = 0; w < 8; w++) {
    float* win = coef + w * AAC_FRAME_SIZE_SHORT;
    for (int k = 0; k < short_length; k++) {
      win[k] *= gain[8 * k];
    }
  }
}

void aac_drc_set_qmf_gains(const AacDrcDecoder* d, int elem, int first_channel, int nch,
                           AacDrcQmf* q, int frame_length) {
  const AacDrcInfo* info = info_for(d, elem);
  q->interpolate = !info || info->interpolation_scheme == 0;
  q->active = 0;
  for (int c = 0; c < nch && c < 2; c++) {
    float gain[AAC_FRAME_SIZE_LONG];
    if (!aac_drc_line_gains(d, elem, first_channel + c, gain, frame_length)) {
      for (int k = 0; k < 64; k++) {
        q->gain[c][k] = 1.0f;
      }
      continue;
    }
    /* The core's lines cover the lower 32 QMF bands of the SBR rate; the
     * replicated upper bands take the gain of the top of the core. */
    const int lines_per_band = frame_length / 32;
    for (int k = 0; k < 32; k++) {
      q->gain[c][k] = gain[k * lines_per_band];
    }
    for (int k = 32; k < 64; k++) {
      q->gain[c][k] = gain[frame_length - 1];
    }
    q->active = 1;
  }
}

void aac_drc_apply_qmf(AacDrcQmf* q, int channel, float (*X)[38][64], int slots) {
  float* prev = q->prev[channel];
  const float* cur = q->gain[channel];
  bool unity = true;
  for (int k = 0; k < 64 && unity; k++) {
    unity = prev[k] == 1.0f && (!q->active || cur[k] == 1.0f);
  }
  if (!unity) {
    for (int i = 0; i < slots; i++) {
      const float w = q->interpolate ? (float)(i + 1) / (float)slots : 1.0f;
      for (int k = 0; k < 64; k++) {
        const float g1 = q->active ? cur[k] : 1.0f;
        const float g = prev[k] + (g1 - prev[k]) * w;
        X[0][i][k] *= g;
        X[1][i][k] *= g;
      }
    }
  }
  for (int k = 0; k < 64; k++) {
    prev[k] = q->active ? cur[k] : 1.0f;
  }
}

void aac_drc_qmf_init(AacDrcQmf* q) {
  memset(q, 0, sizeof(*q));
  q->interpolate = 1;
  for (int c = 0; c < 2; c++) {
    for (int k = 0; k < 64; k++) {
      q->prev[c][k] = 1.0f;
      q->gain[c][k] = 1.0f;
    }
  }
}

/* ── encoder ──────────────────────────────────────────────────────── */

/* The compression curves, as gain in dB against the level of the frame
 * relative to the program reference level (0 dB is a frame as loud as the
 * reference).  Each is a piecewise-linear table in the manner of the
 * broadcast DRC profiles: a boost region below a null band around the
 * reference, where quiet passages are raised at a 2:1 ratio up to the
 * profile's maximum boost, the null band itself, a cut region above it at
 * 2:1 down to -10 dB, and a steeper 20:1 region above that for peaks,
 * capped at -24 dB.  The profiles differ in how wide the null band is
 * and how much boost they allow: the film profiles keep dialogue at the
 * reference with a narrow null band (the light variant widens it so
 * that most of the film passes untouched), the music profiles widen the
 * null band and the light variant doubles the boost for quiet music, and
 * the speech profile boosts hardest so that quiet speech stays
 * intelligible.  The breakpoints are {level relative to reference,
 * gain} in dB; between points the gain is interpolated linearly and
 * beyond the ends it is held. */
namespace {

struct CurvePoint {
  float level, gain;
};

const CurvePoint kFilmStandard[] = {{-17.0f, 6.0f}, {-5.0f, 0.0f}, {5.0f, 0.0f},
                                    {25.0f, -10.0f}, {39.0f, -24.0f}};
const CurvePoint kFilmLight[] = {{-32.0f, 6.0f}, {-20.0f, 0.0f}, {10.0f, 0.0f},
                                 {30.0f, -10.0f}, {44.0f, -24.0f}};
const CurvePoint kMusicStandard[] = {{-25.0f, 6.0f}, {-13.0f, 0.0f}, {5.0f, 0.0f},
                                     {25.0f, -10.0f}, {39.0f, -24.0f}};
const CurvePoint kMusicLight[] = {{-48.0f, 12.0f}, {-24.0f, 0.0f}, {10.0f, 0.0f},
                                  {30.0f, -10.0f}, {44.0f, -24.0f}};
const CurvePoint kSpeech[] = {{-41.0f, 15.0f}, {-11.0f, 0.0f}, {5.0f, 0.0f},
                              {25.0f, -10.0f}, {39.0f, -24.0f}};

/* The level estimate falls by at most this much per frame, so that a cut
 * releases gradually after a loud passage instead of pumping. */
constexpr float kReleaseDbPerFrame = 2.0f;
constexpr float kSilenceDb = -120.0f;

float curve_gain(const CurvePoint* p, int n, float level) {
  if (level <= p[0].level) {
    return p[0].gain;
  }
  for (int i = 1; i < n; i++) {
    if (level <= p[i].level) {
      const float t = (level - p[i - 1].level) / (p[i].level - p[i - 1].level);
      return p[i - 1].gain + t * (p[i].gain - p[i - 1].gain);
    }
  }
  return p[n - 1].gain;
}

}  // namespace

float aac_drc_profile_gain_db(AacDrcProfile profile, float rel_db) {
  switch (profile) {
    case AAC_DRC_FILM_STANDARD:
      return curve_gain(kFilmStandard, 5, rel_db);
    case AAC_DRC_FILM_LIGHT:
      return curve_gain(kFilmLight, 5, rel_db);
    case AAC_DRC_MUSIC_STANDARD:
      return curve_gain(kMusicStandard, 5, rel_db);
    case AAC_DRC_MUSIC_LIGHT:
      return curve_gain(kMusicLight, 5, rel_db);
    case AAC_DRC_SPEECH:
      return curve_gain(kSpeech, 5, rel_db);
    case AAC_DRC_NONE:
    default:
      return 0.0f;
  }
}

void aac_drc_encoder_init(AacDrcEncoder* e) {
  memset(e, 0, sizeof(*e));
  e->profile = AAC_DRC_NONE;
  aac_drc_encoder_reset(e);
}

void aac_drc_encoder_reset(AacDrcEncoder* e) {
  for (int i = 0; i < AAC_MAX_ELEMENTS; i++) {
    e->level_db[i] = kSilenceDb;
    e->sign[i] = 0;
    e->ctl[i] = 0;
  }
}

void aac_drc_encoder_analyse(AacDrcEncoder* e, int elem, const float* a, const float* b, int n,
                             float scale) {
  if (e->profile == AAC_DRC_NONE || elem < 0 || elem >= AAC_MAX_ELEMENTS || n <= 0) {
    return;
  }
  double energy = 0.0;
  int samples = 0;
  for (const float* pcm : {a, b}) {
    if (!pcm) {
      continue;
    }
    for (int i = 0; i < n; i++) {
      energy += (double)pcm[i] * pcm[i];
    }
    samples += n;
  }
  const double rms = sqrt(energy / samples) / scale;
  const float instant = rms > 0.0 ? (float)(20.0 * log10(rms)) : kSilenceDb;
  const float held = e->level_db[elem] - kReleaseDbPerFrame;
  e->level_db[elem] = std::max(instant, std::max(held, kSilenceDb));
  const float reference_db = -(float)e->prog_ref_level / 4.0f;
  const float gain_db = aac_drc_profile_gain_db(e->profile, e->level_db[elem] - reference_db);
  const int ctl = (int)lrintf(fabsf(gain_db) * 4.0f);
  e->ctl[elem] = std::min(ctl, kMaxCtl);
  e->sign[elem] = gain_db < 0.0f && e->ctl[elem] > 0 ? 1 : 0;
}

int aac_drc_encoder_bits(const AacDrcEncoder* e) {
  if (e->profile == AAC_DRC_NONE) {
    return 0;
  }
  /* One flag byte, the reference level and one band. */
  return kFilBits + 8 * 3;
}

void aac_drc_encoder_write(const AacDrcEncoder* e, int elem, AacBitWriter* w) {
  if (e->profile == AAC_DRC_NONE || elem < 0 || elem >= AAC_MAX_ELEMENTS) {
    return;
  }
  AacDrcInfo info = {};
  info.num_bands = 1;
  info.prog_ref_level = e->prog_ref_level;
  info.sign[0] = e->sign[elem];
  info.ctl[0] = e->ctl[elem];
  aac_bitwriter_write(w, AAC_ELEM_FIL, 3);
  aac_bitwriter_write(w, (uint32_t)aac_drc_bytes(&info), 4);
  aac_drc_write(&info, w);
}
