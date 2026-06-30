import styled from 'styled-components'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useContextPanelStore } from '../stores/context-panel-store'
import { useSidebarStore } from '../stores/sidebar-store'
import { useMediaModeStore } from '../stores/media-mode-store'
import { useSidebarConfig } from '../hooks/use-sidebar-config'
import { SidebarSelector } from './SidebarSelector'
import { SidebarContent } from './SidebarContent'
import { SidebarPinnedFooter } from './SidebarPinnedFooter'
import { Input } from '@/shared/components/ui/input'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { Search, Settings2 } from 'lucide-react'

const SearchInput = styled(Input)`
  height: 1.75rem;
  padding-left: 2rem;
  font-size: 0.75rem;
`

const NavSkeleton = styled(Skeleton)`
  height: 2rem;
  width: 100%;
  border-radius: var(--radius-md);
`

const SidebarAside = styled.aside`
  display: flex;
  height: 100%;
  width: 14rem;
  flex-shrink: 0;
  flex-direction: column;
  background-color: var(--color-sidebar);
`

const LogoRow = styled.div`
  display: flex;
  height: 3rem;
  align-items: center;
  justify-content: space-between;
  padding: 0 1rem;
`

const LogoLink = styled.a`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  text-decoration: none;
`

const LogoImg = styled.img`
  height: 1.75rem;
  width: 1.75rem;
`

const LogoText = styled.span`
  font-size: 1rem;
  font-weight: 600;
  letter-spacing: -0.025em;
  color: var(--color-sidebar-foreground);
`

const SearchForm = styled.form`
  padding: 0.5rem 0.75rem;
`

const SearchWrapper = styled.div`
  position: relative;
`

const SearchIcon = styled(Search)`
  position: absolute;
  left: 0.625rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--color-muted-foreground);
`

const LoadingZone = styled.div`
  min-height: 0;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  overflow-y: auto;
  padding: 0 0.5rem;
`

const LoadingInner = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const ConfigureWrapper = styled.div`
  flex-shrink: 0;
  border-top: 1px solid var(--color-border);
  padding: 0.5rem;
`

const ConfigureButton = styled.button`
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.5rem;
  border-radius: var(--radius-md);
  padding: 0.375rem 0.625rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
  background: none;
  border: none;
  cursor: pointer;
  transition: background-color 150ms ease, color 150ms ease;

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
    color: var(--color-accent-foreground);
  }
`

// Admin navigation lives at /admin with its own AdminSidebar.
// A discrete link to /admin is shown on the Settings page for ROLE_ADMIN users.

export function Sidebar() {
  const [query, setQuery] = useState('')
  const navigate = useNavigate()
  const { isLoading } = useSidebarConfig()
  const activeMedia = useMediaModeStore((s) => s.activeMedia)
  const { setActiveTab, setMode } = useContextPanelStore()

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault()
    if (query.trim()) {
      navigate(`/search?q=${encodeURIComponent(query.trim())}&scope=${activeMedia}`)
    }
  }

  const handleItemClick = (item: { type: string; config?: Record<string, unknown> }) => {
    if (item.type === 'panel_action') {
      const tab = item.config?.tab as string | undefined
      if (tab) {
        setActiveTab(tab as 'queue' | 'lyrics' | 'details')
        setMode('expanded')
      }
    }
  }

  return (
    <SidebarAside>
      {/* Logo */}
      <LogoRow>
        <LogoLink href="/">
          <LogoImg src="/logo.svg" alt="Bånder" />
          <LogoText>Bånder</LogoText>
        </LogoLink>
      </LogoRow>

      {/* Search */}
      <SearchForm onSubmit={handleSearch}>
        <SearchWrapper>
          <SearchIcon size={14} />
          <SearchInput
            type="text"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search..."
          />
        </SearchWrapper>
      </SearchForm>

      {/* Media Type Selector */}
      <SidebarSelector />

      {/* Content Zone — sectioned navigation */}
      {isLoading ? (
        <LoadingZone>
          <LoadingInner>
            {Array.from({ length: 6 }).map((_, i) => (
              <NavSkeleton key={i} />
            ))}
          </LoadingInner>
        </LoadingZone>
      ) : (
        <SidebarContent onItemClick={handleItemClick} />
      )}

      {/* Pinned Footer */}
      <SidebarPinnedFooter />

      {/* Configure sidebar */}
      <ConfigureWrapper>
        <ConfigureButton
          type="button"
          onClick={() => useSidebarStore.getState().setEditorOpen(true)}
        >
          <Settings2 size={14} />
          <span>Customize</span>
        </ConfigureButton>
      </ConfigureWrapper>
    </SidebarAside>
  )
}
