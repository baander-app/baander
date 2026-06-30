import styled from 'styled-components'
import { type AdminUser } from '../../api/user-admin-api'
import { useDeleteUser } from '../../hooks/use-users'
import { Button } from '@/shared/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'

const MonoText = styled.span`
  font-family: monospace;
  color: var(--color-foreground);
`

export function DeleteUserDialog({ user, open, onOpenChange }: { user: AdminUser | null; open: boolean; onOpenChange: (v: boolean) => void }) {
  const deleteUser = useDeleteUser()

  if (!user) return null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent style={{ maxWidth: '28rem' }}>
        <DialogHeader>
          <DialogTitle>Delete User</DialogTitle>
          <DialogDescription>
            Permanently delete <MonoText>{user.email}</MonoText>? This cannot be undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button
            variant="destructive"
            disabled={deleteUser.isPending}
            onClick={() => deleteUser.mutate(user.id, { onSuccess: () => onOpenChange(false) })}
          >
            {deleteUser.isPending ? 'Deleting...' : 'Delete'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
