import styled from 'styled-components'
import { textRole } from '@/shared/theme'
import React from 'react';
import type { LucideIcon } from 'lucide-react'

const Header = styled.div`
  flex-wrap: wrap;
  gap: 1rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const LeftGroup = styled.div`
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const IconWrapper = styled.span`
  color: var(--color-muted-foreground);
`

const TitleGroup = styled.div`
  min-width: 0;
  overflow-wrap: anywhere;
`

const Title = styled.h1`
  ${textRole('title')}
`

const Subtitle = styled.p`
  ${textRole('body')}
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
