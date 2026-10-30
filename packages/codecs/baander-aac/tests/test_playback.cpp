/* Playback control through the public API: the decoder's delay against
 * the alignment of every profile's decode, the tail aac_decoder_flush
 * drains (the limiter's look-ahead, the surround decoder's delay), the
 * seek of aac_decoder_clear_history against a fresh decoder, the rules
 * on which dynamic range control and SBR signalling apply where, and
 * the field tolerance of the decoder (program config elements in any
 * element order or changing mid-stream, SBR appearing in and vanishing
 * from a stream, an SBR header that cannot be applied). */
#include "asc.h"
#include "bitstream.h"
#include "channel_map.h"
#include "decoder.h"
#include "latm.h"
#include "mdct.h"
#include "test_util.h"

namespace {

constexpr int kMaxOut = AAC_DECODER_MAX_OUTPUT * 8;

/* Tones below 3 kHz, distinct per channel: inside the core band of
 * every profile, so the decode lines up with the source sample for
 * sample at the declared delay. */
std::vector<float> low_tones(int n, int sr, int channels) {
  std::vector<float> pcm((size_t)n * channels);
  for (int i = 0; i < n; i++) {
    const float t = (float)i / (float)sr;
    for (int c = 0; c < channels; c++) {
      pcm[(size_t)i * channels + c] =
          0.25f * sinf(2.0f * (float)M_PI * (440.0f + 220.0f * (float)c) * t) +
          0.15f * sinf(2.0f * (float)M_PI * 1250.0f * t + (float)c);
    }
  }
  return pcm;
}

/* ── encoding ──────────────────────────────────────────────────── */

struct Profile {
  const char* name;
  AacObjectType aot;
  int rate;
  int channels;
  int bitrate;
  AacTransport transport;
  int ld_sbr;   /* -1 none, else the dual-rate flag */
  int usac_sbr; /* -1 not USAC, else the sbr option */
  int mps212;
  double min_snr;
};

struct Encoded {
  std::vector<uint8_t> stream;
  int delay = 0;
  int frame = 0;
  int error = 0;
};

Encoded encode(const Profile& p, const std::vector<float>& pcm) {
  Encoded e;
  AacEncoderHandle enc = aac_encoder_create(p.rate, p.channels, p.bitrate, p.aot, AAC_RC_CBR);
  if (!enc) {
    e.error = AAC_ERR_INIT;
    return e;
  }
  if (p.transport != AAC_TRANSPORT_ADTS) {
    aac_encoder_set_transport(enc, p.transport);
  }
  if (p.ld_sbr >= 0) {
    aac_encoder_set_ld_sbr(enc, 1, p.ld_sbr);
  }
  if (p.usac_sbr >= 0) {
    AacUsacEncoderOptions o;
    aac_usac_encoder_options_init(&o);
    o.noise_filling = 1;
    o.preroll = 1;
    o.independent_interval_frames = 8;
    o.stream_id = -1;
    o.sbr = p.usac_sbr;
    o.mps212 = p.mps212;
    e.error = aac_encoder_set_usac(enc, &o);
  }
  e.delay = aac_encoder_delay(enc);
  e.frame = aac_encoder_frame_size(enc);
  std::vector<float> block((size_t)e.frame * p.channels);
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc) * 4);
  for (size_t pos = 0; pos < pcm.size() && !e.error; pos += block.size()) {
    std::fill(block.begin(), block.end(), 0.0f);
    memcpy(block.data(), pcm.data() + pos, std::min(block.size(), pcm.size() - pos) * sizeof(float));
    const int len = aac_encoder_encode(enc, block.data(), e.frame, buf.data(), (int)buf.size());
    if (len < 0) {
      e.error = len;
    } else {
      e.stream.insert(e.stream.end(), buf.begin(), buf.begin() + len);
    }
  }
  const int len = e.error ? 0 : aac_encoder_flush(enc, buf.data(), (int)buf.size());
  if (len < 0) {
    e.error = len;
  } else {
    e.stream.insert(e.stream.end(), buf.begin(), buf.begin() + len);
  }
  aac_encoder_destroy(enc);
  return e;
}

/* The frames of an ADTS or LOAS stream. */
std::vector<std::vector<uint8_t>> split_frames(const std::vector<uint8_t>& s) {
  std::vector<std::vector<uint8_t>> frames;
  size_t pos = 0;
  while (pos + 7 <= s.size()) {
    size_t len = 0;
    if (s[pos] == 0xFF && (s[pos + 1] & 0xF6) == 0xF0) {
      len = ((s[pos + 3] & 3) << 11) | (s[pos + 4] << 3) | (s[pos + 5] >> 5);
    } else if (aac_loas_is_sync(s.data() + pos, (int)(s.size() - pos))) {
      len = AAC_LOAS_HEADER_BYTES + aac_loas_payload_length(s.data() + pos);
    }
    if (len < 7 || pos + len > s.size()) {
      break;
    }
    frames.emplace_back(s.begin() + (long)pos, s.begin() + (long)(pos + len));
    pos += len;
  }
  return frames;
}

/* ── decoding ──────────────────────────────────────────────────── */

struct Decoded {
  std::vector<float> pcm;    /* interleaved, the flushed tail included */
  std::vector<int> frame_at; /* first sample of each frame's output */
  int channels = 0;
  int rate = 0;
  int delay = 0;
  int tail = 0; /* samples per channel the flush returned */
  int errors = 0;
};

/* Feeds the frames through the FIFO and decodes everything, then
 * flushes when asked. */
void decode_frames(AacDecoderHandle dec, const std::vector<std::vector<uint8_t>>& frames,
                   size_t from, size_t to, Decoded* d) {
  std::vector<float> out(kMaxOut);
  for (size_t f = from; f < to && f < frames.size(); f++) {
    CHECK(aac_decoder_feed(dec, frames[f].data(), (int)frames[f].size()) == (int)frames[f].size(),
          "frame %zu fed", f);
    for (;;) {
      const int got = aac_decoder_decode_next(dec, out.data(), kMaxOut);
      if (got == 0) {
        break;
      }
      if (got < 0) {
        d->errors++;
        continue;
      }
      d->channels = aac_decoder_channels(dec);
      d->rate = aac_decoder_output_sample_rate(dec);
      d->frame_at.push_back((int)(d->pcm.size() / (size_t)d->channels));
      d->pcm.insert(d->pcm.end(), out.begin(), out.begin() + (size_t)got * d->channels);
    }
  }
}

Decoded decode_all(const std::vector<uint8_t>& stream, int rate, int limiter_ms, bool flush) {
  Decoded d;
  AacDecoderHandle dec = aac_decoder_create(rate, 0);
  if (limiter_ms > 0) {
    aac_decoder_set_limiter(dec, 1, limiter_ms, 50);
  }
  decode_frames(dec, split_frames(stream), 0, SIZE_MAX, &d);
  d.delay = aac_decoder_delay(dec);
  if (flush) {
    std::vector<float> out(kMaxOut);
    d.tail = aac_decoder_flush(dec, out.data(), kMaxOut);
    if (d.tail > 0) {
      d.pcm.insert(d.pcm.end(), out.begin(), out.begin() + (size_t)d.tail * d.channels);
    }
    CHECK(aac_decoder_flush(dec, out.data(), kMaxOut) == 0, "a second flush has nothing left");
  }
  aac_decoder_destroy(dec);
  return d;
}

/* SNR of the decode against the source at `delay` samples per channel
 * over `from` to `to` source samples. */
double snr_at(const std::vector<float>& src, const std::vector<float>& out, int channels, int delay,
              size_t from, size_t to) {
  const size_t n = src.size() / channels;
  to = std::min(to, n);
  if ((to + delay) * channels > out.size() || from >= to) {
    return -100.0;
  }
  return snr_db(src.data() + from * channels, out.data() + (from + delay) * channels,
                (to - from) * channels);
}

/* The sum of the channels, the signal a parametric stereo or surround
 * coder carries in its downmix. */
std::vector<float> channel_sum(const std::vector<float>& pcm, int channels) {
  std::vector<float> sum(pcm.size() / channels);
  for (size_t i = 0; i < sum.size(); i++) {
    for (int c = 0; c < channels; c++) {
      sum[i] += pcm[i * channels + c];
    }
  }
  return sum;
}

/* ── delay, flush and alignment per profile ─────────────────────── */

const Profile kProfiles[] = {
    {"lc", AAC_AOT_LC, 48000, 2, 128000, AAC_TRANSPORT_ADTS, -1, -1, 0, 25.0},
    {"he", AAC_AOT_SBR, 48000, 2, 64000, AAC_TRANSPORT_ADTS, -1, -1, 0, 15.0},
    {"hev2", AAC_AOT_PS, 48000, 2, 32000, AAC_TRANSPORT_ADTS, -1, -1, 0, 15.0},
    {"ld", AAC_AOT_ER_LD, 48000, 2, 128000, AAC_TRANSPORT_LOAS, -1, -1, 0, 20.0},
    {"eld", AAC_AOT_ER_ELD, 48000, 2, 128000, AAC_TRANSPORT_LOAS, -1, -1, 0, 20.0},
    {"eld ld-sbr", AAC_AOT_ER_ELD, 48000, 2, 64000, AAC_TRANSPORT_LOAS, 1, -1, 0, 12.0},
    {"xhe", AAC_AOT_USAC, 48000, 2, 128000, AAC_TRANSPORT_LOAS, -1, 0, 0, 20.0},
    {"xhe sbr", AAC_AOT_USAC, 48000, 2, 64000, AAC_TRANSPORT_LOAS, -1, 1, 0, 12.0},
    {"xhe sbr mono", AAC_AOT_USAC, 48000, 1, 32000, AAC_TRANSPORT_LOAS, -1, 1, 0, 12.0},
    {"xhe mps212", AAC_AOT_USAC, 48000, 2, 48000, AAC_TRANSPORT_LOAS, -1, 1, 1, 15.0},
};

/* Encodes a known signal, decodes it with and without the limiter, and
 * checks: the decode lines up best at the encoder's delay plus the
 * decoder's (0 here), the output after the flush covers the whole input
 * once both delays are trimmed with the last samples reconstructed, and
 * with the limiter the flush returns exactly its look-ahead, after which
 * the limited output is the plain output delayed by that much (the
 * signal never needs a reduction, so the limiter passes it unchanged). */
void test_profile(const Profile& p) {
  const int n = p.rate * 3 / 2;
  const std::vector<float> src = low_tones(n, p.rate, p.channels);
  const Encoded e = encode(p, src);
  CHECK(e.error == 0 && !e.stream.empty(), "%s: encodes (%d)", p.name, e.error);
  if (e.error) {
    return;
  }
  const Decoded plain = decode_all(e.stream, p.rate, 0, true);
  CHECK(plain.errors == 0, "%s: %d errors", p.name, plain.errors);
  CHECK(plain.delay == 0 && plain.tail == 0, "%s: no decoder delay without the limiter (%d, %d)",
        p.name, plain.delay, plain.tail);
  const int ch = p.channels == 1 && p.aot == AAC_AOT_PS ? 2 : p.channels;
  CHECK(plain.channels == ch, "%s: %d channels", p.name, plain.channels);
  /* The best alignment of the low band, to the sample.  A parametric
   * stereo or surround decode rebuilds the channels only roughly from
   * their downmix, which is what lines up. */
  const bool parametric = p.aot == AAC_AOT_PS || p.mps212;
  const std::vector<float> ref = parametric ? channel_sum(src, p.channels) : src;
  const std::vector<float> got = parametric ? channel_sum(plain.pcm, ch) : plain.pcm;
  const int cmp_ch = parametric ? 1 : ch;
  const int expect = e.delay + plain.delay;
  int best = -1;
  double best_snr = -200.0;
  for (int d = expect - 48; d <= expect + 48; d++) {
    const double v = snr_at(ref, got, cmp_ch, d, (size_t)p.rate / 4, (size_t)n - 2048);
    if (v > best_snr) {
      best_snr = v;
      best = d;
    }
  }
  const size_t total = plain.pcm.size() / ch;
  const double whole = snr_at(ref, got, cmp_ch, expect, 0, (size_t)n);
  const double last = snr_at(ref, got, cmp_ch, expect, (size_t)n - 2048, (size_t)n);
  printf("%-13s encoder delay %5d, decoder delay %d: best alignment %5d (%.1f dB), whole %.1f dB, "
         "last 2048 samples %.1f dB, %zu output samples for %d\n",
         p.name, e.delay, plain.delay, best, best_snr, whole, last, total, n);
  CHECK(best == expect, "%s: best alignment %d, declared %d", p.name, best, expect);
  CHECK(total >= (size_t)(n + expect), "%s: %zu output samples cover %d after %d of delay", p.name,
        total, n, expect);
  CHECK(whole >= p.min_snr && last >= p.min_snr, "%s: SNR %.1f, at the end %.1f, below %.1f",
        p.name, whole, last, p.min_snr);

  const int attack_ms = 5;
  const Decoded limited = decode_all(e.stream, p.rate, attack_ms, true);
  const int look = plain.rate * attack_ms / 1000;
  CHECK(limited.delay == look && limited.tail == look,
        "%s: the limiter's %d samples reported (%d) and drained (%d)", p.name, look, limited.delay,
        limited.tail);
  bool same = limited.pcm.size() == plain.pcm.size() + (size_t)look * ch;
  for (size_t i = 0; same && i < plain.pcm.size(); i++) {
    same = limited.pcm[i + (size_t)look * ch] == plain.pcm[i];
  }
  for (size_t i = 0; same && i < (size_t)look * ch; i++) {
    same = limited.pcm[i] == 0.0f;
  }
  CHECK(same, "%s: the limited and flushed output is the plain output %d samples later, in full",
        p.name, look);
}

/* A flush into a buffer too small leaves the decoder as it was; a flush
 * on a decoder that decoded nothing returns 0; after a flush the decoder
 * takes a new stream like a fresh one. */
void test_flush_edges() {
  const Profile& p = kProfiles[0];
  const std::vector<float> src = low_tones(p.rate, p.rate, 2);
  const Encoded e = encode(p, src);
  const std::vector<std::vector<uint8_t>> frames = split_frames(e.stream);
  AacDecoderHandle dec = aac_decoder_create(p.rate, 0);
  std::vector<float> out(kMaxOut);
  CHECK(aac_decoder_flush(dec, out.data(), kMaxOut) == 0, "nothing to flush before any frame");
  CHECK(aac_decoder_flush(nullptr, out.data(), kMaxOut) == AAC_ERR_INVALID_ARG, "null handle");
  CHECK(aac_decoder_flush(dec, nullptr, 16) == AAC_ERR_INVALID_ARG, "null buffer");
  aac_decoder_set_limiter(dec, 1, 10, 50);
  Decoded first;
  decode_frames(dec, frames, 0, frames.size(), &first);
  CHECK(aac_decoder_flush(dec, out.data(), 100) == AAC_ERR_OVERFLOW, "a short buffer is refused");
  const int tail = aac_decoder_flush(dec, out.data(), kMaxOut);
  CHECK(tail == 480, "the refused flush left the tail in place (%d)", tail);
  Decoded again;
  decode_frames(dec, frames, 0, frames.size(), &again);
  CHECK(again.pcm == first.pcm, "after the flush a new stream decodes as on a fresh decoder");
  aac_decoder_destroy(dec);
}

/* ── the surround decoder's tail ────────────────────────────────── */

/* A raw data block of silence for the core of a surround stream: one
 * SCE or CPE with no coded band, or the ELD form without element ids. */
std::vector<uint8_t> silent_block(int aot, int channel_config) {
  uint8_t buf[64] = {};
  AacBitWriter w;
  aac_bitwriter_init(&w, buf, sizeof(buf));
  const int nch = channel_config == 2 ? 2 : 1;
  if (aot == AAC_AOT_ER_ELD) {
    for (int c = 0; c < nch; c++) {
      if (c == 0) {
        aac_bitwriter_write(&w, 100, 8); /* global_gain */
        aac_bitwriter_write(&w, 0, 6);   /* max_sfb */
      } else {
        aac_bitwriter_write(&w, 100, 8);
      }
      aac_bitwriter_write(&w, 0, 1); /* tns_data_present */
    }
  } else {
    aac_bitwriter_write(&w, nch == 2 ? AAC_ELEM_CPE : AAC_ELEM_SCE, 3);
    aac_bitwriter_write(&w, 0, 4); /* element_instance_tag */
    if (nch == 2) {
      aac_bitwriter_write(&w, 0, 1); /* common_window */
    }
    for (int c = 0; c < nch; c++) {
      aac_bitwriter_write(&w, 100, 8);              /* global_gain */
      aac_bitwriter_write(&w, 0, 1);                /* ics_reserved_bit */
      aac_bitwriter_write(&w, AAC_WIN_ONLY_LONG, 2); /* window_sequence */
      aac_bitwriter_write(&w, 0, 1);                /* window_shape */
      aac_bitwriter_write(&w, 0, 6);                /* max_sfb */
      aac_bitwriter_write(&w, 0, 1);                /* predictor_data_present */
      aac_bitwriter_write(&w, 0, 3);                /* pulse, tns, gain control */
    }
    aac_bitwriter_write(&w, AAC_ELEM_END, 3);
  }
  aac_bitwriter_byte_align(&w);
  return std::vector<uint8_t>(buf, buf + aac_bitwriter_bytes_written(&w));
}

/* The flushed tail of a surround stream is what the decoder outputs when
 * silent core frames without a spatial payload follow the stream: the
 * same samples, the reported delay's worth of them. */
void test_surround_tail(const char* name, int expect_delay) {
  const std::vector<uint8_t> stream = read_file(data_path(name));
  CHECK(!stream.empty(), "%s present", name);
  if (stream.empty()) {
    return;
  }
  const std::vector<std::vector<uint8_t>> frames = split_frames(stream);
  AacLatmConfig cfg = {};
  std::vector<uint8_t> au(8192);
  AacLatmPayload payloads[AAC_LATM_MAX_SUBFRAMES];
  const int count = aac_latm_read_mux_element(
      frames[0].data() + AAC_LOAS_HEADER_BYTES, (int)frames[0].size() - AAC_LOAS_HEADER_BYTES, 1,
      &cfg, au.data(), (int)au.size(), payloads, AAC_LATM_MAX_SUBFRAMES, nullptr);
  CHECK(count == 1 && cfg.valid, "%s: the first element parses", name);
  if (cfg.asc.aot == AAC_AOT_ER_ELD &&
      (cfg.asc.section_resilience || cfg.asc.scalefactor_resilience ||
       cfg.asc.spectral_resilience || cfg.asc.ld_sbr_present)) {
    CHECK(false, "%s: the silent frame does not cover this configuration", name);
    return;
  }
  const std::vector<uint8_t> silent = silent_block(cfg.asc.aot, cfg.asc.channel_config);
  std::vector<std::vector<uint8_t>> extended = frames;
  for (int k = 0; k < 3; k++) {
    std::vector<uint8_t> f(256);
    const int len = aac_loas_write_frame(&cfg, 0, silent.data(), (int)silent.size(), f.data(),
                                         (int)f.size());
    CHECK(len > 0, "%s: silent frame written", name);
    f.resize((size_t)std::max(len, 0));
    extended.push_back(f);
  }
  for (int limiter_ms : {0, 3}) {
    AacDecoderHandle dec = aac_decoder_create(48000, 0);
    AacDecoderHandle ref = aac_decoder_create(48000, 0);
    if (limiter_ms) {
      aac_decoder_set_limiter(dec, 1, limiter_ms, 50);
      aac_decoder_set_limiter(ref, 1, limiter_ms, 50);
    }
    Decoded d;
    decode_frames(dec, frames, 0, frames.size(), &d);
    const int delay = aac_decoder_delay(dec);
    const int look = limiter_ms * 48000 / 1000;
    CHECK(delay == expect_delay + look, "%s: delay %d, expected %d", name, delay,
          expect_delay + look);
    std::vector<float> out(kMaxOut);
    const int tail = aac_decoder_flush(dec, out.data(), kMaxOut);
    CHECK(tail == delay, "%s: flush returns %d samples of %d", name, tail, delay);
    Decoded r;
    decode_frames(ref, extended, 0, extended.size(), &r);
    const size_t at = d.pcm.size();
    bool same = r.pcm.size() >= at + (size_t)std::max(tail, 0) * d.channels && r.channels == d.channels &&
                d.errors == 0 && r.errors == 0;
    double peak = 0.0;
    for (size_t i = 0; same && i < (size_t)tail * d.channels; i++) {
      same = fabsf(out[i] - r.pcm[at + i]) <= 1e-6f;
      peak = std::max(peak, (double)fabsf(out[i]));
    }
    printf("%s limiter %d ms: delay %d, tail %d samples x %d channels, peak %.3f\n", name,
           limiter_ms, delay, tail, d.channels, peak);
    CHECK(same, "%s: the flushed tail equals the decode of silent frames that follow", name);
    CHECK(peak > 1e-4, "%s: the tail carries signal", name);
    aac_decoder_destroy(dec);
    aac_decoder_destroy(ref);
  }
}

/* ── seeking ───────────────────────────────────────────────────── */

/* The first bit of a USAC access unit, usacIndependencyFlag, of a LOAS
 * frame; -1 when the frame does not parse. */
int independent_flag(const std::vector<uint8_t>& frame, AacLatmConfig* cfg) {
  std::vector<uint8_t> au(16384);
  AacLatmPayload payloads[AAC_LATM_MAX_SUBFRAMES];
  const int count = aac_latm_read_mux_element(
      frame.data() + AAC_LOAS_HEADER_BYTES, (int)frame.size() - AAC_LOAS_HEADER_BYTES, 1, cfg,
      au.data(), (int)au.size(), payloads, AAC_LATM_MAX_SUBFRAMES, nullptr);
  return count == 1 ? au[(size_t)payloads[0].offset] >> 7 : -1;
}

/* A decoder that played the start of the stream, then seeks: the rest
 * of a partial frame waits in its FIFO, aac_decoder_clear_history, and
 * the frames from the seek point on.  Its output equals a fresh
 * decoder's from that point, bit for bit, and starts from silence. */
void seek_case(const char* name, const std::vector<std::vector<uint8_t>>& frames, size_t played,
               size_t seek, int rate, bool fresh_from_reset, bool starts_silent) {
  AacDecoderHandle dec = aac_decoder_create(rate, 0);
  Decoded before;
  decode_frames(dec, frames, 0, played, &before);
  const std::vector<uint8_t>& partial = frames[played];
  aac_decoder_feed(dec, partial.data(), (int)partial.size() / 2);
  const int concealed = aac_decoder_concealed_frames(dec);
  CHECK(aac_decoder_clear_history(dec) == AAC_OK, "%s: clear history", name);
  CHECK(aac_decoder_pending_bytes(dec) == 0, "%s: the old position's bytes are dropped", name);
  CHECK(aac_decoder_concealed_frames(dec) == concealed, "%s: the counters stay", name);
  Decoded after;
  decode_frames(dec, frames, seek, frames.size(), &after);
  AacDecoderHandle fresh = aac_decoder_create(rate, 0);
  if (fresh_from_reset) {
    /* A stream that carries its configuration only in the first frame:
     * the reference learns it there and starts over with a reset. */
    Decoded skip;
    decode_frames(fresh, frames, 0, 1, &skip);
    aac_decoder_reset(fresh);
  }
  Decoded ref;
  decode_frames(fresh, frames, seek, frames.size(), &ref);
  CHECK(after.errors == 0 && ref.errors == 0 && !after.pcm.empty(), "%s: decodes (%d, %d)", name,
        after.errors, ref.errors);
  CHECK(after.pcm == ref.pcm, "%s: after the seek the output is a fresh decoder's (%zu, %zu samples)",
        name, after.pcm.size(), ref.pcm.size());
  /* A fresh decoder may conceal too: an AAC-ELD stream's SBR data is
   * coded against the previous frame's, so its first frames from a
   * point inside the stream cannot be read. */
  CHECK(aac_decoder_concealed_frames(dec) - concealed == aac_decoder_concealed_frames(fresh),
        "%s: the seek conceals what a fresh decoder conceals (%d, %d)", name,
        aac_decoder_concealed_frames(dec) - concealed, aac_decoder_concealed_frames(fresh));
  if (starts_silent && !after.pcm.empty()) {
    double first = 0.0;
    for (int c = 0; c < after.channels; c++) {
      first = std::max(first, (double)fabsf(after.pcm[(size_t)c]));
    }
    CHECK(first < 1e-3, "%s: the first sample after the seek is %.4f, not silence", name, first);
  }
  aac_decoder_destroy(dec);
  aac_decoder_destroy(fresh);
}

void test_seek(const Profile& p) {
  const int n = p.rate * 3;
  const Encoded e = encode(p, low_tones(n, p.rate, p.channels));
  const std::vector<std::vector<uint8_t>> frames = split_frames(e.stream);
  CHECK(frames.size() > 40, "%s: %zu frames", p.name, frames.size());
  if (frames.size() <= 40) {
    return;
  }
  size_t seek = 30;
  if (p.aot == AAC_AOT_USAC) {
    /* An independent frame, which carries its pre-roll. */
    AacLatmConfig cfg = {};
    while (seek < frames.size() && independent_flag(frames[seek], &cfg) != 1) {
      seek++;
    }
    CHECK(seek < frames.size() - 4, "%s: an independent frame after 30", p.name);
  }
  seek_case(p.name, frames, 12, seek, p.rate, false, p.aot != AAC_AOT_USAC);
}

/* LOAS whose later frames refer to the first frame's StreamMuxConfig
 * (useSameStreamMux): the seek keeps it. */
void test_seek_same_mux() {
  const Profile p = {"lc loas", AAC_AOT_LC, 48000, 2, 128000, AAC_TRANSPORT_LOAS, -1, -1, 0, 0.0};
  const Encoded e = encode(p, low_tones(p.rate, p.rate, 2));
  std::vector<std::vector<uint8_t>> frames = split_frames(e.stream);
  AacLatmConfig cfg = {};
  std::vector<uint8_t> au(8192);
  AacLatmPayload payloads[AAC_LATM_MAX_SUBFRAMES];
  for (size_t f = 1; f < frames.size(); f++) {
    const int count = aac_latm_read_mux_element(
        frames[f].data() + AAC_LOAS_HEADER_BYTES, (int)frames[f].size() - AAC_LOAS_HEADER_BYTES, 1,
        &cfg, au.data(), (int)au.size(), payloads, AAC_LATM_MAX_SUBFRAMES, nullptr);
    CHECK(count == 1, "frame %zu parses", f);
    std::vector<uint8_t> out(8192);
    const int len = aac_loas_write_frame(&cfg, 1, au.data() + payloads[0].offset, payloads[0].size,
                                         out.data(), (int)out.size());
    out.resize((size_t)std::max(len, 0));
    frames[f] = out;
  }
  seek_case("lc loas, same stream mux", frames, 10, 25, p.rate, true, true);
}

/* The surround streams: the configuration of an in-band header stays
 * across the seek. */
void test_seek_surround(const char* name) {
  const std::vector<std::vector<uint8_t>> frames = split_frames(read_file(data_path(name)));
  CHECK(frames.size() > 30, "%s: %zu frames", name, frames.size());
  if (frames.size() > 30) {
    seek_case(name, frames, 8, 20, 48000, false, false);
  }
}

/* ── applicability rules ───────────────────────────────────────── */

/* Hand-built frames at 48 kHz (as in the layout suite): one coded band
 * per channel at a gain that tells the channels apart. */
void write_ics(AacBitWriter* w, int global_gain) {
  aac_bitwriter_write(w, (uint32_t)global_gain, 8);
  aac_bitwriter_write(w, 0, 1);
  aac_bitwriter_write(w, AAC_WIN_ONLY_LONG, 2);
  aac_bitwriter_write(w, AAC_WIN_SINE, 1);
  aac_bitwriter_write(w, 49, 6); /* max_sfb */
  aac_bitwriter_write(w, 0, 1);  /* predictor_data_present */
  aac_bitwriter_write(w, 4, 4);  /* section: codebook 4, length 1 */
  aac_bitwriter_write(w, 1, 5);
  aac_bitwriter_write(w, 0, 4); /* codebook 0 for the remaining 48 bands */
  aac_bitwriter_write(w, 31, 5);
  aac_bitwriter_write(w, 17, 5);
  aac_bitwriter_write_scalefactor(w, 0);
  aac_bitwriter_write(w, 0, 3); /* pulse, tns, gain control */
  const int quad[4] = {1, 0, 0, 0};
  aac_bitwriter_write_huffman(w, 4, quad);
}

void write_sce(AacBitWriter* w, int elem, int tag, int gain) {
  aac_bitwriter_write(w, (uint32_t)elem, 3);
  aac_bitwriter_write(w, (uint32_t)tag, 4);
  write_ics(w, gain);
}

void write_cpe(AacBitWriter* w, int tag, int gain_l, int gain_r) {
  aac_bitwriter_write(w, AAC_ELEM_CPE, 3);
  aac_bitwriter_write(w, (uint32_t)tag, 4);
  aac_bitwriter_write(w, 0, 1); /* common_window */
  write_ics(w, gain_l);
  write_ics(w, gain_r);
}

struct PceElem {
  int is_cpe;
  int tag;
};

/* program_config_element() with front, back and LFE lists. */
void write_pce(AacBitWriter* w, const std::vector<PceElem>& front, const std::vector<PceElem>& back,
               int lfe_tag) {
  aac_bitwriter_write(w, AAC_ELEM_PCE, 3);
  aac_bitwriter_write(w, 0, 4); /* element_instance_tag */
  aac_bitwriter_write(w, 1, 2); /* object_type: LC */
  aac_bitwriter_write(w, 3, 4); /* sampling_frequency_index: 48 kHz */
  aac_bitwriter_write(w, (uint32_t)front.size(), 4);
  aac_bitwriter_write(w, 0, 4);
  aac_bitwriter_write(w, (uint32_t)back.size(), 4);
  aac_bitwriter_write(w, lfe_tag >= 0 ? 1 : 0, 2);
  aac_bitwriter_write(w, 0, 3); /* num_assoc_data */
  aac_bitwriter_write(w, 0, 4); /* num_valid_cc */
  aac_bitwriter_write(w, 0, 3); /* no mixdowns */
  for (const auto* list : {&front, &back}) {
    for (const PceElem& e : *list) {
      aac_bitwriter_write(w, (uint32_t)e.is_cpe, 1);
      aac_bitwriter_write(w, (uint32_t)e.tag, 4);
    }
  }
  if (lfe_tag >= 0) {
    aac_bitwriter_write(w, (uint32_t)lfe_tag, 4);
  }
  aac_bitwriter_byte_align(w);
  aac_bitwriter_write(w, 0, 8); /* comment_field_bytes */
}

void start_frame(AacBitWriter* w, uint8_t* out, int size) {
  aac_bitwriter_init(w, out, size);
  aac_bitwriter_write(w, 0, 32);
  aac_bitwriter_write(w, 0, 24);
}

std::vector<uint8_t> finish_frame(AacBitWriter* w, uint8_t* out, int channel_config) {
  aac_bitwriter_write(w, AAC_ELEM_END, 3);
  aac_bitwriter_byte_align(w);
  AacAdtsHeader hdr = {};
  hdr.protection_absent = 1;
  hdr.object_type = AAC_AOT_LC;
  hdr.sample_rate_index = 3;
  hdr.channel_config = channel_config;
  hdr.frame_length = aac_bitwriter_bytes_written(w);
  hdr.buffer_fullness = 0x7FF;
  hdr.num_raw_blocks = 1;
  aac_adts_write(&hdr, out);
  return std::vector<uint8_t>(out, out + hdr.frame_length);
}

double slot_energy(const std::vector<float>& pcm, int channels, int c) {
  double e = 0.0;
  for (size_t i = (size_t)c; i < pcm.size(); i += (size_t)channels) {
    e += (double)pcm[i] * pcm[i];
  }
  return e;
}

/* One frame through a decoder: samples per channel or the error. */
int decode_one(AacDecoderHandle dec, const std::vector<uint8_t>& frame, std::vector<float>* out) {
  out->assign(kMaxOut, 0.0f);
  const int n = aac_decoder_decode(dec, frame.data(), (int)frame.size(), out->data(), kMaxOut);
  out->resize(n > 0 ? (size_t)n * aac_decoder_channels(dec) : 0);
  return n;
}

/* A fill element with an MPEG-D DRC payload (extension_type
 * EXT_UNI_DRC) in an AAC-LC frame is skipped: MPEG-D DRC belongs to
 * USAC here.  MPEG-4 DRC requested before a USAC stream is ignored
 * there (dynamic_range_info cannot occur in its syntax), and refused
 * once the stream is USAC. */
void test_drc_rules() {
  uint8_t buf[512];
  AacBitWriter w;
  start_frame(&w, buf, sizeof(buf));
  write_cpe(&w, 0, 160, 150);
  const std::vector<uint8_t> plain = finish_frame(&w, buf, 2);
  start_frame(&w, buf, sizeof(buf));
  write_cpe(&w, 0, 160, 150);
  aac_bitwriter_write(&w, AAC_ELEM_FIL, 3);
  aac_bitwriter_write(&w, 4, 4);         /* count: four bytes */
  aac_bitwriter_write(&w, 4, 4);         /* extension_type EXT_UNI_DRC */
  aac_bitwriter_write(&w, 0xA5C3F1E, 28); /* a uniDrcGain()-like payload */
  const std::vector<uint8_t> with_unidrc = finish_frame(&w, buf, 2);
  AacDecoderHandle a = aac_decoder_create(48000, 0);
  AacDecoderHandle b = aac_decoder_create(48000, 0);
  aac_decoder_set_drc(b, 1, 100, 100, 100, 0);
  aac_decoder_set_concealment(b, AAC_CONCEAL_OFF);
  std::vector<float> pa, pb;
  for (int f = 0; f < 3; f++) {
    CHECK(decode_one(a, plain, &pa) == 1024, "plain frame");
    CHECK(decode_one(b, with_unidrc, &pb) == 1024, "frame with an MPEG-D DRC fill payload");
  }
  CHECK(pa == pb && aac_decoder_has_drc(b) == 0 && aac_decoder_last_frame_concealed(b) == 0,
        "the MPEG-D DRC payload of an AAC-LC frame is skipped");
  aac_decoder_destroy(a);
  aac_decoder_destroy(b);

  const std::vector<uint8_t> usac = read_file(data_path("usac_music_48000_96000.loas"));
  CHECK(!usac.empty(), "USAC stream present");
  Decoded ref;
  Decoded drc;
  AacDecoderHandle r = aac_decoder_create(48000, 0);
  AacDecoderHandle d = aac_decoder_create(48000, 0);
  CHECK(aac_decoder_set_drc(d, 1, 100, 100, 40, 1) == AAC_OK, "MPEG-4 DRC before the stream");
  const std::vector<std::vector<uint8_t>> frames = split_frames(usac);
  decode_frames(r, frames, 0, frames.size(), &ref);
  decode_frames(d, frames, 0, frames.size(), &drc);
  CHECK(!ref.pcm.empty() && ref.pcm == drc.pcm && aac_decoder_has_drc(d) == 0 &&
            aac_decoder_program_ref_level(d) == -1,
        "MPEG-4 DRC requested before a USAC stream leaves it alone");
  CHECK(aac_decoder_set_drc(d, 1, 100, 100, 40, 1) == AAC_ERR_UNSUPPORTED,
        "MPEG-4 DRC refused on a USAC stream");
  aac_decoder_destroy(r);
  aac_decoder_destroy(d);
}

/* An explicitly signalled SBR rate other than twice the core rate
 * (downsampled SBR, or more than twice) is refused, and the decoder
 * takes a valid configuration afterwards. */
void test_sbr_rate_rule() {
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  for (int ext : {16000, 48000, 64000, 32000}) {
    for (AacSignaling sig : {AAC_SIGNAL_EXPLICIT_BC, AAC_SIGNAL_EXPLICIT_HIER}) {
      AacAsc asc;
      aac_asc_init(&asc, 16000, 2);
      asc.signaling = sig;
      asc.sbr_present = 1;
      asc.ps_present = -1;
      asc.ext_sample_rate = ext;
      uint8_t bytes[AAC_ASC_MAX_BYTES];
      const int len = aac_asc_write(&asc, bytes, sizeof(bytes));
      CHECK(len > 0, "config with SBR at %d written", ext);
      const int ret = aac_decoder_set_config(dec, bytes, len);
      if (ext == 32000) {
        CHECK(ret == AAC_OK && aac_decoder_output_sample_rate(dec) == 32000,
              "SBR at twice the core rate (%d, rate %d)", ret, aac_decoder_output_sample_rate(dec));
      } else {
        CHECK(ret == AAC_ERR_UNSUPPORTED, "SBR at %d over a 16 kHz core refused (%d)", ext, ret);
      }
    }
  }
  aac_decoder_destroy(dec);
}

/* ── field tolerance ───────────────────────────────────────────── */

/* A program config element is followed whatever the order of the
 * elements in the block or in its own lists, and a PCE after the
 * channel elements leaves the layout they define. */
void test_pce_order() {
  uint8_t buf[1024];
  AacBitWriter w;
  /* PCE: front SCE 0 and CPE 0, back SCE 1, LFE 0; the block carries
   * them back to front.  L 170 > C 160 > R 150 > Cs 140 > LFE 130 in the
   * slots L R C LFE Cs. */
  start_frame(&w, buf, sizeof(buf));
  write_pce(&w, {{0, 0}, {1, 0}}, {{0, 1}}, 0);
  write_sce(&w, AAC_ELEM_LFE, 0, 130);
  write_sce(&w, AAC_ELEM_SCE, 1, 140);
  write_cpe(&w, 0, 170, 150);
  write_sce(&w, AAC_ELEM_SCE, 0, 160);
  const std::vector<uint8_t> reversed = finish_frame(&w, buf, 0);
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
  std::vector<float> pcm;
  CHECK(decode_one(dec, reversed, &pcm) == 1024 && aac_decoder_channels(dec) == 5,
        "elements in reverse PCE order decode (%d channels)", aac_decoder_channels(dec));
  if (aac_decoder_channels(dec) == 5) {
    double e[5];
    for (int c = 0; c < 5; c++) {
      e[c] = slot_energy(pcm, 5, c);
    }
    CHECK(e[0] > e[2] && e[2] > e[1] && e[1] > e[4] && e[4] > e[3] && e[3] > 0.0,
          "each element lands in its PCE slot (%g %g %g %g %g)", e[0], e[1], e[2], e[3], e[4]);
  }
  aac_decoder_destroy(dec);

  /* PCE lists out of the usual order: the pair before the centre, tags
   * descending. */
  start_frame(&w, buf, sizeof(buf));
  write_pce(&w, {{1, 3}, {0, 2}}, {{1, 1}}, -1);
  write_sce(&w, AAC_ELEM_SCE, 2, 160);
  write_cpe(&w, 3, 170, 150);
  write_cpe(&w, 1, 140, 135);
  const std::vector<uint8_t> unusual = finish_frame(&w, buf, 0);
  dec = aac_decoder_create(48000, 0);
  aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
  CHECK(decode_one(dec, unusual, &pcm) == 1024 && aac_decoder_channels(dec) == 5,
        "a PCE listing the pair before the centre is followed (%d channels)",
        aac_decoder_channels(dec));
  CHECK(decode_one(dec, unusual, &pcm) == 1024, "and the next frame with it");
  aac_decoder_destroy(dec);

  /* A PCE after the channel elements of a stream without configuration. */
  start_frame(&w, buf, sizeof(buf));
  write_sce(&w, AAC_ELEM_SCE, 0, 160);
  write_cpe(&w, 0, 170, 150);
  write_pce(&w, {{0, 0}, {1, 0}}, {}, -1);
  const std::vector<uint8_t> late = finish_frame(&w, buf, 0);
  dec = aac_decoder_create(48000, 0);
  aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
  CHECK(decode_one(dec, late, &pcm) == 1024 && aac_decoder_channels(dec) == 3,
        "a PCE after the elements keeps their layout (%d channels)", aac_decoder_channels(dec));
  aac_decoder_destroy(dec);
}

/* A PCE inside an ADTS stream that names another layout than the one in
 * force (the header's channel configuration, or an earlier PCE) is a
 * change of configuration: that frame is refused (concealed with
 * concealment on) and the decoder continues with the PCE's layout. */
void test_pce_change() {
  uint8_t buf[1024];
  AacBitWriter w;
  for (int header_config : {2, 0}) {
    std::vector<std::vector<uint8_t>> frames;
    for (int f = 0; f < 2; f++) {
      start_frame(&w, buf, sizeof(buf));
      if (header_config == 0) {
        write_pce(&w, {{1, 0}}, {}, -1);
      }
      write_cpe(&w, 0, 170, 150);
      frames.push_back(finish_frame(&w, buf, header_config));
    }
    start_frame(&w, buf, sizeof(buf));
    write_pce(&w, {{0, 0}, {1, 0}}, {{1, 1}}, -1);
    write_sce(&w, AAC_ELEM_SCE, 0, 160);
    write_cpe(&w, 0, 170, 150);
    write_cpe(&w, 1, 140, 135);
    frames.push_back(finish_frame(&w, buf, header_config));
    for (int f = 0; f < 2; f++) {
      start_frame(&w, buf, sizeof(buf));
      write_sce(&w, AAC_ELEM_SCE, 0, 160);
      write_cpe(&w, 0, 170, 150);
      write_cpe(&w, 1, 140, 135);
      frames.push_back(finish_frame(&w, buf, header_config));
    }
    for (AacConcealment mode : {AAC_CONCEAL_OFF, AAC_CONCEAL_INTERPOLATE}) {
      AacDecoderHandle dec = aac_decoder_create(48000, 0);
      aac_decoder_set_concealment(dec, mode);
      std::vector<float> pcm;
      const int n0 = decode_one(dec, frames[0], &pcm);
      const int n1 = decode_one(dec, frames[1], &pcm);
      CHECK(n0 == 1024 && n1 == 1024 && aac_decoder_channels(dec) == 2,
            "config %d: the stereo frames decode", header_config);
      const int n2 = decode_one(dec, frames[2], &pcm);
      if (mode == AAC_CONCEAL_OFF) {
        CHECK(n2 < 0, "config %d: the frame that changes the layout is refused (%d)",
              header_config, n2);
      } else {
        CHECK(n2 == 1024 && aac_decoder_last_frame_concealed(dec) == 1 &&
                  aac_decoder_channels(dec) == 5,
              "config %d: with concealment it is concealed in the new layout (%d, %d channels)",
              header_config, n2, aac_decoder_channels(dec));
      }
      const int n3 = decode_one(dec, frames[3], &pcm);
      const int n4 = decode_one(dec, frames[4], &pcm);
      CHECK(n3 == 1024 && n4 == 1024 && aac_decoder_channels(dec) == 5 &&
                aac_decoder_last_frame_concealed(dec) == 0,
            "config %d mode %d: the frames after it decode in the PCE's layout (%d %d, %d ch)",
            header_config, (int)mode, n3, n4, aac_decoder_channels(dec));
      if (aac_decoder_channels(dec) == 5) {
        CHECK(slot_energy(pcm, 5, 0) > slot_energy(pcm, 5, 2) &&
                  slot_energy(pcm, 5, 2) > slot_energy(pcm, 5, 1) &&
                  slot_energy(pcm, 5, 4) > 0.0,
              "config %d: the new layout's slots", header_config);
      }
      aac_decoder_destroy(dec);
    }
  }
}

/* SBR data appearing in or vanishing from an implicitly signalled
 * stream: the output rate and frame size follow at that frame, with no
 * frame lost, and the stream after the change decodes as it does on its
 * own once the filterbanks have settled. */
void test_implicit_sbr_change() {
  const int core = 24000;
  const std::vector<float> src_he = low_tones(48000, 48000, 2);
  const std::vector<float> src_lc = low_tones(core, core, 2);
  const Profile he = {"he", AAC_AOT_SBR, 48000, 2, 48000, AAC_TRANSPORT_ADTS, -1, -1, 0, 0.0};
  const Profile lc = {"lc", AAC_AOT_LC, core, 2, 64000, AAC_TRANSPORT_ADTS, -1, -1, 0, 0.0};
  const std::vector<std::vector<uint8_t>> he_frames = split_frames(encode(he, src_he).stream);
  const std::vector<std::vector<uint8_t>> lc_frames = split_frames(encode(lc, src_lc).stream);
  for (int order = 0; order < 2; order++) {
    const auto& first = order == 0 ? he_frames : lc_frames;
    const auto& second = order == 0 ? lc_frames : he_frames;
    std::vector<std::vector<uint8_t>> joined = first;
    joined.insert(joined.end(), second.begin(), second.end());
    AacDecoderHandle dec = aac_decoder_create(core, 0);
    std::vector<float> out(kMaxOut);
    std::vector<float> after;
    std::vector<int> after_at;
    int errors = 0;
    bool rates_ok = true;
    for (size_t f = 0; f < joined.size(); f++) {
      aac_decoder_feed(dec, joined[f].data(), (int)joined[f].size());
      for (;;) {
        const int got = aac_decoder_decode_next(dec, out.data(), kMaxOut);
        if (got == 0) {
          break;
        }
        if (got < 0) {
          errors++;
          continue;
        }
        const bool in_he = (order == 0) == (f < first.size());
        rates_ok = rates_ok && aac_decoder_output_sample_rate(dec) == (in_he ? 48000 : core) &&
                   got == (in_he ? 2048 : 1024) && aac_decoder_frame_size(dec) == got;
        if (f >= first.size()) {
          after_at.push_back((int)after.size());
          after.insert(after.end(), out.begin(), out.begin() + (size_t)got * 2);
        }
      }
    }
    CHECK(errors == 0 && aac_decoder_concealed_frames(dec) == 0,
          "%s: %d errors, %d concealed across the change", order ? "lc to he" : "he to lc", errors,
          aac_decoder_concealed_frames(dec));
    CHECK(rates_ok, "%s: rate and frame size follow the SBR data frame by frame",
          order ? "lc to he" : "he to lc");
    CHECK(after_at.size() == second.size(), "%s: every frame after the change decoded",
          order ? "lc to he" : "he to lc");
    aac_decoder_destroy(dec);
    std::vector<uint8_t> alone;
    for (const auto& f : second) {
      alone.insert(alone.end(), f.begin(), f.end());
    }
    const Decoded ref = decode_all(alone, core, 0, false);
    const size_t from = after_at.size() > 4 ? (size_t)after_at[4] * 2 : 0;
    const size_t m = std::min(after.size(), ref.pcm.size());
    const double snr = m > from ? snr_db(ref.pcm.data() + from, after.data() + from, m - from) : -100.0;
    printf("%s: after the change %.1f dB against the stream decoded alone\n",
           order ? "lc to he" : "he to lc", snr);
    CHECK(snr > 60.0, "%s: the stream after the change decodes as alone (%.1f dB)",
          order ? "lc to he" : "he to lc", snr);
  }
}

/* An SBR header whose frequency tables cannot be built: the frame's SBR
 * payload is discarded with it, the core decodes, and the high band
 * continues on the previous parameters as concealment, instead of
 * vanishing until the next header. */
void test_bad_sbr_header() {
  const int sr = 48000;
  const std::vector<float> src = gen_wideband(sr * 2, sr, 1);
  const Profile p = {"he mono", AAC_AOT_SBR, sr, 1, 32000, AAC_TRANSPORT_ADTS, -1, -1, 0, 0.0};
  const std::vector<std::vector<uint8_t>> frames = split_frames(encode(p, src).stream);
  /* The frames whose SBR extension carries a header, found from the
   * element extents the decoder records. */
  AacDecoderHandle probe = aac_decoder_create(24000, 0);
  auto* ps = static_cast<AacDecoderState*>(probe);
  std::vector<float> pcm;
  size_t target = 0;
  int header_bit = -1;
  for (size_t f = 0; f < frames.size() && header_bit < 0; f++) {
    decode_one(probe, frames[f], &pcm);
    for (int i = 0; i < ps->num_crc_regions && f > 20; i++) {
      if (ps->crc_region[i].type != AAC_ELEM_FIL) {
        continue;
      }
      AacBitReader r;
      aac_bitreader_init(&r, frames[f].data() + 7, (int)frames[f].size() - 7);
      aac_bitreader_skip(&r, ps->crc_region[i].start);
      const int count = (int)aac_bitreader_read(&r, 4);
      if (count == 15) {
        aac_bitreader_skip(&r, 8);
      }
      if (aac_bitreader_read(&r, 4) == 13 && aac_bitreader_read(&r, 1) == 1) {
        target = f;
        header_bit = 56 + r.pos;
      }
    }
  }
  aac_decoder_destroy(probe);
  CHECK(header_bit > 0, "a frame with an SBR header after the first second's start");
  if (header_bit <= 0) {
    return;
  }
  /* bs_amp_res, then bs_start_freq 0 and bs_stop_freq 13: a high band
   * wider than the QMF bank allows. */
  std::vector<uint8_t> bad = frames[target];
  auto set_bits = [&](int pos, int nbits, int value) {
    for (int b = 0; b < nbits; b++) {
      const int bit = (value >> (nbits - 1 - b)) & 1;
      uint8_t& byte = bad[(size_t)(pos + b) >> 3];
      const int shift = 7 - ((pos + b) & 7);
      byte = (uint8_t)((byte & ~(1 << shift)) | (bit << shift));
    }
  };
  set_bits(header_bit + 1, 4, 0);
  set_bits(header_bit + 5, 4, 13);
  AacDecoderHandle clean = aac_decoder_create(24000, 0);
  AacDecoderHandle damaged = aac_decoder_create(24000, 0);
  std::vector<float> all_clean, all_damaged;
  int concealed = 0;
  for (size_t f = 0; f <= target + 1; f++) {
    std::vector<float> a, b;
    CHECK(decode_one(clean, frames[f], &a) == 2048, "clean frame %zu", f);
    const int n = decode_one(damaged, f == target ? bad : frames[f], &b);
    CHECK(n == 2048, "frame %zu decodes at the SBR rate (%d)", f, n);
    if (f == target) {
      concealed = aac_decoder_last_frame_concealed(damaged);
    }
    all_clean.insert(all_clean.end(), a.begin(), a.end());
    all_damaged.insert(all_damaged.end(), b.begin(), b.end());
  }
  CHECK(concealed == 1, "the frame with the unusable header counts as concealed");
  const size_t from = target * 2048;
  const size_t to = from + 2048;
  const double low_c = band_db(all_clean, 1, 0, 100.0f, 3000.0f, sr, from, to);
  const double low_d = band_db(all_damaged, 1, 0, 100.0f, 3000.0f, sr, from, to);
  const double high_c = band_db(all_clean, 1, 0, 11000.0f, 15000.0f, sr, from, to);
  const double high_d = band_db(all_damaged, 1, 0, 11000.0f, 15000.0f, sr, from, to);
  printf("unusable SBR header in frame %zu: core band %.1f / %.1f dB, high band %.1f / %.1f dB\n",
         target, low_c, low_d, high_c, high_d);
  CHECK(fabs(low_c - low_d) < 0.5, "the core band decodes (%.1f against %.1f dB)", low_d, low_c);
  CHECK(fabs(high_c - high_d) < 6.0, "the high band continues (%.1f against %.1f dB)", high_d,
        high_c);
  aac_decoder_destroy(clean);
  aac_decoder_destroy(damaged);
}

}  // namespace

int main() {
  aac_tables_init();
  for (const Profile& p : kProfiles) {
    test_profile(p);
  }
  test_flush_edges();
  test_surround_tail("mps_525_header.loas", 560 + 6 * 64 + 577);
  test_surround_tail("mps_5151_header.loas", 560 + 6 * 64 + 577);
  test_surround_tail("mps_212_eld.loas", 256);
  for (const Profile& p : kProfiles) {
    test_seek(p);
  }
  test_seek_same_mux();
  test_seek_surround("mps_525_header.loas");
  test_seek_surround("mps_525_asc.loas");
  test_seek_surround("mps_212_eld.loas");
  test_drc_rules();
  test_sbr_rate_rule();
  test_pce_order();
  test_pce_change();
  test_implicit_sbr_change();
  test_bad_sbr_header();
  return test_summary("test_playback");
}
