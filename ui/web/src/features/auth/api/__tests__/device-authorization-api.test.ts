import { AxiosError, AxiosHeaders } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { customInstance } from '@/shared/api-client/axios-instance'
import { decideDeviceAuthorization, lookupDeviceAuthorization, userCodeErrorReason } from '../device-authorization-api'

vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: vi.fn() }))

const mockRequest = vi.mocked(customInstance)

const PENDING = {
  userCode: 'BCDF-GHJK',
  clientId: 'tv-app',
  clientName: 'Bånder TV',
  scopes: ['library'],
  expiresAt: '2026-10-07T12:15:00+00:00',
}

function apiError(status: number, details: Record<string, unknown> = {}) {
  return new AxiosError('Request failed', 'ERR_BAD_REQUEST', undefined, undefined, {
    data: { error: { code: status, message: 'Refused.', details } },
    status,
    statusText: 'Error',
    headers: {},
    config: { headers: new AxiosHeaders() },
  })
}

describe('device authorization API', () => {
  beforeEach(() => {
    mockRequest.mockReset()
  })

  it('looks up the pending request by user code', async () => {
    mockRequest.mockResolvedValue({ data: PENDING })
    const signal = new AbortController().signal

    await expect(lookupDeviceAuthorization('BCDFGHJK', signal)).resolves.toEqual(PENDING)
    expect(mockRequest).toHaveBeenCalledWith('/api/oauth/device/verify?user_code=BCDFGHJK', expect.objectContaining({
      method: 'GET',
      signal,
    }))
  })

  it('sends the decision and returns the one the server recorded', async () => {
    mockRequest.mockResolvedValue({ data: { decision: 'denied', message: 'Device denied.' } })

    await expect(decideDeviceAuthorization('BCDF-GHJK', 'deny')).resolves.toBe('denied')
    expect(mockRequest).toHaveBeenCalledWith('/api/oauth/device/approve', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ userCode: 'BCDF-GHJK', decision: 'deny' }),
    }))
  })

  it('propagates request failures', async () => {
    mockRequest.mockRejectedValue(new Error('Unavailable'))

    await expect(decideDeviceAuthorization('BCDF-GHJK', 'approve')).rejects.toThrow('Unavailable')
  })

  it.each(['user_code_required', 'invalid_user_code', 'device_already_processed'])(
    'reads the %s reason from a 400 answer',
    (reason) => {
      expect(userCodeErrorReason(apiError(400, { reason }))).toBe(reason)
    },
  )

  it('ignores unknown reasons, other statuses, and other errors', () => {
    expect(userCodeErrorReason(apiError(400, { reason: 'something_else' }))).toBeNull()
    expect(userCodeErrorReason(apiError(400))).toBeNull()
    expect(userCodeErrorReason(apiError(422, { reason: 'invalid_user_code' }))).toBeNull()
    expect(userCodeErrorReason(new Error('Unavailable'))).toBeNull()
  })
})
