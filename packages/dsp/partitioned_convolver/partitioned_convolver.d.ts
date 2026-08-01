/**
 * Opaque handle returned by create(); represents a native convolver context.
 * It's a pointer (byte offset) into WASM memory.
 */
declare type ConvolverHandle = number;

/**
 * Low-level WASM API exported by the partitioned convolver module.
 * Maps directly to the underlying C++ exports; the JS loader resolves
 * both underscored and plain names via a pick() helper.
 */
declare interface PartitionedConvolverAPI {
  /**
   * WebAssembly linear memory to create typed views over.
   */
  memory: WebAssembly.Memory;

  /**
   * Create a convolver context.
   * @param irPtr Byte offset (pointer) to Float32Array[irLength] impulse response.
   * @param irLength Number of IR samples (mono IR expected; applied per channel).
   * @param blockSize Processing block size (frames per internal step).
   * @param channels Number of channels in interleaved input/output.
   * @returns Opaque handle to the created context (pointer).
   */
  create(irPtr: number, irLength: number, blockSize: number, channels: number): ConvolverHandle;

  /**
   * Convolve interleaved float32 input into interleaved float32 output.
   * The channel count is the one provided at create(), and buffers must be interleaved
   * accordingly: LRLR... for stereo, or mono if channels === 1.
   * @param ctx Convolver handle returned by create().
   * @param inPtr Byte offset (pointer) to Float32Array[frames * channels] input.
   * @param outPtr Byte offset (pointer) to Float32Array[frames * channels] output.
   * @param frames Number of frames to process.
   */
  process(ctx: ConvolverHandle, inPtr: number, outPtr: number, frames: number): void;

  /**
   * Destroy a previously created convolver context.
   * @param ctx Convolver handle.
   */
  destroy(ctx: ConvolverHandle): void;
}

/**
 * Load and instantiate the partitioned convolver WebAssembly module.
 * The loader resolves both underscored and plain export names.
 * @param url Optional URL to the .wasm binary. Defaults to './partitioned_convolver.wasm' (module-relative).
 */
declare function loadConvolver(url?: string): Promise<PartitionedConvolverAPI>;
