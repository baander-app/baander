import { useState, useEffect } from 'react'
import styled, { css } from 'styled-components'
import { mediator } from '@/shared/lib/mediator/bus'
import { useDevPanelStore } from '@/shared/stores/dev-panel-store'
import { ActionTimeline } from './ActionTimeline'
import { HandlerMap } from './HandlerMap'
import { StoreInspector } from './StoreInspector'

type Tab = 'timeline' | 'handlers' | 'inspector'

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
  box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
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
  width: 520px;
  height: 400px;
  background-color: var(--color-background);
  border: 1px solid var(--color-border);
  border-bottom: none;
  border-right: none;
  border-top-left-radius: var(--radius-lg);
  box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.25);
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

  &:hover {
    color: var(--color-foreground);
  }
`

const PanelContent = styled.div`
  flex: 1;
  overflow: hidden;
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
    if (!isOpen) return

    const unsub = mediator.subscribe(() => {
      setLog(mediator.getActionLog())
      setHandlerMap(mediator.getHandlerMap())
    })

    // Sync initial state
    setLog(mediator.getActionLog())
    setHandlerMap(mediator.getHandlerMap())

    return unsub
  }, [isOpen])

  const tabs: { id: Tab; label: string }[] = [
    { id: 'timeline', label: 'Action Timeline' },
    { id: 'handlers', label: 'Handlers' },
    { id: 'inspector', label: 'Store Inspector' },
  ]

  if (!visible) return null

  if (!isOpen) {
    return (
      <ToggleButton
        onClick={() => setIsOpen(true)}
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
              onClick={() => setActiveTab(tab.id)}
            >
              {tab.label}
            </TabButton>
          ))}
        </TabGroup>
        <HeaderActions>
          <ActionCount>{log.length} actions</ActionCount>
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
        {activeTab === 'inspector' && <StoreInspector />}
      </PanelContent>
    </Panel>
  )
}
