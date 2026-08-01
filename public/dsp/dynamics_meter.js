export async function loadDynamics(url = './dynamics_meter.wasm') {
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

  const init = pick('init_meters', '_init_meters');
  const reset = pick('reset_meters', '_reset_meters');
  const process = pick('process_frames', '_process_frames');

  const rmsL = pick('get_rms_left', '_get_rms_left');
  const rmsR = pick('get_rms_right', '_get_rms_right');
  const peakL = pick('get_peak_left', '_get_peak_left');
  const peakR = pick('get_peak_right', '_get_peak_right');
  const crestL = pick('get_crest_left', '_get_crest_left');
  const crestR = pick('get_crest_right', '_get_crest_right');

  // Helpful error if something essential is missing
  const missing = [];
  if (!memory) missing.push('memory');
  if (!init) missing.push('init_meters');
  if (!process) missing.push('process_frames');
  if (!rmsL) missing.push('get_rms_left');
  if (!rmsR) missing.push('get_rms_right');
  if (!peakL) missing.push('get_peak_left');
  if (!peakR) missing.push('get_peak_right');
  if (!crestL) missing.push('get_crest_left');
  if (!crestR) missing.push('get_crest_right');
  if (missing.length) {
    console.error('[dynamics_meter] Missing exports:', missing, 'Available exports:', Object.keys(e));
    throw new Error('Missing required WASM exports: ' + missing.join(', '));
  }

  return {
    memory,
    init,
    reset,
    process,
    rmsL,
    rmsR,
    peakL,
    peakR,
    crestL,
    crestR,
  };
}
