import styled from 'styled-components'
import { focusVisibleRing, textRole } from '@/shared/theme'
import tmdbLogo from './tmdb-logo.svg'

const Credit = styled.p`
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-sm);
  ${textRole('caption')}
  color: var(--color-muted-foreground);

  a {
    color: var(--color-accent-text);
    ${focusVisibleRing}
  }

  img {
    display: block;
    width: 5rem;
    height: auto;
  }
`

export function MetadataAttribution({ provider }: { provider?: string | null }) {
  if (provider === 'tmdb') {
    return (
      <Credit>
        <a href="https://www.themoviedb.org">
          <img src={tmdbLogo} alt="TMDB" width={80} height={11} />
        </a>
        <span>This product uses the TMDB API but is not endorsed or certified by TMDB.</span>
      </Credit>
    )
  }

  if (provider === 'tvdb') {
    return (
      <Credit>
        Metadata provided by <a href="https://thetvdb.com">TheTVDB.com</a>
      </Credit>
    )
  }

  return null
}
