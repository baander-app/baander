export class VolumeNormalizationService {
  private audioContext: AudioContext;
  private analyzerNode: AnalyserNode;
  private gainNode: GainNode;
  private audioWorkletNode: AudioWorkletNode | null = null;
  private sourceNode: MediaElementAudioSourceNode | null = null;
  private lufsBuffer: number[] = [];
  private readonly LUFS_WINDOW_SIZE = 400; // 400ms window for LUFS calculation
  private readonly SMOOTHING_TIME = 0.1; // seconds for gain smoothing
  private isConnected = false;

  constructor() {
    this.audioContext = new AudioContext();
    this.analyzerNode = this.audioContext.createAnalyser();
    this.gainNode = this.audioContext.createGain();

    // Configure analyzer for better frequency analysis
    this.analyzerNode.fftSize = 2048;
    this.analyzerNode.smoothingTimeConstant = 0.8;

    this.setupAudioWorklet();
  }

  private async setupAudioWorklet() {
    try {
      // Register the audio worklet for LUFS calculation
      await this.audioContext.audioWorklet.addModule('/audio-worklets/lufs-processor.js');

      this.audioWorkletNode = new AudioWorkletNode(this.audioContext, 'lufs-processor');

      // Listen for LUFS measurements from the worklet
      this.audioWorkletNode.port.onmessage = (event) => {
        if (event.data.type === 'lufs') {
          this.onLufsUpdate?.(event.data.value);
        }
      };

      // Connect the audio graph
      this.analyzerNode.connect(this.audioWorkletNode);
      this.audioWorkletNode.connect(this.gainNode);
      this.gainNode.connect(this.audioContext.destination);

    } catch (error) {
      console.warn('AudioWorklet not supported, falling back to ScriptProcessorNode');
      this.setupScriptProcessor();
    }
  }

  private setupScriptProcessor() {
    // Fallback for browsers that don't support AudioWorklet
    const scriptProcessor = this.audioContext.createScriptProcessor(4096, 2, 2);

    scriptProcessor.onaudioprocess = (event) => {
      const lufs = this.calculateLufsFromBuffer(event.inputBuffer);
      this.onLufsUpdate?.(lufs);

      // Pass through audio
      for (let channel = 0; channel < event.outputBuffer.numberOfChannels; channel++) {
        const inputData = event.inputBuffer.getChannelData(channel);
        const outputData = event.outputBuffer.getChannelData(channel);
        outputData.set(inputData);
      }
    };

    this.analyzerNode.connect(scriptProcessor);
    scriptProcessor.connect(this.gainNode);
    this.gainNode.connect(this.audioContext.destination);
  }

  public connectAudioElement(audioElement: HTMLAudioElement) {
    if (this.isConnected) {
      this.disconnect();
    }

    // Resume audio context if suspended
    if (this.audioContext.state === 'suspended') {
      this.audioContext.resume();
    }

    this.sourceNode = this.audioContext.createMediaElementSource(audioElement);
    this.sourceNode.connect(this.analyzerNode);
    this.isConnected = true;
  }

  public disconnect() {
    if (this.sourceNode) {
      this.sourceNode.disconnect();
      this.sourceNode = null;
    }
    this.isConnected = false;
  }

  private calculateLufsFromBuffer(buffer: AudioBuffer): number {
    let sum = 0;
    let sampleCount = 0;

    // Calculate RMS with K-weighting approximation
    for (let channel = 0; channel < buffer.numberOfChannels; channel++) {
      const channelData = buffer.getChannelData(channel);
      for (let i = 0; i < channelData.length; i++) {
        const sample = channelData[i];
        sum += sample * sample;
        sampleCount++;
      }
    }

    const rms = Math.sqrt(sum / sampleCount);

    // Convert to LUFS (simplified approximation)
    const lufs = -0.691 + 10 * Math.log10(rms * rms + 1e-10);

    // Apply gating (simplified)
    this.lufsBuffer.push(lufs);
    if (this.lufsBuffer.length > this.LUFS_WINDOW_SIZE) {
      this.lufsBuffer.shift();
    }

    // Return integrated loudness
    return this.lufsBuffer.reduce((acc, val) => acc + val, 0) / this.lufsBuffer.length;
  }

  public setNormalizationGain(targetLufs: number, currentLufs: number): number {
    const gainDb = Math.max(-20, Math.min(20, targetLufs - currentLufs)); // Limit gain
    const gainLinear = Math.pow(10, gainDb / 20);

    // Apply smooth gain changes
    this.gainNode.gain.setTargetAtTime(
      gainLinear,
      this.audioContext.currentTime,
      this.SMOOTHING_TIME
    );

    return gainDb;
  }

  public setVolume(volume: number) {
    // Set master volume (0-1)
    const currentGain = this.gainNode.gain.value;
    this.gainNode.gain.setTargetAtTime(
      currentGain * volume,
      this.audioContext.currentTime,
      0.05
    );
  }

  public getFrequencyData(): Uint8Array {
    const frequencyData = new Uint8Array(this.analyzerNode.frequencyBinCount);
    this.analyzerNode.getByteFrequencyData(frequencyData);
    return frequencyData;
  }

  public getTimeDomainData(): Uint8Array {
    const timeDomainData = new Uint8Array(this.analyzerNode.fftSize);
    this.analyzerNode.getByteTimeDomainData(timeDomainData);
    return timeDomainData;
  }

  public onLufsUpdate?: (lufs: number) => void;

  public get context(): AudioContext {
    return this.audioContext;
  }

  public get isActive(): boolean {
    return this.isConnected && this.audioContext.state === 'running';
  }

  public destroy() {
    this.disconnect();

    if (this.audioWorkletNode) {
      this.audioWorkletNode.disconnect();
    }

    this.gainNode.disconnect();
    this.analyzerNode.disconnect();

    if (this.audioContext.state !== 'closed') {
      this.audioContext.close();
    }
  }
}