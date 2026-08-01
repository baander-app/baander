import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { BrowserRouter } from 'react-router-dom'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import type { UseQueryReturnType } from '@tanstack/react-query'

const testTheme = resolveTheme('dark', 'violet')

// Mock the Orval-generated hook
const mockUseGetAlbumIndex = vi.fn()
vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetAlbumIndex: (...args: unknown[]) => mockUseGetAlbumIndex(...args),
  useGetGenreIndex: () => ({ data: { data: [] }, isLoading: false }),
  PaginatedResponse: undefined,
}))

vi.mock('@/features/layout/stores/context-panel-store', () => ({
  useContextPanelStore: (selector: (s: unknown) => unknown) =>
    selector({ setSelectedItem: vi.fn() }),
}))

vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (s: unknown) => unknown) =>
    selector({ playTrack: vi.fn() }),
}))

import { AlbumsPage } from '@/features/catalog/pages/AlbumsPage'

function renderWithProviders(ui: React.ReactElement) {
  return render(
    <SCTypedThemeProvider theme={testTheme}>
      <BrowserRouter>{ui}</BrowserRouter>
    </SCTypedThemeProvider>,
  )
}

function mockQueryResult(data: unknown) {
  return {
    data,
    isLoading: false,
    isError: false,
    error: null,
    isFetching: false,
  } as unknown as UseQueryReturnType<unknown, Error>
}

describe('AlbumsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // Default localStorage mock
    vi.spyOn(Storage.prototype, 'getItem').mockReturnValue('grid')
  })

  it('shows loading skeleton while fetching', () => {
    mockUseGetAlbumIndex.mockReturnValue({
      data: undefined,
      isLoading: true,
      isError: false,
      error: null,
    } as unknown as UseQueryReturnType<unknown, Error>)

    renderWithProviders(<AlbumsPage />)

    // styled-components no longer uses .animate-pulse; check for data-slot="skeleton"
    const skeletonElements = document.querySelectorAll('[data-slot="skeleton"]')
    expect(skeletonElements.length).toBeGreaterThan(0)
  })

  it('renders album cards when data is available', () => {
    mockUseGetAlbumIndex.mockReturnValue(
      mockQueryResult({
        data: [
          { publicId: 'alb_1', title: 'Album One', artistName: 'Artist A' },
          { publicId: 'alb_2', title: 'Album Two', artistName: 'Artist B' },
        ],
        current_page: 1,
        last_page: 1,
        total: 2,
      }),
    )

    renderWithProviders(<AlbumsPage />)

    expect(screen.getByText('Album One')).toBeInTheDocument()
    expect(screen.getByText('Album Two')).toBeInTheDocument()
    expect(screen.getByText('Artist A')).toBeInTheDocument()
  })

  it('shows empty state when no albums exist', () => {
    mockUseGetAlbumIndex.mockReturnValue(
      mockQueryResult({ data: [], current_page: 1, last_page: 1, total: 0 }),
    )

    renderWithProviders(<AlbumsPage />)

    const emptyEl = screen.getByText('No albums yet')
    expect(emptyEl).toBeInTheDocument()
  })
})
