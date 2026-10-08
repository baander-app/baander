import { useState } from 'react'
import styled from 'styled-components'
import { Copy } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { createLogger } from '@/shared/lib/logger'

const logger = createLogger('ClientSecretReveal')

type CopyState = 'idle' | 'copied' | 'failed'

const Stack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const Warning = styled.p`
  border-radius: var(--radius-md);
  background-color: color-mix(in srgb, var(--color-destructive) 10%, transparent);
  padding: 0.75rem;
  font-size: 0.8125rem;
  color: var(--color-destructive);
`

const SecretRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const Secret = styled.code`
  flex: 1;
  overflow-x: auto;
  border-radius: var(--radius-md);
  background-color: var(--color-secondary);
  padding: 0.5rem 0.75rem;
  font-family: var(--font-mono);
  font-size: 0.8125rem;
  white-space: nowrap;
  user-select: all;
`

const CopyStatus = styled.span`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

interface ClientSecretRevealProps {
  clientName: string
  secret: string
}

/** Shows a client secret the server returns once, with a copy button and a warning. */
export function ClientSecretReveal({ clientName, secret }: ClientSecretRevealProps) {
  const [copyState, setCopyState] = useState<CopyState>('idle')

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(secret)
      setCopyState('copied')
    } catch (err: unknown) {
      logger.warn('Could not copy the client secret:', err)
      setCopyState('failed')
    }
  }

  return (
    <Stack>
      <Warning role="alert">
        Copy the secret for {clientName} now. It will not be shown again; if you lose it, rotate the secret.
      </Warning>
      <SecretRow>
        <Secret aria-label="Client secret">{secret}</Secret>
        <Button size="sm" variant="outline" onClick={copy}>
          <Copy size={14} /> Copy
        </Button>
      </SecretRow>
      <CopyStatus aria-live="polite">
        {copyState === 'copied' && 'Copied to the clipboard.'}
        {copyState === 'failed' && 'Could not copy. Select the secret and copy it manually.'}
      </CopyStatus>
    </Stack>
  )
}
