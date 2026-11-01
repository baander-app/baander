import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthenticatedClientProvider } from '@/shared/live/provider'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { synchronizeOwnership } from '@/shared/live/ownership'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { notificationApi, type NotificationItem } from '@/features/notification/api/notification-api'
import { notificationsKey } from '@/features/notification/hooks/use-notifications'
import { NotificationReader } from '@/features/notification/components/NotificationReader'
import { useNotificationStore } from '@/features/notification/stores/notification-store'
import { useMediaModeStore, type MediaType } from '../../stores/media-mode-store'
import { SidebarPinnedFooter } from '../SidebarPinnedFooter'

vi.mock('@/features/notification/api/notification-api', () => ({ notificationApi: {
  list: vi.fn(), unreadCount: vi.fn(), markRead: vi.fn(), markAllRead: vi.fn(),
} }))

vi.mock('@/shared/live/transport', () => ({
  authenticatedTransport: {
    retain: () => () => {},
    subscribe: () => () => {},
    isConnected: () => false,
  },
}))
vi.mock('@/shared/api-client/gen/endpoints', async importOriginal => ({
  ...await importOriginal<typeof import('@/shared/api-client/gen/endpoints')>(),
  getLiveRecovery: vi.fn(async () => ({
    version: 1, families: ['notifications'], authorizationFingerprint: 'b'.repeat(64),
    health: { state: 'current', oldestPendingAgeMs: null },
  })),
}))

const notification: NotificationItem = {
  publicId: 'fresh-alert', category: 'security', eventType: 'security.alert',
  title: 'New security alert', body: null, parameters: null, isRead: false,
  createdAt: '2026-11-01T10:00:00Z',
}

function Reader({ collapsed = false }: { collapsed?: boolean }) {
  return <NotificationReader><SidebarPinnedFooter collapsed={collapsed} /></NotificationReader>
}

function setup(collapsed = false) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(<QueryClientProvider client={client}><MemoryRouter><Reader collapsed={collapsed} /><button>Outside</button></MemoryRouter></QueryClientProvider>)
  return { client, user: userEvent.setup() }
}

beforeEach(() => {
  vi.resetAllMocks()
  useNotificationStore.getState().resetForPrincipal()
  useMediaModeStore.setState({ activeMedia: 'music' })
  vi.mocked(notificationApi.list).mockResolvedValue({ data: [notification], nextCursor: null })
  vi.mocked(notificationApi.unreadCount).mockResolvedValue(1)
})
afterEach(() => {
  cleanup()
  useAuthStore.setState({ isAuthenticated: false, principalSessionId: null })
  synchronizeOwnership(null)
})

describe('sidebar notifications', () => {
  it('keeps one reader active while closed and updates the badge and readable items after refresh', async () => {
    const { client, user } = setup()
    const trigger = await screen.findByRole('button', { name: '1 unread notifications' })
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(client.getQueryCache().find({ queryKey: notificationsKey })?.getObserversCount()).toBe(1)
    vi.mocked(notificationApi.list).mockResolvedValue({ data: [notification, { ...notification, publicId: 'second', title: 'Second alert' }], nextCursor: null })
    vi.mocked(notificationApi.unreadCount).mockResolvedValue(2)
    await act(async () => { await client.invalidateQueries() })
    await waitFor(() => expect(trigger).toHaveAccessibleName('2 unread notifications'))
    await user.click(trigger)
    expect(await screen.findByRole('dialog', { name: 'Notifications' })).toBeVisible()
    expect(screen.getByText('Second alert')).toBeVisible()
    expect(client.getQueryCache().find({ queryKey: notificationsKey })?.getObserversCount()).toBe(1)
    await user.keyboard('{Escape}')
    await waitFor(() => expect(trigger).toHaveFocus())
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    await user.click(trigger)
    await user.click(screen.getByRole('button', { name: 'Outside' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it.each([false, true])('opens by keyboard and marks all read with collapsed=%s', async (collapsed) => {
    vi.mocked(notificationApi.markAllRead).mockImplementation(async () => {
      vi.mocked(notificationApi.unreadCount).mockResolvedValue(0)
      vi.mocked(notificationApi.list).mockResolvedValue({ data: [{ ...notification, isRead: true }], nextCursor: null })
      return {} as Awaited<ReturnType<typeof notificationApi.markAllRead>>
    })
    const { user } = setup(collapsed)
    const trigger = await screen.findByRole('button', { name: '1 unread notifications' })
    trigger.focus()
    await user.keyboard('{Enter}')
    const markAll = await screen.findByRole('button', { name: 'Mark all read' })
    expect(markAll).toHaveFocus()
    await user.keyboard('{Enter}')
    await waitFor(() => expect(trigger).toHaveAccessibleName('0 unread notifications'))
    await user.keyboard('{Escape}')
    await waitFor(() => expect(trigger).toHaveFocus())
  })

  it('clears an open popout and unread badge when the authenticated principal changes', async () => {
    useAuthStore.setState({ isAuthenticated: true, principalSessionId: 'notifications-account-a' })
    render(<AuthenticatedClientProvider><MemoryRouter><Reader /></MemoryRouter></AuthenticatedClientProvider>)
    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: '1 unread notifications' }))
    expect(await screen.findByText('New security alert')).toBeVisible()
    vi.mocked(notificationApi.list).mockResolvedValue({ data: [], nextCursor: null })
    vi.mocked(notificationApi.unreadCount).mockResolvedValue(0)
    act(() => useAuthStore.setState({ principalSessionId: 'notifications-account-b' }))
    await screen.findByRole('button', { name: '0 unread notifications' })
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: '0 unread notifications' }))
    expect(await screen.findByText('No notifications')).toBeVisible()
    expect(screen.queryByText('New security alert')).not.toBeInTheDocument()
  })

  it.each<MediaType>(['music', 'movies', 'tv', 'podcasts', 'concerts', 'ebooks'])('retains utility links in %s mode', async (media) => {
    useMediaModeStore.setState({ activeMedia: media })
    setup()
    expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute('href', '/settings')
    expect(screen.queryByRole('link', { name: 'Equalizer' }) !== null).toBe(media === 'music')
    expect(await screen.findByRole('button', { name: '1 unread notifications' })).toBeVisible()
  })
})
