import { type AdminUser } from '../../api/user-admin-api'
import { Button } from '@/shared/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/shared/components/ui/dropdown-menu'
import { Eye, MoreHorizontal, ShieldAlert, Trash2, KeyRound, UserCog } from 'lucide-react'

interface UserRowActionsProps {
  user: AdminUser
  /** Only super admins may change users; other admins can only view them. */
  canManage: boolean
  onEdit: () => void
  onAssignRoles: () => void
  onResetPassword: () => void
  onToggle: () => void
  onDelete: () => void
}

export function UserRowActions({ user, canManage, onEdit, onAssignRoles, onResetPassword, onToggle, onDelete }: UserRowActionsProps) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon-xs" aria-label={`Actions for ${user.email}`}>
          <MoreHorizontal size={14} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {canManage ? (
          <>
            <DropdownMenuItem onSelect={onEdit}>
              <UserCog size={14} /> Edit
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={onAssignRoles}>
              <ShieldAlert size={14} /> Assign Roles
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={onResetPassword}>
              <KeyRound size={14} /> Reset Password
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={onToggle}>
              {user.disabled ? 'Enable' : 'Disable'}
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem variant="destructive" onSelect={onDelete}>
              <Trash2 size={14} /> Delete
            </DropdownMenuItem>
          </>
        ) : (
          <DropdownMenuItem onSelect={onEdit}>
            <Eye size={14} /> View
          </DropdownMenuItem>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
