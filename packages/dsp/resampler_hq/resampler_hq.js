export async function loadResampler(url = './resampler_hq.wasm') {
  // Resolve URL relative to this module so demos work regardless of where they are served from
  const resolvedUrl = url ? new URL(url, import.meta.url) : new URL('./resampler_hq.wasm', import.meta.url);

  const res = await fetch(resolvedUrl);
  if (!res.ok) {
    throw new Error(`[resampler_hq] Failed to fetch WASM (${res.status} ${res.statusText}) at ${resolvedUrl}`);
  }
  const bytes = await res.arrayBuffer();

  let instance;
  try {
    ({ instance } = await WebAssembly.instantiate(bytes, {}));
  } catch (err) {
    // If server returned HTML (e.g., 404 page), help diagnose the "magic word" error
    const head = new TextDecoder().decode(bytes.slice(0, 16));
    if (head.startsWith('<!DOCTYPE') || head.startsWith('<html')) {
      throw new Error(`[resampler_hq] The fetched file is HTML, not a .wasm. Check the path: ${resolvedUrl}`);
    }
    // Optional WASI shim in case the module was compiled with WASI
    try {
      ({ instance } = await WebAssembly.instantiate(bytes, {
        wasi_snapshot_preview1: {
          fd_write: () => 0,
          fd_close: () => 0,
          fd_seek: () => 0,
          fd_read: () => 0,
          environ_sizes_get: () => 0,
          environ_get: () => 0,
          random_get: () => 0,
          poll_oneoff: () => 0,
          proc_exit: (code) => { throw new Error(`WASI proc_exit(${code})`); },
          clock_time_get: () => 0,
          path_open: () => 0,
          path_filestat_get: () => 0,
          fd_fdstat_get: () => 0,
          fd_sync: () => 0,
        },
        env: {},
      }));
    } catch (err2) {
      throw err; // surface the original error if WASI fallback doesn't apply
    }
  }

  const e = instance.exports;

  // Helper to resolve either plain or underscored export names
  const pick = (...names) => {
    for (const n of names) {
      if (typeof e[n] === 'function' || typeof e[n] === 'object') return e[n];
    }
    return undefined;
  };

  const memory   = pick('memory');
  const create   = pick('create_resampler',   '_create_resampler');
  const destroy  = pick('destroy_resampler',  '_destroy_resampler');
  const resample = pick('resample',           '_resample');

  // Helpful error if something essential is missing
  const missing = [];
  if (!memory)   missing.push('memory');
  if (!create)   missing.push('create_resampler');
  if (!destroy)  missing.push('destroy_resampler');
  if (!resample) missing.push('resample');
  if (missing.length) {
    console.error('[resampler_hq] Missing exports:', missing, 'Available exports:', Object.keys(e));
    throw new Error('Missing required WASM exports: ' + missing.join(', '));
  }

  return {
    memory,
    create,
    destroy,
    resample,
  };
}
