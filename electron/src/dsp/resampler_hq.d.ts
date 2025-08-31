/**
 * Opaque handle returned by create(); represents a native resampler context.
 * It's a pointer (byte offset) into WASM memory.
 */
export type ResamplerHandle = number;

/**
 * Low-level WASM API exported by the high-quality resampler module.
 * Maps directly to the underlying C++ exports; the JS loader resolves
 * both underscored and plain names via a pick() helper.
 */
export interface ResamplerHQAPI {
  /**
   * WebAssembly linear memory to create typed views over.
   */
  memory: WebAssembly.Memory;

  /**
   * Create a resampler context.
   * @param inRate Input sample rate (Hz).
   * @param outRate Output sample rate (Hz).
   * @param channels Number of channels in interleaved input/output.
   * @param quality Kernel taps (quality hint). Implementation clamps to [8..128], default ~32 if <= 0.
   * @returns Opaque handle to the created context (pointer).
   */
  create(inRate: number, outRate: number, channels: number, quality: number): ResamplerHandle;

  /**
   * Destroy a previously created resampler context.
   * @param ctx Resampler handle.
   */
  destroy(ctx: ResamplerHandle): void;

  /**
   * Resample interleaved float32 input into interleaved float32 output.
   * Uses a windowed-sinc kernel. Returns the number of output frames written.
   *
   * Buffers must be interleaved according to the channel count specified at create():
   * LRLR... for stereo, or mono if channels === 1.
   *
   * @param ctx Resampler handle.
   * @param inPtr Byte offset (pointer) to Float32Array[inFrames * channels] input.
   * @param inFrames Number of input frames available at inPtr.
   * @param outPtr Byte offset (pointer) to Float32Array[outCapacityFrames * channels] output buffer.
   * @param outCapacityFrames Maximum number of frames that can be written to outPtr.
   * @returns Number of output frames actually written (<= outCapacityFrames).
   */
  resample(
    ctx: ResamplerHandle,
    inPtr: number,
    inFrames: number,
    outPtr: number,
    outCapacityFrames: number
  ): number;
}

/**
 * Load and instantiate the resampler WebAssembly module.
 * The loader resolves both underscored and plain export names.
 * @param url Optional URL to the .wasm binary. Defaults to './resampler_hq.wasm' (module-relative).
 */
export function loadResampler(url?: string): Promise<ResamplerHQAPI>;
