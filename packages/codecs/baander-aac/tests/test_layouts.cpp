/* Channel layouts and multi-element streams on hand-built frames: the
 * layout module's speaker assignment, program config elements, LFE
 * elements, coupling channel elements (dependent and independent), ADTS
 * frames with several raw data blocks, and the output mapping. */
#include "bitstream.h"
#include "channel_map.h"
#include "mdct.h"
#include "test_util.h"

/* One individual_channel_stream at 48 kHz: long window, one coded band
 * carrying the quad (1,0,0,0) with codebook 4 at the given global gain. */
static void write_ics(AacBitWriter* w, int global_gain, int with_ics_info) {
  aac_bitwriter_write(w, (uint32_t)global_gain, 8);
  if (with_ics_info) {
    aac_bitwriter_write(w, 0, 1);
    aac_bitwriter_write(w, AAC_WIN_ONLY_LONG, 2);
    aac_bitwriter_write(w, AAC_WIN_SINE, 1);
    aac_bitwriter_write(w, 49, 6); /* max_sfb */
    aac_bitwriter_write(w, 0, 1);  /* predictor_data_present */
  }
  aac_bitwriter_write(w, 4, 4); /* section: codebook 4, length 1 */
  aac_bitwriter_write(w, 1, 5);
  aac_bitwriter_write(w, 0, 4); /* codebook 0 for the remaining 48 bands */
  aac_bitwriter_write(w, 31, 5);
  aac_bitwriter_write(w, 17, 5);
  aac_bitwriter_write_scalefactor(w, 0);
  aac_bitwriter_write(w, 0, 1); /* pulse */
  aac_bitwriter_write(w, 0, 1); /* tns */
  aac_bitwriter_write(w, 0, 1); /* gain control */
  const int quad[4] = {1, 0, 0, 0};
  aac_bitwriter_write_huffman(w, 4, quad);
}

static void write_sce(AacBitWriter* w, int elem, int tag, int global_gain) {
  aac_bitwriter_write(w, (uint32_t)elem, 3);
  aac_bitwriter_write(w, (uint32_t)tag, 4);
  write_ics(w, global_gain, 1);
}

static void write_cpe(AacBitWriter* w, int tag, int gain_l, int gain_r) {
  aac_bitwriter_write(w, AAC_ELEM_CPE, 3);
  aac_bitwriter_write(w, (uint32_t)tag, 4);
  aac_bitwriter_write(w, 0, 1); /* common_window */
  write_ics(w, gain_l, 1);
  write_ics(w, gain_r, 1);
}

static int finish_frame(AacBitWriter* w, uint8_t* out, int channel_config, int blocks) {
  AacAdtsHeader hdr = {};
  hdr.protection_absent = 1;
  hdr.object_type = AAC_AOT_LC;
  hdr.sample_rate_index = 3; /* 48000 Hz */
  hdr.channel_config = channel_config;
  hdr.frame_length = aac_bitwriter_bytes_written(w);
  hdr.buffer_fullness = 0x7FF;
  hdr.num_raw_blocks = blocks;
  aac_adts_write(&hdr, out);
  return hdr.frame_length;
}

static void start_frame(AacBitWriter* w, uint8_t* out, int out_size) {
  aac_bitwriter_init(w, out, out_size);
  aac_bitwriter_write(w, 0, 32);
  aac_bitwriter_write(w, 0, 24);
}

static void end_block(AacBitWriter* w) {
  aac_bitwriter_write(w, AAC_ELEM_END, 3);
  aac_bitwriter_byte_align(w);
}

static double energy(const float* pcm, int channels, int ch) {
  double e = 0.0;
  for (int i = 0; i < 1024; i++) {
    e += (double)pcm[i * channels + ch] * pcm[i * channels + ch];
  }
  return e;
}

static void test_layout_module() {
  AacLayout l;
  CHECK(aac_layout_from_config(&l, 6) == 0 && l.num_channels == 6, "5.1 layout");
  /* SCE centre, CPE front, CPE back, LFE: slots L R C LFE Ls Rs. */
  CHECK(l.elems[0].slot[0] == 2, "centre in slot 2");
  CHECK(l.elems[1].slot[0] == 0 && l.elems[1].slot[1] == 1, "front pair in slots 0 and 1");
  CHECK(l.elems[2].slot[0] == 4 && l.elems[2].slot[1] == 5, "back pair in slots 4 and 5");
  CHECK(l.elems[3].slot[0] == 3, "LFE in slot 3");
  CHECK(aac_layout_from_config(&l, 7) == 0 && l.num_channels == 8, "7.1 layout");
  CHECK(l.elems[1].slot[0] == 0 && l.elems[2].slot[0] == 6, "7.1: first pair front, second outer");
  CHECK(aac_layout_from_config(&l, 11) == 0 && l.num_channels == 7 && l.elems[3].slot[0] == 6,
        "6.1: back centre last");
  CHECK(aac_layout_from_config(&l, 12) == 0 && l.num_channels == 8 && l.elems[2].slot[0] == 6 &&
            l.elems[3].slot[0] == 4,
        "7.1 back: side pair after the back pair");
  CHECK(aac_layout_from_config(&l, 8) == AAC_ERR_UNSUPPORTED, "configuration 8 has no layout");
  CHECK(aac_layout_from_config(&l, 13) == AAC_ERR_UNSUPPORTED, "22.2 refused");

  /* A lone side channel cannot be placed: element order is kept. */
  AacLayoutElement odd[3] = {};
  odd[0] = {AAC_ELEM_CPE, 0, AAC_POS_FRONT, {0, 0}, {0, 0}};
  odd[1] = {AAC_ELEM_SCE, 0, AAC_POS_SIDE, {0, 0}, {0, 0}};
  odd[2] = {AAC_ELEM_SCE, 1, AAC_POS_BACK, {0, 0}, {0, 0}};
  l.num_cce = 0;
  CHECK(aac_layout_from_elements(&l, odd, 3) == 0 && l.num_channels == 4, "odd layout accepted");
  CHECK(l.elems[1].slot[0] == 2 && l.elems[2].slot[0] == 3 && l.slot_speaker[2] < 0,
        "odd layout keeps element order");
  CHECK(aac_layout_config_for_elements(odd, 3) == 0, "no configuration matches");
  AacLayoutElement five[3] = {{AAC_ELEM_SCE, 0, AAC_POS_FRONT, {0, 0}, {0, 0}},
                              {AAC_ELEM_CPE, 0, AAC_POS_FRONT, {0, 0}, {0, 0}},
                              {AAC_ELEM_CPE, 1, AAC_POS_BACK, {0, 0}, {0, 0}}};
  CHECK(aac_layout_config_for_elements(five, 3) == 5, "SCE CPE CPE is configuration 5");

  /* Downmix of 5.1: centre and surrounds at -3 dB, LFE dropped. */
  aac_layout_from_config(&l, 6);
  float in[6][4];
  const float* in_ptr[6];
  for (int c = 0; c < 6; c++) {
    for (int i = 0; i < 4; i++) {
      in[c][i] = 0.0f;
    }
    in[c][0] = 1.0f;
    in_ptr[c] = in[c];
  }
  float out[8];
  aac_layout_mix(&l, in_ptr, 1, 2, out);
  CHECK(fabs(out[0] - (1.0f + 2.0f * 0.70710678f)) < 1e-5 && fabs(out[1] - out[0]) < 1e-6,
        "5.1 stereo downmix weights (%g, %g)", out[0], out[1]);
  aac_layout_mix(&l, in_ptr, 1, 1, out);
  CHECK(fabs(out[0] - 0.70710678f * 2.0f * (1.0f + 2.0f * 0.70710678f)) < 1e-5,
        "5.1 mono downmix weight %g", out[0]);
  aac_layout_mix(&l, in_ptr, 1, 8, out);
  CHECK(out[5] == 1.0f && out[6] == 0.0f && out[7] == 0.0f, "wider request zero-padded");
}

static void test_pce_stream() {
  /* channel_config 0: the PCE lists SCE 0 and CPE 0 in front, SCE 1 in
   * the back and LFE 0; the elements follow with distinct gains. */
  uint8_t frame[1024];
  AacBitWriter w;
  start_frame(&w, frame, sizeof(frame));
  aac_bitwriter_write(&w, AAC_ELEM_PCE, 3);
  aac_bitwriter_write(&w, 0, 4);  /* element_instance_tag */
  aac_bitwriter_write(&w, 1, 2);  /* object_type: LC */
  aac_bitwriter_write(&w, 3, 4);  /* sampling_frequency_index */
  aac_bitwriter_write(&w, 2, 4);  /* num_front */
  aac_bitwriter_write(&w, 0, 4);  /* num_side */
  aac_bitwriter_write(&w, 1, 4);  /* num_back */
  aac_bitwriter_write(&w, 1, 2);  /* num_lfe */
  aac_bitwriter_write(&w, 0, 3);  /* num_assoc_data */
  aac_bitwriter_write(&w, 0, 4);  /* num_valid_cc */
  aac_bitwriter_write(&w, 0, 1);  /* mono_mixdown_present */
  aac_bitwriter_write(&w, 0, 1);  /* stereo_mixdown_present */
  aac_bitwriter_write(&w, 0, 1);  /* matrix_mixdown_idx_present */
  aac_bitwriter_write(&w, 0, 1);  /* front 0: SCE */
  aac_bitwriter_write(&w, 0, 4);
  aac_bitwriter_write(&w, 1, 1);  /* front 1: CPE */
  aac_bitwriter_write(&w, 0, 4);
  aac_bitwriter_write(&w, 0, 1);  /* back 0: SCE */
  aac_bitwriter_write(&w, 1, 4);
  aac_bitwriter_write(&w, 0, 4);  /* LFE tag */
  aac_bitwriter_byte_align(&w);
  aac_bitwriter_write(&w, 3, 8); /* comment: 3 bytes */
  aac_bitwriter_write(&w, 'a', 8);
  aac_bitwriter_write(&w, 'b', 8);
  aac_bitwriter_write(&w, 'c', 8);
  write_sce(&w, AAC_ELEM_SCE, 0, 160);  /* centre */
  write_cpe(&w, 0, 170, 150);           /* left, right */
  write_sce(&w, AAC_ELEM_SCE, 1, 140);  /* back centre */
  write_sce(&w, AAC_ELEM_LFE, 0, 130);  /* LFE */
  end_block(&w);
  const int len = finish_frame(&w, frame, 0, 1);

  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  float pcm[AAC_DECODER_MAX_OUTPUT * 8];
  const int n = aac_decoder_decode(dec, frame, len, pcm, (int)(sizeof(pcm) / sizeof(float)));
  CHECK(n == 1024, "PCE frame decoded %d samples", n);
  CHECK(aac_decoder_channels(dec) == 5 && aac_decoder_stream_channels(dec) == 5,
        "PCE layout has five channels (%d)", aac_decoder_channels(dec));
  /* Gains order the energies: L (170) > C (160) > R (150) > Cs (140) > LFE (130);
   * slots are L R C LFE Cs. */
  double e[5];
  for (int c = 0; c < 5; c++) {
    e[c] = energy(pcm, 5, c);
  }
  CHECK(e[0] > e[2] && e[2] > e[1] && e[1] > e[4] && e[4] > e[3] && e[3] > 0.0,
        "PCE slots in canonical order (%g %g %g %g %g)", e[0], e[1], e[2], e[3], e[4]);

  /* The same frame through a stereo decoder is downmixed. */
  AacDecoderHandle st = aac_decoder_create(48000, 2);
  float mixed[2048];
  CHECK(aac_decoder_decode(st, frame, len, mixed, 2048) == 1024, "PCE frame downmixed");
  float expect_l = 0.0f;
  for (int i = 0; i < 16; i++) {
    expect_l = pcm[i * 5 + 0] + 0.70710678f * pcm[i * 5 + 2] + 0.5f * pcm[i * 5 + 4];
    CHECK(fabs(mixed[2 * i] - expect_l) < 1e-5, "downmix sample %d: %g vs %g", i, mixed[2 * i],
          expect_l);
  }
  aac_decoder_destroy(st);

  /* A second frame without PCE keeps the layout; one with different
   * elements is refused. */
  start_frame(&w, frame, sizeof(frame));
  write_sce(&w, AAC_ELEM_SCE, 0, 160);
  write_cpe(&w, 0, 170, 150);
  write_sce(&w, AAC_ELEM_SCE, 1, 140);
  write_sce(&w, AAC_ELEM_LFE, 0, 130);
  end_block(&w);
  const int len2 = finish_frame(&w, frame, 0, 1);
  CHECK(aac_decoder_decode(dec, frame, len2, pcm, (int)(sizeof(pcm) / sizeof(float))) == 1024,
        "frame after the PCE");
  start_frame(&w, frame, sizeof(frame));
  write_cpe(&w, 3, 170, 150);
  end_block(&w);
  const int len3 = finish_frame(&w, frame, 0, 1);
  CHECK(aac_decoder_decode(dec, frame, len3, pcm, (int)(sizeof(pcm) / sizeof(float))) < 0,
        "unknown element refused");
  aac_decoder_destroy(dec);
}

static void test_sniffed_layout() {
  /* channel_config 0 without PCE: SCE, CPE, CPE is configuration 5. */
  uint8_t frame[1024];
  AacBitWriter w;
  start_frame(&w, frame, sizeof(frame));
  write_sce(&w, AAC_ELEM_SCE, 0, 160);
  write_cpe(&w, 0, 170, 150);
  write_cpe(&w, 1, 140, 130);
  end_block(&w);
  const int len = finish_frame(&w, frame, 0, 1);
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  float pcm[AAC_DECODER_MAX_OUTPUT * 8];
  CHECK(aac_decoder_decode(dec, frame, len, pcm, (int)(sizeof(pcm) / sizeof(float))) == 1024,
        "sniffed frame");
  CHECK(aac_decoder_channels(dec) == 5, "sniffed layout has five channels");
  double e[5];
  for (int c = 0; c < 5; c++) {
    e[c] = energy(pcm, 5, c);
  }
  CHECK(e[0] > e[2] && e[2] > e[1] && e[1] > e[3] && e[3] > e[4], "sniffed slots L R C Ls Rs");
  aac_decoder_destroy(dec);
}

static void test_coupling() {
  uint8_t frame[1024];
  float ref[1024 * 2], pcm[1024 * 2];
  AacBitWriter w;

  /* Reference: a CPE alone (channel_config 2). */
  start_frame(&w, frame, sizeof(frame));
  write_cpe(&w, 0, 160, 160);
  end_block(&w);
  int len = finish_frame(&w, frame, 2, 1);
  AacDecoderHandle dec = aac_decoder_create(48000, 2);
  CHECK(aac_decoder_decode(dec, frame, len, ref, 2048) == 1024, "reference CPE");
  aac_decoder_destroy(dec);
  const double e_ref = energy(ref, 2, 0);
  CHECK(e_ref > 0.0, "reference has energy");

  for (int ind_sw = 0; ind_sw < 2; ind_sw++) {
    for (int domain = 0; domain < (ind_sw ? 1 : 2); domain++) {
      /* The CPE plus a coupling channel with the same spectrum, coupled
       * to both channels with scale 2 (gain_element_scale 3): the left
       * channel gets gain 1 (first list), the right one gets 2^4. */
      start_frame(&w, frame, sizeof(frame));
      write_cpe(&w, 0, 160, 160);
      aac_bitwriter_write(&w, AAC_ELEM_CCE, 3);
      aac_bitwriter_write(&w, 0, 4);                   /* tag */
      aac_bitwriter_write(&w, (uint32_t)ind_sw, 1);    /* ind_sw_cce_flag */
      aac_bitwriter_write(&w, 0, 3);                   /* num_coupled_elements - 1 */
      aac_bitwriter_write(&w, 1, 1);                   /* cc_target_is_cpe */
      aac_bitwriter_write(&w, 0, 4);                   /* cc_target_tag_select */
      aac_bitwriter_write(&w, 3, 2);                   /* cc_l, cc_r */
      aac_bitwriter_write(&w, (uint32_t)domain, 1);    /* cc_domain */
      aac_bitwriter_write(&w, 0, 1);                   /* gain_element_sign */
      aac_bitwriter_write(&w, 3, 2);                   /* gain_element_scale: 2 */
      write_ics(&w, 160, 1);
      if (!ind_sw) {
        aac_bitwriter_write(&w, 1, 1); /* common_gain_element_present for list 1 */
      }
      aac_bitwriter_write_scalefactor(&w, -4); /* gain 2^4 */
      end_block(&w);
      len = finish_frame(&w, frame, 2, 1);
      dec = aac_decoder_create(48000, 2);
      CHECK(aac_decoder_decode(dec, frame, len, pcm, 2048) == 1024, "CCE frame ind_sw=%d domain=%d",
            ind_sw, domain);
      aac_decoder_destroy(dec);
      double err_l = 0.0;
      double err_r = 0.0;
      for (int i = 0; i < 1024; i++) {
        err_l += pow(pcm[2 * i] - 2.0f * ref[2 * i], 2);
        err_r += pow(pcm[2 * i + 1] - 17.0f * ref[2 * i + 1], 2);
      }
      CHECK(err_l < 1e-9 * e_ref, "ind_sw=%d domain=%d: left is not doubled (%g)", ind_sw, domain,
            err_l / e_ref);
      CHECK(err_r < 1e-9 * e_ref * 289.0, "ind_sw=%d domain=%d: right gain is not 17 (%g)", ind_sw,
            domain, err_r / e_ref);
    }
  }
}

static void test_multiple_blocks() {
  /* One ADTS frame with two raw data blocks decodes 2048 samples. */
  uint8_t frame[1024];
  float pcm[4096];
  AacBitWriter w;
  start_frame(&w, frame, sizeof(frame));
  write_cpe(&w, 0, 160, 160);
  end_block(&w);
  write_cpe(&w, 0, 160, 160);
  end_block(&w);
  const int len = finish_frame(&w, frame, 2, 2);
  AacDecoderHandle dec = aac_decoder_create(48000, 2);
  CHECK(aac_decoder_decode(dec, frame, len, pcm, 4096) == 2048, "two raw data blocks");
  CHECK(aac_decoder_decode(dec, frame, len, pcm, 3000) == AAC_ERR_OVERFLOW,
        "buffer too small for the second block");
  aac_decoder_destroy(dec);
}

static void test_lfe_and_reset() {
  /* 5.1 (configuration 6) with the LFE carrying the strongest signal, then
   * a stereo frame: the layout change resets the state and the output
   * shrinks to two channels. */
  uint8_t frame[1024];
  float pcm[AAC_DECODER_MAX_OUTPUT * 8];
  AacBitWriter w;
  start_frame(&w, frame, sizeof(frame));
  write_sce(&w, AAC_ELEM_SCE, 0, 140);
  write_cpe(&w, 0, 150, 150);
  write_cpe(&w, 1, 130, 130);
  write_sce(&w, AAC_ELEM_LFE, 0, 170);
  end_block(&w);
  int len = finish_frame(&w, frame, 6, 1);
  AacDecoderHandle dec = aac_decoder_create(48000, 0);
  CHECK(aac_decoder_decode(dec, frame, len, pcm, (int)(sizeof(pcm) / sizeof(float))) == 1024,
        "5.1 frame");
  CHECK(aac_decoder_channels(dec) == 6, "six output channels");
  CHECK(energy(pcm, 6, 3) > energy(pcm, 6, 0), "LFE in slot 3");
  start_frame(&w, frame, sizeof(frame));
  write_cpe(&w, 0, 160, 160);
  end_block(&w);
  len = finish_frame(&w, frame, 2, 1);
  CHECK(aac_decoder_decode(dec, frame, len, pcm, (int)(sizeof(pcm) / sizeof(float))) == 1024,
        "stereo frame after 5.1");
  CHECK(aac_decoder_channels(dec) == 2 && aac_decoder_stream_channels(dec) == 2,
        "layout followed the header");
  CHECK(aac_decoder_reset(dec) == AAC_OK, "reset");
  aac_decoder_destroy(dec);
}

int main() {
  test_layout_module();
  test_pce_stream();
  test_sniffed_layout();
  test_coupling();
  test_multiple_blocks();
  test_lfe_and_reset();
  return test_summary("test_layouts");
}
