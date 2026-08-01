import styled from 'styled-components'
import { useNavigate } from 'react-router-dom'
import { focusVisibleRing } from '@/shared/theme'

const CardButton = styled.button`
  position: relative;
  aspect-ratio: 2/3;
  overflow: hidden;
  border-radius: var(--radius-lg);
  background-color: var(--color-surface-2);
  transition: all 150ms;
  ${focusVisibleRing}
  text-align: left;
  width: 100%;
  border: none;
  cursor: pointer;
  padding: 0;

  &:hover {
    transform: scale(1.02);
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
  }

  &:focus {
    outline: 2px solid var(--color-primary);
  }
`

const PosterImage = styled.img`
  height: 100%;
  width: 100%;
  object-fit: cover;
`

const PosterPlaceholder = styled.div`
  display: flex;
  height: 100%;
  align-items: center;
  justify-content: center;
  background-color: var(--color-surface-3);
`

const PlaceholderIcon = styled.svg`
  height: 3rem;
  width: 3rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);
`

const GradientOverlay = styled.div`
  position: absolute;
  inset-inline: 0;
  bottom: 0;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.8), transparent);
  padding: 0.75rem;
  padding-top: 2rem;
`

const MovieTitle = styled.h3`
  font-size: 0.875rem;
  font-weight: 500;
  color: white;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const MetaRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.75rem;
  color: rgba(255, 255, 255, 0.7);
`

const StarIcon = styled.svg`
  height: 0.75rem;
  width: 0.75rem;
`

const StarSpan = styled.span`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

interface MovieGridItemProps {
  movie: {
    publicId: string
    title: string
    year?: number | null
    posterUrl?: string | null
    rating?: number | null
  }
}

export function MovieGridItem({ movie }: MovieGridItemProps) {
  const navigate = useNavigate()

  return (
    <CardButton onClick={() => navigate(`/movies/${movie.publicId}`)}>
      {movie.posterUrl ? (
        <PosterImage src={movie.posterUrl} alt={movie.title} loading="lazy" />
      ) : (
        <PosterPlaceholder>
          <PlaceholderIcon viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5}>
            <rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18" />
            <line x1="7" y1="2" x2="7" y2="22" />
            <line x1="17" y1="2" x2="17" y2="22" />
            <line x1="2" y1="12" x2="22" y2="12" />
          </PlaceholderIcon>
        </PosterPlaceholder>
      )}
      <GradientOverlay>
        <MovieTitle>{movie.title}</MovieTitle>
        <MetaRow>
          {movie.year && <span>{movie.year}</span>}
          {movie.rating && (
            <StarSpan>
              <StarIcon viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
              </StarIcon>
              {movie.rating.toFixed(1)}
            </StarSpan>
          )}
        </MetaRow>
      </GradientOverlay>
    </CardButton>
  )
}
