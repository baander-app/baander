import type { Page } from '@playwright/test'

export async function loadedRuntimeModule(page: Page, path: string): Promise<string | null> {
  return page.evaluate(modulePath => performance.getEntriesByType('resource').findLast(entry => {
    const url = new URL(entry.name)
    return url.origin === location.origin && url.pathname === modulePath
  })?.name ?? null, path)
}
