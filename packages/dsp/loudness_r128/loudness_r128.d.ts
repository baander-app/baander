/**
 * Optional oversampling factors for true-peak estimation.
 * Matches C++ init_loudness(sample_rate, truepeak_oversample).
 */
declare const enum TruePeakOversample {
  x1 = 1,
  x2 = 2,
  x4 = 4,
}

/**
 * Low-level WASM API exported by the R128 loudness module.
 * This maps directly to the underlying C++ exports; JS loader may map underscored names via pick().
 */
declare interface LoudnessR128API {
  /**
   * WebAssembly linear memory to create typed views over.
   */
  memory: WebAssembly.Memory;

  /**
   * Initialize loudness state.
   * @param sampleRate Audio sample rate in Hz.
   * @param truePeakOversample Oversampling factor for true-peak estimation (1, 2, or 4).
   */
  init(sampleRate: number, truePeakOversample: TruePeakOversample | 1 | 2 | 4): void;

  /**
   * Reset internal state to defaults (reuses the last init parameters).
   */
  reset(): void;

  /**
   * Feed interleaved frames to the analyzer.
   * Buffer must be Float32 interleaved LR (if channels === 1, R is mirrored from L).
   * @param inputPtr Byte offset (pointer) into WASM memory where samples start.
   * @param frames Number of frames in the provided block.
   * @param channels Number of channels (1 = mono, 2 = stereo).
   */
  process(inputPtr: number, frames: number, channels: number): void;

  /**
   * Momentary loudness (LUFS), ~400 ms window.
   */
  lufsM(): number;

  /**
   * Short-term loudness (LUFS), ~3 s window.
   */
  lufsS(): number;

  /**
   * Integrated loudness (LUFS) with gating.
   */
  lufsI(): number;

  /**
   * Loudness range (LRA) computed from recent history.
   */
  lra(): number;

  /**
   * True-peak in dBFS estimated with the configured oversample factor.
   */
  truePkDbfs(): number;
}

/**
 * Load and instantiate the loudness WebAssembly module.
 * The loader resolves both underscored and plain export names.
 * @param url Optional URL to the .wasm binary. Defaults to './loudness_r128.wasm' (module-relative).
 */
declare function loadLoudness(url?: string): Promise<LoudnessR128API>;
