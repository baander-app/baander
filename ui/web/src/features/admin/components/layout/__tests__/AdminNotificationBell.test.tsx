import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { notificationApi, type NotificationItem } from '@/features/notification/api/notification-api'
import { useNotificationStore } from '@/features/notification/stores/notification-store'
import { useLiveOutageStore } from '@/shared/live/outage-store'
import { AdminNotificationBell } from '../AdminNotificationBell'

vi.mock('@/features/notification/api/notification-api', () => ({ notificationApi: {
  list: vi.fn(),
  unreadCount: vi.fn(),
  markRead: vi.fn(),
  markAllRead: vi.fn(),
} }))

const OUTAGE_ENTRY = /Live updates unavailable/

const reindexFinished: NotificationItem = {
  publicId: 'reindex-finished',
  category: 'admin_operations',
  eventType: 'admin_operations.reindex_finished',
  title: 'Reindex finished',
  body: null,
  parameters: null,
  isRead: false,
  createdAt: '2026-11-01T10:00:00Z',
}

function listOutageEntry() {
  const outage = useLiveOutageStore.getState()
  outage.begin(Date.now() - 3 * 60_000)
  outage.listEntry()
}

function setup() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <AdminNotificationBell />
    </QueryClientProvider>,
  )
  return userEvent.setup()
}

async function openPopout(name: string) {
  const user = setup()
  const trigger = await screen.findByRole('button', { name })
  await user.click(trigger)
  await waitFor(() => expect(screen.queryByText('Loading notifications…')).not.toBeInTheDocument())
  return { user, trigger }
}

beforeEach(() => {
  vi.resetAllMocks()
  useNotificationStore.getState().resetForPrincipal()
  useLiveOutageStore.getState().end()
  vi.mocked(notificationApi.list).mockResolvedValue({ data: [reindexFinished], nextCursor: null })
  vi.mocked(notificationApi.unreadCount).mockResolvedValue(1)
})
afterEach(() => {
  cleanup()
  useLiveOutageStore.getState().end()
})

describe('admin notification bell', () => {
  it('counts unread admin notifications alone while no outage is listed', async () => {
    await openPopout('Notifications (1 unread)')

    expect(screen.getByText('Reindex finished')).toBeVisible()
    expect(screen.queryByRole('button', { name: OUTAGE_ENTRY })).not.toBeInTheDocument()
  })

  it('offers no mark-all with no admin notifications, even when other notifications are unread', async () => {
    vi.mocked(notificationApi.list).mockResolvedValue({ data: [], nextCursor: null })
    useNotificationStore.getState().setUnreadCount(4)
    await openPopout('Notifications (4 unread)')
    expect(screen.getByText('No admin notifications')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Mark all read' })).not.toBeInTheDocument()
    expect(notificationApi.markAllRead).not.toHaveBeenCalled()
  })

  it('counts and lists an unread outage entry, and marks it read without closing or a request', async () => {
    listOutageEntry()
    const { user, trigger } = await openPopout('Notifications (2 unread)')
    expect(screen.getByText('Reindex finished')).toBeVisible()

    await user.click(screen.getByRole('button', { name: OUTAGE_ENTRY }))

    expect(trigger).toHaveAccessibleName('Notifications (1 unread)')
    expect(screen.getByText('Admin Notifications')).toBeVisible()
    expect(screen.getByRole('button', { name: OUTAGE_ENTRY })).toBeVisible()
    expect(notificationApi.markRead).not.toHaveBeenCalled()
    expect(notificationApi.markAllRead).not.toHaveBeenCalled()
  })

  it('shows the entry instead of the empty message and marks it read without a request', async () => {
    vi.mocked(notificationApi.list).mockResolvedValue({ data: [], nextCursor: null })
    vi.mocked(notificationApi.unreadCount).mockResolvedValue(0)
    listOutageEntry()
    const { user, trigger } = await openPopout('Notifications (1 unread)')
    expect(screen.getByRole('button', { name: OUTAGE_ENTRY })).toBeVisible()
    expect(screen.queryByText('No admin notifications')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Mark all read' }))

    expect(trigger).toHaveAccessibleName('Notifications')
    expect(screen.getByRole('button', { name: OUTAGE_ENTRY })).toBeVisible()
    expect(notificationApi.markAllRead).not.toHaveBeenCalled()
  })

  it('marks the entry read and asks the server when admin notifications are unread', async () => {
    vi.mocked(notificationApi.markAllRead).mockImplementation(async () => {
      vi.mocked(notificationApi.list).mockResolvedValue({ data: [{ ...reindexFinished, isRead: true }], nextCursor: null })
      vi.mocked(notificationApi.unreadCount).mockResolvedValue(0)
      return {} as Awaited<ReturnType<typeof notificationApi.markAllRead>>
    })
    listOutageEntry()
    const { user, trigger } = await openPopout('Notifications (2 unread)')

    await user.click(screen.getByRole('button', { name: 'Mark all read' }))

    expect(notificationApi.markAllRead).toHaveBeenCalledTimes(1)
    await waitFor(() => expect(trigger).toHaveAccessibleName('Notifications'))
    expect(screen.getByRole('button', { name: OUTAGE_ENTRY })).toBeVisible()
  })

  it('drops the entry and its count when the outage ends, moving focus from the entry to the popout', async () => {
    listOutageEntry()
    const { trigger } = await openPopout('Notifications (2 unread)')
    const entry = screen.getByRole('button', { name: OUTAGE_ENTRY })
    act(() => entry.focus())

    act(() => useLiveOutageStore.getState().end())

    expect(entry).not.toBeInTheDocument()
    expect(trigger).toHaveAccessibleName('Notifications (1 unread)')
    expect(document.activeElement).not.toBe(document.body)
    expect(document.activeElement).toContainElement(screen.getByText('Admin Notifications'))
  })
})
