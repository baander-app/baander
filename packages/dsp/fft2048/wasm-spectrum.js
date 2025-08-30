class WasmSpectrumProcessor extends AudioWorkletProcessor {
  static get parameterDescriptors() { return []; }

  constructor() {
    super();

    // WASM globals
    this._exp = null;
    this._HEAPF32 = null;
    this._HEAPU8 = null;
    this._ready = false;

    // Heap pointers
    this._inPtr = 0;
    this._magPtr = 0;
    this._wavePtr = 0;
    this._magDownPtr = 0;
    this._waveDownPtr = 0;

    // JS-side working buffers
    this._monoAcc = new Float32Array(2048);
    this._monoIdx = 0;

    // Downsampled, recycled output buffers to keep postMessage cheap
    this._freqBins = 256;
    this._timeBins = 512;
    this._freqOut = new Uint8Array(this._freqBins);
    this._timeOut = new Uint8Array(this._timeBins);

    // Throttle post rate
    this._spectraCounter = 0;
    this._postEvery = 2;

    this.port.onmessage = async (e) => {
      const d = e.data || {};
      if (d.type !== 'wasm' || !d.bytes) return;
      try {
        const { instance } = await WebAssembly.instantiate(d.bytes, {});
        const exp = instance.exports;
        this._exp = exp;

        // Resolve export names (support both underscored and plain)
        const wasm_malloc =
          exp.wasm_malloc || exp._wasm_malloc || exp.malloc || exp._malloc;
        const init_fft =
          exp.init_fft || exp._init_fft;
        const process_spectrum =
          exp.process_spectrum || exp._process_spectrum;
        const downsample_mag_stride4 =
          exp.downsample_mag_stride4 || exp._downsample_mag_stride4;
        const downsample_wave_stride4 =
          exp.downsample_wave_stride4 || exp._downsample_wave_stride4;

        if (!wasm_malloc || !init_fft || !process_spectrum) {
          try { this.port.postMessage({ type: 'error', reason: 'missing-exports' }); } catch {}
          return;
        }

        // Bind resolved methods for faster calls
        this._fn = {
          malloc: wasm_malloc.bind(exp),
          init_fft: init_fft.bind(exp),
          process: process_spectrum.bind(exp),
          down_mag: (downsample_mag_stride4 || (() => {})).bind(exp),
          down_wave: (downsample_wave_stride4 || (() => {})).bind(exp),
        };

        // Heaps
        this._HEAPF32 = new Float32Array(exp.memory.buffer);
        this._HEAPU8  = new Uint8Array(exp.memory.buffer);

        // Allocate heap buffers
        this._inPtr       = this._fn.malloc(2048 * 4);
        this._magPtr      = this._fn.malloc(1024);
        this._wavePtr     = this._fn.malloc(2048);
        this._magDownPtr  = this._fn.malloc(256);
        this._waveDownPtr = this._fn.malloc(512);

        // Init (Hann window = 1)
        this._fn.init_fft(1);

        this._ready = true;
        try { this.port.postMessage({ type: 'ready' }); } catch {}
      } catch (err) {
        try { this.port.postMessage({ type: 'error', reason: 'instantiate-failed', message: String(err) }); } catch {}
        this._ready = false;
      }
    };
  }

  process(inputs, outputs) {
    // Pass-through audio
    const input = inputs[0];
    const output = outputs[0];
    if (input && output) {
      const ch = Math.min(input.length, output.length);
      for (let c = 0; c < ch; c++) {
        if (input[c] && output[c]) output[c].set(input[c]);
      }
    }

    // No input to analyze
    if (!input || !input[0]) return true;

    if (this._ready) {
      const L = input[0];
      const R = input[1] || L;
      const n = L.length | 0; // typically 128

      // Accumulate to 2048-sample mono buffer
      let idx = this._monoIdx;
      for (let i = 0; i < n && idx < 2048; i++, idx++) {
        const l = L[i] || 0;
        const r = R ? (R[i] || 0) : l;
        this._monoAcc[idx] = l + r;
      }
      this._monoIdx = idx;

      if (this._monoIdx >= 2048) {
        // Copy to WASM and compute spectrum
        this._HEAPF32.set(this._monoAcc, this._inPtr >> 2);
        this._fn.process(this._inPtr, this._magPtr, this._wavePtr);

        // Downsample in WASM if helpers exist, else do JS fallback
        if (this._fn.down_mag && this._fn.down_wave) {
          this._fn.down_mag(this._magPtr, this._magDownPtr);
          this._fn.down_wave(this._wavePtr, this._waveDownPtr);
          this._freqOut.set(new Uint8Array(this._HEAPU8.buffer, this._magDownPtr, 256));
          this._timeOut.set(new Uint8Array(this._HEAPU8.buffer, this._waveDownPtr, 512));
        } else {
          // JS fallback (stride 4)
          const mag = new Uint8Array(this._HEAPU8.buffer, this._magPtr, 1024);
          const wav = new Uint8Array(this._HEAPU8.buffer, this._wavePtr, 2048);
          for (let i = 0, j = 0; i < 256; i++, j += 4) this._freqOut[i] = mag[j];
          for (let i = 0, j = 0; i < 512; i++, j += 4) this._timeOut[i] = wav[j];
        }

        this._spectraCounter++;
        if ((this._spectraCounter % this._postEvery) === 0) {
          try {
            this.port.postMessage({
              type: 'spectrum',
              frequencyData: this._freqOut,
              timeDomainData: this._timeOut
            });
          } catch {}
        }

        // Reset accumulator
        this._monoIdx = 0;
      }
    }

    return true;
  }
}

registerProcessor('wasm-spectrum', WasmSpectrumProcessor);
