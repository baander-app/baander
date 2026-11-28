export function formatMetadataProvider(provider: string): string {
  switch (provider) {
    case 'tmdb':
      return 'TMDB'
    case 'tvdb':
      return 'TheTVDB'
    case 'local':
      return 'Local files'
    default:
      return provider
  }
}
