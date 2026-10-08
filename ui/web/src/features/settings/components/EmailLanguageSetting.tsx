import { useId } from 'react'
import styled from 'styled-components'
import { parseApiError, parseFieldViolations } from '@/features/auth/lib/parse-api-error'
import { Button } from '@/shared/components/ui/button'
import { Card, CardContent } from '@/shared/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/components/ui/select'
import { Skeleton } from '@/shared/components/ui/skeleton'
import type { UserSettingResource } from '@/shared/api-client/gen/endpoints'
import { EMAIL_LANGUAGE_KEY, SERVER_DEFAULT_SELECTION } from '../email-language'
import { useResetUserSetting, useSetUserSetting, useUserSetting } from '../hooks/use-user-settings'

const LABEL = 'Email language'

const CardStack = styled(CardContent)`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const FieldHeading = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
`

const FieldDescription = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const ErrorText = styled.div`
  font-size: 0.75rem;
  color: var(--color-destructive);
`

const ErrorBlock = styled.div`
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.5rem;
`

const SelectSlot = styled.div`
  width: 100%;
  max-width: 20rem;
`

const SkeletonLine = styled(Skeleton)<{ $width: string; $height: string }>`
  height: ${({ $height }) => $height};
  width: ${({ $width }) => $width};
  max-width: 100%;
  background: var(--color-muted);
`

/** The language Baander writes the user's emails in, or the server default. */
export function EmailLanguageSetting() {
  const labelId = useId()
  const setting = useUserSetting(EMAIL_LANGUAGE_KEY)

  if (setting.isPending) {
    return (
      <Card size="sm">
        <CardStack aria-busy="true" aria-label={`Loading ${LABEL.toLowerCase()}`}>
          <FieldHeading>{LABEL}</FieldHeading>
          <SkeletonLine $width="16rem" $height="0.75rem" />
          <SkeletonLine $width="20rem" $height="2.25rem" />
        </CardStack>
      </Card>
    )
  }

  if (setting.isError || setting.data === null) {
    const retry = async () => {
      await setting.refetch()
    }

    return (
      <Card size="sm">
        <CardStack>
          <FieldHeading>{LABEL}</FieldHeading>
          <ErrorBlock>
            <ErrorText role="alert">Unable to load your email language.</ErrorText>
            <Button variant="ghost" size="sm" onClick={retry}>
              Retry
            </Button>
          </ErrorBlock>
        </CardStack>
      </Card>
    )
  }

  return <EmailLanguageControl setting={setting.data} labelId={labelId} />
}

interface EmailLanguageControlProps {
  setting: UserSettingResource
  labelId: string
}

function EmailLanguageControl({ setting, labelId }: EmailLanguageControlProps) {
  const descriptionId = useId()
  const errorId = useId()
  const save = useSetUserSetting()
  const reset = useResetUserSetting()
  const { options } = setting.definition

  const serverDefaultName = options.find((option) => String(option.value) === String(setting.resetValue))?.label
    ?? String(setting.resetValue)

  // While a save is pending, show what the user chose; otherwise show what the server holds.
  let selected = setting.choice === null ? SERVER_DEFAULT_SELECTION : String(setting.choice)
  if (save.isPending && save.variables) {
    selected = String(save.variables.data.value)
  } else if (reset.isPending) {
    selected = SERVER_DEFAULT_SELECTION
  }

  const failure = save.error ?? reset.error
  const errors = failure ? saveErrors(failure) : []
  const busy = save.isPending || reset.isPending

  const choose = (chosen: string) => {
    save.reset()
    reset.reset()
    if (chosen === SERVER_DEFAULT_SELECTION) {
      reset.mutate({ key: setting.key })

      return
    }
    const option = options.find((candidate) => String(candidate.value) === chosen)
    if (option) {
      save.mutate({ key: setting.key, data: { value: option.value } })
    }
  }

  return (
    <Card size="sm">
      <CardStack>
        <FieldHeading id={labelId}>{LABEL}</FieldHeading>
        <FieldDescription id={descriptionId}>{setting.definition.description}</FieldDescription>
        <SelectSlot>
          <Select value={selected} onValueChange={choose} disabled={busy}>
            <SelectTrigger
              aria-labelledby={labelId}
              aria-describedby={errors.length > 0 ? `${descriptionId} ${errorId}` : descriptionId}
              aria-invalid={errors.length > 0}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={SERVER_DEFAULT_SELECTION}>{`Server default (${serverDefaultName})`}</SelectItem>
              {options.map((option) => (
                <SelectItem key={String(option.value)} value={String(option.value)}>
                  {option.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </SelectSlot>
        {errors.length > 0 && (
          <ErrorText id={errorId} role="alert">
            {errors.map((message) => (
              <div key={message}>{message}</div>
            ))}
          </ErrorText>
        )}
      </CardStack>
    </Card>
  )
}

/** The server's violations for this setting, or a general save failure. */
function saveErrors(error: unknown): string[] {
  const violations = parseFieldViolations(error, EMAIL_LANGUAGE_KEY)
  if (violations.length > 0) {
    return violations
  }

  return [`Could not save: ${parseApiError(error, 'The server did not answer.').message}`]
}
