import { useState, useEffect } from 'react'
import styled, { css } from 'styled-components'
import { mediator } from '@/shared/lib/mediator/bus'
import { useDevPanelStore } from '@/shared/stores/dev-panel-store'
import { ActionTimeline } from './ActionTimeline'
import { HandlerMap } from './HandlerMap'
import { StoreInspector } from './StoreInspector'
import { StoreTimeline } from './StoreTimeline'
import { storeDebugger, setStoreDebugEnabled } from '@/shared/stores/debug'
import { Button } from '@/shared/components/ui/button'
import { focusVisibleRing } from '@/shared/theme'

type Tab = 'timeline' | 'handlers' | 'inspector' | 'stores'

const ToggleButton = styled.button`
  position: fixed;
  bottom: 1rem;
  right: 1rem;
  z-index: 50;
  padding: 0.375rem 0.75rem;
  font-size: 0.75rem;
  font-family: var(--font-mono);
  background-color: color-mix(in srgb, var(--color-muted) 80%, transparent);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  backdrop-filter: blur(4px);
  cursor: pointer;

  &:hover {
    background-color: var(--color-muted);
  }
`

const Panel = styled.div`
  position: fixed;
  bottom: 0;
  right: 0;
  z-index: 50;
  width: min(900px, 100vw);
  height: min(600px, 80vh);
  background-color: var(--color-background);
  border: 1px solid var(--color-border);
  border-bottom: none;
  border-right: none;
  border-top-left-radius: var(--radius-lg);
  display: flex;
  flex-direction: column;
`

const PanelHeader = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.5rem 0.75rem;
  border-bottom: 1px solid var(--color-border);
`

const TabGroup = styled.div`
  display: flex;
  gap: 0.25rem;
`

const TabButton = styled.button<{ $active: boolean }>`
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
  border-radius: var(--radius-md);
  cursor: pointer;
  border: 0;
  background: transparent;
  &:focus-visible { ${focusVisibleRing} }

  ${props => props.$active
    ? css`
        background-color: var(--color-primary);
        color: var(--color-primary-foreground);
      `
    : css`
        color: var(--color-muted-foreground);
        &:hover { background-color: var(--color-muted); }
      `
  }
`

const HeaderActions = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const ActionCount = styled.span`
  font-size: 10px;
  color: var(--color-muted-foreground);
  font-family: var(--font-mono);
`

const CloseButton = styled.button`
  color: var(--color-muted-foreground);
  font-size: 0.75rem;
  cursor: pointer;
  border: 0;
  background: transparent;
  &:focus-visible { ${focusVisibleRing} }

  &:hover {
    color: var(--color-foreground);
  }
`

const PanelContent = styled.div`
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  > button { align-self: flex-start; flex-shrink: 0; margin: 8px 16px; }
`

/**
 * Collapsible debug panel for the cross-context mediator.
 * Shows action timeline, handler registrations, and store state.
 * Toggle visibility from the Diagnostics admin page.
 */
export function MediatorDevPanel() {
  const [isOpen, setIsOpen] = useState(false)
  const [activeTab, setActiveTab] = useState<Tab>('timeline')
  const [log, setLog] = useState(() => mediator.getActionLog())
  const [handlerMap, setHandlerMap] = useState(() => mediator.getHandlerMap())
  const visible = useDevPanelStore((s) => s.visible)

  // Live updates via mediator subscription
  useEffect(() => {
    if (!isOpen || !visible) return

    const unsub = mediator.subscribe(() => {
      setLog(mediator.getActionLog())
      setHandlerMap(mediator.getHandlerMap())
    })

    return unsub
  }, [isOpen, visible])

  const tabs: { id: Tab; label: string }[] = [
    { id: 'timeline', label: 'Action Timeline' },
    { id: 'handlers', label: 'Handlers' },
    { id: 'inspector', label: 'Store Inspector' },
    { id: 'stores', label: 'Store Timeline' },
  ]

  if (!visible) return null

  if (!isOpen) {
    return (
      <ToggleButton
        onClick={() => {
          setLog(mediator.getActionLog())
          setHandlerMap(mediator.getHandlerMap())
          setIsOpen(true)
        }}
        aria-label="Toggle debug panel"
      >
        🐛 Debug
      </ToggleButton>
    )
  }

  return (
    <Panel>
      {/* Header */}
      <PanelHeader>
        <TabGroup>
          {tabs.map((tab) => (
            <TabButton
              key={tab.id}
              $active={activeTab === tab.id}
              aria-pressed={activeTab === tab.id}
              onClick={() => setActiveTab(tab.id)}
            >
              {tab.label}
            </TabButton>
          ))}
        </TabGroup>
        <HeaderActions>
          {(activeTab === 'timeline' || activeTab === 'handlers') && <ActionCount>{log.length} mediator actions</ActionCount>}
          <CloseButton
            onClick={() => setIsOpen(false)}
            aria-label="Close debug panel"
          >
            ✕
          </CloseButton>
        </HeaderActions>
      </PanelHeader>

      {/* Tab content */}
      <PanelContent>
        {activeTab === 'timeline' && <ActionTimeline log={log} />}
        {activeTab === 'handlers' && <HandlerMap handlerMap={handlerMap} />}
        {(activeTab === 'inspector' || activeTab === 'stores') && !storeDebugger.enabled && (
          <div>
            <p>Store recording is disabled. Enabling it reloads the app and adds tracing overhead.</p>
            {import.meta.env.DEV ? <Button onClick={() => setStoreDebugEnabled(true)}>Enable store tracing and reload</Button>
              : <p>Use a development build to enable recording.</p>}
          </div>
        )}
        {storeDebugger.enabled && (activeTab === 'inspector' || activeTab === 'stores') && (
          <Button size="sm" onClick={() => setStoreDebugEnabled(false)}>Disable store tracing and reload</Button>
        )}
        {activeTab === 'inspector' && storeDebugger.enabled && <StoreInspector />}
        {activeTab === 'stores' && storeDebugger.enabled && <StoreTimeline />}
      </PanelContent>
    </Panel>
  )
}
