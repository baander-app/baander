/* Encoder tuning controls and encoder correctness: the peak bitrate caps
 * every frame, the bandwidth override sets the decoded lowpass, the VBR
 * modes order their bitrates and follow a peak bitrate, a 5.1 HE-AAC
 * frame carries each element's SBR data right after that element and
 * FFmpeg decodes it channel for channel, HE-AAC v2 drains a short
 * last block and a single-sample input correctly, NaN, infinite and huge
 * input samples are coded as their documented replacements, and the
 * committed AAC-LC, HE-AAC and HE-AAC v2 streams.
 *
 * With arguments the program writes the committed streams instead:
 *   test_encoder_tuning --write-streams <dir>
 */
#include <cfloat>

#include "bitstream.h"
#include "encoder.h"
#include "test_util.h"

namespace {

struct Encoded {
  std::vector<uint8_t> stream;
  std::vector<int> sizes; /* of every frame or unit, the flush's included */
};

/* Encodes a whole signal; `setup` applies the settings under test before
 * the first frame. */
template <typename Setup>
Encoded encode_with(const std::vector<float>& pcm, int sr, int channels, int bitrate,
                    AacObjectType aot, AacRateControl rc, Setup setup) {
  Encoded e;
  AacEncoderHandle enc = aac_encoder_create(sr, channels, bitrate, aot, rc);
  CHECK(enc != nullptr, "create %d Hz %d ch %d bit/s aot %d", sr, channels, bitrate, (int)aot);
  if (!enc) {
    return e;
  }
  setup(enc);
  const int frame = aac_encoder_frame_size(enc);
  std::vector<float> block((size_t)frame * channels);
  std::vector<uint8_t> buf(65536);
  for (size_t pos = 0; pos < pcm.size(); pos += block.size()) {
    std::fill(block.begin(), block.end(), 0.0f);
    memcpy(block.data(), pcm.data() + pos,
           std::min(block.size(), pcm.size() - pos) * sizeof(float));
    const int len = aac_encoder_encode(enc, block.data(), frame, buf.data(), (int)buf.size());
    CHECK(len > 0, "encode returned %d", len);
    if (len <= 0) {
      break;
    }
    e.stream.insert(e.stream.end(), buf.begin(), buf.begin() + len);
    e.sizes.push_back(len);
  }
  for (;;) {
    const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
    if (len <= 0) {
      CHECK(len == 0, "flush returned %d", len);
      break;
    }
    e.stream.insert(e.stream.end(), buf.begin(), buf.begin() + len);
    e.sizes.push_back(len);
  }
  /* an ADTS flush writes its frames back to back in one call */
  if (!e.stream.empty() && e.stream[0] == 0xFF) {
    e.sizes.clear();
    for (const AdtsFrame& f : split_adts(e.stream)) {
      e.sizes.push_back((int)f.size);
    }
  }
  aac_encoder_destroy(enc);
  return e;
}

double kbps(const std::vector<uint8_t>& stream, size_t samples, int sr) {
  return (double)stream.size() * 8.0 * sr / (double)samples / 1000.0;
}

/* Clicks: short full-scale noise bursts over silence, the hardest case
 * for a reservoir. */
std::vector<float> gen_clicks(int n, int sr, int channels) {
  std::vector<float> pcm((size_t)n * channels, 0.0f);
  unsigned seed = 31u;
  for (int start = sr / 20; start < n; start += sr / 4) {
    for (int i = 0; i < sr / 200 && start + i < n; i++) {
      for (int c = 0; c < channels; c++) {
        seed = seed * 1664525u + 1013904223u;
        pcm[(size_t)(start + i) * channels + c] = 0.9f * ((float)(seed >> 8) / 8388608.0f - 1.0f);
      }
    }
  }
  return pcm;
}

/* ── peak bitrate ──────────────────────────────────────────────── */

/* No unit exceeds the peak bitrate's share of a frame, in every profile
 * and rate control mode, on clicks and on noise. */
void test_peak_bitrate() {
  const int sr = 48000;
  const int n = sr * 2;
  struct Case {
    AacObjectType aot;
    AacRateControl rc;
    int bitrate, peak;
  };
  const Case cases[] = {
      {AAC_AOT_LC, AAC_RC_CBR, 64000, 80000},   {AAC_AOT_LC, AAC_RC_ABR, 64000, 96000},
      {AAC_AOT_LC, AAC_RC_CVBR, 64000, 72000},  {AAC_AOT_LC, AAC_RC_TVBR, 64000, 96000},
      {AAC_AOT_SBR, AAC_RC_CBR, 48000, 64000},  {AAC_AOT_SBR, AAC_RC_TVBR, 48000, 80000},
      {AAC_AOT_PS, AAC_RC_TVBR, 32000, 48000},  {AAC_AOT_USAC, AAC_RC_CBR, 64000, 80000},
      {AAC_AOT_USAC, AAC_RC_TVBR, 64000, 96000}, {AAC_AOT_ER_LD, AAC_RC_TVBR, 64000, 96000},
  };
  const std::vector<float> signals[2] = {gen_clicks(n, sr, 2), gen_noise(n, 2)};
  for (const Case& c : cases) {
    for (int k = 0; k < 2; k++) {
      int frame_length = 0;
      int core_rate = 0;
      const Encoded e = encode_with(signals[k], sr, 2, c.bitrate, c.aot, c.rc, [&](AacEncoderHandle h) {
        if (c.aot == AAC_AOT_USAC) {
          /* the pre-roll repeats the previous unit outside the budget */
          AacUsacEncoderOptions o;
          aac_usac_encoder_options_init(&o);
          o.preroll = 0;
          CHECK(aac_encoder_set_usac(h, &o) == AAC_OK, "usac options");
        }
        if (c.aot != AAC_AOT_LC && c.aot != AAC_AOT_SBR && c.aot != AAC_AOT_PS) {
          CHECK(aac_encoder_set_transport(h, AAC_TRANSPORT_RAW) == AAC_OK, "raw transport");
        }
        CHECK(aac_encoder_set_peak_bitrate(h, c.peak) == AAC_OK, "peak %d", c.peak);
        auto* s = static_cast<AacEncoderState*>(h);
        frame_length = s->frame_length;
        core_rate = s->sample_rate;
      });
      /* an ADTS frame or a raw unit is the budget itself */
      const int cap = (int)((long long)c.peak * frame_length / core_rate / 8);
      int largest = 0;
      for (int len : e.sizes) {
        largest = std::max(largest, len);
      }
      printf("peak: aot %d rc %d %s %d/%d kbit/s: %.1f kbit/s, largest unit %d of %d bytes\n",
             (int)c.aot, (int)c.rc, k ? "noise " : "clicks", c.bitrate / 1000, c.peak / 1000,
             kbps(e.stream, (size_t)n, sr), largest, cap);
      CHECK(largest <= cap, "aot %d rc %d: a unit of %d bytes exceeds the cap of %d",
            (int)c.aot, (int)c.rc, largest, cap);
    }
  }

  AacEncoderHandle enc = aac_encoder_create(sr, 2, 64000, AAC_AOT_LC, AAC_RC_CBR);
  CHECK(aac_encoder_set_peak_bitrate(enc, -1) == AAC_ERR_INVALID_ARG, "negative peak");
  CHECK(aac_encoder_set_peak_bitrate(enc, 48000) == AAC_ERR_INVALID_ARG,
        "CBR peak below the bitrate");
  CHECK(aac_encoder_set_peak_bitrate(enc, 96000) == AAC_OK, "peak above the bitrate");
  std::vector<float> block(2048, 0.0f);
  std::vector<uint8_t> buf(8192);
  aac_encoder_encode(enc, block.data(), 1024, buf.data(), (int)buf.size());
  CHECK(aac_encoder_set_peak_bitrate(enc, 0) == AAC_ERR_STATE, "peak after the first frame");
  aac_encoder_destroy(enc);
  CHECK(aac_encoder_set_peak_bitrate(nullptr, 0) == AAC_ERR_INVALID_ARG, "null handle");
}

/* ── bandwidth ─────────────────────────────────────────────────── */

/* Highest frequency whose band level, averaged over the decode, lies
 * within 50 dB of the loudest band (2048-point frames, 23.4 Hz bins at
 * 48 kHz, levels per 375 Hz band). */
double bandwidth_50db(const std::vector<float>& x, int channels, int sr) {
  const int nfft = 2048;
  const int bins = nfft / 2;
  std::vector<double> power((size_t)bins, 0.0);
  std::vector<double> re(nfft), im(nfft);
  const size_t frames = x.size() / channels;
  for (size_t start = 4096; start + nfft <= frames; start += nfft) {
    for (int k = 0; k < bins; k += 1) {
      double r = 0.0;
      double i = 0.0;
      for (int t = 0; t < nfft; t += 1) {
        const double w = 0.5 - 0.5 * cos(2.0 * M_PI * t / nfft);
        const double v = w * x[(start + t) * channels];
        const double ph = 2.0 * M_PI * k * t / nfft;
        r += v * cos(ph);
        i -= v * sin(ph);
      }
      power[k] += r * r + i * i;
    }
    break; /* one frame of a stationary signal is enough */
  }
  const int group = 16;
  std::vector<double> band((size_t)bins / group, 0.0);
  for (int k = 0; k < bins; k++) {
    band[(size_t)k / group] += power[k];
  }
  const double peak = *std::max_element(band.begin(), band.end());
  int top = 0;
  for (size_t b = 0; b < band.size(); b++) {
    if (band[b] > peak * 1e-5) {
      top = (int)b;
    }
  }
  return (double)(top + 1) * group * sr / nfft;
}

void test_bandwidth() {
  const int sr = 48000;
  const std::vector<float> pcm = gen_noise(sr, 2);
  for (int hz : {0, 6000, 11000, 16000}) {
    int reported = 0;
    const Encoded e =
        encode_with(pcm, sr, 2, 128000, AAC_AOT_LC, AAC_RC_CBR, [&](AacEncoderHandle h) {
          CHECK(aac_encoder_set_bandwidth(h, hz) == AAC_OK, "bandwidth %d", hz);
          reported = aac_encoder_bandwidth(h);
        });
    int errors = 0;
    const std::vector<float> out = decode_stream(e.stream, sr, 2, &errors);
    CHECK(errors == 0, "bandwidth %d: %d frames failed", hz, errors);
    const double bw = bandwidth_50db(out, 2, sr);
    printf("bandwidth: asked %5d Hz, reported %5d Hz, -50 dB bandwidth of the decode %.0f Hz\n",
           hz, reported, bw);
    if (hz > 0) {
      CHECK(reported == hz, "reported %d for %d", reported, hz);
      /* coded up to the end of the band that holds the cutoff */
      CHECK(bw >= hz - 400 && bw <= hz + 1200, "asked %d Hz, decode reaches %.0f Hz", hz, bw);
    }
  }

  /* With SBR the core of the element is coded up to the crossover
   * whatever the override says: the stream does not change. */
  const std::vector<float> wide = gen_wideband(sr, sr, 2);
  const Encoded he_plain =
      encode_with(wide, sr, 2, 48000, AAC_AOT_SBR, AAC_RC_CBR, [](AacEncoderHandle) {});
  const Encoded he_override =
      encode_with(wide, sr, 2, 48000, AAC_AOT_SBR, AAC_RC_CBR, [](AacEncoderHandle h) {
        CHECK(aac_encoder_set_bandwidth(h, 4000) == AAC_OK, "HE-AAC bandwidth");
      });
  CHECK(he_plain.stream == he_override.stream, "HE-AAC: the override leaves the stream alone");
  AacEncoderHandle he = aac_encoder_create(sr, 2, 48000, AAC_AOT_SBR, AAC_RC_CBR);
  CHECK(aac_encoder_set_bandwidth(he, 999) == AAC_ERR_INVALID_ARG, "bandwidth below 1 kHz");
  CHECK(aac_encoder_set_bandwidth(he, -1) == AAC_ERR_INVALID_ARG, "negative bandwidth");
  aac_encoder_destroy(he);
  /* clamped to half the rate */
  AacEncoderHandle lc = aac_encoder_create(32000, 1, 96000, AAC_AOT_LC, AAC_RC_CBR);
  CHECK(aac_encoder_set_bandwidth(lc, 20000) == AAC_OK && aac_encoder_bandwidth(lc) == 16000,
        "bandwidth clamped to half the sample rate (%d)", aac_encoder_bandwidth(lc));
  aac_encoder_destroy(lc);
}

/* ── VBR modes ─────────────────────────────────────────────────── */

void test_vbr_modes() {
  const int sr = 48000;
  const int n = sr * 3;
  const std::vector<float> pcm = gen_wideband(n, sr, 2);
  double prev = 0.0;
  for (int mode = 1; mode <= 5; mode++) {
    const Encoded e = encode_with(pcm, sr, 2, 64000, AAC_AOT_LC, AAC_RC_CBR, [&](AacEncoderHandle h) {
      CHECK(aac_encoder_set_vbr_mode(h, mode) == AAC_OK, "mode %d", mode);
    });
    const double rate = kbps(e.stream, (size_t)n, sr);
    printf("vbr mode %d: %.1f kbit/s (target %d)\n", mode, rate,
           aac_encoder_vbr_mode_bitrate(mode, 2) / 1000);
    CHECK(rate > prev, "mode %d: %.1f kbit/s does not exceed the mode below", mode, rate);
    CHECK(rate < 1.1 * aac_encoder_vbr_mode_bitrate(mode, 2) / 1000.0,
          "mode %d overshoots its target: %.1f kbit/s", mode, rate);
    prev = rate;
    int vbr_marker = 0;
    for (const AdtsFrame& f : split_adts(e.stream)) {
      AacAdtsHeader hdr;
      aac_adts_parse(&hdr, e.stream.data() + f.offset, (int)f.size);
      vbr_marker += hdr.buffer_fullness == 0x7FF;
    }
    CHECK(vbr_marker == (int)split_adts(e.stream).size(), "mode %d: VBR marker in every frame",
          mode);
    int errors = 0;
    decode_stream(e.stream, sr, 2, &errors);
    CHECK(errors == 0, "mode %d decodes", mode);
  }
  CHECK(aac_encoder_vbr_mode_bitrate(2, 1) == 36000 && aac_encoder_vbr_mode_bitrate(5, 6) == 684000,
        "targets scale per channel");

  /* A peak bitrate below the target lowers the mode: mode 5 under a
   * 120 kbit/s cap is mode 3 under it. */
  auto capped = [&](int mode) {
    return encode_with(pcm, sr, 2, 64000, AAC_AOT_LC, AAC_RC_CBR, [&](AacEncoderHandle h) {
      CHECK(aac_encoder_set_peak_bitrate(h, 120000) == AAC_OK, "peak");
      CHECK(aac_encoder_set_vbr_mode(h, mode) == AAC_OK, "mode %d", mode);
    });
  };
  CHECK(capped(5).stream == capped(3).stream, "a peak bitrate lowers the mode");
  /* in either order */
  const Encoded late = encode_with(pcm, sr, 2, 64000, AAC_AOT_LC, AAC_RC_CBR, [&](AacEncoderHandle h) {
    aac_encoder_set_vbr_mode(h, 5);
    CHECK(aac_encoder_set_peak_bitrate(h, 120000) == AAC_OK, "peak below the mode's target");
  });
  CHECK(late.stream == capped(3).stream, "a peak set after the mode lowers it too");

  /* Clearing the mode restores the creation's bitrate and rate control. */
  const Encoded plain = encode_with(pcm, sr, 2, 64000, AAC_AOT_LC, AAC_RC_CBR, [](AacEncoderHandle) {});
  const Encoded cleared = encode_with(pcm, sr, 2, 64000, AAC_AOT_LC, AAC_RC_CBR, [](AacEncoderHandle h) {
    aac_encoder_set_vbr_mode(h, 4);
    CHECK(aac_encoder_set_vbr_mode(h, 0) == AAC_OK, "clear");
  });
  CHECK(plain.stream == cleared.stream, "a cleared mode leaves the plain stream");

  /* A quality set in a mode outlasts a peak bitrate set afterwards. */
  auto quality_then_peak = [&](bool peak_last) {
    return encode_with(pcm, sr, 2, 64000, AAC_AOT_LC, AAC_RC_CBR, [&](AacEncoderHandle h) {
      aac_encoder_set_vbr_mode(h, 4);
      if (!peak_last) {
        aac_encoder_set_peak_bitrate(h, 320000);
      }
      CHECK(aac_encoder_set_quality(h, 30) == AAC_OK, "quality in a mode");
      if (peak_last) {
        CHECK(aac_encoder_set_peak_bitrate(h, 320000) == AAC_OK, "peak after the quality");
      }
    });
  };
  CHECK(quality_then_peak(true).stream == quality_then_peak(false).stream,
        "the quality survives a later peak bitrate");

  /* Refused: a peak below the first mode's target in a mode, and clearing
   * the mode while a peak below the creation's CBR bitrate is set. */
  {
    AacEncoderHandle h = aac_encoder_create(sr, 2, 256000, AAC_AOT_LC, AAC_RC_CBR);
    CHECK(aac_encoder_set_vbr_mode(h, 1) == AAC_OK, "mode 1");
    CHECK(aac_encoder_set_peak_bitrate(h, 8000) == AAC_ERR_INVALID_ARG, "peak below mode 1");
    CHECK(aac_encoder_set_vbr_mode(h, 5) == AAC_OK, "mode 5");
    CHECK(aac_encoder_set_peak_bitrate(h, 128000) == AAC_OK, "peak lowers mode 5");
    CHECK(aac_encoder_set_vbr_mode(h, 0) == AAC_ERR_INVALID_ARG,
          "no CBR at 256 kbit/s under a 128 kbit/s peak");
    CHECK(aac_encoder_set_peak_bitrate(h, 0) == AAC_OK && aac_encoder_set_vbr_mode(h, 0) == AAC_OK,
          "clearing the peak first");
    aac_encoder_destroy(h);
  }

  /* The afterburner spends no surplus in a VBR mode. */
  const auto mode3 = [&](bool afterburner) {
    return encode_with(pcm, sr, 2, 64000, AAC_AOT_LC, AAC_RC_CBR, [&](AacEncoderHandle h) {
      aac_encoder_set_vbr_mode(h, 3);
      aac_encoder_set_afterburner(h, afterburner ? 1 : 0);
    });
  };
  const double without_ab = kbps(mode3(false).stream, (size_t)n, sr);
  const double with_ab = kbps(mode3(true).stream, (size_t)n, sr);
  printf("vbr mode 3: %.1f kbit/s, %.1f with the afterburner\n", without_ab, with_ab);
  CHECK(with_ab < without_ab * 1.02, "the afterburner keeps a VBR mode's rate");

  /* HE-AAC v1 and v2 and xHE-AAC take the modes too. */
  const Encoded hev2 = encode_with(pcm, sr, 2, 64000, AAC_AOT_PS, AAC_RC_CBR, [](AacEncoderHandle h) {
    CHECK(aac_encoder_set_vbr_mode(h, 1) == AAC_OK, "hev2 mode 1");
  });
  const Encoded he = encode_with(pcm, sr, 2, 64000, AAC_AOT_SBR, AAC_RC_CBR, [](AacEncoderHandle h) {
    CHECK(aac_encoder_set_vbr_mode(h, 2) == AAC_OK, "he mode 2");
  });
  printf("vbr mode 1 HE-AAC v2: %.1f kbit/s, mode 2 HE-AAC: %.1f kbit/s\n",
         kbps(hev2.stream, (size_t)n, sr), kbps(he.stream, (size_t)n, sr));
  CHECK(kbps(hev2.stream, (size_t)n, sr) < 35.2 && kbps(he.stream, (size_t)n, sr) < 79.2,
        "HE-AAC modes stay near their targets");

  AacEncoderHandle enc = aac_encoder_create(sr, 2, 64000, AAC_AOT_LC, AAC_RC_CBR);
  CHECK(aac_encoder_set_vbr_mode(enc, 6) == AAC_ERR_INVALID_ARG &&
            aac_encoder_set_vbr_mode(enc, -1) == AAC_ERR_INVALID_ARG,
        "modes 1 to 5 only");
  std::vector<float> block(2048, 0.0f);
  std::vector<uint8_t> buf(8192);
  aac_encoder_encode(enc, block.data(), 1024, buf.data(), (int)buf.size());
  CHECK(aac_encoder_set_vbr_mode(enc, 2) == AAC_ERR_STATE, "mode after the first frame");
  aac_encoder_destroy(enc);
}

/* ── 5.1 HE-AAC ────────────────────────────────────────────────── */

/* Every SCE and CPE of a 5.1 HE-AAC frame is followed by the fill
 * element with its own SBR data, and the LFE by the end of the block:
 * SCE FIL CPE FIL CPE FIL LFE END (ISO/IEC 14496-3 4.5.2.8.2.2). */
void test_he51_element_order() {
  const int sr = 48000;
  const int channels = 6;
  const std::vector<float> pcm = gen_multichannel(sr, sr, channels);
  AacEncoderHandle enc = aac_encoder_create(sr, channels, 160000, AAC_AOT_SBR, AAC_RC_CBR);
  auto* s = static_cast<AacEncoderState*>(enc);
  const int frame = aac_encoder_frame_size(enc);
  std::vector<uint8_t> buf(65536);
  int checked = 0;
  for (size_t pos = 0; pos + (size_t)frame * channels <= pcm.size(); pos += (size_t)frame * channels) {
    const int len = aac_encoder_encode(enc, pcm.data() + pos, frame, buf.data(), (int)buf.size());
    CHECK(len > AAC_ADTS_HEADER_SIZE, "encode");
    if (len <= AAC_ADTS_HEADER_SIZE) {
      break;
    }
    const uint8_t* raw = buf.data() + AAC_ADTS_HEADER_SIZE;
    const int raw_bytes = len - AAC_ADTS_HEADER_SIZE;
    std::string order;
    for (int i = 0; i < s->num_crc_regions; i++) {
      const AacCrcRegion& r = s->crc_region[i];
      static const char* kNames[8] = {"SCE", "CPE", "CCE", "LFE", "DSE", "PCE", "FIL", "END"};
      order += std::string(kNames[r.type]) + " ";
      AacBitReader br;
      aac_bitreader_init(&br, raw, raw_bytes);
      aac_bitreader_skip(&br, r.end);
      const int next = (int)aac_bitreader_read(&br, 3);
      if (r.type == AAC_ELEM_LFE) {
        order += std::string(kNames[next]) + " ";
        CHECK(next == AAC_ELEM_END, "the LFE is followed by %s", kNames[next]);
        continue;
      }
      CHECK(next == AAC_ELEM_FIL, "element %d is followed by %s, not its fill element", i,
            kNames[next]);
      int count = (int)aac_bitreader_read(&br, 4);
      if (count == 15) {
        count += (int)aac_bitreader_read(&br, 8) - 1;
      }
      const int ext = (int)aac_bitreader_read(&br, 4);
      CHECK(count > 0 && (ext == 13 || ext == 14), "element %d: fill of %d bytes, extension %d", i,
            count, ext);
      order += "FIL ";
      /* the next element starts right after the fill element */
      if (i + 1 < s->num_crc_regions) {
        CHECK(s->crc_region[i + 1].start == r.end + 3 + 4 + (count >= 15 ? 8 : 0) + 8 * count + 3,
              "element %d: the next element starts after its fill element", i);
      }
    }
    if (checked == 0) {
      printf("5.1 HE-AAC frame: %s\n", order.c_str());
    }
    CHECK(order == "SCE FIL CPE FIL CPE FIL LFE END ", "element order %s", order.c_str());
    checked++;
  }
  CHECK(checked > 10, "%d frames checked", checked);
  aac_encoder_destroy(enc);
}

/* FFmpeg decodes a 5.1 HE-AAC stream of ours channel for channel: each
 * output channel matches its own input channel best, at the SNR our
 * decoder reaches. */
void test_he51_ffmpeg() {
  if (!have_ffmpeg()) {
    printf("SKIP: ffmpeg not found\n");
    return;
  }
  const int sr = 48000;
  const int channels = 6;
  const std::vector<float> pcm = gen_multichannel(sr * 2, sr, channels);
  const Encoded e = encode_with(pcm, sr, channels, 192000, AAC_AOT_SBR, AAC_RC_CBR, [](AacEncoderHandle) {});
  const std::string dir = make_temp_dir();
  CHECK(write_file(dir + "/he51.aac", e.stream.data(), e.stream.size()), "write");
  CHECK(run_command("ffmpeg -nostdin -v error -y -i " + dir + "/he51.aac -f f32le " + dir +
                    "/he51.f32"),
        "ffmpeg decodes the 5.1 HE-AAC stream");
  const std::vector<float> ff = read_f32(dir + "/he51.f32");
  int errors = 0;
  const std::vector<float> ours = decode_stream(e.stream, sr, channels, &errors);
  CHECK(errors == 0, "our decoder");
  remove_temp_dir(dir);
  const int delay = AAC_ENCODER_SBR_DELAY;
  const size_t frames = std::min(pcm.size() / channels, ff.size() / channels - delay) - 2048;
  for (int c = 0; c < channels; c++) {
    double best = -1e9;
    int best_in = -1;
    double own = 0.0;
    for (int j = 0; j < channels; j++) {
      double sig = 0.0;
      double err = 0.0;
      for (size_t i = 0; i < frames; i++) {
        const double r = pcm[i * channels + j];
        const double d = r - ff[(i + delay) * channels + c];
        sig += r * r;
        err += d * d;
      }
      const double snr = 10.0 * log10(sig / std::max(err, 1e-20));
      if (snr > best) {
        best = snr;
        best_in = j;
      }
      if (j == c) {
        own = snr;
      }
    }
    double diff_sig = 0.0;
    double diff_err = 0.0;
    for (size_t i = 0; i < frames && (i + delay) * channels + c < ours.size(); i++) {
      const double a = ours[(i + delay) * channels + c];
      const double d = a - ff[(i + delay) * channels + c];
      diff_sig += a * a;
      diff_err += d * d;
    }
    const double agree = 10.0 * log10(diff_sig / std::max(diff_err, 1e-20));
    printf("5.1 HE-AAC FFmpeg channel %d: %.1f dB against input %d, %.1f dB against our decode\n",
           c, own, best_in, agree);
    CHECK(best_in == c, "FFmpeg channel %d matches input %d", c, best_in);
    /* the LFE keeps its lowest lines only, so its upper partial is gone */
    CHECK(own > (c == 3 ? 6.0 : 15.0), "FFmpeg channel %d: %.1f dB", c, own);
    CHECK(agree > 60.0, "FFmpeg and our decoder agree on channel %d (%.1f dB)", c, agree);
  }
}

/* ── HE-AAC v2 drain ───────────────────────────────────────────── */

/* A stream whose last block holds fewer samples than a frame (the
 * caller pads it with silence): the flush drains the parametric stereo
 * encoder's hybrid analysis and the QMF chains, so the last real samples
 * come back after the delay, in our decoder and in FFmpeg. */
void test_ps_short_last_block() {
  const int sr = 48000;
  const int n = 4 * 2048 + 700;
  std::vector<float> pcm((size_t)n * 2);
  for (int i = 0; i < n; i++) {
    const float v = 0.4f * sinf(2.0f * (float)M_PI * 440.0f * (float)i / (float)sr);
    pcm[(size_t)i * 2] = v;
    pcm[(size_t)i * 2 + 1] = v;
  }
  const Encoded e = encode_with(pcm, sr, 2, 32000, AAC_AOT_PS, AAC_RC_CBR, [](AacEncoderHandle) {});
  CHECK(e.sizes.size() == 5 + 3, "%zu units: five blocks and three of drain", e.sizes.size());
  int errors = 0;
  const std::vector<float> out = decode_stream(e.stream, sr, 2, &errors);
  CHECK(errors == 0, "our decoder");
  const int delay = AAC_ENCODER_SBR_DELAY;
  CHECK(out.size() / 2 >= (size_t)(n + delay), "the decode covers the input and the delay (%zu)",
        out.size() / 2);
  /* the last 2048 input samples, which the last block and the drain carry */
  auto tail_snr = [&](const std::vector<float>& dec, int d) {
    double sig = 0.0;
    double err = 0.0;
    for (int i = n - 2048; i < n - 64; i++) {
      for (int c = 0; c < 2; c++) {
        const double r = pcm[(size_t)i * 2 + c];
        const size_t at = (size_t)(i + d) * 2 + c;
        const double diff = r - (at < dec.size() ? dec[at] : 0.0);
        sig += r * r;
        err += diff * diff;
      }
    }
    return 10.0 * log10(sig / std::max(err, 1e-20));
  };
  const double ours = tail_snr(out, delay);
  printf("HE-AAC v2 short last block: tail SNR %.1f dB in our decoder\n", ours);
  CHECK(ours > 10.0, "the last block comes back (%.1f dB)", ours);
  if (have_ffmpeg()) {
    const std::string dir = make_temp_dir();
    write_file(dir + "/ps.aac", e.stream.data(), e.stream.size());
    CHECK(run_command("ffmpeg -nostdin -v error -y -i " + dir + "/ps.aac -f f32le -ac 2 " + dir +
                      "/ps.f32"),
          "ffmpeg decodes the stream");
    const std::vector<float> ff = read_f32(dir + "/ps.f32");
    remove_temp_dir(dir);
    const double snr = tail_snr(ff, delay);
    printf("HE-AAC v2 short last block: tail SNR %.1f dB in FFmpeg\n", snr);
    CHECK(snr > 10.0, "FFmpeg: the last block comes back (%.1f dB)", snr);
  }
}

/* One input sample, then the flush: a valid stream of one block and the
 * drain, which our decoder and FFmpeg decode without error. */
void test_ps_single_sample() {
  const int sr = 44100;
  const std::vector<float> pcm = {0.5f, -0.5f};
  const Encoded e = encode_with(pcm, sr, 2, 32000, AAC_AOT_PS, AAC_RC_CBR, [](AacEncoderHandle) {});
  CHECK(e.sizes.size() == 4, "%zu units for one sample", e.sizes.size());
  CHECK(split_adts(e.stream).size() == 4, "four ADTS frames");
  int errors = 0;
  const std::vector<float> out = decode_stream(e.stream, sr, 2, &errors);
  CHECK(errors == 0 && out.size() == 4 * 2048 * 2, "our decoder: %d errors, %zu samples", errors,
        out.size());
  for (float v : out) {
    if (!std::isfinite(v) || fabsf(v) > 1.0f) {
      CHECK(false, "a decoded sample out of range: %f", v);
      break;
    }
  }
  if (have_ffmpeg()) {
    const std::string dir = make_temp_dir();
    write_file(dir + "/one.aac", e.stream.data(), e.stream.size());
    CHECK(run_command("ffmpeg -nostdin -v error -y -i " + dir + "/one.aac -f f32le " + dir +
                      "/one.f32"),
          "ffmpeg decodes a one-sample HE-AAC v2 stream");
    remove_temp_dir(dir);
  }
}

/* ── input out of range ────────────────────────────────────────── */

/* Decodes a stream of any transport through the FIFO, concealment off;
 * returns whether every frame decoded and every sample is finite. */
bool decodes_finite(const std::vector<uint8_t>& stream) {
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  if (!dec) {
    return false;
  }
  aac_decoder_set_concealment(dec, AAC_CONCEAL_OFF);
  std::vector<float> out((size_t)AAC_DECODER_MAX_OUTPUT * 8);
  bool ok = true;
  int frames = 0;
  size_t pos = 0;
  for (;;) {
    pos += (size_t)aac_decoder_feed(dec, stream.data() + pos, (int)(stream.size() - pos));
    const int got = aac_decoder_decode_next(dec, out.data(), (int)out.size());
    if (got == 0 && pos == stream.size()) {
      break;
    }
    ok = ok && got >= 0;
    for (int i = 0; i < got * aac_decoder_channels(dec); i++) {
      ok = ok && std::isfinite(out[(size_t)i]);
    }
    frames += got > 0;
  }
  aac_decoder_destroy(dec);
  return ok && frames > 0;
}

/* A NaN is coded as silence and a sample beyond 65536 in magnitude as
 * 65536 of its sign: a stream with NaN, infinities or huge values is
 * byte for byte the stream of the signal with those replacements, in
 * every profile, and decodes to finite samples.  Single samples and a
 * run longer than a block are replaced. */
void test_out_of_range_input() {
  struct Profile {
    const char* name;
    AacObjectType aot;
    int bitrate;
    AacRateControl rc;
  };
  const Profile profiles[] = {{"AAC-LC", AAC_AOT_LC, 128000, AAC_RC_CBR},
                              {"AAC-LC TVBR", AAC_AOT_LC, 128000, AAC_RC_TVBR},
                              {"HE-AAC", AAC_AOT_SBR, 48000, AAC_RC_CBR},
                              {"HE-AAC v2", AAC_AOT_PS, 32000, AAC_RC_CBR},
                              {"xHE-AAC", AAC_AOT_USAC, 64000, AAC_RC_CBR},
                              {"AAC-ELD", AAC_AOT_ER_ELD, 128000, AAC_RC_CBR}};
  std::vector<float> signal = gen_he_signal(2);
  signal.resize(2 * 36000);
  std::vector<float> nans = signal, silenced = signal;
  std::vector<float> infinite = signal, huge = signal, limited = signal;
  for (size_t k : {1001u, 12346u, 20001u, 33333u}) {
    const float sign = k & 1 ? 1.0f : -1.0f;
    nans[k] = NAN;
    silenced[k] = 0.0f;
    infinite[k] = sign * INFINITY;
    huge[k] = sign * (k < 20000 ? 1e30f : FLT_MAX);
    limited[k] = sign * 65536.0f;
  }
  for (size_t k = 2 * 30000; k < 2 * 32048; k++) { /* a whole block and more */
    nans[k] = NAN;
    silenced[k] = 0.0f;
    infinite[k] = -INFINITY;
    huge[k] = -1e20f;
    limited[k] = -65536.0f;
  }
  const auto none = [](AacEncoderHandle) {};
  for (const Profile& p : profiles) {
    const auto encode = [&](const std::vector<float>& pcm) {
      return encode_with(pcm, 48000, 2, p.bitrate, p.aot, p.rc, none).stream;
    };
    const std::vector<uint8_t> want_silence = encode(silenced);
    const std::vector<uint8_t> want_limit = encode(limited);
    CHECK(encode(nans) == want_silence, "%s: NaN codes as silence", p.name);
    CHECK(encode(infinite) == want_limit, "%s: an infinity codes as 65536", p.name);
    CHECK(encode(huge) == want_limit, "%s: a huge sample codes as 65536", p.name);
    CHECK(decodes_finite(want_silence) && decodes_finite(want_limit),
          "%s: the streams decode to finite samples", p.name);
  }
}

/* ── the committed streams ─────────────────────────────────────── */

/* One stream per encoder the xHE-AAC suites do not reach: AAC-LC with
 * the afterburner, its default, which is what a transcode writes; the
 * SBR encoder over it; and parametric stereo over both. */
struct Committed {
  const char* name;
  AacObjectType aot;
  int bitrate;
  double min_low_db; /* of the decode's low band against the source's */
};
const Committed kCommitted[3] = {{"lc_enc_cpe_128k.aac", AAC_AOT_LC, 128000, 20.0},
                                 {"he_enc_cpe_48k.aac", AAC_AOT_SBR, 48000, 16.0},
                                 {"hev2_enc_cpe_32k.aac", AAC_AOT_PS, 32000, 13.0}};
constexpr uint32_t kCommittedSignal = 0xe5ee8187u; /* signal_checksum of committed_signal() */

/* The committed streams' signal: the HE-AAC suite's tones, noise and
 * clicks at half level, as in the xHE-AAC suites. */
std::vector<float> committed_signal() {
  std::vector<float> pcm = gen_he_signal(2);
  for (float& v : pcm) {
    v *= 0.5f;
  }
  return pcm;
}

std::vector<uint8_t> committed_encode(const Committed& s, const std::vector<float>& pcm) {
  int error = 0;
  const std::vector<uint8_t> stream =
      encode_stream(pcm, 48000, 2, s.bitrate, AAC_RC_CBR, 0, &error, s.aot);
  CHECK(error == 0 && !stream.empty(), "%s: encode error %d", s.name, error);
  return stream;
}

/* The mono downmix below 3 kHz, where every one of the three profiles
 * codes the waveform. */
std::vector<float> low_band(const std::vector<float>& stereo) {
  std::vector<float> mono(stereo.size() / 2);
  for (size_t i = 0; i < mono.size(); i++) {
    mono[i] = 0.5f * (stereo[2 * i] + stereo[2 * i + 1]);
  }
  return lowpass_channel(mono, 1, 0, 3000.0f, 48000);
}

/* SNR of a stream's decoded low band against the source's, at the best
 * delay; -100 when a frame fails or the output is not stereo. */
double low_band_db(const std::vector<float>& low_src, const std::vector<uint8_t>& stream) {
  int errors = 0;
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  const std::vector<float> out = decode_stream(stream, 48000, 0, &errors, dec);
  const int channels = dec ? aac_decoder_channels(dec) : 0;
  aac_decoder_destroy(dec);
  if (errors != 0 || channels != 2 || out.empty()) {
    return -100.0;
  }
  return best_aligned_snr_db(low_src, low_band(out), 1024, 7000, nullptr);
}

int write_streams(const char* dir) {
  const std::vector<float> pcm = committed_signal();
  for (const Committed& s : kCommitted) {
    const std::vector<uint8_t> stream = committed_encode(s, pcm);
    const std::string path = std::string(dir) + "/" + s.name;
    if (g_failures || !write_file(path, stream.data(), stream.size())) {
      fprintf(stderr, "%s: not written\n", path.c_str());
      return 1;
    }
    printf("%s: %zu frames, %zu bytes\n", path.c_str(), split_adts(stream).size(), stream.size());
  }
  return 0;
}

/* The streams under tests/data decode without error and match the
 * generator's signal in the low band; a fresh encode of that signal is
 * byte identical, so a change of the encoder's output shows up here and
 * the streams are regenerated with --write-streams on purpose.  The
 * streams were written by the reference build (test_util.h) from the
 * signal whose checksum is given with them; other builds compare the
 * decodes instead. */
void test_committed() {
  const std::vector<float> pcm = committed_signal();
  const std::vector<float> low_src = low_band(pcm);
  for (const Committed& s : kCommitted) {
    const std::vector<uint8_t> stream = read_file(data_path(s.name));
    CHECK(!stream.empty(), "%s present", s.name);
    if (stream.empty()) {
      continue;
    }
    const double low = low_band_db(low_src, stream);
    CHECK(low > s.min_low_db, "%s: low band %.1f dB against the source", s.name, low);
    const std::vector<uint8_t> fresh = committed_encode(s, pcm);
#if BAAC_REFERENCE_BUILD
    if (!check_committed_signal(s.name, pcm, kCommittedSignal)) {
      continue;
    }
    CHECK(fresh == stream, "%s: a fresh encode is byte identical (%zu vs %zu bytes)", s.name,
          fresh.size(), stream.size());
#else
    const double again = low_band_db(low_src, fresh);
    CHECK(fabs(again - low) < 1.5,
          "%s: a fresh encode of this build decodes like the committed stream (%.1f against "
          "%.1f dB; the byte identity check needs the reference build)",
          s.name, again, low);
#endif
    printf("  %s: low band %.1f dB\n", s.name, low);
  }
}

}  // namespace

int main(int argc, char** argv) {
  if (const char* dir = write_streams_dir(argc, argv)) {
    return write_streams(dir);
  }
  test_committed();
  test_peak_bitrate();
  test_bandwidth();
  test_vbr_modes();
  test_he51_element_order();
  test_he51_ffmpeg();
  test_ps_short_last_block();
  test_ps_single_sample();
  test_out_of_range_input();
  return test_summary("test_encoder_tuning");
}
