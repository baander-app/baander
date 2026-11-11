/* The lossless mode in the Node-API binding: a FLAC encoder and a FLAC
 * decoder over the C API of flac.h.  PCM is an Int32Array of interleaved
 * samples at the stream's bit depth (a 16-bit sample is a value in
 * -32768..32767); a FLAC stream is bytes.  Both classes take their input
 * in chunks of any size. */
#include <napi.h>

#include <cmath>
#include <cstdint>
#include <cstring>
#include <string>
#include <vector>

#include "addon_common.h"
#include "flac.h"

namespace {

/* A count of samples as an option: a whole number a double holds
 * exactly.  `*out` keeps its value when the option is absent.  Returns
 * false after throwing. */
bool option_count(const Napi::Env& env, const Napi::Object& o, const char* key, const char* what,
                  uint64_t* out) {
  if (!o.Has(key) || o.Get(key).IsUndefined()) {
    return true;
  }
  const Napi::Value v = o.Get(key);
  const double d = v.IsNumber() ? v.As<Napi::Number>().DoubleValue() : -1.0;
  if (!v.IsNumber() || !(d >= 0.0 && d <= 9007199254740992.0) || d != std::floor(d)) {
    throw_error(env, std::string(what) + " must be a whole number that is not negative");
    return false;
  }
  *out = static_cast<uint64_t>(d);
  return true;
}

/* A boolean option that, when given, has to be a boolean. */
bool option_flag(const Napi::Env& env, const Napi::Object& o, const char* key, const char* what,
                 bool* out) {
  if (!o.Has(key) || o.Get(key).IsUndefined()) {
    return true;
  }
  if (!o.Get(key).IsBoolean()) {
    throw_error(env, std::string(what) + " must be a boolean");
    return false;
  }
  *out = o.Get(key).As<Napi::Boolean>().Value();
  return true;
}

/* A count option of a picture (its type, a side in pixels, ...). */
bool picture_number(const Napi::Env& env, const Napi::Object& o, const char* key, uint32_t* out) {
  uint64_t value = *out;
  if (!option_count(env, o, key, (std::string("FlacEncoder: pictures[].") + key).c_str(), &value)) {
    return false;
  }
  if (value > 0xFFFFFFFFull) {
    throw_error(env, std::string("FlacEncoder: pictures[].") + key + " is out of range");
    return false;
  }
  *out = static_cast<uint32_t>(value);
  return true;
}

/* The tags and pictures of the options, in memory that lives as long as
 * this object: what the library is shown points into it. */
struct TagStore {
  std::vector<std::string> tags;
  std::string vendor;
  bool have_vendor = false;
  std::vector<std::string> mime_types;
  std::vector<std::string> descriptions;
  std::vector<BaacFlacComment> comments;
  std::vector<BaacFlacPicture> pictures;

  /* Reads `tags`, `vendor` and `pictures`.  Returns false after
   * throwing. */
  bool read(const Napi::Env& env, const Napi::Object& o) {
    if (o.Has("tags") && !o.Get("tags").IsUndefined()) {
      if (!o.Get("tags").IsArray()) {
        throw_error(env, "FlacEncoder: tags must be an array of 'NAME=value' strings");
        return false;
      }
      const Napi::Array list = o.Get("tags").As<Napi::Array>();
      for (uint32_t i = 0; i < list.Length(); i++) {
        const Napi::Value v = list.Get(i);
        if (!v.IsString()) {
          throw_error(env, "FlacEncoder: tags must be an array of 'NAME=value' strings");
          return false;
        }
        tags.push_back(v.As<Napi::String>().Utf8Value());
      }
    }
    if (o.Has("vendor") && !o.Get("vendor").IsUndefined()) {
      if (!o.Get("vendor").IsString()) {
        throw_error(env, "FlacEncoder: vendor must be a string");
        return false;
      }
      vendor = o.Get("vendor").As<Napi::String>().Utf8Value();
      have_vendor = true;
    }
    if (o.Has("pictures") && !o.Get("pictures").IsUndefined()) {
      if (!o.Get("pictures").IsArray()) {
        throw_error(env, "FlacEncoder: pictures must be an array");
        return false;
      }
      const Napi::Array list = o.Get("pictures").As<Napi::Array>();
      /* The strings are all read before any is pointed at. */
      mime_types.resize(list.Length());
      descriptions.resize(list.Length());
      for (uint32_t i = 0; i < list.Length(); i++) {
        if (!list.Get(i).IsObject()) {
          throw_error(env, "FlacEncoder: pictures must be an array of objects");
          return false;
        }
        const Napi::Object p = list.Get(i).As<Napi::Object>();
        BaacFlacPicture picture = {};
        picture.type = 3;
        const uint8_t* data = nullptr;
        size_t size = 0;
        if (!p.Has("mimeType") || !p.Get("mimeType").IsString()) {
          throw_error(env, "FlacEncoder: pictures[].mimeType must be a string");
          return false;
        }
        if (!p.Has("data") || !bytes_of(p.Get("data"), &data, &size)) {
          throw_error(env,
                      "FlacEncoder: pictures[].data must be a Buffer, Uint8Array or "
                      "ArrayBuffer");
          return false;
        }
        if (p.Has("description") && !p.Get("description").IsUndefined()) {
          if (!p.Get("description").IsString()) {
            throw_error(env, "FlacEncoder: pictures[].description must be a string");
            return false;
          }
          descriptions[i] = p.Get("description").As<Napi::String>().Utf8Value();
        }
        if (size > BAAC_FLAC_MAX_PICTURE_BYTES) {
          throw_error(env,
                      "FlacEncoder: pictures[].data is larger than a FLAC picture block "
                      "holds");
          return false;
        }
        mime_types[i] = p.Get("mimeType").As<Napi::String>().Utf8Value();
        if (!picture_number(env, p, "type", &picture.type) ||
            !picture_number(env, p, "width", &picture.width) ||
            !picture_number(env, p, "height", &picture.height) ||
            !picture_number(env, p, "depth", &picture.depth) ||
            !picture_number(env, p, "colors", &picture.colors)) {
          return false;
        }
        picture.data = data;
        picture.data_bytes = static_cast<uint32_t>(size);
        pictures.push_back(picture);
      }
      for (size_t i = 0; i < pictures.size(); i++) {
        pictures[i].mime_type = mime_types[i].c_str();
        pictures[i].description = descriptions[i].c_str();
      }
    }
    for (const std::string& tag : tags) {
      comments.push_back({tag.data(), static_cast<uint32_t>(tag.size())});
    }
    return true;
  }

  void apply(BaacFlacEncoderOptions* options) const {
    options->comments = comments.data();
    options->comment_count = static_cast<int>(comments.size());
    options->vendor = have_vendor ? vendor.c_str() : nullptr;
    options->pictures = pictures.data();
    options->picture_count = static_cast<int>(pictures.size());
  }
};

Napi::Object stream_info_object(const Napi::Env& env, const BaacFlacStreamInfo& info) {
  Napi::Object o = Napi::Object::New(env);
  o.Set("minBlockSize", Napi::Number::New(env, info.min_block_size));
  o.Set("maxBlockSize", Napi::Number::New(env, info.max_block_size));
  o.Set("minFrameBytes", Napi::Number::New(env, info.min_frame_bytes));
  o.Set("maxFrameBytes", Napi::Number::New(env, info.max_frame_bytes));
  o.Set("sampleRate", Napi::Number::New(env, info.sample_rate));
  o.Set("channels", Napi::Number::New(env, info.channels));
  o.Set("bitsPerSample", Napi::Number::New(env, info.bits_per_sample));
  o.Set("totalSamples", Napi::Number::New(env, static_cast<double>(info.total_samples)));
  o.Set("md5", Napi::Buffer<uint8_t>::Copy(env, info.md5, sizeof(info.md5)));
  return o;
}

Napi::Object frame_info_object(const Napi::Env& env, const BaacFlacFrameInfo& info) {
  Napi::Object o = Napi::Object::New(env);
  o.Set("samplePosition", Napi::Number::New(env, static_cast<double>(info.sample_position)));
  o.Set("blockSize", Napi::Number::New(env, info.block_size));
  o.Set("bytes", Napi::Number::New(env, info.bytes));
  return o;
}

/* ── encoder ───────────────────────────────────────────────────── */

class FlacEncoder : public Napi::ObjectWrap<FlacEncoder> {
 public:
  static Napi::Function Init(Napi::Env env) {
    return DefineClass(env, "FlacEncoder",
                       {InstanceMethod("encode", &FlacEncoder::Encode),
                        InstanceMethod("finish", &FlacEncoder::Finish),
                        InstanceMethod("finalHeader", &FlacEncoder::FinalHeader),
                        InstanceAccessor("headerBytes", &FlacEncoder::HeaderBytes, nullptr),
                        InstanceAccessor("lastFrame", &FlacEncoder::LastFrame, nullptr),
                        InstanceAccessor("streamInfo", &FlacEncoder::StreamInfo, nullptr),
                        InstanceAccessor("sampleRate", &FlacEncoder::SampleRate, nullptr),
                        InstanceAccessor("channels", &FlacEncoder::Channels, nullptr),
                        InstanceAccessor("bitsPerSample", &FlacEncoder::BitsPerSample, nullptr),
                        InstanceAccessor("level", &FlacEncoder::Level, nullptr),
                        InstanceAccessor("blockSize", &FlacEncoder::BlockSize, nullptr)});
  }

  explicit FlacEncoder(const Napi::CallbackInfo& info) : Napi::ObjectWrap<FlacEncoder>(info) {
    const Napi::Env env = info.Env();
    BaacFlacEncoderOptions options;
    baac_flac_encoder_options_init(&options);
    bool seek_table = false;
    TagStore store;
    if (info.Length() > 0 && info[0].IsObject()) {
      const Napi::Object o = info[0].As<Napi::Object>();
      if (!store.read(env, o)) {
        return;
      }
      if (!option_number(env, o, "sampleRate", "FlacEncoder: sampleRate", &sample_rate_) ||
          !option_number(env, o, "channels", "FlacEncoder: channels", &channels_) ||
          !option_number(env, o, "bitsPerSample", "FlacEncoder: bitsPerSample", &bits_) ||
          !option_number(env, o, "level", "FlacEncoder: level", &level_) ||
          !option_number(env, o, "padding", "FlacEncoder: padding", &options.padding_bytes) ||
          !option_count(env, o, "totalSamples", "FlacEncoder: totalSamples",
                        &options.total_samples_hint) ||
          !option_flag(env, o, "seekTable", "FlacEncoder: seekTable", &seek_table)) {
        return;
      }
    }
    options.seek_table = seek_table ? 1 : 0;
    handle_ = baac_flac_encoder_create(sample_rate_, channels_, bits_, level_, nullptr);
    if (!handle_) {
      throw_error(env,
                  "FlacEncoder: unsupported configuration (1 to 8 channels; 8, 12, 16, 20, "
                  "24 or 32 bits; a sample rate a FLAC frame header can state; level 0 to "
                  "8)");
      return;
    }
    /* The library copies the tags and pictures; the store may go. */
    store.apply(&options);
    const int ret = baac_flac_encoder_set_options(handle_, &options);
    if (ret != AAC_OK) {
      throw_error(env,
                  "FlacEncoder: options the format does not take: padding up to 16777215 "
                  "bytes, a total within 36 bits, tags of 'NAME=value' with a name of "
                  "printable ASCII, a picture type up to 20 with a media type of printable "
                  "ASCII, one picture each of types 1 and 2, and tags and each picture "
                  "within a block of 16777215 bytes");
      return;
    }
  }

  ~FlacEncoder() override { baac_flac_encoder_destroy(handle_); }

 private:
  /* The header of a stream that has not begun, appended to `out`. */
  bool begin(std::vector<uint8_t>* out) {
    if (started_) {
      return true;
    }
    const int size = baac_flac_encoder_header_bytes(handle_);
    if (size < 0) {
      return false;
    }
    out->resize(static_cast<size_t>(size));
    if (baac_flac_encoder_write_header(handle_, out->data(), size) != size) {
      return false;
    }
    started_ = true;
    return true;
  }

  /* Codes the samples and returns every frame they complete.  The first
   * result of a stream begins with the stream's header. */
  Napi::Value Encode(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "FlacEncoder: not initialized");
    }
    if (info.Length() < 1 || !info[0].IsTypedArray() ||
        info[0].As<Napi::TypedArray>().TypedArrayType() != napi_int32_array) {
      return throw_error(env, "FlacEncoder.encode: expected an Int32Array");
    }
    const Napi::Int32Array pcm = info[0].As<Napi::Int32Array>();
    const size_t values = pcm.ElementLength();
    if (values % static_cast<size_t>(channels_) != 0) {
      return throw_error(env,
                         "FlacEncoder.encode: the sample count is not a multiple of the "
                         "channel count");
    }
    /* Every sample is checked before any is coded, so that a refused
     * call has written nothing. */
    const int32_t* samples = pcm.Data();
    if (bits_ < 32) {
      const int32_t limit = static_cast<int32_t>(1) << (bits_ - 1);
      for (size_t i = 0; i < values; i++) {
        if (samples[i] < -limit || samples[i] >= limit) {
          return throw_error(env, "FlacEncoder.encode: a sample lies outside the bit depth");
        }
      }
    }
    std::vector<uint8_t> out;
    if (!begin(&out)) {
      return throw_error(env, "FlacEncoder.encode: the stream cannot begin");
    }
    const size_t frames = values / static_cast<size_t>(channels_);
    const size_t step = 1 << 16;
    for (size_t pos = 0; pos < frames; pos += step) {
      const int count = static_cast<int>(frames - pos < step ? frames - pos : step);
      const int bound = baac_flac_encoder_max_output_bytes(handle_, count);
      if (bound < 0) {
        return throw_error(env, std::string("FlacEncoder.encode: ") + error_name(bound));
      }
      const size_t at = out.size();
      out.resize(at + static_cast<size_t>(bound));
      const int n = baac_flac_encoder_encode(handle_, samples + pos * channels_, count,
                                             out.data() + at, bound);
      if (n < 0) {
        return throw_error(env, std::string("FlacEncoder.encode: ") + error_name(n));
      }
      out.resize(at + static_cast<size_t>(n));
    }
    return Napi::Buffer<uint8_t>::Copy(env, out.data(), out.size());
  }

  /* Ends the stream: the samples still held as its last, shorter frame.
   * The next encode() starts a new stream. */
  Napi::Value Finish(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "FlacEncoder: not initialized");
    }
    std::vector<uint8_t> out;
    if (!begin(&out)) {
      return throw_error(env, "FlacEncoder.finish: the stream cannot begin");
    }
    const int bound = baac_flac_encoder_max_output_bytes(handle_, 0);
    const size_t at = out.size();
    out.resize(at + static_cast<size_t>(bound > 0 ? bound : 0));
    const int n = baac_flac_encoder_finish(handle_, out.data() + at, bound);
    if (n < 0) {
      return throw_error(env, std::string("FlacEncoder.finish: ") + error_name(n));
    }
    out.resize(at + static_cast<size_t>(n));
    started_ = false;
    return Napi::Buffer<uint8_t>::Copy(env, out.data(), out.size());
  }

  /* After finish(): the header again, of the same length, with the
   * sample total, the MD5 and the seek points, to be written over the
   * first one. */
  Napi::Value FinalHeader(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "FlacEncoder: not initialized");
    }
    const int size = baac_flac_encoder_header_bytes(handle_);
    std::vector<uint8_t> out(static_cast<size_t>(size > 0 ? size : 0));
    const int n = baac_flac_encoder_final_header(handle_, out.data(), size);
    if (n < 0) {
      return throw_error(env,
                         std::string("FlacEncoder.finalHeader: ") +
                             (n == AAC_ERR_STATE ? "the stream is not finished" : error_name(n)));
    }
    return Napi::Buffer<uint8_t>::Copy(env, out.data(), static_cast<size_t>(n));
  }

  Napi::Value HeaderBytes(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), handle_ ? baac_flac_encoder_header_bytes(handle_) : 0);
  }

  Napi::Value LastFrame(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    BaacFlacFrameInfo frame;
    if (!handle_ || baac_flac_encoder_last_frame_info(handle_, &frame) != AAC_OK) {
      return env.Null();
    }
    return frame_info_object(env, frame);
  }

  Napi::Value StreamInfo(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    BaacFlacStreamInfo stream;
    if (!handle_ || baac_flac_encoder_stream_info(handle_, &stream) != AAC_OK) {
      return env.Null();
    }
    return stream_info_object(env, stream);
  }

  Napi::Value SampleRate(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), sample_rate_);
  }
  Napi::Value Channels(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), channels_);
  }
  Napi::Value BitsPerSample(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), bits_);
  }
  Napi::Value Level(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), level_);
  }
  Napi::Value BlockSize(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), handle_ ? baac_flac_encoder_block_size(handle_) : 0);
  }

  BaacFlacEncoder* handle_ = nullptr;
  int sample_rate_ = 44100;
  int channels_ = 2;
  int bits_ = 16;
  int level_ = BAAC_FLAC_LEVEL_DEFAULT;
  bool started_ = false; /* the header of the running stream is out */
};

/* ── decoder ───────────────────────────────────────────────────── */

class FlacDecoder : public Napi::ObjectWrap<FlacDecoder> {
 public:
  static Napi::Function Init(Napi::Env env) {
    return DefineClass(
        env, "FlacDecoder",
        {InstanceMethod("decode", &FlacDecoder::Decode),
         InstanceMethod("finish", &FlacDecoder::Finish),
         InstanceMethod("reset", &FlacDecoder::Reset),
         InstanceMethod("seekReset", &FlacDecoder::SeekReset),
         InstanceAccessor("streamInfo", &FlacDecoder::StreamInfo, nullptr),
         InstanceAccessor("lastFrame", &FlacDecoder::LastFrame, nullptr),
         InstanceAccessor("sampleRate", &FlacDecoder::SampleRate, nullptr),
         InstanceAccessor("channels", &FlacDecoder::Channels, nullptr),
         InstanceAccessor("bitsPerSample", &FlacDecoder::BitsPerSample, nullptr),
         InstanceAccessor("errors", &FlacDecoder::Errors, nullptr),
         InstanceAccessor("md5", &FlacDecoder::Md5, nullptr),
         InstanceAccessor("seekPoints", &FlacDecoder::SeekPoints, nullptr),
         InstanceAccessor("firstFrameOffset", &FlacDecoder::FirstFrameOffset, nullptr),
         InstanceAccessor("tags", &FlacDecoder::Tags, nullptr),
         InstanceAccessor("vendor", &FlacDecoder::Vendor, nullptr),
         InstanceAccessor("pictures", &FlacDecoder::Pictures, nullptr),
         InstanceAccessor("skippedMetadata", &FlacDecoder::SkippedMetadata, nullptr)});
  }

  explicit FlacDecoder(const Napi::CallbackInfo& info) : Napi::ObjectWrap<FlacDecoder>(info) {
    const Napi::Env env = info.Env();
    bool md5 = false;
    bool metadata = false;
    uint64_t metadata_bytes = 0;
    const uint8_t* streaminfo = nullptr;
    size_t streaminfo_size = 0;
    if (info.Length() > 0 && info[0].IsObject()) {
      const Napi::Object o = info[0].As<Napi::Object>();
      if (!option_flag(env, o, "md5", "FlacDecoder: md5", &md5)) {
        return;
      }
      /* metadata: true, or { maxBytes } for a limit of the caller's. */
      if (o.Has("metadata") && !o.Get("metadata").IsUndefined()) {
        const Napi::Value m = o.Get("metadata");
        if (m.IsBoolean()) {
          metadata = m.As<Napi::Boolean>().Value();
        } else if (m.IsObject()) {
          metadata = true;
          if (!option_count(env, m.As<Napi::Object>(), "maxBytes", "FlacDecoder: metadata.maxBytes",
                            &metadata_bytes)) {
            return;
          }
        } else {
          throw_error(env, "FlacDecoder: metadata must be a boolean or an object");
          return;
        }
      }
      if (o.Has("streamInfo") && !o.Get("streamInfo").IsUndefined() &&
          !bytes_of(o.Get("streamInfo"), &streaminfo, &streaminfo_size)) {
        throw_error(env, "FlacDecoder: streamInfo must be a Buffer, Uint8Array or ArrayBuffer");
        return;
      }
    }
    handle_ = baac_flac_decoder_create();
    if (!handle_) {
      throw_error(env, "FlacDecoder: out of memory");
      return;
    }
    baac_flac_decoder_set_md5_check(handle_, md5 ? 1 : 0);
    baac_flac_decoder_set_metadata(handle_, metadata ? 1 : 0, static_cast<size_t>(metadata_bytes));
    if (streaminfo && baac_flac_decoder_set_streaminfo(
                          handle_, streaminfo, static_cast<int>(streaminfo_size)) != AAC_OK) {
      throw_error(env,
                  "FlacDecoder: streamInfo is not a STREAMINFO block of 34 bytes with "
                  "values the format allows");
      return;
    }
    pcm_.resize(static_cast<size_t>(4096) * BAAC_FLAC_MAX_CHANNELS);
  }

  ~FlacDecoder() override { baac_flac_decoder_destroy(handle_); }

 private:
  /* Decodes every frame that is complete and appends its samples to
   * `out`.  A damaged stretch is counted by the decoder and decoding
   * goes on behind it; false, after throwing, for a stream that cannot
   * be decoded at all. */
  bool drain(const Napi::Env& env, const char* where, std::vector<int32_t>* out, int* frames) {
    for (;;) {
      const int n =
          baac_flac_decoder_decode_next(handle_, pcm_.data(), static_cast<int>(pcm_.size()));
      if (n == 0) {
        return true;
      }
      if (n == AAC_ERR_OVERFLOW &&
          pcm_.size() < static_cast<size_t>(BAAC_FLAC_MAX_BLOCK_SIZE) * BAAC_FLAC_MAX_CHANNELS) {
        /* A block larger than the usual: room for the largest there is. */
        pcm_.resize(static_cast<size_t>(BAAC_FLAC_MAX_BLOCK_SIZE) * BAAC_FLAC_MAX_CHANNELS);
        continue;
      }
      if (n == AAC_ERR_DECODE) {
        (*frames)++;
        continue;
      }
      if (n < 0) {
        throw_error(env, std::string(where) + ": " + error_name(n));
        return false;
      }
      BaacFlacStreamInfo stream;
      if (baac_flac_decoder_stream_info(handle_, &stream) != AAC_OK) {
        throw_error(env, std::string(where) + ": the stream has no properties");
        return false;
      }
      out->insert(out->end(), pcm_.begin(),
                  pcm_.begin() + static_cast<size_t>(n) * static_cast<size_t>(stream.channels));
      (*frames)++;
    }
  }

  static Napi::Value samples(const Napi::Env& env, const std::vector<int32_t>& out) {
    Napi::Int32Array result = Napi::Int32Array::New(env, out.size());
    if (!out.empty()) {
      memcpy(result.Data(), out.data(), out.size() * sizeof(int32_t));
    }
    return result;
  }

  /* Takes the bytes and returns the samples of every frame they
   * complete; a partial frame waits for the next call. */
  Napi::Value Decode(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "FlacDecoder: not initialized");
    }
    const uint8_t* data = nullptr;
    size_t size = 0;
    if (info.Length() < 1 || !bytes_of(info[0], &data, &size)) {
      return throw_error(env, "FlacDecoder.decode: expected a Buffer, Uint8Array or ArrayBuffer");
    }
    std::vector<int32_t> out;
    size_t pos = 0;
    while (pos < size) {
      const size_t chunk = size - pos < (1u << 30) ? size - pos : (1u << 30);
      const int taken = baac_flac_decoder_feed(handle_, data + pos, static_cast<int>(chunk));
      if (taken < 0) {
        return throw_error(env, std::string("FlacDecoder.decode: ") +
                                    (taken == AAC_ERR_STATE ? "the stream is finished; reset() "
                                                              "starts another"
                                                            : error_name(taken)));
      }
      pos += static_cast<size_t>(taken);
      int frames = 0;
      if (!drain(env, "FlacDecoder.decode", &out, &frames)) {
        return env.Undefined();
      }
      if (taken == 0 && frames == 0) {
        return throw_error(env, "FlacDecoder.decode: input buffer full");
      }
    }
    return samples(env, out);
  }

  /* Ends the stream: no more bytes follow.  Returns the samples of the
   * frames that were waiting for that word; `md5` and `errors` then
   * state the result.  The decoder takes no input until reset(). */
  Napi::Value Finish(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "FlacDecoder: not initialized");
    }
    std::vector<int32_t> out;
    int frames = 0;
    baac_flac_decoder_end_input(handle_);
    if (!drain(env, "FlacDecoder.finish", &out, &frames)) {
      return env.Undefined();
    }
    baac_flac_decoder_finish(handle_, &verdict_);
    return samples(env, out);
  }

  Napi::Value Reset(const Napi::CallbackInfo& info) {
    if (handle_) {
      baac_flac_decoder_reset(handle_);
      verdict_ = BAAC_FLAC_MD5_UNKNOWN;
    }
    return info.Env().Undefined();
  }

  /* For a seek: drops the buffered bytes; the bytes given next may begin
   * anywhere in the stream. */
  Napi::Value SeekReset(const Napi::CallbackInfo& info) {
    if (handle_) {
      baac_flac_decoder_seek_reset(handle_);
      verdict_ = BAAC_FLAC_MD5_UNKNOWN;
    }
    return info.Env().Undefined();
  }

  bool stream_info(BaacFlacStreamInfo* stream) const {
    return handle_ && baac_flac_decoder_stream_info(handle_, stream) == AAC_OK;
  }

  Napi::Value StreamInfo(const Napi::CallbackInfo& info) {
    BaacFlacStreamInfo stream;
    return stream_info(&stream) ? Napi::Value(stream_info_object(info.Env(), stream))
                                : info.Env().Null();
  }

  Napi::Value LastFrame(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    BaacFlacFrameInfo frame;
    if (!handle_ || baac_flac_decoder_last_frame_info(handle_, &frame) != AAC_OK) {
      return env.Null();
    }
    return frame_info_object(env, frame);
  }

  Napi::Value SampleRate(const Napi::CallbackInfo& info) {
    BaacFlacStreamInfo stream;
    return Napi::Number::New(info.Env(), stream_info(&stream) ? stream.sample_rate : 0);
  }
  Napi::Value Channels(const Napi::CallbackInfo& info) {
    BaacFlacStreamInfo stream;
    return Napi::Number::New(info.Env(), stream_info(&stream) ? stream.channels : 0);
  }
  Napi::Value BitsPerSample(const Napi::CallbackInfo& info) {
    BaacFlacStreamInfo stream;
    return Napi::Number::New(info.Env(), stream_info(&stream) ? stream.bits_per_sample : 0);
  }

  /* Damaged stretches of the stream so far. */
  Napi::Value Errors(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), handle_ ? baac_flac_decoder_lost_frames(handle_) : 0);
  }

  Napi::Value Md5(const Napi::CallbackInfo& info) {
    return Napi::String::New(info.Env(), verdict_ == BAAC_FLAC_MD5_MATCH      ? "match"
                                         : verdict_ == BAAC_FLAC_MD5_MISMATCH ? "mismatch"
                                                                              : "unknown");
  }

  Napi::Value SeekPoints(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    const BaacFlacSeekPoint* points = nullptr;
    const int count = handle_ ? baac_flac_decoder_seek_points(handle_, &points) : 0;
    Napi::Array result = Napi::Array::New(env, static_cast<size_t>(count > 0 ? count : 0));
    for (int i = 0; i < count; i++) {
      Napi::Object o = Napi::Object::New(env);
      o.Set("samplePosition",
            Napi::Number::New(env, static_cast<double>(points[i].sample_position)));
      o.Set("byteOffset", Napi::Number::New(env, static_cast<double>(points[i].byte_offset)));
      o.Set("blockSize", Napi::Number::New(env, points[i].block_size));
      result.Set(static_cast<uint32_t>(i), o);
    }
    return result;
  }

  Napi::Value FirstFrameOffset(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    const int64_t offset = handle_ ? baac_flac_decoder_first_frame_offset(handle_) : -1;
    return offset < 0 ? env.Null()
                      : Napi::Value(Napi::Number::New(env, static_cast<double>(offset)));
  }

  /* The stream's tags, in its order, each 'NAME=value'. */
  Napi::Value Tags(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    const BaacFlacComment* comments = nullptr;
    const int count = handle_ ? baac_flac_decoder_comments(handle_, &comments, nullptr) : 0;
    Napi::Array result = Napi::Array::New(env, static_cast<size_t>(count > 0 ? count : 0));
    for (int i = 0; i < count; i++) {
      result.Set(static_cast<uint32_t>(i),
                 Napi::String::New(env, comments[i].text, comments[i].length));
    }
    return result;
  }

  Napi::Value Vendor(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    const BaacFlacComment* comments = nullptr;
    BaacFlacComment vendor = {};
    if (!handle_ || baac_flac_decoder_comments(handle_, &comments, &vendor) < 0) {
      return Napi::String::New(env, "");
    }
    return Napi::String::New(env, vendor.text, vendor.length);
  }

  Napi::Value Pictures(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    const BaacFlacPicture* pictures = nullptr;
    const int count = handle_ ? baac_flac_decoder_pictures(handle_, &pictures) : 0;
    Napi::Array result = Napi::Array::New(env, static_cast<size_t>(count > 0 ? count : 0));
    for (int i = 0; i < count; i++) {
      const BaacFlacPicture& p = pictures[i];
      Napi::Object o = Napi::Object::New(env);
      o.Set("type", Napi::Number::New(env, p.type));
      o.Set("mimeType", Napi::String::New(env, p.mime_type));
      o.Set("description", Napi::String::New(env, p.description));
      o.Set("width", Napi::Number::New(env, p.width));
      o.Set("height", Napi::Number::New(env, p.height));
      o.Set("depth", Napi::Number::New(env, p.depth));
      o.Set("colors", Napi::Number::New(env, p.colors));
      o.Set("data", Napi::Buffer<uint8_t>::Copy(env, p.data, p.data_bytes));
      result.Set(static_cast<uint32_t>(i), o);
    }
    return result;
  }

  Napi::Value SkippedMetadata(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), handle_ ? baac_flac_decoder_skipped_metadata(handle_) : 0);
  }

  BaacFlacDecoder* handle_ = nullptr;
  std::vector<int32_t> pcm_;
  BaacFlacMd5Verdict verdict_ = BAAC_FLAC_MD5_UNKNOWN;
};

}  // namespace

void InitFlac(Napi::Env env, Napi::Object exports) {
  exports.Set("FlacEncoder", FlacEncoder::Init(env));
  exports.Set("FlacDecoder", FlacDecoder::Init(env));
}
