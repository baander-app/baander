/**
 * Messages sent from the main thread to the WasmSpectrumProcessor.
 */
declare type WasmSpectrumToProcessorMessage =
  | {
  type: 'wasm';
  /**
   * Raw bytes of the FFT WebAssembly module (e.g., fetched via fetch(...).arrayBuffer()).
   */
  bytes: ArrayBuffer;
};

/**
 * Messages posted by the WasmSpectrumProcessor back to the main thread.
 */
declare type WasmSpectrumFromProcessorMessage =
  | {
  type: 'ready';
}
  | {
  type: 'error';
  reason: 'missing-exports' | 'instantiate-failed';
  message?: string;
}
  | {
  type: 'spectrum';
  /**
   * Downsampled frequency magnitudes (256 bins, 0..255 byte range).
   */
  frequencyData: Uint8Array;
  /**
   * Downsampled waveform (512 samples, 0..255 byte range).
   */
  timeDomainData: Uint8Array;
};

/**
 * The registered AudioWorkletProcessor name.
 */
declare const WASM_SPECTRUM_PROCESSOR_NAME = 'wasm-spectrum';

/**
 * Utility interface for a typed AudioWorklet node/port that communicates with the WasmSpectrumProcessor.
 * Note: This is a compile-time helper; the runtime object will be a normal AudioWorkletNode/MessagePort.
 */
declare interface WasmSpectrumNode extends AudioWorkletNode {
  port: WasmSpectrumPort;
}

declare interface WasmSpectrumPort extends MessagePort {
  postMessage(message: WasmSpectrumToProcessorMessage, transfer?: Transferable[]): void;
  onmessage: ((event: MessageEvent<WasmSpectrumFromProcessorMessage>) => void) | null;
  addEventListener<K extends 'message'>(
    type: K,
    listener: (this: MessagePort, ev: MessageEvent<WasmSpectrumFromProcessorMessage>) => any,
    options?: boolean | AddEventListenerOptions
  ): void;
}
