import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync, cpSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const repository = fileURLToPath(new URL('../../../', import.meta.url));

test('a maintained chapter edit updates its page and the real search index together', async ({ page, baseURL }) => {
  const fixture = mkdtempSync(join(tmpdir(), 'baander-site-edit-'));
  try {
    for (const directory of ['docs-book/part-1-operator-guide', 'docs-book/part-2-developer-guide', 'ui/site/templates']) {
      mkdirSync(join(fixture, directory), { recursive: true });
    }
    writeFileSync(join(fixture, 'docs-book/README.md'), '# Manual\n');
    writeFileSync(join(fixture, 'docs-book/part-1-operator-guide/README.md'), '# Operator\n[Library](library.md)\n');
    writeFileSync(join(fixture, 'docs-book/part-2-developer-guide/README.md'), '# Developer\n');
    for (const template of ['home', 'page', '404']) {
      writeFileSync(join(fixture, `ui/site/templates/${template}.php`), '<!doctype html><html lang="en"><head><title><?= htmlspecialchars($page["title"]) ?></title></head><body><main data-pagefind-body><?= $page["html"] ?></main></body></html>');
    }
    const chapter = join(fixture, 'docs-book/part-1-operator-guide/library.md');
    const build = (word: string, version: string) => {
      writeFileSync(chapter, `# Library\n\n## Scanning\n\n${word} is the current catalog checkpoint.\n`);
      execFileSync('php', ['-r', 'require $argv[1]; (new Baander\\Tooling\\Website\\WebsiteBuilder($argv[2], "https://github.com/baander-app/baander/tree/master"))->build();', join(repository, 'scripts/bootstrap.php'), fixture]);
      execFileSync('corepack', ['yarn', 'pagefind', '--site', join(fixture, 'var/website')], { cwd: join(repository, 'ui/site'), stdio: 'pipe' });
      cpSync(join(fixture, 'var/website'), join(fixture, version), { recursive: true });
    };
    build('amberful', 'before');
    build('quartzful', 'after');

    const origin = new URL(baseURL!).origin;
    await page.route('**/*', async (route) => {
      const url = new URL(route.request().url());
      if (url.origin !== origin) return route.abort();
      if (!url.pathname.startsWith('/__fixture/')) return route.continue();
      const relative = decodeURIComponent(url.pathname.slice('/__fixture/'.length));
      const path = resolve(fixture, relative);
      if (!path.startsWith(fixture + sep)) return route.abort();
      const contentType = path.endsWith('.js') ? 'text/javascript' : path.endsWith('.wasm') ? 'application/wasm' : path.endsWith('.html') ? 'text/html' : 'application/octet-stream';
      await route.fulfill({ status: 200, contentType, body: readFileSync(path) });
    });
    await page.goto('/');
    for (const [version, present, absent] of [['before', 'amberful', 'quartzful'], ['after', 'quartzful', 'amberful']]) {
      const html = readFileSync(join(fixture, version, 'docs/operator-guide/library/index.html'), 'utf8');
      expect(html).toContain(present);
      expect(html).not.toContain(absent);
      const counts = await page.evaluate(async ({ version, present, absent }) => {
        const index = await import(`/__fixture/${version}/pagefind/pagefind.js`);
        return [(await index.search(present)).results.length, (await index.search(absent)).results.length];
      }, { version, present, absent });
      expect(counts).toEqual([1, 0]);
    }
  } finally {
    rmSync(fixture, { recursive: true, force: true });
  }
});
