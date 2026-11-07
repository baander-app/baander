/*
 * bAAC decoder wrapper for libavcodec
 * Copyright (c) 2026 Baander
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may
 * not use this file except in compliance with the License. You may obtain a
 * copy of the License at http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations
 * under the License.
 *
 * Raw access units arrive with the AudioSpecificConfig in extradata; ADTS
 * frames arrive whole from the parser. The decoder reports its own layout
 * and rate per frame, which change when SBR or parametric stereo appear.
 */

#include <baac/aac.h>

#include "libavutil/channel_layout.h"
#include "libavutil/common.h"
#include "libavutil/mem.h"
#include "libavutil/opt.h"
#include "avcodec.h"
#include "codec_internal.h"
#include "decode.h"

#define BAAC_DEC_MAX_CHANNELS 8

typedef struct BaacDecContext {
    const AVClass *class;
    AacDecoderHandle dec;
    float *pcm;
    int pcm_floats;
    int conceal;
} BaacDecContext;

#define OFFSET(x) offsetof(BaacDecContext, x)
#define AD AV_OPT_FLAG_AUDIO_PARAM | AV_OPT_FLAG_DECODING_PARAM
static const AVOption baac_dec_options[] = {
    { "conceal", "Error concealment", OFFSET(conceal), AV_OPT_TYPE_INT, { .i64 = AAC_CONCEAL_INTERPOLATE }, 0, 2, AD, .unit = "conceal" },
    { "off",         "A damaged frame is an error",                     0, AV_OPT_TYPE_CONST, { .i64 = AAC_CONCEAL_OFF },         0, 0, AD, .unit = "conceal" },
    { "noise",       "Refill the last good band energies with noise",   0, AV_OPT_TYPE_CONST, { .i64 = AAC_CONCEAL_NOISE },       0, 0, AD, .unit = "conceal" },
    { "interpolate", "Fade the last good spectrum, then noise",         0, AV_OPT_TYPE_CONST, { .i64 = AAC_CONCEAL_INTERPOLATE }, 0, 0, AD, .unit = "conceal" },
    { NULL }
};

static const AVClass baac_dec_class = {
    .class_name = "libbaac decoder",
    .item_name  = av_default_item_name,
    .option     = baac_dec_options,
    .version    = LIBAVUTIL_VERSION_INT,
};

static const char *baac_error(int err)
{
    switch (err) {
    case AAC_ERR_INVALID_ARG:  return "invalid argument";
    case AAC_ERR_MEMORY:       return "out of memory";
    case AAC_ERR_INIT:         return "initialisation failed";
    case AAC_ERR_DECODE:       return "damaged frame";
    case AAC_ERR_UNSUPPORTED:  return "unsupported stream";
    case AAC_ERR_OVERFLOW:     return "output buffer too small";
    case AAC_ERR_STATE:        return "wrong decoder state";
    default:                   return "unknown error";
    }
}

static av_cold int baac_decode_close(AVCodecContext *avctx)
{
    BaacDecContext *s = avctx->priv_data;

    if (s->dec) {
        int concealed = aac_decoder_concealed_frames(s->dec);
        if (concealed > 0)
            av_log(avctx, AV_LOG_VERBOSE, "%d frame(s) concealed\n", concealed);
        aac_decoder_destroy(s->dec);
    }
    s->dec = NULL;
    av_freep(&s->pcm);

    return 0;
}

static av_cold int baac_decode_init(AVCodecContext *avctx)
{
    BaacDecContext *s = avctx->priv_data;
    int ret;

    /* The rate given here only seeds a raw stream without configuration;
     * ADTS headers and the AudioSpecificConfig carry the real one. */
    s->dec = aac_decoder_create(avctx->sample_rate > 0 ? avctx->sample_rate : 48000, 0);
    if (!s->dec) {
        av_log(avctx, AV_LOG_ERROR, "Unable to create the bAAC decoder\n");
        return AVERROR(EINVAL);
    }

    if (avctx->extradata_size > 0) {
        ret = aac_decoder_set_config(s->dec, avctx->extradata, avctx->extradata_size);
        if (ret != AAC_OK) {
            av_log(avctx, AV_LOG_ERROR, "Unable to apply the AudioSpecificConfig: %s\n",
                   baac_error(ret));
            return ret == AAC_ERR_UNSUPPORTED ? AVERROR_PATCHWELCOME : AVERROR_INVALIDDATA;
        }
    }

    ret = aac_decoder_set_concealment(s->dec, (AacConcealment)s->conceal);
    if (ret != AAC_OK) {
        av_log(avctx, AV_LOG_ERROR, "Unable to set the concealment mode: %s\n", baac_error(ret));
        return AVERROR(EINVAL);
    }

    s->pcm_floats = AAC_DECODER_MAX_OUTPUT * BAAC_DEC_MAX_CHANNELS;
    s->pcm = av_malloc_array(s->pcm_floats, sizeof(*s->pcm));
    if (!s->pcm)
        return AVERROR(ENOMEM);

    avctx->sample_fmt = AV_SAMPLE_FMT_FLT;

    return 0;
}

/* bAAC emits the canonical order L, R, C, LFE, Ls, Rs; the plain
 * configurations map onto FFmpeg's back-surround layouts. */
static void baac_set_layout(AVCodecContext *avctx, int channels)
{
    static const AVChannelLayout layouts[] = {
        AV_CHANNEL_LAYOUT_MONO,
        AV_CHANNEL_LAYOUT_STEREO,
        AV_CHANNEL_LAYOUT_SURROUND,
        AV_CHANNEL_LAYOUT_4POINT0,
        AV_CHANNEL_LAYOUT_5POINT0_BACK,
        AV_CHANNEL_LAYOUT_5POINT1_BACK,
    };

    if (avctx->ch_layout.nb_channels == channels &&
        avctx->ch_layout.order != AV_CHANNEL_ORDER_UNSPEC)
        return;
    av_channel_layout_uninit(&avctx->ch_layout);
    if (channels >= 1 && channels <= (int)FF_ARRAY_ELEMS(layouts)) {
        av_channel_layout_copy(&avctx->ch_layout, &layouts[channels - 1]);
    } else {
        avctx->ch_layout.order       = AV_CHANNEL_ORDER_UNSPEC;
        avctx->ch_layout.nb_channels = channels;
    }
}

static int baac_decode_frame(AVCodecContext *avctx, AVFrame *frame,
                             int *got_frame_ptr, AVPacket *avpkt)
{
    BaacDecContext *s = avctx->priv_data;
    int samples, channels, ret;

    if (avpkt->size <= 0)
        return avpkt->size;

    samples = aac_decoder_decode(s->dec, avpkt->data, avpkt->size, s->pcm, s->pcm_floats);
    if (samples < 0) {
        av_log(avctx, AV_LOG_ERROR, "Unable to decode frame: %s\n", baac_error(samples));
        return samples == AAC_ERR_UNSUPPORTED ? AVERROR_PATCHWELCOME : AVERROR_INVALIDDATA;
    }
    if (samples == 0)
        return avpkt->size;

    channels = aac_decoder_channels(s->dec);
    if (channels <= 0 || channels > BAAC_DEC_MAX_CHANNELS) {
        av_log(avctx, AV_LOG_ERROR, "Unsupported channel count %d\n", channels);
        return AVERROR_INVALIDDATA;
    }
    avctx->sample_rate = aac_decoder_output_sample_rate(s->dec);
    baac_set_layout(avctx, channels);

    frame->nb_samples = samples;
    if ((ret = ff_get_buffer(avctx, frame, 0)) < 0)
        return ret;
    memcpy(frame->extended_data[0], s->pcm, (size_t)samples * channels * sizeof(float));
    if (aac_decoder_last_frame_concealed(s->dec))
        frame->flags |= AV_FRAME_FLAG_CORRUPT;

    *got_frame_ptr = 1;
    return avpkt->size;
}

static av_cold void baac_decode_flush(AVCodecContext *avctx)
{
    BaacDecContext *s = avctx->priv_data;

    if (s->dec)
        aac_decoder_clear_history(s->dec);
}

const FFCodec ff_libbaac_decoder = {
    .p.name         = "libbaac",
    CODEC_LONG_NAME("bAAC (Baander AAC)"),
    .p.type         = AVMEDIA_TYPE_AUDIO,
    .p.id           = AV_CODEC_ID_AAC,
    .priv_data_size = sizeof(BaacDecContext),
    .init           = baac_decode_init,
    FF_CODEC_DECODE_CB(baac_decode_frame),
    .close          = baac_decode_close,
    .flush          = baac_decode_flush,
    .p.capabilities = AV_CODEC_CAP_DR1 | AV_CODEC_CAP_CHANNEL_CONF,
    .p.priv_class   = &baac_dec_class,
    .caps_internal  = FF_CODEC_CAP_INIT_CLEANUP,
    .p.wrapper_name = "libbaac",
};
