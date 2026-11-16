import { act, cleanup, render, screen } from '@testing-library/react'
import { AxiosError, type InternalAxiosRequestConfig } from 'axios'
import { toast } from 'sonner'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { synchronizeOwnership } from '@/shared/live/ownership'

// jsdom has no Web Audio; the player engine stays out of a test about where toasts render.
vi.mock('@/features/player/hooks/use-audio-playback', () => ({
  useAudioPlayback: () => {},
}))
vi.mock('@/shared/live/transport', () => ({
  authenticatedTransport: {
    retain: () => () => {},
    subscribe: () => () => {},
    isConnected: () => false,
  },
}))
vi.mock('@/shared/api-client/gen/endpoints', async importOriginal => ({
  ...await importOriginal<typeof import('@/shared/api-client/gen/endpoints')>(),
  getLiveRecovery: vi.fn(async () => ({
    version: 1,
    families: [],
    authorizationFingerprint: 'b'.repeat(64),
    health: { state: 'current', oldestPendingAgeMs: null },
  })),
}))

const previousAdapter = AXIOS_INSTANCE.defaults.adapter

function refuseRequest(config: InternalAxiosRequestConfig): Promise<never> {
  return Promise.reject(new AxiosError('No server in this test', AxiosError.ERR_NETWORK, config))
}

function matchNoMedia(query: string) {
  return {
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  }
}

beforeEach(() => {
  AXIOS_INSTANCE.defaults.adapter = refuseRequest
  vi.stubGlobal('fetch', vi.fn(async () => {
    throw new TypeError('No server in this test')
  }))
  vi.stubGlobal('matchMedia', matchNoMedia)
})

afterEach(() => {
  cleanup()
  // Sonner keeps active toasts in a module-wide store and replays them to the next Toaster.
  toast.dismiss()
  AXIOS_INSTANCE.defaults.adapter = previousAdapter
  vi.unstubAllGlobals()
  useAuthStore.setState({ isAuthenticated: false, principalSessionId: null, user: null })
  synchronizeOwnership(null)
})

function signIn(roles: string[]) {
  useAuthStore.setState({
    isAuthenticated: true,
    principalSessionId: `toast-host-${roles.join('-')}`,
    user: { publicId: 'usr_toast_host', email: 'listener@baander.app', name: null, roles },
  })
}

// App builds its browser router once, on import, so tests move it the way the browser does.
function showRoute(path: string) {
  act(() => {
    window.history.pushState({}, '', path)
    window.dispatchEvent(new PopStateEvent('popstate'))
  })
}

function toastRegions() {
  return document.querySelectorAll('section[aria-label^="Notifications"]')
}

// Each mounted Toaster applies a new toast in its own zero-delay timeout; waiting one more
// macrotask lets every mounted Toaster render it before the test counts copies.
async function raiseToast(message: string) {
  await act(async () => {
    toast(message)
    await new Promise(resolve => setTimeout(resolve, 0))
  })
}

describe('toast host', () => {
  it('shows a toast raised on an admin page without returning to the app shell', async () => {
    signIn(['ROLE_USER', 'ROLE_ADMIN'])
    showRoute('/admin')
    render(<App />)
    expect(await screen.findByRole('link', { name: 'Library' })).toHaveAttribute('href', '/admin/library')
    expect(screen.queryByRole('tablist', { name: 'Media type' })).not.toBeInTheDocument()
    await raiseToast('Library scan started')
    expect(screen.getByText('Library scan started')).toBeVisible()
    expect(toastRegions()).toHaveLength(1)
  })

  it('shows a toast raised in the app shell once, from a single toast region', async () => {
    signIn(['ROLE_USER'])
    showRoute('/settings')
    render(<App />)
    expect(await screen.findByRole('tablist', { name: 'Media type' })).toBeInTheDocument()
    expect(toastRegions()).toHaveLength(1)
    await raiseToast('Playlist saved')
    expect(screen.getAllByText('Playlist saved')).toHaveLength(1)
    expect(screen.getByText('Playlist saved')).toBeVisible()
  })
})
