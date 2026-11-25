import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { renderHook, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import MockAdapter from 'axios-mock-adapter'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useStarredStations as useHomeStarredStations } from '@/features/catalog/hooks/use-starred-stations'
import { render } from '../../../../../tests/test-utils'
import { StarredStations } from '../StarredStations'
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

/** The last of 501 stations: the station list's first page of 500 does not hold it. */
const LAST = DANISH_STATIONS[500]

function client(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } })
}

describe('starred stations outside the first page of the station list', () => {
  let api: MockAdapter

  beforeEach(() => {
    api = new MockAdapter(AXIOS_INSTANCE, { onNoMatch: 'throwException' })
    api.onGet('/api/radio/starred').reply(200, {
      data: [{ userId: 'user00000000000000001', stationId: LAST.publicId, starredAt: '2026-11-25T10:00:00+00:00' }],
    })
    serveStations(api, DANISH_STATIONS)
  })

  afterEach(() => api.restore())

  it('appear in the Radio page starred list, read by public ID', async () => {
    render(
      <QueryClientProvider client={client()}>
        <StarredStations />
      </QueryClientProvider>,
    )

    expect(await screen.findByText('Station 501')).toBeInTheDocument()
    expect(api.history.get.filter((request) => request.url === '/api/radio/stations').map((request) => request.params)).toEqual([
      { publicIds: LAST.publicId, limit: 1, offset: 0 },
    ])
  })

  it('appear on the home page, read by public ID', async () => {
    const queryClient = client()
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    )

    const { result } = renderHook(() => useHomeStarredStations(), { wrapper })

    await waitFor(() => expect(result.current.data).toEqual([LAST]))
  })
})
