class WasmSpectrumProcessor extends AudioWorkletProcessor {
  static get parameterDescriptors() { return []; }

  constructor() {
    super();
    this._ready = false;
    this._initializing = false;
    this._monoAcc = new Float32Array(2048);
    this._monoIdx = 0;
    this._freqOut = new Uint8Array(1024);
    this._timeOut = new Uint8Array(2048);
    this._spectraCounter = 0;
    this._postEvery = 2;

    this.port.onmessage = async (event) => {
      const data = event.data || {};
      if (data.type !== 'wasm' || !data.bytes || this._ready || this._initializing) return;
      this._initializing = true;
      const allocated = [];
      let free;
      try {
        const { instance } = await WebAssembly.instantiate(data.bytes, {});
        const exp = instance.exports;
        const malloc = exp.wasm_malloc || exp._wasm_malloc || exp.malloc || exp._malloc;
        free = exp.wasm_free || exp._wasm_free || exp.free || exp._free;
        const init = exp.init_fft || exp._init_fft;
        const process = exp.process_spectrum || exp._process_spectrum;
        if (!exp.memory || !malloc || !free || !init || !process) throw new Error('Missing FFT exports');
        exp._initialize?.();
        this._fn = { process };
        // Native ABI: float[2048], uint8_t[1024], uint8_t[2048].
        for (const size of [2048 * 4, 1024, 2048]) {
          const ptr = malloc(size);
          if (!ptr) throw new Error('FFT allocation failed');
          allocated.push(ptr);
        }
        [this._inPtr, this._magPtr, this._wavePtr] = allocated;
        this._memory = exp.memory;
        init(1);
        this._ready = true;
        this.port.postMessage({ type: 'ready' });
      } catch (error) {
        for (const ptr of allocated) free?.(ptr);
        this._ready = false;
        this.port.postMessage({ type: 'error', reason: 'init-failed' });
      } finally {
        this._initializing = false;
      }
    };
  }

  process(inputs, outputs) {
    const input = inputs[0];
    const output = outputs[0];
    if (input && output) {
      for (let channel = 0; channel < Math.min(input.length, output.length); channel++) {
        if (input[channel] && output[channel]) output[channel].set(input[channel]);
      }
    }
    if (!this._ready || !input || !input[0]) return true;

    const left = input[0];
    const right = input[1] || left;
    for (let i = 0; i < left.length; i++) {
      this._monoAcc[this._monoIdx++] = (left[i] + (right[i] || 0)) * 0.5;
      if (this._monoIdx !== 2048) continue;

      // Refresh views so a future memory-growth build preserves the contract.
      new Float32Array(this._memory.buffer, this._inPtr, 2048).set(this._monoAcc);
      this._fn.process(this._inPtr, this._magPtr, this._wavePtr);
      this._monoIdx = 0;
      if (++this._spectraCounter % this._postEvery !== 0) continue;

      this._freqOut.set(new Uint8Array(this._memory.buffer, this._magPtr, 1024));
      this._timeOut.set(new Uint8Array(this._memory.buffer, this._wavePtr, 2048));
      this.port.postMessage({
        type: 'spectrum',
        frequencyData: this._freqOut,
        timeDomainData: this._timeOut,
      });
    }
    return true;
  }
}

registerProcessor('wasm-spectrum', WasmSpectrumProcessor);
