import assert from 'node:assert/strict';
import { mock, test } from 'node:test';

const handlers = new Map<string, Function>();
const calls: unknown[][] = [];
const service = new Proxy({}, { get: (_target, name: string) => (...args: unknown[]) => { calls.push([name, ...args]); return `${name}-result`; } });
mock.module('../src/ipc/guard.ts', { exports: { handleTrusted: (channel: string, fn: Function) => { assert.equal(handlers.has(channel), false); handlers.set(channel, fn); } } });
mock.module('../src/main/services/aac.service.ts', { exports: { getAacService: () => service } });
const { registerAacIpc } = await import('../src/ipc/modules/aac.ipc.ts');
registerAacIpc();

/** The validated options, nested ones included, without the keys the validator leaves undefined. */
function defined(arg: unknown): unknown {
  return arg && typeof arg === 'object' && !Array.isArray(arg) && !ArrayBuffer.isView(arg) && !(arg instanceof ArrayBuffer)
    ? Object.fromEntries(Object.entries(arg).filter(([, v]) => v !== undefined).map(([k, v]) => [k, defined(v)]))
    : arg;
}

function given(call: unknown[]): unknown[] {
  return call.map(defined);
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
  calls.length = 0;
  const xhe = { profile: 'xhe', usac: { independentIntervalMs: 2000, independentIntervalSamples: 96000, independentMaxDistanceFrames: 100, segmentMaxBytes: 65536, drc: true, drcTargetRangeLu: 8 } };
  assert.equal(await invoke('encoder-open', xhe), 'openEncoder-result');
  assert.equal(await invoke('encoder-open', { peakBitrate: 192000, bandwidth: 15000, vbrMode: 3 }), 'openEncoder-result');
  assert.equal(await invoke('encoder-open', { peakBitrate: 0, bandwidth: 0 }), 'openEncoder-result');
  assert.deepEqual(given(calls[0]), ['openEncoder', xhe]);
  assert.deepEqual(given(calls[1]), ['openEncoder', { peakBitrate: 192000, bandwidth: 15000, vbrMode: 3 }]);
  assert.deepEqual(given(calls[2]), ['openEncoder', { peakBitrate: 0, bandwidth: 0 }]);
  calls.length = 0;
  assert.equal(await invoke('decoder-flush', 7), 'flushDecoder-result');
  assert.equal(await invoke('decoder-clear-history', 7), 'clearDecoderHistory-result');
  assert.equal(await invoke('encoder-loudness', 8), 'encoderLoudness-result');
  assert.equal(await invoke('encoder-request-independent-frame', 8), 'requestIndependentFrame-result');
  assert.equal(await invoke('encoder-last-frame', 8), 'encoderLastFrame-result');
  assert.equal(await invoke('measure-loudness', pcm, 48000, 1), 'measureLoudness-result');
  assert.deepEqual(calls, [
    ['flushDecoder', 7], ['clearDecoderHistory', 7], ['encoderLoudness', 8],
    ['requestIndependentFrame', 8], ['encoderLastFrame', 8], ['measureLoudness', pcm, 48000, 1],
  ]);
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
    ['decoder-flush', [0]],
    ['decoder-clear-history', ['7']],
    ['encoder-request-independent-frame', [1.5]],
    ['encoder-last-frame', [null]],
    ['encode', [pcm, { profile: 'xhe', usac: { independentIntervalMs: -1 } }]],
    ['encode', [pcm, { profile: 'xhe', usac: { segmentMaxBytes: 1.5 } }]],
    ['encode', [pcm, { profile: 'xhe', usac: { segmentMaxBytes: 2 ** 32 } }]],
    ['encode', [pcm, { profile: 'xhe', usac: { independentMaxDistanceFrames: '4' } }]],
    ['encode', [pcm, { profile: 'lc', usac: { independentIntervalSamples: 1024 } }]],
    ['encode', [pcm, { profile: 'xhe', usac: { drc: 1 } }]],
    ['encode', [pcm, { profile: 'xhe', usac: { drcTargetRangeLu: 20 } }]],
    ['encode', [pcm, { profile: 'xhe', usac: { drcTargetRangeLu: 8.5 } }]],
    ['encode', [pcm, { peakBitrate: 100 }]],
    ['encode', [pcm, { bandwidth: 500 }]],
    ['encode', [pcm, { bandwidth: 96000 }]],
    ['encode', [pcm, { vbrMode: 0 }]],
    ['encode', [pcm, { vbrMode: 6 }]],
    ['encode', [pcm, { vbrMode: '2' }]],
    ['measure-loudness', [pcm, 4000, 1]],
    ['measure-loudness', [pcm, 48000, 7]],
  ];
  for (const [channel, args] of bad) {
    calls.length = 0;
    await assert.rejects(() => invoke(channel, ...args), /invalid/i, `${channel} ${JSON.stringify(args)}`);
    assert.deepEqual(calls, []);
  }
});
