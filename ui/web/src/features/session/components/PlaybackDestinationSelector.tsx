import { LoaderCircle, Monitor, TriangleAlert } from 'lucide-react'
import styled, { keyframes } from 'styled-components'
import { useId, useState } from 'react'
import { Button } from '@/shared/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/shared/components/ui/dropdown-menu'
import { usePlaybackSession } from '../hooks/use-playback-session'
import { hasUnconfirmedPlayback, overridableHold } from '../services/playback-status'
import { textRole } from '@/shared/theme'

const DestinationMenu = styled(DropdownMenuContent)`
  min-width: 16rem;
  box-shadow: none;
`
const Control = styled(Button).attrs({ variant: 'ghost' })`
  gap: 0.25rem;
  max-width: 12rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`
const Status = styled.p`
  max-width: 20rem;
  margin: 0.5rem;
  color: var(--color-muted-foreground);
  ${textRole('caption')}
`

const rotate = keyframes`
  to { transform: rotate(360deg); }
`
const TransferSpinner = styled(LoaderCircle)`
  animation: ${rotate} 900ms linear infinite;
  @media (prefers-reduced-motion: reduce) { animation: none; }
`
const CompactStatus = styled.span`
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
`

export function PlaybackDestinationSelector({ compact = false }: { compact?: boolean }) {
  const { snapshot, endpoint, connected, error, localRecovery, transfer, reconcile, override, retry } = usePlaybackSession()
  const statusId = useId()
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState<string | null>(null)
  const active = snapshot?.destinations.find(destination => destination.incarnationId === snapshot.activeIncarnationId)
  const available = snapshot?.destinations.filter(destination => destination.available) ?? []
  const uncertain = snapshot?.transfer?.state === 'uncertain'
  const unconfirmed = hasUnconfirmedPlayback(snapshot)
  const hold = overridableHold(snapshot)
  const recoveryMessage = localRecovery?.status === 'recovered' && (snapshot?.activeIncarnationId || snapshot?.transfer)
    ? null : localRecovery?.message
  const recovering = localRecovery?.status === 'recovering'
  const progress = recovering ? 'Recovering local playback…' : unconfirmed ? 'Previous playback is unconfirmed.' : uncertain ? 'Playback at the destination is uncertain.' : snapshot?.transfer ? 'Transferring playback…' : null
  const perform = async (action: () => Promise<void>): Promise<boolean> => {
    setBusy(true)
    setFailure(null)
    try {
      await action()
      return true
    } catch (reason) {
      setFailure(reason instanceof Error ? reason.message : 'Playback destination could not change.')
      return false
    } finally { setBusy(false) }
  }
  // The menu stays open until the server has answered, so a refusal appears where the listener pressed.
  const playHereAnyway = async () => {
    if (await perform(override)) setOpen(false)
  }
  const overrideConsequence = 'Play here anyway ends its hold; if it is still playing, it continues until it reconnects.'
  return (
    <DropdownMenu open={open} onOpenChange={setOpen}>
      <DropdownMenuTrigger asChild>
        <Control type="button" size={compact ? 'icon-xs' : 'xs'} aria-label="Playback destination" aria-describedby={compact && progress ? statusId : undefined} title={progress ?? (active?.available ? `Playing on ${active.name}` : 'Choose playback destination')}>
          {uncertain || unconfirmed ? <TriangleAlert size={14} aria-hidden="true" /> : (snapshot?.transfer || recovering) ? <TransferSpinner size={14} aria-hidden="true" /> : <Monitor size={14} aria-hidden="true" />}
          {!compact && (recovering ? 'Recovering…' : snapshot?.transfer ? (uncertain ? 'Playback uncertain' : 'Transferring…') : active?.available ? active.name : 'This browser')}
        </Control>
      </DropdownMenuTrigger>
      {compact && progress ? <CompactStatus id={statusId} role="status">{progress}</CompactStatus> : null}
      <DestinationMenu align="end">
        <DropdownMenuLabel>Playback destination</DropdownMenuLabel>
        {available.map(destination => {
          const local = destination.incarnationId === endpoint?.incarnationId
          const incompatible = snapshot?.media && !destination.capabilities.includes(snapshot.media.kind)
          const reason = incompatible ? 'Media not supported' : null
          const selected = destination.incarnationId === snapshot?.activeIncarnationId
          return (
            <DropdownMenuItem key={destination.destinationId}
              disabled={!connected || busy || recovering || unconfirmed || !!snapshot?.transfer || !!reason || selected}
              onSelect={() => { if (destination.incarnationId) perform(() => transfer(destination.incarnationId!)) }}>
              {local ? 'This browser' : destination.name}{selected ? ' — Active' : ''}{reason ? ` — ${reason}` : ''}
            </DropdownMenuItem>
          )
        })}
        {!available.length ? <DropdownMenuItem disabled>This browser — Connecting…</DropdownMenuItem> : null}
        {active?.available && active.incarnationId !== endpoint?.incarnationId && endpoint ? (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem disabled={!connected || busy || recovering || unconfirmed || !!snapshot?.transfer} onSelect={() => { perform(() => transfer(endpoint.incarnationId)) }}>
              Return playback to this browser
            </DropdownMenuItem>
            <Status>Sound processing and volume depend on the destination. This browser’s EQ does not apply remotely.</Status>
          </>
        ) : null}
        {uncertain ? (
          <>
            <Status role="status">
              The destination has not confirmed it stopped. Playback here remains paused until it is safe to resume.
              {hold ? ` It is unavailable. ${overrideConsequence}` : null}
            </Status>
            <DropdownMenuItem disabled={!connected || busy} onSelect={() => { perform(reconcile) }}>Check destination stopped</DropdownMenuItem>
          </>
        ) : unconfirmed ? (
          <Status role="status">
            {active ? active.name : 'The previous destination'} is unavailable and has not confirmed that it stopped.
            {hold ? ` ${overrideConsequence}` : null}
          </Status>
        ) : null}
        {hold ? (
          <DropdownMenuItem disabled={!connected || busy || recovering} onSelect={event => { event.preventDefault(); playHereAnyway() }}>
            Play here anyway
          </DropdownMenuItem>
        ) : null}
        {!connected ? <Status role="status">Connection lost. Remote playback may still be active.</Status> : null}
        {recoveryMessage ? <Status role={localRecovery?.status === 'unavailable' ? 'alert' : 'status'}>{recoveryMessage}</Status> : null}
        {localRecovery?.status === 'retryable' && !error ? <DropdownMenuItem disabled={busy} onSelect={() => { perform(retry) }}>Retry local recovery</DropdownMenuItem> : null}
        {error ? <DropdownMenuItem disabled={busy} onSelect={() => { perform(retry) }}>{connected ? 'Check playback request' : 'Retry playback connection'}</DropdownMenuItem> : null}
        {failure || error ? <Status role="alert">{failure ?? error}</Status> : null}
      </DestinationMenu>
    </DropdownMenu>
  )
}
