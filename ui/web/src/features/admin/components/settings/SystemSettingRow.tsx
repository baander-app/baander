import { useId, useState, type KeyboardEvent } from 'react'
import styled from 'styled-components'
import { Badge } from '@/shared/components/ui/badge'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/components/ui/select'
import { Switch } from '@/shared/components/ui/switch'
import type {
  SettingDefinitionResource,
  SystemSettingResource,
} from '@/shared/api-client/gen/endpoints'
import {
  useResetSystemSetting,
  useUpdateSystemSettings,
  type SystemSettingValue,
} from '../../hooks/use-system-settings'

const Row = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  padding: 0.75rem 0;
`

const Text = styled.div`
  flex: 1 1 16rem;
  min-width: 0;
`

const NameLine = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
`

const Name = styled.span`
  font-size: 0.8125rem;
`

const Description = styled.div`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
`

const Errors = styled.div`
  margin-top: 0.25rem;
  font-size: 0.6875rem;
  color: var(--color-destructive);
`

const Controls = styled.div`
  display: flex;
  flex-shrink: 0;
  align-items: center;
  gap: 0.5rem;
`

const SelectSlot = styled.div`
  width: 10rem;
`

const InputSlot = styled.div`
  width: 7rem;
`

interface SystemSettingRowProps {
  definition: SettingDefinitionResource
  setting: SystemSettingResource | undefined
  canEdit: boolean
}

interface ControlProps {
  definition: SettingDefinitionResource
  value: SystemSettingValue | null
  labelId: string
  describedBy: string
  invalid: boolean
  disabled: boolean
  onSave: (value: SystemSettingValue) => void
}

/** One server-wide setting: its control, reset action and save errors. */
export function SystemSettingRow({ definition, setting, canEdit }: SystemSettingRowProps) {
  const labelId = useId()
  const descriptionId = useId()
  const errorId = useId()
  const update = useUpdateSystemSettings()
  const reset = useResetSystemSetting()
  const { key } = definition

  // While a save is pending or after it failed, show what the admin chose.
  const attempted = update.variables?.data.settings[key]
  const showAttempted = (update.isPending || update.isError) && isSettingValue(attempted)
  const value = showAttempted ? attempted : (setting?.value ?? definition.default)

  const violations = update.error ? violationsFor(update.error, key) : []
  const errors = [...violations]
  if (update.error && violations.length === 0) {
    errors.push(`Could not save: ${errorMessage(update.error)}`)
  }
  if (reset.error) {
    errors.push(`Could not reset: ${errorMessage(reset.error)}`)
  }

  const busy = update.isPending || reset.isPending

  const save = (next: SystemSettingValue) => {
    reset.reset()
    update.mutate({ data: { settings: { [key]: next } } })
  }

  const resetToDefault = () => {
    update.reset()
    reset.mutate({ key })
  }

  const controlProps: ControlProps = {
    definition,
    value,
    labelId,
    describedBy: errors.length > 0 ? `${descriptionId} ${errorId}` : descriptionId,
    invalid: violations.length > 0,
    disabled: !canEdit || busy,
    onSave: save,
  }

  return (
    <Row role="group" aria-labelledby={labelId}>
      <Text>
        <NameLine>
          <Name id={labelId}>{definition.label}</Name>
          {!definition.enforced && <Badge variant="outline">Not yet enforced</Badge>}
        </NameLine>
        <Description id={descriptionId}>{definition.description}</Description>
        {errors.length > 0 && (
          <Errors id={errorId} role="alert">
            {errors.map((message) => (
              <div key={message}>{message}</div>
            ))}
          </Errors>
        )}
      </Text>
      <Controls>
        <SettingControl {...controlProps} />
        {canEdit && (
          <Button
            variant="ghost"
            size="sm"
            aria-label={`Reset ${definition.label} to default`}
            disabled={!setting?.isExplicit || busy}
            onClick={resetToDefault}
          >
            Reset
          </Button>
        )}
      </Controls>
    </Row>
  )
}

function SettingControl(props: ControlProps) {
  switch (props.definition.type) {
    case 'boolean':
      return <BooleanControl {...props} />
    case 'enum':
      return <EnumControl {...props} />
    case 'integer':
      return <TextControl {...props} numeric />
    case 'string':
      return <TextControl {...props} numeric={false} />
  }
}

function BooleanControl({ value, labelId, describedBy, invalid, disabled, onSave }: ControlProps) {
  return (
    <Switch
      checked={value === true}
      onCheckedChange={onSave}
      disabled={disabled}
      aria-labelledby={labelId}
      aria-describedby={describedBy}
      aria-invalid={invalid}
    />
  )
}

function EnumControl({ definition, value, labelId, describedBy, invalid, disabled, onSave }: ControlProps) {
  const choose = (chosen: string) => {
    const option = definition.options.find((candidate) => String(candidate.value) === chosen)
    if (option) {
      onSave(option.value)
    }
  }

  return (
    <SelectSlot>
      <Select value={value === null ? undefined : String(value)} onValueChange={choose} disabled={disabled}>
        <SelectTrigger aria-labelledby={labelId} aria-describedby={describedBy} aria-invalid={invalid}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {definition.options.map((option) => (
            <SelectItem key={String(option.value)} value={String(option.value)}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </SelectSlot>
  )
}

/** Integer and string settings save when the field is left or Enter is pressed. */
function TextControl({
  definition,
  value,
  labelId,
  describedBy,
  invalid,
  disabled,
  onSave,
  numeric,
}: ControlProps & { numeric: boolean }) {
  const [draft, setDraft] = useState<string | null>(null)
  const shown = draft ?? (value === null ? '' : String(value))

  const commit = () => {
    if (draft === null) {
      return
    }
    setDraft(null)
    if (draft === (value === null ? '' : String(value))) {
      return
    }
    onSave(numeric ? toInteger(draft) : draft)
  }

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Enter') {
      commit()
    }
  }

  return (
    <InputSlot>
      <Input
        type={numeric ? 'number' : 'text'}
        inputMode={numeric ? 'numeric' : undefined}
        min={definition.min ?? undefined}
        max={definition.max ?? undefined}
        step={numeric ? 1 : undefined}
        value={shown}
        disabled={disabled}
        aria-labelledby={labelId}
        aria-describedby={describedBy}
        aria-invalid={invalid}
        onChange={(event) => setDraft(event.target.value)}
        onBlur={commit}
        onKeyDown={onKeyDown}
      />
    </InputSlot>
  )
}

/** A whole number is sent as a number; anything else is sent as typed for the server to reject. */
function toInteger(text: string): SystemSettingValue {
  const trimmed = text.trim()
  const parsed = Number(trimmed)

  return trimmed !== '' && Number.isInteger(parsed) ? parsed : text
}

function isSettingValue(value: unknown): value is SystemSettingValue {
  return typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string'
}

interface ErrorBody {
  error?: {
    message?: unknown
    details?: unknown
  }
}

interface HttpErrorLike {
  message: string
  response?: {
    status?: number
    data?: unknown
  }
}

function errorBody(error: HttpErrorLike): ErrorBody | undefined {
  const data = error.response?.data

  return typeof data === 'object' && data !== null ? data : undefined
}

/** The 422 messages the server returned for one setting key. */
function violationsFor(error: HttpErrorLike, key: string): string[] {
  if (error.response?.status !== 422) {
    return []
  }
  const details = errorBody(error)?.error?.details
  if (typeof details !== 'object' || details === null) {
    return []
  }
  const messages: unknown = Object.entries(details).find(([field]) => field === key)?.[1]

  return Array.isArray(messages) ? messages.filter((message) => typeof message === 'string') : []
}

function errorMessage(error: HttpErrorLike): string {
  const message = errorBody(error)?.error?.message

  return typeof message === 'string' && message !== '' ? message : error.message
}
