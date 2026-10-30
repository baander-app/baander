import { readFile, writeFile } from 'node:fs/promises'
import { randomUUID } from 'node:crypto'
import { setTimeout as delay } from 'node:timers/promises'
import { test, expect, type BrowserContext } from '@playwright/test'
import { FixtureClient, LoginAdmission, login, authenticatedGet, type FixtureManifest, type FixtureReceipt } from './capacity-fixtures'
import { CapacityObserver, percentile95 } from './capacity-observers'

interface Worker {
  worker_id: number
  process: { pid: number }
  pools: { active: number; free: number; limit: number }[]
  memory: { usage: number; real: number }
  coroutines: { coroutine_num: number }
  live_updates: { active_subscriptions: number; pending_send_count: number; [key: string]: unknown }
}
interface Stats { data: { workers: Worker[]; missing_workers: number[]; worker_errors: unknown[] } }
interface DiagnosticSample { epochMs: number; phase: string; stats: Stats }

test.use({ launchOptions: { args: ['--mute-audio'] } })

test('32 real principals converge after ordinary commits and a 10,000-capture burst', async ({ browser, baseURL }, testInfo) => {
  const ciRuntime = Boolean(process.env.BAANDER_LIVE_CAPACITY_MANIFEST)
  test.setTimeout(ciRuntime ? 1_800_000 : 900_000)
  const loginAdmission = ciRuntime ? new LoginAdmission(Date.now() + 1_800_000) : undefined
  const identityPath = process.env.BAANDER_LIVE_CAPACITY_IDENTITY
  expect(baseURL).toBeTruthy()
  const fixture = FixtureClient.start()
  const contexts: BrowserContext[] = []
  const participantContexts: BrowserContext[] = []
  let observerFixture: FixtureClient | undefined
  const observers: CapacityObserver[] = []
  const diagnostics: DiagnosticSample[] = []
  const browserProcesses: unknown[] = []
  const browserSession = await browser.newBrowserCDPSession()
  const latencies: { round: number; userId: string; dispatchUpperBoundMs: number; producerCommitEndToHostReceiptMs: number; producerCommitStartToHostReceiptMs: number }[] = []
  const evidence: Record<string, unknown> = {
    browser: browser.version(), baseURL, node: process.version, startEpochMs: Date.now(),
    loginSecurity: ciRuntime
      ? 'Real production login limiter retained. Shared setup admission counts actual browser nonce/login POSTs plus PHP observer attempts conservatively, reserving two slots before each browser login under 20 attempts per 300 seconds.'
      : 'Existing preview dev RateLimitListener bypasses auth-endpoint throttles by environment; harness does not alter security configuration.',
    memoryAcceptance: 'Memory/coroutine raw trend analysis remains pending until actual results; no invented tolerance is applied.',
    environment: ciRuntime ? 'Managed disposable CI image, production environment and built frontend; verified identity attached.'
      : 'Preview dev container, APP_DEBUG and Vite; production-mode capacity remains a separate qualification.',
    timing: 'Host command dispatch to host receipt of next-animation-frame visible DOM is the conservative latency upper bound. Producer commit brackets additionally reported; wall clocks are not assumed synchronized. Burst upper bound anchors elapsed producer commit duration at host dispatch, which precedes first commit.',
    overhead: ciRuntime
      ? '32 complete SPAs plus a separate real password/DPoP PHP HTTP observer. No admin SPA. JSONL diagnostics and Playwright bindings add observer cost; snapshots cannot see all short peaks.'
      : '32 complete SPAs plus one admin observer context. Observer uses real authenticated debug HTTP; polling and Playwright bindings add measured observer cost. Browser memory API may be unavailable; snapshots cannot see all short peaks.',
  }
  let sampler: Promise<void> | undefined
  let stopSampling = false
  let samplingFailure: unknown
  let measuredStartEpochMs = 0
  let cleaned = false
  let cleanupFailure: unknown
  let manifest: FixtureManifest | undefined
  let observerHTTP: { get: <T>(path: string) => Promise<T> } | undefined
  const sample = async (phase: string) => {
    if (!observerHTTP) throw new Error('Observer not initialized')
    browserProcesses.push({ epochMs: Date.now(), phase, nodeMemory: process.memoryUsage(),
      nodeCpu: process.cpuUsage(), ...await browserSession.send('SystemInfo.getProcessInfo') })
    await Promise.all(observers.filter((observer) => !observer.page.isClosed()).map((observer) => observer.sample()))
    const stats = await observerHTTP.get<Stats>('/api/debug/stats')
    diagnostics.push({ epochMs: Date.now(), phase, stats })
    expect(stats.data.missing_workers, 'missing diagnostic workers').toEqual([])
    expect(stats.data.worker_errors, 'worker diagnostic failures').toEqual([])
    expect(stats.data.workers.length).toBeGreaterThan(0)
    for (const worker of stats.data.workers) {
      expect(worker.process.pid).toBeGreaterThan(0)
      for (const pool of worker.pools) expect(pool.active, 'pool exhausted').toBeLessThan(pool.limit)
    }
    return stats
  }
  const quiet = async (phase: string) => {
    for (let index = 0; index < 10; index++) {
      await sample(phase)
      await delay(250)
    }
  }
  try {
    // CI qualification requires explicit image identity; preview can record its absence.
    if (ciRuntime) expect(identityPath, 'CI runtime identity must be configured').toBeTruthy()
    if (identityPath) {
      const identity = JSON.parse(await readFile(identityPath, 'utf8')) as Record<string, unknown>
      evidence.runtimeIdentity = identity
      if (ciRuntime) {
        expect(identity.environment).toBe('prod')
        expect(identity.debug).toBe('0')
        expect(identity.frontend).toBe('built')
      }
    } else {
      evidence.runtimeIdentity = {
        available: false,
        qualification: 'Preview-only observations; no native-runtime identity artifact was supplied.',
      }
    }
    const created = await fixture.request<FixtureManifest>({ op: 'create', run: `capacity-${randomUUID()}` })
    manifest = created.receipt
    expect(manifest.users).toHaveLength(32)
    expect(new Set(manifest.users.map((user) => user.id)).size).toBe(32)
    expect(manifest.users.every((user) => !user.admin)).toBe(true)
    expect(manifest.observer.admin).toBe(true)
    if (ciRuntime) {
      observerFixture = FixtureClient.start()
      while (true) {
        await loginAdmission!.admit()
        const observerLogin = await observerFixture.request<{
          authenticated: boolean; status: number; loginAttempts: { startEpochMs: number; endEpochMs: number; status: number }[]; retryAfterSeconds?: number
        }>({ op: 'observer-login', run: fixture.run })
        observerLogin.receipt.loginAttempts.forEach(() => loginAdmission!.recordAttempt())
        if (observerLogin.receipt.authenticated) break
        expect(observerLogin.receipt.status).toBe(429)
        await loginAdmission!.wait(Math.max(1, Number(observerLogin.receipt.retryAfterSeconds)) * 1000, 'observer server Retry-After')
      }
      observerHTTP = { get: async <T>(path: string) => (await observerFixture!.request<T>({ op: 'observer-get', path })).receipt }
    } else {
      const adminContext = await browser.newContext({ baseURL, ignoreHTTPSErrors: true })
      contexts.push(adminContext)
      const observerPage = await adminContext.newPage()
      await login(observerPage, manifest.observer)
      observerHTTP = { get: <T>(path: string) => authenticatedGet<T>(observerPage, path) }
    }
    for (let index = 0; index < 40; index++) {
      await observerHTTP.get('/api/notifications/unread-count')
      await observerHTTP.get('/api/notifications/')
    }
    for (let index = 0; index < 3; index++) await sample('observer-warm')
    await quiet('quiet-before')
    const quietSubscriptionCount = diagnostics.findLast((entry) => entry.phase === 'quiet-before')!
      .stats.data.workers.reduce((sum, worker) => sum + worker.live_updates.active_subscriptions, 0)
    for (const user of manifest.users) {
      const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true })
      contexts.push(context)
      participantContexts.push(context)
      const page = await context.newPage()
      const observer = new CapacityObserver(page, user.id)
      observers.push(observer)
      await login(page, user, loginAdmission)
      await observer.install()
      await page.getByRole('button', { name: /\d+ unread notifications/ }).click()
      await expect(page.getByText('No notifications', { exact: true })).toBeVisible()
      await expect.poll(() => observer.ready, { timeout: 10_000 }).toBe(true)
      expect(await page.evaluate(() => document.visibilityState)).toBe('visible')
      await observer.sample()
    }
    await quiet('established-steady')
    expect(observers.every((observer) => observer.ready)).toBe(true)
    const steadySubscriptions = diagnostics.findLast((entry) => entry.phase === 'established-steady')!
      .stats.data.workers.reduce((sum, worker) => sum + worker.live_updates.active_subscriptions, 0)
    expect(steadySubscriptions - quietSubscriptionCount).toBe(32)
    measuredStartEpochMs = Date.now()
    for (const observer of observers) observer.startMeasurement()
    sampler = (async () => {
      while (!stopSampling) {
        await sample('workload')
        await delay(1000)
      }
    })().catch((error: unknown) => {
      samplingFailure = error
      evidence.samplingError = String(error)
      stopSampling = true
    })
    for (let round = 1; round <= 10; round++) {
      const dispatch = Date.now()
      const response = await fixture.request({ op: 'ordinary', sequence: round })
      expect(response.receipt.capturedRows).toBe(32)
      const users = response.receipt.users!
      expect(users).toHaveLength(32)
      await Promise.all(users.map(async (user) => {
        const observer = observers.find((entry) => entry.userId === user.id)!
        const rendered = await observer.waitForCount(user.count, user.title)
        latencies.push({ round, userId: user.id, dispatchUpperBoundMs: rendered.hostEpochMs - dispatch,
          producerCommitEndToHostReceiptMs: rendered.hostEpochMs - Number(response.receipt.commitEndEpochMs),
          producerCommitStartToHostReceiptMs: rendered.hostEpochMs - Number(response.receipt.commitStartEpochMs) })
      }))
    }
    expect(latencies).toHaveLength(320)
    const ordinaryP95 = percentile95(latencies.map((entry) => entry.dispatchUpperBoundMs))
    const worstPrincipalP95 = Math.max(...manifest.users.map((user) => percentile95(latencies
      .filter((entry) => entry.userId === user.id).map((entry) => entry.dispatchUpperBoundMs))))
    evidence.ordinary = { p95Ms: ordinaryP95, worstPrincipalP95Ms: worstPrincipalP95,
      maxMs: Math.max(...latencies.map((entry) => entry.dispatchUpperBoundMs)), latencies }
    const burstDispatchEpochMs = Date.now()
    const dispatch = burstDispatchEpochMs
    const burst = await fixture.request({ op: 'burst', count: 10_000, durationMs: 10_000 })
    evidence.burst = burst.receipt
    const producerDurationMs = Number(burst.receipt.commitEndEpochMs) - Number(burst.receipt.producerStartedEpochMs)
    evidence.burstPacing = { producerDurationMs, achievedRowsPerSecond: 10_000 / (producerDurationMs / 1000),
      formula: 'final commitEndEpochMs - producerStartedEpochMs; excludes postcommit count/sample queries',
      maximumMs: 10_100, schedulingGranularityMs: 100 }
    expect(burst.receipt.capturedRows).toBe(10_000)
    const firstCommit = burst.receipt.firstCommit as { commitStartEpochMs: number }
    const finalCommitEarliestHostMs = dispatch + Number(burst.receipt.commitStartEpochMs) - firstCommit.commitStartEpochMs
    const burstRendered = await Promise.all(burst.receipt.users!.map(async (user) => {
      const observer = observers.find((entry) => entry.userId === user.id)!
      const render = await observer.waitForCount(user.count, user.title)
      return { userId: user.id, ...render, dispatchUpperBoundMs: render.hostEpochMs - dispatch,
        finalCommitUpperBoundMs: render.hostEpochMs - finalCommitEarliestHostMs,
        producerFinalCommitEndToHostReceiptMs: render.hostEpochMs - Number(burst.receipt.commitEndEpochMs) }
    }))
    evidence.burstRendered = burstRendered
    const drainStarted = Date.now()
    const drainSamples: FixtureReceipt[] = []
    // The fixture's scoped backlog receipt remains authoritative; retain every drain observation.
    await expect.poll(async () => {
      const drained = await fixture.request({ op: 'sample' })
      drainSamples.push(drained.receipt)
      return Number((drained.receipt.scopedInbox as { count: number }).count)
    }, { timeout: 30_000, intervals: [250] }).toBe(0)
    evidence.backlogAfterBurst = { observedEpochMs: drainStarted, drainObservedEpochMs: Date.now(), samples: drainSamples }
    for (const observer of observers) await observer.sample()
    stopSampling = true
    await sampler
    sampler = undefined
    await quiet('recovered-steady')
    for (const context of participantContexts) await context.close()
    // Closing real contexts releases subscriptions; cleanup below revokes their owned sessions.
    await expect.poll(async () => (await sample('closed-wait')).data.workers
      .reduce((sum, worker) => sum + worker.live_updates.active_subscriptions, 0), { timeout: 15_000 }).toBe(quietSubscriptionCount)
    await quiet('quiet-after')
    const before = diagnostics.filter((entry) => entry.phase === 'quiet-before')
    const after = diagnostics.filter((entry) => entry.phase === 'quiet-after')
    const identities = before[0]!.stats.data.workers.map((worker) => `${worker.worker_id}:${worker.process.pid}`).sort()
    for (const entry of after) {
      expect(entry.stats.data.workers.map((worker) => `${worker.worker_id}:${worker.process.pid}`).sort()).toEqual(identities)
      for (const worker of entry.stats.data.workers) {
        worker.pools.forEach((pool, index) => {
          const envelope = Math.max(...before.map((baseline) => baseline.stats.data.workers
            .find((candidate) => candidate.worker_id === worker.worker_id)!.pools[index]!.active))
          expect(pool.active, `worker ${worker.worker_id} pool ${index} quiet assignment`).toBeLessThanOrEqual(envelope)
        })
        expect(worker.live_updates.pending_send_count).toBe(0)
        for (const counter of ['projection_error_count', 'read_error_count']) {
          const initial = before[0]!.stats.data.workers.find((candidate) => candidate.worker_id === worker.worker_id)!
          expect(worker.live_updates[counter], counter).toBe(initial.live_updates[counter])
        }
      }
    }
    const sourceReads = observers.flatMap((observer) => observer.http.filter((entry) => entry.startEpochMs >= measuredStartEpochMs
      && entry.path.startsWith('/api/notifications')).map((entry) => ({ userId: observer.userId, ...entry })))
    const httpBudgets = observers.map((observer) => {
      const reads = observer.http.filter((entry) => entry.startEpochMs >= measuredStartEpochMs)
      return { userId: observer.userId, maxInFlight: observer.maxInFlight,
        maxStartsPerSecond: Math.max(0, ...reads.map((entry) => reads.filter((other) =>
          other.startEpochMs >= entry.startEpochMs && other.startEpochMs < entry.startEpochMs + 1000).length)) }
    })
    evidence.httpBudgets = { scope: 'All GET /api/ attempts from the 32 measured SPA contexts during workload; admin control requests and startup excluded.', clients: httpBudgets }
    expect(httpBudgets.every((entry) => entry.maxInFlight <= 4 && entry.maxStartsPerSecond <= 20), 'automatic HTTP workload budgets').toBe(true)
    expect(samplingFailure, 'runtime sampling remained healthy').toBeUndefined()
    expect(producerDurationMs, '10K producer duration with one100ms batch scheduling granularity').toBeLessThanOrEqual(10_100)
    const summarizeSourceReads = (reads: typeof sourceReads) => ({
      requests: reads.length,
      premiseSatisfied: reads.length > 0 && reads.every((entry) => !entry.failed && entry.status >= 200 && entry.status < 300
        && Number.isFinite(entry.browserTiming.responseEnd) && entry.browserTiming.responseEnd >= 0
        && entry.browserTiming.responseEnd <= 1000),
      exceededCount: reads.filter((entry) => entry.browserTiming.responseEnd > 1000).length,
      exceededReads: reads.filter((entry) => entry.browserTiming.responseEnd > 1000),
      maxBrowserTransportMs: Math.max(0, ...reads.map((entry) => entry.browserTiming.responseEnd)),
      maxHostHandlerMs: Math.max(0, ...reads.map((entry) => entry.endEpochMs - entry.startEpochMs)),
      maxHostLegacyMs: Math.max(0, ...reads.map((entry) => entry.responseObservedEpochMs - entry.startEpochMs)),
      invalidBrowserTiming: reads.filter((entry) => !Number.isFinite(entry.browserTiming.responseEnd)
        || entry.browserTiming.responseEnd < 0).length,
    })
    evidence.sourceReadPremise = {
      method: 'Chromium Request.timing().responseEnd: milliseconds from browser request start through response body completion; finite nonnegative timing required, no host-duration fallback.',
      acceptance: 'The one-second source premise is reported separately. Every ordinary rendered sample must meet three seconds, including samples whose source reads exceed the premise.',
      all: summarizeSourceReads(sourceReads),
      ordinary: summarizeSourceReads(sourceReads.filter((entry) => entry.startEpochMs < burstDispatchEpochMs)),
      burstAndRecovery: summarizeSourceReads(sourceReads.filter((entry) => entry.startEpochMs >= burstDispatchEpochMs)),
      phaseBoundary: { measuredStartEpochMs, burstDispatchEpochMs, clock: 'host request event receipt' },
    }
    expect(sourceReads.length).toBeGreaterThanOrEqual(320)
    expect(sourceReads.every((entry) => !entry.failed && entry.status >= 200 && entry.status < 300),
      'all authoritative source HTTP reads succeeded').toBe(true)
    expect(sourceReads.every((entry) => Number.isFinite(entry.browserTiming.responseEnd)
      && entry.browserTiming.responseEnd >= 0), 'all source HTTP reads have valid native transport timing').toBe(true)
    expect(observers.every((observer) => observer.visibility.every((entry) => entry.state === 'visible'))).toBe(true)
    expect(latencies.every((entry) => entry.dispatchUpperBoundMs <= 3000),
      'every ordinary committed change rendered within three seconds regardless of source duration').toBe(true)
    expect(ordinaryP95).toBeLessThanOrEqual(3000)
    expect(worstPrincipalP95).toBeLessThanOrEqual(3000)
    expect(burstRendered.every((entry) => entry.finalCommitUpperBoundMs <= 5000), 'final count and visible marker <=5s').toBe(true)
  } finally {
    stopSampling = true
    if (sampler) await sampler.catch((error: unknown) => { evidence.samplingError = String(error) })
    if (observerFixture) {
      evidence.observerReceipts = observerFixture.receipts
      await observerFixture.close().catch((error: unknown) => { evidence.observerCloseError = String(error) })
    }
    if (loginAdmission) evidence.loginAdmission = { attempts: loginAdmission.attempts, waits: loginAdmission.waits }
    if (fixture.run && !cleaned) {
      try {
        const cleanup = (await fixture.request({ op: 'cleanup' })).receipt
        evidence.cleanup = cleanup
        cleaned = cleanup.complete === true
        expect(cleaned, 'owned fixture cleanup completed').toBe(true)
      }
      catch (error) { evidence.cleanupError = String(error); cleanupFailure = error }
    }
    evidence.contextClosure = await Promise.allSettled(contexts.map((context) => context.close()))
    await fixture.close().catch((error: unknown) => { evidence.fixtureCloseError = String(error) })
    evidence.observers = observers.map((observer) => observer.snapshot())
    evidence.diagnostics = diagnostics
    evidence.browserProcesses = browserProcesses
    await browserSession.detach().catch((error: unknown) => { evidence.browserSessionError = String(error) })
    evidence.fixtureReceipts = fixture.receipts
    evidence.fixtureRecovery = { run: fixture.run, manifestPath: fixture.run ? `var/live-capacity/${fixture.run}/manifest.json` : null }
    evidence.endEpochMs = Date.now()
    const path = testInfo.outputPath('capacity-raw-receipts.json')
    await writeFile(path, JSON.stringify(evidence, null, 2))
    await testInfo.attach('capacity-raw-receipts', { path, contentType: 'application/json' })
  }
  if (cleanupFailure) throw cleanupFailure
})
