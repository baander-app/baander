/* Node-API binding for baander-aac: streaming decoder and PCM encoder
 * objects over the public C API, and the loudness meter as a function.
 * PCM is interleaved 32-bit float at full scale 1.0; AAC data is an ADTS
 * or LOAS stream, LATM elements or raw access units.  Both classes accept
 * input in arbitrary chunk sizes and keep partial frames between calls. */
#include <napi.h>

#include <cmath>
#include <cstdint>
#include <cstring>
#include <string>
#include <vector>

#include "aac.h"

namespace {

Napi::Value throw_error(const Napi::Env& env, const std::string& message) {
  Napi::Error::New(env, message).ThrowAsJavaScriptException();
  return env.Undefined();
}

const char* error_name(int code) {
  switch (code) {
    case AAC_ERR_INVALID_ARG:
      return "invalid argument";
    case AAC_ERR_MEMORY:
      return "out of memory";
    case AAC_ERR_INIT:
      return "initialization failed";
    case AAC_ERR_ENCODE:
      return "encoding failed";
    case AAC_ERR_DECODE:
      return "decoding failed";
    case AAC_ERR_UNSUPPORTED:
      return "unsupported stream";
    case AAC_ERR_OVERFLOW:
      return "buffer too small";
    case AAC_ERR_STATE:
      return "invalid state";
    default:
      return "unknown error";
  }
}

/* Bytes of a Buffer, Uint8Array or ArrayBuffer argument. */
bool bytes_of(const Napi::Value& v, const uint8_t** data, size_t* size) {
  if (v.IsArrayBuffer()) {
    Napi::ArrayBuffer ab = v.As<Napi::ArrayBuffer>();
    *data = static_cast<const uint8_t*>(ab.Data());
    *size = ab.ByteLength();
    return true;
  }
  if (v.IsTypedArray()) {
    Napi::TypedArray ta = v.As<Napi::TypedArray>();
    *data = static_cast<const uint8_t*>(ta.ArrayBuffer().Data()) + ta.ByteOffset();
    *size = ta.ByteLength();
    return true;
  }
  return false;
}

/* Whether the option is given (present and not undefined). */
bool option_given(const Napi::Object& o, const char* key) {
  if (!o.Has(key)) {
    return false;
  }
  const Napi::Value v = o.Get(key);
  return !v.IsUndefined() && !v.IsNull();
}

int option_int(const Napi::Object& o, const char* key, int fallback) {
  if (!o.Has(key) || o.Get(key).IsUndefined()) {
    return fallback;
  }
  return o.Get(key).ToNumber().Int32Value();
}

/* An integer option that, when given, has to be a number: a boolean or an
 * object is refused with the option's name (`what`, as the message names
 * it) instead of being read as 0 or 1.  `*out` keeps its value when the
 * option is absent.  Returns false after throwing. */
bool option_number(const Napi::Env& env, const Napi::Object& o, const char* key, const char* what,
                   int* out) {
  if (!o.Has(key) || o.Get(key).IsUndefined()) {
    return true;
  }
  const Napi::Value v = o.Get(key);
  if (!v.IsNumber()) {
    throw_error(env, std::string(what) + " must be a number");
    return false;
  }
  *out = v.As<Napi::Number>().Int32Value();
  return true;
}

std::string option_string(const Napi::Object& o, const char* key, const char* fallback) {
  if (!o.Has(key) || !o.Get(key).IsString()) {
    return fallback;
  }
  return o.Get(key).As<Napi::String>();
}

bool option_bool(const Napi::Object& o, const char* key, bool fallback) {
  if (!o.Has(key) || o.Get(key).IsUndefined()) {
    return fallback;
  }
  return o.Get(key).ToBoolean().Value();
}

/* Transport names shared by both classes; "detect" is the decoder's only. */
bool transport_of(const std::string& name, AacTransport* out) {
  if (name == "detect") {
    *out = AAC_TRANSPORT_DETECT;
  } else if (name == "adts") {
    *out = AAC_TRANSPORT_ADTS;
  } else if (name == "loas") {
    *out = AAC_TRANSPORT_LOAS;
  } else if (name == "latm") {
    *out = AAC_TRANSPORT_LATM_MCP1;
  } else if (name == "latm-raw") {
    *out = AAC_TRANSPORT_LATM_MCP0;
  } else if (name == "raw") {
    *out = AAC_TRANSPORT_RAW;
  } else {
    return false;
  }
  return true;
}

bool channel_mode_of(const std::string& name, AacChannelMode* out) {
  if (name == "default") {
    *out = AAC_MODE_DEFAULT;
  } else if (name == "7.1") {
    *out = AAC_MODE_7_1_BACK;
  } else if (name == "7.1-front") {
    *out = AAC_MODE_7_1_FRONT_CENTER;
  } else if (name == "7.1-top") {
    *out = AAC_MODE_7_1_TOP_FRONT;
  } else if (name == "7.1-rear") {
    *out = AAC_MODE_7_1_REAR_SURROUND;
  } else {
    return false;
  }
  return true;
}

const char* object_type_name(int aot) {
  switch (aot) {
    case AAC_AOT_LC:
      return "lc";
    case AAC_AOT_ER_LC:
      return "er-lc";
    case AAC_AOT_ER_LD:
      return "ld";
    case AAC_AOT_ER_ELD:
      return "eld";
    case AAC_AOT_USAC:
      return "usac";
    default:
      return "unknown";
  }
}

/* The MPEG-D DRC effect a decoder option names. */
bool drc_effect_of(const std::string& name, AacDrcEffect* out) {
  if (name == "off") {
    *out = AAC_DRC_EFFECT_OFF;
  } else if (name == "none") {
    *out = AAC_DRC_EFFECT_NONE;
  } else if (name == "night") {
    *out = AAC_DRC_EFFECT_NIGHT;
  } else if (name == "noisy") {
    *out = AAC_DRC_EFFECT_NOISY;
  } else if (name == "limited") {
    *out = AAC_DRC_EFFECT_LIMITED;
  } else if (name == "lowlevel") {
    *out = AAC_DRC_EFFECT_LOWLEVEL;
  } else if (name == "dialog") {
    *out = AAC_DRC_EFFECT_DIALOG;
  } else if (name == "general") {
    *out = AAC_DRC_EFFECT_GENERAL;
  } else {
    return false;
  }
  return true;
}

bool drc_profile_of(const std::string& name, AacDrcProfile* out) {
  if (name == "none") {
    *out = AAC_DRC_NONE;
  } else if (name == "film-standard") {
    *out = AAC_DRC_FILM_STANDARD;
  } else if (name == "film-light") {
    *out = AAC_DRC_FILM_LIGHT;
  } else if (name == "music-standard") {
    *out = AAC_DRC_MUSIC_STANDARD;
  } else if (name == "music-light") {
    *out = AAC_DRC_MUSIC_LIGHT;
  } else if (name == "speech") {
    *out = AAC_DRC_SPEECH;
  } else {
    return false;
  }
  return true;
}

/* on, off or auto (the encoder's choice by bitrate) of a USAC tool. */
bool on_off_auto_of(const std::string& name, int* out) {
  if (name == "on") {
    *out = 1;
  } else if (name == "off") {
    *out = 0;
  } else if (name == "auto") {
    *out = -1;
  } else {
    return false;
  }
  return true;
}

/* The figures of a loudness measurement as the binding's object: the
 * optional ones are null when the meter has no window for them. */
Napi::Object loudness_object(const Napi::Env& env, const AacLoudnessInfo& info) {
  Napi::Object o = Napi::Object::New(env);
  o.Set("integratedLkfs", Napi::Number::New(env, info.integrated_lkfs));
  o.Set("samplePeakDbfs", Napi::Number::New(env, info.sample_peak_dbfs));
  o.Set("truePeakDbtp", info.has_true_peak ? Napi::Value(Napi::Number::New(env, info.true_peak_dbtp))
                                           : Napi::Value(env.Null()));
  o.Set("rangeLu", info.has_range ? Napi::Value(Napi::Number::New(env, info.range_lu))
                                  : Napi::Value(env.Null()));
  o.Set("maxMomentaryLkfs", info.has_momentary
                                ? Napi::Value(Napi::Number::New(env, info.max_momentary_lkfs))
                                : Napi::Value(env.Null()));
  o.Set("maxShortTermLkfs", info.has_short_term
                                ? Napi::Value(Napi::Number::New(env, info.max_short_term_lkfs))
                                : Napi::Value(env.Null()));
  return o;
}

/* A finite number option into a float figure; `*present` says whether
 * it was given.  Returns false after throwing. */
bool loudness_figure(const Napi::Env& env, const Napi::Object& o, const char* key, float* out,
                     int* present) {
  *present = 0;
  if (!option_given(o, key)) {
    return true;
  }
  const Napi::Value v = o.Get(key);
  if (!v.IsNumber() || !std::isfinite(v.As<Napi::Number>().DoubleValue())) {
    throw_error(env, std::string("AacEncoder: loudnessInfo.") + key + " must be a finite number");
    return false;
  }
  *out = static_cast<float>(v.As<Napi::Number>().DoubleValue());
  *present = 1;
  return true;
}

/* The loudnessInfo option: integratedLkfs and samplePeakDbfs are needed,
 * the other figures are written when given. */
bool loudness_info_of(const Napi::Env& env, const Napi::Object& o, AacLoudnessInfo* info) {
  memset(info, 0, sizeof(*info));
  int has_integrated = 0;
  int has_sample_peak = 0;
  if (!loudness_figure(env, o, "integratedLkfs", &info->integrated_lkfs, &has_integrated) ||
      !loudness_figure(env, o, "samplePeakDbfs", &info->sample_peak_dbfs, &has_sample_peak) ||
      !loudness_figure(env, o, "truePeakDbtp", &info->true_peak_dbtp, &info->has_true_peak) ||
      !loudness_figure(env, o, "rangeLu", &info->range_lu, &info->has_range) ||
      !loudness_figure(env, o, "anchorLkfs", &info->anchor_lkfs, &info->has_anchor) ||
      !loudness_figure(env, o, "maxMomentaryLkfs", &info->max_momentary_lkfs, &info->has_momentary) ||
      !loudness_figure(env, o, "maxShortTermLkfs", &info->max_short_term_lkfs, &info->has_short_term)) {
    return false;
  }
  if (!has_integrated || !has_sample_peak) {
    throw_error(env, "AacEncoder: loudnessInfo needs integratedLkfs and samplePeakDbfs");
    return false;
  }
  return true;
}

const char* channel_mode_name(int mode) {
  switch (mode) {
    case AAC_MODE_7_1_BACK:
      return "7.1";
    case AAC_MODE_7_1_FRONT_CENTER:
      return "7.1-front";
    case AAC_MODE_7_1_TOP_FRONT:
      return "7.1-top";
    case AAC_MODE_7_1_REAR_SURROUND:
      return "7.1-rear";
    default:
      return "default";
  }
}

/* ── decoder ───────────────────────────────────────────────────── */

class Decoder : public Napi::ObjectWrap<Decoder> {
 public:
  static Napi::Function Init(Napi::Env env) {
    return DefineClass(
        env, "AacDecoder",
        {InstanceMethod("decode", &Decoder::Decode), InstanceMethod("flush", &Decoder::Flush),
         InstanceMethod("clearHistory", &Decoder::ClearHistory),
         InstanceMethod("reset", &Decoder::Reset), InstanceMethod("loudness", &Decoder::Loudness),
         InstanceAccessor("sampleRate", &Decoder::SampleRate, nullptr),
         InstanceAccessor("coreSampleRate", &Decoder::CoreSampleRate, nullptr),
         InstanceAccessor("channels", &Decoder::Channels, nullptr),
         InstanceAccessor("streamChannels", &Decoder::StreamChannels, nullptr),
         InstanceAccessor("channelMode", &Decoder::ChannelMode, nullptr),
         InstanceAccessor("frameSize", &Decoder::FrameSize, nullptr),
         InstanceAccessor("hasSbr", &Decoder::HasSbr, nullptr),
         InstanceAccessor("hasPs", &Decoder::HasPs, nullptr),
         InstanceAccessor("hasSurround", &Decoder::HasSurround, nullptr),
         InstanceAccessor("errors", &Decoder::Errors, nullptr),
         InstanceAccessor("concealedFrames", &Decoder::ConcealedFrames, nullptr),
         InstanceAccessor("delay", &Decoder::Delay, nullptr),
         InstanceAccessor("objectType", &Decoder::ObjectType, nullptr),
         InstanceAccessor("programRefLevel", &Decoder::ProgramRefLevel, nullptr),
         InstanceAccessor("hasDrc", &Decoder::HasDrc, nullptr)});
  }

  explicit Decoder(const Napi::CallbackInfo& info) : Napi::ObjectWrap<Decoder>(info) {
    const Napi::Env env = info.Env();
    int sample_rate = 44100;
    int channels = 2;
    AacTransport transport = AAC_TRANSPORT_DETECT;
    AacConcealment concealment = AAC_CONCEAL_INTERPOLATE;
    int frame_length = 0;
    bool limiter = false;
    int attack_ms = 15;
    int release_ms = 50;
    const uint8_t* config = nullptr;
    size_t config_size = 0;
    bool drc = false;
    int boost = 100;
    int cut = 100;
    int target_ref_level = -1;
    bool heavy = false;
    int eld_downscale = 1;
    bool loudness = false;
    bool loudness_enabled = true;
    int target_lufs_x100 = -2400;
    bool drc_effect = false;
    AacDrcEffect effect = AAC_DRC_EFFECT_NONE;
    bool album_mode = false;
    int drc_boost = 100;
    int drc_cut = 100;
    if (info.Length() > 0 && info[0].IsObject()) {
      const Napi::Object o = info[0].As<Napi::Object>();
      sample_rate = option_int(o, "sampleRate", sample_rate);
      if (!option_number(env, o, "eldDownscale", "AacDecoder: eldDownscale", &eld_downscale) ||
          !option_number(env, o, "frameLength", "AacDecoder: frameLength", &frame_length)) {
        return;
      }
      if (o.Has("drc") && o.Get("drc").IsObject()) {
        const Napi::Object d = o.Get("drc").As<Napi::Object>();
        drc = true;
        if (!option_number(env, d, "boost", "AacDecoder: drc.boost", &boost) ||
            !option_number(env, d, "cut", "AacDecoder: drc.cut", &cut) ||
            !option_number(env, d, "targetRefLevel", "AacDecoder: drc.targetRefLevel",
                           &target_ref_level)) {
          return;
        }
        heavy = option_bool(d, "heavy", false);
      } else {
        drc = option_bool(o, "drc", false);
      }
      /* MPEG-D loudness normalisation of USAC streams: `loudness` is a
       * boolean or { enabled, targetLufs }; the target is given in LUFS
       * and the decoder takes hundredths. */
      if (o.Has("loudness") && o.Get("loudness").IsObject()) {
        const Napi::Object l = o.Get("loudness").As<Napi::Object>();
        loudness = true;
        loudness_enabled = option_bool(l, "enabled", true);
        if (option_given(l, "targetLufs")) {
          if (!l.Get("targetLufs").IsNumber()) {
            throw_error(env, "AacDecoder: loudness.targetLufs must be a number");
            return;
          }
          const double lufs = l.Get("targetLufs").As<Napi::Number>().DoubleValue();
          target_lufs_x100 = static_cast<int>(lufs * 100.0 + (lufs < 0 ? -0.5 : 0.5));
        }
      } else if (option_given(o, "loudness")) {
        loudness = true;
        loudness_enabled = o.Get("loudness").ToBoolean().Value();
      }
      /* The MPEG-D DRC request: the effect by name, album mode, and the
       * share of the boosting and attenuating gains in percent. */
      if (option_given(o, "drcEffect")) {
        drc_effect = true;
        if (!o.Get("drcEffect").IsString() ||
            !drc_effect_of(o.Get("drcEffect").As<Napi::String>(), &effect)) {
          throw_error(env, "AacDecoder: drcEffect must be off, none, night, noisy, limited, "
                           "lowlevel, dialog or general");
          return;
        }
      }
      if (option_given(o, "albumMode")) {
        drc_effect = true;
        album_mode = o.Get("albumMode").ToBoolean().Value();
      }
      drc_effect = drc_effect || option_given(o, "drcBoost") || option_given(o, "drcCut");
      if (!option_number(env, o, "drcBoost", "AacDecoder: drcBoost", &drc_boost) ||
          !option_number(env, o, "drcCut", "AacDecoder: drcCut", &drc_cut)) {
        return;
      }
      channels = option_int(o, "channels", channels);
      if (!transport_of(option_string(o, "transport", "detect"), &transport)) {
        throw_error(env, "AacDecoder: transport must be detect, adts, loas, latm, latm-raw or raw");
        return;
      }
      const std::string conceal = option_string(o, "concealment", "interpolate");
      if (conceal == "off") {
        concealment = AAC_CONCEAL_OFF;
      } else if (conceal == "noise") {
        concealment = AAC_CONCEAL_NOISE;
      } else if (conceal == "interpolate") {
        concealment = AAC_CONCEAL_INTERPOLATE;
      } else {
        throw_error(env, "AacDecoder: concealment must be off, noise or interpolate");
        return;
      }
      if (o.Has("limiter") && o.Get("limiter").IsObject()) {
        const Napi::Object l = o.Get("limiter").As<Napi::Object>();
        limiter = true;
        attack_ms = option_int(l, "attackMs", attack_ms);
        release_ms = option_int(l, "releaseMs", release_ms);
      } else {
        limiter = option_bool(o, "limiter", false);
      }
      if (o.Has("config") && !o.Get("config").IsUndefined() &&
          !bytes_of(o.Get("config"), &config, &config_size)) {
        throw_error(env, "AacDecoder: config must be a Buffer, Uint8Array or ArrayBuffer");
        return;
      }
    }
    handle_ = aac_decoder_create(sample_rate, channels);
    if (!handle_) {
      throw_error(env, "AacDecoder: unsupported sample rate or channel count");
      return;
    }
    int ret = 0;
    if ((ret = aac_decoder_set_transport(handle_, transport)) < 0 ||
        (ret = aac_decoder_set_concealment(handle_, concealment)) < 0 ||
        (frame_length && (ret = aac_decoder_set_frame_length(handle_, frame_length)) < 0) ||
        (limiter && (ret = aac_decoder_set_limiter(handle_, 1, attack_ms, release_ms)) < 0) ||
        (drc && (ret = aac_decoder_set_drc(handle_, 1, boost, cut, target_ref_level, heavy ? 1 : 0)) < 0) ||
        (eld_downscale != 1 && (ret = aac_decoder_set_eld_downscale(handle_, eld_downscale)) < 0) ||
        (loudness && (ret = aac_decoder_set_loudness(handle_, loudness_enabled ? 1 : 0,
                                                     target_lufs_x100)) < 0) ||
        (drc_effect && (ret = aac_decoder_set_drc_effect(handle_, effect, album_mode ? 1 : 0,
                                                         drc_boost, drc_cut)) < 0) ||
        (config && (ret = aac_decoder_set_config(handle_, config, static_cast<int>(config_size))) < 0)) {
      aac_decoder_destroy(handle_);
      handle_ = nullptr;
      throw_error(env, std::string("AacDecoder: ") + error_name(ret));
      return;
    }
    /* room for the largest frame at any layout, with SBR */
    pcm_.resize(static_cast<size_t>(AAC_DECODER_MAX_OUTPUT) * 8);
  }

  ~Decoder() override { aac_decoder_destroy(handle_); }

 private:
  /* Feeds the chunk to the decoder and decodes every frame that becomes
   * complete; a trailing partial frame waits for the next call.  With a
   * packet transport (LATM elements, raw access units) each call is one
   * packet. */
  Napi::Value Decode(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "AacDecoder: not initialized");
    }
    const uint8_t* data = nullptr;
    size_t size = 0;
    if (info.Length() < 1 || !bytes_of(info[0], &data, &size)) {
      return throw_error(env, "AacDecoder.decode: expected a Buffer, Uint8Array or ArrayBuffer");
    }

    std::vector<float> out;
    size_t pos = 0;
    bool more = true;
    while (more) {
      const int taken = aac_decoder_feed(handle_, data + pos, static_cast<int>(size - pos));
      if (taken < 0) {
        return throw_error(env, std::string("AacDecoder.decode: ") + error_name(taken));
      }
      pos += static_cast<size_t>(taken);
      more = pos < size;
      for (;;) {
        const int n = aac_decoder_decode_next(handle_, pcm_.data(), static_cast<int>(pcm_.size()));
        if (n == 0) {
          break;
        }
        if (n < 0) {
          if (n == AAC_ERR_UNSUPPORTED && !more) {
            return throw_error(env, std::string("AacDecoder.decode: ") + error_name(n));
          }
          errors_++;
          continue;
        }
        const size_t floats = static_cast<size_t>(n) * aac_decoder_channels(handle_);
        out.insert(out.end(), pcm_.begin(), pcm_.begin() + floats);
      }
      if (more && taken == 0) {
        return throw_error(env, "AacDecoder.decode: input buffer full");
      }
    }

    Napi::Float32Array result = Napi::Float32Array::New(env, out.size());
    if (!out.empty()) {
      memcpy(result.Data(), out.data(), out.size() * sizeof(float));
    }
    return result;
  }

  /* Drains the decoder's own delay at the end of the stream (the
   * limiter's look-ahead, the surround decoder's delay) as interleaved
   * samples, empty when it has none, then resets the decoder as reset()
   * does, so that a new stream can follow. */
  Napi::Value Flush(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "AacDecoder: not initialized");
    }
    const int channels = aac_decoder_channels(handle_);
    const int delay = aac_decoder_delay(handle_);
    std::vector<float> out(delay > 0 && channels > 0 ? static_cast<size_t>(delay) * channels : 1);
    const int n = aac_decoder_flush(handle_, out.data(), static_cast<int>(out.size()));
    errors_ = 0;
    if (n < 0) {
      return throw_error(env, std::string("AacDecoder.flush: ") + error_name(n));
    }
    const size_t floats = static_cast<size_t>(n) * channels;
    Napi::Float32Array result = Napi::Float32Array::New(env, floats);
    if (floats > 0) {
      memcpy(result.Data(), out.data(), floats * sizeof(float));
    }
    return result;
  }

  /* For a seek: drops the pending bytes and what the decoder remembers
   * of the audio before (overlap, SBR, parametric stereo and surround,
   * DRC and concealment history), keeping the stream's configuration,
   * so that the frames from the new position decode as they would in a
   * fresh decoder. */
  Napi::Value ClearHistory(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "AacDecoder: not initialized");
    }
    const int ret = aac_decoder_clear_history(handle_);
    if (ret < 0) {
      return throw_error(env, std::string("AacDecoder.clearHistory: ") + error_name(ret));
    }
    return env.Undefined();
  }

  /* Drops pending bytes and the error count and returns the decoder to
   * its state after creation, so that a new stream can follow. */
  Napi::Value Reset(const Napi::CallbackInfo& info) {
    errors_ = 0;
    if (handle_) {
      aac_decoder_reset(handle_);
    }
    return info.Env().Undefined();
  }

  /* The MPEG-D loudness state after the last frame: the program loudness
   * the stream declares in LUFS (null when it declares none, and for
   * every stream that is not USAC) and the normalisation gain applied in
   * dB (0 when none is). */
  Napi::Value Loudness(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    Napi::Object result = Napi::Object::New(env);
    int program_x100 = INT32_MIN;
    int gain_x100 = 0;
    if (handle_ && aac_decoder_loudness(handle_, &program_x100, &gain_x100) < 0) {
      program_x100 = INT32_MIN;
      gain_x100 = 0;
    }
    result.Set("programLoudness", program_x100 == INT32_MIN
                                      ? env.Null()
                                      : Napi::Number::New(env, program_x100 / 100.0));
    result.Set("normalizationGain", Napi::Number::New(env, gain_x100 / 100.0));
    return result;
  }

  Napi::Value SampleRate(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), handle_ ? aac_decoder_output_sample_rate(handle_) : 0);
  }
  Napi::Value CoreSampleRate(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), handle_ ? aac_decoder_sample_rate(handle_) : 0);
  }
  Napi::Value Channels(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), handle_ ? aac_decoder_channels(handle_) : 0);
  }
  Napi::Value StreamChannels(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), handle_ ? aac_decoder_stream_channels(handle_) : 0);
  }
  Napi::Value ChannelMode(const Napi::CallbackInfo& info) {
    return Napi::String::New(info.Env(),
                             channel_mode_name(handle_ ? aac_decoder_channel_mode(handle_) : 0));
  }
  Napi::Value FrameSize(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), handle_ ? aac_decoder_frame_size(handle_) : 0);
  }
  Napi::Value HasSbr(const Napi::CallbackInfo& info) {
    int sbr = 0;
    if (handle_) {
      aac_decoder_get_sbr_ps(handle_, &sbr, nullptr);
    }
    return Napi::Boolean::New(info.Env(), sbr != 0);
  }
  Napi::Value HasPs(const Napi::CallbackInfo& info) {
    int ps = 0;
    if (handle_) {
      aac_decoder_get_sbr_ps(handle_, nullptr, &ps);
    }
    return Napi::Boolean::New(info.Env(), ps != 0);
  }
  Napi::Value HasSurround(const Napi::CallbackInfo& info) {
    return Napi::Boolean::New(info.Env(), handle_ && aac_decoder_has_surround(handle_) > 0);
  }
  Napi::Value Errors(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), errors_);
  }
  Napi::Value ConcealedFrames(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), handle_ ? aac_decoder_concealed_frames(handle_) : 0);
  }
  Napi::Value Delay(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), handle_ ? aac_decoder_delay(handle_) : 0);
  }
  Napi::Value ObjectType(const Napi::CallbackInfo& info) {
    return Napi::String::New(info.Env(),
                             object_type_name(handle_ ? aac_decoder_object_type(handle_) : 0));
  }
  Napi::Value ProgramRefLevel(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), handle_ ? aac_decoder_program_ref_level(handle_) : -1);
  }
  Napi::Value HasDrc(const Napi::CallbackInfo& info) {
    return Napi::Boolean::New(info.Env(), handle_ && aac_decoder_has_drc(handle_) > 0);
  }

  AacDecoderHandle handle_ = nullptr;
  int errors_ = 0;
  std::vector<float> pcm_;
};

/* ── encoder ───────────────────────────────────────────────────── */

class Encoder : public Napi::ObjectWrap<Encoder> {
 public:
  static Napi::Function Init(Napi::Env env) {
    return DefineClass(
        env, "AacEncoder",
        {InstanceMethod("encode", &Encoder::Encode), InstanceMethod("flush", &Encoder::Flush),
         InstanceMethod("requestIndependentFrame", &Encoder::RequestIndependentFrame),
         InstanceAccessor("lastFrame", &Encoder::LastFrame, nullptr),
         InstanceAccessor("sampleRate", &Encoder::SampleRate, nullptr),
         InstanceAccessor("channels", &Encoder::Channels, nullptr),
         InstanceAccessor("frameSize", &Encoder::FrameSize, nullptr),
         InstanceAccessor("delay", &Encoder::Delay, nullptr),
         InstanceAccessor("profile", &Encoder::Profile, nullptr),
         InstanceAccessor("config", &Encoder::Config, nullptr),
         InstanceAccessor("unitSizes", &Encoder::UnitSizes, nullptr),
         InstanceAccessor("loudness", &Encoder::Loudness, nullptr)});
  }

  explicit Encoder(const Napi::CallbackInfo& info) : Napi::ObjectWrap<Encoder>(info) {
    const Napi::Env env = info.Env();
    int bitrate = 128000;
    int quality = 0;
    AacRateControl rc = AAC_RC_CBR;
    AacObjectType aot = AAC_AOT_LC;
    sample_rate_ = 44100;
    channels_ = 2;
    if (info.Length() > 0 && info[0].IsObject()) {
      const Napi::Object o = info[0].As<Napi::Object>();
      sample_rate_ = option_int(o, "sampleRate", sample_rate_);
      channels_ = option_int(o, "channels", channels_);
      bitrate = option_int(o, "bitrate", bitrate);
      quality = option_int(o, "quality", 0);
      if (o.Has("mode") && o.Get("mode").IsString()) {
        const std::string mode = o.Get("mode").As<Napi::String>();
        if (mode == "cbr") {
          rc = AAC_RC_CBR;
        } else if (mode == "abr") {
          rc = AAC_RC_ABR;
        } else if (mode == "cvbr") {
          rc = AAC_RC_CVBR;
        } else if (mode == "tvbr") {
          rc = AAC_RC_TVBR;
        } else {
          throw_error(env, "AacEncoder: mode must be cbr, abr, cvbr or tvbr");
          return;
        }
      }
      if (o.Has("profile") && o.Get("profile").IsString()) {
        const std::string profile = o.Get("profile").As<Napi::String>();
        if (profile == "lc") {
          aot = AAC_AOT_LC;
        } else if (profile == "he") {
          aot = AAC_AOT_SBR;
        } else if (profile == "hev2") {
          aot = AAC_AOT_PS;
        } else if (profile == "ld") {
          aot = AAC_AOT_ER_LD;
        } else if (profile == "eld") {
          aot = AAC_AOT_ER_ELD;
        } else if (profile == "xhe") {
          aot = AAC_AOT_USAC;
        } else {
          throw_error(env, "AacEncoder: profile must be lc, he, hev2, ld, eld or xhe");
          return;
        }
      }
    }
    profile_ = aot == AAC_AOT_LC      ? "lc"
               : aot == AAC_AOT_SBR   ? "he"
               : aot == AAC_AOT_PS    ? "hev2"
               : aot == AAC_AOT_ER_LD ? "ld"
               : aot == AAC_AOT_USAC  ? "xhe"
                                      : "eld";
    handle_ = aac_encoder_create(sample_rate_, channels_, bitrate, aot, rc);
    if (!handle_) {
      throw_error(env, "AacEncoder: unsupported sample rate, channel count, bitrate or profile");
      return;
    }
    if (quality && aac_encoder_set_quality(handle_, quality) != AAC_OK) {
      aac_encoder_destroy(handle_);
      handle_ = nullptr;
      throw_error(env, "AacEncoder: quality must be 1..100");
      return;
    }
    if (!apply_stream_options(env, info)) {
      aac_encoder_destroy(handle_);
      handle_ = nullptr;
      return;
    }
    frame_size_ = aac_encoder_frame_size(handle_);
    block_.resize(static_cast<size_t>(frame_size_) * channels_);
    /* the flush writes up to three frames back to back */
    out_.resize(static_cast<size_t>(aac_encoder_max_frame_bytes(handle_)) * 3);
  }

  ~Encoder() override { aac_encoder_destroy(handle_); }

 private:
  /* Stream format options: transport, signalling, frame length, CRC, the
   * MPEG-2 header, the 7.1 channel mode, LD-SBR, the afterburner, the
   * DRC metadata, the USAC options, the loudness info set, the loudness
   * meter, and the tuning (peak bitrate, VBR mode, bandwidth).  Throws
   * and returns false on an invalid one. */
  bool apply_stream_options(const Napi::Env& env, const Napi::CallbackInfo& info) {
    if (info.Length() == 0 || !info[0].IsObject()) {
      return true;
    }
    const Napi::Object o = info[0].As<Napi::Object>();
    const bool low_delay = profile_ == "ld" || profile_ == "eld";
    const bool usac = profile_ == "xhe";
    AacTransport transport = AAC_TRANSPORT_ADTS;
    if (!transport_of(option_string(o, "transport", low_delay || usac ? "loas" : "adts"),
                      &transport) ||
        transport == AAC_TRANSPORT_DETECT) {
      throw_error(env, "AacEncoder: transport must be adts, loas, latm, latm-raw or raw");
      return false;
    }
    AacSignaling signaling = AAC_SIGNAL_IMPLICIT;
    const std::string sig = option_string(o, "signaling", "implicit");
    if (sig == "implicit") {
      signaling = AAC_SIGNAL_IMPLICIT;
    } else if (sig == "explicit") {
      signaling = AAC_SIGNAL_EXPLICIT_BC;
    } else if (sig == "hierarchical") {
      signaling = AAC_SIGNAL_EXPLICIT_HIER;
    } else {
      throw_error(env, "AacEncoder: signaling must be implicit, explicit or hierarchical");
      return false;
    }
    AacChannelMode mode = AAC_MODE_DEFAULT;
    if (!channel_mode_of(option_string(o, "channelMode", "default"), &mode)) {
      throw_error(env, "AacEncoder: channelMode must be default, 7.1, 7.1-front, 7.1-top or 7.1-rear");
      return false;
    }
    int frame_length = 0;
    if (!option_number(env, o, "frameLength", "AacEncoder: frameLength", &frame_length)) {
      return false;
    }
    bool ld_sbr = false;
    bool dual_rate = true;
    if (o.Has("ldSbr") && o.Get("ldSbr").IsObject()) {
      ld_sbr = true;
      dual_rate = option_bool(o.Get("ldSbr").As<Napi::Object>(), "dualRate", true);
    } else {
      ld_sbr = option_bool(o, "ldSbr", false);
    }
    AacDrcProfile drc_profile = AAC_DRC_NONE;
    int prog_ref_level = 124;
    if (o.Has("metadata") && o.Get("metadata").IsObject()) {
      const Napi::Object m = o.Get("metadata").As<Napi::Object>();
      if (!drc_profile_of(option_string(m, "drcProfile", "none"), &drc_profile)) {
        throw_error(env, "AacEncoder: metadata.drcProfile must be none, film-standard, film-light, "
                         "music-standard, music-light or speech");
        return false;
      }
      if (!option_number(env, m, "programRefLevel", "AacEncoder: metadata.programRefLevel",
                         &prog_ref_level)) {
        return false;
      }
    }
    /* The error resilient frames of the low-delay profiles and the USAC
     * frame carry no fill elements, so there is nowhere to put the
     * metadata. */
    if (drc_profile != AAC_DRC_NONE && (low_delay || usac)) {
      throw_error(env, "AacEncoder: metadata is not available for the ld, eld and xhe profiles");
      return false;
    }
    /* The USAC options: noise filling, the pre-roll, the independent
     * frame schedule (in frames, milliseconds or samples, with its
     * distance bound), the segment size ceiling, the stream id, and SBR
     * and MPS 212 as on, off or auto.  A field left out keeps the
     * encoder's default. */
    bool have_usac = false;
    AacUsacEncoderOptions usac_opt;
    memset(&usac_opt, 0, sizeof(usac_opt));
    usac_opt.noise_filling = -1;
    usac_opt.preroll = -1;
    usac_opt.stream_id = -1;
    usac_opt.sbr = -1;
    usac_opt.mps212 = -1;
    if (option_given(o, "usac")) {
      if (!o.Get("usac").IsObject()) {
        throw_error(env, "AacEncoder: usac must be an object");
        return false;
      }
      if (!usac) {
        throw_error(env, "AacEncoder: usac options need the xhe profile");
        return false;
      }
      const Napi::Object u = o.Get("usac").As<Napi::Object>();
      have_usac = true;
      if (option_given(u, "noiseFilling")) {
        usac_opt.noise_filling = u.Get("noiseFilling").ToBoolean().Value() ? 1 : 0;
      }
      if (option_given(u, "preroll")) {
        usac_opt.preroll = u.Get("preroll").ToBoolean().Value() ? 1 : 0;
      }
      if (!option_number(env, u, "independentIntervalFrames",
                         "AacEncoder: usac.independentIntervalFrames",
                         &usac_opt.independent_interval_frames) ||
          !option_number(env, u, "independentIntervalMs", "AacEncoder: usac.independentIntervalMs",
                         &usac_opt.independent_interval_ms) ||
          !option_number(env, u, "independentIntervalSamples",
                         "AacEncoder: usac.independentIntervalSamples",
                         &usac_opt.independent_interval_samples) ||
          !option_number(env, u, "independentMaxDistanceFrames",
                         "AacEncoder: usac.independentMaxDistanceFrames",
                         &usac_opt.independent_max_distance_frames) ||
          !option_number(env, u, "segmentMaxBytes", "AacEncoder: usac.segmentMaxBytes",
                         &usac_opt.segment_max_bytes) ||
          !option_number(env, u, "streamId", "AacEncoder: usac.streamId", &usac_opt.stream_id)) {
        return false;
      }
      if (usac_opt.independent_interval_frames < 0 || usac_opt.independent_interval_ms < 0 ||
          usac_opt.independent_interval_samples < 0 ||
          usac_opt.independent_max_distance_frames < 0 || usac_opt.segment_max_bytes < 0) {
        throw_error(env, "AacEncoder: usac.independentIntervalFrames, independentIntervalMs, "
                         "independentIntervalSamples, independentMaxDistanceFrames and "
                         "segmentMaxBytes must be 0 or more");
        return false;
      }
      if (usac_opt.stream_id < -1 || usac_opt.stream_id > 65535) {
        throw_error(env, "AacEncoder: usac.streamId must be 0 to 65535");
        return false;
      }
      if (!on_off_auto_of(option_string(u, "sbr", "auto"), &usac_opt.sbr) ||
          !on_off_auto_of(option_string(u, "mps212", "auto"), &usac_opt.mps212)) {
        throw_error(env, "AacEncoder: usac.sbr and usac.mps212 must be on, off or auto");
        return false;
      }
    }
    bool have_loudness_info = false;
    AacLoudnessInfo loudness_info;
    if (option_given(o, "loudnessInfo")) {
      if (!o.Get("loudnessInfo").IsObject()) {
        throw_error(env, "AacEncoder: loudnessInfo must be an object");
        return false;
      }
      if (!usac) {
        throw_error(env, "AacEncoder: loudnessInfo needs the xhe profile");
        return false;
      }
      if (!loudness_info_of(env, o.Get("loudnessInfo").As<Napi::Object>(), &loudness_info)) {
        return false;
      }
      have_loudness_info = true;
    }
    const bool measure = option_bool(o, "measureLoudness", false);
    int ret = 0;
    if ((ret = aac_encoder_set_transport(handle_, transport)) < 0 ||
        (ret = aac_encoder_set_signaling(handle_, signaling)) < 0 ||
        (frame_length && (ret = aac_encoder_set_frame_length(handle_, frame_length)) < 0) ||
        (ret = aac_encoder_set_crc(handle_, option_bool(o, "crc", false) ? 1 : 0)) < 0 ||
        (ret = aac_encoder_set_mpeg2(handle_, option_bool(o, "mpeg2", false) ? 1 : 0)) < 0 ||
        (mode != AAC_MODE_DEFAULT && (ret = aac_encoder_set_channel_mode(handle_, mode)) < 0) ||
        (ld_sbr && (ret = aac_encoder_set_ld_sbr(handle_, 1, dual_rate ? 1 : 0)) < 0) ||
        (ret = aac_encoder_set_afterburner(handle_, option_bool(o, "afterburner", false) ? 1 : 0)) < 0 ||
        (drc_profile != AAC_DRC_NONE &&
         (ret = aac_encoder_set_metadata(handle_, drc_profile, prog_ref_level)) < 0) ||
        (have_usac && (ret = aac_encoder_set_usac(handle_, &usac_opt)) < 0) ||
        (have_loudness_info && (ret = aac_encoder_set_loudness_info(handle_, &loudness_info)) < 0) ||
        (measure && (ret = aac_encoder_set_loudness_measurement(handle_, 1)) < 0)) {
      throw_error(env, std::string("AacEncoder: ") + error_name(ret));
      return false;
    }
    /* Tuning: the peak first, so that it can lower the VBR mode; a
     * quality given with a VBR mode overrides the mode's own. */
    int peak_bitrate = 0;
    int bandwidth = 0;
    int vbr_mode = 0;
    if (!option_number(env, o, "peakBitrate", "peakBitrate", &peak_bitrate) ||
        !option_number(env, o, "bandwidth", "bandwidth", &bandwidth) ||
        !option_number(env, o, "vbrMode", "vbrMode", &vbr_mode)) {
      return false;
    }
    if ((peak_bitrate && (ret = aac_encoder_set_peak_bitrate(handle_, peak_bitrate)) < 0) ||
        (vbr_mode && (ret = aac_encoder_set_vbr_mode(handle_, vbr_mode)) < 0) ||
        (vbr_mode && option_given(o, "quality") &&
         (ret = aac_encoder_set_quality(handle_, option_int(o, "quality", 0))) < 0) ||
        (bandwidth && (ret = aac_encoder_set_bandwidth(handle_, bandwidth)) < 0)) {
      throw_error(env, std::string("AacEncoder: peakBitrate, vbrMode or bandwidth: ") +
                           error_name(ret));
      return false;
    }
    return true;
  }

  /* Encodes a block held in block_ and appends the frame to result. */
  bool encode_block(const Napi::Env& env, std::vector<uint8_t>& result) {
    const int len = aac_encoder_encode(handle_, block_.data(), frame_size_, out_.data(),
                                       static_cast<int>(out_.size()));
    if (len < 0) {
      throw_error(env, std::string("AacEncoder.encode: ") + error_name(len));
      return false;
    }
    result.insert(result.end(), out_.begin(), out_.begin() + len);
    unit_sizes_.push_back(len);
    return true;
  }

  /* Takes interleaved float samples of any length; returns the frames that
   * became complete. */
  Napi::Value Encode(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "AacEncoder: not initialized");
    }
    if (info.Length() < 1 || !info[0].IsTypedArray() ||
        info[0].As<Napi::TypedArray>().TypedArrayType() != napi_float32_array) {
      return throw_error(env, "AacEncoder.encode: expected a Float32Array of interleaved samples");
    }
    const Napi::Float32Array in = info[0].As<Napi::Float32Array>();
    pending_.insert(pending_.end(), in.Data(), in.Data() + in.ElementLength());

    unit_sizes_.clear();
    std::vector<uint8_t> result;
    size_t pos = 0;
    while (pending_.size() - pos >= block_.size()) {
      memcpy(block_.data(), pending_.data() + pos, block_.size() * sizeof(float));
      if (!encode_block(env, result)) {
        return env.Undefined();
      }
      pos += block_.size();
    }
    pending_.erase(pending_.begin(), pending_.begin() + static_cast<ptrdiff_t>(pos));
    return Napi::Buffer<uint8_t>::Copy(env, result.data(), result.size());
  }

  /* Pads the remaining samples to a frame, then drains the encoder delay.
   * The encoder stays usable for a new stream afterwards. */
  Napi::Value Flush(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "AacEncoder: not initialized");
    }
    unit_sizes_.clear();
    std::vector<uint8_t> result;
    if (!pending_.empty()) {
      std::fill(block_.begin(), block_.end(), 0.0f);
      memcpy(block_.data(), pending_.data(), pending_.size() * sizeof(float));
      pending_.clear();
      if (!encode_block(env, result)) {
        return env.Undefined();
      }
    }
    /* A packet transport yields one unit per call; a stream transport
     * writes the rest at once and returns 0 next. */
    for (;;) {
      const int len = aac_encoder_flush(handle_, out_.data(), static_cast<int>(out_.size()));
      if (len < 0) {
        return throw_error(env, std::string("AacEncoder.flush: ") + error_name(len));
      }
      if (len == 0) {
        break;
      }
      result.insert(result.end(), out_.begin(), out_.begin() + len);
      unit_sizes_.push_back(len);
    }
    return Napi::Buffer<uint8_t>::Copy(env, result.data(), result.size());
  }

  /* 'xhe' only: the next frame is independent and opens the pre-roll
   * of the immediate playout frame that follows it. */
  Napi::Value RequestIndependentFrame(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "AacEncoder: not initialized");
    }
    const int ret = aac_encoder_request_independent_frame(handle_);
    if (ret < 0) {
      return throw_error(env, std::string("AacEncoder.requestIndependentFrame: ") + error_name(ret));
    }
    return env.Undefined();
  }
  /* 'xhe' only: the last frame produced by encode or flush, for a muxer
   * (a flush of a stream transport reports its last frame); null before
   * the first frame and for the other profiles. */
  Napi::Value LastFrame(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    AacEncoderFrameInfo frame;
    if (!handle_ || aac_encoder_last_frame_info(handle_, &frame) != AAC_OK) {
      return env.Null();
    }
    Napi::Object o = Napi::Object::New(env);
    o.Set("frameIndex", Napi::Number::New(env, frame.frame_index));
    o.Set("independent", Napi::Boolean::New(env, frame.independent != 0));
    o.Set("prerollBytes", Napi::Number::New(env, frame.preroll_bytes));
    o.Set("bytes", Napi::Number::New(env, frame.bytes));
    o.Set("samplePosition", Napi::Number::New(env, static_cast<double>(frame.sample_position)));
    return o;
  }
  Napi::Value SampleRate(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), sample_rate_);
  }
  Napi::Value Channels(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), channels_);
  }
  Napi::Value FrameSize(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), frame_size_);
  }
  Napi::Value Delay(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), handle_ ? aac_encoder_delay(handle_) : 0);
  }
  Napi::Value Profile(const Napi::CallbackInfo& info) {
    return Napi::String::New(info.Env(), profile_);
  }
  /* The loudness measured so far by the meter of `measureLoudness`, or
   * null while there is no meter or fewer than 400 ms were encoded.  The
   * figures of a flushed stream stay until the next stream begins. */
  Napi::Value Loudness(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    AacLoudnessInfo result;
    if (!handle_ || aac_encoder_loudness(handle_, &result) != AAC_OK) {
      return env.Null();
    }
    return loudness_object(env, result);
  }
  /* Lengths of the units in the last encode or flush result: one per
   * frame for ADTS and LOAS, one per LATM element or access unit. */
  Napi::Value UnitSizes(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    Napi::Array sizes = Napi::Array::New(env, unit_sizes_.size());
    for (size_t i = 0; i < unit_sizes_.size(); i++) {
      sizes.Set(static_cast<uint32_t>(i), Napi::Number::New(env, unit_sizes_[i]));
    }
    return sizes;
  }
  /* The AudioSpecificConfig of the stream, for an MP4 esds box or an
   * out-of-band StreamMuxConfig. */
  Napi::Value Config(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    uint8_t asc[64];
    const int len = handle_ ? aac_encoder_config(handle_, asc, static_cast<int>(sizeof asc)) : 0;
    if (len < 0) {
      return throw_error(env, std::string("AacEncoder.config: ") + error_name(len));
    }
    return Napi::Buffer<uint8_t>::Copy(env, asc, static_cast<size_t>(len));
  }

  AacEncoderHandle handle_ = nullptr;
  int sample_rate_ = 0;
  int channels_ = 0;
  int frame_size_ = 0;
  std::string profile_;
  std::vector<float> pending_;
  std::vector<float> block_;
  std::vector<uint8_t> out_;
  std::vector<int> unit_sizes_;
};

/* measureLoudness(pcm, sampleRate, channels): the loudness meter over a
 * whole interleaved signal, for the two-pass flow. */
Napi::Value MeasureLoudness(const Napi::CallbackInfo& info) {
  const Napi::Env env = info.Env();
  if (info.Length() < 3 || !info[0].IsTypedArray() ||
      info[0].As<Napi::TypedArray>().TypedArrayType() != napi_float32_array ||
      !info[1].IsNumber() || !info[2].IsNumber()) {
    return throw_error(env, "measureLoudness: expected a Float32Array, the sample rate and the "
                            "channel count");
  }
  const Napi::Float32Array pcm = info[0].As<Napi::Float32Array>();
  const int sample_rate = info[1].As<Napi::Number>().Int32Value();
  const int channels = info[2].As<Napi::Number>().Int32Value();
  AacLoudnessMeter* meter = aac_loudness_create(sample_rate, channels);
  if (!meter) {
    return throw_error(env, "measureLoudness: 1 to 6 channels at 8000 to 384000 Hz");
  }
  aac_loudness_feed(meter, pcm.Data(), static_cast<int>(pcm.ElementLength() / channels));
  AacLoudnessInfo result;
  const int ret = aac_loudness_result(meter, &result);
  aac_loudness_destroy(meter);
  if (ret != AAC_OK) {
    return throw_error(env, "measureLoudness: at least 400 ms of input is needed");
  }
  return loudness_object(env, result);
}

Napi::Object InitModule(Napi::Env env, Napi::Object exports) {
  exports.Set("AacDecoder", Decoder::Init(env));
  exports.Set("AacEncoder", Encoder::Init(env));
  exports.Set("measureLoudness", Napi::Function::New(env, MeasureLoudness));
  exports.Set("version", Napi::String::New(env, "0.1.0"));
  return exports;
}

}  // namespace

NODE_API_MODULE(baander_aac, InitModule)
