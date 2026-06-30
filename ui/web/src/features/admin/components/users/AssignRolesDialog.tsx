import { useState } from 'react'
import styled from 'styled-components'
import { type AdminUser } from '../../api/user-admin-api'
import { useAssignRoles } from '../../hooks/use-users'
import { Button } from '@/shared/components/ui/button'
import { ShieldAlert } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'

const ALL_ROLES = ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN']

const Form = styled.form`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const RoleList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const RoleLabel = styled.label`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
`

const RoleName = styled.span`
  font-size: 0.875rem;
`

const WarningText = styled.span`
  font-size: 11px;
  color: #fbbf24;
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const HintText = styled.span`
  font-size: 11px;
  color: var(--color-muted-foreground);
`

export function AssignRolesDialog({ user, open, onOpenChange }: { user: AdminUser | null; open: boolean; onOpenChange: (v: boolean) => void }) {
  const [roles, setRoles] = useState<string[]>([])
  const assignRoles = useAssignRoles()

  const handleOpen = (val: boolean) => {
    if (val && user) {
      setRoles([...user.roles])
    }
    onOpenChange(val)
  }

  if (!user) return null

  const toggleRole = (role: string) => {
    setRoles((prev) =>
      prev.includes(role) ? prev.filter((r) => r !== role) : [...prev, role],
    )
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    const finalRoles = roles.includes('ROLE_SUPER_ADMIN')
      ? ALL_ROLES
      : roles.includes('ROLE_ADMIN')
        ? ['ROLE_USER', 'ROLE_ADMIN'].filter((r) => roles.includes(r))
        : ['ROLE_USER']
    assignRoles.mutate(
      { id: user.id, roles: finalRoles },
      { onSuccess: () => onOpenChange(false) },
    )
  }

  return (
    <Dialog open={open} onOpenChange={handleOpen}>
      <DialogContent style={{ maxWidth: '28rem' }}>
        <DialogHeader>
          <DialogTitle>Assign Roles</DialogTitle>
          <DialogDescription>Set roles for {user.email}.</DialogDescription>
        </DialogHeader>
        <Form onSubmit={handleSubmit}>
          <RoleList>
            {ALL_ROLES.map((role) => {
              const isUser = role === 'ROLE_USER'
              return (
                <RoleLabel key={role}>
                  <input
                    type="checkbox"
                    checked={roles.includes(role)}
                    onChange={() => toggleRole(role)}
                    disabled={isUser}
                    style={{ borderRadius: '0.25rem', borderColor: 'var(--color-border)' }}
                  />
                  <RoleName>{role.replace('ROLE_', '')}</RoleName>
                  {role === 'ROLE_SUPER_ADMIN' && roles.includes('ROLE_SUPER_ADMIN') && (
                    <WarningText>
                      <ShieldAlert size={12} /> Grants full system access
                    </WarningText>
                  )}
                  {isUser && <HintText>(always assigned)</HintText>}
                </RoleLabel>
              )
            })}
          </RoleList>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button type="submit" disabled={assignRoles.isPending}>
              {assignRoles.isPending ? 'Saving...' : 'Save Roles'}
            </Button>
          </DialogFooter>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
