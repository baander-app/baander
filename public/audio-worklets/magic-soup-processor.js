class MagicSoupProcessor extends AudioWorkletProcessor {
  constructor() {
    super();
    this.lufsBuffer = [];
    this.windowSize = 400; // 400ms window
    this.sampleRate = 48000;
    this.frameCount = 0;
  }

  process(inputs, outputs, parameters) {
    const input = inputs[0];
    const output = outputs[0];

    if (input.length > 0) {
      this.sampleRate = globalThis.sampleRate;

      // Calculate analysis data
      const analysisData = this.analyzeAudio(input);

      // Send analysis to main thread every 30 frames (~30fps)
      if (this.frameCount % 30 === 0) {
        this.port.postMessage({
          type: 'analysis',
          ...analysisData
        });
      }

      this.frameCount++;

      // Pass through audio with potential processing
      for (let channel = 0; channel < output.length; channel++) {
        if (input[channel]) {
          output[channel].set(input[channel]);
        }
      }
    }

    return true;
  }

  analyzeAudio(input) {
    const lufs = this.calculateLufs(input);
    const levels = this.calculateChannelLevels(input);
    const rms = this.calculateRMS(input);

    return {
      lufs,
      leftLevel: levels.left,
      rightLevel: levels.right,
      rms
    };
  }

  calculateLufs(input) {
    let sum = 0;
    let sampleCount = 0;

    for (let channel = 0; channel < input.length; channel++) {
      const channelData = input[channel];
      for (let i = 0; i < channelData.length; i++) {
        const sample = channelData[i];
        sum += sample * sample;
        sampleCount++;
      }
    }

    const rms = Math.sqrt(sum / sampleCount);
    const lufs = -0.691 + 10 * Math.log10(rms * rms + 1e-10);

    // Apply windowing
    this.lufsBuffer.push(lufs);
    if (this.lufsBuffer.length > this.windowSize) {
      this.lufsBuffer.shift();
    }

    return this.lufsBuffer.reduce((acc, val) => acc + val, 0) / this.lufsBuffer.length;
  }

  calculateChannelLevels(input) {
    if (input.length === 0) return { left: 0, right: 0 };

    const leftData = input[0];
    const rightData = input.length > 1 ? input[1] : leftData;

    let leftSum = 0, rightSum = 0;

    for (let i = 0; i < leftData.length; i++) {
      leftSum += leftData[i] * leftData[i];
      rightSum += rightData[i] * rightData[i];
    }

    return {
      left: Math.sqrt(leftSum / leftData.length) * 100,
      right: Math.sqrt(rightSum / rightData.length) * 100
    };
  }

  calculateRMS(input) {
    let sum = 0;
    let sampleCount = 0;

    for (let channel = 0; channel < input.length; channel++) {
      const channelData = input[channel];
      for (let i = 0; i < channelData.length; i++) {
        sum += channelData[i] * channelData[i];
        sampleCount++;
      }
    }

    return Math.sqrt(sum / sampleCount);
  }
}

registerProcessor('magic-soup-processor', MagicSoupProcessor);