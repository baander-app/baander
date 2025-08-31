// Load WASM using helper modules from electron/src/dsp, then start the app.

async function loadWasmHelpers() {
  try {
    // Dynamically import helper modules (bundled under electron/src/dsp)
    const [
      loudnessMod,
      dynamicsMod,
      spectralMod,
      convolverMod,
      resamplerMod,
    ] = await Promise.all([
      import('../dsp/loudness_r128'),
      import('../dsp/dynamics_meter'),
      import('../dsp/spectral_features'),
      import('../dsp/partitioned_convolver'),
      import('../dsp/resampler_hq'),
    ]);

    // Load their WASM counterparts via helpers
    const [
      loudness,
      dynamics,
      spectral,
      convolver,
      resampler,
    ] = await Promise.all([
      loudnessMod.loadLoudness('../dsp/loudness_r128.wasm'),
      dynamicsMod.loadDynamics('../dsp/dynamics_meter.wasm'),
      spectralMod.loadSpectralFeatures('../dsp/spectral_features.wasm'),
      convolverMod.loadConvolver('../dsp/partitioned_convolver.wasm'),
      resamplerMod.loadResampler('../dsp/resampler_hq.wasm'),
    ]);

    (window as any).__baanderWasm = {
      loudness,
      dynamics,
      spectral,
      convolver,
      resampler,
    };
  } catch (err) {
    console.warn('[WASM] Failed to load one or more modules via helpers:', err);
  }
}

async function bootstrap() {
  // await loadWasmHelpers().catch(() => { /* continue without WASM */ });

  // Now import and start the real app entry
  await import('@/index.tsx');
}

bootstrap().catch((e) => {
  console.error('[bootstrap] Unhandled error:', e);
  import('@/index.tsx');
});
