/*
 * Bånder Audio Core encoder wrapper for libavcodec
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
 * The wrapper always emits raw access units and publishes the
 * AudioSpecificConfig as extradata; the adts and mp4 muxers frame them.
 */

#include <bac/aac.h>

#include "libavutil/channel_layout.h"
#include "libavutil/common.h"
#include "libavutil/intreadwrite.h"
#include "libavutil/mem.h"
#include "libavutil/opt.h"
#include "avcodec.h"
#include "audio_frame_queue.h"
#include "codec_internal.h"
#include "encode.h"
#include "profiles.h"

typedef struct BacEncContext {
    const AVClass *class;
    AacEncoderHandle enc;
    int afterburner;
    int rc;
    int quality;
    float *frame_buf;
    int frame_floats;
    int max_frame_bytes;
    int delay_sent;
    AudioFrameQueue afq;
} BacEncContext;

#define OFFSET(x) offsetof(BacEncContext, x)
#define AE AV_OPT_FLAG_AUDIO_PARAM | AV_OPT_FLAG_ENCODING_PARAM
static const AVOption bac_enc_options[] = {
    { "afterburner", "Second quantisation pass (library default when unset)", OFFSET(afterburner), AV_OPT_TYPE_INT, { .i64 = -1 }, -1, 1, AE },
    { "quality", "Quality override for the rate control (library default when unset)", OFFSET(quality), AV_OPT_TYPE_INT, { .i64 = -1 }, -1, 100, AE },
    { "rc", "Rate control mode", OFFSET(rc), AV_OPT_TYPE_INT, { .i64 = AAC_RC_CBR }, 0, 3, AE, .unit = "rc" },
    { "tvbr", "True VBR, quality based", 0, AV_OPT_TYPE_CONST, { .i64 = AAC_RC_TVBR }, 0, 0, AE, .unit = "rc" },
    { "cvbr", "Constrained VBR", 0, AV_OPT_TYPE_CONST, { .i64 = AAC_RC_CVBR }, 0, 0, AE, .unit = "rc" },
    { "abr", "Average bitrate", 0, AV_OPT_TYPE_CONST, { .i64 = AAC_RC_ABR }, 0, 0, AE, .unit = "rc" },
    { "cbr", "Constant bitrate", 0, AV_OPT_TYPE_CONST, { .i64 = AAC_RC_CBR }, 0, 0, AE, .unit = "rc" },
    FF_AAC_PROFILE_OPTS
    { NULL }
};

static const AVClass bac_enc_class = {
    .class_name = "libbac",
    .item_name  = av_default_item_name,
    .option     = bac_enc_options,
    .version    = LIBAVUTIL_VERSION_INT,
};

static const char *bac_error(int err)
{
    switch (err) {
    case AAC_ERR_INVALID_ARG:  return "invalid argument";
    case AAC_ERR_MEMORY:       return "out of memory";
    case AAC_ERR_INIT:         return "initialisation failed";
    case AAC_ERR_ENCODE:       return "encoding failed";
    case AAC_ERR_UNSUPPORTED:  return "unsupported";
    case AAC_ERR_OVERFLOW:     return "output buffer too small";
    case AAC_ERR_STATE:        return "wrong encoder state";
    default:                   return "unknown error";
    }
}

static av_cold int bac_encode_close(AVCodecContext *avctx)
{
    BacEncContext *s = avctx->priv_data;

    if (s->enc)
        aac_encoder_destroy(s->enc);
    s->enc = NULL;
    av_freep(&s->frame_buf);
    ff_af_queue_close(&s->afq);

    return 0;
}

/* Drains the encoder so that the next frame opens a clean stream; used
 * for a seek, where the queued frames are discarded. */
static av_cold void bac_encode_flush(AVCodecContext *avctx)
{
    BacEncContext *s = avctx->priv_data;
    int64_t pts, duration;
    uint8_t *scratch;
    int len;

    ff_af_queue_remove(&s->afq, s->afq.frame_count, &pts, &duration);
    if (!s->enc)
        return;
    scratch = av_malloc(s->max_frame_bytes);
    if (!scratch)
        return;
    do {
        len = aac_encoder_flush(s->enc, scratch, s->max_frame_bytes);
    } while (len > 0);
    av_free(scratch);
}

static av_cold int bac_encode_init(AVCodecContext *avctx)
{
    BacEncContext *s = avctx->priv_data;
    AVCPBProperties *cpb_props;
    uint8_t asc[64];
    int channels = avctx->ch_layout.nb_channels;
    int sce, cpe, ret, asc_size;

    if (avctx->profile != AV_PROFILE_UNKNOWN && avctx->profile != AV_PROFILE_AAC_LOW) {
        av_log(avctx, AV_LOG_ERROR, "libbac encodes the AAC-LC profile only\n");
        return AVERROR(EINVAL);
    }

    switch (channels) {
    case 1: sce = 1; cpe = 0; break;
    case 2: sce = 0; cpe = 1; break;
    case 6: sce = 2; cpe = 2; break;
    default:
        av_log(avctx, AV_LOG_ERROR, "Unsupported number of channels %d\n", channels);
        return AVERROR(EINVAL);
    }

    if (avctx->bit_rate <= 0)
        avctx->bit_rate = (int64_t)(96 * sce + 128 * cpe) * avctx->sample_rate / 44;
    if (avctx->bit_rate > INT_MAX) {
        av_log(avctx, AV_LOG_ERROR, "Bitrate %"PRId64" is out of range\n", avctx->bit_rate);
        return AVERROR(EINVAL);
    }

    s->enc = aac_encoder_create(avctx->sample_rate, channels, (int)avctx->bit_rate,
                                AAC_AOT_LC, (AacRateControl)s->rc);
    if (!s->enc) {
        av_log(avctx, AV_LOG_ERROR, "Unable to create the Baander Audio Core encoder for %d Hz, %d channels, %"PRId64" bit/s\n",
               avctx->sample_rate, channels, avctx->bit_rate);
        return AVERROR(EINVAL);
    }

    ret = aac_encoder_set_transport(s->enc, AAC_TRANSPORT_RAW);
    if (ret != AAC_OK) {
        av_log(avctx, AV_LOG_ERROR, "Unable to select raw access units: %s\n", bac_error(ret));
        goto error;
    }
    if (s->afterburner >= 0) {
        ret = aac_encoder_set_afterburner(s->enc, s->afterburner);
        if (ret != AAC_OK) {
            av_log(avctx, AV_LOG_ERROR, "Unable to set the afterburner: %s\n", bac_error(ret));
            goto error;
        }
    }
    if (s->quality >= 0) {
        ret = aac_encoder_set_quality(s->enc, s->quality);
        if (ret != AAC_OK) {
            av_log(avctx, AV_LOG_ERROR, "Unable to set the quality: %s\n", bac_error(ret));
            goto error;
        }
    }
    if (avctx->cutoff > 0) {
        ret = aac_encoder_set_bandwidth(s->enc, avctx->cutoff);
        if (ret != AAC_OK) {
            av_log(avctx, AV_LOG_ERROR, "Unable to set the bandwidth to %d Hz: %s\n",
                   avctx->cutoff, bac_error(ret));
            goto error;
        }
    }

    avctx->frame_size      = aac_encoder_frame_size(s->enc);
    avctx->initial_padding = aac_encoder_delay(s->enc);
    s->max_frame_bytes     = aac_encoder_max_frame_bytes(s->enc);
    s->frame_floats        = avctx->frame_size * channels;
    s->frame_buf           = av_calloc(s->frame_floats, sizeof(*s->frame_buf));
    if (!s->frame_buf) {
        ret = AVERROR(ENOMEM);
        goto fail;
    }
    ff_af_queue_init(avctx, &s->afq);

    asc_size = aac_encoder_config(s->enc, asc, sizeof(asc));
    if (asc_size <= 0) {
        av_log(avctx, AV_LOG_ERROR, "Unable to write the AudioSpecificConfig: %s\n",
               bac_error(asc_size));
        ret = asc_size;
        goto error;
    }
    avctx->extradata = av_mallocz(asc_size + AV_INPUT_BUFFER_PADDING_SIZE);
    if (!avctx->extradata) {
        ret = AVERROR(ENOMEM);
        goto fail;
    }
    memcpy(avctx->extradata, asc, asc_size);
    avctx->extradata_size = asc_size;

    cpb_props = ff_encode_add_cpb_side_data(avctx);
    if (!cpb_props) {
        ret = AVERROR(ENOMEM);
        goto fail;
    }
    cpb_props->max_bitrate =
    cpb_props->min_bitrate =
    cpb_props->avg_bitrate = avctx->bit_rate;

    return 0;
error:
    ret = AVERROR(EINVAL);
fail:
    bac_encode_close(avctx);
    return ret;
}

static int bac_encode_frame(AVCodecContext *avctx, AVPacket *avpkt,
                             const AVFrame *frame, int *got_packet_ptr)
{
    BacEncContext *s = avctx->priv_data;
    int channels = avctx->ch_layout.nb_channels;
    int ret, len, discard_padding;

    ret = ff_alloc_packet(avctx, avpkt, s->max_frame_bytes);
    if (ret < 0)
        return ret;

    if (frame) {
        int floats = frame->nb_samples * channels;
        memcpy(s->frame_buf, frame->data[0], floats * sizeof(float));
        if (floats < s->frame_floats)
            memset(s->frame_buf + floats, 0, (s->frame_floats - floats) * sizeof(float));
        if ((ret = ff_af_queue_add(&s->afq, frame)) < 0)
            return ret;
        len = aac_encoder_encode(s->enc, s->frame_buf, avctx->frame_size, avpkt->data, avpkt->size);
    } else {
        len = aac_encoder_flush(s->enc, avpkt->data, avpkt->size);
    }
    if (len < 0) {
        av_log(avctx, AV_LOG_ERROR, "Unable to encode frame: %s\n", bac_error(len));
        return AVERROR(EINVAL);
    }
    if (len == 0)
        return 0;

    ff_af_queue_remove(&s->afq, avctx->frame_size, &avpkt->pts, &avpkt->duration);

    discard_padding = avctx->frame_size - ff_samples_from_time_base(avctx, avpkt->duration);
    if ((discard_padding < avctx->frame_size) != (avpkt->duration > 0)) {
        av_log(avctx, AV_LOG_ERROR, "discard padding overflow\n");
        return AVERROR(EINVAL);
    }
    if ((!s->delay_sent && avctx->initial_padding > 0) || discard_padding > 0) {
        uint8_t *side_data = av_packet_new_side_data(avpkt, AV_PKT_DATA_SKIP_SAMPLES, 10);
        if (!side_data)
            return AVERROR(ENOMEM);
        if (!s->delay_sent) {
            AV_WL32(side_data, avctx->initial_padding);
            s->delay_sent = 1;
        }
        AV_WL32(side_data + 4, discard_padding);
    }

    avpkt->size     = len;
    avpkt->flags   |= AV_PKT_FLAG_KEY;
    *got_packet_ptr = 1;
    return 0;
}

static const AVProfile profiles[] = {
    { AV_PROFILE_AAC_LOW, "LC" },
    { AV_PROFILE_UNKNOWN },
};

static const FFCodecDefault bac_encode_defaults[] = {
    { "b", "0" },
    { NULL }
};

static const AVChannelLayout bac_ch_layouts[] = {
    AV_CHANNEL_LAYOUT_MONO,
    AV_CHANNEL_LAYOUT_STEREO,
    AV_CHANNEL_LAYOUT_5POINT1_BACK,
    { 0 },
};

static const int bac_sample_rates[] = {
    96000, 88200, 64000, 48000, 44100, 32000,
    24000, 22050, 16000, 12000, 11025, 8000, 0
};

const FFCodec ff_libbac_encoder = {
    .p.name                = "libbac",
    CODEC_LONG_NAME("Baander Audio Core"),
    .p.type                = AVMEDIA_TYPE_AUDIO,
    .p.id                  = AV_CODEC_ID_AAC,
    .p.capabilities        = AV_CODEC_CAP_DR1 | AV_CODEC_CAP_DELAY |
                             AV_CODEC_CAP_ENCODER_FLUSH |
                             AV_CODEC_CAP_SMALL_LAST_FRAME,
    .caps_internal         = FF_CODEC_CAP_NOT_INIT_THREADSAFE,
    .priv_data_size        = sizeof(BacEncContext),
    .init                  = bac_encode_init,
    FF_CODEC_ENCODE_CB(bac_encode_frame),
    .flush                 = bac_encode_flush,
    .close                 = bac_encode_close,
    CODEC_SAMPLEFMTS(AV_SAMPLE_FMT_FLT),
    .p.priv_class          = &bac_enc_class,
    .defaults              = bac_encode_defaults,
    .p.profiles            = profiles,
    CODEC_SAMPLERATES_ARRAY(bac_sample_rates),
    .p.wrapper_name        = "libbac",
    CODEC_CH_LAYOUTS_ARRAY(bac_ch_layouts),
};
