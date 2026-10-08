import { useEffect, useRef } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { useTranslation } from '@/shared/i18n'
import {
  type AuthorizationDecision,
  type AuthorizationLookup,
  type AuthorizationOutcome,
  decideAuthorization,
  lookupAuthorization,
} from '../api/oauth-authorize-api'
import { useRetryCountdown } from '../hooks/use-retry-countdown'
import { leaveAppFor } from '../lib/browser-navigation'
import { checkedClientRedirect } from '../lib/client-redirect'
import { parseApiError } from '../lib/parse-api-error'
import { retryAfterSeconds } from '../lib/retry-after'
import { ScopeList } from './ScopeList'
import { ErrorAlert, Form, FullWidthButton, Hint, Notice } from './auth-form-styles'

const KNOWN_CLIENT_TYPES = new Set(['public', 'confidential'])

const ClientIntro = styled.p`
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-foreground);
`

const ClientType = styled.p`
  margin-top: 0.25rem;
  font-size: 0.75rem;
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
  | { name: 'rejected' }
  | { name: 'unsafeRedirect' }
  | { name: 'lookupFailed'; error: unknown }

function viewForOutcome(outcome: AuthorizationLookup | AuthorizationOutcome, requestedRedirect: string | null): View | null {
  if (outcome.kind === 'rejected') return { name: 'rejected' }
  if (outcome.kind === 'consent') return null

  const target = checkedClientRedirect(outcome.redirectUri, requestedRedirect)
  return target === null ? { name: 'unsafeRedirect' } : { name: 'leaving', target }
}

interface OAuthConsentProps {
  /** The authorization request's query string without the leading `?`, passed on unchanged. */
  query: string
}

/**
 * The consent step of the authorization code flow. The server checks the request; the page
 * shows the client and scopes, sends the user's decision, and sends the browser back to the
 * client. When the client or redirect URI is invalid, the page reports it and never redirects.
 */
export function OAuthConsent({ query }: OAuthConsentProps) {
  const { t } = useTranslation()
  const { secondsLeft, start: startCountdown } = useRetryCountdown()

  const lookup = useQuery({
    queryKey: ['oauth-authorize', query],
    queryFn: async ({ signal }) => {
      try {
        return await lookupAuthorization(query, signal)
      } catch (err: unknown) {
        const wait = retryAfterSeconds(err)
        if (wait !== null) startCountdown(wait)
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
  // The decision must return to the URI the request named or, without one, to the one the
  // server validated for it.
  const requestedRedirect = new URLSearchParams(query).get('redirect_uri') ?? details?.redirectUri ?? null

  let view: View
  if (decision.data !== undefined) {
    view = viewForOutcome(decision.data, requestedRedirect) ?? { name: 'consent' }
  } else if (lookup.isPending) {
    view = { name: 'loading' }
  } else if (lookup.isError) {
    view = { name: 'lookupFailed', error: lookup.error }
  } else {
    view = viewForOutcome(lookup.data, requestedRedirect) ?? { name: 'consent' }
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
          <ErrorAlert role="alert">{t('auth.consent.invalidRequest')}</ErrorAlert>
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
            <ClientIntro>{t('auth.consent.requestIntro', { client: details.clientName })}</ClientIntro>
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
