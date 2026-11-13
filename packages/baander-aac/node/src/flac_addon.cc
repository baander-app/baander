/* The lossless mode in the Node-API binding: a FLAC encoder and a FLAC
 * decoder over the C API of flac.h.  PCM is an Int32Array of interleaved
 * samples at the stream's bit depth (a 16-bit sample is a value in
 * -32768..32767); a FLAC stream is bytes.  Both classes take their input
 * in chunks of any size. */
#include <napi.h>

#include <cmath>
#include <cstdint>
#include <cstring>
#include <limits>
#include <new>
#include <string>
#include <vector>

#include "addon_common.h"
#include "flac.h"

namespace {

/* A count as an option: a whole number a double holds exactly.  `*out`
 * keeps its value when the option is absent.  Returns false after
 * throwing, or when reading the option threw. */
bool option_count(const Napi::Env& env, const Napi::Object& o, const char* key, const char* what,
                  uint64_t* out) {
  Napi::Value v;
  if (!option_defined(o, key, &v)) {
    return !env.IsExceptionPending();
  }
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
  Napi::Value v;
  if (!option_defined(o, key, &v)) {
    return !env.IsExceptionPending();
  }
  if (!v.IsBoolean()) {
    throw_error(env, std::string(what) + " must be a boolean");
    return false;
  }
  *out = v.As<Napi::Boolean>().Value();
  return true;
}

/* A string option that, when given, has to be a string.  `*given` says
 * whether it was. */
bool option_text(const Napi::Env& env, const Napi::Object& o, const char* key, const char* what,
                 std::string* out, bool* given) {
  Napi::Value v;
  *given = option_defined(o, key, &v);
  if (!*given) {
    return !env.IsExceptionPending();
  }
  if (!v.IsString()) {
    throw_error(env, std::string(what) + " must be a string");
    return false;
  }
  *out = v.As<Napi::String>().Utf8Value();
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

/* Whether the bytes behind a binary value are gone: its ArrayBuffer was
 * detached (transferred to another thread, for one). */
bool bytes_detached(const Napi::Value& v) {
  if (v.IsArrayBuffer()) {
    return v.As<Napi::ArrayBuffer>().IsDetached();
  }
  return v.IsTypedArray() && v.As<Napi::TypedArray>().ArrayBuffer().IsDetached();
}

/* The tags and pictures of the options.
 *
 * Reading an option can run code of the caller's: a getter, a Proxy.
 * Such code can detach or shrink the ArrayBuffer of a picture that was
 * read before it.  So the options are taken in two steps.  read() reads
 * every property and keeps the strings and numbers as copies and each
 * picture's `data` as the JavaScript value it is, without looking at
 * its bytes.  bind() then asks each value where its bytes are and how
 * many, as the last thing before the library copies them: between
 * bind() and that copy no JavaScript runs, so what bind() found is what
 * the library reads.  (Copying each picture when its `data` is read
 * would be as safe and cost a second copy of up to 16 MiB each.) */
struct TagStore {
  /* One picture as it was read: the figures the library will see, the
   * strings and the JavaScript value its pointers will be set to. */
  struct PictureSource {
    BaacFlacPicture view;
    std::string mime_type;
    std::string description;
    Napi::Value data;
  };

  std::vector<std::string> tags;
  std::string vendor;
  bool have_vendor = false;
  std::vector<PictureSource> sources;
  std::vector<BaacFlacComment> comments;
  std::vector<BaacFlacPicture> pictures;

  /* Reads `tags`, `vendor` and `pictures`.  Returns false after
   * throwing, or when reading threw. */
  bool read(const Napi::Env& env, const Napi::Object& o) {
    Napi::Value v;
    if (option_defined(o, "tags", &v)) {
      if (!v.IsArray()) {
        throw_error(env, "FlacEncoder: tags must be an array of 'NAME=value' strings");
        return false;
      }
      const Napi::Array list = v.As<Napi::Array>();
      const uint32_t count = list.Length();
      for (uint32_t i = 0; i < count; i++) {
        const Napi::Value tag = list.Get(i);
        if (env.IsExceptionPending() || !tag.IsString()) {
          throw_error(env, "FlacEncoder: tags must be an array of 'NAME=value' strings");
          return false;
        }
        tags.push_back(tag.As<Napi::String>().Utf8Value());
      }
    }
    if (env.IsExceptionPending() ||
        !option_text(env, o, "vendor", "FlacEncoder: vendor", &vendor, &have_vendor)) {
      return false;
    }
    if (option_defined(o, "pictures", &v)) {
      if (!v.IsArray()) {
        throw_error(env, "FlacEncoder: pictures must be an array");
        return false;
      }
      const Napi::Array list = v.As<Napi::Array>();
      const uint32_t count = list.Length();
      for (uint32_t i = 0; i < count; i++) {
        const Napi::Value item = list.Get(i);
        if (env.IsExceptionPending() || !item.IsObject()) {
          throw_error(env, "FlacEncoder: pictures must be an array of objects");
          return false;
        }
        const Napi::Object p = item.As<Napi::Object>();
        BaacFlacPicture picture = {};
        picture.type = 3;
        std::string mime;
        std::string description;
        bool given = false;
        Napi::Value bytes;
        if (!option_text(env, p, "mimeType", "FlacEncoder: pictures[].mimeType", &mime, &given) ||
            !given) {
          throw_error(env, "FlacEncoder: pictures[].mimeType must be a string");
          return false;
        }
        if (!option_defined(p, "data", &bytes) ||
            !(bytes.IsArrayBuffer() || bytes.IsTypedArray())) {
          throw_error(env,
                      "FlacEncoder: pictures[].data must be a Buffer, Uint8Array or "
                      "ArrayBuffer");
          return false;
        }
        if (!option_text(env, p, "description", "FlacEncoder: pictures[].description", &description,
                         &given) ||
            !picture_number(env, p, "type", &picture.type) ||
            !picture_number(env, p, "width", &picture.width) ||
            !picture_number(env, p, "height", &picture.height) ||
            !picture_number(env, p, "depth", &picture.depth) ||
            !picture_number(env, p, "colors", &picture.colors)) {
          return false;
        }
        sources.push_back({picture, mime, description, bytes});
      }
    }
    return !env.IsExceptionPending();
  }

  /* Points the library's view of the options at the strings and at the
   * bytes of each picture as they are now.  No JavaScript may run
   * between this and the library's copy.  Returns false after throwing. */
  bool bind(const Napi::Env& env, BaacFlacEncoderOptions* options) {
    comments.clear();
    for (const std::string& tag : tags) {
      comments.push_back({tag.data(), static_cast<uint32_t>(tag.size())});
    }
    pictures.clear();
    for (const PictureSource& source : sources) {
      const uint8_t* bytes = nullptr;
      size_t size = 0;
      if (bytes_detached(source.data) || !bytes_of(source.data, &bytes, &size)) {
        throw_error(env, "FlacEncoder: pictures[].data was detached while the options were read");
        return false;
      }
      if (size > BAAC_FLAC_MAX_PICTURE_BYTES) {
        throw_error(env,
                    "FlacEncoder: pictures[].data is larger than a FLAC picture block "
                    "holds");
        return false;
      }
      BaacFlacPicture picture = source.view;
      picture.mime_type = source.mime_type.c_str();
      picture.description = source.description.c_str();
      picture.data = bytes;
      picture.data_bytes = static_cast<uint32_t>(size);
      pictures.push_back(picture);
    }
    options->comments = comments.data();
    options->comment_count = static_cast<int>(comments.size());
    options->vendor = have_vendor ? vendor.c_str() : nullptr;
    options->pictures = pictures.data();
    options->picture_count = static_cast<int>(pictures.size());
    return true;
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
    /* The addon is built without C++ exceptions crossing into Node: a
     * failed allocation while the options are copied is caught here and
     * thrown as a JavaScript error. */
    try {
      construct(info);
    } catch (const std::bad_alloc&) {
      throw_error(info.Env(), "FlacEncoder: out of memory");
    }
  }

  ~FlacEncoder() override { baac_flac_encoder_destroy(handle_); }

 private:
  void construct(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    BaacFlacEncoderOptions options;
    baac_flac_encoder_options_init(&options);
    bool seek_table = false;
    TagStore store;
    /* Every option is read first; see TagStore for why. */
    if (info.Length() > 0 && info[0].IsObject()) {
      const Napi::Object o = info[0].As<Napi::Object>();
      if (!store.read(env, o) ||
          !option_number(env, o, "sampleRate", "FlacEncoder: sampleRate", &sample_rate_) ||
          !option_number(env, o, "channels", "FlacEncoder: channels", &channels_) ||
          !option_number(env, o, "bitsPerSample", "FlacEncoder: bitsPerSample", &bits_) ||
          !option_number(env, o, "level", "FlacEncoder: level", &level_) ||
          !option_number(env, o, "padding", "FlacEncoder: padding", &options.padding_bytes) ||
          !option_count(env, o, "totalSamples", "FlacEncoder: totalSamples",
                        &options.total_samples_hint) ||
          !option_flag(env, o, "seekTable", "FlacEncoder: seekTable", &seek_table) ||
          env.IsExceptionPending()) {
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
    /* From here to the library's copy of the tags and pictures no
     * JavaScript runs. */
    if (!store.bind(env, &options)) {
      return;
    }
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

  /* Starts the stream when none is running: the header into `out`,
   * which is empty and has room for it, so that nothing here can fail
   * for want of memory once the encoder has begun. */
  bool begin(std::vector<uint8_t>* out, int header_bytes) {
    if (started_) {
      return true;
    }
    out->resize(static_cast<size_t>(header_bytes));
    if (baac_flac_encoder_write_header(handle_, out->data(), header_bytes) != header_bytes) {
      return false;
    }
    started_ = true;
    finished_ = false;
    return true;
  }

  /* What a call needs before it changes anything: room in `out` for
   * the header of a stream that has not begun, and the scratch buffer
   * at the size of the first step.  Throws std::bad_alloc; the encoder
   * is then as it was, and the call can be made again.  Returns the
   * header's length, 0 for a stream that runs, or a negative error. */
  int prepare(std::vector<uint8_t>* out, int first_bound) {
    int header_bytes = 0;
    if (!started_) {
      header_bytes = baac_flac_encoder_header_bytes(handle_);
      if (header_bytes < 0) {
        return header_bytes;
      }
      out->reserve(static_cast<size_t>(header_bytes));
    }
    scratch(static_cast<size_t>(first_bound > 0 ? first_bound : 0));
    return header_bytes;
  }

  /* Refuses a call on an encoder that lost output: frames that were
   * coded and could not be handed out are gone, and what would follow
   * them is no stream. */
  bool refuse_failed(const Napi::Env& env, const char* where) {
    if (!failed_) {
      return false;
    }
    throw_error(env, std::string(where) +
                         ": an earlier call failed after coding began; the stream is "
                         "incomplete and this encoder writes no more");
    return true;
  }

  /* Room for `size` bytes of frames, kept from call to call: the bound
   * of a call is far above what it writes, and a fresh buffer of that
   * size would be cleared every time. */
  uint8_t* scratch(size_t size) {
    if (scratch_.size() < size) {
      scratch_.resize(size);
    }
    return scratch_.data();
  }

  /* Codes the samples and returns every frame they complete.  The first
   * result of a stream begins with the stream's header. */
  Napi::Value Encode(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "FlacEncoder: not initialized");
    }
    if (refuse_failed(env, "FlacEncoder.encode")) {
      return env.Undefined();
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
      const int bits = bits_;
      const uint32_t half = 1u << (bits - 1);
      uint32_t outside = 0;
      for (size_t i = 0; i < values; i++) {
        outside |= (static_cast<uint32_t>(samples[i]) + half) >> bits;
      }
      if (outside) {
        return throw_error(env, "FlacEncoder.encode: a sample lies outside the bit depth");
      }
    }
    const size_t frames = values / static_cast<size_t>(channels_);
    const size_t step = 1 << 16;
    const auto count_at = [&](size_t pos) {
      return static_cast<int>(frames - pos < step ? frames - pos : step);
    };
    std::vector<uint8_t> out;
    bool coding = false; /* the encoder's state has changed: a failure now loses output */
    try {
      const int first_bound = baac_flac_encoder_max_output_bytes(handle_, count_at(0));
      if (first_bound < 0) {
        return throw_error(env, std::string("FlacEncoder.encode: ") + error_name(first_bound));
      }
      const int header_bytes = prepare(&out, first_bound);
      if (header_bytes < 0) {
        return throw_error(env, "FlacEncoder.encode: the stream cannot begin");
      }
      coding = true;
      if (!begin(&out, header_bytes)) {
        failed_ = true;
        return throw_error(env, "FlacEncoder.encode: the stream cannot begin");
      }
      for (size_t pos = 0; pos < frames; pos += step) {
        const int count = count_at(pos);
        const int bound = baac_flac_encoder_max_output_bytes(handle_, count);
        /* A step the library refuses has consumed nothing.  What the
         * call holds by then, the header or earlier frames, is lost
         * with it. */
        if (bound < 0) {
          failed_ = !out.empty() || pos > 0;
          return throw_error(env, std::string("FlacEncoder.encode: ") + error_name(bound));
        }
        uint8_t* const frames_out = scratch(static_cast<size_t>(bound));
        const int n =
            baac_flac_encoder_encode(handle_, samples + pos * channels_, count, frames_out, bound);
        if (n < 0) {
          failed_ = !out.empty() || pos > 0;
          return throw_error(env, std::string("FlacEncoder.encode: ") + error_name(n));
        }
        out.insert(out.end(), frames_out, frames_out + n);
      }
    } catch (const std::bad_alloc&) {
      failed_ = failed_ || coding;
      return throw_error(env, "FlacEncoder.encode: out of memory");
    }
    return hand_out(env, out);
  }

  /* The bytes of a call as a Buffer.  When Node cannot make one, the
   * frames in it are lost and the encoder writes no more. */
  Napi::Value hand_out(const Napi::Env& env, const std::vector<uint8_t>& out) {
    Napi::Value result = Napi::Buffer<uint8_t>::Copy(env, out.data(), out.size());
    if (env.IsExceptionPending()) {
      failed_ = true;
    }
    return result;
  }

  /* Ends the stream: the samples still held as its last, shorter frame.
   * The next encode() starts a new stream; a second finish() without
   * one is refused, as the library refuses it. */
  Napi::Value Finish(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "FlacEncoder: not initialized");
    }
    if (refuse_failed(env, "FlacEncoder.finish")) {
      return env.Undefined();
    }
    if (finished_) {
      return throw_error(env,
                         "FlacEncoder.finish: the stream is finished; encode() starts "
                         "another");
    }
    std::vector<uint8_t> out;
    bool coding = false;
    try {
      const int bound = baac_flac_encoder_max_output_bytes(handle_, 0);
      const int header_bytes = prepare(&out, bound);
      if (header_bytes < 0) {
        return throw_error(env, "FlacEncoder.finish: the stream cannot begin");
      }
      coding = true;
      if (!begin(&out, header_bytes)) {
        failed_ = true;
        return throw_error(env, "FlacEncoder.finish: the stream cannot begin");
      }
      uint8_t* const frames_out = scratch(static_cast<size_t>(bound > 0 ? bound : 0));
      const int n = baac_flac_encoder_finish(handle_, frames_out, bound);
      if (n < 0) {
        failed_ = !out.empty();
        return throw_error(env, std::string("FlacEncoder.finish: ") + error_name(n));
      }
      started_ = false;
      finished_ = true;
      out.insert(out.end(), frames_out, frames_out + n);
    } catch (const std::bad_alloc&) {
      failed_ = failed_ || coding;
      return throw_error(env, "FlacEncoder.finish: out of memory");
    }
    return hand_out(env, out);
  }

  /* After finish(): the header again, of the same length, with the
   * sample total, the MD5 and the seek points, to be written over the
   * first one. */
  Napi::Value FinalHeader(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "FlacEncoder: not initialized");
    }
    if (refuse_failed(env, "FlacEncoder.finalHeader")) {
      return env.Undefined();
    }
    const int size = baac_flac_encoder_header_bytes(handle_);
    std::vector<uint8_t> out;
    try {
      out.resize(static_cast<size_t>(size > 0 ? size : 0));
    } catch (const std::bad_alloc&) {
      return throw_error(env, "FlacEncoder.finalHeader: out of memory");
    }
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
  bool started_ = false;  /* the header of the running stream is out */
  bool finished_ = false; /* finish() ended a stream and none has begun since */
  bool failed_ = false;   /* output was lost: the stream is incomplete, and nothing follows */
  std::vector<uint8_t> scratch_;
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
         InstanceAccessor("skippedMetadata", &FlacDecoder::SkippedMetadata, nullptr),
         InstanceAccessor("id3v2Bytes", &FlacDecoder::Id3v2Bytes, nullptr),
         InstanceAccessor("id3v1", &FlacDecoder::Id3v1, nullptr),
         InstanceAccessor("pending", &FlacDecoder::Pending, nullptr),
         InstanceAccessor("maxOutput", &FlacDecoder::MaxOutput, nullptr)});
  }

  explicit FlacDecoder(const Napi::CallbackInfo& info) : Napi::ObjectWrap<FlacDecoder>(info) {
    const Napi::Env env = info.Env();
    bool md5 = false;
    bool metadata = false;
    uint64_t metadata_bytes = 0;
    uint64_t max_output = kDefaultOutput;
    Napi::Value streaminfo;
    bool have_streaminfo = false;
    /* Every option is read before the bytes of `streamInfo` are looked
     * at: reading an option can run code that detaches them. */
    if (info.Length() > 0 && info[0].IsObject()) {
      const Napi::Object o = info[0].As<Napi::Object>();
      if (!option_flag(env, o, "md5", "FlacDecoder: md5", &md5) ||
          !option_count(env, o, "maxOutput", "FlacDecoder: maxOutput", &max_output)) {
        return;
      }
      if (max_output < kFrameValues || max_output > kLargestOutput) {
        throw_error(env, "FlacDecoder: maxOutput is " + std::to_string(kFrameValues) +
                             " values (one frame of the largest size) to " +
                             std::to_string(kLargestOutput));
        return;
      }
      /* metadata: true, or { maxBytes } for a limit of the caller's. */
      Napi::Value m;
      if (option_defined(o, "metadata", &m)) {
        if (m.IsBoolean()) {
          metadata = m.As<Napi::Boolean>().Value();
        } else if (m.IsObject()) {
          metadata = true;
          if (!option_count(env, m.As<Napi::Object>(), "maxBytes", "FlacDecoder: metadata.maxBytes",
                            &metadata_bytes)) {
            return;
          }
          /* A limit this build cannot address would otherwise wrap, and
           * a wrap to zero would mean the default. */
          if (metadata_bytes > static_cast<uint64_t>(std::numeric_limits<size_t>::max())) {
            throw_error(env, "FlacDecoder: metadata.maxBytes is more than this build can hold");
            return;
          }
        } else {
          throw_error(env, "FlacDecoder: metadata must be a boolean or an object");
          return;
        }
      }
      have_streaminfo = option_defined(o, "streamInfo", &streaminfo);
      if (env.IsExceptionPending()) {
        return;
      }
    }
    max_output_ = static_cast<size_t>(max_output);
    handle_ = baac_flac_decoder_create();
    if (!handle_) {
      throw_error(env, "FlacDecoder: out of memory");
      return;
    }
    baac_flac_decoder_set_md5_check(handle_, md5 ? 1 : 0);
    baac_flac_decoder_set_metadata(handle_, metadata ? 1 : 0, static_cast<size_t>(metadata_bytes));
    if (have_streaminfo) {
      const uint8_t* bytes = nullptr;
      size_t size = 0;
      if (!bytes_of(streaminfo, &bytes, &size)) {
        throw_error(env, "FlacDecoder: streamInfo must be a Buffer, Uint8Array or ArrayBuffer");
        return;
      }
      if (baac_flac_decoder_set_streaminfo(handle_, bytes, static_cast<int>(size)) != AAC_OK) {
        throw_error(env,
                    "FlacDecoder: streamInfo is not a STREAMINFO block of 34 bytes with "
                    "values the format allows");
        return;
      }
    }
  }

  ~FlacDecoder() override { baac_flac_decoder_destroy(handle_); }

 private:
  /* One frame of the largest block in the most channels: the least a
   * call has to be able to return, and so the smallest bound. */
  static constexpr uint64_t kFrameValues =
      static_cast<uint64_t>(BAAC_FLAC_MAX_BLOCK_SIZE) * BAAC_FLAC_MAX_CHANNELS;
  static constexpr uint64_t kDefaultOutput = 1 << 20;
  static constexpr uint64_t kLargestOutput = 1 << 28;

  /* The values the next frame can have at most: what STREAMINFO allows
   * when the stream has one, else what the format allows. */
  size_t frame_values() const {
    BaacFlacStreamInfo stream;
    if (baac_flac_decoder_stream_info(handle_, &stream) == AAC_OK && stream.max_block_size > 0) {
      return static_cast<size_t>(stream.max_block_size) * static_cast<size_t>(stream.channels);
    }
    return static_cast<size_t>(kFrameValues);
  }

  /* Throws the error that was held back until the samples before it
   * had been handed out.  Every such error but a failed allocation is
   * final for the stream: the library answers each later call the same
   * way.  The bytes that were waiting are dropped then, so that
   * `pending` does not promise samples that cannot come, and decode()
   * goes on answering with this error until reset() or seekReset(). */
  Napi::Value throw_deferred(const Napi::Env& env, const char* where) {
    const int code = deferred_error_;
    deferred_error_ = 0;
    if (code != AAC_ERR_MEMORY) {
      failed_ = code;
      drop_queue();
      more_ = false;
    }
    return throw_error(env, std::string(where) + ": " + error_name(code));
  }

  /* The bytes of an earlier chunk that are not fed yet. */
  const uint8_t* queued() const { return queue_.data() + queue_head_; }
  size_t queued_bytes() const { return queue_.size() - queue_head_; }
  void drop_queue() {
    queue_.clear();
    queue_head_ = 0;
  }

  /* Feeds `size` bytes at `data` (the caller's chunk, or the rest of an
   * earlier one that is kept in `queue_`) and decodes frames straight
   * into `out_` until the input is spent or another frame might pass
   * the bound.  What was not fed stays in `queue_`.  With `finishing`
   * the input is declared ended once it is all fed, and the stream is
   * finished once nothing is held back.  Returns the samples; throws
   * for a stream that cannot be decoded, after handing out what was
   * decoded before that. */
  Napi::Value pump(const Napi::Env& env, const char* where, const uint8_t* data, size_t size,
                   bool queued) {
    if (deferred_error_ != 0) {
      return throw_deferred(env, where);
    }
    size_t used = 0;
    size_t left = size;
    more_ = false;
    try {
      for (;;) {
        int taken = 0;
        if (left) {
          const size_t piece = left < (1u << 30) ? left : (1u << 30);
          taken = baac_flac_decoder_feed(handle_, data, static_cast<int>(piece));
          if (taken < 0) {
            deferred_error_ = taken;
            break;
          }
          data += taken;
          left -= static_cast<size_t>(taken);
        } else if (finishing_ && !ended_) {
          baac_flac_decoder_end_input(handle_);
          ended_ = true;
        }
        bool decoded = false;
        for (;;) {
          size_t room = frame_values();
          if (used && used + room > max_output_) {
            more_ = true;
            break;
          }
          if (out_.size() < used + room) {
            out_.resize(used + room);
          }
          int n =
              baac_flac_decoder_decode_next(handle_, out_.data() + used, static_cast<int>(room));
          if (n == AAC_ERR_OVERFLOW && room < kFrameValues) {
            /* A frame larger than STREAMINFO said: the format's largest. */
            room = static_cast<size_t>(kFrameValues);
            if (used && used + room > max_output_) {
              more_ = true;
              break;
            }
            out_.resize(used + room);
            n = baac_flac_decoder_decode_next(handle_, out_.data() + used, static_cast<int>(room));
          }
          if (n == 0) {
            break;
          }
          decoded = true;
          if (n == AAC_ERR_DECODE) {
            continue; /* a damaged stretch: counted by the decoder, and on */
          }
          BaacFlacStreamInfo stream;
          if (n < 0 || baac_flac_decoder_stream_info(handle_, &stream) != AAC_OK) {
            deferred_error_ = n < 0 ? n : AAC_ERR_DECODE;
            break;
          }
          used += static_cast<size_t>(n) * static_cast<size_t>(stream.channels);
        }
        if (more_ || deferred_error_ != 0) {
          break;
        }
        if (left == 0 && (!finishing_ || ended_)) {
          break;
        }
        if (left && taken == 0 && !decoded) {
          deferred_error_ = AAC_ERR_OVERFLOW; /* a full FIFO without a frame: cannot be */
          break;
        }
      }
      /* The bytes not yet fed wait for the next call.  Of a chunk
       * that is queued already only the start moves: taking the fed
       * bytes out each time would copy a large chunk once for every
       * call that a small bound makes necessary. */
      if (left == 0) {
        drop_queue();
      } else if (queued) {
        queue_head_ = queue_.size() - left;
      } else {
        queue_.assign(data, data + left);
        queue_head_ = 0;
      }
    } catch (const std::bad_alloc&) {
      drop_queue();
      more_ = false;
      return throw_error(env, std::string(where) + ": out of memory");
    }
    if (finishing_ && ended_ && !more_ && queued_bytes() == 0 && deferred_error_ == 0 &&
        !finished_) {
      /* A stream that ends inside a frame is damage the library counts,
       * which `errors` shows; any other answer is an error of its own. */
      const int ret = baac_flac_decoder_finish(handle_, &verdict_);
      if (ret != AAC_OK && ret != AAC_ERR_DECODE) {
        deferred_error_ = ret;
      } else {
        finished_ = true;
      }
    }
    if (used == 0 && deferred_error_ != 0) {
      return throw_deferred(env, where);
    }
    Napi::Int32Array result = Napi::Int32Array::New(env, used);
    if (used) {
      memcpy(result.Data(), out_.data(), used * sizeof(int32_t));
    }
    return result;
  }

  /* Takes the bytes and returns the samples of the frames they
   * complete, up to the bound of one call; without an argument, goes on
   * with what an earlier call held back. */
  Napi::Value Decode(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "FlacDecoder: not initialized");
    }
    /* A call without input goes on with what is held back, also after
     * finish() has been called; new bytes are refused from then on. */
    const bool with_input = info.Length() >= 1 && !info[0].IsUndefined();
    if (finished_ || (with_input && finishing_)) {
      return throw_error(env,
                         "FlacDecoder.decode: the stream is finished; reset() starts "
                         "another");
    }
    if (failed_ != 0) {
      return throw_error(env, std::string("FlacDecoder.decode: ") + error_name(failed_) +
                                  "; the stream cannot be decoded further, reset() starts another");
    }
    if (!with_input) {
      return pump(env, "FlacDecoder.decode", queued(), queued_bytes(), true);
    }
    if (pending()) {
      return throw_error(env,
                         "FlacDecoder.decode: samples are pending; call decode() without "
                         "input until `pending` is false");
    }
    const uint8_t* data = nullptr;
    size_t size = 0;
    if (!bytes_of(info[0], &data, &size)) {
      return throw_error(env, "FlacDecoder.decode: expected a Buffer, Uint8Array or ArrayBuffer");
    }
    return pump(env, "FlacDecoder.decode", data, size, false);
  }

  /* Ends the stream: no more bytes follow.  Returns the samples of the
   * frames that were waiting for that word, up to the bound of one
   * call; while `pending` is true, another finish() or decode() returns
   * more.  `md5` and `errors` state the result once nothing is pending.
   * The decoder takes no input until reset(). */
  Napi::Value Finish(const Napi::CallbackInfo& info) {
    const Napi::Env env = info.Env();
    if (!handle_) {
      return throw_error(env, "FlacDecoder: not initialized");
    }
    if (finished_) {
      return Napi::Int32Array::New(env, 0);
    }
    if (failed_ != 0) {
      /* The stream ended in an error that decode() has thrown: there is
       * nothing more to hand out, and the stream is closed as it is. */
      baac_flac_decoder_finish(handle_, &verdict_);
      verdict_ = BAAC_FLAC_MD5_UNKNOWN;
      finishing_ = true;
      ended_ = true;
      finished_ = true;
      return Napi::Int32Array::New(env, 0);
    }
    finishing_ = true;
    return pump(env, "FlacDecoder.finish", queued(), queued_bytes(), true);
  }

  bool pending() const { return more_ || queued_bytes() != 0 || deferred_error_ != 0; }

  /* Forgets what a stream left behind in this object. */
  void clear() {
    drop_queue();
    more_ = false;
    deferred_error_ = 0;
    failed_ = 0;
    verdict_ = BAAC_FLAC_MD5_UNKNOWN;
  }

  Napi::Value Reset(const Napi::CallbackInfo& info) {
    if (handle_) {
      baac_flac_decoder_reset(handle_);
      clear();
      finishing_ = false;
      ended_ = false;
      finished_ = false;
    }
    return info.Env().Undefined();
  }

  /* For a seek: drops the buffered bytes and the samples not yet
   * collected; the bytes given next may begin anywhere in the stream. */
  Napi::Value SeekReset(const Napi::CallbackInfo& info) {
    if (handle_ && baac_flac_decoder_seek_reset(handle_) == AAC_OK) {
      /* The library takes input again, also after finish() was called
       * and had not yet ended the stream; so does this object. */
      clear();
      finishing_ = false;
      ended_ = false;
    }
    return info.Env().Undefined();
  }

  Napi::Value Pending(const Napi::CallbackInfo& info) {
    return Napi::Boolean::New(info.Env(), pending());
  }

  Napi::Value MaxOutput(const Napi::CallbackInfo& info) {
    return Napi::Number::New(info.Env(), static_cast<double>(max_output_));
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

  /* The bytes of ID3v2 tags passed over in front of the stream. */
  Napi::Value Id3v2Bytes(const Napi::CallbackInfo& info) {
    uint64_t bytes = 0;
    if (handle_) {
      baac_flac_decoder_id3(handle_, &bytes, nullptr);
    }
    return Napi::Number::New(info.Env(), static_cast<double>(bytes));
  }

  /* Whether an ID3v1 tag behind the last frame was passed over. */
  Napi::Value Id3v1(const Napi::CallbackInfo& info) {
    int trailer = 0;
    if (handle_) {
      baac_flac_decoder_id3(handle_, nullptr, &trailer);
    }
    return Napi::Boolean::New(info.Env(), trailer != 0);
  }

  BaacFlacDecoder* handle_ = nullptr;
  size_t max_output_ = static_cast<size_t>(kDefaultOutput); /* values one call returns at most */
  std::vector<int32_t> out_;   /* where frames are decoded, kept from call to call */
  std::vector<uint8_t> queue_; /* the last chunk, when a call could not feed all of it */
  size_t queue_head_ = 0;      /* the first byte of `queue_` that is not fed yet */
  bool more_ = false;          /* frames are waiting that the bound held back */
  bool finishing_ = false;     /* finish() was called */
  bool ended_ = false;         /* the decoder was told the input has ended */
  bool finished_ = false;      /* the stream is finished */
  int deferred_error_ = 0;     /* an error to throw once the samples before it are out */
  int failed_ = 0;             /* the error that ended the stream, once it has been thrown */
  BaacFlacMd5Verdict verdict_ = BAAC_FLAC_MD5_UNKNOWN;
};

}  // namespace

void InitFlac(Napi::Env env, Napi::Object exports) {
  exports.Set("FlacEncoder", FlacEncoder::Init(env));
  exports.Set("FlacDecoder", FlacDecoder::Init(env));
}
