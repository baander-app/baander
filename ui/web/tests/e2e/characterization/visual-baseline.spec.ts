import { test, expect } from '../styling/fixtures'
import { spaNavigate } from '../fixtures'
import { main } from '../selectors'
import { setTheme, MOODS } from '../helpers/theme-utils'

const PAGES = [
  { name: 'artists', path: '/music/artists', heading: 'Artists' },
  { name: 'genres', path: '/music/genres', heading: 'Genres' },
]

test.describe('visual characterization evidence', () => {
  for (const pageDef of PAGES) {
    for (const mood of MOODS) {
      for (const accent of ['violet']) {
        test(`${pageDef.name} — ${mood}/${accent}`, async ({ emptyCatalog: page }, testInfo) => {
          await spaNavigate(page)(pageDef.path)
          await expect(main(page).getByRole('heading', { name: pageDef.heading, exact: true, level: 1 })).toBeVisible()
          await setTheme(page, mood, accent)
          await testInfo.attach(`render-${pageDef.name}-${mood}-${accent}`, {
            body: await page.screenshot({ path: testInfo.outputPath(`render-${pageDef.name}-${mood}-${accent}.png`), fullPage: true, animations: 'disabled' }),
            contentType: 'image/png',
          })
          await testInfo.attach('render-context', {
            body: JSON.stringify({ route: pageDef.path, viewport: page.viewportSize(), mood, accent }),
            contentType: 'application/json',
          })
        })
      }
    }
  }
})

test('theme helper removes its temporary transition marker', async ({ emptyCatalog: page }) => {
  for (const mood of MOODS) {
    await setTheme(page, mood, 'violet')
    await expect(page.locator('html')).toHaveAttribute('data-theme', mood)
    await expect(page.locator('html')).not.toHaveClass(/theme-transitioning/)
  }
})
