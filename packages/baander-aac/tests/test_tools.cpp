/* The encoder's coding tools as seen in its streams: perceptual noise
 * substitution, intensity stereo and TNS are used where the material calls
 * for them, every such frame decodes, and the decoded signal keeps the
 * level, spectrum and stereo balance the tools stand in for. */
#include "aac_dsp.h"
#include "decoder.h"
#include "mdct.h"
#include "test_util.h"

struct ToolStats {
  int frames = 0;
  int noise_frames = 0;
  int intensity_frames = 0;
  int tns_frames = 0;
  int short_tns_frames = 0;
  int two_filter_frames = 0;
  int errors = 0;
};

/* Walks an ADTS stream with the internal decoder and counts the frames
 * that carry each tool. */
static ToolStats tool_stats(const std::vector<uint8_t>& stream, int sr, int channels) {
  ToolStats st;
  AacDSP dsp;
  aac_dsp_init(&dsp);
  aac_tables_init();
  AacDecoderState* s = aac_decoder_state_create(sr, channels, &dsp);
  for (const AdtsFrame& f : split_adts(stream)) {
    AacAdtsHeader hdr;
    if (aac_adts_parse(&hdr, stream.data() + f.offset, (int)f.size) < 0) {
      st.errors++;
      continue;
    }
    const int header = AAC_ADTS_HEADER_SIZE + (hdr.protection_absent ? 0 : 2);
    AacBitReader r;
    aac_bitreader_init(&r, stream.data() + f.offset + header, (int)f.size - header);
    if (aac_decode_raw_data_block(s, &r) < 0) {
      st.errors++;
      continue;
    }
    st.frames++;
    bool noise = false, intensity = false, tns = false, short_tns = false, two = false;
    for (int c = 0; c < channels; c++) {
      const AacChannelStream* cs = &s->ch[c].cs;
      const AacIcsInfo* ics = &cs->ics;
      for (int i = 0; i < ics->num_window_groups * ics->max_sfb; i++) {
        noise |= cs->band_type[i] == AAC_CB_NOISE;
        intensity |= cs->band_type[i] == AAC_CB_INTENSITY || cs->band_type[i] == AAC_CB_INTENSITY2;
      }
      if (cs->tns_present) {
        tns = true;
        for (int w = 0; w < ics->num_windows; w++) {
          short_tns |= ics->num_windows == 8 && cs->tns.n_filt[w] > 0;
          two |= cs->tns.n_filt[w] > 1;
        }
      }
    }
    st.noise_frames += noise;
    st.intensity_frames += intensity;
    st.tns_frames += tns;
    st.short_tns_frames += short_tns;
    st.two_filter_frames += two;
  }
  aac_decoder_state_destroy(s);
  return st;
}

static double rms(const std::vector<float>& x, size_t start, size_t count, int channels, int ch) {
  double acc = 0.0;
  for (size_t i = 0; i < count; i++) {
    const float v = x[(start + i) * channels + ch];
    acc += v * v;
  }
  return sqrt(acc / (double)count);
}

/* Energy of the signal in MDCT lines [lo, hi) of 2048-sample sine-windowed
 * frames over x[start .. start + count), in dB. */
static double band_energy_db(const std::vector<float>& x, size_t start, size_t count, int channels,
                             int ch, int lo, int hi) {
  AacMdct mdct;
  aac_mdct_init(&mdct, 2048);
  static float window[2048];
  aac_sine_window(window, 2048);
  float in[2048], out[1024];
  double acc = 0.0;
  for (size_t pos = 0; pos + 2048 <= count; pos += 1024) {
    for (int i = 0; i < 2048; i++) {
      const float w = i < 1024 ? window[i] : window[2047 - i];
      in[i] = x[(start + pos + (size_t)i) * channels + ch] * w;
    }
    aac_mdct_forward(&mdct, in, out);
    for (int k = lo; k < hi; k++) {
      acc += (double)out[k] * out[k];
    }
  }
  return 10.0 * log10(acc + 1e-30);
}

static void pns_keeps_the_noise_level() {
  const int sr = 44100;
  const int n = sr * 3;
  const std::vector<float> pcm = gen_noise(n, 1);
  int err = 0;
  const std::vector<uint8_t> stream = encode_stream(pcm, sr, 1, 40000, AAC_RC_CBR, 0, &err);
  CHECK(err == 0, "pns: encode error %d", err);
  const ToolStats st = tool_stats(stream, sr, 1);
  CHECK(st.errors == 0, "pns: %d frames failed to decode", st.errors);
  printf("pns mono 40k: %d of %d frames substitute noise\n", st.noise_frames, st.frames);
  CHECK(st.noise_frames >= st.frames * 9 / 10, "pns: noise substitution in only %d of %d frames",
        st.noise_frames, st.frames);

  int errors = 0;
  const std::vector<float> out = decode_stream(stream, sr, 1, &errors);
  CHECK(errors == 0, "pns: public decoder errors %d", errors);
  /* The lowpass at this bitrate sits near 12 kHz; below it, the coded
   * lines and the substituted region (above about 4 kHz) keep their
   * energy. */
  const size_t count = (size_t)n - 4096;
  const int line_4k = 1024 * 4100 / (sr / 2);
  const int line_11k = 1024 * 11000 / (sr / 2);
  const double low = band_energy_db(out, 2048, count, 1, 0, 0, line_4k) -
                     band_energy_db(pcm, 0, count, 1, 0, 0, line_4k);
  const double noise = band_energy_db(out, 2048, count, 1, 0, line_4k, line_11k) -
                       band_energy_db(pcm, 0, count, 1, 0, line_4k, line_11k);
  printf("pns mono 40k: coded region %+.2f dB, substituted region %+.2f dB\n", low, noise);
  CHECK(fabs(low) < 1.5, "pns: level below 4 kHz off by %.2f dB", low);
  CHECK(fabs(noise) < 1.5, "pns: level of the substituted region off by %.2f dB", noise);

  /* The variable-rate modes do without substitution. */
  const std::vector<uint8_t> vbr = encode_stream(pcm, sr, 1, 40000, AAC_RC_TVBR, 0, &err);
  CHECK(err == 0, "pns tvbr: encode error %d", err);
  const ToolStats vst = tool_stats(vbr, sr, 1);
  CHECK(vst.noise_frames == 0, "pns: %d noise frames in a VBR stream", vst.noise_frames);
}

static void intensity_keeps_the_balance() {
  const int sr = 44100;
  const int n = sr * 3;
  const std::vector<float> pcm = gen_harmonics(n, sr, 2); /* right = 0.7 * left */
  int err = 0;
  const std::vector<uint8_t> stream = encode_stream(pcm, sr, 2, 48000, AAC_RC_CBR, 0, &err);
  CHECK(err == 0, "intensity: encode error %d", err);
  const ToolStats st = tool_stats(stream, sr, 2);
  CHECK(st.errors == 0, "intensity: %d frames failed to decode", st.errors);
  printf("intensity stereo 48k: %d of %d frames use it\n", st.intensity_frames, st.frames);
  CHECK(st.intensity_frames >= st.frames / 2, "intensity: used in only %d of %d frames",
        st.intensity_frames, st.frames);

  int errors = 0;
  const std::vector<float> out = decode_stream(stream, sr, 2, &errors);
  CHECK(errors == 0, "intensity: public decoder errors %d", errors);
  const size_t count = (size_t)n - 4096;
  const double balance = 20.0 * log10(rms(out, 2048, count, 2, 1) / rms(out, 2048, count, 2, 0));
  const double expected = 20.0 * log10(0.7);
  const double snr = aligned_snr_db(pcm, out, 2, 2048);
  printf("intensity stereo 48k: balance %.2f dB (source %.2f dB), SNR %.1f dB\n", balance, expected,
         snr);
  CHECK(fabs(balance - expected) < 1.0, "intensity: balance %.2f dB, expected %.2f", balance,
        expected);
  CHECK(snr > 25.0, "intensity: SNR %.1f dB", snr);

  /* Not at bitrates that afford both channels. */
  const std::vector<uint8_t> high = encode_stream(pcm, sr, 2, 128000, AAC_RC_CBR, 0, &err);
  const ToolStats hst = tool_stats(high, sr, 2);
  CHECK(hst.intensity_frames == 0, "intensity: %d frames at 128 kbit/s", hst.intensity_frames);
}

static void tns_covers_both_window_types() {
  const int sr = 44100;
  const int n = sr * 3;
  const std::vector<float> pcm = gen_transients(n, sr, 2);
  int err = 0;
  const std::vector<uint8_t> stream = encode_stream(pcm, sr, 2, 96000, AAC_RC_CBR, 0, &err);
  CHECK(err == 0, "tns: encode error %d", err);
  const ToolStats st = tool_stats(stream, sr, 2);
  CHECK(st.errors == 0, "tns: %d frames failed to decode", st.errors);
  printf(
      "tns transients 96k: %d of %d frames, %d with short windows, %d with two filters, "
      "%d with noise substitution\n",
      st.tns_frames, st.frames, st.short_tns_frames, st.two_filter_frames, st.noise_frames);
  /* The bursts are 40 ms in 180 ms periods; the silent frames between
   * them carry nothing to filter. */
  CHECK(st.tns_frames > st.frames / 4, "tns: active in only %d of %d frames", st.tns_frames,
        st.frames);
  CHECK(st.short_tns_frames > 0, "tns: never used on short windows");
  CHECK(st.two_filter_frames > 0, "tns: never used two filters");
  int errors = 0;
  const std::vector<float> out = decode_stream(stream, sr, 2, &errors);
  CHECK(errors == 0, "tns: public decoder errors %d", errors);
  const double snr = aligned_snr_db(pcm, out, 2, 2048);
  CHECK(snr > 3.0, "tns: SNR %.1f dB", snr);
}

int main() {
  pns_keeps_the_noise_level();
  intensity_keeps_the_balance();
  tns_covers_both_window_types();
  return test_summary("test_tools");
}
