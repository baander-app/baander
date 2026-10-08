import { useState } from 'react'
import styled from 'styled-components'
import { Plus } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { StatusDot } from '@/shared/components/status-dot'
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check'
import { type AdminOAuthClientResource } from '@/shared/api-client/gen/endpoints'
import { useOAuthClients } from '../hooks/use-oauth-clients'
import { CLIENT_TYPE_LABELS } from '../components/oauth-clients/client-type-labels'
import { CreateOAuthClientDialog } from '../components/oauth-clients/CreateOAuthClientDialog'
import { RevokeOAuthClientDialog } from '../components/oauth-clients/RevokeOAuthClientDialog'
import { RotateSecretDialog } from '../components/oauth-clients/RotateSecretDialog'

type ActiveDialog =
  | { type: 'rotate'; client: AdminOAuthClientResource }
  | { type: 'revoke'; client: AdminOAuthClientResource }
  | null

const READ_ONLY_COLUMNS = 'minmax(0, 1fr) 110px 120px 100px'
const MANAGE_COLUMNS = `${READ_ONLY_COLUMNS} 200px`

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.5rem;
`

const HeaderRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
`

const Note = styled.p`
  font-size: 0.8125rem;
  color: var(--color-muted-foreground);
`

const DividerStack = styled.div`
  & > div + div {
    border-top: 1px solid var(--color-border);
  }
`

const Row = styled.div<{ $columns: string }>`
  display: grid;
  grid-template-columns: ${({ $columns }) => $columns};
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem;
  font-size: 0.8125rem;
`

const ColLabel = styled.span`
  font-size: 0.6875rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const NameCell = styled.div`
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 0.125rem;
`

const NameLine = styled.span`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const ClientId = styled.span`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-family: var(--font-mono);
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
`

const DateCell = styled.span`
  font-family: var(--font-mono);
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
`

const ActionsCell = styled.div`
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
`

const SkeletonStack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const SkeletonRow = styled(Skeleton)`
  height: 2.5rem;
  background: var(--color-muted);
`

const EmptyState = styled.div`
  padding: 3rem 0;
  text-align: center;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

/** The first-party client and revoked clients have no actions; only confidential ones rotate. */
function canRotate(client: AdminOAuthClientResource): boolean {
  return client.type === 'confidential' && !client.revoked
}

function canRevoke(client: AdminOAuthClientResource): boolean {
  return client.type !== 'first_party' && !client.revoked
}

/**
 * OAuth clients registered with the authorization server. Admins can see the list; only super
 * admins create clients, rotate secrets, and revoke clients, as the API enforces.
 */
export function OAuthClientsPage() {
  const { isSuperAdmin } = useAdminCheck()
  const [showCreate, setShowCreate] = useState(false)
  const [activeDialog, setActiveDialog] = useState<ActiveDialog>(null)
  const { data, isLoading, isFetching, isError, refetch } = useOAuthClients()
  const clients = isError ? [] : data ?? []
  const columns = isSuperAdmin ? MANAGE_COLUMNS : READ_ONLY_COLUMNS
  const closeDialog = (open: boolean) => {
    if (!open) setActiveDialog(null)
  }

  return (
    <Container>
      <HeaderRow>
        <Note>
          {isSuperAdmin
            ? 'Apps that sign users in through OAuth. A client secret is shown once, when it is created or rotated.'
            : 'Apps that sign users in through OAuth. Only super admins can create, rotate, or revoke clients.'}
        </Note>
        {isSuperAdmin && (
          <Button size="sm" onClick={() => setShowCreate(true)}>
            <Plus size={14} /> Create client
          </Button>
        )}
      </HeaderRow>

      {isError ? (
        <EmptyState role="alert">
          <p>Unable to load OAuth clients.</p>
          <Button size="sm" variant="ghost" onClick={() => refetch()} disabled={isFetching}>Retry</Button>
        </EmptyState>
      ) : isLoading ? (
        <SkeletonStack>
          {Array.from({ length: 3 }).map((_, i) => (
            <SkeletonRow key={i} />
          ))}
        </SkeletonStack>
      ) : clients.length === 0 ? (
        <EmptyState>No OAuth clients yet.</EmptyState>
      ) : (
        <DividerStack role="table" aria-label="OAuth clients">
          <Row $columns={columns} role="row">
            <ColLabel role="columnheader">Name</ColLabel>
            <ColLabel role="columnheader">Type</ColLabel>
            <ColLabel role="columnheader">Created</ColLabel>
            <ColLabel role="columnheader">Status</ColLabel>
            {isSuperAdmin && <span role="columnheader" aria-label="Actions" />}
          </Row>
          {clients.map((client) => (
            <Row key={client.clientId} $columns={columns} role="row" aria-label={client.name}>
              <NameCell role="cell">
                <NameLine>{client.name}</NameLine>
                <ClientId title="Client ID">{client.clientId}</ClientId>
              </NameCell>
              <span role="cell">{CLIENT_TYPE_LABELS[client.type]}</span>
              <DateCell role="cell">{new Date(client.createdAt).toLocaleDateString()}</DateCell>
              <span role="cell">
                <StatusDot color={client.revoked ? 'red' : 'green'} label={client.revoked ? 'Revoked' : 'Active'} />
              </span>
              {isSuperAdmin && (
                <ActionsCell role="cell">
                  {canRotate(client) && (
                    <Button size="xs" variant="outline" onClick={() => setActiveDialog({ type: 'rotate', client })}>
                      Rotate secret
                    </Button>
                  )}
                  {canRevoke(client) && (
                    <Button size="xs" variant="destructive" onClick={() => setActiveDialog({ type: 'revoke', client })}>
                      Revoke
                    </Button>
                  )}
                </ActionsCell>
              )}
            </Row>
          ))}
        </DividerStack>
      )}

      {isSuperAdmin && (
        <>
          <CreateOAuthClientDialog open={showCreate} onOpenChange={setShowCreate} />
          <RotateSecretDialog
            client={activeDialog?.type === 'rotate' ? activeDialog.client : null}
            open={activeDialog?.type === 'rotate'}
            onOpenChange={closeDialog}
          />
          <RevokeOAuthClientDialog
            client={activeDialog?.type === 'revoke' ? activeDialog.client : null}
            open={activeDialog?.type === 'revoke'}
            onOpenChange={closeDialog}
          />
        </>
      )}
    </Container>
  )
}
