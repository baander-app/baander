import styled from 'styled-components'
import { X } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'

export interface FilterOption {
  value: string
  label: string
}

const Wrapper = styled.div<{ $hasClassName: boolean }>`
  display: flex;
  align-items: center;
  gap: 0.375rem;
  flex-wrap: wrap;
`

interface FilterBarProps {
  filters: FilterOption[]
  selected: string[]
  onToggle: (value: string) => void
  onClear: () => void
  className?: string
}

export function FilterBar({ filters, selected, onToggle, onClear }: FilterBarProps) {
  if (filters.length === 0) return null

  return (
    <Wrapper $hasClassName={false}>
      {filters.map((filter) => {
        const active = selected.includes(filter.value)
        return (
          <Button
            key={filter.value}
            variant={active ? 'secondary' : 'ghost'}
            size="xs"
            onClick={() => onToggle(filter.value)}
          >
            {filter.label}
          </Button>
        )
      })}
      {selected.length > 0 && (
        <Button variant="ghost" size="xs" onClick={onClear}>
          <X size={12} />
          Clear
        </Button>
      )}
    </Wrapper>
  )
}
