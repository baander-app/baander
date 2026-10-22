import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { MoviePlayerOverlay } from '@/features/catalog/components/MoviePlayerOverlay'

const { playback } = vi.hoisted(() => ({ playback: {
  currentTime: 3, duration: 180, pendingSeekPosition: null as number | null, seekTo: vi.fn(),
} }))
vi.mock('@/features/catalog/hooks/useBaanderPlayer', () => ({ useBaanderPlayer: () => playback }))

afterEach(cleanup)
beforeEach(() => {
  playback.pendingSeekPosition = null
  playback.seekTo.mockClear()
})

function renderPlayer() {
  render(<ThemeProvider theme={resolveTheme('dark', 'violet')}>
    <MoviePlayerOverlay title="Fixture movie" videoId="video" onClose={vi.fn()} />
  </ThemeProvider>)
  return screen.getByRole('slider', { name: 'Seek movie' })
}

describe('movie source timeline', () => {
  it('previews a position across the full source and commits it on pointer release', () => {
    const timeline = renderPlayer()
    expect(timeline).toHaveAttribute('max', '180')
    fireEvent.change(timeline, { target: { value: '120' } })
    expect(playback.seekTo).not.toHaveBeenCalled()
    expect(timeline).toHaveAttribute('aria-valuetext', '2:00 of 3:00')
    fireEvent.pointerUp(timeline)
    expect(playback.seekTo).toHaveBeenCalledExactlyOnceWith(120)
  })

  it('commits keyboard source seeking and exposes the pending global position', () => {
    playback.pendingSeekPosition = 120
    const timeline = renderPlayer()
    expect(timeline).toHaveValue('120')
    fireEvent.change(timeline, { target: { value: '121' } })
    fireEvent.keyUp(timeline, { key: 'ArrowRight' })
    expect(playback.seekTo).toHaveBeenCalledExactlyOnceWith(121)
  })

  it('abandons a cancelled pointer gesture without seeking', () => {
    const timeline = renderPlayer()
    fireEvent.change(timeline, { target: { value: '120' } })
    fireEvent.pointerCancel(timeline)
    expect(timeline).toHaveValue('3')
    expect(playback.seekTo).not.toHaveBeenCalled()
  })

  it('discards an uncommitted drag when another movie replaces the overlay', () => {
    const theme = resolveTheme('dark', 'violet')
    const view = render(<ThemeProvider theme={theme}>
      <MoviePlayerOverlay title="First movie" videoId="first" onClose={vi.fn()} />
    </ThemeProvider>)
    const timeline = screen.getByRole('slider', { name: 'Seek movie' })
    fireEvent.change(timeline, { target: { value: '120' } })
    view.rerender(<ThemeProvider theme={theme}>
      <MoviePlayerOverlay title="Second movie" videoId="second" onClose={vi.fn()} />
    </ThemeProvider>)
    expect(timeline).toHaveValue('3')
    fireEvent.pointerUp(timeline)
    expect(playback.seekTo).not.toHaveBeenCalled()
  })
})
