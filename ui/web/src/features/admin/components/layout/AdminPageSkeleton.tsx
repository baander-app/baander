import styled, { keyframes } from 'styled-components'

const pulse = keyframes`
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
`

const Wrapper = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.5rem;
`

const TitleSkeleton = styled.div`
  height: 1.5rem;
  width: 12rem;
  border-radius: 0.25rem;
  background-color: var(--color-muted);
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

const SubtitleSkeleton = styled.div`
  margin-top: 0.5rem;
  height: 1rem;
  width: 16rem;
  border-radius: 0.25rem;
  background-color: var(--color-muted);
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

const ListSkeleton = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const ListRow = styled.div`
  height: 2.5rem;
  border-radius: 0.25rem;
  background-color: var(--color-muted);
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

const CardsGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(1, 1fr);
  gap: 1rem;

  @media (min-width: 640px) {
    grid-template-columns: repeat(2, 1fr);
  }

  @media (min-width: 1024px) {
    grid-template-columns: repeat(3, 1fr);
  }
`

const CardSkeleton = styled.div`
  height: 7rem;
  border-radius: var(--radius-lg, 0.5rem);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

const SectionsGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(1, 1fr);
  gap: 1rem;

  @media (min-width: 1024px) {
    grid-template-columns: repeat(2, 1fr);
  }
`

const SectionSkeleton = styled.div`
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
`

const SectionHeader = styled.div`
  height: 2.5rem;
  border-radius: 0.375rem 0.375rem 0 0;
  background-color: var(--color-muted);
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

const SectionBody = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1rem;
`

const SectionLine = styled.div`
  height: 1rem;
  border-radius: 0.25rem;
  background-color: var(--color-muted);
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

interface AdminPageSkeletonProps {
  title?: string
  variant?: 'list' | 'cards' | 'sections'
  count?: number
}

export function AdminPageSkeleton({ title, variant = 'list', count = 5 }: AdminPageSkeletonProps) {
  return (
    <Wrapper>
      {title && (
        <div>
          <TitleSkeleton />
          <SubtitleSkeleton />
        </div>
      )}
      {variant === 'list' && (
        <ListSkeleton>
          {Array.from({ length: count }).map((_, i) => (
            <ListRow key={i} />
          ))}
        </ListSkeleton>
      )}
      {variant === 'cards' && (
        <CardsGrid>
          {Array.from({ length: count }).map((_, i) => (
            <CardSkeleton key={i} />
          ))}
        </CardsGrid>
      )}
      {variant === 'sections' && (
        <SectionsGrid>
          {Array.from({ length: count }).map((_, i) => (
            <SectionSkeleton key={i}>
              <SectionHeader />
              <SectionBody>
                {Array.from({ length: 4 }).map((_, j) => (
                  <SectionLine key={j} />
                ))}
              </SectionBody>
            </SectionSkeleton>
          ))}
        </SectionsGrid>
      )}
    </Wrapper>
  )
}
