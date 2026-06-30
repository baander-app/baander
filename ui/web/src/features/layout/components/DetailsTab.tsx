import styled from 'styled-components'
import { useContextPanelStore } from '../stores/context-panel-store'
import { AlbumDetailsPanel } from './panels/AlbumDetailsPanel'
import { ArtistDetailsPanel } from './panels/ArtistDetailsPanel'
import { SongPreviewPanel } from '@/features/catalog/components/panels/SongPreviewPanel'
import { InfoPanel } from '@/features/catalog/components/metadata/InfoPanel'

const EmptyState = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 3rem 0;
`

const EmptyIcon = styled.svg`
  color: color-mix(in srgb, var(--color-muted-foreground) 20%, transparent);
`

const EmptyTitle = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const EmptySubtitle = styled.p`
  font-size: 0.75rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 70%, transparent);
`

export function DetailsTab() {
  const selectedItem = useContextPanelStore((s) => s.selectedItem)
  const activeTab = useContextPanelStore((s) => s.activeTab)

  if (!selectedItem) {
    return (
      <EmptyState>
        <EmptyIcon width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
          <circle cx="12" cy="12" r="10" />
          <path d="M12 16v-4" />
          <path d="M12 8h.01" />
        </EmptyIcon>
        <EmptyTitle>Nothing selected</EmptyTitle>
        <EmptySubtitle>Click an album, artist, or song to see details</EmptySubtitle>
      </EmptyState>
    )
  }

  // Info tab — metadata editor
  if (activeTab === 'info' && (selectedItem.type === 'song' || selectedItem.type === 'album' || selectedItem.type === 'artist')) {
    return <InfoPanel entityType={selectedItem.type} publicId={selectedItem.publicId} />
  }

  if (selectedItem.type === 'album') {
    return <AlbumDetailsPanel publicId={selectedItem.publicId} />
  }

  if (selectedItem.type === 'artist') {
    return <ArtistDetailsPanel publicId={selectedItem.publicId} />
  }

  if (selectedItem.type === 'song') {
    return <SongPreviewPanel publicId={selectedItem.publicId} />
  }

  return (
    <EmptyState>
      <EmptyTitle>Select an album, artist, or song to see details</EmptyTitle>
    </EmptyState>
  )
}
