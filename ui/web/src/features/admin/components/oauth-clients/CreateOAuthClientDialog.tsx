import { captureOwnership, isCurrentOwnership, subscribeOwnership } from '@/shared/live/ownership'
import { type FormEvent, useState, useEffect } from 'react'
import styled from 'styled-components'
import { textRole } from '@/shared/theme'
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
  AUTH_METHOD_LABELS,
  CLIENT_TYPE_LABELS,
  CONFIDENTIAL_AUTH_METHODS,
  CREATABLE_CLIENT_TYPES,
  type ConfidentialAuthMethod,
  type CreatableClientType,
  isConfidentialAuthMethod,
  isCreatableClientType,
} from './client-type-labels'
import { KeySourceFields } from './KeySourceFields'
import { keySourcePayload, type KeySourceKind, type KeySourcePayload } from './key-source-rules'
import { parseRedirectUris, redirectUrisProblem } from './redirect-uri-rules'

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
  ${textRole('label')}
  color: var(--color-muted-foreground);
`

const Hint = styled.p`
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

const ErrorText = styled.p`
  ${textRole('body-sm')}
  color: var(--color-destructive);
`

const MonoTextarea = styled(Textarea)`
  ${textRole('mono-sm')}
`

const TYPE_HINTS: Record<CreatableClientType, string> = {
  device: 'For devices without a browser, such as TV apps. Uses the device flow; no redirect URI.',
  public: 'For apps that cannot keep a secret, such as mobile and desktop apps. Uses PKCE.',
  confidential: 'For server-side apps. Authenticates with a client secret, shown once, or with its own signing keys.',
}

const AUTH_METHOD_HINTS: Record<ConfidentialAuthMethod, string> = {
  client_secret_post: 'Bånder generates a secret and shows it once.',
  private_key_jwt: 'The app signs each request with its private key; Bånder keeps only the public keys. Fixed once the client exists.',
}

function needsRedirectUris(type: CreatableClientType): boolean {
  return type !== 'device'
}

interface CreateOAuthClientDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function CreateOAuthClientDialog({ open, onOpenChange }: CreateOAuthClientDialogProps) {
  const [name, setName] = useState('')
  const [type, setType] = useState<CreatableClientType>('public')
  const [redirectText, setRedirectText] = useState('')
  const [authMethod, setAuthMethod] = useState<ConfidentialAuthMethod>('client_secret_post')
  const [keyKind, setKeyKind] = useState<KeySourceKind>('jwksUri')
  const [keyValue, setKeyValue] = useState('')
  const [formError, setFormError] = useState<string | null>(null)
  const createClient = useCreateOAuthClient()
  const { reset: resetSecret } = createClient
  useEffect(() => subscribeOwnership(() => {
    resetSecret()
    onOpenChange(false)
  }), [resetSecret, onOpenChange])

  const created = createClient.data
  const secret = created?.clientSecret ?? null

  const close = () => {
    setName('')
    setType('public')
    setRedirectText('')
    setAuthMethod('client_secret_post')
    setKeyKind('jwksUri')
    setKeyValue('')
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
      const problem = redirectUrisProblem(redirectUris)
      if (problem !== null) {
        setFormError(problem)
        return
      }
    }

    const usesKeys = type === 'confidential' && authMethod === 'private_key_jwt'
    let keys: KeySourcePayload | null = null
    if (usesKeys) {
      const keySource = keySourcePayload(keyKind, keyValue)
      if (keySource.payload === null) {
        setFormError(keySource.problem)
        return
      }
      keys = keySource.payload
    }

    const stamp = captureOwnership()
    try {
      const result = await createClient.mutateAsync({
        name: name.trim(),
        type,
        ...(needsRedirectUris(type) ? { redirectUris } : {}),
        ...(keys !== null ? { authMethod: 'private_key_jwt', ...keys } : {}),
      })
      if (!isCurrentOwnership(stamp)) return
      if (result.clientSecret === null) close()
    } catch (err: unknown) {
      if (!isCurrentOwnership(stamp)) return
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
                  <Hint>
                    One per line, up to 10, without a fragment: https, http on a loopback host (any port
                    matches), or a private-use scheme such as app.baander.tv:/callback.
                  </Hint>
                </FieldGroup>
              )}
              {type === 'confidential' && (
                <FieldGroup>
                  <Label id="oauth-client-auth-method-label">Authentication</Label>
                  <Select value={authMethod} onValueChange={(value) => {
                    if (isConfidentialAuthMethod(value)) setAuthMethod(value)
                  }}>
                    <SelectTrigger aria-labelledby="oauth-client-auth-method-label">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {CONFIDENTIAL_AUTH_METHODS.map((option) => (
                        <SelectItem key={option} value={option}>{AUTH_METHOD_LABELS[option]}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <Hint>{AUTH_METHOD_HINTS[authMethod]}</Hint>
                </FieldGroup>
              )}
              {type === 'confidential' && authMethod === 'private_key_jwt' && (
                <KeySourceFields
                  idPrefix="oauth-client"
                  kind={keyKind}
                  value={keyValue}
                  onKindChange={(next) => {
                    setKeyKind(next)
                    setKeyValue('')
                  }}
                  onValueChange={setKeyValue}
                />
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
