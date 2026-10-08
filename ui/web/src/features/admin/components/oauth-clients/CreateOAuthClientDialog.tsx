import { type FormEvent, useState } from 'react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Textarea } from '@/shared/components/ui/textarea'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/components/ui/select'
import { parseApiError } from '@/features/auth/lib/parse-api-error'
import { useCreateOAuthClient } from '../../hooks/use-oauth-clients'
import { ClientSecretReveal } from './ClientSecretReveal'
import {
  CLIENT_TYPE_LABELS,
  CREATABLE_CLIENT_TYPES,
  type CreatableClientType,
  isCreatableClientType,
} from './client-type-labels'

const Form = styled.form`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const FieldGroup = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const Label = styled.label`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const Hint = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const ErrorText = styled.p`
  font-size: 0.8125rem;
  color: var(--color-destructive);
`

const MonoTextarea = styled(Textarea)`
  font-family: var(--font-mono);
  font-size: 0.8125rem;
`

const TYPE_HINTS: Record<CreatableClientType, string> = {
  device: 'For devices without a browser, such as TV apps. Uses the device flow; no redirect URI.',
  public: 'For apps that cannot keep a secret, such as mobile and desktop apps. Uses PKCE.',
  confidential: 'For server-side apps. Gets a client secret, shown once.',
}

function needsRedirectUris(type: CreatableClientType): boolean {
  return type !== 'device'
}

/** One URI per line; blank lines are ignored. */
function parseRedirectUris(text: string): string[] {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '')
}

function firstInvalidUri(uris: string[]): string | null {
  for (const uri of uris) {
    try {
      new URL(uri)
    } catch {
      return uri
    }
  }

  return null
}

interface CreateOAuthClientDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function CreateOAuthClientDialog({ open, onOpenChange }: CreateOAuthClientDialogProps) {
  const [name, setName] = useState('')
  const [type, setType] = useState<CreatableClientType>('public')
  const [redirectText, setRedirectText] = useState('')
  const [formError, setFormError] = useState<string | null>(null)
  const createClient = useCreateOAuthClient()

  const created = createClient.data
  const secret = created?.clientSecret ?? null

  const close = () => {
    setName('')
    setType('public')
    setRedirectText('')
    setFormError(null)
    // Drops the answer, and with it the secret; it is never shown again.
    createClient.reset()
    onOpenChange(false)
  }

  const handleOpenChange = (next: boolean) => {
    if (next) {
      onOpenChange(true)
      return
    }

    close()
  }

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setFormError(null)

    const redirectUris = needsRedirectUris(type) ? parseRedirectUris(redirectText) : []
    if (needsRedirectUris(type)) {
      if (redirectUris.length === 0) {
        setFormError('Add at least one redirect URI.')
        return
      }

      const invalid = firstInvalidUri(redirectUris)
      if (invalid !== null) {
        setFormError(`Not an absolute URI: ${invalid}`)
        return
      }
    }

    try {
      const result = await createClient.mutateAsync({
        name: name.trim(),
        type,
        ...(needsRedirectUris(type) ? { redirectUris } : {}),
      })
      if (result.clientSecret === null) close()
    } catch (err: unknown) {
      setFormError(parseApiError(err, 'Could not create the client.').message)
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent style={{ maxWidth: '32rem' }}>
        {created && secret !== null ? (
          <>
            <DialogHeader>
              <DialogTitle>Client created</DialogTitle>
              <DialogDescription>
                {created.name} has the client ID {created.clientId}.
              </DialogDescription>
            </DialogHeader>
            <ClientSecretReveal clientName={created.name} secret={secret} />
            <DialogFooter>
              <Button onClick={close}>Done</Button>
            </DialogFooter>
          </>
        ) : (
          <>
            <DialogHeader>
              <DialogTitle>Create OAuth client</DialogTitle>
              <DialogDescription>Register an app that signs users in to Bånder.</DialogDescription>
            </DialogHeader>
            <Form onSubmit={handleSubmit}>
              <FieldGroup>
                <Label htmlFor="oauth-client-name">Name</Label>
                <Input
                  id="oauth-client-name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  required
                  maxLength={100}
                />
              </FieldGroup>
              <FieldGroup>
                <Label id="oauth-client-type-label">Type</Label>
                <Select value={type} onValueChange={(value) => {
                  if (isCreatableClientType(value)) setType(value)
                }}>
                  <SelectTrigger aria-labelledby="oauth-client-type-label">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {CREATABLE_CLIENT_TYPES.map((option) => (
                      <SelectItem key={option} value={option}>{CLIENT_TYPE_LABELS[option]}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <Hint>{TYPE_HINTS[type]}</Hint>
              </FieldGroup>
              {needsRedirectUris(type) && (
                <FieldGroup>
                  <Label htmlFor="oauth-client-redirect-uris">Redirect URIs</Label>
                  <MonoTextarea
                    id="oauth-client-redirect-uris"
                    value={redirectText}
                    onChange={(e) => setRedirectText(e.target.value)}
                    placeholder="https://app.baander.app/callback"
                    rows={3}
                  />
                  <Hint>One per line, up to 10. Loopback URIs (http://127.0.0.1) match any port.</Hint>
                </FieldGroup>
              )}
              {formError !== null && <ErrorText role="alert">{formError}</ErrorText>}
              <DialogFooter>
                <Button type="button" variant="outline" onClick={close}>
                  Cancel
                </Button>
                <Button type="submit" disabled={createClient.isPending}>
                  {createClient.isPending ? 'Creating...' : 'Create'}
                </Button>
              </DialogFooter>
            </Form>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
