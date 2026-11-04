import { describe, it, expect, vi } from 'vitest'
import type { ReactNode } from 'react'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AlbumDetailPage } from '../AlbumDetailPage'

vi.mock('@tanstack/react-virtual', () => ({
  useVirtualizer: ({ count, estimateSize }: { count: number; estimateSize: (index: number) => number }) => ({
    getTotalSize: () => count * estimateSize(0),
    getVirtualItems: () =>
      Array.from({ length: count }, (_, index) => ({ index, key: index, size: estimateSize(index), start: index * estimateSize(index) })),
  }),
}))

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetAlbumShow: () => ({
    data: {
      data: {
        publicId: 'album-1',
        title: 'Ongaku',
        language: 'jpn',
        year: 1999,
        artists: [{ name: '坂本龍一', role: null }],
        coverImage: null,
        songs: [{ publicId: 'song-1', title: '直', available: true, length: 200, track: 1 }],
      },
    },
    isPending: false,
    isError: false,
    refetch: vi.fn(),
  }),
}))

vi.mock('../../api/album-duplicates-api', () => ({ useAlbumDuplicates: () => ({ data: [] }) }))
vi.mock('../../hooks/use-blurhash-accent', () => ({ useBlurhashAccent: () => {} }))
vi.mock('../../components/MergeAlbumsDialog', () => ({ MergeAlbumsDialog: () => null }))
vi.mock('../../components/menus/AlbumContextMenu', () => ({
  AlbumContextMenu: ({ children }: { children: ReactNode }) => children,
}))
vi.mock('../../components/menus/SongContextMenu', () => ({
  SongContextMenu: ({ children }: { children: ReactNode }) => children,
}))

describe('AlbumDetailPage', () => {
  it('renders the songs in the album language and the album artist in the interface language', () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <MemoryRouter initialEntries={['/albums/album-1']}>
          <Routes>
            <Route path="/albums/:publicId" element={<AlbumDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(screen.getByText('直')).toHaveAttribute('lang', 'ja')
    expect(screen.getByText('Ongaku')).toHaveAttribute('lang', 'ja')
    expect(screen.getByText('坂本龍一')).toHaveAttribute('lang', 'en')
  })
})
