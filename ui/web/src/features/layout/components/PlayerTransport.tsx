import { Play, Pause, SkipBack, SkipForward, Shuffle, Repeat, Repeat1 } from 'lucide-react'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { Button } from '@/shared/components/ui/button'
import { DevicePicker } from '@/features/session/components/DevicePicker'

export function PlayerTransport() {
  const isPlaying = usePlayerStore((s) => s.isPlaying)
  const setIsPlaying = usePlayerStore((s) => s.setIsPlaying)
  const playNext = usePlayerStore((s) => s.playNext)
  const playPrevious = usePlayerStore((s) => s.playPrevious)
  const shuffle = usePlayerStore((s) => s.shuffle)
  const repeat = usePlayerStore((s) => s.repeat)
  const toggleShuffle = usePlayerStore((s) => s.toggleShuffle)
  const toggleRepeat = usePlayerStore((s) => s.toggleRepeat)

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '0.125rem' }}>
      <Button variant={shuffle ? 'secondary' : 'ghost'} size="icon-xs" onClick={toggleShuffle} aria-label="Shuffle">
        <Shuffle size={12} />
      </Button>
      <Button variant="ghost" size="icon-xs" onClick={playPrevious} aria-label="Previous">
        <SkipBack size={13} fill="currentColor" />
      </Button>
      <Button variant="ghost" size="icon-sm" onClick={() => setIsPlaying(!isPlaying)} aria-label={isPlaying ? 'Pause' : 'Play'}>
        {isPlaying ? <Pause size={16} fill="currentColor" /> : <Play size={16} fill="currentColor" />}
      </Button>
      <Button variant="ghost" size="icon-xs" onClick={playNext} aria-label="Next">
        <SkipForward size={13} fill="currentColor" />
      </Button>
      <Button variant={repeat !== 'off' ? 'secondary' : 'ghost'} size="icon-xs" onClick={toggleRepeat} aria-label="Repeat">
        {repeat === 'one' ? <Repeat1 size={12} /> : <Repeat size={12} />}
      </Button>
      <DevicePicker />
    </div>
  )
}
