import Axios, { AxiosError, AxiosHeaders, type AxiosAdapter, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: { getState: () => ({ accessToken: null, refreshToken: null }) },
}))
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: vi.fn(() => null),
  getDpopNonce: () => null,
  setDpopNonce: vi.fn(),
}))
vi.mock('@/shared/crypto/dpop-proof', () => ({ createDpopProof: vi.fn() }))

import { AXIOS_INSTANCE, customInstance } from '@/shared/api-client/axios-instance'
import { bindLiveReadContext } from '@/shared/live/http-admission'
import { getDpopKeyPair } from '@/shared/crypto/dpop-store'
import { createDpopProof } from '@/shared/crypto/dpop-proof'
import { advanceAuthorization, setAuthorityPending } from '@/shared/live/ownership'

function response(config: InternalAxiosRequestConfig): AxiosResponse {
  return {
    config,
    status: 200,
    statusText: 'OK',
    headers: new AxiosHeaders(),
    data: {},
  }
}
function resourceSignal(priority: 'foreground' | 'background' = 'background'): AbortController {
  const controller = new AbortController()
  bindLiveReadContext(controller.signal, { family: 'catalog', priority })
  return controller
}
const flush = async () => {
  for (let step = 0; step < 20; step++) {
    await Promise.resolve()
  }
}

describe('Axios resource admission', () => {
  let previousAdapter: typeof AXIOS_INSTANCE.defaults.adapter
  let clock = 100_000

  beforeEach(() => {
    clock += 10_000
    vi.useFakeTimers({ now: clock })
    previousAdapter = AXIOS_INSTANCE.defaults.adapter
    vi.mocked(getDpopKeyPair).mockReturnValue(null)
    vi.mocked(createDpopProof).mockReset()
    setAuthorityPending(false)
  })
  afterEach(() => {
    AXIOS_INSTANCE.defaults.adapter = previousAdapter
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  it('budgets each retained page and prioritizes an explicit foreground reader at the next window', async () => {
    const order: string[] = []
    AXIOS_INSTANCE.defaults.adapter = async config => {
      order.push(config.url ?? '')
      return response(config)
    }
    const pages = resourceSignal()
    for (let page = 0; page < 20; page++) {
      await customInstance(`/api/catalog?page=${page}`, { method: 'GET', signal: pages.signal })
    }
    const background = customInstance('/api/catalog?page=20', { method: 'GET', signal: pages.signal })
    const foreground = AXIOS_INSTANCE.get('/api/catalog/user', { signal: resourceSignal('foreground').signal })
    await flush()
    expect(order).toHaveLength(20)
    await vi.advanceTimersByTimeAsync(1000)
    await Promise.all([background, foreground])
    expect(order.slice(20)).toEqual(['/api/catalog/user', '/api/catalog?page=20'])
  })

  it('times out stalled XHR responses and admits the next background reader', async () => {
    const sent: StalledRequest[] = []
    class StalledRequest {
      timeout = 0
      status = 200
      statusText = 'OK'
      responseText = '{}'
      onloadend: (() => void) | null = null
      ontimeout: (() => void) | null = null
      onabort: (() => void) | null = null
      private timer: ReturnType<typeof setTimeout> | undefined
      open() {}
      setRequestHeader() {}
      getAllResponseHeaders() { return 'Content-Type: application/json' }
      send() {
        sent.push(this)
        if (sent.length === 5) {
          this.onloadend?.()
        } else if (this.timeout > 0) {
          this.timer = setTimeout(() => this.ontimeout?.(), this.timeout)
        }
      }
      abort() {
        clearTimeout(this.timer)
        this.onabort?.()
      }
    }
    vi.stubGlobal('XMLHttpRequest', StalledRequest)
    AXIOS_INSTANCE.defaults.adapter = 'xhr'
    const controllers = Array.from({ length: 5 }, () => resourceSignal())
    const requests = controllers.map(controller =>
      AXIOS_INSTANCE.get('/api/catalog', { signal: controller.signal }).catch(error => error as unknown),
    )
    try {
      await flush()
      expect(sent).toHaveLength(4)
      await vi.advanceTimersByTimeAsync(29_999)
      expect(sent).toHaveLength(4)
      await vi.advanceTimersByTimeAsync(1)
      expect(sent).toHaveLength(5)
      const results = await Promise.all(requests)
      expect(results.slice(0, 4).every(result => result instanceof AxiosError && result.code === 'ECONNABORTED')).toBe(true)
      expect(results[4]).toMatchObject({ status: 200 })
      expect(sent.every(request => request.timeout === 30_000)).toBe(true)
    } finally {
      controllers.forEach(controller => controller.abort())
      await Promise.all(requests)
    }
  })

  it.each([undefined, 0, -1, 5_000, 60_000])('bounds resource timeout %s while preserving shorter deadlines', async timeout => {
    let admittedTimeout: number | undefined
    AXIOS_INSTANCE.defaults.adapter = async config => {
      admittedTimeout = config.timeout
      return response(config)
    }
    await AXIOS_INSTANCE.get('/api/catalog', { signal: resourceSignal().signal, timeout })
    expect(admittedTimeout).toBe(timeout === 5_000 ? 5_000 : 30_000)
    await AXIOS_INSTANCE.get('/api/live/recovery', { signal: resourceSignal().signal, timeout: 7_000 })
    expect(admittedTimeout).toBe(7_000)
  })

  it('counts nonce retries as fresh HTTP attempts', async () => {
    let attempts = 0
    AXIOS_INSTANCE.defaults.adapter = async config => {
      attempts++
      if (config.url === '/api/catalog/retry' && attempts === 20) {
        const rejected: AxiosResponse = {
          ...response(config), status: 400,
          headers: new AxiosHeaders({ 'dpop-nonce': 'fresh-nonce' }),
          data: { error: 'use_dpop_nonce' },
        }
        throw new AxiosError('Nonce required', 'ERR_BAD_REQUEST', config, undefined, rejected)
      }
      return response(config)
    }
    for (let page = 0; page < 19; page++) {
      await AXIOS_INSTANCE.get('/api/catalog/page', { signal: resourceSignal().signal })
    }
    const retry = AXIOS_INSTANCE.get('/api/catalog/retry', { signal: resourceSignal().signal })
    await flush()
    expect(attempts).toBe(20)
    await vi.advanceTimersByTimeAsync(999)
    expect(attempts).toBe(20)
    await vi.advanceTimersByTimeAsync(1)
    await retry
    expect(attempts).toBe(21)
  })

  it('acquires before DPoP signing and releases aborts and signing failures before dispatch', async () => {
    const keyPair = {} as NonNullable<ReturnType<typeof getDpopKeyPair>>
    vi.mocked(getDpopKeyPair).mockReturnValue(keyPair)
    const signing: Array<(proof: string) => void> = []
    vi.mocked(createDpopProof).mockImplementation(() => new Promise(resolve => signing.push(resolve)))
    const adapter = vi.fn<AxiosAdapter>(async config => response(config))
    AXIOS_INSTANCE.defaults.adapter = adapter
    const controllers = Array.from({ length: 5 }, () => resourceSignal())
    const requests = controllers.map(controller =>
      AXIOS_INSTANCE.get('/api/catalog', { signal: controller.signal }).catch(error => error as unknown),
    )
    await flush()
    expect(signing).toHaveLength(4)
    controllers[0].abort()
    await flush()
    expect(signing).toHaveLength(5)
    signing.forEach(resolve => resolve('signed-proof'))
    const results = await Promise.all(requests)
    expect(Axios.isCancel(results[0])).toBe(true)
    expect(adapter).toHaveBeenCalledTimes(4)

    vi.mocked(createDpopProof).mockRejectedValueOnce(new Error('Signing failed'))
    await expect(AXIOS_INSTANCE.get('/api/catalog', { signal: resourceSignal().signal })).rejects.toThrow('Signing failed')
    vi.mocked(createDpopProof).mockResolvedValue('signed-proof')
    await Promise.all(Array.from({ length: 4 }, () => AXIOS_INSTANCE.get('/api/catalog', { signal: resourceSignal().signal })))
    expect(adapter).toHaveBeenCalledTimes(8)
  })

  it('releases adapter failures even when they have no Axios config metadata', async () => {
    AXIOS_INSTANCE.defaults.adapter = async () => {
      throw new Error('Adapter failed')
    }
    await Promise.all(Array.from({ length: 4 }, () =>
      expect(AXIOS_INSTANCE.get('/api/catalog', { signal: resourceSignal().signal })).rejects.toThrow('Adapter failed'),
    ))
    const adapter = vi.fn<AxiosAdapter>(async config => response(config))
    AXIOS_INSTANCE.defaults.adapter = adapter
    await Promise.all(Array.from({ length: 4 }, () => AXIOS_INSTANCE.get('/api/catalog', { signal: resourceSignal().signal })))
    expect(adapter).toHaveBeenCalledTimes(4)
  })

  it('fences an attempt whose authority changed while waiting for a permit', async () => {
    const held: Array<() => void> = []
    const adapter = vi.fn<AxiosAdapter>(config => new Promise(resolve => held.push(() => resolve(response(config)))))
    AXIOS_INSTANCE.defaults.adapter = adapter
    const requests = Array.from({ length: 5 }, () =>
      AXIOS_INSTANCE.get('/api/catalog', { signal: resourceSignal().signal }).catch(error => error as unknown),
    )
    await flush()
    expect(adapter).toHaveBeenCalledTimes(4)
    advanceAuthorization()
    held.forEach(release => release())
    const results = await Promise.all(requests)
    expect(results.every(Axios.isCancel)).toBe(true)
    expect(adapter).toHaveBeenCalledTimes(4)
  })

  it('does not queue auth, ticket, or recovery controls behind resource attempts', async () => {
    const held: Array<() => void> = []
    const controls: string[] = []
    AXIOS_INSTANCE.defaults.adapter = config => {
      if (config.url === '/api/catalog') return new Promise(resolve => held.push(() => resolve(response(config))))
      controls.push(config.url ?? '')
      return Promise.resolve(response(config))
    }
    const requests = Array.from({ length: 4 }, () => AXIOS_INSTANCE.get('/api/catalog', { signal: resourceSignal().signal }))
    await flush()
    await Promise.all([
      AXIOS_INSTANCE.get('/api/live/recovery', { signal: resourceSignal().signal }),
      AXIOS_INSTANCE.post('/api/ws/tickets', {}, { signal: resourceSignal().signal }),
      AXIOS_INSTANCE.post('/api/auth/login', {}, { signal: resourceSignal().signal }),
    ])
    expect(controls).toEqual(['/api/live/recovery', '/api/ws/tickets', '/api/auth/login'])
    held.forEach(release => release())
    await Promise.all(requests)
  })
})
