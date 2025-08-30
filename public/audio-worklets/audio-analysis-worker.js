
class AudioAnalysisWorker {
  constructor() {
    this.fftSize = 2048;
    this.sampleRate = 48000;

    // SharedArrayBuffer support
    this.sharedFrequencyBuffer = null;
    this.sharedTimeDomainBuffer = null;
    this.frequencyData = null;
    this.timeDomainData = null;

    this.setupFFT();

    // Simulation state
    this.simulationTime = 0;
    this.simulationCounter = 0;
  }

  initSharedBuffers(frequencyBuffer, timeDomainBuffer) {
    if (frequencyBuffer && timeDomainBuffer) {
      this.sharedFrequencyBuffer = frequencyBuffer;
      this.sharedTimeDomainBuffer = timeDomainBuffer;
      this.frequencyData = new Uint8Array(this.sharedFrequencyBuffer);
      this.timeDomainData = new Uint8Array(this.sharedTimeDomainBuffer);
      console.log('Worker: Using SharedArrayBuffer for zero-copy performance');
    } else {
      // Fallback to regular arrays
      this.frequencyData = new Uint8Array(this.fftSize / 2);
      this.timeDomainData = new Uint8Array(this.fftSize);
      console.log('Worker: Using regular ArrayBuffer');
    }
  }

  setupFFT() {
    this.twiddleFactors = new Float32Array(this.fftSize);
    for (let i = 0; i < this.fftSize / 2; i++) {
      this.twiddleFactors[i * 2] = Math.cos(-2 * Math.PI * i / this.fftSize);
      this.twiddleFactors[i * 2 + 1] = Math.sin(-2 * Math.PI * i / this.fftSize);
    }
  }

  performFFT(audioData) {
    const N = audioData.length;
    const output = new Float32Array(N);

    for (let k = 0; k < N / 2; k++) {
      let real = 0, imag = 0;

      if (k % 4 !== 0) continue;

      for (let n = 0; n < N; n += 4) {
        const angle = -2 * Math.PI * k * n / N;
        const cos = Math.cos(angle);
        const sin = Math.sin(angle);

        real += audioData[n] * cos;
        imag += audioData[n] * sin;
      }

      const magnitude = Math.sqrt(real * real + imag * imag);
      output[k] = magnitude;
    }

    return output;
  }

  generateSimulatedSpectrum() {
    this.simulationTime += 50;
    this.simulationCounter++;

    if (this.simulationCounter % 2 !== 0) {
      return {
        frequencyData: this.frequencyData,
        timeDomainData: this.timeDomainData,
        peakFrequency: 440 + Math.sin(this.simulationTime / 1000) * 100
      };
    }

    // Generate data directly into shared buffer if available
    const step = Math.ceil(this.frequencyData.length / 128);

    for (let i = 0; i < this.frequencyData.length; i += step) {
      const freqRatio = i / this.frequencyData.length;

      let baseLevel;
      if (freqRatio < 0.1) {
        baseLevel = 80 + Math.sin(this.simulationTime / 200) * 30;
      } else if (freqRatio < 0.3) {
        baseLevel = 60 + Math.sin(this.simulationTime / 150) * 25;
      } else if (freqRatio < 0.6) {
        baseLevel = 40 + Math.sin(this.simulationTime / 100) * 20;
      } else {
        baseLevel = 20 + Math.sin(this.simulationTime / 80) * 15;
      }

      const noise = (Math.random() - 0.5) * 10;
      const variation = Math.sin(freqRatio * 10 + this.simulationTime / 50) * 5;
      const value = Math.max(0, Math.min(255, Math.floor(baseLevel + noise + variation)));

      for (let j = 0; j < step && i + j < this.frequencyData.length; j++) {
        this.frequencyData[i + j] = value;
      }
    }

    const sampleStep = Math.floor(this.timeDomainData.length / 64);
    for (let i = 0; i < this.timeDomainData.length; i += sampleStep) {
      const t = this.simulationTime / 1000 + i / 1000;
      const sample = Math.sin(t * 440 * 2 * Math.PI) * 0.5 +
        Math.sin(t * 880 * 2 * Math.PI) * 0.25 +
        (Math.random() - 0.5) * 0.1;

      const value = Math.floor((sample + 1) * 127.5);

      for (let j = 0; j < sampleStep && i + j < this.timeDomainData.length; j++) {
        this.timeDomainData[i + j] = value;
      }
    }

    let maxValue = 0;
    let peakIndex = 0;
    for (let i = 0; i < this.frequencyData.length; i += 4) {
      if (this.frequencyData[i] > maxValue) {
        maxValue = this.frequencyData[i];
        peakIndex = i;
      }
    }

    const peakFrequency = (peakIndex / this.frequencyData.length) * (this.sampleRate / 2);

    return {
      frequencyData: this.frequencyData,
      timeDomainData: this.timeDomainData,
      peakFrequency
    };
  }

  processAudioData(audioData, isPassiveMode, useSharedBuffer) {
    if (isPassiveMode) {
      const result = this.generateSimulatedSpectrum();

      // With SharedArrayBuffer, data is already written to shared memory
      if (useSharedBuffer) {
        return {
          peakFrequency: result.peakFrequency
        };
      } else {
        return result;
      }
    }

    const fftResult = this.performFFT(audioData);

    for (let i = 0; i < this.frequencyData.length; i++) {
      this.frequencyData[i] = Math.min(255, Math.floor(fftResult[i] * 255));
    }

    for (let i = 0; i < Math.min(audioData.length, this.timeDomainData.length); i++) {
      this.timeDomainData[i] = Math.floor((audioData[i] + 1) * 127.5);
    }

    let maxValue = 0;
    let peakIndex = 0;
    for (let i = 0; i < fftResult.length; i++) {
      if (fftResult[i] > maxValue) {
        maxValue = fftResult[i];
        peakIndex = i;
      }
    }

    const peakFrequency = (peakIndex / fftResult.length) * (this.sampleRate / 2);

    if (useSharedBuffer) {
      return { peakFrequency };
    } else {
      return {
        frequencyData: this.frequencyData,
        timeDomainData: this.timeDomainData,
        peakFrequency
      };
    }
  }
}

const analysisWorker = new AudioAnalysisWorker();

self.onmessage = function(e) {
  const { type, audioData, isPassiveMode, sampleRate, useSharedBuffer, frequencyBuffer, timeDomainBuffer } = e.data;

  if (type === 'init-shared-buffers') {
    analysisWorker.initSharedBuffers(frequencyBuffer, timeDomainBuffer);
    return;
  }

  if (type === 'analyze') {
    if (sampleRate) {
      analysisWorker.sampleRate = sampleRate;
    }

    const result = analysisWorker.processAudioData(audioData, isPassiveMode, useSharedBuffer);

    if (useSharedBuffer) {
      // With SharedArrayBuffer, only send metadata
      self.postMessage({
        type: 'analysis-result',
        peakFrequency: result.peakFrequency
      });
    } else {
      // Fallback: transfer buffers
      self.postMessage({
        type: 'analysis-result',
        ...result
      }, result.frequencyData ? [result.frequencyData.buffer, result.timeDomainData.buffer] : []);
    }
  }
};
