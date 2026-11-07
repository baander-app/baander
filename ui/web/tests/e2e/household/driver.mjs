import { randomUUID } from 'node:crypto'
import { closeSync, existsSync, mkdirSync, openSync, readFileSync, renameSync, writeFileSync, writeSync } from 'node:fs'
import { dirname, isAbsolute, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { performance } from 'node:perf_hooks'
import { hostname, platform, release } from 'node:os'

export const thresholds = Object.freeze({
  sampleIntervalSeconds: 1,
  maximumSampleGapSeconds: 3,
  minimumAdvancingCoverage: 0.99,
  maximumContinuousStallSeconds: 1,
  minimumPositionAdvanceSeconds: 0.05,
  maximumProgressLossFloorSeconds: 3,
  maximumProgressLossFraction: 0.005,
  maximumPostReadyWaitingSeconds: 1,
  minimumMeasurementSeconds: 1800,
})

// Recovery and insufficient-memory runs play the original-overlap workload.
const scenarioDeliveries = Object.freeze({
  'original-overlap': 'original', 'fresh-copy-overlap': 'copy', 'mixed-overlap': 'original', recovery: 'original', 'insufficient-memory': 'original',
})

function requireValue(condition, message) {
  if (!condition) throw new Error(message)
}

export function validateConfiguration(value) {
  requireValue(value && typeof value === 'object' && !Array.isArray(value), 'Configuration must be an object')
  const url = new URL(value.baseURL)
  requireValue(['http:', 'https:'].includes(url.protocol) && !url.username && !url.password
    && !url.search && !url.hash && url.pathname === '/', 'baseURL must be an HTTP(S) origin')
  requireValue(Object.hasOwn(scenarioDeliveries, value.scenario), 'Unknown playback scenario')
  requireValue(Number.isInteger(value.durationSeconds) && value.durationSeconds >= thresholds.minimumMeasurementSeconds, 'durationSeconds must be at least 1800')
  const players = value.players ?? 2
  requireValue(players === 1 || players === 2, 'players must be 1 or 2')
  requireValue(Array.isArray(value.slots) && value.slots.length === players, 'Provide one playback slot per player')
  const slots = value.slots.map((slot, index) => {
    requireValue(slot && ['audio', 'video'].includes(slot.kind), `Slot ${index} requires audio or video`)
    requireValue(typeof slot.publicId === 'string' && /^[A-Za-z0-9_-]+$/.test(slot.publicId), `Slot ${index} requires a publicId`)
    requireValue(['original', 'copy'].includes(slot.expectedDelivery), `Slot ${index} requires original or copy delivery`)
    requireValue(slot.kind !== 'audio' || (typeof slot.songTitle === 'string' && slot.songTitle.length > 0), `Audio slot ${index} requires songTitle`)
    const pagePath = slot.pagePath ?? (slot.kind === 'video' ? `/movies/${slot.publicId}` : '/music/songs')
    requireValue(typeof pagePath === 'string' && pagePath.startsWith('/') && !pagePath.startsWith('//')
      && new URL(pagePath, url).origin === url.origin, `Slot ${index} pagePath must stay on baseURL`)
    const email = slot.email ?? value.email
    const password = slot.password ?? value.password
    requireValue(typeof email === 'string' && email.includes('@') && typeof password === 'string' && password.length > 0, `Slot ${index} requires login credentials`)
    return { ...slot, pagePath, email, password }
  })
  if (players === 2) requireValue(slots[0].email.toLowerCase() !== slots[1].email.toLowerCase(), 'Two distinct accounts are required to preserve independent playback ownership')
  requireValue(slots.every(slot => slot.expectedDelivery === scenarioDeliveries[value.scenario]), 'Slot deliveries disagree with scenario')
  if (value.scenario === 'mixed-overlap' && players === 2) requireValue(slots.map(slot => slot.kind).sort().join(',') === 'audio,video', 'Mixed playback requires one audio and one video slot')
  for (const key of ['outputPath', 'readyPath', 'stopPath']) {
    requireValue(typeof value[key] === 'string' && isAbsolute(value[key]), `${key} must be an absolute path`)
  }
  requireValue(new Set([value.outputPath, value.readyPath, value.stopPath, `${value.outputPath}.summary.json`]).size === 4, 'Control and evidence paths must differ')
  const startupTimeoutSeconds = value.startupTimeoutSeconds ?? 180
  requireValue(Number.isInteger(startupTimeoutSeconds) && startupTimeoutSeconds > 0 && startupTimeoutSeconds <= 900, 'startupTimeoutSeconds must be 1–900')
  if (value.chromiumExecutablePath !== undefined) requireValue(typeof value.chromiumExecutablePath === 'string' && isAbsolute(value.chromiumExecutablePath), 'chromiumExecutablePath must be absolute')
  return { ...value, baseURL: url.origin, players, slots, startupTimeoutSeconds }
}

export function summarizeSlot(samples, expectedDelivery, kind, durationSeconds) {
  let total = 0
  let advancing = 0
  let stall = 0
  let longestStall = 0
  let maximumSampleGap = 0
  let regressions = 0
  let nativeErrors = 0
  let endedSamples = 0
  let missingSamples = 0
  let wrongDeliverySamples = 0
  let unexpectedControlEvents = 0
  let totalPositionAdvance = 0
  let waitingIntervals = 0
  let longestPostReadyWaitingSeconds = 0
  const measurementEpoch = samples[0]?.epochMs ?? null
  let decodedFrameIntervals = 0
  let decodedFrameAdvancingIntervals = 0
  for (let index = 0; index < samples.length; index++) {
    const sample = samples[index]
    if (!sample.media) missingSamples++
    if (sample.media?.errorCode) nativeErrors++
    if (sample.media?.ended) endedSamples++
    if (sample.delivery !== expectedDelivery) wrongDeliverySamples++
    if (measurementEpoch !== null) {
      for (const event of sample.events ?? []) {
        if (event.epochMs >= measurementEpoch && ['pause', 'seeking', 'seeked', 'emptied', 'ended'].includes(event.event)) unexpectedControlEvents++
        for (const interval of event.waitingIntervals ?? []) {
          const seconds = Math.max(0, interval.endEpochMs - Math.max(interval.startEpochMs, measurementEpoch)) / 1000
          if (seconds > 0) waitingIntervals++
          longestPostReadyWaitingSeconds = Math.max(longestPostReadyWaitingSeconds, seconds)
        }
      }
      for (const field of ['waitingSinceEpochMs', 'stalledSinceEpochMs']) {
        if (typeof sample.media?.[field] === 'number') {
          longestPostReadyWaitingSeconds = Math.max(longestPostReadyWaitingSeconds,
            Math.max(0, sample.epochMs - Math.max(sample.media[field], measurementEpoch)) / 1000)
        }
      }
    }
    if (!index) continue
    const previous = samples[index - 1]
    const seconds = (sample.monotonicMs - previous.monotonicMs) / 1000
    if (!(seconds > 0)) continue
    total += seconds
    maximumSampleGap = Math.max(maximumSampleGap, seconds)
    const change = sample.presentationPosition !== null && previous.presentationPosition !== null
      ? sample.presentationPosition - previous.presentationPosition : null
    if (change !== null && change < -0.25) regressions++
    if (change !== null && change > 0) totalPositionAdvance += change
    let framesAdvanced = true
    if (kind === 'video') {
      const before = previous.media?.decodedFrames
      const after = sample.media?.decodedFrames
      if (typeof before === 'number' && typeof after === 'number') {
        decodedFrameIntervals++
        framesAdvanced = after > before
        if (framesAdvanced) decodedFrameAdvancingIntervals++
      } else framesAdvanced = false
    }
    const progresses = change !== null && change >= thresholds.minimumPositionAdvanceSeconds
      && sample.media && !sample.media.paused && !sample.media.ended && !sample.media.errorCode
      && sample.media.readyState >= 2 && framesAdvanced && seconds <= thresholds.maximumSampleGapSeconds
    if (progresses) {
      advancing += seconds
      stall = 0
    } else {
      stall += seconds
      longestStall = Math.max(longestStall, stall)
    }
  }
  const advancingCoverage = total > 0 ? advancing / total : 0
  const failures = []
  if (total < durationSeconds) failures.push('incomplete-measurement-window')
  if (maximumSampleGap > thresholds.maximumSampleGapSeconds) failures.push('sampler-gap')
  const progressLossSeconds = Math.max(0, total - totalPositionAdvance)
  const progressLossToleranceSeconds = Math.max(thresholds.maximumProgressLossFloorSeconds, total * thresholds.maximumProgressLossFraction)
  if (progressLossSeconds > progressLossToleranceSeconds) failures.push('playback-progress-loss')
  if (longestPostReadyWaitingSeconds >= thresholds.maximumPostReadyWaitingSeconds) failures.push('post-ready-waiting-or-stalled')
  if (advancingCoverage < thresholds.minimumAdvancingCoverage) failures.push('insufficient-advancing-coverage')
  if (longestStall >= thresholds.maximumContinuousStallSeconds) failures.push('continuous-stall')
  if (regressions) failures.push('position-regression')
  if (unexpectedControlEvents) failures.push('unexpected-pause-seek-or-source-reset')
  if (nativeErrors) failures.push('native-media-error')
  if (endedSamples) failures.push('fixture-ended-before-measurement-completed')
  if (missingSamples) failures.push('missing-native-media')
  if (wrongDeliverySamples) failures.push('unexpected-delivery')
  if (kind === 'video' && !decodedFrameIntervals) failures.push('missing-decoded-frame-evidence')
  return { passed: failures.length === 0, failures, measuredSeconds: total, advancingSeconds: advancing,
    advancingCoverage, progressLossSeconds, progressLossToleranceSeconds, waitingIntervals, longestPostReadyWaitingSeconds, totalPositionAdvanceSeconds: totalPositionAdvance, playbackRatio: total > 0 ? totalPositionAdvance / total : 0, longestStallSeconds: longestStall, maximumSampleGapSeconds: maximumSampleGap,
    positionRegressions: regressions, unexpectedControlEvents, nativeErrors, endedSamples, missingSamples, wrongDeliverySamples,
    decodedFrameIntervals, decodedFrameAdvancingIntervals, samples: samples.length }
}

export function playersAdvancing(samples, previous, slots) {
  return previous !== null && previous.length === slots.length && samples.length === slots.length && samples.every((sample, index) =>
    sample.delivery === slots[index].expectedDelivery
    && sample.media && !sample.media.paused && !sample.media.ended && !sample.media.errorCode && sample.media.readyState >= 2
    && previous[index]?.presentationPosition !== null
    && sample.presentationPosition > previous[index].presentationPosition + thresholds.minimumPositionAdvanceSeconds
    && (slots[index].kind !== 'video' || (typeof sample.media.decodedFrames === 'number'
      && sample.media.decodedFrames > (previous[index].media?.decodedFrames ?? Infinity))))
}

function atomicJson(path, value) {
  mkdirSync(dirname(path), { recursive: true, mode: 0o700 })
  const temporary = `${path}.${randomUUID()}.tmp`
  writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, { mode: 0o600, flag: 'wx' })
  renameSync(temporary, path)
}

// This observes native elements, including detached Audio instances, without changing playback decisions.
function installNativeObserver() {
  const entries = new Map()
  const events = []
  let sequence = 0
  const describe = element => {
    const quality = element.getVideoPlaybackQuality?.()
    return { elementId: entries.get(element)?.id, kind: element instanceof HTMLVideoElement ? 'video' : 'audio',
      currentTime: element.currentTime, paused: element.paused, readyState: element.readyState,
      networkState: element.networkState, seeking: element.seeking, ended: element.ended,
      duration: Number.isFinite(element.duration) ? element.duration : null,
      decodedFrames: quality?.totalVideoFrames ?? element.webkitDecodedFrameCount ?? null,
      droppedFrames: quality?.droppedVideoFrames ?? element.webkitDroppedFrameCount ?? null,
      errorCode: element.error?.code ?? null,
      sourcePath: element.currentSrc ? new URL(element.currentSrc, location.href).pathname : null,
      waitingSinceEpochMs: entries.get(element)?.waitingSince ?? null,
      stalledSinceEpochMs: entries.get(element)?.stalledSince ?? null }
  }
  const attach = element => {
    if (entries.has(element)) return
    const entry = { id: ++sequence, waitingSince: null, stalledSince: null, sampledPosition: null }
    entries.set(element, entry)
    for (const name of ['playing', 'waiting', 'stalled', 'pause', 'ended', 'error', 'seeking', 'seeked', 'emptied']) {
      element.addEventListener(name, () => {
        const now = Date.now()
        if (name === 'waiting' && entry.waitingSince === null) entry.waitingSince = now
        if (name === 'stalled' && entry.stalledSince === null) entry.stalledSince = now
        const waitingIntervals = []
        if (name === 'playing') {
          for (const field of ['waitingSince', 'stalledSince']) {
            if (entry[field] !== null) waitingIntervals.push({ type: field, startEpochMs: entry[field], endEpochMs: now, seconds: (now - entry[field]) / 1000 })
            entry[field] = null
          }
        }
        events.push({ event: name, epochMs: now, pageMonotonicMs: performance.now(), waitingIntervals, ...describe(element) })
        if (events.length > 1000) events.shift()
      })
    }
  }
  const originalPlay = HTMLMediaElement.prototype.play
  HTMLMediaElement.prototype.play = function (...args) {
    attach(this)
    return originalPlay.apply(this, args)
  }
  window.__householdNativeObservation = kind => {
    document.querySelectorAll('video,audio').forEach(attach)
    const matching = [...entries.keys()].filter(element => (element instanceof HTMLVideoElement ? 'video' : 'audio') === kind)
    const selected = matching.findLast(element => !element.paused && element.currentSrc) ?? matching.at(-1)
    if (selected) {
      const entry = entries.get(selected)
      if (entry.stalledSince !== null && entry.sampledPosition !== null && selected.currentTime > entry.sampledPosition + 0.05) {
        const now = Date.now()
        events.push({ event: 'stalled-progress-resumed', epochMs: now, pageMonotonicMs: performance.now(),
          waitingIntervals: [{ type: 'stalledSince', startEpochMs: entry.stalledSince, endEpochMs: now, seconds: (now - entry.stalledSince) / 1000 }] })
        entry.stalledSince = null
      }
      entry.sampledPosition = selected.currentTime
    }
    return { media: selected ? describe(selected) : null, events: events.splice(0), epochMs: Date.now(),
      pageMonotonicMs: performance.now(), pageTimeOriginMs: performance.timeOrigin }
  }
}

async function login(page, slot) {
  await page.goto('/login', { waitUntil: 'domcontentloaded' })
  await page.getByLabel(/email/i).fill(slot.email)
  await page.getByLabel(/password/i).fill(slot.password)
  let nonceSeen = false
  const responsePromise = page.waitForResponse(async response => {
    if (new URL(response.url()).pathname !== '/api/auth/login' || response.request().method() !== 'POST') return false
    if (!nonceSeen && response.status() === 400 && response.headers()['dpop-nonce']) {
      const body = await response.json().catch(() => ({}))
      if (body.error === 'use_dpop_nonce') { nonceSeen = true; return false }
    }
    return true
  })
  const [response] = await Promise.all([responsePromise, page.getByRole('button', { name: /log in/i }).click()])
  requireValue(response.ok(), `Real UI login failed with HTTP ${response.status()}`)
  await page.waitForURL(url => url.pathname === '/music')
  // The application stores the new session asynchronously; a full navigation before that write
  // completes starts without a session and returns to the login page.
  await page.waitForFunction(async () => {
    if (!(await indexedDB.databases()).some(database => database.name === 'baander-auth')) return false
    const database = await new Promise((resolve, reject) => {
      const request = indexedDB.open('baander-auth')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    try {
      if (!database.objectStoreNames.contains('auth')) return false
      const record = await new Promise((resolve, reject) => {
        const request = database.transaction('auth', 'readonly').objectStore('auth').get('current')
        request.onsuccess = () => resolve(request.result)
        request.onerror = () => reject(request.error)
      })
      return record != null && Number.isInteger(record.epoch) && record.cleared !== true
    } finally {
      database.close()
    }
  }, undefined, { polling: 100 })
}

function observeResponses(page, slot, state, record, pending) {
  page.on('response', response => {
    const path = new URL(response.url()).pathname
    const relevant = path === '/api/stream/delivery' || path === '/api/stream/sign'
    if (!relevant) {
      if (response.status() >= 400 && /^\/api\/(stream|transcode|session)\//.test(path)) record({ type: 'http-error', slot: state.index, path, status: response.status() })
      return
    }
    const read = (async () => {
      let request
      try { request = response.request().postDataJSON() } catch { request = null }
      const body = await response.json()
      const result = body?.data ?? body
      if (path === '/api/stream/delivery') {
        if (request?.id !== slot.publicId) return
        state.delivery = result.kind
        state.requestId = result.requestId ?? null
        state.jobId = result.jobId ?? null
        state.decisions.push({ kind: result.kind, jobId: state.jobId, requestId: state.requestId })
      } else {
        if (request?.path !== `/api/transcode/${slot.publicId}/master.m3u8` && result.jobId !== state.jobId) return
        if (typeof result.startTime === 'number') state.positionOffset = result.startTime
        if (typeof result.jobId === 'string') state.jobId = result.jobId
      }
      record({ type: 'delivery', slot: state.index, path, status: response.status(), delivery: state.delivery,
        requestId: state.requestId, jobId: state.jobId, positionOffset: state.positionOffset,
        generation: result.generation ?? null, ready: result.ready ?? null })
    })().catch(() => record({ type: 'observation-error', slot: state.index, path }))
    pending.add(read)
    read.then(() => pending.delete(read))
  })
  page.on('requestfailed', request => {
    const path = new URL(request.url()).pathname
    if (/^\/api\/(stream|transcode|session)\//.test(path)) record({ type: 'request-failed', slot: state.index, path,
      error: request.failure()?.errorText?.match(/^net::[A-Z_]+$/)?.[0] ?? 'transport-failure' })
  })
  page.on('pageerror', error => {
    state.pageErrors++
    record({ type: 'page-error', slot: state.index, name: error.name })
  })
}

async function snapshot(page, state) {
  const native = await page.evaluate(kind => window.__householdNativeObservation(kind), state.kind)
  return { ...native, monotonicMs: performance.now(), delivery: state.delivery, jobId: state.jobId,
    requestId: state.requestId, positionOffset: state.positionOffset,
    presentationPosition: native.media ? native.media.currentTime + state.positionOffset : null }
}

export async function run(configuration) {
  const config = validateConfiguration(configuration)
  for (const path of [config.outputPath, config.readyPath, config.stopPath, `${config.outputPath}.summary.json`]) requireValue(!existsSync(path), 'Evidence and control paths must be fresh')
  mkdirSync(dirname(config.outputPath), { recursive: true, mode: 0o700 })
  const artifacts = `${config.outputPath}.artifacts`
  mkdirSync(artifacts, { mode: 0o700 })
  const descriptor = openSync(config.outputPath, 'wx', 0o600)
  const started = performance.now()
  const record = entry => writeSync(descriptor, `${JSON.stringify({ epochMs: Date.now(), hostMonotonicMs: performance.now(), ...entry })}\n`)
  const clients = []
  const pending = new Set()
  let browser
  let measurementStart = null
  let stopRequested = false
  let failure = null
  let interrupted = false
  const interrupt = () => { interrupted = true }
  process.on('SIGTERM', interrupt)
  process.on('SIGINT', interrupt)
  let browserInspector
  const browserUsage = async () => {
    if (!browserInspector) return { available: false }
    try {
      const { processInfo } = await browserInspector.send('SystemInfo.getProcessInfo')
      return { available: true, processes: processInfo.map(item => {
        let rssBytes = null
        try {
          const status = readFileSync(`/proc/${item.id}/status`, 'utf8')
          const match = /^VmRSS:\s+(\d+) kB$/m.exec(status)
          rssBytes = match ? Number(match[1]) * 1024 : null
        } catch { /* Platforms without procfs still report browser CPU time. */ }
        return { pid: item.id, type: item.type, cpuSeconds: item.cpuTime, rssBytes }
      }) }
    } catch { return { available: false } }
  }
  let previousCpu = process.cpuUsage()
  let previousUsageAt = performance.now()
  const usage = () => {
    const now = performance.now()
    const delta = process.cpuUsage(previousCpu)
    previousCpu = process.cpuUsage()
    const result = { driverPid: process.pid, rssBytes: process.memoryUsage().rss,
      cpuUserMicroseconds: delta.user, cpuSystemMicroseconds: delta.system, intervalMs: now - previousUsageAt,
      cumulativeResourceUsage: process.resourceUsage() }
    previousUsageAt = now
    return result
  }
  try {
    const { chromium } = await import('playwright-core')
    browser = await chromium.launch({ headless: true, executablePath: config.chromiumExecutablePath,
      args: ['--autoplay-policy=no-user-gesture-required', '--disable-background-timer-throttling', '--disable-renderer-backgrounding', '--disable-backgrounding-occluded-windows'] })
    browserInspector = await browser.newBrowserCDPSession()
    record({ type: 'run-start', slots: config.slots.map(({ kind, publicId, pagePath, expectedDelivery, librarySlug }) => ({ kind, publicId, pagePath, expectedDelivery, librarySlug })), scenario: config.scenario, baseURL: config.baseURL, durationSeconds: config.durationSeconds,
      thresholds, observer: { location: 'external-host-outside-constrained-vm', hostname: hostname(), platform: platform(), release: release(), nodeVersion: process.version, browserVersion: browser.version() } })
    for (const [index, slot] of config.slots.entries()) {
      const context = await browser.newContext({ baseURL: config.baseURL, ignoreHTTPSErrors: true })
      await context.addInitScript(name => localStorage.setItem('baander-device-name', name), `household-${index}-${randomUUID()}`)
      await context.addInitScript(installNativeObserver)
      const page = await context.newPage()
      page.setDefaultTimeout(config.startupTimeoutSeconds * 1000)
      const state = { index, kind: slot.kind, delivery: null, requestId: null, jobId: null, positionOffset: 0, pageErrors: 0, decisions: [], samples: [] }
      clients.push({ context, page, slot, state })
      observeResponses(page, slot, state, record, pending)
      await login(page, slot)
      await page.goto(slot.pagePath, { waitUntil: 'domcontentloaded' })
      if (slot.kind === 'video') await page.locator('main').getByRole('button', { name: 'Play', exact: true }).click()
      else await page.locator('main').getByText(slot.songTitle, { exact: true }).dblclick()
    }
    const startupDeadline = performance.now() + config.startupTimeoutSeconds * 1000
    let previous = null
    while (performance.now() < startupDeadline && !interrupted && !existsSync(config.stopPath)) {
      const samples = await Promise.all(clients.map(client => snapshot(client.page, client.state)))
      samples.forEach((sample, index) => record({ type: 'startup-sample', slot: index, ...sample }))
      const ready = playersAdvancing(samples, previous, config.slots)
      if (ready) break
      previous = samples
      await new Promise(resolve => setTimeout(resolve, 1000))
    }
    if (interrupted || existsSync(config.stopPath)) stopRequested = true
    const initial = await Promise.all(clients.map(client => snapshot(client.page, client.state)))
    requireValue(!interrupted && !existsSync(config.stopPath) && playersAdvancing(initial, previous, config.slots), `${config.slots.length === 1 ? 'The real player' : 'Both real players'} did not reach advancing playback before startup deadline`)
    measurementStart = { epochMs: Date.now(), monotonicMs: performance.now() }
    initial.forEach((sample, index) => { clients[index].state.samples.push(sample); record({ type: 'sample', slot: index, ...sample }) })
    atomicJson(config.readyPath, { version: 1, measurementStart, scenario: config.scenario, players: config.players, slots: clients.map(({ state, slot }) => ({ index: state.index, kind: state.kind, publicId: slot.publicId, delivery: state.delivery, jobId: state.jobId })) })
    while (performance.now() - measurementStart.monotonicMs < config.durationSeconds * 1000) {
      if (interrupted || existsSync(config.stopPath)) { stopRequested = true; break }
      const target = measurementStart.monotonicMs + clients[0].state.samples.length * 1000
      await new Promise(resolve => setTimeout(resolve, Math.max(0, target - performance.now())))
      const samples = await Promise.all(clients.map(client => snapshot(client.page, client.state)))
      samples.forEach((sample, index) => { clients[index].state.samples.push(sample); record({ type: 'sample', slot: index, ...sample }) })
      record({ type: 'observer-process', usage: usage(), chromium: await browserUsage() })
    }
  } catch (error) {
    failure = { name: error.name, message: String(error.message) }
    for (const slot of config.slots) failure.message = failure.message.replaceAll(slot.password, '[redacted]').replaceAll(slot.email, '[account]')
    record({ type: 'run-failure', failure })
  } finally {
    await Promise.all([...pending])
    const slots = clients.map(({ slot, state }) => ({ index: state.index, kind: slot.kind, publicId: slot.publicId,
      expectedDelivery: slot.expectedDelivery, observedDecisions: state.decisions, pageErrors: state.pageErrors,
      ...summarizeSlot(state.samples, slot.expectedDelivery, slot.kind, config.durationSeconds) }))
    const passed = !failure && !stopRequested && slots.length === config.slots.length && slots.every(slot => slot.passed && slot.pageErrors === 0)
    for (const { page, state } of clients) {
      if (!passed) await page.screenshot({ path: `${artifacts}/slot-${state.index}.png`, fullPage: true }).catch(() => {})
    }
    const finalBrowserUsage = await browserUsage()
    for (const { page } of clients) { page.removeAllListeners('response'); page.removeAllListeners('requestfailed'); page.removeAllListeners('pageerror') }
    await Promise.all([...pending])
    for (const { context } of clients) await context.close().catch(() => {})
    if (browser) await browser.close().catch(() => {})
    const summary = { version: 1, passed, failure, stopRequested, interrupted, scenario: config.scenario, players: config.players, thresholds,
      durationSeconds: config.durationSeconds, measurementStart, finishedEpochMs: Date.now(),
      driverElapsedSeconds: (performance.now() - started) / 1000, slots,
      observer: { location: 'external-host-outside-constrained-vm', usage: usage(), chromium: finalBrowserUsage,
        accountingLimit: 'Chromium RSS comes from procfs when available; summed process RSS may double-count shared pages. This observer host is outside the constrained deployment.' } }
    record({ type: 'summary', ...summary })
    atomicJson(`${config.outputPath}.summary.json`, summary)
    closeSync(descriptor)
    process.off('SIGTERM', interrupt)
    process.off('SIGINT', interrupt)
    return summary
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  try {
    requireValue(process.argv.length === 3, 'Usage: node driver.mjs /absolute/private-configuration.json')
    const configuration = JSON.parse(readFileSync(process.argv[2], 'utf8'))
    const result = await run(configuration)
    process.stdout.write(`${JSON.stringify({ passed: result.passed, summaryPath: `${configuration.outputPath}.summary.json` })}\n`)
    process.exitCode = result.passed ? 0 : 1
  } catch (error) {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  }
}
