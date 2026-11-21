import { execFileSync } from 'node:child_process'
import { randomUUID } from 'node:crypto'
import { appendFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import type { BrowserContext } from '@playwright/test'

const repository = resolve(import.meta.dirname, '../../../../..')
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const ownedContexts = new WeakMap<object, Promise<string>>()
type Context = Pick<BrowserContext, 'addInitScript'>
type CleanupDependencies = {
  resolvePreview?: () => string
  execute?: (deviceIds: string[]) => unknown
}

export function resolvePreview(): string {
  return execFileSync('make', ['--no-print-directory', 'preview-url'], {
    cwd: repository, encoding: 'utf8', timeout: 60_000,
  }).trim()
}

function origin(value: string): string {
  const target = new URL(value)
  if (!['http:', 'https:'].includes(target.protocol) || target.username || target.password) {
    throw new Error('Browser device ownership requires an HTTP(S) preview origin')
  }
  return target.origin
}

function requirePreview(baseURL: string, actual: string) {
  if (origin(baseURL) !== origin(actual)) {
    throw new Error('Browser device target does not match this worktree preview')
  }
}

export function initializeOwnership(baseURL: string, preview = resolvePreview, root = join(repository, 'var/browser-devices')): string {
  requirePreview(baseURL, preview())
  mkdirSync(root, { recursive: true })
  const manifest = mkdtempSync(join(root, 'run-'))
  writeFileSync(join(manifest, 'target.json'), JSON.stringify({ origin: origin(baseURL) }), { flag: 'wx' })
  writeFileSync(join(manifest, 'devices.jsonl'), '', { flag: 'wx' })
  return manifest
}

function requireManifest(manifest: string, baseURL: string) {
  const target: unknown = JSON.parse(readFileSync(join(manifest, 'target.json'), 'utf8'))
  if (!target || typeof target !== 'object' || !('origin' in target) || target.origin !== origin(baseURL)) {
    throw new Error('Browser device manifest target does not match the preview')
  }
}

/** Call before the first navigation of any context that can authenticate the seeded administrator. */
export async function ownBrowserContext(context: Context, baseURL = process.env.E2E_BASE_URL): Promise<string> {
  const existing = ownedContexts.get(context)
  if (existing) return existing
  const initialization = (async () => {
    const manifest = process.env.BAANDER_BROWSER_DEVICE_MANIFEST
    if (!manifest || !baseURL) throw new Error('Seeded browser authentication requires a preview ownership manifest')
    requireManifest(manifest, baseURL)
    const deviceId = randomUUID()
    // A single O_APPEND write per UUID keeps parallel workers from overwriting one another.
    appendFileSync(join(manifest, 'devices.jsonl'), `${deviceId}\n`)
    await context.addInitScript(({ deviceId, target }) => {
      if (location.origin === target) localStorage.setItem('baander-device-id', deviceId)
    }, { deviceId, target: origin(baseURL) })
    return deviceId
  })()
  ownedContexts.set(context, initialization)
  return initialization
}

function executeCleanup(deviceIds: string[]): unknown {
  const output = execFileSync('php', [
    'scripts/dev-environment.php', 'exec', '--', 'env', 'BAANDER_BROWSER_DEVICE_CLEANUP=1',
    'php', 'tests/Fixtures/BrowserDevices/device-fixture.php',
  ], {
    cwd: repository, encoding: 'utf8', input: JSON.stringify({ deviceIds }), timeout: 120_000,
  })
  return JSON.parse(output)
}

export async function cleanupOwnership(manifest: string, baseURL: string, dependencies: CleanupDependencies = {}): Promise<void> {
  try {
    requireManifest(manifest, baseURL)
    requirePreview(baseURL, (dependencies.resolvePreview ?? resolvePreview)())
    const records = readFileSync(join(manifest, 'devices.jsonl'), 'utf8')
    const deviceIds = records === '' ? [] : records.trimEnd().split('\n')
    if (deviceIds.some(deviceId => !uuid.test(deviceId))) throw new Error('Browser device manifest contains an invalid UUID')
    const receipt = (dependencies.execute ?? executeCleanup)([...new Set(deviceIds)])
    if (!receipt || typeof receipt !== 'object' || !('deleted' in receipt)
      || typeof receipt.deleted !== 'number' || !Number.isSafeInteger(receipt.deleted) || receipt.deleted < 0) {
      throw new Error('Invalid browser device cleanup receipt')
    }
    rmSync(manifest, { recursive: true })
  } catch (error) {
    throw new Error(`Browser device cleanup failed: ${error instanceof Error ? error.message : String(error)}; ownership manifest retained at ${manifest}`, { cause: error })
  }
}
