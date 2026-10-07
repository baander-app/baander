import { cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { AdminUser, AdminUserSetting } from '../../../api/user-admin-api'
import { render } from '../../../../../../tests/test-utils'
import { EditUserDialog } from '../EditUserDialog'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: vi.fn(), put: vi.fn(), delete: vi.fn(), patch: vi.fn() },
}))

const mockGet = vi.mocked(AXIOS_INSTANCE.get)
const mockPut = vi.mocked(AXIOS_INSTANCE.put)
const mockDelete = vi.mocked(AXIOS_INSTANCE.delete)
const mockPatch = vi.mocked(AXIOS_INSTANCE.patch)
const initialAuthState = useAuthStore.getState()

const ALICE: AdminUser = {
  id: '0198d4d2-7600-7000-8000-0000000000a1',
  email: 'alice@baander.app',
  name: 'Alice',
  roles: ['ROLE_USER'],
  disabled: false,
  createdAt: '2026-10-01T00:00:00Z',
  libraryAccess: [],
}

const SETTINGS_URL = `/api/admin/users/${ALICE.id}/settings`

function language(overrides: Partial<AdminUserSetting> = {}): AdminUserSetting {
  return {
    key: 'language',
    label: 'Email language',
    type: 'enum',
    options: [
      { value: 'en', label: 'English' },
      { value: 'da', label: 'Dansk' },
      { value: 'th', label: 'ไทย' },
    ],
    userEditable: true,
    storedValue: null,
    storedValueValid: true,
    value: 'en',
    resetValue: 'en',
    source: 'server_default',
    ...overrides,
  }
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

function signIn(roles: string[]) {
  useAuthStore.setState({
    user: {
      uuid: '0198d4d2-7600-7000-8000-0000000000aa',
      publicId: 'usr_admin',
      email: 'admin@baander.app',
      name: 'Admin',
      roles,
    },
  })
}

function renderDialog(onOpenChange = vi.fn()) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  render(
    <QueryClientProvider client={client}>
      <EditUserDialog user={ALICE} open onOpenChange={onOpenChange} />
    </QueryClientProvider>,
  )

  return onOpenChange
}

function languageSelect() {
  return screen.findByRole('combobox', { name: 'Email language' })
}

async function chooseLanguage(option: string) {
  const user = userEvent.setup()
  await user.click(await languageSelect())
  await user.click(await screen.findByRole('option', { name: option }))
}

describe('EditUserDialog', () => {
  beforeEach(() => {
    mockGet.mockReset()
    mockPut.mockReset()
    mockDelete.mockReset()
    mockPatch.mockReset()
    mockGet.mockImplementation(async (url: string) => {
      if (url === SETTINGS_URL) return { data: { data: [language()] } }
      throw new Error(`Unexpected GET ${url}`)
    })
    mockPatch.mockResolvedValue({ data: { data: ALICE } })
    signIn(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])
    Element.prototype.hasPointerCapture = () => false
    Element.prototype.setPointerCapture = () => {}
    Element.prototype.releasePointerCapture = () => {}
    Element.prototype.scrollIntoView = () => {}
  })

  afterEach(() => {
    cleanup()
    useAuthStore.setState(initialAuthState, true)
  })

  it('opens with the user’s name and email filled in', async () => {
    renderDialog()

    expect(screen.getByDisplayValue('alice@baander.app')).toBeInTheDocument()
    expect(screen.getByDisplayValue('Alice')).toBeInTheDocument()
    expect(await languageSelect()).toHaveTextContent('Server default (English)')
  })

  it('lets a super admin set the language, then saves the other fields and closes', async () => {
    mockPut.mockResolvedValue({ data: { data: language({ storedValue: 'da', value: 'da', source: 'user' }) } })
    const user = userEvent.setup()
    const onOpenChange = renderDialog()

    await chooseLanguage('Dansk')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
    expect(mockPut).toHaveBeenCalledWith(`${SETTINGS_URL}/language`, { value: 'da' })
    expect(mockPatch).toHaveBeenCalledWith(`/api/admin/users/${ALICE.id}`, { email: ALICE.email, name: ALICE.name })
  })

  it('resets the language when the server default is chosen over a stored choice', async () => {
    mockGet.mockResolvedValue({ data: { data: [language({ storedValue: 'th', value: 'th', source: 'user' })] } })
    mockDelete.mockResolvedValue({ data: { data: language() } })
    const user = userEvent.setup()
    const onOpenChange = renderDialog()

    expect(await languageSelect()).toHaveTextContent('ไทย')
    await chooseLanguage('Server default (English)')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
    expect(mockDelete).toHaveBeenCalledWith(`${SETTINGS_URL}/language`)
    expect(mockPut).not.toHaveBeenCalled()
  })

  it('does not send the language when it was left alone', async () => {
    const user = userEvent.setup()
    const onOpenChange = renderDialog()
    await languageSelect()

    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
    expect(mockPut).not.toHaveBeenCalled()
    expect(mockDelete).not.toHaveBeenCalled()
  })

  it('shows a rejected language next to the field and saves nothing else', async () => {
    mockPut.mockRejectedValue(httpError(422, 'Validation failed.', { language: ['Must be one of: en, da, th.'] }))
    const user = userEvent.setup()
    const onOpenChange = renderDialog()

    await chooseLanguage('Dansk')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByText('Must be one of: en, da, th.')).toBeInTheDocument()
    expect(await languageSelect()).toHaveAttribute('aria-invalid', 'true')
    expect(mockPatch).not.toHaveBeenCalled()
    expect(onOpenChange).not.toHaveBeenCalled()
  })

  it('shows a stored language that is no longer offered as invalid, with the language the user gets', async () => {
    mockGet.mockResolvedValue({ data: { data: [language({ storedValue: 'de', storedValueValid: false, value: 'da', resetValue: 'da' })] } })
    renderDialog()

    expect(await languageSelect()).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByText(/The stored language "de" is no longer offered, so emails go out in Dansk\./)).toBeInTheDocument()
  })

  it('makes the language read-only for an admin who is not a super admin', async () => {
    signIn(['ROLE_USER', 'ROLE_ADMIN'])
    mockGet.mockResolvedValue({ data: { data: [language({ storedValue: 'th', value: 'th', source: 'user' })] } })
    renderDialog()

    const select = await languageSelect()
    expect(select).toBeDisabled()
    expect(select).toHaveTextContent('ไทย')
    expect(screen.getByText(/Only super admins can change it\./)).toBeInTheDocument()
  })

  it('shows an admin who is not a super admin the user without a way to save', async () => {
    signIn(['ROLE_USER', 'ROLE_ADMIN'])
    mockGet.mockResolvedValue({ data: { data: [language({})] } })
    renderDialog()

    await languageSelect()
    expect(screen.getByRole('heading', { name: 'User Details' })).toBeInTheDocument()
    expect(screen.getByDisplayValue('alice@baander.app')).toHaveAttribute('readonly')
    expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument()
  })

  it('offers a retry when the settings cannot be loaded', async () => {
    mockGet.mockRejectedValueOnce(new Error('Unavailable'))
    const user = userEvent.setup()
    renderDialog()

    expect(await screen.findByText('Unable to load the language.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Retry' }))

    expect(await languageSelect()).toHaveTextContent('Server default (English)')
  })
})
