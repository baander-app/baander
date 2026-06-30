import { useState } from 'react'
import { useEqProcessingStore, type ProcessingModule, DEFAULT_CHAIN_ORDER } from '../stores/eq-processing-store'
import styled, { css } from 'styled-components'
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core'
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'

const MODULE_META: Record<ProcessingModule, { label: string; abbr: string }> = {
  eq: { label: 'EQ', abbr: 'EQ' },
  compressor: { label: 'Compressor', abbr: 'CMP' },
  stereo: { label: 'Stereo Width', abbr: 'STR' },
  crossfeed: { label: 'Crossfeed', abbr: 'XF' },
  loudness: { label: 'Loudness', abbr: 'LDN' },
  masterGain: { label: 'Master Gain', abbr: 'GN' },
}

const SlotWrapper = styled.div<{ $isDragging: boolean; $expanded: boolean }>`
  border-radius: var(--radius-sm);
  border-left: 2px solid;
  background-color: var(--color-card);
  transition: background-color 0.15s;

  ${(p) => p.$isDragging && css`opacity: 0.6; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);`}
  ${(p) => p.$expanded
    ? css`border-left-color: var(--color-primary);`
    : css`border-left-color: var(--color-border);`
  }
`

const SlotHeader = styled.div`
  display: flex;
  align-items: center;
  height: 2rem;
  padding: 0 0.5rem;
  gap: 0.5rem;
`

const DragHandle = styled.button`
  cursor: grab;
  flex-shrink: 0;
  color: var(--color-muted-foreground);
  border: none;
  background: none;
  padding: 0;

  &:hover { color: var(--color-foreground); }
  &:active { cursor: grabbing; }
`

const SlotName = styled.button`
  flex: 1;
  text-align: left;
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.025em;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  border: none;
  background: none;
  color: inherit;
  cursor: pointer;
  padding: 0;
`

const ExpandedContent = styled.div`
  padding: 0 0.5rem 0.5rem;
  padding-top: 0.25rem;
  border-top: 1px solid rgba(var(--color-border-rgb, 128 128 128), 0.3);
`

const BypassButton = styled.button<{ $enabled: boolean }>`
  width: 1rem;
  height: 1rem;
  border-radius: 9999px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: 8px;
  font-weight: 700;
  border: none;
  cursor: pointer;
  transition: background-color 0.15s, color 0.15s;

  ${(p) => p.$enabled
    ? css`background-color: var(--color-primary); color: var(--color-primary-foreground);`
    : css`background-color: var(--color-muted); color: var(--color-muted-foreground);`
  }
`

const ControlsCol = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
`

const SliderRowWrapper = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.125rem 0;
`

const SliderLabel = styled.span`
  font-size: 10px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
  width: 3rem;
  flex-shrink: 0;
`

const SliderValue = styled.span`
  width: 3.5rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
  font-size: 10px;
  flex-shrink: 0;
`

const ModeRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const ModeButton = styled.button<{ $active: boolean }>`
  padding: 0.125rem 0.5rem;
  border-radius: var(--radius-sm);
  font-size: 10px;
  font-weight: 500;
  border: none;
  cursor: pointer;
  transition: background-color 0.15s, color 0.15s;

  ${(p) => p.$active
    ? css`background-color: rgba(var(--color-primary-rgb, 0 0 0), 0.2); color: var(--color-primary);`
    : css`color: var(--color-muted-foreground); &:hover { color: var(--color-foreground); }`
  }
`

const HintText = styled.span`
  font-size: 10px;
  color: var(--color-muted-foreground);
`

const NormalizeRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.125rem 0;
`

const TargetRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.125rem;
`

const TargetButton = styled.button<{ $active: boolean }>`
  padding: 0.125rem 0.375rem;
  border-radius: var(--radius-sm);
  font-size: 10px;
  font-variant-numeric: tabular-nums;
  border: none;
  cursor: pointer;
  transition: background-color 0.15s, color 0.15s;

  ${(p) => p.$active
    ? css`background-color: rgba(var(--color-primary-rgb, 0 0 0), 0.2); color: var(--color-primary);`
    : css`color: var(--color-muted-foreground); &:hover { color: var(--color-foreground); }`
  }
`

const ChainList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1px;
  background-color: rgba(var(--color-border-rgb, 128 128 128), 0.3);
  border-radius: var(--radius-sm);
  overflow: hidden;
`

const Wrapper = styled.div`
  display: flex;
  flex-direction: column;
`

const Footer = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 0.375rem;
  padding: 0 0.25rem;
`

const FooterHint = styled.span`
  font-size: 9px;
  color: var(--color-muted-foreground);
`

const ResetButton = styled.button`
  font-size: 10px;
  color: var(--color-muted-foreground);
  border: none;
  background: none;
  cursor: pointer;
  transition: color 0.15s;

  &:hover { color: var(--color-foreground); }
`

function SortableSlot({ module, expanded, onToggle }: {
  module: ProcessingModule
  expanded: boolean
  onToggle: () => void
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: module })
  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
  }
  const meta = MODULE_META[module]

  return (
    <SlotWrapper
      ref={setNodeRef}
      $isDragging={isDragging}
      $expanded={expanded}
      style={style}
    >
      {/* Slot header bar */}
      <SlotHeader>
        {/* Drag handle */}
        <DragHandle {...attributes} {...listeners} tabIndex={-1}>
          <svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor">
            <circle cx="3" cy="2" r="1.2" />
            <circle cx="7" cy="2" r="1.2" />
            <circle cx="3" cy="7" r="1.2" />
            <circle cx="7" cy="7" r="1.2" />
            <circle cx="3" cy="12" r="1.2" />
            <circle cx="7" cy="12" r="1.2" />
          </svg>
        </DragHandle>

        {/* Slot name — click to expand */}
        <SlotName onClick={onToggle}>
          {meta.label}
        </SlotName>

        {/* Module bypass */}
        <ModuleBypass module={module} />
      </SlotHeader>

      {/* Expanded controls */}
      {expanded && (
        <ExpandedContent>
          <ModuleControls module={module} />
        </ExpandedContent>
      )}
    </SlotWrapper>
  )
}

function ModuleBypass({ module }: { module: ProcessingModule }) {
  // Each module has its own enabled state in the processing store
  const enabled = useModuleEnabled(module)
  const toggle = useModuleToggle(module)

  if (!toggle) return null

  return (
    <BypassButton
      onClick={toggle}
      $enabled={enabled}
      title={enabled ? 'Bypass' : 'Enable'}
    >
      {enabled ? 'B' : 'B'}
    </BypassButton>
  )
}

function useModuleEnabled(module: ProcessingModule): boolean {
  const compressionEnabled = useEqProcessingStore((s) => s.compressionEnabled)
  const stereoEnabled = useEqProcessingStore((s) => s.stereoEnabled)
  const crossfeedEnabled = useEqProcessingStore((s) => s.crossfeedEnabled)
  const loudnessContourEnabled = useEqProcessingStore((s) => s.loudnessContourEnabled)

  switch (module) {
    case 'eq': return true // EQ enabled state is in bands store
    case 'compressor': return compressionEnabled
    case 'stereo': return stereoEnabled
    case 'crossfeed': return crossfeedEnabled
    case 'loudness': return loudnessContourEnabled
    case 'masterGain': return true // always active
    default: return false
  }
}

function useModuleToggle(module: ProcessingModule): (() => void) | null {
  const setCompressionEnabled = useEqProcessingStore((s) => s.setCompressionEnabled)
  const setStereoEnabled = useEqProcessingStore((s) => s.setStereoEnabled)
  const setCrossfeedEnabled = useEqProcessingStore((s) => s.setCrossfeedEnabled)
  const setLoudnessContourEnabled = useEqProcessingStore((s) => s.setLoudnessContourEnabled)

  switch (module) {
    case 'compressor':
      return () => setCompressionEnabled(!useEqProcessingStore.getState().compressionEnabled)
    case 'stereo':
      return () => setStereoEnabled(!useEqProcessingStore.getState().stereoEnabled)
    case 'crossfeed':
      return () => setCrossfeedEnabled(!useEqProcessingStore.getState().crossfeedEnabled)
    case 'loudness':
      return () => setLoudnessContourEnabled(!useEqProcessingStore.getState().loudnessContourEnabled)
    default:
      return null
  }
}

function ModuleControls({ module }: { module: ProcessingModule }) {
  switch (module) {
    case 'compressor': return <CompressorControls />
    case 'stereo': return <StereoControls />
    case 'crossfeed': return <CrossfeedControls />
    case 'loudness': return <LoudnessControls />
    case 'masterGain': return <MasterGainControls />
    case 'eq': return <EqInfoControls />
    default: return null
  }
}

// Import Slider
import { Slider } from '@/shared/components/ui/slider'

function SliderRow({ label, value, min, max, step, unit, onChange }: {
  label: string
  value: number
  min: number
  max: number
  step: number
  unit: string
  onChange: (v: number) => void
}) {
  return (
    <SliderRowWrapper>
      <SliderLabel>{label}</SliderLabel>
      <Slider
        min={min}
        max={max}
        step={step}
        value={[value]}
        onValueChange={([v]) => onChange(v)}
        aria-label={label}
        style={{ flex: 1 }}
      />
      <SliderValue>{value.toFixed(step < 1 ? 1 : 0)}{unit}</SliderValue>
    </SliderRowWrapper>
  )
}

function CompressorControls() {
  const threshold = useEqProcessingStore((s) => s.compressorThreshold)
  const ratio = useEqProcessingStore((s) => s.compressorRatio)
  const knee = useEqProcessingStore((s) => s.compressorKnee)
  const attack = useEqProcessingStore((s) => s.compressorAttack)
  const release = useEqProcessingStore((s) => s.compressorRelease)
  const setParams = useEqProcessingStore((s) => s.setCompressorParams)

  return (
    <ControlsCol>
      <SliderRow label="Thresh" value={threshold} min={-50} max={0} step={1} unit="dB"
        onChange={(v) => setParams({ threshold: v })} />
      <SliderRow label="Ratio" value={ratio} min={1} max={20} step={0.5} unit=":1"
        onChange={(v) => setParams({ ratio: v })} />
      <SliderRow label="Knee" value={knee} min={0} max={40} step={1} unit="dB"
        onChange={(v) => setParams({ knee: v })} />
      <SliderRow label="Attack" value={attack} min={0.1} max={100} step={0.1} unit="ms"
        onChange={(v) => setParams({ attack: v })} />
      <SliderRow label="Release" value={release} min={10} max={1000} step={10} unit="ms"
        onChange={(v) => setParams({ release: v })} />
    </ControlsCol>
  )
}

function StereoControls() {
  const stereoWidth = useEqProcessingStore((s) => s.stereoWidth)
  const stereoMode = useEqProcessingStore((s) => s.stereoMode)
  const setStereoWidth = useEqProcessingStore((s) => s.setStereoWidth)
  const setStereoMode = useEqProcessingStore((s) => s.setStereoMode)

  return (
    <ControlsCol style={{ gap: '0.25rem' }}>
      <SliderRow label="Width" value={stereoWidth} min={0} max={2} step={0.05} unit=""
        onChange={setStereoWidth} />
      <ModeRow>
        {(['normal', 'mid', 'side'] as const).map((mode) => (
          <ModeButton
            key={mode}
            $active={stereoMode === mode}
            onClick={() => setStereoMode(mode)}
          >
            {mode}
          </ModeButton>
        ))}
      </ModeRow>
    </ControlsCol>
  )
}

function CrossfeedControls() {
  const crossfeedPreset = useEqProcessingStore((s) => s.crossfeedPreset)
  const setCrossfeedPreset = useEqProcessingStore((s) => s.setCrossfeedPreset)

  return (
    <ModeRow>
      {(['light', 'normal', 'heavy'] as const).map((preset) => (
        <ModeButton
          key={preset}
          $active={crossfeedPreset === preset}
          onClick={() => setCrossfeedPreset(preset)}
        >
          {preset}
        </ModeButton>
      ))}
    </ModeRow>
  )
}

function LoudnessControls() {
  return (
    <HintText>Boosts bass and treble at low volume (ISO 226)</HintText>
  )
}

function MasterGainControls() {
  const masterGain = useEqProcessingStore((s) => s.masterGain)
  const setMasterGain = useEqProcessingStore((s) => s.setMasterGain)
  const normalizationEnabled = useEqProcessingStore((s) => s.normalizationEnabled)
  const targetLufs = useEqProcessingStore((s) => s.targetLufs)
  const setNormalizationEnabled = useEqProcessingStore((s) => s.setNormalizationEnabled)
  const setTargetLufs = useEqProcessingStore((s) => s.setTargetLufs)

  return (
    <ControlsCol style={{ gap: '0.25rem' }}>
      <SliderRow label="Gain" value={masterGain} min={-12} max={12} step={0.5} unit="dB"
        onChange={setMasterGain} />
      <NormalizeRow>
        <ModeButton
          $active={normalizationEnabled}
          onClick={() => setNormalizationEnabled(!normalizationEnabled)}
        >
          Normalize
        </ModeButton>
        {normalizationEnabled && (
          <TargetRow>
            {([-14, -16, -18, -23] as const).map((val) => (
              <TargetButton
                key={val}
                $active={targetLufs === val}
                onClick={() => setTargetLufs(val)}
              >
                {val}
              </TargetButton>
            ))}
          </TargetRow>
        )}
      </NormalizeRow>
    </ControlsCol>
  )
}

function EqInfoControls() {
  return (
    <HintText>10-band parametric EQ configured above</HintText>
  )
}

export function ProcessingPanel() {
  const chainOrder = useEqProcessingStore((s) => s.chainOrder)
  const setChainOrder = useEqProcessingStore((s) => s.setChainOrder)
  const [expanded, setExpanded] = useState<Set<ProcessingModule>>(new Set())

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )

  const toggleExpand = (module: ProcessingModule) => {
    setExpanded((prev) => {
      const next = new Set(prev)
      if (next.has(module)) next.delete(module)
      else next.add(module)
      return next
    })
  }

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event
    if (over && active.id !== over.id) {
      const oldIndex = chainOrder.indexOf(active.id as ProcessingModule)
      const newIndex = chainOrder.indexOf(over.id as ProcessingModule)
      setChainOrder(arrayMove(chainOrder, oldIndex, newIndex))
    }
  }

  return (
    <Wrapper>
      <DndContext
        sensors={sensors}
        collisionDetection={closestCenter}
        onDragEnd={handleDragEnd}
      >
        <SortableContext
          items={chainOrder}
          strategy={verticalListSortingStrategy}
        >
          <ChainList>
            {chainOrder.map((module) => (
              <SortableSlot
                key={module}
                module={module}
                expanded={expanded.has(module)}
                onToggle={() => toggleExpand(module)}
              />
            ))}
          </ChainList>
        </SortableContext>
      </DndContext>
      <Footer>
        <FooterHint>Signal flows top to bottom. Drag to reorder.</FooterHint>
        <ResetButton onClick={() => setChainOrder([...DEFAULT_CHAIN_ORDER])}>
          Reset
        </ResetButton>
      </Footer>
    </Wrapper>
  )
}
