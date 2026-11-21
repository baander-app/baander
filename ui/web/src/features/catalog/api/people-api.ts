import type {
  CreditRoleResource,
  PersonWorkResource,
} from '@/shared/api-client/gen/endpoints'

export function retryPersonRead(count: number, error: unknown): boolean {
  const status = (error as { response?: { status?: number } }).response?.status
  return status !== 404 && status !== 403 && count < 1
}

export function creditLabel(credit: CreditRoleResource): string {
  const job = credit.job || credit.role
  const character = credit.characterName ? ` as ${credit.characterName}` : ''
  const name = credit.creditedName ? ` (credited as ${credit.creditedName})` : ''
  return `${job}${character}${name}`
}

export function workPath(work: PersonWorkResource): string {
  switch (work.kind) {
    case 'movie': return `/movies/${work.publicId}`
    case 'series': return `/tv/series/${work.publicId}`
    case 'episode': return `/tv/episodes/${work.publicId}`
    case 'album': return `/music/albums/${work.publicId}`
    case 'song': return `/music/songs/${work.publicId}`
  }
}
