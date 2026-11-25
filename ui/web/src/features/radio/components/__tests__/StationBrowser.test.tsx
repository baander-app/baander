import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import MockAdapter from 'axios-mock-adapter'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { render } from '../../../../../tests/test-utils'
import { StationBrowser } from '../StationBrowser'
import { DANISH_STATIONS, serveStations } from './radio-station-server'

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: { getState: () => ({ accessToken: null, refreshToken: null }) },
}))
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null,
  getDpopNonce: () => null,
  setDpopNonce: vi.fn(),
}))
vi.mock('@/shared/crypto/dpop-proof', () => ({ createDpopProof: vi.fn() }))
vi.mock('@/features/radio/hooks/use-radio-playback', () => ({
  useRadioPlayback: () => ({ start: vi.fn(), stop: vi.fn() }),
}))

describe('StationBrowser', () => {
  let api: MockAdapter

  beforeEach(() => {
    api = new MockAdapter(AXIOS_INSTANCE, { onNoMatch: 'throwException' })
    api.onGet('/api/radio/subscriptions').reply(200, {
      data: [{ publicId: 'subscription000000001', userId: 'user00000000000000001', sourceId: 'source000000000000001', countryCode: 'DK', lastSyncedAt: null, createdAt: '2026-11-25T10:00:00+00:00' }],
    })
    api.onGet('/api/radio/starred').reply(200, { data: [] })
    serveStations(api, DANISH_STATIONS)
  })

  afterEach(() => api.restore())

  it('shows the last station of a country with more stations than one page holds', async () => {
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <StationBrowser />
      </QueryClientProvider>,
    )

    fireEvent.click(await screen.findByRole('button', { name: 'DK' }))

    expect(await screen.findByText('Station 501', {}, { timeout: 3000 })).toBeInTheDocument()
    expect(api.history.get.filter((request) => request.url === '/api/radio/stations').map((request) => request.params)).toEqual([
      { country: 'DK', limit: 500, offset: 0 },
      { country: 'DK', limit: 500, offset: 500 },
    ])
  })
})
