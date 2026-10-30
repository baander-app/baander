import { describe, expect, it } from 'vitest'
import { useNotificationStore } from '../notification-store'

describe('notification ownership reset', () => {
  it('clears notification contents, counts and the open popout', () => {
    useNotificationStore.setState({
      notifications: [{
        publicId: 'private-notification',
        category: 'security',
        eventType: 'account.updated',
        title: 'Account updated',
        body: null,
        parameters: null,
        isRead: false,
        createdAt: '2026-10-30T20:00:00Z',
      }],
      unreadCount: 4,
      isPopoutOpen: true,
    })

    useNotificationStore.getState().resetForPrincipal()

    expect(useNotificationStore.getState()).toMatchObject({
      notifications: [],
      unreadCount: 0,
      isPopoutOpen: false,
    })
  })
})
