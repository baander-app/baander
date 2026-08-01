import { test, expect } from '../fixtures';
import { setTheme } from '../helpers/theme-utils';

const PAGES = [
  { name: 'home', path: '/' },
  { name: 'albums', path: '/albums' },
  { name: 'artists', path: '/artists' },
  { name: 'songs', path: '/songs' },
  { name: 'genres', path: '/genres' },
  { name: 'search', path: '/search' },
  { name: 'playlists', path: '/playlists' },
];

const MOODS = ['dark', 'warm', 'cool', 'balanced'] as const;
const ACCENTS = ['white', 'blue', 'violet', 'rose', 'amber', 'emerald', 'cyan', 'pink'] as const;

test.describe('visual baseline characterization', () => {
  for (const pageDef of PAGES) {
    for (const mood of MOODS) {
      for (const accent of ACCENTS) {
        test(`${pageDef.name} — ${mood}/${accent}`, async ({ asAdmin: page }) => {
          // Navigate via SPA to preserve auth state
          const link = page.locator(`nav a[href="${pageDef.path}"]`);
          if (await link.isVisible().catch(() => false)) {
            await link.click();
          } else {
            await page.evaluate((href) => {
              const event = new PopStateEvent('popstate', { state: null });
              history.pushState(null, '', href);
              dispatchEvent(event);
            }, pageDef.path);
          }
          await page.waitForTimeout(500);

          await setTheme(page, mood, accent);
          await page.waitForTimeout(250); // theme transition settles

          await expect(page).toHaveScreenshot(
            `baseline-${pageDef.name}-${mood}-${accent}.png`,
            { fullPage: true, maxDiffPixels: 100 },
          );
        });
      }
    }
  }
});

test.describe('theme switching verification', () => {
  test('background colors match expected values per mood', async ({ asAdmin: page }) => {
    const expected: Record<string, string> = {
      dark: 'rgb(0, 0, 0)',
      warm: 'rgb(250, 245, 238)',
      cool: 'rgb(240, 244, 248)',
      balanced: 'rgb(245, 245, 245)',
    };

    for (const [mood, expectedBg] of Object.entries(expected)) {
      await setTheme(page, mood, 'violet');
      const bg = await page.evaluate(() =>
        getComputedStyle(document.body).backgroundColor
      );
      // Allow slight tolerance for color-mix / rendering differences
      expect(bg).toBe(expectedBg);
    }
  });
});
