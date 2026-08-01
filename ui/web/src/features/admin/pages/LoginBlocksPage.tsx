import styled from 'styled-components'
import { useState } from 'react'
import { useLoginBlocks, useDeleteLoginBlock, useDeleteAllLoginBlocks } from '../hooks/use-login-blocks'
import { Button } from '@/shared/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/shared/components/ui/dropdown-menu'
import { MoreHorizontal, Trash2, ShieldAlert } from 'lucide-react'
import { AdminPageHeader } from '../components/layout/AdminPageHeader'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.5rem;
`

const TableWrapper = styled.div`
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
`

const StyledTable = styled.table`
  width: 100%;
  font-size: 0.8125rem;
`

const HeadRow = styled.tr`
  border-bottom: 1px solid var(--color-border);
  text-align: left;
  font-size: 0.6875rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const Th = styled.th`
  padding: 0.5rem 0.75rem;
`

const ThAction = styled.th`
  width: 2.5rem;
  padding: 0.5rem 0.75rem;
`

const BodyRow = styled.tr`
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 50%, transparent);

  &:last-child {
    border-bottom: none;
  }

  &:hover {
    background: color-mix(in srgb, var(--color-muted) 30%, transparent);
  }
`

const Td = styled.td`
  padding: 0.5rem 0.75rem;
`

const TdMono = styled.td`
  padding: 0.5rem 0.75rem;
  font-family: var(--font-mono);
  font-size: 0.75rem;
`

const TdWarn = styled.td`
  padding: 0.5rem 0.75rem;
  font-family: var(--font-mono);
  font-size: 0.75rem;
  color: #fbbf24;
`

const TdMutedSmall = styled.td`
  padding: 0.5rem 0.75rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const TdNowrap = styled.td`
  padding: 0.5rem 0.75rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
  white-space: nowrap;
`

const TdAction = styled.td`
  padding: 0.5rem 0.75rem;
`

const ActionButton = styled.button`
  border-radius: var(--radius-md);
  padding: 0.25rem;

  &:hover {
    background: var(--color-muted);
  }
`

const LoadingRow = styled.div`
  height: 2.5rem;
  border-radius: var(--radius-md);
  background: var(--color-muted);
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

const LoadingStack = styled.div`
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

const Pagination = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 0.8125rem;
  color: var(--color-muted-foreground);
`

const PaginationButtons = styled.div`
  display: flex;
  gap: 0.5rem;
`

function relativeTime(dateStr: string): string {
  const diff = Date.now() - new Date(dateStr).getTime()
  const minutes = Math.floor(diff / 60000)
  if (minutes < 1) return 'just now'
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  return `${days}d ago`
}

function truncate(str: string, max: number): string {
  return str.length > max ? str.slice(0, max) + '…' : str
}

export function LoginBlocksPage() {
  const [page, setPage] = useState(0)
  const limit = 25

  const { data, isLoading } = useLoginBlocks({ limit, offset: page * limit })
  const deleteBlock = useDeleteLoginBlock()
  const deleteAll = useDeleteAllLoginBlocks()

  const [showClearAll, setShowClearAll] = useState(false)

  const blocks = data?.data ?? []
  const total = data?.meta?.total ?? 0

  return (
    <Container>
      <AdminPageHeader
        title="Login Blocks"
        subtitle={`Bots caught by the login honeypot. ${total} blocked.`}
        icon={ShieldAlert}
        action={
          total > 0 ? (
            <Button variant="outline" size="sm" onClick={() => setShowClearAll(true)}>
              <Trash2 size={14} style={{ marginRight: '0.375rem' }} />
              Clear All
            </Button>
          ) : undefined
        }
      />

      {/* Table */}
      {isLoading ? (
        <LoadingStack>
          {Array.from({ length: 5 }).map((_, i) => (
            <LoadingRow key={i} />
          ))}
        </LoadingStack>
      ) : blocks.length === 0 ? (
        <EmptyState>No blocked attempts recorded.</EmptyState>
      ) : (
        <TableWrapper>
          <StyledTable>
            <thead>
              <HeadRow>
                <Th>IP</Th>
                <Th>Email</Th>
                <Th>Field Value</Th>
                <Th>User Agent</Th>
                <Th>When</Th>
                <ThAction />
              </HeadRow>
            </thead>
            <tbody>
              {blocks.map((block) => (
                <BodyRow key={block.id}>
                  <TdMono>{block.ipAddress}</TdMono>
                  <Td>{block.email || '—'}</Td>
                  <TdWarn>{truncate(block.fieldValue, 30)}</TdWarn>
                  <TdMutedSmall title={block.userAgent}>
                    {truncate(block.userAgent, 40)}
                  </TdMutedSmall>
                  <TdNowrap>{relativeTime(block.createdAt)}</TdNowrap>
                  <TdAction>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <ActionButton type="button">
                          <MoreHorizontal size={14} />
                        </ActionButton>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem
                          style={{ color: 'var(--color-destructive)' }}
                          onClick={() => {
                            deleteBlock.mutate(block.id)
                          }}
                        >
                          <Trash2 size={13} style={{ marginRight: '0.375rem' }} />
                          Delete
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TdAction>
                </BodyRow>
              ))}
            </tbody>
          </StyledTable>
        </TableWrapper>
      )}

      {/* Pagination */}
      {total > limit && (
        <Pagination>
          <span>
            {page * limit + 1}–{Math.min((page + 1) * limit, total)} of {total}
          </span>
          <PaginationButtons>
            <Button variant="outline" size="sm" disabled={page === 0} onClick={() => setPage((p) => p - 1)}>
              Previous
            </Button>
            <Button variant="outline" size="sm" disabled={(page + 1) * limit >= total} onClick={() => setPage((p) => p + 1)}>
              Next
            </Button>
          </PaginationButtons>
        </Pagination>
      )}

      {/* Clear All Dialog */}
      <Dialog open={showClearAll} onOpenChange={setShowClearAll}>
        <DialogContent style={{ maxWidth: '24rem' }}>
          <DialogHeader>
            <DialogTitle>Clear All Blocks</DialogTitle>
            <DialogDescription>
              Permanently delete all {total} blocked login attempts? This cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setShowClearAll(false)}>
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              disabled={deleteAll.isPending}
              onClick={() => {
                deleteAll.mutate(undefined, { onSuccess: () => setShowClearAll(false) })
              }}
            >
              {deleteAll.isPending ? 'Deleting...' : 'Delete All'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Container>
  )
}
