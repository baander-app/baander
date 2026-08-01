import styled from 'styled-components'
import {
  Music,
  Film,
  Tv,
  Podcast,
  Mic2,
  BookOpen,
} from 'lucide-react'
import { useMediaMode } from '../hooks/use-media-mode'
import { MEDIA_TYPES, MEDIA_TYPE_LABELS, type MediaType } from '../stores/media-mode-store'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/shared/components/ui/tooltip'

const MEDIA_TYPE_ICONS: Record<MediaType, React.ComponentType<{ className?: string }>> = {
  music: Music,
  movies: Film,
  tv: Tv,
  podcasts: Podcast,
  concerts: Mic2,
  ebooks: BookOpen,
}

const SmallTooltip = styled(TooltipContent)`
  font-size: 0.75rem;
`

const SelectorWrapper = styled.div`
  padding: 0.5rem 0.75rem;
`

const SelectorBar = styled.div`
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.125rem;
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, var(--color-border) 60%, transparent);
  background-color: color-mix(in srgb, #000 20%, transparent);
  padding: 0.25rem;
  box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
`

const MediaButton = styled.button<{ $isActive: boolean }>`
  display: flex;
  height: 1.75rem;
  width: 1.75rem;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-md);
  border: none;
  background: none;
  cursor: pointer;
  transition: background-color 60ms ease-out, color 60ms ease-out;
  color: ${({ $isActive }) =>
    $isActive
      ? 'var(--color-foreground)'
      : 'var(--color-muted-foreground)'};

  ${({ $isActive }) =>
    $isActive
      ? `background-color: var(--color-accent); box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);`
      : `&:hover { background-color: color-mix(in srgb, var(--color-accent) 50%, transparent); color: var(--color-foreground); }`}
`

const IconWrapper = styled.span`
  height: 1rem;
  width: 1rem;
  display: inline-flex;

  & > svg {
    width: 100%;
    height: 100%;
  }
`

export function SidebarSelector() {
  const { activeMedia, switchMedia } = useMediaMode()

  return (
    <SelectorWrapper role="tablist" aria-label="Media type">
      <TooltipProvider delayDuration={300}>
        <SelectorBar>
          {MEDIA_TYPES.map((mt) => {
            const Icon = MEDIA_TYPE_ICONS[mt]
            const isActive = activeMedia === mt
            return (
              <Tooltip key={mt}>
                <TooltipTrigger asChild>
                  <MediaButton
                    type="button"
                    role="tab"
                    aria-selected={isActive}
                    data-testid={`media-tab-${mt}`}
                    $isActive={isActive}
                    onClick={() => switchMedia(mt)}
                  >
                    <IconWrapper><Icon /></IconWrapper>
                  </MediaButton>
                </TooltipTrigger>
                <SmallTooltip side="bottom">
                  {MEDIA_TYPE_LABELS[mt]}
                </SmallTooltip>
              </Tooltip>
            )
          })}
        </SelectorBar>
      </TooltipProvider>
    </SelectorWrapper>
  )
}
