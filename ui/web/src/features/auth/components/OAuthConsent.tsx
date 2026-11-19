import { useEffect, useRef } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { useTranslation } from '@/shared/i18n'
import { textRole } from '@/shared/theme'
import {
  type AuthorizationDecision,
  type AuthorizationLookup,
  type AuthorizationOutcome,
  decideAuthorization,
  lookupAuthorization,
} from '../api/oauth-authorize-api'
import { captureOwnership, isCurrentOwnership } from '@/shared/live/ownership'
import { useRetryCountdown } from '../hooks/use-retry-countdown'
import { leaveAppFor } from '../lib/browser-navigation'
import { checkedClientRedirect } from '../lib/client-redirect'
import { parseApiError } from '../lib/parse-api-error'
import { retryAfterSeconds } from '../lib/retry-after'
import { ClientRequestIntro } from './ClientRequestIntro'
import { ScopeList } from './ScopeList'
import { ErrorAlert, Form, FullWidthButton, Hint, Notice } from './auth-form-styles'

const KNOWN_CLIENT_TYPES = new Set(['public', 'confidential'])

const ClientType = styled.p`
  margin-top: 0.25rem;
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

const Actions = styled.div`
  display: flex;
  gap: 0.75rem;

  & > * {
    flex: 1;
  }
`

/** What the page shows, derived from the lookup and the decision. */
type View =
  | { name: 'loading' }
  | { name: 'consent' }
  | { name: 'leaving'; target: string }
  | { name: 'rejected'; error: string }
  | { name: 'unsafeRedirect' }
  | { name: 'lookupFailed'; error: unknown }

function viewForOutcome(outcome: AuthorizationLookup | AuthorizationOutcome, expectedRedirect: string | null): View | null {
  if (outcome.kind === 'rejected') return { name: 'rejected', error: outcome.error }
  if (outcome.kind === 'consent') return null

  const target = checkedClientRedirect(outcome.redirectUri, expectedRedirect)
  return target === null ? { name: 'unsafeRedirect' } : { name: 'leaving', target }
}

interface OAuthConsentProps {
  /** The page's query string without the leading `?`; only its `client_id` and `request_uri` reach the server. */
  query: string
}

/**
 * The consent step of the authorization code flow, on a request the client pushed to the server
 * beforehand. The server checks the pushed request; the page shows the client and scopes, sends
 * the user's decision, and sends the browser back to the redirect URI the server validated. When
 * the client or pushed request is invalid, the page reports it and never redirects.
 */
export function OAuthConsent({ query }: OAuthConsentProps) {
  const { t } = useTranslation()
  const { secondsLeft, start: startCountdown } = useRetryCountdown()

  const lookup = useQuery({
    queryKey: ['oauth-authorize', query],
    queryFn: async ({ signal }) => {
      const owner = captureOwnership()
      try {
        return await lookupAuthorization(query, signal)
      } catch (err: unknown) {
        const wait = retryAfterSeconds(err)
        if (wait !== null && isCurrentOwnership(owner)) startCountdown(wait)
        throw err
      }
    },
    retry: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    staleTime: Infinity,
    gcTime: 0,
  })

  const decision = useMutation({
    mutationFn: (choice: AuthorizationDecision) => decideAuthorization(query, choice),
    onError: (err: unknown) => {
      const wait = retryAfterSeconds(err)
      if (wait !== null) startCountdown(wait)
    },
  })
  const { mutate: decide } = decision

  const details = lookup.data?.kind === 'consent' ? lookup.data.details : null
  // The decision must return to the redirect URI the lookup reported for the pushed request; a
  // redirect_uri in the browser URL is never trusted.
  const expectedRedirect = details?.redirectUri ?? null

  let view: View
  if (decision.data !== undefined) {
    view = viewForOutcome(decision.data, expectedRedirect) ?? { name: 'consent' }
  } else if (lookup.isPending) {
    view = { name: 'loading' }
  } else if (lookup.isError) {
    view = { name: 'lookupFailed', error: lookup.error }
  } else {
    view = viewForOutcome(lookup.data, expectedRedirect) ?? { name: 'consent' }
  }

  // Leave for each checked target once, also when StrictMode replays the effect.
  const followedTarget = useRef<string | null>(null)
  const target = view.name === 'leaving' ? view.target : null
  useEffect(() => {
    if (target === null || followedTarget.current === target) return

    followedTarget.current = target
    leaveAppFor(target)
  }, [target])

  // The first-party client and the user's own personal access clients need no consent: approve
  // once without asking.
  const autoApproved = useRef(false)
  const approveWithoutConsent = details !== null && !details.consentRequired
  useEffect(() => {
    if (!approveWithoutConsent || autoApproved.current) return

    autoApproved.current = true
    decide('approve')
  }, [approveWithoutConsent, decide])

  const waiting = secondsLeft > 0

  switch (view.name) {
    case 'loading':
      return (
        <Form as="div">
          <Notice role="status">{t('auth.consent.loading')}</Notice>
        </Form>
      )

    case 'leaving':
      return (
        <Form as="div">
          <Notice role="status">{t('auth.consent.redirecting')}</Notice>
        </Form>
      )

    case 'rejected':
      return (
        <Form as="div">
          <ErrorAlert role="alert">
            {view.error === 'invalid_request_uri' ? t('auth.consent.requestUnavailable') : t('auth.consent.invalidRequest')}
          </ErrorAlert>
        </Form>
      )

    case 'unsafeRedirect':
      return (
        <Form as="div">
          <ErrorAlert role="alert">{t('auth.consent.unsafeRedirect')}</ErrorAlert>
        </Form>
      )

    case 'lookupFailed': {
      const rateLimited = retryAfterSeconds(view.error) !== null

      return (
        <Form as="div">
          {(!rateLimited || waiting) && (
            <ErrorAlert role="alert">
              {rateLimited
                ? t('auth.consent.rateLimited', { count: secondsLeft })
                : parseApiError(view.error, t('common.error')).message}
            </ErrorAlert>
          )}
          <FullWidthButton
            variant="ghost"
            onClick={() => lookup.refetch()}
            disabled={waiting || lookup.isFetching}
          >
            {t('auth.consent.tryAgain')}
          </FullWidthButton>
        </Form>
      )
    }

    case 'consent': {
      if (details === null || !details.consentRequired) {
        return (
          <Form as="div">
            <Notice role="status">{t('auth.consent.loading')}</Notice>
          </Form>
        )
      }

      const decisionError = decision.error
      const rateLimited = decisionError !== null && retryAfterSeconds(decisionError) !== null

      return (
        <Form as="div">
          <div>
            <ClientRequestIntro messageKey="auth.consent.requestIntro" clientName={details.clientName} />
            {KNOWN_CLIENT_TYPES.has(details.clientType) && (
              <ClientType>{t(`auth.consent.clientType.${details.clientType}`)}</ClientType>
            )}
          </div>
          <ScopeList scopes={details.scopes} />
          <Hint>{t('auth.consent.trustHint')}</Hint>
          {decisionError !== null && (!rateLimited || waiting) && (
            <ErrorAlert role="alert">
              {rateLimited
                ? t('auth.consent.rateLimited', { count: secondsLeft })
                : parseApiError(decisionError, t('common.error')).message}
            </ErrorAlert>
          )}
          <Actions>
            <Button
              variant="outline"
              onClick={() => decide('deny')}
              disabled={decision.isPending || waiting}
            >
              {t('auth.consent.deny')}
            </Button>
            <Button
              onClick={() => decide('approve')}
              disabled={decision.isPending || waiting}
            >
              {t('auth.consent.allow')}
            </Button>
          </Actions>
        </Form>
      )
    }
  }
}
