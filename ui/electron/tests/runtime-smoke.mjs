import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, resolve, extname, sep } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const desktop = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const webRequire = createRequire(resolve(desktop, '../web/package.json'));
const { _electron: electron, expect } = webRequire('@playwright/test');
const scratch = await mkdtemp(resolve(tmpdir(), 'baander-desktop-smoke-'));
let display, application, api, ui;
try {
  display = spawn('Xvfb', ['-displayfd', '1', '-screen', '0', '1280x800x24', '-nolisten', 'tcp'], {
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  const displayNumber = await new Promise((resolveDisplay, reject) => {
    display.stdout.once('data', data => resolveDisplay(data.toString().trim()));
    display.once('error', reject);
    display.once('exit', code => reject(new Error(`Xvfb exited: ${code}`)));
  });
  const rendererRoot = resolve(desktop, 'dist/renderer');
  let apiCalls = 0;
  api = createServer((request, response) => {
    apiCalls++;
    response.setHeader('Content-Type', 'application/json');
    response.end(JSON.stringify({ smoke: true }));
  });
  ui = createServer(async (request, response) => {
    const url = new URL(request.url, 'http://desktop.baander.app');
    const target = resolve(rendererRoot, `.${!extname(url.pathname) ? '/index.html' : url.pathname}`);
    if (!target.startsWith(rendererRoot + sep)) { response.writeHead(403).end(); return; }
    try {
      const types = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.wasm': 'application/wasm' };
      response.setHeader('Content-Type', types[extname(target)] ?? 'application/octet-stream');
      response.end(await readFile(target));
    } catch { response.writeHead(404).end(); }
  });
  await Promise.all([new Promise(r => api.listen(0, '127.0.0.1', r)), new Promise(r => ui.listen(0, '127.0.0.1', r))]);
  const apiUrl = `http://127.0.0.1:${api.address().port}`;
  const uiUrl = `http://127.0.0.1:${ui.address().port}`;
  const entry = resolve(scratch, 'main.mjs');
  await writeFile(entry, `
import { app, dialog, session } from 'electron';
const fail = error => { console.error(error); app.exit(1); };
process.on('unhandledRejection', fail);
process.on('uncaughtException', fail);
dialog.showErrorBox = (title, content) => fail(new Error(title + ': ' + content));
dialog.showMessageBoxSync = (_window, options) => { console.error(options); return 0; };
app.setPath('userData', process.env.BAANDER_SMOKE_DATA);
app.setAsDefaultProtocolClient = () => false;
app.whenReady().then(() => session.defaultSession.webRequest.onBeforeRequest((details, callback) => {
  const url = new URL(details.url);
  const network = url.protocol === 'http:' || url.protocol === 'https:';
  callback({ cancel: network && !${JSON.stringify([apiUrl, uiUrl])}.includes(url.origin) });
}));
await import(${JSON.stringify(pathToFileURL(resolve(desktop, 'dist/main/index.js')).href)});
`);

  async function appPage(main) {
    let result;
    await expect.poll(() => {
      result = application.windows().find(page => {
        const url = page.url();
        return (url.startsWith(pathToFileURL(rendererRoot).href) || url.startsWith(uiUrl))
          && url.includes('/config/index.html') !== main;
      });
      return Boolean(result);
    }, { timeout: 15000 }).toBe(true).catch(error => { console.error('windows:', application.windows().map(page => page.url())); throw error; });
    return result;
  }

  async function exerciseDiscovery(page) {
    const advertisedUrl = `${apiUrl}/music/" data-injected="yes`;
    const announcement = {
      name: '<img src="x" onerror="window.discoveryInjected = true">',
      url: advertisedUrl,
      version: '<img src="x" onerror="window.discoveryInjected = true">',
      apiVersion: '1',
      timestamp: '2026-10-19T00:00:00Z',
      lastSeen: 1,
    };
    const send = server => application.evaluate(({ BrowserWindow }, value) => {
      const config = BrowserWindow.getAllWindows().find(window => window.webContents.getURL().includes('/config/index.html'));
      config.webContents.send('baander:discovery:server-found', value);
    }, server);
    await send(announcement);
    const row = page.locator('.server-item').first();
    await row.waitFor();
    assert.equal(await page.locator('#serversList img').count(), 0, 'discovery metadata must not create HTML elements');
    assert.equal(await page.locator('[data-injected]').count(), 0, 'advertised URL must not create attributes');
    assert.equal(await page.evaluate(() => window.discoveryInjected), undefined);
    assert.equal(await row.locator('.server-name').textContent(), announcement.name);
    assert.equal(await row.locator('.server-url').textContent(), advertisedUrl);
    assert.equal(await row.locator('.server-meta').textContent(), `v${announcement.version}`);
    await row.click();
    assert.equal(await page.locator('#serverUrl').inputValue(), advertisedUrl);
    assert.equal(await page.evaluate(() => window.BaanderElectron.config.getServerUrl()), '', 'selecting a row must not save configuration');

    await send({ ...announcement, name: 'Local Baander', url: `${apiUrl}/music`, version: '1.0' });
    await expect(page.locator('.server-item')).toHaveCount(2);
    await page.locator('.server-item').last().click();
    assert.equal(await page.locator('#serverUrl').inputValue(), `${apiUrl}/music`);
    await send(announcement);
    await expect(page.locator('.server-item')).toHaveCount(2);

    // Clear the list through its scan button without sending UDP broadcasts.
    await application.evaluate(({ ipcMain }) => {
      ipcMain.removeHandler('baander:discovery:start-scan');
      ipcMain.handle('baander:discovery:start-scan', () => ({ success: true }));
    });
    await page.locator('#discoverBtn').click();
    await expect(page.locator('.server-item')).toHaveCount(0);
    assert.equal(await page.locator('.no-servers').textContent(), 'No servers found on your network');
    await send({ ...announcement, name: 'Local Baander', url: apiUrl, version: '1.0' });
    await page.locator('.server-item').click();
    assert.equal(await page.locator('#serverUrl').inputValue(), apiUrl);
  }

  async function launch(mode, configured) {
    const userData = resolve(scratch, mode);
    const env = { ...process.env, DISPLAY: `:${displayNumber}`, BAANDER_SMOKE_DATA: userData };
    delete env.ELECTRON_RUN_AS_NODE;
    delete env.VITE_DEV_SERVER_URL;
    if (mode === 'development') env.VITE_DEV_SERVER_URL = uiUrl;
    application = await electron.launch({ executablePath: resolve(desktop, 'node_modules/electron/dist/electron'), args: ['--no-sandbox', entry], env, timeout: 30000 });
    const page = await appPage(configured);
    await page.waitForFunction(() => Boolean(window.BaanderElectron?.config), null, { timeout: 10000 });
    if (!configured) {
      assert.match(page.url(), /config\/index.html$/);
      await exerciseDiscovery(page);
      await page.evaluate(async server => {
        if (!await window.BaanderElectron.config.setServerUrl(server)) throw new Error('Save failed');
      }, apiUrl);
      await page.evaluate(() => window.BaanderElectron.config.finishSetup());
      const main = await appPage(true);
      await main.waitForFunction(() => Boolean(window.BaanderWindow));
      await expect.poll(() => page.isClosed()).toBe(true);
      return main;
    }
    assert.doesNotMatch(page.url(), /config\/index.html$/);
    return page;
  }

  async function exercise(page) {
    await page.locator('input[type=email]').waitFor();
    if (page.url().startsWith('file:')) assert.match(page.url(), /index\.html#\/login/);
    assert.equal(await page.evaluate(() => window.BaanderElectron.config.getServerUrl()), apiUrl);
    assert.equal(await page.evaluate(server => fetch(`${server}/smoke`).then(r => r.json()).then(r => r.smoke), apiUrl), true);
    const before = page.url();
    await page.evaluate(target => { window.location.href = target; }, `${apiUrl}/untrusted`);
    await page.waitForTimeout(100);
    assert.equal(page.url(), before, 'untrusted navigation must stay on app UI');
    const count = application.windows().length;
    await page.evaluate(target => { window.open(target); }, `${apiUrl}/popup`);
    await page.waitForTimeout(100);
    assert.equal(application.windows().length, count, 'renderer popup must be denied');
    await page.evaluate(() => window.BaanderWindow.maximize());
    assert.equal(typeof await page.evaluate(() => window.BaanderWindow.isMaximized()), 'boolean');
    await page.evaluate(() => window.BaanderWindow.maximize());
    assert.equal(typeof await page.evaluate(() => window.BaanderWindow.isMaximized()), 'boolean');
    await page.reload();
    await page.locator('input[type=email]').waitFor();
    assert.equal(await page.evaluate(() => window.BaanderElectron.config.getServerUrl()), apiUrl);
  }

  for (const mode of ['built', 'development']) {
    let page = await launch(mode, false);
    await exercise(page);
    await application.close();
    application = undefined;
    page = await launch(mode, true);
    await exercise(page);
    await application.close();
    application = undefined;
    console.log(`${mode}: literal discovery metadata/selection, setup handoff, configured startup, API access, reload, blocked navigation/popup and titlebar controls passed`);
  }
  assert.ok(apiCalls >= 4);
} finally {
  await Promise.allSettled([
    application?.close(),
    api && new Promise(resolveClose => api.close(resolveClose)),
    ui && new Promise(resolveClose => ui.close(resolveClose)),
  ]);
  display?.kill();
  await rm(scratch, { recursive: true, force: true });
}
