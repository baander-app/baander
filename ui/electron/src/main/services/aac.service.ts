/**
 * AAC codec service: the native baander-aac binding (@baander/aac-native)
 * loaded in the main process, offered as one-shot calls and as streaming
 * sessions for the renderer.  The binding is optional at runtime: when it
 * is missing or fails to load, the service reports itself unavailable and
 * every operation rejects with a descriptive error instead of crashing
 * the app.
 */
import type {
  AacChannelMode,
  AacLoudness,
  AacLoudnessMeasurement,
  AacObjectType,
  AacDecoder,
  AacDecoderOptions,
  AacEncoder,
  AacEncoderFrameInfo,
  AacEncoderOptions,
  AacProfile,
  measureLoudness,
} from '@baander/aac-native';

type Native = {
  AacDecoder: typeof AacDecoder;
  AacEncoder: typeof AacEncoder;
  measureLoudness: typeof measureLoudness;
  version: string;
};

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
  /** The stream's 7.1 layout, or 'default'. */
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
  /** The object type of the stream: 'lc', 'er-lc', 'ld', 'eld', 'usac' or 'unknown'. */
  objectType: AacObjectType;
  /** The program reference level the last frame carried, or -1. */
  programRefLevel: number;
  /** The last frame carried dynamic range control data. */
  hasDrc: boolean;
  /**
   * The MPEG-D loudness of a USAC stream: the declared program loudness in
   * LUFS (null when none, and for every other object type) and the
   * normalisation gain applied in dB.
   */
  loudness: AacLoudness;
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

const MAX_SESSIONS = 64;

type Loaded = { native: Native | null; error: string | null };

let loading: Promise<Loaded> | null = null;

/**
 * Loads the binding once.  BAANDER_AAC_NATIVE_MODULE names the module to
 * import instead of the installed package (a path or file URL of its
 * entry), for development and tests.
 */
export function loadAacNative(): Promise<Loaded> {
  if (!loading) {
    const specifier = process.env.BAANDER_AAC_NATIVE_MODULE || '@baander/aac-native';
    loading = import(specifier).then(
      (module: Native) => ({ native: module, error: null }),
      (error: unknown) => ({ native: null, error: error instanceof Error ? error.message : String(error) }),
    );
  }
  return loading;
}

/** For tests: forget the cached binding so the next call loads again. */
export function resetAacNativeForTests(): void {
  loading = null;
}

export class AacService {
  private readonly decoders = new Map<number, AacDecoder>();
  private readonly encoders = new Map<number, AacEncoder>();
  private nextId = 1;

  async info(): Promise<AacInfo> {
    const { native, error } = await loadAacNative();
    return { available: native !== null, version: native?.version ?? null, error };
  }

  async isAvailable(): Promise<boolean> {
    return (await loadAacNative()).native !== null;
  }

  private async native(): Promise<Native> {
    const { native, error } = await loadAacNative();
    if (!native) throw new Error(`AAC codec unavailable: ${error}`);
    return native;
  }

  /** Decodes a complete stream (ADTS, LOAS, or one LATM element or access unit). */
  async decode(data: Uint8Array, options: AacDecoderOptions = {}): Promise<AacDecodeResult> {
    const decoder = new (await this.native()).AacDecoder(options);
    return describe(decoder, decoder.decode(data));
  }

  /**
   * Measures a whole interleaved signal with the BS.1770-4 loudness meter
   * (1 to 6 channels in the canonical order); the result is the
   * `loudnessInfo` of an xHE-AAC encode that follows.
   */
  async measureLoudness(pcm: Float32Array, sampleRate: number, channels: number): Promise<AacLoudnessMeasurement> {
    return (await this.native()).measureLoudness(pcm, sampleRate, channels);
  }

  /**
   * Encodes interleaved PCM into a complete stream in the chosen
   * transport. With `profile: 'xhe'` the options take the USAC settings
   * (`usac`) and a `loudnessInfo` set written into the stream.
   */
  async encode(pcm: Float32Array, options: AacEncoderOptions): Promise<Uint8Array> {
    const encoder = new (await this.native()).AacEncoder(options);
    const body = encoder.encode(pcm);
    const tail = encoder.flush();
    const out = new Uint8Array(body.length + tail.length);
    out.set(body, 0);
    out.set(tail, body.length);
    return out;
  }

  async openDecoder(options: AacDecoderOptions = {}): Promise<number> {
    this.ensureCapacity();
    const decoder = new (await this.native()).AacDecoder(options);
    const id = this.nextId++;
    this.decoders.set(id, decoder);
    return id;
  }

  async decodeChunk(id: number, chunk: Uint8Array): Promise<AacDecodeResult> {
    const decoder = this.decoder(id);
    return describe(decoder, decoder.decode(chunk));
  }

  /**
   * Ends the stream: the result carries the last `delay` samples per
   * channel the decoder still held (none without a limiter or surround),
   * described as the stream was before the end. The session stays open
   * for another stream, starting over.
   */
  async flushDecoder(id: number): Promise<AacDecodeResult> {
    const decoder = this.decoder(id);
    const result = describe(decoder, new Float32Array(0));
    result.pcm = decoder.flush();
    return result;
  }

  /**
   * Prepares a seek: drops the pending bytes and the decoder's memory of
   * the audio before, keeping the stream's configuration, so that the
   * chunks from the new position decode as in a fresh session. An
   * xHE-AAC stream resumes at an independent frame.
   */
  async clearDecoderHistory(id: number): Promise<void> {
    this.decoder(id).clearHistory();
  }

  async closeDecoder(id: number): Promise<boolean> {
    return this.decoders.delete(id);
  }

  async openEncoder(options: AacEncoderOptions): Promise<AacEncoderSession> {
    this.ensureCapacity();
    const encoder = new (await this.native()).AacEncoder(options);
    const id = this.nextId++;
    this.encoders.set(id, encoder);
    return {
      id,
      profile: encoder.profile,
      frameSize: encoder.frameSize,
      delay: encoder.delay,
      config: toBytes(encoder.config),
    };
  }

  async encodeChunk(id: number, pcm: Float32Array): Promise<Uint8Array> {
    return toBytes(this.encoder(id).encode(pcm));
  }

  /** Emits the final frames; the session stays open for another stream. */
  async flushEncoder(id: number): Promise<Uint8Array> {
    return toBytes(this.encoder(id).flush());
  }

  /**
   * The loudness of the input encoded so far by a session opened with
   * `measureLoudness: true`: null before 400 ms of input and for a
   * session without the meter.
   */
  async encoderLoudness(id: number): Promise<AacLoudnessMeasurement | null> {
    return this.encoder(id).loudness;
  }

  /**
   * xHE-AAC only: makes the next frame independent, so that the immediate
   * playout frame with the pre-roll follows it; the binding throws for
   * the other profiles.
   */
  async requestIndependentFrame(id: number): Promise<void> {
    this.encoder(id).requestIndependentFrame();
  }

  /**
   * xHE-AAC only: the last frame the session produced (its index, whether
   * it is independent, the pre-roll and frame sizes and its sample
   * position), for a muxer that cuts segments; null before the first
   * frame and for the other profiles.
   */
  async encoderLastFrame(id: number): Promise<AacEncoderFrameInfo | null> {
    return this.encoder(id).lastFrame;
  }

  async closeEncoder(id: number): Promise<boolean> {
    return this.encoders.delete(id);
  }

  /** Drops every session, e.g. when the renderer that owned them goes away. */
  closeAll(): void {
    this.decoders.clear();
    this.encoders.clear();
  }

  private decoder(id: number): AacDecoder {
    const decoder = this.decoders.get(id);
    if (!decoder) throw new Error(`AAC decoder session ${id} does not exist`);
    return decoder;
  }

  private encoder(id: number): AacEncoder {
    const encoder = this.encoders.get(id);
    if (!encoder) throw new Error(`AAC encoder session ${id} does not exist`);
    return encoder;
  }

  private ensureCapacity(): void {
    if (this.decoders.size + this.encoders.size >= MAX_SESSIONS) {
      throw new Error(`AAC codec: more than ${MAX_SESSIONS} open sessions`);
    }
  }
}

function describe(decoder: AacDecoder, pcm: Float32Array): AacDecodeResult {
  return {
    sampleRate: decoder.sampleRate,
    channels: decoder.channels,
    streamChannels: decoder.streamChannels,
    channelMode: decoder.channelMode,
    frameSize: decoder.frameSize,
    errors: decoder.errors,
    concealedFrames: decoder.concealedFrames,
    delay: decoder.delay,
    hasSbr: decoder.hasSbr,
    hasPs: decoder.hasPs,
    objectType: decoder.objectType,
    programRefLevel: decoder.programRefLevel,
    hasDrc: decoder.hasDrc,
    loudness: decoder.loudness(),
    pcm,
  };
}

function toBytes(buffer: Uint8Array): Uint8Array {
  // A Node Buffer crosses IPC as a plain Uint8Array; copy to detach it from the pool.
  return new Uint8Array(buffer);
}

let service: AacService | null = null;

export function getAacService(): AacService {
  if (!service) service = new AacService();
  return service;
}
