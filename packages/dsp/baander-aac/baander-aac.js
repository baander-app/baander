/**
 * Baander AAC WASM Decoder Loader
 *
 * Loads and instantiates the baander-aac WebAssembly decoder module.
 * Follows the same pattern as resampler_hq with pick() helper for
 * resolving both underscored and plain export names.
 */
export async function loadAacDecoder(url = './baander-aac.wasm') {
  // Resolve URL relative to this module
  const resolvedUrl = url ? new URL(url, import.meta.url) : new URL('./baander-aac.wasm', import.meta.url);

  const res = await fetch(resolvedUrl);
  if (!res.ok) {
    throw new Error(`[baander-aac] Failed to fetch WASM (${res.status} ${res.statusText}) at ${resolvedUrl}`);
  }
  const bytes = await res.arrayBuffer();

  let instance;
  try {
    ({ instance } = await WebAssembly.instantiate(bytes, {}));
  } catch (err) {
    // Diagnose HTML-returned-as-WASM
    const head = new TextDecoder().decode(bytes.slice(0, 16));
    if (head.startsWith('<!DOCTYPE') || head.startsWith('<html')) {
      throw new Error(`[baander-aac] The fetched file is HTML, not a .wasm. Check the path: ${resolvedUrl}`);
    }
    // WASI shim fallback
    try {
      ({ instance } = await WebAssembly.instantiate(bytes, {
        wasi_snapshot_preview1: {
          fd_write: () => 0, fd_close: () => 0, fd_seek: () => 0,
          fd_read: () => 0, environ_sizes_get: () => 0, environ_get: () => 0,
          random_get: () => 0, poll_oneoff: () => 0,
          proc_exit: (code) => { throw new Error(`WASI proc_exit(${code})`); },
          clock_time_get: () => 0, path_open: () => 0,
          path_filestat_get: () => 0, fd_fdstat_get: () => 0, fd_sync: () => 0,
        },
        env: {},
      }));
    } catch (err2) {
      throw err;
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

  const memory       = pick('memory');
  const decoderCreate  = pick('aac_decoder_create',   '_aac_decoder_create');
  const decoderDestroy = pick('aac_decoder_destroy',  '_aac_decoder_destroy');
  const decoderDecode  = pick('aac_decoder_decode',   '_aac_decoder_decode');
  const decoderFrameSize    = pick('aac_decoder_frame_size',    '_aac_decoder_frame_size');
  const decoderSampleRate   = pick('aac_decoder_sample_rate',   '_aac_decoder_sample_rate');
  const decoderChannels     = pick('aac_decoder_channels',      '_aac_decoder_channels');
  const decoderGetSbrPs     = pick('aac_decoder_get_sbr_ps',    '_aac_decoder_get_sbr_ps');

  // Validate essential exports
  const missing = [];
  if (!memory)         missing.push('memory');
  if (!decoderCreate)  missing.push('aac_decoder_create');
  if (!decoderDestroy) missing.push('aac_decoder_destroy');
  if (!decoderDecode)  missing.push('aac_decoder_decode');
  if (missing.length) {
    console.error('[baander-aac] Missing exports:', missing, 'Available exports:', Object.keys(e));
    throw new Error('Missing required WASM exports: ' + missing.join(', '));
  }

  return {
    memory,
    decoderCreate,
    decoderDestroy,
    decoderDecode,
    decoderFrameSize,
    decoderSampleRate,
    decoderChannels,
    decoderGetSbrPs,
  };
}
