import type { ReactNode } from 'react'
import { Ellipsis } from 'lucide-react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuTrigger,
} from '@/shared/components/ui/context-menu'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/shared/components/ui/dropdown-menu'

const Target = styled.div`
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  min-width: 0;
`

interface VideoAction {
  label: string
  run: () => void
  disabled?: boolean
}

interface VideoActionMenuProps {
  label: string
  actions: VideoAction[]
  children: ReactNode
  className?: string
}

export function VideoActionMenu({ label, actions, children, className }: VideoActionMenuProps) {
  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>
        <Target className={className}>
          {children}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button type="button" aria-label={label} variant="ghost" size="icon">
                <Ellipsis size={18} aria-hidden="true" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {actions.map(action => (
                <DropdownMenuItem key={action.label} disabled={action.disabled} onSelect={action.run}>
                  {action.label}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        </Target>
      </ContextMenuTrigger>
      <ContextMenuContent>
        {actions.map(action => (
          <ContextMenuItem key={action.label} disabled={action.disabled} onSelect={action.run}>
            {action.label}
          </ContextMenuItem>
        ))}
      </ContextMenuContent>
    </ContextMenu>
  )
}
