import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { Button } from '@/shared/components/ui/button'
import { Radio, Plus, ArrowRightLeft } from 'lucide-react'
import type { SessionData } from '../hooks/use-session'
import styled from 'styled-components'

const ButtonRow = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`;

const FullWidthButton = styled(Button)`
  width: 100%;
`;

const IconWrapper = styled.span`
  margin-right: 0.5rem;
  display: flex;
`;

interface SessionTransferPromptProps {
  open: boolean
  session: SessionData | null
  onClaim: () => void
  onClaimWithQueue: () => void
  onNew: () => void
  onDismiss: () => void
  isClaiming: boolean
  isCreating: boolean
}

export function SessionTransferPrompt({
  open,
  session,
  onClaim,
  onClaimWithQueue,
  onNew,
  onDismiss,
  isClaiming,
  isCreating,
}: SessionTransferPromptProps) {
  const hasQueue = session && session.queue.length > 0
  const trackLabel = hasQueue
    ? `${session.queue.length} track${session.queue.length !== 1 ? 's' : ''}`
    : ''

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onDismiss()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Resume listening?</DialogTitle>
          <DialogDescription>
            {hasQueue
              ? `Another device has a session with ${trackLabel} at position ${formatPosition(session.position)}.`
              : 'Another device has an active session.'}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter style={{ flexDirection: 'column', gap: '0.5rem' }}>
          <ButtonRow>
            <FullWidthButton
              onClick={onClaim}
              disabled={isClaiming}
            >
              <IconWrapper><Radio size={14} /></IconWrapper>
              {isClaiming ? 'Resuming...' : 'Resume here'}
            </FullWidthButton>
            <FullWidthButton
              variant="outline"
              onClick={onClaimWithQueue}
              disabled={isClaiming}
            >
              <IconWrapper><ArrowRightLeft size={14} /></IconWrapper>
              {isClaiming ? 'Transferring...' : 'Bring local queue'}
            </FullWidthButton>
            <FullWidthButton
              variant="outline"
              onClick={onNew}
              disabled={isCreating}
            >
              <IconWrapper><Plus size={14} /></IconWrapper>
              {isCreating ? 'Starting...' : 'Start new session'}
            </FullWidthButton>
          </ButtonRow>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function formatPosition(seconds: number): string {
  const mins = Math.floor(seconds / 60)
  const secs = Math.floor(seconds % 60)
  return `${mins}:${secs.toString().padStart(2, '0')}`
}
