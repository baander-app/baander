export const libraryKeys = {
  all: ['libraries'] as const,
  list: (type?: string) => ['libraries', 'list', type] as const,
  detail: (id: string) => ['libraries', 'detail', id] as const,
  stats: (id: string) => ['libraries', 'detail', id, 'stats'] as const,
}
