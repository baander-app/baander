/**
 * Opaque handle returned by decoderCreate(); represents a native AAC decoder context.
 * It's a pointer (byte offset) into WASM memory.
 */
declare type AacDecoderHandle = number;

/**
 * Low-level WASM API exported by the Baander AAC decoder module.
 * Maps directly to the underlying C++ exports; the JS loader resolves
 * both underscored and plain names via a pick() helper.
 */
declare interface BaanderAacAPI {
  /**
   * WebAssembly linear memory to create typed views over.
   */
  memory: WebAssembly.Memory;

  /**
   * Create an AAC decoder context.
   * @param sample_rate Sample rate in Hz (e.g. 44100, 48000).
   * @param channels Number of audio channels (1=mono, 2=stereo).
   * @returns Opaque handle to the created decoder context (pointer).
   */
  decoderCreate(sample_rate: number, channels: number): AacDecoderHandle;

  /**
   * Destroy a previously created AAC decoder context.
   * @param ctx Decoder handle.
   */
  decoderDestroy(ctx: AacDecoderHandle): void;

  /**
   * Decode one AAC frame into PCM float output.
   * @param ctx Decoder handle.
   * @param dataPtr Byte offset to encoded AAC data in WASM memory.
   * @param dataSize Size of encoded data in bytes.
   * @param pcmPtr Byte offset to Float32Array output buffer in WASM memory.
   * @param pcmSize Size of PCM output buffer in floats.
   * @returns Number of decoded samples (per channel), or negative error code.
   */
  decoderDecode(
    ctx: AacDecoderHandle,
    dataPtr: number,
    dataSize: number,
    pcmPtr: number,
    pcmSize: number
  ): number;

  /**
   * Returns the decoder frame size in samples (typically 1024 for AAC-LC).
   * @param ctx Decoder handle.
   */
  decoderFrameSize(ctx: AacDecoderHandle): number;

  /**
   * Returns the configured sample rate.
   * @param ctx Decoder handle.
   */
  decoderSampleRate(ctx: AacDecoderHandle): number;

  /**
   * Returns the configured channel count.
   * @param ctx Decoder handle.
   */
  decoderChannels(ctx: AacDecoderHandle): number;

  /**
   * Query whether the decoder has activated SBR and/or PS.
   * @param ctx Decoder handle.
   * @param hasSbrPtr Pointer to int to receive SBR flag.
   * @param hasPsPtr Pointer to int to receive PS flag.
   * @returns 0 on success, negative error code on failure.
   */
  decoderGetSbrPs(
    ctx: AacDecoderHandle,
    hasSbrPtr: number,
    hasPsPtr: number
  ): number;
}

/**
 * Load and instantiate the Baander AAC decoder WebAssembly module.
 * The loader resolves both underscored and plain export names.
 * @param url Optional URL to the .wasm binary. Defaults to './baander-aac.wasm' (module-relative).
 */
declare function loadAacDecoder(url?: string): Promise<BaanderAacAPI>;

export { loadAacDecoder, BaanderAacAPI, AacDecoderHandle };
