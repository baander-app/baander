import styled from 'styled-components'
import { useSidebarStore, type SidebarItemData } from '../stores/sidebar-store'
import { useMediaModeStore } from '../stores/media-mode-store'
import { useLibraries } from '@/features/library/hooks/use-libraries'
import { SidebarSection } from './SidebarSection'
import { SidebarRecentItems } from './SidebarRecentItems'
import { useRecentItems } from '../hooks/use-recent-items'

const Nav = styled.nav`
  min-height: 0;
  flex: 1;
  overflow-y: auto;

  & > * + * {
    margin-top: 0.125rem;
  }
`

interface SidebarContentProps {
  onItemClick?: (item: SidebarItemData) => void
}

function TvLibraryLinks({ id, label }: { id: string; label: string }) {
  const { data: libraries = [] } = useLibraries('tv_show')
  const items = libraries.map((library): SidebarItemData => ({
    id: library.publicId,
    type: 'page_link',
    label: library.name,
    icon: '',
    config: { route: `/tv/shows?library=${encodeURIComponent(library.publicId)}`, library: library.publicId },
  }))

  return <SidebarSection id={id} label={label} items={items} />
}

export function SidebarContent({ onItemClick }: SidebarContentProps) {
  const activeMedia = useMediaModeStore((s) => s.activeMedia)
  const schema = useSidebarStore((s) => s.schemas[activeMedia])
  const { items: recentItems, isLoading: recentLoading } = useRecentItems({ limit: 5, mediaType: activeMedia })

  return (
    <Nav>
      {schema.sections.map((section, index) => {
        if (section.type === 'tv-libraries') return <TvLibraryLinks key={section.id} id={section.id} label={section.label} />
        if (section.type !== 'navigation') return null
        return (
          <SidebarSection
            key={section.id}
            id={section.id}
            label={section.label}
            items={section.items}
            isFirst={index === 0}
            onItemClick={onItemClick}
          />
        )
      })}
      <SidebarRecentItems items={recentItems} isLoading={recentLoading} />
    </Nav>
  )
}
