import { spawn, type ChildProcessWithoutNullStreams } from 'node:child_process'
import { createInterface } from 'node:readline'
import { resolve } from 'node:path'
import { expect, type Browser, type Page, type Request } from '@playwright/test'

export interface FixtureUser { id: string; publicId: string; email: string; password: string; admin: boolean }
export interface FixtureResource { id: string; publicId: string; slug?: string; path?: string }
export interface FixtureManifest {
  users: FixtureUser[]
  observer: FixtureUser
  library: FixtureResource
  playlist: FixtureResource
  song: FixtureResource
  album: FixtureResource
  job: FixtureResource
}
export interface FixtureReceipt {
  users?: { id: string; count: number; title: string }[]
  capturedRows?: number
  commitStartEpochMs?: number
  commitEndEpochMs?: number
  [key: string]: unknown
}
export interface FixtureResponse<T = FixtureReceipt> {
  requestId: string
  op: string
  run: string
  ok: boolean
  complete?: boolean
  receipt: T
  error?: unknown
}

export const redact = (value: unknown): unknown => JSON.parse(JSON.stringify(value, (key, entry: unknown) =>
  /password|token|authorization|dpop/i.test(key) ? '[redacted]' : entry)) as unknown

/** Keeps the managed executor open; its lifecycle lock forbids concurrent CLI probes. */
export class FixtureClient {
  readonly receipts: unknown[] = []
  run = ''
  private sequence = 0
  private pending: { id: string; resolve: (value: FixtureResponse) => void; reject: (error: Error) => void } | undefined
  private stderr = ''
  private constructor(private readonly child: ChildProcessWithoutNullStreams) {
    createInterface({ input: child.stdout }).on('line', (line) => {
      let result: FixtureResponse
      try { result = JSON.parse(line) as FixtureResponse } catch { return }
      this.receipts.push({ receivedEpochMs: Date.now(), ...redact(result) as object })
      if (result.requestId !== this.pending?.id || result.complete === false) return
      if (typeof result.run === 'string' && result.run) this.run = result.run
      const pending = this.pending
      this.pending = undefined
      if (result.ok) pending.resolve(result)
      else pending.reject(new Error(`Fixture ${result.op} failed: ${JSON.stringify(result.error)}`))
    })
    child.stderr.on('data', (chunk: Buffer) => { this.stderr = (this.stderr + chunk.toString()).slice(-8000) })
    child.stdin.on('error', (error) => this.pending?.reject(error))
    child.on('error', (error) => this.pending?.reject(error))
    child.on('exit', (code) => this.pending?.reject(new Error(`Fixture exited ${code}: ${this.stderr}`)))
  }
  static start() {
    const manifest = process.env.BAANDER_LIVE_CAPACITY_MANIFEST
    const args = manifest
      ? ['scripts/test-live-capacity-exec.php', manifest]
      : ['scripts/dev-environment.php', 'exec', '--', 'php', 'tests/Fixtures/LiveUpdates/capacity-fixture.php']
    return new FixtureClient(spawn('php', args, {
      cwd: resolve(import.meta.dirname, '../../../../..'), stdio: 'pipe',
    }))
  }
  async request<T = FixtureReceipt>(command: { op: string; [key: string]: unknown }): Promise<FixtureResponse<T>> {
    if (this.child.exitCode !== null || this.child.signalCode !== null) throw new Error('Fixture executor has exited')
    if (this.pending) throw new Error('Fixture commands must be serialized')
    if (typeof command.run === 'string') this.run = command.run
    const requestId = String(++this.sequence)
    const result = new Promise<FixtureResponse>((resolveRequest, reject) => {
      this.pending = { id: requestId, resolve: resolveRequest, reject }
    })
    const timeout = setTimeout(() => this.pending?.reject(new Error(`Fixture ${command.op} timed out after 180s; manifest retained`)), 180_000)
    this.child.stdin.write(`${JSON.stringify({ run: this.run || undefined, ...command, requestId })}\n`)
    try { return await result as FixtureResponse<T> }
    finally { clearTimeout(timeout); this.pending = undefined }
  }
  async close() {
    if (this.child.exitCode !== null) return
    const exited = new Promise<void>((resolveExit) => this.child.once('exit', () => resolveExit()))
    this.child.stdin.end()
    await exited
  }
}

/** Conservatively reserves both the initial login and its one nonce retry. */
export class LoginAdmission {
  readonly attempts: { epochMs: number }[] = []
  readonly waits: { epochMs: number; milliseconds: number; reason: string }[] = []
  constructor(readonly deadlineEpochMs: number) {}
  recordAttempt() {
    this.attempts.push({ epochMs: Date.now() })
  }
  async wait(milliseconds: number, reason: string) {
    if (!Number.isFinite(milliseconds) || milliseconds < 0 || Date.now() + milliseconds >= this.deadlineEpochMs) {
      throw new Error('Production login setup deadline exceeded')
    }
    this.waits.push({ epochMs: Date.now(), milliseconds, reason })
    await new Promise<void>((resolveWait) => setTimeout(resolveWait, milliseconds))
  }
  async admit() {
    while (true) {
      if (Date.now() >= this.deadlineEpochMs) throw new Error('Production login setup deadline exceeded')
      const window = this.attempts.filter((entry) => entry.epochMs > Date.now() - 300_000)
      if (window.length + 2 <= 20) return
      await this.wait(Math.max(1, window[0]!.epochMs + 300_100 - Date.now()), '20 login attempts per 300 seconds, including nonce retry')
    }
  }
}

export async function login(page: Page, user: FixtureUser, admission?: LoginAdmission) {
  await page.goto('/login', { waitUntil: 'domcontentloaded' })
  await page.getByLabel(/email/i).fill(user.email)
  await page.getByLabel(/password/i).fill(user.password)
  const recordAttempt = (request: Request) => {
    if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/auth/login') admission?.recordAttempt()
  }
  if (admission) page.on('request', recordAttempt)
  try {
    while (true) {
      await admission?.admit()
      let nonceChallengeObserved = false
      const loginResponse = page.waitForResponse(async (response) => {
        if (new URL(response.url()).pathname !== '/api/auth/login' || response.request().method() !== 'POST') return false
        if (!nonceChallengeObserved && response.status() === 400 && response.headers()['dpop-nonce']) {
          const body = await response.json().catch(() => ({})) as Record<string, unknown>
          if (body.error === 'use_dpop_nonce') {
            nonceChallengeObserved = true
            return false
          }
        }
        return true
      })
      const [response] = await Promise.all([
        loginResponse,
        page.getByRole('button', { name: /log in/i }).click(),
      ])
      if (response.status() === 429 && admission) {
        const retryAfter = response.headers()['retry-after']
        const seconds = retryAfter && /^\d+$/.test(retryAfter) ? Number(retryAfter) : Number.NaN
        if (!Number.isFinite(seconds)) throw new Error('Login rate limit supplied no bounded Retry-After')
        await admission.wait(Math.max(1, seconds) * 1000, 'server Retry-After')
        continue
      }
      if (response.status() >= 400) {
        const body = await response.json().catch(() => ({})) as Record<string, unknown>
        const failure = Object.fromEntries(Object.entries(body)
          .filter(([key, value]) => ['code', 'error', 'message', 'title', 'detail'].includes(key)
            && ['string', 'number'].includes(typeof value))
          .map(([key, value]) => [key, typeof value === 'string' ? value.replaceAll(user.password, '[redacted]').slice(0, 1000) : value]))
        await page.getByLabel(/password/i).fill('')
        throw new Error(`Real login HTTP ${response.status()}: ${JSON.stringify(failure)}`)
      }
      break
    }
  } finally {
    if (admission) page.off('request', recordAttempt)
  }
  await expect(page).toHaveURL(/\/music$/)
  await expect(page.getByRole('button', { name: /\d+ unread notifications/ })).toBeVisible()
}

export async function openFixturePage(browser: Browser, user: FixtureUser, baseURL: string) {
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true })
  const page = await context.newPage()
  await login(page, user)
  return { context, page }
}

/** Uses the SPA's real DPoP/auth middleware. This requires the preview Vite runtime. */
export async function authenticatedGet<T = unknown>(page: Page, path: string): Promise<T> {
  return page.evaluate(async (url) => {
    const modulePath = '/src/shared/api-client/axios-instance.ts'
    const { AXIOS_INSTANCE } = await import(/* @vite-ignore */ modulePath) as {
      AXIOS_INSTANCE: { get: (path: string) => Promise<{ data: unknown }> }
    }
    return (await AXIOS_INSTANCE.get(url)).data
  }, path) as Promise<T>
}
