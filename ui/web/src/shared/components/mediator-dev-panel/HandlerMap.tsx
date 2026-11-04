import { useState, useEffect } from 'react'
import styled from 'styled-components'
import { mediator } from '@/shared/lib/mediator/bus'
import { textRole } from '@/shared/theme'

interface HandlerMapProps {
  handlerMap?: Record<string, string[]>
}

const Container = styled.div`
  overflow-y: auto;
  padding: 0.5rem;
`

const Group = styled.div`
  margin-bottom: 0.75rem;
`

const GroupTitle = styled.h4`
  ${textRole('label')}
  color: var(--color-muted-foreground);
  margin-bottom: 0.25rem;
  padding: 0 0.25rem;
`

const ActionRow = styled.div`
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.25rem 0.5rem;
  ${textRole('mono-sm')}
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 30%, transparent);
`

const ActionName = styled.span`
  font-weight: 600;
  color: var(--color-primary);
  min-width: 10rem;
`

const HandlerList = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
`

const HandlerBadge = styled.span`
  padding: 0.125rem 0.375rem;
  background-color: var(--color-muted);
  border-radius: var(--radius-md);
`

const EmptyState = styled.div`
  padding: 1rem;
  ${textRole('body')}
  color: var(--color-muted-foreground);
  text-align: center;
`

export function HandlerMap({ handlerMap: externalMap }: HandlerMapProps) {
  const [handlerMap, setHandlerMap] = useState<Record<string, string[]>>(
    externalMap ?? {},
  )
  const usingExternal = externalMap !== undefined

  useEffect(() => {
    if (usingExternal) return
    const interval = setInterval(() => {
      setHandlerMap(mediator.getHandlerMap())
    }, 2000)
    return () => clearInterval(interval)
  }, [usingExternal])

  const entries = Object.entries(handlerMap).sort(([a], [b]) => a.localeCompare(b))

  if (entries.length === 0) {
    return <EmptyState>No handlers registered.</EmptyState>
  }

  // Group by prefix (e.g., 'player', 'radio', 'settings')
  const grouped = entries.reduce<Record<string, [string, string[]][]>>(
    (acc, [action, handlers]) => {
      const prefix = action.split(':')[0]
      if (!acc[prefix]) acc[prefix] = []
      acc[prefix].push([action, handlers])
      return acc
    },
    {},
  )

  return (
    <Container>
      {Object.entries(grouped).map(([prefix, actions]) => (
        <Group key={prefix}>
          <GroupTitle>{prefix}</GroupTitle>
          {actions.map(([action, handlers]) => (
            <ActionRow key={action}>
              <ActionName>{action}</ActionName>
              <HandlerList>
                {handlers.map((h) => (
                  <HandlerBadge key={h}>{h}</HandlerBadge>
                ))}
              </HandlerList>
            </ActionRow>
          ))}
        </Group>
      ))}
    </Container>
  )
}
