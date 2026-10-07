import { type FormEvent, useState } from 'react'
import { AxiosError } from 'axios'
import { useNavigate } from 'react-router-dom'
import { usePostAuthPasswordReset } from '@/shared/api-client/gen/endpoints'
import { Input } from '@/shared/components/ui/input'
import { useTranslation } from '@/shared/i18n'
import { useRetryCountdown } from '../hooks/use-retry-countdown'
import { useAuthStore } from '../stores/auth-store'
import { parseApiError } from '../lib/parse-api-error'
import { retryAfterSeconds } from '../lib/retry-after'
import {
  ErrorAlert,
  FieldWrapper,
  FooterText,
  Form,
  FullWidthButton,
  Hint,
  Label,
  TextLink,
} from './auth-form-styles'

/** The server's password policy: 8 to 255 characters. */
export const PASSWORD_MIN_LENGTH = 8
export const PASSWORD_MAX_LENGTH = 255

/** Location state the login page reads to confirm a completed reset. */
export interface PasswordResetDoneState {
  passwordReset: true
}

interface ResetPasswordFormProps {
  token: string
}

export function ResetPasswordForm({ token }: ResetPasswordFormProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const resetPassword = usePostAuthPasswordReset()
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  const clearAuth = useAuthStore((s) => s.clearAuth)
  const countdown = useRetryCountdown()

  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [linkInvalid, setLinkInvalid] = useState(false)

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)

    if (password.length < PASSWORD_MIN_LENGTH || password.length > PASSWORD_MAX_LENGTH) {
      setError(t('auth.passwordReset.passwordLength'))
      return
    }

    if (password !== confirmPassword) {
      setError(t('auth.passwordMismatch'))
      return
    }

    try {
      await resetPassword.mutateAsync({ data: { token, password } })
      // The reset ended every session on the server, including one held in this browser.
      if (isAuthenticated) clearAuth()
      const state: PasswordResetDoneState = { passwordReset: true }
      navigate('/login', { replace: true, state })
    } catch (err: unknown) {
      const wait = retryAfterSeconds(err)
      if (wait !== null) {
        countdown.start(wait)
        return
      }

      // The server answers every unusable token with one generic 400.
      if (err instanceof AxiosError && err.response?.status === 400) {
        setLinkInvalid(true)
        return
      }

      setError(parseApiError(err, t('common.error')).message)
    }
  }

  if (linkInvalid) {
    return <InvalidResetLink />
  }

  return (
    <Form onSubmit={handleSubmit}>
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
        <Label htmlFor="reset-new-password">
          {t('auth.passwordReset.newPassword')}
        </Label>
        <Input
          id="reset-new-password"
          type="password"
          required
          minLength={PASSWORD_MIN_LENGTH}
          maxLength={PASSWORD_MAX_LENGTH}
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          autoComplete="new-password"
          aria-describedby="reset-password-hint"
          autoFocus
        />
        <Hint id="reset-password-hint">
          {t('auth.passwordReset.passwordLength')}
        </Hint>
      </FieldWrapper>

      <FieldWrapper>
        <Label htmlFor="reset-confirm-password">
          {t('auth.passwordReset.confirmPassword')}
        </Label>
        <Input
          id="reset-confirm-password"
          type="password"
          required
          maxLength={PASSWORD_MAX_LENGTH}
          value={confirmPassword}
          onChange={(e) => setConfirmPassword(e.target.value)}
          autoComplete="new-password"
        />
      </FieldWrapper>

      <FullWidthButton type="submit" disabled={resetPassword.isPending || countdown.secondsLeft > 0}>
        {resetPassword.isPending ? t('common.loading') : t('auth.passwordReset.submit')}
      </FullWidthButton>
    </Form>
  )
}

/** Shown for a missing, unknown, used or expired reset link. */
export function InvalidResetLink() {
  const { t } = useTranslation()

  return (
    <Form as="div">
      <ErrorAlert role="alert">
        {t('auth.passwordReset.invalidLink')}
      </ErrorAlert>
      <FooterText>
        <TextLink to="/forgot-password">
          {t('auth.passwordReset.requestNewLink')}
        </TextLink>
      </FooterText>
    </Form>
  )
}
