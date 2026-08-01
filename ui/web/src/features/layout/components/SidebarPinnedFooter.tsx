import styled from 'styled-components'
import { NavLink } from 'react-router-dom'
import { useMediaModeStore, type MediaType } from '../stores/media-mode-store'

const FooterWrapper = styled.div`
  flex-shrink: 0;
  border-top: 1px solid var(--color-border);
  padding: 0.5rem 1rem;
`

const FooterLinks = styled.div`
  display: flex;
  align-items: center;
  gap: 0.375rem;
  font-size: 0.75rem;
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

export function SidebarPinnedFooter() {
  const activeMedia = useMediaModeStore((s) => s.activeMedia)
  const links = PINNED_FOOTER_LINKS[activeMedia]

  return (
    <FooterWrapper>
      <FooterLinks>
        {links.map((link, i) => (
          <span key={link.route} style={{ display: 'contents' }}>
            {i > 0 && <Separator>·</Separator>}
            <StyledNavLink to={link.route}>
              {link.label}
            </StyledNavLink>
          </span>
        ))}
      </FooterLinks>
    </FooterWrapper>
  )
}
