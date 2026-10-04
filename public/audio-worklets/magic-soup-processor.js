class MagicSoupProcessor extends AudioWorkletProcessor {
  static get parameterDescriptors() { return []; }

  constructor() {
    super();
    this.loudnessAPI = null;
    this.dynamicsAPI = null;
    this.loudnessReady = false;
    this.dynamicsReady = false;
    this.loudnessBufferPtr = 0;
    this.dynamicsBufferPtr = 0;
    this.initialization = null;
    this.bufferFrames = 128;
    this.frameCounter = 0;
    this.analysisFrameInterval = 16;
    this.isPlaying = false;
    this.intervalTruePeak = -Infinity;
    this.outputMessage = {
      type: 'analysis', lufs: -60, leftChannel: 0, rightChannel: 0,
      rms: 0, isPlaying: false, truePeak: -60, crestL: 0, crestR: 0,
    };
    this.port.onmessage = event => {
      if (event.data?.type === 'init-dsp') void this.initDSPFromMessage(event.data);
    };
    this.port.postMessage({ type: 'request-dsp-init' });
  }

  initDSPFromMessage(data) {
    if (!data.loudnessWasm || !data.dynamicsWasm) return Promise.resolve();
    if (!this.initialization) {
      this.initialization = Promise.all([
        this.initLoudnessModule(data.loudnessWasm),
        this.initDynamicsModule(data.dynamicsWasm),
      ]);
    }
    return this.initialization;
  }

  async createMeter(wasmBytes, kind) {
    const { instance } = await WebAssembly.instantiate(wasmBytes, {});
    const e = instance.exports;
    e._initialize?.();
    const pick = (...names) => names.map(name => e[name]).find(value => value !== undefined);
    const api = {
      memory: e.memory,
      malloc: pick('malloc', '_malloc', 'wasm_malloc', '_wasm_malloc'),
      free: pick('free', '_free', 'wasm_free', '_wasm_free'),
      process: pick('process_frames', '_process_frames'),
    };
    const required = ['malloc', 'free', 'process'];
    if (kind === 'loudness') {
      Object.assign(api, {
        init: pick('init_loudness', '_init_loudness'),
        lufsM: pick('get_lufs_momentary', '_get_lufs_momentary'),
        truePkDbfs: pick('get_true_peak_dbfs', '_get_true_peak_dbfs'),
      });
      required.push('init', 'lufsM', 'truePkDbfs');
    } else {
      Object.assign(api, {
        init: pick('init_meters', '_init_meters'),
        rmsL: pick('get_rms_left', '_get_rms_left'),
        rmsR: pick('get_rms_right', '_get_rms_right'),
        crestL: pick('get_crest_left', '_get_crest_left'),
        crestR: pick('get_crest_right', '_get_crest_right'),
      });
      required.push('init', 'rmsL', 'rmsR', 'crestL', 'crestR');
    }
    if (!api.memory?.buffer || required.some(name => typeof api[name] !== 'function')) {
      throw new Error(`Missing required ${kind} exports`);
    }
    if (kind === 'loudness') api.init(sampleRate, 2);
    else api.init(10, 100, sampleRate);
    const bytes = this.bufferFrames * 2 * Float32Array.BYTES_PER_ELEMENT;
    const ptr = api.malloc(bytes);
    if (!Number.isInteger(ptr) || ptr <= 0 || ptr % 4 !== 0 || ptr + bytes > api.memory.buffer.byteLength) {
      if (ptr > 0) api.free(ptr);
      throw new Error(`Invalid ${kind} buffer allocation`);
    }
    try {
      api.heap = new Float32Array(api.memory.buffer, ptr, this.bufferFrames * 2);
      api.ptr = ptr;
      return api;
    } catch (error) {
      api.free(ptr);
      throw error;
    }
  }

  async initLoudnessModule(wasmBytes) {
    try {
      this.loudnessAPI = await this.createMeter(wasmBytes, 'loudness');
      this.loudnessBufferPtr = this.loudnessAPI.ptr;
      this.loudnessReady = true;
    } catch (error) { console.warn('Failed to initialize loudness module:', error); }
  }

  async initDynamicsModule(wasmBytes) {
    try {
      this.dynamicsAPI = await this.createMeter(wasmBytes, 'dynamics');
      this.dynamicsBufferPtr = this.dynamicsAPI.ptr;
      this.dynamicsReady = true;
    } catch (error) { console.warn('Failed to initialize dynamics module:', error); }
  }

  disableMeter(kind, error) {
    const api = this[`${kind}API`];
    this[`${kind}Ready`] = false;
    this[`${kind}BufferPtr`] = 0;
    this[`${kind}API`] = null;
    if (api?.ptr) {
      try { api.free(api.ptr); }
      catch (cleanupError) { console.warn(`${kind} buffer cleanup failed:`, cleanupError); }
    }
    console.warn(`${kind} analysis disabled:`, error);
  }

  process(inputs, outputs) {
    const input = inputs[0];
    const output = outputs[0];
    if (!input?.[0] || !output || input[0].length === 0) return true;
    for (let channel = 0; channel < output.length; channel++) {
      if (input[channel]) output[channel].set(input[channel]);
      else output[channel].fill(0);
    }
    this.detectPlayingState(input);
    // Native meter time and envelopes must advance for every frame, including silence.
    this.performWASMAnalysis(input);
    if (++this.frameCounter % this.analysisFrameInterval === 0) {
      this.performFallbackAnalysis(input);
      if (this.loudnessReady) {
        try {
          this.outputMessage.lufs = this.loudnessAPI.lufsM();
          this.outputMessage.truePeak = this.intervalTruePeak;
        } catch (error) { this.disableMeter('loudness', error); }
      }
      if (this.dynamicsReady) {
        try {
          const l = this.dynamicsAPI.rmsL(), r = this.dynamicsAPI.rmsR();
          const crestL = this.dynamicsAPI.crestL(), crestR = this.dynamicsAPI.crestR();
          this.outputMessage.leftChannel = Math.min(100, l * 100);
          this.outputMessage.rightChannel = Math.min(100, r * 100);
          this.outputMessage.rms = Math.sqrt((l * l + r * r) * 0.5);
          this.outputMessage.crestL = crestL;
          this.outputMessage.crestR = crestR;
        } catch (error) { this.disableMeter('dynamics', error); }
      }
      this.outputMessage.isPlaying = this.isPlaying;
      this.port.postMessage(this.outputMessage);
      this.intervalTruePeak = -Infinity;
    }
    return true;
  }

  detectPlayingState(inputChannels) {
    this.isPlaying = false;
    for (const channel of inputChannels) {
      for (let i = 0; i < channel.length; i++) {
        if (Math.abs(channel[i]) > 0.001) { this.isPlaying = true; return; }
      }
    }
  }

  feedMeter(api, left, right) {
    for (let offset = 0; offset < left.length; offset += this.bufferFrames) {
      const count = Math.min(this.bufferFrames, left.length - offset);
      if (api.heap.buffer !== api.memory.buffer) {
        api.heap = new Float32Array(api.memory.buffer, api.ptr, this.bufferFrames * 2);
      }
      for (let i = 0; i < count; i++) {
        api.heap[i * 2] = left[offset + i];
        api.heap[i * 2 + 1] = right[offset + i] ?? 0;
      }
      api.process(api.ptr, count, 2);
      if (api.truePkDbfs) this.intervalTruePeak = Math.max(this.intervalTruePeak, api.truePkDbfs());
    }
  }

  performWASMAnalysis(inputChannels) {
    const left = inputChannels[0], right = inputChannels[1] || left;
    if (this.loudnessReady) {
      try { this.feedMeter(this.loudnessAPI, left, right); }
      catch (error) { this.disableMeter('loudness', error); }
    }
    if (this.dynamicsReady) {
      try { this.feedMeter(this.dynamicsAPI, left, right); }
      catch (error) { this.disableMeter('dynamics', error); }
    }
  }

  performFallbackAnalysis(inputChannels) {
    const left = inputChannels[0], right = inputChannels[1] || left;
    let l = 0, r = 0, peak = 0;
    for (let i = 0; i < left.length; i++) {
      l += left[i] * left[i];
      r += (right[i] ?? 0) * (right[i] ?? 0);
      peak = Math.max(peak, Math.abs(left[i]), Math.abs(right[i] ?? 0));
    }
    const leftRms = Math.sqrt(l / left.length), rightRms = Math.sqrt(r / left.length);
    const rms = Math.sqrt((l + r) / (2 * left.length));
    this.outputMessage.lufs = rms < 1e-6 ? -60 : -0.691 + 20 * Math.log10(rms);
    this.outputMessage.truePeak = peak > 0 ? 20 * Math.log10(peak) : -60;
    this.outputMessage.leftChannel = Math.min(100, leftRms * 100);
    this.outputMessage.rightChannel = Math.min(100, rightRms * 100);
    this.outputMessage.rms = rms;
    this.outputMessage.crestL = 0;
    this.outputMessage.crestR = 0;
  }
}

registerProcessor('magic-soup-processor', MagicSoupProcessor);
