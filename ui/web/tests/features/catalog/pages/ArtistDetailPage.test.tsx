import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetArtistShow: () => ({
    data: { publicId: 'artist_1', name: 'Test Artist' },
    isLoading: false,
  }),
  useGetAlbumIndex: () => ({
    data: {
      data: [
        { publicId: 'album_1', title: 'Album One', year: 2024, coverImage: null, artists: [] },
        { publicId: 'album_2', title: 'Album Two', year: 2023, coverImage: null, artists: [] },
      ],
    },
    isLoading: false,
  }),
}))

vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (s: unknown) => unknown) =>
    selector({ playTrack: vi.fn() }),
}))

vi.mock('@/features/layout/stores/context-panel-store', () => ({
  useContextPanelStore: (selector: (s: unknown) => unknown) =>
    selector({ setSelectedItem: vi.fn() }),
}))

import { ArtistDetailPage } from '@/features/catalog/pages/ArtistDetailPage'

describe('ArtistDetailPage', () => {
  it('renders artist name', () => {
    render(
      <MemoryRouter initialEntries={['/artists/artist_1']}>
        <Routes>
          <Route path="/artists/:publicId" element={<ArtistDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(screen.getByText('Test Artist')).toBeInTheDocument()
  })

  it('renders discography section with album cards', () => {
    render(
      <MemoryRouter initialEntries={['/artists/artist_1']}>
        <Routes>
          <Route path="/artists/:publicId" element={<ArtistDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    // Album grid cards should render with titles
    expect(screen.getByText('Album One')).toBeInTheDocument()
    expect(screen.getByText('Album Two')).toBeInTheDocument()
  })
})
