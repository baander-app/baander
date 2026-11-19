import { advanceAuthorization } from '@/shared/live/ownership'
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider } from 'styled-components'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { customInstance } from '@/shared/api-client/axios-instance'
import type { AdminOAuthClientResource } from '@/shared/api-client/gen/endpoints'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { OAuthClientsPage } from '../OAuthClientsPage'

vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: vi.fn() }))

const authState = vi.hoisted(() => ({ roles: ['ROLE_ADMIN'] as string[] }))

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: vi.fn((selector: (state: { user: { roles: string[] } }) => unknown) =>
    selector({ user: { roles: authState.roles } })),
}))

const mockRequest = vi.mocked(customInstance)

type Answer = (url: string, options: RequestInit) => unknown

/** Answers the list from `clients` and each POST from `post`, which a test sets. */
let post: Answer
function postCalls() {
  return mockRequest.mock.calls.filter(([, options]) => options.method === 'POST')
}

const SPA: AdminOAuthClientResource = {
  clientId: '01920000-0000-7000-8000-000000000001',
  name: 'Bånder Web',
  type: 'first_party',
  authMethod: 'none',
  jwksUri: null,
  redirectUris: ['https://baander.app/'],
  revoked: false,
  createdAt: '2026-10-01T09:00:00+00:00',
  updatedAt: '2026-10-01T09:00:00+00:00',
}

const SYNC: AdminOAuthClientResource = {
  clientId: '01920000-0000-7000-8000-000000000002',
  name: 'Bånder Sync',
  type: 'confidential',
  authMethod: 'client_secret_post',
  jwksUri: null,
  redirectUris: ['https://sync.baander.app/callback'],
  revoked: false,
  createdAt: '2026-10-02T09:00:00+00:00',
  updatedAt: '2026-10-02T09:00:00+00:00',
}

const TV: AdminOAuthClientResource = {
  clientId: '01920000-0000-7000-8000-000000000003',
  name: 'Bånder TV',
  type: 'device',
  authMethod: 'none',
  jwksUri: null,
  redirectUris: [],
  revoked: false,
  createdAt: '2026-10-03T09:00:00+00:00',
  updatedAt: '2026-10-03T09:00:00+00:00',
}

const OLD: AdminOAuthClientResource = {
  clientId: '01920000-0000-7000-8000-000000000004',
  name: 'Old Sync',
  type: 'confidential',
  authMethod: 'client_secret_post',
  jwksUri: null,
  redirectUris: ['https://old.baander.app/callback'],
  revoked: true,
  createdAt: '2026-09-01T09:00:00+00:00',
  updatedAt: '2026-09-01T09:00:00+00:00',
}

const SIGNER: AdminOAuthClientResource = {
  clientId: '01920000-0000-7000-8000-000000000006',
  name: 'Bånder Signer',
  type: 'confidential',
  authMethod: 'private_key_jwt',
  jwksUri: 'https://signer.baander.app/jwks.json',
  redirectUris: ['https://signer.baander.app/callback'],
  revoked: false,
  createdAt: '2026-10-04T09:00:00+00:00',
  updatedAt: '2026-10-04T09:00:00+00:00',
}

const OLD_SIGNER: AdminOAuthClientResource = {
  ...SIGNER,
  clientId: '01920000-0000-7000-8000-000000000007',
  name: 'Old Signer',
  revoked: true,
}

const SIGNER_JWKS = {
  keys: [{ kty: 'EC', crv: 'P-256', kid: 'signer-2', x: 'WKn-ZIGevcwGIyyrzFoZNBdaq9_TsqzGl96oc0CWuis', y: 'y77t-RvAHRKTsSGdIYUfweuOvwrvDD-Q3Hv5J0fSKbE' }],
}

let clients: AdminOAuthClientResource[]
let queryClient: QueryClient

function mount() {
  queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <ThemeProvider theme={resolveTheme('dark', 'violet')}>
        <OAuthClientsPage />
      </ThemeProvider>
    </QueryClientProvider>,
  )
}

function row(name: string) {
  return screen.getByRole('row', { name })
}

describe('OAuthClientsPage', () => {
  beforeEach(() => {
    authState.roles = ['ROLE_ADMIN']
    clients = [SPA, SYNC, TV, OLD, SIGNER, OLD_SIGNER]
    mockRequest.mockReset()
    post = () => { throw new Error('Unexpected POST') }
    mockRequest.mockImplementation(async (url: string, options: RequestInit) => {
      if (options.method === 'POST') return post(url, options)
      if (url === '/api/admin/oauth/clients') return { data: clients }

      throw new Error(`Unexpected request to ${url}`)
    })
    Element.prototype.hasPointerCapture = () => false
    Element.prototype.setPointerCapture = () => {}
    Element.prototype.releasePointerCapture = () => {}
    Element.prototype.scrollIntoView = () => {}
  })

  afterEach(() => {
    cleanup()
    queryClient?.clear()
  })

  it('lists clients read-only for an admin', async () => {
    mount()

    expect(await screen.findByText('Bånder Sync')).toBeInTheDocument()
    expect(within(row('Bånder Web')).getByText('First party')).toBeInTheDocument()
    expect(within(row('Bånder TV')).getByText('Device')).toBeInTheDocument()
    expect(within(row('Old Sync')).getByText('Revoked')).toBeInTheDocument()
    expect(within(row('Bånder Sync')).getByText('Active')).toBeInTheDocument()
    expect(screen.getByText(/Only super admins can create, rotate, or revoke clients/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Create client/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Rotate secret' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Revoke' })).not.toBeInTheDocument()
  })

  it('offers actions to a super admin, except on the first-party and revoked clients', async () => {
    authState.roles = ['ROLE_SUPER_ADMIN']
    mount()

    await screen.findByText('Bånder Sync')
    expect(within(row('Bånder Sync')).getByRole('button', { name: 'Rotate secret' })).toBeInTheDocument()
    expect(within(row('Bånder Sync')).getByRole('button', { name: 'Revoke' })).toBeInTheDocument()
    expect(within(row('Bånder TV')).queryByRole('button', { name: 'Rotate secret' })).not.toBeInTheDocument()
    expect(within(row('Bånder TV')).getByRole('button', { name: 'Revoke' })).toBeInTheDocument()
    expect(within(row('Bånder Web')).queryByRole('button')).not.toBeInTheDocument()
    expect(within(row('Old Sync')).queryByRole('button')).not.toBeInTheDocument()
  })

  it('offers key replacement only for active key clients and rotation only for secret clients', async () => {
    authState.roles = ['ROLE_SUPER_ADMIN']
    mount()

    await screen.findByText('Bånder Signer')
    expect(within(row('Bånder Signer')).getByText('Signing keys')).toBeInTheDocument()
    expect(within(row('Bånder Sync')).getByText('Client secret')).toBeInTheDocument()
    expect(within(row('Bånder Signer')).getByRole('button', { name: 'Replace keys' })).toBeInTheDocument()
    expect(within(row('Bånder Signer')).queryByRole('button', { name: 'Rotate secret' })).not.toBeInTheDocument()
    expect(within(row('Bånder Sync')).queryByRole('button', { name: 'Replace keys' })).not.toBeInTheDocument()
    expect(within(row('Bånder TV')).queryByRole('button', { name: 'Replace keys' })).not.toBeInTheDocument()
    expect(within(row('Old Signer')).queryByRole('button')).not.toBeInTheDocument()
  })

  it('registers a key client with a jwks_uri and lists its method', async () => {
    authState.roles = ['ROLE_SUPER_ADMIN']
    const created: AdminOAuthClientResource = {
      ...SIGNER,
      clientId: '01920000-0000-7000-8000-000000000008',
      name: 'Bånder Relay',
      jwksUri: 'https://relay.baander.app/jwks.json',
      redirectUris: ['https://relay.baander.app/callback'],
    }
    post = () => {
      clients = [...clients, created]
      return { data: { ...created, clientSecret: null } }
    }
    mount()
    await screen.findByText('Bånder Sync')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /Create client/ }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Name'), 'Bånder Relay')
    await user.click(within(dialog).getByRole('combobox', { name: 'Type' }))
    await user.click(await screen.findByRole('option', { name: 'Confidential' }))
    await user.click(within(dialog).getByLabelText('Redirect URIs'))
    await user.paste('https://relay.baander.app/callback')
    await user.click(within(dialog).getByRole('combobox', { name: 'Authentication' }))
    await user.click(await screen.findByRole('option', { name: 'Signing keys' }))
    await user.click(within(dialog).getByLabelText('Key set URL'))
    await user.paste('https://relay.baander.app/jwks.json')
    await user.click(within(dialog).getByRole('button', { name: 'Create' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(JSON.parse(String(postCalls()[0][1].body))).toEqual({
      name: 'Bånder Relay',
      type: 'confidential',
      redirectUris: ['https://relay.baander.app/callback'],
      authMethod: 'private_key_jwt',
      jwksUri: 'https://relay.baander.app/jwks.json',
    })
    expect(within(await screen.findByRole('row', { name: 'Bånder Relay' })).getByText('Signing keys')).toBeInTheDocument()
  })

  it('checks a key client\'s key source before sending it', async () => {
    authState.roles = ['ROLE_SUPER_ADMIN']
    mount()
    await screen.findByText('Bånder Sync')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /Create client/ }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Name'), 'Bånder Relay')
    await user.click(within(dialog).getByRole('combobox', { name: 'Type' }))
    await user.click(await screen.findByRole('option', { name: 'Confidential' }))
    await user.click(within(dialog).getByLabelText('Redirect URIs'))
    await user.paste('https://relay.baander.app/callback')
    await user.click(within(dialog).getByRole('combobox', { name: 'Authentication' }))
    await user.click(await screen.findByRole('option', { name: 'Signing keys' }))
    await user.click(within(dialog).getByLabelText('Key set URL'))
    await user.paste('http://relay.baander.app/jwks.json')
    await user.click(within(dialog).getByRole('button', { name: 'Create' }))

    expect(within(dialog).getByRole('alert')).toHaveTextContent('Use an https URL on the default port')
    expect(postCalls()).toHaveLength(0)
  })

  it('replaces a key client\'s keys with an inline key set', async () => {
    authState.roles = ['ROLE_SUPER_ADMIN']
    post = () => ({ data: { ...SIGNER, jwksUri: null } })
    mount()
    await screen.findByText('Bånder Signer')

    const user = userEvent.setup()
    await user.click(within(row('Bånder Signer')).getByRole('button', { name: 'Replace keys' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/Assertions signed with the current keys stop working at once/)).toBeInTheDocument()
    await user.click(within(dialog).getByRole('combobox', { name: 'Key source' }))
    await user.click(await screen.findByRole('option', { name: 'Inline key set' }))

    const keySet = within(dialog).getByLabelText('Key set (JWKS)')
    await user.type(keySet, 'not json')
    await user.click(within(dialog).getByRole('button', { name: 'Replace keys' }))
    expect(within(dialog).getByRole('alert')).toHaveTextContent('The key set must be a JSON object with a "keys" list.')
    expect(postCalls()).toHaveLength(0)

    await user.clear(keySet)
    await user.click(keySet)
    await user.paste(JSON.stringify(SIGNER_JWKS))
    await user.click(within(dialog).getByRole('button', { name: 'Replace keys' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(postCalls().map(([url]) => url)).toEqual([`/api/admin/oauth/clients/${SIGNER.clientId}/keys`])
    expect(JSON.parse(String(postCalls()[0][1].body))).toEqual({ jwks: SIGNER_JWKS })
  })

  it('creates a confidential client and shows its secret once', async () => {
    authState.roles = ['ROLE_SUPER_ADMIN']
    const created: AdminOAuthClientResource = { ...SYNC, clientId: '01920000-0000-7000-8000-000000000005', name: 'Bånder Scrobbler' }
    post = () => {
      clients = [...clients, created]
      return { data: { ...created, clientSecret: 'cs_0nce_only' } }
    }
    mount()
    await screen.findByText('Bånder Sync')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /Create client/ }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Name'), 'Bånder Scrobbler')
    await user.click(within(dialog).getByRole('combobox', { name: 'Type' }))
    await user.click(await screen.findByRole('option', { name: 'Confidential' }))
    await user.type(
      within(dialog).getByLabelText('Redirect URIs'),
      'https://scrobbler.baander.app/callback{enter}{enter}http://127.0.0.1/cb',
    )
    await user.click(within(dialog).getByRole('button', { name: 'Create' }))

    expect(await screen.findByLabelText('Client secret')).toHaveTextContent('cs_0nce_only')
    expect(screen.getByRole('alert')).toHaveTextContent('It will not be shown again')
    expect(postCalls()).toHaveLength(1)
    expect(postCalls()[0][0]).toBe('/api/admin/oauth/clients')
    expect(JSON.parse(String(postCalls()[0][1].body))).toEqual({
      name: 'Bånder Scrobbler',
      type: 'confidential',
      redirectUris: ['https://scrobbler.baander.app/callback', 'http://127.0.0.1/cb'],
    })

    await user.click(screen.getByRole('button', { name: 'Copy' }))
    expect(await screen.findByText('Copied to the clipboard.')).toBeInTheDocument()
    await expect(navigator.clipboard.readText()).resolves.toBe('cs_0nce_only')

    await user.click(screen.getByRole('button', { name: 'Done' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(await screen.findByText('Bånder Scrobbler')).toBeInTheDocument()
    expect(screen.queryByText('cs_0nce_only')).not.toBeInTheDocument()

    // Opening the dialog again starts a new form; the secret is gone for good.
    await user.click(screen.getByRole('button', { name: /Create client/ }))
    expect(await screen.findByRole('dialog')).toBeInTheDocument()
    expect(screen.queryByText('cs_0nce_only')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Name')).toHaveValue('')
  })

  it('creates a device client without redirect URIs or a secret', async () => {
    authState.roles = ['ROLE_SUPER_ADMIN']
    post = () => ({ data: { ...TV, name: 'Living Room', clientSecret: null } })
    mount()
    await screen.findByText('Bånder Sync')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /Create client/ }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Name'), 'Living Room')
    await user.click(within(dialog).getByRole('combobox', { name: 'Type' }))
    await user.click(await screen.findByRole('option', { name: 'Device' }))
    expect(within(dialog).queryByLabelText('Redirect URIs')).not.toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: 'Create' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(postCalls()[0][0]).toBe('/api/admin/oauth/clients')
    expect(JSON.parse(String(postCalls()[0][1].body))).toEqual({ name: 'Living Room', type: 'device' })
  })

  it('requires valid redirect URIs for a public client', async () => {
    authState.roles = ['ROLE_SUPER_ADMIN']
    mount()
    await screen.findByText('Bånder Sync')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /Create client/ }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Name'), 'Bånder Mobile')
    await user.click(within(dialog).getByRole('button', { name: 'Create' }))
    expect(within(dialog).getByRole('alert')).toHaveTextContent('Add at least one redirect URI.')

    await user.type(within(dialog).getByLabelText('Redirect URIs'), 'not a uri')
    await user.click(within(dialog).getByRole('button', { name: 'Create' }))
    expect(within(dialog).getByRole('alert')).toHaveTextContent('Not an absolute URI: not a uri')
    expect(postCalls()).toHaveLength(0)
  })

  it('rotates a secret after confirmation and shows the new one once', async () => {
    authState.roles = ['ROLE_SUPER_ADMIN']
    post = () => ({ data: { ...SYNC, clientSecret: 'cs_r0tated' } })
    mount()
    await screen.findByText('Bånder Sync')

    const user = userEvent.setup()
    await user.click(within(row('Bånder Sync')).getByRole('button', { name: 'Rotate secret' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/The current secret stops working at once/)).toBeInTheDocument()
    expect(postCalls()).toHaveLength(0)

    await user.click(within(dialog).getByRole('button', { name: 'Rotate secret' }))
    expect(await screen.findByLabelText('Client secret')).toHaveTextContent('cs_r0tated')
    expect(postCalls().map(([url]) => url)).toEqual([`/api/admin/oauth/clients/${SYNC.clientId}/rotate-secret`])

    await user.keyboard('{Escape}')
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.queryByText('cs_r0tated')).not.toBeInTheDocument()

    await user.click(within(row('Bånder Sync')).getByRole('button', { name: 'Rotate secret' }))
    expect(await screen.findByRole('dialog')).toBeInTheDocument()
    expect(screen.queryByText('cs_r0tated')).not.toBeInTheDocument()
  })

  it('removes a revealed rotation secret immediately when authorization changes', async () => {
    authState.roles = ['ROLE_SUPER_ADMIN']
    post = () => ({ data: { ...SYNC, clientSecret: 'cs_revoked' } })
    mount()
    const user = userEvent.setup()
    await screen.findByText('Bånder Sync')
    await user.click(within(row('Bånder Sync')).getByRole('button', { name: 'Rotate secret' }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Rotate secret' }))
    expect(await screen.findByLabelText('Client secret')).toHaveTextContent('cs_revoked')
    act(() => { advanceAuthorization() })
    expect(screen.queryByLabelText('Client secret')).not.toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('revokes a client only after confirmation', async () => {
    authState.roles = ['ROLE_SUPER_ADMIN']
    post = () => {
      clients = clients.map((client) => (client.clientId === TV.clientId ? { ...client, revoked: true } : client))
      return { data: { ...TV, revoked: true } }
    }
    mount()
    await screen.findByText('Bånder TV')

    const user = userEvent.setup()
    await user.click(within(row('Bånder TV')).getByRole('button', { name: 'Revoke' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(postCalls()).toHaveLength(0)

    await user.click(within(row('Bånder TV')).getByRole('button', { name: 'Revoke' }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Revoke' }))

    await waitFor(() => expect(within(row('Bånder TV')).getByText('Revoked')).toBeInTheDocument())
    expect(postCalls().map(([url]) => url)).toEqual([`/api/admin/oauth/clients/${TV.clientId}/revoke`])
    expect(within(row('Bånder TV')).queryByRole('button')).not.toBeInTheDocument()
  })

  it('reports a failed load with a retry instead of an empty list', async () => {
    mockRequest.mockRejectedValueOnce(new Error('Unavailable'))
    mount()

    expect(await screen.findByRole('alert')).toHaveTextContent('Unable to load OAuth clients.')
    expect(screen.queryByText('No OAuth clients yet.')).not.toBeInTheDocument()

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Retry' }))
    expect(await screen.findByText('Bånder Sync')).toBeInTheDocument()
  })
})
