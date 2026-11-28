import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test as base, expect } from '@playwright/test';

// The motion page: its transitions equal the design language's Motion table, and each duration token
// lists the stylesheets and application files that use it.

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
    expect(outbound, 'The motion page must load without external services').toEqual([]);
  }, { auto: true }],
});

const repository = fileURLToPath(new URL('../../../', import.meta.url));
const design = readFileSync(join(repository, 'ui/DESIGN.md'), 'utf8');
const theme = readFileSync(join(repository, 'ui/design-tokens/build/web/theme.css'), 'utf8');

/** The body rows of the first table under `## <heading>`, each as its trimmed cells. */
function table(heading: string): string[][] {
  const body = design.split(`\n## ${heading}\n`)[1].split(/\n## /)[0];
  return body.split('\n').filter((line) => line.startsWith('|')).slice(2)
    .map((line) => line.slice(1, -1).split('|').map((cell) => cell.trim()));
}

function files(directory: string, extensions: string[]): string[] {
  return readdirSync(join(repository, directory), { recursive: true, encoding: 'utf8' })
    .map((entry) => join(directory, entry))
    .filter((path) => extensions.some((extension) => path.endsWith(`.${extension}`)) && statSync(join(repository, path)).isFile());
}

test('the transitions table equals the Motion table of the design language, in order', async ({ page }) => {
  await page.goto('/design-system/motion/');
  const rows = await page.getByRole('region', { name: 'Transitions and their durations' }).locator('tbody tr')
    .evaluateAll((trs) => trs.map((tr) => [...tr.querySelectorAll('th, td')].map((cell) => cell.textContent!.trim())));
  expect(rows).toEqual(table('Motion').map((cells) => cells.map((cell) => cell.replaceAll('`', ''))));
  await expect(page.getByRole('heading', { level: 2, name: 'Motion', exact: true })).toBeVisible();
  await expect(page.locator('section[aria-labelledby="motion"] li', { hasText: 'ease-out' }).first()).toBeVisible();
});

test('each duration token lists the files that use it, and the hover token equals the Hover state row', async ({ page }) => {
  await page.goto('/design-system/motion/');
  const root = /:root\s*\{([^}]*)\}/.exec(theme)![1];
  const tokens = [...root.matchAll(/(--duration-[a-z0-9-]+):\s*([^;]+);/g)].map(([, name, value]) => [name, value.trim()]);
  expect(tokens.length).toBe(4);
  const region = page.getByRole('region', { name: 'Duration tokens and where they are used' });
  const rows = await region.locator('tbody tr').evaluateAll((trs) => trs.map((tr) => ({
    token: tr.querySelector('th')!.textContent!.trim(),
    value: tr.querySelectorAll('td')[0].textContent!.trim(),
    users: [...tr.querySelectorAll('td code')].map((code) => code.textContent!.trim()),
    text: tr.querySelectorAll('td')[1].textContent!.trim(),
  })));
  expect(rows.map((row) => [row.token, row.value])).toEqual(tokens);

  const website = files('ui/site/assets', ['css']).filter((path) => !path.includes('/', 'ui/site/assets/'.length));
  const application = files('ui/web/src', ['css', 'ts', 'tsx']);
  for (const row of rows) {
    const using = (paths: string[]) => paths.filter((path) => readFileSync(join(repository, path), 'utf8').includes(`var(${row.token})`)).sort();
    const expected = [...using(website), ...using(application)];
    expect([...row.users].sort(), row.token).toEqual(expected.sort());
    if (expected.length === 0) expect(row.text, row.token).toBe('No stylesheet uses it');
  }
  const hover = table('Motion').find(([transition]) => transition === 'Hover state')!;
  expect(rows.find((row) => row.token === '--duration-hover')?.value).toBe(hover[1]);
  for (const cell of await region.locator('td').all()) await expect(cell).toHaveAttribute('data-pagefind-ignore');
});

test('the page states the anti-patterns duration limit that governs application motion', async ({ page }) => {
  await page.goto('/design-system/motion/');
  const instead = table('Anti-patterns (do not do these)').map(([, text]) => text).find((text) => /\d+ms or less/.test(text))!;
  const limit = /(\d+ms) or less/.exec(instead)![1];
  await expect(page.locator('section[aria-labelledby="motion-tokens"] > p').last())
    .toHaveText(new RegExp(`^The ${limit} limit governs the application’s interface motion: .*“${instead}”\\.$`));
});

for (const width of [1440, 390]) {
  test(`${width}px: the motion page stays within the viewport`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/design-system/motion/');
    await page.locator('details').evaluateAll((disclosures) => disclosures.forEach((disclosure) => { (disclosure as HTMLDetailsElement).open = true; }));
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  });
}
