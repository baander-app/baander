import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, screen } from '@testing-library/react'
import { render } from '../../../../../tests/test-utils'
import { PasskeyManagement } from '../PasskeyManagement'

vi.mock('../../hooks/use-passkey-registration', () => ({
  usePasskeyRegistration: () => ({ register: vi.fn(), loading: false, error: null, success: false }),
}))

vi.mock('../../hooks/use-passkey-list', () => ({
  usePasskeyList: () => ({
    isPending: false,
    data: [
      { publicId: 'passkey-yubikey', name: 'YubiKey', createdAt: '2026-10-01T09:00:00Z', lastUsedAt: null },
      { publicId: 'passkey-phone', name: 'ゆうきのスマホ', createdAt: '2026-10-02T09:00:00Z', lastUsedAt: null },
    ],
  }),
  useDeletePasskey: () => ({ isPending: false, mutate: vi.fn() }),
}))

describe('PasskeyManagement', () => {
  afterEach(() => {
    cleanup()
  })

  it('renders each passkey name as content text in its own language', () => {
    render(<PasskeyManagement />)

    expect(screen.getByText('YubiKey')).toHaveAttribute('lang', 'en')
    expect(screen.getByText('ゆうきのスマホ')).toHaveAttribute('lang', 'ja')
  })
})
