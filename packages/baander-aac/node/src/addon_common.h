/* What the classes of the binding share: how an error is thrown and
 * named, the bytes of a binary argument, and options that have to be of
 * a kind.  The functions are inline; each source file of the addon
 * includes this header. */
#ifndef BAANDER_AAC_ADDON_COMMON_H
#define BAANDER_AAC_ADDON_COMMON_H

#include <napi.h>

#include <cstdint>
#include <string>

#include "aac.h"

/* Throws `message`, unless an exception is on its way already: a getter
 * of the caller's that threw while an option was read is then the error
 * to report, and a second throw on top of it would end the process. */
inline Napi::Value throw_error(const Napi::Env& env, const std::string& message) {
  if (!env.IsExceptionPending()) {
    Napi::Error::New(env, message).ThrowAsJavaScriptException();
  }
  return env.Undefined();
}

/* Whether the option is there and not undefined, with its value: read
 * once, since reading may run a getter of the caller's.  (An option
 * that is null counts as given here; option_given of the AAC classes
 * treats null as absent.) */
inline bool option_defined(const Napi::Object& o, const char* key, Napi::Value* value) {
  if (!o.Has(key)) {
    return false;
  }
  *value = o.Get(key);
  return !o.Env().IsExceptionPending() && !value->IsUndefined();
}

inline const char* error_name(int code) {
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
inline bool bytes_of(const Napi::Value& v, const uint8_t** data, size_t* size) {
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

/* An integer option that, when given, has to be a number: a boolean or an
 * object is refused with the option's name (`what`, as the message names
 * it) instead of being read as 0 or 1, and so is a number outside the
 * 32-bit range.  `*out` keeps its value when the
 * option is absent.  Returns false after throwing. */
inline bool option_number(const Napi::Env& env, const Napi::Object& o, const char* key,
                          const char* what, int* out) {
  Napi::Value v;
  if (!option_defined(o, key, &v)) {
    return !env.IsExceptionPending();
  }
  /* a value outside the 32-bit range is refused rather than wrapped, so
   * that 2^32 cannot turn into 0 (an option's "off") */
  const double d = v.IsNumber() ? v.As<Napi::Number>().DoubleValue() : 0.0;
  if (!v.IsNumber() || !(d >= -2147483648.0 && d <= 2147483647.0)) {
    throw_error(env, std::string(what) + " must be a number in the 32-bit integer range");
    return false;
  }
  *out = static_cast<int32_t>(d);
  return true;
}

inline bool option_bool(const Napi::Object& o, const char* key, bool fallback) {
  Napi::Value v;
  return option_defined(o, key, &v) ? v.ToBoolean().Value() : fallback;
}

/* Adds the classes of the lossless mode to the module (flac_addon.cc). */
void InitFlac(Napi::Env env, Napi::Object exports);

#endif /* BAANDER_AAC_ADDON_COMMON_H */
