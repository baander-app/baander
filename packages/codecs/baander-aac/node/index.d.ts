/**
 * Native binding of the baander-aac codec (AAC-LC encoder and decoder).
 *
 * PCM is interleaved 32-bit float at full scale 1.0; AAC data is an ADTS
 * stream.  Both classes accept input in arbitrary chunk sizes and keep
 * partial frames between calls, so they work on streams as well as whole
 * files.
 */

export interface AacDecoderOptions {
  /** Expected sample rate; the ADTS headers override it. Default 44100. */
  sampleRate?: number;
  /** Output channels, 1 or 2; a stream with the other count is downmixed or duplicated. Default 2. */
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
  /** Drops pending bytes and the error count; the overlap state continues. */
  reset(): void;
  /** Sample rate of the stream decoded so far. */
  readonly sampleRate: number;
  /** Output channel count. */
  readonly channels: number;
  /** Samples per channel per frame (1024). */
  readonly frameSize: number;
  /** The stream carries SBR data, which is not decoded: output is the AAC-LC core. */
  readonly hasSbr: boolean;
  /** Frames that failed to decode since construction or `reset()`. */
  readonly errors: number;
}

export type AacRateControlMode = 'cbr' | 'abr' | 'cvbr' | 'tvbr';

export interface AacEncoderOptions {
  /** 8000 to 96000 Hz, one of the AAC sample rates. Default 44100. */
  sampleRate?: number;
  /** 1 or 2. Default 2. */
  channels?: number;
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
  /** Samples per channel per frame (1024). */
  readonly frameSize: number;
  /** Samples of silence before the first input sample in the decoded output (2048). */
  readonly delay: number;
}

export declare const version: string;

declare const binding: {
  AacDecoder: typeof AacDecoder;
  AacEncoder: typeof AacEncoder;
  version: string;
};
export default binding;
