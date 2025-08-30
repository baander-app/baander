class AudioAnalysisWorker {
  constructor() {
    // Minimal state
    this.frequencyData = null;
    this.timeDomainData = null;

    // No WASM - too expensive for background worker
    this.spectralReady = false;
  }

  initSharedBuffers(frequencyBuffer, timeDomainBuffer) {
    this.frequencyData = new Uint8Array(frequencyBuffer);
    this.timeDomainData = new Uint8Array(timeDomainBuffer);
  }

  initArraysWithoutSAB(freqLen, timeLen) {
    this.frequencyData = new Uint8Array(freqLen || 1024);
    this.timeDomainData = new Uint8Array(timeLen || 2048);
  }

  // Minimal spectral analysis - just peak detection
  findPeakFrequency(frequencyData, sampleRate = 48000) {
    if (!frequencyData) return 0;

    let peakIndex = 0;
    let peakValue = 0;

    // Check only lower frequencies to save CPU
    const maxBin = Math.min(256, frequencyData.length);

    for (let i = 0; i < maxBin; i += 4) { // Every 4th bin
      if (frequencyData[i] > peakValue) {
        peakValue = frequencyData[i];
        peakIndex = i;
      }
    }

    // Convert to Hz
    const fftSize = frequencyData.length * 2;
    return (peakIndex / (fftSize / 2)) * (sampleRate / 2);
  }

  processAnalysisRequest(data) {
    const { frequencyData, sampleRate, useSharedBuffer } = data;

    // Only compute peak frequency - skip expensive spectral features
    const peakFrequency = this.findPeakFrequency(
      frequencyData || this.frequencyData,
      sampleRate
    );

    if (useSharedBuffer) {
      return { peakFrequency };
    } else {
      return {
        frequencyData: frequencyData || this.frequencyData,
        timeDomainData: data.timeDomainData || this.timeDomainData,
        peakFrequency,
        spectralCentroid: 0,  // Disabled
        spectralRolloff: 0,   // Disabled
        spectralFlux: 0,      // Disabled
        spectralFlatness: 0,  // Disabled
      };
    }
  }
}

const analysisWorker = new AudioAnalysisWorker();

self.onmessage = function(e) {
  const { type, frequencyBuffer, timeDomainBuffer, length } = e.data || {};

  switch (type) {
    case 'init-shared-buffers':
      if (frequencyBuffer && timeDomainBuffer) {
        analysisWorker.initSharedBuffers(frequencyBuffer, timeDomainBuffer);
      }
      break;

    case 'init':
      analysisWorker.initArraysWithoutSAB(length?.freq, length?.time);
      break;

    case 'init-spectral-wasm':
      // Skip WASM initialization to save memory
      break;

    case 'analyze':
      const result = analysisWorker.processAnalysisRequest(e.data);
      self.postMessage({
        type: 'analysis-result',
        ...result
      });
      break;
  }
};
