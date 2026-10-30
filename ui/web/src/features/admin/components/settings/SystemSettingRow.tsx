import { useEffect, useId, useRef, type KeyboardEvent } from 'react'
import { useDraft } from '@/shared/drafts/use-draft'
import { captureOwnership, isCurrentOwnership, subscribeOwnership } from '@/shared/live/ownership'
import styled from 'styled-components'
import { parseFieldViolations } from '@/features/auth/lib/parse-api-error'
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
  snapshot: SystemSettingResource | undefined
  value: SystemSettingValue | null
  labelId: string
  describedBy: string
  invalid: boolean
  disabled: boolean
  onSave: (value: SystemSettingValue) => Promise<unknown>
}

/** One server-wide setting: its control, reset action and save errors. */
export function SystemSettingRow({ definition, setting, canEdit }: SystemSettingRowProps) {
  const labelId = useId()
  const descriptionId = useId()
  const errorId = useId()
  const update = useUpdateSystemSettings()
  const reset = useResetSystemSetting()
  const { key } = definition

  const attempted = update.variables?.data.settings[key]
  const showAttempted = (update.isPending || update.isError) && (definition.type === 'boolean' || definition.type === 'enum')
  const supportedAttempt = typeof attempted === 'boolean'
    || typeof attempted === 'number'
    || typeof attempted === 'string'
  const value = showAttempted && supportedAttempt ? attempted : setting?.value ?? definition.default

  const violations = parseFieldViolations(update.error, key)
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
    return update.mutateAsync({ data: { settings: { [key]: next } } })
  }

  const resetToDefault = () => {
    update.reset()
    reset.mutate({ key })
  }

  const controlProps: ControlProps = {
    definition,
    snapshot: setting,
    value,
    labelId,
    describedBy: errors.length > 0 ? `${descriptionId} ${errorId}` : descriptionId,
    invalid: violations.length > 0,
    disabled: !canEdit,
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
      onCheckedChange={(next) => { onSave(next).catch(() => {}) }}
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
      onSave(option.value).catch(() => {})
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
  snapshot,
  value,
  labelId,
  describedBy,
  invalid,
  disabled,
  onSave,
  numeric,
}: ControlProps & { numeric: boolean }) {
  const serverText = value === null ? '' : String(value)
  const draft = useDraft({ identity: definition.key, initialData: { text: serverText } })
  const { receive, revokeAccess, pending } = draft
  const lastSnapshot = useRef(snapshot)
  const revokedSnapshot = useRef<SystemSettingResource | undefined>(undefined)
  useEffect(() => subscribeOwnership(() => {
    revokedSnapshot.current = lastSnapshot.current
    revokeAccess()
  }), [revokeAccess])
  useEffect(() => {
    lastSnapshot.current = snapshot
    if (!pending && snapshot !== revokedSnapshot.current) receive({ text: serverText })
  }, [receive, serverText, snapshot, pending])
  const wasDisabled = useRef(disabled)
  useEffect(() => {
    if (!wasDisabled.current && disabled) {
      revokedSnapshot.current = lastSnapshot.current
      revokeAccess()
    }
    wasDisabled.current = disabled
  }, [disabled, revokeAccess])

  const commit = async () => {
    if (disabled) return
    const submission = draft.beginSave()
    if (!submission) return
    const stamp = captureOwnership()
    try {
      await onSave(numeric ? toInteger(submission.values.text) : submission.values.text)
      if (isCurrentOwnership(stamp)) draft.completeSave(submission)
    } catch {
      if (isCurrentOwnership(stamp)) draft.failSave(submission)
    }
  }

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Enter') {
      commit()
    }
  }

  return (
    <InputSlot>
      {draft.status === 'conflict' && (
        <div role="alert">
          Changed on the server.
          <Button size="sm" onClick={() => draft.resolveConflict('local')}>Keep my text</Button>
          <Button size="sm" onClick={() => draft.resolveConflict('server')}>Use server value</Button>
        </div>
      )}
      <Input
        type={numeric ? 'number' : 'text'}
        inputMode={numeric ? 'numeric' : undefined}
        min={definition.min ?? undefined}
        max={definition.max ?? undefined}
        step={numeric ? 1 : undefined}
        value={draft.values.text ?? ''}
        disabled={disabled}
        aria-labelledby={labelId}
        aria-describedby={describedBy}
        aria-invalid={invalid}
        onChange={(event) => draft.updateField('text', event.target.value)}
        onBlur={() => { commit() }}
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


interface ErrorBody {
  error?: {
    message?: unknown
  }
}

interface HttpErrorLike {
  message: string
  response?: {
    data?: unknown
  }
}

function errorBody(error: HttpErrorLike): ErrorBody | undefined {
  const data = error.response?.data

  return typeof data === 'object' && data !== null ? data : undefined
}

function errorMessage(error: HttpErrorLike): string {
  const message = errorBody(error)?.error?.message

  return typeof message === 'string' && message !== '' ? message : error.message
}
