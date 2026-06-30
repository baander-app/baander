import { useNavigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import styled, { css } from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { useLibrary } from '../hooks/use-library'
import { useLibraryStats } from '../hooks/use-library-stats'
import { useScanLibrary, useDeleteLibrary } from '../hooks/use-library-mutations'
import { LibraryStatsPanel } from '../components/LibraryStatsPanel'
import { DeleteLibraryDialog, ScanLibraryDialog } from '../components/LibraryDialogs'
import { useState, useEffect } from 'react'
import { formatRelativeTime } from '../utils/format'

const PageWrapper = styled.div`
  display: flex;
  flex-direction: column;
  height: 100%;
`

const TopBar = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.5rem;
`

const LeftSection = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const BackButton = styled.button`
  border: none;
  background: none;
  border-radius: var(--radius-md);
  padding: 0.25rem;
  color: var(--color-muted-foreground);
  cursor: pointer;
  transition: background-color 0.15s, color 0.15s;

  &:hover {
    background-color: var(--color-accent);
    color: var(--color-accent-foreground);
  }
`

const TitleArea = styled.div`
  h1 {
    font-size: 1.125rem;
    font-weight: 600;
    letter-spacing: -0.025em;
  }

  p {
    font-size: 0.75rem;
    color: var(--color-muted-foreground);
    font-family: monospace;
  }
`

const RightSection = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const StatusBadge = styled.span<{ $status?: string }>`
  border-radius: 9999px;
  padding: 0.125rem 0.5rem;
  font-size: 10px;
  font-weight: 500;

  ${(p) => {
    switch (p.$status) {
      case 'scanning':
        return css`background-color: rgba(59, 130, 246, 0.15); color: #3b82f6;`
      case 'completed':
        return css`background-color: rgba(16, 185, 129, 0.15); color: #10b981;`
      case 'failed':
        return css`background-color: rgba(239, 68, 68, 0.15); color: #ef4444;`
      default:
        return css`background-color: var(--color-muted); color: var(--color-muted-foreground);`
    }
  }}
`

const SpinIcon = styled.svg`
  margin-right: 0.25rem;
  display: inline;
  height: 0.75rem;
  width: 0.75rem;
  animation: spin 1s linear infinite;

  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }
`

const ContentArea = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 0 1.5rem 1.5rem;
`

const ContentInner = styled.div`
  max-width: 56rem;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
`

const SectionTitle = styled.h2`
  margin-bottom: 0.75rem;
  font-size: 0.875rem;
  font-weight: 600;
  letter-spacing: -0.025em;
  color: var(--color-muted-foreground);
  text-transform: uppercase;
`

const DetailGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.75rem;

  @media (min-width: 1024px) {
    grid-template-columns: repeat(3, 1fr);
  }
`

const DetailCard = styled.div`
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 0.625rem 0.75rem;

  p:first-child {
    font-size: 0.75rem;
    color: var(--color-muted-foreground);
  }

  p:last-child {
    font-size: 0.875rem;
  }
`

const MonoText = styled.p`
  font-family: monospace;
`

const CapitalizeText = styled.p`
  text-transform: capitalize;
`

const ErrorWrapper = styled.div`
  padding: 1.5rem;

  h1 {
    font-size: 1.125rem;
    font-weight: 600;
    letter-spacing: -0.025em;
  }

  p {
    margin-top: 0.5rem;
    font-size: 0.875rem;
    color: var(--color-muted-foreground);
  }
`

const SkeletonWrapper = styled.div`
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const SkeletonLine = styled.div<{ $w: string }>`
  height: ${(p) => p.$w === 'lg' ? '2rem' : '1rem'};
  width: ${(p) => p.$w === '1/3' ? '33.333%' : p.$w === '2/3' ? '66.666%' : '100%'};
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  border-radius: var(--radius-sm);
  background-color: var(--color-muted);

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

const SkeletonStatsGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.75rem;

  @media (min-width: 640px) {
    grid-template-columns: repeat(3, 1fr);
  }

  @media (min-width: 1024px) {
    grid-template-columns: repeat(6, 1fr);
  }
`

const SkeletonStatCard = styled.div`
  height: 4rem;
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-muted);
`

function Skeleton() {
  return (
    <SkeletonWrapper>
      <SkeletonLine $w="lg" />
      <SkeletonLine $w="2/3" />
      <SkeletonStatsGrid>
        {Array.from({ length: 6 }).map((_, i) => (
          <SkeletonStatCard key={i} />
        ))}
      </SkeletonStatsGrid>
    </SkeletonWrapper>
  )
}

export function LibraryDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { data: library, isLoading, error } = useLibrary(id ?? '')
  const { data: stats, isLoading: statsLoading } = useLibraryStats(id ?? '')
  const scanMutation = useScanLibrary()
  const deleteMutation = useDeleteLibrary()
  const [deleteTarget, setDeleteTarget] = useState<NonNullable<typeof library> | null>(null)
  const [scanDialogOpen, setScanDialogOpen] = useState(false)

  // Poll stats while scanning
  useEffect(() => {
    if (library?.scanStatus !== 'scanning') return
    const timer = setInterval(() => {
      // React Query refetch via invalidation would be cleaner but this is simpler
    }, 5000)
    return () => clearInterval(timer)
  }, [library?.scanStatus])

  if (isLoading) return <Skeleton />

  if (error || !library) {
    return (
      <ErrorWrapper>
        <h1>Library not found</h1>
        <p>The library you&apos;re looking for doesn&apos;t exist.</p>
        <Button variant="outline" style={{ marginTop: '1rem' }} onClick={() => navigate('/admin/library')}>
          Back to Libraries
        </Button>
      </ErrorWrapper>
    )
  }

  return (
    <PageWrapper>
      <TopBar>
        <LeftSection>
          <BackButton
            type="button"
            onClick={() => navigate('/admin/library')}
            aria-label="Back to libraries"
          >
            <svg width={20} height={20} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
              <path d="m15 18-6-6 6-6" />
            </svg>
          </BackButton>
          <TitleArea>
            <h1>{library.name}</h1>
            <p>{library.path}</p>
          </TitleArea>
          {library.scanStatus && (
            <StatusBadge $status={library.scanStatus}>
              {library.scanStatus === 'scanning' && (
                <SpinIcon viewBox="0 0 24 24" fill="none">
                  <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="3" style={{ opacity: 0.25 }} />
                  <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
                </SpinIcon>
              )}
              {library.scanStatus}
            </StatusBadge>
          )}
        </LeftSection>
        <RightSection>
          <Button
            size="sm"
            onClick={() => setScanDialogOpen(true)}
            disabled={library.scanStatus === 'scanning'}
          >
            {library.scanStatus === 'scanning' ? 'Scanning...' : 'Scan Now'}
          </Button>
          <Button size="sm" variant="outline" onClick={() => setDeleteTarget(library)}>
            Delete
          </Button>
        </RightSection>
      </TopBar>

      <ContentArea>
        <ContentInner>
          {/* Stats */}
          <section>
            <SectionTitle>Statistics</SectionTitle>
            <LibraryStatsPanel stats={stats} isLoading={statsLoading} />
          </section>

          {/* Info Grid */}
          <section>
            <SectionTitle>Details</SectionTitle>
            <DetailGrid>
              <DetailCard>
                <p>Slug</p>
                <MonoText>/{library.slug}</MonoText>
              </DetailCard>
              <DetailCard>
                <p>Type</p>
                <CapitalizeText>{library.type.replace('_', ' ')}</CapitalizeText>
              </DetailCard>
              <DetailCard>
                <p>Sort Order</p>
                <p>{library.sortOrder}</p>
              </DetailCard>
              <DetailCard>
                <p>Last Scan</p>
                <p>{formatRelativeTime(library.lastScan)}</p>
              </DetailCard>
              <DetailCard>
                <p>Created</p>
                <p>{new Date(library.createdAt).toLocaleDateString()}</p>
              </DetailCard>
              <DetailCard>
                <p>Updated</p>
                <p>{new Date(library.updatedAt).toLocaleDateString()}</p>
              </DetailCard>
            </DetailGrid>
          </section>
        </ContentInner>
      </ContentArea>

      <ScanLibraryDialog
        library={scanDialogOpen ? library : null}
        onClose={() => setScanDialogOpen(false)}
        onConfirm={(rescan) => {
          scanMutation.mutate(
            { id: library.id, rescan },
            {
              onSuccess: () => {
                setScanDialogOpen(false)
                toast.success(`Scan started${rescan ? ' (rescan)' : ''}`)
              },
              onError: () => toast.error('Failed to start scan'),
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
              navigate('/admin/libraries')
            },
            onError: () => toast.error('Failed to delete library'),
          })
        }}
        isPending={deleteMutation.isPending}
      />
    </PageWrapper>
  )
}
