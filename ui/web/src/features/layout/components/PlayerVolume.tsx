import { Volume2, VolumeX } from 'lucide-react'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { Button } from '@/shared/components/ui/button'

export function PlayerVolume() {
  const volume = usePlayerStore((s) => s.volume)
  const muted = usePlayerStore((s) => s.muted)
  const toggleMute = usePlayerStore((s) => s.toggleMute)

  return (
    <Button variant="ghost" size="icon-xs" onClick={toggleMute} aria-label={muted ? 'Unmute' : 'Mute'}>
      {muted || volume === 0 ? <VolumeX size={12} /> : <Volume2 size={12} />}
    </Button>
  )
}
