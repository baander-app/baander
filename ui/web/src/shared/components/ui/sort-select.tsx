import { ArrowUpDown } from 'lucide-react'
import styled from 'styled-components'
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
} from '@/shared/components/ui/dropdown-menu'
import { Button } from '@/shared/components/ui/button'

export interface SortOption {
  value: string
  label: string
}

const StyledContent = styled(DropdownMenuContent)`
  width: 11rem;
`

interface SortSelectProps {
  options: SortOption[]
  value: string
  onChange: (value: string) => void
}

export function SortSelect({ options, value, onChange }: SortSelectProps) {
  const current = options.find((o) => o.value === value)

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="sm">
          <ArrowUpDown size={14} />
          {current?.label ?? 'Sort'}
        </Button>
      </DropdownMenuTrigger>
      <StyledContent align="start">
        <DropdownMenuRadioGroup value={value} onValueChange={onChange}>
          {options.map((option) => (
            <DropdownMenuRadioItem key={option.value} value={option.value}>
              {option.label}
            </DropdownMenuRadioItem>
          ))}
        </DropdownMenuRadioGroup>
      </StyledContent>
    </DropdownMenu>
  )
}
