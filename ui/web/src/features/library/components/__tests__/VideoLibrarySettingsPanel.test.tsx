import { cleanup, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render } from '../../../../../tests/test-utils'
import { customInstance } from '@/shared/api-client/axios-instance'
import { VideoLibrarySettingsPanel } from '../VideoLibrarySettingsPanel'

vi.mock('@/shared/api-client/axios-instance', () => ({
  customInstance: vi.fn(),
}))

const fixtures = vi.hoisted(() => ({
  isAdmin: true,
  statuses: [
    { id: 'tvdb', name: 'TheTVDB', enabled: false, configured: true },
    { id: 'tmdb', name: 'TMDB', enabled: true, configured: false },
  ],
}))

vi.mock('@/features/auth/hooks/use-admin-check', () => ({
  useIsAdmin: () => fixtures.isAdmin,
}))
vi.mock('@/features/admin/hooks/use-metadata-admin', () => ({
  useMetadataProviders: () => ({
    data: fixtures.statuses,
    isPending: false,
    isError: false,
  }),
}))

const request = vi.mocked(customInstance)
const libraryId = 'library00000000000001'
const prefix = `/api/admin/video-matches/libraries/${libraryId}`
let savedOrder: string[]
let failPreview: boolean
let failSave: boolean

function mount(libraryType: 'tv_show' | 'movie' | 'music' = 'tv_show') {
  const client = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })
  const rendered = render(
    <QueryClientProvider client={client}>
      <VideoLibrarySettingsPanel libraryId={libraryId} libraryType={libraryType} />
    </QueryClientProvider>,
  )

  return { client, ...rendered }
}

function mutationBodies() {
  return request.mock.calls
    .filter(([, options]) => options?.method === 'POST')
    .map(([, options]) => JSON.parse(String(options?.body)) as { confirmed: boolean; force: boolean })
}

describe('VideoLibrarySettingsPanel', () => {
  beforeEach(() => {
    fixtures.isAdmin = true
    savedOrder = ['tvdb', 'tmdb', 'local']
    failPreview = false
    failSave = false
    request.mockImplementation((url, options) => {
      if (url === `${prefix}/provider-order` && options?.method === 'PUT') {
        if (failSave) {
          return Promise.reject(new Error('Unavailable'))
        }
        savedOrder = (JSON.parse(String(options.body)) as { providers: string[] }).providers
        return Promise.resolve({ data: { providers: savedOrder } })
      }
      if (url === `${prefix}/provider-order`) {
        return Promise.resolve({ data: { providers: savedOrder } })
      }
      if (url === `${prefix}/rematch-preview`) {
        if (failPreview) {
          return Promise.reject(new Error('Unavailable'))
        }
        return Promise.resolve({ data: {
          libraryId,
          totalTitles: 10,
          handMatchedTitles: 3,
          handAssignedFiles: 7,
        } })
      }
      if (url === `${prefix}/rematch` && options?.method === 'POST') {
        return Promise.resolve({ data: {
          libraryId,
          totalTitles: 10,
          handMatchedTitles: 3,
          handAssignedFiles: 7,
          queued: 7,
          skipped: 3,
        } })
      }
      return Promise.reject(new Error(`Unexpected request ${url}`))
    })
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('does not expose controls or issue admin reads for members or music libraries', () => {
    fixtures.isAdmin = false
    const member = mount()
    expect(screen.queryByRole('heading', { name: 'Video matching' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
    member.unmount()
    fixtures.isAdmin = true
    mount('music')
    expect(screen.queryByRole('heading', { name: 'Video matching' })).not.toBeInTheDocument()
    expect(request).not.toHaveBeenCalled()
  })

  it('keeps disabled and unconfigured providers, moves them by keyboard and saves the complete order', async () => {
    const user = userEvent.setup()
    mount()
    const list = await screen.findByRole('list', { name: 'Metadata provider order' })
    expect(within(list).getAllByRole('listitem')).toHaveLength(3)
    expect(screen.getByText('Disabled on this server')).toBeInTheDocument()
    expect(screen.getByText('Key not configured')).toBeInTheDocument()
    expect(screen.getByText('Local files')).toBeInTheDocument()
    const move = screen.getByRole('button', { name: 'Move TMDB up' })
    move.focus()
    await user.keyboard('{Enter}')
    expect(within(list).getAllByRole('listitem').map((item) => item.textContent)).toEqual([
      'TMDBKey not configured',
      'TheTVDBDisabled on this server',
      'Local filesAvailable',
    ])
    await user.click(screen.getByRole('button', { name: 'Save provider order' }))
    expect(await screen.findByText('Provider order saved.')).toBeInTheDocument()
    expect(savedOrder).toEqual(['tmdb', 'tvdb', 'local'])
    expect(screen.getByRole('button', { name: 'Save provider order' })).toBeDisabled()
  })

  it('uses the movie library’s complete supported order without adding television providers', async () => {
    savedOrder = ['tmdb', 'local']
    mount('movie')
    const list = await screen.findByRole('list', { name: 'Metadata provider order' })
    expect(within(list).getAllByRole('listitem')).toHaveLength(2)
    expect(within(list).queryByText('TheTVDB')).not.toBeInTheDocument()
    expect(within(list).getByText('TMDB')).toBeInTheDocument()
    expect(within(list).getByText('Local files')).toBeInTheDocument()
  })

  it('previews counts without writing and only submits after explicit confirmation', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByRole('list', { name: 'Metadata provider order' })
    await user.click(screen.getByRole('button', { name: 'Review library re-match' }))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText('10 titles, including 3 manually matched titles.')).toBeInTheDocument()
    expect(within(dialog).getByText(/7 files have manual assignments/)).toBeInTheDocument()
    expect(mutationBodies()).toEqual([])
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    expect(mutationBodies()).toEqual([])
    await user.click(screen.getByRole('button', { name: 'Review library re-match' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Confirm library re-match' })).toBeEnabled())
    expect(screen.getByRole('checkbox', { name: 'Include manually matched titles' })).not.toBeChecked()
    await user.click(screen.getByRole('button', { name: 'Confirm library re-match' }))
    expect(await screen.findByText('Re-match queued for 7 titles; 3 titles kept.')).toBeInTheDocument()
    expect(mutationBodies()).toEqual([{ confirmed: true, force: false }])
  })

  it('sends force only when the separate manual-match option is checked', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByRole('list', { name: 'Metadata provider order' })
    await user.click(screen.getByRole('button', { name: 'Review library re-match' }))
    await screen.findByRole('checkbox', { name: 'Include manually matched titles' })
    await user.click(screen.getByRole('checkbox', { name: 'Include manually matched titles' }))
    expect(mutationBodies()).toEqual([])
    await user.click(screen.getByRole('button', { name: 'Confirm library re-match' }))
    await waitFor(() => expect(mutationBodies()).toEqual([{ confirmed: true, force: true }]))
  })

  it('blocks confirmation on failed preview and permits a retry', async () => {
    failPreview = true
    const user = userEvent.setup()
    mount()
    await screen.findByRole('list', { name: 'Metadata provider order' })
    await user.click(screen.getByRole('button', { name: 'Review library re-match' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load the re-match preview.')
    expect(screen.getByRole('button', { name: 'Confirm library re-match' })).toBeDisabled()
    expect(mutationBodies()).toEqual([])
    failPreview = false
    await user.click(screen.getByRole('button', { name: 'Retry preview' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Confirm library re-match' })).toBeEnabled())
  })

  it('preserves the changed order after a save failure and clears the error on another move', async () => {
    failSave = true
    const user = userEvent.setup()
    mount()
    await screen.findByRole('list', { name: 'Metadata provider order' })
    await user.click(screen.getByRole('button', { name: 'Move TMDB up' }))
    await user.click(screen.getByRole('button', { name: 'Save provider order' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not save the provider order.')
    expect(screen.getByRole('button', { name: 'Save provider order' })).toBeEnabled()
    await user.click(screen.getByRole('button', { name: 'Move TMDB down' }))
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})
