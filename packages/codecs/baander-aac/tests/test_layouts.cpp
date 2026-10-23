/* Channel layouts and multi-element streams on hand-built frames: the
 * layout module's speaker assignment, program config elements, LFE
 * elements, coupling channel elements (dependent and independent), ADTS
 * frames with several raw data blocks, and the output mapping; then the
 * 7.1 channel modes through the encoder and decoder (and FFmpeg's
 * decoder when ffmpeg is on PATH) and the MPEG-2 ADTS variant. */
#include "bitstream.h"
#include "channel_map.h"
#include "encoder.h"
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
  /* Configuration 14: C, L+R, Ls+Rs, LFE, then the top front pair in
   * slots 6 and 7. */
  CHECK(aac_layout_from_config(&l, 14) == 0 && l.num_channels == 8, "7.1 top front layout");
  CHECK(l.elems[0].slot[0] == 2 && l.elems[1].slot[0] == 0 && l.elems[2].slot[0] == 4 &&
            l.elems[3].slot[0] == 3 && l.elems[4].slot[0] == 6 && l.elems[4].slot[1] == 7,
        "7.1 top front slots");
  CHECK(l.elems[4].speaker[0] == AAC_SPK_TFL && l.elems[4].speaker[1] == AAC_SPK_TFR &&
            l.elems[4].height == AAC_HEIGHT_TOP,
        "top front pair on the top front speakers");
  CHECK(aac_layout_mode(&l) == AAC_MODE_7_1_TOP_FRONT, "configuration 14 is the top front mode");
  CHECK(aac_layout_config_for_elements(l.elems, 5) == 14, "SCE CPE CPE LFE CPE is configuration 14");

  /* The modes build their layouts and classify back. */
  CHECK(aac_layout_from_mode(&l, AAC_MODE_7_1_FRONT_CENTER) == 0 && l.channel_config == 7 &&
            aac_layout_mode(&l) == AAC_MODE_7_1_FRONT_CENTER,
        "front centre mode");
  CHECK(aac_layout_from_mode(&l, AAC_MODE_7_1_BACK) == 0 && l.channel_config == 12 &&
            aac_layout_mode(&l) == AAC_MODE_7_1_BACK,
        "back mode");
  CHECK(aac_layout_from_mode(&l, AAC_MODE_7_1_TOP_FRONT) == 0 && l.channel_config == 14 &&
            aac_layout_mode(&l) == AAC_MODE_7_1_TOP_FRONT,
        "top front mode");
  CHECK(aac_layout_from_mode(&l, AAC_MODE_7_1_REAR_SURROUND) == 0 && l.channel_config == 0 &&
            l.num_channels == 8 && aac_layout_mode(&l) == AAC_MODE_7_1_REAR_SURROUND,
        "rear surround mode");
  /* Rear surround: the side pair in slots 6 and 7, the back pair in 4 and 5. */
  CHECK(l.elems[2].position == AAC_POS_SIDE && l.elems[2].slot[0] == 6 &&
            l.elems[3].position == AAC_POS_BACK && l.elems[3].slot[0] == 4,
        "rear surround slots");
  CHECK(aac_layout_from_mode(&l, AAC_MODE_DEFAULT) == AAC_ERR_INVALID_ARG,
        "the default mode names no layout");
  for (int c = 1; c <= 7; c++) {
    if (c != 7 && aac_layout_from_config(&l, c) == 0) {
      CHECK(aac_layout_mode(&l) == AAC_MODE_DEFAULT, "configuration %d is a default mode", c);
    }
  }
  CHECK(aac_layout_from_config(&l, 11) == 0 && aac_layout_mode(&l) == AAC_MODE_DEFAULT,
        "6.1 is a default mode");

  /* Height CRC: the extension of configuration 14's elements (sync, then
   * 0 0 0 1 0 over the three front and one back element). */
  const uint8_t ext[3] = {AAC_PCE_HEIGHT_EXTENSION_SYNC, 0x04, 0x00};
  const uint8_t crc = aac_pce_height_crc(ext, 3);
  const uint8_t ext_bad[3] = {AAC_PCE_HEIGHT_EXTENSION_SYNC, 0x05, 0x00};
  CHECK(crc != aac_pce_height_crc(ext_bad, 3), "the CRC tells height words apart");
  const uint8_t one[1] = {0x00};
  CHECK(aac_pce_height_crc(one, 1) == 0xF3, "CRC-8 of a zero byte from 0xFF (%02x)",
        aac_pce_height_crc(one, 1));

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

  /* Downmix of 7.1 top front: the height pair joins the front at -3 dB. */
  aac_layout_from_config(&l, 14);
  float in8[8][1];
  const float* in8_ptr[8];
  for (int c = 0; c < 8; c++) {
    in8[c][0] = c == 6 ? 1.0f : 0.0f; /* Ltf alone */
    in8_ptr[c] = in8[c];
  }
  aac_layout_mix(&l, in8_ptr, 1, 2, out);
  CHECK(fabs(out[0] - 0.70710678f) < 1e-6 && out[1] == 0.0f, "top front left mixes left at -3 dB");
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

/* ── 7.1 channel modes through the codec ─────────────────────────── */

static const int kModeRate = 48000;

/* Eight channels, each a tone of its own frequency: 300 Hz for the first
 * slot, then 500, 700, ... (the LFE slot gets 60 Hz). */
static float slot_frequency(int slot) { return slot == 3 ? 60.0f : 300.0f + 200.0f * (float)slot; }

static std::vector<float> gen_mode_tones(int n) {
  std::vector<float> pcm((size_t)n * 8);
  for (int i = 0; i < n; i++) {
    const float t = (float)i / (float)kModeRate;
    for (int c = 0; c < 8; c++) {
      pcm[(size_t)i * 8 + c] = 0.4f * sinf(2.0f * (float)M_PI * slot_frequency(c) * t);
    }
  }
  return pcm;
}

/* Power of a tone in one channel of an interleaved signal (Goertzel). */
static double tone_power(const std::vector<float>& pcm, int channels, int channel, float freq,
                         size_t from, size_t to) {
  const double w = 2.0 * M_PI * freq / kModeRate;
  const double coef = 2.0 * cos(w);
  double s1 = 0.0;
  double s2 = 0.0;
  size_t n = 0;
  for (size_t i = from; i < to && i < pcm.size() / channels; i++, n++) {
    const double s0 = pcm[i * channels + channel] + coef * s1 - s2;
    s2 = s1;
    s1 = s0;
  }
  return (s1 * s1 + s2 * s2 - coef * s1 * s2) / (double)(n ? n : 1);
}

/* The input slot whose tone dominates a decoded channel, or -1 when no
 * tone stands at least 20 dB above the others. */
static int dominant_slot(const std::vector<float>& pcm, int channels, int channel) {
  const size_t frames = pcm.size() / channels;
  const size_t from = frames > 8192 ? 4096 : 0;
  double best = 0.0;
  double second = 0.0;
  int slot = -1;
  for (int c = 0; c < 8; c++) {
    const double p = tone_power(pcm, channels, channel, slot_frequency(c), from, frames);
    if (p > best) {
      second = best;
      best = p;
      slot = c;
    } else if (p > second) {
      second = p;
    }
  }
  return best > 100.0 * second ? slot : -1;
}

/* Encodes the eight-channel tones with a channel mode (the flush included). */
static std::vector<uint8_t> encode_mode(const std::vector<float>& pcm, AacChannelMode mode,
                                        int mpeg2, int channels, int bitrate) {
  std::vector<uint8_t> out;
  AacEncoderHandle enc = aac_encoder_create(kModeRate, channels, bitrate, AAC_AOT_LC, AAC_RC_CBR);
  CHECK(enc != nullptr, "encoder for %d channels", channels);
  if (!enc) {
    return out;
  }
  CHECK(aac_encoder_set_channel_mode(enc, mode) == AAC_OK, "set channel mode %d", (int)mode);
  CHECK(aac_encoder_set_mpeg2(enc, mpeg2) == AAC_OK, "set mpeg2");
  const int frame = aac_encoder_frame_size(enc);
  std::vector<float> block((size_t)frame * channels);
  std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc));
  for (size_t pos = 0; pos < pcm.size(); pos += block.size()) {
    std::fill(block.begin(), block.end(), 0.0f);
    const size_t n = std::min(block.size(), pcm.size() - pos);
    memcpy(block.data(), pcm.data() + pos, n * sizeof(float));
    const int len = aac_encoder_encode(enc, block.data(), frame, buf.data(), (int)buf.size());
    CHECK(len > 0, "encode frame (%d)", len);
    if (len < 0) {
      break;
    }
    out.insert(out.end(), buf.begin(), buf.begin() + len);
  }
  CHECK(aac_encoder_set_channel_mode(enc, mode) == AAC_ERR_STATE,
        "the mode cannot change while a stream is open");
  const int len = aac_encoder_flush(enc, buf.data(), (int)buf.size());
  if (len > 0) {
    out.insert(out.end(), buf.begin(), buf.begin() + len);
  }
  aac_encoder_destroy(enc);
  return out;
}

static const char* mode_name(AacChannelMode mode) {
  switch (mode) {
    case AAC_MODE_7_1_FRONT_CENTER:
      return "7.1 front centre";
    case AAC_MODE_7_1_BACK:
      return "7.1 back";
    case AAC_MODE_7_1_TOP_FRONT:
      return "7.1 top front";
    case AAC_MODE_7_1_REAR_SURROUND:
      return "7.1 rear surround";
    default:
      return "default";
  }
}

static std::string g_dir;

/* Decodes our stream with FFmpeg and finds which of its output channels
 * carries each input slot's tone, as FFmpeg does not promise our order. */
static void ffmpeg_mode(const char* name, const std::vector<uint8_t>& stream,
                        const std::vector<float>& ours) {
  const std::string aac = g_dir + "/mode.aac";
  const std::string f32 = g_dir + "/mode.f32";
  const std::string probe = g_dir + "/mode.txt";
  CHECK(write_file(aac, stream.data(), stream.size()), "write stream");
  CHECK(run_command("ffmpeg -nostdin -v error -y -i '" + aac + "' -f f32le '" + f32 + "'"),
        "%s: ffmpeg refused our stream", name);
  CHECK(system(("ffprobe -v error -show_entries stream=channels,channel_layout,profile "
                "-of csv=p=0 '" +
                aac + "' > '" + probe + "' 2>/dev/null")
                   .c_str()) == 0,
        "%s: ffprobe", name);
  std::vector<uint8_t> raw = read_file(probe);
  std::string report(raw.begin(), raw.end());
  while (!report.empty() && (report.back() == '\n' || report.back() == '\r')) {
    report.pop_back();
  }
  const std::vector<float> theirs = read_f32(f32);
  CHECK(theirs.size() % 8 == 0 && theirs.size() == ours.size(),
        "%s: ffmpeg decoded %zu samples, we %zu", name, theirs.size(), ours.size());
  if (theirs.size() != ours.size()) {
    return;
  }
  int order[8];
  bool complete = true;
  int found[8] = {};
  for (int c = 0; c < 8; c++) {
    order[c] = dominant_slot(theirs, 8, c);
    if (order[c] >= 0) {
      found[order[c]]++;
    }
    complete = complete && order[c] >= 0;
  }
  for (int c = 0; c < 8; c++) {
    complete = complete && found[c] == 1;
  }
  CHECK(complete, "%s: ffmpeg carries every tone once", name);
  printf("%-20s ffprobe: %s; ffmpeg channel -> our slot:", name, report.c_str());
  for (int c = 0; c < 8; c++) {
    printf(" %d", order[c]);
  }
  printf("\n");
  /* Reordered to our slots, the decodes must agree to floating point
   * precision. */
  if (complete) {
    std::vector<float> reordered(theirs.size());
    for (size_t i = 0; i < theirs.size() / 8; i++) {
      for (int c = 0; c < 8; c++) {
        reordered[i * 8 + order[c]] = theirs[i * 8 + c];
      }
    }
    const double d = snr_db(ours.data(), reordered.data(), ours.size());
    printf("%-20s ours vs ffmpeg (reordered): %.1f dB\n", name, d);
    CHECK(d > 90.0, "%s: decoders disagree (%.1f dB)", name, d);
  }
}

static void test_channel_modes() {
  const std::vector<float> pcm = gen_mode_tones(kModeRate * 2);
  const AacChannelMode modes[5] = {AAC_MODE_DEFAULT, AAC_MODE_7_1_FRONT_CENTER, AAC_MODE_7_1_BACK,
                                   AAC_MODE_7_1_TOP_FRONT, AAC_MODE_7_1_REAR_SURROUND};
  const bool ffmpeg = have_ffmpeg() && !g_dir.empty();
  for (const AacChannelMode mode : modes) {
    const char* name = mode_name(mode);
    const std::vector<uint8_t> stream = encode_mode(pcm, mode, 0, 8, 512000);
    CHECK(!stream.empty(), "%s: encoded", name);
    /* The ADTS header names configuration 7 only; the others carry a
     * PCE and configuration 0. */
    const int config = stream.size() > 3 ? ((stream[2] & 1) << 2) | (stream[3] >> 6) : -1;
    CHECK(config == (mode == AAC_MODE_7_1_FRONT_CENTER ? 7 : 0), "%s: ADTS channel config %d",
          name, config);
    int errors = 0;
    AacDecoderHandle dec = aac_decoder_create(kModeRate, 0);
    const std::vector<float> out = decode_stream(stream, kModeRate, 0, &errors, dec);
    CHECK(errors == 0, "%s: %d frames failed", name, errors);
    CHECK(aac_decoder_channels(dec) == 8, "%s: eight output channels", name);
    const int expect = mode == AAC_MODE_DEFAULT ? AAC_MODE_7_1_BACK : (int)mode;
    CHECK(aac_decoder_channel_mode(dec) == expect, "%s: decoder reports mode %d", name,
          aac_decoder_channel_mode(dec));
    aac_decoder_destroy(dec);
    for (int c = 0; c < 8; c++) {
      CHECK(dominant_slot(out, 8, c) == c, "%s: channel %d carries slot %d", name, c,
            dominant_slot(out, 8, c));
    }
    const double q = aligned_snr_db(pcm, out, 8, 2048);
    CHECK(q > 20.0, "%s: roundtrip quality %.1f dB", name, q);
    if (ffmpeg) {
      ffmpeg_mode(name, stream, out);
    }
  }
  /* A mode that needs eight channels is refused for six. */
  AacEncoderHandle enc = aac_encoder_create(kModeRate, 6, 320000, AAC_AOT_LC, AAC_RC_CBR);
  CHECK(aac_encoder_set_channel_mode(enc, AAC_MODE_7_1_BACK) == AAC_ERR_INVALID_ARG,
        "7.1 mode refused for 5.1");
  CHECK(aac_encoder_set_channel_mode(enc, AAC_MODE_DEFAULT) == AAC_OK, "default mode for 5.1");
  aac_encoder_destroy(enc);
  /* HE-AAC takes the modes too: the SBR encoders follow the elements. */
  enc = aac_encoder_create(kModeRate, 8, 256000, AAC_AOT_SBR, AAC_RC_CBR);
  CHECK(enc && aac_encoder_set_channel_mode(enc, AAC_MODE_7_1_TOP_FRONT) == AAC_OK,
        "top front mode with SBR");
  if (enc) {
    const int frame = aac_encoder_frame_size(enc);
    std::vector<float> block((size_t)frame * 8);
    std::vector<uint8_t> buf((size_t)aac_encoder_max_frame_bytes(enc));
    for (int i = 0; i < 4; i++) {
      memcpy(block.data(), pcm.data() + (size_t)i * block.size(), block.size() * sizeof(float));
      CHECK(aac_encoder_encode(enc, block.data(), frame, buf.data(), (int)buf.size()) > 0,
            "SBR frame in top front mode");
    }
    aac_encoder_destroy(enc);
  }
}

static void test_mpeg2() {
  /* MPEG-2 ADTS: the id bit is set in every frame, the profile stays LC,
   * noise substitution is off, both decoders agree. */
  const int n = kModeRate * 2;
  const std::vector<float> pcm = gen_noise(n, 2);
  AacEncoderHandle enc = aac_encoder_create(kModeRate, 2, 64000, AAC_AOT_LC, AAC_RC_CBR);
  auto* state = static_cast<AacEncoderState*>(enc);
  CHECK(state->use_pns == 1, "64 kbit/s stereo CBR uses PNS in MPEG-4");
  aac_encoder_destroy(enc);
  const std::vector<uint8_t> stream = encode_mode(pcm, AAC_MODE_DEFAULT, 1, 2, 64000);
  CHECK(!stream.empty(), "mpeg2: encoded");
  enc = aac_encoder_create(kModeRate, 2, 64000, AAC_AOT_LC, AAC_RC_CBR);
  CHECK(aac_encoder_set_mpeg2(enc, 1) == AAC_OK && static_cast<AacEncoderState*>(enc)->use_pns == 0,
        "mpeg2 turns PNS off");
  aac_encoder_destroy(enc);
  int frames = 0;
  bool all_mpeg2 = true;
  for (const AdtsFrame& f : split_adts(stream)) {
    all_mpeg2 = all_mpeg2 && (stream[f.offset + 1] & 0x08) != 0;
    frames++;
  }
  CHECK(frames > 0 && all_mpeg2, "every ADTS header has id 1 (%d frames)", frames);
  int errors = 0;
  const std::vector<float> ours = decode_stream(stream, kModeRate, 2, &errors);
  CHECK(errors == 0 && ours.size() > 0, "our decoder takes MPEG-2 ADTS (%d errors)", errors);
  const std::vector<uint8_t> mpeg4 = encode_mode(pcm, AAC_MODE_DEFAULT, 0, 2, 64000);
  CHECK(mpeg4.size() != stream.size() || mpeg4 != stream, "the MPEG-4 stream differs");
  if (have_ffmpeg() && !g_dir.empty()) {
    const std::string aac = g_dir + "/mpeg2.aac";
    const std::string f32 = g_dir + "/mpeg2.f32";
    const std::string probe = g_dir + "/mpeg2.txt";
    CHECK(write_file(aac, stream.data(), stream.size()), "write stream");
    CHECK(run_command("ffmpeg -nostdin -v error -y -i '" + aac + "' -f f32le '" + f32 + "'"),
          "mpeg2: ffmpeg refused our stream");
    const std::vector<float> theirs = read_f32(f32);
    CHECK(theirs.size() == ours.size(), "mpeg2: %zu vs %zu samples", theirs.size(), ours.size());
    const size_t m = std::min(theirs.size(), ours.size());
    const double d = snr_db(theirs.data(), ours.data(), m);
    printf("%-20s ours vs ffmpeg: %.1f dB\n", "mpeg2 adts", d);
    CHECK(d > 90.0, "mpeg2: decoders disagree (%.1f dB)", d);
    CHECK(system(("ffprobe -v error -show_entries stream=profile -of csv=p=0 '" + aac + "' > '" +
                  probe + "' 2>/dev/null")
                     .c_str()) == 0,
          "mpeg2: ffprobe");
    const std::vector<uint8_t> raw = read_file(probe);
    const std::string report(raw.begin(), raw.end());
    printf("%-20s ffprobe profile: %s", "mpeg2 adts", report.c_str());
    CHECK(report.rfind("LC", 0) == 0, "mpeg2: ffprobe reports profile LC");
  }
}

int main() {
  test_layout_module();
  test_pce_stream();
  test_sniffed_layout();
  test_coupling();
  test_multiple_blocks();
  test_lfe_and_reset();
  g_dir = have_ffmpeg() ? make_temp_dir() : std::string();
  test_channel_modes();
  test_mpeg2();
  remove_temp_dir(g_dir);
  return test_summary("test_layouts");
}
