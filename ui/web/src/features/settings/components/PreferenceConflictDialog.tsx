import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { Button } from '@/shared/components/ui/button'
import styled from 'styled-components'

const ButtonColumn = styled(DialogFooter)`
  flex-direction: column;
  gap: 0.5rem;

  @media (min-width: 640px) {
    flex-direction: column;
  }
`

const FullButton = styled(Button)`
  width: 100%;
`

interface PreferenceConflictDialogProps {
  open: boolean
  serverVersion: number | null
  onResolve: (resolution: 'mine' | 'theirs') => void
}

export function PreferenceConflictDialog({
  open,
  serverVersion,
  onResolve,
}: PreferenceConflictDialogProps) {
  return (
    <Dialog open={open} onOpenChange={(o) => !o && onResolve('theirs')}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Settings conflict</DialogTitle>
          <DialogDescription>
            These settings were updated on another device
            {serverVersion != null ? ` (version ${serverVersion})` : ''}.
            Which version do you want to keep?
          </DialogDescription>
        </DialogHeader>
        <ButtonColumn>
          <FullButton onClick={() => onResolve('mine')}>
            Keep my changes
          </FullButton>
          <FullButton
            variant="outline"
            onClick={() => onResolve('theirs')}
          >
            Use server version
          </FullButton>
        </ButtonColumn>
      </DialogContent>
    </Dialog>
  )
}
