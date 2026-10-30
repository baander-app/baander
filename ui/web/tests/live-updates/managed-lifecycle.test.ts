// @vitest-environment node
import { EventEmitter } from 'node:events'
import { PassThrough } from 'node:stream'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { startManagedLifecycle } from '../e2e/live-updates/managed-lifecycle'

const { spawn } = vi.hoisted(() => ({ spawn: vi.fn() }))
vi.mock('node:child_process', () => ({ spawn }))

function childProcess() {
  return Object.assign(new EventEmitter(), {
    pid: 12345,
    stdout: new PassThrough(),
    stderr: new PassThrough(),
    kill: vi.fn(() => true),
  })
}

describe('managed lifecycle child ownership', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => {
    vi.useRealTimers()
    vi.clearAllMocks()
  })

  function start() {
    const child = childProcess()
    spawn.mockReturnValue(child)
    const controller = new AbortController()
    const owned = startManagedLifecycle(['restart'], {
      cwd: '/baander', run: 'owned-run', signal: controller.signal,
      commandMs: 1000, cleanupMs: 100,
    })
    const outcome = owned.completion.then(value => ({ value }), error => ({ error }))
    return { child, controller, owned, outcome }
  }

  it('waits for close and drained output after process exit', async () => {
    const { child, owned, outcome } = start()
    let settled = false
    outcome.then(() => { settled = true })
    child.emit('exit', 0, null)
    await Promise.resolve()
    expect(settled).toBe(false)
    child.stdout.write('final output')
    child.emit('close', 0, null)
    await expect(outcome).resolves.toEqual({ value: expect.objectContaining({ code: 0, closeConfirmed: true }) })
    expect(owned.receipt.pid).toBe(12345)
    expect(owned.canResumeFixture).toBe(true)
    expect(child.kill).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('retains bounded diagnostic output on a nonzero exit', async () => {
    const { child, owned, outcome } = start()
    child.stderr.write('x'.repeat(5000))
    child.emit('close', 37, null)
    const result = await outcome
    expect(result).toEqual({ error: expect.objectContaining({ message: expect.stringContaining('(37)') }) })
    if ('error' in result) {
      expect(result.error.message).toContain('x'.repeat(4000))
      expect(result.error.message.length).toBeLessThan(4100)
    }
    expect(JSON.stringify(owned.receipt)).not.toContain('x'.repeat(30))
    expect(owned.receipt.closeConfirmed).toBe(true)
    expect(owned.canResumeFixture).toBe(false)
    expect(owned.canCleanupFixture).toBe(true)
  })

  it('omits successful CLI credential output from qualification receipts', async () => {
    const { child, owned, outcome } = start()
    child.stdout.write('Password: test-only-login-secret\n')
    child.emit('close', 0, null)
    await outcome
    expect(JSON.stringify(owned.receipt)).not.toContain('test-only-login-secret')
    expect(owned.receipt).not.toHaveProperty('output')
  })

  it('terminates at the command deadline and waits for PHP cleanup to close', async () => {
    const { child, owned, outcome } = start()
    await vi.advanceTimersByTimeAsync(1000)
    expect(child.kill).toHaveBeenCalledExactlyOnceWith('SIGTERM')
    expect(owned.receipt.closeConfirmed).toBe(false)
    child.emit('close', 143, null)
    expect(await outcome).toEqual({ error: expect.objectContaining({ message: expect.stringContaining('deadline') }) })
    expect(owned.receipt.closeConfirmed).toBe(true)
    expect(owned.canCleanupFixture).toBe(true)
  })

  it('teardown abort and deadline racing send one signal and join one close', async () => {
    const { child, controller, owned, outcome } = start()
    controller.abort()
    await vi.advanceTimersByTimeAsync(50)
    expect(child.kill).toHaveBeenCalledExactlyOnceWith('SIGTERM')
    child.emit('close', null, 'SIGTERM')
    await vi.advanceTimersByTimeAsync(1000)
    expect(await outcome).toEqual({ error: expect.any(Error) })
    expect(owned.receipt.closeConfirmed).toBe(true)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('reports unconfirmed ownership after cleanup grace without claiming closure', async () => {
    const { child, controller, owned, outcome } = start()
    controller.abort()
    await vi.advanceTimersByTimeAsync(100)
    expect(await outcome).toEqual({ error: expect.objectContaining({ message: expect.stringContaining('unconfirmed') }) })
    expect(owned.receipt).toMatchObject({ run: 'owned-run', pid: 12345, command: ['restart'], closeConfirmed: false })
    expect(owned.canResumeFixture).toBe(false)
    expect(owned.canCleanupFixture).toBe(false)
    expect(child.kill).toHaveBeenCalledExactlyOnceWith('SIGTERM')
    expect(child.kill).not.toHaveBeenCalledWith('SIGKILL')
    child.emit('close', 0, null)
    expect(owned.canResumeFixture).toBe(false)
    expect(owned.canCleanupFixture).toBe(false)
  })

  it('captures spawn failure and confirms closure before rejecting', async () => {
    const { child, owned, outcome } = start()
    child.emit('error', new Error('spawn failed'))
    expect(owned.receipt.closeConfirmed).toBe(false)
    child.emit('close', -2, null)
    expect(await outcome).toEqual({ error: expect.objectContaining({ message: expect.stringContaining('spawn failed') }) })
    expect(owned.receipt.closeConfirmed).toBe(true)
  })
})
