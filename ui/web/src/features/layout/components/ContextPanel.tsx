import styled, { css } from 'styled-components'
import { useEffect, useState } from 'react'
import { ListMusic, FileText, Info, Pencil } from 'lucide-react'
import { useContextPanelStore } from '../stores/context-panel-store'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/shared/components/ui/tabs'
import { Separator } from '@/shared/components/ui/separator'
import { QueueTab } from './QueueTab'
import { LyricsTab } from './LyricsTab'
import { DetailsTab } from './DetailsTab'
import { PlayerBar } from './PlayerBar'
import { NotificationBell } from '@/features/notification/components/NotificationBell'
import { NotificationPopout } from '@/features/notification/components/NotificationPopout'
import { useNotifications } from '@/features/notification/hooks/use-notifications'
import { DevicePicker } from '@/features/session/components/DevicePicker'

const ExpandedTabs = styled(Tabs)`
  display: flex;
  flex: 1;
  flex-direction: column;
  overflow: hidden;
`

const FullWidthTabsList = styled(TabsList)`
  width: 100%;
`

const PanelTabTrigger = styled(TabsTrigger)`
  flex: 1;
  gap: 0.375rem;
  font-size: 0.75rem;
  background: transparent !important;

  &[data-active] {
    background: transparent !important;
  }

  &:hover {
    background: color-mix(in srgb, var(--color-foreground) 8%, transparent) !important;
  }
`

const TabLabel = styled.span`
  display: none;
  @media (min-width: 1280px) {
    display: inline;
  }
`

const PanelTabsContent = styled(TabsContent)<{ $scrollable?: boolean }>`
  margin-top: 0;
  min-height: 0;
  flex: 1;
  padding: 0.75rem;
  ${({ $scrollable }) => $scrollable && css`overflow-y: auto;`}
`

const PanelAside = styled.aside`
  position: relative;
  display: flex;
  height: 100%;
  flex-shrink: 0;
  flex-direction: column;
  border-left: 1px solid var(--color-border);
  background-color: var(--color-sidebar);
`

const ResizeContainer = styled.div`
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 0.25rem;
  cursor: col-resize;
  z-index: 10;
`

const ResizeLine = styled.div`
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 1px;
  background-color: var(--color-border);
  opacity: 0;
  transition: opacity 100ms ease-out;

  ${ResizeContainer}:hover & {
    opacity: 1;
  }
`

const ResizeHitArea = styled.div`
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 0.25rem;
  transition: background-color 100ms ease-out;

  &:hover {
    background-color: color-mix(in srgb, var(--color-primary) 20%, transparent);
  }
`

const PanelHeader = styled.div`
  position: relative;
  display: flex;
  height: 2.5rem;
  align-items: center;
  justify-content: space-between;
  padding: 0 0.75rem;
`

const CompactLabel = styled.span`
  font-size: 0.75rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const HeaderActions = styled.div`
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const TabBarWrapper = styled.div`
  padding: 0.5rem 0.75rem 0;
`

const TabContentZone = styled.div`
  display: flex;
  min-height: 0;
  flex: 1;
  flex-direction: column;
`

const TabContentInner = styled.div`
  margin-top: 0;
  min-height: 0;
  flex: 1;
  overflow-y: auto;
  padding: 0.75rem;
`

const MIN_WIDTH = 240
const MAX_WIDTH = 600
const SIDEBAR_WIDTH = 224 // w-56 = 14rem = 224px

function useMaxAvailableWidth() {
  const [available, setAvailable] = useState(() => window.innerWidth - SIDEBAR_WIDTH - MIN_WIDTH)
  useEffect(() => {
    const handleResize = () => setAvailable(window.innerWidth - SIDEBAR_WIDTH - MIN_WIDTH)
    window.addEventListener('resize', handleResize)
    return () => window.removeEventListener('resize', handleResize)
  }, [])
  return available
}

function ResizeHandle({ width, onWidthChange }: { width: number; onWidthChange: (width: number) => void }) {
  const handleMouseDown = (e: React.MouseEvent) => {
    e.preventDefault()
    const startX = e.clientX
    const startWidth = width

    const handleMouseMove = (moveEvent: MouseEvent) => {
      const next = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, startWidth + (startX - moveEvent.clientX)))
      onWidthChange(next)
    }

    const handleMouseUp = () => {
      document.removeEventListener('mousemove', handleMouseMove)
      document.removeEventListener('mouseup', handleMouseUp)
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }

    document.body.style.cursor = 'col-resize'
    document.body.style.userSelect = 'none'
    document.addEventListener('mousemove', handleMouseMove)
    document.addEventListener('mouseup', handleMouseUp)
  }

  return (
    <ResizeContainer
      onMouseDown={handleMouseDown}
    >
      <ResizeLine />
      <ResizeHitArea />
    </ResizeContainer>
  )
}

export function ContextPanel() {
  const mode = useContextPanelStore((s) => s.mode)
  const activeTab = useContextPanelStore((s) => s.activeTab)
  const isOpen = useContextPanelStore((s) => s.isOpen)
  const width = useContextPanelStore((s) => s.width)
  const toggleMode = useContextPanelStore((s) => s.toggleMode)
  const setActiveTab = useContextPanelStore((s) => s.setActiveTab)
  const setWidth = useContextPanelStore((s) => s.setWidth)

  // Initialize notification fetching
  useNotifications()

  const maxAvailable = useMaxAvailableWidth()
  const effectiveMax = Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, maxAvailable))

  if (!isOpen) return null

  // Compact mode uses fixed width, resizable only in expanded
  const panelWidth = mode === 'compact' ? 280 : Math.min(width, effectiveMax)

  return (
    <PanelAside style={{ width: panelWidth }}>
      {mode !== 'compact' && <ResizeHandle width={width} onWidthChange={(w) => setWidth(Math.min(w, effectiveMax))} />}

      {/* Panel header — notification bell replaces "Now Playing" text */}
      <PanelHeader>
        {mode === 'compact' ? (
          <CompactLabel>Now Playing</CompactLabel>
        ) : (
          <div />
        )}
        <HeaderActions>
          <DevicePicker />
          <NotificationBell />
          <NotificationPopout />
        </HeaderActions>
      </PanelHeader>

      <Separator />

      <ExpandedTabs value={activeTab} onValueChange={(v) => setActiveTab(v as typeof activeTab)}>
        <TabBarWrapper>
          <FullWidthTabsList variant="line">
            <PanelTabTrigger value="queue">
              <ListMusic size={12} />
              <TabLabel>Queue</TabLabel>
            </PanelTabTrigger>
            <PanelTabTrigger value="lyrics">
              <FileText size={12} />
              <TabLabel>Lyrics</TabLabel>
            </PanelTabTrigger>
            <PanelTabTrigger value="details">
              <Info size={12} />
              <TabLabel>Details</TabLabel>
            </PanelTabTrigger>
            <PanelTabTrigger value="info">
              <Pencil size={12} />
              <TabLabel>Edit</TabLabel>
            </PanelTabTrigger>
          </FullWidthTabsList>
        </TabBarWrapper>

        <TabContentZone>
          <PanelTabsContent value="queue" $scrollable>
            <QueueTab />
          </PanelTabsContent>
          <PanelTabsContent value="lyrics">
            <LyricsTab />
          </PanelTabsContent>
          <PanelTabsContent value="details" $scrollable>
            <DetailsTab />
          </PanelTabsContent>
          <PanelTabsContent value="info" $scrollable>
            <DetailsTab />
          </PanelTabsContent>
        </TabContentZone>
      </ExpandedTabs>

      {mode !== 'compact' && (
        <>
          <Separator />
          <PlayerBar />
        </>
      )}
    </PanelAside>
  )
}
