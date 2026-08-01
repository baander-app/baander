import { test } from '@playwright/test';

const SCREENSHOTS = '/home/martin/dev/baander/ui/web/tests/e2e/__screenshots__';

test.describe('Bug inspection screenshots', () => {
  test.skip(({ browserName }) => browserName !== 'chromium', 'Chromium only');

  test('capture logged-in app screenshots', async ({ page }) => {
    await page.goto('/');
    await page.waitForTimeout(3000);

    // Check if we're on login page or already logged in
    const url = page.url();
    console.log('Initial URL:', url);

    // Save current state
    await page.screenshot({ path: `${SCREENSHOTS}/01-initial.png` });

    // Try to get CSS vars for debugging
    const pageInfo = await page.evaluate(() => {
      const root = document.documentElement;
      const cs = getComputedStyle(root);
      const vars = {};
      const importantVars = [
        '--color-background', '--color-foreground', '--color-card', '--color-card-foreground',
        '--color-sidebar', '--color-sidebar-foreground', '--color-primary', '--color-primary-foreground',
        '--color-highlight', '--color-highlight-foreground', '--color-destructive',
        '--color-muted', '--color-muted-foreground', '--color-border', '--color-ring',
        '--color-accent', '--color-accent-foreground',
      ];
      importantVars.forEach(v => {
        vars[v] = cs.getPropertyValue(v).trim();
      });

      // Check for any elements with invisible text (same fg/bg color)
      const problems = [];
      const allEls = document.querySelectorAll('*');
      let count = 0;
      for (const el of allEls) {
        if (count++ > 200) break;
        const s = getComputedStyle(el);
        if (s.color === s.backgroundColor && s.color !== 'rgba(0, 0, 0, 0)' && el.textContent?.trim()) {
          problems.push({
            tag: el.tagName,
            text: el.textContent.trim().slice(0, 50),
            color: s.color,
            slot: el.getAttribute('data-slot'),
            class: el.className.toString().slice(0, 60),
          });
        }
      }

      return { vars, problems, url: window.location.href };
    });

    console.log('PAGE INFO:', JSON.stringify(pageInfo, null, 2));

    // If on login page, try to log in
    if (pageInfo.url.includes('/login') || await page.locator('input[type="email"]').count() > 0) {
      // Check if there's a way to bypass auth or if we have credentials
      console.log('On login page - attempting to capture anyway');

      // Fill in dummy credentials to see what happens
      const emailInput = page.locator('input[type="email"], input[name="email"]').first();
      if (await emailInput.count() > 0) {
        await emailInput.fill('admin@baander.test');
        await page.locator('input[type="password"], input[name="password"]').first().fill('password');
        await page.screenshot({ path: `${SCREENSHOTS}/02-login-filled.png` });

        // Try to submit
        await page.locator('button[type="submit"], button:has-text("Log in")').first().click();
        await page.waitForTimeout(5000);
        await page.screenshot({ path: `${SCREENSHOTS}/03-after-login.png` });

        // After login, capture the main app
        const afterUrl = page.url();
        console.log('After login URL:', afterUrl);

        if (!afterUrl.includes('/login')) {
          await page.waitForTimeout(2000);
          await page.screenshot({ path: `${SCREENSHOTS}/04-main-app.png` });

          // Navigate to a few pages
          // Try clicking on sidebar links
          const sidebarLinks = page.locator('nav a, [data-slot="sidebar"] a');
          const linkCount = await sidebarLinks.count();
          console.log(`Found ${linkCount} sidebar links`);

          if (linkCount > 1) {
            await sidebarLinks.nth(1).click();
            await page.waitForTimeout(2000);
            await page.screenshot({ path: `${SCREENSHOTS}/05-second-page.png` });
          }
        }
      }
    } else {
      // Already logged in, capture various pages
      await page.screenshot({ path: `${SCREENSHOTS}/04-main-app.png` });

      // Try clicking sidebar items
      const sidebarLinks = page.locator('nav a, [data-slot="sidebar"] a');
      const linkCount = await sidebarLinks.count();
      console.log(`Found ${linkCount} sidebar links`);

      for (let i = 0; i < Math.min(linkCount, 5); i++) {
        try {
          await sidebarLinks.nth(i).click();
          await page.waitForTimeout(1500);
          await page.screenshot({ path: `${SCREENSHOTS}/05-page-${i}.png` });
        } catch (e) {
          console.log(`Failed to click link ${i}:`, e.message);
        }
      }
    }
  });
});
