import { useSearchParams } from 'react-router-dom'
import { Search } from 'lucide-react'
import styled from 'styled-components'
import { SearchResults } from '../components/SearchResults'
import { useMediaModeStore } from '@/features/layout/stores/media-mode-store'

const PageContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
`

const TopBar = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.5rem;
`

const Title = styled.h1`
  font-size: 1.125rem;
  font-weight: 600;
  letter-spacing: -0.025em;
`

const ScopeToggle = styled.button`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
  transition: color var(--duration-hover) ease-out;
  background: none;
  border: none;
  cursor: pointer;

  &:hover {
    color: var(--color-foreground);
  }
`

const ContentArea = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 0 1.5rem 1.5rem;
`

const EmptyState = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 5rem 0;
`

const EmptyText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

export function SearchPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const query = searchParams.get('q') ?? ''
  const scope = searchParams.get('scope') ?? 'all'
  const activeMedia = useMediaModeStore((s) => s.activeMedia)

  const toggleScope = () => {
    setSearchParams((prev) => {
      prev.set('scope', scope === 'all' ? activeMedia : 'all')
      return prev
    })
  }

  return (
    <PageContainer>
      <TopBar>
        <Title>Search</Title>
        {query && (
          <ScopeToggle type="button" onClick={toggleScope}>
            {scope === 'all' ? 'All' : scope.charAt(0).toUpperCase() + scope.slice(1)}
            {' · '}
            {scope === 'all' ? `Switch to ${activeMedia}` : 'Switch to all'}
          </ScopeToggle>
        )}
      </TopBar>

      <ContentArea>
        {!query ? (
          <EmptyState>
            <Search size={32} strokeWidth={1.5} style={{ color: 'color-mix(in srgb, var(--color-muted-foreground) 20%, transparent)' }} />
            <EmptyText>Search for albums, artists, or songs</EmptyText>
          </EmptyState>
        ) : (
          <SearchResults query={query} />
        )}
      </ContentArea>
    </PageContainer>
  )
}
