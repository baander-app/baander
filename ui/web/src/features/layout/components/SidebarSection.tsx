import styled, { css } from 'styled-components'
import { NavLink } from 'react-router-dom'
import type { SidebarItemData } from '../stores/sidebar-store'
import { getSidebarIcon } from '../schemas/icons'

const SectionHeader = styled.div<{ $isFirst: boolean }>`
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-weight: 500;
  color: var(--color-muted-foreground);
  padding: ${({ $isFirst }) =>
    $isFirst ? '1rem 1rem 0.25rem' : '0.75rem 1rem 0.25rem'};
`

const SectionItems = styled.div`
  padding: 0 0.5rem;

  & > * + * {
    margin-top: 0.125rem;
  }
`

const navLinkStyles = css`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border-radius: var(--radius-md);
  padding: 0.375rem 0.625rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
  text-decoration: none;
  transition: background-color 150ms ease, color 150ms ease;

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
    color: var(--color-accent-foreground);
  }

  &.active {
    background-color: var(--color-accent);
    color: var(--color-accent-foreground);
  }
`

const StyledNavLink = styled(NavLink)`
  ${navLinkStyles}
`

const NavIcon = styled.span`
  height: 1rem;
  width: 1rem;
  flex-shrink: 0;
  display: inline-flex;

  & > svg {
    width: 100%;
    height: 100%;
  }
`

const NavLinkLabel = styled.span`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const ActionButton = styled.button`
  ${navLinkStyles}
  width: 100%;
  text-align: left;
  background: none;
  border: none;
  cursor: pointer;
  font: inherit;
`

interface SidebarSectionProps {
  id: string
  label: string
  items: SidebarItemData[]
  isFirst?: boolean
  onItemClick?: (item: SidebarItemData) => void
}

export function SidebarSection({ id, label, items, isFirst = false, onItemClick }: SidebarSectionProps) {
  if (items.length === 0) return null

  return (
    <div role="group" aria-labelledby={`${id}-header`}>
      <SectionHeader id={`${id}-header`} $isFirst={isFirst}>
        {label}
      </SectionHeader>
      <SectionItems>
        {items.map((item) => {
          const Icon = getSidebarIcon(item.icon)

          if (item.type === 'page_link') {
            const route = item.config?.route as string | undefined
            if (!route) return null
            return (
              <StyledNavLink
                key={item.id}
                to={route}
                end={route === '/'}
              >
                <NavIcon><Icon /></NavIcon>
                <NavLinkLabel>{item.label}</NavLinkLabel>
              </StyledNavLink>
            )
          }

          if (item.type === 'panel_action') {
            return (
              <ActionButton
                key={item.id}
                type="button"
                onClick={() => onItemClick?.(item)}
              >
                <NavIcon><Icon /></NavIcon>
                <NavLinkLabel>{item.label}</NavLinkLabel>
              </ActionButton>
            )
          }

          if (item.type === 'smart_filter') {
            const route = item.config?.route as string | undefined
            if (!route) return null
            return (
              <StyledNavLink
                key={item.id}
                to={route}
              >
                <NavIcon><Icon /></NavIcon>
                <NavLinkLabel>{item.label}</NavLinkLabel>
              </StyledNavLink>
            )
          }

          return null
        })}
      </SectionItems>
    </div>
  )
}
