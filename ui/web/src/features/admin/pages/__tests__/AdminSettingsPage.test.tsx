import { cleanup, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { customInstance } from '@/shared/api-client/axios-instance'
import type {
  SettingDefinitionResource,
  SystemSettingResource,
} from '@/shared/api-client/gen/endpoints'
import { render } from '../../../../../tests/test-utils'
import { AdminSettingsPage } from '../AdminSettingsPage'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {},
  customInstance: vi.fn(),
}))
vi.mock('../ConfigurationPage', () => ({ ConfigurationPage: () => null }))

const mockRequest = vi.mocked(customInstance)
const initialAuthState = useAuthStore.getState()

type SettingValue = boolean | number | string

function definition(
  overrides: Partial<SettingDefinitionResource> & Pick<SettingDefinitionResource, 'key' | 'label' | 'group'>,
): SettingDefinitionResource {
  return {
    type: 'boolean',
    scope: 'system',
    description: `${overrides.label} description`,
    default: false,
    options: [],
    min: null,
    max: null,
    editRole: 'ROLE_SUPER_ADMIN',
    userVisible: false,
    enforced: false,
    fallbackKey: null,
    ...overrides,
  }
}

const DEFINITIONS: SettingDefinitionResource[] = [
  definition({
    key: 'admin.can_view_users',
    label: 'View user list',
    group: 'User Management',
  }),
  definition({
    key: 'transcode.enabled',
    label: 'Enable transcoding',
    group: 'Media',
    default: true,
    enforced: true,
  }),
  definition({
    key: 'transcode.max_bitrate',
    label: 'Max transcode bitrate',
    group: 'Media',
    type: 'enum',
    default: 320,
    options: [
      { value: 128, label: '128 kbps' },
      { value: 192, label: '192 kbps' },
      { value: 320, label: '320 kbps' },
    ],
  }),
  definition({
    key: 'library.scan_workers',
    label: 'Scan workers',
    group: 'Media',
    type: 'integer',
    default: 4,
    min: 1,
    max: 16,
  }),
  definition({
    key: 'i18n.default_language',
    label: 'Default language',
    group: 'Language',
    type: 'enum',
    default: 'en',
    userVisible: true,
    options: [
      { value: 'en', label: 'English' },
      { value: 'da', label: 'Dansk' },
      { value: 'th', label: 'ไทย' },
    ],
  }),
  definition({
    key: 'email.language',
    label: 'Email language',
    group: 'Account',
    scope: 'user',
    type: 'enum',
    default: null,
    editRole: 'ROLE_USER',
    fallbackKey: 'i18n.default_language',
    options: [
      { value: 'en', label: 'English' },
      { value: 'da', label: 'Dansk' },
    ],
  }),
]

const SYSTEM_DEFINITIONS = DEFINITIONS.filter((item) => item.scope === 'system')

/** Stored values on the fake server; a missing key follows its default. */
let stored: Map<string, SettingValue>
let patchFailure: AxiosError | null

function entry(key: string): SystemSettingResource {
  const item = SYSTEM_DEFINITIONS.find((candidate) => candidate.key === key)
  if (!item || item.default === null) throw new Error(`Unknown setting ${key}`)
  const storedValue = stored.get(key)

  return {
    key,
    value: storedValue ?? item.default,
    storedValue: storedValue ?? null,
    isExplicit: storedValue !== undefined,
    storedValueValid: true,
  }
}

function entries(): SystemSettingResource[] {
  return SYSTEM_DEFINITIONS.map((item) => entry(item.key))
}

function httpError(status: number, message: string, details?: Record<string, string[]>) {
  const config = { headers: new AxiosHeaders() }

  return new AxiosError(`Request failed with status code ${status}`, 'ERR_BAD_REQUEST', config, undefined, {
    data: { error: { code: status, message, details } },
    status,
    statusText: 'Error',
    headers: {},
    config,
  })
}

function serve() {
  mockRequest.mockImplementation(async (url: string, options: RequestInit) => {
    const method = options.method ?? 'GET'

    if (method === 'GET' && url === '/api/admin/settings') {
      return { data: entries() }
    }
    if (method === 'GET' && url === '/api/admin/settings/definitions') {
      return { data: DEFINITIONS }
    }
    if (method === 'PATCH' && url === '/api/admin/settings') {
      if (patchFailure) throw patchFailure
      const body: { settings: Record<string, SettingValue> } = JSON.parse(String(options.body))
      for (const [key, value] of Object.entries(body.settings)) {
        stored.set(key, value)
      }

      return { data: entries() }
    }
    if (method === 'DELETE' && url.startsWith('/api/admin/settings/')) {
      const key = url.slice('/api/admin/settings/'.length)
      stored.delete(key)

      return { data: entry(key) }
    }

    throw new Error(`Unexpected ${method} ${url}`)
  })
}

function signIn(roles: string[]) {
  useAuthStore.setState({
    user: {
      uuid: '0198d4d2-7600-7000-8000-0000000000aa',
      publicId: 'usr_settings_admin',
      email: 'settings-admin@baander.app',
      name: 'Settings admin',
      roles,
    },
  })
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <AdminSettingsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** The row that holds one setting, named by its label. */
function row(label: string) {
  return screen.getByRole('group', { name: label })
}

async function choose(label: string, option: string) {
  const user = userEvent.setup()
  await user.click(within(row(label)).getByRole('combobox'))
  await user.click(await screen.findByRole('option', { name: option }))
}

function patchCalls() {
  return mockRequest.mock.calls
    .filter(([, options]) => options.method === 'PATCH')
    .map(([url, options]) => ({ url, body: JSON.parse(String(options.body)) }))
}

describe('AdminSettingsPage', () => {
  beforeEach(() => {
    stored = new Map()
    patchFailure = null
    mockRequest.mockReset()
    serve()
    signIn(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])
    Element.prototype.hasPointerCapture = () => false
    Element.prototype.setPointerCapture = () => {}
    Element.prototype.releasePointerCapture = () => {}
    Element.prototype.scrollIntoView = () => {}
  })

  afterEach(() => {
    cleanup()
    useAuthStore.setState(initialAuthState, true)
  })

  it('renders one control per served system definition, grouped and labelled by the definitions', async () => {
    renderPage()

    await screen.findByRole('group', { name: 'View user list' })
    expect(screen.getAllByRole('group')).toHaveLength(SYSTEM_DEFINITIONS.length)
    expect(screen.queryByText('Email language')).not.toBeInTheDocument()

    expect(screen.getByRole('heading', { name: 'User Management' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Media' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Language' })).toBeInTheDocument()

    expect(within(row('View user list')).getByRole('switch', { name: 'View user list' })).not.toBeChecked()
    expect(within(row('Enable transcoding')).getByRole('switch', { name: 'Enable transcoding' })).toBeChecked()
    expect(within(row('Max transcode bitrate')).getByRole('combobox')).toHaveTextContent('320 kbps')
    expect(within(row('Scan workers')).getByRole('spinbutton', { name: 'Scan workers' })).toHaveValue(4)
    expect(within(row('Default language')).getByRole('combobox')).toHaveTextContent('English')
    expect(within(row('Default language')).getByText('Default language description')).toBeInTheDocument()
  })

  it('marks only definitions the backend does not enforce yet', async () => {
    renderPage()

    await screen.findByRole('group', { name: 'View user list' })
    expect(within(row('View user list')).getByText('Not yet enforced')).toBeInTheDocument()
    expect(within(row('Enable transcoding')).queryByText('Not yet enforced')).not.toBeInTheDocument()
  })

  it('saves a chosen enum value under its key, keeping integer option values numeric', async () => {
    renderPage()
    await screen.findByRole('group', { name: 'Default language' })

    await choose('Default language', 'Dansk')
    await waitFor(() => expect(patchCalls()).toHaveLength(1))
    expect(patchCalls()[0]).toEqual({
      url: '/api/admin/settings',
      body: { settings: { 'i18n.default_language': 'da' } },
    })
    await waitFor(() => expect(within(row('Default language')).getByRole('combobox')).toHaveTextContent('Dansk'))

    await choose('Max transcode bitrate', '192 kbps')
    await waitFor(() => expect(patchCalls()).toHaveLength(2))
    expect(patchCalls()[1].body).toEqual({ settings: { 'transcode.max_bitrate': 192 } })
    await waitFor(() => expect(within(row('Max transcode bitrate')).getByRole('combobox')).toHaveTextContent('192 kbps'))
  })

  it('saves a toggle and an integer under their keys', async () => {
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole('group', { name: 'View user list' })

    await user.click(within(row('View user list')).getByRole('switch'))
    await waitFor(() => expect(patchCalls()).toHaveLength(1))
    expect(patchCalls()[0].body).toEqual({ settings: { 'admin.can_view_users': true } })
    await waitFor(() => expect(within(row('View user list')).getByRole('switch')).toBeChecked())

    const workers = within(row('Scan workers')).getByRole('spinbutton')
    await user.clear(workers)
    await user.type(workers, '8{Enter}')
    await waitFor(() => expect(patchCalls()).toHaveLength(2))
    expect(patchCalls()[1].body).toEqual({ settings: { 'library.scan_workers': 8 } })
  })

  it('shows a rejected value with its violation next to that field', async () => {
    patchFailure = httpError(422, 'Validation failed.', {
      'i18n.default_language': ['The language "th" is not offered.'],
    })
    renderPage()
    await screen.findByRole('group', { name: 'Default language' })

    await choose('Default language', 'ไทย')

    const language = row('Default language')
    expect(await within(language).findByText('The language "th" is not offered.')).toBeInTheDocument()
    expect(within(language).getByRole('combobox')).toHaveTextContent('ไทย')
    expect(within(row('Max transcode bitrate')).queryByText('The language "th" is not offered.')).not.toBeInTheDocument()
  })

  it('shows a save failure that is not a validation error', async () => {
    patchFailure = httpError(403, 'Only super admins can change system settings.')
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole('group', { name: 'View user list' })

    await user.click(within(row('View user list')).getByRole('switch'))

    expect(await within(row('View user list')).findByRole('alert')).toHaveTextContent(
      'Could not save: Only super admins can change system settings.',
    )
  })

  it('resets an explicit setting so it shows its default again', async () => {
    stored.set('i18n.default_language', 'da')
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole('group', { name: 'Default language' })
    expect(within(row('Default language')).getByRole('combobox')).toHaveTextContent('Dansk')
    expect(within(row('Max transcode bitrate')).getByRole('button', { name: /reset/i })).toBeDisabled()

    await user.click(within(row('Default language')).getByRole('button', { name: /reset/i }))

    await waitFor(() => expect(within(row('Default language')).getByRole('combobox')).toHaveTextContent('English'))
    expect(mockRequest).toHaveBeenCalledWith(
      '/api/admin/settings/i18n.default_language',
      expect.objectContaining({ method: 'DELETE' }),
    )
  })

  it('shows read-only controls to an admin who is not a super admin', async () => {
    signIn(['ROLE_ADMIN'])
    renderPage()
    await screen.findByRole('group', { name: 'View user list' })

    expect(within(row('View user list')).getByRole('switch')).toBeDisabled()
    expect(within(row('Default language')).getByRole('combobox')).toBeDisabled()
    expect(within(row('Scan workers')).getByRole('spinbutton')).toBeDisabled()
    expect(screen.queryByRole('button', { name: /reset/i })).not.toBeInTheDocument()
  })
})
