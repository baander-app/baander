import { type AdminUser } from '../../api/user-admin-api'
import { Button } from '@/shared/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/shared/components/ui/dropdown-menu'
import { MoreHorizontal, ShieldAlert, Trash2, KeyRound, UserCog } from 'lucide-react'

interface UserRowActionsProps {
  user: AdminUser
  onEdit: () => void
  onAssignRoles: () => void
  onResetPassword: () => void
  onToggle: () => void
  onDelete: () => void
}

export function UserRowActions({ user, onEdit, onAssignRoles, onResetPassword, onToggle, onDelete }: UserRowActionsProps) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon-xs">
          <MoreHorizontal size={14} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
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
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
