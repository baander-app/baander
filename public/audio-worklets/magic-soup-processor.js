class MagicSoupProcessor extends AudioWorkletProcessor {
  constructor() {
    super();

    // Analysis state
    this.analysisBufferSize = 2048;
    this.analysisBuffer = new Float32Array(this.analysisBufferSize);
    this.bufferIndex = 0;

    // LUFS calculation state
    this.lufsBuffer = new Float32Array(400); // 400 sample LUFS window
    this.lufsIndex = 0;
    this.lufsWindowSize = 400;

    // Performance optimization - reduce analysis frequency
    this.frameCount = 0;
    this.analysisInterval = 128; // Only analyze every 128 frames (~2.7ms at 48kHz)

    // Pre-compute constants for LUFS
    this.k = -0.691; // LUFS K-weighting constant
    this.preGain = Math.pow(10, 14.7 / 20); // Pre-gain for LUFS
  }

  process(inputs, outputs, parameters) {
    const input = inputs[0];
    const output = outputs[0];

    // Pass audio through unchanged
    if (input && output && input.length > 0) {
      for (let channel = 0; channel < input.length; channel++) {
        if (output[channel]) {
          output[channel].set(input[channel]);
        }
      }

      // Perform analysis at reduced frequency
      this.frameCount++;
      if (this.frameCount % this.analysisInterval === 0) {
        this.performAnalysis(input);
      }
    }

    return true;
  }

  performAnalysis(inputChannels) {
    if (!inputChannels || inputChannels.length === 0) return;

    const leftChannel = inputChannels[0];
    const rightChannel = inputChannels[1] || inputChannels[0]; // Fallback to mono

    // Calculate RMS levels for each channel
    let leftSum = 0, rightSum = 0;
    const sampleCount = leftChannel.length;

    // Optimized RMS calculation
    for (let i = 0; i < sampleCount; i++) {
      const leftSample = leftChannel[i];
      const rightSample = rightChannel[i];

      leftSum += leftSample * leftSample;
      rightSum += rightSample * rightSample;

      // Store samples in analysis buffer for frequency analysis
      this.analysisBuffer[this.bufferIndex] = (leftSample + rightSample) * 0.5;
      this.bufferIndex = (this.bufferIndex + 1) % this.analysisBufferSize;
    }

    // Convert to dB levels (0-100 scale)
    const leftLevel = Math.sqrt(leftSum / sampleCount) * 100;
    const rightLevel = Math.sqrt(rightSum / sampleCount) * 100;

    // Calculate LUFS using simplified K-weighting
    const rms = Math.sqrt((leftSum + rightSum) / (sampleCount * 2));
    const lufs = this.k + 10 * Math.log10(rms * rms * this.preGain + 1e-10);

    // Update LUFS buffer for smoothing
    this.lufsBuffer[this.lufsIndex] = lufs;
    this.lufsIndex = (this.lufsIndex + 1) % this.lufsWindowSize;

    // Calculate smoothed LUFS
    let lufsSum = 0;
    for (let i = 0; i < this.lufsWindowSize; i++) {
      lufsSum += this.lufsBuffer[i];
    }
    const smoothedLufs = lufsSum / this.lufsWindowSize;

    // Send analysis data to main thread
    this.port.postMessage({
      type: 'analysis',
      lufs: smoothedLufs,
      leftChannel: Math.min(100, leftLevel),
      rightChannel: Math.min(100, rightLevel),
      rms: rms
    });
  }
}

registerProcessor('magic-soup-processor', MagicSoupProcessor);
