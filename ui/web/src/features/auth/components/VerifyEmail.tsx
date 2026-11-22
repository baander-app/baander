import { useCallback, useEffect, useRef, useState } from 'react'
import { AxiosError } from 'axios'
import { useLocation } from 'react-router-dom'
import { postAuthEmailVerify } from '@/shared/api-client/gen/endpoints'
import { useTranslation } from '@/shared/i18n'
import { createLogger } from '@/shared/lib/logger'
import { useRetryCountdown } from '../hooks/use-retry-countdown'
import { readCurrentUser } from '../lib/current-user'
import { parseApiError } from '../lib/parse-api-error'
import { retryAfterSeconds } from '../lib/retry-after'
import { returnToState } from '../lib/return-to'
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

const OTHER_ACCOUNT_REASON = 'verification_link_other_account'

type Outcome =
  | 'verifying'
  | 'verified'
  | 'emailChanged'
  | 'signInRequired'
  | 'otherAccount'
  | 'invalid'
  | 'rateLimited'
  | 'failed'

interface VerifyEmailProps {
  /** The token from the link, or null when the link carried none. */
  token: string | null
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** The server refused the link because it was sent for another account than the signed-in one. */
function isOtherAccountLink(err: unknown): boolean {
  if (!(err instanceof AxiosError) || err.response?.status !== 403) return false

  const body: unknown = err.response.data
  return isRecord(body) && isRecord(body.error) && isRecord(body.error.details)
    && body.error.details.reason === OTHER_ACCOUNT_REASON
}

/** Reads the signed-in user's profile again so the store sees the verified address. */
async function refreshSignedInUser(): Promise<void> {
  if (!useAuthStore.getState().isAuthenticated) return

  try {
    await readCurrentUser()
  } catch (err: unknown) {
    logger.warn('Could not reload the profile after verifying the email address:', err)
  }
}

/**
 * Redeems an email verification token once and reports the outcome. Only a signed-in session
 * of the account the link was sent for redeems it. Signed out, the token waits in memory while
 * the user signs in, and sign-in returns here with it. The server answers every unusable token
 * (unknown, expired, used, or for an address the account no longer has) with one generic 400.
 */
export function VerifyEmail({ token }: VerifyEmailProps) {
  const { t } = useTranslation()
  const location = useLocation()
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  const signedInEmail = useAuthStore((s) => s.user?.email ?? null)
  const logout = useAuthStore((s) => s.logout)
  const { secondsLeft, start: startCountdown } = useRetryCountdown()

  const [outcome, setOutcome] = useState<Outcome>(() => {
    if (token === null) return 'invalid'
    return isAuthenticated ? 'verifying' : 'signInRequired'
  })
  const [error, setError] = useState<string | null>(null)
  const [signingOut, setSigningOut] = useState(false)
  // A token is single-use, so StrictMode's replayed effect must not redeem it a second time.
  const requestedToken = useRef<string | null>(null)

  const verify = useCallback(async (value: string) => {
    let emailChanged: boolean
    try {
      // A session that turns out to be over leaves the user here, with the token, to sign in again.
      const response = await postAuthEmailVerify({ token: value }, { keepPageOnSignOut: true })
      emailChanged = response.data?.emailChanged === true
    } catch (err: unknown) {
      const wait = retryAfterSeconds(err)
      if (wait !== null) {
        startCountdown(wait)
        setOutcome('rateLimited')
        return
      }

      if (isOtherAccountLink(err)) {
        setOutcome('otherAccount')
        return
      }

      // A malformed token (422) is as unusable as an unknown one (400).
      if (err instanceof AxiosError && (err.response?.status === 400 || err.response?.status === 422)) {
        setOutcome('invalid')
        return
      }

      // The session ended while the request ran, for example because its refresh was rejected.
      if (!useAuthStore.getState().isAuthenticated) {
        setOutcome('signInRequired')
        return
      }

      setError(parseApiError(err, t('common.error')).message)
      setOutcome('failed')
      return
    }

    setOutcome(emailChanged ? 'emailChanged' : 'verified')
    await refreshSignedInUser()
  }, [startCountdown, t])

  useEffect(() => {
    if (token === null || outcome !== 'verifying' || requestedToken.current === token) return

    requestedToken.current = token
    verify(token).catch((err: unknown) => {
      logger.error('Email verification ended unexpectedly:', err)
    })
  }, [outcome, token, verify])

  const retry = async () => {
    if (token === null) return

    setError(null)
    setOutcome('verifying')
    await verify(token)
  }

  const signOutAndContinue = async () => {
    setSigningOut(true)
    try {
      await logout()
      setOutcome('signInRequired')
    } catch (err: unknown) {
      logger.error('Signing out to use the verification link failed:', err)
    } finally {
      setSigningOut(false)
    }
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
    case 'emailChanged':
      return (
        <Form as="div">
          <Notice role="status">
            {outcome === 'emailChanged'
              ? t('auth.emailVerification.emailChanged')
              : t('auth.emailVerification.verified')}
          </Notice>
          <FooterText>
            <TextLink to="/">
              {t('auth.emailVerification.continue')}
            </TextLink>
          </FooterText>
        </Form>
      )

    case 'signInRequired':
      return (
        <Form as="div">
          <Intro>
            {t('auth.emailVerification.signInRequired')}
          </Intro>
          <FooterText>
            <TextLink
              to="/login"
              state={returnToState({
                pathname: location.pathname,
                search: location.search,
                hash: `#token=${encodeURIComponent(token ?? '')}`,
              })}
            >
              {t('auth.emailVerification.logIn')}
            </TextLink>
          </FooterText>
        </Form>
      )

    case 'otherAccount':
      return (
        <Form as="div">
          <ErrorAlert role="alert">
            {t('auth.emailVerification.otherAccount', { email: signedInEmail ?? '' })}
          </ErrorAlert>
          <FullWidthButton
            onClick={signOutAndContinue}
            disabled={signingOut}
          >
            {t('auth.emailVerification.signOutAndContinue')}
          </FullWidthButton>
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
