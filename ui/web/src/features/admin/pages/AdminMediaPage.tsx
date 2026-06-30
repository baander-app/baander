import styled from 'styled-components'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/shared/components/ui/tabs'
import { useTabParam } from '@/shared/hooks/use-tab-search-params'
import { TranscodePage } from './TranscodePage'
import { MediaPage } from './MediaPage'

const MEDIA_TABS = ['transcode', 'storage'] as const

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
  font-size: 1.125rem;
  font-weight: 600;
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

export function AdminMediaPage() {
  const [tab, setTab] = useTabParam('transcode', MEDIA_TABS)

  return (
    <Container>
      <Header>
        <Title>Media</Title>
      </Header>

      <StyledTabs value={tab} onValueChange={setTab}>
        <TabBar>
          <TabsList variant="line">
            <TabsTrigger value="transcode">Transcode</TabsTrigger>
            <TabsTrigger value="storage">Storage</TabsTrigger>
          </TabsList>
        </TabBar>

        <StyledTabsContent value="transcode">
          <TranscodePage />
        </StyledTabsContent>
        <StyledTabsContent value="storage">
          <MediaPage />
        </StyledTabsContent>
      </StyledTabs>
    </Container>
  )
}
