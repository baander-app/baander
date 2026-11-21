import type { FullConfig } from '@playwright/test'
import { initializeOwnership } from './helpers/browser-device-lifecycle'

export default function setup(config: FullConfig) {
  // Qualification runs use disposable users and retain their own fixture lifecycle.
  if (process.env.BAANDER_LIVE_CAPACITY_MANIFEST) return
  const baseURL = config.projects[0]?.use.baseURL
  if (!baseURL) throw new Error('Browser device setup requires an explicit base URL')
  process.env.BAANDER_BROWSER_DEVICE_MANIFEST = initializeOwnership(baseURL)
}
