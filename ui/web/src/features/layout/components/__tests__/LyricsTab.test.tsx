import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { LyricsTab } from '../LyricsTab'

const { lyrics } = vi.hoisted(() => ({
  lyrics: { current: { plainLyrics: null as string | null, syncedLyrics: null as string | null, source: 'lrclib', isInstrumental: false } },
}))

vi.mock('@/features/auth/hooks/use-admin-check', () => ({ useIsAdmin: () => false }))
vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (state: unknown) => unknown) =>
    selector({ currentTrack: { publicId: 'song-1', title: '直', albumLanguage: 'jpn' }, seekTo: () => {} }),
}))
vi.mock('@/features/player/stores/player-time-tracker', () => ({ useCurrentTime: () => 0 }))
vi.mock('@tanstack/react-query', () => ({ useQueryClient: () => ({ invalidateQueries: () => {} }) }))
vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetLyricsSongLyrics: () => ({ data: { data: lyrics.current }, isPending: false, isError: false }),
  usePostLyricsSongLyricsFetch: () => ({ mutate: () => {}, isPending: false }),
  getGetLyricsSongLyricsQueryKey: () => ['lyrics'],
}))

class ResizeObserverStub {
  observe() {}
  disconnect() {}
}

describe('LyricsTab', () => {
  beforeEach(() => {
    globalThis.ResizeObserver = ResizeObserverStub as unknown as typeof ResizeObserver
    Element.prototype.scrollTo = vi.fn()
  })

  it('renders plain Thai lyrics as one block with the Thai script rules', () => {
    lyrics.current = { plainLyrics: 'ฉันรักเธอ\nทุกวัน', syncedLyrics: null, source: 'lrclib', isInstrumental: false }
    render(<LyricsTab />)

    const block = screen.getByText((_, element) => element?.tagName === 'PRE' && (element.textContent ?? '').startsWith('ฉันรักเธอ'))
    expect(block.getAttribute('data-script')?.split(' ')).toContain('thai')
  })

  it('renders each synced line in the song language', () => {
    lyrics.current = { plainLyrics: null, syncedLyrics: '[00:01.00]直\n[00:05.00]ทุกวัน', source: 'lrclib', isInstrumental: false }
    render(<LyricsTab />)

    expect(screen.getByText('直')).toHaveAttribute('lang', 'ja')
    expect(screen.getByText('ทุกวัน').getAttribute('data-script')?.split(' ')).toContain('thai')
  })
})
