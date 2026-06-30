import styled from 'styled-components'
import React, { useState } from 'react'
import { useAlbumDuplicates } from '../hooks/use-album-duplicates'
import { useLibraries } from '@/features/library/hooks/use-libraries'
import { Layers, Copy, CheckCircle2 } from 'lucide-react'
import { DuplicateGroupCard } from '../components/media/DuplicateGroupCard'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.5rem;
`

const HeaderRow = styled.div`
  display: flex;
  justify-content: flex-end;
`

const LibrarySelect = styled.select`
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background: var(--color-background);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  color: var(--color-foreground);

  &:focus {
    outline: none;
    box-shadow: 0 0 0 1px var(--color-primary);
  }
`

const StatsGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;

  @media (max-width: 1024px) {
    grid-template-columns: repeat(2, 1fr);
  }

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

const ErrorCard = styled.div`
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, var(--color-destructive) 30%, transparent);
  background: var(--color-card);
  padding: 1rem;
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-destructive);
`

const ErrorDetail = styled.p`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
  margin-top: 0.25rem;
  font-family: var(--font-mono);
`

const DuplicateHeader = styled.h2`
  font-size: 0.875rem;
  font-weight: 500;
`

const DuplicateStack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const EmptyCard = styled.div`
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background: var(--color-card);
  padding: 2rem;
  text-align: center;
`

const EmptyIcon = styled(CheckCircle2)`
  margin: 0 auto;
  color: var(--color-muted-foreground);
  margin-bottom: 0.75rem;
`

const EmptyText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

function StatCardEl({ label, value, sub, icon: Icon }: {
  label: string
  value: React.ReactNode
  sub?: string
  icon: React.ComponentType<{ size?: number; strokeWidth?: number; className?: string }>
}) {
  return (
    <StatCardWrapper>
      <StatCardHeader>
        <Icon size={15} strokeWidth={1.5} />
        <StatCardLabel>{label}</StatCardLabel>
      </StatCardHeader>
      <StatCardValue>{value}</StatCardValue>
      {sub && <StatCardSub>{sub}</StatCardSub>}
    </StatCardWrapper>
  )
}

const StatCardWrapper = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background: var(--color-card);
  padding: 1rem;
`

const StatCardHeader = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--color-muted-foreground);
`

const StatCardLabel = styled.span`
  font-size: 0.6875rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
`

const StatCardValue = styled.div`
  font-size: 1.25rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
`

const StatCardSub = styled.p`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
`

export function AlbumDuplicatesPage() {
  const { data: libraries } = useLibraries()
  const [selectedLibraryId, setSelectedLibraryId] = useState<string | null>(null)
  const { data: duplicateGroups, isLoading, error, refetch } = useAlbumDuplicates(selectedLibraryId)

  // Auto-select first library if available and none selected
  if (libraries && libraries.length > 0 && selectedLibraryId === null) {
    setSelectedLibraryId(libraries[0].id)
  }

  const totalDuplicates = duplicateGroups?.length ?? 0
  const totalAlbumsAffected = duplicateGroups?.reduce((sum, g) => sum + g.albumCount, 0) ?? 0

  return (
    <Container>
      <HeaderRow>
        {libraries && libraries.length > 1 && (
          <LibrarySelect
            value={selectedLibraryId ?? ''}
            onChange={(e) => setSelectedLibraryId(e.target.value || null)}
          >
            <option value="">Select library...</option>
            {libraries.map((lib) => (
              <option key={lib.id} value={lib.id}>
                {lib.name}
              </option>
            ))}
          </LibrarySelect>
        )}
      </HeaderRow>

      {isLoading && (
        <StatsGrid>
          {Array.from({ length: 4 }).map((_, i) => (
            <LoadingCard key={i} />
          ))}
        </StatsGrid>
      )}

      {error && (
        <ErrorCard>
          <ErrorText>Failed to load duplicates.</ErrorText>
          <ErrorDetail>
            {error instanceof Error ? error.message : 'Unknown error'}
          </ErrorDetail>
        </ErrorCard>
      )}

      {!isLoading && !error && (
        <>
          {/* Stats */}
          <StatsGrid>
            <StatCardEl label="Duplicate Groups" value={totalDuplicates} icon={Layers} />
            <StatCardEl
              label="Albums Affected"
              value={totalAlbumsAffected}
              sub={totalDuplicates > 0 ? `Across ${totalDuplicates} groups` : undefined}
              icon={Copy}
            />
            <StatCardEl
              label="Status"
              value={totalDuplicates === 0 ? 'Clean' : 'Action Needed'}
              sub={totalDuplicates === 0 ? 'No duplicates found' : 'Review duplicates below'}
              icon={CheckCircle2}
            />
          </StatsGrid>

          {/* Duplicate Groups */}
          {duplicateGroups && duplicateGroups.length > 0 ? (
            <DuplicateStack>
              <DuplicateHeader>Duplicate Groups</DuplicateHeader>
              {duplicateGroups.map((group) => (
                <DuplicateGroupCard
                  key={group.albumIds.join('-')}
                  group={group}
                  onMergeComplete={() => refetch()}
                />
              ))}
            </DuplicateStack>
          ) : (
            <EmptyCard>
              <EmptyIcon size={32} strokeWidth={1.5} />
              <EmptyText>No duplicate albums found.</EmptyText>
            </EmptyCard>
          )}
        </>
      )}
    </Container>
  )
}
