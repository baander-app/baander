import type { Page } from '@playwright/test';
import { resolve } from 'node:path';

/**
 * Opens a test-only player page from ./fixtures as if the server served it at /__e2e/,
 * so the page's player requests stay same-origin. The pages never ship in the
 * application image.
 */
export async function openFixturePage(page: Page, serverUrl: string, name: string): Promise<void> {
  const url = `${serverUrl}/__e2e/${name}`;
  await page.route(url, (route) => route.fulfill({ path: resolve(__dirname, 'fixtures', name), contentType: 'text/html' }));
  await page.goto(url, { waitUntil: 'load' });
}
