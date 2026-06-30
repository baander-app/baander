import styled from 'styled-components'
import { useNavigate } from 'react-router-dom'
import { interactiveTransition } from '@/shared/theme'

const Row = styled.tr`
  cursor: pointer;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 50%, transparent);
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 30%, transparent);
  }
`

const PosterCell = styled.td`
  padding: 0.5rem;
`

const PosterImage = styled.img`
  border-radius: var(--radius-sm);
  object-fit: cover;
`

const PosterPlaceholder = styled.div`
  display: flex;
  height: 66px;
  width: 44px;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-sm);
  background-color: var(--color-surface-3);
`

const PlaceholderIcon = styled.svg`
  height: 1rem;
  width: 1rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);
`

const TitleCell = styled.td`
  padding: 0.5rem;
`

const MovieTitle = styled.div`
  font-size: 0.875rem;
  font-weight: 500;
`

const RuntimeText = styled.div`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const YearCell = styled.td`
  padding: 0.5rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const RatingCell = styled.td`
  padding: 0.5rem;
  font-size: 0.875rem;
`

const StarIcon = styled.svg`
  height: 0.875rem;
  width: 0.875rem;
  color: #fbbf24;
`

const StarSpan = styled.span`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const DashText = styled.span``

interface MovieListItemProps {
  movie: {
    publicId: string
    title: string
    year?: number | null
    posterUrl?: string | null
    rating?: number | null
    runtime?: number | null
  }
}

export function MovieListItem({ movie }: MovieListItemProps) {
  const navigate = useNavigate()

  return (
    <Row onClick={() => navigate(`/movies/${movie.publicId}`)}>
      <PosterCell>
        {movie.posterUrl ? (
          <PosterImage src={movie.posterUrl} alt="" width={44} height={66} loading="lazy" />
        ) : (
          <PosterPlaceholder>
            <PlaceholderIcon viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5}>
              <rect x="2" y="2" width="20" height="20" rx="2" />
            </PlaceholderIcon>
          </PosterPlaceholder>
        )}
      </PosterCell>
      <TitleCell>
        <MovieTitle>{movie.title}</MovieTitle>
        {movie.runtime && <RuntimeText>{Math.floor(movie.runtime / 60)}h {movie.runtime % 60}m</RuntimeText>}
      </TitleCell>
      <YearCell>{movie.year ?? '\u2014'}</YearCell>
      <RatingCell>
        {movie.rating ? (
          <StarSpan>
            <StarIcon viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
            </StarIcon>
            {movie.rating.toFixed(1)}
          </StarSpan>
        ) : <DashText>\u2014</DashText>}
      </RatingCell>
    </Row>
  )
}
