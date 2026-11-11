import { expect, type Browser, type BrowserContext, type Page, type TestInfo } from '@playwright/test'
import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'
import { authenticatedGet, login, type FixtureUser } from '../live-updates/capacity-fixtures'

export interface SessionExchange {
  tab: string
  at: number
  path: string
  status?: number
  action?: string
  outcome?: string | null
  message?: string
}

/** One browser context of the account, with every session request and answer it produced. */
export interface SessionTab {
  name: string
  context: BrowserContext
  page: Page
  exchanges: SessionExchange[]
  /** Whether this context asked the session for `path`, as far as the driver saw. */
  requested(path: string): boolean
}

const sessionPath = (url: string) => {
  const path = new URL(url).pathname
  return path.startsWith('/api/session/') ? path : null
}

/**
 * Opens a context of its own for `user`, named `name` in the destination list, and signs in.
 * A context has its own storage: it shares no tab identity, lock or local recovery record with
 * another one, so nothing but the session itself connects two of them.
 */
export async function openSessionTab(
  browser: Browser, baseURL: string | undefined, user: FixtureUser, name: string, prepare: (context: BrowserContext) => void = () => {},
): Promise<SessionTab> {
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } })
  prepare(context)
  await context.addInitScript((deviceName: string) => {
    localStorage.setItem('baander-device-name', deviceName)
  }, name)
  const exchanges: SessionExchange[] = []
  // Observed on the context, so a request an unloading page sends is still attributed to it.
  context.on('request', request => {
    const path = sessionPath(request.url())
    if (!path || request.method() !== 'POST') return
    let body: { action?: string; outcome?: string } | null = null
    try { body = request.postDataJSON() } catch { /* A request without a JSON body carries nothing to keep. */ }
    exchanges.push({ tab: name, at: Date.now(), path, action: body?.action, outcome: body?.outcome })
  })
  context.on('response', async response => {
    const path = sessionPath(response.url())
    if (!path || response.request().method() !== 'POST') return
    const exchange: SessionExchange = { tab: name, at: Date.now(), path, status: response.status() }
    try {
      const body = await response.json()
      exchange.outcome = body.data?.requestResult?.outcome
      exchange.message = body.error?.message
    } catch { /* A page that went away no longer exposes the body of its answer. */ }
    exchanges.push(exchange)
  })
  const page = await context.newPage()
  await login(page, user)
  return { name, context, page, exchanges, requested: path => exchanges.some(exchange => exchange.path === path) }
}

export interface RegistrationAnswer {
  revision: number
  activeIncarnationId: string | null
  desiredState: string
  media: unknown
}

/**
 * Collects what the server answers to every endpoint registration of the context's pages: the
 * snapshot that comes back over the socket together with the new endpoint. The frames are only
 * observed, and the capability in the answer is not kept.
 */
export function observeRegistrationAnswers(context: BrowserContext): RegistrationAnswer[] {
  const answers: RegistrationAnswer[] = []
  context.on('page', page => {
    page.on('websocket', socket => {
      socket.on('framereceived', ({ payload }) => {
        if (typeof payload !== 'string' || !payload.includes('"session.snapshot"')) return
        let message: { type?: string; data?: RegistrationAnswer & { endpoint?: unknown } }
        try { message = JSON.parse(payload) } catch { return }
        if (message.type !== 'session.snapshot' || !message.data?.endpoint) return
        const { revision, activeIncarnationId, desiredState, media } = message.data
        answers.push({ revision, activeIncarnationId, desiredState, media })
      })
    })
  })
  return answers
}

export async function sessionSnapshot(page: Page): Promise<PlaybackSnapshotResource> {
  return (await authenticatedGet<{ data: PlaybackSnapshotResource }>(page, '/api/session/snapshot')).data
}

/** The state of the tab's own audio element. It needs no network, so it also reads an offline tab. */
export async function nativeAudio(page: Page) {
  return page.evaluate(async () => {
    const path = '/src/features/player/stores/player-store.ts'
    const { usePlayerStore } = await import(/* @vite-ignore */ path)
    const audio = usePlayerStore.getState().audioElement as HTMLAudioElement | null
    return { paused: audio?.paused ?? true, ended: audio?.ended ?? false, time: audio?.currentTime ?? 0, duration: audio?.duration ?? 0 }
  })
}

/** The incarnation the destination list shows for the tab named `name`, while it is registered. */
export async function incarnationOf(observer: Page, name: string): Promise<string | null> {
  const snapshot = await sessionSnapshot(observer)
  return snapshot.destinations.find(destination => destination.name === name && destination.available)?.incarnationId ?? null
}

/**
 * Starts the fixture song in `tab` and waits until the session has confirmed that this tab plays
 * it: the tab's element advances, and the session names the tab as its runner, playing.
 */
export async function playFixtureSong(tab: SessionTab): Promise<string> {
  await tab.page.goto('/music/songs')
  await tab.page.getByText('Live song 00000', { exact: true }).dblclick()
  await expect.poll(async () => (await nativeAudio(tab.page)).paused, { timeout: 25_000 }).toBe(false)
  await expect.poll(async () => (await nativeAudio(tab.page)).time).toBeGreaterThan(1)
  await expect.poll(async () => {
    const snapshot = await sessionSnapshot(tab.page)
    return snapshot.observedState === 'playing' && snapshot.activeIncarnationId !== null
      && snapshot.activeIncarnationId === snapshot.destinations.find(destination => destination.name === tab.name)?.incarnationId
  }, { message: `the session to confirm that ${tab.name} plays` }).toBe(true)
  return (await sessionSnapshot(tab.page)).activeIncarnationId!
}

/** The playback destination control. Its title says what the tab itself knows about the session. */
export function destinationControl(page: Page) {
  return page.getByRole('button', { name: 'Playback destination', exact: true }).first()
}

export async function attachExchanges(testInfo: TestInfo, tabs: SessionTab[], receipt: object = {}) {
  const exchanges = tabs.flatMap(tab => tab.exchanges).sort((left, right) => left.at - right.at)
  await testInfo.attach('session-exchanges', { body: JSON.stringify({ receipt, exchanges }, null, 2), contentType: 'application/json' })
}
