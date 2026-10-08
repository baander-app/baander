import styled from 'styled-components'
import { useTranslation } from '@/shared/i18n'

/** Scopes the server grants (`auth.scopes.user_grants`); others are shown by name. */
const KNOWN_SCOPES = new Set(['profile', 'email', 'library', 'playlist'])

const Intro = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const List = styled.ul`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0;
  padding-left: 1.25rem;
  font-size: 0.875rem;
  color: var(--color-foreground);
`

interface ScopeListProps {
  scopes: string[]
}

/** What a client will be allowed to do, in words. No scopes means the default grant. */
export function ScopeList({ scopes }: ScopeListProps) {
  const { t } = useTranslation()

  return (
    <div>
      <Intro>{t('auth.scopes.intro')}</Intro>
      <List>
        {scopes.length === 0 ? (
          <li>{t('auth.scopes.default')}</li>
        ) : (
          scopes.map((scope) => (
            <li key={scope}>
              {KNOWN_SCOPES.has(scope) ? t(`auth.scopes.${scope}`) : scope}
            </li>
          ))
        )}
      </List>
    </div>
  )
}
