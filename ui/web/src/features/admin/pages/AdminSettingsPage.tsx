import styled from 'styled-components'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/shared/components/ui/tabs'
import { useTabParam } from '@/shared/hooks/use-tab-search-params'
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check'
import { SystemSettingsPanel } from '../components/settings/SystemSettingsPanel'
import { ConfigurationPage } from './ConfigurationPage'

const SETTINGS_TABS = ['general', 'config-health'] as const

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

export function AdminSettingsPage() {
  const { isSuperAdmin } = useAdminCheck()
  const validTabs = isSuperAdmin
    ? (SETTINGS_TABS as readonly string[])
    : SETTINGS_TABS.filter((t) => t !== 'config-health')
  const [tab, setTab] = useTabParam('general', validTabs)

  return (
    <Container>
      <Header>
        <Title>Settings</Title>
      </Header>

      <StyledTabs value={tab} onValueChange={setTab}>
        <TabBar>
          <TabsList variant="line">
            <TabsTrigger value="general">General</TabsTrigger>
            {isSuperAdmin && <TabsTrigger value="config-health">Config Health</TabsTrigger>}
          </TabsList>
        </TabBar>

        <StyledTabsContent value="general">
          <SystemSettingsPanel />
        </StyledTabsContent>
        {isSuperAdmin && (
          <StyledTabsContent value="config-health">
            <ConfigurationPage />
          </StyledTabsContent>
        )}
      </StyledTabs>
    </Container>
  )
}
