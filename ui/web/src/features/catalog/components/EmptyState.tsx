import styled from 'styled-components'
import { useTranslation } from '@/shared/i18n'

const EmptyContainer = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 5rem 0;
  color: var(--color-muted-foreground);
`

const EmptyMessage = styled.p`
  font-size: 1.125rem;
`

export function EmptyState({ message }: { message: string }) {
  return (
    <EmptyContainer>
      <EmptyMessage>{message}</EmptyMessage>
    </EmptyContainer>
  )
}

export function CatalogEmptyState({ type }: { type: 'albums' | 'artists' | 'songs' | 'genres' }) {
  const { t } = useTranslation()
  const messages: Record<string, string> = {
    albums: t('catalog.noAlbums'),
    artists: t('catalog.noArtists'),
    songs: t('catalog.noSongs'),
    genres: t('catalog.noGenres'),
  }
  return <EmptyState message={messages[type]} />
}
