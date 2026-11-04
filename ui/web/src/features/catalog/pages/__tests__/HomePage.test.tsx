import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'

const testTheme = resolveTheme('dark', 'violet')

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetAlbumIndex: () => ({
    data: {
      data: [{
        publicId: 'album-1',
        title: 'Rammstein Live',
        language: 'de',
        artists: [{ name: 'Rammstein', role: null }],
        coverImage: null,
      }],
    },
    isPending: false,
  }),
  useGetActivityHistory: () => ({
    data: {
      data: [{
        songTitle: '花',
        songAvailable: false,
        songPublicId: 'song-1',
        albumPublicId: 'album-2',
        albumTitle: 'Album',
        artistName: 'Artist',
      }],
    },
    isPending: false,
  }),
  useGetPlaylistIndex: () => ({ data: { data: [] }, isPending: false }),
}))

vi.mock('@/features/catalog/hooks/use-for-you-view-model', () => ({
  useForYouViewModel: () => ({ songs: [], isLoading: false }),
}))

vi.mock('@/features/catalog/hooks/use-starred-stations', () => ({
  useStarredStations: () => ({ data: [], isPending: false }),
}))

vi.mock('@/features/catalog/hooks/use-play-album', () => ({
  usePlayAlbum: () => ({ playAlbum: vi.fn() }),
}))

vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (s: Record<string, unknown>) => unknown) => selector({ playTrack: vi.fn() }),
}))

vi.mock('@/features/layout/stores/context-panel-store', () => ({
  useContextPanelStore: (selector: (s: Record<string, unknown>) => unknown) => selector({ setSelectedItem: vi.fn() }),
}))

vi.mock('@/features/radio/hooks/use-radio-playback', () => ({
  useRadioPlayback: () => ({ start: vi.fn() }),
}))

vi.mock('@/features/radio/stores/radio-store', () => ({
  useRadioStore: (selector: (s: Record<string, unknown>) => unknown) => selector({ activeStation: null, isPlaying: false }),
}))

vi.mock('@/shared/hooks/use-image-blob', () => ({
  useImageBlob: () => ({ src: null, isLoading: false }),
}))

import { HomePage } from '../HomePage'

function renderPage() {
  render(<SCTypedThemeProvider theme={testTheme}><HomePage /></SCTypedThemeProvider>)
}

describe('HomePage', () => {
  it('sets an album card title in the album language and its artist in the interface language', () => {
    renderPage()
    for (const title of screen.getAllByText('Rammstein Live')) {
      expect(title).toHaveAttribute('lang', 'de')
    }
    for (const artist of screen.getAllByText('Rammstein')) {
      expect(artist).toHaveAttribute('lang', 'en')
    }
  })

  it('keeps a recently played song title apart from its unavailable note', () => {
    renderPage()
    const title = screen.getByText('花')
    expect(title).toHaveAttribute('lang')
    expect(title).not.toHaveTextContent('Unavailable')
    expect(screen.getByText('· Unavailable')).not.toHaveAttribute('lang')
  })
})
