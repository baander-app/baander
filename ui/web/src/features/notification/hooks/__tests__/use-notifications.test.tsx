import { act, cleanup, renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { PropsWithChildren } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { notificationApi, type NotificationItem } from '../../api/notification-api'
import { useNotificationStore } from '../../stores/notification-store'
import { useNotifications } from '../use-notifications'
import { useNotificationActions } from '../use-notification-actions'

vi.mock('../../api/notification-api', () => ({ notificationApi: {
  list: vi.fn(), unreadCount: vi.fn(), markRead: vi.fn(), markAllRead: vi.fn(),
} }))

const item = (publicId: string, isRead = false): NotificationItem => ({
  publicId, isRead, category: 'security', eventType: 'security.alert', title: publicId,
  body: null, parameters: null, createdAt: '2026-10-04T10:00:00Z',
})
function setup() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const wrapper = ({ children }: PropsWithChildren) => <QueryClientProvider client={client}>{children}</QueryClientProvider>
  return { client, ...renderHook(() => ({ ...useNotifications(), ...useNotificationActions() }), { wrapper }) }
}

beforeEach(() => {
  vi.resetAllMocks()
  useNotificationStore.setState({ notifications: [], unreadCount: 0, isPopoutOpen: false })
  vi.mocked(notificationApi.unreadCount).mockResolvedValue(100)
})
afterEach(cleanup)

describe('notification cursor pagination', () => {
  it('forwards the cursor, appends unique items beyond fifty, and preserves the global count', async () => {
    const first = Array.from({ length: 20 }, (_, n) => item(`first-${n}`))
    const second = Array.from({ length: 20 }, (_, n) => item(`second-${n}`))
    const third = Array.from({ length: 20 }, (_, n) => item(`third-${n}`))
    vi.mocked(notificationApi.list)
      .mockResolvedValueOnce({ data: first, nextCursor: 'opaque-first' })
      .mockResolvedValueOnce({ data: [first[0], ...second], nextCursor: 'opaque-second' })
      .mockResolvedValueOnce({ data: third, nextCursor: null })
    const { result } = setup()
    await waitFor(() => expect(result.current.notifications).toHaveLength(20))
    act(() => result.current.loadMore())
    await waitFor(() => expect(result.current.notifications).toHaveLength(40))
    expect(notificationApi.list).toHaveBeenNthCalledWith(2, { limit: 20, cursor: 'opaque-first' }, expect.any(AbortSignal))
    act(() => result.current.loadMore())
    await waitFor(() => expect(result.current.notifications).toHaveLength(60))
    expect(result.current.unreadCount).toBe(100)
    expect(notificationApi.unreadCount).toHaveBeenCalledTimes(1)
    expect(result.current.hasNextPage).toBe(false)
    act(() => result.current.loadMore())
    expect(notificationApi.list).toHaveBeenCalledTimes(3)
  })

  it('cancels the request when its final observer unmounts', async () => {
    vi.mocked(notificationApi.list).mockImplementation(() => new Promise(() => {}))
    const { unmount } = setup()
    await waitFor(() => expect(notificationApi.list).toHaveBeenCalledTimes(1))
    const signal = vi.mocked(notificationApi.list).mock.calls[0][1]!
    expect(signal.aborted).toBe(false)
    unmount()
    expect(signal.aborted).toBe(true)
  })

  it('cancels a page started while mark all is pending before applying its read state', async () => {
    let allRead = false
    let finishMutation: () => void = () => {}
    vi.mocked(notificationApi.list).mockImplementation(async (params) => params?.cursor
      ? new Promise(() => {})
      : { data: [item('first', allRead)], nextCursor: 'next' })
    vi.mocked(notificationApi.unreadCount).mockImplementation(async () => allRead ? 0 : 100)
    vi.mocked(notificationApi.markAllRead).mockImplementation(() => new Promise((resolve) => {
      finishMutation = () => { allRead = true; resolve({} as Awaited<ReturnType<typeof notificationApi.markAllRead>>) }
    }))
    const { result } = setup()
    await waitFor(() => expect(result.current.notifications).toHaveLength(1))
    act(() => result.current.markAllRead.mutate())
    await waitFor(() => expect(notificationApi.markAllRead).toHaveBeenCalledTimes(1))
    act(() => result.current.loadMore())
    await waitFor(() => expect(notificationApi.list).toHaveBeenCalledTimes(2))
    const pageSignal = vi.mocked(notificationApi.list).mock.calls[1][1]!
    act(() => finishMutation())
    await waitFor(() => expect(result.current.markAllRead.isPending).toBe(false))
    expect(pageSignal.aborted).toBe(true)
    expect(result.current.notifications[0].isRead).toBe(true)
    expect(result.current.unreadCount).toBe(0)
  })

  it('keeps unread items and the count when a read mutation fails', async () => {
    vi.mocked(notificationApi.list).mockResolvedValue({ data: [item('first')], nextCursor: null })
    vi.mocked(notificationApi.markRead).mockRejectedValue(new Error('temporary'))
    const { result } = setup()
    await waitFor(() => expect(result.current.unreadCount).toBe(100))
    await act(async () => { await expect(result.current.markRead.mutateAsync('first')).rejects.toThrow('temporary') })
    expect(result.current.notifications[0].isRead).toBe(false)
    expect(result.current.unreadCount).toBe(100)
  })

  it('ends an empty page without another request', async () => {
    vi.mocked(notificationApi.list).mockResolvedValue({ data: [], nextCursor: null })
    const { result } = setup()
    await waitFor(() => expect(result.current.isLoading).toBe(false))
    expect(result.current.hasNextPage).toBe(false)
    act(() => result.current.loadMore())
    expect(notificationApi.list).toHaveBeenCalledTimes(1)
  })

  it('retries a failed next page without discarding loaded items', async () => {
    vi.mocked(notificationApi.list)
      .mockResolvedValueOnce({ data: [item('first')], nextCursor: 'retry-cursor' })
      .mockRejectedValueOnce(new Error('temporary'))
      .mockResolvedValueOnce({ data: [item('second')], nextCursor: null })
    const { result } = setup()
    await waitFor(() => expect(result.current.notifications).toHaveLength(1))
    act(() => result.current.loadMore())
    await waitFor(() => expect(result.current.isFetchNextPageError).toBe(true))
    expect(result.current.notifications.map((n) => n.publicId)).toEqual(['first'])
    act(() => result.current.loadMore())
    await waitFor(() => expect(result.current.notifications).toHaveLength(2))
    expect(notificationApi.list).toHaveBeenLastCalledWith({ limit: 20, cursor: 'retry-cursor' }, expect.any(AbortSignal))
  })

  it('keeps successful read mutations in earlier pages when loading later pages', async () => {
    let read = false
    let allRead = false
    vi.mocked(notificationApi.unreadCount).mockImplementation(async () => allRead ? 0 : read ? 99 : 100)
    vi.mocked(notificationApi.list).mockImplementation(async (params) => params?.cursor
      ? { data: [item('first'), item('second', allRead)], nextCursor: null }
      : { data: [item('first', read || allRead)], nextCursor: 'next' })
    vi.mocked(notificationApi.markRead).mockImplementation(async () => { read = true; return {} as Awaited<ReturnType<typeof notificationApi.markRead>> })
    vi.mocked(notificationApi.markAllRead).mockImplementation(async () => { allRead = true; return {} as Awaited<ReturnType<typeof notificationApi.markAllRead>> })
    const { result } = setup()
    await waitFor(() => expect(result.current.notifications).toHaveLength(1))
    await act(async () => { await result.current.markRead.mutateAsync('first') })
    act(() => result.current.loadMore())
    await waitFor(() => expect(result.current.notifications).toHaveLength(2))
    expect(result.current.notifications[0].isRead).toBe(true)
    expect(result.current.unreadCount).toBe(99)
    await act(async () => { await result.current.markAllRead.mutateAsync() })
    expect(result.current.notifications.every((n) => n.isRead)).toBe(true)
    expect(result.current.unreadCount).toBe(0)
  })
})
