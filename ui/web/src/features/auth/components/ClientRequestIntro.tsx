import styled from 'styled-components'
import { useTranslation } from '@/shared/i18n'
import { textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'

/** Stands in for the client name in the translated intro, so the name renders as its own element. */
const CLIENT_SLOT = '\u0001'

const Intro = styled.p`
  ${textRole('body')}
  font-weight: 600;
  color: var(--color-foreground);
`

interface ClientRequestIntroProps {
  /** The translation key of the sentence that names the client through `{{client}}`. */
  readonly messageKey: 'auth.consent.requestIntro' | 'auth.device.requestIntro'
  readonly clientName: string
}

/** The sentence naming the client that asks for access, with the name set as content text. */
export function ClientRequestIntro({ messageKey, clientName }: ClientRequestIntroProps) {
  const { t } = useTranslation()
  const [before, after = ''] = t(messageKey, { client: CLIENT_SLOT }).split(CLIENT_SLOT)

  return (
    <Intro>
      {before}
      <ContentText textRole="body" text={clientName} />
      {after}
    </Intro>
  )
}
