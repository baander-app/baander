/* The transport layer: the ADTS CRC, resynchronisation of the streaming
 * decoder across damaged input, the AudioSpecificConfig in every
 * signalling mode, and LATM/LOAS framing of our own streams. */
#include "asc.h"
#include "bitstream.h"
#include "latm.h"
#include "test_util.h"

/* ── helpers ───────────────────────────────────────────────────── */

/* Decodes `stream` through the FIFO in chunks of `chunk` bytes (one feed
 * per chunk, every pending frame decoded after each), returning the
 * samples and counting frames and errors. */
static std::vector<float> decode_chunked(AacDecoderHandle dec, const std::vector<uint8_t>& stream,
                                         size_t chunk, int* frames, int* errors) {
  std::vector<float> out;
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  *frames = 0;
  *errors = 0;
  size_t pos = 0;
  while (pos < stream.size()) {
    const size_t n = std::min(chunk, stream.size() - pos);
    const int taken = aac_decoder_feed(dec, stream.data() + pos, (int)n);
    if (taken < 0) {
      (*errors)++;
      break;
    }
    pos += (size_t)taken;
    for (;;) {
      const int got = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
      if (got == 0) {
        break;
      }
      if (got < 0) {
        (*errors)++;
        continue;
      }
      (*frames)++;
      out.insert(out.end(), pcm.begin(), pcm.begin() + (size_t)got * aac_decoder_channels(dec));
    }
  }
  return out;
}

/* Feeds each packet whole and decodes it. */
static std::vector<float> decode_packets(AacDecoderHandle dec,
                                         const std::vector<std::vector<uint8_t>>& packets,
                                         int* errors) {
  std::vector<float> out;
  std::vector<float> pcm((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  *errors = 0;
  for (const std::vector<uint8_t>& p : packets) {
    if (aac_decoder_feed(dec, p.data(), (int)p.size()) != (int)p.size()) {
      (*errors)++;
      continue;
    }
    const int got = aac_decoder_decode_next(dec, pcm.data(), (int)pcm.size());
    if (got <= 0) {
      (*errors)++;
      continue;
    }
    out.insert(out.end(), pcm.begin(), pcm.begin() + (size_t)got * aac_decoder_channels(dec));
  }
  return out;
}

/* Encodes a signal with the given transport settings; `packets` receives
 * every frame on its own (LATM elements, raw access units), `config` the
 * AudioSpecificConfig. */
static std::vector<uint8_t> encode_transport(const std::vector<float>& pcm, int sr, int channels,
                                             int bitrate, AacObjectType aot,
                                             AacTransport transport, AacSignaling signaling,
                                             int crc, std::vector<std::vector<uint8_t>>* packets,
                                             std::vector<uint8_t>* config) {
  std::vector<uint8_t> out;
  AacEncoderHandle enc = aac_encoder_create(sr, channels, bitrate, aot, AAC_RC_CBR);
  CHECK(enc != nullptr, "encoder create");
  if (!enc) {
    return out;
  }
  CHECK(aac_encoder_set_transport(enc, transport) == AAC_OK, "set transport");
  CHECK(aac_encoder_set_signaling(enc, signaling) == AAC_OK, "set signaling");
  CHECK(aac_encoder_set_crc(enc, crc) == AAC_OK, "set crc");
  if (config) {
    config->resize(AAC_ASC_MAX_BYTES);
    const int len = aac_encoder_config(enc, config->data(), (int)config->size());
    CHECK(len > 0, "encoder config: %d", len);
    config->resize((size_t)std::max(len, 0));
  }
  const int frame = aac_encoder_frame_size(enc);
  std::vector<float> block((size_t)frame * channels);
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc));
  bool started = false;
  for (size_t pos = 0; pos < pcm.size(); pos += block.size()) {
    std::fill(block.begin(), block.end(), 0.0f);
    const size_t n = std::min(block.size(), pcm.size() - pos);
    memcpy(block.data(), pcm.data() + pos, n * sizeof(float));
    const int len = aac_encoder_encode(enc, block.data(), frame, buf.data(), (int)buf.size());
    CHECK(len > 0, "encode: %d", len);
    if (len <= 0) {
      break;
    }
    if (!started) {
      started = true;
      CHECK(aac_encoder_set_transport(enc, transport) == AAC_ERR_STATE,
            "transport change refused mid-stream");
    }
    out.insert(out.end(), buf.begin(), buf.begin() + len);
    if (packets) {
      packets->emplace_back(buf.begin(), buf.begin() + len);
    }
  }
  /* A stream transport flushes its frames back to back, which are split
   * again here; a packet transport yields one unit per call. */
  std::vector<uint8_t> tail(buf.size() * 4);
  int flushed = 0;
  for (;;) {
    const int len = aac_encoder_flush(enc, tail.data(), (int)tail.size());
    CHECK(len >= 0, "flush: %d", len);
    if (len <= 0) {
      break;
    }
    flushed += len;
    out.insert(out.end(), tail.begin(), tail.begin() + len);
    if (packets) {
      size_t pos = 0;
      while (pos < (size_t)len) {
        size_t n = 0;
        if (transport == AAC_TRANSPORT_ADTS) {
          n = ((tail[pos + 3] & 3) << 11) | (tail[pos + 4] << 3) | (tail[pos + 5] >> 5);
        } else if (transport == AAC_TRANSPORT_LOAS) {
          n = 3 + (((tail[pos + 1] & 0x1F) << 8) | tail[pos + 2]);
        } else {
          n = (size_t)len - pos;
        }
        packets->emplace_back(tail.begin() + (long)pos, tail.begin() + (long)(pos + n));
        pos += n;
      }
    }
  }
  CHECK(flushed > 0, "flush wrote the delay");
  CHECK(aac_encoder_set_transport(enc, transport) == AAC_OK, "transport settable after flush");
  aac_encoder_destroy(enc);
  return out;
}

static bool same_samples(const std::vector<float>& a, const std::vector<float>& b) {
  return a.size() == b.size() && memcmp(a.data(), b.data(), a.size() * sizeof(float)) == 0;
}

/* ── CRC ───────────────────────────────────────────────────────── */

static void test_crc_vectors() {
  /* CRC-16 with polynomial 0x8005, initial value 0xFFFF, unreflected, no
   * final inversion: the check value of "123456789" is 0xAEE7. */
  const uint8_t msg[] = "123456789";
  CHECK(aac_crc16_bits(AAC_CRC16_INIT, msg, 0, 72) == 0xAEE7, "check value %04X",
        aac_crc16_bits(AAC_CRC16_INIT, msg, 0, 72));
  CHECK(aac_crc16_bits(AAC_CRC16_INIT, msg, 0, 0) == 0xFFFF, "empty message");
  const uint8_t a[] = {0xFF};
  CHECK(aac_crc16_bits(AAC_CRC16_INIT, a, 0, 8) == 0xFF00, "one 0xFF byte: %04X",
        aac_crc16_bits(AAC_CRC16_INIT, a, 0, 8));
  /* Bit by bit from an offset equals the whole, and explicit zero bits
   * equal zero data. */
  uint16_t crc = AAC_CRC16_INIT;
  for (int i = 0; i < 72; i++) {
    crc = aac_crc16_bits(crc, msg, i, 1);
  }
  CHECK(crc == 0xAEE7, "bitwise feeding");
  const uint8_t zeros[4] = {0, 0, 0, 0};
  CHECK(aac_crc16_zero_bits(0x1234, 29) == aac_crc16_bits(0x1234, zeros, 0, 29),
        "zero padding");

  /* The ADTS rule: a 192-bit region of a short element is padded with
   * zeros, a PCE is covered whole, a fill element not at all. */
  uint8_t block[64];
  for (int i = 0; i < 64; i++) {
    block[i] = (uint8_t)(i * 37 + 11);
  }
  const uint8_t header[7] = {0xFF, 0xF1, 0x50, 0x80, 0x02, 0x1F, 0xFC};
  AacCrcRegion sce = {AAC_ELEM_SCE, 3, -1, 100};
  uint16_t expect = aac_crc16_bits(AAC_CRC16_INIT, header, 0, 56);
  expect = aac_crc16_bits(expect, block, 3, 97);
  expect = aac_crc16_zero_bits(expect, 95);
  CHECK(aac_adts_crc(header, 56, block, &sce, 1) == expect, "short SCE padded");
  AacCrcRegion cpe = {AAC_ELEM_CPE, 3, 150, 400};
  expect = aac_crc16_bits(AAC_CRC16_INIT, header, 0, 56);
  expect = aac_crc16_bits(expect, block, 3, 192);
  expect = aac_crc16_bits(expect, block, 150, 128);
  CHECK(aac_adts_crc(header, 56, block, &cpe, 1) == expect, "CPE regions");
  AacCrcRegion mixed[3] = {{AAC_ELEM_PCE, 3, -1, 60}, {AAC_ELEM_FIL, 63, -1, 200},
                           {AAC_ELEM_LFE, 203, -1, 230}};
  expect = aac_crc16_bits(AAC_CRC16_INIT, header, 0, 56);
  expect = aac_crc16_bits(expect, block, 3, 57);
  expect = aac_crc16_bits(expect, block, 203, 27);
  expect = aac_crc16_zero_bits(expect, 165);
  CHECK(aac_adts_crc(header, 56, block, mixed, 3) == expect, "PCE whole, FIL skipped");
}

static void test_encoder_crc() {
  const int sr = 44100;
  const std::vector<float> pcm = gen_tones(sr, sr, 2);
  const std::vector<uint8_t> plain = encode_transport(pcm, sr, 2, 96000, AAC_AOT_LC,
                                                      AAC_TRANSPORT_ADTS, AAC_SIGNAL_IMPLICIT, 0,
                                                      nullptr, nullptr);
  const std::vector<uint8_t> crc = encode_transport(pcm, sr, 2, 96000, AAC_AOT_LC,
                                                    AAC_TRANSPORT_ADTS, AAC_SIGNAL_IMPLICIT, 1,
                                                    nullptr, nullptr);
  const std::vector<AdtsFrame> fp = split_adts(plain);
  const std::vector<AdtsFrame> fc = split_adts(crc);
  CHECK(fp.size() == fc.size() && fp.size() > 10, "%zu vs %zu frames", fp.size(), fc.size());
  CHECK(crc.size() == plain.size() + 2 * fp.size(), "two CRC bytes per frame");
  for (size_t i = 0; i < fc.size(); i++) {
    AacAdtsHeader h;
    CHECK(aac_adts_parse(&h, crc.data() + fc[i].offset, (int)fc[i].size) == 0 &&
              h.protection_absent == 0,
          "frame %zu protected", i);
  }
  int e1 = 0;
  int e2 = 0;
  const std::vector<float> a = decode_stream(plain, sr, 2, &e1);
  const std::vector<float> b = decode_stream(crc, sr, 2, &e2);
  CHECK(e1 == 0 && e2 == 0, "errors %d %d", e1, e2);
  CHECK(same_samples(a, b), "the CRC stream decodes as the plain one");

  /* A wrong CRC, or a change under its coverage, is refused with
   * concealment off; the frames around it still decode. */
  AacDecoderHandle dec = aac_decoder_create(sr, 2);
  aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
  std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 2);
  std::vector<uint8_t> bad = crc;
  const AdtsFrame& f = fc[5];
  bad[f.offset + 8] ^= 0x10;
  CHECK(aac_decoder_decode(dec, bad.data() + fc[4].offset, (int)fc[4].size, out.data(),
                           (int)out.size()) == 1024,
        "frame before");
  CHECK(aac_decoder_decode(dec, bad.data() + f.offset, (int)f.size, out.data(),
                           (int)out.size()) == AAC_ERR_DECODE,
        "wrong CRC refused");
  bad = crc;
  bad[f.offset + 10] ^= 0x04; /* inside the first element's covered bits */
  const int got =
      aac_decoder_decode(dec, bad.data() + f.offset, (int)f.size, out.data(), (int)out.size());
  CHECK(got < 0, "covered change detected: %d", got);
  CHECK(aac_decoder_decode(dec, crc.data() + fc[6].offset, (int)fc[6].size, out.data(),
                           (int)out.size()) == 1024,
        "frame after");
  aac_decoder_destroy(dec);
}

/* ── streaming ADTS ────────────────────────────────────────────── */

static void test_adts_chunks() {
  const int sr = 48000;
  const std::vector<float> pcm = gen_transients(sr * 2, sr, 2);
  const std::vector<uint8_t> stream = encode_transport(pcm, sr, 2, 128000, AAC_AOT_LC,
                                                       AAC_TRANSPORT_ADTS, AAC_SIGNAL_IMPLICIT, 1,
                                                       nullptr, nullptr);
  int errors = 0;
  const std::vector<float> ref = decode_stream(stream, sr, 2, &errors);
  CHECK(errors == 0, "one-shot errors %d", errors);
  const size_t chunks[] = {1, 7, 100, 4096, stream.size()};
  for (size_t chunk : chunks) {
    AacDecoderHandle dec = aac_decoder_create(sr, 2);
    int frames = 0;
    const std::vector<float> out = decode_chunked(dec, stream, chunk, &frames, &errors);
    CHECK(errors == 0, "chunk %zu: %d errors", chunk, errors);
    CHECK((size_t)frames == split_adts(stream).size(), "chunk %zu: %d frames", chunk, frames);
    CHECK(same_samples(ref, out), "chunk %zu: streaming decode differs from one-shot", chunk);
    CHECK(aac_decoder_pending_bytes(dec) == 0, "chunk %zu: %d bytes left", chunk,
          aac_decoder_pending_bytes(dec));
    aac_decoder_destroy(dec);
  }
  /* The FIFO holds 64 KiB plus one frame; a feed beyond that is cut. */
  AacDecoderHandle dec = aac_decoder_create(sr, 2);
  std::vector<uint8_t> big(200000, 0);
  const int taken = aac_decoder_feed(dec, big.data(), (int)big.size());
  CHECK(taken > 65536 && taken < 80000, "FIFO cap: %d", taken);
  CHECK(aac_decoder_feed(dec, big.data(), 10) == 0, "full FIFO takes nothing");
  aac_decoder_destroy(dec);
}

static void test_adts_resync() {
  const int sr = 44100;
  const std::vector<float> pcm = gen_harmonics(sr * 2, sr, 2);
  const std::vector<uint8_t> stream = encode_transport(pcm, sr, 2, 128000, AAC_AOT_LC,
                                                       AAC_TRANSPORT_ADTS, AAC_SIGNAL_IMPLICIT, 0,
                                                       nullptr, nullptr);
  const std::vector<AdtsFrame> frames = split_adts(stream);
  CHECK(frames.size() > 12, "%zu frames", frames.size());
  /* The reference: each frame decoded on its own, in order. */
  std::vector<std::vector<float>> ref;
  {
    AacDecoderHandle dec = aac_decoder_create(sr, 2);
    std::vector<float> pcm_out((size_t)AAC_DECODER_MAX_OUTPUT * 2);
    for (const AdtsFrame& f : frames) {
      const int n = aac_decoder_decode(dec, stream.data() + f.offset, (int)f.size, pcm_out.data(),
                                       (int)pcm_out.size());
      CHECK(n == 1024, "reference frame");
      ref.emplace_back(pcm_out.begin(), pcm_out.begin() + 2048);
    }
    aac_decoder_destroy(dec);
  }
  /* Damage: garbage with a fake header (another sample rate, whose frame
   * would end exactly at the next real header) after frame 1, frame 5
   * truncated to half, frame 8 preceded by random bytes. */
  std::vector<uint8_t> damaged;
  auto append = [&](size_t i) {
    damaged.insert(damaged.end(), stream.begin() + (long)frames[i].offset,
                   stream.begin() + (long)(frames[i].offset + frames[i].size));
  };
  unsigned seed = 5;
  auto garbage = [&](size_t n) {
    for (size_t i = 0; i < n; i++) {
      seed = seed * 1664525u + 1013904223u;
      damaged.push_back((uint8_t)(seed >> 24));
    }
  };
  for (size_t i = 0; i < frames.size(); i++) {
    if (i == 2) {
      uint8_t fake[AAC_ADTS_HEADER_SIZE];
      AacAdtsHeader h = {};
      h.protection_absent = 1;
      h.object_type = AAC_AOT_LC;
      h.sample_rate_index = 7;
      h.channel_config = 1;
      h.frame_length = 20;
      h.buffer_fullness = 0x7FF;
      h.num_raw_blocks = 1;
      aac_adts_write(&h, fake);
      damaged.insert(damaged.end(), fake, fake + AAC_ADTS_HEADER_SIZE);
      garbage(13);
    }
    if (i == 5) {
      damaged.insert(damaged.end(), stream.begin() + (long)frames[i].offset,
                     stream.begin() + (long)(frames[i].offset + frames[i].size / 2));
      continue;
    }
    if (i == 8) {
      garbage(31);
    }
    append(i);
  }
  const size_t chunks[] = {1, 50, damaged.size()};
  for (size_t chunk : chunks) {
    AacDecoderHandle dec = aac_decoder_create(sr, 2);
    aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
    int got = 0;
    int errors = 0;
    const std::vector<float> out = decode_chunked(dec, damaged, chunk, &got, &errors);
    /* Fed byte by byte, the truncated frame is complete by its own account
     * before its successor can contradict it, so only its decode reveals
     * the damage (one error with concealment off). */
    CHECK(errors <= (chunk == 1 ? 1 : 0), "chunk %zu: %d errors", chunk, errors);
    CHECK((size_t)got == frames.size() - 1, "chunk %zu: %d of %zu frames", chunk, got,
          frames.size());
    if ((size_t)got != frames.size() - 1) {
      aac_decoder_destroy(dec);
      continue;
    }
    /* Frame 5 is lost; the frame after it carries a stale overlap, every
     * other one is exact. */
    for (size_t i = 0, o = 0; i < frames.size(); i++) {
      if (i == 5) {
        continue;
      }
      const bool exact = memcmp(ref[i].data(), out.data() + o * 2048, 2048 * sizeof(float)) == 0;
      if (i == 6) {
        CHECK(!exact, "chunk %zu: frame after the loss cannot be exact", chunk);
      } else {
        CHECK(exact, "chunk %zu: frame %zu differs", chunk, i);
      }
      o++;
    }
    aac_decoder_destroy(dec);
  }
}

/* ── AudioSpecificConfig ───────────────────────────────────────── */

static void check_asc_roundtrip(const AacAsc& asc, const char* name) {
  uint8_t buf[AAC_ASC_MAX_BYTES];
  const int len = aac_asc_write(&asc, buf, sizeof(buf));
  CHECK(len > 0, "%s: write %d", name, len);
  AacAsc back;
  const int ret = aac_asc_parse(buf, len, &back);
  CHECK(ret == 0, "%s: parse %d", name, ret);
  CHECK(back.aot == asc.aot && back.sample_rate == asc.sample_rate &&
            back.channel_config == asc.channel_config && back.frame_length == asc.frame_length,
        "%s: core fields", name);
  const bool sbr = asc.sbr_present == 1;
  /* Without an extension to signal, the config is the plain one. */
  const AacSignaling expect =
      sbr || (asc.signaling == AAC_SIGNAL_EXPLICIT_BC && asc.sbr_present >= 0)
          ? asc.signaling
          : AAC_SIGNAL_IMPLICIT;
  CHECK(back.signaling == expect, "%s: signalling %d vs %d", name, back.signaling, expect);
  if (asc.signaling == AAC_SIGNAL_IMPLICIT) {
    CHECK(back.sbr_present == -1 && back.ps_present == -1 && back.ext_sample_rate == 0,
          "%s: nothing signalled", name);
  } else if (asc.signaling == AAC_SIGNAL_EXPLICIT_BC && asc.sbr_present >= 0) {
    CHECK(back.sbr_present == asc.sbr_present, "%s: sbr flag", name);
    if (sbr) {
      CHECK(back.ext_sample_rate == asc.ext_sample_rate, "%s: extension rate", name);
      CHECK(back.ps_present == asc.ps_present, "%s: ps flag %d vs %d", name, back.ps_present,
            asc.ps_present);
    }
  } else if (sbr) {
    CHECK(back.sbr_present == 1 && back.ext_sample_rate == asc.ext_sample_rate, "%s: sbr", name);
    CHECK(back.ps_present == (asc.ps_present == 1 ? 1 : -1), "%s: ps", name);
  }
  if (asc.channel_config == 0) {
    CHECK(back.has_pce && back.layout.num_elements == asc.layout.num_elements &&
              back.layout.num_channels == asc.layout.num_channels,
          "%s: PCE layout", name);
    for (int e = 0; e < asc.layout.num_elements; e++) {
      CHECK(back.layout.elems[e].type == asc.layout.elems[e].type &&
                back.layout.elems[e].tag == asc.layout.elems[e].tag &&
                back.layout.elems[e].position == asc.layout.elems[e].position,
            "%s: PCE element %d", name, e);
    }
  }
  /* The same config written at an odd bit offset (as inside a
   * StreamMuxConfig) reads back the same, PCE alignment included. */
  uint8_t shifted[AAC_ASC_MAX_BYTES + 2];
  AacBitWriter w;
  aac_bitwriter_init(&w, shifted, sizeof(shifted));
  aac_bitwriter_write(&w, 5, 3);
  const int bits = aac_asc_write_bits(&asc, &w);
  CHECK(bits > 0, "%s: bit write", name);
  aac_bitwriter_write(&w, 0, 3); /* frameLengthType, as a mux config would */
  AacBitReader r;
  aac_bitreader_init(&r, shifted, aac_bitwriter_bytes_written(&w));
  aac_bitreader_skip(&r, 3);
  AacAsc again;
  CHECK(aac_asc_read(&r, &again) == 0, "%s: unaligned parse", name);
  CHECK(r.pos == 3 + bits, "%s: unaligned parse consumed %d of %d bits", name, r.pos - 3, bits);
  CHECK(again.sample_rate == back.sample_rate && again.channel_config == back.channel_config &&
            again.sbr_present == back.sbr_present && again.ps_present == back.ps_present &&
            again.frame_length == back.frame_length,
        "%s: unaligned fields", name);
}

static void test_asc() {
  /* Known configs: AAC-LC 44.1 kHz stereo; AAC-LC 22.05 kHz stereo with
   * SBR to 44.1 kHz signalled backward compatibly, PS absent; HE-AAC v2
   * 24 kHz mono to 48 kHz signalled hierarchically. */
  const uint8_t lc[] = {0x12, 0x10};
  AacAsc a;
  CHECK(aac_asc_parse(lc, 2, &a) == 0 && a.aot == 2 && a.sample_rate == 44100 &&
            a.channel_config == 2 && a.frame_length == 1024 && a.sbr_present == -1 &&
            a.signaling == AAC_SIGNAL_IMPLICIT,
        "AAC-LC config");
  const uint8_t he[] = {0x13, 0x90, 0x56, 0xE5, 0xA5, 0x48, 0x00};
  CHECK(aac_asc_parse(he, 7, &a) == 0 && a.aot == 2 && a.sample_rate == 22050 &&
            a.channel_config == 2 && a.signaling == AAC_SIGNAL_EXPLICIT_BC &&
            a.sbr_present == 1 && a.ext_sample_rate == 44100 && a.ps_present == 0,
        "HE-AAC v1 backward compatible config");
  const uint8_t hev2[] = {0xEB, 0x09, 0x88, 0x00};
  CHECK(aac_asc_parse(hev2, 4, &a) == 0 && a.aot == 2 && a.sample_rate == 24000 &&
            a.channel_config == 1 && a.signaling == AAC_SIGNAL_EXPLICIT_HIER &&
            a.sbr_present == 1 && a.ps_present == 1 && a.ext_sample_rate == 48000,
        "HE-AAC v2 hierarchical config");
  /* The two-byte core config followed by padding is not misread. */
  const uint8_t padded[] = {0x12, 0x10, 0x00, 0x00};
  CHECK(aac_asc_parse(padded, 4, &a) == 0 && a.sbr_present == -1, "padding is not an extension");
  /* An explicit sampling frequency. */
  uint8_t odd[8];
  AacBitWriter w;
  aac_bitwriter_init(&w, odd, sizeof(odd));
  aac_bitwriter_write(&w, 2, 5);
  aac_bitwriter_write(&w, 15, 4);
  aac_bitwriter_write(&w, 44100, 24);
  aac_bitwriter_write(&w, 1, 4);
  aac_bitwriter_write(&w, 0, 3);
  CHECK(aac_asc_parse(odd, aac_bitwriter_bytes_written(&w), &a) == 0 && a.sample_rate == 44100 &&
            a.channel_config == 1,
        "explicit sampling frequency");
  /* Object types outside General Audio are refused, as are truncated
   * configs. */
  const uint8_t celp[] = {0x40, 0x10};
  CHECK(aac_asc_parse(celp, 2, &a) == AAC_ERR_UNSUPPORTED, "CELP config refused");
  CHECK(aac_asc_parse(lc, 1, &a) == AAC_ERR_DECODE, "truncated config refused");

  /* Roundtrips over every signalling mode, extension, frame length and
   * layout, including a PCE-defined one. */
  const AacSignaling modes[] = {AAC_SIGNAL_IMPLICIT, AAC_SIGNAL_EXPLICIT_BC,
                                AAC_SIGNAL_EXPLICIT_HIER};
  const int configs[] = {1, 2, 6, 7, 0};
  AacLayout pce_layout = {};
  CHECK(aac_layout_from_config(&pce_layout, 11) == 0, "6.1 layout");
  for (AacSignaling mode : modes) {
    for (int config : configs) {
      for (int frame_length = 1024; frame_length >= 960; frame_length -= 64) {
        for (int ext = 0; ext < 3; ext++) {
          AacAsc asc;
          aac_asc_init(&asc, ext ? 24000 : 48000, config);
          if (config == 0) {
            asc.has_pce = 1;
            asc.layout = pce_layout;
          }
          asc.frame_length = frame_length;
          asc.signaling = mode;
          if (ext) {
            asc.sbr_present = 1;
            asc.ps_present = ext == 2 ? 1 : -1;
            asc.ext_sample_rate = 48000;
          }
          char name[96];
          snprintf(name, sizeof(name), "mode %d config %d frame %d ext %d", mode, config,
                   frame_length, ext);
          check_asc_roundtrip(asc, name);
        }
      }
    }
  }
  /* Backward compatible signalling can also say that SBR is absent. */
  AacAsc none;
  aac_asc_init(&none, 48000, 2);
  none.signaling = AAC_SIGNAL_EXPLICIT_BC;
  none.sbr_present = 0;
  check_asc_roundtrip(none, "explicitly no SBR");
}

/* The encoder's config and the decoder's application of it. */
static void test_encoder_config() {
  struct Case {
    AacObjectType aot;
    int sr, channels;
    AacSignaling mode;
    int expect_rate, expect_channels, expect_sbr, expect_ps;
  } cases[] = {
      {AAC_AOT_LC, 44100, 2, AAC_SIGNAL_IMPLICIT, 44100, 2, -1, -1},
      {AAC_AOT_LC, 48000, 7, AAC_SIGNAL_EXPLICIT_HIER, 48000, 7, -1, -1},
      {AAC_AOT_SBR, 44100, 1, AAC_SIGNAL_IMPLICIT, 22050, 1, -1, -1},
      {AAC_AOT_SBR, 48000, 2, AAC_SIGNAL_EXPLICIT_BC, 24000, 2, 1, -1},
      {AAC_AOT_SBR, 48000, 2, AAC_SIGNAL_EXPLICIT_HIER, 24000, 2, 1, -1},
      {AAC_AOT_PS, 44100, 2, AAC_SIGNAL_EXPLICIT_BC, 22050, 1, 1, 1},
      {AAC_AOT_PS, 44100, 2, AAC_SIGNAL_EXPLICIT_HIER, 22050, 1, 1, 1},
  };
  for (const Case& c : cases) {
    AacEncoderHandle enc = aac_encoder_create(c.sr, c.channels, 64000, c.aot, AAC_RC_CBR);
    CHECK(enc != nullptr, "encoder");
    CHECK(aac_encoder_set_signaling(enc, c.mode) == AAC_OK, "signaling");
    uint8_t buf[AAC_ASC_MAX_BYTES];
    const int len = aac_encoder_config(enc, buf, sizeof(buf));
    CHECK(len > 0, "config length %d", len);
    AacAsc asc;
    CHECK(aac_asc_parse(buf, len, &asc) == 0, "config parses");
    CHECK(asc.sample_rate == c.expect_rate && asc.sbr_present == c.expect_sbr &&
              asc.ps_present == c.expect_ps,
          "aot %d mode %d: rate %d sbr %d ps %d", c.aot, c.mode, asc.sample_rate,
          asc.sbr_present, asc.ps_present);
    const int channels = asc.channel_config ? asc.channel_config : asc.layout.num_channels;
    CHECK(channels == c.expect_channels, "aot %d: %d channels", c.aot, channels);
    CHECK(c.expect_channels != 7 || (asc.channel_config == 0 && asc.has_pce), "6.1 as a PCE");
    CHECK(aac_encoder_config(enc, buf, 1) == AAC_ERR_OVERFLOW, "short buffer");
    aac_encoder_destroy(enc);

    /* The decoder presets itself from the config. */
    AacDecoderHandle dec = aac_decoder_create(48000, 0);
    CHECK(aac_decoder_set_config(dec, buf, len) == AAC_OK, "decoder config");
    CHECK(aac_decoder_sample_rate(dec) == c.expect_rate, "decoder rate %d",
          aac_decoder_sample_rate(dec));
    const bool explicit_sbr = c.expect_sbr == 1;
    CHECK(aac_decoder_output_sample_rate(dec) == (explicit_sbr ? 2 * c.expect_rate : c.expect_rate),
          "decoder output rate %d", aac_decoder_output_sample_rate(dec));
    CHECK(aac_decoder_frame_size(dec) == (explicit_sbr ? 2048 : 1024), "decoder frame size");
    CHECK(aac_decoder_channels(dec) == (c.expect_ps == 1 ? 2 : c.expect_channels),
          "decoder channels %d", aac_decoder_channels(dec));
    /* A reset keeps the configured modes. */
    aac_decoder_reset(dec);
    CHECK(aac_decoder_frame_size(dec) == (explicit_sbr ? 2048 : 1024), "frame size after reset");
    aac_decoder_destroy(dec);
  }
  /* The 960-sample frame length follows the configuration. */
  AacAsc short_frames;
  aac_asc_init(&short_frames, 48000, 2);
  short_frames.frame_length = 960;
  uint8_t buf[8];
  const int len = aac_asc_write(&short_frames, buf, sizeof(buf));
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  CHECK(aac_decoder_set_config(dec, buf, len) == AAC_OK, "960 frames configured");
  CHECK(aac_decoder_frame_size(dec) == 960, "frame size 960 after the config");
  const uint8_t celp[] = {0x40, 0x10};
  CHECK(aac_decoder_set_config(dec, celp, 2) == AAC_ERR_UNSUPPORTED, "CELP refused");
  aac_decoder_destroy(dec);
}

/* ── LATM / LOAS ───────────────────────────────────────────────── */

static void test_stream_mux_config() {
  AacAsc asc;
  aac_asc_init(&asc, 48000, 2);
  for (int version = 0; version < 2; version++) {
    AacLatmConfig cfg;
    aac_latm_config_init(&cfg, &asc, version);
    cfg.other_data_present = 1;
    cfg.other_data_len_bits = version ? 300 : 20;
    cfg.crc_check_present = 1;
    cfg.crc_check_sum = 0x5A;
    cfg.latm_buffer_fullness = 0x80;
    cfg.tara_buffer_fullness = 0x123;
    uint8_t buf[128];
    AacBitWriter w;
    aac_bitwriter_init(&w, buf, sizeof(buf));
    aac_bitwriter_write(&w, 1, 1);
    CHECK(aac_latm_write_stream_mux_config(&w, &cfg) == 0, "version %d: write", version);
    const int bits = aac_bitwriter_bits_written(&w);
    aac_bitwriter_write(&w, 0xAB, 8);
    AacBitReader r;
    aac_bitreader_init(&r, buf, aac_bitwriter_bytes_written(&w));
    aac_bitreader_skip(&r, 1);
    AacLatmConfig back;
    CHECK(aac_latm_read_stream_mux_config(&r, &back) == 0, "version %d: read", version);
    CHECK(r.pos == bits, "version %d: consumed %d of %d bits", version, r.pos, bits);
    CHECK(aac_bitreader_read(&r, 8) == 0xAB, "version %d: next field intact", version);
    CHECK(back.valid && back.audio_mux_version == version && back.num_sub_frames == 0 &&
              back.frame_length_type == 0 && back.latm_buffer_fullness == 0x80 &&
              back.other_data_present && back.other_data_len_bits == cfg.other_data_len_bits &&
              back.crc_check_present && back.crc_check_sum == 0x5A &&
              (version == 0 || back.tara_buffer_fullness == 0x123),
          "version %d: fields", version);
    CHECK(back.asc.sample_rate == 48000 && back.asc.channel_config == 2, "version %d: asc",
          version);
  }
  /* An element with the payload, with and without the in-band config,
   * at any payload size (the 255-byte length chunks). */
  AacLatmConfig cfg;
  aac_latm_config_init(&cfg, &asc, 0);
  const int sizes[] = {1, 254, 255, 256, 510, 511, 777};
  for (int size : sizes) {
    std::vector<uint8_t> au((size_t)size);
    for (int i = 0; i < size; i++) {
      au[(size_t)i] = (uint8_t)(i * 7 + 3);
    }
    for (int mcp = 0; mcp < 2; mcp++) {
      std::vector<uint8_t> elem((size_t)size + 64);
      const int len =
          aac_latm_write_mux_element(&cfg, mcp, 0, au.data(), size, elem.data(), (int)elem.size());
      CHECK(len > size, "size %d mcp %d: element %d", size, mcp, len);
      AacLatmConfig got = {};
      if (!mcp) {
        got = cfg;
      }
      std::vector<uint8_t> out((size_t)len);
      AacLatmPayload payloads[2];
      int used = 0;
      const int n = aac_latm_read_mux_element(elem.data(), len, mcp, &got, out.data(), len,
                                              payloads, 2, &used);
      CHECK(n == 1 && used == len && payloads[0].size == size &&
                memcmp(out.data() + payloads[0].offset, au.data(), (size_t)size) == 0,
            "size %d mcp %d: payload back (%d, %d bytes used)", size, mcp, n, used);
    }
  }
  /* Without a config the element cannot be read. */
  std::vector<uint8_t> elem(64);
  const int len = aac_latm_write_mux_element(&cfg, 1, 1, elem.data(), 8, elem.data(), 64);
  AacLatmConfig none = {};
  AacLatmPayload p[1];
  std::vector<uint8_t> out(64);
  CHECK(aac_latm_read_mux_element(elem.data(), len, 1, &none, out.data(), 64, p, 1, nullptr) ==
            AAC_ERR_STATE,
        "useSameStreamMux without a config");
}

static std::vector<std::vector<uint8_t>> split_loas(const std::vector<uint8_t>& s) {
  std::vector<std::vector<uint8_t>> frames;
  size_t pos = 0;
  while (pos + 3 <= s.size()) {
    if (s[pos] != 0x56 || (s[pos + 1] & 0xE0) != 0xE0) {
      break;
    }
    const size_t len = 3 + (((s[pos + 1] & 0x1F) << 8) | s[pos + 2]);
    if (pos + len > s.size()) {
      break;
    }
    frames.emplace_back(s.begin() + (long)pos, s.begin() + (long)(pos + len));
    pos += len;
  }
  return frames;
}

static void test_latm_roundtrip() {
  struct Case {
    const char* name;
    AacObjectType aot;
    int sr, channels, bitrate;
    AacSignaling mode;
  } cases[] = {
      {"lc 48k stereo", AAC_AOT_LC, 48000, 2, 128000, AAC_SIGNAL_IMPLICIT},
      {"lc 44.1k 6.1 pce", AAC_AOT_LC, 44100, 7, 448000, AAC_SIGNAL_IMPLICIT},
      {"he 48k stereo hier", AAC_AOT_SBR, 48000, 2, 64000, AAC_SIGNAL_EXPLICIT_HIER},
      {"he 44.1k mono bc", AAC_AOT_SBR, 44100, 1, 32000, AAC_SIGNAL_EXPLICIT_BC},
      {"hev2 44.1k hier", AAC_AOT_PS, 44100, 2, 32000, AAC_SIGNAL_EXPLICIT_HIER},
      {"he 48k 5.1 implicit", AAC_AOT_SBR, 48000, 6, 192000, AAC_SIGNAL_IMPLICIT},
  };
  for (const Case& c : cases) {
    const std::vector<float> pcm = c.aot == AAC_AOT_LC ? gen_multichannel(c.sr, c.sr, c.channels)
                                                       : gen_wideband(c.sr, c.sr, c.channels);
    int errors = 0;
    const std::vector<uint8_t> adts = encode_transport(pcm, c.sr, c.channels, c.bitrate, c.aot,
                                                       AAC_TRANSPORT_ADTS, c.mode, 0, nullptr,
                                                       nullptr);
    const std::vector<float> ref = decode_stream(adts, c.sr, 0, &errors);
    CHECK(errors == 0, "%s: adts errors %d", c.name, errors);
    const size_t n_frames = split_adts(adts).size();

    /* LOAS: as a byte stream in odd chunks, and frame by frame. */
    std::vector<std::vector<uint8_t>> loas_frames;
    const std::vector<uint8_t> loas = encode_transport(pcm, c.sr, c.channels, c.bitrate, c.aot,
                                                       AAC_TRANSPORT_LOAS, c.mode, 0,
                                                       &loas_frames, nullptr);
    CHECK(split_loas(loas).size() == n_frames, "%s: %zu LOAS frames for %zu", c.name,
          split_loas(loas).size(), n_frames);
    AacDecoderHandle dec = aac_decoder_create(48000, 0);
    int frames = 0;
    std::vector<float> out = decode_chunked(dec, loas, 333, &frames, &errors);
    CHECK(errors == 0 && (size_t)frames == n_frames, "%s: LOAS stream %d frames %d errors",
          c.name, frames, errors);
    CHECK(same_samples(ref, out), "%s: LOAS stream decode differs from ADTS", c.name);
    CHECK(aac_decoder_output_sample_rate(dec) == c.sr && aac_decoder_channels(dec) == c.channels,
          "%s: LOAS stream parameters %d Hz %d ch", c.name, aac_decoder_output_sample_rate(dec),
          aac_decoder_channels(dec));
    aac_decoder_destroy(dec);
    dec = aac_decoder_create(48000, 0);
    out.clear();
    std::vector<float> buf((size_t)AAC_DECODER_MAX_OUTPUT * 8);
    for (const std::vector<uint8_t>& f : loas_frames) {
      const int got = aac_decoder_decode(dec, f.data(), (int)f.size(), buf.data(), (int)buf.size());
      CHECK(got > 0, "%s: LOAS one-shot %d", c.name, got);
      if (got > 0) {
        out.insert(out.end(), buf.begin(), buf.begin() + (size_t)got * aac_decoder_channels(dec));
      }
    }
    CHECK(same_samples(ref, out), "%s: LOAS one-shot decode differs", c.name);
    aac_decoder_destroy(dec);

    /* LATM with in-band config, as packets, detected and explicit. */
    std::vector<std::vector<uint8_t>> packets;
    encode_transport(pcm, c.sr, c.channels, c.bitrate, c.aot, AAC_TRANSPORT_LATM_MCP1, c.mode, 0,
                     &packets, nullptr);
    /* The flushed frames came out joined; drop them from the packet list
     * and from the reference for the packet comparisons. */
    const size_t main_frames = n_frames - (c.aot == AAC_AOT_LC ? 2 : 3);
    packets.resize(main_frames);
    const size_t frame_samples = (size_t)(c.aot == AAC_AOT_LC ? 1024 : 2048) * c.channels;
    const std::vector<float> ref_main(ref.begin(), ref.begin() + (long)(main_frames * frame_samples));
    for (int explicit_transport = 0; explicit_transport < 2; explicit_transport++) {
      dec = aac_decoder_create(48000, 0);
      if (explicit_transport) {
        aac_decoder_set_transport(dec, AAC_TRANSPORT_LATM_MCP1);
      }
      out = decode_packets(dec, packets, &errors);
      CHECK(errors == 0, "%s: LATM packets (%s) %d errors", c.name,
            explicit_transport ? "set" : "detected", errors);
      CHECK(same_samples(ref_main, out), "%s: LATM packets (%s) differ", c.name,
            explicit_transport ? "set" : "detected");
      aac_decoder_destroy(dec);
    }

    /* LATM without in-band config needs the config out of band; raw
     * access units likewise. */
    for (int raw = 0; raw < 2; raw++) {
      std::vector<uint8_t> config;
      packets.clear();
      encode_transport(pcm, c.sr, c.channels, c.bitrate, c.aot,
                       raw ? AAC_TRANSPORT_RAW : AAC_TRANSPORT_LATM_MCP0, c.mode, 0, &packets,
                       &config);
      packets.resize(main_frames);
      dec = aac_decoder_create(48000, 0);
      if (!raw) {
        aac_decoder_set_transport(dec, AAC_TRANSPORT_LATM_MCP0);
      }
      CHECK(aac_decoder_set_config(dec, config.data(), (int)config.size()) == AAC_OK,
            "%s: config", c.name);
      out = decode_packets(dec, packets, &errors);
      CHECK(errors == 0, "%s: %s packets %d errors", c.name, raw ? "raw" : "LATM MCP0", errors);
      CHECK(same_samples(ref_main, out), "%s: %s packets differ", c.name,
            raw ? "raw" : "LATM MCP0");
      aac_decoder_destroy(dec);
    }
  }
}

/* With explicit signalling the decoder runs at the SBR rate from the
 * first frame; the one-shot call accepts a LATM element when told. */
static void test_explicit_signalling() {
  const int sr = 48000;
  const std::vector<float> pcm = gen_wideband(sr, sr, 2);
  std::vector<std::vector<uint8_t>> packets;
  std::vector<uint8_t> config;
  encode_transport(pcm, sr, 2, 64000, AAC_AOT_SBR, AAC_TRANSPORT_LATM_MCP1,
                   AAC_SIGNAL_EXPLICIT_HIER, 0, &packets, &config);
  AacDecoderHandle dec = aac_decoder_create(24000, 0);
  aac_decoder_set_transport(dec, AAC_TRANSPORT_LATM_MCP1);
  std::vector<float> buf((size_t)AAC_DECODER_MAX_OUTPUT * 2);
  const int got =
      aac_decoder_decode(dec, packets[0].data(), (int)packets[0].size(), buf.data(), (int)buf.size());
  CHECK(got == 2048, "first LATM element: %d samples", got);
  CHECK(aac_decoder_output_sample_rate(dec) == 48000 && aac_decoder_sample_rate(dec) == 24000,
        "rates %d/%d", aac_decoder_output_sample_rate(dec), aac_decoder_sample_rate(dec));
  aac_decoder_destroy(dec);

  /* Raw access units with the config: the first unit already comes out
   * at the SBR rate and, for HE-AAC v2, in stereo. */
  packets.clear();
  encode_transport(gen_wideband(44100, 44100, 2), 44100, 2, 32000, AAC_AOT_PS, AAC_TRANSPORT_RAW,
                   AAC_SIGNAL_EXPLICIT_BC, 0, &packets, &config);
  dec = aac_decoder_create(22050, 0);
  CHECK(aac_decoder_set_config(dec, config.data(), (int)config.size()) == AAC_OK, "PS config");
  CHECK(aac_decoder_channels(dec) == 2 && aac_decoder_frame_size(dec) == 2048,
        "PS config presets stereo at the SBR rate");
  const int n =
      aac_decoder_decode(dec, packets[0].data(), (int)packets[0].size(), buf.data(), (int)buf.size());
  CHECK(n == 2048 && aac_decoder_channels(dec) == 2, "raw HE-AAC v2 unit: %d", n);
  aac_decoder_destroy(dec);
}

int main() {
  test_crc_vectors();
  test_encoder_crc();
  test_adts_chunks();
  test_adts_resync();
  test_asc();
  test_encoder_config();
  test_stream_mux_config();
  test_latm_roundtrip();
  test_explicit_signalling();
  return test_summary("test_transport");
}
