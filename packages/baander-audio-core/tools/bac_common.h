/* What the parts of the bac tool share (bac.cpp and the bac_flac
 * files, which link into one program). */
#ifndef BAANDER_AUDIO_CORE_TOOLS_BAC_COMMON_H
#define BAANDER_AUDIO_CORE_TOOLS_BAC_COMMON_H

#include <chrono>
#include <cstdint>

/* Little-endian numbers of a RIFF file. */
static inline uint32_t le16(const uint8_t* p) { return (uint32_t)p[0] | (uint32_t)p[1] << 8; }
static inline uint32_t le32(const uint8_t* p) {
  return (uint32_t)p[0] | (uint32_t)p[1] << 8 | (uint32_t)p[2] << 16 | (uint32_t)p[3] << 24;
}

/* Wall-clock seconds from a clock that only moves forward. */
static inline double now_seconds() {
  using clock = std::chrono::steady_clock;
  return std::chrono::duration<double>(clock::now().time_since_epoch()).count();
}

#endif /* BAANDER_AUDIO_CORE_TOOLS_BAC_COMMON_H */
