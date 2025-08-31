export interface DynamicsMeterAPI {
  /**
   * Underlying WebAssembly linear memory.
   * Use to create typed views for passing data to the WASM side.
   */
  memory: WebAssembly.Memory;

  /**
   * Initialize meter state and time constants.
   * @param attackMs Attack time in milliseconds.
   * @param releaseMs Release time in milliseconds.
   * @param sampleRate Audio sample rate (Hz).
   */
  init(attackMs: number, releaseMs: number, sampleRate: number): void;

  /**
   * Reset internal meter state (RMS/Peak envelopes).
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
   * Smoothed RMS (linear) of left channel.
   */
  rmsL(): number;

  /**
   * Smoothed RMS (linear) of right channel.
   */
  rmsR(): number;

  /**
   * Smoothed peak (linear) of left channel.
   */
  peakL(): number;

  /**
   * Smoothed peak (linear) of right channel.
   */
  peakR(): number;

  /**
   * Crest factor (dB) of left channel: 20*log10(peak/rms).
   */
  crestL(): number;

  /**
   * Crest factor (dB) of right channel: 20*log10(peak/rms).
   */
  crestR(): number;
}

/**
 * Load and instantiate the dynamics meter WebAssembly module.
 * @param url Optional URL to the .wasm binary. Defaults to './dynamics_meter.wasm' (module-relative).
 */
export function loadDynamics(url?: string): Promise<DynamicsMeterAPI>;
