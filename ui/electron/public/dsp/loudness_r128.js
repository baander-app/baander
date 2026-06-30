export async function loadLoudness(url = './loudness_r128.wasm') {
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

  const memory = pick('memory');

  const init   = pick('init_loudness',   '_init_loudness');
  const reset  = pick('reset_loudness',  '_reset_loudness');
  const process= pick('process_frames',  '_process_frames');

  const lufsM  = pick('get_lufs_momentary',  '_get_lufs_momentary');
  const lufsS  = pick('get_lufs_shortterm',  '_get_lufs_shortterm');
  const lufsI  = pick('get_lufs_integrated', '_get_lufs_integrated');
  const lra    = pick('get_lra',             '_get_lra');
  const truePk = pick('get_true_peak_dbfs',  '_get_true_peak_dbfs');

  // Helpful error if something essential is missing
  const missing = [];
  if (!memory) missing.push('memory');
  if (!init)   missing.push('init_loudness');
  if (!process)missing.push('process_frames');
  if (!lufsM || !lufsS || !lufsI || !lra || !truePk) {
    if (!lufsM) missing.push('get_lufs_momentary');
    if (!lufsS) missing.push('get_lufs_shortterm');
    if (!lufsI) missing.push('get_lufs_integrated');
    if (!lra)   missing.push('get_lra');
    if (!truePk)missing.push('get_true_peak_dbfs');
  }
  if (missing.length) {
    console.error('[loudness_r128] Missing exports:', missing, 'Available exports:', Object.keys(e));
    throw new Error('Missing required WASM exports: ' + missing.join(', '));
  }

  return {
    memory,
    init,
    reset,
    process,
    lufsM,
    lufsS,
    lufsI,
    lra,
    truePkDbfs: truePk,
  };
}
