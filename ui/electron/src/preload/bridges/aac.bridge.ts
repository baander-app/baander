import { ipcRenderer } from 'electron';

export type AacTransport = 'detect' | 'adts' | 'loas' | 'latm' | 'latm-raw' | 'raw';
export type AacSignaling = 'implicit' | 'explicit' | 'hierarchical';
export type AacConcealment = 'off' | 'noise' | 'interpolate';
export type AacChannelMode = 'default' | '7.1' | '7.1-front' | '7.1-top' | '7.1-rear';
export type AacObjectType = 'lc' | 'er-lc' | 'ld' | 'eld' | 'unknown';
export type AacDrcProfile = 'none' | 'film-standard' | 'film-light' | 'music-standard' | 'music-light' | 'speech';

/** How the decoder applies a stream's MPEG-4 dynamic range control data. */
export interface AacDrcOptions {
  /** Share of the signalled boost, in percent. Default 100. */
  boost?: number;
  /** Share of the signalled cut, in percent. Default 100. */
  cut?: number;
  /** Wanted loudness in quarter dB below full scale (0..127), or -1 for none. */
  targetRefLevel?: number;
  /** Use the DVB heavy compression gains when present. */
  heavy?: boolean;
}

export interface AacMetadataOptions {
  drcProfile?: AacDrcProfile;
  /** Program reference level in quarter dB below full scale (0..127). */
  programRefLevel?: number;
}

export interface AacLdSbrOptions {
  /** The core at half the input rate (default) or the same rate. */
  dualRate?: boolean;
}

export interface AacLimiterOptions {
  attackMs?: number;
  releaseMs?: number;
}

/** Options of the native AAC decoder; see @baander/aac-native. */
export interface AacDecoderOptions {
  sampleRate?: number;
  /** 0 for the stream's own channels, 1 or 2 for a downmix, up to 8. */
  channels?: number;
  /** 'detect' (default) picks ADTS or LOAS; 'latm-raw' and 'raw' need `config`. */
  transport?: AacTransport;
  /** AudioSpecificConfig bytes. */
  config?: Uint8Array;
  frameLength?: 960 | 1024;
  /** Default 'interpolate'. */
  concealment?: AacConcealment;
  limiter?: boolean | AacLimiterOptions;
  /** Apply the stream's dynamic range control data; off by default. */
  drc?: boolean | AacDrcOptions;
  /** AAC-ELD playout at 1/2 or 1/4 of the stream's rate. Default 1. */
  eldDownscale?: 1 | 2 | 4;
}

export type AacRateControlMode = 'cbr' | 'abr' | 'cvbr' | 'tvbr';
/** AAC-LC, HE-AAC v1 (SBR), HE-AAC v2 (SBR and parametric stereo), AAC-LD or AAC-ELD. */
export type AacProfile = 'lc' | 'he' | 'hev2' | 'ld' | 'eld';

/** Options of the native AAC encoder; see @baander/aac-native. */
export interface AacEncoderOptions {
  sampleRate?: number;
  /** 1 to 8 in the canonical order; 'hev2' takes exactly 2. */
  channels?: number;
  bitrate?: number;
  mode?: AacRateControlMode;
  quality?: number;
  profile?: AacProfile;
  transport?: Exclude<AacTransport, 'detect'>;
  signaling?: AacSignaling;
  /** 1024 or 960; 512 or 480 for the low-delay profiles. */
  frameLength?: 960 | 1024 | 512 | 480;
  crc?: boolean;
  mpeg2?: boolean;
  channelMode?: AacChannelMode;
  /** 'eld' only: SBR with the low-delay filterbank. */
  ldSbr?: boolean | AacLdSbrOptions;
  /** A second quantisation pass for better quality at more CPU. */
  afterburner?: boolean;
  /** Dynamic range control metadata written into the stream. */
  metadata?: AacMetadataOptions;
}

export interface AacInfo {
  available: boolean;
  version: string | null;
  error: string | null;
}

export interface AacDecodeResult {
  /** Output rate: twice the core rate while SBR is decoded. */
  sampleRate: number;
  /** Output channels; a mono stream with parametric stereo plays as two. */
  channels: number;
  /** Channels coded in the stream. */
  streamChannels: number;
  channelMode: AacChannelMode;
  frameSize: number;
  /** Frames that failed (concealment off). */
  errors: number;
  /** Frames concealed so far. */
  concealedFrames: number;
  /** Output samples of delay added by the limiter. */
  delay: number;
  hasSbr: boolean;
  hasPs: boolean;
  /** The object type of the stream. */
  objectType: AacObjectType;
  /** The program reference level the last frame carried, or -1. */
  programRefLevel: number;
  /** The last frame carried dynamic range control data. */
  hasDrc: boolean;
  /** Interleaved float PCM, full scale 1.0. */
  pcm: Float32Array;
}

export interface AacEncoderSession {
  id: number;
  profile: AacProfile;
  /** Samples per channel per frame: 1024, or 2048 for the HE-AAC profiles. */
  frameSize: number;
  /** Samples of lead-in before the first input sample in the decoded output. */
  delay: number;
  /** The AudioSpecificConfig of the stream. */
  config: Uint8Array;
}

/**
 * AAC codec bridge to the native baander-aac binding in the main process.
 * One-shot calls take a whole stream or signal; sessions decode or encode
 * chunk by chunk and must be closed when done.
 */
export const aacBridge = {
  info: (): Promise<AacInfo> => ipcRenderer.invoke('baander:aac:info'),

  decode: (data: Uint8Array, options?: AacDecoderOptions): Promise<AacDecodeResult> =>
    ipcRenderer.invoke('baander:aac:decode', data, options),

  encode: (pcm: Float32Array, options: AacEncoderOptions): Promise<Uint8Array> =>
    ipcRenderer.invoke('baander:aac:encode', pcm, options),

  openDecoder: (options?: AacDecoderOptions): Promise<number> =>
    ipcRenderer.invoke('baander:aac:decoder-open', options),

  decodeChunk: (id: number, chunk: Uint8Array): Promise<AacDecodeResult> =>
    ipcRenderer.invoke('baander:aac:decoder-decode', id, chunk),

  closeDecoder: (id: number): Promise<boolean> => ipcRenderer.invoke('baander:aac:decoder-close', id),

  openEncoder: (options: AacEncoderOptions): Promise<AacEncoderSession> =>
    ipcRenderer.invoke('baander:aac:encoder-open', options),

  encodeChunk: (id: number, pcm: Float32Array): Promise<Uint8Array> =>
    ipcRenderer.invoke('baander:aac:encoder-encode', id, pcm),

  flushEncoder: (id: number): Promise<Uint8Array> => ipcRenderer.invoke('baander:aac:encoder-flush', id),

  closeEncoder: (id: number): Promise<boolean> => ipcRenderer.invoke('baander:aac:encoder-close', id),
} as const;

export type AacBridge = typeof aacBridge;
