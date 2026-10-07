import { useState } from 'react'
import styled from 'styled-components'
import { usePostAuthMeEmailVerification } from '@/shared/api-client/gen/endpoints'
import { Button } from '@/shared/components/ui/button'
import { useTranslation } from '@/shared/i18n'
import { useRetryCountdown } from '../hooks/use-retry-countdown'
import { parseApiError } from '../lib/parse-api-error'
import { retryAfterSeconds } from '../lib/retry-after'
import { ErrorAlert, FullWidthButton, Notice } from './auth-form-styles'

const Stack = styled.div`
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.5rem;
`

const FullWidthStack = styled(Stack)`
  align-items: stretch;
  gap: 1rem;
`

interface ResendVerificationProps {
  /** Smaller outline button for a settings row; the default fills an account page card. */
  compact?: boolean
}

/**
 * Asks the server to email the signed-in user a new verification link. The confirmation is the
 * same whether or not an email was sent, matching the server, which sends nothing for a
 * verified address or a user over the resend allowance.
 */
export function ResendVerification({ compact = false }: ResendVerificationProps) {
  const { t } = useTranslation()
  const resend = usePostAuthMeEmailVerification()
  const countdown = useRetryCountdown()

  const [sent, setSent] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleResend = async () => {
    setError(null)
    setSent(false)

    try {
      await resend.mutateAsync()
      setSent(true)
    } catch (err: unknown) {
      const wait = retryAfterSeconds(err)
      if (wait !== null) {
        countdown.start(wait)
        return
      }

      setError(parseApiError(err, t('common.error')).message)
    }
  }

  const disabled = resend.isPending || countdown.secondsLeft > 0
  const label = resend.isPending ? t('common.loading') : t('auth.emailVerification.resend')
  const Container = compact ? Stack : FullWidthStack

  return (
    <Container>
      {sent && (
        <Notice role="status">
          {t('auth.emailVerification.resendSent')}
        </Notice>
      )}

      {error && (
        <ErrorAlert role="alert">
          {error}
        </ErrorAlert>
      )}

      {countdown.secondsLeft > 0 && (
        <ErrorAlert role="alert">
          {t('auth.emailVerification.rateLimited', { count: countdown.secondsLeft })}
        </ErrorAlert>
      )}

      {compact ? (
        <Button size="xs" variant="outline" onClick={handleResend} disabled={disabled}>
          {label}
        </Button>
      ) : (
        <FullWidthButton onClick={handleResend} disabled={disabled}>
          {label}
        </FullWidthButton>
      )}
    </Container>
  )
}
