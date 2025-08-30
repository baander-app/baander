const dspCache: {
  dynamics?: Promise<DynamicsMeterAPI>;
  loudness?: Promise<LoudnessR128API>;
  convolver?: Promise<PartitionedConvolverAPI>;
  resampler?: Promise<ResamplerHQAPI>;
  spectral?: Promise<SpectralFeaturesApi>;
} = {};

type DspType = 'dynamics' | 'loudness' | 'convolver' | 'resampler' | 'spectral';

const fetchAndImport = async (url: string) => {
  const src = await fetch(url).then(res => res.text());
  const blob = new Blob([src], { type: 'application/javascript' });
  return await import(URL.createObjectURL(blob));
}

const dspJsFetcher = (type: DspType) => {
  switch (type) {
    case 'dynamics':
      return fetchAndImport('/dsp/dynamics_meter.js');
    case 'loudness':
      return fetchAndImport('/dsp/loudness_r128.js');
    case 'convolver':
      return fetchAndImport('/dsp/partitioned_convolver.js');
    case 'resampler':
      return fetchAndImport('/dsp/resampler_hq.js');
    case 'spectral':
      return fetchAndImport('/dsp/spectral_features.js');
    default:
      throw new Error(`Unknown DSP type: ${type}`);
  }
};

export function getDynamics(): Promise<DynamicsMeterAPI> {
  return dspCache.dynamics ??= loadDynamics();
}

export function getLoudness(): Promise<LoudnessR128API> {
  return dspCache.loudness ??= loadLoudness();
}

export function getConvolver(): Promise<PartitionedConvolverAPI> {
  return dspCache.convolver ??= loadConvolver();
}

export function getResampler(): Promise<ResamplerHQAPI> {
  return dspCache.resampler ??= loadResampler();
}

export function getSpectralFeatures(): Promise<SpectralFeaturesApi> {
  return dspCache.spectral ??= loadSpectralFeatures();
}

async function loadDynamics(): Promise<DynamicsMeterAPI> {
  const mod = await dspJsFetcher('dynamics') as {
    loadDynamics: (url?: string) => Promise<DynamicsMeterAPI>;
  };
  return mod.loadDynamics('/dsp/dynamics_meter.wasm');
}

async function loadLoudness(): Promise<LoudnessR128API> {
  const mod = await dspJsFetcher('loudness') as {
    loadLoudness: (url?: string) => Promise<LoudnessR128API>;
  };
  return mod.loadLoudness('/dsp/loudness_r128.wasm');
}

async function loadConvolver(): Promise<PartitionedConvolverAPI> {
  const mod = await dspJsFetcher('convolver') as {
    loadConvolver: (url?: string) => Promise<PartitionedConvolverAPI>;
  };
  return mod.loadConvolver('/dsp/partitioned_convolver.wasm');
}

async function loadResampler(): Promise<ResamplerHQAPI> {
  const mod = await dspJsFetcher('resampler') as {
    loadResampler: (url?: string) => Promise<ResamplerHQAPI>;
  };
  return mod.loadResampler('/dsp/resampler_hq.wasm');
}

async function loadSpectralFeatures(): Promise<SpectralFeaturesApi> {
  const mod = await dspJsFetcher('spectral') as {
    loadSpectralFeatures: (url?: string) => Promise<SpectralFeaturesApi>;
  };
  return mod.loadSpectralFeatures('/dsp/spectral_features.wasm');
}
