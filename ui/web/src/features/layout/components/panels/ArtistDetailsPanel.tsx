import styled from 'styled-components'
import { useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { Play, Shuffle, ExternalLink } from 'lucide-react'
import { useGetArtistShow } from '@/shared/api-client/gen/endpoints'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { ArtistContextMenu } from '@/features/catalog/components/menus/ArtistContextMenu'

/* eslint-disable @typescript-eslint/no-explicit-any */

const AvatarSkeleton = styled(Skeleton)`
  margin-inline: auto;
  height: 8rem;
  width: 8rem;
  border-radius: 9999px;
`

const TitleSkeleton = styled(Skeleton)`
  height: 1.25rem;
  width: 7rem;
`

const SubtitleSkeleton = styled(Skeleton)`
  height: 1rem;
  width: 5rem;
`

const ActionSkeleton = styled(Skeleton)`
  height: 2rem;
  flex: 1;
`

const FullWidthButton = styled(Button)`
  width: 100%;
  gap: 0.375rem;
`

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const Header = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  text-align: center;
`

const AvatarCircle = styled.div`
  display: flex;
  height: 8rem;
  width: 8rem;
  align-items: center;
  justify-content: center;
  border-radius: 9999px;
  background-color: var(--color-secondary);
`

const AvatarInitial = styled.span`
  font-size: 1.5rem;
  font-weight: 600;
  color: color-mix(in srgb, var(--color-muted-foreground) 40%, transparent);
`

const ArtistName = styled.h3`
  font-size: 1rem;
  font-weight: 500;
  color: var(--color-foreground);
  margin: 0;
`

const ArtistMeta = styled.p`
  font-size: 0.75rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 70%, transparent);
  margin: 0;
`

const ActionRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const ErrorState = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 2rem 0;
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const LoadingContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const LoadingHeader = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
`

const LoadingActions = styled.div`
  display: flex;
  gap: 0.5rem;
  padding: 0 0.5rem;
`

interface ArtistDetailsPanelProps {
  publicId: string
}

export function ArtistDetailsPanel({ publicId }: ArtistDetailsPanelProps) {
  const navigate = useNavigate()
  const { data, isLoading, isError, refetch } = useGetArtistShow(publicId, {
    query: { enabled: !!publicId },
  })
  const artist = (data as any)?.data as any

  const handleOpen = useCallback(() => {
    navigate(`/artists/${publicId}`)
  }, [publicId, navigate])

  const handlePlay = useCallback(() => {
    navigate(`/artists/${publicId}`)
  }, [publicId, navigate])

  const handleShuffle = useCallback(() => {
    navigate(`/artists/${publicId}`)
  }, [publicId, navigate])

  if (isLoading) {
    return (
      <LoadingContainer>
        <AvatarSkeleton />
        <LoadingHeader>
          <TitleSkeleton />
          <SubtitleSkeleton />
        </LoadingHeader>
        <LoadingActions>
          <ActionSkeleton />
          <ActionSkeleton />
        </LoadingActions>
      </LoadingContainer>
    )
  }

  if (isError || !artist) {
    return (
      <ErrorState>
        <ErrorText>Failed to load artist</ErrorText>
        <Button variant="ghost" size="sm" onClick={() => refetch()}>Retry</Button>
      </ErrorState>
    )
  }

  const name: string = artist.name ?? ''
  const initial = name.charAt(0).toUpperCase()
  const albumCount = typeof artist.albumCount === 'number' ? artist.albumCount : null

  return (
    <ArtistContextMenu artist={{ publicId, name }}>
      <Container>
      {/* Artist header */}
      <Header>
        <AvatarCircle>
          <AvatarInitial>{initial || '?'}</AvatarInitial>
        </AvatarCircle>
        <div>
          <ArtistName>{name}</ArtistName>
          {albumCount !== null && (
            <ArtistMeta>
              {albumCount} {albumCount === 1 ? 'album' : 'albums'}
            </ArtistMeta>
          )}
        </div>
        <ActionRow>
          <Button size="sm" onClick={handlePlay}>
            <Play size={14} fill="currentColor" />
            Play
          </Button>
          <Button variant="outline" size="sm" onClick={handleShuffle}>
            <Shuffle size={14} />
          </Button>
        </ActionRow>
      </Header>

      {/* Open button */}
      <FullWidthButton variant="outline" size="sm" onClick={handleOpen}>
        <ExternalLink size={12} />
        Open
      </FullWidthButton>
    </Container>
    </ArtistContextMenu>
  )
}
