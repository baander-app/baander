import styled from 'styled-components'
import { useTranslation } from '@/shared/i18n'
import { textRole } from '@/shared/theme'

/** The scopes the server grants (`auth.scopes.user_grants`); the server drops any other. */
const KNOWN_SCOPES = ['profile', 'email', 'library', 'playlist'] as const

type KnownScope = (typeof KNOWN_SCOPES)[number]

function isKnownScope(scope: string): scope is KnownScope {
  return (KNOWN_SCOPES as readonly string[]).includes(scope)
}

const Intro = styled.p`
  ${textRole('body')}
  color: var(--color-muted-foreground);
`

const List = styled.ul`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0;
  padding-left: 1.25rem;
  ${textRole('body')}
  color: var(--color-foreground);
`

interface ScopeListProps {
  scopes: string[]
}

/**
 * What a client will be allowed to do, in words. The server grants only the scopes it lists and
 * refuses a request with none, so an unknown scope grants nothing and is not shown.
 */
export function ScopeList({ scopes }: ScopeListProps) {
  const { t } = useTranslation()

  return (
    <div>
      <Intro>{t('auth.scopes.intro')}</Intro>
      <List>
        {scopes.filter(isKnownScope).map((scope) => (
          <li key={scope}>{t(`auth.scopes.${scope}`)}</li>
        ))}
      </List>
    </div>
  )
}
