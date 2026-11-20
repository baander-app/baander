import { type FormEvent, type ReactNode, useState } from 'react'
import { AxiosError } from 'axios'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { parseApiError } from '@/features/auth/lib/parse-api-error'
import { useTranslation } from '@/shared/i18n'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/components/ui/dialog'
import { EmailVerificationNotice } from './EmailVerificationNotice'
import { ErrorBox, FieldLabel, Form, SpinningIconWithMargin } from './settings-form-styles'

/** What a sensitive change sends to prove the signed-in user is present. */
export interface StepUpCredentials {
  currentPassword: string
  totpCode?: string
}

interface StepUpDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description: ReactNode
  submitLabel: string
  /** Shown while the error has no message of its own. */
  fallbackError: string
  /** Fields of the change itself, shown above the password. */
  children?: ReactNode
  trigger?: ReactNode
  /**
   * Makes the change. Resolves true to close the dialog, false to keep it open for another
   * try; a rejection is shown in the dialog.
   */
  onConfirm: (credentials: StepUpCredentials) => Promise<boolean>
}

type Refusal =
  | { kind: 'failed' }
  | { kind: 'limited' }
  | { kind: 'emailUnverified' }
  | { kind: 'other', message: string }

function refusalOf(err: unknown, fallback: string): Refusal {
  if (err instanceof AxiosError && err.response?.status === 429) return { kind: 'limited' }

  switch (reasonOf(err instanceof AxiosError ? err.response?.data : undefined)) {
    case 'step_up_failed':
      return { kind: 'failed' }
    case 'email_unverified':
      return { kind: 'emailUnverified' }
    default:
      return { kind: 'other', message: parseApiError(err, fallback).message }
  }
}

function reasonOf(data: unknown): string | undefined {
  if (typeof data !== 'object' || data === null || !('error' in data)) return undefined
  const { error } = data
  if (typeof error !== 'object' || error === null || !('details' in error)) return undefined
  const { details } = error
  if (typeof details !== 'object' || details === null || !('reason' in details)) return undefined

  return typeof details.reason === 'string' ? details.reason : undefined
}

/**
 * Asks for the current password, and a code from the authenticator app when TOTP is on,
 * before a sensitive change such as a new email address or a passkey. A wrong password or
 * code clears the fields and keeps the dialog open; after too many attempts the dialog stays
 * locked until it is closed.
 */
export function StepUpDialog({
  open,
  onOpenChange,
  title,
  description,
  submitLabel,
  fallbackError,
  children,
  trigger,
  onConfirm,
}: StepUpDialogProps) {
  const { t } = useTranslation()
  const totpEnabled = useAuthStore((s) => s.user?.totpEnabled === true)
  const [currentPassword, setCurrentPassword] = useState('')
  const [totpCode, setTotpCode] = useState('')
  const [loading, setLoading] = useState(false)
  const [refusal, setRefusal] = useState<Refusal | null>(null)

  const clearCredentials = () => {
    setCurrentPassword('')
    setTotpCode('')
  }

  const handleOpenChange = (next: boolean) => {
    if (!next) {
      clearCredentials()
      setRefusal(null)
    }
    onOpenChange(next)
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setRefusal(null)
    setLoading(true)

    try {
      const done = await onConfirm({
        currentPassword,
        ...(totpEnabled ? { totpCode } : {}),
      })
      // A confirmed or abandoned attempt has used the code; the next one starts over.
      clearCredentials()
      if (done) handleOpenChange(false)
    } catch (err: unknown) {
      clearCredentials()
      setRefusal(refusalOf(err, fallbackError))
    } finally {
      setLoading(false)
    }
  }

  const errorMessage = (() => {
    switch (refusal?.kind) {
      case 'failed':
        return t('auth.stepUp.failed')
      case 'limited':
        return t('auth.stepUp.limited')
      case 'other':
        return refusal.message
      default:
        return null
    }
  })()

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      {trigger && <DialogTrigger asChild>{trigger}</DialogTrigger>}
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        {refusal?.kind === 'emailUnverified' ? (
          <EmailVerificationNotice />
        ) : (
          <Form onSubmit={handleSubmit}>
            {errorMessage && (
              <ErrorBox role="alert">{errorMessage}</ErrorBox>
            )}
            {children}
            <div>
              <FieldLabel htmlFor="step-up-password">
                {t('auth.stepUp.currentPassword')}
              </FieldLabel>
              <Input
                id="step-up-password"
                type="password"
                required
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
                autoComplete="current-password"
              />
            </div>
            {totpEnabled && (
              <div>
                <FieldLabel htmlFor="step-up-code">
                  {t('auth.totpCode')}
                </FieldLabel>
                <Input
                  id="step-up-code"
                  required
                  value={totpCode}
                  onChange={(e) => setTotpCode(e.target.value)}
                  autoComplete="one-time-code"
                  inputMode="numeric"
                  maxLength={6}
                />
              </div>
            )}
            <DialogFooter>
              <Button variant="outline" type="button" onClick={() => handleOpenChange(false)}>
                {t('common.cancel')}
              </Button>
              <Button type="submit" disabled={loading || refusal?.kind === 'limited'}>
                {loading ? <SpinningIconWithMargin size={14} /> : null}
                {submitLabel}
              </Button>
            </DialogFooter>
          </Form>
        )}
      </DialogContent>
    </Dialog>
  )
}
