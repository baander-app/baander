import styled, { keyframes } from 'styled-components'

const pulse = keyframes`
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
`

const SkeletonBlock = styled.div`
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  background-color: var(--color-muted);
  border-radius: var(--radius-md);
`

const ResponsiveGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;

  @media (min-width: 640px) { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  @media (min-width: 768px) { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  @media (min-width: 1024px) { grid-template-columns: repeat(5, minmax(0, 1fr)); }
  @media (min-width: 1280px) { grid-template-columns: repeat(6, minmax(0, 1fr)); }
`

const CardStack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const ListStack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const SongRow = styled.div`
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.5rem 0.75rem;
`

const Spacer = styled.div`
  margin-left: auto;
`

const DetailContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1rem;
`

const DetailHeader = styled.div`
  display: flex;
  align-items: center;
  gap: 1rem;
`

const DetailList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const DetailRow = styled.div`
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.5rem 0;
`

export function AlbumGridSkeleton() {
  return (
    <ResponsiveGrid>
      {Array.from({ length: 12 }).map((_, i) => (
        <CardStack key={i}>
          <SkeletonBlock style={{ aspectRatio: '1 / 1' }} />
          <SkeletonBlock style={{ height: '0.75rem', width: '75%' }} />
          <SkeletonBlock style={{ height: '0.5rem', width: '50%' }} />
        </CardStack>
      ))}
    </ResponsiveGrid>
  )
}

export function SongListSkeleton() {
  return (
    <ListStack>
      {Array.from({ length: 20 }).map((_, i) => (
        <SongRow key={i}>
          <SkeletonBlock style={{ height: '1rem', width: '2rem' }} />
          <SkeletonBlock style={{ height: '1rem', width: '33%' }} />
          <SkeletonBlock style={{ height: '1rem', width: '25%' }} />
          <Spacer>
            <SkeletonBlock style={{ height: '1rem', width: '2.5rem' }} />
          </Spacer>
        </SongRow>
      ))}
    </ListStack>
  )
}

export function ArtistGridSkeleton() {
  return (
    <ResponsiveGrid>
      {Array.from({ length: 12 }).map((_, i) => (
        <CardStack key={i}>
          <SkeletonBlock style={{ aspectRatio: '1 / 1', borderRadius: '9999px' }} />
          <SkeletonBlock style={{ height: '0.75rem', width: '66%' }} />
        </CardStack>
      ))}
    </ResponsiveGrid>
  )
}

export function GenreGridSkeleton() {
  return (
    <ResponsiveGrid>
      {Array.from({ length: 8 }).map((_, i) => (
        <CardStack key={i}>
          <SkeletonBlock style={{ height: '5rem' }} />
          <SkeletonBlock style={{ height: '0.75rem', width: '50%' }} />
        </CardStack>
      ))}
    </ResponsiveGrid>
  )
}

export function DetailPageSkeleton() {
  return (
    <DetailContainer>
      <DetailHeader>
        <SkeletonBlock style={{ height: '2rem', width: '2rem', borderRadius: '9999px' }} />
        <SkeletonBlock style={{ height: '1.5rem', width: '10rem' }} />
      </DetailHeader>
      <SkeletonBlock style={{ height: '12rem', width: '100%' }} />
      <DetailList>
        {Array.from({ length: 10 }).map((_, i) => (
          <DetailRow key={i}>
            <SkeletonBlock style={{ height: '1rem', width: '2rem' }} />
            <SkeletonBlock style={{ height: '1rem', width: '33%' }} />
            <Spacer>
              <SkeletonBlock style={{ height: '1rem', width: '2.5rem' }} />
            </Spacer>
          </DetailRow>
        ))}
      </DetailList>
    </DetailContainer>
  )
}
