import { LoaderCircle, Monitor, TriangleAlert } from 'lucide-react'
import styled, { keyframes } from 'styled-components'
import { useId, useState } from 'react'
import { Button } from '@/shared/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/shared/components/ui/dropdown-menu'
import { usePlaybackSession } from '../hooks/use-playback-session'
import { hasUnconfirmedPlayback } from '../services/playback-status'
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
  const { snapshot, endpoint, connected, error, localRecovery, transfer, reconcile, retry } = usePlaybackSession()
  const statusId = useId()
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState<string | null>(null)
  const active = snapshot?.destinations.find(destination => destination.incarnationId === snapshot.activeIncarnationId)
  const available = snapshot?.destinations.filter(destination => destination.available) ?? []
  const uncertain = snapshot?.transfer?.state === 'uncertain'
  const unconfirmed = hasUnconfirmedPlayback(snapshot)
  const recoveryMessage = localRecovery?.status === 'recovered' && (snapshot?.activeIncarnationId || snapshot?.transfer)
    ? null : localRecovery?.message
  const recovering = localRecovery?.status === 'recovering'
  const progress = recovering ? 'Recovering local playback…' : unconfirmed ? 'Previous playback is unconfirmed.' : uncertain ? 'Playback at the destination is uncertain.' : snapshot?.transfer ? 'Transferring playback…' : null
  const perform = async (action: () => Promise<void>) => {
    setBusy(true)
    setFailure(null)
    try { await action() }
    catch (reason) { setFailure(reason instanceof Error ? reason.message : 'Playback destination could not change.') }
    finally { setBusy(false) }
  }
  return (
    <DropdownMenu>
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
            <Status role="status">The destination has not confirmed it stopped. Playback here remains paused until it is safe to resume.</Status>
            <DropdownMenuItem disabled={!connected || busy} onSelect={() => { perform(reconcile) }}>Check destination stopped</DropdownMenuItem>
          </>
        ) : null}
        {unconfirmed ? <Status role="status">{active ? `${active.name} is unavailable` : 'The previous destination is unavailable'} and may still be playing. Resume requires its stop acknowledgement or a valid local recovery proof.</Status> : null}
        {!connected ? <Status role="status">Connection lost. Remote playback may still be active.</Status> : null}
        {recoveryMessage ? <Status role={localRecovery?.status === 'unavailable' ? 'alert' : 'status'}>{recoveryMessage}</Status> : null}
        {localRecovery?.status === 'retryable' && !error ? <DropdownMenuItem disabled={busy} onSelect={() => { perform(retry) }}>Retry local recovery</DropdownMenuItem> : null}
        {error ? <DropdownMenuItem disabled={busy} onSelect={() => { perform(retry) }}>{connected ? 'Check playback request' : 'Retry playback connection'}</DropdownMenuItem> : null}
        {failure || error ? <Status role="alert">{failure ?? error}</Status> : null}
      </DestinationMenu>
    </DropdownMenu>
  )
}
