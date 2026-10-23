/**
 * Native binding of the baander-aac codec (AAC-LC and HE-AAC v1/v2
 * encoder and decoder).
 *
 * PCM is interleaved 32-bit float at full scale 1.0; AAC data is an ADTS
 * stream.  Both classes accept input in arbitrary chunk sizes and keep
 * partial frames between calls, so they work on streams as well as whole
 * files.
 */

export interface AacDecoderOptions {
  /** Expected sample rate; the ADTS headers override it. Default 44100. */
  sampleRate?: number;
  /**
   * Output channels: 0 emits the stream's own channels in canonical order
   * (L, R, C, LFE, Ls, Rs, Lc, Rc; 2 for a mono stream with parametric
   * stereo), 1 and 2 downmix or duplicate to that count. Default 2.
   */
  channels?: number;
}

export declare class AacDecoder {
  constructor(options?: AacDecoderOptions);
  /**
   * Decodes every complete ADTS frame in the pending bytes plus `data` and
   * returns the interleaved PCM; a trailing partial frame is kept for the
   * next call. Damaged frames are skipped and counted in `errors`.
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
  /** Samples per channel per frame: 1024, or 2048 while SBR is decoded. */
  readonly frameSize: number;
  /** The stream carries SBR data, which is decoded: output runs at twice the core rate. */
  readonly hasSbr: boolean;
  /** The stream carries parametric stereo data, which is decoded: a mono stream plays as stereo. */
  readonly hasPs: boolean;
  /** Frames that failed to decode since construction or `reset()`. */
  readonly errors: number;
}

export type AacRateControlMode = 'cbr' | 'abr' | 'cvbr' | 'tvbr';
/** AAC-LC, HE-AAC v1 (SBR) or HE-AAC v2 (SBR and parametric stereo). */
export type AacProfile = 'lc' | 'he' | 'hev2';

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
}

export declare class AacEncoder {
  constructor(options?: AacEncoderOptions);
  /**
   * Takes interleaved samples of any length and returns the ADTS frames
   * that became complete (possibly empty).
   */
  encode(pcm: Float32Array): Buffer;
  /**
   * Pads the remaining samples to a frame and drains the encoder delay;
   * returns the final ADTS frames. The encoder can then start a new stream.
   */
  flush(): Buffer;
  readonly sampleRate: number;
  readonly channels: number;
  readonly profile: AacProfile;
  /** Samples per channel per frame: 1024, or 2048 for the HE-AAC profiles. */
  readonly frameSize: number;
  /** Samples of silence before the first input sample in the decoded output: 2048, or 5635 for HE-AAC. */
  readonly delay: number;
}

export declare const version: string;

declare const binding: {
  AacDecoder: typeof AacDecoder;
  AacEncoder: typeof AacEncoder;
  version: string;
};
export default binding;
