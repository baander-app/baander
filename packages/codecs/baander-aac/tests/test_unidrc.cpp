/* MPEG-D DRC and loudness: the uniDrcConfig and loudnessInfoSet of the
 * xHE-AAC reference streams parsed to the bit, the uniDrcGain payload of
 * every frame of the committed streams, the gain decoding against node
 * sequences whose decoding was established with the FDK decoder, the
 * selection process (effect fallback, loudness normalisation, album mode,
 * peak protection, clipping prevention) on the stream configs and on
 * hand-built ones, the time-domain application, and a mutation fuzz of
 * the parsers. */
#include "latm.h"
#include "test_util.h"
#include "unidrc.h"

/* ── bit helpers ───────────────────────────────────────────────── */

struct Bits {
  std::vector<uint8_t> buf = std::vector<uint8_t>(4096);
  AacBitWriter w;
  Bits() { aac_bitwriter_init(&w, buf.data(), (int)buf.size()); }
  void put(uint32_t v, int n) { aac_bitwriter_write(&w, v, n); }
  /* a string of '0' and '1', other characters ignored */
  void bits(const char* s) {
    for (; *s; s++) {
      if (*s == '0' || *s == '1') {
        aac_bitwriter_write(&w, (uint32_t)(*s - '0'), 1);
      }
    }
  }
  int count() const { return aac_bitwriter_bits_written(&w); }
  std::vector<uint8_t> bytes() const {
    return std::vector<uint8_t>(buf.begin(), buf.begin() + (count() + 7) / 8);
  }
};

static std::vector<uint8_t> from_hex(const char* h) {
  std::vector<uint8_t> out;
  for (; h[0] && h[1]; h += 2) {
    char tmp[3] = {h[0], h[1], 0};
    out.push_back((uint8_t)strtol(tmp, nullptr, 16));
  }
  return out;
}

/* ── the kit's configurations ──────────────────────────────────── */

/* uniDrcConfig() of the FDK xHE-AAC encoder, stereo and mono. */
static const char* kConfigStereo =
    "00000124b66810204060b5c880209020809080009fc0180011080055fc0180c2819080081ff2006028608420020640"
    "06020000";
static const char* kConfigMono =
    "000000a4b264880209020809080009fc0100221000abf80230a064200207fc801143042100103200220000";
/* loudnessInfoSet(): program loudness -14.25, -14.0, -19.25 and -19.75 LUFS */
static const char* kLoudMusic48 = "0010004b8c235c58";
static const char* kLoudMusic = "0010004b8c235e58";
static const char* kLoudSpeech48 = "0010004c38233458";
static const char* kLoudSpeech32 = "0010004c38233058";

static unsigned escaped(AacBitReader* r, int a, int b, int c) {
  unsigned v = aac_bitreader_read(r, a);
  if (v == (1u << a) - 1) {
    const unsigned v2 = aac_bitreader_read(r, b);
    v += v2;
    if (c && v2 == (1u << b) - 1) {
      v += aac_bitreader_read(r, c);
    }
  }
  return v;
}

/* What the test needs of a LOAS stream: the stream layout, the DRC
 * configuration bytes and the access units. */
struct Stream {
  int rate = 0, channels = 0, frame_length = 0;
  int drc_default_length = 0;
  std::vector<uint8_t> drc_config, loudness;
  std::vector<std::vector<uint8_t>> aus;
};

/* UsacConfig() as far as the extension elements, after the ASC header. */
static void read_usac_config(AacBitReader* r, Stream* s) {
  static const int kRates[] = {96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000,
                               12000, 11025, 8000,  7350,  0,     0,     57600, 51200, 40000,
                               38400, 34150, 28800, 25600, 20000, 19200, 17075, 14400, 12800, 9600};
  const int sfi = (int)aac_bitreader_read(r, 5);
  s->rate = sfi == 31 ? (int)aac_bitreader_read(r, 24) : kRates[sfi];
  const int csfl = (int)aac_bitreader_read(r, 3);
  static const int kOut[] = {768, 1024, 2048, 2048, 4096};
  static const int kSbr[] = {0, 0, 2, 3, 1};
  s->frame_length = kOut[csfl];
  const int cci = (int)aac_bitreader_read(r, 5);
  static const int kChannels[] = {0, 1, 2, 3, 4, 5, 6, 8, 2, 3, 4, 7, 8, 24, 8, 12, 10, 12, 14, 12, 14};
  if (cci == 0) {
    s->channels = (int)escaped(r, 5, 8, 16);
    for (int i = 0; i < s->channels; i++) {
      aac_bitreader_skip(r, 5);
    }
  } else {
    s->channels = kChannels[cci];
  }
  const int nelem = (int)escaped(r, 4, 8, 16) + 1;
  for (int e = 0; e < nelem; e++) {
    const int t = (int)aac_bitreader_read(r, 2);
    if (t == 0 || t == 1) {
      aac_bitreader_skip(r, 2); /* tw_mdct, noiseFilling */
      if (kSbr[csfl] > 0) {
        aac_bitreader_skip(r, 3 + 4 + 4); /* harmonicSBR, interTes, pvc, start, stop */
        const int e1 = (int)aac_bitreader_read(r, 1), e2 = (int)aac_bitreader_read(r, 1);
        if (e1) aac_bitreader_skip(r, 5);
        if (e2) aac_bitreader_skip(r, 6);
      }
      if (t == 1) {
        const int sci = kSbr[csfl] > 0 ? (int)aac_bitreader_read(r, 2) : 0;
        if (sci > 0) {
          aac_bitreader_skip(r, 3 + 3);
          const int temp_shape = (int)aac_bitreader_read(r, 2);
          aac_bitreader_skip(r, 2 + 1 + 1);
          if (aac_bitreader_read(r, 1)) aac_bitreader_skip(r, 5);
          if (sci > 1) aac_bitreader_skip(r, 5 + 1);
          if (temp_shape == 2) aac_bitreader_skip(r, 1);
        }
      }
    } else if (t == 3) {
      const int ext_type = (int)escaped(r, 4, 8, 16);
      const int clen = (int)escaped(r, 4, 8, 16);
      int default_length = 0;
      if (aac_bitreader_read(r, 1)) {
        default_length = (int)escaped(r, 8, 16, 0) + 1;
      }
      aac_bitreader_skip(r, 1); /* payloadFrag */
      std::vector<uint8_t> cfg;
      for (int i = 0; i < clen; i++) {
        cfg.push_back((uint8_t)aac_bitreader_read(r, 8));
      }
      if (ext_type == 4) {
        s->drc_config = cfg;
        s->drc_default_length = default_length;
      }
    }
  }
  if (aac_bitreader_read(r, 1)) { /* usacConfigExtensionPresent */
    const int n = (int)escaped(r, 2, 4, 8) + 1;
    for (int i = 0; i < n; i++) {
      const int type = (int)escaped(r, 4, 8, 16);
      const int len = (int)escaped(r, 4, 8, 16);
      std::vector<uint8_t> data;
      for (int k = 0; k < len; k++) {
        data.push_back((uint8_t)aac_bitreader_read(r, 8));
      }
      if (type == 2) {
        s->loudness = data;
      }
    }
  }
}

/* Walks a LOAS file (audioMuxVersion 0, one program, one layer, one
 * subframe) with its first StreamMuxConfig. */
static Stream read_loas(const std::vector<uint8_t>& data) {
  Stream s;
  size_t pos = 0;
  while (pos + 3 <= data.size()) {
    CHECK(aac_loas_is_sync(data.data() + pos, (int)(data.size() - pos)), "LOAS sync at %zu", pos);
    const int len = aac_loas_payload_length(data.data() + pos);
    if (pos + 3 + (size_t)len > data.size()) {
      break;
    }
    AacBitReader r;
    aac_bitreader_init(&r, data.data() + pos + 3, len);
    if (!aac_bitreader_read(&r, 1)) { /* useSameStreamMux */
      CHECK(aac_bitreader_read(&r, 1) == 0, "audioMuxVersion 0");
      aac_bitreader_skip(&r, 1 + 6 + 4 + 3);
      int aot = (int)aac_bitreader_read(&r, 5);
      if (aot == 31) {
        aot = 32 + (int)aac_bitreader_read(&r, 6);
      }
      CHECK(aot == 42, "USAC object type, got %d", aot);
      const int sfi = (int)aac_bitreader_read(&r, 4);
      if (sfi == 15) aac_bitreader_skip(&r, 24);
      aac_bitreader_skip(&r, 4);
      read_usac_config(&r, &s);
      CHECK(aac_bitreader_read(&r, 3) == 0, "frameLengthType 0");
      aac_bitreader_skip(&r, 8);
      if (aac_bitreader_read(&r, 1)) {
        for (;;) {
          const int esc = (int)aac_bitreader_read(&r, 1);
          aac_bitreader_skip(&r, 8);
          if (!esc) break;
        }
      }
      if (aac_bitreader_read(&r, 1)) aac_bitreader_skip(&r, 8);
    }
    int n = 0;
    for (;;) {
      const int t = (int)aac_bitreader_read(&r, 8);
      n += t;
      if (t != 255) break;
    }
    std::vector<uint8_t> au;
    for (int i = 0; i < n; i++) {
      au.push_back((uint8_t)aac_bitreader_read(&r, 8));
    }
    s.aus.push_back(au);
    pos += 3 + (size_t)len;
  }
  return s;
}

/* The UsacExtElement in a frame: present, and the payload's bit range. */
static int ext_element(AacBitReader* r, int default_length, int* payload_bits) {
  if (!aac_bitreader_read(r, 1)) {
    return 0;
  }
  int n;
  if (aac_bitreader_read(r, 1)) {
    n = default_length;
  } else {
    n = (int)aac_bitreader_read(r, 8);
    if (n == 255) {
      n += (int)aac_bitreader_read(r, 16) - 2;
    }
  }
  *payload_bits = 8 * n;
  return 1;
}

/* ── configuration parsing ─────────────────────────────────────── */

static void test_kit_config(const char* hex, int channels, int* bits_used) {
  const std::vector<uint8_t> bytes = from_hex(hex);
  AacBitReader r;
  aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
  AacUniDrcConfig c;
  CHECK(aac_unidrc_parse_config(&r, &c) == AAC_OK, "parse uniDrcConfig (%d channels)", channels);
  *bits_used = r.pos;
  CHECK(r.pos <= (int)bytes.size() * 8 && r.pos > (int)bytes.size() * 8 - 8,
        "uniDrcConfig consumed %d of %d bits", r.pos, (int)bytes.size() * 8);
  CHECK(c.base_channel_count == channels, "baseChannelCount %d", c.base_channel_count);
  CHECK(c.coefficients_count == 1 && c.coefficients[0].version == 1, "one v1 coefficients block");
  CHECK(c.coefficients[0].gain_set_count == 1, "one gain set");
  CHECK(c.coefficients[0].gain_sequence_count == 1, "one gain sequence");
  const AacUniDrcGainSet* gs = &c.coefficients[0].gain_set[0];
  CHECK(gs->gain_coding_profile == AAC_UNIDRC_PROFILE_REGULAR, "regular profile");
  CHECK(gs->gain_interpolation_type == 1, "linear interpolation");
  CHECK(gs->full_frame == 0 && gs->time_alignment == 0 && gs->time_delta_min == 0, "gain set flags");
  CHECK(gs->band_count == 1 && gs->band[0].gain_sequence_index == 0, "one band, sequence 0");
  CHECK(aac_unidrc_time_delta_min(gs, 48000) == 32, "deltaTmin 32 at 48 kHz");
  CHECK(c.instructions_count == 4, "four DRC sets, got %d", c.instructions_count);
  static const unsigned kEffects[4] = {AAC_UNIDRC_EFFECT_NOISY,
                                       AAC_UNIDRC_EFFECT_NIGHT | AAC_UNIDRC_EFFECT_LIMITED |
                                           AAC_UNIDRC_EFFECT_DIALOG,
                                       AAC_UNIDRC_EFFECT_GENERAL, AAC_UNIDRC_EFFECT_GENERAL};
  static const float kAtt[4] = {1.0f, 1.0f, 0.5f, 0.0f};
  static const float kAmp[4] = {1.0f, 0.625f, 0.375f, 0.0f};
  for (int i = 0; i < c.instructions_count && i < 4; i++) {
    const AacUniDrcInstructions* in = &c.instructions[i];
    CHECK(in->drc_set_id == i + 1, "set %d id %d", i, in->drc_set_id);
    CHECK(in->version == 1 && in->complexity_level == 2 && in->drc_location == 1, "set %d header", i);
    CHECK(in->downmix_id == 0, "set %d downmixId", i);
    CHECK(in->drc_set_effect == kEffects[i], "set %d effect %u", i, in->drc_set_effect);
    CHECK(!in->limiter_peak_target_present, "set %d no limiter target", i);
    CHECK(in->target_loudness_present, "set %d target loudness", i);
    CHECK(in->depends_on_drc_set == -1 && !in->no_independent_use && !in->requires_eq,
          "set %d flags", i);
    CHECK(in->channel_count == channels && in->group_count == 1, "set %d channels", i);
    for (int ch = 0; ch < channels; ch++) {
      CHECK(in->gain_set_index[ch] == 0, "set %d channel %d gain set", i, ch);
    }
    CHECK(fabsf(in->group[0].attenuation_scaling - kAtt[i]) < 1e-6f, "set %d attenuation %g", i,
          in->group[0].attenuation_scaling);
    CHECK(fabsf(in->group[0].amplification_scaling - kAmp[i]) < 1e-6f, "set %d amplification %g",
          i, in->group[0].amplification_scaling);
    CHECK(in->group[0].gain_offset_db == 0.0f, "set %d no offset", i);
  }
  CHECK(c.instructions[0].target_loudness_upper == 0 && c.instructions[0].target_loudness_lower == -63,
        "set 1 loudness range");
  CHECK(c.instructions[2].target_loudness_upper == 0 && c.instructions[2].target_loudness_lower == -27,
        "set 3 loudness range %d..%d", c.instructions[2].target_loudness_lower,
        c.instructions[2].target_loudness_upper);
  CHECK(c.instructions[3].target_loudness_upper == -27 && c.instructions[3].target_loudness_lower == -63,
        "set 4 loudness range");
  CHECK(c.downmix_count == (channels == 2 ? 1 : 0), "downmix instructions %d", c.downmix_count);
  if (c.downmix_count == 1) {
    CHECK(c.downmix[0].downmix_id == 1 && c.downmix[0].target_channel_count == 1 &&
              c.downmix[0].target_layout == 1 && c.downmix[0].coefficients_present,
          "mono downmix instructions");
  }
  CHECK(!c.has_parametric_drc && !c.has_loud_eq && !c.has_eq, "no EQ tools");
}

static void test_kit_loudness(const char* hex, float lufs, float peak_db) {
  const std::vector<uint8_t> bytes = from_hex(hex);
  AacBitReader r;
  aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
  AacUniDrcLoudnessSet l;
  CHECK(aac_unidrc_parse_loudness(&r, &l) == AAC_OK, "parse loudnessInfoSet %s", hex);
  CHECK(r.pos == 62, "loudnessInfoSet consumed %d bits", r.pos);
  CHECK(l.album_count == 0 && l.count == 1, "one program loudnessInfo");
  const AacUniDrcLoudnessInfo* li = &l.info[0];
  CHECK(li->drc_set_id == 0 && li->downmix_id == 0, "general loudnessInfo");
  CHECK(li->sample_peak_present && !li->true_peak_present, "sample peak only");
  CHECK(fabsf(li->sample_peak_db - peak_db) < 0.02f, "sample peak %g dBFS", li->sample_peak_db);
  CHECK(li->measurement_count == 1, "one measurement");
  CHECK(li->m[0].method == AAC_UNIDRC_METHOD_PROGRAM_LOUDNESS, "program loudness");
  CHECK(fabsf(li->m[0].value - lufs) < 1e-4f, "loudness %g LUFS", li->m[0].value);
  CHECK(li->m[0].system == 2 && li->m[0].reliability == 3, "EBU R128, accurate");
}

static void test_config_parsing() {
  int bits;
  test_kit_config(kConfigStereo, 2, &bits);
  CHECK(bits == 407, "stereo config is 407 bits, got %d", bits);
  test_kit_config(kConfigMono, 1, &bits);
  CHECK(bits == 343, "mono config is 343 bits, got %d", bits);
  test_kit_loudness(kLoudMusic48, -14.25f, -3.09f);
  test_kit_loudness(kLoudMusic, -14.0f, -3.09f);
  test_kit_loudness(kLoudSpeech48, -19.25f, -4.44f);
  test_kit_loudness(kLoudSpeech32, -19.75f, -4.44f);
}

/* A version 0 uniDrcConfig with one single-band gain set and the given
 * DRC sets (effect, limiter peak target in dB or 1 for none, attenuation
 * and amplification scaling codes, gain offset code or -1). */
struct SetSpec {
  unsigned effect;
  float limiter_peak_db; /* > 0: none */
  int att, amp;          /* scaling codes 0..8, -1 none */
  int offset;            /* bsGainOffset, -1 none */
  int target_upper, target_lower; /* LUFS, 1 for none */
};

static std::vector<uint8_t> build_config_v0(int channels, const std::vector<SetSpec>& sets,
                                            int interpolation = 1, int time_delta_min = 0) {
  Bits b;
  b.put(0, 1);                   /* sampleRatePresent */
  b.put(0, 7);                   /* downmixInstructionsCount */
  b.put(0, 1);                   /* drcDescriptionBasicPresent */
  b.put(1, 3);                   /* drcCoefficientsUniDrcCount */
  b.put((uint32_t)sets.size(), 6); /* drcInstructionsUniDrcCount */
  b.put((uint32_t)channels, 7);  /* baseChannelCount */
  b.put(0, 1);                   /* layoutSignalingPresent */
  /* drcCoefficientsUniDrc */
  b.put(1, 4); /* drcLocation */
  b.put(0, 1); /* drcFrameSizePresent */
  b.put(1, 6); /* gainSetCount */
  b.put(AAC_UNIDRC_PROFILE_REGULAR, 2);
  b.put((uint32_t)interpolation, 1);
  b.put(0, 1); /* fullFrame */
  b.put(0, 1); /* timeAlignment */
  if (time_delta_min) {
    b.put(1, 1);
    b.put((uint32_t)(time_delta_min - 1), 11);
  } else {
    b.put(0, 1);
  }
  b.put(1, 4); /* bandCount */
  b.put(0, 7); /* drcCharacteristic */
  for (size_t i = 0; i < sets.size(); i++) {
    const SetSpec& s = sets[i];
    b.put((uint32_t)(i + 1), 6); /* drcSetId */
    b.put(1, 4);                 /* drcLocation */
    b.put(0, 1);                 /* downmixIdPresent */
    b.put(s.effect, 16);
    if (!(s.effect & (AAC_UNIDRC_EFFECT_DUCK_OTHER | AAC_UNIDRC_EFFECT_DUCK_SELF))) {
      if (s.limiter_peak_db <= 0.0f) {
        b.put(1, 1);
        b.put((uint32_t)lrintf(-s.limiter_peak_db * 8.0f), 8);
      } else {
        b.put(0, 1);
      }
    }
    if (s.target_upper <= 0) {
      b.put(1, 1);
      b.put((uint32_t)(s.target_upper + 63), 6);
      if (s.target_lower <= 0) {
        b.put(1, 1);
        b.put((uint32_t)(s.target_lower + 63), 6);
      } else {
        b.put(0, 1);
      }
    } else {
      b.put(0, 1);
    }
    b.put(0, 1); /* dependsOnDrcSetPresent */
    b.put(0, 1); /* noIndependentUse */
    b.put(1, 6); /* bsGainSetIndex of the first channel */
    if (channels > 1) {
      b.put(1, 1);                       /* repeatGainSetIndex */
      b.put((uint32_t)(channels - 2), 5); /* bsRepeatGainSetIndexCount */
    } else {
      b.put(0, 1);
    }
    if (s.att >= 0) {
      b.put(1, 1);
      b.put((uint32_t)s.att, 4);
      b.put((uint32_t)s.amp, 4);
    } else {
      b.put(0, 1);
    }
    if (s.offset >= 0) {
      b.put(1, 1);
      b.put((uint32_t)s.offset, 6);
    } else {
      b.put(0, 1);
    }
  }
  b.put(0, 1); /* uniDrcConfigExtPresent */
  return b.bytes();
}

/* A loudnessInfoSet with program entries and optional album entries:
 * each {drcSetId, loudness LUFS, sample peak dBFS, true peak dBFS or 1}. */
struct LoudSpec {
  int drc_set_id;
  float lufs;
  float sample_peak;
  float true_peak; /* > 0: none */
};

static void put_loudness_info(Bits& b, const LoudSpec& s) {
  b.put((uint32_t)s.drc_set_id, 6);
  b.put(0, 7); /* downmixId */
  b.put(1, 1);
  b.put((uint32_t)lrintf((20.0f - s.sample_peak) * 32.0f), 12);
  if (s.true_peak <= 0.0f) {
    b.put(1, 1);
    b.put((uint32_t)lrintf((20.0f - s.true_peak) * 32.0f), 12);
    b.put(2, 4);
    b.put(3, 2);
  } else {
    b.put(0, 1);
  }
  b.put(1, 4); /* measurementCount */
  b.put(AAC_UNIDRC_METHOD_PROGRAM_LOUDNESS, 4);
  b.put((uint32_t)lrintf((s.lufs + 57.75f) * 4.0f), 8);
  b.put(2, 4);
  b.put(3, 2);
}

static std::vector<uint8_t> build_loudness(const std::vector<LoudSpec>& program,
                                           const std::vector<LoudSpec>& album) {
  Bits b;
  b.put((uint32_t)album.size(), 6);
  b.put((uint32_t)program.size(), 6);
  for (const LoudSpec& s : album) put_loudness_info(b, s);
  for (const LoudSpec& s : program) put_loudness_info(b, s);
  b.put(0, 1);
  return b.bytes();
}

static void test_built_config_parsing() {
  const std::vector<SetSpec> sets = {
      {AAC_UNIDRC_EFFECT_CLIPPING, -1.0f, 8, 8, -1, 1, 1},
      {AAC_UNIDRC_EFFECT_NIGHT | AAC_UNIDRC_EFFECT_NOISY, 1.0f, 4, 6, 0x25, -20, -31},
  };
  const std::vector<uint8_t> cfg = build_config_v0(2, sets, 0, 64);
  AacBitReader r;
  aac_bitreader_init(&r, cfg.data(), (int)cfg.size());
  AacUniDrcConfig c;
  CHECK(aac_unidrc_parse_config(&r, &c) == AAC_OK, "parse built v0 config");
  CHECK(r.pos > (int)cfg.size() * 8 - 8 && r.pos <= (int)cfg.size() * 8, "v0 config bit count %d/%d",
        r.pos, (int)cfg.size() * 8);
  CHECK(c.coefficients_count == 1 && c.coefficients[0].version == 0, "v0 coefficients");
  CHECK(c.coefficients[0].gain_sequence_count == 1, "v0 gain sequence count");
  CHECK(c.coefficients[0].gain_set[0].gain_interpolation_type == 0, "spline flag kept");
  CHECK(c.coefficients[0].gain_set[0].time_delta_min == 64, "deltaTmin 64");
  CHECK(aac_unidrc_time_delta_min(&c.coefficients[0].gain_set[0], 48000) == 64, "signalled deltaTmin");
  CHECK(c.instructions_count == 2, "two sets");
  CHECK(c.instructions[0].limiter_peak_target_present &&
            fabsf(c.instructions[0].limiter_peak_target_db + 1.0f) < 1e-6f,
        "limiter peak target -1 dB, got %g", c.instructions[0].limiter_peak_target_db);
  CHECK(!c.instructions[0].target_loudness_present, "set 1 without target loudness");
  CHECK(c.instructions[1].target_loudness_upper == -20 && c.instructions[1].target_loudness_lower == -31,
        "set 2 target loudness range");
  CHECK(c.instructions[1].channel_count == 2 && c.instructions[1].group_count == 1, "channel groups");
  CHECK(fabsf(c.instructions[1].group[0].attenuation_scaling - 0.5f) < 1e-6f &&
            fabsf(c.instructions[1].group[0].amplification_scaling - 0.75f) < 1e-6f,
        "scaling 0.5 / 0.75");
  CHECK(fabsf(c.instructions[1].group[0].gain_offset_db + 1.5f) < 1e-6f, "gain offset -1.5 dB, got %g",
        c.instructions[1].group[0].gain_offset_db);
  CHECK(aac_unidrc_sequence_index(&c, 0, 0) == 0 && aac_unidrc_sequence_index(&c, 1, 0) == -1,
        "sequence lookup");

  const std::vector<uint8_t> loud = build_loudness({{0, -23.0f, -1.0f, 1.0f}, {2, -18.0f, -6.0f, -5.0f}},
                                                   {{0, -20.0f, -0.5f, 1.0f}});
  aac_bitreader_init(&r, loud.data(), (int)loud.size());
  AacUniDrcLoudnessSet l;
  CHECK(aac_unidrc_parse_loudness(&r, &l) == AAC_OK, "parse built loudnessInfoSet");
  CHECK(l.album_count == 1 && l.count == 2, "loudness list sizes %d %d", l.album_count, l.count);
  CHECK(fabsf(l.album[0].m[0].value + 20.0f) < 1e-4f, "album loudness");
  CHECK(l.info[1].true_peak_present && fabsf(l.info[1].true_peak_db + 5.0f) < 0.02f, "true peak");
  CHECK(l.info[1].drc_set_id == 2 && fabsf(l.info[1].m[0].value + 18.0f) < 1e-4f, "set 2 loudness");
}

/* ── gain payload decoding ─────────────────────────────────────── */

static AacUniDrcConfig g_cfg_stereo;

static int parse_gain_bits(const char* bits, int frame_length, AacUniDrcGainFrame* g, int* used) {
  Bits b;
  b.bits(bits);
  const std::vector<uint8_t> bytes = b.bytes();
  AacBitReader r;
  aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
  const int err = aac_unidrc_parse_gain(&g_cfg_stereo, &r, (int)bytes.size() * 8, frame_length, 48000, g);
  *used = r.pos;
  return err;
}

static void test_gain_decoding() {
  AacBitReader r;
  const std::vector<uint8_t> cfg = from_hex(kConfigStereo);
  aac_bitreader_init(&r, cfg.data(), (int)cfg.size());
  CHECK(aac_unidrc_parse_config(&r, &g_cfg_stereo) == AAC_OK, "stereo config");
  AacUniDrcGainFrame g;
  int used;

  /* The compact mode: one node at the frame end with an absolute gain,
   * then the extension flag; the FDK encoder writes this in every
   * steady frame. */
  CHECK(parse_gain_bits("0 1 00010000 0", 1024, &g, &used) == AAC_OK, "compact frame");
  CHECK(used == 11, "compact frame is 11 bits, used %d", used);
  CHECK(g.sequence_count == 1 && g.seq[0].node_count == 1, "one node");
  CHECK(g.seq[0].node[0].time == 1023 && fabsf(g.seq[0].node[0].gain_db + 2.0f) < 1e-6f,
        "node at 1023 with -2 dB, got %d %g", g.seq[0].node[0].time, g.seq[0].node[0].gain_db);
  CHECK(parse_gain_bits("0 0 00100001 0", 2048, &g, &used) == AAC_OK, "compact frame 2048");
  CHECK(g.seq[0].node[0].time == 2047 && fabsf(g.seq[0].node[0].gain_db - 4.125f) < 1e-6f,
        "node at 2047 with +4.125 dB");

  /* Node mode: unary node count, frameEndFlag, time deltas with the
   * two-bit prefix code, absolute first gain, Huffman deltas. */
  CHECK(parse_gain_bits("1 1 1 1 00010000 0", 1024, &g, &used) == AAC_OK, "one node at the frame end");
  CHECK(g.seq[0].node_count == 1 && g.seq[0].node[0].time == 1023, "frame end node");
  CHECK(parse_gain_bits("1 1 0 00 1 00010000 0", 1024, &g, &used) == AAC_OK, "one node at delta 1");
  CHECK(g.seq[0].node[0].time == 31, "delta 1 is sample 31, got %d", g.seq[0].node[0].time);
  CHECK(parse_gain_bits("1 1 0 0111 1 00010000 0", 1024, &g, &used) == AAC_OK, "delta 5");
  CHECK(g.seq[0].node[0].time == 159, "delta 5 is sample 159, got %d", g.seq[0].node[0].time);
  CHECK(parse_gain_bits("1 1 0 10000 1 00010000 0", 1024, &g, &used) == AAC_OK, "delta 6");
  CHECK(g.seq[0].node[0].time == 191, "delta 6 is sample 191, got %d", g.seq[0].node[0].time);
  CHECK(parse_gain_bits("1 1 0 11 000010 1 00010000 0", 1024, &g, &used) == AAC_OK, "delta 16");
  CHECK(g.seq[0].node[0].time == 511, "delta 16 is sample 511, got %d", g.seq[0].node[0].time);
  /* The FDK encoder's frame before the frame before an IPF: the node at
   * the frame middle, with the Z-bit escape widening per frame length. */
  CHECK(parse_gain_bits("1 1 0 11 0010010 000000000 0", 2048, &g, &used) == AAC_OK, "2048 mid frame");
  CHECK(g.seq[0].node[0].time == 1023 && used == 22, "node at 1023 in 22 bits, got %d %d",
        g.seq[0].node[0].time, used);
  CHECK(parse_gain_bits("1 1 0 11 00110010 000000000 0", 4096, &g, &used) == AAC_OK, "4096 mid frame");
  CHECK(g.seq[0].node[0].time == 2047 && used == 23, "node at 2047 in 23 bits");
  /* Three nodes: delta 2, delta 6 and the frame end, gains -2, -4, -6. */
  CHECK(parse_gain_bits("1 001 1 0100 10000 100010000 0000 0000 0", 1024, &g, &used) == AAC_OK,
        "three nodes");
  CHECK(g.seq[0].node_count == 3, "three nodes, got %d", g.seq[0].node_count);
  CHECK(g.seq[0].node[0].time == 63 && g.seq[0].node[1].time == 255 && g.seq[0].node[2].time == 1023,
        "three node times %d %d %d", g.seq[0].node[0].time, g.seq[0].node[1].time,
        g.seq[0].node[2].time);
  CHECK(fabsf(g.seq[0].node[1].gain_db + 4.0f) < 1e-6f && fabsf(g.seq[0].node[2].gain_db + 6.0f) < 1e-6f,
        "delta code 0000 is -2 dB: %g %g", g.seq[0].node[1].gain_db, g.seq[0].node[2].gain_db);
  /* The delta gain codes in 1/8 dB. */
  struct {
    const char* code;
    int units;
  } deltas[] = {{"10", 1},          {"11", -1},          {"010", 0},        {"001", -2},
                {"00010", -4},      {"01110", 8},        {"01111", -3},     {"000110", 3},
                {"011000", 2},      {"011001", -5},      {"0001111", -8},   {"0110100", -7},
                {"0110110", -6},    {"0110111", 4},      {"00011101", 5},   {"000111001", -15},
                {"011010101", -9},  {"011010100", 7},    {"011010111", 6},  {"0001110000", -12},
                {"0110101100", -11}, {"0110101101", -10}, {"00011100010", -14}, {"00011100011", -13},
                {"0000", -16}};
  for (const auto& d : deltas) {
    std::string bits = std::string("1 01 1 0100 100010000 ") + d.code + " 0";
    CHECK(parse_gain_bits(bits.c_str(), 1024, &g, &used) == AAC_OK, "delta code %s", d.code);
    CHECK(g.seq[0].node_count == 2 &&
              fabsf(g.seq[0].node[1].gain_db - (-2.0f + 0.125f * (float)d.units)) < 1e-5f,
          "delta code %s is %d/8 dB, got %g", d.code, d.units, g.seq[0].node[1].gain_db + 2.0f);
    CHECK(used == 1 + 2 + 1 + 4 + 9 + (int)strlen(d.code) + 1, "delta code %s bit count %d", d.code, used);
  }
  /* The gain extension is skipped by its size. */
  CHECK(parse_gain_bits("0 1 00010000 1 0001 0100 00000011 1010 0000", 1024, &g, &used) == AAC_OK,
        "gain extension");
  CHECK(used == 11 + 4 + 4 + 8 + 4 + 4, "gain extension bit count %d", used);
  /* Payload bounds: a truncated payload fails. */
  {
    Bits b;
    b.bits("1 001 1 0100 10000 100010000 0000");
    const std::vector<uint8_t> bytes = b.bytes();
    aac_bitreader_init(&r, bytes.data(), (int)bytes.size());
    CHECK(aac_unidrc_parse_gain(&g_cfg_stereo, &r, 20, 1024, 48000, &g) == AAC_ERR_DECODE,
          "payload bound enforced");
  }
}

/* ── the committed streams ─────────────────────────────────────── */

static void test_stream(const char* name, float program_lufs, float expected_gain_db) {
  const std::string path = std::string(BAAC_TEST_DATA_DIR) + "/usac_" + name + ".loas";
  const Stream s = read_loas(read_file(path));
  CHECK(s.aus.size() == 48, "%s: 48 access units, got %zu", name, s.aus.size());
  CHECK(!s.drc_config.empty() && !s.loudness.empty(), "%s: DRC config and loudness", name);
  CHECK(s.drc_default_length == 2, "%s: usacExtElementDefaultLength 2", name);
  AacUniDrc* d = aac_unidrc_create();
  CHECK(aac_unidrc_set_config(d, s.drc_config.data(), (int)s.drc_config.size(), s.loudness.data(),
                              (int)s.loudness.size()) == AAC_OK,
        "%s: set_config", name);
  aac_unidrc_set_stream(d, s.rate, s.channels, s.frame_length);
  int pl, ng, id;
  aac_unidrc_status(d, &pl, &ng, &id);
  CHECK(pl == (int)lrintf(program_lufs * 100.0f), "%s: program loudness %d", name, pl);
  CHECK(ng == (int)lrintf(expected_gain_db * 100.0f), "%s: normalisation gain %d", name, ng);
  CHECK(id == -1, "%s: no DRC set with the default request, got %d", name, id);
  const float expected = powf(10.0f, expected_gain_db / 20.0f);
  std::vector<float> pcm((size_t)s.frame_length * s.channels, 1.0f);
  std::vector<float*> ch(s.channels);
  int frames_with_gain = 0, nodes_total = 0;
  for (size_t f = 0; f < s.aus.size(); f++) {
    const std::vector<uint8_t>& au = s.aus[f];
    AacBitReader r;
    aac_bitreader_init(&r, au.data(), (int)au.size());
    const int indep = (int)aac_bitreader_read(&r, 1);
    int bits;
    if (ext_element(&r, 0, &bits)) { /* AudioPreRoll */
      aac_bitreader_skip(&r, bits);
    }
    if (ext_element(&r, s.drc_default_length, &bits)) {
      const int start = r.pos;
      AacUniDrcGainFrame g;
      AacBitReader probe = r;
      CHECK(aac_unidrc_parse_gain(aac_unidrc_config(d), &probe, bits, s.frame_length, s.rate, &g) ==
                AAC_OK,
            "%s: frame %zu gain parse", name, f);
      CHECK(probe.pos - start <= bits && probe.pos - start > bits - 8,
            "%s: frame %zu used %d of %d payload bits", name, f, probe.pos - start, bits);
      for (int q = 0; q < g.seq[0].node_count; q++) {
        CHECK(g.seq[0].node[q].gain_db == 0.0f, "%s: frame %zu node %d is 0 dB", name, f, q);
        nodes_total++;
      }
      CHECK(aac_unidrc_read_gain(d, &r, bits, indep) == AAC_OK, "%s: frame %zu read_gain", name, f);
      CHECK(r.pos == start + bits, "%s: frame %zu reader past the payload", name, f);
      frames_with_gain++;
    } else {
      aac_unidrc_no_gain(d);
    }
    for (int c = 0; c < s.channels; c++) {
      ch[c] = pcm.data() + (size_t)c * s.frame_length;
      for (int t = 0; t < s.frame_length; t++) {
        ch[c][t] = 1.0f;
      }
    }
    aac_unidrc_apply(d, ch.data(), s.channels, s.frame_length);
    float lo = 10.0f, hi = -10.0f;
    for (float v : pcm) {
      lo = std::min(lo, v);
      hi = std::max(hi, v);
    }
    CHECK(fabsf(lo - expected) < 1e-4f && fabsf(hi - expected) < 1e-4f,
          "%s: frame %zu applies %g (range %g..%g)", name, f, expected, lo, hi);
  }
  CHECK(frames_with_gain == 48, "%s: every frame carries uniDrcGain, %d did", name, frames_with_gain);
  CHECK(nodes_total == 44 + 2 * 1 + 2 * 2, "%s: 50 nodes over 48 frames, got %d", name, nodes_total);
  aac_unidrc_destroy(d);
}

/* ── selection ─────────────────────────────────────────────────── */

static AacUniDrcRequest request(int effect, int target_x100 = -2400, int norm = 1, int album = 0,
                                int limiter = 1, int boost = 100, int cut = 100) {
  AacUniDrcRequest q;
  q.loudness_normalization = norm;
  q.target_loudness_x100 = target_x100;
  q.effect = effect;
  q.album_mode = album;
  q.boost_percent = boost;
  q.cut_percent = cut;
  q.peak_limiter = limiter;
  return q;
}

static int selected_id(AacUniDrc* d) {
  int id;
  aac_unidrc_status(d, nullptr, nullptr, &id);
  return id;
}

static int norm_gain(AacUniDrc* d) {
  int ng;
  aac_unidrc_status(d, nullptr, &ng, nullptr);
  return ng;
}

static void test_selection_kit() {
  const std::vector<uint8_t> cfg = from_hex(kConfigStereo);
  const std::vector<uint8_t> loud = from_hex(kLoudMusic48);
  AacUniDrc* d = aac_unidrc_create();
  CHECK(aac_unidrc_set_config(d, cfg.data(), (int)cfg.size(), loud.data(), (int)loud.size()) == AAC_OK,
        "set_config");
  aac_unidrc_set_stream(d, 48000, 2, 2048);
  CHECK(selected_id(d) == -1 && norm_gain(d) == -975, "default: no set, -9.75 dB (%d, %d)",
        selected_id(d), norm_gain(d));
  struct {
    int effect, id;
  } cases[] = {{AAC_DRC_EFFECT_NIGHT, 2},    {AAC_DRC_EFFECT_NOISY, 1},  {AAC_DRC_EFFECT_LIMITED, 2},
               {AAC_DRC_EFFECT_LOWLEVEL, 1}, {AAC_DRC_EFFECT_DIALOG, 2}, {AAC_DRC_EFFECT_GENERAL, 3},
               {AAC_DRC_EFFECT_NONE, -1},    {AAC_DRC_EFFECT_OFF, -1}};
  for (const auto& c : cases) {
    AacUniDrcRequest q = request(c.effect);
    aac_unidrc_set_request(d, &q);
    CHECK(selected_id(d) == c.id, "effect %d selects set %d, got %d", c.effect, c.id, selected_id(d));
  }
  /* GENERAL with a target below -27 LUFS takes the set made for it. */
  AacUniDrcRequest q = request(AAC_DRC_EFFECT_GENERAL, -3000);
  aac_unidrc_set_request(d, &q);
  CHECK(selected_id(d) == 4 && norm_gain(d) == -1575, "GENERAL at -30 LUFS: set 4, -15.75 dB");
  /* Album mode without album entries falls back to the program loudness. */
  q = request(AAC_DRC_EFFECT_NONE, -2400, 1, 1);
  aac_unidrc_set_request(d, &q);
  CHECK(norm_gain(d) == -975, "album mode falls back to the program loudness");
  /* Normalisation off: unity. */
  q = request(AAC_DRC_EFFECT_NONE, -2400, 0);
  aac_unidrc_set_request(d, &q);
  CHECK(norm_gain(d) == 0, "normalisation off");
  int pl;
  aac_unidrc_status(d, &pl, nullptr, nullptr);
  CHECK(pl == -1425, "program loudness still reported, got %d", pl);
  /* Peak protection: at -10 LUFS the gain would be +4.25 dB and the
   * -3.09 dBFS sample peak would clip; the cap keeps it at 0 dBFS. */
  q = request(AAC_DRC_EFFECT_NONE, -1000);
  aac_unidrc_set_request(d, &q);
  CHECK(norm_gain(d) == 309, "peak protection caps at +3.09 dB, got %d", norm_gain(d));
  q = request(AAC_DRC_EFFECT_NONE, -1000, 1, 0, 0);
  aac_unidrc_set_request(d, &q);
  CHECK(norm_gain(d) == 425, "without peak protection +4.25 dB, got %d", norm_gain(d));
  /* The applied gain with a DRC set selected and all nodes at 0 dB is
   * the normalisation gain. */
  q = request(AAC_DRC_EFFECT_NIGHT);
  aac_unidrc_set_request(d, &q);
  float gains[2048];
  aac_unidrc_channel_gain(d, 0, gains, 2048);
  CHECK(fabsf(gains[0] - powf(10.0f, -9.75f / 20.0f)) < 1e-5f && fabsf(gains[2047] - gains[0]) < 1e-6f,
        "flat normalisation gain with a 0 dB DRC set");
  /* Effect off: unity even with loudness metadata. */
  q = request(AAC_DRC_EFFECT_OFF);
  aac_unidrc_set_request(d, &q);
  aac_unidrc_channel_gain(d, 0, gains, 2048);
  CHECK(gains[0] == 1.0f && gains[1000] == 1.0f, "effect off leaves the signal");
  /* Reset forgets the stream. */
  aac_unidrc_reset(d);
  CHECK(norm_gain(d) == 0 && selected_id(d) == -1, "reset");
  aac_unidrc_status(d, &pl, nullptr, nullptr);
  CHECK(pl == INT32_MIN, "no loudness after reset");
  /* A stream without metadata: the request has nothing to act on. */
  CHECK(aac_unidrc_set_config(d, nullptr, 0, nullptr, 0) == AAC_OK, "empty config");
  CHECK(norm_gain(d) == 0 && selected_id(d) == -1, "no metadata: unity");
  aac_unidrc_destroy(d);
}

static void test_selection_built() {
  const std::vector<SetSpec> sets = {
      {AAC_UNIDRC_EFFECT_CLIPPING, -1.0f, -1, -1, -1, 1, 1},
      {AAC_UNIDRC_EFFECT_NIGHT, 1.0f, 4, 6, 0x25, 1, 1},
      {AAC_UNIDRC_EFFECT_GENERAL, 1.0f, -1, -1, -1, -20, -31},
      {AAC_UNIDRC_EFFECT_GENERAL, 1.0f, -1, -1, -1, -10, -19},
  };
  const std::vector<uint8_t> cfg = build_config_v0(2, sets);
  const std::vector<uint8_t> loud = build_loudness({{0, -23.0f, -1.0f, 1.0f}, {2, -18.0f, -6.0f, -5.0f}},
                                                   {{0, -20.0f, -0.5f, 1.0f}});
  AacUniDrc* d = aac_unidrc_create();
  CHECK(aac_unidrc_set_config(d, cfg.data(), (int)cfg.size(), loud.data(), (int)loud.size()) == AAC_OK,
        "set built config");
  aac_unidrc_set_stream(d, 48000, 2, 1024);
  /* Default request: -24 target against -23 LUFS, no clipping, no set. */
  CHECK(selected_id(d) == -1 && norm_gain(d) == -100, "default: -1 dB, no set (%d %d)", selected_id(d),
        norm_gain(d));
  /* A loud target would push the -1 dBFS peak over full scale: the
   * clipping prevention set is selected and its limiter target caps the
   * gain at -1 dBFS. */
  AacUniDrcRequest q = request(AAC_DRC_EFFECT_NONE, -1600);
  aac_unidrc_set_request(d, &q);
  CHECK(selected_id(d) == 1, "clipping prevention set, got %d", selected_id(d));
  CHECK(norm_gain(d) == 0, "gain capped at the -1 dBFS limiter target, got %d", norm_gain(d));
  /* The NIGHT set has its own loudnessInfo (-18 LUFS, true peak -5). */
  q = request(AAC_DRC_EFFECT_NIGHT);
  aac_unidrc_set_request(d, &q);
  CHECK(selected_id(d) == 2 && norm_gain(d) == -600, "NIGHT: set 2 with its own loudness (%d %d)",
        selected_id(d), norm_gain(d));
  CHECK(aac_unidrc_selected_loudness(d) == &aac_unidrc_loudness(d)->info[1], "set-specific loudnessInfo");
  q = request(AAC_DRC_EFFECT_NIGHT, -1000);
  aac_unidrc_set_request(d, &q);
  CHECK(norm_gain(d) == 500, "true peak -5 dBFS caps the gain at +5 dB, got %d", norm_gain(d));
  /* Album mode takes the album loudness. */
  q = request(AAC_DRC_EFFECT_NONE, -2400, 1, 1);
  aac_unidrc_set_request(d, &q);
  CHECK(norm_gain(d) == -400, "album loudness -20 LUFS: -4 dB, got %d", norm_gain(d));
  /* GENERAL: the set whose target range holds the target. */
  q = request(AAC_DRC_EFFECT_GENERAL, -2400);
  aac_unidrc_set_request(d, &q);
  CHECK(selected_id(d) == 3, "GENERAL at -24 picks the -31..-20 set, got %d", selected_id(d));
  q = request(AAC_DRC_EFFECT_GENERAL, -1400);
  aac_unidrc_set_request(d, &q);
  CHECK(selected_id(d) == 4, "GENERAL at -14 picks the -19..-10 set, got %d", selected_id(d));
  /* Fallback: NOISY is absent, NIGHT is its first alternative; LOWLEVEL
   * and DIALOG also land on NIGHT before GENERAL. */
  q = request(AAC_DRC_EFFECT_NOISY);
  aac_unidrc_set_request(d, &q);
  CHECK(selected_id(d) == 2, "NOISY falls back to NIGHT, got %d", selected_id(d));
  q = request(AAC_DRC_EFFECT_DIALOG);
  aac_unidrc_set_request(d, &q);
  CHECK(selected_id(d) == 3, "DIALOG falls back to GENERAL first, got %d", selected_id(d));
  aac_unidrc_destroy(d);

  /* The gain modifiers and the request's boost and cut. */
  const std::vector<SetSpec> one = {{AAC_UNIDRC_EFFECT_NIGHT, 1.0f, 4, 6, 0x25, 1, 1}};
  const std::vector<uint8_t> cfg1 = build_config_v0(1, one);
  d = aac_unidrc_create();
  CHECK(aac_unidrc_set_config(d, cfg1.data(), (int)cfg1.size(), nullptr, 0) == AAC_OK, "mono config");
  aac_unidrc_set_stream(d, 48000, 1, 1024);
  q = request(AAC_DRC_EFFECT_NIGHT, -2400, 0);
  aac_unidrc_set_request(d, &q);
  CHECK(selected_id(d) == 1, "NIGHT set");
  /* -8 dB at the frame end: attenuation scaling 0.5 and offset -1.5 give
   * -5.5 dB at the last sample, from 0 dB (offset -1.5) at the start. */
  Bits b;
  b.bits("0 1 01000000 0");
  const std::vector<uint8_t> payload = b.bytes();
  AacBitReader r;
  aac_bitreader_init(&r, payload.data(), (int)payload.size());
  CHECK(aac_unidrc_read_gain(d, &r, (int)payload.size() * 8, 1) == AAC_OK, "read -8 dB node");
  float gains[1024];
  aac_unidrc_channel_gain(d, 0, gains, 1024);
  CHECK(fabsf(gains[1023] - powf(10.0f, -5.5f / 20.0f)) < 1e-4f, "scaled and offset node gain, got %g dB",
        20.0f * log10f(gains[1023]));
  CHECK(fabsf(gains[0] - powf(10.0f, -1.5f / 20.0f)) < 2e-3f, "start of the ramp near the offset, got %g dB",
        20.0f * log10f(gains[0]));
  q = request(AAC_DRC_EFFECT_NIGHT, -2400, 0, 0, 1, 100, 50);
  aac_unidrc_set_request(d, &q);
  aac_unidrc_channel_gain(d, 0, gains, 1024);
  CHECK(fabsf(gains[1023] - powf(10.0f, -3.5f / 20.0f)) < 1e-4f, "cut 50%% halves the attenuation, got %g dB",
        20.0f * log10f(gains[1023]));
  /* +8 dB: amplification scaling 0.75, boost 50 %, offset -1.5. */
  aac_unidrc_apply(d, nullptr, 0, 1024);
  Bits b2;
  b2.bits("0 0 01000000 0");
  const std::vector<uint8_t> payload2 = b2.bytes();
  aac_bitreader_init(&r, payload2.data(), (int)payload2.size());
  CHECK(aac_unidrc_read_gain(d, &r, (int)payload2.size() * 8, 0) == AAC_OK, "read +8 dB node");
  q = request(AAC_DRC_EFFECT_NIGHT, -2400, 0, 0, 1, 50, 100);
  aac_unidrc_set_request(d, &q);
  aac_unidrc_channel_gain(d, 0, gains, 1024);
  CHECK(fabsf(gains[1023] - powf(10.0f, 1.5f / 20.0f)) < 1e-4f, "boost 50%% of 0.75 of +8 dB minus 1.5, got %g dB",
        20.0f * log10f(gains[1023]));
  aac_unidrc_destroy(d);
}

/* ── interpolation and application ─────────────────────────────── */

static void test_interpolation() {
  const std::vector<SetSpec> one = {{AAC_UNIDRC_EFFECT_NIGHT, 1.0f, -1, -1, -1, 1, 1}};
  const std::vector<uint8_t> cfg = build_config_v0(2, one);
  AacUniDrc* d = aac_unidrc_create();
  CHECK(aac_unidrc_set_config(d, cfg.data(), (int)cfg.size(), nullptr, 0) == AAC_OK, "config");
  aac_unidrc_set_stream(d, 48000, 2, 1024);
  AacUniDrcRequest q = request(AAC_DRC_EFFECT_NIGHT, -2400, 0);
  aac_unidrc_set_request(d, &q);
  auto feed = [&](const char* bits) {
    Bits b;
    b.bits(bits);
    const std::vector<uint8_t> p = b.bytes();
    AacBitReader r;
    aac_bitreader_init(&r, p.data(), (int)p.size());
    CHECK(aac_unidrc_read_gain(d, &r, (int)p.size() * 8, 0) == AAC_OK, "feed %s", bits);
  };
  std::vector<float> l(1024), rch(1024);
  float* ch[2] = {l.data(), rch.data()};
  auto fill = [&]() {
    std::fill(l.begin(), l.end(), 1.0f);
    std::fill(rch.begin(), rch.end(), 0.5f);
  };
  /* A -20 dB node at the frame end: the linear gain ramps linearly from
   * 1 to 0.1, so the frame middle sits near 0.55 (not -10 dB). */
  feed("0 1 10100000 0");
  fill();
  aac_unidrc_apply(d, ch, 2, 1024);
  CHECK(fabsf(l[1023] - 0.1f) < 1e-3f, "last sample at -20 dB, got %g", l[1023]);
  CHECK(fabsf(l[511] - 0.55f) < 0.01f, "middle of the ramp at 0.55, got %g", l[511]);
  CHECK(fabsf(rch[511] - 0.275f) < 0.005f, "both channels of the group, got %g", rch[511]);
  /* The next frame continues from -20 dB: a node at sample 511 with
   * 0 dB and the end at -2 dB (delta code 0000). */
  feed("1 01 1 11 000010 000000000 0000 0");
  fill();
  aac_unidrc_apply(d, ch, 2, 1024);
  CHECK(fabsf(l[0] - (0.1f + 0.9f / 512.0f)) < 1e-3f, "continues from the previous node, got %g", l[0]);
  CHECK(fabsf(l[511] - 1.0f) < 1e-4f, "0 dB at sample 511, got %g", l[511]);
  const float minus2 = powf(10.0f, -2.0f / 20.0f);
  CHECK(fabsf(l[1023] - minus2) < 1e-3f, "-2 dB at the frame end, got %g", l[1023]);
  CHECK(fabsf(l[767] - (1.0f + minus2) / 2.0f) < 1e-3f, "linear between the nodes, got %g", l[767]);
  /* A frame without payload holds the last gain. */
  aac_unidrc_no_gain(d);
  fill();
  aac_unidrc_apply(d, ch, 2, 1024);
  CHECK(fabsf(l[0] - minus2) < 1e-3f && fabsf(l[1023] - minus2) < 1e-3f, "hold without payload: %g %g",
        l[0], l[1023]);
  /* A node beyond the frame end (as the FDK encoder writes before an
   * IPF) shapes this frame and the next one. */
  feed("1 01 0 11 010010 11 010010 000000000 0000 0");
  fill();
  aac_unidrc_apply(d, ch, 2, 1024);
  CHECK(fabsf(l[1023] - 1.0f) < 1e-3f, "0 dB at this frame end, got %g", l[1023]);
  feed("0 0 00000000 0");
  fill();
  aac_unidrc_apply(d, ch, 2, 1024);
  CHECK(fabsf(l[511] - (1.0f + minus2) / 2.0f) < 2e-3f, "ramps toward the carried-over node at -2 dB, got %g",
        l[511]);
  aac_unidrc_destroy(d);
}

/* ── fuzz ──────────────────────────────────────────────────────── */

static void test_fuzz() {
  unsigned seed = 12345u;
  auto rnd = [&]() {
    seed = seed * 1664525u + 1013904223u;
    return seed >> 8;
  };
  const std::vector<uint8_t> cfg = from_hex(kConfigStereo);
  const std::vector<uint8_t> loud = from_hex(kLoudMusic48);
  const std::vector<SetSpec> sets = {{AAC_UNIDRC_EFFECT_NIGHT, -1.0f, 4, 6, 0x25, -20, -31}};
  const std::vector<uint8_t> cfg0 = build_config_v0(2, sets, 0, 7);
  AacUniDrc* d = aac_unidrc_create();
  int ok = 0, bad = 0;
  for (int iter = 0; iter < 3000; iter++) {
    std::vector<uint8_t> c = (iter & 1) ? cfg : cfg0;
    std::vector<uint8_t> l = loud;
    const int flips = 1 + (int)(rnd() % 4);
    for (int k = 0; k < flips; k++) {
      std::vector<uint8_t>& v = (rnd() & 1) ? c : l;
      v[rnd() % v.size()] ^= (uint8_t)(1u << (rnd() & 7));
    }
    if (rnd() % 8 == 0) {
      c.resize(rnd() % c.size());
    }
    const int err = aac_unidrc_set_config(d, c.data(), (int)c.size(), l.data(), (int)l.size());
    err == AAC_OK ? ok++ : bad++;
    aac_unidrc_set_stream(d, 48000, 2, 1024 << (rnd() % 3));
    AacUniDrcRequest q = request((int)(rnd() % 8) - 1, -3100 + (int)(rnd() % 2100), (int)(rnd() & 1),
                                 (int)(rnd() & 1), (int)(rnd() & 1), (int)(rnd() % 101), (int)(rnd() % 101));
    aac_unidrc_set_request(d, &q);
    std::vector<float> pcm(2 * 4096, 0.5f);
    float* ch[2] = {pcm.data(), pcm.data() + 4096};
    for (int f = 0; f < 3; f++) {
      uint8_t payload[16];
      const int n = 1 + (int)(rnd() % 8);
      for (int i = 0; i < n; i++) {
        payload[i] = (uint8_t)rnd();
      }
      AacBitReader r;
      aac_bitreader_init(&r, payload, n);
      aac_unidrc_read_gain(d, &r, n * 8, f == 0);
      aac_unidrc_apply(d, ch, 2, 1024 << (f % 3));
    }
    for (float v : pcm) {
      CHECK(std::isfinite(v), "finite output under fuzz (iteration %d)", iter);
      if (!std::isfinite(v)) {
        break;
      }
    }
  }
  CHECK(ok > 0 && bad > 0, "fuzz parsed %d and refused %d configurations", ok, bad);
  /* Random gain payloads on the real configuration never fault. */
  CHECK(aac_unidrc_set_config(d, cfg.data(), (int)cfg.size(), loud.data(), (int)loud.size()) == AAC_OK,
        "restore config");
  aac_unidrc_set_stream(d, 48000, 2, 2048);
  AacUniDrcRequest q = request(AAC_DRC_EFFECT_NIGHT);
  aac_unidrc_set_request(d, &q);
  int parsed = 0;
  for (int iter = 0; iter < 3000; iter++) {
    uint8_t payload[8];
    for (int i = 0; i < 8; i++) {
      payload[i] = (uint8_t)rnd();
    }
    AacBitReader r;
    aac_bitreader_init(&r, payload, 8);
    const int bits = 1 + (int)(rnd() % 64);
    if (aac_unidrc_read_gain(d, &r, bits, 0) == AAC_OK) {
      parsed++;
    }
    std::vector<float> pcm(2 * 2048, 0.5f);
    float* ch[2] = {pcm.data(), pcm.data() + 2048};
    aac_unidrc_apply(d, ch, 2, 2048);
    for (float v : pcm) {
      CHECK(std::isfinite(v), "finite output under payload fuzz");
      if (!std::isfinite(v)) break;
    }
  }
  CHECK(parsed > 0, "some random payloads parse (%d)", parsed);
  aac_unidrc_destroy(d);
}

int main() {
  test_config_parsing();
  test_built_config_parsing();
  test_gain_decoding();
  test_stream("music_48000_64000", -14.25f, -9.75f);
  test_stream("speech_48000_24000", -19.25f, -4.75f);
  test_selection_kit();
  test_selection_built();
  test_interpolation();
  test_fuzz();
  return test_summary("test_unidrc");
}
