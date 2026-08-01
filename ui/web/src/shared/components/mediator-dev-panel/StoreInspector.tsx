import { useState, useEffect } from 'react'
import styled from 'styled-components'
import { STORE_REGISTRY } from '@/shared/lib/mediator/store-registry'
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/shared/components/ui/select'

interface StoreInspectorProps {
  /** Override store snapshot sources. Keyed by display name. */
  stores?: Record<string, () => Record<string, unknown>>
}

const Container = styled.div`
  display: flex;
  flex-direction: column;
  height: 100%;
`

const SelectBar = styled.div`
  padding: 0.5rem;
  border-bottom: 1px solid var(--color-border);
`

const Content = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 0.5rem;
`

const JsonPre = styled.pre`
  font-size: 0.75rem;
  font-family: var(--font-mono);
  white-space: pre-wrap;
  word-break: break-word;
`

const EmptyState = styled.div`
  padding: 1rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
  text-align: center;
`

/**
 * Displays current state of any registered store as a JSON tree.
 * Auto-refreshes on selection change.
 */
export function StoreInspector({ stores: externalRegistry }: StoreInspectorProps) {
  const registry = externalRegistry ?? STORE_REGISTRY
  const storeNames = Object.keys(registry).sort()
  const [selected, setSelected] = useState('')
  const [snapshot, setSnapshot] = useState<Record<string, unknown> | null>(null)

  // Refresh snapshot when selection changes
  useEffect(() => {
    if (!selected || !registry[selected]) {
      setSnapshot(null)
      return
    }

    // Initial snapshot
    setSnapshot(registry[selected]())

    // Poll for changes while selected (stores don't emit events we can hook here)
    const interval = setInterval(() => {
      setSnapshot(registry[selected]())
    }, 1000)

    return () => clearInterval(interval)
  }, [selected, registry])

  return (
    <Container>
      <SelectBar>
        <Select value={selected} onValueChange={setSelected}>
          <SelectTrigger aria-label="Select store">
            <SelectValue placeholder="Select store..." />
          </SelectTrigger>
          <SelectContent>
            {storeNames.map((name) => (
              <SelectItem key={name} value={name}>
                {name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </SelectBar>
      <Content>
        {snapshot ? (
          <JsonPre>{JSON.stringify(snapshot, null, 2)}</JsonPre>
        ) : (
          <EmptyState>Select a store to inspect its state.</EmptyState>
        )}
      </Content>
    </Container>
  )
}
