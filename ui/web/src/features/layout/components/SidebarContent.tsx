import styled from 'styled-components'
import { useSidebarStore, type SidebarItemData } from '../stores/sidebar-store'
import { useMediaModeStore } from '../stores/media-mode-store'
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

export function SidebarContent({ onItemClick }: SidebarContentProps) {
  const activeMedia = useMediaModeStore((s) => s.activeMedia)
  const schema = useSidebarStore((s) => s.schemas[activeMedia])
  const { items: recentItems } = useRecentItems({ limit: 5, mediaType: activeMedia })

  return (
    <Nav>
      {schema.sections
        .filter((section) => section.type === 'navigation')
        .map((section, index) => (
          <SidebarSection
            key={section.id}
            id={section.id}
            label={section.label}
            items={section.items}
            isFirst={index === 0}
            onItemClick={onItemClick}
          />
        ))}
      <SidebarRecentItems items={recentItems} />
    </Nav>
  )
}
