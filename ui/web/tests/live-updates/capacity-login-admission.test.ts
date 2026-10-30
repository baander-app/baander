import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Page, Request, Response } from '@playwright/test'
import { LoginAdmission, login, type FixtureUser } from '../e2e/live-updates/capacity-fixtures'

vi.mock('@playwright/test', () => ({
  expect: () => ({ toHaveURL: vi.fn(), toBeVisible: vi.fn() }),
}))

interface Answer { status: number; headers?: Record<string, string>; body?: Record<string, unknown> }
const user: FixtureUser = {
  id: 'account', publicId: 'public-account', email: 'capacity@baander.app', password: 'fixture-password', admin: false,
}

/** Supplies the HTTP events emitted by a real login; DOM assertions belong to browser qualification. */
function loginPage(submissions: Answer[][]) {
  const listeners = new Set<(request: Request) => void>()
  let predicate: ((response: Response) => Promise<boolean>) | undefined
  let resolveResponse: ((response: Response) => void) | undefined
  const request = { method: () => 'POST', url: () => 'https://baander.app/api/auth/login' } as Request
  const click = vi.fn(async () => {
    const answers = submissions.shift()
    if (!answers) throw new Error('Unexpected login resubmission')
    for (const answer of answers) {
      listeners.forEach(listener => listener(request))
      const response = {
        status: () => answer.status, headers: () => answer.headers ?? {}, json: async () => answer.body ?? {},
        request: () => request, url: () => request.url(),
      } as Response
      if (await predicate!(response)) resolveResponse!(response)
    }
  })
  const page = {
    goto: vi.fn(),
    getByLabel: () => ({ fill: vi.fn() }),
    getByRole: () => ({ click }),
    on: (_event: string, listener: (request: Request) => void) => listeners.add(listener),
    off: (_event: string, listener: (request: Request) => void) => listeners.delete(listener),
    waitForResponse: (next: (response: Response) => Promise<boolean>) => {
      predicate = next
      return new Promise<Response>(resolve => { resolveResponse = resolve })
    },
  } as unknown as Page
  return { page, click, listeners }
}

beforeEach(() => { vi.useFakeTimers({ now: 100_000 }) })
afterEach(() => { vi.useRealTimers() })
const flush = async () => {
  for (let index = 0; index < 20; index++) await Promise.resolve()
}

describe('production capacity login admission', () => {
  it('counts the PHP observer attempt and both actual browser nonce/login POSTs', async () => {
    const admission = new LoginAdmission(Date.now() + 1_800_000)
    admission.recordAttempt()
    const { page, listeners } = loginPage([[
      { status: 400, headers: { 'dpop-nonce': 'challenge' }, body: { error: 'use_dpop_nonce' } },
      { status: 200 },
    ]])
    await login(page, user, admission)
    expect(admission.attempts).toHaveLength(3)
    expect(admission.waits).toHaveLength(0)
    expect(listeners.size).toBe(0)
  })

  it('reserves room for both browser attempts when an observer shares the IP window', async () => {
    const admission = new LoginAdmission(Date.now() + 1_800_000)
    admission.recordAttempt()
    for (let principal = 0; principal < 9; principal++) {
      admission.recordAttempt()
      admission.recordAttempt()
    }
    let admitted = false
    const waiting = admission.admit().then(() => { admitted = true })
    await vi.advanceTimersByTimeAsync(299_999)
    expect(admitted).toBe(false)
    await vi.advanceTimersByTimeAsync(1001)
    await waiting
    expect(admitted).toBe(true)
    admission.recordAttempt()
    admission.recordAttempt()
    await admission.admit()
    expect(admission.attempts).toHaveLength(21)
  })

  it('waits out a full twenty-attempt window without discarding its attempt history', async () => {
    const admission = new LoginAdmission(Date.now() + 1_800_000)
    for (let index = 0; index < 20; index++) admission.recordAttempt()
    let admitted = false
    const waiting = admission.admit().then(() => { admitted = true })
    await vi.advanceTimersByTimeAsync(299_999)
    expect(admitted).toBe(false)
    await vi.advanceTimersByTimeAsync(1001)
    await waiting
    expect(admitted).toBe(true)
    expect(admission.attempts).toHaveLength(20)
  })

  it('honors a numeric server Retry-After before resubmitting the real login flow', async () => {
    const admission = new LoginAdmission(Date.now() + 1_800_000)
    const { page, click } = loginPage([
      [{ status: 429, headers: { 'retry-after': '2' } }],
      [{ status: 400, headers: { 'dpop-nonce': 'challenge' }, body: { error: 'use_dpop_nonce' } }, { status: 200 }],
    ])
    const pending = login(page, user, admission)
    await flush()
    expect(click).toHaveBeenCalledOnce()
    await vi.advanceTimersByTimeAsync(1999)
    expect(click).toHaveBeenCalledOnce()
    await vi.advanceTimersByTimeAsync(1)
    await pending
    expect(click).toHaveBeenCalledTimes(2)
    expect(admission.attempts).toHaveLength(3)
  })

  it('rejects a rate limit without a valid Retry-After instead of silently retrying', async () => {
    const admission = new LoginAdmission(Date.now() + 1_800_000)
    const { page, click, listeners } = loginPage([[{ status: 429, headers: { 'retry-after': 'invalid' } }]])
    await expect(login(page, user, admission)).rejects.toThrow('no bounded Retry-After')
    expect(click).toHaveBeenCalledOnce()
    expect(listeners.size).toBe(0)
  })

  it('rejects Retry-After beyond the scenario deadline', async () => {
    const admission = new LoginAdmission(Date.now() + 5000)
    const { page, click } = loginPage([[{ status: 429, headers: { 'retry-after': '300' } }]])
    await expect(login(page, user, admission)).rejects.toThrow('setup deadline exceeded')
    expect(click).toHaveBeenCalledOnce()
  })

  it('rejects an expired setup deadline even when there are free attempt slots', async () => {
    const admission = new LoginAdmission(Date.now())
    await expect(admission.admit()).rejects.toThrow('setup deadline exceeded')
  })
})
