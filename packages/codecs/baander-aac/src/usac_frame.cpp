#include <cstring>
#include <new>

#include "asc.h"
#include "decoder.h"
#include "mps212.h"
#include "sbr.h"
#include "unidrc.h"
#include "usac.h"
#include "usac_fd.h"
#include "usac_lpd.h"

/* The USAC frame walker (ISO/IEC 23003-3 6.1, UsacFrame()): the frame
 * has no element identifiers, so the configuration's element list drives
 * the parse.  Each channel element goes through the core coder, the
 * enhanced SBR and, for a pair with stereoConfigIndex > 0, MPEG Surround
 * 212; the extension elements carry the audio pre-roll of an immediate
 * playout frame, the MPEG-D DRC gains and fill.  The output of the frame
 * lands in the decoder's channel buffers in layout order, output_frame_
 * length samples per channel, with the loudness and DRC gains applied. */


/* usacExtElementPayloadLength (ISO/IEC 23003-3 Table 21): 8 bits, and
 * when they read 255 a 16-bit value less 2 is added. */
int aac_usac_ext_payload_length(AacBitReader* r) {
  int len = (int)aac_bitreader_read(r, 8);
  if (len == 255) {
    len += (int)aac_bitreader_read(r, 16) - 2;
  }
  return len;
}

namespace {

/* Element type of the layout for a USAC channel element. */
int layout_type(int usac_type) {
  switch (usac_type) {
    case AAC_USAC_SCE:
      return AAC_ELEM_SCE;
    case AAC_USAC_CPE:
      return AAC_ELEM_CPE;
    default:
      return AAC_ELEM_LFE;
  }
}

void destroy_elements(AacUsacState* u) {
  for (int i = 0; i < AAC_USAC_MAX_ELEMENTS; i++) {
    AacUsacElement* e = &u->elem[i];
    for (int c = 0; c < 2; c++) {
      aac_usac_fd_channel_destroy(e->core[c].fd);
      aac_usac_lpd_channel_destroy(e->core[c].lpd);
    }
    delete e->sbr;
    aac_mps212_destroy(e->mps);
  }
  memset(u->elem, 0, sizeof(u->elem));
  u->num_elements = 0;
  u->unidrc_elem = -1;
}

void reset_core_channel(AacUsacCoreChannel* ch, int position) {
  ch->prev_core_mode = -1;
  ch->noise_seed = position ? 0x10932u : 0x3039u;
  ch->prev_window_sequence = 0;
  ch->prev_window_shape = 0;
  memset(ch->overlap, 0, sizeof(ch->overlap));
  if (ch->fd) {
    aac_usac_fd_channel_reset(ch->fd);
  }
  if (ch->lpd) {
    aac_usac_lpd_channel_reset(ch->lpd);
  }
}

/* The MPEG-D DRC configuration of the stream: the uniDrcConfig() of the
 * UNI_DRC extension element and the loudnessInfoSet() of the
 * LOUDNESS_INFO configuration extension, then the stream layout and the
 * player's request.  Applied at configuration and again after a reset,
 * which makes the DRC module forget the stream. */
void configure_unidrc(AacUsacState* u) {
  const AacUsacConfig* c = &u->cfg;
  const uint8_t* drc_cfg = nullptr;
  int drc_len = 0;
  if (u->unidrc_elem >= 0) {
    drc_cfg = c->elem[u->unidrc_elem].ext_config;
    drc_len = c->elem[u->unidrc_elem].ext_config_len;
  }
  const uint8_t* loudness = nullptr;
  int loudness_len = 0;
  for (int i = 0; i < c->num_config_exts; i++) {
    if (c->config_ext[i].type == AAC_USAC_CFG_EXT_LOUDNESS_INFO) {
      loudness = c->config_ext[i].data;
      loudness_len = c->config_ext[i].len;
    }
  }
  aac_unidrc_set_config(u->drc, drc_cfg, drc_len, loudness, loudness_len);
  aac_unidrc_set_stream(u->drc, c->sample_rate, c->num_channels, c->output_frame_length);
  aac_unidrc_set_request(u->drc, &u->request);
}

/* The per-element states of the configuration in u->cfg, with the
 * layout's element indices. */
int build_elements(AacUsacState* u) {
  const AacUsacConfig* c = &u->cfg;
  int layout_index = 0;
  u->num_elements = c->num_elements;
  for (int i = 0; i < c->num_elements; i++) {
    const AacUsacElementConfig* ec = &c->elem[i];
    AacUsacElement* e = &u->elem[i];
    e->type = ec->type;
    e->layout_index = -1;
    if (ec->type == AAC_USAC_EXT) {
      if (ec->ext_type == AAC_USAC_EXT_UNI_DRC && u->unidrc_elem < 0) {
        u->unidrc_elem = i;
      }
      continue;
    }
    e->layout_index = layout_index++;
    const int sci = ec->type == AAC_USAC_CPE ? ec->stereo_config_index : 0;
    e->num_output_channels = ec->type == AAC_USAC_CPE ? 2 : 1;
    /* A pair with stereoConfigIndex 1 codes the downmix alone; with 2 or
     * 3 the second core channel is the residual (7.11.2.1).  The SBR data
     * is that of the downmix with 1 and 2, of both outputs with 0 and 3;
     * an LFE carries none and is only upsampled through the SBR
     * filterbanks when the stream runs SBR, so that every channel keeps
     * the output rate and delay. */
    e->num_core_channels = ec->type == AAC_USAC_CPE && sci != 1 ? 2 : 1;
    if (c->sbr_ratio_index == 0 || ec->type == AAC_USAC_LFE) {
      e->num_sbr_channels = 0;
    } else {
      e->num_sbr_channels = sci == 1 || sci == 2 ? 1 : e->num_output_channels;
    }
    e->mps_before_sbr = sci == 3;
    for (int ch = 0; ch < e->num_core_channels; ch++) {
      AacUsacCoreChannel* core = &e->core[ch];
      core->fd = aac_usac_fd_channel_create(c->frame_length, c->core_sample_rate);
      /* An LFE is frequency-domain only (6.2.11). */
      core->lpd = ec->type == AAC_USAC_LFE
                      ? nullptr
                      : aac_usac_lpd_channel_create(c->frame_length, c->core_sample_rate);
      if (!core->fd || (!core->lpd && ec->type != AAC_USAC_LFE)) {
        return AAC_ERR_MEMORY;
      }
      reset_core_channel(core, ch);
    }
    if (c->sbr_ratio_index > 0) {
      e->sbr = new (std::nothrow) AacSbrState();
      if (!e->sbr) {
        return AAC_ERR_MEMORY;
      }
      aac_sbr_usac_init(e->sbr, c->core_sample_rate, c->frame_length, c->sbr_ratio_index, &ec->sbr);
    }
    if (sci > 0) {
      e->mps =
          aac_mps212_create(&ec->mps, sci, c->frame_length, c->output_frame_length, c->sample_rate);
      if (!e->mps) {
        return AAC_ERR_MEMORY;
      }
    }
  }
  return AAC_OK;
}

/* `nbits` bits from the reader into `out`, byte aligned. */
void copy_bits(AacBitReader* r, uint8_t* out, int nbits) {
  int i = 0;
  for (; i + 8 <= nbits; i += 8) {
    out[i >> 3] = (uint8_t)aac_bitreader_read(r, 8);
  }
  if (i < nbits) {
    out[i >> 3] = (uint8_t)(aac_bitreader_read(r, nbits - i) << (8 - (nbits - i)));
  }
}

/* The decoder's buffers of a channel element's output channels. */
AacDecoderChannel* output_channels(AacDecoderState* s, const AacUsacElement* e) {
  return &s->ch[s->elem_base[e->layout_index]];
}

/* From the core output of an element (u->core[]) to its output channels:
 * copied as it is without SBR, through the SBR in place, or through the
 * MPS 212 coupling in the QMF domain.
 *
 * With stereoConfigIndex 1 and 2 the SBR reconstructs the downmix, MPS
 * 212 splits it into left and right (adding the residual with 2), and
 * both are synthesised (7.11.2.3).  With stereoConfigIndex 3 the
 * standard runs MPS 212 on the core-band QMF of the downmix and the
 * residual first, and the two-channel UsacSbrData then reconstructs the
 * high band of each output.  The SBR interface reconstructs the high
 * band while it analyses (aac_sbr_usac_apply_to_qmf), so this walker
 * issues the same call sequence for 3 as for 1 and 2 and marks the
 * element (mps_before_sbr); the SBR module can honour the order by
 * leaving only the analysed low band in apply_to_qmf for a two-channel
 * state and reconstructing each channel's high band in
 * aac_sbr_usac_synthesize.  The oracle comparison decides once the
 * modules exist; the switch is this flag. */
void render_element(AacDecoderState* s, AacUsacState* u, AacUsacElement* e) {
  const AacUsacConfig* c = &u->cfg;
  AacDecoderChannel* chans = output_channels(s, e);
  if (!e->mps) {
    for (int ch = 0; ch < e->num_output_channels; ch++) {
      memcpy(chans[ch].output, u->core[ch], (size_t)c->frame_length * sizeof(float));
    }
    if (e->sbr) {
      aac_sbr_usac_apply(e->sbr, chans[0].output,
                         e->num_output_channels == 2 ? chans[1].output : nullptr);
    }
    return;
  }
  const float* residual = e->num_core_channels == 2 ? u->core[1] : nullptr;
  AacUsacQmfFrame* dmx = &u->qmf[0];
  AacUsacQmfFrame* left = &u->qmf[1];
  AacUsacQmfFrame* right = &u->qmf[2];
  aac_sbr_usac_apply_to_qmf(e->sbr, 0, u->core[0], dmx);
  aac_mps212_apply(e->mps, dmx, residual, left, right);
  aac_sbr_usac_synthesize(e->sbr, 0, left, chans[0].output);
  aac_sbr_usac_synthesize(e->sbr, 1, right, chans[1].output);
}

/* UsacSingleChannelElement(), UsacChannelPairElement() and
 * UsacLfeElement() (Tables 17 to 19): UsacCoreCoderData(), then
 * UsacSbrData() with SBR, then Mps212Data() with stereoConfigIndex > 0. */
int decode_channel_element(AacDecoderState* s, AacUsacState* u, int index, int indep,
                           AacBitReader* r) {
  const AacUsacConfig* c = &u->cfg;
  const AacUsacElementConfig* ec = &c->elem[index];
  AacUsacElement* e = &u->elem[index];
  AacUsacCoreChannel* chs[2] = {&e->core[0], &e->core[1]};
  float* out[2] = {u->core[0], u->core[1]};
  int ret = aac_usac_core_decode(chs, e->num_core_channels, e->type == AAC_USAC_LFE,
                                 ec->noise_filling, c->frame_length, indep, r, out);
  if (ret) {
    return ret;
  }
  if (e->num_sbr_channels > 0) {
    ret = aac_sbr_usac_decode(e->sbr, r, layout_type(e->type), e->num_sbr_channels, indep);
    if (ret) {
      return ret;
    }
  }
  if (e->mps) {
    ret = aac_mps212_read(e->mps, r, indep);
    if (ret) {
      return ret;
    }
  }
  if (r->overread) {
    return AAC_ERR_DECODE;
  }
  render_element(s, u, e);
  return AAC_OK;
}

int decode_preroll(AacDecoderState* s, AacUsacState* u, int index, const uint8_t* payload, int len);

/* UsacExtElement() (Table 24): the presence flag, the payload length
 * (the configured default or an escaped value) and the payload, which is
 * dispatched on the configured usacExtElementType.  Fragmented payloads
 * (usacExtElementPayloadFrag) are outside what the decoder handles. */
int decode_ext_element(AacDecoderState* s, AacUsacState* u, int index, int indep, AacBitReader* r,
                       bool* drc_gain_seen) {
  const AacUsacElementConfig* ec = &u->cfg.elem[index];
  if (!aac_bitreader_read(r, 1)) { /* usacExtElementPresent */
    return r->overread ? AAC_ERR_DECODE : AAC_OK;
  }
  int len;
  if (aac_bitreader_read(r, 1)) { /* usacExtElementUseDefaultLength */
    if (ec->ext_default_length == 0) {
      return AAC_ERR_DECODE;
    }
    len = ec->ext_default_length;
  } else {
    len = aac_usac_ext_payload_length(r);
  }
  if (ec->ext_payload_frag) {
    return AAC_ERR_UNSUPPORTED;
  }
  if (r->overread || aac_bitreader_bits_left(r) < 8 * len) {
    return AAC_ERR_DECODE;
  }
  switch (ec->ext_type) {
    case AAC_USAC_EXT_UNI_DRC: {
      const int end = r->pos + 8 * len;
      const int ret = aac_unidrc_read_gain(u->drc, r, 8 * len, indep);
      if (ret) {
        return ret;
      }
      *drc_gain_seen = true;
      /* The payload is a whole number of bytes whatever the gain syntax
       * consumed. */
      r->pos = end;
      return AAC_OK;
    }
    case AAC_USAC_EXT_AUDIOPREROLL: {
      if (len > AAC_USAC_MAX_EXT_PAYLOAD) {
        return AAC_ERR_UNSUPPORTED;
      }
      copy_bits(r, u->ext_payload, 8 * len);
      return decode_preroll(s, u, index, u->ext_payload, len);
    }
    default: /* ID_EXT_ELE_FILL and unknown types are skipped */
      aac_bitreader_skip(r, 8 * len);
      return r->overread ? AAC_ERR_DECODE : AAC_OK;
  }
}

/* The frame's elements after usacIndependencyFlag. */
int decode_elements(AacDecoderState* s, AacUsacState* u, int indep, AacBitReader* r) {
  bool drc_gain_seen = false;
  /* The element count is read afresh each time: an audio pre-roll may
   * reconfigure the stream and with it the list. */
  for (int i = 0; i < u->num_elements; i++) {
    const int ret = u->elem[i].type == AAC_USAC_EXT
                        ? decode_ext_element(s, u, i, indep, r, &drc_gain_seen)
                        : decode_channel_element(s, u, i, indep, r);
    if (ret) {
      return ret;
    }
  }
  if (r->overread) {
    return AAC_ERR_DECODE;
  }
  if (!drc_gain_seen) {
    aac_unidrc_no_gain(u->drc);
  }
  return AAC_OK;
}

/* The loudness normalisation and DRC gains over the frame's output
 * channels, in the stream's channel order (layout element order). */
void apply_unidrc(AacDecoderState* s, AacUsacState* u) {
  float* ch[AAC_MAX_CHANNELS];
  const int n = s->layout.num_channels;
  for (int i = 0; i < n; i++) {
    ch[i] = s->ch[i].output;
  }
  aac_unidrc_apply(u->drc, ch, n, u->cfg.output_frame_length);
}

/* An audio pre-roll (5.5.2.2): the embedded configuration replaces the
 * stream's when it differs, after which the frame continues under the
 * new element list (the pre-roll element must then still be the first
 * element).  A cold decoder (nothing decoded since the configuration or
 * a reset) decodes the pre-roll access units through this walker and
 * discards their output; a warm one skips them.  A pre-roll access unit
 * that fails to decode leaves the decoder as cold as it was; a tool the
 * decoder lacks is refused. */
int decode_preroll(AacDecoderState* s, AacUsacState* u, int index, const uint8_t* payload,
                   int len) {
  if (u->in_preroll) {
    return AAC_ERR_DECODE; /* a pre-roll inside a pre-roll frame */
  }
  AacUsacPreroll* p = &u->preroll;
  int ret = aac_usac_preroll_parse(payload, len, p);
  if (ret) {
    return ret;
  }
  if (p->has_config && !aac_usac_config_equal(&p->cfg, &u->cfg)) {
    if (index != 0) {
      return AAC_ERR_DECODE;
    }
    ret = aac_usac_apply_config(s, &p->cfg);
    if (ret) {
      return ret;
    }
    u = s->usac;
    if (u->num_elements == 0 || u->elem[0].type != AAC_USAC_EXT ||
        u->cfg.elem[0].ext_type != AAC_USAC_EXT_AUDIOPREROLL) {
      return AAC_ERR_DECODE;
    }
  }
  if (u->warm) {
    return AAC_OK;
  }
  u->in_preroll = 1;
  u->preroll_frames_decoded = 0;
  for (int i = 0; i < p->num_frames; i++) {
    AacBitReader r;
    aac_bitreader_init(&r, p->data + p->au_offset[i], p->au_len[i]);
    ret = aac_usac_decode_frame(s, &r);
    if (ret == AAC_ERR_UNSUPPORTED) {
      u->in_preroll = 0;
      return ret;
    }
    if (ret == AAC_OK) {
      u->preroll_frames_decoded++;
    }
  }
  u->in_preroll = 0;
  return AAC_OK;
}

}  // namespace

AacUsacState* aac_usac_state_create(void) {
  auto* u = new (std::nothrow) AacUsacState();
  if (!u) {
    return nullptr;
  }
  memset(u, 0, sizeof(*u));
  u->unidrc_elem = -1;
  u->drc = aac_unidrc_create();
  if (!u->drc) {
    delete u;
    return nullptr;
  }
  return u;
}

void aac_usac_state_destroy(AacUsacState* u) {
  if (!u) {
    return;
  }
  destroy_elements(u);
  aac_unidrc_destroy(u->drc);
  delete u;
}

void aac_usac_state_reset(AacUsacState* u) {
  for (int i = 0; i < u->num_elements; i++) {
    AacUsacElement* e = &u->elem[i];
    for (int c = 0; c < e->num_core_channels; c++) {
      reset_core_channel(&e->core[c], c);
    }
    if (e->sbr) {
      aac_sbr_usac_init(e->sbr, u->cfg.core_sample_rate, u->cfg.frame_length,
                        u->cfg.sbr_ratio_index, &u->cfg.elem[i].sbr);
    }
    if (e->mps) {
      aac_mps212_reset(e->mps);
    }
  }
  aac_unidrc_reset(u->drc);
  if (u->num_elements > 0) {
    configure_unidrc(u);
  }
  u->warm = 0;
  u->in_preroll = 0;
  u->indep = 0;
  u->preroll_frames_decoded = 0;
}

int aac_usac_apply_config(AacDecoderState* s, const AacUsacConfig* c) {
  const int rate_index = aac_asc_rate_index(c->sample_rate);
  if (rate_index < 0) {
    return AAC_ERR_UNSUPPORTED; /* an output rate the decoder has no tables for */
  }
  AacLayout layout = {};
  int ret = aac_usac_config_layout(c, &layout);
  if (ret) {
    return ret;
  }
  if (s->usac && s->aot == AAC_AOT_USAC && s->layout_source != 0 &&
      aac_usac_config_equal(&s->usac->cfg, c)) {
    return AAC_OK;
  }
  if (!s->usac) {
    s->usac = aac_usac_state_create();
    if (!s->usac) {
      return AAC_ERR_MEMORY;
    }
  }
  AacUsacState* u = s->usac;
  destroy_elements(u);
  u->cfg = *c;
  u->request = s->unidrc_request;
  ret = build_elements(u);
  if (ret == 0) {
    ret = aac_decoder_set_object_type(s, AAC_AOT_USAC);
  }
  if (ret == 0) {
    ret = aac_decoder_set_rate_index(s, rate_index);
  }
  if (ret == 0) {
    ret = aac_decoder_set_layout(s, &layout);
  }
  if (ret) {
    destroy_elements(u);
    s->layout_source = 0;
    return ret;
  }
  /* Whatever the steps above reset, the stream starts cold here; the
   * reset applies the DRC configuration. */
  aac_decoder_reset_state(s);
  return AAC_OK;
}

int aac_usac_decode_frame(AacDecoderState* s, AacBitReader* r) {
  AacUsacState* u = s->usac;
  if (!u || s->layout_source == 0 || s->aot != AAC_AOT_USAC) {
    return AAC_ERR_STATE;
  }
  const int indep = (int)aac_bitreader_read(r, 1);
  u->indep = indep;
  const int ret = decode_elements(s, u, indep, r);
  if (ret) {
    return ret == AAC_ERR_UNSUPPORTED || ret == AAC_ERR_MEMORY ? ret : AAC_ERR_DECODE;
  }
  /* The pre-roll may have replaced the state. */
  u = s->usac;
  apply_unidrc(s, u);
  u->warm = 1;
  return AAC_OK;
}

int aac_usac_conceal_frame(AacDecoderState* s) {
  AacUsacState* u = s->usac;
  if (!u || s->layout_source == 0 || s->aot != AAC_AOT_USAC) {
    return AAC_ERR_STATE;
  }
  const int fl = u->cfg.frame_length;
  for (int i = 0; i < u->num_elements; i++) {
    AacUsacElement* e = &u->elem[i];
    if (e->type == AAC_USAC_EXT) {
      continue;
    }
    for (int c = 0; c < e->num_core_channels; c++) {
      AacUsacCoreChannel* ch = &e->core[c];
      float* out = u->core[c];
      if (ch->prev_core_mode == 1) {
        aac_usac_lpd_conceal(ch, fl, out);
      } else {
        aac_usac_core_conceal(&ch, 1, fl, &out);
      }
    }
    if (e->sbr) {
      aac_sbr_conceal(e->sbr);
    }
    if (e->mps) {
      aac_mps212_conceal(e->mps);
    }
    render_element(s, u, e);
  }
  aac_unidrc_no_gain(u->drc);
  apply_unidrc(s, u);
  return AAC_OK;
}

int aac_usac_preroll_parse(const uint8_t* data, int len, AacUsacPreroll* p) {
  if (!data || len <= 0 || !p) {
    return AAC_ERR_INVALID_ARG;
  }
  p->has_config = 0;
  p->apply_crossfade = 0;
  p->num_frames = 0;
  AacBitReader r;
  aac_bitreader_init(&r, data, len);
  const int config_len = (int)aac_usac_escaped_value(&r, 4, 4, 8);
  if (r.overread || aac_bitreader_bits_left(&r) < 8 * config_len) {
    return AAC_ERR_DECODE;
  }
  if (config_len > 0) {
    /* The UsacConfig() sits in configLen bytes, padded at the end. */
    const int start = r.pos;
    const int ret = aac_usac_config_read(&r, &p->cfg);
    if (ret) {
      return ret;
    }
    if (r.pos - start > 8 * config_len) {
      return AAC_ERR_DECODE;
    }
    r.pos = start + 8 * config_len;
    p->has_config = 1;
  }
  p->apply_crossfade = (int)aac_bitreader_read(&r, 1);
  aac_bitreader_skip(&r, 1); /* reserved */
  const int n = (int)aac_usac_escaped_value(&r, 2, 4, 0);
  if (r.overread) {
    return AAC_ERR_DECODE;
  }
  if (n > AAC_USAC_MAX_PREROLL_FRAMES) {
    return AAC_ERR_UNSUPPORTED;
  }
  int used = 0;
  for (int i = 0; i < n; i++) {
    const int au_len = (int)aac_usac_escaped_value(&r, 16, 16, 0);
    if (r.overread || aac_bitreader_bits_left(&r) < 8 * au_len) {
      return AAC_ERR_DECODE;
    }
    if (used + au_len > AAC_USAC_MAX_EXT_PAYLOAD) {
      return AAC_ERR_UNSUPPORTED;
    }
    copy_bits(&r, p->data + used, 8 * au_len);
    p->au_offset[i] = used;
    p->au_len[i] = au_len;
    used += au_len;
    p->num_frames = i + 1;
  }
  return r.overread ? AAC_ERR_DECODE : AAC_OK;
}
