// @vitest-environment node
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mkdtempSync, readFileSync, rmSync, existsSync, appendFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { initializeOwnership, ownBrowserContext, cleanupOwnership } from '../e2e/helpers/browser-device-lifecycle'

const origin = 'https://preview.baander.app'
const roots: string[] = []
function directory() {
  const root = mkdtempSync(join(tmpdir(), 'browser-devices-test-'))
  roots.push(root)
  return root
}
afterEach(() => {
  vi.unstubAllEnvs()
  for (const root of roots.splice(0)) rmSync(root, { recursive: true, force: true })
})

function context() {
  return { addInitScript: vi.fn().mockResolvedValue(undefined) }
}

describe('browser device ownership', () => {
  it('records distinct contexts once and preserves identity through repeated initialization and reload', async () => {
    const manifest = initializeOwnership(origin, () => origin, directory())
    vi.stubEnv('BAANDER_BROWSER_DEVICE_MANIFEST', manifest)
    const first = context()
    const second = context()
    const firstId = await ownBrowserContext(first, origin)
    expect(await ownBrowserContext(first, origin)).toBe(firstId)
    expect(await ownBrowserContext(second, origin)).not.toBe(firstId)
    expect(first.addInitScript).toHaveBeenCalledTimes(1)
    const [script, value] = first.addInitScript.mock.calls[0]
    const setItem = vi.fn()
    vi.stubGlobal('localStorage', { setItem })
    vi.stubGlobal('location', { origin })
    script(value)
    script(value)
    expect(setItem.mock.calls).toEqual([['baander-device-id', firstId], ['baander-device-id', firstId]])
    vi.unstubAllGlobals()
    expect(readFileSync(join(manifest, 'devices.jsonl'), 'utf8').trim().split('\n')).toHaveLength(2)
  })

  it('records concurrent contexts without overwriting another worker token', async () => {
    const manifest = initializeOwnership(origin, () => origin, directory())
    vi.stubEnv('BAANDER_BROWSER_DEVICE_MANIFEST', manifest)
    const deviceIds = await Promise.all(Array.from({ length: 32 }, () => ownBrowserContext(context(), origin)))
    const recorded = readFileSync(join(manifest, 'devices.jsonl'), 'utf8').trim().split('\n')
    expect(new Set(recorded).size).toBe(32)
    expect(recorded.sort()).toEqual(deviceIds.sort())
  })

  it('rejects context target mismatch before installing browser identity', async () => {
    const manifest = initializeOwnership(origin, () => origin, directory())
    vi.stubEnv('BAANDER_BROWSER_DEVICE_MANIFEST', manifest)
    const browser = context()
    await expect(ownBrowserContext(browser, 'https://other.baander.app')).rejects.toThrow(/preview/)
    expect(browser.addInitScript).not.toHaveBeenCalled()
    expect(readFileSync(join(manifest, 'devices.jsonl'), 'utf8')).toBe('')
  })

  it('rejects a mismatched preview before creating ownership', () => {
    const root = directory()
    expect(() => initializeOwnership(origin, () => 'https://other.baander.app', root)).toThrow(/preview/)
  })

  it('rejects seeded authentication without an initialized manifest', async () => {
    vi.stubEnv('BAANDER_BROWSER_DEVICE_MANIFEST', '')
    const browser = context()
    await expect(ownBrowserContext(browser, origin)).rejects.toThrow(/manifest/)
    expect(browser.addInitScript).not.toHaveBeenCalled()
  })

  it('rejects malformed ownership and mismatched targets without executing cleanup', async () => {
    const manifest = initializeOwnership(origin, () => origin, directory())
    const execute = vi.fn()
    await expect(cleanupOwnership(manifest, origin, { resolvePreview: () => 'https://other.baander.app', execute })).rejects.toThrow(/preview/)
    appendFileSync(join(manifest, 'devices.jsonl'), 'not-a-uuid\n')
    await expect(cleanupOwnership(manifest, origin, { resolvePreview: () => origin, execute })).rejects.toThrow(/UUID/)
    expect(execute).not.toHaveBeenCalled()
    expect(existsSync(manifest)).toBe(true)
  })

  it('retains failure evidence and removes the manifest only after successful cleanup', async () => {
    const manifest = initializeOwnership(origin, () => origin, directory())
    vi.stubEnv('BAANDER_BROWSER_DEVICE_MANIFEST', manifest)
    const deviceId = await ownBrowserContext(context(), origin)
    await expect(cleanupOwnership(manifest, origin, { resolvePreview: () => origin, execute: () => { throw new Error('database unavailable') } })).rejects.toThrow(/database unavailable/)
    expect(existsSync(join(manifest, 'devices.jsonl'))).toBe(true)
    const execute = vi.fn().mockReturnValue({ deleted: 1 })
    await cleanupOwnership(manifest, origin, { resolvePreview: () => origin, execute })
    expect(execute).toHaveBeenCalledWith([deviceId])
    expect(existsSync(manifest)).toBe(false)
  })

  it('accepts empty runs and validates cleanup receipts', async () => {
    const manifest = initializeOwnership(origin, () => origin, directory())
    await expect(cleanupOwnership(manifest, origin, { resolvePreview: () => origin, execute: () => ({ deleted: -1 }) })).rejects.toThrow(/receipt/)
    await cleanupOwnership(manifest, origin, { resolvePreview: () => origin, execute: () => ({ deleted: 0 }) })
    expect(existsSync(manifest)).toBe(false)
  })
})
