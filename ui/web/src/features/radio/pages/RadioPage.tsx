import { useState } from 'react'
import styled from 'styled-components'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/shared/components/ui/tabs'
import { Globe, Radio, Star } from 'lucide-react'
import { CountryPicker } from '../components/CountryPicker'
import { StationBrowser } from '../components/StationBrowser'
import { StarredStations } from '../components/StarredStations'

const PageContainer = styled.div`
  display: flex;
  flex-direction: column;
  height: 100%;
`

const PageHeader = styled.div`
  border-bottom: 1px solid var(--color-border);
  padding: 1rem 1.5rem;
`

const PageTitle = styled.h1`
  font-size: 1.125rem;
  font-weight: 600;
`

const PageDescription = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const TabBar = styled.div`
  border-bottom: 1px solid var(--color-border);
  padding: 0 1.5rem;
`

const StyledTabs = styled(Tabs)`
  display: flex;
  flex: 1;
  flex-direction: column;
`

const TabContent = styled(TabsContent)`
  flex: 1;
  overflow-y: auto;
  padding: 1.5rem;
`

export function RadioPage() {
  const [tab, setTab] = useState('countries')

  return (
    <PageContainer>
      <PageHeader>
        <PageTitle>Radio</PageTitle>
        <PageDescription>
          Browse internet radio stations from around the world.
        </PageDescription>
      </PageHeader>

      <StyledTabs value={tab} onValueChange={setTab}>
        <TabBar>
          <TabsList>
            <TabsTrigger value="countries" style={{ gap: '0.375rem' }}>
              <Globe size={14} />
              Countries
            </TabsTrigger>
            <TabsTrigger value="stations" style={{ gap: '0.375rem' }}>
              <Radio size={14} />
              Stations
            </TabsTrigger>
            <TabsTrigger value="starred" style={{ gap: '0.375rem' }}>
              <Star size={14} />
              Starred
            </TabsTrigger>
          </TabsList>
        </TabBar>

        <TabContent value="countries">
          <CountryPicker />
        </TabContent>
        <TabContent value="stations">
          <StationBrowser />
        </TabContent>
        <TabContent value="starred">
          <StarredStations />
        </TabContent>
      </StyledTabs>
    </PageContainer>
  )
}
