import { writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { test as base, expect, type BrowserContext, type Page, type Route } from '@playwright/test'
import { FixtureClient, LoginAdmission, login, redact, type FixtureManifest } from './capacity-fixtures'
import { RECOVERY_TEARDOWN_MS, startManagedLifecycle, type ManagedReceipt } from './managed-lifecycle'

export interface RecoveryRun {
  fixture: FixtureClient
  manifest: FixtureManifest
  pages: Page[]
  evidence: unknown[]
  open: (index: number, beforeLogin?: (page: Page) => Promise<void>) => Promise<Page>
  managed: (arguments_: string[]) => Promise<void>
}

export const test = base.extend<{ run: RecoveryRun }>({
  run: [async ({ browser, baseURL }, provideRun, info) => {
    if (!baseURL) throw new Error('An isolated preview URL is required')
    let fixture = FixtureClient.start()
    const clients = [fixture]
    const contexts: BrowserContext[] = []
    const pages: Page[] = []
    const evidence: unknown[] = []
    const loginAdmission = process.env.BAANDER_LIVE_CAPACITY_MANIFEST
      ? new LoginAdmission(Date.now() + 900_000)
      : undefined
    if (loginAdmission) evidence.push({ kind: 'production-login-admission', admission: loginAdmission })
    const controller = new AbortController()
    let closing = false
    let fixtureOpen = true
    let commandRunning = false
    let commandCount = 0
    let writes = Promise.resolve()
    let active: {
      owned: ReturnType<typeof startManagedLifecycle>
      outcome: Promise<{ receipt: ManagedReceipt } | { error: unknown }>
      path: string
    } | undefined
    const persistCommand = () => {
      if (!active) return Promise.resolve()
      const { path, owned } = active
      const snapshot = JSON.stringify(redact(owned.receipt), null, 2)
      const write = writes.then(() => writeFile(path, snapshot, { mode: 0o600 }))
      writes = write.catch(() => undefined)
      return write
    }
    const resumeFixture = (cleanupOnly = false) => {
      const permitted = cleanupOnly ? active?.owned.canCleanupFixture : active?.owned.canResumeFixture
      if (!permitted) {
        throw new Error(`Managed lifecycle did not confirm safe fixture access; retain run ${fixture.run} and ${active?.path}`)
      }
      const runId = fixture.run
      fixture = FixtureClient.start()
      fixture.run = runId
      clients.push(fixture)
      fixtureOpen = true
      return fixture
    }
    try {
      const { receipt: manifest } = await fixture.request<FixtureManifest>({ op: 'create' })
      const run: RecoveryRun = { fixture, manifest, pages, evidence, open: async (index, beforeLogin) => {
        const user = index < 0 ? manifest.observer : manifest.users[index]!
        const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true })
        contexts.push(context)
        const page = await context.newPage()
        pages.push(page)
        await beforeLogin?.(page)
        await login(page, user, loginAdmission)
        return page
      }, managed: async (arguments_) => {
        if (closing || commandRunning || (active && !active.owned.canResumeFixture)) {
          throw new Error('A managed lifecycle command is already active or closing')
        }
        commandRunning = true
        try {
          const runId = fixture.run
          await fixture.close()
          fixtureOpen = false
          if (closing) throw new Error('Recovery fixture is closing before lifecycle startup')
          const owned = startManagedLifecycle(arguments_, {
            cwd: resolve(import.meta.dirname, '../../../../..'), run: runId, signal: controller.signal,
          })
          const outcome = owned.completion.then(receipt => ({ receipt }), (error: unknown) => ({ error }))
          active = { owned, outcome, path: info.outputPath(`managed-command-${++commandCount}.json`) }
          try {
            await persistCommand()
            const result = await outcome
            if ('error' in result) throw result.error
          } finally {
            evidence.push({ kind: 'managed-command', ...owned.receipt })
            await persistCommand()
            if (!closing && owned.canResumeFixture) run.fixture = resumeFixture()
          }
        } finally {
          commandRunning = false
        }
      } }
      await provideRun(run)
    } finally {
      closing = true
      controller.abort()
      try {
        if (active) {
          await active.outcome
          await persistCommand()
        }
        await Promise.all(contexts.map(context => context.close()))
        if (!fixtureOpen) resumeFixture(true)
        if (fixture.run) {
          await expect.poll(async () => {
            const cleanup = await fixture.request({ op: 'cleanup', waitMs: 1000 })
            return cleanup.receipt.complete
          }, { message: 'owned fixture cleanup must finish', timeout: 90_000,
            intervals: [250, 500, 1000] }).toBe(true)
        }
      } finally {
        try {
          if (fixtureOpen) await fixture.close()
        } finally {
          const path = info.outputPath('qualification.json')
          await writeFile(path, JSON.stringify(redact({ receipts: clients.flatMap(client => client.receipts), evidence }), null, 2), { mode: 0o600 })
          await info.attach('qualification', { path, contentType: 'application/json' })
        }
      }
    }
  }, { timeout: RECOVERY_TEARDOWN_MS }],
})

export { expect }

export async function navigate(page: Page, path: string) {
  await page.evaluate((href) => {
    history.pushState(null, '', href)
    dispatchEvent(new PopStateEvent('popstate'))
  }, path)
  await expect(page).toHaveURL(url => url.pathname + url.search === path)
  await expect(page.locator('main')).toBeVisible()
}

/** Observe the production DOM on a visible animation frame after the assertion. */
export async function paint(page: Page) {
  return page.evaluate(() => new Promise<{ epochMs: number; visible: boolean }>((resolve) => {
    requestAnimationFrame(() => resolve({ epochMs: performance.timeOrigin + performance.now(),
      visible: document.visibilityState === 'visible' }))
  }))
}

export async function authenticatedWrite(page: Page, method: 'post' | 'put' | 'patch', path: string, data?: unknown) {
  return page.evaluate(async ({ method: verb, path: url, data: body }) => {
    const modulePath = '/src/shared/api-client/axios-instance.ts'
    const { AXIOS_INSTANCE } = await import(/* @vite-ignore */ modulePath) as {
      AXIOS_INSTANCE: Record<string, (path: string, data: unknown) => Promise<{ data: unknown }>>
    }
    return (await AXIOS_INSTANCE[verb]!(url, body)).data
  }, { method, path, data })
}

/** Delay a real authorized response; never manufacture its protected payload. */
export async function holdResponse(page: Page, pattern: string, method?: string) {
  let release: () => void = () => {}
  const released = new Promise<void>(resolve => { release = resolve })
  let entered: () => void = () => {}
  const pending = new Promise<void>(resolve => { entered = resolve })
  let complete: () => void = () => {}
  const finished = new Promise<void>(resolve => { complete = resolve })
  let held = false
  let status: number | undefined
  const handler = async (route: Route) => {
    if (method && route.request().method() !== method) return route.continue()
    if (held) return route.continue()
    held = true
    try {
      const response = await route.fetch()
      status = response.status()
      entered()
      await released
      await route.fulfill({ response }).catch(() => {})
    } finally { complete() }
  }
  await page.route(pattern, handler)
  return { pending, get status() { return status }, release: async () => {
    release()
    await page.unroute(pattern, handler)
    if (held) await finished
  } }
}

export function observeReads(page: Page, path: string) {
  const receipts: { path: string; status: number; epochMs: number }[] = []
  page.on('response', response => {
    const url = new URL(response.url())
    if (url.pathname === path) receipts.push({ path: url.pathname + url.search,
      status: response.status(), epochMs: Date.now() })
  })
  return receipts
}

export async function interruptDelivery(page: Page) {
  await page.route('**/api/ws/tickets', route => route.abort('connectionfailed'))
  await page.evaluate(async () => {
    const modulePath = '/src/shared/live/transport.ts'
    const { authenticatedTransport } = await import(/* @vite-ignore */ modulePath) as {
      authenticatedTransport: { reconnect: () => void }
    }
    authenticatedTransport.reconnect()
  })
  await expect(page.getByRole('status').filter({ hasText: 'Updates unavailable' })).toBeVisible({ timeout: 20_000 })
  return async () => { await page.unroute('**/api/ws/tickets') }
}
