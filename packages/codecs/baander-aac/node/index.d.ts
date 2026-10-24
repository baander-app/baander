/**
 * Native binding of the baander-aac codec (AAC-LC, HE-AAC v1/v2, AAC-LD
 * and AAC-ELD encoder and decoder).
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
 * profiles, 'er-lc' the error-resilient AAC-LC syntax, 'ld' AAC-LD and
 * 'eld' AAC-ELD; 'unknown' before the first frame.
 */
export type AacObjectType = 'lc' | 'er-lc' | 'ld' | 'eld' | 'unknown';
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

export interface AacMetadataOptions {
  /** The DRC profile written into every frame. Default 'none'. */
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
  /** Drops pending bytes and the error count and starts over, ready for a new stream. */
  reset(): void;
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
  /** Frames that failed to decode since construction or `reset()` (concealment off). */
  readonly errors: number;
  /** Frames concealed since construction or `reset()`. */
  readonly concealedFrames: number;
  /** Output samples of delay added by the limiter (0 without it). */
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
 * by default; ADTS cannot carry them).
 */
export type AacProfile = 'lc' | 'he' | 'hev2' | 'ld' | 'eld';

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
  /** A second quantisation pass for better quality at more CPU. Default false. */
  afterburner?: boolean;
  /** Dynamic range control metadata written into the stream. */
  metadata?: AacMetadataOptions;
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
  /** Samples per channel per frame: 1024 or 960, twice that for the HE-AAC profiles. */
  readonly frameSize: number;
  /** Samples of silence before the first input sample in the decoded output (2048 for AAC-LC at 1024). */
  readonly delay: number;
}

export declare const version: string;

declare const binding: {
  AacDecoder: typeof AacDecoder;
  AacEncoder: typeof AacEncoder;
  version: string;
};
export default binding;
