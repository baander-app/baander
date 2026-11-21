import type { Page } from '@playwright/test'

export async function loadedRuntimeModule(page: Page, path: string): Promise<string | null> {
  return page.evaluate(modulePath => {
    const loaded = performance.getEntriesByType('resource').findLast(entry => {
      const url = new URL(entry.name)
      return url.origin === location.origin && url.pathname === modulePath
    })?.name
    if (loaded) return loaded

    const builtApplication = Array.from(document.scripts).some(script => {
      if (!script.src) return false
      const url = new URL(script.src, location.href)
      return url.origin === location.origin && /^\/assets\/app[-.].*\.js$/.test(url.pathname)
    })
    if (builtApplication) {
      throw new Error(
        'Playback browser checks require the Vite application. This page loaded a built app bundle; '
          + 'restart this isolated preview’s Vite service after running a production build.',
      )
    }
    return null
  }, path)
}
