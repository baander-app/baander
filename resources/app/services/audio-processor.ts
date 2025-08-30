interface WorkletAnalysisData {
  type: 'analysis';
  lufs: number;
  leftChannel: number;
  rightChannel: number;
  rms: number;
}

interface WorkerAnalysisData {
  frequencyData: Uint8Array;
  timeDomainData: Uint8Array;
  peakFrequency: number;
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
  private audioWorkletNode: AudioWorkletNode | null = null;
  private contextResumed = false;

  private analysisWorker: Worker | null = null;
  private workerReady = false;

  private lufsBuffer: number[] = [];
  private readonly LUFS_WINDOW_SIZE = 400;
  private readonly SMOOTHING_TIME = 0.1;

  private isConnected = false;
  private passiveMode = false;
  private audioElement: HTMLAudioElement | null = null;

  private masterGain = 0;

  private readonly frequencies = [31.5, 63, 125, 250, 500, 1000, 2000, 4000, 8000, 16000];

  // SharedArrayBuffer for high-performance data sharing
  private sharedFrequencyBuffer: SharedArrayBuffer | null = null;
  private sharedTimeDomainBuffer: SharedArrayBuffer | null = null;
  private frequencyData!: Uint8Array;
  private timeDomainData!: Uint8Array;
  private peakFrequency = 0;

  private lastWorkerAnalysisTime = 0;
  private workerAnalysisInterval = 100;

  private analysisInterval: number | null = null;
  private simulationTime = 0;

  // Add: centralized teardown for worklet node
  private teardownWorklet() {
    if (this.audioWorkletNode) {
      try {
        // Detach message handler and disconnect node
        this.audioWorkletNode.port.onmessage = null as any;
        this.audioWorkletNode.disconnect();
      } catch (e) {
        // no-op
      } finally {
        this.audioWorkletNode = null;
      }
    }

    // Ensure compressor routes directly to master when worklet is not present
    try {
      // Disconnect any previous routing and connect compressor -> masterGain
      this.compressorNode.disconnect();
      this.compressorNode.connect(this.masterGainNode);
    } catch (e) {
      // no-op
    }
  }

  constructor() {
    this.audioContext = new AudioContext();
    this.initializeSharedBuffers();
    this.initializeNodes();
    this.setupAudioGraph();
    this.initializeWorker();
  }

  private initializeSharedBuffers() {
    try {
      // Try to use SharedArrayBuffer for zero-copy data sharing
      if (typeof SharedArrayBuffer !== 'undefined') {
        this.sharedFrequencyBuffer = new SharedArrayBuffer(1024);
        this.sharedTimeDomainBuffer = new SharedArrayBuffer(2048);
        this.frequencyData = new Uint8Array(this.sharedFrequencyBuffer);
        this.timeDomainData = new Uint8Array(this.sharedTimeDomainBuffer);
        console.log('Using SharedArrayBuffer for optimized performance');
      } else {
        throw new Error('SharedArrayBuffer not available');
      }
    } catch (error) {
      // Fallback to regular ArrayBuffer
      console.warn('SharedArrayBuffer not available, using regular ArrayBuffer:', error);
      this.frequencyData = new Uint8Array(1024);
      this.timeDomainData = new Uint8Array(2048);
    }

    this.frequencyData.fill(20);
    this.timeDomainData.fill(128);
  }

  private initializeNodes() {
    this.analyzerNode = this.audioContext.createAnalyser();
    this.analyzerNode.fftSize = 1024;
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
    this.frequencies.forEach((freq, index) => {
      const filter = this.audioContext.createBiquadFilter();

      if (index === 0) {
        filter.type = 'lowshelf';
        filter.frequency.value = freq;
      } else if (index === this.frequencies.length - 1) {
        filter.type = 'highshelf';
        filter.frequency.value = freq;
      } else {
        filter.type = 'peaking';
        filter.frequency.value = freq;
        filter.Q.value = 0.7;
      }

      filter.gain.value = 0;
      this.filters.push(filter);
    });
  }

  private setupAudioGraph() {
    let currentNode: AudioNode = this.analyzerNode;

    this.filters.forEach(filter => {
      currentNode.connect(filter);
      currentNode = filter;
    });

    currentNode.connect(this.compressorNode);
    this.compressorNode.connect(this.masterGainNode);
    this.masterGainNode.connect(this.gainNode);
    this.gainNode.connect(this.audioContext.destination);
  }

  private initializeWorker() {
    try {
      this.analysisWorker = new Worker('/audio-worklets/audio-analysis-worker.js');

      this.analysisWorker.onmessage = (e) => {
        if (e.data.type === 'analysis-result') {
          this.handleWorkerAnalysisResult(e.data);
        }
      };

      this.analysisWorker.onerror = (error) => {
        console.warn('Analysis worker error:', error);
        // Ensure readiness flag matches actual state
        this.workerReady = false;
        this.analysisWorker = null;
        this.setupFallbackAnalysis();
      };

      // Send shared buffers to worker if available
      if (this.sharedFrequencyBuffer && this.sharedTimeDomainBuffer) {
        this.analysisWorker.postMessage({
          type: 'init-shared-buffers',
          frequencyBuffer: this.sharedFrequencyBuffer,
          timeDomainBuffer: this.sharedTimeDomainBuffer
        });
      }

      this.workerReady = true;
      console.log('Audio analysis worker initialized');
    } catch (error) {
      console.warn('Failed to initialize analysis worker:', error);
      this.workerReady = false;
      this.setupFallbackAnalysis();
    }
  }

  private handleWorkerAnalysisResult(data: WorkerAnalysisData) {
    // With SharedArrayBuffer, data is already updated in shared memory
    if (!this.sharedFrequencyBuffer) {
      // Fallback: copy data for regular ArrayBuffer
      if (data.frequencyData && data.frequencyData.length > 0) {
        // Create new Uint8Array to avoid the type error
        this.frequencyData = new Uint8Array(data.frequencyData);
      }
      if (data.timeDomainData && data.timeDomainData.length > 0) {
        this.timeDomainData = new Uint8Array(data.timeDomainData);
      }
    }
    this.peakFrequency = data.peakFrequency || 0;
  }

  async initializePassiveMode() {
    console.log('Initializing passive mode - using optimized simulation');
    this.passiveMode = true;
    this.isConnected = true;

    this.setupOptimizedFallbackAnalysis();

    try {
      await this.setupVolumeNormalization();
    } catch (error) {
      console.warn('Could not initialize worklet in passive mode:', error);
    }
  }

  private setupOptimizedFallbackAnalysis() {
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval);
    }

    this.analysisInterval = window.setInterval(() => {
      this.performOptimizedFallbackAnalysis();
    }, 50);
  }

  private performOptimizedFallbackAnalysis() {
    const now = performance.now();

    if (this.workerReady && this.analysisWorker &&
      (now - this.lastWorkerAnalysisTime) > this.workerAnalysisInterval) {

      this.lastWorkerAnalysisTime = now;

      // With SharedArrayBuffer, we don't need to transfer data
      this.analysisWorker.postMessage({
        type: 'analyze',
        isPassiveMode: this.passiveMode,
        sampleRate: this.audioContext.sampleRate,
        useSharedBuffer: !!this.sharedFrequencyBuffer
      });

      return;
    }

    this.simulationTime += 50;

    if (this.passiveMode) {
      const baseLufs = -15;
      const variation = Math.sin(this.simulationTime / 2000) * 3;
      const estimatedLufs = baseLufs + variation;

      this.lufsBuffer.push(estimatedLufs);
      if (this.lufsBuffer.length > this.LUFS_WINDOW_SIZE) {
        this.lufsBuffer.shift();
      }
      return;
    }

    if (this.analyzerNode) {
      // Always use non-shared arrays for analyzer node methods
      const tempFrequencyData = new Uint8Array(1024);
      const tempTimeDomainData = new Uint8Array(2048);

      this.analyzerNode.getByteFrequencyData(tempFrequencyData);
      this.analyzerNode.getByteTimeDomainData(tempTimeDomainData);

      // Copy data to shared arrays if needed
      if (this.sharedFrequencyBuffer) {
        for (let i = 0; i < tempFrequencyData.length; i++) {
          this.frequencyData[i] = tempFrequencyData[i];
        }
      } else {
        // If not using shared buffers, just replace the arrays
        this.frequencyData = tempFrequencyData;
      }

      if (this.sharedTimeDomainBuffer) {
        for (let i = 0; i < tempTimeDomainData.length; i++) {
          this.timeDomainData[i] = tempTimeDomainData[i];
        }
      } else {
        this.timeDomainData = tempTimeDomainData;
      }

      let sum = 0;
      const step = 4;
      for (let i = 0; i < tempTimeDomainData.length; i += step) {
        const normalized = (tempTimeDomainData[i] - 128) / 128;
        sum += normalized * normalized;
      }

      const rms = Math.sqrt(sum / (tempTimeDomainData.length / step));
      const estimatedLufs = -0.691 + 10 * Math.log10(rms * rms + 1e-10);

      this.lufsBuffer.push(estimatedLufs);
      if (this.lufsBuffer.length > this.LUFS_WINDOW_SIZE) {
        this.lufsBuffer.shift();
      }
    }
  }

  private setupFallbackAnalysis() {
    this.setupOptimizedFallbackAnalysis();
  }

  private async setupVolumeNormalization() {
    try {
      // Avoid creating/connecting the worklet in passive mode
      if (this.passiveMode) {
        return;
      }

      if (this.audioContext.state !== 'running') {
        await this.audioContext.resume();
      }

      if (!this.audioContext.audioWorklet) {
        throw new Error('AudioWorklet not supported in this browser');
      }

      // If a previous worklet exists, reuse it instead of creating a new one
      if (!this.audioWorkletNode) {
        await this.audioContext.audioWorklet.addModule('/audio-worklets/magic-soup-processor.js');

        this.audioWorkletNode = new AudioWorkletNode(this.audioContext, 'magic-soup-processor', {
          numberOfInputs: 1,
          numberOfOutputs: 1,
          channelCount: 2,
          channelCountMode: 'explicit',
          channelInterpretation: 'speakers'
        });

        this.audioWorkletNode.port.onmessage = (event) => {
          if (event.data.type === 'analysis') {
            this.updateAnalysisFromWorklet(event.data as WorkletAnalysisData);
          }
        };
      }

      if (this.sourceNode) {
        // Rewire: compressor -> worklet -> masterGain
        try {
          this.compressorNode.disconnect();
        } catch {}
        this.compressorNode.connect(this.audioWorkletNode);
        this.audioWorkletNode.connect(this.masterGainNode);
      }

    } catch (error) {
      console.warn('AudioWorklet not supported, using optimized fallback:', error);
      this.setupFallbackAnalysis();
    }
  }

  private updateAnalysisFromWorklet(data: WorkletAnalysisData) {
    this.lufsBuffer.push(data.lufs);
    if (this.lufsBuffer.length > this.LUFS_WINDOW_SIZE) {
      this.lufsBuffer.shift();
    }
  }

  async resumeContextIfNeeded(): Promise<void> {
    if (this.audioContext.state === 'suspended' && !this.contextResumed) {
      try {
        await this.audioContext.resume();
        this.contextResumed = true;
        console.log('AudioContext resumed successfully');
      } catch (error) {
        console.warn('Failed to resume AudioContext:', error);
        throw error;
      }
    }
  }

  async connectAudioElement(audioElement: HTMLAudioElement) {
    try {
      if (this.isConnected && this.audioElement !== audioElement) {
        this.disconnect();
      }

      if (this.isConnected && this.audioElement === audioElement) {
        console.log('Already connected to this audio element');
        return;
      }

      this.audioElement = audioElement;

      this.sourceNode = this.audioContext.createMediaElementSource(audioElement);
      this.sourceNode.connect(this.analyzerNode);

      this.isConnected = true;
      this.passiveMode = false;

      try {
        await this.setupVolumeNormalization();
      } catch (error) {
        console.warn('Could not initialize worklet immediately:', error);
      }

    } catch (error) {
      console.error('Failed to connect audio element:', error);

      if (error instanceof DOMException && error.name === 'InvalidStateError') {
        console.log('Audio element already in use - falling back to passive mode');
        await this.initializePassiveMode();
      } else {
        throw error;
      }
    }
  }

  disconnect() {
    if (this.sourceNode) {
      this.sourceNode.disconnect();
      this.sourceNode = null;
    }

    // Stop analysis interval when not connected to avoid background work
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval);
      this.analysisInterval = null;
    }

    // Tear down any worklet routing and free the node
    this.teardownWorklet();

    // Dispose optional spatial node to avoid accumulation
    if (this.spatialNode) {
      try {
        this.spatialNode.disconnect();
      } catch {}
      this.spatialNode = null;
    }

    this.audioElement = null;
    this.isConnected = false;
    this.passiveMode = false;
  }

  setEnabled() {
    if (this.passiveMode) return;

    const targetGain = 1;
    this.gainNode.gain.setTargetAtTime(targetGain, this.audioContext.currentTime, 0.1);
  }

  setMasterGain(gainDb: number) {
    this.masterGain = gainDb;

    if (this.passiveMode) return;

    const linearGain = Math.pow(10, gainDb / 20);
    this.masterGainNode.gain.setTargetAtTime(
      linearGain,
      this.audioContext.currentTime,
      this.SMOOTHING_TIME
    );
  }

  getMasterGain(): number {
    return this.masterGain;
  }

  updateEQBands(gains: number[]) {
    if (this.passiveMode) return;

    gains.forEach((gain, index) => {
      if (index < this.filters.length) {
        this.filters[index].gain.setTargetAtTime(
          gain,
          this.audioContext.currentTime,
          this.SMOOTHING_TIME
        );
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
      this.gainNode.gain.setTargetAtTime(
        safeGain,
        this.audioContext.currentTime,
        this.SMOOTHING_TIME
      );
    }

    return gainDb;
  }

  getAnalysisData() {
    if (this.passiveMode) {
      const now = this.simulationTime;
      const leftLevel = Math.abs(Math.sin(now / 200)) * 60 + 20;
      const rightLevel = Math.abs(Math.cos(now / 200)) * 60 + 20;

      return {
        frequencyData: this.frequencyData,
        timeDomainData: this.timeDomainData,
        leftChannel: leftLevel,
        rightChannel: rightLevel,
        lufs: this.lufsBuffer.length > 0
              ? this.lufsBuffer.reduce((a, b) => a + b, 0) / this.lufsBuffer.length
              : -20,
        peakFrequency: this.peakFrequency,
        rms: 0.1
      };
    } else {
      if (this.analyzerNode) {
        // Create temporary non-shared arrays for analyzer methods
        const tempFrequencyData = new Uint8Array(this.analyzerNode.frequencyBinCount);
        const tempTimeDomainData = new Uint8Array(this.analyzerNode.frequencyBinCount * 2);

        this.analyzerNode.getByteFrequencyData(tempFrequencyData);
        this.analyzerNode.getByteTimeDomainData(tempTimeDomainData);

        // Copy the data to our class arrays if using shared buffers
        if (this.sharedFrequencyBuffer) {
          for (let i = 0; i < tempFrequencyData.length; i++) {
            this.frequencyData[i] = tempFrequencyData[i];
          }
        } else {
          this.frequencyData = tempFrequencyData;
        }

        if (this.sharedTimeDomainBuffer) {
          for (let i = 0; i < tempTimeDomainData.length; i++) {
            this.timeDomainData[i] = tempTimeDomainData[i];
          }
        } else {
          this.timeDomainData = tempTimeDomainData;
        }

        const bufferLength = this.analyzerNode.frequencyBinCount;
        let leftSum = 0, rightSum = 0;

        for (let i = 0; i < bufferLength; i += 4) {
          // Use tempTimeDomainData for calculations to avoid issues with shared arrays
          const value = tempTimeDomainData[i] / 128.0 - 1.0;
          if (i % 8 === 0) leftSum += value * value;
          else rightSum += value * value;
        }

        const leftLevel = Math.sqrt(leftSum / (bufferLength / 8)) * 100;
        const rightLevel = Math.sqrt(rightSum / (bufferLength / 8)) * 100;

        let maxValue = 0;
        let peakIndex = 0;
        for (let i = 0; i < tempFrequencyData.length; i += 8) {
          if (tempFrequencyData[i] > maxValue) {
            maxValue = tempFrequencyData[i];
            peakIndex = i;
          }
        }
        const peakFrequency = (peakIndex / tempFrequencyData.length) * (this.audioContext.sampleRate / 2);
        this.peakFrequency = peakFrequency;

        const lufs = this.lufsBuffer.length > 0
                     ? this.lufsBuffer.reduce((a, b) => a + b, 0) / this.lufsBuffer.length
                     : -30;

        return {
          // Return temporary arrays for immediate use, but keep shared arrays for worker
          frequencyData: tempFrequencyData,
          timeDomainData: tempTimeDomainData,
          leftChannel: leftLevel,
          rightChannel: rightLevel,
          lufs,
          peakFrequency,
          rms: Math.sqrt(leftSum + rightSum) / (bufferLength / 4)
        };
      }

      // Fallback if analyzer node is not available
      return {
        frequencyData: this.frequencyData,
        timeDomainData: this.timeDomainData,
        leftChannel: 0,
        rightChannel: 0,
        lufs: -30,
        peakFrequency: 0,
        rms: 0
      };
    }
  }

  get isActive(): boolean {
    return this.isConnected;
  }

  destroy() {
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval);
      this.analysisInterval = null;
    }

    if (this.analysisWorker) {
      this.analysisWorker.terminate();
      this.analysisWorker = null;
    }
    // Ensure flag reflects actual state
    this.workerReady = false;

    // Ensure worklet node is torn down on destroy as well
    this.teardownWorklet();

    this.disconnect();

    if (this.audioContext.state !== 'closed') {
      this.audioContext.close();
    }
  }

  setVolume(volume: number) {
    if (this.passiveMode) return;

    this.gainNode.gain.setTargetAtTime(
      volume,
      this.audioContext.currentTime,
      0.05
    );
  }

  setMuted(muted: boolean) {
    if (this.passiveMode) return;

    const targetGain = muted ? 0 : 1;
    this.gainNode.gain.setTargetAtTime(
      targetGain,
      this.audioContext.currentTime,
      0.05
    );
  }
}
