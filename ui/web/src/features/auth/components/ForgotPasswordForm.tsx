import { type FormEvent, useState } from 'react'
import { usePostAuthPasswordResetRequest } from '@/shared/api-client/gen/endpoints'
import { Input } from '@/shared/components/ui/input'
import { useTranslation } from '@/shared/i18n'
import { useRetryCountdown } from '../hooks/use-retry-countdown'
import { parseApiError } from '../lib/parse-api-error'
import { retryAfterSeconds } from '../lib/retry-after'
import {
  ErrorAlert,
  FieldWrapper,
  FooterText,
  Form,
  FullWidthButton,
  Intro,
  Label,
  Notice,
  TextLink,
} from './auth-form-styles'

/**
 * Asks for a reset link. The confirmation is the same whether or not an account uses the
 * address, matching the server, which never reveals that either.
 */
export function ForgotPasswordForm() {
  const { t } = useTranslation()
  const requestReset = usePostAuthPasswordResetRequest()
  const countdown = useRetryCountdown()

  const [email, setEmail] = useState('')
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)

    try {
      await requestReset.mutateAsync({ data: { email } })
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

  if (sent) {
    return (
      <Form as="div">
        <Notice role="status">
          {t('auth.passwordReset.requestSent')}
        </Notice>
        <FooterText>
          <TextLink to="/login">
            {t('auth.passwordReset.backToLogin')}
          </TextLink>
        </FooterText>
      </Form>
    )
  }

  return (
    <Form onSubmit={handleSubmit}>
      <Intro>
        {t('auth.passwordReset.requestIntro')}
      </Intro>

      {error && (
        <ErrorAlert role="alert">
          {error}
        </ErrorAlert>
      )}

      {countdown.secondsLeft > 0 && (
        <ErrorAlert role="alert">
          {t('auth.passwordReset.rateLimited', { count: countdown.secondsLeft })}
        </ErrorAlert>
      )}

      <FieldWrapper>
        <Label htmlFor="reset-email">
          {t('auth.email')}
        </Label>
        <Input
          id="reset-email"
          type="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          autoComplete="email"
          autoFocus
        />
      </FieldWrapper>

      <FullWidthButton type="submit" disabled={requestReset.isPending || countdown.secondsLeft > 0}>
        {requestReset.isPending ? t('common.loading') : t('auth.passwordReset.sendLink')}
      </FullWidthButton>

      <FooterText>
        <TextLink to="/login">
          {t('auth.passwordReset.backToLogin')}
        </TextLink>
      </FooterText>
    </Form>
  )
}
