import styled, { css } from 'styled-components'
import { NavLink, useLocation } from 'react-router-dom'
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check'
import { ArrowLeft } from 'lucide-react'
import { interactiveTransition } from '@/shared/theme'

interface NavItem {
  to: string
  label: string
  end?: boolean
  superAdminOnly?: boolean
}

const navItems: NavItem[] = [
  { to: '/admin', label: 'Overview', end: true },
  { to: '/admin/library', label: 'Library' },
  { to: '/admin/security', label: 'Security' },
  { to: '/admin/media', label: 'Media' },
  { to: '/admin/radio', label: 'Radio' },
  { to: '/admin/analytics', label: 'Analytics' },
  { to: '/admin/settings', label: 'Settings', superAdminOnly: true },
]

const Sidebar = styled.aside`
  display: flex;
  height: 100%;
  width: 13rem;
  flex-shrink: 0;
  flex-direction: column;
  border-right: 1px solid color-mix(in srgb, var(--color-border) 50%, transparent);
  background-color: var(--color-sidebar);
`

const BrandBar = styled.div`
  display: flex;
  height: 3rem;
  align-items: center;
  gap: 0.5rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 40%, transparent);
  padding: 0 1rem;
`

const BrandLogo = styled.img`
  height: 1.5rem;
  width: 1.5rem;
`

const BrandText = styled.span`
  font-size: 0.875rem;
  font-weight: 600;
  letter-spacing: -0.01em;
  color: var(--color-sidebar-foreground);
`

const Nav = styled.nav`
  flex: 1;
  padding: 0.5rem 0.25rem;
  overflow-y: auto;

  & > a {
    display: block;
    margin-bottom: 0.125rem;
  }
`

const NavLinkStyled = styled(NavLink)<{ $active: boolean }>`
  display: block;
  border-radius: 0.375rem;
  padding: 0.375rem 0.625rem;
  font-size: 13px;
  ${interactiveTransition(['color', 'background-color'])}

  ${({ $active }) => $active
    ? css`
        background-color: color-mix(in srgb, var(--color-primary) 10%, transparent);
        color: var(--color-primary);
        font-weight: 500;
      `
    : css`
        color: var(--color-muted-foreground);
        &:hover {
          background-color: color-mix(in srgb, var(--color-primary) 5%, transparent);
          color: var(--color-foreground);
        }
      `
  }
`

const BottomSection = styled.div`
  border-top: 1px solid color-mix(in srgb, var(--color-border) 40%, transparent);
  padding: 0.5rem;
`

const BackLink = styled(NavLink)`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border-radius: 0.375rem;
  padding: 0.375rem 0.625rem;
  font-size: 13px;
  color: var(--color-muted-foreground);
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
    color: var(--color-accent-foreground);
  }
`

export function AdminSidebar() {
  const location = useLocation()
  const { isSuperAdmin } = useAdminCheck()

  return (
    <Sidebar>
      <BrandBar>
        <BrandLogo src="/logo.svg" alt="Bånder" />
        <BrandText>Admin</BrandText>
      </BrandBar>

      <Nav>
        {navItems.map(({ to, label, end, superAdminOnly }) => {
          if (superAdminOnly && !isSuperAdmin) return null

          const isActive = end
            ? location.pathname === to
            : location.pathname.startsWith(to)

          return (
            <NavLinkStyled key={to} to={to} end={end} $active={isActive}>
              {label}
            </NavLinkStyled>
          )
        })}
      </Nav>

      <BottomSection>
        <BackLink to="/">
          <ArrowLeft size={15} strokeWidth={1.5} />
          Back to app
        </BackLink>
      </BottomSection>
    </Sidebar>
  )
}
