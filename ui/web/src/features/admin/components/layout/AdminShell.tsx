import styled from 'styled-components'
import { Outlet } from 'react-router-dom'
import { AdminSidebar } from './AdminSidebar'
import { AdminNotificationBell } from './AdminNotificationBell'
import { ErrorBoundary } from '@/shared/components/ErrorBoundary'

const ShellLayout = styled.div`
  display: flex;
  height: 100vh;
  background-color: var(--color-background);
`

const MainColumn = styled.div`
  display: flex;
  flex: 1;
  flex-direction: column;
  overflow: hidden;
`

const HeaderBar = styled.header`
  display: flex;
  height: 3rem;
  align-items: center;
  justify-content: flex-end;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 40%, transparent);
  padding: 0 1rem;
`

const MainContent = styled.main`
  flex: 1;
  overflow-y: auto;
`

export function AdminShell() {
  return (
    <ShellLayout>
      <AdminSidebar />
      <MainColumn>
        <HeaderBar>
          <AdminNotificationBell />
        </HeaderBar>
        <MainContent>
          <ErrorBoundary>
            <Outlet />
          </ErrorBoundary>
        </MainContent>
      </MainColumn>
    </ShellLayout>
  )
}
