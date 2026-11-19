import { cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { leaveAppFor } from '../../lib/browser-navigation'
import { OAuthConsentPage } from '../OAuthConsentPage'
import { renderAt } from './auth-page-test-utils'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../../lib/browser-navigation', () => ({ leaveAppFor: vi.fn() }))

const mockGet = vi.mocked(AXIOS_INSTANCE.get)
const mockPost = vi.mocked(AXIOS_INSTANCE.post)
const mockLeave = vi.mocked(leaveAppFor)

const REDIRECT_URI = 'https://app.baander.app/callback'
const REQUEST_URI = 'urn:ietf:params:oauth:request_uri:6esc_11ACC5bwc014ltc14eY22c'
const QUERY = new URLSearchParams({ client_id: 'tv-app', request_uri: REQUEST_URI }).toString()

function consent(consentRequired = true) {
  return {
    data: {
      client_id: 'tv-app',
      client_name: 'Bånder TV',
      client_type: consentRequired ? 'public' : 'first_party',
      scopes: ['library', 'playlist'],
      redirect_uri: REDIRECT_URI,
      consent_required: consentRequired,
    },
  }
}

/** The body the page sends: the pushed request's reference with the decision. */
function decisionBody(decision: 'approve' | 'deny') {
  return { client_id: 'tv-app', request_uri: REQUEST_URI, decision }
}

/** The parameters the first lookup sent. */
function lookupParams() {
  return mockGet.mock.calls[0][1]?.params
}

function axiosError(status: number, data: unknown, headers: Record<string, string> = {}) {
  return new AxiosError('Request failed', 'ERR_BAD_REQUEST', undefined, undefined, {
    data,
    status,
    statusText: 'Error',
    headers,
    config: { headers: new AxiosHeaders() },
  })
}

function mount(query = QUERY) {
  return renderAt(`/oauth/authorize?${query}`, {
    '/oauth/authorize': <OAuthConsentPage />,
  })
}

describe('OAuthConsentPage', () => {
  beforeEach(() => {
    mockGet.mockReset()
    mockPost.mockReset()
    mockLeave.mockReset()
  })

  afterEach(() => {
    cleanup()
  })

  it('shows the client and scopes, then returns to the client with the code', async () => {
    mockGet.mockResolvedValue(consent())
    const approved = `${REDIRECT_URI}?code=c0de&state=af0ifjsldkj`
    mockPost.mockResolvedValue({ data: { redirect_uri: approved } })
    mount()

    const clientName = await screen.findByText('Bånder TV')
    expect(clientName).toHaveAttribute('lang', 'en')
    expect(clientName.parentElement).toHaveTextContent('Bånder TV wants to use your Bånder account.')
    expect(screen.getByText('App on your device')).toBeInTheDocument()
    expect(screen.getByText('Browse and play your library, and control playback on your devices')).toBeInTheDocument()
    expect(lookupParams()).toEqual({ client_id: 'tv-app', request_uri: REQUEST_URI })
    expect(mockLeave).not.toHaveBeenCalled()

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Allow' }))

    await waitFor(() => expect(mockLeave).toHaveBeenCalledWith(approved))
    expect(mockLeave).toHaveBeenCalledTimes(1)
    expect(mockPost).toHaveBeenCalledWith('/api/oauth/authorize', decisionBody('approve'))
    expect(screen.getByRole('status')).toHaveTextContent('Returning you to the app…')
  })

  it('returns access_denied to the client when the user denies', async () => {
    mockGet.mockResolvedValue(consent())
    const denied = `${REDIRECT_URI}?error=access_denied&state=af0ifjsldkj`
    mockPost.mockResolvedValue({ data: { redirect_uri: denied } })
    mount()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Deny' }))

    await waitFor(() => expect(mockLeave).toHaveBeenCalledWith(denied))
    expect(mockPost).toHaveBeenCalledWith('/api/oauth/authorize', decisionBody('deny'))
  })

  it('reports an invalid client and never navigates', async () => {
    mockGet.mockRejectedValue(axiosError(400, { error: 'invalid_client', error_description: 'Unknown or revoked client.' }))
    mount()

    expect(await screen.findByRole('alert')).toHaveTextContent('This sign-in request is not valid')
    expect(screen.queryByRole('button', { name: 'Allow' })).not.toBeInTheDocument()
    expect(mockPost).not.toHaveBeenCalled()
    expect(mockLeave).not.toHaveBeenCalled()
  })

  it('asks the user to start again from the app when the pushed request is unknown, expired or used', async () => {
    mockGet.mockRejectedValue(axiosError(400, { error: 'invalid_request_uri', error_description: 'The request has expired.' }))
    mount()

    expect(await screen.findByRole('alert')).toHaveTextContent('Start again from the app')
    expect(screen.queryByRole('button', { name: 'Allow' })).not.toBeInTheDocument()
    expect(mockPost).not.toHaveBeenCalled()
    expect(mockLeave).not.toHaveBeenCalled()
  })

  it('asks the user to start again from the app when the request asks for nothing that can be granted', async () => {
    mockGet.mockRejectedValue(axiosError(400, { error: 'invalid_scope', error_description: 'None of the requested scopes can be granted.' }))
    mount()

    expect(await screen.findByRole('alert')).toHaveTextContent('asks for no access Bånder can give. Start again from the app.')
    expect(screen.queryByRole('button', { name: 'Allow' })).not.toBeInTheDocument()
    expect(mockLeave).not.toHaveBeenCalled()
  })

  it('sends only the pushed request reference and returns to the server\'s redirect URI, not the URL\'s', async () => {
    const query = new URLSearchParams({
      client_id: 'tv-app',
      request_uri: REQUEST_URI,
      redirect_uri: 'https://other.baander.app/callback',
      scope: 'admin',
      state: 'forged',
    }).toString()
    mockGet.mockResolvedValue(consent())
    const approved = `${REDIRECT_URI}?code=c0de&state=af0ifjsldkj`
    mockPost.mockResolvedValue({ data: { redirect_uri: approved } })
    mount(query)

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Allow' }))

    await waitFor(() => expect(mockLeave).toHaveBeenCalledWith(approved))
    expect(lookupParams()).toEqual({ client_id: 'tv-app', request_uri: REQUEST_URI })
    expect(mockPost).toHaveBeenCalledWith('/api/oauth/authorize', decisionBody('approve'))
    expect(screen.queryByText(/unexpected return address/)).not.toBeInTheDocument()
  })

  it('follows an error redirect from the lookup', async () => {
    const errorRedirect = `${REDIRECT_URI}?error=invalid_scope&state=af0ifjsldkj`
    mockGet.mockRejectedValue(axiosError(400, {
      error: 'invalid_scope',
      error_description: 'Unknown scope.',
      redirect_uri: errorRedirect,
    }))
    mount()

    await waitFor(() => expect(mockLeave).toHaveBeenCalledWith(errorRedirect))
    expect(mockLeave).toHaveBeenCalledTimes(1)
  })

  it.each([
    ['another host', 'https://evil.baander.app/callback?code=c0de'],
    ['a script URL', 'javascript:alert(document.cookie)'],
  ])('refuses a returned redirect to %s', async (_label, target) => {
    mockGet.mockResolvedValue(consent())
    mockPost.mockResolvedValue({ data: { redirect_uri: target } })
    mount()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Allow' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('unexpected return address')
    expect(mockLeave).not.toHaveBeenCalled()
  })

  it('reports a rejection of the decision without navigating', async () => {
    mockGet.mockResolvedValue(consent())
    mockPost.mockRejectedValue(axiosError(400, { error: 'invalid_client', error_description: 'Unknown or revoked client.' }))
    mount()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Allow' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('This sign-in request is not valid')
    expect(mockLeave).not.toHaveBeenCalled()
  })

  it('approves once without asking when no consent is needed', async () => {
    mockGet.mockResolvedValue(consent(false))
    const approved = `${REDIRECT_URI}?code=c0de&state=af0ifjsldkj`
    mockPost.mockResolvedValue({ data: { redirect_uri: approved } })
    mount()

    await waitFor(() => expect(mockLeave).toHaveBeenCalledWith(approved))
    expect(mockPost).toHaveBeenCalledTimes(1)
    expect(mockPost).toHaveBeenCalledWith('/api/oauth/authorize', decisionBody('approve'))
    expect(screen.queryByRole('button', { name: 'Allow' })).not.toBeInTheDocument()
  })

  it('refuses a decision answer that matches the URL\'s redirect URI but not the server\'s', async () => {
    const forged = 'https://other.baander.app/callback'
    const query = new URLSearchParams({ client_id: 'tv-app', request_uri: REQUEST_URI, redirect_uri: forged }).toString()
    mockGet.mockResolvedValue(consent())
    mockPost.mockResolvedValue({ data: { redirect_uri: `${forged}?code=c0de` } })
    mount(query)

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Allow' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('unexpected return address')
    expect(mockLeave).not.toHaveBeenCalled()
  })

  it('waits for the Retry-After delay before looking the request up again', async () => {
    // StrictMode aborts and repeats the first lookup, so answer by phase rather than by call.
    let limited = true
    mockGet.mockImplementation(async () => {
      if (limited) throw axiosError(429, { error: { code: 429, message: 'Too many requests.' } }, { 'retry-after': '1' })
      return consent()
    })
    mount()

    expect(await screen.findByRole('alert')).toHaveTextContent('Too many attempts. Try again in 1 second.')
    const retry = screen.getByRole('button', { name: 'Try again' })
    expect(retry).toBeDisabled()

    await waitFor(() => expect(retry).toBeEnabled(), { timeout: 2500 })
    limited = false
    const user = userEvent.setup()
    await user.click(retry)

    expect(await screen.findByRole('button', { name: 'Allow' })).toBeInTheDocument()
    expect(mockLeave).not.toHaveBeenCalled()
  })

  it('keeps the choice available after a failed decision', async () => {
    mockGet.mockResolvedValue(consent())
    const approved = `${REDIRECT_URI}?code=c0de&state=af0ifjsldkj`
    mockPost
      .mockRejectedValueOnce(axiosError(500, { error: { code: 500, message: 'The server failed.' } }))
      .mockResolvedValueOnce({ data: { redirect_uri: approved } })
    mount()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Allow' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The server failed.')
    expect(mockLeave).not.toHaveBeenCalled()

    await user.click(screen.getByRole('button', { name: 'Allow' }))
    await waitFor(() => expect(mockLeave).toHaveBeenCalledWith(approved))
  })
})
