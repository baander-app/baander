import styled from 'styled-components'
import { textRole } from '@/shared/theme'
import type { ActivityEntry } from '../types/activity'
import { ActivityItem } from './ActivityItem'

const Group = styled.div`
  display: flex;
  flex-direction: column;
`

const PeriodHeader = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 1rem 0.5rem 0.5rem;
`

const PeriodLabel = styled.span`
  ${textRole('label')}
  color: var(--color-muted-foreground);
`

const Divider = styled.div`
  height: 1px;
  flex: 1;
  background-color: var(--color-border);
`

const ItemsContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
`

interface ActivityGroupProps {
  label: string
  items: ActivityEntry[]
}

export function ActivityGroup({ label, items }: ActivityGroupProps) {
  return (
    <Group>
      {/* Period header */}
      <PeriodHeader>
        <PeriodLabel>{label}</PeriodLabel>
        <Divider />
      </PeriodHeader>

      {/* Items */}
      <ItemsContainer>
        {items.map((entry) => (
          <ActivityItem key={entry.publicId} entry={entry} />
        ))}
      </ItemsContainer>
    </Group>
  )
}
