import styled from 'styled-components'
import React from 'react';
import type { LucideIcon } from 'lucide-react'

const Header = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const LeftGroup = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const IconWrapper = styled.span`
  color: var(--color-muted-foreground);
`

const TitleGroup = styled.div``

const Title = styled.h1`
  font-size: 1.125rem;
  font-weight: 600;
  letter-spacing: -0.01em;
`

const Subtitle = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

interface AdminPageHeaderProps {
  title: string
  subtitle?: string
  icon?: LucideIcon
  action?: React.ReactNode
}

export function AdminPageHeader({ title, subtitle, icon: Icon, action }: AdminPageHeaderProps) {
  return (
    <Header>
      <LeftGroup>
        {Icon && (
          <IconWrapper>
            <Icon size={18} strokeWidth={1.5} />
          </IconWrapper>
        )}
        <TitleGroup>
          <Title>{title}</Title>
          {subtitle && <Subtitle>{subtitle}</Subtitle>}
        </TitleGroup>
      </LeftGroup>
      {action}
    </Header>
  )
}
