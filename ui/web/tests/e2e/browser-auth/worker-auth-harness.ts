import { test as base, expect, type Page } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { createServer, type Server } from 'node:http'
import { fileURLToPath } from 'node:url'
import { transformSync } from 'esbuild'

interface Observation {
  host: string
  path: string
  method: string
  authorization: string | null
  proof: string | null
  cookie: string | null
  range: string | null
}
interface LocalServer {
  server: Server
  webOrigin: string
  apiOrigin: string
  foreignOrigin: string
  observations: Observation[]
}
interface Harness {
  apiOrigin: string
  foreignOrigin: string
  observations: Observation[]
  image(url: string): Promise<'loaded' | 'failed'>
}

const hosts = ['web.baander.app', 'api.baander.app', 'foreign.baander.app']
const html = `<!doctype html><title>Worker transport fixture</title><script>
window.configureWorker = async (apiOrigin) => {
  navigator.serviceWorker.addEventListener('message', event => {
    if (event.data.type !== 'SW_SIGN_DPOP') return;
    event.ports[0].postMessage({type: 'SW_DPOP_PROOF', proof: 'transport-fixture-proof'});
    event.ports[0].close();
  });
  const registration = await navigator.serviceWorker.register('/auth-stream-worker.js');
  await navigator.serviceWorker.ready;
  if (!navigator.serviceWorker.controller) {
    await new Promise(resolve => navigator.serviceWorker.addEventListener('controllerchange', resolve, {once: true}));
  }
  registration.active.postMessage({type: 'SW_SET_API_URL', apiUrl: apiOrigin});
  registration.active.postMessage({type: 'SW_SET_TOKEN', token: 'transport-fixture-token'});
};
</script>`

async function startServer(): Promise<LocalServer> {
  const workerPath = fileURLToPath(new URL('../../../src/features/player/services/auth-stream-worker.ts', import.meta.url))
  const worker = transformSync(readFileSync(workerPath, 'utf8'), { loader: 'ts' }).code
  const observations: Observation[] = []
  const server = createServer((request, response) => {
    const host = (request.headers.host ?? '').split(':')[0]
    if (!hosts.includes(host)) {
      response.writeHead(400); response.end(); return
    }
    const path = new URL(request.url ?? '/', 'http://web.baander.app').pathname
    response.setHeader('Access-Control-Allow-Origin', local.webOrigin)
    response.setHeader('Access-Control-Allow-Credentials', 'true')
    response.setHeader('Access-Control-Allow-Headers', 'Authorization, DPoP, Range')
    response.setHeader('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS')
    response.setHeader('Access-Control-Expose-Headers', 'Content-Range')
    if (request.method === 'OPTIONS') {
      response.writeHead(204); response.end(); return
    }
    if (path === '/auth-stream-worker.js') {
      response.writeHead(200, { 'Content-Type': 'application/javascript', 'Service-Worker-Allowed': '/' })
      response.end(worker); return
    }
    if (path === '/seed-cookie') {
      response.writeHead(200, { 'Set-Cookie': 'worker-fixture=must-not-forward; Path=/; SameSite=Lax' })
      response.end('seeded'); return
    }
    if (path.startsWith('/api/images/') || path.startsWith('/api/stream/')) {
      observations.push({
        host, path, method: request.method ?? '',
        authorization: request.headers.authorization ?? null,
        proof: typeof request.headers.dpop === 'string' ? request.headers.dpop : null,
        cookie: request.headers.cookie ?? null, range: request.headers.range ?? null,
      })
      if (host !== 'foreign.baander.app' && (!request.headers.authorization || !request.headers.dpop)) {
        response.writeHead(401); response.end('Worker authentication missing'); return
      }
      if (path === '/api/images/ready') {
        response.writeHead(200); response.end('ready'); return
      }
      if (path === '/api/images/redirect') {
        response.writeHead(302, { Location: local.foreignOrigin + '/api/images/redirect-target' })
        response.end(); return
      }
      if (path.startsWith('/api/stream/')) {
        const ranged = request.headers.range === 'bytes=0-3'
        response.writeHead(ranged ? 206 : 200, ranged ? { 'Content-Range': 'bytes 0-3/8' } : {})
        response.end(request.method === 'HEAD' ? undefined : ranged ? 'abcd' : 'abcdefgh'); return
      }
      response.writeHead(200, { 'Content-Type': 'image/png' })
      response.end(Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jYZkAAAAASUVORK5CYII=', 'base64'))
      return
    }
    response.writeHead(200, { 'Content-Type': 'text/html' }); response.end(html)
  })
  const local: LocalServer = { server, webOrigin: '', apiOrigin: '', foreignOrigin: '', observations }
  await new Promise<void>(resolve => server.listen(0, '127.0.0.1', resolve))
  const address = server.address()
  if (!address || typeof address === 'string') throw new Error('Loopback fixture did not start')
  local.webOrigin = `http://web.baander.app:${address.port}`
  local.apiOrigin = `http://api.baander.app:${address.port}`
  local.foreignOrigin = `http://foreign.baander.app:${address.port}`
  return local
}

export const test = base.extend<{ harness: Harness }, { localServer: LocalServer }>({
  localServer: [async ({ browserName }, provide) => {
    expect(browserName).toBe('chromium')
    const local = await startServer()
    try { await provide(local) }
    finally { await new Promise<void>((resolve, reject) => local.server.close(error => error ? reject(error) : resolve())) }
  }, { scope: 'worker' }],
  browser: [async ({ playwright, localServer }, provide) => {
    const browser = await playwright.chromium.launch({ channel: 'chromium', args: [
      '--host-resolver-rules=' + hosts.map(host => `MAP ${host} 127.0.0.1`).join(', ') + ', MAP * ~NOTFOUND',
      '--unsafely-treat-insecure-origin-as-secure=' + [localServer.webOrigin, localServer.apiOrigin, localServer.foreignOrigin].join(','),
      '--no-proxy-server', '--disable-background-networking',
    ] })
    try { await provide(browser) } finally { await browser.close() }
  }, { scope: 'worker' }],
  context: async ({ browser, localServer }, provide) => {
    const context = await browser.newContext({ serviceWorkers: 'allow' })
    const origins = [localServer.webOrigin, localServer.apiOrigin, localServer.foreignOrigin]
    await context.route('**/*', route => origins.includes(new URL(route.request().url()).origin) ? route.continue() : route.abort())
    try { await provide(context) } finally { await context.close() }
  },
  harness: async ({ page, localServer }, provide) => {
    await page.goto(localServer.webOrigin)
    await page.evaluate(async api => {
      const configure = Reflect.get(window, 'configureWorker') as (origin: string) => Promise<void>
      await configure(api)
    }, localServer.apiOrigin)
    // Real protected fetch is the readiness handshake; no activation/signing sleeps.
    await expect.poll(() => page.evaluate(async api => (await fetch(api + '/api/images/ready')).status, localServer.apiOrigin)).toBe(200)
    for (const origin of [localServer.webOrigin, localServer.apiOrigin]) {
      await page.evaluate(async target => {
        await fetch(target + '/seed-cookie', { credentials: 'include' })
      }, origin)
    }
    const cookies = await page.context().cookies()
    expect(cookies.filter(cookie => cookie.name === 'worker-fixture')).toHaveLength(2)
    localServer.observations.length = 0
    await provide({
      apiOrigin: localServer.apiOrigin, foreignOrigin: localServer.foreignOrigin,
      observations: localServer.observations,
      image: url => imageOutcome(page, url),
    })
  },
})

async function imageOutcome(page: Page, url: string): Promise<'loaded' | 'failed'> {
  return page.evaluate(target => new Promise<'loaded' | 'failed'>(resolve => {
    const image = new Image()
    image.onload = () => resolve('loaded')
    image.onerror = () => resolve('failed')
    image.src = target
    document.body.append(image)
  }), url)
}
export { expect }
