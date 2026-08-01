import styled, { css } from 'styled-components'
import { useState, useCallback } from 'react'
import { TimelineYear } from './TimelineYear'
import type { TimelineDecade as TimelineDecadeType } from '../hooks/use-timeline-view-model'
import { focusVisibleRing } from '@/shared/theme'

const Container = styled.div``

const ToggleButton = styled.button`
  margin-bottom: 0.5rem;
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.5rem;
  background: transparent;
  text-align: left;
  border: none;
  cursor: pointer;
  padding: 0;
  ${focusVisibleRing}
`

const ChevronIcon = styled.svg<{ $collapsed: boolean }>`
  flex-shrink: 0;
  color: var(--color-muted-foreground);
  transition: transform 80ms ease-out;

  ${({ $collapsed }) =>
    $collapsed &&
    css`transform: rotate(-90deg);`}
`

const DecadeLabel = styled.span`
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-weight: 500;
  color: var(--color-muted-foreground);
`

const Collapsible = styled.div<{ $collapsed: boolean }>`
  overflow: hidden;
  transition: max-height 80ms ease-out, opacity 80ms ease-out;

  ${({ $collapsed }) =>
    $collapsed
      ? css`
        max-height: 0px;
        opacity: 0;
      `
      : css`
        max-height: 9999px;
        opacity: 1;
      `}
`

const YearsContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

interface TimelineDecadeProps {
  decade: TimelineDecadeType
}

export function TimelineDecade({ decade }: TimelineDecadeProps) {
  const [collapsed, setCollapsed] = useState(false)

  const toggleCollapsed = useCallback(() => {
    setCollapsed((prev) => !prev)
  }, [])

  return (
    <Container>
      <ToggleButton
        onClick={toggleCollapsed}
        aria-expanded={!collapsed}
      >
        <ChevronIcon
          width="12"
          height="12"
          viewBox="0 0 12 12"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          $collapsed={collapsed}
        >
          <path d="M3 4.5L6 7.5L9 4.5" />
        </ChevronIcon>
        <DecadeLabel>{decade.label}</DecadeLabel>
      </ToggleButton>
      <Collapsible $collapsed={collapsed}>
        <YearsContainer>
          {decade.years.map((year) => (
            <TimelineYear key={year.label} label={year.label} albums={year.albums} />
          ))}
        </YearsContainer>
      </Collapsible>
    </Container>
  )
}
