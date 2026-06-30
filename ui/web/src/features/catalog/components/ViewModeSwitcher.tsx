import styled, { css } from 'styled-components'
import { useCallback, useEffect } from 'react'
import {
  LayoutGrid,
  List,
  Columns3,
  Clock,
  Activity,
  Compass,
} from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/shared/components/ui/tooltip'
import {
  useViewModeStore,
  type ViewMode,
  VIEW_MODES,
} from '../stores/view-mode-store'

const Toolbar = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const ViewButton = styled(Button)<{ $isActive: boolean }>`
  height: 2rem;
  width: 2rem;

  ${({ $isActive }) =>
    $isActive
      ? css`color: var(--color-primary);`
      : css`color: var(--color-muted-foreground);`}
`

const ShortcutKey = styled.kbd`
  margin-left: 0.25rem;
  font-size: 11px;
  opacity: 0.6;
`

interface ViewModeOption {
  mode: ViewMode
  label: string
  shortcut: string
  icon: React.ComponentType<{ className?: string }>
}

const OPTIONS: ViewModeOption[] = [
  { mode: 'grid', label: 'Grid', shortcut: '1', icon: LayoutGrid },
  { mode: 'list', label: 'List', shortcut: '2', icon: List },
  { mode: 'columns', label: 'Columns', shortcut: '3', icon: Columns3 },
  { mode: 'timeline', label: 'Timeline', shortcut: '4', icon: Clock },
  { mode: 'activity', label: 'Activity', shortcut: '5', icon: Activity },
  { mode: 'discover', label: 'Discover', shortcut: '6', icon: Compass },
]

export function ViewModeSwitcher() {
  const viewMode = useViewModeStore((s) => s.viewMode)
  const setViewMode = useViewModeStore((s) => s.setViewMode)

  const handleKeyDown = useCallback(
    (e: KeyboardEvent) => {
      if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) return
      if ((e.target as HTMLElement)?.isContentEditable) return

      const num = parseInt(e.key, 10)
      if (num >= 1 && num <= 6) {
        e.preventDefault()
        setViewMode(VIEW_MODES[num - 1])
      }
    },
    [setViewMode],
  )

  useEffect(() => {
    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [handleKeyDown])

  return (
    <TooltipProvider delayDuration={300}>
      <Toolbar role="toolbar" aria-label="View mode">
        {OPTIONS.map(({ mode, label, shortcut, icon: Icon }) => {
          const isActive = viewMode === mode
          return (
            <Tooltip key={mode}>
              <TooltipTrigger asChild>
                <ViewButton
                  variant="ghost"
                  size="icon"
                  $isActive={isActive}
                  onClick={() => setViewMode(mode)}
                  aria-label={`${label} view`}
                  aria-pressed={isActive}
                >
                  <Icon style={{ height: '1rem', width: '1rem' }} />
                </ViewButton>
              </TooltipTrigger>
              <TooltipContent side="bottom">
                {label} <ShortcutKey>{shortcut}</ShortcutKey>
              </TooltipContent>
            </Tooltip>
          )
        })}
      </Toolbar>
    </TooltipProvider>
  )
}
