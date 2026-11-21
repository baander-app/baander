import type { ComponentProps, ReactNode } from 'react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger
} from '@/shared/components/ui/tooltip'
import { textRole } from '@/shared/theme'

export function CinemaButton({
  label,
  children,
  ...props
}: ComponentProps<typeof Button> & {
  label: string
  children: ReactNode
}) {
  return (
    <TooltipProvider delayDuration={500}>
      <Tooltip>
        <TooltipTrigger asChild>
          <Button variant="ghost" size="icon" aria-label={label} {...props}>
            {children}
          </Button>
        </TooltipTrigger>
        <TooltipContent>{label}</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

const Timing = styled.fieldset`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-sm);
  border: 0;
  padding: 0;
  margin: var(--space-md) 0;
  legend {
    ${textRole('label')}
  }
  output {
    ${textRole('timecode')}
    min-width: 5rem;
  }
`

export function CinemaTiming({
  label,
  value,
  disabled,
  onChange
}: {
  label: string
  value: number
  disabled: boolean
  onChange: (value: number) => void
}) {
  return (
    <Timing disabled={disabled}>
      <legend>{label}</legend>
      <Button
        variant="outline"
        size="sm"
        aria-label={`${label} earlier`}
        disabled={disabled || value <= -10000}
        onClick={() => onChange(Math.max(-10000, value - 100))}
      >
        Earlier
      </Button>
      <output aria-label={`${label} offset`}>
        {value > 0 ? '+' : ''}
        {value} ms
      </output>
      <Button
        variant="outline"
        size="sm"
        aria-label={`${label} later`}
        disabled={disabled || value >= 10000}
        onClick={() => onChange(Math.min(10000, value + 100))}
      >
        Later
      </Button>
      <Button
        variant="ghost"
        size="sm"
        aria-label={`Reset ${label.toLowerCase()}`}
        disabled={disabled || value === 0}
        onClick={() => onChange(0)}
      >
        Reset
      </Button>
    </Timing>
  )
}
