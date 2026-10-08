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
import { useRotateOAuthClientSecret } from '../../hooks/use-oauth-clients'
import { ClientSecretReveal } from './ClientSecretReveal'

const ErrorText = styled.p`
  font-size: 0.8125rem;
  color: var(--color-destructive);
`

interface RotateSecretDialogProps {
  client: AdminOAuthClientResource | null
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function RotateSecretDialog({ client, open, onOpenChange }: RotateSecretDialogProps) {
  const rotate = useRotateOAuthClientSecret()
  const [error, setError] = useState<string | null>(null)
  const secret = rotate.data?.clientSecret ?? null

  const close = () => {
    setError(null)
    // Drops the answer, and with it the secret; it is never shown again.
    rotate.reset()
    onOpenChange(false)
  }

  const confirm = async () => {
    if (!client) return

    setError(null)
    try {
      await rotate.mutateAsync(client.clientId)
    } catch (err: unknown) {
      setError(parseApiError(err, 'Could not rotate the secret.').message)
    }
  }

  if (!client) return null

  return (
    <Dialog open={open} onOpenChange={(next) => { if (!next) close() }}>
      <DialogContent style={{ maxWidth: '32rem' }}>
        {secret !== null ? (
          <>
            <DialogHeader>
              <DialogTitle>New client secret</DialogTitle>
              <DialogDescription>The previous secret of {client.name} no longer works.</DialogDescription>
            </DialogHeader>
            <ClientSecretReveal clientName={client.name} secret={secret} />
            <DialogFooter>
              <Button onClick={close}>Done</Button>
            </DialogFooter>
          </>
        ) : (
          <>
            <DialogHeader>
              <DialogTitle>Rotate client secret</DialogTitle>
              <DialogDescription>
                Replace the secret of {client.name}? The current secret stops working at once, so the app
                cannot sign anyone in until it is configured with the new one.
              </DialogDescription>
            </DialogHeader>
            {error !== null && <ErrorText role="alert">{error}</ErrorText>}
            <DialogFooter>
              <Button variant="outline" onClick={close}>Cancel</Button>
              <Button variant="destructive" disabled={rotate.isPending} onClick={confirm}>
                {rotate.isPending ? 'Rotating...' : 'Rotate secret'}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
