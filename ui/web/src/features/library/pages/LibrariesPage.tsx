import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { useLibraries } from '../hooks/use-libraries'
import { useCreateLibrary, useUpdateLibrary, useDeleteLibrary, useScanLibrary, useScanAllLibraries } from '../hooks/use-library-mutations'
import { useScanPolling } from '../hooks/use-scan-polling'
import { LibraryCard } from '../components/LibraryCard'
import { CreateLibraryDialog, EditLibraryDialog, DeleteLibraryDialog, ScanLibraryDialog } from '../components/LibraryDialogs'
import type { Library } from '../api/library-api'

const PageWrapper = styled.div`
  display: flex;
  flex-direction: column;
  height: 100%;
`

const TopBar = styled.div`
  display: flex;
  align-items: center;
  justify-content: flex-end;
  padding: 1rem 1.5rem;
`

const TopBarActions = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const ContentArea = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 0 1.5rem 1.5rem;
`

const CardList = styled.div`
  max-width: 42rem;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const EmptyState = styled.div`
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 2rem;
  text-align: center;

  p:first-child {
    font-size: 0.875rem;
    color: var(--color-muted-foreground);
  }

  p:last-child {
    margin-top: 0.25rem;
    font-size: 0.75rem;
    color: var(--color-muted-foreground);
  }
`

const SkeletonWrapper = styled.div`
  padding: 1.5rem;
`

const SkeletonCard = styled.div`
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 1rem;

  & + & {
    margin-top: 0.75rem;
  }
`

const SkeletonInner = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const SkeletonLine = styled.div<{ $width: string }>`
  height: ${(p) => p.$width === '1/3' ? '1rem' : '0.75rem'};
  width: ${(p) => {
    switch (p.$width) {
      case '1/3': return '33.333%'
      case '2/3': return '66.666%'
      case '1/4': return '25%'
      default: return '100%'
    }
  }};
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  border-radius: var(--radius-sm);
  background-color: var(--color-muted);

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

const ErrorText = styled.p`
  margin-top: 0.5rem;
  font-size: 0.875rem;
  color: var(--color-destructive);
`

function Skeleton() {
  return (
    <SkeletonWrapper>
      {Array.from({ length: 3 }).map((_, i) => (
        <SkeletonCard key={i}>
          <SkeletonInner>
            <SkeletonLine $width="1/3" />
            <SkeletonLine $width="2/3" />
            <SkeletonLine $width="1/4" />
          </SkeletonInner>
        </SkeletonCard>
      ))}
    </SkeletonWrapper>
  )
}

export function LibrariesPage() {
  const navigate = useNavigate()
  const { data: libraries, isLoading, error, refetch } = useLibraries()
  const createMutation = useCreateLibrary()
  const updateMutation = useUpdateLibrary()
  const deleteMutation = useDeleteLibrary()
  const scanMutation = useScanLibrary()
  const scanAllMutation = useScanAllLibraries()

  const { scanningIds, startPolling, stopPolling } = useScanPolling()

  const [createOpen, setCreateOpen] = useState(false)
  const [editTarget, setEditTarget] = useState<Library | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<Library | null>(null)
  const [scanTarget, setScanTarget] = useState<Library | null>(null)

  // Start/stop polling based on whether any scan is active
  useEffect(() => {
    const hasActive = libraries?.some((l) => l.scanStatus === 'scanning') ?? false
    if (hasActive) {
      startPolling()
    } else {
      stopPolling()
    }
  }, [libraries, startPolling, stopPolling])

  // Refetch when polling detects status changes
  useEffect(() => {
    if (scanningIds.size === 0 && libraries?.some((l) => l.scanStatus === 'scanning')) {
      refetch()
    }
  }, [scanningIds, libraries, refetch])

  if (isLoading) {
    return <Skeleton />
  }

  if (error) {
    return (
      <SkeletonWrapper>
        <ErrorText>Failed to load libraries.</ErrorText>
      </SkeletonWrapper>
    )
  }

  const hasAnyLibrary = (libraries?.length ?? 0) > 0
  const anyScanning = libraries?.some((l) => l.scanStatus === 'scanning') ?? false

  return (
    <PageWrapper>
      <TopBar>
        <TopBarActions>
          {hasAnyLibrary && (
            <Button
              size="sm"
              variant="outline"
              onClick={() => {
                scanAllMutation.mutate(undefined, {
                  onSuccess: (result) => {
                    toast.success(`Scan dispatched for ${result.dispatched} libraries`)
                    if (result.skipped > 0) {
                      toast.info(`${result.skipped} libraries skipped (already scanning)`)
                    }
                  },
                  onError: () => toast.error('Failed to start scan'),
                })
              }}
              disabled={anyScanning}
            >
              {anyScanning ? 'Scanning...' : 'Scan All'}
            </Button>
          )}
          <Button size="sm" onClick={() => setCreateOpen(true)}>
            Add Library
          </Button>
        </TopBarActions>
      </TopBar>

      <ContentArea>
        <CardList>
          {hasAnyLibrary ? (
            libraries!.map((library) => (
              <LibraryCard
                key={library.id}
                library={library}
                isScanning={library.scanStatus === 'scanning'}
                onEdit={setEditTarget}
                onDelete={setDeleteTarget}
                onScan={setScanTarget}
                onViewDetail={(lib) => navigate(`/admin/library/${lib.id}`)}
              />
            ))
          ) : (
            <EmptyState>
              <p>No libraries configured.</p>
              <p>Add a library to start scanning your media collection.</p>
            </EmptyState>
          )}
        </CardList>
      </ContentArea>

      <CreateLibraryDialog
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onSubmit={(data) => {
          createMutation.mutate(data, {
            onSuccess: () => {
              setCreateOpen(false)
              toast.success('Library created')
            },
            onError: () => {
              toast.error('Failed to create library')
            },
          })
        }}
        isPending={createMutation.isPending}
      />

      <EditLibraryDialog
        library={editTarget}
        onClose={() => setEditTarget(null)}
        onSubmit={(data) => {
          if (!editTarget) return
          updateMutation.mutate(
            { id: editTarget.id, payload: data },
            {
              onSuccess: () => {
                setEditTarget(null)
                toast.success('Library updated')
              },
              onError: () => {
                toast.error('Failed to update library')
              },
            },
          )
        }}
        isPending={updateMutation.isPending}
      />

      <ScanLibraryDialog
        library={scanTarget}
        onClose={() => setScanTarget(null)}
        onConfirm={(rescan) => {
          if (!scanTarget) return
          scanMutation.mutate(
            { id: scanTarget.id, rescan },
            {
              onSuccess: () => {
                setScanTarget(null)
                toast.success(`Scan started for ${scanTarget.name}${rescan ? ' (rescan)' : ''}`)
              },
              onError: () => toast.error(`Failed to scan ${scanTarget.name}`),
            },
          )
        }}
        isPending={scanMutation.isPending}
      />

      <DeleteLibraryDialog
        library={deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={() => {
          if (!deleteTarget) return
          deleteMutation.mutate(deleteTarget.id, {
            onSuccess: () => {
              setDeleteTarget(null)
              toast.success('Library deleted')
            },
            onError: () => {
              toast.error('Failed to delete library')
            },
          })
        }}
        isPending={deleteMutation.isPending}
      />
    </PageWrapper>
  )
}
