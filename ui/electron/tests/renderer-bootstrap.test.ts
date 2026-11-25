import assert from 'node:assert/strict';
import { test } from 'node:test';
import { contentSecurityPolicy, startRenderer } from '../src/renderer/bootstrap.ts';

class FakeElement {
  children: FakeElement[] = [];
  listeners = new Map<string, () => void>();
  textContent = '';
  attributes = new Map<string, string>();
  [property: string]: unknown;

  constructor(readonly tagName: string) {}

  setAttribute(name: string, value: string) {
    this.attributes.set(name, value);
  }

  append(...nodes: FakeElement[]) {
    this.children.push(...nodes);
  }

  replaceChildren(...nodes: FakeElement[]) {
    this.children = nodes;
  }

  addEventListener(type: string, listener: () => void) {
    this.listeners.set(type, listener);
  }

  get firstChild() {
    return this.children[0] ?? null;
  }

  insertBefore(node: FakeElement, reference: FakeElement | null) {
    const index = reference ? this.children.indexOf(reference) : this.children.length;
    this.children.splice(index, 0, node);
  }

  find(predicate: (element: FakeElement) => boolean): FakeElement | undefined {
    for (const child of this.children) {
      if (predicate(child)) return child;
      const nested = child.find(predicate);
      if (nested) return nested;
    }
    return undefined;
  }
}

function fakeDocument() {
  const head = new FakeElement('head');
  const body = new FakeElement('body');
  const root = new FakeElement('div');
  body.append(root);
  const document = {
    head,
    body,
    createElement: (tagName: string) => new FakeElement(tagName),
    getElementById: (id: string) => (id === 'baanderapproot' ? root : null),
    querySelector: () => head.find((element) => element.tagName === 'meta') ?? null,
  };
  return { document, head, root };
}

function renderer(serverUrl: string | undefined, loadApp: () => Promise<unknown> = async () => {}) {
  const { document, head, root } = fakeDocument();
  const steps: string[] = [];
  const environment = {
    document: document as unknown as Document,
    isDevServer: false,
    getServerUrl: async () => serverUrl,
    publishApiUrl: (origin: string) => {
      steps.push(`api ${origin}`);
    },
    loadApp: async () => {
      steps.push(head.find((element) => element.tagName === 'meta') ? 'app with policy' : 'app without policy');
      return loadApp();
    },
    openSetup: async () => {
      steps.push('setup');
      return true;
    },
  };
  return { environment, head, root, steps };
}

function directives(policy: string): Map<string, string[]> {
  return new Map(policy.split(';').map((directive) => {
    const [name, ...sources] = directive.trim().split(/\s+/);
    return [name, sources];
  }));
}

test('the policy names only the origin of a stored server URL', () => {
  const policy = directives(contentSecurityPolicy('https://api.baander.app/path;script-src *', false));

  assert.deepEqual(policy.get('script-src'), ["'self'", "'unsafe-eval'", 'blob:']);
  assert.deepEqual(policy.get('connect-src'), ["'self'", 'blob:', 'https:', 'https://api.baander.app', 'baander:']);
  const hosts = [...policy.values()].flat().filter((source) => source.includes('//'));
  assert.deepEqual(new Set(hosts), new Set(['https://api.baander.app']));
  assert.equal([...policy.values()].flat().includes('*'), false);
});

test('the policy refuses a stored value that is not an HTTP(S) URL', () => {
  for (const value of ['', 'api.baander.app', 'javascript:alert(1)', 'file:///etc/passwd']) {
    assert.throws(() => contentSecurityPolicy(value, false), /server URL/, value);
  }
});

test('a configured renderer publishes the origin and installs the policy before it loads the web client', async () => {
  const { environment, head, steps } = renderer('https://api.baander.app/path');

  await startRenderer(environment);

  assert.deepEqual(steps, ['api https://api.baander.app', 'app with policy']);
  const meta = head.find((element) => element.tagName === 'meta');
  assert.equal(meta?.httpEquiv, 'Content-Security-Policy');
  assert.match(String(meta?.content), /connect-src [^;]*https:\/\/api\.baander\.app /);
});

for (const [description, serverUrl] of [
  ['no server is configured', undefined],
  ['the stored server URL is empty', ''],
  ['the stored server URL is not a URL', 'not a url'],
] as const) {
  test(`when ${description}, the renderer shows an error and never imports the web client`, async () => {
    const { environment, head, root, steps } = renderer(serverUrl);

    await startRenderer(environment);

    assert.deepEqual(steps, []);
    assert.equal(head.find((element) => element.tagName === 'meta'), undefined);
    const alert = root.find((element) => element.attributes.get('role') === 'alert');
    assert.ok(alert, 'an error view replaces the application root');
    assert.match(alert.find((element) => element.tagName === 'h1')?.textContent ?? '', /could not start/);

    const button = alert.find((element) => element.tagName === 'button');
    assert.equal(button?.textContent, 'Configure server');
    button?.listeners.get('click')?.();
    await Promise.resolve();
    assert.deepEqual(steps, ['setup']);
  });
}

test('a web client that fails to load leaves the error view in its place', async () => {
  const { environment, root, steps } = renderer('https://api.baander.app', async () => {
    throw new Error('chunk failed');
  });

  await startRenderer(environment);

  assert.deepEqual(steps, ['api https://api.baander.app', 'app with policy']);
  assert.ok(root.find((element) => element.attributes.get('role') === 'alert'));
});
