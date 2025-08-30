class WasmSpectrumProcessor extends AudioWorkletProcessor {
  static get parameterDescriptors() { return []; }

  constructor() {
    super();

    // Minimal WASM state
    this._exp = null;
    this._HEAPF32 = null;
    this._HEAPU8 = null;
    this._ready = false;

    // Reduced buffer sizes
    this._monoAcc = new Float32Array(1024); // Smaller buffer
    this._monoIdx = 0;

    // Smaller output buffers
    this._freqBins = 128;  // Reduced from 256
    this._timeBins = 256;  // Reduced from 512
    this._freqOut = new Uint8Array(this._freqBins);
    this._timeOut = new Uint8Array(this._timeBins);

    // Less frequent posting
    this._spectraCounter = 0;
    this._postEvery = 8; // Increased from 2

    this.port.onmessage = async (e) => {
      const d = e.data || {};
      if (d.type !== 'wasm' || !d.bytes) return;

      try {
        const { instance } = await WebAssembly.instantiate(d.bytes, {});
        const exp = instance.exports;

        const wasm_malloc = exp.wasm_malloc || exp._wasm_malloc || exp.malloc || exp._malloc;
        const init_fft = exp.init_fft || exp._init_fft;
        const process_spectrum = exp.process_spectrum || exp._process_spectrum;

        if (!wasm_malloc || !init_fft || !process_spectrum) {
          this.port.postMessage({ type: 'error', reason: 'missing-exports' });
          return;
        }

        this._fn = {
          malloc: wasm_malloc.bind(exp),
          init_fft: init_fft.bind(exp),
          process: process_spectrum.bind(exp),
        };

        this._HEAPF32 = new Float32Array(exp.memory.buffer);
        this._HEAPU8 = new Uint8Array(exp.memory.buffer);

        // Smaller allocations
        this._inPtr = this._fn.malloc(1024 * 4);
        this._magPtr = this._fn.malloc(512);
        this._wavePtr = this._fn.malloc(1024);

        this._fn.init_fft(1);
        this._ready = true;
        this.port.postMessage({ type: 'ready' });

      } catch (err) {
        this.port.postMessage({ type: 'error', reason: 'init-failed' });
        this._ready = false;
      }
    };
  }

  process(inputs, outputs) {
    // Pass-through audio
    const input = inputs[0];
    const output = outputs[0];
    if (input && output && input[0] && output[0]) {
      output[0].set(input[0]);
      if (input[1] && output[1]) output[1].set(input[1]);
    }

    // Skip if not ready or no input
    if (!this._ready || !input || !input[0]) return true;

    const L = input[0];
    const R = input[1] || L;
    const n = L.length;

    // Accumulate to smaller buffer (1024 instead of 2048)
    let idx = this._monoIdx;
    for (let i = 0; i < n && idx < 1024; i++, idx++) {
      this._monoAcc[idx] = (L[i] + (R[i] || 0)) * 0.5; // Average channels
    }
    this._monoIdx = idx;

    // Process when buffer is full
    if (this._monoIdx >= 1024) {
      this._HEAPF32.set(this._monoAcc, this._inPtr >> 2);
      this._fn.process(this._inPtr, this._magPtr, this._wavePtr);

      // Simple decimation instead of fancy downsampling
      const mag = new Uint8Array(this._HEAPU8.buffer, this._magPtr, 512);
      const wav = new Uint8Array(this._HEAPU8.buffer, this._wavePtr, 1024);

      for (let i = 0; i < 128; i++) this._freqOut[i] = mag[i * 4];
      for (let i = 0; i < 256; i++) this._timeOut[i] = wav[i * 4];

      // Post less frequently
      this._spectraCounter++;
      if ((this._spectraCounter % this._postEvery) === 0) {
        this.port.postMessage({
          type: 'spectrum',
          frequencyData: this._freqOut,
          timeDomainData: this._timeOut
        });
      }

      this._monoIdx = 0;
    }

    return true;
  }
}

registerProcessor('wasm-spectrum', WasmSpectrumProcessor);
