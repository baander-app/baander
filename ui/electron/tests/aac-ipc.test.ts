import assert from 'node:assert/strict';
import { mock, test } from 'node:test';

const handlers = new Map<string, Function>();
const calls: unknown[][] = [];
const service = new Proxy({}, { get: (_target, name: string) => (...args: unknown[]) => { calls.push([name, ...args]); return `${name}-result`; } });
mock.module('../src/ipc/guard.ts', { exports: { handleTrusted: (channel: string, fn: Function) => { assert.equal(handlers.has(channel), false); handlers.set(channel, fn); } } });
mock.module('../src/main/services/aac.service.ts', { exports: { getAacService: () => service } });
const { registerAacIpc } = await import('../src/ipc/modules/aac.ipc.ts');
registerAacIpc();

/** The validated options without the keys the validator leaves undefined. */
function given(call: unknown[]): unknown[] {
  return call.map(arg =>
    arg && typeof arg === 'object' && !ArrayBuffer.isView(arg) && !(arg instanceof ArrayBuffer)
      ? Object.fromEntries(Object.entries(arg).filter(([, v]) => v !== undefined))
      : arg);
}

async function invoke(channel: string, ...args: unknown[]) {
  const handler = handlers.get(`baander:aac:${channel}`);
  assert.ok(handler, channel);
  return handler({}, ...args);
}

test('valid requests reach the service with validated arguments', async () => {
  const data = new Uint8Array([1, 2, 3]);
  const pcm = new Float32Array([0.1]);
  assert.equal(await invoke('info'), 'info-result');
  assert.equal(await invoke('decode', data, { channels: 1 }), 'decode-result');
  assert.equal(await invoke('decode', data.buffer), 'decode-result');
  assert.equal(await invoke('encode', pcm, { sampleRate: 48000, channels: 2, bitrate: 96000, mode: 'abr' }), 'encode-result');
  assert.equal(await invoke('decoder-open', undefined), 'openDecoder-result');
  assert.equal(await invoke('decoder-decode', 7, data), 'decodeChunk-result');
  assert.equal(await invoke('decoder-close', 7), 'closeDecoder-result');
  assert.equal(await invoke('encoder-open', { mode: 'tvbr', quality: 90 }), 'openEncoder-result');
  assert.equal(await invoke('encoder-encode', 8, pcm), 'encodeChunk-result');
  assert.equal(await invoke('encoder-flush', 8), 'flushEncoder-result');
  assert.equal(await invoke('encoder-close', 8), 'closeEncoder-result');
  assert.deepEqual(calls.map(c => c[0]), ['info', 'decode', 'decode', 'encode', 'openDecoder', 'decodeChunk', 'closeDecoder', 'openEncoder', 'encodeChunk', 'flushEncoder', 'closeEncoder']);
  assert.deepEqual(given(calls[1]), ['decode', data, { channels: 1 }]);
  assert.deepEqual(given(calls[7]), ['openEncoder', { mode: 'tvbr', quality: 90 }]);
  calls.length = 0;
  assert.equal(await invoke('decode', data, { channels: 0 }), 'decode-result');
  assert.equal(await invoke('encoder-open', { sampleRate: 48000, channels: 6, bitrate: 192000, profile: 'he' }), 'openEncoder-result');
  assert.deepEqual(given(calls[0]), ['decode', data, { channels: 0 }]);
  assert.deepEqual(given(calls[1]), ['openEncoder', { sampleRate: 48000, channels: 6, bitrate: 192000, profile: 'he' }]);
  calls.length = 0;
  assert.equal(await invoke('decoder-open', { drc: { cut: 50, targetRefLevel: 100 }, eldDownscale: 2 }), 'openDecoder-result');
  assert.equal(await invoke('encoder-open', { profile: 'eld', frameLength: 480, ldSbr: { dualRate: false }, afterburner: true, metadata: { drcProfile: 'speech', programRefLevel: 112 } }), 'openEncoder-result');
  assert.deepEqual(given(calls[0]), ['openDecoder', { drc: { cut: 50, targetRefLevel: 100 }, eldDownscale: 2 }]);
  assert.deepEqual(given(calls[1]), ['openEncoder', { profile: 'eld', frameLength: 480, ldSbr: { dualRate: false }, afterburner: true, metadata: { drcProfile: 'speech', programRefLevel: 112 } }]);
  calls.length = 0;
  assert.equal(await invoke('decoder-open', { loudness: { enabled: true, targetLufs: -16.5 }, drcEffect: 'night', albumMode: true, drcBoost: 50, drcCut: 0 }), 'openDecoder-result');
  assert.equal(await invoke('decode', data, { loudness: false }), 'decode-result');
  assert.deepEqual(given(calls[0]), ['openDecoder', { loudness: { enabled: true, targetLufs: -16.5 }, drcEffect: 'night', albumMode: true, drcBoost: 50, drcCut: 0 }]);
  assert.deepEqual(given(calls[1]), ['decode', data, { loudness: false }]);
});

test('malformed payloads are rejected before the service is called', async () => {
  const pcm = new Float32Array(4);
  const bad: [string, unknown[]][] = [
    ['decode', ['not bytes']],
    ['decode', [new Uint8Array(1), { channels: 9 }]],
    ['decode', [new Uint8Array(1), { channels: 1.5 }]],
    ['encode', [pcm, { channels: 0 }]],
    ['encode', [pcm, { profile: 'main' }]],
    ['decode', [new Uint8Array(1), { sampleRate: 37000 }]],
    ['encode', [new Int16Array(4), {}]],
    ['encode', [pcm, { mode: 'fast' }]],
    ['encode', [pcm, { bitrate: 100 }]],
    ['encode', [pcm, { quality: 0 }]],
    ['encode', [pcm, { profile: 'ld', transport: 'adts' }]],
    ['encode', [pcm, { profile: 'ld', frameLength: 1024 }]],
    ['encode', [pcm, { profile: 'lc', frameLength: 512 }]],
    ['encode', [pcm, { profile: 'lc', ldSbr: true }]],
    ['encode', [pcm, { metadata: { drcProfile: 'loud' } }]],
    ['encode', [pcm, { metadata: { programRefLevel: 128 } }]],
    ['decode', [new Uint8Array(1), { drc: { boost: 101 } }]],
    ['decode', [new Uint8Array(1), { eldDownscale: 3 }]],
    ['decode', [new Uint8Array(1), { loudness: { targetLufs: -5 } }]],
    ['decode', [new Uint8Array(1), { loudness: { enabled: 'yes' } }]],
    ['decode', [new Uint8Array(1), { loudness: [] }]],
    ['decode', [new Uint8Array(1), { drcEffect: 'loud' }]],
    ['decode', [new Uint8Array(1), { albumMode: 1 }]],
    ['decode', [new Uint8Array(1), { drcBoost: 101 }]],
    ['decode', [new Uint8Array(1), { drcCut: -1 }]],
    ['encode', [pcm, []]],
    ['decoder-decode', ['1', new Uint8Array(1)]],
    ['decoder-decode', [0, new Uint8Array(1)]],
    ['encoder-encode', [1.5, pcm]],
    ['encoder-close', [-1]],
  ];
  for (const [channel, args] of bad) {
    calls.length = 0;
    await assert.rejects(() => invoke(channel, ...args), /invalid/i, `${channel} ${JSON.stringify(args)}`);
    assert.deepEqual(calls, []);
  }
});
