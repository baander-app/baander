/**
 * Playwright fixtures for the browser-auth suite: one Chromium per worker, resolving the three
 * baander.app test hosts to the scripted server on the loopback, and one page per test whose
 * credentials are set up for the store the test chose (`test.use({ store: 'real' })`).
 */

import { test as base, expect, type Page } from '@playwright/test'
import { buildFixtureBundles, type StoreKind } from './fixture-bundles'
import type { RefreshState } from './refresh-state'
import {
  HOSTS,
  startScriptedServer,
  type AuthCall,
  type Observation,
  type RefreshMode,
  type RefreshObservation,
  type ScriptedServer,
} from './scripted-server'

/** What the page script exposes on `window.fixture`; the real store exposes login and logout. */
interface BrowserFixture {
  ready: Promise<void>
  pulls(): number
  token(): string | null
  clear(): void
  configure(token: string, keyId: string): Promise<void>
  login(email: string, password: string): Promise<void>
  logout(): Promise<void>
}

export interface Harness {
  apiOrigin: string
  foreignOrigin: string
  /** Every image and stream request the scripted server saw since the test started. */
  observations: Observation[]
  /** Every presentation of a refresh token, with its verdict. */
  refreshes: RefreshObservation[]
  /** Every login and logout call. */
  authCalls: AuthCall[]
  refreshState: RefreshState
  /** Loads `url` as an image in `page` (the test's page by default) and reports how it ended. */
  image(url: string, page?: Page): Promise<'loaded' | 'failed'>
  /** Fake store: gives `page` the pair `token` and `token-refresh`, known to the server, and a worker. */
  configure(page: Page, token: string): Promise<void>
  clear(page: Page): Promise<void>
  token(page: Page): Promise<string | null>
  /** Holds the server's answer to presentations of `token` until the returned function runs. */
  gate(token: string): () => void
  setRefreshMode(mode: RefreshMode): void
  /** Stops the service worker through CDP, as the browser does with an idle worker. */
  stopWorker(page: Page): Promise<void>
  /** How many credential pulls the worker made from `page`. */
  pulls(page: Page): Promise<number>
  /** Real store: signs `page` in and waits until its worker serves a protected image. */
  login(page: Page, email: string): Promise<void>
  logout(page: Page): Promise<void>
  /** A second page of the same origin, booted; on the real store it adopts the stored session. */
  openPage(): Promise<Page>
}

interface TestOptions {
  store: StoreKind
}

interface TestFixtures {
  harness: Harness
}

interface WorkerFixtures {
  localServer: ScriptedServer
}

const READY_PATH = '/api/images/ready'
const FIXTURE_PASSWORD = 'correct-horse-battery-staple'

/* Functions passed to page.evaluate are serialized, so each helper names the fixture itself. */

async function fixtureConfigure(page: Page, token: string, keyId: string): Promise<void> {
  await page.evaluate(async ([token, keyId]) => {
    const fixture = Reflect.get(window, 'fixture') as BrowserFixture
    await fixture.ready
    await fixture.configure(token, keyId)
  }, [token, keyId] as const)
}

async function fixtureReady(page: Page): Promise<void> {
  await page.evaluate(async () => {
    const fixture = Reflect.get(window, 'fixture') as BrowserFixture
    await fixture.ready
  })
}

function fixtureToken(page: Page): Promise<string | null> {
  return page.evaluate(async () => {
    const fixture = Reflect.get(window, 'fixture') as BrowserFixture
    await fixture.ready
    return fixture.token()
  })
}

function fixturePulls(page: Page): Promise<number> {
  return page.evaluate(async () => {
    const fixture = Reflect.get(window, 'fixture') as BrowserFixture
    await fixture.ready
    return fixture.pulls()
  })
}

async function fixtureClear(page: Page): Promise<void> {
  await page.evaluate(async () => {
    const fixture = Reflect.get(window, 'fixture') as BrowserFixture
    await fixture.ready
    fixture.clear()
  })
}

async function fixtureLogin(page: Page, email: string): Promise<void> {
  await page.evaluate(async ([email, password]) => {
    const fixture = Reflect.get(window, 'fixture') as BrowserFixture
    await fixture.ready
    await fixture.login(email, password)
  }, [email, FIXTURE_PASSWORD] as const)
}

async function fixtureLogout(page: Page): Promise<void> {
  await page.evaluate(async () => {
    const fixture = Reflect.get(window, 'fixture') as BrowserFixture
    await fixture.ready
    await fixture.logout()
  })
}

/** A real protected fetch is the readiness handshake; no activation or signing sleeps. */
async function waitForSignedFetch(page: Page, apiOrigin: string): Promise<void> {
  await expect.poll(() => page.evaluate(async url => (await fetch(url)).status, apiOrigin + READY_PATH)).toBe(200)
}

async function seedCookies(page: Page, origins: string[]): Promise<void> {
  for (const origin of origins) {
    await page.evaluate(async target => {
      await fetch(target + '/seed-cookie', { credentials: 'include' })
    }, origin)
  }
  const cookies = await page.context().cookies()
  expect(cookies.filter(cookie => cookie.name === 'worker-fixture')).toHaveLength(origins.length)
}

function imageOutcome(page: Page, url: string): Promise<'loaded' | 'failed'> {
  return page.evaluate(target => new Promise<'loaded' | 'failed'>(resolve => {
    const image = new Image()
    image.onload = () => resolve('loaded')
    image.onerror = () => resolve('failed')
    image.src = target
    document.body.append(image)
  }), url)
}

/**
 * `ServiceWorker.enable` reports the registration's versions; `ServiceWorker.stopAllWorkers` stops
 * the running one, and the next `workerVersionUpdated` event confirms it is stopped. The next fetch
 * event starts a fresh worker instance with no credentials.
 */
async function stopServiceWorker(page: Page): Promise<void> {
  const session = await page.context().newCDPSession(page)
  try {
    const stopped = new Promise<void>(resolve => {
      session.on('ServiceWorker.workerVersionUpdated', event => {
        if (event.versions.some(version => version.runningStatus === 'stopped')) resolve()
      })
    })
    await session.send('ServiceWorker.enable')
    await session.send('ServiceWorker.stopAllWorkers')
    await stopped
  } finally {
    await session.detach()
  }
}

export const test = base.extend<TestOptions & TestFixtures, WorkerFixtures>({
  store: ['fake', { option: true }],

  localServer: [async ({ browserName }, provide) => {
    expect(browserName).toBe('chromium')
    const server = await startScriptedServer(await buildFixtureBundles())
    try {
      await provide(server)
    } finally {
      await server.close()
    }
  }, { scope: 'worker' }],

  browser: [async ({ playwright, localServer }, provide) => {
    const origins = [localServer.webOrigin, localServer.apiOrigin, localServer.foreignOrigin]
    const browser = await playwright.chromium.launch({
      channel: 'chromium',
      args: [
        '--host-resolver-rules=' + HOSTS.map(host => `MAP ${host} 127.0.0.1`).join(', ') + ', MAP * ~NOTFOUND',
        '--unsafely-treat-insecure-origin-as-secure=' + origins.join(','),
        '--no-proxy-server',
        '--disable-background-networking',
      ],
    })
    try {
      await provide(browser)
    } finally {
      await browser.close()
    }
  }, { scope: 'worker' }],

  context: async ({ browser, localServer }, provide) => {
    const context = await browser.newContext({ serviceWorkers: 'allow' })
    const origins = [localServer.webOrigin, localServer.apiOrigin, localServer.foreignOrigin]
    await context.route('**/*', route => origins.includes(new URL(route.request().url()).origin)
      ? route.continue()
      : route.abort())
    try {
      await provide(context)
    } finally {
      await context.close()
    }
  },

  harness: async ({ page, context, localServer, store }, provide) => {
    localServer.reset(store)

    const configure = async (target: Page, token: string): Promise<void> => {
      if (target.url() === 'about:blank') await target.goto(localServer.webOrigin)
      const keyId = `${token}-key`
      localServer.refreshState.login(token, keyId)
      await fixtureConfigure(target, token, keyId)
      await waitForSignedFetch(target, localServer.apiOrigin)
    }

    await page.goto(localServer.webOrigin)
    if (store === 'fake') {
      await configure(page, 'transport-fixture-token')
    } else {
      await fixtureReady(page)
    }
    await seedCookies(page, [localServer.webOrigin, localServer.apiOrigin])
    localServer.observations.length = 0
    localServer.refreshes.length = 0
    localServer.authCalls.length = 0

    await provide({
      apiOrigin: localServer.apiOrigin,
      foreignOrigin: localServer.foreignOrigin,
      observations: localServer.observations,
      refreshes: localServer.refreshes,
      authCalls: localServer.authCalls,
      refreshState: localServer.refreshState,
      image: (url, target = page) => imageOutcome(target, url),
      configure,
      clear: fixtureClear,
      token: fixtureToken,
      gate: token => {
        let release!: () => void
        const wait = new Promise<void>(resolve => { release = resolve })
        localServer.gates.set(token, { wait, release })
        return release
      },
      setRefreshMode: mode => { localServer.refreshMode = mode },
      stopWorker: stopServiceWorker,
      pulls: fixturePulls,
      login: async (target, email) => {
        await fixtureLogin(target, email)
        await waitForSignedFetch(target, localServer.apiOrigin)
      },
      logout: fixtureLogout,
      openPage: async () => {
        const other = await context.newPage()
        await other.goto(localServer.webOrigin)
        await fixtureReady(other)
        return other
      },
    })
  },
})

export { expect }
