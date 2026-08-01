import styled from 'styled-components'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/shared/components/ui/tabs'
import { useTabParam } from '@/shared/hooks/use-tab-search-params'
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check'
import { AdminDashboardPage } from './AdminDashboardPage'
import { JobMonitorPage } from './JobMonitorPage'
import { SchedulerPage } from './SchedulerPage'
import { RateLimitersPage } from './RateLimitersPage'
import { ServerDiagnosticsPage } from './ServerDiagnosticsPage'

const ALL_TABS = ['dashboard', 'jobs', 'scheduler', 'rate-limits', 'diagnostics'] as const

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

export function AdminOverviewPage() {
  const { isSuperAdmin } = useAdminCheck()
  const validTabs = isSuperAdmin
    ? (ALL_TABS as readonly string[])
    : ALL_TABS.filter((t) => t !== 'diagnostics')
  const [tab, setTab] = useTabParam('dashboard', validTabs)

  return (
    <Container>
      <Header>
        <Title>Overview</Title>
      </Header>

      <StyledTabs value={tab} onValueChange={setTab}>
        <TabBar>
          <TabsList variant="line">
            <TabsTrigger value="dashboard">Dashboard</TabsTrigger>
            <TabsTrigger value="jobs">Jobs</TabsTrigger>
            <TabsTrigger value="scheduler">Scheduler</TabsTrigger>
            <TabsTrigger value="rate-limits">Rate Limits</TabsTrigger>
            {isSuperAdmin && <TabsTrigger value="diagnostics">Diagnostics</TabsTrigger>}
          </TabsList>
        </TabBar>

        <StyledTabsContent value="dashboard">
          <AdminDashboardPage />
        </StyledTabsContent>
        <StyledTabsContent value="jobs">
          <JobMonitorPage />
        </StyledTabsContent>
        <StyledTabsContent value="scheduler">
          <SchedulerPage />
        </StyledTabsContent>
        <StyledTabsContent value="rate-limits">
          <RateLimitersPage />
        </StyledTabsContent>
        {isSuperAdmin && (
          <StyledTabsContent value="diagnostics">
            <ServerDiagnosticsPage />
          </StyledTabsContent>
        )}
      </StyledTabs>
    </Container>
  )
}
