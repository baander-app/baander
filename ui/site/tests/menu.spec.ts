import { test as base, expect, type Page } from '@playwright/test';
import { openMenuPanel } from './site-menu';

const test = base.extend<{ localRequests: void }>({
  localRequests: [async ({ context, baseURL }, use) => {
    const outbound: string[] = [];
    await context.route('**/*', async (route) => {
      if (new URL(route.request().url()).origin !== new URL(baseURL!).origin) {
        outbound.push(route.request().url());
        await route.abort();
      } else {
        await route.continue();
      }
    });
    await use();
    expect(outbound, 'The menu must work without external services').toEqual([]);
  }, { auto: true }],
});

const navigation = (page: Page) => page.getByRole('navigation', { name: 'Main navigation' });
const panel = (page: Page, title: string) => navigation(page).locator('details.menu-panel')
  .filter({ has: page.locator(':scope > summary', { hasText: title }) });
const noHorizontalScroll = async (page: Page) =>
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);

test.describe('desktop panels', () => {
  test.use({ viewport: { width: 1440, height: 900 } });

  test('a keyboard user opens Resources onto its first entry, and Escape closes it back onto Resources', async ({ page }) => {
    await page.goto('/');
    const resources = panel(page, 'Resources');
    await page.getByRole('link', { name: 'Bånder home' }).focus();
    await page.keyboard.press('Tab');
    await expect(panel(page, 'Product').locator(':scope > summary')).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(resources.locator(':scope > summary')).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(resources).toHaveAttribute('open', '');
    await expect(resources.getByRole('link', { name: 'Operator manual', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(resources).not.toHaveAttribute('open');
    await expect(resources.getByRole('link', { name: 'Operator manual', exact: true })).toBeHidden();
    await expect(resources.locator(':scope > summary')).toBeFocused();
  });

  test('a panel closes when focus leaves it, on a click outside it, and when the other panel opens', async ({ page }) => {
    await page.goto('/');
    const product = panel(page, 'Product');
    const resources = panel(page, 'Resources');
    await resources.locator(':scope > summary').click();
    await expect(resources).toHaveAttribute('open', '');
    await resources.getByRole('link', { name: 'Third-party licensing', exact: true }).focus();
    await page.keyboard.press('Tab');
    await expect(navigation(page).getByRole('link', { name: /Source/ })).toBeFocused();
    await expect(resources).not.toHaveAttribute('open');

    await product.locator(':scope > summary').click();
    await expect(product).toHaveAttribute('open', '');
    // The open panel covers the middle of the page, so the click lands in the empty margin beside it.
    await page.mouse.click(40, 600);
    await expect(product).not.toHaveAttribute('open');

    await resources.locator(':scope > summary').click();
    await expect(resources).toHaveAttribute('open', '');
    await product.locator(':scope > summary').click();
    await expect(product).toHaveAttribute('open', '');
    await expect(resources).not.toHaveAttribute('open');
  });

  test('opening a panel moves nothing on the page', async ({ page }) => {
    await page.goto('/docs/operator-guide/');
    const layout = () => page.evaluate(() => ['.site-header', '.site-header nav', 'main'].map((selector) => {
      const box = document.querySelector(selector)!.getBoundingClientRect();
      return [box.x, box.y, box.width, box.height];
    }));
    const before = await layout();
    // Opened from a script, so the Layout Instability API counts any shift it causes as unexpected.
    const shifts = await page.evaluate(async () => {
      const recorded: number[] = [];
      new PerformanceObserver((list) => list.getEntries().forEach((entry) => recorded.push((entry as PerformanceEntry & { value: number }).value)))
        .observe({ type: 'layout-shift' });
      document.querySelector<HTMLDetailsElement>('details.menu-panel')!.open = true;
      await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
      return recorded;
    });
    await expect(panel(page, 'Product').getByRole('link', { name: 'Features', exact: true })).toBeVisible();
    expect(shifts).toEqual([]);
    expect(await layout()).toEqual(before);
  });

  test('the menu marks the current page, its section and the panel that holds it', async ({ page }) => {
    await page.goto('/docs/developer-guide/testing/');
    await expect(panel(page, 'Resources').locator(':scope > summary')).toHaveAttribute('aria-current', 'true');
    await expect(panel(page, 'Product').locator(':scope > summary')).not.toHaveAttribute('aria-current');
    const resources = await openMenuPanel(page, 'Resources');
    await expect(resources.getByRole('link', { name: 'Developer guide', exact: true })).toHaveAttribute('aria-current', 'true');
    await expect(resources.getByRole('link', { name: 'Operator manual', exact: true })).not.toHaveAttribute('aria-current');

    await page.goto('/features/player/');
    await expect(panel(page, 'Product').locator(':scope > summary')).toHaveAttribute('aria-current', 'true');
    await expect(panel(page, 'Resources').locator(':scope > summary')).not.toHaveAttribute('aria-current');
    const product = await openMenuPanel(page, 'Product');
    await expect(product.getByRole('link', { name: 'Player', exact: true })).toHaveAttribute('aria-current', 'page');
    await expect(product.getByRole('link', { name: 'Features', exact: true })).toHaveAttribute('aria-current', 'true');
    await expect(product.getByRole('link', { name: 'Equalizer', exact: true })).not.toHaveAttribute('aria-current');

    await page.goto('/concepts/');
    await expect((await openMenuPanel(page, 'Resources')).getByRole('link', { name: 'Concepts', exact: true }))
      .toHaveAttribute('aria-current', 'page');
  });

  test('feature groups use compact links, other entries keep descriptions, and the footer keeps every destination', async ({ page }) => {
    await page.goto('/');
    for (const title of ['Product', 'Resources'] as const) {
      const open = await openMenuPanel(page, title);
      const entries = open.locator('.menu-entry');
      expect(await entries.count()).toBeGreaterThan(0);
      for (const entry of await entries.all()) {
        await expect(entry.getByRole('link')).toBeVisible();
        if (await entry.evaluate((element) => element.closest('.menu-subgroup-compact') !== null)) {
          await expect(entry.locator('.menu-entry-description')).toHaveCount(0);
        } else {
          await expect(entry.locator('.menu-entry-description')).toHaveText(/\S/);
        }
      }
    }
    await expect(panel(page, 'Resources').locator('.menu-column-title')).toHaveText(['Run it', 'Build on it', 'Open source']);
    const hrefs = (selector: string) => page.locator(selector).evaluateAll((links) => links.map((link) => link.getAttribute('href')));
    const footer = page.getByRole('navigation', { name: 'Footer navigation' });
    expect(await hrefs('footer nav[aria-label="Footer navigation"] .footer-group a')).toEqual(await hrefs('.site-header details.menu-panel a'));
    await expect(footer.locator('.footer-panel-title')).toHaveText(['Product', 'Resources']);
    await expect(panel(page, 'Product').locator('.menu-group-title')).toHaveText(['For listening', 'For running it', 'For building on it']);
    await expect(page.locator('.footer-bottom').getByRole('link', { name: 'Source', exact: true }))
      .toHaveAttribute('href', 'https://github.com/baander-app/baander');
  });

  test('every Product link fits without scrolling at desktop sizes', async ({ page }) => {
    await page.goto('/');
    for (const viewport of [{ width: 1440, height: 900 }, { width: 1024, height: 768 }]) {
      await page.setViewportSize(viewport);
      const productBody = (await openMenuPanel(page, 'Product')).locator('.menu-panel-body');
      const product = await productBody.evaluate((element) => ({ client: element.clientHeight, scroll: element.scrollHeight }));
      expect(product.client).toBeLessThanOrEqual(400);
      expect(product.scroll).toBe(product.client);
      for (const link of await productBody.getByRole('link').all()) {
        await expect(link).toBeInViewport({ ratio: 1 });
      }
      await noHorizontalScroll(page);
    }
    const resourcesBody = (await openMenuPanel(page, 'Resources')).locator('.menu-panel-body');
    const resources = await resourcesBody.evaluate((element) => ({ client: element.clientHeight, scroll: element.scrollHeight }));
    expect(resources.scroll).toBe(resources.client);
  });

  test('footer aligns its desktop columns and stacks without overflow on phones', async ({ page }) => {
    await page.goto('/');
    const footer = page.locator('.site-footer');
    const intro = (await footer.locator('.footer-intro').boundingBox())!;
    const product = (await footer.locator('.footer-panel').first().boundingBox())!;
    const resources = (await footer.locator('.footer-panel').last().boundingBox())!;
    expect(product.y).toBe(intro.y);
    expect(resources.y).toBe(product.y);
    expect(product.x).toBeGreaterThanOrEqual(intro.x + intro.width);
    expect(resources.x).toBeGreaterThanOrEqual(product.x + product.width);
    for (const width of [390, 320]) {
      await page.setViewportSize({ width, height: 844 });
      const panels = await footer.locator('.footer-panel').evaluateAll((elements) => elements.map((element) => {
        const { x, y, bottom } = element.getBoundingClientRect();
        return { x, y, bottom };
      }));
      expect(panels[1].x).toBe(panels[0].x);
      expect(panels[1].y).toBeGreaterThanOrEqual(panels[0].bottom);
      await noHorizontalScroll(page);
    }
  });

  test('at 760px the Resources panel opens inside the viewport with its columns stacked', async ({ page }) => {
    await page.setViewportSize({ width: 760, height: 900 });
    await page.goto('/');
    const resources = await openMenuPanel(page, 'Resources');
    const body = resources.locator('.menu-panel-body');
    const width = await page.evaluate(() => document.documentElement.clientWidth);
    const box = (await body.boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(width);
    const columns = await resources.locator('.menu-column').evaluateAll((elements) => elements.map((element) => {
      const rect = element.getBoundingClientRect();
      return { left: rect.left, top: rect.top, bottom: rect.bottom };
    }));
    expect(columns).toHaveLength(3);
    for (let index = 1; index < columns.length; index++) {
      expect(columns[index].left).toBe(columns[0].left);
      expect(columns[index].top).toBeGreaterThanOrEqual(columns[index - 1].bottom);
    }
    await expect(resources.getByRole('link', { name: 'Third-party licensing', exact: true })).toBeVisible();
    await noHorizontalScroll(page);
  });
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false, viewport: { width: 1440, height: 900 } });

  test('both panels open and close by click and every entry link is reachable', async ({ page }) => {
    await page.goto('/');
    const product = panel(page, 'Product');
    const resources = panel(page, 'Resources');
    for (const disclosure of [product, resources]) {
      await disclosure.locator(':scope > summary').click();
      await expect(disclosure).toHaveAttribute('open', '');
      const links = disclosure.locator('.menu-panel-body a');
      expect(await links.count()).toBeGreaterThan(0);
      for (const link of await links.all()) {
        await expect(link).toBeVisible();
        await expect(link).toHaveAttribute('href', /^\//);
      }
    }
    await expect(product).not.toHaveAttribute('open');
    await resources.locator(':scope > summary').click();
    await expect(resources).not.toHaveAttribute('open');
  });
});

for (const viewport of [{ width: 390, height: 844 }, { width: 320, height: 640 }]) {
  test(`${viewport.width}px: one Menu disclosure lists both groups with Source last, without horizontal scroll`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await page.goto('/');
    await expect(navigation(page)).toBeVisible();
    await expect(panel(page, 'Product')).toBeHidden();
    await expect(panel(page, 'Resources')).toBeHidden();
    await expect(navigation(page).locator('.menu-source')).toBeHidden();
    const menu = navigation(page).locator('details.menu-phone');
    await expect(menu.locator(':scope > summary')).toHaveText('Menu');
    await noHorizontalScroll(page);
    await openMenuPanel(page, 'Resources');
    await expect(menu).toHaveAttribute('open', '');
    await expect(menu.locator('.menu-panel-title')).toHaveText(['Product', 'Resources']);
    await expect(menu.getByRole('link', { name: 'Features', exact: true })).toBeVisible();
    await expect(menu.getByRole('link', { name: 'Third-party licensing', exact: true })).toBeVisible();
    await expect(menu.locator('.menu-panel-body a').last()).toHaveAccessibleName(/^Source/);
    await expect(menu.locator('.menu-panel-body a').last()).toHaveAttribute('href', 'https://github.com/baander-app/baander');
    await noHorizontalScroll(page);
  });
}

test('390x500: the last entry of the open Menu scrolls into view and can be activated', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 500 });
  await page.goto('/');
  const menu = await openMenuPanel(page, 'Product');
  const body = menu.locator('.menu-panel-body');
  expect(await body.evaluate((element) => element.scrollHeight > element.clientHeight), 'The open menu scrolls within itself').toBe(true);
  const last = menu.locator('.menu-panel-body a').last();
  await last.scrollIntoViewIfNeeded();
  await expect(last).toBeInViewport();
  // A trial click runs every actionability check, including that nothing covers the link, without
  // following it to the source repository.
  await last.click({ trial: true });
});
