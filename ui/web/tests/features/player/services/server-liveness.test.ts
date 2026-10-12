import { AxiosError } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { get } }))
import { probeServerLiveness } from '@/features/player/services/server-liveness'

describe('probeServerLiveness', () => {
  beforeEach(() => {
    get.mockReset()
  })

  it('asks the liveness endpoint with a timeout and reads every status itself', async () => {
    get.mockResolvedValueOnce({ status: 200, data: { status: 'alive', draining: false } })

    await probeServerLiveness()

    expect(get).toHaveBeenCalledOnce()
    const [path, config] = get.mock.calls[0]
    expect(path).toBe('/live')
    expect(config.timeout).toBeGreaterThan(0)
    expect(config.validateStatus(503)).toBe(true)
  })

  it.each([
    ['a server that is not draining', { status: 'alive', draining: false }],
    ['a server that does not report draining', { status: 'alive' }],
    ['an answer without a body', undefined],
  ])('reports up for a 2xx answer from %s', async (_, data) => {
    get.mockResolvedValueOnce({ status: 200, data })

    await expect(probeServerLiveness()).resolves.toBe('up')
  })

  it('reports down for a 2xx answer from a server that is draining', async () => {
    get.mockResolvedValueOnce({ status: 200, data: { status: 'alive', draining: true } })

    await expect(probeServerLiveness()).resolves.toBe('down')
  })

  it.each([301, 404, 500, 502, 503])('reports down for a %i answer', async (status) => {
    get.mockResolvedValueOnce({ status, data: {} })

    await expect(probeServerLiveness()).resolves.toBe('down')
  })

  it('reports down when the probe times out', async () => {
    get.mockRejectedValueOnce(new AxiosError('timeout of 5000ms exceeded', AxiosError.ECONNABORTED))

    await expect(probeServerLiveness()).resolves.toBe('down')
  })

  it('reports down when the network fails', async () => {
    get.mockRejectedValueOnce(new AxiosError('Network Error', AxiosError.ERR_NETWORK))

    await expect(probeServerLiveness()).resolves.toBe('down')
  })
})
