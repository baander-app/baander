import { AxiosError } from 'axios'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type {
  AuthorizationRedirectResource,
  AuthorizationRequestResource,
} from '@/shared/api-client/gen/endpoints'

/*
 * The client pushed its authorization request to the server beforehand; the browser URL names
 * it only by `client_id` and `request_uri`, and those two parameters are all these calls send.
 * Anything else in the URL is dropped, because only the pushed parameters govern the request.
 * The calls use the shared Axios instance (authentication, DPoP, and refresh included), and the
 * answers are read with checks rather than trusted casts.
 */

const AUTHORIZE_URL = '/api/oauth/authorize'

export type AuthorizationDecision = 'approve' | 'deny'

/** What the user is asked to allow. */
export interface AuthorizationRequestDetails {
  clientName: string
  /** `public` or `confidential` when consent is asked; the page names other types generically. */
  clientType: string
  scopes: string[]
  /** The validated redirect URI the decision's answer must return to. */
  redirectUri: string
  /** False for the first-party client and the user's own personal access clients, approved without asking. */
  consentRequired: boolean
}

/**
 * The client or pushed request is invalid: report the error and never redirect. An
 * `invalid_request_uri` error means the pushed request is unknown, expired, already used, or was
 * pushed by another client.
 */
export interface AuthorizationRejected {
  kind: 'rejected'
  error: string
  description: string | null
}

/** Send the browser back to the client, with a code or an OAuth error in the query. */
export interface AuthorizationRedirect {
  kind: 'redirect'
  redirectUri: string
}

export type AuthorizationOutcome = AuthorizationRedirect | AuthorizationRejected

export type AuthorizationLookup =
  | { kind: 'consent'; details: AuthorizationRequestDetails }
  | AuthorizationOutcome

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isStringArray(value: unknown): value is string[] {
  return Array.isArray(value) && value.every((item) => typeof item === 'string')
}

/** The `{ redirect_uri }` of a decision, or of an error the client must receive. */
function redirectFrom(body: unknown): AuthorizationRedirect | null {
  if (!isRecord(body) || typeof body.redirect_uri !== 'string' || body.redirect_uri === '') return null

  return { kind: 'redirect', redirectUri: body.redirect_uri }
}

/** An RFC 6749 error object without a redirect URI: the client or redirect URI is invalid. */
function rejectionFrom(body: unknown): AuthorizationRejected | null {
  if (!isRecord(body) || typeof body.error !== 'string') return null

  return {
    kind: 'rejected',
    error: body.error,
    description: typeof body.error_description === 'string' ? body.error_description : null,
  }
}

function detailsFrom(body: unknown): AuthorizationRequestDetails | null {
  if (!isRecord(body)) return null

  const { client_name, client_type, scopes, redirect_uri, consent_required } = body
  if (typeof client_name !== 'string' || typeof client_type !== 'string') return null
  if (!isStringArray(scopes) || typeof redirect_uri !== 'string' || typeof consent_required !== 'boolean') {
    return null
  }

  return {
    clientName: client_name,
    clientType: client_type,
    scopes,
    redirectUri: redirect_uri,
    consentRequired: consent_required,
  }
}

/**
 * Maps a 400 OAuth error to a redirect (when it carries `redirect_uri`) or a rejection. Anything
 * else (a lost session, a rate limit, a server failure) is rethrown for the page to report.
 */
function outcomeFromError(err: unknown): AuthorizationOutcome {
  if (!(err instanceof AxiosError) || err.response?.status !== 400) throw err

  const outcome = redirectFrom(err.response.data) ?? rejectionFrom(err.response.data)
  if (outcome === null) throw err

  return outcome
}

/** The pushed request's reference in a browser query: its `client_id` and `request_uri`, when present. */
function pushedRequestReference(query: string): Record<string, string> {
  const params = new URLSearchParams(query)

  return Object.fromEntries(
    ['client_id', 'request_uri'].flatMap((name) => {
      const value = params.get(name)
      return value === null ? [] : [[name, value]]
    }),
  )
}

/**
 * Asks the server what the pushed request named in `query` (the consent page's query string)
 * asks for, or where to send the browser instead.
 */
export async function lookupAuthorization(query: string, signal?: AbortSignal): Promise<AuthorizationLookup> {
  let body: unknown
  try {
    const response = await AXIOS_INSTANCE.get<AuthorizationRequestResource>(AUTHORIZE_URL, {
      params: pushedRequestReference(query),
      signal,
    })
    body = response.data
  } catch (err: unknown) {
    return outcomeFromError(err)
  }

  const details = detailsFrom(body)
  if (details === null) throw new Error('The authorization endpoint returned an unexpected answer.')

  return { kind: 'consent', details }
}

/** Records the user's decision on the pushed request named in `query`; the answer names where to send the browser. */
export async function decideAuthorization(query: string, decision: AuthorizationDecision): Promise<AuthorizationOutcome> {
  let body: unknown
  try {
    const response = await AXIOS_INSTANCE.post<AuthorizationRedirectResource>(AUTHORIZE_URL, {
      ...pushedRequestReference(query),
      decision,
    })
    body = response.data
  } catch (err: unknown) {
    return outcomeFromError(err)
  }

  const redirect = redirectFrom(body)
  if (redirect === null) throw new Error('The authorization endpoint returned no redirect URI.')

  return redirect
}
