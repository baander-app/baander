import { useId } from 'react'
import styled from 'styled-components'
import { textRole } from '@/shared/theme'
import { Input } from '@/shared/components/ui/input'
import { Button } from '@/shared/components/ui/button'
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/shared/components/ui/select'
import { ErrorNotice } from './span-inspector-styles'
import type { SpanFilterInputs, SpanFilterErrors } from './span-inspector'

const Fields = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 11rem), 1fr));
  gap: var(--space-md);
  align-items: start;
  margin-bottom: var(--space-md);
  ${textRole('body-sm')}
`
const Field = styled.div`
  min-width: 0;
  display: grid;
  gap: var(--space-md);
`
interface Props {
  value: SpanFilterInputs
  services: string[]
  errors: SpanFilterErrors
  onChange: (next: SpanFilterInputs) => void
  onReset: () => void
}
export function SpanFilters({ value, services, errors, onChange, onReset }: Props) {
  const id = useId()
  function update<K extends keyof SpanFilterInputs>(key: K, next: SpanFilterInputs[K]) {
    onChange({ ...value, [key]: next })
  }
  return (
    <Fields>
      <Field>
        <label htmlFor={`${id}-search`}>Search spans</label>
        <Input id={`${id}-search`} value={value.text} onChange={(event) => update('text', event.target.value)} />
      </Field>
      <Field>
        <label id={`${id}-service`}>Service</label>
        <Select value={value.service ? `service:${value.service}` : 'all'} onValueChange={(next) => update('service', next === 'all' ? '' : next.slice('service:'.length))}>
          <SelectTrigger aria-labelledby={`${id}-service`}><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All services</SelectItem>
            {services.map((service) => <SelectItem key={service} value={`service:${service}`}>{service}</SelectItem>)}
          </SelectContent>
        </Select>
      </Field>
      <Field>
        <label id={`${id}-status`}>Status</label>
        <Select value={value.status} onValueChange={(next) => update('status', next as SpanFilterInputs['status'])}>
          <SelectTrigger aria-labelledby={`${id}-status`}><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            <SelectItem value="error">Error</SelectItem>
            <SelectItem value="non-error">Non-error</SelectItem>
          </SelectContent>
        </Select>
      </Field>
      {(['minDurationMs', 'maxDurationMs'] as const).map((key) => (
        <Field key={key}>
          <label htmlFor={`${id}-${key}`}>{key === 'minDurationMs' ? 'Minimum' : 'Maximum'} duration (ms)</label>
          <Input id={`${id}-${key}`} inputMode="decimal" value={value[key]} aria-invalid={!!errors[key]} aria-describedby={errors[key] ? `${id}-${key}-error` : undefined} onChange={(event) => update(key, event.target.value)} />
          {errors[key] && <ErrorNotice id={`${id}-${key}-error`} role="alert">{errors[key]}</ErrorNotice>}
        </Field>
      ))}
      <Field>
        <label id={`${id}-sort`}>Sort</label>
        <Select value={value.sort} onValueChange={(next) => update('sort', next as SpanFilterInputs['sort'])}>
          <SelectTrigger aria-labelledby={`${id}-sort`}><SelectValue /></SelectTrigger>
          <SelectContent><SelectItem value="newest">Newest</SelectItem><SelectItem value="slowest">Slowest</SelectItem></SelectContent>
        </Select>
      </Field>
      <Button variant="outline" size="sm" onClick={onReset}>Reset filters</Button>
    </Fields>
  )
}
