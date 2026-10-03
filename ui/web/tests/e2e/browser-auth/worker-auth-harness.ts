import { test as base, expect, type Page } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { createServer, type Server } from 'node:http'
import { fileURLToPath } from 'node:url'
import { build, transformSync } from 'esbuild'
import { dirname, resolve } from 'node:path'
import { createRequire } from 'node:module'

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
  refreshes: string[]
  refreshProofs: Array<{token:string; proof:string|null}>
  gates: Map<string, { wait: Promise<void>; release: () => void }>
}
interface Harness {
  apiOrigin: string
  foreignOrigin: string
  observations: Observation[]
  image(url: string): Promise<'loaded' | 'failed'>
  configure(page: Page, token: string): Promise<void>
  refreshes: string[]
  refreshProofs: Array<{token:string; proof:string|null}>
  gate(token: string): () => void
  clear(page: Page): Promise<void>
  token(page: Page): Promise<string | null>
}

const hosts = ['web.baander.app', 'api.baander.app', 'foreign.baander.app']
const html = '<!doctype html><title>Worker transport fixture</title><script src="/fixture.js"></script>'

async function fixtureBundle(): Promise<string> {
  const sourceRoot = fileURLToPath(new URL('../../../src/', import.meta.url))
  const bridge = resolve(sourceRoot, 'features/player/services/service-worker-bridge.ts')
  const auth = `import { postTokenToWorker } from ${JSON.stringify(bridge)};
    import { setKey } from '@/shared/crypto/dpop-store';
    export const state = { accessToken: null, refreshToken: null,
      setTokens(accessToken, refreshToken) { this.accessToken=accessToken; this.refreshToken=refreshToken; void postTokenToWorker(accessToken); },
      clearAuth() { setKey(null); this.accessToken=null; this.refreshToken=null; void postTokenToWorker(null); } };
    export const getAuthSnapshot = () => state;
    export const useAuthStore = { getState: getAuthSnapshot };`
  const fixtures: Record<string, string> = {
    '@/features/auth/stores/auth-store': auth,
    '@/shared/crypto/dpop-store': `let key=null, nonce=null; export const setKey=value=>key=value;
      export const getDpopKeyPair=()=>key; export const getDpopNonce=()=>nonce; export const setDpopNonce=value=>nonce=value;`,
    '@/shared/crypto/dpop-proof': `export const createDpopProof=async(key,method,url,options={})=>
      options.accessToken==='transport-fixture-token' ? 'transport-fixture-proof' : JSON.stringify({key:key.id,method,url,token:options.accessToken??null});`,
  }
  const result = await build({ bundle: true, write: false, format: 'iife', platform: 'browser',
    absWorkingDir: resolve(sourceRoot, '..'),
    stdin: { resolveDir: sourceRoot, contents: `
      import { initServiceWorkerListener, initWorkerApiUrl, postTokenToWorker } from ${JSON.stringify(bridge)};
      import { state } from '@/features/auth/stores/auth-store';
      import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance';
      import { setKey } from '@/shared/crypto/dpop-store';
      let login=0;
      window.fixture = { token:()=>state.accessToken, clear:()=>state.clearAuth(),
        async configure(api,token) { window.__BAANDER_API_URL__=api; AXIOS_INSTANCE.defaults.baseURL=api;
          setKey({id:token+'-key-'+ ++login}); state.setTokens(token, token+'-refresh');
          initServiceWorkerListener();
          await navigator.serviceWorker.register('/auth-stream-worker.js'); await navigator.serviceWorker.ready;
          if (!navigator.serviceWorker.controller) await new Promise(resolve=>navigator.serviceWorker.addEventListener('controllerchange',resolve,{once:true}));
          await initWorkerApiUrl(); await postTokenToWorker(token);
        } };
    ` }, plugins: [{ name: 'tab-fixtures', setup(plugin) {
      // Resolve the real browser Axios build in this workspace, avoiding the unrelated root PnP manifest.
      plugin.onResolve({ filter: /^axios$/ }, () => ({ path: resolve(dirname(createRequire(import.meta.url).resolve('axios/package.json')), 'dist/browser/axios.cjs') }))
      plugin.onResolve({ filter: /^@\// }, args => fixtures[args.path]
        ? { path: args.path, namespace: 'fixture' }
        : { path: resolve(sourceRoot, args.path.slice(2)+'.ts') })
      plugin.onLoad({ filter: /.*/, namespace: 'fixture' }, args => ({ contents: fixtures[args.path], loader: 'js', resolveDir: sourceRoot }))
    } }] })
  return result.outputFiles[0].text
}

async function startServer(): Promise<LocalServer> {
  const workerPath = fileURLToPath(new URL('../../../src/features/player/services/auth-stream-worker.ts', import.meta.url))
  const worker = transformSync(readFileSync(workerPath, 'utf8'), { loader: 'ts' }).code
  const observations: Observation[] = []
  const bundle = await fixtureBundle()
  const server = createServer((request, response) => {
    const host = (request.headers.host ?? '').split(':')[0]
    if (!hosts.includes(host)) {
      response.writeHead(400); response.end(); return
    }
    const path = new URL(request.url ?? '/', 'http://web.baander.app').pathname
    response.setHeader('Access-Control-Allow-Origin', local.webOrigin)
    response.setHeader('Access-Control-Allow-Credentials', 'true')
    response.setHeader('Access-Control-Allow-Headers', 'Authorization, DPoP, Range, Content-Type')
    response.setHeader('Access-Control-Allow-Methods', 'GET, HEAD, POST, OPTIONS')
    response.setHeader('Access-Control-Expose-Headers', 'Content-Range')
    if (request.method === 'OPTIONS') {
      response.writeHead(204); response.end(); return
    }
    if (path === '/fixture.js') { response.writeHead(200, { 'Content-Type': 'application/javascript' }); response.end(bundle); return }
    if (path === '/api/auth/refresh') {
      let body = ''
      request.on('data', chunk => { body += String(chunk) })
      request.on('end', () => { void (async () => {
        const { refreshToken } = JSON.parse(body) as { refreshToken: string }
        local.refreshes.push(refreshToken)
        local.refreshProofs.push({token:refreshToken, proof:typeof request.headers.dpop==='string' ? request.headers.dpop : null})
        await local.gates.get(refreshToken)?.wait
        response.writeHead(refreshToken.startsWith('failure') ? 401 : 200, { 'Content-Type': 'application/json' })
        response.end(JSON.stringify({ accessToken: refreshToken+'-rotated', refreshToken: refreshToken+'-next' }))
      })() })
      return
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
      if (path.endsWith('/always401') || (path.endsWith('/expired') && !request.headers.authorization?.endsWith('-rotated'))) {
        response.writeHead(401); response.end('expired'); return
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
  const local: LocalServer = { server, webOrigin: '', apiOrigin: '', foreignOrigin: '', observations, refreshes: [], refreshProofs: [], gates: new Map() }
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
    finally { local.gates.forEach(gate=>gate.release()); local.server.closeAllConnections(); await new Promise<void>((resolve, reject) => local.server.close(error => error ? reject(error) : resolve())) }
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
      const fixture = Reflect.get(window, 'fixture') as BrowserFixture
      await fixture.configure(api, 'transport-fixture-token')
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
    localServer.refreshes.length = 0
    localServer.refreshProofs.length = 0
    localServer.gates.clear()
    await provide({
      apiOrigin: localServer.apiOrigin, foreignOrigin: localServer.foreignOrigin,
      observations: localServer.observations,
      image: url => imageOutcome(page, url),
      refreshes: localServer.refreshes,
      refreshProofs: localServer.refreshProofs,
      configure: async (target, token) => { if (target.url() === 'about:blank') await target.goto(localServer.webOrigin); await target.evaluate(async ({api,token}) => {
        await (Reflect.get(window, 'fixture') as BrowserFixture).configure(api,token)
      }, {api:localServer.apiOrigin,token}); await expect.poll(()=>target.evaluate(async api=>(await fetch(api+'/api/images/ready')).status,localServer.apiOrigin)).toBe(200) },
      clear: target => target.evaluate(() => (Reflect.get(window, 'fixture') as BrowserFixture).clear()),
      token: target => target.evaluate(() => (Reflect.get(window, 'fixture') as BrowserFixture).token()),
      gate: token => { let release!:()=>void; const wait=new Promise<void>(resolve=>{release=resolve}); localServer.gates.set(token,{wait,release}); return release },
    })
  },
})

interface BrowserFixture { configure(api:string,token:string):Promise<void>; clear():void; token():string|null }

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
