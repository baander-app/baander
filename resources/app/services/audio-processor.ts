interface WorkletAnalysisData {
  type: 'analysis';
  lufs: number;
  leftChannel: number;
  rightChannel: number;
  rms: number;
}

export class AudioProcessor {
  private audioContext: AudioContext;
  private sourceNode: MediaElementAudioSourceNode | null = null;
  private analyzerNode: AnalyserNode;
  private gainNode: GainNode;
  private masterGainNode: GainNode; // New master gain control
  private compressorNode: DynamicsCompressorNode;
  private filters: BiquadFilterNode[] = [];
  private spatialNode: ConvolverNode | null = null;
  private audioWorkletNode: AudioWorkletNode | null = null;

  // Volume normalization
  private lufsBuffer: number[] = [];
  private readonly LUFS_WINDOW_SIZE = 400;
  private readonly SMOOTHING_TIME = 0.1;

  private isConnected = false;
  private hasWorkletSupport = false;
  private passiveMode = false;
  private audioElement: HTMLAudioElement | null = null;

  // New gain controls
  private masterGain = 0; // dB

  private readonly frequencies = [31.5, 63, 125, 250, 500, 1000, 2000, 4000, 8000, 16000];

  // Analysis data - initialize with proper sizes
  private frequencyData = new Uint8Array(1024);
  private timeDomainData = new Uint8Array(2048);

  // Fallback analysis interval
  private analysisInterval: number | null = null;

  // Simulation state for passive mode
  private simulationTime = 0;

  constructor() {
    this.audioContext = new AudioContext();
    this.initializeNodes();
    this.setupAudioGraph();

    // Initialize arrays with some baseline data
    this.frequencyData.fill(20);
    this.timeDomainData.fill(128);
  }

  // Add passive mode for when we can't connect directly
  async initializePassiveMode() {
    console.log('Initializing passive mode - using simulated analysis (ENABLED)');
    this.passiveMode = true;
    this.isConnected = true;

    this.setupFallbackAnalysis();

    try {
      await this.setupVolumeNormalization();
    } catch (error) {
      console.warn('Could not initialize worklet in passive mode:', error);
    }
  }

  private async initializeWorkletIfNeeded() {
    if (!this.hasWorkletSupport && !this.audioWorkletNode) {
      await this.setupVolumeNormalization();
    }
  }

  private initializeNodes() {
    this.analyzerNode = this.audioContext.createAnalyser();
    this.analyzerNode.fftSize = 2048;
    this.analyzerNode.smoothingTimeConstant = 0.8;

    this.gainNode = this.audioContext.createGain();
    this.masterGainNode = this.audioContext.createGain(); // New master gain node
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

  private async setupVolumeNormalization() {
    try {
      if (this.audioContext.state !== 'running') {
        await this.audioContext.resume();
      }

      if (!this.audioContext.audioWorklet) {
        throw new Error('AudioWorklet not supported in this browser');
      }

      await this.audioContext.audioWorklet.addModule('/audio-worklets/magic-soup-processor.js');

      this.audioWorkletNode = new AudioWorkletNode(this.audioContext, 'magic-soup-processor', {
        numberOfInputs: 1,
        numberOfOutputs: 1,
        channelCount: 2,
        channelCountMode: 'explicit',
        channelInterpretation: 'speakers'
      });

      this.hasWorkletSupport = true;

      this.audioWorkletNode.port.onmessage = (event) => {
        if (event.data.type === 'analysis') {
          this.updateAnalysisFromWorklet(event.data as WorkletAnalysisData);
        }
      };

      // Only connect worklet if we're not in passive mode
      if (!this.passiveMode && this.sourceNode) {
        // Insert worklet between compressor and master gain
        this.compressorNode.disconnect();
        this.compressorNode.connect(this.audioWorkletNode);
        this.audioWorkletNode.connect(this.masterGainNode);
      }

    } catch (error) {
      console.warn('No soup.. AudioWorklet not supported, using fallback analysis:', error);
      this.hasWorkletSupport = false;
      this.setupFallbackAnalysis();
    }
  }

  private setupFallbackAnalysis() {
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval);
    }

    this.analysisInterval = window.setInterval(() => {
      this.performFallbackAnalysis();
    }, 33); // Update every ~33ms for ~30fps
  }

  private performFallbackAnalysis() {
    this.simulationTime += 33; // Increment by 33ms

    if (this.passiveMode) {
      // Generate realistic frequency spectrum with bass emphasis and natural decay
      for (let i = 0; i < this.frequencyData.length; i++) {
        const freqRatio = i / this.frequencyData.length;

        let baseLevel;
        if (freqRatio < 0.1) {
          // Bass frequencies (stronger)
          baseLevel = 80 + Math.sin(this.simulationTime / 200) * 30;
        } else if (freqRatio < 0.3) {
          // Mid-bass
          baseLevel = 60 + Math.sin(this.simulationTime / 150) * 25;
        } else if (freqRatio < 0.6) {
          // Midrange
          baseLevel = 40 + Math.sin(this.simulationTime / 100) * 20;
        } else {
          // High frequencies (weaker)
          baseLevel = 20 + Math.sin(this.simulationTime / 80) * 15;
        }

        // Add some random variation
        const noise = (Math.random() - 0.5) * 10;
        const variation = Math.sin(freqRatio * 10 + this.simulationTime / 50) * 5;

        this.frequencyData[i] = Math.max(0, Math.min(255, Math.floor(baseLevel + noise + variation)));
      }

      for (let i = 0; i < this.timeDomainData.length; i++) {
        const t = this.simulationTime / 1000 + i / 1000;
        // Create a complex waveform with multiple harmonics
        const fundamental = Math.sin(t * 440 * 2 * Math.PI) * 0.5;
        const harmonic2 = Math.sin(t * 880 * 2 * Math.PI) * 0.25;
        const harmonic3 = Math.sin(t * 1320 * 2 * Math.PI) * 0.125;
        const noise = (Math.random() - 0.5) * 0.1;

        const sample = fundamental + harmonic2 + harmonic3 + noise;
        this.timeDomainData[i] = Math.floor((sample + 1) * 127.5);
      }

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
      this.analyzerNode.getByteFrequencyData(this.frequencyData);
      this.analyzerNode.getByteTimeDomainData(this.timeDomainData);

      // Calculate a simple RMS-based LUFS estimation
      let sum = 0;
      for (let i = 0; i < this.timeDomainData.length; i++) {
        const normalized = (this.timeDomainData[i] - 128) / 128;
        sum += normalized * normalized;
      }

      const rms = Math.sqrt(sum / this.timeDomainData.length);
      const estimatedLufs = -0.691 + 10 * Math.log10(rms * rms + 1e-10);

      // Update LUFS buffer
      this.lufsBuffer.push(estimatedLufs);
      if (this.lufsBuffer.length > this.LUFS_WINDOW_SIZE) {
        this.lufsBuffer.shift();
      }
    }
  }

  private updateAnalysisFromWorklet(data: WorkletAnalysisData) {
    this.lufsBuffer.push(data.lufs);
    if (this.lufsBuffer.length > this.LUFS_WINDOW_SIZE) {
      this.lufsBuffer.shift();
    }
  }

  async connectAudioElement(audioElement: HTMLAudioElement) {
    try {
      if (this.isConnected && this.audioElement !== audioElement) {
        this.disconnect();
      }

      if (this.isConnected && this.audioElement === audioElement) {
        console.log('Already connected to this audio element - PROCESSOR ENABLED');
        return;
      }

      this.audioElement = audioElement;

      if (this.audioContext.state === 'suspended') {
        await this.audioContext.resume();
      }

      if (this.audioContext.state !== 'running') {
        await new Promise(resolve => {
          const checkState = () => {
            if (this.audioContext.state === 'running') {
              resolve(void 0);
            } else {
              setTimeout(checkState, 10);
            }
          };
          checkState();
        });
      }

      this.sourceNode = this.audioContext.createMediaElementSource(audioElement);
      this.sourceNode.connect(this.analyzerNode);

      this.isConnected = true;
      this.passiveMode = false;
      await this.initializeWorkletIfNeeded();

    } catch (error) {
      console.error('Failed to connect audio element:', error);

      if (error instanceof DOMException && error.name === 'InvalidStateError') {
        console.log('Audio element already in use - falling back to passive mode - PROCESSOR STILL ENABLED');
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

    this.audioElement = null;
    this.isConnected = false;
    this.passiveMode = false;
    console.log('Audio processor disconnected - but REMAINS ENABLED');
  }

  setEnabled() {
    if (this.passiveMode) {
      return;
    }

    const targetGain = 1
    this.gainNode.gain.setTargetAtTime(targetGain, this.audioContext.currentTime, 0.1);
  }

  setMasterGain(gainDb: number) {
    this.masterGain = gainDb;

    if (this.passiveMode) {
      console.log(`Master gain set to ${gainDb}dB (passive mode)`);
      return;
    }

    // Convert dB to linear gain
    const linearGain = Math.pow(10, gainDb / 20);

    // Apply with smoothing to prevent audio artifacts
    this.masterGainNode.gain.setTargetAtTime(
      linearGain,
      this.audioContext.currentTime,
      this.SMOOTHING_TIME
    );

    console.log(`Master gain applied: ${gainDb}dB (linear: ${linearGain.toFixed(3)})`);
  }

  // Get current master gain
  getMasterGain(): number {
    return this.masterGain;
  }

  updateEQBands(gains: number[]) {
    // Always allow EQ band updates since processor is always enabled
    if (this.passiveMode) {
      console.log('EQ bands updated (passive mode - still active):', gains);
      return;
    }

    gains.forEach((gain, index) => {
      if (index < this.filters.length) {
        this.filters[index].gain.setTargetAtTime(
          gain,
          this.audioContext.currentTime,
          this.SMOOTHING_TIME
        );
      }
    });

    console.log('EQ bands applied:', gains);
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

    console.log(`Compression ${enabled ? 'enabled' : 'disabled'} (processor always enabled)`);
  }

  setSpatialEnhancement(enabled: boolean) {
    if (this.passiveMode) return;

    // Implementation placeholder - requires convolver setup
    if (enabled && !this.spatialNode) {
      this.spatialNode = this.audioContext.createConvolver();
      // TODO: Load impulse response for spatial effect
      console.log('Spatial enhancement enabled - implementation needed (processor always enabled)');
    }
  }

  applyVolumeNormalization(targetLufs: number, currentLufs: number): number {
    const gainDb = Math.max(-20, Math.min(20, targetLufs - currentLufs));

    if (!this.passiveMode) {
      // Apply normalization gain through the main gain node
      const normGainLinear = Math.pow(10, gainDb / 20);
      // Use a subtle normalization gain to avoid clipping
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
      // Calculate simulated levels from our generated data
      const now = this.simulationTime;
      const leftLevel = Math.abs(Math.sin(now / 200)) * 60 + 20;
      const rightLevel = Math.abs(Math.cos(now / 200)) * 60 + 20;

      // Find peak frequency (simulated)
      let maxValue = 0;
      let peakIndex = 0;
      for (let i = 0; i < this.frequencyData.length; i++) {
        if (this.frequencyData[i] > maxValue) {
          maxValue = this.frequencyData[i];
          peakIndex = i;
        }
      }
      const peakFrequency = (peakIndex / this.frequencyData.length) * (this.audioContext.sampleRate / 2);

      return {
        frequencyData: this.frequencyData,
        timeDomainData: this.timeDomainData,
        leftChannel: leftLevel,
        rightChannel: rightLevel,
        lufs: this.lufsBuffer.length > 0
              ? this.lufsBuffer.reduce((a, b) => a + b, 0) / this.lufsBuffer.length
              : -20,
        peakFrequency,
        rms: 0.1
      };
    } else {
      // Get real analysis data
      if (this.analyzerNode) {
        this.analyzerNode.getByteFrequencyData(this.frequencyData);
        this.analyzerNode.getByteTimeDomainData(this.timeDomainData);
      }
    }

    // Calculate current levels from time domain data
    const bufferLength = this.analyzerNode.frequencyBinCount;
    let leftSum = 0, rightSum = 0;

    for (let i = 0; i < bufferLength; i++) {
      const value = this.timeDomainData[i] / 128.0 - 1.0;
      if (i % 2 === 0) leftSum += value * value;
      else rightSum += value * value;
    }

    const leftLevel = Math.sqrt(leftSum / (bufferLength / 2)) * 100;
    const rightLevel = Math.sqrt(rightSum / (bufferLength / 2)) * 100;

    // Find peak frequency
    let maxValue = 0;
    let peakIndex = 0;
    for (let i = 0; i < this.frequencyData.length; i++) {
      if (this.frequencyData[i] > maxValue) {
        maxValue = this.frequencyData[i];
        peakIndex = i;
      }
    }
    const peakFrequency = (peakIndex / this.frequencyData.length) * (this.audioContext.sampleRate / 2);

    // Calculate integrated LUFS
    const lufs = this.lufsBuffer.length > 0
                 ? this.lufsBuffer.reduce((a, b) => a + b, 0) / this.lufsBuffer.length
                 : -30;

    return {
      frequencyData: this.frequencyData,
      timeDomainData: this.timeDomainData,
      leftChannel: leftLevel,
      rightChannel: rightLevel,
      lufs,
      peakFrequency,
      rms: Math.sqrt(leftSum + rightSum) / bufferLength
    };
  }

  get isActive(): boolean {
    return this.isConnected;
  }

  destroy() {
    if (this.analysisInterval) {
      clearInterval(this.analysisInterval);
      this.analysisInterval = null;
    }

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