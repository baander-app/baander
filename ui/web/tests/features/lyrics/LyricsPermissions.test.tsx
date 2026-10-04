import { act, cleanup, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render } from '../../test-utils'

type FetchVariables = { publicId: string }
type ApplyVariables = { resultId: number; data: { songPublicId: string } }

type MutationOptions<Variables> = {
  mutation: {
    onSuccess: (data: unknown, variables: Variables) => void
  }
}

type MutationState<Variables> = {
  mutate: (variables: Variables) => void
  isPending: boolean
}

const api = vi.hoisted(() => ({
  read: vi.fn(),
  search: vi.fn(),
  fetch: vi.fn(),
  apply: vi.fn(),
  fetchHook: vi.fn<(options: MutationOptions<FetchVariables>) => MutationState<FetchVariables>>(),
  applyHook: vi.fn<(options: MutationOptions<ApplyVariables>) => MutationState<ApplyVariables>>(),
}))

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetLyricsSongLyrics: api.read,
  useGetLyricsSearch: api.search,
  usePostLyricsSongLyricsFetch: api.fetchHook,
  usePostLyricsApply: api.applyHook,
  getGetLyricsSongLyricsQueryKey: (id: string) => ['lyrics', id],
}))
vi.mock('@/features/player/services/service-worker-bridge', () => ({
  postTokenToWorker: vi.fn().mockResolvedValue(undefined),
}))
vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (state: { currentTrack: { publicId: string } }) => unknown) =>
    selector({ currentTrack: { publicId: 'LyricsPermissionSong1' } }),
}))

import { useAuthStore } from '@/features/auth/stores/auth-store'
import { LyricsDialog } from '@/features/catalog/components/LyricsDialog'
import { LyricsTab } from '@/features/layout/components/LyricsTab'

const initialState = useAuthStore.getState()
const user = {
  uuid: '0198d4d2-7600-7000-8000-000000000001',
  publicId: 'LyricsPermissionUser1',
  email: 'lyrics@baander.app',
  name: 'Lyrics user',
  roles: ['ROLE_USER'],
}
const dialogProps = {
  open: true,
  onOpenChange: vi.fn(),
  songPublicId: 'LyricsPermissionSong1',
  songTitle: 'Allowed song',
  artistName: 'Allowed artist',
}

function renderDialog(open = true) {
  const client = new QueryClient()
  const view = render(
    <QueryClientProvider client={client}>
      <LyricsDialog {...dialogProps} open={open} />
    </QueryClientProvider>,
  )

  return { ...view, client }
}

beforeEach(() => {
  vi.clearAllMocks()
  api.fetchHook.mockReturnValue({ mutate: api.fetch, isPending: false })
  api.applyHook.mockReturnValue({ mutate: api.apply, isPending: false })
  useAuthStore.setState({ user })
  api.read.mockReturnValue({ data: { data: [] }, isLoading: false })
  api.search.mockReturnValue({
    data: {
      data: [{ id: 42, trackName: 'Provider result', plainLyrics: 'Preview' }],
    },
    isLoading: false,
  })
})

afterEach(() => {
  cleanup()
  useAuthStore.setState(initialState, true)
})

describe('lyrics management permissions', () => {
  it('keeps member lyrics reads but hides management controls', () => {
    api.read.mockReturnValue({ data: { data: { plainLyrics: 'Cached member lyrics' } }, isLoading: false })
    renderDialog()

    expect(screen.getByText('Cached member lyrics')).toBeVisible()
    expect(screen.queryByRole('tab', { name: 'Search' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Fetch from LRCLIB/ })).not.toBeInTheDocument()
    expect(screen.queryByText('Provider result')).not.toBeInTheDocument()
    expect(api.read).toHaveBeenLastCalledWith(dialogProps.songPublicId, { query: { enabled: true } })
    expect(api.search).toHaveBeenLastCalledWith({ q: '' }, { query: { enabled: false } })
    expect(api.fetch).not.toHaveBeenCalled()
    expect(api.apply).not.toHaveBeenCalled()
  })

  it('hides the player fetch control for members with no cached lyrics', () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <LyricsTab />
      </QueryClientProvider>,
    )

    expect(screen.getByText('No lyrics cached')).toBeVisible()
    expect(screen.queryByRole('button', { name: /Fetch from LRCLIB/ })).not.toBeInTheDocument()
    expect(api.fetch).not.toHaveBeenCalled()
  })

  it.each(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])('allows %s to fetch and apply', async (role) => {
    useAuthStore.setState({ user: { ...user, roles: [role] } })
    renderDialog()
    const interaction = userEvent.setup()

    await interaction.click(screen.getByRole('button', { name: /Fetch from LRCLIB/ }))
    expect(api.fetch).toHaveBeenCalledWith({ publicId: dialogProps.songPublicId })
    await interaction.click(screen.getByRole('tab', { name: 'Search' }))
    expect(api.search).toHaveBeenLastCalledWith({ q: '' }, { query: { enabled: false } })
    await interaction.click(screen.getByRole('button', { name: 'Auto' }))
    expect(api.search).toHaveBeenLastCalledWith({ q: 'Allowed song Allowed artist' }, { query: { enabled: true } })
    await interaction.click(screen.getByRole('button', { name: /Provider result/ }))
    expect(api.apply).toHaveBeenCalledWith({ resultId: 42, data: { songPublicId: dialogProps.songPublicId } })
  })

  it('keeps failed reads distinct from an empty cache in both views', () => {
    useAuthStore.setState({ user: { ...user, roles: ['ROLE_ADMIN'] } })
    api.read.mockReturnValue({ isLoading: false, isError: true })
    const dialog = renderDialog()
    expect(screen.getByText('Unable to load lyrics')).toBeVisible()
    expect(screen.queryByText('No lyrics cached for this track.')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Fetch from LRCLIB/ })).not.toBeInTheDocument()
    dialog.unmount()

    render(
      <QueryClientProvider client={new QueryClient()}>
        <LyricsTab />
      </QueryClientProvider>,
    )
    expect(screen.getByText('Unable to load lyrics')).toBeVisible()
    expect(screen.queryByText('No lyrics cached')).not.toBeInTheDocument()
    expect(api.fetch).not.toHaveBeenCalled()
  })

  it('allows the administrator to fetch in the player view', async () => {
    useAuthStore.setState({ user: { ...user, roles: ['ROLE_ADMIN'] } })
    render(
      <QueryClientProvider client={new QueryClient()}>
        <LyricsTab />
      </QueryClientProvider>,
    )
    await userEvent.setup().click(screen.getByRole('button', { name: /Fetch from LRCLIB/ }))
    expect(api.fetch).toHaveBeenCalledWith({ publicId: dialogProps.songPublicId })
  })

  it('invalidates the mutated song when the dialog changes songs before completion', () => {
    useAuthStore.setState({ user: { ...user, roles: ['ROLE_ADMIN'] } })
    const view = renderDialog()
    const invalidation = vi.spyOn(view.client, 'invalidateQueries').mockResolvedValue()
    view.rerender(
      <QueryClientProvider client={view.client}>
        <LyricsDialog {...dialogProps} songPublicId="DifferentSongFixture1" />
      </QueryClientProvider>,
    )
    const fetchCallbacks = api.fetchHook.mock.lastCall?.[0].mutation
    const applyCallbacks = api.applyHook.mock.lastCall?.[0].mutation
    expect(fetchCallbacks).toBeDefined()
    expect(applyCallbacks).toBeDefined()
    fetchCallbacks?.onSuccess(undefined, { publicId: dialogProps.songPublicId })
    applyCallbacks?.onSuccess(undefined, { resultId: 42, data: { songPublicId: dialogProps.songPublicId } })
    expect(invalidation).toHaveBeenNthCalledWith(1, { queryKey: ['lyrics', dialogProps.songPublicId] })
    expect(invalidation).toHaveBeenNthCalledWith(2, { queryKey: ['lyrics', dialogProps.songPublicId] })
  })

  it('renders cached lyrics immediately on demotion and disables searches while closed', async () => {
    useAuthStore.setState({ user: { ...user, roles: ['ROLE_ADMIN'] } })
    const view = renderDialog()
    const interaction = userEvent.setup()
    await interaction.click(screen.getByRole('tab', { name: 'Search' }))
    await interaction.click(screen.getByRole('button', { name: 'Auto' }))
    expect(api.search).toHaveBeenLastCalledWith({ q: 'Allowed song Allowed artist' }, { query: { enabled: true } })

    view.rerender(
      <QueryClientProvider client={new QueryClient()}>
        <LyricsDialog {...dialogProps} open={false} />
      </QueryClientProvider>,
    )
    expect(api.search).toHaveBeenLastCalledWith({ q: 'Allowed song Allowed artist' }, { query: { enabled: false } })
    view.rerender(
      <QueryClientProvider client={new QueryClient()}>
        <LyricsDialog {...dialogProps} />
      </QueryClientProvider>,
    )
    api.read.mockReturnValue({ data: { data: { plainLyrics: 'Visible after demotion' } }, isLoading: false })
    act(() => {
      useAuthStore.setState({ user })
    })

    expect(screen.getByRole('tab', { name: 'Lyrics' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByText('Visible after demotion')).toBeVisible()
    expect(screen.queryByRole('tab', { name: 'Search' })).not.toBeInTheDocument()
    expect(screen.queryByText('Provider result')).not.toBeInTheDocument()
    expect(api.search).toHaveBeenLastCalledWith({ q: 'Allowed song Allowed artist' }, { query: { enabled: false } })
    expect(api.apply).not.toHaveBeenCalled()
    expect(api.fetch).not.toHaveBeenCalled()
  })
})
