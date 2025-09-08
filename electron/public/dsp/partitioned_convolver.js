export async function loadConvolver(url = './partitioned_convolver.wasm') {
  const bytes = await (await fetch(url)).arrayBuffer();
  let instance;
  try {
    ({ instance } = await WebAssembly.instantiate(bytes, {}));
  } catch (e) {
    // Fallback for WASI-compiled binaries that expect wasi_snapshot_preview1 imports
    const wasi = {
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
    };
    ({ instance } = await WebAssembly.instantiate(bytes, {
      wasi_snapshot_preview1: wasi,
      env: {},
    }));
  }
  const e = instance.exports;

  // Helper to resolve either plain or underscored export names
  const pick = (...names) => {
    for (const n of names) {
      if (typeof e[n] === 'function' || typeof e[n] === 'object') return e[n];
    }
    return undefined;
  };

  const memory  = pick('memory');
  const create  = pick('create_convolver',  '_create_convolver');
  const process = pick('process',           '_process');
  const destroy = pick('destroy_convolver', '_destroy_convolver');

  // Helpful error if something essential is missing
  const missing = [];
  if (!memory)  missing.push('memory');
  if (!create)  missing.push('create_convolver');
  if (!process) missing.push('process');
  if (!destroy) missing.push('destroy_convolver');
  if (missing.length) {
    console.error('[partitioned_convolver] Missing exports:', missing, 'Available exports:', Object.keys(e));
    throw new Error('Missing required WASM exports: ' + missing.join(', '));
  }

  return {
    memory,
    create,
    process,
    destroy,
  };
}
