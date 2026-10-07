import styled from 'styled-components'
import { useState } from 'react'
import { isAxiosError } from 'axios'
import { useUsers, useToggleUser } from '../hooks/use-users'
import { type AdminUser, type AdminUserListParams } from '../api/user-admin-api'
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check'
import { useGetAdminSettingsIndex } from '@/shared/api-client/gen/endpoints'
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

const PAGE_SIZE = 50
const CAN_CREATE_USERS = 'admin.can_create_users'

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
  gap: 1rem;
`

const PaginationRow = styled.nav`
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 1rem;
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
  const [offset, setOffset] = useState(0)
  const [showCreate, setShowCreate] = useState(false)
  const [activeDialog, setActiveDialog] = useState<ActiveDialog>(null)

  const toggleUser = useToggleUser()
  const { isSuperAdmin } = useAdminCheck()
  // Super admins may always create users; other admins only while the server setting allows it.
  const { data: settings } = useGetAdminSettingsIndex({ query: { enabled: !isSuperAdmin } })
  const canCreateUsers = isSuperAdmin
    || settings?.data?.find((setting) => setting.key === CAN_CREATE_USERS)?.value === true

  const params: AdminUserListParams = { limit: PAGE_SIZE, offset }
  if (roleFilter) params.role = roleFilter
  if (statusFilter === 'active') params.disabled = false
  if (statusFilter === 'disabled') params.disabled = true

  const { data, error, isLoading, isFetching, isError, refetch } = useUsers(params)
  // admin.can_view_users is enforced by the list endpoint, so its 403 is the source of truth.
  const listDenied = isAxiosError(error) && error.response?.status === 403
  const users = isError ? [] : data?.data ?? []
  const meta = isError ? undefined : data?.meta
  const total = meta?.total
  const limit = meta?.limit ?? PAGE_SIZE

  // Mutations can remove the last result on a page or shrink the filtered list.
  if (total !== undefined && offset > 0 && offset >= total) {
    setOffset(Math.max(0, Math.floor((total - 1) / limit) * limit))
  }
  const activeUser = activeDialog?.user ?? null

  return (
    <Container>
      {canCreateUsers && (
        <HeaderRow>
          <Button size="sm" onClick={() => setShowCreate(true)}>
            <Plus size={14} /> Create User
          </Button>
        </HeaderRow>
      )}

      {listDenied ? (
        <EmptyState>
          Admins cannot view the user list on this server. A super admin can allow it in Settings.
        </EmptyState>
      ) : (
        <>
          {/* Filters */}
          <FilterRow>
            <Select value={roleFilter || '_all'} onValueChange={(v) => { setRoleFilter(v === '_all' ? '' : v); setOffset(0) }}>
              <SelectTrigger aria-label="Filter by role" style={{ height: '1.75rem', width: '8rem', fontSize: '0.8125rem' }}>
                <SelectValue placeholder="All Roles" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="_all">All Roles</SelectItem>
                <SelectItem value="ROLE_USER">User</SelectItem>
                <SelectItem value="ROLE_ADMIN">Admin</SelectItem>
                <SelectItem value="ROLE_SUPER_ADMIN">Super Admin</SelectItem>
              </SelectContent>
            </Select>
            <Select value={statusFilter || '_all'} onValueChange={(v) => { setStatusFilter(v === '_all' ? '' : v); setOffset(0) }}>
              <SelectTrigger aria-label="Filter by status" style={{ height: '1.75rem', width: '8rem', fontSize: '0.8125rem' }}>
                <SelectValue placeholder="All Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="_all">All Status</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="disabled">Disabled</SelectItem>
              </SelectContent>
            </Select>
            <UserCount>
              {meta ? `${meta.total} user${meta.total !== 1 ? 's' : ''}` : ''}
            </UserCount>
          </FilterRow>

          {/* Table */}
          {isError ? (
            <EmptyState role="alert">
              <p>Unable to load users.</p>
              <Button size="sm" onClick={() => refetch()} disabled={isFetching}>Retry</Button>
            </EmptyState>
          ) : isLoading ? (
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

          <PaginationRow aria-label="Users pagination">
            <UserCount aria-live="polite">
              {meta && users.length > 0 ? `${meta.offset + 1}–${meta.offset + users.length} of ${meta.total}` : ''}
            </UserCount>
            <Button size="sm" variant="outline" disabled={isFetching || offset === 0}
              onClick={() => setOffset(Math.max(0, offset - limit))}>Previous</Button>
            <Button size="sm" variant="outline"
              disabled={isFetching || !meta || meta.offset + meta.limit >= meta.total}
              onClick={() => { if (meta) setOffset(meta.offset + meta.limit) }}>Next</Button>
          </PaginationRow>
        </>
      )}

      {/* Dialogs */}
      <CreateUserDialog open={showCreate} onOpenChange={setShowCreate} canAssignRoles={isSuperAdmin} />
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
