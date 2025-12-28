import { resolveDspLoader } from './loaders';
import type { DspModuleType } from './loaders/dsp-loader.interface';

const dspCache: {
  dynamics?: Promise<DynamicsMeterAPI>;
  loudness?: Promise<LoudnessR128API>;
  convolver?: Promise<PartitionedConvolverAPI>;
  resampler?: Promise<ResamplerHQAPI>;
  spectral?: Promise<SpectralFeaturesApi>;
} = {};

const loader = resolveDspLoader();

type DspModule = {
  name: DspModuleType;
  loadMethod: string;
};

const modules: DspModule[] = [
  { name: 'dynamics_meter', loadMethod: 'loadDynamics' },
  { name: 'loudness_r128', loadMethod: 'loadLoudness' },
  { name: 'partitioned_convolver', loadMethod: 'loadConvolver' },
  { name: 'resampler_hq', loadMethod: 'loadResampler' },
  { name: 'spectral_features', loadMethod: 'loadSpectralFeatures' },
];

async function loadModule(module: DspModule): Promise<any> {
  const mod = await loader.loadJsModule(module.name);
  const wasmUrl = loader.getWasmUrl(module.name);
  return mod[module.loadMethod](wasmUrl);
}

export function getDynamics(): Promise<DynamicsMeterAPI> {
  return dspCache.dynamics ??= loadModule(modules[0]);
}

export function getLoudness(): Promise<LoudnessR128API> {
  return dspCache.loudness ??= loadModule(modules[1]);
}

export function getConvolver(): Promise<PartitionedConvolverAPI> {
  return dspCache.convolver ??= loadModule(modules[2]);
}

export function getResampler(): Promise<ResamplerHQAPI> {
  return dspCache.resampler ??= loadModule(modules[3]);
}

export function getSpectralFeatures(): Promise<SpectralFeaturesApi> {
  return dspCache.spectral ??= loadModule(modules[4]);
}
