import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { mock, test } from 'node:test';
import { pathToFileURL } from 'node:url';
import { createRendererTrust, getTrustedWindow } from '../src/main/security/renderer-trust.ts';

const permissionHandlers: Record<string, Function> = {};
mock.module('electron', {
  exports: {
    BrowserWindow: class extends EventEmitter {
      webContents: ReturnType<typeof windowFixture>['contents'];
      constructor() {
        super();
        this.webContents = windowFixture('').contents;
      }
      isDestroyed() { return false; }
      loadURL(url: string) {
        this.webContents.mainFrame.url = url;
        assert.equal(getTrustedWindow({ sender: this.webContents, senderFrame: this.webContents.mainFrame } as any), this);
        for (const name of ['will-navigate', 'will-frame-navigate', 'will-redirect']) {
          let blocked = false;
          this.webContents.emit(name, {
            url: 'https://api.baander.app/',
            preventDefault: () => { blocked = true; },
          }, 'https://api.baander.app/');
          assert.equal(blocked, true, `${name} must be guarded before initial load`);
        }
        assert.deepEqual(this.webContents.openWindow({ url: 'https://baander.app/' }), { action: 'deny' });
        this.webContents.emit('did-finish-load');
        return Promise.resolve();
      }
    },
    app: { isPackaged: true, getAppPath: () => '/opt/baander/app', exit: () => assert.fail('Unexpected app exit') },
    dialog: { showMessageBoxSync: () => assert.fail('Unexpected load error') },
    session: { defaultSession: {
      setPermissionRequestHandler: (handler: Function) => { permissionHandlers.request = handler; },
      setPermissionCheckHandler: (handler: Function) => { permissionHandlers.check = handler; },
    } },
  },
});
mock.module('../src/shared/config-store.ts', { exports: { getThemeMoodSync: () => 'dark' } });
mock.module('../src/main/log.ts', { exports: { mainLog: { error: () => assert.fail('Unexpected load error') } } });
const { secureAppWindow, hardenSecurity } = await import('../src/main/security/harden.ts');
const files = {
  mainFilePath: '/opt/baander/app/index.html',
  configFilePath: '/opt/baander/app/config/index.html',
};

function windowFixture(url: string) {
  const contents = new EventEmitter() as EventEmitter & Record<string, any>;
  const window = new EventEmitter() as EventEmitter & Record<string, any>;
  contents.mainFrame = { url };
  contents.getURL = () => contents.mainFrame.url;
  contents.isDestroyed = () => false;
  contents.setWindowOpenHandler = (handler: Function) => { contents.openWindow = handler; };
  window.webContents = contents;
  window.isDestroyed = () => false;
  return { window: window as any, contents };
}

test('development trusts the exact UI origin and retains configuration and SPA routing', () => {
  const trust = createRendererTrust({ ...files, devServerUrl: 'http://localhost:5175/' });
  assert.equal(trust.mainUrl, 'http://localhost:5175/');
  assert.equal(trust.configUrl, 'http://localhost:5175/config/index.html');
  for (const url of [trust.mainUrl, trust.configUrl, 'http://localhost:5175/library/albums?sort=name#latest']) {
    assert.equal(trust.isTrusted(url), true, url);
  }
  for (const url of [
    'http://localhost:5176/', 'http://localhost.baander.app:5175/',
    'https://localhost:5175/', 'https://api.baander.app/',
    'file:///opt/baander/app/index.html', 'data:text/html,test', 'invalid',
    'http://user:password@localhost:5175/',
  ]) assert.equal(trust.isTrusted(url), false, url);
});

test('built UI trusts only its two resolved entrypoints with query and fragment state', () => {
  const trust = createRendererTrust(files);
  assert.equal(trust.mainUrl, pathToFileURL(files.mainFilePath).href);
  assert.equal(trust.configUrl, pathToFileURL(files.configFilePath).href);
  for (const url of [trust.mainUrl, `${trust.mainUrl}?theme=dark#/library`, `${trust.configUrl}?setup=1#server`]) {
    assert.equal(trust.isTrusted(url), true, url);
  }
  for (const url of [
    'file:///opt/baander/app/other.html', 'file:///opt/baander/app/config/../../index.html',
    'file:///opt/baander/app/%2e%2e/index.html', 'file:///opt/baander/app/config%2findex.html',
    'file://remote/opt/baander/app/index.html', 'https://api.baander.app/',
  ]) assert.equal(trust.isTrusted(url), false, url);
});

test('window guards block untrusted main-frame/frame navigation, redirects and popups', () => {
  const trust = createRendererTrust(files);
  const { window, contents } = windowFixture(trust.mainUrl);
  secureAppWindow(window, trust);
  for (const name of ['will-navigate', 'will-frame-navigate', 'will-redirect']) {
    for (const url of [trust.configUrl, 'https://api.baander.app/', 'file:///tmp/untrusted.html']) {
      let prevented = false;
      contents.emit(name, { url, preventDefault: () => { prevented = true; } }, url);
      assert.equal(prevented, !trust.isTrusted(url), `${name}: ${url}`);
    }
  }
  for (const url of [trust.mainUrl, 'https://baander.app/', 'http://localhost:5175/']) {
    assert.deepEqual(contents.openWindow({ url }), { action: 'deny' });
  }
});

test('ownership and current main-frame document are required and ownership expires on close', () => {
  const trust = createRendererTrust(files);
  const { window, contents } = windowFixture(trust.mainUrl);
  const event = { sender: contents, senderFrame: contents.mainFrame } as any;
  assert.equal(getTrustedWindow(event), null);
  secureAppWindow(window, trust);
  assert.equal(getTrustedWindow(event), window);
  assert.equal(getTrustedWindow({ ...event, senderFrame: { url: trust.mainUrl } }), null);
  assert.equal(getTrustedWindow({ ...event, senderFrame: null }), null);
  contents.mainFrame.detached = true;
  assert.equal(getTrustedWindow(event), null);
  contents.mainFrame.detached = false;
  contents.getURL = () => 'https://api.baander.app/';
  assert.equal(getTrustedWindow(event), null);
  contents.getURL = () => contents.mainFrame.url;
  contents.mainFrame.url = 'https://api.baander.app/';
  assert.equal(getTrustedWindow(event), null);
  contents.mainFrame.url = trust.mainUrl;
  window.isDestroyed = () => true;
  assert.equal(getTrustedWindow(event), null);
  window.isDestroyed = () => false;
  contents.isDestroyed = () => true;
  assert.equal(getTrustedWindow(event), null);
  contents.isDestroyed = () => false;
  window.emit('closed');
  assert.equal(getTrustedWindow(event), null);
});

test('permission requests and synchronous permission checks are denied', () => {
  hardenSecurity();
  let granted: boolean | undefined;
  permissionHandlers.request(null, 'media', (value: boolean) => { granted = value; });
  assert.equal(granted, false);
  assert.equal(permissionHandlers.check(null, 'media'), false);
});


test('both app window factories secure initial development and packaged document loads', async () => {
  const { createMainWindow } = await import('../src/main/windows/main-window.ts');
  const { createConfigWindow } = await import('../src/main/windows/config-window.ts');
  const previous = process.env.VITE_DEV_SERVER_URL;
  try {
    for (const devUrl of [undefined, 'http://localhost:5175/']) {
      if (devUrl) process.env.VITE_DEV_SERVER_URL = devUrl;
      else delete process.env.VITE_DEV_SERVER_URL;
      const main = createMainWindow();
      const config = createConfigWindow();
      assert.equal(main.webContents.getURL(), devUrl ?? 'file:///opt/baander/app/index.html');
      assert.equal(config.webContents.getURL(), devUrl ? `${devUrl}config/index.html` : 'file:///opt/baander/app/config/index.html');
      main.emit('closed');
      config.emit('closed');
    }
  } finally {
    if (previous === undefined) delete process.env.VITE_DEV_SERVER_URL;
    else process.env.VITE_DEV_SERVER_URL = previous;
  }
});
