/** The Vite dev server may take any port in this range, so the policy allows all of them. */
const DEV_PORT_MIN = 5150;
const DEV_PORT_MAX = 5200;

export interface RendererEnvironment {
  document: Document;
  /** True when the renderer is served by the Vite dev server instead of `file://`. */
  isDevServer: boolean;
  getServerUrl: () => Promise<string | undefined>;
  publishApiUrl: (origin: string) => void;
  loadApp: () => Promise<unknown>;
  openSetup: () => Promise<unknown>;
}

/** The origin of a stored server URL, so no path or other trailing text reaches the policy. */
export function serverOrigin(value: string | undefined): string {
  if (!value) throw new Error('No server URL is configured.');
  let url: URL;
  try {
    url = new URL(value);
  } catch {
    throw new Error('The configured server URL is not a valid URL.');
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new Error('The configured server URL does not use HTTP or HTTPS.');
  }
  return url.origin;
}

function devPortOrigins(): string[] {
  const origins: string[] = [];
  for (let port = DEV_PORT_MIN; port <= DEV_PORT_MAX; port++) {
    origins.push(`http://localhost:${port}`, `ws://localhost:${port}`);
  }
  return origins;
}

export function contentSecurityPolicy(serverUrl: string, isDevServer: boolean): string {
  const api = serverOrigin(serverUrl);
  const dev = isDevServer ? devPortOrigins() : [];
  const directive = (name: string, ...sources: string[]) => [name, ...sources].join(' ');

  return [
    directive('default-src', "'self'"),
    directive('script-src', "'self'", "'unsafe-eval'", 'blob:', ...dev),
    directive('style-src', "'self'", "'unsafe-inline'", ...dev),
    // blob: for album art through object URLs, https: for remote station logos and covers
    directive('img-src', "'self'", 'data:', 'blob:', 'https:', ...dev, api),
    directive('connect-src', "'self'", 'blob:', 'https:', ...dev, api, 'baander:'),
    directive('font-src', "'self'", 'data:', ...dev),
    directive('media-src', "'self'", 'blob:', ...dev, api),
    directive('worker-src', "'self'", 'blob:', 'baander:'),
    directive('object-src', "'none'"),
    directive('frame-src', "'none'"),
  ].join('; ');
}

function installPolicy(document: Document, policy: string): void {
  document.querySelector('meta[http-equiv="Content-Security-Policy"]')?.remove();
  const meta = document.createElement('meta');
  meta.httpEquiv = 'Content-Security-Policy';
  meta.content = policy;
  document.head.insertBefore(meta, document.head.firstChild);
}

function showStartupError(document: Document, error: unknown, openSetup: () => Promise<unknown>): void {
  const view = document.createElement('section');
  view.setAttribute('role', 'alert');

  const title = document.createElement('h1');
  title.textContent = 'Bånder could not start';

  const detail = document.createElement('p');
  detail.textContent = error instanceof Error ? error.message : String(error);

  const configure = document.createElement('button');
  configure.type = 'button';
  configure.textContent = 'Configure server';
  configure.addEventListener('click', () => {
    openSetup().catch((setupError: unknown) => console.error('[bootstrap] Could not open setup:', setupError));
  });

  view.append(title, detail, configure);
  (document.getElementById('baanderapproot') ?? document.body).replaceChildren(view);
}

/**
 * Loads the web client only after the content policy for the configured server is in place.
 * Any failure on the way shows an error view with a way back to setup instead.
 */
export async function startRenderer(environment: RendererEnvironment): Promise<void> {
  const { document } = environment;
  try {
    const origin = serverOrigin(await environment.getServerUrl());
    environment.publishApiUrl(origin);
    installPolicy(document, contentSecurityPolicy(origin, environment.isDevServer));
    await environment.loadApp();
  } catch (error) {
    console.error('[bootstrap] The web client could not start:', error);
    showStartupError(document, error, environment.openSetup);
  }
}
