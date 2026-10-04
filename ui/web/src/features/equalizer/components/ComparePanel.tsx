import { captureEqSettings, applyEqSettings } from '../stores/eq-settings'
import { useEqCompareStore, type EqSnapshot } from '../stores/eq-compare-store'
import { Button } from '@/shared/components/ui/button'
import styled from 'styled-components'

function captureCurrentState(label: string): EqSnapshot {
  const { bands, ...processing } = captureEqSettings()
  return { id: crypto.randomUUID(), label, timestamp: Date.now(), bands, processing }
}

function restoreSnapshot(snapshot: EqSnapshot) {
  applyEqSettings({ ...snapshot.processing, bands: snapshot.bands })
}

const SlotColumn = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
`

const SlotHeader = styled.div`
  display: flex;
  align-items: center;
  gap: 0.375rem;
`

const Timestamp = styled.span`
  font-size: 10px;
  color: var(--color-muted-foreground);
  text-align: center;
`

const Grid = styled.div`
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
`

const ToggleRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const Wrapper = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

function SlotButton({ slot, label }: { slot: 'A' | 'B'; label: string }) {
  const snapshot = useEqCompareStore((s) => slot === 'A' ? s.slotA : s.slotB)
  const activeSlot = useEqCompareStore((s) => s.activeSlot)
  const captureSlot = useEqCompareStore((s) => s.captureSlot)
  const setActiveSlot = useEqCompareStore((s) => s.setActiveSlot)
  const clearSlot = useEqCompareStore((s) => s.clearSlot)
  const isActive = activeSlot === slot

  const handleCapture = () => {
    captureSlot(slot, captureCurrentState(`Slot ${slot}`))
  }

  const handleActivate = () => {
    if (snapshot) {
      setActiveSlot(slot)
      restoreSnapshot(snapshot)
    }
  }

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation()
    clearSlot(slot)
  }

  return (
    <SlotColumn>
      <SlotHeader>
        <Button
          variant={isActive ? 'secondary' : snapshot ? 'outline' : 'ghost'}
          size="xs"
          onClick={snapshot ? handleActivate : handleCapture}
          style={{ flex: 1, justifyContent: 'center', ...(isActive ? { boxShadow: '0 0 0 1px var(--color-primary)' } : {}) }}
        >
          {label}
        </Button>
        {snapshot && !isActive && (
          <Button variant="ghost" size="xs" onClick={handleCapture} title="Re-capture">
            Re-cap
          </Button>
        )}
        {snapshot && (
          <Button variant="ghost" size="xs" onClick={handleClear} title="Clear">
            x
          </Button>
        )}
      </SlotHeader>
      {snapshot && (
        <Timestamp>
          {new Date(snapshot.timestamp).toLocaleTimeString()}
        </Timestamp>
      )}
      {!snapshot && (
        <Timestamp>
          Click to capture
        </Timestamp>
      )}
    </SlotColumn>
  )
}

export function ComparePanel() {
  const slotA = useEqCompareStore((s) => s.slotA)
  const slotB = useEqCompareStore((s) => s.slotB)
  const activeSlot = useEqCompareStore((s) => s.activeSlot)
  const setActiveSlot = useEqCompareStore((s) => s.setActiveSlot)
  const clearAll = useEqCompareStore((s) => s.clearAll)

  return (
    <Wrapper>
      <Grid>
        <SlotButton slot="A" label={slotA ? `A: ${slotA.label}` : 'Slot A'} />
        <SlotButton slot="B" label={slotB ? `B: ${slotB.label}` : 'Slot B'} />
      </Grid>

      {slotA && slotB && (
        <ToggleRow>
          <Button
            variant="ghost"
            size="xs"
            style={{ flex: 1 }}
            onClick={() => {
              const next = activeSlot === 'A' ? 'B' : 'A'
              setActiveSlot(next)
              const snapshot = next === 'A' ? slotA : slotB
              if (snapshot) restoreSnapshot(snapshot)
            }}
          >
            Toggle A/B
          </Button>
          <Button variant="ghost" size="xs" onClick={clearAll}>
            Clear All
          </Button>
        </ToggleRow>
      )}
    </Wrapper>
  )
}
