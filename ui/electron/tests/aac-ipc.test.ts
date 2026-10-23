import assert from 'node:assert/strict';
import { mock, test } from 'node:test';

const handlers = new Map<string, Function>();
const calls: unknown[][] = [];
const service = new Proxy({}, { get: (_target, name: string) => (...args: unknown[]) => { calls.push([name, ...args]); return `${name}-result`; } });
mock.module('../src/ipc/guard.ts', { exports: { handleTrusted: (channel: string, fn: Function) => { assert.equal(handlers.has(channel), false); handlers.set(channel, fn); } } });
mock.module('../src/main/services/aac.service.ts', { exports: { getAacService: () => service } });
const { registerAacIpc } = await import('../src/ipc/modules/aac.ipc.ts');
registerAacIpc();

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
  assert.deepEqual(calls[1], ['decode', data, { sampleRate: undefined, channels: 1 }]);
  assert.deepEqual(calls[7], ['openEncoder', { sampleRate: undefined, channels: undefined, bitrate: undefined, mode: 'tvbr', quality: 90, profile: undefined }]);
  calls.length = 0;
  assert.equal(await invoke('decode', data, { channels: 0 }), 'decode-result');
  assert.equal(await invoke('encoder-open', { sampleRate: 48000, channels: 6, bitrate: 192000, profile: 'he' }), 'openEncoder-result');
  assert.deepEqual(calls[0], ['decode', data, { sampleRate: undefined, channels: 0 }]);
  assert.deepEqual(calls[1], ['openEncoder', { sampleRate: 48000, channels: 6, bitrate: 192000, mode: undefined, quality: undefined, profile: 'he' }]);
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
