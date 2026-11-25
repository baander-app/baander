import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { mock, test } from 'node:test';
import { createRendererTrust, registerTrustedWindow } from '../src/main/security/renderer-trust.ts';
const handlers = new Map<string, Function>();
const effects: unknown[][] = [];
let server = ''; let main: any;
const discovery = Object.assign(new EventEmitter(), { startScan: () => effects.push(['scan']), stopScan: () => effects.push(['stop']), getServers: () => [], isScanningActive: () => false });
const exposed = new Map<string, any>();
mock.module('electron', { exports: {
  app: {},
  ipcMain: { handle: (name: string, fn: Function) => { assert.equal(handlers.has(name), false); handlers.set(name, fn); } },
  BrowserWindow: { getAllWindows: () => [] },
  contextBridge: { exposeInMainWorld: (name: string, api: unknown) => exposed.set(name, api) },
  ipcRenderer: { invoke: async (...args: unknown[]) => args, on() {}, removeListener() {}, removeAllListeners() {} },
} });
mock.module('../src/shared/config-store.ts', { exports: { initMainProcessImpl: () => ({ getServerUrl: () => { effects.push(['read']); return server; }, setServerUrl: (v: string) => { effects.push(['write', v]); server = v; }, getThemeMood: () => 'dark', setThemeMood: (v: string) => effects.push(['mood', v]) }) } });
mock.module('../src/shared/credentials.ts', { exports: Object.fromEntries(['setUser', 'clearUser'].map(name => [name, (...args: unknown[]) => { effects.push([name, ...args]); return true; }])) });
mock.module('../src/main/security/cors-shim.ts', { exports: { installOrUpdateCorsShim: (...args: unknown[]) => effects.push(['cors', ...args]) } });
mock.module('../src/main/windows/main-window.ts', { exports: { getMainWindow: () => main, createMainWindow: () => main } });
mock.module('../src/main/windows/config-window.ts', { exports: { getConfigWindow: () => null, createConfigWindow: () => effects.push(['config-window']) } });
mock.module('../src/main/services/discovery.service.ts', { exports: { getDiscoveryService: () => discovery } });
mock.module('../src/main/log.ts', { exports: { mainLog: { log() {}, error() {} } } });
const { registerIpc } = await import('../src/ipc/index.ts');
registerIpc({ rendererOrigin: 'http://baander.app:5175', getServerUrlSync: () => server, deepLinkService: { getPendingUrl: () => { effects.push(['pending']); return null; }, clearPendingUrl: () => effects.push(['clear']) } });
const trust = createRendererTrust({ devServerUrl: 'http://baander.app:5175', mainFilePath: '/main.html', configFilePath: '/config.html' });
function fixture(trusted = true) {
  const window: any = new EventEmitter();
  window.webContents = { mainFrame: { url: trust.mainUrl }, getURL: () => window.webContents.mainFrame.url, isDestroyed: () => false };
  window.isDestroyed = () => false;
  for (const action of ['minimize', 'maximize', 'unmaximize', 'close', 'setBackgroundColor']) window[action] = (...args: unknown[]) => effects.push([action, ...args]);
  window.isMaximized = () => false; window.isVisible = () => true; window.isMinimized = () => false; if (trusted) registerTrustedWindow(window, trust);
  return { window, event: { sender: window.webContents, senderFrame: window.webContents.mainFrame } };
}
async function invoke(channel: string, event: any, ...args: unknown[]) { const handler = handlers.get(`baander:${channel}`); assert.ok(handler, channel); return handler(event, ...args); }
test('every handler rejects foreign, subframe, missing, destroyed and untrusted callers without side effects', async () => {
  main = fixture().window;
  for (const change of ['foreign', 'subframe', 'missing', 'destroyed', 'contents', 'detached', 'url']) {
    const own = fixture();
    if (change === 'foreign') own.event.sender = fixture(false).event.sender;
    if (change === 'subframe') own.event.senderFrame = { url: trust.mainUrl };
    if (change === 'missing') own.event.senderFrame = null as any;
    if (change === 'destroyed') own.window.isDestroyed = () => true;
    if (change === 'contents') own.window.webContents.isDestroyed = () => true;
    if (change === 'detached') own.event.senderFrame.detached = true;
    if (change === 'url') own.event.senderFrame.url = 'https://api.baander.app';
    for (const [name, handler] of handlers) { effects.length = 0; await assert.rejects(async () => handler(own.event, 'https://api.baander.app', 'password'), /unauthorized/i, `${name}: ${change}`); assert.deepEqual(effects, []); }
  }
});
test('trusted discovery and pending deep-link actions reach the existing services', async () => {
  const { event } = fixture(); effects.length = 0;
  await invoke('discovery:start-scan', event); await invoke('discovery:stop-scan', event);
  await invoke('deeplink:get-pending-url', event); await invoke('deeplink:clear-pending-url', event);
  assert.deepEqual(effects, [['scan'], ['stop'], ['pending'], ['clear']]);
});
test('configuration accepts current URL/mood contracts and rejects malformed payloads before mutations', async () => {
  const { event } = fixture(); assert.equal(await invoke('config:get-server-url', event), '');
  for (const [value, origin] of [['https://api.baander.app/path', 'https://api.baander.app'], ['http://192.168.1.2:8080/', 'http://192.168.1.2:8080'], ['', '']]) {
    effects.length = 0;
    assert.equal(await invoke('config:set-server-url', event, value), true);
    assert.deepEqual(effects, [['write', origin], ['cors', origin, 'http://baander.app:5175']]);
  }
  for (const value of [null, false, 3, {}, 'broken', 'file:///tmp/app', 'javascript:alert(1)']) { effects.length = 0; await assert.rejects(async () => invoke('config:set-server-url', event, value), /invalid/i); assert.deepEqual(effects, []); }
  for (const mood of ['dark', 'warm', 'cool', 'balanced']) await invoke('config:set-theme-mood', event, mood);
  for (const mood of ['', 'other', 1, null]) { effects.length = 0; await assert.rejects(async () => invoke('config:set-theme-mood', event, mood), /invalid/i); assert.deepEqual(effects, []); }
});
test('a server URL is stored as its origin, so trailing text cannot reach the content policy', async () => {
  const { event } = fixture();
  effects.length = 0;
  assert.equal(await invoke('config:set-server-url', event, 'https://api.baander.app/path;script-src *'), true);
  assert.deepEqual(effects, [['write', 'https://api.baander.app'], ['cors', 'https://api.baander.app', 'http://baander.app:5175']]);
  assert.equal(await invoke('config:get-server-url', event), 'https://api.baander.app');
});
test('keyring stores and clears credentials and rejects invalid arguments before service calls', async () => {
  const { event } = fixture(); effects.length = 0;
  await invoke('config:set-user', event, 'user@baander.app', ''); await invoke('config:clear-user', event, 'user@baander.app');
  assert.deepEqual(effects, [['setUser', 'user@baander.app', ''], ['clearUser', 'user@baander.app']]);
  for (const [channel, args] of [['clear-user', []], ['clear-user', ['']], ['clear-user', ['user\0name']], ['set-user', ['user']], ['set-user', ['user', 7]], ['set-user', [false, 'password']]] as const) { effects.length = 0; await assert.rejects(async () => invoke(`config:${channel}`, event, ...args), /invalid/i); assert.deepEqual(effects, []); }
});
test('no channel returns a stored credential to page script', async () => {
  assert.equal(handlers.has('baander:config:get-user'), false);
  await import('../src/preload/index.ts');
  const config = exposed.get('BaanderElectron').config;
  assert.deepEqual(Object.keys(config).sort(), [
    'clearUser',
    'finishSetup',
    'getServerUrl',
    'getThemeMood',
    'openSetup',
    'setServerUrl',
    'setThemeMood',
    'setUser',
  ]);
  for (const declarations of ['../src/preload/bridges/types/bridges.d.ts', '../../web/src/types.d.ts']) {
    assert.doesNotMatch(readFileSync(new URL(declarations, import.meta.url), 'utf8'), /getUser|get-user/, declarations);
  }
});
test('setup can be reopened from a trusted window, which opens the configuration window and closes the main window', async () => {
  const own = fixture();
  let closed = 0;
  own.window.close = () => { closed++; };
  main = own.window;
  effects.length = 0;
  assert.equal(await invoke('config:open-setup', own.event), true);
  assert.deepEqual(effects, [['config-window']]);
  assert.equal(closed, 1);
});
test('native controls require the live main owner and reject invalid colors', async () => {
  const own = fixture(); main = own.window;
  for (const color of ['#000', '#faf5ee', '#AA112233']) await invoke('window:set-background-color', own.event, color);
  for (const color of ['garbage', {}, null, 'url(https://baander.app)']) { effects.length = 0; await assert.rejects(async () => invoke('window:set-background-color', own.event, color), /invalid/i); assert.deepEqual(effects, []); }
  for (const action of ['minimize', 'maximize', 'close']) await invoke(`window:${action}`, own.event);
  effects.length = 0; await assert.rejects(async () => invoke('window:minimize', fixture().event), /unauthorized/i); assert.deepEqual(effects, []);
  assert.equal(await invoke('window:is-visible', own.event), true);
  own.window.isMinimized = () => true; assert.equal(await invoke('window:is-visible', own.event), false);
  await assert.rejects(async () => invoke('window:is-visible', fixture().event), /unauthorized/i);
});
test('failed configuration save reports feedback without finishing setup', async () => {
  // The window's style imports are bundled by Vite; only its behaviour runs here.
  const script = readFileSync(new URL('../src/renderer/config/index.html', import.meta.url), 'utf8').match(/<script[^>]*>([\s\S]*?)<\/script>/)![1]
    .replace(/^\s*import\s+['"][^'"]+['"];?\s*$/gm, '');
  for (const result of [false, new Error('failed')]) {
    const elements = new Map<string, any>(); const alerts: string[] = []; let finished = 0;
    const element = (id: string) => { if (!elements.has(id)) elements.set(id, { value: 'https://baander.app', addEventListener(name: string, callback: Function) { this[name] = callback; }, focus() {}, classList: { add() {}, remove() {} } }); return elements.get(id); };
    const bridge = { config: { getServerUrl: async () => '', setServerUrl: async () => { if (result instanceof Error) throw result; return result; }, finishSetup: async () => { finished++; return true; } }, discovery: new Proxy({}, { get: () => () => {} }) };
    runInNewContext(script, { URL, window: { BaanderElectron: bridge }, document: { getElementById: element }, alert: (message: string) => alerts.push(message) });
    await element('saveBtn').click(); assert.equal(finished, 0); assert.equal(alerts.length, 1);
  }
});
