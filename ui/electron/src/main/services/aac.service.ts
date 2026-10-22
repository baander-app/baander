/**
 * AAC codec service: the native baander-aac binding (@baander/aac-native)
 * loaded in the main process, offered as one-shot calls and as streaming
 * sessions for the renderer.  The binding is optional at runtime: when it
 * is missing or fails to load, the service reports itself unavailable and
 * every operation rejects with a descriptive error instead of crashing
 * the app.
 */
import type { AacDecoder, AacDecoderOptions, AacEncoder, AacEncoderOptions } from '@baander/aac-native';

type Native = {
  AacDecoder: typeof AacDecoder;
  AacEncoder: typeof AacEncoder;
  version: string;
};

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
  pcm: Float32Array;
}

export interface AacEncoderSession {
  id: number;
  frameSize: number;
  delay: number;
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

  /** Decodes a complete ADTS stream. */
  async decode(data: Uint8Array, options: AacDecoderOptions = {}): Promise<AacDecodeResult> {
    const decoder = new (await this.native()).AacDecoder(options);
    return describe(decoder, decoder.decode(data));
  }

  /** Encodes interleaved PCM into a complete ADTS stream. */
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
    const decoder = this.decoders.get(id);
    if (!decoder) throw new Error(`AAC decoder session ${id} does not exist`);
    return describe(decoder, decoder.decode(chunk));
  }

  async closeDecoder(id: number): Promise<boolean> {
    return this.decoders.delete(id);
  }

  async openEncoder(options: AacEncoderOptions): Promise<AacEncoderSession> {
    this.ensureCapacity();
    const encoder = new (await this.native()).AacEncoder(options);
    const id = this.nextId++;
    this.encoders.set(id, encoder);
    return { id, frameSize: encoder.frameSize, delay: encoder.delay };
  }

  async encodeChunk(id: number, pcm: Float32Array): Promise<Uint8Array> {
    return toBytes(this.encoder(id).encode(pcm));
  }

  /** Emits the final frames; the session stays open for another stream. */
  async flushEncoder(id: number): Promise<Uint8Array> {
    return toBytes(this.encoder(id).flush());
  }

  async closeEncoder(id: number): Promise<boolean> {
    return this.encoders.delete(id);
  }

  /** Drops every session, e.g. when the renderer that owned them goes away. */
  closeAll(): void {
    this.decoders.clear();
    this.encoders.clear();
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
    frameSize: decoder.frameSize,
    errors: decoder.errors,
    hasSbr: decoder.hasSbr,
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
