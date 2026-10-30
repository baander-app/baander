/* The xHE-AAC encoder's random access controls for streaming: the
 * independent frame schedule (in frames, milliseconds or samples, with
 * and without SBR, the distance bound, on-demand requests), the segment
 * size ceiling measured on the raw access units, the frame info a muxer
 * cuts by (aligned against the decoded output), a decoder starting at
 * any immediate playout frame of a stream with on-demand independent
 * frames, and the committed stream.
 *
 * With arguments the program writes the committed stream, or the stream
 * and its cut at each immediate playout frame for the reference
 * decoders, instead:
 *   test_usac_rap --write-streams <dir>
 *   test_usac_rap --write-cuts <dir>
 */
#include <cmath>
#include <cstdio>
#include <cstring>
#include <set>
#include <string>
#include <vector>

#include "aac.h"
#include "bitstream.h"
#include "encoder.h"
#include "test_util.h"
#include "usac.h"
#include "usac_enc.h"

/* The committed stream is the release build's bytes; a sanitizer build
 * keeps the same flags but not the same vectorised arithmetic. */
#if defined(__SANITIZE_ADDRESS__) || defined(__SANITIZE_UNDEFINED__)
#define BAAC_SANITIZED 1
#elif defined(__has_feature)
#if __has_feature(address_sanitizer) || __has_feature(undefined_behavior_sanitizer)
#define BAAC_SANITIZED 1
#endif
#endif
#ifndef BAAC_SANITIZED
#define BAAC_SANITIZED 0
#endif

namespace {

constexpr int kRate = 48000;
constexpr int kCore = 1024;

/* The options with the tools chosen and the schedule at its defaults. */
AacUsacEncoderOptions options(int sbr) {
  AacUsacEncoderOptions o;
  aac_usac_encoder_options_init(&o);
  memset(&o, 0, sizeof(o));
  o.noise_filling = 1;
  o.preroll = 1;
  o.stream_id = -1;
  o.sbr = sbr;
  o.mps212 = 0;
  return o;
}

/* One produced frame: its bytes, what the encoder reported for it, and
 * what its access unit says (raw transport only). */
struct Unit {
  std::vector<uint8_t> bytes;
  AacEncoderFrameInfo info;
  bool has_info = true;    /* the report is this unit's (a LOAS flush reports its last frame) */
  int flag_indep = -1;     /* usacIndependencyFlag of the access unit */
  int preroll_present = -1; /* usacExtElementPresent of the pre-roll element */
};

struct Encoded {
  std::vector<uint8_t> stream; /* every unit back to back */
  std::vector<Unit> units;
  std::vector<uint8_t> asc;
  int frame_size = 0;
  int delay = 0;
  int error = 0;
};

/* usacIndependencyFlag and the pre-roll element's presence bit, which
 * come first in every access unit of a stream with the pre-roll element
 * configured. */
void read_au_flags(const std::vector<uint8_t>& au, int has_preroll_element, Unit* u) {
  AacBitReader r;
  aac_bitreader_init(&r, au.data(), (int)au.size());
  u->flag_indep = (int)aac_bitreader_read(&r, 1);
  u->preroll_present = has_preroll_element ? (int)aac_bitreader_read(&r, 1) : 0;
}

/* LOAS frames of a stream: offset and length of each. */
std::vector<std::pair<size_t, size_t>> split_loas(const std::vector<uint8_t>& s) {
  std::vector<std::pair<size_t, size_t>> frames;
  size_t pos = 0;
  while (pos + 3 <= s.size()) {
    if (s[pos] != 0x56 || (s[pos + 1] & 0xE0) != 0xE0) {
      break;
    }
    const size_t len = (size_t)((s[pos + 1] & 0x1F) << 8 | s[pos + 2]) + 3;
    if (pos + len > s.size()) {
      break;
    }
    frames.push_back({pos, len});
    pos += len;
  }
  return frames;
}

/* Encodes `pcm` with an independent frame requested before the frames
 * in `requests` (by the index of the frame the call produces), and
 * keeps every unit with the encoder's report of it.  The raw transport
 * yields one access unit per call; LOAS writes the flush's frames in
 * one call, which are split again on their sync words. */
Encoded encode(const std::vector<float>& pcm, int channels, int bitrate,
               const AacUsacEncoderOptions& opt, AacTransport transport,
               const std::set<int>& requests = {}, AacRateControl rc = AAC_RC_CBR) {
  Encoded e;
  AacEncoderHandle enc = aac_encoder_create(kRate, channels, bitrate, AAC_AOT_USAC, rc);
  CHECK(enc != nullptr, "encoder for %d channels at %d", channels, bitrate);
  if (!enc) {
    e.error = AAC_ERR_INIT;
    return e;
  }
  CHECK(aac_encoder_set_usac(enc, &opt) == AAC_OK, "options accepted");
  CHECK(aac_encoder_set_transport(enc, transport) == AAC_OK, "transport %d", (int)transport);
  e.delay = aac_encoder_delay(enc);
  e.frame_size = aac_encoder_frame_size(enc);
  e.asc.resize(64);
  const int asc_len = aac_encoder_config(enc, e.asc.data(), (int)e.asc.size());
  CHECK(asc_len > 0, "config");
  e.asc.resize((size_t)std::max(asc_len, 0));
  std::vector<float> block((size_t)e.frame_size * channels);
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) * 4);
  int produced = 0;
  auto take = [&](const uint8_t* data, int len, bool own_report = true) {
    Unit u;
    u.bytes.assign(data, data + len);
    u.has_info = own_report;
    CHECK(aac_encoder_last_frame_info(enc, &u.info) == AAC_OK, "frame info of unit %d", produced);
    if (transport == AAC_TRANSPORT_RAW) {
      read_au_flags(u.bytes, opt.preroll, &u);
    }
    e.stream.insert(e.stream.end(), data, data + len);
    e.units.push_back(u);
    produced++;
  };
  for (size_t pos = 0; pos < pcm.size() && !e.error; pos += block.size()) {
    std::fill(block.begin(), block.end(), 0.0f);
    const size_t n = std::min(block.size(), pcm.size() - pos);
    memcpy(block.data(), pcm.data() + pos, n * sizeof(float));
    if (requests.count(produced)) {
      CHECK(aac_encoder_request_independent_frame(enc) == AAC_OK, "request before frame %d",
            produced);
    }
    const int len = aac_encoder_encode(enc, block.data(), e.frame_size, buf.data(), (int)buf.size());
    if (len < 0) {
      e.error = len;
    } else {
      take(buf.data(), len);
    }
  }
  for (;;) {
    if (requests.count(produced)) {
      CHECK(aac_encoder_request_independent_frame(enc) == AAC_OK, "request before frame %d",
            produced);
    }
    const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
    if (len <= 0) {
      if (len < 0) {
        e.error = len;
      }
      break;
    }
    if (transport == AAC_TRANSPORT_RAW) {
      take(buf.data(), len);
      continue;
    }
    /* the flush's frames arrive together; the report is the last one's */
    const std::vector<uint8_t> all(buf.begin(), buf.begin() + len);
    const std::vector<std::pair<size_t, size_t>> frames = split_loas(all);
    CHECK(!frames.empty(), "the flush splits into LOAS frames");
    for (size_t i = 0; i < frames.size(); i++) {
      take(all.data() + frames[i].first, (int)frames[i].second, i + 1 == frames.size());
    }
    break;
  }
  aac_encoder_destroy(enc);
  return e;
}

/* Decodes a LOAS stream (or any tail of one) whole through the public
 * FIFO, concealment off so that every damaged frame counts. */
std::vector<float> decode_loas(const std::vector<uint8_t>& stream, int channels, int* errors) {
  std::vector<float> pcm;
  AacDecoderHandle dec = aac_decoder_create(kRate, channels);
  CHECK(dec != nullptr, "decoder");
  if (!dec) {
    *errors = -1;
    return pcm;
  }
  aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
  CHECK(aac_decoder_feed(dec, stream.data(), (int)stream.size()) == (int)stream.size(), "stream fed");
  std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  *errors = 0;
  for (;;) {
    const int got = aac_decoder_decode_next(dec, out.data(), (int)out.size());
    if (got == 0) {
      break;
    }
    if (got < 0) {
      (*errors)++;
      continue;
    }
    pcm.insert(pcm.end(), out.begin(), out.begin() + (size_t)got * aac_decoder_channels(dec));
  }
  aac_decoder_destroy(dec);
  return pcm;
}

/* Decodes the access units from `first` on with a cold decoder that
 * knows the configuration. */
std::vector<float> decode_raw(const Encoded& e, size_t first, int channels, int* errors) {
  std::vector<float> pcm;
  AacDecoderHandle dec = aac_decoder_create(kRate, channels);
  CHECK(dec && aac_decoder_set_config(dec, e.asc.data(), (int)e.asc.size()) == AAC_OK,
        "raw configuration accepted");
  if (!dec) {
    *errors = -1;
    return pcm;
  }
  aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
  std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  *errors = 0;
  for (size_t i = first; i < e.units.size(); i++) {
    const std::vector<uint8_t>& au = e.units[i].bytes;
    const int got = aac_decoder_decode(dec, au.data(), (int)au.size(), out.data(), (int)out.size());
    if (got != e.frame_size) {
      (*errors)++;
      continue;
    }
    pcm.insert(pcm.end(), out.begin(), out.begin() + (size_t)got * channels);
  }
  aac_decoder_destroy(dec);
  return pcm;
}

std::vector<int> indices_where(const Encoded& e, bool preroll) {
  std::vector<int> v;
  for (size_t i = 0; i < e.units.size(); i++) {
    if (preroll ? e.units[i].preroll_present > 0 : e.units[i].flag_indep > 0) {
      v.push_back((int)i);
    }
  }
  return v;
}

std::string join(const std::vector<int>& v) {
  std::string s;
  for (int x : v) {
    s += (s.empty() ? "" : ",") + std::to_string(x);
  }
  return s;
}

/* ── the schedule ──────────────────────────────────────────────── */

/* Encodes `seconds` of a signal with the raw transport and checks the
 * immediate playout frames and the independent frames against the
 * expected lists (every frame of the stream, the flush included), and
 * the encoder's report of each unit against its access unit. */
void check_schedule(const char* name, int channels, int bitrate, const AacUsacEncoderOptions& opt,
                    const std::set<int>& requests, double seconds, const std::vector<int>& want_ipf,
                    const std::vector<int>& want_indep) {
  const std::vector<float> pcm = gen_tones((int)(seconds * kRate), kRate, channels);
  const Encoded e = encode(pcm, channels, bitrate, opt, AAC_TRANSPORT_RAW, requests);
  CHECK(e.error == 0, "%s: encodes (%d)", name, e.error);
  const std::vector<int> ipf = indices_where(e, true);
  const std::vector<int> indep = indices_where(e, false);
  CHECK(ipf == want_ipf, "%s: pre-roll frames at %s (wanted %s)", name, join(ipf).c_str(),
        join(want_ipf).c_str());
  CHECK(indep == want_indep, "%s: independent frames at %s (wanted %s)", name, join(indep).c_str(),
        join(want_indep).c_str());
  for (size_t i = 0; i < e.units.size(); i++) {
    const Unit& u = e.units[i];
    CHECK(u.info.frame_index == (int)i && u.info.independent == u.flag_indep &&
              (u.info.preroll_bytes > 0) == (u.preroll_present > 0) &&
              u.info.bytes == (int)u.bytes.size() &&
              u.info.sample_position == (int64_t)i * e.frame_size - e.delay,
          "%s: unit %zu reported as the access unit says (index %d, independent %d/%d, pre-roll "
          "%d/%d, bytes %d/%zu, position %lld)",
          name, i, u.info.frame_index, u.info.independent, u.flag_indep, u.info.preroll_bytes,
          u.preroll_present, u.info.bytes, u.bytes.size(), (long long)u.info.sample_position);
    if (u.preroll_present > 0) {
      /* the pre-roll holds the configuration and a frame or two */
      CHECK(u.info.preroll_bytes > 8 && u.info.preroll_bytes < u.info.bytes,
            "%s: unit %zu pre-roll of %d bytes in %d", name, i, u.info.preroll_bytes, u.info.bytes);
    }
  }
  printf("  %s: %zu frames, pre-roll at %s\n", name, e.units.size(), join(ipf).c_str());
}

/* The frames from 0 to `frames` - 1 at every multiple of `step`, with
 * the frames `lead` before each one when `with_lead` (the lead of a
 * pre-roll frame beyond the stream's end included). */
std::vector<int> every(int step, int frames, int lead, bool with_lead) {
  std::vector<int> v;
  for (int i = 0; i < frames; i++) {
    if (i % step == 0 || (with_lead && lead > 0 && (i + lead) % step == 0)) {
      v.push_back(i);
    }
  }
  return v;
}

void test_schedule() {
  /* Two seconds of the plain core: 94 frames and two of flush. */
  const int plain_frames = (2 * kRate + kCore - 1) / kCore + 2;
  AacUsacEncoderOptions o = options(0);
  o.independent_interval_frames = 8;
  check_schedule("plain, every 8 frames", 2, 96000, o, {}, 2.0, every(8, plain_frames, 1, false),
                 every(8, plain_frames, 1, true));
  o = options(0);
  o.independent_interval_ms = 500; /* 24000 samples: 24 frames */
  check_schedule("plain, every 500 ms", 2, 96000, o, {}, 2.0, every(24, plain_frames, 1, false),
                 every(24, plain_frames, 1, true));
  o = options(0);
  o.independent_interval_ms = 501; /* rounded up to 24 frames */
  check_schedule("plain, every 501 ms", 1, 64000, o, {}, 2.0, every(24, plain_frames, 1, false),
                 every(24, plain_frames, 1, true));
  o = options(0);
  o.independent_interval_samples = 10000; /* 10 frames */
  check_schedule("plain, every 10000 samples", 2, 96000, o, {}, 2.0,
                 every(10, plain_frames, 1, false), every(10, plain_frames, 1, true));
  /* The precedence: samples over milliseconds over frames. */
  o = options(0);
  o.independent_interval_samples = 10000;
  o.independent_interval_ms = 500;
  o.independent_interval_frames = 3;
  check_schedule("plain, samples win", 2, 96000, o, {}, 2.0, every(10, plain_frames, 1, false),
                 every(10, plain_frames, 1, true));
  o = options(0);
  o.independent_interval_ms = 500;
  o.independent_interval_frames = 3;
  check_schedule("plain, milliseconds win", 2, 96000, o, {}, 2.0, every(24, plain_frames, 1, false),
                 every(24, plain_frames, 1, true));
  /* The distance bound caps the interval and leaves a shorter one alone. */
  o = options(0);
  o.independent_interval_frames = 50;
  o.independent_max_distance_frames = 10;
  check_schedule("plain, 50 frames bounded to 10", 2, 96000, o, {}, 2.0,
                 every(10, plain_frames, 1, false), every(10, plain_frames, 1, true));
  o = options(0);
  o.independent_interval_ms = 500;
  o.independent_max_distance_frames = 100;
  check_schedule("plain, 500 ms under a bound of 100", 2, 96000, o, {}, 2.0,
                 every(24, plain_frames, 1, false), every(24, plain_frames, 1, true));
  /* The default: one second, 47 frames at 48 kHz. */
  check_schedule("plain, default", 2, 128000, options(0), {}, 2.0, every(47, plain_frames, 1, false),
                 every(47, plain_frames, 1, true));

  /* With SBR a frame is 2048 output samples and the pre-roll two frames:
   * three seconds are 70 frames and three of flush. */
  const int sbr_frames = (3 * kRate + 2 * kCore - 1) / (2 * kCore) + 3;
  o = options(1);
  o.independent_interval_ms = 500; /* 12 frames of 2048 */
  check_schedule("SBR, every 500 ms", 1, 32000, o, {}, 3.0, every(12, sbr_frames, 2, false),
                 every(12, sbr_frames, 2, true));
  o = options(1);
  o.independent_interval_samples = 10000; /* 5 frames */
  check_schedule("SBR, every 10000 samples", 1, 32000, o, {}, 3.0, every(5, sbr_frames, 2, false),
                 every(5, sbr_frames, 2, true));
  o = options(1);
  o.independent_interval_frames = 7;
  check_schedule("SBR, every 7 frames", 2, 64000, o, {}, 3.0, every(7, sbr_frames, 2, false),
                 every(7, sbr_frames, 2, true));
  check_schedule("SBR, default", 2, 64000, options(1), {}, 3.0, every(24, sbr_frames, 2, false),
                 every(24, sbr_frames, 2, true));

  /* On demand: the next frame is independent and the pre-roll frame
   * follows by the lead; the interval counts from there; a request
   * while one is already due sooner changes nothing. */
  o = options(0);
  o.independent_interval_frames = 40;
  check_schedule("plain, requests at 15 and 39", 2, 96000, o, {15, 39}, 2.0, {0, 16, 40, 80},
                 {0, 15, 16, 39, 40, 79, 80});
  o = options(1);
  o.independent_interval_frames = 40;
  check_schedule("SBR, requests at 15 and 56", 1, 32000, o, {15, 56}, 3.0, {0, 17, 57},
                 {0, 15, 17, 55, 57});
  /* A request before the first frame, and one in the flush. */
  o = options(0);
  o.independent_interval_frames = 40;
  check_schedule("plain, requests at 0 and 94", 2, 96000, o, {0, 94}, 2.0, {0, 40, 80, 95},
                 {0, 39, 40, 79, 80, 94, 95});
  /* Without the pre-roll the independent frame itself opens the
   * segment, at once. */
  o = options(0);
  o.preroll = 0;
  o.independent_interval_frames = 20;
  check_schedule("plain without pre-roll, request at 7", 2, 96000, o, {7}, 2.0, {},
                 {0, 7, 27, 47, 67, 87});
  o = options(1);
  o.preroll = 0;
  o.independent_interval_ms = 500;
  check_schedule("SBR without pre-roll, request at 7", 1, 32000, o, {7}, 3.0, {},
                 {0, 7, 19, 31, 43, 55, 67});
}

/* ── the segment ceiling ───────────────────────────────────────── */

/* The bytes of each segment of raw access units: from a frame with the
 * pre-roll (an independent frame without one) to the next. */
std::vector<size_t> segment_sizes(const Encoded& e, int preroll) {
  std::vector<size_t> sizes;
  for (size_t i = 0; i < e.units.size(); i++) {
    const Unit& u = e.units[i];
    const bool starts = preroll ? u.preroll_present > 0 : u.flag_indep > 0;
    if (starts || sizes.empty()) {
      sizes.push_back(0);
    }
    sizes.back() += u.bytes.size();
  }
  return sizes;
}

void check_ceiling(const char* name, const std::vector<float>& pcm, int channels, int bitrate,
                   AacUsacEncoderOptions opt, int cap, const std::set<int>& requests,
                   double min_snr) {
  const Encoded free = encode(pcm, channels, bitrate, opt, AAC_TRANSPORT_RAW, requests);
  opt.segment_max_bytes = cap;
  const Encoded capped = encode(pcm, channels, bitrate, opt, AAC_TRANSPORT_RAW, requests);
  CHECK(free.error == 0 && capped.error == 0, "%s: encodes (%d, %d)", name, free.error, capped.error);
  const std::vector<size_t> before = segment_sizes(free, opt.preroll);
  const std::vector<size_t> after = segment_sizes(capped, opt.preroll);
  size_t largest_before = 0;
  size_t largest_after = 0;
  int over = 0;
  for (size_t s : before) {
    largest_before = std::max(largest_before, s);
  }
  for (size_t s : after) {
    largest_after = std::max(largest_after, s);
    over += s > (size_t)cap;
  }
  CHECK(largest_before > (size_t)cap, "%s: without the ceiling a segment exceeds it (%zu > %d)",
        name, largest_before, cap);
  CHECK(over == 0, "%s: %d of %zu segments over the ceiling of %d (largest %zu)", name, over,
        after.size(), cap, largest_after);
  CHECK(after.size() == before.size(), "%s: the schedule is the same under the ceiling", name);
  /* The same frames open the segments. */
  CHECK(indices_where(capped, true) == indices_where(free, true) &&
            indices_where(capped, false) == indices_where(free, false),
        "%s: the same independent frames", name);
  int errors = 0;
  const std::vector<float> out = decode_raw(capped, 0, channels, &errors);
  const double snr = aligned_snr_db(pcm, out, channels, capped.delay);
  int free_errors = 0;
  const std::vector<float> free_out = decode_raw(free, 0, channels, &free_errors);
  const double free_snr = aligned_snr_db(pcm, free_out, channels, free.delay);
  CHECK(errors == 0 && snr >= min_snr, "%s: decodes under the ceiling (%d errors, %.1f dB)", name,
        errors, snr);
  printf("  %s: %zu segments, largest %zu bytes free, %zu under the ceiling of %d; %.1f dB "
         "(free %.1f dB)\n",
         name, after.size(), largest_before, largest_after, cap, snr, free_snr);
}

/* Harmonics over a noise bed with decaying noise bursts every 180 ms:
 * short windows and bursts of bits over a dense spectrum. */
std::vector<float> gen_rich(int n, int channels) {
  std::vector<float> pcm = gen_wideband(n, kRate, channels);
  const std::vector<float> bursts = gen_transients(n, kRate, channels);
  for (size_t i = 0; i < pcm.size(); i++) {
    pcm[i] = 0.6f * pcm[i] + 0.8f * bursts[i];
  }
  return pcm;
}

void test_segment_ceiling() {
  /* The plain core at 128 kbit/s spends up to about 3100 bytes on eight
   * frames of this signal; the ceiling takes a third of that away, and
   * three dB of SNR with it. */
  const std::vector<float> bursts = gen_rich(3 * kRate, 2);
  AacUsacEncoderOptions o = options(0);
  o.independent_interval_frames = 8;
  check_ceiling("plain 128k, 8 frames under 2000 bytes", bursts, 2, 128000, o, 2000, {}, 6.0);
  /* Requests shorten segments; the ceiling holds through them. */
  check_ceiling("plain 128k with requests", bursts, 2, 128000, o, 2000, {3, 11, 12, 30, 45}, 6.0);
  /* The ceiling binds in the quality modes too. */
  {
    AacUsacEncoderOptions q = o;
    const Encoded capped_free = encode(bursts, 2, 128000, q, AAC_TRANSPORT_RAW, {}, AAC_RC_TVBR);
    q.segment_max_bytes = 2000;
    const Encoded capped = encode(bursts, 2, 128000, q, AAC_TRANSPORT_RAW, {}, AAC_RC_TVBR);
    size_t largest = 0;
    size_t largest_free = 0;
    for (size_t s : segment_sizes(capped, 1)) {
      largest = std::max(largest, s);
    }
    for (size_t s : segment_sizes(capped_free, 1)) {
      largest_free = std::max(largest_free, s);
    }
    CHECK(capped.error == 0 && largest <= 2000 && largest_free > 2000,
          "true VBR: largest segment %zu under the ceiling (%zu without it)", largest, largest_free);
  }
  /* With SBR: mono at 32 kbit/s over half-second segments of twelve
   * 2048-sample frames, up to about 2400 bytes each, under 1500. */
  const std::vector<float> mono = gen_rich(3 * kRate, 1);
  o = options(1);
  o.independent_interval_ms = 500;
  check_ceiling("SBR 32k, 500 ms under 1500 bytes", mono, 1, 32000, o, 1500, {5, 20}, 3.0);
  /* Without the pre-roll the segments run between independent frames. */
  o = options(0);
  o.preroll = 0;
  o.independent_interval_frames = 8;
  check_ceiling("plain 128k without pre-roll", bursts, 2, 128000, o, 2000, {}, 6.0);
}

/* ── frame info and cuts ───────────────────────────────────────── */

/* Encodes with the raw and the LOAS transports, decodes the whole
 * stream, and at every frame with a pre-roll decodes the stream from
 * that frame on with a cold decoder: the first frame it returns must
 * match the continuous decode and the source at the reported sample
 * position, and must match the source worse when shifted. */
void check_cuts(const char* name, const std::vector<float>& pcm, int channels, int bitrate,
                const AacUsacEncoderOptions& opt, const std::set<int>& requests) {
  const Encoded raw = encode(pcm, channels, bitrate, opt, AAC_TRANSPORT_RAW, requests);
  const Encoded loas = encode(pcm, channels, bitrate, opt, AAC_TRANSPORT_LOAS, requests);
  CHECK(raw.error == 0 && loas.error == 0 && raw.units.size() == loas.units.size(),
        "%s: both transports encode (%zu and %zu units)", name, raw.units.size(), loas.units.size());
  if (raw.error || loas.error || raw.units.size() != loas.units.size()) {
    return;
  }
  const int frame = raw.frame_size;
  int errors = 0;
  const std::vector<float> whole = decode_raw(raw, 0, channels, &errors);
  CHECK(errors == 0 && whole.size() == raw.units.size() * (size_t)frame * channels,
        "%s: the whole stream decodes (%d errors)", name, errors);
  int loas_errors = 0;
  const std::vector<float> whole_loas = decode_loas(loas.stream, channels, &loas_errors);
  CHECK(loas_errors == 0 && whole_loas == whole, "%s: the LOAS stream decodes to the same samples",
        name);
  /* The LOAS reports: the transport frame's bytes, the same flags. */
  for (size_t i = 0; i < loas.units.size(); i++) {
    const AacEncoderFrameInfo& a = raw.units[i].info;
    const AacEncoderFrameInfo& b = loas.units[i].info;
    if (!loas.units[i].has_info) {
      continue;
    }
    CHECK(b.frame_index == a.frame_index && b.independent == a.independent &&
              b.preroll_bytes == a.preroll_bytes && b.sample_position == a.sample_position &&
              b.bytes == (int)loas.units[i].bytes.size() && b.bytes > a.bytes,
          "%s: LOAS unit %zu reports the transport frame (%d bytes over %d)", name, i, b.bytes,
          a.bytes);
  }
  const std::vector<int> ipf = indices_where(raw, true);
  CHECK(ipf.size() >= 3, "%s: %zu pre-roll frames to cut at", name, ipf.size());
  const size_t n = (size_t)frame * channels;
  double worst_vs_whole = 200.0;
  double worst_vs_source = 200.0;
  for (int i : ipf) {
    const AacEncoderFrameInfo& info = raw.units[(size_t)i].info;
    if (info.sample_position < 0) {
      continue; /* the frames that decode to the priming */
    }
    const size_t at = (size_t)info.sample_position * channels;
    if (at + n > pcm.size()) {
      continue;
    }
    for (int pass = 0; pass < 2; pass++) {
      int cut_errors = 0;
      std::vector<float> cut;
      if (pass == 0) {
        cut = decode_raw(raw, (size_t)i, channels, &cut_errors);
      } else {
        const std::vector<std::pair<size_t, size_t>> frames = split_loas(loas.stream);
        CHECK(frames.size() == loas.units.size(), "%s: %zu LOAS frames", name, frames.size());
        const std::vector<uint8_t> tail(loas.stream.begin() + (long)frames[(size_t)i].first,
                                        loas.stream.end());
        cut = decode_loas(tail, channels, &cut_errors);
      }
      CHECK(cut_errors == 0 && cut.size() == (raw.units.size() - (size_t)i) * n,
            "%s: the %s stream cut at frame %d decodes whole (%d errors, %zu samples)", name,
            pass ? "LOAS" : "raw", i, cut_errors, cut.size());
      if (cut.size() < n) {
        continue;
      }
      const double vs_whole = snr_db(whole.data() + (size_t)i * n, cut.data(), n);
      const double vs_source = snr_db(pcm.data() + at, cut.data(), n);
      const double whole_vs_source = snr_db(pcm.data() + at, whole.data() + (size_t)i * n, n);
      /* shifted by a quarter frame the first frame no longer fits */
      const size_t shift = (size_t)(frame / 4) * channels;
      const double shifted = at >= shift ? snr_db(pcm.data() + at - shift, cut.data(), n) : -100.0;
      /* The noise filled lines come from each decoder's own generator,
       * so the cut and the continuous decode agree to the noise's level
       * and are measured against the source instead. */
      CHECK(vs_whole > 15.0 && vs_source > whole_vs_source - 2.0 && vs_source > shifted + 6.0,
            "%s: %s cut at frame %d: first frame %.1f dB against the continuous decode, %.1f dB "
            "against the source at %lld (continuous %.1f dB, shifted %.1f dB)",
            name, pass ? "LOAS" : "raw", i, vs_whole, vs_source, (long long)info.sample_position,
            whole_vs_source, shifted);
      worst_vs_whole = std::min(worst_vs_whole, vs_whole);
      worst_vs_source = std::min(worst_vs_source, vs_source - whole_vs_source);
    }
  }
  printf("  %s: cuts at %s; first frame at worst %.1f dB from the continuous decode, %.1f dB "
         "from its SNR\n",
         name, join(ipf).c_str(), worst_vs_whole, worst_vs_source);
}

void test_cuts() {
  const std::vector<float> stereo = gen_wideband(2 * kRate, kRate, 2);
  AacUsacEncoderOptions o = options(0);
  o.independent_interval_ms = 400;
  check_cuts("plain 96k, requests at 5 and 30", stereo, 2, 96000, o, {5, 30});
  o = options(1);
  o.independent_interval_ms = 500;
  check_cuts("SBR 64k, requests at 5 and 20", stereo, 2, 64000, o, {5, 20});
  const std::vector<float> mono = gen_wideband(2 * kRate, kRate, 1);
  o = options(1);
  o.independent_interval_frames = 9;
  o.segment_max_bytes = 1400;
  check_cuts("SBR 32k mono under a ceiling, requests at 4 and 25", mono, 1, 32000, o, {4, 25});
}

/* ── the API ───────────────────────────────────────────────────── */

void test_api() {
  AacEncoderHandle lc = aac_encoder_create(kRate, 2, 128000, AAC_AOT_LC, AAC_RC_CBR);
  AacEncoderFrameInfo info;
  CHECK(lc && aac_encoder_request_independent_frame(lc) == AAC_ERR_UNSUPPORTED &&
            aac_encoder_last_frame_info(lc, &info) == AAC_ERR_UNSUPPORTED,
        "the calls are refused for AAC-LC");
  aac_encoder_destroy(lc);
  AacEncoderHandle enc = aac_encoder_create(kRate, 2, 128000, AAC_AOT_USAC, AAC_RC_CBR);
  CHECK(enc != nullptr, "stereo encoder");
  if (!enc) {
    return;
  }
  CHECK(aac_encoder_request_independent_frame(nullptr) == AAC_ERR_INVALID_ARG &&
            aac_encoder_last_frame_info(enc, nullptr) == AAC_ERR_INVALID_ARG,
        "null arguments refused");
  CHECK(aac_encoder_last_frame_info(enc, &info) == AAC_ERR_STATE, "no frame yet");
  /* Negative schedule fields take the defaults. */
  AacUsacEncoderOptions o = options(0);
  o.independent_interval_ms = -5;
  o.independent_interval_samples = -1;
  o.independent_max_distance_frames = -1;
  o.segment_max_bytes = -100;
  CHECK(aac_encoder_set_usac(enc, &o) == AAC_OK, "negative schedule fields accepted as none");
  CHECK(aac_usac_enc_interval_frames(&o, kRate, kCore) == 47 &&
            aac_usac_enc_interval_frames(&o, kRate, 2 * kCore) == 24 &&
            aac_usac_enc_interval_frames(&o, 44100, kCore) == 44,
        "the default interval is one second rounded up");
  o.independent_interval_ms = 1000;
  CHECK(aac_usac_enc_interval_frames(&o, 44100, kCore) == 44 &&
            aac_usac_enc_interval_frames(&o, 44100, 2 * kCore) == 22,
        "a second in milliseconds rounds up the same way");
  o.independent_interval_samples = 1;
  CHECK(aac_usac_enc_interval_frames(&o, kRate, kCore) == 1, "one sample is one frame");
  o.independent_interval_samples = 0;
  o.independent_interval_ms = 1;
  CHECK(aac_usac_enc_interval_frames(&o, kRate, kCore) == 1, "one millisecond is one frame");
  o.independent_interval_ms = 0;
  o.independent_interval_frames = 1000;
  o.independent_max_distance_frames = 3;
  CHECK(aac_usac_enc_interval_frames(&o, kRate, kCore) == 3, "the bound caps the interval");
  /* The report of a flushed stream stays until the next one begins. */
  std::vector<float> block((size_t)kCore * 2, 0.1f);
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) * 4);
  o = options(0);
  CHECK(aac_encoder_set_usac(enc, &o) == AAC_OK, "plain options");
  CHECK(aac_encoder_encode(enc, block.data(), kCore, buf.data(), (int)buf.size()) > 0, "a frame");
  CHECK(aac_encoder_last_frame_info(enc, &info) == AAC_OK && info.frame_index == 0 &&
            info.independent == 1 && info.preroll_bytes > 0 && info.sample_position == -2 * kCore,
        "the first frame's report");
  CHECK(aac_encoder_request_independent_frame(enc) == AAC_OK, "a request mid-stream");
  CHECK(aac_encoder_encode(enc, block.data(), kCore, buf.data(), (int)buf.size()) > 0, "a frame");
  CHECK(aac_encoder_last_frame_info(enc, &info) == AAC_OK && info.frame_index == 1 &&
            info.independent == 1 && info.preroll_bytes == 0,
        "the requested frame is independent and opens the pre-roll");
  CHECK(aac_encoder_encode(enc, block.data(), kCore, buf.data(), (int)buf.size()) > 0, "a frame");
  CHECK(aac_encoder_last_frame_info(enc, &info) == AAC_OK && info.frame_index == 2 &&
            info.independent == 1 && info.preroll_bytes > 0 && info.sample_position == 0,
        "the pre-roll frame follows, at the source's first sample");
  CHECK(aac_encoder_flush(enc, buf.data(), (int)buf.size()) > 0, "flush");
  CHECK(aac_encoder_last_frame_info(enc, &info) == AAC_OK && info.frame_index == 4,
        "the flushed stream's last frame stays readable (index %d)", info.frame_index);
  CHECK(aac_encoder_encode(enc, block.data(), kCore, buf.data(), (int)buf.size()) > 0 &&
            aac_encoder_last_frame_info(enc, &info) == AAC_OK && info.frame_index == 0,
        "the next stream starts over");
  aac_encoder_destroy(enc);
}

/* ── the committed stream ──────────────────────────────────────── */

/* Stereo at 64 kbit/s (SBR by the bitrate rule), half-second segments
 * under 3000 bytes, independent frames requested before frames 5 and
 * 20: the pre-roll frames land at 0, 7, 19 and 22. */
const char* kCommittedName = "usac_enc_rap_cpe_64k.loas";
const std::set<int> kCommittedRequests = {5, 20};
const std::vector<int> kCommittedIpf = {0, 7, 19, 22};

AacUsacEncoderOptions committed_options() {
  AacUsacEncoderOptions o = options(-1);
  o.independent_interval_ms = 500;
  o.segment_max_bytes = 3000;
  return o;
}

/* The committed stream's signal: the HE-AAC suite's tones, noise and
 * clicks at half level, so that the reference decoders' 16-bit output
 * does not clip on the decoded clicks. */
std::vector<float> committed_signal() {
  std::vector<float> pcm = gen_he_signal(2);
  for (float& v : pcm) {
    v *= 0.5f;
  }
  return pcm;
}

int write_streams(const char* dir) {
  const Encoded e = encode(committed_signal(), 2, 64000, committed_options(), AAC_TRANSPORT_LOAS,
                           kCommittedRequests);
  const std::string path = std::string(dir) + "/" + kCommittedName;
  if (e.error || !write_file(path, e.stream.data(), e.stream.size())) {
    fprintf(stderr, "%s: not written\n", path.c_str());
    return 1;
  }
  printf("%s: %zu frames, %zu bytes\n", path.c_str(), e.units.size(), e.stream.size());
  return 0;
}

/* The committed stream's signal, the stream, and the stream cut at each
 * of its pre-roll frames, for the reference decoders; the sample
 * position of each cut is printed. */
int write_cuts(const char* dir) {
  const std::vector<float> pcm = committed_signal();
  const Encoded e = encode(pcm, 2, 64000, committed_options(), AAC_TRANSPORT_LOAS, kCommittedRequests);
  const Encoded raw = encode(pcm, 2, 64000, committed_options(), AAC_TRANSPORT_RAW, kCommittedRequests);
  const std::vector<std::pair<size_t, size_t>> frames = split_loas(e.stream);
  const std::string base = std::string(dir) + "/rap";
  if (e.error || raw.error || frames.size() != raw.units.size() ||
      !write_file(base + "_source.f32", pcm.data(), pcm.size() * sizeof(float)) ||
      !write_file(base + "_full.loas", e.stream.data(), e.stream.size())) {
    fprintf(stderr, "%s: not written\n", base.c_str());
    return 1;
  }
  printf("%s_full.loas: %zu frames, delay %d, frame %d\n", base.c_str(), frames.size(), e.delay,
         e.frame_size);
  /* FFmpeg's LATM decoder does not take USAC; it reads the MP4 form. */
  auto write_mp4 = [&](const std::string& path, size_t first) {
    std::vector<std::vector<uint8_t>> aus;
    for (size_t k = first; k < raw.units.size(); k++) {
      aus.push_back(raw.units[k].bytes);
    }
    const std::vector<uint8_t> file = mp4_file(aus, raw.asc, raw.frame_size, kRate, 2);
    return write_file(path, file.data(), file.size());
  };
  if (!write_mp4(base + "_full.mp4", 0)) {
    fprintf(stderr, "%s_full.mp4: not written\n", base.c_str());
    return 1;
  }
  for (int i : indices_where(raw, true)) {
    const std::string path = base + "_cut" + std::to_string(i) + ".loas";
    const size_t at = frames[(size_t)i].first;
    if (!write_file(path, e.stream.data() + at, e.stream.size() - at) ||
        !write_mp4(base + "_cut" + std::to_string(i) + ".mp4", (size_t)i)) {
      fprintf(stderr, "%s: not written\n", path.c_str());
      return 1;
    }
    printf("%s: frame %d, sample position %lld\n", path.c_str(), i,
           (long long)raw.units[(size_t)i].info.sample_position);
  }
  return 0;
}

void test_committed() {
  const std::vector<uint8_t> stream = read_file(data_path(kCommittedName));
  CHECK(!stream.empty(), "%s present", kCommittedName);
  if (stream.empty()) {
    return;
  }
  const std::vector<float> pcm = committed_signal();
  int errors = 0;
  const std::vector<float> out = decode_loas(stream, 2, &errors);
  const double snr = aligned_snr_db(pcm, out, 2, AAC_ENCODER_SBR_DELAY);
  CHECK(errors == 0 && split_loas(stream).size() == 27 && snr > 9.0,
        "%s: %zu frames, %d errors, %.1f dB", kCommittedName, split_loas(stream).size(), errors, snr);
  const Encoded raw = encode(pcm, 2, 64000, committed_options(), AAC_TRANSPORT_RAW, kCommittedRequests);
  CHECK(raw.error == 0 && indices_where(raw, true) == kCommittedIpf,
        "%s: pre-roll frames at %s", kCommittedName, join(indices_where(raw, true)).c_str());
  size_t largest = 0;
  for (size_t s : segment_sizes(raw, 1)) {
    largest = std::max(largest, s);
  }
  CHECK(largest <= 3000, "%s: largest segment %zu bytes", kCommittedName, largest);
  const Encoded e = encode(pcm, 2, 64000, committed_options(), AAC_TRANSPORT_LOAS, kCommittedRequests);
#if defined(__OPTIMIZE__) && defined(__FAST_MATH__) && !BAAC_SANITIZED
  CHECK(e.stream == stream, "%s: a fresh encode is byte identical (%zu vs %zu bytes)", kCommittedName,
        e.stream.size(), stream.size());
#else
  /* Two encodes that differ in a quantised line also differ in every
   * noise filled line after it, so the decodes are compared through the
   * source, not with each other. */
  int again_errors = 0;
  const std::vector<float> again = decode_loas(e.stream, 2, &again_errors);
  const double fresh = aligned_snr_db(pcm, again, 2, AAC_ENCODER_SBR_DELAY);
  CHECK(again_errors == 0 && fabs(fresh - snr) < 1.5,
        "%s: a fresh encode of this build decodes like the committed stream (%.1f against %.1f dB; "
        "the byte identity check needs the release build's flags)",
        kCommittedName, fresh, snr);
#endif
  printf("  %s: %.1f dB, largest segment %zu bytes\n", kCommittedName, snr, largest);
}

}  // namespace

int main(int argc, char** argv) {
  aac_tables_init();
  if (argc >= 3 && strcmp(argv[1], "--write-streams") == 0) {
    return write_streams(argv[2]);
  }
  if (argc >= 3 && strcmp(argv[1], "--write-cuts") == 0) {
    return write_cuts(argv[2]);
  }
  test_api();
  test_schedule();
  test_segment_ceiling();
  test_cuts();
  test_committed();
  return test_summary("test_usac_rap");
}
