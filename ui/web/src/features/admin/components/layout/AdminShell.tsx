import styled, { css } from 'styled-components'
import { Outlet, useLocation } from 'react-router-dom'
import { AdminSidebar } from './AdminSidebar'
import { AdminNotificationBell } from './AdminNotificationBell'
import { ErrorBoundary } from '@/shared/components/ErrorBoundary'

const ShellLayout = styled.div<{ $bounded: boolean }>`
  display: flex;
  height: 100vh;
  ${({ $bounded }) => $bounded && css`
    height: 100dvh;
    overflow: hidden;
  `}
  @media (max-width: 640px) { flex-direction: column; }
  background-color: var(--color-background);
`

const MainColumn = styled.div`
  min-width: 0;
  min-height: 0;
  display: flex;
  flex: 1;
  flex-direction: column;
  overflow: hidden;
`

const HeaderBar = styled.header`
  display: flex;
  height: 3rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: flex-end;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 40%, transparent);
  padding: 0 1rem;
`

const MainContent = styled.main<{ $bounded: boolean }>`
  flex: 1;
  overflow-y: auto;
  ${({ $bounded }) => $bounded && css`
    display: flex;
    flex-direction: column;
    min-height: 0;
    min-width: 0;
    overflow: hidden;
  `}
`

export function AdminShell() {
  const { pathname } = useLocation()
  const bounded = pathname === '/admin/spans' || pathname === '/admin/spans/'

  return (
    <ShellLayout $bounded={bounded}>
      <AdminSidebar />
      <MainColumn>
        <HeaderBar>
          <AdminNotificationBell />
        </HeaderBar>
        <MainContent $bounded={bounded}>
          <ErrorBoundary>
            <Outlet />
          </ErrorBoundary>
        </MainContent>
      </MainColumn>
    </ShellLayout>
  )
}
