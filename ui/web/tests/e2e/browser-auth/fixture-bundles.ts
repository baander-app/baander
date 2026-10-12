/**
 * The page scripts the scripted server serves.
 *
 * Both bundles run the real service worker bridge and the real Axios instance against a fake DPoP
 * signer, so a proof is readable JSON the server can check. The fake-store bundle replaces the auth
 * store with a plain object whose tokens a test sets directly. The real-store bundle runs the real
 * auth store, its IndexedDB adapter and the cross-tab coordination module, with real Web Locks and
 * BroadcastChannel, so two pages of one account behave as two tabs do.
 */

import { existsSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { build, type Plugin } from 'esbuild'

export type StoreKind = 'fake' | 'real'

export interface FixtureBundles {
  fake: string
  real: string
}

const sourceRoot = fileURLToPath(new URL('../../../src/', import.meta.url))
const bridgePath = resolve(sourceRoot, 'features/player/services/service-worker-bridge.ts')

/** A proof is JSON the scripted server reads back; the initial access token keeps its fixed proof. */
const fakeProofModule = `
  export const createDpopProof = async (key, method, url, options = {}) => {
    if (options.accessToken === 'transport-fixture-token') return 'transport-fixture-proof'
    return JSON.stringify({
      key: key.id ?? key.jkt,
      method,
      url,
      token: options.accessToken ?? null,
      nonce: options.nonce ?? null,
    })
  }
`

const fakeDpopStoreModule = `
  let key = null
  let nonce = null
  export const setKey = value => { key = value }
  export const getDpopKeyPair = () => key
  export const getDpopNonce = () => nonce
  export const setDpopNonce = value => { nonce = value }
`

const fakeAuthStoreModule = `
  import { postTokenToWorker } from ${JSON.stringify(bridgePath)}
  import { setKey } from '@/shared/crypto/dpop-store'

  const reportPushFailure = error => { console.error('Token push to the worker failed', error) }

  export const state = {
    accessToken: null,
    refreshToken: null,
    setTokens(accessToken, refreshToken) {
      this.accessToken = accessToken
      this.refreshToken = refreshToken
      postTokenToWorker(accessToken).catch(reportPushFailure)
      return Promise.resolve()
    },
    clearAuth() {
      setKey(null)
      this.accessToken = null
      this.refreshToken = null
      postTokenToWorker(null).catch(reportPushFailure)
    },
    // The refresh re-reads the stored record under the auth lock; the fake store keeps no record.
    adoptStoredAuth: async () => false,
  }
  export const getAuthSnapshot = () => state
  export const useAuthStore = { getState: getAuthSnapshot }
`

/** Shared by both entries: the worker registration and the count of credential pulls it makes. */
const fixtureCommon = `
  import { initServiceWorkerListener, initWorkerApiUrl } from ${JSON.stringify(bridgePath)}

  let pulls = 0
  navigator.serviceWorker.addEventListener('message', event => {
    if (event.data?.type === 'SW_PULL_AUTH') pulls++
  })

  async function registerWorker() {
    initServiceWorkerListener()
    await navigator.serviceWorker.register('/auth-stream-worker.js')
    await navigator.serviceWorker.ready
    if (!navigator.serviceWorker.controller) {
      await new Promise(resolve => navigator.serviceWorker.addEventListener('controllerchange', resolve, { once: true }))
    }
    await initWorkerApiUrl()
  }
`

const fakeStoreEntry = `
  ${fixtureCommon}
  import { state } from '@/features/auth/stores/auth-store'
  import { setKey } from '@/shared/crypto/dpop-store'

  window.fixture = {
    ready: Promise.resolve(),
    pulls: () => pulls,
    token: () => state.accessToken,
    clear: () => state.clearAuth(),
    async configure(token, keyId) {
      setKey({ id: keyId })
      await state.setTokens(token, token + '-refresh')
      await registerWorker()
    },
  }
`

const realStoreEntry = `
  ${fixtureCommon}
  import { useAuthStore } from '@/features/auth/stores/auth-store'

  const store = () => useAuthStore.getState()
  const ready = (async () => {
    await registerWorker()
    await store().initAuth()
    await initWorkerApiUrl()
  })()

  window.fixture = {
    ready,
    pulls: () => pulls,
    token: () => store().accessToken,
    clear: () => store().clearAuth(),
    login: (email, password) => store().login(email, password),
    logout: () => store().logout(),
  }
`

/** `@/x` is `src/x.ts` or the directory's `index.ts`. */
function sourceModule(specifier: string): string {
  const base = resolve(sourceRoot, specifier.slice(2))
  return existsSync(base + '.ts') ? base + '.ts' : resolve(base, 'index.ts')
}

function fixturePlugin(fixtures: Record<string, string>): Plugin {
  // Resolve packages from this workspace; the repository root carries an unrelated PnP manifest.
  const require = createRequire(resolve(sourceRoot, '../package.json'))
  return {
    name: 'fixture-modules',
    setup(plugin) {
      plugin.onResolve({ filter: /^axios$/ }, () => ({
        path: resolve(dirname(require.resolve('axios/package.json')), 'dist/browser/axios.cjs'),
      }))
      plugin.onResolve({ filter: /^@\// }, args => fixtures[args.path]
        ? { path: args.path, namespace: 'fixture' }
        : { path: sourceModule(args.path) })
      plugin.onResolve({ filter: /^[^./]/ }, args => ({ path: require.resolve(args.path) }))
      plugin.onLoad({ filter: /.*/, namespace: 'fixture' }, args => ({
        contents: fixtures[args.path],
        loader: 'js',
        resolveDir: sourceRoot,
      }))
    },
  }
}

async function bundle(entry: string, fixtures: Record<string, string>): Promise<string> {
  const result = await build({
    bundle: true,
    write: false,
    format: 'iife',
    platform: 'browser',
    absWorkingDir: resolve(sourceRoot, '..'),
    define: { 'import.meta.env': '{}' },
    stdin: { resolveDir: sourceRoot, contents: entry },
    plugins: [fixturePlugin(fixtures)],
  })
  return result.outputFiles[0].text
}

export async function buildFixtureBundles(): Promise<FixtureBundles> {
  const fake = await bundle(fakeStoreEntry, {
    '@/features/auth/stores/auth-store': fakeAuthStoreModule,
    '@/shared/crypto/dpop-store': fakeDpopStoreModule,
    '@/shared/crypto/dpop-proof': fakeProofModule,
  })
  const real = await bundle(realStoreEntry, {
    '@/shared/crypto/dpop-proof': fakeProofModule,
  })
  return { fake, real }
}
