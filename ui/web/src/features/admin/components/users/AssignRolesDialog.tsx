import { useAdminCheck } from '@/features/auth/hooks/use-admin-check'
import { useDraft } from '@/shared/drafts/use-draft'
import { guardMutationCallbacks, subscribeOwnership } from '@/shared/live/ownership'
import { useEffect, useRef } from 'react'
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
  color: var(--color-warning);
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const HintText = styled.span`
  font-size: 11px;
  color: var(--color-muted-foreground);
`

export function AssignRolesDialog({ user, open, onOpenChange }: { user: AdminUser | null; open: boolean; onOpenChange: (v: boolean) => void }) {
  const { isSuperAdmin } = useAdminCheck()
  const draft = useDraft({ identity: user?.publicId ?? '', initialData: { roles: user?.roles ?? [] } })
  const { receive, pending } = draft
  const latestRoles = useRef(draft.values.roles)
  useEffect(() => {
    latestRoles.current = draft.values.roles
  }, [draft.values.roles])
  const lastUser = useRef(user)
  const revokedUser = useRef<AdminUser | null>(null)
  const { revokeAccess } = draft
  useEffect(() => subscribeOwnership(() => {
    revokedUser.current = lastUser.current
    revokeAccess()
  }), [revokeAccess])
  useEffect(() => {
    lastUser.current = user
    if (!isSuperAdmin) {
      revokedUser.current = user
      revokeAccess()
    } else if (user && revokedUser.current !== user) {
      const confirmsPending = pending && user.roles.length === pending.values.roles.length
        && user.roles.every(role => pending.values.roles.includes(role))
      if (!confirmsPending) receive({ roles: user.roles })
    }
  }, [receive, revokeAccess, user, isSuperAdmin, pending])
  const roles = draft.values.roles ?? []
  const assignRoles = useAssignRoles()

  const handleOpen = (val: boolean) => {
    onOpenChange(val)
  }

  if (!user) return null

  const toggleRole = (role: string) => {
    draft.updateField('roles', roles.includes(role) ? roles.filter(r => r !== role) : [...roles, role])
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!isSuperAdmin || !draft.canSave) return
    const finalRoles = roles.includes('ROLE_SUPER_ADMIN')
      ? ALL_ROLES
      : roles.includes('ROLE_ADMIN')
        ? ['ROLE_USER', 'ROLE_ADMIN'].filter((r) => roles.includes(r))
        : ['ROLE_USER']
    draft.updateField('roles', finalRoles)
    const submission = draft.beginSave()
    if (!submission) return
    assignRoles.mutate(
      { id: user.publicId, roles: finalRoles },
      guardMutationCallbacks({
        onSuccess: () => {
          draft.completeSave(submission)
          const latest = latestRoles.current
          if (latest && latest.length === submission.values.roles.length
            && latest.every(role => submission.values.roles.includes(role))) {
            onOpenChange(false)
          }
        },
        onError: () => draft.failSave(submission),
      }),
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
                    disabled={isUser || !isSuperAdmin || draft.status === 'unavailable'}
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
          {draft.status === 'conflict' && <div role="alert">
            Roles changed on the server.
            <Button type="button" onClick={() => draft.resolveConflict('local')}>Keep my changes</Button>
            <Button type="button" onClick={() => draft.resolveConflict('server')}>Use server roles</Button>
          </div>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button type="submit" disabled={assignRoles.isPending || !draft.canSave}>
              {assignRoles.isPending ? 'Saving...' : 'Save Roles'}
            </Button>
          </DialogFooter>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
