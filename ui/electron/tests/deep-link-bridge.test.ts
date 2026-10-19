import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { mock, test } from 'node:test';

const ipcRenderer = new EventEmitter();
mock.module('electron', { exports: { ipcRenderer } });
const { deepLinkBridge } = await import('../src/preload/bridges/deep-link.bridge.ts');

test('deep-link subscription removes its wrapped IPC listener', () => {
  const received: string[] = [];
  const unsubscribe = deepLinkBridge.onDeepLinkReceived(url => received.push(url));
  ipcRenderer.emit('baander:deeplink:received', {}, 'baander://login');
  assert.deepEqual(received, ['baander://login']);
  unsubscribe();
  ipcRenderer.emit('baander:deeplink:received', {}, 'baander://later');
  assert.deepEqual(received, ['baander://login']);
});
