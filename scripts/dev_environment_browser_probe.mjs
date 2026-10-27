#!/usr/bin/env node
/** Real development UI authentication, catalog, audio and preview HMR qualification. */
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { dirname } from 'node:path'
import { chromium } from '../ui/web/node_modules/playwright-core/index.mjs'

const values = process.argv.slice(2)
const option = name => values[values.indexOf(name) + 1]
assert(values.includes('--url') && values.includes('--output'), 'Pass --url and --output')
const origin = new URL(option('--url')).origin
assert(origin.startsWith('https://'), 'Browser qualification requires the public HTTPS origin')
const output = option('--output')
const preview = values.includes('--preview')
const evidence = { passed: false, origin, preview, navigation: 'ui-links', tlsVerified: false,
  responses: [], sockets: [], errors: [] }
await mkdir(dirname(output), { recursive: true })
let browser
let page
try {
  browser = await chromium.launch({ headless: true })
  const context = await browser.newContext()
  await context.addInitScript(() => {
    window.__qualificationAudio = []
    window.__qualificationEvents = []
    const NativeAudio = window.Audio
    window.Audio = class extends NativeAudio {
      constructor(...args) {
        super(...args)
        window.__qualificationAudio.push(this)
        for (const event of ['playing', 'timeupdate', 'ended', 'error']) {
          this.addEventListener(event, () => {
            window.__qualificationEvents.push({ event, src: this.currentSrc, time: this.currentTime,
              duration: this.duration, ended: this.ended, error: this.error?.code ?? null })
          })
        }
      }
    }
  })
  page = await context.newPage()
  page.setDefaultTimeout(45000)
  page.on('pageerror', error => evidence.errors.push(error.message))
  page.on('response', response => {
    const url = new URL(response.url())
    if (url.origin === origin && (url.pathname.startsWith('/api/') || url.pathname === '/@vite/client')) {
      evidence.responses.push({ path: url.pathname, format: url.searchParams.get('format'), status: response.status() })
    }
  })
  page.on('websocket', socket => {
    const url = new URL(socket.url())
    const record = { origin: url.origin, path: url.pathname, connected: false }
    evidence.sockets.push(record)
    socket.on('framereceived', ({ payload }) => {
      try { if (JSON.parse(String(payload)).type === 'connected') record.connected = true } catch {}
    })
  })
  await page.goto(origin + '/login')
  evidence.tlsVerified = true
  await page.locator('#email').fill('admin@baander.test')
  await page.locator('#password').fill('admin')
  await page.locator('button[type=submit]').click()
  await page.waitForURL(url => url.pathname === '/music')
  evidence.authenticated = true
  await page.getByRole('link', { name: 'Settings', exact: true }).click()
  await page.getByRole('link', { name: 'Admin', exact: true }).click()
  await page.getByRole('link', { name: 'Library', exact: true }).click()
  await page.getByText('/qualification-media', { exact: true }).first().waitFor()
  evidence.libraryRendered = true
  await page.getByRole('link', { name: 'Back to app', exact: true }).click()
  await page.getByRole('link', { name: 'Songs', exact: true }).click()
  // The selected row replaces its hover Play button with an indicator. Its
  // title supports the same double-click playback action in either state.
  const title = page.locator('div[style*="translateY("]')
    .getByText('Workflow qualification tone', { exact: true })
  await title.waitFor()
  evidence.trackRendered = await title.innerText()
  evidence.playbackAction = 'double-click-song-row'
  await page.evaluate(() => { window.__qualificationEvents = [] })
  await title.dblclick()
  await page.waitForFunction(() => window.__qualificationEvents.some(event =>
    event.event === 'timeupdate' && event.time > 0 && event.src.includes('/api/stream/track')))
  await page.waitForFunction(() => window.__qualificationEvents.some(event =>
    event.event === 'ended' && event.ended && event.time > 0 && !event.error))
  evidence.original = await page.evaluate(() => window.__qualificationEvents.find(event => event.event === 'ended'))
  assert.equal(new URL(evidence.original.src).origin, origin)
  assert.equal(new URL(evidence.original.src).searchParams.get('format'), null,
    'Product playback must qualify the original stream')
  // Use the application's authenticated stream worker for a second real native decode.
  // This exercises the public streaming endpoint without reaching into application stores.
  await page.evaluate(source => {
    for (const audio of window.__qualificationAudio) audio.pause()
    const url = new URL(source)
    url.searchParams.set('format', 'opus')
    url.searchParams.set('bitrate', '96000')
    window.__qualificationTranscoded = new Audio(url.href)
    return window.__qualificationTranscoded.play()
  }, evidence.original.src)
  await page.waitForFunction(() => window.__qualificationEvents.some(event =>
    event.event === 'timeupdate' && event.time > 0 && new URL(event.src).searchParams.get('format') === 'opus'))
  await page.waitForFunction(() => window.__qualificationEvents.some(event =>
    event.event === 'ended' && event.ended && !event.error && new URL(event.src).searchParams.get('format') === 'opus'))
  evidence.transcoded = await page.evaluate(() => window.__qualificationEvents.find(event =>
    event.event === 'ended' && new URL(event.src).searchParams.get('format') === 'opus'))
  assert.equal(new URL(evidence.transcoded.src).origin, origin)
  for (const state of [evidence.original, evidence.transcoded]) {
    assert(Number.isFinite(state.duration) && Math.abs(state.duration - 8) < 0.1,
      'Browser playback must decode the complete eight-second qualification fixture')
    assert(Math.abs(state.time - state.duration) < 0.1, 'Native playback must reach the audio endpoint')
  }
  if (preview) {
    assert(evidence.responses.some(response => response.path === '/@vite/client' && response.status === 200),
      'The preview must load its Vite client through the public origin')
    assert(evidence.sockets.some(socket => socket.connected && socket.path === '/__vite_hmr'
      && socket.origin === origin.replace('https:', 'wss:')),
      'The preview must establish the actual Vite HMR connection through HTTPS')
    evidence.hmrConnected = true
  }
  await page.screenshot({ path: output.replace(/\.json$/, '') + '.png', fullPage: true })
  evidence.passed = true
} catch (error) {
  evidence.failure = error.stack ?? String(error)
  if (page) {
    evidence.failedUrl = page.url()
    try {
      await page.screenshot({ path: output.replace(/\.json$/, '') + '-failure.png', fullPage: true })
      evidence.failedPageText = (await page.locator('body').innerText()).slice(0, 4000)
    } catch {}
  }
  process.exitCode = 1
} finally {
  if (browser) await browser.close()
  await writeFile(output, JSON.stringify(evidence, null, 2) + '\n', { mode: 0o600 })
  console.log(JSON.stringify(evidence))
}
