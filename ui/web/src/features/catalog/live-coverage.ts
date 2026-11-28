import type { LiveCoverageDescriptor } from '@/shared/live/coverage-types'

export const catalogLiveCoverage = [
  {
    id: 'catalog.catalog',
    family: 'catalog',
    policy: {
      kind: 'stored',
      reason:
        'Stored catalog metadata and relation changes affect lists, details, filters, covers and availability.'
    },
    audiences: ['library', 'user'],
    sourceTables: [
      'albums',
      'artists',
      'songs',
      'genres',
      'movies',
      'videos',
      'images',
      'artist_album',
      'artist_song',
      'genre_album',
      'genre_song',
      'genre_movie',
      'movie_video',
      'movie_collections',
      'user_favorites',
      'watched_marks',
      'resume_positions'
    ],
    deploymentTables: [],
    sources: [
      'apiPath`/api/albums/${albumPublicId}/duplicates`',
      '[ `/api/albums/`, ...(params ? [params] : []) ]',
      '[ `/api/artists/`, ...(params ? [params] : []) ]',
      '[ `/api/songs/`, ...(params ? [params] : []) ]',
      '[ `/api/songs/${publicId}` ]',
      '[ `/api/albums/${publicId}` ]',
      '[ `/api/artists/${publicId}` ]',
      '[ `/api/genres/`, ...(params ? [params] : []) ]',
      '[ `/api/movies/${publicId}` ]',
      '[ `/api/movies/`, ...(params ? [params] : []) ]'
    ],
    keyVariants: [
      "['album-duplicates',albumPublicId]",
      '[ `/api/albums/`, ...(params ? [params] : []) ]',
      '[ `/api/artists/`, ...(params ? [params] : []) ]',
      '[ `/api/songs/`, ...(params ? [params] : []) ]',
      '[ `/api/songs/${publicId}` ]',
      '[ `/api/albums/${publicId}` ]',
      '[ `/api/artists/${publicId}` ]',
      '[ `/api/genres/`, ...(params ? [params] : []) ]',
      "[...getGetSongIndexQueryKey(params),'infinite']",
      '[ `/api/movies/${publicId}` ]',
      '[ `/api/movies/`, ...(params ? [params] : []) ]'
    ],
    dependentFamilies: [
      'activity',
      'playlists',
      'libraries',
      'recommendations',
      'lyrics'
    ],
    mirrors: [],
    responseCaches: ['authoritative-read'],
    consumers: [
      "src/features/catalog/api/album-duplicates-api.ts#useQuery:['album-duplicates',albumPublicId]",
      'src/features/catalog/api/album-duplicates-api.ts#AXIOS_INSTANCE.get:apiPath`/api/albums/${albumPublicId}/duplicates`',
      'src/features/catalog/components/SearchResults.tsx#useGetAlbumIndex:generated',
      'src/features/catalog/components/SearchResults.tsx#useGetArtistIndex:generated',
      'src/features/catalog/components/SearchResults.tsx#useGetSongIndex:generated',
      'src/features/catalog/components/metadata/InfoPanel.tsx#useGetSongShow:generated',
      'src/features/catalog/pages/SongDetailPage.tsx#useGetSongShow:generated',
      'src/features/catalog/components/metadata/InfoPanel.tsx#useGetAlbumShow:generated',
      'src/features/catalog/components/metadata/InfoPanel.tsx#useGetArtistShow:generated',
      'src/features/catalog/components/panels/SongPreviewPanel.tsx#useGetSongShow:generated',
      'src/features/catalog/components/panels/SongPreviewPanel.tsx#useGetAlbumShow:generated',
      'src/features/catalog/hooks/use-artist-detail.ts#useGetArtistShow:generated',
      'src/features/catalog/hooks/use-artist-detail.ts#useGetAlbumIndex:generated',
      'src/features/catalog/hooks/use-column-browser.ts#useGetGenreIndex:generated',
      'src/features/catalog/hooks/use-column-browser.ts#useGetArtistIndex:generated',
      'src/features/catalog/hooks/use-column-browser.ts#useGetAlbumIndex:generated',
      'src/features/catalog/hooks/use-column-browser.ts#useGetSongIndex:generated',
      'src/features/catalog/hooks/use-grid-view-model.ts#useGetAlbumIndex:generated',
      "src/features/catalog/hooks/use-song-list.ts#useInfiniteQuery:[...getGetSongIndexQueryKey(params),'infinite']",
      'src/features/catalog/hooks/use-song-list.ts#getSongIndex:generated',
      'src/features/catalog/pages/AlbumDetailPage.tsx#useGetAlbumShow:generated',
      'src/features/catalog/pages/AlbumsPage.tsx#useGetGenreIndex:generated',
      'src/features/catalog/pages/AlbumsPage.tsx#useGetAlbumIndex:generated',
      'src/features/catalog/pages/ArtistsPage.tsx#useGetGenreIndex:generated',
      'src/features/catalog/pages/ArtistsPage.tsx#useGetArtistIndex:generated',
      'src/features/catalog/pages/GenresPage.tsx#useGetGenreIndex:generated',
      'src/features/catalog/pages/HomePage.tsx#useGetAlbumIndex:generated',
      'src/features/catalog/pages/MovieDetailPage.tsx#useGetMovieShow:generated',
      'src/features/catalog/pages/MoviesBrowsePage.tsx#useGetMovieIndex:generated',
      'src/features/catalog/views/GridView.tsx#useGetAlbumIndex:generated'
    ],
    coverageVerification: ['tests/live-updates/coverage.test.ts'],
    behaviorVerification: {
      unitProofs: [
        'tests/live-updates/catalog-consumers.test.tsx',
        'tests/live-updates/collections.test.tsx',
        'src/features/catalog/pages/__tests__/MovieDetailPage.test.tsx',
        'src/features/catalog/components/metadata/__tests__/InfoPanel.test.tsx',
        'src/features/catalog/components/metadata/__tests__/use-metadata-form.test.tsx',
        'src/shared/drafts/__tests__/use-draft.test.tsx'
      ]
    }
  },
  {
    id: 'catalog.people-and-tv',
    family: 'catalog',
    policy: {
      kind: 'sampled',
      intervalMs: 30000,
      reason:
        'Person identities, screen credits and TV titles refresh while visible and after catalog hints. Member hints also refresh watched state and resume positions.'
    },
    audiences: ['library', 'user'],
    sourceTables: ['movies', 'songs', 'albums', 'artists', 'user_library_access', 'watched_marks', 'resume_positions'],
    deploymentTables: [],
    sources: [
      '/api/people',
      '/api/people/{publicId}/credits',
      '/api/movies/{publicId}/credits',
      '/api/artists/{publicId}/person',
      '/api/tv/series',
      '/api/tv/series/{publicId}',
      '/api/tv/series/{publicId}/episodes',
      '/api/tv/episodes/{publicId}'
    ],
    keyVariants: ['Generated People and television query keys with filter and page parameters'],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: ['authoritative-read'],
    consumers: [
      'src/features/catalog/pages/PeoplePage.tsx#usePersonIndex:generated',
      'src/features/catalog/pages/PersonDetailPage.tsx#usePersonShow:generated',
      'src/features/catalog/pages/PersonDetailPage.tsx#usePersonCredits:generated',
      'src/features/catalog/components/WorkPeople.tsx#usePersonArtist:generated',
      'src/features/catalog/components/WorkPeople.tsx#useQuery:queries[kind](publicId,params,options)',
      'src/features/catalog/components/use-video-watched.ts#getTvSeriesShowQueryKey:generated',
      'src/features/catalog/components/use-video-watched.ts#getTvSeriesEpisodesQueryKey:generated',
      'src/features/catalog/components/use-video-watched.ts#getTvEpisodeShowQueryKey:generated',
      'src/features/tv/pages/TVSeriesPage.tsx#useTvSeriesIndex:generated',
      'src/features/tv/pages/TVDetailPage.tsx#useTvSeriesShow:generated',
      'src/features/tv/pages/TVDetailPage.tsx#useTvSeriesEpisodes:generated',
      'src/features/tv/pages/TVDetailPage.tsx#useTvEpisodeShow:generated'
    ],
    coverageVerification: ['tests/live-updates/coverage.test.ts'],
    behaviorVerification: {
      unitProofs: [
        'tests/live-updates/people.test.tsx',
        'src/features/tv/pages/__tests__/TVDetailPage.test.tsx'
      ]
    }
  },
  {
    id: 'catalog.directors',
    family: 'catalog',
    policy: {
      kind: 'sampled',
      intervalMs: 30000,
      reason:
        'Director identities and movie credits are stored without a live capture producer; visible director views refresh every thirty seconds and after catalog hints.'
    },
    audiences: ['library'],
    sourceTables: ['movies', 'movie_video', 'user_library_access'],
    deploymentTables: [],
    sources: [
      'Person identities and directing credits with accessible movies',
      '/api/movies/directors',
      '/api/movies/directors/{publicId}',
      '/api/movies/directors/{publicId}/movies'
    ],
    keyVariants: [
      '[ `/api/movies/directors`, ...(params ? [params] : []) ]',
      '[ `/api/movies/directors/${publicId}` ]',
      '[ `/api/movies/directors/${publicId}/movies`, ...(params ? [params] : []) ]'
    ],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: ['authoritative-read'],
    consumers: [
      'src/features/catalog/pages/DirectorsPage.tsx#useGetMovieDirectorIndex:generated',
      'src/features/catalog/pages/DirectorDetailPage.tsx#useGetMovieDirectorShow:generated',
      'src/features/catalog/pages/DirectorDetailPage.tsx#useGetMovieDirectorMovies:generated'
    ],
    coverageVerification: ['tests/live-updates/coverage.test.ts'],
    behaviorVerification: {
      unitProofs: ['tests/live-updates/directors.test.tsx']
    }
  },
  {
    id: 'catalog.video-playback-information',
    family: 'catalog',
    policy: {
      kind: 'sampled',
      intervalMs: 5000,
      reason:
        'Authorized video metadata includes preview preparation state, which changes without a stored catalog write.'
    },
    audiences: ['library'],
    sourceTables: ['videos'],
    deploymentTables: [],
    sources: ['/api/videos/{publicId}/playback-information'],
    keyVariants: ["['catalog','video-playback-information',videoId]"],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: ['authoritative-read'],
    consumers: [
      "src/features/catalog/hooks/use-video-playback-information.ts#useQuery:['catalog','video-playback-information',videoId]",
      'src/features/catalog/hooks/use-video-playback-information.ts#AXIOS_INSTANCE.get:`/api/videos/${encodeURIComponent(videoId)}/playback-information`'
    ],
    coverageVerification: ['tests/live-updates/coverage.test.ts'],
    behaviorVerification: {
      unitProofs: ['tests/live-updates/video-playback-information.test.tsx']
    }
  },
  {
    id: 'catalog.lyrics',
    family: 'lyrics',
    policy: {
      kind: 'stored',
      reason:
        'Stored lyrics and song metadata refresh open lyrics views; catalog references determine visibility.'
    },
    audiences: ['library'],
    sourceTables: ['lyrics', 'songs'],
    deploymentTables: [],
    sources: ['[ `/api/songs/${publicId}/lyrics` ]'],
    keyVariants: ['[ `/api/songs/${publicId}/lyrics` ]'],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: ['authoritative-read'],
    consumers: [
      'src/features/catalog/components/LyricsDialog.tsx#useGetLyricsSongLyrics:generated'
    ],
    coverageVerification: ['tests/live-updates/coverage.test.ts'],
    behaviorVerification: {
      unitProofs: [
        'tests/live-updates/catalog-consumers.test.tsx',
        'src/features/catalog/components/__tests__/LyricsDialog.test.tsx'
      ]
    }
  },
  {
    id: 'catalog.lyrics-demand',
    family: 'lyrics',
    policy: {
      kind: 'demand',
      reason:
        'External provider search is an explicit administrator action and has no local durable change producer.'
    },
    audiences: ['admin'],
    sourceTables: [],
    deploymentTables: [],
    sources: [
      'LrclibClient external search',
      '[ `/api/lyrics/search`, ...(params ? [params] : []) ]'
    ],
    keyVariants: ['[ `/api/lyrics/search`, ...(params ? [params] : []) ]'],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: [],
    consumers: [
      'src/features/catalog/components/LyricsDialog.tsx#useGetLyricsSearch:generated'
    ],
    coverageVerification: ['tests/live-updates/coverage.test.ts'],
    behaviorVerification: {
      unitProofs: ['tests/live-updates/collections.test.tsx']
    }
  },
  {
    id: 'catalog.musicbrainz-match-demand',
    family: 'admin-metadata',
    policy: {
      kind: 'demand',
      reason:
        'Candidate searches and comparisons ask MusicBrainz, which answers one request per second for the whole server; they run only when an admin opens, refines or reloads them.'
    },
    audiences: ['admin'],
    sourceTables: [],
    deploymentTables: [],
    sources: [
      'MusicBrainz candidate search and lookup',
      '[ `/api/admin/metadata/match/${type}/${publicId}/candidates`, ...(params ? [params] : []) ]',
      '[ `/api/admin/metadata/match/${type}/${publicId}/comparison`, ...(params ? [params] : []) ]'
    ],
    keyVariants: [
      '[ `/api/admin/metadata/match/${type}/${publicId}/candidates`, ...(params ? [params] : []) ]',
      '[ `/api/admin/metadata/match/${type}/${publicId}/comparison`, ...(params ? [params] : []) ]'
    ],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: [],
    consumers: [
      'src/features/catalog/hooks/use-musicbrainz-match.ts#useGetAdminMetadataMatchCandidates:generated',
      'src/features/catalog/hooks/use-musicbrainz-match.ts#useGetAdminMetadataMatchComparison:generated'
    ],
    coverageVerification: ['tests/live-updates/coverage.test.ts'],
    behaviorVerification: {
      unitProofs: [
        'tests/live-updates/descriptors.test.ts',
        'src/features/catalog/components/metadata/__tests__/MusicBrainzMatchDialog.test.tsx'
      ]
    }
  },
  {
    id: 'catalog.catalog-demand',
    family: 'catalog',
    policy: {
      kind: 'demand',
      reason:
        'Playback, queue hydration and context-menu actions read authoritative data when invoked; refreshing must never replay an action.'
    },
    audiences: ['library'],
    sourceTables: [
      'albums',
      'artists',
      'songs',
      'artist_album',
      'artist_song',
      'user_library_access'
    ],
    deploymentTables: [],
    sources: ['[ `/api/albums/${publicId}` ]', '[ `/api/songs/`, ...(params ? [params] : []) ]'],
    keyVariants: [],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: ['authoritative-read'],
    consumers: [
      'src/features/catalog/components/menus/AlbumContextMenu.tsx#getAlbumShow:generated',
      'src/features/catalog/hooks/use-play-album.ts#getAlbumShow:generated',
      'src/features/catalog/hooks/use-play-artist.tsx#getSongIndex:generated'
    ],
    coverageVerification: ['tests/live-updates/coverage.test.ts'],
    behaviorVerification: {
      unitProofs: ['tests/live-updates/collections.test.tsx']
    }
  },
  {
    id: 'catalog.activity',
    family: 'activity',
    policy: {
      kind: 'stored',
      reason:
        'Playback records and catalog metadata affect mounted Recents and paginated activity ordering.'
    },
    audiences: ['user', 'library'],
    sourceTables: [
      'media_activities',
      'albums',
      'artists',
      'songs',
      'movies',
      'user_favorites'
    ],
    deploymentTables: [],
    sources: ['[ `/api/activity/history`, ...(params ? [params] : []) ]'],
    keyVariants: [
      "[...getGetActivityHistoryQueryKey({limit}),'infinite']",
      '[ `/api/activity/history`, ...(params ? [params] : []) ]'
    ],
    dependentFamilies: ['recommendations'],
    mirrors: ['Recents component state'],
    responseCaches: ['authoritative-read'],
    consumers: [
      "src/features/catalog/hooks/use-activity-view-model.ts#useInfiniteQuery:[...getGetActivityHistoryQueryKey({limit}),'infinite']",
      'src/features/catalog/hooks/use-activity-view-model.ts#getActivityHistory:generated',
      'src/features/catalog/pages/HomePage.tsx#useGetActivityHistory:generated'
    ],
    coverageVerification: ['tests/live-updates/coverage.test.ts'],
    behaviorVerification: {
      unitProofs: [
        'tests/features/layout/hooks/use-recent-items.test.tsx',
        'tests/features/player/services/activity-service.test.ts',
        'src/features/catalog/hooks/__tests__/use-activity-view-model.test.tsx'
      ]
    }
  },
  {
    id: 'catalog.recommendations',
    family: 'recommendations',
    policy: {
      kind: 'stored',
      reason:
        'Recommendation projections and their catalog/activity inputs affect discovery and For You views.'
    },
    audiences: ['user', 'library'],
    sourceTables: [
      'recommendations',
      'recommendation_jobs',
      'media_activities',
      'songs',
      'albums',
      'artists',
      'user_favorites',
      'user_library_access'
    ],
    deploymentTables: [],
    sources: [
      '[ `/api/recommendations/`, ...(params ? [params] : []) ]',
      '[ `/api/recommendations/for-you`, ...(params ? [params] : []) ]'
    ],
    keyVariants: [
      '[ `/api/recommendations/`, ...(params ? [params] : []) ]',
      '[ `/api/recommendations/for-you`, ...(params ? [params] : []) ]'
    ],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: ['authoritative-read'],
    consumers: [
      'src/features/catalog/hooks/use-discover-view-model.ts#useGetRecommendationIndex:generated',
      'src/features/catalog/hooks/use-for-you-view-model.ts#useGetRecommendationForYou:generated'
    ],
    coverageVerification: ['tests/live-updates/coverage.test.ts'],
    behaviorVerification: {
      unitProofs: ['tests/live-updates/catalog-consumers.test.tsx']
    }
  },
  {
    id: 'catalog.radio-user',
    family: 'radio',
    policy: {
      kind: 'stored',
      reason:
        'Subscriptions, stars and current radio sessions are private to the authenticated user; joined station metadata also affects their presentation.'
    },
    audiences: ['user'],
    sourceTables: [
      'country_subscriptions',
      'starred_stations',
      'radio_sessions',
      'radio_stations'
    ],
    deploymentTables: [],
    sources: ["'/api/radio/starred'"],
    keyVariants: ["['catalog','starred-stations']"],
    dependentFamilies: [],
    mirrors: ['radio-store catalog starred-stations query'],
    responseCaches: ['authoritative-read'],
    consumers: [
      "src/features/catalog/hooks/use-starred-stations.ts#useQuery:['catalog','starred-stations']",
      "src/features/catalog/hooks/use-starred-stations.ts#AXIOS_INSTANCE.get:'/api/radio/starred'"
    ],
    coverageVerification: ['tests/live-updates/coverage.test.ts'],
    behaviorVerification: {
      unitProofs: ['tests/live-updates/collections.test.tsx']
    }
  },
  {
    id: 'catalog.catalog-timeline',
    family: 'catalog',
    policy: {
      kind: 'stored',
      reason:
        'The mounted Timeline retains an all-page catalog walk that must refresh through a bounded adapter without replacing navigation or scroll state.'
    },
    audiences: ['library'],
    sourceTables: [
      'albums',
      'artist_album',
      'artists',
      'libraries',
      'user_library_access'
    ],
    deploymentTables: [],
    sources: ["'/api/albums/'"],
    keyVariants: ["['catalog','timeline']"],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: ['authoritative-read'],
    consumers: [
      "src/features/catalog/hooks/use-timeline-view-model.ts#useQuery:['catalog','timeline']",
      "src/features/catalog/hooks/use-timeline-view-model.ts#AXIOS_INSTANCE.get:'/api/albums/'"
    ],
    coverageVerification: ['tests/live-updates/coverage.test.ts'],
    behaviorVerification: {
      unitProofs: [
        'src/features/catalog/hooks/__tests__/use-timeline-view-model.test.ts'
      ]
    }
  }
] as const satisfies readonly LiveCoverageDescriptor[]
