import { VisuallyHidden } from 'radix-ui'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { useCurrentTime } from '@/features/player/stores/player-time-tracker'
import type { PlaybackRecovery } from '@/features/player/stores/player-types'
import { Button } from '@/shared/components/ui/button'

const RECOVERY_TEXT: Record<Exclude<PlaybackRecovery, 'none'>, string> = {
  reconnecting: 'Reconnecting…',
  'tap-to-resume': 'Tap to resume',
}

/**
 * The playback position, or the state of an interrupted track in its place. Each player bar
 * shows one; the single announcer below speaks for all of them.
 */
export function PlaybackClock({ format }: { format: (seconds: number) => string }) {
  const currentTime = useCurrentTime()
  const recovery = usePlayerStore((s) => s.playbackRecovery)
  const setIsPlaying = usePlayerStore((s) => s.setIsPlaying)

  if (recovery === 'reconnecting') return <>{RECOVERY_TEXT.reconnecting}</>
  if (recovery === 'tap-to-resume') {
    return (
      <Button variant="ghost" size="xs" onClick={() => setIsPlaying(true)}>
        {RECOVERY_TEXT['tap-to-resume']}
      </Button>
    )
  }
  return <>{format(currentTime)}</>
}

/** Mounted once, so a screen reader hears each recovery state once however many bars show it. */
export function PlaybackRecoveryAnnouncer() {
  const recovery = usePlayerStore((s) => s.playbackRecovery)
  return (
    <VisuallyHidden.Root role="status" aria-live="polite">
      {recovery === 'none' ? '' : RECOVERY_TEXT[recovery]}
    </VisuallyHidden.Root>
  )
}
