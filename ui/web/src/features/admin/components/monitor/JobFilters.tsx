import styled from 'styled-components'
import { Input } from '@/shared/components/ui/input'
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/shared/components/ui/select'
import { interactiveTransition } from '@/shared/theme'

interface JobFilterValues {
  status?: string
  name?: string
  queue?: string
}

interface JobFiltersProps {
  filters: JobFilterValues
  onFiltersChange: (filters: JobFilterValues) => void
}

const STATUS_OPTIONS = [
  { value: '_all', label: 'All' },
  { value: 'queued', label: 'Queued' },
  { value: 'running', label: 'Running' },
  { value: 'finished', label: 'Finished' },
  { value: 'failed', label: 'Failed' },
  { value: 'cancelled', label: 'Cancelled' },
]

const Wrapper = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
`

const ClearButton = styled.button`
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  ${interactiveTransition(['background-color'])}

  &:hover { background-color: var(--color-accent); }
`

export type { JobFilterValues }

export function JobFilters({ filters, onFiltersChange }: JobFiltersProps) {
  const hasActiveFilters = filters.status || filters.name || filters.queue

  return (
    <Wrapper>
      <Select
        value={filters.status || '_all'}
        onValueChange={(value) =>
          onFiltersChange({ ...filters, status: value === '_all' ? undefined : value })
        }
      >
        <SelectTrigger style={{ height: '2rem', width: '8rem' }}>
          <SelectValue placeholder="All" />
        </SelectTrigger>
        <SelectContent>
          {STATUS_OPTIONS.map((opt) => (
            <SelectItem key={opt.value} value={opt.value}>
              {opt.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      <Input
        type="text"
        value={filters.name ?? ''}
        onChange={(e) =>
          onFiltersChange({ ...filters, name: e.target.value || undefined })
        }
        placeholder="Filter by name..."
        style={{ height: '2rem', width: '12rem' }}
      />

      <Input
        type="text"
        value={filters.queue ?? ''}
        onChange={(e) =>
          onFiltersChange({ ...filters, queue: e.target.value || undefined })
        }
        placeholder="Filter by queue..."
        style={{ height: '2rem', width: '12rem' }}
      />

      {hasActiveFilters && (
        <ClearButton onClick={() => onFiltersChange({})}>Clear</ClearButton>
      )}
    </Wrapper>
  )
}
