import { beforeEach, describe, expect, it, vi } from 'vitest'
import { customInstance } from '@/shared/api-client/axios-instance'
import { decideDeviceAuthorization, lookupDeviceAuthorization } from '../device-authorization-api'

vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: vi.fn() }))

const mockRequest = vi.mocked(customInstance)

describe('device authorization API', () => {
  beforeEach(() => {
    mockRequest.mockReset()
  })

  it('looks up the pending request by user code', async () => {
    mockRequest.mockResolvedValue({
      data: {
        userCode: 'BCDF-GHJK',
        clientId: 'V1StGXR8_Z5jdHi6B-myT',
        clientName: 'Bånder TV',
        scopes: ['library'],
        expiresAt: '2026-10-08T12:15:00+00:00',
      },
    })
    const signal = new AbortController().signal

    await expect(lookupDeviceAuthorization('BCDFGHJK', signal)).resolves.toEqual({
      userCode: 'BCDF-GHJK',
      clientName: 'Bånder TV',
      scopes: ['library'],
    })
    expect(mockRequest).toHaveBeenCalledWith('/api/oauth/device/verify?user_code=BCDFGHJK', expect.objectContaining({
      method: 'GET',
      signal,
    }))
  })

  it('sends the decision with the user code', async () => {
    mockRequest.mockResolvedValue({ data: { decision: 'denied', message: 'Device denied.' } })

    await decideDeviceAuthorization('BCDF-GHJK', 'deny')

    expect(mockRequest).toHaveBeenCalledWith('/api/oauth/device/approve', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ userCode: 'BCDF-GHJK', decision: 'deny' }),
    }))
  })

  it('propagates request failures', async () => {
    mockRequest.mockRejectedValue(new Error('Unavailable'))

    await expect(decideDeviceAuthorization('BCDF-GHJK', 'approve')).rejects.toThrow('Unavailable')
  })
})
