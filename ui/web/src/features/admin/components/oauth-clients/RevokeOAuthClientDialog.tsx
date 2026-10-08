import { useState } from 'react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { parseApiError } from '@/features/auth/lib/parse-api-error'
import { type AdminOAuthClientResource } from '@/shared/api-client/gen/endpoints'
import { useRevokeOAuthClient } from '../../hooks/use-oauth-clients'

const ErrorText = styled.p`
  font-size: 0.8125rem;
  color: var(--color-destructive);
`

interface RevokeOAuthClientDialogProps {
  client: AdminOAuthClientResource | null
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function RevokeOAuthClientDialog({ client, open, onOpenChange }: RevokeOAuthClientDialogProps) {
  const revoke = useRevokeOAuthClient()
  const [error, setError] = useState<string | null>(null)

  const close = () => {
    setError(null)
    revoke.reset()
    onOpenChange(false)
  }

  const confirm = async () => {
    if (!client) return

    setError(null)
    try {
      await revoke.mutateAsync(client.clientId)
      close()
    } catch (err: unknown) {
      setError(parseApiError(err, 'Could not revoke the client.').message)
    }
  }

  if (!client) return null

  return (
    <Dialog open={open} onOpenChange={(next) => { if (!next) close() }}>
      <DialogContent style={{ maxWidth: '28rem' }}>
        <DialogHeader>
          <DialogTitle>Revoke client</DialogTitle>
          <DialogDescription>
            Revoke {client.name}? Every token issued to it stops working and it cannot sign anyone in again.
            This cannot be undone.
          </DialogDescription>
        </DialogHeader>
        {error !== null && <ErrorText role="alert">{error}</ErrorText>}
        <DialogFooter>
          <Button variant="outline" onClick={close}>Cancel</Button>
          <Button variant="destructive" disabled={revoke.isPending} onClick={confirm}>
            {revoke.isPending ? 'Revoking...' : 'Revoke'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
