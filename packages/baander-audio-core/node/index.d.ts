/**
 * Native binding of Bånder Audio Core (AAC-LC, HE-AAC v1/v2, AAC-LD,
 * AAC-ELD and USAC / xHE-AAC encoder and decoder, and the BS.1770-4
 * loudness meter).
 *
 * PCM is interleaved 32-bit float at full scale 1.0; AAC data is an ADTS
 * or LOAS stream, LATM elements or raw access units.  Both classes accept
 * input in arbitrary chunk sizes and keep partial frames between calls, so
 * they work on streams as well as whole files.
 */

/**
 * Transport of the AAC data: 'adts' and 'loas' are self-synchronising
 * streams, 'latm' is an AudioMuxElement with its StreamMuxConfig in band,
 * 'latm-raw' one whose configuration travels out of band (the decoder
 * needs `config`), 'raw' a bare access unit per call (MP4 samples; the
 * decoder needs `config`). The decoder's 'detect' picks ADTS or LOAS by
 * their sync words and otherwise expects raw access units.
 */
export type AacTransport = 'detect' | 'adts' | 'loas' | 'latm' | 'latm-raw' | 'raw';
/** How the AudioSpecificConfig announces SBR and PS; ADTS is always implicit. */
export type AacSignaling = 'implicit' | 'explicit' | 'hierarchical';
/** What the decoder does with a damaged or missing frame. */
export type AacConcealment = 'off' | 'noise' | 'interpolate';
/**
 * The 7.1 layouts: '7.1' is the back pair behind the side pair
 * (configuration 12, the default for 8 channels), '7.1-front' the front
 * centre pair (configuration 7), '7.1-top' the top front pair
 * (configuration 14), '7.1-rear' the PCE-signalled rear surround layout.
 * 'default' covers every other channel count.
 */
export type AacChannelMode = 'default' | '7.1' | '7.1-front' | '7.1-top' | '7.1-rear';
/**
 * The object type of a decoded stream: 'lc' covers AAC-LC and the HE-AAC
 * profiles, 'er-lc' the error-resilient AAC-LC syntax, 'ld' AAC-LD,
 * 'eld' AAC-ELD and 'usac' USAC / xHE-AAC (audio object type 42);
 * 'unknown' before the first frame.
 */
export type AacObjectType = 'lc' | 'er-lc' | 'ld' | 'eld' | 'usac' | 'unknown';
/**
 * The MPEG-D DRC effect a player asks of a USAC stream (ISO/IEC 23003-4):
 * the stream's DRC sets are matched to it, with the standard's fallback
 * order when the exact effect is absent. 'none' (the default) applies DRC
 * only to prevent clipping; 'off' applies no MPEG-D DRC at all.
 */
export type AacDrcEffect = 'off' | 'none' | 'night' | 'noisy' | 'limited' | 'lowlevel' | 'dialog' | 'general';
/**
 * MPEG-4 dynamic range control profiles the encoder writes as metadata:
 * a compression curve of the named character plus the program reference
 * level.
 */
export type AacDrcProfile =
  | 'none'
  | 'film-standard'
  | 'film-light'
  | 'music-standard'
  | 'music-light'
  | 'speech';

export interface AacDrcOptions {
  /** How much of the signalled boost to apply, in percent. Default 100. */
  boost?: number;
  /** How much of the signalled cut to apply, in percent. Default 100. */
  cut?: number;
  /**
   * Wanted program loudness in quarter dB below full scale (0..127; 124
   * is -31 dBFS); a stream's program reference level is matched to it.
   * Default -1: no loudness normalisation.
   */
  targetRefLevel?: number;
  /** Apply the DVB heavy compression gains instead of the DRC bands when present. Default false. */
  heavy?: boolean;
}

/** Loudness normalisation of USAC streams from their MPEG-D loudness metadata. */
export interface AacLoudnessOptions {
  /** Normalise the program to `targetLufs`. Default true. */
  enabled?: boolean;
  /** Target program loudness in LUFS, -31 to -10. Default -24. */
  targetLufs?: number;
}

/** The MPEG-D loudness state of a USAC stream after the last decoded frame. */
export interface AacLoudness {
  /** The program loudness the stream declares in LUFS, or null when it declares none (and for every non-USAC stream). */
  programLoudness: number | null;
  /** The normalisation gain applied to the output in dB (0 when none is). */
  normalizationGain: number;
}

export interface AacMetadataOptions {
  /** The DRC profile written into every frame ('lc', 'he' and 'hev2' only). Default 'none'. */
  drcProfile?: AacDrcProfile;
  /** Program reference level in quarter dB below full scale (0..127). Default 124 (-31 dBFS). */
  programRefLevel?: number;
}

export interface AacLimiterOptions {
  /** Look-ahead and attack time in ms, which is also the added delay. Default 15. */
  attackMs?: number;
  /** Gain recovery time in ms. Default 50. */
  releaseMs?: number;
}

export interface AacDecoderOptions {
  /** Expected sample rate; the ADTS headers override it. Default 44100. */
  sampleRate?: number;
  /**
   * Output channels: 0 emits the stream's own channels in canonical order
   * (L, R, C, LFE, Ls, Rs, Lc, Rc; 2 for a mono stream with parametric
   * stereo), 1 and 2 downmix or duplicate to that count. Default 2.
   */
  channels?: number;
  /** Default 'detect'. */
  transport?: AacTransport;
  /** AudioSpecificConfig bytes of a raw or 'latm-raw' stream. */
  config?: Uint8Array;
  /** 1024 (default) or 960 samples per frame, for ADTS streams that cannot say. */
  frameLength?: 960 | 1024;
  /** Default 'interpolate'. */
  concealment?: AacConcealment;
  /** Peak limiter on the output; off by default. */
  limiter?: boolean | AacLimiterOptions;
  /** Apply the stream's MPEG-4 dynamic range control data; off by default. */
  drc?: boolean | AacDrcOptions;
  /**
   * AAC-ELD downscaled playout: decode at 1/2 or 1/4 of the stream's rate
   * with correspondingly shorter frames. Default 1.
   */
  eldDownscale?: 1 | 2 | 4;
  /**
   * USAC loudness normalisation from the stream's MPEG-D loudness
   * metadata: on by default at -24 LUFS; `false` leaves the program at
   * its coded level. Streams without loudness metadata are left as they are.
   */
  loudness?: boolean | AacLoudnessOptions;
  /** The MPEG-D DRC effect requested of a USAC stream. Default 'none'. */
  drcEffect?: AacDrcEffect;
  /** Use the album loudness and DRC gains when the USAC stream has them. Default false. */
  albumMode?: boolean;
  /** Share of the MPEG-D DRC boosting gains to apply, in percent. Default 100. */
  drcBoost?: number;
  /** Share of the MPEG-D DRC attenuating gains to apply, in percent. Default 100. */
  drcCut?: number;
}

export declare class AacDecoder {
  constructor(options?: AacDecoderOptions);
  /**
   * Decodes every complete frame in the pending bytes plus `data` and
   * returns the interleaved PCM; a trailing partial frame is kept for the
   * next call. With a packet transport ('latm', 'latm-raw', 'raw') each
   * call is one packet. Damaged frames are concealed and counted in
   * `concealedFrames`, or skipped and counted in `errors` with
   * concealment off.
   */
  decode(data: Uint8Array | ArrayBuffer): Float32Array;
  /**
   * Ends the stream: returns the last `delay` samples per channel the
   * decoder still holds, interleaved (empty when it holds none), then
   * starts over as `reset()` does. Bytes of an incomplete frame still
   * pending are dropped.
   */
  flush(): Float32Array;
  /**
   * Prepares a seek: drops the pending bytes and what the decoder
   * remembers of the audio before (overlap, SBR, parametric stereo and
   * surround state, DRC gains, concealment history) and keeps the
   * stream's configuration. The frames from the new position then decode
   * as they would in a fresh decoder; for xHE-AAC, seek to an independent
   * frame, which carries its pre-roll.
   */
  clearHistory(): void;
  /** Drops pending bytes and the error count and starts over, ready for a new stream. */
  reset(): void;
  /**
   * The MPEG-D loudness state of a USAC stream after the last decoded
   * frame: the declared program loudness and the normalisation gain
   * applied. For every other object type `programLoudness` is null and
   * the gain 0.
   */
  loudness(): AacLoudness;
  /** Sample rate of the output: twice the core rate while SBR is decoded. */
  readonly sampleRate: number;
  /** Sample rate of the core stream. */
  readonly coreSampleRate: number;
  /** Output channel count: the requested count, or the stream's with `channels: 0`. */
  readonly channels: number;
  /** Channels coded in the stream (1 for an HE-AAC v2 stream). */
  readonly streamChannels: number;
  /** The stream's 7.1 layout, or 'default' for every other layout. */
  readonly channelMode: AacChannelMode;
  /** Samples per channel per frame: 1024, or 2048 while SBR is decoded. */
  readonly frameSize: number;
  /** The stream carries SBR data, which is decoded: output runs at twice the core rate. */
  readonly hasSbr: boolean;
  /** The stream carries parametric stereo data, which is decoded: a mono stream plays as stereo. */
  readonly hasPs: boolean;
  /** The last frame carried MPEG Surround data, which is decoded: the stream plays as 5.1. */
  readonly hasSurround: boolean;
  /** Frames that failed to decode since construction or `reset()` (concealment off). */
  readonly errors: number;
  /** Frames concealed since construction or `reset()`. */
  readonly concealedFrames: number;
  /**
   * Samples per channel the decoder delays its output by beyond the
   * encoder's priming: the limiter's look-ahead, plus the surround
   * decoder's delay for an MPEG Surround stream. `flush()` returns this
   * many samples. A player trims the encoder's priming plus this delay
   * from the start of the output.
   */
  readonly delay: number;
  /** The object type of the stream being decoded. */
  readonly objectType: AacObjectType;
  /** The program reference level the last frame carried (quarter dB below full scale), or -1. */
  readonly programRefLevel: number;
  /** The last frame carried dynamic range control data. */
  readonly hasDrc: boolean;
}

export type AacRateControlMode = 'cbr' | 'abr' | 'cvbr' | 'tvbr';
/**
 * AAC-LC, HE-AAC v1 (SBR), HE-AAC v2 (SBR and parametric stereo), AAC-LD
 * or AAC-ELD (the low-delay profiles, 512 or 480 samples per frame, LOAS
 * by default; ADTS cannot carry them), or xHE-AAC (USAC, ISO/IEC 23003-3,
 * mono or stereo, LOAS by default, raw access units with `config` for
 * MP4; ADTS cannot carry it).
 */
export type AacProfile = 'lc' | 'he' | 'hev2' | 'ld' | 'eld' | 'xhe';
/** A USAC tool switched on, off, or left to the encoder's choice by bitrate. */
export type AacUsacTool = 'on' | 'off' | 'auto';

/** The xHE-AAC encoder's options ('xhe' only). */
export interface AacUsacOptions {
  /** Noise filling of the lines quantised to zero. Default true. */
  noiseFilling?: boolean;
  /**
   * The audio pre-roll extension at every independent frame, which lets
   * a decoder start there. Default true.
   */
  preroll?: boolean;
  /**
   * The independent frame schedule: the distance from one immediate
   * playout frame (independent, with the audio pre-roll) to the next, in
   * frames, milliseconds or output samples. The first one given wins in
   * the order samples, milliseconds, frames; samples and milliseconds
   * are rounded up to whole frames (2048 samples while SBR runs). None
   * keeps the default of one second. The frame before each (two before
   * it with SBR) is independent too and opens the pre-roll.
   */
  independentIntervalFrames?: number;
  independentIntervalMs?: number;
  independentIntervalSamples?: number;
  /**
   * An upper bound in frames on the distance between independent frames,
   * whatever the interval and `requestIndependentFrame()` say. Default none.
   */
  independentMaxDistanceFrames?: number;
  /**
   * A ceiling in bytes on each segment of access units from one immediate
   * playout frame to the next, the pre-roll included, for a muxer that
   * cuts there; the rate loop caps every frame at the segment's remaining
   * budget, so the quality falls while the ceiling binds. Default none.
   */
  segmentMaxBytes?: number;
  /** The stream identifier written into the configuration, 0..65535. Default none. */
  streamId?: number;
  /** Enhanced SBR at 2:1; 'auto' (default) uses it below 64 kbit/s per channel. */
  sbr?: AacUsacTool;
  /**
   * MPEG Surround 2-1-2 for stereo input; 'auto' (default) leaves it off,
   * as FFmpeg cannot decode it. It runs inside SBR, so 'on' brings SBR
   * with it and is refused together with `sbr: 'off'`.
   */
  mps212?: AacUsacTool;
  /**
   * MPEG-D DRC gain sets (ISO/IEC 23003-4) for a quieter listening mode.
   * The stream then carries two DRC sets a player applying MPEG-D DRC
   * selects by the effect it asks for: "general compression" (a
   * compressor that boosts the quiet passages and cuts the loud ones
   * around the programme's integrated loudness) and "night" (the same
   * with a limiter that holds the true peak at -1 dBTP after
   * normalisation to -16 LUFS). With `loudnessInfo` the programme's
   * figures anchor the compressor; without them it follows the input's
   * loudness so far. Other decoders ignore the gains. Default false.
   */
  drc?: boolean;
  /** The loudness range in LU the DRC sets bring the programme towards, 6 to 16. Default 10. */
  drcTargetRangeLu?: number;
}

/**
 * A programme's loudness figures as the xHE-AAC encoder writes them into
 * the stream's loudness info set (ISO/IEC 23003-4): the integrated
 * loudness and the sample peak are needed, the rest is written when
 * given. Levels in LKFS / dBTP / dBFS, the range in LU; every level is
 * coded at 0.25 dB resolution.
 */
export interface AacLoudnessInfo {
  integratedLkfs: number;
  samplePeakDbfs: number;
  truePeakDbtp?: number;
  /** EBU Tech 3342 loudness range. */
  rangeLu?: number;
  /** Anchor (dialogue) loudness, which the meter does not measure. */
  anchorLkfs?: number;
  /** EBU Tech 3341 maxima, ungated. */
  maxMomentaryLkfs?: number;
  maxShortTermLkfs?: number;
}

/**
 * What the loudness meter measures (ITU-R BS.1770-4, EBU Tech 3341/3342):
 * the range and the short-term maximum need 3 s of input and are null
 * before that; the momentary maximum and the true peak are always
 * present. The object is accepted as `loudnessInfo` for a second encode.
 */
export interface AacLoudnessMeasurement {
  integratedLkfs: number;
  samplePeakDbfs: number;
  truePeakDbtp: number | null;
  rangeLu: number | null;
  maxMomentaryLkfs: number | null;
  maxShortTermLkfs: number | null;
}

/** What a muxer needs to know about the last frame an xHE-AAC encoder produced. */
export interface AacEncoderFrameInfo {
  /** Frames since the stream's start, from 0. */
  frameIndex: number;
  /** usacIndependencyFlag: a decoder may start here. */
  independent: boolean;
  /** Bytes of the audio pre-roll extension; a frame with one opens a segment. */
  prerollBytes: number;
  /** Bytes of the frame as produced: the access unit, or the transport frame. */
  bytes: number;
  /**
   * The position of the frame's first decoded sample on the input's
   * timeline, in output samples: the frame index times `frameSize` less
   * `delay`, so the first frames are negative (they decode to the
   * delay's priming).
   */
  samplePosition: number;
}

export interface AacLdSbrOptions {
  /**
   * The core runs at half the input rate and a frame is 1024 (960) input
   * samples; without it the core and the SBR share one rate. Default true.
   */
  dualRate?: boolean;
}

export interface AacEncoderOptions {
  /**
   * 8000 to 96000 Hz, one of the AAC sample rates; the HE-AAC profiles
   * take 32000 to 96000 Hz (twice a core rate). Default 44100.
   */
  sampleRate?: number;
  /** 1 to 8 in the canonical order; 'hev2' takes exactly 2. Default 2. */
  channels?: number;
  /** Object type. Default 'lc'. */
  profile?: AacProfile;
  /** Target bitrate in bit/s for cbr/abr; sets the bandwidth for cvbr/tvbr. Default 128000. */
  bitrate?: number;
  /** Rate control mode. Default 'cbr'. */
  mode?: AacRateControlMode;
  /** 1..100 for the VBR modes (70 is the masking threshold). */
  quality?: number;
  /** Default 'adts', or 'loas' for the low-delay profiles. */
  transport?: Exclude<AacTransport, 'detect'>;
  /** SBR/PS signalling in the AudioSpecificConfig. Default 'implicit'. */
  signaling?: AacSignaling;
  /** 1024 (default) or 960 samples per frame; 512 (default) or 480 for the low-delay profiles. */
  frameLength?: 960 | 1024 | 512 | 480;
  /** Write the ADTS CRC. Default false. */
  crc?: boolean;
  /** MPEG-2 AAC (ADTS id 1, no noise substitution). Default false. */
  mpeg2?: boolean;
  /** The 7.1 layout of an 8-channel input. Default '7.1'. */
  channelMode?: AacChannelMode;
  /** 'eld' only: SBR with the low-delay filterbank. Default false. */
  ldSbr?: boolean | AacLdSbrOptions;
  /**
   * A second quantisation pass for better quality at about 85 % more CPU.
   * Default true for 'lc', 'he' and 'hev2', false for 'ld' and 'eld';
   * 'usac' refuses true.
   */
  afterburner?: boolean;
  /**
   * A hard cap in bit/s on any single frame: no frame takes more than its
   * share of it, and the bit reservoir never lends past it. In 'cbr' and
   * 'abr' it may not be below `bitrate`. Default 0 (none).
   */
  peakBitrate?: number;
  /**
   * The core's lowpass in Hz (1000 or more, clamped to half the core
   * rate) instead of the bitrate's tuning table. No effect on a core with
   * SBR, which is coded up to the SBR crossover. Default 0 (the table).
   */
  bandwidth?: number;
  /**
   * One of the VBR modes as the FDK encoder names them, 1 to 5: about 32,
   * 72, 112, 148 or 228 kbit/s for stereo (scaled per channel). The
   * mode's target replaces `bitrate` and `mode`; a `peakBitrate` below it
   * lowers the mode, and a `quality` given with it overrides the mode's.
   */
  vbrMode?: 1 | 2 | 3 | 4 | 5;
  /**
   * Dynamic range control metadata written into the stream.  Not available
   * for the 'ld', 'eld' and 'xhe' profiles, whose frames carry no fill
   * elements: the constructor throws when a profile other than 'none' is
   * given.
   */
  metadata?: AacMetadataOptions;
  /** 'xhe' only: the USAC encoder's options. */
  usac?: AacUsacOptions;
  /**
   * 'xhe' only: the loudness info set written into the stream's
   * configuration, which players normalise by (two-pass: measure the
   * whole programme with `measureLoudness()` first).
   */
  loudnessInfo?: AacLoudnessInfo;
  /**
   * Meter the input as it is encoded and expose the figures as
   * `loudness` (one-pass). A stream's configuration cannot change once it
   * has begun, so the result is for an MP4 header or a second encode with
   * `loudnessInfo`, not for the stream it was measured from. Available
   * for up to six channels. Default false.
   */
  measureLoudness?: boolean;
}

export declare class AacEncoder {
  constructor(options?: AacEncoderOptions);
  /**
   * Takes interleaved samples of any length and returns the frames that
   * became complete (possibly empty), in the chosen transport.
   */
  encode(pcm: Float32Array): Buffer;
  /**
   * Pads the remaining samples to a frame and drains the encoder delay;
   * returns the final frames. The encoder can then start a new stream.
   */
  flush(): Buffer;
  /**
   * 'xhe' only: asks for an independent frame on demand, at any point of
   * the stream. The next frame is independent and opens the pre-roll of
   * the immediate playout frame that follows it (one frame later for the
   * plain core, two with SBR; the same frame without the pre-roll),
   * unless one is already due sooner; the interval counts from there.
   * Throws for the other profiles.
   */
  requestIndependentFrame(): void;
  /**
   * 'xhe' only: the last frame `encode` or `flush` produced, for a muxer
   * that cuts segments at the frames with a pre-roll (a `flush` of a
   * stream transport reports its last frame). Null before the first
   * frame and for the other profiles; the last frame of a flushed stream
   * stays until the next stream begins.
   */
  readonly lastFrame: AacEncoderFrameInfo | null;
  /** The AudioSpecificConfig, for an MP4 esds box or an out-of-band StreamMuxConfig. */
  readonly config: Buffer;
  /**
   * Lengths of the units in the last `encode` or `flush` result, in
   * order: one per frame for 'adts' and 'loas', one per LATM element or
   * access unit for the packet transports, which a packetiser needs.
   */
  readonly unitSizes: number[];
  readonly sampleRate: number;
  readonly channels: number;
  readonly profile: AacProfile;
  /**
   * Samples per channel per frame: 1024 or 960, twice that for the HE-AAC
   * profiles and for 'xhe' while it runs SBR (by default stereo below
   * 128 kbit/s and mono below 64 kbit/s, or as the `sbr` option says).
   */
  readonly frameSize: number;
  /**
   * Samples of silence before the first input sample in the decoded
   * output: 2048 for AAC-LC at 1024, 5635 for the HE-AAC profiles and
   * for 'xhe' while it runs SBR.
   */
  readonly delay: number;
  /**
   * With `measureLoudness`: the figures of the input encoded so far, or
   * null before 400 ms of input (and without the option). They stay after
   * `flush()` until the next stream begins.
   */
  readonly loudness: AacLoudnessMeasurement | null;
}

/**
 * Measures a whole interleaved signal with the loudness meter (1 to 6
 * channels in the canonical order L, R, C, LFE, Ls, Rs; 8000 to
 * 384000 Hz), for the two-pass flow: the result is the `loudnessInfo` of
 * the encode that follows. Throws for fewer than 400 ms of input.
 */
export declare function measureLoudness(
  pcm: Float32Array,
  sampleRate: number,
  channels: number,
): AacLoudnessMeasurement;

/** What a FLAC stream's STREAMINFO block states. */
export interface FlacStreamInfo {
  /** Samples per channel of the smallest block; 0 when the stream carried no STREAMINFO. */
  minBlockSize: number;
  /** Samples per channel of the largest block; 0 when the stream carried no STREAMINFO. */
  maxBlockSize: number;
  /** 0: unknown. */
  minFrameBytes: number;
  /** 0: unknown. */
  maxFrameBytes: number;
  sampleRate: number;
  /** 1 to 8. */
  channels: number;
  /** 4 to 32. */
  bitsPerSample: number;
  /** Per channel; 0: unknown. */
  totalSamples: number;
  /** The MD5 of the unencoded samples, 16 bytes; all zero: unknown. */
  md5: Buffer;
}

/** One FLAC frame as the encoder wrote it or the decoder read it. */
export interface FlacFrameInfo {
  /** Of the frame's first sample, per channel, from the start of the stream. */
  samplePosition: number;
  /** Samples per channel in the frame. */
  blockSize: number;
  /** The frame from its sync code to its CRC-16. */
  bytes: number;
}

/** One entry of a FLAC stream's seek table. */
export interface FlacSeekPoint {
  /** Of the first sample of the target frame. */
  samplePosition: number;
  /** Of the target frame, from the first byte of the first frame. */
  byteOffset: number;
  /** Samples per channel in the target frame. */
  blockSize: number;
}

/** A picture of a FLAC stream: the content of one PICTURE block. */
export interface FlacPicture {
  /** 0 to 20 by RFC 9639 Table 13: 3 is the front cover, 4 the back. Default 3 when writing. */
  type?: number;
  /** 'image/jpeg', 'image/png', ...; '-->' when `data` is a URI. */
  mimeType: string;
  description?: string;
  /** Pixels; 0 or absent: unknown. The library does not look into the picture. */
  width?: number;
  height?: number;
  /** Bits per pixel. */
  depth?: number;
  /** Of an indexed picture; 0 otherwise. */
  colors?: number;
  /** The picture file as it is, at most 16777183 bytes less the media type and description. */
  data: Buffer | Uint8Array | ArrayBuffer;
}

/** A picture as the decoder returns it: every field present, the data a Buffer. */
export interface FlacDecodedPicture {
  type: number;
  mimeType: string;
  description: string;
  width: number;
  height: number;
  depth: number;
  colors: number;
  data: Buffer;
}

export interface FlacEncoderOptions {
  /** Any rate a FLAC frame header can state. Default 44100. */
  sampleRate?: number;
  /** 1 to 8, in the order RFC 9639 assigns to each count. Default 2. */
  channels?: number;
  /** 8, 12, 16, 20, 24 or 32. Default 16. */
  bitsPerSample?: number;
  /** 0 (fastest) to 8 (smallest). Default 5. */
  level?: number;
  /**
   * Samples per channel the stream will hold, when known up front. It
   * sizes the seek table.
   */
  totalSamples?: number;
  /**
   * Reserves a seek table in the header, a point for every ten seconds
   * of `totalSamples`, which `finalHeader()` fills. For callers that
   * will write the final header over the first one.
   */
  seekTable?: boolean;
  /** Bytes of a PADDING block that closes the metadata. Default 0. */
  padding?: number;
  /**
   * Tags (Vorbis comments), each 'NAME=value', written in this order. A
   * name is printable ASCII without '='; names may repeat.
   */
  tags?: string[];
  /** The writing program's name in the tag block. Default: the library's. */
  vendor?: string;
  /**
   * Pictures, written in this order behind the tags. Of the types 1 and
   * 2 (the file icons) a stream holds one each at most.
   */
  pictures?: FlacPicture[];
}

/**
 * Lossless mode: encodes interleaved integer PCM to a native FLAC stream
 * (RFC 9639) in the streamable subset. Nothing is ever coded with loss;
 * a configuration FLAC cannot hold throws.
 *
 * The results of `encode()` and `finish()` joined are a complete stream:
 * the first one begins with the stream's header (`headerBytes` long). A
 * caller that can seek in its output then writes `finalHeader()` over
 * that header, which adds the sample total, the MD5 of the input and the
 * seek points. Without that step the stream is decodable, with a total
 * and an MD5 of zero.
 */
export declare class FlacEncoder {
  constructor(options?: FlacEncoderOptions);
  /**
   * Codes interleaved samples at the stream's bit depth (a 16-bit sample
   * is a value in -32768..32767) and returns every frame they complete.
   * Throws, having coded nothing, when the array's length is not a
   * multiple of the channel count and when a sample lies outside the
   * depth.
   *
   * A call that runs out of memory before it has coded anything throws
   * and can be made again. One that fails after coding began has lost
   * frames: the stream is incomplete, and every later call on the
   * encoder throws.
   */
  encode(pcm: Int32Array): Buffer;
  /**
   * Ends the stream: returns the samples still held as its last, shorter
   * frame. On an encoder that has coded nothing it returns the header of
   * a stream without samples. The next `encode()` starts a new stream; a
   * second `finish()` without one throws.
   */
  finish(): Buffer;
  /**
   * After `finish()`: the header again, of the same length, with the
   * sample total, the MD5, the frame size range and the seek points.
   */
  finalHeader(): Buffer;
  /** The length of the stream's header: the fLaC marker and the metadata blocks. */
  readonly headerBytes: number;
  /** The last frame written; null before the first. */
  readonly lastFrame: FlacFrameInfo | null;
  /** The totals and the MD5 are zero until `finish()`. */
  readonly streamInfo: FlacStreamInfo;
  readonly sampleRate: number;
  readonly channels: number;
  readonly bitsPerSample: number;
  readonly level: number;
  /** Samples per channel in every frame but the last of a stream. */
  readonly blockSize: number;
}

export interface FlacDecoderOptions {
  /**
   * Compares the MD5 of the decoded samples with the one in STREAMINFO;
   * `md5` states the result after `finish()`. Default false. The frame
   * checksums are verified in either case.
   */
  md5?: boolean;
  /**
   * A STREAMINFO block supplied out of band (its 34 bytes, as an MP4
   * dfLa box or a Matroska CodecPrivate carries it), for a stream that
   * starts at a frame.
   */
  streamInfo?: Buffer | Uint8Array | ArrayBuffer;
  /**
   * Keeps the stream's tags and pictures: true for up to 32 MiB of
   * them, or `{ maxBytes }` for another limit. A tag or picture block
   * past the limit is skipped and counted in `skippedMetadata`. Default
   * false: tags and pictures are skipped unread, however large.
   * A `maxBytes` of 0 is the 32 MiB as well; one that the build cannot
   * address (above 4 GiB on a 32-bit build) throws.
   */
  metadata?: boolean | { maxBytes?: number };
  /**
   * The most samples (values of the Int32Array, all channels counted)
   * one `decode()` or `finish()` call returns. Default 1048576 (4 MiB);
   * at least 524280, which is one frame of the largest block in eight
   * channels, and at most 268435456. See `decode()`.
   */
  maxOutput?: number;
}

/**
 * Lossless mode: decodes a native FLAC stream, fed in chunks of any
 * size, to interleaved integer PCM at the stream's bit depth. A damaged
 * frame is never concealed: it is counted in `errors`, decoding goes on
 * at the next valid frame, and `lastFrame.samplePosition` shows how many
 * samples were lost.
 *
 * What a call returns is bounded, whatever the stream says: a FLAC
 * frame of 50 bytes can stand for two megabytes of samples, so the size
 * of the input says nothing about the size of the output. One call
 * returns at most `maxOutput` samples and holds the rest back:
 *
 *     let pcm = decoder.decode(chunk);
 *     use(pcm);
 *     while (decoder.pending) use(decoder.decode());
 *
 * Nothing is lost on the way, and the decoder holds no more than the
 * unread rest of the one chunk and a buffer of `maxOutput` samples.
 */
export declare class FlacDecoder {
  constructor(options?: FlacDecoderOptions);
  /**
   * Takes the bytes and returns the samples of the frames they complete,
   * interleaved, at most `maxOutput` of them. When `pending` is true
   * afterwards, more are waiting: call `decode()` without an argument
   * until it is false, and only then give the next bytes (a call with
   * bytes while samples are pending throws, so that nothing can pile
   * up).
   *
   * Throws for a stream that cannot be decoded further: one whose sample
   * rate, channel count or bit depth changes on the way, or frames that
   * leave those to a STREAMINFO the decoder was not given. The samples
   * before that point are returned first, with `pending` true for the
   * error that waits; the call after them throws. From then on nothing
   * is pending, every `decode()` throws the same error, `finish()`
   * closes the stream without samples, and `reset()` or `seekReset()`
   * starts again.
   */
  decode(data?: Buffer | Uint8Array | ArrayBuffer): Int32Array;
  /**
   * Ends the stream: no more bytes follow. Returns the samples of frames
   * that were waiting for that word, at most `maxOutput` of them; while
   * `pending` is true, call `finish()` again for the rest. `md5` and
   * `errors` state the result once `pending` is false; a stream that
   * ends inside a frame does not throw and counts in `errors`. The
   * decoder takes no input until `reset()`.
   */
  finish(): Int32Array;
  /**
   * Whether samples or bytes of the last chunk are still held back for
   * the bound of a call, or an error waits behind samples already
   * returned: another `decode()` without an argument (or `finish()`,
   * once that was called) returns more or throws that error.
   */
  readonly pending: boolean;
  /** The most samples one call returns. */
  readonly maxOutput: number;
  /** Starts over for a new stream. */
  reset(): void;
  /**
   * For a seek: drops the buffered bytes and the samples not yet
   * collected. The bytes given next may begin anywhere in the stream;
   * the stream's properties and its seek table are kept, and `md5`
   * becomes 'unknown'. It also takes back a `finish()` that has not yet
   * handed out its last samples: the decoder takes bytes again. Once
   * the stream is finished, only `reset()` does.
   */
  seekReset(): void;
  /** Null until the stream's properties are known. */
  readonly streamInfo: FlacStreamInfo | null;
  /** The last frame decoded; null before the first. */
  readonly lastFrame: FlacFrameInfo | null;
  /** 0 until the stream's properties are known. */
  readonly sampleRate: number;
  readonly channels: number;
  readonly bitsPerSample: number;
  /** Damaged stretches of the stream so far, each one frame or several in a row. */
  readonly errors: number;
  /** After `finish()` with the `md5` option: whether the decoded samples are the encoded ones. */
  readonly md5: 'unknown' | 'match' | 'mismatch';
  /** The stream's seek table, placeholders left out. */
  readonly seekPoints: FlacSeekPoint[];
  /**
   * The byte offset of the first frame, which the seek points count
   * from; null until the metadata has been read and for a stream that
   * began at a frame.
   */
  readonly firstFrameOffset: number | null;
  /**
   * With the `metadata` option: the stream's tags in its order, each
   * 'NAME=value', once the header has been read (`firstFrameOffset` is
   * a number then).
   */
  readonly tags: string[];
  /** With the `metadata` option: the vendor string of the tag block; '' without one. */
  readonly vendor: string;
  /** With the `metadata` option: the stream's pictures in its order. */
  readonly pictures: FlacDecodedPicture[];
  /** Tag and picture blocks skipped because of the `metadata` limit. */
  readonly skippedMetadata: number;
  /**
   * The bytes of ID3v2 tags in front of the stream, which the decoder
   * passes over by the length each states; 0 when the input began with
   * the stream. `firstFrameOffset` counts from the first byte given, so
   * it includes them.
   */
  readonly id3v2Bytes: number;
  /**
   * Whether an ID3v1 tag, 128 bytes that begin with 'TAG' behind the
   * last frame, was passed over; known after `finish()`. The reference
   * decoder, `flac`, reports such a tag as lost sync; this one decodes
   * and verifies every sample before it, counts no error for it and
   * says so here.
   */
  readonly id3v1: boolean;
}

export declare const version: string;

declare const binding: {
  AacDecoder: typeof AacDecoder;
  AacEncoder: typeof AacEncoder;
  FlacDecoder: typeof FlacDecoder;
  FlacEncoder: typeof FlacEncoder;
  measureLoudness: typeof measureLoudness;
  version: string;
};
export default binding;
