import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { ProgressBar } from '@/features/player/components/ProgressBar'
import { PlaybackRecoveryAnnouncer } from '@/features/player/components/PlaybackRecoveryStatus'
import { PlayerTimeLabel } from '@/features/layout/components/PlayerTimeLabel'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { updateTime } from '@/features/player/stores/player-time-tracker'

/** The full player's progress row, the mini-player's time, and the shell's single announcer. */
function renderPlayers() {
  return render(
    <>
      <ProgressBar />
      <PlayerTimeLabel />
      <PlaybackRecoveryAnnouncer />
    </>,
  )
}

describe('playback recovery status', () => {
  beforeEach(() => {
    usePlayerStore.setState({ currentTrack: { publicId: 'song-1', title: 'Song', available: true }, isPlaying: true, duration: 200, audioElement: null, playbackRecovery: 'none' })
    updateTime(42)
  })

  afterEach(() => {
    act(() => {
      updateTime(0)
      usePlayerStore.setState({ isPlaying: false, duration: 0, playbackRecovery: 'none' })
    })
  })

  it('shows the position in both players and announces nothing while playback is healthy', () => {
    renderPlayers()
    expect(screen.getByText('0:42')).toBeInTheDocument()
    expect(screen.getByText('00:42')).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('')
  })

  it('shows Reconnecting… in place of the time and announces it through one polite region', () => {
    renderPlayers()
    act(() => { usePlayerStore.setState({ playbackRecovery: 'reconnecting' }) })

    expect(screen.queryByText('0:42')).not.toBeInTheDocument()
    expect(screen.queryByText('00:42')).not.toBeInTheDocument()
    const regions = screen.getAllByRole('status')
    expect(regions).toHaveLength(1)
    expect(regions[0]).toHaveAttribute('aria-live', 'polite')
    expect(regions[0]).toHaveTextContent('Reconnecting…')
    // The visible labels are not live regions, so the message is spoken once.
    expect(screen.getAllByText('Reconnecting…')).toHaveLength(3)
    // The seek bar keeps the frozen position.
    expect(screen.getByRole('slider', { name: 'Seek' })).toHaveAttribute('aria-valuenow', '42')
  })

  it('offers Tap to resume as a keyboard-reachable button that resumes playback', async () => {
    const user = userEvent.setup()
    usePlayerStore.setState({ isPlaying: false, playbackRecovery: 'tap-to-resume' })
    renderPlayers()

    expect(screen.getByRole('status')).toHaveTextContent('Tap to resume')
    const buttons = screen.getAllByRole('button', { name: 'Tap to resume' })
    expect(buttons).toHaveLength(2)
    await user.tab()
    expect(buttons[0]).toHaveFocus()
    await user.keyboard('{Enter}')
    expect(usePlayerStore.getState().isPlaying).toBe(true)
  })
})
