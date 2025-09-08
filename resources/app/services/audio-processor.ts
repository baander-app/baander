/* eslint-disable @typescript-eslint/no-explicit-any */

import { getDynamics, getLoudness, getSpectralFeatures } from '@/modules/dsp/dsp-repository.ts';

interface WorkletAnalysisData {
  type: 'analysis';
  lufs: number;
  leftChannel: number;
  rightChannel: number;
  rms: number;
  isPlaying?: boolean;
  truePeak?: number;
  crestL?: number;
  crestR?: number;
}

interface WorkerAnalysisData {
  frequencyData: Uint8Array | null;
  timeDomainData: Uint8Array | null;
  peakFrequency: number;
  spectralCentroid?: number;
  spectralRolloff?: number;
  spectralFlux?: number;
  spectralFlatness?: number;
}

export class AudioProcessor {
  private audioContext: AudioContext;

  private sourceNode: MediaElementAudioSourceNode | null = null;
  private analyzerNode!: AnalyserNode;
  private gainNode!: GainNode;
  private masterGainNode!: GainNode;
  private compressorNode!: DynamicsCompressorNode;
  private filters: BiquadFilterNode[] = [];
  private spatialNode: ConvolverNode | null = null;

  // High-performance WASM spectrum analyzer
  private wasmSpectrumNode: WasmSpectrumNode | null = null;
  private wasmSpectrumReady = false;

  // WASM DSP modules
  private loudnessAPI: LoudnessR128API | null = null;
  private dynamicsAPI: DynamicsMeterAPI | null = null;
  private spectralAPI: SpectralFeaturesApi | null = null;
  private dspReady = false;

  // AudioWorklet for real-time analysis
  private audioWorkletNode: AudioWorkletNode | null = null;

  // Web Worker for background analysis
  private analysisWorker: Worker | null = null;
  private workerReady = false;
  private lastWorkerAnalysisTime = 0;

  // Data buffers
  private readonly FFT_SIZE = 2048;
  private readonly TIME_SIZE = 2048;

  private sharedFrequencyBuffer: SharedArrayBuffer | null = null;
  private sharedTimeDomainBuffer: SharedArrayBuffer | null = null;

  private frequencyData!: Uint8Array;
  private timeDomainData!: Uint8Array;
  private tempFrequencyData!: Uint8Array;
  private tempTimeDomainData!: Uint8Array;

  // Analysis results
  private peakFrequency = 0;
  private spectralCentroid = 0;
  private spectralRolloff = 0;
  private spectralFlux = 0;
  private spectralFlatness = 0;
  private lufsBuffer: number[] = [];
  private readonly LUFS_WINDOW_SIZE = 400;
  private readonly SMOOTHING_TIME = 0.1;

  // State
  private isConnected = false;
  private passiveMode = false;
  private audioElement: HTMLAudioElement | null = null;
  private contextResumed = false;
  private isPlaying = false;

  private readonly frequencies = [31.5, 63, 125, 250, 500, 1000, 2000, 4000, 8000, 16000];

  private analysisInterval: number | null = null;
  private readonly ANALYSIS_INTERVAL = 40; // 25fps

  constructor() {
    this.audioContext = new AudioContext();
    this.initializeSharedBuffers();
    this.initializeNodes();
    this.setupAudioGraph();

    this.tempFrequencyData = new Uint8Array(this.FFT_SIZE / 2);
    this.tempTimeDomainData = new Uint8Array(this.FFT_SIZE);

    this.initializeDSP();
    this.initializeWorker();
  }

  private async initializeDSP() {
    try {
      // Initialize WASM DSP modules
      [this.loudnessAPI, this.dynamicsAPI, this.spectralAPI] = await Promise.all([
        getLoudness(),
        getDynamics(),
        getSpectralFeatures()
      ]);

      // Initialize modules
      this.loudnessAPI.init(this.audioContext.sampleRate, 2); // 2x oversampling
      this.dynamicsAPI.init(10, 100, this.audioContext.sampleRate); // 10ms attack, 100ms release
      this.spectralAPI.init(this.FFT_SIZE, this.audioContext.sampleRate);

      this.dspReady = true;
      console.log('DSP modules initialized successfully');
    } catch (error) {
      console.warn('Failed to initialize DSP modules:', error);
      this.dspReady = false;
    }
  }

  private async initializeWasmSpectrum() {
    try {
      if (this.audioContext.state !== 'running') {
        await this.audioContext.resume();
      }

      // Load the WASM spectrum processor from packages/dsp/fft2048/
      await this.audioContext.audioWorklet.addModule('/dsp/wasm-spectrum.js');

      this.wasmSpectrumNode = new AudioWorkletNode(
        this.audioContext,
        'wasm-spectrum',
        {
          numberOfInputs: 1,
          numberOfOutputs: 1,
          channelCount: 2,
          channelCountMode: 'explicit',
          channelInterpretation: 'speakers',
        }
      ) as WasmSpectrumNode;

      // Load and send the WASM binary
      const wasmBytes = await fetch('/dsp/fft2048.wasm').then(r => r.arrayBuffer());
      this.wasmSpectrumNode.port.postMessage({ type: 'wasm', bytes: wasmBytes });

      // Handle messages from the processor
      this.wasmSpectrumNode.port.onmessage = (event) => {
        const msg = event.data as WasmSpectrumFromProcessorMessage;

        if (msg.type === 'ready') {
          this.wasmSpectrumReady = true;
          console.log('WASM spectrum processor ready');
        } else if (msg.type === 'error') {
          console.error('WASM spectrum processor error:', msg);
          this.wasmSpectrumReady = false;
        } else if (msg.type === 'spectrum') {
          // Update our frequency and time domain data
          if (msg.frequencyData && msg.timeDomainData) {
            const freqLen = Math.min(this.frequencyData.length, msg.frequencyData.length);
            const timeLen = Math.min(this.timeDomainData.length, msg.timeDomainData.length);

            for (let i = 0; i < freqLen; i++) {
              this.frequencyData[i] = msg.frequencyData[i];
            }
            for (let i = 0; i < timeLen; i++) {
              this.timeDomainData[i] = msg.timeDomainData[i];
            }

            // Compute spectral features if WASM DSP is ready
            if (this.spectralAPI && this.dspReady) {
              this.computeSpectralFeatures(msg.frequencyData);
            }
          }
        }
      };

    } catch (error) {
      console.warn('Failed to initialize WASM spectrum processor:', error);
      this.wasmSpectrumReady = false;
    }
  }

  private computeSpectralFeatures(frequencyData: Uint8Array) {
    if (!this.spectralAPI || !this.dspReady) return;

    try {
      // Allocate buffer and copy data
      const magPtr = this.spectralAPI.malloc(frequencyData.length);
      const HEAPU8 = new Uint8Array(this.spectralAPI.memory.buffer);
      HEAPU8.set(frequencyData, magPtr);

      // Compute features
      this.spectralAPI.computeFromMag(magPtr);

      // Get results
      this.spectralCentroid = this.spectralAPI.getCentroidHz();
      this.spectralRolloff = this.spectralAPI.getRolloffHz(0.85);
      this.spectralFlux = this.spectralAPI.getFlux();
      this.spectralFlatness = this.spectralAPI.getFlatness();

      // Find peak frequency
      const peakIndex = this.spectralAPI.getPeakIndex();
      this.peakFrequency = (peakIndex / (this.FFT_SIZE / 2)) * (this.audioContext.sampleRate / 2);

      // Cleanup
      this.spectralAPI.free(magPtr);
    } catch (error) {
      console.warn('Spectral features computation error:', error);
    }
  }

  private initializeSharedBuffers() {
    try {
      if (typeof SharedArrayBuffer !== 'undefined') {
        this.sharedFrequencyBuffer = new SharedArrayBuffer(this.FFT_SIZE / 2);
        this.sharedTimeDomainBuffer = new SharedArrayBuffer(this.TIME_SIZE);
        this.frequencyData = new Uint8Array(this.sharedFrequencyBuffer);
        this.timeDomainData = new Uint8Array(this.sharedTimeDomainBuffer);
        this.frequencyData.fill(20);
        this.timeDomainData.fill(128);
      } else {
        throw new Error('SharedArrayBuffer not available');
      }
    } catch {
      this.sharedFrequencyBuffer = null;
      this.sharedTimeDomainBuffer = null;
      this.frequencyData = new Uint8Array(this.FFT_SIZE / 2);
      this.timeDomainData = new Uint8Array(this.TIME_SIZE);
      this.frequencyData.fill(20);
      this.timeDomainData.fill(128);
    }
  }

  private initializeNodes() {
    this.analyzerNode = this.audioContext.createAnalyser();
    this.analyzerNode.fftSize = this.FFT_SIZE;
    this.analyzerNode.smoothingTimeConstant = 0.8;

    this.gainNode = this.audioContext.createGain();
    this.masterGainNode = this.audioContext.createGain();
    this.compressorNode = this.audioContext.createDynamicsCompressor();

    this.compressorNode.threshold.value = -24;
    this.compressorNode.knee.value = 30;
    this.compressorNode.ratio.value = 3;
    this.compressorNode.attack.value = 0.003;
    this.compressorNode.release.value = 0.25;

    this.initializeEQFilters();
  }

  private initializeEQFilters() {
    this.filters = [];
    this.frequencies.forEach((freq, index) => {
      const filter = this.audioContext.createBiquadFilter();

      if (index === 0) {
        filter.type = 'lowshelf';
      } else if (index === this.frequencies.length - 1) {
        filter.type = 'highshelf';
      } else {
        filter.type = 'peaking';
        filter.Q.value = 0.7;
      }
      filter.frequency.value = freq;
      filter.gain.value = 0;

      this.filters.push(filter);
    });
  }

  private setupAudioGraph() {
    let currentNode: AudioNode = this.analyzerNode;
    for (const filter of this.filters) {
      currentNode.connect(filter);
      currentNode = filter;
    }
    currentNode.connect(this.compressorNode);
    this.compressorNode.connect(this.masterGainNode);
    this.masterGainNode.connect(this.gainNode);
    this.gainNode.connect(this.audioContext.destination);
  }

  private initializeWorker() {
    try {
      this.analysisWorker = new Worker('/audio-worklets/audio-analysis-worker.js');

      this.analysisWorker.onmessage = (e: MessageEvent) => {
        const data = e.data as { type: string } & WorkerAnalysisData;
        if (data.type === 'analysis-result') {
          this.handleWorkerAnalysisResult(data);
        }
      };

      this.analysisWorker.onerror = () => {
        this.workerReady = false;
        this.analysisWorker = null;
        this.setupFallbackAnalysis();
      };

      if (this.sharedFrequencyBuffer && this.sharedTimeDomainBuffer) {
        this.analysisWorker.postMessage({
          type: 'init-shared-buffers',
          frequencyBuffer: this.sharedFrequencyBuffer,
          timeDomainBuffer: this.sharedTimeDomainBuffer,
        });
      } else {
        this.analysisWorker.postMessage({
          type: 'init',
          length: { freq: this.FFT_SIZE / 2, time: this.TIME_SIZE },
        });
      }

      this.sendSpectralWasmToWorker();

      this.workerReady = true;
    } catch {
      this.workerReady = false;
      this.analysisWorker = null;
      this.setupFallbackAnalysis();
    }
  }

  private async sendSpectralWasmToWorker() {
    try {
      const spectralWasm = await fetch('/dsp/spectral_features.wasm').then(r => r.arrayBuffer());

      if (this.analysisWorker) {
        this.analysisWorker.postMessage({
          type: 'init-spectral-wasm',
          spectralWasm
        });
      }
    } catch (error) {
      console.warn('Failed to send spectral WASM to worker:', error);
    }
  }

  private handleWorkerAnalysisResult(data: WorkerAnalysisData) {
    if (!this.sharedFrequencyBuffer) {
      if (data.frequencyData && data.frequencyData.length > 0) {
        const len = Math.min(this.frequencyData.length, data.frequencyData.length);
        for (let i = 0; i < len; i++) this.frequencyData[i] = data.frequencyData[i];
      }
      if (data.timeDomainData && data.timeDomainData.length > 0) {
        const len = Math.min(this.timeDomainData.length, data.timeDomainData.length);
        for (let i = 0; i < len; i++) this.timeDomainData[i] = data.timeDomainData[i];
      }
    }

    this.peakFrequency = data.peakFrequency || 0;
    this.spectralCentroid = data.spectralCentroid || 0;
    this.spectralRolloff = data.spectralRolloff || 0;
    this.spectralFlux = data.spectralFlux || 0;
    this.spectralFlatness = data.spectralFlatness || 0;
  }

  private setupOptimizedFallbackAnalysis() {
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval);
    }

    this.analysisInterval = window.setInterval(() => {
      this.performUnifiedAnalysis();
    }, this.ANALYSIS_INTERVAL);
  }

  private performUnifiedAnalysis() {
    if (!this.isPlaying) {
      this.frequencyData.fill(20);
      this.timeDomainData.fill(128);
      this.peakFrequency = 0;
      this.spectralCentroid = 0;
      this.spectralRolloff = 0;
      this.spectralFlux = 0;
      this.spectralFlatness = 0;
      return;
    }

    const now = performance.now();

    if (this.passiveMode) {
      if (
        this.workerReady &&
        this.analysisWorker &&
        now - this.lastWorkerAnalysisTime > 100
      ) {
        this.lastWorkerAnalysisTime = now;
        this.analysisWorker.postMessage({
          type: 'analyze',
          isPassiveMode: true,
          sampleRate: this.audioContext.sampleRate,
          useSharedBuffer: !!this.sharedFrequencyBuffer,
          isPlaying: this.isPlaying,
        });
      }
      return;
    }

    // Use WASM spectrum if available, otherwise fallback to native analyzer
    if (this.wasmSpectrumReady) {
      // WASM spectrum processor handles this automatically
      return;
    } else if (this.analyzerNode) {
      this.analyzerNode.getByteFrequencyData(this.tempFrequencyData);
      this.analyzerNode.getByteTimeDomainData(this.tempTimeDomainData);

      const freqLen = Math.min(this.frequencyData.length, this.tempFrequencyData.length);
      const timeLen = Math.min(this.timeDomainData.length, this.tempTimeDomainData.length);

      for (let i = 0; i < freqLen; i++) {
        this.frequencyData[i] = this.tempFrequencyData[i];
      }
      for (let i = 0; i < timeLen; i++) {
        this.timeDomainData[i] = this.tempTimeDomainData[i];
      }

      // Compute spectral features with WASM
      if (this.spectralAPI && this.dspReady) {
        this.computeSpectralFeatures(this.tempFrequencyData);
      }

      // Estimate LUFS
      let sum = 0;
      const step = 4;
      for (let i = 0; i < this.tempTimeDomainData.length; i += step) {
        const normalized = (this.tempTimeDomainData[i] - 128) / 128;
        sum += normalized * normalized;
      }
      const rms = Math.sqrt(sum / (this.tempTimeDomainData.length / step));
      const estimatedLufs = -0.691 + 10 * Math.log10(rms * rms + 1e-10);

      this.lufsBuffer.push(estimatedLufs);
      if (this.lufsBuffer.length > this.LUFS_WINDOW_SIZE) this.lufsBuffer.shift();
    }
  }

  private setupFallbackAnalysis() {
    this.setupOptimizedFallbackAnalysis();
  }

  // AudioWorklet setup for low-latency metrics (LUFS/meters)
  private async setupVolumeNormalization() {
    try {
      if (this.passiveMode) return;

      if (this.audioContext.state !== 'running') {
        await this.audioContext.resume();
      }

      if (!this.audioContext.audioWorklet) {
        throw new Error('AudioWorklet not supported');
      }

      if (!this.audioWorkletNode) {
        await this.audioContext.audioWorklet.addModule('/audio-worklets/magic-soup-processor.js');
        this.audioWorkletNode = new AudioWorkletNode(this.audioContext, 'magic-soup-processor', {
          numberOfInputs: 1,
          numberOfOutputs: 1,
          channelCount: 2,
          channelCountMode: 'explicit',
          channelInterpretation: 'speakers',
        });

        this.audioWorkletNode.port.onmessage = (event: MessageEvent) => {
          const msg = event.data as WorkletAnalysisData & { type: string };

          if (msg.type === 'request-dsp-init') {
            // Send WASM bytes to worklet
            this.sendDSPToWorklet();
          } else if (msg?.type === 'analysis') {
            // Smooth LUFS buffer (for UI usage)
            this.lufsBuffer.push(msg.lufs);
            if (this.lufsBuffer.length > this.LUFS_WINDOW_SIZE) this.lufsBuffer.shift();
          }
        };
      }

      if (this.sourceNode) {
        try {
          this.compressorNode.disconnect();
        } catch {}
        // Route: compressor -> worklet -> master
        this.compressorNode.connect(this.audioWorkletNode);
        this.audioWorkletNode.connect(this.masterGainNode);
      }
    } catch {
      this.setupFallbackAnalysis();
    }
  }

  private async sendDSPToWorklet() {
    try {
      // Fetch WASM binaries
      const [loudnessWasm, dynamicsWasm] = await Promise.all([
        fetch('/dsp/loudness_r128.wasm').then(r => r.arrayBuffer()),
        fetch('/dsp/dynamics_meter.wasm').then(r => r.arrayBuffer())
      ]);

      // Send to worklet
      if (this.audioWorkletNode) {
        this.audioWorkletNode.port.postMessage({
          type: 'init-dsp',
          loudnessWasm,
          dynamicsWasm
        });
      }
    } catch (error) {
      console.warn('Failed to send DSP to worklet:', error);
    }
  }

  private teardownWorklet() {
    if (this.audioWorkletNode) {
      try {
        this.audioWorkletNode.port.onmessage = null as any;
        this.audioWorkletNode.disconnect();
      } catch {}
      this.audioWorkletNode = null;
    }

    if (this.wasmSpectrumNode) {
      try {
        this.wasmSpectrumNode.port.onmessage = null;
        this.wasmSpectrumNode.disconnect();
      } catch {}
      this.wasmSpectrumNode = null;
      this.wasmSpectrumReady = false;
    }

    try {
      this.compressorNode.disconnect();
    } catch {}
    this.compressorNode.connect(this.masterGainNode);
  }

  // Public method to update playing state
  public setPlayingState(isPlaying: boolean) {
    if (this.isPlaying === isPlaying) return; // No change

    this.isPlaying = isPlaying;

    // Notify worker about playing state change
    if (this.analysisWorker && this.workerReady) {
      this.analysisWorker.postMessage({
        type: 'set-playing-state',
        isPlaying: isPlaying,
      });
    }

    // If stopping, clear analysis interval to save CPU
    if (!isPlaying) {
      if (this.analysisInterval) {
        clearInterval(this.analysisInterval);
        this.analysisInterval = null;
      }

      // Reset data to baseline
      this.frequencyData.fill(20);
      this.timeDomainData.fill(128);
      this.peakFrequency = 0;
      this.spectralCentroid = 0;
      this.spectralRolloff = 0;
      this.spectralFlux = 0;
      this.spectralFlatness = 0;
      this.lufsBuffer = [];
    } else {
      // If starting to play, restart analysis if we have a connection
      if (this.isConnected && !this.analysisInterval) {
        this.setupOptimizedFallbackAnalysis();
      }
    }
  }

  async resumeContextIfNeeded(): Promise<void> {
    if (this.audioContext.state === 'suspended' && !this.contextResumed) {
      try {
        await this.audioContext.resume();
        this.contextResumed = true;
      } catch (error) {
        console.warn('Failed to resume AudioContext:', error);
        throw error;
      }
    }
  }

  async connectAudioElement(audioElement: HTMLAudioElement) {
    try {
      // Guard against multiple connections
      if (this.analysisInterval) clearInterval(this.analysisInterval);

      if (this.isConnected && this.audioElement !== audioElement) {
        this.disconnect();
      }
      if (this.isConnected && this.audioElement === audioElement) {
        return;
      }

      this.audioElement = audioElement;
      this.sourceNode = this.audioContext.createMediaElementSource(audioElement);
      this.sourceNode.connect(this.analyzerNode);

      this.isConnected = true;
      this.passiveMode = false;

      try {
        await this.setupVolumeNormalization();
        await this.initializeWasmSpectrum();

        // Connect WASM spectrum processor if ready
        if (this.wasmSpectrumNode && this.wasmSpectrumReady) {
          this.sourceNode.connect(this.wasmSpectrumNode);
        }
      } catch {
        // Fall back quietly
      }

      // Only start analysis if playing
      if (this.isPlaying) {
        this.setupOptimizedFallbackAnalysis();
      }
    } catch (error) {
      console.error('Failed to connect audio element:', error);
      if (error instanceof DOMException && error.name === 'InvalidStateError') {
        await this.initializePassiveMode();
      } else {
        throw error;
      }
    }
  }

  async initializePassiveMode() {
    this.passiveMode = true;
    this.isConnected = true;

    // Guard against multiple intervals
    if (this.analysisInterval) clearInterval(this.analysisInterval);

    // Only start analysis if playing
    if (this.isPlaying) {
      this.setupOptimizedFallbackAnalysis();
    }
  }

  disconnect() {
    if (this.sourceNode) {
      try {
        this.sourceNode.disconnect();
      } catch {}
      this.sourceNode = null;
    }

    // Guard: always clear interval
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval);
      this.analysisInterval = null;
    }

    this.teardownWorklet();

    if (this.spatialNode) {
      try {
        this.spatialNode.disconnect();
      } catch {}
      this.spatialNode = null;
    }

    this.audioElement = null;
    this.isConnected = false;
    this.passiveMode = false;
    this.isPlaying = false;
  }

  destroy() {
    // Guard: always clear interval
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval);
      this.analysisInterval = null;
    }

    if (this.analysisWorker) {
      this.analysisWorker.terminate();
      this.analysisWorker = null;
    }
    this.workerReady = false;

    this.teardownWorklet();
    this.disconnect();

    if (this.audioContext.state !== 'closed') {
      this.audioContext.close();
    }
  }

  setEnabled() {
    if (this.passiveMode) return;
    const targetGain = 1;
    this.gainNode.gain.setTargetAtTime(targetGain, this.audioContext.currentTime, 0.1);
  }

  setVolume(volume: number) {
    if (this.passiveMode) return;
    const v = Math.max(0, Math.min(1, volume));
    this.gainNode.gain.setTargetAtTime(v, this.audioContext.currentTime, 0.05);
  }

  setMuted(muted: boolean) {
    if (this.passiveMode) return;
    const targetGain = muted ? 0 : 1;
    this.gainNode.gain.setTargetAtTime(targetGain, this.audioContext.currentTime, 0.05);
  }

  setMasterGain(gainDb: number) {
    if (this.passiveMode) return;

    const linearGain = Math.pow(10, gainDb / 20);
    this.masterGainNode.gain.setTargetAtTime(linearGain, this.audioContext.currentTime, this.SMOOTHING_TIME);
  }

  updateEQBands(gains: number[]) {
    if (this.passiveMode) return;
    gains.forEach((gain, index) => {
      if (index < this.filters.length) {
        this.filters[index].gain.setTargetAtTime(gain, this.audioContext.currentTime, this.SMOOTHING_TIME);
      }
    });
  }

  setCompression(enabled: boolean) {
    if (this.passiveMode) return;
    if (enabled) {
      this.compressorNode.threshold.value = -24;
      this.compressorNode.ratio.value = 3;
    } else {
      this.compressorNode.threshold.value = -50;
      this.compressorNode.ratio.value = 1;
    }
  }

  setSpatialEnhancement(enabled: boolean) {
    if (this.passiveMode) return;
    if (enabled && !this.spatialNode) {
      this.spatialNode = this.audioContext.createConvolver();
    } else if (!enabled && this.spatialNode) {
      try {
        this.spatialNode.disconnect();
      } catch {}
      this.spatialNode = null;
    }
  }

  applyVolumeNormalization(targetLufs: number, currentLufs: number): number {
    const gainDb = Math.max(-20, Math.min(20, targetLufs - currentLufs));
    if (!this.passiveMode) {
      const normGainLinear = Math.pow(10, gainDb / 20);
      const safeGain = Math.min(2.0, normGainLinear);
      this.gainNode.gain.setTargetAtTime(safeGain, this.audioContext.currentTime, this.SMOOTHING_TIME);
    }
    return gainDb;
  }

  getAnalysisData() {
    // Early exit if not playing - return static data
    if (!this.isPlaying) {
      return {
        frequencyData: this.frequencyData,
        timeDomainData: this.timeDomainData,
        leftChannel: 0,
        rightChannel: 0,
        lufs: -60,
        peakFrequency: 0,
        spectralCentroid: 0,
        spectralRolloff: 0,
        spectralFlux: 0,
        spectralFlatness: 0,
        rms: 0,
      };
    }

    if (this.passiveMode) {
      const now = performance.now();
      const leftLevel = Math.abs(Math.sin(now / 200)) * 60 + 20;
      const rightLevel = Math.abs(Math.cos(now / 200)) * 60 + 20;

      return {
        frequencyData: this.frequencyData,
        timeDomainData: this.timeDomainData,
        leftChannel: leftLevel,
        rightChannel: rightLevel,
        lufs:
          this.lufsBuffer.length > 0
          ? this.lufsBuffer.reduce((a, b) => a + b, 0) / this.lufsBuffer.length
          : -20,
        peakFrequency: this.peakFrequency,
        spectralCentroid: this.spectralCentroid,
        spectralRolloff: this.spectralRolloff,
        spectralFlux: this.spectralFlux,
        spectralFlatness: this.spectralFlatness,
        rms: 0.1,
      };
    }

    if (this.analyzerNode) {
      // Reuse temp arrays, then copy into recycled arrays
      this.analyzerNode.getByteFrequencyData(this.tempFrequencyData);
      this.analyzerNode.getByteTimeDomainData(this.tempTimeDomainData);

      for (let i = 0; i < this.tempFrequencyData.length && i < this.frequencyData.length; i++) {
        this.frequencyData[i] = this.tempFrequencyData[i];
      }
      for (let i = 0; i < this.tempTimeDomainData.length && i < this.timeDomainData.length; i++) {
        this.timeDomainData[i] = this.tempTimeDomainData[i];
      }

      // Compute simple meters from temp time-domain
      const bufferLength = this.analyzerNode.frequencyBinCount;
      let leftSum = 0,
        rightSum = 0;

      for (let i = 0; i < bufferLength; i += 4) {
        const value = this.tempTimeDomainData[i] / 128.0 - 1.0;
        if (i % 8 === 0) leftSum += value * value;
        else rightSum += value * value;
      }

      const leftLevel = Math.sqrt(leftSum / (bufferLength / 8)) * 100;
      const rightLevel = Math.sqrt(rightSum / (bufferLength / 8)) * 100;

      const lufs =
        this.lufsBuffer.length > 0
        ? this.lufsBuffer.reduce((a, b) => a + b, 0) / this.lufsBuffer.length
        : -30;

      return {
        frequencyData: this.frequencyData,
        timeDomainData: this.timeDomainData,
        leftChannel: leftLevel,
        rightChannel: rightLevel,
        lufs,
        peakFrequency: this.peakFrequency,
        spectralCentroid: this.spectralCentroid,
        spectralRolloff: this.spectralRolloff,
        spectralFlux: this.spectralFlux,
        spectralFlatness: this.spectralFlatness,
        rms: Math.sqrt(leftSum + rightSum) / (bufferLength / 4),
      };
    }

    // Fallback if analyzer is missing
    return {
      frequencyData: this.frequencyData,
      timeDomainData: this.timeDomainData,
      leftChannel: 0,
      rightChannel: 0,
      lufs: -30,
      peakFrequency: 0,
      spectralCentroid: 0,
      spectralRolloff: 0,
      spectralFlux: 0,
      spectralFlatness: 0,
      rms: 0,
    };
  }

  get isActive(): boolean {
    return this.isConnected;
  }

  // Expose passive flag for UI if needed
  get passive(): boolean {
    return this.passiveMode;
  }

  get playing(): boolean {
    return this.isPlaying;
  }
}
