import { Link, useParams } from 'react-router-dom'
import styled from 'styled-components'
import { Play } from 'lucide-react'
import { useGetSongShow } from '@/shared/api-client/gen/endpoints'
import { Button } from '@/shared/components/ui/button'
import { ContentText } from '@/shared/typography'
import { textRole } from '@/shared/theme'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { DirectorBrowseError, DirectorBrowseLoading } from '../components/DirectorBrowseLayout'

const Page = styled.section`
  height: 100%;
  overflow-y: auto;
  padding: var(--space-lg);
  p { ${textRole('body')} }
`
export function SongDetailPage() {
  const { publicId = '' } = useParams<{ publicId: string }>()
  const song = useGetSongShow(publicId)
  const playTrack = usePlayerStore(state => state.playTrack)
  const track = song.data?.data
  return (
    <Page>
      <Link to="/music/songs">Back to Songs</Link>
      {song.isError ? <DirectorBrowseError retry={song.refetch} /> : song.isPending || !track ? <DirectorBrowseLoading /> : (
        <>
          <ContentText text={track.title} language={track.albumLanguage} textRole="title-lg" element="h1" />
          {track.artistName ? <ContentText text={track.artistName} textRole="body" element="p" /> : null}
          <Link to={`/music/albums/${track.albumId}`}>
            <ContentText text={track.albumName || 'View album'} language={track.albumLanguage} textRole="body" element="span" />
          </Link>
          <p>
            <Button
              disabled={!track.available}
              onClick={() => playTrack({
                publicId: track.publicId,
                title: track.title,
                available: track.available,
                artistName: track.artistName ?? undefined,
                albumPublicId: track.albumId,
                albumName: track.albumName ?? undefined,
                duration: track.length ?? undefined,
              })}
            >
              <Play size={16} aria-hidden="true" />
              Play
            </Button>
          </p>
          {!track.available ? <p>This song is unavailable.</p> : null}
        </>
      )}
    </Page>
  )
}
