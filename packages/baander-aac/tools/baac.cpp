/* baac: command-line encoder/decoder for baander-aac.
 *
 *   baac enc <in.f32le> <out.aac> <rate> <channels> [bitrate] [mode] [quality] [profile] [options]
 *   baac dec <in.aac> <out.f32le> [rate] [channels] [--no-loudness]
 *   baac loudness <in.wav | in.f32le rate channels>
 *   baac bench [--seconds N] [--dump DIR] [--only NAME] [--streams DIR]
 *
 * Raw PCM is interleaved 32-bit float; the AAC side is an ADTS stream
 * (a LOAS stream for the xhe profile, which ADTS cannot carry).
 * mode: cbr (default), abr, cvbr, tvbr.  For every profile,
 * --peak-bitrate N caps the bits of any frame at its share of N bit/s,
 * --bandwidth HZ sets the core's lowpass (no effect on an SBR core) and
 * --vbr-mode 1..5 picks one of the FDK-named VBR modes, whose target
 * replaces the bitrate, and --afterburner turns on the second
 * quantisation pass.  The xhe options: --loudness
 * measures the whole input first and writes the loudness info set into
 * the stream; --sbr and --mps (on, off, auto), --no-noise-filling,
 * --preroll off and --stream-id N set the USAC encoder options;
 * --rap-ms N, --rap-samples N and --rap-max-frames N set the independent
 * frame schedule and --segment-max-bytes N the segment size ceiling;
 * --drc writes the MPEG-D DRC gain sets (general compression and night)
 * aiming at the loudness range of --drc-range LU (6 to 16, default 10),
 * and with --loudness a further pass measures each set's output for the
 * loudness info set.  The
 * decoder follows the ADTS headers, so rate and channels only set the
 * output layout (0 keeps the stream's channels); --no-loudness turns off
 * the loudness normalisation of USAC streams.  loudness prints the
 * meter's figures of a WAV file (PCM or float) or of raw float input.
 * bench encodes and decodes a synthetic stereo signal at 48 kHz (30 s by
 * default) through every profile and prints the times and realtime
 * factors; --dump writes each stream and its decode to DIR for
 * comparisons between builds; --streams decodes every .aac and .loas
 * stream in DIR (the committed test streams) and prints the realtime
 * factor of each and of each profile. */
#include <algorithm>
#include <chrono>
#include <filesystem>
#include <map>
#include <cmath>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>
#include <vector>

#include "aac.h"

static std::vector<uint8_t> read_all(const char* path) {
  std::vector<uint8_t> data;
  FILE* f = strcmp(path, "-") == 0 ? stdin : fopen(path, "rb");
  if (!f) {
    fprintf(stderr, "baac: cannot open %s\n", path);
    exit(1);
  }
  uint8_t buf[65536];
  size_t n;
  while ((n = fread(buf, 1, sizeof(buf), f)) > 0) {
    data.insert(data.end(), buf, buf + n);
  }
  if (f != stdin) {
    fclose(f);
  }
  return data;
}

static FILE* open_out(const char* path) {
  FILE* f = strcmp(path, "-") == 0 ? stdout : fopen(path, "wb");
  if (!f) {
    fprintf(stderr, "baac: cannot create %s\n", path);
    exit(1);
  }
  return f;
}

static int usage() {
  fprintf(stderr,
          "usage: baac enc <in.f32le> <out.aac> <rate> <channels> [bitrate] [cbr|abr|cvbr|tvbr] "
          "[quality] [lc|he|hev2|xhe] [options]\n"
          "         options: --peak-bitrate N  --bandwidth HZ  --vbr-mode 1..5  --afterburner\n"
          "         xhe options: --loudness (two-pass: measure the input, write the loudness "
          "info set)\n"
          "                      --sbr on|off|auto  --mps on|off|auto  --no-noise-filling\n"
          "                      --preroll off  --stream-id N\n"
          "                      --rap-ms N | --rap-samples N  (the independent frame interval)\n"
          "                      --rap-max-frames N  --segment-max-bytes N\n"
          "                      --drc  --drc-range LU  (MPEG-D DRC gain sets, 6 to 16 LU)\n"
          "       baac dec <in.aac> <out.f32le> [rate] [channels: 0 = as in the stream] [--no-loudness]\n"
          "       baac loudness <in.wav> | <in.f32le> <rate> <channels>\n"
          "       baac bench [--seconds N] [--dump DIR] [--only NAME] [--streams DIR]\n");
  return 2;
}

/* ── loudness ──────────────────────────────────────────────────── */

/* Measures interleaved float input with the loudness meter; false when
 * the meter refuses the layout or the input is shorter than one block. */
static bool measure_loudness(const std::vector<float>& pcm, int sr, int channels,
                             AacLoudnessInfo* info) {
  AacLoudnessMeter* m = aac_loudness_create(sr, channels);
  if (!m) {
    fprintf(stderr, "baac: the loudness meter takes 1 to 6 channels at 8 to 384 kHz\n");
    return false;
  }
  const size_t frames = pcm.size() / (size_t)channels;
  const size_t chunk = 4096;
  for (size_t pos = 0; pos < frames; pos += chunk) {
    aac_loudness_feed(m, pcm.data() + pos * (size_t)channels,
                      (int)std::min(chunk, frames - pos));
  }
  memset(info, 0, sizeof(*info));
  const int ret = aac_loudness_result(m, info);
  aac_loudness_destroy(m);
  if (ret != AAC_OK) {
    fprintf(stderr, "baac: the loudness measurement needs at least 400 ms of input\n");
    return false;
  }
  return true;
}

static void print_loudness(FILE* f, const AacLoudnessInfo& info) {
  fprintf(f, "integrated loudness   %7.2f LKFS\n", info.integrated_lkfs);
  if (info.has_range) {
    fprintf(f, "loudness range        %7.2f LU\n", info.range_lu);
  } else {
    fprintf(f, "loudness range        needs 3 s of input\n");
  }
  if (info.has_true_peak) {
    fprintf(f, "true peak             %7.2f dBTP\n", info.true_peak_dbtp);
  }
  fprintf(f, "sample peak           %7.2f dBFS\n", info.sample_peak_dbfs);
  if (info.has_momentary) {
    fprintf(f, "max momentary         %7.2f LKFS\n", info.max_momentary_lkfs);
  }
  if (info.has_short_term) {
    fprintf(f, "max short-term        %7.2f LKFS\n", info.max_short_term_lkfs);
  } else {
    fprintf(f, "max short-term        needs 3 s of input\n");
  }
}

static uint32_t le32(const uint8_t* p) {
  return (uint32_t)p[0] | (uint32_t)p[1] << 8 | (uint32_t)p[2] << 16 | (uint32_t)p[3] << 24;
}
static uint32_t le16(const uint8_t* p) { return (uint32_t)p[0] | (uint32_t)p[1] << 8; }

/* Reads a RIFF/WAVE file with integer PCM of 8 to 32 bits or 32-bit
 * float samples (plain or WAVE_FORMAT_EXTENSIBLE) into interleaved
 * float at full scale 1.0; false when the bytes are no such file. */
static bool read_wav(const std::vector<uint8_t>& data, std::vector<float>* pcm, int* sr,
                     int* channels) {
  if (data.size() < 12 || memcmp(data.data(), "RIFF", 4) != 0 ||
      memcmp(data.data() + 8, "WAVE", 4) != 0) {
    return false;
  }
  int format = 0;
  int bits = 0;
  size_t pos = 12;
  while (pos + 8 <= data.size()) {
    const uint8_t* chunk = data.data() + pos;
    const size_t size = le32(chunk + 4);
    const uint8_t* body = chunk + 8;
    const size_t avail = std::min(size, data.size() - pos - 8);
    if (memcmp(chunk, "fmt ", 4) == 0 && avail >= 16) {
      format = (int)le16(body);
      *channels = (int)le16(body + 2);
      *sr = (int)le32(body + 4);
      bits = (int)le16(body + 14);
      if (format == 0xFFFE && avail >= 26) {
        format = (int)le16(body + 24); /* the sub-format's first two bytes */
      }
    } else if (memcmp(chunk, "data", 4) == 0) {
      if (*channels <= 0 || *sr <= 0 || bits <= 0) {
        fprintf(stderr, "baac: the WAV data precedes its format\n");
        return false;
      }
      const bool is_float = format == 3 && bits == 32;
      const bool is_pcm = format == 1 && (bits == 8 || bits == 16 || bits == 24 || bits == 32);
      if (!is_float && !is_pcm) {
        fprintf(stderr, "baac: unsupported WAV format %d at %d bits\n", format, bits);
        return false;
      }
      const size_t bytes = (size_t)bits / 8;
      const size_t n = avail / bytes;
      pcm->resize(n);
      for (size_t i = 0; i < n; i++) {
        const uint8_t* p = body + i * bytes;
        float v = 0.0f;
        if (is_float) {
          uint32_t u = le32(p);
          memcpy(&v, &u, sizeof(v));
        } else if (bits == 8) {
          v = ((float)p[0] - 128.0f) / 128.0f;
        } else if (bits == 16) {
          v = (float)(int16_t)le16(p) / 32768.0f;
        } else if (bits == 24) {
          const int32_t x = (int32_t)((uint32_t)p[0] << 8 | (uint32_t)p[1] << 16 | (uint32_t)p[2] << 24);
          v = (float)(x >> 8) / 8388608.0f;
        } else {
          v = (float)(int32_t)le32(p) / 2147483648.0f;
        }
        (*pcm)[i] = v;
      }
      return true;
    }
    pos += 8 + size + (size & 1);
  }
  fprintf(stderr, "baac: no data chunk in the WAV file\n");
  return false;
}

static std::vector<float> floats_of(const std::vector<uint8_t>& in) {
  std::vector<float> pcm(in.size() / sizeof(float));
  memcpy(pcm.data(), in.data(), pcm.size() * sizeof(float));
  return pcm;
}

static int loudness(int argc, char** argv) {
  if (argc < 3) {
    return usage();
  }
  const std::vector<uint8_t> in = read_all(argv[2]);
  std::vector<float> pcm;
  int sr = 0;
  int channels = 0;
  const bool wav = in.size() >= 4 && memcmp(in.data(), "RIFF", 4) == 0;
  if (wav) {
    if (!read_wav(in, &pcm, &sr, &channels)) {
      return 1;
    }
  } else {
    if (argc < 5) {
      return usage();
    }
    sr = atoi(argv[3]);
    channels = atoi(argv[4]);
    pcm = floats_of(in);
  }
  if (sr <= 0 || channels <= 0) {
    return usage();
  }
  AacLoudnessInfo info;
  if (!measure_loudness(pcm, sr, channels, &info)) {
    return 1;
  }
  printf("%s: %d Hz, %d channels, %.2f s\n", argv[2], sr, channels,
         (double)pcm.size() / channels / sr);
  print_loudness(stdout, info);
  return 0;
}

/* ── encode ────────────────────────────────────────────────────── */

static AacUsacEncoderOptions default_usac_options() {
  AacUsacEncoderOptions o;
  aac_usac_encoder_options_init(&o);
  return o;
}

/* The --options of `baac enc`, taken out of argv before the positional
 * arguments are read. */
struct EncodeOptions {
  bool loudness = false;
  bool usac = false; /* any USAC option given */
  int peak_bitrate = 0;
  int bandwidth = 0;
  int vbr_mode = 0;
  bool afterburner = false;
  AacUsacEncoderOptions opt = default_usac_options();
};

/* A positive integer option value; false with a message otherwise. */
static bool positive_option(const char* name, const char* value, int* out) {
  char* end = nullptr;
  const long v = strtol(value, &end, 10);
  if (end == value || *end != '\0' || v <= 0 || v > 1000000000L) {
    fprintf(stderr, "baac: %s takes a positive count\n", name);
    return false;
  }
  *out = (int)v;
  return true;
}

static bool on_off_auto(const char* value, int* out) {
  const std::string v = value;
  if (v == "on") {
    *out = 1;
  } else if (v == "off") {
    *out = 0;
  } else if (v == "auto") {
    *out = -1;
  } else {
    return false;
  }
  return true;
}

static bool parse_encode_options(int* argc, char** argv, EncodeOptions* o) {
  int kept = 0;
  for (int i = 0; i < *argc; i++) {
    const std::string arg = argv[i];
    const bool has_value = i + 1 < *argc;
    if (arg == "--loudness") {
      o->loudness = true;
    } else if (arg == "--afterburner") {
      o->afterburner = true;
    } else if ((arg == "--peak-bitrate" || arg == "--bandwidth" || arg == "--vbr-mode") &&
               has_value) {
      if (!positive_option(argv[i], argv[i + 1],
                           arg == "--peak-bitrate" ? &o->peak_bitrate
                           : arg == "--bandwidth"  ? &o->bandwidth
                                                   : &o->vbr_mode)) {
        return false;
      }
      i++;
    } else if (arg == "--sbr" && has_value && on_off_auto(argv[i + 1], &o->opt.sbr)) {
      o->usac = true;
      i++;
    } else if (arg == "--mps" && has_value && on_off_auto(argv[i + 1], &o->opt.mps212)) {
      o->usac = true;
      i++;
    } else if (arg == "--no-noise-filling") {
      o->usac = true;
      o->opt.noise_filling = 0;
    } else if (arg == "--preroll" && has_value && strcmp(argv[i + 1], "off") == 0) {
      o->usac = true;
      o->opt.preroll = 0;
      i++;
    } else if (arg == "--rap-ms" && has_value) {
      if (!positive_option("--rap-ms", argv[i + 1], &o->opt.independent_interval_ms)) {
        return false;
      }
      o->usac = true;
      i++;
    } else if (arg == "--rap-samples" && has_value) {
      if (!positive_option("--rap-samples", argv[i + 1], &o->opt.independent_interval_samples)) {
        return false;
      }
      o->usac = true;
      i++;
    } else if (arg == "--rap-max-frames" && has_value) {
      if (!positive_option("--rap-max-frames", argv[i + 1],
                           &o->opt.independent_max_distance_frames)) {
        return false;
      }
      o->usac = true;
      i++;
    } else if (arg == "--segment-max-bytes" && has_value) {
      if (!positive_option("--segment-max-bytes", argv[i + 1], &o->opt.segment_max_bytes)) {
        return false;
      }
      o->usac = true;
      i++;
    } else if (arg == "--drc") {
      o->usac = true;
      o->opt.drc = 1;
    } else if (arg == "--drc-range" && has_value) {
      const int lu = atoi(argv[i + 1]);
      if (lu < 6 || lu > 16) {
        fprintf(stderr, "baac: --drc-range takes 6 to 16 LU\n");
        return false;
      }
      o->usac = true;
      o->opt.drc_target_range_lu = lu;
      i++;
    } else if (arg == "--stream-id" && has_value) {
      const int id = atoi(argv[i + 1]);
      if (id < 0 || id > 65535) {
        fprintf(stderr, "baac: --stream-id takes 0 to 65535\n");
        return false;
      }
      o->usac = true;
      o->opt.stream_id = id;
      i++;
    } else if (arg.size() > 2 && arg[0] == '-' && arg[1] == '-') {
      fprintf(stderr, "baac: unknown option %s\n", argv[i]);
      return false;
    } else {
      argv[kept++] = argv[i];
    }
  }
  *argc = kept;
  return true;
}

/* Encodes the whole input through `enc` and its flush, writing the
 * stream to `out` (nothing when NULL); false on an encode error. */
static bool encode_all(AacEncoderHandle enc, const std::vector<uint8_t>& in, int channels,
                       FILE* out, long* bytes, int* frames) {
  const int frame = aac_encoder_frame_size(enc);
  const size_t block = (size_t)frame * channels;
  const size_t total = in.size() / sizeof(float);
  std::vector<float> pcm(block);
  std::vector<uint8_t> buf(16384);
  *bytes = 0;
  *frames = 0;
  for (size_t pos = 0; pos < total; pos += block) {
    std::fill(pcm.begin(), pcm.end(), 0.0f);
    const size_t n = std::min(block, total - pos);
    memcpy(pcm.data(), in.data() + pos * sizeof(float), n * sizeof(float));
    const int len = aac_encoder_encode(enc, pcm.data(), frame, buf.data(), (int)buf.size());
    if (len < 0) {
      fprintf(stderr, "baac: encode error %d at frame %d\n", len, *frames);
      return false;
    }
    if (out) {
      fwrite(buf.data(), 1, (size_t)len, out);
    }
    *bytes += len;
    (*frames)++;
  }
  const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
  if (len > 0) {
    if (out) {
      fwrite(buf.data(), 1, (size_t)len, out);
    }
    *bytes += len;
  }
  return true;
}

/* The pass that measures the DRC sets' output: an encode with the
 * programme's figures and the encoder's meters on, whose stream is
 * dropped, and the figures of each set into the encoder `enc`. */
static bool measure_drc_sets(int sr, int channels, int bitrate, AacRateControl rc,
                             const EncodeOptions& options, const AacLoudnessInfo& program,
                             const std::vector<uint8_t>& in, AacEncoderHandle enc) {
  AacEncoderHandle probe = aac_encoder_create(sr, channels, bitrate, AAC_AOT_USAC, rc);
  if (!probe || aac_encoder_set_usac(probe, &options.opt) != AAC_OK ||
      aac_encoder_set_loudness_info(probe, &program) != AAC_OK ||
      aac_encoder_set_loudness_measurement(probe, 1) != AAC_OK) {
    fprintf(stderr, "baac: the DRC measurement pass could not start\n");
    aac_encoder_destroy(probe);
    return false;
  }
  long bytes;
  int frames;
  bool ok = encode_all(probe, in, channels, nullptr, &bytes, &frames);
  for (int id = AAC_USAC_DRC_SET_GENERAL; ok && id <= AAC_USAC_DRC_SET_NIGHT; id++) {
    AacLoudnessInfo set;
    if (aac_encoder_drc_loudness(probe, id, &set) != AAC_OK ||
        aac_encoder_set_drc_loudness_info(enc, id, &set) != AAC_OK) {
      fprintf(stderr, "baac: DRC set %d could not be measured\n", id);
      ok = false;
      break;
    }
    fprintf(stderr, "DRC set %d: ", id);
    print_loudness(stderr, set);
  }
  aac_encoder_destroy(probe);
  return ok;
}

static int encode(int argc, char** argv) {
  EncodeOptions options;
  if (!parse_encode_options(&argc, argv, &options)) {
    return usage();
  }
  if (argc < 6) {
    return usage();
  }
  const int sr = atoi(argv[4]);
  const int channels = atoi(argv[5]);
  const int bitrate = argc > 6 ? atoi(argv[6]) : 128000;
  AacRateControl rc = AAC_RC_CBR;
  if (argc > 7) {
    const std::string mode = argv[7];
    rc = mode == "abr"    ? AAC_RC_ABR
         : mode == "cvbr" ? AAC_RC_CVBR
         : mode == "tvbr" ? AAC_RC_TVBR
                          : AAC_RC_CBR;
  }
  AacObjectType aot = AAC_AOT_LC;
  if (argc > 9) {
    const std::string profile = argv[9];
    aot = profile == "he"     ? AAC_AOT_SBR
          : profile == "hev2" ? AAC_AOT_PS
          : profile == "xhe"  ? AAC_AOT_USAC
                              : AAC_AOT_LC;
  }
  if ((options.usac || options.loudness) && aot != AAC_AOT_USAC) {
    fprintf(stderr, "baac: the USAC options and --loudness need the xhe profile\n");
    return usage();
  }
  AacEncoderHandle enc = aac_encoder_create(sr, channels, bitrate, aot, rc);
  if (!enc) {
    fprintf(stderr, "baac: unsupported encoder configuration\n");
    return 1;
  }
  if (argc > 8 && atoi(argv[8]) > 0) {
    aac_encoder_set_quality(enc, atoi(argv[8]));
  }
  if (options.usac && aac_encoder_set_usac(enc, &options.opt) != AAC_OK) {
    fprintf(stderr, "baac: the USAC options were refused\n");
    return 1;
  }
  /* the VBR mode first, so that a peak below the bitrate is taken as a
   * cap that lowers the mode rather than refused as in cbr and abr; a
   * quality given on the command line overrides the mode's */
  if (options.vbr_mode && aac_encoder_set_vbr_mode(enc, options.vbr_mode) != AAC_OK) {
    fprintf(stderr, "baac: --vbr-mode takes 1 to 5\n");
    return 1;
  }
  if (options.peak_bitrate && aac_encoder_set_peak_bitrate(enc, options.peak_bitrate) != AAC_OK) {
    fprintf(stderr, "baac: --peak-bitrate was refused (below the bitrate in cbr or abr?)\n");
    return 1;
  }
  if (options.vbr_mode && argc > 8 && atoi(argv[8]) > 0) {
    aac_encoder_set_quality(enc, atoi(argv[8]));
  }
  if (options.afterburner && aac_encoder_set_afterburner(enc, 1) != AAC_OK) {
    fprintf(stderr, "baac: --afterburner was refused for this profile\n");
    return 1;
  }
  if (options.bandwidth && aac_encoder_set_bandwidth(enc, options.bandwidth) != AAC_OK) {
    fprintf(stderr, "baac: --bandwidth takes 1000 Hz or more\n");
    return 1;
  }
  const std::vector<uint8_t> in = read_all(argv[2]);
  if (options.loudness) {
    /* The first pass: the whole programme through the meter, its
     * figures into the stream's configuration before the first frame. */
    AacLoudnessInfo info;
    if (!measure_loudness(floats_of(in), sr, channels, &info)) {
      return 1;
    }
    const int ret = aac_encoder_set_loudness_info(enc, &info);
    if (ret != AAC_OK) {
      fprintf(stderr, "baac: the loudness info set was refused (%d)\n", ret);
      return 1;
    }
    print_loudness(stderr, info);
    if (options.opt.drc == 1 &&
        !measure_drc_sets(sr, channels, bitrate, rc, options, info, in, enc)) {
      return 1;
    }
  }
  FILE* out = open_out(argv[3]);
  const size_t total = in.size() / sizeof(float);
  long bytes = 0;
  int frames = 0;
  if (!encode_all(enc, in, channels, out, &bytes, &frames)) {
    return 1;
  }
  if (out != stdout) {
    fclose(out);
  }
  aac_encoder_destroy(enc);
  const double seconds = (double)total / channels / sr;
  fprintf(stderr, "baac: %d frames, %ld bytes, %.1f kbit/s\n", frames, bytes,
          seconds > 0 ? bytes * 8.0 / seconds / 1000.0 : 0.0);
  return 0;
}

static int decode(int argc, char** argv) {
  /* --no-loudness: leave USAC streams at their coded level */
  bool loudness = true;
  int kept = 0;
  for (int i = 0; i < argc; i++) {
    if (strcmp(argv[i], "--no-loudness") == 0) {
      loudness = false;
    } else {
      argv[kept++] = argv[i];
    }
  }
  argc = kept;
  if (argc < 4) {
    return usage();
  }
  const int sr = argc > 4 ? atoi(argv[4]) : 44100;
  const int channels = argc > 5 ? atoi(argv[5]) : 2;
  AacDecoderHandle dec = aac_decoder_create(sr, channels);
  if (!dec) {
    fprintf(stderr, "baac: unsupported decoder configuration\n");
    return 1;
  }
  if (!loudness) {
    aac_decoder_set_loudness(dec, 0, -2400);
  }
  const std::vector<uint8_t> in = read_all(argv[2]);
  FILE* out = open_out(argv[3]);
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  /* The streaming path takes ADTS, LOAS/LATM and raw access units
   * alike, resynchronising across damaged bytes. */
  size_t pos = 0;
  int frames = 0;
  int errors = 0;
  for (;;) {
    if (pos < in.size()) {
      const int taken = aac_decoder_feed(dec, in.data() + pos, (int)std::min<size_t>(in.size() - pos, 65536));
      if (taken < 0) {
        errors++;
        break;
      }
      pos += (size_t)taken;
    }
    const int n = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
    if (n == 0) {
      if (pos >= in.size()) {
        break;
      }
      continue;
    }
    if (n < 0) {
      errors++;
      if (n == AAC_ERR_UNSUPPORTED) {
        break;
      }
      continue;
    }
    fwrite(pcm.data(), sizeof(float), (size_t)n * aac_decoder_channels(dec), out);
    frames++;
  }
  if (out != stdout) {
    fclose(out);
  }
  fprintf(stderr, "baac: %d frames, %d errors, %d Hz, %d channels (stream %d)\n", frames, errors,
          aac_decoder_output_sample_rate(dec), aac_decoder_channels(dec),
          aac_decoder_stream_channels(dec));
  aac_decoder_destroy(dec);
  return errors ? 1 : 0;
}

/* ── bench ─────────────────────────────────────────────────────── */

/* One benchmark configuration: an encoder setup and its decode. */
struct BenchConfig {
  const char* name;
  AacObjectType aot;
  int bitrate;
  int frame_length; /* 0 keeps the object type's default */
  int afterburner;
  int usac_sbr;    /* USAC: enhanced SBR (1 on, 0 off) */
  int usac_mps212; /* USAC: MPS 2-1-2 (1 on, 0 off) */
};

static const BenchConfig kBenchConfigs[] = {
    {"LC 128k", AAC_AOT_LC, 128000, 0, 0, 0, 0},
    {"HE-AAC v1 64k", AAC_AOT_SBR, 64000, 0, 0, 0, 0},
    {"HE-AAC v2 32k", AAC_AOT_PS, 32000, 0, 0, 0, 0},
    {"LC 960 128k", AAC_AOT_LC, 128000, 960, 0, 0, 0},
    {"LD 512 64k", AAC_AOT_ER_LD, 64000, 512, 0, 0, 0},
    {"ELD 512 64k", AAC_AOT_ER_ELD, 64000, 512, 0, 0, 0},
    {"LC 48k afterburner", AAC_AOT_LC, 48000, 0, 1, 0, 0},
    {"xHE-AAC 96k", AAC_AOT_USAC, 96000, 0, 0, 0, 0},
    {"xHE-AAC SBR 48k", AAC_AOT_USAC, 48000, 0, 0, 1, 0},
    {"xHE-AAC 2-1-2 32k", AAC_AOT_USAC, 32000, 0, 0, 1, 1},
};

/* A deterministic stereo test signal: harmonics of a slowly sweeping
 * fundamental over a noise bed, with a click every 300 ms so that the
 * block switching and the transient tools run, and the right channel
 * quieter and offset in phase. */
static std::vector<float> bench_signal(int n, int sr) {
  std::vector<float> pcm((size_t)n * 2);
  const int harmonics = 24;
  float phase[harmonics] = {0};
  unsigned seed = 12345u;
  const int click_period = sr * 3 / 10;
  for (int i = 0; i < n; i++) {
    const float f0 = 110.0f + 30.0f * sinf(2.0f * (float)M_PI * (float)i / ((float)sr * 7.0f));
    float left = 0.0f;
    float right = 0.0f;
    for (int h = 0; h < harmonics; h++) {
      phase[h] += 2.0f * (float)M_PI * f0 * (float)(h + 1) / (float)sr;
      if (phase[h] > 2.0f * (float)M_PI) {
        phase[h] -= 2.0f * (float)M_PI;
      }
      const float amp = 0.18f / (float)(h + 1);
      left += amp * sinf(phase[h]);
      right += amp * sinf(phase[h] + 0.4f * (float)(h + 1));
    }
    seed = seed * 1664525u + 1013904223u;
    const float noise = 0.02f * ((float)(seed >> 8) / 8388608.0f - 1.0f);
    const int in_period = i % click_period;
    const float click = in_period < 48 ? 0.5f * (1.0f - (float)in_period / 48.0f) : 0.0f;
    pcm[(size_t)i * 2] = left + noise + click;
    pcm[(size_t)i * 2 + 1] = 0.7f * right + noise - click;
  }
  return pcm;
}

static double now_seconds() {
  using clock = std::chrono::steady_clock;
  return std::chrono::duration<double>(clock::now().time_since_epoch()).count();
}

static void write_file(const std::string& path, const void* data, size_t bytes) {
  FILE* f = fopen(path.c_str(), "wb");
  if (!f) {
    fprintf(stderr, "baac: cannot create %s\n", path.c_str());
    exit(1);
  }
  fwrite(data, 1, bytes, f);
  fclose(f);
}

/* Encodes the signal with one configuration into `stream` and reports
 * the wall time; returns false when the configuration is refused. */
static bool bench_encode(const BenchConfig& cfg, const std::vector<float>& pcm, int sr,
                         std::vector<uint8_t>& stream, double* seconds) {
  AacEncoderHandle enc = aac_encoder_create(sr, 2, cfg.bitrate, cfg.aot, AAC_RC_CBR);
  if (!enc) {
    return false;
  }
  if (cfg.frame_length && aac_encoder_set_frame_length(enc, cfg.frame_length) != AAC_OK) {
    aac_encoder_destroy(enc);
    return false;
  }
  if (cfg.afterburner) {
    aac_encoder_set_afterburner(enc, 1);
  }
  if (cfg.aot == AAC_AOT_USAC) {
    AacUsacEncoderOptions opt;
    aac_usac_encoder_options_init(&opt);
    opt.sbr = cfg.usac_sbr;
    opt.mps212 = cfg.usac_mps212;
    if (aac_encoder_set_usac(enc, &opt) != AAC_OK) {
      aac_encoder_destroy(enc);
      return false;
    }
  }
  const int frame = aac_encoder_frame_size(enc);
  const size_t block = (size_t)frame * 2;
  const size_t total = pcm.size();
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) + 64);
  std::vector<float> tail(block, 0.0f);
  stream.clear();
  stream.reserve(total / 8);
  const double t0 = now_seconds();
  for (size_t pos = 0; pos < total; pos += block) {
    const float* in = pcm.data() + pos;
    if (total - pos < block) {
      memcpy(tail.data(), in, (total - pos) * sizeof(float));
      in = tail.data();
    }
    const int len = aac_encoder_encode(enc, in, frame, buf.data(), (int)buf.size());
    if (len < 0) {
      fprintf(stderr, "baac: %s: encode error %d\n", cfg.name, len);
      aac_encoder_destroy(enc);
      return false;
    }
    stream.insert(stream.end(), buf.begin(), buf.begin() + len);
  }
  for (;;) {
    const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
    if (len <= 0) {
      break;
    }
    stream.insert(stream.end(), buf.begin(), buf.begin() + len);
  }
  *seconds = now_seconds() - t0;
  aac_encoder_destroy(enc);
  return true;
}

/* Decodes a stream through the FIFO interface into `out` and reports
 * the wall time and the output rate. */
static bool bench_decode(const BenchConfig& cfg, const std::vector<uint8_t>& stream,
                         std::vector<float>& out, double* seconds, int* out_rate) {
  AacDecoderHandle dec = aac_decoder_create(48000, 2);
  if (!dec) {
    return false;
  }
  if (cfg.frame_length == 960) {
    aac_decoder_set_frame_length(dec, 960);
  }
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 2);
  out.clear();
  out.reserve(stream.size() * 64);
  int errors = 0;
  const double t0 = now_seconds();
  for (size_t pos = 0; pos < stream.size();) {
    const int taken = aac_decoder_feed(dec, stream.data() + pos,
                                       (int)std::min((size_t)4096, stream.size() - pos));
    if (taken <= 0) {
      errors++;
      break;
    }
    pos += (size_t)taken;
    for (;;) {
      const int got = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
      if (got == 0) {
        break;
      }
      if (got < 0) {
        errors++;
        continue;
      }
      out.insert(out.end(), pcm.begin(), pcm.begin() + (size_t)got * 2);
    }
  }
  *seconds = now_seconds() - t0;
  *out_rate = aac_decoder_output_sample_rate(dec);
  aac_decoder_destroy(dec);
  if (errors) {
    fprintf(stderr, "baac: %s: %d decode errors\n", cfg.name, errors);
  }
  return errors == 0;
}

/* The profile a decoded stream belongs to, from what the decoder saw. */
static std::string stream_profile(AacDecoderHandle dec) {
  int sbr = 0;
  int ps = 0;
  aac_decoder_get_sbr_ps(dec, &sbr, &ps);
  const int surround = aac_decoder_has_surround(dec);
  std::string name;
  switch (aac_decoder_object_type(dec)) {
    case AAC_AOT_USAC:
      name = "xHE-AAC";
      break;
    case AAC_AOT_ER_ELD:
      name = "ELD";
      break;
    case AAC_AOT_ER_LD:
      name = "LD";
      break;
    default:
      name = ps ? "HE-AAC v2" : sbr ? "HE-AAC v1" : "LC";
      break;
  }
  if (aac_decoder_object_type(dec) == AAC_AOT_USAC && sbr) {
    name += " SBR";
  }
  if (surround) {
    name += aac_decoder_channels(dec) > 2 ? " + MPS 5.1" : " + MPS 2-1-2";
  } else {
    /* The USAC 2-1-2 tool is not reported apart from a channel pair. */
    name += aac_decoder_channels(dec) == 1 ? " mono" : " stereo";
  }
  return name;
}

/* Decodes one stream through the FIFO interface `*passes` times, as
 * many as fill about half a second, and reports the audio seconds of
 * one pass and the wall time of all of them. */
static bool bench_stream(const std::vector<uint8_t>& stream, double* audio_seconds,
                         double* wall_seconds, int* passes, std::string* profile) {
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  *passes = 0;
  *wall_seconds = 0.0;
  bool ok = true;
  while (*wall_seconds < 0.5 && *passes < 1000) {
    AacDecoderHandle dec = aac_decoder_create(48000, 0);
    if (!dec) {
      return false;
    }
    long samples = 0;
    int rate = 0;
    const double t0 = now_seconds();
    for (size_t pos = 0; pos < stream.size();) {
      const int taken = aac_decoder_feed(dec, stream.data() + pos,
                                         (int)std::min((size_t)4096, stream.size() - pos));
      if (taken <= 0) {
        ok = false;
        break;
      }
      pos += (size_t)taken;
      for (;;) {
        const int got = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
        if (got == 0) {
          break;
        }
        if (got < 0) {
          ok = false;
          continue;
        }
        samples += got;
        rate = aac_decoder_output_sample_rate(dec);
      }
    }
    *wall_seconds += now_seconds() - t0;
    (*passes)++;
    *audio_seconds = rate > 0 ? (double)samples / (double)rate : 0.0;
    *profile = stream_profile(dec);
    aac_decoder_destroy(dec);
    if (!ok || samples == 0) {
      return false;
    }
  }
  return true;
}

/* The decode realtime factor of every .aac and .loas stream in `dir`,
 * then per profile over the streams of that profile. */
static int bench_streams(const std::string& dir) {
  std::error_code err;
  std::filesystem::directory_iterator it(dir, err);
  if (err) {
    fprintf(stderr, "baac: cannot read %s\n", dir.c_str());
    return 1;
  }
  std::vector<std::string> names;
  for (const auto& e : it) {
    const std::string n = e.path().filename().string();
    const bool aac = n.size() > 4 && n.compare(n.size() - 4, 4, ".aac") == 0;
    const bool loas = n.size() > 5 && n.compare(n.size() - 5, 5, ".loas") == 0;
    if (aac || loas) {
      names.push_back(n);
    }
  }
  std::sort(names.begin(), names.end());
  struct Total {
    double audio = 0.0, wall = 0.0;
    int streams = 0;
  };
  std::map<std::string, Total> totals;
  int failures = 0;
  printf("\n%-32s %-22s %8s %9s\n", "stream", "profile", "audio s", "dec x");
  for (const std::string& n : names) {
    const std::vector<uint8_t> stream = read_all((dir + "/" + n).c_str());
    double audio = 0.0;
    double wall = 0.0;
    int passes = 0;
    std::string profile;
    if (!bench_stream(stream, &audio, &wall, &passes, &profile)) {
      printf("%-32s decode errors\n", n.c_str());
      failures++;
      continue;
    }
    printf("%-32s %-22s %8.2f %8.1fx\n", n.c_str(), profile.c_str(), audio,
           audio * passes / wall);
    Total& t = totals[profile];
    t.audio += audio * passes;
    t.wall += wall;
    t.streams++;
  }
  printf("\n%-32s %8s %9s\n", "profile (committed streams)", "streams", "dec x");
  for (const auto& [profile, t] : totals) {
    printf("%-32s %8d %8.1fx\n", profile.c_str(), t.streams, t.audio / t.wall);
  }
  return failures ? 1 : 0;
}

static int bench(int argc, char** argv) {
  int seconds = 30;
  std::string dump;
  std::string only;
  std::string streams;
  for (int i = 2; i < argc; i++) {
    if (strcmp(argv[i], "--seconds") == 0 && i + 1 < argc) {
      seconds = atoi(argv[++i]);
    } else if (strcmp(argv[i], "--dump") == 0 && i + 1 < argc) {
      dump = argv[++i];
    } else if (strcmp(argv[i], "--only") == 0 && i + 1 < argc) {
      only = argv[++i];
    } else if (strcmp(argv[i], "--streams") == 0 && i + 1 < argc) {
      streams = argv[++i];
    } else {
      return usage();
    }
  }
  if (seconds <= 0) {
    return usage();
  }
  const int sr = 48000;
  const std::vector<float> pcm = bench_signal(sr * seconds, sr);
  printf("baac bench: %d s stereo at %d Hz\n\n", seconds, sr);
  printf("%-22s %8s %9s %9s %9s %9s\n", "configuration", "kbit/s", "enc s", "enc x", "dec s",
         "dec x");
  int failures = 0;
  for (const BenchConfig& cfg : kBenchConfigs) {
    if (!only.empty() && only != cfg.name) {
      continue;
    }
    std::vector<uint8_t> stream;
    double enc_s = 0.0;
    if (!bench_encode(cfg, pcm, sr, stream, &enc_s)) {
      printf("%-22s unsupported\n", cfg.name);
      failures++;
      continue;
    }
    std::vector<float> out;
    double dec_s = 0.0;
    int out_rate = sr;
    const bool ok = bench_decode(cfg, stream, out, &dec_s, &out_rate);
    const double decoded = (double)out.size() / 2.0 / (double)out_rate;
    printf("%-22s %8.1f %9.3f %8.1fx %9.3f %8.1fx\n", cfg.name,
           (double)stream.size() * 8.0 / (double)seconds / 1000.0, enc_s, (double)seconds / enc_s,
           dec_s, decoded / dec_s);
    if (!ok) {
      failures++;
    }
    if (!dump.empty()) {
      std::string base = dump + "/" + cfg.name;
      std::replace(base.begin(), base.end(), ' ', '_');
      write_file(base + ".aac", stream.data(), stream.size());
      write_file(base + ".f32", out.data(), out.size() * sizeof(float));
    }
  }
  if (!streams.empty() && bench_streams(streams) != 0) {
    failures++;
  }
  return failures ? 1 : 0;
}

int main(int argc, char** argv) {
  if (argc < 2) {
    return usage();
  }
  if (strcmp(argv[1], "enc") == 0) {
    return encode(argc, argv);
  }
  if (strcmp(argv[1], "dec") == 0) {
    return decode(argc, argv);
  }
  if (strcmp(argv[1], "loudness") == 0) {
    return loudness(argc, argv);
  }
  if (strcmp(argv[1], "bench") == 0) {
    return bench(argc, argv);
  }
  return usage();
}
