import { ipcRenderer } from 'electron';

/** Options of the native AAC decoder; see @baander/aac-native. */
export interface AacDecoderOptions {
  sampleRate?: number;
  channels?: number;
}

export type AacRateControlMode = 'cbr' | 'abr' | 'cvbr' | 'tvbr';

/** Options of the native AAC encoder; see @baander/aac-native. */
export interface AacEncoderOptions {
  sampleRate?: number;
  channels?: number;
  bitrate?: number;
  mode?: AacRateControlMode;
  quality?: number;
}

export interface AacInfo {
  available: boolean;
  version: string | null;
  error: string | null;
}

export interface AacDecodeResult {
  sampleRate: number;
  channels: number;
  frameSize: number;
  errors: number;
  hasSbr: boolean;
  /** Interleaved float PCM, full scale 1.0. */
  pcm: Float32Array;
}

export interface AacEncoderSession {
  id: number;
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
