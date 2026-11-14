import { useState } from 'react'
import styled from 'styled-components'
import { textRole } from '@/shared/theme'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/shared/components/ui/tabs'
import { useTabParam } from '@/shared/hooks/use-tab-search-params'
import { LibrariesPage } from '@/features/library/pages/LibrariesPage'
import { MetadataPage } from './MetadataPage'
import { GenresPage } from './GenresPage'
import { AlbumDuplicatesPage } from './AlbumDuplicatesPage'
import { LyricsAdminPage } from './LyricsAdminPage'
import { MatchReviewTable } from '../components/matching/MatchReviewTable'
import type { MatchReview } from '../api/match-reviews-api'
import { MusicBrainzMatchDialog } from '@/features/catalog/components/metadata/MusicBrainzMatchDialog'

const LIBRARY_TABS = ['libraries', 'metadata', 'matching', 'genres', 'duplicates', 'lyrics'] as const

const Container = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
`

const Header = styled.div`
  border-bottom: 1px solid var(--color-border);
  padding: 1rem 1.5rem;
`

const Title = styled.h1`
  ${textRole('title')}
`

const TabBar = styled.div`
  border-bottom: 1px solid var(--color-border);
  padding: 0 1.5rem;
`

const StyledTabs = styled(Tabs)`
  display: flex;
  flex: 1 1 0;
  flex-direction: column;
`

const StyledTabsContent = styled(TabsContent)`
  flex: 1 1 0;
  overflow-y: auto;
`

export function AdminLibraryPage() {
  const [tab, setTab] = useTabParam('libraries', LIBRARY_TABS)
  const [matching, setMatching] = useState<MatchReview | null>(null)

  return (
    <Container>
      <Header>
        <Title>Library</Title>
      </Header>

      <StyledTabs value={tab} onValueChange={setTab}>
        <TabBar>
          <TabsList variant="line">
            <TabsTrigger value="libraries">Libraries</TabsTrigger>
            <TabsTrigger value="metadata">Metadata</TabsTrigger>
            <TabsTrigger value="matching">Matching</TabsTrigger>
            <TabsTrigger value="genres">Genres</TabsTrigger>
            <TabsTrigger value="duplicates">Duplicates</TabsTrigger>
            <TabsTrigger value="lyrics">Lyrics</TabsTrigger>
          </TabsList>
        </TabBar>

        <StyledTabsContent value="libraries">
          <LibrariesPage />
        </StyledTabsContent>
        <StyledTabsContent value="metadata">
          <MetadataPage />
        </StyledTabsContent>
        <StyledTabsContent value="matching">
          <MatchReviewTable onMatchAlbum={setMatching} />
        </StyledTabsContent>
        <StyledTabsContent value="genres">
          <GenresPage />
        </StyledTabsContent>
        <StyledTabsContent value="duplicates">
          <AlbumDuplicatesPage />
        </StyledTabsContent>
        <StyledTabsContent value="lyrics">
          <LyricsAdminPage />
        </StyledTabsContent>
      </StyledTabs>

      {matching !== null && (
        <MusicBrainzMatchDialog
          key={matching.albumPublicId}
          open
          onOpenChange={(open) => {
            if (!open) setMatching(null)
          }}
          type="album"
          publicId={matching.albumPublicId}
          title={matching.title}
        />
      )}
    </Container>
  )
}
