/* Node-API binding for baander-aac: streaming ADTS decoder and PCM encoder
 * objects over the public C API.  PCM is interleaved 32-bit float at full
 * scale 1.0; AAC data is an ADTS stream.  Both classes accept input in
 * arbitrary chunk sizes and keep partial frames between calls. */
#include <napi.h>

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

int option_int(const Napi::Object& o, const char* key, int fallback) {
  if (!o.Has(key) || o.Get(key).IsUndefined()) {
    return fallback;
  }
  return o.Get(key).ToNumber().Int32Value();
}

/* ── decoder ───────────────────────────────────────────────────── */

class Decoder : public Napi::ObjectWrap<Decoder> {
 public:
  static Napi::Function Init(Napi::Env env) {
    return DefineClass(
        env, "AacDecoder",
        {InstanceMethod("decode", &Decoder::Decode), InstanceMethod("reset", &Decoder::Reset),
         InstanceAccessor("sampleRate", &Decoder::SampleRate, nullptr),
         InstanceAccessor("channels", &Decoder::Channels, nullptr),
         InstanceAccessor("frameSize", &Decoder::FrameSize, nullptr),
         InstanceAccessor("hasSbr", &Decoder::HasSbr, nullptr),
         InstanceAccessor("errors", &Decoder::Errors, nullptr)});
  }

  explicit Decoder(const Napi::CallbackInfo& info) : Napi::ObjectWrap<Decoder>(info) {
    const Napi::Env env = info.Env();
    int sample_rate = 44100;
    int channels = 2;
    if (info.Length() > 0 && info[0].IsObject()) {
      const Napi::Object o = info[0].As<Napi::Object>();
      sample_rate = option_int(o, "sampleRate", sample_rate);
      channels = option_int(o, "channels", channels);
    }
    handle_ = aac_decoder_create(sample_rate, channels);
    if (!handle_) {
      throw_error(env, "AacDecoder: unsupported sample rate or channel count");
      return;
    }
    channels_ = channels;
    pcm_.resize(static_cast<size_t>(aac_decoder_frame_size(handle_)) * channels);
  }

  ~Decoder() override { aac_decoder_destroy(handle_); }

 private:
  /* Decodes every complete ADTS frame in the pending bytes plus the new
   * chunk; a trailing partial frame waits for the next call. */
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
    pending_.insert(pending_.end(), data, data + size);

    std::vector<float> out;
    size_t pos = 0;
    while (pos + 7 <= pending_.size()) {
      if (pending_[pos] != 0xFF || (pending_[pos + 1] & 0xF6) != 0xF0) {
        pos++; /* resynchronize on the next syncword */
        continue;
      }
      const size_t len =
          ((pending_[pos + 3] & 3) << 11) | (pending_[pos + 4] << 3) | (pending_[pos + 5] >> 5);
      if (len < 7) {
        pos++;
        continue;
      }
      if (pos + len > pending_.size()) {
        break; /* partial frame */
      }
      const int n = aac_decoder_decode(handle_, pending_.data() + pos, static_cast<int>(len),
                                       pcm_.data(), static_cast<int>(pcm_.size()));
      if (n < 0) {
        errors_++;
      } else {
        out.insert(out.end(), pcm_.begin(), pcm_.begin() + static_cast<size_t>(n) * channels_);
      }
      pos += len;
    }
    pending_.erase(pending_.begin(), pending_.begin() + static_cast<ptrdiff_t>(pos));

    Napi::Float32Array result = Napi::Float32Array::New(env, out.size());
    if (!out.empty()) {
      memcpy(result.Data(), out.data(), out.size() * sizeof(float));
    }
    return result;
  }

  Napi::Value Reset(const Napi::CallbackInfo& info) {
    pending_.clear();
    errors_ = 0;
    return info.Env().Undefined();
  }

  Napi::Value SampleRate(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), handle_ ? aac_decoder_sample_rate(handle_) : 0);
  }
  Napi::Value Channels(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), channels_);
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
  Napi::Value Errors(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), errors_);
  }

  AacDecoderHandle handle_ = nullptr;
  int channels_ = 0;
  int errors_ = 0;
  std::vector<uint8_t> pending_;
  std::vector<float> pcm_;
};

/* ── encoder ───────────────────────────────────────────────────── */

class Encoder : public Napi::ObjectWrap<Encoder> {
 public:
  static Napi::Function Init(Napi::Env env) {
    return DefineClass(
        env, "AacEncoder",
        {InstanceMethod("encode", &Encoder::Encode), InstanceMethod("flush", &Encoder::Flush),
         InstanceAccessor("sampleRate", &Encoder::SampleRate, nullptr),
         InstanceAccessor("channels", &Encoder::Channels, nullptr),
         InstanceAccessor("frameSize", &Encoder::FrameSize, nullptr),
         InstanceAccessor("delay", &Encoder::Delay, nullptr)});
  }

  explicit Encoder(const Napi::CallbackInfo& info) : Napi::ObjectWrap<Encoder>(info) {
    const Napi::Env env = info.Env();
    int bitrate = 128000;
    int quality = 0;
    AacRateControl rc = AAC_RC_CBR;
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
    }
    handle_ = aac_encoder_create(sample_rate_, channels_, bitrate, AAC_AOT_LC, rc);
    if (!handle_) {
      throw_error(env, "AacEncoder: unsupported sample rate, channel count or bitrate");
      return;
    }
    if (quality && aac_encoder_set_quality(handle_, quality) != AAC_OK) {
      aac_encoder_destroy(handle_);
      handle_ = nullptr;
      throw_error(env, "AacEncoder: quality must be 1..100");
      return;
    }
    frame_size_ = aac_encoder_frame_size(handle_);
    block_.resize(static_cast<size_t>(frame_size_) * channels_);
    out_.resize(16384);
  }

  ~Encoder() override { aac_encoder_destroy(handle_); }

 private:
  /* Encodes a block held in block_ and appends the ADTS frame to result. */
  bool encode_block(const Napi::Env& env, std::vector<uint8_t>& result) {
    const int len = aac_encoder_encode(handle_, block_.data(), frame_size_, out_.data(),
                                       static_cast<int>(out_.size()));
    if (len < 0) {
      throw_error(env, std::string("AacEncoder.encode: ") + error_name(len));
      return false;
    }
    result.insert(result.end(), out_.begin(), out_.begin() + len);
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
    std::vector<uint8_t> result;
    if (!pending_.empty()) {
      std::fill(block_.begin(), block_.end(), 0.0f);
      memcpy(block_.data(), pending_.data(), pending_.size() * sizeof(float));
      pending_.clear();
      if (!encode_block(env, result)) {
        return env.Undefined();
      }
    }
    const int len = aac_encoder_flush(handle_, out_.data(), static_cast<int>(out_.size()));
    if (len < 0) {
      return throw_error(env, std::string("AacEncoder.flush: ") + error_name(len));
    }
    result.insert(result.end(), out_.begin(), out_.begin() + len);
    return Napi::Buffer<uint8_t>::Copy(env, result.data(), result.size());
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

  AacEncoderHandle handle_ = nullptr;
  int sample_rate_ = 0;
  int channels_ = 0;
  int frame_size_ = 0;
  std::vector<float> pending_;
  std::vector<float> block_;
  std::vector<uint8_t> out_;
};

Napi::Object InitModule(Napi::Env env, Napi::Object exports) {
  exports.Set("AacDecoder", Decoder::Init(env));
  exports.Set("AacEncoder", Encoder::Init(env));
  exports.Set("version", Napi::String::New(env, "0.1.0"));
  return exports;
}

}  // namespace

NODE_API_MODULE(baander_aac, InitModule)
