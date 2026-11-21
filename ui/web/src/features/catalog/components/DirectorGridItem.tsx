import type { MouseEventHandler } from 'react'
import { Link } from 'react-router-dom'
import styled from 'styled-components'
import type { DirectorResource } from '@/shared/api-client/gen/endpoints'
import { ContentText } from '@/shared/typography'
import { focusVisibleRing, textRole } from '@/shared/theme'

const Card = styled(Link)`
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  overflow: hidden;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-card);
  color: var(--color-foreground);
  padding: var(--space-md);
  text-decoration: none;
  ${focusVisibleRing}
`
const Portrait = styled.img`
  width: 100%;
  aspect-ratio: 2 / 3;
  object-fit: cover;
  border-radius: var(--radius-md);
`
const Name = styled(ContentText)`
  overflow-wrap: anywhere;
`
const Count = styled.span`
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

interface DirectorGridItemProps {
  director: DirectorResource
  onOpen?: MouseEventHandler<HTMLAnchorElement>
}

export function DirectorGridItem({ director, onOpen }: DirectorGridItemProps) {
  return (
    <Card
      id={`director-card-${director.publicId}`}
      to={`/movies/directors/${director.publicId}`}
      onClick={onOpen}
    >
      {director.profileUrl ? (
        <Portrait src={director.profileUrl} alt="" loading="lazy" />
      ) : null}
      <Name text={director.name} textRole="body" element="h2" />
      <Count>{director.movieCount} {director.movieCount === 1 ? 'movie' : 'movies'}</Count>
    </Card>
  )
}
