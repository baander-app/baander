import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react'
import { AxiosError } from 'axios'
import { useMutation } from '@tanstack/react-query'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { useTranslation } from '@/shared/i18n'
import { createLogger } from '@/shared/lib/logger'
import {
  type DeviceDecision,
  type PendingDeviceAuthorization,
  decideDeviceAuthorization,
  lookupDeviceAuthorization,
} from '../api/device-authorization-api'
import { useRetryCountdown } from '../hooks/use-retry-countdown'
import { parseApiError } from '../lib/parse-api-error'
import { retryAfterSeconds } from '../lib/retry-after'
import { formatUserCode, isCompleteUserCode, normalizeUserCode } from '../lib/user-code'
import { ScopeList } from './ScopeList'
import {
  ErrorAlert,
  FieldWrapper,
  FooterText,
  Form,
  FullWidthButton,
  Intro,
  Label,
  Notice,
  TextLink,
} from './auth-form-styles'

const logger = createLogger('DeviceAuthorization')

type Step =
  | { name: 'enter' }
  | { name: 'confirm'; request: PendingDeviceAuthorization }
  | { name: 'approved' }
  | { name: 'denied' }

type Problem =
  | { kind: 'invalid' }
  | { kind: 'rateLimited' }
  | { kind: 'failed'; message: string }

interface DecisionVariables {
  userCode: string
  decision: DeviceDecision
}

const CodeInput = styled(Input)`
  font-family: var(--font-mono);
  font-size: 1.125rem;
  letter-spacing: 0.1em;
  text-align: center;
  text-transform: uppercase;
`

const Code = styled.span`
  font-family: var(--font-mono);
  letter-spacing: 0.05em;
`

const ClientIntro = styled.p`
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-foreground);
`

const Actions = styled.div`
  display: flex;
  gap: 0.75rem;

  & > * {
    flex: 1;
  }
`

interface DeviceAuthorizationProps {
  /** The code from `?user_code=`, or an empty string. */
  initialCode: string
}

/**
 * The RFC 8628 verification page: the signed-in user enters the code a device shows, checks
 * which client asks for access, and approves or denies it. A code that arrives in the link is
 * looked up at once, but approving always takes a click.
 */
export function DeviceAuthorization({ initialCode }: DeviceAuthorizationProps) {
  const { t } = useTranslation()
  const { secondsLeft, start: startCountdown } = useRetryCountdown()

  const [code, setCode] = useState(() => normalizeUserCode(initialCode))
  const [step, setStep] = useState<Step>({ name: 'enter' })
  const [problem, setProblem] = useState<Problem | null>(null)
  // A prefilled code is looked up once, also when StrictMode replays the effect.
  const prefilledLookup = useRef<string | null>(null)

  // The lookup is a plain call, not a mutation: a prefilled code is looked up while StrictMode
  // replays the mount, and a mutation started then would stop reporting its pending state.
  const [isLookingUp, setIsLookingUp] = useState(false)
  const { mutateAsync: sendDecision, isPending: isDeciding } = useMutation({
    mutationFn: ({ userCode, decision }: DecisionVariables) => decideDeviceAuthorization(userCode, decision),
  })

  const report = useCallback((err: unknown) => {
    const wait = retryAfterSeconds(err)
    if (wait !== null) {
      startCountdown(wait)
      setProblem({ kind: 'rateLimited' })
      return
    }

    // Unknown, expired, already used (400), or malformed (422): ask for the code again.
    if (err instanceof AxiosError && (err.response?.status === 400 || err.response?.status === 422)) {
      setStep({ name: 'enter' })
      setProblem({ kind: 'invalid' })
      return
    }

    setProblem({ kind: 'failed', message: parseApiError(err, t('common.error')).message })
  }, [startCountdown, t])

  const lookUp = useCallback(async (userCode: string) => {
    setProblem(null)
    setIsLookingUp(true)
    try {
      const request = await lookupDeviceAuthorization(userCode)
      setStep({ name: 'confirm', request })
    } catch (err: unknown) {
      report(err)
    } finally {
      setIsLookingUp(false)
    }
  }, [report])

  useEffect(() => {
    const normalized = normalizeUserCode(initialCode)
    if (!isCompleteUserCode(normalized) || prefilledLookup.current === normalized) return

    prefilledLookup.current = normalized
    lookUp(normalized).catch((err: unknown) => {
      logger.error('Looking up the device code ended unexpectedly:', err)
    })
  }, [initialCode, lookUp])

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault()
    if (!isCompleteUserCode(code)) return

    await lookUp(code)
  }

  const decide = async (userCode: string, decision: DeviceDecision) => {
    setProblem(null)
    try {
      await sendDecision({ userCode, decision })
      setStep(decision === 'approve' ? { name: 'approved' } : { name: 'denied' })
    } catch (err: unknown) {
      report(err)
    }
  }

  const startOver = () => {
    setCode('')
    setProblem(null)
    setStep({ name: 'enter' })
  }

  const waiting = secondsLeft > 0
  const alert = problem && (
    <ErrorAlert role="alert">
      {problem.kind === 'invalid' && t('auth.device.invalidCode')}
      {problem.kind === 'rateLimited' && waiting && t('auth.device.rateLimited', { count: secondsLeft })}
      {problem.kind === 'failed' && problem.message}
    </ErrorAlert>
  )
  const showAlert = problem !== null && (problem.kind !== 'rateLimited' || waiting)

  switch (step.name) {
    case 'enter':
      return (
        <Form onSubmit={handleSubmit}>
          <Intro>{t('auth.device.intro')}</Intro>
          {showAlert && alert}
          <FieldWrapper>
            <Label htmlFor="device-code">{t('auth.device.codeLabel')}</Label>
            <CodeInput
              id="device-code"
              name="user_code"
              value={formatUserCode(code)}
              onChange={(event) => setCode(normalizeUserCode(event.target.value))}
              placeholder="XXXX-XXXX"
              autoComplete="one-time-code"
              autoCapitalize="characters"
              autoCorrect="off"
              spellCheck={false}
              autoFocus
            />
          </FieldWrapper>
          <FullWidthButton type="submit" disabled={!isCompleteUserCode(code) || isLookingUp || waiting}>
            {isLookingUp ? t('auth.device.lookingUp') : t('auth.device.lookUp')}
          </FullWidthButton>
        </Form>
      )

    case 'confirm':
      return (
        <Form as="div">
          <ClientIntro>
            {t('auth.device.requestIntro', { client: step.request.clientName })}
          </ClientIntro>
          <Notice>
            {t('auth.device.compareCode')}
            {' '}
            <Code>{formatUserCode(normalizeUserCode(step.request.userCode))}</Code>
          </Notice>
          <ScopeList scopes={step.request.scopes} />
          {showAlert && alert}
          <Actions>
            <Button
              variant="outline"
              onClick={() => decide(step.request.userCode, 'deny')}
              disabled={isDeciding || waiting}
            >
              {t('auth.device.deny')}
            </Button>
            <Button
              onClick={() => decide(step.request.userCode, 'approve')}
              disabled={isDeciding || waiting}
            >
              {t('auth.device.approve')}
            </Button>
          </Actions>
        </Form>
      )

    case 'approved':
      return (
        <Form as="div">
          <Notice role="status">{t('auth.device.approved')}</Notice>
          <FooterText>
            <TextLink to="/">{t('auth.device.done')}</TextLink>
          </FooterText>
        </Form>
      )

    case 'denied':
      return (
        <Form as="div">
          <Notice role="status">{t('auth.device.denied')}</Notice>
          <FullWidthButton variant="outline" onClick={startOver}>
            {t('auth.device.useAnotherCode')}
          </FullWidthButton>
          <FooterText>
            <TextLink to="/">{t('auth.device.done')}</TextLink>
          </FooterText>
        </Form>
      )
  }
}
