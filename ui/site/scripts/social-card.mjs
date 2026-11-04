// Renders the 1200 × 630 link-preview card from the built site's own styles, fonts, logo and a
// screenshot. Run after `make website-build`: node ui/site/scripts/social-card.mjs
import { readFile } from 'node:fs/promises';
import { extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const site = resolve(root, 'var/website');
const output = resolve(root, 'ui/site/assets/social-card.png');
const types = { '.css': 'text/css', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.woff2': 'font/woff2' };

const card = `<!doctype html>
<html data-theme="dark">
<head>
  <meta charset="utf-8">
  <link rel="stylesheet" href="/assets/site/theme.css">
  <link rel="stylesheet" href="/assets/site/site.css">
  <style>
    html, body { margin: 0; width: 1200px; height: 630px; overflow: hidden; background: var(--color-background); }
    .card {
      position: relative; box-sizing: border-box; width: 1200px; height: 630px; padding: 64px 72px;
      background: radial-gradient(900px 500px at 85% 110%, color-mix(in oklab, var(--color-accent-text) 22%, transparent), transparent 70%), var(--color-background);
    }
    .brand { display: flex; align-items: center; gap: 18px; font: 600 34px/1 var(--font-sans); color: var(--color-foreground); }
    .brand img { width: 56px; height: 56px; }
    h1 { max-width: 560px; margin: 56px 0 20px; font: 600 64px/1.08 var(--font-sans); letter-spacing: -.02em; color: var(--color-foreground); }
    p { max-width: 480px; margin: 0; font: 400 24px/1.45 var(--font-sans); color: var(--color-muted-foreground); }
    .url { position: absolute; left: 72px; bottom: 56px; font: 500 20px/1 var(--font-sans); letter-spacing: .02em; color: var(--color-accent-text); }
    .shot { position: absolute; left: 630px; top: 110px; width: 800px; border: 1px solid var(--color-border); border-radius: 14px; box-shadow: 0 30px 80px rgb(0 0 0 / .55); }
  </style>
</head>
<body>
  <div class="card">
    <div class="brand"><img src="/assets/site/logo.svg" alt="">Bånder</div>
    <h1>Your collection. Ready to play.</h1>
    <p>Browse your music and movies in a media server you run on your own hardware.</p>
    <div class="url">baander.app</div>
    <img class="shot" src="/assets/site/screenshots/destinations.webp" alt="">
  </div>
</body>
</html>`;

const browser = await chromium.launch();
try {
  const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 });
  await page.route('http://card.local/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/') return route.fulfill({ contentType: 'text/html', body: card });
    const file = resolve(site, '.' + path);
    if (!file.startsWith(site + '/')) return route.fulfill({ status: 404 });
    return route.fulfill({ contentType: types[extname(file)] ?? 'application/octet-stream', body: await readFile(file) });
  });
  await page.goto('http://card.local/');
  await page.evaluate(() => document.fonts.ready);
  await page.waitForFunction(() => [...document.images].every((image) => image.complete && image.naturalWidth > 0));
  await page.screenshot({ path: output });
  console.log(`Wrote ${output}`);
} finally {
  await browser.close();
}
