class MagicSoupProcessor extends AudioWorkletProcessor {
  static get parameterDescriptors() {
    return [];
  }

  constructor() {
    super();

    // DSP modules for loudness and dynamics analysis
    this.loudnessAPI = null;
    this.dynamicsAPI = null;
    this.loudnessReady = false;
    this.dynamicsReady = false;

    // WASM heap pointers - properly allocated per module
    this.loudnessBufferPtr = 0;
    this.dynamicsBufferPtr = 0;

    // Throttling for analysis - much more conservative
    this.frameCounter = 0;
    this.analysisFrameInterval = 16; // Reduced frequency
    this.playingCheckInterval = 32;

    // State tracking
    this.isPlaying = false;

    // Pre-allocated output message to avoid object creation
    this.outputMessage = {
      type: 'analysis',
      lufs: -60,
      leftChannel: 0,
      rightChannel: 0,
      rms: 0,
      isPlaying: false,
      truePeak: -60,
      crestL: 0,
      crestR: 0
    };

    // Handle initialization from main thread
    this.port.onmessage = (event) => {
      if (event.data?.type === 'init-dsp') {
        this.initDSPFromMessage(event.data);
      }
    };

    // Request DSP initialization
    this.port.postMessage({ type: 'request-dsp-init' });
  }

  initDSPFromMessage(data) {
    try {
      if (data.loudnessWasm && data.dynamicsWasm) {
        Promise.all([
          this.initLoudnessModule(data.loudnessWasm),
          this.initDynamicsModule(data.dynamicsWasm)
        ]).then(() => {
          console.log('DSP initialized in worklet');
          this.port.postMessage({ type: 'dsp-initialized' });
        })
      }
    } catch (error) {
      console.warn('Failed to initialize DSP from message:', error);
    }
  }

  async initLoudnessModule(wasmBytes) {
    try {
      const { instance } = await WebAssembly.instantiate(wasmBytes, {});
      const e = instance.exports;

      // Helper to resolve export names
      const pick = (...names) => {
        for (const n of names) {
          if (typeof e[n] === 'function' || typeof e[n] === 'object') return e[n];
        }
        return undefined;
      };

      const memory = pick('memory');
      const malloc = pick('malloc', '_malloc', 'wasm_malloc', '_wasm_malloc');
      const init = pick('init_loudness', '_init_loudness');
      const process = pick('process_frames', '_process_frames');
      const lufsM = pick('get_lufs_momentary', '_get_lufs_momentary');
      const truePk = pick('get_true_peak_dbfs', '_get_true_peak_dbfs');

      if (!memory || !init || !process || !lufsM) {
        throw new Error('Missing required loudness exports');
      }

      this.loudnessAPI = {
        memory,
        malloc: malloc || (() => 0),
        init,
        process,
        lufsM,
        truePkDbfs: truePk,
      };

      // Initialize with 48kHz and 2x oversampling for true peak
      this.loudnessAPI.init(48000, 2);

      // Allocate buffer in WASM memory if malloc is available
      if (malloc) {
        this.loudnessBufferPtr = this.loudnessAPI.malloc(128 * 2 * 4); // 128 frames * 2 channels * 4 bytes
      } else {
        // Use fixed offset if malloc not available
        this.loudnessBufferPtr = 8192; // 8KB offset
      }

      this.loudnessReady = true;
      console.log('Loudness module initialized in worklet');
    } catch (error) {
      console.warn('Failed to initialize loudness module:', error);
      this.loudnessReady = false;
    }
  }

  async initDynamicsModule(wasmBytes) {
    try {
      const { instance } = await WebAssembly.instantiate(wasmBytes, {});
      const e = instance.exports;

      // Helper to resolve export names
      const pick = (...names) => {
        for (const n of names) {
          if (typeof e[n] === 'function' || typeof e[n] === 'object') return e[n];
        }
        return undefined;
      };

      const memory = pick('memory');
      const malloc = pick('malloc', '_malloc', 'wasm_malloc', '_wasm_malloc');
      const init = pick('init_meters', '_init_meters');
      const process = pick('process_frames', '_process_frames');
      const rmsL = pick('get_rms_left', '_get_rms_left');
      const rmsR = pick('get_rms_right', '_get_rms_right');
      const crestL = pick('get_crest_left', '_get_crest_left');
      const crestR = pick('get_crest_right', '_get_crest_right');

      if (!memory || !init || !process || !rmsL || !rmsR) {
        throw new Error('Missing required dynamics exports');
      }

      this.dynamicsAPI = {
        memory,
        malloc: malloc || (() => 0),
        init,
        process,
        rmsL,
        rmsR,
        crestL: crestL || (() => 0),
        crestR: crestR || (() => 0),
      };

      // Initialize dynamics with 10ms attack, 100ms release at 48kHz
      this.dynamicsAPI.init(10, 100, 48000);

      // Allocate buffer in WASM memory
      if (malloc) {
        this.dynamicsBufferPtr = this.dynamicsAPI.malloc(128 * 2 * 4); // 128 frames * 2 channels * 4 bytes
      } else {
        // Use different fixed offset from loudness to avoid conflicts
        this.dynamicsBufferPtr = 16384; // 16KB offset
      }

      this.dynamicsReady = true;
      console.log('Dynamics module initialized in worklet');
    } catch (error) {
      console.warn('Failed to initialize dynamics module:', error);
      this.dynamicsReady = false;
    }
  }

  process(inputs, outputs) {
    const input = inputs[0];
    const output = outputs[0];

    if (!input || !output || input.length === 0) {
      return true;
    }

    // Pass-through audio
    const channelCount = Math.min(input.length, output.length);
    for (let channel = 0; channel < channelCount; channel++) {
      if (input[channel] && output[channel]) {
        output[channel].set(input[channel]);
      }
    }

    this.frameCounter++;

    // Check playing state less frequently
    if (this.frameCounter % this.playingCheckInterval === 0) {
      this.detectPlayingState(input);
    }

    // Skip analysis if not playing or DSP not ready
    if (!this.isPlaying || (!this.loudnessReady && !this.dynamicsReady)) {
      return true;
    }

    // Perform analysis with heavy throttling
    if (this.frameCounter % this.analysisFrameInterval === 0) {
      this.performWASMAnalysis(input);
    }

    return true;
  }

  detectPlayingState(inputChannels) {
    if (!inputChannels || inputChannels.length === 0) {
      this.isPlaying = false;
      return;
    }

    const leftChannel = inputChannels[0];
    if (!leftChannel) {
      this.isPlaying = false;
      return;
    }

    // Quick amplitude check with heavy decimation
    let maxAmplitudeSq = 0;
    const samplesToCheck = Math.min(8, leftChannel.length); // Reduced samples

    for (let i = 0; i < samplesToCheck; i += 4) {
      const sample = leftChannel[i];
      const amplitudeSq = sample * sample;
      if (amplitudeSq > maxAmplitudeSq) {
        maxAmplitudeSq = amplitudeSq;
      }
    }

    this.isPlaying = maxAmplitudeSq > 1e-6;
  }

  performWASMAnalysis(inputChannels) {
    if (!inputChannels || inputChannels.length === 0) {
      return;
    }

    const leftChannel = inputChannels[0];
    const rightChannel = inputChannels[1] || leftChannel;

    if (!leftChannel) {
      return;
    }

    const frameCount = leftChannel.length;

    try {
      // Process with loudness analyzer
      if (this.loudnessReady && this.loudnessBufferPtr > 0) {
        const HEAPF32 = new Float32Array(this.loudnessAPI.memory.buffer);
        const heapOffset = this.loudnessBufferPtr / 4;

        // Bounds check - ensure we don't exceed memory
        const maxFrames = Math.min(frameCount, 128);
        if (heapOffset + maxFrames * 2 < HEAPF32.length) {
          // Copy interleaved audio to WASM memory
          for (let i = 0; i < maxFrames; i++) {
            HEAPF32[heapOffset + i * 2] = leftChannel[i] || 0;
            HEAPF32[heapOffset + i * 2 + 1] = rightChannel[i] || 0;
          }

          this.loudnessAPI.process(this.loudnessBufferPtr, maxFrames, 2);
          this.outputMessage.lufs = this.loudnessAPI.lufsM();
          if (this.loudnessAPI.truePkDbfs) {
            this.outputMessage.truePeak = this.loudnessAPI.truePkDbfs();
          }
        }
      }

      // Process with dynamics meter
      if (this.dynamicsReady && this.dynamicsBufferPtr > 0) {
        const HEAPF32 = new Float32Array(this.dynamicsAPI.memory.buffer);
        const heapOffset = this.dynamicsBufferPtr / 4;

        // Bounds check
        const maxFrames = Math.min(frameCount, 128);
        if (heapOffset + maxFrames * 2 < HEAPF32.length) {
          // Copy interleaved audio to WASM memory
          for (let i = 0; i < maxFrames; i++) {
            HEAPF32[heapOffset + i * 2] = leftChannel[i] || 0;
            HEAPF32[heapOffset + i * 2 + 1] = rightChannel[i] || 0;
          }

          this.dynamicsAPI.process(this.dynamicsBufferPtr, maxFrames, 2);

          const rmsL = this.dynamicsAPI.rmsL();
          const rmsR = this.dynamicsAPI.rmsR();

          this.outputMessage.leftChannel = Math.min(100, rmsL * 100);
          this.outputMessage.rightChannel = Math.min(100, rmsR * 100);
          this.outputMessage.rms = Math.sqrt((rmsL * rmsL + rmsR * rmsR) * 0.5);

          if (this.dynamicsAPI.crestL) {
            this.outputMessage.crestL = this.dynamicsAPI.crestL();
          }
          if (this.dynamicsAPI.crestR) {
            this.outputMessage.crestR = this.dynamicsAPI.crestR();
          }
        }
      }

      this.outputMessage.isPlaying = this.isPlaying;
      this.port.postMessage(this.outputMessage);
    } catch (error) {
      console.warn('WASM analysis error:', error);
      // Fallback to basic analysis
      this.performFallbackAnalysis(inputChannels);
    }
  }

  performFallbackAnalysis(inputChannels) {
    const leftChannel = inputChannels[0];
    const rightChannel = inputChannels[1] || leftChannel;
    const frameCount = leftChannel.length;

    // Basic RMS calculation with heavy decimation
    let leftSumSq = 0;
    let rightSumSq = 0;

    for (let i = 0; i < frameCount; i += 4) {
      const l = leftChannel[i] || 0;
      const r = rightChannel[i] || 0;
      leftSumSq += l * l;
      rightSumSq += r * r;
    }

    const sampleCount = Math.floor(frameCount / 4);
    if (sampleCount > 0) {
      const leftRms = Math.sqrt(leftSumSq / sampleCount);
      const rightRms = Math.sqrt(rightSumSq / sampleCount);
      const totalRms = Math.sqrt((leftSumSq + rightSumSq) / (sampleCount * 2));

      // Estimate LUFS
      const lufsInstant = totalRms < 1e-6 ? -60 : -0.691 + 10 * Math.log10(totalRms * totalRms + 1e-10);

      this.outputMessage.lufs = lufsInstant;
      this.outputMessage.leftChannel = Math.min(100, leftRms * 100);
      this.outputMessage.rightChannel = Math.min(100, rightRms * 100);
      this.outputMessage.rms = totalRms;
    } else {
      // No samples to process
      this.outputMessage.lufs = -60;
      this.outputMessage.leftChannel = 0;
      this.outputMessage.rightChannel = 0;
      this.outputMessage.rms = 0;
    }

    this.outputMessage.truePeak = -60;
    this.outputMessage.crestL = 0;
    this.outputMessage.crestR = 0;
    this.outputMessage.isPlaying = this.isPlaying;

    this.port.postMessage(this.outputMessage);
  }
}

registerProcessor('magic-soup-processor', MagicSoupProcessor);
