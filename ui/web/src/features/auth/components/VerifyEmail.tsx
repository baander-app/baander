import { useCallback, useEffect, useRef, useState } from 'react'
import { AxiosError } from 'axios'
import { getAuthMe, postAuthEmailVerify } from '@/shared/api-client/gen/endpoints'
import { useTranslation } from '@/shared/i18n'
import { createLogger } from '@/shared/lib/logger'
import { useRetryCountdown } from '../hooks/use-retry-countdown'
import { applyCurrentUser } from '../lib/current-user'
import { parseApiError } from '../lib/parse-api-error'
import { retryAfterSeconds } from '../lib/retry-after'
import { useAuthStore } from '../stores/auth-store'
import { ResendVerification } from './ResendVerification'
import {
  ErrorAlert,
  FooterText,
  Form,
  FullWidthButton,
  Intro,
  Notice,
  TextLink,
} from './auth-form-styles'

const logger = createLogger('VerifyEmail')

type Outcome = 'verifying' | 'verified' | 'invalid' | 'rateLimited' | 'failed'

interface VerifyEmailProps {
  /** The token from the link, or null when the link carried none. */
  token: string | null
}

/** Reads the signed-in user's profile again so the store sees the verified address. */
async function refreshSignedInUser(): Promise<void> {
  if (!useAuthStore.getState().isAuthenticated) return

  try {
    const me = await getAuthMe()
    if (me.data) applyCurrentUser(me.data)
  } catch (err: unknown) {
    logger.warn('Could not reload the profile after verifying the email address:', err)
  }
}

/**
 * Redeems an email verification token once and reports the outcome. The server answers every
 * unusable token (unknown, expired, used, or for an address the account no longer has) with
 * one generic 400.
 */
export function VerifyEmail({ token }: VerifyEmailProps) {
  const { t } = useTranslation()
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  const { secondsLeft, start: startCountdown } = useRetryCountdown()

  const [outcome, setOutcome] = useState<Outcome>(token === null ? 'invalid' : 'verifying')
  const [error, setError] = useState<string | null>(null)
  // A token is single-use, so StrictMode's replayed effect must not redeem it a second time.
  const requestedToken = useRef<string | null>(null)

  const verify = useCallback(async (value: string) => {
    try {
      await postAuthEmailVerify({ token: value })
    } catch (err: unknown) {
      const wait = retryAfterSeconds(err)
      if (wait !== null) {
        startCountdown(wait)
        setOutcome('rateLimited')
        return
      }

      // A malformed token (422) is as unusable as an unknown one (400).
      if (err instanceof AxiosError && (err.response?.status === 400 || err.response?.status === 422)) {
        setOutcome('invalid')
        return
      }

      setError(parseApiError(err, t('common.error')).message)
      setOutcome('failed')
      return
    }

    setOutcome('verified')
    await refreshSignedInUser()
  }, [startCountdown, t])

  useEffect(() => {
    if (token === null || requestedToken.current === token) return

    requestedToken.current = token
    verify(token).catch((err: unknown) => {
      logger.error('Email verification ended unexpectedly:', err)
    })
  }, [token, verify])

  const retry = async () => {
    if (token === null) return

    setError(null)
    setOutcome('verifying')
    await verify(token)
  }

  switch (outcome) {
    case 'verifying':
      return (
        <Form as="div">
          <Notice role="status">
            {t('auth.emailVerification.verifying')}
          </Notice>
        </Form>
      )

    case 'verified':
      return (
        <Form as="div">
          <Notice role="status">
            {t('auth.emailVerification.verified')}
          </Notice>
          <FooterText>
            {isAuthenticated ? (
              <TextLink to="/">
                {t('auth.emailVerification.continue')}
              </TextLink>
            ) : (
              <TextLink to="/login">
                {t('auth.emailVerification.logIn')}
              </TextLink>
            )}
          </FooterText>
        </Form>
      )

    case 'invalid':
      return (
        <Form as="div">
          <ErrorAlert role="alert">
            {t('auth.emailVerification.invalidLink')}
          </ErrorAlert>
          {isAuthenticated ? (
            <ResendVerification />
          ) : (
            <>
              <Intro>
                {t('auth.emailVerification.logInToResend')}
              </Intro>
              <FooterText>
                <TextLink to="/login">
                  {t('auth.emailVerification.logIn')}
                </TextLink>
              </FooterText>
            </>
          )}
        </Form>
      )

    case 'rateLimited':
    case 'failed':
      return (
        <Form as="div">
          {outcome === 'failed' && error && (
            <ErrorAlert role="alert">
              {error}
            </ErrorAlert>
          )}
          {outcome === 'rateLimited' && secondsLeft > 0 && (
            <ErrorAlert role="alert">
              {t('auth.emailVerification.rateLimited', { count: secondsLeft })}
            </ErrorAlert>
          )}
          <FullWidthButton onClick={retry} disabled={secondsLeft > 0}>
            {t('auth.emailVerification.tryAgain')}
          </FullWidthButton>
        </Form>
      )
  }
}
