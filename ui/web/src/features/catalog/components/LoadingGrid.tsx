import styled from 'styled-components'

const Grid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem;

  @media (min-width: 640px) {
    grid-template-columns: repeat(3, 1fr);
  }
  @media (min-width: 768px) {
    grid-template-columns: repeat(4, 1fr);
  }
  @media (min-width: 1024px) {
    grid-template-columns: repeat(5, 1fr);
  }
  @media (min-width: 1280px) {
    grid-template-columns: repeat(6, 1fr);
  }
`

const SkeletonCard = styled.div`
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

const SkeletonSquare = styled.div`
  margin-bottom: 0.5rem;
  aspect-ratio: 1;
  border-radius: var(--radius-md);
  background-color: var(--color-muted);
`

const SkeletonLine1 = styled.div`
  height: 1rem;
  width: 75%;
  border-radius: var(--radius-sm);
  background-color: var(--color-muted);
`

const SkeletonLine2 = styled.div`
  margin-top: 0.25rem;
  height: 0.75rem;
  width: 50%;
  border-radius: var(--radius-sm);
  background-color: var(--color-muted);
`

export function LoadingGrid({ count = 12 }: { count?: number }) {
  return (
    <Grid>
      {Array.from({ length: count }).map((_, i) => (
        <SkeletonCard key={i}>
          <SkeletonSquare />
          <SkeletonLine1 />
          <SkeletonLine2 />
        </SkeletonCard>
      ))}
    </Grid>
  )
}
