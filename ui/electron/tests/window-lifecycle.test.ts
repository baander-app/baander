import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { mock, test } from 'node:test';
import { getTrustedWindow } from '../src/main/security/renderer-trust.ts';

const handlers = new Map<string, Function>();
const shortcuts = new Map<string, Function>();
const windows: FakeWindow[] = [];
let configured = '';
let registrationCount = 0;
class FakeWindow extends EventEmitter {
  destroyed = false; visible = false; maximized = false; minimized = false;
  sent: unknown[][] = []; loading = true;
  webContents: any;
  constructor(_options: unknown) {
    super(); windows.push(this);
    const contents: any = new EventEmitter();
    contents.mainFrame = { url: '' }; contents.getURL = () => contents.mainFrame.url;
    contents.isDestroyed = () => this.destroyed;
    contents.isLoadingMainFrame = () => this.loading;
    contents.setWindowOpenHandler = () => {};
    contents.send = (...args: unknown[]) => this.sent.push(args);
    contents.openDevTools = () => {};
    this.webContents = contents;
  }
  loadURL(url: string) {
    this.webContents.mainFrame.url = url;
    assert.equal(getTrustedWindow({ sender: this.webContents, senderFrame: this.webContents.mainFrame }), this);
    assert.equal(this.listenerCount('maximize'), url.includes('/config/') ? 0 : 1, 'main listeners must precede load');
    return Promise.resolve();
  }
  finishLoad() { this.loading = false; this.webContents.emit('did-finish-load'); }
  isDestroyed() { return this.destroyed; }
  isVisible() { return this.visible; }
  show() { this.visible = true; }
  close() { this.destroyed = true; this.emit('closed'); }
  minimize() { this.minimized = true; }
  maximize() { this.maximized = true; this.emit('maximize'); }
  unmaximize() { this.maximized = false; this.emit('unmaximize'); }
  isMaximized() { return this.maximized; }
}
const app = Object.assign(new EventEmitter(), {
  isPackaged: true, getAppPath: () => '/opt/baander', whenReady: () => Promise.resolve(),
  commandLine: { appendSwitch() {} }, requestSingleInstanceLock: () => true, quit() {}, exit: () => assert.fail('Unexpected app exit'),
});
mock.module('electron', { exports: {
  app, BrowserWindow: FakeWindow, dialog: { showMessageBoxSync: () => assert.fail('Unexpected dialog') }, screen: { getPrimaryDisplay: () => ({ scaleFactor: 1 }) },
  globalShortcut: { register: (key: string, callback: Function) => shortcuts.set(key, callback), unregisterAll() {} },
  ipcMain: { handle: (channel: string, callback: Function) => { registrationCount++; assert.equal(handlers.has(channel), false); handlers.set(channel, callback); } },
  session: { defaultSession: { setPermissionRequestHandler() {}, setPermissionCheckHandler() {} } },
} });
mock.module('../src/shared/config-store.ts', { exports: { getThemeMoodSync: () => 'dark', getServerUrlSync: () => configured, initMainProcessImpl: () => ({ getServerUrl: () => configured }) } });
mock.module('../src/shared/credentials.ts', { exports: { getUser() {}, setUser() {}, clearUser() {} } });
mock.module('../src/main/security/cors-shim.ts', { exports: { installOrUpdateCorsShim() {} } });
mock.module('../src/main/log.ts', { exports: { mainLog: { error: () => assert.fail('Unexpected load failure'), log() {} } } });
mock.module('../src/main/flags.ts', { exports: { setCliFlags() {} } });
mock.module('../src/main/menu/index.ts', { exports: { setupApplicationMenu() {} } });
mock.module('../src/main/menu/i18n.ts', { exports: { createMenuTranslator: () => () => '' } });
mock.module('../src/main/menu/tray.ts', { exports: { destroyTray() {}, setupTray() {} } });
mock.module('../src/main/protocols/resource-protocol.ts', { exports: { registerWasmProtocol() {} } });
mock.module('../src/main/services/deep-link.service.ts', { exports: { DeepLinkService: class { initializeProtocol() {} setupEventListeners() {} } } });
const discovery = Object.assign(new EventEmitter(), { startScan() {}, stopScan() {}, getServers: () => [], isScanningActive: () => false });
mock.module('../src/main/services/discovery.service.ts', { exports: { getDiscoveryService: () => discovery } });
const { getMainWindow, createMainWindow } = await import('../src/main/windows/main-window.ts');
const { getConfigWindow } = await import('../src/main/windows/config-window.ts');
const event = (window: FakeWindow) => ({ sender: window.webContents, senderFrame: window.webContents.mainFrame });
async function invoke(channel: string, window: FakeWindow) {
  const handler = handlers.get(`baander:${channel}`); assert.ok(handler, channel); return handler(event(window));
}

test('fresh setup and configured startup share controls, listeners and live media targets', async () => {
  await import('../src/main/index.ts?first-run');
  await new Promise(resolve => setImmediate(resolve));
  const config = getConfigWindow() as unknown as FakeWindow; assert.ok(config); config.finishLoad();
  assert.equal(getMainWindow(), null);
  shortcuts.get('MediaPlayPause')!(); assert.deepEqual(config.sent, []);
  await invoke('config:finish', config);
  const main = getMainWindow() as unknown as FakeWindow; assert.ok(main);
  await invoke('config:finish', config);
  assert.equal(windows.length, 2); assert.equal(config.destroyed, false);
  assert.equal(main.webContents.listenerCount('did-finish-load'), 2, 'watchdog + one setup callback');
  main.finishLoad(); assert.equal(config.destroyed, true);
  let called = 0; createMainWindow(() => { called++; }); assert.equal(called, 1);
  for (const action of ['minimize', 'maximize']) await invoke(`window:${action}`, main);
  assert.equal(main.minimized, true); assert.equal(main.maximized, true);
  assert.deepEqual(main.sent, [['baander:window:maximized-change', true]]);
  await invoke('window:maximize', main); assert.equal(main.maximized, false);
  assert.deepEqual(main.sent.at(-1), ['baander:window:maximized-change', false]);
  assert.equal(await invoke('window:is-maximized', main), false);
  shortcuts.get('MediaNextTrack')!(); assert.deepEqual(main.sent.at(-1), ['baander:playback:next']);
  await assert.rejects(async () => invoke('window:minimize', config), /unauthorized/i);
  const registrations = registrationCount;
  await invoke('window:close', main); assert.equal(main.destroyed, true);
  shortcuts.get('MediaPreviousTrack')!();
  configured = 'https://api.baander.app';
  await import('../src/main/index.ts?configured');
  await new Promise(resolve => setImmediate(resolve));
  const next = getMainWindow() as unknown as FakeWindow; assert.ok(next); assert.notEqual(next, main);
  next.finishLoad(); await invoke('window:maximize', next);
  assert.equal(next.listenerCount('maximize'), 1); assert.equal(registrationCount, registrations);
  shortcuts.get('MediaPlayPause')!(); assert.deepEqual(next.sent.at(-1), ['baander:playback:toggle']);
  next.close();
});
