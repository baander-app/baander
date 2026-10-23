import { ipcRenderer } from 'electron';

/** Options of the native AAC decoder; see @baander/aac-native. */
export interface AacDecoderOptions {
  sampleRate?: number;
  /** 0 for the stream's own channels, 1 or 2 for a downmix, up to 8. */
  channels?: number;
}

export type AacRateControlMode = 'cbr' | 'abr' | 'cvbr' | 'tvbr';
/** AAC-LC, HE-AAC v1 (SBR) or HE-AAC v2 (SBR and parametric stereo). */
export type AacProfile = 'lc' | 'he' | 'hev2';

/** Options of the native AAC encoder; see @baander/aac-native. */
export interface AacEncoderOptions {
  sampleRate?: number;
  /** 1 to 8 in the canonical order; 'hev2' takes exactly 2. */
  channels?: number;
  bitrate?: number;
  mode?: AacRateControlMode;
  quality?: number;
  profile?: AacProfile;
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
  frameSize: number;
  errors: number;
  hasSbr: boolean;
  hasPs: boolean;
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
