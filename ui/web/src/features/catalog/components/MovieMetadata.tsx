import styled from 'styled-components'
import { useState } from 'react'
import { ChevronDown, ChevronUp, ExternalLink } from 'lucide-react'
import { interactiveTransition, textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'
import type { MovieDetail } from '../types'

const Container = styled.div`
  padding: 0 1.5rem 2rem;
`

const ToggleButton = styled.button`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  ${textRole('body')}
  font-weight: 500;
  color: var(--color-muted-foreground);
  ${interactiveTransition(['color'])}
  background: none;
  border: none;
  cursor: pointer;
  padding: 0;

  &:hover {
    color: var(--color-foreground);
  }
`

const ExpandedContent = styled.div`
  margin-top: 0.75rem;

  & > * + * {
    margin-top: 1rem;
  }
`

const Synopsis = styled(ContentText)`
  display: block;
  color: var(--color-muted-foreground);
`

const MetaGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  column-gap: 2rem;
  row-gap: 0.5rem;
  ${textRole('body-sm')}
`

const MetaLabel = styled.span`
  color: var(--color-muted-foreground);
`

const MetaValue = styled.p`
  font-weight: 500;
`

const ExternalLinkStyled = styled.a`
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  font-weight: 500;
  color: var(--color-accent-text);

  &:hover {
    text-decoration: underline;
  }
`

const VersionSection = styled.div``

const VersionTitle = styled.h3`
  ${textRole('body')}
  font-weight: 500;
  color: var(--color-muted-foreground);
  margin-bottom: 0.5rem;
`

const VersionRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
  ${textRole('body-sm')}

  & + & {
    margin-top: 0.25rem;
  }
`

const VersionLabel = styled.span`
  color: var(--color-muted-foreground);
`

const VersionValue = styled.span`
  ${textRole('mono-sm')}
  font-weight: 500;
`

const VersionDuration = styled.span`
  ${textRole('mono-sm')}
  color: var(--color-muted-foreground);
`

interface MovieMetadataProps {
  movie: Pick<MovieDetail, 'overview' | 'originalLanguage' | 'runtime' | 'tmdbId' | 'imdbId' | 'videos'>
}

export function MovieMetadata({ movie }: MovieMetadataProps) {
  const [expanded, setExpanded] = useState(true)

  return (
    <Container>
      <ToggleButton onClick={() => setExpanded(!expanded)}>
        Details
        {expanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
      </ToggleButton>

      {expanded && (
        <ExpandedContent>
          {/* Synopsis */}
          {movie.overview && (
            <Synopsis textRole="body" text={movie.overview} forwardedAs="p" />
          )}

          {/* Metadata grid */}
          <MetaGrid>
            {movie.originalLanguage && (
              <div>
                <MetaLabel>Language</MetaLabel>
                <MetaValue>{movie.originalLanguage.toUpperCase()}</MetaValue>
              </div>
            )}
            {movie.runtime && (
              <div>
                <MetaLabel>Runtime</MetaLabel>
                <MetaValue>{Math.floor(movie.runtime / 60)}h {movie.runtime % 60}m</MetaValue>
              </div>
            )}
            {movie.tmdbId && (
              <div>
                <MetaLabel>TMDB</MetaLabel>
                <ExternalLinkStyled
                  href={`https://www.themoviedb.org/movie/${movie.tmdbId}`}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  View <ExternalLink size={12} />
                </ExternalLinkStyled>
              </div>
            )}
            {movie.imdbId && (
              <div>
                <MetaLabel>IMDB</MetaLabel>
                <ExternalLinkStyled
                  href={`https://www.imdb.com/title/${movie.imdbId}`}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  View <ExternalLink size={12} />
                </ExternalLinkStyled>
              </div>
            )}
          </MetaGrid>

          {/* Video versions */}
          {movie.videos && movie.videos.length > 1 && (
            <VersionSection>
              <VersionTitle>Versions</VersionTitle>
              <div>
                {movie.videos.map((video, idx: number) => (
                  <VersionRow key={video.publicId ?? idx}>
                    <VersionLabel>Version {idx + 1}</VersionLabel>
                    <VersionValue>{video.width}\u00d7{video.height}</VersionValue>
                    {video.duration && (
                      <VersionDuration>
                        {Math.floor(video.duration / 60)}:{String(Math.floor(video.duration % 60)).padStart(2, '0')}
                      </VersionDuration>
                    )}
                  </VersionRow>
                ))}
              </div>
            </VersionSection>
          )}
        </ExpandedContent>
      )}
    </Container>
  )
}
