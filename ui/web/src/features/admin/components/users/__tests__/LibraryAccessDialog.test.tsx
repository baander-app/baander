import { useState } from 'react'
import { cleanup, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { AdminUser, AdminUserLibraryAccess } from '../../../api/user-admin-api'
import { render } from '../../../../../../tests/test-utils'
import { LibraryAccessDialog } from '../LibraryAccessDialog'
import { UserRowActions } from '../UserRowActions'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: vi.fn(), put: vi.fn(), delete: vi.fn() },
  customInstance: vi.fn(),
}))

const mockGet = vi.mocked(AXIOS_INSTANCE.get)
const mockPut = vi.mocked(AXIOS_INSTANCE.put)
const mockDelete = vi.mocked(AXIOS_INSTANCE.delete)

const ALICE: AdminUser = {
  id: '0199bf3c-8a00-7000-8000-00000000a11c',
  email: 'alice@baander.app',
  name: 'Alice',
  roles: ['ROLE_USER'],
  disabled: false,
  createdAt: '2026-10-07T12:00:00+00:00',
}

const LIBRARIES_URL = `/api/admin/users/${ALICE.id}/libraries`

const JAZZ: AdminUserLibraryAccess = {
  libraryId: '0199bf3c-8a00-7000-8000-0000000000b1',
  name: 'Jazz',
  slug: 'jazz',
  type: 'music',
  granted: true,
}

const ROCK: AdminUserLibraryAccess = {
  libraryId: '0199bf3c-8a00-7000-8000-0000000000b2',
  name: 'Rock',
  slug: 'rock',
  type: 'music',
  granted: false,
}

const FILMS: AdminUserLibraryAccess = {
  libraryId: '0199bf3c-8a00-7000-8000-0000000000b3',
  name: 'Films',
  slug: 'films',
  type: 'movie',
  granted: true,
}

/** The libraries the fake server lists for Alice. */
let served: AdminUserLibraryAccess[]
/** Library ids whose grant or revoke the fake server refuses. */
let failing: Set<string>

function serverError(): AxiosError {
  return new AxiosError('Server error', 'ERR_BAD_RESPONSE', undefined, undefined, {
    status: 500,
    statusText: 'Internal Server Error',
    headers: {},
    config: { headers: new AxiosHeaders() },
    data: { message: 'Server error' },
  })
}

function write(url: string, granted: boolean) {
  const library = served.find((candidate) => url === `${LIBRARIES_URL}/${candidate.libraryId}`)
  if (!library) throw new Error(`Unexpected write to ${url}`)
  if (failing.has(library.libraryId)) throw serverError()

  const saved = { ...library, granted }
  served = served.map((candidate) => (candidate.libraryId === saved.libraryId ? saved : candidate))

  return { data: { data: saved } }
}

function serve() {
  mockGet.mockImplementation(async (url: string) => {
    if (url !== LIBRARIES_URL) throw new Error(`Unexpected GET ${url}`)

    return { data: { data: served } }
  })
  mockPut.mockImplementation(async (url: string) => write(url, true))
  mockDelete.mockImplementation(async (url: string) => write(url, false))
}

function renderDialog() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const onOpenChange = vi.fn()
  render(
    <QueryClientProvider client={client}>
      <LibraryAccessDialog user={ALICE} open onOpenChange={onOpenChange} />
    </QueryClientProvider>,
  )

  return { client, onOpenChange }
}

describe('LibraryAccessDialog', () => {
  beforeAll(() => {
    // Radix menus use pointer capture and scrolling, which jsdom lacks.
    Element.prototype.hasPointerCapture = () => false
    Element.prototype.releasePointerCapture = () => {}
    Element.prototype.scrollIntoView = () => {}
  })

  beforeEach(() => {
    served = [JAZZ, ROCK, FILMS]
    failing = new Set()
    mockGet.mockReset()
    mockPut.mockReset()
    mockDelete.mockReset()
    serve()
  })

  afterEach(() => {
    cleanup()
  })

  it('lists every library with its access and saves only the rows that changed', async () => {
    const user = userEvent.setup()
    const { client, onOpenChange } = renderDialog()
    const invalidate = vi.spyOn(client, 'invalidateQueries')

    expect(await screen.findByRole('checkbox', { name: 'Jazz' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Rock' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Films' })).toBeChecked()

    await user.click(screen.getByRole('checkbox', { name: 'Rock' }))
    await user.click(screen.getByRole('checkbox', { name: 'Films' }))
    // Toggled twice, so it is back to its loaded state and must not be sent.
    await user.click(screen.getByRole('checkbox', { name: 'Jazz' }))
    await user.click(screen.getByRole('checkbox', { name: 'Jazz' }))
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
    expect(mockPut).toHaveBeenCalledOnce()
    expect(mockPut).toHaveBeenCalledWith(`${LIBRARIES_URL}/${ROCK.libraryId}`)
    expect(mockDelete).toHaveBeenCalledOnce()
    expect(mockDelete).toHaveBeenCalledWith(`${LIBRARIES_URL}/${FILMS.libraryId}`)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['admin-user-libraries', ALICE.id] })
  })

  it('shows the empty message and no Save action when there are no libraries', async () => {
    served = []
    renderDialog()

    expect(await screen.findByText('There are no libraries yet.')).toBeInTheDocument()
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument()
  })

  it('keeps a failed revoke checked, names the library and stays open', async () => {
    failing = new Set([FILMS.libraryId])
    const user = userEvent.setup()
    const { onOpenChange } = renderDialog()

    await user.click(await screen.findByRole('checkbox', { name: 'Films' }))
    await user.click(screen.getByRole('checkbox', { name: 'Rock' }))
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Access to Films was not saved.')
    expect(screen.getByRole('alert')).not.toHaveTextContent('Rock')
    expect(screen.getByRole('checkbox', { name: 'Films' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Rock' })).toBeChecked()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
    expect(onOpenChange).not.toHaveBeenCalled()

    // The grant that succeeded is now the saved state, so saving again sends nothing for it.
    mockPut.mockClear()
    mockDelete.mockClear()
    failing = new Set()
    await user.click(screen.getByRole('checkbox', { name: 'Films' }))
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
    expect(mockPut).not.toHaveBeenCalled()
    expect(mockDelete).toHaveBeenCalledOnce()
    expect(mockDelete).toHaveBeenCalledWith(`${LIBRARIES_URL}/${FILMS.libraryId}`)
  })

  it('disables the checkboxes and Save while saving', async () => {
    let finish: (value: unknown) => void = () => {}
    mockPut.mockImplementation(() => new Promise((resolve) => {
      finish = resolve
    }))
    const user = userEvent.setup()
    renderDialog()

    await user.click(await screen.findByRole('checkbox', { name: 'Rock' }))
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByRole('button', { name: 'Saving...' })).toBeDisabled()
    for (const checkbox of screen.getAllByRole('checkbox')) {
      expect(checkbox).toBeDisabled()
    }

    finish({ data: { data: { ...ROCK, granted: true } } })
  })

  it('returns focus to the row action when it closes', async () => {
    function Harness() {
      const [trigger, setTrigger] = useState<HTMLElement | null>(null)

      return (
        <>
          <UserRowActions
            user={ALICE}
            canManage
            onEdit={vi.fn()}
            onAssignRoles={vi.fn()}
            onLibraryAccess={setTrigger}
            onResetPassword={vi.fn()}
            onToggle={vi.fn()}
            onDelete={vi.fn()}
          />
          <LibraryAccessDialog
            user={trigger ? ALICE : null}
            open={trigger !== null}
            onOpenChange={(open) => {
              if (!open) setTrigger(null)
            }}
            returnFocusTo={trigger}
          />
        </>
      )
    }

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const user = userEvent.setup()
    render(
      <QueryClientProvider client={client}>
        <Harness />
      </QueryClientProvider>,
    )
    const trigger = screen.getByRole('button', { name: 'Actions for alice@baander.app' })

    await user.click(trigger)
    await user.click(screen.getByRole('menuitem', { name: 'Library access' }))
    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByRole('checkbox', { name: 'Jazz' })
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(trigger).toHaveFocus()
  })
})
