import { useId } from 'react'
import styled from 'styled-components'
import { SectionCard } from '@/shared/components/section-card'
import { Switch } from '@/shared/components/ui/switch'
import { useDevPanelStore } from '@/shared/stores/dev-panel-store'

const Row = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-md);
  padding: 0.625rem var(--space-md);
  font-size: 0.875rem;
`

const Label = styled.label`
  color: var(--color-muted-foreground);
`

const Hint = styled.p`
  margin-top: 0.125rem;
  font-size: 0.75rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 60%, transparent);
`

export function DeveloperToolsCard() {
  const visible = useDevPanelStore((state) => state.visible)
  const setVisible = useDevPanelStore((state) => state.setVisible)
  const switchId = useId()

  return (
    <SectionCard title="Developer Tools">
      <Row>
        <div>
          <Label htmlFor={switchId}>Mediator debug panel</Label>
          <Hint>Cross-context action timeline, handler map, and store inspector</Hint>
        </div>
        <Switch id={switchId} checked={visible} onCheckedChange={setVisible} />
      </Row>
    </SectionCard>
  )
}
