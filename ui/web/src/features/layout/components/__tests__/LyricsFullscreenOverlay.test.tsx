import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { useEqBandsStore } from '@/features/equalizer/stores/eq-bands-store'
import { useLyricsFullscreenStore } from '../../stores/lyrics-fullscreen-store'
import { LyricsFullscreenOverlay } from '../LyricsFullscreenOverlay'

vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (state: unknown) => unknown) =>
    selector({
      currentTrack: { publicId: 'song-1', albumPublicId: 'album-1', title: 'Still Alive' },
      isPlaying: false,
      setIsPlaying: () => {},
      playNext: () => {},
      playPrevious: () => {},
      duration: 180,
      seekTo: () => {},
    }),
}))
vi.mock('@/features/player/stores/player-time-tracker', () => ({ useCurrentTime: () => 0 }))
vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetLyricsSongLyrics: () => ({ data: undefined }),
  useGetAlbumShow: () => ({ data: undefined }),
}))
vi.mock('@/features/visualizer/components/VisualizerHost', () => ({
  VisualizerHost: ({ mode, className }: { mode: string; className?: string }) => (
    <div data-testid="visualizer" data-mode={mode} className={className} />
  ),
}))
vi.mock('@/features/visualizer/register-visualizer-renderers', () => ({ registerVisualizerRenderers: () => {} }))
vi.mock('@/features/visualizer/hooks/use-album-palette', () => ({ useAlbumPalette: () => {} }))

const EFFECTS = ['Enhanced', 'Circular', 'Spectrogram', 'Particles']

function mount() {
  return render(
    <ThemeProvider theme={resolveTheme('dark', 'violet')}>
      <LyricsFullscreenOverlay />
    </ThemeProvider>,
  )
}

function effectButton(name: string) {
  return screen.getByRole('button', { name })
}

function pressedEffects() {
  return EFFECTS.filter((name) => effectButton(name).getAttribute('aria-pressed') === 'true')
}

describe('LyricsFullscreenOverlay effects', () => {
  beforeEach(() => {
    useEqBandsStore.setState({ visualizerMode: 'spectrum' })
    useLyricsFullscreenStore.setState({ isOpen: true })
  })

  afterEach(() => {
    cleanup()
    act(() => {
      useLyricsFullscreenStore.setState({ isOpen: false })
    })
  })

  it('turns every effect off when the active effect is clicked again', async () => {
    const user = userEvent.setup()
    mount()

    await user.click(effectButton('Enhanced'))
    expect(pressedEffects()).toEqual(['Enhanced'])
    expect(screen.getByTestId('visualizer').getAttribute('data-mode')).toBe('enhanced-spectrum')

    await user.click(effectButton('Enhanced'))
    expect(pressedEffects()).toEqual([])
    expect(screen.queryByTestId('visualizer')).toBeNull()
    expect(useEqBandsStore.getState().visualizerMode).toBe('spectrum')
  })

  it('switches to another effect instead of turning effects off', async () => {
    const user = userEvent.setup()
    mount()

    await user.click(effectButton('Enhanced'))
    await user.click(effectButton('Circular'))

    expect(pressedEffects()).toEqual(['Circular'])
    expect(screen.getByTestId('visualizer').getAttribute('data-mode')).toBe('circular')
  })
})
