import styled from 'styled-components'
import { Play } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'

const Container = styled.div`
  position: relative;
`

const BackdropArea = styled.div`
  position: absolute;
  inset: 0;
  height: 20rem;
  overflow: hidden;
`

const BackdropImage = styled.img`
  height: 100%;
  width: 100%;
  object-fit: cover;
`

const BackdropGradient = styled.div`
  position: absolute;
  inset: 0;
  background: linear-gradient(to top, var(--color-background), color-mix(in srgb, var(--color-background) 60%, transparent), transparent);
`

const Content = styled.div`
  position: relative;
  display: flex;
  gap: 1.5rem;
  padding: 2rem 1.5rem 1.5rem;
`

const PosterArea = styled.div`
  flex-shrink: 0;
`

const PosterImage = styled.img`
  aspect-ratio: 2/3;
  width: 12rem;
  border-radius: var(--radius-lg);
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
`

const PosterPlaceholder = styled.div`
  display: flex;
  aspect-ratio: 2/3;
  width: 12rem;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-lg);
  background-color: var(--color-surface-3);
`

const PlaceholderIcon = styled.svg`
  height: 4rem;
  width: 4rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);
`

const MetaArea = styled.div`
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 1rem 0;
`

const MetaRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const Dot = styled.span`
  margin: 0 0.25rem;
`

const StarIcon = styled.svg`
  height: 0.875rem;
  width: 0.875rem;
  color: #fbbf24;
`

const MovieTitle = styled.h1`
  font-size: 1.875rem;
  font-weight: 700;
  letter-spacing: -0.025em;
`

const Tagline = styled.p`
  font-size: 0.875rem;
  font-style: italic;
  color: var(--color-muted-foreground);
`

interface MovieHeaderProps {
  movie: {
    title: string
    year?: number | null
    tagline?: string | null
    runtime?: number | null
    rating?: number | null
    posterUrl?: string | null
    backdropUrl?: string | null
    videos?: any[]
  }
  onPlay: () => void
}

export function MovieHeader({ movie, onPlay }: MovieHeaderProps) {
  const hasVideo = movie.videos && movie.videos.length > 0
  const runtime = movie.runtime
    ? `${Math.floor(movie.runtime / 60)}h ${movie.runtime % 60}m`
    : null

  return (
    <Container>
      {/* Backdrop */}
      {movie.backdropUrl && (
        <BackdropArea>
          <BackdropImage src={movie.backdropUrl} alt="" />
          <BackdropGradient />
        </BackdropArea>
      )}

      {/* Content */}
      <Content>
        {/* Poster */}
        <PosterArea>
          {movie.posterUrl ? (
            <PosterImage src={movie.posterUrl} alt={movie.title} width={192} />
          ) : (
            <PosterPlaceholder>
              <PlaceholderIcon viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5}>
                <rect x="2" y="2" width="20" height="20" rx="2" />
              </PlaceholderIcon>
            </PosterPlaceholder>
          )}
        </PosterArea>

        {/* Metadata */}
        <MetaArea>
          <MetaRow>
            {movie.year && <span>{movie.year}</span>}
            {runtime && <><Dot>\u00b7</Dot><span>{runtime}</span></>}
            {movie.rating && (
              <>
                <Dot>\u00b7</Dot>
                <span style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
                  <StarIcon viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" /></StarIcon>
                  {movie.rating.toFixed(1)}
                </span>
              </>
            )}
          </MetaRow>

          <MovieTitle>{movie.title}</MovieTitle>

          {movie.tagline && (
            <Tagline>&ldquo;{movie.tagline}&rdquo;</Tagline>
          )}

          {hasVideo && (
            <Button
              onClick={onPlay}
              style={{ marginTop: '0.75rem', width: 'fit-content', gap: '0.5rem' }}
              size="lg"
            >
              <Play size={18} fill="currentColor" />
              Play
            </Button>
          )}
        </MetaArea>
      </Content>
    </Container>
  )
}
