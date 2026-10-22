#ifndef BAANDER_AAC_DECODER_H
#define BAANDER_AAC_DECODER_H
#include "aac.h"
#include "aac_dsp.h"
#include "aac_tables.h"
#include "bitstream.h"
#include "ics.h"
#include "mdct.h"
#ifdef __cplusplus
extern "C" {
#endif

#define AAC_TNS_MAX_ORDER 20

using AacTnsData = struct AacTnsData_ {
  int n_filt[8];
  int length[8][4];
  int order[8][4];
  int direction[8][4];
  float coef[8][4][AAC_TNS_MAX_ORDER];
};

/* One individual_channel_stream as parsed from the current frame. */
using AacChannelStream = struct AacChannelStream_ {
  AacIcsInfo ics;
  int band_type[AAC_MAX_BANDS]; /* [group * max_sfb + sfb] */
  int sf[AAC_MAX_BANDS];        /* scalefactor, noise energy or intensity position */
  int quant[1024];              /* quantized values, [window * 128 + k] for short windows */
  float coef[1024];             /* dequantized spectrum */
  int tns_present;
  AacTnsData tns;
};

using AacDecoderChannel = struct AacDecoderChannel_ {
  AacChannelStream cs;
  float overlap[1024];   /* second half of the previous windowed IMDCT */
  int prev_window_shape; /* window_shape of the previous frame */
  float output[1024];    /* decoded PCM of the current frame, full scale = 1.0 */
  int has_sbr, has_ps;
};

using AacDecoderState = struct AacDecoderState_ {
  int sample_rate;     /* sample rate of the stream */
  int channels;        /* output channels requested by the caller */
  int rate_index;      /* sampling_frequency_index of the stream */
  int stream_channels; /* channels carried by the last decoded frame */
  int frame_size;
  AacDecoderChannel ch[2];
  AacMdct mdct_long, mdct_short;
  unsigned random_state; /* PNS noise generator */
  const AacDSP* dsp;
};

AacDecoderState* aac_decoder_state_create(int sr, int ch, const AacDSP* dsp);
void aac_decoder_state_destroy(AacDecoderState* s);

/* Decodes one raw_data_block() (ISO/IEC 14496-3 Table 4.3) for the stream
 * layout in `s` and leaves the PCM of each carried channel in ch[c].output.
 * Returns the number of channels decoded (1 or 2) or a negative AacError. */
int aac_decode_raw_data_block(AacDecoderState* s, AacBitReader* r);

/* Selects the sampling_frequency_index used for band tables; returns
 * AAC_ERR_UNSUPPORTED for an index without tables. */
int aac_decoder_set_rate_index(AacDecoderState* s, int rate_index);

#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_DECODER_H */
