import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '../../../../../tests/test-utils'
import userEvent from '@testing-library/user-event'
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
        { publicId: 'device-desktop', name: 'Office desktop', lastUsedAt: new Date().toISOString() },
        { publicId: 'device-phone', name: 'Phone', lastUsedAt: null },
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

  it('addresses rename and forget by public identity instead of the browser token', async () => {
    const user = userEvent.setup()
    const publicId = 'devicePublicIdentity1'
    const browserToken = '0198d4d2-7600-7000-8000-000000000001'
    mock.onPut(`/api/devices/${publicId}`).reply(200, { data: { message: 'Device renamed.' } })
    mock.onDelete(`/api/devices/${publicId}`).reply(200, { data: { message: 'Device forgotten.' } })
    renderDevices({ data: [{ publicId, deviceId: browserToken, name: 'Office desktop', lastUsedAt: null }] })

    await user.click(await screen.findByRole('button', { name: 'Rename Office desktop' }))
    await user.clear(screen.getByRole('textbox'))
    await user.type(screen.getByRole('textbox'), 'Living room')
    await user.click(screen.getByRole('button', { name: 'Rename' }))
    await waitFor(() => expect(mock.history.put).toHaveLength(1))
    expect(mock.history.put[0].url).toBe(`/api/devices/${publicId}`)

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    await user.click(screen.getByRole('button', { name: 'Forget Office desktop' }))
    await user.click(screen.getByRole('button', { name: 'Forget' }))
    await waitFor(() => expect(mock.history.delete).toHaveLength(1))
    expect(mock.history.delete[0].url).toBe(`/api/devices/${publicId}`)
  })
  it('retains a dirty name and exposes conflict or deletion from fresh server snapshots', async () => {
    const user = userEvent.setup()
    renderDevices({ data: [{ publicId: 'device', name: 'Office' }] })
    await user.click(await screen.findByRole('button', { name: 'Rename Office' }))
    await user.clear(screen.getByRole('textbox'))
    await user.type(screen.getByRole('textbox'), 'My name')
    client.setQueryData(getGetDeviceListQueryKey(), { data: [{ publicId: 'device', name: 'Remote' }] })
    expect(await screen.findByText('This device changed elsewhere.')).toBeInTheDocument()
    expect(screen.getByRole('textbox')).toHaveValue('My name')
    expect(screen.getByRole('button', { name: 'Rename' })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Keep my name' }))
    expect(screen.getByRole('button', { name: 'Rename' })).toBeEnabled()
    client.setQueryData(getGetDeviceListQueryKey(), { data: [] })
    expect(await screen.findByText(/This device is no longer available/)).toBeInTheDocument()
    expect(screen.getByRole('textbox')).toHaveValue('My name')
    expect(screen.getByRole('button', { name: 'Rename' })).toBeDisabled()
  })

  it('keeps newer input when a pending rename succeeds', async () => {
    const user = userEvent.setup()
    let finish: ((value: [number, unknown]) => void) | undefined
    let savedName = 'Office'
    mock.onGet('/api/devices').reply(() => [200, { data: [{ publicId: 'device', name: savedName }] }])
    mock.onPut('/api/devices/device').reply(() => new Promise(resolve => { finish = resolve }))
    render(<QueryClientProvider client={client}><DeviceManagement /></QueryClientProvider>)
    await user.click(await screen.findByRole('button', { name: 'Rename Office' }))
    await user.clear(screen.getByRole('textbox'))
    await user.type(screen.getByRole('textbox'), 'Submitted')
    await user.click(screen.getByRole('button', { name: 'Rename' }))
    await waitFor(() => expect(finish).toBeDefined())
    await user.clear(screen.getByRole('textbox'))
    await user.type(screen.getByRole('textbox'), 'Newer edit')
    savedName = 'Submitted'
    finish?.([200, { data: { message: 'Device renamed.' } }])
    await waitFor(() => expect(screen.getByRole('button', { name: 'Rename' })).toBeEnabled())
    expect(screen.getByRole('textbox')).toHaveValue('Newer edit')
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

})
