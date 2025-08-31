export async function loadSpectralFeatures(url = './spectral_features.wasm') {
  const bytes = await (await fetch(url)).arrayBuffer();
  const { instance } = await WebAssembly.instantiate(bytes, {});
  const e = instance.exports;

  // Helper to resolve either plain or underscored export names
  const pick = (...names) => {
    for (const n of names) {
      if (typeof e[n] === 'function' || typeof e[n] === 'object') return e[n];
    }
    return undefined;
  };

  const memory          = pick('memory');
  const malloc          = pick('wasm_malloc',        '_wasm_malloc');
  const free            = pick('wasm_free',          '_wasm_free');
  const init            = pick('init_features',      '_init_features');
  const computeFromMag  = pick('compute_from_mag',   '_compute_from_mag');
  const getCentroidHz   = pick('get_centroid_hz',    '_get_centroid_hz');
  const getRolloffHz    = pick('get_rolloff_hz',     '_get_rolloff_hz');
  const getFlux         = pick('get_flux',           '_get_flux');
  const getFlatness     = pick('get_flatness',       '_get_flatness');
  const getPeakIndex    = pick('get_peak_index',     '_get_peak_index');
  const getBandEnergies = pick('get_band_energies',  '_get_band_energies');

  // Helpful error if something essential is missing
  const missing = [];
  if (!memory) missing.push('memory');
  if (!malloc) missing.push('wasm_malloc');
  if (!free) missing.push('wasm_free');
  if (!init) missing.push('init_features');
  if (!computeFromMag) missing.push('compute_from_mag');
  if (missing.length) {
    console.error('[spectral_features] Missing exports:', missing, 'Available exports:', Object.keys(e));
    throw new Error('Missing required WASM exports: ' + missing.join(', '));
  }

  return {
    memory,
    malloc,
    free,
    init,
    computeFromMag,
    getCentroidHz,
    getRolloffHz,
    getFlux,
    getFlatness,
    getPeakIndex,
    getBandEnergies,
  };
}
