import { useId } from 'react'
import styled from 'styled-components'
import { AxiosError } from 'axios'
import { parseApiError } from '@/features/auth/lib/parse-api-error'
import { Button } from '@/shared/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/components/ui/select'
import { Skeleton } from '@/shared/components/ui/skeleton'
import type { AdminUserSetting } from '../../api/user-admin-api'
import { SERVER_DEFAULT, initialLanguageSelection } from './user-language'

const FieldGroup = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const Label = styled.span`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const Hint = styled.p`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
`

const ErrorText = styled.div`
  font-size: 0.6875rem;
  color: var(--color-destructive);
`

const ErrorRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const SkeletonLine = styled(Skeleton)`
  height: 2.25rem;
  background: var(--color-muted);
`

interface UserLanguageFieldProps {
  setting: AdminUserSetting | undefined
  loading: boolean
  loadFailed: boolean
  onRetry: () => void
  selection: string | null
  onSelect: (selection: string) => void
  canEdit: boolean
  saveError: unknown
  disabled: boolean
}

/** The user's email language: their choice, the server default, or a stored value that is no longer offered. */
export function UserLanguageField({
  setting,
  loading,
  loadFailed,
  onRetry,
  selection,
  onSelect,
  canEdit,
  saveError,
  disabled,
}: UserLanguageFieldProps) {
  const labelId = useId()
  const hintId = useId()
  const errorId = useId()

  if (loading) {
    return (
      <FieldGroup aria-busy="true">
        <Label id={labelId}>Email language</Label>
        <SkeletonLine />
      </FieldGroup>
    )
  }

  if (loadFailed || !setting) {
    return (
      <FieldGroup>
        <Label id={labelId}>Email language</Label>
        <ErrorRow>
          <ErrorText role="alert">Unable to load the language.</ErrorText>
          <Button type="button" variant="ghost" size="sm" onClick={onRetry}>
            Retry
          </Button>
        </ErrorRow>
      </FieldGroup>
    )
  }

  const errors = saveError ? saveErrorMessages(saveError, setting.key) : []
  const effective = optionLabel(setting, setting.value)
  const describedBy = errors.length > 0 ? `${hintId} ${errorId}` : hintId

  return (
    <FieldGroup>
      <Label id={labelId}>Email language</Label>
      <Select
        value={selection ?? initialLanguageSelection(setting)}
        onValueChange={onSelect}
        disabled={!canEdit || disabled}
      >
        <SelectTrigger
          aria-labelledby={labelId}
          aria-describedby={describedBy}
          aria-invalid={errors.length > 0 || !setting.storedValueValid}
        >
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={SERVER_DEFAULT}>
            {`Server default (${optionLabel(setting, setting.resetValue)})`}
          </SelectItem>
          {setting.options.map((option) => (
            <SelectItem key={String(option.value)} value={String(option.value)}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      <Hint id={hintId}>
        {setting.storedValueValid
          ? `Emails go out in ${effective}.`
          : `The stored language ${JSON.stringify(setting.storedValue)} is no longer offered, so emails go out in ${effective}.`}
        {!canEdit && ' Only super admins can change it.'}
      </Hint>
      {errors.length > 0 && (
        <ErrorText id={errorId} role="alert">
          {errors.map((message) => (
            <div key={message}>{message}</div>
          ))}
        </ErrorText>
      )}
    </FieldGroup>
  )
}

function optionLabel(setting: AdminUserSetting, value: unknown): string {
  return setting.options.find((option) => option.value === value)?.label ?? String(value)
}

/** The 422 messages for the setting, or one general message for any other failure. */
function saveErrorMessages(error: unknown, key: string): string[] {
  if (error instanceof AxiosError && error.response?.status === 422) {
    const data: unknown = error.response.data
    const details = isRecord(data) && isRecord(data.error) ? data.error.details : undefined
    const messages = isRecord(details) ? details[key] : undefined
    if (Array.isArray(messages)) {
      const strings = messages.filter((message): message is string => typeof message === 'string')
      if (strings.length > 0) {
        return strings
      }
    }
  }

  return [`Could not save the language: ${parseApiError(error, 'Request failed.').message}`]
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
