import styled from 'styled-components'
import { useState } from 'react'
import { useUsers, useToggleUser } from '../hooks/use-users'
import { type AdminUser } from '../api/user-admin-api'
import { Button } from '@/shared/components/ui/button'
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/shared/components/ui/select'
import { Plus } from 'lucide-react'
import { StatusDot } from '@/shared/components/status-dot'
import { RoleBadge } from '../components/users/RoleBadge'
import { UserRowActions } from '../components/users/UserRowActions'
import { CreateUserDialog } from '../components/users/CreateUserDialog'
import { EditUserDialog } from '../components/users/EditUserDialog'
import { AssignRolesDialog } from '../components/users/AssignRolesDialog'
import { ResetPasswordDialog } from '../components/users/ResetPasswordDialog'
import { DeleteUserDialog } from '../components/users/DeleteUserDialog'

type ActiveDialog =
  | { type: 'edit'; user: AdminUser }
  | { type: 'roles'; user: AdminUser }
  | { type: 'password'; user: AdminUser }
  | { type: 'delete'; user: AdminUser }
  | null

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.5rem;
`

const HeaderRow = styled.div`
  display: flex;
  justify-content: flex-end;
`

const FilterRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const UserCount = styled.span`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
  margin-left: auto;
`

const DividerStack = styled.div`
  & > div + div {
    border-top: 1px solid var(--color-border);
  }
`

const ColumnHeader = styled.div`
  display: grid;
  grid-template-columns: 1fr 1fr 200px 100px 140px 40px;
  gap: 0.75rem;
  padding: 0 0.5rem;
  padding-top: 0.375rem;
  padding-bottom: 0.375rem;
`

const ColLabel = styled.span`
  font-size: 0.6875rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const UserRow = styled.div`
  display: grid;
  grid-template-columns: 1fr 1fr 200px 100px 140px 40px;
  align-items: center;
  gap: 0.75rem;
  padding: 0 0.5rem;
  padding-top: 0.5rem;
  padding-bottom: 0.5rem;
  font-size: 0.8125rem;
  transition: background-color 100ms ease-out;

  &:hover {
    background: color-mix(in srgb, var(--color-highlight) 20%, transparent);
  }
`

const EmailCell = styled.span`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-family: var(--font-mono);
  font-size: 0.8125rem;
`

const NameCell = styled.span`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const RolesCell = styled.div`
  display: flex;
  gap: 0.25rem;
  flex-wrap: wrap;
`

const DateCell = styled.span`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
  font-family: var(--font-mono);
`

const SkeletonRow = styled.div`
  height: 2.5rem;
  border-radius: var(--radius-md);
  background: var(--color-muted);
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

const SkeletonStack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const EmptyState = styled.div`
  padding: 3rem 0;
  text-align: center;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

export function AdminUsersPage() {
  const [roleFilter, setRoleFilter] = useState<string>('')
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [showCreate, setShowCreate] = useState(false)
  const [activeDialog, setActiveDialog] = useState<ActiveDialog>(null)

  const toggleUser = useToggleUser()

  const params: { role?: string; disabled?: boolean } = {}
  if (roleFilter) params.role = roleFilter
  if (statusFilter === 'active') params.disabled = false
  if (statusFilter === 'disabled') params.disabled = true

  const { data, isLoading } = useUsers(Object.keys(params).length > 0 ? params : undefined)
  const users = data?.data ?? []
  const activeUser = activeDialog?.user ?? null

  return (
    <Container>
      <HeaderRow>
        <Button size="sm" onClick={() => setShowCreate(true)}>
          <Plus size={14} /> Create User
        </Button>
      </HeaderRow>

      {/* Filters */}
      <FilterRow>
        <Select value={roleFilter || '_all'} onValueChange={(v) => setRoleFilter(v === '_all' ? '' : v)}>
          <SelectTrigger style={{ height: '1.75rem', width: '8rem', fontSize: '0.8125rem' }}>
            <SelectValue placeholder="All Roles" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="_all">All Roles</SelectItem>
            <SelectItem value="ROLE_USER">User</SelectItem>
            <SelectItem value="ROLE_ADMIN">Admin</SelectItem>
            <SelectItem value="ROLE_SUPER_ADMIN">Super Admin</SelectItem>
          </SelectContent>
        </Select>
        <Select value={statusFilter || '_all'} onValueChange={(v) => setStatusFilter(v === '_all' ? '' : v)}>
          <SelectTrigger style={{ height: '1.75rem', width: '8rem', fontSize: '0.8125rem' }}>
            <SelectValue placeholder="All Status" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="_all">All Status</SelectItem>
            <SelectItem value="active">Active</SelectItem>
            <SelectItem value="disabled">Disabled</SelectItem>
          </SelectContent>
        </Select>
        <UserCount>
          {users.length} user{users.length !== 1 ? 's' : ''}
        </UserCount>
      </FilterRow>

      {/* Table */}
      {isLoading ? (
        <SkeletonStack>
          {Array.from({ length: 5 }).map((_, i) => (
            <SkeletonRow key={i} />
          ))}
        </SkeletonStack>
      ) : users.length === 0 ? (
        <EmptyState>No users found.</EmptyState>
      ) : (
        <DividerStack>
          <ColumnHeader>
            <ColLabel>Email</ColLabel>
            <ColLabel>Name</ColLabel>
            <ColLabel>Roles</ColLabel>
            <ColLabel>Status</ColLabel>
            <ColLabel>Created</ColLabel>
            <span />
          </ColumnHeader>
          {users.map((user) => (
            <UserRow key={user.id}>
              <EmailCell>{user.email}</EmailCell>
              <NameCell>{user.name}</NameCell>
              <RolesCell>
                {user.roles.map((role) => (
                  <RoleBadge key={role} role={role} />
                ))}
              </RolesCell>
              <StatusDot color={user.disabled ? 'red' : 'green'} label={user.disabled ? 'Disabled' : 'Active'} />
              <DateCell>
                {new Date(user.createdAt).toLocaleDateString()}
              </DateCell>
              <UserRowActions
                user={user}
                onEdit={() => setActiveDialog({ type: 'edit', user })}
                onAssignRoles={() => setActiveDialog({ type: 'roles', user })}
                onResetPassword={() => setActiveDialog({ type: 'password', user })}
                onToggle={() => toggleUser.mutate({ id: user.id, disabled: user.disabled })}
                onDelete={() => setActiveDialog({ type: 'delete', user })}
              />
            </UserRow>
          ))}
        </DividerStack>
      )}

      {/* Dialogs */}
      <CreateUserDialog open={showCreate} onOpenChange={setShowCreate} />
      <EditUserDialog
        user={activeUser}
        open={activeDialog?.type === 'edit'}
        onOpenChange={(v) => { if (!v) setActiveDialog(null) }}
      />
      <AssignRolesDialog
        user={activeUser}
        open={activeDialog?.type === 'roles'}
        onOpenChange={(v) => { if (!v) setActiveDialog(null) }}
      />
      <ResetPasswordDialog
        user={activeUser}
        open={activeDialog?.type === 'password'}
        onOpenChange={(v) => { if (!v) setActiveDialog(null) }}
      />
      <DeleteUserDialog
        user={activeUser}
        open={activeDialog?.type === 'delete'}
        onOpenChange={(v) => { if (!v) setActiveDialog(null) }}
      />
    </Container>
  )
}
