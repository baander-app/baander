import styled from 'styled-components'
import { useMediaStorageStats, useCheckMissingImages, usePruneMissingImages } from '../hooks/use-media-admin'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { Image, HardDrive, Trash2, Search, AlertTriangle, CheckCircle2 } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { StatCard } from '@/shared/components/stat-card'
import { ConfirmDialog } from '@/shared/components/confirm-dialog'
import { ErrorBanner } from '@/shared/components/error-banner'
import { formatBytes } from '@/shared/utils/format-human'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.5rem;
`

const ActionsRow = styled.div`
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
`

const StatsGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;

  @media (max-width: 640px) {
    grid-template-columns: 1fr;
  }
`

const LoadingCard = styled.div`
  height: 7rem;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background: var(--color-card);
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

const Card = styled.div`
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background: var(--color-card);
`

const CardHeader = styled.div`
  border-bottom: 1px solid var(--color-border);
  padding: 0.75rem 1rem;
`

const CardTitle = styled.h2`
  font-size: 0.8125rem;
  font-weight: 500;
`

const Divider = styled.div`
  & > div + div {
    border-top: 1px solid var(--color-border);
  }
`

const TypeRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 1rem;
`

const TypeName = styled.span`
  font-size: 0.8125rem;
  font-weight: 500;
  text-transform: capitalize;
`

const TypeStats = styled.div`
  display: flex;
  align-items: center;
  gap: 1rem;
`

const TypeCount = styled.span`
  font-size: 0.8125rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const TypeSize = styled.span`
  font-size: 0.8125rem;
  font-variant-numeric: tabular-nums;
  font-weight: 500;
`

const FeedbackCard = styled.div<{ $variant: 'success' | 'warning' | 'error' }>`
  border-radius: var(--radius-lg);
  border: 1px solid ${({ $variant }) => {
    switch ($variant) {
      case 'success': return 'color-mix(in srgb, #10b981 30%, transparent)'
      case 'warning': return 'color-mix(in srgb, #f59e0b 30%, transparent)'
      case 'error': return 'color-mix(in srgb, var(--color-destructive) 30%, transparent)'
    }
  }};
  background: var(--color-card);
  padding: 1rem;
`

const FeedbackHeader = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const FeedbackTitle = styled.span`
  font-size: 0.8125rem;
  font-weight: 500;
`

const FeedbackDesc = styled.p`
  margin-top: 0.25rem;
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
`

const MissingList = styled.div`
  margin-top: 0.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const MissingItem = styled.div`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
  font-family: var(--font-mono);
`

const StyledLink = styled(Link)`
  text-decoration: underline;
`

export function MediaPage() {
  const { data: stats, isLoading, error } = useMediaStorageStats()
  const checkMissing = useCheckMissingImages()
  const pruneMissing = usePruneMissingImages()
  const roles = useAuthStore((s) => s.user?.roles ?? [])
  const isSuperAdmin = roles.includes('ROLE_SUPER_ADMIN')
  const [showMissing, setShowMissing] = useState(false)
  const [confirmPrune, setConfirmPrune] = useState(false)

  const handleCheckMissing = () => {
    setShowMissing(true)
    checkMissing.refetch()
  }

  const handlePrune = () => {
    setConfirmPrune(false)
    pruneMissing.mutate(undefined, {
      onSuccess: () => {
        setShowMissing(false)
      },
    })
  }

  if (isLoading) {
    return (
      <Container>
        <StatsGrid>
          {Array.from({ length: 3 }).map((_, i) => (
            <LoadingCard key={i} />
          ))}
        </StatsGrid>
      </Container>
    )
  }

  if (error) {
    return (
      <Container>
        <ErrorBanner message={error instanceof Error ? error.message : 'Failed to load storage stats.'} />
      </Container>
    )
  }

  const totalImages = stats?.totalImages ?? 0
  const totalSize = stats?.totalSize ?? 0
  const byType = stats?.byType ?? []
  const missingData = checkMissing.data
  const missingCount = missingData?.missingCount ?? 0
  const missingImages = missingData?.missingImages ?? []

  return (
    <Container>
      {isSuperAdmin && (
        <ActionsRow>
          <Button
            variant="ghost"
            size="sm"
            onClick={handleCheckMissing}
            disabled={checkMissing.isLoading}
          >
            <Search size={14} strokeWidth={1.5} style={{ animation: checkMissing.isLoading ? 'pulse 2s infinite' : 'none' }} />
            <span style={{ marginLeft: '0.375rem' }}>Check Missing</span>
          </Button>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setConfirmPrune(true)}
            disabled={pruneMissing.isPending}
          >
            <Trash2 size={14} strokeWidth={1.5} style={{ animation: pruneMissing.isPending ? 'spin 1s linear infinite' : 'none' }} />
            <span style={{ marginLeft: '0.375rem' }}>Prune Missing</span>
          </Button>
        </ActionsRow>
      )}

      {/* Storage overview */}
      <StatsGrid>
        <StatCard label="Total Images" value={totalImages.toLocaleString()} icon={Image} />
        <StatCard label="Total Size" value={formatBytes(totalSize)} icon={HardDrive} />
        <StatCard
          label="Types"
          value={byType.length}
          sub={byType.map((t) => `${t.type}: ${t.count}`).join(', ')}
          icon={Image}
        />
      </StatsGrid>

      {/* By-type breakdown */}
      {byType.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Storage by Type</CardTitle>
          </CardHeader>
          <Divider>
            {byType.map((t) => (
              <TypeRow key={t.type}>
                <TypeName>{t.type}</TypeName>
                <TypeStats>
                  <TypeCount>{t.count.toLocaleString()} images</TypeCount>
                  <TypeSize>{formatBytes(t.size)}</TypeSize>
                </TypeStats>
              </TypeRow>
            ))}
          </Divider>
        </Card>
      )}

      {/* Prune dispatched feedback */}
      {pruneMissing.isSuccess && pruneMissing.data && (
        <FeedbackCard $variant="success">
          <FeedbackHeader>
            <CheckCircle2 size={16} strokeWidth={1.5} style={{ color: '#10b981' }} />
            <FeedbackTitle>Prune job dispatched</FeedbackTitle>
          </FeedbackHeader>
          <FeedbackDesc>
            Check the <StyledLink to="/admin?tab=jobs">Job Monitor</StyledLink> for progress.
          </FeedbackDesc>
        </FeedbackCard>
      )}

      {/* Missing check results */}
      {showMissing && checkMissing.isSuccess && missingData && (
        <FeedbackCard $variant={missingCount > 0 ? 'warning' : 'success'}>
          <FeedbackHeader>
            {missingCount > 0 ? (
              <>
                <AlertTriangle size={16} strokeWidth={1.5} style={{ color: '#f59e0b' }} />
                <FeedbackTitle>
                  {missingCount.toLocaleString()} of {missingData.totalImages.toLocaleString()} images have missing files
                </FeedbackTitle>
              </>
            ) : (
              <>
                <CheckCircle2 size={16} strokeWidth={1.5} style={{ color: '#10b981' }} />
                <FeedbackTitle>All image files are present</FeedbackTitle>
              </>
            )}
          </FeedbackHeader>
          {missingCount > 0 && (
            <MissingList>
              {missingImages.map((img) => (
                <MissingItem key={img.id}>
                  {img.path} ({img.type})
                </MissingItem>
              ))}
            </MissingList>
          )}
        </FeedbackCard>
      )}

      {/* Prune error */}
      {pruneMissing.isError && (
        <FeedbackCard $variant="error">
          <FeedbackTitle style={{ color: 'var(--color-destructive)', fontSize: '0.875rem' }}>Failed to dispatch prune job.</FeedbackTitle>
          <FeedbackDesc style={{ fontFamily: 'var(--font-mono)' }}>
            {pruneMissing.error instanceof Error ? pruneMissing.error.message : 'Unknown error'}
          </FeedbackDesc>
        </FeedbackCard>
      )}

      {/* Confirmation dialog */}
      <ConfirmDialog
        open={confirmPrune}
        onConfirm={handlePrune}
        onCancel={() => setConfirmPrune(false)}
        title="Prune missing images?"
        description="This will delete all image records whose files no longer exist on disk. This action cannot be undone. The job will run asynchronously — check the Job Monitor for progress."
        confirmLabel="Confirm"
        variant="destructive"
      />
    </Container>
  )
}
