import { captureOwnership, isCurrentOwnership, subscribeOwnership } from '@/shared/live/ownership'
import { type FormEvent, useEffect, useState } from 'react'
import styled from 'styled-components'
import { textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'
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
import { useReplaceOAuthClientKeys } from '../../hooks/use-oauth-clients'
import { KeySourceFields } from './KeySourceFields'
import { keySourcePayload, type KeySourceKind } from './key-source-rules'

const Form = styled.form`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const ErrorText = styled.p`
  ${textRole('body-sm')}
  color: var(--color-destructive);
`

interface ReplaceKeysDialogProps {
  client: AdminOAuthClientResource | null
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function ReplaceKeysDialog({ client, open, onOpenChange }: ReplaceKeysDialogProps) {
  const replace = useReplaceOAuthClientKeys()
  const [kind, setKind] = useState<KeySourceKind>('jwksUri')
  const [value, setValue] = useState('')
  const [error, setError] = useState<string | null>(null)
  useEffect(() => subscribeOwnership(() => onOpenChange(false)), [onOpenChange])

  const close = () => {
    setKind('jwksUri')
    setValue('')
    setError(null)
    replace.reset()
    onOpenChange(false)
  }

  const changeKind = (next: KeySourceKind) => {
    setKind(next)
    setValue('')
    setError(null)
  }

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()
    if (!client) return

    setError(null)
    const keySource = keySourcePayload(kind, value)
    if (keySource.payload === null) {
      setError(keySource.problem)
      return
    }

    const stamp = captureOwnership()
    try {
      await replace.mutateAsync({ clientId: client.clientId, keys: keySource.payload })
      if (!isCurrentOwnership(stamp)) return
      close()
    } catch (err: unknown) {
      if (!isCurrentOwnership(stamp)) return
      setError(parseApiError(err, 'Could not replace the keys.').message)
    }
  }

  if (!client) return null

  return (
    <Dialog open={open} onOpenChange={(next) => { if (!next) close() }}>
      <DialogContent style={{ maxWidth: '32rem' }}>
        <DialogHeader>
          <DialogTitle>Replace keys</DialogTitle>
          <DialogDescription>
            Give <ContentText textRole="body" text={client.name} /> new public keys? Assertions signed with the current keys stop
            working at once, so the app cannot sign anyone in until it signs with a new key.
          </DialogDescription>
        </DialogHeader>
        <Form onSubmit={handleSubmit}>
          <KeySourceFields idPrefix="replace-keys" kind={kind} value={value} onKindChange={changeKind} onValueChange={setValue} />
          {error !== null && <ErrorText role="alert">{error}</ErrorText>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={close}>Cancel</Button>
            <Button type="submit" variant="destructive" disabled={replace.isPending}>
              {replace.isPending ? 'Replacing...' : 'Replace keys'}
            </Button>
          </DialogFooter>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
