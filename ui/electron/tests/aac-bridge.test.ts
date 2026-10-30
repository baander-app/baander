import assert from 'node:assert/strict';
import { mock, test } from 'node:test';

const calls: unknown[][] = [];
mock.module('electron', { exports: { ipcRenderer: { invoke: async (...args: unknown[]) => { calls.push(args); return 'reply'; } } } });
const { aacBridge } = await import('../src/preload/bridges/aac.bridge.ts');

test('every AAC bridge method invokes its channel with the arguments as given', async () => {
  const data = new Uint8Array([0xff, 0xf1]);
  const pcm = new Float32Array([0.5, -0.5]);
  const expected: [string, unknown[]][] = [
    ['baander:aac:info', []],
    ['baander:aac:decode', [data, { channels: 2 }]],
    ['baander:aac:encode', [pcm, { sampleRate: 48000, channels: 2, bitrate: 128000 }]],
    ['baander:aac:decoder-open', [{ sampleRate: 44100, loudness: { targetLufs: -16 }, drcEffect: 'night' }]],
    ['baander:aac:decoder-decode', [3, data]],
    ['baander:aac:decoder-close', [3]],
    ['baander:aac:encoder-open', [{ mode: 'tvbr', quality: 80 }]],
    ['baander:aac:encoder-encode', [4, pcm]],
    ['baander:aac:encoder-flush', [4]],
    ['baander:aac:encoder-close', [4]],
  ];
  assert.equal(await aacBridge.info(), 'reply');
  await aacBridge.decode(data, { channels: 2 });
  await aacBridge.encode(pcm, { sampleRate: 48000, channels: 2, bitrate: 128000 });
  await aacBridge.openDecoder({ sampleRate: 44100, loudness: { targetLufs: -16 }, drcEffect: 'night' });
  await aacBridge.decodeChunk(3, data);
  await aacBridge.closeDecoder(3);
  await aacBridge.openEncoder({ mode: 'tvbr', quality: 80 });
  await aacBridge.encodeChunk(4, pcm);
  await aacBridge.flushEncoder(4);
  await aacBridge.closeEncoder(4);
  assert.deepEqual(calls, expected.map(([channel, args]) => [channel, ...args]));
});

test('the playback, loudness and segmenting methods invoke their channels', async () => {
  calls.length = 0;
  const pcm = new Float32Array([0.25]);
  await aacBridge.flushDecoder(5);
  await aacBridge.clearDecoderHistory(5);
  await aacBridge.encoderLoudness(6);
  await aacBridge.requestIndependentFrame(6);
  await aacBridge.encoderLastFrame(6);
  await aacBridge.measureLoudness(pcm, 48000, 1);
  assert.deepEqual(calls, [
    ['baander:aac:decoder-flush', 5],
    ['baander:aac:decoder-clear-history', 5],
    ['baander:aac:encoder-loudness', 6],
    ['baander:aac:encoder-request-independent-frame', 6],
    ['baander:aac:encoder-last-frame', 6],
    ['baander:aac:measure-loudness', pcm, 48000, 1],
  ]);
});
