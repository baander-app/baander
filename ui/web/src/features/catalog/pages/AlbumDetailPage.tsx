import { useRef, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import styled from 'styled-components'
import { useGetAlbumShow } from '@/shared/api-client/gen/endpoints'
import { asAlbumFromData, asSongsFromItems } from '../utils/api-adapters'
import { useBlurhashAccent } from '../hooks/use-blurhash-accent'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { Button } from '@/shared/components/ui/button'
import { AlbumHeader } from '../components/AlbumHeader'
import { AlbumTrackList } from '../components/AlbumTrackList'
import { AlbumMetadata } from '../components/AlbumMetadata'
import { DuplicateWarningBanner } from '../components/DuplicateWarningBanner'
import { MergeAlbumsDialog } from '../components/MergeAlbumsDialog'
import { useAlbumDuplicates } from '../api/album-duplicates-api'

const PageContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
`

const LoadingHeader = styled.div`
  display: flex;
  height: 3rem;
  align-items: center;
  gap: 0.75rem;
  padding: 0 1rem;
`

const LoadingContent = styled.div`
  flex: 1;
  padding: 0 1rem;
`

const CenterMessage = styled.div`
  display: flex;
  height: 100%;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-destructive);
`

export function AlbumDetailPage() {
  const { publicId } = useParams<{ publicId: string }>()
  const { data, isLoading, isError, refetch } = useGetAlbumShow(publicId ?? '')
  const { data: duplicateGroups } = useAlbumDuplicates(publicId)
  const navigate = useNavigate()
  const containerRef = useRef<HTMLDivElement>(null)
  const [metadataOpen, setMetadataOpen] = useState(false)
  const [warningDismissed, setWarningDismissed] = useState(false)

  // Unwrap the generated API response wrapper (data = { data: {...}, status, headers })
  const album = asAlbumFromData(data)
  const blurhash: string | null | undefined = album?.coverImage?.blurhash ?? null
  useBlurhashAccent(blurhash, containerRef)

  if (isLoading || !publicId) {
    return (
      <PageContainer>
        <LoadingHeader>
          <Skeleton style={{ height: '1.5rem', width: '1.5rem', borderRadius: '0.25rem' }} />
          <Skeleton style={{ height: '2rem', width: '2rem', borderRadius: '0.25rem' }} />
          <Skeleton style={{ height: '1rem', width: '10rem' }} />
          <Skeleton style={{ height: '1rem', width: '6rem' }} />
        </LoadingHeader>
        <LoadingContent>
          {Array.from({ length: 10 }).map((_, i) => (
            <Skeleton key={i} style={{ marginBottom: '0.25rem', height: '2rem', width: '100%', borderRadius: '0.25rem' }} />
          ))}
        </LoadingContent>
      </PageContainer>
    )
  }

  if (isError || !album) {
    return (
      <CenterMessage>
        <ErrorText>Failed to load album</ErrorText>
        <Button variant="ghost" size="sm" onClick={() => refetch()}>
          Retry
        </Button>
      </CenterMessage>
    )
  }

  // Extract songs from the raw response wrapper (inside data.data.songs)
  const rawData = (data as Record<string, unknown> | null)?.data as Record<string, unknown> | undefined
  const songs = asSongsFromItems(rawData?.songs)
  const coverUrl: string | null = album.coverImage?.url ?? null
  const artistName: string | undefined =
    album.artists?.[0]?.name

  return (
    <PageContainer ref={containerRef}>
      <AlbumHeader
        title={album.title ?? ''}
        artistName={artistName}
        year={album.year ?? null}
        coverUrl={coverUrl}
        songs={songs as unknown as Record<string, unknown>[]}
        albumTitle={album.title ?? ''}
        albumPublicId={publicId}
        onBack={() => navigate(-1)}
        onToggleMetadata={() => setMetadataOpen((v) => !v)}
        metadataOpen={metadataOpen}
      />
      {!warningDismissed && duplicateGroups && duplicateGroups.length > 0 && (
        <DuplicateWarningBanner
          duplicateGroups={duplicateGroups}
          albumTitle={album.title ?? ''}
          albumUuid={album.uuid ?? ''}
          onDismiss={() => setWarningDismissed(true)}
        />
      )}
      {metadataOpen && (
        <AlbumMetadata album={album as unknown as Record<string, unknown>} />
      )}
      <AlbumTrackList songs={songs as unknown as Record<string, unknown>[]} albumTitle={album.title ?? ''} albumPublicId={publicId} />
      <MergeAlbumsDialog />
    </PageContainer>
  )
}
