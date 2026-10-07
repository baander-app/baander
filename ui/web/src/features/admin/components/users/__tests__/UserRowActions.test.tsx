import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeAll, describe, expect, it, vi } from 'vitest'
import { UserRowActions } from '../UserRowActions'
import { type AdminUser } from '../../../api/user-admin-api'

const ALICE = {
  id: '0199bf3c-8a00-7000-8000-00000000a11c',
  email: 'alice@baander.app',
  name: 'Alice',
  roles: ['ROLE_USER'],
  disabled: false,
  createdAt: '2026-10-07T12:00:00+00:00',
} as AdminUser

function renderActions(canManage: boolean) {
  const handlers = {
    onEdit: vi.fn(),
    onAssignRoles: vi.fn(),
    onResetPassword: vi.fn(),
    onToggle: vi.fn(),
    onDelete: vi.fn(),
  }
  render(<UserRowActions user={ALICE} canManage={canManage} {...handlers} />)

  return handlers
}

describe('UserRowActions', () => {
  beforeAll(() => {
    // Radix menus use pointer capture and scrolling, which jsdom lacks.
    Element.prototype.hasPointerCapture = () => false
    Element.prototype.releasePointerCapture = () => {}
    Element.prototype.scrollIntoView = () => {}
  })

  it('offers a super admin every user action', async () => {
    const user = userEvent.setup()
    renderActions(true)

    await user.click(screen.getByRole('button', { name: 'Actions for alice@baander.app' }))

    expect(screen.getAllByRole('menuitem').map((item) => item.textContent?.trim())).toEqual([
      'Edit', 'Assign Roles', 'Reset Password', 'Disable', 'Delete',
    ])
  })

  it('offers an admin who is not a super admin only a view of the user', async () => {
    const user = userEvent.setup()
    const handlers = renderActions(false)

    await user.click(screen.getByRole('button', { name: 'Actions for alice@baander.app' }))
    expect(screen.getAllByRole('menuitem').map((item) => item.textContent?.trim())).toEqual(['View'])

    await user.click(screen.getByRole('menuitem', { name: 'View' }))
    expect(handlers.onEdit).toHaveBeenCalledOnce()
  })
})
