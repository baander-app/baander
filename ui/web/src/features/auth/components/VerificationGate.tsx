import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChangeEmailDialog } from '@/features/settings/components/ChangeEmailDialog'
import { useTranslation } from '@/shared/i18n'
import { createLogger } from '@/shared/lib/logger'
import { readCurrentUser } from '../lib/current-user'
import { useAuthStore } from '../stores/auth-store'
import { AuthPageLayout } from './AuthPageLayout'
import { ResendVerification } from './ResendVerification'
import { ErrorAlert, Form, FullWidthButton, Intro, Notice } from './auth-form-styles'

const logger = createLogger('VerificationGate')

type Check = 'idle' | 'checking' | 'unverified' | 'failed'

/** Reads the profile again in the background; a failure leaves the gate as it is. */
function rereadProfile(): void {
  readCurrentUser().catch((err: unknown) => {
    logger.warn('Could not read the profile again:', err)
  })
}

/**
 * Shown instead of the app while the signed-in account has yet to verify its email address: the
 * server refuses it everything but its profile and the routes that confirm the address. The
 * profile is read again on mount, whenever the window regains focus and on request, so an address
 * verified on another device opens the app here.
 */
export function VerificationGate() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const email = useAuthStore((s) => s.user?.pendingEmail ?? s.user?.email ?? '')
  const logout = useAuthStore((s) => s.logout)

  const [check, setCheck] = useState<Check>('idle')
  const [emailDialogOpen, setEmailDialogOpen] = useState(false)
  const [signingOut, setSigningOut] = useState(false)

  useEffect(() => {
    rereadProfile()
    window.addEventListener('focus', rereadProfile)

    return () => window.removeEventListener('focus', rereadProfile)
  }, [])

  const checkAgain = async () => {
    setCheck('checking')
    try {
      await readCurrentUser()
      // A verified address replaces the gate with the app before this runs.
      setCheck('unverified')
    } catch (err: unknown) {
      logger.warn('Could not read the profile:', err)
      setCheck('failed')
    }
  }

  // Read after the dialog closes, the profile shows a requested change as pending.
  const handleEmailDialogChange = (open: boolean) => {
    setEmailDialogOpen(open)
    if (!open) rereadProfile()
  }

  const signOut = async () => {
    setSigningOut(true)
    try {
      await logout()
      navigate('/login')
    } finally {
      setSigningOut(false)
    }
  }

  return (
    <AuthPageLayout title={t('auth.verificationGate.title')}>
      <Form as="div">
        <Intro>
          {t('auth.verificationGate.intro', { email })}
        </Intro>

        {check === 'unverified' && (
          <Notice role="status">
            {t('auth.verificationGate.notVerifiedYet')}
          </Notice>
        )}

        {check === 'failed' && (
          <ErrorAlert role="alert">
            {t('common.error')}
          </ErrorAlert>
        )}

        <ResendVerification />

        <FullWidthButton
          variant="outline"
          onClick={checkAgain}
          disabled={check === 'checking'}
        >
          {t('auth.verificationGate.checkAgain')}
        </FullWidthButton>

        <ChangeEmailDialog
          open={emailDialogOpen}
          onOpenChange={handleEmailDialogChange}
        >
          <FullWidthButton variant="outline">
            {t('auth.verificationGate.changeEmail')}
          </FullWidthButton>
        </ChangeEmailDialog>

        <FullWidthButton
          variant="ghost"
          onClick={signOut}
          disabled={signingOut}
        >
          {t('auth.verificationGate.signOut')}
        </FullWidthButton>
      </Form>
    </AuthPageLayout>
  )
}
