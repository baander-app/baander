import { getServerUrl } from '../shared/config-store';

async function injectCSP() {
  // Remove existing CSP tag if present
  const existingCSP = document.querySelector('meta[http-equiv="Content-Security-Policy"]');
  if (existingCSP) {
    existingCSP.remove();
  }

  const apiServer = await getServerUrl();

  // Create new CSP meta tag with dynamic backend URL
  const cspTag = document.createElement('meta');
  cspTag.httpEquiv = 'Content-Security-Policy';
  cspTag.content = `
    default-src 'self';
    script-src 'self' 'unsafe-eval' blob: http://localhost:5173 ws://localhost:5173;
    style-src 'self' 'unsafe-inline' http://localhost:5173;
    img-src 'self' data: http://localhost:5173 ${apiServer};
    connect-src 'self' blob: ws://localhost:5173 ws://127.0.0.1:5173 http://localhost:5173 http://localhost:5173 ${apiServer};
    font-src 'self' http://localhost:5173;
    media-src 'self' blob: http://localhost:5173 ${apiServer};
    object-src 'none';
    frame-src 'none';
  `;

  // Insert it into the head before any other elements
  document.head.insertBefore(cspTag, document.head.firstChild);
}

// Rest of the file remains the same
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
  await injectCSP();

  // await loadWasmHelpers().catch(() => { /* continue without WASM */ });

  // Now import and start the real app entry
  await import('@/index.tsx');
}

bootstrap().catch((e) => {
  console.error('[bootstrap] Unhandled error:', e);
  import('@/index.tsx');
});
