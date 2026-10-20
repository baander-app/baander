import React, { Profiler } from 'react'
import { createRoot } from 'react-dom/client'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { GlobalStyles } from '@/shared/theme/GlobalStyles'
import { MediatorDevPanel } from '@/shared/components/mediator-dev-panel'
import { useDevPanelStore } from '@/shared/stores/dev-panel-store'
import { useSelectionStore } from '@/features/catalog/stores/selection-store'
import { useViewModeStore } from '@/features/catalog/stores/view-mode-store'
import { createStore } from 'zustand/vanilla'
import { Button } from '@/shared/components/ui/button'
import { storeDebugger } from '@/shared/stores/debug'

useDevPanelStore.getState().setVisible(true)

const renders: Record<string, number> = {}
function recordRender(id: string) {
  renders[id] = (renders[id] ?? 0) + 1
  document.querySelector(`[data-testid="${id}-renders"]`)!.textContent = String(renders[id])
}
export function Selection() {
  const selected = useSelectionStore(state => state.selectedId)
  return <p>Selection: <output data-testid="selection">{selected ?? 'none'}</output></p>
}
function ViewMode() {
  const mode = useViewModeStore(state => state.viewMode)
  return <p>View: <output data-testid="view-mode">{mode}</output></p>
}
export function Actions() {
  const select = useSelectionStore(state => state.select)
  const clear = useSelectionStore(state => state.clear)
  return <>
    <button onClick={() => select('album-one', 'album')}>Select album</button>
    <button onClick={() => select('album-two', 'album')}>Select another album</button>
    <Button onClick={() => clear()}>Clear selection</Button>
  </>
}
createRoot(document.getElementById('root')!).render(<ThemeProvider theme={resolveTheme('dark', 'violet')}>
  <GlobalStyles />
  <main><h1>Store debugger fixture</h1>
    <Profiler id="selection" onRender={recordRender}><Selection /></Profiler>
    <ViewMode />
    <Profiler id="action" onRender={recordRender}><Actions /></Profiler>
    <p>Selection renders: <output data-testid="selection-renders" /> · Action renders: <output data-testid="action-renders" /></p>
    <button onClick={() => {
      const { viewMode, setViewMode } = useViewModeStore.getState()
      setViewMode(viewMode === 'list' ? 'grid' : 'list')
    }}>Change view</button>
    <button onClick={() => {
      for (let index = 0; index < 97; index++) {
        createStore(storeDebugger.instrument(`fixture.store.${index}`, () => ({ count: index })))
      }
    }}>Register fixture stores</button>
  </main>
  <MediatorDevPanel />
</ThemeProvider>)
