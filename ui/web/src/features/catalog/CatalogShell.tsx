import styled from 'styled-components'
import { useViewModeStore } from './stores/view-mode-store'
import { ViewModeSwitcher } from './components/ViewModeSwitcher'
import { GridView } from './views/GridView'
import { ListView } from './views/ListView'
import { ColumnBrowserView } from './views/ColumnBrowserView'
import { TimelineView } from './views/TimelineView'
import { ActivityView } from './views/ActivityView'
import { DiscoverView } from './views/DiscoverView'

const Shell = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
`

const Toolbar = styled.div`
  display: flex;
  height: 3rem;
  flex-shrink: 0;
  align-items: center;
  border-bottom: 1px solid var(--color-border);
  padding: 0 1.5rem;
`

const Content = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 1.5rem;
  transition: opacity 80ms ease-out;
`

const VIEW_COMPONENTS: Record<string, React.ComponentType> = {
  grid: GridView,
  list: ListView,
  columns: ColumnBrowserView,
  timeline: TimelineView,
  activity: ActivityView,
  discover: DiscoverView,
}

export function CatalogShell() {
  const viewMode = useViewModeStore((s) => s.viewMode)
  const ViewComponent = VIEW_COMPONENTS[viewMode] ?? GridView

  return (
    <Shell>
      <Toolbar>
        <ViewModeSwitcher />
      </Toolbar>
      <Content>
        <ViewComponent />
      </Content>
    </Shell>
  )
}
