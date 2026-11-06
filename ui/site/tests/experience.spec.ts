import { test as base, expect, type Locator } from '@playwright/test';

const test = base.extend<{ localRequests: void }>({
  localRequests: [async ({ context, baseURL }, use) => {
    const outbound: string[] = [];
    await context.route('**/*', async (route) => {
      if (new URL(route.request().url()).origin !== new URL(baseURL!).origin) {
        outbound.push(route.request().url());
        await route.abort();
      } else await route.continue();
    });
    await use();
    expect(outbound).toEqual([]);
  }, { auto: true }],
});

async function loadedImage(image: Locator): Promise<void> {
  await image.scrollIntoViewIfNeeded();
  await expect(image).toBeVisible();
  await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0)).toBe(true);
  await expect(image).toHaveCSS('filter', 'none');
  const light = await image.evaluate(() => document.documentElement.dataset.theme
    ? document.documentElement.dataset.theme === 'balanced'
    : matchMedia('(prefers-color-scheme: light)').matches);
  await expect(image).toHaveAttribute('src', light ? /-light\.webp$/ : /(?<!-light)\.webp$/);
}

test('equalizer slides respond to controls and keyboard, and stay still without input', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.clock.install();
  await page.goto('/');
  const carousel = page.locator('[data-carousel]');
  const slides = carousel.locator('[data-carousel-slide]');
  const dots = carousel.locator('[data-carousel-index]');
  await expect(slides).toHaveCount(4);
  const active = async (index: number) => {
    for (let position = 0; position < 4; position++) {
      if (position === index) await expect(slides.nth(position)).toBeVisible();
      else await expect(slides.nth(position)).toBeHidden();
    }
    await expect(dots.nth(index)).toHaveAttribute('aria-current', 'true');
    await expect(carousel.getByRole('status')).toContainText(`${index + 1} of 4`);
    await loadedImage(slides.nth(index).getByRole('img'));
  };
  await active(0);
  await page.clock.fastForward(60_000);
  await active(0);
  await carousel.getByRole('button', { name: 'Next equalizer feature' }).click();
  await active(1);
  await carousel.getByRole('button', { name: 'Show audio processing' }).click();
  await active(2);
  await dots.nth(2).press('ArrowRight');
  await active(3);
  await expect(dots.nth(3)).toBeFocused();
  await dots.nth(3).press('ArrowRight');
  await active(0);
  await expect(dots.nth(0)).toBeFocused();
  await carousel.getByRole('button', { name: 'Previous equalizer feature' }).click();
  await active(3);
  await carousel.focus();
  await carousel.press('ArrowLeft');
  await active(2);
  await expect(carousel).toBeFocused();
});

for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
  test(`${viewport.width}px: the header has one size and nav position whether or not the appearance script has run`, async ({ page, browser, baseURL }) => {
    const geometry = (target: import('@playwright/test').Page) => target.evaluate(() => ({
      header: document.querySelector('.site-header')!.getBoundingClientRect().height,
      navLeft: document.querySelector('.site-header nav')!.getBoundingClientRect().left,
      navTop: document.querySelector('.site-header nav')!.getBoundingClientRect().top,
    }));
    await page.setViewportSize(viewport);
    await page.goto('/');
    await expect(page.locator('[data-appearance-toggle]')).toBeVisible();
    const withScript = await geometry(page);
    const context = await browser.newContext({ javaScriptEnabled: false, baseURL, viewport });
    await context.route('**/*', (route) => new URL(route.request().url()).origin === new URL(baseURL!).origin ? route.continue() : route.abort());
    try {
      const plain = await context.newPage();
      await plain.goto('/');
      await expect(plain.locator('[data-appearance-toggle]')).toBeHidden();
      expect(await geometry(plain)).toEqual(withScript);
    } finally {
      await context.close();
    }
  });
}

test.describe('static homepage', () => {
  test.use({ javaScriptEnabled: false });
  test('without JavaScript every equalizer feature and screenshot remains readable', async ({ page }) => {
    await page.goto('/');
    const slides = page.locator('[data-carousel-slide]');
    await expect(slides).toHaveCount(4);
    await expect(page.locator('[data-carousel-controls]')).toBeHidden();
    await expect(page.locator('[data-appearance-toggle]')).toBeHidden();
    for (let index = 0; index < 4; index++) {
      await expect(slides.nth(index).getByRole('heading')).toBeVisible();
      await loadedImage(slides.nth(index).getByRole('img'));
    }
  });
});

for (const colorScheme of ['light', 'dark'] as const) {
  test(`${colorScheme} system preference follows changes until a persistent manual choice`, async ({ page }) => {
    await page.emulateMedia({ colorScheme });
    await page.goto('/');
    const initial = colorScheme === 'light' ? 'balanced' : 'dark';
    const opposite = colorScheme === 'light' ? 'dark' : 'light';
    await expect(page.locator('html')).toHaveAttribute('data-theme', initial);
    await page.emulateMedia({ colorScheme: opposite });
    await expect(page.locator('html')).toHaveAttribute('data-theme', opposite === 'light' ? 'balanced' : 'dark');
    await page.getByRole('button', { name: `Switch to ${colorScheme} mode` }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', initial);
    await page.emulateMedia({ colorScheme });
    await page.emulateMedia({ colorScheme: opposite });
    await expect(page.locator('html')).toHaveAttribute('data-theme', initial);
    await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Manual', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', initial);
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', initial);
    await expect(page.getByRole('button', { name: `Switch to ${opposite} mode` })).toBeVisible();
  });

  test(`390px ${colorScheme}: header, feature images and carousel fit the viewport`, async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.emulateMedia({ colorScheme });
    await page.goto('/');
    const contained = async () => expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await expect(page.getByRole('navigation', { name: 'Main navigation' })).toBeVisible();
    await expect(page.getByRole('button', { name: /Switch to .* mode/ })).toBeVisible();
    await contained();
    await loadedImage(page.getByRole('img', { name: /music library in the browser/ }));
    const carousel = page.locator('[data-carousel]');
    for (let index = 0; index < 4; index++) {
      await carousel.locator('[data-carousel-index]').nth(index).click();
      await loadedImage(carousel.locator('[data-carousel-slide]').nth(index).getByRole('img'));
      await contained();
    }
    await loadedImage(page.getByRole('img', { name: /playlists view in the browser/ }));
    await contained();
    await loadedImage(page.getByRole('img', { name: /playback destination menu/ }));
    await contained();
  });
}

test('appearance choice still works for the page when storage is blocked', async ({ page }) => {
  await page.addInitScript(() => {
    Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('Storage blocked', 'SecurityError'); } });
  });
  await page.emulateMedia({ colorScheme: 'light' });
  await page.goto('/');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'balanced');
  await page.getByRole('button', { name: 'Switch to dark mode' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.emulateMedia({ colorScheme: 'light' });
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await page.getByRole('button', { name: 'Switch to light mode' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'balanced');
});

// Inspect the start in the click's browser task so short transitions cannot finish
// between the input and the assertion on a busy runner.
test('theme screenshots use real image pairs in a stable unfiltered frame and crossfade briefly', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'no-preference' });
  await page.goto('/');
  const figure = page.locator('.hero-screenshot');
  const exposed = figure.getByRole('img');
  await expect(exposed).toHaveCount(1);
  await expect(exposed).toHaveAttribute('src', '/assets/site/screenshots/music.webp');
  await loadedImage(exposed);
  const frameBefore = await figure.boundingBox();
  const transition = await page.evaluate(() => {
    document.querySelector<HTMLButtonElement>('[data-appearance-toggle]')!.click();
    return [...document.querySelectorAll('.hero-screenshot img')].map((image) => {
      const style = getComputedStyle(image);
      const rect = image.getBoundingClientRect();
      return { width: rect.width, height: rect.height, filter: style.filter, willChange: style.willChange, transition: style.transitionDuration };
    });
  });
  expect(transition).toHaveLength(2);
  expect(transition[0].width).toBe(transition[1].width);
  expect(transition[0].height).toBe(transition[1].height);
  for (const image of transition) {
    expect(image.filter).toBe('none');
    expect(image.willChange).toBe('opacity');
    expect(image.transition).toContain('0.2s');
  }
  await expect(exposed).toHaveCount(1);
  await expect(exposed).toHaveAttribute('src', '/assets/site/screenshots/music-light.webp');
  await loadedImage(exposed);
  await expect.poll(() => figure.locator('img').first().evaluate((image) => getComputedStyle(image).willChange)).toBe('auto');
  const frameAfter = await figure.boundingBox();
  expect(frameAfter?.width).toBe(frameBefore?.width);
  expect(frameAfter?.height).toBe(frameBefore?.height);
  await page.getByRole('button', { name: 'Switch to dark mode' }).click();
  await expect(exposed).toHaveCount(1);
  await expect(exposed).toHaveAttribute('src', '/assets/site/screenshots/music.webp');
  await expect.poll(() => figure.locator('img').first().evaluate((image) => getComputedStyle(image).willChange)).toBe('auto');
});

test('next and previous animate in opposite directions and release compositor hints', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await page.goto('/');
  const carousel = page.locator('[data-carousel]');
  await carousel.scrollIntoViewIfNeeded();
  for (const [direction, sign, number] of [['next', 1, 2], ['previous', -1, 1]] as const) {
    const start = await carousel.evaluate((element, direction) => {
      element.querySelector<HTMLButtonElement>(`[data-carousel-${direction}]`)!.click();
      const slide = element.querySelector<HTMLElement>('[data-carousel-slide]:not([hidden])')!;
      const style = getComputedStyle(slide);
      return { animation: style.animationName, translate: new DOMMatrixReadOnly(style.transform).m41, hint: style.willChange };
    }, direction);
    expect(start.animation).not.toBe('none');
    expect(start.translate * sign).toBeGreaterThan(0);
    expect(start.hint).toContain('transform');
    await expect(carousel.getByRole('status')).toContainText(`${number} of 4`);
    await expect.poll(() => carousel.locator('[data-carousel-slide]:not([hidden])').evaluate((slide) => getComputedStyle(slide).willChange)).toBe('auto');
  }
});

test('reduced motion suppresses effects and cancels transitions already in progress', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'no-preference' });
  await page.goto('/');
  const heroImage = page.locator('.hero-screenshot img').first();
  const slide = page.locator('[data-carousel-slide]:not([hidden])');
  const started = await page.evaluate(() => {
    document.querySelector<HTMLButtonElement>('[data-appearance-toggle]')!.click();
    document.querySelector<HTMLButtonElement>('[data-carousel-next]')!.click();
    return {
      image: getComputedStyle(document.querySelector('.hero-screenshot img')!).willChange,
      slide: getComputedStyle(document.querySelector('[data-carousel-slide]:not([hidden])')!).animationName,
    };
  });
  expect(started.image).toBe('opacity');
  expect(started.slide).not.toBe('none');
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await expect.poll(() => heroImage.evaluate((image) => getComputedStyle(image).willChange)).toBe('auto');
  await expect.poll(() => slide.evaluate((element) => getComputedStyle(element).animationName)).toBe('none');
  await expect.poll(() => slide.evaluate((element) => getComputedStyle(element).willChange)).toBe('auto');
  await page.getByRole('button', { name: 'Switch to dark mode' }).click();
  await page.getByRole('button', { name: 'Previous equalizer feature' }).click();
  expect(await heroImage.evaluate((image) => getComputedStyle(image).transitionDuration)).toBe('0s');
  expect(await slide.evaluate((element) => getComputedStyle(element).animationName)).toBe('none');
  await expect(page.locator('.hero-screenshot').getByRole('img')).toHaveAttribute('src', '/assets/site/screenshots/music.webp');
  await expect(page.locator('[data-carousel-status]')).toContainText('1 of 4');
});
