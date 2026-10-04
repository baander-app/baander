declare interface DynamicsMeterAPI {
  /**
   * Underlying WebAssembly linear memory.
   * Use to create typed views for passing data to the WASM side.
   */
  memory: WebAssembly.Memory;
  /** Allocate a caller-owned input buffer in bytes. */
  malloc(bytes: number): number;
  /** Free a caller-owned input buffer. */
  free(ptr: number): void;

  /**
   * Initialize a zero-padded rectangular RMS window and sample-peak envelope.
   * @param rmsWindowMs RMS window duration in milliseconds, rounded to frames (1–384000).
   * @param releaseMs Peak decay time in milliseconds (exp(-1) per time constant).
   * @param sampleRate Audio sample rate (Hz).
   */
  init(rmsWindowMs: number, releaseMs: number, sampleRate: number): void;

  /**
   * Reset RMS window history and peak envelopes, preserving settings.
   */
  reset(): void;

  /**
   * Process interleaved audio frames from a pointer in WASM memory.
   * The buffer is expected to be Float32 interleaved LR (or mono if channels === 1).
   * @param inputPtr Byte offset (pointer) into WASM memory where samples start.
   * @param frames Number of frames to process.
   * @param channels Number of channels (1 = mono, 2 = stereo).
   */
  process(inputPtr: number, frames: number, channels: number): void;

  /**
   * Rectangular-window RMS (linear) of left channel, initially zero-padded.
   */
  rmsL(): number;

  /**
   * Rectangular-window RMS (linear) of right channel, initially zero-padded.
   */
  rmsR(): number;

  /**
   * Instantly captured sample-peak envelope (linear) of left channel.
   */
  peakL(): number;

  /**
   * Instantly captured sample-peak envelope (linear) of right channel.
   */
  peakR(): number;

  /**
   * Envelope ratio (dB) of left channel: 20*log10(peak/rms), zero at zero RMS.
   */
  crestL(): number;

  /**
   * Envelope ratio (dB) of right channel: 20*log10(peak/rms), zero at zero RMS.
   */
  crestR(): number;
}

/**
 * Load and instantiate the dynamics meter WebAssembly module.
 * @param url Optional URL to the .wasm binary. Defaults to './dynamics_meter.wasm' (module-relative).
 */
declare function loadDynamics(url?: string): Promise<DynamicsMeterAPI>;
