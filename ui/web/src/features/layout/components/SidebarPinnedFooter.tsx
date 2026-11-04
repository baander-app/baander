import { Popover } from 'radix-ui'
import { NotificationBell } from '@/features/notification/components/NotificationBell'
import { NotificationPopout } from '@/features/notification/components/NotificationPopout'
import { useNotificationStore } from '@/features/notification/stores/notification-store'
import styled from 'styled-components'
import { NavLink } from 'react-router-dom'
import { useMediaModeStore, type MediaType } from '../stores/media-mode-store'
import { textRole } from '@/shared/theme'

const FooterWrapper = styled.div`
  margin-top: auto;
  @media (max-width: 767px) {
    &[data-collapsed="true"] { padding: 0.5rem; }
    &[data-collapsed="true"] [data-utility-links] { display: none; }
    &[data-collapsed="true"] > div { justify-content: center; }
  }
  flex-shrink: 0;
  border-top: 1px solid var(--color-border);
  padding: 0.5rem 1rem;
`

const FooterLinks = styled.div`
  display: flex;
  align-items: center;
  gap: 0.375rem;
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

const StyledNavLink = styled(NavLink)`
  display: flex;
  align-items: center;
  gap: 0.25rem;
  color: var(--color-muted-foreground);
  text-decoration: none;
  transition: color 60ms ease-out;

  &:hover {
    color: var(--color-foreground);
  }
`

const Separator = styled.span`
  color: var(--color-muted-foreground);
`

const UtilityLink = styled.span`
  display: contents;
`

interface PinnedLink {
  label: string
  route: string
  icon: string
}

const PINNED_FOOTER_LINKS: Record<MediaType, PinnedLink[]> = {
  music: [
    { label: 'Settings', route: '/settings', icon: 'settings' },
    { label: 'Equalizer', route: '/equalizer', icon: 'sliders-horizontal' },
  ],
  movies: [
    { label: 'Settings', route: '/settings', icon: 'settings' },
  ],
  tv: [
    { label: 'Settings', route: '/settings', icon: 'settings' },
  ],
  podcasts: [
    { label: 'Settings', route: '/settings', icon: 'settings' },
  ],
  concerts: [
    { label: 'Settings', route: '/settings', icon: 'settings' },
  ],
  ebooks: [
    { label: 'Settings', route: '/settings', icon: 'settings' },
  ],
}

export function SidebarPinnedFooter({ collapsed = false }: { collapsed?: boolean }) {
  const isOpen = useNotificationStore((s) => s.isPopoutOpen)
  const setOpen = useNotificationStore((s) => s.setPopoutOpen)
  const activeMedia = useMediaModeStore((s) => s.activeMedia)
  const links = PINNED_FOOTER_LINKS[activeMedia]

  return (
    <FooterWrapper data-sidebar-utilities data-collapsed={collapsed}>
      <FooterLinks>
        {links.map((link, i) => (
          <UtilityLink data-utility-links key={link.route}>
            {i > 0 && <Separator>·</Separator>}
            <StyledNavLink to={link.route}>
              {link.label}
            </StyledNavLink>
          </UtilityLink>
        ))}
        <Popover.Root open={isOpen} onOpenChange={setOpen}>
          <Popover.Trigger asChild>
            <NotificationBell />
          </Popover.Trigger>
          <NotificationPopout />
        </Popover.Root>
      </FooterLinks>
    </FooterWrapper>
  )
}
