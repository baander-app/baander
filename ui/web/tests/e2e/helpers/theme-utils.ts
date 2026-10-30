import { expect, type Page } from '@playwright/test'

export const MOODS = ['dark', 'warm', 'cool', 'balanced'] as const
export const ACCENTS = ['white', 'blue', 'violet', 'rose', 'amber', 'emerald', 'cyan', 'pink'] as const

/** Set render-only appearance. Persistence is exercised through Settings separately. */
export async function setTheme(page: Page, mood: string, accent: string) {
  await page.evaluate(([theme, color]) => {
    const root = document.documentElement
    root.classList.add('theme-transitioning')
    root.dataset.theme = theme
    root.dataset.accent = color
  }, [mood, accent])
  try {
    await expect(page.locator('html')).toHaveAttribute('data-theme', mood)
    await expect(page.locator('html')).toHaveAttribute('data-accent', accent)
    await page.evaluate(async () => {
      await document.fonts.ready
      // ThemeProvider observes root attributes and can inject its styles after the
      // attribute selectors have already started their first transitions.
      let stableSince = performance.now()
      let previous = ''
      const deadline = performance.now() + 3000
      while (performance.now() < deadline) {
        await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()))
        const rootStyle = getComputedStyle(document.documentElement)
        const bodyStyle = getComputedStyle(document.body)
        const signature = [rootStyle.getPropertyValue('--color-background'), rootStyle.getPropertyValue('--color-foreground'), bodyStyle.backgroundColor, bodyStyle.color].join('|')
        const transitioning = document.getAnimations().some((animation) => animation instanceof CSSTransition && animation.playState === 'running')
        if (signature !== previous || transitioning) {
          previous = signature
          stableSince = performance.now()
        } else if (performance.now() - stableSince >= 140) {
          return
        }
      }
      throw new Error('Theme styles did not settle after changing appearance')
    })
  } finally {
    await page.evaluate(() => document.documentElement.classList.remove('theme-transitioning'))
  }
}
