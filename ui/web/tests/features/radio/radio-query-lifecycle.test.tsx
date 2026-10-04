import type { ReactNode } from 'react'
import { ThemeProvider } from 'styled-components'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, expect, it, vi } from 'vitest'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { CountryPicker } from '@/features/radio/components/CountryPicker'
import { StarredStations } from '@/features/radio/components/StarredStations'
import {
  getAvailableCountries,
  getSubscriptions,
  subscribeCountry,
  getStarredStations,
  getStations,
  unstarStation,
  type RadioStation,
} from '@/features/radio/api/radio-api'

interface StationCardMockProps {
  station: RadioStation
  onUnstar: (id: string) => void
}

vi.mock('@/features/radio/api/radio-api', () => ({
  getAvailableCountries: vi.fn(),
  getSubscriptions: vi.fn(),
  subscribeCountry: vi.fn(),
  unsubscribeCountry: vi.fn(),
  getStarredStations: vi.fn(),
  getStations: vi.fn(),
  unstarStation: vi.fn(),
}))

vi.mock('@/features/radio/components/StationCard', () => ({
  StationCard: ({ station, onUnstar }: StationCardMockProps) => (
    <button onClick={() => onUnstar(station.id)}>
      {station.name}
    </button>
  ),
}))

beforeEach(() => vi.clearAllMocks())

function queryWrapper(client: QueryClient) {
  return ({ children }: { children: ReactNode }) => (
    <ThemeProvider theme={resolveTheme('dark', 'violet')}>
      <QueryClientProvider client={client}>
        {children}
      </QueryClientProvider>
    </ThemeProvider>
  )
}

it('shares subscription query ownership across StrictMode and updates cached subscriptions after a toggle', async () => {
  const client = new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
        staleTime: Infinity,
      },
    },
  })
  vi.mocked(getAvailableCountries).mockResolvedValue([
    { code: 'DK', name: 'Denmark', station_count: 10 },
  ])
  vi.mocked(getSubscriptions).mockResolvedValue([])
  const subscription = {
    id: 'sub',
    userId: 'user',
    sourceId: 'source',
    countryCode: 'DK',
    lastSyncedAt: null,
    createdAt: '',
  }
  vi.mocked(subscribeCountry).mockResolvedValue(subscription)
  const view = render(<CountryPicker />, {
    wrapper: queryWrapper(client),
    reactStrictMode: true,
  })

  await screen.findByText('Denmark')

  expect(getSubscriptions).toHaveBeenCalledOnce()

  fireEvent.click(screen.getByRole('button', { name: /Denmark/ }))

  await waitFor(() => {
    expect(client.getQueryData(['radio', 'subscriptions'])).toEqual([subscription])
  })

  view.unmount()
  client.clear()
})

it('loads starred stations through shared query keys and keeps unstarred results out of the cache', async () => {
  const client = new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
        staleTime: Infinity,
      },
    },
  })
  vi.mocked(getStarredStations).mockResolvedValue([
    { id: 'star', userId: 'user', stationId: 'station', starredAt: '' },
  ])
  vi.mocked(getStations).mockResolvedValue([
    { id: 'station', name: 'Favorite station' } as RadioStation,
  ])
  vi.mocked(unstarStation).mockResolvedValue(undefined)
  render(<StarredStations />, {
    wrapper: queryWrapper(client),
    reactStrictMode: true,
  })

  fireEvent.click(await screen.findByRole('button', { name: 'Favorite station' }))

  await screen.findByText(/No starred stations yet/)
  expect(client.getQueryData(['radio', 'starred'])).toEqual([])
  expect(getStarredStations).toHaveBeenCalledOnce()

  client.clear()
})
