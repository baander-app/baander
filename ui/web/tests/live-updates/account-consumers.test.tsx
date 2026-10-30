import { act, cleanup, renderHook, waitFor } from '@testing-library/react'
import { QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider } from 'styled-components'
import MockAdapter from 'axios-mock-adapter'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render } from '../test-utils'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { useCurrentUserRefresh } from '@/features/auth/hooks/use-current-user-refresh'
import { OAuthConsent } from '@/features/auth/components/OAuthConsent'
import { authLiveDescriptors } from '@/features/auth/live-descriptors'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { synchronizeOwnership } from '@/shared/live/ownership'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import type { LiveSnapshot } from '@/shared/live/recovery'

vi.mock('@/features/auth/lib/browser-navigation', () => ({ leaveAppFor: vi.fn() }))
const disposers: (() => void)[] = []
let mock: MockAdapter
function setup() {
  const client = new OwnedQueryClient()
  client.setDefaultOptions({ queries: { retry: false } })
  const snapshot: LiveSnapshot = {
    status: 'current', authorityReady: true, families: ['account'], authorizationFingerprint: 'a'.repeat(64),
    health: { state: 'current', oldestPendingAgeMs: null },
  }
  const recovery = { getSnapshot: () => snapshot, subscribe: () => () => {}, subscribeEvents: () => () => {},
    completeRecovery: () => {}, resourceReadFailed: vi.fn() }
  const coordinator = new LiveQueryCoordinator(client, recovery, authLiveDescriptors)
  client.setCoordinator(coordinator)
  disposers.push(coordinator.start(), client.attach(), () => client.dispose())
  const wrapper = ({ children }: { children: React.ReactNode }) => <QueryClientProvider client={client}>
    <ThemeProvider theme={resolveTheme('dark', 'violet')}>{children}</ThemeProvider>
  </QueryClientProvider>
  return { coordinator, wrapper }
}
beforeEach(() => {
  synchronizeOwnership('account-proof')
  useAuthStore.setState({ isAuthenticated: true, accessToken: null, refreshToken: null,
    user: { publicId: 'listener', email: 'listener@baander.app', name: 'Before', roles: [] } })
  mock = new MockAdapter(AXIOS_INSTANCE, { onNoMatch: 'throwException' })
})
afterEach(() => {
  cleanup()
  disposers.splice(0).reverse().forEach(dispose => dispose())
  mock.restore()
  useAuthStore.setState({ isAuthenticated: false, user: null })
  synchronizeOwnership(null)
})

describe('account consumers', () => {
  it('refreshes the mounted current-user mirror while preserving player objects', async () => {
    let name = 'Before'
    mock.onGet('/api/auth/me').reply(() => [200, { data: {
      publicId: 'listener', email: 'listener@baander.app', name, roles: [], emailVerifiedAt: null,
    } }])
    const { coordinator, wrapper } = setup()
    const before = usePlayerStore.getState()
    renderHook(useCurrentUserRefresh, { wrapper })
    await waitFor(() => expect(mock.history.get).toHaveLength(1))
    name = 'After'
    act(() => coordinator.hint(['account']))
    await waitFor(() => expect(useAuthStore.getState().user?.name).toBe('After'))
    const after = usePlayerStore.getState()
    expect(after.audioElement).toBe(before.audioElement)
    expect(after.queue).toBe(before.queue)
    expect(after.currentTrack).toBe(before.currentTrack)
  })

  it('keeps consent demand driven without repeating automatic approval after account hints', async () => {
    const redirect = 'https://baander.app/callback'
    mock.onGet('/api/oauth/authorize').reply(200, {
      client_id: 'first-party', client_name: 'Baander', client_type: 'first_party',
      scopes: ['library'], redirect_uri: redirect, consent_required: false,
    })
    mock.onPost('/api/oauth/authorize').reply(200, { redirect_uri: redirect + '?code=once' })
    const { coordinator, wrapper } = setup()
    render(<OAuthConsent query={new URLSearchParams({ client_id: 'first-party', redirect_uri: redirect }).toString()} />, { wrapper })
    await waitFor(() => expect(mock.history.post).toHaveLength(1))
    act(() => { coordinator.hint(['account']); coordinator.hint(['account']) })
    await new Promise(resolve => setTimeout(resolve, 100))
    expect(mock.history.get).toHaveLength(1)
    expect(mock.history.post).toHaveLength(1)
  })
})
