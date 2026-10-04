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
    this.phaseRing = new Float32Array(128);
    this.phaseWriteIndex = 0;
    this.intervalL2 = 0;
    this.intervalR2 = 0;
    this.intervalLR = 0;
    this.programmeGeneration = 0;
    this.outputMessage = {
      type: 'analysis', programmeGeneration: 0, lufs: -60, leftChannel: 0, rightChannel: 0,
      loudnessReady: false,
      rms: 0, isPlaying: false, truePeak: -60, crestL: 0, crestR: 0,
      phase: { samples: new Float32Array(128), correlation: null },
    };
    this.port.onmessage = event => {
      if (event.data?.type === 'init-dsp') void this.initDSPFromMessage(event.data);
      else if (event.data?.type === 'reset-programme') this.resetProgramme(event.data.programmeGeneration);
    };
    this.port.postMessage({ type: 'request-dsp-init' });
  }

  resetProgramme(generation) {
    if (!Number.isSafeInteger(generation) || generation <= this.programmeGeneration) return;
    this.programmeGeneration = generation;
    for (const kind of ['loudness', 'dynamics']) {
      if (this[`${kind}Ready`]) {
        try { this[`${kind}API`].reset(); }
        catch (error) { this.disableMeter(kind, error); }
      }
    }
    // Pending initialization creates fresh meters; no previous programme has fed them.
    this.intervalTruePeak = -Infinity;
    this.phaseRing.fill(0);
    this.phaseWriteIndex = 0;
    this.intervalL2 = 0;
    this.intervalR2 = 0;
    this.intervalLR = 0;
    this.outputMessage.phase.samples.fill(0);
    this.outputMessage.phase.correlation = null;
    this.frameCounter = 0;
    this.isPlaying = false;
    this.outputMessage.programmeGeneration = generation;
    this.outputMessage.lufs = -60;
    this.outputMessage.loudnessReady = false;
    this.outputMessage.truePeak = -60;
    this.outputMessage.leftChannel = 0;
    this.outputMessage.rightChannel = 0;
    this.outputMessage.rms = 0;
    this.outputMessage.crestL = 0;
    this.outputMessage.crestR = 0;
    this.outputMessage.isPlaying = false;
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
        reset: pick('reset_loudness', '_reset_loudness'),
        lufsM: pick('get_lufs_momentary', '_get_lufs_momentary'),
        truePkDbfs: pick('get_true_peak_dbfs', '_get_true_peak_dbfs'),
      });
      required.push('init', 'reset', 'lufsM', 'truePkDbfs');
    } else {
      Object.assign(api, {
        init: pick('init_meters', '_init_meters'),
        reset: pick('reset_meters', '_reset_meters'),
        rmsL: pick('get_rms_left', '_get_rms_left'),
        rmsR: pick('get_rms_right', '_get_rms_right'),
        crestL: pick('get_crest_left', '_get_crest_left'),
        crestR: pick('get_crest_right', '_get_crest_right'),
      });
      required.push('init', 'reset', 'rmsL', 'rmsR', 'crestL', 'crestR');
    }
    if (!api.memory?.buffer || required.some(name => typeof api[name] !== 'function')) {
      throw new Error(`Missing required ${kind} exports`);
    }
    if (kind === 'loudness') api.init(sampleRate, 4);
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
    this.accumulatePhase(input);
    // Native meter time and envelopes must advance for every frame, including silence.
    this.performWASMAnalysis(input);
    if (++this.frameCounter % this.analysisFrameInterval === 0) {
      this.performFallbackAnalysis(input);
      this.outputMessage.loudnessReady = false;
      if (this.loudnessReady) {
        try {
          this.outputMessage.lufs = this.loudnessAPI.lufsM();
          this.outputMessage.truePeak = this.intervalTruePeak;
          this.outputMessage.loudnessReady = Number.isFinite(this.outputMessage.lufs)
            && this.outputMessage.lufs > -60 && this.outputMessage.rms > 1e-6;
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
      this.publishPhase();
      this.port.postMessage(this.outputMessage);
      this.intervalTruePeak = -Infinity;
      this.intervalL2 = 0;
      this.intervalR2 = 0;
      this.intervalLR = 0;
    }
    return true;
  }

  accumulatePhase(inputChannels) {
    const left = inputChannels[0], right = inputChannels[1] || left;
    for (let i = 0; i < left.length; i++) {
      const l = left[i], r = right[i] ?? 0;
      this.phaseRing[this.phaseWriteIndex] = l;
      this.phaseRing[this.phaseWriteIndex + 1] = r;
      this.phaseWriteIndex = (this.phaseWriteIndex + 2) % this.phaseRing.length;
      this.intervalL2 += l * l;
      this.intervalR2 += r * r;
      this.intervalLR += l * r;
    }
  }

  publishPhase() {
    const phase = this.outputMessage.phase;
    // The next write position is the oldest pair; startup slots remain zero.
    for (let i = 0; i < this.phaseRing.length; i++) {
      phase.samples[i] = this.phaseRing[(this.phaseWriteIndex + i) % this.phaseRing.length];
    }
    phase.correlation = this.intervalL2 > 0 && this.intervalR2 > 0
      ? Math.max(-1, Math.min(1, this.intervalLR / Math.sqrt(this.intervalL2 * this.intervalR2)))
      : null;
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
