import { cleanup, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { customInstance } from '@/shared/api-client/axios-instance'
import type { GetPlaylistShow200Data } from '@/shared/api-client/gen/endpoints'
import { render } from '../../../../../tests/test-utils'
import { SharePlaylistDialog } from '../SharePlaylistDialog'

vi.mock('@/shared/api-client/axios-instance', () => ({
  customInstance: vi.fn(),
}))

const mockRequest = vi.mocked(customInstance)
const initialAuthState = useAuthStore.getState()

const OWNER = 'ownerPublicId00000001'
const COLLABORATOR = 'collabPublicId0000001'
const NEWCOMER = 'newCollabPublicId0001'
const PLAYLIST = 'playlistPublicId00001'
const PLAYLIST_URL = `/api/playlists/${PLAYLIST}`

/** The playlist the fake server answers with; writes change it in place. */
let served: GetPlaylistShow200Data
/** Replaces the answer to the next collaborator write. */
let writeFailure: AxiosError | null

function playlist(overrides: Partial<GetPlaylistShow200Data> = {}): GetPlaylistShow200Data {
  return {
    publicId: PLAYLIST,
    ownerPublicId: OWNER,
    collaboratorPublicIds: [],
    name: 'Road trip',
    description: null,
    isPublic: false,
    isCollaborative: false,
    isSmart: false,
    songCount: 0,
    createdAt: '2026-10-14T10:00:00+00:00',
    songs: [],
    ...overrides,
  }
}

function httpError(status: number, message: string): AxiosError {
  const config = { headers: new AxiosHeaders() }

  return new AxiosError(`Request failed with status code ${status}`, 'ERR_BAD_REQUEST', config, undefined, {
    data: { error: { code: status, message } },
    status,
    statusText: 'Error',
    headers: {},
    config,
  })
}

function collaboratorOf(url: string): string {
  const prefix = `${PLAYLIST_URL}/collaborators/`
  if (!url.startsWith(prefix)) throw new Error(`Unexpected request ${url}`)

  return url.slice(prefix.length)
}

function serve() {
  mockRequest.mockImplementation(async (url: string, options: RequestInit) => {
    if (url === PLAYLIST_URL && options.method === 'GET') return { data: served }
    if (url === PLAYLIST_URL && options.method === 'PATCH') {
      served = { ...served, ...JSON.parse(String(options.body)) }

      return { data: served }
    }
    if (writeFailure) {
      const failure = writeFailure
      writeFailure = null
      throw failure
    }
    const collaborator = collaboratorOf(url)
    const others = (served.collaboratorPublicIds ?? []).filter((candidate) => candidate !== collaborator)
    if (options.method === 'PUT') served = { ...served, collaboratorPublicIds: [...others, collaborator] }
    if (options.method === 'DELETE') served = { ...served, collaboratorPublicIds: others }

    return undefined
  })
}

function signInAs(publicId: string) {
  useAuthStore.setState({
    user: { publicId, email: 'owner@baander.app', name: 'Owner', roles: ['ROLE_USER'] },
    isAuthenticated: true,
  })
}

function renderDialog() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  render(
    <QueryClientProvider client={client}>
      <SharePlaylistDialog playlistPublicId={PLAYLIST} />
    </QueryClientProvider>,
  )

  return client
}

async function openDialog() {
  const user = userEvent.setup()
  const client = renderDialog()
  await user.click(screen.getByRole('button', { name: 'Share' }))
  const dialog = await screen.findByRole('dialog', { name: 'Share playlist' })

  return { user, client, dialog }
}

function collaboratorList(dialog: HTMLElement) {
  return within(dialog).getByRole('list', { name: 'Collaborators' })
}

function requestsTo(method: string, url: string) {
  return mockRequest.mock.calls.filter(([calledUrl, options]) => calledUrl === url && options.method === method)
}

describe('SharePlaylistDialog', () => {
  beforeEach(() => {
    served = playlist()
    writeFailure = null
    mockRequest.mockReset()
    serve()
    signInAs(OWNER)
  })

  afterEach(() => {
    cleanup()
    useAuthStore.setState(initialAuthState, true)
  })

  it('opens with the switch off, no collaborators and the owner’s own public id', async () => {
    const { dialog } = await openDialog()

    expect(await within(dialog).findByRole('switch', { name: 'Let collaborators edit' })).not.toBeChecked()
    expect(within(dialog).getByText('No collaborators yet.')).toBeInTheDocument()
    expect(within(dialog).getByText(OWNER)).toBeInTheDocument()
  })

  it('turns collaboration on through the update and shows the refetched state', async () => {
    const { user, client, dialog } = await openDialog()
    const invalidate = vi.spyOn(client, 'invalidateQueries')

    await user.click(await within(dialog).findByRole('switch', { name: 'Let collaborators edit' }))

    await waitFor(() => expect(within(dialog).getByRole('switch', { name: 'Let collaborators edit' })).toBeChecked())
    const [update] = requestsTo('PATCH', PLAYLIST_URL)
    expect(JSON.parse(String(update[1].body))).toEqual({ isCollaborative: true })
    expect(invalidate).toHaveBeenCalledWith({ predicate: expect.any(Function) })
    expect(requestsTo('GET', PLAYLIST_URL).length).toBeGreaterThanOrEqual(2)
  })

  it('adds a collaborator by public id and lists them', async () => {
    const { user, dialog } = await openDialog()

    await user.type(await within(dialog).findByRole('textbox', { name: 'Collaborator public id' }), NEWCOMER)
    await user.click(within(dialog).getByRole('button', { name: 'Add' }))

    expect(await within(collaboratorList(dialog)).findByText(NEWCOMER)).toBeInTheDocument()
    expect(requestsTo('PUT', `${PLAYLIST_URL}/collaborators/${NEWCOMER}`)).toHaveLength(1)
    expect(within(dialog).getByRole('textbox', { name: 'Collaborator public id' })).toHaveValue('')
    expect(within(dialog).queryByText('No collaborators yet.')).not.toBeInTheDocument()
  })

  it('removes a collaborator', async () => {
    served = playlist({ collaboratorPublicIds: [COLLABORATOR] })
    const { user, dialog } = await openDialog()

    expect(await within(collaboratorList(dialog)).findByText(COLLABORATOR)).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: `Remove ${COLLABORATOR}` }))

    await waitFor(() => expect(within(dialog).queryByText(COLLABORATOR)).not.toBeInTheDocument())
    expect(requestsTo('DELETE', `${PLAYLIST_URL}/collaborators/${COLLABORATOR}`)).toHaveLength(1)
    expect(within(dialog).getByText('No collaborators yet.')).toBeInTheDocument()
  })

  it('shows the server’s message when the public id is unknown and leaves the list unchanged', async () => {
    served = playlist({ collaboratorPublicIds: [COLLABORATOR] })
    writeFailure = httpError(404, 'No user has the public id unknownPublicId000001.')
    const { user, dialog } = await openDialog()

    await user.type(await within(dialog).findByRole('textbox', { name: 'Collaborator public id' }), 'unknownPublicId000001')
    await user.click(within(dialog).getByRole('button', { name: 'Add' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('No user has the public id unknownPublicId000001.')
    expect(within(collaboratorList(dialog)).getAllByRole('listitem')).toHaveLength(1)
    expect(within(dialog).queryByText('unknownPublicId000001')).not.toBeInTheDocument()
  })

  it('shows the server’s message when the owner adds their own public id', async () => {
    writeFailure = httpError(422, 'The owner cannot be a collaborator.')
    const { user, dialog } = await openDialog()

    await user.type(await within(dialog).findByRole('textbox', { name: 'Collaborator public id' }), OWNER)
    await user.click(within(dialog).getByRole('button', { name: 'Add' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('The owner cannot be a collaborator.')
    expect(within(dialog).getByText('No collaborators yet.')).toBeInTheDocument()
  })

  it('copies the owner’s public id to the clipboard', async () => {
    // user-event installs a clipboard stub on setup; the copy lands there.
    const { user, dialog } = await openDialog()

    await user.click(await within(dialog).findByRole('button', { name: 'Copy your public id' }))

    expect(await within(dialog).findByText('Copied to the clipboard.')).toBeInTheDocument()
    expect(await navigator.clipboard.readText()).toBe(OWNER)
  })
})
