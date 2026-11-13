/* Dynamic range control: the dynamic_range_info parser on hand-built bit
 * streams, the gain formulas against hand-computed values, the decoder's
 * application of encoder-written metadata (band gain, boost and cut
 * scaling, reference-level normalisation), excluded channels and several
 * bands from a hand-built fill element, DVB heavy compression from a
 * hand-built data stream element, SBR streams, and FFmpeg's reading of
 * our metadata stream. */
#include "decoder.h"
#include "drc.h"
#include "test_util.h"

static const int kRate = 48000;

/* ── bit stream helpers ────────────────────────────────────────── */

struct Bits {
  std::vector<uint8_t> buf = std::vector<uint8_t>(1024);
  AacBitWriter w;
  Bits() { aac_bitwriter_init(&w, buf.data(), (int)buf.size()); }
  void put(uint32_t v, int n) { aac_bitwriter_write(&w, v, n); }
  int bits() const { return aac_bitwriter_bits_written(&w); }
};

/* Decibels of a linear gain. */
static double gain_db(double g) { return 20.0 * log10(g); }

/* RMS in dB of one channel over the frames [from, to) of a decoded
 * stream of `channels` channels and `frame` samples per frame. */
static double rms_db(const std::vector<float>& pcm, int channels, int channel, int frame, int from,
                     int to) {
  double e = 0.0;
  size_t n = 0;
  for (size_t i = (size_t)from * frame; i < (size_t)to * frame && i < pcm.size() / channels; i++) {
    const double v = pcm[i * channels + channel];
    e += v * v;
    n++;
  }
  return 10.0 * log10(e / (double)std::max<size_t>(n, 1) + 1e-30);
}

/* Encodes a signal with DRC metadata. */
static std::vector<uint8_t> encode_with_metadata(const std::vector<float>& pcm, int sr,
                                                 int channels, int bitrate, AacDrcProfile profile,
                                                 int prog_ref_level, AacObjectType aot = AAC_AOT_LC) {
  std::vector<uint8_t> out;
  AacEncoderHandle enc = aac_encoder_create(sr, channels, bitrate, aot, AAC_RC_CBR);
  if (!enc) {
    return out;
  }
  CHECK(aac_encoder_set_metadata(enc, profile, prog_ref_level) == AAC_OK, "set_metadata");
  const int frame = aac_encoder_frame_size(enc);
  std::vector<float> block((size_t)frame * channels);
  std::vector<uint8_t> buf(16384);
  for (size_t pos = 0; pos < pcm.size(); pos += block.size()) {
    std::fill(block.begin(), block.end(), 0.0f);
    const size_t n = std::min(block.size(), pcm.size() - pos);
    memcpy(block.data(), pcm.data() + pos, n * sizeof(float));
    const int len = aac_encoder_encode(enc, block.data(), frame, buf.data(), (int)buf.size());
    CHECK(len > 0, "encode frame (%d)", len);
    if (len > 0) {
      out.insert(out.end(), buf.begin(), buf.begin() + len);
    }
  }
  const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
  if (len > 0) {
    out.insert(out.end(), buf.begin(), buf.begin() + len);
  }
  aac_encoder_destroy(enc);
  return out;
}

/* Decodes a stream frame by frame, recording the DRC info the decoder
 * parsed for element 0 (or the program) in each frame. */
struct FrameDrc {
  int present, sign, ctl, prog_ref_level, has_drc;
};

static std::vector<float> decode_with(const std::vector<uint8_t>& stream, int channels,
                                      int enabled, int boost, int cut, int target, int heavy,
                                      std::vector<FrameDrc>* infos = nullptr, int* errors = nullptr) {
  std::vector<float> out;
  if (infos) {
    infos->clear();
  }
  AacDecoderHandle dec = aac_decoder_create(kRate, channels);
  CHECK(aac_decoder_set_drc(dec, enabled, boost, cut, target, heavy) == AAC_OK, "set_drc");
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  int bad = 0;
  for (const AdtsFrame& f : split_adts(stream)) {
    const int n =
        aac_decoder_decode(dec, stream.data() + f.offset, (int)f.size, pcm.data(), (int)pcm.size());
    if (n < 0) {
      bad++;
      continue;
    }
    out.insert(out.end(), pcm.begin(), pcm.begin() + (size_t)n * aac_decoder_channels(dec));
    if (infos) {
      const AacDecoderState* s = static_cast<const AacDecoderState*>(dec);
      const AacDrcInfo* info = s->drc.info[0].present ? &s->drc.info[0] : &s->drc.global;
      infos->push_back({info->present, info->sign[0], info->ctl[0],
                        aac_decoder_program_ref_level(dec), aac_decoder_has_drc(dec)});
    }
  }
  if (errors) {
    *errors = bad;
  }
  aac_decoder_destroy(dec);
  return out;
}

/* Rebuilds every ADTS frame of a stream with the bits of `element`
 * (a complete syntactic element with its id) inserted at the start of
 * the raw data block, so that it precedes every channel element. */
static std::vector<uint8_t> insert_element(const std::vector<uint8_t>& stream,
                                           const std::vector<uint8_t>& element, int element_bits) {
  std::vector<uint8_t> out;
  for (const AdtsFrame& f : split_adts(stream)) {
    const uint8_t* frame = stream.data() + f.offset;
    const int header = (frame[1] & 1) ? 7 : 9;
    std::vector<uint8_t> buf(f.size + element.size() + 16);
    AacBitWriter w;
    aac_bitwriter_init(&w, buf.data(), (int)buf.size());
    AacBitReader r;
    aac_bitreader_init(&r, element.data(), (int)element.size());
    for (int i = 0; i < element_bits; i++) {
      aac_bitwriter_write(&w, aac_bitreader_read(&r, 1), 1);
    }
    aac_bitreader_init(&r, frame + header, (int)f.size - header);
    for (int i = 0; i < 8 * ((int)f.size - header); i++) {
      aac_bitwriter_write(&w, aac_bitreader_read(&r, 1), 1);
    }
    aac_bitwriter_byte_align(&w);
    const int body = aac_bitwriter_bytes_written(&w);
    AacAdtsHeader hdr;
    CHECK(aac_adts_parse(&hdr, frame, (int)f.size) == 0, "adts parse");
    hdr.frame_length = header + body;
    uint8_t h[9];
    aac_adts_write(&hdr, h);
    out.insert(out.end(), h, h + header);
    out.insert(out.end(), buf.begin(), buf.begin() + body);
  }
  return out;
}

/* A fill element carrying `info`, with its id and count. */
static std::vector<uint8_t> fill_element(const AacDrcInfo& info, int* bits) {
  Bits b;
  b.put(AAC_ELEM_FIL, 3);
  b.put((uint32_t)aac_drc_bytes(&info), 4);
  aac_drc_write(&info, &b.w);
  *bits = b.bits();
  return b.buf;
}

/* A data stream element whose payload is DVB ancillary data with the
 * heavy compression word. */
static std::vector<uint8_t> dse_element(int compression_on, int compression_value, int* bits) {
  AacDvbAncillary a = {};
  a.compression_on = compression_on;
  a.compression_value = compression_value;
  uint8_t payload[16];
  const int n = aac_dvb_ancillary_write(&a, payload, sizeof(payload));
  Bits b;
  b.put(AAC_ELEM_DSE, 3);
  b.put(0, 4);  /* element_instance_tag */
  b.put(1, 1);  /* data_byte_align_flag */
  b.put((uint32_t)n, 8);
  aac_bitwriter_byte_align(&b.w);
  for (int i = 0; i < n; i++) {
    b.put(payload[i], 8);
  }
  *bits = b.bits();
  return b.buf;
}

/* ── parser ───────────────────────────────────────────────────── */

static void test_parse_minimal() {
  /* extension_type, four absent flags, one band: a cut of 34 steps. */
  Bits b;
  b.put(AAC_EXT_DYNAMIC_RANGE, 4);
  b.put(0, 1);
  b.put(0, 1);
  b.put(0, 1);
  b.put(0, 1);
  b.put(1, 1);
  b.put(34, 7);
  AacBitReader r;
  aac_bitreader_init(&r, b.buf.data(), 2);
  aac_bitreader_skip(&r, 4);
  AacDrcInfo d;
  const int n = aac_drc_parse(&d, &r);
  CHECK(n == 2, "minimal info is two bytes (%d)", n);
  CHECK(d.present && d.num_bands == 1 && d.sign[0] == 1 && d.ctl[0] == 34, "one band parsed");
  CHECK(d.prog_ref_level == -1 && !d.excluded_present && !d.bands_present && !d.pce_tag_present,
        "optional parts absent");
  CHECK(aac_drc_bytes(&d) == 2, "byte count of the parsed info");
}

static void test_parse_full() {
  Bits b;
  b.put(AAC_EXT_DYNAMIC_RANGE, 4);
  b.put(1, 1); /* pce_tag_present */
  b.put(5, 4); /* pce_instance_tag */
  b.put(0, 4); /* drc_tag_reserved_bits */
  b.put(1, 1); /* excluded_chns_present */
  /* excluded_channels(): channel 1 in the first byte, channel 9 in the
   * second (flag 2 of the next seven), no third byte. */
  b.put(0b0100000, 7);
  b.put(1, 1); /* additional_excluded_chns */
  b.put(0b0010000, 7);
  b.put(0, 1);
  b.put(1, 1); /* drc_bands_present */
  b.put(2, 4); /* drc_band_incr: three bands */
  b.put(0, 4); /* drc_interpolation_scheme */
  b.put(10, 8);
  b.put(50, 8);
  b.put(255, 8);
  b.put(1, 1); /* prog_ref_level_present */
  b.put(124, 7);
  b.put(0, 1);
  b.put(1, 1);
  b.put(48, 7);
  b.put(0, 1);
  b.put(12, 7);
  b.put(1, 1);
  b.put(127, 7);
  AacBitReader r;
  aac_bitreader_init(&r, b.buf.data(), 16);
  aac_bitreader_skip(&r, 4);
  AacDrcInfo d;
  const int n = aac_drc_parse(&d, &r);
  CHECK(n == 12, "full info is twelve bytes (%d)", n);
  CHECK(d.pce_tag_present && d.pce_instance_tag == 5, "pce tag");
  CHECK(d.excluded_present && d.num_excluded == 14 && d.exclude_mask == ((1u << 1) | (1u << 9)),
        "excluded channels 1 and 9 (mask %x, %d flags)", d.exclude_mask, d.num_excluded);
  CHECK(d.bands_present && d.num_bands == 3 && d.interpolation_scheme == 0, "three bands");
  CHECK(d.band_top[0] == 10 && d.band_top[1] == 50 && d.band_top[2] == 255, "band tops");
  CHECK(d.prog_ref_level == 124, "prog_ref_level");
  CHECK(d.sign[0] == 1 && d.ctl[0] == 48 && d.sign[1] == 0 && d.ctl[1] == 12 && d.sign[2] == 1 &&
            d.ctl[2] == 127,
        "band gains");
  CHECK(aac_drc_bytes(&d) == 12, "byte count of the full info");
  CHECK(r.pos == 8 * 12, "reader consumed exactly the info (%d bits)", r.pos);

  /* The writer reproduces the stream. */
  Bits w;
  const int written = aac_drc_write(&d, &w.w);
  CHECK(w.bits() == 8 * 12, "writer length (%d bits)", w.bits());
  CHECK(written == 8 * 12 && aac_drc_bytes(&d) == 12, "writer reports %d bits, %d bytes", written,
        aac_drc_bytes(&d));
  CHECK(memcmp(w.buf.data(), b.buf.data(), 12) == 0, "writer reproduces the hand-built bytes");

  /* A truncated info is refused. */
  aac_bitreader_init(&r, b.buf.data(), 6);
  aac_bitreader_skip(&r, 4);
  CHECK(aac_drc_parse(&d, &r) == AAC_ERR_DECODE, "truncated info refused");
}

static void test_dvb_ancillary() {
  AacDvbAncillary a = {};
  a.dolby_surround_mode = 2;
  a.drc_presentation_mode = 1;
  a.compression_on = 1;
  a.compression_value = 0xE3;
  uint8_t bytes[16];
  const int n = aac_dvb_ancillary_write(&a, bytes, sizeof(bytes));
  CHECK(n == 5, "ancillary data with compression is five bytes (%d)", n);
  CHECK(bytes[0] == AAC_DVB_ANCILLARY_SYNC, "sync byte");
  AacDvbAncillary p;
  CHECK(aac_dvb_ancillary_parse(&p, bytes, n) == 1, "parsed");
  CHECK(p.dolby_surround_mode == 2 && p.drc_presentation_mode == 1 && p.compression_on == 1 &&
            p.compression_value == 0xE3,
        "fields");
  bytes[0] = 0x5A;
  CHECK(aac_dvb_ancillary_parse(&p, bytes, n) == 0, "other payloads are not ancillary data");
  bytes[0] = AAC_DVB_ANCILLARY_SYNC;
  CHECK(aac_dvb_ancillary_parse(&p, bytes, 3) == 0, "truncated compression refused");
}

/* ── gains ────────────────────────────────────────────────────── */

static void test_gain_formulas() {
  /* One step of dyn_rng_ctl is 2^(1/24), a quarter dB. */
  CHECK(fabs(gain_db(aac_drc_band_gain(1, 34, 100, 100)) - (-34.0 * 6.0206 / 24.0)) < 0.01,
        "cut of 34 steps: %.3f dB", gain_db(aac_drc_band_gain(1, 34, 100, 100)));
  CHECK(fabs(gain_db(aac_drc_band_gain(0, 24, 100, 100)) - 6.0206) < 0.01,
        "boost of 24 steps is 6.02 dB");
  CHECK(fabs(gain_db(aac_drc_band_gain(1, 48, 100, 50)) - (-6.0206)) < 0.01,
        "cut at 50 percent halves the attenuation");
  CHECK(fabs(gain_db(aac_drc_band_gain(0, 48, 25, 100)) - 3.0103) < 0.01,
        "boost at 25 percent quarters the boost");
  CHECK(aac_drc_band_gain(1, 100, 100, 0) == 1.0f && aac_drc_band_gain(0, 100, 0, 100) == 1.0f,
        "zero percent leaves the signal alone");
  CHECK(fabs(gain_db(aac_drc_reference_gain(124, 100)) - 6.0) < 1e-4,
        "a -31 dB program raised to -25 dB");
  CHECK(fabs(gain_db(aac_drc_reference_gain(100, 124)) + 6.0) < 1e-4,
        "a -25 dB program lowered to -31 dB");
  CHECK(aac_drc_reference_gain(-1, 100) == 1.0f && aac_drc_reference_gain(124, -1) == 1.0f,
        "no reference level: no gain");
  CHECK(aac_dvb_compression_gain(0x00) == 1.0f, "compr 0x00 is unity");
  CHECK(fabs(aac_dvb_compression_gain(0x0F) - 1.9375) < 1e-6, "compr 0x0F is 31/16");
  CHECK(fabs(aac_dvb_compression_gain(0x80) - 1.0 / 256.0) < 1e-9, "compr 0x80 is -48.2 dB");
  CHECK(fabs(gain_db(aac_dvb_compression_gain(0x7F)) - 47.89) < 0.01, "compr 0x7F is +47.9 dB");
  CHECK(fabs(gain_db(aac_dvb_compression_gain(0xF0)) + 6.0206) < 0.01, "compr 0xF0 is -6 dB");
  CHECK(fabs(gain_db(aac_dvb_compression_gain(0xE0)) + 12.04) < 0.01, "compr 0xE0 is -12 dB");
}

static void test_profile_curves() {
  /* Every curve is flat at the reference, cuts above it and boosts
   * below, monotonically, within the signalled range. */
  for (int p = AAC_DRC_FILM_STANDARD; p <= AAC_DRC_SPEECH; p++) {
    const AacDrcProfile profile = (AacDrcProfile)p;
    CHECK(aac_drc_profile_gain_db(profile, 0.0f) == 0.0f, "profile %d: unity at the reference", p);
    CHECK(aac_drc_profile_gain_db(profile, 22.0f) < -5.0f, "profile %d cuts loud frames", p);
    CHECK(aac_drc_profile_gain_db(profile, -40.0f) >= 6.0f, "profile %d boosts quiet frames", p);
    float prev = aac_drc_profile_gain_db(profile, -60.0f);
    for (float l = -59.0f; l <= 60.0f; l += 1.0f) {
      const float g = aac_drc_profile_gain_db(profile, l);
      CHECK(g <= prev + 1e-5f, "profile %d monotonic at %.0f dB", p, l);
      CHECK(g >= -24.0f && g <= 15.0f, "profile %d within range at %.0f dB", p, l);
      prev = g;
    }
  }
  CHECK(aac_drc_profile_gain_db(AAC_DRC_NONE, 30.0f) == 0.0f, "no profile: no gain");
  CHECK(aac_drc_profile_gain_db(AAC_DRC_FILM_LIGHT, 8.0f) == 0.0f &&
            aac_drc_profile_gain_db(AAC_DRC_FILM_STANDARD, 8.0f) < 0.0f,
        "the light film curve has the wider null band");
  CHECK(aac_drc_profile_gain_db(AAC_DRC_MUSIC_LIGHT, -48.0f) == 12.0f &&
            aac_drc_profile_gain_db(AAC_DRC_SPEECH, -41.0f) == 15.0f,
        "maximum boosts");
}

/* ── application ──────────────────────────────────────────────── */

/* A stereo tone at -9 dBFS RMS: 22 dB above a -31 dB reference, which
 * the film standard curve cuts by 8.5 dB (34 steps). */
static std::vector<float> loud_tones(int seconds, int channels) {
  const int n = kRate * seconds;
  std::vector<float> pcm((size_t)n * channels);
  for (int i = 0; i < n; i++) {
    const float t = (float)i / (float)kRate;
    for (int c = 0; c < channels; c++) {
      pcm[(size_t)i * channels + c] =
          0.5f * sinf(2.0f * (float)M_PI * (c ? 660.0f : 440.0f) * t);
    }
  }
  return pcm;
}

static void test_apply_band_gain() {
  const std::vector<float> pcm = loud_tones(2, 2);
  const std::vector<uint8_t> stream =
      encode_with_metadata(pcm, kRate, 2, 128000, AAC_DRC_FILM_STANDARD, 124);
  CHECK(!stream.empty(), "encoded");
  std::vector<FrameDrc> infos;
  int errors = 0;
  const std::vector<float> off = decode_with(stream, 2, 0, 100, 100, -1, 0, &infos, &errors);
  CHECK(errors == 0, "decodes without errors");
  const int frames = (int)infos.size();
  CHECK(frames > 40, "enough frames (%d)", frames);
  /* The metadata is parsed whether or not it is applied. */
  int signalled_ctl = -1;
  for (int f = 10; f < frames - 10; f++) {
    CHECK(infos[f].present && infos[f].has_drc == 1 && infos[f].prog_ref_level == 124,
          "frame %d carries DRC with the reference level", f);
    CHECK(infos[f].sign == 1, "frame %d: a cut", f);
    if (signalled_ctl < 0) {
      signalled_ctl = infos[f].ctl;
    }
    CHECK(infos[f].ctl == signalled_ctl, "frame %d: steady gain (%d vs %d)", f, infos[f].ctl,
          signalled_ctl);
  }
  CHECK(signalled_ctl == 34, "the film standard curve cuts 22 dB over reference by 34 steps (%d)",
        signalled_ctl);
  const double expected_db = gain_db(aac_drc_band_gain(1, signalled_ctl, 100, 100));

  const std::vector<float> on = decode_with(stream, 2, 1, 100, 100, -1, 0);
  CHECK(on.size() == off.size(), "same output length");
  for (int c = 0; c < 2; c++) {
    const double ratio = rms_db(on, 2, c, 1024, 12, frames - 12) - rms_db(off, 2, c, 1024, 12, frames - 12);
    CHECK(fabs(ratio - expected_db) < 0.1, "channel %d: cut of %.2f dB applied (%.2f dB)", c,
          expected_db, ratio);
  }
  /* The gain is constant within a frame, so its ratio is constant over
   * the steady region, not just on average. */
  for (int f = 12; f < frames - 12; f++) {
    const double ratio = rms_db(on, 2, 0, 1024, f, f + 1) - rms_db(off, 2, 0, 1024, f, f + 1);
    CHECK(fabs(ratio - expected_db) < 0.1, "frame %d: %.2f dB", f, ratio);
  }

  /* Cut at 50 percent halves the attenuation; boost scaling does not
   * touch a cut. */
  const std::vector<float> half = decode_with(stream, 2, 1, 100, 50, -1, 0);
  const double half_db = rms_db(half, 2, 0, 1024, 12, frames - 12) - rms_db(off, 2, 0, 1024, 12, frames - 12);
  CHECK(fabs(half_db - expected_db / 2.0) < 0.1, "cut at 50 percent: %.2f dB", half_db);
  const std::vector<float> nocut = decode_with(stream, 2, 1, 0, 0, -1, 0);
  const double nocut_db = rms_db(nocut, 2, 0, 1024, 12, frames - 12) - rms_db(off, 2, 0, 1024, 12, frames - 12);
  CHECK(fabs(nocut_db) < 0.01, "cut at 0 percent leaves the signal (%.3f dB)", nocut_db);
  CHECK(snr_db(off.data(), nocut.data(), off.size()) > 120.0, "enabled with zero gains is exact");

  /* Reference-level normalisation: the -31 dB program brought to -25 dB
   * is raised by 6 dB, on top of the band gain when that applies. */
  const std::vector<float> norm = decode_with(stream, 2, 1, 0, 0, 100, 0);
  const double norm_db = rms_db(norm, 2, 1, 1024, 12, frames - 12) - rms_db(off, 2, 1, 1024, 12, frames - 12);
  CHECK(fabs(norm_db - 6.0) < 0.05, "normalised to -25 dB: +%.2f dB", norm_db);
  const std::vector<float> both = decode_with(stream, 2, 1, 100, 100, 100, 0);
  const double both_db = rms_db(both, 2, 1, 1024, 12, frames - 12) - rms_db(off, 2, 1, 1024, 12, frames - 12);
  CHECK(fabs(both_db - (expected_db + 6.0)) < 0.1, "cut and normalisation: %.2f dB", both_db);
  const std::vector<float> lower = decode_with(stream, 2, 1, 0, 0, 127, 0);
  const double lower_db = rms_db(lower, 2, 0, 1024, 12, frames - 12) - rms_db(off, 2, 0, 1024, 12, frames - 12);
  CHECK(fabs(lower_db + 0.75) < 0.05, "normalised to -31.75 dB: %.2f dB", lower_db);

  /* Heavy compression requested but not carried: the band gains apply. */
  const std::vector<float> heavy = decode_with(stream, 2, 1, 100, 100, -1, 1);
  CHECK(snr_db(on.data(), heavy.data(), on.size()) > 120.0, "no ancillary data: band gains");
}

static void test_quiet_boost() {
  /* A quiet signal 27 dB below the reference is boosted: the speech
   * curve's 2:1 boost starts 11 dB below the reference, so 16 dB further
   * down gives 8 dB. */
  std::vector<float> pcm = loud_tones(1, 1);
  for (float& v : pcm) {
    v *= 0.00356f; /* -58 dBFS RMS */
  }
  const std::vector<uint8_t> stream =
      encode_with_metadata(pcm, kRate, 1, 64000, AAC_DRC_SPEECH, 124);
  std::vector<FrameDrc> infos;
  const std::vector<float> off = decode_with(stream, 1, 0, 100, 100, -1, 0, &infos);
  const int frames = (int)infos.size();
  int ctl = -1;
  for (int f = 10; f < frames - 10; f++) {
    CHECK(infos[f].present && infos[f].sign == 0, "frame %d: a boost", f);
    ctl = infos[f].ctl;
  }
  CHECK(ctl >= 30 && ctl <= 34, "speech curve boosts 27 dB below reference by about 8 dB (%d)", ctl);
  const double expected_db = gain_db(aac_drc_band_gain(0, ctl, 100, 100));
  const std::vector<float> on = decode_with(stream, 1, 1, 100, 100, -1, 0);
  const double ratio = rms_db(on, 1, 0, 1024, 12, frames - 12) - rms_db(off, 1, 0, 1024, 12, frames - 12);
  CHECK(fabs(ratio - expected_db) < 0.1, "boost of %.2f dB applied (%.2f dB)", expected_db, ratio);
  const std::vector<float> half = decode_with(stream, 1, 1, 50, 100, -1, 0);
  const double half_db = rms_db(half, 1, 0, 1024, 12, frames - 12) - rms_db(off, 1, 0, 1024, 12, frames - 12);
  CHECK(fabs(half_db - expected_db / 2.0) < 0.1, "boost at 50 percent: %.2f dB", half_db);
}

static void test_excluded_and_bands() {
  const std::vector<float> pcm = loud_tones(1, 2);
  const std::vector<uint8_t> plain = encode_stream(pcm, kRate, 2, 128000, AAC_RC_CBR);
  std::vector<FrameDrc> infos;
  const std::vector<float> off = decode_with(plain, 2, 1, 100, 100, -1, 0, &infos);
  const int frames = (int)infos.size();
  for (int f = 0; f < frames; f++) {
    CHECK(!infos[f].has_drc && infos[f].prog_ref_level == -1, "frame %d: no DRC in a plain stream", f);
  }

  /* A program-wide fill element before the channel element: a 12 dB cut
   * with the right channel excluded. */
  AacDrcInfo info = {};
  info.num_bands = 1;
  info.prog_ref_level = -1;
  info.excluded_present = 1;
  info.exclude_mask = 1u << 1;
  info.num_excluded = 7;
  info.sign[0] = 1;
  info.ctl[0] = 48;
  int bits = 0;
  const std::vector<uint8_t> fil = fill_element(info, &bits);
  const std::vector<uint8_t> stream = insert_element(plain, fil, bits);
  int errors = 0;
  const std::vector<float> on = decode_with(stream, 2, 1, 100, 100, -1, 0, &infos, &errors);
  CHECK(errors == 0 && on.size() == off.size(), "stream with the inserted fill element decodes");
  for (int f = 0; f < frames; f++) {
    CHECK(infos[f].has_drc && infos[f].present && infos[f].ctl == 48, "frame %d: global DRC", f);
  }
  const double left = rms_db(on, 2, 0, 1024, 8, frames - 8) - rms_db(off, 2, 0, 1024, 8, frames - 8);
  const double right = rms_db(on, 2, 1, 1024, 8, frames - 8) - rms_db(off, 2, 1, 1024, 8, frames - 8);
  CHECK(fabs(left + 12.04) < 0.1, "left channel cut by 12 dB (%.2f)", left);
  CHECK(fabs(right) < 1e-3, "right channel excluded (%.3f dB)", right);

  /* Two bands: the lines up to 1.5 kHz (band top 15: 64 lines of 23.4 Hz)
   * cut by 12 dB, the rest untouched.  The left tone at 440 Hz is in the
   * first band and the right tone at 660 Hz too; add a 3 kHz tone to see
   * the second band pass. */
  std::vector<float> two = loud_tones(1, 1);
  for (size_t i = 0; i < two.size(); i++) {
    two[i] += 0.3f * sinf(2.0f * (float)M_PI * 3000.0f * (float)i / (float)kRate);
  }
  const std::vector<uint8_t> plain_mono = encode_stream(two, kRate, 1, 96000, AAC_RC_CBR);
  AacDrcInfo bands = {};
  bands.bands_present = 1;
  bands.num_bands = 2;
  bands.band_top[0] = 15;
  bands.band_top[1] = 255;
  bands.prog_ref_level = -1;
  bands.sign[0] = 1;
  bands.ctl[0] = 48;
  bands.sign[1] = 0;
  bands.ctl[1] = 0;
  const std::vector<uint8_t> fil2 = fill_element(bands, &bits);
  const std::vector<uint8_t> stream2 = insert_element(plain_mono, fil2, bits);
  const std::vector<float> off2 = decode_with(plain_mono, 1, 0, 100, 100, -1, 0, &infos);
  const int frames2 = (int)infos.size();
  const std::vector<float> on2 = decode_with(stream2, 1, 1, 100, 100, -1, 0, nullptr, &errors);
  CHECK(errors == 0, "two-band stream decodes");
  const size_t from = (size_t)8 * 1024;
  const size_t to = (size_t)(frames2 - 8) * 1024;
  const double low = band_db(on2, 1, 0, 200.0f, 800.0f, kRate, from, to) -
                     band_db(off2, 1, 0, 200.0f, 800.0f, kRate, from, to);
  const double high = band_db(on2, 1, 0, 2000.0f, 4000.0f, kRate, from, to) -
                      band_db(off2, 1, 0, 2000.0f, 4000.0f, kRate, from, to);
  CHECK(fabs(low + 12.04) < 0.2, "first band cut by 12 dB (%.2f)", low);
  CHECK(fabs(high) < 0.2, "second band untouched (%.2f dB)", high);
}

static void test_heavy_compression() {
  const std::vector<float> pcm = loud_tones(1, 2);
  const std::vector<uint8_t> with_drc =
      encode_with_metadata(pcm, kRate, 2, 128000, AAC_DRC_FILM_STANDARD, 124);
  int bits = 0;
  const std::vector<uint8_t> dse = dse_element(1, 0xE0, &bits); /* -12 dB */
  const std::vector<uint8_t> stream = insert_element(with_drc, dse, bits);
  std::vector<FrameDrc> infos;
  int errors = 0;
  const std::vector<float> off = decode_with(stream, 2, 0, 100, 100, -1, 0, &infos, &errors);
  CHECK(errors == 0, "stream with the data stream element decodes");
  const int frames = (int)infos.size();
  const std::vector<float> plain = decode_with(with_drc, 2, 0, 100, 100, -1, 0);
  CHECK(snr_db(plain.data(), off.data(), plain.size()) > 120.0, "the DSE does not change the audio");
  /* Heavy compression replaces the band gains. */
  const std::vector<float> heavy = decode_with(stream, 2, 1, 100, 100, -1, 1);
  const double ratio = rms_db(heavy, 2, 0, 1024, 12, frames - 12) - rms_db(off, 2, 0, 1024, 12, frames - 12);
  CHECK(fabs(ratio + 12.04) < 0.1, "heavy compression of -12 dB applied (%.2f dB)", ratio);
  /* Without the heavy flag the band gains apply as before. */
  const std::vector<float> bands = decode_with(stream, 2, 1, 100, 100, -1, 0);
  const double band_ratio = rms_db(bands, 2, 0, 1024, 12, frames - 12) - rms_db(off, 2, 0, 1024, 12, frames - 12);
  CHECK(fabs(band_ratio - gain_db(aac_drc_band_gain(1, 34, 100, 100))) < 0.1,
        "band gains without the heavy flag (%.2f dB)", band_ratio);
  /* The reference level still normalises the heavy path. */
  const std::vector<float> both = decode_with(stream, 2, 1, 100, 100, 100, 1);
  const double both_db = rms_db(both, 2, 0, 1024, 12, frames - 12) - rms_db(off, 2, 0, 1024, 12, frames - 12);
  CHECK(fabs(both_db + 6.04) < 0.1, "heavy compression and normalisation (%.2f dB)", both_db);
  /* compression_on clear: nothing heavy to apply. */
  const std::vector<uint8_t> dse_off = dse_element(0, 0xE0, &bits);
  const std::vector<uint8_t> stream_off = insert_element(with_drc, dse_off, bits);
  const std::vector<float> none = decode_with(stream_off, 2, 1, 100, 100, -1, 1);
  CHECK(snr_db(bands.data(), none.data(), bands.size()) > 120.0, "compression off: band gains");
}

static void test_sbr_stream() {
  /* An HE-AAC stream: the gain scales the whole band, the replicated
   * part included, and is interpolated across the frame. */
  std::vector<float> pcm = gen_wideband(kRate * 2, kRate, 2);
  const std::vector<uint8_t> stream =
      encode_with_metadata(pcm, kRate, 2, 64000, AAC_DRC_FILM_STANDARD, 124, AAC_AOT_SBR);
  CHECK(!stream.empty(), "HE-AAC encoded");
  std::vector<FrameDrc> infos;
  int errors = 0;
  const std::vector<float> off = decode_with(stream, 2, 0, 100, 100, -1, 0, &infos, &errors);
  CHECK(errors == 0, "HE-AAC decodes");
  const int frames = (int)infos.size();
  int ctl = -1;
  for (int f = 8; f < frames - 8; f++) {
    CHECK(infos[f].present, "frame %d carries DRC", f);
    if (infos[f].present) {
      ctl = ctl < 0 ? infos[f].ctl : ctl;
    }
  }
  const std::vector<float> on = decode_with(stream, 2, 1, 100, 100, -1, 0);
  CHECK(on.size() == off.size(), "same length");
  /* The gain varies a little with the signal; compare frame by frame
   * against what each frame signalled (the previous frame's gain is
   * blended in over the slots, so take the middle of long runs). */
  int checked = 0;
  for (int f = 8; f < frames - 8; f++) {
    if (infos[f].ctl != infos[f - 1].ctl || infos[f].ctl != infos[f + 1].ctl) {
      continue;
    }
    const double expected = gain_db(aac_drc_band_gain(infos[f].sign, infos[f].ctl, 100, 100));
    const double got = rms_db(on, 2, 0, 2048, f, f + 1) - rms_db(off, 2, 0, 2048, f, f + 1);
    CHECK(fabs(got - expected) < 0.3, "HE-AAC frame %d: %.2f vs %.2f dB", f, got, expected);
    const double high = band_db(on, 2, 0, 9000.0f, 14000.0f, kRate, (size_t)f * 2048, (size_t)(f + 1) * 2048) -
                        band_db(off, 2, 0, 9000.0f, 14000.0f, kRate, (size_t)f * 2048, (size_t)(f + 1) * 2048);
    CHECK(fabs(high - expected) < 0.5, "HE-AAC frame %d high band: %.2f vs %.2f dB", f, high, expected);
    checked++;
  }
  CHECK(checked > 5, "frames with steady gain checked (%d)", checked);
}

/* Decodes an ADTS stream through the streaming API with frame `lost`
 * replaced by as many zero bytes, which the transport discards and
 * stands a concealed frame in for; returns one entry per output frame. */
static std::vector<std::vector<float>> decode_with_gap(const std::vector<uint8_t>& stream,
                                                       int lost, int channels, int enabled,
                                                       int target, int* concealed_at) {
  std::vector<uint8_t> damaged = stream;
  const std::vector<AdtsFrame> frames = split_adts(stream);
  memset(damaged.data() + frames[lost].offset, 0, frames[lost].size);
  AacDecoderHandle dec = aac_decoder_create(kRate, channels);
  CHECK(aac_decoder_set_drc(dec, enabled, 100, 100, target, 0) == AAC_OK, "set_drc");
  CHECK(aac_decoder_feed(dec, damaged.data(), (int)damaged.size()) == (int)damaged.size(),
        "the stream fits the FIFO");
  std::vector<std::vector<float>> out;
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  *concealed_at = -1;
  for (;;) {
    const int got = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
    if (got <= 0) {
      CHECK(got == 0, "decode_next %d", got);
      break;
    }
    if (aac_decoder_last_frame_concealed(dec) && *concealed_at < 0) {
      *concealed_at = (int)out.size();
    }
    out.emplace_back(pcm.begin(), pcm.begin() + (size_t)got * aac_decoder_channels(dec));
  }
  aac_decoder_destroy(dec);
  return out;
}

/* Energy in dB of one frame of interleaved output. */
static double frame_db(const std::vector<float>& frame) {
  double e = 0.0;
  for (float v : frame) {
    e += (double)v * v;
  }
  return 10.0 * log10(e / (double)std::max<size_t>(frame.size(), 1) + 1e-30);
}

/* A lost frame of an HE-AAC stream with DRC and a target reference level
 * keeps the gains of the frame before it: the concealed frame's level,
 * relative to the same concealed frame with DRC off, stays with its
 * neighbours' instead of ramping to unity and back. */
static void test_concealed_frame_gain() {
  const std::vector<float> pcm = gen_wideband(kRate * 2, kRate, 2);
  const std::vector<uint8_t> stream =
      encode_with_metadata(pcm, kRate, 2, 64000, AAC_DRC_FILM_STANDARD, 124, AAC_AOT_SBR);
  CHECK(!stream.empty(), "HE-AAC encoded");
  const int lost = (int)split_adts(stream).size() / 2;
  int concealed_off = -1;
  int concealed_on = -1;
  const std::vector<std::vector<float>> off =
      decode_with_gap(stream, lost, 2, 0, -1, &concealed_off);
  const std::vector<std::vector<float>> on =
      decode_with_gap(stream, lost, 2, 1, 100, &concealed_on);
  CHECK(concealed_off > 2 && concealed_off == concealed_on && on.size() == off.size(),
        "one concealed frame at %d / %d of %zu / %zu frames", concealed_off, concealed_on,
        off.size(), on.size());
  if (concealed_off <= 2 || concealed_off != concealed_on || on.size() != off.size() ||
      (size_t)concealed_off + 2 >= on.size()) {
    return;
  }
  const int k = concealed_off;
  const double before = frame_db(on[k - 1]) - frame_db(off[k - 1]);
  const double during = frame_db(on[k]) - frame_db(off[k]);
  const double after = frame_db(on[k + 1]) - frame_db(off[k + 1]);
  printf("concealed frame %d: DRC gain %.2f dB, neighbours %.2f and %.2f dB\n", k, during, before,
         after);
  CHECK(fabs(during - 0.5 * (before + after)) < 1.0,
        "concealed frame gain %.2f dB against neighbours %.2f and %.2f dB", during, before, after);
  /* The reference-level gain alone is 6 dB here, which a ramp to unity
   * would lose. */
  CHECK(before > 3.0, "the neighbours carry the DRC gain (%.2f dB)", before);
}

static void test_api() {
  AacEncoderHandle enc = aac_encoder_create(kRate, 2, 128000, AAC_AOT_LC, AAC_RC_CBR);
  CHECK(aac_encoder_set_metadata(enc, (AacDrcProfile)6, 124) == AAC_ERR_INVALID_ARG, "bad profile");
  CHECK(aac_encoder_set_metadata(enc, AAC_DRC_FILM_LIGHT, 128) == AAC_ERR_INVALID_ARG, "bad level");
  CHECK(aac_encoder_set_metadata(enc, AAC_DRC_FILM_LIGHT, -1) == AAC_ERR_INVALID_ARG, "bad level");
  CHECK(aac_encoder_set_metadata(enc, AAC_DRC_NONE, 0) == AAC_OK, "none");
  std::vector<float> block(2048, 0.0f);
  std::vector<uint8_t> buf(8192);
  CHECK(aac_encoder_encode(enc, block.data(), 1024, buf.data(), (int)buf.size()) > 0, "encode");
  CHECK(aac_encoder_set_metadata(enc, AAC_DRC_FILM_LIGHT, 124) == AAC_ERR_STATE,
        "metadata after the first frame refused");
  aac_encoder_destroy(enc);
  CHECK(aac_encoder_set_metadata(nullptr, AAC_DRC_NONE, 0) == AAC_ERR_INVALID_ARG, "null encoder");

  AacDecoderHandle dec = aac_decoder_create(kRate, 2);
  CHECK(aac_decoder_program_ref_level(dec) == -1 && aac_decoder_has_drc(dec) == 0,
        "nothing decoded yet");
  CHECK(aac_decoder_set_drc(dec, 1, 101, 100, -1, 0) == AAC_ERR_INVALID_ARG, "boost over 100");
  CHECK(aac_decoder_set_drc(dec, 1, 100, -1, -1, 0) == AAC_ERR_INVALID_ARG, "cut below 0");
  CHECK(aac_decoder_set_drc(dec, 1, 100, 100, 128, 0) == AAC_ERR_INVALID_ARG, "target over 127");
  CHECK(aac_decoder_set_drc(dec, 1, 100, 100, -2, 0) == AAC_ERR_INVALID_ARG, "target below -1");
  CHECK(aac_decoder_set_drc(dec, 1, 0, 0, 127, 1) == AAC_OK, "valid settings");
  aac_decoder_destroy(dec);

  /* The readback describes the last frame; a reset (a seek) clears it. */
  const std::vector<uint8_t> with_drc =
      encode_with_metadata(loud_tones(1, 2), kRate, 2, 128000, AAC_DRC_FILM_STANDARD, 124);
  dec = aac_decoder_create(kRate, 2);
  decode_stream(with_drc, kRate, 2, nullptr, dec);
  CHECK(aac_decoder_has_drc(dec) == 1 && aac_decoder_program_ref_level(dec) == 124,
        "readback after the stream: %d, %d", aac_decoder_has_drc(dec),
        aac_decoder_program_ref_level(dec));
  CHECK(aac_decoder_reset(dec) == AAC_OK, "reset");
  CHECK(aac_decoder_has_drc(dec) == 0 && aac_decoder_program_ref_level(dec) == -1,
        "readback cleared by the reset: %d, %d", aac_decoder_has_drc(dec),
        aac_decoder_program_ref_level(dec));
  aac_decoder_destroy(dec);
  CHECK(aac_decoder_set_drc(nullptr, 1, 100, 100, -1, 0) == AAC_ERR_INVALID_ARG, "null decoder");
  CHECK(aac_decoder_program_ref_level(nullptr) == AAC_ERR_INVALID_ARG, "null decoder");
}

/* The encoder's output is the same with and without a metadata call that
 * selects no profile. */
static void test_no_metadata_identity() {
  const std::vector<float> pcm = loud_tones(1, 2);
  const std::vector<uint8_t> plain = encode_stream(pcm, kRate, 2, 128000, AAC_RC_CBR);
  const std::vector<uint8_t> none = encode_with_metadata(pcm, kRate, 2, 128000, AAC_DRC_NONE, 124);
  CHECK(plain == none, "AAC_DRC_NONE writes nothing");
  /* At a constant rate the metadata displaces audio bits rather than
   * growing the stream, so look for it in the decoded frames. */
  const std::vector<uint8_t> with = encode_with_metadata(pcm, kRate, 2, 128000, AAC_DRC_FILM_STANDARD, 124);
  CHECK(with != plain, "the metadata changes the stream");
  std::vector<FrameDrc> infos;
  decode_with(with, 2, 0, 0, 0, -1, 0, &infos);
  int carrying = 0;
  for (const FrameDrc& f : infos) {
    carrying += f.has_drc;
  }
  CHECK(!infos.empty() && carrying == (int)infos.size(), "metadata in %d of %zu frames", carrying,
        infos.size());
}

/* FFmpeg's native decoder parses the fill element: it must decode the
 * stream without complaint and give the same audio as our decoder with
 * DRC off, since it does not apply the gains itself. */
static void test_ffmpeg() {
  if (!have_ffmpeg()) {
    printf("ffmpeg not found: interop skipped\n");
    return;
  }
  const std::string dir = make_temp_dir();
  const std::vector<float> pcm = loud_tones(2, 2);
  const std::vector<uint8_t> stream =
      encode_with_metadata(pcm, kRate, 2, 128000, AAC_DRC_FILM_STANDARD, 124);
  CHECK(write_file(dir + "/drc.aac", stream.data(), stream.size()), "write stream");
  const std::string cmd = "ffmpeg -nostdin -v error -y -i '" + dir + "/drc.aac' -f f32le -acodec pcm_f32le '" + dir + "/ff.f32'";
  CHECK(run_command(cmd), "ffmpeg decodes the metadata stream");
  const std::string log = "ffmpeg -nostdin -v warning -i '" + dir + "/drc.aac' -f null - 2> '" + dir + "/log.txt'";
  (void)system(log.c_str());
  /* The demuxer's note that it estimates the duration from the bitrate
   * is not about the stream's content. */
  const std::vector<uint8_t> log_bytes = read_file(dir + "/log.txt");
  std::string warnings(log_bytes.begin(), log_bytes.end());
  const size_t note = warnings.find("Estimating duration from bitrate");
  if (note != std::string::npos) {
    const size_t end = warnings.find('\n', note);
    warnings.erase(0, end == std::string::npos ? warnings.size() : end + 1);
  }
  CHECK(warnings.empty(), "ffmpeg logs no warning (%.200s)", warnings.c_str());
  const std::vector<float> ff = read_f32(dir + "/ff.f32");
  const std::vector<float> off = decode_with(stream, 2, 0, 100, 100, -1, 0);
  const std::vector<float> on = decode_with(stream, 2, 1, 100, 100, -1, 0);
  const size_t n = std::min(ff.size(), off.size());
  CHECK(n > 0 && ff.size() == off.size(), "ffmpeg output length %zu vs ours %zu", ff.size(), off.size());
  const double d_off = snr_db(off.data(), ff.data(), n);
  const double d_on = snr_db(on.data(), ff.data(), n);
  printf("%-28s ffmpeg vs ours off: %.1f dB, vs ours on: %.1f dB\n", "drc metadata stream", d_off, d_on);
  CHECK(d_off > 90.0, "ffmpeg's decode matches ours without DRC (%.1f dB)", d_off);
  const int frames = (int)(n / 2 / 1024);
  const double ratio = rms_db(on, 2, 0, 1024, 12, frames - 12) - rms_db(ff, 2, 0, 1024, 12, frames - 12);
  CHECK(fabs(ratio - gain_db(aac_drc_band_gain(1, 34, 100, 100))) < 0.1,
        "ours with DRC sits the signalled 8.5 dB below ffmpeg (%.2f dB)", ratio);
  remove_temp_dir(dir);
}

int main() {
  test_parse_minimal();
  test_parse_full();
  test_dvb_ancillary();
  test_gain_formulas();
  test_profile_curves();
  test_apply_band_gain();
  test_quiet_boost();
  test_excluded_and_bands();
  test_heavy_compression();
  test_sbr_stream();
  test_concealed_frame_gain();
  test_api();
  test_no_metadata_identity();
  test_ffmpeg();
  return test_summary("test_drc");
}
