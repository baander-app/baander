import { type Page } from '@playwright/test';

export async function setTheme(page: Page, mood: string, accent: string) {
  await page.evaluate(([m, a]) => {
    document.documentElement.setAttribute('data-theme', m);
    document.documentElement.setAttribute('data-accent', a);
    document.documentElement.classList.add('theme-transitioning');
  }, [mood, accent]);
  await page.waitForTimeout(250);
}
