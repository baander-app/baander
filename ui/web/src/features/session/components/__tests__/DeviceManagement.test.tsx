import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import MockAdapter from 'axios-mock-adapter'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { getGetDeviceListQueryKey, type GetDeviceList200 } from '@/shared/api-client/gen/endpoints'
import { DeviceManagement } from '../DeviceManagement'

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: {
    getState: () => ({ accessToken: null, refreshToken: null }),
  },
}))

vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null,
  getDpopNonce: () => null,
  setDpopNonce: vi.fn(),
}))

describe('DeviceManagement', () => {
  let mock: MockAdapter
  let client: QueryClient

  beforeEach(() => {
    mock = new MockAdapter(AXIOS_INSTANCE, { onNoMatch: 'throwException' })
    client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  })

  afterEach(() => {
    cleanup()
    client.clear()
    mock.restore()
  })

  function renderDevices(body: GetDeviceList200) {
    mock.onGet('/api/devices').reply(200, body)
    render(
      <QueryClientProvider client={client}>
        <DeviceManagement />
      </QueryClientProvider>,
    )
  }

  it('renders device rows through the generated hook and body-only adapter', async () => {
    const body: GetDeviceList200 = {
      data: [
        { id: 'device-desktop', name: 'Office desktop', lastUsedAt: new Date().toISOString() },
        { id: 'device-phone', name: 'Phone', lastUsedAt: null },
      ],
    }
    renderDevices(body)

    expect(await screen.findByText('Office desktop')).toBeInTheDocument()
    expect(screen.getByText('Phone')).toBeInTheDocument()
    expect(screen.getByText('Last used today')).toBeInTheDocument()
    expect(screen.queryByText('No devices registered')).not.toBeInTheDocument()
    expect(client.getQueryData(getGetDeviceListQueryKey())).toEqual(body)
    expect(mock.history.get).toHaveLength(1)
  })

  it.each([{ data: [] }, {}] satisfies GetDeviceList200[])(
    'renders the empty state when the device response is %j',
    async (body) => {
      renderDevices(body)

      expect(await screen.findByText('No devices registered')).toBeInTheDocument()
      expect(client.getQueryData(getGetDeviceListQueryKey())).toEqual(body)
      expect(mock.history.get).toHaveLength(1)
    },
  )
})
