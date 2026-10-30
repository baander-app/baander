import { ipcRenderer } from 'electron';

export type AacTransport = 'detect' | 'adts' | 'loas' | 'latm' | 'latm-raw' | 'raw';
export type AacSignaling = 'implicit' | 'explicit' | 'hierarchical';
export type AacConcealment = 'off' | 'noise' | 'interpolate';
export type AacChannelMode = 'default' | '7.1' | '7.1-front' | '7.1-top' | '7.1-rear';
/** 'usac' is USAC / xHE-AAC (audio object type 42), which the encoder writes as the 'xhe' profile. */
export type AacObjectType = 'lc' | 'er-lc' | 'ld' | 'eld' | 'usac' | 'unknown';
export type AacDrcProfile = 'none' | 'film-standard' | 'film-light' | 'music-standard' | 'music-light' | 'speech';
/** The MPEG-D DRC effect requested of a USAC stream; 'none' limits DRC to clipping prevention, 'off' applies none. */
export type AacDrcEffect = 'off' | 'none' | 'night' | 'noisy' | 'limited' | 'lowlevel' | 'dialog' | 'general';

/** Loudness normalisation of a USAC stream from its MPEG-D loudness metadata. */
export interface AacLoudnessOptions {
  /** Default true. */
  enabled?: boolean;
  /** Target program loudness in LUFS, -31 to -10. Default -24. */
  targetLufs?: number;
}

/** The MPEG-D loudness state of a USAC stream after the last decoded frame. */
export interface AacLoudness {
  /** Declared program loudness in LUFS, or null when the stream declares none (and for every other object type). */
  programLoudness: number | null;
  /** Normalisation gain applied in dB (0 when none is). */
  normalizationGain: number;
}

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
  /** USAC loudness normalisation; on by default at -24 LUFS. */
  loudness?: boolean | AacLoudnessOptions;
  /** The MPEG-D DRC effect requested of a USAC stream. Default 'none'. */
  drcEffect?: AacDrcEffect;
  /** Use the album loudness and DRC gains when the USAC stream has them. */
  albumMode?: boolean;
  /** Share of the MPEG-D DRC boosting gains, in percent. Default 100. */
  drcBoost?: number;
  /** Share of the MPEG-D DRC attenuating gains, in percent. Default 100. */
  drcCut?: number;
}

export type AacRateControlMode = 'cbr' | 'abr' | 'cvbr' | 'tvbr';
/** AAC-LC, HE-AAC v1 (SBR), HE-AAC v2 (SBR and parametric stereo), AAC-LD, AAC-ELD or xHE-AAC (USAC; mono or stereo, LOAS by default). */
export type AacProfile = 'lc' | 'he' | 'hev2' | 'ld' | 'eld' | 'xhe';
/** A USAC tool switched on, off, or left to the encoder's choice by bitrate. */
export type AacUsacTool = 'on' | 'off' | 'auto';

/** The xHE-AAC encoder's options ('xhe' only). */
export interface AacUsacOptions {
  /** Noise filling of the lines quantised to zero. Default true. */
  noiseFilling?: boolean;
  /** The audio pre-roll at every independent frame. Default true. */
  preroll?: boolean;
  /**
   * The distance between immediate playout frames in frames, milliseconds
   * or output samples (samples win over milliseconds over frames); none
   * keeps the default of one second.
   */
  independentIntervalFrames?: number;
  independentIntervalMs?: number;
  independentIntervalSamples?: number;
  /** An upper bound in frames on the distance between independent frames. */
  independentMaxDistanceFrames?: number;
  /** A ceiling in bytes on each segment from one immediate playout frame to the next. */
  segmentMaxBytes?: number;
  /** The stream identifier in the configuration, 0..65535. */
  streamId?: number;
  /** Enhanced SBR at 2:1; 'auto' (default) below 64 kbit/s per channel. */
  sbr?: AacUsacTool;
  /**
   * MPEG Surround 2-1-2 for stereo input; 'auto' (default) leaves it off,
   * as FFmpeg cannot decode it. It runs inside SBR, so 'on' brings SBR
   * with it and is refused together with `sbr: 'off'`.
   */
  mps212?: AacUsacTool;
}

/**
 * A programme's loudness figures as the xHE-AAC encoder writes them into
 * the stream's loudness info set: the integrated loudness and the sample
 * peak are needed, the rest is written when given (levels in LKFS, dBTP
 * and dBFS, the range in LU, coded at 0.25 dB).
 */
export interface AacLoudnessInfo {
  integratedLkfs: number;
  samplePeakDbfs: number;
  truePeakDbtp?: number;
  rangeLu?: number;
  anchorLkfs?: number;
  maxMomentaryLkfs?: number;
  maxShortTermLkfs?: number;
}

/**
 * What the loudness meter measures (ITU-R BS.1770-4, EBU Tech 3341/3342);
 * the range and the short-term maximum need 3 s of input and are null
 * before that. Accepted as `loudnessInfo` for a second encode.
 */
export interface AacLoudnessMeasurement {
  integratedLkfs: number;
  samplePeakDbfs: number;
  truePeakDbtp: number | null;
  rangeLu: number | null;
  maxMomentaryLkfs: number | null;
  maxShortTermLkfs: number | null;
}

/** What a muxer needs to know about the last frame an xHE-AAC encoder session produced. */
export interface AacEncoderFrameInfo {
  frameIndex: number;
  /** A decoder may start here. */
  independent: boolean;
  /** Bytes of the audio pre-roll; a frame with one opens a segment. */
  prerollBytes: number;
  bytes: number;
  /** The frame's first decoded sample on the input's timeline (negative for the priming frames). */
  samplePosition: number;
}

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
  /** A cap in bit/s on any single frame; 0 for none. */
  peakBitrate?: number;
  /** The core's lowpass in Hz (1000 or more); 0 keeps the tuning table. */
  bandwidth?: number;
  /** An FDK-named VBR mode, about 32/72/112/148/228 kbit/s for stereo. */
  vbrMode?: 1 | 2 | 3 | 4 | 5;
  /** Dynamic range control metadata written into the stream ('lc', 'he' and 'hev2' only). */
  metadata?: AacMetadataOptions;
  /** 'xhe' only: the USAC encoder's options. */
  usac?: AacUsacOptions;
  /** 'xhe' only: the loudness info set written into the stream's configuration (two-pass). */
  loudnessInfo?: AacLoudnessInfo;
  /**
   * Meter the input as it is encoded (one-pass); a session reads the
   * figures with `encoderLoudness`. The stream it was measured from
   * cannot carry them: they are for an MP4 header or a second encode.
   */
  measureLoudness?: boolean;
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
  /**
   * Samples per channel the decoder delays its output by beyond the
   * encoder's priming: the limiter's look-ahead, plus the surround
   * decoder's delay for an MPEG Surround stream. A flush returns this
   * many samples.
   */
  delay: number;
  hasSbr: boolean;
  hasPs: boolean;
  /** The object type of the stream. */
  objectType: AacObjectType;
  /** The program reference level the last frame carried, or -1. */
  programRefLevel: number;
  /** The last frame carried dynamic range control data. */
  hasDrc: boolean;
  /** The MPEG-D loudness of a USAC stream (null program loudness and 0 dB for every other object type). */
  loudness: AacLoudness;
  /** Interleaved float PCM, full scale 1.0. */
  pcm: Float32Array;
}

export interface AacEncoderSession {
  id: number;
  profile: AacProfile;
  /**
   * Samples per channel per frame: 1024, or 2048 for the HE-AAC profiles
   * and for 'xhe' while it runs SBR (by default stereo below 128 kbit/s
   * and mono below 64 kbit/s, or as the `sbr` option says).
   */
  frameSize: number;
  /**
   * Samples of lead-in before the first input sample in the decoded
   * output: 2048 for AAC-LC, 5635 for the HE-AAC profiles and for 'xhe'
   * while it runs SBR.
   */
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

  /** Ends the stream: the samples the decoder still held; the session starts over. */
  flushDecoder: (id: number): Promise<AacDecodeResult> => ipcRenderer.invoke('baander:aac:decoder-flush', id),

  /** Prepares a seek: forgets the audio before, keeps the configuration. */
  clearDecoderHistory: (id: number): Promise<void> => ipcRenderer.invoke('baander:aac:decoder-clear-history', id),

  closeDecoder: (id: number): Promise<boolean> => ipcRenderer.invoke('baander:aac:decoder-close', id),

  openEncoder: (options: AacEncoderOptions): Promise<AacEncoderSession> =>
    ipcRenderer.invoke('baander:aac:encoder-open', options),

  encodeChunk: (id: number, pcm: Float32Array): Promise<Uint8Array> =>
    ipcRenderer.invoke('baander:aac:encoder-encode', id, pcm),

  flushEncoder: (id: number): Promise<Uint8Array> => ipcRenderer.invoke('baander:aac:encoder-flush', id),

  /** The loudness measured so far by a session opened with `measureLoudness`, or null. */
  encoderLoudness: (id: number): Promise<AacLoudnessMeasurement | null> =>
    ipcRenderer.invoke('baander:aac:encoder-loudness', id),

  /** xHE-AAC only: the next frame is independent and the pre-roll follows. */
  requestIndependentFrame: (id: number): Promise<void> =>
    ipcRenderer.invoke('baander:aac:encoder-request-independent-frame', id),

  /** xHE-AAC only: the last frame the session produced, or null. */
  encoderLastFrame: (id: number): Promise<AacEncoderFrameInfo | null> =>
    ipcRenderer.invoke('baander:aac:encoder-last-frame', id),

  closeEncoder: (id: number): Promise<boolean> => ipcRenderer.invoke('baander:aac:encoder-close', id),

  /** Measures a whole signal (1 to 6 channels, canonical order) with the BS.1770-4 meter. */
  measureLoudness: (pcm: Float32Array, sampleRate: number, channels: number): Promise<AacLoudnessMeasurement> =>
    ipcRenderer.invoke('baander:aac:measure-loudness', pcm, sampleRate, channels),
} as const;

export type AacBridge = typeof aacBridge;
