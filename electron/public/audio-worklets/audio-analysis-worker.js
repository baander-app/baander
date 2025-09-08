class AudioAnalysisWorker {
  constructor() {
    // WASM spectral features API
    this.spectralAPI = null;
    this.spectralReady = false;

    // Data buffers - no longer generate simulation data
    this.frequencyData = null;
    this.timeDomainData = null;

    // Analysis results
    this.peakFrequency = 0;
    this.spectralCentroid = 0;
    this.spectralRolloff = 0;
    this.spectralFlux = 0;
    this.spectralFlatness = 0;
  }

  async initSpectralWasm(wasmBytes) {
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
      const init = pick('init_spectral', '_init_spectral');
      const malloc = pick('malloc', '_malloc', 'wasm_malloc', '_wasm_malloc');
      const free = pick('free', '_free', 'wasm_free', '_wasm_free');
      const computeFromMag = pick('compute_from_mag', '_compute_from_mag');
      const getCentroidHz = pick('get_centroid_hz', '_get_centroid_hz');
      const getRolloffHz = pick('get_rolloff_hz', '_get_rolloff_hz');
      const getFlux = pick('get_flux', '_get_flux');
      const getFlatness = pick('get_flatness', '_get_flatness');
      const getPeakIndex = pick('get_peak_index', '_get_peak_index');

      if (!memory || !computeFromMag || !getCentroidHz || !malloc) {
        throw new Error('Missing required spectral exports');
      }

      this.spectralAPI = {
        memory,
        init: init || (() => {}),
        malloc,
        free: free || (() => {}),
        computeFromMag,
        getCentroidHz,
        getRolloffHz: getRolloffHz || (() => 0),
        getFlux: getFlux || (() => 0),
        getFlatness: getFlatness || (() => 0),
        getPeakIndex: getPeakIndex || (() => 0),
      };

      // Initialize with default FFT size and sample rate
      if (this.spectralAPI.init) {
        this.spectralAPI.init(2048, 48000);
      }

      this.spectralReady = true;
      console.log('Spectral features WASM initialized in worker');
    } catch (error) {
      console.warn('Failed to initialize spectral WASM in worker:', error);
      this.spectralReady = false;
    }
  }

  initSharedBuffers(frequencyBuffer, timeDomainBuffer) {
    this.frequencyData = new Uint8Array(frequencyBuffer);
    this.timeDomainData = new Uint8Array(timeDomainBuffer);
  }

  initArraysWithoutSAB(freqLen, timeLen) {
    this.frequencyData = new Uint8Array(freqLen || 1024);
    this.timeDomainData = new Uint8Array(timeLen || 2048);
  }

  computeSpectralFeatures(frequencyData, sampleRate = 48000) {
    if (!this.spectralAPI || !this.spectralReady || !frequencyData) {
      return {
        peakFrequency: 0,
        spectralCentroid: 0,
        spectralRolloff: 0,
        spectralFlux: 0,
        spectralFlatness: 0,
      };
    }

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
      const fftSize = frequencyData.length * 2; // magnitude array is half of FFT size
      this.peakFrequency = (peakIndex / (fftSize / 2)) * (sampleRate / 2);

      // Cleanup
      this.spectralAPI.free(magPtr);

      return {
        peakFrequency: this.peakFrequency,
        spectralCentroid: this.spectralCentroid,
        spectralRolloff: this.spectralRolloff,
        spectralFlux: this.spectralFlux,
        spectralFlatness: this.spectralFlatness,
      };
    } catch (error) {
      console.warn('Spectral features computation error in worker:', error);
      return {
        peakFrequency: 0,
        spectralCentroid: 0,
        spectralRolloff: 0,
        spectralFlux: 0,
        spectralFlatness: 0,
      };
    }
  }

  // Minimal spectral analysis - just peak detection (fallback when WASM not available)
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
    const { frequencyData, timeDomainData, sampleRate, useSharedBuffer } = data;

    // Process spectral features if we have frequency data
    const spectralFeatures = this.spectralReady
                             ? this.computeSpectralFeatures(
        frequencyData || this.frequencyData,
        sampleRate
      )
                             : {
        peakFrequency: this.findPeakFrequency(
          frequencyData || this.frequencyData,
          sampleRate
        ),
        spectralCentroid: 0,  // Disabled without WASM
        spectralRolloff: 0,   // Disabled without WASM
        spectralFlux: 0,      // Disabled without WASM
        spectralFlatness: 0,  // Disabled without WASM
      };

    if (useSharedBuffer) {
      // With SharedArrayBuffer, only send computed features
      return spectralFeatures;
    } else {
      // Send both arrays and computed features
      return {
        frequencyData: frequencyData || this.frequencyData,
        timeDomainData: timeDomainData || this.timeDomainData,
        ...spectralFeatures,
      };
    }
  }
}

const analysisWorker = new AudioAnalysisWorker();

self.onmessage = function(e) {
  const {
    type,
    frequencyBuffer,
    timeDomainBuffer,
    length,
    spectralWasm,
    frequencyData,
    timeDomainData,
    sampleRate,
    useSharedBuffer
  } = e.data || {};

  switch (type) {
    case 'init-shared-buffers':
      analysisWorker.initSharedBuffers(frequencyBuffer, timeDomainBuffer);
      break;

    case 'init':
      analysisWorker.initArraysWithoutSAB(length?.freq, length?.time);
      break;

    case 'init-spectral-wasm':
      analysisWorker.initSpectralWasm(spectralWasm);
      break;

    case 'analyze':
      const result = analysisWorker.processAnalysisRequest({
        frequencyData,
        timeDomainData,
        sampleRate,
        useSharedBuffer
      });

      self.postMessage({
        type: 'analysis-result',
        ...result
      });
      break;
  }
};
