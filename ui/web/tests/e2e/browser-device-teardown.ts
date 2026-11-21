import type { FullConfig } from '@playwright/test'
import { cleanupOwnership } from './helpers/browser-device-lifecycle'

export default async function teardown(config: FullConfig) {
  if (process.env.BAANDER_LIVE_CAPACITY_MANIFEST) return
  const manifest = process.env.BAANDER_BROWSER_DEVICE_MANIFEST
  const baseURL = config.projects[0]?.use.baseURL
  if (!manifest || !baseURL) throw new Error('Browser device teardown requires its run ownership manifest and base URL')
  await cleanupOwnership(manifest, baseURL)
}
