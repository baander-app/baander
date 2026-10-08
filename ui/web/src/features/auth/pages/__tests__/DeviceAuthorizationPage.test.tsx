import { cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { customInstance } from '@/shared/api-client/axios-instance'
import { DeviceAuthorizationPage } from '../DeviceAuthorizationPage'
import { httpError, renderAt } from './auth-page-test-utils'

vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: vi.fn() }))

const mockRequest = vi.mocked(customInstance)

const PENDING = {
  userCode: 'BCDF-GHJK',
  clientId: 'V1StGXR8_Z5jdHi6B-myT',
  clientName: 'Bånder TV',
  scopes: ['library', 'playlist'],
  expiresAt: '2026-10-08T12:15:00+00:00',
}

function mount(entry = '/device') {
  return renderAt(entry, {
    '/device': <DeviceAuthorizationPage />,
    '/': <p>Home</p>,
  })
}

function verifyCalls() {
  return mockRequest.mock.calls.filter(([url]) => url.startsWith('/api/oauth/device/verify'))
}

function approveCalls() {
  return mockRequest.mock.calls.filter(([url]) => url === '/api/oauth/device/approve')
}

/** Answers verify with the pending request and approve with success, unless overridden. */
function serve(overrides: { verify?: () => unknown; approve?: () => unknown } = {}) {
  mockRequest.mockImplementation(async (url: string) => {
    if (url.startsWith('/api/oauth/device/verify')) {
      return overrides.verify ? overrides.verify() : { data: PENDING }
    }
    if (url === '/api/oauth/device/approve') {
      return overrides.approve ? overrides.approve() : { data: { message: 'Done.' } }
    }

    throw new Error(`Unexpected request to ${url}`)
  })
}

describe('DeviceAuthorizationPage', () => {
  beforeEach(() => {
    mockRequest.mockReset()
  })

  afterEach(() => {
    cleanup()
  })

  it('looks up a code from the link once and approves it after a click', async () => {
    serve()
    mount('/device?user_code=bcdf%20ghjk')

    expect(await screen.findByText('Bånder TV wants to use your Bånder account.')).toBeInTheDocument()
    expect(screen.getByText('BCDF-GHJK')).toBeInTheDocument()
    expect(screen.getByText('Browse and play your library')).toBeInTheDocument()
    expect(screen.getByText('See and change your playlists')).toBeInTheDocument()
    expect(verifyCalls()).toHaveLength(1)
    expect(verifyCalls()[0][0]).toBe('/api/oauth/device/verify?user_code=BCDFGHJK')
    expect(approveCalls()).toHaveLength(0)

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Approve' }))

    expect(await screen.findByRole('status')).toHaveTextContent('You can return to your device.')
    expect(approveCalls()).toHaveLength(1)
    expect(approveCalls()[0][1]).toEqual(expect.objectContaining({
      body: JSON.stringify({ userCode: 'BCDF-GHJK', decision: 'approve' }),
    }))
  })

  it('formats a typed code and enables lookup only when it is complete', async () => {
    serve()
    mount()

    const user = userEvent.setup()
    const input = screen.getByLabelText('Device code')
    const submit = screen.getByRole('button', { name: 'Continue' })
    expect(submit).toBeDisabled()

    await user.type(input, 'bcdf ghj')
    expect(input).toHaveValue('BCDF-GHJ')
    expect(submit).toBeDisabled()

    await user.type(input, 'k')
    expect(input).toHaveValue('BCDF-GHJK')
    await user.click(submit)

    expect(await screen.findByRole('button', { name: 'Approve' })).toBeInTheDocument()
    expect(verifyCalls()[0][0]).toBe('/api/oauth/device/verify?user_code=BCDFGHJK')
  })

  it('does not look up an incomplete code from the link', () => {
    serve()
    mount('/device?user_code=BCD')

    expect(screen.getByLabelText('Device code')).toHaveValue('BCD')
    expect(mockRequest).not.toHaveBeenCalled()
  })

  it('reports the denial and lets the user enter another code', async () => {
    serve()
    mount('/device?user_code=BCDF-GHJK')

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Deny' }))

    expect(await screen.findByRole('status')).toHaveTextContent('You denied the request.')
    expect(approveCalls()[0][1]).toEqual(expect.objectContaining({
      body: JSON.stringify({ userCode: 'BCDF-GHJK', decision: 'deny' }),
    }))

    await user.click(screen.getByRole('button', { name: 'Enter another code' }))
    expect(screen.getByLabelText('Device code')).toHaveValue('')
  })

  it('explains an unknown or expired code and keeps the input', async () => {
    serve({ verify: () => { throw httpError(400, 'Invalid or expired user code.') } })
    mount('/device?user_code=BCDF-GHJK')

    expect(await screen.findByRole('alert')).toHaveTextContent('This code is unknown or has expired.')
    expect(screen.getByLabelText('Device code')).toHaveValue('BCDF-GHJK')
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument()
  })

  it('returns to code entry when the request expires before approval', async () => {
    serve({ approve: () => { throw httpError(400, 'Invalid or expired user code.') } })
    mount('/device?user_code=BCDF-GHJK')

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Approve' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('This code is unknown or has expired.')
    expect(screen.getByLabelText('Device code')).toBeInTheDocument()
  })

  it('waits for the Retry-After delay before another lookup', async () => {
    let limited = true
    serve({
      verify: () => {
        if (limited) throw httpError(429, 'Too many requests.', { 'retry-after': '1' })
        return { data: PENDING }
      },
    })
    mount('/device?user_code=BCDF-GHJK')

    expect(await screen.findByRole('alert')).toHaveTextContent('Too many attempts. Try again in 1 second.')
    const submit = await screen.findByRole('button', { name: 'Continue' })
    expect(submit).toBeDisabled()

    await waitFor(() => expect(submit).toBeEnabled(), { timeout: 2500 })
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()

    limited = false
    const user = userEvent.setup()
    await user.click(submit)
    expect(await screen.findByRole('button', { name: 'Approve' })).toBeInTheDocument()
  })

  it('shows other failures and keeps the decision available', async () => {
    let failing = true
    serve({
      approve: () => {
        if (failing) throw httpError(500, 'The server could not record the decision.')
        return { data: { message: 'Done.' } }
      },
    })
    mount('/device?user_code=BCDF-GHJK')

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Approve' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The server could not record the decision.')

    failing = false
    await user.click(screen.getByRole('button', { name: 'Approve' }))
    expect(await screen.findByRole('status')).toHaveTextContent('You can return to your device.')
  })
})
