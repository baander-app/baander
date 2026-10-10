import { cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { customInstance } from '@/shared/api-client/axios-instance'
import type { SettingDefinitionResource, UserSettingResource } from '@/shared/api-client/gen/endpoints'
import { render } from '../../../../../tests/test-utils'
import { EmailLanguageSetting } from '../EmailLanguageSetting'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {},
  customInstance: vi.fn(),
}))

const mockRequest = vi.mocked(customInstance)

const LANGUAGE_DEFINITION: SettingDefinitionResource = {
  key: 'language',
  type: 'enum',
  scope: 'user',
  label: 'Language',
  description: 'The language of the emails and messages Baander sends you. With no choice, emails use the server default and messages follow your browser.',
  group: 'Account',
  default: null,
  options: [
    { value: 'en', label: 'English' },
    { value: 'da', label: 'Dansk' },
    { value: 'th', label: 'ไทย' },
  ],
  min: null,
  max: null,
  editRole: 'ROLE_USER',
  userVisible: false,
  enforced: false,
  fallbackKey: 'i18n.default_language',
}

const SERVER_DEFAULT = 'da'

/** The user's stored choice on the fake server; null follows the server default. */
let choice: string | null
let saveFailure: AxiosError | null
let loadFailure: AxiosError | null

function languageSetting(): UserSettingResource {
  return {
    key: 'language',
    choice,
    value: choice ?? SERVER_DEFAULT,
    resetValue: SERVER_DEFAULT,
    source: choice === null ? 'server_default' : 'user',
    editable: true,
    definition: LANGUAGE_DEFINITION,
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

function serve() {
  mockRequest.mockImplementation(async (url: string, options: RequestInit) => {
    const method = options.method ?? 'GET'

    if (method === 'GET' && url === '/api/user/settings') {
      if (loadFailure) throw loadFailure

      return { data: [languageSetting()] }
    }
    if (method === 'PUT' && url === '/api/user/settings/language') {
      if (saveFailure) throw saveFailure
      const body: { value: string } = JSON.parse(String(options.body))
      choice = body.value

      return { data: languageSetting() }
    }
    if (method === 'DELETE' && url === '/api/user/settings/language') {
      if (saveFailure) throw saveFailure
      choice = null

      return { data: languageSetting() }
    }

    throw new Error(`Unexpected ${method} ${url}`)
  })
}

function renderSetting() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={client}>
      <EmailLanguageSetting />
    </QueryClientProvider>,
  )
}

async function choose(option: string) {
  const user = userEvent.setup()
  await user.click(screen.getByRole('combobox', { name: 'Language' }))
  await user.click(await screen.findByRole('option', { name: option }))
}

function writeCalls() {
  return mockRequest.mock.calls
    .filter(([, options]) => options.method === 'PUT' || options.method === 'DELETE')
    .map(([url, options]) => ({
      method: options.method,
      url,
      body: options.body === undefined ? undefined : JSON.parse(String(options.body)),
    }))
}

describe('EmailLanguageSetting', () => {
  beforeEach(() => {
    choice = null
    saveFailure = null
    loadFailure = null
    mockRequest.mockReset()
    serve()
    Element.prototype.hasPointerCapture = () => false
    Element.prototype.setPointerCapture = () => {}
    Element.prototype.releasePointerCapture = () => {}
    Element.prototype.scrollIntoView = () => {}
  })

  afterEach(() => {
    cleanup()
  })

  it('selects the server default, named by its language, when the user has no choice', async () => {
    renderSetting()

    const control = await screen.findByRole('combobox', { name: 'Language' })
    expect(control).toHaveTextContent('Server default (Dansk)')
    expect(screen.getByText('The language of the emails and messages Baander sends you. With no choice, emails use the server default and messages follow your browser.')).toBeInTheDocument()
  })

  it('lists the server default first, then each language by its native name', async () => {
    const user = userEvent.setup()
    renderSetting()

    await user.click(await screen.findByRole('combobox', { name: 'Language' }))

    const options = await screen.findAllByRole('option')
    expect(options.map((option) => option.textContent)).toEqual([
      'Server default (Dansk)',
      'English',
      'Dansk',
      'ไทย',
    ])
  })

  it('saves a chosen language and shows it as selected', async () => {
    renderSetting()
    await screen.findByRole('combobox', { name: 'Language' })

    await choose('ไทย')

    await waitFor(() => expect(writeCalls()).toHaveLength(1))
    expect(writeCalls()[0]).toEqual({
      method: 'PUT',
      url: '/api/user/settings/language',
      body: { value: 'th' },
    })
    await waitFor(() => expect(screen.getByRole('combobox', { name: 'Language' })).toHaveTextContent('ไทย'))
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('names the server default while the user has their own choice', async () => {
    choice = 'th'
    renderSetting()

    const control = await screen.findByRole('combobox', { name: 'Language' })
    expect(control).toHaveTextContent('ไทย')

    const user = userEvent.setup()
    await user.click(control)
    expect(await screen.findByRole('option', { name: 'Server default (Dansk)' })).toBeInTheDocument()
  })

  it('resets the choice when the server default is chosen', async () => {
    choice = 'th'
    renderSetting()
    await screen.findByRole('combobox', { name: 'Language' })

    await choose('Server default (Dansk)')

    await waitFor(() => expect(writeCalls()).toHaveLength(1))
    expect(writeCalls()[0]).toEqual({
      method: 'DELETE',
      url: '/api/user/settings/language',
      body: undefined,
    })
    await waitFor(() => expect(screen.getByRole('combobox', { name: 'Language' })).toHaveTextContent(
      'Server default (Dansk)',
    ))
  })

  it('shows a failed save and restores the previous selection', async () => {
    saveFailure = httpError(500, 'The setting could not be saved.')
    renderSetting()
    await screen.findByRole('combobox', { name: 'Language' })

    await choose('ไทย')

    expect(await screen.findByRole('alert')).toHaveTextContent('Could not save: The setting could not be saved.')
    expect(screen.getByRole('combobox', { name: 'Language' })).toHaveTextContent('Server default (Dansk)')
  })

  it('shows the violation the server returns for a rejected language', async () => {
    saveFailure = httpError(422, 'Validation failed.', {
      language: ['The language "th" is not offered.'],
    })
    renderSetting()
    await screen.findByRole('combobox', { name: 'Language' })

    await choose('ไทย')

    expect(await screen.findByRole('alert')).toHaveTextContent('The language "th" is not offered.')
    expect(screen.getByRole('combobox', { name: 'Language' })).toHaveTextContent('Server default (Dansk)')
  })

  it('shows a load failure with a retry instead of an empty control', async () => {
    loadFailure = httpError(500, 'Server error')
    const user = userEvent.setup()
    renderSetting()

    expect(await screen.findByRole('alert')).toHaveTextContent('Unable to load your language.')
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()

    loadFailure = null
    await user.click(screen.getByRole('button', { name: 'Retry' }))

    expect(await screen.findByRole('combobox', { name: 'Language' })).toHaveTextContent('Server default (Dansk)')
  })
})
