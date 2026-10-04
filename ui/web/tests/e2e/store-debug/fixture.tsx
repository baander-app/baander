import React, { Profiler } from 'react'
import { createRoot } from 'react-dom/client'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { GlobalStyles } from '@/shared/theme/GlobalStyles'
import { MediatorDevPanel } from '@/shared/components/mediator-dev-panel'
import { useDevPanelStore } from '@/shared/stores/dev-panel-store'
import { useSelectionStore } from '@/features/catalog/stores/selection-store'
import { useViewModeStore } from '@/features/catalog/stores/view-mode-store'

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
export function Actions() {
  const select = useSelectionStore(state => state.select)
  return <button onClick={() => select('album-one', 'album')}>Select album</button>
}
createRoot(document.getElementById('root')!).render(<ThemeProvider theme={resolveTheme('dark', 'violet')}>
  <GlobalStyles />
  <main><h1>Store debugger fixture</h1>
    <Profiler id="selection" onRender={recordRender}><Selection /></Profiler>
    <Profiler id="action" onRender={recordRender}><Actions /></Profiler>
    <p>Selection renders: <output data-testid="selection-renders" /> · Action renders: <output data-testid="action-renders" /></p>
    <button onClick={() => useViewModeStore.getState().setViewMode('list')}>Change view</button>
  </main>
  <MediatorDevPanel />
</ThemeProvider>)
